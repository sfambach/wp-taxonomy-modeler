<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Woher die Kandidaten eines Feldverweises kommen, den eine Zusatzfunktion trägt ([D-844](../../../../docs/NewConcept/90-decision-log.md),
 * [D-845](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * *Sein Paar: «x aus vater mit y aus kind». `Holder` sind die Felder des Satzes, der den Verweis trägt, und des Satzes, der ihn hält;
 * `Target` die Felder des angebotenen Satzes. Am Knoten ohne Kante sind beides die Felder des Knotens.*
 */
enum FieldSource: string
{
    case Holder = 'holder';
    case Target = 'target';

    /**
     * Die Felder des Knotens, der im Feld `ziel` derselben Stelle gewählt ist — für den Sprung ([D-769](../../../../docs/NewConcept/90-decision-log.md)):
     * das Filterfeld liegt am Ziel des Sprungs, nicht am Typ «Jump».
     */
    case ChosenTarget = 'chosen_target';

    /** Nur die Felder des Knotens, von dem die Kante ausgeht — «das Feld dieses Satzes» des Sprungs (D-769), ohne die haltenden Sätze. */
    case Owner = 'owner';
}
