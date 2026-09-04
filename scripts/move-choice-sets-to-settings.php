<?php declare(strict_types=1);
/**
 * `Renderer`, `Converter`, `Label roles` und `Validator` in den `Settings`-Zweig.
 *
 *     php scripts/move-choice-sets-to-settings.php            (Probelauf)
 *     php scripts/move-choice-sets-to-settings.php --write    (verschiebt)
 *
 * ⚠️ **Auf sein Wort, und der Zweig hat genau eine Aufgabe:** *er beantwortet, wo ein Wert
 * gespeichert wird. Der Eigentümer, als ich Unterknoten zur Ordnung vorschlug: «man könnte die
 * Knoten anlegen, um es ordentlicher zu machen, **aber ohne zusätzliche Funktion, oder?»* — richtig,
 * also gibt es sie nicht.*
 *
 * ⚠️ **Was der Zweig **nicht** sagt: «das ist eine Einstellung».** *Das sagt die Kante
 * ([D-526](../docs/NewConcept/90-decision-log.md)). Sein Satz dazu: «ich sehe nicht, dass wir
 * unbedingt einen Knoten brauchen, wenn wir eine Einstellungskante auf `int` setzen und sie
 * `exponent` nennen.» **Der Typ kommt vom Ziel, die Einstellung von der Kante** — dieser Zweig ist
 * nur der Ort für die Mengen, aus denen eine Auswahl-Einstellung wählt.*
 *
 * ⚠️ *`DisplayOption` bleibt unter `Compositions`: es hält Datensätze, und genau das bedeutet
 * `Compositions` — ein Teil gehört dem, der ihn erklärt hat, und stirbt mit ihm.*
 *
 * ⚠️ **Und [D-151](../docs/NewConcept/90-decision-log.md) wollte das von Anfang an:** *«Label roles are
 * nodes … modelling-time content **under their own branch**». Der Satz im Saatgut, der dagegen zu
 * sprechen schien, sagt «kein **Daten**zweig» — und das stimmt weiter: dieser Zweig hält keine
 * Datensätze.*
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

use Taxmod\Core\Model\Branch;
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

$ziel = $fw->rootOf(Branch::Settings);

echo "Astwurzel: {$ziel->id} «{$ziel->name}» ({$ziel->path})\n\n";

$n = Schema::table('nodes');
$r = Schema::table('relations');

$umziehen = ['Renderer', 'Converter', 'Label roles', 'Validator'];
$plan     = [];

foreach ($umziehen as $name) {
    $treffer = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, path FROM {$n} WHERE name = %s ORDER BY id",
        $name
    ), ARRAY_A) ?: [];

    foreach ($treffer as $t) {
        $kinder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$n} WHERE path LIKE %s",
            $wpdb->esc_like($t['path'] . '.') . '%'
        ));

        $felder = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$r} WHERE to_id = %d AND kind <> 'inheritance'",
            (int) $t['id']
        ));

        // ⚠️ *Leere Doppelgänger kommen weg statt mitzuziehen — auf `Label roles` gab es zwei, einer
        // ist Rückstand des doppelten Baums vom 2026-08-29.*
        $was = ($kinder === 0 && $felder === 0 && $t['name'] === 'Label roles') ? 'WEG (leer)' : 'umziehen';

        $plan[] = ['knoten' => $t, 'kinder' => $kinder, 'felder' => $felder, 'was' => $was];

        printf(
            "  %-14s %-8s %-22s %2d Kinder, %d Felder darauf  =>  %s\n",
            $t['name'],
            $t['id'],
            $t['path'],
            $kinder,
            $felder,
            $was
        );
    }
}

if (! $schreiben) {
    echo "\nProbelauf. Mit --write verschieben.\n";
    exit(0);
}

echo "\n";

foreach ($plan as $p) {
    $id = (int) $p['knoten']['id'];

    if ($p['was'] !== 'umziehen') {
        $editor->moveToTrash($id);
        echo "  in den Muell  {$p['knoten']['name']} ({$id})\n";

        continue;
    }

    $editor->move($id, $ziel->id);
    echo "  umgezogen     {$p['knoten']['name']} ({$id}) -> Settings\n";
}

$gone = $editor->clearTrash();

echo "\nMuell geleert: nodes {$gone['nodes']}, edges {$gone['edges']}\n\nDer Zweig jetzt:\n";

foreach ($wpdb->get_results($wpdb->prepare(
    "SELECT id, name, path FROM {$n} WHERE path LIKE %s AND path NOT LIKE %s ORDER BY name",
    $wpdb->esc_like($ziel->path . '.') . '%',
    $wpdb->esc_like($ziel->path . '.') . '%.%'
), ARRAY_A) ?: [] as $z) {
    printf("  %-8s %s\n", $z['id'], $z['name']);
}
