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
			'empty'              => __( 'Select a node in the sidebar.', 'wp-taxonomy-tree' ),
			'notFound'           => __( 'Node not found.', 'wp-taxonomy-tree' ),
			'noProperties'       => __( 'This node has no attributes.', 'wp-taxonomy-tree' ),
			'name'               => __( 'Name', 'wp-taxonomy-tree' ),
			'description'        => __( 'Description', 'wp-taxonomy-tree' ),
			'shortDescription'   => __( 'Short description', 'wp-taxonomy-tree' ),
			'type'               => __( 'Type', 'wp-taxonomy-tree' ),
			'path'               => __( 'Path', 'wp-taxonomy-tree' ),
			'properties'         => __( 'Properties', 'wp-taxonomy-tree' ),
			'inherited'          => __( 'Inherited', 'wp-taxonomy-tree' ),
			'hidden'             => __( 'Hidden', 'wp-taxonomy-tree' ),
			'emptyValue'         => __( '—', 'wp-taxonomy-tree' ),
			'pickTaxonomy'       => __( 'Taxonomy', 'wp-taxonomy-tree' ),
			'pickNode'           => __( 'Node', 'wp-taxonomy-tree' ),
			'pickHint'           => __( 'Bind a taxonomy tree node to display its name, descriptions, and attributes.', 'wp-taxonomy-tree' ),
			'loading'            => __( 'Loading…', 'wp-taxonomy-tree' ),
			'noNodes'            => __( 'No nodes found in this taxonomy.', 'wp-taxonomy-tree' ),
			'multiplicity'       => __( 'Mult.', 'wp-taxonomy-tree' ),
			'binding'            => __( 'Binding', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Enqueue shared CSS/JS for frontend or editor mirror.
	 */
	public static function enqueue_assets(): void {
		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';

		if ( ! wp_script_is( 'wtt-node-render', 'registered' ) ) {
			wp_register_script(
				'wtt-node-render',
				WTT_PLUGIN_URL . 'assets/js/wtt-node-render.js',
				array(),
				$ver,
				true
			);
		}

		wp_enqueue_style(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-object-render.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'wtt-object-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-object-render.js',
			array( 'wtt-node-render' ),
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
	 * Pickable nodes for the block sidebar (flat path list).
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
			$terms = get_terms(
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

		$properties = array();
		foreach ( Attribute::list( $taxonomy, $term_id ) as $row ) {
			if ( ! empty( $row['hidden'] ) ) {
				continue;
			}
			$properties[] = self::property_dto( $row );
		}

		return array(
			'id'               => $term_id,
			'taxonomy'         => $taxonomy,
			'name'             => $term->name,
			'path'             => Composition::term_path( $taxonomy, $term_id ),
			'description'      => Tree_Model::decode_term_description( (string) $term->description ),
			'shortDescription' => Tree_Model::get_short_description( $term_id ),
			'typeId'           => $type_id,
			'typeName'         => $type_name,
			'typeKey'          => $type_key,
			'properties'       => $properties,
		);
	}

	/**
	 * @param array<string, mixed> $row Attribute row from Attribute::list.
	 * @return array<string, mixed>
	 */
	private static function property_dto( array $row ): array {
		$values = array();
		if ( isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ) {
			foreach ( $row['fixedValues'] as $v ) {
				$values[] = (string) $v;
			}
		}

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'name'          => (string) ( $row['name'] ?? '' ),
			'typeId'        => (int) ( $row['typeId'] ?? 0 ),
			'typeName'      => (string) ( $row['typeName'] ?? '' ),
			'typeKey'       => (string) ( $row['typeKey'] ?? '' ),
			'multiplicity'  => (string) ( $row['multiplicity'] ?? Attribute::DEFAULT_MULTIPLICITY ),
			'binding'       => (string) ( $row['binding'] ?? Attribute::DEFAULT_BINDING ),
			'bindingLabel'  => (string) ( $row['bindingLabel'] ?? '' ),
			'inherited'     => ! empty( $row['inherited'] ),
			'definedOnId'   => (int) ( $row['definedOnId'] ?? 0 ),
			'definedOnName' => (string) ( $row['definedOnName'] ?? '' ),
			'valueLabel'    => (string) ( $row['fixedLabel'] ?? '' ),
			'values'        => $values,
		);
	}

	/**
	 * SSR / dynamic block HTML (Form layout).
	 *
	 * @param array<string, mixed> $attributes Block attributes (termId, taxonomy).
	 */
	public static function render_html( array $attributes ): string {
		$term_id  = isset( $attributes['termId'] ) ? (int) $attributes['termId'] : 0;
		$taxonomy = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : '';
		$view     = $term_id > 0 ? self::get_view( $taxonomy, $term_id ) : null;
		$i18n     = self::i18n();

		self::enqueue_assets();

		ob_start();
		echo '<div class="wtt-object-view">';

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

		echo '<dl class="wtt-object-view__meta">';
		self::echo_meta_row( $i18n['name'], (string) $view['name'] );
		if ( '' !== (string) $view['shortDescription'] ) {
			self::echo_meta_row( $i18n['shortDescription'], (string) $view['shortDescription'] );
		}
		if ( '' !== (string) $view['description'] ) {
			self::echo_meta_row( $i18n['description'], (string) $view['description'] );
		}
		if ( '' !== (string) $view['typeName'] ) {
			self::echo_meta_row( $i18n['type'], (string) $view['typeName'] );
		}
		echo '</dl>';

		$properties = isset( $view['properties'] ) && is_array( $view['properties'] )
			? $view['properties']
			: array();

		echo '<section class="wtt-object-view__properties" aria-label="' . esc_attr( $i18n['properties'] ) . '">';
		echo '<h4 class="wtt-object-view__section-title">' . esc_html( $i18n['properties'] ) . '</h4>';

		if ( array() === $properties ) {
			echo '<p class="wtt-object-view__empty">' . esc_html( $i18n['noProperties'] ) . '</p>';
		} else {
			echo '<div class="wtt-object-view__form" role="list">';
			foreach ( $properties as $prop ) {
				if ( ! is_array( $prop ) ) {
					continue;
				}
				self::echo_property_row( $prop, $i18n );
			}
			echo '</div>';
		}
		echo '</section>';

		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $prop Property DTO.
	 * @param array<string, string> $i18n Strings.
	 */
	private static function echo_property_row( array $prop, array $i18n ): void {
		$name       = (string) ( $prop['name'] ?? '' );
		$type_name  = (string) ( $prop['typeName'] ?? '' );
		$value      = (string) ( $prop['valueLabel'] ?? '' );
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
		if ( '' === $value ) {
			echo '<span class="wtt-object-view__empty-value">' . esc_html( $i18n['emptyValue'] ) . '</span>';
		} else {
			echo esc_html( $value );
		}
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
