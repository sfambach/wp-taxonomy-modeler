<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * Schreibt die Felder vom Typ «Zusammenfassung» neu — nach jeder Änderung an einem Satz oder an einem seiner Teile.
 *
 * ⚠️ **[D-885](../../../docs/NewConcept/90-decision-log.md), sein Wort:** *«bei jeder Änderung muss es natürlich neu
 * geschrieben werden»* — und der Grund: *«dann haben wir es auch einfacher mit der Selektion»*.
 *
 * ```mermaid
 * flowchart LR
 *   W["Wert geschrieben"] --> H["Halter gesucht: der Teil meldet seinen Besitzer"]
 *   H --> F["Zusammenfassungs-Felder am Knoten des Satzes"]
 *   F --> T["Text gerechnet wie im Waehler"]
 *   T --> S["nur geschrieben, wenn er sich geaendert hat"]
 * ```
 *
 * *Der Text kommt aus {@see Rendering::summaryTextsOf()}, also aus denselben Worten, die ein Wähler zeigt; geschrieben
 * wird über {@see DataEntry::put()}, damit Schatten und Änderungsbuch stimmen.*
 */
final class SummaryWriter
{
    /** Wie viele Stufen nach oben ein Teil seinen Besitzer meldet — Einheitenwert im Teil im Satz. */
    private const STUFEN = 3;

    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly TypeNodes $typeNodes,
        private readonly Rendering $rendering,
        private readonly DataEntry $data,
    ) {
    }

    /**
     * Die Zusammenfassungen dieser Sätze und ihrer Besitzer neu schreiben.
     *
     * @param  list<int> $recordIds
     * @return int Wie viele Texte sich geändert haben.
     */
    public function refresh(array $recordIds): int
    {
        $typKnoten = $this->typeNodes->nodeId(SimpleType::Summary);
        $betroffen = $this->mitBesitzern($recordIds);

        if ($typKnoten === null || $betroffen === []) {
            return 0;
        }

        // *Je Knoten einmal fragen, welche Felder Zusammenfassungen sind (`CD-7`).*
        $knotenJeSatz = [];
        $saetzeJeFeld = [];
        $felderJeKnoten = [];

        foreach ($this->records->byIds($betroffen) as $satz) {
            $knotenJeSatz[$satz->id] = $satz->nodeId;
        }

        foreach (array_unique(array_values($knotenJeSatz)) as $knotenId) {
            $knoten = $this->nodes->find($knotenId);

            if ($knoten === null) {
                continue;
            }

            foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($knoten)) as $kante) {
                if ($kante->hide || $kante->isSetting()) {
                    continue;
                }

                // ⚠️ *Zwei Wege zu demselben Text: ein Feld **vom Typ** «Zusammenfassung» (D-885) und ein gewöhnliches Feld, an dem
                // **hier** steht, woraus es sich zusammensetzt ([D-888](../../../docs/NewConcept/90-decision-log.md), sein Wort:
                // «bezeichnung soll das feld sein bei bauteilen»). Geschrieben wird beides gleich.*
                if ($kante->toNodeId === $typKnoten || $this->rendering->zusammengesetztHier($kante, $knotenId)) {
                    $felderJeKnoten[$knotenId][] = $kante;
                }
            }
        }

        foreach ($knotenJeSatz as $satzId => $knotenId) {
            foreach ($felderJeKnoten[$knotenId] ?? [] as $kante) {
                // ⚠️ *Je Knoten **und** Kante ein Auftrag: zwei Knoten können dasselbe Feld aus verschiedenen Feldern zusammensetzen.*
                $schluessel = $kante->id . ':' . $knotenId;

                $saetzeJeFeld[$schluessel]['relation']  = $kante;
                $saetzeJeFeld[$schluessel]['fields']    = $this->rendering->zusammensetzungAn($kante, $knotenId);
                $saetzeJeFeld[$schluessel]['records'][] = $satzId;
            }
        }

        if ($saetzeJeFeld === []) {
            return 0;
        }

        // ⚠️ *Erst vergessen, dann rechnen: geschrieben wurde eben, und der Zeichenlauf merkt sich jeden Satz (D-885).*
        $this->rendering->forgetRecords($betroffen);

        $geaendert = 0;

        // ⚠️ *Ein Auftrag je Knoten und Feld, nicht alle auf einmal: dieselbe Kante kann an zwei Knoten aus **verschiedenen** Feldern
        // zusammengesetzt sein, und ein Auftrag je Kante liesse den zweiten den ersten überschreiben. Gemessen am 2026-09-20: im
        // Massenlauf blieben die Stecker auf dem alten Text stehen, einzeln gerechnet stimmte er.*
        foreach ($saetzeJeFeld as $eintrag) {
            $kanteId = $eintrag['relation']->id;

            foreach ($this->rendering->summaryTextsOf([$eintrag])[$kanteId] ?? [] as $satzId => $text) {
                // ⚠️ *Ohne einen einzigen Wert bleibt das Feld leer. Gemessen an einem Kabel ohne Hersteller und Teilenummer: da
                // stand «#24621» — die Notnummer, mit der ein Wähler einen namenlosen Satz zeigt. Im Satz ist sie kein Name.*
                $text = preg_match('/^#\d+$/', trim($text)) === 1 ? '' : $text;

                if ($this->steht($satzId, $kanteId) === $text || ($text === '' && $this->steht($satzId, $kanteId) === null)) {
                    continue;
                }

                $this->data->put($satzId, $kanteId, TypedValue::ofText($text));
                ++$geaendert;
            }
        }

        return $geaendert;
    }

    /** Was heute in diesem Feld steht, oder null. */
    private function steht(int $recordId, int $relationId): ?string
    {
        foreach ($this->records->valuesOf($recordId) as $zeile) {
            if ($zeile->relationId === $relationId && $zeile->locale === '') {
                return $zeile->value->text;
            }
        }

        return null;
    }

    /**
     * Die Sätze selbst und, Stufe für Stufe, die Sätze, die sie als Teil halten.
     *
     * @param  list<int> $recordIds
     * @return list<int>
     */
    private function mitBesitzern(array $recordIds): array
    {
        $alle  = [];
        $runde = array_values(array_unique(array_filter(array_map('intval', $recordIds))));

        for ($stufe = 0; $stufe < self::STUFEN && $runde !== []; $stufe++) {
            foreach ($runde as $id) {
                $alle[$id] = true;
            }

            $halter = array_map(
                static fn (\Taxmod\Core\Model\RelationRecord $zeile): int => $zeile->recordId,
                array_values($this->records->holdersOf($runde))
            );
            $runde  = array_values(array_diff($halter, array_keys($alle)));
        }

        return array_map('intval', array_keys($alle));
    }
}
