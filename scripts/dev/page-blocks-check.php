<?php declare(strict_types=1);
/**
 * Die Blöcke der Knotenseite teilen nach der **Kante** ein, nicht nach dem Zielknoten.
 *
 *     php scripts/dev/page-blocks-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer hat den Widerspruch in einer einzigen Zeile gesehen:** *auf dem Knoten `form`
 * stand `with_label` **in der Tabelle Fields**, und in ihrer eigenen Spalte «Kind» stand `setting`.
 * Seine Frage war «falscher Relationstyp gewählt?» — **nein, die Art war richtig.** Die Tabelle fragte
 * die Sorte des **Zielknotens**, und `Boolean` ist ein `field`.*
 *
 * ⚠️ **Gemessen vor der Behebung: fünf von sieben Einstellungskanten lagen unter Fields** —
 * `orientation`, `exponent`, `label_role`, `with_label` und alles, dessen Ziel eine Knotensorte `field`
 * trägt. *Seit [D-526](../../docs/NewConcept/90-decision-log.md) sagt die Kante selbst, was sie ist; die
 * Knotensorte ist eine zweite Abschrift derselben Aussage — und **zwei Angaben, die dasselbe sagen
 * sollen, sind eine**, dieselbe Auflösung wie bei `mandatory` ([D-405](../../docs/NewConcept/90-decision-log.md))
 * und `persistent` ([D-538](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Diese Prüfung zeichnet die echte Seite.** *Sie lädt WordPress, setzt den Verwalter **im Code**
 * und ruft die Schirmklasse — keine Anmeldung, kein Formular. Das ist die erste Prüfung, die den
 * Schirm selbst ansieht statt seine Bestandteile: **die vier Fehler dieses Abends waren alle
 * Verdrahtung**, und Verdrahtung sieht man nur an der fertigen Seite.*
 *
 * @see docs/NewConcept/30-renderer.md
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
use Taxmod\WordPress\Plugin;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/**
 * Die Seite eines Knotens, gezeichnet wie im Browser.
 *
 * ⚠️ *Der Verwalter wird im Code gesetzt. Die Schirmklasse prüft eine Fähigkeit (`CD-5`), und ohne
 * angemeldeten Benutzer bekäme dieser Lauf die Absage statt die Seite — **er umgeht keine Prüfung,
 * er erfüllt sie**.*
 */
function seiteVon(int $nodeId): string
{
    $admin = get_users(['role' => 'administrator', 'number' => 1]);

    if ($admin === []) {
        return '';
    }

    wp_set_current_user($admin[0]->ID);

    $_GET['taxmod_node'] = (string) $nodeId;
    $_GET['page']        = 'taxmod-nodes';

    // ⚠️ *Der Konstruktor ist privat, weil das Plugin über `boot()` entsteht — für einen Lauf ohne
    // Hooks bleibt der Weg über die Spiegelung. **Nur lesend**: es wird nichts registriert.*
    $bau    = new ReflectionClass(Plugin::class);
    $plugin = $bau->newInstanceWithoutConstructor();
    $bau->getProperty('file')->setValue($plugin, 'taxmod.php');

    return $plugin->screen()->render();
}

/**
 * Die Zeilen einer Tabelle unter dieser Überschrift, jede Zelle als Klartext.
 *
 * @return list<list<string>>
 */
function tabelleUnter(string $html, string $ueberschrift, string $bisUeberschrift): array
{
    // ⚠️ *Die Dialoge des Ziel-Wählers weg — sie tragen den ganzen Baum und darin steht jedes Wort.*
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    $von = strpos($html, '>' . $ueberschrift . '<');

    if ($von === false) {
        return [];
    }

    $bis  = strpos($html, '>' . $bisUeberschrift . '<', $von + 4);
    $teil = substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    if (! preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $teil, $zeilen)) {
        return [];
    }

    $aus = [];

    foreach ($zeilen[1] as $zeile) {
        preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/s', $zeile, $zellen);

        $aus[] = array_map(
            static fn (string $z): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($z)))),
            $zellen[1]
        );
    }

    return $aus;
}

function knotenId(string $name): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s ORDER BY id DESC LIMIT 1',
        $name
    ));
}

echo "\n== Keine Einstellungskante unter «Fields» ==\n";

