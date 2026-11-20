<?php declare(strict_types=1);

/**
 * A field's own help stands before the help of the node it points at (D-1524) — two fields on the same node mean different things.
 *
 * ⚠️ *His finding 20.11.: «ist der Standardtext vom Textfeld … das erklärt, warum Drucker und Standarddrucker keine Hilfe haben».*
 *
 *     php scripts/dev/field-help-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);
    $failed += $ok ? 0 : 1;
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$text   = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

$apply = static function (array $changes): array {
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        echo '  FAIL setup refused: ' . $antwort->get_error_message() . "\n";
        exit(1);
    }

    return $antwort['refs'];
};

$refs = $apply([
    ['op' => 'create_node', 'ref' => 'n', 'name' => '__fh Platz', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'a', 'node' => '@n', 'target' => $text->id, 'name' => '__fh Eins'],
    ['op' => 'add_field', 'ref' => 'b', 'node' => '@n', 'target' => $text->id, 'name' => '__fh Zwei'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@n'],
]);
$apply([['op' => 'set_label', 'node' => $refs['n'], 'field' => $refs['a'], 'role' => 'help', 'text' => '__fh Hilfe am Feld eins']]);

$_GET  = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $refs['n'], 'taxmod_preview_record' => (string) $refs['s']];
$seite = $plugin->screen()->render();
$_GET  = [];

$zeile = static function (string $name) use ($seite): string {
    $an = strpos($seite, '<span class="taxmod-form-label">' . $name . '</span>');

    return $an === false ? '' : (string) substr($seite, $an, (int) (strpos($seite, 'taxmod-form-row', $an) ?: $an + 2000) - $an);
};

$say(str_contains($zeile('__fh Eins'), '__fh Hilfe am Feld eins'), 'the field shows its own help');
$say(! str_contains($zeile('__fh Zwei'), '__fh Hilfe am Feld eins'), 'the other field on the same node does not');
$say(str_contains($zeile('__fh Zwei'), 'taxmod-hint-text'), 'the other field keeps the help of its target');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
