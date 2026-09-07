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
 * ```mermaid
 * flowchart TB
 *   R["Root"] --> M["Model"]
 *   R --> C["Compositions"]
 *   R --> P["Primitives"]
 *   P --> DT["Data Types"]
 *   P --> K["Constants"]
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

    /**
     * Die Mengen, aus denen eine **Einstellung** ausgewählt wird — Renderer, Wandler, Namensrollen,
     * Validatoren.
     *
     * ⚠️ **Er sagt nicht «das ist eine Einstellung» — das sagt die Kante**
     * ([D-526](../../../docs/NewConcept/90-decision-log.md)). *Der Eigentümer, als ich ihm die
     * Platz-Variante vorschlug: «ich sehe nicht, dass wir unbedingt einen Knoten brauchen, wenn wir
     * eine Einstellungskante auf `int` setzen und sie `exponent` nennen.» **Er hat recht: der Typ kommt
     * vom Ziel, die Einstellung von der Kante.***
     *
     * ⚠️ **Wofür dieser Ast dann da ist:** *eine Einstellung, deren Wert eine **Auswahl** ist, braucht
     * eine Menge, aus der gewählt wird — und diese Mengen lagen bisher verstreut: `Renderer` und
     * `Converter` unter `Constants`, `Label roles` neben den Ästen, wo die Speicherfrage **keine
     * Antwort** hatte ([OQ-139](../../../docs/NewConcept/91-open-questions.md)). Hier haben sie einen
     * Platz, und die Antwort ist dieselbe wie bei `Constants`: der Wert ist ein Knotenverweis.*
     */
    case Settings = 'settings';

    /** Which kind of relation reaches a node in this branch (D-161). */
    public function relationKind(): RelationKind
    {
        return match ($this) {
            self::Model, self::Constants, self::Settings         => RelationKind::Aggregation,
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
            self::Model, self::Compositions, self::Settings  => false,
        };
    }

    /** Whether nodes in this branch have records of their own (D-183). */
    public function holdsData(): bool
    {
        // ⚠️ **`Settings` stand hier auf `false`, und 48 Datensätze sagten das Gegenteil.**
        //
        // ⚠️ *Der Eigentümer fragte, warum die Vorschau von `DisplayOption` «Nothing to preview here»
        // sagt, und vermutete: «weil es Settings sind». **Fast** — es lag nicht an den
        // Einstellungskanten, sondern an dieser Zeile. Gemessen im selben Zug: `SELECT COUNT(*) …
        // node_id = DisplayOption` ergibt **48**, und der Datensatzblock auf derselben Seite listet sie.*
        //
        // ⚠️ **Ein Ast, der «ich halte keine Datensätze» sagt, während 48 an ihm hängen, ist eine
        // Angabe, die ihre eigene Tabelle nicht kennt.** *Sie ist aus der Zeit vor
        // [D-541](../../../docs/NewConcept/90-decision-log.md): dort hat der Eigentümer entschieden, dass
        // eine Einstellung mit **eigenen Feldern** einen eigenen Teil braucht — und ein Teil ist ein
        // Datensatz.*
        // ⚠️ **`Combined` steht bei den beiden anderen unter `Primitives` und nicht bei
        // `Compositions`** ([D-677](../../../docs/NewConcept/90-decision-log.md)). *Sein Wort: «der
        // underschied zwischen composition und combined ist das combined keine user daten enthält
        // nur example oder default wie bei typ». **Aus Feldern gebaut zu sein und Benutzerdaten zu
        // halten sind zwei verschiedene Fragen** — hier wird die zweite beantwortet.*
        return match ($this) {
            self::Model, self::Compositions, self::Settings   => true,
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
            // ⚠️ *Dieselbe Antwort wie `Constants`, und das ist der ganze Zweck: **damit die
            // Speicherfrage überhaupt eine Antwort hat.** Ein Knoten neben den Ästen hatte keine.*
            self::Constants, self::Settings => Storage::NodeRef,
        };
    }
}
