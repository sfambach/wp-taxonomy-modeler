<?php declare(strict_types=1);
/**
 * Einmalig: die Klassennamen in `nodes.implemented_by` schreiben (TASK-008).
 *
 *     php scripts/dev/implemented-by-migrate.php [path/to/wordpress] [--dry]
 *
 * ⚠️ **Die Quelle sind die Optionen, die dabei sind zu fallen** — *`taxmod_render_renderer_slider_id`
 * sagt heute, welcher Knoten der Schieber-Renderer ist. **Genau diese Auskunft zieht in den Knoten
 * um** (TASK-009); danach hält keine Option mehr eine Knoten-Id, und `AR-1` gilt auch für sie.*
 *
 * ⚠️ **Über die Ids und nicht über die Namen** ([D-022](../../docs/NewConcept/90-decision-log.md),
 * [D-510](../../docs/NewConcept/90-decision-log.md)): *Knotennamen sind absichtlich nicht eindeutig,
 * und `int` neben `Integer` ist der Fall, an dem das schon einmal wehgetan hat.*
 *
 * ⚠️ **Umkehrbar** ([D-535](../../docs/NewConcept/90-decision-log.md)): *jede angefasste Zeile steht
 * vorher als Schattenzeile in `nodes_history`, weil der Umzug über
 * {@see \Taxmod\Core\Service\ModelEditor::setImplementedBy()} läuft und nicht über ein `UPDATE`.
 * Dieselbe Bewegung schreibt ihre Zeile ins Änderungsbuch.*
 *
 * @see docs/pakete/modelltabellen/package.md
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

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$editor    = new ModelEditor($nodes, $edges, $framework, $log);

$registraturen = [
    'Renderer'  => ShippedRenderers::registry(),
    'Converter' => ShippedConverters::registry(),
    'Validator' => ShippedValidators::registry(),
];

$geschrieben = 0;
$standSchon  = 0;
$gefallen    = 0;
$ohneOption  = [];

foreach ($registraturen as $behaelter => $registratur) {
    foreach ($registratur->namesForNodes() as $name) {
        $klasse = $registratur->classFor($name);

        if ($klasse === null) {
            continue;
        }

        $option = RenderingScaffold::optionFor($behaelter, $name);
        $id     = (int) get_option($option, 0);

        if ($id === 0) {
            // ⚠️ *Nicht raten (`PR-4`). Ohne Option ist der Knoten dieser Klasse unbekannt — die Saat
            // legt ihn beim nächsten Lauf an und schreibt die Klasse gleich mit.*
            $ohneOption[] = $behaelter . ' ' . $name;

            continue;
        }

        $node = $editor->find($id);

        if ($node === null) {
            $ohneOption[] = $behaelter . ' ' . $name . ' (Knoten ' . $id . ' gibt es nicht)';

            continue;
        }

        if ($node->implementedBy === $klasse) {
            $standSchon++;
        } else {
            echo ($trocken ? '  [trocken] ' : '  ') . $node->id . ' «' . $node->name . '» → ' . $klasse . "\n";

            if (! $trocken) {
                $editor->setImplementedBy($node->id, $klasse);
            }

            $geschrieben++;
        }

        // ⚠️ **Erst wenn die Angabe steht, fällt die Option** (TASK-009, Reihenfolge Wächter, Leser,
        // Daten). *Zwei Orte für dieselbe Auskunft sind die Doppelung, die `CLAUDE.md` verbietet —
        // und die Option war der Ort **ausserhalb** des Modells, gegen `AR-1`.*
        if (! $trocken && get_option($option, false) !== false) {
            delete_option($option);
            $gefallen++;
        }
    }
}

printf(
    "\n%d %s, %d standen schon, %d Optionen gefallen, %d ohne Option\n",
    $geschrieben,
    $trocken ? 'wären zu schreiben' : 'geschrieben',
    $standSchon,
    $gefallen,
    count($ohneOption)
);

foreach ($ohneOption as $was) {
    echo "  ohne Option: $was\n";
}
