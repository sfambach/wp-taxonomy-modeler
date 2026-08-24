<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

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
final class Relation
{
    /**
     * @param int    $id       From the model identity space, shared with nodes (C11) — which is
     *                         what lets an edge carry settings and labels of its own (C8).
     * @param string $name     Empty for an inheritance edge: the tree edge has no name of its
     *                         own, the child does.
     * @param int    $position Order among the siblings of `fromId`, counted from zero.
     */
    private function __construct(
        public readonly int $id,
        public readonly int $version,
        public readonly int $fromId,
        public readonly int $toId,
        public readonly RelationKind $kind,
        public readonly string $name,
        public readonly int $position,
    ) {
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
    ): self {
        return new self($id, $version, $fromId, $toId, RelationKind::from($kind), $name, $position);
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
}
