/**
 * The modelling screen keeps its scroll position across a page change.
 *
 * ⚠️ **This is the first script on this screen and that was a decision, not a convenience**
 * (D-408). Everything else here is deliberately scriptless: `form="…"` submits a panel from
 * outside it, a nameless checkbox opens the chooser dialog, `<details>` holds the fold state.
 * A `#fragment` — the scriptless answer — can only *place* a row, never *preserve* an offset,
 * and the owner picked the script knowingly.
 *
 * ⚠️ **It restores what actually scrolls, which took three wrong answers to establish** (D-420).
 * The first version saved `.taxmod-tree`'s `scrollTop` — and that element is a **table with no
 * `overflow`**, so it never scrolls and the value was always zero. *The script had been
 * faithfully saving and restoring nothing while three other things were blamed: a fragment, a
 * margin, and a listener that recorded its own competition.*
 *
 * ⚠️ **What it must not become.** It restores two numbers. It does not render, submit, validate
 * or fetch, and nothing on the screen may come to depend on it.
 */
( function () {
	'use strict';

	// Per tab, not in the URL: a bookmarked address should not carry somebody's scroll offset,
	// and two tabs on the same model are two places to be looking.
	var KEY = 'taxmod.scroll';

	/**
	 * ⚠️ **The page is the scroller, and any pane that grows one later is the other.** Both are
	 * saved, because which of them holds the offset is a layout decision that has already changed
	 * once — the owner asked for the tree to get its own scrollbar, and today it has none.
	 */
	function pane() {
		return document.querySelector( '.taxmod-tree-pane' );
	}

	// ⚠️ **Read once, at the very top, before anything can scroll.** An earlier version re-read it
	// inside every restore — and the browser's own jump to a `#fragment` fires a scroll event, so
	// the watcher below saved *that* position and the next restore put the page back where the
	// browser had gone. *Three restore points reading an overwritten value are three ways to do
	// nothing.*
	//
	// ⚠️ Storage can throw rather than merely come back empty — a private window, cleared site data,
	// a browser set to refuse it. A screen that fails to load because a preference could not be read
	// would be worse than one that starts at the top.
	var saved = null;

	try {
		saved = JSON.parse( window.sessionStorage.getItem( KEY ) || 'null' );
	} catch ( e ) {
		saved = null;
	}

	function restore() {
		if ( ! saved ) {
			return;
		}

		if ( typeof saved.page === 'number' ) {
			// ⚠️ *A stale offset — the tree got shorter because a branch is folded — is clamped by the
			// browser, so what remains visible is the bottom of the page rather than a wrong place.*
			window.scrollTo( 0, saved.page );
		}

		var tree = pane();

		if ( tree && typeof saved.tree === 'number' ) {
			tree.scrollTop = saved.tree;
		}
	}

	function save() {
		var tree = pane();

		try {
			window.sessionStorage.setItem( KEY, JSON.stringify( {
				page: window.scrollY || window.pageYOffset || 0,
				tree: tree ? tree.scrollTop : 0
			} ) );
		} catch ( e ) {
			// Nothing to do and nothing to report: the next load simply starts at the top.
		}
	}

	function watch() {
		var pending = false;

		function note() {
			// One write per frame at most. A scroll event fires far more often than a person changes
			// their mind about where they are.
			if ( pending ) {
				return;
			}

			pending = true;

			window.requestAnimationFrame( function () {
				pending = false;
				save();
			} );
		}

		window.addEventListener( 'scroll', note, { passive: true } );

		var tree = pane();

		if ( tree ) {
			tree.addEventListener( 'scroll', note, { passive: true } );
		}
	}

	restore();

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', restore );
	}

	// ⚠️ **The watcher starts only after the last restore, and the order is part of the fix.** While
	// it was attached earlier it recorded whatever the browser did on its own, which is
	// indistinguishable from not watching at all.
	window.addEventListener( 'load', function () {
		restore();
		watch();
	} );
} )();
