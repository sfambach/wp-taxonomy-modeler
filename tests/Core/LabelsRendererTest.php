<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\LabelSlot;
use Taxmod\Core\Renderer\LabelsRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Submission;
use Taxmod\Core\Renderer\Surroundings;

/**
 * What a subject is called — the layout the owner asked for, held in place (D-384).
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelsRendererTest extends TestCase
{
    private function drawn(array $slots, Purpose $purpose = Purpose::Edit): string
    {
        return ShippedRenderers::registry()->byName(LabelsRenderer::NAME)->render(
            Node::create(7, 'Condensator', null),
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                surroundings: new Surroundings(
                    actions: [new Control('do', LabelsRenderer::WRITE, 'save')],
                    submits: new Submission('/post.php', ['id' => '7']),
                    rows: $slots,
                    sections: ['locale' => new Section('Locale', '<select name="l"></select>')]
                )
            )
        )->markup;
    }

    private function slot(string $role, bool $isLong = false, ?string $stored = null, string $note = ''): LabelSlot
    {
        return new LabelSlot($role, 'Condensator', $stored, 'taxmod_label[' . $role . ']', $isLong, $note === '', $note);
    }

    #[Test]
    public function the_short_roles_share_one_line_and_the_long_one_gets_a_row(): void
    {
        // ⚠️ The owner's layout: *the short ones in one row, help underneath, a blank line between
        // the short ones and help.* Short and long are laid out differently because they are
        // differently long — `help` holds a sentence that doubles as the tooltip (D-209).
        $markup = $this->drawn([
            $this->slot('form'),
            $this->slot('symbol'),
            $this->slot('help', true),
        ]);

        self::assertStringContainsString('taxmod-labels-short', $markup);
        self::assertStringContainsString('taxmod-labels-long', $markup);
        self::assertStringContainsString('<textarea', $markup);

        // The seam between words and sentences — the one distinction the panel makes.
        self::assertMatchesRegularExpression(
            '#taxmod-labels-short.*height:\.7em.*taxmod-labels-long#s',
            $markup
        );
    }

    #[Test]
    public function a_remark_rides_in_the_title_so_the_row_stays_level(): void
    {
        // ⚠️ **The owner's *almost perfect*.** A visible note under one field of a flex row makes
        // that column taller and tips the whole line: *the layout is shifted upwards because of the
        // text «the same in every language».* So the remark is a tooltip and nothing else.
        $markup = $this->drawn([
            $this->slot('form'),
            $this->slot('symbol', false, 'C', 'the same in every language'),
        ]);

        self::assertStringContainsString('title="the same in every language"', $markup);
        self::assertStringNotContainsString('>the same in every language<', $markup);
    }

    #[Test]
    public function the_chain_answer_is_the_placeholder_and_the_stored_text_is_the_value(): void
    {
        // ⚠️ Two different facts, side by side (D-020): what is written **here in this locale**, and
        // what the fallback answers. *An empty box with the resolved text greyed in says «nothing is
        // stored and something else answers»; an empty box alone says «this has no name», which is
        // never true.*
        $markup = $this->drawn([$this->slot('symbol', false, 'C')]);

        self::assertStringContainsString('value="C"', $markup);
        self::assertStringContainsString('placeholder="Condensator"', $markup);
    }

    #[Test]
    public function every_field_sits_inside_one_form_with_one_button(): void
    {
        // ⚠️ **One form, one save.** Five fields with five buttons has no answer to what Enter does —
        // and a field outside the form submits nothing at all, which is how this was first written.
        $markup = $this->drawn([$this->slot('form'), $this->slot('help', true)]);

        // The entry form opens before the first field and closes after the button.
        self::assertMatchesRegularExpression(
            '#<form method="post"[^>]*>.*taxmod_label\[form\].*taxmod_label\[help\].*put_labels.*</form>#s',
            $markup
        );
    }

    #[Test]
    public function the_locale_picker_leads_the_short_row_and_submits_nothing(): void
    {
        // ⚠️ **This test used to assert the picker sat *outside* the form**, which was true while it
        // was a form of its own. The owner then asked for it *at the start of the short fields, then
        // we save space* — and those live inside the entry form, where HTML forbids a second one. So
        // it became a bare `select`, and the invariant moved with it: **not outside, but nameless.**
        $markup = $this->drawn([$this->slot('form'), $this->slot('symbol')]);

        // It is the first thing in the short row, ahead of every field.
        self::assertMatchesRegularExpression(
            '#taxmod-labels-short[^>]*>\s*<div class="taxmod-labels-locale"#s',
            $markup
        );

        // ⚠️ And it is a control that **moves** rather than a value that is written: saving the panel
        // must not carry a language along with the texts.
        self::assertStringNotContainsString('name="taxmod_locale"', $markup);
    }

    #[Test]
    public function a_role_that_is_the_same_everywhere_gets_a_narrower_column(): void
    {
        // ⚠️ The owner: *you could make symbol shorter.* A symbol holds `Ω`, `C`, `St` — at most a
        // couple of characters — and giving it the width of `Condensator` wastes the line the row
        // exists to save. **Read off the role's own property**, not off its name (`CD-9`): a role that
        // is the same in every language is one fixed by a standard, and those are short.
        $markup = $this->drawn([
            $this->slot('form'),
            $this->slot('symbol', false, 'C', 'the same in every language'),
        ]);

        self::assertStringContainsString('flex:0 0 5em', $markup);
        self::assertStringContainsString('flex:1 1 8em', $markup);
    }

    #[Test]
    public function display_only_draws_no_fields_at_all(): void
    {
        // A panel asked to show rather than to edit offers nothing to type into.
        $markup = $this->drawn([$this->slot('form')], Purpose::Display);

        self::assertStringNotContainsString('<input type="text"', $markup);
        self::assertStringNotContainsString('put_labels', $markup);
        self::assertStringContainsString('Condensator', $markup);
    }
}
