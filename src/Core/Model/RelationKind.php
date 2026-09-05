<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The kinds of relation — and the kind is never chosen.
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

    // ⚠️ **Hier standen `isComposition()` und `isSetting()`, und sie sind Verhalten geworden**
    // ([D-639](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «je Wert eine Klasse, wie
    // bei den Knoten.» Was hier als `$this === self::Setting` stand, steht jetzt in
    // {@see \Taxmod\Core\Model\Edge\SettingEdge}, {@see \Taxmod\Core\Model\Edge\AggregationEdge} und
    // {@see \Taxmod\Core\Model\Edge\CompositionEdge} — und das «erbt von», das eine Aufzählung in PHP
    // nicht ausdrücken konnte, braucht keinen Ausdruck mehr: es heisst
    // {@see \Taxmod\Core\Model\Relation::deletesRecordWithOwner()} und ist an zwei der drei Klassen
    // wahr.*
    //
    // ⚠️ *Die Aufzählung bleibt, weil die **Spalte** bleibt: sie ist der Wert in der Zeile, aus dem
    // {@see \Taxmod\Core\Model\Relation::classFor()} an genau einer Stelle die Klasse baut. **Der
    // Klassenname steht nicht in der Zeile** — die Menge ist geschlossen und hat drei Elemente.*
}
