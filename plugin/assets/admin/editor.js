/**
 * Okno — éditeur visuel.
 *
 * Sidebar gauche : onglets Pages / Structure. La structure vient du bridge
 * (sections annotées sur la page réelle) ; une section adossée à une ligne de
 * flexible content (data-okno-layout) est déplaçable, duplicable, supprimable.
 * L'inspecteur de droite n'affiche que les champs de la sélection courante.
 * Plusieurs posts peuvent cohabiter sur une page (la page elle-même + les
 * globaux type « Réglages du site » portés par le header/footer).
 *
 * Vanilla JS + apis WordPress (wp.apiFetch, wp.media, wp.editor).
 */
( function () {
	'use strict';

	var cfg = window.OknoConfig || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! apiFetch || ! document.getElementById( 'okno-editor' ) ) {
		return;
	}

	// Langue de wp-admin pour les dates (et non une locale figée).
	var LOCALE = ( function () {
		var lang = document.documentElement.lang || undefined;
		try {
			new Intl.DateTimeFormat( lang );
			return lang;
		} catch ( e ) {
			return undefined; // Balise de langue invalide : locale du navigateur.
		}
	} )();

	var PER_PAGE = 50;
	var AUTOSAVE_MS = 2000;
	var HISTORY_MAX = 50;

	/* ------------------------------------------------------------------ *
	 * État
	 * ------------------------------------------------------------------ */

	var state = {
		posts: [], // liste /posts (contenus éditables).
		postsPage: 1,
		postsTotalPages: 1,
		postsSearch: '',
		post: null, // page principale ouverte.
		schemas: {}, // postId (string) -> schéma.
		fields: {}, // "postId:path" -> champ (top-level), enrichi _post/_group.
		dirtyByPost: {}, // postId -> { path: valeur }.
		structuralDirty: {}, // "postId:path" -> true (conteneur : structure modifiée).
		knownFields: {}, // "postId:path[.i.sub…]" -> { refresh } annoncés par le bridge.
		sections: [], // bridge v3 : [{ id, label, layout, fields }].
		structureStale: false, // structure modifiée localement : l'aperçu est en retard.
		selection: 'page', // 'page' | id de section | 'row:post:path:index'.
		bridgeReady: false,
		bridgeVersion: 0,
		handshakeTimer: null,
		deployId: cfg.activeDeploy || '',
		deployPollTimer: null,
		tinymceIds: [],
		history: { stack: [], index: -1, muted: false },
		draftTimer: null,
		uid: 0,
		frameUrl: '', // Dernière URL annoncée par le bridge (navigation client comprise).
		pendingField: cfg.initialField || '', // Lien profond : champ à ouvrir au premier handshake.
		saving: false,
		fieldErrors: {}, // "postId:path" -> code d'erreur renvoyé par le dernier enregistrement.
		publishing: false, // Clic sur « Publier » en cours (son save n'arme pas la minuterie).
		auto: {
			pending: false, // Changements enregistrés, pas encore publiés.
			dueAt: 0, // Échéance de la publication automatique (ms).
			armedAt: 0,
			timer: null,
			ticker: null, // Compte à rebours affiché.
			beaconSent: false, // Publication envoyée en quittant l'onglet.
		},
	};

	// Publication automatique : réglage coché, mode qui publie, droit de publier.
	var AUTO = cfg.autoDeploy && cfg.canDeploy && cfg.driverConfigured && ! cfg.liveMode ? cfg.autoDeploy : null;

	var els = {
		root: document.getElementById( 'okno-editor' ),
		theme: document.getElementById( 'okno-theme' ),
		title: document.getElementById( 'okno-current-title' ),
		tabPages: document.getElementById( 'okno-tab-pages' ),
		tabStructure: document.getElementById( 'okno-tab-structure' ),
		pagesList: document.getElementById( 'okno-pages-list' ),
		structureTree: document.getElementById( 'okno-structure-tree' ),
		frame: document.getElementById( 'okno-frame' ),
		frameWrap: document.getElementById( 'okno-frame-wrap' ),
		framePlaceholder: document.getElementById( 'okno-frame-placeholder' ),
		frameHelp: document.getElementById( 'okno-frame-help' ),
		panel: document.getElementById( 'okno-panel' ),
		save: document.getElementById( 'okno-save' ),
		publish: document.getElementById( 'okno-publish' ),
		banner: document.getElementById( 'okno-deploy-banner' ),
		undo: document.getElementById( 'okno-undo' ),
		redo: document.getElementById( 'okno-redo' ),
		tabHistory: document.getElementById( 'okno-tab-history' ),
		historyList: document.getElementById( 'okno-history-list' ),
		saveState: document.getElementById( 'okno-save-state' ),
	};

	/* ------------------------------------------------------------------ *
	 * Utilitaires
	 * ------------------------------------------------------------------ */

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, ms );
		};
	}

	function uid( prefix ) {
		state.uid += 1;
		return 'okno-' + prefix + '-' + state.uid;
	}

	function clone( value ) {
		return value === undefined ? value : JSON.parse( JSON.stringify( value ) );
	}

	/**
	 * Icônes en SVG inline plutôt qu'en police Dashicons : une police qui ne
	 * charge pas (plugin qui la déscharge, fichier absent) rendait des carrés
	 * « tofu » larges qui débordaient sur les libellés.
	 */
	var ICON_PATHS = {
		page: 'M6 2h7l5 5v15H6z M13 2v5h5',
		section: 'M3 5h18 M3 12h18 M3 19h12',
		folder: 'M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
		chevron: 'M9 5l7 7-7 7',
		desktop: 'M3 4h18v12H3z M8 20h8 M12 16v4',
		tablet: 'M6 3h12v18H6z M11 18h2',
		mobile: 'M8 2h8v20H8z M11 19h2',
		back: 'M15 5l-7 7 7 7',
		undo: 'M9 7l-5 5 5 5 M4 12h10a5 5 0 0 1 0 10h-3',
		redo: 'M15 7l5 5-5 5 M20 12H10a5 5 0 0 0 0 10h3',
		moon: 'M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z',
		sun: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z M12 3v2 M12 19v2 M3 12h2 M19 12h2 M5.6 5.6l1.4 1.4 M17 17l1.4 1.4 M5.6 18.4L7 17 M17 7l1.4-1.4',
	};

	function icon( name, size ) {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', 'okno-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', size || 15 );
		svg.setAttribute( 'height', size || 15 );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.8' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		( ICON_PATHS[ name ] || '' ).split( ' M' ).forEach( function ( d, i ) {
			var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
			path.setAttribute( 'd', 0 === i ? d : 'M' + d );
			svg.appendChild( path );
		} );

		return svg;
	}

	function fieldKey( postId, path ) {
		return String( postId ) + ':' + path;
	}

	function markDirty( postId, path, value ) {
		postId = String( postId );
		if ( ! state.dirtyByPost[ postId ] ) {
			state.dirtyByPost[ postId ] = {};
		}
		state.dirtyByPost[ postId ][ path ] = value;
		delete state.fieldErrors[ fieldKey( postId, path ) ];
		scheduleDraft();
	}

	function isDirty() {
		return Object.keys( state.dirtyByPost ).some( function ( postId ) {
			return Object.keys( state.dirtyByPost[ postId ] ).length > 0;
		} );
	}

	function confirmLoseChanges() {
		return ! isDirty() || window.confirm( __( 'You have unsaved changes. Continuing will discard them. Continue?', 'okno' ) );
	}

	function dirtyCount() {
		return Object.keys( state.dirtyByPost ).reduce( function ( total, postId ) {
			return total + Object.keys( state.dirtyByPost[ postId ] ).length;
		}, 0 );
	}

	function setButtons() {
		els.save.disabled = ! isDirty() || state.saving;
		// Site qui lit le contenu en direct : enregistrer = mettre en ligne.
		els.publish.hidden = !! cfg.liveMode;
		els.publish.disabled = ! state.post || ! cfg.canDeploy || ! cfg.driverConfigured;
		els.publish.title = ! cfg.driverConfigured
			? __( 'No publishing method is set up in the settings.', 'okno' )
			: '';
		if ( els.saveState ) {
			var count = dirtyCount();
			els.saveState.classList.toggle( 'is-dirty', count > 0 );
			els.saveState.textContent = ! state.post
				? ''
				: state.saving
				? __( 'Saving…', 'okno' )
				: count
				? /* translators: %d: number of unsaved changes. */
				  sprintf( _n( '%d unsaved change', '%d unsaved changes', count, 'okno' ), count )
				: cfg.liveMode
				? __( 'Everything is live', 'okno' )
				: __( 'Everything is saved', 'okno' );
		}
		if ( els.undo ) {
			els.undo.disabled = state.history.index <= 0;
		}
		if ( els.redo ) {
			els.redo.disabled = state.history.index < 0 || state.history.index >= state.history.stack.length - 1;
		}
	}

	var liveRegion = document.createElement( 'div' );
	liveRegion.className = 'screen-reader-text';
	liveRegion.setAttribute( 'aria-live', 'polite' );
	liveRegion.setAttribute( 'role', 'status' );
	document.body.appendChild( liveRegion );

	function announceToScreenReader( message ) {
		liveRegion.textContent = '';
		setTimeout( function () {
			liveRegion.textContent = message;
		}, 50 );
	}

	function toast( message, isError ) {
		var el = document.createElement( 'div' );
		el.className = 'okno-toast' + ( isError ? ' okno-toast-error' : '' );
		el.textContent = message;
		document.body.appendChild( el );
		announceToScreenReader( message );
		setTimeout( function () {
			el.classList.add( 'okno-toast-visible' );
		}, 10 );
		setTimeout( function () {
			el.classList.remove( 'okno-toast-visible' );
			setTimeout( function () {
				el.remove();
			}, 300 );
		}, 4000 );
	}

	/* ------------------------------------------------------------------ *
	 * Historique local : annuler / rétablir (E2)
	 * ------------------------------------------------------------------ */

	// Instantané des valeurs éditables + du dirty. Suffisant : l'éditeur ne
	// modifie jamais la structure d'un schéma, seulement des valeurs.
	function snapshot() {
		var values = {};
		Object.keys( state.fields ).forEach( function ( key ) {
			var field = state.fields[ key ];
			values[ key ] = { value: clone( field.value ), meta: clone( field.meta ) };
		} );
		return JSON.stringify( {
			values: values,
			dirty: state.dirtyByPost,
			structural: state.structuralDirty,
		} );
	}

	function pushHistory() {
		if ( state.history.muted ) {
			return;
		}
		var snap = snapshot();
		if ( state.history.stack[ state.history.index ] === snap ) {
			return;
		}
		state.history.stack = state.history.stack.slice( 0, state.history.index + 1 );
		state.history.stack.push( snap );
		if ( state.history.stack.length > HISTORY_MAX ) {
			state.history.stack.shift();
		}
		state.history.index = state.history.stack.length - 1;
		setButtons();
	}

	var pushHistoryDebounced = debounce( pushHistory, 400 );

	function resetHistory() {
		state.history = { stack: [], index: -1, muted: false };
		pushHistory();
	}

	function restore( snap ) {
		var data = JSON.parse( snap );
		Object.keys( data.values ).forEach( function ( key ) {
			var field = state.fields[ key ];
			if ( field ) {
				field.value = clone( data.values[ key ].value );
				field.meta = clone( data.values[ key ].meta );
			}
		} );
		state.dirtyByPost = clone( data.dirty );
		state.structuralDirty = clone( data.structural );
		renderInspector();
		renderStructure();
		hydrateFrame();
		setButtons();
		scheduleDraft();
	}

	function undo() {
		if ( state.history.index <= 0 ) {
			return;
		}
		state.history.index -= 1;
		restore( state.history.stack[ state.history.index ] );
		announceToScreenReader( __( 'Change undone.', 'okno' ) );
	}

	function redo() {
		if ( state.history.index >= state.history.stack.length - 1 ) {
			return;
		}
		state.history.index += 1;
		restore( state.history.stack[ state.history.index ] );
		announceToScreenReader( __( 'Change redone.', 'okno' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Brouillon local (E2) : rien n'est écrit en base sans action explicite,
	 * mais un onglet fermé ne perd plus le travail en cours.
	 * ------------------------------------------------------------------ */

	function draftKey( postId ) {
		return 'okno-draft-' + postId;
	}

	function scheduleDraft() {
		clearTimeout( state.draftTimer );
		state.draftTimer = setTimeout( writeDraft, AUTOSAVE_MS );
	}

	function writeDraft() {
		if ( ! state.post ) {
			return;
		}
		var schema = state.schemas[ String( state.post.id ) ];
		try {
			if ( ! isDirty() ) {
				window.localStorage.removeItem( draftKey( state.post.id ) );
				return;
			}
			window.localStorage.setItem(
				draftKey( state.post.id ),
				JSON.stringify( {
					revision: schema ? schema.revision : '',
					savedAt: Date.now(),
					dirty: state.dirtyByPost,
					structural: state.structuralDirty,
				} )
			);
		} catch ( e ) {
			/* Stockage plein ou indisponible : le garde beforeunload reste. */
		}
	}

	function readDraft( postId ) {
		try {
			var raw = window.localStorage.getItem( draftKey( postId ) );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function clearDraft( postId ) {
		try {
			window.localStorage.removeItem( draftKey( postId ) );
		} catch ( e ) {
			/* rien à faire. */
		}
	}

	// Réapplique un brouillon sur les schémas chargés.
	function applyDraft( draft ) {
		Object.keys( draft.dirty || {} ).forEach( function ( postId ) {
			Object.keys( draft.dirty[ postId ] ).forEach( function ( path ) {
				var value = draft.dirty[ postId ][ path ];
				var field = state.fields[ fieldKey( postId, path ) ];
				if ( field ) {
					field.value = clone( value );
				}
				markDirty( postId, path, value );
			} );
		} );
		state.structuralDirty = clone( draft.structural || {} );
	}

	/* ------------------------------------------------------------------ *
	 * postMessage vers/depuis le bridge
	 * ------------------------------------------------------------------ */

	function postToFrame( message ) {
		if ( ! els.frame.contentWindow || ! cfg.frontOrigin ) {
			return;
		}
		message.ns = 'okno';
		els.frame.contentWindow.postMessage( message, cfg.frontOrigin );
	}

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== cfg.frontOrigin ) {
			return;
		}
		if ( ! els.frame.contentWindow || event.source !== els.frame.contentWindow ) {
			return;
		}
		var data = event.data;
		if ( ! data || data.ns !== 'okno' ) {
			return;
		}

		if ( 'ready' === data.type ) {
			onBridgeReady( data );
		} else if ( 'fieldFocus' === data.type ) {
			onFieldFocusFromFrame( String( data.postId ), data.path );
		} else if ( 'sectionFocus' === data.type ) {
			selectNode( data.id, { fromFrame: true } );
		} else if ( 'navigate' === data.type ) {
			onNavigateFromFrame( data );
		} else if ( 'managedFocus' === data.type ) {
			showManaged( data );
		} else if ( 'cloakTimeout' === data.type ) {
			toast( __( 'The preview loaded without your content: the handshake did not complete.', 'okno' ), true );
		}
	} );

	/**
	 * E1 : un clic sur un lien dans l'aperçu bascule l'éditeur sur la page
	 * correspondante — se déplacer dans son site est le geste le plus naturel
	 * de l'édition visuelle, il était purement et simplement bloqué.
	 */
	function onNavigateFromFrame( data ) {
		if ( data.external ) {
			/* translators: %s: URL of the external link. */
			if ( window.confirm( sprintf( __( 'This link leaves the site (%s). Open it in a new tab?', 'okno' ), data.href ) ) ) {
				window.open( data.href, '_blank', 'noopener' );
			}
			return;
		}

		var target = findPostByPath( data.path );
		if ( target ) {
			if ( state.post && target.id === state.post.id ) {
				return;
			}
			if ( confirmLoseChanges() ) {
				openPost( target );
			}
			return;
		}

		// Page inconnue d'Okno (hors post types configurés, ou URL non mappée) :
		// on recharge quand même l'aperçu dessus pour ne pas bloquer la visite.
		toast( __( 'This page can’t be edited with Okno — preview only.', 'okno' ) );
		loadFrame( data.href );
	}

	/**
	 * Un clic sur un contenu géré ailleurs (menu, liste, produit) : on explique
	 * où le modifier au lieu de ne rien faire.
	 */
	/** Noms lisibles des fournisseurs de contenu courants (clé data-okno-managed). */
	var MANAGED_PROVIDERS = {
		wordpress: 'WordPress',
		menu: __( 'WordPress menus', 'okno' ),
		acf: 'ACF',
		woocommerce: 'WooCommerce',
		jetengine: 'JetEngine',
	};

	function managedLabel( provider ) {
		var name = MANAGED_PROVIDERS[ provider ] || provider || 'WordPress';
		/* translators: %s: name of the tool that manages this content (e.g. WooCommerce). */
		return sprintf( __( 'Managed in %s', 'okno' ), name );
	}

	function showManaged( data ) {
		state.selection = 'managed';
		renderStructure();
		els.panel.innerHTML = '';

		var card = document.createElement( 'div' );
		card.className = 'okno-managed';

		var title = document.createElement( 'h2' );
		title.textContent = data.label || managedLabel( data.provider );
		card.appendChild( title );

		var text = document.createElement( 'p' );
		text.textContent = __( 'This content doesn’t come from a field on this page: it’s edited on its own WordPress screen. Changes made there will show up here once saved.', 'okno' );
		card.appendChild( text );

		// On n'ouvre que des écrans de ce wp-admin : l'URL vient du HTML du site,
		// elle ne doit pas pouvoir envoyer l'éditeur n'importe où.
		var url = null;
		try {
			url = data.editUrl ? new URL( data.editUrl, cfg.wpOrigin ) : null;
		} catch ( e ) {
			url = null;
		}
		if ( url && url.origin === cfg.wpOrigin ) {
			var link = document.createElement( 'a' );
			link.className = 'okno-btn';
			link.href = url.href;
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = __( 'Open the edit screen', 'okno' );
			card.appendChild( link );
		}

		els.panel.appendChild( card );
	}

	function normalizePath( path ) {
		if ( ! path ) {
			return '/';
		}
		var clean = path.split( '?' )[ 0 ].split( '#' )[ 0 ];
		if ( clean.length > 1 && '/' === clean.charAt( clean.length - 1 ) ) {
			clean = clean.slice( 0, -1 );
		}
		return clean || '/';
	}

	function findPostByPath( path ) {
		var wanted = normalizePath( path );
		return state.posts.find( function ( post ) {
			return [ post.preview_url, post.front_url ].some( function ( url ) {
				if ( ! url ) {
					return false;
				}
				try {
					return normalizePath( new URL( url ).pathname ) === wanted;
				} catch ( e ) {
					return false;
				}
			} );
		} );
	}

	function onBridgeReady( data ) {
		state.bridgeReady = true;
		state.bridgeVersion = data.version || 1;
		clearTimeout( state.handshakeTimer );
		els.frameHelp.hidden = true;

		// Navigation client (pushState d'une app React, Next…) : le site a changé
		// de page sans recharger l'iframe. On bascule l'éditeur sur ce contenu.
		if ( data.url && state.post && samePageChanged( state.frameUrl, data.url ) ) {
			state.frameUrl = data.url;
			var landed = findPostByPath( new URL( data.url ).pathname );
			if ( landed && landed.id !== state.post.id ) {
				if ( isDirty() ) {
					writeDraft();
					toast( __( 'Your changes to the previous page were kept as an unsaved draft.', 'okno' ) );
				}
				// Le handshake sera rejoué une fois le schéma de la nouvelle page
				// chargé : sinon elle serait prise pour un contenu « global ».
				openPost( landed, { keepFrame: true, ready: data } );
				return;
			}
		} else if ( data.url ) {
			state.frameUrl = data.url;
		}

		state.knownFields = {};
		var announcedPosts = data.posts || {};
		Object.keys( announcedPosts ).forEach( function ( postId ) {
			( announcedPosts[ postId ] || [] ).forEach( function ( f ) {
				state.knownFields[ fieldKey( postId, f.path ) ] = { refresh: !! f.refresh, ratio: f.ratio || 0 };
			} );
		} );

		state.sections = ( data.sections || [] ).map( function ( section, i ) {
			// Libellé par défaut composé ici (le bridge ne connaît pas la langue de wp-admin).
			if ( ! section.label ) {
				section.label =
					'header' === section.auto
						? __( 'Header', 'okno' )
						: 'footer' === section.auto
						? __( 'Footer', 'okno' )
						: /* translators: %d: position of the section on the page. */
						  sprintf( __( 'Section %d', 'okno' ), i + 1 );
			}
			return section;
		} );

		// Posts annoncés mais pas encore chargés : les globaux (header, footer…).
		var missing = Object.keys( announcedPosts ).filter( function ( postId ) {
			return ! state.schemas[ postId ];
		} );

		Promise.all(
			missing.map( function ( postId ) {
				return apiFetch( { path: '/okno/v1/schema/' + postId } )
					.then( function ( schema ) {
						indexSchema( schema, { global: true } );
					} )
					.catch( function () {
						/* Pas la permission ou post inconnu : champs non éditables. */
					} );
			} )
		).then( function () {
			renderStructure();
			renderInspector();
			hydrateFrame();
			pushHistory();

			// Lien profond (?field=…) : on ouvre le champ demandé une seule fois.
			if ( state.pendingField && state.post ) {
				var target = state.pendingField;
				state.pendingField = '';
				onFieldFocusFromFrame( String( state.post.id ), target );
				postToFrame( { type: 'focusField', postId: String( state.post.id ), path: target } );
			}
		} );
	}

	function samePageChanged( before, after ) {
		try {
			var a = new URL( before, window.location.href );
			var b = new URL( after );
			return normalizePath( a.pathname ) !== normalizePath( b.pathname );
		} catch ( e ) {
			return false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Schémas et valeurs
	 * ------------------------------------------------------------------ */

	function indexSchema( schema, options ) {
		var postId = String( schema.post_id );
		schema._global = !! ( options && options.global );
		state.schemas[ postId ] = schema;
		schema.groups.forEach( function ( group ) {
			group.fields.forEach( function ( field ) {
				field._post = postId;
				field._group = group.title;
				state.fields[ fieldKey( postId, field.path ) ] = field;
			} );
		} );
	}

	/** Sous-champs applicables : ceux du layout pour une ligne de flexible. */
	function rowSubs( def, row ) {
		if ( 'flexible_content' !== def.type ) {
			return def.sub_fields || [];
		}
		var layout = ( def.layouts || [] ).find( function ( l ) {
			return l.name === ( row && row._layout );
		} );
		return layout ? layout.sub_fields : [];
	}

	function layoutOf( def, row ) {
		return ( def.layouts || [] ).find( function ( l ) {
			return l.name === ( row && row._layout );
		} );
	}

	// Valeur « affichable » d'un champ simple pour l'aperçu. null = rien à pousser.
	function displayValue( def, value, meta ) {
		meta = meta || {};
		switch ( def.type ) {
			case 'image':
			case 'file':
				return meta.url || '';
			case 'wysiwyg':
				return meta.rendered !== undefined ? meta.rendered : value || '';
			case 'select':
				return def.options && def.options[ value ] !== undefined ? def.options[ value ] : value || '';
			case 'post_object':
				return meta.label || '';
			case 'link':
				return value && 'object' === typeof value ? value : { url: value || '' };
			case 'true_false':
			case 'gallery':
			case 'relationship':
			case 'taxonomy':
			case 'repeater':
			case 'group':
			case 'clone':
			case 'flexible_content':
				return null;
			default:
				return value === null || value === undefined ? '' : String( value );
		}
	}

	/**
	 * Parcourt un champ et ses descendants, en émettant les chemins pointés
	 * exactement tels que le bridge les attend (rep.0.sous, group.sous).
	 */
	function walkField( def, value, meta, basePath, emit ) {
		if ( 'repeater' === def.type || 'flexible_content' === def.type ) {
			( value || [] ).forEach( function ( row, i ) {
				var rowMeta = ( meta || [] )[ i ] || {};
				rowSubs( def, row ).forEach( function ( sub ) {
					walkField( sub, row[ sub.path ], rowMeta[ sub.path ], basePath + '.' + i + '.' + sub.path, emit );
				} );
			} );
			return;
		}

		if ( 'group' === def.type || 'clone' === def.type ) {
			( def.sub_fields || [] ).forEach( function ( sub ) {
				walkField( sub, ( value || {} )[ sub.path ], ( meta || {} )[ sub.path ], basePath + '.' + sub.path, emit );
			} );
			return;
		}

		if ( false === def.supported ) {
			return;
		}

		var display = displayValue( def, value, meta );
		if ( null !== display ) {
			emit( basePath, display, def.type );
		}
	}

	function hydrateFrame() {
		var values = {};
		Object.keys( state.schemas ).forEach( function ( postId ) {
			var bucket = ( values[ postId ] = {} );
			state.schemas[ postId ].groups.forEach( function ( group ) {
				group.fields.forEach( function ( field ) {
					if ( false === field.supported ) {
						return;
					}
					walkField( field, field.value, field.meta, field.path, function ( path, v, type ) {
						bucket[ path ] = { value: v, fieldType: type };
					} );
				} );
			} );
		} );
		postToFrame( { type: 'init', values: values } );
	}

	var pushPreview = debounce( function ( postId, path, value, fieldType ) {
		if ( ! state.bridgeReady ) {
			return;
		}
		var known = state.knownFields[ fieldKey( postId, path ) ];
		if ( ! known || known.refresh ) {
			return;
		}
		postToFrame( {
			type: 'fieldUpdate',
			postId: postId,
			path: path,
			value: value,
			fieldType: fieldType,
		} );
	}, 120 );

	function previewUpdate( topField, def, path, value, meta ) {
		if ( state.structuralDirty[ fieldKey( topField._post, topField.path ) ] ) {
			return; // La structure a bougé : l'aperçu sera rechargé après save.
		}
		var display = displayValue( def, value, meta );
		if ( null === display ) {
			return;
		}
		pushPreview( topField._post, path, display, def.type );
	}

	/* ------------------------------------------------------------------ *
	 * Onglets, liste des pages
	 * ------------------------------------------------------------------ */

	function switchTab( tab ) {
		var tabs = {
			pages: [ els.tabPages, els.pagesList ],
			structure: [ els.tabStructure, els.structureTree ],
			history: [ els.tabHistory, els.historyList ],
		};
		Object.keys( tabs ).forEach( function ( name ) {
			var pair = tabs[ name ];
			if ( ! pair[ 0 ] || ! pair[ 1 ] ) {
				return;
			}
			var on = name === tab;
			pair[ 0 ].classList.toggle( 'active', on );
			pair[ 0 ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
			pair[ 1 ].hidden = ! on;
		} );
		if ( 'history' === tab ) {
			renderHistory();
		}
	}

	els.tabPages.setAttribute( 'role', 'tab' );
	els.tabStructure.setAttribute( 'role', 'tab' );
	els.tabPages.setAttribute( 'aria-controls', 'okno-pages-list' );
	els.tabStructure.setAttribute( 'aria-controls', 'okno-structure-tree' );
	els.pagesList.setAttribute( 'role', 'tabpanel' );
	els.pagesList.setAttribute( 'aria-label', __( 'Site pages', 'okno' ) );
	els.structureTree.setAttribute( 'role', 'tabpanel' );
	els.structureTree.setAttribute( 'aria-label', __( 'Page structure', 'okno' ) );
	if ( els.tabPages.parentElement ) {
		els.tabPages.parentElement.setAttribute( 'role', 'tablist' );
	}

	els.tabPages.addEventListener( 'click', function () {
		switchTab( 'pages' );
	} );
	els.tabStructure.addEventListener( 'click', function () {
		switchTab( 'structure' );
	} );
	if ( els.tabHistory ) {
		els.tabHistory.setAttribute( 'role', 'tab' );
		els.tabHistory.setAttribute( 'aria-controls', 'okno-history-list' );
		els.historyList.setAttribute( 'role', 'tabpanel' );
		els.historyList.setAttribute( 'aria-label', __( 'Page history', 'okno' ) );
		els.tabHistory.addEventListener( 'click', function () {
			switchTab( 'history' );
		} );
	}

	/**
	 * Liste paginée et cherchée côté serveur : un site de milliers de contenus
	 * reste navigable.
	 */
	function loadPosts( options ) {
		options = options || {};
		var page = options.append ? state.postsPage + 1 : 1;

		return apiFetch( {
			path:
				'/okno/v1/posts?per_page=' +
				PER_PAGE +
				'&page=' +
				page +
				'&search=' +
				encodeURIComponent( state.postsSearch ),
			parse: false,
		} )
			.then( function ( response ) {
				state.postsTotalPages = Number( response.headers.get( 'X-WP-TotalPages' ) || 1 );
				state.postsPage = page;
				return response.json();
			} )
			.then( function ( posts ) {
				state.posts = options.append ? state.posts.concat( posts ) : posts;
				renderPagesTab();

				if ( ! options.append && cfg.initialPost && ! state.post ) {
					var initial = state.posts.find( function ( p ) {
						return p.id === cfg.initialPost;
					} );
					if ( initial ) {
						openPost( initial );
					} else {
						// Absent de la page de liste chargée (pagination, recherche) :
						// on le demande directement.
						apiFetch( { path: '/okno/v1/posts/' + cfg.initialPost } )
							.then( openPost )
							.catch( function () {
								toast( __( 'This content no longer exists or you’re not allowed to edit it.', 'okno' ), true );
							} );
					}
				}
			} )
			.catch( function ( err ) {
				toast( ( err && err.message ) || __( 'Couldn’t load the content list.', 'okno' ), true );
			} );
	}

	// Onglet Pages : arborescence par dossier (segments du chemin sur le front),
	// avec recherche serveur. Comme un explorateur de fichiers.
	function postPath( post ) {
		try {
			return new URL( post.preview_url || post.front_url ).pathname;
		} catch ( e ) {
			return '/' + ( post.title || '' );
		}
	}

	function renderPagesTab() {
		els.pagesList.innerHTML = '';

		var searchId = uid( 'search' );
		var searchLabel = document.createElement( 'label' );
		searchLabel.className = 'screen-reader-text';
		searchLabel.setAttribute( 'for', searchId );
		searchLabel.textContent = __( 'Search pages', 'okno' );
		els.pagesList.appendChild( searchLabel );

		var search = document.createElement( 'input' );
		search.type = 'search';
		search.id = searchId;
		search.className = 'okno-pages-search';
		search.placeholder = __( 'Search pages…', 'okno' );
		search.value = state.postsSearch;
		els.pagesList.appendChild( search );

		var treeWrap = document.createElement( 'div' );
		treeWrap.setAttribute( 'role', 'tree' );
		treeWrap.setAttribute( 'aria-label', __( 'Editable content', 'okno' ) );
		els.pagesList.appendChild( treeWrap );

		if ( state.postsPage < state.postsTotalPages ) {
			var more = document.createElement( 'button' );
			more.type = 'button';
			more.className = 'okno-btn okno-btn--quiet okno-pages-more';
			more.textContent = __( 'Load more content', 'okno' );
			more.addEventListener( 'click', function () {
				more.disabled = true;
				loadPosts( { append: true } );
			} );
			els.pagesList.appendChild( more );
		}

		els.pagesList.appendChild( renderCreateBox() );

		buildTree( treeWrap );

		search.addEventListener(
			'input',
			debounce( function () {
				state.postsSearch = search.value.trim();
				loadPosts().then( function () {
					var next = els.pagesList.querySelector( '.okno-pages-search' );
					if ( next ) {
						next.focus();
						next.setSelectionRange( next.value.length, next.value.length );
					}
				} );
			}, 300 )
		);
	}

	function buildTree( treeWrap ) {
		treeWrap.innerHTML = '';
		var root = { folders: {}, pages: [] };

		state.posts.forEach( function ( post ) {
			var segments = postPath( post ).split( '/' ).filter( Boolean );
			var node = root;
			segments.slice( 0, -1 ).forEach( function ( seg ) {
				if ( ! node.folders[ seg ] ) {
					node.folders[ seg ] = { folders: {}, pages: [] };
				}
				node = node.folders[ seg ];
			} );
			node.pages.push( post );
		} );

		renderLevel( root, treeWrap, 0, !! state.postsSearch );
		markActivePage();
	}

	function renderLevel( node, parent, depth, expandAll ) {
		Object.keys( node.folders ).sort().forEach( function ( name ) {
			var folder = node.folders[ name ];

			var row = document.createElement( 'button' );
			row.type = 'button';
			row.className = 'okno-tree-folder';
			row.style.paddingLeft = 8 + depth * 14 + 'px';
			row.setAttribute( 'aria-expanded', expandAll ? 'true' : 'false' );

			var chevron = icon( 'chevron', 13 );
			chevron.classList.add( 'okno-tree-chevron' );
			row.appendChild( chevron );

			row.appendChild( icon( 'folder', 14 ) );

			var text = document.createElement( 'span' );
			text.className = 'okno-tree-label';
			text.textContent = name;
			row.appendChild( text );

			parent.appendChild( row );

			var children = document.createElement( 'div' );
			children.setAttribute( 'role', 'group' );
			children.hidden = ! expandAll;
			chevron.classList.toggle( 'open', expandAll );
			parent.appendChild( children );

			row.addEventListener( 'click', function () {
				children.hidden = ! children.hidden;
				chevron.classList.toggle( 'open', ! children.hidden );
				row.setAttribute( 'aria-expanded', children.hidden ? 'false' : 'true' );
			} );

			renderLevel( folder, children, depth + 1, expandAll );
		} );

		node.pages
			.slice()
			.sort( function ( a, b ) {
				return postPath( a ).localeCompare( postPath( b ) );
			} )
			.forEach( function ( post ) {
				parent.appendChild( renderPageItem( post, depth ) );
			} );
	}

	function renderPageItem( post, depth ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'okno-page-row';
		wrap.setAttribute( 'role', 'treeitem' );
		wrap.setAttribute( 'aria-selected', state.post && state.post.id === post.id ? 'true' : 'false' );

		var item = document.createElement( 'button' );
		item.type = 'button';
		item.className = 'okno-page-item';
		item.dataset.id = post.id;
		item.style.paddingLeft = 8 + depth * 14 + 18 + 'px';
		item.title = postPath( post );

		item.appendChild( icon( 'page', 13 ) );

		var name = document.createElement( 'span' );
		name.className = 'okno-page-item-title';
		name.textContent = post.title || __( '(no title)', 'okno' );
		item.appendChild( name );

		if ( 'publish' !== post.status ) {
			var status = document.createElement( 'span' );
			status.className = 'okno-page-item-status';
			status.textContent = post.status;
			item.appendChild( status );
		}

		item.addEventListener( 'click', function () {
			if ( state.post && state.post.id === post.id ) {
				return;
			}
			if ( ! confirmLoseChanges() ) {
				return;
			}
			openPost( post );
		} );

		wrap.appendChild( item );
		wrap.appendChild( renderPageActions( post ) );
		return wrap;
	}

	/** Dupliquer / supprimer, sans passer par wp-admin. */
	function renderPageActions( post ) {
		var actions = document.createElement( 'span' );
		actions.className = 'okno-page-actions';

		var duplicate = document.createElement( 'button' );
		duplicate.type = 'button';
		duplicate.className = 'okno-page-action';
		duplicate.textContent = '⧉';
		/* translators: %s: content title. */
		duplicate.title = sprintf( __( 'Duplicate “%s”', 'okno' ), post.title || '' );
		duplicate.setAttribute(
			'aria-label',
			post.title
				? /* translators: %s: content title. */
				  sprintf( __( 'Duplicate %s', 'okno' ), post.title )
				: __( 'Duplicate this content', 'okno' )
		);
		duplicate.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			duplicate.disabled = true;
			apiFetch( { path: '/okno/v1/posts/' + post.id + '/duplicate', method: 'POST' } )
				.then( function ( copy ) {
					/* translators: %s: content title. */
					toast( sprintf( __( '“%s” created as a draft.', 'okno' ), copy.title ) );
					return loadPosts();
				} )
				.catch( function ( err ) {
					toast( ( err && err.message ) || __( 'Couldn’t duplicate this content.', 'okno' ), true );
				} )
				.finally( function () {
					duplicate.disabled = false;
				} );
		} );
		actions.appendChild( duplicate );

		if ( post.can_delete ) {
			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'okno-page-action okno-page-action-danger';
			remove.textContent = '×';
			/* translators: %s: content title. */
			remove.title = sprintf( __( 'Move “%s” to trash', 'okno' ), post.title || '' );
			remove.setAttribute(
				'aria-label',
				post.title
					? /* translators: %s: content title. */
					  sprintf( __( 'Move %s to trash', 'okno' ), post.title )
					: __( 'Move this content to trash', 'okno' )
			);
			remove.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				/* translators: %s: content title. */
				if ( ! window.confirm( sprintf( __( 'Move “%s” to trash?', 'okno' ), post.title || '' ) ) ) {
					return;
				}
				apiFetch( { path: '/okno/v1/posts/' + post.id, method: 'DELETE' } )
					.then( function () {
						toast( __( 'Content moved to trash.', 'okno' ) );
						if ( state.post && state.post.id === post.id ) {
							closePost();
						}
						return loadPosts();
					} )
					.catch( function ( err ) {
						toast( ( err && err.message ) || __( 'Couldn’t move this content to trash.', 'okno' ), true );
					} );
			} );
			actions.appendChild( remove );
		}

		return actions;
	}

	/** Création d'un contenu depuis l'éditeur. */
	function renderCreateBox() {
		var box = document.createElement( 'div' );
		box.className = 'okno-pages-create';

		var types = ( cfg.postTypes || [] ).filter( function ( t ) {
			return t.canCreate;
		} );
		if ( ! types.length ) {
			return box;
		}

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'okno-btn okno-btn--quiet okno-create-toggle';
		toggle.textContent = __( '+ New content', 'okno' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		box.appendChild( toggle );

		var form = document.createElement( 'form' );
		form.className = 'okno-create-form';
		form.hidden = true;

		var titleId = uid( 'new-title' );
		var typeId = uid( 'new-type' );

		var titleLabel = document.createElement( 'label' );
		titleLabel.setAttribute( 'for', titleId );
		titleLabel.textContent = __( 'Title', 'okno' );
		form.appendChild( titleLabel );

		var title = document.createElement( 'input' );
		title.type = 'text';
		title.id = titleId;
		title.required = true;
		form.appendChild( title );

		var typeLabel = document.createElement( 'label' );
		typeLabel.setAttribute( 'for', typeId );
		typeLabel.textContent = __( 'Type', 'okno' );
		form.appendChild( typeLabel );

		var type = document.createElement( 'select' );
		type.id = typeId;
		types.forEach( function ( t ) {
			var opt = document.createElement( 'option' );
			opt.value = t.name;
			opt.textContent = t.label;
			type.appendChild( opt );
		} );
		form.appendChild( type );

		var submit = document.createElement( 'button' );
		submit.type = 'submit';
		submit.className = 'okno-btn';
		submit.textContent = __( 'Create as draft', 'okno' );
		form.appendChild( submit );

		toggle.addEventListener( 'click', function () {
			form.hidden = ! form.hidden;
			toggle.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );
			if ( ! form.hidden ) {
				title.focus();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( ! title.value.trim() ) {
				return;
			}
			submit.disabled = true;
			apiFetch( {
				path: '/okno/v1/posts',
				method: 'POST',
				data: { title: title.value.trim(), post_type: type.value },
			} )
				.then( function ( post ) {
					title.value = '';
					form.hidden = true;
					toggle.setAttribute( 'aria-expanded', 'false' );
					/* translators: %s: content title. */
					toast( sprintf( __( '“%s” created as a draft.', 'okno' ), post.title ) );
					return loadPosts().then( function () {
						var fresh = state.posts.find( function ( p ) {
							return p.id === post.id;
						} );
						if ( fresh && confirmLoseChanges() ) {
							openPost( fresh );
						}
					} );
				} )
				.catch( function ( err ) {
					toast( ( err && err.message ) || __( 'Couldn’t create this content.', 'okno' ), true );
				} )
				.finally( function () {
					submit.disabled = false;
				} );
		} );

		box.appendChild( form );
		return box;
	}

	function markActivePage() {
		els.pagesList.querySelectorAll( '.okno-page-item' ).forEach( function ( item ) {
			var active = !! state.post && Number( item.dataset.id ) === state.post.id;
			item.classList.toggle( 'active', active );
			if ( item.parentElement ) {
				item.parentElement.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Ouverture d'une page
	 * ------------------------------------------------------------------ */

	function closePost() {
		state.post = null;
		state.schemas = {};
		state.fields = {};
		state.dirtyByPost = {};
		state.fieldErrors = {};
		state.structuralDirty = {};
		state.structureStale = false;
		state.sections = [];
		state.selection = 'page';
		els.title.textContent = '';
		els.tabStructure.disabled = true;
		els.frame.hidden = true;
		els.framePlaceholder.hidden = false;
		renderInspector();
		renderStructure();
		setButtons();
		switchTab( 'pages' );
	}

	function openPost( post, options ) {
		options = options || {};
		if ( ! post.preview_url ) {
			toast( __( 'This content has no mapped URL. Check the Okno settings.', 'okno' ), true );
			return;
		}

		state.post = post;
		state.schemas = {};
		state.fields = {};
		state.dirtyByPost = {};
		state.structuralDirty = {};
		state.structureStale = false;
		state.sections = [];
		state.selection = 'page';

		els.title.textContent = post.title || '';
		els.tabStructure.disabled = false;
		if ( els.tabHistory ) {
			els.tabHistory.disabled = false;
		}
		markActivePage();
		setButtons();

		return apiFetch( { path: '/okno/v1/schema/' + post.id } ).then( function ( schema ) {
			indexSchema( schema );

			var draft = readDraft( post.id );
			if ( draft && draft.revision === schema.revision && draft.dirty ) {
				var when = new Date( draft.savedAt );
				/* translators: %s: date and time the unsaved changes were made. */
				if ( window.confirm( sprintf( __( 'Unsaved changes from %s were found. Restore them?', 'okno' ), when.toLocaleString( LOCALE ) ) ) ) {
					applyDraft( draft );
				} else {
					clearDraft( post.id );
				}
			} else if ( draft ) {
				clearDraft( post.id );
			}

			resetHistory();
			renderStructure();
			renderInspector();
			if ( options.keepFrame ) {
				// L'iframe est déjà sur la bonne page (navigation client du site) :
				// on rejoue son handshake au lieu de la recharger.
				if ( options.ready ) {
					onBridgeReady( options.ready );
				} else {
					hydrateFrame();
				}
			} else {
				loadFrame( post.preview_url );
			}
			switchTab( 'structure' );
			setButtons();
		} );
	}

	function loadFrame( url ) {
		state.bridgeReady = false;
		state.knownFields = {};
		els.framePlaceholder.hidden = true;
		els.frameHelp.hidden = true;
		els.frame.hidden = false;

		var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
		state.frameUrl = url;
		els.frame.src = url + sep + 'okno=' + Date.now();

		clearTimeout( state.handshakeTimer );
		state.handshakeTimer = setTimeout( function () {
			if ( ! state.bridgeReady ) {
				els.frameHelp.hidden = false;
			}
		}, cfg.handshakeTimeout || 8000 );
	}

	/* ------------------------------------------------------------------ *
	 * Structure (arbre des sections) + opérations structurelles
	 * ------------------------------------------------------------------ */

	/**
	 * Résout un chemin pointé vers son conteneur : { topField, def, value, meta }.
	 * Rend null si le chemin ne correspond à rien de chargé.
	 */
	function resolvePath( postId, path ) {
		var parts = path.split( '.' );
		var top = state.fields[ fieldKey( postId, parts[ 0 ] ) ];
		if ( ! top ) {
			return null;
		}

		var def = top;
		var value = top.value;
		var meta = top.meta;

		for ( var i = 1; i < parts.length; i++ ) {
			var seg = parts[ i ];
			if ( ( 'repeater' === def.type || 'flexible_content' === def.type ) && ! isNaN( Number( seg ) ) ) {
				var index = Number( seg );
				var row = ( value || [] )[ index ];
				if ( ! row ) {
					return null;
				}
				var subs = rowSubs( def, row );
				var next = parts[ ++i ];
				var sub = subs.find( function ( s ) {
					return s.path === next;
				} );
				if ( ! sub ) {
					return null;
				}
				meta = ( ( meta || [] )[ index ] || {} )[ next ];
				value = row[ next ];
				def = sub;
				continue;
			}

			var owned = ( def.sub_fields || [] ).find( function ( s ) {
				return s.path === seg;
			} );
			if ( ! owned ) {
				return null;
			}
			meta = ( meta || {} )[ seg ];
			value = ( value || {} )[ seg ];
			def = owned;
		}

		return { topField: top, def: def, value: value, meta: meta };
	}

	/** Champs flexible content présents sur la page, via les sections annoncées. */
	function layoutHosts() {
		var hosts = [];
		state.sections.forEach( function ( section ) {
			if ( ! section.layout || ! section.layout.post || ! section.layout.path ) {
				return;
			}
			var key = section.layout.post + ':' + section.layout.path;
			if ( hosts.some( function ( h ) {
				return h.key === key;
			} ) ) {
				return;
			}
			var resolved = resolvePath( section.layout.post, section.layout.path );
			if ( resolved && 'flexible_content' === resolved.def.type ) {
				hosts.push( {
					key: key,
					post: section.layout.post,
					path: section.layout.path,
					resolved: resolved,
				} );
			}
		} );
		return hosts;
	}

	function commitStructure( host ) {
		var top = host.resolved.topField;
		markDirty( top._post, top.path, top.value );
		state.structuralDirty[ fieldKey( top._post, top.path ) ] = true;
		state.structureStale = true;
		pushHistory();
		renderStructure();
		renderInspector();
		setButtons();
	}

	function emptyRowFor( subs ) {
		var row = {};
		( subs || [] ).forEach( function ( sub ) {
			if ( 'repeater' === sub.type || 'flexible_content' === sub.type ) {
				row[ sub.path ] = [];
			} else if ( 'group' === sub.type || 'clone' === sub.type ) {
				row[ sub.path ] = emptyRowFor( sub.sub_fields );
			} else if ( 'true_false' === sub.type ) {
				row[ sub.path ] = false;
			} else if ( 'gallery' === sub.type || 'relationship' === sub.type || 'taxonomy' === sub.type ) {
				row[ sub.path ] = [];
			} else if ( 'link' === sub.type ) {
				row[ sub.path ] = { url: '', title: '', target: '' };
			} else {
				row[ sub.path ] = '';
			}
		} );
		return row;
	}

	function moveRow( host, index, dir ) {
		var rows = host.resolved.value;
		var meta = host.resolved.meta || [];
		var target = index + dir;
		if ( target < 0 || target >= rows.length ) {
			return;
		}
		var tmp = rows[ index ];
		rows[ index ] = rows[ target ];
		rows[ target ] = tmp;
		var tm = meta[ index ];
		meta[ index ] = meta[ target ];
		meta[ target ] = tm;
		commitStructure( host );
	}

	function duplicateRow( host, index ) {
		var rows = host.resolved.value;
		var meta = host.resolved.meta || [];
		rows.splice( index + 1, 0, clone( rows[ index ] ) );
		meta.splice( index + 1, 0, clone( meta[ index ] || {} ) );
		commitStructure( host );
	}

	function deleteRow( host, index, label ) {
		/* translators: %s: section name. */
		if ( ! window.confirm( sprintf( __( 'Delete the “%s” section?', 'okno' ), label ) ) ) {
			return;
		}
		host.resolved.value.splice( index, 1 );
		( host.resolved.meta || [] ).splice( index, 1 );
		commitStructure( host );
	}

	function addRow( host, layoutName, atIndex ) {
		var layout = ( host.resolved.def.layouts || [] ).find( function ( l ) {
			return l.name === layoutName;
		} );
		if ( ! layout ) {
			return;
		}
		var row = emptyRowFor( layout.sub_fields );
		row._layout = layout.name;
		var index = undefined === atIndex ? host.resolved.value.length : atIndex;
		host.resolved.value.splice( index, 0, row );
		( host.resolved.meta || [] ).splice( index, 0, {} );
		commitStructure( host );
		/* translators: %s: section type name. */
		announceToScreenReader( sprintf( __( '“%s” section added.', 'okno' ), layout.label ) );
	}

	function treeNode( id, iconName, label, count, options ) {
		options = options || {};
		var row = document.createElement( 'div' );
		row.className = 'okno-tree-row';
		row.setAttribute( 'role', 'treeitem' );
		row.setAttribute( 'aria-selected', state.selection === id ? 'true' : 'false' );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'okno-tree-node' + ( state.selection === id ? ' active' : '' );
		btn.dataset.node = id;

		btn.appendChild( icon( iconName ) );

		var text = document.createElement( 'span' );
		text.className = 'okno-tree-label';
		text.textContent = label;
		btn.appendChild( text );

		if ( count ) {
			var badge = document.createElement( 'span' );
			badge.className = 'okno-tree-count';
			badge.textContent = count;
			/* translators: %d: number of editable fields. */
			badge.title = sprintf( _n( '%d editable field', '%d editable fields', count, 'okno' ), count );
			btn.appendChild( badge );
		}

		btn.addEventListener( 'click', function () {
			selectNode( id );
		} );
		row.appendChild( btn );

		if ( options.actions ) {
			row.appendChild( options.actions );
		}

		return row;
	}

	function rowActions( host, index, label ) {
		var wrap = document.createElement( 'span' );
		wrap.className = 'okno-tree-actions';

		[
			{
				label: '↑',
				title: __( 'Move section up', 'okno' ),
				disabled: 0 === index,
				run: function () {
					moveRow( host, index, -1 );
				},
			},
			{
				label: '↓',
				title: __( 'Move section down', 'okno' ),
				disabled: index === host.resolved.value.length - 1,
				run: function () {
					moveRow( host, index, 1 );
				},
			},
			{
				label: '⧉',
				title: __( 'Duplicate section', 'okno' ),
				disabled: false,
				run: function () {
					duplicateRow( host, index );
				},
			},
			{
				label: '×',
				title: __( 'Delete section', 'okno' ),
				disabled: false,
				run: function () {
					deleteRow( host, index, label );
				},
			},
		].forEach( function ( action ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'okno-tree-action';
			btn.textContent = action.label;
			btn.title = action.title;
			/* translators: 1: action (e.g. “Move section up”), 2: section name. */
			btn.setAttribute( 'aria-label', sprintf( __( '%1$s: %2$s', 'okno' ), action.title, label ) );
			btn.disabled = action.disabled;
			btn.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				action.run();
			} );
			wrap.appendChild( btn );
		} );

		return wrap;
	}

	function addSectionBox( hosts ) {
		var box = document.createElement( 'div' );
		box.className = 'okno-add-section';

		hosts.forEach( function ( host ) {
			var layouts = host.resolved.def.layouts || [];
			if ( ! layouts.length ) {
				return;
			}

			var selectId = uid( 'layout' );
			var label = document.createElement( 'label' );
			label.setAttribute( 'for', selectId );
			/* translators: %s: name of the flexible content field. */
			label.textContent = sprintf( __( 'Add a section (%s)', 'okno' ), host.resolved.def.label );
			box.appendChild( label );

			var picker = document.createElement( 'div' );
			picker.className = 'okno-add-section-row';

			var select = document.createElement( 'select' );
			select.id = selectId;
			layouts.forEach( function ( layout ) {
				var opt = document.createElement( 'option' );
				opt.value = layout.name;
				opt.textContent = layout.label;
				select.appendChild( opt );
			} );
			picker.appendChild( select );

			var add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'okno-btn';
			add.textContent = __( 'Add', 'okno' );
			add.addEventListener( 'click', function () {
				addRow( host, select.value );
			} );
			picker.appendChild( add );

			box.appendChild( picker );
		} );

		return box;
	}

	function renderStructure() {
		els.structureTree.innerHTML = '';
		if ( ! state.post ) {
			return;
		}

		var tree = document.createElement( 'div' );
		tree.setAttribute( 'role', 'tree' );
		tree.setAttribute( 'aria-label', __( 'Page sections', 'okno' ) );
		els.structureTree.appendChild( tree );

		tree.appendChild( treeNode( 'page', 'page', state.post.title || __( 'Page', 'okno' ), 0 ) );

		var hosts = layoutHosts();

		if ( state.structureStale && hosts.length ) {
			// Après une opération structurelle, les sections annoncées par le
			// bridge ne correspondent plus au contenu : on rend l'arbre depuis
			// les lignes du flexible content, source de vérité en attendant le save.
			hosts.forEach( function ( host ) {
				( host.resolved.value || [] ).forEach( function ( row, index ) {
					var layout = layoutOf( host.resolved.def, row );
					var label = layout ? layout.label : row._layout;
					tree.appendChild(
						treeNode( 'row:' + host.key + ':' + index, 'section', label, 0, {
							actions: rowActions( host, index, label ),
						} )
					);
				} );
			} );

			var notice = document.createElement( 'p' );
			notice.className = 'okno-tree-hint';
			notice.textContent = __( 'Structure changed: save to see it in the preview.', 'okno' );
			els.structureTree.appendChild( notice );
		} else {
			state.sections.forEach( function ( section ) {
				var editable = ( section.fields || [] ).length;
				var host = null;
				if ( section.layout ) {
					host = hosts.find( function ( h ) {
						return h.key === section.layout.post + ':' + section.layout.path;
					} );
				}
				tree.appendChild(
					treeNode( section.id, 'section', section.label, editable, {
						actions: host ? rowActions( host, section.layout.index, section.label ) : null,
					} )
				);
			} );
		}

		if ( hosts.length ) {
			els.structureTree.appendChild( addSectionBox( hosts ) );
		}

		if ( ! state.sections.length && ! state.structureStale ) {
			var hint = document.createElement( 'p' );
			hint.className = 'okno-tree-hint';
			if ( ! state.bridgeReady ) {
				hint.textContent = __( 'The structure will appear once the preview responds.', 'okno' );
			} else if ( state.bridgeVersion < 3 ) {
				hint.textContent = __(
					'The preview uses an old version of the bridge: update it (v3) to get navigation and structure editing.',
					'okno'
				);
			} else {
				hint.textContent = __( 'No sections found on this page.', 'okno' );
			}
			els.structureTree.appendChild( hint );
		}
	}

	function selectNode( id, options ) {
		state.selection = id;
		els.structureTree.querySelectorAll( '.okno-tree-node' ).forEach( function ( n ) {
			var active = n.dataset.node === id;
			n.classList.toggle( 'active', active );
			if ( n.parentElement ) {
				n.parentElement.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			}
		} );
		renderInspector();
		if ( 'page' !== id && 0 !== id.indexOf( 'row:' ) && ! ( options && options.fromFrame ) ) {
			postToFrame( { type: 'focusSection', id: id } );
		}
		if ( options && options.fromFrame ) {
			switchTab( 'structure' );
		}
	}

	function onFieldFocusFromFrame( postId, path ) {
		var top = path.split( '.' )[ 0 ];
		var owner = null;
		state.sections.forEach( function ( section ) {
			( section.fields || [] ).forEach( function ( f ) {
				if ( String( f.post ) === postId && f.path === path ) {
					owner = section.id;
				}
			} );
		} );
		selectNode( owner || 'page', { fromFrame: true } );
		// Cible d'abord la cellule exacte (ligne de conteneur comprise), sinon le champ racine.
		focusPanelField( fieldKey( postId, path ), fieldKey( postId, top ) );
	}

	/* ------------------------------------------------------------------ *
	 * Inspecteur (panneau de droite)
	 * ------------------------------------------------------------------ */

	// Champs (top-level, dédupliqués) de la sélection courante, groupés.
	// Chaque entrée : { field, only } — only = Set d'index de lignes quand la
	// section ne référence qu'une partie d'un conteneur (inspecteur ciblé).
	function fieldsForSelection() {
		var groups = []; // [{ title, entries: [{ field, only }] }]

		function push( groupTitle, field, rowIndex ) {
			var group = groups.find( function ( g ) {
				return g.title === groupTitle;
			} );
			if ( ! group ) {
				group = { title: groupTitle, entries: [] };
				groups.push( group );
			}
			var entry = group.entries.find( function ( e ) {
				return e.field === field;
			} );
			if ( ! entry ) {
				entry = { field: field, only: null };
				group.entries.push( entry );
				if ( 'repeater' === field.type || 'flexible_content' === field.type ) {
					entry.only = new Set();
				}
			}
			if ( entry.only ) {
				if ( undefined === rowIndex || isNaN( rowIndex ) ) {
					entry.only = null; // Référence globale : toutes les lignes.
				} else {
					entry.only.add( rowIndex );
				}
			}
		}

		// Ligne de structure sélectionnée après une modification structurelle.
		if ( 0 === String( state.selection ).indexOf( 'row:' ) ) {
			var parts = state.selection.split( ':' );
			var host = layoutHosts().find( function ( h ) {
				return h.key === parts[ 1 ] + ':' + parts[ 2 ];
			} );
			if ( host ) {
				var field = host.resolved.topField;
				push( field._group, field, Number( parts[ 3 ] ) );
			}
			return groups;
		}

		if ( 'page' === state.selection ) {
			// La page : champs du post principal hors sections (titre, SEO, meta…).
			var covered = coveredTopPaths();
			var main = state.post ? state.schemas[ String( state.post.id ) ] : null;
			if ( main ) {
				main.groups.forEach( function ( group ) {
					group.fields.forEach( function ( f ) {
						if ( ! covered[ fieldKey( f._post, f.path ) ] ) {
							push( group.title, f );
						}
					} );
				} );
			}
			return groups;
		}

		var section = state.sections.find( function ( s ) {
			return s.id === state.selection;
		} );
		( section && section.fields ? section.fields : [] ).forEach( function ( f ) {
			var segments = f.path.split( '.' );
			var field = state.fields[ fieldKey( f.post, segments[ 0 ] ) ];
			if ( field ) {
				var schema = state.schemas[ field._post ];
				var origin =
					schema && schema._global
						? /* translators: %s: field group name. */
						  sprintf( __( '%s — global', 'okno' ), field._group )
						: field._group;
				push( origin, field, segments.length > 1 ? Number( segments[ 1 ] ) : undefined );
			}
		} );
		return groups;
	}

	// Paths (post-scopés) du post principal couverts par une section.
	function coveredTopPaths() {
		var covered = {};
		state.sections.forEach( function ( section ) {
			( section.fields || [] ).forEach( function ( f ) {
				covered[ fieldKey( f.post, f.path.split( '.' )[ 0 ] ) ] = true;
			} );
		} );
		return covered;
	}

	function renderInspector() {
		state.tinymceIds.forEach( function ( id ) {
			if ( window.wp && window.wp.editor && window.wp.editor.remove ) {
				window.wp.editor.remove( id );
			}
		} );
		state.tinymceIds = [];

		els.panel.innerHTML = '';
		els.panel.setAttribute( 'role', 'region' );
		els.panel.setAttribute( 'aria-label', __( 'Selected fields', 'okno' ) );

		if ( ! state.post ) {
			var empty = document.createElement( 'div' );
			empty.className = 'okno-panel-empty';
			empty.textContent = __( 'Pick a page in the Pages panel.', 'okno' );
			els.panel.appendChild( empty );
			return;
		}

		// En-tête : sélection courante.
		var header = document.createElement( 'div' );
		header.className = 'okno-inspector-head';
		var section = state.sections.find( function ( s ) {
			return s.id === state.selection;
		} );
		var title = document.createElement( 'h2' );
		title.textContent = section ? section.label : state.post.title || __( 'Page', 'okno' );
		header.appendChild( title );
		var subtitle = document.createElement( 'p' );
		subtitle.textContent = section ? __( 'Fields in this section', 'okno' ) : __( 'Page fields (outside sections)', 'okno' );
		header.appendChild( subtitle );
		els.panel.appendChild( header );

		var groups = fieldsForSelection();

		if ( ! groups.length ) {
			var none = document.createElement( 'div' );
			none.className = 'okno-panel-empty';
			none.textContent = __( 'No editable fields here.', 'okno' );
			els.panel.appendChild( none );
			return;
		}

		groups.forEach( function ( group ) {
			var sectionEl = document.createElement( 'section' );
			sectionEl.className = 'okno-group';
			var headingId = uid( 'group' );

			var h = document.createElement( 'h3' );
			h.id = headingId;
			h.textContent = group.title;
			sectionEl.setAttribute( 'aria-labelledby', headingId );
			sectionEl.appendChild( h );

			group.entries.forEach( function ( entry ) {
				sectionEl.appendChild( renderField( entry.field, entry.only ) );
			} );

			els.panel.appendChild( sectionEl );
		} );
	}

	function focusPanelField( key, fallbackKey ) {
		var wrap = els.panel.querySelector( '[data-key="' + key + '"]' );
		if ( ! wrap && fallbackKey ) {
			wrap = els.panel.querySelector( '[data-key="' + fallbackKey + '"]' );
		}
		if ( ! wrap ) {
			return;
		}
		wrap.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		wrap.classList.add( 'okno-field-flash' );
		setTimeout( function () {
			wrap.classList.remove( 'okno-field-flash' );
		}, 1200 );
		var input = wrap.querySelector( 'input, textarea, select' );
		if ( input && 'checkbox' !== input.type && 'color' !== input.type ) {
			input.focus( { preventScroll: true } );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Rendu des champs
	 * ------------------------------------------------------------------ */

	function isFieldVisible( field ) {
		var key = fieldKey( field._post, field.path );
		var prefix = key + '.';
		return Object.keys( state.knownFields ).some( function ( k ) {
			return k === key || 0 === k.indexOf( prefix );
		} );
	}

	/**
	 * Contexte d'édition d'une valeur : lecture, écriture, méta d'affichage.
	 * Le même contexte sert au champ racine et à n'importe quelle cellule
	 * imbriquée — c'est ce qui rend les conteneurs récursifs.
	 */
	function makeCtx( topField, def, path, accessors ) {
		return {
			topField: topField,
			def: def,
			path: path,
			read: accessors.read,
			readMeta: accessors.readMeta || function () {
				return undefined;
			},
			// Normalisation à l'affichage (tableau/objet manquant) : surtout pas
			// un write, sinon ouvrir une page suffirait à la marquer modifiée.
			init: function ( value, meta ) {
				accessors.write( value );
				if ( undefined !== meta && accessors.writeMeta ) {
					accessors.writeMeta( meta );
				}
			},
			write: function ( value, meta, structural ) {
				accessors.write( value );
				if ( undefined !== meta && accessors.writeMeta ) {
					accessors.writeMeta( meta );
				}
				markDirty( topField._post, topField.path, topField.value );
				if ( structural ) {
					state.structuralDirty[ fieldKey( topField._post, topField.path ) ] = true;
				}
				previewUpdate( topField, def, path, value, accessors.readMeta ? accessors.readMeta() : undefined );
				pushHistoryDebounced();
				setButtons();
			},
		};
	}

	function rootCtx( field ) {
		return makeCtx( field, field, field.path, {
			read: function () {
				return field.value;
			},
			write: function ( value ) {
				field.value = value;
			},
			readMeta: function () {
				return field.meta;
			},
			writeMeta: function ( meta ) {
				field.meta = meta;
			},
		} );
	}

	function childCtx( topField, def, path, holder, metaHolder, key ) {
		return makeCtx( topField, def, path, {
			read: function () {
				return holder[ key ];
			},
			write: function ( value ) {
				holder[ key ] = value;
			},
			readMeta: function () {
				return metaHolder ? metaHolder[ key ] : undefined;
			},
			writeMeta: function ( meta ) {
				if ( metaHolder ) {
					metaHolder[ key ] = meta;
				}
			},
		} );
	}

	function renderField( field, onlyRows ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'okno-field';
		wrap.dataset.key = fieldKey( field._post, field.path );

		var controlId = uid( 'field' );
		var label = document.createElement( 'label' );
		label.className = 'okno-field-label';
		label.setAttribute( 'for', controlId );
		var chip = document.createElement( 'span' );
		chip.className = 'okno-field-chip';
		chip.textContent = field.label + ( field.required ? ' *' : '' );
		label.appendChild( chip );
		wrap.appendChild( label );

		if ( false !== field.supported && ! isFieldVisible( field ) ) {
			var badge = document.createElement( 'span' );
			badge.className = 'okno-field-badge';
			badge.textContent = __( 'not on this page', 'okno' );
			badge.title = __( 'This field isn’t shown anywhere on this page, so it can’t be previewed live.', 'okno' );
			label.appendChild( badge );
		}

		if ( false === field.supported ) {
			wrap.classList.add( 'okno-field-unsupported' );
			var note = document.createElement( 'p' );
			note.className = 'okno-field-note';
			/* translators: %s: ACF field type (e.g. google_map). */
			note.textContent = sprintf( __( 'The “%s” field type can’t be edited here.', 'okno' ), field.type ) + ' ';
			if ( field.edit_url ) {
				var link = document.createElement( 'a' );
				link.href = field.edit_url;
				link.textContent = __( 'Edit in WordPress', 'okno' );
				note.appendChild( link );
			}
			wrap.appendChild( note );
			return wrap;
		}

		var control = buildControl( rootCtx( field ), { onlyRows: onlyRows, id: controlId } );
		if ( control ) {
			wrap.appendChild( control );
		}

		var error = document.createElement( 'p' );
		error.className = 'okno-field-error';
		error.setAttribute( 'role', 'alert' );
		var messages = errorsFor( field._post, field.path );
		error.textContent = messages.join( ' ' );
		error.hidden = ! messages.length;
		if ( messages.length ) {
			wrap.classList.add( 'okno-field--error' );
		}
		wrap.appendChild( error );

		return wrap;
	}

	/**
	 * Construit le contrôle d'édition d'une valeur, quel que soit son type et
	 * sa profondeur. Les conteneurs (repeater, group, flexible content) se
	 * rappellent sur leurs sous-champs.
	 */
	function buildControl( ctx, options ) {
		options = options || {};
		var def = ctx.def;
		var value = ctx.read();
		var input;

		function commit( v, meta, structural ) {
			ctx.write( v, meta, structural );
		}

		switch ( def.type ) {
			case 'repeater':
			case 'flexible_content':
				return buildRows( ctx, options.onlyRows );

			case 'group':
			case 'clone':
				return buildGroup( ctx );

			case 'wysiwyg':
				return buildWysiwyg( ctx, options.id );

			case 'image':
			case 'file':
				return buildMediaField( ctx, options.id );

			case 'gallery':
				return buildGallery( ctx );

			case 'link':
				return buildLink( ctx, options.id );

			case 'relationship':
			case 'taxonomy':
				return buildMultiSelect( ctx, options.id );

			case 'textarea':
				input = document.createElement( 'textarea' );
				input.rows = 4;
				input.value = value || '';
				input.addEventListener( 'input', function () {
					commit( input.value );
				} );
				break;

			case 'select':
			case 'post_object':
				input = document.createElement( 'select' );
				if ( ! def.required ) {
					var empty = document.createElement( 'option' );
					empty.value = '';
					empty.textContent = '—';
					input.appendChild( empty );
				}
				Object.keys( def.options || {} ).forEach( function ( key ) {
					var opt = document.createElement( 'option' );
					opt.value = key;
					opt.textContent = def.options[ key ];
					input.appendChild( opt );
				} );
				input.value = value === null || value === undefined ? '' : String( value );
				input.addEventListener( 'change', function () {
					var meta = 'post_object' === def.type
						? { label: input.options[ input.selectedIndex ] ? input.options[ input.selectedIndex ].textContent : '' }
						: undefined;
					commit( input.value, meta );
				} );
				break;

			case 'true_false':
				var toggleLabel = document.createElement( 'label' );
				toggleLabel.className = 'okno-toggle';
				input = document.createElement( 'input' );
				input.type = 'checkbox';
				input.id = options.id || uid( 'check' );
				input.checked = !! value;
				input.addEventListener( 'change', function () {
					commit( input.checked );
				} );
				toggleLabel.appendChild( input );
				toggleLabel.appendChild( document.createTextNode( ' ' + __( 'Enabled', 'okno' ) ) );
				return toggleLabel;

			case 'number':
			case 'range':
				input = document.createElement( 'input' );
				input.type = 'range' === def.type ? 'range' : 'number';
				if ( undefined !== def.min ) {
					input.min = def.min;
				}
				if ( undefined !== def.max && def.max ) {
					input.max = def.max;
				}
				if ( def.step ) {
					input.step = def.step;
				}
				input.value = value === null || value === undefined ? '' : value;
				input.addEventListener( 'input', function () {
					commit( input.value );
				} );
				break;

			case 'date_picker':
				input = document.createElement( 'input' );
				input.type = 'date';
				input.value = toInputDate( value );
				input.addEventListener( 'change', function () {
					commit( input.value );
				} );
				break;

			case 'date_time_picker':
				input = document.createElement( 'input' );
				input.type = 'datetime-local';
				input.value = String( value || '' ).replace( ' ', 'T' ).slice( 0, 16 );
				input.addEventListener( 'change', function () {
					commit( input.value.replace( 'T', ' ' ) );
				} );
				break;

			case 'time_picker':
				input = document.createElement( 'input' );
				input.type = 'time';
				input.value = String( value || '' ).slice( 0, 5 );
				input.addEventListener( 'change', function () {
					commit( input.value );
				} );
				break;

			default: // text, url, email, oembed, page_link.
				input = document.createElement( 'input' );
				input.type =
					'url' === def.type || 'oembed' === def.type || 'page_link' === def.type
						? 'url'
						: 'email' === def.type
						? 'email'
						: 'text';
				input.value = value || '';
				input.addEventListener( 'input', function () {
					commit( input.value );
				} );
		}

		input.id = options.id || uid( 'input' );
		if ( def.required ) {
			input.setAttribute( 'aria-required', 'true' );
		}

		// Adresse saisie sans schéma (« exemple.fr », « contact@… ») : on la
		// complète en quittant le champ, comme le fera tout navigateur.
		if ( 'url' === input.type ) {
			input.addEventListener( 'blur', function () {
				var fixed = normalizeUrl( input.value );
				if ( fixed !== input.value ) {
					input.value = fixed;
					commit( fixed );
				}
			} );
		}

		// Limite de caractères posée dans ACF : on l'applique et on l'affiche.
		if ( def.max_length && ( 'INPUT' === input.tagName || 'TEXTAREA' === input.tagName ) ) {
			input.maxLength = def.max_length;
			var counter = document.createElement( 'span' );
			counter.className = 'okno-counter';
			var syncCounter = function () {
				var left = def.max_length - input.value.length;
				counter.textContent = input.value.length + ' / ' + def.max_length;
				counter.classList.toggle( 'is-near', left <= Math.max( 5, def.max_length * 0.1 ) );
			};
			input.addEventListener( 'input', syncCounter );
			syncCounter();
			var wrap = document.createElement( 'div' );
			wrap.className = 'okno-counted';
			wrap.appendChild( input );
			wrap.appendChild( counter );
			input.addEventListener( 'focus', function () {
				postToFrame( { type: 'focusField', postId: ctx.topField._post, path: ctx.path } );
			} );
			return wrap;
		}
		input.addEventListener( 'focus', function () {
			postToFrame( { type: 'focusField', postId: ctx.topField._post, path: ctx.path } );
		} );

		return input;
	}

	/**
	 * Complète une adresse saisie à la main : « exemple.fr » → https://,
	 * « nom@exemple.fr » → mailto:. Les liens relatifs et ancres restent tels quels.
	 */
	function normalizeUrl( value ) {
		var url = String( value || '' ).trim();
		if ( ! url || /^([a-z][a-z0-9+.-]*:\/\/|(mailto|tel|sms):|\/|#|\?)/i.test( url ) ) {
			return url;
		}
		if ( /^[^\s@\/]+@[^\s@\/]+\.[^\s@\/]+$/.test( url ) ) {
			return 'mailto:' + url;
		}
		if ( /^\+?[\d\s.-]{6,}$/.test( url ) ) {
			return 'tel:' + url.replace( /[\s.-]/g, '' );
		}
		return 'https://' + url;
	}

	// ACF stocke les dates en Ymd ; <input type="date"> attend Y-m-d.
	function toInputDate( value ) {
		var raw = String( value || '' );
		if ( /^\d{8}$/.test( raw ) ) {
			return raw.slice( 0, 4 ) + '-' + raw.slice( 4, 6 ) + '-' + raw.slice( 6, 8 );
		}
		return raw.slice( 0, 10 );
	}

	function buildGroup( ctx ) {
		var box = document.createElement( 'div' );
		box.className = 'okno-group-field';

		var value = ctx.read();
		var meta = ctx.readMeta();
		if ( ! value || 'object' !== typeof value ) {
			value = {};
			ctx.init( value, meta && 'object' === typeof meta ? meta : {} );
		}
		if ( ! meta || 'object' !== typeof meta ) {
			meta = {};
		}

		( ctx.def.sub_fields || [] ).forEach( function ( sub ) {
			box.appendChild( renderCell( ctx.topField, sub, ctx.path + '.' + sub.path, value, meta ) );
		} );

		return box;
	}

	function renderCell( topField, sub, path, holder, metaHolder ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'okno-subfield';
		wrap.dataset.key = fieldKey( topField._post, path );

		var id = uid( 'cell' );
		var label = document.createElement( 'label' );
		label.className = 'okno-subfield-label';
		label.setAttribute( 'for', id );
		label.textContent = sub.label + ( sub.required ? ' *' : '' );
		wrap.appendChild( label );

		if ( false === sub.supported ) {
			var note = document.createElement( 'p' );
			note.className = 'okno-field-note';
			/* translators: %s: ACF field type (e.g. google_map). */
			note.textContent = sprintf( __( 'The “%s” field type can’t be edited here.', 'okno' ), sub.type );
			wrap.appendChild( note );
			return wrap;
		}

		var ctx = childCtx( topField, sub, path, holder, metaHolder, sub.path );
		var control = buildControl( ctx, { id: id } );
		if ( control ) {
			wrap.appendChild( control );
		}
		return wrap;
	}

	/* ------------------------------------------------------------------ *
	 * Conteneurs à lignes : repeater et flexible content
	 * ------------------------------------------------------------------ */

	function buildRows( ctx, onlyRows ) {
		var def = ctx.def;
		var flexible = 'flexible_content' === def.type;

		var rows = ctx.read();
		var meta = ctx.readMeta();
		if ( ! Array.isArray( rows ) || ! Array.isArray( meta ) ) {
			rows = Array.isArray( rows ) ? rows : [];
			meta = Array.isArray( meta ) ? meta : [];
			ctx.init( rows, meta );
		}

		var container = document.createElement( 'div' );
		container.className = 'okno-repeater';

		var list = document.createElement( 'div' );
		container.appendChild( list );

		// Vue ciblée (section sélectionnée) : seules ses lignes. Un Set couvrant
		// toutes les lignes = vue complète.
		var only = onlyRows && onlyRows.size && onlyRows.size < rows.length ? onlyRows : null;

		var adder = flexible ? buildLayoutAdder() : buildRowAdder();
		adder.hidden = !! only; // Vue ciblée : pas d'action structurelle.
		container.appendChild( adder );

		if ( only ) {
			var expand = document.createElement( 'button' );
			expand.type = 'button';
			expand.className = 'okno-repeater-expand';
			/* translators: %d: total number of rows. */
			expand.textContent = sprintf( __( 'Show all rows (%d)', 'okno' ), rows.length );
			expand.addEventListener( 'click', function () {
				container.replaceWith( buildRows( ctx, null ) );
			} );
			container.appendChild( expand );
		}

		function commitRows( structural ) {
			ctx.write( rows, meta, structural );
			if ( structural ) {
				state.structureStale = true;
			}
		}

		function subsFor( row ) {
			return flexible ? rowSubs( def, row ) : def.sub_fields || [];
		}

		function emptyRow( layout ) {
			var row = emptyRowFor( layout ? layout.sub_fields : def.sub_fields );
			if ( layout ) {
				row._layout = layout.name;
			}
			return row;
		}

		function buildRowAdder() {
			var add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'okno-repeater-add';
			add.textContent = __( '+ Add a row', 'okno' );
			add.addEventListener( 'click', function () {
				rows.push( emptyRow( null ) );
				meta.push( {} );
				commitRows( true );
				renderRows();
			} );
			return add;
		}

		function buildLayoutAdder() {
			var box = document.createElement( 'div' );
			box.className = 'okno-flex-add';

			var select = document.createElement( 'select' );
			select.setAttribute( 'aria-label', __( 'Type of block to add', 'okno' ) );
			( def.layouts || [] ).forEach( function ( layout ) {
				var opt = document.createElement( 'option' );
				opt.value = layout.name;
				opt.textContent = layout.label;
				select.appendChild( opt );
			} );
			box.appendChild( select );

			var add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'okno-repeater-add';
			add.textContent = __( '+ Add a block', 'okno' );
			add.addEventListener( 'click', function () {
				var layout = ( def.layouts || [] ).find( function ( l ) {
					return l.name === select.value;
				} );
				if ( ! layout ) {
					return;
				}
				rows.push( emptyRow( layout ) );
				meta.push( {} );
				commitRows( true );
				renderRows();
				/* translators: %s: block type name. */
				announceToScreenReader( sprintf( __( '“%s” block added.', 'okno' ), layout.label ) );
			} );
			box.appendChild( add );

			return box;
		}

		function rowTitle( row, i ) {
			if ( ! flexible ) {
				/* translators: %d: row number. */
				return sprintf( __( 'Row %d', 'okno' ), i + 1 );
			}
			var layout = layoutOf( def, row );
			/* translators: 1: block type name, 2: block number. */
			return sprintf( __( '%1$s — %2$d', 'okno' ), layout ? layout.label : row._layout, i + 1 );
		}

		function renderRows() {
			list.innerHTML = '';
			rows.forEach( function ( row, i ) {
				if ( only && ! only.has( i ) ) {
					return;
				}
				list.appendChild( renderRow( row, i ) );
			} );
			var max = def.max || 0;
			var addBtn = adder.querySelector( 'button' ) || adder;
			if ( addBtn && addBtn.tagName === 'BUTTON' ) {
				addBtn.disabled = max > 0 && rows.length >= max;
			}
		}

		function renderRow( row, i ) {
			var card = document.createElement( 'div' );
			card.className = 'okno-repeater-row';
			card.setAttribute( 'role', 'group' );
			card.setAttribute( 'aria-label', rowTitle( row, i ) );

			var head = document.createElement( 'div' );
			head.className = 'okno-repeater-head';

			var title = document.createElement( 'span' );
			title.className = 'okno-repeater-title';
			title.textContent = rowTitle( row, i );
			head.appendChild( title );

			var actions = document.createElement( 'span' );
			actions.className = 'okno-repeater-actions';
			actions.hidden = !! only; // Vue ciblée : valeurs uniquement.
			[
				{
					label: '↑',
					title: __( 'Move up', 'okno' ),
					disabled: 0 === i,
					run: function () {
						move( i, -1 );
					},
				},
				{
					label: '↓',
					title: __( 'Move down', 'okno' ),
					disabled: i === rows.length - 1,
					run: function () {
						move( i, 1 );
					},
				},
				{
					label: '⧉',
					title: __( 'Duplicate', 'okno' ),
					disabled: def.max > 0 && rows.length >= def.max,
					run: function () {
						rows.splice( i + 1, 0, clone( row ) );
						meta.splice( i + 1, 0, clone( meta[ i ] || {} ) );
						commitRows( true );
						renderRows();
					},
				},
				{
					label: '×',
					title: __( 'Delete', 'okno' ),
					disabled: rows.length <= ( def.min || 0 ),
					run: function () {
						rows.splice( i, 1 );
						meta.splice( i, 1 );
						commitRows( true );
						renderRows();
					},
				},
			].forEach( function ( a ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'okno-repeater-btn';
				b.textContent = a.label;
				b.title = a.title;
				/* translators: 1: action (e.g. “Move up”), 2: row name. */
				b.setAttribute( 'aria-label', sprintf( __( '%1$s: %2$s', 'okno' ), a.title, rowTitle( row, i ) ) );
				b.disabled = a.disabled;
				b.addEventListener( 'click', a.run );
				actions.appendChild( b );
			} );
			head.appendChild( actions );
			card.appendChild( head );

			if ( ! meta[ i ] ) {
				meta[ i ] = {};
			}

			subsFor( row ).forEach( function ( sub ) {
				card.appendChild(
					renderCell( ctx.topField, sub, ctx.path + '.' + i + '.' + sub.path, row, meta[ i ] )
				);
			} );

			return card;
		}

		function move( i, dir ) {
			var j = i + dir;
			if ( j < 0 || j >= rows.length ) {
				return;
			}
			var tmp = rows[ i ];
			rows[ i ] = rows[ j ];
			rows[ j ] = tmp;
			var tm = meta[ i ];
			meta[ i ] = meta[ j ];
			meta[ j ] = tm;
			commitRows( true );
			renderRows();
		}

		renderRows();
		return container;
	}

	/* ------------------------------------------------------------------ *
	 * Contrôles spécialisés
	 * ------------------------------------------------------------------ */

	function buildWysiwyg( ctx, id ) {
		var container = document.createElement( 'div' );
		var textarea = document.createElement( 'textarea' );
		var editorId = id || uid( 'wys' );
		textarea.id = editorId;
		textarea.rows = 8;
		textarea.value = ctx.read() || '';
		container.appendChild( textarea );

		var sync = debounce( function ( content ) {
			// L'aperçu reçoit le HTML brut ; le bridge le filtre comme
			// wp_kses_post() le fera à l'enregistrement (E3).
			ctx.write( content, { rendered: content } );
		}, 200 );

		if ( window.wp && window.wp.editor && window.wp.editor.initialize ) {
			setTimeout( function () {
				window.wp.editor.initialize( editorId, {
					tinymce: {
						toolbar1: 'bold,italic,bullist,numlist,link,unlink,undo,redo',
						// Coller depuis Word ou un autre site ne doit pas importer sa mise en forme.
						paste_as_text: true,
						menubar: false,
						statusbar: false,
						height: 180,
						setup: function ( ed ) {
							ed.on( 'change keyup input', function () {
								sync( ed.getContent() );
							} );
						},
					},
					quicktags: { buttons: 'strong,em,link,ul,ol,li' },
					mediaButtons: false,
				} );
				state.tinymceIds.push( editorId );
			}, 0 );
		} else {
			textarea.addEventListener( 'input', function () {
				sync( textarea.value );
			} );
		}

		return container;
	}

	function pickMedia( options ) {
		return new Promise( function ( resolve ) {
			if ( ! window.wp || ! window.wp.media ) {
				toast( __( 'The media library isn’t available.', 'okno' ), true );
				resolve( null );
				return;
			}
			var frame = window.wp.media( options );
			if ( options.current ) {
				// Ouvre la médiathèque avec l'image actuelle déjà sélectionnée.
				frame.on( 'open', function () {
					var selection = frame.state().get( 'selection' );
					var attachment = window.wp.media.attachment( options.current );
					attachment.fetch();
					selection.add( attachment );
				} );
			}
			frame.on( 'select', function () {
				var selection = frame.state().get( 'selection' );
				resolve(
					options.multiple
						? selection.toJSON()
						: selection.first().toJSON()
				);
			} );
			frame.open();
		} );
	}

	function buildMediaField( ctx, id ) {
		var isImage = 'image' === ctx.def.type;
		var container = document.createElement( 'div' );
		container.className = 'okno-image-field';

		var preview = document.createElement( 'img' );
		preview.className = 'okno-image-preview';
		preview.alt = '';
		var meta = ctx.readMeta() || {};
		preview.src = meta.url || '';
		preview.hidden = ! meta.url || ! isImage;
		container.appendChild( preview );

		var name = document.createElement( 'span' );
		name.className = 'okno-file-name';
		name.textContent = isImage ? '' : meta.title || '';
		container.appendChild( name );

		var row = document.createElement( 'div' );
		row.className = 'okno-image-actions';

		var choose = document.createElement( 'button' );
		choose.type = 'button';
		choose.className = 'okno-btn okno-btn--quiet';
		choose.id = id || uid( 'media' );
		choose.textContent = ctx.read()
			? __( 'Replace', 'okno' )
			: isImage
			? __( 'Choose an image', 'okno' )
			: __( 'Choose a file', 'okno' );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'okno-image-remove';
		remove.textContent = __( 'Remove', 'okno' );
		remove.hidden = ! ctx.read();

		function setMedia( attachment ) {
			var value = attachment ? attachment.id : 0;
			var nextMeta = {
				url: attachment ? attachment.url : '',
				title: attachment ? attachment.title : '',
			};
			preview.src = nextMeta.url;
			preview.hidden = ! nextMeta.url || ! isImage;
			name.textContent = isImage ? '' : nextMeta.title;
			remove.hidden = ! value;
			choose.textContent = value
				? __( 'Replace', 'okno' )
				: isImage
				? __( 'Choose an image', 'okno' )
				: __( 'Choose a file', 'okno' );
			ctx.write( value, nextMeta );
		}

		var cropNote = document.createElement( 'p' );
		cropNote.className = 'okno-field-note okno-crop-note';
		cropNote.hidden = true;

		choose.addEventListener( 'click', function () {
			pickMedia( {
				title: isImage ? __( 'Choose an image', 'okno' ) : __( 'Choose a file', 'okno' ),
				multiple: false,
				library: isImage ? { type: 'image' } : {},
				current: ctx.read() || 0,
			} ).then( function ( attachment ) {
				if ( attachment ) {
					setMedia( attachment );
					warnCrop( attachment );
				}
			} );
		} );

		/**
		 * L'emplacement a un format fixé par la mise en page ; si l'image choisie
		 * en est très éloignée, elle sera recadrée. On le dit avant la surprise.
		 */
		function warnCrop( attachment ) {
			cropNote.hidden = true;
			var known = state.knownFields[ fieldKey( ctx.topField._post, ctx.path ) ];
			if ( ! isImage || ! known || ! known.ratio || ! attachment.width || ! attachment.height ) {
				return;
			}
			var imageRatio = attachment.width / attachment.height;
			if ( Math.abs( Math.log( imageRatio / known.ratio ) ) < 0.25 ) {
				return;
			}
			cropNote.textContent = sprintf(
				/* translators: 1: shape of the image slot (e.g. “wide (1.78:1)”), 2: shape of the chosen image. */
				__( 'This image will be cropped: the slot is %1$s, the image is %2$s. Check the framing in the preview.', 'okno' ),
				describeRatio( known.ratio ),
				describeRatio( imageRatio )
			);
			cropNote.hidden = false;
		}

		remove.addEventListener( 'click', function () {
			setMedia( null );
		} );

		row.appendChild( choose );
		row.appendChild( remove );
		container.appendChild( row );
		container.appendChild( cropNote );
		return container;
	}

	function formatRatio( n ) {
		return n.toLocaleString( LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function describeRatio( ratio ) {
		if ( ratio > 1.15 ) {
			/* translators: %s: aspect ratio, e.g. 1.78 (as in 1.78:1). */
			return sprintf( __( 'wide (%s:1)', 'okno' ), formatRatio( ratio ) );
		}
		if ( ratio < 0.87 ) {
			/* translators: %s: aspect ratio, e.g. 1.50 (as in 1:1.50). */
			return sprintf( __( 'tall (1:%s)', 'okno' ), formatRatio( 1 / ratio ) );
		}
		return __( 'almost square', 'okno' );
	}

	function buildGallery( ctx ) {
		var container = document.createElement( 'div' );
		container.className = 'okno-gallery-field';

		var grid = document.createElement( 'ul' );
		grid.className = 'okno-gallery-grid';
		container.appendChild( grid );

		function items() {
			var meta = ctx.readMeta() || {};
			return Array.isArray( meta.items ) ? meta.items : [];
		}

		function commit( list ) {
			ctx.write(
				list.map( function ( item ) {
					return item.id;
				} ),
				{ items: list },
				true
			);
			render();
		}

		function render() {
			grid.innerHTML = '';
			items().forEach( function ( item, index ) {
				var li = document.createElement( 'li' );
				li.className = 'okno-gallery-item';

				var img = document.createElement( 'img' );
				img.src = item.url || '';
				img.alt = item.title || '';
				li.appendChild( img );

				var actions = document.createElement( 'span' );
				actions.className = 'okno-gallery-actions';
				[
					{
						label: '←',
						title: __( 'Move earlier', 'okno' ),
						disabled: 0 === index,
						run: function () {
							var list = items().slice();
							var tmp = list[ index - 1 ];
							list[ index - 1 ] = list[ index ];
							list[ index ] = tmp;
							commit( list );
						},
					},
					{
						label: '→',
						title: __( 'Move later', 'okno' ),
						disabled: index === items().length - 1,
						run: function () {
							var list = items().slice();
							var tmp = list[ index + 1 ];
							list[ index + 1 ] = list[ index ];
							list[ index ] = tmp;
							commit( list );
						},
					},
					{
						label: '×',
						title: __( 'Remove from gallery', 'okno' ),
						disabled: false,
						run: function () {
							var list = items().slice();
							list.splice( index, 1 );
							commit( list );
						},
					},
				].forEach( function ( action ) {
					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'okno-repeater-btn';
					btn.textContent = action.label;
					btn.title = action.title;
					btn.setAttribute(
						'aria-label',
						sprintf(
							/* translators: 1: action (e.g. “Move earlier”), 2: image title. */
							__( '%1$s: %2$s', 'okno' ),
							action.title,
							item.title ||
								/* translators: %d: image position in the gallery. */
								sprintf( __( 'image %d', 'okno' ), index + 1 )
						)
					);
					btn.disabled = action.disabled;
					btn.addEventListener( 'click', action.run );
					actions.appendChild( btn );
				} );
				li.appendChild( actions );
				grid.appendChild( li );
			} );
		}

		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'okno-btn okno-btn--quiet';
		add.textContent = __( '+ Add images', 'okno' );
		add.addEventListener( 'click', function () {
			pickMedia( { title: __( 'Add to gallery', 'okno' ), multiple: true, library: { type: 'image' } } ).then( function (
				attachments
			) {
				if ( ! attachments ) {
					return;
				}
				var list = items().slice();
				attachments.forEach( function ( att ) {
					if (
						! list.some( function ( item ) {
							return item.id === att.id;
						} )
					) {
						list.push( { id: att.id, url: att.url, title: att.title } );
					}
				} );
				commit( list );
			} );
		} );
		container.appendChild( add );

		render();
		return container;
	}

	function buildLink( ctx, id ) {
		var value = ctx.read();
		if ( ! value || 'object' !== typeof value ) {
			value = { url: 'string' === typeof value ? value : '', title: '', target: '' };
		}

		var box = document.createElement( 'div' );
		box.className = 'okno-link-field';

		function commit() {
			ctx.write(
				{ url: url.value, title: title.value, target: target.checked ? '_blank' : '' },
				{ label: title.value || url.value }
			);
		}

		var url = document.createElement( 'input' );
		url.type = 'url';
		url.id = id || uid( 'link' );
		url.placeholder = __( 'https://… or /contact', 'okno' );
		url.value = value.url || '';
		url.addEventListener( 'input', commit );
		url.addEventListener( 'blur', function () {
			var fixed = normalizeUrl( url.value );
			if ( fixed !== url.value ) {
				url.value = fixed;
				commit();
			}
		} );
		box.appendChild( url );

		var titleLabel = document.createElement( 'label' );
		titleLabel.className = 'okno-subfield-label';
		var titleId = uid( 'link-title' );
		titleLabel.setAttribute( 'for', titleId );
		titleLabel.textContent = __( 'Label', 'okno' );
		box.appendChild( titleLabel );

		var title = document.createElement( 'input' );
		title.type = 'text';
		title.id = titleId;
		title.value = value.title || '';
		title.addEventListener( 'input', commit );
		box.appendChild( title );

		var targetLabel = document.createElement( 'label' );
		targetLabel.className = 'okno-toggle';
		var target = document.createElement( 'input' );
		target.type = 'checkbox';
		target.checked = '_blank' === value.target;
		target.addEventListener( 'change', commit );
		targetLabel.appendChild( target );
		targetLabel.appendChild( document.createTextNode( ' ' + __( 'Open in a new tab', 'okno' ) ) );
		box.appendChild( targetLabel );

		return box;
	}

	function buildMultiSelect( ctx, id ) {
		var box = document.createElement( 'div' );
		box.className = 'okno-multi-field';

		var select = document.createElement( 'select' );
		select.multiple = true;
		select.size = Math.min( 8, Math.max( 3, Object.keys( ctx.def.options || {} ).length ) );
		select.id = id || uid( 'multi' );

		var current = Array.isArray( ctx.read() ) ? ctx.read().map( String ) : [];

		Object.keys( ctx.def.options || {} ).forEach( function ( key ) {
			var opt = document.createElement( 'option' );
			opt.value = key;
			opt.textContent = ctx.def.options[ key ];
			opt.selected = current.indexOf( key ) !== -1;
			select.appendChild( opt );
		} );

		select.addEventListener( 'change', function () {
			var ids = [];
			var items = [];
			[].forEach.call( select.selectedOptions, function ( opt ) {
				ids.push( Number( opt.value ) );
				items.push( { id: Number( opt.value ), label: opt.textContent } );
			} );
			ctx.write( ids, { items: items } );
		} );

		box.appendChild( select );

		if ( ! Object.keys( ctx.def.options || {} ).length ) {
			var note = document.createElement( 'p' );
			note.className = 'okno-field-note';
			note.textContent = __( 'No items available.', 'okno' );
			box.appendChild( note );
		}

		return box;
	}

	/* ------------------------------------------------------------------ *
	 * Enregistrer / Publier
	 * ------------------------------------------------------------------ */

	var ERROR_MESSAGES = {
		required: __( 'This field is required.', 'okno' ),
		too_long: __( 'Text is too long for this field.', 'okno' ),
		save_failed: __( 'WordPress refused to save (content is locked or a plugin is blocking it).', 'okno' ),
		invalid_email: __( 'Invalid email address.', 'okno' ),
		invalid_number: __( 'Invalid or out-of-range number.', 'okno' ),
		invalid_choice: __( 'This choice isn’t allowed.', 'okno' ),
		invalid_attachment: __( 'File not found or not allowed.', 'okno' ),
		invalid_post: __( 'Linked content not found.', 'okno' ),
		invalid_term: __( 'Category not found.', 'okno' ),
		invalid_color: __( 'Invalid color.', 'okno' ),
		invalid_link: __( 'Invalid link.', 'okno' ),
		invalid_date: __( 'Invalid date.', 'okno' ),
		invalid_reference: __( 'Linked item not found.', 'okno' ),
		invalid_group: __( 'Malformed field group: reload the page.', 'okno' ),
		invalid_rows: __( 'Malformed rows: reload the page.', 'okno' ),
		too_few_rows: __( 'Not enough rows for this field.', 'okno' ),
		too_many_rows: __( 'Too many rows for this field.', 'okno' ),
		too_many_items: __( 'Too many items selected.', 'okno' ),
		unknown_layout: __( 'Unknown block type: reload the page.', 'okno' ),
		row_not_found: __( 'This row no longer exists: reload the page.', 'okno' ),
		unknown_field: __( 'WordPress doesn’t know this field: reload the page.', 'okno' ),
		unsupported_type: __( 'Okno doesn’t support this field type.', 'okno' ),
	};

	function errorMessage( code ) {
		/* translators: %s: technical error code. */
		return ERROR_MESSAGES[ code ] || sprintf( __( 'Value rejected (%s).', 'okno' ), code );
	}

	/** Erreurs du dernier enregistrement qui concernent ce champ ou ses sous-champs. */
	function errorsFor( postId, path ) {
		var prefix = fieldKey( postId, path );
		return Object.keys( state.fieldErrors )
			.filter( function ( key ) {
				return key === prefix || 0 === key.indexOf( prefix + '.' );
			} )
			.map( function ( key ) {
				var sub = key.slice( prefix.length + 1 );
				var message = errorMessage( state.fieldErrors[ key ] );
				if ( ! sub ) {
					return message;
				}
				var where = sub
					.split( '.' )
					.map( function ( part ) {
						return /^\d+$/.test( part ) ? '#' + ( Number( part ) + 1 ) : part;
					} )
					.join( ' › ' );
				/* translators: 1: location of the sub-field (e.g. “#2 › title”), 2: error message. */
				return sprintf( __( '%1$s: %2$s', 'okno' ), where, message );
			} );
	}

	/** Libellé lisible d'un chemin en erreur, pour le toast. */
	function errorLabel( postId, path ) {
		var root = path.split( '.' )[ 0 ];
		var field = state.fields[ fieldKey( postId, root ) ];
		return field ? field.label : root;
	}

	function onConflict( postId, err ) {
		var fresh = err && err.data && err.data.schema;
		toast( __( 'This content was changed elsewhere: your changes were not saved.', 'okno' ), true );
		if ( ! window.confirm( __( 'This content was changed elsewhere since you opened it.\n\nLoad the latest version? Your unsaved changes will be lost.', 'okno' ) ) ) {
			return;
		}
		if ( fresh ) {
			indexSchema( fresh, { global: state.schemas[ postId ] && state.schemas[ postId ]._global } );
		}
		delete state.dirtyByPost[ postId ];
		state.structuralDirty = {};
		state.structureStale = false;
		clearDraft( postId );
		resetHistory();
		renderInspector();
		renderStructure();
		if ( state.post ) {
			loadFrame( state.post.preview_url );
		}
		setButtons();
	}

	function save() {
		if ( ! isDirty() || ! state.post ) {
			return Promise.resolve( false );
		}

		state.saving = true;
		setButtons();
		els.save.textContent = __( 'Saving…', 'okno' );

		var postIds = Object.keys( state.dirtyByPost ).filter( function ( postId ) {
			return Object.keys( state.dirtyByPost[ postId ] ).length > 0;
		} );

		// Reload nécessaire ? (champ refresh-après-save ou structure modifiée.)
		var needsReload =
			state.structureStale ||
			postIds.some( function ( postId ) {
				return Object.keys( state.dirtyByPost[ postId ] ).some( function ( path ) {
					var known = state.knownFields[ fieldKey( postId, path ) ];
					return ( known && known.refresh ) || state.structuralDirty[ fieldKey( postId, path ) ];
				} );
			} );

		var totalErrors = 0;
		var totalSaved = 0;
		var errorLabels = [];
		var conflicted = false;

		return Promise.all(
			postIds.map( function ( postId ) {
				var schema = state.schemas[ postId ];
				return apiFetch( {
					path: '/okno/v1/save/' + postId,
					method: 'POST',
					data: {
						values: Object.assign( {}, state.dirtyByPost[ postId ] ),
						revision: schema ? schema.revision : '',
					},
				} )
					.then( function ( response ) {
						var errors = response.errors || {};
						var errorPaths = Object.keys( errors );
						totalErrors += errorPaths.length;
						totalSaved += ( response.saved || [] ).length;

						( response.saved || [] ).forEach( function ( path ) {
							delete state.dirtyByPost[ postId ][ path ];
						} );

						( response.saved || [] ).forEach( function ( path ) {
							delete state.fieldErrors[ fieldKey( postId, path ) ];
						} );
						errorPaths.forEach( function ( path ) {
							state.fieldErrors[ fieldKey( postId, path ) ] = errors[ path ];
							var label = sprintf(
								/* translators: 1: field name, 2: error message. */
								__( '%1$s (%2$s)', 'okno' ),
								errorLabel( postId, path ),
								errorMessage( errors[ path ] ).replace( /\.$/, '' ).toLowerCase()
							);
							if ( errorLabels.indexOf( label ) === -1 ) {
								errorLabels.push( label );
							}
						} );

						if ( response.schema ) {
							indexSchema( response.schema, {
								global: state.schemas[ postId ] && state.schemas[ postId ]._global,
							} );
						}
					} )
					.catch( function ( err ) {
						if ( err && 'okno_conflict' === err.code ) {
							conflicted = true;
							onConflict( postId, err );
							return;
						}
						throw err;
					} );
			} )
		)
			.then( function () {
				if ( conflicted ) {
					return false;
				}

				state.structuralDirty = {};
				Object.keys( state.dirtyByPost ).forEach( function ( postId ) {
					if ( ! Object.keys( state.dirtyByPost[ postId ] ).length ) {
						delete state.dirtyByPost[ postId ];
					}
				} );

				if ( ! isDirty() ) {
					clearDraft( state.post.id );
				}

				if ( needsReload ) {
					state.structureStale = false;
					loadFrame( state.post.preview_url );
				} else {
					hydrateFrame();
				}

				renderInspector();
				renderStructure();

				if ( totalErrors ) {
					toast(
						sprintf(
							/* translators: 1: number of fields not saved, 2: list of the fields and their errors. */
							_n( '%1$d field wasn’t saved: %2$s.', '%1$d fields weren’t saved: %2$s.', totalErrors, 'okno' ),
							totalErrors,
							errorLabels.join( ', ' )
						),
						true
					);
				} else if ( cfg.liveMode ) {
					toast( __( 'Saved: it’s live.', 'okno' ) );
				} else if ( AUTO ) {
					toast( __( 'Saved. It will be published automatically.', 'okno' ) );
				} else {
					toast( __( 'Saved. Click “Publish” to put it live.', 'okno' ) );
				}
				if ( els.historyList && ! els.historyList.hidden ) {
					renderHistory();
				}
				// Chaque enregistrement repousse la publication automatique : le
				// client qui enchaîne les retouches n'en déclenche qu'une.
				if ( AUTO && totalSaved > 0 && ! state.publishing ) {
					armAutoPublish( AUTO.delay );
				}
				return true;
			} )
			.catch( function ( err ) {
				toast( ( err && err.message ) || __( 'Saving failed.', 'okno' ), true );
				return Promise.reject( err );
			} )
			.finally( function () {
				state.saving = false;
				els.save.textContent = __( 'Save', 'okno' );
				setButtons();
			} );
	}

	/* ------------------------------------------------------------------ *
	 * Historique de la page (journal serveur)
	 * ------------------------------------------------------------------ */

	function renderHistory() {
		if ( ! els.historyList || ! state.post ) {
			return;
		}
		var postId = state.post.id;
		els.historyList.innerHTML = '';
		var loading = document.createElement( 'p' );
		loading.className = 'okno-tree-hint';
		loading.textContent = __( 'Loading…', 'okno' );
		els.historyList.appendChild( loading );

		apiFetch( { path: '/okno/v1/activity?post=' + postId } )
			.then( function ( items ) {
				if ( ! state.post || state.post.id !== postId ) {
					return;
				}
				els.historyList.innerHTML = '';
				if ( ! items.length ) {
					var empty = document.createElement( 'p' );
					empty.className = 'okno-tree-hint';
					empty.textContent = __( 'No changes saved on this page yet.', 'okno' );
					els.historyList.appendChild( empty );
					return;
				}
				var list = document.createElement( 'ol' );
				list.className = 'okno-timeline';
				items.forEach( function ( item ) {
					list.appendChild( renderHistoryItem( item ) );
				} );
				els.historyList.appendChild( list );
			} )
			.catch( function () {
				loading.textContent = __( 'Couldn’t load the history.', 'okno' );
			} );
	}

	function renderHistoryItem( item ) {
		var li = document.createElement( 'li' );
		li.className = 'okno-timeline-item';

		var head = document.createElement( 'button' );
		head.type = 'button';
		head.className = 'okno-timeline-field';
		head.textContent = item.label;
		head.title = __( 'Show this field', 'okno' );
		head.addEventListener( 'click', function () {
			onFieldFocusFromFrame( String( state.post.id ), item.path );
			postToFrame( { type: 'focusField', postId: String( state.post.id ), path: item.path } );
		} );
		li.appendChild( head );

		var meta = document.createElement( 'span' );
		meta.className = 'okno-timeline-meta';
		meta.textContent = ( item.user || __( 'Unknown user', 'okno' ) ) + ' · ' + relativeTime( item.when );
		li.appendChild( meta );

		var change = document.createElement( 'span' );
		change.className = 'okno-timeline-change';
		var before = document.createElement( 'del' );
		before.textContent = shortValue( item.old, item.type ) || __( 'empty', 'okno' );
		var after = document.createElement( 'ins' );
		after.textContent = shortValue( item.new, item.type ) || __( 'empty', 'okno' );
		change.appendChild( before );
		change.appendChild( after );
		li.appendChild( change );

		return li;
	}

	function shortValue( value, type ) {
		if ( ! value ) {
			return '';
		}
		if ( 'image' === type || 'file' === type ) {
			/* translators: %s: media library ID of the image or file. */
			return sprintf( __( 'Image #%s', 'okno' ), value );
		}
		if ( 'true_false' === type ) {
			return '1' === value ? __( 'Yes', 'okno' ) : __( 'No', 'okno' );
		}
		var text = value;
		if ( '[' === value.charAt( 0 ) ) {
			try {
				var list = JSON.parse( value );
				/* translators: %d: number of items. */
				return sprintf( _n( '%d item', '%d items', list.length, 'okno' ), list.length );
			} catch ( e ) {
				/* texte brut */
			}
		}
		if ( '{' === value.charAt( 0 ) ) {
			try {
				var obj = JSON.parse( value );
				text = obj.title || obj.url || value;
			} catch ( e ) {
				/* texte brut */
			}
		}
		var div = document.createElement( 'div' );
		div.innerHTML = text;
		text = ( div.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		return text.length > 80 ? text.slice( 0, 77 ) + '…' : text;
	}

	function relativeTime( iso ) {
		var then = new Date( iso ).getTime();
		if ( ! then ) {
			return '';
		}
		var minutes = Math.round( ( Date.now() - then ) / 60000 );
		if ( minutes < 1 ) {
			return __( 'just now', 'okno' );
		}
		if ( minutes < 60 ) {
			/* translators: %d: number of minutes. */
			return sprintf( _n( '%d minute ago', '%d minutes ago', minutes, 'okno' ), minutes );
		}
		var hours = Math.round( minutes / 60 );
		if ( hours < 24 ) {
			/* translators: %d: number of hours. */
			return sprintf( _n( '%d hour ago', '%d hours ago', hours, 'okno' ), hours );
		}
		var days = Math.round( hours / 24 );
		if ( days < 2 ) {
			return __( 'yesterday', 'okno' );
		}
		/* translators: %d: number of days. */
		return sprintf( _n( '%d day ago', '%d days ago', days, 'okno' ), days );
	}

	function publish() {
		els.publish.disabled = true;
		state.publishing = true;
		// Publication manuelle : elle emporte aussi les changements en attente.
		disarmAutoPublish();

		var maybeSave = isDirty() ? save() : Promise.resolve( true );

		maybeSave
			.then( function ( ok ) {
				if ( false === ok ) {
					return null; // Conflit : on ne déploie pas un état incertain.
				}
				return apiFetch( { path: '/okno/v1/deploy', method: 'POST' } );
			} )
			.then( function ( record ) {
				if ( ! record ) {
					// Conflit au save : la publication automatique reste en attente.
					if ( AUTO ) {
						armAutoPublish( AUTO.delay );
					}
					return;
				}
				state.deployId = record.deploy_id;
				showDeployBanner( record );
				if ( cfg.driverSupportsStatus ) {
					pollDeploy();
				}
			} )
			.catch( function ( err ) {
				toast( ( err && err.message ) || __( 'Couldn’t start the deployment.', 'okno' ), true );
				// Rien n'est parti : la publication automatique reprend la main
				// (le serveur répond « rien à publier » s'il n'y a rien en attente).
				if ( AUTO ) {
					armAutoPublish( AUTO.delay );
				}
			} )
			.finally( function () {
				state.publishing = false;
				setButtons();
			} );
	}

	function showDeployBanner( record ) {
		var banner = els.banner;
		banner.hidden = false;
		banner.className = 'okno-deploy-banner okno-deploy-' + record.status;
		banner.setAttribute( 'aria-live', 'polite' );
		banner.removeAttribute( 'title' );

		var text;
		switch ( record.status ) {
			case 'pending':
			case 'building':
				text = __( 'Building…', 'okno' );
				break;
			case 'triggered':
				/* translators: %d: estimated number of minutes before the site is live. */
				text = sprintf( __( 'Deployment triggered — live in ~%d min', 'okno' ), record.eta || 3 );
				setTimeout( function () {
					if ( ! banner.hidden && banner.classList.contains( 'okno-deploy-triggered' ) ) {
						banner.textContent = __( 'The site should be live now.', 'okno' );
						setTimeout( hideBanner, 30000 );
					}
				}, ( record.eta || 3 ) * 60000 );
				break;
			case 'success':
				text = __( 'Live ✓', 'okno' );
				setTimeout( hideBanner, 15000 );
				break;
			case 'error':
				text = record.message
					? /* translators: %s: error message from the deployment service. */
					  sprintf( __( 'Deployment failed — %s', 'okno' ), record.message )
					: __( 'Deployment failed', 'okno' );
				break;
			default:
				text = record.status;
		}

		banner.textContent = text;

		if ( record.run_url && ( 'error' === record.status || 'building' === record.status ) ) {
			var link = document.createElement( 'a' );
			link.href = record.run_url;
			link.target = '_blank';
			link.rel = 'noopener';
			link.textContent = ' ' + __( 'Logs', 'okno' );
			banner.appendChild( link );
		}
	}

	function hideBanner() {
		// Un compte à rebours a pris la place d'un ancien statut : on le garde.
		if ( state.auto.pending && els.banner.classList.contains( 'okno-deploy-scheduled' ) ) {
			return;
		}
		els.banner.hidden = true;
	}

	/* ------------------------------------------------------------------ *
	 * Publication automatique
	 *
	 * Le serveur publie seul (heartbeat, cron) ; l'éditeur, qui sait quand le
	 * client arrête de modifier, publie plus tôt et plus sûrement : minuterie
	 * relancée à chaque enregistrement, et envoi immédiat quand l'onglet se
	 * ferme ou passe en arrière-plan. Le serveur ne publie qu'une fois.
	 * ------------------------------------------------------------------ */

	function armAutoPublish( seconds ) {
		var auto = state.auto;
		clearTimeout( auto.timer );
		auto.pending = true;
		auto.beaconSent = false;
		auto.armedAt = Date.now();
		auto.dueAt = Date.now() + Math.max( 1, seconds ) * 1000;
		auto.timer = setTimeout( autoPublishNow, Math.max( 1, seconds ) * 1000 );
		if ( ! auto.ticker ) {
			auto.ticker = setInterval( renderCountdown, 1000 );
		}
		renderCountdown();
	}

	function disarmAutoPublish() {
		var auto = state.auto;
		clearTimeout( auto.timer );
		clearInterval( auto.ticker );
		auto.timer = null;
		auto.ticker = null;
		auto.pending = false;
		if ( els.banner.classList.contains( 'okno-deploy-scheduled' ) ) {
			els.banner.hidden = true;
		}
	}

	function renderCountdown() {
		var banner = els.banner;
		// Un build suivi en direct garde la bannière : son statut compte plus.
		if (
			! state.auto.pending ||
			banner.classList.contains( 'okno-deploy-pending' ) ||
			banner.classList.contains( 'okno-deploy-building' )
		) {
			return;
		}
		var seconds = Math.max( 0, Math.ceil( ( state.auto.dueAt - Date.now() ) / 1000 ) );
		banner.hidden = false;
		banner.className = 'okno-deploy-banner okno-deploy-scheduled';
		banner.removeAttribute( 'aria-live' ); // Pas d'annonce à chaque seconde.
		banner.textContent = seconds > 0
			? /* translators: %d: number of seconds before automatic publishing. */
			  sprintf( __( 'Publishing in %d s', 'okno' ), seconds )
			: __( 'Publishing…', 'okno' );
		banner.title = __( 'Your saved changes go live automatically. Click “Publish” to do it now.', 'okno' );
	}

	function autoPublishNow() {
		state.auto.timer = null;
		if ( ! state.auto.pending ) {
			return;
		}
		// Le client modifie encore : on attend qu'il ait fini.
		if ( state.saving || state.publishing || isDirty() ) {
			armAutoPublish( AUTO.delay );
			return;
		}
		state.auto.dueAt = Date.now();
		renderCountdown();
		flushRequest();
	}

	/**
	 * Demande au serveur de publier ce qui attend (ou, si un autre
	 * déclencheur l'a déjà fait, le statut de ce déploiement).
	 */
	function flushRequest() {
		return apiFetch( { path: '/okno/v1/deploy/flush', method: 'POST' } )
			.then( onFlushResult )
			.catch( function ( err ) {
				disarmAutoPublish();
				showDeployBanner( {
					status: 'error',
					message: ( err && err.message ) || __( 'Couldn’t start the deployment.', 'okno' ),
				} );
			} );
	}

	function onFlushResult( body ) {
		if ( 'pending' === body.state ) {
			// Save en cours, build déjà lancé, délai entre deux déploiements :
			// on retente plus tard, le serveur garde la publication en attente.
			armAutoPublish( body.retry_in || 15 );
			return;
		}
		disarmAutoPublish();
		var record = body.deploy;
		if ( ! record ) {
			return;
		}
		state.deployId = record.deploy_id;
		showDeployBanner( record );
		if ( cfg.driverSupportsStatus && ( 'pending' === record.status || 'building' === record.status ) ) {
			pollDeploy();
		}
	}

	/**
	 * L'onglet se ferme ou passe en arrière-plan avec une publication en
	 * attente : on l'envoie tout de suite. sendBeacon survit à la fermeture
	 * de la page mais ne pose pas d'en-tête : le nonce REST part dans le corps
	 * (_wpnonce), que WordPress accepte aussi.
	 */
	function flushOnLeave() {
		if ( ! state.auto.pending || state.saving ) {
			return;
		}
		var nonce = ( apiFetch.nonceMiddleware && apiFetch.nonceMiddleware.nonce ) || AUTO.nonce;
		var body = new URLSearchParams( { _wpnonce: nonce } );
		var sent = false;
		try {
			sent = !! ( navigator.sendBeacon && navigator.sendBeacon( AUTO.flushUrl, body ) );
		} catch ( e ) {
			sent = false;
		}
		if ( ! sent && window.fetch ) {
			window.fetch( AUTO.flushUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } ).catch( function () {} );
		}
		disarmAutoPublish();
		state.auto.beaconSent = true;
	}

	if ( AUTO ) {
		window.addEventListener( 'pagehide', flushOnLeave );
		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				flushOnLeave();
			} else if ( state.auto.beaconSent ) {
				// De retour : on affiche le statut de la publication partie en notre absence.
				state.auto.beaconSent = false;
				flushRequest();
			}
		} );

		// Heartbeat de wp-admin : le serveur a peut-être publié sans nous
		// (heartbeat, cron, autre onglet). Il renouvelle aussi le nonce REST.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'heartbeat-tick', function ( event, data ) {
				if ( data && data.rest_nonce ) {
					AUTO.nonce = data.rest_nonce;
				}
				var info = data && data.okno_autopublish;
				if ( ! info ) {
					return;
				}
				if ( ! info.pending && state.auto.pending && Date.now() - state.auto.armedAt > 10000 ) {
					flushRequest();
				} else if ( info.pending && ! state.auto.pending && ! state.publishing ) {
					armAutoPublish( info.due_in || AUTO.delay );
				}
			} );
		}
	}

	function pollDeploy() {
		clearTimeout( state.deployPollTimer );
		if ( ! state.deployId ) {
			return;
		}
		state.deployPollTimer = setTimeout( function () {
			apiFetch( { path: '/okno/v1/deploy/' + state.deployId } )
				.then( function ( record ) {
					showDeployBanner( record );
					if ( 'pending' === record.status || 'building' === record.status ) {
						pollDeploy();
					} else {
						state.deployId = '';
					}
				} )
				.catch( function () {
					pollDeploy();
				} );
		}, 5000 );
	}

	/* ------------------------------------------------------------------ *
	 * Wiring
	 * ------------------------------------------------------------------ */

	els.save.addEventListener( 'click', save );
	els.publish.addEventListener( 'click', publish );

	if ( els.undo ) {
		els.undo.addEventListener( 'click', undo );
	}
	if ( els.redo ) {
		els.redo.addEventListener( 'click', redo );
	}

	document.addEventListener( 'keydown', function ( event ) {
		var mod = event.metaKey || event.ctrlKey;
		if ( ! mod ) {
			return;
		}
		var key = event.key.toLowerCase();
		if ( 's' === key ) {
			event.preventDefault();
			save();
		} else if ( 'z' === key && ! event.shiftKey ) {
			// Dans un champ de saisie, on laisse l'undo natif du navigateur.
			if ( isTextEntry( event.target ) ) {
				return;
			}
			event.preventDefault();
			undo();
		} else if ( ( 'z' === key && event.shiftKey ) || 'y' === key ) {
			if ( isTextEntry( event.target ) ) {
				return;
			}
			event.preventDefault();
			redo();
		}
	} );

	function isTextEntry( el ) {
		if ( ! el ) {
			return false;
		}
		var tag = el.tagName;
		return 'INPUT' === tag || 'TEXTAREA' === tag || el.isContentEditable;
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( isDirty() ) {
			writeDraft();
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	document.querySelectorAll( '.okno-vw' ).forEach( function ( btn ) {
		btn.setAttribute( 'aria-pressed', btn.classList.contains( 'active' ) ? 'true' : 'false' );
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '.okno-vw' ).forEach( function ( b ) {
				b.classList.remove( 'active' );
				b.setAttribute( 'aria-pressed', 'false' );
			} );
			btn.classList.add( 'active' );
			btn.setAttribute( 'aria-pressed', 'true' );
			var width = Number( btn.dataset.width || 0 );
			els.frameWrap.style.maxWidth = width ? width + 28 + 'px' : '';
			els.frameWrap.classList.toggle( 'okno-frame-device', !! width );
			els.frameWrap.classList.toggle( 'okno-frame-phone', width > 0 && width < 500 );
		} );
	} );

	/* ------------------------------------------------------------------ *
	 * Plein écran + thème clair/sombre
	 * ------------------------------------------------------------------ */

	document.documentElement.classList.add( 'okno-fullscreen' );

	function applyTheme( theme ) {
		els.root.dataset.theme = theme;
		if ( els.theme ) {
			els.theme.innerHTML = '';
			els.theme.appendChild( icon( 'dark' === theme ? 'sun' : 'moon', 16 ) );
			els.theme.setAttribute( 'aria-pressed', 'dark' === theme ? 'true' : 'false' );
			els.theme.setAttribute(
				'aria-label',
				'dark' === theme ? __( 'Switch to light theme', 'okno' ) : __( 'Switch to dark theme', 'okno' )
			);
		}
	}

	var storedTheme = null;
	try {
		storedTheme = window.localStorage.getItem( 'okno-theme' );
	} catch ( e ) { /* stockage indisponible : thème système. */ }

	applyTheme(
		storedTheme ||
			( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light' )
	);

	if ( els.theme ) {
		els.theme.addEventListener( 'click', function () {
			var next = 'dark' === els.root.dataset.theme ? 'light' : 'dark';
			try {
				window.localStorage.setItem( 'okno-theme', next );
			} catch ( e ) { /* tant pis, non persisté. */ }
			applyTheme( next );
		} );
	}

	setButtons();
	renderInspector();
	loadPosts();

	if ( state.deployId && cfg.driverSupportsStatus ) {
		pollDeploy();
	}

	// Changements enregistrés avant l'ouverture (autre onglet, wp-admin) :
	// la publication automatique reprend où elle en était.
	if ( AUTO && AUTO.pending ) {
		armAutoPublish( AUTO.dueIn );
	}
} )();
