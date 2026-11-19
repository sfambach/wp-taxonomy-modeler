<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * The one rule for what a filter on a field choosing a node keeps: the node wanted, anything below it ([D-791](../../../docs/NewConcept/90-decision-log.md)),
 * and each ancestor of it up to and including the field's target ([D-1477](../../../docs/NewConcept/90-decision-log.md)) — so a record that chose
 * «all computers» is found under «XT». Compared on the materialised paths ([D-082](../../../docs/NewConcept/90-decision-log.md)), nothing is read here.
 * The record table's filter row and `taxmod/query` both ask this; nothing else decides it.
 *
 * ```mermaid
 * flowchart TD
 *   T["Computer-Systeme (target)"] --> P["PC"] --> X["XT (wanted)"] --> U["XT clone"]
 *   T -. fits .- X
 *   P -. fits .- X
 *   U -. fits .- X
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1477
 */
final class ChoiceFilter
{
    /**
     * Whether a record that chose the node at `$chosenPath` is kept by a filter for the node at `$wantedPath` in a field
     * whose target is at `$targetPath`. Paths are ids joined by `.`, own id last. An empty choice is «not yet decided» and
     * is never kept.
     */
    public static function fits(string $chosenPath, string $wantedPath, string $targetPath): bool
    {
        if ($chosenPath === '' || $wantedPath === '') {
            return false;
        }

        if (self::within($chosenPath, $wantedPath)) {
            return true;
        }

        return $targetPath !== '' && self::within($wantedPath, $chosenPath) && self::within($chosenPath, $targetPath);
    }

    /** Whether `$path` is `$top` or lies below it. */
    private static function within(string $path, string $top): bool
    {
        return $path === $top || str_starts_with($path, $top . '.');
    }
}
