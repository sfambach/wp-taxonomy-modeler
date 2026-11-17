/**
 * Which rows of a tree show — the one walk for every tree drawn flat in the document (Ladezeit S5, D-1418): the shared node tree
 * (admin.js) and the side tree of the modules (side-tree.js) depend on this script.
 */
( function () {
	'use strict';

	/**
	 * ⚠️ **Welche Zeilen eines Baums zu sehen sind, steht an einer Stelle** (Ladezeit S5, D-1418) — *bisher je einmal hier, im Seitenbaum
	 * der Module (`side-tree.js`) und im Satzbaum.* Der Baum ist flach im Dokument, jede Zeile kennt ihre Tiefe; eine Zeile ist zu sehen,
	 * wenn alle über ihr offen sind. `offen`: true öffnet alles, false schliesst alles, eine Funktion (Zeile, Klapper) sagt es je Zeile —
	 * sie wird für jede Zeile in Reihenfolge gefragt —, ohne Angabe gilt, was der Klapper sagt.
	 */
	function baumZeigen( wurzel, offen ) {
		var stapel = [];

		Array.prototype.forEach.call( wurzel.querySelectorAll( '.taxmod-tree-row' ), function ( zeile ) {
			var tiefe = parseInt( zeile.getAttribute( 'data-depth' ) || '0', 10 );

			while ( stapel.length && stapel[ stapel.length - 1 ].tiefe >= tiefe ) {
				stapel.pop();
			}

			var oben     = stapel[ stapel.length - 1 ];
			var sichtbar = ! oben || ( oben.sichtbar && oben.auf );
			var klapper  = zeile.querySelector( '.taxmod-tree-fold' );
			var gefragt  = typeof offen === 'function' ? offen( zeile, klapper ) : offen;
			var auf      = !! klapper && ( gefragt === true || ( gefragt !== false && klapper.getAttribute( 'data-fold' ) === 'auf' ) );

			zeile.style.display = sichtbar ? 'flex' : 'none';

			if ( klapper ) {
				klapper.setAttribute( 'data-fold', auf ? 'auf' : 'zu' );
				klapper.innerHTML = auf ? '▾' : '▸';
			}

			stapel.push( { tiefe: tiefe, auf: auf, sichtbar: sichtbar } );
		} );
	}

	window.taxmodTreeShow = baumZeigen;
}() );
