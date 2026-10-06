<?php declare(strict_types=1);

/**
 * Die zweite `Renderer`-Saat wieder wegräumen — und die gemerkten Ids auf die echte zurückstellen.
 *
 *     php scripts/dev/undo-duplicate-constants.php [path/to/wordpress]
 *
 * ⚠️ **Was geschehen ist, gemessen und nicht vermutet.** *Der Eigentümer hat `Renderer` aus `Constants`
 * heraus in den Ast `Settings` verschoben — auf sein Wort: «render with label sollte das gleiche sein wie
 * render label roles, also die Renderer von label with roles zu render with label schieben». Danach lief
 * {@see \Taxmod\WordPress\Persistence\RenderingScaffold::import()} in einem Prüflauf, prüfte die gemerkte
 * Id **gegen den Elternknoten** `Constants`, fand sie dort nicht mehr — und legte den ganzen Baum ein
 * zweites Mal an. **24 Knoten, alle leer**, und die gemerkten Ids zeigten danach auf die leeren.*
 *
 * ⚠️ **Nichts war kaputt auf dem Schirm, und das ist das Beunruhigende daran.** *Die Verweise in den
 * Datensätzen zeigen weiter auf den echten Baum; nur die Saat wusste es anders. **Eine stille zweite
 * Heimat für eine Sache ist genau das, was `R1` verbietet** — sie fällt erst auf, wenn ein Leser der
 * gemerkten Id folgt.*
 *
 * ⚠️ **Dieses Skript räumt nur auf. Die Frage, wer nachgeben muss, ist nicht entschieden** — folgt die
 * Saat einem verschobenen Knoten (gemerkte Id gewinnt über den Elternknoten), oder ist das Verschieben
 * eines gesäten Knotens aus seinem Ast heraus zu verweigern? *Das ist seine Entscheidung und steht in der
 * Arbeitsliste.*
 *
 * ⚠️ *Gelöscht wird **nur, was frei ist**: keine Verweise, keine Datensätze, keine Felder, keine Texte.
 * Ein Knoten, an dem etwas hängt, bleibt stehen und wird gemeldet.*
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();

    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }

    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$knotenTabelle = Schema::table('nodes');
$kantenTabelle = Schema::table('relations');
$werteTabelle  = Schema::table('relation_records');
$saetzeTabelle = Schema::table('node_records');
$texteTabelle  = Schema::table('labels');

/**
 * Wieviel an einem Knoten hängt — Verweise, Datensätze, Felder, Texte.
 *
 * ⚠️ **`null` heisst «Abfrage kaputt» und nicht «nichts gefunden».** *Die beiden sehen bei `$wpdb` gleich
 * aus, und ein Aufräumskript, das den Unterschied nicht macht, räumt im Zweifel das Falsche.*
 */
function haengtDran(int $id): int
{
    global $wpdb, $kantenTabelle, $werteTabelle, $saetzeTabelle, $texteTabelle;

    $summe = 0;

    $fragen = [
        "SELECT COUNT(*) FROM {$werteTabelle} WHERE value_ref = %d",
        "SELECT COUNT(*) FROM {$saetzeTabelle} WHERE node_id = %d",
        // ⚠️ *Die Texttabelle nennt ihren Eigentümer `owner_id` und nicht `node_id` — und `$wpdb` sagt
        // «unbekannte Spalte» nur, wenn man ihn fragt. Genau darum steht die Prüfung auf `null` oben.*
        "SELECT COUNT(*) FROM {$texteTabelle} WHERE owner_id = %d",
    ];

    foreach ($fragen as $frage) {
        $zahl = $wpdb->get_var($wpdb->prepare($frage, $id));

        if ($zahl === null) {
            fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
            exit(1);
        }

        $summe += (int) $zahl;
    }

    $felder = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$kantenTabelle} WHERE (from_node_id = %d OR to_node_id = %d) AND kind <> 'inheritance'",
        $id,
        $id
    ));

    if ($felder === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(1);
    }

    return $summe + (int) $felder;
}

/**
 * Alle Knoten dieses Namens, samt Pfad und was unter ihnen liegt.
 *
 * @return list<array{id: int, path: string, unten: list<int>, belegt: int}>
 */