// ⚠️ *`form` ist der Knoten, an dem er es gesehen hat; `Integer` erbt dieselben drei; `Passiv` ist der
// Gegenfall mit echten Feldern.*
foreach (['form', 'Integer', 'Passiv'] as $name) {
    $id = knotenId($name);

    if ($id === 0) {
        check("«{$name}» steht im Modell", false);

        continue;
    }

    $html = seiteVon($id);

    if ($html === '') {
        check("«{$name}»: die Seite laesst sich zeichnen", false, 'kein Administrator gefunden');

        continue;
    }

    $felder = tabelleUnter($html, 'Fields', 'Settings');

    $einstellungen = [];

    foreach ($felder as $zeile) {
        // Spalten: Name, Points at, Kind, From, How many, Akte.
        if (($zeile[2] ?? '') === 'setting') {
            $einstellungen[] = $zeile[0] ?? '?';
        }
    }

    check(
        "«{$name}»: keine setting-Zeile unter Fields",
        $einstellungen === [],
        implode(', ', $einstellungen)
    );
}

echo "\n== Und die Einstellungen stehen unter «Settings» ==\n";

// ⚠️ **Der Gegenfall, und ohne ihn ist die Prüfung wertlos.** *Ein Schirm, der die Einstellungen
// **gar nicht** mehr zeigt, hätte oben ebenfalls keine setting-Zeile unter Fields.*
$id   = knotenId('form');
$html = $id === 0 ? '' : seiteVon($id);

$settings = $html === '' ? [] : tabelleUnter($html, 'Settings', 'Preview');
$gesehen  = [];

foreach ($settings as $zeile) {
    if (($zeile[2] ?? '') === 'setting') {
        $gesehen[] = $zeile[0] ?? '?';
    }
}

// ⚠️ *Hier stand `>= 3` — die drei Einstellungen der **Wurzel**. Seit
// [D-545](../../docs/NewConcept/90-decision-log.md) erbt nichts im Settings-Ast mehr von `Root`, und
// `form` liegt darin: es zeigt jetzt seine zwei eigenen, `label_role` und `with_label`. **Die Zusage
// wollte nie eine Zahl, sondern «die Einstellungen dieses Knotens stehen unter Settings».***
check(
    '«form» zeigt seine Einstellungen unter Settings',
    count($gesehen) >= 2,
    count($gesehen) . ': ' . implode(', ', $gesehen)
);

// ⚠️ *Namentlich die, die er gemeldet hat.*
check(
    'und «with_label» ist darunter',
    in_array('with_label', $gesehen, true),
    implode(', ', $gesehen)
);

echo "\n== Ein Knoten mit echten Feldern behaelt sie ==\n";

$id     = knotenId('Passiv');
$html   = $id === 0 ? '' : seiteVon($id);
$felder = $html === '' ? [] : tabelleUnter($html, 'Fields', 'Settings');
$echte  = 0;

foreach ($felder as $zeile) {
    if (in_array($zeile[2] ?? '', ['composition', 'aggregation'], true)) {
        ++$echte;
    }
}

check('«Passiv» zeigt seine eigenen Felder unter Fields', $echte >= 3, (string) $echte);

echo "\n== «How many» ist an einer geerbten Kante gesperrt ==\n";

// ⚠️ **Er hat gefragt, ob die Umsetzung fehlt — sie fehlte.** *«How many sollte für Attribute des
// Vaterknotens nicht mehr im Kindknoten auswählbar sein, hatten wir geändert.»* *Gemessen an
// `render with label`: **keine einzige** Auswahl war gesperrt, auch nicht die drei geerbten. Die Zeile
// wusste es — ihre Spalte «From» sagte `inherited` — und gab es nicht an die Auswahl weiter; in
// `drawChoice()` stand `editable: true`, fest verdrahtet.*
//
// ⚠️ *[D-376](../../docs/NewConcept/90-decision-log.md): eine geerbte Kante gehört dem Vorfahren, und
// sie **hier** zu ändern hiesse, sie für alle zu ändern — still.*
// ⚠️ *Hier stand «render with label» — der liegt im Settings-Ast und erbt seit
// [D-545](../../docs/NewConcept/90-decision-log.md) nichts mehr von der Wurzel, hat also keine
// geerbte Zeile mehr. **Gefragt ist ein Knoten, der wirklich erbt.***
$id   = knotenId('Kontact');
$html = $id === 0 ? '' : seiteVon($id);

