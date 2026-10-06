<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Repository\TypeNodes;

/**
 * The ids a seed wrote down, in memory.
 *
 * ⚠️ **It has no fallback and that is deliberate.** *The Notnagel of
 * [D-510](../../../docs/NewConcept/90-decision-log.md) reads node names and writes an option; it
 * belongs to the boundary and is measured there. A double that reimplemented it would be a test of
 * the double — and a lookup that quietly answered from a name here would hide the very thing these
 * tests exist to hold: **the binding is the id.***
 */
final class RememberedTypeNodes implements TypeNodes
{
    /** @var array<string,int> By {@see SimpleType::$value}. */
    private array $ids = [];

    public function nodeId(SimpleType $type): ?int
    {
        return $this->ids[$type->value] ?? null;
    }

    public function typeOf(int $nodeId): ?SimpleType
    {
        $value = array_search($nodeId, $this->ids, true);

        return $value === false ? null : SimpleType::from($value);
    }

    public function remember(SimpleType $type, int $nodeId): void
    {
        $this->ids[$type->value] = $nodeId;
    }
}
