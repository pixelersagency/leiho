/**
 * Leiho — coque de l'application plein écran.
 *
 * Navigation latérale (repliable, au clavier), barre du haut (publication,
 * thème, menu utilisateur) et utilitaires partagés par les vues JavaScript
 * (Collections, Médias) : window.LeihoApp.
 *
 * Vanilla JS + apis WordPress (wp.apiFetch, wp.i18n). Aucun build.
 */
( function () {
	'use strict';

	var cfg = window.LeihoAppConfig || {};
	var root = document.getElementById( 'leiho-app' );
	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	if ( ! root || ! apiFetch ) {
		return;
	}

	document.documentElement.classList.add( 'leiho-app-html' );

	/* ------------------------------------------------------------------ *
	 * Utilitaires DOM
	 * ------------------------------------------------------------------ */

	var uidCount = 0;

	function uid( prefix ) {
		uidCount += 1;
		return 'leiho-' + ( prefix || 'id' ) + '-' + uidCount;
	}

	/**
	 * Crée un élément : h( 'button', { class: 'x', onClick: fn, text: 'OK' }, [ enfants ] ).
	 * Les attributs aria-*, data-*, role, for… passent par setAttribute ;
	 * text remplit textContent (jamais de HTML injecté).
	 */
	function h( tag, attrs, children ) {
		var el = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( name ) {
			var value = attrs[ name ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'class' === name ) {
				el.className = value;
			} else if ( 'text' === name ) {
				el.textContent = value;
			} else if ( 'dataset' === name ) {
				Object.keys( value ).forEach( function ( k ) {
					el.dataset[ k ] = value[ k ];
				} );
			} else if ( 0 === name.indexOf( 'on' ) && 'function' === typeof value ) {
				el.addEventListener( name.slice( 2 ).toLowerCase(), value );
			} else if ( [ 'value', 'checked', 'disabled', 'hidden', 'selected', 'required', 'multiple', 'readOnly', 'type', 'name', 'id', 'href', 'min', 'max', 'step', 'placeholder', 'draggable' ].indexOf( name ) !== -1 ) {
				el[ name ] = value;
			} else {
				el.setAttribute( name, true === value ? '' : value );
			}
		} );
		append( el, children );
		return el;
	}

	function append( el, children ) {
		if ( null === children || undefined === children || false === children ) {
			return el;
		}
		if ( ! Array.isArray( children ) ) {
			children = [ children ];
		}
		children.forEach( function ( child ) {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			if ( Array.isArray( child ) ) {
				append( el, child );
			} else {
				el.appendChild( 'object' === typeof child ? child : document.createTextNode( String( child ) ) );
			}
		} );
		return el;
	}

	function clear( el ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	}

	var ICONS = {
		plus: 'M12 5v14 M5 12h14',
		close: 'M6 6l12 12 M18 6L6 18',
		edit: 'M4 20h4L19 9l-4-4L4 16z M13 7l4 4',
		form: 'M5 4h14v16H5z M8 8h8 M8 12h8 M8 16h5',
		copy: 'M9 9h11v11H9z M5 15H4V4h11v1',
		trash: 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13',
		restore: 'M3 12a9 9 0 1 0 3-6.7 M3 4v5h5',
		search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z M20 20l-4-4',
		grip: 'M9 6h.01 M15 6h.01 M9 12h.01 M15 12h.01 M9 18h.01 M15 18h.01',
		up: 'M6 15l6-6 6 6',
		down: 'M6 9l6 6 6-6',
		chevron: 'M9 5l7 7-7 7',
		external: 'M14 4h6v6 M20 4l-9 9 M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
		upload: 'M12 16V4 M7 9l5-5 5 5 M4 16v4h16v-4',
		download: 'M12 4v12 M7 11l5 5 5-5 M4 20h16',
		database: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6 M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
		page: 'M6 2h7l5 5v15H6z M13 2v5h5',
		image: 'M3 5h18v14H3z M3 16l5-5 4 4 3-3 6 6 M15.5 9h.01',
		moon: 'M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z',
		sun: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z M12 3v2 M12 19v2 M3 12h2 M19 12h2 M5.6 5.6l1.4 1.4 M17 17l1.4 1.4 M5.6 18.4L7 17 M17 7l1.4-1.4',
		warning: 'M12 3l10 18H2z M12 10v4 M12 17h.01',
		check: 'M5 12.5l4.5 4.5L19 7.5',
		draft: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16z M9 12h6',
		// Types de champ (sélecteur « Ajouter un champ »).
		'type-text': 'M5 6h14 M12 6v13 M9 19h6',
		'type-richtext': 'M4 6h16 M4 10h16 M4 14h10 M4 18h7 M17 14l3 3-3 3',
		'type-number': 'M9 4L7 20 M17 4l-2 16 M4 9h17 M3 15h17',
		'type-boolean': 'M8 7h8a5 5 0 0 1 0 10H8A5 5 0 0 1 8 7z M16 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
		'type-date': 'M4 6h16v14H4z M4 10h16 M8 3v4 M16 3v4',
		'type-datetime': 'M4 6h11v14H4z M4 10h11 M8 3v4 M17.5 12a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z M17.5 14v1.5l1 1',
		'type-select': 'M4 5h16v6H4z M9 7.5l1.5 1.5L12 7.5 M4 14h16 M4 18h10',
		'type-slug': 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1 M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
		'type-email': 'M3 6h18v12H3z M3 7l9 6 9-6',
		'type-url': 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M3 12h18 M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9 M12 3c-2.5 2.5-3.5 5.5-3.5 9s1 6.5 3.5 9',
		'type-color': 'M12 3c4 4.5 6 7.5 6 10.5a6 6 0 0 1-12 0C6 10.5 8 7.5 12 3z',
		'type-image': 'M3 5h18v14H3z M3 16l5-5 4 4 3-3 6 6 M15.5 9h.01',
		'type-file': 'M6 2h8l5 5v15H6z M14 2v5h5 M9 13h7 M9 17h5',
		'type-gallery': 'M7 3h14v14H7z M7 13l4-4 3 3 2-2 5 5 M3 7v14h14',
		'type-link': 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.5 1.5 M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.5-1.5',
		'type-reference': 'M4 4h7v7H4z M13 13h7v7h-7z M11 7.5h4.5v5.5',
		'type-list': 'M8 6h12 M8 12h12 M8 18h12 M4 6h.01 M4 12h.01 M4 18h.01',
		'type-blocks': 'M4 4h16v6H4z M4 14h7v6H4z M14 14h6v6h-6z',
		'type-group': 'M4 4h16v16H4z M8 9h8 M8 13h8 M8 17h5',
		archive: 'M3 4h18v4H3z M5 8v12h14V8 M10 12h4',
		list: 'M8 6h12 M8 12h12 M8 18h12 M4 6h.01 M4 12h.01 M4 18h.01',
		settings: 'M4 7h10 M18 7h2 M4 17h4 M12 17h8 M14 5v4 M10 15v4',
	};

	function icon( name, size ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'class', 'leiho-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', size || 16 );
		svg.setAttribute( 'height', size || 16 );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.8' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		( ICONS[ name ] || '' ).split( ' M' ).forEach( function ( d, i ) {
			var path = document.createElementNS( ns, 'path' );
			path.setAttribute( 'd', 0 === i ? d : 'M' + d );
			svg.appendChild( path );
		} );
		return svg;
	}

	/* ------------------------------------------------------------------ *
	 * Annonces, toasts, boîtes de dialogue
	 * ------------------------------------------------------------------ */

	var live = h( 'div', { class: 'leiho-sr', 'aria-live': 'polite', role: 'status' } );
	root.appendChild( live );

	function announce( message ) {
		live.textContent = '';
		setTimeout( function () {
			live.textContent = message;
		}, 50 );
	}

	var toasts = h( 'div', { class: 'leiho-toasts' } );
	root.appendChild( toasts );

	function toast( message, isError ) {
		var el = h( 'div', { class: 'leiho-toast' + ( isError ? ' leiho-toast--error' : '' ), role: isError ? 'alert' : 'status' }, message );
		toasts.appendChild( el );
		setTimeout(
			function () {
				el.classList.add( 'is-leaving' );
				setTimeout( function () {
					el.remove();
				}, 300 );
			},
			isError ? 7000 : 3500
		);
	}

	/**
	 * Boîte de dialogue modale accessible (focus piégé, Échap pour fermer,
	 * focus rendu à l'élément d'origine).
	 *
	 * @param {Object} options { title, body (Node|string), actions: [{ label, primary, danger, onClick( close ) }], wide }
	 * @return {{ close: Function, el: Element, body: Element }}
	 */
	function modal( options ) {
		var opener = document.activeElement;
		var titleId = uid( 'dialog-title' );
		var body = h( 'div', { class: 'leiho-dialog__body' }, options.body );
		var footer = h( 'div', { class: 'leiho-dialog__actions' } );
		var dialog = h(
			'div',
			{ class: 'leiho-dialog' + ( options.wide ? ' leiho-dialog--wide' : '' ), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
			[
				h( 'div', { class: 'leiho-dialog__head' }, [
					h( 'h2', { id: titleId, text: options.title } ),
					h( 'button', { type: 'button', class: 'leiho-icon-btn', 'aria-label': __( 'Close', 'leiho' ), onClick: function () {
						close( false );
					} }, icon( 'close' ) ),
				] ),
				body,
				footer,
			]
		);
		var backdrop = h( 'div', { class: 'leiho-backdrop' }, dialog );
		var closed = false;

		function close() {
			if ( closed ) {
				return;
			}
			closed = true;
			backdrop.remove();
			document.removeEventListener( 'keydown', onKey, true );
			if ( options.onClose ) {
				options.onClose();
			}
			if ( opener && opener.focus && document.contains( opener ) ) {
				opener.focus();
			}
		}

		function focusables() {
			return Array.prototype.filter.call(
				dialog.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
				function ( el ) {
					return ! el.disabled && el.offsetParent !== null;
				}
			);
		}

		function onKey( event ) {
			// Menu ou calendrier ouvert par-dessus : il gère lui-même Échap et Tab.
			if ( event.target && event.target.closest && event.target.closest( '.leiho-listbox, .leiho-datepicker' ) ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close();
			} else if ( 'Tab' === event.key ) {
				var list = focusables();
				if ( ! list.length ) {
					return;
				}
				var first = list[ 0 ];
				var last = list[ list.length - 1 ];
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		}

		( options.actions || [] ).forEach( function ( action ) {
			var btn = h( 'button', {
				type: 'button',
				class: 'leiho-btn' + ( action.primary ? '' : action.danger ? ' leiho-btn--danger' : ' leiho-btn--quiet' ),
				text: action.label,
				onClick: function () {
					if ( action.onClick ) {
						action.onClick( close, btn );
					} else {
						close();
					}
				},
			} );
			if ( action.id ) {
				btn.id = action.id;
			}
			action.el = btn;
			footer.appendChild( btn );
		} );

		backdrop.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === backdrop && ! options.sticky ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKey, true );
		root.appendChild( backdrop );

		var first = dialog.querySelector( '[autofocus]' ) || focusables()[ 1 ] || focusables()[ 0 ];
		if ( first ) {
			first.focus();
		}
		return { close: close, el: dialog, body: body, actions: options.actions || [] };
	}

	/**
	 * Confirmation. typeToConfirm : texte à recopier (actions destructrices).
	 *
	 * @return {Promise<boolean>}
	 */
	function confirmDialog( options ) {
		return new Promise( function ( resolve ) {
			var answered = false;
			var input = null;
			var children = [ h( 'p', { text: options.message } ) ];
			if ( options.typeToConfirm ) {
				var inputId = uid( 'confirm' );
				input = h( 'input', { type: 'text', id: inputId, class: 'leiho-input', autocomplete: 'off', spellcheck: 'false' } );
				children.push(
					h( 'label', { for: inputId, class: 'leiho-label' }, [
						/* translators: %s: text the user must type to confirm. */
						sprintf( __( 'Type %s to confirm', 'leiho' ), options.typeToConfirm ),
					] ),
					input
				);
			}
			var dialog = modal( {
				title: options.title,
				body: children,
				onClose: function () {
					if ( ! answered ) {
						resolve( false );
					}
				},
				actions: [
					{ label: options.cancelLabel || __( 'Cancel', 'leiho' ) },
					{
						label: options.confirmLabel || __( 'Confirm', 'leiho' ),
						primary: ! options.danger,
						danger: !! options.danger,
						onClick: function ( close ) {
							if ( input && input.value.trim() !== options.typeToConfirm ) {
								input.focus();
								input.setAttribute( 'aria-invalid', 'true' );
								return;
							}
							answered = true;
							resolve( true );
							close();
						},
					},
				],
			} );
			if ( input ) {
				input.focus();
				var confirmBtn = dialog.actions[ 1 ].el;
				confirmBtn.disabled = true;
				input.addEventListener( 'input', function () {
					confirmBtn.disabled = input.value.trim() !== options.typeToConfirm;
				} );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Formats
	 * ------------------------------------------------------------------ */

	var LOCALE = ( function () {
		var lang = document.documentElement.lang || undefined;
		try {
			new Intl.DateTimeFormat( lang );
			return lang;
		} catch ( e ) {
			return undefined;
		}
	} )();

	function formatDate( iso ) {
		if ( ! iso ) {
			return '';
		}
		var date = new Date( iso );
		if ( isNaN( date.getTime() ) ) {
			return '';
		}
		var diff = ( Date.now() - date.getTime() ) / 1000;
		if ( diff < 60 ) {
			return __( 'just now', 'leiho' );
		}
		if ( diff < 3600 ) {
			/* translators: %d: number of minutes. */
			return sprintf( wp.i18n._n( '%d minute ago', '%d minutes ago', Math.floor( diff / 60 ), 'leiho' ), Math.floor( diff / 60 ) );
		}
		if ( diff < 86400 ) {
			/* translators: %d: number of hours. */
			return sprintf( wp.i18n._n( '%d hour ago', '%d hours ago', Math.floor( diff / 3600 ), 'leiho' ), Math.floor( diff / 3600 ) );
		}
		return date.toLocaleDateString( LOCALE, { day: 'numeric', month: 'short', year: 'numeric' } );
	}

	/** « Témoignages clients » → « temoignages_clients » (ids et noms de champ). */
	function slugify( text, max ) {
		var out = String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' );
		if ( out && ! /^[a-z]/.test( out ) ) {
			out = 'f_' + out;
		}
		return out.slice( 0, max || 64 ).replace( /_+$/, '' );
	}

	function errorMessage( err, fallback ) {
		return ( err && err.message ) || fallback || __( 'Something went wrong.', 'leiho' );
	}

	/* ------------------------------------------------------------------ *
	 * Thème clair / sombre (partagé avec l'éditeur)
	 * ------------------------------------------------------------------ */

	var themeBtn = document.getElementById( 'leiho-app-theme' );

	function storage( key, value ) {
		try {
			if ( undefined === value ) {
				return window.localStorage.getItem( key );
			}
			window.localStorage.setItem( key, value );
		} catch ( e ) {
			/* Stockage indisponible (navigation privée) : rien de grave. */
		}
		return null;
	}

	function applyTheme( theme ) {
		root.dataset.theme = theme;
		if ( themeBtn ) {
			clear( themeBtn ).appendChild( icon( 'dark' === theme ? 'sun' : 'moon' ) );
			themeBtn.setAttribute( 'aria-pressed', 'dark' === theme ? 'true' : 'false' );
			themeBtn.setAttribute( 'aria-label', 'dark' === theme ? __( 'Switch to light theme', 'leiho' ) : __( 'Switch to dark theme', 'leiho' ) );
		}
	}

	// Sombre par défaut, comme une application de bureau. Le thème est déjà
	// posé par PHP au premier rendu (méta utilisateur) ; le choix est gardé
	// côté serveur, en cookie et dans le navigateur (LeihoUI.theme).
	var themeApi = window.LeihoUI && window.LeihoUI.theme;
	applyTheme( themeApi ? themeApi.current() : 'light' === ( storage( 'leiho-theme' ) || storage( 'okno-theme' ) ) ? 'light' : 'dark' ); // okno-theme : nom d'avant Leiho.

	if ( themeBtn ) {
		themeBtn.addEventListener( 'click', function () {
			var next = 'dark' === root.dataset.theme ? 'light' : 'dark';
			if ( themeApi ) {
				themeApi.save( next );
			} else {
				storage( 'leiho-theme', next );
			}
			applyTheme( next );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Navigation : repliable (bureau), tiroir (mobile)
	 * ------------------------------------------------------------------ */

	var nav = document.getElementById( 'leiho-nav' );
	var collapseBtn = document.getElementById( 'leiho-nav-collapse' );
	var navToggle = document.getElementById( 'leiho-nav-toggle' );
	var embedded = root.classList.contains( 'leiho-app--embedded' );

	function setCollapsed( collapsed, persist ) {
		root.classList.toggle( 'leiho-app--collapsed', collapsed );
		if ( collapseBtn ) {
			collapseBtn.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			var label = collapsed ? __( 'Expand the menu', 'leiho' ) : __( 'Collapse the menu', 'leiho' );
			collapseBtn.title = label;
			var span = collapseBtn.querySelector( '.leiho-nav__label' );
			if ( span ) {
				span.textContent = label;
			}
		}
		if ( persist ) {
			storage( 'leiho-nav', collapsed ? 'collapsed' : 'expanded' );
		}
	}

	// Rail d'icônes par défaut (les volets ont besoin de place) ; l'éditeur
	// reste replié quel que soit le choix enregistré.
	setCollapsed( embedded || 'expanded' !== storage( 'leiho-nav' ), false );

	if ( collapseBtn ) {
		collapseBtn.addEventListener( 'click', function () {
			setCollapsed( ! root.classList.contains( 'leiho-app--collapsed' ), ! embedded );
		} );
	}

	function setDrawer( open ) {
		root.classList.toggle( 'leiho-app--nav-open', open );
		if ( navToggle ) {
			navToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			navToggle.setAttribute( 'aria-label', open ? __( 'Close the menu', 'leiho' ) : __( 'Open the menu', 'leiho' ) );
		}
		if ( open ) {
			var current = nav.querySelector( '[aria-current="page"]' ) || nav.querySelector( 'a' );
			if ( current ) {
				current.focus();
			}
		}
	}

	if ( navToggle ) {
		navToggle.addEventListener( 'click', function () {
			setDrawer( ! root.classList.contains( 'leiho-app--nav-open' ) );
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && root.classList.contains( 'leiho-app--nav-open' ) ) {
			setDrawer( false );
			if ( navToggle ) {
				navToggle.focus();
			}
		}
	} );

	root.addEventListener( 'click', function ( event ) {
		if ( root.classList.contains( 'leiho-app--nav-open' ) && ! nav.contains( event.target ) && navToggle && ! navToggle.contains( event.target ) ) {
			setDrawer( false );
		}
	} );

	// Flèches haut/bas entre les liens de la navigation.
	nav.addEventListener( 'keydown', function ( event ) {
		if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {
			return;
		}
		var links = Array.prototype.slice.call( nav.querySelectorAll( '.leiho-nav__link' ) );
		var index = links.indexOf( document.activeElement );
		if ( -1 === index ) {
			return;
		}
		event.preventDefault();
		var next = links[ ( index + ( 'ArrowDown' === event.key ? 1 : links.length - 1 ) ) % links.length ];
		next.focus();
	} );

	/* ------------------------------------------------------------------ *
	 * Menu utilisateur
	 * ------------------------------------------------------------------ */

	var userBtn = document.getElementById( 'leiho-user-button' );
	var userMenu = document.getElementById( 'leiho-user-menu' );

	function setUserMenu( open, focusFirst ) {
		userMenu.hidden = ! open;
		userBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( open && focusFirst ) {
			var first = userMenu.querySelector( '[role="menuitem"]' );
			if ( first ) {
				first.focus();
			}
		}
	}

	if ( userBtn && userMenu ) {
		userBtn.addEventListener( 'click', function () {
			setUserMenu( userMenu.hidden, false );
		} );
		userBtn.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				setUserMenu( true, true );
			}
		} );
		userMenu.addEventListener( 'keydown', function ( event ) {
			var items = Array.prototype.slice.call( userMenu.querySelectorAll( '[role="menuitem"]' ) );
			var index = items.indexOf( document.activeElement );
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				setUserMenu( false );
				userBtn.focus();
			} else if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				items[ ( index + ( 'ArrowDown' === event.key ? 1 : items.length - 1 ) ) % items.length ].focus();
			} else if ( 'Tab' === event.key ) {
				setUserMenu( false );
			}
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! userMenu.hidden && ! userMenu.contains( event.target ) && ! userBtn.contains( event.target ) ) {
				setUserMenu( false );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Publication (barre du haut)
	 * ------------------------------------------------------------------ */

	var publishBtn = document.getElementById( 'leiho-app-publish' );
	var pendingDot = document.getElementById( 'leiho-app-pending' );
	var statusEl = document.getElementById( 'leiho-app-deploy' );
	var publish = cfg.publish || {};
	var pollTimer = null;
	var publishing = false;

	function setStatus( text, level ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = text || '';
		statusEl.className = 'leiho-topbar__status' + ( level ? ' is-' + level : '' );
	}

	function renderPending( state ) {
		if ( ! publishBtn ) {
			return;
		}
		pendingDot.hidden = ! state.pending;
		publishBtn.title = state.pending
			? state.auto
				? __( 'Changes are waiting to be published. They will be published automatically.', 'leiho' )
				: __( 'Some changes aren’t published yet.', 'leiho' )
			: __( 'Everything is published', 'leiho' );
		publishBtn.setAttribute(
			'aria-label',
			state.pending ? __( 'Publish (unpublished changes)', 'leiho' ) : __( 'Publish', 'leiho' )
		);
		if ( state.active && ! publishing ) {
			setStatus( __( 'Publishing…', 'leiho' ), 'busy' );
			if ( publish.tracks ) {
				poll( state.active );
			}
		} else if ( ! publishing && ! state.active && statusEl && statusEl.classList.contains( 'is-busy' ) ) {
			setStatus( '' );
		}
	}

	function refreshPublishState() {
		if ( ! publishBtn ) {
			return Promise.resolve();
		}
		return apiFetch( { path: '/leiho/v1/deploy/state' } )
			.then( renderPending )
			.catch( function () {
				/* Pas grave : l'indicateur se remettra à jour plus tard. */
			} );
	}

	function poll( id ) {
		clearTimeout( pollTimer );
		pollTimer = setTimeout( function () {
			apiFetch( { path: '/leiho/v1/deploy/' + encodeURIComponent( id ) } )
				.then( function ( record ) {
					if ( 'pending' === record.status || 'building' === record.status ) {
						setStatus( __( 'Publishing…', 'leiho' ), 'busy' );
						poll( id );
						return;
					}
					publishing = false;
					if ( publishBtn ) {
						publishBtn.disabled = false;
					}
					if ( 'success' === record.status || 'triggered' === record.status ) {
						setStatus( __( 'Published', 'leiho' ), 'ok' );
						toast( __( 'Your site is up to date.', 'leiho' ) );
					} else {
						setStatus( __( 'Publishing failed', 'leiho' ), 'error' );
						toast( record.message || __( 'Publishing failed.', 'leiho' ), true );
					}
					refreshPublishState();
				} )
				.catch( function () {
					publishing = false;
					if ( publishBtn ) {
						publishBtn.disabled = false;
					}
					setStatus( '' );
				} );
		}, 5000 );
	}

	if ( publishBtn ) {
		renderPending( publish );
		publishBtn.addEventListener( 'click', function () {
			if ( publishing ) {
				return;
			}
			publishing = true;
			publishBtn.disabled = true;
			setStatus( __( 'Publishing…', 'leiho' ), 'busy' );
			apiFetch( { path: '/leiho/v1/deploy', method: 'POST' } )
				.then( function ( record ) {
					pendingDot.hidden = true;
					announce( __( 'Publishing started.', 'leiho' ) );
					if ( publish.tracks && ( 'pending' === record.status || 'building' === record.status ) ) {
						poll( record.deploy_id );
						return;
					}
					publishing = false;
					publishBtn.disabled = false;
					setStatus( __( 'Publishing started', 'leiho' ), 'ok' );
					toast(
						record.eta
							? /* translators: %d: estimated build time in minutes. */
							  sprintf( __( 'Publishing started. Your site will be up to date in about %d min.', 'leiho' ), record.eta )
							: __( 'Publishing started.', 'leiho' )
					);
					refreshPublishState();
				} )
				.catch( function ( err ) {
					publishing = false;
					publishBtn.disabled = false;
					setStatus( '' );
					toast( errorMessage( err, __( 'Publishing failed.', 'leiho' ) ), true );
				} );
		} );

		// Changements faits ailleurs (autre onglet, autre utilisateur).
		setInterval( function () {
			if ( ! document.hidden && ! publishing ) {
				refreshPublishState();
			}
		}, 60000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && ! publishing ) {
				refreshPublishState();
			}
		} );
	}

	/** Une vue a modifié du contenu : l'indicateur « non publié » se met à jour. */
	function notifyChanged() {
		setTimeout( refreshPublishState, 400 );
	}

	/* ------------------------------------------------------------------ *
	 * API pour les vues
	 * ------------------------------------------------------------------ */

	window.LeihoApp = {
		cfg: cfg,
		root: root,
		view: document.getElementById( 'leiho-app-view' ),
		api: apiFetch,
		h: h,
		append: append,
		clear: clear,
		icon: icon,
		uid: uid,
		toast: toast,
		announce: announce,
		modal: modal,
		confirm: confirmDialog,
		formatDate: formatDate,
		slugify: slugify,
		errorMessage: errorMessage,
		storage: storage,
		notifyChanged: notifyChanged,
		refreshPublishState: refreshPublishState,
	};
} )();
