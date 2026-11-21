<?php declare(strict_types=1);
/**
 * A confidential field is stored sealed and read plain, and nothing but the record repository touches stored text (D-1514).
 *
 *     php scripts/dev/field-cipher-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   S["Quelltext: value_text nur an erlaubten Stellen"] --> W["apply: Feld «Vertraulich», Wert schreiben"]
 *   W --> D[("live, Schatten, Chronik: enc1:")]
 *   D --> R["lesen, suchen, rückgängig: Klartext"]
 *   W --> J["Altbestand: FieldSealJob versiegelt nach"]
 * ```
 *
 * The first part reads the source and needs no WordPress; the second runs inside the clamp (lib/no-write.php) and
 * defines a data key for itself when wp-config.php has none.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */

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

echo "Source: stored text only where it may be touched\n";

/*
 * Every file that names the column `value_text`. The record repository is the one place that reads and writes it for
 * records; the others only name the column (types, schema), copy rows as they are (seed, backup) or look for «media:».
 * A new file here is a reader or writer past the seal and fails until it goes through the repository.
 */
const ALLOWED = [
    'src/WordPress/Persistence/WpdbRecordRepository.php' => 'the one place: seals on write, opens on read and search',
    'src/WordPress/Persistence/Schema.php'               => 'the table definitions',
    'src/WordPress/Persistence/SeedImage.php'            => 'the seed of a fresh install, which has no confidential field',
    'src/WordPress/Persistence/Backup.php'               => 'copies rows as they are (sealed stays sealed); looks for «media:»',
    'src/WordPress/Persistence/MetricSums.php'           => 'a key figure\'s filter value; a confidential field never matches it',
    'src/Core/Service/BackupRewrite.php'                 => 'names the column in its docblock',
    'src/WordPress/Privacy/FieldSealJob.php'             => 'seals what a field held before it became confidential',
];

$base   = dirname(__DIR__, 2);
$roots  = [$base];
$finds  = [];

foreach (glob(dirname($base) . '/wp_tax_*', GLOB_ONLYDIR) ?: [] as $module) {
    $roots[] = $module;
}

foreach ($roots as $root) {
    if (! is_dir($root . '/src')) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || preg_match('/\bvalue_text\b/', (string) file_get_contents($file->getPathname())) !== 1) {
            continue;
        }

        $relative = ($root === $base ? '' : basename($root) . '/') . substr($file->getPathname(), strlen($root) + 1);

        if (! isset(ALLOWED[$relative]) && ! str_starts_with($relative, 'src/Core/Model/Type/')) {
            $finds[] = $relative;
        }
    }
}

check('no file outside the allowed ones touches value_text', $finds === [], implode(', ', $finds));

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
    echo "  SKIP the database part: no WordPress found (pass its folder as the first argument)\n";
    echo "\n$ok ok, $bad failed\n";
    exit($bad === 0 ? 0 : 1);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Privacy\FieldSeal;
use Taxmod\WordPress\Privacy\FieldSealJob;

global $wpdb;

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    echo "\n$ok ok, $bad failed\n";
    exit($bad === 0 ? 0 : 1);
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$apply = wp_get_ability('taxmod/apply');
$tree  = wp_get_ability('taxmod/read-tree');
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$why   = static fn (mixed $answer): string => is_wp_error($answer) ? $answer->get_error_message() : 'accepted';
$raw   = static fn (int $record, int $relation): ?string => $wpdb->get_var($wpdb->prepare(
    'SELECT value_text FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d',
    $record,
    $relation
));

echo "Model\n";

