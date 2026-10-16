<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Wo der Hauptsatz im Vergleich steht — je Fall vorn oder in der Mitte ([D-920](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Ein unbekanntes Wort ist «vorn»: auf die Vorgabe zurückzufallen erfindet keine dritte Lage* (wie {@see Orientation}).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
enum ComparisonPosition: string
{
    case Front  = 'front';
    case Middle = 'middle';

    public static function fromWord(string $word): self
    {
        return self::tryFrom($word) ?? self::Front;
    }
}
