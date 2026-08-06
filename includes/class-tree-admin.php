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

		$case_study = Taxonomy::is_case_study( $requested );

		// Scaffold: seed active taxonomy when empty; BOM ensures only on wtt_tree.
		if ( current_user_can( Capabilities::edit_terms( $requested ) ) ) {
			if ( $case_study ) {
				Case_Data::maybe_install( $requested );
			} else {
				$bom = get_terms(
					array(
						'taxonomy'   => $requested,
						'name'       => Demo_Data::ROOT_NAME,
						'parent'     => 0,
						'hide_empty' => false,
						'number'     => 1,
					)
				);
				if ( ! is_array( $bom ) || empty( $bom ) ) {
					Demo_Data::install( $requested );
				}
				Demo_Data::migrate_basiseinheit_wert_to_typ( $requested );
				Demo_Data::migrate_abmessung_t_to_h( $requested );
				Demo_Data::migrate_subtree_type_to_node_embed( $requested );
				Demo_Data::ensure_node_pick_type_group( $requested );
				Demo_Data::ensure_datatype_flags( $requested );
				Demo_Data::ensure_type_inheritance( $requested );
				Demo_Data::ensure_relation_types( $requested );
				Demo_Data::ensure_set_composition_members( $requested );
				Demo_Data::ensure_prefix_multiplikators( $requested );
				Demo_Data::ensure_short_descriptions( $requested );
				Demo_Data::ensure_media_type( $requested );
				Demo_Data::ensure_email_type( $requested );
				Demo_Data::ensure_subnode_type( $requested );
				Demo_Data::ensure_bom_columns( $requested );
				Node_Type::ensure_table_type_props( $requested );
				Demo_Data::ensure_deletable_flags( $requested );
				Demo_Data::strip_distributor_samples_under_enum( $requested );
			}
			Catalog_Bindings::ensure( $requested );
		}

		$select_hint = $case_study
			? __( 'Select a node. Case-study tree (Definition / Implementation) — slim detail UI; not a model sign-off.', 'wp-taxonomy-tree' )
			: __( 'Select a node to inspect it. Domain model (Project / Node; Eigenschaften = typed children) is still in planning - this screen is the taxonomy-tree scaffold.', 'wp-taxonomy-tree' );
		$confirm_reset = $case_study
			? __( 'Delete Fallstudie root, then reinstall the case-study tree?', 'wp-taxonomy-tree' )
			: __( 'Delete BOM Testprojekt (and old Passive Components / Semiconductors stubs), then reinstall the full demo tree?', 'wp-taxonomy-tree' );
		$reset_label = $case_study
			? __( 'Reset case tree', 'wp-taxonomy-tree' )
			: __( 'Reset test tree', 'wp-taxonomy-tree' );
		$reset_done = $case_study
			? __( 'Case tree reset and reinstalled.', 'wp-taxonomy-tree' )
			: __( 'Test tree reset and reinstalled.', 'wp-taxonomy-tree' );

		$config = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( Tree_Ajax::NONCE_ACTION ),
			'taxonomy'   => $requested,
			'taxonomies' => $taxonomies,
			'tree'       => Tree_Model::get_tree( $requested ),
			'version'    => WTT_VERSION,
			'caseStudyMode'     => $case_study,
			'testMode'          => Settings::is_test_mode(),
			'showTypeInTree'    => Settings::show_type_in_tree(),
			'showSetChildProps' => Settings::show_set_child_props(),
			'saveViaButton'     => Settings::save_via_button(),
			'treePickerMode'    => Settings::tree_picker_mode(),
			'confirmNodeDelete' => Settings::confirm_node_delete(),
			'developmentMode'   => Settings::is_development_mode(),
			'catalogBindings'   => Catalog_Bindings::for_client( $requested ),
			/* Trial: flags + static meta as form rows (label left, chips right). Set false to revert strip layout. */
			'flagsAsFormRow'   => true,
			'i18n'       => array(
				'empty'           => __( 'No terms yet. Create a root node to start the tree.', 'wp-taxonomy-tree' ),
				'selectHint'      => $select_hint,
				'loading'         => __( 'Loading...', 'wp-taxonomy-tree' ),
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
				'confirmNodeOnly' => __( 'Move this node and all descendants to Trash? Parent/child links are kept.', 'wp-taxonomy-tree' ),
				'confirmBranch'   => __( 'Move this node and all descendants to Trash? Parent/child links are kept.', 'wp-taxonomy-tree' ),
				'confirmMoveToTrash' => __( 'Move this node to Trash?', 'wp-taxonomy-tree' ),
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
				'resetDemo'       => $reset_label,
				'confirmReset'    => $confirm_reset,
				'demoReset'       => $reset_done,
				'dataType'        => __( 'Data type', 'wp-taxonomy-tree' ),
				'dataTypeNone'    => __( 'No type', 'wp-taxonomy-tree' ),
				'dataTypeHint'    => __( 'Pick a non-abstract data-type node. Stored as type_id; Relations → has_type shows the same binding.', 'wp-taxonomy-tree' ),
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
				'isDatatype'      => __( 'Is data type', 'wp-taxonomy-tree' ),
				'isDatatypeHint'  => __( 'Marks this node as a type catalog entry (chooser forest). Children inherit the flag unless overridden. A datatype may also have its own type assigned.', 'wp-taxonomy-tree' ),
				'isAbstract'      => __( 'Is abstract', 'wp-taxonomy-tree' ),
				'isAbstractHint'  => __( 'Local only — not inherited. On data-type nodes: abstract types appear in the chooser but cannot be selected. On other nodes: marks this node as abstract for its own use.', 'wp-taxonomy-tree' ),
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
				'fieldMultiplicityHint' => __( 'How many targets this node_ref may pick at runtime (1..* = many). Not the Mult. column on has_type / ref_scope relations (those stay 0..1).', 'wp-taxonomy-tree' ),
				'relationsMultLockedHint' => __( 'Locked: child_of is always 1; has_type and ref_scope are always 0..1. Use Field multiplicity under Properties for 1..* picks on node_ref.', 'wp-taxonomy-tree' ),
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
				'attributesTitle' => __( 'Attributes', 'wp-taxonomy-tree' ),
				'attributesHelp'  => __( 'Name + type + multiplicity + Bindung. Inherited along child_of; Hide inherited; Default value and Readonly on this node. Actions column is always last.', 'wp-taxonomy-tree' ),
				'attributesEmpty' => __( 'No attributes yet.', 'wp-taxonomy-tree' ),
				'attributesAdd'   => __( 'Add attribute', 'wp-taxonomy-tree' ),
				'attributesName'  => __( 'Name', 'wp-taxonomy-tree' ),
				'attributesType'  => __( 'Type', 'wp-taxonomy-tree' ),
				'attributesMult'  => __( 'Mult.', 'wp-taxonomy-tree' ),
				'attributesMultTitle' => __( 'Multiplicity', 'wp-taxonomy-tree' ),
				'attributesFixed' => __( 'Default', 'wp-taxonomy-tree' ),
				'attributesReadonly' => __( 'RO', 'wp-taxonomy-tree' ),
				'attributesReadonlyHint' => __( 'When on, the attribute is not editable in forms (default value may still apply).', 'wp-taxonomy-tree' ),
				'attributesReadonlyTitle' => __( 'Readonly', 'wp-taxonomy-tree' ),
				'attributesFixedTitle' => __( 'Default value', 'wp-taxonomy-tree' ),
				'attributesFixedAdd' => __( 'Set default', 'wp-taxonomy-tree' ),
				'attributesReorderUp' => __( 'Move up', 'wp-taxonomy-tree' ),
				'attributesReorderDown' => __( 'Move down', 'wp-taxonomy-tree' ),
				'attributesBinding' => __( 'Bindung', 'wp-taxonomy-tree' ),
				'attributesBindingComposition' => __( 'Composition (besteht_aus)', 'wp-taxonomy-tree' ),
				'attributesBindingAggregation' => __( 'Aggregation', 'wp-taxonomy-tree' ),
				'attributesInherited' => __( 'Inherited', 'wp-taxonomy-tree' ),
				'attributesActions' => __( 'Actions', 'wp-taxonomy-tree' ),
				'attributesInheritedNo' => __( '—', 'wp-taxonomy-tree' ),
				'attributesInheritedYes' => __( 'Yes', 'wp-taxonomy-tree' ),
				'attributesHideLabel' => __( 'Hide', 'wp-taxonomy-tree' ),
				'attributesPickType' => __( 'Choose attribute type', 'wp-taxonomy-tree' ),
				'attributesNameRequired' => __( 'Attribute name is required.', 'wp-taxonomy-tree' ),
				'attributesTypeRequired' => __( 'Attribute type is required.', 'wp-taxonomy-tree' ),
				'attributesRemove' => __( 'Remove', 'wp-taxonomy-tree' ),
				'attributesRemoveConfirm' => __( 'Remove this attribute?', 'wp-taxonomy-tree' ),
				'attributesMoveToParent' => __( 'Move to parent', 'wp-taxonomy-tree' ),
				'attributesMoveToParentConfirm' => __( 'Move this attribute to the parent node?', 'wp-taxonomy-tree' ),
				'attributesMoveToParentHint' => __( 'Move this own attribute to the parent node. It will then be inherited here along child_of.', 'wp-taxonomy-tree' ),
				'attributesMoveToChild' => __( 'Move to child', 'wp-taxonomy-tree' ),
				'attributesMoveToChildHint' => __( 'Move this own attribute to a direct hierarchy child. Choose the child first.', 'wp-taxonomy-tree' ),
				'attributesMoveToChildPick' => __( 'Choose child for attribute', 'wp-taxonomy-tree' ),
				'attributesMoveToChildEmpty' => __( 'No eligible children to move this attribute to.', 'wp-taxonomy-tree' ),
				'attributesUntyped' => __( 'not typed', 'wp-taxonomy-tree' ),
				'attributesInheritedFrom' => __( 'Inherited from %s', 'wp-taxonomy-tree' ),
				'attributesHide' => __( 'Hide', 'wp-taxonomy-tree' ),
				'attributesShow' => __( 'Show', 'wp-taxonomy-tree' ),
				'attributesHidden' => __( 'hidden', 'wp-taxonomy-tree' ),
				'attributesHideHint' => __( 'Hide this inherited attribute on this node (does not delete the parent definition). Default: off (visible).', 'wp-taxonomy-tree' ),
				'attributesHideOwnHint' => __( 'Hide applies only to inherited attributes. Default: off.', 'wp-taxonomy-tree' ),
				'attributesFixedNone' => __( 'No default value', 'wp-taxonomy-tree' ),
				'attributesFixedEdit' => __( 'Choose default…', 'wp-taxonomy-tree' ),
				'attributesFixedClear' => __( 'Clear', 'wp-taxonomy-tree' ),
				'attributesFixedApply' => __( 'Apply', 'wp-taxonomy-tree' ),
				'attributesFixedRequired' => __( 'At least one value is required for this multiplicity.', 'wp-taxonomy-tree' ),
				'attributesFixedAddValue' => __( 'Add value', 'wp-taxonomy-tree' ),
				'attributesFixedHint' => __( 'Default value(s) on this node (own or inherited attribute). Catalog types: pick from the type tree (list picker). Multiplicity controls how many values.', 'wp-taxonomy-tree' ),
				'attributesFixedEmpty' => __( 'This type has no selectable values yet.', 'wp-taxonomy-tree' ),
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
				'relationsHint'   => __( 'Format: node → relation type → node. Click To to change the target (except child_of — use Reparent). has_type mirrors Data type from properties; Mult. = definition multiplicity.', 'wp-taxonomy-tree' ),
				'relationsEmpty'  => __( 'None', 'wp-taxonomy-tree' ),
				'relationsType'   => __( 'Relation type', 'wp-taxonomy-tree' ),
				'relationsTypeHint' => __( 'Relation type (e.g. composition) — not a Node. Not for child_of.', 'wp-taxonomy-tree' ),
				'relationsTarget' => __( 'To', 'wp-taxonomy-tree' ),
				'relationsSource' => __( 'From', 'wp-taxonomy-tree' ),
				'relationsNotes'  => __( 'Notes', 'wp-taxonomy-tree' ),
				'relationsMult'   => __( 'Mult.', 'wp-taxonomy-tree' ),
				'relationsMultHint' => __( 'Definition multiplicity: lower bound 0 or 1; upper bound 1 or * (many).', 'wp-taxonomy-tree' ),
				'relationsProtected' => __( 'protected — reparent only', 'wp-taxonomy-tree' ),
				'relationsHasTypeNote' => __( 'Data type binding (0..1). Prefer the Data type field above; Relations mirrors the same type_id.', 'wp-taxonomy-tree' ),
				'relationsHasTypeInherited' => __( 'inherited — enable Override to change', 'wp-taxonomy-tree' ),
				'relationsPickHasTypeTarget' => __( 'Choose data-type node (has_type target)', 'wp-taxonomy-tree' ),
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
				'fixedValue'      => __( 'Fixed value', 'wp-taxonomy-tree' ),
				'fixedValueNone'  => __( 'Not fixed', 'wp-taxonomy-tree' ),
				'fixedValueOff'   => __( 'No fixed value', 'wp-taxonomy-tree' ),
				'fixedValueOn'    => __( 'Use fixed value', 'wp-taxonomy-tree' ),
				'fixedValueChoose'=> __( 'Choose node', 'wp-taxonomy-tree' ),
				'fixedValueHint'  => __( 'Off by default. When on, this slot is constant (not filled by the user).', 'wp-taxonomy-tree' ),
				'fixedLiteralHint'=> __( 'Simple types: enter the constant (e.g. 10 for double). Empty is not allowed while “Use fixed value” is selected.', 'wp-taxonomy-tree' ),
				'fixedLiteralPlaceholder' => __( 'Constant value…', 'wp-taxonomy-tree' ),
				'fixedCatalogHint'=> __( 'Catalog types: pick a Typen node (e.g. Einheit → Ohm).', 'wp-taxonomy-tree' ),
				'fixedValueUnavailable' => __( 'Fixed value is available for simple and catalog types after you choose a data type.', 'wp-taxonomy-tree' ),
				'boolTrue'        => __( 'true', 'wp-taxonomy-tree' ),
				'boolFalse'       => __( 'false', 'wp-taxonomy-tree' ),
				'emailInvalid'    => __( 'Enter a valid email address.', 'wp-taxonomy-tree' ),
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
				'unitConvNone'    => __( '(none)', 'wp-taxonomy-tree' ),
				'setPreview'      => __( 'Preview', 'wp-taxonomy-tree' ),
				'unifiedPreviewHint' => __( 'Form and table — editable above, display mirror below (same fields).', 'wp-taxonomy-tree' ),
				'previewSchema'   => __( 'Definition', 'wp-taxonomy-tree' ),
				'unitSchemaHint'  => __( 'Unit schema only — not an instance. Kuerzel is the unit symbol (Meter → m). Praefix catalog “m” is Milli — same letter, different node.', 'wp-taxonomy-tree' ),
				'unitUsageHint'   => __( 'Usage sample when a field uses this unit (value + prefix + symbol). Sample often picks milli → e.g. 10.5mm.', 'wp-taxonomy-tree' ),
				'previewAsForm'   => __( 'Form', 'wp-taxonomy-tree' ),
				'previewAsTable'  => __( 'Table', 'wp-taxonomy-tree' ),
				'previewAsCompact'=> __( 'Compact', 'wp-taxonomy-tree' ),
				'previewCompactHorizontal' => __( 'Horizontal', 'wp-taxonomy-tree' ),
				'previewCompactVertical' => __( 'Vertical', 'wp-taxonomy-tree' ),
				'previewAsTree'   => __( 'Tree', 'wp-taxonomy-tree' ),
				'nodeRenderPreviewHint' => __( 'Rendered via NodeRendererRegistry (tree / form / table). Same path for admin preview and future frontend.', 'wp-taxonomy-tree' ),
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

		$shared_js    = WTT_PLUGIN_DIR . 'assets/js/wtt-media-render.js';
		$sample_js    = WTT_PLUGIN_DIR . 'assets/js/wtt-sample-data.js';
		$render_js    = WTT_PLUGIN_DIR . 'assets/js/wtt-node-render.js';
		$object_js    = WTT_PLUGIN_DIR . 'assets/js/wtt-object-render.js';
		$validator_js = WTT_PLUGIN_DIR . 'assets/js/wtt-table-validator.js';
		$js_abs       = WTT_PLUGIN_DIR . 'assets/js/tree-admin.js';
		$shared       = is_readable( $shared_js ) ? file_get_contents( $shared_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sample       = is_readable( $sample_js ) ? file_get_contents( $sample_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$render       = is_readable( $render_js ) ? file_get_contents( $render_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$object       = is_readable( $object_js ) ? file_get_contents( $object_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$validator    = is_readable( $validator_js ) ? file_get_contents( $validator_js ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js           = is_readable( $js_abs ) ? file_get_contents( $js_abs ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$json = wp_json_encode( self::$boot_config );
		if ( false === $json ) {
			$json = '{}';
		}

		echo "<script id=\"wtt-tree-boot\">\n";
		echo 'window.wttTree = ' . $json . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</script>\n";

		if ( false !== $shared && '' !== $shared ) {
			echo "<script id=\"wtt-media-render-js\">\n" . $shared . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

		if ( false !== $validator && '' !== $validator ) {
			echo "<script id=\"wtt-table-validator-js\">\n" . $validator . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
