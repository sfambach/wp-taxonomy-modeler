// A wide comparison scrolls sideways; the scrollbar at the bottom is easy to miss, so the edge
// that still has columns behind it gets a shade and an arrow.
( function () {
	function mark( wrap, frame ) {
		var left = wrap.scrollLeft > 1;
		var right = wrap.scrollLeft + wrap.clientWidth < wrap.scrollWidth - 1;
		frame.classList.toggle( 'is-more-left', left );
		frame.classList.toggle( 'is-more-right', right );
	}

	function start() {
		document.querySelectorAll( '.taxmod-comparison-wrap' ).forEach( function ( wrap ) {
			var frame = document.createElement( 'div' );
			frame.className = 'taxmod-comparison-frame';
			wrap.parentNode.insertBefore( frame, wrap );
			frame.appendChild( wrap );

			var update = function () { mark( wrap, frame ); };
			wrap.addEventListener( 'scroll', update, { passive: true } );
			window.addEventListener( 'resize', update );
			update();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
