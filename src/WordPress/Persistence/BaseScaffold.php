<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
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
    public const VERSION = 5;

    public function __construct(
        private readonly ModelEditor $editor,
        private readonly FrameworkNodes $framework,
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

        foreach ($this->editor->childrenOf($dataTypes->id) as $child) {
            $taken[$child->name] = true;
        }

        $created = [];

        foreach (SimpleType::cases() as $type) {
            if (isset($taken[$type->value])) {
                continue;
            }

            $this->editor->createNode($type->value, $dataTypes->id);
            $created[] = $type->value;
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
        $bounds = [
            // bigint, signed.
            'int' => ['-9223372036854775808', '9223372036854775807', '1'],
        ];

        foreach ($bounds as $name => [$low, $high, $step]) {
            $node = $this->typeNamed($name);

            if ($node === null) {
                continue;
            }

            $chain = $this->settings->chainFor($node);

            // ⚠️ **Written only where nothing is there.** A person may already have narrowed `int`, and
            // a scaffold that overwrites on every upgrade would undo their work — which is the same
            // reason the scaffold has a version at all.
            $resolved = $this->settings->resolve($chain);

            if (! isset($resolved[SettingKey::RangeMin->value])) {
                $this->settings->put($chain, SettingKey::RangeMin->value, TypedValue::ofInt((int) $low));
            }

            if (! isset($resolved[SettingKey::RangeMax->value])) {
                $this->settings->put($chain, SettingKey::RangeMax->value, TypedValue::ofInt((int) $high));
            }

            if (! isset($resolved[SettingKey::RangeStep->value])) {
                $this->settings->put($chain, SettingKey::RangeStep->value, TypedValue::ofInt((int) $step));
            }
        }
    }

    /** The seeded data type with this name — the same lookup `importOnce()` already does. */
    private function typeNamed(string $name): ?Node
    {
        foreach ($this->editor->childrenOf($this->framework->rootOf(Branch::DataTypes)->id) as $child) {
            if ($child->name === $name) {
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
