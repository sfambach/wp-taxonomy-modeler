/**
 * Der gemeinsame Auswahlbaum der Knotenseite (D-815, D-821).
 *
 * ⚠️ **Ein Baum je Seite statt einer je Stelle.** Sein Wort: «one shared window for the whole page». Der Öffner trägt, wohin die
 * Wahl geht (das versteckte Feld hinter ihm), was gewählt ist, was hier nicht gewählt werden kann und welcher Ast offen steht; das
 * Skript richtet den einen Baum dafür her und öffnet ihn. «OK» trägt die Wahl ein und schickt den Akt ab, wo einer dahintersteht;
 * «Abbrechen» legt zurück wie jeder Dialog (D-804). *Ohne Skript gibt es diesen Dialog nicht — «3 ok».*
 */
( function () {
	'use strict';

	var aktuell = null;

	function liste( wert ) {
		return ( wert || '' ).split( ',' ).filter( function ( eines ) {
			return eines !== '';
		} );
	}

	function herrichten( huelle, oeffner ) {
		var gesperrt = liste( oeffner.getAttribute( 'data-barred' ) );
		var offen    = liste( oeffner.getAttribute( 'data-open' ) );
		var gewaehlt = oeffner.getAttribute( 'data-chosen' ) || '';
		var zeilen   = huelle.querySelectorAll( '.taxmod-tree-row' );
		var vater    = {};
		var stapel   = [];
		var ids      = [];
		var i;

		for ( i = 0; i < zeilen.length; i++ ) {
			var tiefe = parseInt( zeilen[ i ].getAttribute( 'data-depth' ), 10 );
			var radio = zeilen[ i ].querySelector( 'input[type="radio"]' );
			var zelle = zeilen[ i ].querySelector( '.taxmod-tree-node' );
			var id    = radio ? radio.value : ( zelle && zelle.id ? zelle.id.replace( /^.*-/, '' ) : '' );

			stapel[ tiefe ] = id;
			stapel.length   = tiefe + 1;
			vater[ id ]     = tiefe > 0 ? stapel[ tiefe - 1 ] : null;
			ids[ i ]        = id;

			if ( radio ) {
				var aus = gesperrt.indexOf( id ) !== -1;

				radio.disabled = aus;
				radio.checked  = ! aus && id === gewaehlt;
				zeilen[ i ].classList.toggle( 'taxmod-pick-barred', aus );
			}
		}

		// ⚠️ *Offen stehen der Einstiegsast mit seinem Weg und der Weg zum Gewählten — der Gewählte selbst bleibt zu (D-615).*
		var auf = {};

		function mitWeg( id ) {
			while ( id !== null && id !== undefined && id !== '' ) {
				auf[ id ] = true;
				id = vater[ id ];
			}
		}

		offen.forEach( mitWeg );

		if ( gewaehlt !== '' ) {
			mitWeg( vater[ gewaehlt ] );
		}

		var sichtbar = {};

		for ( i = 0; i < zeilen.length; i++ ) {
			var eigen = ids[ i ];
			var oben  = vater[ eigen ];

			sichtbar[ eigen ] = oben === null || ( sichtbar[ oben ] === true && auf[ oben ] === true );
			zeilen[ i ].style.display = sichtbar[ eigen ] ? 'flex' : 'none';

			var klapper = zeilen[ i ].querySelector( '.taxmod-tree-fold' );

			if ( klapper ) {
				klapper.setAttribute( 'data-fold', auf[ eigen ] === true ? 'auf' : 'zu' );
				klapper.innerHTML = auf[ eigen ] === true ? '▾' : '▸';
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var oeffner = event.target instanceof Element ? event.target.closest( '.taxmod-pick-open' ) : null;

		if ( ! oeffner ) {
			return;
		}

		event.preventDefault();

		var huelle   = document.querySelector( '.taxmod-shared-pick' );
		var schalter = huelle ? huelle.querySelector( '.taxmod-dialog-switch' ) : null;

		if ( ! schalter ) {
			return;
		}

		aktuell = oeffner;
		herrichten( huelle, oeffner );

		var kopf = huelle.querySelector( '.taxmod-chooser-current' );

		if ( kopf ) {
			kopf.textContent = oeffner.getAttribute( 'title' ) || '';
		}

		schalter.checked = true;
		schalter.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	document.addEventListener( 'click', function ( event ) {
		var ok = event.target instanceof Element ? event.target.closest( '.taxmod-shared-pick .taxmod-dialog-ok' ) : null;

		if ( ! ok || ! aktuell ) {
			return;
		}

		var oeffner = aktuell;
		var radio   = ok.closest( '.taxmod-shared-pick' ).querySelector( 'input[type="radio"]:checked' );

		aktuell = null;

		if ( ! radio ) {
			return;
		}

		var feld = oeffner.nextElementSibling;

		if ( feld && feld.matches( 'input[type="hidden"]' ) ) {
			feld.value = radio.value;
		}

		oeffner.setAttribute( 'data-chosen', radio.value );

		var zeile   = radio.closest( '.taxmod-chooser-row' );
		var name    = zeile ? zeile.querySelector( '.taxmod-tree-label' ) : null;
		var anzeige = oeffner.parentNode ? oeffner.parentNode.querySelector( '.taxmod-chosen' ) : null;

		if ( anzeige && name ) {
			if ( anzeige.tagName === 'INPUT' ) {
				anzeige.value = name.textContent;
			} else {
				anzeige.textContent = name.textContent;
			}
		}

		// ⚠️ *Ein Öffner, der den gewählten Namen selbst zeigt (ein Verweis auf den ganzen Baum), trägt ihn danach.*
		if ( oeffner.getAttribute( 'data-names' ) === '1' && name ) {
			oeffner.textContent = name.textContent;
		}

		var senden = feld ? feld.nextElementSibling : null;

		if ( senden && senden.matches( '.taxmod-pick-submit' ) && senden.form ) {
			if ( senden.form.requestSubmit ) {
				senden.form.requestSubmit( senden );
			} else {
				senden.click();
			}
		}
	} );
} )();

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
	var SPRUNG = 'taxmod.sprung';
	var springen = false;

	try {
		saved = JSON.parse( window.sessionStorage.getItem( KEY ) || 'null' );
		springen = window.sessionStorage.getItem( SPRUNG ) === '1';
		window.sessionStorage.removeItem( SPRUNG );
	} catch ( e ) {
		saved = null;
	}

	// *Die Wahl steht an der Seite (`data-taxmod-tree-click`); gemerkt wird nur, dass der nächste Aufbau aus einem Baumklick kommt.*
	document.addEventListener( 'click', function ( ereignis ) {
		var link = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-tree-pane a[href]' ) : null;
		var seite = link ? link.closest( '[data-taxmod-tree-click]' ) : null;

		if ( ! seite || seite.getAttribute( 'data-taxmod-tree-click' ) !== 'jump' ) {
			return;
		}

		try {
			window.sessionStorage.setItem( SPRUNG, '1' );
		} catch ( e ) {
			// Ohne Speicher bleibt die Seite stehen — die Vorgabe.
		}
	} );

	function restore() {
		// ⚠️ **Ein Anker in der Adresse geht vor** (D-788) — sein Befund: *«Wenn ich auf eine Zeile klicke, dann springt der
		// Bildschirm ganz nach oben … er sollte … in der Eingabe anhalten.»* *Der geöffnete Satz bringt `#taxmod-preview-edit`
		// mit; die gemerkte Stelle gehört zur Seite davor und würde ihn wegschieben.*
		var anker = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;

		if ( anker ) {
			anker.scrollIntoView( { block: 'start' } );

			return;
		}

		// ⚠️ **Ein Klick im Baum springt nach oben, wenn die Konfiguration es sagt** (D-807) — sein Wort: «if selecting something in the
		// tree it should jump to top, this should be switchable, stay or jump … default would be stay».
		if ( springen ) {
			window.scrollTo( 0, 0 );

			return;
		}

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

	// ⚠️ **Was beim Öffnen gewählt war, legt «Abbrechen» zurück** (D-804) — sein Wort: «they should have buttons ok/confirm, cancel».
	// *Beim Öffnen wird der Zustand jedes Bedienelements im Dialog und die Beschriftung des Öffners gemerkt; «Abbrechen», das ✕ und
	// Escape legen ihn zurück, «OK» behält, was jetzt gilt.*
	var gemerkt = new WeakMap();

	function merken( theSwitch ) {
		var chooser = theSwitch.closest( '.taxmod-chooser' );

		if ( ! chooser ) {
			return;
		}

		var oeffner = chooser.querySelector( ':scope > .taxmod-dialog-open, :scope > .taxmod-record-dialog-open' );
		var kopf = chooser.querySelector( '.taxmod-chooser-current' );

		gemerkt.set( theSwitch, {
			felder: Array.prototype.map.call( chooser.querySelectorAll( '.taxmod-dialog-panel input, .taxmod-dialog-panel select' ), function ( feld ) {
				return { feld: feld, checked: feld.checked, value: feld.value };
			} ),
			oeffner: oeffner ? { html: oeffner.innerHTML, klasse: oeffner.className } : null,
			kopf: kopf ? kopf.innerHTML : null
		} );
	}

	function zuruecklegen( theSwitch ) {
		var stand = gemerkt.get( theSwitch );
		var chooser = theSwitch.closest( '.taxmod-chooser' );

		if ( ! stand || ! chooser ) {
			return;
		}

		stand.felder.forEach( function ( eintrag ) {
			eintrag.feld.checked = eintrag.checked;
			eintrag.feld.value = eintrag.value;
		} );

		var oeffner = chooser.querySelector( ':scope > .taxmod-dialog-open, :scope > .taxmod-record-dialog-open' );
		var kopf = chooser.querySelector( '.taxmod-chooser-current' );

		if ( oeffner && stand.oeffner ) {
			oeffner.innerHTML = stand.oeffner.html;
			oeffner.className = stand.oeffner.klasse;
		}

		if ( kopf && stand.kopf !== null ) {
			kopf.innerHTML = stand.kopf;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var abbruch = event.target instanceof Element ? event.target.closest( '.taxmod-dialog-cancel' ) : null;
		var chooser = abbruch ? abbruch.closest( '.taxmod-chooser' ) : null;
		var theSwitch = chooser ? chooser.querySelector( SWITCH ) : null;

		if ( theSwitch ) {
			zuruecklegen( theSwitch );
		}
	}, true );

	function close( theSwitch ) {
		zuruecklegen( theSwitch );
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

		merken( target );

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

		// \u26A0\uFE0F **Aufklappen zeigt genau eine Ebene, nicht den ganzen Ast** \u2014 sein Befund am
		// 2026-09-06: *\u00ABwenn ich auf einen andern knoten klicke wird der dann fast vollst\u00E4ndig
		// ausgeklappt, was nicht sein sollte nur der eine knoten soll aufgemacht werden\u00BB*.
		//
		// \u26A0\uFE0F *Hier stand `display = 'flex'` f\u00FCr **jede** tiefere Zeile bis zum Ende des Astes. Damit
		// klappte ein Klick auf `Model` sein ganzes Unterholz auf, gleich wie tief \u2014 und der
		// Klappzustand der Kinder war dabei egal, weil niemand ihn ansah.*
		//
		// \u26A0\uFE0F **Zuklappen nimmt dagegen den ganzen Ast mit**, und stellt seine Klapper auf \u00ABzu\u00BB:
		// *sonst st\u00FCnde ein Kind als \u00ABoffen\u00BB markiert da, w\u00E4hrend es versteckt ist, und der n\u00E4chste
		// Klick auf den Vater br\u00E4chte einen Ast zur\u00FCck, den niemand mehr im Sinn hatte.*
		while ( naechste && parseInt( naechste.getAttribute( 'data-depth' ), 10 ) > tiefe ) {
			var eigene = parseInt( naechste.getAttribute( 'data-depth' ), 10 );

			if ( zu ) {
				naechste.style.display = 'none';

				var innen = naechste.querySelector( '.taxmod-tree-fold' );

				if ( innen && innen.getAttribute( 'data-fold' ) === 'auf' ) {
					innen.setAttribute( 'data-fold', 'zu' );
					innen.innerHTML = '\u25B8';
				}
			} else if ( eigene === tiefe + 1 ) {
				naechste.style.display = 'flex';
			}

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
	 *
	 * WICHTIG: Das gilt nur fuer den Dialog OHNE eigenen Knopf. Sein Befund am 2026-09-06: "man
	 * klickt den combined knoten an und der dialog geht zu aber nichts passiert auch speichern
	 * hilft nicht". Gemessen: der Verschiebedialog traegt seinen Knopf INNEN
	 * ({@see \Taxmod\Core\Renderer\DialogChooserRenderer::CONFIRM}) -- also nahm dieses Zuklappen
	 * dem Benutzer genau den Knopf weg, mit dem er haette bestaetigen muessen. Der Akt selbst war
	 * nie kaputt; er wurde nie abgeschickt. Deshalb: hat der Dialog einen Fuss, bleibt er offen und
	 * zeigt oben, was gewaehlt ist. Hat er keinen, schliesst er wie bisher.
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
		var fuss = chooser.querySelector( '.taxmod-dialog-foot' );

		if ( fuss ) {
			// Der Dialog bestaetigt selbst: offen lassen, und im Kopf steht ab jetzt der gewaehlte
			// Knoten -- sonst waere der Klick ohne jede Rueckmeldung.
			var kopf = chooser.querySelector( '.taxmod-chooser-current' );

			if ( kopf && text ) {
				kopf.textContent = text.textContent;
			}

			return;
		}

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

	/**
	 * Den Klappzustand beim Laden anwenden.
	 *
	 * Der Auswahldialog traegt ALLE Zeilen im Dokument - er muss, weil er ohne Neuaufbau klappen
	 * koennen soll. Welche Aeste zu sind, sagt data-fold am Klapper. Ohne diese Stelle stand die
	 * Marke da und niemand las sie: gemessen 24 Aeste auf zu und trotzdem 125 sichtbare Zeilen.
	 */
	function klappzustandAnwenden( wurzel ) {
		var zu = wurzel.querySelectorAll( '.taxmod-tree-fold[data-fold="zu"]' );

		for ( var i = 0; i < zu.length; i++ ) {
			var zeile = zu[ i ].closest( '.taxmod-tree-row' );

			if ( ! zeile ) {
				continue;
			}

			var tiefe    = parseInt( zeile.getAttribute( 'data-depth' ), 10 );
			var naechste = zeile.nextElementSibling;

			while ( naechste && parseInt( naechste.getAttribute( 'data-depth' ), 10 ) > tiefe ) {
				naechste.style.display = 'none';
				naechste = naechste.nextElementSibling;
			}
		}
	}

	/**
	 * Eine geaenderte Einstellung wird sofort uebernommen -- die Seite zeichnet sich mit ihr neu.
	 *
	 * Auf sein Wort am 2026-09-06: "koennen wir einfuegen, dass bei Einstellungswechsel der Wert
	 * gleich uebernommen wird? (neu gezeichnet mit neuen Einstellungen)".
	 *
	 * WICHTIG: Nur Auswahlfelder und Schalter, nicht Textfelder. Ein Textfeld meldet seine
	 * Aenderung beim Verlassen, und ein Absenden mitten im Tippen naehme dem Benutzer die Zeile
	 * unter den Fingern weg.
	 *
	 * WICHTIG: Ohne Skript aendert sich nichts -- die Seite bleibt bedienbar, es kostet nur einen
	 * Klick auf Speichern mehr. Genau die Linie, die die Seitenansicht seit je haelt.
	 */
	document.addEventListener( 'change', function ( event ) {
		var feld = event.target;

		if ( ! feld || ! feld.matches ) {
			return;
		}

		if ( ! feld.matches( '.taxmod-setting-value select, .taxmod-setting-value input[type="checkbox"]' ) ) {
			return;
		}

		var form = feld.form || feld.closest( 'form' );

		if ( ! form ) {
			return;
		}

		if ( typeof form.requestSubmit === 'function' ) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			klappzustandAnwenden( document );
		} );
	} else {
		klappzustandAnwenden( document );
	}

} )();

/**
 * Der Einstellungsbereich einer Feldzeile — nachgeholt statt neu geladen.
 *
 * ⚠️ **Der Beschluss ist «nicht gelesen», nicht «versteckt»** (D-666). Der Server zeichnet den
 * Bereich nur, wenn er offen ist; ein `display:none` hätte ihn längst aufgelöst und mitgeschickt.
 * Dieses Stück ändert daran nichts — es holt denselben Bereich vom selben Kernaufruf, nur ohne die
 * ganze Seite neu zu zeichnen.
 *
 * ⚠️ **Ohne Skript funktioniert der Knopf trotzdem.** Er ist ein gewöhnlicher `<button name="do">`
 * im Formular seiner Zeile: abschicken, weiterleiten, Seite aufgeklappt neu. Dieses Stück fängt den
 * Klick nur ab, wenn es ihn auch bedienen kann — fehlt `fetch`, fehlt die Nonce oder antwortet der
 * Server nicht, geschieht **nichts**, und das Formular geht seinen alten Weg.
 *
 * ⚠️ *Es rendert nichts. Das Markup kommt fertig aus dem Kern (`R1`), hier wird es eingehängt.*
 */
( function () {
	'use strict';

	var AKT = 'toggle_field_settings';
	var HOL = 'taxmod_field_settings';

	/**
	 * Die Feldzeile, zu der dieser Knopf gehört.
	 *
	 * ⚠️ *Seit dem 2026-09-06 steht der Knopf in einer **eigenen** Zeile unter der Feldzeile — der
	 * Klappleiste, so wie im Bild, das er geschickt hat. Sie trägt selbst keine Kanten-Nummer, also
	 * ist die gesuchte Zeile die davor. `closest()` allein fände gar nichts mehr.*
	 */
	function zeileVon( knopf ) {
		var eigene = knopf.closest( 'tr' );

		if ( ! eigene ) {
			return null;
		}

		if ( eigene.hasAttribute( 'data-taxmod-relation' ) ) {
			return eigene;
		}

		var davor = eigene.previousElementSibling;

		return davor && davor.hasAttribute( 'data-taxmod-relation' ) ? davor : null;
	}

	/** Der Einstellungsbereich dieser Feldzeile, falls er schon geholt wurde. */
	function bereichVon( zeile ) {
		var naechste = zeile.nextElementSibling;

		// ⚠️ *Die Klappleiste steht dazwischen — eine Zeile weiter, und dann erst der Bereich.*
		while ( naechste && naechste.classList.contains( 'taxmod-field-fold' ) ) {
			naechste = naechste.nextElementSibling;
		}

		return naechste && naechste.classList.contains( 'taxmod-field-settings-row' ) ? naechste : null;
	}

	/**
	 * Das Dreieck sagt den Zustand — dieselben zwei Zeichen wie in der Baumzeile.
	 *
	 * ⚠️ *Der Server schreibt sie beim Zeichnen hin; hier werden sie umgestellt, weil ohne
	 * Seitenaufruf niemand sonst es tut. **Zwei Stellen für zwei Zeichen** — sie stehen im
	 * Kern (`FieldRowRenderer`) und hier, und mehr Stellen darf es nicht geben.*
	 */
	function zeichen( knopf, offen ) {
		var glyphe = knopf.querySelector( '.taxmod-icon-glyph' );

		if ( glyphe ) {
			glyphe.textContent = offen ? '▾' : '▸';
		}
	}

	/** Die Adresse des Nachschlags, gebaut aus dem, was das Formular der Zeile ohnehin trägt. */
	function adresse( form, kante ) {
		var id = form.querySelector( 'input[name="id"]' );
		var nonce = form.querySelector( 'input[name="_taxmod_nonce"]' );

		if ( ! form.action || ! id || ! nonce ) {
			return null;
		}

		return form.action
			+ ( form.action.indexOf( '?' ) === -1 ? '?' : '&' )
			+ 'action=' + encodeURIComponent( HOL )
			+ '&id=' + encodeURIComponent( id.value )
			+ '&relation=' + encodeURIComponent( kante )
			+ '&_taxmod_nonce=' + encodeURIComponent( nonce.value );
	}

	document.addEventListener( 'click', function ( ereignis ) {
		var knopf = ereignis.target && ereignis.target.closest
			? ereignis.target.closest( 'button[name="do"][value="' + AKT + '"]' )
			: null;

		if ( ! knopf || typeof window.fetch !== 'function' ) {
			return;
		}

		var zeile = zeileVon( knopf );
		var form = knopf.form || knopf.closest( 'form' );

		if ( ! zeile || ! form ) {
			return;
		}

		var kante = zeile.getAttribute( 'data-taxmod-relation' );
		var offen = bereichVon( zeile );

		// ⚠️ **Zuklappen versteckt, es wirft nicht weg** — sein Wort am 2026-09-06: «beim
		// Wiederzuklappen müssen der geladene Abschnitt nicht zerstört werden, einmal geladen
		// bleibt erstmal bis die Seite geschlossen wird, damit es mit gespeichert werden kann.»
		//
		// ⚠️ *Und das ist kein Widerspruch zu D-666: der Beschluss sagt «nicht gelesen» über eine
		// Zeile, die **noch nie** offen war. Eine einmal geöffnete ist längst aufgelöst — sie
		// wieder wegzuwerfen kostete beim nächsten Aufklappen einen zweiten Nachschlag und
		// verlöre auf dem Weg dahin jede Eingabe, die noch nicht gespeichert ist.*
		if ( offen && offen.classList.contains( 'taxmod-field-settings-row' ) ) {
			ereignis.preventDefault();
			offen.hidden = ! offen.hidden;
			zeichen( knopf, ! offen.hidden );

			return;
		}

		var url = adresse( form, kante );

		if ( ! url ) {
			return;
		}

		ereignis.preventDefault();

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( antwort ) {
				if ( ! antwort.ok ) {
					throw new Error( 'nachschlag' );
				}

				return antwort.text();
			} )
			.then( function ( markup ) {
				var spalten = zeile.children.length;
				var neu = document.createElement( 'tr' );

				neu.className = 'taxmod-field-settings-row';
				neu.innerHTML = '<td colspan="' + spalten + '">' + markup + '</td>';

				// ⚠️ *Hinter die Klappleiste, nicht davor — sonst stünde der Bereich über seinem
				// eigenen Knopf.*
				var leiste = knopf.closest( 'tr' ) || zeile;

				leiste.parentNode.insertBefore( neu, leiste.nextSibling );
				zeichen( knopf, true );
			} )
			.catch( function () {
				// ⚠️ *Der alte Weg bleibt der Rückfall: das Formular abschicken, Seite neu.*
				if ( typeof form.requestSubmit === 'function' ) {
					form.requestSubmit( knopf );
				} else {
					form.submit();
				}
			} );
	} );

	// ⚠️ **Ein Klick auf die ganze Zeile öffnet den Satz in der Vorschau** (D-788) — sein Wort: «jetzt Klick auf die ganze
	// Zeile bauen». *Die Satznummer bleibt der Link und ohne Skript der Weg; hier wird nur ihr Ziel für den Rest der Zeile
	// geliehen. Wer in der Zeile etwas bedient — Verschieben, Löschen, einen Sprung, einen Dialog —, meint nicht die Zeile.*
	document.addEventListener( 'click', function ( ereignis ) {
		if ( ereignis.defaultPrevented || ereignis.button !== 0 || ereignis.metaKey || ereignis.ctrlKey || ereignis.shiftKey || ereignis.altKey ) {
			return;
		}

		var ziel = ereignis.target;

		if ( ! ( ziel instanceof Element ) || ziel.closest( 'a, button, input, select, textarea, label, summary, dialog, .taxmod-table-acts' ) ) {
			return;
		}

		// ⚠️ **Nur eine Zeile, deren eigener Satzlink in ihr selbst steht** (D-803) — *sein Befund: «dialog opens and if you click somewhere
		// else it closes». Hier stand «eine Zelle der Zeile enthält irgendwo einen Satzlink», und die Seite selbst ist eine Tabelle: ihre
		// rechte Zelle enthält die Satztabelle. Jeder Klick in einen Dialog der Vorschau sprang so zum ersten Satz und lud die Seite neu.*
		var link  = null;
		var zeile = ziel.closest( 'tr' );

		while ( zeile && ! link ) {
			link = Array.prototype.find.call( zeile.querySelectorAll( 'a.taxmod-record-open' ), function ( kandidat ) {
				return kandidat.closest( 'tr' ) === zeile;
			} ) || null;

			if ( ! link ) {
				zeile = zeile.parentElement ? zeile.parentElement.closest( 'tr' ) : null;
			}
		}

		// *Ein Klick in einem Dialog gehört dem Dialog, nie der Zeile dahinter.*
		if ( ! link || ziel.closest( '.taxmod-dialog' ) ) {
			return;
		}

		// ⚠️ *Wer Text markiert, will kopieren, nicht springen.*
		if ( window.getSelection && String( window.getSelection() ).length > 0 ) {
			return;
		}

		window.location.href = link.href;
	} );

	// ⚠️ **Die Schreibweise der Elektronik** (D-792, Zeile 153) — sein Beispiel: *«user enter 1k and then all 1 k Ohm resistors will
	// appear or 1k7 all 1,7 K ohm resistors»*. *«1k7» wird zu den Wörtern «1.7» und «kilo», «4n7» zu «4.7» und «nano», «100p» zu «100»
	// und «pico»; ein Komma ist ein Punkt. Gross M ist mega, klein m milli — deshalb vor dem Kleinschreiben gelesen.*
	var PRAEFIXE = { p: 'pico', n: 'nano', u: 'micro', 'µ': 'micro', m: 'milli', k: 'kilo', K: 'kilo', M: 'mega', G: 'giga' };

	function suchwoerter( eingabe ) {
		var aus = [];

		String( eingabe ).split( /\s+/ ).filter( Boolean ).forEach( function ( wort ) {
			var treffer = /^(\d+(?:[.,]\d+)?)([pnuµmkKMG])(\d*)$/.exec( wort );

			if ( treffer ) {
				var zahl = treffer[ 3 ] ? treffer[ 1 ].replace( ',', '.' ) + ( treffer[ 1 ].indexOf( '.' ) === -1 && treffer[ 1 ].indexOf( ',' ) === -1 ? '.' : '' ) + treffer[ 3 ] : treffer[ 1 ].replace( ',', '.' );

				aus.push( zahl, PRAEFIXE[ treffer[ 2 ] ] );

				return;
			}

			aus.push( wort.replace( ',', '.' ).toLowerCase() );
		} );

		return aus;
	}

	// *Eine Zahl muss ein ganzes Wort treffen («1» trifft nicht «100»), jedes andere Wort den Anfang eines Wortes («smd» trifft «smd»,
	// «kera» trifft «keramik»).*
	function passt( text, woerter ) {
		var teile = text.split( /\s+/ );

		return woerter.every( function ( wort ) {
			var zahl = /^\d+(?:\.\d+)?$/.test( wort );

			return teile.some( function ( teil ) {
				return zahl ? teil === wort : teil.indexOf( wort ) === 0;
			} );
		} );
	}

	// ⚠️ **Die Suche im Dialog der Satzauswahl** (D-791 Schritt 2) — *jedes Wort muss im Suchtext eines Satzes stehen; was nicht passt,
	// wird ausgeblendet, ein Ast ohne sichtbaren Satz auch, und ein Ast mit Treffern klappt auf. Leeres Feld: alles wieder da.*
	document.addEventListener( 'input', function ( ereignis ) {
		var feld = ereignis.target;

		if ( ! ( feld instanceof HTMLInputElement ) || ! feld.classList.contains( 'taxmod-record-search' ) ) {
			return;
		}

		var baum = feld.closest( '.taxmod-record-tree' );

		if ( ! baum ) {
			return;
		}

		var woerter = suchwoerter( feld.value );

		baum.querySelectorAll( '.taxmod-record-choice[data-taxmod-search]' ).forEach( function ( wahl ) {
			wahl.hidden = ! passt( wahl.getAttribute( 'data-taxmod-search' ) || '', woerter );
		} );

		// *Seit D-805 stehen die Sätze rechts in Gruppen je Knoten: eine Gruppe ohne Treffer verschwindet, der Baum links bleibt.*
		baum.querySelectorAll( '.taxmod-record-group' ).forEach( function ( gruppe ) {
			var treffer = gruppe.querySelector( '.taxmod-record-choice[data-taxmod-search]:not([hidden])' );

			gruppe.classList.toggle( 'taxmod-record-group-nomatch', woerter.length > 0 && ! treffer );
		} );
	} );

	// ⚠️ **Das Suchfeld vor dem Satzdialog** (D-792, Zeile 153) — *tippen, darunter erscheinen die passenden Sätze aus dem Baum des
	// Dialogs; ein Klick (oder Enter für den ersten) setzt den Auswahlknopf im Dialog und schreibt den Satz in den Öffner. Findet sich
	// nichts, ist der Knopf daneben der Dialog.*
	function trefferZeigen( feld ) {
		var wahl  = feld.closest( '.taxmod-record-pick' );
		var liste = wahl ? wahl.querySelector( '.taxmod-record-hits' ) : null;

		if ( ! liste ) {
			return;
		}

		var woerter = suchwoerter( feld.value );

		liste.innerHTML = '';

		if ( woerter.length === 0 ) {
			liste.hidden = true;

			return;
		}

		var anzahl = 0;

		wahl.querySelectorAll( '.taxmod-record-choice[data-taxmod-search]' ).forEach( function ( eintrag ) {
			var knopf = eintrag.querySelector( 'input[type="radio"]' );

			if ( anzahl >= 15 || ! knopf || ! passt( eintrag.getAttribute( 'data-taxmod-search' ) || '', woerter ) ) {
				return;
			}

			var treffer = document.createElement( 'button' );

			treffer.type = 'button';
			treffer.className = 'taxmod-record-hit';
			treffer.textContent = eintrag.textContent.trim();
			treffer.setAttribute( 'data-taxmod-value', knopf.value );
			liste.appendChild( treffer );
			anzahl++;
		} );

		liste.hidden = anzahl === 0;
	}

	function trefferNehmen( treffer ) {
		var wahl = treffer.closest( '.taxmod-record-pick' );

		if ( ! wahl ) {
			return;
		}

		wahl.querySelectorAll( '.taxmod-record-choice input[type="radio"]' ).forEach( function ( knopf ) {
			if ( knopf.value === treffer.getAttribute( 'data-taxmod-value' ) ) {
				knopf.checked = true;
			}
		} );

		var oeffner = wahl.querySelector( '.taxmod-dialog-open, .taxmod-record-dialog-open' );

		if ( oeffner ) {
			oeffner.textContent = treffer.textContent;
			oeffner.className = 'button taxmod-record-dialog-open';
		}

		var feld = wahl.querySelector( '.taxmod-record-quick' );

		if ( feld ) {
			feld.value = '';
		}

		wahl.querySelector( '.taxmod-record-hits' ).hidden = true;
	}

	document.addEventListener( 'input', function ( ereignis ) {
		if ( ereignis.target instanceof HTMLInputElement && ereignis.target.classList.contains( 'taxmod-record-quick' ) ) {
			trefferZeigen( ereignis.target );
		}
	} );

	// ⚠️ **Ein Satz im Dialog gewählt: er steht im Öffner, und der Dialog geht zu** (D-803) — *sein Befund: «if you select a leave it closes
	// but does not select the leave for the item at least it does not show up». Der Auswahlknopf war gesetzt, aber nichts zeigte ihn;
	// gespeichert wird er weiter mit der Seite.*
	document.addEventListener( 'change', function ( ereignis ) {
		var knopf = ereignis.target;

		if ( ! ( knopf instanceof HTMLInputElement ) || knopf.type !== 'radio' || ! knopf.closest( '.taxmod-record-tree' ) ) {
			return;
		}

		var wahl    = knopf.closest( '.taxmod-record-pick' ) || knopf.closest( '.taxmod-chooser' );
		var eintrag = knopf.closest( '.taxmod-record-choice' );
		var oeffner = wahl ? wahl.querySelector( '.taxmod-dialog-open, .taxmod-record-dialog-open' ) : null;
		var kopf    = wahl ? wahl.querySelector( '.taxmod-chooser-current' ) : null;
		var wort    = eintrag ? eintrag.textContent.trim() : '';

		if ( oeffner ) {
			oeffner.textContent = wort === '' ? '—' : wort;
			oeffner.className = 'button taxmod-record-dialog-open';
		}

		if ( kopf ) {
			kopf.textContent = wort === '' ? '—' : wort;
		}

		// ⚠️ *Der Dialog bleibt offen — geschlossen wird nur über «OK», «Abbrechen» oder das ✕ (D-804). «Abbrechen» nimmt die Wahl zurück.*
	} );

	document.addEventListener( 'click', function ( ereignis ) {
		var treffer = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-record-hit' ) : null;

		if ( treffer ) {
			ereignis.preventDefault();
			trefferNehmen( treffer );
		}
	} );

	document.addEventListener( 'keydown', function ( ereignis ) {
		var feld = ereignis.target;

		if ( ereignis.key !== 'Enter' || ! ( feld instanceof HTMLInputElement ) || ! feld.classList.contains( 'taxmod-record-quick' ) ) {
			return;
		}

		ereignis.preventDefault();

		var erster = feld.closest( '.taxmod-record-pick' ).querySelector( '.taxmod-record-hit' );

		if ( erster ) {
			trefferNehmen( erster );
		}
	} );

	// ⚠️ *Das eine Auswahlfeld (D-380): ohne Eintrag gesperrt und ausgegraut, der «+» daneben mit — auch nachdem das Skript Einträge
	// herausgenommen oder zurückgelegt hat.*
	function auswahlZustand( auswahl ) {
		if ( ! auswahl ) {
			return;
		}

		var frei  = Array.prototype.some.call( auswahl.options, function ( eintrag ) { return eintrag.value !== ''; } );
		var knopf = auswahl.parentElement ? auswahl.parentElement.querySelector( '.taxmod-list-add, .taxmod-addon-add' ) : null;

		auswahl.disabled    = ! frei;
		auswahl.style.opacity = frei ? '' : '.55';

		if ( knopf ) {
			knopf.disabled      = ! frei;
			knopf.style.opacity = frei ? '' : '.35';
		}
	}

	// ⚠️ **Die Werteliste eines mehrfachen Feldes** (D-842) — sein Wort: «darstellungsform für multiple ordered lists mit aktivierung die
	// sollten wir auch für den renderer verwenden». *Dieselbe Liste wie in den Einstellungen, nur dass ein Wert selbst das Mitglied ist:
	// Hinzufügen legt eine Zeile mit dem Wert als verborgenem Eintrag an, der Mülleimer nimmt die Zeile ganz heraus. Die Reihenfolge der
	// Zeilen ist die gespeicherte.*
	function wertZeileAnlegen( waehler, wert, wort ) {
		var liste  = waehler.querySelector( '.taxmod-switch-cascade' );
		var quelle = waehler.querySelector( '[data-taxmod-name]' );

		if ( ! liste || ! quelle ) {
			return;
		}

		var zeile    = document.createElement( 'li' );
		var mitglied = document.createElement( 'input' );
		var name     = document.createElement( 'span' );

		zeile.className = 'taxmod-switch-chosen';
		zeile.setAttribute( 'data-taxmod-id', wert );
		mitglied.type = 'hidden';
		mitglied.className = 'taxmod-switch-member';
		mitglied.name = quelle.getAttribute( 'data-taxmod-name' );
		mitglied.value = wert;


		if ( liste.querySelector( 'input[form]' ) ) {
			mitglied.setAttribute( 'form', liste.querySelector( 'input[form]' ).getAttribute( 'form' ) );
		} else if ( quelle.getAttribute( 'form' ) ) {
			mitglied.setAttribute( 'form', quelle.getAttribute( 'form' ) );
		}

		name.className = 'taxmod-switch-name';
		name.textContent = wort;
		zeile.appendChild( mitglied );
		zeile.appendChild( name );
		zeile.appendChild( document.createTextNode( ' ' ) );

		[ [ 'taxmod-list-move', 'up', 'arrow-up-alt2', '#1d2327' ], [ 'taxmod-list-move', 'down', 'arrow-down-alt2', '#1d2327' ], [ 'taxmod-list-remove', '', 'trash', '#b32d2e' ] ].forEach( function ( art ) {
			var knopf = document.createElement( 'button' );
			var bild  = document.createElement( 'span' );

			knopf.type = 'button';
			knopf.className = 'button taxmod-icon-button ' + art[ 0 ];
			knopf.style.color = art[ 3 ];

			if ( art[ 1 ] ) {
				knopf.setAttribute( 'data-taxmod-move', art[ 1 ] );
			}

			bild.className = 'taxmod-icon dashicons dashicons-' + art[ 2 ];
			bild.setAttribute( 'aria-hidden', 'true' );
			knopf.title = wort;
			knopf.appendChild( bild );
			zeile.appendChild( knopf );
		} );

		liste.appendChild( zeile );
		wertPfeileSetzen( liste );
	}

	function wertPfeileSetzen( liste ) {
		var gewaehlte = liste.querySelectorAll( 'li.taxmod-switch-chosen' );

		gewaehlte.forEach( function ( eintrag, stelle ) {
			eintrag.querySelectorAll( '.taxmod-list-move' ).forEach( function ( pfeil ) {
				var gesperrt = pfeil.getAttribute( 'data-taxmod-move' ) === 'up' ? stelle === 0 : stelle === gewaehlte.length - 1;

				pfeil.disabled = gesperrt;
				pfeil.style.opacity = gesperrt ? '.35' : '';
			} );
		} );
	}

	function wertHinzufuegen( waehler ) {
		var auswahl = waehler.querySelector( '.taxmod-value-candidates' );
		var neu     = waehler.querySelector( '.taxmod-value-new' );

		if ( auswahl ) {
			var eintrag = auswahl.options[ auswahl.selectedIndex ];

			if ( ! eintrag || eintrag.value === '' ) {
				return;
			}

			wertZeileAnlegen( waehler, eintrag.value, eintrag.textContent );
			eintrag.remove();
			auswahl.value = '';
			auswahlZustand( auswahl );

			return;
		}

		if ( neu && neu.value.trim() !== '' ) {
			wertZeileAnlegen( waehler, neu.value.trim(), neu.value.trim() );
			neu.value = '';
			neu.focus();
		}
	}

	document.addEventListener( 'click', function ( ereignis ) {
		var ziel    = ereignis.target instanceof Element ? ereignis.target : null;
		var knopf   = ziel ? ziel.closest( '.taxmod-list-add, .taxmod-list-remove' ) : null;
		var waehler = knopf ? knopf.closest( '.taxmod-value-picker' ) : null;

		if ( ! waehler ) {
			return;
		}

		// *Die Schalterliste der Einstellungen hört auf dieselben Knöpfe; hier endet der Klick.*
		ereignis.preventDefault();
		ereignis.stopImmediatePropagation();

		if ( knopf.classList.contains( 'taxmod-list-add' ) ) {
			wertHinzufuegen( waehler );

			return;
		}

		var zeile   = knopf.closest( 'li' );
		var auswahl = waehler.querySelector( '.taxmod-value-candidates' );

		if ( ! zeile ) {
			return;
		}

		if ( auswahl ) {
			var zurueck = document.createElement( 'option' );

			zurueck.value = zeile.getAttribute( 'data-taxmod-id' ) || '';
			zurueck.textContent = ( zeile.querySelector( '.taxmod-switch-name' ) || zeile ).textContent;
			auswahl.appendChild( zurueck );
			auswahlZustand( auswahl );
		}

		var liste = zeile.parentElement;

		zeile.remove();
		wertPfeileSetzen( liste );
	}, true );

	// ⚠️ **Die Zusatzfunktionen einer Stelle** (D-845). *Dieselbe Liste; ein neues Glied kommt aus der Vorlage seiner Funktion, mit einer
	// eigenen Nummer in der Adresse. Der Mülleimer nimmt das Glied heraus; gespeichert wird mit der Seite, in der Reihenfolge der Liste.*
	var addonZaehler = 0;

	document.addEventListener( 'click', function ( ereignis ) {
		var ziel    = ereignis.target instanceof Element ? ereignis.target : null;
		var knopf   = ziel ? ziel.closest( '.taxmod-addon-add, .taxmod-list-remove' ) : null;
		var waehler = knopf ? knopf.closest( '.taxmod-addon-picker' ) : null;

		if ( ! waehler ) {
			return;
		}

		ereignis.preventDefault();
		ereignis.stopImmediatePropagation();

		var liste = waehler.querySelector( '.taxmod-switch-cascade' );

		if ( knopf.classList.contains( 'taxmod-list-remove' ) ) {
			var weg = knopf.closest( 'li' );

			if ( weg ) {
				weg.remove();
				wertPfeileSetzen( liste );
			}

			return;
		}

		var auswahl = waehler.querySelector( '.taxmod-addon-candidates' );
		var name    = auswahl ? auswahl.value : '';
		var vorlage = null;

		waehler.querySelectorAll( 'template.taxmod-addon-template' ).forEach( function ( eine ) {
			if ( eine.getAttribute( 'data-taxmod-addon' ) === name ) {
				vorlage = eine;
			}
		} );

		if ( ! vorlage || ! liste ) {
			return;
		}

		addonZaehler++;

		var glied = 'n' + Date.now() + '_' + addonZaehler;
		var halter = document.createElement( 'div' );

		halter.innerHTML = vorlage.innerHTML.split( '__glied__' ).join( glied );
		halter.querySelectorAll( '[data-taxmod-name]' ).forEach( function ( feld ) {
			feld.setAttribute( 'name', feld.getAttribute( 'data-taxmod-name' ) );
			feld.removeAttribute( 'data-taxmod-name' );
		} );

		while ( halter.firstElementChild ) {
			liste.appendChild( halter.firstElementChild );
		}

		auswahl.value = '';
		auswahlZustand( auswahl );
		wertPfeileSetzen( liste );
	}, true );

	// ⚠️ **Automatisch speichern** (D-854) — *sein Wort: «wir sollten dafür einen umschalter in der config machen, autosave und ihn
	// anschalten. es ist aber nicht immer das feld bei checkboxen kann es auch beim verlassen der gruppe sein».* Ein Textfeld schickt beim
	// Verlassen, ein Haken oder eine Wahl erst, wenn der Fokus die Gruppe verlässt — so reisen mehrere Haken zusammen. Was in einem Dialog
	// steht, wartet auf «OK», und die Suchfelder schicken nie.
	var autosaveWartet = null;

	function autosaveAn( teil ) {
		var seite = teil.closest( '[data-taxmod-autosave]' );

		return !! seite && seite.getAttribute( 'data-taxmod-autosave' ) === 'on';
	}

	function autosaveGruppe( feld ) {
		return feld.closest( '.taxmod-switch-picker, .taxmod-addon-picker, .taxmod-settings-switches, .taxmod-setting-band, fieldset, li, tr' );
	}

	function autosaveSchicken( feld ) {
		var formular = feld.form || feld.closest( 'form' );

		if ( ! formular ) {
			return;
		}

		if ( autosaveWartet ) {
			window.clearTimeout( autosaveWartet );
		}

		autosaveWartet = window.setTimeout( function () {
			autosaveWartet = null;

			var knopf = formular.querySelector( 'button[name="do"], input[type="submit"][name="do"]' );

			if ( formular.requestSubmit ) {
				formular.requestSubmit( knopf || undefined );
			} else {
				formular.submit();
			}
		}, 150 );
	}

	function autosaveMeint( feld ) {
		if ( ! ( feld instanceof HTMLInputElement || feld instanceof HTMLSelectElement || feld instanceof HTMLTextAreaElement ) ) {
			return false;
		}

		if ( feld.disabled || feld.type === 'search' || feld.type === 'file' || feld.type === 'hidden' || feld.name === '' ) {
			return false;
		}

		// *In einem Dialog entscheidet «OK»; Baumfilter und Schnellsuche schicken ihre eigenen Wege.*
		if ( feld.closest( '.taxmod-dialog, .taxmod-tree-searchform' ) || feld.classList.contains( 'taxmod-tree-filter' ) ) {
			return false;
		}

		return autosaveAn( feld );
	}

	document.addEventListener( 'change', function ( ereignis ) {
		var feld = ereignis.target;

		if ( ! autosaveMeint( feld ) ) {
			return;
		}

		// *Haken und Auswahlknöpfe warten auf das Verlassen ihrer Gruppe.*
		if ( feld.type === 'checkbox' || feld.type === 'radio' ) {
			return;
		}

		autosaveSchicken( feld );
	} );

	document.addEventListener( 'focusout', function ( ereignis ) {
		var feld = ereignis.target;

		if ( ! autosaveMeint( feld ) || ( feld.type !== 'checkbox' && feld.type !== 'radio' ) ) {
			return;
		}

		var gruppe = autosaveGruppe( feld );

		window.setTimeout( function () {
			var jetzt = document.activeElement;

			if ( gruppe && jetzt && gruppe.contains( jetzt ) ) {
				return;
			}

			autosaveSchicken( feld );
		}, 0 );
	} );

	// ⚠️ **Der Linkdialog von WordPress** (D-857) — sein Wort: «ja bau den wp linkdialog ein». *Der Dialog schreibt seinen Link als
	// `<a href="…">Text</a>` in ein verborgenes Textfeld; beim Schliessen liest dieses Skript daraus Adresse und Linktext und trägt sie in
	// das Adressfeld und das Beschriftungsfeld der Zeile ein. Abbrechen lässt das Textfeld leer — dann bleibt alles, wie es war. Die
	// Felder melden die Änderung, damit auch das automatische Speichern (D-854) sie sieht.*
	var linkZiel = null;

	// *Die Felder, die ein Medienknopf füllt: über die Namen am Knopf (D-858 — die Knöpfe stehen rechts, nicht mehr im Feld), sonst das Feld
	// daneben.*
	function medienFelder( knopf ) {
		var suche = function ( name ) {
			return name ? document.querySelector( '[name="' + name.replace( /"/g, '\\"' ) + '"]' ) : null;
		};
		var adresse = suche( knopf.getAttribute( 'data-taxmod-address' ) || '' );

		if ( ! adresse ) {
			var umfeld = knopf.closest( '.taxmod-media-input, .taxmod-switch-add' );

			adresse = umfeld ? umfeld.querySelector( 'input[type="text"]' ) : null;
		}

		return { adresse: adresse, titel: suche( knopf.getAttribute( 'data-taxmod-caption' ) || '' ) };
	}

	function medienEintragen( ziel, adresse, titel ) {
		ziel.adresse.value = adresse;
		ziel.adresse.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		if ( ziel.titel && titel ) {
			ziel.titel.value = titel;
			ziel.titel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	// ⚠️ **Die Mediathek** (D-858) — sein Wort: «für datei sollte die mediathek geöffnet werden». *Gewählt wird eine Datei; ihre Adresse kommt
	// ins Adressfeld, ihr Titel ins Beschriftungsfeld, wenn dort noch nichts steht.*
	document.addEventListener( 'click', function ( ereignis ) {
		var knopf = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-media-library' ) : null;

		if ( ! knopf || ! window.wp || ! window.wp.media ) {
			return;
		}

		ereignis.preventDefault();

		var ziel = medienFelder( knopf );

		if ( ! ziel.adresse ) {
			return;
		}

		var rahmen = window.wp.media( { title: knopf.getAttribute( 'title' ) || '', multiple: false } );

		rahmen.on( 'select', function () {
			var datei = rahmen.state().get( 'selection' ).first().toJSON();

			medienEintragen( ziel, datei.url || '', ziel.titel && ziel.titel.value.trim() !== '' ? '' : ( datei.title || '' ) );
		} );

		rahmen.open();
	} );

	document.addEventListener( 'click', function ( ereignis ) {
		var knopf  = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-media-wplink' ) : null;
		var ablage = document.getElementById( 'taxmod-wplink-target' );

		if ( ! knopf || ! ablage || ! window.wpLink ) {
			return;
		}

		var ziel    = medienFelder( knopf );
		var adresse = ziel.adresse;
		var titel   = ziel.titel;

		if ( ! adresse ) {
			return;
		}

		ereignis.preventDefault();

		linkZiel = ziel;
		ablage.value = '';
		window.wpLink.open( 'taxmod-wplink-target', adresse.value, titel ? titel.value : '' );

		// *«In neuem Tab öffnen» folgt der Einstellung des Feldes (D-858), Vorgabe an.*
		var neuerTab = document.getElementById( 'wp-link-target' );

		if ( neuerTab ) {
			neuerTab.checked = knopf.getAttribute( 'data-taxmod-newtab' ) !== '0';
		}

		// *Ohne Editor setzt der Dialog die Adresse nicht selbst vor — gemessen: das Feld blieb leer, und ohne Adresse schreibt er nichts.*
		var dialogAdresse = document.getElementById( 'wp-link-url' );

		if ( dialogAdresse && adresse.value !== '' ) {
			dialogAdresse.value = adresse.value;
		}
	} );

	if ( window.jQuery ) {
		window.jQuery( document ).on( 'wplink-close', function () {
			var ablage = document.getElementById( 'taxmod-wplink-target' );
			var ziel   = linkZiel;

			linkZiel = null;

			if ( ! ablage || ! ziel || ablage.value.trim() === '' ) {
				return;
			}

			var gelesen = new DOMParser().parseFromString( ablage.value, 'text/html' ).querySelector( 'a' );

			ablage.value = '';

			if ( ! gelesen ) {
				return;
			}

			medienEintragen( ziel, gelesen.getAttribute( 'href' ) || '', gelesen.textContent.trim() );
		} );
	}

	// ⚠️ **Der Mülleimer an einer gesetzten Referenz** (D-851) — *sein Wort: «bei den referenzen könnte höchstens delete stehen und dann eine
	// neue wahl ermöglichen». Er wählt «nichts» (der leere Knopf des Dialogs) und gibt Suchfeld und Öffner wieder frei; gespeichert wird mit
	// der Seite.*
	document.addEventListener( 'click', function ( ereignis ) {
		var knopf = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-record-clear' ) : null;
		var wahl  = knopf ? knopf.closest( '.taxmod-record-pick' ) : null;

		if ( ! wahl ) {
			return;
		}

		ereignis.preventDefault();

		var leer = wahl.querySelector( 'input[type="radio"][value=""]' );

		if ( leer ) {
			leer.checked = true;
		}

		var oeffner = wahl.querySelector( '.taxmod-dialog-open, .taxmod-record-dialog-open' );
		var feld    = wahl.querySelector( '.taxmod-record-quick' );

		if ( oeffner ) {
			oeffner.textContent = '—';
		}

		wahl.classList.remove( 'taxmod-record-taken' );
		knopf.remove();

		if ( feld ) {
			feld.hidden = false;
			feld.focus();
		}
	} );

	// ⚠️ **Jeder Symbolknopf zeigt beim Darüberfahren seinen Namen** (D-847) — sein Wort: «Tooltip knopf beschreibung/name». *Die Knöpfe aus
	// {@see ControlMarkup} tragen ihn schon; hier bekommen ihn auch die von Hand gebauten und die aus Vorlagen eingefügten, aus dem Namen
	// ihres Symbols — beim ersten Darüberfahren, also auch für später eingefügte.*
	document.addEventListener( 'mouseover', function ( ereignis ) {
		var knopf = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-icon-button' ) : null;

		if ( ! knopf || knopf.hasAttribute( 'title' ) ) {
			return;
		}

		var benannt = knopf.querySelector( '[aria-label]' );
		var name    = benannt ? benannt.getAttribute( 'aria-label' ) : '';

		if ( name ) {
			knopf.setAttribute( 'title', name );
		}
	} );


	// *Enter im Eingabefeld übernimmt den Wert, statt die Seite abzuschicken.*
	document.addEventListener( 'keydown', function ( ereignis ) {
		var feld = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-value-new' ) : null;

		if ( ! feld || ereignis.key !== 'Enter' ) {
			return;
		}

		ereignis.preventDefault();
		wertHinzufuegen( feld.closest( '.taxmod-value-picker' ) );
	} );

	// ⚠️ **Hinzufügen und Entfernen in einer Auswahlliste** (D-799) — sein Wort: «select unit and press add, added showing up in a list
	// and can be removed again». *Hinzufügen legt eine Zeile mit verborgener 1 an und nimmt den Eintrag aus dem Auswahlfeld; Entfernen setzt
	// die verborgene 0, blendet die Zeile aus und gibt den Eintrag dem Auswahlfeld zurück. Gespeichert wird mit der Seite.*
	document.addEventListener( 'click', function ( ereignis ) {
		var ziel = ereignis.target instanceof Element ? ereignis.target : null;
		var dazu = ziel ? ziel.closest( '.taxmod-list-add' ) : null;
		var weg  = ziel ? ziel.closest( '.taxmod-list-remove' ) : null;

		if ( ! dazu && ! weg ) {
			return;
		}

		ereignis.preventDefault();

		var waehler = ( dazu || weg ).closest( '.taxmod-switch-picker' );
		var liste   = waehler ? waehler.querySelector( '.taxmod-switch-cascade' ) : null;
		var auswahl = waehler ? waehler.querySelector( '.taxmod-switch-candidates' ) : null;

		if ( ! liste || ! auswahl ) {
			return;
		}

		if ( dazu ) {
			var eintrag = auswahl.options[ auswahl.selectedIndex ];

			if ( ! eintrag || eintrag.value === '' ) {
				return;
			}

			var zeile = document.createElement( 'li' );
			var mitglied = document.createElement( 'input' );
			var name = document.createElement( 'span' );
			var entfernen = document.createElement( 'button' );

			zeile.className = 'taxmod-switch-chosen';
			zeile.setAttribute( 'data-taxmod-id', eintrag.value );
			mitglied.type = 'hidden';
			mitglied.className = 'taxmod-switch-member';
			mitglied.name = auswahl.name.replace( /\[add\]$/, '[' + eintrag.value + ']' );
			mitglied.value = '1';

			if ( auswahl.getAttribute( 'form' ) ) {
				mitglied.setAttribute( 'form', auswahl.getAttribute( 'form' ) );
			}

			name.className = 'taxmod-switch-name';
			name.textContent = eintrag.textContent;
			entfernen.type = 'button';
			entfernen.className = 'button taxmod-icon-button taxmod-list-remove';
			entfernen.style.color = '#b32d2e';
			// *Löschen ist immer der Mülleimer (D-828).*
			entfernen.innerHTML = '<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"></span>';
			entfernen.querySelector( '.screen-reader-text' ).textContent = eintrag.textContent;

			zeile.appendChild( mitglied );
			zeile.appendChild( name );
			zeile.appendChild( entfernen );
			liste.appendChild( zeile );
			eintrag.remove();
			auswahl.value = '';
			auswahlZustand( auswahl );

			return;
		}

		var weggenommen = weg.closest( 'li' );
		var feld = weggenommen ? weggenommen.querySelector( '.taxmod-switch-member' ) : null;

		if ( ! weggenommen || ! feld ) {
			return;
		}

		feld.value = '0';
		weggenommen.hidden = true;
		weggenommen.classList.remove( 'taxmod-switch-chosen' );

		var zurueck = document.createElement( 'option' );

		zurueck.value = weggenommen.getAttribute( 'data-taxmod-id' ) || '';
		zurueck.textContent = ( weggenommen.querySelector( '.taxmod-switch-name' ) || weggenommen ).textContent;
		auswahl.appendChild( zurueck );
		auswahlZustand( auswahl );
	} );

	// ⚠️ **Die Pfeile einer geordneten Schalterliste** (D-794) — sein Wort: «more ordered by arrows». *Eine gewählte Zeile tauscht mit
	// ihrer gewählten Nachbarin; danach werden die verborgenen Stellen der Reihe nach neu gezählt und mit der Seite gespeichert.*
	document.addEventListener( 'click', function ( ereignis ) {
		var knopf = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-list-move' ) : null;

		if ( ! knopf ) {
			return;
		}

		ereignis.preventDefault();

		var zeile = knopf.closest( 'li' );
		var liste = zeile ? zeile.parentElement : null;

		if ( ! liste ) {
			return;
		}

		if ( knopf.getAttribute( 'data-taxmod-move' ) === 'up' ) {
			var davor = zeile.previousElementSibling;

			if ( davor && davor.classList.contains( 'taxmod-switch-chosen' ) ) {
				liste.insertBefore( zeile, davor );
			}
		} else {
			var danach = zeile.nextElementSibling;

			if ( danach && danach.classList.contains( 'taxmod-switch-chosen' ) ) {
				liste.insertBefore( danach, zeile );
			}
		}

		var gewaehlte = liste.querySelectorAll( 'li.taxmod-switch-chosen' );

		gewaehlte.forEach( function ( eintrag, stelle ) {
			var feld = eintrag.querySelector( '.taxmod-setting-list-position' );

			if ( feld ) {
				feld.value = String( stelle );
			}

			// *Wie in den Feldzeilen: der erste kann nicht höher, der letzte nicht tiefer.*
			eintrag.querySelectorAll( '.taxmod-list-move' ).forEach( function ( pfeil ) {
				var gesperrt = pfeil.getAttribute( 'data-taxmod-move' ) === 'up' ? stelle === 0 : stelle === gewaehlte.length - 1;

				pfeil.disabled = gesperrt;
				pfeil.style.opacity = gesperrt ? '.35' : '';
			} );
		} );

		if ( ! knopf.disabled ) {
			knopf.focus();
		}
	} );

	// ⚠️ **Ein Knoten im Baum des Satzdialogs zeigt rechts seine Sätze** (D-805) — sein Wort: «show the tree select a node and then see a
	// list of datasets to select from in a list». *Gezeigt werden die Gruppe des Knotens und die aller Knoten darunter — erkannt an der
	// Tiefe: nach dem Knoten, bis eine Gruppe nicht mehr tiefer liegt. Das Auf- und Zuklappen des Astes macht der Browser selbst.*
	document.addEventListener( 'click', function ( ereignis ) {
		var name = ereignis.target instanceof Element ? ereignis.target.closest( '.taxmod-record-node' ) : null;
		var baum = name ? name.closest( '.taxmod-record-tree' ) : null;

		if ( ! baum ) {
			return;
		}

		var nummer = name.getAttribute( 'data-taxmod-group' );
		var drin = false;
		var tiefe = -1;

		baum.querySelectorAll( '.taxmod-record-node.is-active' ).forEach( function ( alt ) {
			alt.classList.remove( 'is-active' );
		} );
		name.classList.add( 'is-active' );

		baum.querySelectorAll( '.taxmod-record-group' ).forEach( function ( gruppe ) {
			var eigene = parseInt( gruppe.getAttribute( 'data-taxmod-depth' ) || '0', 10 );

			if ( gruppe.getAttribute( 'data-taxmod-group' ) === nummer ) {
				drin = true;
				tiefe = eigene;
			} else if ( drin && eigene <= tiefe ) {
				drin = false;
				tiefe = -2;
			}

			gruppe.classList.toggle( 'taxmod-record-group-off', ! drin );
		} );
	} );

	// ⚠️ **Der Baum lässt sich ausschalten** (D-805) — sein Wort: «can be switched on and off». *Aus: nur die Liste, alle Gruppen.*
	document.addEventListener( 'change', function ( ereignis ) {
		var schalter = ereignis.target;

		if ( ! ( schalter instanceof HTMLInputElement ) || ! schalter.classList.contains( 'taxmod-record-treetoggle' ) ) {
			return;
		}

		var baum = schalter.closest( '.taxmod-record-tree' );

		if ( ! baum ) {
			return;
		}

		baum.classList.toggle( 'taxmod-record-notree', ! schalter.checked );

		if ( ! schalter.checked ) {
			baum.querySelectorAll( '.taxmod-record-group-off' ).forEach( function ( gruppe ) {
				gruppe.classList.remove( 'taxmod-record-group-off' );
			} );
			baum.querySelectorAll( '.taxmod-record-node.is-active' ).forEach( function ( alt ) {
				alt.classList.remove( 'is-active' );
			} );
		}
	} );

	// *Enter in der Suche schickt nichts ab.*
	document.addEventListener( 'keydown', function ( ereignis ) {
		if ( ereignis.key === 'Enter' && ereignis.target instanceof HTMLInputElement && ereignis.target.classList.contains( 'taxmod-record-search' ) ) {
			ereignis.preventDefault();
		}
	} );

	// ⚠️ **Ein mitlaufender waagerechter Balken unter jeder breiten Satztabelle** (D-807) — sein Befund: «no scrollbar, and records are
	// shrinked to size of the page». *Der echte Balken des Satzformulars liegt am Ende einer langen Tabelle; dieser steht unten am Fenster,
	// solange die Tabelle im Blick ist, und rollt dasselbe.*
	function balkenAnlegen() {
		document.querySelectorAll( '.taxmod-records-block .taxmod-record-form' ).forEach( function ( formular ) {
			var balken = formular.nextElementSibling && formular.nextElementSibling.classList.contains( 'taxmod-hscroll' ) ? formular.nextElementSibling : null;

			if ( formular.scrollWidth <= formular.clientWidth + 1 ) {
				if ( balken ) {
					balken.hidden = true;
				}

				return;
			}

			if ( ! balken ) {
				balken = document.createElement( 'div' );
				balken.className = 'taxmod-hscroll';
				balken.appendChild( document.createElement( 'div' ) );
				formular.parentNode.insertBefore( balken, formular.nextSibling );

				var gerade = false;

				balken.addEventListener( 'scroll', function () {
					if ( gerade ) {
						gerade = false;

						return;
					}

					gerade = true;
					formular.scrollLeft = balken.scrollLeft;
				}, { passive: true } );
				formular.addEventListener( 'scroll', function () {
					if ( gerade ) {
						gerade = false;

						return;
					}

					gerade = true;
					balken.scrollLeft = formular.scrollLeft;
				}, { passive: true } );
			}

			balken.hidden = false;
			balken.style.width = formular.clientWidth + 'px';
			balken.firstChild.style.width = formular.scrollWidth + 'px';
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', balkenAnlegen );
	} else {
		balkenAnlegen();
	}

	window.addEventListener( 'resize', balkenAnlegen );

} )();
