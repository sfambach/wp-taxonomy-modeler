<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Wo eine Zusatzfunktion gewählt werden darf ([D-845](../../../docs/NewConcept/90-decision-log.md)).
 *
 * *`Node` gilt für den Knoten und jede Kante auf ihn; `Edge` nur an einer Verwendungsstelle; `ManyEdge` nur an einer Kante, die mehr
 * als einen Wert trägt; `RecordEdge` nur an einer Kante, deren Werte Sätze sind.*
 */
enum AddonSite: string
{
    case Node = 'node';
    case Edge = 'edge';
    case ManyEdge = 'many_edge';
    case RecordEdge = 'record_edge';
}
