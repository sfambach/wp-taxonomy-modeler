<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Setting;

/**
 * Ein Glied einer Listeneinstellung, wie die Maske es zeigt: sein Wort, ob es an ist, seine Stelle —
 * und ob es hier gesetzt oder von der Kante aus gesehen geerbt ist.
 *
 * ⚠️ **Sein Wort zur Liste an der Kante** ([D-712](../../../../docs/NewConcept/90-decision-log.md), Z3/Z3a):
 * *«Z3 ergänzen mit änderbarer Reihenfolge»* und *«standard aktiv, haken raus nicht mehr aktiv»* — ein
 * geerbtes Glied bleibt an der Kante sichtbar, mit seinem Schalter; abschalten heisst nicht löschen.
 *
 * @see docs/einstellungen-anforderungen.md §5.5
 */
final class ListEntry
{
    public function __construct(
        /** Die Zeile in `settings_value`, über die das Glied angesprochen wird. */
        public readonly int $rowId,
        public readonly string $word,
        public readonly bool $aktiv,
        public readonly int $position,
        /** Am Knoten: immer; an der Kante: nur, wenn die Zeile die Kante nennt. */
        public readonly bool $setHere,
        /** Der Knoten, auf den das Glied zeigt — für eine Kaskade von Schaltern je Kandidat (D-732). */
        public readonly ?int $reference = null,
    ) {
    }
}