function fassungenVon(string $name): array
{
    global $wpdb, $knotenTabelle;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, path FROM {$knotenTabelle} WHERE name = %s ORDER BY id",
        $name
    ), ARRAY_A);

    if ($rows === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(1);
    }

    $aus = [];

    foreach ($rows as $r) {
        $id   = (int) $r['id'];
        $pfad = (string) $r['path'];

        $unten = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$knotenTabelle} WHERE path LIKE %s",
            $wpdb->esc_like($pfad . '.') . '%'
        ));

        if ($unten === null) {
            fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
            exit(1);
        }

        $unten  = array_map('intval', $unten);
        $belegt = haengtDran($id);

        foreach ($unten as $eines) {
            $belegt += haengtDran($eines);
        }

        $aus[] = ['id' => $id, 'path' => $pfad, 'unten' => $unten, 'belegt' => $belegt];
    }

    return $aus;
}

$weg      = 0;
$gemeldet = 0;

/**
 * Der Pfad der **echten** Fassung je Behälter, klein geschrieben — so heisst er auch in der Option.
 *
 * ⚠️ *Gesammelt statt geraten: die Suche nach einem Renderer-Namen darf nur **unter seinem Behälter**
 * laufen, sonst antwortet ein gleichnamiger Knoten irgendwo im Modell.*
 *
 * @var array<string, string>
 */
$echteAeste = [];

foreach (['Renderer', 'Converter', 'Validator'] as $name) {
    echo "\n== {$name} ==\n";

    $fassungen = fassungenVon($name);

    foreach ($fassungen as $eine) {
        printf(
            "  #%-7d %-34s %2d Kinder, %2d belegt%s\n",
            $eine['id'],
            $eine['path'],
            count($eine['unten']),
            $eine['belegt'],
            $eine['belegt'] === 0 ? '  frei' : ''
        );
    }

    if (count($fassungen) === 1) {
        $echteAeste[strtolower($name)] = $fassungen[0]['path'];

        echo "  nur eine Fassung — nichts zu tun\n";

        continue;
    }

    if ($fassungen === []) {
        echo "  gar keine Fassung — nichts zu tun\n";

        continue;
    }

    // ⚠️ *Belegt zu sein ist das Merkmal der echten Fassung. Sind zwei belegt oder keine, wird **nichts**
    // angefasst — dann ist es keine Doppelung dieses Laufs, sondern eine Frage an den Eigentümer.*
    $belegte = array_values(array_filter($fassungen, static fn (array $f): bool => $f['belegt'] > 0));

    if (count($belegte) !== 1) {
        echo '  ! ', count($belegte), " Fassungen sind belegt — nichts angefasst\n";
        ++$gemeldet;

        continue;
    }

    $echteAeste[strtolower($name)] = $belegte[0]['path'];

    foreach ($fassungen as $eine) {
        if ($eine['id'] === $belegte[0]['id'] || $eine['belegt'] > 0) {
            continue;
        }

        foreach ([...$eine['unten'], $eine['id']] as $wegId) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$kantenTabelle} WHERE from_node_id = %d OR to_node_id = %d",
                $wegId,
                $wegId
            ));
            $wpdb->query($wpdb->prepare("DELETE FROM {$knotenTabelle} WHERE id = %d", $wegId));
            ++$weg;
        }

        echo '  weggeraeumt: #', $eine['id'], ' samt ', count($eine['unten']), " Kindern\n";
    }
}

echo "\n== Die gemerkten Ids zurueck auf die echten Knoten ==\n";

$gerichtet = 0;

$optionen = $wpdb->get_results(
    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'taxmod_render_%_id'",
    ARRAY_A
);

if ($optionen === null) {
    fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
    exit(1);
}

