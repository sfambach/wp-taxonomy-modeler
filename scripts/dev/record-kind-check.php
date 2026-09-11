<?php declare(strict_types=1);

/**
 * Vier Satzarten, und die Einstellungen wohnen in der vierten — und die Grenzen in den Grenzknoten.
 *
 *     php scripts/dev/record-kind-check.php [path/to/wordpress]
 *
 * ⚠️ **Zwei Beschlüsse des Eigentümers vom 2026-09-09:** [D-704](../../docs/NewConcept/90-decision-log.md)
 * *«ich finde es auch das die vier arten es genauer machen sollten wir so festlegen»* — und
 * [D-707](../../docs/NewConcept/90-decision-log.md) *«ja wenn nichts in der kante gesetzt ist gilt der
 * Wert des Zielknoten (wenn einer da ist)»*.
 *
 * ```mermaid
 * flowchart LR
 *   B["Bestand"] -->|"jede Einstellungszeile"| S["liegt in einem settings-Satz"]
 *   B -->|"kein default/user/example"| N["trägt eine Einstellungszeile"]
 *   W["schreiben an einem frischen Knoten"] --> S2["legt einen settings-Satz an, keinen default"]
 *   V["Verwendungsstelle schreiben"] --> S3["ihr Satz ist settings"]
 *   G["Integer ohne Kantenwert"] -->|"Kette"| M["min aus integer_min, max aus integer_max"]
 *   K["Kind setzt min enger"] --> K2["näher schlägt ferner"]
 * ```
 *
 * ⚠️ *Gemessen am Bestand **und** an einer eigenen Wiese (Präfix `__rk`): der Bestand sagt, dass die
 * Fassung 42 gelaufen ist; die Wiese sagt, dass der Rand seither richtig schreibt.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\Type\DecimalType;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  ok   {$what}\n";

        return;
    }

    $failed++;
    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

$saetze    = Schema::table('node_records');
$werte     = Schema::table('relation_records');
$kanten    = Schema::table('relations');
$benannt   = Schema::table('nodes_named');

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $rows);
$data      = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock(), $log);
$werteDesModells = static fn (): ModelValues => new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework);

echo "== 1. der Bestand nach Fassung 42 (D-704) ==\n";

$falsch = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT s.id) FROM {$saetze} s
       JOIN {$werte} v ON v.node_record_id = s.id
       JOIN {$kanten} r ON r.id = v.relation_id AND r.kind = 'setting'
      WHERE s.record_type <> 'settings'"
);
check('jede Einstellungszeile liegt in einem settings-Satz', $falsch === 0, "{$falsch} Sätze anderer Art tragen eine");

$fremd = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id
       LEFT JOIN {$kanten} r ON r.id = v.relation_id
      WHERE s.record_type = 'settings' AND v.relation_id <> 0 AND (r.kind IS NULL OR r.kind <> 'setting')"
);
check('und ein settings-Satz trägt nichts anderes', $fremd === 0, "{$fremd} fremde Zeilen");

$stellen = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$saetze} WHERE relation_id <> 0 AND record_type <> 'settings'");
check('der Satz einer Verwendungsstelle ist settings (D-702)', $stellen === 0, "{$stellen} andere");

$doppelt = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (SELECT node_id, relation_id FROM {$saetze} WHERE record_type = 'settings' GROUP BY node_id, relation_id HAVING COUNT(*) > 1) d"
);
check('einer je Adresse, nie zwei (D-538)', $doppelt === 0, "{$doppelt} Adressen mit mehreren");

echo "\n== 2. die Grenzen wohnen in den Grenzknoten (D-707) ==\n";

// ⚠️ *Nicht über den Namen (TASK-049, [D-613](../../docs/NewConcept/90-decision-log.md)): die Typen über
// ihre Klasse, die Grenzknoten als Ziel der Kanten `min`/`max` — so, wie der Kode sie findet (D-707).*
$typKnoten = [
    'Integer' => $editor->nodeImplementing(IntType::class)?->id ?? 0,
    'Decimal' => $editor->nodeImplementing(DecimalType::class)?->id ?? 0,
];
$grenze = static function (int $typId, string $key) use ($relations): int {
    foreach ($typId === 0 ? [] : $relations->fieldRelationsOf([$typId]) as $kante) {
        if ($kante->isSetting() && $kante->name === $key) {
            return $kante->toNodeId;
        }
    }

    return 0;
};
$id = static fn (string $name): int => match ($name) {
    'Integer'     => $typKnoten['Integer'],
    'Decimal'     => $typKnoten['Decimal'],
    'integer_min' => $grenze($typKnoten['Integer'], SettingKey::Min->value),
    'integer_max' => $grenze($typKnoten['Integer'], SettingKey::Max->value),
    'decimal_min' => $grenze($typKnoten['Decimal'], SettingKey::Min->value),
    'decimal_max' => $grenze($typKnoten['Decimal'], SettingKey::Max->value),
    default       => 0,
};
$eigenerWert = static function (int $nodeId) use ($wpdb, $saetze, $werte): ?string {
    $wert = $wpdb->get_row($wpdb->prepare(
        "SELECT v.value_int, v.value_decimal FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id
          WHERE s.node_id = %d AND s.record_type = 'default' AND s.relation_id = 0 AND v.relation_id = 0 LIMIT 1",
        $nodeId
    ), ARRAY_A);

    return $wert === null ? null : (string) ($wert['value_int'] ?? $wert['value_decimal']);
};

foreach ([
    ['integer_min', (string) PHP_INT_MIN],
    ['integer_max', (string) PHP_INT_MAX],
    ['decimal_min', '-99999999999999999999.9999999999'],
    ['decimal_max', '99999999999999999999.9999999999'],
] as [$name, $erwartet]) {
    $wert = $id($name) === 0 ? null : $eigenerWert($id($name));
    check("`{$name}` trägt seine Grenze als eigenen Wert im default-Satz", $wert === $erwartet, (string) $wert);
}

foreach (['Integer', 'Decimal'] as $typ) {
    $anKanten = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$werte} v JOIN {$saetze} s ON s.id = v.node_record_id
           JOIN " . Schema::table('relations_named') . " r ON r.id = v.relation_id
          WHERE s.node_id = %d AND r.name IN ('min', 'max')",
        $id($typ)
    ));
    check("an den Kanten min/max von `{$typ}` steht nichts mehr", $anKanten === 0, "{$anKanten} Zeilen");
    check("und `{$typ}` selbst trägt keinen eigenen Wert mehr", $eigenerWert($id($typ)) === null, (string) $eigenerWert($id($typ)));
}

$integer = $editor->nodeImplementing(IntType::class);
$kette   = $integer === null ? [] : $werteDesModells()->forNode($integer);
check('die Kette an `Integer` liefert min aus `integer_min`', ($kette['min'] ?? null)?->value->describe() === (string) PHP_INT_MIN && ($kette['min'] ?? null)?->fromOwnerId === $id('integer_min'), json_encode(['wert' => ($kette['min'] ?? null)?->value->describe(), 'von' => ($kette['min'] ?? null)?->fromOwnerId]));
check('und max aus `integer_max`', ($kette['max'] ?? null)?->value->describe() === (string) PHP_INT_MAX);
check('und beides als geerbt, nicht als hier gesetzt', ! ($kette['min'] ?? null)?->setHere && ! ($kette['max'] ?? null)?->setHere);

echo "\n== 3. die Wiese: der Rand schreibt seit Fassung 42 richtig ==\n";

$modell = $editor->createNode('__rk Modell', $framework->rootOf(Branch::Model)->id);
$zahl   = $editor->createNode('__rk Zahl', $integer->id);
// ⚠️ *Ein direktes Kind von `Integer` ist ein Geschwister von `integer_min` und erbt die Kante `min` nicht
// ([D-686](../../docs/NewConcept/90-decision-log.md)) — der Enkel erbt sie wieder.*
$enkel  = $editor->createNode('__rk Enkel', $zahl->id);

check('ein frischer Knoten hat keinen Satz', $data->recordsOf($modell->id) === []);

// ⚠️ *Bis Schritt 2 des Bauplans (2026-09-11) stand hier `read_only` — die Kante ist mit Fassung 48
// gewandert ([D-714](../../docs/NewConcept/90-decision-log.md)); `validator` ist eine
// Einstellungskante an der Wurzel, die geblieben ist und einen Namen als Wert nimmt.*
$leseKante = 0;
foreach ($relations->fieldRelationsOf([$framework->root()->id]) as $kante) {
    if ($kante->isSetting() && $kante->name === SettingKey::Validator->value) {
        $leseKante = $kante->id;
    }
}
check('die Einstellungskante `validator` ist da', $leseKante !== 0);
check('die Einstellungskante `read_only` ist gewandert (D-714)', ! in_array('read_only', array_map(static fn ($k) => $k->name, array_filter($relations->fieldRelationsOf([$framework->root()->id]), static fn ($k) => $k->isSetting())), true));
$data->putSettingAt($modell->id, $leseKante, 0, TypedValue::ofText('range'));
$arten = array_map(static fn ($s): string => $s->recordType->value, $data->recordsOf($modell->id));
check('das erste Schreiben einer Einstellung legt einen settings-Satz an, keinen default', $arten === ['settings'], implode(',', $arten));
check('und der Wert steht darin', ($data->settingValuesOf($modell->id, [$leseKante])[$leseKante] ?? null)?->text === 'range');

$feld = $editor->addField($modell->id, $integer->id, '__rk Menge', RelationKind::Composition);
$minKante = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relations_named') . " WHERE from_node_id = %d AND name = 'min' AND kind = 'setting' LIMIT 1",
    $integer->id
));
$data->putSettingAtUseSite($feld->id, $minKante, TypedValue::ofInt(0));
$stelle = $rows->ofRelation($feld->id);
check('der Satz einer Verwendungsstelle entsteht als settings', $stelle?->recordType === RecordType::Settings, (string) $stelle?->recordType->value);

$anDerStelle = $werteDesModells()->forUseSite($feld);
check('und an der Stelle gilt das engere min', ($anDerStelle['min'] ?? null)?->value->describe() === '0' && ($anDerStelle['min'] ?? null)?->setHere, json_encode(['wert' => ($anDerStelle['min'] ?? null)?->value->describe()]));
check('während max weiter aus `integer_max` kommt', ($anDerStelle['max'] ?? null)?->value->describe() === (string) PHP_INT_MAX && ! ($anDerStelle['max'] ?? null)?->setHere);

$data->putSettingAt($enkel->id, $minKante, 0, TypedValue::ofInt(10));
$amKind = $werteDesModells()->forNode($nodes->find($enkel->id));
check('ein Enkel von `Integer`, der min selbst setzt: näher schlägt ferner', ($amKind['min'] ?? null)?->value->describe() === '10' && ($amKind['min'] ?? null)?->setHere);
check('und sein max bleibt das aus `integer_max`', ($amKind['max'] ?? null)?->value->describe() === (string) PHP_INT_MAX);

echo "\n{$passed} ok, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
