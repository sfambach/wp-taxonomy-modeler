<?php declare(strict_types=1);
/**
 * `relations.sort_order` — eine Stelle je Knoten und Kantenart, und die erste ist `0` (TASK-012).
 *
 *     php scripts/dev/sort-order-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Eigentümer:** *«Position würde ich eher Order nennen. Und die erste Position ist immer
 * null … die Sort Order entsteht pro Knoten, und zwar dem From-Knoten.»*
 *
 * ⚠️ **Die dritte Spalte im Schlüssel ist der ganze Befund.** *Meine gemeldeten «17 doppelten
 * Reihenfolgen» waren keine: alle Gruppen mischen Kantenarten — Kind im Baum gegen Feld des Knotens
 * —, und nicht eine Doppelung lag innerhalb derselben Art. **Ein Schlüssel auf
 * `(from_id, sort_order)` hätte 17 gültige Zeilen abgelehnt.** Dieser Lauf misst beides und zeigt den
 * Unterschied, damit die Begründung nachprüfbar bleibt und nicht nur behauptet ist.*
 *
 * Geprüft wird viererlei:
 *
 * 1. **Die Spalte heisst `sort_order`**, lebend und im Schatten, und `position` gibt es nicht mehr.
 * 2. **Der Schlüssel steht** — eindeutig über `(from_id, kind, sort_order)` — **und der alte
 *    Einzelindex auf `from_id` ist fort.**
 * 3. **Keine Stelle ist zweimal vergeben.** *Dass die erste jeder Liste `0` ist, wird **gezählt und
 *    nicht verlangt** — siehe `INF-022`.*
 * 4. **Ein Tausch tauscht wirklich** — der Fall, an dem der Schlüssel den Schreibweg brach, ohne
 *    dass etwas rot wurde.
 *
 * ⚠️ *Er legt nichts an und räumt nichts weg: Punkt 4 tauscht zwei bestehende Kanten und tauscht sie
 * zurück (TASK-025, TASK-039, TASK-047).*
 *
 * @see docs/pakete/modelltabellen/package.md
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

use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

function spalten(string $tabelle): array
{
    global $wpdb;

    return array_map(
        static fn (array $z): string => (string) $z['Field'],
        $wpdb->get_results("SHOW COLUMNS FROM {$tabelle}", ARRAY_A) ?: []
    );
}

$relations = Schema::table('relations');
$schatten  = Schema::table('relations_history');

echo "1 · Die Spalte heisst sort_order\n";

$lebend = spalten($relations);
$alt    = spalten($schatten);

check('relations.sort_order', in_array('sort_order', $lebend, true));
check('relations hat kein position mehr', ! in_array('position', $lebend, true));
check('relations_history.sort_order', in_array('sort_order', $alt, true), 'der Schatten zieht mit');

if ($bad > 0) {
    echo "\nOhne die Spalte hat der Rest nichts zu prüfen.\n";

    exit(1);
}

echo "\n2 · Der Schlüssel steht über drei Spalten\n";

/** @var list<array{Key_name: string, Column_name: string, Non_unique: string, Seq_in_index: string}> $indizes */
$indizes = $wpdb->get_results("SHOW INDEX FROM {$relations}", ARRAY_A) ?: [];

$dreier = [];

foreach ($indizes as $eintrag) {
    if ((int) $eintrag['Non_unique'] === 0 && $eintrag['Key_name'] !== 'PRIMARY') {
        $dreier[$eintrag['Key_name']][(int) $eintrag['Seq_in_index']] = $eintrag['Column_name'];
    }
}

$gefunden = false;

foreach ($dreier as $spaltenFolge) {
    ksort($spaltenFolge);

    if (array_values($spaltenFolge) === ['from_id', 'kind', 'sort_order']) {
        $gefunden = true;
    }
}

check(
    'eindeutig über (from_id, kind, sort_order)',
    $gefunden,
    implode(' · ', array_map(
        static fn (array $s): string => implode('+', $s),
        $dreier
    ))
);

// ⚠️ *Der neue Schlüssel beginnt mit `from_id` und dient damit als Suchindex. **Zwei Indizes über
// dieselbe führende Spalte sind Doppelung**, und `dbDelta` räumt einen bestehenden nie von selbst ab.*
$einzeln = false;

