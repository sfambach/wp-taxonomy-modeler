<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * The firms an order's «Plattform» may name once the field is a reference ([D-1487](../../../docs/NewConcept/90-decision-log.md)):
 * a source still says «AliExpress», the model keeps the firm record it means.
 *
 * ⚠️ *A name names a firm only when exactly one firm has it as its own word, case ignored — the rule of D-1484. None or two
 * and the name names nothing: the field stays empty rather than pointing at a guess.*
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class PlatformFirms
{
    /** @var array<string, int> lower-case word => firm id, only the unambiguous ones */
    private array $byWord = [];

    /** @param array<int, string> $words firm record id => its own word */
    public function __construct(private readonly array $words)
    {
        $seen = [];

        foreach ($words as $id => $word) {
            $key = self::key($word);

            if ($key !== '') {
                $seen[$key][] = (int) $id;
            }
        }

        foreach ($seen as $key => $ids) {
            if (count($ids) === 1) {
                $this->byWord[$key] = $ids[0];
            }
        }
    }

    public function idOf(string $name): ?int
    {
        return $this->byWord[self::key($name)] ?? null;
    }

    /** The firm's own word; `null` for an id that is no firm of the field. */
    public function wordOf(int $id): ?string
    {
        return $this->words[$id] ?? null;
    }

    /**
     * What both sides of a lookup are compared by: the firm's word for a name that names one, '' for one that names none —
     * an order whose field is empty is the one such a name was written to.
     */
    public function canonical(string $name): string
    {
        $id = $this->idOf($name);

        return $id === null ? '' : (string) $this->wordOf($id);
    }

    private static function key(string $word): string
    {
        return mb_strtolower(trim($word));
    }
}
