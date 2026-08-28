<?php declare(strict_types=1);

/**
 * Ein Akt, eine Änderungsnummer.
 *
 * ⚠️ **Der Eigentümer, 2026-08-26:** *«Ich glaube, wir brauchen eine eindeutige Änderungsnummer —
 * was in einer Änderung geändert wurde, Kante, Knoten, Setting, wenn sie zusammen geändert wurden,
 * sollten sie eine Änderungsnummer haben.»*
 *
 * ⚠️ *Gemessen, bevor die Klammer da war: 2282 Zeilen in 1945 Gruppen, davon **1609 mit einer
 * einzigen Zeile**, und **0 von 1945** über mehr als eine Art von Eigentümer. Die Spalte war da, war
 * entschieden ([D-348](../../docs/NewConcept/90-decision-log.md)) und gruppierte nichts.*
 *
 * ⚠️ **Gegen die echte Datenbank, weil der Fehler in der Verdrahtung saß und nicht im Kern.**
 * *`new WpdbChangelog(…)` stand siebenmal in `Plugin` — die Klammer auf dem einen Exemplar hätte den
 * anderen sechs nichts gesagt. Ein Kerntest mit einem Doppelgänger hätte das nie gesehen.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require 'C:/Devel/Wordpress/source/wp-taxonomy-tree/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, 'C:/Devel/Wordpress/source/wp-taxonomy-tree/wp-taxonomy-modeler.php');

// ⚠️ *Über `$plugin` und nicht über eigene Exemplare — es ist genau die Verdrahtung, die geprüft
// werden soll. Ein selbst gebauter Editor hätte seinen eigenen Changelog und wäre grün.*
$editor    = $plugin->editor();
$changelog = (new ReflectionMethod($plugin, 'changelog'))->invoke($plugin);
$fw        = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

$vorher = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

    exit(2);
}

// ── Ein zusammengesetzter Akt, in einer Klammer ────────────────────────────
$changelog->beginAct();

$ast   = $editor->createNode('__cg Ast', $fw->rootOf(Branch::Model)->id);
$kind  = $editor->createNode('__cg Kind', $ast->id);
$enkel = $editor->createNode('__cg Enkel', $kind->id);

$editor->rename($ast->id, '__cg Ast neu');

// ⚠️ *Ein Feld, damit die Gruppe eine **Kante** enthaelt — das ist die Zusage des Eigentuemers,
// «Kante, Knoten, Setting … eine Aenderungsnummer». `moveUp()` stand hier zuerst und schrieb nichts:
// der Knoten war das einzige Kind, also war nichts zu bewegen. **Eine Pruefung, die eine Zusage
// gegen einen Fall haelt, der nicht eintritt, ist gruen und wertlos.***
$typ  = $editor->createNode('__cg Typ', $fw->rootOf(Branch::DataTypes)->id);
$feld = $editor->addField($ast->id, $typ->id, 'menge');
$editor->renameField($ast->id, $feld->id, 'anzahl');

$changelog->endAct();

$zeilen = $wpdb->get_results(
    $wpdb->prepare("SELECT change_group_id, owner_kind, what FROM {$p}changelog WHERE id > %d", $vorher)
);

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "Abfrage kaputt: {$wpdb->last_error}\n");

    exit(2);
}

$gruppen = array_unique(array_map(static fn (object $z): int => (int) $z->change_group_id, $zeilen));
$arten   = array_unique(array_map(static fn (object $z): string => (string) $z->owner_kind, $zeilen));

echo "== ein Akt, eine Nummer ==\n";

printf("       %d Zeilen geschrieben: %s\n", count($zeilen), implode(', ', array_map(static fn (object $z): string => $z->what, $zeilen)));

$say(count($zeilen) > 1, 'der Akt hat mehr als eine Zeile geschrieben');
$say(count($gruppen) === 1, sprintf('sie liegen in EINER Gruppe (gefunden: %d)', count($gruppen)));
$say(! in_array(0, $gruppen, true), 'und nicht in Gruppe null — der Fehler, den der Kern nicht sehen kann');

// ⚠️ *Die eigentliche Zusage des Eigentümers: Knoten UND Kante in derselben Nummer. Vorher war das
// bei 0 von 1945 Gruppen der Fall.*
$say(count($arten) > 1, sprintf('die Gruppe umspannt mehr als eine Art Eigentümer (%s)', implode(' + ', $arten)));

// ── Und ohne Klammer bleibt es, wie es war ─────────────────────────────────
$marke = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$p}changelog");

$allein = $editor->createNode('__cg Allein', $fw->rootOf(Branch::Model)->id);
$editor->rename($allein->id, '__cg Allein neu');

$ohne = array_unique(array_map(
    static fn (object $z): int => (int) $z->change_group_id,
    $wpdb->get_results($wpdb->prepare("SELECT change_group_id FROM {$p}changelog WHERE id > %d", $marke))
));

echo "\n== ohne Klammer bleibt jeder Schreibvorgang seine eigene Nummer ==\n";

$say(count($ohne) === 2, sprintf('zwei Schreibvorgänge, zwei Gruppen (gefunden: %d)', count($ohne)));

// aufraeumen
foreach ([$allein->id, $enkel->id, $kind->id, $feld->id, $typ->id, $ast->id] as $id) {
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

echo "\n";
$say((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes WHERE name LIKE '__cg %'") === 0, 'die Wiese ist wieder weg');

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
