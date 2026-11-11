/**
 * D-1309: the page of a view dialog loads only when its dialog opens (ListPage::viewDialog).
 */
document.addEventListener( 'change', function ( event ) {
	var sw = event.target;

	if ( ! sw.classList || ! sw.classList.contains( 'taxmod-dialog-switch' ) || ! sw.checked ) {
		return;
	}

	var frame = sw.parentNode.querySelector( '.taxmod-view-dialog-frame[data-src]' );

	if ( frame && ! frame.getAttribute( 'src' ) ) {
		frame.setAttribute( 'src', frame.getAttribute( 'data-src' ) );
	}
} );
