<?php declare(strict_types=1);

/**
 * A module asks for the records of one node as a tree and gets the node chooser's view: dialog, search field, the same rows.
 *
 *     php scripts/dev/record-chooser-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1084](../../docs/NewConcept/90-decision-log.md), his word:** *«ja sätze im baum passen»* — storage locations stay records
 * and are shown and chosen in the same tree as in the modeller. *A sandbox `__rc`: a node «Ort» with a name field and a field
 * «Übergeordnet» pointing at its own records; a chain Haus › Keller › Regal › Kiste, an excluded Garage with a child, an orphan
 * whose parent is a record of another node, and a cycle of two.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply = wp_get_ability('taxmod/apply');
$model = (int) get_option('taxmod_branch_model_id', 0);
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class]->id ?? 0;

if ($apply === null || $model === 0 || $text === 0) {
    echo "  FAIL scaffold missing (apply, Model, Text)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$namen = ['haus' => 'Haus', 'keller' => 'Keller', 'regal' => 'Regal', 'kiste' => 'Kiste', 'garage' => 'Garage', 'werkbank' => 'Werkbank', 'waise' => 'Waise', 'kreis1' => 'Kreis Eins', 'kreis2' => 'Kreis Zwei'];
$aenderungen = [
    ['op' => 'create_node', 'ref' => 'ort', 'name' => '__rc Ort', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'anderer', 'name' => '__rc Anderer', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@ort', 'target' => $text, 'name' => '__rc Name'],
    ['op' => 'add_field', 'ref' => 'ueber', 'node' => '@ort', 'target' => '@ort', 'name' => '__rc Übergeordnet', 'kind' => 'aggregation'],
    ['op' => 'create_record', 'ref' => 'fremd', 'node' => '@anderer'],
];

foreach ($namen as $ref => $wort) {
    $aenderungen[] = ['op' => 'create_record', 'ref' => $ref, 'node' => '@ort'];
    $aenderungen[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => ['@name' => '__rc ' . $wort]];
}

$geruest = $apply->execute(['changes' => $aenderungen]);

$pruefe(is_array($geruest), 'sandbox' . (is_wp_error($geruest) ? ' — ' . $geruest->get_error_message() : ''));

if (! is_array($geruest)) {
    exit(1);
}

$r       = $geruest['refs'];
$records = new WpdbRecordRepository();
$haenge  = static function (string $kind, string $vater) use ($records, $r): void {
    $records->putValue(new RelationRecord($r[$kind], $r['ueber'], '', TypedValue::ofRecordReference($r[$vater])));
};

$haenge('keller', 'haus');
$haenge('regal', 'keller');
$haenge('kiste', 'regal');
$haenge('werkbank', 'garage');
$haenge('waise', 'fremd');
$haenge('kreis1', 'kreis2');
$haenge('kreis2', 'kreis1');

$frage = static fn (array $args): string => (string) apply_filters(Plugin::RECORD_CHOOSER_FILTER, '', $args + [
    'name'         => 'lager[ort]',
    'node'         => $r['ort'],
    'parent_field' => $r['ueber'],
    'label_field'  => $r['name'],
    'form'         => 'lager-form',
]);

$tiefe = static function (string $html, int $satz): ?int {
    // *Each row carries its depth; the radio of a record sits in its row.*
    foreach (explode('<div class="taxmod-tree-row"', $html) as $zeile) {
        if (preg_match('~value="' . $satz . '"~', $zeile) === 1 && preg_match('~^ data-depth="(\d+)"~', $zeile, $m) === 1) {
            return (int) $m[1];
        }
    }

    return null;
};

$html = $frage(['exclude' => [$r['garage']], 'preselected' => $r['regal'], 'unpickable' => [$r['haus']]]);

$pruefe(str_contains($html, 'taxmod-dialog'), 'a dialog, as in the node chooser');
$pruefe(str_contains($html, 'taxmod-tree-filter'), 'with the search field at the tree');
$pruefe(substr_count($html, 'class="taxmod-tree-row"') >= 7 && str_contains($html, 'taxmod-chooser-row'), 'the node chooser\'s own rows and cells');
$pruefe([$tiefe($html, $r['keller']), $tiefe($html, $r['regal']), $tiefe($html, $r['kiste'])] === [1, 2, 3], 'arranged by the «Übergeordnet» field: Keller 1, Regal 2, Kiste 3');
$pruefe(str_contains($html, '__rc Kiste') && str_contains($html, '__rc Haus'), 'the word comes from the label field');
$pruefe(! str_contains($html, '__rc Garage') && ! str_contains($html, '__rc Werkbank'), 'an excluded record is left out with its subtree');
$pruefe($tiefe($html, $r['waise']) === 0, 'a record whose parent is not among the records stands at the top level');
$pruefe($tiefe($html, $r['kreis1']) !== null && $tiefe($html, $r['kreis2']) !== null, 'a cycle does not hang and both records can be chosen');
$pruefe(! str_contains($html, 'value="' . $r['haus'] . '"') && str_contains($html, '__rc Haus'), 'an unpickable record is shown, without a radio');
$pruefe((bool) preg_match('~type="radio"[^>]*name="lager\[ort\]"~', $html) || (bool) preg_match('~name="lager\[ort\]"[^>]*type="radio"~', $html), 'the choice goes to the module\'s field name');
$pruefe(str_contains($html, 'form="lager-form"'), 'and to the module\'s form');
$pruefe((bool) preg_match('~value="' . $r['regal'] . '"[^>]*checked|checked[^>]*value="' . $r['regal'] . '"~', $html), 'the preselected record is chosen');

$ohne = (string) apply_filters(Plugin::RECORD_CHOOSER_FILTER, '', ['name' => 'lager[ort]', 'node' => $r['ort'], 'parent_field' => $r['ueber']]);
$pruefe(str_contains($ohne, '__rc Kiste') && $tiefe($ohne, $r['kiste']) === 3, 'without label_field the word the modeller\'s record chooser shows');

$ast = $frage(['root' => $r['keller']]);
$pruefe($tiefe($ast, $r['keller']) === 0 && $tiefe($ast, $r['kiste']) === 2 && ! str_contains($ast, '__rc Haus') && ! str_contains($ast, '__rc Waise'), 'root: only that record and what lies below it');

// ⚠️ *`CD-7`: the number of queries does not grow with the depth — four more levels under the Kiste, the same count.*
global $wpdb;
$vorher = $wpdb->num_queries;
$frage([]);
$flach = $wpdb->num_queries - $vorher;

$tiefer = $apply->execute(['changes' => array_merge(...array_map(static fn (int $i): array => [
    ['op' => 'create_record', 'ref' => 'k' . $i, 'node' => $r['ort']],
    ['op' => 'set_values', 'record' => '@k' . $i, 'values' => [(string) $r['name'] => '__rc Ebene ' . $i]],
], range(1, 4)))]);

$vater = $r['kiste'];

foreach (range(1, 4) as $i) {
    $records->putValue(new RelationRecord($tiefer['refs']['k' . $i], $r['ueber'], '', TypedValue::ofRecordReference($vater)));
    $vater = $tiefer['refs']['k' . $i];
}

$vorher = $wpdb->num_queries;
$tief   = $frage([]);
$dann   = $wpdb->num_queries - $vorher;
$pruefe($tiefe($tief, $vater) === 7 && $dann === $flach, "no query per level — {$flach} queries at depth 3, {$dann} at depth 7");

wp_set_current_user(0);
$pruefe($frage([]) === '', 'nothing for someone who may not shape the model');

exit($fehler === 0 ? 0 : 1);
