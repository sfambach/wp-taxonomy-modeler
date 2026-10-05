<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/** Eine an einer Stelle gewählte Zusatzfunktion, mit ihren eigenen Feldern ([D-845](../../../docs/NewConcept/90-decision-log.md)). */
final class ChosenAddon
{
    public function __construct(
        /** @var class-string<Addon> */
        public readonly string $klasse,
        public readonly int $objectId,
        /** @var array<string, \Taxmod\Core\Model\TypedValue> Die eigenen Felder, roh — Verweise als Nummer. */
        public readonly array $settings,
        /** Ob sie an genau dieser Stelle steht (und nicht vom Knoten geerbt ist). */
        public readonly bool $setHere,
    ) {
    }
}
