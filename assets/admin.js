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

	// ⚠️ **Read once, at the very top, and this is the bug that made the whole script useless.** The
	// owner: *with collapsed nodes the tree still jumps when I select a node.* It jumped with every
	// node — because **the browser's own jump to `#taxmod-node-<id>` is a scroll event**, so the
	// watcher below wrote *that* position into storage, and every later `restore()` then read it back
	// and «restored» the place the fragment had jumped to.
	//
	// ⚠️ *So the offset is captured **before** anything can scroll, and `restore()` uses the captured
	// value rather than re-reading. Three restore points against a browser were fine; three restore
	// points reading a value the browser had already overwritten were three ways to do nothing.*
	//
	// ⚠️ Storage can throw, not merely come back empty — a private window, cleared site data, a browser
	// set to refuse it. A tree that fails to load because a preference could not be read would be worse
	// than a tree that starts at the top.
	var saved = null;

	try {
		saved = window.sessionStorage.getItem( KEY );
	} catch ( e ) {
		saved = null;
	}

	function restore() {
		var tree = pane();

		if ( ! tree || saved === null ) {
			return;
		}

		// ⚠️ *If the offset is stale — the tree got shorter because a branch is folded — the browser
		// clamps it, and what remains visible is the bottom of the tree rather than a wrong place.*
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

	// ⚠️ **Restored three times, and each one is for a different competitor.** The owner reported the
	// remainder: *and the tree still jumps slightly.* It did, because the browser scrolls to
	// `#taxmod-node-<id>` on its own — the scriptless fallback — and a single restore on
	// `DOMContentLoaded` can land either side of that.
	//
	// ⚠️ *Right now: the script sits in the footer, so the tree is already parsed and setting the offset
	// here happens before the first paint — no flash. On `DOMContentLoaded`: for the case where it is
	// not. On `load`: **after** the browser has finished its own fragment scroll, which is the one that
	// was winning. Three cheap writes of one number beats guessing which order a browser picks.*
	restore();

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', restore );
	}

	// ⚠️ **The watcher starts only after the last restore, and the order is the whole fix.** While it
	// was attached earlier, the browser's own fragment jump fired a scroll event, the watcher saved that
	// position, and the next restore faithfully put the tree back where the fragment had gone. *A
	// listener that records what it is competing with cannot be told apart from no listener at all.*
	window.addEventListener( 'load', function () {
		restore();
		watch();
	} );
} )();
