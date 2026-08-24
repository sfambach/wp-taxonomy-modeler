<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Exception\InvalidName;

/**
 * A node — the one kind of thing the model is built from.
 *
 * Exactly four fixed attributes and no more (sentence 3 of the core on one page). Everything
 * else a node appears to have is a **relation** seen from the node that owns it (D-031), and a
 * node's **type** is its position in the inheritance tree, not a column (D-041, D-042).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class Node
{
    /**
     * @param int    $id      From the model identity space, shared with relations (C11).
     *                        Meaningless, stable, never resolved on.
     * @param int    $version Raised on every change that actually changed something (D-282).
     * @param string $name    Required, deliberately **not** unique (D-022).
     * @param string $path    Materialised ancestor path, ids separated by `.`, own id last.
     *                        **Derived** and rebuildable — never a second truth (D-014).
     */
    private function __construct(
        public readonly int $id,
        public readonly int $version,
        public readonly string $name,
        public readonly string $path,
    ) {
    }

    /**
     * Rebuild a node from what storage holds. No validation beyond the name — storage is
     * trusted, input is not.
     */
    public static function fromStorage(int $id, int $version, string $name, string $path): self
    {
        return new self($id, $version, $name, $path);
    }

    /**
     * A node as it is first created: version 1, and its path decided by its parent.
     *
     * @param string|null $parentPath Null for a root, whose path is its own id.
     */
    public static function create(int $id, string $name, ?string $parentPath): self
    {
        $name = self::cleanName($name);

        return new self(
            $id,
            1,
            $name,
            $parentPath === null ? (string) $id : $parentPath . '.' . $id,
        );
    }

    /**
     * The same node under a different name, one version on.
     *
     * ⚠️ Returns the **same** instance when nothing changed, so an unchanged save cannot raise
     * the version (D-282). Callers compare identity, not equality.
     */
    public function renamedTo(string $name): self
    {
        $name = self::cleanName($name);

        if ($name === $this->name) {
            return $this;
        }

        return new self($this->id, $this->version + 1, $name, $this->path);
    }

    /**
     * The same node moved under a new parent, one version on.
     *
     * The path of every descendant changes with it; that is the repository's job, because it is
     * one statement in the database and would be N+1 here (`CD-7`).
     */
    public function movedUnder(?string $parentPath): self
    {
        $path = $parentPath === null ? (string) $this->id : $parentPath . '.' . $this->id;

        if ($path === $this->path) {
            return $this;
        }

        return new self($this->id, $this->version + 1, $this->name, $path);
    }

    /**
     * The ids of this node's ancestors, nearest last, without the node itself.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = array_map(intval(...), explode('.', $this->path));
        array_pop($ids);

        return $ids;
    }

    public function parentId(): ?int
    {
        $ancestors = $this->ancestorIds();

        return $ancestors === [] ? null : (int) end($ancestors);
    }

    public function isDescendantOf(self $other): bool
    {
        return str_starts_with($this->path, $other->path . '.');
    }

    /**
     * Trim what a person cannot see, then refuse what is left only if it is nothing.
     *
     * The trimming is deliberate: a trailing space is a mistake nobody makes on purpose and
     * every later comparison would carry it. Refusing it instead would be a needless error.
     */
    private static function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidName::empty();
        }

        return $name;
    }
}
