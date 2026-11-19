<?php declare(strict_types=1);
/**
 * Every change made through the tools can be taken back by its number, and the undo itself can be taken back (D-1410, D-172).
 *
 *     php scripts/dev/undo-check.php [path/to/wordpress]
 *
 * ⚠️ *Measured at the result: the model's tables are read before an op, the op runs, `undo_act` takes it back, and the tables must
 * read the same again (versions and act numbers aside, they count forward). Then the undo is undone and the op's result must
 * stand again.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Plugin;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

/** The model as it reads — every live row without the columns that count forward, and the label texts of live owners. */
function fingerprint(): string
{
    global $wpdb;

    $teile = [];

    foreach (Schema::LIVE_TABLES as $tabelle) {
        $zeilen = $wpdb->get_results('SELECT * FROM ' . Schema::table($tabelle) . ' ORDER BY id', ARRAY_A) ?: [];

        foreach ($zeilen as $zeile) {
            unset($zeile['version'], $zeile['created_by_group_id']);
            $teile[] = $tabelle . ':' . wp_json_encode($zeile);
        }
    }

    foreach (['nodes', 'relations'] as $besitzer) {
        $texte = $wpdb->get_results(
            'SELECT o.id AS owner, t.locale, t.number, t.text_name, t.text_form, t.text_table, t.text_select, t.text_help, t.text_symbol
             FROM ' . Schema::table($besitzer) . ' o JOIN ' . Schema::table('label_texts') . ' t ON t.label_id = o.label_id
             ORDER BY o.id, t.locale, t.number',
            ARRAY_A
        ) ?: [];

        foreach ($texte as $text) {
            $teile[] = $besitzer . '-label:' . wp_json_encode($text);
        }
    }

    return md5(implode("\n", $teile));
}

/** @return array{0: int, 1: string} the act and an error, if any */
function run(string $ability, array $changes): array
{
    $antwort = wp_get_ability($ability)->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        return [0, $antwort->get_error_message()];
    }

    return [(int) ($antwort['act'] ?? 0), ''];
}

/** An op, its undo, and the undo's undo — each compared against the model as it read. */
function roundTrip(string $what, array $changes): void
{
    $vorher = fingerprint();
    [$akt, $fehler] = run('taxmod/apply', $changes);

    check("{$what}: the answer names its act", $akt > 0, $fehler);

    if ($akt === 0) {
        return;
    }

    $nachher = fingerprint();
    check("{$what}: it changed something", $nachher !== $vorher);

    [$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $akt]]);
    check("{$what}: undo runs", $undo > 0, $fehler);
    check("{$what}: undo brings the model back", fingerprint() === $vorher);

    [$redo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $undo]]);
    check("{$what}: undoing the undo runs", $redo > 0, $fehler);
    check("{$what}: and the change stands again", fingerprint() === $nachher);

    // *And back to where it started, so the next round finds the model as it was built.*
    [$zurueck, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $redo]]);
    check("{$what}: a third undo takes it back again", $zurueck > 0 && fingerprint() === $vorher, $fehler);
}

// *A group property is a plain node setting any module may declare (D-1259) — the one setting every installation accepts.*
add_filter(Plugin::NODE_PROPERTIES_FILTER, static fn (array $declared): array => $declared + ['un_flag' => 'Undo test flag']);

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);
$konst = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Constants);
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

echo "\n== 1. The model ==\n";

roundTrip('create_node with a field', [
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__un Gruppe', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__un Farbe'],
]);

$gebaut = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__un Bauteile', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__un Kabel', 'parent' => '@a'],
    ['op' => 'create_node', 'ref' => 'c', 'name' => '__un Stecker', 'parent' => '@a'],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__un Farbe'],
    ['op' => 'add_field', 'ref' => 'g', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__un Farbe alt'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@b', 'values' => ['@f' => 'rot']],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@b', 'values' => ['@g' => 'blau']],
]]);

check('the test nodes are built', is_array($gebaut), is_wp_error($gebaut) ? $gebaut->get_error_message() : '');

