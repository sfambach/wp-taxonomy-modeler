/**
 * The tree in the left column (`ListPage::sideTree()`): «Expand all», «Collapse all», and the open branches kept per user,
 * so the tree looks as he left it. His word 2026-11-09: «hier fange ich immer neu an … wie der baum aussah als ich ihn
 * verlassen haben, button wie collapse all usw fehlen».
 *
 * The tree is flat in the document; a row knows its depth, and a branch is every following row that stands deeper.
 */
( function () {
	'use strict';

	function rows( box ) {
		return Array.prototype.slice.call( box.querySelectorAll( '.taxmod-tree-row' ) );
	}

	function idOf( row ) {
		var pick = row.querySelector( 'input[type="radio"], input[type="checkbox"]' );

		return pick ? pick.value : '';
	}

	/**
	 * Draws the tree with `wanted` open: true opens every branch, false closes every one, an object of ids opens those and
	 * keeps what the page already opened (the way to the chosen branch). Which rows show is the modeller's one walk
	 * (`window.taxmodTreeShow`, assets/tree-show.js, a dependency of this script, Ladezeit S5 D-1418).
	 */
	function draw( box, wanted ) {
		if ( typeof window.taxmodTreeShow !== 'function' ) {
			// *A dependency, so this means the page is broken — say so instead of leaving the tree silently unfolded.*
			window.console.error( 'taxmod side tree: assets/tree-show.js is not loaded' );

			return;
		}

		window.taxmodTreeShow( box, typeof wanted === 'boolean' ? wanted : function ( row ) {
			return wanted[ idOf( row ) ] === true ? true : undefined;
		} );
	}

	function openIds( box ) {
		return rows( box ).filter( function ( row ) {
			var fold = row.querySelector( '.taxmod-tree-fold' );

			return fold && fold.getAttribute( 'data-fold' ) === 'auf' && idOf( row ) !== '';
		} ).map( idOf );
	}

	var waiting = null;

	function keep( box ) {
		clearTimeout( waiting );
		waiting = setTimeout( function () {
			var data = new FormData();

			data.append( 'tree', box.getAttribute( 'data-taxmod-tree' ) || '' );
			openIds( box ).forEach( function ( id ) {
				data.append( 'open[]', id );
			} );
			fetch( box.getAttribute( 'data-taxmod-tree-save' ) || '', { method: 'POST', body: data, credentials: 'same-origin' } ).catch( function () {} );
		}, 400 );
	}

	/** A long name ends in «…» in the narrow column and shows whole on hover; the chosen row is scrolled into view in the box. */
	function settle( box ) {
		box.querySelectorAll( '.taxmod-tree-row' ).forEach( function ( row ) {
			row.title = row.textContent.trim();
		} );

		var on   = box.querySelector( '.taxmod-tree-row input[type="radio"]:checked' );
		var list = box.querySelector( '.taxmod-chooser-tree' );

		if ( ! on || ! list ) {
			return;
		}

		var row  = on.closest( '.taxmod-tree-row' );
		var r    = row.getBoundingClientRect();
		var l    = list.getBoundingClientRect();
		var head = box.querySelector( '.taxmod-tree-search' );
		var top  = l.top + ( head ? head.offsetHeight : 0 );

		if ( r.top < top || r.bottom > l.bottom ) {
			list.scrollTop += r.top - top - ( l.bottom - top ) / 3;
		}
	}

	function start() {
		document.querySelectorAll( '.taxmod-side-tree' ).forEach( function ( box ) {
			var stored = {};

			try {
				JSON.parse( box.getAttribute( 'data-taxmod-tree-open' ) || '[]' ).forEach( function ( id ) {
					stored[ String( id ) ] = true;
				} );
			} catch ( e ) {}

			draw( box, stored );
			settle( box );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target instanceof Element ? event.target : null;
		var box    = target ? target.closest( '.taxmod-side-tree' ) : null;

		if ( ! box ) {
			return;
		}

		if ( target.closest( '.taxmod-side-tree-expand, .taxmod-side-tree-collapse' ) ) {
			event.preventDefault();
			draw( box, !! target.closest( '.taxmod-side-tree-expand' ) );
			keep( box );

			return;
		}

		// The fold itself is the modeller's (admin.js); this only keeps what it left, after it ran.
		if ( target.closest( '.taxmod-tree-fold' ) ) {
			setTimeout( function () {
				keep( box );
			}, 0 );
		}
	} );

	// D-1408: an act puts the page in place without loading it; the tree is drawn again from what the server now says.
	document.addEventListener( 'taxmod:replaced', start );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
