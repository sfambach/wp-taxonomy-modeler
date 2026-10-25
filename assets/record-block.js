( function ( wp ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ComboboxControl = wp.components.ComboboxControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var Placeholder = wp.components.Placeholder;
	var Spinner = wp.components.Spinner;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	// One request per node and page load; the sidebar re-renders often.
	var nodeCache = {};

	function ids( list ) {
		return String( list || '' ).split( /[^0-9]+/ ).map( Number ).filter( Boolean );
	}

	function loadNode( id, records, label ) {
		var key = id + ( records ? ':' + ( label || 0 ) : ':fields' );
		if ( ! nodeCache[ key ] ) {
			nodeCache[ key ] = apiFetch( { path: '/taxmod/v1/block-node/' + id + ( records ? '?label=' + ( label || 0 ) : '?records=0' ) } );
		}
		return nodeCache[ key ];
	}

	// Keeps the last answer while the next one loads, so the sidebar does not jump.
	function useNode( id, records, label ) {
		var state = useState( null );
		useEffect( function () {
			var alive = true;
			if ( ! id ) {
				state[ 1 ]( null );
				return;
			}
			loadNode( id, records, label ).then( function ( n ) { if ( alive ) { state[ 1 ]( n ); } }, function () { if ( alive ) { state[ 1 ]( false ); } } );
			return function () { alive = false; };
		}, [ id, records, label ] );
		return state[ 0 ] && state[ 0 ].id === id ? state[ 0 ] : null;
	}

	function fieldOptions( node, emptyLabel ) {
		var options = emptyLabel ? [ { label: emptyLabel, value: 0 } ] : [];
		return options.concat( ( node ? node.fields : [] ).map( function ( f ) { return { label: f.name, value: f.id }; } ) );
	}

	function NodePicker( props ) {
		var found = useState( [] );
		var node = props.node;

		if ( node ) {
			return el( 'div', { className: 'taxmod-block-node' },
				el( 'p', null, el( 'strong', null, node.name ), ' ' ),
				el( Button, { variant: 'secondary', onClick: function () { props.onChange( 0 ); } }, __( 'Choose another node', 'taxmod' ) )
			);
		}

		return el( ComboboxControl, {
			label: __( 'Node whose records are shown', 'taxmod' ),
			help: __( 'Type part of the name, e.g. CPUs.', 'taxmod' ),
			value: null,
			options: found[ 0 ],
			onFilterValueChange: function ( text ) {
				if ( text.length < 2 ) {
					return;
				}
				apiFetch( { path: '/taxmod/v1/block-nodes?search=' + encodeURIComponent( text ) } ).then( function ( rows ) {
					found[ 1 ]( rows.map( function ( r ) {
						return { value: r.id, label: r.name + ( r.parent ? ' (' + r.parent + ')' : '' ) + ' · ' + r.records };
					} ) );
				} );
			},
			onChange: function ( v ) { if ( v ) { props.onChange( Number( v ) ); } }
		} );
	}

	function RecordList( props ) {
		var a = props.attributes;
		var node = props.node;
		var list = ids( a.records );
		var filter = useState( '' );
		var labels = {};
		( node.records || [] ).forEach( function ( r ) { labels[ r.id ] = r.label; } );

		function set( next, main ) {
			var m = main === undefined ? a.record : main;
			if ( next.indexOf( m ) < 0 ) {
				m = next.length ? next[ 0 ] : 0;
			}
			props.setAttributes( { records: next.join( ', ' ), record: m, position: 'listed' } );
		}

		function move( i, step ) {
			var next = list.slice();
			var t = next[ i ];
			next[ i ] = next[ i + step ];
			next[ i + step ] = t;
			set( next );
		}

		var rows = list.map( function ( id, i ) {
			return el( 'li', { key: id, className: 'taxmod-block-record' + ( id === a.record ? ' is-main' : '' ) },
				el( 'span', null, labels[ id ] || '#' + id ),
				el( Button, { icon: 'arrow-up-alt2', label: __( 'Up', 'taxmod' ), disabled: i === 0, onClick: function () { move( i, -1 ); } } ),
				el( Button, { icon: 'arrow-down-alt2', label: __( 'Down', 'taxmod' ), disabled: i === list.length - 1, onClick: function () { move( i, 1 ); } } ),
				el( Button, { icon: 'no-alt', label: __( 'Remove', 'taxmod' ), onClick: function () { set( list.filter( function ( x ) { return x !== id; } ) ); } } )
			);
		} );

		// The combobox only searches the names; the filter searches every value, so «386» finds the boards with a 386.
		var words = filter[ 0 ].trim().toLowerCase().split( /\s+/ ).filter( Boolean );
		var offered = ( node.records || [] ).filter( function ( r ) {
			var text = ( r.label + ' ' + ( r.search || '' ) ).toLowerCase();
			return list.indexOf( r.id ) < 0 && words.every( function ( w ) { return text.indexOf( w ) >= 0; } );
		} );

		return el( 'div', null,
			el( 'ol', { className: 'taxmod-block-records' }, rows ),
			el( TextControl, {
				label: __( 'Filter records by any value', 'taxmod' ),
				help: words.length ? offered.length + ' ' + __( 'records match', 'taxmod' ) : __( 'e.g. 386 or Intel', 'taxmod' ),
				value: filter[ 0 ],
				onChange: filter[ 1 ]
			} ),
			el( ComboboxControl, {
				label: __( 'Add record', 'taxmod' ),
				value: null,
				options: offered.map( function ( r ) { return { value: r.id, label: r.label }; } ),
				onChange: function ( v ) { if ( v ) { set( list.concat( [ Number( v ) ] ) ); } }
			} ),
			list.length ? el( SelectControl, {
				label: __( 'Main record (the one this post is about)', 'taxmod' ),
				value: a.record,
				options: list.map( function ( id ) { return { label: labels[ id ] || '#' + id, value: id }; } ),
				onChange: function ( v ) { set( list, Number( v ) ); }
			} ) : null
		);
	}

	function FieldChecks( props ) {
		var chosen = ids( props.value );
		return el( 'fieldset', { className: 'taxmod-block-fields' },
			el( 'legend', null, props.label ),
			( props.node ? props.node.fields : [] ).map( function ( f ) {
				return el( CheckboxControl, {
					key: f.id,
					label: f.name,
					checked: chosen.indexOf( f.id ) >= 0,
					onChange: function ( on ) {
						// Kept in the node's field order, so the rows read as the record does.
						var next = props.node.fields.map( function ( g ) { return g.id; } ).filter( function ( id ) {
							return id === f.id ? on : chosen.indexOf( id ) >= 0;
						} );
						props.onChange( next.join( ',' ) );
					}
				} );
			} )
		);
	}

	function Rows( props ) {
		var a = props.attributes;
		var node = props.node;
		var referrer = ( node.referrers || [] ).filter( function ( r ) { return r.id === a.via; } )[ 0 ];
		var owner = useNode( referrer ? referrer.owner : 0, false );
		var key = owner ? owner.fields.filter( function ( f ) { return f.id === a.rowKey; } )[ 0 ] : null;
		var target = useNode( key ? key.target : 0, false );

		var source = el( SelectControl, {
			label: __( 'Rows', 'taxmod' ),
			value: a.via,
			options: [ { label: __( 'Fields of the records', 'taxmod' ), value: 0 } ].concat( ( node.referrers || [] ).map( function ( r ) {
				return { label: r.ownerName + ' → ' + r.name, value: r.id };
			} ) ),
			onChange: function ( v ) { props.setAttributes( { via: Number( v ), rowKey: 0, value: 0, groupBy: 0, rowFields: '' } ); }
		} );

		if ( ! a.via ) {
			return el( 'div', null, source,
				el( FieldChecks, { label: __( 'Fields to compare (none = all shown)', 'taxmod' ), node: node, value: a.rowFields, onChange: function ( v ) { props.setAttributes( { rowFields: v } ); } } )
			);
		}

		if ( ! owner ) {
			return el( 'div', null, source, el( Spinner ) );
		}

		return el( 'div', null, source,
			el( SelectControl, {
				label: __( 'One row per', 'taxmod' ),
				value: a.rowKey,
				options: fieldOptions( owner, '—' ),
				onChange: function ( v ) { props.setAttributes( { rowKey: Number( v ), groupBy: 0, rowFields: '' } ); }
			} ),
			el( SelectControl, {
				label: __( 'Value in the cell', 'taxmod' ),
				value: a.value,
				options: fieldOptions( owner, '—' ),
				onChange: function ( v ) { props.setAttributes( { value: Number( v ) } ); }
			} ),
			target ? el( SelectControl, {
				label: __( 'Group rows by', 'taxmod' ),
				value: a.groupBy,
				options: fieldOptions( target, __( 'No groups', 'taxmod' ) ),
				onChange: function ( v ) { props.setAttributes( { groupBy: Number( v ) } ); }
			} ) : null,
			target ? el( FieldChecks, { label: __( 'Row labels', 'taxmod' ), node: target, value: a.rowFields, onChange: function ( v ) { props.setAttributes( { rowFields: v } ); } } ) : null
		);
	}

	// D-991: the source apart from the display. «Through field» empty is the record itself; a field shows the records of another
	// node whose field points here (D-916). The fields ticked are the ones shown, as form rows or as table columns.
	function Source( props ) {
		var a = props.attributes;
		var referrer = ( props.node.referrers || [] ).filter( function ( r ) { return r.id === a.via; } )[ 0 ];
		var owner = useNode( referrer ? referrer.owner : 0, false );
		var shown = a.via ? owner : props.node;

		return el( 'div', null,
			el( SelectControl, {
				label: __( 'Through field', 'taxmod' ),
				help: __( 'Empty: this record. A field: the records pointing here through it.', 'taxmod' ),
				value: a.via,
				options: [ { label: __( '— this record', 'taxmod' ), value: 0 } ].concat( ( props.node.referrers || [] ).map( function ( r ) {
					return { label: r.ownerName + ' → ' + r.name, value: r.id };
				} ) ),
				onChange: function ( v ) { props.setAttributes( { via: Number( v ), rowFields: '', field: 0 } ); }
			} ),
			shown ? el( FieldChecks, { label: __( 'Fields shown (none = all)', 'taxmod' ), node: shown, value: a.rowFields, onChange: function ( v ) { props.setAttributes( { rowFields: v } ); } } ) : ( a.via ? el( Spinner ) : null ),
			// D-993 «Daten anreichern»: the record itself first, then what points here, as one form.
			a.via && ( a.display || 'record' ) === 'record' ? el( ToggleControl, {
				label: __( 'Enrich: show this record first', 'taxmod' ),
				checked: !! a.enrich,
				onChange: function ( v ) { props.setAttributes( { enrich: v } ); }
			} ) : null,
			a.via && a.enrich ? el( FieldChecks, { label: __( 'Fields of this record (none = all)', 'taxmod' ), node: props.node, value: a.ownFields, onChange: function ( v ) { props.setAttributes( { ownFields: v } ); } } ) : null
		);
	}

	function Edit( props ) {
		var a = props.attributes;
		var comparison = a.display === 'comparison';
		var node = useNode( a.node, true, a.columnLabel );

		// Blocks written before the node was stored: find it from the main record once.
		useEffect( function () {
			if ( ! a.node && a.record ) {
				apiFetch( { path: '/taxmod/v1/block-record-node/' + a.record } ).then( function ( r ) { props.setAttributes( { node: r.node } ); } );
			}
		}, [ a.node, a.record ] );

		var display = el( SelectControl, {
			label: __( 'Display', 'taxmod' ),
			value: a.display === 'referrers' ? 'table' : ( a.display || 'record' ),
			options: [
				{ label: __( 'Form (vertical)', 'taxmod' ), value: 'record' },
				{ label: __( 'Table', 'taxmod' ), value: 'table' },
				{ label: __( 'Comparison', 'taxmod' ), value: 'comparison' }
			],
			onChange: function ( v ) { props.setAttributes( { display: v } ); }
		} );

		var picker = el( NodePicker, {
			node: node,
			onChange: function ( id ) { props.setAttributes( { node: id, record: 0, records: '', field: 0, via: 0, rowKey: 0, value: 0, groupBy: 0, rowFields: '', columnLabel: 0 } ); }
		} );

		var single = node && ! comparison ? [
			el( ComboboxControl, {
				key: 'record',
				label: __( 'Record', 'taxmod' ),
				value: a.record || null,
				options: node.records.map( function ( r ) { return { value: r.id, label: r.label }; } ),
				onChange: function ( v ) { props.setAttributes( { record: Number( v ) || 0 } ); }
			} ),
			el( Source, { key: 'source', attributes: a, setAttributes: props.setAttributes, node: node } )
		] : null;

		var naming = node && comparison ? el( SelectControl, {
			label: __( 'Name the records by', 'taxmod' ),
			help: __( 'Used in this list and above the columns.', 'taxmod' ),
			value: a.columnLabel,
			options: fieldOptions( node, __( 'Their summary', 'taxmod' ) ),
			onChange: function ( v ) { props.setAttributes( { columnLabel: Number( v ) } ); }
		} ) : null;

		var panels = [
			el( PanelBody, { key: 'what', title: __( 'Records', 'taxmod' ) }, display, picker, naming, single, node && comparison ? el( RecordList, { attributes: a, setAttributes: props.setAttributes, node: node } ) : null )
		];

		if ( node && comparison ) {
			panels.push( el( PanelBody, { key: 'rows', title: __( 'Rows', 'taxmod' ) }, el( Rows, { attributes: a, setAttributes: props.setAttributes, node: node } ) ) );
		}

		var body = a.record || ( comparison && a.records )
			? el( ServerSideRender, { block: 'taxmod/record', attributes: a } )
			: el( Placeholder, { icon: 'networking', label: __( 'Model record', 'taxmod' ), instructions: __( 'Choose a node and its records in the block settings.', 'taxmod' ) } );

		return el( 'div', useBlockProps(), el( InspectorControls, null, panels ), body );
	}

	wp.blocks.registerBlockType( 'taxmod/record', {
		edit: Edit,
		save: function () {
			return null;
		}
	} );
} )( window.wp );