if ($html === '') {
    check('«render with label» steht im Modell', false);
} else {
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $zeilen);

    $offeneGeerbte = 0;
    $gesperrteEigene = 0;
    $geerbte = 0;
    $eigene  = 0;

    foreach ($zeilen[1] as $zeile) {
        if (! str_contains($zeile, 'taxmod-field-many')) {
            continue;
        }

        if (! preg_match('/<select\b[^>]*class="taxmod-choice[^"]*"[^>]*>/', $zeile, $treffer)) {
            continue;
        }

        $gesperrt = str_contains($treffer[0], 'disabled');

        if (str_contains($zeile, 'inherited')) {
            ++$geerbte;

            if (! $gesperrt) {
                ++$offeneGeerbte;
            }

            continue;
        }

        ++$eigene;

        if ($gesperrt) {
            ++$gesperrteEigene;
        }
    }

    check('geerbte Zeilen gefunden', $geerbte >= 3, (string) $geerbte);
    check('und keine davon laesst «How many» aendern', $offeneGeerbte === 0, "{$offeneGeerbte} offen");

    // ⚠️ **Der Gegenfall.** *Alles zu sperren wäre oben ebenfalls grün — und wäre schlimmer als der
    // Fehler, den diese Zusage fängt.*
    check('eigene Zeilen gefunden', $eigene >= 1, (string) $eigene);
    check('und die duerfen es weiterhin', $gesperrteEigene === 0, "{$gesperrteEigene} gesperrt");
}

echo "\n== Die Renderkette geht durch ein zusammengesetztes Feld ==\n";

// ⚠️ **Seine Beobachtung, und dann seine Diagnose:** *«Renderer ist form, Adressfeld hat mindestens 4
// Felder, gezeigt wird aber nur eines»* — *«heisst wohl Renderkette ist unterbrochen»*. **Sie war
// unterbrochen.** *Gemessen: das Feld `Address` bekam Typ «keiner» und `plain`, während `Adresse` fünf
// eigene Felder trägt. Vier von fünf waren nie zu sehen.*
//
// ⚠️ *Der Knoten wird über **die Kante** gesucht, nicht über seinen Namen: er heisst «Kontact» und
// nicht «Kontakt», und eine Prüfung, die den Namen rät, ist morgen rot, weil jemand ihn korrigiert.*
$zusammengesetzt = $wpdb->get_row(
    'SELECT v.id AS besitzer, z.id AS ziel, rel.name AS feld
     FROM ' . Schema::table('relations') . ' rel
     INNER JOIN ' . Schema::table('nodes') . ' v ON v.id = rel.from_id
     INNER JOIN ' . Schema::table('nodes') . " z ON z.id = rel.to_id
     WHERE z.name = 'Adresse' AND rel.kind <> 'inheritance' LIMIT 1",
    ARRAY_A
);

if ($zusammengesetzt === null) {
    check('ein Feld zeigt auf «Adresse»', false, 'keines gefunden');
} else {
    check('ein Feld zeigt auf «Adresse»', true);

    $innen = $wpdb->get_col($wpdb->prepare(
        'SELECT rel.name FROM ' . Schema::table('relations') . " rel
         WHERE rel.from_id = %d AND rel.kind <> 'inheritance' AND rel.hide = 0 AND rel.kind <> 'setting'",
        (int) $zusammengesetzt['ziel']
    )) ?: [];

    check('«Adresse» hat mehrere eigene Felder', count($innen) >= 4, count($innen) . ': ' . implode(', ', $innen));

    $html = seiteVon((int) $zusammengesetzt['besitzer']);
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    $von  = strpos($html, '>Preview<');
    $bis  = $von === false ? false : strpos($html, '>Used by<', $von);
    $teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    $fehlen = [];

    foreach ($innen as $feld) {
        if (! str_contains($teil, (string) $feld)) {
            $fehlen[] = (string) $feld;
        }
    }

    check(
        'und die Vorschau nennt sie alle',
        $fehlen === [],
        'fehlt: ' . implode(', ', $fehlen)
    );

    // ⚠️ **Der Gegenfall: nicht nur Wörter, sondern Bedienelemente.** *Ein Behälter, der die Namen
    // ausgibt und keine Eingabe zeichnet, wäre oben grün — und wäre genau das, was vorher war.*
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/', $teil, $bedienung);

    check(
        'und zeichnet je Feld eine Bedienung',
        count($bedienung[0]) >= count($innen),
        count($bedienung[0]) . ' fuer ' . count($innen) . ' Felder'
    );
}

echo "\n== Die Auswahl sieht durch einen markierten Zwischenknoten hindurch ==\n";

// ⚠️ **Auf sein Wort: «table, form, compact muss wählbar bleiben, warum auch nicht?»** *Sie waren es
// nicht mehr: er hatte «render label roles» und «render with label» zu einem zusammengelegt und die
// Renderer darunter geschoben, und damit sind sie **Enkel** von `Renderer`.
// [D-540](../../docs/NewConcept/90-decision-log.md) spricht von **Kindern** — den Zwischenknoten kannte
// die Entscheidung noch nicht.*
//
// ⚠️ *Die Erweiterung folgt aus ihren eigenen Worten: «unmarkiert» ist dort die Bedingung, also heisst
// **markiert** «kein Wert, nur Struktur» — und die Auswahl steigt hindurch. Gemessen vorher: die Liste
// bot «render with label» an und weder `form` noch `table` noch `compact`.*
$id   = knotenId('Kontact');
$html = $id === 0 ? '' : seiteVon($id);

