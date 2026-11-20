<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\NodeClass\Attribut;
use Taxmod\Core\Model\NodeClass\FieldSource;
use Taxmod\Core\Model\TypedValue;

/**
 * «Auswahl aus Feld» ([D-1520](../../../docs/NewConcept/90-decision-log.md)): ein Verweis bietet nur die Sätze an, die ein anderes
 * Feld **desselben Satzes** trägt, und Speichern weist alles andere ab.
 *
 * ⚠️ *Sein Wort: «eigentlich darf er nur die sehen, die er auch am Platz verfügbar hat» — der Standarddrucker eines Arbeitsplatzes
 * aus dessen verfügbaren Druckern. Allgemein, kein Drucker-Sonderfall: jedes Verweisfeld, jedes Quellfeld desselben Knotens.*
 *
 * ⚠️ *Ein leeres Quellfeld eines bekannten Satzes bietet nichts an — «nur die, die er hat» heisst bei keinem: keiner. Der schon gewählte Wert bleibt sichtbar
 * (die Stelle legt ihn zurück ins Angebot), damit niemand eine Nummer sieht.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ChoiceFromFieldAddon implements ShapesOffer
{
    public const NAME = 'choice_from';

    public const NOT_IN_SOURCE = 'not_in_source';

    /** Das Feld desselben Satzes, dessen Sätze angeboten werden. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Owner)]
    public ?int $from_field = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::RecordEdge];
    }

    public function requirements(): array
    {
        return [];
    }

    /** @param array<string, TypedValue> $settings */
    public static function sourceOf(array $settings): ?int
    {
        $quelle = ($settings['from_field'] ?? null)?->reference;

        return $quelle === null || $quelle <= 0 ? null : $quelle;
    }

    public function judge(array $offer, array $offerValues, array $holderValues, array $settings, \Closure $matches, \Closure $behaviourAt, array $holderMany = []): OfferVerdict
    {
        $quelle = self::sourceOf($settings);

        // *Kein tragender Satz bekannt (eine Tabelle ohne Werte, ein neuer Satz): kein Urteil — das Speichern prüft trotzdem.*
        if ($quelle === null || ($holderValues === [] && $holderMany === [])) {
            return OfferVerdict::nothingSaid();
        }

        $erlaubt = array_fill_keys(self::held($quelle, $holderValues, $holderMany), true);
        $bleibt  = [];

        foreach ($offer as $satzId) {
            if (isset($erlaubt[$satzId])) {
                $bleibt[$satzId] = true;
            }
        }

        return new OfferVerdict($bleibt);
    }

    /**
     * Was gewählt ist und nicht im Quellfeld steht — je Satz eine Beanstandung.
     *
     * @param  list<int> $chosen
     * @param  list<int> $allowed
     * @return list<Complaint>
     */
    public static function complaintsAbout(array $chosen, array $allowed): array
    {
        $erlaubt = array_fill_keys($allowed, true);
        $aus     = [];

        foreach ($chosen as $satzId) {
            if (! isset($erlaubt[$satzId])) {
                $aus[] = new Complaint(self::NAME, self::NOT_IN_SOURCE, ['record' => $satzId]);
            }
        }

        return $aus;
    }

    /**
     * @param  array<int, TypedValue> $holderValues
     * @param  array<int, list<int>>  $holderMany
     * @return list<int>
     */
    private static function held(int $quelle, array $holderValues, array $holderMany): array
    {
        if (isset($holderMany[$quelle])) {
            return array_values(array_map('intval', $holderMany[$quelle]));
        }

        $einer = $holderValues[$quelle] ?? null;

        return $einer?->reference === null ? [] : [(int) $einer->reference];
    }
}
