/**
 * Leiho — admin screens: copy buttons, code tabs, publishing mode fields,
 * live connection checks.
 */
( function () {
	'use strict';

	var cfg = window.LeihoAdmin || {};
	var t = cfg.strings || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = window.wp.i18n.__;

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( template ).replace( /%(\d)\$[sd]/g, function ( m, i ) {
			return args[ Number( i ) - 1 ];
		} );
	}

	/* ── Copy ───────────────────────────────────────────────────────── */

	function copyText( text, button ) {
		var done = function () {
			var label = button.textContent;
			button.textContent = t.copied || __( 'Copied', 'leiho' );
			setTimeout( function () {
				button.textContent = label;
			}, 1600 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}

		// Fallback: wp-admin served over plain HTTP, no Clipboard API.
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			/* Nothing to do: the text can still be selected by hand. */
		}
		area.remove();
	}

	document.addEventListener( 'click', function ( event ) {
		var direct = event.target.closest( '[data-leiho-copy]' );
		if ( direct ) {
			copyText( direct.getAttribute( 'data-leiho-copy' ), direct );
			return;
		}
		// Plusieurs sources (identifiants séparés par des espaces) : textes
		// mis bout à bout, séparés par un filet (consigne + guide complet).
		var from = event.target.closest( '[data-leiho-copy-from]' );
		if ( from ) {
			var parts = from.getAttribute( 'data-leiho-copy-from' ).split( /\s+/ ).map( function ( id ) {
				var source = document.getElementById( id );
				return source ? source.value : '';
			} ).filter( Boolean );
			if ( parts.length ) {
				copyText( parts.join( '\n\n---\n\n' ), from );
				from.dispatchEvent( new CustomEvent( 'leiho:copied', { bubbles: true } ) );
			}
		}
	} );

	/* ── Code tabs (.leiho-segmented) ──────────────────────────────── */

	document.querySelectorAll( '[data-leiho-segmented]' ).forEach( function ( group ) {
		var buttons = Array.prototype.slice.call( group.querySelectorAll( 'button[data-panel]' ) );
		var scope = group.parentElement;

		function select( name, focus ) {
			buttons.forEach( function ( b ) {
				var on = b.getAttribute( 'data-panel' ) === name;
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				b.tabIndex = on ? 0 : -1;
				if ( on && focus ) {
					b.focus();
				}
			} );
			// Panneaux de ce groupe seulement : d'autres onglets peuvent être imbriqués.
			Array.prototype.forEach.call( scope.children, function ( panel ) {
				if ( panel.hasAttribute( 'data-panel-id' ) ) {
					panel.hidden = panel.getAttribute( 'data-panel-id' ) !== name;
				}
			} );
		}

		buttons.forEach( function ( b, i ) {
			b.addEventListener( 'click', function () {
				select( b.getAttribute( 'data-panel' ) );
			} );
			// Flèches gauche / droite entre les onglets (motif ARIA tablist).
			b.addEventListener( 'keydown', function ( event ) {
				var step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];
				if ( step ) {
					event.preventDefault();
					var next = buttons[ ( i + step + buttons.length ) % buttons.length ];
					select( next.getAttribute( 'data-panel' ), true );
				}
			} );
		} );
	} );

	/* ── Settings: only show the fields of the chosen mode ──────────── */

	var radios = document.querySelectorAll( '[data-leiho-driver]' );
	if ( radios.length ) {
		var syncDriverFields = function () {
			var current = '';
			radios.forEach( function ( r ) {
				if ( r.checked ) {
					current = r.value;
				}
			} );
			document.querySelectorAll( '[data-leiho-driver-fields]' ).forEach( function ( block ) {
				var list = block.getAttribute( 'data-leiho-driver-fields' ).split( /\s+/ );
				block.hidden = list.indexOf( current ) === -1;
			} );
		};
		radios.forEach( function ( r ) {
			r.addEventListener( 'change', syncDriverFields );
		} );
		syncDriverFields();
	}

	/* ── Get started: framework, path, progress ─────────────────────── */

	var onboarding = document.querySelector( '[data-leiho-onboarding]' );
	var STORE_FW = 'leiho.start.fw';
	var STORE_PATH = 'leiho.start.path';

	// localStorage peut être indisponible (navigation privée, stockage bloqué).
	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return window.localStorage.getItem( key ) || '';
			}
			window.localStorage.setItem( key, value );
		} catch ( e ) {
			/* Le choix n'est pas retenu, l'écran marche quand même. */
		}
		return '';
	}

	// Garde ?fw= et ?path= dans l'adresse : lien partageable, rechargement fidèle.
	function syncUrl( name, value ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( name, value );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			/* Adresse non modifiable : sans conséquence. */
		}
	}

	function radioValue( selector ) {
		var checked = onboarding.querySelector( selector + ':checked' );
		return checked ? checked.value : '';
	}

	function checkRadio( selector, value ) {
		var found = false;
		onboarding.querySelectorAll( selector ).forEach( function ( r ) {
			if ( r.value === value ) {
				r.checked = true;
				found = true;
			}
		} );
		return found;
	}

	function applyFramework( fw ) {
		onboarding.setAttribute( 'data-fw', fw );
		onboarding.querySelectorAll( '[data-leiho-fw]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-leiho-fw' ) !== fw;
		} );
		var after = onboarding.querySelector( '[data-leiho-after-fw]' );
		if ( after ) {
			after.hidden = ! fw;
		}
	}

	function applyPath( path ) {
		onboarding.querySelectorAll( '[data-leiho-path-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-leiho-path-panel' ) !== path;
		} );
	}

	var STEP_ORDER = [ 'url', 'frame', 'bridge', 'content', 'annotate', 'test' ];

	function refreshProgress() {
		if ( ! onboarding ) {
			return;
		}
		var steps = onboarding.querySelectorAll( '[data-leiho-step]' );
		var done = 0;
		var current = null;
		steps.forEach( function ( li ) {
			li.classList.remove( 'leiho-step--current' );
			if ( li.classList.contains( 'leiho-step--done' ) ) {
				done++;
			} else if ( ! current ) {
				current = li;
			}
		} );
		if ( current ) {
			current.classList.add( 'leiho-step--current' );
		}
		var bar = onboarding.querySelector( '[data-leiho-progress-bar]' );
		var label = onboarding.querySelector( '[data-leiho-progress-label]' );
		if ( bar ) {
			bar.value = done;
		}
		if ( label ) {
			/* translators: 1: steps done, 2: total steps. */
			label.textContent = window.wp.i18n.sprintf( __( '%1$d of %2$d steps done', 'leiho' ), done, steps.length );
		}
	}

	/** Marque des étapes comme faites (vérifiées dans le navigateur). */
	function markSteps( names ) {
		if ( ! onboarding ) {
			return;
		}
		names.forEach( function ( name ) {
			if ( STEP_ORDER.indexOf( name ) === -1 ) {
				return;
			}
			var li = onboarding.querySelector( '[data-leiho-step="' + name + '"]' );
			if ( li ) {
				li.classList.add( 'leiho-step--done' );
			}
		} );
		refreshProgress();
	}

	if ( onboarding ) {
		// Choix de l'adresse (?fw=, ?path=, déjà rendus par le serveur), sinon le dernier retenu.
		var fw = radioValue( '[data-leiho-fw-choice]' );
		if ( ! fw && checkRadio( '[data-leiho-fw-choice]', store( STORE_FW ) ) ) {
			fw = radioValue( '[data-leiho-fw-choice]' );
		}
		applyFramework( fw );

		var path = radioValue( '[data-leiho-path-choice]' );
		if ( ! path && checkRadio( '[data-leiho-path-choice]', store( STORE_PATH ) ) ) {
			path = radioValue( '[data-leiho-path-choice]' );
		}
		applyPath( path );

		onboarding.addEventListener( 'change', function ( event ) {
			var input = event.target;
			if ( input.matches( '[data-leiho-fw-choice]' ) ) {
				store( STORE_FW, input.value );
				syncUrl( 'fw', input.value );
				applyFramework( input.value );
			} else if ( input.matches( '[data-leiho-path-choice]' ) ) {
				store( STORE_PATH, input.value );
				syncUrl( 'path', input.value );
				applyPath( input.value );
			}
		} );

		// Le JS fait le travail du formulaire (sans JS : bouton « Afficher »).
		var fwForm = onboarding.querySelector( '[data-leiho-fw-form]' );
		if ( fwForm ) {
			fwForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
			} );
		}

		// Consigne copiée : on montre la suite (tester la connexion).
		onboarding.addEventListener( 'leiho:copied', function ( event ) {
			if ( ! event.target.matches( '[data-leiho-prompt-copy]' ) ) {
				return;
			}
			var after = onboarding.querySelector( '[data-leiho-after-copy]' );
			if ( after ) {
				after.hidden = false;
			}
		} );

		onboarding.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-leiho-goto-test]' ) ) {
				return;
			}
			checkRadio( '[data-leiho-path-choice]', 'steps' );
			store( STORE_PATH, 'steps' );
			syncUrl( 'path', 'steps' );
			applyPath( 'steps' );
			var step = document.getElementById( 'leiho-step-test' );
			if ( step ) {
				step.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				var button = step.querySelector( '[data-leiho-test-bridge]' );
				if ( button && ! button.disabled ) {
					button.focus( { preventScroll: true } );
				}
			}
		} );

		refreshProgress();
	}

	/* ── Get started: live checks ───────────────────────────────────── */

	function showResult( el, level, message ) {
		el.hidden = false;
		el.className = 'leiho-check' + ( level ? ' leiho-check--' + level : '' );
		el.innerHTML = '';
		var dot = document.createElement( 'span' );
		dot.className = 'leiho-dot' + ( level ? ' leiho-dot--' + level : '' );
		var text = document.createElement( 'span' );
		text.textContent = message;
		el.appendChild( dot );
		el.appendChild( text );
	}

	var headersButton = document.querySelector( '[data-leiho-check-headers]' );
	var headersResult = document.querySelector( '[data-leiho-headers-result]' );
	if ( headersButton && headersResult && apiFetch ) {
		headersButton.addEventListener( 'click', function () {
			headersButton.disabled = true;
			showResult( headersResult, '', t.checking || '…' );
			apiFetch( { path: '/leiho/v1/connection' } )
				.then( function ( res ) {
					showResult( headersResult, res.level, res.message );
					// « warn » : aucun en-tête ne bloque, l'éditeur fonctionnera.
					if ( 'ok' === res.level || 'warn' === res.level ) {
						markSteps( [ 'frame' ] );
					}
				} )
				.catch( function ( err ) {
					showResult( headersResult, 'error', ( err && err.message ) || t.headersFailed );
				} )
				.finally( function () {
					headersButton.disabled = false;
				} );
		} );
	}

	/**
	 * Bridge test: load the site in a hidden iframe and wait for its
	 * handshake. This is exactly what the editor will do.
	 */
	var testButton = document.querySelector( '[data-leiho-test-bridge]' );
	var testResult = document.querySelector( '[data-leiho-bridge-result]' );
	if ( testButton && testResult && cfg.previewUrl ) {
		testButton.addEventListener( 'click', function () {
			testButton.disabled = true;
			showResult( testResult, '', t.testing || '…' );

			var frame = document.createElement( 'iframe' );
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.setAttribute( 'tabindex', '-1' );
			frame.style.cssText = 'position:absolute;left:-9999px;width:1280px;height:800px;border:0;';

			var finished = false;
			var timer;

			function finish( payload ) {
				if ( finished ) {
					return;
				}
				finished = true;
				clearTimeout( timer );
				window.removeEventListener( 'message', onMessage );
				frame.remove();
				testButton.disabled = false;

				if ( ! payload ) {
					showResult( testResult, 'error', t.bridgeTimeout );
				} else if ( ! payload.fields ) {
					showResult( testResult, 'warn', t.bridgeNoFields );
					markSteps( [ 'frame', 'bridge', 'test' ] );
				} else {
					showResult( testResult, 'ok', format( t.bridgeOk, payload.version, payload.fields, payload.sections ) );
					markSteps( [ 'frame', 'bridge', 'content', 'annotate', 'test' ] );
				}

				if ( apiFetch ) {
					apiFetch( {
						path: '/leiho/v1/connection',
						method: 'POST',
						data: {
							ok: !! payload,
							version: payload ? String( payload.version ) : '',
							fields: payload ? payload.fields : 0,
							sections: payload ? payload.sections : 0,
						},
					} ).catch( function () {} );
				}
			}

			function onMessage( event ) {
				if ( event.origin !== cfg.frontOrigin || event.source !== frame.contentWindow ) {
					return;
				}
				var data = event.data;
				if ( ! data || ( 'leiho' !== data.ns && 'okno' !== data.ns ) || 'ready' !== data.type ) { // okno : bridge d'avant Leiho.
					return;
				}
				var fields = 0;
				Object.keys( data.posts || {} ).forEach( function ( id ) {
					fields += ( data.posts[ id ] || [] ).length;
				} );
				finish( { version: data.version || 1, fields: fields, sections: ( data.sections || [] ).length } );
			}

			window.addEventListener( 'message', onMessage );
			timer = setTimeout( function () {
				finish( null );
			}, 10000 );

			frame.src = cfg.previewUrl + ( cfg.previewUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'leiho-check=' + Date.now();
			document.body.appendChild( frame );
		} );
	}

	/**
	 * Accueil : aperçu en direct du site, rendu en largeur bureau (1440 px)
	 * puis réduit à la largeur de la carte. Le bridge du site attend l'éditeur
	 * avant d'afficher la page : on lui répond « init » sans valeur, la page
	 * s'affiche telle qu'elle est en ligne.
	 */
	var previewBox = document.querySelector( '[data-leiho-site-preview]' );
	if ( previewBox ) {
		var previewFrame = previewBox.querySelector( 'iframe' );
		var fit = function () {
			previewBox.style.setProperty( '--leiho-preview-scale', String( previewBox.clientWidth / 1440 ) );
		};
		fit();
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( fit ).observe( previewBox );
		} else {
			window.addEventListener( 'resize', fit );
		}
		window.addEventListener( 'message', function ( event ) {
			var data = event.data;
			if ( ! previewFrame || event.source !== previewFrame.contentWindow || event.origin !== cfg.frontOrigin ) {
				return;
			}
			// « okno » : bridge d'avant le renommage en Leiho.
			if ( data && ( 'leiho' === data.ns || 'okno' === data.ns ) && 'ready' === data.type ) {
				previewFrame.contentWindow.postMessage( { ns: data.ns, type: 'init', values: {} }, cfg.frontOrigin );
			}
		} );
	}
} )();

