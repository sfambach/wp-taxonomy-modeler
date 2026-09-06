<?php declare(strict_types=1);

/**
 * Der Sprunglink: das Ziel eines Attributs führt zu seinem Knoten.
 *
 * ⚠️ **Der Eigentümer, 2026-08-26:** *«should have a jump link to the node.»* Vorher stand
 * `BOM Position` als Text in der Zeile, und ein Modell zu lesen hieß, das Ziel im Baum mit dem Auge
 * zu suchen.
 *
 * ⚠️ *Gegen eine echte Datenbank und den echten Screen geprüft, nicht gegen den Kern allein — der
 * Kern kann keine URL bauen (`CD-1`), also ist «die Adresse kommt an» genau die Naht, die ein
 * Kerntest nicht sehen kann.*
 */

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

$model = $fw->rootOf(Branch::Model)->id;

$teil = $editor->createNode('__tl Teil', $model);
$pos  = $editor->createNode('__tl Position', $model);
$relation = $editor->addField($teil->id, $pos->id, 'position');

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

// ⚠️ *Den Knoten auswählen, denn nur seine Seite zeichnet die Attributtabelle.* Der Screen liest die
// Auswahl aus der Anfrage, deshalb steht sie hier in `$_GET`.
$_GET['taxmod_node'] = (string) $teil->id;

$markup = $plugin->screen()->render();

echo "== die Zeile führt zum Ziel ==\n";

$say(
    str_contains($markup, 'class="taxmod-field-target-link taxmod-chosen"'),
    'das Ziel ist ein Link und kein Text'
);

// ⚠️ **Auf die Id im href geprüft, nicht auf den Namen.** *Derselbe Grund wie in
// `hide-abort-check.php`: ein abgebrochener Lauf hinterlässt Knoten mit denselben Namen, und ein
// Namensvergleich findet dann den Rest statt die Wiese dieses Laufs.*
$say(
    (bool) preg_match(
        '/<a href="[^"]*taxmod_node=' . $pos->id . '[^"]*" class="taxmod-field-target-link taxmod-chosen"/',
        $markup
    ),
    sprintf('der Link wählt Knoten %d aus', $pos->id)
);

$say(
    ! str_contains($markup, 'taxmod_node=' . $pos->id . '#'),
    'kein #fragment — es hat sich mit dem Scroll-Skript geschlagen'
);

// ⚠️ *Und die Gegenprobe: der Name ist noch da. Ein Link, der seinen Text verliert, wäre grün und
// unbenutzbar.*
$say(
    str_contains($markup, '__tl Position</a>'),
    'der Name des Ziels steht im Link'
);

// aufraeumen
global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

foreach ([$pos->id, $teil->id] as $id) {
    $e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_node_id = {$id} OR to_node_id = {$id}"));
    $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

    $wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");
    $wpdb->query("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

    if ($e !== []) {
        $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
    }

    $wpdb->query("DELETE FROM {$p}nodes WHERE id = {$id}");
}

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '__tl %'") === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
