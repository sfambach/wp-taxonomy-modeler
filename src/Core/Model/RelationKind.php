<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The kinds of edge — and the kind is never chosen.
 *
 * It is **read off** the branch the target sits in (sentence 5 of the core on one page), which
 * is why this enum has no factory taking user input: nothing outside the branch rule may decide
 * a kind.
 *
 * ⚠️ **`Inheritance` stand hier und ist gefallen** (TASK-018,
 * [D-581](../../../docs/NewConcept/90-decision-log.md)). *Sein Satz: «Vererbung ist so
 * unterschiedlich zu Relation, eigentlich würde hier eine `parent_node_id` im Knoten reichen.» Der
 * Beleg lag gemessen daneben — **`name` war leer genau dann, wenn Vererbung, `multiplicity` sagte
 * nichts, `hide` sass nur dort**: drei von neun Spalten verhielten sich anders, und das war das
 * Signal, dass es kein Kantentyp ist. **Der Baum steht jetzt in `nodes.parent_node_id` mit
 * `nodes.sort_order`**, und die Frage «ist es Vererbung?» stellt niemand mehr.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum RelationKind: string
{
    /** The target belongs to the whole and is deleted with it. */
    case Composition = 'composition';

    /** The target is independent and always another node. */
    case Aggregation = 'aggregation';

    /**
     * Eine Einstellung — **eine Komposition, die der Autor setzt statt eines Benutzers**.
     *
     * ⚠️ **[D-526](../../../docs/NewConcept/90-decision-log.md), sein Vorschlag und seine Begründung:**
     * *«nehmen wir an, es gibt nur settings, nicht setting_composition und setting_aggregation, weil
     * settings immer eine Komposition ist — und settings erbt von composition.»* **Und das trägt:**
     * «welchen Renderer benutze **ich**» gehört dem Knoten, auch wenn der Renderer selbst geteilt ist.
     * *Wie der **Wert** gespeichert wird, sagt weiterhin der Ast des Ziels — eine Konstante bleibt ein
     * `NodeRef`.*
     *
     * ⚠️ **Sie ist die einzige Art, die nicht vom Ast abgelesen wird**, und darin liegt ihr Sinn:
     * `Composition` und `Aggregation` folgen aus dem Ort des Ziels
     * ([D-161](../../../docs/NewConcept/90-decision-log.md)), **`Setting` sagt jemand**.
     *
     * ⚠️ *Der Eigentümer hat vorher dreimal denselben Gedanken anders eingekleidet — vierter
     * Relationstyp, `Settings`-Ast, Marke am Zielknoten — und die Messung erledigte die ersten zwei:
     * `Root.validator` ist eine **Aggregation** und eine Einstellung, `Root.renderer` eine
     * **Komposition** und eine Einstellung. **Erst als er entschied, eine Einstellung sei immer eine
     * Komposition, fiel der Widerspruch weg** — und damit wurde aus der vierten Art die richtige
     * Form.*
     *
     * ⚠️ *Und warum sie nicht am **Ziel** hängt: gemessen zeigen `Prefixes.exponent` (Autor),
     * `Passiv.Tolerance` und `Part List Item.Quantity` (Benutzer) alle auf `Integer`. **Ein Knoten
     * kann das nicht auseinanderhalten, eine Kante schon.** Die sieben Hilfsknoten, die es dafür
     * heute gibt, werden damit entbehrlich.*
     */
    case Setting = 'setting';

    /**
     * Ob der Wert dem Besitzer gehört — für `Setting` ebenso wie für `Composition`.
     *
     * ⚠️ **Das ist das «erbt von», das eine Aufzählung in PHP nicht ausdrücken kann.** *Jede Stelle,
     * die «ist das eine Komposition» fragt, muss diese Methode nehmen und nicht `=== Composition` —
     * sonst fällt eine Einstellung stillschweigend durch. **Gemessen vergleicht heute keine Stelle im
     * Kern so**, nur Prüfungen über einzelne Kanten; die Methode steht hier für die erste, die es tun
     * will.*
     */
    public function isComposition(): bool
    {
        return $this === self::Composition || $this === self::Setting;
    }

    /** Ob diese Kante eine Einstellung erklärt statt eines Feldes. */
    public function isSetting(): bool
    {
        return $this === self::Setting;
    }
}
