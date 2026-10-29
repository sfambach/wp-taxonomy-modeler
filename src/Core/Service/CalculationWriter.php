<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Addon\CalculationOperation;
use Taxmod\Core\Addon\CalculationStep;
use Taxmod\Core\Addon\ChosenAddon;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RecordRepository;
use Taxmod\Core\Repository\RelationRepository;
use Taxmod\Core\Repository\TypeNodes;

/**
 * Schreibt die gerechneten Felder neu — nach jeder Änderung an einem Satz oder an einem seiner Teile
 * ([D-1022](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Gespeichert, nicht beim Lesen gerechnet: so lesen Maske, Block, `read-node`, Filter und die Module den Wert wie jeden anderen —
 * derselbe Weg wie die Zusammenfassung ([D-885](../../../docs/NewConcept/90-decision-log.md), {@see SummaryWriter}) und wie
 * [D-140](../../../docs/NewConcept/90-decision-log.md) es für Rechnungen über Felder desselben Satzes vorsah.*
 *
 * ```mermaid
 * flowchart LR
 *   W["Wert geschrieben"] --> H["Halter gesucht: der Teil meldet seinen Satz"]
 *   H --> S["Rechenschritte der Kanten am Knoten des Satzes"]
 *   S --> R["0 + a + b ÷ c, in Zehntausendsteln"]
 *   R --> E["geschrieben, nur wenn es sich ändert; nicht rechenbar heisst leer"]
 * ```
 *
 * *Ein Betrag mit Einheit (Einheitenwert) rechnet mit seiner Zahl; das Ergebnis bekommt Einheit, Präfix und Kurs des ersten Betrags.
 * Stehen zwei Beträge in verschiedenen Einheiten, ist das Feld nicht rechenbar und bleibt leer — umgerechnet wird nicht.*
 */
final class CalculationWriter
{
    /** Wie viele Stufen nach oben ein Teil seinen Satz meldet — wie bei {@see SummaryWriter}. */
    private const STUFEN = 3;

    /** Zehntausendstel, wie der Import Beträge schreibt ({@see \Taxmod\Core\Import\Money}). */
    private const SCALE = 10000;

    public function __construct(
        private readonly RecordRepository $records,
        private readonly RelationRepository $relations,
        private readonly NodeRepository $nodes,
        private readonly FrameworkNodes $framework,
        private readonly TypeNodes $typeNodes,
        private readonly SettingsResolver $resolver,
        private readonly DataEntry $data,
        /** Die versteckte Kante `kurs` am Einheitenwert ([D-966](../../../docs/NewConcept/90-decision-log.md)) — sie ist keine Zahl, mit der gerechnet wird. */
        private readonly ?int $rateFieldId = null,
    ) {
    }

    /**
     * Die Rechenschritte einer Kante, in ihrer Reihenfolge — leer heisst: das Feld wird eingegeben, nicht gerechnet.
     *
     * ⚠️ *Nur Schritte **an der Kante** zählen: ein Schritt nennt Felder des Knotens, von dem die Kante ausgeht, und am Zieltyp
     * (Einheitenwert, Zahl) hätte er keine.*
     *
     * @return list<array{0: CalculationOperation, 1: int}>
     */
    public function stepsOf(Relation $relation): array
    {
        if ($relation->isSetting() || $relation->kind === RelationKind::Aggregation) {
            return [];
        }

        $ziel = $this->nodes->find($relation->toNodeId);

        if ($ziel === null) {
            return [];
        }

        $schritte = [];

        foreach ($this->resolver->addonsAt($ziel, $relation) as $gewaehlt) {
            $schritt = $this->schritt($gewaehlt);

            if ($schritt !== null) {
                $schritte[] = $schritt;
            }
        }

        return $schritte;
    }

