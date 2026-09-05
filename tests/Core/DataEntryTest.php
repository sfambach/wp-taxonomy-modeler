<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\EdgeRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedClock;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRecords;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Entering something against a model and finding it again.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DataEntryTest extends TestCase
{
    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private InMemoryRecords $records;
    private ModelEditor $editor;
    private DataEntry $data;
    /** @var array<string,Node> */
    private array $branchRoot = [];
    private Node $part;
    private Node $text;
    private Node $gram;
    private Relation $description;

    protected function setUp(): void
    {
        $this->edges   = new InMemoryRelations();
        $this->nodes   = new InMemoryNodes($this->edges);
        $this->records = new InMemoryRecords();
        $identities    = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities): Node {
            $node = Node::create($identities->next(), $name, $parent?->path);
            $this->nodes->add($node);

            if ($parent !== null) {
                $this->edges->add(Relation::inheritance(
                    $identities->next(),
                    $parent->id,
                    $node->id,
                    $this->edges->nextPositionUnder($parent->id)
                ));
            }

            return $node;
        };

        $root  = $make('Root', null);
        $trash = $make('Trash', $root);

        $this->branchRoot['model']        = $make('Model', $root);
        $this->branchRoot['compositions'] = $make('Compositions', $root);

        $primitives = $make('Primitives', $root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($root, $trash, $this->branchRoot);

        // ⚠️ *Das Record-Repository geht mit hinein, weil `clearTrash()` es braucht, um die Daten
        // mitzunehmen ([C102](../../docs/NewConcept/10-domain-core.md)). **Ohne es überlebte ein
        // Record seinen Knoten** — und der Docblock der Methode behauptete das Gegenteil.*
        $this->editor = new ModelEditor(
            $this->nodes,
            $this->edges,
            $framework,
            new RecordedChanges(),
            records: $this->records
        );
        $this->data   = new DataEntry($this->records, $this->edges, $this->nodes, $framework, new FixedClock());

        $this->part = $this->editor->createNode('Part', $this->branchRoot['model']->id);
        $this->text = $this->editor->createNode('Text', $this->branchRoot['data-types']->id);
        $this->gram = $this->editor->createNode('Gramm', $this->branchRoot['constants']->id);

        $this->description = $this->editor->addField($this->part->id, $this->text->id, 'description');
    }

    #[Test]
    public function a_record_is_entered_against_a_model_node(): void
    {
        $record = $this->data->create($this->part->id);

        self::assertSame($this->part->id, $record->nodeId);
        self::assertGreaterThan(0, $record->id);
    }

    #[Test]
    public function it_keeps_the_model_version_it_was_written_against(): void
    {
        // D-060, D-210: *written against*, not *checked against*. Records at several versions
        // are a normal steady state.
        $before = $this->nodes->byId($this->part->id)->version;
        $record = $this->data->create($this->part->id);

        $this->editor->rename($this->part->id, 'Bauteil');

        self::assertSame($before, $this->data->find($record->id)->nodeVersion);
        self::assertGreaterThan($before, $this->nodes->byId($this->part->id)->version);
    }

    #[Test]
    public function a_data_type_has_no_records_of_its_own(): void
    {
        // D-183: only branches with instances have them. A `Text` node is not a thing somebody
        // owns three of.
        $this->expectException(NotYetStorable::class);

        $this->data->create($this->text->id);
    }

    #[Test]
    public function a_value_goes_in_and_comes_back(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('a resistor'));

        $values = $this->data->valuesOf($record->id);

        self::assertCount(1, $values);
        self::assertSame('a resistor', $values[0]->value->text);
    }

    #[Test]
    public function the_last_edge_is_kept_beside_the_path(): void
    {
        // ⚠️ D-134, and it is what makes the data searchable: the edge is indexed, the path
        // narrows.
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('x'));

        $value = $this->data->valuesOf($record->id)[0];

        self::assertSame($this->description->id, $value->edgeId);
        self::assertSame((string) $this->description->id, $value->path);
    }

    #[Test]
    public function a_constant_is_stored_as_a_reference_to_a_node(): void
    {
        $unit   = $this->editor->addField($this->part->id, $this->gram->id, 'unit');
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $unit->id, TypedValue::ofReference($this->gram->id));

        $value = $this->data->valuesOf($record->id)[0];

        self::assertSame($this->gram->id, $value->value->reference);
        self::assertNull($value->value->text);
    }

    #[Test]
    public function a_composed_target_is_refused_rather_than_stored_in_the_wrong_place(): void
    {
        // ⚠️ Unfinished work, said plainly. Storing it inline would look right until somebody
        // tried to share it.
        $line = $this->editor->createNode('Order line', $this->branchRoot['compositions']->id);
        $has  = $this->editor->addField($this->part->id, $line->id, 'lines');

        $record = $this->data->create($this->part->id);

        $this->expectException(NotYetStorable::class);

        $this->data->put($record->id, $has->id, TypedValue::ofInt(1));
    }

    #[Test]
    public function a_value_can_be_written_against_an_inherited_attribute(): void
    {
        // The tree *is* inheritance, so a child's record answers what the parent declared.
        $child  = $this->editor->createNode('Resistor', $this->part->id);
        $record = $this->data->create($child->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('inherited attribute'));

        self::assertSame('inherited attribute', $this->data->valuesOf($record->id)[0]->value->text);
    }

    #[Test]
    public function an_attribute_of_a_different_model_is_refused(): void
    {
        $other = $this->editor->createNode('Supplier', $this->branchRoot['model']->id);
        $alien = $this->editor->addField($other->id, $this->text->id, 'note');

        $record = $this->data->create($this->part->id);

        $this->expectException(NotYetStorable::class);

        $this->data->put($record->id, $alien->id, TypedValue::ofText('nowhere'));
    }

    #[Test]
    public function a_second_write_replaces_the_first(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('first'));
        $this->data->put($record->id, $this->description->id, TypedValue::ofText('second'));

        $values = $this->data->valuesOf($record->id);

        self::assertCount(1, $values);
        self::assertSame('second', $values[0]->value->text);
    }

    #[Test]
    public function clearing_leaves_the_attribute_unanswered(): void
    {
        // ⚠️ A missing row means *not answered*, never *no* — three states at `0..1`, and
        // collapsing the last two loses them for good.
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('something'));
        $this->data->clear($record->id, $this->description->id);

        self::assertSame([], $this->data->valuesOf($record->id));
    }

    #[Test]
    public function the_records_of_a_model_can_be_listed(): void
    {
        $this->data->create($this->part->id);
        $this->data->create($this->part->id);
        $this->data->create($this->editor->createNode('Supplier', $this->branchRoot['model']->id)->id);

        self::assertCount(2, $this->data->recordsOf($this->part->id));
    }

    #[Test]
    public function a_record_is_found_again_by_what_it_holds(): void
    {
        // The owner's own check for this package.
        $wanted = $this->data->create($this->part->id);
        $other  = $this->data->create($this->part->id);

        $this->data->put($wanted->id, $this->description->id, TypedValue::ofText('4k7'));
        $this->data->put($other->id, $this->description->id, TypedValue::ofText('10k'));

        $found = $this->data->findByValue($this->description->id, TypedValue::ofText('4k7'));

        self::assertCount(1, $found);
        self::assertSame($wanted->id, $found[0]->id);
    }

    #[Test]
    public function record_ids_are_their_own_space_and_may_collide_with_node_ids(): void
    {
        // D-164: the two halves do not share a number space, and nothing goes wrong when the
        // numbers happen to be the same — the branch decides what a reference resolves to.
        $record = $this->data->create($this->part->id);

        self::assertNotNull($this->nodes->find($record->id) ?? $this->data->find($record->id));
        self::assertSame($record->id, $this->data->find($record->id)->id);
    }

    /**
     * ⚠️ **[D-232](../../docs/NewConcept/90-decision-log.md): Mehrfachheit spielt für die Speicherung
     * keine Rolle** — fünf Ganzzahlen sind fünf **Zeilen** in einem Datensatz, nicht fünf Datensätze.
     *
     * ⚠️ *Und sie sind **Zeilen**, nicht Pfade ([D-530](../../docs/NewConcept/90-decision-log.md)):
     * alle drei tragen denselben Pfad, weil sie sich eine Kante teilen. **Was sie trennt, ist die Id,
     * die jede Zeile immer schon hatte** — der Eigentümer: «warum führen wir jetzt eine neue Zahl ein,
     * wo wir doch die Id des Records haben?»*
     */
    #[Test]
    public function several_values_of_one_attribute_are_several_rows_in_one_record(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->appendValue($record->id, $this->description->id, TypedValue::ofText('erster'));
        $this->data->appendValue($record->id, $this->description->id, TypedValue::ofText('zweiter'));

        $werte = $this->data->valuesOf($record->id);

        self::assertCount(2, $werte);
        self::assertCount(1, $this->data->recordsOf($this->part->id), 'und trotzdem nur ein Datensatz');

        self::assertSame(
            $werte[0]->path,
            $werte[1]->path,
            'beide teilen sich eine Kante, also auch einen Pfad'
        );

        self::assertNotSame($werte[0]->id, $werte[1]->id, 'die Zeilen-Id trennt sie');
        self::assertLessThan($werte[1]->position, $werte[0]->position, 'und position ordnet sie');
    }

    /**
     * ⚠️ **«Setze den Wert» kann ein Feld mit mehreren Werten nicht beantworten.**
     * *Vorher verhinderte der eindeutige Schlüssel den Fall; seit [D-530](../../docs/NewConcept/90-decision-log.md)
     * stehen mehrere Zeilen nebeneinander, und eine davon zu raten wäre in der Hälfte der Fälle
     * die falsche.*
     */
    #[Test]
    public function setting_the_value_of_a_field_that_holds_several_is_refused(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->appendValue($record->id, $this->description->id, TypedValue::ofText('erster'));
        $this->data->appendValue($record->id, $this->description->id, TypedValue::ofText('zweiter'));

        $this->expectException(\Taxmod\Core\Exception\NotYetStorable::class);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('welcher denn?'));
    }

    // ------------------------------------- ein Record ohne seinen Knoten darf es nicht geben

    /**
     * ⚠️ **[C102](../../docs/NewConcept/10-domain-core.md) durchgesetzt, und der Eigentümer hat die
     * Regel gestellt:** *«solange noch eine Referenz da ist, kann ein Knoten nicht endgültig gelöscht
     * werden. Somit wäre ein Record ohne Knoten undenkbar. Und wenn man ihn löschen will und das Risiko
     * eingeht, dann müssen die Daten mitgelöscht werden … **sonst weiss man ja auch gar nicht, wie
     * dieser Record interpretiert werden soll.**»*
     *
     * ⚠️ *Der Docblock von `clearTrash()` behauptete das seit dem Anfang — «settings, labels, **records**
     * und edges» — und die Methode löschte vier von sechs. **Geschrieben und nicht gebaut.***
     */
    #[Test]
    public function clearing_the_trash_takes_a_nodes_records_with_it(): void
    {
        $record = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('4k7'));

        self::assertCount(1, $this->data->recordsOf($this->part->id));
        self::assertCount(1, $this->data->valuesOf($record->id));

        $this->editor->moveToTrash($this->part->id);

        $gone = $this->editor->clearTrash();

        // ⚠️ *Der Akt sagt auch, was ging — eine Oberfläche muss «und 1 Datensatz» nennen können.*
        self::assertSame(1, $gone['records']);
        self::assertSame(1, $gone['values']);

        self::assertSame([], $this->data->recordsOf($this->part->id));
        self::assertSame([], $this->data->valuesOf($record->id));
    }

    #[Test]
    public function a_record_of_an_untouched_node_survives_the_clearing(): void
    {
        // ⚠️ *Die Gegenprobe, ohne die die obige Zusage auch von einem `DELETE FROM records` erfüllt
        // wäre.*
        $other       = $this->editor->createNode('Widerstand', $this->branchRoot['model']->id);
        $otherRecord = $this->data->create($other->id);

        $this->data->create($this->part->id);
        $this->editor->moveToTrash($this->part->id);
        $this->editor->clearTrash();

        self::assertCount(1, $this->data->recordsOf($other->id));
        self::assertNotNull($this->records->find($otherRecord->id));
    }

    /**
     * ⚠️ **Der Akt raeumt auch nur eine Auswahl** (TASK-039). *Der Grund ist gemessener Schaden: ein
     * Waechterlauf leerte den ganzen Papierkorb und nahm mit, was der Eigentuemer selbst geparkt hatte.
     * **Wer nur seinen eigenen Unrat wegraeumen will, muss das sagen koennen.***
     */
    #[Test]
    public function it_clears_only_the_parked_nodes_it_was_given(): void
    {
        $seiner = $this->editor->createNode('Sein Geparktes', $this->branchRoot['model']->id);

        $this->editor->moveToTrash($seiner->id);
        $this->editor->moveToTrash($this->part->id);

        $gone = $this->editor->clearTrash([$this->part->id]);

        self::assertSame(1, $gone['nodes']);
        self::assertNull($this->nodes->find($this->part->id));
        self::assertNotNull($this->nodes->find($seiner->id));
    }

    /**
     * ⚠️ **[D-609](../../docs/NewConcept/90-decision-log.md), und es ist BUG-004:** *Loeschen legte an.
     * Der Eigentuemer: «ein Datensatz entsteht beim ersten Schreiben, nicht beim Ansehen? ja bitte.»*
     */
    #[Test]
    public function clearing_a_setting_that_was_never_set_creates_no_record(): void
    {
        $this->data->clearSettingAt($this->part->id, $this->description->id, 0);

        self::assertSame([], $this->data->recordsOf($this->part->id));
    }

    /** ⚠️ *Die Gegenprobe: das erste **Schreiben** legt den Satz sehr wohl an.* */
    #[Test]
    public function the_first_write_does_create_the_record(): void
    {
        $this->data->putSettingAt($this->part->id, $this->description->id, 0, TypedValue::ofText('kompakt'));

        self::assertCount(1, $this->data->recordsOf($this->part->id));
    }
    // ------------------------------------- ein Wert an einer Verwendungsstelle

    /**
     * ⚠️ **Die Adresse, die der Speicher seit Paket 1 kann und die niemand benutzt hat.**
     * *Gemessen am 2026-08-30 trug **kein einziger** Pfad in `record_values` einen Punkt — während 21
     * Zeilen der alten Settings-Tabelle genau diese Form längst benutzten.*
     *
     * ⚠️ **Der Eigentümer hat den Umweg abgeschnitten, den ich bauen wollte:** *«wir haben alle
     * Mittel, einer Kanten-Knoten-Kombination in jeglicher Schachtelung Daten zuzuweisen — **warum
     * brauche ich hier ein zusätzliches Mittel?**» Es ist kein Datensatz an der Kante, sondern ein
     * Wert im Datensatz des Besitzers, adressiert über die Kette.*
     */
    #[Test]
    public function a_value_may_sit_at_a_use_site(): void
    {
        $renderer = $this->editor->addField($this->text->id, $this->gram->id, 'renderer');
        $record   = $this->data->create($this->part->id);

        $this->data->putAt($record->id, [$this->description->id, $renderer->id], TypedValue::ofText('kompakt'));

        $werte = $this->data->valuesAt($record->id, [$this->description->id, $renderer->id]);

        self::assertCount(1, $werte);
        self::assertSame('kompakt', $werte[0]->value->text);
        self::assertSame($this->description->id . '.' . $renderer->id, $werte[0]->path);

        // ⚠️ *Die **letzte** Stufe steht in `edge_id`, damit «alle Renderer, wo auch immer sie sitzen»
        // ein indizierter Zugriff bleibt (D-134).*
        self::assertSame($renderer->id, $werte[0]->edgeId);
    }

    /**
     * ⚠️ **Der eigentliche Wert der Kette ist die Prüfung, nicht das Zusammensetzen.** *Eine Adresse
     * «Feld A, darin Feld B» ist nur etwas wert, wenn B wirklich ein Feld des Zieles von A ist.
     * **Ohne die Prüfung könnte man an jede erfundene Stelle schreiben**, und es fiele erst auf, wenn
     * jemand dort etwas sucht.*
     */
    #[Test]
    public function an_invented_second_step_is_refused(): void
    {
        $record = $this->data->create($this->part->id);

        $this->expectException(NotYetStorable::class);

        // ⚠️ *Auf die **Begründung** geprüft und nicht nur auf die Ausnahme: eine Gegenprobe zeigte, dass
        // der Test auch grün blieb, als die Kettenprüfung durch eine **andere** Verweigerung ersetzt
        // wurde. **Eine Zusage, die jede Ausnahme annimmt, prüft die Regel nicht.***
        $this->expectExceptionMessage($this->nodes->byId($this->text->id)->name);

        // `description` ist ein Feld von `Part`, aber nicht von `Text`.
        $this->data->putAt($record->id, [$this->description->id, $this->description->id], TypedValue::ofText('nirgends'));
    }

    /**
     * ⚠️ *Der Wert **des Feldes** und der Wert **an der Verwendungsstelle** sind zwei Stellen und
     * überschreiben einander nicht — sie unterscheiden sich im Pfad, nicht in der Kante.*
     */
    #[Test]
    public function the_field_and_its_use_site_are_two_places(): void
    {
        $renderer = $this->editor->addField($this->text->id, $this->gram->id, 'renderer');
        $record   = $this->data->create($this->part->id);

        $this->data->put($record->id, $this->description->id, TypedValue::ofText('4k7'));
        $this->data->putAt($record->id, [$this->description->id, $renderer->id], TypedValue::ofText('kompakt'));

        self::assertCount(2, $this->data->valuesOf($record->id));
        self::assertSame('4k7', $this->data->valuesAt($record->id, [$this->description->id])[0]->value->text);
        self::assertSame('kompakt', $this->data->valuesAt($record->id, [$this->description->id, $renderer->id])[0]->value->text);
    }

    /**
     * Die Wahl eines Einstellungsdatensatzes steht in der **Spalte** und nicht an einer Kante.
     *
     * ⚠️ **Das ist [D-584](../../docs/NewConcept/90-decision-log.md), und es ist die Zusage von
     * TASK-020:** *«bei genau einem Renderer ist ein einzelner Zeiger auf einen einzelnen Datensatz
     * genau richtig, und dessen `node_id` sagt schon, welcher Renderer es ist».*
     *
     * ⚠️ **Warum es überhaupt eine Zusage braucht:** *solange der Halter eine Wertzeile an einer
     * Trägerkante war, hing er von dieser Kante ab — und als der Eigentümer den Hüllknoten löschte,
     * standen **29 Renderer-Wahlen an einer Kante, die es nicht mehr gab**
     * ([D-604](../../docs/NewConcept/90-decision-log.md)). Eine Spalte kann das nicht.*
     */
    #[Test]
    public function a_chosen_setting_record_lives_in_the_column(): void
    {
        // ⚠️ *Zwei waehlbare Knoten, und beide muessen eigene Datensaetze haben koennen — der
        // Einstellungssatz ist ein Satz des **gewaehlten** Knotens (D-583).*
        $compact = $this->editor->createNode('compact', $this->branchRoot['model']->id);
        $table   = $this->editor->createNode('table', $this->branchRoot['model']->id);

        $this->data->chooseSettingRecordAtNode($this->gram->id, $compact->id);

        $satzId = $this->nodes->settingsRecordIdsOf([$this->gram->id])[$this->gram->id] ?? 0;

        self::assertNotSame(0, $satzId, 'der Zeiger steht in der Spalte');
        self::assertSame($compact->id, $this->records->find($satzId)?->nodeId, 'und der Satz ist einer des gewaehlten Knotens');

        // Dieselbe Wahl noch einmal legt nichts Zweites an — die Zeile *ist* der Datensatz (D-583).
        $this->data->chooseSettingRecordAtNode($this->gram->id, $compact->id);

        self::assertSame($satzId, $this->nodes->settingsRecordIdsOf([$this->gram->id])[$this->gram->id] ?? 0);

        // Eine andere Wahl haengt um, statt einen zweiten Halter danebenzustellen.
        $this->data->chooseSettingRecordAtNode($this->gram->id, $table->id);

        $neu = $this->nodes->settingsRecordIdsOf([$this->gram->id])[$this->gram->id] ?? 0;

        self::assertNotSame($satzId, $neu);
        self::assertSame($table->id, $this->records->find($neu)?->nodeId);
    }
}