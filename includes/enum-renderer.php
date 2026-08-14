<?php
/**
 * Preferred / Registry renderer ids (Q113).
 *
 * Wire/meta/JS store the string value; PHP uses this backed enum.
 * Catalog bindings stay `builtin.int` (Q96) — separate from Renderer ids.
 *
 * Chrome variants (int_spinner, bool_checkbox, …) are Preferred siblings for the
 * same type — not a second Settings “control” field.
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
	case IntSpinner       = 'IntSpinnerRenderer';
	case IntRange         = 'IntRangeRenderer';
	case Double           = 'DoubleRenderer';
	case DoubleSpinner    = 'DoubleSpinnerRenderer';
	case DoubleRange      = 'DoubleRangeRenderer';
	case Text             = 'TextRenderer';
	case Textarea         = 'TextareaRenderer';
	case Char             = 'CharRenderer';
	case Bool             = 'BoolRenderer';
	case BoolCheckbox     = 'BoolCheckboxRenderer';
	case BoolRadio        = 'BoolRadioRenderer';
	case Email            = 'EmailRenderer';
	case Date             = 'DateRenderer';
	case Time             = 'TimeRenderer';
	case DateTime         = 'DateTimeRenderer';
	case Color            = 'ColorRenderer';
	case Media            = 'MediaRenderer';
	case NodePresentation = 'NodePresentationRenderer';
	case Quantity         = 'QuantityRenderer';
	case Unit             = 'UnitRenderer';
	case NodeRef          = 'NodeRefRenderer';
	case Form             = 'FormRenderer';
	case Table            = 'TableRenderer';
	case Compact          = 'CompactRenderer';
	case CompactVertical  = 'CompactVerticalRenderer';
	/** Pick kind → filter/create (UR-B6). Legacy wire: EmbeddedRenderer / embed. */
	case Multistep        = 'MultistepRenderer';
	case ChildList        = 'ChildListRenderer';

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
			self::Multistep,
			self::ChildList,
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
			'int'                    => self::Int,
			'integer'                => self::Int,
			'int_spinner'            => self::IntSpinner,
			'intspinner'             => self::IntSpinner,
			'int_range'              => self::IntRange,
			'intrange'               => self::IntRange,
			'double'                 => self::Double,
			'float'                  => self::Double,
			'double_spinner'         => self::DoubleSpinner,
			'doublespinner'          => self::DoubleSpinner,
			'double_range'           => self::DoubleRange,
			'doublerange'            => self::DoubleRange,
			'text'                   => self::Text,
			'textarea'               => self::Textarea,
			'char'                   => self::Char,
			'bool'                   => self::Bool,
			'boolean'                => self::Bool,
			'bool_switch'            => self::Bool,
			'boolswitch'             => self::Bool,
			'bool_checkbox'          => self::BoolCheckbox,
			'boolcheckbox'           => self::BoolCheckbox,
			'bool_radio'             => self::BoolRadio,
			'boolradio'              => self::BoolRadio,
			'email'                  => self::Email,
			'date'                   => self::Date,
			'time'                   => self::Time,
			'datetime'               => self::DateTime,
			'date_time'              => self::DateTime,
			'color'                  => self::Color,
			'media'                  => self::Media,
			'display_node_name'      => self::NodePresentation,
			'displaynodenamerenderer'=> self::NodePresentation,
			'node_presentation'      => self::NodePresentation,
			'nodepresentation'       => self::NodePresentation,
			'nodepresentationrenderer' => self::NodePresentation,
			'quantity'               => self::Quantity,
			'unit'                   => self::Unit,
			'basiseinheit'           => self::Unit,
			'node_ref'               => self::NodeRef,
			'form'                   => self::Form,
			'table'                  => self::Table,
			'list'                   => self::Table,
			'compact'                => self::Compact,
			'compact-horizontal'     => self::Compact,
			'compact-h'              => self::Compact,
			'compact-vertical'       => self::CompactVertical,
			'compact-v'              => self::CompactVertical,
			'embed'                  => self::Multistep,
			'embeddedrenderer'       => self::Multistep,
			'pick-fill'              => self::Multistep,
			'pick_fill'              => self::Multistep,
			'compact-embed'          => self::Multistep,
			'multistep'              => self::Multistep,
			'multisteprenderer'      => self::Multistep,
			'child_list'             => self::ChildList,
			'childlist'              => self::ChildList,
			'childlistrenderer'      => self::ChildList,
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
