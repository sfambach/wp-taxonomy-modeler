<?php declare(strict_types=1);

/**
 * Ist diese Installation über eine Wanderung gegangen — oder wurde sie erst danach angelegt?
 *
 * ⚠️ *Grenzlauf 2026-11-17 ([D-1427](../../../docs/NewConcept/90-decision-log.md)): vier Wächter verlangten die Spuren alter
 * Wanderungen (Marken, Journalzeilen, Schattenzeilen) und waren in jeder frischen Installation rot — dort gab es nichts zu
 * wandern. Eine Installation, die nie gewandert ist, ist **ein anderer Fall und kein Fehler**; der Wächter sagt ihn, statt ihn grün
 * oder rot zu färben, wie `inheritance-column` es für TASK-018 schon tat. Fehlt die Marke, ist die Installation älter als sie: dort
 * gilt die strenge Zusage unverändert.*
 *
 * @see src/WordPress/Persistence/Schema.php BORN_AT_OPTION
 */
function taxmodNachDerWanderungAngelegt(int $fassung): bool
{
    $geboren = (int) get_option(\Taxmod\WordPress\Persistence\Schema::BORN_AT_OPTION, 0);

    if ($geboren >= $fassung) {
        echo "  --   angelegt mit Fassung {$geboren}, nach der Wanderung von Fassung {$fassung}: hier ist nie etwas gewandert\n";

        return true;
    }

    if ($geboren === 0) {
        echo "  --   Geburtsfassung unbekannt (angelegt vor 0.2.132): eine Test-Datenbank, die nie gewandert ist, nennt sie mit\n"
            . "       «wp option add " . \Taxmod\WordPress\Persistence\Schema::BORN_AT_OPTION . " <Fassung>» — die Fassung, mit der sie angelegt wurde\n";
    }

    return false;
}
