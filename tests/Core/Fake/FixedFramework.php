<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Repository\FrameworkNodes;

/** A root, a trash and the four branch roots, made once and protected. */
final class FixedFramework implements FrameworkNodes
{
    /**
     * @param array<string,Node> $branchRoots keyed by {@see Branch::value}
     */
    public function __construct(
        private readonly Node $root,
        private readonly Node $trash,
        private readonly array $branchRoots = [],
        private readonly int $installationId = 999000,
        /** @var array<string,int> */
        private readonly array $roleIds = [],
    ) {
    }

    public function root(): Node
    {
        return $this->root;
    }

    public function trash(): Node
    {
        return $this->trash;
    }

    public function rootOf(Branch $branch): Node
    {
        return $this->branchRoots[$branch->value];
    }

    public function branchOf(Node $node): ?Branch
    {
        foreach ($this->branchRoots as $value => $root) {
            if ($node->id === $root->id || $node->isDescendantOf($root)) {
                return Branch::from($value);
            }
        }

        return null;
    }



    public function roleId(SeededRole $role): int
    {
        return $this->roleIds[$role->value] ?? 0;
    }

    /** @var array<string, array{int, int}> */
    private array $settingRelations = [];

    /**
     * ⚠️ *Ohne einen Settings-Ast im Doppel gilt die alte Kette — die Regel greift nur, wo der Ast
     * existiert ([D-545](../../docs/NewConcept/90-decision-log.md)).*
     *
     * @return list<int>
     */
    public function inheritanceOwnersOf(Node $node): array
    {
        $kette = [...$node->ancestorIds(), $node->id];
        $ast   = $this->branchRoots[Branch::Settings->value] ?? null;

        if ($ast === null) {
            return $kette;
        }

        $wo = array_search($ast->id, $kette, true);

        return $wo === false ? $kette : array_values(array_slice($kette, (int) $wo));
    }

    public function settingRelationId(SettingKey $key): int
    {
        return $this->settingRelations[$key->value][0] ?? 0;
    }

    public function settingValueRelationId(SettingKey $key): int
    {
        return $this->settingRelations[$key->value][1] ?? 0;
    }

    public function rememberSettingRelations(SettingKey $key, int $relationId, int $valueRelationId): void
    {
        $this->settingRelations[$key->value] = [$relationId, $valueRelationId];
    }

    public function installationId(): int
    {
        return $this->installationId;
    }

    public function isProtected(Node $node): bool
    {
        $ids = [$this->root->id, $this->trash->id];

        foreach ($this->branchRoots as $root) {
            $ids[] = $root->id;
        }

        return in_array($node->id, $ids, true);
    }
}
