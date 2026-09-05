<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The human-readable text of an identity, in one role, one plural category and one locale.
 *
 * ⚠️ **Not the same mechanism as a software string** (AR-2). What the plugin itself says goes
 * through the WordPress text domain; what a user called their node is a label stored in the
 * model. The two never share a mechanism, and confusing them is how a translated interface ends
 * up renaming somebody's data.
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class Label
{
    /**
     * @param int           $ownerId   A node **or** an relation — labels hang on an identity (C8).
     * @param IdentitySpace $ownerKind Welcher der beiden Id-Räume gemeint ist.
     * @param string        $path      Which thing inside that owner, as a path of relation ids. Empty
     *                                 for the owner itself; used to reach one validator of several (D-158).
     * @param int           $roleId    A role **node** (D-151), not a constant.
     * @param string        $number    A plural category — `one`, `other`, and where a language needs
     *                                 them `zero`, `two`, `few`, `many` (D-216). Sparsely filled.
     * @param string        $locale    Empty means locale-neutral.
     * @param int           $version   Die Zeilennummer dieser Beschriftung.
     *
     * ⚠️ **`ownerKind` ist verpflichtend und hat ausdrücklich keine Vorgabe** (`INF-035`,
     * [D-597](../../../docs/NewConcept/90-decision-log.md)). *Eine Vorgabe wäre genau das Raten, das
     * hier abgeschafft wird: ein Label hängt an einem Knoten **oder** an einer Kante
     * ([D-410](../../../docs/NewConcept/90-decision-log.md)), und seit jede Tabelle ihren eigenen
     * Id-Raum hat (TASK-004), sagt die Nummer allein nicht mehr, an welchem. **Gemessen am
     * 2026-09-05**, als eine frische Kante die fünf Beschriftungen eines gleichnummerigen Knotens
     * zurückbekam.*
     *
     * ⚠️ **`version` ist die Zeilennummer, keine Änderungsgruppe** — dieselbe Spalte, die `nodes`,
     * `relations`, `node_records` und `relation_records` schon tragen. *Sie kam am 2026-09-05 dazu,
     * weil [D-634](../../../docs/NewConcept/90-decision-log.md) die Version beim Melden zum
     * Pflichtwert macht und `labels` als **einzige** Tabelle keine hatte: {@see \Taxmod\Core\Service\Labels}
     * musste dem Journal `null` hinschreiben. **Eine frisch gebaute Beschriftung ist Version 1**; die
     * Ablage zählt beim Überschreiben hoch.*
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly IdentitySpace $ownerKind,
        public readonly string $path,
        public readonly int $roleId,
        public readonly string $number,
        public readonly string $locale,
        public readonly string $text,
        public readonly int $version = 1,
    ) {
    }

    /** The base plural category, and the one every fallback lands on. */
    public const BASE_NUMBER = 'one';
}
