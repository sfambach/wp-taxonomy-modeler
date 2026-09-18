<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One thing a person can do to the subject — **described, not drawn**.
 *
 * ⚠️ **A renderer *can* build a button, and I had claimed otherwise.** The owner: *why can a
 * renderer not build buttons? that is not right.* He is correct, and the mistake was a confusion
 * worth naming: a renderer cannot **invent** the three WordPress-shaped values a control needs —
 * a **nonce**, a form **URL**, and a **translated** label (`CD-1`, `AR-2`) — but composing
 * `<button name=… value=…>` out of values it was given is string work, which is all a renderer
 * ever does.
 *
 * ```mermaid
 * flowchart LR
 *   B["the boundary<br/>nonce · URL · words · what is allowed"] --> C[Control]
 *   C --> R["the renderer<br/>builds the markup"]
 * ```
 *
 * ⚠️ **So the boundary hands in facts, not markup.** That is the difference between a renderer
 * that owns the shape of a row and one that concatenates somebody else's HTML — and `R1` wants the
 * first: *anything that shows model data goes through the renderer contract.*
 *
 * ⚠️ **Availability is carried here, not expressed by leaving the control out**
 * ([D-370](../../../docs/NewConcept/90-decision-log.md)).
 * [U8](../../../docs/NewConcept/20-interaction.md) said the opposite — *not greyed out, **absent***
 * — and the owner reversed it so that **the row of buttons looks the same everywhere**. *At four
 * controls in fixed places the eye learns a position; with omission the bin slides left on every
 * row that cannot move up.* It also brings this in step with
 * [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), whose own table
 * says **disabled** and **greyed** rather than gone.
 *
 * ⚠️ *Deciding availability still needs knowledge a renderer must not fetch (D-159) — a protected
 * node, the last child — so the **boundary** decides and states it here.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Control
{
    /**
     * The one character that stands for saving, because Dashicons has no diskette.
     *
     * ⚠️ **It lives here because it was written out five times and four of them were wrong.** Measured
     * on the rendered node page: `put_labels` and `save_field` reached the browser as
     * `<button class="button">💾</button>` — WordPress's blue-bordered secondary button, 49×41 among
     * 17px neighbours — because the character had been handed in as the **label** rather than as the
     * glyph, and {@see ControlMarkup} decides «this is an icon» from `icon` and `glyph` alone. *So the
     * class of fault was not a missing CSS rule and not a fourth copy of the markup: it was a
     * positional argument landing in the slot next door.*
     */
    public const SAVE_GLYPH = '💾';

    /**
     * A save act, with the diskette in the slot it belongs in.
     *
     * ⚠️ **This exists so the glyph cannot land in the label again.** *Four of the five save buttons
     * passed `💾` as the third positional argument, which is `$label` — and a label is drawn as words,
     * so those four lost the class that takes a button's box away. One named constructor is the same
     * cure `ControlMarkup` already applied to the markup side: the thing that has to be got right is
     * stated once.*
     */
    public static function saving(
        string $name,
        string $value,
        string $label,
        string $title = '',
        bool $available = true,
        string $form = '',
    ): self {
        return new self($name, $value, $label, $title, $available, false, '', $form, false, self::SAVE_GLYPH);
    }

    /**
     * @param string $name      The form field it submits under.
     * @param string $value     What it submits.
     * @param string $label     What a person reads — **already translated**, because the text domain
     *                          is the boundary's (`AR-2`).
     * @param string $title     The longer explanation, translated the same way. Empty for none.
     * @param bool   $available Whether it can be used now. A disabled button submits nothing, so
     *                          keeping it is a matter of layout and never of safety.
     * @param bool   $destroys  Whether the act takes something away.
     *
     * ⚠️ **`destroys` is a fact about the act, not a colour.** The boundary knows what an action
     * does; how that reads on screen is the renderer's — which is why the flag is here and the red
     * is over there. *Otherwise every surface would pick its own red, and the one control that must
     * never be clicked by accident would look different in each of them.*
     */
    /**
     * @param string $icon A Dashicon key without its `dashicons-` prefix, or empty for none. When
     *                     set, the renderer draws the icon **instead of** the label — and the label
     *                     is still required, because it is what a screen reader is left with.
     *
     * ⚠️ **This exists because an emoji is an outline and an icon font is a face.** The owner: *the
     * bin icon is still very thin, hardly recognisable.* `🗑` renders as a hairline glyph at button
     * size in most system emoji fonts and no CSS can thicken it; `dashicons-trash` is a **font
     * glyph**, so it takes `color` and `font-size` like text and comes out solid. *The same reason
     * the tree row draws its node icon as a Dashicon rather than a picture
     * ([D-251](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * ⚠️ **A key and not markup**, so the boundary states which icon and the renderer decides how an
     * icon is drawn — the same division as `destroys` stating a fact and the renderer choosing the
     * red. *Handing in a `<span class="dashicons …">` would put the shape of a control back on the
     * surface, which is what `R1` is for.*
     */
    /**
     * @param string $form  The id of the form this button submits, for HTML's own `form="…"`, or
     *                      empty when it sits inside the form it means.
     * @param bool   $leads Whether it is the **leading** act of its group — the one a person came to
     *                      press.
     *
     * ⚠️ **Both exist because four buttons were still built by hand and looked it.** The owner:
     * *every time new buttons appear they look odd again — is there a button renderer? If not, let us
     * build it and use it everywhere.* **Measured: six went through {@see ControlMarkup::button()} and
     * four were written out on the screen** — `Add`, the page save, `Move here` and `add_attribute`.
     * *The blue diskette he reported was one of the four: hand-written, it carried `button-primary`
     * **and** the icon class that exists to remove a background.*
     *
     * ⚠️ **`leads` is a fact about the act, not a class name** — the same division `destroys` already
     * draws. *The boundary knows which act a person came for; whether that reads as blue, bold or
     * bigger is the renderer's business, and stating it as `button-primary` here would put the shape
     * of a control back on the surface.*
     *
     * ⚠️ *`form` is a fact too, and a peculiar one: it is plain HTML that lets a button submit a form
     * it is **not inside**, which is what makes the page-level save work with no script
     * ([D-392](../../../docs/NewConcept/90-decision-log.md)). The renderer cannot invent it — only the
     * surface knows which panel the button means.*
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $label,
        public readonly string $title = '',
        public readonly bool $available = true,
        public readonly bool $destroys = false,
        public readonly string $icon = '',
        public readonly string $form = '',
        public readonly bool $leads = false,
        /**
         * A character to draw instead of the label, where the icon font has no word for it.
         *
         * ⚠️ **It was written for exactly one button and there are now five**, which is the whole story
         * of the boxes coming back. The page save draws `💾` **against** the icon font on the owner's
         * own observation: *`dashicons-saved` is a tick.* There is no diskette in Dashicons, so the
         * choice is a character or the wrong picture — *and the label stays what a screen reader is
         * left with, which is why this is a third field and not a label holding an emoji.*
         *
         * ⚠️ **Hand it in through {@see self::saving()} rather than by position.** *`$label` is the
         * third argument and this is the tenth; four call sites put the diskette in the third, so the
         * button drew it as a word and never got the class that removes its box. A label holding an
         * emoji is also the thing this field exists to prevent — it makes «floppy disk» the accessible
         * name of the save button.*
         *
         * ⚠️ *`icon` wins where both are given: a Dashicon takes `color` and `font-size` like text and
         * an emoji does not, which is the whole argument in the `icon` docblock above.*
         */
        public readonly string $glyph = '',
        /**
         * Ein Dialog, der sich **vor** dem Akt öffnet — Felder, Bestätigen, Abbruch ([D-730](../../../docs/NewConcept/90-decision-log.md)).
         * *Der Knopf wird zum Öffner; der Akt hängt am Bestätigen im Fuss.*
         */
        public readonly ?Dialog $opens = null,
        /**
         * Symbol **und** Wort — sein Wort: *«wobei ich hier den text lassen würde und ein + als icon dazu … gleiche für add field»*
         * ([D-861](../../../docs/NewConcept/90-decision-log.md)). Ohne: ein Symbol ersetzt das Wort (D-847).
         */
        public readonly bool $iconWithLabel = false,
    ) {
    }
}
