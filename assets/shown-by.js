/**
 * D-1311: a part with data-taxmod-shown-by="<field>" data-taxmod-shown-for="<value>" shows only while that field of its form
 * has that value; its own fields are disabled while hidden, so they are not sent. Without the script every part shows.
 */
( function () {
	function value( form, name ) {
		var checked = form.querySelector( '[name="' + name + '"]:checked' );
		var field = checked || form.querySelector( 'select[name="' + name + '"], input[name="' + name + '"]:not([type=radio]):not([type=checkbox])' );
		return field ? field.value : '';
	}

	function apply( root ) {
		root.querySelectorAll( '[data-taxmod-shown-by]' ).forEach( function ( part ) {
			var form = part.closest( 'form' ) || document;
			var shown = value( form, part.getAttribute( 'data-taxmod-shown-by' ) ) === part.getAttribute( 'data-taxmod-shown-for' );
			part.hidden = ! shown;
			part.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( field ) {
				field.disabled = ! shown;
			} );
		} );
	}

	document.addEventListener( 'change', function () {
		apply( document );
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		apply( document );
	} );
} )();
