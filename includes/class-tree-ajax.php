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
		add_action( 'wp_ajax_wtt_copy_term', array( self::class, 'copy_term' ) );
		add_action( 'wp_ajax_wtt_delete_term', array( self::class, 'delete_term' ) );
		add_action( 'wp_ajax_wtt_reset_demo', array( self::class, 'reset_demo' ) );
		add_action( 'wp_ajax_wtt_set_node_type', array( self::class, 'set_node_type' ) );
		add_action( 'wp_ajax_wtt_set_node_required', array( self::class, 'set_node_required' ) );
		add_action( 'wp_ajax_wtt_set_node_has_footer', array( self::class, 'set_node_has_footer' ) );
		add_action( 'wp_ajax_wtt_set_node_fixed', array( self::class, 'set_node_fixed' ) );
		add_action( 'wp_ajax_wtt_set_branch_child', array( self::class, 'set_branch_child' ) );
		add_action( 'wp_ajax_wtt_get_type_branch', array( self::class, 'get_type_branch' ) );
		add_action( 'wp_ajax_wtt_save_node_settings', array( self::class, 'save_node_settings' ) );
		add_action( 'wp_ajax_wtt_move_term', array( self::class, 'move_term' ) );
		add_action( 'wp_ajax_wtt_reparent_term', array( self::class, 'reparent_term' ) );
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

	public static function copy_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$node    = Tree_Model::copy_term_as_sibling( $taxonomy, $term_id );
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

	public static function reset_demo(): void {
		self::verify_request();

		if ( ! Settings::is_test_mode() ) {
			self::send_error(
				new \WP_Error(
					'wtt_test_mode_off',
					__( 'Reset test tree is only available in test mode.', 'wp-taxonomy-tree' ),
					array( 'status' => 403 )
				)
			);
		}

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

	public static function set_node_type(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_required(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = isset( $_POST['required'] ) ? (string) wp_unslash( $_POST['required'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = in_array( $required, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_required( $taxonomy, $term_id, $required );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_has_footer(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id    = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = isset( $_POST['has_footer'] ) ? (string) wp_unslash( $_POST['has_footer'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = in_array( $has_footer, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_has_footer( $taxonomy, $term_id, $has_footer );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_fixed(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id        = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fixed_node_id  = isset( $_POST['fixed_node_id'] ) ? absint( wp_unslash( $_POST['fixed_node_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Node_Type::set_fixed_node_id( $taxonomy, $term_id, $fixed_node_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_branch_child(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$child_id = isset( $_POST['child_id'] ) ? absint( wp_unslash( $_POST['child_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled  = isset( $_POST['enabled'] ) ? (string) wp_unslash( $_POST['enabled'] ) : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled  = in_array( $enabled, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_branch_child_enabled( $taxonomy, $term_id, $child_id, $enabled );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function get_type_branch(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity = Node_Type::get_quantity_schema_for_type( $taxonomy, $type_id );
		$branch   = null !== $quantity ? null : Node_Type::build_type_branch( $taxonomy, $type_id, array() );

		wp_send_json_success(
			array(
				'typeBranch'     => $branch,
				'typeName'       => $branch['typeName'] ?? ( $quantity['unitName'] ?? '' ),
				'isSet'          => $type_id > 0 ? Node_Type::is_set_typed( $taxonomy, $type_id ) : false,
				'isUnitQuantity' => null !== $quantity,
				'quantitySchema' => $quantity,
			)
		);
	}

	public static function save_node_settings(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$short_description = isset( $_POST['short_description'] ) ? sanitize_text_field( wp_unslash( $_POST['short_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$required = isset( $_POST['required'] ) ? (string) wp_unslash( $_POST['required'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = in_array( $required, array( '1', 'true', 'yes' ), true );

		$has_footer = isset( $_POST['has_footer'] ) ? (string) wp_unslash( $_POST['has_footer'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = in_array( $has_footer, array( '1', 'true', 'yes' ), true );

		$set_separator = isset( $_POST['set_separator'] ) ? (string) wp_unslash( $_POST['set_separator'] ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$set_separator = sanitize_text_field( $set_separator );

		$set_join_units = true;
		if ( isset( $_POST['set_join_units'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_join = (string) wp_unslash( $_POST['set_join_units'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$set_join_units = in_array( $raw_join, array( '1', 'true', 'yes' ), true );
		}

		$set_label_children = true;
		if ( isset( $_POST['set_label_children'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_label = (string) wp_unslash( $_POST['set_label_children'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$set_label_children = in_array( $raw_label, array( '1', 'true', 'yes' ), true );
		}

		$fixed_enabled = isset( $_POST['fixed_enabled'] ) ? (string) wp_unslash( $_POST['fixed_enabled'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fixed_enabled = in_array( $fixed_enabled, array( '1', 'true', 'yes' ), true );
		$fixed_literal = isset( $_POST['fixed_literal'] ) ? sanitize_text_field( wp_unslash( $_POST['fixed_literal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// textarea fixed values may need newlines — allow lightly.
		if ( isset( $_POST['fixed_literal'] ) && is_string( $_POST['fixed_literal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fixed_literal = sanitize_textarea_field( wp_unslash( $_POST['fixed_literal'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$fixed_node_id = isset( $_POST['fixed_node_id'] ) ? absint( wp_unslash( $_POST['fixed_node_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$disabled_raw = isset( $_POST['disabled_branch_ids'] ) ? wp_unslash( $_POST['disabled_branch_ids'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $disabled_raw ) ) {
			$decoded = json_decode( $disabled_raw, true );
			$disabled_raw = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $disabled_raw ) ) {
			$disabled_raw = array();
		}

		$multi_raw = isset( $_POST['prefix_multiplikators'] ) ? wp_unslash( $_POST['prefix_multiplikators'] ) : '{}'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $multi_raw ) ) {
			$decoded_multi = json_decode( $multi_raw, true );
			$multi_raw     = is_array( $decoded_multi ) ? $decoded_multi : array();
		}
		if ( ! is_array( $multi_raw ) ) {
			$multi_raw = array();
		}

		$prefix_root_to_si = null;
		if ( isset( $_POST['prefix_root_to_si'] ) && is_numeric( wp_unslash( $_POST['prefix_root_to_si'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$prefix_root_to_si = (float) wp_unslash( $_POST['prefix_root_to_si'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$result = Node_Type::save_node_settings(
			$taxonomy,
			$term_id,
			array(
				'name'                  => $name,
				'description'           => $description,
				'short_description'     => $short_description,
				'type_id'               => $type_id,
				'required'              => $required,
				'has_footer'            => $has_footer,
				'set_separator'         => $set_separator,
				'set_join_units'        => $set_join_units,
				'set_label_children'    => $set_label_children,
				'fixed_enabled'         => $fixed_enabled,
				'fixed_literal'         => $fixed_literal,
				'fixed_node_id'         => $fixed_node_id,
				'disabled_branch_ids'   => $disabled_raw,
				'prefix_multiplikators' => $multi_raw,
				'prefix_root_to_si'     => $prefix_root_to_si,
			)
		);
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

	public static function move_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id   = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Tree_Model::move_term( $taxonomy, $term_id, $direction );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function reparent_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id       = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_parent_id = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Tree_Model::reparent_term( $taxonomy, $term_id, $new_parent_id );
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