if (! is_array($gebaut)) {
    exit(1);
}

$r = $gebaut['refs'];

roundTrip('rename_node', [['op' => 'rename_node', 'node' => $r['b'], 'name' => '__un Leitungen']]);
roundTrip('move_node', [['op' => 'move_node', 'node' => $r['c'], 'parent' => $r['b']]]);
roundTrip('trash_node', [['op' => 'trash_node', 'node' => $r['c']]]);
roundTrip('rename_field', [['op' => 'rename_field', 'node' => $r['a'], 'field' => $r['f'], 'name' => '__un Farbton']]);
roundTrip('set_multiplicity', [['op' => 'set_multiplicity', 'node' => $r['a'], 'field' => $r['f'], 'multiplicity' => '0..*']]);
roundTrip('hide_field', [['op' => 'hide_field', 'node' => $r['a'], 'field' => $r['f'], 'hidden' => true]]);
roundTrip('set_label', [['op' => 'set_label', 'node' => $r['b'], 'role' => 'help', 'text' => '__un Hilfe']]);
roundTrip('set_setting', [['op' => 'set_setting', 'node' => $r['a'], 'attribute' => 'un_flag', 'value' => '1']]);
roundTrip('remove_field', [['op' => 'remove_field', 'node' => $r['a'], 'field' => $r['g']]]);
roundTrip('merge_field', [['op' => 'merge_field', 'node' => $r['a'], 'field' => $r['g'], 'into' => $r['f']]]);

// D-1458: a doubled constant — the record that chose the double chooses the other, the double goes to the trash.
$liste = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'l', 'name' => '__un Bauform', 'parent' => $konst->id],
    ['op' => 'create_node', 'ref' => 'x', 'name' => '__un THT', 'parent' => '@l'],
    ['op' => 'create_node', 'ref' => 'y', 'name' => '__un DIP', 'parent' => '@l'],
    ['op' => 'add_field', 'ref' => 'h', 'node' => $r['a'], 'target' => '@l', 'name' => '__un Bauform'],
    ['op' => 'create_record', 'ref' => 't', 'node' => $r['b'], 'values' => ['@h' => '@y']],
]]);
check('the constants are built', is_array($liste), is_wp_error($liste) ? $liste->get_error_message() : '');

if (is_array($liste)) {
    $l = $liste['refs'];
    $gewaehlt = static fn (): int => (int) $wpdb->get_var($wpdb->prepare(
        'SELECT value_ref FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d AND value_ref_kind = \'node\'',
        $l['t'],
        $l['h']
    ));
    check('merge_constant: the record chose the double', $gewaehlt() === (int) $l['y'], (string) $gewaehlt());
    [$akt, $fehler] = run('taxmod/apply', [['op' => 'merge_constant', 'from' => $l['y'], 'into' => $l['x']]]);
    check('merge_constant: the record now chooses the other', $akt > 0 && $gewaehlt() === (int) $l['x'], $fehler . ' ' . $gewaehlt());
    [$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $akt]]);
    check('merge_constant: undo brings the choice back', $undo > 0 && $gewaehlt() === (int) $l['y'], $fehler);
    roundTrip('merge_constant', [['op' => 'merge_constant', 'from' => $l['y'], 'into' => $l['x']]]);
    [, $fehler] = run('taxmod/apply', [['op' => 'merge_constant', 'from' => $l['l'], 'into' => $l['x']]]);
    check('merge_constant: a list with children is refused', $fehler !== '', 'accepted');

    // D-1474: «Löschen» of a choice-list value — only what nothing chooses; the chosen one is refused, naming how often.
    [, $fehler] = run('taxmod/apply', [['op' => 'trash_unused_constant', 'node' => $l['y']]]);
    check('trash_unused_constant: a value a record chooses is refused', str_contains($fehler, '1 value(s)'), $fehler === '' ? 'accepted' : $fehler);
    [, $fehler] = run('taxmod/apply', [['op' => 'trash_unused_constant', 'node' => $l['l']]]);
    check('trash_unused_constant: a list with values is refused', $fehler !== '', 'accepted');
    $frei = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'create_node', 'ref' => 'z', 'name' => '__un Silber', 'parent' => $l['l']]]]);
    check('trash_unused_constant: the unused value is built', is_array($frei));
    check('constantUses: one query counts the chosen value only', (new \Taxmod\WordPress\ModelDoor())->constantUses([(int) $l['y'], (int) ($frei['refs']['z'] ?? 0)]) === [(int) $l['y'] => 1]);
    roundTrip('trash_unused_constant', [['op' => 'trash_unused_constant', 'node' => $frei['refs']['z'] ?? 0]]);
}

