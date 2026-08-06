/**
 * Embedded chooser (shared by Taxo Model table / Object view).
 * Visual language matches admin `.wtt-node-picker` (search + twisty tree).
 *
 * Modes:
 * - `tree` — always tree (taxonomy browse, e.g. Model table bind)
 * - `flat` — flat selectable list
 * - `auto` — type specialization children: max choice depth ≤ 1 → flat, ≥ 2 → tree
 */
import { useMemo, useState, useCallback } from '@wordpress/element';
import {
	buildPathTree,
	expandKeysForSelection,
	resolveChooserMode,
} from './build-path-tree';

function filterTree( nodes, query ) {
	const q = String( query || '' )
		.trim()
		.toLowerCase();
	if ( ! q ) {
		return { nodes, forceExpand: {} };
	}

	const forceExpand = {};

	function filter( list ) {
		const out = [];
		list.forEach( ( n ) => {
			const childResult = filter( n.children || [] );
			const nameHit = String( n.name || '' )
				.toLowerCase()
				.includes( q );
			const pathHit = String( n.path || '' )
				.toLowerCase()
				.includes( q );
			if ( nameHit || pathHit || childResult.length ) {
				if ( childResult.length ) {
					forceExpand[ n.key ] = true;
				}
				out.push( {
					...n,
					children: childResult,
				} );
			}
		} );
		return out;
	}

	return { nodes: filter( nodes ), forceExpand };
}

function flattenSelectable( items, query ) {
	const q = String( query || '' )
		.trim()
		.toLowerCase();
	const list = ( Array.isArray( items ) ? items : [] ).filter( ( item ) => {
		const id = parseInt( item && item.id, 10 ) || 0;
		if ( id <= 0 ) {
			return false;
		}
		if ( ! q ) {
			return true;
		}
		const name = String( ( item && item.name ) || '' ).toLowerCase();
		const path = String( ( item && item.path ) || '' ).toLowerCase();
		return name.includes( q ) || path.includes( q );
	} );
	return list.slice().sort( ( a, b ) => {
		const pa = String( ( a && a.path ) || ( a && a.name ) || '' );
		const pb = String( ( b && b.path ) || ( b && b.name ) || '' );
		return pa.localeCompare( pb );
	} );
}

function TreeNode( {
	node,
	depth,
	expanded,
	selectedId,
	onToggle,
	onSelect,
	i18n,
} ) {
	const hasChildren = node.children && node.children.length > 0;
	const isOpen = !! expanded[ node.key ];
	const isSelected =
		node.selectable && Number( node.id ) === Number( selectedId || 0 );
	const selectable = !! node.selectable;

	return (
		<li className="wtt-node-picker__node">
			<div
				className={
					'wtt-node-picker__row' +
					( isSelected ? ' is-picked is-current' : '' ) +
					( selectable ? '' : ' is-not-selectable' )
				}
				style={ { paddingLeft: `${ depth * 0.75 }rem` } }
			>
				{ hasChildren ? (
					<button
						type="button"
						className="wtt-node-picker__twist"
						aria-expanded={ isOpen }
						aria-label={
							isOpen
								? i18n.nodePickerCollapse || 'Collapse'
								: i18n.nodePickerExpand || 'Expand'
						}
						onClick={ () => onToggle( node.key ) }
					>
						{ isOpen ? '▾' : '▸' }
					</button>
				) : (
					<span className="wtt-node-picker__twist wtt-node-picker__twist--spacer" />
				) }
				<button
					type="button"
					className="wtt-node-picker__name"
					disabled={ ! selectable }
					title={
						selectable
							? node.path
							: i18n.nodePickerAbstractHint ||
							  'Expand and choose a child.'
					}
					onClick={ () => {
						if ( selectable ) {
							onSelect( node );
						} else if ( hasChildren ) {
							onToggle( node.key );
						}
					} }
				>
					{ node.name }
					{ selectable && node.kind ? (
						<span className="wtt-model-tree-chooser__kind">
							{ ' ' }
							[{ node.kind }]
						</span>
					) : null }
				</button>
			</div>
			{ hasChildren && isOpen ? (
				<ul className="wtt-node-picker__list">
					{ node.children.map( ( child ) => (
						<TreeNode
							key={ child.key }
							node={ child }
							depth={ depth + 1 }
							expanded={ expanded }
							selectedId={ selectedId }
							onToggle={ onToggle }
							onSelect={ onSelect }
							i18n={ i18n }
						/>
					) ) }
				</ul>
			) : null }
		</li>
	);
}

