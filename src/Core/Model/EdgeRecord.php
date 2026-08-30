<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One value inside a record, addressed by the path that reaches it.
 *
 * ```mermaid
 * flowchart LR
 *   R["record"] --> P["path · 100.101"] --> V["value"]
 *   P --> E["edge_id · 101 · the last step"]
 * ```
 *
 * ⚠️ **The last edge is kept alongside the path** (D-134), and that is what makes the data
 * searchable at all: `WHERE edge_id = … AND value_decimal > 1000` finds every price over a
 * thousand **wherever it sits**, and adding the path narrows it to one attribute. Without the
 * separate column the same question would need a `LIKE` over a text path.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class EdgeRecord
{
    /**
     * @param string $path   Edge ids from the record's model down to this value, `.`-separated.
     * @param int    $edgeId The last step of that path, kept apart so it can be indexed.
     * @param string $locale Empty unless the attribute is declared translatable (D-317).
     */
    public function __construct(
        public readonly int $recordId,
        public readonly string $path,
        public readonly int $edgeId,
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
     * *Mehrere Werte sind mehrere **Zeilen** auf derselben Kante, nicht mehrere Pfade — sie werden
     * durch ihre eigene Id unterschieden und durch `position` geordnet. **Der Pfad bleibt, was der
     * Docblock oben sagt: Kanten-Ids.***
     */
    public static function direct(int $recordId, int $edgeId, TypedValue $value, string $locale = '', int $position = 0): self
    {
        return new self($recordId, (string) $edgeId, $edgeId, $locale, $value, null, $position);
    }

    /** Dieselbe Zeile, nachdem der Speicher ihr eine Id gegeben hat. */
    public function stored(int $id): self
    {
        return new self($this->recordId, $this->path, $this->edgeId, $this->locale, $this->value, $id, $this->position);
    }

    /** Derselbe Wert an einer anderen Stelle unter seinen Geschwistern. */
    public function movedTo(int $position): self
    {
        return new self($this->recordId, $this->path, $this->edgeId, $this->locale, $this->value, $this->id, $position);
    }
}
