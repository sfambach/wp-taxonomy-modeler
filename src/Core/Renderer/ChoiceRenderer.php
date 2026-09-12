<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * One answer out of a set — and the only place [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete)
 * is implemented.
 *
 * The owner asked for it as one rule for the whole admin screen: *bools always with a slider,
 * **select fields always greyed out when there is no entry or only one entry**. Empty-if-available
 * counts as an entry — so if empty and one more item is in the list, a choice must be possible.*
 * That last sentence **is** [R31b](../../../docs/NewConcept/30-renderer.md#r31b--the-rule-counts-possibilities-not-entries),
 * arrived at from the other direction.
 *
 * ```mermaid
 * flowchart TD
 *   A[this control] --> B{how many outcomes}
 *   B -->|none| C["disabled · the model cannot be satisfied"]
 *   B -->|exactly one| D["preselected, greyed"]
 *   B -->|more than one| E[a real control]
 * ```
 *
 * ⚠️ **Outcomes, never rows, and the distinction is the whole rule.** R31b: *the test is never how
 * many rows are in the list but how many **outcomes** this control can produce.* One entry that may
 * also be left empty is **two** outcomes and stays alive; one entry that is mandatory is one, and it
 * is already decided. *Counting rows would grey out exactly the case where a person still has a
 * take-it-or-leave-it decision to make — R28–R32's fourth row, which the concept marks as the place
 * the rule must not be over-applied.*
 *
 * ⚠️ **Zero entries with nothing allowed is a broken model, not a control state**
 * ([R31a](../../../docs/NewConcept/30-renderer.md#r31a--the-second-row-is-not-a-control-state-it-is-a-broken-model)).
 * It is caught where the narrowing happens, three steps before any form. All this can do is refuse
 * to pretend: the control is disabled **and marked**, so a person looking at the form sees a fault
 * rather than an empty box they cannot fill.
 *
 * ⚠️ **Greyed rather than absent**, as [D-370](../../../docs/NewConcept/90-decision-log.md) settled
 * for the button rows: a control that vanishes takes its label and its position with it, and the eye
 * has to find the row again. A disabled control submits nothing, so keeping it costs nothing.
 *
 * ⚠️ **The set arrives finished.** Which nodes may be picked, which renderers are eligible, which
 * four notations exist — none of that is a renderer's to work out ([D-159](../../../docs/NewConcept/90-decision-log.md)),
 * and the words in it are already translated because the text domain is the boundary's (`AR-2`).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ChoiceRenderer extends RendererNode
{
    public const NAME = 'choice';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: a choice has no simple type — it has a set. */
    public function handles(): array
    {
        return [];
    }

    /**
     * ⚠️ **Ja — und ohne Menge wird er gar nicht erst angeboten**
     * ([OQ-120](../../../docs/NewConcept/91-open-questions.md), sein Befund an `Ampere`: *«das kann
     * aber nicht richtig sein, weil der Knoten keine Kinder hat»*).
     */
    public function needsSomethingToChooseFrom(): bool
    {
        return true;
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;

        // ⚠️ **Ein gewählter Knoten steht als Verweis da, nicht als Text.** *Bis [D-540](../../../docs/NewConcept/90-decision-log.md)
        // war jede Auswahl eine Menge von Wörtern — `wie oft` ist `0..1` — und `text` war die ganze
        // Wahrheit. Eine Auswahl aus **Kindern** trägt eine Knoten-Id, und ohne diese Zeile stünde die
        // Liste richtig da und **nichts wäre vorausgewählt**: der gespeicherte Wert sähe wie keiner aus,
        // und das nächste Speichern hätte ihn gelöscht.*
        $now = $context->value->text
            ?? ($context->value->reference === null ? null : (string) $context->value->reference);

        if ($context->purpose === Purpose::Display) {
            return $this->shown($now, $offered);
        }

        // ⚠️ **Hier stand die Regel, und sie steht jetzt in {@see Choice}.** *Der Eigentümer hat den
        // Grund benannt: «von der Multiplizität zum Choice ist ein Weg — das ist nicht eine einfache
        // Umsetzung, sondern da ist Code dazwischen. Und es kann sein, dass du den mehrfach
        // erfindest.» **Gemessen: die Regel stand einmal, der Weg dorthin viermal** — und am
        // 2026-08-31 kam ein fünfter Zweig dazu, von mir.*
        //
        // ⚠️ *Dieser Renderer **zeichnet** eine Wahl und **rechnet** sie nicht. Ausgänge, entschieden,
        // unerfüllbar — alles Fragen an das eine Objekt, das sie beantworten darf.*
        $wahl = Choice::forSetting(
            $offered,
            ! $context->surroundings->mayBeNothing,
            $context->value,
            $context->editable
        );

        $mayBeNothing  = $wahl->mayBeNothing;
        $outcomes      = $wahl->outcomes();
        $unsatisfiable = $wahl->isUnsatisfiable();
        $decided       = $wahl->isDecided();

        $markup = '<select name="' . RenderResult::escape($context->fieldName) . '"'
            // ⚠️ **Where the control cannot sit inside its form, it names it.** An attribute row is a
            // `<tr>`: its cells cannot be wrapped in one form, so without this the select submitted
            // nothing at all — which is how a multiplicity change looked like a save that did nothing.
            . ($context->surroundings->formId === ''
                ? ''
                : ' form="' . RenderResult::escape($context->surroundings->formId) . '"')
            . ' class="taxmod-choice' . ($unsatisfiable ? ' taxmod-unsatisfiable' : '') . '"'
            // ⚠️ *«chooser sollte auch display size haben» (D-727): die Breite, wo der Aufrufer eine mitgibt.*
            . (($breite = $context->setting('display_size')?->int) === null || $breite <= 0 ? '' : ' style="width:' . $breite . 'ch"')
            . ($wahl->isOperable() ? '' : ' disabled')
            . ($decided ? ' style="opacity:.55"' : '')
            . ($unsatisfiable
                ? ' title="' . RenderResult::escape($context->surroundings->refersTo ?? '') . '"'
                : '')
            . '>';

        // ⚠️ **The empty option exists only where nothing is a real answer**, which is what makes
        // *empty counts as an entry* true rather than a courtesy. Where it is not allowed, offering
        // it would be a control that can produce an answer the model refuses.
        if ($mayBeNothing) {
            $markup .= '<option value=""' . ($now === '' || $now === null ? ' selected' : '') . '></option>';
        }

        foreach ($offered as $value => $label) {
            $value = (string) $value;

            // R30: with one outcome that outcome **is** the answer, so it is selected rather than
            // merely offered — a greyed control showing nothing would say the field is unset.
            $chosen = $now === $value || ($decided && $outcomes === 1 && ! $mayBeNothing);

            $markup .= '<option value="' . RenderResult::escape($value) . '"'
                . ($chosen ? ' selected' : '') . '>'
                . RenderResult::escape($label) . '</option>';
        }

        return RenderResult::of($markup . '</select>');
    }

    /**
     * The chosen one, read.
     *
     * ⚠️ *Nothing is nothing* ([D-232](../../../docs/NewConcept/90-decision-log.md)): an unanswered
     * choice is an em dash and not an empty cell, so a column still reads as a column. A value that
     * is **set but not in the set** is marked rather than hidden — it is usually a member somebody
     * deleted, and hiding it would make the row look complete.
     */
    private function shown(?string $now, array $offered): RenderResult
    {
        if ($now === null || $now === '') {
            return RenderResult::of('<span class="taxmod-nothing">—</span>');
        }

        if (! array_key_exists($now, $offered)) {
            // Set, but no longer one of the possibilities — usually a member somebody deleted.
            return RenderResult::of(
                '<span class="taxmod-choice taxmod-unknown-choice">' . RenderResult::escape($now) . '</span>'
            );
        }

        return RenderResult::of('<span class="taxmod-choice">' . RenderResult::escape($offered[$now]) . '</span>');
    }
}