    /**
     * Die gerechneten Felder dieser Sätze und ihrer Besitzer neu schreiben.
     *
     * @param  list<int> $recordIds
     * @return int Wie viele Felder sich geändert haben.
     */
    public function refresh(array $recordIds): int
    {
        $betroffen = $this->mitBesitzern($recordIds);

        if ($betroffen === []) {
            return 0;
        }

        $knotenJeSatz = [];

        foreach ($this->records->byIds($betroffen) as $satz) {
            $knotenJeSatz[$satz->id] = $satz->nodeId;
        }

        // *Je Knoten einmal fragen, welche Felder gerechnet werden (`CD-7`).*
        $formelnJeKnoten = [];

        foreach (array_unique(array_values($knotenJeSatz)) as $knotenId) {
            $knoten = $this->nodes->find($knotenId);

            if ($knoten !== null) {
                $formelnJeKnoten[$knotenId] = $this->formelnAn($knoten);
            }
        }

        $saetze = array_keys(array_filter($knotenJeSatz, static fn (int $knotenId): bool => ($formelnJeKnoten[$knotenId] ?? []) !== []));

        if ($saetze === []) {
            return 0;
        }

        $werte = $this->records->valuesOfMany($saetze);
        $teile = $this->records->valuesOfMany($this->teilVerweise($werte));
        $teilKnoten = [];

        foreach ($this->records->byIds(array_keys($teile)) as $teil) {
            $teilKnoten[$teil->id] = $teil->nodeId;
        }

        $geaendert = 0;

        foreach ($saetze as $satzId) {
            $nachFeld = $this->nachFeld($werte[$satzId] ?? []);

            foreach ($formelnJeKnoten[$knotenJeSatz[$satzId]] as ['relation' => $kante, 'steps' => $schritte]) {
                $ergebnis = $this->rechnen($schritte, $nachFeld, $teile, $teilKnoten);

                if ($this->schreiben($satzId, $kante, $ergebnis, $nachFeld[$kante->id] ?? null, $teile, $teilKnoten)) {
                    ++$geaendert;
                }
            }
        }

        return $geaendert;
    }

    /**
     * Alle Sätze eines Knotens und seiner Kinder neu rechnen — nach einer geänderten Formel.
     *
     * @return int Wie viele Felder sich geändert haben.
     */
    public function refreshNode(int $nodeId): int
    {
        $knoten = $this->nodes->find($nodeId);

        if ($knoten === null) {
            return 0;
        }

        $ids = [];

        foreach ($this->records->ofNodes(array_map(static fn (Node $n): int => $n->id, [$knoten, ...$this->nodes->subtreeOf($knoten)])) as $saetze) {
            foreach ($saetze as $satz) {
                $ids[] = $satz->id;
            }
        }

        return $ids === [] ? 0 : $this->refresh(array_values(array_unique($ids)));
    }

    /**
     * Das Ergebnis der Schritte: eine Zahl in Zehntausendsteln und, wenn ein Betrag mit Einheit dabei war, dessen Teil als Muster.
     *
     * ⚠️ *Ein leeres Feld in `+` oder `−` zählt null — sein Wort: «0 plus irgendwas ergibt ja irgendwas». In `×` oder `÷` macht es das
     * Ergebnis unrechenbar, ebenso ein Text, zwei Einheiten oder eine Division durch null. Ganz ohne einen Wert gibt es nichts.*
     *
     * @param  list<array{0: CalculationOperation, 1: int}> $schritte
     * @param  array<int, TypedValue>                        $nachFeld
     * @param  array<int, list<RelationRecord>>              $teile
     * @param  array<int, int>                               $teilKnoten
     * @return array{units: int, shape: ?int}|null
     */
    public function rechnen(array $schritte, array $nachFeld, array $teile, array $teilKnoten = []): ?array
    {
        $summe = 0;
        $muster = null;
        $einheit = null;
        $gesehen = false;

        foreach ($schritte as [$art, $feld]) {
            $wert = $nachFeld[$feld] ?? null;
            $zahl = null;

            if ($wert !== null && ! $wert->isNothing()) {
                $operand = $this->operand($wert, $teile);

                if ($operand === null) {
                    return null;
                }

                [$zahl, $teilId, $form] = $operand;

                if ($teilId !== null && $zahl !== null) {
                    if ($einheit !== null && $einheit !== $form) {
                        return null;
                    }

                    $einheit ??= $form;
                    $muster ??= $teilId;
                }

                // *Nur ein Summand trägt das Ergebnis — eine Anzahl allein ist kein Preis.*
                $gesehen = $gesehen || ($zahl !== null && ($art === CalculationOperation::Plus || $art === CalculationOperation::Minus));
            }

            if ($zahl === null && ($art === CalculationOperation::Times || $art === CalculationOperation::DividedBy)) {
                return null;
            }

            $zahl ??= 0;

            $summe = match ($art) {
                CalculationOperation::Plus      => $summe + $zahl,
                CalculationOperation::Minus     => $summe - $zahl,
                CalculationOperation::Times     => self::mal($summe, $zahl),
                CalculationOperation::DividedBy => $zahl === 0 ? null : (int) round($summe * self::SCALE / $zahl),
            };

            if (! is_int($summe)) {
                return null;
            }
        }

        return $gesehen ? ['units' => $summe, 'shape' => $muster] : null;
    }

