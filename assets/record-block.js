( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	function number( value ) {
		var n = parseInt( value, 10 );
		return isNaN( n ) || n < 0 ? 0 : n;
	}

	wp.blocks.registerBlockType( 'taxmod/record', {
		edit: function ( props ) {
			var a = props.attributes;
			var controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Record', 'taxmod' ) },
					el( TextControl, {
						label: __( 'Record id', 'taxmod' ),
						type: 'number',
						value: a.record || '',
						onChange: function ( v ) { props.setAttributes( { record: number( v ) } ); }
					} ),
					el( TextControl, {
						label: __( 'Only this field (relation id, empty = all)', 'taxmod' ),
						type: 'number',
						value: a.field || '',
						onChange: function ( v ) { props.setAttributes( { field: number( v ) } ); }
					} )
				)
			);

			var body = a.record
				? el( ServerSideRender, { block: 'taxmod/record', attributes: a } )
				: el( Placeholder, { icon: 'networking', label: __( 'Model record', 'taxmod' ), instructions: __( 'Enter the record id in the block settings.', 'taxmod' ) } );

			return el( 'div', useBlockProps(), controls, body );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
