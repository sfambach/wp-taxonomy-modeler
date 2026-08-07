import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useEffect, useState, useRef, useMemo, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ModelTreeChooser from '../shared/model-tree-chooser';
import ModelInstancePicker from '../shared/model-instance-picker';
import '../shared/model-bind.scss';

const cfg = window.wttObjectView || {};
const i18n = cfg.i18n || {};

function formatInstanceChip( instanceId, i18nMap ) {
	const id = String( instanceId || '' ).trim();
	if ( ! id ) {
		return i18nMap.noInstance || 'No dataset';
	}
	return id;
}

/**
 * Collect attr-id → store string from a live Object View DTO + pending edits map.
 *
 * @param {object|null} view
 * @param {Record<string, string>} pending
 * @return {Record<string, string>}
 */
function collectInstanceValues( view, pending ) {
	const out = {};
	const props =
		view && Array.isArray( view.properties ) ? view.properties : [];
	const fromView =
		view && view.instanceValues && typeof view.instanceValues === 'object'
			? view.instanceValues
			: {};
	props.forEach( ( prop ) => {
		const id = prop && prop.id != null ? String( prop.id ) : '';
		if ( ! id || id === '0' ) {
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( pending, id ) ) {
			out[ id ] = String( pending[ id ] ?? '' );
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( fromView, id ) ) {
			out[ id ] = String( fromView[ id ] ?? '' );
			return;
		}
		if ( Array.isArray( prop.values ) && prop.values.length ) {
			out[ id ] =
				prop.values.length > 1
					? JSON.stringify( prop.values.map( String ) )
					: String( prop.values[ 0 ] );
		}
	} );
	Object.keys( pending ).forEach( ( id ) => {
		if ( ! Object.prototype.hasOwnProperty.call( out, id ) ) {
			out[ id ] = String( pending[ id ] ?? '' );
		}
	} );
	return out;
}

