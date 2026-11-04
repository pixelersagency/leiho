/**
 * Leiho — outils d'IA : window.LeihoAI.
 *
 * Menu « IA » des champs texte (générer, réécrire, raccourcir, développer,
 * corriger, changer le ton, traduire, proposer un titre ou une description
 * SEO), aperçu avec différences et contrôle des formulations interdites
 * avant d'accepter ; texte alternatif des images ; nouvelle entrée écrite
 * par l'IA ; contexte d'une collection ; export / import du contexte de
 * marque dans les réglages.
 *
 * Rien n'est enregistré par l'IA : le texte accepté est écrit dans le champ
 * comme une saisie (annulable, enregistré avec le reste).
 *
 * Vanilla JS + apis WordPress (wp.apiFetch, wp.i18n) et window.LeihoApp.
 */
( function () {
	'use strict';

	var App = window.LeihoApp;
	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! App || ! apiFetch ) {
		return;
	}

	var cfg = window.LeihoAIConfig || {};
	var h = App.h;
	var API = '/leiho/v1/ai';
	var TEXT_TYPES = [ 'text', 'textarea', 'wysiwyg' ];

	/* ------------------------------------------------------------------ *
	 * Icônes (même trait que le reste d'Leiho)
	 * ------------------------------------------------------------------ */

	var ICONS = {
		sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z M19 17l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z',
		pen: 'M4 20h4L19 9l-4-4L4 16z M13 7l4 4',
		rewrite: 'M4 7h12 M4 12h9 M4 17h6 M15 15l2.5 2.5L21 13',
		shorten: 'M4 8h16 M4 12h10 M4 16h6',
		expand: 'M4 6h16 M4 10h16 M4 14h16 M4 18h10',
		fix: 'M5 12.5l4.5 4.5L19 7.5',
		tone: 'M4 15c2-4 4-6 6-6s3 4 5 4 3-2 5-4 M4 19h16',
		translate: 'M4 5h9 M8.5 3v2 M6 5c.5 3 3 6 6 7.5 M11 5c-.5 3-3 6-6 7.5 M13 20l4-9 4 9 M14.5 17h5',
		suggest: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z M20 20l-4-4 M8 11h6 M11 8v6',
		image: 'M3 5h18v14H3z M3 16l5-5 4 4 3-3 6 6 M15.5 9h.01',
		external: 'M14 4h6v6 M20 4l-9 9 M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
		warning: 'M12 3l10 18H2z M12 10v4 M12 17h.01',
		chevron: 'M9 6l6 6-6 6',
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
	 * Disponibilité
	 * ------------------------------------------------------------------ */

	function enabled() {
		return !! cfg.enabled;
	}

	/** Pas d'IA, mais un administrateur peut configurer un fournisseur. */
	function canSetUp() {
		return ! cfg.enabled && !! cfg.connectorsUrl;
	}

	function isTextField( def ) {
		return !! def && TEXT_TYPES.indexOf( def.type ) !== -1 && ! def.readonly && ! def.blocks && false !== def.supported;
	}

	/** Champ SEO d'après son nom ou son libellé (même règle que le serveur). */
	function seoKind( path, label ) {
		path = String( path || '' ).toLowerCase();
		if ( '_excerpt' === path ) {
			return 'description';
		}
		var last = path.replace( /^.*\./, '' );
		var text = last + ' ' + fold( label );
		if ( ! /(^|[^a-z])(seo|meta|og|twitter)([^a-z]|$)|seo_|_seo|meta_|og_/.test( text ) ) {
			return '';
		}
		if ( /desc/.test( text ) ) {
			return 'description';
		}
		return /title|titre/.test( text ) ? 'title' : '';
	}

	function fold( text ) {
		return String( text || '' ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	/* ------------------------------------------------------------------ *
	 * Texte : version brute, différences, surlignage
	 * ------------------------------------------------------------------ */

	/** Texte visible d'un HTML (paragraphes = sauts de ligne). */
	function plain( value, html ) {
		var text = String( value || '' );
		if ( html || /<[a-z!\/]/i.test( text ) ) {
			var doc = new DOMParser().parseFromString( '<body>' + text.replace( /<br\s*\/?>/gi, '\n' ).replace( /<\/(p|h[1-6]|li|blockquote|div)>/gi, '$&\n' ) + '</body>', 'text/html' );
			text = doc.body.textContent || '';
		}
		return text.replace( /[ \t ]+/g, ' ' ).replace( / *\n */g, '\n' ).replace( /\n{3,}/g, '\n\n' ).trim();
	}

	function length( text ) {
		return Array.from( plain( text ) ).length;
	}

	function tokens( text ) {
		return text.match( /\s+|[\p{L}\p{N}’'_-]+|[^\s\p{L}\p{N}]/gu ) || [];
	}

	/**
	 * Différences mot à mot (plus longue sous-suite commune).
	 *
	 * @return {Array<{type:string,text:string}>} eq | del | ins
	 */
	function diff( before, after ) {
		var a = tokens( before );
		var b = tokens( after );
		var n = a.length, m = b.length;
		if ( ! n ) {
			return after ? [ { type: 'ins', text: after } ] : [];
		}
		if ( n * m > 2500000 ) {
			return [ { type: 'del', text: before }, { type: 'ins', text: after } ];
		}
		var dp = [];
		for ( var i = 0; i <= n; i++ ) {
			dp.push( new Uint32Array( m + 1 ) );
		}
		for ( i = n - 1; i >= 0; i-- ) {
			for ( var j = m - 1; j >= 0; j-- ) {
				dp[ i ][ j ] = a[ i ] === b[ j ] ? dp[ i + 1 ][ j + 1 ] + 1 : Math.max( dp[ i + 1 ][ j ], dp[ i ][ j + 1 ] );
			}
		}
		var ops = [];
		function push( type, text ) {
			var last = ops[ ops.length - 1 ];
			if ( last && last.type === type ) {
				last.text += text;
			} else {
				ops.push( { type: type, text: text } );
			}
		}
		i = 0;
		j = 0;
		while ( i < n && j < m ) {
			if ( a[ i ] === b[ j ] ) {
				push( 'eq', a[ i ] );
				i++;
				j++;
			} else if ( dp[ i + 1 ][ j ] >= dp[ i ][ j + 1 ] ) {
				push( 'del', a[ i ] );
				i++;
			} else {
				push( 'ins', b[ j ] );
				j++;
			}
		}
		for ( ; i < n; i++ ) {
			push( 'del', a[ i ] );
		}
		for ( ; j < m; j++ ) {
			push( 'ins', b[ j ] );
		}
		return ops;
	}

	function escapeRegExp( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/** Passages à surligner (formulations interdites trouvées par le serveur). */
	function flagRanges( text, forbidden ) {
		var ranges = [];
		( forbidden || [] ).forEach( function ( item ) {
			var found = String( item.found || '' ).trim();
			if ( ! found ) {
				return;
			}
			var re = new RegExp( escapeRegExp( found ).replace( /\s+/g, '\\s+' ), 'g' );
			var match;
			while ( ( match = re.exec( text ) ) ) {
				ranges.push( { start: match.index, end: match.index + match[ 0 ].length, rule: item.rule } );
				if ( ! match[ 0 ].length ) {
					re.lastIndex++;
				}
			}
		} );
		return ranges.sort( function ( x, y ) {
			return x.start - y.start;
		} );
	}

	/** Texte avec ses passages interdits en <mark>. */
	function appendMarked( parent, text, offset, ranges ) {
		var pos = 0;
		ranges.forEach( function ( r ) {
			var start = Math.max( r.start - offset, pos );
			var end = Math.min( r.end - offset, text.length );
			if ( end <= start || start >= text.length ) {
				return;
			}
			if ( start > pos ) {
				parent.appendChild( document.createTextNode( text.slice( pos, start ) ) );
			}
			parent.appendChild( h( 'mark', { class: 'leiho-ai-flag', title: sprintf( /* translators: %s: forbidden claim rule. */ __( 'Forbidden claim: %s', 'leiho' ), r.rule ) }, text.slice( start, end ) ) );
			pos = end;
		} );
		if ( pos < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( pos ) ) );
		}
	}

	/** Vue des différences, passages interdits surlignés dans le nouveau texte. */
	function renderDiff( before, after, forbidden ) {
		var box = h( 'div', { class: 'leiho-ai-diff', tabindex: '0', 'aria-label': __( 'Changes', 'leiho' ) } );
		var ranges = flagRanges( after, forbidden );
		var offset = 0;
		diff( before, after ).forEach( function ( op ) {
			if ( 'del' === op.type ) {
				box.appendChild( h( 'del', {}, op.text ) );
				return;
			}
			var el = 'ins' === op.type ? h( 'ins' ) : document.createDocumentFragment();
			appendMarked( el, op.text, offset, ranges );
			box.appendChild( el );
			offset += op.text.length;
		} );
		return box;
	}

	/* ------------------------------------------------------------------ *
	 * Menu d'actions
	 * ------------------------------------------------------------------ */

	var openMenu = null;

	/**
	 * Menu sous un bouton : [{ label, icon, onSelect, disabled, href }].
	 */
	function showMenu( anchor, items, label ) {
		closeMenu();
		var layer = anchor.closest( '.leiho-app' ) || document.body;
		var menu = h( 'div', { class: 'leiho-listbox leiho-ai-menu', role: 'menu', 'aria-label': label } );
		var entries = [];
		items.forEach( function ( item ) {
			if ( item.separator ) {
				menu.appendChild( h( 'div', { class: 'leiho-ai-menu__sep', role: 'separator' } ) );
				return;
			}
			if ( item.note ) {
				menu.appendChild( h( 'div', { class: 'leiho-ai-menu__note', role: 'presentation', text: item.note } ) );
				return;
			}
			var el = h( item.href ? 'a' : 'div', {
				class: 'leiho-listbox__option leiho-ai-menu__item',
				role: 'menuitem',
				tabindex: '-1',
				href: item.href || null,
				'aria-disabled': item.disabled ? 'true' : null,
				title: item.title || null,
			}, [ icon( item.icon || 'sparkle', 15 ), h( 'span', { class: 'leiho-listbox__text', text: item.label } ), item.hint ? h( 'span', { class: 'leiho-ai-menu__hint', text: item.hint } ) : null ] );
			el.addEventListener( 'click', function ( event ) {
				if ( item.disabled ) {
					event.preventDefault();
					return;
				}
				if ( item.href ) {
					return;
				}
				event.preventDefault();
				closeMenu( true );
				item.onSelect();
			} );
			el.addEventListener( 'mousemove', function () {
				focusItem( entries.indexOf( el ) );
			} );
			entries.push( el );
			menu.appendChild( el );
		} );

		function focusItem( index ) {
			if ( index < 0 || index >= entries.length ) {
				return;
			}
			entries.forEach( function ( e ) {
				e.classList.remove( 'is-active' );
			} );
			entries[ index ].classList.add( 'is-active' );
			entries[ index ].focus( { preventScroll: true } );
		}

		function enabledIndex( from, step ) {
			for ( var k = 0; k < entries.length; k++ ) {
				var idx = ( from + step * ( k + 1 ) + entries.length * 4 ) % entries.length;
				if ( 'true' !== entries[ idx ].getAttribute( 'aria-disabled' ) ) {
					return idx;
				}
			}
			return -1;
		}

		menu.addEventListener( 'keydown', function ( event ) {
			var current = entries.indexOf( document.activeElement );
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				focusItem( enabledIndex( current, 1 ) );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				focusItem( enabledIndex( current < 0 ? 0 : current, -1 ) );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				focusItem( enabledIndex( -1, 1 ) );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				focusItem( enabledIndex( entries.length, -1 ) );
			} else if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				if ( current >= 0 ) {
					entries[ current ].click();
				}
			} else if ( 'Escape' === event.key || 'Tab' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				closeMenu( true );
			}
		} );

		layer.appendChild( menu );

		// Sous le bouton si la place le permet, sinon au-dessus ; suit le
		// défilement, se ferme quand le bouton sort de la fenêtre.
		function place() {
			var r = anchor.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			var vh = document.documentElement.clientHeight;
			if ( r.bottom < 0 || r.top > vh ) {
				closeMenu();
				return;
			}
			var below = vh - r.bottom > menu.offsetHeight + 12 || vh - r.bottom > r.top;
			menu.style.top = ( below ? r.bottom + 4 : Math.max( 8, r.top - 4 - menu.offsetHeight ) ) + 'px';
			menu.style.left = Math.max( 8, Math.min( r.right - menu.offsetWidth, vw - menu.offsetWidth - 8 ) ) + 'px';
		}
		place();

		function onDoc( event ) {
			if ( ! menu.contains( event.target ) && ! anchor.contains( event.target ) ) {
				closeMenu();
			}
		}
		document.addEventListener( 'mousedown', onDoc, true );
		window.addEventListener( 'resize', onScroll );
		window.addEventListener( 'scroll', onScroll, true );
		function onScroll( event ) {
			if ( event && event.target && event.target.nodeType && menu.contains( event.target ) ) {
				return;
			}
			place();
		}

		anchor.setAttribute( 'aria-expanded', 'true' );
		openMenu = {
			close: function ( refocus ) {
				menu.remove();
				document.removeEventListener( 'mousedown', onDoc, true );
				window.removeEventListener( 'resize', onScroll );
				window.removeEventListener( 'scroll', onScroll, true );
				anchor.setAttribute( 'aria-expanded', 'false' );
				if ( refocus ) {
					anchor.focus();
				}
			},
		};
		focusItem( enabledIndex( -1, 1 ) );
	}

	function closeMenu( refocus ) {
		if ( openMenu ) {
			var m = openMenu;
			openMenu = null;
			m.close( refocus );
		}
	}

	/** Bouton « IA » (étincelle). */
	function trigger( label, onOpen, extraClass ) {
		var btn = h( 'button', {
			type: 'button',
			class: 'leiho-ai-trigger' + ( extraClass ? ' ' + extraClass : '' ),
			title: label,
			'aria-label': label,
			'aria-haspopup': 'menu',
			'aria-expanded': 'false',
		}, [ icon( 'sparkle', 14 ), h( 'span', { class: 'leiho-ai-trigger__text', text: __( 'AI', 'leiho' ) } ) ] );
		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			if ( openMenu && 'true' === btn.getAttribute( 'aria-expanded' ) ) {
				closeMenu( true );
				return;
			}
			onOpen( btn );
		} );
		btn.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				onOpen( btn );
			}
		} );
		return btn;
	}

	/** Menu d'un administrateur sans fournisseur : un lien vers les connecteurs. */
	function setupItems() {
		return [
			{ note: __( 'AI isn’t set up on this site yet.', 'leiho' ) },
			{
				label: __( 'Set up an AI provider', 'leiho' ),
				icon: 'external',
				href: cfg.connectorsUrl,
				hint: __( 'Settings → Connectors', 'leiho' ),
			},
		];
	}

	/* ------------------------------------------------------------------ *
	 * Requêtes
	 * ------------------------------------------------------------------ */

	function request( path, data, signal ) {
		return apiFetch( { path: API + path, method: 'POST', data: data, signal: signal } );
	}

	function errorText( err ) {
		if ( err && 'AbortError' === err.name ) {
			return '';
		}
		return App.errorMessage( err, __( 'The AI couldn’t answer. Try again.', 'leiho' ) );
	}

	function loadingBody( text ) {
		return h( 'div', { class: 'leiho-ai-loading', role: 'status' }, [
			h( 'span', { class: 'leiho-ai-loading__spark' }, icon( 'sparkle', 22 ) ),
			h( 'span', { text: text || __( 'Writing…', 'leiho' ) } ),
		] );
	}

	function errorBody( message ) {
		return h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, h( 'p', { text: message } ) );
	}

	function setActions( dialog, actions ) {
		var footer = dialog.el.querySelector( '.leiho-dialog__actions' );
		App.clear( footer );
		var first = null;
		actions.forEach( function ( action ) {
			var btn = h( 'button', {
				type: 'button',
				class: 'leiho-btn' + ( action.primary ? '' : ' leiho-btn--quiet' ),
				disabled: !! action.disabled,
				onClick: action.onClick,
			}, [ action.icon ? icon( action.icon, 14 ) : null, action.label ] );
			if ( action.primary ) {
				first = btn;
			}
			footer.appendChild( btn );
		} );
		return first;
	}

	/* ------------------------------------------------------------------ *
	 * Avertissements d'une proposition
	 * ------------------------------------------------------------------ */

	function warningsBox( check, cut ) {
		var items = [];
		if ( check && check.forbidden && check.forbidden.length ) {
			var seen = {};
			var list = check.forbidden.filter( function ( f ) {
				var key = f.rule + '|' + f.found;
				if ( seen[ key ] ) {
					return false;
				}
				seen[ key ] = true;
				return true;
			} );
			items.push( h( 'li', {}, [
				h( 'strong', { text: _n( 'Forbidden claim found:', 'Forbidden claims found:', list.length, 'leiho' ) } ),
				' ',
				list.map( function ( f, i ) {
					return [ i ? ', ' : '', h( 'mark', { class: 'leiho-ai-flag', text: f.found } ), f.found.toLowerCase() !== f.rule.toLowerCase() ? ' (' + f.rule + ')' : '' ];
				} ),
				'. ',
				__( 'Edit the text after accepting, or regenerate.', 'leiho' ),
			] ) );
		}
		if ( check && check.too_long ) {
			items.push( h( 'li', {}, [
				h( 'strong', { text: __( 'Too long:', 'leiho' ) } ),
				' ',
				/* translators: 1: length of the text, 2: maximum length. */
				sprintf( __( '%1$d characters for %2$d allowed.', 'leiho' ), check.length, check.max ),
				' ',
				cut ? __( 'Accepting cuts it at the last word that fits.', 'leiho' ) : '',
			] ) );
		}
		if ( ! items.length ) {
			return null;
		}
		return h( 'div', { class: 'leiho-notice leiho-notice--warn leiho-ai-warnings', role: 'alert' }, [
			h( 'span', { class: 'leiho-ai-warnings__icon' }, icon( 'warning', 16 ) ),
			h( 'ul', {}, items ),
		] );
	}

	/** Coupe au dernier mot qui tient (texte brut). */
	function cutToLength( text, max ) {
		var chars = Array.from( text );
		if ( ! max || chars.length <= max ) {
			return text;
		}
		var cut = chars.slice( 0, max + 1 ).join( '' );
		var space = cut.lastIndexOf( ' ' );
		cut = space > max * 0.6 ? cut.slice( 0, space ) : chars.slice( 0, max ).join( '' );
		return cut.replace( /[\s,;:–—-]+$/, '' );
	}

	/* ------------------------------------------------------------------ *
	 * Actions sur un champ texte
	 * ------------------------------------------------------------------ */

	var TONES = [
		__( 'More formal', 'leiho' ),
		__( 'Warmer', 'leiho' ),
		__( 'More direct', 'leiho' ),
		__( 'Simpler', 'leiho' ),
		__( 'More enthusiastic', 'leiho' ),
		__( 'More reassuring', 'leiho' ),
	];

	var LANGUAGES = [ 'English', 'Français', 'Español', 'Deutsch', 'Italiano', 'Português', 'Nederlands' ];

	function actionLabel( action, seo ) {
		switch ( action ) {
			case 'generate':
				return __( 'Generate…', 'leiho' );
			case 'rewrite':
				return __( 'Rewrite', 'leiho' );
			case 'shorten':
				return __( 'Shorten', 'leiho' );
			case 'expand':
				return __( 'Expand', 'leiho' );
			case 'fix':
				return __( 'Fix spelling and grammar', 'leiho' );
			case 'tone':
				return __( 'Change the tone…', 'leiho' );
			case 'translate':
				return __( 'Translate…', 'leiho' );
			case 'suggest':
				return 'title' === seo ? __( 'Suggest a search title', 'leiho' ) : __( 'Suggest a description', 'leiho' );
		}
		return action;
	}

	var ACTION_ICONS = { generate: 'pen', rewrite: 'rewrite', shorten: 'shorten', expand: 'expand', fix: 'fix', tone: 'tone', translate: 'translate', suggest: 'suggest' };

	/**
	 * Bouton « IA » d'un champ texte.
	 *
	 * @param {Object}   o
	 * @param {number}   o.postId  Contenu.
	 * @param {string}   o.path    Chemin pointé du champ.
	 * @param {Object}   o.def     Définition (type, label, help, max_length, required).
	 * @param {Function} o.read    () → valeur actuelle.
	 * @param {Function} o.write   ( valeur ) écrit la valeur acceptée dans le champ.
	 * @param {Function} o.context () → [{ path, label, value }] autres champs de l'entrée.
	 * @param {string}   o.label   Libellé composé du champ (Sections › #1 › Titre).
	 * @return {Element|null}
	 */
	function fieldTrigger( o ) {
		// Sans fournisseur, rien sur les champs : un seul point d'entrée pour
		// les administrateurs (bouton IA des collections, réglages).
		if ( ! isTextField( o.def ) || ! enabled() ) {
			return null;
		}
		var label = o.label || o.def.label || o.path;
		/* translators: %s: field label. */
		var btnLabel = sprintf( __( 'AI actions for “%s”', 'leiho' ), label );
		return trigger( btnLabel, function ( btn ) {
			var empty = ! plain( o.read() ).length;
			var seo = seoKind( o.path, o.def.label );
			var items = [ { action: 'generate' } ];
			if ( seo ) {
				items.push( { action: 'suggest' } );
			}
			items.push( { separator: true } );
			[ 'rewrite', 'shorten', 'expand', 'fix', 'tone', 'translate' ].forEach( function ( action ) {
				items.push( { action: action, disabled: empty, title: empty ? __( 'The field is empty.', 'leiho' ) : null } );
			} );
			showMenu(
				btn,
				items.map( function ( item ) {
					if ( item.separator ) {
						return item;
					}
					return {
						label: actionLabel( item.action, seo ),
						icon: ACTION_ICONS[ item.action ],
						disabled: item.disabled,
						title: item.title,
						onSelect: function () {
							runField( o, item.action, label, seo );
						},
					};
				} ),
				btnLabel
			);
		} );
	}

	/**
	 * Boîte de dialogue d'une action : saisie (si besoin), génération,
	 * aperçu avec différences, acceptation.
	 */
	function runField( o, action, label, seo ) {
		var before = o.read() || '';
		var html = 'wysiwyg' === o.def.type;
		var params = { instruction: '', tone: '', language: '' };
		var controller = null;
		var last = null;

		var dialog = App.modal( {
			/* translators: 1: action (e.g. Rewrite), 2: field label. */
			title: sprintf( __( '%1$s · %2$s', 'leiho' ), actionLabel( action, seo ).replace( /…$/, '' ), label ),
			wide: true,
			sticky: true,
			body: h( 'div' ),
			actions: [],
			onClose: function () {
				if ( controller ) {
					controller.abort();
				}
			},
		} );
		dialog.el.classList.add( 'leiho-ai-dialog' );

		function show( nodes ) {
			App.append( App.clear( dialog.body ), nodes );
		}

		function askStep() {
			var id = App.uid( 'ai-ask' );
			var input;
			var nodes = [];
			if ( 'generate' === action ) {
				input = h( 'textarea', { id: id, class: 'leiho-input', rows: '4', maxlength: '1000', placeholder: __( 'For example: a two-sentence intro about our on-site audits, for facility managers.', 'leiho' ) } );
				input.value = params.instruction;
				nodes.push( h( 'label', { class: 'leiho-label', for: id, text: __( 'What should the AI write?', 'leiho' ) } ), input );
			} else if ( 'tone' === action ) {
				input = h( 'input', { type: 'text', id: id, class: 'leiho-input', maxlength: '100', placeholder: __( 'Or describe it…', 'leiho' ) } );
				input.value = params.tone;
				var chips = h( 'div', { class: 'leiho-ai-chips', role: 'group', 'aria-label': __( 'Tone', 'leiho' ) }, TONES.map( function ( tone ) {
					return h( 'button', { type: 'button', class: 'leiho-ai-chip', 'aria-pressed': tone === input.value ? 'true' : 'false', onClick: function ( event ) {
						input.value = tone;
						Array.prototype.forEach.call( chips.children, function ( c ) {
							c.setAttribute( 'aria-pressed', c === event.currentTarget ? 'true' : 'false' );
						} );
					} }, tone );
				} ) );
				nodes.push( h( 'span', { class: 'leiho-label', text: __( 'New tone', 'leiho' ) } ), chips, h( 'label', { class: 'leiho-sr', for: id, text: __( 'Tone', 'leiho' ) } ), input );
			} else if ( 'translate' === action ) {
				input = h( 'input', { type: 'text', id: id, class: 'leiho-input', maxlength: '60', list: id + '-list', placeholder: 'English' } );
				input.value = params.language || ( /^fr|fran/i.test( cfg.language || '' ) ? 'English' : '' );
				nodes.push(
					h( 'label', { class: 'leiho-label', for: id, text: __( 'Translate into', 'leiho' ) } ),
					input,
					h( 'datalist', { id: id + '-list' }, LANGUAGES.map( function ( l ) {
						return h( 'option', { value: l } );
					} ) )
				);
			}
			nodes.push( h( 'p', { class: 'leiho-help', text: __( 'The brand context and the other fields of this entry are sent along.', 'leiho' ) } ) );
			var form = h( 'form', { class: 'leiho-form-grid', onSubmit: function ( event ) {
				event.preventDefault();
				go();
			} }, nodes );
			show( form );
			function go() {
				var value = input.value.trim();
				if ( 'generate' === action && ! value ) {
					input.setAttribute( 'aria-invalid', 'true' );
					input.focus();
					return;
				}
				if ( 'generate' === action ) {
					params.instruction = value;
				} else if ( 'tone' === action ) {
					params.tone = value || TONES[ 0 ];
				} else {
					params.language = value || 'English';
				}
				generate();
			}
			setActions( dialog, [
				{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
				{ label: __( 'Generate', 'leiho' ), icon: 'sparkle', primary: true, onClick: go },
			] );
			input.focus();
			if ( 'generate' === action ) {
				input.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
						event.preventDefault();
						go();
					}
				} );
			}
		}

		function generate() {
			if ( controller ) {
				controller.abort();
			}
			controller = window.AbortController ? new window.AbortController() : null;
			show( loadingBody() );
			setActions( dialog, [ { label: __( 'Cancel', 'leiho' ), onClick: dialog.close } ] );
			dialog.el.querySelector( '.leiho-dialog__actions .leiho-btn' ).focus();
			App.announce( __( 'Writing…', 'leiho' ) );
			request(
				'/field',
				{
					post_id: o.postId,
					path: o.path,
					action: action,
					value: before,
					instruction: params.instruction,
					tone: params.tone,
					language: params.language,
					context: o.context ? o.context() : [],
					field: { type: o.def.type, label: label, help: o.def.help || '', max_length: o.def.max_length || 0, required: !! o.def.required },
				},
				controller ? controller.signal : undefined
			).then( function ( result ) {
				controller = null;
				last = result;
				preview( result );
			} ).catch( function ( err ) {
				controller = null;
				var message = errorText( err );
				if ( ! message ) {
					return;
				}
				show( errorBody( message ) );
				App.announce( message );
				var retry = setActions( dialog, [
					{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
					{ label: __( 'Try again', 'leiho' ), primary: true, onClick: generate },
				] );
				if ( retry ) {
					retry.focus();
				}
			} );
		}

		function preview( result ) {
			var text = result.text || '';
			var check = result.check || {};
			var cut = ! html && check.too_long && result.max > 0;
			var beforePlain = plain( before, html );
			var afterPlain = plain( text, html );
			var nodes = [ warningsBox( check, cut ) ];
			nodes.push( h( 'div', { class: 'leiho-ai-compare' }, [
				h( 'div', { class: 'leiho-ai-compare__head' }, [
					h( 'span', { class: 'leiho-label', text: beforePlain ? __( 'Changes', 'leiho' ) : __( 'Proposal', 'leiho' ) } ),
					h( 'span', { class: 'leiho-ai-count' + ( check.too_long ? ' is-over' : '' ), text: result.max
						? Array.from( afterPlain ).length + ' / ' + result.max
						/* translators: %d: number of characters. */
						: sprintf( _n( '%d character', '%d characters', Array.from( afterPlain ).length, 'leiho' ), Array.from( afterPlain ).length ) } ),
				] ),
				renderDiff( beforePlain, afterPlain, check.forbidden ),
			] ) );
			if ( html && text ) {
				var rendered = h( 'div', { class: 'leiho-ai-rendered' } );
				// HTML filtré par wp_kses_post() côté serveur.
				rendered.innerHTML = text;
				nodes.push( h( 'details', { class: 'leiho-ai-details' }, [ h( 'summary', { text: __( 'Formatted result', 'leiho' ) } ), rendered ] ) );
			}
			if ( ! cfg.brandFilled && cfg.settingsUrl ) {
				nodes.push( h( 'p', { class: 'leiho-help' }, [
					__( 'Tip: fill in the brand context so the AI writes in your voice.', 'leiho' ),
					' ',
					h( 'a', { href: cfg.settingsUrl, text: __( 'Brand context', 'leiho' ) } ),
				] ) );
			}
			show( nodes );
			var forbidden = check.forbidden && check.forbidden.length;
			var accept = setActions( dialog, [
				{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
				{ label: __( 'Regenerate', 'leiho' ), icon: 'sparkle', onClick: function () {
					if ( 'generate' === action || 'tone' === action || 'translate' === action ) {
						askStep();
					} else {
						generate();
					}
				} },
				{
					label: forbidden ? __( 'Accept anyway', 'leiho' ) : cut ? __( 'Accept and cut', 'leiho' ) : __( 'Accept', 'leiho' ),
					primary: true,
					disabled: ! plain( text, html ),
					onClick: function () {
						var value = cut ? cutToLength( text, result.max ) : text;
						dialog.close();
						o.write( value );
						App.toast( __( 'Text applied. Undo with Ctrl+Z; save to keep it.', 'leiho' ) );
					},
				},
			] );
			App.announce( forbidden ? __( 'Proposal ready, with warnings.', 'leiho' ) : __( 'Proposal ready.', 'leiho' ) );
			if ( accept ) {
				accept.focus();
			}
		}

		if ( 'generate' === action || 'tone' === action || 'translate' === action ) {
			askStep();
		} else {
			generate();
		}
		return last;
	}

	/* ------------------------------------------------------------------ *
	 * Texte alternatif
	 * ------------------------------------------------------------------ */

	/**
	 * Bouton « Texte alternatif » d'une image.
	 *
	 * @param {Object}   o
	 * @param {Function} o.attachmentId () → id de l'image (0 : aucune).
	 * @param {number}   o.postId       Contenu édité (journal), facultatif.
	 * @param {string}   o.context      Où l'image sert (libellé du champ).
	 * @param {Function} o.onAccept     ( texte ) → Promise|void.
	 * @param {boolean}  o.small        Bouton compact.
	 * @return {Element|null}
	 */
	function altTrigger( o ) {
		if ( ! cfg.image ) {
			return null;
		}
		var label = __( 'Write the alternative text with AI', 'leiho' );
		var btn = h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet leiho-ai-alt' + ( o.small ? ' leiho-btn--small' : '' ), title: label }, [ icon( 'sparkle', 14 ), h( 'span', { text: __( 'Alt text', 'leiho' ) } ) ] );
		btn.setAttribute( 'aria-label', label );
		btn.addEventListener( 'click', function () {
			var id = o.attachmentId();
			if ( id ) {
				runAlt( id, o );
			}
		} );
		return btn;
	}

	function runAlt( id, o ) {
		var controller = null;
		var dialog = App.modal( {
			title: __( 'Alternative text', 'leiho' ),
			wide: true,
			sticky: true,
			body: h( 'div' ),
			actions: [],
			onClose: function () {
				if ( controller ) {
					controller.abort();
				}
			},
		} );
		dialog.el.classList.add( 'leiho-ai-dialog' );

		function show( nodes ) {
			App.append( App.clear( dialog.body ), nodes );
		}

		function generate() {
			controller = window.AbortController ? new window.AbortController() : null;
			show( loadingBody( __( 'Looking at the image…', 'leiho' ) ) );
			setActions( dialog, [ { label: __( 'Cancel', 'leiho' ), onClick: dialog.close } ] );
			request( '/alt', { attachment_id: id, post_id: o.postId || 0, context: o.context || '' }, controller ? controller.signal : undefined )
				.then( function ( result ) {
					controller = null;
					var fieldId = App.uid( 'ai-alt' );
					var input = h( 'textarea', { id: fieldId, class: 'leiho-input', rows: '3' } );
					input.value = result.text || '';
					var forbidden = result.check && result.check.forbidden && result.check.forbidden.length;
					show( [
						warningsBox( result.check, false ),
						h( 'label', { class: 'leiho-label', for: fieldId, text: __( 'Proposal (you can edit it)', 'leiho' ) } ),
						input,
						h( 'p', { class: 'leiho-help', text: __( 'Describe what matters in this context. Leave it empty for a purely decorative image.', 'leiho' ) } ),
					] );
					var accept = setActions( dialog, [
						{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
						{ label: __( 'Regenerate', 'leiho' ), icon: 'sparkle', onClick: generate },
						{ label: forbidden ? __( 'Accept anyway', 'leiho' ) : __( 'Accept', 'leiho' ), primary: true, onClick: function () {
							var text = input.value.trim();
							dialog.close();
							Promise.resolve( o.onAccept( text ) ).catch( function ( err ) {
								App.toast( App.errorMessage( err, __( 'Couldn’t save the alternative text.', 'leiho' ) ), true );
							} );
						} },
					] );
					App.announce( __( 'Proposal ready.', 'leiho' ) );
					if ( accept ) {
						input.focus();
					}
				} )
				.catch( function ( err ) {
					controller = null;
					var message = errorText( err );
					if ( message ) {
						show( errorBody( message ) );
						setActions( dialog, [
							{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
							{ label: __( 'Try again', 'leiho' ), primary: true, onClick: generate },
						] );
					}
				} );
		}
		generate();
	}

	/* ------------------------------------------------------------------ *
	 * Nouvelle entrée avec l'IA
	 * ------------------------------------------------------------------ */

	/**
	 * Bouton « Nouvelle entrée avec l'IA ».
	 *
	 * @param {Object}   type      Type (cfg.types de l'app) : name, label, singular.
	 * @param {Function} onCreated ( post ) une fois le brouillon créé.
	 * @return {Element|null}
	 */
	function newEntryButton( type, onCreated ) {
		if ( ! enabled() && ! canSetUp() ) {
			return null;
		}
		var label = __( 'New entry with AI', 'leiho' );
		var btn = h( 'button', { type: 'button', class: 'leiho-icon-btn leiho-ai-new', title: label, 'aria-label': label }, icon( 'sparkle', 16 ) );
		btn.addEventListener( 'click', function () {
			if ( ! enabled() ) {
				btn.setAttribute( 'aria-haspopup', 'menu' );
				showMenu( btn, setupItems(), label );
				return;
			}
			openNewEntry( type, onCreated );
		} );
		return btn;
	}

	function openNewEntry( type, onCreated ) {
		var brief = '';
		var controller = null;
		var dialog = App.modal( {
			/* translators: %s: content type (e.g. Services). */
			title: sprintf( __( 'New entry in %s with AI', 'leiho' ), type.label ),
			wide: true,
			sticky: true,
			body: h( 'div' ),
			actions: [],
			onClose: function () {
				if ( controller ) {
					controller.abort();
				}
			},
		} );
		dialog.el.classList.add( 'leiho-ai-dialog', 'leiho-ai-dialog--entry' );

		function show( nodes ) {
			App.append( App.clear( dialog.body ), nodes );
		}

		function askStep() {
			var id = App.uid( 'ai-brief' );
			var input = h( 'textarea', { id: id, class: 'leiho-input', rows: '6', maxlength: '4000', placeholder: __( 'Subject, key points, facts and figures to include, who it’s for…', 'leiho' ) } );
			input.value = brief;
			show( h( 'form', { class: 'leiho-form-grid', onSubmit: function ( event ) {
				event.preventDefault();
				go();
			} }, [
				h( 'label', { class: 'leiho-label', for: id, text: __( 'Brief', 'leiho' ) } ),
				input,
				h( 'p', { class: 'leiho-help', text: __( 'The AI fills the text fields of this type from your brief and the brand context. You review everything before the draft is created; images and links stay for you to add.', 'leiho' ) } ),
			] ) );
			function go() {
				brief = input.value.trim();
				if ( ! brief ) {
					input.setAttribute( 'aria-invalid', 'true' );
					input.focus();
					return;
				}
				generate();
			}
			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
					event.preventDefault();
					go();
				}
			} );
			setActions( dialog, [
				{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
				{ label: __( 'Generate', 'leiho' ), icon: 'sparkle', primary: true, onClick: go },
			] );
			input.focus();
		}

		function generate() {
			controller = window.AbortController ? new window.AbortController() : null;
			show( loadingBody( __( 'Writing the entry…', 'leiho' ) ) );
			setActions( dialog, [ { label: __( 'Cancel', 'leiho' ), onClick: dialog.close } ] );
			App.announce( __( 'Writing the entry…', 'leiho' ) );
			request( '/entry', { post_type: type.name, brief: brief }, controller ? controller.signal : undefined )
				.then( function ( result ) {
					controller = null;
					preview( result );
				} )
				.catch( function ( err ) {
					controller = null;
					var message = errorText( err );
					if ( message ) {
						show( errorBody( message ) );
						setActions( dialog, [
							{ label: __( 'Edit the brief', 'leiho' ), onClick: askStep },
							{ label: __( 'Try again', 'leiho' ), primary: true, onClick: generate },
						] );
					}
				} );
		}

		function preview( result ) {
			var values = result.values || {};
			var warnings = result.warnings || {};
			var fields = result.fields || [];
			var flagged = Object.keys( warnings ).filter( function ( p ) {
				return warnings[ p ].forbidden;
			} );
			var filled = fields.filter( function ( f ) {
				return undefined !== values[ f.path ];
			} );
			var nodes = [];
			if ( flagged.length ) {
				nodes.push( h( 'div', { class: 'leiho-notice leiho-notice--warn leiho-ai-warnings', role: 'alert' }, [
					h( 'span', { class: 'leiho-ai-warnings__icon' }, icon( 'warning', 16 ) ),
					h( 'p', { text: sprintf(
						/* translators: %d: number of fields. */
						_n( 'Forbidden claims found in %d field: they’re highlighted below. Edit them after creating the draft, or regenerate.', 'Forbidden claims found in %d fields: they’re highlighted below. Edit them after creating the draft, or regenerate.', flagged.length, 'leiho' ),
						flagged.length
					) } ),
				] ) );
			}
			nodes.push( h( 'dl', { class: 'leiho-ai-entry' }, filled.map( function ( f ) {
				return renderValue( f, values[ f.path ], f.path, warnings );
			} ) ) );
			var empty = fields.length - filled.length;
			nodes.push( h( 'p', { class: 'leiho-help', text: [
				__( 'Created as a draft: nothing is visible on the site until you publish it.', 'leiho' ),
				/* translators: %d: number of fields. */
				empty ? sprintf( _n( '%d field left empty.', '%d fields left empty.', empty, 'leiho' ), empty ) : '',
			].join( ' ' ) } ) );
			show( nodes );
			var create = setActions( dialog, [
				{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
				{ label: __( 'Edit the brief', 'leiho' ), onClick: askStep },
				{ label: __( 'Regenerate', 'leiho' ), icon: 'sparkle', onClick: generate },
				{ label: __( 'Create the draft', 'leiho' ), primary: true, disabled: ! values._title, onClick: function () {
					createDraft( values );
				} },
			] );
			App.announce( __( 'Proposal ready.', 'leiho' ) );
			if ( create ) {
				create.focus();
			}
		}

		function createDraft( values ) {
			show( loadingBody( __( 'Creating the draft…', 'leiho' ) ) );
			setActions( dialog, [] );
			request( '/entry/create', { post_type: type.name, values: values } )
				.then( function ( result ) {
					dialog.close();
					var refused = Object.keys( result.errors || {} );
					if ( refused.length ) {
						/* translators: %s: comma-separated field paths. */
						App.toast( sprintf( __( 'Draft created. Some values were refused, check: %s', 'leiho' ), refused.join( ', ' ) ), true );
					} else {
						/* translators: %s: entry title. */
						App.toast( sprintf( __( '“%s” created as a draft.', 'leiho' ), result.post.title ) );
					}
					if ( onCreated ) {
						onCreated( result.post );
					}
				} )
				.catch( function ( err ) {
					show( errorBody( App.errorMessage( err, __( 'Couldn’t create the draft.', 'leiho' ) ) ) );
					setActions( dialog, [
						{ label: __( 'Cancel', 'leiho' ), onClick: dialog.close },
						{ label: __( 'Try again', 'leiho' ), primary: true, onClick: function () {
							createDraft( values );
						} },
					] );
				} );
		}

		askStep();
	}

	/** Valeur proposée, lisible (sections et listes dépliées). */
	function renderValue( def, value, path, warnings ) {
		var forbidden = warnings[ path ] && warnings[ path ].forbidden;
		var dd = h( 'dd', {} );
		switch ( def.type ) {
			case 'wysiwyg':
				var box = h( 'div', { class: 'leiho-ai-rendered' } );
				box.innerHTML = String( value ); // Filtré par wp_kses_post() côté serveur.
				if ( forbidden ) {
					markIn( box, forbidden );
				}
				dd.appendChild( box );
				break;
			case 'text':
			case 'textarea':
				appendMarked( dd, String( value ), 0, flagRanges( String( value ), forbidden ) );
				break;
			case 'select':
				dd.textContent = ( def.options && def.options[ value ] ) || String( value );
				break;
			case 'true_false':
				dd.textContent = value ? __( 'Yes', 'leiho' ) : __( 'No', 'leiho' );
				break;
			case 'number':
			case 'range':
				dd.textContent = String( value );
				break;
			case 'group':
			case 'clone':
				dd.appendChild( h( 'dl', { class: 'leiho-ai-entry leiho-ai-entry--nested' }, ( def.sub_fields || [] ).filter( function ( s ) {
					return value && undefined !== value[ s.path ];
				} ).map( function ( s ) {
					return renderValue( s, value[ s.path ], path + '.' + s.path, warnings );
				} ) ) );
				break;
			case 'repeater':
			case 'flexible_content':
				dd.appendChild( h( 'ol', { class: 'leiho-ai-rows' }, ( value || [] ).map( function ( row, i ) {
					var subs = def.sub_fields || [];
					var title = '#' + ( i + 1 );
					if ( 'flexible_content' === def.type ) {
						var layout = ( def.layouts || [] ).filter( function ( l ) {
							return l.name === row._layout;
						} )[ 0 ];
						subs = layout ? layout.sub_fields : [];
						title += ' · ' + ( layout ? layout.label : row._layout );
					}
					return h( 'li', {}, [
						h( 'span', { class: 'leiho-ai-rows__title', text: title } ),
						h( 'dl', { class: 'leiho-ai-entry leiho-ai-entry--nested' }, subs.filter( function ( s ) {
							return undefined !== row[ s.path ];
						} ).map( function ( s ) {
							return renderValue( s, row[ s.path ], path + '.' + i + '.' + s.path, warnings );
						} ) ),
					] );
				} ) ) );
				break;
		}
		var note = warnings[ path ] && warnings[ path ].trimmed ? h( 'span', { class: 'leiho-ai-entry__note', text: __( 'cut to the maximum length', 'leiho' ) } ) : null;
		return h( 'div', { class: 'leiho-ai-entry__row' + ( forbidden ? ' has-flag' : '' ) }, [ h( 'dt', {}, [ def.label || def.path, note ] ), dd ] );
	}

	/** Surligne les passages interdits dans un HTML rendu. */
	function markIn( root, forbidden ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		var nodes = [];
		while ( walker.nextNode() ) {
			nodes.push( walker.currentNode );
		}
		nodes.forEach( function ( node ) {
			var ranges = flagRanges( node.nodeValue, forbidden );
			if ( ! ranges.length ) {
				return;
			}
			var frag = document.createDocumentFragment();
			appendMarked( frag, node.nodeValue, 0, ranges );
			node.parentNode.replaceChild( frag, node );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Contexte d'une collection (réglages de la collection)
	 * ------------------------------------------------------------------ */

	function collectionCard( type ) {
		if ( ! cfg.canCollections || ! type ) {
			return null;
		}
		var purposeId = App.uid( 'ai-purpose' );
		var audienceId = App.uid( 'ai-audience' );
		var purpose = h( 'textarea', { id: purposeId, class: 'leiho-input', rows: '3', maxlength: '1000', disabled: true, placeholder: __( 'Example: our services, one per page, to explain what we do and get a quote request.', 'leiho' ) } );
		var audience = h( 'textarea', { id: audienceId, class: 'leiho-input', rows: '3', maxlength: '1000', disabled: true, placeholder: __( 'Example: facility managers of mid-sized companies.', 'leiho' ) } );
		var status = h( 'span', { class: 'leiho-muted', role: 'status' } );
		var save = h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet', disabled: true }, __( 'Save the AI context', 'leiho' ) );
		var dirty = false;
		function touch() {
			dirty = true;
			save.disabled = false;
			status.textContent = '';
		}
		purpose.addEventListener( 'input', touch );
		audience.addEventListener( 'input', touch );
		save.addEventListener( 'click', function () {
			save.disabled = true;
			status.textContent = __( 'Saving…', 'leiho' );
			apiFetch( { path: API + '/collections/' + encodeURIComponent( type ), method: 'POST', data: { purpose: purpose.value, audience: audience.value } } )
				.then( function () {
					dirty = false;
					status.textContent = __( 'Saved.', 'leiho' );
				} )
				.catch( function ( err ) {
					save.disabled = false;
					status.textContent = App.errorMessage( err );
				} );
		} );
		apiFetch( { path: API + '/collections/' + encodeURIComponent( type ) } )
			.then( function ( ctx ) {
				if ( ! dirty ) {
					purpose.value = ctx.purpose || '';
					audience.value = ctx.audience || '';
				}
				purpose.disabled = false;
				audience.disabled = false;
			} )
			.catch( function () {
				status.textContent = __( 'Couldn’t load the AI context.', 'leiho' );
			} );

		return h( 'div', { class: 'leiho-detail__card leiho-ai-card' }, [
			h( 'h3', {}, [ icon( 'sparkle', 15 ), ' ', __( 'AI context', 'leiho' ) ] ),
			h( 'p', { class: 'leiho-muted', text: enabled() || ! cfg.client
				? __( 'Sent to the AI with the brand context when it writes entries of this type. Saved on its own, apart from the fields.', 'leiho' )
				: __( 'Used once an AI provider is set up in Settings → Connectors.', 'leiho' ) } ),
			h( 'div', { class: 'leiho-settings-grid' }, [
				h( 'div', { class: 'leiho-setting' }, [ h( 'label', { class: 'leiho-label', for: purposeId, text: __( 'Purpose of this collection', 'leiho' ) } ), purpose ] ),
				h( 'div', { class: 'leiho-setting' }, [ h( 'label', { class: 'leiho-label', for: audienceId, text: __( 'Readers', 'leiho' ) } ), audience ] ),
			] ),
			h( 'div', { class: 'leiho-ai-card__foot' }, [ save, status ] ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Réglages : export / import du contexte de marque
	 * ------------------------------------------------------------------ */

	function setupBrandSettings() {
		var section = document.querySelector( '[data-leiho-brand]' );
		if ( ! section ) {
			return;
		}
		var exportBtn = section.querySelector( '[data-leiho-brand-export]' );
		var importBtn = section.querySelector( '[data-leiho-brand-import]' );
		var file = h( 'input', { type: 'file', accept: 'application/json,.json', hidden: true } );
		section.appendChild( file );

		function collect() {
			var out = { leiho: 'brand', version: 1, samples: [] };
			Array.prototype.forEach.call( section.querySelectorAll( '[data-brand-key]' ), function ( el ) {
				var key = el.getAttribute( 'data-brand-key' );
				if ( 'samples' === key ) {
					if ( el.value.trim() ) {
						out.samples.push( el.value.trim() );
					}
				} else if ( el.hasAttribute( 'data-brand-list' ) ) {
					out[ key ] = el.value.split( /\r?\n/ ).map( function ( s ) {
						return s.trim();
					} ).filter( Boolean );
				} else {
					out[ key ] = el.value.trim();
				}
			} );
			return out;
		}

		function fill( data ) {
			var samples = Array.isArray( data.samples ) ? data.samples : [];
			var sampleIndex = 0;
			Array.prototype.forEach.call( section.querySelectorAll( '[data-brand-key]' ), function ( el ) {
				var key = el.getAttribute( 'data-brand-key' );
				var value = data[ key ];
				if ( 'samples' === key ) {
					el.value = samples[ sampleIndex ] || '';
					sampleIndex++;
				} else if ( el.hasAttribute( 'data-brand-list' ) ) {
					el.value = Array.isArray( value ) ? value.join( '\n' ) : String( value || '' );
				} else {
					el.value = undefined === value || null === value ? '' : String( value );
				}
				el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		}

		if ( exportBtn ) {
			exportBtn.addEventListener( 'click', function () {
				var blob = new Blob( [ JSON.stringify( collect(), null, '\t' ) + '\n' ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = h( 'a', { href: url, download: 'leiho-brand.json', hidden: true } );
				document.body.appendChild( a );
				a.click();
				setTimeout( function () {
					URL.revokeObjectURL( url );
					a.remove();
				}, 0 );
			} );
		}
		if ( importBtn ) {
			importBtn.addEventListener( 'click', function () {
				file.value = '';
				file.click();
			} );
		}
		file.addEventListener( 'change', function () {
			var f = file.files && file.files[ 0 ];
			if ( ! f ) {
				return;
			}
			f.text().then( function ( text ) {
				var data;
				try {
					data = JSON.parse( text );
				} catch ( e ) {
					data = null;
				}
				if ( ! data || 'object' !== typeof data || Array.isArray( data ) || ( data.leiho && 'brand' !== data.leiho ) ) {
					App.toast( __( 'This file isn’t a brand context.', 'leiho' ), true );
					return;
				}
				fill( data );
				App.toast( __( 'Brand context imported: check it, then save.', 'leiho' ) );
			} );
		} );
	}

	setupBrandSettings();

	window.LeihoAI = {
		config: cfg,
		enabled: enabled,
		isTextField: isTextField,
		fieldTrigger: fieldTrigger,
		altTrigger: altTrigger,
		newEntryButton: newEntryButton,
		openNewEntry: openNewEntry,
		collectionCard: collectionCard,
		plain: plain,
		diff: diff,
		length: length,
	};
} )();
