<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\ReservedKey;
use Taxmod\Core\Exception\SettingDoesNotApply;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Narrowing;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\Setting;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\SettingRepository;

/**
 * The resolution chain: installation → model root → ancestors → node → use site.
 *
 * ```mermaid
 * flowchart LR
 *   I["installation"] --> R["model root"] --> A["ancestors"] --> N["node"] --> U["use site"]
 * ```
 *
 * ⚠️ **Walked key by key**, so a consumer may take a mix (D-079, D-093): the renderer from the
 * type, the multiplicity from the use site, the icon from three levels up. It is not one link
 * winning the whole set.
 *
 * ⚠️ **`model root → ancestors → node` is the node's path**, in order. That is what makes the
 * walk one indexed lookup rather than a climb (D-014), and why the chain is known before the
 * first query is sent.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Settings
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        // ⚠️ **New, and it closes a hole that cost two wrong answers in one day.** The owner:
        // *that we should change — settings should be recorded.* Until now **591 setting rows had
        // zero changelog entries**: `owner_kind` knew only `node` and `relation`, so
        // [D-081](../../../docs/NewConcept/90-decision-log.md)'s *every object has at least one
        // changelog item* was false of the most-edited table in the model, and
        // [D-061](../../../docs/NewConcept/90-decision-log.md)'s *the changelog is the migration
        // script* would have replayed into a model with no settings.
        //
        // ⚠️ *Optional, because the core must keep working without one — a scaffold, a test and a
        // migration all write settings and none of them has a person behind the change.*
        private readonly ?Changelog $changelog = null,
    ) {
    }

    /**
     * The chain for a node: the installation, then every step of its path.
     *
     * @return list<int>
     */
    public function chainFor(Node $node): array
    {
        return [$this->framework->installationId(), ...$node->ancestorIds(), $node->id];
    }

    /**
     * The chain for a use site: the target's chain, then the edge itself.
     *
     * ⚠️ **The use site is the last link and therefore the strongest** — which is exactly what
     * makes *a configured default plus a choice in the moment* one pattern rather than two
     * (D-032): the two ends of one walk that already exists.
     *
     * @return list<int>
     */
    public function chainForUseSite(Relation $edge): array
    {
        return [...$this->chainFor($this->nodes->byId($edge->toId)), $edge->id];
    }

    /**
     * The same for a set of **nodes** — a tree's worth of rows in one query.
     *
     * ⚠️ **This exists for the same reason {@see resolveForUseSites()} does, and the tree is where
     * it matters most.** A row draws the node's icon ([D-251](../../../docs/NewConcept/90-decision-log.md)),
     * which is a setting resolved along the chain — so a hundred rows would be a hundred walks, and
     * `CD-7` forbids the loop outright. The batched load is [D-014](../../../docs/NewConcept/90-decision-log.md)'s
     * own construction applied to a set of chains instead of one.
     *
     * @param  list<Node>                                $nodes
     * @return array<int, array<string, ResolvedSetting>> Keyed by node id.
     */
    public function resolveForNodes(array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $chains = [];

        foreach ($nodes as $node) {
            $chains[$node->id] = $this->chainFor($node);
        }

        $everyOwner = array_values(array_unique(array_merge(...array_values($chains))));
        $settings   = $this->settings->forOwners($everyOwner);

        $resolved = [];

        foreach ($chains as $nodeId => $chain) {
            $resolved[$nodeId] = $this->walk($chain, $settings);
        }

        return $resolved;
    }

    /**
     * Resolve every setting along a chain, key by key.
     *
     * @param list<int> $chain
     *
     * @return array<string,ResolvedSetting>
     */
    public function resolve(array $chain): array
    {
        // One query for the whole chain (D-014), then the walk happens in memory.
        return $this->walk($chain, $this->settings->forOwners($chain));
    }

    /**
     * The same answer for many use sites, in **two** queries however many there are.
     *
     * ⚠️ **This exists because a caller with a list of attributes would otherwise resolve in a
     * loop**, which is the N+1 the code standard forbids outright (`CD-7`). The batched load is
     * D-014's own construction, applied to a set of chains instead of one.
     *
     * @param  list<Relation>                            $edges
     * @return array<int, array<string, ResolvedSetting>> Keyed by edge id.
     */
    public function resolveForUseSites(array $edges): array
    {
        if ($edges === []) {
            return [];
        }

        $targets = $this->nodes->byIds(array_map(static fn (Relation $e): int => $e->toId, $edges));
        $chains  = [];

        foreach ($edges as $edge) {
            $target = $targets[$edge->toId] ?? null;

            $chains[$edge->id] = $target === null
                ? [$this->framework->installationId(), $edge->id]
                : [$this->framework->installationId(), ...$target->ancestorIds(), $target->id, $edge->id];
        }

        $everyOwner = array_values(array_unique(array_merge(...array_values($chains))));
        $settings   = $this->settings->forOwners($everyOwner);

        $resolved = [];

        foreach ($chains as $edgeId => $chain) {
            $resolved[$edgeId] = $this->walk($chain, $settings);
        }

        return $resolved;
    }

    /**
     * @param  list<int>                     $chain
     * @param  list<Setting>                 $settings Everything stored for those owners, and
     *                                                 possibly for others — extras are skipped.
     * @return array<string, ResolvedSetting>
     */
    private function walk(array $chain, array $settings): array
    {
        $position = array_flip($chain);
        $winner   = [];

        foreach ($settings as $setting) {
            $at = $position[$setting->ownerId] ?? null;

            if ($at === null) {
                continue;
            }

            $known = $winner[$setting->key] ?? null;

            if ($known === null || $at >= $known[1]) {
                $winner[$setting->key] = [$setting, $at];
            }
        }

        $last     = count($chain) - 1;
        $resolved = [];

        foreach ($winner as $key => [$setting, $at]) {
            $resolved[$key] = new ResolvedSetting($key, $setting->value, $setting->ownerId, $at === $last);
        }

        return $resolved;
    }

    /**
     * Write a setting at one link of a chain, refusing anything that widens a bound.
     *
     * @param list<int> $chain The chain the owner is the **last** link of.
     */
    public function put(array $chain, string $key, TypedValue $value): void
    {
        $ownerId = $chain[count($chain) - 1];
        $engine  = SettingKey::tryFrom($key);

        if ($engine === null) {
            // A free key may be anything that is not one of the engine's names (D-084).
            $was = $this->valueAt($ownerId, $key);

            $this->settings->put(new Setting($ownerId, $key, $value));
            $this->note($ownerId, $key, $was, $value);

            return;
        }

        $this->refuseWhereItDoesNotApply($engine, $ownerId, $value);
        $this->refuseWidening($engine, $chain, $value);

        $was = $this->valueAt($ownerId, $key);

        $this->settings->put(new Setting($ownerId, $key, $value));
        $this->note($ownerId, $key, $was, $value);
    }

    /**
     * Declare a free setting, refusing one of the engine's names.
     *
     * ⚠️ Separate from {@see put()} because the check belongs where a **new name** is invented,
     * not where a known one is written.
     */
    public function declareFree(array $chain, string $key, TypedValue $value): void
    {
        if (SettingKey::isReserved($key)) {
            throw ReservedKey::named($key);
        }

        $this->put($chain, $key, $value);
    }

    /**
     * Make a setting inherited again — the row disappears (D-266).
     *
     * ⚠️ **Not the same as writing nothing.** After a reset, later changes at the base arrive
     * here once more; after *set to nothing*, they deliberately do not.
     */
    public function reset(int $ownerId, string $key): void
    {
        $this->settings->forget($ownerId, $key);
    }

    /**
     * ⚠️ **Compared against what the chain says *above* this link**, not against the resolved
     * value — otherwise a link would be measured against itself and every write would pass.
     *
     * @param list<int> $chain
     */
    /**
     * Refuse a key at an owner that has nothing to say about it.
     *
     * ⚠️ **Nodes and edges share one id space** (C11), so *is this owner an edge* is answered by
     * looking: an id the node table does not know is a relation. One lookup, and it is the same
     * lookup the resolution walk already does.
     */
    private function refuseWhereItDoesNotApply(SettingKey $key, int $ownerId, TypedValue $value): void
    {
        if ($key->isEdgeOnly() && $this->nodes->find($ownerId) !== null) {
            throw SettingDoesNotApply::toANode($key);
        }

        // The four constants are the type, so a value outside them is refused here rather than
        // surfacing later as a multiplicity nobody can read (D-351).
        if ($key === SettingKey::Multiplicity && ! $value->isNothing()) {
            $written = $value->text ?? $value->describe();

            if (Multiplicity::tryFrom($written) === null) {
                throw SettingDoesNotApply::notOneOfTheFour($written);
            }
        }
    }

    private function refuseWidening(SettingKey $key, array $chain, TypedValue $value): void
    {
        $above     = array_slice($chain, 0, -1);
        $inherited = $this->resolve($above)[$key->value] ?? null;

        if ($inherited === null || $inherited->value->isNothing()) {
            return;
        }

        $was = $inherited->value;

        switch ($key->direction()) {
            case Narrowing::OnceOnAlwaysOn:
                // D-311: what an ancestor declares mandatory stays mandatory for every
                // descendant. The same shape holds for hide and read_only.
                if ($was->asBool() && ! $value->asBool()) {
                    throw CannotWiden::mandatoryStays($key);
                }

                break;

            case Narrowing::OnlyUp:
                if ($this->lessThan($value, $was)) {
                    throw CannotWiden::bound($key, $was->describe(), $value->describe());
                }

                break;

            case Narrowing::OnlyDown:
                if ($this->lessThan($was, $value)) {
                    throw CannotWiden::bound($key, $was->describe(), $value->describe());
                }

                break;

            case Narrowing::BySubset:
                // Containment, not arithmetic — and an incomparable pair falls out here too,
                // because neither of the two contains the other (D-351).
                $inheritedOne = Multiplicity::tryFrom($was->text ?? '');
                $attempted    = Multiplicity::tryFrom($value->text ?? '');

                if ($inheritedOne !== null && $attempted !== null && ! $attempted->narrows($inheritedOne)) {
                    throw CannotWiden::notNarrower($inheritedOne->notation(), $attempted->notation());
                }

                break;

            case Narrowing::Free:
                break;
        }
    }

    /**
     * Numeric comparison over whichever typed column carries the number.
     *
     * ⚠️ Decimals are compared as **numbers**, not as strings — `9` is not greater than `10`
     * merely because it starts with a nine. They are stored exactly (D-057), and a comparison
     * that spans the two columns is the one place they meet.
     */
    private function lessThan(TypedValue $a, TypedValue $b): bool
    {
        $left  = $a->int ?? ($a->decimal !== null ? (float) $a->decimal : null);
        $right = $b->int ?? ($b->decimal !== null ? (float) $b->decimal : null);

        if ($left === null || $right === null) {
            return false;
        }

        return $left < $right;
    }
    /**
     * One changelog line per setting written or cleared.
     *
     * ```mermaid
     * flowchart LR
     *   S["a setting written"] --> O["recorded against its OWNER"]
     *   O --> Q["«what happened to this node» now includes its settings"]
     * ```
     *
     * ⚠️ **Against the owner and not against the setting**, and that is the whole design decision. A
     * setting has no identity a person navigates to — they look at a **node** and ask what changed. So
     * the entry hangs off the node or the edge, `owner_kind` keeps its two values, **no schema step is
     * needed**, and {@see \Taxmod\Core\Model\ChangeSummary} starts reporting the changes it was blind
     * to.
     *
     * ⚠️ **Nothing is recorded when nothing changed.** A page save posts every key on the panel, so
     * without this a single save would write thirty entries with thirty unchanged values — and a
     * journal that logs non-events is one nobody reads.
     *
     * ⚠️ *`before` and `after` are `describe()`d, which is a **diagnostic** rendering and deliberately
     * not a stored value ([D-400](../../../docs/NewConcept/90-decision-log.md)): a reference reads as
     * «(a reference)» here rather than as a bare id, because a log line is prose and not data.*
     */
    private function note(int $ownerId, string $key, ?TypedValue $was, ?TypedValue $now): void
    {
        if ($this->changelog === null) {
            return;
        }

        $before = $was === null || $was->isNothing() ? null : $was->describe();
        $after  = $now === null || $now->isNothing() ? null : $now->describe();

        if ($before === $after) {
            return;
        }

        $this->changelog->record(
            $ownerId,
            // ⚠️ **Three kinds, because the installation identity is neither.** An edge carries
            // settings as readily as a node ([D-381]) and the id alone cannot say which — so it is
            // asked. *And the **installation** has an identity with no node behind it, which is where
            // a key's own default lives ([D-079](../../../docs/NewConcept/90-decision-log.md)); the
            // first version of this line called that a `relation`, which was simply a lie.*
            $this->kindOf($ownerId),
            $after === null ? "setting {$key} cleared" : "setting {$key} set",
            $before,
            $after
        );
    }
    /** What this owner has stored under this key right now, or nothing. */
    private function valueAt(int $ownerId, string $key): ?TypedValue
    {
        foreach ($this->settings->ownedBy($ownerId) as $one) {
            if ($one->key === $key) {
                return $one->value;
            }
        }

        return null;
    }

    /** Which of the three things this owner id is. */
    private function kindOf(int $ownerId): string
    {
        if ($ownerId === $this->framework->installationId()) {
            return 'installation';
        }

        return $this->nodes->find($ownerId) === null ? 'relation' : 'node';
    }

}
