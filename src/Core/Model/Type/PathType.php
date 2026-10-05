<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Der Weg — ein Feld, das am Vater erklärt wird und an jedem Kind darunter die Kette der Namen vom Vater
 * bis zum Vater des Kindes zeigt, nicht eingebbar.
 *
 * ⚠️ **Seine Form, 2026-09-12 ([D-751](../../../../docs/NewConcept/90-decision-log.md)):** *«man wählt es am Vater als
 * Feld (sollte auch einen Knoten in den simplen Typen haben) und in den Kindern wird von Vater bis Kind die Hierarchie
 * angezeigt, Vater → Kat 1 → Kat 1 → (Kind wird aber nicht mehr angezeigt, oder per Schalter auch Kind anzeigen)».*
 *
 * ⚠️ *Gerechnet beim Zeichnen, nie gespeichert: ein Umbenennen oder Verschieben ändert den Weg von selbst. Der Weg
 * beginnt am Knoten, der das Feld erklärt, nicht an der Wurzel ({@see \Taxmod\Core\Service\Rendering::pathValueFor()}).*
 *
 * @see docs/einstellungen-anforderungen.md §3.1
 */
final class PathType extends SpecialisedType
{
    /** ⚠️ *«oder per Schalter auch Kind anzeigen» — der Knoten selbst als letztes Glied.* */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public bool $with_node = false;

    public const WITH_NODE = 'with_node';

    /**
     * ⚠️ *Sein Wort am 2026-09-12 ([D-755](../../../../docs/NewConcept/90-decision-log.md)): «ich hätte gerne ein Feld, das an PC
     * definiert ist, aber reinschreibt, ob es Hardware oder Software ist … über den Ast bestimmen, was drinne steht … im Grunde
     * könnte das eine Einstellung am Reference-Typ sein, only direct child oder so».* Nur das erste Glied unter dem
     * erklärenden Knoten — der Ast, in dem der Knoten liegt.
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public bool $only_direct_child = false;

    public const ONLY_DIRECT_CHILD = 'only_direct_child';

    public const SEPARATOR = ' → ';

    public function type(): SimpleType
    {
        return SimpleType::Path;
    }

    public function nodeName(): string
    {
        return 'Path';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /** Ein Weg wird nie eingegeben — was doch ankommt, wird als Text abgelegt und beim nächsten Zeichnen überdeckt. */
    public function valueFrom(string $characters): TypedValue
    {
        return TypedValue::ofText($characters);
    }
}
