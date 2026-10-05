<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Edge;

use Taxmod\Core\Model\Relation;

/**
 * Eine Kante auf etwas Eigenstaendiges — der Datensatz dahinter ueberlebt den Besitzer.
 *
 * ⚠️ **Der Gegenfall zu {@see CompositionEdge}, und der Unterschied ist eine Aussage ueber
 * Datensaetze** ([D-639](../../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «wenn ich
 * einen **Datensatz** loesche — also den Datensatz von Kunde A —, dann muss auch die Adresse von
 * Kunde A geloescht werden.» Bei einer Aggregation geschieht genau das **nicht**: der Lieferant
 * bleibt, wenn die Bestellung geht.*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
final class AggregationEdge extends Relation
{
    public function isSetting(): bool
    {
        return false;
    }

    public function deletesRecordWithOwner(): bool
    {
        return false;
    }
}
