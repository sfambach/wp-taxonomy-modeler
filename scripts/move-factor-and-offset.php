<?php declare(strict_types=1);

/**
 * `factor` und `offset` aus der alten `settings`-Tabelle in Kanten und Datensätze — wie `exponent`.
 *
 *     php scripts/move-factor-and-offset.php [path/to/wordpress]
 *
 * ⚠️ **Auf seine Entscheidung, wörtlich:** *«Faktor und Offset einfach wie Exponent behandeln.»* Damit
 * ist [Zeile 32](../docs/NewConcept/97-implementation-plan.md#the-working-list) beantwortet.
 *
 * ⚠️ **Das Vorbild ist gemessen und wird nicht nachempfunden.** *`exponent` ist eine **Einstellungskante
 * an der Gruppe** — `#4654` von `Prefixes` auf `Integer` — und der Wert steht im `default`-Satz des
 * **einzelnen** Knotens, an der Adresse der Kante: `yotta` hat `Pfad=4654, int=24`. Genau diese Form
 * bekommen `factor` und `offset` an `Base units`.*
 *
 * ⚠️ **`0..1` und nicht `1..1`.** *`exponent` ist `1..1`, weil **jedes** Präfix einen hat. Von den
 * Einheiten hat heute nur `Celsius` einen Faktor; `1..1` würde nach [D-549](../docs/NewConcept/90-decision-log.md)
 * einen Wert an jeder Einheit verlangen und die Seite sperren. **Eine Multiplizität, die den Bestand
 * verletzt, ist keine Regel, sondern ein Fehler mit Anspruch.***
 *
 * ⚠️ **Umzug, nicht Löschung.** *Erst wird geschrieben, dann **gelesen und geprüft**, und nur wenn der
 * neue Weg antwortet, verschwindet die alte Zeile. Die `settings`-Tabelle hat keinen Schatten — also
 * ist die Reihenfolge der ganze Schutz.*
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

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
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$records   = new WpdbRecordRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);
$data      = new DataEntry($records, $edges, $nodes, $framework, new SystemClock());
$model     = new ModelValues($records, $edges, $nodes, $framework);
$typen     = new SeededTypeNodes($nodes, $framework);

$fehler = 0;

function sagen(string $was, bool $gut, string $dazu = ''): void
{
    global $fehler;

    if (! $gut) {
        ++$fehler;
    }

    echo ($gut ? '  ok   ' : '  FEHLER '), $was, ($dazu === '' ? '' : "  — {$dazu}"), "\n";
}

// ── Die Gruppe und der Typ ──────────────────────────────────────────────────────────────────────
//
// ⚠️ *Über die **Id** der Saat und nicht über einen Namen — `Base units` ist ein Modellname und darf
// sich ändern ([D-022](../docs/NewConcept/90-decision-log.md)).*
$dezimalId = $typen->nodeId(SimpleType::Decimal);

sagen('der Typ «decimal» ist notiert', $dezimalId !== null, (string) $dezimalId);

// ⚠️ *Die Gruppe wird über die Zeilen gefunden, die umziehen: der Eigentümer der alten Zeile ist der
// Knoten, und die Gruppe ist sein **Elternknoten** — dieselbe Anordnung wie `Prefixes` zu `yolta`.*
$besitzer = $wpdb->get_col("SELECT DISTINCT owner_id FROM " . Schema::table('settings') . " WHERE setting_key IN ('factor','offset')");

if ($besitzer === null) {
    fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
    exit(2);
}

if ($besitzer === []) {
    echo "Nichts zu tun: keine factor/offset-Zeilen mehr.\n";
    exit(0);
}

$gruppen = [];

foreach ($besitzer as $rohId) {
    $knoten = $nodes->find((int) $rohId);

    if ($knoten === null) {
        sagen('Besitzer #' . (int) $rohId . ' ist ein Knoten', false, 'gibt es nicht');

        continue;
    }

    $elternId = $knoten->parentId();

    if ($elternId === null) {
        sagen('«' . $knoten->name . '» hat einen Elternknoten', false);

        continue;
    }

    $gruppen[$elternId] = true;
}

sagen('genau eine Gruppe betroffen', count($gruppen) === 1, implode(', ', array_keys($gruppen)));

if ($fehler > 0 || $dezimalId === null) {
    echo "\nAbgebrochen, nichts geändert.\n";
    exit(1);
}

$gruppeId = (int) array_key_first($gruppen);
$gruppe   = $nodes->byId($gruppeId);

echo "\nGruppe: «{$gruppe->name}» #{$gruppe->id}\n\n";

// ── Die zwei Kanten ─────────────────────────────────────────────────────────────────────────────

$kanten = [];

foreach (['factor', 'offset'] as $name) {
    $gefunden = null;

    foreach ($editor->fieldsOf($gruppe->id) as $eine) {
        if ($eine->name === $name) {
            $gefunden = $eine;
        }
    }

    if ($gefunden === null) {
        // ⚠️ *Anlegen und dann **markieren**: `addField()` liest die Art vom Ast ab, und
        // `markAsSetting()` ist die einzige Stelle, die sie auf `setting` setzt
        // ([D-526](../docs/NewConcept/90-decision-log.md) — die Marke sitzt an der Kante, nicht am Ziel).*
        $gefunden = $editor->addField($gruppe->id, $dezimalId, $name);
        $gefunden = $editor->markAsSetting($gruppe->id, $gefunden->id, true);
        echo "  angelegt: Einstellungskante «{$name}» #{$gefunden->id}\n";
    } else {
        echo "  vorhanden: Einstellungskante «{$name}» #{$gefunden->id}\n";
    }

    if (! $gefunden->multiplicity->allowsMany() && $gefunden->multiplicity->requiresOne()) {
        // ⚠️ *`0..1`: heute hat nur eine Einheit einen Faktor, und `1..1` würde nach D-549 einen Wert
        // an jeder verlangen.*
        $gefunden = $editor->setMultiplicity($gruppe->id, $gefunden->id, Multiplicity::ZeroToOne);
        echo "    auf «0..1» gestellt\n";
    }

    $kanten[$name] = $gefunden;
}

// ── Die Werte ───────────────────────────────────────────────────────────────────────────────────

echo "\n";

$zeilen = $wpdb->get_results(
    "SELECT owner_id, setting_key, value_decimal FROM " . Schema::table('settings') . " WHERE setting_key IN ('factor','offset')",
    ARRAY_A
);

if ($zeilen === null) {
    fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
    exit(2);
}

$umgezogen = 0;

foreach ($zeilen as $z) {
    $knotenId = (int) $z['owner_id'];
    $schluessel = (string) $z['setting_key'];
    $roh      = $z['value_decimal'];

    if ($roh === null || $roh === '') {
        sagen("«{$schluessel}» an #{$knotenId} trägt einen Wert", false, 'leer');

        continue;
    }

    $kante = $kanten[$schluessel];

    // ⚠️ *Über den gewöhnlichen Weg, nicht mit rohem SQL: `putSettingAt()` legt den `default`-Satz an,
    // wenn es keinen gibt, und schreibt an derselben Adresse, die `exponent` benutzt.*
    $data->putSettingAt($knotenId, $kante->id, 0, TypedValue::ofDecimal((string) $roh));

    ++$umgezogen;

    echo "  geschrieben: «{$schluessel}» = {$roh} an Knoten #{$knotenId}, Pfad {$kante->id}\n";
}

// ── Gelesen, bevor die alte Zeile geht ──────────────────────────────────────────────────────────

echo "\n";

$gelesen = 0;

foreach ($zeilen as $z) {
    $knoten = $nodes->find((int) $z['owner_id']);

    if ($knoten === null) {
        continue;
    }

    $ausDemModell = $model->forNode($knoten);
    $schluessel   = (string) $z['setting_key'];

    $da = isset($ausDemModell[$schluessel]);

    sagen(
        "«{$schluessel}» an «{$knoten->name}» kommt über den neuen Weg zurück",
        $da,
        $da ? $ausDemModell[$schluessel]->value->describe() : 'nichts'
    );

    if ($da) {
        ++$gelesen;
    }
}

if ($fehler > 0) {
    echo "\nDie alten Zeilen bleiben stehen, weil der neue Weg nicht antwortet.\n";
    exit(1);
}

$wpdb->query("DELETE FROM " . Schema::table('settings') . " WHERE setting_key IN ('factor','offset')");

$rest = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('settings'));

echo "\n{$umgezogen} umgezogen, {$gelesen} gelesen, {$rest} Zeilen bleiben in der alten Tabelle.\n";

exit(0);
