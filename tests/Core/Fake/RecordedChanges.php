<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Repository\Changelog;

/** Keeps what was logged so a test can assert that an unchanged save wrote nothing. */
final class RecordedChanges implements Changelog
{
    /** @var list<array{int,string,string,?string,?string,int}> */
    public array $entries = [];

    private int $lastRow = 0;

    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
        ?int $changeGroupId = null,
    ): int {
        // The row that opens an act becomes its own group, exactly as the SQL one does.
        $row   = ++$this->lastRow;
        $group = $changeGroupId ?? $row;

        $this->entries[] = [$ownerId, $ownerKind, $what, $before, $after, $group];

        return $group;
    }


    public function recordMany(array $rows, ?int $changeGroupId = null): int
    {
        $group = $changeGroupId;

        foreach ($rows as $row) {
            $group = $this->record(
                $row['ownerId'],
                $row['ownerKind'],
                $row['what'],
                $row['before'],
                $row['after'],
                $group
            );
        }

        return $group ?? 0;
    }

    public function actAround(int $ownerId, string $what): array
    {
        $group = null;

        foreach ($this->entries as [$id, , $verb, , , $g]) {
            if ($id === $ownerId && $verb === $what) {
                $group = $g;
            }
        }

        if ($group === null) {
            return [];
        }

        $rows = [];

        foreach ($this->entries as [$id, , $verb, $before, $after, $g]) {
            if ($g === $group) {
                $rows[] = ['ownerId' => $id, 'what' => $verb, 'before' => $before, 'after' => $after];
            }
        }

        return $rows;
    }

    public function pathBeforeLastParking(int $ownerId): ?string
    {
        foreach (array_reverse($this->entries) as [$id, , $what, $before]) {
            if ($id === $ownerId && $what === 'parked' && $before !== null) {
                $at = strrpos($before, ' path=');

                return $at === false ? null : substr($before, $at + 6);
            }
        }

        return null;
    }

    /** @return list<string> */
    public function verbsFor(int $ownerId): array
    {
        $verbs = [];

        foreach ($this->entries as [$id, , $what]) {
            if ($id === $ownerId) {
                $verbs[] = $what;
            }
        }

        return $verbs;
    }

    /** The groups the rows of one owner fall into, in order. @return list<int> */
    public function groupsFor(int $ownerId): array
    {
        $groups = [];

        foreach ($this->entries as [$id, , , , , $group]) {
            if ($id === $ownerId) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** Everything written under one bracket. @return list<array{int,string}> owner id and verb */
    public function group(int $groupId): array
    {
        $rows = [];

        foreach ($this->entries as [$id, , $what, , , $group]) {
            if ($group === $groupId) {
                $rows[] = [$id, $what];
            }
        }

        return $rows;
    }
}
