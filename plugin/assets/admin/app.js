/**
 * Okno — coque de l'application plein écran.
 *
 * Navigation latérale (repliable, au clavier), barre du haut (publication,
 * thème, menu utilisateur) et utilitaires partagés par les vues JavaScript
 * (Collections, Médias) : window.OknoApp.
 *
 * Vanilla JS + apis WordPress (wp.apiFetch, wp.i18n). Aucun build.
 */
( function () {
	'use strict';

	var cfg = window.OknoAppConfig || {};
	var root = document.getElementById( 'okno-app' );
	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	if ( ! root || ! apiFetch ) {
		return;
	}

	document.documentElement.classList.add( 'okno-app-html' );

	/* ------------------------------------------------------------------ *
	 * Utilitaires DOM
	 * ------------------------------------------------------------------ */

	var uidCount = 0;

	function uid( prefix ) {
		uidCount += 1;
		return 'okno-' + ( prefix || 'id' ) + '-' + uidCount;
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
		archive: 'M3 4h18v4H3z M5 8v12h14V8 M10 12h4',
		list: 'M8 6h12 M8 12h12 M8 18h12 M4 6h.01 M4 12h.01 M4 18h.01',
		settings: 'M4 7h10 M18 7h2 M4 17h4 M12 17h8 M14 5v4 M10 15v4',
	};

	function icon( name, size ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'class', 'okno-icon' );
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

	var live = h( 'div', { class: 'okno-sr', 'aria-live': 'polite', role: 'status' } );
	root.appendChild( live );

	function announce( message ) {
		live.textContent = '';
		setTimeout( function () {
			live.textContent = message;
		}, 50 );
	}

	var toasts = h( 'div', { class: 'okno-toasts' } );
	root.appendChild( toasts );

	function toast( message, isError ) {
		var el = h( 'div', { class: 'okno-toast' + ( isError ? ' okno-toast--error' : '' ), role: isError ? 'alert' : 'status' }, message );
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
		var body = h( 'div', { class: 'okno-dialog__body' }, options.body );
		var footer = h( 'div', { class: 'okno-dialog__actions' } );
		var dialog = h(
			'div',
			{ class: 'okno-dialog' + ( options.wide ? ' okno-dialog--wide' : '' ), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId },
			[
				h( 'div', { class: 'okno-dialog__head' }, [
					h( 'h2', { id: titleId, text: options.title } ),
					h( 'button', { type: 'button', class: 'okno-icon-btn', 'aria-label': __( 'Close', 'okno' ), onClick: function () {
						close( false );
					} }, icon( 'close' ) ),
				] ),
				body,
				footer,
			]
		);
		var backdrop = h( 'div', { class: 'okno-backdrop' }, dialog );
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
				class: 'okno-btn' + ( action.primary ? '' : action.danger ? ' okno-btn--danger' : ' okno-btn--quiet' ),
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
				input = h( 'input', { type: 'text', id: inputId, class: 'okno-input', autocomplete: 'off', spellcheck: 'false' } );
				children.push(
					h( 'label', { for: inputId, class: 'okno-label' }, [
						/* translators: %s: text the user must type to confirm. */
						sprintf( __( 'Type %s to confirm', 'okno' ), options.typeToConfirm ),
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
					{ label: options.cancelLabel || __( 'Cancel', 'okno' ) },
					{
						label: options.confirmLabel || __( 'Confirm', 'okno' ),
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
			return __( 'just now', 'okno' );
		}
		if ( diff < 3600 ) {
			/* translators: %d: number of minutes. */
			return sprintf( wp.i18n._n( '%d minute ago', '%d minutes ago', Math.floor( diff / 60 ), 'okno' ), Math.floor( diff / 60 ) );
		}
		if ( diff < 86400 ) {
			/* translators: %d: number of hours. */
			return sprintf( wp.i18n._n( '%d hour ago', '%d hours ago', Math.floor( diff / 3600 ), 'okno' ), Math.floor( diff / 3600 ) );
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
		return ( err && err.message ) || fallback || __( 'Something went wrong.', 'okno' );
	}

	/* ------------------------------------------------------------------ *
	 * Thème clair / sombre (partagé avec l'éditeur)
	 * ------------------------------------------------------------------ */

	var themeBtn = document.getElementById( 'okno-app-theme' );

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
			themeBtn.setAttribute( 'aria-label', 'dark' === theme ? __( 'Switch to light theme', 'okno' ) : __( 'Switch to dark theme', 'okno' ) );
		}
	}

	// Sombre par défaut, comme une application de bureau ; le choix est gardé.
	applyTheme( 'light' === storage( 'okno-theme' ) ? 'light' : 'dark' );

	if ( themeBtn ) {
		themeBtn.addEventListener( 'click', function () {
			var next = 'dark' === root.dataset.theme ? 'light' : 'dark';
			storage( 'okno-theme', next );
			applyTheme( next );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Navigation : repliable (bureau), tiroir (mobile)
	 * ------------------------------------------------------------------ */

	var nav = document.getElementById( 'okno-nav' );
	var collapseBtn = document.getElementById( 'okno-nav-collapse' );
	var navToggle = document.getElementById( 'okno-nav-toggle' );
	var embedded = root.classList.contains( 'okno-app--embedded' );

	function setCollapsed( collapsed, persist ) {
		root.classList.toggle( 'okno-app--collapsed', collapsed );
		if ( collapseBtn ) {
			collapseBtn.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			var label = collapsed ? __( 'Expand the menu', 'okno' ) : __( 'Collapse the menu', 'okno' );
			collapseBtn.title = label;
			var span = collapseBtn.querySelector( '.okno-nav__label' );
			if ( span ) {
				span.textContent = label;
			}
		}
		if ( persist ) {
			storage( 'okno-nav', collapsed ? 'collapsed' : 'expanded' );
		}
	}

	// Rail d'icônes par défaut (les volets ont besoin de place) ; l'éditeur
	// reste replié quel que soit le choix enregistré.
	setCollapsed( embedded || 'expanded' !== storage( 'okno-nav' ), false );

	if ( collapseBtn ) {
		collapseBtn.addEventListener( 'click', function () {
			setCollapsed( ! root.classList.contains( 'okno-app--collapsed' ), ! embedded );
		} );
	}

	function setDrawer( open ) {
		root.classList.toggle( 'okno-app--nav-open', open );
		if ( navToggle ) {
			navToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			navToggle.setAttribute( 'aria-label', open ? __( 'Close the menu', 'okno' ) : __( 'Open the menu', 'okno' ) );
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
			setDrawer( ! root.classList.contains( 'okno-app--nav-open' ) );
		} );
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && root.classList.contains( 'okno-app--nav-open' ) ) {
			setDrawer( false );
			if ( navToggle ) {
				navToggle.focus();
			}
		}
	} );

	root.addEventListener( 'click', function ( event ) {
		if ( root.classList.contains( 'okno-app--nav-open' ) && ! nav.contains( event.target ) && navToggle && ! navToggle.contains( event.target ) ) {
			setDrawer( false );
		}
	} );

	// Flèches haut/bas entre les liens de la navigation.
	nav.addEventListener( 'keydown', function ( event ) {
		if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {
			return;
		}
		var links = Array.prototype.slice.call( nav.querySelectorAll( '.okno-nav__link' ) );
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

	var userBtn = document.getElementById( 'okno-user-button' );
	var userMenu = document.getElementById( 'okno-user-menu' );

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

	var publishBtn = document.getElementById( 'okno-app-publish' );
	var pendingDot = document.getElementById( 'okno-app-pending' );
	var statusEl = document.getElementById( 'okno-app-deploy' );
	var publish = cfg.publish || {};
	var pollTimer = null;
	var publishing = false;

	function setStatus( text, level ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = text || '';
		statusEl.className = 'okno-topbar__status' + ( level ? ' is-' + level : '' );
	}

	function renderPending( state ) {
		if ( ! publishBtn ) {
			return;
		}
		pendingDot.hidden = ! state.pending;
		publishBtn.title = state.pending
			? state.auto
				? __( 'Changes are waiting to be published. They will be published automatically.', 'okno' )
				: __( 'Some changes aren’t published yet.', 'okno' )
			: __( 'Everything is published', 'okno' );
		publishBtn.setAttribute(
			'aria-label',
			state.pending ? __( 'Publish (unpublished changes)', 'okno' ) : __( 'Publish', 'okno' )
		);
		if ( state.active && ! publishing ) {
			setStatus( __( 'Publishing…', 'okno' ), 'busy' );
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
		return apiFetch( { path: '/okno/v1/deploy/state' } )
			.then( renderPending )
			.catch( function () {
				/* Pas grave : l'indicateur se remettra à jour plus tard. */
			} );
	}

	function poll( id ) {
		clearTimeout( pollTimer );
		pollTimer = setTimeout( function () {
			apiFetch( { path: '/okno/v1/deploy/' + encodeURIComponent( id ) } )
				.then( function ( record ) {
					if ( 'pending' === record.status || 'building' === record.status ) {
						setStatus( __( 'Publishing…', 'okno' ), 'busy' );
						poll( id );
						return;
					}
					publishing = false;
					if ( publishBtn ) {
						publishBtn.disabled = false;
					}
					if ( 'success' === record.status || 'triggered' === record.status ) {
						setStatus( __( 'Published', 'okno' ), 'ok' );
						toast( __( 'Your site is up to date.', 'okno' ) );
					} else {
						setStatus( __( 'Publishing failed', 'okno' ), 'error' );
						toast( record.message || __( 'Publishing failed.', 'okno' ), true );
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
			setStatus( __( 'Publishing…', 'okno' ), 'busy' );
			apiFetch( { path: '/okno/v1/deploy', method: 'POST' } )
				.then( function ( record ) {
					pendingDot.hidden = true;
					announce( __( 'Publishing started.', 'okno' ) );
					if ( publish.tracks && ( 'pending' === record.status || 'building' === record.status ) ) {
						poll( record.deploy_id );
						return;
					}
					publishing = false;
					publishBtn.disabled = false;
					setStatus( __( 'Publishing started', 'okno' ), 'ok' );
					toast(
						record.eta
							? /* translators: %d: estimated build time in minutes. */
							  sprintf( __( 'Publishing started. Your site will be up to date in about %d min.', 'okno' ), record.eta )
							: __( 'Publishing started.', 'okno' )
					);
					refreshPublishState();
				} )
				.catch( function ( err ) {
					publishing = false;
					publishBtn.disabled = false;
					setStatus( '' );
					toast( errorMessage( err, __( 'Publishing failed.', 'okno' ) ), true );
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

	window.OknoApp = {
		cfg: cfg,
		root: root,
		view: document.getElementById( 'okno-app-view' ),
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
