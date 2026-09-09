<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * In welcher Reihenfolge die Felder eines Knotens stehen — die des Besitzers, oder die, die ein Kind
 * an derselben Adresse wie seine anderen Einstellungen darüberlegt.
 *
 * ⚠️ **[D-698](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«würde sagen kind darf
 * felder neu anordnen»* — auf die Lücke *«ich müsste prefix vor unit in with prefix knoten schieben»*.
 * *Verschieben ging nur beim Besitzer ([D-435](../../../docs/NewConcept/90-decision-log.md): `sort_order`
 * gehört der Kante), und geerbte Felder kamen in der Reihenfolge ihrer Besitzer.*
 *
 * ⚠️ **Die Form, dieselbe Adresse wie [D-697](../../../docs/NewConcept/90-decision-log.md):** *der Satz
 * `Knoten × Kante` ([D-667](../../../docs/NewConcept/90-decision-log.md)), darin die Einstellung
 * `position` — eine Einstellungskante an der Wurzel, wie `read_only`. **Dünn:** steht an keiner Zeile
 * eine Position, gilt die Reihenfolge des Besitzers. Steht eine, gilt sie; Zeilen ohne kommen danach,
 * wie der Besitzer sie ordnet. Näher schlägt ferner ([D-602](../../../docs/NewConcept/90-decision-log.md)):
 * ein Enkel erbt die Anordnung des Kindes und darf sie wieder umstellen.*
 *
 * ```mermaid
 * flowchart LR
 *   B["Besitzer: f1, f2 (sort_order)"] --> K["Kind: f1, f2, g — nichts gesetzt"]
 *   K -->|"g hoch, zweimal"| P["Satz Kind × f1: position 1 · Kind × f2: 2 · Kind × g: 0"]
 *   P --> E["Enkel liest: g, f1, f2"]
 *   P -.->|"unberührt"| B
 * ```
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class FieldOrder
{
    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
    ) {
    }

    /**
     * Die Einstellungskante `position` — an der Wurzel erklärt, für jede Verwendungsstelle gültig. `null`,
     * solange Fassung 45 sie nicht angelegt hat.
     */
    public function positionRelation(): ?Relation
    {
        foreach ($this->relations->fieldRelationsOf([$this->framework->root()->id]) as $kante) {
            if ($kante->isSetting() && $kante->name === SettingKey::Position->value) {
                return $kante;
            }
        }

        return null;
    }

    /**
     * Die an diesem Knoten geltenden Positionen — je Kante die nächste gesetzte, vom Knoten aufwärts.
     *
     * ⚠️ *Zwei Abfragen für die ganze Kette (`CD-7`): die Sätze aller Glieder, dann ihre Werte.*
     *
     * @param  list<Relation>  $relations Die Zeilen des Knotens.
     * @return array<int, int> Kanten-Id => Position; nur, wo eine gesetzt ist.
     */
    public function positionsAt(int $nodeId, array $relations): array
    {
        $kante = $this->positionRelation();
        $hier  = $this->nodes->find($nodeId);

        if ($kante === null || $hier === null || $relations === []) {
            return [];
        }

        $zeilen = [];

        foreach ($relations as $relation) {
            $zeilen[$relation->id] = true;
        }

        $kette  = $this->framework->inheritanceOwnersOf($hier);
        $saetze = [];

        foreach ($this->records->ofRelationsAt($kette, array_keys($zeilen)) as $anDenKanten) {
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
                    $aus[$satz->relationId]   = $wert->value->int;
                }
            }
        }

        return $aus;
    }

    /**
     * Die Zeilen in der Reihenfolge, die an diesem Knoten gilt.
     *
     * @param  list<Relation> $relations In der Reihenfolge der Besitzer.
     * @return list<Relation>
     */
    public function orderedAt(int $nodeId, array $relations): array
    {
        $felder     = $this->fieldRowsOf($relations);
        $positionen = $this->positionsAt($nodeId, $felder);

        if ($positionen === []) {
            return $relations;
        }

        $mitRang = [];

        foreach ($felder as $stelle => $relation) {
            $mitRang[] = [$positionen[$relation->id] ?? PHP_INT_MAX, $stelle, $relation];
        }

        usort($mitRang, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        // ⚠️ *Die Einstellungskanten — `read_only`, `renderer`, `position` selbst — sind keine Feldzeilen
        // und werden nicht angeordnet; sie folgen in ihrer eigenen Reihenfolge.*
        return [
            ...array_map(static fn (array $eintrag): Relation => $eintrag[2], $mitRang),
            ...array_values(array_filter($relations, static fn (Relation $r): bool => $r->isSetting())),
        ];
    }

    /**
     * Nur die Feldzeilen — was ein Knoten anordnet.
     *
     * @param  list<Relation> $relations
     * @return list<Relation>
     */
    public function fieldRowsOf(array $relations): array
    {
        return array_values(array_filter($relations, static fn (Relation $r): bool => ! $r->isSetting()));
    }

    /**
     * Ob an diesem Knoten schon eine eigene oder geerbte Anordnung gilt — dann schreibt auch das
     * Verschieben einer eigenen Zeile Positionen statt `sort_order`, sonst stünden zwei Ordnungen
     * nebeneinander.
     */
    public function isArrangedAt(int $nodeId, array $relations): bool
    {
        return $this->positionsAt($nodeId, $relations) !== [];
    }

    /** Wo eine Zeile nach dem Schritt stünde — die neue Liste, oder `null`, wenn der Schritt ins Leere geht. */
    public function movedAt(int $nodeId, array $relations, int $relationId, int $direction): ?array
    {
        $liste = $this->fieldRowsOf($this->orderedAt($nodeId, $relations));
        $von   = null;

        foreach ($liste as $stelle => $relation) {
            if ($relation->id === $relationId) {
                $von = $stelle;
            }
        }

        if ($von === null) {
            return null;
        }

        $nach = $von + ($direction < 0 ? -1 : 1);

        if ($nach < 0 || $nach >= count($liste)) {
            return null;
        }

        [$liste[$von], $liste[$nach]] = [$liste[$nach], $liste[$von]];

        return $liste;
    }
}
