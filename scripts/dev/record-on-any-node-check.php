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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RecordKind;
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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$editor    = new ModelEditor($nodes, $edges, $framework, $log);
$data      = new DataEntry(new WpdbRecordRepository(), $edges, $nodes, $framework, new SystemClock());

/** @var list<int> Alles, was dieser Lauf angelegt hat. */
$meine = [];

register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach ($meine as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $id));
    }
});

echo "\n== 1. Eine Konstante nimmt einen Datensatz an ==\n";

// ⚠️ *`kilo` über den Namen unter `Prefixes` gesucht — es gibt keine Option dafür, und der Knoten
// gehört der Saat, nicht dem Rahmenwerk. Findet sie ihn nicht, sagt die Prüfung das, statt still
// durchzulaufen.*
$prefixes = null;

foreach ($nodes->childrenOf($framework->rootOf(Branch::Constants)) as $child) {
    if ($child->name === 'Prefixes') {
        $prefixes = $child;
    }
}

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

    $exponent = null;

    foreach ($editor->fieldsOf($kilo->id) as $edge) {
        if ($edge->name === 'exponent') {
            $exponent = $edge;
        }
    }

    if ($exponent === null) {
        check('die Konstante erbt ein Feld «exponent»', false);
    } else {
        // ⚠️ **Gefangen und nicht durchgelassen.** *Ohne das stürzt die Prüfung ab, sobald das Tor
        // wieder nach dem Zweig fragt — und **eine abstürzende Prüfung überspringt ihre restlichen
        // Zusicherungen**, was genau die Sorte «grün aus dem falschen Grund» ist, die dieses Projekt
        // schon zweimal erwischt hat.*
        $record = null;

        try {
            $record  = $data->create($kilo->id);
            $meine[] = $record->id;
        } catch (NotYetStorable $e) {
            check('ein Datensatz an der Konstanten lässt sich anlegen', false, $e->getMessage());
        }

        $verweigert = null;

        if ($record !== null) {
            check('ein Datensatz an der Konstanten lässt sich anlegen', $record->id > 0, (string) $record->id);

            // ⚠️ **Umgedreht am 2026-09-01, und das ist der sichtbare Teil von [D-538](../../docs/NewConcept/90-decision-log.md).**
            // *Diese Prüfung schrieb hier einen Wert und verlangte, dass er unter der Kanten-Id steht.
            // **`exponent` ist aber eine Einstellungskante** (4654, `kind = setting`), und seit D-538
            // gilt: «für den Benutzer werden ja nur die Felder gespeichert, nicht die Settings, weil
            // die Settings Eigenschaften des Modells sind». **Die Prüfung hatte sich genau das
            // Beispiel gesucht, das die Entscheidung unmöglich macht** — und stürzte deshalb ab,
            // statt rot zu werden. Ein Absturz überspringt die restlichen Zusicherungen.*
            try {
                $data->put($record->id, $exponent->id, TypedValue::ofInt(3));
            } catch (NotYetStorable $e) {
                $verweigert = $e->getMessage();
            }
        }

        check(
            'eine Einstellungskante verweigert den Datensatzwert, mit Begründung',
            $verweigert !== null && str_contains($verweigert, 'setting'),
            $verweigert ?? 'nicht verweigert — der Wert wurde geschrieben'
        );

        $roh = $record === null ? null : $wpdb->get_row($wpdb->prepare(
            'SELECT edge_id, path, value_int FROM ' . Schema::table('record_values') . ' WHERE record_id = %d',
            $record->id
        ), ARRAY_A);

        // ⚠️ **Das ist die Zusage, um die es geht:** *der Wert steht unter der **Kanten-Id** des
        // Feldes — nicht unter einem Namen wie `default`. **Damit ist eine Einstellung und ein
        // Datensatzwert dieselbe Zeile in zwei Tabellen**, und eine davon kann fallen.*
        // ⚠️ **Die Gegenprobe zur Verweigerung: es darf auch wirklich nichts dastehen.** *Ohne sie
        // wäre die Zusage oben aus einem Grund grün, der nichts beweist — eine Ausnahme kann fliegen,
        // nachdem geschrieben wurde.*
        check(
            'und es steht wirklich kein Wert da',
            $roh === null,
            json_encode($roh)
        );
    }
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