$built = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__fc Kontakt', 'parent' => $tree->execute(['depth' => 1])['root']],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@k', 'target' => $text?->id ?? 0, 'name' => '__fc Name'],
    ['op' => 'add_field', 'ref' => 'ort', 'node' => '@k', 'target' => $text?->id ?? 0, 'name' => '__fc Ort'],
    ['op' => 'add_field', 'ref' => 'nr', 'node' => '@k', 'target' => $text?->id ?? 0, 'name' => '__fc Kundennummer'],
    ['op' => 'create_record', 'ref' => 'alt', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@alt', 'values' => ['@ort' => 'Altstadt vor dem Umstellen']],
]]);

check('apply builds a contact node with three text fields', is_array($built), $why($built));

if (! is_array($built)) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

$ref = $built['refs'];

if (FieldSeal::cipher() === null) {
    $marked = $apply->execute(['changes' => [['op' => 'set_addons', 'node' => $text->id, 'field' => $ref['name'], 'addons' => [['name' => 'confidential']]]]]);
    $answer = $apply->execute(['changes' => [['op' => 'create_record', 'ref' => 'x', 'node' => $ref['k'], 'values' => [(string) $ref['name'] => 'ohne Schlüssel']]]]);
    check('without a data key a confidential field is refused, never stored plain', ! is_wp_error($marked) && is_wp_error($answer) && str_contains($why($answer), FieldSeal::KEY_CONSTANT), $why($answer));
    check('without a key the notice offers a key line', str_contains(\Taxmod\WordPress\Admin\FieldSealNotice::markup(), FieldSeal::KEY_CONSTANT));
    define(FieldSeal::KEY_CONSTANT, FieldCipher::newKeyText());
    FieldSeal::forgetKey();
} else {
    echo "  SKIP the part without a key: wp-config.php defines one\n";
}

$marked = $apply->execute(['changes' => [
    ['op' => 'set_addons', 'node' => $text->id, 'field' => $ref['name'], 'addons' => [['name' => 'confidential']]],
    ['op' => 'set_addons', 'node' => $text->id, 'field' => $ref['ort'], 'addons' => [['name' => 'confidential']]],
]]);
check('«Vertraulich» is chosen at two edges', ! is_wp_error($marked) && isset(FieldSeal::confidentialRelations()[$ref['name']], FieldSeal::confidentialRelations()[$ref['ort']]), $why($marked));
check('the number field stays plain', ! FieldSeal::isConfidential($ref['nr']));

echo "Writing\n";

$made = $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'p', 'node' => $ref['k'], 'values' => [(string) $ref['name'] => 'Erika Mustermann', (string) $ref['ort'] => 'Musterstadt', (string) $ref['nr'] => 'K-4711']],
]]);
$p    = is_array($made) ? (int) $made['refs']['p'] : 0;
check('a contact with three values is created', $p > 0, $why($made));

$name = (string) $raw($p, $ref['name']);
check('the name is stored sealed', FieldCipher::isSealed($name) && ! str_contains($name, 'Mustermann'), $name);
check('the place is stored sealed', FieldCipher::isSealed((string) $raw($p, $ref['ort'])));
check('the number is stored plain', $raw($p, $ref['nr']) === 'K-4711');

$changed = $apply->execute(['changes' => [['op' => 'set_values', 'record' => $p, 'values' => [(string) $ref['name'] => 'Erika Musterfrau']]]]);
$act     = (int) ($changed['act'] ?? 0);
$shadow  = $wpdb->get_col($wpdb->prepare('SELECT value_text FROM ' . Schema::table('relation_records_history') . ' WHERE node_record_id = %d AND relation_id = %d', $p, $ref['name']));
check('the shadow row of the old name is sealed', $shadow !== [] && array_filter($shadow, static fn ($t): bool => ! FieldCipher::isSealed((string) $t)) === [], wp_json_encode($shadow));

$journal = implode("\n", $wpdb->get_col($wpdb->prepare(
    'SELECT CONCAT(COALESCE(before_state, \'\'), \' | \', COALESCE(after_state, \'\')) FROM ' . Schema::table('changelog') . ' WHERE owner_id = %d AND owner_kind = %s',
    $p,
    'record_value'
)));
check('the chronicle holds no plain name or place', ! str_contains($journal, 'Muster') && str_contains($journal, 'value=' . FieldCipher::PREFIX), $journal);
check('the chronicle holds the plain number', str_contains($journal, 'K-4711'));

