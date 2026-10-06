<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Was eine Zusatzfunktion über ein Angebot sagt: welche Sätze bleiben und welche nach vorn gehören.
 *
 * *`null` heisst «dazu nichts gesagt» — ohne Wert im Satz bleibt alles, wie es ohne die Funktion wäre (D-791).*
 */
final class OfferVerdict
{
    public function __construct(
        /** @var array<int, true>|null Nur diese bleiben; `null`: alle. */
        public readonly ?array $keep = null,
        /** @var array<int, true>|null Diese stehen vorn; `null`: keine Reihenfolge. */
        public readonly ?array $first = null,
    ) {
    }

    public static function nothingSaid(): self
    {
        return new self();
    }
}
