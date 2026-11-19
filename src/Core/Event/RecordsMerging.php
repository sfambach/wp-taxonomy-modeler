<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * One record is about to be merged into another (D-1480): a module that keeps records pointing at `from` answers here — a veto
 * with its reason, or ids of its own records that go to the trash because `into` has its own. The modeller applies the answers
 * in its own act, so one undo takes everything back; the module writes nothing (event concept §10: the result stands on the event).
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
final class RecordsMerging
{
    use EventId;

    /** @var list<string> */
    private array $vetoes = [];

    /** @var array<int, true> */
    private array $trash = [];

    public function __construct(
        public readonly int $from,
        public readonly int $into,
    ) {
    }

    public function veto(string $reason): void
    {
        $reason = trim($reason);

        if ($reason !== '') {
            $this->vetoes[] = $reason;
        }
    }

    public function trash(int ...$recordIds): void
    {
        foreach ($recordIds as $id) {
            if ($id > 0) {
                $this->trash[$id] = true;
            }
        }
    }

    /** @return list<string> */
    public function vetoes(): array
    {
        return $this->vetoes;
    }

    /** @return list<int> */
    public function trashed(): array
    {
        return array_keys($this->trash);
    }
}
