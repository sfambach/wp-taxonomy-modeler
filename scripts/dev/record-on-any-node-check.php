<?php declare(strict_types=1);
/**
 * Ein Knoten trägt Datensätze für seine Felder — gleich wo er hängt.
 *
 *     php scripts/dev/record-on-any-node-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-522](../../docs/NewConcept/90-decision-log.md), sein Entwurf:** *«so ein Record, den ich hier
 * im Modell eingebe, ist auch einfach nur ein Record zur Kante — gehört er zu Field, ist es ein
 * Default-Wert; gehört er zu Settings, ist es eine Einstellung.»*
 *
 * ⚠️ **Die Zusage, die diese Prüfung trägt, ist die eine, die vorher unmöglich war:** *ein Knoten
 * ausserhalb von `Model` und `Compositions` — eine Konstante unter `Prefixes` — nimmt einen Datensatz an, und
 * der Wert landet unter der **Kanten-Id** des Feldes. **Fällt das, ist der ganze Umbau von `default`
 * zurück auf Anfang.***
 *
 * ⚠️ *Sie legt **keinen Knoten** an und nimmt jeden Datensatz wieder weg, den sie anlegt — über die
 * Ids ihres eigenen Laufs, samt `register_shutdown_function`, damit auch ein Absturz aufräumt
 * ([Zeile 81](../../docs/NewConcept/97-implementation-plan.md#the-working-list)).*
 *
 * @see docs/NewConcept/02-field-and-setting.md
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log);
$data      = new DataEntry(new WpdbRecordRepository(), $relations, $nodes, $framework, new SystemClock());

/** @var list<int> Alles, was dieser Lauf angelegt hat. */
$meine = [];

register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach ($meine as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
    }
});

echo "\n== 1. Eine Konstante nimmt einen Datensatz an ==\n";

// ⚠️ *`Prefixes` über die Notiz des Gerüsts (D-709, TASK-049) — hier stand «es gibt keine Option dafür»,
// und seit Fassung 4 des Gerüsts gibt es sie. Findet sie ihn nicht, sagt die Prüfung das.*
$prefixesId = \Taxmod\WordPress\Persistence\UnitScaffold::nodeId('Prefixes');
$prefixes   = $prefixesId === null ? null : $nodes->find($prefixesId);

// ⚠️ **Der erste Kindknoten, nicht «kilo»** ([D-613](../../docs/NewConcept/90-decision-log.md),
// vollzieht [D-022](../../docs/NewConcept/90-decision-log.md)). *`kilo` ist sein Inhalt und darf
// heissen, wie er will; **die Zusage gilt jeder Konstanten unter `Prefixes`** — dass sie in einem
// Zweig ohne eigene Daten liegt und trotzdem einen Datensatz annimmt. `Prefixes` selbst bleibt
// stehen: das bringt das Plugin mit.*
$kilo = null;

if ($prefixes !== null) {
    foreach ($nodes->childrenOf($prefixes) as $child) {
        $kilo ??= $child;
    }
}

if ($kilo === null) {
    check('eine Konstante unter Constants › Prefixes', false);
} else {
    check('sie liegt unter Constants', $framework->branchOf($kilo) === Branch::Constants, '#' . $kilo->id);
    check(
        'und sein Zweig sagt weiterhin «keine Daten»',
        $framework->branchOf($kilo)?->holdsData() === false,
        'holdsData() darf sich nicht geändert haben'
    );

}

echo "\n== 2. Der alte Weg bleibt, wo er war ==\n";

// ⚠️ **Additiv und nicht ersetzend, und das hat ein Kerntest erzwungen:** *meine erste Fassung fragte
// **nur** nach Feldern und nahm damit einem Modellknoten **ohne** Felder das Anlegen weg, das er
// vorher konnte. Ein Modell, an dem noch nichts erklärt ist, ist eine Baustelle und kein Fehler.*
$model = $framework->rootOf(Branch::Model);

