/**
 * The tree keeps its scroll position across a page change.
 *
 * ⚠️ **This is the first script on this screen and that was a decision, not a convenience**
 * (D-408). Everything else here is deliberately scriptless: `form="…"` submits a panel from
 * outside it, a nameless checkbox opens the chooser dialog, `<details>` holds the fold state.
 * The owner asked three times for the tree to stay where it was, and a `#fragment` — the
 * scriptless answer — can only *place* the row, never *preserve* the offset. He picked the
 * script knowingly.
 *
 * ⚠️ **What it must not become.** It restores one number. It does not render, submit, validate
 * or fetch, and nothing on the screen may start depending on it: with the script off, the
 * fragment still puts the selected row on screen, which is the behaviour this replaces rather
 * than the behaviour it enables.
 */
( function () {
	'use strict';

	// Per tab, not in the URL: a bookmarked address should not carry somebody's scroll offset,
	// and two tabs on the same model are two places to be looking.
	var KEY = 'taxmod.tree.scroll';

	function pane() {
		return document.querySelector( '.taxmod-tree' );
	}

	function restore() {
		var tree = pane();

		if ( ! tree ) {
			return;
		}

		var saved = null;

		// ⚠️ Storage can throw, not merely come back empty — a private window, cleared site data,
		// a browser set to refuse it. A tree that fails to load because a preference could not be
		// read would be worse than a tree that starts at the top.
		try {
			saved = window.sessionStorage.getItem( KEY );
		} catch ( e ) {
			return;
		}

		if ( saved === null ) {
			return;
		}

		// ⚠️ **After the fragment, deliberately.** The browser has already jumped to
		// `#taxmod-node-<id>` by now, so writing `scrollTop` here wins — and if the offset is
		// stale (the tree got shorter), the browser clamps it and the fragment's placement is
		// what remains visible.
		tree.scrollTop = parseInt( saved, 10 ) || 0;
	}

	function watch() {
		var tree = pane();

		if ( ! tree ) {
			return;
		}

		var pending = false;

		tree.addEventListener( 'scroll', function () {
			// One write per frame at most. A scroll event fires far more often than a person
			// changes their mind about where they are.
			if ( pending ) {
				return;
			}

			pending = true;

			window.requestAnimationFrame( function () {
				pending = false;

				try {
					window.sessionStorage.setItem( KEY, String( tree.scrollTop ) );
				} catch ( e ) {
					// Nothing to do and nothing to report: the next load simply starts at the top.
				}
			} );
		}, { passive: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			restore();
			watch();
		} );
	} else {
		restore();
		watch();
	}
} )();
