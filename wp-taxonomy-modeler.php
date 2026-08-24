<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxonomy Modeller
 * Description:       Build data models as a tree of nodes and relations — objects and their relationships, not relational tables.
 * Version:           0.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Stefan Fambach
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmod
 *
 * @see docs/NewConcept/10-domain-core.md
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxmodAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodAutoloader)) {
    // Classes load through Composer PSR-4 and nothing else (`CD-3`), so without the autoloader
    // there is nothing to start. Saying so beats a fatal error somebody has to read a log for.
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxonomy Modeller:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxmod')
            . '</p></div>';
    });

    return;
}

require_once $taxmodAutoloader;

Taxmod\WordPress\Plugin::boot(__FILE__);
