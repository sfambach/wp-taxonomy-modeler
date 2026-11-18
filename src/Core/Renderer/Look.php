<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The CSS class names that core markup still built by hand needs while renderer step 6c is under way.
 *
 * ⚠️ *Scaffolding (A15): the drawing of buttons moved to the edge ({@see \Taxmod\WordPress\Draw\ControlMarkup}), which
 * names these classes from here so the fact stands once. When the last hand-built button in the core is a described
 * {@see Control}, nothing in the core needs a class name and this file goes.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Look
{
    /** Marks a button whose whole content is an icon, so the surface can strip its box. */
    public const ICON_ONLY = 'taxmod-icon-button';

    /**
     * Marks a drawn character standing in for an icon, so it takes the same size as a Dashicon.
     *
     * ⚠️ **Measured: the diskette was 13px beside 17px Dashicons in the same row** — the glyph span had
     * no class, so the only size it could get was the inherited `font-size` of an admin page.*
     */
    public const GLYPH_FACE = 'taxmod-icon-glyph';

    /** Die Klasse jedes Speichern-Knopfs ([D-860](../../../docs/NewConcept/90-decision-log.md)). */
    public const SAVE = 'taxmod-save';
}
