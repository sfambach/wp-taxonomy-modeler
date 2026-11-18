<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

/**
 * The drawing classes that left the core in renderer step 6c, by their old name — the one list of the bridge for the
 * modules that still call them so (A17, D-1430).
 *
 * ⚠️ *A name only finds its class; nothing draws twice (D-638). The list may only shrink: `core-html-ratchet-check` counts
 * it. When a module names the new class, its entry goes.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class FormerNames
{
    /** @var array<class-string, class-string> old name ⇒ class at the edge */
    public const MOVED = [
        'Taxmod\\Core\\Renderer\\ControlMarkup'  => ControlMarkup::class,
        'Taxmod\\Core\\Renderer\\DialogMarkup'   => DialogMarkup::class,
        'Taxmod\\Core\\Renderer\\GridMarkup'     => GridMarkup::class,
        'Taxmod\\Core\\Renderer\\IconMarkup'     => IconMarkup::class,
        'Taxmod\\Core\\Renderer\\HintMarkup'     => HintMarkup::class,
        'Taxmod\\Core\\Renderer\\SelectMarkup'   => SelectMarkup::class,
        'Taxmod\\Core\\Renderer\\ToggleMarkup'   => ToggleMarkup::class,
        'Taxmod\\Core\\Renderer\\ConflictDialog' => ConflictDialog::class,
        'Taxmod\\Core\\Tile\\TileGrid'          => TileGrid::class,
        'Taxmod\\Core\\Tile\\PeriodChart'       => PeriodChart::class,
    ];

    /** Lets an old name find its class; loaded only when a module asks for it. */
    public static function bridge(string $class): void
    {
        if (isset(self::MOVED[$class])) {
            class_alias(self::MOVED[$class], $class);
        }
    }
}
