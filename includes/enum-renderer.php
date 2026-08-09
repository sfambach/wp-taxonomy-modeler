<?php
/**
 * Preferred / Registry renderer ids (Q113).
 *
 * Wire/meta/JS store the string value; PHP uses this backed enum.
 * Catalog bindings stay `builtin.int` (Q96) — separate from Renderer ids.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object layouts and field renderers — one enum, readable wire ids.
 */
enum Renderer: string {
	case Int              = 'IntRenderer';
	case Double           = 'DoubleRenderer';
	case Text             = 'TextRenderer';
	case Textarea         = 'TextareaRenderer';
	case Char             = 'CharRenderer';
	case Bool             = 'BoolRenderer';
	case Email            = 'EmailRenderer';
	case Date             = 'DateRenderer';
	case Media            = 'MediaRenderer';
	case DisplayNodeName  = 'DisplayNodeNameRenderer';
	case Quantity         = 'QuantityRenderer';
	case Unit             = 'UnitRenderer';
	case NodeRef          = 'NodeRefRenderer';
	case Form             = 'FormRenderer';
	case Table            = 'TableRenderer';
	case Compact          = 'CompactRenderer';
	case CompactVertical  = 'CompactVerticalRenderer';
	case Embedded         = 'EmbeddedRenderer';

	/**
	 * Object-view / Preferred layout cases (not scalar field paint).
	 *
	 * @return list<self>
	 */
	public static function object_layouts(): array {
		return array(
			self::Form,
			self::Table,
			self::Compact,
			self::CompactVertical,
			self::Embedded,
		);
	}

	/**
	 * Normalize legacy or alias keys to a Renderer (null if unknown).
	 */
	public static function try_from_legacy( string $raw ): ?self {
		$key = strtolower( trim( $raw ) );
		if ( '' === $key ) {
			return null;
		}

		$direct = self::tryFrom( $raw );
		if ( $direct instanceof self ) {
			return $direct;
		}

		/* Already wire id with wrong case. */
		foreach ( self::cases() as $case ) {
			if ( strtolower( $case->value ) === $key ) {
				return $case;
			}
		}

		$map = array(
			'int'               => self::Int,
			'integer'           => self::Int,
			'double'            => self::Double,
			'float'             => self::Double,
			'text'              => self::Text,
			'textarea'          => self::Textarea,
			'char'              => self::Char,
			'bool'              => self::Bool,
			'boolean'           => self::Bool,
			'email'             => self::Email,
			'date'              => self::Date,
			'datetime'          => self::Date,
			'media'             => self::Media,
			'display_node_name' => self::DisplayNodeName,
			'quantity'          => self::Quantity,
			'unit'              => self::Unit,
			'basiseinheit'      => self::Unit,
			'node_ref'          => self::NodeRef,
			'form'              => self::Form,
			'table'             => self::Table,
			'list'              => self::Table,
			'compact'           => self::Compact,
			'compact-horizontal'=> self::Compact,
			'compact-h'         => self::Compact,
			'compact-vertical'  => self::CompactVertical,
			'compact-v'         => self::CompactVertical,
			'embed'             => self::Embedded,
			'pick-fill'         => self::Embedded,
			'pick_fill'         => self::Embedded,
			'compact-embed'     => self::Embedded,
		);

		return $map[ $key ] ?? null;
	}

	/**
	 * Normalize to wire id; unknown → FormRenderer.
	 */
	public static function normalize( string $raw ): string {
		$found = self::try_from_legacy( $raw );
		return $found instanceof self ? $found->value : self::Form->value;
	}

	/**
	 * Whether this is an object layout (vs field renderer).
	 */
	public function is_object_layout(): bool {
		return in_array( $this, self::object_layouts(), true );
	}
}
