/**
 * D-1311: a part with data-taxmod-shown-by="<field>" data-taxmod-shown-for="<value>" shows only while that field of its form
 * has that value; its own fields are disabled while hidden, so they are not sent. Without the script every part shows.
 */
( function () {
	function value( form, name ) {
		// A field may stand outside its form and belong to it by its form attribute (a chooser tree beside the dialog's form).
		var fields = form.elements ? Array.prototype.filter.call( form.elements, function ( field ) {
			return field.name === name;
		} ) : Array.prototype.slice.call( form.querySelectorAll( '[name="' + name + '"]' ) );
		var checked = fields.filter( function ( field ) {
			return ( field.type === 'radio' || field.type === 'checkbox' ) && field.checked;
		} )[ 0 ];
		var field = checked || fields.filter( function ( one ) {
			return one.tagName === 'SELECT' || ( one.tagName === 'INPUT' && one.type !== 'radio' && one.type !== 'checkbox' );
		} )[ 0 ];
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
	// A record picked from the quick search is checked by script, which fires no «change».
	document.addEventListener( 'taxmod-record-picked', function () {
		apply( document );
	} );
	document.addEventListener( 'DOMContentLoaded', function () {
		apply( document );
	} );
} )();
