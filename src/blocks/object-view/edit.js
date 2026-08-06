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

const cfg = window.wttObjectView || {};
const i18n = cfg.i18n || {};

function formatInstanceChip( instanceId, i18nMap ) {
	const id = String( instanceId || '' ).trim();
	if ( ! id ) {
		return i18nMap.noInstance || 'No dataset';
	}
	return id;
}

export default function ObjectViewEdit( { attributes, setAttributes } ) {
	const {
		termId = 0,
		taxonomy = '',
		instanceId = '',
		layout = 'form',
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
	const previewRef = useRef( null );

	const selectedNode = useMemo(
		() =>
			nodes.find( ( n ) => Number( n.id ) === Number( termId ) ) || null,
		[ nodes, termId ]
	);

	const showTree = ! termId;
	const showInstancePicker =
		!! termId && ! loading && ! String( instanceId || '' ).trim();
	const boundReady = !! termId;

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
			return undefined;
		}
		let cancelled = false;
		setLoading( true );
		setError( '' );
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

	useEffect( () => {
		const host = previewRef.current;
		const api = window.WTTObjectRender;
		if ( ! host ) {
			return undefined;
		}
		if ( api && typeof api.mount === 'function' ) {
			api.mount( host, view, { layout: layout || 'form' } );
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
	}, [ view, layout ] );

	const onTreeSelect = ( node ) => {
		const id = node && node.id != null ? parseInt( node.id, 10 ) || 0 : 0;
		setAttributes( {
			termId: id,
			taxonomy: ( node && node.taxonomy ) || taxonomy || '',
			instanceId: '',
		} );
		setError( '' );
	};

	const clearBinding = () => {
		setAttributes( {
			termId: 0,
			taxonomy: '',
			instanceId: '',
		} );
		setView( null );
		setError( '' );
	};

	const clearInstance = () => {
		setAttributes( { instanceId: '' } );
	};

	const onPickInstance = ( inst ) => {
		const id = inst && inst.id ? String( inst.id ) : '';
		setAttributes( { instanceId: id } );
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

			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
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
