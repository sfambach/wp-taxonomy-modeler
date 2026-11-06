/**
 * The message field beside the heading (D-1203): every Taxmod page says what just happened in the same place, without a
 * reload, and keeps the last messages to look back at. `window.taxmodMessage( text, tone )` or the event `taxmod:message`
 * write to it — tone `ok`,
 * `bad` or `info`; notices WordPress drew on a reloaded page are moved into it as well. A page without the field keeps
 * the old toast top right.
 *
 * Loaded with the stylesheet on every Taxmod page (D-1187), like the confirm dialog.
 */
( function () {
	'use strict';

	var KEY = 'taxmodMessages';
	var KEEP = 30;
	var words = window.taxmodMessageWords || { none: 'No messages yet.', recent: 'Recent messages' };

	function history() {
		try {
			var stored = JSON.parse( window.localStorage.getItem( KEY ) || '[]' );

			return Array.isArray( stored ) ? stored : [];
		} catch ( e ) {
			return [];
		}
	}

	function remember( entry ) {
		var all = [ entry ].concat( history() ).slice( 0, KEEP );

		try {
			window.localStorage.setItem( KEY, JSON.stringify( all ) );
		} catch ( e ) {
			// A browser that stores nothing still shows the message; only the list stays short.
		}

		return all;
	}

	function clock( time ) {
		var d = new Date( time );

		return ( '0' + d.getHours() ).slice( -2 ) + ':' + ( '0' + d.getMinutes() ).slice( -2 );
	}

	function fill( field, all ) {
		var last = field.querySelector( '.taxmod-messages-last' );
		var list = field.querySelector( '.taxmod-messages-list' );

		list.textContent = '';

		if ( all.length === 0 ) {
			var empty = document.createElement( 'li' );
			empty.textContent = words.none;
			list.appendChild( empty );

			return;
		}

		all.forEach( function ( entry ) {
			var item = document.createElement( 'li' );
			item.className = 'is-' + entry.tone;
			item.textContent = clock( entry.time ) + '  ' + entry.text;
			list.appendChild( item );
		} );

		return last;
	}

	function toast( text, tone ) {
		document.querySelectorAll( '.taxmod-toast' ).forEach( function ( old ) {
			old.parentNode.removeChild( old );
		} );

		var node = document.createElement( 'div' );
		node.className = 'taxmod-toast ' + ( tone === 'bad' ? 'taxmod-toast-bad' : 'taxmod-toast-ok' );
		node.setAttribute( 'role', 'status' );
		node.textContent = text;
		document.body.appendChild( node );
	}

	function say( text, tone ) {
		text = String( text || '' ).trim();
		tone = tone === 'bad' || tone === 'info' ? tone : 'ok';

		if ( text === '' ) {
			return;
		}

		var field = document.querySelector( '.taxmod-messages' );

		if ( ! field ) {
			toast( text, tone );

			return;
		}

		var all = remember( { text: text, tone: tone, time: Date.now() } );
		var last = field.querySelector( '.taxmod-messages-last' );

		last.textContent = text;
		last.title = text;
		field.className = 'taxmod-messages is-' + tone + ' is-fresh';
		fill( field, all );

		// The colour fades, the words stay until the next message.
		window.setTimeout( function () {
			field.classList.remove( 'is-fresh' );
		}, 4000 );
	}

	window.taxmodMessage = say;

	// The shared contract for acts in the background (D-1203): `taxmod:message` with {text, ok} or {text, tone}; the field
	// takes it and cancels the event, so the sender knows not to fall back on its own toast.
	document.addEventListener( 'taxmod:message', function ( event ) {
		var detail = event.detail || {};

		if ( ! document.querySelector( '.taxmod-messages' ) ) {
			return;
		}

		event.preventDefault();
		say( detail.text, detail.tone || ( detail.ok === false ? 'bad' : 'ok' ) );
	} );

	// A notice WordPress drew after a reload carries no buttons of its own: its words move into the field.
	function gather( field ) {
		document.querySelectorAll( '.wrap .notice, .taxmod-toast' ).forEach( function ( notice ) {
			if ( notice.closest( '.taxmod-dialog' ) || notice.classList.contains( 'inline' ) || notice.querySelector( 'a, button, form, input, select' ) ) {
				return;
			}

			var tone = notice.classList.contains( 'notice-error' ) || notice.classList.contains( 'taxmod-toast-bad' ) ? 'bad'
				: ( notice.classList.contains( 'notice-success' ) || notice.classList.contains( 'taxmod-toast-ok' ) ? 'ok' : 'info' );

			say( notice.textContent, tone );
			notice.parentNode.removeChild( notice );
		} );

		fill( field, history() );
	}

	function start() {
		var field = document.querySelector( '.taxmod-messages' );

		if ( ! field ) {
			return;
		}

		var opener = field.querySelector( '.taxmod-messages-last' );
		var list = field.querySelector( '.taxmod-messages-list' );

		opener.setAttribute( 'aria-label', words.recent );
		opener.addEventListener( 'click', function () {
			var open = list.hidden;
			list.hidden = ! open;
			opener.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! field.contains( event.target ) ) {
				list.hidden = true;
				opener.setAttribute( 'aria-expanded', 'false' );
			}
		} );

		gather( field );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
