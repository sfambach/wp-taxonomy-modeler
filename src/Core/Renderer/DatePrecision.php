<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Wie genau ein Zeitpunkt eingegeben wird — das Attribut `date_precision` des {@see DateTimeRenderer}.
 *
 * ⚠️ *Ein Enum und kein freier Text (Anforderung 3.2.2): drei Fälle, im Code erklärt, per Vertrag
 * gelesen. Die Werte sind die, die der Renderer schon immer verglichen hat.*
 */
enum DatePrecision: string
{
    /** ⚠️ *Sein Wort am 2026-09-12: «nur jahr, nur monat und jahr, monat jahr tag … zeit» (D-737).* */
    case Year     = 'year';
    case Month    = 'month';
    case Date     = 'date';
    case Time     = 'time';
    case DateTime = 'datetime';
}
