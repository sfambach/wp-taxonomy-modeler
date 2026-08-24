<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * The nodes the engine stands on, created once and then found by their stored ids.
 *
 * ⚠️ **The ids live in options, not in the code.** An id is meaningless (sentence 2), so a
 * hard-coded `1` would be exactly the special-casing the code standard forbids — and it would
 * break the moment a second installation allocated differently.
 *
 * ```mermaid
 * flowchart TB
 *   R["Root"] --> M["Model"]
 *   R --> C["Compositions"]
 *   R --> P["Primitives"]
 *   R --> T["Trash"]
 *   P --> DT["Data Types"]
 *   P --> K["Constants"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SeededFrameworkNodes implements FrameworkNodes
{
    public const ROOT_OPTION  = 'taxmod_root_id';
    public const TRASH_OPTION = 'taxmod_trash_id';

    /** The reserved identity installation-wide settings hang on (OQ-039). Not a node. */
    private const INSTALLATION_OPTION = 'taxmod_installation_id';

    /** `Primitives` is a container that splits; the branches are the two nodes beneath it. */
    private const PRIMITIVES_OPTION = 'taxmod_primitives_id';

    /** @var array<string,string> */
    private const BRANCH_OPTIONS = [
        'model'        => 'taxmod_branch_model_id',
        'compositions' => 'taxmod_branch_compositions_id',
        'data-types'   => 'taxmod_branch_data_types_id',
        'constants'    => 'taxmod_branch_constants_id',
    ];

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

    public function rootOf(Branch $branch): Node
    {
        return $this->nodes->byId((int) get_option(self::BRANCH_OPTIONS[$branch->value], 0));
    }

    /**
     * ⚠️ **Null is a real answer, not a failure.** The root, the trash and `Primitives` itself
     * sit in no branch — and so does anything hung directly under `Primitives`, because the
     * concept splits it into `Data Types` and `Constants` and says nothing about the space
     * between. Refusing there is honest; guessing a branch would invent a rule.
     */
    public function branchOf(Node $node): ?Branch
    {
        foreach (Branch::cases() as $branch) {
            $root = $this->nodes->find((int) get_option(self::BRANCH_OPTIONS[$branch->value], 0));

            if ($root !== null && ($node->id === $root->id || $node->isDescendantOf($root))) {
                return $branch;
            }
        }

        return null;
    }


    public function installationId(): int
    {
        $id = (int) get_option(self::INSTALLATION_OPTION, 0);

        if ($id === 0) {
            // ⚠️ An identity with no node behind it. The foreign keys point at `identities`,
            // not at `nodes` (D-339), so a settings owner that is not a node is a first-class
            // thing rather than a hole in the schema.
            $id = $this->identities->next();
            update_option(self::INSTALLATION_OPTION, $id, true);
        }

        return $id;
    }

    public function isProtected(Node $node): bool
    {
        return in_array($node->id, $this->protectedIds(), true);
    }

    /**
     * Create what is missing. Safe to call on every activation — it adds, never replaces.
     *
     * ⚠️ **The names here are `name`, not labels.** Labels are per role and per locale and
     * arrive with Package 5; until then these nodes carry a plain internal name.
     */
    public function seed(): void
    {
        $root = $this->ensure(self::ROOT_OPTION, 'Root', null);

        $this->ensure(self::TRASH_OPTION, 'Trash', $root);
        $this->ensure(self::BRANCH_OPTIONS['model'], 'Model', $root);
        $this->ensure(self::BRANCH_OPTIONS['compositions'], 'Compositions', $root);

        $primitives = $this->ensure(self::PRIMITIVES_OPTION, 'Primitives', $root);

        $this->ensure(self::BRANCH_OPTIONS['data-types'], 'Data Types', $primitives);
        $this->ensure(self::BRANCH_OPTIONS['constants'], 'Constants', $primitives);
    }

    /** @return list<int> */
    private function protectedIds(): array
    {
        $ids = [
            (int) get_option(self::ROOT_OPTION, 0),
            (int) get_option(self::TRASH_OPTION, 0),
            (int) get_option(self::PRIMITIVES_OPTION, 0),
        ];

        foreach (self::BRANCH_OPTIONS as $option) {
            $ids[] = (int) get_option($option, 0);
        }

        return $ids;
    }

    /** Find the node an option points at, or make it under the given parent. */
    private function ensure(string $option, string $name, ?Node $parent): Node
    {
        $id       = (int) get_option($option, 0);
        $existing = $id === 0 ? null : $this->nodes->find($id);

        if ($existing !== null) {
            return $existing;
        }

        $node = Node::create($this->identities->next(), $name, $parent?->path);
        $this->nodes->add($node);

        if ($parent !== null) {
            // A framework node without an edge would be a node the tree cannot see.
            $this->relations->add(Relation::inheritance(
                $this->identities->next(),
                $parent->id,
                $node->id,
                $this->relations->nextPositionUnder($parent->id)
            ));
        }

        $this->changelog->record($node->id, 'node', 'created', null, 'framework: ' . $name);
        update_option($option, $node->id, true);

        return $node;
    }
}
