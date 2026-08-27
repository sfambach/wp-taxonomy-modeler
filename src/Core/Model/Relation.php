<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Renderer\Renderable;

use Taxmod\Core\Exception\InvalidName;

/**
 * An edge — and seen from the node that owns it, an **attribute** (D-031). Two names, one thing.
 *
 * ⚠️ **The kind is never chosen.** It is read off the branch the target sits in (sentence 5 of
 * the core on one page), which is why {@see inheritance()} is a named constructor and there is
 * no way to hand this class an arbitrary kind from a form.
 *
 * ⚠️ **`position` belongs here and not on the node**, because order is per parent: the same
 * node reached from two parents may sit third under one and first under the other.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Relation extends Identity implements Renderable
{
    /**
     * @param int    $id       From the model identity space, shared with nodes (C11) — which is
     *                         what lets an edge carry settings and labels of its own (C8).
     * @param string $name     Empty for an inheritance edge: the tree edge has no name of its
     *                         own, the child does.
     * @param int      $position     Order among the siblings of `fromId`, counted from zero.
     * @param int|null $parkedByGroup The act that parked it, or null while it is live.
     *
     * ⚠️ **An edge is parked by a column, and a node is not** ([D-371](../../../docs/NewConcept/90-decision-log.md)).
     * A node's **position** is its mark — it sits under the trash — so a flag would be the same fact
     * twice; an edge has no position in the tree, so there is nothing to duplicate. **It holds the
     * change group rather than a bare flag** because [D-128](../../../docs/NewConcept/90-decision-log.md)
     * wants a parked attribute labelled *deleted with «X»*, and the group is where that act is
     * written down ([D-348](../../../docs/NewConcept/90-decision-log.md)).
     */
    private function __construct(
        int $id,
        int $version,
        public readonly int $fromId,
        public readonly int $toId,
        public readonly RelationKind $kind,
        string $name,
        public readonly int $position,
        public readonly ?int $parkedByGroup = null,
    ) {
        // ⚠️ *Wie beim Knoten: die zwei gemeinsamen Felder wohnen bei {@see Identity}.*
        parent::__construct($id, $version, $name);
    }

    /**
     * The same edge under another name, one version on.
     *
     * ⚠️ **An attribute's name is trimmed like a node's, and an empty one is refused.** Nothing
     * decided that for edges — [D-022](../../../docs/NewConcept/90-decision-log.md) governs node
     * names — but *the use site is an attribute* argues they are the same kind of thing, and it is
     * the assumption Package 3 recorded rather than invented quietly.
     */
    public function renamedTo(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return new self(
            $this->id,
            $this->version + 1,
            $this->fromId,
            $this->toId,
            $this->kind,
            $name,
            $this->position,
            $this->parkedByGroup
        );
    }

    /** Whether it has been removed — parked, not purged (D-123's two stages). */
    public function isParked(): bool
    {
        return $this->parkedByGroup !== null;
    }

    /** The same edge, parked by one act. */
    public function parkedBy(int $changeGroup): self
    {
        return new self(
            $this->id,
            $this->version,
            $this->fromId,
            $this->toId,
            $this->kind,
            $this->name,
            $this->position,
            $changeGroup
        );
    }

    /** The same edge, live again — what a restore writes (D-172: forwards, never a rewind). */
    public function revived(): self
    {
        return new self(
            $this->id,
            $this->version,
            $this->fromId,
            $this->toId,
            $this->kind,
            $this->name,
            $this->position
        );
    }

    /** The tree edge: parent to child, and the only kind the tree is made of (V3). */
    public static function inheritance(int $id, int $parentId, int $childId, int $position): self
    {
        return new self($id, 1, $parentId, $childId, RelationKind::Inheritance, '', $position);
    }

    /**
     * An attribute edge: the owner points at a target, and the **kind comes from the caller
     * having read it off the target's branch** — never from a person choosing it (D-161).
     */
    public static function attribute(
        int $id,
        int $ownerId,
        int $targetId,
        RelationKind $kind,
        string $name,
        int $position,
    ): self {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return new self($id, 1, $ownerId, $targetId, $kind, $name, $position);
    }

    public static function fromStorage(
        int $id,
        int $version,
        int $fromId,
        int $toId,
        string $kind,
        string $name,
        int $position,
        ?int $parkedByGroup = null,
    ): self {
        return new self(
            $id,
            $version,
            $fromId,
            $toId,
            RelationKind::from($kind),
            $name,
            $position,
            $parkedByGroup
        );
    }

    /** The same edge pointing at a new parent, one version on. */
    public function reparentedTo(int $parentId, int $position): self
    {
        if ($parentId === $this->fromId && $position === $this->position) {
            return $this;
        }

        return new self($this->id, $this->version + 1, $parentId, $this->toId, $this->kind, $this->name, $position);
    }

    /** The same edge in a different place among its siblings, one version on. */
    public function movedTo(int $position): self
    {
        return $this->reparentedTo($this->fromId, $position);
    }

    /**
     * Was ein Leser fuer diese Kante liest — der Name des Attributs.
     *
     * ⚠️ *Noch der rohe Name. [D-105](../../../docs/NewConcept/90-decision-log.md) will eine Referenz
     * als **Label des Ziels** gezeichnet sehen, und [Zeile 21](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)
     * ist genau darum offen — **eine Methode ist, was das an einer Stelle behebbar macht.***
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * Leer: eine Kante haelt keinen Wert, sie sagt nur, wo einer hingehoert.
     */
    public function content(): string
    {
        return '';
    }
}
