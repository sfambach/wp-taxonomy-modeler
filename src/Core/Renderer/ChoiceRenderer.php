<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

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
final class ChoiceRenderer implements Renderer
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

    public function fits(Node|Relation $subject): bool
    {
        return true;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        $offered = $context->surroundings->options;
        $now     = $context->value->text;

        // ⚠️ **R31b's count.** *Nothing* is an outcome exactly where it is an allowed answer — R29
        // reads that off the multiplicity: `0..1` and `0..*` may be empty, `1` and `1..*` may not.
        $mayBeNothing = $context->surroundings->mayBeNothing;
        $outcomes     = count($offered) + ($mayBeNothing ? 1 : 0);

        if ($context->purpose === Purpose::Display) {
            return $this->shown($now, $offered);
        }

        // R31a: nothing can be chosen and nothing is not allowed — the model cannot be satisfied.
        $unsatisfiable = $offered === [] && ! $mayBeNothing;

        // R30 / R31: one outcome or none is already decided, so the control is greyed.
        $decided = $outcomes <= 1;

        $markup = '<select name="' . RenderResult::escape($context->fieldName) . '"'
            // ⚠️ **Where the control cannot sit inside its form, it names it.** An attribute row is a
            // `<tr>`: its cells cannot be wrapped in one form, so without this the select submitted
            // nothing at all — which is how a multiplicity change looked like a save that did nothing.
            . ($context->surroundings->formId === ''
                ? ''
                : ' form="' . RenderResult::escape($context->surroundings->formId) . '"')
            . ' class="taxmod-choice' . ($unsatisfiable ? ' taxmod-unsatisfiable' : '') . '"'
            . ($decided || ! $context->editable ? ' disabled' : '')
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
