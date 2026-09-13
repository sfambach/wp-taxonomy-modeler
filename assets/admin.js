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
		// ⚠️ **Ein Anker in der Adresse geht vor** (D-788) — sein Befund: *«Wenn ich auf eine Zeile klicke, dann springt der
		// Bildschirm ganz nach oben … er sollte … in der Eingabe anhalten.»* *Der geöffnete Satz bringt `#taxmod-preview-edit`
		// mit; die gemerkte Stelle gehört zur Seite davor und würde ihn wegschieben.*
		var anker = window.location.hash ? document.getElementById( window.location.hash.slice( 1 ) ) : null;

		if ( anker ) {
			anker.scrollIntoView( { block: 'start' } );

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

		// ⚠️ *Die Zeile, die selbst den Satzlink trägt — nicht eine Zeile einer Tabelle, die in einer Zelle steckt.*
		var zeile = ziel.closest( 'tr' );

		while ( zeile && ! zeile.querySelector( ':scope > * a.taxmod-record-open' ) ) {
			zeile = zeile.parentElement ? zeile.parentElement.closest( 'tr' ) : null;
		}

		if ( ! zeile ) {
			return;
		}

		// ⚠️ *Wer Text markiert, will kopieren, nicht springen.*
		if ( window.getSelection && String( window.getSelection() ).length > 0 ) {
			return;
		}

		window.location.href = zeile.querySelector( ':scope > * a.taxmod-record-open' ).href;
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

		// *Von innen nach aussen, damit ein Vater seine schon entschiedenen Kinder sieht.*
		Array.prototype.slice.call( baum.querySelectorAll( 'details.taxmod-record-branch' ) ).reverse().forEach( function ( ast ) {
			var treffer = ast.querySelector( '.taxmod-record-choice[data-taxmod-search]:not([hidden])' );

			ast.hidden = woerter.length > 0 && ! treffer;

			if ( woerter.length > 0 && treffer ) {
				ast.open = true;
			}
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

	// *Enter in der Suche schickt nichts ab.*
	document.addEventListener( 'keydown', function ( ereignis ) {
		if ( ereignis.key === 'Enter' && ereignis.target instanceof HTMLInputElement && ereignis.target.classList.contains( 'taxmod-record-search' ) ) {
			ereignis.preventDefault();
		}
	} );

} )();
