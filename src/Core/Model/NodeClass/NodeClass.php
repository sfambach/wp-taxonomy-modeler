<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Eine **Knotenklasse** — was ein Knoten *ist*, als programmierte Klasse (D-716).
 *
 * ⚠️ **Sein Gerüst** ([D-712](../../../../docs/NewConcept/90-decision-log.md)): *«Einstellungen sind
 * Attribute von programmierten Knotenklassen.»* Und zur Vergabe ([D-716](../../../../docs/NewConcept/90-decision-log.md)):
 * *«eine kante hat genau eine klasse, entweder eine eigene oder sie erbt sie beim anlegen, aber immer
 * genau nur eine. das gleiche gilt für knoten.»*
 *
 * ```mermaid
 * flowchart LR
 *   K["Knoten · nodes.klasse"] -->|"nennt"| C["Knotenklasse · Code"]
 *   C -->|"erklärt"| V["Vertrag · einmal abgeleitet"]
 *   V --> A["erlaubte Kindklassen · Vorwahl · Icon"]
 * ```
 *
 * ⚠️ **Alles hier ist statisch, weil es die Klasse beschreibt und nicht ein Exemplar.** *Ein
 * `IntType`-Knoten ist ein Objekt; dass Integer-Knoten nur Integer-Kinder vorwählen, ist eine
 * Aussage über die Klasse. Der {@see Contract} liest sie genau einmal
 * ([`einstellungen-anforderungen.md`](../../../../docs/einstellungen-anforderungen.md) §2.4).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
interface NodeClass
{
    /**
     * Welche Klassen ein Kind dieses Knotens haben darf — **leer heisst: alle** (Anforderung 2.2).
     *
     * @return list<class-string<NodeClass>>
     */
    public static function allowedChildClasses(): array;

    /**
     * Die Klasse, die ein Kind bekommt, wenn niemand eine wählt (Anforderung 2.2.3).
     *
     * @return class-string<NodeClass>
     */
    public static function defaultChildClass(): string;

    /**
     * Das Icon der Klasse — ein Dashicon-Schlüssel ohne Präfix, wie die Labels ihn tragen
     * ([D-723](../../../../docs/NewConcept/90-decision-log.md)).
     */
    public static function classIcon(): string;

    /**
     * Der maschinennahe Name der Klasse, den der Rand übersetzt (`AR-2`) — etwa `category`.
     */
    public static function classKey(): string;
}
