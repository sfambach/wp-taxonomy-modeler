<?php declare(strict_types=1);

/** Der Abbruch: ein versteckter Knoten nimmt seinen Ast mit aus dem Baum. */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$nodes = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $relations, $log);

$editor = new ModelEditor($nodes, $relations, $fw, $log);

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$ast   = $editor->createNode('__ab Ast', $fw->rootOf(Branch::Model)->id);
$kind  = $editor->createNode('__ab Kind', $ast->id);
$enkel = $editor->createNode('__ab Enkel', $kind->id);

$r = new ReflectionClass(\Taxmod\WordPress\Plugin::class);

$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

$screen = $plugin->screen();

// ⚠️ **Auf die Zeilen-Id geprueft und nicht auf den Namen.** *Ein abgebrochener Lauf hinterlaesst
// Knoten mit demselben Namen, und `str_contains($markup, '__ab Ast')` findet dann den **Rest** statt
// die Wiese dieses Laufs. Genau das hat diese Pruefung beim Umbau falsch gemeldet.*
$sichtbar = static function (\Taxmod\WordPress\Admin\NodesScreen $screen, array $ids): array {
    $markup = $screen->render();
    $da     = [];

    foreach ($ids as $name => $id) {
        $da[$name] = str_contains($markup, 'id="taxmod-node-' . $id . '"');
    }

    return $da;
};

$namen = ['__ab Ast' => $ast->id, '__ab Kind' => $kind->id, '__ab Enkel' => $enkel->id];

// ⚠️ **Der Baum startet eingeklappt** ([Zeile 60](../../docs/NewConcept/97-implementation-plan.md#the-working-list)),
// also muss diese Pruefung ihn ausdruecklich aufklappen — ihr Gegenstand ist `hide` und nicht das
// Falten. *Und sie waere ohne das nicht bloss rot geworden, sie war **gruen aus dem falschen Grund**:
// die drei «ist weg»-Zusagen in der Mitte hielten, weil die Knoten eingeklappt waren, nicht weil sie
// versteckt waren. Der Merker `none` heisst «absichtlich alles offen» und ist genau dafuer da.*
$_GET['taxmod_collapsed'] = 'none';

echo "== vorher stehen alle drei im Baum ==\n";

$vorher = $sichtbar($screen, $namen);

foreach (array_keys($namen) as $name) {
    $say($vorher[$name], sprintf('«%s» ist da', $name));
}

echo "\n== jetzt den Ast verstecken ==\n";

// ⚠️ *Die Vererbungskante, nicht der Knoten ([D-467]) — `hidePlacement()` findet sie selbst.*
$editor->hidePlacement($ast->id, true);

$nachher = $sichtbar($plugin->screen(), $namen);

foreach (array_keys($namen) as $name) {
    $say(! $nachher[$name], sprintf('«%s» ist weg', $name));
}

echo "\n== und wieder zeigen ==\n";

$editor->hidePlacement($ast->id, false);

$wieder = $sichtbar($plugin->screen(), $namen);

foreach (array_keys($namen) as $name) {
    $say($wieder[$name], sprintf('«%s» ist zurueck', $name));
}

// aufraeumen
global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

foreach ([$enkel->id, $kind->id, $ast->id] as $id) {
    $e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id = {$id} OR to_node_id = {$id}"));
    $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

    $wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");
    $wpdb->query("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

    if ($e) {
        $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
    }

    $wpdb->query("DELETE FROM {$p}nodes WHERE id = {$id}");
}

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '__ab %'") === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