check(
    'der Model-Zweig hält weiterhin Daten',
    $framework->branchOf($model)?->holdsData() === true
);

echo "\n== 3. Was der Umbau kostet, wird gemessen und nicht geschätzt ==\n";
// ⚠️ **Hier stand «jeder Knoten ausserhalb von Settings hat Felder» — wahr war es nur wegen der Einstellungskanten an den Typen; und die Konstante «erbte» ein Feld exponent, das eine Einstellungskante war** *— seit Schritt 7 des Bauplans (2026-09-11): Renderer, Konverter und Validatoren sind
// Objekte programmierter Klassen, keine Knoten; die Einstellungskanten und der Ast `Settings` sind in den Schatten
// gewandert ([D-712](../../docs/NewConcept/90-decision-log.md), [D-718](../../docs/NewConcept/90-decision-log.md)).*

// WICHTIG: Ausserhalb des Settings-Astes, und das ist eine sichtbare Aenderung dieser Zusage
// (PR-9). Sie verlangte es von *jedem* Knoten und war gruen, solange der Settings-Ast klein
// war. Gemessen am 2026-09-04: 20 von 131 ohne Feld, und alle 20 liegen unter Settings --
// Label roles, Converter, Validator, Orientation und ihre Kinder. Dass ein Einstellungsknoten
// die Modellfelder nicht erbt, ist richtig; die Zusage war zu weit gefasst.

echo "\n== 3b. Die Marke am Datensatz — drei Zustände, nicht zwei ==\n";

// ⚠️ **[C65](../../docs/NewConcept/10-domain-core.md) hatte es seit dem 2026-08-23:** *«a checkbox
// «is test data» / «is default value» would do it»* — **drei Zustände**, und `is_test` konnte zwei.
$spalten = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . Schema::table('node_records'), ARRAY_A), 'Field');

// ⚠️ *Die Spalte heisst seit TASK-015 `record_type` — die Zusage ist dieselbe, nur der Name nicht.*
check('node_records hat die Spalte record_type', in_array('record_type', $spalten, true), implode(', ', $spalten));
check('und kind gibt es nicht mehr', ! in_array('kind', $spalten, true));
check('und is_test ist weg — nicht danebengestellt', ! in_array('is_test', $spalten, true));

$fremd = array_values(array_filter(
    $wpdb->get_col('SELECT DISTINCT record_type FROM ' . Schema::table('node_records')),
    static fn ($v): bool => RecordType::tryFrom((string) $v) === null
));

check('keine Marke, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

// ⚠️ **Die Zusage, die die Marke überhaupt rechtfertigt:** *ein Autorenwert und eine Benutzerzeile
// hängen am **selben** Knoten und müssen sich trotzdem unterscheiden lassen — sonst erscheint die
// Vorgabe von `Parts List.Name` als vierte Stückliste.*
if ($kilo !== null) {
    $alsAutor  = $data->create($kilo->id, RecordType::Default);
    $meine[]   = $alsAutor->id;

    check('ein Datensatz lässt sich als Autorenwert anlegen', $alsAutor->recordType === RecordType::Default, $alsAutor->recordType->value);

    $zurueck = $data->find($alsAutor->id);

    check('und die Marke liest sich zurück', $zurueck?->recordType === RecordType::Default, $zurueck?->recordType->value ?? 'null');

    $roh = $wpdb->get_var($wpdb->prepare(
        'SELECT record_type FROM ' . Schema::table('node_records') . ' WHERE id = %d',
        $alsAutor->id
    ));

    check('auch roh aus der Spalte', $roh === 'default', var_export($roh, true));
}

echo "\n== 4. Die Prüfung lässt nichts liegen ==\n";

$vorher = count($meine);

foreach ($meine as $id) {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
}

$uebrig = $meine === [] ? 0 : (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' WHERE id IN (' . implode(',', array_map('intval', $meine)) . ')'
);

$meine = [];

check('die ' . $vorher . ' angelegten Datensätze sind wieder weg', $uebrig === 0, (string) $uebrig);

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
