<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\TypedValue;

/**
 * Eine Zusatzfunktion, die beim Anbieten eingreift: sie filtert das Angebot eines Verweises oder stellt Passendes nach vorn
 * ([D-845](../../../docs/NewConcept/90-decision-log.md)).
 */
interface ShapesOffer extends Addon
{
    /**
     * Ein Urteil über ein Angebot.
     *
     * @param list<int>                               $offer        Die angebotenen Sätze.
     * @param array<int, array<int, list<TypedValue>>> $offerValues  Je Satz die Werte je Feld.
     * @param array<int, TypedValue>                  $holderValues Die Werte des Satzes, der den Verweis trägt, dahinter die des haltenden.
     * @param array<string, TypedValue>               $settings     Die eigenen Felder der gewählten Funktion.
     * @param \Closure(TypedValue, TypedValue): bool   $matches      Passt ein Wert des Angebots zum gesuchten?
     * @param \Closure(TypedValue): ?string            $behaviourAt  Was am gesuchten Wert als Verhalten eingestellt ist (das Bedingte).
     */
    public function judge(array $offer, array $offerValues, array $holderValues, array $settings, \Closure $matches, \Closure $behaviourAt): OfferVerdict;
}
