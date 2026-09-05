<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\ChangeSummary;
use Taxmod\Core\Model\FrozenState;
use Taxmod\Core\Repository\Changelog;

/** Keeps what was logged so a test can assert that an unchanged save wrote nothing. */
final class RecordedChanges implements Changelog
{
    /** @var list<array{int,string,string,?string,?string,int,?int}> owner, kind, verb, before, after, group, version */
    public array $entries = [];

    private int $lastRow = 0;

    /**
     * ⚠️ **The bracket is mirrored here on purpose, and a fake that did not would be worse than
     * none.** *`recordMany()` once wrote a whole act into group zero against the real database while
     * this double reported it fine — the comment in {@see \Taxmod\WordPress\Persistence\WpdbChangelog}
     * still records that day. **A double that cannot reproduce the mechanism cannot fail for it.***
     */
    private int $depth = 0;

    private ?int $openAct = null;

    public function beginAct(): void
    {
        ++$this->depth;
    }

    public function endAct(): void
    {
        if ($this->depth > 0) {
            --$this->depth;
        }

        if ($this->depth === 0) {
            $this->openAct = null;
        }
    }

    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
        /**
         * ⚠️ *Der Doppelgänger nimmt sie entgegen und **behält sie**, statt sie zu schlucken: sonst
         * könnte ein Kerntest nicht zeigen, dass ein Schreibweg sie überhaupt mitgibt
         * ([D-536](../../../docs/NewConcept/90-decision-log.md)). **Ohne Vorgabewert wie das
         * Original** ([D-634](../../../docs/NewConcept/90-decision-log.md)) — ein Doppelgänger, der
         * das Weglassen erlaubt, verdeckt genau den Fehler, um den es geht.*
         */
        ?int $version,
        ?int $changeGroupId = null,
    ): int {
        $changeGroupId ??= $this->openAct;

        // The row that opens an act becomes its own group, exactly as the SQL one does.
        $row   = ++$this->lastRow;
        $group = $changeGroupId ?? $row;

        if ($this->depth > 0) {
            $this->openAct ??= $group;
        }

        // ⚠️ *Die Version hinten angehängt, damit die Stellen 0 bis 5 bleiben, wo sie waren — Tests
        // greifen positionsweise zu.*
        $this->entries[] = [$ownerId, $ownerKind, $what, $before, $after, $group, $version];

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
                $row['version'] ?? null,
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

    /**
     * ⚠️ **Reads through {@see FrozenState}, like the real one.** *This method used to hold its own
     * copy of `strrpos(' path=')` — a second reader for the same format, in the double that is
     * supposed to prove the format works. A double that parses differently from the thing it stands
     * in for can be green while the format is broken.*
     */
    public function pathBeforeLastParking(int $ownerId): ?string
    {
        foreach (array_reverse($this->entries) as [$id, , $what, $before]) {
            if ($id === $ownerId && $what === 'parked' && $before !== null) {
                return FrozenState::parse($before)?->field('path');
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

    /**
     * ⚠️ *Answers **nothing known** rather than inventing timestamps: the double has no clock, and a
     * fabricated birthday in a test is a fact nobody can trace back to a decision.*
     */
    public function summaryOf(int $ownerId): ChangeSummary
    {
        return new ChangeSummary();
    }
}
