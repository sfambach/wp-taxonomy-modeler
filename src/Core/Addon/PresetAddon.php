<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\NodeClass\Attribut;
use Taxmod\Core\Model\NodeClass\FieldSource;

/**
 * Die Vorbelegung eines Verweises als **ein** Vergleichspaar ([D-844](../../../docs/NewConcept/90-decision-log.md),
 * [D-845](../../../docs/NewConcept/90-decision-log.md)) — die erste Zusatzfunktion.
 *
 * ⚠️ *Sein Wort: «immer als pärchen vergleich x aus vater mit y aus kind». Mehrere Paare sind mehrere gewählte Vorbelegungen an
 * derselben Stelle; jedes urteilt für sich, die Stelle legt die Urteile zusammen.*
 *
 * ⚠️ *Ob gefiltert oder sortiert wird, sagt zuerst der **gesuchte Wert** — sein Wort: «an den konstanten sagen ist filter oder ist sort
 * smd filter, tht filter, almost .. sort» —, also das bedingte Feld `preset_behaviour` am Knoten des Wertes oder darüber; erst ohne
 * Angabe dort gilt `mode` des Paars. **Angenommen, nicht von ihm gesagt:** diese Rangfolge.*
 */
final class PresetAddon implements ShapesOffer
{
    public const NAME = 'preset';

    public const BEHAVIOUR = 'preset_behaviour';

    /** Das Feld im Satz, der den Verweis trägt (oder ihn hält). */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Holder)]
    public ?int $source_field = null;

    /** Das Feld am angebotenen Satz. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Target)]
    public ?int $offered_field = null;

    #[Attribut]
    public PresetMode $mode = PresetMode::Sort;

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::RecordEdge, AddonSite::Node];
    }

    public function requirements(): array
    {
        return [new AddonRequirement(self::BEHAVIOUR, PresetMode::class, 'offered_field')];
    }

    public function judge(array $offer, array $offerValues, array $holderValues, array $settings, \Closure $matches, \Closure $behaviourAt): OfferVerdict
    {
        $quelle = ($settings['source_field'] ?? null)?->reference;
        $ziel   = ($settings['offered_field'] ?? null)?->reference;

        if ($quelle === null || $ziel === null) {
            return OfferVerdict::nothingSaid();
        }

        $gesucht = $holderValues[$quelle] ?? null;

        // *Ohne Wert im Satz keine Vorbelegung — dann steht alles da wie ohne sie (D-791).*
        if ($gesucht === null || $gesucht->isNothing()) {
            return OfferVerdict::nothingSaid();
        }

        $passend = [];

        foreach ($offer as $satzId) {
            foreach ($offerValues[$satzId][$ziel] ?? [] as $wert) {
                if ($matches($wert, $gesucht)) {
                    $passend[$satzId] = true;

                    break;
                }
            }
        }

        $modus = PresetMode::tryFrom((string) $behaviourAt($gesucht))
            ?? PresetMode::tryFrom((string) (($settings['mode'] ?? null)?->text ?? ''))
            ?? PresetMode::Sort;

        return $modus === PresetMode::Filter ? new OfferVerdict($passend, $passend) : new OfferVerdict(null, $passend);
    }
}
