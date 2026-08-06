import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useEffect, useState, useRef, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ModelTreeChooser from '../shared/model-tree-chooser';
import ModelInstancePicker from '../shared/model-instance-picker';
import '../shared/model-bind.scss';

const cfg = window.wttCollectionTable || {};
const i18n = cfg.i18n || {};

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
		return { id, cells };
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

function formatInstanceChip( instanceId, i18nMap ) {
	const id = String( instanceId || '' ).trim();
	if ( ! id ) {
		return i18nMap.noInstance || 'No dataset';
	}
	return id;
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
		instanceId = '',
		rows = [],
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'wtt-collection-table-editor',
	} );

	const [ collections, setCollections ] = useState( () =>
		Array.isArray( cfg.collections ) ? cfg.collections : []
	);
	const [ schema, setSchema ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saveNote, setSaveNote ] = useState( '' );
	const saveTimer = useRef( null );
	const isCatalog = schema && schema.kind === 'catalog';
	const isModel = schema && schema.kind === 'model';
	const needsInstance = !! isModel;
	const boundReady =
		!! collectionTermId &&
		( ! needsInstance || !! String( instanceId || '' ).trim() );

	const selectedCollection = useMemo(
		() =>
			collections.find(
				( c ) => Number( c.id ) === Number( collectionTermId )
			) || null,
		[ collections, collectionTermId ]
	);

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

	/* Always refresh picker list from REST (localize can be empty / stale). */
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
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( ! collectionTermId ) {
			setSchema( null );
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
						rows: normalizeRows( data.rows, cols ),
					} );
				} else if ( data.kind === 'model' ) {
					/* Rows come from model-data instance load below. */
				} else {
					setAttributes( {
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

	/* Load selected model-data instance into a single table row. */
	useEffect( () => {
		if ( ! isModel || ! collectionTermId || ! instanceId ) {
			return undefined;
		}
		let cancelled = false;
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
				const match = list.find(
					( inst ) => String( inst.id ) === String( instanceId )
				);
				const cols = ( schema && schema.columns ) || [];
				if ( match ) {
					setAttributes( {
						rows: [
							{
								id: String( match.id ),
								cells: cellsFromInstanceValues(
									match.values,
									cols
								),
							},
						],
					} );
				}
			} )
			.catch( () => {
				/* keep existing rows */
			} );
		return () => {
			cancelled = true;
		};
	}, [ isModel, collectionTermId, instanceId, schema ] );

	const columns = schema && Array.isArray( schema.columns ) ? schema.columns : [];
	const displayRows = normalizeRows( rows, columns );

	const persistCatalogRows = ( nextRows ) => {
		if ( ! isCatalog || ! collectionTermId ) {
			return;
		}
		if ( saveTimer.current ) {
			clearTimeout( saveTimer.current );
		}
		saveTimer.current = setTimeout( () => {
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

	const persistModelInstance = ( nextRows ) => {
		if ( ! isModel || ! collectionTermId || ! instanceId ) {
			return;
		}
		const row = Array.isArray( nextRows ) && nextRows[ 0 ] ? nextRows[ 0 ] : null;
		if ( ! row ) {
			return;
		}
		if ( saveTimer.current ) {
			clearTimeout( saveTimer.current );
		}
		const tax = ( schema && schema.taxonomy ) || '';
		saveTimer.current = setTimeout( () => {
			setSaving( true );
			setSaveNote( i18n.savingInstance || 'Saving instance…' );
			apiFetch( {
				path: `/wtt/v1/model-data/${ collectionTermId }`,
				method: 'POST',
				data: {
					taxonomy: tax || undefined,
					id: String( instanceId ),
					values: valuesFromCells( row.cells ),
				},
			} )
				.then( () => {
					setSaving( false );
					setSaveNote( i18n.savedInstance || 'Instance saved.' );
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
		setSaveNote( '' );
	};

	const onTreeSelect = ( node ) => {
		const id = node && node.id ? parseInt( node.id, 10 ) || 0 : 0;
		setAttributes( {
			collectionTermId: id,
			instanceId: '',
			rows: [],
		} );
		setSaveNote( '' );
	};

	const clearBinding = () => {
		setAttributes( {
			collectionTermId: 0,
			instanceId: '',
			rows: [],
		} );
		setSchema( null );
		setSaveNote( '' );
	};

	const clearInstance = () => {
		setAttributes( {
			instanceId: '',
			rows: [],
		} );
		setSaveNote( '' );
	};

	const onPickInstance = ( inst ) => {
		const id = inst && inst.id ? String( inst.id ) : '';
		const cols = columns.length ? columns : ( schema && schema.columns ) || [];
		setAttributes( {
			instanceId: id,
			rows: id
				? [
						{
							id,
							cells: cellsFromInstanceValues(
								inst && inst.values,
								cols
							),
						},
				  ]
				: [],
		} );
		setSaveNote( '' );
	};

	const addRow = () => {
		if ( isModel ) {
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
		} else if ( isModel ) {
			persistModelInstance( next );
		}
	};

	const titleText = schema ? formatInstanceTitle( schema.name ) : '';
	const showTree = ! collectionTermId;
	const showInstancePicker =
		!! collectionTermId &&
		! loading &&
		needsInstance &&
		! String( instanceId || '' ).trim();
	const showTable = boundReady && schema && columns.length > 0;

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ i18n.title || 'Taxo Model table' }
					initialOpen={ true }
				>
					{ collectionTermId ? (
						<>
							<p className="wtt-collection-table-editor__hint">
								{ ( selectedCollection && selectedCollection.path ) ||
									( schema && schema.path ) ||
									`#${ collectionTermId }` }
							</p>
							{ needsInstance ? (
								<p className="wtt-collection-table-editor__hint">
									{ i18n.datasetLabel || 'Dataset:' }{ ' ' }
									{ formatInstanceChip( instanceId, i18n ) }
								</p>
							) : null }
							<Button variant="secondary" onClick={ clearBinding }>
								{ i18n.changeModel || 'Change model…' }
							</Button>
							{ needsInstance && instanceId ? (
								<Button
									variant="link"
									onClick={ clearInstance }
									style={ { marginLeft: '0.5rem' } }
								>
									{ i18n.changeInstance || 'Change dataset…' }
								</Button>
							) : null }
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
									  'Pick a model or schema node (e.g. Kontakt, Platine). Columns come from its attributes.'
									: i18n.noCollections ||
									  'No model nodes found. Create a node with attributes under Taxonomy Tree, then reload.'
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			{ showTree ? (
				<>
					<p className="wtt-collection-table-editor__hint">
						{ i18n.chooseModelCanvas ||
							'Choose a model node in the tree below.' }
					</p>
					{ collections.length === 0 ? (
						<Notice status="info" isDismissible={ false }>
							{ i18n.noCollections ||
								'No model nodes found. Create a node with attributes under Taxonomy Tree, then reload.' }
						</Notice>
					) : (
						<ModelTreeChooser
							items={ collections }
							selectedId={ collectionTermId }
							onSelect={ onTreeSelect }
							mode="tree"
							i18n={ i18n }
						/>
					) }
				</>
			) : null }

			{ collectionTermId && ! showTree ? (
				<p className="wtt-collection-table-editor__hint">
					{ needsInstance && ! instanceId
						? i18n.flowHintInstance ||
						  'Model bound — pick or create a dataset instance.'
						: i18n.flowHint ||
						  'Choose a model node, then fill the rows.' }
					{ ' ' }
					<strong>
						{ ( selectedCollection && selectedCollection.path ) ||
							( schema && schema.path ) ||
							titleText }
					</strong>
					{ ' ' }
					<Button variant="link" onClick={ clearBinding }>
						{ i18n.changeModel || 'Change model…' }
					</Button>
				</p>
			) : null }

			{ showInstancePicker ? (
				<ModelInstancePicker
					structureId={ collectionTermId }
					taxonomy={ ( schema && schema.taxonomy ) || '' }
					selectedId={ instanceId }
					onSelect={ onPickInstance }
					onCreated={ onPickInstance }
					i18n={ i18n }
				/>
			) : null }

			{ loading && <Spinner /> }

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

			{ schema && ! loading && columns.length === 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ i18n.noColumns ||
						'This node has no attributes yet.' }
				</Notice>
			) }

			{ showTable && (
				<>
					<div className="wtt-collection-table-editor__toolbar">
						<strong>
							{ titleText }
							{ isModel && instanceId
								? ` · ${ formatInstanceChip( instanceId, i18n ) }`
								: '' }
						</strong>
						{ ! isModel ? (
							<Button variant="secondary" onClick={ addRow }>
								{ i18n.addRow || 'Add row' }
							</Button>
						) : (
							<Button variant="link" onClick={ clearInstance }>
								{ i18n.changeInstance || 'Change dataset…' }
							</Button>
						) }
					</div>
					<div className="wtt-collection-table__wrap">
						<table className="wtt-collection-table__table">
							<thead>
								<tr>
									<th scope="col">
										{ i18n.colIndex || '#' }
									</th>
									{ columns.map( ( col ) => (
										<th key={ col.id } scope="col">
											{ col.name }
											{ col.typeName
												? ` (${ col.typeName })`
												: '' }
										</th>
									) ) }
									{ ! isModel ? <th scope="col" /> : null }
								</tr>
							</thead>
							<tbody>
								{ displayRows.length === 0 && (
									<tr>
										<td colSpan={ columns.length + ( isModel ? 1 : 2 ) }>
											—
										</td>
									</tr>
								) }
								{ displayRows.map( ( row, index ) => (
									<tr key={ row.id }>
										<td>{ index + 1 }</td>
										{ columns.map( ( col ) => {
											const typeKey = columnTypeKey( col );
											const cellVal =
												row.cells[ String( col.id ) ] ||
												'';
											if ( typeKey === 'node_ref' ) {
												return (
													<td
														key={ col.id }
														className="wtt-collection-table-editor__cell--node-ref"
													>
														<NodeRefCell
															column={ col }
															value={ cellVal }
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
																e.target.value
															)
														}
													/>
												</td>
											);
										} ) }
										{ ! isModel ? (
											<td className="wtt-collection-table-editor__row-actions">
												<Button
													isDestructive
													variant="link"
													onClick={ () =>
														removeRow( row.id )
													}
												>
													{ i18n.removeRow || 'Remove' }
												</Button>
											</td>
										) : null }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }
		</div>
	);
}
