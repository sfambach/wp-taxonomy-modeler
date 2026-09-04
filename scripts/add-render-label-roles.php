<?php declare(strict_types=1);
/**
 * Ein Zwischenknoten `render label roles` unter `Renderer`, und das Feld `label_role` daran.
 *
 *     php scripts/add-render-label-roles.php            (Probelauf)
 *     php scripts/add-render-label-roles.php --write    (baut)
 *
 * ⚠️ **Auf sein Wort, nachdem die Messung die Frage geschärft hatte.** *Gemessen wird die Namensrolle
 * nur gefragt, **wenn der Wert eines Feldes ein Verweis ist** — von 16 Renderern zeichnen genau drei
 * einen: `reference`, `chooser-inline`, `chooser-dialog`. Die anderen dreizehn sehen nie einen Namen.*
 *
 * ⚠️ **Darum ein Zwischenknoten und nicht ein Feld an `Renderer` selbst:** *hinge `label_role` an
 * jedem Renderer, trügen dreizehn davon einen Schalter, der nie etwas tut. Der Eigentümer: «ok, machen
 * wir einen Zwischenknoten «render label roles»».*
 *
 * ⚠️ *Die drei erben das Feld, weil ein Kind die Felder seines Vaters erbt — kein neuer Mechanismus.
 * Und die Auswahl bleibt richtig, weil **die Wählbaren die Blätter sind**: `render label roles` ist
 * selbst keine Wahl, sondern eine Gruppe darin.*
 */

$schreiben = in_array('--write', $argv, true);
$root      = getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes  = new WpdbNodeRepository();
$edges  = new WpdbRelationRepository();
$log    = new WpdbChangelog(new SystemClock());
$fw     = new SeededFrameworkNodes($nodes, $edges, $log);
$editor = new ModelEditor($nodes, $edges, $fw, $log, records: new WpdbRecordRepository());

$n = Schema::table('nodes');

/** Ein Knoten über seinen Namen, oder null. */
function knotenNamens(string $name): ?int
{
    global $wpdb, $n;

    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$n} WHERE name = %s LIMIT 1", $name));

    return $id === 0 ? null : $id;
}

$renderer   = knotenNamens('Renderer');
$labelRoles = knotenNamens('Label roles');
$gruppe     = knotenNamens('render label roles');

if ($renderer === null || $labelRoles === null) {
    fwrite(STDERR, "«Renderer» oder «Label roles» fehlt.\n");
    exit(1);
}

$dieDrei = ['reference', 'chooser-inline', 'chooser-dialog'];

echo "Renderer = {$renderer}, Label roles = {$labelRoles}\n";
echo 'Gruppe   = ' . ($gruppe ?? 'wird angelegt') . "\n\n";

foreach ($dieDrei as $name) {
    $id = knotenNamens($name);
    printf("  %-16s %s\n", $name, $id === null ? 'FEHLT' : 'zieht unter die Gruppe (' . $id . ')');
}

if (! $schreiben) {
    echo "\nDanach bekommt die Gruppe ein Feld «label_role» auf «Label roles», als Einstellungskante.\n";
    echo "Probelauf. Mit --write bauen.\n";
    exit(0);
}

echo "\n";

if ($gruppe === null) {
    $gruppe = $editor->createNode('render label roles', $renderer)->id;
    echo "  angelegt      «render label roles» ({$gruppe})\n";
}

foreach ($dieDrei as $name) {
    $id = knotenNamens($name);

    if ($id === null) {
        echo "  ⚠ {$name} fehlt\n";

        continue;
    }

    $editor->move($id, $gruppe);
    echo "  umgezogen     {$name} -> render label roles\n";
}

// Das Feld, und es ist eine Einstellungskante: die Rolle ist eine Angabe des Modells.
$vorhanden = null;

foreach ($edges->fieldEdgesOf([$gruppe]) as $eine) {
    if ($eine->name === 'label_role') {
        $vorhanden = $eine;
    }
}

if ($vorhanden === null) {
    $feld = $editor->addField($gruppe, $labelRoles, 'label_role');
    $editor->markAsSetting($gruppe, $feld->id, true);
    echo "  Feld angelegt «label_role» -> Label roles (Einstellungskante, {$feld->id})\n";
} else {
    echo "  Feld stand schon: {$vorhanden->id}\n";
}

echo "\nUnter «render label roles» jetzt:\n";

$pfad = $nodes->byId($gruppe)->path;

foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT id, name FROM {$n} WHERE path LIKE %s ORDER BY name",
    $wpdb->esc_like($pfad . '.') . '%'
), ARRAY_A) ?: [] as $z) {
    printf("  %-8s %s\n", $z['id'], $z['name']);
}