foreach ($indizes as $eintrag) {
    if ($eintrag['Key_name'] === 'from_id') {
        $einzeln = true;
    }
}

check('und der alte Einzelindex auf from_id ist fort', ! $einzeln);

echo "\n3 · Keine Stelle ist zweimal vergeben, und die erste ist 0\n";

$mitArt = $wpdb->get_results(
    "SELECT from_id, kind, sort_order, COUNT(*) c FROM {$relations}
     GROUP BY from_id, kind, sort_order HAVING c > 1",
    ARRAY_A
) ?: [];

$ohneArt = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (SELECT from_id, sort_order FROM {$relations}
     GROUP BY from_id, sort_order HAVING COUNT(*) > 1) x"
);

check(
    'keine Doppelung innerhalb einer Kantenart',
    $mitArt === [],
    implode(' · ', array_map(
        static fn (array $z): string => $z['from_id'] . '/' . $z['kind'] . '@' . $z['sort_order'],
        array_slice($mitArt, 0, 5)
    ))
);

printf(
    "       gemessen: mit der Kantenart %d Verletzungen, ohne sie %d — das ist der Grund für die dritte Spalte\n",
    count($mitArt),
    $ohneArt
);

$ohneNull = $wpdb->get_results(
    "SELECT from_id, kind, MIN(sort_order) erste FROM {$relations}
     GROUP BY from_id, kind HAVING erste <> 0",
    ARRAY_A
) ?: [];

// ⚠️ **Gezählt und nicht verlangt, und das ist eine benannte Lücke** (`INF-022`). *Der Eigentümer
// sagt «die erste Position ist immer null» — **gemessen beginnen mehrere Listen bei 1**, weil eine
// gelöschte Kante eine Lücke hinterlässt. **Ob sein Satz heisst «gezählt wird ab null» oder «die
// Liste ist lückenlos», sagt keine Entscheidung** (`PR-4`), und die Listen umzunummerieren wäre eine
// Handlung an seinen Daten, die niemand verlangt hat. Also steht die Zahl da, bis er sie beantwortet.
printf(
    "       gemessen: %d Listen beginnen nicht bei 0 — offen als INF-022, nicht umnummeriert\n",
    count($ohneNull)
);

echo "\n4 · Ein Tausch tauscht wirklich\n";

// ⚠️ **Das ist die Zusage, die der Schlüssel gebrochen hat, ohne dass etwas rot wurde.** *Ein Tausch
// schreibt zwangsläufig einmal auf eine besetzte Stelle; MySQL weist das zurück, und `$wpdb` sagt
// darüber nichts. `ModelEditor` geht seither über eine freie Stelle.*
$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$editor    = new ModelEditor($nodes, $edges, $framework, $log);

$paar = $wpdb->get_results(
    "SELECT from_id FROM {$relations} WHERE kind = 'inheritance'
     GROUP BY from_id HAVING COUNT(*) > 1 ORDER BY from_id LIMIT 1",
    ARRAY_A
) ?: [];

if ($paar === []) {
    check('ein Elternknoten mit zwei Kindern war zu finden', false);
} else {
    $eltern = (int) $paar[0]['from_id'];
    $kinder = array_map(
        static fn ($n): int => $n->id,
        $editor->childrenOf($eltern)
    );

    $vorher = array_slice($kinder, 0, 2);

    $editor->moveUp($vorher[1]);

    $nachher = array_slice(array_map(
        static fn ($n): int => $n->id,
        $editor->childrenOf($eltern)
    ), 0, 2);

    check(
        'die beiden ersten Kinder haben getauscht',
        $nachher === [$vorher[1], $vorher[0]],
        implode(',', $vorher) . ' → ' . implode(',', $nachher)
    );

    // ⚠️ *Zurück, auch wenn es rot war — dieser Lauf lässt das Modell, wie er es fand.*
    $editor->moveUp($vorher[0]);

    $zurueck = array_slice(array_map(
        static fn ($n): int => $n->id,
        $editor->childrenOf($eltern)
    ), 0, 2);

    check('und der Lauf hat zurückgetauscht', $zurueck === $vorher, implode(',', $zurueck));
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
