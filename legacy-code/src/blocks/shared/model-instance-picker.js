/**
 * Model-data instance picker: searchable table with row selection.
 * Shared by Taxo Table view / Object view when binding a structure host.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {Object} inst
 * @return {{ seq: string, version: string, modified: string, id: string }}
 */
function instanceCells( inst ) {
	return {
		seq: inst && inst.seq != null ? String( inst.seq ) : '',
		version: inst && inst.version != null ? String( inst.version ) : '',
		modified:
			( inst && ( inst.modifiedAtLabel || inst.modifiedAt ) ) || '',
		id: inst && inst.id ? String( inst.id ) : '',
	};
}

/**
 * @param {Object} inst
 * @param {string} query
 * @return {boolean}
 */
function instanceMatches( inst, query ) {
	const q = String( query || '' )
		.trim()
		.toLowerCase();
	if ( ! q ) {
		return true;
	}
	const cells = instanceCells( inst );
	const hay = [ cells.seq, cells.version, cells.modified, cells.id ]
		.join( ' ' )
		.toLowerCase();
	return hay.includes( q );
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
	const [ query, setQuery ] = useState( '' );

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

	const filtered = useMemo(
		() => instances.filter( ( inst ) => instanceMatches( inst, query ) ),
		[ instances, query ]
	);

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

	const colSeq = i18n.colIndex || i18n.colSeq || '#';
	const colVersion = i18n.colVersion || 'Version';
	const colModified = i18n.colModified || 'Modified';
	const colId = i18n.colInstanceId || i18n.colId || 'Id';

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
				<>
					<div className="wtt-model-instance-picker__tools">
						<div className="wtt-model-instance-picker__search">
							<input
								type="search"
								className="wtt-model-instance-picker__search-input"
								value={ query }
								placeholder={
									i18n.instanceSearchPlaceholder ||
									i18n.nodePickerSearchPlaceholder ||
									'Search…'
								}
								aria-label={
									i18n.instanceSearch ||
									i18n.nodePickerSearch ||
									'Search'
								}
								onChange={ ( e ) => setQuery( e.target.value ) }
							/>
						</div>
					</div>
					{ filtered.length === 0 ? (
						<p className="wtt-model-instance-picker__empty">
							{ i18n.noMatchingInstances ||
								'No matching instances.' }
						</p>
					) : (
						<div className="wtt-model-instance-picker__wrap">
							<table className="wtt-model-instance-picker__table">
								<thead>
									<tr>
										<th scope="col">{ colSeq }</th>
										<th scope="col">{ colVersion }</th>
										<th scope="col">{ colModified }</th>
										<th scope="col">{ colId }</th>
									</tr>
								</thead>
								<tbody>
									{ filtered.map( ( inst ) => {
										const cells = instanceCells( inst );
										const iid = cells.id;
										const active =
											iid &&
											iid === String( selectedId || '' );
										return (
											<tr
												key={ iid || cells.seq }
												className={
													'wtt-model-instance-picker__row' +
													( active
														? ' is-active'
														: '' )
												}
												onClick={ () => {
													if (
														typeof onSelect ===
														'function'
													) {
														onSelect( inst );
													}
												} }
												onKeyDown={ ( e ) => {
													if (
														e.key === 'Enter' ||
														e.key === ' '
													) {
														e.preventDefault();
														if (
															typeof onSelect ===
															'function'
														) {
															onSelect( inst );
														}
													}
												} }
												tabIndex={ 0 }
												role="button"
												aria-pressed={ active }
											>
												<td className="wtt-model-instance-picker__cell--seq">
													{ cells.seq
														? `#${ cells.seq }`
														: '—' }
												</td>
												<td className="wtt-model-instance-picker__cell--version">
													{ cells.version
														? `v${ cells.version }`
														: '—' }
												</td>
												<td className="wtt-model-instance-picker__cell--modified">
													{ cells.modified || '—' }
												</td>
												<td className="wtt-model-instance-picker__cell--id">
													{ iid ? (
														<code className="wtt-model-instance-picker__id">
															{ iid }
														</code>
													) : (
														'—'
													) }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) : null }
		</div>
	);
}
