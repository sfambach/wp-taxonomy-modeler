<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Repository\Changelog;

/** Keeps what was logged so a test can assert that an unchanged save wrote nothing. */
final class RecordedChanges implements Changelog
{
    /** @var list<array{int,string,string,?string,?string}> */
    public array $entries = [];

    public function record(int $ownerId, string $ownerKind, string $what, ?string $before, ?string $after): void
    {
        $this->entries[] = [$ownerId, $ownerKind, $what, $before, $after];
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
}
