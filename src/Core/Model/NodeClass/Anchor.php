<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Ein fester Knoten des Gerüsts, aus dessen **Kindern** ein Verweisattribut wählt.
 *
 * ⚠️ **Sein Befund am 2026-09-12: «warum werden die konstanten bei label role angezeigt».** *`label_role`
 * sagte nur «zeigt auf eine Konstante», und Konstanten sind seit Fassung 49 auch yotta, kilo und Gramm. Die
 * Rollen sind die Kinder des einen Gerüstknotens der Rollen — und das sagt das Attribut jetzt selbst,
 * ohne einen Namen zu nennen* ([D-728](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/einstellungen-anforderungen.md §3.6
 */
enum Anchor: string
{
    case Roles = 'roles';

    /** ⚠️ *Sein Bild am 2026-09-12: `erlaubte_praefixe` bot form, table, yotta … an — alle Konstanten statt der Präfixe.* */
    case Prefixes = 'prefixes';

    /**
     * ⚠️ *Sein Wort am 2026-09-13: «shrink the available base units for these fields in the settings» ([D-783](../../../../docs/NewConcept/90-decision-log.md)).
     * Die Einheiten liegen eine Ebene tiefer als die Präfixe — unter «With prefix» und «Without prefix».*
     */
    case Units = 'units';
}
