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

check(
    '«form» zeigt seine Einstellungen unter Settings',
    count($gesehen) >= 3,
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
$id   = knotenId('render with label');
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

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
