<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Wo der Hauptsatz im Vergleich steht ([D-920](../../../docs/NewConcept/90-decision-log.md), [D-924](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Die Vorgabe ist die Liste selbst** (D-924, sein Wort: *«die werden dargestellt in der Reihenfolge, in der sie aufgelistet
 * wurden»*): der Hauptsatz steht, wo er in der Liste steht. Vorn und Mitte bleiben für Blöcke, die sie schon tragen. *Ein
 * unbekanntes oder leeres Wort ist die Liste — ein alter Block ohne Wort hatte den Hauptsatz nicht in der Liste, er kommt
 * vorangestellt dorthin, wo «vorn» ihn hatte.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
enum ComparisonPosition: string
{
    case Listed = 'listed';
    case Front  = 'front';
    case Middle = 'middle';

    public static function fromWord(string $word): self
    {
        return self::tryFrom($word) ?? self::Listed;
    }
}
