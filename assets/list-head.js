/**
 * The one table head of every Taxmod list (D-1227): above each `table.taxmod-list` a bar with the filter over the whole
 * table, «Group by» a column and the filter row; every column head sorts. It works on the rows the page drew — a list
 * the server sorts, filters or pages (D-1054, D-1147) keeps its own links, this only adds what they lack.
 *
 * A row's details (`tr.taxmod-list-details`) and the rows a module marks `tr.taxmod-list-more` move with the row above
 * them. A cell sorts by its `[data-taxmod-sort]` value when it holds one, else by its text.
 *
 * Loaded by `ListPage::table()`, so a module page needs nothing else (D-1187).
 */
( function () {
	'use strict';

	var words = window.taxmodListHeadWords || {};
	var word = function ( key, fallback ) {
		return words[ key ] || fallback;
	};

	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined ) {
			node.textContent = text;
		}

		return node;
	}

	function load( key ) {
		try {
			return JSON.parse( window.localStorage.getItem( key ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function save( key, state ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( state ) );
		} catch ( e ) {}
	}

	function isMore( row ) {
		if ( row.classList.contains( 'taxmod-list-details' ) || row.classList.contains( 'taxmod-list-more' ) ) {
			return true;
		}

		return ! row.classList.contains( 'no-items' ) && !! row.querySelector( ':scope > td[colspan]:not([colspan="1"])' );
	}

	/** The rows of the body, each with the rows that belong to it. */
	function unitsOf( body ) {
		var units = [];

		Array.prototype.forEach.call( body.rows, function ( row ) {
			if ( row.classList.contains( 'taxmod-list-group' ) || row.classList.contains( 'taxmod-list-prompt' ) || row.classList.contains( 'no-items' ) ) {
				return;
			}

			if ( units.length && isMore( row ) ) {
				units[ units.length - 1 ].rows.push( row );

				return;
			}

			units.push( { rows: [ row ], at: units.length } );
		} );

		return units;
	}

	function cellText( unit, index ) {
		var cell = unit.rows[ 0 ].cells[ index ];

		if ( ! cell ) {
			return '';
		}

		var marked = cell.querySelector( '[data-taxmod-sort]' );

		return ( marked ? marked.getAttribute( 'data-taxmod-sort' ) : cell.textContent ).replace( /\s+/g, ' ' ).trim();
	}

	/** A date (2026-11-07, 07.11.2026, with a time or not) or a number (1.234,5 · 12 Stk · #42) as a number; else null. */
	function asNumber( text ) {
		var m = text.match( /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/ ) || null;

		if ( m ) {
			return Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +( m[ 4 ] || 0 ), +( m[ 5 ] || 0 ) );
		}

		m = text.match( /^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:,?\s+(\d{1,2}):(\d{2}))?/ );

		if ( m ) {
			return Date.UTC( +m[ 3 ], +m[ 2 ] - 1, +m[ 1 ], +( m[ 4 ] || 0 ), +( m[ 5 ] || 0 ) );
		}

		m = text.replace( /^[#€$\s]+/, '' ).match( /^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?(?!\d)|^-?\d+(?:[,.]\d+)?/ );

		return m ? parseFloat( m[ 0 ].replace( /\.(?=\d{3}(?!\d))/g, '' ).replace( ',', '.' ) ) : null;
	}

	function comparer( units, index ) {
		var values = units.map( function ( unit ) {
			return cellText( unit, index );
		} );
		var numbers = values.map( asNumber );
		var numeric = values.every( function ( value, i ) {
			return value === '' || value === '—' || numbers[ i ] !== null;
		} );
		var keyed = new Map();

		units.forEach( function ( unit, i ) {
			keyed.set( unit, { text: values[ i ], number: numbers[ i ] } );
		} );

		return function ( a, b ) {
			var x = keyed.get( a ), y = keyed.get( b );
			var text = x.text.localeCompare( y.text, undefined, { numeric: true, sensitivity: 'base' } );

			if ( numeric ) {
				if ( x.number === null || y.number === null ) {
					return ( x.number === null ) - ( y.number === null ) || text;
				}

				return x.number - y.number || text;
			}

			return text;
		};
	}

	function enhance( table, position ) {
		if ( table.dataset.taxmodHead ) {
			return;
		}

		table.dataset.taxmodHead = '1';

		var head = table.tHead && table.tHead.rows[ 0 ];
		var body = table.tBodies[ 0 ];

		if ( ! head || ! body ) {
			return;
		}

		var span = head.cells.length;
		var columns = [];

		Array.prototype.forEach.call( head.cells, function ( cell, index ) {
			if ( index === 0 || index === span - 1 || cell.classList.contains( 'check-column' ) ) {
				return;
			}

			var label = cell.textContent.replace( ' •', '' ).replace( /\s+/g, ' ' ).trim();

			if ( label !== '' ) {
				columns.push( { index: index, label: label, cell: cell, server: !! cell.querySelector( 'a[href]' ) } );
			}
		} );

		if ( ! columns.length ) {
			return;
		}

		var key = 'taxmod-list:' + location.pathname + ':' + ( new URLSearchParams( location.search ).get( 'page' ) || '' ) + ':'
			+ ( new URLSearchParams( location.search ).get( 'tab' ) || '' ) + ':' + position;
		var state = load( key );
		var all = unitsOf( body );
		var serverFilter = table.tHead.querySelector( '.taxmod-filter-row' );

		// The bar: filter over the whole table, group by, filter row, count.
		var bar = element( 'div', 'taxmod-list-head' );
		var search = element( 'input', 'taxmod-list-head-search' );
		search.type = 'search';
		search.placeholder = word( 'search', 'Filter the whole table …' );
		search.setAttribute( 'aria-label', word( 'search', 'Filter the whole table …' ) );

		var groupLabel = element( 'label', 'taxmod-list-head-group' );
		var group = element( 'select' );
		group.appendChild( new Option( word( 'noGroup', 'No grouping' ), '' ) );
		columns.forEach( function ( column ) {
			group.appendChild( new Option( column.label, String( column.index ) ) );
		} );
		groupLabel.append( word( 'group', 'Group by' ) + ' ', group );

		var count = element( 'span', 'taxmod-list-head-count displaying-num' );
		bar.append( search, groupLabel );

		// A filter row of its own where the server draws none.
		var filters = [];

		if ( ! serverFilter ) {
			var row = element( 'tr', 'taxmod-filter-row taxmod-filter-row-client' );
			row.hidden = true;
			Array.prototype.forEach.call( head.cells, function ( cell, index ) {
				var td = element( 'td' );
				var column = columns.filter( function ( c ) {
					return c.index === index;
				} )[ 0 ];

				if ( column ) {
					var input = element( 'input' );
					input.type = 'search';
					input.setAttribute( 'aria-label', word( 'filterColumn', 'Filter %s' ).replace( '%s', column.label ) );
					input.dataset.column = String( index );
					input.addEventListener( 'input', draw );
					filters.push( input );
					td.appendChild( input );
				}

				row.appendChild( td );
			} );
			head.after( row );

			var toggle = element( 'button', 'button', word( 'filterRow', 'Filter row' ) );
			toggle.type = 'button';
			toggle.addEventListener( 'click', function () {
				row.hidden = ! row.hidden;
				toggle.setAttribute( 'aria-expanded', String( ! row.hidden ) );

				if ( row.hidden ) {
					filters.forEach( function ( input ) {
						input.value = '';
					} );
					draw();
				}
			} );
			toggle.setAttribute( 'aria-expanded', 'false' );
			bar.appendChild( toggle );
		}

		bar.appendChild( count );

		// Every column head sorts: the server's links stay, the others sort here.
		columns.forEach( function ( column ) {
			if ( column.server ) {
				return;
			}

			var cell = column.cell;
			var link = element( 'a' );
			link.href = '#';
			link.append( element( 'span', '', column.label ) );
			var indicators = element( 'span', 'sorting-indicators' );
			indicators.append( element( 'span', 'sorting-indicator asc' ), element( 'span', 'sorting-indicator desc' ) );
			link.append( indicators );
			cell.textContent = '';
			cell.appendChild( link );
			cell.classList.add( 'sortable', 'desc' );
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				state.desc = state.sort === column.index ? ! state.desc : false;
				state.sort = column.index;
				save( key, state );
				draw();
			} );
		} );

		// The foot repeats the head; it keeps its plain labels.
		group.value = state.group !== undefined && columns.some( function ( c ) {
			return String( c.index ) === String( state.group );
		} ) ? String( state.group ) : '';
		group.addEventListener( 'change', function () {
			state.group = group.value === '' ? undefined : +group.value;
			save( key, state );
			draw();
		} );
		search.addEventListener( 'input', draw );

		var collapsed = {};

		function draw() {
			var needle = search.value.trim().toLocaleLowerCase();
			var active = filters.filter( function ( input ) {
				return input.value.trim() !== '';
			} );
			var units = all.slice();

			if ( typeof state.sort === 'number' && columns.some( function ( c ) {
				return c.index === state.sort && ! c.server;
			} ) ) {
				var by = comparer( units, state.sort );
				units.sort( function ( a, b ) {
					return ( state.desc ? -1 : 1 ) * by( a, b ) || a.at - b.at;
				} );
			}

			columns.forEach( function ( column ) {
				if ( column.server ) {
					return;
				}

				var sorted = state.sort === column.index;
				column.cell.classList.toggle( 'sorted', sorted );
				column.cell.classList.toggle( 'sortable', ! sorted );
				column.cell.classList.toggle( 'asc', sorted && ! state.desc );
				column.cell.classList.toggle( 'desc', ! sorted || !! state.desc );
			} );

			var groupBy = group.value === '' ? null : +group.value;

			if ( groupBy !== null ) {
				var byGroup = comparer( units, groupBy );
				units = units.map( function ( unit, i ) {
					return { unit: unit, i: i };
				} ).sort( function ( a, b ) {
					return byGroup( a.unit, b.unit ) || a.i - b.i;
				} ).map( function ( pair ) {
					return pair.unit;
				} );
			}

			body.querySelectorAll( ':scope > tr.taxmod-list-group' ).forEach( function ( row ) {
				row.remove();
			} );

			var shown = 0;
			var current = null;
			var header = null;
			var inGroup = 0;
			var fragment = document.createDocumentFragment();
			var close = function () {
				if ( header ) {
					header.querySelector( '.taxmod-list-group-count' ).textContent = '(' + inGroup + ')';
					header.hidden = inGroup === 0;
				}
			};

			units.forEach( function ( unit ) {
				var text = unit.rows.map( function ( row ) {
					return row.textContent;
				} ).join( ' ' ).toLocaleLowerCase();
				var keep = ( needle === '' || text.indexOf( needle ) !== -1 ) && active.every( function ( input ) {
					return cellText( unit, +input.dataset.column ).toLocaleLowerCase().indexOf( input.value.trim().toLocaleLowerCase() ) !== -1;
				} );

				if ( groupBy !== null ) {
					var value = cellText( unit, groupBy );

					if ( header === null || value !== current ) {
						close();
						current = value;
						inGroup = 0;
						header = element( 'tr', 'taxmod-list-group' );
						var td = element( 'td' );
						td.colSpan = span;
						var button = element( 'button', 'taxmod-list-group-toggle' );
						button.type = 'button';
						button.setAttribute( 'aria-expanded', String( ! collapsed[ value ] ) );
						button.append( element( 'span', 'dashicons dashicons-arrow-down-alt2' ), element( 'strong', '', value === '' ? word( 'empty', '(empty)' ) : value ), ' ', element( 'span', 'taxmod-list-group-count' ) );
						button.addEventListener( 'click', ( function ( name ) {
							return function () {
								collapsed[ name ] = ! collapsed[ name ];
								draw();
							};
						} )( value ) );
						td.appendChild( button );
						header.appendChild( td );
						fragment.appendChild( header );
					}

					if ( keep ) {
						inGroup++;
					}
				}

				var open = keep && ! ( groupBy !== null && collapsed[ current ] );

				unit.rows.forEach( function ( row, i ) {
					if ( i === 0 ) {
						row.hidden = ! open;
					} else if ( ! open ) {
						row.dataset.taxmodWasHidden = row.dataset.taxmodWasHidden || String( row.hidden );
						row.hidden = true;
					} else if ( row.dataset.taxmodWasHidden !== undefined ) {
						row.hidden = row.dataset.taxmodWasHidden === 'true';
						delete row.dataset.taxmodWasHidden;
					}

					fragment.appendChild( row );
				} );

				if ( ! keep ) {
					// A row the filter hides is no longer ticked: a bulk action reaches only what one sees.
					unit.rows[ 0 ].querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( box ) {
						box.checked = false;
					} );
				}

				if ( keep ) {
					shown++;
				}
			} );
			close();

			var prompt = body.querySelector( ':scope > tr.taxmod-list-prompt, :scope > tr.no-items' );
			body.appendChild( fragment );

			if ( prompt ) {
				body.appendChild( prompt );
			}

			table.classList.toggle( 'taxmod-list-grouped', groupBy !== null );
			count.textContent = shown === all.length ? '' : word( 'count', '%1$d of %2$d' ).replace( '%1$d', shown ).replace( '%2$d', all.length );
		}

		// His word 2026-11-08: «können wir die gruppen aktionen rechts neben die gruppierung machen und alle andere aus der zeile
		// drüber auch» — the bar of bulk actions, expand/collapse and count that `ListPage::bulkBar()` draws above the list joins
		// this bar, right of «Group by»; its count stands at the far right.
		var above = table.previousElementSibling;

		while ( above && ( above.tagName === 'STYLE' || above.tagName === 'SCRIPT' ) ) {
			above = above.previousElementSibling;
		}

		var moved = false;

		if ( above && above.classList.contains( 'tablenav' ) && above.classList.contains( 'top' ) ) {
			var actions = element( 'div', 'taxmod-list-head-actions' );
			var total = above.querySelector( '.displaying-num' );

			Array.prototype.slice.call( above.children ).forEach( function ( child ) {
				if ( child.tagName === 'BR' || child.classList.contains( 'tablenav-pages' ) ) {
					return;
				}

				actions.appendChild( child );
			} );
			count.before( actions );

			if ( total ) {
				total.classList.add( 'taxmod-list-head-total' );
				bar.appendChild( total );
			}

			above.remove();
			moved = true;
		}

		if ( all.length < 2 && ! moved ) {
			// Nothing to sort or group by: the head still sorts once rows come, the bar waits.
			bar.hidden = true;
		}

		table.before( bar );
		draw();
	}

	function start( root, prefix ) {
		( root || document ).querySelectorAll( 'table.taxmod-list' ).forEach( function ( table, i ) {
			enhance( table, ( prefix || '' ) + i );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			start();
		} );
	} else {
		start();
	}

	// Details drawn on opening (D-1147) may hold a list of their own.
	document.addEventListener( 'taxmod:details', function ( event ) {
		var source = event.target.dataset ? event.target.dataset.source || '' : '';
		start( event.target, 'details-' + source + ':' );
	} );
}() );
