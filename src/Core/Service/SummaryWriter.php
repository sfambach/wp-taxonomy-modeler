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

        // D-1482: a variant is named after its parent, so a parent's change renames its variants, and a variant needs its parent's name.
        $vaterJeVariante = $this->variantsAround($knotenJeSatz);

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
                // ⚠️ **Nennt die Feldwahl das Feld selbst, steht dort der Name des Knotens** ([D-954](../../../docs/NewConcept/90-decision-log.md) Regel 4):
                // *«IC-Sockel · DIP-14» statt «14 St». Ein Feld setzt sich nicht aus sich selbst zusammen, also ist das Glied frei; eine Knoten-Id
                // ging nicht — die Glieder einer Feldwahl sind Kanten (gemessen am 2026-10-21: der Fremdschlüssel lehnte sie ab).*
                $saetzeJeFeld[$schluessel]['fields']    = array_map(
                    static fn (int $feld): int => $feld === $kante->id ? Rendering::KNOTENNAME : $feld,
                    $this->rendering->zusammensetzungAn($kante, $knotenId)
                );
                $saetzeJeFeld[$schluessel]['records'][] = $satzId;
            }
        }

        if ($saetzeJeFeld === []) {
            return 0;
        }

        // ⚠️ *Erst vergessen, dann rechnen: geschrieben wurde eben, und der Zeichenlauf merkt sich jeden Satz (D-885).*
        $this->rendering->forgetRecords(array_keys($knotenJeSatz));

        $geaendert = 0;

        // ⚠️ *Ein Auftrag je Knoten und Feld, nicht alle auf einmal: dieselbe Kante kann an zwei Knoten aus **verschiedenen** Feldern
        // zusammengesetzt sein, und ein Auftrag je Kante liesse den zweiten den ersten überschreiben. Gemessen am 2026-09-20: im
        // Massenlauf blieben die Stecker auf dem alten Text stehen, einzeln gerechnet stimmte er.*
        foreach ($saetzeJeFeld as $eintrag) {
            $kanteId = $eintrag['relation']->id;

            $texte   = $this->rendering->summaryTextsOf([$eintrag])[$kanteId] ?? [];
            $texte   = $this->namedAfterParents($eintrag, $texte, $vaterJeVariante, $knotenJeSatz);

            foreach ($texte as $satzId => $text) {
                // ⚠️ *Ohne einen einzigen Wert bleibt das Feld leer. Gemessen an einem Kabel ohne Hersteller und Teilenummer: da
                // stand «#24621» — die Notnummer, mit der ein Wähler einen namenlosen Satz zeigt. Im Satz ist sie kein Name.*
                $text = preg_match('/^#\d+$/', trim($text)) === 1 ? '' : $text;

                if ($this->steht($satzId, $kanteId) === $text || ($text === '' && $this->steht($satzId, $kanteId) === null)) {
                    continue;
                }

                $this->data->putDerived($satzId, $kanteId, TypedValue::ofText($text));
                ++$geaendert;
            }
        }

        return $geaendert;
    }

    /**
     * The parents and variants of these records, added to `$knotenJeSatz`; answers variant → parent (D-1482). Two reads at most
     * for all records together (CD-7).
     *
     * @param array<int, int> $knotenJeSatz record → node, extended in place
     * @return array<int, int> variant → parent
     */
    private function variantsAround(array &$knotenJeSatz): array
    {
        $vaterFeld = [];

        foreach (array_unique(array_values($knotenJeSatz)) as $knotenId) {
            $feld = $this->data->variantFieldsOf($knotenId)['variantOf'];

            if ($feld > 0) {
                $vaterFeld[$knotenId] = $feld;
            }
        }

        if ($vaterFeld === []) {
            return [];
        }

        $ids    = array_keys(array_filter($knotenJeSatz, static fn (int $knoten): bool => isset($vaterFeld[$knoten])));
        $vater  = [];
        $felder = array_flip(array_values($vaterFeld));

        foreach ($this->records->valuesOfMany($ids) as $satzId => $zeilen) {
            foreach ($zeilen as $zeile) {
                if ($zeile->relationId === ($vaterFeld[$knotenJeSatz[$satzId]] ?? 0) && $zeile->value->reference !== null) {
                    $vater[(int) $satzId] = $zeile->value->reference;
                }
            }
        }

        foreach ($this->records->referrersOfMany($ids) as $zeilen) {
            foreach ($zeilen as $zeile) {
                if (isset($felder[$zeile->relationId]) && $zeile->value->reference !== null) {
                    $vater[$zeile->recordId] = $zeile->value->reference;
                }
            }
        }

        $fehlen = array_values(array_diff(array_unique(array_merge(array_keys($vater), array_values($vater))), array_keys($knotenJeSatz)));

        foreach ($fehlen === [] ? [] : $this->records->byIds($fehlen) as $satz) {
            $knotenJeSatz[$satz->id] = $satz->nodeId;
        }

        return array_filter($vater, static fn (int $parent, int $variant): bool => isset($knotenJeSatz[$parent], $knotenJeSatz[$variant]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * D-1482: a variant's name is its parent's name and the words of its own «Je Variante» fields — only fields that say a word
     * (a choice, a text, a number); pictures and parts such as a pin assignment stay out (D-1473).
     *
     * @param array{relation: Relation, fields: list<int>, records: list<int>} $eintrag
     * @param array<int, string> $texte record → text, as computed from the stored values
     * @param array<int, int>    $vaterJeVariante
     * @param array<int, int>    $knotenJeSatz
     * @return array<int, string>
     */
    private function namedAfterParents(array $eintrag, array $texte, array $vaterJeVariante, array $knotenJeSatz): array
    {
        $varianten = array_values(array_filter($eintrag['records'], static fn (int $id): bool => isset($vaterJeVariante[$id])));

        if ($varianten === []) {
            return $texte;
        }

        $knotenId = $knotenJeSatz[$varianten[0]];
        $worte    = $this->wordFields($knotenId, $eintrag['relation']->id);
        $eigene   = $worte === [] ? [] : ($this->rendering->summaryTextsOf([['relation' => $eintrag['relation'], 'records' => $varianten, 'fields' => $worte]])[$eintrag['relation']->id] ?? []);

        foreach ($varianten as $variante) {
            $vater   = $vaterJeVariante[$variante];
            $name    = $texte[$vater] ?? (string) $this->steht($vater, $eintrag['relation']->id);
            $eigenes = preg_match('/^#\d+$/', trim($eigene[$variante] ?? '')) === 1 ? '' : trim($eigene[$variante] ?? '');

            $texte[$variante] = implode(' · ', array_filter([$name, $eigenes], static fn (string $teil): bool => $teil !== ''));
        }

        return $texte;
    }

    /** @return list<int> the «Je Variante» fields of a node that say a word, in the node's field order */
    private function wordFields(int $knotenId, int $ausser): array
    {
        $eigen  = $this->data->variantFieldsOf($knotenId)['own'];
        $knoten = $this->nodes->find($knotenId);
        $aus    = [];

        foreach ($knoten === null || $eigen === [] ? [] : $this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($knoten)) as $kante) {
            if (! isset($eigen[$kante->id]) || $kante->id === $ausser) {
                continue;
            }

            $typ = $this->typeNodes->typeOf($kante->toNodeId);

            if ($kante->kind === \Taxmod\Core\Model\RelationKind::Aggregation || ($typ !== null && $typ !== SimpleType::Media && $typ !== SimpleType::Summary)) {
                $aus[] = $kante->id;
            }
        }

        return $aus;
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