if ($html === '') {
    check('ein Knoten mit geerbter Renderer-Einstellung', false);
} else {
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    // Die Zelle, in der die Einstellung ihren Wert zeigt.
    preg_match_all('/<td class="taxmod-field-value[^"]*"[^>]*>(.*?)<\/td>/s', $html, $zellen);

    $angebot = [];

    foreach ($zellen[1] as $zelle) {
        if (! str_contains($zelle, 'render')) {
            continue;
        }

        preg_match_all('/<option value="[^"]*"[^>]*>([^<]*)<\/option>/', $zelle, $treffer);
        $angebot = [...$angebot, ...$treffer[1]];
    }

    foreach (['form', 'table', 'compact'] as $wunsch) {
        check("«{$wunsch}» steht zur Wahl", in_array($wunsch, $angebot, true), implode(', ', array_slice($angebot, 0, 20)));
    }

    // ⚠️ **Der Gegenfall: der Zwischenknoten selbst ist keine Möglichkeit.** *Ohne ihn wäre «alles
    // anbieten» ebenfalls grün — und «render with label» ist kein Renderer.*
    check(
        'und der Zwischenknoten selbst nicht',
        ! in_array('render with label', $angebot, true),
        implode(', ', $angebot)
    );
}

echo "\n== Die Vorschau hat drei Seiten, und Admin mischt keine Einstellungen ein ==\n";

// ⚠️ **Seine Entscheidung** ([D-547](../../docs/NewConcept/90-decision-log.md)), *gegen meinen
// Widerspruch: «und da du dich wehrst, entscheide ich jetzt: es gibt eine dritte Form neben Admin und
// Show, machen wir jetzt Settings.»*
//
// ⚠️ **Widerlegt hat mich seine Messung an der Seite.** *Ich hatte die Einstellungen in die
// Admin-Seite gelegt — und auf `Adresse` standen sie **zwischen** den Feldern: Street, Display Option,
// No., validator, Post Code … Er: «man sieht den Render in Settings, aber auch in der Preview vom
// Modell, und das darf nicht sein.»*
$id   = knotenId('Adresse');
$html = $id === 0 ? '' : seiteVon($id);

if ($html === '') {
    check('«Adresse» steht im Modell', false);
} else {
    $html = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $html) ?? $html;

    $von  = strpos($html, '>Preview<');
    $bis  = $von === false ? false : strpos($html, '>Used by<', $von);
    $teil = $von === false ? '' : substr($html, $von, ($bis === false ? strlen($html) : $bis) - $von);

    preg_match_all('/<h4>([^<]*)<\/h4>(.*?)(?=<h4>|$)/s', $teil, $seiten, PREG_SET_ORDER);

    $ueberschriften = array_map(static fn (array $s): string => $s[1], $seiten);

    check('drei Seiten', count($seiten) === 3, implode(', ', $ueberschriften));

    foreach (['Display', 'Admin', 'Settings'] as $wunsch) {
        check("  · «{$wunsch}»", in_array($wunsch, $ueberschriften, true), implode(', ', $ueberschriften));
    }

    $nachName = [];

    foreach ($seiten as $s) {
        $nachName[$s[1]] = trim((string) preg_replace('/\s+/', ' ', strip_tags($s[2])));
    }

    // ⚠️ *Genau der Fall, den er gemeldet hat.*
    check(
        'Admin nennt keine Einstellung',
        ! str_contains($nachName['Admin'] ?? '', 'Display Option')
        && ! str_contains($nachName['Admin'] ?? '', 'read_only'),
        substr($nachName['Admin'] ?? '', 0, 80)
    );

    // ⚠️ **Die Gegenfälle.** *Ohne sie wären auch drei leere Seiten grün — und «Admin nennt keine
    // Einstellung» wäre am billigsten dadurch erfüllt, dass Admin gar nichts nennt.*
    check(
        'aber seine Felder',
        str_contains($nachName['Admin'] ?? '', 'Street'),
        substr($nachName['Admin'] ?? '', 0, 80)
    );

    check(
        'und Settings nennt die Einstellungen',
        str_contains($nachName['Settings'] ?? '', 'Display Option'),
        substr($nachName['Settings'] ?? '', 0, 80)
    );

    check(
        'und keines seiner Felder',
        ! str_contains($nachName['Settings'] ?? '', 'Street'),
        substr($nachName['Settings'] ?? '', 0, 80)
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