/* ── Nouvelle adresse de connexion : fenêtre « ajoutez-la à vos favoris » ── */
( function () {
	var box = document.querySelector( '[data-leiho-bookmark]' );
	if ( ! box ) {
		return;
	}

	// Raccourci du bon système ; sur mobile, le menu du navigateur.
	var keys = box.querySelector( '[data-leiho-bookmark-keys]' );
	var platform = ( navigator.userAgentData && navigator.userAgentData.platform ) || navigator.platform || '';
	var touch = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;
	if ( keys && touch ) {
		keys.textContent = keys.getAttribute( 'data-touch' );
	} else if ( keys && /mac|iphone|ipad/i.test( platform ) ) {
		keys.textContent = keys.getAttribute( 'data-mac' );
	}

	// Ne plus la montrer au rechargement de la page.
	if ( window.history && window.history.replaceState ) {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'leiho_login_new' );
		window.history.replaceState( null, '', url.toString() );
	}

	var opener = document.activeElement;
	var close = function () {
		box.remove();
		document.removeEventListener( 'keydown', onKey );
		if ( opener && opener.focus ) {
			opener.focus();
		}
	};
	var onKey = function ( event ) {
		if ( 'Escape' === event.key ) {
			close();
		}
	};
	document.addEventListener( 'keydown', onKey );
	box.addEventListener( 'click', function ( event ) {
		if ( event.target === box || event.target.closest( '[data-leiho-bookmark-close]' ) ) {
			close();
		}
	} );
	var first = box.querySelector( '[data-leiho-copy]' );
	if ( first ) {
		first.focus();
	}
} )();
