<?php declare(strict_types=1);

/** Row 7: the converter runs both ways, on the real database. */

define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\{Purpose, ShippedRenderers};
use Taxmod\Core\Service\{Labels, ModelEditor, Rendering, Settings};
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, TableIdentityAllocator, WpdbChangelog, WpdbLabelRepository, WpdbNodeRepository, WpdbRelationRepository, WpdbSettingRepository};
use Taxmod\WordPress\SystemClock;

$nodes = new WpdbNodeRepository();
$edges = new WpdbRelationRepository();
$ids   = new TableIdentityAllocator();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $edges, $ids, $log);

$settings  = new Settings(new WpdbSettingRepository(), $nodes, $fw, $log);
$editor    = new ModelEditor($nodes, $edges, $ids, $fw, $log);
$rendering = new Rendering(
    $nodes,
    $fw,
    $settings,
    ShippedRenderers::registry(),
    new Labels(new WpdbLabelRepository(), $fw),
    ShippedConverters::registry()
);

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

// A scratch model, so nothing the owner is editing is touched (row 23).
$holder  = $editor->createNode('__cv Messwert', $fw->rootOf(Branch::Model)->id);

global $wpdb;

$p         = $wpdb->prefix . 'taxmod_';
$integerId = (int) $wpdb->get_var("SELECT id FROM {$p}nodes WHERE name = 'Integer' LIMIT 1");
$edge      = $editor->addField($holder->id, $integerId, 'zaehler');

echo "== der Schluessel war ein totes Steuerelement, jetzt nicht mehr ==\n";

// ⚠️ **Am Attribut gemessen, nicht am Knoten.** Ein Knoten unter `Model` hat keinen einfachen Typ,
// also auch keine passenden Konverter — die Kante bekommt ihren Typ vom Ziel (`Integer`), und dort
// ist die Frage «welche Abbildung darf dieser Wert bekommen» ueberhaupt gestellt.
$drawn = $rendering->settingsFor(
    $edge,
    $settings->resolve($settings->chainForUseSite($edge)),
    Purpose::Edit
);

// ⚠️ *`settingsFor()` gibt eine **Liste** zurueck und keine Zuordnung nach Schluessel — die Zeile
// traegt ihren Schluessel selbst.*
$row = null;

foreach ($drawn as $one) {
    if ($one->key === SettingKey::Converter->value) {
        $row = $one;
    }
}

if ($row === null) {
    $say(false, 'die converter-Zeile wurde ueberhaupt gezeichnet');
} else {
    $say(! str_contains($row->result->markup, 'disabled'), 'das Steuerelement ist lebendig');
    $say(str_contains($row->result->markup, 'roman'), 'und bietet «roman» an');
    $say(str_contains($row->result->markup, 'hexadecimal'), 'und «hexadecimal»');
}

echo "\n== ohne Konverter steht der Wert wie gespeichert ==\n";

$before = $rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($before->result->markup, '12'), 'die 12 steht als 12 da');

echo "\n== mit roman wird sie XII, ohne den Renderer zu wechseln ==\n";

$settings->put($settings->chainForUseSite($edge), SettingKey::Converter->value, TypedValue::ofText('roman'));

$after = $rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($after->result->markup, 'XII'), 'XII steht auf dem Schirm');
$say($after->rendererName === $before->rendererName, 'derselbe Renderer wie vorher — die Abbildung wechselte, nicht die Form');

echo "\n== und XII kommt als 12 zurueck ==\n";

$read = $rendering->valuesFrom([$edge], [$edge->id => 'XII']);

$say(($read[$edge->id]->int ?? null) === 12, 'die Eingaberichtung liest XII als 12');

$read = $rendering->valuesFrom([$edge], [$edge->id => 'xii']);

$say(($read[$edge->id]->int ?? null) === 12, 'auch klein geschrieben');

echo "\n== hexadecimal genauso ==\n";

$settings->put($settings->chainForUseSite($edge), SettingKey::Converter->value, TypedValue::ofText('hexadecimal'));

$hex = $rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(255)], Purpose::Display)[0];

$say(str_contains($hex->result->markup, 'FF'), '255 steht als FF da');

$read = $rendering->valuesFrom([$edge], [$edge->id => 'FF']);

$say(($read[$edge->id]->int ?? null) === 255, 'und FF kommt als 255 zurueck');

echo "\n== was nicht lesbar ist, wird verweigert und nicht als 0 gespeichert ==\n";

try {
    $rendering->valuesFrom([$edge], [$edge->id => 'zz']);
    $say(false, 'zz wurde abgelehnt');
} catch (\Taxmod\Core\Exception\NotAValueOfThatType) {
    $say(true, 'zz wurde abgelehnt');
}

echo "\n== ein Konvertername, den es nicht gibt, nimmt kein Formular mit runter ==\n";

$settings->put($settings->chainForUseSite($edge), SettingKey::Converter->value, TypedValue::ofText('gibt-es-nicht'));

$stale = $rendering->fieldsFor([$edge], [$edge->id => TypedValue::ofInt(12)], Purpose::Display)[0];

$say(str_contains($stale->result->markup, '12'), 'der Wert steht gespeichert da');

// aufraeumen
$in  = (string) $holder->id;
$all = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_id = {$in} OR to_id = {$in}"));
$own = $all === [] ? $in : $in . ',' . implode(',', $all);

$wpdb->query("DELETE FROM {$p}settings WHERE owner_id IN ({$own})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");

if ($all) {
    $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $all) . ')');
}

$wpdb->query("DELETE FROM {$p}nodes WHERE id = {$in}");

$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '__cv %'");

echo "\n";
$say($left === 0, 'die Spielwiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
