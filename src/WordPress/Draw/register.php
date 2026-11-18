<?php declare(strict_types=1);

/**
 * Hands the core its drawer as soon as the autoloader is there — in the plugin, in every check and in the core run alike.
 *
 * ⚠️ *Scaffolding of renderer step 6c (D-635): while composite renderers still build markup, they need the drawn piece of a
 * renderer that already only describes. When the last of them describes too, the page is drawn once at the edge and this
 * file goes (umsetzung-2026-11.md, A15).*
 */

Taxmod\Core\Renderer\RenderResult::drawWith(new Taxmod\WordPress\Draw\EdgeDrawer());
