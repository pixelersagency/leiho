/**
 * Okno — composants d'interface partagés : window.OknoUI.
 *
 * - select( el )   : remplace l'apparence d'un <select> par un bouton et une
 *                    liste (role listbox) stylés. Le <select> reste dans la
 *                    page, masqué : sa valeur, ses évènements change et les
 *                    formulaires PHP fonctionnent comme avant.
 * - date( el )     : remplace l'apparence d'un <input type="date",
 *                    "datetime-local" ou "time"> par un bouton et un
 *                    calendrier (role dialog). L'input reste dans la page,
 *                    masqué, et garde sa valeur au format natif.
 * - autoSelects( root ) : applique select() et date() à tous les <select>
 *                    simples et champs de date de root, y compris ceux
 *                    ajoutés plus tard.
 * - chips( opts )  : choix multiples en pastilles, avec recherche et, si
 *                    demandé, création d'une valeur (« Créer « … » »).
 * - theme          : lecture / enregistrement du thème clair ou sombre.
 *
 * Vanilla JS, sans build. Clavier : flèches, Début / Fin, Entrée, Espace,
 * Échap, recherche par les premières lettres.
 */
( function () {
	'use strict';

	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : function ( s, a ) {
		return String( s ).replace( '%s', a );
	};

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var count = 0;

	function uid( prefix ) {
		count += 1;
		return 'okno-ui-' + prefix + '-' + count;
	}

	function svg( paths, className ) {
		var el = document.createElementNS( SVG_NS, 'svg' );
		el.setAttribute( 'viewBox', '0 0 24 24' );
		el.setAttribute( 'fill', 'none' );
		el.setAttribute( 'stroke', 'currentColor' );
		el.setAttribute( 'stroke-width', '2' );
		el.setAttribute( 'stroke-linecap', 'round' );
		el.setAttribute( 'stroke-linejoin', 'round' );
		el.setAttribute( 'aria-hidden', 'true' );
		el.setAttribute( 'focusable', 'false' );
		if ( className ) {
			el.setAttribute( 'class', className );
		}
		paths.forEach( function ( d ) {
			var p = document.createElementNS( SVG_NS, 'path' );
			p.setAttribute( 'd', d );
			el.appendChild( p );
		} );
		return el;
	}

	var ICONS = {
		chevron: [ 'M6 9l6 6 6-6' ],
		check: [ 'M5 12.5l4.5 4.5L19 7.5' ],
		close: [ 'M6 6l12 12', 'M18 6L6 18' ],
		plus: [ 'M12 5v14', 'M5 12h14' ],
		left: [ 'M15 6l-6 6 6 6' ],
		right: [ 'M9 6l6 6-6 6' ],
		// Calendrier : cadre arrondi, deux anneaux, une ligne d'en-tête.
		calendar: [ 'M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z', 'M4 10h16', 'M8.5 3v4', 'M15.5 3v4' ],
		clock: [ 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M12 7.5V12l3 2' ],
	};

	/** Couche où poser les listes : la coque de l'app (jetons de couleur). */
	function layerFor( el ) {
		return ( el && el.closest && ( el.closest( '.okno-app' ) || el.closest( '.okno-editor' ) ) ) || document.body;
	}

	function normalize( text ) {
		return String( text || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' );
	}

	/* ------------------------------------------------------------------ *
	 * Liste flottante (listbox) partagée par select() et chips()
	 * ------------------------------------------------------------------ */

	/**
	 * @param {Object} o
	 * @param {Element} o.anchor      Élément sous lequel ouvrir la liste.
	 * @param {Element} o.focusTarget Élément qui garde le focus (null : la liste le prend).
	 * @param {string}  o.label       Nom accessible.
	 * @param {boolean} o.multi       aria-multiselectable.
	 * @param {Function} o.onPick     ( item ) quand une option est choisie.
	 * @param {Function} o.onClose    ( reason ) à la fermeture.
	 */
	function Popover( o ) {
		this.o = o;
		this.items = [];
		this.active = -1;
		this.el = document.createElement( 'div' );
		this.el.className = 'okno-listbox';
		this.el.id = uid( 'listbox' );
		this.el.setAttribute( 'role', 'listbox' );
		this.el.tabIndex = -1;
		if ( o.label ) {
			this.el.setAttribute( 'aria-label', o.label );
		}
		if ( o.multi ) {
			this.el.setAttribute( 'aria-multiselectable', 'true' );
		}
		this.open = false;
		var self = this;
		this.onDocDown = function ( e ) {
			if ( ! self.el.contains( e.target ) && ! o.anchor.contains( e.target ) ) {
				self.close( 'outside' );
			}
		};
		this.onReflow = function ( e ) {
			if ( e && 'scroll' === e.type && self.el.contains( e.target ) ) {
				return;
			}
			self.position();
		};
		// Souris : survol = option active, clic = choix (mousedown pour ne pas
		// retirer le focus du champ de recherche).
		this.el.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
		} );
		this.el.addEventListener( 'mousemove', function ( e ) {
			var opt = e.target.closest( '[role="option"]' );
			if ( opt ) {
				self.setActive( Number( opt.dataset.index ), false );
			}
		} );
		this.el.addEventListener( 'click', function ( e ) {
			var opt = e.target.closest( '[role="option"]' );
			if ( opt ) {
				self.pick( Number( opt.dataset.index ) );
			}
		} );
	}

	/**
	 * @param {Array} items [{ value, label, selected, disabled, dot, group, create }]
	 */
	Popover.prototype.render = function ( items, emptyText ) {
		var el = this.el;
		el.innerHTML = '';
		this.items = items;
		var lastGroup = null;
		var self = this;
		items.forEach( function ( item, i ) {
			if ( item.group && item.group !== lastGroup ) {
				lastGroup = item.group;
				var g = document.createElement( 'div' );
				g.className = 'okno-listbox__group';
				g.setAttribute( 'role', 'presentation' );
				g.textContent = item.group;
				el.appendChild( g );
			}
			var opt = document.createElement( 'div' );
			opt.className = 'okno-listbox__option' + ( item.create ? ' okno-listbox__option--create' : '' );
			opt.id = el.id + '-' + i;
			opt.dataset.index = i;
			opt.setAttribute( 'role', 'option' );
			opt.setAttribute( 'aria-selected', item.selected ? 'true' : 'false' );
			if ( item.disabled ) {
				opt.setAttribute( 'aria-disabled', 'true' );
			}
			opt.appendChild( svg( item.create ? ICONS.plus : ICONS.check, 'okno-listbox__check' ) );
			if ( item.create ) {
				opt.firstChild.style.visibility = 'visible';
			}
			if ( item.dot ) {
				opt.dataset.dot = item.dot;
				var dot = document.createElement( 'span' );
				dot.className = 'okno-listbox__dot';
				opt.appendChild( dot );
			}
			var text = document.createElement( 'span' );
			text.className = 'okno-listbox__text';
			text.textContent = item.label;
			opt.appendChild( text );
			el.appendChild( opt );
		} );
		if ( ! items.length && emptyText ) {
			var empty = document.createElement( 'div' );
			empty.className = 'okno-listbox__empty';
			empty.textContent = emptyText;
			el.appendChild( empty );
		}
		if ( this.active >= items.length ) {
			this.active = items.length - 1;
		}
		this.setActive( this.active, false );
	};

	Popover.prototype.show = function () {
		if ( this.open ) {
			return;
		}
		this.open = true;
		layerFor( this.o.anchor ).appendChild( this.el );
		this.position();
		document.addEventListener( 'mousedown', this.onDocDown, true );
		document.addEventListener( 'touchstart', this.onDocDown, true );
		window.addEventListener( 'resize', this.onReflow );
		window.addEventListener( 'scroll', this.onReflow, true );
		if ( ! this.o.focusTarget ) {
			this.el.focus( { preventScroll: true } );
		}
		this.scrollToActive();
	};

	Popover.prototype.close = function ( reason ) {
		if ( ! this.open ) {
			return;
		}
		this.open = false;
		if ( this.el.parentNode ) {
			this.el.parentNode.removeChild( this.el );
		}
		document.removeEventListener( 'mousedown', this.onDocDown, true );
		document.removeEventListener( 'touchstart', this.onDocDown, true );
		window.removeEventListener( 'resize', this.onReflow );
		window.removeEventListener( 'scroll', this.onReflow, true );
		if ( this.o.onClose ) {
			this.o.onClose( reason );
		}
	};

	/** Sous l'ancre si la place le permet, sinon au-dessus ; toujours dans la fenêtre. */
	Popover.prototype.position = function () {
		if ( ! this.open ) {
			return;
		}
		var r = this.o.anchor.getBoundingClientRect();
		var vw = document.documentElement.clientWidth;
		var vh = document.documentElement.clientHeight;
		if ( r.bottom < 0 || r.top > vh ) {
			this.close( 'scroll' );
			return;
		}
		var gap = 4;
		var margin = 8;
		var el = this.el;
		el.style.minWidth = Math.max( 160, r.width ) + 'px';
		el.style.maxHeight = '';
		var below = vh - r.bottom - gap - margin;
		var above = r.top - gap - margin;
		var natural = Math.min( el.scrollHeight + 2, 340 );
		var placeBelow = below >= Math.min( natural, 200 ) || below >= above;
		var room = Math.max( 120, placeBelow ? below : above );
		var height = Math.min( natural, room );
		el.style.maxHeight = Math.min( 340, room ) + 'px';
		el.style.top = ( placeBelow ? r.bottom + gap : r.top - gap - height ) + 'px';
		var width = el.offsetWidth;
		var left = Math.min( r.left, vw - width - margin );
		el.style.left = Math.max( margin, left ) + 'px';
	};

	Popover.prototype.setActive = function ( index, scroll ) {
		var prev = this.el.querySelector( '.is-active' );
		if ( prev ) {
			prev.classList.remove( 'is-active' );
		}
		this.active = index;
		var target = this.o.focusTarget || this.el;
		var opt = index >= 0 ? this.el.querySelector( '[data-index="' + index + '"]' ) : null;
		if ( opt ) {
			opt.classList.add( 'is-active' );
			target.setAttribute( 'aria-activedescendant', opt.id );
			if ( false !== scroll ) {
				this.scrollToActive();
			}
		} else {
			target.removeAttribute( 'aria-activedescendant' );
		}
	};

	Popover.prototype.scrollToActive = function () {
		var opt = this.el.querySelector( '.is-active' );
		if ( opt && opt.scrollIntoView ) {
			opt.scrollIntoView( { block: 'nearest' } );
		}
	};

	Popover.prototype.move = function ( delta ) {
		var n = this.items.length;
		if ( ! n ) {
			return;
		}
		var i = this.active;
		for ( var step = 0; step < n; step++ ) {
			i = i < 0 ? ( delta > 0 ? 0 : n - 1 ) : Math.min( n - 1, Math.max( 0, i + delta ) );
			if ( ! this.items[ i ].disabled ) {
				break;
			}
			if ( ( 0 === i && delta < 0 ) || ( n - 1 === i && delta > 0 ) ) {
				return;
			}
		}
		this.setActive( i );
	};

	Popover.prototype.edge = function ( last ) {
		var list = this.items;
		var order = list.map( function ( _, i ) {
			return i;
		} );
		if ( last ) {
			order.reverse();
		}
		for ( var k = 0; k < order.length; k++ ) {
			if ( ! list[ order[ k ] ].disabled ) {
				this.setActive( order[ k ] );
				return;
			}
		}
	};

	Popover.prototype.pick = function ( index ) {
		var item = this.items[ index ];
		if ( ! item || item.disabled ) {
			return;
		}
		this.o.onPick( item, index );
	};

	/** Recherche par les premières lettres tapées (comme un <select>). */
	function typeahead() {
		var buffer = '';
		var timer = null;
		return function ( key, items, from ) {
			clearTimeout( timer );
			timer = setTimeout( function () {
				buffer = '';
			}, 600 );
			buffer += normalize( key );
			var n = items.length;
			var single = buffer.split( '' ).every( function ( c ) {
				return c === buffer[ 0 ];
			} );
			var start = single && buffer.length > 1 ? from + 1 : Math.max( 0, from );
			for ( var k = 0; k < n; k++ ) {
				var i = ( start + k ) % n;
				var label = normalize( items[ i ].label );
				var needle = single && buffer.length > 1 ? buffer[ 0 ] : buffer;
				if ( ! items[ i ].disabled && 0 === label.indexOf( needle ) ) {
					return i;
				}
			}
			return -1;
		};
	}

	/* ------------------------------------------------------------------ *
	 * select()
	 * ------------------------------------------------------------------ */

	var valueDesc = Object.getOwnPropertyDescriptor( HTMLSelectElement.prototype, 'value' );
	var indexDesc = Object.getOwnPropertyDescriptor( HTMLSelectElement.prototype, 'selectedIndex' );

	function canEnhance( el ) {
		return (
			el &&
			'SELECT' === el.tagName &&
			! el.multiple &&
			! ( el.size > 1 ) &&
			! el.oknoSelect &&
			! el.hasAttribute( 'data-okno-native' ) &&
			! el.closest( '[data-okno-native]' )
		);
	}

	function optionItems( select ) {
		var items = [];
		[].forEach.call( select.options, function ( opt ) {
			if ( opt.hidden ) {
				return;
			}
			items.push( {
				value: opt.value,
				label: opt.textContent.trim() || opt.value,
				selected: opt.selected,
				disabled: opt.disabled || ( opt.parentNode && opt.parentNode.disabled ),
				dot: opt.dataset.dot || '',
				group: 'OPTGROUP' === opt.parentNode.tagName ? opt.parentNode.label : '',
				option: opt,
			} );
		} );
		return items;
	}

	function enhanceSelect( select ) {
		if ( ! canEnhance( select ) ) {
			return select && select.oknoSelect ? select.oknoSelect : null;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.setAttribute( 'aria-haspopup', 'listbox' );
		button.setAttribute( 'aria-expanded', 'false' );
		var dot = document.createElement( 'span' );
		dot.className = 'okno-select__dot';
		var label = document.createElement( 'span' );
		label.className = 'okno-select__label';
		button.appendChild( dot );
		button.appendChild( label );
		button.appendChild( svg( ICONS.chevron, 'okno-select__chevron' ) );

		var api = { select: select, button: button };
		var pop = new Popover( {
			anchor: button,
			focusTarget: null,
			multi: false,
			onPick: function ( item ) {
				choose( item.value );
				pop.close( 'pick' );
			},
			onClose: function ( reason ) {
				button.setAttribute( 'aria-expanded', 'false' );
				button.removeAttribute( 'aria-controls' );
				if ( 'outside' !== reason && 'scroll' !== reason && 'tab' !== reason ) {
					button.focus( { preventScroll: true } );
				}
			},
		} );
		var find = typeahead();

		function labelText() {
			var l = select.labels && select.labels.length ? select.labels[ 0 ] : null;
			var aria = select.getAttribute( 'aria-label' );
			return aria || ( l ? l.textContent.trim() : '' );
		}

		function sync() {
			var opt = select.options[ select.selectedIndex ];
			var hasDots = [].some.call( select.options, function ( o ) {
				return !! o.dataset.dot;
			} );
			label.textContent = opt ? opt.textContent.trim() : '';
			label.classList.toggle( 'is-placeholder', ! opt || '' === opt.value );
			dot.hidden = ! hasDots || ! opt || ! opt.dataset.dot;
			if ( opt && opt.dataset.dot ) {
				button.dataset.dot = opt.dataset.dot;
			} else {
				delete button.dataset.dot;
			}
			// Classes, état et attributs reportés sur le bouton.
			var classes = ( select.getAttribute( 'class' ) || '' )
				.split( /\s+/ )
				.filter( function ( c ) {
					return c && 'okno-select-native' !== c;
				} );
			button.className = [ 'okno-select' ].concat( classes ).join( ' ' );
			button.disabled = select.disabled;
			button.hidden = select.hidden;
			button.title = select.title || '';
			if ( select.dataset.status ) {
				button.dataset.status = select.dataset.status;
			}
			var aria = labelText();
			var describedBy = select.getAttribute( 'aria-describedby' );
			if ( select.id && select.labels && select.labels.length ) {
				var ids = [];
				[].forEach.call( select.labels, function ( l ) {
					if ( ! l.id ) {
						l.id = uid( 'label' );
					}
					ids.push( l.id );
				} );
				button.setAttribute( 'aria-labelledby', ids.join( ' ' ) + ' ' + label.id );
				button.removeAttribute( 'aria-label' );
			} else if ( aria ) {
				button.setAttribute( 'aria-label', aria + ( opt ? ' : ' + opt.textContent.trim() : '' ) );
			}
			if ( describedBy ) {
				button.setAttribute( 'aria-describedby', describedBy );
			}
			if ( select.getAttribute( 'aria-invalid' ) ) {
				button.setAttribute( 'aria-invalid', select.getAttribute( 'aria-invalid' ) );
			} else {
				button.removeAttribute( 'aria-invalid' );
			}
			if ( pop.open ) {
				pop.render( optionItems( select ) );
			}
		}
		label.id = uid( 'value' );

		function choose( value ) {
			if ( valueDesc.get.call( select ) === value ) {
				return;
			}
			valueDesc.set.call( select, value );
			sync();
			select.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function open() {
			if ( select.disabled || pop.open ) {
				return;
			}
			var items = optionItems( select );
			pop.el.setAttribute( 'aria-label', labelText() || __( 'Options', 'okno' ) );
			pop.active = Math.max( 0, select.selectedIndex );
			pop.render( items );
			button.setAttribute( 'aria-expanded', 'true' );
			button.setAttribute( 'aria-controls', pop.el.id );
			pop.show();
		}

		button.addEventListener( 'click', function () {
			if ( pop.open ) {
				pop.close( 'toggle' );
			} else {
				open();
			}
		} );

		button.addEventListener( 'keydown', function ( e ) {
			if ( [ 'ArrowDown', 'ArrowUp', 'Enter', ' ' ].indexOf( e.key ) !== -1 ) {
				e.preventDefault();
				open();
			} else if ( 1 === e.key.length && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
				// Lettre tapée bouton fermé : choisit directement, comme un <select>.
				var items = optionItems( select );
				var i = find( e.key, items, select.selectedIndex );
				if ( i >= 0 ) {
					e.preventDefault();
					choose( items[ i ].value );
				}
			}
		} );

		pop.el.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					pop.move( 1 );
					break;
				case 'ArrowUp':
					e.preventDefault();
					pop.move( -1 );
					break;
				case 'Home':
				case 'PageUp':
					e.preventDefault();
					pop.edge( false );
					break;
				case 'End':
				case 'PageDown':
					e.preventDefault();
					pop.edge( true );
					break;
				case 'Enter':
				case ' ':
					e.preventDefault();
					pop.pick( pop.active );
					break;
				case 'Escape':
					e.preventDefault();
					e.stopPropagation(); // Ne ferme pas la boîte de dialogue ou le volet autour.
					pop.close( 'escape' );
					break;
				case 'Tab':
					pop.close( 'tab' );
					button.focus( { preventScroll: true } );
					break;
				default:
					if ( 1 === e.key.length && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
						var i = find( e.key, pop.items, pop.active );
						if ( i >= 0 ) {
							pop.setActive( i );
						}
					}
			}
		} );

		// Le <select> reste la source de vérité : valeur, options, état.
		Object.defineProperty( select, 'value', {
			configurable: true,
			get: function () {
				return valueDesc.get.call( select );
			},
			set: function ( v ) {
				valueDesc.set.call( select, v );
				sync();
			},
		} );
		Object.defineProperty( select, 'selectedIndex', {
			configurable: true,
			get: function () {
				return indexDesc.get.call( select );
			},
			set: function ( v ) {
				indexDesc.set.call( select, v );
				sync();
			},
		} );
		select.focus = function ( options ) {
			button.focus( options );
		};
		select.addEventListener( 'change', sync );
		var form = select.form;
		if ( form ) {
			form.addEventListener( 'reset', function () {
				setTimeout( sync, 0 );
			} );
		}

		new MutationObserver( sync ).observe( select, {
			childList: true,
			subtree: true,
			characterData: true,
			attributes: true,
			attributeFilter: [ 'hidden', 'disabled', 'class', 'title', 'data-status', 'aria-invalid', 'aria-label', 'selected' ],
		} );

		select.classList.add( 'okno-select-native' );
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );
		select.parentNode.insertBefore( button, select.nextSibling );
		select.oknoSelect = api;
		api.sync = sync;
		api.open = open;
		api.close = function () {
			pop.close( 'api' );
		};
		sync();
		return api;
	}

	// Un clic sur le <label for> d'un select remplacé donne le focus au bouton.
	document.addEventListener( 'click', function ( e ) {
		var l = e.target.closest && e.target.closest( 'label[for]' );
		if ( ! l ) {
			return;
		}
		var target = document.getElementById( l.getAttribute( 'for' ) );
		var widget = target && ( target.oknoSelect || target.oknoDate );
		if ( widget ) {
			e.preventDefault();
			widget.button.focus();
		}
	} );

	var DATE_SELECTOR = 'input[type="date"], input[type="datetime-local"], input[type="time"]';

	function enhanceAll( root ) {
		[].forEach.call( root.querySelectorAll( 'select' ), enhanceSelect );
		[].forEach.call( root.querySelectorAll( DATE_SELECTOR ), enhanceDate );
	}

	/** Menus et champs de date de root, y compris ceux ajoutés plus tard. */
	function autoSelects( root ) {
		if ( ! root ) {
			return;
		}
		enhanceAll( root );
		var pending = false;
		new MutationObserver( function ( records ) {
			if ( pending ) {
				return;
			}
			var found = records.some( function ( r ) {
				return [].some.call( r.addedNodes, function ( n ) {
					return 1 === n.nodeType && ( 'SELECT' === n.tagName || 'INPUT' === n.tagName || ( n.querySelector && n.querySelector( 'select, ' + DATE_SELECTOR ) ) );
				} );
			} );
			if ( ! found ) {
				return;
			}
			pending = true;
			// Après le rendu en cours : les options sont posées, la valeur aussi.
			Promise.resolve().then( function () {
				pending = false;
				enhanceAll( root );
			} );
		} ).observe( root, { childList: true, subtree: true } );
	}

	/* ------------------------------------------------------------------ *
	 * chips()
	 * ------------------------------------------------------------------ */

	/**
	 * @param {Object}   o
	 * @param {Array}    o.options    [{ value, label }]
	 * @param {Array}    o.value      Valeurs choisies.
	 * @param {Function} o.onChange   ( values, items ).
	 * @param {boolean}  o.create     Propose « Créer « … » » (valeur = le texte).
	 * @param {string}   o.id         Id du champ de saisie (pour le <label for>).
	 * @param {string}   o.placeholder
	 * @param {string}   o.label      Nom accessible de la liste.
	 */
	function chips( o ) {
		var options = ( o.options || [] ).map( function ( opt ) {
			return { value: opt.value, label: String( opt.label ) };
		} );
		var selected = ( o.value || [] ).map( function ( v ) {
			var known = options.find( function ( opt ) {
				return String( opt.value ) === String( v );
			} );
			return known || { value: v, label: String( v ), isNew: 'string' === typeof v && o.create };
		} );

		var box = document.createElement( 'div' );
		box.className = 'okno-chips';
		var list = document.createElement( 'span' );
		list.style.display = 'contents';
		list.setAttribute( 'role', 'list' );
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'okno-chips__input';
		input.id = o.id || uid( 'chips' );
		input.autocomplete = 'off';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.placeholder = o.placeholder || __( 'Search…', 'okno' );
		box.appendChild( list );
		box.appendChild( input );

		var pop = new Popover( {
			anchor: box,
			focusTarget: input,
			multi: true,
			label: o.label,
			onPick: function ( item ) {
				if ( item.create ) {
					var name = input.value.trim();
					add( { value: name, label: name, isNew: true } );
					input.value = '';
				} else {
					toggle( item );
				}
				refresh();
			},
			onClose: function () {
				input.setAttribute( 'aria-expanded', 'false' );
				input.removeAttribute( 'aria-controls' );
			},
		} );

		function isSelected( value ) {
			return selected.some( function ( s ) {
				return String( s.value ) === String( value );
			} );
		}

		function emit() {
			if ( o.onChange ) {
				o.onChange(
					selected.map( function ( s ) {
						return s.value;
					} ),
					selected.slice()
				);
			}
		}

		function add( item ) {
			if ( isSelected( item.value ) ) {
				return;
			}
			selected.push( item );
			renderChips();
			emit();
		}

		function remove( value ) {
			selected = selected.filter( function ( s ) {
				return String( s.value ) !== String( value );
			} );
			renderChips();
			emit();
		}

		function toggle( item ) {
			if ( isSelected( item.value ) ) {
				remove( item.value );
			} else {
				add( { value: item.value, label: item.label } );
			}
		}

		function renderChips() {
			list.innerHTML = '';
			selected.forEach( function ( s ) {
				var chip = document.createElement( 'span' );
				chip.className = 'okno-chips__chip' + ( s.isNew ? ' is-new' : '' );
				chip.setAttribute( 'role', 'listitem' );
				var text = document.createElement( 'span' );
				text.textContent = s.label;
				chip.appendChild( text );
				if ( s.isNew ) {
					chip.title = __( 'New: created when you save', 'okno' );
				}
				var x = document.createElement( 'button' );
				x.type = 'button';
				x.className = 'okno-chips__remove';
				/* translators: %s: item name (e.g. a tag). */
				x.setAttribute( 'aria-label', sprintf( __( 'Remove %s', 'okno' ), s.label ) );
				x.appendChild( svg( ICONS.close ) );
				x.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					remove( s.value );
					input.focus();
				} );
				chip.appendChild( x );
				list.appendChild( chip );
			} );
		}

		function matches() {
			var q = normalize( input.value.trim() );
			var items = options
				.filter( function ( opt ) {
					return ! q || normalize( opt.label ).indexOf( q ) !== -1;
				} )
				.map( function ( opt ) {
					return { value: opt.value, label: opt.label, selected: isSelected( opt.value ) };
				} );
			var exact = options.some( function ( opt ) {
				return normalize( opt.label ) === q;
			} ) || selected.some( function ( s ) {
				return normalize( s.label ) === q;
			} );
			if ( o.create && q && ! exact ) {
				items.push( {
					value: null,
					/* translators: %s: name typed by the user (e.g. a new tag). */
					label: sprintf( __( 'Create “%s”', 'okno' ), input.value.trim() ),
					create: true,
				} );
			}
			return items;
		}

		function refresh() {
			var items = matches();
			if ( pop.active < 0 || pop.active >= items.length ) {
				pop.active = items.length ? 0 : -1;
			}
			pop.render( items, __( 'No results', 'okno' ) );
			if ( ! pop.open ) {
				pop.show();
				input.setAttribute( 'aria-expanded', 'true' );
				input.setAttribute( 'aria-controls', pop.el.id );
			} else {
				pop.position();
			}
		}

		box.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === box || e.target === list ) {
				e.preventDefault();
				input.focus();
			}
		} );
		input.addEventListener( 'focus', function () {
			pop.active = -1;
			refresh();
		} );
		input.addEventListener( 'click', function () {
			if ( ! pop.open ) {
				refresh();
			}
		} );
		input.addEventListener( 'input', function () {
			pop.active = 0;
			refresh();
		} );
		input.addEventListener( 'blur', function () {
			pop.close( 'blur' );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					if ( ! pop.open ) {
						refresh();
					}
					pop.move( 1 );
					break;
				case 'ArrowUp':
					e.preventDefault();
					pop.move( -1 );
					break;
				case 'Enter':
					if ( pop.open && pop.active >= 0 ) {
						e.preventDefault();
						pop.pick( pop.active );
					}
					break;
				case 'Escape':
					if ( pop.open ) {
						e.preventDefault();
						e.stopPropagation();
						pop.close( 'escape' );
					}
					break;
				case 'Backspace':
					if ( '' === input.value && selected.length ) {
						remove( selected[ selected.length - 1 ].value );
						if ( pop.open ) {
							refresh();
						}
					}
					break;
				case 'Tab':
					pop.close( 'tab' );
					break;
			}
		} );

		renderChips();
		box.oknoChips = {
			input: input,
			value: function () {
				return selected.map( function ( s ) {
					return s.value;
				} );
			},
		};
		return box;
	}

	/* ------------------------------------------------------------------ *
	 * date() : date, date et heure, heure
	 * ------------------------------------------------------------------ */

	/*
	 * Un bouton au format d'un champ montre la valeur dans la langue du site
	 * (« 11 août 2026, 21:56 ») et ouvre un calendrier (role dialog, non
	 * modal). L'<input type="date|datetime-local|time"> reste dans la page,
	 * masqué : il garde la valeur au format attendu (YYYY-MM-DD,
	 * YYYY-MM-DDTHH:MM, HH:MM) et reçoit input / change comme avant.
	 */

	var inputValueDesc = Object.getOwnPropertyDescriptor( HTMLInputElement.prototype, 'value' );
	var DATE_TYPES = [ 'date', 'datetime-local', 'time' ];
	var dateFmt = null;

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	/** Langue, premier jour de la semaine et format horaire du site (OknoUIConfig). */
	function dateSetup() {
		if ( dateFmt ) {
			return dateFmt;
		}
		var cfg = window.OknoUIConfig || {};
		var locale;
		[ cfg.locale, document.documentElement.lang ].some( function ( cand ) {
			if ( ! cand ) {
				return false;
			}
			try {
				new Intl.DateTimeFormat( cand );
				locale = cand;
				return true;
			} catch ( e ) {
				return false;
			}
		} );
		var hour12 = 'boolean' === typeof cfg.hour12
			? cfg.hour12
			: !! new Intl.DateTimeFormat( locale, { hour: 'numeric' } ).resolvedOptions().hour12;
		var start = parseInt( cfg.startOfWeek, 10 );
		function f( opts ) {
			return new Intl.DateTimeFormat( locale, opts );
		}
		dateFmt = {
			locale: locale,
			hour12: hour12,
			start: start >= 0 && start <= 6 ? start : 1,
			date: f( { day: 'numeric', month: 'long', year: 'numeric' } ),
			full: f( { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } ),
			time: f( { hour: hour12 ? 'numeric' : '2-digit', minute: '2-digit', hour12: hour12 } ),
			monthYear: f( { month: 'long', year: 'numeric' } ),
			month: f( { month: 'long' } ),
			monthShort: f( { month: 'short' } ),
			weekday: f( { weekday: 'long' } ),
			weekdayShort: f( { weekday: 'short' } ),
			period: f( { hour: 'numeric', hour12: true } ),
		};
		return dateFmt;
	}

	/** Midi : pas de saut d'un jour au changement d'heure. Années < 100 comprises. */
	function makeDate( y, m, d ) {
		var dt = new Date( 2000, 0, 1, 12 );
		dt.setFullYear( y, m, d );
		return dt;
	}

	function daysIn( y, m ) {
		return makeDate( y, m + 1, 0 ).getDate();
	}

	function ymd( dt ) {
		var y = dt.getFullYear();
		return ( y < 1000 ? ( '000' + y ).slice( -4 ) : y ) + '-' + pad( dt.getMonth() + 1 ) + '-' + pad( dt.getDate() );
	}

	function parseDay( s ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( s || '' ) );
		if ( ! m ) {
			return null;
		}
		var dt = makeDate( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] );
		return ymd( dt ) === m[ 0 ] ? dt : null;
	}

	function parseTime( s ) {
		var m = /^(\d{2}):(\d{2})/.exec( String( s || '' ) );
		if ( ! m || +m[ 1 ] > 23 || +m[ 2 ] > 59 ) {
			return null;
		}
		return { h: +m[ 1 ], i: +m[ 2 ] };
	}

	function capitalize( s ) {
		return s ? s.charAt( 0 ).toLocaleUpperCase( dateSetup().locale ) + s.slice( 1 ) : s;
	}

	/** Nom de la période (« AM » / « PM » dans la langue du site). */
	function periodLabel( pm ) {
		var parts = dateSetup().period.formatToParts( new Date( 2000, 0, 1, pm ? 15 : 9 ) );
		var found = parts.filter( function ( p ) {
			return 'dayPeriod' === p.type || 'dayperiod' === p.type;
		} )[ 0 ];
		return found ? found.value : pm ? 'PM' : 'AM';
	}

	function canEnhanceDate( el ) {
		return (
			el &&
			'INPUT' === el.tagName &&
			DATE_TYPES.indexOf( String( el.getAttribute( 'type' ) || '' ).toLowerCase() ) !== -1 &&
			! el.oknoDate &&
			! el.hasAttribute( 'data-okno-native' ) &&
			! el.closest( '[data-okno-native]' ) &&
			el.parentNode
		);
	}

	function enhanceDate( input ) {
		if ( ! canEnhanceDate( input ) ) {
			return input && input.oknoDate ? input.oknoDate : null;
		}
		var fmt = dateSetup();
		var type = String( input.getAttribute( 'type' ) ).toLowerCase();
		var mode = 'datetime-local' === type ? 'datetime' : type;
		var hasDate = 'time' !== mode;
		var hasTime = 'date' !== mode;

		/* ---- Champ : bouton + effacer ---- */

		var wrap = document.createElement( 'span' );
		wrap.className = 'okno-date';
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.appendChild( svg( hasDate ? ICONS.calendar : ICONS.clock, 'okno-date__icon' ) );
		var label = document.createElement( 'span' );
		label.className = 'okno-select__label okno-date__label';
		label.id = uid( 'date-value' );
		button.appendChild( label );
		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'okno-date__clear';
		clear.appendChild( svg( ICONS.close ) );
		wrap.appendChild( button );
		wrap.appendChild( clear );

		var api = { input: input, button: button };

		function raw() {
			return inputValueDesc.get.call( input );
		}

		function required() {
			return input.required || 'true' === input.getAttribute( 'aria-required' );
		}

		function fieldLabel() {
			var l = input.labels && input.labels.length ? input.labels[ 0 ] : null;
			return input.getAttribute( 'aria-label' ) || ( l ? l.textContent.trim() : '' );
		}

		function parts( value ) {
			value = String( value || '' );
			return {
				day: hasDate ? parseDay( value.slice( 0, 10 ) ) : null,
				time: 'time' === mode ? parseTime( value ) : 'datetime' === mode ? parseTime( value.slice( 11 ) ) : null,
			};
		}

		function display( value ) {
			var p = parts( value );
			if ( ( hasDate && ! p.day ) || ( 'time' === mode && ! p.time ) ) {
				return value || '';
			}
			var text = hasDate ? fmt.date.format( p.day ) : '';
			if ( hasTime && p.time ) {
				var t = fmt.time.format( new Date( 2000, 0, 1, p.time.h, p.time.i ) );
				text = text ? text + ', ' + t : t;
			}
			return text;
		}

		function placeholder() {
			if ( input.placeholder ) {
				return input.placeholder;
			}
			if ( 'time' === mode ) {
				return __( 'Choose a time', 'okno' );
			}
			return 'datetime' === mode ? __( 'Choose a date and time', 'okno' ) : __( 'Choose a date', 'okno' );
		}

		function sync() {
			var value = raw();
			label.textContent = value ? display( value ) : placeholder();
			label.classList.toggle( 'is-placeholder', ! value );
			var classes = ( input.getAttribute( 'class' ) || '' ).split( /\s+/ ).filter( function ( c ) {
				return c && 'okno-date-native' !== c;
			} );
			button.className = [ 'okno-select', 'okno-date__trigger' ].concat( classes ).join( ' ' );
			button.disabled = input.disabled || input.readOnly;
			wrap.hidden = input.hidden;
			button.title = input.title || '';
			var canClear = !! value && ! required() && ! button.disabled;
			clear.hidden = ! canClear;
			wrap.classList.toggle( 'is-clearable', canClear );
			var name = fieldLabel();
			/* translators: %s: field label. */
			clear.setAttribute( 'aria-label', name ? sprintf( __( 'Clear %s', 'okno' ), name ) : __( 'Clear', 'okno' ) );
			if ( input.id && input.labels && input.labels.length ) {
				var ids = [];
				[].forEach.call( input.labels, function ( l ) {
					if ( ! l.id ) {
						l.id = uid( 'label' );
					}
					ids.push( l.id );
				} );
				button.setAttribute( 'aria-labelledby', ids.join( ' ' ) + ' ' + label.id );
				button.removeAttribute( 'aria-label' );
			} else if ( name ) {
				button.setAttribute( 'aria-label', name + ' : ' + label.textContent );
			}
			[ 'aria-describedby', 'aria-invalid' ].forEach( function ( attr ) {
				if ( input.getAttribute( attr ) ) {
					button.setAttribute( attr, input.getAttribute( attr ) );
				} else {
					button.removeAttribute( attr );
				}
			} );
			if ( required() ) {
				button.setAttribute( 'aria-required', 'true' );
			} else {
				button.removeAttribute( 'aria-required' );
			}
		}

		/** Bornes min / max de l'input, au format de sa valeur. */
		function bound( which ) {
			var v = input.getAttribute( which ) || '';
			if ( 'datetime' === mode && /^\d{4}-\d{2}-\d{2}$/.test( v ) ) {
				v += 'max' === which ? 'T23:59' : 'T00:00';
			}
			return 'datetime' === mode ? v.slice( 0, 16 ) : 'time' === mode ? v.slice( 0, 5 ) : v.slice( 0, 10 );
		}

		function clamp( value ) {
			var min = bound( 'min' );
			var max = bound( 'max' );
			if ( value && min && value < min ) {
				return min;
			}
			if ( value && max && value > max ) {
				return max;
			}
			return value;
		}

		function dayDisabled( dt ) {
			var key = ymd( dt );
			var min = bound( 'min' ).slice( 0, 10 );
			var max = bound( 'max' ).slice( 0, 10 );
			return ( min && key < min ) || ( max && key > max );
		}

		function setValue( value ) {
			value = clamp( value );
			if ( raw() === value ) {
				return;
			}
			inputValueDesc.set.call( input, value );
			sync();
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/** Pas des minutes : attribut step (secondes), 1 min par défaut. */
		function minuteStep() {
			var step = parseFloat( input.getAttribute( 'step' ) );
			return step > 0 ? Math.min( 60, Math.max( 1, Math.round( step / 60 ) ) ) : 1;
		}

		function nowTime() {
			var now = new Date();
			var s = minuteStep();
			return { h: now.getHours(), i: Math.floor( now.getMinutes() / s ) * s };
		}

		function compose( day, time ) {
			var t = time ? pad( time.h ) + ':' + pad( time.i ) : '';
			if ( 'time' === mode ) {
				return t;
			}
			if ( 'date' === mode ) {
				return ymd( day );
			}
			return ymd( day ) + 'T' + ( t || '00:00' );
		}

		/* ---- Calendrier ---- */

		var dialog = null;
		var els = {};
		var state = { view: 'days', y: 0, m: 0, focus: null };
		var isOpen = false;

		function today() {
			var n = new Date();
			return makeDate( n.getFullYear(), n.getMonth(), n.getDate() );
		}

		function el( tag, cls, attrs ) {
			var node = document.createElement( tag );
			if ( cls ) {
				node.className = cls;
			}
			Object.keys( attrs || {} ).forEach( function ( k ) {
				if ( 'text' === k ) {
					node.textContent = attrs[ k ];
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
			return node;
		}

		function navButton( dir ) {
			var b = el( 'button', 'okno-datepicker__nav', { type: 'button' } );
			b.appendChild( svg( dir < 0 ? ICONS.left : ICONS.right ) );
			b.addEventListener( 'click', function () {
				if ( 'months' === state.view ) {
					state.y += dir;
					render();
				} else {
					shiftFocus( 0, dir, false );
				}
			} );
			return b;
		}

		function buildDialog() {
			dialog = el( 'div', 'okno-datepicker okno-datepicker--' + mode, {
				role: 'dialog',
				'aria-modal': 'false',
				id: uid( 'datepicker' ),
			} );
			if ( hasDate ) {
				var head = el( 'div', 'okno-datepicker__head' );
				els.prev = navButton( -1 );
				els.next = navButton( 1 );
				els.title = el( 'button', 'okno-datepicker__title', { type: 'button', id: uid( 'datepicker-title' ), 'aria-live': 'off' } );
				els.titleText = el( 'span' );
				els.title.appendChild( els.titleText );
				els.title.appendChild( svg( ICONS.chevron, 'okno-datepicker__title-chevron' ) );
				els.title.addEventListener( 'click', function () {
					state.view = 'days' === state.view ? 'months' : 'months' === state.view ? 'years' : 'days';
					render( true );
				} );
				head.appendChild( els.prev );
				head.appendChild( els.title );
				head.appendChild( els.next );
				dialog.appendChild( head );
				els.live = el( 'div', 'okno-sr', { 'aria-live': 'polite', 'aria-atomic': 'true' } );
				dialog.appendChild( els.live );
				els.body = el( 'div', 'okno-datepicker__body' );
				dialog.appendChild( els.body );
			}
			if ( hasTime ) {
				dialog.appendChild( buildTime() );
			}
			var foot = el( 'div', 'okno-datepicker__foot' );
			els.today = el( 'button', 'okno-datepicker__action', { type: 'button', text: hasDate ? __( 'Today', 'okno' ) : __( 'Now', 'okno' ) } );
			els.today.addEventListener( 'click', pickToday );
			foot.appendChild( els.today );
			els.erase = el( 'button', 'okno-datepicker__action', { type: 'button', text: __( 'Clear', 'okno' ) } );
			els.erase.addEventListener( 'click', function () {
				setValue( '' );
				close( 'pick' );
			} );
			foot.appendChild( els.erase );
			if ( hasTime ) {
				var done = el( 'button', 'okno-datepicker__action okno-datepicker__action--primary', { type: 'button', text: __( 'Done', 'okno' ) } );
				done.addEventListener( 'click', function () {
					close( 'pick' );
				} );
				foot.appendChild( done );
			}
			dialog.appendChild( foot );
			dialog.addEventListener( 'keydown', onDialogKey );
			dialog.addEventListener( 'focusout', function ( e ) {
				var to = e.relatedTarget;
				if ( isOpen && to && ! dialog.contains( to ) && ! wrap.contains( to ) && ! ( to.closest && to.closest( '.okno-listbox' ) ) ) {
					close( 'tab' );
				}
			} );
		}

		/* Heure : deux menus OknoUI (heures, minutes), plus AM / PM en 12 h. */
		function buildTime() {
			var row = el( 'div', 'okno-datepicker__time' );
			var title = el( 'span', 'okno-datepicker__time-label', { id: uid( 'time-label' ), text: __( 'Time', 'okno' ) } );
			row.appendChild( title );
			els.hour = el( 'select', 'okno-select--compact okno-datepicker__hour', { 'aria-label': __( 'Hour', 'okno' ) } );
			els.minute = el( 'select', 'okno-select--compact okno-datepicker__minute', { 'aria-label': __( 'Minutes', 'okno' ) } );
			var hours = fmt.hour12 ? [ 12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11 ] : null;
			( hours || Array.apply( null, { length: 24 } ).map( function ( _, i ) {
				return i;
			} ) ).forEach( function ( hh ) {
				els.hour.appendChild( el( 'option', '', { value: String( hh ), text: fmt.hour12 ? String( hh ) : pad( hh ) } ) );
			} );
			row.appendChild( els.hour );
			row.appendChild( el( 'span', 'okno-datepicker__colon', { 'aria-hidden': 'true', text: ':' } ) );
			row.appendChild( els.minute );
			if ( fmt.hour12 ) {
				els.period = el( 'select', 'okno-select--compact okno-datepicker__period', { 'aria-label': __( 'AM or PM', 'okno' ) } );
				els.period.appendChild( el( 'option', '', { value: 'am', text: periodLabel( false ) } ) );
				els.period.appendChild( el( 'option', '', { value: 'pm', text: periodLabel( true ) } ) );
				row.appendChild( els.period );
			}
			[ els.hour, els.minute, els.period ].forEach( function ( s ) {
				if ( s ) {
					s.addEventListener( 'change', onTimeChange );
					enhanceSelect( s );
				}
			} );
			return row;
		}

		function fillMinutes( current ) {
			var step = minuteStep();
			var values = [];
			for ( var i = 0; i < 60; i += step ) {
				values.push( i );
			}
			if ( null !== current && values.indexOf( current ) === -1 ) {
				values.push( current );
				values.sort( function ( a, b ) {
					return a - b;
				} );
			}
			var key = values.join( ',' );
			if ( els.minute.dataset.values !== key ) {
				els.minute.dataset.values = key;
				els.minute.innerHTML = '';
				values.forEach( function ( v ) {
					els.minute.appendChild( el( 'option', '', { value: String( v ), text: pad( v ) } ) );
				} );
			}
		}

		function syncTime() {
			var t = parts( raw() ).time || nowTime();
			fillMinutes( t.i );
			if ( fmt.hour12 ) {
				els.hour.value = String( t.h % 12 || 12 );
				els.period.value = t.h >= 12 ? 'pm' : 'am';
			} else {
				els.hour.value = String( t.h );
			}
			els.minute.value = String( t.i );
		}

		function onTimeChange() {
			var h = parseInt( els.hour.value, 10 );
			if ( fmt.hour12 ) {
				h = ( h % 12 ) + ( 'pm' === els.period.value ? 12 : 0 );
			}
			var time = { h: h, i: parseInt( els.minute.value, 10 ) };
			var day = parts( raw() ).day || state.focus || today();
			setValue( compose( day, time ) );
			syncTime();
			if ( hasDate ) {
				render();
			}
		}

		function pickToday() {
			if ( 'time' === mode ) {
				setValue( compose( null, nowTime() ) );
				close( 'pick' );
				return;
			}
			var t = today();
			if ( dayDisabled( t ) ) {
				return;
			}
			pickDay( t );
		}

		function pickDay( dt ) {
			if ( dayDisabled( dt ) ) {
				return;
			}
			if ( 'date' === mode ) {
				setValue( compose( dt ) );
				close( 'pick' );
				return;
			}
			setValue( compose( dt, parts( raw() ).time || nowTime() ) );
			state.focus = dt;
			state.y = dt.getFullYear();
			state.m = dt.getMonth();
			syncTime();
			render( true );
		}

		function announce( text ) {
			if ( els.live ) {
				els.live.textContent = text;
			}
		}

		/** Déplace le jour actif de days jours, months mois (le jour reste dans le mois). */
		function shiftFocus( days, months, focusIt ) {
			var f = state.focus || makeDate( state.y, state.m, 1 );
			var next;
			if ( months ) {
				var y = f.getFullYear();
				var m = f.getMonth() + months;
				var first = makeDate( y, m, 1 );
				next = makeDate( first.getFullYear(), first.getMonth(), Math.min( f.getDate(), daysIn( first.getFullYear(), first.getMonth() ) ) );
			} else {
				next = makeDate( f.getFullYear(), f.getMonth(), f.getDate() + days );
			}
			moveTo( next, false !== focusIt );
		}

		function moveTo( dt, focusIt ) {
			var changed = dt.getFullYear() !== state.y || dt.getMonth() !== state.m;
			state.focus = dt;
			state.y = dt.getFullYear();
			state.m = dt.getMonth();
			render( focusIt );
			if ( changed ) {
				announce( capitalize( fmt.monthYear.format( makeDate( state.y, state.m, 1 ) ) ) );
			}
		}

		function weekdayOrder() {
			var out = [];
			for ( var k = 0; k < 7; k++ ) {
				out.push( ( fmt.start + k ) % 7 );
			}
			return out;
		}

		function render( focusIt ) {
			if ( ! dialog || ! hasDate ) {
				return;
			}
			var view = state.view;
			dialog.dataset.view = view;
			var first = makeDate( state.y, state.m, 1 );
			if ( 'days' === view ) {
				els.titleText.textContent = capitalize( fmt.monthYear.format( first ) );
				/* translators: %s: a month and its year (e.g. August 2026). */
				els.title.setAttribute( 'aria-label', sprintf( __( '%s, choose a month', 'okno' ), capitalize( fmt.monthYear.format( first ) ) ) );
				els.prev.setAttribute( 'aria-label', __( 'Previous month', 'okno' ) );
				els.next.setAttribute( 'aria-label', __( 'Next month', 'okno' ) );
				renderDays( focusIt );
			} else if ( 'months' === view ) {
				els.titleText.textContent = String( state.y );
				/* translators: %s: a year. */
				els.title.setAttribute( 'aria-label', sprintf( __( '%s, choose a year', 'okno' ), state.y ) );
				els.prev.setAttribute( 'aria-label', __( 'Previous year', 'okno' ) );
				els.next.setAttribute( 'aria-label', __( 'Next year', 'okno' ) );
				renderMonths( focusIt );
			} else {
				els.titleText.textContent = String( state.y );
				els.title.setAttribute( 'aria-label', __( 'Back to the calendar', 'okno' ) );
				renderYears( focusIt );
			}
			els.prev.hidden = els.next.hidden = 'years' === view;
			els.title.setAttribute( 'aria-expanded', 'days' === view ? 'false' : 'true' );
			if ( els.today ) {
				els.today.disabled = dayDisabled( today() );
			}
			if ( els.erase ) {
				els.erase.hidden = required();
			}
			position();
		}

		function renderDays( focusIt ) {
			var selected = parts( raw() ).day;
			var selKey = selected ? ymd( selected ) : '';
			var todayKey = ymd( today() );
			var focusKey = ymd( state.focus );
			var table = el( 'table', 'okno-datepicker__grid', { role: 'grid', 'aria-labelledby': els.title.id } );
			var thead = el( 'thead' );
			var hr = el( 'tr' );
			// Le 2 janvier 2000 est un dimanche : 2 + n donne le jour n.
			weekdayOrder().forEach( function ( wd ) {
				var ref = makeDate( 2000, 0, 2 + wd );
				var th = el( 'th', '', {
					scope: 'col',
					abbr: fmt.weekday.format( ref ),
					text: fmt.weekdayShort.format( ref ).replace( /\.$/, '' ),
				} );
				hr.appendChild( th );
			} );
			thead.appendChild( hr );
			table.appendChild( thead );
			var tbody = el( 'tbody' );
			var lead = ( makeDate( state.y, state.m, 1 ).getDay() - fmt.start + 7 ) % 7;
			var cursor = makeDate( state.y, state.m, 1 - lead );
			for ( var w = 0; w < 6; w++ ) {
				var tr = el( 'tr' );
				for ( var d = 0; d < 7; d++ ) {
					var key = ymd( cursor );
					var td = el( 'td', 'okno-datepicker__day', {
						role: 'gridcell',
						'data-date': key,
						tabindex: key === focusKey ? '0' : '-1',
						'aria-selected': key === selKey ? 'true' : 'false',
						'aria-label': fmt.full.format( cursor ),
						text: String( cursor.getDate() ),
					} );
					if ( cursor.getMonth() !== state.m ) {
						td.classList.add( 'is-outside' );
					}
					if ( key === todayKey ) {
						td.classList.add( 'is-today' );
						td.setAttribute( 'aria-current', 'date' );
					}
					if ( dayDisabled( cursor ) ) {
						td.setAttribute( 'aria-disabled', 'true' );
					}
					tr.appendChild( td );
					cursor = makeDate( cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1 );
				}
				tbody.appendChild( tr );
			}
			table.appendChild( tbody );
			table.addEventListener( 'click', function ( e ) {
				var cell = e.target.closest( 'td[data-date]' );
				if ( cell && 'true' !== cell.getAttribute( 'aria-disabled' ) ) {
					pickDay( parseDay( cell.dataset.date ) );
				}
			} );
			table.addEventListener( 'keydown', onGridKey );
			els.body.innerHTML = '';
			els.body.appendChild( table );
			if ( focusIt ) {
				var cell = table.querySelector( '[tabindex="0"]' );
				if ( cell ) {
					cell.focus( { preventScroll: true } );
				}
			}
		}

		function onGridKey( e ) {
			var handled = true;
			var f = state.focus;
			switch ( e.key ) {
				case 'ArrowLeft':
					shiftFocus( -1 );
					break;
				case 'ArrowRight':
					shiftFocus( 1 );
					break;
				case 'ArrowUp':
					shiftFocus( -7 );
					break;
				case 'ArrowDown':
					shiftFocus( 7 );
					break;
				case 'PageUp':
					shiftFocus( 0, e.shiftKey ? -12 : -1 );
					break;
				case 'PageDown':
					shiftFocus( 0, e.shiftKey ? 12 : 1 );
					break;
				case 'Home':
					shiftFocus( -( ( f.getDay() - fmt.start + 7 ) % 7 ) );
					break;
				case 'End':
					shiftFocus( 6 - ( ( f.getDay() - fmt.start + 7 ) % 7 ) );
					break;
				case 'Enter':
				case ' ':
					pickDay( f );
					break;
				default:
					handled = false;
			}
			if ( handled ) {
				e.preventDefault();
			}
		}

		/** Grille de boutons (mois, années) : flèches, Début / Fin, Entrée. */
		function choiceGrid( cls, items, cols, onChoose, focusIt ) {
			var grid = el( 'div', 'okno-datepicker__choices ' + cls, { role: 'group', 'aria-labelledby': els.title.id } );
			var active = 0;
			items.forEach( function ( item, i ) {
				var b = el( 'button', 'okno-datepicker__choice', {
					type: 'button',
					tabindex: item.active ? '0' : '-1',
					'aria-pressed': item.selected ? 'true' : 'false',
					text: item.label,
				} );
				if ( item.label !== item.name ) {
					b.setAttribute( 'aria-label', item.name );
				}
				if ( item.current ) {
					b.classList.add( 'is-today' );
				}
				if ( item.disabled ) {
					b.disabled = true;
				}
				if ( item.active ) {
					active = i;
				}
				b.addEventListener( 'click', function () {
					onChoose( item.value );
				} );
				grid.appendChild( b );
			} );
			grid.addEventListener( 'keydown', function ( e ) {
				var buttons = [].slice.call( grid.children );
				var i = buttons.indexOf( document.activeElement );
				if ( i < 0 ) {
					return;
				}
				var to = { ArrowLeft: i - 1, ArrowRight: i + 1, ArrowUp: i - cols, ArrowDown: i + cols, Home: 0, End: buttons.length - 1 }[ e.key ];
				if ( undefined === to ) {
					return;
				}
				e.preventDefault();
				to = Math.max( 0, Math.min( buttons.length - 1, to ) );
				buttons[ i ].tabIndex = -1;
				buttons[ to ].tabIndex = 0;
				buttons[ to ].focus();
				if ( buttons[ to ].scrollIntoView ) {
					buttons[ to ].scrollIntoView( { block: 'nearest' } );
				}
			} );
			els.body.innerHTML = '';
			els.body.appendChild( grid );
			var target = grid.children[ active ];
			if ( target && target.scrollIntoView ) {
				target.scrollIntoView( { block: 'center' } );
			}
			if ( focusIt && target ) {
				target.focus( { preventScroll: true } );
			}
		}

		function renderMonths( focusIt ) {
			var selected = parts( raw() ).day;
			var now = today();
			var min = bound( 'min' ).slice( 0, 7 );
			var max = bound( 'max' ).slice( 0, 7 );
			var items = [];
			for ( var m = 0; m < 12; m++ ) {
				var dt = makeDate( state.y, m, 1 );
				var key = ymd( dt ).slice( 0, 7 );
				items.push( {
					value: m,
					label: capitalize( fmt.monthShort.format( dt ).replace( /\.$/, '' ) ),
					name: capitalize( fmt.monthYear.format( dt ) ),
					selected: !! selected && selected.getFullYear() === state.y && selected.getMonth() === m,
					current: now.getFullYear() === state.y && now.getMonth() === m,
					active: m === state.m,
					disabled: ( min && key < min ) || ( max && key > max ),
				} );
			}
			choiceGrid( 'okno-datepicker__months', items, 3, function ( m ) {
				var f = state.focus || makeDate( state.y, m, 1 );
				state.view = 'days';
				moveTo( makeDate( state.y, m, Math.min( f.getDate(), daysIn( state.y, m ) ) ), true );
			}, focusIt );
		}

		function renderYears( focusIt ) {
			var selected = parts( raw() ).day;
			var now = today().getFullYear();
			var min = parseInt( bound( 'min' ).slice( 0, 4 ), 10 );
			var max = parseInt( bound( 'max' ).slice( 0, 4 ), 10 );
			var from = Math.min( isNaN( min ) ? now - 100 : min, state.y );
			var to = Math.max( isNaN( max ) ? now + 20 : max, state.y );
			var items = [];
			for ( var y = from; y <= to; y++ ) {
				items.push( {
					value: y,
					label: String( y ),
					name: String( y ),
					selected: !! selected && selected.getFullYear() === y,
					current: now === y,
					active: y === state.y,
				} );
			}
			choiceGrid( 'okno-datepicker__years', items, 4, function ( y ) {
				state.y = y;
				state.view = 'months';
				render( true );
			}, focusIt );
		}

		function onDialogKey( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				e.stopPropagation();
				close( 'escape' );
			} else if ( 'Tab' === e.key ) {
				// Non modal : sortir du calendrier au clavier le referme et
				// rend le focus au champ.
				var list = [].filter.call( dialog.querySelectorAll( 'button, [tabindex="0"]' ), function ( b ) {
					return b.tabIndex >= 0 && ! b.disabled && ! b.hidden && null !== b.offsetParent;
				} );
				var first = list[ 0 ];
				var last = list[ list.length - 1 ];
				if ( ( e.shiftKey && document.activeElement === first ) || ( ! e.shiftKey && document.activeElement === last ) ) {
					e.preventDefault();
					close( 'escape' );
				}
			}
		}

		function onDocDown( e ) {
			var t = e.target;
			if ( dialog.contains( t ) || wrap.contains( t ) || ( t.closest && t.closest( '.okno-listbox' ) ) ) {
				return;
			}
			close( 'outside' );
		}

		function onReflow( e ) {
			if ( e && 'scroll' === e.type && e.target && e.target.nodeType === 1 && ( dialog.contains( e.target ) || ( e.target.closest && e.target.closest( '.okno-listbox' ) ) ) ) {
				return;
			}
			position();
		}

		/** Sous le champ si la place le permet, sinon au-dessus ; toujours dans la fenêtre. */
		function position() {
			if ( ! isOpen ) {
				return;
			}
			var r = button.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			var vh = document.documentElement.clientHeight;
			if ( r.bottom < 0 || r.top > vh ) {
				close( 'scroll' );
				return;
			}
			var gap = 4;
			var margin = 8;
			var h = dialog.offsetHeight;
			var w = dialog.offsetWidth;
			var below = vh - r.bottom - gap - margin;
			var above = r.top - gap - margin;
			var top = below >= h || below >= above ? r.bottom + gap : r.top - gap - h;
			dialog.style.top = Math.max( margin, Math.min( top, vh - h - margin ) ) + 'px';
			dialog.style.left = Math.max( margin, Math.min( r.left, vw - w - margin ) ) + 'px';
		}

		function open() {
			if ( isOpen || button.disabled ) {
				return;
			}
			if ( ! dialog ) {
				buildDialog();
			}
			var p = parts( raw() );
			var start = p.day || today();
			var min = parseDay( bound( 'min' ) );
			var max = parseDay( bound( 'max' ) );
			if ( ! p.day && min && start < min ) {
				start = min;
			} else if ( ! p.day && max && start > max ) {
				start = max;
			}
			state.view = 'days';
			state.focus = start;
			state.y = start.getFullYear();
			state.m = start.getMonth();
			dialog.setAttribute( 'aria-label', fieldLabel() || placeholder() );
			isOpen = true;
			layerFor( wrap ).appendChild( dialog );
			button.setAttribute( 'aria-expanded', 'true' );
			button.setAttribute( 'aria-controls', dialog.id );
			if ( hasTime ) {
				syncTime();
			}
			if ( hasDate ) {
				render( true );
			} else {
				if ( els.erase ) {
					els.erase.hidden = required();
				}
				position();
				els.hour.focus( { preventScroll: true } );
			}
			document.addEventListener( 'mousedown', onDocDown, true );
			document.addEventListener( 'touchstart', onDocDown, true );
			window.addEventListener( 'resize', onReflow );
			window.addEventListener( 'scroll', onReflow, true );
		}

		function close( reason ) {
			if ( ! isOpen ) {
				return;
			}
			isOpen = false;
			[ els.hour, els.minute, els.period ].forEach( function ( s ) {
				if ( s && s.oknoSelect ) {
					s.oknoSelect.close();
				}
			} );
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			button.setAttribute( 'aria-expanded', 'false' );
			button.removeAttribute( 'aria-controls' );
			document.removeEventListener( 'mousedown', onDocDown, true );
			document.removeEventListener( 'touchstart', onDocDown, true );
			window.removeEventListener( 'resize', onReflow );
			window.removeEventListener( 'scroll', onReflow, true );
			if ( 'outside' !== reason && 'scroll' !== reason && 'tab' !== reason ) {
				button.focus( { preventScroll: true } );
			}
		}

		/* ---- Évènements du champ ---- */

		button.addEventListener( 'click', function () {
			if ( isOpen ) {
				close( 'toggle' );
			} else {
				open();
			}
		} );
		button.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				open();
			} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && ! clear.hidden ) {
				e.preventDefault();
				setValue( '' );
			}
		} );
		// Le code qui écoute le focus de l'input (éditeur : champ suivi dans
		// l'aperçu) le reçoit quand le bouton le prend.
		button.addEventListener( 'focus', function () {
			input.dispatchEvent( new FocusEvent( 'focus' ) );
		} );
		clear.addEventListener( 'click', function () {
			setValue( '' );
			button.focus();
		} );

		// L'input reste la source de vérité : valeur, bornes, état.
		Object.defineProperty( input, 'value', {
			configurable: true,
			get: function () {
				return inputValueDesc.get.call( input );
			},
			set: function ( v ) {
				inputValueDesc.set.call( input, v );
				sync();
				if ( isOpen ) {
					if ( hasTime ) {
						syncTime();
					}
					render();
				}
			},
		} );
		input.focus = function ( options ) {
			button.focus( options );
		};
		if ( input.form ) {
			input.form.addEventListener( 'reset', function () {
				setTimeout( sync, 0 );
			} );
		}
		new MutationObserver( sync ).observe( input, {
			attributes: true,
			attributeFilter: [ 'hidden', 'disabled', 'readonly', 'required', 'class', 'title', 'placeholder', 'aria-invalid', 'aria-label', 'aria-required', 'aria-describedby', 'min', 'max' ],
		} );

		input.classList.add( 'okno-date-native' );
		input.setAttribute( 'tabindex', '-1' );
		input.setAttribute( 'aria-hidden', 'true' );
		input.parentNode.insertBefore( wrap, input.nextSibling );
		input.oknoDate = api;
		api.sync = sync;
		api.open = open;
		api.close = function () {
			close( 'api' );
		};
		sync();
		return api;
	}

	/* ------------------------------------------------------------------ *
	 * Thème : sombre par défaut ; le choix est gardé côté serveur (méta
	 * utilisateur, imprimée par PHP au premier rendu : pas de flash), en
	 * cookie et dans le navigateur.
	 * ------------------------------------------------------------------ */

	var themeCfg = window.OknoTheme || {};

	function currentTheme() {
		var t = document.documentElement.getAttribute( 'data-okno-theme' );
		return 'light' === t ? 'light' : 'dark';
	}

	function saveTheme( theme ) {
		theme = 'light' === theme ? 'light' : 'dark';
		document.documentElement.setAttribute( 'data-okno-theme', theme );
		try {
			window.localStorage.setItem( 'okno-theme', theme );
		} catch ( e ) {
			/* Stockage indisponible : le cookie et le serveur suffisent. */
		}
		document.cookie = 'okno_theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
		themeCfg.saved = theme;
		if ( window.wp && window.wp.apiFetch ) {
			window.wp
				.apiFetch( { path: '/okno/v1/preferences', method: 'POST', data: { theme: theme } } )
				.catch( function () {
					/* Hors ligne : le cookie prendra le relais. */
				} );
		}
		return theme;
	}

	window.OknoUI = {
		select: enhanceSelect,
		date: enhanceDate,
		autoSelects: autoSelects,
		chips: chips,
		theme: { current: currentTheme, save: saveTheme },
	};

	// Toute l'application : listes déroulantes stylées, y compris celles que
	// les vues ajoutent plus tard (formulaires d'entrée, réglages, filtres).
	function boot() {
		autoSelects( document.getElementById( 'okno-app' ) );
		// Choix fait avant 1.0.0-beta.9 (navigateur seulement) : recopié une
		// fois côté serveur, pour que PHP l'imprime dès le premier rendu.
		var stored = null;
		try {
			stored = window.localStorage.getItem( 'okno-theme' );
		} catch ( e ) {
			stored = null;
		}
		if ( ! themeCfg.saved && ( 'light' === stored || 'dark' === stored ) && document.getElementById( 'okno-app' ) ) {
			saveTheme( stored );
		}
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
