<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Ob ein Feld an einem Knoten versteckt ist — die Spalte `hide` des Besitzers, oder was ein Knoten darunter
 * für sich und seinen Unterbaum darüberlegt.
 *
 * ⚠️ **[D-1144](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«dann bräuchten wir wieder hide an der
 * relation. und die relation müsste überschreibbar sein»* — und dann *«ist dann aber eine einstellung an der relation
 * wie read only»*. *Dieselbe Adresse wie `position` ([D-698](../../../docs/NewConcept/90-decision-log.md)): der Satz
 * `Knoten × Kante` ([D-667](../../../docs/NewConcept/90-decision-log.md)), darin die Einstellung `hidden` der Wurzel.
 * **Gelesen wird nur die Kette der Knoten**, nie die des Ziels — so kann ein Verstecken am Typ nicht wieder jedes Feld
 * dieses Typs leeren ([D-457](../../../docs/NewConcept/90-decision-log.md), OQ-101). Näher schlägt ferner; steht nichts,
 * gilt die Spalte.*
 *
 * ```mermaid
 * flowchart LR
 *   B["Model: Herkunft (hide = 0)"] --> L["Lagerorte × Herkunft: hidden = 1"]
 *   L --> U["Unterknoten: versteckt"]
 *   B --> M["Material: sichtbar"]
 * ```
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class FieldVisibility
{
    public const SETTING = 'hidden';

    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /** Die Einstellungskante `hidden` an der Wurzel — `null`, solange Fassung 57 sie nicht angelegt hat. */
    public function hiddenRelation(): ?Relation
    {
        foreach ($this->relations->fieldRelationsOf([$this->framework->root()->id]) as $kante) {
            if ($kante->isSetting() && $kante->name === self::SETTING) {
                return $kante;
            }
        }

        return null;
    }

    /**
     * Was an diesem Knoten über die Kanten gesagt ist — je Kante das nächste gesetzte, vom Knoten aufwärts.
     *
     * ⚠️ *Zwei Abfragen für die ganze Kette (`CD-7`): die Sätze aller Glieder, dann ihre Werte.*
     *
     * @param  list<Relation> $relations
     * @return array<int, bool> Kanten-Id => versteckt; nur, wo etwas gesetzt ist.
     */
    public function overridesAt(int $nodeId, array $relations): array
    {
        $kante = $this->hiddenRelation();
        $hier  = $this->nodes->find($nodeId);

        if ($kante === null || $hier === null || $relations === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn (Relation $r): int => $r->id, $relations)));

        $kette  = $this->framework->inheritanceOwnersOf($hier);
        $saetze = [];

        foreach ($this->records->ofRelationsAt($kette, $ids) as $anDenKanten) {
            foreach ($anDenKanten as $satz) {
                if ($satz->recordType === RecordType::Settings) {
                    $saetze[$satz->id] = $satz;
                }
            }
        }

        if ($saetze === []) {
            return [];
        }

        $werte = $this->records->valuesOfMany(array_keys($saetze));
        $rang  = array_flip($kette);
        $aus   = [];
        $tiefe = [];

        foreach ($saetze as $satz) {
            foreach ($werte[$satz->id] ?? [] as $wert) {
                if ($wert->relationId !== $kante->id || $wert->value->int === null) {
                    continue;
                }

                $stufe = $rang[$satz->nodeId] ?? -1;

                if (! isset($tiefe[$satz->relationId]) || $stufe > $tiefe[$satz->relationId]) {
                    $tiefe[$satz->relationId] = $stufe;
                    $aus[$satz->relationId]   = $wert->value->int !== 0;
                }
            }
        }

        return $aus;
    }

    /**
     * Die Zeilen so, wie sie an diesem Knoten gelten: wo ein Knoten der Kette etwas gesagt hat, trägt die Kante
     * dessen `hide`. Einstellungskanten bleiben unberührt.
     *
     * @param  list<Relation> $relations
     * @return list<Relation>
     */
    public function appliedAt(int $nodeId, array $relations): array
    {
        $gesagt = $this->overridesAt($nodeId, array_values(array_filter($relations, static fn (Relation $r): bool => ! $r->isSetting())));

        if ($gesagt === []) {
            return $relations;
        }

        return array_map(
            static fn (Relation $r): Relation => isset($gesagt[$r->id]) && ! $r->isSetting() ? $r->asSeenWithHide($gesagt[$r->id]) : $r,
            $relations
        );
    }
}