/**
 * @param {Object} props
 * @param {Array} props.items Flat pickable nodes with path.
 * @param {number} [props.selectedId]
 * @param {Function} props.onSelect (node) => void — node has id, name, path, taxonomy, kind
 * @param {'tree'|'flat'|'auto'} [props.mode] Taxonomy browse → `tree`; type children → `auto`.
 * @param {Object} [props.i18n]
 * @param {string} [props.className]
 */
export default function ModelTreeChooser( {
	items,
	selectedId = 0,
	onSelect,
	mode = 'tree',
	i18n = {},
	className = '',
} ) {
	const resolvedMode = useMemo(
		() => resolveChooserMode( items, mode ),
		[ items, mode ]
	);
	const roots = useMemo( () => buildPathTree( items ), [ items ] );
	const [ query, setQuery ] = useState( '' );
	const [ expanded, setExpanded ] = useState( () =>
		expandKeysForSelection( roots, selectedId )
	);

	const { nodes: visible, forceExpand } = useMemo(
		() => filterTree( roots, query ),
		[ roots, query ]
	);

	const flatItems = useMemo(
		() => flattenSelectable( items, query ),
		[ items, query ]
	);

	const mergedExpanded = useMemo(
		() => ( { ...expanded, ...forceExpand } ),
		[ expanded, forceExpand ]
	);

	const onToggle = useCallback( ( key ) => {
		setExpanded( ( prev ) => ( {
			...prev,
			[ key ]: ! prev[ key ],
		} ) );
	}, [] );

	const handleSelect = useCallback(
		( node ) => {
			if ( typeof onSelect === 'function' ) {
				onSelect( node );
			}
		},
		[ onSelect ]
	);

	const empty =
		resolvedMode === 'flat' ? flatItems.length === 0 : visible.length === 0;

	return (
		<div
			className={
				'wtt-node-picker wtt-model-tree-chooser' +
				( resolvedMode === 'flat'
					? ' wtt-model-tree-chooser--flat'
					: ' wtt-model-tree-chooser--tree' ) +
				( className ? ` ${ className }` : '' )
			}
			data-chooser-mode={ resolvedMode }
		>
			<div className="wtt-node-picker__tools">
				<div className="wtt-node-picker__search">
					<input
						type="search"
						className="wtt-node-picker__search-input"
						value={ query }
						placeholder={
							i18n.nodePickerSearchPlaceholder || 'Search nodes…'
						}
						aria-label={ i18n.nodePickerSearch || 'Search' }
						onChange={ ( e ) => setQuery( e.target.value ) }
					/>
				</div>
			</div>
			<div className="wtt-node-picker__tree">
				{ empty ? (
					<p className="wtt-model-tree-chooser__empty">
						{ i18n.nodePickerSearchEmpty ||
							i18n.noCollections ||
							'No matching nodes.' }
					</p>
				) : null }
				{ ! empty && resolvedMode === 'flat' ? (
					<ul className="wtt-node-picker__list wtt-model-tree-chooser__flat-list">
						{ flatItems.map( ( item ) => {
							const id = parseInt( item.id, 10 ) || 0;
							const isSelected = id === Number( selectedId || 0 );
							return (
								<li
									key={ `${ item.taxonomy || '' }-${ id }` }
									className="wtt-node-picker__node"
								>
									<div
										className={
											'wtt-node-picker__row' +
											( isSelected
												? ' is-picked is-current'
												: '' )
										}
									>
										<span className="wtt-node-picker__twist wtt-node-picker__twist--spacer" />
										<button
											type="button"
											className="wtt-node-picker__name"
											title={ item.path || item.name }
											onClick={ () =>
												handleSelect( {
													id,
													name: item.name,
													path: item.path,
													taxonomy: item.taxonomy,
													kind: item.kind,
													selectable: true,
												} )
											}
										>
											{ item.name || item.path || `#${ id }` }
											{ item.path &&
											item.path !== item.name ? (
												<span className="wtt-model-tree-chooser__path">
													{ ' ' }
													{ item.path }
												</span>
											) : null }
										</button>
									</div>
								</li>
							);
						} ) }
					</ul>
				) : null }
				{ ! empty && resolvedMode === 'tree' ? (
					<ul className="wtt-node-picker__list">
						{ visible.map( ( node ) => (
							<TreeNode
								key={ node.key }
								node={ node }
								depth={ 0 }
								expanded={ mergedExpanded }
								selectedId={ selectedId }
								onToggle={ onToggle }
								onSelect={ handleSelect }
								i18n={ i18n }
							/>
						) ) }
					</ul>
				) : null }
			</div>
		</div>
	);
}