export default function ObjectViewEdit( { attributes, setAttributes } ) {
	const {
		termId = 0,
		taxonomy = '',
		instanceId = '',
		layout = 'form',
		renderDepth = 1,
		referenceMode = 'link',
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'wtt-object-view-editor',
	} );

	const [ taxonomies, setTaxonomies ] = useState( () =>
		Array.isArray( cfg.taxonomies ) ? cfg.taxonomies : []
	);
	const [ nodes, setNodes ] = useState( () =>
		Array.isArray( cfg.nodes ) ? cfg.nodes : []
	);
	const [ view, setView ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saveNote, setSaveNote ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ pendingValues, setPendingValues ] = useState( {} );
	const previewRef = useRef( null );
	const saveTimer = useRef( null );
	const pendingRef = useRef( {} );
	const viewRef = useRef( null );

	pendingRef.current = pendingValues;
	viewRef.current = view;

	const selectedNode = useMemo(
		() =>
			nodes.find( ( n ) => Number( n.id ) === Number( termId ) ) || null,
		[ nodes, termId ]
	);

	const showTree = ! termId;
	const showInstancePicker =
		!! termId && ! loading && ! String( instanceId || '' ).trim();
	const boundReady = !! termId;
	const canEdit = !! termId && !! String( instanceId || '' ).trim();

	useEffect( () => {
		window.wttTree = Object.assign( {}, window.wttTree || {}, {
			ajaxUrl: cfg.ajaxUrl || '',
			nonce: cfg.ajaxNonce || '',
			taxonomy: taxonomy || '',
			treePickerMode: cfg.treePickerMode || 'popup',
			i18n: Object.assign(
				{},
				( window.wttTree && window.wttTree.i18n ) || {},
				i18n
			),
		} );
	}, [ taxonomy ] );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/wtt/v1/object-view/taxonomies' } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				const list =
					data && Array.isArray( data.taxonomies ) ? data.taxonomies : [];
				setTaxonomies( list );
			} )
			.catch( () => {
				/* keep localized fallback */
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		let cancelled = false;
		const path = taxonomy
			? `/wtt/v1/object-view/nodes?taxonomy=${ encodeURIComponent( taxonomy ) }`
			: '/wtt/v1/object-view/nodes';
		apiFetch( { path } )
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
	}, [ taxonomy ] );

	useEffect( () => {
		if ( ! termId ) {
			setView( null );
			setError( '' );
			setPendingValues( {} );
			return undefined;
		}
		let cancelled = false;
		setLoading( true );
		setError( '' );
		setPendingValues( {} );
		const params = new URLSearchParams();
		if ( taxonomy ) {
			params.set( 'taxonomy', taxonomy );
		}
		if ( instanceId ) {
			params.set( 'instanceId', instanceId );
		}
		const qs = params.toString() ? `?${ params.toString() }` : '';
		apiFetch( {
			path: `/wtt/v1/object-view/${ termId }${ qs }`,
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setView( data );
				setLoading( false );
				if ( data && data.taxonomy && data.taxonomy !== taxonomy ) {
					setAttributes( { taxonomy: data.taxonomy } );
				}
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setView( null );
				setLoading( false );
				setError(
					( err && err.message ) ||
						i18n.notFound ||
						'Node not found.'
				);
			} );
		return () => {
			cancelled = true;
		};
	}, [ termId, taxonomy, instanceId, setAttributes ] );

	const persistInstance = useCallback(
		( nextPending ) => {
			if ( ! canEdit || ! termId ) {
				return;
			}
			if ( saveTimer.current ) {
				clearTimeout( saveTimer.current );
			}
			saveTimer.current = setTimeout( () => {
				const values = collectInstanceValues(
					viewRef.current,
					nextPending || pendingRef.current
				);
				const tax =
					taxonomy ||
					( viewRef.current && viewRef.current.taxonomy ) ||
					'';
				setSaving( true );
				setSaveNote( i18n.savingInstance || 'Saving instance…' );
				apiFetch( {
					path: `/wtt/v1/model-data/${ termId }`,
					method: 'POST',
					data: {
						taxonomy: tax || undefined,
						id: String( instanceId ),
						values,
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
		},
		[ canEdit, termId, taxonomy, instanceId ]
	);

	const onFieldInput = useCallback(
		( field, next ) => {
			if ( ! canEdit || ! field ) {
				return;
			}
			const id = field.id != null ? String( field.id ) : '';
			if ( ! id || id === '0' ) {
				return;
			}
			const merged = {
				...pendingRef.current,
				[ id ]: next == null ? '' : String( next ),
			};
			setPendingValues( merged );
			persistInstance( merged );
		},
		[ canEdit, persistInstance ]
	);

	useEffect( () => {
		const host = previewRef.current;
		const api = window.WTTObjectRender;
		if ( ! host ) {
			return undefined;
		}
		if ( api && typeof api.mount === 'function' ) {
			api.mount( host, view, {
				layout: layout || 'form',
				renderDepth:
					typeof renderDepth === 'number' ? renderDepth : 1,
				referenceMode: referenceMode || 'link',
				mode: canEdit ? 'edit' : 'display',
				readonly: ! canEdit,
				onFieldInput: canEdit ? onFieldInput : null,
			} );
		} else {
			host.textContent = '';
			if ( view && view.name ) {
				const title = document.createElement( 'p' );
				title.textContent = String( view.name );
				host.appendChild( title );
			}
		}
		return () => {
			if ( host ) {
				host.textContent = '';
			}
		};
	}, [
		view,
		layout,
		renderDepth,
		referenceMode,
		canEdit,
		onFieldInput,
	] );

	useEffect( () => {
		return () => {
			if ( saveTimer.current ) {
				clearTimeout( saveTimer.current );
			}
		};
	}, [] );

	const onTreeSelect = ( node ) => {
		const id = node && node.id != null ? parseInt( node.id, 10 ) || 0 : 0;
		setAttributes( {
			termId: id,
			taxonomy: ( node && node.taxonomy ) || taxonomy || '',
			instanceId: '',
		} );
		setError( '' );
		setSaveNote( '' );
	};

	const clearBinding = () => {
		setAttributes( {
			termId: 0,
			taxonomy: '',
			instanceId: '',
		} );
		setView( null );
		setError( '' );
		setSaveNote( '' );
		setPendingValues( {} );
	};

	const clearInstance = () => {
		setAttributes( { instanceId: '' } );
		setSaveNote( '' );
		setPendingValues( {} );
	};

	const onPickInstance = ( inst ) => {
		const id = inst && inst.id ? String( inst.id ) : '';
		setAttributes( { instanceId: id } );
		setSaveNote( '' );
		setPendingValues( {} );
	};

	const taxonomyOptions = [
		{
			label: i18n.pickTaxonomy || 'Taxonomy…',
			value: '',
		},
		...( Array.isArray( taxonomies ) ? taxonomies : [] ).map( ( t ) => ( {
			label: t.label || t.slug,
			value: String( t.slug ),
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ i18n.title || 'Taxo Object view' }
					initialOpen={ true }
				>
					{ termId ? (
						<>
							<p className="wtt-object-view-editor__hint">
								{ ( selectedNode && selectedNode.path ) ||
									( view && view.path ) ||
									`#${ termId }` }
							</p>
							<p className="wtt-object-view-editor__hint">
								{ i18n.datasetLabel || 'Dataset:' }{ ' ' }
								{ formatInstanceChip( instanceId, i18n ) }
							</p>
							<Button variant="secondary" onClick={ clearBinding }>
								{ i18n.changeModel || 'Change model…' }
							</Button>
							{ instanceId ? (
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
						<>
							<p className="wtt-object-view-editor__hint">
								{ i18n.pickHint ||
									'Bind a taxonomy tree node to display its name, descriptions, and attributes.' }
							</p>
							<SelectControl
								label={ i18n.pickTaxonomy || 'Taxonomy' }
								value={ taxonomy || '' }
								options={ taxonomyOptions }
								onChange={ ( next ) => {
									setAttributes( {
										taxonomy: next || '',
										termId: 0,
										instanceId: '',
									} );
								} }
							/>
						</>
					) }
					<SelectControl
						label={ i18n.pickLayout || 'Layout' }
						value={ layout || 'form' }
						options={ [
							{
								label: i18n.layoutForm || 'Form + Table (auto)',
								value: 'form',
							},
							{
								label: i18n.layoutTable || 'Table (all)',
								value: 'table',
							},
							{
								label: i18n.layoutCompact || 'Compact (horizontal)',
								value: 'compact',
							},
							{
								label:
									i18n.layoutCompactVertical ||
									'Compact (vertical)',
								value: 'compact-vertical',
							},
						] }
						onChange={ ( next ) => {
							setAttributes( { layout: next || 'form' } );
						} }
					/>
				</PanelBody>
				{ /* TODO(per-attribute): later allow per-attribute renderDepth / referenceMode overrides. */ }
				<PanelBody
					title={ i18n.renderingPanel || 'Rendering' }
					initialOpen={ false }
				>
					<RangeControl
						label={ i18n.pickRenderDepth || 'Render depth' }
						help={
							i18n.renderDepthHelp ||
							'How deep nested objects are expanded. 1 = this node and its attributes; 0 = meta only.'
						}
						value={
							typeof renderDepth === 'number' ? renderDepth : 1
						}
						onChange={ ( next ) => {
							const n =
								typeof next === 'number' && ! Number.isNaN( next )
									? next
									: 1;
							setAttributes( {
								renderDepth: Math.max( 0, Math.min( 5, n ) ),
							} );
						} }
						min={ 0 }
						max={ 5 }
						step={ 1 }
					/>
					<SelectControl
						label={ i18n.pickReferenceMode || 'Reference rendering' }
						help={
							i18n.referenceModeHelp ||
							'How node references and catalog picks are shown when not editing.'
						}
						value={ referenceMode || 'link' }
						options={ [
							{
								label: i18n.referenceModeNone || 'None (omit)',
								value: 'none',
							},
							{
								label: i18n.referenceModeLink || 'Link / name',
								value: 'link',
							},
							{
								label: i18n.referenceModeSummary || 'Summary',
								value: 'summary',
							},
							{
								label:
									i18n.referenceModeEmbed ||
									'Embed (nested view)',
								value: 'embed',
							},
						] }
						onChange={ ( next ) => {
							setAttributes( { referenceMode: next || 'link' } );
						} }
					/>
				</PanelBody>
			</InspectorControls>

			{ showTree ? (
				<>
					<p className="wtt-object-view-editor__hint">
						{ i18n.chooseModelCanvas ||
							'Choose a model node in the tree below.' }
					</p>
					{ nodes.length === 0 ? (
						<Notice status="info" isDismissible={ false }>
							{ i18n.noNodes ||
								'No nodes found in this taxonomy.' }
						</Notice>
					) : (
						<ModelTreeChooser
							items={ nodes }
							selectedId={ termId }
							onSelect={ onTreeSelect }
							mode="tree"
							i18n={ i18n }
						/>
					) }
				</>
			) : null }

			{ boundReady && ! showTree ? (
				<p className="wtt-object-view-editor__hint">
					{ ! instanceId
						? i18n.flowHintInstance ||
						  'Model bound — pick or create a dataset instance.'
						: i18n.pickHint ||
						  'Bound node and dataset.' }
					{ ' ' }
					<strong>
						{ ( selectedNode && selectedNode.path ) ||
							( view && view.path ) ||
							( view && view.name ) ||
							`#${ termId }` }
					</strong>
					{ ' ' }
					<Button variant="link" onClick={ clearBinding }>
						{ i18n.changeModel || 'Change model…' }
					</Button>
					{ instanceId ? (
						<>
							{ ' ' }
							<Button variant="link" onClick={ clearInstance }>
								{ i18n.changeInstance || 'Change dataset…' }
							</Button>
						</>
					) : null }
				</p>
			) : null }

			{ showInstancePicker ? (
				<ModelInstancePicker
					structureId={ termId }
					taxonomy={
						taxonomy ||
						( view && view.taxonomy ) ||
						( selectedNode && selectedNode.taxonomy ) ||
						''
					}
					selectedId={ instanceId }
					onSelect={ onPickInstance }
					onCreated={ onPickInstance }
					i18n={ i18n }
				/>
			) : null }

			{ boundReady && ! canEdit && ! showInstancePicker && ! loading ? (
				<p className="wtt-object-view-editor__hint">
					{ i18n.editNeedsInstance ||
						'Pick a dataset to edit attribute values.' }
				</p>
			) : null }

			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }

			{ saveNote || saving ? (
				<p className="wtt-object-view-editor__hint">
					{ saveNote }
					{ saving ? '…' : '' }
				</p>
			) : null }

			{ loading ? <Spinner /> : null }

			{ boundReady && ! loading ? (
				<div
					className="wtt-object-view-editor__preview"
					ref={ previewRef }
				/>
			) : null }
		</div>
	);
}
