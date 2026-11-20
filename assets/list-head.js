/**
 * The one table head of every Taxmod list (D-1227, D-1462): every column head sorts, «Group by» stands in the menu «Columns»,
 * and the search and the filter row narrow the list. The bar itself is the server's — bulk actions, filters, «Filter row»,
 * «Columns» in one row, the search on the right (D-1462 replaces the part of D-1240 in which this script rebuilt it).
 *
 * One field, one meaning: where the server marked its answer complete (`data-taxmod-complete`, every hit on this page) and a
 * new question only narrows it, the drawn rows are narrowed and sorted here, at once; otherwise the server is asked, after a
 * short pause, without loading the page (`window.taxmodShow`, D-1408). Either way the address holds the question.
 *
 * ```mermaid
 * flowchart LR
 *   T[typing · Enter · Filter] --> N{complete and narrows?}
 *   N -- yes --> D[narrow the drawn rows] --> A[replaceState]
 *   N -- no --> S[ask the server] --> P[page put in place] --> H[head again]
 * ```
 *
 * A row's details (`tr.taxmod-list-details`) and the rows a module marks `tr.taxmod-list-more` move with the row above
 * them. A cell sorts by its `[data-taxmod-sort]` value when it holds one, else by its text; a row's `data-taxmod-text` is what
 * the server searched in it, its cells split by U+001F.
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

	/**
	 * D-1378: the one spelling a search compares, as `Wildcard::canonical()` — «µ» is «u», «Ω» is «ohm», no space between a number
	 * and what follows, a decimal comma is a point. Change both or neither.
	 */
	function canonical( text ) {
		return text
			.toLocaleLowerCase()
			.replace( /[\u00B5\u03BC]/g, 'u' )
			.replace( /[\u2126\u03A9\u03C9]/g, 'ohm' )
			.replace( /(\d)[\s\u00A0\u2009\u202F]+(?=[^\d\s])/g, '$1' )
			.replace( /(\d),(?=\d)/g, '$1.' );
	}

	/**
	 * D-1357: «contains», ignoring case, «*» for any characters — the same rule as `src/Core/Listing/Wildcard.php`, which the
	 * server uses; change both or neither.
	 */
	function matches( text, term ) {
		var at = 0;
		var haystack = canonical( text );

		return term.trim().split( '*' ).every( function ( part ) {
			part = canonical( part );

			if ( part === '' ) {
				return true;
			}

			var found = haystack.indexOf( part, at );

			if ( found === -1 ) {
				return false;
			}

			at = found + part.length;

			return true;
		} );
	}

	/**
	 * D-1357: the page's own search (`ListPage::searchBox()`, a form with `s`) for a list on the page itself, not in a row's
	 * details; null where there is none. His word 2026-11-14: «Die Filterzeile oben … scheint nicht zu funktionieren» — a
	 * list that waits for a search (D-1147) or pages has rows the bar cannot see, so its term goes to the server.
	 */
	function serverSearchOf( table, position ) {
		if ( String( position ) !== '0' ) {
			return null;
		}

		var field = ( table.closest( '.wrap' ) || document ).querySelector( 'p.search-box input[name="s"]' );

		return field && field.form ? field : null;
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

	/**
	 * D-1462: the one rule for a column filter, as `FilterTerm` in the core — a number column also reads `2`, `>2`, `<2`, `>=2`,
	 * `<=2` and `2-10`; anything else is «contains». Change both or neither (`list-head-check` runs the same cases through both).
	 */
	var NUMBER = '-?\\d+(?:[.,]\\d+)?';

	function decimal( text ) {
		return parseFloat( text.replace( ',', '.' ) );
	}

	function rangeOf( term ) {
		term = term.trim();

		var m = term.match( new RegExp( '^(' + NUMBER + ')\\s*(?:-|–|\\.\\.)\\s*(' + NUMBER + ')$' ) );

		if ( m ) {
			return [ '-', decimal( m[ 1 ] ), decimal( m[ 2 ] ) ];
		}

		m = term.match( new RegExp( '^(>=|<=|>|<|=)?\\s*(' + NUMBER + ')$' ) );

		return m ? [ m[ 1 ] || '=', decimal( m[ 2 ] ), decimal( m[ 2 ] ) ] : null;
	}

	function numberOf( text ) {
		var m = text.trim().replace( /^[#€$\s]+/, '' ).match( /^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?(?!\d)|^-?\d+(?:[,.]\d+)?/ );

		return m ? parseFloat( m[ 0 ].replace( /\.(?=\d{3}(?!\d))/g, '' ).replace( ',', '.' ) ) : null;
	}

	function filterMatches( text, term, number ) {
		term = term.trim();

		if ( term === '' ) {
			return true;
		}

		var range = number ? rangeOf( term ) : null;

		if ( ! range ) {
			return matches( text, term );
		}

		var value = numberOf( text );

		if ( value === null ) {
			return false;
		}

		switch ( range[ 0 ] ) {
			case '>': return value > range[ 1 ];
			case '<': return value < range[ 1 ];
			case '>=': return value >= range[ 1 ];
			case '<=': return value <= range[ 1 ];
			case '-': return value >= Math.min( range[ 1 ], range[ 2 ] ) && value <= Math.max( range[ 1 ], range[ 2 ] );
			default: return value === range[ 1 ];
		}
	}

	/** The search form's question as name → value; the controls that join it with `form=` count. */
	function questionOf( form ) {
		var question = {};

		new FormData( form ).forEach( function ( value, name ) {
			if ( typeof value === 'string' && name !== 'paged' && name !== 'orderby' && name !== 'order' ) {
				question[ name ] = value.trim();
			}
		} );

		return question;
	}

	/**
	 * D-1462: whether the rows the server drew answer `now` as well — the server marked them complete (every hit stands on this
	 * page) and `now` only narrows what they answered: the same choices, a search and text filters that hold the old term, a
	 * number filter set where none was. Anything else is the server's.
	 */
	function narrows( then, now, numbers ) {
		var names = Object.keys( then ).concat( Object.keys( now ) );

		return names.every( function ( name ) {
			var old = then[ name ] || '';
			var fresh = now[ name ] || '';

			if ( old === fresh || old === '' && ( name === 's' || /^f\[/.test( name ) ) ) {
				return true;
			}

			if ( name === 's' || ( /^f\[/.test( name ) && ! numbers[ name ] ) ) {
				return canonical( fresh ).indexOf( canonical( old ) ) !== -1;
			}

			return false;
		} );
	}

	function precedingNav( table ) {
		var above = table.previousElementSibling;

		while ( above && ( above.tagName === 'STYLE' || above.tagName === 'SCRIPT' ) ) {
			above = above.previousElementSibling;
		}

		return above && above.classList.contains( 'tablenav' ) && above.classList.contains( 'top' ) ? above : null;
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
				var link = cell.querySelector( 'a[href]' );
				columns.push( { index: index, label: label, cell: cell, server: !! link, key: link ? new URL( link.href, location.href ).searchParams.get( 'orderby' ) || '' : '' } );
			}
		} );

		if ( ! columns.length ) {
			return;
		}

		var key = 'taxmod-list:' + location.pathname + ':' + ( new URLSearchParams( location.search ).get( 'page' ) || '' ) + ':'
			+ ( new URLSearchParams( location.search ).get( 'tab' ) || '' ) + ':' + position;
		var state = load( key );
		var all = unitsOf( body );
		var serverFilter = table.tHead.querySelector( '.taxmod-filter-row:not(.taxmod-filter-row-client)' );
		var server = serverSearchOf( table, position );
		var form = server ? server.form : null;
		// D-1462: every hit of the server's answer stands on this page, so narrowing and sorting it needs no server.
		var complete = table.hasAttribute( 'data-taxmod-complete' );
		var then = form ? questionOf( form ) : {};
		var nav = precedingNav( table );
		var numbers = {};
		var filters = [];
		var search = server;

		// The server's filter row: each box filters its column; a number box by number.
		if ( serverFilter ) {
			Array.prototype.forEach.call( serverFilter.querySelectorAll( 'input[name^="f["]' ), function ( input ) {
				input.dataset.column = String( input.closest( 'td' ).cellIndex );
				numbers[ input.name ] = input.hasAttribute( 'data-taxmod-number' );
				filters.push( input );
			} );
		}

		// Without a search of the server's, one of the head's own, standing where the server's stands: right, above the bar.
		if ( ! server ) {
			var box = element( 'p', 'search-box taxmod-list-head-search-box' );
			search = element( 'input', 'taxmod-list-head-search' );
			search.type = 'search';
			search.placeholder = word( 'search', 'Filter the whole table …' );
			search.setAttribute( 'aria-label', word( 'search', 'Filter the whole table …' ) );
			search.addEventListener( 'input', draw );
			box.appendChild( search );

			if ( nav ) {
				// In the bar's row, on the right, before the count (D-1462).
				( nav.querySelector( '.tablenav-pages' ) || nav.lastElementChild ).before( box );
			} else {
				table.before( box );
			}
		}

		// A filter row of its own where the server draws none.
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
		}

		// D-1462 (his choice B): «Group by» stands in the menu «Columns», so the bar keeps one row.
		var groupLabel = element( 'label', 'taxmod-list-head-group' );
		var group = element( 'select' );
		group.appendChild( new Option( word( 'noGroup', 'No grouping' ), '' ) );
		columns.forEach( function ( column ) {
			group.appendChild( new Option( column.label, String( column.index ) ) );
		} );
		groupLabel.append( word( 'group', 'Group by' ) + ' ', group );

		var bar = null;
		var menu = nav ? nav.querySelector( '.taxmod-columns form' ) : null;
		var actions = nav ? null : element( 'div', 'alignleft actions' );

		if ( menu ) {
			groupLabel.style.display = 'block';
			groupLabel.style.marginBottom = '8px';
			menu.prepend( groupLabel );
		} else if ( nav ) {
			// Architecture gate 19.11.: in the bar the word is not left standing beside the choice — it goes into the choice itself.
			groupLabel.textContent = '';
			group.title = word( 'group', 'Group by' );
			group.setAttribute( 'aria-label', group.title );
			Array.prototype.forEach.call( group.options, function ( option ) {
				option.textContent = option.value === '' ? option.textContent : group.title + ': ' + option.textContent;
			} );
			groupLabel.appendChild( group );
			actions = element( 'div', 'alignleft actions' );
			actions.appendChild( groupLabel );
			( nav.querySelector( '.tablenav-pages' ) || nav.lastElementChild ).before( actions );
		} else {
			bar = element( 'div', 'tablenav top taxmod-list-head' );
			bar.appendChild( actions );
			actions.appendChild( groupLabel );
		}

		if ( ! serverFilter ) {
			if ( ! actions ) {
				actions = element( 'div', 'alignleft actions' );
				( nav.querySelector( '.tablenav-pages' ) || nav.lastElementChild ).before( actions );
			}

			// D-1500: the same icon square as the server's filter switch (ListPage::filterToggle), the word as tooltip.
			var toggle = element( 'button', 'button taxmod-square' );
			toggle.type = 'button';
			toggle.title = word( 'filterRow', 'Filter row' );
			toggle.setAttribute( 'aria-label', toggle.title );
			toggle.appendChild( element( 'span', 'dashicons dashicons-filter' ) );
			toggle.addEventListener( 'click', function () {
				var own = table.tHead.querySelector( '.taxmod-filter-row-client' );
				own.hidden = ! own.hidden;
				toggle.setAttribute( 'aria-expanded', String( ! own.hidden ) );

				if ( own.hidden ) {
					filters.forEach( function ( input ) {
						input.value = '';
					} );
					draw();
				}
			} );
			toggle.setAttribute( 'aria-expanded', 'false' );
			actions.appendChild( toggle );
		}

		var total = nav ? nav.querySelector( '.displaying-num' ) : null;
		var count = total || element( 'span', 'taxmod-list-head-count displaying-num' );
		var said = count.textContent;

		if ( bar ) {
			var pages = element( 'div', 'tablenav-pages one-page' );
			pages.appendChild( count );
			bar.append( pages, element( 'br', 'clear' ) );
		} else if ( ! total ) {
			nav.appendChild( count );
		}

		// Every column head sorts: on the page, or — where the server sorts and its answer is not all here — by the server.
		columns.forEach( function ( column ) {
			var cell = column.cell;

			if ( column.server ) {
				if ( cell.classList.contains( 'sorted' ) ) {
					state.sort = column.index;
					state.desc = cell.classList.contains( 'desc' );
					state.fromServer = true;
				}

				// A property, not a listener: a head put in place again keeps this link, and one click asks once.
				cell.querySelector( 'a[href]' ).onclick = function ( event ) {
					event.preventDefault();

					// Not all here: the server sorts, and the page is put in place without loading (D-1408).
					if ( ! complete ) {
						if ( window.taxmodShow ) {
							window.taxmodShow( this.href );
						} else {
							location.href = this.href;
						}

						return;
					}

					sortBy( column );
				};

				return;
			}

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
				sortBy( column );
			} );
		} );

		function sortBy( column ) {
			state.desc = state.sort === column.index ? ! state.desc : false;
			state.sort = column.index;
			state.fromServer = false;

			if ( ! column.server ) {
				save( key, { sort: state.sort, desc: state.desc, group: state.group } );
			}

			draw();
			remember();
		}

		group.value = state.group !== undefined && columns.some( function ( c ) {
			return String( c.index ) === String( state.group );
		} ) ? String( state.group ) : '';
		group.addEventListener( 'change', function () {
			state.group = group.value === '' ? undefined : +group.value;
			save( key, { sort: state.fromServer ? undefined : state.sort, desc: state.desc, group: state.group } );
			draw();
		} );

		/** D-1462: what the page shows stands in its address, so a reload or «back» brings it again — without loading (D-1408). */
		function remember() {
			if ( ! form ) {
				return;
			}

			var url = new URL( location.href );
			var question = questionOf( form );

			Object.keys( question ).forEach( function ( name ) {
				if ( name === 's' || /^f\[/.test( name ) ) {
					if ( question[ name ] === '' ) {
						url.searchParams.delete( name );
					} else {
						url.searchParams.set( name, question[ name ] );
					}
				}
			} );

			var sorted = columns.filter( function ( c ) {
				return c.index === state.sort && c.server;
			} )[ 0 ];

			if ( sorted && ! state.fromServer ) {
				url.searchParams.set( 'orderby', sorted.key );
				url.searchParams.set( 'order', state.desc ? 'desc' : 'asc' );
			}

			url.searchParams.delete( 'paged' );
			history.replaceState( history.state, '', url.toString() );
		}

		var waiting = null;

		/** One field, one meaning (D-1462): the same answer whether the page narrows it or the server is asked. */
		function changed( now ) {
			clearTimeout( waiting );

			if ( complete && narrows( then, questionOf( form ), numbers ) ) {
				draw();
				remember();

				return;
			}

			var ask = function () {
				var url = new URL( form.getAttribute( 'action' ) || location.href, location.href );
				var query = new URLSearchParams();

				// An empty box asks nothing; it stays out of the address.
				new FormData( form ).forEach( function ( value, name ) {
					if ( typeof value === 'string' && ( value.trim() !== '' || ! ( name === 's' || /^f\[/.test( name ) ) ) ) {
						query.append( name, value );
					}
				} );
				url.search = query.toString();

				if ( window.taxmodShow ) {
					window.taxmodShow( url.toString() );
				} else {
					location.href = url.toString();
				}
			};

			if ( now ) {
				ask();
			} else {
				waiting = setTimeout( ask, 400 );
			}
		}

		if ( form ) {
			form.taxmodHead = changed;
		}

		var collapsed = {};

		// The terms the drawn rows are narrowed by here: all of them where the page answers, none while the server is asked.
		function local() {
			return ! form || ( complete && narrows( then, questionOf( form ), numbers ) );
		}

		function textsOf( unit ) {
			var marked = unit.rows[ 0 ].getAttribute( 'data-taxmod-text' );

			if ( marked !== null ) {
				return marked.split( '\u001f' );
			}

			return columns.map( function ( column ) {
				return cellText( unit, column.index );
			} );
		}

		function draw() {
			var narrowing = local();
			var needle = narrowing && search ? search.value.trim() : '';
			var active = narrowing ? filters.filter( function ( input ) {
				return input.value.trim() !== '';
			} ) : [];
			var units = all.slice();
			var byPage = typeof state.sort === 'number' && ! state.fromServer && columns.some( function ( c ) {
				return c.index === state.sort && ( ! c.server || complete );
			} );

			if ( byPage ) {
				var by = comparer( units, state.sort );
				units.sort( function ( a, b ) {
					return ( state.desc ? -1 : 1 ) * by( a, b ) || a.at - b.at;
				} );
			}

			columns.forEach( function ( column ) {
				if ( column.server && ! complete ) {
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
				var keep = ( needle === '' || textsOf( unit ).some( function ( text ) {
					return matches( text, needle );
				} ) ) && active.every( function ( input ) {
					return filterMatches( cellText( unit, +input.dataset.column ), input.value, numbers[ input.name ] || false );
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
			count.textContent = shown === all.length ? said : word( 'count', '%1$d of %2$d' ).replace( '%1$d', shown ).replace( '%2$d', all.length );
		}

		if ( bar ) {
			if ( all.length < 2 ) {
				// Nothing to sort or group by: the head still sorts once rows come, the bar waits.
				bar.hidden = true;

				if ( ! server ) {
					search.parentNode.hidden = true;
				}
			}

			table.before( bar );
		}

		draw();
	}

	// D-1462: the search form and its filter row answer through the head of their list — typing, Enter and «Filter» alike.
	document.addEventListener( 'input', function ( event ) {
		var field = event.target;
		var form = field && field.form;

		if ( form && form.taxmodHead && ( field.name === 's' || /^f\[/.test( field.name || '' ) ) ) {
			form.taxmodHead( false );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( form && form.taxmodHead && form.method.toLowerCase() === 'get' ) {
			event.preventDefault();
			form.taxmodHead( true );
		}
	}, true );

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

	// A list the server answered in place (D-1408, D-1462) has its head again.
	document.addEventListener( 'taxmod:replaced', function ( event ) {
		start( event.target.closest && event.target.closest( '.wrap' ) ? event.target.closest( '.wrap' ) : event.target );
	} );

	// Details drawn on opening (D-1147) may hold a list of their own.
	document.addEventListener( 'taxmod:details', function ( event ) {
		var source = event.target.dataset ? event.target.dataset.source || '' : '';
		start( event.target, 'details-' + source + ':' );
	} );
}() );
