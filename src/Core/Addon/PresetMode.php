<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Ob ein Vergleichspaar der Vorbelegung filtert oder sortiert ([D-844](../../../docs/NewConcept/90-decision-log.md)).
 *
 * *Sein Wort: «eigentlich müssten wir bei jedem wert sagen wir gefiltert oder sortiert wird also eher filter/sort». `filter`: nur
 * Passende bleiben. `sort`: alle bleiben, Passende stehen vorn und sind hervorgehoben — das frühere `first`.*
 */
enum PresetMode: string
{
    case Filter = 'filter';
    case Sort = 'sort';
}
