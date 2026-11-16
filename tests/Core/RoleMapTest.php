<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\RoleMissing;
use Taxmod\Core\Role\RoleMap;

/** D-1394: one role table; the bundled ids complete only the site they belong to. */
final class RoleMapTest extends TestCase
{
    private const BUNDLED = ['nodes' => ['board_revision' => 105262, 'lager' => 110147], 'fields' => ['label' => 103839, 'revision_layers' => 110169]];

    public function testTheSameSiteIsCompletedByTheBundledRoles(): void
    {
        $roles = RoleMap::completed(['nodes' => ['lager' => 110147]], self::BUNDLED);

        self::assertSame(105262, $roles->node('board_revision'));
        self::assertSame(110169, $roles->field('revision_layers'));
    }

    public function testAnotherSiteKeepsItsOwnRoles(): void
    {
        $roles = RoleMap::completed(['nodes' => ['lager' => 7]], self::BUNDLED);

        self::assertSame(7, $roles->node('lager'));
        self::assertNull($roles->find('nodes', 'board_revision'));
    }

    public function testAnEmptySiteTakesNothingItCannotProve(): void
    {
        self::assertTrue(RoleMap::completed([], self::BUNDLED)->isEmpty());
    }

    public function testAMissingRoleIsSaidByName(): void
    {
        $this->expectException(RoleMissing::class);
        $this->expectExceptionMessage('fields.revision_layers');

        RoleMap::fromArray([])->field('revision_layers');
    }

    public function testOnlyWellFormedNamesAndIdsAreKept(): void
    {
        $roles = RoleMap::fromArray(['nodes' => ['Bad Name' => 1, 'ok' => '5', 'zero' => 0, 'text' => 'x'], 'other' => ['x' => 1]]);

        self::assertSame(['nodes' => ['ok' => 5], 'fields' => [], 'values' => []], $roles->toArray());
    }

    public function testMissingListsOnlyRequiredRolesWithoutId(): void
    {
        $roles = RoleMap::fromArray(self::BUNDLED);

        self::assertSame([['module' => 'jlcpcb', 'group' => 'fields', 'key' => 'revision_surface']], $roles->missing([
            ['module' => 'jlcpcb', 'group' => 'fields', 'key' => 'revision_layers', 'required' => true],
            ['module' => 'jlcpcb', 'group' => 'fields', 'key' => 'revision_surface', 'required' => true],
            ['module' => 'jlcpcb', 'group' => 'fields', 'key' => 'revision_note', 'required' => false],
        ]));
    }

    public function testTheBundledFileHoldsNoRoleTwice(): void
    {
        $bundled = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/roles-live.json'), true);

        self::assertIsArray($bundled);
        self::assertSame(RoleMap::fromArray($bundled)->toArray(), array_map(static fn (array $ids): array => array_map('intval', $ids), $bundled));
    }
}