foreach ($optionen as $o) {
    $wert = (int) $o['option_value'];

    $steht = $wert === 0
        ? null
        : $wpdb->get_var($wpdb->prepare("SELECT id FROM {$knotenTabelle} WHERE id = %d", $wert));

    if ($steht !== null) {
        continue;
    }

    // Der Name steckt in der Option: `taxmod_render_renderer_chooser_dialog_id`.
    //
    // ⚠️ **Zwei Fehler standen hier, und der zweite hat wirklich Schaden angerichtet.** *Erstens war die
    // Alternation `…_(renderer|…)_|_id$` gierig genug, dass aus `taxmod_render_renderer_id` — der Option
    // des **Behälters** — der Name `id` wurde. Zweitens hat der Namensvergleich ohne Rücksicht auf den
    // Ast gesucht: `settings` traf den **Astwurzelknoten `Settings`**, und die Option zeigte danach auf
    // ihn. **Der Griff «Name, LIMIT 1» hat schon einmal die Rolle `form` mit dem Renderer `form`
    // verwechselt** ([D-022](../../docs/NewConcept/90-decision-log.md): Namen sind absichtlich nicht
    // eindeutig) — und dieses Skript hat ihn wiederholt.*
    // ⚠️ **Die Option des Behälters selbst — und die ist der Kern des Vorfalls.** *Sie zeigte auf die
    // leere Zweitfassung, und solange sie das tut, legt der nächste Saatlauf sie wieder an. Ihr Ziel
    // steht schon fest: der Ast, den die erste Runde als den echten gemessen hat.*
    if (preg_match('/^taxmod_render_(renderer|converter|validator)_id$/', (string) $o['option_name'], $behaelter)) {
        $ast = $echteAeste[$behaelter[1]] ?? null;

        if ($ast === null) {
            echo '  ! ', $o['option_name'], " zeigt ins Leere und sein Ast ist unklar — nichts gesetzt\n";
            ++$gemeldet;

            continue;
        }

        // Der letzte Abschnitt des Pfades ist die Id des Knotens selbst.
        $teileDesPfades = explode('.', $ast);
        $astId          = (int) end($teileDesPfades);

        update_option((string) $o['option_name'], $astId, true);
        echo '  ', $o['option_name'], ' -> #', $astId, "\n";
        ++$gerichtet;

        continue;
    }

    if (! preg_match('/^taxmod_render_(renderer|converter|validator)_(.+)_id$/', (string) $o['option_name'], $teile)) {
        echo '  ! ', $o['option_name'], " nennt keinen Behaelter — nichts gesetzt\n";
        ++$gemeldet;

        continue;
    }

    $roh       = $teile[2];
    $echterAst = $echteAeste[$teile[1]] ?? null;

    if ($echterAst === null) {
        echo '  ! ', $o['option_name'], " zeigt ins Leere und sein Behaelter ist unklar — nichts gesetzt\n";
        ++$gemeldet;

        continue;
    }

    // ⚠️ *Nur **innerhalb der echten Fassung** gesucht, und nur dort. Ein Name draussen im Modell ist
    // keine Antwort auf «wo liegt dieser Renderer».*
    $kandidaten = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$knotenTabelle}
         WHERE (BINARY name = %s OR BINARY name = %s) AND path LIKE %s",
        str_replace('_', '-', $roh),
        $roh,
        $wpdb->esc_like($echterAst . '.') . '%'
    ));

    if ($kandidaten === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(1);
    }

    // ⚠️ *Nur bei **genau einem** Treffer. Knotennamen sind absichtlich nicht eindeutig
    // ([D-022](../../docs/NewConcept/90-decision-log.md)), und `LIMIT 1` auf einen Namen ist genau der
    // Griff, der schon einmal die Rolle `form` mit dem Renderer `form` verwechselt hat.*
    if (count($kandidaten) !== 1) {
        echo '  ! ', $o['option_name'], ' zeigt ins Leere und «', $roh, '» ist ',
            count($kandidaten), "x da — nichts gesetzt\n";
        ++$gemeldet;

        continue;
    }

    update_option((string) $o['option_name'], (int) $kandidaten[0], true);
    echo '  ', $o['option_name'], ' -> #', (int) $kandidaten[0], "\n";
    ++$gerichtet;
}

echo "\n{$weg} Knoten weggeraeumt, {$gerichtet} Ids gerichtet, {$gemeldet} gemeldet\n";

exit($gemeldet === 0 ? 0 : 1);
