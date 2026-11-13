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
} )();
