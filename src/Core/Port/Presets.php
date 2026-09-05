<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\TypedValue;

/**
 * Was in einem Feld stehen soll, **bevor jemand etwas eingetragen hat**.
 *
 * ⚠️ **Es gibt diese Naht, weil zwei Dienste dieselbe Antwort brauchen und nur einer sie berechnen
 * kann.** *{@see \Taxmod\Core\Service\Rendering} löst Typ und Kette ohnehin auf — es weiss, welcher
 * Typ hinter einer Kante steht und was `read_only` an dieser Stelle sagt.
 * {@see \Taxmod\Core\Service\DataEntry} legt den Datensatz an und braucht die Antwort genau dort:
 * **beim ersten Schreiben** ([D-609](../../../docs/NewConcept/90-decision-log.md)). Eine zweite
 * Auflösung im Schreibweg wäre die Doppelung, die auseinanderläuft.*
 *
 * ⚠️ **Schmal statt der ganzen Klasse.** *`DataEntry` gegen `Rendering` zu binden hiesse, den
 * Schreibweg vom Zeichenweg abhängig zu machen; diese Naht nennt genau die eine Frage, die er
 * stellt.*
 *
 * ⚠️ *Welche Typen überhaupt eine Vorbelegung haben, steht **nicht** hier, sondern in den Typklassen
 * ({@see \Taxmod\Core\Model\Type\SpecialisedType::presetFor()}) — heute genau einer
 * ([D-649](../../../docs/NewConcept/90-decision-log.md), [D-650](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
interface Presets
{
    /**
     * @param  list<Relation>         $relations
     * @return array<int, TypedValue> Nach Kanten-Id, nur für die, die eine Vorbelegung haben.
     */
    public function presetsFor(array $relations): array;
}
