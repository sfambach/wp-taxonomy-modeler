/**
 * Model-data instance list + Create new (Fill Model Data style).
 * Shared by Taxo Model table / Object view when binding a structure host.
 */
import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

function formatInstanceLabel( inst, i18n ) {
	const seq = inst && inst.seq != null ? String( inst.seq ) : '';
	const id = inst && inst.id ? String( inst.id ) : '';
	const modified =
		( inst && ( inst.modifiedAtLabel || inst.modifiedAt ) ) || '';
	const ver = inst && inst.version != null ? String( inst.version ) : '';
	const parts = [];
	if ( seq ) {
		parts.push( `#${ seq }` );
	}
	if ( ver ) {
		parts.push( `v${ ver }` );
	}
	if ( modified ) {
		parts.push( modified );
	}
	if ( ! parts.length && id ) {
		parts.push( id );
	}
	return parts.join( ' · ' ) || ( i18n.unnamedInstance || 'Instance' );
}

/**
 * @param {Object} props
 * @param {number} props.structureId Model / structure term id.
 * @param {string} [props.taxonomy]
 * @param {string} [props.selectedId] Current instance id (md_…).
 * @param {Function} props.onSelect (instance) => void
 * @param {Function} [props.onCreated] (instance) => void — after create
 * @param {Object} [props.i18n]
 * @param {string} [props.className]
 */
export default function ModelInstancePicker( {
	structureId,
	taxonomy = '',
	selectedId = '',
	onSelect,
	onCreated,
	i18n = {},
	className = '',
} ) {
	const [ instances, setInstances ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const id = parseInt( structureId, 10 ) || 0;
		if ( ! id ) {
			setInstances( [] );
			setError( '' );
			return undefined;
		}
		let cancelled = false;
		setLoading( true );
		setError( '' );
		const qs = taxonomy
			? `?taxonomy=${ encodeURIComponent( taxonomy ) }`
			: '';
		apiFetch( { path: `/wtt/v1/model-data/${ id }${ qs }` } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setInstances(
					data && Array.isArray( data.instances ) ? data.instances : []
				);
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setInstances( [] );
				setLoading( false );
				setError(
					( err && err.message ) ||
						i18n.instanceLoadFailed ||
						'Could not load instances.'
				);
			} );
		return () => {
			cancelled = true;
		};
	}, [ structureId, taxonomy, i18n.instanceLoadFailed ] );

	const createNew = () => {
		const id = parseInt( structureId, 10 ) || 0;
		if ( ! id || creating ) {
			return;
		}
		setCreating( true );
		setError( '' );
		apiFetch( {
			path: `/wtt/v1/model-data/${ id }`,
			method: 'POST',
			data: {
				taxonomy: taxonomy || undefined,
				values: {},
			},
		} )
			.then( ( data ) => {
				setCreating( false );
				const list =
					data && Array.isArray( data.instances )
						? data.instances
						: [];
				setInstances( list );
				const created = data && data.instance ? data.instance : null;
				if ( created ) {
					if ( typeof onCreated === 'function' ) {
						onCreated( created );
					} else if ( typeof onSelect === 'function' ) {
						onSelect( created );
					}
				}
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

	return (
		<div
			className={
				'wtt-model-instance-picker' +
				( className ? ` ${ className }` : '' )
			}
		>
			<div className="wtt-model-instance-picker__toolbar">
				<strong>
					{ i18n.pickInstance || 'Dataset (instance)' }
				</strong>
				<Button
					variant="primary"
					onClick={ createNew }
					disabled={ creating || loading }
				>
					{ creating ? (
						<Spinner />
					) : (
						i18n.createInstance || 'Create new'
					) }
				</Button>
			</div>
			<p className="wtt-model-instance-picker__hint">
				{ i18n.pickInstanceHint ||
					'Pick an existing model-data instance or create a new one.' }
			</p>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }
			{ loading ? <Spinner /> : null }
			{ ! loading && instances.length === 0 ? (
				<p className="wtt-model-instance-picker__empty">
					{ i18n.noInstances ||
						'No instances yet. Create one to continue.' }
				</p>
			) : null }
			{ ! loading && instances.length > 0 ? (
				<ul className="wtt-model-instance-picker__list">
					{ instances.map( ( inst ) => {
						const iid = String( ( inst && inst.id ) || '' );
						const active = iid && iid === String( selectedId || '' );
						return (
							<li key={ iid || formatInstanceLabel( inst, i18n ) }>
								<button
									type="button"
									className={
										'wtt-model-instance-picker__row' +
										( active ? ' is-active' : '' )
									}
									onClick={ () => {
										if ( typeof onSelect === 'function' ) {
											onSelect( inst );
										}
									} }
								>
									<span className="wtt-model-instance-picker__label">
										{ formatInstanceLabel( inst, i18n ) }
									</span>
									{ iid ? (
										<code className="wtt-model-instance-picker__id">
											{ iid }
										</code>
									) : null }
								</button>
							</li>
						);
					} ) }
				</ul>
			) : null }
		</div>
	);
}