echo "Reading\n";

$records = (new ModelDoor())->records($ref['k']);
check('the module door reads the name plain', ($records[$p][$ref['name']] ?? []) === ['Erika Musterfrau'], wp_json_encode($records[$p] ?? null));
check('the module door reads the place plain', ($records[$p][$ref['ort']] ?? []) === ['Musterstadt']);

$node   = wp_get_ability('taxmod/read-node')->execute(['node' => $ref['k']]);
check('read-node answers plain text to the administrator (E1)', str_contains((string) wp_json_encode($node), 'Erika Musterfrau'));

$repo = new WpdbRecordRepository();
check('a «contains» search on the field finds the record', in_array($p, $repo->recordsWhere([$ref['k']], [['field' => $ref['name'], 'op' => 'contains', 'values' => ['muster*frau']]], 10, 0)['ids'], true));
check('a «contains» search on any field finds it', in_array($p, $repo->recordsWhere([$ref['k']], [['field' => 0, 'op' => 'contains', 'values' => ['Musterstadt']]], 10, 0)['ids'], true));
check('an «eq» search finds it', in_array($p, $repo->recordsWhere([$ref['k']], [['field' => $ref['ort'], 'op' => 'eq', 'values' => ['Musterstadt']]], 10, 0)['ids'], true));
check('a search for something else does not', $repo->recordsWhere([$ref['k']], [['field' => $ref['name'], 'op' => 'contains', 'values' => ['Gabler']]], 10, 0)['ids'] === []);
check('the record search finds it', in_array($p, $repo->recordsMatching([$ref['k']], [['text' => 'Musterfrau', 'nodes' => []]], 10), true));
check('the value lookup finds it', $repo->findByRelationValue($ref['ort'], \Taxmod\Core\Model\TypedValue::ofText('Musterstadt')) === [$p]);

echo "Undo\n";

$undo    = $apply->execute(['changes' => [['op' => 'undo_act', 'act' => $act]]]);
$records = (new ModelDoor())->records($ref['k']);
check('undo brings the old name back', ! is_wp_error($undo) && ($records[$p][$ref['name']] ?? []) === ['Erika Mustermann'], $why($undo));
check('after the undo the name is still stored sealed', FieldCipher::isSealed((string) $raw($p, $ref['name'])));
check('the lock is refused as a value', is_wp_error($apply->execute(['changes' => [['op' => 'set_values', 'record' => $p, 'values' => [(string) $ref['ort'] => FieldSeal::LOCKED]]]])));

echo "Values from before the field became confidential\n";

check('the value written before is still plain', $raw((int) $ref['alt'], $ref['ort']) === 'Altstadt vor dem Umstellen');
check('and reads as it is', ((new ModelDoor())->records($ref['k'])[$ref['alt']][$ref['ort']] ?? []) === ['Altstadt vor dem Umstellen']);
check('the job counts what is left plain', FieldSealJob::plainLeft()['live'] >= 1 && FieldSealJob::plainLeft()['journal'] >= 1, wp_json_encode(FieldSealJob::plainLeft()));

for ($i = 0; $i < 50 && FieldSealJob::runBatch() > 0; $i++) {
}

check('after the job nothing is plain', FieldSealJob::plainLeft() === ['live' => 0, 'history' => 0, 'journal' => 0], wp_json_encode(FieldSealJob::plainLeft()));
check('the old value is now sealed and reads plain', FieldCipher::isSealed((string) $raw((int) $ref['alt'], $ref['ort'])) && ((new ModelDoor())->records($ref['k'])[$ref['alt']][$ref['ort']] ?? []) === ['Altstadt vor dem Umstellen']);

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
