<?php declare(strict_types=1);
/**
 * Die vier Wertzeilen wegraeumen, die an geloeschten Kanten haengen.
 *
 *     php scripts/dev/dead-edge-values-clean.php [--apply] [path/to/wordpress]
 *
 * ⚠️ **Der Anlass:** *der Eigentuemer hat den Huellknoten `DisplayOption` geloescht; seine Kanten
 * `render` (44091), `converter` (44092) und die Traegerkante (44093) sind mit ihm gefallen. Vier
 * Wertzeilen zeigen seither auf eine Adresse, die es nicht mehr gibt.*
 *
 * ⚠️ **Eine davon ist die aus [D-616](../../docs/NewConcept/90-decision-log.md)** — *die Zeile am
 * `default`-Satz der Modellwurzel, die `render = checkbox` sagte. Sein Wort: der Mechanismus bleibt,
 * «falsch war nur der eine Wert, der dort stand; er wird weggeraeumt». Und die Entscheidung nennt
 * sie ausdruecklich als eine der «vier Reste aus TASK-046».*
 *
 * ⚠️ **Es faellt nur, was an einer wirklich verschwundenen Kante haengt.** *Der Lauf sucht die
 * Kanten-Ids nicht aus einer Liste in dieser Datei, sondern fragt `relations` — steht die Kante
 * wieder da, faellt ihre Zeile nicht. Ohne `--apply` wird nur gezeigt, was faellt.*
 *
 * @see docs/pakete/modelltabellen/geltende-regeln.md
 */

$argumente = array_slice($argv, 1);
$apply     = in_array('--apply', $argumente, true);
$root      = null;

foreach ($argumente as $eines) {
    if ($eines !== '--apply') {
        $root = $eines;
    }
}

$root ??= getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;

global $wpdb;

$zeilen = $wpdb->get_results(
    'SELECT w.id, w.node_record_id, w.relation_id, w.path, w.value_ref, w.value_ref_kind
       FROM ' . Schema::table('relation_records') . ' w
       LEFT JOIN ' . Schema::table('relations') . ' e ON e.id = w.relation_id
      WHERE w.relation_id <> 0 AND e.id IS NULL
      ORDER BY w.id',
    ARRAY_A
) ?: [];

if ($zeilen === []) {
    echo "Keine Wertzeile haengt an einer verschwundenen Kante.\n";

    exit(0);
}

echo count($zeilen) . " Wertzeile(n) an verschwundenen Kanten:\n";

foreach ($zeilen as $z) {
    $besitzer = $wpdb->get_var($wpdb->prepare(
        'SELECT n.name FROM ' . Schema::table('node_records') . ' r
         INNER JOIN ' . Schema::table('nodes') . ' n ON n.id = r.node_id
         WHERE r.id = %d',
        (int) $z['node_record_id']
    ));

    echo "  Zeile {$z['id']} · Satz {$z['node_record_id']} (" . ($besitzer ?? 'ohne Knoten')
        . ") · Kante {$z['relation_id']} · Verweis {$z['value_ref']} ({$z['value_ref_kind']})\n";
}

if (! $apply) {
    echo "\nProbelauf. Mit --apply wird geloescht.\n";

    exit(0);
}

// ⚠️ *Die Schattenzeilen gehen mit — eine Geschichte zu einer Adresse, die es nicht gibt, ist
// dieselbe Leiche eine Tabelle weiter.*
$weg = 0;

foreach ($zeilen as $z) {
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . Schema::table('relation_records') . ' WHERE id = %d',
        (int) $z['id']
    ));

    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . Schema::table('relation_records_history') . ' WHERE id = %d',
        (int) $z['id']
    ));

    ++$weg;
}

echo "\n{$weg} Wertzeile(n) geloescht.\n";
