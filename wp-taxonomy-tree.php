<?php
/**
 * Plugin Name:       WP Taxonomy Tree
 * Description:       Hierarchical taxonomy tree environment for wp-admin (scaffold preview).
 * Version:           0.0.6
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

define( 'WTT_VERSION', '0.0.6' );
define( 'WTT_PLUGIN_FILE', __FILE__ );
define( 'WTT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WTT_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-model.php';
require_once WTT_PLUGIN_DIR . 'includes/class-demo-data.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-ajax.php';
require_once WTT_PLUGIN_DIR . 'includes/class-tree-admin.php';
require_once WTT_PLUGIN_DIR . 'includes/class-plugin.php';

WTT\Plugin::instance();
