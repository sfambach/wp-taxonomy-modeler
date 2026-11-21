<?php declare(strict_types=1);

namespace Taxmod\Core\Privacy;

/**
 * Whether a field is confidential for a record (D-1514, D-1578): «Vertraulich» at the edge holds for every node, and a node
 * may switch it on or off for itself and its subtree (the record `node × edge`, as «hidden» in D-1144). Nearer wins.
 *
 * ```mermaid
 * flowchart LR
 *   K["Kante Name: Vertraulich (oder nicht)"] --> P["Person × Name: an"]
 *   K --> O["Organisation × Name: nichts gesagt"]
 *   P --> S["Satz unter Person: versiegelt"]
 *   O --> T["Satz unter Organisation: wie die Kante"]
 * ```
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class Confidentiality
{
    /** Name der Einstellung an der Wurzel, die «vertraulich» je (Knoten, Kante) trägt — wie `hidden` (D-1144, D-1578). */
    public const SETTING = 'confidential';

    /**
     * @param array<int, true>              $edges     edges with «Vertraulich» at the edge itself
     * @param array<int, array<int, bool>>  $overrides edge → node → on/off, said at that node
     */
    public function __construct(private readonly array $edges, private readonly array $overrides)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** Whether any record may hold a sealed value on this edge — what a search or a job has to look at. */
    public function mayHold(int $edge): bool
    {
        return isset($this->edges[$edge]) || in_array(true, $this->overrides[$edge] ?? [], true);
    }

    /** Whether the answer depends on the node of the record. */
    public function dependsOnNode(int $edge): bool
    {
        return ($this->overrides[$edge] ?? []) !== [];
    }

    /**
     * The answer for a record whose node has this chain of owners, root first and its own node last.
     *
     * @param list<int> $chain
     */
    public function at(int $edge, array $chain): bool
    {
        $said = $this->overrides[$edge] ?? [];

        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (isset($said[$chain[$i]])) {
                return $said[$chain[$i]];
            }
        }

        return isset($this->edges[$edge]);
    }

    /** @return array<int, true> every edge that may hold a sealed value */
    public function edges(): array
    {
        $aus = $this->edges;

        foreach ($this->overrides as $edge => $said) {
            if (in_array(true, $said, true)) {
                $aus[$edge] = true;
            }
        }

        return $aus;
    }
}
