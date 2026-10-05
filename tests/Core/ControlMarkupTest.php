<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;

/**
 * The one place that composes a button — and the slot the diskette kept missing.
 *
 * ⚠️ **`ControlMarkup` had no test at all**, which is how a class whose whole reason for existing is
 * *«there were four copies and they had drifted»* came to be blameless in a fault reported against it.
 * On 2026-08-28 the owner saw boxes round the icons again; the markup was correct and **the boundary
 * had handed the diskette in as `$label`** — a word, drawn as a word, and rightly given no borderless
 * class. *Nothing here would have caught that either, because nothing here existed.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ControlMarkupTest extends TestCase
{
    #[Test]
    public function a_button_with_words_keeps_its_box(): void
    {
        $markup = ControlMarkup::button(new Control('do', 'add', 'Add'));

        $this->assertStringNotContainsString(ControlMarkup::ICON_ONLY, $markup);
        $this->assertStringContainsString('>Add</button>', $markup);
    }

    #[Test]
    public function a_dashicon_button_says_it_is_icon_only(): void
    {
        $markup = ControlMarkup::button(new Control('do', 'trash', 'Delete', icon: 'trash'));

        $this->assertStringContainsString(ControlMarkup::ICON_ONLY, $markup);
        $this->assertStringContainsString('dashicons-trash', $markup);
    }

    /**
     * ⚠️ *The measured fault: a glyph is icon-only in every way that matters to the layout, and only
     * the face cares which font drew it.*
     */
    #[Test]
    public function a_glyph_button_is_icon_only_as_well(): void
    {
        $markup = ControlMarkup::button(new Control('do', 'put', 'Save', glyph: Control::SAVE_GLYPH));

        $this->assertStringContainsString(ControlMarkup::ICON_ONLY, $markup);
        $this->assertStringContainsString(ControlMarkup::GLYPH_FACE, $markup);
        $this->assertStringContainsString('aria-label="Save"', $markup);
    }

    /**
     * ⚠️ **The regression itself, stated as a test.** *Five call sites handed the diskette in; four put
     * it third, where `$label` is. This asserts what that produces — a button with no borderless class
     * and an emoji for a name — so the next person to count arguments wrong is told by a red test
     * instead of by the owner.*
     */
    #[Test]
    public function the_diskette_in_the_label_slot_is_what_produced_a_box(): void
    {
        $wrong = ControlMarkup::button(new Control('do', 'put_labels', Control::SAVE_GLYPH));

        $this->assertStringNotContainsString(ControlMarkup::ICON_ONLY, $wrong);

        $right = ControlMarkup::button(Control::saving('do', 'put_labels', 'Save'));

        $this->assertStringContainsString(ControlMarkup::ICON_ONLY, $right);
        $this->assertStringContainsString(Control::SAVE_GLYPH, $right);
        $this->assertStringNotContainsString('>' . Control::SAVE_GLYPH . '</button>', $right);
    }

    /**
     * ⚠️ *`button-primary` paints a background and the borderless class exists to take one away, so the
     * combination is unrepresentable rather than merely discouraged.*
     */
    #[Test]
    public function an_icon_button_is_never_the_prominent_one(): void
    {
        $markup = ControlMarkup::button(new Control('do', 'put', 'Save', leads: true, glyph: Control::SAVE_GLYPH));

        $this->assertStringContainsString(ControlMarkup::ICON_ONLY, $markup);
        $this->assertStringNotContainsString('button-primary', $markup);
    }

    #[Test]
    public function a_leading_word_button_is_the_prominent_one(): void
    {
        $this->assertStringContainsString(
            'button-primary',
            ControlMarkup::button(new Control('do', 'add', 'Add', leads: true))
        );
    }

    /**
     * ⚠️ *`saving()` exists so the glyph cannot land in the label again — what it fills in is the part
     * that was got wrong, so that is what is asserted.*
     */
    #[Test]
    public function saving_puts_the_diskette_in_the_glyph_and_the_words_in_the_label(): void
    {
        $control = Control::saving('do', 'save_field', 'Save', 'Save this field', false, 'panel-7');

        $this->assertSame('Save', $control->label);
        $this->assertSame(Control::SAVE_GLYPH, $control->glyph);
        $this->assertSame('', $control->icon);
        $this->assertSame('panel-7', $control->form);
        $this->assertFalse($control->available);
        $this->assertFalse($control->destroys);
        $this->assertFalse($control->leads);
    }

    #[Test]
    public function an_unavailable_button_is_disabled_rather_than_absent(): void
    {
        $markup = ControlMarkup::button(new Control('do', 'up', 'Up', icon: 'arrow-up-alt2', available: false));

        $this->assertStringContainsString(' disabled', $markup);
    }

    #[Test]
    public function availability_can_be_overridden_for_one_row(): void
    {
        $control = new Control('do', 'reset', 'Reset', icon: 'undo');

        $this->assertStringContainsString(' disabled', ControlMarkup::button($control, false));
        $this->assertStringNotContainsString(' disabled', ControlMarkup::button($control, true));
    }

    #[Test]
    public function a_destructive_button_is_red_and_a_plain_one_is_not(): void
    {
        $this->assertStringContainsString('#b32d2e', ControlMarkup::button(new Control('do', 'trash', 'Delete', destroys: true, icon: 'trash')));
        $this->assertStringNotContainsString('#b32d2e', ControlMarkup::button(new Control('do', 'copy', 'Copy', icon: 'admin-page')));
    }

    #[Test]
    public function a_word_travelling_with_the_buttons_is_recognised(): void
    {
        $this->assertTrue(ControlMarkup::isAWord(new Control('word:here', '', 'here')));
        $this->assertFalse(ControlMarkup::isAWord(new Control('do', 'trash', 'Delete')));
    }
}
