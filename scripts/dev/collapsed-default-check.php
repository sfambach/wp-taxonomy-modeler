<?php declare(strict_types=1);

/**
 * Der Baum startet eingeklappt — und «alles offen» bleibt davon unterscheidbar.
 *
 * ⚠️ **Der Eigentümer, 2026-08-28:** *«wenn ich die Seite neu aufmach, dann sollte Kolleps sein — das
 * bitte die beste Übersicht.»*
 *
 * ⚠️ **Gegen die echte Seite und nicht nur gegen den Kern, weil die Vorgabe an der Naht sitzt.**
 * *`Tree::collapsedByDefault()` ist Kern und hat eigene Tests; was hier geprüft wird, ist die
 * Verdrahtung: `NodesScreen` muss «kein Parameter» als **frische Seite** lesen und
 * `taxmod_collapsed=none` als **absichtlich alles offen**. Ein Kerntest sieht diesen Unterschied nie,
 * weil er kein `$_GET` hat.*
 *
 * ⚠️ **Auf Zeilen-Ids geprüft und nicht auf Namen** — ein abgestürzter Lauf hinterlässt Knoten mit
 * denselben Namen, und ein Namensvergleich findet dann den Rest statt die eigene Wiese.
 *
 * ⚠️ *Und die Gegenprobe ist mitgeprüft: dieselbe Seite mit `taxmod_collapsed=none` muss den Enkel
 * **zeigen**. Ohne sie wäre eine Abfrage, die versehentlich nichts findet, genauso grün.*
 *
 * Usage: php scripts/dev/collapsed-default-check.php
 *
 * @see docs/NewConcept/97-implementation-plan.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Tree;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

$nodes = new WpdbNodeRepository();
$edges = new WpdbRelationRepository();
$log   = new WpdbChangelog(new SystemClock());
$fw    = new SeededFrameworkNodes($nodes, $edges, $log);

$editor = new ModelEditor($nodes, $edges, $fw, $log);
$tree   = new Tree($nodes, $edges);

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$ast   = $editor->createNode('__cd Ast', $fw->rootOf(Branch::Model)->id);
$kind  = $editor->createNode('__cd Kind', $ast->id);
$enkel = $editor->createNode('__cd Enkel', $kind->id);

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

/** Welche der drei Zeilen die gezeichnete Seite enthält — an der Id, nicht am Namen. */
$sichtbar = static function (NodesScreen $screen, array $ids): array {
    $markup = $screen->render();
    $da     = [];

    foreach ($ids as $name => $id) {
        $da[$name] = str_contains($markup, 'id="taxmod-node-' . $id . '"');
    }

    return $da;
};

$wiese = ['Ast' => $ast->id, 'Kind' => $kind->id, 'Enkel' => $enkel->id];

// ── Die frische Seite: kein Parameter, alles zu ────────────────────────────
unset($_GET['taxmod_collapsed'], $_GET['taxmod_node'], $_GET['taxmod_hidden']);

echo "== die frische Seite zeigt nur die oberste Ebene ==\n";

$frisch = $sichtbar($plugin->screen(), $wiese);

// ⚠️ **Gemessen und es ist mehr als erwartet weg: auch der Ast.** *Er hängt unter `Model`, und
// `Model` ist selbst ein Zweig unter der Wurzel — also ist die frische Seite genau die Liste der
// Zweige (Model, Kompositionen, Einheiten, Datentypen …) und sonst nichts. **Das ist «die beste
// Übersicht», die der Eigentümer nannte**, und es war beim Schreiben dieser Prüfung nicht
// selbstverständlich: die erste Fassung behauptete, der Ast stünde noch da, und wurde widerlegt.
$say(! $frisch['Ast'], 'der Ast ist eingeklappt — Model ist selbst ein Zweig und zu');
$say(! $frisch['Kind'], 'das Kind ist eingeklappt');
$say(! $frisch['Enkel'], 'der Enkel ist eingeklappt');

// ── Die Gegenprobe: absichtlich alles offen ───────────────────────────────
echo "\n== und mit dem Merker «alles offen» stehen alle drei da ==\n";

$_GET['taxmod_collapsed'] = 'none';

$offen = $sichtbar($plugin->screen(), $wiese);

foreach (array_keys($wiese) as $name) {
    $say($offen[$name], sprintf('«%s» ist da', $name));
}

// ⚠️ *Der eigentliche Punkt des Merkers: **ohne** ihn wäre diese URL «kein Parameter» und damit
// wieder die frische, eingeklappte Seite. Die beiden Läufe oben sind genau derselbe Aufruf mit
// genau einem Unterschied — deshalb ist die Zusage geprüft und nicht nur behauptet.*
$say($frisch['Enkel'] !== $offen['Enkel'], 'die zwei Zustände sind unterscheidbar — sonst wäre die Prüfung wertlos');

// ── Der ausgewählte Knoten ist immer sichtbar ─────────────────────────────
echo "\n== der Pfad zum ausgewählten Knoten ist offen ==\n";

unset($_GET['taxmod_collapsed']);

$_GET['taxmod_node'] = (string) $enkel->id;

$gewaehlt = $sichtbar($plugin->screen(), $wiese);

