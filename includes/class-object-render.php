<?php
/**
 * Shared Object View renderer — node + all effective attributes (Q87).
 *
 * Used by the taxo/object-view block (SSR) and mirrored in assets/js/wtt-object-render.js.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a display DTO and Form-layout HTML for a bound taxonomy node.
 */
final class Object_Render {

	/**
	 * i18n strings for PHP HTML + JS mirror.
	 *
	 * @return array<string, string>
	 */
	public static function i18n(): array {
		return array(
			'empty'                   => __( 'Choose a model node in the tree below.', 'wp-taxonomy-tree' ),
			'notFound'                => __( 'Node not found.', 'wp-taxonomy-tree' ),
			'noProperties'            => __( 'This node has no attributes.', 'wp-taxonomy-tree' ),
			'name'                    => __( 'Name', 'wp-taxonomy-tree' ),
			'description'             => __( 'Description', 'wp-taxonomy-tree' ),
			'shortDescription'        => __( 'Short description', 'wp-taxonomy-tree' ),
			'type'                    => __( 'Type', 'wp-taxonomy-tree' ),
			'path'                    => __( 'Path', 'wp-taxonomy-tree' ),
			'nodeMeta'                => __( 'Meta', 'wp-taxonomy-tree' ),
			'termId'                  => __( 'ID', 'wp-taxonomy-tree' ),
			'parent'                  => __( 'Parent', 'wp-taxonomy-tree' ),
			'slug'                    => __( 'Slug', 'wp-taxonomy-tree' ),
			'lastModifiedBy'          => __( 'Last modified by', 'wp-taxonomy-tree' ),
			'lastModifiedAt'          => __( 'Last modified', 'wp-taxonomy-tree' ),
			'none'                    => __( '—', 'wp-taxonomy-tree' ),
			'properties'              => __( 'Properties', 'wp-taxonomy-tree' ),
			'propertiesMany'          => __( 'Multi-value attributes', 'wp-taxonomy-tree' ),
			'colIndex'                => __( '#', 'wp-taxonomy-tree' ),
			'inherited'               => __( 'Inherited', 'wp-taxonomy-tree' ),
			'hidden'                  => __( 'Hidden', 'wp-taxonomy-tree' ),
			'emptyValue'              => __( '—', 'wp-taxonomy-tree' ),
			'pickTaxonomy'            => __( 'Taxonomy', 'wp-taxonomy-tree' ),
			'pickNode'                => __( 'Node', 'wp-taxonomy-tree' ),
			'pickHint'                => __( 'Bind a taxonomy tree node to display its name, descriptions, and attributes.', 'wp-taxonomy-tree' ),
			'chooseModelCanvas'       => __( 'Choose a model node in the tree below.', 'wp-taxonomy-tree' ),
			'changeModel'             => __( 'Change model…', 'wp-taxonomy-tree' ),
			'changeInstance'          => __( 'Change dataset…', 'wp-taxonomy-tree' ),
			'pickInstance'            => __( 'Dataset (instance)', 'wp-taxonomy-tree' ),
			'pickInstanceHint'        => __( 'Pick an existing model-data instance or create a new one.', 'wp-taxonomy-tree' ),
			'createInstance'          => __( 'Create new', 'wp-taxonomy-tree' ),
			'noInstances'             => __( 'No instances yet. Create one to continue.', 'wp-taxonomy-tree' ),
			'noMatchingInstances'     => __( 'No matching instances.', 'wp-taxonomy-tree' ),
			'tableEmpty'              => __( 'No data available.', 'wp-taxonomy-tree' ),
			'instanceSearch'          => __( 'Search', 'wp-taxonomy-tree' ),
			'instanceSearchPlaceholder' => __( 'Search…', 'wp-taxonomy-tree' ),
			'instanceLoadFailed'      => __( 'Could not load instances.', 'wp-taxonomy-tree' ),
			'instanceCreateFailed'    => __( 'Could not create instance.', 'wp-taxonomy-tree' ),
			'datasetLabel'            => __( 'Dataset:', 'wp-taxonomy-tree' ),
			'noInstance'              => __( 'No dataset', 'wp-taxonomy-tree' ),
			'flowHintInstance'        => __( 'Model bound — pick or create a dataset instance.', 'wp-taxonomy-tree' ),
			'loading'                 => __( 'Loading…', 'wp-taxonomy-tree' ),
			'noNodes'                 => __( 'No nodes found in this taxonomy.', 'wp-taxonomy-tree' ),
			'multiplicity'            => __( 'Mult.', 'wp-taxonomy-tree' ),
			'binding'                 => __( 'Binding', 'wp-taxonomy-tree' ),
			'pickLayout'              => __( 'Layout', 'wp-taxonomy-tree' ),
			'layoutForm'              => __( 'Form + Table (auto)', 'wp-taxonomy-tree' ),
			'layoutTable'             => __( 'Table (singles)', 'wp-taxonomy-tree' ),
			'layoutCompact'           => __( 'Compact (horizontal)', 'wp-taxonomy-tree' ),
			'layoutCompactVertical'   => __( 'Compact (vertical)', 'wp-taxonomy-tree' ),
			'layoutEmbed'             => __( 'Embedded renderer', 'wp-taxonomy-tree' ),
			'layoutAuto'              => __( 'Node preferred', 'wp-taxonomy-tree' ),
			'layoutAutoHelp'          => __( 'Use the preferred render stored on the bound node.', 'wp-taxonomy-tree' ),
			'embedPickHint'           => __( 'Choose kind…', 'wp-taxonomy-tree' ),
			'embedNoChoices'          => __( 'No specialization children under this node.', 'wp-taxonomy-tree' ),
			'embedLoading'            => __( 'Loading…', 'wp-taxonomy-tree' ),
			'embedNoFields'           => __( 'Selected node has no attributes.', 'wp-taxonomy-tree' ),
			'embedPickPart'           => __( 'Pick part…', 'wp-taxonomy-tree' ),
			'embedChangePart'         => __( 'Change…', 'wp-taxonomy-tree' ),
			'embedPhaseATitle'        => __( 'Choose part kind', 'wp-taxonomy-tree' ),
			'embedPhaseBTitle'        => __( 'Pick or create part', 'wp-taxonomy-tree' ),
			'embedPhaseBHint'         => __( 'Filter existing Model data for this kind, pick a match, or create from the form.', 'wp-taxonomy-tree' ),
			'embedFilterLabel'        => __( 'Filter (AND)', 'wp-taxonomy-tree' ),
			'embedMatches'            => __( 'Matches', 'wp-taxonomy-tree' ),
			'embedNoMatches'          => __( 'No matching instances.', 'wp-taxonomy-tree' ),
			'embedCreateBind'         => __( 'Create and bind', 'wp-taxonomy-tree' ),
			'embedBackKind'           => __( '← Kind', 'wp-taxonomy-tree' ),
			'embedRequiredEmpty'      => __( 'Required — pick or create a part.', 'wp-taxonomy-tree' ),
			'embedInstanceApiMissing' => __( 'Model data API unavailable — cannot list or create instances.', 'wp-taxonomy-tree' ),
			'nodePickerTitle'         => __( 'Choose node', 'wp-taxonomy-tree' ),
			'nodePickerClear'         => __( 'Clear', 'wp-taxonomy-tree' ),
			'cancel'                  => __( 'Cancel', 'wp-taxonomy-tree' ),
			'colIndex'                => __( '#', 'wp-taxonomy-tree' ),
			'renderingPanel'          => __( 'Rendering', 'wp-taxonomy-tree' ),
			'pickRenderDepth'         => __( 'Render depth', 'wp-taxonomy-tree' ),
			'renderDepthHelp'         => __( 'How deep nested objects are expanded. 0 = meta only; 1 = this node and its direct attributes (site default). Deeper values nest related objects. Change the site default under Taxonomy Tree → Settings.', 'wp-taxonomy-tree' ),
			'renderDepthSiteDefault'  => __( 'Site default', 'wp-taxonomy-tree' ),
			'pickReferenceMode'       => __( 'Reference rendering', 'wp-taxonomy-tree' ),
			'referenceModeHelp'       => __( 'How node references and catalog picks are shown when not editing.', 'wp-taxonomy-tree' ),
			'referenceModeNone'       => __( 'None (omit)', 'wp-taxonomy-tree' ),
			'referenceModeLink'       => __( 'Link / name', 'wp-taxonomy-tree' ),
			'referenceModeSummary'    => __( 'Summary', 'wp-taxonomy-tree' ),
			'referenceModeEmbed'      => __( 'Embed (nested view)', 'wp-taxonomy-tree' ),
			'referenceEmbedDeferred'  => __( 'Nested embed requires depth ≥ 2; showing summary until full nest is available.', 'wp-taxonomy-tree' ),
			'savingInstance'          => __( 'Saving instance…', 'wp-taxonomy-tree' ),
			'savedInstance'           => __( 'Instance saved.', 'wp-taxonomy-tree' ),
			'editNeedsInstance'       => __( 'Pick a dataset to edit attribute values.', 'wp-taxonomy-tree' ),
			'addLine'                 => __( 'Add line', 'wp-taxonomy-tree' ),
			'noRelatedLines'          => __( 'No related lines yet.', 'wp-taxonomy-tree' ),
			'lineCreated'             => __( 'Line created.', 'wp-taxonomy-tree' ),
			'lineSaved'               => __( 'Line saved.', 'wp-taxonomy-tree' ),
			'relatedLinesHint'        => __( 'Composition/aggregation Mult many rows for this instance (not a global orphan list).', 'wp-taxonomy-tree' ),
			'colVersion'              => __( 'Version', 'wp-taxonomy-tree' ),
			'colModified'             => __( 'Modified', 'wp-taxonomy-tree' ),
			'colInstanceId'           => __( 'Id', 'wp-taxonomy-tree' ),
			'nodePickerSearch'        => __( 'Search', 'wp-taxonomy-tree' ),
			'nodePickerSearchPlaceholder' => __( 'Search…', 'wp-taxonomy-tree' ),
			'nodePickerSearchEmpty'   => __( 'No matching nodes.', 'wp-taxonomy-tree' ),
			'nodePickerExpand'        => __( 'Expand', 'wp-taxonomy-tree' ),
			'nodePickerCollapse'      => __( 'Collapse', 'wp-taxonomy-tree' ),
			'nodePickerAbstractHint'  => __( 'Expand and choose a child.', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Enqueue shared CSS/JS for frontend or editor mirror.
	 */
	public static function enqueue_assets(): void {
		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';

		Media_Render::enqueue_assets();

		if ( ! wp_script_is( 'wtt-sample-data', 'registered' ) ) {
			wp_register_script(
				'wtt-sample-data',
				WTT_PLUGIN_URL . 'assets/js/wtt-sample-data.js',
				array( 'wtt-media-render' ),
				$ver,
				true
			);
		}
		if ( ! wp_script_is( 'wtt-int-value', 'registered' ) ) {
			wp_register_script(
				'wtt-int-value',
				WTT_PLUGIN_URL . 'assets/js/wtt-int-value.js',
				array(),
				$ver,
				true
			);
		}
		if ( ! wp_script_is( 'wtt-converter', 'registered' ) ) {
			wp_register_script(
				'wtt-converter',
				WTT_PLUGIN_URL . 'assets/js/wtt-converter.js',
				array( 'wtt-int-value' ),
				$ver,
				true
			);
		}
		if ( ! wp_script_is( 'wtt-node-render', 'registered' ) ) {
			wp_register_script(
				'wtt-node-render',
				WTT_PLUGIN_URL . 'assets/js/wtt-node-render.js',
				array( 'wtt-sample-data', 'wtt-int-value', 'wtt-converter' ),
				$ver,
				true
			);
		}

		wp_enqueue_style(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-object-render.css',
			array( 'wtt-media-render' ),
			$ver
		);
		wp_enqueue_script(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-object-render.js',
			array( 'wtt-node-render', 'wtt-media-render' ),
			$ver,
			true
		);
		wp_localize_script(
			'wtt-object-render',
			'wttObjectRenderI18n',
			self::i18n()
		);
		wp_add_inline_script(
			'wtt-object-render',
			'if (window.WTTObjectRender) { window.WTTObjectRender.configure({ i18n: window.wttObjectRenderI18n || {} }); }',
			'after'
		);
	}

	/**
	 * Pickable nodes for Object View / Model bind tree.
	 * One scaffold tree: under chooser_root when bound; attribute slots excluded.
	 *
	 * @return list<array{id:int,name:string,path:string,taxonomy:string}>
	 */
	public static function list_pickable_nodes( string $taxonomy = '' ): array {
		$slugs = '' !== $taxonomy
			? array( $taxonomy )
			: Taxonomy::scaffold_slugs();
		$out   = array();

		foreach ( $slugs as $slug ) {
			if ( ! Taxonomy::is_scaffold( $slug ) || ! taxonomy_exists( $slug ) ) {
				continue;
			}
			Catalog_Bindings::ensure( $slug );
			$root_id = Catalog_Bindings::resolve( $slug, Catalog_Bindings::KEY_CHOOSER_ROOT );
			$terms   = get_terms(
				array(
					'taxonomy'   => $slug,
					'hide_empty' => false,
					'number'     => 0,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$term_id = (int) $term->term_id;
				if ( Trash::is_trashed( $term_id ) || Trash::is_trash_node( $term_id ) ) {
					continue;
				}
				if ( Attribute::is_slot( $term_id ) ) {
					continue;
				}
				if ( $root_id > 0 && ! self::term_is_under( $slug, $term_id, $root_id ) ) {
					continue;
				}
				$out[] = array(
					'id'       => $term_id,
					'name'     => $term->name,
					'path'     => Composition::term_path( $slug, $term_id ),
					'taxonomy' => $slug,
				);
			}
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				$tax = strcasecmp( (string) ( $a['taxonomy'] ?? '' ), (string) ( $b['taxonomy'] ?? '' ) );
				if ( 0 !== $tax ) {
					return $tax;
				}
				return strcasecmp( $a['path'], $b['path'] );
			}
		);

		return $out;
	}

	/**
	 * Whether $term_id is $root_id or a descendant (via WP term_parent).
	 */
	private static function term_is_under( string $taxonomy, int $term_id, int $root_id ): bool {
		if ( $root_id <= 0 || $term_id <= 0 ) {
			return true;
		}
		if ( $term_id === $root_id ) {
			return true;
		}
		$guard = 0;
		$cur   = $term_id;
		while ( $cur > 0 && $guard < 64 ) {
			++$guard;
			$term = get_term( $cur, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			$parent = (int) $term->parent;
			if ( $parent === $root_id ) {
				return true;
			}
			$cur = $parent;
		}
		return false;
	}

	/**
	 * Display DTO for a bound node (name, descriptions, effective attributes).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_view( string $taxonomy, int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}
		if ( '' === $taxonomy ) {
			$taxonomy = Taxonomy::taxonomy_for_term( $term_id );
		}
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return null;
		}
		if ( Trash::is_trashed( $term_id ) || Trash::is_trash_node( $term_id ) ) {
			return null;
		}

		$type_id   = Node_Type::get_effective_type_id( $taxonomy, $term_id );
		$type_name = '';
		$type_key  = '';
		if ( $type_id > 0 ) {
			$type = get_term( $type_id, $taxonomy );
			if ( $type instanceof \WP_Term ) {
				$type_name = $type->name;
				$type_key  = strtolower( $type->name );
			}
		}

		$parent_id   = (int) $term->parent;
		$parent_name = '';
		if ( $parent_id > 0 ) {
			$parent = get_term( $parent_id, $taxonomy );
			if ( $parent instanceof \WP_Term ) {
				$parent_name = $parent->name;
			}
		}

		$properties = array();
		foreach ( Attribute::list( $taxonomy, $term_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$properties[] = self::property_dto( $row, $taxonomy );
		}

		return array(
			'id'               => $term_id,
			'taxonomy'         => $taxonomy,
			'name'             => $term->name,
			'slug'             => (string) $term->slug,
			'path'             => Composition::term_path( $taxonomy, $term_id ),
			'description'      => Tree_Model::decode_term_description( (string) $term->description ),
			'shortDescription' => Tree_Model::get_short_description( $term_id ),
			'parent'           => $parent_id,
			'parentName'       => $parent_name,
			'modified'         => Tree_Model::get_modified_info( $term_id ),
			'typeId'           => $type_id,
			'typeName'         => $type_name,
			'typeKey'          => $type_key,
			'preferredRender'  => Node_Type::get_preferred_render( $term_id ),
			'preferredConverter' => Node_Type::get_preferred_converter_for_node( $taxonomy, $term_id ),
			'validators'         => Node_Type::get_validators_for_node( $taxonomy, $term_id ),
			'embedChoiceOptions' => Attribute::embed_choice_options_for_type( $taxonomy, $term_id ),
			'properties'       => $properties,
			'instanceId'       => '',
			'instanceValues'   => new \stdClass(),
		);
	}

	/**
	 * Merge Model_Data instance values into a view DTO (attr id → store string).
	 *
	 * @param array<string, mixed> $view        View from get_view.
	 * @param string               $instance_id Instance id (md_…).
	 * @return array<string, mixed>
	 */
	public static function with_instance_values( array $view, string $instance_id ): array {
		$instance_id = sanitize_key( $instance_id );
		$taxonomy    = isset( $view['taxonomy'] ) ? sanitize_key( (string) $view['taxonomy'] ) : '';
		$term_id     = isset( $view['id'] ) ? (int) $view['id'] : 0;
		$view['instanceId'] = $instance_id;

		$values = array();
		if ( '' !== $instance_id && $term_id > 0 && '' !== $taxonomy ) {
			$inst = Model_Data::get( $taxonomy, $term_id, $instance_id );
			if ( is_array( $inst ) && isset( $inst['values'] ) && is_array( $inst['values'] ) ) {
				foreach ( $inst['values'] as $k => $v ) {
					$values[ (string) $k ] = is_scalar( $v ) ? (string) $v : '';
				}
			}
		}

		$props = isset( $view['properties'] ) && is_array( $view['properties'] )
			? $view['properties']
			: array();
		$out_props = array();
		foreach ( $props as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$aid = Attribute::normalize_attr_id( $prop['id'] ?? '' );
			/*
			 * Q97: Mult many structured attrs (BOM Position, …) read related Model_Data
			 * via links[] — not an inline blob on the parent attribute slot.
			 */
			if ( '' !== $instance_id && Model_Data::is_related_dataset_attr( $taxonomy, $prop ) ) {
				$type_id   = (int) ( $prop['typeId'] ?? 0 );
				$binding   = Attribute::normalize_binding( (string) ( $prop['binding'] ?? Attribute::DEFAULT_BINDING ) );
				$related   = Model_Data::list_related( $taxonomy, $term_id, $instance_id, $binding, $type_id );
				$type_cols = isset( $prop['typeProperties'] ) && is_array( $prop['typeProperties'] )
					? $prop['typeProperties']
					: array();
				$rel_inst  = array();
				foreach ( $related as $link_row ) {
					$child = isset( $link_row['instance'] ) && is_array( $link_row['instance'] )
						? $link_row['instance']
						: null;
					if ( null === $child ) {
						continue;
					}
					$child_vals = isset( $child['values'] ) && is_array( $child['values'] ) ? $child['values'] : array();
					$norm_vals  = array();
					foreach ( $child_vals as $ck => $cv ) {
						$norm_vals[ (string) $ck ] = is_scalar( $cv ) ? (string) $cv : '';
					}
					$rel_inst[] = array(
						'id'         => (string) ( $child['id'] ?? $link_row['instanceId'] ?? '' ),
						'seq'        => (int) ( $child['seq'] ?? 0 ),
						'attributes' => $type_cols,
						'values'     => $norm_vals,
						'structureId'=> (int) ( $link_row['structureId'] ?? $type_id ),
						'relation'   => (string) ( $link_row['relation'] ?? $binding ),
					);
				}
				$prop['relatedInstances']     = $rel_inst;
				$prop['usesRelatedInstances'] = true;
				$prop['isRelatedDataset']     = true;
				$prop['values']               = array();
				$prop['valueLabel']           = '';
				$prop['hasInstanceValue']     = array() !== $rel_inst;
				unset( $values[ $aid ] );
				$out_props[] = $prop;
				continue;
			}
			if ( '' !== $aid && isset( $values[ $aid ] ) && '' !== trim( (string) $values[ $aid ] ) ) {
				$raw                = (string) $values[ $aid ];
				$prop['values']     = self::decode_store_values( $raw );
				$first              = isset( $prop['values'][0] ) ? (string) $prop['values'][0] : $raw;
				$prop['valueLabel'] = self::display_label_for_prop( $prop, $first );
				$prop['hasInstanceValue'] = true;
			}
			$out_props[] = $prop;
		}

		/* Derive computed attributes on read (flat-list Aggregate ops). */
		$value_map = $values;
		foreach ( $out_props as $prop ) {
			$aid = Attribute::normalize_attr_id( $prop['id'] ?? '' );
			if ( '' === $aid ) {
				continue;
			}
			if ( isset( $prop['values'][0] ) ) {
				$value_map[ $aid ] = $prop['values'][0];
			}
		}
		foreach ( $out_props as $i => $prop ) {
			if ( empty( $prop['computed'] ) && empty( $prop['compute'] ) ) {
				continue;
			}
			$computed = Attribute::evaluate_compute( $prop, $out_props, $value_map );
			if ( null === $computed || '' === $computed ) {
				continue;
			}
			$out_props[ $i ]['values']           = array( $computed );
			$out_props[ $i ]['valueLabel']       = $computed;
			$out_props[ $i ]['hasInstanceValue'] = true;
			$out_props[ $i ]['readonly']         = true;
			$aid                                 = Attribute::normalize_attr_id( $prop['id'] ?? '' );
			if ( '' !== $aid ) {
				$values[ $aid ] = $computed;
			}
		}

		$view['properties']     = $out_props;
		$view['instanceValues'] = (object) $values;
		return $view;
	}

	/**
	 * Human label for SSR / chips — never dump raw media JSON.
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 * @param string               $raw  Store value.
	 */
	public static function display_label_for_prop( array $prop, string $raw ): string {
		$type_key = strtolower( trim( (string) ( $prop['typeKey'] ?? $prop['typeName'] ?? '' ) ) );
		if ( false !== strpos( $type_key, '/' ) ) {
			$parts    = array_map( 'trim', explode( '/', $type_key ) );
			$type_key = strtolower( (string) end( $parts ) );
		}
		if ( 'media' === $type_key ) {
			$ref = self::parse_media_ref( $raw );
			if ( null === $ref ) {
				return '';
			}
			$i18n = Media_Render::i18n();
			if ( ! empty( $ref['filename'] ) ) {
				return (string) $ref['filename'];
			}
			$url = (string) ( $ref['url'] ?? '' );
			if ( '' !== $url && str_starts_with( $url, 'data:' ) ) {
				$kind = Media_Render::classify_kind( $ref );
				return '' !== $kind ? (string) ( $i18n['mediaKind' . ucfirst( $kind )] ?? $kind ) : $i18n['mediaEmpty'];
			}
			if ( '' !== $url ) {
				return $url;
			}
			if ( ! empty( $ref['attachment_id'] ) ) {
				return '#' . (int) $ref['attachment_id'];
			}
			return $i18n['mediaEmpty'];
		}
		return $raw;
	}

	/**
	 * @param string $raw Media store JSON or URL.
	 * @return array<string, mixed>|null
	 */
	public static function parse_media_ref( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( '{' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		if ( ctype_digit( $raw ) ) {
			return array(
				'source'        => 'attachment',
				'attachment_id' => (int) $raw,
			);
		}
		return array(
			'source' => 'url',
			'url'    => $raw,
		);
	}

	/**
	 * Decode a Model_Data store string into one or more display/edit values.
	 * JSON arrays become multiple values; scalars stay a one-element list.
	 *
	 * @return list<string>
	 */
	public static function decode_store_values( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		if ( '[' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out = array();
				foreach ( $decoded as $item ) {
					if ( is_scalar( $item ) || null === $item ) {
						$out[] = (string) $item;
					}
				}
				return $out;
			}
		}
		return array( $raw );
	}

	/**
	 * Encode one or more scalar values into a Model_Data store string (Q106).
	 * One value → plain string; several → JSON array string (matches JS encodeManyStoreValues).
	 *
	 * @param list<string|mixed> $values Scalar defaults (nested maps skipped).
	 */
	public static function encode_store_values( array $values ): string {
		$cleaned = array();
		foreach ( $values as $v ) {
			if ( is_array( $v ) ) {
				continue;
			}
			$s = trim( (string) $v );
			if ( '' !== $s ) {
				$cleaned[] = $s;
			}
		}
		if ( array() === $cleaned ) {
			return '';
		}
		if ( 1 === count( $cleaned ) ) {
			return $cleaned[0];
		}
		$json = Json_Meta::encode_raw( array_values( $cleaned ) );
		return false === $json ? $cleaned[0] : $json;
	}

	/**
	 * @param array<string, mixed> $row              Attribute row from Attribute::list.
	 * @param string               $taxonomy         Taxonomy slug (for node_ref extras).
	 * @param bool                 $with_type_schema Include type's attributes once (for Mult many → Table(n)).
	 * @return array<string, mixed>
	 */
	private static function property_dto( array $row, string $taxonomy = '', bool $with_type_schema = true ): array {
		$values = array();
		if ( isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ) {
			foreach ( $row['fixedValues'] as $v ) {
				/* Q106: scalars are strings; related Mult defaults may be nested value maps. */
				if ( is_array( $v ) ) {
					$values[] = $v;
				} else {
					$values[] = (string) $v;
				}
			}
		}

		/* Q123: dto id = Relation edge id; legacySlotId only for pre-migrate term lookups. */
		$attr_id     = Attribute::normalize_attr_id( $row['id'] ?? '' );
		$legacy_slot = (int) ( $row['legacySlotId'] ?? 0 );
		$type_id     = (int) ( $row['typeId'] ?? 0 );
		$type_key    = (string) ( $row['typeKey'] ?? '' );
		$dto         = array(
			'id'            => $attr_id,
			'name'          => (string) ( $row['name'] ?? '' ),
			'typeId'        => $type_id,
			'typeName'      => (string) ( $row['typeName'] ?? '' ),
			'typeKey'       => $type_key,
			'multiplicity'  => (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
			'allowsMany'    => ! empty( $row['allowsMany'] )
				|| Attribute::multiplicity_allows_many(
					(string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY )
				),
			'readonly'      => ! empty( $row['readonly'] ),
			'binding'       => (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ),
			'bindingLabel'  => (string) ( $row['bindingLabel'] ?? '' ),
			'inherited'     => ! empty( $row['inherited'] ),
			'definedOnId'   => (int) ( $row['definedOnId'] ?? 0 ),
			'definedOnName' => (string) ( $row['definedOnName'] ?? '' ),
			'valueLabel'    => (string) ( $row['fixedLabel'] ?? '' ),
			'values'        => $values,
			'fixedMode'     => (string) ( $row['fixedMode'] ?? '' ),
			'fixedRootId'   => (int) ( $row['fixedRootId'] ?? 0 ) > 0
				? (int) $row['fixedRootId']
				: $type_id,
			'fixedOptions'  => isset( $row['fixedOptions'] ) && is_array( $row['fixedOptions'] )
				? array_values( $row['fixedOptions'] )
				: array(),
			'choiceDepth'   => Attribute::choice_depth_from_options(
				isset( $row['fixedOptions'] ) && is_array( $row['fixedOptions'] )
					? $row['fixedOptions']
					: array()
			),
			'typeProperties'=> array(),
			'typePreferredRender' => isset( $row['typePreferredRender'] )
				? Node_Type::normalize_preferred_render( (string) $row['typePreferredRender'] )
				: (
					$type_id > 0
						? Node_Type::get_preferred_render( $type_id )
						: Renderer::Form->value
				),
			'preferredRender' => isset( $row['preferredRender'] )
				? Node_Type::normalize_preferred_render( (string) $row['preferredRender'] )
				: (
					isset( $row['typePreferredRender'] )
						? Node_Type::normalize_preferred_render( (string) $row['typePreferredRender'] )
						: (
							$legacy_slot > 0
								? Node_Type::get_preferred_render( $legacy_slot )
								: (
									$type_id > 0
										? Node_Type::get_preferred_render( $type_id )
										: Renderer::Form->value
								)
						)
				),
		);

		/* node_ref edit/display needs catalog options (same extras as Model table columns). */
		if ( 'node_ref' === strtolower( $type_key ) && $legacy_slot > 0 && '' !== $taxonomy ) {
			$scope_id                     = Node_Type::get_ref_scope_id( $legacy_slot );
			$dto['refScopeId']            = $scope_id;
			$dto['fieldMultiplicity']     = Node_Type::get_field_multiplicity( $legacy_slot );
			$dto['nodeRefOptions']        = Node_Type::get_node_ref_options_for_slot( $taxonomy, $legacy_slot );
			$dto['nodeRefCreateFields']   = Composition::get_node_ref_create_fields( $taxonomy, $scope_id );
		}

		if ( 'date' === strtolower( $type_key ) ) {
			if ( isset( $row['dateConfig'] ) && is_array( $row['dateConfig'] ) ) {
				$dto['dateConfig'] = $row['dateConfig'];
			} elseif ( '' !== $taxonomy ) {
				$cfg_id            = $legacy_slot > 0 ? $legacy_slot : $type_id;
				$cfg               = $cfg_id > 0 ? Node_Type::get_date_config_for_node( $taxonomy, $cfg_id ) : null;
				$dto['dateConfig'] = $cfg ? $cfg : array( 'mode' => 'date' );
			}
		}

		if ( 'int' === strtolower( $type_key ) || 'integer' === strtolower( $type_key ) ) {
			if ( isset( $row['preferredConverter'] ) && is_string( $row['preferredConverter'] ) && '' !== $row['preferredConverter'] ) {
				$dto['preferredConverter'] = Node_Type::normalize_preferred_converter( (string) $row['preferredConverter'] );
			}
			if ( isset( $row['intConfig'] ) && is_array( $row['intConfig'] ) ) {
				$dto['intConfig']     = $row['intConfig'];
				$dto['displayFormat'] = isset( $row['intConfig']['displayFormat'] )
					? Int_Value::normalize_format_id( (string) $row['intConfig']['displayFormat'] )
					: Int_Value::DEFAULT_FORMAT;
			} elseif ( isset( $row['displayFormat'] ) ) {
				$dto['displayFormat'] = Int_Value::normalize_format_id( (string) $row['displayFormat'] );
				$dto['intConfig']     = array( 'displayFormat' => $dto['displayFormat'] );
			} elseif ( '' !== $taxonomy ) {
				$cfg_term = $type_id > 0 ? $type_id : $legacy_slot;
				$cfg      = $cfg_term > 0 ? Node_Type::get_int_config_for_node( $taxonomy, $cfg_term ) : null;
				$fmt      = is_array( $cfg ) && isset( $cfg['displayFormat'] )
					? Int_Value::normalize_format_id( (string) $cfg['displayFormat'] )
					: Int_Value::DEFAULT_FORMAT;
				$dto['intConfig']     = array( 'displayFormat' => $fmt );
				$dto['displayFormat'] = $fmt;
			}
			if ( empty( $dto['preferredConverter'] ) && ! empty( $dto['displayFormat'] ) ) {
				$dto['preferredConverter'] = (string) $dto['displayFormat'];
			}
		}

		if ( 'media' === strtolower( $type_key ) && '' !== $taxonomy ) {
			$cfg = null;
			if ( $type_id > 0 ) {
				$cfg = Node_Type::get_media_config_for_node( $taxonomy, $type_id );
			}
			if ( null === $cfg && $legacy_slot > 0 ) {
				$cfg = Node_Type::get_media_config_for_node( $taxonomy, $legacy_slot );
			}
			$dto['mediaConfig'] = is_array( $cfg )
				? $cfg
				: array(
					'allowUpload'  => true,
					'allowUrl'     => false,
					'allowedKinds' => array(),
				);
		}

		if ( isset( $row['quantitySchema'] ) && is_array( $row['quantitySchema'] ) ) {
			$dto['quantitySchema'] = $row['quantitySchema'];
		} else {
			$dto['quantitySchema'] = null;
		}

		if ( isset( $row['typeExtras'] ) && is_array( $row['typeExtras'] ) ) {
			$dto['typeExtras'] = $row['typeExtras'];
		}
		if ( ! empty( $row['computed'] ) || ( isset( $row['compute'] ) && is_array( $row['compute'] ) ) ) {
			$dto['computed'] = true;
			$dto['compute']  = isset( $row['compute'] ) && is_array( $row['compute'] ) ? $row['compute'] : null;
			$dto['readonly'] = true;
		}

		/*
		 * Mult > 1 → list of the attribute's type. When the type itself has attributes
		 * (structure), those become Table(n) columns. No recursive nesting of typeProperties.
		 */
		if ( $with_type_schema && '' !== $taxonomy ) {
			$type_id = (int) ( $row['typeId'] ?? 0 );
			if ( $type_id > 0 && Attribute::type_has_attributes( $taxonomy, $type_id ) ) {
				foreach ( Attribute::list( $taxonomy, $type_id ) as $child_row ) {
					if ( ! is_array( $child_row ) || ! empty( $child_row['hidden'] ) ) {
						continue;
					}
					$dto['typeProperties'][] = self::property_dto( $child_row, $taxonomy, false );
				}
			}
		}

		/* Q97: Mult many + structured type → related Model_Data rows (links[]), not host blob. */
		if ( '' !== $taxonomy && Model_Data::is_related_dataset_attr( $taxonomy, $row ) ) {
			$dto['isRelatedDataset'] = true;
		}

		return $dto;
	}

	/**
	 * SSR / dynamic block HTML.
	 * Default (form/auto): node meta strip → Form for Mult≤1 → Table for Mult many.
	 *
	 * @param array<string, mixed> $attributes Block attributes (termId, taxonomy, instanceId, layout, renderDepth, referenceMode).
	 */
	public static function render_html( array $attributes ): string {
		$term_id     = isset( $attributes['termId'] ) ? (int) $attributes['termId'] : 0;
		$taxonomy    = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : '';
		$instance_id = isset( $attributes['instanceId'] ) ? sanitize_key( (string) $attributes['instanceId'] ) : '';
		$raw_layout  = isset( $attributes['layout'] ) ? (string) $attributes['layout'] : 'auto';
		$depth       = self::normalize_render_depth(
			array_key_exists( 'renderDepth', $attributes )
				? $attributes['renderDepth']
				: Settings::default_render_depth()
		);
		$ref_mode    = self::normalize_reference_mode( isset( $attributes['referenceMode'] ) ? (string) $attributes['referenceMode'] : 'link' );
		$view        = $term_id > 0 ? self::get_view( $taxonomy, $term_id ) : null;
		if ( null !== $view && '' !== $instance_id ) {
			$view = self::with_instance_values( $view, $instance_id );
		}
		$layout = self::resolve_layout( $raw_layout, $view );
		$i18n = self::i18n();

		self::enqueue_assets();

		ob_start();
		echo '<div class="wtt-object-view wtt-object-view--layout-' . esc_attr( $layout ) .
			' wtt-object-view--depth-' . esc_attr( (string) $depth ) .
			' wtt-object-view--ref-' . esc_attr( $ref_mode ) . '"';
		echo ' data-wtt-render-depth="' . esc_attr( (string) $depth ) . '"';
		echo ' data-wtt-reference-mode="' . esc_attr( $ref_mode ) . '">';

		if ( null === $view ) {
			$msg = $term_id > 0 ? $i18n['notFound'] : $i18n['empty'];
			echo '<p class="wtt-object-view__empty">' . esc_html( $msg ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<header class="wtt-object-view__header">';
		echo '<h3 class="wtt-object-view__title">' . esc_html( (string) $view['name'] ) . '</h3>';
		if ( '' !== (string) $view['path'] ) {
			echo '<p class="wtt-object-view__path">' . esc_html( (string) $view['path'] ) . '</p>';
		}
		echo '</header>';

		self::echo_meta_strip( $view, $i18n );

		/* Depth 0 = meta-only (header + pills). */
		if ( $depth < 1 ) {
			echo '</div>';
			return (string) ob_get_clean();
		}

		$properties = isset( $view['properties'] ) && is_array( $view['properties'] )
			? $view['properties']
			: array();
		$properties = self::enrich_property_samples( $properties );
		$render_ctx = array(
			'renderDepth'   => $depth,
			'referenceMode' => $ref_mode,
			'remainingDepth'=> max( 0, $depth - 1 ),
		);

		echo '<section class="wtt-object-view__properties" aria-label="' . esc_attr( $i18n['properties'] ) . '">';

		if ( array() === $properties ) {
			echo '<p class="wtt-object-view__empty">' . esc_html( $i18n['noProperties'] ) . '</p>';
		} else {
			$parts  = self::partition_properties( $properties );
			$single = $parts['single'];
			$many   = $parts['many'];

			/* Singles follow layout; multi-value attributes always render as a table. */
			if ( array() !== $single ) {
				echo '<h4 class="wtt-object-view__section-title">' . esc_html( $i18n['properties'] ) . '</h4>';
				if ( Renderer::Table->value === $layout ) {
					self::echo_properties_table( $single, $i18n, $render_ctx );
				} elseif ( Renderer::Compact->value === $layout || Renderer::CompactVertical->value === $layout ) {
					self::echo_properties_compact( $single, $i18n, $layout, $render_ctx );
				} elseif ( Renderer::Embedded->value === $layout ) {
					/* Interactive pick+fill is JS; SSR falls back to compact of host attrs. */
					self::echo_properties_compact( $single, $i18n, Renderer::Compact->value, $render_ctx );
				} else {
					echo '<div class="wtt-object-view__form" role="list">';
					foreach ( $single as $prop ) {
						self::echo_property_row( $prop, $i18n, $render_ctx );
					}
					echo '</div>';
				}
			}

			if ( array() !== $many ) {
				echo '<h4 class="wtt-object-view__section-title">' . esc_html(
					$i18n['propertiesMany'] ?? __( 'Multi-value attributes', 'wp-taxonomy-tree' )
				) . '</h4>';
				self::echo_many_properties_table( $many, $i18n, $render_ctx );
			}

			if ( array() === $single && array() === $many ) {
				echo '<p class="wtt-object-view__empty">' . esc_html( $i18n['noProperties'] ) . '</p>';
			}
		}
		echo '</section>';

		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Readonly node Meta pills — same chrome as admin `.wtt-form__meta-strip--static`.
	 * Chips: ID, Parent, Slug, Last modified by/at (+ Type when present).
	 *
	 * @param array<string, mixed>  $view View DTO.
	 * @param array<string, string> $i18n Strings.
	 */
	private static function echo_meta_strip( array $view, array $i18n ): void {
		$none = $i18n['none'] ?? '—';
		$chips = array();

		$id = isset( $view['id'] ) ? (int) $view['id'] : 0;
		if ( $id > 0 ) {
			$chips[] = array(
				'label' => $i18n['termId'] ?? __( 'ID', 'wp-taxonomy-tree' ),
				'value' => (string) $id,
				'key'   => 'id',
			);
		}

		$parent_id   = isset( $view['parent'] ) ? (int) $view['parent'] : 0;
		$parent_name = (string) ( $view['parentName'] ?? '' );
		$parent_val  = $parent_id > 0
			? ( '' !== $parent_name ? $parent_name : '#' . $parent_id )
			: $none;
		$chips[] = array(
			'label' => $i18n['parent'] ?? __( 'Parent', 'wp-taxonomy-tree' ),
			'value' => $parent_val,
			'key'   => 'parent',
		);

		$slug = (string) ( $view['slug'] ?? '' );
		$chips[] = array(
			'label' => $i18n['slug'] ?? __( 'Slug', 'wp-taxonomy-tree' ),
			'value' => '' !== $slug ? $slug : $none,
			'key'   => 'slug',
		);

		$type = (string) ( $view['typeName'] ?? '' );
		if ( '' !== $type ) {
			$chips[] = array(
				'label' => $i18n['type'] ?? __( 'Type', 'wp-taxonomy-tree' ),
				'value' => $type,
				'key'   => 'type',
			);
		}

		$modified = isset( $view['modified'] ) && is_array( $view['modified'] ) ? $view['modified'] : null;
		if ( is_array( $modified ) && ( ! empty( $modified['userName'] ) || ! empty( $modified['atLabel'] ) ) ) {
			$mod_by = (string) ( $modified['userName'] ?? '' );
			if ( '' === $mod_by && ! empty( $modified['userId'] ) ) {
				$mod_by = '#' . (int) $modified['userId'];
			}
			if ( '' === $mod_by ) {
				$mod_by = $none;
			}
			$at_label = (string) ( $modified['atLabel'] ?? '' );
			$chips[]  = array(
				'label' => $i18n['lastModifiedBy'] ?? __( 'Last modified by', 'wp-taxonomy-tree' ),
				'value' => $mod_by,
				'title' => '' !== $at_label
					? ( ( $i18n['lastModifiedAt'] ?? __( 'Last modified', 'wp-taxonomy-tree' ) ) . ': ' . $at_label )
					: '',
				'key'   => 'modifiedBy',
			);
			if ( '' !== $at_label ) {
				$chips[] = array(
					'label' => $i18n['lastModifiedAt'] ?? __( 'Last modified', 'wp-taxonomy-tree' ),
					'value' => $at_label,
					'key'   => 'modifiedAt',
				);
			}
		}

		if ( array() === $chips ) {
			return;
		}

		$aria = $i18n['nodeMeta'] ?? __( 'Meta', 'wp-taxonomy-tree' );
		echo '<div class="wtt-object-view__meta wtt-object-view__meta--pills" role="group" aria-label="' . esc_attr( $aria ) . '">';
		echo '<div class="wtt-form__meta-strip wtt-form__meta-strip--static wtt-object-view__meta-strip">';
		foreach ( $chips as $chip ) {
			$label = (string) ( $chip['label'] ?? '' );
			$value = (string) ( $chip['value'] ?? $none );
			$title = (string) ( $chip['title'] ?? '' );
			$key   = (string) ( $chip['key'] ?? '' );
			$text  = '' !== $label ? $label . ': ' . $value : $value;
			echo '<span class="wtt-form__meta-static"';
			if ( '' !== $key ) {
				echo ' data-wtt-meta="' . esc_attr( $key ) . '"';
			}
			if ( '' !== $title ) {
				echo ' title="' . esc_attr( $title ) . '"';
			}
			echo '>' . esc_html( $text ) . '</span>';
		}
		echo '</div></div>';
	}

	/**
	 * Whether a property typeKey resolves to media.
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 */
	private static function is_media_prop( array $prop ): bool {
		$type_key = strtolower( trim( (string) ( $prop['typeKey'] ?? $prop['typeName'] ?? '' ) ) );
		if ( false !== strpos( $type_key, '/' ) ) {
			$parts    = array_map( 'trim', explode( '/', $type_key ) );
			$type_key = strtolower( (string) end( $parts ) );
		}
		return 'media' === $type_key;
	}

	/**
	 * Echo a typed property value (media via Media_Render; refs via referenceMode; never dump raw JSON).
	 *
	 * @param array<string, mixed>  $prop  Property DTO.
	 * @param string                $raw   Store or display string.
	 * @param array<string, string> $i18n  Strings.
	 * @param bool                  $compact Compact media chrome.
	 * @param array<string, mixed>  $ctx     renderDepth / referenceMode / remainingDepth.
	 */
	private static function echo_typed_value( array $prop, string $raw, array $i18n, bool $compact = false, array $ctx = array() ): void {
		$raw = trim( $raw );
		if ( self::is_structure_prop( $prop ) ) {
			self::echo_structure_value( $prop, $raw, $i18n, $ctx );
			return;
		}
		if ( '' === $raw ) {
			echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ) . '</span>';
			return;
		}
		if ( self::is_media_prop( $prop ) ) {
			$ref = self::parse_media_ref( $raw );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Media_Render::render_html escapes.
			echo Media_Render::render_html( $ref, array( 'compact' => $compact ) );
			return;
		}
		$type_key = strtolower( trim( (string) ( $prop['typeKey'] ?? $prop['typeName'] ?? '' ) ) );
		if ( false !== strpos( $type_key, '/' ) ) {
			$parts    = array_map( 'trim', explode( '/', $type_key ) );
			$type_key = strtolower( (string) end( $parts ) );
		}
		if ( 'date' === $type_key || 'datetime' === $type_key ) {
			$mode = 'date';
			if ( isset( $prop['dateConfig']['mode'] ) ) {
				$mode = Node_Type::normalize_date_mode( (string) $prop['dateConfig']['mode'] );
			}
			$ts        = Node_Type::parse_date_store_value( $raw );
			$formatted = $ts > 0 ? Node_Type::format_date_store_value( $ts, $mode ) : '';
			if ( '' === $formatted ) {
				echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ) . '</span>';
				return;
			}
			echo esc_html( $formatted );
			return;
		}
		if ( self::is_reference_prop( $prop ) ) {
			self::echo_reference_value( $prop, $raw, $i18n, $ctx );
			return;
		}
		echo esc_html( $raw );
	}

	/**
	 * Type carries its own attributes → structure (Form/Table embed), not CatalogChoice.
	 * Honor fixedMode=catalog (Bauformen etc. may still expose typeProperties for heirs).
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 */
	private static function is_structure_prop( array $prop ): bool {
		if ( 'catalog' === strtolower( (string) ( $prop['fixedMode'] ?? '' ) ) ) {
			return false;
		}
		$tp = isset( $prop['typeProperties'] ) && is_array( $prop['typeProperties'] )
			? $prop['typeProperties']
			: array();
		return array() !== $tp;
	}

	/**
	 * SSR display for a structured attribute value (type schema fields).
	 *
	 * @param array<string, mixed>  $prop Property DTO.
	 * @param string                $raw  Store string.
	 * @param array<string, string> $i18n Strings.
	 * @param array<string, mixed>  $ctx  Render context.
	 */
	private static function echo_structure_value( array $prop, string $raw, array $i18n, array $ctx ): void {
		$columns = isset( $prop['typeProperties'] ) && is_array( $prop['typeProperties'] )
			? $prop['typeProperties']
			: array();
		if ( array() === $columns ) {
			echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ) . '</span>';
			return;
		}
		$row_vals = self::many_row_values_from_store( $columns, $raw );
		echo '<div class="wtt-object-view__structure-embed" role="list">';
		foreach ( $columns as $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}
			$col_id   = isset( $col['id'] ) ? (string) $col['id'] : (string) ( $col['name'] ?? '' );
			$col_name = (string) ( $col['name'] ?? '' );
			$cell     = isset( $row_vals[ $col_id ] ) ? (string) $row_vals[ $col_id ] : '';
			echo '<div class="wtt-object-view__row" role="listitem">';
			echo '<div class="wtt-object-view__label"><span class="wtt-object-view__label-text">' .
				esc_html( '' !== $col_name ? $col_name : '—' ) .
				'</span></div>';
			echo '<div class="wtt-object-view__value">';
			self::echo_typed_value( $col, $cell, $i18n, true, $ctx );
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Whether the property stores a node id reference (node_ref or CatalogChoice).
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 */
	private static function is_reference_prop( array $prop ): bool {
		if ( self::is_structure_prop( $prop ) ) {
			return false;
		}
		$type_key = strtolower( trim( (string) ( $prop['typeKey'] ?? $prop['typeName'] ?? '' ) ) );
		if ( false !== strpos( $type_key, '/' ) ) {
			$parts    = array_map( 'trim', explode( '/', $type_key ) );
			$type_key = strtolower( (string) end( $parts ) );
		}
		if ( 'node_ref' === $type_key || 'node_embed' === $type_key || 'node_pick' === $type_key ) {
			return true;
		}
		if ( 'catalog' === strtolower( (string) ( $prop['fixedMode'] ?? '' ) ) ) {
			return true;
		}
		$opts = isset( $prop['fixedOptions'] ) && is_array( $prop['fixedOptions'] ) ? $prop['fixedOptions'] : array();
		$refs = isset( $prop['nodeRefOptions'] ) && is_array( $prop['nodeRefOptions'] ) ? $prop['nodeRefOptions'] : array();
		return array() !== $opts || array() !== $refs;
	}

	/**
	 * Render a reference value according to referenceMode (SSR / frontend display).
	 *
	 * @param array<string, mixed>  $prop Property DTO.
	 * @param string                $raw  Store value (id or comma-ids).
	 * @param array<string, string> $i18n Strings.
	 * @param array<string, mixed>  $ctx  Render context.
	 */
	private static function echo_reference_value( array $prop, string $raw, array $i18n, array $ctx ): void {
		$mode = self::normalize_reference_mode( isset( $ctx['referenceMode'] ) ? (string) $ctx['referenceMode'] : 'link' );
		if ( 'none' === $mode ) {
			echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ) . '</span>';
			return;
		}

		$label = self::reference_display_label( $prop, $raw );
		if ( '' === $label ) {
			$label = $raw;
		}

		if ( 'summary' === $mode || 'embed' === $mode ) {
			$type_name = (string) ( $prop['typeName'] ?? $prop['typeKey'] ?? '' );
			$summary   = $label;
			if ( '' !== $type_name ) {
				$summary .= ' · ' . $type_name;
			}
			/* Full nested Object View embed (depth≥2) is deferred — fall back to summary chrome. */
			$class = 'embed' === $mode
				? 'wtt-object-view__ref wtt-object-view__ref--embed-stub'
				: 'wtt-object-view__ref wtt-object-view__ref--summary';
			$title = 'embed' === $mode
				? (string) ( $i18n['referenceEmbedDeferred'] ?? '' )
				: '';
			echo '<span class="' . esc_attr( $class ) . '"';
			if ( '' !== $title ) {
				echo ' title="' . esc_attr( $title ) . '"';
			}
			echo '>' . esc_html( $summary ) . '</span>';
			return;
		}

		/* link (default): name/path as plain text. */
		echo '<span class="wtt-object-view__ref wtt-object-view__ref--link">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Resolve a human label for a stored reference id (or comma-list).
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 * @param string               $raw  Store value.
	 */
	private static function reference_display_label( array $prop, string $raw ): string {
		$opts = array();
		if ( isset( $prop['nodeRefOptions'] ) && is_array( $prop['nodeRefOptions'] ) ) {
			$opts = $prop['nodeRefOptions'];
		} elseif ( isset( $prop['fixedOptions'] ) && is_array( $prop['fixedOptions'] ) ) {
			$opts = $prop['fixedOptions'];
		}
		$ids = preg_split( '/\s*,\s*/', trim( $raw ) ) ?: array();
		$labels = array();
		foreach ( $ids as $id_raw ) {
			$id = (string) absint( $id_raw );
			if ( '' === $id || '0' === $id ) {
				if ( '' !== trim( (string) $id_raw ) ) {
					$labels[] = (string) $id_raw;
				}
				continue;
			}
			$found = '';
			foreach ( $opts as $opt ) {
				if ( ! is_array( $opt ) ) {
					continue;
				}
				if ( (string) (int) ( $opt['id'] ?? 0 ) === $id ) {
					$found = (string) ( $opt['path'] ?? $opt['name'] ?? $id );
					break;
				}
			}
			$labels[] = '' !== $found ? $found : '#' . $id;
		}
		return implode( ', ', $labels );
	}

	/**
	 * Clamp render depth (0 = meta-only; 1 = attributes; 2+ reserved for nest).
	 *
	 * @param mixed $raw Raw attribute.
	 */
	public static function normalize_render_depth( $raw ): int {
		$n = is_numeric( $raw ) ? (int) $raw : 1;
		if ( $n < 0 ) {
			$n = 0;
		}
		if ( $n > 5 ) {
			$n = 5;
		}
		return $n;
	}

	/**
	 * @param string $mode Raw referenceMode.
	 */
	public static function normalize_reference_mode( string $mode ): string {
		$key = strtolower( trim( $mode ) );
		if ( in_array( $key, array( 'none', 'link', 'summary', 'embed' ), true ) ) {
			return $key;
		}
		return 'link';
	}

	/**
	 * Prefer instance store string, then valueLabel, then first values[].
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 */
	private static function property_raw_value( array $prop ): string {
		if ( ! empty( $prop['hasInstanceValue'] ) && isset( $prop['values'] ) && is_array( $prop['values'] ) && array() !== $prop['values'] ) {
			return (string) $prop['values'][0];
		}
		$label = (string) ( $prop['valueLabel'] ?? '' );
		if ( '' !== $label && ! self::is_media_prop( $prop ) ) {
			return $label;
		}
		if ( isset( $prop['values'] ) && is_array( $prop['values'] ) && array() !== $prop['values'] ) {
			return (string) $prop['values'][0];
		}
		return $label;
	}

	/**
	 * @param list<array<string, mixed>> $properties Properties.
	 * @return array{single:list<array<string,mixed>>,many:list<array<string,mixed>>}
	 */
	private static function partition_properties( array $properties ): array {
		$single = array();
		$many   = array();
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$mult = (string) ( $prop['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY );
			if ( ! empty( $prop['allowsMany'] ) || Attribute::multiplicity_allows_many( $mult ) ) {
				$many[] = $prop;
			} else {
				$single[] = $prop;
			}
		}
		return array(
			'single' => $single,
			'many'   => $many,
		);
	}

	/**
	 * Fill empty valueLabel from Sample_Data for preview/SSR.
	 *
	 * @param list<array<string, mixed>> $properties Properties.
	 * @return list<array<string, mixed>>
	 */
	private static function enrich_property_samples( array $properties ): array {
		$out = array();
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			/* Instance values already set — do not overwrite with samples. */
			if ( ! empty( $prop['hasInstanceValue'] ) ) {
				$out[] = $prop;
				continue;
			}
			$label = (string) ( $prop['valueLabel'] ?? '' );
			if ( '' === $label && isset( $prop['values'] ) && is_array( $prop['values'] ) && array() !== $prop['values'] ) {
				$label = (string) $prop['values'][0];
			}
			if ( '' === $label ) {
				$label = Sample_Data::for_attribute( $prop );
			}
			/* Never leave raw media JSON as the only display string. */
			if ( self::is_media_prop( $prop ) && '' !== $label && '{' === $label[0] ) {
				$prop['values']     = array( $label );
				$prop['valueLabel'] = self::display_label_for_prop( $prop, $label );
			} else {
				$prop['valueLabel'] = $label;
			}
			$out[] = $prop;
		}
		return $out;
	}

	/**
	 * Multiplicity > 1 = list of the attribute's type → plain Table(n) render.
	 * Columns = type attributes when the type is structured; otherwise one column = the field.
	 *
	 * @param list<array<string, mixed>> $properties Many-valued properties.
	 * @param array<string, string>       $i18n       Strings.
	 * @param array<string, mixed>        $ctx        Render context.
	 */
	private static function echo_many_properties_table( array $properties, array $i18n, array $ctx = array() ): void {
		echo '<div class="wtt-object-view__many-stack">';
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$type_props = isset( $prop['typeProperties'] ) && is_array( $prop['typeProperties'] )
				? $prop['typeProperties']
				: array();
			$columns    = array() !== $type_props ? $type_props : array( $prop );

			$name = (string) ( $prop['name'] ?? '' );
			echo '<div class="wtt-object-view__many-item">';
			if ( '' !== $name ) {
				echo '<h5 class="wtt-object-view__many-title">' . esc_html( $name ) . '</h5>';
			}
			echo '<div class="wtt-object-view__table-wrap">';
			echo '<table class="wtt-object-view__table wtt-object-render__table">';
			echo '<thead><tr>';
			foreach ( $columns as $col ) {
				if ( ! is_array( $col ) ) {
					continue;
				}
				$col_name = (string) ( $col['name'] ?? '' );
				echo '<th scope="col">' . esc_html( '' !== $col_name ? $col_name : '—' ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			/* Prefer related Model_Data rows (Q97 BOM) over inline Mult many store blobs. */
			$related = isset( $prop['relatedInstances'] ) && is_array( $prop['relatedInstances'] )
				? $prop['relatedInstances']
				: array();
			$uses_related = ! empty( $prop['usesRelatedInstances'] )
				|| ! empty( $prop['isRelatedDataset'] )
				|| array() !== $related;
			if ( $uses_related ) {
				if ( array() === $related ) {
					echo '<tr><td colspan="' . esc_attr( (string) max( 1, count( $columns ) ) ) . '">';
					echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ?? '—' ) . '</span>';
					echo '</td></tr>';
				} else {
					foreach ( $related as $row_inst ) {
						if ( ! is_array( $row_inst ) ) {
							continue;
						}
						$row_vals = isset( $row_inst['values'] ) && is_array( $row_inst['values'] )
							? $row_inst['values']
							: array();
						echo '<tr>';
						foreach ( $columns as $col ) {
							if ( ! is_array( $col ) ) {
								continue;
							}
							$col_id = isset( $col['id'] ) ? (string) $col['id'] : (string) ( $col['name'] ?? '' );
							$cell   = isset( $row_vals[ $col_id ] ) ? (string) $row_vals[ $col_id ] : '';
							echo '<td>';
							self::echo_typed_value( $col, $cell, $i18n, true, $ctx );
							echo '</td>';
						}
						echo '</tr>';
					}
				}
			} else {
				$raw_rows = self::many_prop_store_values( $prop );
				if ( array() === $raw_rows ) {
					$raw_rows = array( '' );
				}
				foreach ( $raw_rows as $raw ) {
					$row_vals = self::many_row_values_from_store( $columns, (string) $raw );
					echo '<tr>';
					foreach ( $columns as $col ) {
						if ( ! is_array( $col ) ) {
							continue;
						}
						$col_id = isset( $col['id'] ) ? (string) $col['id'] : (string) ( $col['name'] ?? '' );
						$cell   = isset( $row_vals[ $col_id ] ) ? (string) $row_vals[ $col_id ] : '';
						echo '<td>';
						self::echo_typed_value( $col, $cell, $i18n, true, $ctx );
						echo '</td>';
					}
					echo '</tr>';
				}
			}
			echo '</tbody></table></div></div>';
		}
		echo '</div>';
	}

	/**
	 * Decode one list-row store string into column id → value.
	 *
	 * @param list<array<string, mixed>> $columns Column property DTOs.
	 * @param string                     $raw     Store string.
	 * @return array<string, string>
	 */
	private static function many_row_values_from_store( array $columns, string $raw ): array {
		$raw = trim( $raw );
		$out = array();
		if ( '' === $raw ) {
			return $out;
		}
		if ( '{' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$keys    = array_keys( $decoded );
				$is_list = $keys === array_keys( $keys );
				if ( ! $is_list ) {
					foreach ( $decoded as $k => $v ) {
						$out[ (string) $k ] = is_scalar( $v ) || null === $v ? (string) $v : '';
					}
					return $out;
				}
			}
		}
		if ( 1 === count( $columns ) ) {
			$col    = $columns[0];
			$col_id = isset( $col['id'] ) ? (string) $col['id'] : (string) ( $col['name'] ?? '' );
			if ( '' !== $col_id ) {
				$out[ $col_id ] = $raw;
			}
		}
		return $out;
	}

	/**
	 * Store strings for a many-valued property.
	 * Media: keep JSON/ref store values; do not substitute display-only valueLabel (filename).
	 *
	 * @param array<string, mixed> $prop Property DTO.
	 * @return list<string>
	 */
	private static function many_prop_store_values( array $prop ): array {
		$vals = isset( $prop['values'] ) && is_array( $prop['values'] ) ? $prop['values'] : array();
		$out  = array();
		foreach ( $vals as $v ) {
			$out[] = is_scalar( $v ) ? (string) $v : '';
		}
		if ( array() === $out ) {
			$label = (string) ( $prop['valueLabel'] ?? '' );
			if ( '' !== $label ) {
				if ( ! self::is_media_prop( $prop ) || ( isset( $label[0] ) && '{' === $label[0] ) ) {
					$out[] = $label;
				}
			}
		}
		return $out;
	}

	/**
	 * Resolve block layout: auto/empty → node preferredRender; else explicit override.
	 *
	 * @param array<string, mixed>|null $view View DTO.
	 */
	public static function resolve_layout( string $layout, ?array $view ): string {
		$key = strtolower( trim( $layout ) );
		if ( '' === $key || 'auto' === $key ) {
			$preferred = is_array( $view ) && isset( $view['preferredRender'] )
				? (string) $view['preferredRender']
				: Renderer::Form->value;
			return self::normalize_layout( $preferred );
		}
		return self::normalize_layout( $key );
	}

	/**
	 * Normalize to object-layout wire id (Q113). Legacy form|table|embed accepted.
	 *
	 * @param string $layout Raw layout attribute.
	 */
	private static function normalize_layout( string $layout ): string {
		$found = Renderer::try_from_legacy( $layout );
		if ( $found instanceof Renderer && $found->is_object_layout() ) {
			return $found->value;
		}
		return Renderer::Form->value;
	}

	/**
	 * @param list<array<string, mixed>> $properties Properties.
	 * @param array<string, string>       $i18n       Strings.
	 * @param array<string, mixed>        $ctx        Render context.
	 */
	private static function echo_properties_table( array $properties, array $i18n, array $ctx = array() ): void {
		echo '<div class="wtt-object-view__table-wrap"><table class="wtt-object-view__table">';
		echo '<thead><tr>';
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$name = (string) ( $prop['name'] ?? '' );
			echo '<th scope="col">' . esc_html( '' !== $name ? $name : '—' ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$value = self::property_raw_value( $prop );
			echo '<td>';
			self::echo_typed_value( $prop, $value, $i18n, true, $ctx );
			echo '</td>';
		}
		echo '</tr></tbody></table></div>';
	}

	/**
	 * @param list<array<string, mixed>> $properties Properties.
	 * @param array<string, string>       $i18n       Strings.
	 * @param string                      $layout     CompactRenderer|CompactVerticalRenderer (legacy compact* accepted).
	 * @param array<string, mixed>        $ctx        Render context.
	 */
	private static function echo_properties_compact( array $properties, array $i18n, string $layout, array $ctx = array() ): void {
		$layout = self::normalize_layout( $layout );
		$orient = Renderer::CompactVertical->value === $layout ? 'vertical' : 'horizontal';
		echo '<div class="wtt-object-view__compact wtt-object-view__compact--' . esc_attr( $orient ) . '">';
		foreach ( $properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$name  = (string) ( $prop['name'] ?? '' );
			$value = self::property_raw_value( $prop );
			echo '<div class="wtt-object-view__compact-field">';
			echo '<span class="wtt-object-view__compact-label">' . esc_html( '' !== $name ? $name : '—' ) . '</span>';
			echo '<span class="wtt-object-view__compact-value">';
			self::echo_typed_value( $prop, $value, $i18n, true, $ctx );
			echo '</span></div>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed>  $prop Property DTO.
	 * @param array<string, string> $i18n Strings.
	 * @param array<string, mixed>  $ctx  Render context.
	 */
	private static function echo_property_row( array $prop, array $i18n, array $ctx = array() ): void {
		$name       = (string) ( $prop['name'] ?? '' );
		$type_name  = (string) ( $prop['typeName'] ?? '' );
		$value      = self::property_raw_value( $prop );
		$inherited  = ! empty( $prop['inherited'] );
		$mult       = (string) ( $prop['multiplicity'] ?? '' );
		$label      = $name;
		if ( '' !== $type_name ) {
			$label .= ' (' . $type_name . ')';
		}

		echo '<div class="wtt-object-view__row" role="listitem">';
		echo '<div class="wtt-object-view__label">';
		echo '<span class="wtt-object-view__label-text">' . esc_html( $label ) . '</span>';
		if ( $inherited ) {
			$from = (string) ( $prop['definedOnName'] ?? '' );
			$title = '' !== $from
				? sprintf(
					/* translators: %s: ancestor node name */
					__( 'Inherited from %s', 'wp-taxonomy-tree' ),
					$from
				)
				: $i18n['inherited'];
			echo ' <span class="wtt-object-view__badge" title="' . esc_attr( $title ) . '">' . esc_html( $i18n['inherited'] ) . '</span>';
		}
		if ( '' !== $mult ) {
			echo ' <span class="wtt-object-view__mult" title="' . esc_attr( $i18n['multiplicity'] ) . '">' . esc_html( $mult ) . '</span>';
		}
		echo '</div>';
		echo '<div class="wtt-object-view__value">';
		self::echo_typed_value( $prop, $value, $i18n, false, $ctx );
		echo '</div>';
		echo '</div>';
	}

	private static function echo_meta_row( string $label, string $value ): void {
		echo '<div class="wtt-object-view__meta-row">';
		echo '<dt class="wtt-object-view__meta-label">' . esc_html( $label ) . '</dt>';
		echo '<dd class="wtt-object-view__meta-value">' . esc_html( $value ) . '</dd>';
		echo '</div>';
	}
}
