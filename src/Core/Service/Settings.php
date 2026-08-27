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
    public function resolve(array $chain, string $path = ''): array
    {
        // One query for the whole chain (D-014), then the walk happens in memory.
        //
        // ⚠️ *One query **regardless of path**, filtered inside the walk. A second query per path
        // would be the N+1 `CD-7` forbids, and paths multiply faster than chains do.*
        return $this->walk($chain, $this->settings->forOwners($chain), $path);
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
    private function walk(array $chain, array $settings, string $path = ''): array
    {
        $position = array_flip($chain);
        $winner   = [];

        foreach ($settings as $setting) {
            $at = $position[$setting->ownerId] ?? null;

            if ($at === null) {
                continue;
            }

            // ⚠️ **A path never falls back to the empty one, and that refusal is the point of it.**
            // `default` at path `4654` is *this node's value for that attribute*; `default` at the
            // empty path is *this node's own default*. **Two different questions** — and reading one
            // as the other is exactly what the prefix exponent died of: `kilo` carried `default = 3`
            // at the empty path and the `exponent` attribute could never see it
            // ([OQ-099](../../../docs/NewConcept/91-open-questions.md)).
            if ($setting->path !== $path) {
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
    public function put(array $chain, string $key, TypedValue $value, string $path = ''): void
    {
        $ownerId = $chain[count($chain) - 1];
        $engine  = SettingKey::tryFrom($key);

        if ($engine === null) {
            // A free key may be anything that is not one of the engine's names (D-084).
            $was = $this->valueAt($ownerId, $key, $path);

            $this->settings->put(new Setting($ownerId, $key, $value, $path));
            $this->note($ownerId, $key, $was, $value);

            return;
        }

        $this->refuseWhereItDoesNotApply($engine, $ownerId, $value);

        // ⚠️ *The path goes in so a bound is compared with a bound **for the same place**. Measuring
        // `range_min` at path `4654` against `range_min` at the empty path would refuse a value on
        // the strength of an answer to a different question.*
        $this->refuseWidening($engine, $chain, $value, $path);

        $was = $this->valueAt($ownerId, $key, $path);

        $this->settings->put(new Setting($ownerId, $key, $value, $path));
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
    public function reset(int $ownerId, string $key, string $path = ''): void
    {
        // ⚠️ *`path` says **which** place is forgotten. Without it, resetting a node's own default
        // would also throw away what it says about each of its attributes.*
        $this->settings->forget($ownerId, $key, $path);
    }

    /**
     * Fetch what the link **above** says into this link's own row — `reset` once settings are
     * materialised ([D-423](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Why forgetting the row stops being an answer.** With every owner carrying its own rows
     * there is no walk left to fall through, so `forget()` would leave **nothing** rather than the
     * inherited value. The owner: *I could say Reset on the node or on the attribute, and then it
     * **fetches it from the next higher node's setting**.*
     *
     * ⚠️ **«Above» is the chain minus its last link, and that is why no relation repository is
     * needed here.** A chain already ends at its owner — `[installation, …, parent, node]` for a
     * node and `[installation, …, target, edge]` for an attribute — so *above* is `array_slice(…,
     * 0, -1)` in both cases. *This service cannot look up an edge's target and does not have to:
     * the caller that built the chain already knew it.*
     *
     * ⚠️ **Nothing above is an answer, not a failure** — the owner: *if there is nothing there, then
     * they were its own settings.* So a pull either finds a value or proves there never was one, and
     * in the second case the row is left exactly as it stands.
     *
     * @param  list<int> $chain Ending at the owner whose row is being pulled into.
     * @return bool      Whether a value was found above and written.
     */
    public function pull(array $chain, string $key, string $path = ''): bool
    {
        $above = array_slice($chain, 0, -1);

        if ($above === []) {
            // The installation is the first link; there is nothing over it by construction.
            return false;
        }

        $value = ($this->resolve($above, $path)[$key] ?? null)?->value;

        if ($value === null) {
            return false;
        }

        $this->put($chain, $key, $value, $path);

        return true;
    }

    /**
     * Write what a chain resolves to into a **new** owner's own rows — the copy
     * [D-423](../../../docs/NewConcept/90-decision-log.md) asks for on inheriting and on creating an
     * attribute.
     *
     * ⚠️ **This is the whole of materialising, and it is deliberately one method.** The owner's two
     * rules are the same act with a different source: *on inheriting, the settings are written into
     * the inheriting node* — source is the **parent**'s chain — and *when an attribute is created,
     * all settings of the node are taken into the attribute* — source is the **target**'s chain.
     *
     * ⚠️ **It resolves rather than copying rows, and that matters for the tree that already
     * exists.** A parent whose own settings are sparse ([D-015](../../../docs/NewConcept/90-decision-log.md))
     * still resolves to a full set through the chain, so a child created today gets the values that
     * were **in force** rather than the handful that happened to be stored. *Copying `ownedBy()` —
     * which is what {@see ModelEditor::duplicate()} does — would give a new node almost nothing.*
     *
     * ⚠️ **Rows at a path travel too, and the owner had to correct me to get there.** I first shipped
     * this taking only the empty path, on the worry that a child's rows would end up pointing at
     * *edges chosen for its parent*. He: ***I do not understand — nonsense?*** **He is right, and the
     * reason is inheritance itself.** A `path` is a chain of **edge ids**
     * ([D-413](../../../docs/NewConcept/90-decision-log.md), [D-045](../../../docs/NewConcept/90-decision-log.md)),
     * and a child does not get copies of its parent's attribute edges — *it inherits them, the same
     * ids* ({@see ModelEditor::attributesOf()} walks `[...ancestorIds, id]`). **So the address is
     * still the child's own address**, and dropping those rows was the thing that lost information.
     *
     * ⚠️ *Where the worry **does** apply is {@see ModelEditor::duplicate()} — a copy gets **new** edges
     * (`addAttribute()` per attribute), so a path naming the original's edge ids means nothing on the
     * copy and would have to be remapped. Different act, real problem, not this one.*
     *
     * @param  list<int> $source Any chain; what it resolves to is what gets written.
     * @param  list<int> $target Ending at the owner receiving the rows.
     * @return list<string>      The keys written, as `key` or `key@path`.
     */
    public function materialise(array $source, array $target): array
    {
        $ownerId = $target[count($target) - 1];
        $written = [];

        foreach ($this->pathsAlong($source) as $path) {
            foreach ($this->resolve($source, $path) as $key => $resolved) {
                // ⚠️ **Never over a row the owner already has.** Materialising runs on creation, but
                // a caller may run it again — and overwriting would undo the very edit this whole
                // design exists to make possible.
                if ($this->valueAt($ownerId, $key, $path) !== null) {
                    continue;
                }

                // ⚠️ **A row whose value is nothing is never copied, and leaving this out cost real
                // damage the same day.** The backfill turned **8 valueless rows into 90**: an empty
                // row resolves as *set here* and stops the chain ([row 29](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)),
                // so materialising one hands a child a full stop instead of a value. *The owner found
                // it by asking why `factor` hangs on a text node — 26 of the 90 were `factor` and 26
                // were `offset`.*
                //
                // ⚠️ *It is deliberately silent rather than a refusal: an empty row upstream is a
                // fault to be cleaned up, not a reason for creating a node to fail.*
                if ($resolved->value->isNothing()) {
                    continue;
                }

                $engine = SettingKey::tryFrom($key);

                // ⚠️ *`multiplicity` is edge-only, so a node must not receive it — and `put()` would
                // refuse it with an exception. Asking first keeps materialising from having to catch
                // its own refusals.*
                if ($engine !== null && $engine->isEdgeOnly() && $this->nodes->find($ownerId) !== null) {
                    continue;
                }

                $this->put($target, $key, $resolved->value, $path);

                $written[] = $path === '' ? $key : $key . '@' . $path;
            }
        }

        return $written;
    }

    /**
     * Every address the chain has anything stored at — the empty one always, then the rest.
     *
     * ⚠️ **The empty path is included even when nothing sits there**, because that is where every
     * engine key lives; the others are the per-place answers [D-413](../../../docs/NewConcept/90-decision-log.md)
     * added. *One query, and the walk that follows filters in memory — a query per path would be the
     * N+1 `CD-7` forbids, and paths multiply faster than chains do.*
     *
     * @param  list<int>    $chain
     * @return list<string>
     */
    private function pathsAlong(array $chain): array
    {
        $paths = [''];

        foreach ($this->settings->forOwners($chain) as $one) {
            if ($one->path !== '' && ! in_array($one->path, $paths, true)) {
                $paths[] = $one->path;
            }
        }

        return $paths;
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

    private function refuseWidening(SettingKey $key, array $chain, TypedValue $value, string $path = ''): void
    {
        $above     = array_slice($chain, 0, -1);
        $inherited = $this->resolve($above, $path)[$key->value] ?? null;

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
    private function valueAt(int $ownerId, string $key, string $path = ''): ?TypedValue
    {
        foreach ($this->settings->ownedBy($ownerId) as $one) {
            if ($one->key === $key && $one->path === $path) {
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
