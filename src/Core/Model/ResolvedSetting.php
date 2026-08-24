<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * A setting after the walk: what it holds, **and where that came from**.
 *
 * ⚠️ **The origin is not decoration.** *Inherited* and *set here* must look different on screen
 * (D-266), and *inherited* and *deliberately nothing here* must look different again — the
 * second stops later changes at the base from arriving, and nobody can see that from the value.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ResolvedSetting
{
    public function __construct(
        public readonly string $key,
        public readonly TypedValue $value,
        /** The link of the chain that won — an installation, a node or an edge. */
        public readonly int $fromOwnerId,
        /** Whether the winning link is the one that was asked about. */
        public readonly bool $setHere,
    ) {
    }

    public function isInherited(): bool
    {
        return ! $this->setHere;
    }
}
