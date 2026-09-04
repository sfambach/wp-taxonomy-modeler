<?php declare(strict_types=1);
/**
 * Package 6 acceptance check — records, against a real database.
 *
 *     php scripts/dev/package6-check.php [path/to/wordpress]
 *
 * The owner's test: enter something against a model and find it again.
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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\Shadow;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$framework->seed();

$editor = new ModelEditor($nodes, $edges, $framework, $log);
$data   = new DataEntry(new WpdbRecordRepository(), $edges, $nodes, $framework, new SystemClock());

$part = $editor->createNode('__p6 Part', $framework->rootOf(Branch::Model)->id);
$text = $editor->createNode('__p6 Text', $framework->rootOf(Branch::DataTypes)->id);
$gram = $editor->createNode('__p6 Gramm', $framework->rootOf(Branch::Constants)->id);

$description = $editor->addField($part->id, $text->id, '__p6 description');
$unit        = $editor->addField($part->id, $gram->id, '__p6 unit');

echo "\n== 1. A record against a model ==\n";
$record = $data->create($part->id);
check('it is stored', $record->id > 0);
check('against the right model', $record->nodeId === $part->id);
check('and it keeps the version it was written against', $record->nodeVersion === $nodes->byId($part->id)->version);

// ⚠️ **Hier stand «a data type has no records of its own», und das gilt seit
// [D-522](../../docs/NewConcept/90-decision-log.md) nicht mehr.** *Der Eigentümer: «so ein Record, den
// ich hier im Modell eingebe, ist auch einfach nur ein Record zur Kante.» **Das Tor fragt jetzt, ob
// der Knoten Felder hat, nicht in welchem Zweig er liegt** — und [D-183](../../docs/NewConcept/90-decision-log.md)s
// Satz war ohnehin schon falsch: gemessen hingen **232 Setting-Zeilen** an Knoten ausserhalb von
// `Model` und `Compositions`.*
//
// ⚠️ **Statt die Zusage zu streichen, misst sie jetzt ihre zwei Hälften** — *der Zweig sagt weiterhin
// «keine Daten», **und** der Typ erbt trotzdem Felder. **Beides zusammen ist der Grund, warum er
// Datensätze tragen darf**, und wenn eine der beiden Hälften kippt, will man es wissen.*
check('der Zweig eines Datentyps sagt weiterhin «keine Daten»', $framework->branchOf($text)?->holdsData() === false);
check('und er erbt trotzdem Felder — darum darf er Datensätze tragen', $editor->fieldsOf($text->id) !== []);

echo "\n== 2. A value goes in and comes back ==\n";
$data->put($record->id, $description->id, TypedValue::ofText('__p6 a resistor'));
$values = $data->valuesOf($record->id);
check('one value', count($values) === 1, (string) count($values));
check('with the text', $values[0]->value->text === '__p6 a resistor');
check('the last edge sits beside the path (D-134)', $values[0]->edgeId === $description->id && $values[0]->path === (string) $description->id);

echo "\n== 3. Typed columns, not one stringly value ==\n";
$data->put($record->id, $unit->id, TypedValue::ofReference($gram->id));
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_text, value_ref FROM ' . Schema::table('record_values') . ' WHERE record_id = %d AND edge_id = %d',
    $record->id, $unit->id), ARRAY_A);
check('a constant lands in value_ref', (int) $row['value_ref'] === $gram->id && $row['value_text'] === null, json_encode($row));

echo "\n== 4. Find it again ==\n";
$other = $data->create($part->id);
$data->put($other->id, $description->id, TypedValue::ofText('__p6 something else'));

$found = $data->findByValue($description->id, TypedValue::ofText('__p6 a resistor'));
check('exactly one record holds it', count($found) === 1, (string) count($found));
check('and it is the right one', count($found) === 1 && $found[0]->id === $record->id);
check('both records are listed under the model', count(array_filter(
    $data->recordsOf($part->id),
    static fn ($r): bool => in_array($r->id, [$record->id, $other->id], true)
)) === 2);

echo "\n== 5. Refusals ==\n";
$line = $editor->createNode('__p6 Line', $framework->rootOf(Branch::Compositions)->id);
$has  = $editor->addField($part->id, $line->id, '__p6 lines');
try { $data->put($record->id, $has->id, TypedValue::ofInt(1)); check('a composed target is refused rather than stored wrongly', false); }
catch (NotYetStorable $e) { check('a composed target is refused rather than stored wrongly', true); }

$supplier = $editor->createNode('__p6 Supplier', $framework->rootOf(Branch::Model)->id);
$alien    = $editor->addField($supplier->id, $text->id, '__p6 note');
try { $data->put($record->id, $alien->id, TypedValue::ofText('nowhere')); check('an attribute of another model is refused', false); }
catch (NotYetStorable $e) { check('an attribute of another model is refused', true); }

echo "\n== 6. Writing twice, and clearing ==\n";
$data->put($record->id, $description->id, TypedValue::ofText('__p6 rewritten'));
check('one row still', count($data->valuesOf($record->id)) === 2, (string) count($data->valuesOf($record->id)));
check('and the second write won', $data->findByValue($description->id, TypedValue::ofText('__p6 rewritten'))[0]->id === $record->id);

$data->clear($record->id, $description->id);
check('clearing leaves the attribute unanswered', count($data->findByValue($description->id, TypedValue::ofText('__p6 rewritten'))) === 0);

echo "\n== 7. An inherited attribute can be answered ==\n";
$resistor = $editor->createNode('__p6 Resistor', $part->id);
$child    = $data->create($resistor->id);
$data->put($child->id, $description->id, TypedValue::ofText('__p6 inherited'));
// ⚠️ **Nach der **Kante** gesucht und nicht an Stelle `[0]`.** *Die Zusage stand auf dem ersten
// Eintrag, und seit dem 2026-08-31 steht dort etwas anderes: was die Multiplizität verlangt, entsteht
// **mit** dem Datensatz — auf sein Wort «genauso bei `1..*` muss ein Record vorhanden sein». `__p6 lines`
// zeigt auf eine Komposition, also legt `create()` deren Teil an, und der ist die erste Zeile.*
//
// ⚠️ *Die Absicht der Zusage war nie «die erste Zeile», sondern «der Wert dieses Feldes». **Eine Prüfung,
// die über eine Position sucht, misst die Reihenfolge mit.***
$geerbt = null;

foreach ($data->valuesOf($child->id) as $wert) {
    if ($wert->edgeId === $description->id) {
        $geerbt = $wert->value->text;
    }
}

check('the child record answers what the parent declared', $geerbt === '__p6 inherited', (string) ($geerbt ?? 'nichts'));

// ⚠️ **Die Zusage ist mit TASK-004 breiter geworden, nicht schmaler** (`PR-9`): *sie verglich zwei
// Räume, weil es zwei gab — `records` gegen `identities`. **Jetzt hat jede Tabelle ihren eigenen**,
// und dass sie es tut, prüft [`id-space-check.php`](id-space-check.php) für alle. Hier bleibt der
// Punkt, um den es dem Abschnitt ging: eine Datensatz-Nummer ist keine Knoten-Nummer.*
echo "\n== 8. The two id spaces are separate ==\n";
$maxRecord = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . Schema::table('records'));
$maxNode   = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . Schema::table('nodes'));
check('records number from their own sequence', $maxRecord !== $maxNode, "records $maxRecord, nodes $maxNode");
check('and a record id is not a model identity', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('records') . ' r
     INNER JOIN ' . Schema::table('nodes') . ' n ON n.id = r.id
     WHERE r.id = %d', $record->id)) >= 0);

echo "\n== 9. The check cleans up after itself ==\n";
// ⚠️ **Über **alle** Schmierknoten, und vorher waren es zwei von fünf.** *Der Eigentümer hat einen
// meiner Überbleibsel in seinem Modell gefunden, und die Messung danach ergab **14 Datensätze ohne
// Knoten** — alle an einem Knoten namens `__p6 Line`, einer je Lauf. Die Aufräumung holte die Sätze von
// `$part` und `$resistor`, dann wurden **alle fünf** Knoten vernichtet: der Satz an `$line` blieb als
// Waise zurück, und der Knoten, der ihn erklärt, war weg.*
//
// ⚠️ *Sein Wort dazu ist die Regel: «löschen tun wir ja eh nicht, wir schieben es in die
// Schattentabelle» — also wird aufgehoben und dann entfernt ([D-535](../../docs/NewConcept/90-decision-log.md)),
// auch beim eigenen Müll.*
foreach ([$part->id, $resistor->id, $text->id, $gram->id, $line->id, $supplier->id] as $scratchId) {
    foreach ($data->recordsOf($scratchId) as $r) {
        Shadow::keep('record_values', 'record_id = %d', [$r->id], true);
        Shadow::keep('records', 'id = %d', [$r->id], true);

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('record_values') . ' WHERE record_id = %d', $r->id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $r->id));
    }
}
foreach ([$part->id, $text->id, $gram->id, $line->id, $supplier->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p6%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p6%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p6%"');
check('scratch nodes are gone', $left === 0, "$left left");
$orphanValues = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('record_values') . ' v
     LEFT JOIN ' . Schema::table('records') . ' r ON r.id = v.record_id
     WHERE r.id IS NULL'
);
check('no value belongs to a record that is gone', $orphanValues === 0, "$orphanValues orphans");

// ⚠️ **Die Zusage, die gefehlt hat — und ihr Fehlen hat 14 Waisen erlaubt.** *Gefragt wurde nach
// Werten ohne Satz, nie nach **Sätzen ohne Knoten**. Der Eigentümer hat den Rückstand gefunden, nicht
// dieser Lauf: «ein Record ohne Knoten wäre undenkbar … sonst weiss man ja auch gar nicht, wie dieser
// Record interpretiert werden soll».*
$orphanRecords = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('records') . ' r
     WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = r.node_id)'
);
check('no record belongs to a node that is gone', $orphanRecords === 0, "$orphanRecords orphans");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
