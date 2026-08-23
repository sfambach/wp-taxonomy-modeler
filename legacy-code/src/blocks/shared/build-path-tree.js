/**
 * Build a nested tree from flat nodes that carry a path string (e.g. "A/B/C").
 *
 * Important: each path segment is ONE node. If both a parent term (e.g. Model)
 * and children (Model/Platine) exist, they share the same node — never a
 * selectable leaf twin beside a synthetic folder.
 *
 * @param {Array<{id:number,name:string,path:string,taxonomy?:string,kind?:string}>} items
 * @return {Array<object>} Root nodes with { key, id, name, path, taxonomy, kind, selectable, children }.
 */
export function buildPathTree( items ) {
	const list = Array.isArray( items ) ? items : [];
	const roots = [];
	const byKey = new Map();

	/**
	 * Ensure a node exists for the full path; return it.
	 * Folders start non-selectable (id 0); later leaf data can promote them.
	 *
	 * @param {string[]} segments
	 * @param {string} taxonomy
	 */
	function ensureNode( segments, taxonomy ) {
		let parentList = roots;
		let pathSoFar = '';
		let node = null;
		for ( let i = 0; i < segments.length; i++ ) {
			const seg = segments[ i ];
			pathSoFar = pathSoFar ? `${ pathSoFar }/${ seg }` : seg;
			const key = `${ taxonomy || '' }::path::${ pathSoFar }`;
			node = byKey.get( key );
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
		return node;
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
		const parts = path
			? path.split( '/' ).map( ( s ) => s.trim() ).filter( Boolean )
			: [ String( item.name || id ) ];
		const node = ensureNode( parts, taxonomy );
		if ( ! node ) {
			return;
		}
		/* Promote path node to the real term — children stay nested here. */
		node.id = id;
		node.name = parts[ parts.length - 1 ] || String( item.name || id );
		node.path = path || node.path;
		node.taxonomy = taxonomy || node.taxonomy;
		node.kind = String( ( item && item.kind ) || node.kind || '' );
		node.selectable = true;
		if ( item && item.columnCount != null ) {
			node.columnCount = item.columnCount;
		}
		if ( item && item.attributeCount != null ) {
			node.attributeCount = item.attributeCount;
		}
	} );

	return roots;
}

/**
 * Keep only the subtree rooted at rootId (inclusive). Empty rootId → unchanged.
 *
 * @param {Array<object>} roots
 * @param {number} rootId
 * @return {Array<object>}
 */
export function subtreeAtRoot( roots, rootId ) {
	const id = parseInt( rootId, 10 ) || 0;
	if ( ! id ) {
		return Array.isArray( roots ) ? roots : [];
	}

	function find( nodes ) {
		for ( let i = 0; i < nodes.length; i++ ) {
			const n = nodes[ i ];
			if ( Number( n.id ) === id ) {
				return [ n ];
			}
			if ( n.children && n.children.length ) {
				const hit = find( n.children );
				if ( hit ) {
					return hit;
				}
			}
		}
		return null;
	}

	return find( Array.isArray( roots ) ? roots : [] ) || [];
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
		return path
			? path.split( '/' ).map( ( s ) => s.trim() ).filter( Boolean )
			: [ String( item.name || item.id ) ];
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
 * Collect expand keys for ancestors of a selected/focus id.
 * When expandFocusBranch is true (admin parity), also expand the focus node
 * itself so its children are visible in the tree.
 *
 * @param {Array<object>} roots
 * @param {number} targetId
 * @param {boolean} [expandFocusBranch=true]
 * @return {Object<string, boolean>}
 */
export function expandKeysForSelection(
	roots,
	targetId,
	expandFocusBranch = true
) {
	const id = parseInt( targetId, 10 ) || 0;
	const expanded = {};
	if ( ! id ) {
		return expanded;
	}

	function walk( nodes, trail ) {
		for ( let i = 0; i < nodes.length; i++ ) {
			const n = nodes[ i ];
			const nextTrail = trail.concat( [ n.key ] );
			if ( Number( n.id ) === id ) {
				/* Ancestors always open so the node is in view. */
				nextTrail.slice( 0, -1 ).forEach( ( k ) => {
					expanded[ k ] = true;
				} );
				/* Focus branch: expand the node itself (show children). */
				if ( expandFocusBranch ) {
					expanded[ n.key ] = true;
				}
				return true;
			}
			if ( n.children && n.children.length && walk( n.children, nextTrail ) ) {
				return true;
			}
		}
		return false;
	}

	walk( Array.isArray( roots ) ? roots : [], [] );
	return expanded;
}
