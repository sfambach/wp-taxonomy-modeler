<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;

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
        private readonly Settings $settings
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
        $unitValue = $this->existing($compositions, 'Einheitenwert');

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

        foreach (['strasse', 'hausnummer', 'plz', 'ort', 'land'] as $member) {
            $this->attribute($address, $member, 'text');
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
            $this->attributeTo($dimension, $member, $unitValue);
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

        $this->attributeTo($ingredient, 'menge', $unitValue);
        $this->attribute($ingredient, 'bezeichnung', 'text');

        $recipe = $this->ensure($compositions, 'Backrezept', $created);

        $this->attribute($recipe, 'titel', 'text');
        $this->attributeTo($recipe, 'backzeit', $unitValue);
        $this->attributeTo($recipe, 'ofentemperatur', $unitValue);

        $this->widen($this->attributeTo($recipe, 'zutat', $ingredient), Multiplicity::OneToMany);
    }

    /**
     * Say a member's multiplicity, which is a setting at the **use site**.
     *
     * ⚠️ *At the edge and not at the type, because `Einheitenwert` is `0..1` in one place and `1..*`
     * in another — [D-093](../../../docs/NewConcept/90-decision-log.md)'s chain is walked key by key
     * for exactly this.*
     */
    private function widen(Relation $edge, Multiplicity $multiplicity): void
    {
        $this->settings->put(
            $this->settings->chainForUseSite($edge),
            SettingKey::Multiplicity->value,
            TypedValue::ofText($multiplicity->value)
        );
    }

    /**
     * The child of this name, made if it is not there.
     *
     * ⚠️ **Found by name among the children, never by a remembered id** — an id is meaningless on its
     * own, and storing one per seeded node would be a second place where the tree lives.
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

    /** The child of this name, which another delivery owns — absent, this one cannot run. */
    private function existing(Node $parent, string $name): Node
    {
        foreach ($this->editor->childrenOf($parent->id) as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        // ⚠️ Creating it here would make two files own one node; saying so out loud names the
        // ordering that activation has to keep.
        throw new \RuntimeException("«{$name}» is not there yet — the unit scaffold has to run first.");
    }

    /** An attribute pointing at a simple type, found by name under the data types. */
    private function attribute(Node $owner, string $name, string $typeName): Relation
    {
        foreach ($this->editor->attributesOf($owner->id) as $edge) {
            if ($edge->name === $name && $edge->fromId === $owner->id) {
                return $edge;
            }
        }

        foreach ($this->editor->childrenOf($this->framework->rootOf(Branch::DataTypes)->id) as $child) {
            if ($child->name === $typeName) {
                return $this->editor->addAttribute($owner->id, $child->id, $name);
            }
        }

        throw new \RuntimeException("The data type «{$typeName}» is not there yet.");
    }

    /** An attribute pointing at a node given directly, rather than at a type found by name. */
    private function attributeTo(Node $owner, string $name, Node $target): Relation
    {
        foreach ($this->editor->attributesOf($owner->id) as $edge) {
            if ($edge->name === $name && $edge->fromId === $owner->id) {
                return $edge;
            }
        }

        return $this->editor->addAttribute($owner->id, $target->id, $name);
    }
}
