<?php declare(strict_types=1);

/**
 * One picture list, the first picture is the title picture: the old field merges in front, the list offers the star.
 *
 *     php scripts/dev/bildliste-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1205](../../docs/NewConcept/90-decision-log.md), his word:** *«verschieben der bilder muss dann mögich sein»*. *A sandbox
 * `__bl`: (1) merge_field with first puts the title picture at place 1, the pictures move back, a picture in both fields stands once;
 * (2) without first it is still refused; (3) the edit list of a media field carries the mark the star hangs on, and the script has
 * the star; (4) the record table shows the first picture before the count.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply = wp_get_ability('taxmod/apply');
$media = (new WpdbNodeRepository())->byImplementations([MediaType::class])[MediaType::class] ?? null;
$model = (int) get_option('taxmod_branch_model_id', 0);

if ($apply === null || $media === null || $model === 0) {
    echo "  FAIL scaffold missing (apply, Media, Model)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'n', 'name' => '__bl Ding', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 't', 'node' => '@n', 'target' => $media->id, 'name' => '__bl Titelbild', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'b', 'node' => '@n', 'target' => $media->id, 'name' => '__bl Bilder', 'multiplicity' => '0..*'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@n'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@t' => 'media:3', '@b' => ['values' => ['media:1', 'media:2', 'media:3']]]],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@n'],
    ['op' => 'set_values', 'record' => '@s', 'values' => ['@t' => 'media:7']],
]]);

if (! is_array($geruest)) {
    printf("  FAIL the sandbox is not built — %s\n", is_wp_error($geruest) ? $geruest->get_error_message() : '?');
    exit(1);
}

global $wpdb;

$ref   = $geruest['refs'];
$liste = static function (int $satz, int $kante) use ($wpdb): array {
    return array_map('strval', $wpdb->get_col($wpdb->prepare(
        "SELECT value_text FROM {$wpdb->prefix}taxmod_relation_records WHERE node_record_id = %d AND relation_id = %d ORDER BY position, id",
        $satz,
        $kante
    )));
};

$ohne = $apply->execute(['changes' => [['op' => 'merge_field', 'node' => $ref['n'], 'field' => $ref['t'], 'into' => $ref['b']]]]);
$pruefe(is_wp_error($ohne), 'without first a record holding both fields is still refused (D-941)');

$mit = $apply->execute(['changes' => [['op' => 'merge_field', 'node' => $ref['n'], 'field' => $ref['t'], 'into' => $ref['b'], 'first' => true]]]);
$pruefe(is_array($mit), 'merge_field with first goes through — ' . (is_wp_error($mit) ? $mit->get_error_message() : 'ok'));
$pruefe($liste((int) $ref['r'], (int) $ref['b']) === ['media:3', 'media:1', 'media:2'], 'the title picture is place 1, the others move back, the one in both stands once');
$pruefe($liste((int) $ref['s'], (int) $ref['b']) === ['media:7'], 'a record with only a title picture gets a list of one');
$pruefe($liste((int) $ref['r'], (int) $ref['t']) === [], 'the old field holds nothing any more');

$rendering = (string) file_get_contents(__DIR__ . '/../../src/Core/Service/Rendering.php');
$skript    = (string) file_get_contents(__DIR__ . '/../../assets/admin.js');
$pruefe(str_contains($rendering, "(\$medium ? ' data-taxmod-pictures=\"1\"' : '')"), 'the edit list of a media field carries the picture mark');
$pruefe(preg_match("/function titelSterne\(/", $skript) === 1 && str_contains($skript, "'top'"), 'the script hangs a star on every row of a picture list, it moves the row to place 1');
$pruefe(preg_match('/taxmod-media-count">\'\s*\.\s*\$titel\s*\./', $rendering) === 1, 'the record table shows the first picture before the count');

exit($fehler === 0 ? 0 : 1);
