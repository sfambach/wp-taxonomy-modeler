/**
 * The live building block (D-1340, Gesamtkonzept A1): a GET form marked `data-taxmod-live="<id>"` does not load the page.
 * Its answer is fetched, and only the element with that id is swapped. His word 2026-11-12: «beim suchen baut die seite
 * dauernd neu auf schon getippt filter verschwinden … sollte entsprechen aufgklappen und nicht neuladen».
 *
 * ```mermaid
 * flowchart LR
 *   S[submit] --> F[fetch same URL] --> P[parse] --> R[swap #id] --> E[taxmod:replaced]
 *   F -- fails --> N[normal page load]
 * ```
 *
 * The server stays the one place that answers; without a script the form loads the page as before.
 */
( function () {
	'use strict';

	var running = {};

	function urlOf( form ) {
		var url    = new URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
		var params = new URLSearchParams();

		url.search = '';
		// FormData sees the controls that name the form with `form="…"` too.
		new FormData( form ).forEach( function ( value, name ) {
			if ( typeof value === 'string' ) {
				params.append( name, value );
			}
		} );
		url.search = params.toString();

		return url.toString();
	}

	/**
	 * ⚠️ **What a person is typing in stays the same element.** The answer arrives after more keys were pressed; swapping
	 * the field would throw those keys, the focus and the caret away — exactly his complaint.
	 */
	function keepTyping( old, fresh ) {
		var active = document.activeElement;

		if ( ! active || ! active.name || ! old.contains( active ) ) {
			return;
		}

		var twin = fresh.querySelector( '[name="' + active.name.replace( /"/g, '\\"' ) + '"]' );

		if ( twin && twin.tagName === active.tagName ) {
			twin.parentNode.replaceChild( active, twin );

			return active;
		}

		return null;
	}

	// Moving an element in the document drops its focus; it gets it back with its caret.
	function refocus( field, start, end ) {
		if ( ! field ) {
			return;
		}

		field.focus();

		try {
			field.setSelectionRange( start, end );
		} catch ( e ) {
			// A field without a caret (a select) has nothing to restore.
		}
	}

	function swap( form, targetId ) {
		var url = urlOf( form );

		if ( running[ targetId ] ) {
			running[ targetId ].abort();
		}

		var control = new AbortController();

		running[ targetId ] = control;

		return window.fetch( url, { credentials: 'same-origin', signal: control.signal } )
			.then( function ( answer ) {
				if ( ! answer.ok ) {
					throw new Error( String( answer.status ) );
				}

				return answer.text();
			} )
			.then( function ( html ) {
				var fresh = new DOMParser().parseFromString( html, 'text/html' ).getElementById( targetId );
				var old   = document.getElementById( targetId );

				if ( ! fresh || ! old ) {
					throw new Error( 'missing ' + targetId );
				}

				var active = document.activeElement;
				var start  = active && typeof active.selectionStart === 'number' ? active.selectionStart : null;
				var end    = active && typeof active.selectionEnd === 'number' ? active.selectionEnd : null;

				fresh = document.importNode( fresh, true );

				var kept = keepTyping( old, fresh );

				old.parentNode.replaceChild( fresh, old );
				refocus( kept, start, end );
				window.history.replaceState( window.history.state, '', url );
				running[ targetId ] = null;
				fresh.dispatchEvent( new CustomEvent( 'taxmod:replaced', { bubbles: true, detail: { url: url } } ) );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				running[ targetId ] = null;
				window.location.href = url;
			} );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.matches || ! form.matches( 'form[data-taxmod-live]' ) ) {
			return;
		}

		if ( ( form.getAttribute( 'method' ) || 'get' ).toLowerCase() !== 'get' || ! window.fetch || ! window.DOMParser ) {
			return;
		}

		var targetId = form.getAttribute( 'data-taxmod-live' );

		if ( ! targetId || ! document.getElementById( targetId ) ) {
			return;
		}

		event.preventDefault();
		swap( form, targetId );
	} );

	/* ------------------------------------------------------------------------------------------------------------------
	 * D-1408: the projection (event concept, docs/pakete/ereignisse, «Kern → Fläche: dann gilt jetzt das»). An act no longer
	 * loads the page: its form is sent in the background, the server answers as before (act → redirect → page), and only what
	 * differs is put in place — row by row, so scroll and focus stay. Saving in a dialog says `taxmod:saved`; when the dialog
	 * closes the page behind it is brought up to date the same way. The server stays the one that draws (D-623).
	 *
	 * ```mermaid
	 * flowchart LR
	 *   A[act / save] --> F[fetch] --> P[page answer] --> M[morph .wrap] --> E[taxmod:replaced]
	 *   F -- other page --> N[navigate]
	 *   F -- file --> D[download]
	 *   F -- fails --> R[normal submit]
	 * ```
	 * ---------------------------------------------------------------------------------------------------------------- */

	var KEEP = '.taxmod-messages, [data-taxmod-keep]';

	function pageOf( url ) {
		return new URL( url, window.location.href ).searchParams.get( 'page' ) || '';
	}

	function wrapIn( root ) {
		return root.querySelector( '#wpbody-content .wrap' ) || root.querySelector( '.wrap' );
	}

	// *Server state wins for a field nobody is typing in: a value attribute alone does not move a field the person touched.*
	function syncField( old, fresh ) {
		if ( old === document.activeElement ) {
			return;
		}

		if ( old.tagName === 'INPUT' && ( old.type === 'checkbox' || old.type === 'radio' ) ) {
			old.checked = fresh.hasAttribute( 'checked' );
		} else if ( old.tagName === 'INPUT' && old.type !== 'file' ) {
			old.value = fresh.getAttribute( 'value' ) || '';
		} else if ( old.tagName === 'TEXTAREA' ) {
			old.value = fresh.textContent;
		}
	}

	function syncAttributes( old, fresh ) {
		var i;

		for ( i = old.attributes.length - 1; i >= 0; i-- ) {
			if ( ! fresh.hasAttribute( old.attributes[ i ].name ) ) {
				old.removeAttribute( old.attributes[ i ].name );
			}
		}

		for ( i = 0; i < fresh.attributes.length; i++ ) {
			if ( old.getAttribute( fresh.attributes[ i ].name ) !== fresh.attributes[ i ].value ) {
				old.setAttribute( fresh.attributes[ i ].name, fresh.attributes[ i ].value );
			}
		}
	}

	function matches( old, fresh ) {
		if ( old.nodeType !== fresh.nodeType || old.nodeName !== fresh.nodeName ) {
			return false;
		}

		return old.nodeType !== 1 || ( old.id || '' ) === ( fresh.id || '' );
	}

	/**
	 * The children of `old` become those of `fresh`: a child with the same id, else the next one of the same kind, is kept and
	 * brought up to date; what is new is inserted, what is gone is removed.
	 */
	function morphChildren( old, fresh ) {
		var pool = Array.prototype.slice.call( old.childNodes );
		var used = [];
		var next = 0;

		Array.prototype.slice.call( fresh.childNodes ).forEach( function ( child, at ) {
			var found = -1;
			var k;

			if ( child.nodeType === 1 && child.id ) {
				for ( k = 0; k < pool.length; k++ ) {
					if ( ! used[ k ] && pool[ k ].nodeType === 1 && pool[ k ].id === child.id ) {
						found = k;
						break;
					}
				}
			} else {
				for ( k = next; k < pool.length; k++ ) {
					if ( ! used[ k ] && matches( pool[ k ], child ) ) {
						found = k;
						break;
					}
				}
			}

			var node;

			if ( found >= 0 ) {
				used[ found ] = true;
				next = Math.max( next, found + 1 );
				node = morph( pool[ found ], child );
			} else {
				node = document.importNode( child, true );
			}

			if ( old.childNodes[ at ] !== node ) {
				old.insertBefore( node, old.childNodes[ at ] || null );
			}
		} );

		pool.forEach( function ( child, k ) {
			if ( ! used[ k ] && child.parentNode === old && ! ( child.nodeType === 1 && child.matches( KEEP ) ) ) {
				old.removeChild( child );
			}
		} );
	}

	/** Puts `fresh` in place of `old` only where they differ; equal parts stay the same elements. Answers the node now in place. */
	function morph( old, fresh ) {
		if ( old.isEqualNode( fresh ) || ( old.nodeType === 1 && old.matches( KEEP ) ) ) {
			return old;
		}

		if ( old.nodeType !== 1 ) {
			if ( old.nodeType === fresh.nodeType ) {
				old.nodeValue = fresh.nodeValue;

				return old;
			}

			var other = document.importNode( fresh, true );

			old.parentNode.replaceChild( other, old );

			return other;
		}

		if ( ! matches( old, fresh ) || old.tagName === 'SCRIPT' || old.tagName === 'IFRAME' ) {
			if ( old === document.activeElement ) {
				return old;
			}

			var copy = document.importNode( fresh, true );

			old.parentNode.replaceChild( copy, old );

			return copy;
		}

		syncAttributes( old, fresh );

		if ( old.tagName === 'INPUT' || old.tagName === 'TEXTAREA' ) {
			syncField( old, fresh );

			return old;
		}

		morphChildren( old, fresh );

		if ( old.tagName === 'SELECT' && old !== document.activeElement ) {
			var chosen = fresh.querySelector( 'option[selected]' );

			old.value = chosen ? chosen.value : ( old.options[ 0 ] ? old.options[ 0 ].value : '' );
		}

		return old;
	}

	function place( html, url ) {
		var doc   = new DOMParser().parseFromString( html, 'text/html' );
		var fresh = wrapIn( doc );
		var old   = wrapIn( document );

		if ( ! fresh || ! old ) {
			// *An answer without a page (an error page of WordPress) is shown as it is.*
			document.open();
			document.write( html );
			document.close();

			return;
		}

		old = morph( old, fresh );

		if ( doc.title ) {
			document.title = doc.title;
		}

		if ( url ) {
			window.history.replaceState( window.history.state, '', url );
		}

		old.dispatchEvent( new CustomEvent( 'taxmod:replaced', { bubbles: true, detail: { url: url || window.location.href } } ) );
	}

	var refreshing = null;

	/** The page as the server draws it now, put in place. */
	function refresh() {
		if ( ! window.fetch || ! window.DOMParser || ! wrapIn( document ) ) {
			return;
		}

		if ( refreshing ) {
			refreshing.abort();
		}

		refreshing = new AbortController();

		window.fetch( window.location.href, { credentials: 'same-origin', signal: refreshing.signal } )
			.then( function ( answer ) {
				return answer.ok ? answer.text() : null;
			} )
			.then( function ( html ) {
				refreshing = null;

				if ( html ) {
					place( html, '' );
				}
			} )
			.catch( function () {
				refreshing = null;
			} );
	}

	window.taxmodRefresh = refresh;

	function fileName( answer ) {
		var found = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec( answer.headers.get( 'Content-Disposition' ) || '' );

		return found ? decodeURIComponent( found[ 1 ] ) : 'download';
	}

	function download( answer ) {
		return answer.blob().then( function ( blob ) {
			var link = document.createElement( 'a' );

			link.href     = URL.createObjectURL( blob );
			link.download = fileName( answer );
			document.body.appendChild( link );
			link.click();
			link.parentNode.removeChild( link );
			setTimeout( function () {
				URL.revokeObjectURL( link.href );
			}, 1000 );
		} );
	}

	function act( form, submitter ) {
		var method = ( form.getAttribute( 'method' ) || 'get' ).toLowerCase();
		var target = new URL( ( submitter && submitter.getAttribute( 'formaction' ) ) || form.getAttribute( 'action' ) || window.location.href, window.location.href );
		var body   = new FormData( form );
		var init   = { credentials: 'same-origin', method: method === 'post' ? 'POST' : 'GET' };

		if ( submitter && submitter.name ) {
			body.append( submitter.name, submitter.value );
		}

		if ( method === 'post' ) {
			init.body = body;
		} else {
			target.search = '';
			body.forEach( function ( value, name ) {
				if ( typeof value === 'string' ) {
					target.searchParams.append( name, value );
				}
			} );
		}

		form.setAttribute( 'aria-busy', 'true' );

		return window.fetch( target.toString(), init ).then( function ( answer ) {
			form.removeAttribute( 'aria-busy' );

			var type = answer.headers.get( 'Content-Type' ) || '';

			if ( type.indexOf( 'text/html' ) === -1 ) {
				return download( answer );
			}

			// *Another page is another view with its own scripts: go there, as a link would (a GET, nothing is sent twice).*
			if ( pageOf( answer.url ) !== pageOf( window.location.href ) ) {
				window.location.href = answer.url;

				return null;
			}

			return answer.text().then( function ( html ) {
				place( html, answer.url );
			} );
		} );
	}

	function liveAct( form, submitter ) {
		if ( ! form.closest || ! form.closest( '.wrap' ) || form.hasAttribute( 'data-taxmod-live' ) || form.hasAttribute( 'data-taxmod-reload' ) ) {
			return false;
		}

		if ( ( form.getAttribute( 'target' ) || '' ) !== '' || ( submitter && ( submitter.getAttribute( 'formtarget' ) || '' ) !== '' ) ) {
			return false;
		}

		var action = new URL( ( submitter && submitter.getAttribute( 'formaction' ) ) || form.getAttribute( 'action' ) || window.location.href, window.location.href );

		// *Only acts of this admin; a form to another site or the login loads as before.*
		if ( action.origin !== window.location.origin || action.pathname.indexOf( '/wp-admin/' ) === -1 ) {
			return false;
		}

		return true;
	}

	// *On window: every listener of the document (confirm dialog, quiet save) has spoken before this one decides.*
	window.addEventListener( 'submit', function ( event ) {
		var form      = event.target;
		var submitter = event.submitter || null;

		if ( event.defaultPrevented || ! window.fetch || ! window.DOMParser || ! form || ! liveAct( form, submitter ) ) {
			return;
		}

		event.preventDefault();

		act( form, submitter ).catch( function () {
			form.removeAttribute( 'aria-busy' );
			form.setAttribute( 'data-taxmod-reload', '' );

			if ( submitter && form.requestSubmit ) {
				form.requestSubmit( submitter );
			} else {
				form.submit();
			}
		} );
	} );

	// D-1408: a save in a dialog (also in the page a view dialog shows) leaves the page behind it out of date until it closes.
	var stale = false;

	document.addEventListener( 'taxmod:saved', function ( event ) {
		var inDialog = event.detail && event.detail.form && event.detail.form.closest && event.detail.form.closest( '.taxmod-dialog' );

		// *A save on the page itself shows its own value already; refreshing there could overwrite what is still being typed.*
		if ( inDialog ) {
			stale = true;
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var sw = event.target;

		if ( stale && sw.classList && sw.classList.contains( 'taxmod-dialog-switch' ) && ! sw.checked ) {
			stale = false;
			refresh();
		}
	} );

	// *A page shown in a view dialog tells its opener; the frame is the same site, so its document is reachable.*
	function tellOpener() {
		try {
			if ( window.parent && window.parent !== window && window.parent.document ) {
				window.parent.document.dispatchEvent( new CustomEvent( 'taxmod:frame-saved' ) );
			}
		} catch ( e ) {
			// Another origin: nothing to tell.
		}
	}

	document.addEventListener( 'taxmod:saved', tellOpener );
	document.addEventListener( 'taxmod:replaced', tellOpener );

	document.addEventListener( 'taxmod:frame-saved', function () {
		stale = true;
	} );

	// *An act inside the frame that loads its page again is a change too: every load after the first marks the page behind.*
	document.addEventListener( 'load', function ( event ) {
		var frame = event.target;

		if ( ! frame || frame.tagName !== 'IFRAME' || ! frame.classList.contains( 'taxmod-view-dialog-frame' ) ) {
			return;
		}

		if ( frame.getAttribute( 'data-taxmod-loaded' ) ) {
			stale = true;
		}

		frame.setAttribute( 'data-taxmod-loaded', '1' );
	}, true );
} )();
