<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\ModelEditor;

/**
 * The composed types the concept names as **its own test** — an address, a dimension, a recipe.
 *
 * ⚠️ **These three are not decoration; they are the measurement [C116](../../../docs/NewConcept/10-domain-core.md#c116--the-composed-type-is-the-unit-of-rendering)
 * asks for.** It claims of the generic composite renderer: *a new composed type works immediately:
 * dimensions, addresses, baking recipes.* The owner asked for exactly those — *could you put the
 * composed types we described yesterday into the tree, address and such* — and a claim of the form
 * *works immediately* is only worth anything once something has been made to work immediately.
 *
 * ⚠️ **They are deliberately graded, because one example can only fail in one way.**
 * {@see UnitScaffold} built the first composed type ([D-375](../../../docs/NewConcept/90-decision-log.md));
 * these add the three rungs above it.
 *
 * ```mermaid
 * flowchart TD
 *   A["Adresse · flat · five simple members"] --> D["Dimension · nested · members are composed"]
 *   D --> R["Backrezept · a collection of composed values · 1..*"]
 * ```
 *
 * | Rung | What it puts under test |
 * |---|---|
 * | **`Adresse`** | a composed type of **simple members only** — the plain case, and the one that must not need code |
 * | **`Dimension`** | members that are **themselves composed** (`Einheitenwert`), so the renderer has to descend into a descent |
 * | **`Backrezept`** | a **collection** of composed values (`zutat` `1..*` → `Zutat`), which is the rung nothing has ever drawn |
 *
 * ⚠️ **`Adresse` is where the model earns its keep, and it is the `plz` that shows it.** A postcode
 * and a house number are **`text`**, not `int` — `01067` loses its leading zero the moment it is a
 * number, and `12a` was never one. *Neither is arithmetic, and a type is what a thing **is**, not
 * what its characters look like.*
 *
 * ⚠️ **What is knowingly not answered here: the members.** The concept names the three **types** and
 * never their fields, so every member below is an assumption of this file and `PR-4` forbids
 * pretending otherwise — the same standing the unit list has, which *was read out of the legacy tree
 * and confirmed one by one*. **A wrong member is cheap** (these are ordinary authored content after
 * [D-119](../../../docs/NewConcept/90-decision-log.md) and may be thrown away); a wrong *shape*
 * would not be, which is why the shape is the graded part.
 *
 * ⚠️ **And one thing is expected to fail rather than hoped to pass.**
 * [D-375](../../../docs/NewConcept/90-decision-log.md) already found that **storing** a composed
 * value is not built — `DataEntry::put()` refuses a target whose branch keeps its own records, and
 * `Compositions` does. So these can be **modelled and drawn** and not filled in. *`Backrezept` is
 * one rung past that gap, not around it.*
 *
 * ⚠️ **Its own option and version, for the reason {@see UnitScaffold} states**: a delivery is
 * content, the schema is machinery, and raising one must not re-enter the other.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class CompositionScaffold
{
    public const OPTION = 'taxmod_composition_scaffold';

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 1;

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        /** ⚠️ *So a member's type is found by id and not by the node's name ([D-510](../../../docs/NewConcept/90-decision-log.md)).* */
        private readonly TypeNodes $typeNodes,
    ) {
    }

    /**
     * Deliver once, then never again.
     *
     * @return list<string> The names created, so activation can say what it did.
     */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * ⚠️ **Once, and then hands off** ([D-119](../../../docs/NewConcept/90-decision-log.md)): after
     * the import these are ordinary authored content. A model with no use for `Backrezept` may throw
     * it away, and reactivating the plugin must not bring it back.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $compositions = $this->framework->rootOf(Branch::Compositions);
        $created      = [];

        // ⚠️ **Looked up, not created.** `Einheitenwert` belongs to {@see UnitScaffold}, whose members
        // are prefixes and base units — so it stays where its material is, and this file borrows it.
        // *Two scaffolds creating one node by the same name is the duplicated fact, one branch down.*
        // ⚠️ **Über die Id, die {@see UnitScaffold} hinterlegt hat** — *sein Wort: «köntest aber über
        // id gehen 😉», und es ist [D-510](../../../docs/NewConcept/90-decision-log.md): «Ein Name
        // ist eine Beschriftung und darf sich ändern.» **Hier stand eine Suche nach dem Namen unter
        // einem festen Elternknoten, und sie brach ab, als er den Knoten verschob** — richtig
        // verschob, nach [D-677](../../../docs/NewConcept/90-decision-log.md).*
        //
        // ⚠️ *Die Namenssuche bleibt als Notnagel für Bestände, die vor der Option gesät wurden —
        // an **beiden** Stellen, weil der Knoten in beiden liegen kann.*
        $bekannt   = UnitScaffold::unitValueId();
        $unitValue = ($bekannt === null ? null : $this->editor->find($bekannt))
            ?? $this->existing($this->framework->rootOf(Branch::Combined), 'Einheitenwert')
            ?? $this->existing($compositions, 'Einheitenwert')
            ?? throw new \RuntimeException('«Einheitenwert» is not there yet — the unit scaffold has to run first.');

        $this->address($compositions, $created);
        $this->dimension($compositions, $unitValue, $created);
        $this->recipe($compositions, $unitValue, $created);

        return $created;
    }

    /**
     * Rung one — a composed type of simple members, which is the case that must need no code at all.
     *
     * ⚠️ **`hausnummer` and `plz` are `text` and that is the point of the example.** `01067` is not
     * `1067` and `12a` is not a number at all — *a postcode is an identifier that happens to be
     * written in digits*, and storing it as an integer silently discards the part that identifies.
     *
     * @param list<string> $created
     */
    private function address(Node $compositions, array &$created): void
    {
        $address = $this->ensure($compositions, 'Adresse', $created);

        // ⚠️ **Die Saat legt keine Felder an einem Knoten an, den jemand schon geformt hat.**
        //
        // ⚠️ *Gemessen am 2026-08-30, und der Eigentümer hat es an seiner Seite gesehen: `Adresse` hatte
        // **zehn** Felder — seine fünf (`Street`, `No.`, `Post Code`, `City`, `Country`, angelegt am
        // 26. August aus dem Browser) und fünf weitere, die ein Prüflauf am selben Abend um 21:01 dazu
        // gelegt hat. **Er hielt es für eine Folge seiner Umbenennung** und fragte, ob die
        // Schattentabelle nicht arbeite — beides war es nicht: `field()` sucht am **Namen**, fand keinen
        // eigenen und legte an.*
        //
        // ⚠️ **Dieselbe Klasse wie [D-543](../../../docs/NewConcept/90-decision-log.md)**, eine Stufe
        // weiter: *wer am Namen sucht, verdoppelt die Arbeit eines Menschen, der anders benennt. Die
        // Saat kann das nicht über Ids heilen — sie legt die Knoten ja erst an. **Also legt sie nur an,
        // wo noch nichts ist.***
        if (! in_array('Adresse', $created, true) && $this->editor->fieldsOf($address->id) !== []) {
            return;
        }

        foreach (['strasse', 'hausnummer', 'plz', 'ort', 'land'] as $member) {
            $this->field($address, $member, 'text');
        }
    }

    /**
     * Rung two — members that are themselves composed.
     *
     * ⚠️ **This is the rung that tests the *descent*, not the drawing.** Each of the three is an
     * `Einheitenwert`, itself `wert` · `prefix` · `einheit`, so a renderer that places one control
     * per member has to place a rendering rather than a field — three levels from the holder to
     * `Ω`. *A composite renderer that works only over simple members would pass rung one and fail
     * here, which is why rung one alone would have proved nothing.*
     *
     * @param list<string> $created
     */
    private function dimension(Node $compositions, Node $unitValue, array &$created): void
    {
        $dimension = $this->ensure($compositions, 'Dimension', $created);

        foreach (['laenge', 'breite', 'hoehe'] as $member) {
            $this->fieldTo($dimension, $member, $unitValue);
        }
    }

    /**
     * Rung three — a collection of composed values, which nothing has ever drawn.
     *
     * ⚠️ **`zutat` is `1..*` and that is the whole rung.** A recipe with no ingredient is not a
     * recipe, and every ingredient is itself an amount plus a name — so this is a *list of composed
     * values*, the shape [C116](../../../docs/NewConcept/10-domain-core.md#c116--the-composed-type-is-the-unit-of-rendering)
     * names last and the concept has never had an instance of.
     *
     * ⚠️ **It is expected to stop at storage, not at modelling.**
     * [D-375](../../../docs/NewConcept/90-decision-log.md) found that a single composed value has
     * nowhere to be written yet; a collection of them is the same gap multiplied. *Recorded here so
     * that a failing preview is read as the known gap rather than as a new fault.*
     *
     * @param list<string> $created
     */
    private function recipe(Node $compositions, Node $unitValue, array &$created): void
    {
        // ⚠️ **The ingredient is its own type, because `menge` alone is not one.** *2 Gramm salt* is
        // an amount **and** a name; splitting it into two parallel lists on the recipe would let the
        // third amount belong to the fourth name.
        $ingredient = $this->ensure($compositions, 'Zutat', $created);

        $this->fieldTo($ingredient, 'menge', $unitValue);
        $this->field($ingredient, 'bezeichnung', 'text');

        $recipe = $this->ensure($compositions, 'Backrezept', $created);

        $this->field($recipe, 'titel', 'text');
        $this->fieldTo($recipe, 'backzeit', $unitValue);
        $this->fieldTo($recipe, 'ofentemperatur', $unitValue);

        $this->widen($this->fieldTo($recipe, 'zutat', $ingredient), Multiplicity::OneToMany);
    }

    /**
     * Die Multiplizität eines Mitglieds sagen — **eine Spalte an der Kante**.
     *
     * ⚠️ *An der Kante und nicht am Typ, weil `Einheitenwert` an einer Stelle `0..1` und an einer
     * anderen `1..*` ist. **Das war schon der Grund, als es eine Setting-Zeile war**
     * ([D-093](../../../docs/NewConcept/90-decision-log.md)) — seit
     * [D-528](../../../docs/NewConcept/90-decision-log.md) ist es dieselbe Aussage an derselben
     * Stelle, nur ohne Kette.*
     */
    private function widen(Relation $relation, Multiplicity $multiplicity): void
    {
        $this->editor->setMultiplicity($relation->fromNodeId, $relation->id, $multiplicity);
    }

    /**
     * The child of this name, made if it is not there.
     *
     * ⚠️ **Found by name among the children, never by a remembered id** — an id is meaningless on its
     * own, and storing one per seeded node would be a second place where the tree lives.
     *
     * ⚠️ **Diese Begründung ist am 2026-08-29 widerlegt worden, an einer Stelle — und sie steht hier
     * nur noch als Beleg.** *[D-510](docs/NewConcept/90-decision-log.md) und
     * [D-512](docs/NewConcept/90-decision-log.md): der Code findet gesäte Knoten über eine **Id in
     * einer Option**, nicht über den Namen. Der Satz «eine Id ist für sich bedeutungslos» stimmt für
     * eine **nackte** Id — nicht für eine, die unter einem sprechenden Optionsnamen liegt.*
     *
     * ⚠️ **Und der Bruch ist hier gemessen, nicht befürchtet:** *`boundTheNumbers()` in dieser
     * Klassenfamilie suchte einen Knoten namens `int` — er heisst seit
     * [D-428](docs/NewConcept/90-decision-log.md) `Integer`. **Eine frisch gesäte Installation hätte
     * ihre Zahlengrenzen nie bekommen.***
     *
     * ⚠️ *Diese Methode bindet weiter über den Namen: die Umstellung der ~80 Einheiten- und
     * Kompositionsknoten ist **nicht beauftragt** und steht auf der Arbeitsliste. **Bis dahin gilt
     * hier der Namensweg — aber nicht mehr als Regel.***
     *
     * @param list<string> $created
     */
    private function ensure(Node $parent, string $name, array &$created): Node
    {
        foreach ($this->editor->childrenOf($parent->id) as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        $made      = $this->editor->createNode($name, $parent->id);
        $created[] = $name;

        return $made;
    }

    /** Das Kind dieses Namens, oder `null` — der Aufrufer sieht an mehreren Stellen nach. */
    private function existing(Node $parent, string $name): ?Node
    {
        foreach ($this->editor->childrenOf($parent->id) as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        // ⚠️ *`null` statt einer Ausnahme, seit an **zwei** Stellen nachgesehen wird: «hier nicht»
        // ist keine Störung mehr, sondern eine Zwischenantwort. **Die Ausnahme steht weiterhin** —
        // beim Aufrufer, wo sie erst fällt, wenn keine der Stellen etwas hatte. Sie zu erfinden
        // hiesse, dass zwei Dateien einen Knoten besitzen.*
        return null;
    }

    /** An attribute pointing at a simple type, found by name under the data types. */
    private function field(Node $owner, string $name, string $typeName): Relation
    {
        foreach ($this->editor->fieldsOf($owner->id) as $relation) {
            if ($relation->name === $name && $relation->fromNodeId === $owner->id) {
                return $relation;
            }
        }

        // ⚠️ **The literal above is read as a type, the node is then found by its id**
        // ([D-510](../../../docs/NewConcept/90-decision-log.md)). *`$typeName` is a string in this
        // file's own source and may be read as one; the **node's** name is a beschriftung and may
        // not — [D-022](../../../docs/NewConcept/90-decision-log.md) makes node names deliberately
        // non-unique, so a second node called `Text` would have answered here.*
        $wanted = SimpleType::fromNodeName($typeName);
        $id     = $wanted === null ? null : $this->typeNodes->nodeId($wanted);

        foreach ($this->editor->childrenOf($this->framework->rootOf(Branch::DataTypes)->id) as $child) {
            if ($id !== null && $child->id === $id) {
                return $this->editor->addField($owner->id, $child->id, $name);
            }
        }

        throw new \RuntimeException("The data type «{$typeName}» is not there yet.");
    }

    /** An attribute pointing at a node given directly, rather than at a type found by name. */
    private function fieldTo(Node $owner, string $name, Node $target): Relation
    {
        foreach ($this->editor->fieldsOf($owner->id) as $relation) {
            if ($relation->name === $name && $relation->fromNodeId === $owner->id) {
                return $relation;
            }
        }

        return $this->editor->addField($owner->id, $target->id, $name);
    }
}
