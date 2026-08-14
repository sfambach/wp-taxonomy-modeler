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
const defaultTaxonomy = cfg.defaultTaxonomy || 'wtt_fs';
/* Explicit focus/default = catalog binding `model` — never chooser_focus. */
const modelId = parseInt( cfg.modelId, 10 ) || 0;
const chooserRoot = parseInt( cfg.chooserRoot, 10 ) || 0;

function siteDefaultRenderDepth() {
	const n = parseInt( cfg.defaultRenderDepth, 10 );
	if ( Number.isNaN( n ) ) {
		return 1;
	}
	return Math.max( 0, Math.min( 5, n ) );
}

function formatInstanceChip( instanceId, i18nMap ) {
	const id = String( instanceId || '' ).trim();
	if ( ! id ) {
		return i18nMap.noInstance || 'No dataset';
	}
	return id;
}

/**
 * Object layout wire ids (Q113). Accepts legacy form|table|compact|embed on read.
 *
 * @param {string} raw
 * @return {string}
 */
function normalizeObjectLayoutAttr( raw ) {
	const s = String( raw == null ? '' : raw ).trim();
	if ( ! s || s === 'auto' ) {
		return s || 'auto';
	}
	const key = s.toLowerCase();
	const map = {
		form: 'FormRenderer',
		formrenderer: 'FormRenderer',
		table: 'TableRenderer',
		tablerenderer: 'TableRenderer',
		list: 'TableRenderer',
		compact: 'CompactRenderer',
		compactrenderer: 'CompactRenderer',
		'compact-horizontal': 'CompactRenderer',
		'compact-h': 'CompactRenderer',
		'compact-vertical': 'CompactVerticalRenderer',
		compactverticalrenderer: 'CompactVerticalRenderer',
		'compact-v': 'CompactVerticalRenderer',
		embed: 'MultistepRenderer',
		embeddedrenderer: 'MultistepRenderer',
		'pick-fill': 'MultistepRenderer',
		pick_fill: 'MultistepRenderer',
		'compact-embed': 'MultistepRenderer',
		multistep: 'MultistepRenderer',
		multisteprenderer: 'MultistepRenderer',
	};
	return map[ key ] || 'FormRenderer';
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
		/* Q97: related Mult many lives in links[] — never persist as host attr blob. */
		if (
			prop &&
			( prop.usesRelatedInstances || prop.isRelatedDataset )
		) {
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
		layout = 'auto',
		renderDepth = siteDefaultRenderDepth(),
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
	const [ viewTick, setViewTick ] = useState( 0 );
	const previewRef = useRef( null );
	const saveTimer = useRef( null );
	const relatedSaveTimers = useRef( {} );
	const pendingRef = useRef( {} );
	const viewRef = useRef( null );

	pendingRef.current = pendingValues;
	viewRef.current = view;

	const selectedNode = useMemo(
		() =>
			nodes.find( ( n ) => Number( n.id ) === Number( termId ) ) || null,
		[ nodes, termId ]
	);

	const showInstancePicker =
		!! termId && ! loading && ! String( instanceId || '' ).trim();
	const boundReady = !! termId;
	const canEdit = !! termId && !! String( instanceId || '' ).trim();

	/* Default taxonomy + select Model by binding id when unbound. */
	useEffect( () => {
		if ( taxonomy ) {
			return;
		}
		setAttributes( { taxonomy: defaultTaxonomy } );
	}, [ taxonomy, defaultTaxonomy, setAttributes ] );

	useEffect( () => {
		if ( termId || ! modelId ) {
			return;
		}
		setAttributes( {
			termId: modelId,
			taxonomy: taxonomy || defaultTaxonomy,
			instanceId: '',
		} );
	}, [ termId, modelId, taxonomy, defaultTaxonomy, setAttributes ] );

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
						instanceId: String( instanceId ),
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

	const reloadView = useCallback( () => {
		if ( ! termId ) {
			return Promise.resolve();
		}
		const params = new URLSearchParams();
		if ( taxonomy ) {
			params.set( 'taxonomy', taxonomy );
		}
		if ( instanceId ) {
			params.set( 'instanceId', instanceId );
		}
		const qs = params.toString() ? `?${ params.toString() }` : '';
		return apiFetch( {
			path: `/wtt/v1/object-view/${ termId }${ qs }`,
		} )
			.then( ( data ) => {
				setView( data );
				setViewTick( ( n ) => n + 1 );
				if ( data && data.taxonomy && data.taxonomy !== taxonomy ) {
					setAttributes( { taxonomy: data.taxonomy } );
				}
			} )
			.catch( ( err ) => {
				setError(
					( err && err.message ) ||
						i18n.notFound ||
						'Node not found.'
				);
			} );
	}, [ termId, taxonomy, instanceId, setAttributes ] );

	const onRelatedFieldInput = useCallback(
		( prop, field, next, instance ) => {
			if ( ! canEdit || ! prop || ! instance || ! instance.id ) {
				return;
			}
			const childSid =
				parseInt( instance.structureId, 10 ) ||
				parseInt( prop.typeId, 10 ) ||
				0;
			if ( childSid <= 0 ) {
				return;
			}
			const idKey =
				field && field.id != null
					? String( field.id )
					: String( ( field && field.name ) || '' );
			if ( ! instance.values ) {
				instance.values = {};
			}
			instance.values[ idKey ] = next == null ? '' : String( next );

			const timerKey = String( instance.id );
			if ( relatedSaveTimers.current[ timerKey ] ) {
				clearTimeout( relatedSaveTimers.current[ timerKey ] );
			}
			relatedSaveTimers.current[ timerKey ] = setTimeout( () => {
				delete relatedSaveTimers.current[ timerKey ];
				const tax =
					taxonomy ||
					( viewRef.current && viewRef.current.taxonomy ) ||
					'';
				setSaving( true );
				setSaveNote( i18n.savingInstance || 'Saving instance…' );
				apiFetch( {
					path: `/wtt/v1/model-data/${ childSid }`,
					method: 'POST',
					data: {
						taxonomy: tax || undefined,
						instanceId: String( instance.id ),
						values: instance.values || {},
					},
				} )
					.then( () => {
						setSaving( false );
						setSaveNote( i18n.lineSaved || 'Line saved.' );
					} )
					.catch( ( err ) => {
						setSaving( false );
						setError( ( err && err.message ) || 'Save failed' );
						setSaveNote( '' );
					} );
			}, 400 );
		},
		[ canEdit, taxonomy ]
	);

	const onAddRelatedLine = useCallback(
		( prop ) => {
			if ( ! canEdit || ! prop || ! termId || ! instanceId ) {
				return;
			}
			const childSid = parseInt( prop.typeId, 10 ) || 0;
			if ( childSid <= 0 ) {
				return;
			}
			const tax =
				taxonomy ||
				( viewRef.current && viewRef.current.taxonomy ) ||
				'';
			const relation =
				( prop.binding && String( prop.binding ) ) || 'besteht_aus';
			setSaving( true );
			setSaveNote( i18n.loading || 'Loading…' );
			apiFetch( {
				path: `/wtt/v1/model-data/${ termId }/create-linked`,
				method: 'POST',
				data: {
					taxonomy: tax || undefined,
					parentInstanceId: String( instanceId ),
					childStructureId: childSid,
					relation,
					values: {},
				},
			} )
				.then( () => {
					setSaveNote( i18n.lineCreated || 'Line created.' );
					return reloadView();
				} )
				.then( () => {
					setSaving( false );
				} )
				.catch( ( err ) => {
					setSaving( false );
					setError( ( err && err.message ) || 'Create failed' );
					setSaveNote( '' );
				} );
		},
		[ canEdit, termId, instanceId, taxonomy, reloadView ]
	);

	useEffect( () => {
		const host = previewRef.current;
		const api = window.WTTObjectRender;
		if ( ! host ) {
			return undefined;
		}
		if ( api && typeof api.mount === 'function' ) {
			api.mount( host, view, {
				layout: normalizeObjectLayoutAttr( layout || 'auto' ),
				renderDepth:
					typeof renderDepth === 'number'
						? renderDepth
						: siteDefaultRenderDepth(),
				referenceMode: referenceMode || 'link',
				mode: canEdit ? 'edit' : 'display',
				readonly: ! canEdit,
				onFieldInput: canEdit ? onFieldInput : null,
				onRelatedFieldInput: canEdit ? onRelatedFieldInput : null,
				onAddRelatedLine: canEdit ? onAddRelatedLine : null,
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
		viewTick,
		layout,
		renderDepth,
		referenceMode,
		canEdit,
		onFieldInput,
		onRelatedFieldInput,
		onAddRelatedLine,
	] );

	useEffect( () => {
		return () => {
			if ( saveTimer.current ) {
				clearTimeout( saveTimer.current );
			}
			Object.keys( relatedSaveTimers.current ).forEach( ( key ) => {
				clearTimeout( relatedSaveTimers.current[ key ] );
			} );
			relatedSaveTimers.current = {};
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
			termId: modelId || 0,
			taxonomy: taxonomy || defaultTaxonomy,
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
						help={
							i18n.layoutAutoHelp ||
							'Node preferred uses the layout stored on the bound node.'
						}
						value={ normalizeObjectLayoutAttr( layout || 'auto' ) }
						options={ [
							{
								label: i18n.layoutAuto || 'Node preferred',
								value: 'auto',
							},
							{
								label: i18n.layoutForm || 'Form + Table (auto)',
								value: 'FormRenderer',
							},
							{
								label: i18n.layoutTable || 'Table (all)',
								value: 'TableRenderer',
							},
							{
								label: i18n.layoutCompact || 'Compact (horizontal)',
								value: 'CompactRenderer',
							},
							{
								label:
									i18n.layoutCompactVertical ||
									'Compact (vertical)',
								value: 'CompactVerticalRenderer',
							},
							{
								label: i18n.layoutEmbed || i18n.layoutMultistep || 'Multistep',
								value: 'MultistepRenderer',
							},
						] }
						onChange={ ( next ) => {
							const v = next || 'auto';
							setAttributes( {
								layout:
									v === 'auto'
										? 'auto'
										: normalizeObjectLayoutAttr( v ),
							} );
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
							( i18n.renderDepthHelp ||
								'How deep nested objects are expanded. 0 = meta only; 1 = this node and its direct attributes.' ) +
							' ' +
							( i18n.renderDepthSiteDefault ||
								'Site default' ) +
							': ' +
							String( siteDefaultRenderDepth() ) +
							'.'
						}
						value={
							typeof renderDepth === 'number'
								? renderDepth
								: siteDefaultRenderDepth()
						}
						onChange={ ( next ) => {
							const fallback = siteDefaultRenderDepth();
							const n =
								typeof next === 'number' && ! Number.isNaN( next )
									? next
									: fallback;
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

			{ nodes.length === 0 ? (
				<Notice status="info" isDismissible={ false }>
					{ i18n.noNodes || 'No nodes found in this taxonomy.' }
				</Notice>
			) : (
				<ModelTreeChooser
					items={ nodes }
					rootId={ chooserRoot || 0 }
					selectedId={ termId || modelId }
					focusId={ modelId || 0 }
					expandFocusBranch={ true }
					onSelect={ onTreeSelect }
					mode="tree"
					i18n={ i18n }
					className="wtt-object-view-editor__tree"
				/>
			) }

			{ boundReady ? (
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
