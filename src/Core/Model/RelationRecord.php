<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One value inside a record, addressed by the relation that reaches it.
 *
 * ```mermaid
 * flowchart LR
 *   R["record · node_record_id"] --> E["relation_id · 101"] --> V["value"]
 * ```
 *
 * ⚠️ **Die Adresse ist **eine** Zahl, seit der Satz sagt, wem er gehört**
 * ([D-667](../../../docs/NewConcept/90-decision-log.md), TASK-002). *Daneben stand bis Fassung 39
 * eine Spalte `path`, die die Kette der Kanten als Text führte — **gemessen am 2026-09-06 trug jede
 * lebende Zeile darin ihre eigene `relation_id` und sonst nichts**: null Abweichungen, null
 * mehrteilige. Wo der Pfad wirklich zwei Nummern trug, sagt die äussere jetzt der Satz
 * (`node_records.relation_id`). **Sein Wort zu dem Zwischenschritt, den er verworfen hat:** «also
 * verklausulierst du path als Text».*
 *
 * ⚠️ **`relation_id` ist indiziert, und das war schon [D-134](../../../docs/NewConcept/90-decision-log.md)s
 * Grund:** *`WHERE relation_id = … AND value_decimal > 1000` findet jeden Preis über tausend, **wo
 * immer er sitzt** — ein indizierter Zugriff und kein `LIKE` über einen Text.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RelationRecord
{
    /**
     * @param int    $relationId Die Kante, die diesen Wert im Modell des Datensatzes erreicht.
     * @param string $locale Empty unless the attribute is declared translatable (D-317).
     */
    public function __construct(
        public readonly int $recordId,
        public readonly int $relationId,
        public readonly string $locale,
        public readonly TypedValue $value,
        /**
         * Die Zeile selbst — **`null`, solange sie nicht geschrieben ist**.
         *
         * ⚠️ **Sie ist es, die mehrere Werte eines Feldes trennt** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
         * *Der Eigentümer, als eine laufende Nummer im Pfad auftauchte: «warum führen wir jetzt eine
         * neue Zahl ein, wo wir doch die Id des Records haben?» — **es gab sie schon**, die Spalte ist
         * seit jeher `AUTO_INCREMENT`, und niemand hat sie benutzt.*
         */
        public readonly ?int $id = null,
        /**
         * Wo dieser Wert unter seinen Geschwistern steht.
         *
         * ⚠️ *Getrennt von der Id, **weil eine Id nur die Eingabereihenfolge kennt**: umsortieren
         * hiesse sonst, Zeilen neu zu schreiben. Dieselbe Form wie `relations.position` seit
         * [D-407](../../../docs/NewConcept/90-decision-log.md).*
         */
        public readonly int $position = 0,
    ) {
    }

    /**
     * Ein Wert, den **eine** Kante vom Modell des Datensatzes aus erreicht.
     *
     * ⚠️ **Auch der zweite und dritte Wert desselben Feldes gehen hier durch** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
     * *Mehrere Werte sind mehrere **Zeilen** auf derselben Kante — sie werden durch ihre eigene Id
     * unterschieden und durch `position` geordnet.*
     *
     * ⚠️ *Hier stand daneben ein `at()`, das eine Kette von Kanten zu einem Pfad zusammensetzte. **Es
     * ist mit der Spalte gefallen** (Fassung 39, TASK-002): eine Verwendungsstelle steht am Satz
     * ([D-667](../../../docs/NewConcept/90-decision-log.md)), nicht in der Adresse des Wertes.*
     */
    public static function direct(int $recordId, int $relationId, TypedValue $value, string $locale = '', int $position = 0): self
    {
        return new self($recordId, $relationId, $locale, $value, null, $position);
    }

    /** Dieselbe Zeile, nachdem der Speicher ihr eine Id gegeben hat. */
    public function stored(int $id): self
    {
        return new self($this->recordId, $this->relationId, $this->locale, $this->value, $id, $this->position);
    }

    /** Derselbe Wert an einer anderen Stelle unter seinen Geschwistern. */
    public function movedTo(int $position): self
    {
        return new self($this->recordId, $this->relationId, $this->locale, $this->value, $this->id, $position);
    }
}
