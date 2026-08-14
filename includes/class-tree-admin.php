<?php
/**
 * Admin tree screen.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Taxonomy Tree admin page and assets.
 *
 * Assets are printed inline from disk so Laragon junctions cannot break
 * static file URLs under wp-content/plugins/.
 */
final class Tree_Admin {

	public const PAGE_SLUG = 'wp-taxonomy-tree';

	/** @var array<string, mixed>|null */
	private static ?array $boot_config = null;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'prepare_screen' ) );
		add_action( 'admin_head', array( self::class, 'print_inline_css' ) );
		add_action( 'admin_footer', array( self::class, 'print_inline_js' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
			__( 'Taxonomy Tree', 'wp-taxonomy-tree' ),
			'manage_categories',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-networking',
			58
		);
	}

	private static function is_plugin_screen( string $hook_suffix = '' ): bool {
		if ( '' !== $hook_suffix && 'toplevel_page_' . self::PAGE_SLUG === $hook_suffix ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return self::PAGE_SLUG === $page;
	}

	public static function prepare_screen( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_media();
		self::$boot_config = self::build_config();
	}

	/**
	 * Admin URL for the Taxonomy Tree screen, optionally selecting a term.
	 */
	public static function page_url( int $term_id = 0, string $taxonomy = '' ): string {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $taxonomy ) {
			$args['taxonomy'] = $taxonomy;
		}
		if ( $term_id > 0 ) {
			$args['term_id'] = $term_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_config(): array {
		Taxonomy::register_taxonomies();
		$taxonomies = Taxonomy::scaffold_taxonomies();
		$default    = Taxonomy::default_slug();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : $default;
		if ( ! Taxonomy::is_scaffold( $requested ) || ! Tree_Model::is_hierarchical_taxonomy( $requested ) ) {
			$requested = $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$focus_term = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;
		if ( $focus_term > 0 ) {
			$focus_term_obj = get_term( $focus_term, $requested );
			if ( ! ( $focus_term_obj instanceof \WP_Term ) || is_wp_error( $focus_term_obj ) ) {
				$focus_term = 0;
			}
		}

		$case_study = Taxonomy::is_case_study( $requested );

		// Scaffold: Fallstudie (wtt_fs) only — no BOM Demo_Data auto-seed.
		// maybe_install seeds once when empty; do not re-ensure catalogs on every load.
		if ( current_user_can( Capabilities::edit_terms( $requested ) ) ) {
			Case_Data::maybe_install( $requested );
			/* Bindings: fill missing keys only (Catalog_Bindings::ensure is additive). */
			Catalog_Bindings::ensure( $requested );
			/* Presentation legacy fill: one-shot per taxonomy. */
			Node_Presentation::maybe_migrate_taxonomy( $requested );
		}

		$select_hint   = __( 'Select a node. Taxonomy tree (Definition / Model) — slim detail UI; not a model sign-off.', 'wp-taxonomy-tree' );

		$config = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
			'taxonomy'   => $requested,
			'taxonomies' => $taxonomies,
			'tree'       => Tree_Model::get_tree( $requested ),
			'version'    => WTT_VERSION,
			'presentationPageUrl' => Node_Presentation_Admin::page_url( 0, $requested ),
			'initialTermId'       => $focus_term,
			'caseStudyMode'     => $case_study,
			'testMode'          => Settings::is_test_mode(),
			'hideRootNode'           => Settings::hide_root_node(),
			'showTypeInTree'         => Settings::show_type_in_tree(),
			'showModelDataCounts'    => Settings::show_model_data_counts(),
			'showSetChildProps'      => Settings::show_set_child_props(),
			'saveViaButton'          => Settings::save_via_button(),
			'treePickerMode'         => Settings::tree_picker_mode(),
			'confirmNodeDelete'            => Settings::confirm_node_delete(),
			'warnStructuralModelChange'    => Settings::warn_structural_model_change(),
			'dialogOnValidationWarnings'   => Settings::dialog_on_validation_warnings(),
			'developmentMode'              => Settings::is_development_mode(),
			'modelVersionsUrl'          => class_exists( Model_Version_Admin::class )
				? Model_Version_Admin::page_url()
				: admin_url( 'admin.php?page=wp-taxonomy-tree-model-versions' ),
			'modelDataUrl'              => class_exists( Model_Data_Admin::class )
				? Model_Data_Admin::page_url()
				: admin_url( 'admin.php?page=wp-taxonomy-tree-model-data' ),
			'modelDataNonce'            => class_exists( Model_Data_Admin::class )
				? wp_create_nonce( Model_Data_Admin::NONCE_ACTION )
				: '',
			'treeIcons'         => Tree_Icons::picker_options(),
			'catalogBindings'   => Catalog_Bindings::for_client( $requested ),
			/* Trial: flags + static meta as form rows (label left, chips right). Set false to revert strip layout. */
			'flagsAsFormRow'   => true,
			'i18n'       => array(
				'empty'           => __( 'No terms yet under the taxonomy root.', 'wp-taxonomy-tree' ),
				'selectHint'      => $select_hint,
				'loading'         => __( 'Loading...', 'wp-taxonomy-tree' ),
				'hideRootNode'    => __( 'Hide root', 'wp-taxonomy-tree' ),
				'hideRootNodeHint'=> __( 'Hide the project root and show its children at the top level', 'wp-taxonomy-tree' ),
				'showModelDataCounts'     => __( 'Counts', 'wp-taxonomy-tree' ),
				'showModelDataCountsHint' => __( 'Show Model Data instance counts on structure hosts (e.g. Bauteilliste (23)). Click the number to open Fill Model Data.', 'wp-taxonomy-tree' ),
				'modelDataCountLink'      => __( '%d instances — open Fill Model Data', 'wp-taxonomy-tree' ),
				'taxonomyRootLabel' => __( 'Taxonomy', 'wp-taxonomy-tree' ),
				'addRoot'         => __( 'Add root', 'wp-taxonomy-tree' ),
				'expandAll'       => __( 'Expand', 'wp-taxonomy-tree' ),
				'expandAllHint'   => __( 'Expand all nodes', 'wp-taxonomy-tree' ),
				'collapseAll'     => __( 'Collapse', 'wp-taxonomy-tree' ),
				'collapseAllHint' => __( 'Collapse all nodes', 'wp-taxonomy-tree' ),
				'addChild'        => __( 'Add child', 'wp-taxonomy-tree' ),
				'moveUp'          => __( 'Move up', 'wp-taxonomy-tree' ),
				'moveDown'        => __( 'Move down', 'wp-taxonomy-tree' ),
				'delete'          => __( 'Delete', 'wp-taxonomy-tree' ),
				'deleteNode'      => __( 'Delete node', 'wp-taxonomy-tree' ),
				'deleteNodeHint'  => __( 'Delete this node only. Children move up one level.', 'wp-taxonomy-tree' ),
				'deleteBranch'    => __( 'Delete branch', 'wp-taxonomy-tree' ),
				'deleteBranchHint'=> __( 'Delete this node and its entire branch (all descendants).', 'wp-taxonomy-tree' ),
				'name'            => __( 'Name', 'wp-taxonomy-tree' ),
				'nameHint'        => __( 'Display name — identity is the term ID (stable when renaming or copying). Slug is regenerated from the name on save.', 'wp-taxonomy-tree' ),
				'icon'            => __( 'Icon', 'wp-taxonomy-tree' ),
				'iconHint'        => __( 'Stored on this node as term meta. Pick from the Settings allowlist.', 'wp-taxonomy-tree' ),
				'iconNone'        => __( 'No icon', 'wp-taxonomy-tree' ),
				'nodeDisplay'     => __( 'Display', 'wp-taxonomy-tree' ),
				'nodeDisplayHint' => __( 'Presentation (Q117), Preferred render/converter, and validators (Q118). Icon key on the node (`_wtt_icon`); allowed icons: Settings → Tree icons.', 'wp-taxonomy-tree' ),
				'nodeIdentity'    => __( 'Identity', 'wp-taxonomy-tree' ),
				'nodeIdentityHint'=> __( 'Core properties of this node (name, flags, defaults). Descriptions moved to Presentation (Q118).', 'wp-taxonomy-tree' ),
				'presentationFoldTitle' => __( 'Presentation texts', 'wp-taxonomy-tree' ),
				'presentationFoldHint'  => __( 'Empty fields follow the node name (and update on rename). Saved values stay until you change or clear them.', 'wp-taxonomy-tree' ),
				'presentationFoldLoading' => __( 'Loading…', 'wp-taxonomy-tree' ),
				'presentationFoldEmpty' => __( 'No presentation texts yet — use Edit presentation or Fill from legacy.', 'wp-taxonomy-tree' ),
				'presentationFoldError' => __( 'Could not load presentation.', 'wp-taxonomy-tree' ),
				'presentationEditLink'  => __( 'Open full presentation page…', 'wp-taxonomy-tree' ),
				'presentationEditLinkShort' => __( 'Open presentation…', 'wp-taxonomy-tree' ),
				'presentationBackToNode' => __( 'Back to node', 'wp-taxonomy-tree' ),
				'presentationForm'      => __( 'Form', 'wp-taxonomy-tree' ),
				'presentationTable'     => __( 'Table', 'wp-taxonomy-tree' ),
				'presentationSelect'    => __( 'Select', 'wp-taxonomy-tree' ),
				'presentationSymbol'    => __( 'Symbol', 'wp-taxonomy-tree' ),
				'presentationHelp'      => __( 'Help', 'wp-taxonomy-tree' ),
				'presentationIcon'      => __( 'Icon', 'wp-taxonomy-tree' ),
				'presentationTypeSettings' => __( 'Node presentation settings', 'wp-taxonomy-tree' ),
				'presentationTypeHint'  => __( 'Choose which presentation field of the host node to show (form, table, select, symbol, help, or icon).', 'wp-taxonomy-tree' ),
				'attributesPresentationContext' => __( 'Presentation field', 'wp-taxonomy-tree' ),
				'attributesPresentationContextDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'presentationSave'      => __( 'Save presentation', 'wp-taxonomy-tree' ),
				'presentationSaved'     => __( 'Presentation saved.', 'wp-taxonomy-tree' ),
				'presentationFollowsName' => __( 'Follows node name', 'wp-taxonomy-tree' ),
				'slug'            => __( 'Slug', 'wp-taxonomy-tree' ),
				'slugHint'        => __( 'Derived from the name (updates when you rename). WordPress may append -2 if the slug already exists.', 'wp-taxonomy-tree' ),
				'goToParent'      => __( 'Open parent in tree and settings', 'wp-taxonomy-tree' ),
				'parent'          => __( 'Parent', 'wp-taxonomy-tree' ),
				'description'     => __( 'Description', 'wp-taxonomy-tree' ),
				'descriptionHint' => __( 'Optional longer notes for this node.', 'wp-taxonomy-tree' ),
				'shortDescription'=> __( 'Short description', 'wp-taxonomy-tree' ),
				'shortDescriptionHint' => __( 'Compact expansion of the name (e.g. L → Länge, m → Milli). Shown in dropdowns as “name — short”, labels, help, and tooltips.', 'wp-taxonomy-tree' ),
				'count'           => __( 'Assigned posts', 'wp-taxonomy-tree' ),
				'none'            => __( 'None', 'wp-taxonomy-tree' ),
				'promptRoot'      => __( 'Name for the new root term:', 'wp-taxonomy-tree' ),
				'promptChild'     => __( 'Name for the new child term:', 'wp-taxonomy-tree' ),
				'confirmLeaf'     => __( 'Move this node to Trash?', 'wp-taxonomy-tree' ),
				'confirmNodeOnly' => __( 'Move this node to Trash? Children move up one level.', 'wp-taxonomy-tree' ),
				'confirmBranch'   => __( 'Move this node and all descendants to Trash? Parent/child links are kept.', 'wp-taxonomy-tree' ),
				'confirmMoveToTrash' => __( 'Move this node to Trash?', 'wp-taxonomy-tree' ),
				'confirmPromoteToTrash' => __( 'Move this node to Trash? Children move up one level.', 'wp-taxonomy-tree' ),
				'confirmMoveToTrashBranch' => __( 'Move this node and all descendants to Trash? Parent/child links are kept.', 'wp-taxonomy-tree' ),
				'notDeletable'    => __( 'This system or catalog type cannot be deleted.', 'wp-taxonomy-tree' ),
				'trashCannotDelete' => __( 'The Trash node cannot be deleted.', 'wp-taxonomy-tree' ),
				'trashTitle'      => __( 'Trash', 'wp-taxonomy-tree' ),
				'trashHelp'       => __( 'Soft-deleted nodes keep their parent/child links and appear only under Trash. Empty Trash permanently deletes them.', 'wp-taxonomy-tree' ),
				'trashCountLabel' => __( 'Deleted objects', 'wp-taxonomy-tree' ),
				'trashRootsLabel' => __( 'roots', 'wp-taxonomy-tree' ),
				'trashEmpty'      => __( 'Trash is empty.', 'wp-taxonomy-tree' ),
				'trashEmptyAction'=> __( 'Empty Trash', 'wp-taxonomy-tree' ),
				'trashEmptyConfirm' => __( 'Permanently delete all soft-deleted nodes? This cannot be undone.', 'wp-taxonomy-tree' ),
				'hideNode'        => __( 'Hide node', 'wp-taxonomy-tree' ),
				'hideNodeHint'    => __( 'Keep this node but hide it from the tree. Find it again under Hidden nodes.', 'wp-taxonomy-tree' ),
				'confirmHide'     => __( 'Hide this node from the tree? It stays in the database and appears under Hidden nodes.', 'wp-taxonomy-tree' ),
				'unhideNode'      => __( 'Show again', 'wp-taxonomy-tree' ),
				'unhideNodeHint'  => __( 'Restore this node to the tree under its parent.', 'wp-taxonomy-tree' ),
				'hiddenBinCannotHide' => __( 'The Hidden nodes bin cannot be hidden.', 'wp-taxonomy-tree' ),
				'hiddenTitle'     => __( 'Hidden nodes', 'wp-taxonomy-tree' ),
				'hiddenHelp'      => __( 'Nodes marked hidden stay in the database with their parent links, but are omitted from the normal tree. Unhide to restore them.', 'wp-taxonomy-tree' ),
				'hiddenCountLabel'=> __( 'Hidden objects', 'wp-taxonomy-tree' ),
				'hiddenEmpty'     => __( 'No hidden nodes.', 'wp-taxonomy-tree' ),
				'tableBandBindingsTitle' => __( 'Bindings (type properties)', 'wp-taxonomy-tree' ),
				'tableBandBindingsHint' => __( 'Bindings map type property → child node (not by the child’s display name).', 'wp-taxonomy-tree' ),
				'tableBandBindingHelp' => __( 'Bind this slot to a direct child. Columns come from that child’s fields.', 'wp-taxonomy-tree' ),
				'tableBandUnbound' => __( '— not bound —', 'wp-taxonomy-tree' ),
				'dialogTitle'     => __( 'Delete term with children', 'wp-taxonomy-tree' ),
				'dialogText'      => __( 'This term has children. What should happen to them?', 'wp-taxonomy-tree' ),
				'promoteChildren' => __( 'Move children up one level', 'wp-taxonomy-tree' ),
				'deleteChildren'  => __( 'Delete children as well', 'wp-taxonomy-tree' ),
				'cancel'          => __( 'Cancel', 'wp-taxonomy-tree' ),
				'error'           => __( 'Something went wrong.', 'wp-taxonomy-tree' ),
				'taxonomy'        => __( 'Taxonomy', 'wp-taxonomy-tree' ),
				'dataType'        => __( 'Data type', 'wp-taxonomy-tree' ),
				'dataTypeNone'    => __( 'No type', 'wp-taxonomy-tree' ),
				'dataTypeHint'    => __( 'Pick a type node in chooser scope (Q92). Stored as type_id (Q88: hierarchy type is the parent).', 'wp-taxonomy-tree' ),
				'dataTypeSelf'    => __( 'This node is a data type', 'wp-taxonomy-tree' ),
				'dataTypeSelfHint'=> __( 'This node is a data type — it cannot have a data type assigned.', 'wp-taxonomy-tree' ),
				'dataTypeInherited' => __( 'Inherited from parent', 'wp-taxonomy-tree' ),
				'dataTypeInheritedHint' => __( 'Type is inherited from a parent marked “Inheriting”. Enable Override to choose a different type.', 'wp-taxonomy-tree' ),
				'dataTypeParentLockedHint' => __( 'Specialization: data type is the parent node (not editable yet).', 'wp-taxonomy-tree' ),
				'typeInheriting'  => __( 'Inheriting', 'wp-taxonomy-tree' ),
				'typeInheritingHint' => __( 'When on, children without Override use this node’s effective type (Q76).', 'wp-taxonomy-tree' ),
				'typeInheritingLockedHint' => __( 'Type is inherited from a parent marked Inheriting. Enable Override to change type or Inheriting for your own children.', 'wp-taxonomy-tree' ),
				'typeInheritingTableHint' => __( 'Table type cannot be inheriting — band/field children are not tables.', 'wp-taxonomy-tree' ),
				'typeInheritingNeedTypeHint' => __( 'Assign a data type before enabling Inheriting.', 'wp-taxonomy-tree' ),
				'typeOverride'    => __( 'Override', 'wp-taxonomy-tree' ),
				'typeOverrideHint'=> __( 'When off, this node uses the ancestor’s inheriting type (read-only). When on, pick your own type below.', 'wp-taxonomy-tree' ),
				'isTemplate'      => __( 'Is template', 'wp-taxonomy-tree' ),
				'isTemplateHint'  => __( 'Marks a protected catalog / system template node. Seeded templates are not deletable (unless Development mode is on). Editable only in Development mode.', 'wp-taxonomy-tree' ),
				'isTemplateYes'   => __( 'Yes', 'wp-taxonomy-tree' ),
				'isTemplateNo'    => __( 'No', 'wp-taxonomy-tree' ),
				'nodeFlags'       => __( 'Flags', 'wp-taxonomy-tree' ),
				'nodeFlagsHint'   => __( 'Type catalog flags, inheritance to children, and whether this slot is required.', 'wp-taxonomy-tree' ),
				'nodeMeta'        => __( 'Meta', 'wp-taxonomy-tree' ),
				'nodeMetaHint'    => __( 'Read-only identity and audit: ID, parent, slug, last modified.', 'wp-taxonomy-tree' ),
				'termId'          => __( 'ID', 'wp-taxonomy-tree' ),
				'termIdHint'      => __( 'WordPress term ID for this node.', 'wp-taxonomy-tree' ),
				'lastModifiedBy'  => __( 'Last modified by', 'wp-taxonomy-tree' ),
				'lastModifiedAt'  => __( 'Last modified', 'wp-taxonomy-tree' ),
				'refScope'        => __( 'Catalog root (ref_scope)', 'wp-taxonomy-tree' ),
				'refScopeChoose'  => __( 'Choose catalog root…', 'wp-taxonomy-tree' ),
				'refScopeHintEmbed' => __( 'node_embed: direct children of this root are selectable (respecting allowlist); after pick their fields are embedded.', 'wp-taxonomy-tree' ),
				'refScopeHintSubtree' => __( 'node_embed: direct children of this root are selectable (e.g. Bauteile → Widerstand, Kondensator).', 'wp-taxonomy-tree' ),
				'refScopeHintNodeRef' => __( 'node_ref: pick among descendants under this root (respecting allowed children + their subtrees); id only.', 'wp-taxonomy-tree' ),
				'allowedRefChildren' => __( 'Allowed catalog children', 'wp-taxonomy-tree' ),
				'allowedRefHint'  => __( 'Which direct children of the catalog root may be picked. Default: all. Shared by node_embed and node_ref (node_pick / Q73).', 'wp-taxonomy-tree' ),
				'allowedRefEmpty' => __( 'Catalog root has no direct children yet.', 'wp-taxonomy-tree' ),
				'refScopeHint'    => __( 'Catalog root for node_pick / node_embed / node_ref.', 'wp-taxonomy-tree' ),
				'refScopeNeeded'  => __( 'Set catalog root (ref_scope) first…', 'wp-taxonomy-tree' ),
				'fieldMultiplicity' => __( 'Field multiplicity', 'wp-taxonomy-tree' ),
				'fieldMultiplicityHint' => __( 'How many targets this node_ref may pick at runtime (1..* = many). Not the Mult. column on ref_scope relations (those stay 0..1).', 'wp-taxonomy-tree' ),
				'relationsMultLockedHint' => __( 'Locked: child_of is always 1; ref_scope is always 0..1. Use Field multiplicity under Properties for 1..* picks on node_ref.', 'wp-taxonomy-tree' ),
				'subtreeEmpty'    => __( 'No children under catalog root', 'wp-taxonomy-tree' ),
				'nodeRefEmpty'    => __( 'No descendants under catalog root', 'wp-taxonomy-tree' ),
				'nodeRefChoose'   => __( 'Choose node…', 'wp-taxonomy-tree' ),
				'nodeRefChooserTitle' => __( 'Choose catalog entries', 'wp-taxonomy-tree' ),
				'nodeRefChooserEmpty' => __( 'No matching entries.', 'wp-taxonomy-tree' ),
				'nodeRefAddNew'   => __( 'Add new…', 'wp-taxonomy-tree' ),
				'nodeRefBackList' => __( 'Back to list', 'wp-taxonomy-tree' ),
				'nodeRefCreate'   => __( 'Create', 'wp-taxonomy-tree' ),
				'nodeRefApply'    => __( 'Apply', 'wp-taxonomy-tree' ),
				'nodeRefNameRequired' => __( 'Name is required.', 'wp-taxonomy-tree' ),
				'nodeRefCreating' => __( 'Creating…', 'wp-taxonomy-tree' ),
				'nodeRefCreateFailed' => __( 'Could not create entry.', 'wp-taxonomy-tree' ),
				'nodePickerSelected' => __( 'Selected:', 'wp-taxonomy-tree' ),
				'nodePickerClear' => __( 'Clear', 'wp-taxonomy-tree' ),
				'nodePickerChange' => __( 'Change…', 'wp-taxonomy-tree' ),
				'nodePickerChoose' => __( 'Choose…', 'wp-taxonomy-tree' ),
				'nodePickerExpand' => __( 'Expand', 'wp-taxonomy-tree' ),
				'nodePickerCollapse' => __( 'Collapse', 'wp-taxonomy-tree' ),
				'nodePickerTitle' => __( 'Choose node', 'wp-taxonomy-tree' ),
				'nodePickerSearch' => __( 'Search', 'wp-taxonomy-tree' ),
				'nodePickerSearchPlaceholder' => __( 'Search nodes…', 'wp-taxonomy-tree' ),
				'nodePickerSearchEmpty' => __( 'No matching nodes.', 'wp-taxonomy-tree' ),
				'nodePickerAbstractHint' => __( 'Abstract catalog — expand and choose a child, not this folder.', 'wp-taxonomy-tree' ),
				'nodePickerNotSelectable' => __( 'Not selectable in this chooser.', 'wp-taxonomy-tree' ),
				'fixedCatalogWholeTreeHint' => __( 'No catalog root (ref_scope) yet — pick any node in the tree, or set ref_scope first to limit to catalog children.', 'wp-taxonomy-tree' ),
				'typePresetsHint' => __( 'Settings on a type node are defaults applied when this type is assigned to a slot (Q71).', 'wp-taxonomy-tree' ),
				'dynamicRefTitle' => __( 'Fields of', 'wp-taxonomy-tree' ),
				'dynamicRefEmpty' => __( 'Selected node has no child fields.', 'wp-taxonomy-tree' ),
				'dynamicRefPick'  => __( 'Pick a catalog node to show its fields.', 'wp-taxonomy-tree' ),
				'dataTypeSaving'  => __( 'Saving…', 'wp-taxonomy-tree' ),
				'saveSettings'    => __( 'Save settings', 'wp-taxonomy-tree' ),
				'undoSettings'    => __( 'Undo', 'wp-taxonomy-tree' ),
				'settingsUnsavedHint' => __( 'Unsaved changes', 'wp-taxonomy-tree' ),
				'settingsSaving'  => __( 'Saving…', 'wp-taxonomy-tree' ),
				'settingsSaved'   => __( 'Saved', 'wp-taxonomy-tree' ),
				'copy'            => __( 'Copy', 'wp-taxonomy-tree' ),
				'copyHint'        => __( 'Copy selection (Ctrl+C). Child links only if both ends are selected.', 'wp-taxonomy-tree' ),
				'duplicateNode'   => __( 'Duplicate', 'wp-taxonomy-tree' ),
				'duplicateNodeHint' => __( 'Duplicate as sibling under the same parent (placed directly below; name gets “ (copy)”).', 'wp-taxonomy-tree' ),
				'reparent'        => __( 'Reparent', 'wp-taxonomy-tree' ),
				'reparentDragHint'=> __( 'Drag: top edge = before sibling, middle = into (last child), bottom edge = after sibling.', 'wp-taxonomy-tree' ),
				'reparentBlockedDrop' => __( 'Cannot move a node under itself or its own descendant.', 'wp-taxonomy-tree' ),
				'reparentTitle'   => __( 'Change parent', 'wp-taxonomy-tree' ),
				'reparentText'    => __( 'Choose a new parent for this term. Children stay attached.', 'wp-taxonomy-tree' ),
				'reparentRoot'    => __( 'Root (no parent)', 'wp-taxonomy-tree' ),
				'reparentApply'   => __( 'Move', 'wp-taxonomy-tree' ),
				'reparentPicked'  => __( 'New parent:', 'wp-taxonomy-tree' ),
				'reparentBlocked' => __( 'unavailable', 'wp-taxonomy-tree' ),
				'inspecting'      => __( 'Inspecting:', 'wp-taxonomy-tree' ),
				'setMembers'      => __( 'Set members', 'wp-taxonomy-tree' ),
				'setChildProperties' => __( 'Child properties', 'wp-taxonomy-tree' ),
				'setChildPropertiesHint' => __( 'Set members come from outgoing composition Relations (order = list order). Select a member node to edit its fields.', 'wp-taxonomy-tree' ),
				'setMemberType'   => __( 'Type', 'wp-taxonomy-tree' ),
				'setMemberUntyped'=> __( 'not typed', 'wp-taxonomy-tree' ),
				'setParent'       => __( 'Member of set', 'wp-taxonomy-tree' ),
				'relationsTitle'  => __( 'Relations', 'wp-taxonomy-tree' ),
				'relationsFoldLoading' => __( 'Loading…', 'wp-taxonomy-tree' ),
				'relationsFoldCollapsedHint' => __( 'Expand to load Relation types and edit edges. Power-user surface — left collapsed by default.', 'wp-taxonomy-tree' ),
				'relationsFoldError' => __( 'Could not load relation types.', 'wp-taxonomy-tree' ),
				'relationsStoredCountHint' => __( 'Stored relation edges (plus synthetic rows when expanded)', 'wp-taxonomy-tree' ),
				'attributesTitle' => __( 'Attributes', 'wp-taxonomy-tree' ),
				'attributesFoldCollapsedHint' => __( 'Expand to edit attributes.', 'wp-taxonomy-tree' ),
				'attributesFoldCountHint' => __( 'Attribute rows (own + inherited)', 'wp-taxonomy-tree' ),
				'attributesHelp'  => __( 'Name + type + multiplicity + Bindung. Inherited along child_of. Own attrs: Default / RO / Hide live on the Relation edge. Inherited Hide / RO / Default are host-local overrides only (do not change the parent definition). Actions column is always last.', 'wp-taxonomy-tree' ),
				'attributesEmpty' => __( 'No attributes yet.', 'wp-taxonomy-tree' ),
				'attributesAdd'   => __( 'Add attribute', 'wp-taxonomy-tree' ),
				'attributesName'  => __( 'Name', 'wp-taxonomy-tree' ),
				'attributesType'  => __( 'Type', 'wp-taxonomy-tree' ),
				'attributesMult'  => __( 'Mult.', 'wp-taxonomy-tree' ),
				'attributesMultTitle' => __( 'Multiplicity', 'wp-taxonomy-tree' ),
				'attributesFixed' => __( 'Default', 'wp-taxonomy-tree' ),
				'attributesReadonly' => __( 'RO', 'wp-taxonomy-tree' ),
				'attributesReadonlyHint' => __( 'When on, the attribute is not editable in forms (default value may still apply). Own attrs store RO on the Relation edge; inherited attrs use a host-local override.', 'wp-taxonomy-tree' ),
				'attributesReadonlyTitle' => __( 'Read-only', 'wp-taxonomy-tree' ),
				'attributesFixedTitle' => __( 'Default value', 'wp-taxonomy-tree' ),
				'nodeReadonly' => __( 'Read-only', 'wp-taxonomy-tree' ),
				'nodeReadonlyHint' => __( 'When on, this field is not editable in forms. Separate from Default value seeding.', 'wp-taxonomy-tree' ),
				'nodeReadonlyGrayHint' => __( 'Read-only applies to attribute slots (Attributes panel or slot settings). Grayed on type catalog leaves and structure hosts.', 'wp-taxonomy-tree' ),
				'nodeDefaultHint' => __( 'Optional default seed for this node (specializations and typed fields). Not a lock — use Read-only to lock. Attribute instance defaults stay in the Attributes panel.', 'wp-taxonomy-tree' ),
				'nodeDefaultGrayHint' => __( 'Builtin Simple leaves do not store a default here. Specializations under the Simple may set Default value; attribute defaults stay in the Attributes panel.', 'wp-taxonomy-tree' ),
				'nodeDefaultOff' => __( 'No default value', 'wp-taxonomy-tree' ),
				'nodeDefaultOn' => __( 'Use default value', 'wp-taxonomy-tree' ),
				'nodeDefaultChoose' => __( 'Choose node', 'wp-taxonomy-tree' ),
				'nodeDefaultUnavailable' => __( 'Default value is not editable on builtin Simple types.', 'wp-taxonomy-tree' ),
				'nodeDefaultLiteralHint' => __( 'Simple types: enter the default (e.g. 10 for double). Empty is not allowed while “Use default value” is selected.', 'wp-taxonomy-tree' ),
				'nodeDefaultCatalogHint' => __( 'Catalog types: pick a Typen node (e.g. Einheit → Ohm).', 'wp-taxonomy-tree' ),
				'attributesFixedAdd' => __( 'Set default', 'wp-taxonomy-tree' ),
				'attributesReorderUp' => __( 'Move up', 'wp-taxonomy-tree' ),
				'attributesReorderDown' => __( 'Move down', 'wp-taxonomy-tree' ),
				'attributesBinding' => __( 'Bindung', 'wp-taxonomy-tree' ),
				'attributesBindingComposition' => __( 'Composition (besteht_aus)', 'wp-taxonomy-tree' ),
				'attributesBindingAggregation' => __( 'Aggregation', 'wp-taxonomy-tree' ),
				'attributesInherited' => __( 'Inherited', 'wp-taxonomy-tree' ),
				'attributesInheritedTitle' => __( 'Inherited attributes: Hide / RO / Default on this row are host-local overrides (not the parent Relation edge).', 'wp-taxonomy-tree' ),
				'attributesActions' => __( 'Actions', 'wp-taxonomy-tree' ),
				'attributesInheritedNo' => __( '—', 'wp-taxonomy-tree' ),
				'attributesInheritedYes' => __( 'Yes', 'wp-taxonomy-tree' ),
				'attributesInheritedOverrideBadge' => __( 'override', 'wp-taxonomy-tree' ),
				/* translators: %s: comma-separated override kinds (Hide, RO, Default, Options) */
				'attributesInheritedOverrideHint' => __( 'Host-local override on this node: %s.', 'wp-taxonomy-tree' ),
				'attributesInheritedOverrideHide' => __( 'Hide', 'wp-taxonomy-tree' ),
				'attributesInheritedOverrideRo' => __( 'RO', 'wp-taxonomy-tree' ),
				'attributesInheritedOverrideDefault' => __( 'Default', 'wp-taxonomy-tree' ),
				'attributesInheritedOverrideExtras' => __( 'Options', 'wp-taxonomy-tree' ),
				'attributesHideLabel' => __( 'Hide', 'wp-taxonomy-tree' ),
				'attributesPickType' => __( 'Choose attribute type', 'wp-taxonomy-tree' ),
				'attributesNameRequired' => __( 'Attribute name is required.', 'wp-taxonomy-tree' ),
				'attributesTypeRequired' => __( 'Attribute type is required.', 'wp-taxonomy-tree' ),
				'attributesRemove' => __( 'Remove', 'wp-taxonomy-tree' ),
				'attributesRemoveConfirm' => __( 'Remove this attribute?', 'wp-taxonomy-tree' ),
				'warnStructuralModelChange' => __(
					'This structural change creates a new model generation and may cause data conflicts with existing Model Data instances. Continue?',
					'wp-taxonomy-tree'
				),
				'dialogOnValidationWarnings' => __(
					'Validation warnings are present. Continue anyway?',
					'wp-taxonomy-tree'
				),
				'modelVersionConflictBadge' => __(
					'Model version conflicts — open Conflict resolver',
					'wp-taxonomy-tree'
				),
				/* translators: %d: number of conflicting instances */
				'modelVersionConflictCount' => __(
					'%d model version conflicts — open Conflict resolver',
					'wp-taxonomy-tree'
				),
				'attributesMoveToParent' => __( 'Move to parent', 'wp-taxonomy-tree' ),
				'attributesMoveToParentConfirm' => __( 'Move this attribute to the parent node?', 'wp-taxonomy-tree' ),
				'attributesMoveToParentHint' => __( 'Move this own attribute to the parent node. It will then be inherited here along child_of.', 'wp-taxonomy-tree' ),
				'attributesMoveToChild' => __( 'Move to child', 'wp-taxonomy-tree' ),
				'attributesMoveToChildHint' => __( 'Move this own attribute to a direct hierarchy child. Choose the child first.', 'wp-taxonomy-tree' ),
				'attributesMoveToChildPick' => __( 'Choose child for attribute', 'wp-taxonomy-tree' ),
				'attributesMoveToChildEmpty' => __( 'No eligible children to move this attribute to.', 'wp-taxonomy-tree' ),
				'attributesUntyped' => __( 'not typed', 'wp-taxonomy-tree' ),
				'attributesInheritedFrom' => __( 'Inherited from %s', 'wp-taxonomy-tree' ),
				'attributesShadowsTitle' => __( 'Shadows inherited', 'wp-taxonomy-tree' ),
				/* translators: %s: ancestor host name (e.g. Passiv) */
				'attributesShadowsHint' => __( 'Local copy hides the inherited “%s” definition. Remove this attribute to use inheritance from the parent.', 'wp-taxonomy-tree' ),
				'attributesShadowsBanner' => __( 'Some local attributes shadow inherited ones (same name). Remove the local copy to inherit from the parent — keep local only when the field is specialization-specific.', 'wp-taxonomy-tree' ),
				'attributesValidationBanner' => __( 'Attribute rules need attention.', 'wp-taxonomy-tree' ),
				'attributesReadonlyNeedsDefaultBanner' => __( 'Read-only attributes need a default value.', 'wp-taxonomy-tree' ),
				/* translators: %s: attribute name */
				'attributesReadonlyNeedsDefaultError' => __( '“%s” is read-only but has no default value.', 'wp-taxonomy-tree' ),
				'attributesBackgroundOnlyMultBanner' => __( 'Background-only (Hide) requires multiplicity 0..1 or 1.', 'wp-taxonomy-tree' ),
				/* translators: %s: attribute name */
				'attributesBackgroundOnlyMultError' => __( '“%s” is Background-only (Hide) but multiplicity is not 0..1 or 1.', 'wp-taxonomy-tree' ),
				'attributesFixClearReadonly' => __( 'Clear read-only', 'wp-taxonomy-tree' ),
				'attributesFixSetDefault' => __( 'Set default value', 'wp-taxonomy-tree' ),
				'attributesFixSetMult01' => __( 'Set multiplicity to 0..1', 'wp-taxonomy-tree' ),
				'attributesFixClearHide' => __( 'Clear Hide (Background-only)', 'wp-taxonomy-tree' ),
				'attributesHide' => __( 'Hide', 'wp-taxonomy-tree' ),
				'attributesShow' => __( 'Show', 'wp-taxonomy-tree' ),
				'attributesHidden' => __( 'hidden', 'wp-taxonomy-tree' ),
				'attributesHideHint' => __( 'Hide this inherited attribute on this node (does not delete the parent definition). Default: off (visible).', 'wp-taxonomy-tree' ),
				'attributesHideBoHint' => __( 'Background-only: hide from user forms (requires Mult 0..1 or 1). Stored on the Relation edge.', 'wp-taxonomy-tree' ),
				'attributesHideOwnHint' => __( 'Background-only (Hide) on own attributes requires multiplicity 0..1 or 1. Default: off.', 'wp-taxonomy-tree' ),
				'attributesFixedNone' => __( 'No default value', 'wp-taxonomy-tree' ),
				'attributesFixedEdit' => __( 'Choose default…', 'wp-taxonomy-tree' ),
				'attributesFixedClear' => __( 'Clear', 'wp-taxonomy-tree' ),
				'attributesFixedApply' => __( 'Apply', 'wp-taxonomy-tree' ),
				'attributesFixedRequired' => __( 'At least one value is required for this multiplicity.', 'wp-taxonomy-tree' ),
				'attributesFixedAddValue' => __( 'Add value', 'wp-taxonomy-tree' ),
				'attributesFixedHint' => __( 'Default value(s). Own attrs: Relation edge seed. Inherited attrs: host-local name override (does not change the parent). Catalog types: pick from the type tree. Multiplicity controls how many values.', 'wp-taxonomy-tree' ),
				'attributesFixedEmpty' => __( 'This type has no selectable values yet.', 'wp-taxonomy-tree' ),
				'attributesDisplayNodeNameNoDefault' => __( 'This attribute always shows a host presentation field. A default value is not used.', 'wp-taxonomy-tree' ),
				'attributesDuplicate' => __( 'Duplicate', 'wp-taxonomy-tree' ),
				'attributesOptions' => __( 'Options', 'wp-taxonomy-tree' ),
				'attributesOptionsConfigured' => __( 'Settings', 'wp-taxonomy-tree' ),
				'attributesSettingsWalk' => __( 'Settings', 'wp-taxonomy-tree' ),
				/* translators: %d: number of nodes visited by Settings walk. */
				'attributesSettingsWalkNodes' => __( 'Settings walk: %d nodes', 'wp-taxonomy-tree' ),
				'attributesSettingsWalkGo'    => __( 'Select this type node in the tree', 'wp-taxonomy-tree' ),
				'attributesSettingsWalkEdit'  => __( 'Open type node', 'wp-taxonomy-tree' ),
				'attributesSettingsWalkHint'  => __( 'Same Settings walk as the type node (Attribut-Walk): Preferred / converter / Default / validators / Choices / prefix allowlist per level to the leaf. Nested rows can override RO / Hide via Settings.data. Depth-0 Default → edge.default; nested → settings.nested[path].data.default. Reset deletes the override. Open type node (↗) is secondary.', 'wp-taxonomy-tree' ),
				'attributesAllowedPrefixesOverrideHint' => __( 'Relation override: restricts prefixes for this attribute (intersected with each unit’s catalog marriage). Empty = value + unit only.', 'wp-taxonomy-tree' ),
				'attributesAllowedPrefixesUnitDefault' => __( 'Type default: unit catalog prefix marriage. Change to override on this attribute Relation.', 'wp-taxonomy-tree' ),
				'attributesAllowedPrefixesWithPrefixDefault' => __( 'Type default: each unit keeps its catalog prefix marriage. Override to restrict prefixes for this attribute.', 'wp-taxonomy-tree' ),
				'attributesAllowedPrefixesShort' => __( 'Pref', 'wp-taxonomy-tree' ),
				'attributesSettingsWalkCycle' => __( 'Cycle stopped — no further walk.', 'wp-taxonomy-tree' ),
				'attributesPreferredSource'   => __( 'Preferred override: %s', 'wp-taxonomy-tree' ),
				'attributesRelationOverrides' => __( 'Relation overrides', 'wp-taxonomy-tree' ),
				'attributesRelationOverridesHint' => __( 'Hybrid Settings.view / Settings.data deltas on this attribute Relation (depth 0). Nested walk levels below use the same law with path-keyed deltas. Reset deletes the delta key. Host Preview still follows this node’s Preferred (Settings → Preferred), not these Relation Render overrides.', 'wp-taxonomy-tree' ),
				'attributesRelationOverridesInheritedHint' => __( 'Overrides on this child host only (Settings deltas stored on the heir). The parent Relation edge is not mutated. Reset deletes the heir override. Host Preview still follows this node’s Preferred (Settings → Preferred).', 'wp-taxonomy-tree' ),
				'attributesRelationOverrideBadge' => __( 'Relation override', 'wp-taxonomy-tree' ),
				'attributesRelationOverrideReset' => __( 'Reset override', 'wp-taxonomy-tree' ),
				'attributesRelationOverrideResetHint' => __( 'Delete this Relation Settings delta key and inherit the type default.', 'wp-taxonomy-tree' ),
				'attributesDateMode' => __( 'Date mode', 'wp-taxonomy-tree' ),
				'attributesDateModeShort' => __( 'Date', 'wp-taxonomy-tree' ),
				'attributesDateModeDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'attributesChoiceFilter' => __( 'Choices', 'wp-taxonomy-tree' ),
				'attributesChoiceInclude' => __( 'Include', 'wp-taxonomy-tree' ),
				'attributesChoiceExclude' => __( 'Exclude', 'wp-taxonomy-tree' ),
				'attributesChoiceFilterHint' => __( 'All choices start enabled. Uncheck a node to exclude it (and its subtree).', 'wp-taxonomy-tree' ),
				'attributesChoiceFilterDeferHint' => __( 'Tick freely — choices save when you leave this list.', 'wp-taxonomy-tree' ),
				'attributesChoiceEmpty' => __( 'No specialization children under this type.', 'wp-taxonomy-tree' ),
				'attributesValidators' => __( 'Validators', 'wp-taxonomy-tree' ),
				'attributesValidatorsShort' => __( 'Val', 'wp-taxonomy-tree' ),
				'attributesValidatorsDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'attributesValidatorsReset' => __( 'Reset override', 'wp-taxonomy-tree' ),
				'attributesValidatorsHint' => __( 'Relation override for validators (Settings.data). Reset deletes the delta key; empty uses the type list.', 'wp-taxonomy-tree' ),
				'attributesCompute' => __( 'Compute', 'wp-taxonomy-tree' ),
				'attributesComputeOff' => __( 'Off', 'wp-taxonomy-tree' ),
				'attributesComputeHint' => __( 'Aggregate over a flat list of source values (sum/avg/min/max/count). Computed fields are read-only.', 'wp-taxonomy-tree' ),
				'attributesComputePickSource' => __( 'Add source…', 'wp-taxonomy-tree' ),
				'attributesComputePickPath' => __( 'Attribute on linked type…', 'wp-taxonomy-tree' ),
				'attributesComputeRemoveSource' => __( 'Remove source', 'wp-taxonomy-tree' ),
				'attributesComputeNoPathAttrs' => __( 'No attributes on linked type (open that type once to load its attributes).', 'wp-taxonomy-tree' ),
				'attributesComputedRoHint' => __( 'Computed attributes are always read-only.', 'wp-taxonomy-tree' ),
				'soleSelectLockedHint' => __( 'Only one choice — selected automatically.', 'wp-taxonomy-tree' ),
				'preferredRender' => __( 'Preferred render', 'wp-taxonomy-tree' ),
				'preferredRenderHint' => __( 'Default layout for admin preview and Object View (when the block uses Node preferred). Multistep = pick a child/kind, then fill/filter its attributes (not a type). Mode dialog|inline is renderer-local — not Settings treePickerMode.', 'wp-taxonomy-tree' ),
				'preferredChrome' => __( 'Preferred', 'wp-taxonomy-tree' ),
				'preferredChromeHint' => __( 'Render = how the node is painted; converter = value transform; validators = value checks. Only options that apply to this type.', 'wp-taxonomy-tree' ),
				'preferredRenderShort' => __( 'Render', 'wp-taxonomy-tree' ),
				'preferredRenderInherit' => __( 'Inherit from parent', 'wp-taxonomy-tree' ),
				'preferredRenderInheritedBadge' => __( 'Inherited', 'wp-taxonomy-tree' ),
				'preferredRenderInheritHint' => __( 'When unset, Preferred render walks the child_of parent chain. Choose a concrete layout to override on this node.', 'wp-taxonomy-tree' ),
				'preferredConverterShort' => __( 'Converter', 'wp-taxonomy-tree' ),
				'preferredRenderForm' => __( 'Form', 'wp-taxonomy-tree' ),
				'preferredRenderTable' => __( 'Table', 'wp-taxonomy-tree' ),
				'preferredRenderCompact' => __( 'Compact (horizontal)', 'wp-taxonomy-tree' ),
				'preferredRenderCompactVertical' => __( 'Compact (vertical)', 'wp-taxonomy-tree' ),
				'preferredRenderEmbed' => __( 'Multistep', 'wp-taxonomy-tree' ),
				'preferredRenderMultistep' => __( 'Multistep', 'wp-taxonomy-tree' ),
				'preferredRenderEmbedHint' => __( 'Choose a child/kind, then filter existing Model data or create and bind (id only). Dialog = popup Phase A/B; Inline = step 1 and step 2 side by side. Not the same as Settings treePickerMode.', 'wp-taxonomy-tree' ),
				'multistepMode' => __( 'Multistep mode', 'wp-taxonomy-tree' ),
				'multistepModeHint' => __( 'Dialog opens Phase A (kind) then Phase B (filter/create) in a popup. Inline shows step 1 and step 2 in a horizontal strip. Renderer-local — not global tree picker mode.', 'wp-taxonomy-tree' ),
				'compactShowLabels' => __( 'Show field labels', 'wp-taxonomy-tree' ),
				'compactShowLabelsHint' => __( 'When off, Compact paints values only (no Praefix / Kuerzel captions).', 'wp-taxonomy-tree' ),
				'compactOptionsHint' => __( 'Choose Compact (horizontal) or Compact (vertical) as Preferred. Use Show field labels for captions vs dense value-only chrome.', 'wp-taxonomy-tree' ),
				'multistepModeDialog' => __( 'Dialog', 'wp-taxonomy-tree' ),
				'multistepModeInline' => __( 'Inline', 'wp-taxonomy-tree' ),
				'preferredRenderChildList' => __( 'Child list', 'wp-taxonomy-tree' ),
				'preferredRenderChildListHint' => __( 'List field of this node’s hierarchy children (default for Konstanten catalogs with children, e.g. Präfixe).', 'wp-taxonomy-tree' ),
				'preferredRenderMedia' => __( 'MediaRenderer', 'wp-taxonomy-tree' ),
				'previewChildListHint' => __( 'Child list: pick among children of this node (same list/tree chooser as CatalogChoice).', 'wp-taxonomy-tree' ),
				'preferredConverter' => __( 'Preferred converter', 'wp-taxonomy-tree' ),
				'preferredConverterHint' => __( 'Only converters that apply to this node’s type. Used when painting display values (e.g. int number formats).', 'wp-taxonomy-tree' ),
				'preferredConverterNone' => __( 'None (no converters for this type)', 'wp-taxonomy-tree' ),
				'preferredConverterNoneShort' => __( 'None', 'wp-taxonomy-tree' ),
				'validators' => __( 'Validators', 'wp-taxonomy-tree' ),
				'validatorsHint' => __( '0..n value checks for this type. A type default is always included; add more (including Expression). Each needs an error text; optional fixes when available.', 'wp-taxonomy-tree' ),
				'validatorsEmptyHint' => __( 'No validators yet — use Add validator to add one.', 'wp-taxonomy-tree' ),
				'validatorColId' => __( 'Validator', 'wp-taxonomy-tree' ),
				'validatorColBound' => __( 'Bound', 'wp-taxonomy-tree' ),
				'validatorBoundHint' => __( 'Threshold for min/max/length, or charset spec (range / allowlist / regex) in params.value.', 'wp-taxonomy-tree' ),
				'validatorCharsetRangeHint' => __( 'Codepoint ranges: a-z, A-Z, 0-9, or U+0041-U+005A. Comma-separated for several ranges.', 'wp-taxonomy-tree' ),
				'validatorCharsetAllowlistHint' => __( 'Comma-separated allowed characters. Use \\, for a literal comma.', 'wp-taxonomy-tree' ),
				'validatorCharsetRegexHint' => __( 'Regex matched against the whole value (auto-anchored), e.g. [0-9a-z] or [0-9]|[a-z].', 'wp-taxonomy-tree' ),
				'validatorColError' => __( 'Error text', 'wp-taxonomy-tree' ),
				'validatorColExpression' => __( 'Expression', 'wp-taxonomy-tree' ),
				'validatorColFix' => __( 'Fix', 'wp-taxonomy-tree' ),
				'validatorColActions' => __( 'Actions', 'wp-taxonomy-tree' ),
				'validatorAdd' => __( 'Add validator', 'wp-taxonomy-tree' ),
				'validatorExpressionHint' => __( 'Use `value` in a boolean expression, e.g. value >= 0 && value <= 100', 'wp-taxonomy-tree' ),
				'validatorFixPlaceholder' => __( 'Optional fix label', 'wp-taxonomy-tree' ),
				'validatorFixHint' => __( 'Optional fix label (shown when validation fails). Fixes are never auto-run.', 'wp-taxonomy-tree' ),
				'validatorDefaultBadge' => __( 'Default', 'wp-taxonomy-tree' ),
				'attributesPreferredConverterDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'previewPreferredOnlyHint' => __( 'Preview surface = this node’s Preferred (Editable + Display). Nested attribute fields still use their walk / Relation Render (e.g. Kontakt → Table).', 'wp-taxonomy-tree' ),
				'previewEmbed' => __( 'Multistep', 'wp-taxonomy-tree' ),
				'previewMultistep' => __( 'Multistep', 'wp-taxonomy-tree' ),
				'embedPickHint' => __( 'Choose kind…', 'wp-taxonomy-tree' ),
				'embedNoChoices' => __( 'No specialization children under this node.', 'wp-taxonomy-tree' ),
				'embedLoading' => __( 'Loading…', 'wp-taxonomy-tree' ),
				'embedNoFields' => __( 'Selected node has no attributes.', 'wp-taxonomy-tree' ),
				'enumValuesTitle' => __( 'Enum values', 'wp-taxonomy-tree' ),
				'enumValuesHint'  => __( 'Closed Festwerte for this concrete enum. Stored as option leaves under an Option column. Attributes typed as this enum pick from this list.', 'wp-taxonomy-tree' ),
				'enumValuesEmpty' => __( 'No values yet. Add the allowed Festwerte for this enum.', 'wp-taxonomy-tree' ),
				'enumValuesAdd'   => __( 'Add value', 'wp-taxonomy-tree' ),
				'enumValuesSave'  => __( 'Save enum values', 'wp-taxonomy-tree' ),
				'enumValuesValue' => __( 'Value', 'wp-taxonomy-tree' ),
				'enumValuesActions' => __( 'Actions', 'wp-taxonomy-tree' ),
				'enumValuesRemove' => __( 'Remove', 'wp-taxonomy-tree' ),
				'enumValuesMoveUp' => __( 'Move up', 'wp-taxonomy-tree' ),
				'enumValuesMoveDown' => __( 'Move down', 'wp-taxonomy-tree' ),
				'enumValuesDuplicate' => __( 'Duplicate values are ignored.', 'wp-taxonomy-tree' ),
				'enumValuesSaving' => __( 'Saving…', 'wp-taxonomy-tree' ),
				'relationsHelp'   => __( 'Always From node → Relation type → To node. The current node is shown by name (not a link); hover for the hint.', 'wp-taxonomy-tree' ),
				'relationsVon'    => __( 'Relations von', 'wp-taxonomy-tree' ),
				'relationsAn'     => __( 'Relations an', 'wp-taxonomy-tree' ),
				'relationsFrom'   => __( 'From', 'wp-taxonomy-tree' ),
				'relationsTo'     => __( 'To', 'wp-taxonomy-tree' ),
				'relationsThisHint' => __( 'Current node (this endpoint of the relation)', 'wp-taxonomy-tree' ),
				'relationsHint'   => __( 'Format: node → relation type → node. Name = attribute label on besteht_aus / aggregation (same as Attributes → Name). Click To to change the target (except child_of — use Reparent). Mult. = definition multiplicity.', 'wp-taxonomy-tree' ),
				'relationsEmpty'  => __( 'None', 'wp-taxonomy-tree' ),
				'relationsType'   => __( 'Relation type', 'wp-taxonomy-tree' ),
				'relationsTypeHint' => __( 'Relation type (e.g. composition) — not a Node. Not for child_of.', 'wp-taxonomy-tree' ),
				'relationsName'   => __( 'Name', 'wp-taxonomy-tree' ),
				'relationsNameHint' => __( 'Attribute label (Relation.name) for besteht_aus / aggregation. Same field as Attributes → Name.', 'wp-taxonomy-tree' ),
				'relationsNameOptionalHint' => __( 'Name is optional for this relation type', 'wp-taxonomy-tree' ),
				'relationsNameRequired' => __( 'Attribute relations (besteht_aus / aggregation) require a name.', 'wp-taxonomy-tree' ),
				'relationsNamePrompt' => __( 'Attribute name (Relation.name)', 'wp-taxonomy-tree' ),
				'relationsParkedBandBadge' => __( 'Q90 parked', 'wp-taxonomy-tree' ),
				'relationsParkedBandHint' => __( 'Legacy table band (Zeile/Kopf/Fuss) — kept for scaffold table chrome; not a product attribute. Hidden from Attributes.', 'wp-taxonomy-tree' ),
				'relationsTarget' => __( 'To', 'wp-taxonomy-tree' ),
				'relationsSource' => __( 'From', 'wp-taxonomy-tree' ),
				'relationsNotes'  => __( 'Notes', 'wp-taxonomy-tree' ),
				'relationsMult'   => __( 'Mult.', 'wp-taxonomy-tree' ),
				'relationsMultHint' => __( 'Definition multiplicity: lower bound 0 or 1; upper bound 1 or * (many).', 'wp-taxonomy-tree' ),
				'relationsProtected' => __( 'protected — reparent only', 'wp-taxonomy-tree' ),
				'relationsRefScopeNote' => __( 'Catalog root — click To to change', 'wp-taxonomy-tree' ),
				'relationsAdd'    => __( 'Add relation', 'wp-taxonomy-tree' ),
				'relationsHideChildOf' => __( 'Hide child_of', 'wp-taxonomy-tree' ),
				'relationsRemove' => __( 'Remove', 'wp-taxonomy-tree' ),
				'relationsRemoveConfirm' => __( 'Remove this relation?', 'wp-taxonomy-tree' ),
				'relationsRemoveChildOfConfirm' => __( 'Remove child_of (move this node to the root)? Development mode only.', 'wp-taxonomy-tree' ),
				'relationsDuplicate' => __( 'Duplicate', 'wp-taxonomy-tree' ),
				'relationsDuplicateAsType' => __( 'Same connection, other relation type', 'wp-taxonomy-tree' ),
				'relationsDuplicatePickType' => __( 'Choose relation type for the same connection', 'wp-taxonomy-tree' ),
				'relationsDuplicateExists' => __( 'This relation already exists (same From, Relation type, and To).', 'wp-taxonomy-tree' ),
				'relationsMoveUp' => __( 'Move up', 'wp-taxonomy-tree' ),
				'relationsMoveDown' => __( 'Move down', 'wp-taxonomy-tree' ),
				'relationsPickType' => __( 'Choose relation type', 'wp-taxonomy-tree' ),
				'relationTypeCalc' => __( 'Calculation', 'wp-taxonomy-tree' ),
				'relationsCalcNameRequired' => __( 'Calculation requires a name (consumer attribute for default_from).', 'wp-taxonomy-tree' ),
				'relationsDefaultValueFromNameRequired' => __( 'Calculation requires a name (consumer attribute for default_from).', 'wp-taxonomy-tree' ),
				'relationsCalcNameHint' => __( 'Consumer attribute name (calc default_from)', 'wp-taxonomy-tree' ),
				'relationsPickTarget' => __( 'Choose target node', 'wp-taxonomy-tree' ),
				'relationsChangeTarget' => __( 'Change target node', 'wp-taxonomy-tree' ),
				'relationsNoTypes'=> __( 'No Relationstypen found. Reload the page or reset the demo tree.', 'wp-taxonomy-tree' ),
				'relationsStored' => __( 'stored', 'wp-taxonomy-tree' ),
				'required'        => __( 'Required', 'wp-taxonomy-tree' ),
				'optional'        => __( 'Optional', 'wp-taxonomy-tree' ),
				'requiredHint'    => __( 'Fill rule for this slot / set member (not part of the data type itself).', 'wp-taxonomy-tree' ),
				'footerOp'        => __( 'Aggregate', 'wp-taxonomy-tree' ),
				'footerOpHint'    => __( 'Fuss cell operation for this column (aligned with Zeile by index). Type stays the value type; the op lives on the Fuss slot.', 'wp-taxonomy-tree' ),
				'tableTypePreviewHint' => __( 'Table type — preview as table only (tree/form not applicable).', 'wp-taxonomy-tree' ),
				'tableInstancePreviewHint' => __( 'Table preview from Kopf / Zeile / Fuss bindings (type properties).', 'wp-taxonomy-tree' ),
				'tableValidationTitle' => __( 'Table validation', 'wp-taxonomy-tree' ),
				'tableValidationFailed' => __( 'Table preview unavailable until the definition is valid.', 'wp-taxonomy-tree' ),
				'tableValidationBanner' => __( 'Table rule failed', 'wp-taxonomy-tree' ),
				'tableTreeInvalid' => __( 'Table definition invalid', 'wp-taxonomy-tree' ),
				'tableFixCreateFields' => __( 'Create missing fields', 'wp-taxonomy-tree' ),
				'tableFixCreateFieldsHint' => __( 'Add child fields under the bound band so the count matches Zeile (names from Zeile columns).', 'wp-taxonomy-tree' ),
				'tableFixCreateZeile' => __( 'Create Zeile', 'wp-taxonomy-tree' ),
				'tableFixCreateZeileField' => __( 'Create Zeile field', 'wp-taxonomy-tree' ),
				'tableFixAllBands' => __( 'Fix all bands', 'wp-taxonomy-tree' ),
				'tableFixCreated' => __( 'Created %d field(s).', 'wp-taxonomy-tree' ),
				'setSettings'     => __( 'Set settings', 'wp-taxonomy-tree' ),
				'setSeparator'    => __( 'Separator', 'wp-taxonomy-tree' ),
				'setSeparatorHint'=> __( 'Between member names in the label and between values in display (e.g. L/B/H or 10.5/20/5mm).', 'wp-taxonomy-tree' ),
				'setJoinUnits'    => __( 'Join units', 'wp-taxonomy-tree' ),
				'setJoinUnitsHint'=> __( 'When all members share the same type with Praefix, choose Praefix once for all and show values with the separator (e.g. 10.5/20/5mm).', 'wp-taxonomy-tree' ),
				'setJoinUnitsUnavailable' => __( 'Takes effect in preview when every set member has the same data type.', 'wp-taxonomy-tree' ),
				'setJoinUnitsNoPrefix' => __( 'Takes effect in preview when that shared type includes a Praefix.', 'wp-taxonomy-tree' ),
				'setLabelChildren'=> __( 'Include composition in label', 'wp-taxonomy-tree' ),
				'setLabelChildrenHint' => __( 'When on, labels show composition member names after the set name (e.g. Abmessung (L/B/H)). Used in Form and Table previews.', 'wp-taxonomy-tree' ),
				'fixedValue'      => __( 'Default value', 'wp-taxonomy-tree' ),
				'fixedValueNone'  => __( 'No default', 'wp-taxonomy-tree' ),
				'fixedValueOff'   => __( 'No default value', 'wp-taxonomy-tree' ),
				'fixedValueOn'    => __( 'Use default value', 'wp-taxonomy-tree' ),
				'fixedValueChoose'=> __( 'Choose node', 'wp-taxonomy-tree' ),
				'fixedValueHint'  => __( 'Optional default seed for this node (not a lock). Builtin Simples are grayed; specializations and typed fields may set a default. Attribute instance defaults stay in the Attributes panel.', 'wp-taxonomy-tree' ),
				'fixedLiteralHint'=> __( 'Simple types: enter the default (e.g. 10 for double). Empty is not allowed while “Use default value” is selected.', 'wp-taxonomy-tree' ),
				'fixedLiteralPlaceholder' => __( 'Default value…', 'wp-taxonomy-tree' ),
				'fixedCatalogHint'=> __( 'Catalog types: pick a Typen node (e.g. Einheit → Ohm).', 'wp-taxonomy-tree' ),
				'fixedValueUnavailable' => __( 'Default value is available for specializations and typed fields after you choose a data type. Builtin Simples are grayed.', 'wp-taxonomy-tree' ),
				'boolTrue'        => __( 'true', 'wp-taxonomy-tree' ),
				'boolFalse'       => __( 'false', 'wp-taxonomy-tree' ),
				'emailInvalid'    => __( 'Enter a valid email address.', 'wp-taxonomy-tree' ),
				'intInvalid'      => __( 'Enter a whole number.', 'wp-taxonomy-tree' ),
				'intFormatArabic' => __( 'Arabic (decimal)', 'wp-taxonomy-tree' ),
				'intFormatRoman'  => __( 'Roman', 'wp-taxonomy-tree' ),
				'intFormatBinary' => __( 'Binary', 'wp-taxonomy-tree' ),
				'intFormatOctal'  => __( 'Octal', 'wp-taxonomy-tree' ),
				'intFormatHex'    => __( 'Hexadecimal', 'wp-taxonomy-tree' ),
				'charFormatGlyph' => __( 'Character (glyph)', 'wp-taxonomy-tree' ),
				'charFormatAscii' => __( 'ASCII', 'wp-taxonomy-tree' ),
				'charFormatUnicode' => __( 'Unicode (U+)', 'wp-taxonomy-tree' ),
				'attributesIntFormat' => __( 'Preferred converter', 'wp-taxonomy-tree' ),
				'attributesIntFormatDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'attributesPreferredRenderDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'attributesPreferredConverterDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'dateSettings'    => __( 'Date settings', 'wp-taxonomy-tree' ),
				'dateMode'        => __( 'Mode', 'wp-taxonomy-tree' ),
				'dateModeDate'    => __( 'Date only', 'wp-taxonomy-tree' ),
				'dateModeDateTime'=> __( 'Date and time', 'wp-taxonomy-tree' ),
				'dateModeHint'    => __( 'Choose date-only or date+time. Instance values are stored as Unix timestamps (site timezone for controls).', 'wp-taxonomy-tree' ),
				'textareaSettings'=> __( 'Textarea settings', 'wp-taxonomy-tree' ),
				'textareaLayoutHint' => __( 'Columns = characters per line; lines = visible rows in the editor.', 'wp-taxonomy-tree' ),
				'textareaCols'    => __( 'Columns (chars/line)', 'wp-taxonomy-tree' ),
				'textareaRows'    => __( 'Lines (rows)', 'wp-taxonomy-tree' ),
				'attributesTextareaDefault' => __( 'Type default', 'wp-taxonomy-tree' ),
				'displayNodeNameHint' => __( 'This type always shows the node name — no fixed value and no user input.', 'wp-taxonomy-tree' ),
				'mediaSettings'   => __( 'Media settings', 'wp-taxonomy-tree' ),
				'mediaAllowUpload'=> __( 'Allow Media Library', 'wp-taxonomy-tree' ),
				'mediaAllowUploadHint' => __( 'Pick or upload via the WordPress Media Library (stores attachment id).', 'wp-taxonomy-tree' ),
				'mediaAllowUrl'   => __( 'Allow external URL', 'wp-taxonomy-tree' ),
				'mediaAllowUrlHint'=> __( 'Optional: paste an external URL instead of (or in addition to) the Media Library.', 'wp-taxonomy-tree' ),
				'mediaSelect'     => __( 'Select media', 'wp-taxonomy-tree' ),
				'mediaChange'     => __( 'Change', 'wp-taxonomy-tree' ),
				'mediaClear'      => __( 'Clear', 'wp-taxonomy-tree' ),
				'mediaUrlPlaceholder' => __( 'https://…', 'wp-taxonomy-tree' ),
				'mediaFrameTitle' => __( 'Select media', 'wp-taxonomy-tree' ),
				'mediaFrameButton'=> __( 'Use this file', 'wp-taxonomy-tree' ),
				'helpShowDescription' => __( 'Show description', 'wp-taxonomy-tree' ),
				'helpChildProperties' => __( 'Child properties', 'wp-taxonomy-tree' ),
				'typeBranch'      => __( 'Type branch', 'wp-taxonomy-tree' ),
				'typeBranchHint'  => __( 'Direct children of the selected type. Uncheck values that do not apply (e.g. kilo-Farad).', 'wp-taxonomy-tree' ),
				'typeBranchEnabled' => __( 'Enabled', 'wp-taxonomy-tree' ),
				'prefixFilteredByUnit' => __( 'Filtered by Basiseinheit allowlist', 'wp-taxonomy-tree' ),
				'praefixChildSettings' => __( 'Praefix (allowed + conversion)', 'wp-taxonomy-tree' ),
				'praefixChildSettingsHint' => __( 'Enable prefixes for this unit and enter each factor vs the prefix root. to_si = Typ × factor × unit root factor. Factor is stored on the Praefix catalog node (shared). Empty allowlist = no prefixes.', 'wp-taxonomy-tree' ),
				'childExtras' => __( 'Child extras', 'wp-taxonomy-tree' ),
				'childExtrasHint' => __( 'Extras for set members (type, required, fixed, prefix conversion). Name and description stay on the child node.', 'wp-taxonomy-tree' ),
				'childExtrasOnParent' => __( 'Same prefix allowlist and conversion as on this Praefix node — also shown under the parent unit (Child extras).', 'wp-taxonomy-tree' ),
				'multiplikatorPlaceholder' => __( 'e.g. 0.001', 'wp-taxonomy-tree' ),
				'multiplikatorHint' => __( 'Factor vs prefix root (milli = 0.001, kilo = 1000).', 'wp-taxonomy-tree' ),
				'prefixRootToSi' => __( 'Unit: prefix root → SI base', 'wp-taxonomy-tree' ),
				'prefixRootToSiHint' => __( 'Usually 1. Kilogramm uses 0.001 (gram → kilogram).', 'wp-taxonomy-tree' ),
				'unitDisplayLabel' => __( 'Unit label', 'wp-taxonomy-tree' ),
				'unitConversions' => __( 'Conversions', 'wp-taxonomy-tree' ),
				'unitConversionsHint' => __( 'to_si = Typ × multiplikator × prefix_root_to_si. Factors come from the Praefix catalog; prefix_root_to_si is on this unit.', 'wp-taxonomy-tree' ),
				'unitConvPrefix'  => __( 'Praefix', 'wp-taxonomy-tree' ),
				'unitConvSymbol'  => __( 'Symbol', 'wp-taxonomy-tree' ),
				'unitConvFactor'  => __( '× factor', 'wp-taxonomy-tree' ),
				'unitConvToSi'    => __( '1 → SI', 'wp-taxonomy-tree' ),
				'unitConvSample'  => __( '10.5 → SI', 'wp-taxonomy-tree' ),
				'catalogChoiceNone' => __( '—', 'wp-taxonomy-tree' ),
				'unitConvNone'    => __( '—', 'wp-taxonomy-tree' ),
				'unitConvNoneTitle' => __( 'No prefix', 'wp-taxonomy-tree' ),
				'allowedPrefixesTitle' => __( 'Allowed prefixes', 'wp-taxonomy-tree' ),
				'allowedPrefixesHint' => __( 'Which SI prefixes this unit may use (catalog marriage). Empty = value + unit only, no prefix. Factors live on each Präfix node.', 'wp-taxonomy-tree' ),
				'allowedPrefixesMissing' => __( 'This unit has no editable prefix slot yet (typical for Without-prefix catalog units).', 'wp-taxonomy-tree' ),
				'setPreview'      => __( 'Preview', 'wp-taxonomy-tree' ),
				'unifiedPreviewHint' => __( 'Preferred render only — editable above, display mirror below (same fields).', 'wp-taxonomy-tree' ),
				'previewSchema'   => __( 'Definition', 'wp-taxonomy-tree' ),
				'unitSchemaHint'  => __( 'Unit schema only — not an instance. Kuerzel is the unit symbol (Meter → m). Praefix catalog “m” is Milli — same letter, different node.', 'wp-taxonomy-tree' ),
				'unitUsageHint'   => __( 'Usage sample when a field uses this unit (value + prefix + symbol). Sample often picks milli → e.g. 10.5mm.', 'wp-taxonomy-tree' ),
				'previewAsForm'   => __( 'Form', 'wp-taxonomy-tree' ),
				'previewAsTable'  => __( 'Table', 'wp-taxonomy-tree' ),
				'previewAsCompact'=> __( 'Compact', 'wp-taxonomy-tree' ),
				'previewCompactHorizontal' => __( 'Horizontal', 'wp-taxonomy-tree' ),
				'previewCompactVertical' => __( 'Vertical', 'wp-taxonomy-tree' ),
				'previewAsTree'   => __( 'Tree', 'wp-taxonomy-tree' ),
				'nodeRenderPreviewHint' => __( 'Rendered via NodeRendererRegistry — host Preferred surface only (same path for admin preview and future frontend).', 'wp-taxonomy-tree' ),
				'nrTreeSiblingBefore' => __( 'Sample A', 'wp-taxonomy-tree' ),
				'nrTreeSiblingAfter' => __( 'Sample C', 'wp-taxonomy-tree' ),
				'nrFormRowBefore' => __( 'Name', 'wp-taxonomy-tree' ),
				'nrFormRowAfter'  => __( 'Notes', 'wp-taxonomy-tree' ),
				'nrTableColA'     => __( 'Column A', 'wp-taxonomy-tree' ),
				'nrTableColB'     => __( 'Column B', 'wp-taxonomy-tree' ),
				'nrTableSampleA'  => __( '…', 'wp-taxonomy-tree' ),
				'nrTableSampleB'  => __( '…', 'wp-taxonomy-tree' ),
				'previewEditable' => __( 'Editable', 'wp-taxonomy-tree' ),
				'previewDisplayOnly' => __( 'Display only', 'wp-taxonomy-tree' ),
				'setTableCellHint'=> __( 'Compact set as one table field', 'wp-taxonomy-tree' ),
				'previewUnavailable'=> __( 'Preview nicht möglich', 'wp-taxonomy-tree' ),
				'previewRebuildEmpty'=> __( 'Preview rebuild — add attributes to see Form, Table, and Compact samples.', 'wp-taxonomy-tree' ),
				'quantityCatalogPreviewHint'=> __( 'Quantity uses the Quantity renderer: compact magnitude + SI prefix symbol + unit (example follows Preis when present).', 'wp-taxonomy-tree' ),
				'quantityExampleHost'=> __( 'Preis', 'wp-taxonomy-tree' ),
				'previewQuantity' => __( 'Quantity', 'wp-taxonomy-tree' ),
				'previewUnit'     => __( 'Unit', 'wp-taxonomy-tree' ),
				'previewChoiceCatalogHint'=> __( 'CatalogChoice (depth ≥ 2): tree chooser — same control as nested type pickers.', 'wp-taxonomy-tree' ),
				'previewChoiceCatalogListHint'=> __( 'CatalogChoice (depth ≤ 1): list chooser — same control as flat type pickers.', 'wp-taxonomy-tree' ),
				'previewChoiceCatalogEmpty'=> __( 'No child options under this node yet.', 'wp-taxonomy-tree' ),
				'previewChoiceCatalogEmpty'=> __( 'No child options under this node yet.', 'wp-taxonomy-tree' ),
				'previewAttributeHostHint'=> __( 'Form = one filled instance; Table = list of sample instances; Compact = dense H/V strip. Editable samples stay in this session only.', 'wp-taxonomy-tree' ),
				'previewColIndex' => __( '#', 'wp-taxonomy-tree' ),
				'previewColOther' => __( 'Column A', 'wp-taxonomy-tree' ),
				'previewColField' => __( 'Field', 'wp-taxonomy-tree' ),
				'previewColNote'  => __( 'Column B', 'wp-taxonomy-tree' ),
				'previewColGeneric' => __( 'Column', 'wp-taxonomy-tree' ),
				'previewColType'  => __( 'Type', 'wp-taxonomy-tree' ),
				'previewColConstraint' => __( 'Constraint', 'wp-taxonomy-tree' ),
				'previewOptionalPrefix' => __( 'optional (allowlist)', 'wp-taxonomy-tree' ),
				'previewSampleText' => __( 'Sample', 'wp-taxonomy-tree' ),
				'previewSampleTextarea' => __( "Sample text\nSecond line", 'wp-taxonomy-tree' ),
				'previewFooter'   => __( 'Footer', 'wp-taxonomy-tree' ),
				'previewFixed'    => __( 'fixed', 'wp-taxonomy-tree' ),
				'previewFixedSymbol' => __( 'fixed symbol', 'wp-taxonomy-tree' ),
				'scaffoldBadge'   => sprintf(
					/* translators: %s: plugin version */
					__( 'Scaffold %s', 'wp-taxonomy-tree' ),
					WTT_VERSION
				),
			),
		);
		$config['i18n'] = array_merge( Media_Render::i18n(), $config['i18n'] );
		return $config;
	}

	public static function print_inline_css(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		if ( null === self::$boot_config ) {
			self::$boot_config = self::build_config();
		}

		$shared_css = WTT_PLUGIN_DIR . 'assets/css/wtt-media-render.css';
		if ( is_readable( $shared_css ) ) {
			$css = file_get_contents( $shared_css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $css && '' !== $css ) {
				echo "<style id=\"wtt-media-render-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		$object_css = WTT_PLUGIN_DIR . 'assets/css/wtt-object-render.css';
		if ( is_readable( $object_css ) ) {
			$css = file_get_contents( $object_css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $css && '' !== $css ) {
				echo "<style id=\"wtt-object-render-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		$css_abs = WTT_PLUGIN_DIR . 'assets/css/tree-admin.css';
		if ( ! is_readable( $css_abs ) ) {
			return;
		}

		$css = file_get_contents( $css_abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $css || '' === $css ) {
			return;
		}

		echo "<style id=\"wtt-tree-admin-css\">\n" . $css . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function print_inline_js(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		if ( null === self::$boot_config ) {
			self::$boot_config = self::build_config();
		}

		$shared_js      = WTT_PLUGIN_DIR . 'assets/js/wtt-media-render.js';
		$int_js         = WTT_PLUGIN_DIR . 'assets/js/wtt-int-value.js';
		$converter_js   = WTT_PLUGIN_DIR . 'assets/js/wtt-converter.js';
		$value_val_js   = WTT_PLUGIN_DIR . 'assets/js/wtt-validator.js';
		$sample_js      = WTT_PLUGIN_DIR . 'assets/js/wtt-sample-data.js';
		$render_js      = WTT_PLUGIN_DIR . 'assets/js/wtt-node-render.js';
		$object_js      = WTT_PLUGIN_DIR . 'assets/js/wtt-object-render.js';
		$settings_js    = WTT_PLUGIN_DIR . 'assets/js/wtt-settings-render.js';
		$validator_js   = WTT_PLUGIN_DIR . 'assets/js/wtt-table-validator.js';
		$picker_js      = WTT_PLUGIN_DIR . 'assets/js/wtt-node-picker.js';
		$js_abs         = WTT_PLUGIN_DIR . 'assets/js/tree-admin.js';
		$shared         = is_readable( $shared_js ) ? file_get_contents( $shared_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$int_value      = is_readable( $int_js ) ? file_get_contents( $int_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$converter      = is_readable( $converter_js ) ? file_get_contents( $converter_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$value_validator = is_readable( $value_val_js ) ? file_get_contents( $value_val_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sample         = is_readable( $sample_js ) ? file_get_contents( $sample_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$render         = is_readable( $render_js ) ? file_get_contents( $render_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$object         = is_readable( $object_js ) ? file_get_contents( $object_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$settings       = is_readable( $settings_js ) ? file_get_contents( $settings_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$validator      = is_readable( $validator_js ) ? file_get_contents( $validator_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$picker         = is_readable( $picker_js ) ? file_get_contents( $picker_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js             = is_readable( $js_abs ) ? file_get_contents( $js_abs ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$json = Json_Meta::encode_raw( self::$boot_config );
		if ( false === $json ) {
			$json = '{}';
		}

		echo "<script id=\"wtt-tree-boot\">\n";
		echo 'window.wttTree = ' . $json . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</script>\n";

		if ( false !== $shared && '' !== $shared ) {
			echo "<script id=\"wtt-media-render-js\">\n" . $shared . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $int_value && '' !== $int_value ) {
			echo "<script id=\"wtt-int-value-js\">\n" . $int_value . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $converter && '' !== $converter ) {
			echo "<script id=\"wtt-converter-js\">\n" . $converter . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $value_validator && '' !== $value_validator ) {
			echo "<script id=\"wtt-validator-js\">\n" . $value_validator . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $sample && '' !== $sample ) {
			echo "<script id=\"wtt-sample-data-js\">\n" . $sample . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $render && '' !== $render ) {
			echo "<script id=\"wtt-node-render-js\">\n" . $render . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $object && '' !== $object ) {
			echo "<script id=\"wtt-object-render-js\">\n" . $object . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $settings && '' !== $settings ) {
			echo "<script id=\"wtt-settings-render-js\">\n" . $settings . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $validator && '' !== $validator ) {
			echo "<script id=\"wtt-table-validator-js\">\n" . $validator . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $picker && '' !== $picker ) {
			echo "<script id=\"wtt-node-picker-js\">\n" . $picker . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( false !== $js && '' !== $js ) {
			echo "<script id=\"wtt-tree-admin-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo "<script>document.getElementById('wtt-app') && (document.getElementById('wtt-app').innerHTML = '<p class=\"wtt-error\">JS file missing on disk.</p>');</script>\n";
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-taxonomy-tree' ) );
		}

		$css_ok = is_readable( WTT_PLUGIN_DIR . 'assets/css/tree-admin.css' );
		$js_ok  = is_readable( WTT_PLUGIN_DIR . 'assets/js/tree-admin.js' );
		?>
		<div class="wrap wtt-wrap">
			<h1>
				<?php esc_html_e( 'Taxonomy Tree', 'wp-taxonomy-tree' ); ?>
				<span class="wtt-badge" id="wtt-badge"><?php echo esc_html( sprintf( __( 'Scaffold %s', 'wp-taxonomy-tree' ), WTT_VERSION ) ); ?></span>
			</h1>
			<p class="description" id="wtt-intro">
				<?php esc_html_e( 'Select a node to inspect it. Domain model (Project / Node; Eigenschaften = typed children) is still in planning - this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' ); ?>
			</p>
			<?php if ( ! $css_ok || ! $js_ok ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: CSS path status, 2: JS path status */
								__( 'Plugin assets missing on disk. CSS: %1$s / JS: %2$s', 'wp-taxonomy-tree' ),
								$css_ok ? 'OK' : 'MISSING',
								$js_ok ? 'OK' : 'MISSING'
							)
						);
						?>
					</p>
					<p><code><?php echo esc_html( WTT_PLUGIN_DIR ); ?></code></p>
				</div>
			<?php endif; ?>
			<div id="wtt-app" class="wtt-app" aria-live="polite">
				<p class="wtt-empty"><?php esc_html_e( 'Loading tree UI...', 'wp-taxonomy-tree' ); ?></p>
			</div>
		</div>
		<?php
	}
}
