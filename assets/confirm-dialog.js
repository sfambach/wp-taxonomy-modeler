/**
 * The shared confirm dialog (D-804, D-1063, D-1186): a form or button that asks before it acts carries
 * `data-taxmod-confirm="…"` (`ListPage::confirm()`), and this script asks in the same dialog every Taxmod list
 * draws — «Bestätigen» and «Abbrechen» below, ✕ above, a click beside it does not close it. No `window.confirm`.
 *
 * Small on purpose and loaded with the stylesheet on every Taxmod page, so a module page needs nothing else (D-1187).
 */
( function () {
	'use strict';

	var words = window.taxmodConfirmWords || { title: 'Please confirm', ok: 'Confirm', cancel: 'Cancel' };
	var passed = new WeakSet();

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		node.className = className;

		if ( text !== undefined ) {
			node.textContent = text;
		}

		return node;
	}

	/** Opens the dialog with `message`; `onOk` runs after «Bestätigen», nothing after «Abbrechen» or ✕. */
	function ask( message, onOk ) {
		var shell  = element( 'span', 'taxmod-chooser taxmod-confirm' );
		var toggle = document.createElement( 'input' );
		toggle.type = 'checkbox';
		toggle.className = 'taxmod-dialog-switch';
		toggle.checked = true;

		var dialog = element( 'span', 'taxmod-dialog' );
		var panel  = element( 'span', 'taxmod-dialog-panel' );
		var head   = element( 'span', 'taxmod-dialog-head' );
		var close  = element( 'button', 'taxmod-dialog-close taxmod-dialog-cancel', '×' );
		var body   = element( 'span', 'taxmod-dialog-body', message );
		var foot   = element( 'span', 'taxmod-dialog-foot' );
		var ok     = element( 'button', 'button button-primary taxmod-dialog-ok', words.ok );
		var cancel = element( 'button', 'button taxmod-dialog-cancel', words.cancel );

		[ close, ok, cancel ].forEach( function ( button ) {
			button.type = 'button';
		} );
		close.title = words.cancel;
		head.appendChild( element( 'span', 'taxmod-chooser-current', words.title ) );
		head.appendChild( close );
		foot.appendChild( ok );
		foot.appendChild( cancel );
		panel.appendChild( head );
		panel.appendChild( body );
		panel.appendChild( foot );
		dialog.appendChild( element( 'span', 'taxmod-dialog-shade' ) );
		dialog.appendChild( panel );
		shell.appendChild( toggle );
		shell.appendChild( dialog );
		document.body.appendChild( shell );

		function end() {
			shell.remove();
		}

		close.addEventListener( 'click', end );
		cancel.addEventListener( 'click', end );
		ok.addEventListener( 'click', function () {
			end();
			onOk();
		} );
		ok.focus();
	}

	window.taxmodConfirm = ask;

	// A button or link that asks: the click waits for the dialog, then goes on as clicked.
	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target instanceof Element ? event.target.closest( 'button[data-taxmod-confirm], input[type="submit"][data-taxmod-confirm], a[data-taxmod-confirm]' ) : null;

		if ( ! trigger || passed.has( trigger ) ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		ask( trigger.getAttribute( 'data-taxmod-confirm' ), function () {
			passed.add( trigger );
			trigger.click();
			passed.delete( trigger );
		} );
	}, true );

	// A form that asks: its submit waits for the dialog, then submits with the same button.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) || ! form.hasAttribute( 'data-taxmod-confirm' ) || passed.has( form ) ) {
			return;
		}

		var submitter = event.submitter || null;
		event.preventDefault();
		event.stopPropagation();
		ask( form.getAttribute( 'data-taxmod-confirm' ), function () {
			passed.add( form );

			if ( typeof form.requestSubmit === 'function' ) {
				form.requestSubmit( submitter && submitter.form === form ? submitter : undefined );
			} else {
				form.submit();
			}

			passed.delete( form );
		} );
	}, true );
}() );
