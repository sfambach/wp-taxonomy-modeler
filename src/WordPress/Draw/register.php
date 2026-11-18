<?php declare(strict_types=1);

/**
 * Hands the core its drawer as soon as the autoloader is there — in the plugin, in every check and in the core run alike.
 *
 * ⚠️ *Scaffolding of renderer step 6c (D-635): while composite renderers still build markup, they need the drawn piece of a
 * renderer that already only describes. When the last of them describes too, the page is drawn once at the edge and this
 * part goes (umsetzung-2026-11.md, A15).*
 */

Taxmod\Core\Renderer\RenderResult::drawWith(new Taxmod\WordPress\Draw\EdgeDrawer());

/**
 * ⚠️ *The modules (Lager, Fertigung, eBay, Etiketten …) name the drawing classes by their old place in the core. Until each
 * of them names the new one, the old name still finds the class ({@see Taxmod\WordPress\Draw\FormerNames}, A17, D-1430).*
 */
spl_autoload_register([Taxmod\WordPress\Draw\FormerNames::class, 'bridge']);
