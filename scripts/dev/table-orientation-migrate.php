<?php declare(strict_types=1);

/**
 * TASK-063: der Knoten `table` bekommt seine Einstellungskante `orientation` — **eine Kante**.
 *
 * ⚠️ **Der Umschalter existierte schon und wurde nicht gebaut.** *Der Knoten `Orientation` mit den
 * Kindern `horizontal` und `vertical` steht im Modell, und `compact` haengt daran
 * (`compact --orientation--> Orientation`, kind `setting`, gemessen am 2026-09-07). Fuer `table` ist
 * es dieselbe Kante ein zweites Mal — kein neuer Mechanismus, und Vorschau, Vererbung und
 * Einstellungstafel koennen es sofort.*
 *
 * ⚠️ **Ueber den Kern und nicht mit rohem SQL** ({@see ModelEditor::addField()}): *eine Kante
 * anzulegen ist ein Akt mit einer Aenderungsgruppe, und ein `INSERT` haette den Bestand um eine Zeile
 * ohne Geschichte vermehrt.*
 *
 * ⚠️ **Danach wird der Abzug nachgezogen** — `php scripts/dev/saat-export.php` erzeugt
 * `data/saat.json` neu ([D-600](../../docs/NewConcept/90-decision-log.md), TASK-064). *Sonst hat eine
 * frische Installation die Kante nicht, und der Umschalter waere nur in **seinem** Bestand da.*
 *
 * ⚠️ *Er laeuft zweimal ohne Schaden: findet er die Kante schon, tut er nichts.*
 *
 *     php scripts/dev/table-orientation-migrate.php            (nur nachsehen)
 *     php scripts/dev/table-orientation-migrate.php --go       (schreiben)
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null || str_starts_with((string) $root, '--')) {
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

use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Renderer\CompactRenderer;
use Taxmod\Core\Renderer\Orientation;
use Taxmod\Core\Renderer\TableRenderer;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor(
    $nodes,
    $relations,
    $framework,
    $log,
    new WpdbLabelRepository(),
    new WpdbRecordRepository()
);

// ⚠️ **Die beiden Renderer-Knoten werden an `implemented_by` gesucht und nicht am Namen.** *Genau am
// Namen sind die vier Geruest-Laeufe krank (TASK-064): sie suchen am Wort und legen an, was sie nicht
// finden. Die Klasse ueberlebt eine Umbenennung.*
$gefunden = $nodes->byImplementations([TableRenderer::class, CompactRenderer::class]);
$table    = $gefunden[TableRenderer::class] ?? null;
$compact  = $gefunden[CompactRenderer::class] ?? null;

if ($table === null || $compact === null) {
    fwrite(STDERR, "Renderer-Knoten fehlen: table=" . var_export($table !== null, true)
        . ", compact=" . var_export($compact !== null, true) . "\n");
    exit(1);
}

$kanten = $relations->fieldRelationsOf([$table->id, $compact->id]);

// ⚠️ *Das Ziel wird von der Kante geholt, die es schon gibt — `compact --orientation--> …`. **So
// kann dieses Skript den Zielknoten nicht verwechseln**, und es scheitert laut, wenn der Umschalter
// wider Erwarten fehlt, statt einen zweiten `Orientation` anzulegen.*
$ziel = null;

foreach ($kanten as $relation) {
    if ($relation->name !== Orientation::KEY || $relation->kind !== RelationKind::Setting) {
        continue;
    }

    if ($relation->fromNodeId === $table->id) {
        echo "Die Kante steht schon: table --" . Orientation::KEY
            . "--> {$relation->toNodeId} (Kante {$relation->id}).\n";
        exit(0);
    }

    $ziel = $relation->toNodeId;
}

if ($ziel === null) {
    fwrite(STDERR, "Keine vorhandene Einstellungskante '" . Orientation::KEY . "' an compact — der Umschalter fehlt im Modell.\n");
    exit(1);
}

echo "Knoten table = {$table->id}, Ziel Orientation = {$ziel}.\n";

if (! $go) {
    echo "Nur nachgesehen. Mit --go schreiben.\n";
    exit(0);
}

$neu = $editor->addField($table->id, $ziel, Orientation::KEY, RelationKind::Setting);

echo "Angelegt: Kante {$neu->id} — table --{$neu->name}--> {$ziel} ({$neu->kind->value}).\n";
echo "Jetzt den Abzug nachziehen: php scripts/dev/saat-export.php\n";
