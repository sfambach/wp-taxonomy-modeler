<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What a shown value is, so a drawer knows how to show it — the matter, never a tag (D-1429 A14).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
enum ValueKind: string
{
    /** No renderer was chosen; the bare value, or why nothing can be shown. */
    case Unchosen = 'unchosen';
    case Text = 'text';
    /** Text whose line breaks are part of it. */
    case LongText = 'long_text';
    /** Source text in a language (`language`). */
    case Code = 'code';
    /** A colour; a drawer may show a patch of it beside the text. */
    case Colour = 'colour';
    case Email = 'email';
    /** A way to somewhere else (`target`), its text is the hint. */
    case Jump = 'jump';
    /** A yes/no: `1`, `0`, or empty for nothing. */
    case Flag = 'flag';
    /** A yes/no shown as a switch, the look the owner chose for it (`1`, `0`, or empty). */
    case Switch = 'switch';
    /** The word of the chosen option; empty: nothing chosen. */
    case Chosen = 'chosen';
    /** A stored value the offer does not hold: the text is the value itself. */
    case NotOffered = 'not_offered';
    /** The word of a chosen colour (`target`: the colour, if any); empty: nothing chosen. */
    case ChosenColour = 'chosen_colour';
    /** The word of a referenced record (`target`: its address, if any) (D-852). */
    case Record = 'record';
    /** Points at something that is gone: the text is its number. */
    case Dangling = 'dangling';
    /** A text that leads outside, the address in `target` — a provider's link template filled with it (D-1484). */
    case Link = 'link';
}
