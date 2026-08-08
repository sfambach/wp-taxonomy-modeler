<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires hooks for the scaffold.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		Tree_Admin::register();
		Model_Data_Admin::register();
		Model_Version_Admin::register();
		Cleanup_Admin::register();
		Settings::register();
		Tree_Ajax::register();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-taxonomy-tree',
			false,
			dirname( plugin_basename( WTT_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
