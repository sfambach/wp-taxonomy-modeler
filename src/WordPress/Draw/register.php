<?php declare(strict_types=1);

/**
 * Hands the core the edge's answer to «does this show anything» (A20) as soon as the autoloader is there — in the plugin, in every
 * check and in the core run alike. The core draws nothing with it.
 */

Taxmod\Core\Renderer\Shows::measureWith(new Taxmod\WordPress\Draw\EdgeMeasures(new Taxmod\WordPress\Draw\EdgeDrawer()));

/**
 * ⚠️ *The modules (Lager, Fertigung, eBay, Etiketten …) name the drawing classes by their old place in the core. Until each
 * of them names the new one, the old name still finds the class ({@see Taxmod\WordPress\Draw\FormerNames}, A17, D-1430).*
 */
spl_autoload_register([Taxmod\WordPress\Draw\FormerNames::class, 'bridge']);
