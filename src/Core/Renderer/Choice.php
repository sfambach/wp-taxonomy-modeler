<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\TypedValue;

/**
 * Die **Wahl** — das Ding zwischen einer Multiplizität und einem Auswahlfeld.
 *
 * ⚠️ **Diese Klasse gibt es, weil der Eigentümer den Grund für eine Reihe von Fehlern benannt hat.**
 * *«Ich glaube in der Tat, dass das daher rührt, dass du oft nicht die Regeln, die wir definiert haben,
 * berücksichtigst. Ich hatte ja gesagt, mach einen Choice-Renderer und verwende den immer, dass er sich
 * überall gleich verhält. Von der Multiplizität zum Choice ist ja ein Weg — das ist nicht eine einfache
 * Umsetzung, sondern da ist Code dazwischen. Und es kann sein, dass du den mehrfach erfindest.
 * Vielleicht haben wir dafür noch keinen Begriff und brauchen dafür was.»*
 *
 * ⚠️ **Gemessen, und es war schlimmer als «vielleicht».** *Die **Regel**
 * ([R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)) stand an einer Stelle,
 * in {@see ChoiceRenderer}. **Der Weg dorthin war viermal gepflastert**, jedes Mal anders: `mayBeNothing`
 * wurde als `! multiplicity->requiresOne()`, als `$shape !== OneOfFour`, als festes `true` und als
 * «wird in Ruhe gelassen» bestimmt. Ob es überhaupt eine Wahl **ist**, entschieden zwei Stellen
 * unabhängig; die Möglichkeitenliste wurde an **drei** Stellen verengt; und **sieben** Renderer leiteten
 * `aria-required` selbst ab. **Am 2026-08-31 kam ein fünfter Zweig dazu**, von mir.*
 *
 * ```mermaid
 * flowchart LR
 *   M["Multiplizitaet"] --> W["Wahl"]
 *   O["Moeglichkeiten"] --> W
 *   V["gespeicherter Wert"] --> W
 *   W --> A{"Ausgaenge"}
 *   A -->|keiner| S["gesperrt und markiert"]
 *   A -->|einer| G["vorausgewaehlt, ausgegraut"]
 *   A -->|mehrere| E["ein echtes Bedienelement"]
 * ```
 *
 * ⚠️ **Sie zeichnet nichts.** *Sie beantwortet Fragen; {@see ChoiceRenderer} macht daraus Markup. Die
 * Trennung ist der ganze Zweck: **eine Antwort kann geprüft werden, ohne Markup zu lesen.***
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Choice
{
    /**
     * @param array<int|string, string> $options      Was gewählt werden kann — Wert => Beschriftung.
     * @param bool                      $mayBeNothing Ob *nichts* eine gültige Antwort ist.
     * @param bool                      $editable     Ob hier überhaupt bedient werden darf.
     */
    private function __construct(
        public readonly array $options,
        public readonly bool $mayBeNothing,
        public readonly ?TypedValue $stored,
        public readonly bool $editable,
    ) {
    }

    /**
     * Die Wahl an einer **Verwendungsstelle**: *nichts* ist erlaubt, wenn die Multiplizität es erlaubt.
     *
     * ⚠️ **[R29](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete) sagt genau das, und
     * es ist die einzige Stelle, die es ausrechnet:** *ob «nichts» ein Ausgang ist, folgt aus der
     * Multiplizität — `0..1` ja, `1..1` nein.*
     *
     * @param array<int|string, string> $options
     */
    public static function atUseSite(
        Multiplicity $multiplicity,
        array $options,
        ?TypedValue $stored = null,
        bool $editable = true,
    ): self {
        return new self($options, ! $multiplicity->requiresOne(), $stored, $editable);
    }

    /**
     * Die Wahl einer **Einstellung**: *nichts* ist fast immer erlaubt.
     *
     * ⚠️ **Und das ist nicht [R29](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
     * ignoriert, sondern R29 auf eine Einstellung angewendet:** *Einstellungen sind **spärlich**
     * ([D-015](../../../docs/NewConcept/90-decision-log.md)) — eine fehlende heisst «nichts in der Kette
     * hat etwas gesagt», und es gibt keine Pflichteinstellung. R29s `1..1` gehört einem **Wert**, wo das
     * Konzept sie hingestellt hat.*
     *
     * @param array<int|string, string> $options
     * @param bool                      $closedList Eine geschlossene Liste wie die vier Multiplizitäten
     *                                              — dort ist «nichts» **kein** Ausgang.
     */
    public static function forSetting(
        array $options,
        bool $closedList = false,
        ?TypedValue $stored = null,
        bool $editable = true,
    ): self {
        return new self($options, ! $closedList, $stored, $editable);
    }

    /**
     * Wieviele verschiedene Antworten dieses Bedienelement hervorbringen kann.
     *
     * ⚠️ **Nicht die Länge der Liste** — *ein Eintrag, der auch leer bleiben darf, sind **zwei** Ausgänge
     * und bleibt bedienbar; ein Eintrag, der gesetzt sein muss, ist einer und ist entschieden.*
     */
    public function outcomes(): int
    {
        return count($this->options) + ($this->mayBeNothing ? 1 : 0);
    }

    /**
     * Ob die Umstände die Entscheidung schon getroffen haben — dann wird sie nicht vorgelegt.
     *
     * ⚠️ *[D-050](../../../docs/NewConcept/90-decision-log.md) auf Feldgrösse: **niemals eine
     * Entscheidung vorlegen, die die Umstände schon getroffen haben.***
     */
    public function isDecided(): bool
    {
        return $this->outcomes() <= 1;
    }

    /**
     * Ob das Modell hier **nicht erfüllbar** ist — keine Möglichkeit, und «nichts» ist keine Antwort.
     *
     * ⚠️ **Das ist ein Fehler und wird als Fehler gezeigt, nicht als leeres Feld** — *sonst sieht eine
     * Zeile aus, als könne man sie füllen, und niemand erfährt, dass das Modell etwas verlangt, was es
     * nicht anbietet.*
     */
    public function isUnsatisfiable(): bool
    {
        return $this->options === [] && ! $this->mayBeNothing;
    }

    /** Ob ein Bedienelement überhaupt anfassbar sein soll. */
    public function isOperable(): bool
    {
        return $this->editable && ! $this->isDecided();
    }

    /**
     * Ob diese Wahl den **gespeicherten Zustand zeigen kann**.
     *
     * ⚠️ **Der Fall, den ein Kerntest gefangen hat, und deshalb steht er hier und nicht im Abstieg.**
     * *Ein gespeicherter Verweis über einer **leeren** Liste wäre als leeres gesperrtes Auswahlfeld
     * gezeichnet worden — **der Wert stand nirgends mehr auf dem Schirm**, und das nächste Speichern
     * hätte «nichts» geschrieben. Wer eine Wahl zeichnen will, fragt vorher hier.*
     */
    public function canShowItsState(): bool
    {
        if ($this->options !== []) {
            return true;
        }

        return $this->stored === null || $this->stored->isNothing();
    }

    /**
     * Dieselbe Wahl mit einem Eintrag mehr — für einen gespeicherten Wert, den heute niemand anbietet.
     *
     * ⚠️ **[D-360](../../../docs/NewConcept/90-decision-log.md):** *was schon gespeichert ist, bleibt
     * stehen, auch wenn es heute nicht mehr angeboten würde — sonst verschwindet eine Wahl, die jemand
     * bewusst getroffen hat.*
     */
    public function including(int|string $value, string $label): self
    {
        if (isset($this->options[$value])) {
            return $this;
        }

        return new self([...$this->options, $value => $label], $this->mayBeNothing, $this->stored, $this->editable);
    }

    /**
     * Dieselbe Wahl mit einer engeren Liste — was der Typ nicht verträgt, wird nicht angeboten.
     *
     * ⚠️ *Verengen, nicht ersetzen: die Reihenfolge bleibt die des Modells, und ein Eintrag, der nicht
     * mehr passt, fällt weg statt an das Ende zu rutschen.*
     *
     * @param list<int|string> $keep
     */
    public function narrowedTo(array $keep): self
    {
        $behalten = [];

        foreach ($this->options as $wert => $beschriftung) {
            if (in_array($wert, $keep, true)) {
                $behalten[$wert] = $beschriftung;
            }
        }

        return new self($behalten, $this->mayBeNothing, $this->stored, $this->editable);
    }
}
