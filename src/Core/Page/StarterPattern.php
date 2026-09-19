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
 *   M["Seitenvorlage im Modell · Abschnitte"] --> S["StarterPattern::markup()"]
 *   S --> W["Rand: register_block_pattern · core/post-content"]
 *   W --> B["neuer Beitrag: «Mit welcher Vorlage beginnen?»"]
 * ```
 *
 * ⚠️ *Der Kern baut nur Blockmarkup aus Text (`CD-1`); das Zerlegen eines bestehenden Beitrags und das Anmelden bei WordPress macht der Rand.*
 *
 * @see docs/neues-konzept-eingang.md
 */
final class StarterPattern
{
    /** Die Feldnamen des Knotens, der diese Klasse nennt — sein Vertrag mit dem Rand. */
    public const NAME       = 'Name';
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

    /** Das Blockmarkup des Musters, wie der Block-Editor es einsetzt. */
    public function markup(): string
    {
        $teile = trim($this->lead) === '' ? [] : [trim($this->lead)];

        foreach ($this->sections as $abschnitt) {
            $teile[] = self::heading($abschnitt->heading, $abschnitt->level);
            // *Was die Vorlage unter der Überschrift vorgibt, steht so da, wie sie es vorgibt; ohne Vorgabe ein leerer Absatz, der den Hilfetext
            // als Platzhalter zeigt.*
            $teile[] = trim($abschnitt->preset) !== '' ? trim($abschnitt->preset) : self::paragraph($abschnitt->hint);
        }

        return implode("\n\n", $teile);
    }

    private static function heading(string $text, int $level): string
    {
        $ebene = max(1, min(6, $level));

        return '<!-- wp:heading' . ($ebene === 2 ? '' : ' {"level":' . $ebene . '}') . " -->\n"
            . '<h' . $ebene . ' class="wp-block-heading">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h' . $ebene . ">\n"
            . '<!-- /wp:heading -->';
    }

    private static function paragraph(string $hint): string
    {
        $attribute = trim($hint) === '' ? '' : ' ' . json_encode(['placeholder' => trim($hint)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return '<!-- wp:paragraph' . $attribute . " -->\n<p></p>\n<!-- /wp:paragraph -->";
    }
}
