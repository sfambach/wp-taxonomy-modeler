<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\TypeNodes;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;

/**
 * The base scaffold — the simple data types, imported **once**.
 *
 * ⚠️ **Once, and then hands off** (D-119). After the import these are ordinary authored content:
 * a model that never needs `color` may throw it away, and reactivating the plugin must not bring
 * it back. That is what the stored version guards — not *do the nodes exist*, but *has the
 * scaffold been delivered*. Asking the first question instead would quietly undo the owner's
 * deletions on every activation, which is exactly the kind of helpfulness nobody asked for.
 *
 * ⚠️ **Not framework-protected** ([D-194](../../../docs/NewConcept/90-decision-log.md)):
 * protection covers the handful of nodes the machinery stands on, and a data type is not one of
 * them.
 *
 * ⚠️ **Composed types are not here yet.** `quantity`, `money`, `range`, `period`, `tolerance`,
 * `ratio`, `address`, `markup` and `Link` are composed *of* attributes, so seeding them means
 * seeding edges — a bigger step, and one that wants the renderers to be worth looking at.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class BaseScaffold
{
    public const OPTION = 'taxmod_base_scaffold';

    /** Raise it only to deliver something genuinely new; every raise re-enters every install. */
    public const VERSION = 6;

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
        /**
         * ⚠️ **Required, because remembering the id is part of seeding** ([D-510](../../../docs/NewConcept/90-decision-log.md)).
         * *A scaffold that creates the node and writes nothing down leaves the next reader with only
         * the name to go on — which is the fault this replaces.*
         */
        private readonly TypeNodes $typeNodes,
        // ⚠️ **New here, and only for the bounds.** A scaffold that creates the type but not what
        // the type *permits* leaves the most useful fact about `int` unsaid.
        private readonly ?Settings $settings = null,
    ) {
    }

    /** @return list<string> The names actually created, so a caller can report what it did. */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        $created = $this->import();

        $this->boundTheNumbers();
        $this->declareKeyDefaults();

        update_option(self::OPTION, self::VERSION, true);

        return $created;
    }

    /**
     * Create what is not there, whatever the stored version says.
     *
     * Separate from {@see importOnce()} so a check script can call it directly — and so the
     * *once* is a decision of the caller rather than something buried in the writing.
     *
     * @return list<string>
     */
    public function import(): array
    {
        $dataTypes = $this->framework->rootOf(Branch::DataTypes);

        $taken = [];
        $byId  = [];

        foreach ($this->editor->childrenOf($dataTypes->id) as $child) {
            // ⚠️ **`??=` und nicht `=`: der erste Treffer gewinnt, nicht der letzte.** *Gemessen am
            // 2026-08-29: `childrenOf()` liefert nach Position, und ein zweiter Knoten namens
            // `Integer` stand hinter dem gesäten — also band die Saat sich an den **Doppelgänger** und
            // legte beim nächsten Lauf einen dritten `Integer` an. [D-022](../../../docs/NewConcept/90-decision-log.md)
            // sagt, dass Knotennamen absichtlich nicht eindeutig sind; «der letzte gewinnt» ist dazu
            // keine Regel, sondern ein Zufall.*
            $taken[$child->name] ??= $child;
            $byId[$child->id]      = $child;
        }

        $created = [];

        // ⚠️ **The short machine name becomes the spelled-out one** ([D-428](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *the data type `int` is shown as `int`, `decimal` as `decimal` — unify that, for
        // `int` = `Integer`.* **A rename and not a label**, because
        // [D-369](../../../docs/NewConcept/90-decision-log.md) says the modelling tree shows a node's
        // own name — *«there I would take the node name»* — so a label would not appear there at all.
        //
        // ⚠️ *Renaming rather than re-seeding, because these nodes are pointed at: every attribute in
        // the model targets one of them, and creating `Integer` beside `int` would leave every
        // existing field attached to the old one.*
        foreach (SimpleType::cases() as $type) {
            $old = $taken[$type->value] ?? null;

            if ($old === null || isset($taken[$type->nodeName()])) {
                continue;
            }

            $this->editor->rename($old->id, $type->nodeName());

            $taken[$type->nodeName()] = $old;

            unset($taken[$type->value]);
        }

        // ⚠️ **Die Saat schlägt selbst Id zuerst nach und schreibt die Id danach fest**
        // ([D-510](../../../docs/NewConcept/90-decision-log.md)) — dieselbe Reihenfolge wie überall
        // sonst. *Ohne das Nachschlagen könnte ein Doppelgänger die Bindung übernehmen, obwohl längst
        // notiert ist, welcher Knoten der Typ ist. Der notierte Knoten zählt nur, solange er noch unter
        // `Data Types` hängt: ein Typ, den der Eigentümer weggeworfen hat, ist weg
        // ([D-119](../../../docs/NewConcept/90-decision-log.md)).*
        //
        // ⚠️ *Und festgeschrieben wird auch, was schon dastand — nicht nur, was dieser Lauf angelegt
        // hat. Sonst bliebe jede vor der Entscheidung gesäte Installation für immer über den Notnagel
        // in {@see SeededTypeNodes} gebunden, also über einen Rückfall, der die Arbeit der Saat tut.*
        foreach (SimpleType::cases() as $type) {
            $known = $this->typeNodes->nodeId($type);
            $node  = ($known === null ? null : ($byId[$known] ?? null))
                ?? $taken[$type->nodeName()]
                ?? null;

            if ($node === null) {
                $node      = $this->editor->createNode($type->nodeName(), $dataTypes->id);
                $created[] = $type->nodeName();
            }

            $this->typeNodes->remember($type, $node->id);
        }

        return $created;
    }
    /**
     * What a seeded number type actually permits, taken from the column it is stored in.
     *
     * The owner: *`range_min` and `range_max` on `int` should be int's min and max — so minus size to
     * plus size of `int`.* **And the bounds come from storage rather than from PHP**, because storage
     * is what refuses: `value_int` is a `bigint`, `value_decimal` is `decimal(30,10)`.
     *
     * ⚠️ **This makes the narrowing rule real rather than theoretical.** [D-312](../../../docs/NewConcept/90-decision-log.md)
     * says a bounding setting may only be tightened downwards; with nothing at the top there was
     * nothing to tighten *from*, so `my_int` could set any minimum at all. *Now a descendant narrows a
     * real range, which is what the rule was written for.*
     *
     * ⚠️ **What is deliberately not done: locking them at the defining type.** The owner asked for it —
     * *since it is a data type and laid down by us, it should not be changeable in `int` (in
     * descendants it should)* — and then answered his own question: *is that a lot to do? if so leave
     * it, then users can adjust the type, which is not so bad.* **It is a lot**: nothing in the model
     * can say *this control is fixed at this node and free below*. `read_only` locks a **value**, not a
     * setting's control. *That is a new axis and it is on the working list, not smuggled in here.*
     *
     * ⚠️ *`decimal(30,10)` leaves twenty integer digits, which is far past what a float can hold — so
     * the bound is written as a **string** and never through a PHP number.*
     */
    private function boundTheNumbers(): void
    {
        if ($this->settings === null) {
            return;
        }

        // ⚠️ **`step` belongs here too** — the owner: *step still on default 1*. For a whole number it
        // is the only honest step: `int` with `step = 0.5` is not an `int`. *A descendant may widen the
        // step to 5 or 10; `step` is a **choosing** setting, not a bound.*
        //
        // ⚠️ **Keyed by the type and no longer by a node name, and that was not cosmetic.** *This
        // read `'int'` while the node has been called `Integer` since
        // [D-428](../../../docs/NewConcept/90-decision-log.md) — so on any installation seeded after
        // that rename `typeNamed('int')` answered null and **the bounds were never written at all**.
        // The same illness as the check that looked for `int`, in production code
        // ([D-510](../../../docs/NewConcept/90-decision-log.md)).*
        $bounds = [
            // bigint, signed.
            [SimpleType::Int, '-9223372036854775808', '9223372036854775807', '1'],
        ];

        foreach ($bounds as [$type, $low, $high, $step]) {
            $node = $this->seededNode($type);

            if ($node === null) {
                continue;
            }

            $chain = $this->settings->chainFor($node);

            // ⚠️ **Written only where nothing is there.** A person may already have narrowed `int`, and
            // a scaffold that overwrites on every upgrade would undo their work — which is the same
            // reason the scaffold has a version at all.
            $resolved = $this->settings->resolve($chain);

            if (! isset($resolved[SettingKey::Min->value])) {
                $this->settings->put($chain, SettingKey::Min->value, TypedValue::ofInt((int) $low));
            }

            if (! isset($resolved[SettingKey::Max->value])) {
                $this->settings->put($chain, SettingKey::Max->value, TypedValue::ofInt((int) $high));
            }

            if (! isset($resolved[SettingKey::Step->value])) {
                $this->settings->put($chain, SettingKey::Step->value, TypedValue::ofInt((int) $step));
            }
        }
    }

    /**
     * The node this type was seeded as — by the id, and only if it is still where it belongs.
     *
     * ⚠️ *Matched against the children of `Data Types` rather than read by id, because a data type
     * is ordinary content and may have been thrown away
     * ([D-119](../../../docs/NewConcept/90-decision-log.md)). A node that is in the trash must not
     * get bounds written onto it — and null here means exactly «not there», which the caller already
     * knows how to skip.*
     */
    private function seededNode(SimpleType $type): ?Node
    {
        $id = $this->typeNodes->nodeId($type);

        if ($id === null) {
            return null;
        }

        foreach ($this->editor->childrenOf($this->framework->rootOf(Branch::DataTypes)->id) as $child) {
            if ($child->id === $id) {
                return $child;
            }
        }

        return null;
    }
    /**
     * What a key means when nobody has said anything — written at the installation identity.
     *
     * ⚠️ **[D-404](../../../docs/NewConcept/90-decision-log.md) says where this belongs and this is
     * the first use of it**: the installation identity is the *first link* of the resolution chain
     * ([D-079](../../../docs/NewConcept/90-decision-log.md)), so a default written there is answered
     * for every node and every use site by the walk that already exists.
     *
     * ⚠️ **`persistent` is why it got built.** [D-377](../../../docs/NewConcept/90-decision-log.md)
     * decided *default true* and the switch drew **off**, because a switch shows what is stored and
     * nothing was. The owner caught it twice — *persistent is not selectable at all and off by
     * default, that is all wrong*, then *persistent on int still off*. **A control that states the
     * opposite of what is in force is worse than a missing one.**
     *
     * ⚠️ *One row instead of eight `?? true` inventions scattered across renderers and services —
     * and a row a person can see and change, which a compiled-in answer never is.*
     */
    private function declareKeyDefaults(): void
    {
        if ($this->settings === null) {
            return;
        }

        $chain    = [$this->framework->installationId()];
        $resolved = $this->settings->resolve($chain);

        // ⚠️ **Every switch, and the value comes off the key** ([D-401](../../../docs/NewConcept/90-decision-log.md)).
        // *`persistent` was written here by hand and `hide` and `read_only` were not, which is why two
        // of the three defaults still lived as `?? false` inside readers. A loop cannot forget the
        // third one.*
        foreach (SettingKey::cases() as $key) {
            // ⚠️ **Switches only, and that is a decision rather than an oversight.** `multiplicity`
            // has a declared default too, but {@see \Taxmod\Core\Model\Multiplicity} already owns it
            // and [D-015](../../../docs/NewConcept/90-decision-log.md) argues against storing it:
            // *an absent row **means** `0..1`, and writing it everywhere would make «nobody has
            // narrowed this» indistinguishable from «somebody chose the widest option».* The keys
            // with no declared default are skipped by the same test.
            if ($key->shape() !== SettingShape::Switch) {
                continue;
            }

            $declared = $key->declaredDefault();

            // ⚠️ Only where nothing is there: an installation may have decided otherwise, and a
            // scaffold that overwrites on upgrade would undo that.
            if ($declared === null || isset($resolved[$key->value])) {
                continue;
            }

            $this->settings->put($chain, $key->value, $declared);
        }
    }

}
