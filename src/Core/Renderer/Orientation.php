<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Die Achse, auf der ein Behälter auslegt — **eine** Stelle für Schlüssel, Werte und Vorgabe.
 *
 * ⚠️ **Ein Umschalter und nicht zwei Renderer**, und das ist
 * [D-471](../../../docs/NewConcept/90-decision-log.md), sein Wort am Kompaktrenderer: *«der
 * Kompaktrenderer, der die Eigenschaften hat horizontal beziehungsweise vertikal, also **einen
 * Umschalter**»*. Am 2026-09-06 hat er dasselbe für die Tabelle verlangt: *«ich würde gerne hier auch
 * horizontal und vertikal einfügen, horizontal kopf oben daten darunter, vertikal kopf links daten
 * rechts davon»*.
 *
 * ⚠️ **Warum es dieses Ding gibt und die Vorgabe nicht zweimal im Baum steht.** *`orientation` und
 * `horizontal` als Vorgabe standen als drei Zeichenketten in {@see CompactRenderer}. Ein zweiter
 * Leser hätte sie abgeschrieben — und dann hätte eine Änderung der Vorgabe **zwei** Stellen gehabt,
 * von denen die zweite still die alte behält. Der Schlüssel ist der Name der Einstellungskante
 * ([D-647](../../../docs/NewConcept/90-decision-log.md)), also ist er eine Tatsache des Modells und
 * gehört keinem der beiden Renderer.*
 *
 * ⚠️ **Nur das genaue Wort dreht die Achse**; Schweigen, ein leerer Wert und ein Schreibfehler sind
 * alle die Vorgabe. *Ein unbekannter Wert ist kein entschiedener Fall
 * ([OQ-120](../../../docs/NewConcept/91-open-questions.md) besitzt die Form dieser Schlüssel), und
 * auf die erklärte Vorgabe zurückzufallen ist die einzige Lesart, die keine dritte Lage erfindet.*
 *
 * ```mermaid
 * flowchart LR
 *   S["setting orientation"] --> F["fromContext"]
 *   F -->|vertical| V["Vertical"]
 *   F -->|alles andere| H["Horizontal · Vorgabe"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum Orientation: string
{
    case Horizontal = 'horizontal';
    case Vertical   = 'vertical';

    /**
     * Der freie Einstellungsschlüssel, an dem die Achse hängt.
     *
     * ⚠️ **Der Name der Kante ist der Schlüssel** — dieselbe Lehre wie bei `with_label`, das hier
     * einmal `label` hiess und darum lebenslang Schweigen las.
     */
    public const KEY = 'orientation';

    /** Die Vorgabe, an einer Stelle: sein Wort in [D-471], *«Standard horizontal»*. */
    public static function default(): self
    {
        return self::Horizontal;
    }

    public static function fromContext(RenderContext $context): self
    {
        return self::tryFrom($context->setting(self::KEY)?->text ?? '') ?? self::default();
    }

    public function isVertical(): bool
    {
        return $this === self::Vertical;
    }
}
