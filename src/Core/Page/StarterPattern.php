<?php declare(strict_types=1);

namespace Taxmod\Core\Page;

/**
 * Eine Seitenvorlage als Startmuster: der Vorspann, dann je Abschnitt die Überschrift und darunter, was die Vorlage dort vorgibt.
 *
 * ⚠️ **Sein Wort** ([D-870](../../../docs/NewConcept/90-decision-log.md)): *«so ein Template ist ein Seitenaufbau, der immer gleich ist … ist
 * mir da eine Abbildung in der Datenbank oder in unserem Modell eigentlich ganz wichtig»* und *«wenn ich einen neuen Beitrag aufmache,
 * verwende dies oder jenes Template»*. *Die Gliederung steht im Modell (Knoten, der diese Klasse nennt); WordPress bietet sie beim neuen
 * Beitrag als Startmuster an. Die Inhalte bleiben im Beitrag — ein Muster wird einmal eingesetzt und lädt danach nichts nach.*
 *
 * ```mermaid
 * flowchart LR
 *   M["Seitenvorlage im Modell · Abschnitte"] --> S["Rand: WpStarterPatterns::blockMarkup()"]
 *   S --> W["Rand: register_block_pattern · core/post-content"]
 *   W --> B["neuer Beitrag: «Mit welcher Vorlage beginnen?»"]
 * ```
 *
 * ⚠️ *Der Kern hält nur die Gliederung; das Blockmarkup baut der Rand (D-1429, Renderer 6c: kein HTML im Kern). Die Klasse bleibt hier, weil ihr Name im Modell steht (`implemented_by`).*
 *
 * @see docs/neues-konzept-eingang.md
 */
final class StarterPattern
{
    /** Die Feldnamen des Knotens, der diese Klasse nennt — sein Vertrag mit dem Rand. */
    // ⚠️ *«Bezeichnung» erbt jeder Satz unter «Model» (D-883); das eigene Feld «Name» der Seitenvorlagen ist darin aufgegangen.*
    public const NAME       = 'Bezeichnung';
    public const PREFIX     = 'Titel-Präfix';
    public const LEAD       = 'Vorspann';
    public const SECTIONS   = 'Abschnitte';
    public const HEADING    = 'Überschrift';
    public const LEVEL      = 'Ebene';
    public const HINT       = 'Hilfetext';
    public const PRESET     = 'Vorgabe';

    /**
     * @param list<PatternSection> $sections
     */
    public function __construct(
        public readonly string $name,
        public readonly string $lead,
        public readonly array $sections,
    ) {
    }
}
