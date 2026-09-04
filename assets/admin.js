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

/**
 * The chooser dialog closes on Escape and keeps the focus inside while it is open.
 *
 * ⚠️ **This is the whole of [list row 26](../docs/NewConcept/97-implementation-plan.md#the-working-list)**,
 * and the row's own reservation has expired: it said *«this screen has had **no** script at all, so
 * adding the first line of it is a decision rather than a detail»* — the first line arrived with
 * D-408 above. So this is an addition to a script that exists, not a new dependency.
 *
 * ⚠️ **The dialog stays scriptless in the part that matters.** A nameless checkbox holds the open
 * state, a `<label>` flips it, the shade closes it on a click — all of that keeps working with
 * JavaScript switched off. **What script adds is the two things a real `<dialog>` gives and CSS
 * cannot: Escape, and focus that does not wander out of an open overlay.**
 *
 * ⚠️ *The focus goes back to the **checkbox** and not to the opener, because the opener is a
 * `<label>` and a label is not focusable. The stylesheet already draws the ring on the label when the
 * checkbox has focus (`.taxmod-dialog-switch:focus-visible + .taxmod-dialog-open`), so returning it
 * there looks like returning it to the button.*
 */
( function () {
	'use strict';

	var SWITCH = '.taxmod-dialog-switch';
	var PANEL  = '.taxmod-dialog-panel';

	// ⚠️ *`:not([disabled])` matters: a greyed control is drawn rather than removed (D-370), so an
	// open dialog can hold buttons that must not receive the focus.*
	var REACHABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]),'
		+ ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/** Every switch that is currently on, in document order. */
	function open() {
		return Array.prototype.filter.call(
			document.querySelectorAll( SWITCH ),
			function ( one ) {
				return one.checked;
			}
		);
	}

	/**
	 * The dialog that Escape should close.
	 *
	 * ⚠️ *The one holding the focus first, the last-opened otherwise. **Two dialogs can be open at
	 * once** — the CSS makes each independent — and closing all of them on one Escape would take away
	 * a state the person did not ask to lose.*
	 */
	function topmost() {
		var switches = open();

		if ( switches.length === 0 ) {
			return null;
		}

		for ( var i = 0; i < switches.length; i++ ) {
			var panel = panelOf( switches[ i ] );

			if ( panel && panel.contains( document.activeElement ) ) {
				return switches[ i ];
			}
		}

		return switches[ switches.length - 1 ];
	}

	function panelOf( theSwitch ) {
		var chooser = theSwitch.closest( '.taxmod-chooser' );

		return chooser ? chooser.querySelector( PANEL ) : null;
	}

	function reachableIn( panel ) {
		return Array.prototype.filter.call(
			panel.querySelectorAll( REACHABLE ),
			function ( one ) {
				// ⚠️ *A zero-size element is one CSS is hiding — a collapsed `<details>`, a pane the
				// layout dropped. Handing the focus to it puts the caret somewhere invisible.*
				return one.offsetWidth > 0 || one.offsetHeight > 0;
			}
		);
	}

	function close( theSwitch ) {
		theSwitch.checked = false;

		// ⚠️ *Back to the switch, which is the focusable half of the opener. Without this the focus
		// stays on a control inside a panel that is now `display:none`, and the browser drops it to
		// the top of the document — the person loses their place entirely.*
		theSwitch.focus();
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			var theSwitch = topmost();

			if ( theSwitch !== null ) {
				// ⚠️ *Only when a dialog is actually open: Escape belongs to whatever else wants it —
				// a `<details>`, a native picker — the rest of the time.*
				event.preventDefault();
				close( theSwitch );
			}

			return;
		}

		if ( event.key !== 'Tab' ) {
			return;
		}

		var current = topmost();

		if ( current === null ) {
			return;
		}

		var panel = panelOf( current );

		if ( ! panel ) {
			return;
		}

		var stops = reachableIn( panel );

		if ( stops.length === 0 ) {
			return;
		}

		var first = stops[ 0 ];
		var last  = stops[ stops.length - 1 ];

		// ⚠️ **The wrap is the trap.** *Tabbing off the last control would otherwise walk into the page
		// behind the shade, where every click is intercepted — the focus would be somewhere the mouse
		// cannot reach.*
		if ( ! panel.contains( document.activeElement ) ) {
			event.preventDefault();
			( event.shiftKey ? last : first ).focus();

			return;
		}

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();

			return;
		}

		if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	// ⚠️ **On opening, the focus moves in.** *Otherwise a keyboard user flips the switch and their next
	// Tab lands on whatever follows the checkbox in the document — outside the dialog they just opened.*
	document.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.matches || ! target.matches( SWITCH ) || ! target.checked ) {
			return;
		}

		var panel = panelOf( target );

		if ( ! panel ) {
			return;
		}

		var stops = reachableIn( panel );

		if ( stops.length > 0 ) {
			stops[ 0 ].focus();
		}
	} );
	/**
	 * Das Suchfeld im Auswahldialog -- TASK-031.
	 *
	 * Der Baum ist eine flache Folge von Zeilen mit Einrueckung, keine Verschachtelung im DOM.
	 * Deshalb reicht es, Zeilen ohne Treffer auszublenden. Die Eltern eines Treffers verschwinden
	 * dabei mit, und das ist gewollt -- wer sucht, will die Liste und nicht den Baum.
	 */
	document.addEventListener( 'input', function ( event ) {
		var feld = event.target;

		if ( ! feld || ! feld.matches || ! feld.matches( '.taxmod-tree-filter' ) ) {
			return;
		}

		if ( feld.name ) {
			return;
		}

		var panel = feld.closest( '.taxmod-tree' );

		if ( ! panel ) {
			return;
		}

		var gesucht = feld.value.trim().toLowerCase();
		var zeilen  = panel.querySelectorAll( '.taxmod-tree-row' );

		for ( var i = 0; i < zeilen.length; i++ ) {
			var name = zeilen[ i ].querySelector( '.taxmod-chooser-name' )
				|| zeilen[ i ].querySelector( '.taxmod-tree-label' );
			var text = name ? name.textContent.toLowerCase() : '';

			// Wieder 'flex' und nicht '': die Zeile traegt ihr display im style-Attribut, und ein
			// leerer Wert loescht es. Der Baum fiel danach auseinander -- Namen untereinander, keine
			// Einrueckung mehr. Er hat es beim Loeschen der Suchbuchstaben gesehen.
			zeilen[ i ].style.display = ( gesucht === '' || text.indexOf( gesucht ) !== -1 ) ? 'flex' : 'none';
		}
	} );

	/**
	 * Das Suchfeld im Modellbaum sucht nach einer kurzen Pause, nicht erst auf Enter.
	 *
	 * Sein Befund: "da muss man erst enter druecken damit es filtert". Das Feld hier traegt einen
	 * Namen -- der Server sucht (siehe TreeRenderer und NodesScreen::searchTerm) -- und jede
	 * Eingabe wuerde sonst sofort die Seite neu laden. Deshalb wartet dieses Skript, bis eine
	 * halbe Sekunde lang nichts mehr getippt wurde, und schickt das Formular dann selbst ab.
	 */
	( function () {
		var WARTEZEIT = 500;
		var anstehend = null;

		document.addEventListener( 'input', function ( event ) {
			var feld = event.target;

			if ( ! feld || ! feld.matches || ! feld.matches( '.taxmod-tree-filter[name]' ) ) {
				return;
			}

			var formular = feld.form;

			if ( ! formular ) {
				return;
			}

			if ( anstehend ) {
				window.clearTimeout( anstehend );
			}

			anstehend = window.setTimeout( function () {
				anstehend = null;
				formular.requestSubmit ? formular.requestSubmit() : formular.submit();
			}, WARTEZEIT );
		} );

		// ⚠️ **Der Fokus bleibt im Feld, sonst wuerde jede Pause beim Tippen den naechsten
		// Buchstaben irgendwo anders hinschreiben.** Dieselbe Bauart wie die Bildlaufposition oben:
		// einmal lesen, bevor irgendetwas anderes den Zustand veraendern kann, und die Marke steht
		// in der Session, nicht in der Adresse -- ein zweiter Tab soll nicht den Fokus des ersten
		// erben.
		var MARKE = 'taxmod.suchfokus';

		document.addEventListener( 'submit', function ( event ) {
			var formular = event.target;

			if ( ! formular || ! formular.matches || ! formular.matches( '.taxmod-tree-searchform' ) ) {
				return;
			}

			var feld = formular.querySelector( '.taxmod-tree-filter[name]' );

			if ( ! feld ) {
				return;
			}

			try {
				window.sessionStorage.setItem( MARKE, String( feld.selectionStart ) );
			} catch ( e ) {
				// Nichts zu tun: das Feld bekommt beim Laden dann keinen Fokus.
			}
		} );

		function fokusWiederherstellen() {
			var marke = null;

			try {
				marke = window.sessionStorage.getItem( MARKE );
				window.sessionStorage.removeItem( MARKE );
			} catch ( e ) {
				return;
			}

			if ( marke === null ) {
				return;
			}

			var feld = document.querySelector( '.taxmod-tree-filter[name]' );

			if ( ! feld ) {
				return;
			}

			var position = parseInt( marke, 10 ) || feld.value.length;

			feld.focus();
			feld.setSelectionRange( position, position );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fokusWiederherstellen );
		} else {
			fokusWiederherstellen();
		}
	} )();

	/**
	 * Auf- und Zuklappen im Auswahldialog -- TASK-035.
	 *
	 * Auf der Seite ist der Klapper ein Link und braucht kein Skript. Im Dialog kann er keiner sein:
	 * der Dialog wird von einer angehakten Checkbox offengehalten, und ein Seitenneuaufbau schliesst
	 * ihn. Deshalb traegt er dort href='#' und diese Behandlung.
	 *
	 * Der Baum ist im Dokument flach, jede Zeile kennt nur ihre Tiefe. Ein Ast ist deshalb: alle
	 * folgenden Zeilen, bis wieder eine kommt, die nicht tiefer steht.
	 */
	document.addEventListener( 'click', function ( event ) {
		var klapper = event.target.closest ? event.target.closest( '.taxmod-tree-fold' ) : null;

		if ( ! klapper ) {
			return;
		}

		event.preventDefault();

		var zeile = klapper.closest( '.taxmod-tree-row' );

		if ( ! zeile ) {
			return;
		}

		var zu    = klapper.getAttribute( 'data-fold' ) === 'auf';
		var tiefe = parseInt( zeile.getAttribute( 'data-depth' ), 10 );
		var naechste = zeile.nextElementSibling;

		while ( naechste && parseInt( naechste.getAttribute( 'data-depth' ), 10 ) > tiefe ) {
			naechste.style.display = zu ? 'none' : 'flex';
			naechste = naechste.nextElementSibling;
		}

		klapper.setAttribute( 'data-fold', zu ? 'zu' : 'auf' );
		klapper.innerHTML = zu ? '\u25B8' : '\u25BE';
	} );

	/**
	 * Waehlen schliesst den Dialog und schreibt den Knoten in die Zeile -- TASK-028.
	 *
	 * Auf sein Wort: "Benutzer drueckt auf Button, selektiert Knoten wie im Baum, Dialog schliesst
	 * sich, Knoten steht im Feld". Danach erst der Anlegen-Knopf.
	 */
	document.addEventListener( 'change', function ( event ) {
		var radio = event.target;

		if ( ! radio || ! radio.matches || ! radio.matches( '.taxmod-dialog-panel input[type="radio"]' ) ) {
			return;
		}

		var chooser = radio.closest( '.taxmod-chooser' );

		if ( ! chooser ) {
			return;
		}

		var name = radio.closest( '.taxmod-chooser-row' );
		var text = name ? name.querySelector( '.taxmod-tree-label' ) : null;
		var feld = chooser.parentNode ? chooser.parentNode.querySelector( '.taxmod-chosen' ) : null;

		if ( feld && text ) {
			feld.value = text.textContent;
		}

		var schalter = chooser.querySelector( '.taxmod-dialog-switch' );

		if ( schalter ) {
			schalter.checked = false;
		}
	} );

	/**
	 * Der Schalter «Knotennamen benutzen» -- TASK-026.
	 *
	 * Auf sein Wort: "Schalter umschalten, Feld wird geoeffnet, Name steht noch da". Das Feld wird
	 * also nur gesperrt und nicht geleert -- wer zurueckschaltet, findet seinen Namen wieder.
	 */
	document.addEventListener( 'change', function ( event ) {
		var schalter = event.target;

		if ( ! schalter || ! schalter.matches || ! schalter.matches( 'input[name="use_node_name"]' ) ) {
			return;
		}

		var form = schalter.closest( 'form' );
		var feld = form ? form.querySelector( 'input[name="name"]' ) : null;

		if ( feld ) {
			feld.disabled = schalter.checked;
		}
	} );

} )();
