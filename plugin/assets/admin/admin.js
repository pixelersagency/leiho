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
		var from = event.target.closest( '[data-leiho-copy-from]' );
		if ( from ) {
			var source = document.getElementById( from.getAttribute( 'data-leiho-copy-from' ) );
			if ( source ) {
				copyText( source.value, from );
			}
		}
	} );

	/* ── Code tabs (HTML / React) ──────────────────────────────────── */

	document.querySelectorAll( '[data-leiho-segmented]' ).forEach( function ( group ) {
		var buttons = group.querySelectorAll( 'button[data-panel]' );
		var scope = group.parentElement;

		function select( name ) {
			buttons.forEach( function ( b ) {
				b.setAttribute( 'aria-selected', b.getAttribute( 'data-panel' ) === name ? 'true' : 'false' );
			} );
			scope.querySelectorAll( '[data-panel-id]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-panel-id' ) !== name;
			} );
		}

		buttons.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				select( b.getAttribute( 'data-panel' ) );
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
				} else {
					showResult( testResult, 'ok', format( t.bridgeOk, payload.version, payload.fields, payload.sections ) );
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
				if ( ! data || 'leiho' !== data.ns || 'ready' !== data.type ) {
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
} )();
