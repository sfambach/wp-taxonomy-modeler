<?php
/**
 * Gutenberg blocks (Q62 scaffold).
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Gutenberg blocks (collection-table, object-view) + REST helpers.
 */
final class Blocks {

	public const BLOCK_NAME = 'taxo/collection-table';

	public const OBJECT_VIEW_BLOCK = 'taxo/object-view';

	/** Script handle generated from block.json editorScript. */
	public const EDITOR_SCRIPT = 'taxo-collection-table-editor-script';

	public const OBJECT_VIEW_EDITOR_SCRIPT = 'taxo-object-view-editor-script';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_filter( 'block_categories_all', array( self::class, 'register_block_category' ), 10, 2 );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'localize_editor' ), 100 );
		add_action( 'admin_notices', array( self::class, 'maybe_missing_build_notice' ) );
		add_action( 'save_post', array( self::class, 'sync_composition_rows_meta' ), 20, 2 );
	}

	/**
	 * Dedicated inserter category so “Taxo” is easy to find.
	 *
	 * @param array<int, array<string, string>> $categories Categories.
	 * @return array<int, array<string, string>>
	 */
	public static function register_block_category( array $categories, $context = null ): array {
		foreach ( $categories as $cat ) {
			if ( isset( $cat['slug'] ) && 'taxo' === $cat['slug'] ) {
				return $categories;
			}
		}
		$categories[] = array(
			'slug'  => 'taxo',
			'title' => __( 'Taxo', 'wp-taxonomy-tree' ),
			'icon'  => 'editor-table',
		);
		return $categories;
	}

	public static function register_block(): void {
		self::register_collection_table_block();
		self::register_object_view_block();
	}

	private static function register_collection_table_block(): void {
		$block_dir = WTT_PLUGIN_DIR . 'build/blocks/collection-table';
		if ( ! is_readable( $block_dir . '/block.json' ) ) {
			return;
		}

		/*
		 * Register assets via WTT_PLUGIN_URL — not file:./ in block.json alone.
		 * Junction/symlink checkouts make WP resolve editorScript to a broken URL
		 * like …/plugins/C:/Devel/…/index.js (403), so the block never appears in the inserter.
		 */
		self::register_block_assets( $block_dir );

		register_block_type(
			$block_dir,
			array(
				'editor_script'   => self::EDITOR_SCRIPT,
				'editor_style'    => 'taxo-collection-table-editor-style',
				'style'           => 'taxo-collection-table-style',
				'render_callback' => array( self::class, 'render_collection_table' ),
			)
		);
	}

	private static function register_object_view_block(): void {
		$block_dir = WTT_PLUGIN_DIR . 'build/blocks/object-view';
		if ( ! is_readable( $block_dir . '/block.json' ) ) {
			return;
		}

		self::register_object_view_assets( $block_dir );

		register_block_type(
			$block_dir,
			array(
				'editor_script'   => self::OBJECT_VIEW_EDITOR_SCRIPT,
				'editor_style'    => 'taxo-object-view-editor-style',
				'style'           => 'taxo-object-view-style',
				'render_callback' => array( self::class, 'render_object_view' ),
			)
		);
	}

	/**
	 * @param string $block_dir Absolute path to build/blocks/collection-table.
	 */
	private static function register_block_assets( string $block_dir ): void {
		$asset_path = $block_dir . '/index.asset.php';
		$asset      = is_readable( $asset_path )
			? include $asset_path
			: array(
				'dependencies' => array(
					'wp-blocks',
					'wp-element',
					'wp-block-editor',
					'wp-components',
					'wp-api-fetch',
				),
				'version'      => WTT_VERSION,
			);

		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();
		$version = isset( $asset['version'] ) ? (string) $asset['version'] : WTT_VERSION;
		$base    = 'build/blocks/collection-table';

		wp_register_script(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-media-render.js',
			array(),
			WTT_VERSION,
			true
		);
		wp_register_script(
			'wtt-sample-data',
			WTT_PLUGIN_URL . 'assets/js/wtt-sample-data.js',
			array( 'wtt-media-render' ),
			WTT_VERSION,
			true
		);
		wp_register_script(
			'wtt-node-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-node-render.js',
			array( 'wtt-sample-data' ),
			WTT_VERSION,
			true
		);
		if ( ! in_array( 'wtt-node-render', $deps, true ) ) {
			$deps[] = 'wtt-node-render';
		}

		wp_register_script(
			self::EDITOR_SCRIPT,
			WTT_PLUGIN_URL . $base . '/index.js',
			$deps,
			$version,
			true
		);

		if ( is_readable( $block_dir . '/index.css' ) ) {
			wp_register_style(
				'taxo-collection-table-editor-style',
				WTT_PLUGIN_URL . $base . '/index.css',
				array(),
				$version
			);
		}

		if ( is_readable( $block_dir . '/style-index.css' ) ) {
			wp_register_style(
				'taxo-collection-table-style',
				WTT_PLUGIN_URL . $base . '/style-index.css',
				array(),
				$version
			);
		}
	}

	/**
	 * @param string $block_dir Absolute path to build/blocks/object-view.
	 */
	private static function register_object_view_assets( string $block_dir ): void {
		$asset_path = $block_dir . '/index.asset.php';
		$asset      = is_readable( $asset_path )
			? include $asset_path
			: array(
				'dependencies' => array(
					'wp-blocks',
					'wp-element',
					'wp-block-editor',
					'wp-components',
					'wp-api-fetch',
				),
				'version'      => WTT_VERSION,
			);

		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();
		/* Prefer plugin version so hard-reload always picks up shared + block assets. */
		$version = defined( 'WTT_VERSION' ) ? WTT_VERSION : ( isset( $asset['version'] ) ? (string) $asset['version'] : '0.0.1' );
		$base    = 'build/blocks/object-view';

		wp_register_script(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-media-render.js',
			array(),
			WTT_VERSION,
			true
		);
		wp_register_style(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-media-render.css',
			array(),
			WTT_VERSION
		);
		wp_register_script(
			'wtt-sample-data',
			WTT_PLUGIN_URL . 'assets/js/wtt-sample-data.js',
			array( 'wtt-media-render' ),
			WTT_VERSION,
			true
		);
		wp_register_script(
			'wtt-node-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-node-render.js',
			array( 'wtt-sample-data' ),
			WTT_VERSION,
			true
		);
		wp_register_script(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-object-render.js',
			array( 'wtt-node-render', 'wtt-media-render' ),
			WTT_VERSION,
			true
		);
		wp_register_style(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-object-render.css',
			array( 'wtt-media-render' ),
			WTT_VERSION
		);

		if ( ! in_array( 'wtt-object-render', $deps, true ) ) {
			$deps[] = 'wtt-object-render';
		}

		wp_register_script(
			self::OBJECT_VIEW_EDITOR_SCRIPT,
			WTT_PLUGIN_URL . $base . '/index.js',
			$deps,
			$version,
			true
		);

		$editor_style_deps = array( 'wtt-object-render' );
		if ( is_readable( $block_dir . '/index.css' ) ) {
			wp_register_style(
				'taxo-object-view-editor-style',
				WTT_PLUGIN_URL . $base . '/index.css',
				$editor_style_deps,
				$version
			);
		}

		$style_deps = array( 'wtt-object-render' );
		if ( is_readable( $block_dir . '/style-index.css' ) ) {
			wp_register_style(
				'taxo-object-view-style',
				WTT_PLUGIN_URL . $base . '/style-index.css',
				$style_deps,
				$version
			);
		}
	}

	/**
	 * Admin hint when block assets were not built (`npm run build`).
	 */
	public static function maybe_missing_build_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$collection_ok = is_readable( WTT_PLUGIN_DIR . 'build/blocks/collection-table/block.json' );
		$object_ok     = is_readable( WTT_PLUGIN_DIR . 'build/blocks/object-view/block.json' );
		if ( $collection_ok && $object_ok ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$base = (string) $screen->base;
		if ( 'plugins' !== $base && 'dashboard' !== $base && false === strpos( $base, 'post' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'WP Taxonomy Tree: Gutenberg block assets missing. Run npm run build in the plugin folder so Taxo blocks appear in the inserter.',
			'wp-taxonomy-tree'
		);
		echo '</p></div>';
	}

	public static function localize_editor(): void {
		self::localize_collection_table_editor();
		self::localize_object_view_editor();
	}

	private static function localize_collection_table_editor(): void {
		$handle = self::resolve_editor_script_handle( self::BLOCK_NAME, self::EDITOR_SCRIPT );
		if ( '' === $handle ) {
			return;
		}

		wp_localize_script(
			$handle,
			'wttCollectionTable',
			array(
				'restBase'       => esc_url_raw( rest_url( 'wtt/v1' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
				'treePickerMode' => Settings::tree_picker_mode(),
				'collections'    => Composition::list_all_collections(),
				'i18n'           => array(
					'title'                       => __( 'Taxo Model table', 'wp-taxonomy-tree' ),
					'pickCollection'              => __( 'Model / node', 'wp-taxonomy-tree' ),
					'pickHint'                    => __( 'Pick a model or schema node (e.g. Kontakt, Platine). Columns come from its attributes.', 'wp-taxonomy-tree' ),
					'flowHint'                    => __( 'Choose a model node, then fill the rows.', 'wp-taxonomy-tree' ),
					'flowHintInstance'            => __( 'Model bound — pick or create a dataset instance.', 'wp-taxonomy-tree' ),
					'chooseModelCanvas'           => __( 'Choose a model node in the tree below.', 'wp-taxonomy-tree' ),
					'changeModel'                 => __( 'Change model…', 'wp-taxonomy-tree' ),
					'changeInstance'              => __( 'Change dataset…', 'wp-taxonomy-tree' ),
					'pickInstance'                => __( 'Dataset (instance)', 'wp-taxonomy-tree' ),
					'pickInstanceHint'            => __( 'Pick an existing model-data instance or create a new one.', 'wp-taxonomy-tree' ),
					'createInstance'              => __( 'Create new', 'wp-taxonomy-tree' ),
					'noInstances'                 => __( 'No instances yet. Create one to continue.', 'wp-taxonomy-tree' ),
					'instanceLoadFailed'          => __( 'Could not load instances.', 'wp-taxonomy-tree' ),
					'instanceCreateFailed'        => __( 'Could not create instance.', 'wp-taxonomy-tree' ),
					'datasetLabel'                => __( 'Dataset:', 'wp-taxonomy-tree' ),
					'noInstance'                  => __( 'No dataset', 'wp-taxonomy-tree' ),
					'savingInstance'              => __( 'Saving instance…', 'wp-taxonomy-tree' ),
					'savedInstance'               => __( 'Instance saved.', 'wp-taxonomy-tree' ),
					'addRow'                     => __( 'Add row', 'wp-taxonomy-tree' ),
					'removeRow'                   => __( 'Remove', 'wp-taxonomy-tree' ),
					'noCollection'                => __( 'Choose a model node in the block canvas.', 'wp-taxonomy-tree' ),
					'noCollections'               => __( 'No model nodes found. Create a node with attributes under Taxonomy Tree (e.g. Fallstudie/Model/…), then reload the editor.', 'wp-taxonomy-tree' ),
					'noColumns'                   => __( 'This node has no attributes yet. Add attributes in Taxonomy Tree.', 'wp-taxonomy-tree' ),
					'loading'                     => __( 'Loading schema…', 'wp-taxonomy-tree' ),
					'colIndex'                    => __( '#', 'wp-taxonomy-tree' ),
					'saving'                      => __( 'Saving catalog…', 'wp-taxonomy-tree' ),
					'saved'                       => __( 'Catalog saved.', 'wp-taxonomy-tree' ),
					'nodePickerChoose'            => __( 'Choose…', 'wp-taxonomy-tree' ),
					'nodePickerChange'            => __( 'Change…', 'wp-taxonomy-tree' ),
					'nodePickerClear'             => __( 'Clear', 'wp-taxonomy-tree' ),
					'nodePickerExpand'            => __( 'Expand', 'wp-taxonomy-tree' ),
					'nodePickerCollapse'          => __( 'Collapse', 'wp-taxonomy-tree' ),
					'nodePickerSearch'            => __( 'Search', 'wp-taxonomy-tree' ),
					'nodePickerSearchEmpty'       => __( 'No matching nodes.', 'wp-taxonomy-tree' ),
					'nodePickerAbstractHint'      => __( 'Abstract catalog — expand and choose a child, not this folder.', 'wp-taxonomy-tree' ),
					'nodeRefChooserTitle'         => __( 'Choose catalog entries', 'wp-taxonomy-tree' ),
					'nodeRefChooserEmpty'         => __( 'No matching entries.', 'wp-taxonomy-tree' ),
					'nodeRefEmpty'                => __( 'No catalog targets', 'wp-taxonomy-tree' ),
					'nodeRefAddNew'               => __( 'Add new…', 'wp-taxonomy-tree' ),
					'nodeRefBackList'             => __( 'Back to list', 'wp-taxonomy-tree' ),
					'nodeRefCreate'               => __( 'Create', 'wp-taxonomy-tree' ),
					'nodeRefApply'                => __( 'Apply', 'wp-taxonomy-tree' ),
					'nodeRefNameRequired'         => __( 'Name is required.', 'wp-taxonomy-tree' ),
					'nodeRefCreating'             => __( 'Creating…', 'wp-taxonomy-tree' ),
					'nodeRefCreateFailed'         => __( 'Could not create entry.', 'wp-taxonomy-tree' ),
					'cancel'                      => __( 'Cancel', 'wp-taxonomy-tree' ),
					'nodePickerSearchPlaceholder' => __( 'Search…', 'wp-taxonomy-tree' ),
				),
			)
		);
	}

	private static function localize_object_view_editor(): void {
		$handle = self::resolve_editor_script_handle( self::OBJECT_VIEW_BLOCK, self::OBJECT_VIEW_EDITOR_SCRIPT );
		if ( '' === $handle ) {
			return;
		}

		$i18n = Object_Render::i18n();
		$i18n['title'] = __( 'Taxo Object view', 'wp-taxonomy-tree' );

		wp_localize_script(
			'wtt-object-render',
			'wttObjectRenderI18n',
			Object_Render::i18n()
		);
		wp_add_inline_script(
			'wtt-object-render',
			'if (window.WTTObjectRender) { window.WTTObjectRender.configure({ i18n: window.wttObjectRenderI18n || {} }); }',
			'after'
		);

		wp_localize_script(
			$handle,
			'wttObjectView',
			array(
				'restBase'   => esc_url_raw( rest_url( 'wtt/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'taxonomies' => Taxonomy::scaffold_taxonomies(),
				'nodes'      => Object_Render::list_pickable_nodes(),
				'i18n'       => $i18n,
			)
		);
	}

	/**
	 * Resolve the editor script handle registered with the block.
	 *
	 * @param string $block_name Registered block name.
	 * @param string $fallback   Preferred handle constant.
	 */
	private static function resolve_editor_script_handle( string $block_name = '', string $fallback = '' ): string {
		if ( '' === $block_name ) {
			$block_name = self::BLOCK_NAME;
		}
		if ( '' === $fallback ) {
			$fallback = self::EDITOR_SCRIPT;
		}

		$block = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( $block && ! empty( $block->editor_script ) ) {
			$script = $block->editor_script;
			if ( is_array( $script ) ) {
				$script = $script[0] ?? '';
			}
			$script = (string) $script;
			if ( '' !== $script && ( wp_script_is( $script, 'registered' ) || wp_script_is( $script, 'enqueued' ) ) ) {
				return $script;
			}
		}

		$candidates = array(
			$fallback,
			$block_name === self::OBJECT_VIEW_BLOCK
				? 'taxo-object-view-editor-script'
				: 'taxo-collection-table-editor-script',
			$block_name === self::OBJECT_VIEW_BLOCK
				? 'taxo-object-view-index'
				: 'taxo-collection-table-index',
		);
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			if ( wp_script_is( $candidate, 'registered' ) || wp_script_is( $candidate, 'enqueued' ) ) {
				return $candidate;
			}
		}
		return '';
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'wtt/v1',
			'/collections',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_list_collections' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'wtt/v1',
			'/collections/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_get_schema' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'wtt/v1',
			'/collections/(?P<id>\d+)/rows',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_sync_catalog_rows' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_categories' );
				},
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'wtt/v1',
			'/model-data/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'rest_model_data_list' ),
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'taxonomy' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'rest_model_data_save' ),
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			'wtt/v1',
			'/object-view/taxonomies',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_object_view_taxonomies' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'wtt/v1',
			'/object-view/nodes',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_object_view_nodes' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'taxonomy' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'wtt/v1',
			'/object-view/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_object_view_get' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'id'         => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'taxonomy'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'instanceId' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_list_collections( \WP_REST_Request $request ) {
		return new \WP_REST_Response(
			array(
				'collections' => Composition::list_all_collections(),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_get_schema( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$taxonomy = Taxonomy::taxonomy_for_term( $id );
		$schema   = Composition::get_schema( $taxonomy, $id );
		if ( null === $schema ) {
			return new \WP_Error( 'wtt_not_found', __( 'Model node not found.', 'wp-taxonomy-tree' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $schema, 200 );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_sync_catalog_rows( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$taxonomy = Taxonomy::taxonomy_for_term( $id );
		$rows     = $request->get_param( 'rows' );
		if ( ! is_array( $rows ) ) {
			$body = $request->get_json_params();
			$rows = is_array( $body ) && isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
		}
		$result = Composition::sync_catalog_rows( $taxonomy, $id, $rows );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Resolve taxonomy for a structure term id (optional query/body override).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $structure_id Structure term id.
	 */
	private static function rest_resolve_structure_taxonomy( \WP_REST_Request $request, int $structure_id ): string {
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		if ( '' === $taxonomy ) {
			$body = $request->get_json_params();
			if ( is_array( $body ) && isset( $body['taxonomy'] ) ) {
				$taxonomy = sanitize_key( (string) $body['taxonomy'] );
			}
		}
		if ( '' === $taxonomy ) {
			$taxonomy = Taxonomy::taxonomy_for_term( $structure_id );
		}
		return $taxonomy;
	}

	/**
	 * List model-data instances for a structure host.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_model_data_list( \WP_REST_Request $request ) {
		$structure_id = (int) $request['id'];
		$taxonomy     = self::rest_resolve_structure_taxonomy( $request, $structure_id );
		if ( '' === $taxonomy || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}
		$structure = Model_Data::structure_dto( $taxonomy, $structure_id );
		if ( null === $structure ) {
			return new \WP_Error( 'wtt_not_found', __( 'Structure node not found.', 'wp-taxonomy-tree' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response(
			array(
				'structure' => $structure,
				'instances' => Model_Data::list( $taxonomy, $structure_id ),
			),
			200
		);
	}

	/**
	 * Create or update a model-data instance (empty values allowed for create).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_model_data_save( \WP_REST_Request $request ) {
		$structure_id = (int) $request['id'];
		$taxonomy     = self::rest_resolve_structure_taxonomy( $request, $structure_id );
		if ( '' === $taxonomy || ! Taxonomy::is_scaffold( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Invalid taxonomy.', 'wp-taxonomy-tree' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			return new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$payload = array(
			'id'     => isset( $body['id'] ) ? sanitize_key( (string) $body['id'] ) : '',
			'values' => isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array(),
		);

		$result = Model_Data::save( $taxonomy, $structure_id, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'instance'  => $result,
				'instances' => Model_Data::list( $taxonomy, $structure_id ),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_object_view_taxonomies( \WP_REST_Request $request ) {
		unset( $request );
		return new \WP_REST_Response(
			array(
				'taxonomies' => Taxonomy::scaffold_taxonomies(),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_object_view_nodes( \WP_REST_Request $request ) {
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		return new \WP_REST_Response(
			array(
				'nodes' => Object_Render::list_pickable_nodes( $taxonomy ),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_object_view_get( \WP_REST_Request $request ) {
		$id          = (int) $request['id'];
		$taxonomy    = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		$instance_id = sanitize_key( (string) $request->get_param( 'instanceId' ) );
		$view        = Object_Render::get_view( $taxonomy, $id );
		if ( null === $view ) {
			return new \WP_Error( 'wtt_not_found', __( 'Node not found.', 'wp-taxonomy-tree' ), array( 'status' => 404 ) );
		}
		if ( '' !== $instance_id ) {
			$view = Object_Render::with_instance_values( $view, $instance_id );
		}
		return new \WP_REST_Response( $view, 200 );
	}

	/**
	 * Dynamic block render for Object view.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_object_view( array $attributes, string $content = '' ): string {
		unset( $content );
		return Object_Render::render_html( $attributes );
	}

	/**
	 * Frontend / SSR render. Orphan cell keys (removed columns) stay in attributes but are not shown.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_collection_table( array $attributes, string $content = '' ): string {
		$collection_id = isset( $attributes['collectionTermId'] ) ? (int) $attributes['collectionTermId'] : 0;
		$instance_id   = isset( $attributes['instanceId'] ) ? sanitize_key( (string) $attributes['instanceId'] ) : '';
		$taxonomy      = $collection_id > 0 ? Taxonomy::taxonomy_for_term( $collection_id ) : '';
		$schema        = $collection_id > 0 ? Composition::get_schema( $taxonomy, $collection_id ) : null;

		$columns = $schema['columns'] ?? array();
		$kind    = $schema['kind'] ?? Composition::KIND_TABLE;

		if ( Composition::KIND_CATALOG === $kind ) {
			$rows = $schema['rows'] ?? array();
		} elseif ( Composition::KIND_MODEL === $kind && '' !== $instance_id ) {
			$inst = Model_Data::get( $taxonomy, $collection_id, $instance_id );
			if ( null !== $inst ) {
				$values = isset( $inst['values'] ) && is_array( $inst['values'] ) ? $inst['values'] : array();
				$cells  = array();
				foreach ( $columns as $col ) {
					$col_id         = (string) (int) ( $col['id'] ?? 0 );
					$cells[ $col_id ] = isset( $values[ $col_id ] ) ? (string) $values[ $col_id ] : '';
					/* Also accept int-keyed values from store. */
					if ( '' === $cells[ $col_id ] && isset( $values[ (int) $col_id ] ) ) {
						$cells[ $col_id ] = (string) $values[ (int) $col_id ];
					}
				}
				$rows = array(
					array(
						'id'    => $instance_id,
						'cells' => $cells,
					),
				);
			} else {
				$rows = array();
			}
		} else {
			$rows_raw = isset( $attributes['rows'] ) ? $attributes['rows'] : array();
			if ( is_string( $rows_raw ) ) {
				$decoded  = json_decode( $rows_raw, true );
				$rows_raw = is_array( $decoded ) ? $decoded : array();
			}
			$rows = Composition::normalize_rows( $rows_raw, $columns );
		}

		$title = self::format_instance_title(
			(string) ( $schema['name'] ?? '' ),
			'',
			$kind
		);
		if ( Composition::KIND_MODEL === $kind && '' !== $instance_id ) {
			$title .= ' · ' . $instance_id;
		}

		ob_start();
		echo '<div class="wtt-collection-table">';
		if ( null === $schema ) {
			echo '<p class="wtt-collection-table__empty">' . esc_html__( 'Choose a model node in the block editor.', 'wp-taxonomy-tree' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}
		if ( Composition::KIND_MODEL === $kind && '' === $instance_id ) {
			echo '<h3 class="wtt-collection-table__title">' . esc_html( $title ) . '</h3>';
			echo '<p class="wtt-collection-table__empty">' . esc_html__( 'No dataset selected. Pick or create a model-data instance in the editor.', 'wp-taxonomy-tree' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<h3 class="wtt-collection-table__title">' . esc_html( $title ) . '</h3>';
		if ( array() === $columns ) {
			echo '<p class="wtt-collection-table__empty">' . esc_html__( 'This node has no attributes yet. Add attributes in Taxonomy Tree.', 'wp-taxonomy-tree' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<div class="wtt-collection-table__wrap"><table class="wtt-collection-table__table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( '#', 'wp-taxonomy-tree' ) . '</th>';
		foreach ( $columns as $col ) {
			$label = $col['name'];
			if ( '' !== ( $col['typeName'] ?? '' ) ) {
				$label .= ' (' . $col['typeName'] . ')';
			}
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="' . esc_attr( (string) ( count( $columns ) + 1 ) ) . '">—</td></tr>';
		} else {
			foreach ( $rows as $index => $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $index + 1 ) ) . '</td>';
				foreach ( $columns as $col ) {
					$col_id = (string) (int) $col['id'];
					$val    = isset( $row['cells'][ $col_id ] ) ? (string) $row['cells'][ $col_id ] : '';
					$type_key = strtolower( (string) ( $col['typeKey'] ?? $col['typeName'] ?? '' ) );
					if ( 'node_ref' === $type_key ) {
						echo '<td class="wtt-collection-table__cell--node-ref">';
						echo self::render_node_ref_cell_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
							(string) ( $schema['taxonomy'] ?? $taxonomy ),
							$val,
							$col
						);
						echo '</td>';
					} else {
						echo '<td>' . esc_html( $val ) . '</td>';
					}
				}
				echo '</tr>';
			}
		}

		echo '</tbody></table></div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Display node_ref cell as chips (resolved names), not raw CSV ids.
	 *
	 * @param array<string, mixed> $col Column schema.
	 */
	private static function render_node_ref_cell_html( string $taxonomy, string $raw, array $col ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '<span class="wtt-collection-table__empty-cell">—</span>';
		}

		$ids = array();
		if ( '[' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $v ) {
					$s = trim( (string) $v );
					if ( '' !== $s ) {
						$ids[] = $s;
					}
				}
			}
		}
		if ( array() === $ids ) {
			foreach ( preg_split( '/[,;|]/', $raw ) as $part ) {
				$s = trim( (string) $part );
				if ( '' !== $s ) {
					$ids[] = $s;
				}
			}
		}
		if ( array() === $ids ) {
			return '<span class="wtt-collection-table__empty-cell">—</span>';
		}

		$by_id = array();
		$opts  = isset( $col['nodeRefOptions'] ) && is_array( $col['nodeRefOptions'] ) ? $col['nodeRefOptions'] : array();
		foreach ( $opts as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$oid = isset( $opt['id'] ) ? (string) (int) $opt['id'] : '';
			if ( '' === $oid ) {
				continue;
			}
			$by_id[ $oid ] = (string) ( $opt['name'] ?? $opt['path'] ?? $oid );
		}

		$html = '<div class="wtt-node-render__ref-list wtt-node-render--node-ref wtt-node-render--display">';
		foreach ( $ids as $id ) {
			$label = $by_id[ $id ] ?? '';
			if ( '' === $label && '' !== $taxonomy && ctype_digit( $id ) ) {
				$term = get_term( (int) $id, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$label = $term->name;
				}
			}
			if ( '' === $label ) {
				$label = $id;
			}
			$html .= '<span class="wtt-node-render__ref-chip" title="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</span>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Mirror block instance rows into post meta (Q63 scaffold storage).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function sync_composition_rows_meta( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! has_blocks( $post->post_content ) ) {
			return;
		}

		$instances = self::extract_composition_instances( parse_blocks( $post->post_content ) );
		if ( array() === $instances ) {
			delete_post_meta( $post_id, Composition::META_KEY_ROWS );
			return;
		}

		update_post_meta( $post_id, Composition::META_KEY_ROWS, wp_json_encode( $instances ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<array{collectionTermId:int,rows:list<array<string,mixed>}>}
	 */
	private static function extract_composition_instances( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === self::BLOCK_NAME ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$out[] = array(
					'collectionTermId' => isset( $attrs['collectionTermId'] ) ? (int) $attrs['collectionTermId'] : 0,
					'instanceName'     => isset( $attrs['instanceName'] ) ? (string) $attrs['instanceName'] : '',
					'rows'             => isset( $attrs['rows'] ) && is_array( $attrs['rows'] ) ? $attrs['rows'] : array(),
				);
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::extract_composition_instances( $block['innerBlocks'] ) );
			}
		}
		return $out;
	}

	/**
	 * Collection title for the block (structure / catalog name only).
	 */
	private static function format_instance_title( string $schema_name, string $instance_name = '', string $kind = '' ): string {
		unset( $instance_name, $kind );
		$base = trim( $schema_name );
		return '' !== $base ? $base : __( 'Model', 'wp-taxonomy-tree' );
	}
}
