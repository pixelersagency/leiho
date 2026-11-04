/**
 * Okno — composants d'interface partagés : window.OknoUI.
 *
 * - select( el )   : remplace l'apparence d'un <select> par un bouton et une
 *                    liste (role listbox) stylés. Le <select> reste dans la
 *                    page, masqué : sa valeur, ses évènements change et les
 *                    formulaires PHP fonctionnent comme avant.
 * - autoSelects( root ) : applique select() à tous les <select> simples de
 *                    root, y compris ceux ajoutés plus tard.
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
		if ( target && target.oknoSelect ) {
			e.preventDefault();
			target.oknoSelect.button.focus();
		}
	} );

	function autoSelects( root ) {
		if ( ! root ) {
			return;
		}
		[].forEach.call( root.querySelectorAll( 'select' ), enhanceSelect );
		var pending = false;
		new MutationObserver( function ( records ) {
			if ( pending ) {
				return;
			}
			var found = records.some( function ( r ) {
				return [].some.call( r.addedNodes, function ( n ) {
					return 1 === n.nodeType && ( 'SELECT' === n.tagName || ( n.querySelector && n.querySelector( 'select' ) ) );
				} );
			} );
			if ( ! found ) {
				return;
			}
			pending = true;
			// Après le rendu en cours : les options sont posées, la valeur aussi.
			Promise.resolve().then( function () {
				pending = false;
				[].forEach.call( root.querySelectorAll( 'select' ), enhanceSelect );
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