    /** Eine Zahl in Zehntausendsteln als Text, ohne Nullen am Ende — wie der Import sie schreibt. */
    public static function format(int $units): string
    {
        $text = sprintf('%s%d.%04d', $units < 0 ? '-' : '', intdiv(abs($units), self::SCALE), abs($units) % self::SCALE);

        return rtrim(rtrim($text, '0'), '.');
    }

    /** Ein Dezimaltext als Zehntausendstel, kaufmännisch gerundet; `null`, wo er keine Zahl ist. */
    public static function units(string $decimal): ?int
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', trim($decimal), $m) !== 1) {
            return null;
        }

        $nachkomma = str_pad(substr($m[3] ?? '', 0, 5), 5, '0');
        $units = (int) $m[2] * self::SCALE + intdiv((int) $nachkomma + 5, 10);

        return $m[1] === '-' ? -$units : $units;
    }

    /** ⚠️ *Ein Produkt, das nicht in eine Ganzzahl passt, ist nicht rechenbar — PHP machte daraus still eine Fliesskommazahl.* */
    private static function mal(int $a, int $b): ?int
    {
        $roh = $a * $b;

        return is_int($roh) ? (int) round($roh / self::SCALE) : null;
    }

    /**
     * Was ein Wert zum Rechnen beiträgt: die Zahl, und bei einem Teil dessen Id und Form (Einheit, Präfix — alles ausser Zahl und Kurs).
     *
     * @param  array<int, list<RelationRecord>> $teile
     * @return array{0: ?int, 1: ?int, 2: ?string}|null
     */
    private function operand(TypedValue $wert, array $teile): ?array
    {
        if ($wert->int !== null) {
            return [$wert->int * self::SCALE, null, null];
        }

        if ($wert->decimal !== null) {
            $units = self::units($wert->decimal);

            return $units === null ? null : [$units, null, null];
        }

        if ($wert->reference === null || $wert->referenceSpace !== \Taxmod\Core\Model\ReferenceSpace::Record || ! isset($teile[$wert->reference])) {
            return null;
        }

        [$betrag, $form, $zahlen] = $this->betragUndForm($teile[$wert->reference]);

        // ⚠️ *Ein Teil ohne Zahl ist ein leeres Feld — ein Pflichtteil steht leer da, sobald der Satz entsteht.*
        if ($zahlen === 0) {
            return [null, $wert->reference, $form];
        }

        if ($betrag === null) {
            return null;
        }

        $units = self::units($betrag->value->decimal ?? '');

        return $units === null ? null : [$units, $wert->reference, $form];
    }

    /**
     * Die eine Zahl eines Teils und, als Text, was ihn sonst ausmacht.
     *
     * @param  list<RelationRecord> $zeilen
     * @return array{0: ?RelationRecord, 1: string, 2: int} die Zahl, wenn es genau eine ist; die Form; wie viele Zahlen
     */
    private function betragUndForm(array $zeilen): array
    {
        $betraege = [];
        $form = [];

        foreach ($zeilen as $zeile) {
            if ($zeile->relationId === $this->rateFieldId || $zeile->locale !== '') {
                continue;
            }

            if ($zeile->value->decimal !== null) {
                $betraege[] = $zeile;
            } else {
                $form[] = $zeile->relationId . '=' . $zeile->value->rawValue();
            }
        }

        sort($form);

        return [count($betraege) === 1 ? $betraege[0] : null, implode(';', $form), count($betraege)];
    }

    /**
     * Das Ergebnis ins Feld — nur, wenn es sich ändert. Ein Betrag geht in einen Teil derselben Art wie sein Muster.
     *
     * @param array{units: int, shape: ?int}|null $ergebnis
     * @param array<int, list<RelationRecord>>    $teile
     * @param array<int, int>                     $teilKnoten
     */
    private function schreiben(int $satzId, Relation $kante, ?array $ergebnis, ?TypedValue $steht, array $teile, array $teilKnoten): bool
    {
        $teilId = $steht !== null && $steht->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Record ? $steht->reference : null;

        // ⚠️ *Ein Betrag nur in einen Teil derselben Art wie die Beträge, aus denen er kommt — sonst wäre er nicht rechenbar.*
        if ($ergebnis !== null && $ergebnis['shape'] !== null && ($teilKnoten[$ergebnis['shape']] ?? null) !== $kante->toNodeId) {
            $ergebnis = null;
        }

        // ⚠️ *Eine blosse Zahl nur in ein Feld eines einfachen Typs. Gemessen am 2026-10-29 auf fambach.net: ein Kauf ohne Stückpreis,
        // aber mit Anzahl, ergab eine Zahl ohne Einheit, und die liess sich in den Einheitenwert nicht schreiben — das brach den Lauf ab.*
        if ($ergebnis !== null && $ergebnis['shape'] === null && $this->typeNodes->typeOf($kante->toNodeId) === null) {
            $ergebnis = null;
        }

        if ($ergebnis === null) {
            // ⚠️ *Nicht rechenbar heisst leer, nie der alte Wert: eine stehengebliebene Zahl sähe aus wie eine gerechnete (D-104).*
            if ($teilId !== null && ! $kante->multiplicity->requiresOne()) {
                $this->data->removeRecord($teilId);

                return true;
            }

            // *Ein Pflichtteil bleibt stehen, nur seine Zahl geht.*
            if ($teilId !== null) {
                [$betrag] = $this->betragUndForm($teile[$teilId] ?? $this->records->valuesOf($teilId));

                if ($betrag === null) {
                    return false;
                }

                $this->data->clear($teilId, $betrag->relationId);

                return true;
            }

            if ($steht !== null) {
                $this->data->clear($satzId, $kante->id);

                return true;
            }

            return false;
        }

        $text = self::format($ergebnis['units']);

        if ($ergebnis['shape'] === null) {
            $wert = $this->typeNodes->typeOf($kante->toNodeId) === SimpleType::Int
                ? TypedValue::ofInt((int) round($ergebnis['units'] / self::SCALE))
                : TypedValue::ofDecimal($text);

            if ($steht !== null && $steht->equals($wert)) {
                return false;
            }

            $this->data->put($satzId, $kante->id, $wert);

            return true;
        }

        return $this->betragSchreiben($satzId, $kante, $text, $teile[$ergebnis['shape']] ?? [], $teilId === null ? [] : ($teile[$teilId] ?? $this->records->valuesOf($teilId)), $teilId);
    }

    /**
     * Zahl, Einheit, Präfix und Kurs des Musters in den Teil des Feldes; ohne Teil wird einer angelegt.
     *
     * @param list<RelationRecord> $muster
     * @param list<RelationRecord> $ist
     */
    private function betragSchreiben(int $satzId, Relation $kante, string $text, array $muster, array $ist, ?int $teilId): bool
    {
        $soll = [];

        foreach ($muster as $zeile) {
            if ($zeile->locale === '') {
                $soll[$zeile->relationId] = $zeile->value;
            }
        }

        [$betrag] = $this->betragUndForm($muster);

        if ($betrag === null) {
            return false;
        }

        $soll[$betrag->relationId] = TypedValue::ofDecimal($text);

        $vorher = [];

        foreach ($ist as $zeile) {
            if ($zeile->locale === '') {
                $vorher[$zeile->relationId] = $zeile->value;
            }
        }

        $gleich = count($vorher) === count($soll);

        foreach ($soll as $feld => $wert) {
            $gleich = $gleich && isset($vorher[$feld]) && $vorher[$feld]->equals($wert);
        }

        if ($gleich) {
            return false;
        }

        $teilId ??= $this->data->createPart($satzId, $kante->id)->id;

        foreach ($soll as $feld => $wert) {
            if (! isset($vorher[$feld]) || ! $vorher[$feld]->equals($wert)) {
                $this->data->put($teilId, $feld, $wert);
            }
        }

        foreach (array_diff_key($vorher, $soll) as $feld => $_) {
            $this->data->clear($teilId, $feld);
        }

        return true;
    }

    /**
     * Die gerechneten Felder eines Knotens mit ihren Schritten.
     *
     * @return list<array{relation: Relation, steps: list<array{0: CalculationOperation, 1: int}>}>
     */
    private function formelnAn(Node $knoten): array
    {
        $aus = [];

        foreach ($this->relations->fieldRelationsOf($this->framework->inheritanceOwnersOf($knoten)) as $kante) {
            $schritte = $this->stepsOf($kante);

            if ($schritte !== []) {
                $aus[] = ['relation' => $kante, 'steps' => $schritte];
            }
        }

        return $aus;
    }

    /** @return array{0: CalculationOperation, 1: int}|null */
    private function schritt(ChosenAddon $gewaehlt): ?array
    {
        if ($gewaehlt->klasse !== CalculationStep::class || ! $gewaehlt->setHere) {
            return null;
        }

        $feld = ($gewaehlt->settings['field'] ?? null)?->reference;
        $art = CalculationOperation::tryFrom((string) (($gewaehlt->settings['operation'] ?? null)?->text ?? '')) ?? CalculationOperation::Plus;

        return $feld === null ? null : [$art, $feld];
    }

    /**
     * Der letzte Wert je Feld, ohne Sprache.
     *
     * ⚠️ *Der letzte, nicht der erste: gemessen am 2026-10-29 auf fambach.net tragen 84 Käufe «Preis je Stück» und «Preis gesamt»
     * zweimal, und ihr gespeicherter Einstandspreis war aus dem zuletzt geschriebenen Teil gerechnet. Mit dem ersten änderten sich
     * 74 davon. Das Feld selbst ist einwertig — die Doppel sind ein Fehler im Bestand, kein zweiter Wert.*
     *
     * @param  list<RelationRecord>   $zeilen
     * @return array<int, TypedValue>
     */
    private function nachFeld(array $zeilen): array
    {
        $aus = [];

        foreach ($zeilen as $zeile) {
            if ($zeile->locale === '') {
                $aus[$zeile->relationId] = $zeile->value;
            }
        }

        return $aus;
    }

    /**
     * @param  array<int, list<RelationRecord>> $werte
     * @return list<int>
     */
    private function teilVerweise(array $werte): array
    {
        $ids = [];

        foreach ($werte as $zeilen) {
            foreach ($zeilen as $zeile) {
                if ($zeile->value->referenceSpace === \Taxmod\Core\Model\ReferenceSpace::Record && $zeile->value->reference !== null) {
                    $ids[$zeile->value->reference] = true;
                }
            }
        }

        return array_keys($ids);
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

            $halter = array_map(static fn (RelationRecord $zeile): int => $zeile->recordId, array_values($this->records->holdersOf($runde)));
            $runde  = array_values(array_diff($halter, array_keys($alle)));
        }

        return array_map('intval', array_keys($alle));
    }
}
