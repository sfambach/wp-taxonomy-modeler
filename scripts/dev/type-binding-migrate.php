<?php declare(strict_types=1);
/**
 * Einmalig: die elf `taxmod_type_<name>_id` fallen lassen (TASK-009, zweite Hälfte).
 *
 *     php scripts/dev/type-binding-migrate.php [path/to/wordpress] [--dry]
 *
 * ⚠️ **Sie hielten die Bindung «welcher Knoten ist der Integer-Typ» ausserhalb des Modells, gegen
 * `AR-1`.** *Sie standen noch, weil ein einfacher Typ ein **Aufzählungsfall** war und keine Klasse,
 * und `nodes.implemented_by` einen Klassennamen trägt (TASK-008). **Mit
 * [D-484](../../docs/NewConcept/90-decision-log.md) hat jeder Typ eine Klasse**, also sagt der Knoten
 * es jetzt selbst.*
 *
 * ⚠️ **Reihenfolge: Wächter, Leser, Daten.** *Der Wächter ist `simple-type-check.php`, der Leser ist
 * {@see \Taxmod\WordPress\Persistence\SeededTypeNodes} — beide stehen, bevor dieser Lauf etwas
 * löscht. **Und er löscht nur, was mit dem Knoten übereinstimmt**: eine Option, die auf etwas
 * anderes zeigt als die Spalte, wird gemeldet und bleibt stehen.*
 *
 * ⚠️ *Die Modelländerung selbst — der Klassenname im Knoten — ist umkehrbar: sie läuft über
 * {@see \Taxmod\Core\Service\ModelEditor::setImplementedBy()}, also mit Schattenzeile und
 * Änderungsbuch ([D-535](../../docs/NewConcept/90-decision-log.md)). Dieser Lauf schreibt die Werte
 * vorher hin, damit auch die gelöschte Option nachlesbar bleibt.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$argumente = array_slice($argv, 1);
$trocken   = in_array('--dry', $argumente, true);
$root      = null;

foreach ($argumente as $argument) {
    if (! str_starts_with($argument, '--')) {
        $root = $argument;

        break;
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

use Taxmod\Core\Model\SimpleType;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$types     = new SeededTypeNodes($nodes, $framework);

$gefallen  = 0;
$standNich = 0;
$strittig  = [];

foreach (SimpleType::cases() as $type) {
    $option = 'taxmod_type_' . $type->value . '_id';
    $alt    = get_option($option, false);
    $imBaum = $types->nodeId($type);

    if ($alt === false) {
        $standNich++;

        continue;
    }

    if ($imBaum === null || (int) $alt !== $imBaum) {
        $strittig[] = $option . ' = ' . var_export($alt, true) . ', der Knoten sagt ' . var_export($imBaum, true);

        continue;
    }

    echo ($trocken ? '  [trocken] ' : '  ') . $option . ' = ' . $alt
        . ' → steht als ' . SeededTypeNodes::classFor($type) . " im Knoten\n";

    if (! $trocken) {
        delete_option($option);
    }

    $gefallen++;
}

printf(
    "\n%d %s, %d standen schon nicht mehr, %d strittig\n",
    $gefallen,
    $trocken ? 'wären zu löschen' : 'gelöscht',
    $standNich,
    count($strittig)
);

foreach ($strittig as $was) {
    echo "  strittig, nicht angefasst: $was\n";
}

exit($strittig === [] ? 0 : 1);
