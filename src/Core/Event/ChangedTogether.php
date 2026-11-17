<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * Several changes written as one act in one statement — what a node's parking does to the children it promotes.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class ChangedTogether
{
    private ?int $act = null;

    /**
     * @param list<array{ownerId: int, ownerKind: string, what: string, before: ?string, after: ?string, version: ?int}> $rows the chronicle's own shape
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?int $changeGroupId = null,
    ) {
    }

    public function settle(int $act): void
    {
        $this->act ??= $act;
    }

    public function act(): int
    {
        return $this->act ?? throw new \LogicException('Nobody wrote these changes into the chronicle.');
    }
}
