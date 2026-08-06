import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useEffect, useState, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const cfg = window.wttObjectView || {};
const i18n = cfg.i18n || {};

function taxonomyOptions( taxonomies ) {
	const list = Array.isArray( taxonomies ) ? taxonomies : [];
	return [
		{
			label: i18n.pickTaxonomy || 'Taxonomy…',
			value: '',
		},
		...list.map( ( t ) => ( {
			label: t.label || t.slug,
			value: String( t.slug ),
		} ) ),
	];
}

function nodeOptions( nodes, taxonomy ) {
	const list = ( Array.isArray( nodes ) ? nodes : [] ).filter(
		( n ) => ! taxonomy || n.taxonomy === taxonomy
	);
	return [
		{
			label: i18n.pickNode || 'Node…',
			value: '0',
		},
		...list.map( ( n ) => ( {
			label: `${ n.path } (${ n.id })`,
			value: String( n.id ),
		} ) ),
	];
}

export default function ObjectViewEdit( { attributes, setAttributes } ) {
	const { termId = 0, taxonomy = '' } = attributes;
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
			return;
		}
		let cancelled = false;
		setLoading( true );
		setError( '' );
		const qs = taxonomy
			? `?taxonomy=${ encodeURIComponent( taxonomy ) }`
			: '';
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
	}, [ termId, taxonomy, setAttributes ] );

	useEffect( () => {
		const host = previewRef.current;
		const api = window.WTTObjectRender;
		if ( ! host ) {
			return undefined;
		}
		if ( api && typeof api.mount === 'function' ) {
			api.mount( host, view );
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
	}, [ view ] );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ i18n.title || 'Taxo Object view' }
					initialOpen={ true }
				>
					<p className="wtt-object-view-editor__hint">
						{ i18n.pickHint ||
							'Bind a taxonomy tree node to display its name, descriptions, and attributes.' }
					</p>
					<SelectControl
						label={ i18n.pickTaxonomy || 'Taxonomy' }
						value={ taxonomy || '' }
						options={ taxonomyOptions( taxonomies ) }
						onChange={ ( next ) => {
							setAttributes( {
								taxonomy: next || '',
								termId: 0,
							} );
						} }
					/>
					<SelectControl
						label={ i18n.pickNode || 'Node' }
						value={ String( termId || 0 ) }
						options={ nodeOptions( nodes, taxonomy ) }
						onChange={ ( next ) => {
							const id = parseInt( next, 10 ) || 0;
							const match = nodes.find(
								( n ) => Number( n.id ) === id
							);
							setAttributes( {
								termId: id,
								taxonomy:
									( match && match.taxonomy ) || taxonomy || '',
							} );
						} }
					/>
				</PanelBody>
			</InspectorControls>

			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }

			{ loading ? <Spinner /> : null }

			{ ! termId && ! loading ? (
				<p className="wtt-object-view-editor__hint">
					{ i18n.empty || 'Select a node in the sidebar.' }
				</p>
			) : null }

			<div
				className="wtt-object-view-editor__preview"
				ref={ previewRef }
			/>
		</div>
	);
}