foreach (array_keys($wiese) as $name) {
    $say($gewaehlt[$name], sprintf('«%s» liegt auf dem Pfad und ist da', $name));
}

unset($_GET['taxmod_node']);

// ── Der Zustand wird fortgeschrieben ──────────────────────────────────────
//
// ⚠️ **Die Bitte des Eigentümers, nachdem das Einklappen gebaut war:** *«ich hätte lieber, dass der
// Status — welcher Knoten offen ist und welcher nicht — **fortgeschrieben** wird … wenn ich zwischen
// zwei Knoten arbeite und dauernd die Äste zugehen, das ist ziemlich nervig.»*
//
// ⚠️ *Und es war kaputt, obwohl der Zustand «in der URL lag»: eine **frische** Seite trug ihn in
// keinem einzigen Link, also berechnete der nächste Klick die Vorgabe neu. **Der Zustand lag in der
// URL und niemand schrieb ihn hinein.** Diese Prüfung sieht genau da hin.*
echo "\n== der Faltzustand wird in die Links geschrieben, auch auf einer frischen Seite ==\n";

$_GET['taxmod_node'] = (string) $enkel->id;

$markup = $plugin->screen()->render();

$mitZustand = preg_match_all('/taxmod_collapsed=([^&"]*)/', $markup, $treffer);

printf("       %d Links tragen den Faltzustand\n", $mitZustand);

$say($mitZustand > 0, 'die frische Seite schreibt den Zustand in ihre Links');

$menge = array_map('intval', explode(',', urldecode($treffer[1][0] ?? '')));

// ⚠️ *Der Kern der Zusage: die Vorfahren des **anderen** Knotens bleiben in der mitgeführten Menge
// frei, wenn man auf ihn springt — sonst geht sein Ast zu, was er «ziemlich nervig» nannte.*
$say(
    array_intersect($enkel->ancestorIds(), $menge) === [],
    'die Vorfahren des gewählten Knotens stehen nicht in der mitgeführten Menge'
);

$_GET['taxmod_collapsed'] = urldecode($treffer[1][0] ?? '');
$_GET['taxmod_node']      = (string) $ast->id;

$zweiter = $plugin->screen()->render();

preg_match('/taxmod_collapsed=([^&"]*)/', $zweiter, $zweiteMenge);

$menge2 = array_map('intval', explode(',', urldecode($zweiteMenge[1] ?? '')));

$say(
    array_intersect($enkel->ancestorIds(), $menge2) === [],
    'nach dem Sprung auf einen anderen Knoten ist der erste Ast NOCH offen — fortgeschrieben'
);

$say(
    array_intersect($ast->ancestorIds(), $menge2) === [],
    'und der Ast des neuen Knotens ist auch offen'
);

unset($_GET['taxmod_node'], $_GET['taxmod_collapsed']);

// ── Und der Kern sagt dasselbe über sich ──────────────────────────────────
echo "\n== der Kern: was gefaltet wird, sind die Knoten mit Kindern ==\n";

$gefaltet = $tree->collapsedByDefault();

printf("       %d Knoten mit Kindern im ganzen Modell\n", count($gefaltet));

$say(in_array($ast->id, $gefaltet, true), 'der Ast hat ein Kind und ist gefaltet');
$say(in_array($kind->id, $gefaltet, true), 'das Kind hat ein Kind und ist gefaltet');
$say(! in_array($enkel->id, $gefaltet, true), 'der Enkel hat keins und ist nicht gefaltet');

$mitPfad = $tree->collapsedByDefault($enkel);

$say(! in_array($ast->id, $mitPfad, true), 'mit dem Enkel als Ziel ist der Ast offen');
$say(! in_array($kind->id, $mitPfad, true), '… und das Kind auch');
// ⚠️ *Vier und nicht drei: **die Wurzel zählt mit.** Sie steht im Pfad jedes Knotens und hat Kinder,
// also ist sie gefaltet wie jeder andere Elternknoten — und dass ihre Faltung nie gelesen wird
// (`collect()` beginnt **unter** ihr), ändert nichts daran, dass sie in der Menge steht.*
$say(count($gefaltet) - count($mitPfad) === 4, sprintf('genau die vier Vorfahren fielen heraus (Wurzel, Model, Ast, Kind) — %d gegen %d', count($gefaltet), count($mitPfad)));

// aufraeumen
global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

foreach ([$enkel->id, $kind->id, $ast->id] as $id) {
    $e   = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}relations WHERE from_id = {$id} OR to_id = {$id}"));
    $own = $e === [] ? (string) $id : $id . ',' . implode(',', $e);

    $wpdb->query("DELETE FROM {$p}settings WHERE owner_id IN ({$own})");
    $wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$own})");
    $wpdb->query("DELETE FROM {$p}changelog WHERE owner_id IN ({$own})");

    if ($e !== []) {
        $wpdb->query('DELETE FROM ' . $p . 'relations WHERE id IN (' . implode(',', $e) . ')');
    }

    $wpdb->query("DELETE FROM {$p}nodes WHERE id = {$id}");
}

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Aufraeumen kaputt: {$wpdb->last_error}\n");

    exit(2);
}

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE id IN (" . implode(',', [$ast->id, $kind->id, $enkel->id]) . ')') === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
