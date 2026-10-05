<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A move the tree cannot hold.
 *
 * ⚠️ **The one that matters is the cycle**: a node dropped onto its own descendant. It is the
 * easiest mistake to make by dragging and the hardest to notice afterwards, because the
 * subtree simply disappears from the tree it was cut out of.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ImpossibleMove extends DomainError
{
    public static function intoItsOwnDescendant(string $name): self
    {
        return new self(sprintf('«%s» cannot be moved into its own subtree.', $name));
    }

    /** Ein Feld kann nur in einen Vater wandern, der ein Modellknoten ist (D-750). */
    public static function noParentTakesTheField(string $field): self
    {
        return new self(sprintf('«%s» has no parent that could take it.', $field));
    }

    /** Ein Feld wandert nur in unmittelbare Kinder des Besitzers (D-750). */
    public static function notAChildOf(int $nodeId, string $owner): self
    {
        return new self(sprintf('#%d is not a child of «%s».', $nodeId, $owner));
    }

    public static function ofTheRoot(): self
    {
        return new self('The root has no parent and cannot be moved.');
    }
}
