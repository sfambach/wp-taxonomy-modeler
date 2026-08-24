<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * The root and the trash, created once and then found by their stored ids.
 *
 * ⚠️ **The ids live in options, not in the code.** An id is meaningless (sentence 2), so a
 * hard-coded `1` would be exactly the special-casing the code standard forbids — and it would
 * break the moment a second installation allocated differently.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SeededFrameworkNodes implements FrameworkNodes
{
    public const ROOT_OPTION  = 'taxmod_root_id';
    public const TRASH_OPTION = 'taxmod_trash_id';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly RelationRepository $relations,
        private readonly IdentityAllocator $identities,
        private readonly Changelog $changelog,
    ) {
    }

    public function root(): Node
    {
        return $this->nodes->byId((int) get_option(self::ROOT_OPTION, 0));
    }

    public function trash(): Node
    {
        return $this->nodes->byId((int) get_option(self::TRASH_OPTION, 0));
    }

    public function isProtected(Node $node): bool
    {
        return in_array(
            $node->id,
            [(int) get_option(self::ROOT_OPTION, 0), (int) get_option(self::TRASH_OPTION, 0)],
            true
        );
    }

    /**
     * Create what is missing. Safe to call on every activation — it adds, never replaces.
     *
     * ⚠️ **The names here are `name`, not labels.** Labels are per role and per locale and
     * arrive with Package 5; until then these two nodes carry a plain internal name, which is
     * what `name` is for.
     */
    public function seed(): void
    {
        $rootId = (int) get_option(self::ROOT_OPTION, 0);

        if ($rootId === 0 || $this->nodes->find($rootId) === null) {
            $root = Node::create($this->identities->next(), 'Root', null);
            $this->nodes->add($root);
            $this->changelog->record($root->id, 'node', 'created', null, 'the root');
            update_option(self::ROOT_OPTION, $root->id, true);
        } else {
            $root = $this->nodes->byId($rootId);
        }

        $trashId = (int) get_option(self::TRASH_OPTION, 0);

        if ($trashId === 0 || $this->nodes->find($trashId) === null) {
            $trash = Node::create($this->identities->next(), 'Trash', $root->path);

            $this->nodes->add($trash);
            // The trash is an ordinary child of the root, so it gets an ordinary inheritance
            // edge. A framework node that skipped the edge would be a node the tree cannot see.
            $this->relations->add(Relation::inheritance(
                $this->identities->next(),
                $root->id,
                $trash->id,
                $this->relations->nextPositionUnder($root->id)
            ));
            $this->changelog->record($trash->id, 'node', 'created', null, 'the trash');
            update_option(self::TRASH_OPTION, $trash->id, true);
        }
    }
}
