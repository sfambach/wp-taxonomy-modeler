<?php declare(strict_types=1);

/**
 * A source says what it is: «Webseite», «Datei» or both, as links named after their fields; the setting is written through apply.
 *
 *     php scripts/dev/quellen-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-999](../../docs/NewConcept/90-decision-log.md), his word:** *«Hätte lieber quellen und dann art website/datei (beides ist auch
 * möglich»*, to the proposal: *«den finde ich ziemlich gut»*. *A sandbox `__qu`: (1) `set_setting` writes `name_as_link_text` at a use
 * site; (2) the reader sees the field names as link texts, both at once; (3) an unknown setting is refused and nothing is written.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply  = wp_get_ability('taxmod/apply');
$typen  = (new WpdbNodeRepository())->byImplementations([TextType::class, MediaType::class]);
$text   = $typen[TextType::class] ?? null;
$medium = $typen[MediaType::class] ?? null;
$model  = (int) get_option('taxmod_branch_model_id', 0);

if ($apply === null || $text === null || $medium === null || $model === 0) {
    echo "  FAIL scaffold missing (apply, Text, Media, Model)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'q', 'name' => '__qu Quelle', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@q', 'target' => $text->id, 'name' => '__qu Bezeichnung', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'web', 'node' => '@q', 'target' => $medium->id, 'name' => '__qu Webseite', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'datei', 'node' => '@q', 'target' => $medium->id, 'name' => '__qu Datei', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@q'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@name' => 'Gerberdateien', '@web' => 'https://example.com/gerber.zip', '@datei' => 'https://example.org/kopie.zip']],
    ['op' => 'set_setting', 'node' => $medium->id, 'field' => '@web', 'attribute' => MediaType::NAME_AS_LINK_TEXT, 'value' => '1'],
    ['op' => 'set_setting', 'node' => $medium->id, 'field' => '@datei', 'attribute' => MediaType::NAME_AS_LINK_TEXT, 'value' => '1'],
]]);

$pruefe(is_array($geruest), 'set_setting is taken at a use site' . (is_wp_error($geruest) ? ' — ' . $geruest->get_error_message() : ''));

if (! is_array($geruest)) {
    exit(1);
}

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, __FILE__);
$leser  = $plugin->screen()->recordForReaders((int) $geruest['refs']['r'], 0, '');

$pruefe((bool) preg_match('~href="https://example\.com/gerber\.zip"[^>]*>__qu Webseite</a>~', $leser), 'the link to the website is named after its field');
$pruefe((bool) preg_match('~href="https://example\.org/kopie\.zip"[^>]*>__qu Datei</a>~', $leser), 'and the file beside it, both at once');

$falsch = $apply->execute(['changes' => [
    ['op' => 'set_setting', 'node' => $medium->id, 'field' => $geruest['refs']['web'], 'attribute' => 'gibt_es_nicht', 'value' => '1'],
]]);
$pruefe(is_wp_error($falsch), 'an unknown setting is refused');

exit($fehler === 0 ? 0 : 1);
