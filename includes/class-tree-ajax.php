<?php
/**
 * Admin-AJAX handlers for the tree UI.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secure AJAX endpoints for tree mutations.
 */
final class Tree_Ajax {

	public const NONCE_ACTION = 'wtt_tree';

	public static function register(): void {
		add_action( 'wp_ajax_wtt_get_tree', array( self::class, 'get_tree' ) );
		add_action( 'wp_ajax_wtt_get_node', array( self::class, 'get_node' ) );
		add_action( 'wp_ajax_wtt_create_term', array( self::class, 'create_term' ) );
		add_action( 'wp_ajax_wtt_delete_term', array( self::class, 'delete_term' ) );
		add_action( 'wp_ajax_wtt_install_demo', array( self::class, 'install_demo' ) );
		add_action( 'wp_ajax_wtt_reset_demo', array( self::class, 'reset_demo' ) );
		add_action( 'wp_ajax_wtt_update_node', array( self::class, 'update_node' ) );
	}

	public static function get_tree(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		wp_send_json_success(
			array(
				'taxonomy' => $taxonomy,
				'tree'     => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function get_node(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$node    = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function create_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parent = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$node = Tree_Model::create_term( $taxonomy, $name, $parent );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function delete_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::delete_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'leaf'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $mode, array( 'leaf', 'promote', 'cascade' ), true ) ) {
			$mode = 'leaf';
		}

		$result = Tree_Model::delete_term( $taxonomy, $term_id, $mode );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function install_demo(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		$result = Demo_Data::install( $taxonomy );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'created'  => $result['created'],
				'existing' => $result['existing'],
				'tree'     => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function reset_demo(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		$result = Demo_Data::reset( $taxonomy );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'deleted'  => $result['deleted'],
				'created'  => $result['created'],
				'existing' => $result['existing'],
				'tree'     => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function update_node(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw     = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data    = array();
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}

		$result = Node_Meta::update( $taxonomy, $term_id, $data );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	private static function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::send_error( new \WP_Error( 'wtt_bad_nonce', __( 'Invalid nonce.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function request_taxonomy() {
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		return $taxonomy;
	}

	private static function send_error( \WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}
}
