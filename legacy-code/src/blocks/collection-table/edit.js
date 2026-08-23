import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Button,
	Spinner,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState, useRef, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ModelTreeChooser from '../shared/model-tree-chooser';
import '../shared/model-bind.scss';

/**
 * Taxo Table view (`taxo/collection-table`):
 * Pick a structure node → show ALL Model_Data instances as rows
 * (columns = attribute schema). Not a single-instance picker/form.
 */

const cfg = window.wttCollectionTable || {};
const i18n = cfg.i18n || {};
/* Explicit focus/default = catalog binding `model` — never chooser_focus. */
const modelId = parseInt( cfg.modelId, 10 ) || 0;
const chooserRoot = parseInt( cfg.chooserRoot, 10 ) || 0;

function newRowId() {
	return `r${ Date.now().toString( 36 ) }${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

function emptyCells( columns ) {
	const cells = {};
	( columns || [] ).forEach( ( col ) => {
		cells[ String( col.id ) ] = '';
	} );
	return cells;
}

function normalizeRows( rows, columns ) {
	if ( ! Array.isArray( rows ) ) {
		return [];
	}
	return rows.map( ( row ) => {
		const id = row && row.id ? String( row.id ) : newRowId();
		const cellsIn = row && row.cells && typeof row.cells === 'object' ? row.cells : {};
		const cells = { ...cellsIn };
		( columns || [] ).forEach( ( col ) => {
			const key = String( col.id );
			if ( ! Object.prototype.hasOwnProperty.call( cells, key ) ) {
				cells[ key ] = '';
			}
		} );
		const meta = row && row.meta && typeof row.meta === 'object' ? row.meta : {};
		return { id, cells, meta };
	} );
}

function cellsFromInstanceValues( values, columns ) {
	const cells = emptyCells( columns );
	const src = values && typeof values === 'object' ? values : {};
	Object.keys( src ).forEach( ( key ) => {
		cells[ String( key ) ] = src[ key ] == null ? '' : String( src[ key ] );
	} );
	return cells;
}

function valuesFromCells( cells ) {
	const out = {};
	const src = cells && typeof cells === 'object' ? cells : {};
	Object.keys( src ).forEach( ( key ) => {
		out[ String( key ) ] = src[ key ] == null ? '' : String( src[ key ] );
	} );
	return out;
}

function rowsFromInstances( instances, columns ) {
	if ( ! Array.isArray( instances ) ) {
		return [];
	}
	return instances.map( ( inst ) => {
		const id = inst && inst.id ? String( inst.id ) : newRowId();
		return {
			id,
			cells: cellsFromInstanceValues( inst && inst.values, columns ),
			meta: {
				seq: inst && inst.seq != null ? String( inst.seq ) : '',
				version: inst && inst.version != null ? String( inst.version ) : '',
				modified:
					( inst && ( inst.modifiedAtLabel || inst.modifiedAt ) ) || '',
			},
		};
	} );
}

function formatInstanceTitle( schemaName ) {
	return String( schemaName || '' ).trim() || 'Model';
}

function columnTypeKey( col ) {
	const raw = String(
		( col && ( col.typeKey || col.typeName ) ) || ''
	)
		.trim()
		.toLowerCase();
	return raw === 'integer' ? 'int' : raw;
}

/**
 * Map Composition schema columns → WTTObjectRender attribute fields.
 *
 * @param {Array} columns
 * @return {Array}
 */
function columnsAsAttributes( columns ) {
	return ( Array.isArray( columns ) ? columns : [] ).map( ( col ) => ( {
		id: col.id,
		name: col.name || '',
		typeKey: col.typeKey || col.typeName || 'text',
		typeName: col.typeName || '',
		typeId: col.typeId || 0,
		typePath: col.typePath || '',
		readonly: !! col.readonly,
		multiplicity: col.multiplicity || '1',
		fieldMultiplicity: col.fieldMultiplicity || col.multiplicity || '1',
		fixedMode: col.fixedMode || '',
		fixedOptions: Array.isArray( col.fixedOptions ) ? col.fixedOptions : [],
		fixedValues: Array.isArray( col.fixedValues ) ? col.fixedValues : [],
		mediaConfig: col.mediaConfig || null,
		typeProperties: Array.isArray( col.typeProperties )
			? col.typeProperties
			: [],
		quantitySchema: col.quantitySchema || null,
		refScopeId: col.refScopeId || 0,
		nodeRefOptions: Array.isArray( col.nodeRefOptions )
			? col.nodeRefOptions
			: [],
		nodeRefCreateFields: Array.isArray( col.nodeRefCreateFields )
			? col.nodeRefCreateFields
			: [],
		description: col.description || '',
		shortDescription: col.shortDescription || '',
	} ) );
}

/**
 * Editor rows → WTTObjectRender.renderTable instances.
 *
 * @param {Array} rows
 * @param {Array} attributes
 * @return {Array}
 */
function instancesForRenderTable( rows, attributes ) {
	return ( Array.isArray( rows ) ? rows : [] ).map( ( row ) => ( {
		id: row.id,
		attributes,
		values:
			row.cells && typeof row.cells === 'object' ? { ...row.cells } : {},
	} ) );
}

function rowMatchesQuery( row, query, columns ) {
	const q = String( query || '' )
		.trim()
		.toLowerCase();
	if ( ! q ) {
		return true;
	}
	const parts = [ String( row.id || '' ) ];
	if ( row.meta ) {
		parts.push(
			String( row.meta.seq || '' ),
			String( row.meta.version || '' ),
			String( row.meta.modified || '' )
		);
	}
	( columns || [] ).forEach( ( col ) => {
		parts.push( String( ( row.cells && row.cells[ String( col.id ) ] ) || '' ) );
		parts.push( String( col.name || '' ) );
	} );
	return parts.join( ' ' ).toLowerCase().includes( q );
}

/**
 * Mount WTTNodeRender NodeRefChooser into a host div (DOM bridge).
 */
function NodeRefCell( { column, value, onChange, taxonomy } ) {
	const hostRef = useRef( null );
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		window.wttTree = Object.assign( {}, window.wttTree || {}, {
			ajaxUrl: cfg.ajaxUrl || '',
			nonce: cfg.ajaxNonce || '',
			taxonomy: taxonomy || '',
			treePickerMode: cfg.treePickerMode || 'popup',
			i18n: Object.assign( {}, ( window.wttTree && window.wttTree.i18n ) || {}, i18n ),
		} );
	}, [ taxonomy ] );

	useEffect( () => {
		const host = hostRef.current;
		const api = window.WTTNodeRender;
		if ( ! host || ! api || ! api.Registry ) {
			return undefined;
		}
		host.textContent = '';
		const node = {
			id: column.id,
			name: column.name,
			displayName: column.shortDescription || column.name,
			typeKey: 'node_ref',
			type: { name: 'node_ref' },
			fieldMultiplicity: column.fieldMultiplicity || '0..1',
			refScopeId: column.refScopeId || 0,
			nodeRefOptions: Array.isArray( column.nodeRefOptions )
				? column.nodeRefOptions
				: [],
			nodeRefCreateFields: Array.isArray( column.nodeRefCreateFields )
				? column.nodeRefCreateFields
				: [],
		};
		const el = api.Registry.renderContent(
			node,
			{
				name: 'table',
				mode: 'edit',
				value: value != null ? String( value ) : '',
				onInput( next ) {
					if ( typeof onChangeRef.current === 'function' ) {
						onChangeRef.current( next == null ? '' : String( next ) );
					}
				},
			},
			false
		);
		if ( el ) {
			host.appendChild( el );
		}
		return () => {
			host.textContent = '';
		};
		/* Remount when column schema changes; value updates via onInput only. */
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		column.id,
		column.fieldMultiplicity,
		column.refScopeId,
		JSON.stringify( column.nodeRefOptions || [] ),
	] );

	return (
		<div
			className="wtt-collection-table-editor__node-ref"
			ref={ hostRef }
		/>
	);
}

export default function CollectionTableEdit( { attributes, setAttributes } ) {
	const {
		collectionTermId = 0,
		rows = [],
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'wtt-collection-table-editor',
	} );

	const [ collections, setCollections ] = useState( () =>
		Array.isArray( cfg.collections ) ? cfg.collections : []
	);
	const [ nodes, setNodes ] = useState( () =>
		Array.isArray( cfg.nodes ) ? cfg.nodes : []
	);
	const [ schema, setSchema ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ loadingInstances, setLoadingInstances ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saveNote, setSaveNote ] = useState( '' );
	const [ creating, setCreating ] = useState( false );
	const [ filterQuery, setFilterQuery ] = useState( '' );
	/* Model rows live in local state (SoT = Model_Data via REST). */
	const [ modelRows, setModelRows ] = useState( [] );
	const saveTimers = useRef( {} );
	const tableHostRef = useRef( null );
	const setCellRef = useRef( null );
	const isCatalog = schema && schema.kind === 'catalog';
	const isModel = schema && schema.kind === 'model';
	const columns = schema && Array.isArray( schema.columns ) ? schema.columns : [];
	const boundReady = !! collectionTermId && !! schema && ! loading;

	const selectedCollection = useMemo(
		() =>
			collections.find(
				( c ) => Number( c.id ) === Number( collectionTermId )
			) ||
			nodes.find( ( n ) => Number( n.id ) === Number( collectionTermId ) ) ||
			null,
		[ collections, nodes, collectionTermId ]
	);

	/* Same tree as Object View: pickable nodes + kind badge from host list. */
	const treeItems = useMemo( () => {
		const kindById = {};
		collections.forEach( ( c ) => {
			const id = parseInt( c && c.id, 10 ) || 0;
			if ( id > 0 ) {
				kindById[ id ] = String( ( c && c.kind ) || 'model' );
			}
		} );
		const base = nodes.length ? nodes : collections;
		return base.map( ( n ) => {
			const id = parseInt( n && n.id, 10 ) || 0;
			return {
				...n,
				kind: kindById[ id ] || n.kind || '',
			};
		} );
	}, [ nodes, collections ] );

	const collectionOptions = [
		{
			label: i18n.pickCollection || '1. Collection…',
			value: '0',
		},
		...collections.map( ( c ) => ( {
			label: `${ c.path } [${ c.kind || 'model' }] (${ c.columnCount })`,
			value: String( c.id ),
		} ) ),
	];

	/* Default: select Model from catalog binding when unbound. */
	useEffect( () => {
		if ( collectionTermId || ! modelId ) {
			return;
		}
		setAttributes( {
			collectionTermId: modelId,
			instanceId: '',
			rows: [],
		} );
	}, [ collectionTermId, modelId, setAttributes ] );

	/* Host list (schema-capable) + full chooser tree (same as Object View). */
	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/wtt/v1/collections' } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				const list =
					data && Array.isArray( data.collections ) ? data.collections : [];
				setCollections( list );
			} )
			.catch( () => {
				/* keep localized fallback */
			} );
		apiFetch( { path: '/wtt/v1/object-view/nodes?taxonomy=wtt_fs' } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				const list = data && Array.isArray( data.nodes ) ? data.nodes : [];
				setNodes( list );
			} )
			.catch( () => {
				/* keep localized fallback */
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! collectionTermId ) {
			setSchema( null );
			setModelRows( [] );
			setError( '' );
			return;
		}
		let cancelled = false;
		setLoading( true );
		setError( '' );
		apiFetch( {
			path: `/wtt/v1/collections/${ collectionTermId }`,
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setSchema( data );
				setLoading( false );
				const cols = data.columns || [];
				if ( data.kind === 'catalog' && Array.isArray( data.rows ) ) {
					setAttributes( {
						instanceId: '',
						rows: normalizeRows( data.rows, cols ),
					} );
				} else if ( data.kind === 'model' ) {
					setAttributes( { instanceId: '', rows: [] } );
				} else {
					setAttributes( {
						instanceId: '',
						rows: normalizeRows( rows, cols ),
					} );
				}
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setSchema( null );
				setLoading( false );
				setError( ( err && err.message ) || 'Error' );
			} );
		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- reload schema when collection changes only
	}, [ collectionTermId ] );

	/* Load ALL model-data instances for the bound structure node. */
	useEffect( () => {
		if ( ! isModel || ! collectionTermId ) {
			if ( ! isModel ) {
				setModelRows( [] );
			}
			return undefined;
		}
		let cancelled = false;
		setLoadingInstances( true );
		const tax = ( schema && schema.taxonomy ) || '';
		const qs = tax ? `?taxonomy=${ encodeURIComponent( tax ) }` : '';
		apiFetch( {
			path: `/wtt/v1/model-data/${ collectionTermId }${ qs }`,
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				const list =
					data && Array.isArray( data.instances ) ? data.instances : [];
				const cols = ( schema && schema.columns ) || [];
				setModelRows( rowsFromInstances( list, cols ) );
				setLoadingInstances( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setModelRows( [] );
				setLoadingInstances( false );
				setError(
					( err && err.message ) ||
						i18n.instanceLoadFailed ||
						'Could not load instances.'
				);
			} );
		return () => {
			cancelled = true;
		};
	}, [ isModel, collectionTermId, schema ] );

	const catalogRows = normalizeRows( rows, columns );
	const displayRows = isModel
		? normalizeRows( modelRows, columns )
		: catalogRows;

	const filteredRows = useMemo(
		() =>
			displayRows.filter( ( row ) =>
				rowMatchesQuery( row, filterQuery, columns )
			),
		[ displayRows, filterQuery, columns ]
	);
	const filteredRowsRef = useRef( filteredRows );
	filteredRowsRef.current = filteredRows;
	const modelTableKey = useMemo(
		() =>
			[
				filterQuery,
				columns.map( ( c ) => String( c.id ) ).join( ',' ),
				filteredRows.map( ( r ) => String( r.id ) ).join( ',' ),
			].join( '|' ),
		[ filterQuery, columns, filteredRows ]
	);

	const persistCatalogRows = ( nextRows ) => {
		if ( ! isCatalog || ! collectionTermId ) {
			return;
		}
		if ( saveTimers.current.catalog ) {
			clearTimeout( saveTimers.current.catalog );
		}
		saveTimers.current.catalog = setTimeout( () => {
			setSaving( true );
			setSaveNote( i18n.saving || 'Saving catalog…' );
			apiFetch( {
				path: `/wtt/v1/collections/${ collectionTermId }/rows`,
				method: 'POST',
				data: { rows: nextRows },
			} )
				.then( ( data ) => {
					setSaving( false );
					setSaveNote( i18n.saved || 'Catalog saved.' );
					if ( data && Array.isArray( data.rows ) ) {
						setAttributes( {
							rows: normalizeRows( data.rows, columns ),
						} );
					}
				} )
				.catch( ( err ) => {
					setSaving( false );
					setError( ( err && err.message ) || 'Save failed' );
					setSaveNote( '' );
				} );
		}, 450 );
	};

	const persistModelRow = ( row ) => {
		if ( ! isModel || ! collectionTermId || ! row || ! row.id ) {
			return;
		}
		const instanceId = String( row.id );
		if ( saveTimers.current[ instanceId ] ) {
			clearTimeout( saveTimers.current[ instanceId ] );
		}
		const tax = ( schema && schema.taxonomy ) || '';
		saveTimers.current[ instanceId ] = setTimeout( () => {
			setSaving( true );
			setSaveNote( i18n.savingInstance || 'Saving instance…' );
			apiFetch( {
				path: `/wtt/v1/model-data/${ collectionTermId }`,
				method: 'POST',
				data: {
					taxonomy: tax || undefined,
					instanceId,
					values: valuesFromCells( row.cells ),
				},
			} )
				.then( ( data ) => {
					setSaving( false );
					setSaveNote( i18n.savedInstance || 'Instance saved.' );
					const saved = data && data.instance ? data.instance : null;
					if ( saved && saved.id ) {
						setModelRows( ( prev ) =>
							prev.map( ( r ) => {
								if ( String( r.id ) !== String( saved.id ) ) {
									return r;
								}
								return {
									id: String( saved.id ),
									cells: cellsFromInstanceValues(
										saved.values,
										columns
									),
									meta: {
										seq:
											saved.seq != null
												? String( saved.seq )
												: '',
										version:
											saved.version != null
												? String( saved.version )
												: '',
										modified:
											saved.modifiedAtLabel ||
											saved.modifiedAt ||
											'',
									},
								};
							} )
						);
					}
				} )
				.catch( ( err ) => {
					setSaving( false );
					setError( ( err && err.message ) || 'Save failed' );
					setSaveNote( '' );
				} );
		}, 450 );
	};

	const onPickCollection = ( value ) => {
		const id = parseInt( value, 10 ) || 0;
		setAttributes( {
			collectionTermId: id,
			instanceId: '',
			rows: [],
		} );
		setModelRows( [] );
		setFilterQuery( '' );
		setSaveNote( '' );
	};

	const onTreeSelect = ( node ) => {
		const id = node && node.id ? parseInt( node.id, 10 ) || 0 : 0;
		setAttributes( {
			collectionTermId: id,
			instanceId: '',
			rows: [],
		} );
		setModelRows( [] );
		setFilterQuery( '' );
		setSaveNote( '' );
	};

	const clearBinding = () => {
		setAttributes( {
			collectionTermId: modelId || 0,
			instanceId: '',
			rows: [],
		} );
		setSchema( null );
		setModelRows( [] );
		setFilterQuery( '' );
		setSaveNote( '' );
	};

	const createInstance = () => {
		if ( ! isModel || ! collectionTermId || creating ) {
			return;
		}
		setCreating( true );
		setError( '' );
		const tax = ( schema && schema.taxonomy ) || '';
		apiFetch( {
			path: `/wtt/v1/model-data/${ collectionTermId }`,
			method: 'POST',
			data: {
				taxonomy: tax || undefined,
				values: {},
			},
		} )
			.then( ( data ) => {
				setCreating( false );
				const list =
					data && Array.isArray( data.instances )
						? data.instances
						: [];
				setModelRows( rowsFromInstances( list, columns ) );
				setSaveNote( i18n.savedInstance || 'Instance saved.' );
			} )
			.catch( ( err ) => {
				setCreating( false );
				setError(
					( err && err.message ) ||
						i18n.instanceCreateFailed ||
						'Could not create instance.'
				);
			} );
	};

	const addRow = () => {
		if ( isModel ) {
			createInstance();
			return;
		}
		const next = [
			...displayRows,
			{ id: newRowId(), cells: emptyCells( columns ) },
		];
		setAttributes( { rows: next } );
		if ( isCatalog ) {
			persistCatalogRows( next );
		}
	};

	const removeRow = ( rowId ) => {
		if ( isModel ) {
			return;
		}
		const next = displayRows.filter( ( r ) => r.id !== rowId );
		setAttributes( { rows: next } );
		if ( isCatalog ) {
			persistCatalogRows( next );
		}
	};

	const setCell = ( rowId, colId, value ) => {
		if ( isModel ) {
			let changed = null;
			const next = displayRows.map( ( r ) => {
				if ( r.id !== rowId ) {
					return r;
				}
				changed = {
					...r,
					cells: {
						...r.cells,
						[ String( colId ) ]: value,
					},
				};
				return changed;
			} );
			setModelRows( next );
			if ( changed ) {
				persistModelRow( changed );
			}
			return;
		}
		const next = displayRows.map( ( r ) => {
			if ( r.id !== rowId ) {
				return r;
			}
			return {
				...r,
				cells: {
					...r.cells,
					[ String( colId ) ]: value,
				},
			};
		} );
		setAttributes( { rows: next } );
		if ( isCatalog ) {
			persistCatalogRows( next );
		}
	};
	setCellRef.current = setCell;

	const titleText = schema ? formatInstanceTitle( schema.name ) : '';
	/* Model: show table once bound (even with 0 attribute columns — Id column). */
	const showTable = boundReady && ( isModel || columns.length > 0 );
	const busy = loading || ( isModel && loadingInstances );

	/* Model datasets → shared WTTObjectRender.renderTable (not hand-built inputs). */
	useEffect( () => {
		if ( ! isModel || ! showTable || busy ) {
			return undefined;
		}
		const host = tableHostRef.current;
		const api = window.WTTObjectRender;
		if ( ! host ) {
			return undefined;
		}
		host.textContent = '';
		if ( ! api || typeof api.renderTable !== 'function' ) {
			host.textContent =
				i18n.renderUnavailable ||
				'Object table renderer unavailable. Reload the editor.';
			return () => {
				host.textContent = '';
			};
		}

		window.wttTree = Object.assign( {}, window.wttTree || {}, {
			ajaxUrl: cfg.ajaxUrl || '',
			nonce: cfg.ajaxNonce || '',
			taxonomy: ( schema && schema.taxonomy ) || '',
			treePickerMode: cfg.treePickerMode || 'popup',
			i18n: Object.assign(
				{},
				( window.wttTree && window.wttTree.i18n ) || {},
				i18n
			),
		} );

		const attrs = columnsAsAttributes( columns );
		const instances = instancesForRenderTable(
			filteredRowsRef.current,
			attrs
		);
		const emptyMessage =
			filteredRowsRef.current.length === 0 &&
			displayRows.length > 0
				? i18n.noMatchingInstances || 'No matching instances.'
				: i18n.tableEmpty ||
				  i18n.noInstancesEmpty ||
				  'No data available.';
		host.appendChild(
			api.renderTable( instances, {
				readonly: false,
				attributes: attrs,
				className: 'wtt-collection-table-editor__object-table',
				emptyMessage,
				onFieldInput( field, next, instance ) {
					const rowId = instance && instance.id ? String( instance.id ) : '';
					const fieldId =
						field && field.id != null ? String( field.id ) : '';
					if ( ! rowId || ! fieldId || fieldId === '0' ) {
						return;
					}
					if ( typeof setCellRef.current === 'function' ) {
						setCellRef.current(
							rowId,
							fieldId,
							next == null ? '' : String( next )
						);
					}
				},
			} )
		);

		return () => {
			host.textContent = '';
		};
		/* Remount on structure/filter/row-id changes only — not on each cell keystroke. */
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isModel, showTable, busy, modelTableKey, schema ] );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ i18n.title || 'Taxo Table view' }
					initialOpen={ true }
				>
					{ collectionTermId ? (
						<>
							<p className="wtt-collection-table-editor__hint">
								{ ( selectedCollection && selectedCollection.path ) ||
									( schema && schema.path ) ||
									`#${ collectionTermId }` }
							</p>
							{ isModel ? (
								<p className="wtt-collection-table-editor__hint">
									{ i18n.instancesCount
										? i18n.instancesCount.replace(
												'%d',
												String( displayRows.length )
										  )
										: `${ displayRows.length } datasets` }
								</p>
							) : null }
							<Button variant="secondary" onClick={ clearBinding }>
								{ i18n.changeModel || 'Change model…' }
							</Button>
						</>
					) : (
						<SelectControl
							label={ i18n.pickCollection || 'Model / node' }
							value={ String( collectionTermId || 0 ) }
							options={ collectionOptions }
							onChange={ onPickCollection }
							help={
								collections.length
									? i18n.pickHint ||
									  'Pick a model or schema node (e.g. Kontakt, Platine). Columns come from its attributes; rows are all datasets.'
									: i18n.noCollections ||
									  'No model nodes found. Create a node with attributes under Taxonomy Tree, then reload.'
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			{ treeItems.length === 0 ? (
				<Notice status="info" isDismissible={ false }>
					{ i18n.noCollections ||
						'No model nodes found. Create a node with attributes under Taxonomy Tree, then reload.' }
				</Notice>
			) : (
				<>
					<p className="wtt-collection-table-editor__hint">
						{ collectionTermId
							? i18n.flowHint ||
							  'Choose a model node — the table lists all datasets for that node.'
							: i18n.chooseModelCanvas ||
							  'Choose a model node in the tree below.' }
						{ collectionTermId ? (
							<>
								{ ' ' }
								<strong>
									{ ( selectedCollection &&
										selectedCollection.path ) ||
										( schema && schema.path ) ||
										titleText }
								</strong>
							</>
						) : null }
					</p>
					<ModelTreeChooser
						items={ treeItems }
						rootId={ chooserRoot || 0 }
						selectedId={ collectionTermId || modelId }
						focusId={ modelId || 0 }
						expandFocusBranch={ true }
						onSelect={ onTreeSelect }
						mode="tree"
						i18n={ i18n }
					/>
				</>
			) }

			{ busy && <Spinner /> }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ( saving || saveNote ) && ( isCatalog || isModel ) && boundReady && (
				<p className="wtt-collection-table-editor__hint">
					{ saving ? <Spinner /> : null } { saveNote }
				</p>
			) }

			{ schema && ! busy && isModel && columns.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ i18n.noColumns ||
						'This node has no attributes yet. Datasets still appear with Id.' }
				</Notice>
			) }

			{ schema && ! busy && ! isModel && columns.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ i18n.noColumns ||
						'This node has no attributes yet.' }
				</Notice>
			) }

			{ showTable && ! busy && (
				<>
					<div className="wtt-collection-table-editor__toolbar">
						<strong>
							{ titleText }
							{ isModel
								? ` · ${ displayRows.length }`
								: '' }
						</strong>
						<Button
							variant="secondary"
							onClick={ addRow }
							disabled={ creating }
						>
							{ creating ? (
								<Spinner />
							) : isModel ? (
								i18n.createInstance || 'Create new'
							) : (
								i18n.addRow || 'Add row'
							) }
						</Button>
					</div>
					{ ( isModel || displayRows.length > 3 ) && (
						<div className="wtt-collection-table-editor__filter">
							<TextControl
								label={
									i18n.instanceSearch ||
									i18n.nodePickerSearch ||
									'Search'
								}
								hideLabelFromVision
								value={ filterQuery }
								placeholder={
									i18n.instanceSearchPlaceholder ||
									i18n.nodePickerSearchPlaceholder ||
									'Search…'
								}
								onChange={ setFilterQuery }
							/>
						</div>
					) }
					{ isModel ? (
						<div
							className="wtt-collection-table__wrap wtt-collection-table-editor__render-host"
							ref={ tableHostRef }
						/>
					) : (
						<div className="wtt-collection-table__wrap">
							<table className="wtt-collection-table__table wtt-row-edit-table">
								<thead>
									<tr>
										<th scope="col">
											{ i18n.colIndex || '#' }
										</th>
										{ columns.map( ( col ) => (
											<th key={ col.id } scope="col">
												{ col.name }
											</th>
										) ) }
										<th
											scope="col"
											className="wtt-col-actions"
										>
											{ i18n.colActions ||
												i18n.removeRow ||
												'Actions' }
										</th>
									</tr>
								</thead>
								<tbody>
									{ filteredRows.length === 0 && (
										<tr>
											<td
												colSpan={
													columns.length + 2
												}
											>
												—
											</td>
										</tr>
									) }
									{ filteredRows.map( ( row, index ) => (
										<tr key={ row.id }>
											<td>{ index + 1 }</td>
											{ columns.map( ( col ) => {
												const typeKey =
													columnTypeKey( col );
												const cellVal =
													row.cells[
														String( col.id )
													] || '';
												if ( typeKey === 'node_ref' ) {
													return (
														<td
															key={ col.id }
															className="wtt-collection-table-editor__cell--node-ref"
														>
															<NodeRefCell
																column={ col }
																value={
																	cellVal
																}
																taxonomy={
																	schema.taxonomy ||
																	''
																}
																onChange={ (
																	next
																) =>
																	setCell(
																		row.id,
																		col.id,
																		next
																	)
																}
															/>
														</td>
													);
												}
												return (
													<td key={ col.id }>
														<input
															className="wtt-collection-table-editor__cell-input"
															type="text"
															value={ cellVal }
															onChange={ ( e ) =>
																setCell(
																	row.id,
																	col.id,
																	e.target
																		.value
																)
															}
														/>
													</td>
												);
											} ) }
											<td className="wtt-collection-table-editor__row-actions wtt-col-actions">
												<Button
													className="wtt-row-edit-table__trash"
													isDestructive
													variant="link"
													onClick={ () =>
														removeRow( row.id )
													}
													icon="trash"
													label={
														i18n.removeRow ||
														'Remove'
													}
												/>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) }
		</div>
	);
}
