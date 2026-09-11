<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The branch a node sits in — and with it three answers nobody sets by hand.
 *
 * ⚠️ **The relation kind is never asked.** The author picks a **target**; the kind follows from
 * where that target lives (sentence 5 of the core on one page, D-161). That removes the error
 * the whole storage rule exists to prevent — a supplier accidentally *composed* into an order,
 * so every order breeds its own supplier — **not by validating it afterwards but by never
 * offering it.**
 *
 * ⚠️ **Multiplicity plays no part in storage** (D-232). Five integers are five **paths** in one
 * record, not five records.
 *
 * ⚠️ **Der Ast `Settings` ist mit Schritt 7 des Bauplans (2026-09-11) gefallen**
 * ([D-718](../../../docs/NewConcept/90-decision-log.md), sein Wort: *«knoten und felder können weg»*).
 * *Renderer, Konverter und Validatoren sind Objekte programmierter Klassen ([D-712](../../../docs/NewConcept/90-decision-log.md)),
 * keine Knoten; die Rollen wohnen unter `Constants` («K3c unter constants», [D-719](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ```mermaid
 * flowchart TB
 *   R["Root"] --> M["Model"]
 *   R --> C["Compositions"]
 *   R --> P["Primitives"]
 *   P --> DT["Data Types"]
 *   P --> K["Constants"]
 *   P --> CB["Combined"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Branch: string
{
    /** Things that stand on their own — a supplier, a board. Reached by aggregation. */
    case Model = 'model';

    /** Things owned by whatever holds them, deleted with it. Reached by composition. */
    case Compositions = 'compositions';

    /** Integer, text, quantity — no instances of their own; the value lives in the record. */
    case DataTypes = 'data-types';

    /** Fixed values a person may extend — a unit, a colour. The value is a reference to a node. */
    case Constants = 'constants';

    /**
     * Zusammengesetzte Datentypen — eine Adresse, ein Einheitenwert: aus Feldern gebaut, aber
     * **ohne eigene Benutzerdaten**.
     *
     * ⚠️ **Sein Wort, und es ist die Regel dieses Astes** ([D-677](../../../docs/NewConcept/90-decision-log.md)):
     * *«der underschied zwischen composition und combined ist das combined keine user daten enthält
     * nur example oder default wie bei typ. compositoins sind modelle (submodelle) und enthalten
     * daten, somit wäre das die regel an Primitives aber kein ausschluss.»*
     *
     * ⚠️ *Damit steht er da, wo er hingehört: **unter `Primitives`**, neben `Data Types` und
     * `Constants`, und teilt deren Regel — nur `default` und `example`, nie `user`
     * ([D-664](../../../docs/NewConcept/90-decision-log.md)). **Was ihn von den einfachen Typen
     * trennt, ist allein, dass sein Wert aus mehreren Feldern besteht** und deshalb einen eigenen
     * Satz braucht, so wie eine Zusammensetzung.*
     *
     * ⚠️ **Und er war der Grund für einen Ausschluss, der auf nichts stand.** *`Combined` lag in
     * keinem Ast, also war jeder Knoten darunter im Feldziel-Dialog gesperrt — gegen
     * [D-238](../../../docs/NewConcept/90-decision-log.md), das alles ausser der Astwurzel für
     * wählbar erklärt. Sein Befund: «combined zählt definitiv nicht dazu».*
     */
    case Combined = 'combined';

    /** Which kind of relation reaches a node in this branch (D-161). */
    public function relationKind(): RelationKind
    {
        return match ($this) {
            self::Model, self::Constants                         => RelationKind::Aggregation,
            self::Compositions, self::DataTypes, self::Combined  => RelationKind::Composition,
        };
    }

    /**
     * Ob dieser Ast unter `Primitives` liegt — und damit dessen Regel trägt.
     *
     * ⚠️ **Die Regel steht seit [D-677](../../../docs/NewConcept/90-decision-log.md) an
     * `Primitives` und nicht mehr an `Data Types`.** *Sein Wort: «vielleicht müssen wir data types
     * regeln nach oben zu primitives schicken.» **Es war schon zweimal dieselbe Regel an zwei
     * Stellen** — [D-664](../../../docs/NewConcept/90-decision-log.md) für die einfachen Typen und,
     * ungeschrieben, für die Konstanten; `Combined` wäre die dritte Abschrift geworden.*
     *
     * ⚠️ *Was daran hängt: **nur `default` und `example`, nie `user`** — und der Datensatzblock
     * fragt danach, statt einen Ast beim Namen zu nennen.*
     */
    public function underPrimitives(): bool
    {
        return match ($this) {
            self::DataTypes, self::Constants, self::Combined => true,
            self::Model, self::Compositions                  => false,
        };
    }

    /** Whether nodes in this branch have records of their own (D-183). */
    public function holdsData(): bool
    {
        // ⚠️ **`Combined` steht bei den beiden anderen unter `Primitives` und nicht bei
        // `Compositions`** ([D-677](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «der
        // underschied zwischen composition und combined ist das combined keine user daten enthält
        // nur example oder default wie bei typ». **Aus Feldern gebaut zu sein und Benutzerdaten zu
        // halten sind zwei verschiedene Fragen** — hier wird die zweite beantwortet.*
        return match ($this) {
            self::Model, self::Compositions                   => true,
            self::DataTypes, self::Constants, self::Combined  => false,
        };
    }

    /** Where a value given through such an relation is kept (D-232). */
    public function storage(): Storage
    {
        return match ($this) {
            self::Model        => Storage::ExternalReference,
            // ⚠️ *`Combined` speichert wie eine Zusammensetzung, **weil sein Wert aus mehreren
            // Feldern besteht** und in eine Zeile nicht passt. Das ist die eine Frage, in der er
            // den `Compositions` gleicht — und die einzige.*
            self::Compositions, self::Combined => Storage::OwnRecords,
            self::DataTypes    => Storage::InsideTheRecord,
            self::Constants    => Storage::NodeRef,
        };
    }
}
