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
     * @param SeededRole    $role      Welche Rolle — seit TASK-019 eine Spalte und keine Knotennummer.
     * @param string        $number    A plural category — `one`, `other`, and where a language needs
     *                                 them `zero`, `two`, `few`, `many` (D-216). Sparsely filled.
     * @param string        $locale    Die Sprache. **Leer gibt es nicht mehr**: seit
     *                                 [D-645](../../../docs/NewConcept/90-decision-log.md) tritt an
     *                                 die Stelle der sprachneutralen Zeile die **Standardsprache**,
     *                                 und die steht auf der Installationsseite
     *                                 ([D-387](../../../docs/NewConcept/90-decision-log.md)).
     * @param int           $version   Die Zeilennummer dieser Beschriftung.
     *
     * ⚠️ **`ownerKind` ist verpflichtend und hat ausdrücklich keine Vorgabe** (`INF-035`,
     * [D-597](../../../docs/NewConcept/90-decision-log.md)). *Eine Vorgabe wäre genau das Raten, das
     * hier abgeschafft wird: ein Label hängt an einem Knoten **oder** an einer Kante
     * ([D-410](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **`role` war bis TASK-019 eine Knotennummer** ([D-151](../../../docs/NewConcept/90-decision-log.md)).
     * *Seit [D-598](../../../docs/NewConcept/90-decision-log.md) sind die Rollen **Spalten** in
     * `label_texts` — «würde das mal auf den Parkplatz für mögliche spätere Entwicklungen schieben und
     * bei vier Spalten bleiben» —, und eine Spalte hat keine Nummer. **Die Rollenknoten bleiben
     * stehen**: sie sind weiterhin das, woraus ein Renderer seine Rolle wählt.*
     *
     * ⚠️ **`path` gibt es nicht mehr.** *Er adressierte eine Stelle **innerhalb** eines Eigentümers
     * ([D-158](../../../docs/NewConcept/90-decision-log.md)); mit dem umgedrehten Verweis aus
     * [D-580](../../../docs/NewConcept/90-decision-log.md) — eine `label_id` je Zeile — gibt es dafür
     * keine Stelle. **Gemessen trug keine einzige der 52 Beschriftungen einen Pfad**; die Frage steht
     * als `INF-043` im Eingang und wird hier nicht beantwortet (`PR-4`).*
     *
     * ⚠️ **`version` ist die Zeilennummer, keine Änderungsgruppe** — dieselbe Spalte, die `nodes`,
     * `relations`, `node_records` und `relation_records` schon tragen
     * ([D-634](../../../docs/NewConcept/90-decision-log.md),
     * [D-640](../../../docs/NewConcept/90-decision-log.md)). **Eine frisch gebaute Beschriftung ist
     * Version 1**; die Ablage zählt beim Überschreiben hoch.
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly IdentitySpace $ownerKind,
        public readonly SeededRole $role,
        public readonly string $number,
        public readonly string $locale,
        public readonly string $text,
        public readonly int $version = 1,
    ) {
    }

    /** The base plural category, and the one every fallback lands on. */
    public const BASE_NUMBER = 'one';
}