// WICHTIG: Ausserhalb des Settings-Astes, und das ist eine sichtbare Aenderung dieser Zusage
// (PR-9). Sie verlangte es von *jedem* Knoten und war gruen, solange der Settings-Ast klein
// war. Gemessen am 2026-09-04: 20 von 131 ohne Feld, und alle 20 liegen unter Settings --
// Label roles, Converter, Validator, Orientation und ihre Kinder. Dass ein Einstellungsknoten
// die Modellfelder nicht erbt, ist richtig; die Zusage war zu weit gefasst.
$settings = $framework->rootOf(Branch::Settings);
$alle     = $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes'));
$ohne     = 0;
$inSettings = 0;

foreach ($alle as $id) {
    if ($editor->fieldsOf((int) $id) !== []) {
        continue;
    }

    $knoten = $nodes->find((int) $id);

    if ($knoten !== null && ($knoten->id === $settings->id || $knoten->isDescendantOf($settings))) {
        $inSettings++;

        continue;
    }

    $ohne++;
}

printf("  --   %d Knoten im Settings-Ast ohne Feld, und das ist richtig
", $inSettings);

// ⚠️ **Solange die Wurzel Felder erklärt, hat **jeder** Knoten Felder** — gemessen 0 von 129 ohne.
// *Also zeigt der Datensätze-Bereich überall, auch am Müll und an den Zweigwurzeln. **Das ist Lärm,
// und es ist zugleich der Fall, den der Eigentümer will**: ein Datensatz an der Wurzel ist der
// Renderer, den alles erbt. Eine Ausnahme für «Maschinerie» nähme die Wurzel mit, darum gibt es
// keine — aber die Zahl steht hier, damit die Folge sichtbar bleibt statt vergessen zu werden.*
check(
    'jeder Knoten ausserhalb von Settings hat Felder',
    $ohne === 0,
    $ohne . ' von ' . count($alle) . ' ohne Feld'
);

echo "\n== 3b. Die Marke am Datensatz — drei Zustände, nicht zwei ==\n";

// ⚠️ **[C65](../../docs/NewConcept/10-domain-core.md) hatte es seit dem 2026-08-23:** *«a checkbox
// «is test data» / «is default value» would do it»* — **drei Zustände**, und `is_test` konnte zwei.
$spalten = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . Schema::table('records'), ARRAY_A), 'Field');

check('records hat die Spalte kind', in_array('kind', $spalten, true), implode(', ', $spalten));
check('und is_test ist weg — nicht danebengestellt', ! in_array('is_test', $spalten, true));

$fremd = array_values(array_filter(
    $wpdb->get_col('SELECT DISTINCT kind FROM ' . Schema::table('records')),
    static fn ($v): bool => RecordKind::tryFrom((string) $v) === null
));

check('keine Marke, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

// ⚠️ **Die Zusage, die die Marke überhaupt rechtfertigt:** *ein Autorenwert und eine Benutzerzeile
// hängen am **selben** Knoten und müssen sich trotzdem unterscheiden lassen — sonst erscheint die
// Vorgabe von `Parts List.Name` als vierte Stückliste.*
if ($kilo !== null) {
    $alsAutor  = $data->create($kilo->id, RecordKind::Default);
    $meine[]   = $alsAutor->id;

    check('ein Datensatz lässt sich als Autorenwert anlegen', $alsAutor->kind === RecordKind::Default, $alsAutor->kind->value);

    $zurueck = $data->find($alsAutor->id);

    check('und die Marke liest sich zurück', $zurueck?->kind === RecordKind::Default, $zurueck?->kind->value ?? 'null');

    $roh = $wpdb->get_var($wpdb->prepare(
        'SELECT kind FROM ' . Schema::table('records') . ' WHERE id = %d',
        $alsAutor->id
    ));

    check('auch roh aus der Spalte', $roh === 'default', var_export($roh, true));
}

echo "\n== 4. Die Prüfung lässt nichts liegen ==\n";

$vorher = count($meine);

foreach ($meine as $id) {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $id));
}

$uebrig = $meine === [] ? 0 : (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('records') . ' WHERE id IN (' . implode(',', array_map('intval', $meine)) . ')'
);

$meine = [];

check('die ' . $vorher . ' angelegten Datensätze sind wieder weg', $uebrig === 0, (string) $uebrig);

echo "\n" . ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
