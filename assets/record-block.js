( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	function number( value ) {
		var n = parseInt( value, 10 );
		return isNaN( n ) || n < 0 ? 0 : n;
	}

	function numberField( props, name, label ) {
		return el( TextControl, {
			label: label,
			type: 'number',
			value: props.attributes[ name ] || '',
			onChange: function ( v ) { var o = {}; o[ name ] = number( v ); props.setAttributes( o ); }
		} );
	}

	function listField( props, name, label ) {
		return el( TextControl, {
			label: label,
			value: props.attributes[ name ] || '',
			onChange: function ( v ) { var o = {}; o[ name ] = v; props.setAttributes( o ); }
		} );
	}

	wp.blocks.registerBlockType( 'taxmod/record', {
		edit: function ( props ) {
			var a = props.attributes;
			var comparison = a.display === 'comparison';

			var record = el(
				PanelBody,
				{ title: __( 'Record', 'taxmod' ) },
				el( SelectControl, {
					label: __( 'Display', 'taxmod' ),
					value: a.display || 'record',
					options: [
						{ label: __( 'Record', 'taxmod' ), value: 'record' },
						{ label: __( 'Comparison', 'taxmod' ), value: 'comparison' }
					],
					onChange: function ( v ) { props.setAttributes( { display: v } ); }
				} ),
				numberField( props, 'record', comparison ? __( 'Main record id (the one this post is about)', 'taxmod' ) : __( 'Record id', 'taxmod' ) ),
				comparison ? null : numberField( props, 'field', __( 'Only this field (relation id, empty = all)', 'taxmod' ) )
			);

			var compare = comparison ? el(
				PanelBody,
				{ title: __( 'Comparison', 'taxmod' ) },
				listField( props, 'records', __( 'Comparison record ids, comma separated', 'taxmod' ) ),
				el( SelectControl, {
					label: __( 'Main record column', 'taxmod' ),
					value: a.position || 'front',
					options: [
						{ label: __( 'First', 'taxmod' ), value: 'front' },
						{ label: __( 'In the middle', 'taxmod' ), value: 'middle' }
					],
					onChange: function ( v ) { props.setAttributes( { position: v } ); }
				} ),
				numberField( props, 'via', __( 'Rows from records pointing here: their field (relation id, empty = own fields)', 'taxmod' ) ),
				a.via ? numberField( props, 'rowKey', __( 'One row per target of field (relation id)', 'taxmod' ) ) : null,
				a.via ? listField( props, 'rowFields', __( 'Row labels: fields of that target (relation ids)', 'taxmod' ) ) : null,
				a.via ? numberField( props, 'value', __( 'Cell value field (relation id)', 'taxmod' ) ) : null
			) : null;

			var body = a.record || ( comparison && a.records )
				? el( ServerSideRender, { block: 'taxmod/record', attributes: a } )
				: el( Placeholder, { icon: 'networking', label: __( 'Model record', 'taxmod' ), instructions: __( 'Enter the record id in the block settings.', 'taxmod' ) } );

			return el( 'div', useBlockProps(), el( InspectorControls, null, record, compare ), body );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
