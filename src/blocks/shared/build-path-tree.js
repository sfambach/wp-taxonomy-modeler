/**
 * Build a nested tree from flat nodes that carry a path string (e.g. "A/B/C").
 * Pickable leaves keep their id; intermediate path segments are folder nodes (id 0).
 *
 * @param {Array<{id:number,name:string,path:string,taxonomy?:string,kind?:string}>} items
 * @return {Array<object>} Root nodes with { key, id, name, path, taxonomy, kind, selectable, children }.
 */
export function buildPathTree( items ) {
	const list = Array.isArray( items ) ? items : [];
	const roots = [];
	const byKey = new Map();

	function ensureFolder( segments, taxonomy ) {
		let parentList = roots;
		let pathSoFar = '';
		for ( let i = 0; i < segments.length; i++ ) {
			const seg = segments[ i ];
			pathSoFar = pathSoFar ? `${ pathSoFar }/${ seg }` : seg;
			const key = `${ taxonomy || '' }::folder::${ pathSoFar }`;
			let node = byKey.get( key );
			if ( ! node ) {
				node = {
					key,
					id: 0,
					name: seg,
					path: pathSoFar,
					taxonomy: taxonomy || '',
					kind: '',
					selectable: false,
					children: [],
				};
				byKey.set( key, node );
				parentList.push( node );
			}
			parentList = node.children;
		}
		return parentList;
	}

	list.forEach( ( item ) => {
		const id = parseInt( item && item.id, 10 ) || 0;
		if ( id <= 0 ) {
			return;
		}
		const path = String( ( item && item.path ) || ( item && item.name ) || '' )
			.trim()
			.replace( /^\/+|\/+$/g, '' );
		const taxonomy = String( ( item && item.taxonomy ) || '' );
		const parts = path ? path.split( '/' ).filter( Boolean ) : [ String( item.name || id ) ];
		const leafName = parts[ parts.length - 1 ] || String( item.name || id );
		const parentSegs = parts.slice( 0, -1 );
		const parentList =
			parentSegs.length > 0 ? ensureFolder( parentSegs, taxonomy ) : roots;

		const leafKey = `${ taxonomy }::leaf::${ id }`;
		let leaf = byKey.get( leafKey );
		if ( ! leaf ) {
			leaf = {
				key: leafKey,
				id,
				name: leafName,
				path: path || leafName,
				taxonomy,
				kind: String( ( item && item.kind ) || '' ),
				selectable: true,
				columnCount: item && item.columnCount != null ? item.columnCount : undefined,
				attributeCount:
					item && item.attributeCount != null
						? item.attributeCount
						: undefined,
				children: [],
			};
			byKey.set( leafKey, leaf );
			parentList.push( leaf );
		} else {
			leaf.name = leafName;
			leaf.path = path || leafName;
			leaf.kind = String( ( item && item.kind ) || leaf.kind || '' );
		}
	} );

	return roots;
}

/**
 * Max nesting depth among pickable items under a type / option set.
 * Depth 0 = empty; 1 = flat siblings only; ≥2 = nested specializations.
 *
 * Uses path segments relative to the shallowest common path when paths exist;
 * otherwise uses the built tree height of selectable leaves.
 *
 * Product rule: max choice depth ≤ 1 → flat select/list; ≥ 2 → TreeChooser.
 *
 * @param {Array<{id:number,path?:string,name?:string}>} items
 * @return {number}
 */
export function maxChoiceDepth( items ) {
	const list = ( Array.isArray( items ) ? items : [] ).filter(
		( item ) => ( parseInt( item && item.id, 10 ) || 0 ) > 0
	);
	if ( ! list.length ) {
		return 0;
	}

	const paths = list.map( ( item ) => {
		const path = String( ( item && item.path ) || ( item && item.name ) || '' )
			.trim()
			.replace( /^\/+|\/+$/g, '' );
		return path ? path.split( '/' ).filter( Boolean ) : [ String( item.name || item.id ) ];
	} );

	let common = paths[ 0 ].slice();
	for ( let i = 1; i < paths.length; i++ ) {
		const parts = paths[ i ];
		let n = 0;
		while (
			n < common.length &&
			n < parts.length &&
			common[ n ] === parts[ n ]
		) {
			n++;
		}
		common = common.slice( 0, n );
	}

	let maxRel = 0;
	paths.forEach( ( parts ) => {
		const rel = Math.max( 0, parts.length - common.length );
		/* At least depth 1 for a selectable leaf. */
		const depth = Math.max( 1, rel );
		if ( depth > maxRel ) {
			maxRel = depth;
		}
	} );

	return maxRel;
}

/**
 * Resolve UI mode for picking among type specialization children.
 *
 * @param {Array} items
 * @param {'tree'|'flat'|'auto'} [mode]
 * @return {'tree'|'flat'}
 */
export function resolveChooserMode( items, mode = 'auto' ) {
	const m = String( mode || 'auto' ).toLowerCase();
	if ( m === 'tree' || m === 'flat' ) {
		return m;
	}
	return maxChoiceDepth( items ) >= 2 ? 'tree' : 'flat';
}

/**
 * Collect expand keys for ancestors of a selected id (so the tree opens to it).
 *
 * @param {Array<object>} roots
 * @param {number} selectedId
 * @return {Object<string, boolean>}
 */
export function expandKeysForSelection( roots, selectedId ) {
	const id = parseInt( selectedId, 10 ) || 0;
	const expanded = {};
	if ( ! id ) {
		return expanded;
	}

	function walk( nodes, trail ) {
		for ( let i = 0; i < nodes.length; i++ ) {
			const n = nodes[ i ];
			const nextTrail = trail.concat( [ n.key ] );
			if ( n.selectable && Number( n.id ) === id ) {
				nextTrail.slice( 0, -1 ).forEach( ( k ) => {
					expanded[ k ] = true;
				} );
				return true;
			}
			if ( n.children && n.children.length && walk( n.children, nextTrail ) ) {
				return true;
			}
		}
		return false;
	}

	walk( roots, [] );
	return expanded;
}
