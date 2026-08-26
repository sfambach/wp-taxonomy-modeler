<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotRestore;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Setting;
use Taxmod\Core\Repository\LabelRepository;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\SettingRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Everything a person can do to the shape of the model: make a node, rename it, move it,
 * reorder it among its siblings, throw it away.
 *
 * ⚠️ **The tree is the inheritance edges; `path` is derived from them** (D-014). Every operation
 * here changes the edge first and rewrites the path afterwards. Writing the path alone would
 * make the derived value the only truth, which is exactly what D-014 forbids.
 *
 * ⚠️ **Deletion is two stages, and only the second is irreversible** (D-123). Parking is an
 * ordinary move — under the trash. The node stays a real node, so nothing that pointed at it
 * dangles and a conflict can be sorted out afterwards in peace instead of in a dialog blocking
 * the delete.
 *
 * ```mermaid
 * flowchart LR
 *   A[in the model] -->|park = move under the trash| B[under the trash]
 *   B -->|restore = move back| A
 *   B -->|purge| C[gone]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditor
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly RelationRepository $relations,
        private readonly IdentityAllocator $identities,
        private readonly FrameworkNodes $framework,
        private readonly Changelog $changelog,
        // ⚠️ **Only `duplicate()` uses these, and that is why they are optional.** A copy has to
        // resolve exactly like its original or it is not a copy — which means its **own** settings
        // and labels travel with it. *Everything else in this service moves nodes and edges around
        // and has no business reading either.*
        private readonly ?SettingRepository $settings = null,
        private readonly ?LabelRepository $labels = null,
        // ⚠️ **The *service*, not the repository, and it is here for materialising**
        // ([D-423](../../../docs/NewConcept/90-decision-log.md)). A new node's rows are what its
        // parent **resolves** to, which only the service can answer — the repository above holds
        // rows and knows nothing of chains. *Optional for the same reason as the other two: the
        // eighteen places that construct this service mostly move nodes around, and a required
        // argument would make every one of them declare a dependency it never uses.*
        private readonly ?Settings $materialiser = null,
    ) {
    }

    public function createNode(string $name, int $parentId): Node
    {
        $parent = $this->nodes->byId($parentId);

        // Two identities, because an edge is a first-class thing that can carry settings and
        // labels of its own (C8) — and both come from the one model space (C11).
        $node = Node::create($this->identities->next(), $name, $parent->path);
        $edge = Relation::inheritance(
            $this->identities->next(),
            $parent->id,
            $node->id,
            $this->relations->nextPositionUnder($parent->id)
        );

        $this->nodes->add($node);
        $this->relations->add($edge);
        $this->changelog->record($node->id, 'node', 'created', null, $this->state($node));

        // ⚠️ **The parent's settings are written into the child** ([D-423](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *on inheriting, the settings are written into the inheriting node, where they can
        // be changed.* **After the changelog entry on purpose** — the node exists before it is
        // furnished, and each setting write journals itself ([D-403](../../../docs/NewConcept/90-decision-log.md)),
        // so the order in the log reads the way it happened.
        $this->materialise($parent, $node);

        return $node;
    }

    /**
     * Give a fresh node its own copy of what its parent resolves to.
     *
     * ⚠️ *Silently absent where no materialiser was handed in, which is the same contract the
     * settings and labels repositories above already have — a caller that only moves nodes about
     * gets the old sparse behaviour and nothing breaks.*
     */
    private function materialise(Node $parent, Node $child): void
    {
        if ($this->materialiser === null) {
            return;
        }

        $this->materialiser->materialise(
            $this->materialiser->chainFor($parent),
            $this->materialiser->chainFor($child)
        );
    }

    public function rename(int $id, string $name): Node
    {
        $node    = $this->nodes->byId($id);
        $renamed = $node->renamedTo($name);

        // Same instance means nothing changed, and an unchanged save does not raise the
        // version (D-282) — so there is nothing to write and nothing to log either.
        if ($renamed === $node) {
            return $node;
        }

        $this->nodes->save($renamed, $node->version);
        $this->changelog->record($id, 'node', 'renamed', $this->state($node), $this->state($renamed));

        return $renamed;
    }

    /**
     * Give a node an attribute by pointing it at a target. **The kind is not a parameter.**
     *
     * ⚠️ **An attribute *is* a relation** (D-031) — two names for one thing, seen from the node
     * that owns it. And its **kind is read off the branch the target sits in** (D-161), never
     * chosen: the author picks *what* the attribute points at, and composition or aggregation
     * follows.
     *
     * ```mermaid
     * flowchart LR
     *   T["the target's branch"] -->|decides| K["the kind"]
     *   T -->|decides| D["whether it holds data"]
     *   T -->|decides| S["where a value is stored"]
     * ```
     *
     * **That is what removes the error the storage rule exists to prevent** — a supplier
     * accidentally composed into an order, so every order breeds its own supplier — not by
     * catching it afterwards but by never offering it.
     */
    /**
     * Copy a node beside itself — **the node, not its subtree and not its records.**
     *
     * The owner asked for it three times, the last one bluntly: *duplicating `my_int` does not work,
     * no button in the tree nor in the head of the settings.*
     *
     * ```mermaid
     * flowchart LR
     *   N["the node"] --> C["a sibling copy"]
     *   S["its own settings"] --> C
     *   A["its own attribute declarations"] --> C
     *   K["its children"] -.->|not copied| C
     *   R["its records"] -.->|not copied| C
     * ```
     *
     * ⚠️ **Nothing in the concept covered this**, so the scope is stated here rather than assumed:
     * every mention of «duplicate» in `docs/NewConcept/` is about duplicate **detection**
     * ([D-167](../../../docs/NewConcept/90-decision-log.md)), which is a different thing entirely.
     *
     * ⚠️ **Why the subtree is left out.** A copy of `Electronic Parts` that silently brought forty
     * descendants along is not a duplicate, it is an import — and the person who wanted *this node,
     * like that one* now has forty nodes to park. *The narrow act composes: duplicate, then move
     * children in. The wide one does not decompose.*
     *
     * ⚠️ **Why records are left out.** A record belongs to the model it was written against
     * ([D-060](../../../docs/NewConcept/90-decision-log.md)) — copying twenty of them onto a new model
     * would invent twenty facts nobody entered.
     *
     * ⚠️ **What *does* come along, and why each.** Its **own settings**, because a copy that resolves
     * differently from its original is not a copy. Its **own attribute declarations**, as new edges —
     * an attribute is an edge owned by the node ([D-031](../../../docs/NewConcept/90-decision-log.md)),
     * so there is nothing to share and a copy either declares its own or declares none. *Inherited
     * attributes are not copied because they were never here: the copy is a sibling, so it inherits
     * exactly what the original inherits.*
     *
     * ⚠️ *Names need no trick — [D-022](../../../docs/NewConcept/90-decision-log.md) makes them
     * explicitly **not unique**, so the copy simply carries the same name and the person renames it.*
     */
    public function duplicate(int $nodeId): Node
    {
        $node = $this->nodes->byId($nodeId);

        // ⚠️ **The machinery's own nodes are not copyable** ([D-194]): a second `Trash` or a second
        // `Primitives` would give the framework two places to look and one of them would be wrong.
        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        // ⚠️ *The root has no parent, so a copy would have nowhere to be a sibling of.*
        $parentId = $node->parentId()
            ?? throw NodeIsProtected::named($node->name);

        $copy = $this->createNode($node->name, $parentId);

        // ⚠️ **Its own declarations only**, which is what `ownAttribute()` already distinguishes: an
        // inherited attribute belongs to an ancestor and the copy inherits it too, by sitting where it
        // sits.
        foreach ($this->attributesOf($node->id) as $edge) {
            // ⚠️ **`fromId` is what «own» means** — the same test {@see ownAttribute()} makes. An
            // inherited edge belongs to an ancestor, and the copy inherits it by sitting where it
            // sits; declaring it again would give the subtree the same attribute twice.
            if ($edge->fromId !== $node->id) {
                continue;
            }

            $this->addAttribute($copy->id, $edge->toId, $edge->name);
        }

        $this->copySettings($node->id, $copy->id);
        $this->copyLabels($node->id, $copy->id);

        return $copy;
    }

    /**
     * Copy one attribute beside itself — **and the name has to come in**, unlike a node's copy.
     *
     * The owner: *duplicate for the attribute is missing too.*
     *
     * ⚠️ **A node's copy may carry the same name and an attribute's may not.**
     * [D-022](../../../docs/NewConcept/90-decision-log.md) makes node names explicitly *not unique*,
     * so `duplicate()` reuses one. But [D-281](../../../docs/NewConcept/90-decision-log.md) refuses a
     * **duplicate edge** — same `from`, `kind`, `to` **and name** — because *`Breite` and `Höhe` both
     * reach `int` and are two different things; the name is part of what makes an edge itself.* **So a
     * copy with the same name is not a copy, it is the same edge, and the core refuses it.**
     *
     * ⚠️ **Which is why the name is a parameter and not derived here.** Inventing «Breite 2» would be
     * the core writing user-visible content, and a suffix like *(copy)* is a translatable string that
     * belongs at the boundary (`AR-2`, `CD-1`). *The surface knows what «copy» is called; this does
     * not.*
     *
     * ⚠️ **Its own settings travel with it**, for the same reason a node's do: a copy that resolves
     * differently from its original is not a copy. *Its labels do not, because an edge owns none —
     * measured 2026-08-26: 17 edges carry a name and zero labels belong to an edge
     * ([OQ-095](../../../docs/NewConcept/91-open-questions.md)).*
     */
    public function duplicateAttribute(int $ownerId, int $edgeId, string $name): Relation
    {
        // ⚠️ **`ownAttribute()` and not `attributesOf()`**: an inherited attribute belongs to the
        // ancestor that declared it, and copying it from a descendant would put a second declaration
        // in a place that never had the first ([D-376](../../../docs/NewConcept/90-decision-log.md)
        // refuses renaming for the same reason).
        $edge = $this->ownAttribute($ownerId, $edgeId);

        $copy = $this->addAttribute($ownerId, $edge->toId, $name);

        $this->copySettings($edge->id, $copy->id);

        return $copy;
    }

    /**
     * The original's **own** settings, onto the copy.
     *
     * ⚠️ **Own, not resolved** — and the difference is the whole point. Copying what the original
     * *resolves* would freeze its ancestors' answers into the copy, so a later change above would
     * reach the original and not the copy. *Copying only what it holds keeps both of them children
     * of the same parent, which is what a sibling copy is.*
     *
     * ⚠️ *Written straight to the repository rather than through {@see \Taxmod\Core\Service\Settings}:
     * the values were already accepted once at this exact place in the chain, so re-running the
     * bounds checks would refuse nothing and could refuse something — a bound the original was
     * narrowed **to** is not a widening for the copy.*
     */
    private function copySettings(int $fromId, int $toId): void
    {
        if ($this->settings === null) {
            return;
        }

        foreach ($this->settings->ownedBy($fromId) as $one) {
            $this->settings->put(new Setting($toId, $one->key, $one->value));
        }
    }

    /**
     * The original's labels, onto the copy — every role, every locale.
     *
     * ⚠️ *The owner spotted the settings half through the **icon**; labels are the same argument.
     * A copy whose name reads differently in German than its original is not a copy either.*
     */
    private function copyLabels(int $fromId, int $toId): void
    {
        if ($this->labels === null) {
            return;
        }

        foreach ($this->labels->forOwners([$fromId]) as $one) {
            $this->labels->put(new Label($toId, $one->path, $one->roleId, $one->number, $one->locale, $one->text));
        }
    }
    public function addAttribute(int $ownerId, int $targetId, string $name): Relation
    {
        $owner  = $this->nodes->byId($ownerId);
        $target = $this->nodes->byId($targetId);

        $branch = $this->framework->branchOf($target)
            ?? throw NotAPossibleTarget::itSitsInNoBranch($target->name);

        // D-238: everything **but** the branch root is selectable. The root stands for the
        // branch itself, not for a thing in it.
        if ($target->id === $this->framework->rootOf($branch)->id) {
            throw NotAPossibleTarget::itIsABranchRoot($target->name);
        }

        if ($target->isDescendantOf($this->framework->trash())) {
            throw NotAPossibleTarget::itIsInTheTrash($target->name);
        }

        $edge = Relation::attribute(
            $this->identities->next(),
            $owner->id,
            $target->id,
            $branch->relationKind(),
            $name,
            $this->relations->nextAttributePositionUnder($owner->id)
        );

        $this->relations->add($edge);
        $this->changelog->record(
            $edge->id,
            'relation',
            'attribute added',
            null,
            sprintf('%s: %s → %s (%s)', $owner->name, $edge->name, $target->name, $edge->kind->value)
        );

        // ⚠️ **The target's settings are written into the attribute** ([D-423](../../../docs/NewConcept/90-decision-log.md)).
        // The owner: *when an attribute is created, **all** settings of the node are taken into the
        // attribute and can be changed there.* **The source is the *target*, not the owner** — the
        // type is what an attribute is configured like, and it is also what its `reset` pulls from,
        // so creation and reset agree by construction.
        if ($this->materialiser !== null) {
            $this->materialiser->materialise(
                $this->materialiser->chainFor($target),
                $this->materialiser->chainForUseSite($edge)
            );
        }

        return $edge;
    }

    /**
     * A node's attributes: its own, and every one it inherits.
     *
     * ⚠️ **Inheritance is why this takes the ancestors in one go.** The tree *is* inheritance
     * (D-041), so a node carries what its ancestors declare; asking per level would be the walk
     * `CD-7` forbids, and the path already holds the list.
     *
     * @return list<Relation>
     */
    public function attributesOf(int $nodeId): array
    {
        $node = $this->nodes->byId($nodeId);

        return $this->relations->attributeEdgesOf([...$node->ancestorIds(), $node->id]);
    }

    /** Hang a node under a different parent, taking everything below it along. */
    public function move(int $id, int $newParentId): Node
    {
        return $this->reparent($id, $this->nodes->byId($newParentId), 'moved');
    }

    /**
     * Park a node and everything under it.
     *
     * ⚠️ **The old path is written to the changelog and nowhere else.** That is what a restore
     * reads, and it is why the node itself needs no `parked_from` column — one place owns each
     * fact.
     */
    public function moveToTrash(int $id): Node
    {
        return $this->reparent($id, $this->framework->trash(), 'parked');
    }

    /**
     * Park **only** this node; its children are hung on its parent.
     *
     * ⚠️ **Not the harmless half of the choice** ([U4](../../../docs/NewConcept/20-interaction.md)).
     * The tree is inheritance (D-041), so children reattached to the grandparent **lose whatever
     * they inherited from the node being removed**. That is D-155's move reached through a
     * different button, and it is why deleting asks rather than guesses.
     */
    public function moveToTrashPromotingChildren(int $id): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge        = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $grandparent = $this->nodes->byId($edge->fromId);

        // ⚠️ **One row per promoted child, not one row saying *the children moved*** (D-348).
        // A restore has to know **which** child went **where** to put it back, and *these
        // children moved* is not an answer. Read before the move, written in one statement.
        $promoted = [];

        foreach ($this->relations->childEdgesOf($id) as $childEdge) {
            $child = $this->nodes->find($childEdge->toId);

            if ($child === null) {
                continue;
            }

            $promoted[] = [
                'ownerId'   => $child->id,
                'ownerKind' => 'node',
                'what'      => 'promoted',
                'before'    => $child->path,
                'after'     => $grandparent->path . '.' . $child->id,
            ];
        }

        // Both halves in one statement each: the edges repoint together, and the paths of every
        // descendant are rewritten by dropping this node out of the middle of them. Done child
        // by child, either would be a write per row (`CD-7`).
        $this->relations->reparentChildEdges($id, $grandparent->id, $this->relations->nextPositionUnder($grandparent->id));
        $this->nodes->moveSubtree($node->path, $grandparent->path);

        // The bracket opens here and the parking joins it, so both are one act (D-348).
        $group = $this->changelog->recordMany($promoted);

        if ($promoted === []) {
            $group = null;
        }

        // The node is childless now, so parking it is the ordinary move.
        return $this->reparent($id, $this->framework->trash(), 'parked', $group);
    }

    /** Put a node at a different place among its siblings. Order lives on the edge, not the node. */
    public function reorder(int $id, int $position): void
    {
        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $moved = $edge->movedTo(max(0, $position));

        if ($moved === $edge) {
            return;
        }

        $this->relations->save($moved, $edge->version);
        $this->changelog->record($id, 'node', 'reordered', (string) $edge->position, (string) $moved->position);
    }

    /**
     * Put a parked node back where it came from, with everything under it.
     *
     * ⚠️ **This is what makes the trash a trash rather than a graveyard.** *Undo reaches
     * exactly as far as the trash* (D-172), and without a way back the first stage of the
     * two-stage deletion buys nothing.
     *
     * **Where it came from is read out of the changelog and nowhere else** — no `parked_from`
     * column, because one place owns each fact (D-123, D-065).
     */
    public function restore(int $id): RestoreResult
    {
        $node  = $this->nodes->byId($id);
        $trash = $this->framework->trash();

        if (! $node->isDescendantOf($trash)) {
            throw CannotRestore::itWasNeverParked($node->name);
        }

        $was = $this->changelog->pathBeforeLastParking($id);

        if ($was === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        $segments = explode('.', $was);
        array_pop($segments);
        $parent = $segments === [] ? null : $this->nodes->find((int) end($segments));

        if ($parent === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        // Restoring into the trash would look like success and change nothing.
        if ($parent->id === $trash->id || $parent->isDescendantOf($trash)) {
            throw CannotRestore::theOldPlaceIsAlsoParked($node->name, $parent->name);
        }

        // The whole act comes back, not the row (D-347). The bracket is what makes *the whole
        // act* nameable at all (D-348) — without it there is only a list of unrelated lines.
        $act      = $this->changelog->actAround($id, 'parked');
        $restored = $this->reparent($id, $parent, 'restored');

        $back = [];
        $left = [];

        foreach ($act as $row) {
            if ($row['what'] !== 'promoted') {
                continue;
            }

            $child = $this->nodes->find($row['ownerId']);

            // ⚠️ Untouched means *still exactly where the promotion put it*. Anything else is a
            // newer decision by a person, and it wins.
            if ($child === null || $child->path !== $row['after']) {
                if ($child !== null) {
                    $left[] = $child->name;
                }

                continue;
            }

            $this->reparent($child->id, $restored, 'restored');
            $back[] = $child->name;
        }

        return new RestoreResult($restored, $back, $left);
    }

    /** Swap a node with the sibling before it. Does nothing if it is already first. */
    public function moveUp(int $id): void
    {
        $this->swapWithNeighbour($id, -1);
    }

    /** Swap a node with the sibling after it. Does nothing if it is already last. */
    public function moveDown(int $id): void
    {
        $this->swapWithNeighbour($id, 1);
    }


    /** The node with this id, or null. Used by surfaces that may be handed a stale link. */
    /**
     * The nodes a list of attributes points at, in one query (`CD-7`).
     *
     * @param  list<Relation>   $edges
     * @return array<int, Node> Keyed by node id.
     */
    public function targetsOf(array $edges): array
    {
        return $this->nodes->byIds(array_map(static fn (Relation $edge): int => $edge->toId, $edges));
    }

    /**
     * One of a node's **own** attributes, by edge id.
     *
     * ⚠️ **Ownership is checked here rather than trusted from the request.** An inherited edge
     * belongs to an ancestor, and writing to it would change it for every sibling too.
     *
     * @throws \Taxmod\Core\Exception\NotAPossibleTarget
     */
    public function ownAttribute(int $ownerId, int $edgeId): Relation
    {
        foreach ($this->attributesOf($ownerId) as $edge) {
            if ($edge->id === $edgeId && $edge->fromId === $ownerId) {
                return $edge;
            }
        }

        throw NotAPossibleTarget::notAnOwnAttribute($edgeId);
    }

    /**
     * Remove an attribute — **parked, not purged**, and under one bracket.
     *
     * ⚠️ **This was missing since Package 3, and the reason was storage rather than reluctance:**
     * `relations` had nowhere to record that an edge was gone. [D-371](../../../docs/NewConcept/90-decision-log.md)
     * gives it `parked_by_group_id` — the **act** that parked it, not a bare flag, so
     * [D-128](../../../docs/NewConcept/90-decision-log.md)'s *deleted with «X»* has something to
     * name.
     *
     * ⚠️ **Two stages, as everywhere** ([D-123](../../../docs/NewConcept/90-decision-log.md)):
     * parking is reversible and purging is a separate act. **So nothing else is touched** — the
     * settings written at this use site stay, and so do the values records hold through it. *That is
     * not laziness: [D-156](../../../docs/NewConcept/90-decision-log.md) observes that the trash
     * preserves exactly the information a later decision needs, and deleting the overrides here
     * would throw away what a restore has to put back.*
     *
     * ⚠️ **One changelog row, one group** ([D-348](../../../docs/NewConcept/90-decision-log.md)).
     * Today the act is a single row, so the bracket is the row's own id — which is exactly what that
     * decision prescribes, and what makes the bracket cost nothing.
     */
    public function removeAttribute(int $ownerId, int $edgeId): Relation
    {
        // ⚠️ **The parked ones are looked at too, and that is not tidiness.** Once parked, an edge
        // leaves the live list (D-128), so a second click — a double tap, a back button, a stale
        // form — would otherwise be refused with *not one this node owns*, which is both wrong and
        // confusing. It **is** owned; it is already gone. So the act is idempotent.
        foreach ($this->relations->parkedAttributeEdgesOf([$ownerId]) as $already) {
            if ($already->id === $edgeId) {
                return $already;
            }
        }

        $edge = $this->ownAttribute($ownerId, $edgeId);

        $group = $this->changelog->record(
            $edge->id,
            'relation',
            'attribute removed',
            $this->edgeState($edge),
            $this->edgeState($edge->parkedBy(0))
        );

        $parked = $edge->parkedBy($group);

        $this->relations->save($parked, $edge->version);

        return $parked;
    }

    /**
     * Put a removed attribute back.
     *
     * ⚠️ **A new change written forwards, never a rewind** ([D-172](../../../docs/NewConcept/90-decision-log.md)):
     * history is extended, because the changelog is also the migration script
     * ([D-061](../../../docs/NewConcept/90-decision-log.md)).
     */
    public function restoreAttribute(int $ownerId, int $edgeId): Relation
    {
        foreach ($this->relations->parkedAttributeEdgesOf([$ownerId]) as $edge) {
            if ($edge->id !== $edgeId) {
                continue;
            }

            $revived = $edge->revived();

            $this->changelog->record(
                $edge->id,
                'relation',
                'attribute restored',
                $this->edgeState($edge),
                $this->edgeState($revived)
            );

            $this->relations->save($revived, $edge->version);

            return $revived;
        }

        throw NotAPossibleTarget::notAnOwnAttribute($edgeId);
    }

    /**
     * Rename an attribute.
     *
     * ⚠️ **Only where it is declared.** An inherited attribute belongs to the ancestor that
     * declared it, so renaming it from a descendant would rename it for every other user too —
     * silently. {@see ownAttribute()} refuses that, which is the same guard removal uses.
     */
    public function renameAttribute(int $ownerId, int $edgeId, string $name): Relation
    {
        $edge    = $this->ownAttribute($ownerId, $edgeId);
        $renamed = $edge->renamedTo($name);

        $this->changelog->record(
            $edge->id,
            'relation',
            'attribute renamed',
            $this->edgeState($edge),
            $this->edgeState($renamed)
        );

        $this->relations->save($renamed, $edge->version);

        return $renamed;
    }

    /** @return list<Relation> The removed attributes of one node — D-128's *show deleted*. */
    public function removedAttributesOf(int $ownerId): array
    {
        return $this->relations->parkedAttributeEdgesOf([$ownerId]);
    }

    /** What a changelog row records about an edge. */
    private function edgeState(Relation $edge): string
    {
        return 'name=' . $edge->name
            . ' to=' . $edge->toId
            . ' kind=' . $edge->kind->value
            . ' parked=' . ($edge->parkedByGroup ?? 0);
    }

    public function find(int $id): ?Node
    {
        return $this->nodes->find($id);
    }

    /** @return list<Node> */
    public function childrenOf(int $parentId): array
    {
        return $this->nodes->childrenOf($this->nodes->byId($parentId));
    }

    /**
     * Exchange two neighbouring edges' positions.
     *
     * ⚠️ **A swap, not a renumbering.** Reordering by rewriting every sibling would be a write
     * per row, which is the loop `CD-7` forbids; a swap is always exactly two, however many
     * siblings there are.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        $edge     = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $siblings = $this->relations->childEdgesOf($edge->fromId);

        $here = null;

        foreach ($siblings as $index => $sibling) {
            if ($sibling->id === $edge->id) {
                $here = $index;
                break;
            }
        }

        $there = $here + $direction;

        if ($here === null || ! isset($siblings[$there])) {
            return;
        }

        $other = $siblings[$there];

        // Positions may be equal — nothing forbids it, and the list then falls back to id
        // order. Swapping equal numbers would move nothing, so they are forced apart.
        $mine  = $edge->position;
        $yours = $other->position;

        if ($mine === $yours) {
            $mine  = $here;
            $yours = $there;
        }

        $this->relations->save($edge->movedTo($yours), $edge->version);
        $this->relations->save($other->movedTo($mine), $other->version);

        $this->changelog->record($id, 'node', 'reordered', (string) $here, (string) $there);
    }

    /**
     * Change which parent an edge points at, then bring the paths along.
     *
     * ⚠️ **The order is not arbitrary.** The edge is the truth, so it moves first; the paths of
     * the node and everything under it are rewritten from it afterwards, in one statement
     * rather than one per descendant (`CD-7`).
     */
    private function reparent(int $id, Node $newParent, string $verb, ?int $changeGroup = null): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();

        // A node dropped onto its own descendant would cut its whole subtree out of the tree,
        // silently. The path already answers this — that is what a materialised path is for.
        if ($newParent->id === $node->id || $newParent->isDescendantOf($node)) {
            throw ImpossibleMove::intoItsOwnDescendant($node->name);
        }

        $moved = $node->movedUnder($newParent->path);

        if ($moved === $node) {
            return $node;
        }

        $this->relations->save(
            $edge->reparentedTo($newParent->id, $this->relations->nextPositionUnder($newParent->id)),
            $edge->version
        );

        $this->nodes->save($moved, $node->version);
        $this->nodes->moveSubtree($node->path, $moved->path);
        $this->changelog->record($id, 'node', $verb, $this->state($node), $this->state($moved), $changeGroup);

        return $moved;
    }

    /**
     * What the changelog freezes about a node at one moment.
     *
     * Deliberately the four fixed attributes and nothing else — the changelog records what the
     * object *was*, and a node is exactly those four things.
     */
    private function state(Node $node): string
    {
        return sprintf('id=%d version=%d name=%s path=%s', $node->id, $node->version, $node->name, $node->path);
    }
}
