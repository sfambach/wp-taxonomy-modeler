<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Wie die Vorbelegung des Filters an einem Verweisfeld wirkt — nur passende Sätze, oder passende zuerst.
 *
 * ⚠️ **Sein Wort** ([D-791](../../../../docs/NewConcept/90-decision-log.md), Schritt 3): *«smd/tht oder hauptsächlich smd,
 * hauptsächlich tht je nachdem sollen die entsprechenden werte mit der bauform oben stehen»*. *«SMD» ist `filter`, «hauptsächlich
 * SMD» ist `first`: die übrigen Sätze bleiben wählbar.*
 */
enum PresetMode: string
{
    case Filter = 'filter';

    case First = 'first';
}
