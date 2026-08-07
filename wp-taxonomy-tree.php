<?php
/**
 * Plugin Name:       WP Taxonomy Tree
 * Description:       Hierarchical taxonomy tree environment for wp-admin (scaffold preview).
 * Version:           0.0.291
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Stefan Fambach
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-taxonomy-tree
 * Domain Path:       /languages
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTT_VERSION', '0.0.291' );
define( 'WTT_PLUGIN_FILE', __FILE__ );
define( 'WTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
/*
 * Junction/symlink-safe public URL. plugin_dir_url( __FILE__ ) resolves the real path
 * outside wp-content/plugins and yields broken asset URLs (…/plugins/C:/Devel/…).
 */
define(
	'WTT_PLUGIN_URL',
	trailingslashit( plugins_url( '/', WP_PLUGIN_DIR . '/wp-taxonomy-tree/wp-taxonomy-tree.php' ) )
);

require_once WTT_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once WTT_PLUGIN_DIR . 'includes/class-taxonomy.php';
require_once WTT_PLUGIN_DIR . 'includes/class-media-render.php';
require_once WTT_PLUGIN_DIR . 'includes/class-object-render.php';
require_once WTT_PLUGIN_DIR . 'includes/class-footer-ops.php';
require_once WTT_PLUGIN_DIR . 'includes/class-sample-data.php';
require_once WTT_PLUGIN_DIR . 'includes/class-model-data.php';
require_once WTT_PLUGIN_DIR . 'includes/class-model-data-admin.php';
require_once WTT_PLUGIN_DIR . 'includes/class-settings.php';
require_once WTT_PLUGIN_DIR . 'includes/class-catalog-bindings.php';
require_once WTT_PLUGIN_DIR . 'includes/class-node-type.php';
require_once WTT_PLUGIN_DIR . 'includes/class-relation.php';
require_once WTT_PLUGIN_DIR . 'includes/class-attribute.php';
require_once WTT_PLUGIN_DIR . 'includes/class-trash.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-model.php';
require_once WTT_PLUGIN_DIR . 'includes/class-table-validator.php';
require_once WTT_PLUGIN_DIR . 'includes/class-demo-data.php';
require_once WTT_PLUGIN_DIR . 'includes/class-case-data.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-ajax.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-admin.php';
require_once WTT_PLUGIN_DIR . 'includes/class-composition.php';
require_once WTT_PLUGIN_DIR . 'includes/class-blocks.php';
require_once WTT_PLUGIN_DIR . 'includes/class-plugin.php';

WTT\Taxonomy::register();
WTT\Plugin::instance();
WTT\Blocks::register();