echo "\n== 2. Records ==\n";

roundTrip('create_record', [['op' => 'create_record', 'node' => $r['b'], 'values' => [(string) $r['f'] => 'grün']]]);
roundTrip('set_values', [['op' => 'set_values', 'record' => $r['r'], 'values' => [(string) $r['f'] => 'gelb']]]);
roundTrip('remove_record', [['op' => 'remove_record', 'record' => $r['r']]]);
roundTrip('duplicate_record', [['op' => 'duplicate_record', 'record' => $r['r']]]);
roundTrip('move_record', [['op' => 'move_record', 'record' => $r['r'], 'node' => $r['a']]]);

echo "\n== 3. The guard ==\n";

[$erster]  = run('taxmod/apply', [['op' => 'set_values', 'record' => $r['r'], 'values' => [(string) $r['f'] => 'weiss']]]);
[$zweiter] = run('taxmod/apply', [['op' => 'set_values', 'record' => $r['r'], 'values' => [(string) $r['f'] => 'schwarz']]]);
$vorZweitem = fingerprint();

[$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $erster]]);
check('an act written over since is refused', $undo === 0);
check('and the refusal names the later act', str_contains($fehler, (string) $zweiter), $fehler);
check('and nothing was written', fingerprint() === $vorZweitem);

[$undo] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $zweiter]]);
check('the later act can be taken back', $undo > 0);
[$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $erster]]);
check('and then the earlier one', $undo > 0, $fehler);

[$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => $erster]]);
check('an act already taken back is refused the second time', $undo === 0, $fehler);

[$undo, $fehler] = run('taxmod/apply', [['op' => 'undo_act', 'act' => 999999999]]);
check('an unknown number is refused', $undo === 0 && $fehler !== '', $fehler);

echo "\n== 4. write-data ==\n";

[$daten] = run('taxmod/write-data', [['op' => 'set_values', 'record' => $r['s'], 'values' => [(string) $r['g'] => 'lila']]]);
check('write-data names its act', $daten > 0);
[$undo, $fehler] = run('taxmod/write-data', [['op' => 'undo_act', 'act' => $daten]]);
check('write-data takes back a records-only act', $undo > 0, $fehler);

[$modell] = run('taxmod/apply', [['op' => 'rename_node', 'node' => $r['b'], 'name' => '__un Adern']]);
[$undo, $fehler] = run('taxmod/write-data', [['op' => 'undo_act', 'act' => $modell]]);
check('write-data refuses to take back a change of the model', $undo === 0 && $fehler !== '', $fehler);

echo "\n== 5. Every row of an act carries its number ==\n";

global $wpdb;
$offen = 0;

foreach ([...Schema::LIVE_TABLES, ...Schema::SHADOW_TABLES] as $tabelle) {
    $offen += (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table($tabelle) . ' WHERE created_by_group_id >= 9000000000000000000');
}

foreach (Schema::SHADOW_TABLES as $tabelle) {
    $offen += (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table($tabelle) . ' WHERE archived_by_group_id >= 9000000000000000000');
}

check('no row is left with a placeholder', $offen === 0, (string) $offen);

echo $bad === 0 ? "\nAll checks passed.\n" : "\n{$bad} failed.\n";
exit($bad === 0 ? 0 : 1);
