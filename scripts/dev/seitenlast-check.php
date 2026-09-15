<?php declare(strict_types=1);

/**
 * Die Last der Knotenseite: Abfragen, Markup und wiederholte Abfragen auf den Bezugsseiten.
 *
 *     php scripts/dev/seitenlast-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-818](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«F6 but try to reduce what is possible».* Die Grenzen sind
 * Decken — **höchstens 150 Abfragen und 1 MB Markup je Bezugsseite** —, und der Lauf schreibt die gemessenen Zahlen mit, damit ein
 * Rückschritt sichtbar wird, bevor er die Decke erreicht.
 *
 * ⚠️ **Und die dritte Zusage ist die, die das Übel selbst bewacht** ([D-814](../../docs/NewConcept/90-decision-log.md)): *keine
 * Abfrage steht auf einer Seite öfter als 20-mal da. Gemessen am 2026-09-14 stand eine Einstellungsabfrage ~320-mal auf jeder Seite —
 * eine je Knoten. Eine Schleife, die wiederkommt, wird hier rot, auch wenn die Summe noch unter der Decke liegt.*
 *
 * ⚠️ *Die Bezugsseiten sind Knoten seines Modells, an ihrer Id — die fünf langsamsten der Messung vom 2026-09-14 (D-818). Fehlt
 * einer, ist das ein Befund und kein Grund zu raten: der Lauf sagt es, und die Liste ändert sich sichtbar.*
 *
 * @see docs/NewConcept/90-decision-log.md D-814, D-818
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);
define('SAVEQUERIES', true);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

const TAXMOD_MAX_QUERIES = 150;
const TAXMOD_MAX_BYTES   = 1048576;
const TAXMOD_MAX_REPEAT  = 20;

/** @var array<int, string> Id => Name zur Zeit von D-818. */
const TAXMOD_REFERENCE_PAGES = [
    149000104028 => 'Mikrocontroller',
    149000104099 => 'Kompatibilität',
    149000103845 => 'CPUs',
    27           => 'Parts List',
    149000103929 => 'Prozessoren',
];

global $wpdb;

$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed;

    if (! $ok) {
        $failed++;
    }

    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . ' ' . $what . ($detail === '' ? '' : ' — ' . $detail) . "\n";
}

function seite(int $id): string
{
    $_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $id];
    $_REQUEST = $_GET;
    $rc       = new ReflectionClass(Plugin::class);
    $plugin   = $rc->newInstanceWithoutConstructor();
    $rc->getProperty('file')->setValue($plugin, __FILE__);

    return $plugin->screen()->render();
}

/** Eine Anweisung ohne ihre Werte — gleiche Form, gleicher Schlüssel. */
function form(string $sql): string
{
    return (string) preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/\b\d+\b/', '/\(\s*\?(\s*,\s*\?)*\s*\)/', '/\s+/'], ['?', '?', '(?)', ' '], $sql);
}

wp_set_current_user(1);

echo "seitenlast — Abfragen und Markup der Bezugsseiten (D-814, D-818)\n";

// ⚠️ *Ein erster Aufruf zum Aufwärmen: er lädt Klassen und Optionen, die jede echte Anfrage ebenso lädt, aber nicht zweimal zählen soll.*
seite(array_key_first(TAXMOD_REFERENCE_PAGES));

$p = $wpdb->prefix . 'taxmod_';

foreach (TAXMOD_REFERENCE_PAGES as $id => $name) {
    echo "\n== {$name} (#{$id}) ==\n";

    $da = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}nodes WHERE id = %d", $id));

    check('die Bezugsseite gibt es', $da === 1, 'Knoten fehlt — die Liste in D-818 ist zu erneuern');

    if ($da !== 1) {
        continue;
    }

    $wpdb->queries = [];
    $start         = hrtime(true);
    $markup        = seite($id);
    $ms            = (hrtime(true) - $start) / 1e6;
    $abfragen      = count($wpdb->queries);
    $formen        = [];

    foreach ($wpdb->queries as [$sql]) {
        $f          = form((string) $sql);
        $formen[$f] = ($formen[$f] ?? 0) + 1;
    }

    arsort($formen);
    $haeufigste = (int) (reset($formen) ?: 0);

    printf("       %.0f ms, %d Abfragen, %d KB, häufigste Abfrage %d-mal\n", $ms, $abfragen, strlen($markup) / 1024, $haeufigste);

    check('höchstens ' . TAXMOD_MAX_QUERIES . ' Abfragen', $abfragen <= TAXMOD_MAX_QUERIES, (string) $abfragen);
    check('höchstens 1 MB Markup', strlen($markup) <= TAXMOD_MAX_BYTES, (string) intdiv(strlen($markup), 1024) . ' KB');
    check(
        'keine Abfrage öfter als ' . TAXMOD_MAX_REPEAT . '-mal',
        $haeufigste <= TAXMOD_MAX_REPEAT,
        $haeufigste . '-mal: ' . substr((string) key($formen), 0, 160)
    );
    check('der gemeinsame Auswahlbaum steht genau einmal da (D-815)', substr_count($markup, 'class="taxmod-shared-pick"') === 1, (string) substr_count($markup, 'class="taxmod-shared-pick"'));
}

echo "\n" . ($failed === 0 ? 'all green' : "{$failed} FAIL") . "\n";

exit($failed === 0 ? 0 : 1);
