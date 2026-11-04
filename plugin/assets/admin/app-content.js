/**
 * Leiho — vue Collections : tous les contenus éditables, quel que soit leur
 * moteur (pages et articles WordPress, types ACF ou déclarés par le thème,
 * collections et pages uniques Leiho), en trois volets :
 *
 * - à gauche, les types de contenu groupés (Site, Collections Leiho, Pages
 *   uniques) avec leur nombre d'entrées, et « Nouvelle collection » ;
 * - au milieu, les entrées du type choisi (recherche, statut, tri, actions
 *   au survol) ;
 * - à droite, l'entrée choisie, éditée sur place par l'éditeur en mode
 *   formulaire (editor.js, window.LeihoEditor) : mêmes champs, annuler /
 *   rétablir, brouillons locaux, protection des conflits, historique.
 *
 * L'onglet « Champs » d'une collection Leiho remplace les deux volets de
 * droite par le constructeur de schéma (app-collections.js,
 * window.LeihoBuilder) ; les types ACF y renvoient vers ACF.
 *
 * Liens profonds : view=collections&type=<post_type>&id=<post_id>
 * (&panel=fields pour les champs).
 *
 * Portions derived from Stacki (https://github.com/flowtricks/stacki),
 * Copyright (c) 2026 Timothy Ricks, MIT License — see licenses/stacki-MIT.txt.
 * (Organisation en volets collections / entrées / détail, onglet des
 * réglages d'une collection à la place de l'entrée.)
 */
( function () {
	'use strict';

	var App = window.LeihoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	var browser = document.getElementById( 'leiho-form-browser' );
	if ( ! App || ! browser ) {
		return;
	}

	var Editor = window.LeihoEditor || null;
	var Builder = window.LeihoBuilder || null;
	var editorRoot = document.getElementById( 'leiho-editor' );
	var builderHost = document.getElementById( 'leiho-builder' );
	var entryBack = document.getElementById( 'leiho-entry-back' );

	var h = App.h, icon = App.icon, cfg = App.cfg;
	var PER_PAGE = 50;

	var STATUSES = {
		publish: __( 'Published', 'leiho' ),
		future: __( 'Scheduled', 'leiho' ),
		draft: __( 'Draft', 'leiho' ),
		pending: __( 'Pending review', 'leiho' ),
		private: __( 'Private', 'leiho' ),
		trash: __( 'Trash', 'leiho' ),
	};

	// Couleur du point de chaque statut dans les menus (LeihoUI.select).
	var STATUS_DOTS = { publish: 'ok', future: 'accent', draft: 'warn', pending: 'pending', private: 'purple', trash: 'error' };

	var SORTS = {
		modified: __( 'Last modified', 'leiho' ),
		date: __( 'Newest first', 'leiho' ),
		title: __( 'Title (A–Z)', 'leiho' ),
	};

	var params = new URLSearchParams( window.location.search );
	var types = cfg.types || [];
	var typeIndex = {};
	types.forEach( function ( t ) {
		typeIndex[ t.name ] = t;
	} );

	var GROUPS = [
		{ id: 'site', title: __( 'Site', 'leiho' ), test: function ( t ) {
			return ! t.collection;
		} },
		{ id: 'collections', title: __( 'Leiho collections', 'leiho' ), test: function ( t ) {
			return t.collection && ! t.singleton;
		} },
		{ id: 'singletons', title: __( 'Single pages', 'leiho' ), test: function ( t ) {
			return t.singleton;
		} },
	];

	function firstType() {
		for ( var i = 0; i < GROUPS.length; i++ ) {
			var found = types.filter( GROUPS[ i ].test )[ 0 ];
			if ( found ) {
				return found.name;
			}
		}
		return '';
	}

	// Ancien lien du constructeur (view=collections&collection=temoignages).
	var initialType = browser.dataset.type || params.get( 'type' ) || params.get( 'collection' ) || '';
	var initialPanel = 'fields' === params.get( 'panel' ) || ( params.get( 'collection' ) && ! params.get( 'type' ) ) ? 'fields' : 'entries';

	var state = {
		type: typeIndex[ initialType ] ? initialType : firstType(),
		panel: cfg.canCollections ? initialPanel : 'entries', // entries | fields | new.
		status: STATUSES[ params.get( 'status' ) ] ? params.get( 'status' ) : '',
		search: params.get( 's' ) || '',
		orderby: SORTS[ params.get( 'orderby' ) ] ? params.get( 'orderby' ) : 'modified',
		current: Number( browser.dataset.post || params.get( 'id' ) || 0 ),
		page: 1,
		totalPages: 1,
		total: 0,
		items: [],
		counts: {},
		request: 0,
	};

	/* ------------------------------------------------------------------ *
	 * Types et URLs
	 * ------------------------------------------------------------------ */

	function currentType() {
		return typeIndex[ state.type ] || null;
	}

	/** Collection Leiho dont le constructeur gère les champs. */
	function isNative( t ) {
		return !! ( t && t.collection );
	}

	function canUseEditor( item ) {
		var type = typeIndex[ item.type ];
		return !! ( cfg.frontUrl && item.preview_url && ( ! type || type.hasPages ) );
	}

	function editorUrl( item ) {
		return cfg.urls.editor + '&post=' + item.id;
	}

	function entryHref( item ) {
		return cfg.urls.collections + '&type=' + encodeURIComponent( item.type ) + '&id=' + item.id;
	}

	function syncUrl() {
		var url = new URL( window.location.href );
		[ 'type', 'id', 'panel', 'status', 's', 'orderby', 'new', 'collection', 'post', 'field' ].forEach( function ( key ) {
			url.searchParams.delete( key );
		} );
		var pairs = [
			[ 'type', 'new' === state.panel ? '' : state.type ],
			[ 'id', 'entries' === state.panel && state.current ? String( state.current ) : '' ],
			[ 'panel', 'fields' === state.panel ? 'fields' : '' ],
			[ 'status', state.status ],
			[ 's', state.search ],
			[ 'orderby', 'modified' === state.orderby ? '' : state.orderby ],
		];
		pairs.forEach( function ( pair ) {
			if ( pair[ 1 ] ) {
				url.searchParams.set( pair[ 0 ], pair[ 1 ] );
			}
		} );
		window.history.replaceState( null, '', url.toString() );
	}

	/* ------------------------------------------------------------------ *
	 * Garde : changements non enregistrés (entrée) ou non appliqués (champs)
	 * ------------------------------------------------------------------ */

	function guardEntry() {
		if ( ! Editor || ! Editor.isDirty() ) {
			return Promise.resolve( true );
		}
		var post = Editor.current();
		return App.confirm( {
			title: __( 'Discard your changes?', 'leiho' ),
			message: post && post.title
				? /* translators: %s: entry title. */
				  sprintf( __( '“%s” has unsaved changes. If you continue, they are lost.', 'leiho' ), post.title )
				: __( 'This entry has unsaved changes. If you continue, they are lost.', 'leiho' ),
			confirmLabel: __( 'Discard changes', 'leiho' ),
			cancelLabel: __( 'Keep editing', 'leiho' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ok ) {
				Editor.discard();
			}
			return ok;
		} );
	}

	function guardFields() {
		if ( ! Builder || 'entries' === state.panel || ! Builder.isDirty() ) {
			return true;
		}
		if ( Builder.confirmLeave() ) {
			Builder.discard();
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Squelette : volets
	 * ------------------------------------------------------------------ */

	var searchId = App.uid( 'search' );
	var statusId = App.uid( 'status' );
	var sortId = App.uid( 'sort' );

	var typeList = h( 'div', { class: 'leiho-pane__body' } );
	var navTools = h( 'div', { class: 'leiho-pane__tools' } );
	if ( cfg.canCollections && Builder ) {
		App.append( navTools, [
			h( 'button', { type: 'button', class: 'leiho-icon-btn', title: __( 'Languages', 'leiho' ), 'aria-label': __( 'Languages of the collections', 'leiho' ), onClick: Builder.languages }, icon( 'globe' ) ),
			h( 'button', { type: 'button', class: 'leiho-icon-btn', title: __( 'Export the schema', 'leiho' ), 'aria-label': __( 'Export the schema (leiho.schema.json)', 'leiho' ), onClick: Builder.exportSchema }, icon( 'download' ) ),
			h( 'button', { type: 'button', class: 'leiho-icon-btn', title: __( 'Import a schema', 'leiho' ), 'aria-label': __( 'Import a schema (leiho.schema.json)', 'leiho' ), onClick: Builder.importSchema }, icon( 'upload' ) ),
			h( 'button', { type: 'button', class: 'leiho-icon-btn', title: __( 'New collection', 'leiho' ), 'aria-label': __( 'New collection', 'leiho' ), onClick: newCollection }, icon( 'plus' ) ),
		] );
	}
	var navPane = h( 'nav', { class: 'leiho-pane leiho-pane--nav', 'aria-label': __( 'Content types', 'leiho' ) }, [
		h( 'div', { class: 'leiho-pane__head' }, [
			h( 'h1', { class: 'leiho-pane__title', text: __( 'Collections', 'leiho' ) } ),
			navTools,
		] ),
		typeList,
	] );

	var searchInput = h( 'input', {
		type: 'search',
		id: searchId,
		class: 'leiho-input',
		value: state.search,
		placeholder: __( 'Search by title…', 'leiho' ),
	} );
	var statusSelect = h(
		'select',
		{ id: statusId, class: 'leiho-input', title: __( 'Status', 'leiho' ) },
		[ h( 'option', { value: '', text: __( 'All statuses', 'leiho' ) } ) ].concat(
			Object.keys( STATUSES ).map( function ( key ) {
				return h( 'option', { value: key, text: STATUSES[ key ], selected: key === state.status, dataset: { dot: STATUS_DOTS[ key ] || 'neutral' } } );
			} )
		)
	);
	var sortSelect = h(
		'select',
		{ id: sortId, class: 'leiho-input', title: __( 'Sort', 'leiho' ) },
		Object.keys( SORTS ).map( function ( key ) {
			return h( 'option', { value: key, text: SORTS[ key ], selected: key === state.orderby } );
		} )
	);

	var listTitle = h( 'h2', { class: 'leiho-pane__title' } );
	var listTools = h( 'div', { class: 'leiho-pane__tools' } );
	var list = h( 'ul', { class: 'leiho-rows leiho-entries', 'aria-busy': 'true' } );
	var summary = h( 'span', { 'aria-live': 'polite' } );
	var pager = h( 'nav', { class: 'leiho-pager', 'aria-label': __( 'Pagination', 'leiho' ) } );
	var listPane = h( 'section', { class: 'leiho-pane leiho-pane--list', 'aria-label': __( 'Entries', 'leiho' ) }, [
		h( 'div', { class: 'leiho-pane__head' }, [ listTitle, listTools ] ),
		h( 'div', { class: 'leiho-pane__search', role: 'search' }, [
			h( 'label', { for: searchId, class: 'leiho-sr', text: __( 'Search', 'leiho' ) } ),
			h( 'span', { class: 'leiho-input-icon' }, icon( 'search', 14 ) ),
			searchInput,
		] ),
		h( 'div', { class: 'leiho-pane__filters' }, [
			h( 'label', { for: statusId, class: 'leiho-sr', text: __( 'Status', 'leiho' ) } ),
			statusSelect,
			h( 'label', { for: sortId, class: 'leiho-sr', text: __( 'Sort', 'leiho' ) } ),
			sortSelect,
		] ),
		h( 'div', { class: 'leiho-pane__body' }, list ),
		h( 'div', { class: 'leiho-pane__foot' }, [ summary, pager ] ),
	] );

	// Champs d'un type qui n'est pas une collection Leiho (ACF, thème).
	var notePane = h( 'section', { class: 'leiho-pane leiho-pane--detail leiho-fields-note', hidden: true, 'aria-label': __( 'Fields', 'leiho' ) } );

	App.append( App.clear( browser ), [ navPane, listPane ] );
	if ( builderHost ) {
		builderHost.parentNode.insertBefore( notePane, builderHost.nextSibling );
	}

	var searchTimer = null;
	searchInput.addEventListener( 'input', function () {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			state.search = searchInput.value.trim();
			state.page = 1;
			load();
		}, 300 );
	} );
	statusSelect.addEventListener( 'change', function () {
		state.status = statusSelect.value;
		state.page = 1;
		load();
	} );
	sortSelect.addEventListener( 'change', function () {
		state.orderby = sortSelect.value;
		state.page = 1;
		load();
	} );

	/* ------------------------------------------------------------------ *
	 * Onglets Entrées / Champs
	 * ------------------------------------------------------------------ */

	function tabsControl() {
		if ( ! cfg.canCollections ) {
			return null;
		}
		var t = currentType();
		return h( 'span', { class: 'leiho-segment leiho-coll-tabs', role: 'group', 'aria-label': t ? t.label : __( 'Collection', 'leiho' ) }, [
			h( 'button', { type: 'button', 'aria-pressed': 'entries' === state.panel ? 'true' : 'false', onClick: function () {
				setPanel( 'entries' );
			} }, __( 'Entries', 'leiho' ) ),
			h( 'button', { type: 'button', 'aria-pressed': 'fields' === state.panel ? 'true' : 'false', onClick: function () {
				setPanel( 'fields' );
			} }, __( 'Fields', 'leiho' ) ),
		] );
	}

	function newEntryButton() {
		var t = currentType();
		if ( ! t || ! t.canCreate || t.singleton ) {
			return null;
		}
		var label = __( 'New entry', 'leiho' );
		return h( 'button', { type: 'button', class: 'leiho-icon-btn', title: label, 'aria-label': label, onClick: function () {
			openCreate( t.name );
		} }, icon( 'plus' ) );
	}

	/** Nouvelle entrée écrite par l'IA (ai.js), créée en brouillon puis ouverte. */
	function aiEntryButton() {
		var t = currentType();
		if ( ! t || ! t.canCreate || t.singleton || ! window.LeihoAI ) {
			return null;
		}
		return window.LeihoAI.newEntryButton( t, function ( post ) {
			guardEntry().then( function ( ok ) {
				if ( ok ) {
					showCreated( post, t.name );
				}
			} );
		} );
	}

	function setPanel( panel ) {
		if ( panel === state.panel ) {
			return;
		}
		if ( ! guardFields() ) {
			return;
		}
		state.panel = panel;
		applyPanel();
		syncUrl();
	}

	function applyPanel() {
		var t = currentType();
		var fields = 'entries' !== state.panel;
		var native = 'new' === state.panel || isNative( t );
		editorRoot.classList.toggle( 'is-fields', fields );
		if ( builderHost ) {
			builderHost.hidden = ! fields || ! native || ! Builder;
		}
		notePane.hidden = ! fields || native;
		renderListHead();
		renderTypes();

		if ( ! fields ) {
			return;
		}
		if ( 'new' === state.panel ) {
			Builder.showNew();
		} else if ( native && Builder ) {
			Builder.show( t.name ).catch( function () {
				/* Message affiché par le constructeur. */
			} );
		} else {
			renderNote( t );
		}
	}

	function renderNote( t ) {
		var acf = !! cfg.acf;
		App.append( App.clear( notePane ), [
			h( 'div', { class: 'leiho-pane__head leiho-fields-note__head' }, [
				h( 'h2', { class: 'leiho-pane__title', text: t ? t.label : '' } ),
				h( 'div', { class: 'leiho-pane__tools' }, tabsControl() ),
			] ),
			h( 'div', { class: 'leiho-detail__body' }, h( 'div', { class: 'leiho-empty-state' }, [
				h( 'span', { class: 'leiho-empty-state__icon' }, icon( 'settings', 22 ) ),
				h( 'h3', { text: acf ? __( 'Fields managed in ACF', 'leiho' ) : __( 'Fields managed by WordPress', 'leiho' ) } ),
				h( 'p', { text: acf
					? __( 'This content type and its fields are defined in Advanced Custom Fields (or by the theme). Leiho edits their values; change the fields themselves in ACF.', 'leiho' )
					: __( 'This content type and its fields are defined by WordPress, the theme or a plugin. Leiho edits their values.', 'leiho' ) } ),
				canImport( t )
					? h( 'p', { class: 'leiho-muted', text: __( 'You can also move these fields into Leiho: Leiho then defines and stores them, and ACF is no longer needed for this content type. Nothing is deleted from ACF, and you can go back.', 'leiho' ) } )
					: null,
				h( 'div', { class: 'leiho-actions' }, [
					canImport( t ) ? importButton( t, true ) : null,
					acf && cfg.acfUrl
						? h( 'a', { class: 'leiho-btn leiho-btn--quiet', href: cfg.acfUrl }, [ icon( 'external' ), __( 'Open the ACF field groups', 'leiho' ) ] )
						: null,
				] ),
			] ) ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Import ACF → Leiho Collections (administrateurs)
	 * ------------------------------------------------------------------ */

	var IMPORT_API = '/leiho/v1/collections/import-acf';

	var LEIHO_TYPES = {
		text: __( 'Text', 'leiho' ),
		richtext: __( 'Rich text', 'leiho' ),
		number: __( 'Number', 'leiho' ),
		boolean: __( 'Yes / no', 'leiho' ),
		date: __( 'Date', 'leiho' ),
		datetime: __( 'Date and time', 'leiho' ),
		select: __( 'Choice', 'leiho' ),
		email: __( 'Email', 'leiho' ),
		url: __( 'URL', 'leiho' ),
		color: __( 'Color', 'leiho' ),
		image: __( 'Image', 'leiho' ),
		file: __( 'File', 'leiho' ),
		gallery: __( 'Gallery', 'leiho' ),
		link: __( 'Link', 'leiho' ),
		reference: __( 'Reference', 'leiho' ),
		list: __( 'List', 'leiho' ),
		blocks: __( 'Blocks', 'leiho' ),
		group: __( 'Group', 'leiho' ),
		block: __( 'Block type', 'leiho' ),
	};

	var IMPORT_NOTES = {
		layout: __( 'Screen layout only, no value', 'leiho' ),
		name: __( 'Name not usable in the API', 'leiho' ),
		clone: __( 'Clone field', 'leiho' ),
		clone_group: __( 'Clone kept as a group', 'leiho' ),
		unsupported: __( 'No Leiho equivalent', 'leiho' ),
		no_choices: __( 'No choices declared', 'leiho' ),
		empty: __( 'Nothing left inside', 'leiho' ),
		conditional: __( 'Conditional logic dropped', 'leiho' ),
		default: __( 'Default value dropped', 'leiho' ),
		media_rules: __( 'Media restrictions dropped', 'leiho' ),
		custom_choices: __( 'Values outside the choices refused', 'leiho' ),
		filters: __( 'Taxonomy filter dropped', 'leiho' ),
		bidirectional: __( 'No longer bidirectional', 'leiho' ),
		pro: __( 'Collapsed rows / pagination dropped', 'leiho' ),
		oembed: __( 'Kept as its URL', 'leiho' ),
		time_picker: __( 'Kept as text (HH:MM:SS)', 'leiho' ),
	};

	var IMPORT_STATUS = {
		ok: [ 'ok', __( 'Same', 'leiho' ) ],
		changed: [ 'warn', __( 'Adapted', 'leiho' ) ],
		dropped: [ 'error', __( 'Not carried over', 'leiho' ) ],
		ignored: [ '', __( 'Ignored', 'leiho' ) ],
	};

	/** Un type ACF (pas encore une collection) dont l'administrateur peut importer les champs. */
	function canImport( t ) {
		return !! ( cfg.canCollections && t && ! t.collection && t.acfImport );
	}

	function importButton( t, withLabel ) {
		var running = t.acfImport && 'running' === t.acfImport.status;
		var label = running ? __( 'Resume the import into Leiho', 'leiho' ) : __( 'Import into Leiho', 'leiho' );
		if ( withLabel ) {
			return h( 'button', { type: 'button', class: 'leiho-btn', onClick: function () {
				openImport( t );
			} }, [ icon( 'download' ), label ] );
		}
		return h( 'button', { type: 'button', class: 'leiho-icon-btn leiho-import-btn', title: label, 'aria-label': label, onClick: function () {
			openImport( t );
		} }, icon( 'download' ) );
	}

	function setActions( dialog, actions ) {
		var footer = dialog.el.querySelector( '.leiho-dialog__actions' );
		App.clear( footer );
		actions.forEach( function ( action ) {
			footer.appendChild( h( 'button', {
				type: 'button',
				class: 'leiho-btn' + ( action.primary ? '' : action.danger ? ' leiho-btn--danger' : ' leiho-btn--quiet' ),
				disabled: !! action.disabled,
				onClick: action.onClick,
			}, action.label ) );
		} );
	}

	function mapRow( node, depth ) {
		var pill = IMPORT_STATUS[ node.status ] || IMPORT_STATUS.ok;
		var target = node.leiho ? ( LEIHO_TYPES[ node.leiho ] || node.leiho ) : '—';
		var notes = ( node.notes || [] ).map( function ( n ) {
			return IMPORT_NOTES[ n ] || n;
		} ).join( ' · ' );
		var rows = [ h( 'li', { class: 'leiho-acf-map__row leiho-acf-map__row--' + node.status, style: depth ? '--depth:' + depth : null }, [
			h( 'span', { class: 'leiho-acf-map__name' }, [
				h( 'code', { text: node.name || '·' } ),
				node.label && node.label !== node.name ? h( 'span', { class: 'leiho-muted', text: node.label } ) : null,
			] ),
			h( 'span', { class: 'leiho-acf-map__types' }, [
				h( 'span', { class: 'leiho-mono', text: node.acf } ),
				h( 'span', { 'aria-hidden': 'true', text: ' → ' } ),
				h( 'span', { class: 'leiho-sr', text: ' ' + __( 'becomes', 'leiho' ) + ' ' } ),
				h( 'strong', { text: target } ),
			] ),
			h( 'span', { class: 'leiho-acf-map__status' }, [
				h( 'span', { class: 'leiho-pill' + ( pill[ 0 ] ? ' leiho-pill--' + pill[ 0 ] : '' ), text: pill[ 1 ] } ),
				notes ? h( 'span', { class: 'leiho-acf-map__notes', text: notes } ) : null,
			] ),
		] ) ];
		( node.fields || [] ).forEach( function ( child ) {
			rows = rows.concat( mapRow( child, depth + 1 ) );
		} );
		return rows;
	}

	function previewBody( p ) {
		var counts = p.counts || {};
		var out = [
			h( 'p', { text: sprintf(
				/* translators: 1: content type, 2: number of entries, 3: number of fields, 4: number of stored values. */
				__( 'Leiho creates the collection “%1$s” from its ACF fields, then copies %2$s entries (%3$s fields, %4$s stored values) with the same checks as the editor. The front end keeps receiving the same JSON; each entry is checked after the copy.', 'leiho' ),
				p.label, counts.entries, counts.fields, counts.values
			) } ),
		];
		if ( ! p.importable ) {
			out.push( h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, [
				h( 'p', { text: p.reason || __( 'The Leiho collection built from these ACF fields is invalid.', 'leiho' ) } ),
			].concat( ( p.errors || [] ).map( function ( e ) {
				return h( 'p', { text: ( e.path ? e.path + ' : ' : '' ) + e.message } );
			} ) ) ) );
		}
		( p.groups || [] ).forEach( function ( g ) {
			var rows = [];
			g.fields.forEach( function ( f ) {
				rows = rows.concat( mapRow( f, 0 ) );
			} );
			out.push( h( 'section', { class: 'leiho-acf-group' }, [
				h( 'h3', { class: 'leiho-group-label' }, [
					/* translators: %s: ACF field group title. */
					sprintf( __( 'Field group “%s”', 'leiho' ), g.title ),
					g.conditional ? h( 'span', { class: 'leiho-pill leiho-pill--warn', text: __( 'Extra location rules', 'leiho' ) } ) : null,
				] ),
				h( 'ul', { class: 'leiho-acf-map' }, rows ),
			] ) );
		} );
		if ( p.lost && p.lost.length ) {
			out.push( h( 'section', { class: 'leiho-acf-group' }, [
				h( 'h3', { class: 'leiho-group-label', text: __( 'What doesn’t carry over', 'leiho' ) } ),
				h( 'ul', { class: 'leiho-acf-lost' }, p.lost.map( function ( l ) {
					return h( 'li', {}, [
						h( 'span', { text: l.message } ),
						l.items && l.items.length ? h( 'span', { class: 'leiho-mono leiho-muted', text: ' ' + l.items.join( ', ' ) } ) : null,
					] );
				} ) ),
			] ) );
		}
		return out;
	}

	function progressBody( rec ) {
		var total = Math.max( 1, rec.total || 0 );
		return [
			h( 'p', { text: sprintf(
				/* translators: 1: entries copied, 2: total entries. */
				__( 'Copying the entries: %1$s of %2$s. You can close this window: the import resumes where it stopped.', 'leiho' ),
				rec.done || 0, rec.total || 0
			) } ),
			h( 'progress', { class: 'leiho-progress', max: total, value: Math.min( rec.done || 0, total ), 'aria-label': __( 'Import progress', 'leiho' ) } ),
			rec.failed || rec.different
				? h( 'p', { class: 'leiho-muted', text: sprintf(
					/* translators: 1: number of failures, 2: number of differences. */
					__( 'So far: %1$s failures, %2$s differences.', 'leiho' ),
					rec.failed || 0, rec.different || 0
				) } )
				: null,
		];
	}

	function entryLink( type, item ) {
		return h( 'a', { href: cfg.urls.collections + '&type=' + encodeURIComponent( type ) + '&id=' + item.post_id, text: item.title || '#' + item.post_id } );
	}

	function resultBody( type, rec ) {
		var out = [
			h( 'div', { class: 'leiho-acf-result' }, [
				h( 'span', { class: 'leiho-pill leiho-pill--ok', text: sprintf( _n( '%d entry imported', '%d entries imported', rec.imported, 'leiho' ), rec.imported ) } ),
				h( 'span', { class: 'leiho-pill' + ( rec.failed ? ' leiho-pill--error' : '' ), text: sprintf( _n( '%d failure', '%d failures', rec.failed, 'leiho' ), rec.failed ) } ),
				h( 'span', { class: 'leiho-pill' + ( rec.different ? ' leiho-pill--warn' : '' ), text: sprintf( _n( '%d difference', '%d differences', rec.different, 'leiho' ), rec.different ) } ),
			] ),
			h( 'p', { text: rec.failed || rec.different
				? __( 'The content type is now served by Leiho. The values below were refused or changed by Leiho’s checks; ACF still holds the originals. Correct them in Leiho.', 'leiho' )
				: __( 'The content type is now served by Leiho, and every entry gives the front end the same values as before.', 'leiho' ) } ),
		];
		if ( rec.failures && rec.failures.length ) {
			out.push( h( 'h3', { class: 'leiho-group-label', text: __( 'Failures', 'leiho' ) } ) );
			out.push( h( 'ul', { class: 'leiho-acf-issues' }, rec.failures.map( function ( f ) {
				return h( 'li', {}, [ entryLink( type, f ), ' · ', h( 'code', { text: f.field } ), ' — ', f.message ] );
			} ) ) );
		}
		if ( rec.diffs && rec.diffs.length ) {
			out.push( h( 'h3', { class: 'leiho-group-label', text: __( 'Values served differently', 'leiho' ) } ) );
			out.push( h( 'ul', { class: 'leiho-acf-issues' }, rec.diffs.map( function ( d ) {
				return h( 'li', {}, [
					entryLink( type, d ), ' · ', h( 'code', { text: d.field } ),
					h( 'div', { class: 'leiho-acf-diff' }, [
						h( 'span', { class: 'leiho-mono', text: __( 'ACF:', 'leiho' ) + ' ' + d.before } ),
						h( 'span', { class: 'leiho-mono', text: __( 'Leiho:', 'leiho' ) + ' ' + d.after } ),
					] ),
				] );
			} ) ) );
		}
		return out;
	}

	/**
	 * Aperçu → import par lots avec progression → résultat.
	 *
	 * @param {Object} t Type (cfg.types).
	 */
	function openImport( t ) {
		var stopped = false;
		var dialog = App.modal( {
			title: sprintf( /* translators: %s: content type. */ __( 'Import “%s” into Leiho', 'leiho' ), t.label ),
			wide: true,
			sticky: true,
			body: h( 'p', { class: 'leiho-muted', text: __( 'Reading the ACF fields…', 'leiho' ) } ),
			actions: [ { label: __( 'Cancel', 'leiho' ) } ],
			onClose: function () {
				stopped = true;
			},
		} );
		dialog.el.classList.add( 'leiho-dialog--import' );

		function show( nodes ) {
			App.append( App.clear( dialog.body ), nodes );
		}

		function finish( rec ) {
			show( resultBody( t.name, rec ) );
			App.announce( __( 'Import finished.', 'leiho' ) );
			setActions( dialog, [ { label: __( 'Open the collection', 'leiho' ), primary: true, onClick: function () {
				window.location.href = cfg.urls.collections + '&type=' + encodeURIComponent( t.name ) + '&panel=fields';
			} } ] );
		}

		function run() {
			return App.api( { path: IMPORT_API + '/run', method: 'POST', data: { post_type: t.name } } ).then( function ( rec ) {
				if ( 'done' === rec.status ) {
					finish( rec );
					return;
				}
				show( progressBody( rec ) );
				if ( ! stopped ) {
					return run();
				}
			} );
		}

		function start() {
			show( progressBody( { done: 0, total: 0 } ) );
			setActions( dialog, [ { label: __( 'Close', 'leiho' ), onClick: function () {
				dialog.close();
			} } ] );
			run().catch( function ( err ) {
				show( h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, h( 'p', { text: App.errorMessage( err, __( 'The import stopped.', 'leiho' ) ) } ) ) );
				setActions( dialog, [
					{ label: __( 'Close', 'leiho' ), onClick: function () {
						dialog.close();
					} },
					{ label: __( 'Try again', 'leiho' ), primary: true, onClick: start },
				] );
			} );
		}

		if ( t.acfImport && 'running' === t.acfImport.status ) {
			start();
			return;
		}

		App.api( { path: IMPORT_API + '/preview?post_type=' + encodeURIComponent( t.name ) } )
			.then( function ( p ) {
				show( previewBody( p ) );
				setActions( dialog, [
					{ label: __( 'Cancel', 'leiho' ), onClick: function () {
						dialog.close();
					} },
					{
						label: sprintf( _n( 'Import %d entry', 'Import %d entries', p.counts.entries, 'leiho' ), p.counts.entries ),
						primary: true,
						disabled: ! p.importable,
						onClick: start,
					},
				] );
			} )
			.catch( function ( err ) {
				show( h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, h( 'p', { text: App.errorMessage( err, __( 'Couldn’t read the ACF fields.', 'leiho' ) ) } ) ) );
			} );
	}

	/**
	 * Retour à ACF d'une collection importée (réglages de la collection).
	 *
	 * @param {string} name  Type.
	 * @param {string} label Libellé.
	 */
	function rollbackImport( name, label ) {
		App.confirm( {
			/* translators: %s: content type. */
			title: sprintf( __( 'Go back to ACF for “%s”?', 'leiho' ), label || name ),
			message: __( 'ACF serves the fields of this content type again, with the values they had on the import day. Values edited in Leiho since the import are not written back to ACF: they are deleted with the Leiho table of this collection. WordPress’s own fields (title, content, excerpt, slug, categories and tags) keep their current values: they are not undone.', 'leiho' ),
			confirmLabel: __( 'Go back to ACF', 'leiho' ),
			danger: true,
			typeToConfirm: name,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: IMPORT_API + '/rollback', method: 'POST', data: { post_type: name, confirm: name } } )
				.then( function () {
					App.toast( __( 'The content type is served by ACF again.', 'leiho' ) );
					window.location.href = cfg.urls.collections + '&type=' + encodeURIComponent( name );
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err, __( 'Couldn’t go back to ACF.', 'leiho' ) ), true );
				} );
		} );
	}

	window.LeihoAcfImport = { open: openImport, rollback: rollbackImport };

	function renderListHead() {
		var t = currentType();
		listTitle.textContent = t ? t.label : __( 'No content type', 'leiho' );
		App.append( App.clear( listTools ), [ canImport( t ) ? importButton( t, false ) : null, tabsControl(), aiEntryButton(), newEntryButton() ] );
	}

	function newCollection() {
		if ( ! Builder || ! guardFields() ) {
			return;
		}
		state.panel = 'new';
		applyPanel();
		syncUrl();
	}

	if ( Builder ) {
		Builder.setHooks( {
			headTools: tabsControl,
			// Le schéma a changé (types, libellés, chemins) : la vue se recharge.
			onSaved: function ( id ) {
				var url = cfg.urls.collections;
				if ( id && ( typeIndex[ id ] || 'new' === state.panel ) ) {
					url += '&type=' + encodeURIComponent( id ) + '&panel=fields';
				}
				window.location.href = url;
			},
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Types de contenu (volet de gauche)
	 * ------------------------------------------------------------------ */

	function typeIcon( t ) {
		return icon( t.collection ? ( t.singleton ? 'page' : 'database' ) : 'page', 15 );
	}

	function typeRow( t ) {
		var active = 'new' !== state.panel && state.type === t.name;
		var count = state.counts[ t.name ];
		return h( 'li', {}, h( 'a', {
			href: cfg.urls.collections + '&type=' + encodeURIComponent( t.name ),
			class: 'leiho-coll' + ( active ? ' is-active' : '' ),
			'aria-current': active ? 'page' : null,
			'aria-label': undefined === count
				? t.label
				: /* translators: 1: content type, 2: number of entries. */
				  sprintf( __( '%1$s, %2$s', 'leiho' ), t.label, sprintf( _n( '%d entry', '%d entries', count, 'leiho' ), count ) ),
			onClick: function ( event ) {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				selectType( t.name );
			},
		}, [
			h( 'span', { class: 'leiho-coll__icon' + ( t.collection ? ' leiho-coll__icon--collection' : '' ) }, typeIcon( t ) ),
			h( 'span', { class: 'leiho-coll__name', text: t.label } ),
			undefined === count ? null : h( 'span', { class: 'leiho-coll__count', 'aria-hidden': 'true', text: String( count ) } ),
		] ) );
	}

	function renderTypes() {
		App.clear( typeList );
		GROUPS.forEach( function ( group ) {
			var items = types.filter( group.test );
			var rows = items.map( typeRow );
			if ( 'collections' === group.id && 'new' === state.panel ) {
				rows.push( h( 'li', {}, h( 'span', { class: 'leiho-coll is-active', 'aria-current': 'true' }, [
					h( 'span', { class: 'leiho-coll__icon leiho-coll__icon--collection' }, icon( 'plus', 15 ) ),
					h( 'span', { class: 'leiho-coll__name', text: __( 'New collection', 'leiho' ) } ),
				] ) ) );
			} else if ( 'collections' === group.id && cfg.canCollections && Builder ) {
				rows.push( h( 'li', {}, h( 'button', { type: 'button', class: 'leiho-coll leiho-coll--add', onClick: newCollection }, [
					h( 'span', { class: 'leiho-coll__icon' }, icon( 'plus', 15 ) ),
					h( 'span', { class: 'leiho-coll__name', text: __( 'New collection', 'leiho' ) } ),
				] ) ) );
			}
			if ( ! rows.length ) {
				return;
			}
			typeList.appendChild( h( 'h2', { class: 'leiho-group-label', text: group.title } ) );
			typeList.appendChild( h( 'ul', { class: 'leiho-rows' }, rows ) );
		} );
		if ( ! types.length ) {
			typeList.appendChild( h( 'p', { class: 'leiho-list-empty', text: __( 'No content type is editable in Leiho yet. Choose them in the settings.', 'leiho' ) } ) );
		}
	}

	function selectType( name ) {
		if ( name === state.type && 'new' !== state.panel ) {
			return;
		}
		if ( ! guardFields() ) {
			return;
		}
		guardEntry().then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			if ( Editor && Editor.current() ) {
				Editor.close();
			}
			state.type = name;
			state.current = 0;
			state.page = 1;
			state.search = '';
			searchInput.value = '';
			if ( 'new' === state.panel || ( 'fields' === state.panel && ! cfg.canCollections ) ) {
				state.panel = 'entries';
			}
			applyPanel();
			load( { autoOpen: true } );
		} );
	}

	/** Nombre d'entrées de chaque type (une requête légère par type). */
	function loadCounts() {
		return Promise.all( types.map( function ( t ) {
			var query = new URLSearchParams( { per_page: '1', page: '1', type: t.name } );
			return App.api( { path: '/leiho/v1/posts?' + query.toString(), parse: false } )
				.then( function ( response ) {
					state.counts[ t.name ] = Number( response.headers.get( 'X-WP-Total' ) || 0 );
				} )
				.catch( function () {
					/* Le nombre manque, la ligne reste utilisable. */
				} );
		} ) ).then( renderTypes );
	}

	/* ------------------------------------------------------------------ *
	 * Entrées (volet du milieu)
	 * ------------------------------------------------------------------ */

	function load( options ) {
		options = options || {};
		var request = ++state.request;
		if ( ! state.type ) {
			App.clear( list ).appendChild( h( 'li', { class: 'leiho-list-empty', text: __( 'Nothing to show.', 'leiho' ) } ) );
			list.setAttribute( 'aria-busy', 'false' );
			return Promise.resolve();
		}
		list.setAttribute( 'aria-busy', 'true' );
		syncUrl();

		var query = new URLSearchParams( {
			per_page: String( PER_PAGE ),
			page: String( state.page ),
			search: state.search,
			type: state.type,
			status: state.status,
			orderby: state.orderby,
		} );

		return App.api( { path: '/leiho/v1/posts?' + query.toString(), parse: false } )
			.then( function ( response ) {
				state.totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) || 1 );
				state.total = Number( response.headers.get( 'X-WP-Total' ) || 0 );
				return response.json();
			} )
			.then( function ( items ) {
				if ( request !== state.request ) {
					return;
				}
				state.items = items;
				render();
				var open = Editor && Editor.current();
				if ( open ) {
					var fresh = items.find( function ( i ) {
						return i.id === open.id;
					} );
					if ( fresh ) {
						Editor.refresh( fresh );
					}
				}
				// Page unique : son entrée s'ouvre d'elle-même.
				var t = currentType();
				if ( options.autoOpen && t && t.singleton && items.length && ! state.current && 'entries' === state.panel ) {
					selectEntry( items[ 0 ] );
				}
			} )
			.catch( function ( err ) {
				if ( request !== state.request ) {
					return;
				}
				App.clear( list ).appendChild( h( 'li', { class: 'leiho-list-empty', text: App.errorMessage( err, __( 'Couldn’t load the content list.', 'leiho' ) ) } ) );
			} )
			.finally( function () {
				if ( request === state.request ) {
					list.setAttribute( 'aria-busy', 'false' );
				}
			} );
	}

	/** Après une action : la liste et les nombres par type. */
	function reload() {
		loadCounts();
		return load();
	}

	function render() {
		App.clear( list );
		summary.textContent = sprintf(
			/* translators: %d: number of entries. */
			_n( '%d entry', '%d entries', state.total, 'leiho' ),
			state.total
		);

		if ( ! state.items.length ) {
			var t = currentType();
			list.appendChild(
				h( 'li', { class: 'leiho-list-empty' }, [
					h( 'p', { text: state.search || state.status ? __( 'Nothing matches these filters', 'leiho' ) : __( 'No entries yet', 'leiho' ) } ),
					t && t.canCreate && ! t.singleton && ! state.search
						? h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet leiho-btn--small', onClick: function () {
							openCreate( t.name );
						} }, __( 'Create an entry', 'leiho' ) )
						: null,
				] )
			);
			renderPager();
			return;
		}

		state.items.forEach( function ( item ) {
			list.appendChild( row( item ) );
		} );
		renderPager();
		var active = list.querySelector( '.is-active' );
		if ( active && active.scrollIntoView ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	}

	var TRANSLATION_STATES = {
		missing: __( 'Not translated', 'leiho' ),
		partial: __( 'Partly translated', 'leiho' ),
		outdated: __( 'Original changed since the translation', 'leiho' ),
	};

	function row( item ) {
		var type = typeIndex[ item.type ];
		var trashed = 'trash' === item.status;
		var title = item.title || __( '(no title)', 'leiho' );
		var statusText = STATUSES[ item.status ] || item.status;

		var actions = h( 'div', { class: 'leiho-item__actions' } );
		if ( trashed ) {
			if ( item.can_delete ) {
				actions.appendChild( actionBtn( 'restore', __( 'Restore', 'leiho' ), title, function () {
					restore( item );
				} ) );
			}
		} else {
			if ( canUseEditor( item ) ) {
				actions.appendChild( actionLink( 'edit', __( 'Visual editor', 'leiho' ), title, editorUrl( item ) ) );
			}
			if ( 'publish' === item.status ) {
				if ( ! type || ! type.singleton ) {
					actions.appendChild( actionBtn( 'draft', __( 'Back to draft', 'leiho' ), title, function () {
						setStatus( item, 'draft' );
					} ) );
				}
			} else if ( 'future' !== item.status && 'private' !== item.status ) {
				var publishable = ! type || type.canPublish;
				actions.appendChild( actionBtn( 'check', publishable ? __( 'Mark as published', 'leiho' ) : __( 'Submit for review', 'leiho' ), title, function () {
					setStatus( item, publishable ? 'publish' : 'pending' );
				} ) );
			}
			if ( type && type.canCreate && ! type.singleton ) {
				actions.appendChild( actionBtn( 'copy', __( 'Duplicate', 'leiho' ), title, function () {
					duplicate( item );
				} ) );
			}
			if ( item.can_delete ) {
				actions.appendChild( actionBtn( 'trash', __( 'Move to trash', 'leiho' ), title, function () {
					trash( item );
				} ) );
			}
		}

		var active = state.current && item.id === state.current;
		/* translators: 1: content title, 2: status (e.g. Draft), 3: content type. */
		var label = sprintf( __( '%1$s — %2$s, %3$s', 'leiho' ), title, statusText, type ? type.singular : item.type );
		var main = [
			h( 'span', { class: 'leiho-status-dot leiho-status-dot--' + statusLevel( item.status ), title: statusText } ),
			h( 'span', { class: 'leiho-item__title' + ( item.title ? '' : ' is-blank' ), text: title } ),
		];
		// Langues à traduire ou à revoir (rien quand tout est traduit).
		Object.keys( item.translations || {} ).forEach( function ( code ) {
			var level = item.translations[ code ];
			if ( 'complete' !== level ) {
				main.push( h( 'span', { class: 'leiho-l10n-badge is-' + level, title: TRANSLATION_STATES[ level ] || level, text: code.toUpperCase() } ) );
			}
		} );

		return h( 'li', { class: 'leiho-item' + ( active ? ' is-active' : '' ), dataset: { id: String( item.id ) } }, [
			trashed
				? h( 'span', { class: 'leiho-item__link', 'aria-label': label }, main )
				: h( 'a', {
					class: 'leiho-item__link',
					href: entryHref( item ),
					'aria-label': label,
					'aria-current': active ? 'true' : null,
					onClick: function ( event ) {
						if ( event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button ) {
							return;
						}
						event.preventDefault();
						selectEntry( item );
					},
				}, main ),
			h( 'time', { class: 'leiho-item__meta', datetime: item.modified || '', title: item.modified ? new Date( item.modified ).toLocaleString() : '', text: App.formatDate( item.modified ) } ),
			actions,
		] );
	}

	function markActive() {
		Array.prototype.forEach.call( list.querySelectorAll( '.leiho-item' ), function ( li ) {
			var on = Number( li.dataset.id ) === state.current;
			li.classList.toggle( 'is-active', on );
			var link = li.querySelector( 'a.leiho-item__link' );
			if ( link ) {
				if ( on ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			}
		} );
	}

	function statusLevel( status ) {
		return 'publish' === status ? 'ok' : 'trash' === status ? 'error' : 'draft' === status || 'pending' === status ? 'warn' : 'neutral';
	}

	function actionLink( iconName, label, title, href ) {
		/* translators: 1: action (e.g. Duplicate), 2: content title. */
		var aria = sprintf( __( '%1$s: %2$s', 'leiho' ), label, title );
		return h( 'a', { class: 'leiho-icon-btn', href: href, title: label, 'aria-label': aria }, icon( iconName ) );
	}

	function actionBtn( iconName, label, title, onClick ) {
		/* translators: 1: action (e.g. Duplicate), 2: content title. */
		var aria = sprintf( __( '%1$s: %2$s', 'leiho' ), label, title );
		return h( 'button', { type: 'button', class: 'leiho-icon-btn' + ( 'trash' === iconName ? ' leiho-icon-btn--danger' : '' ), title: label, 'aria-label': aria, onClick: onClick }, icon( iconName ) );
	}

	function renderPager() {
		App.clear( pager );
		if ( state.totalPages <= 1 ) {
			return;
		}
		App.append( pager, [
			h( 'button', { type: 'button', class: 'leiho-icon-btn leiho-icon-btn--small', 'aria-label': __( 'Previous', 'leiho' ), title: __( 'Previous', 'leiho' ), disabled: state.page <= 1, onClick: function () {
				state.page -= 1;
				load();
			} }, icon( 'up' ) ),
			h( 'span', {}, sprintf(
				/* translators: 1: current page, 2: number of pages. */
				__( 'Page %1$d of %2$d', 'leiho' ),
				state.page,
				state.totalPages
			) ),
			h( 'button', { type: 'button', class: 'leiho-icon-btn leiho-icon-btn--small', 'aria-label': __( 'Next', 'leiho' ), title: __( 'Next', 'leiho' ), disabled: state.page >= state.totalPages, onClick: function () {
				state.page += 1;
				load();
			} }, icon( 'down' ) ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Entrée ouverte (volet de droite)
	 * ------------------------------------------------------------------ */

	function selectEntry( item ) {
		if ( ! Editor ) {
			return;
		}
		var open = Editor.current();
		if ( open && open.id === item.id && 'entries' === state.panel ) {
			return;
		}
		guardEntry().then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			state.current = item.id;
			markActive();
			syncUrl();
			Editor.open( item ).catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t open this entry.', 'leiho' ) ), true );
			} );
		} );
	}

	function closeEntry() {
		guardEntry().then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			if ( Editor ) {
				Editor.close();
			}
			state.current = 0;
			markActive();
			syncUrl();
		} );
	}

	if ( entryBack ) {
		entryBack.addEventListener( 'click', closeEntry );
	}

	// L'éditeur a enregistré l'entrée ou changé son statut : titre, pastille
	// et date de la liste, nombres par type.
	document.addEventListener( 'leiho:entry-changed', function () {
		App.notifyChanged();
		reload();
	} );

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	function setStatus( item, status ) {
		App.api( { path: '/leiho/v1/posts/' + item.id + '/status', method: 'POST', data: { status: status } } )
			.then( function ( fresh ) {
				/* translators: 1: content title, 2: status (e.g. Published). */
				App.toast( sprintf( __( '“%1$s”: %2$s.', 'leiho' ), fresh.title, STATUSES[ fresh.status ] || fresh.status ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t change the status.', 'leiho' ) ), true );
			} );
	}

	function duplicate( item ) {
		App.api( { path: '/leiho/v1/posts/' + item.id + '/duplicate', method: 'POST' } )
			.then( function ( copy ) {
				/* translators: %s: content title. */
				App.toast( sprintf( __( '“%s” created as a draft.', 'leiho' ), copy.title ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t duplicate this content.', 'leiho' ) ), true );
			} );
	}

	function trash( item ) {
		App.confirm( {
			title: __( 'Move to trash?', 'leiho' ),
			/* translators: %s: content title. */
			message: sprintf( __( '“%s” will be moved to the trash. You can restore it from the Trash filter.', 'leiho' ), item.title ),
			confirmLabel: __( 'Move to trash', 'leiho' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/leiho/v1/posts/' + item.id, method: 'DELETE' } )
				.then( function () {
					/* translators: %s: content title. */
					App.toast( sprintf( __( '“%s” moved to the trash.', 'leiho' ), item.title ) );
					App.notifyChanged();
					// L'entrée ouverte part à la corbeille : le volet se vide.
					if ( Editor && Editor.current() && Editor.current().id === item.id ) {
						Editor.discard();
						Editor.close();
						state.current = 0;
						syncUrl();
					}
					return reload();
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err, __( 'Couldn’t delete this content.', 'leiho' ) ), true );
				} );
		} );
	}

	function restore( item ) {
		App.api( { path: '/leiho/v1/posts/' + item.id + '/restore', method: 'POST' } )
			.then( function () {
				/* translators: %s: content title. */
				App.toast( sprintf( __( '“%s” restored as a draft.', 'leiho' ), item.title ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t restore this content.', 'leiho' ) ), true );
			} );
	}

	/**
	 * Nouvelle entrée : du type choisi, ou (lien « Nouveau contenu » de
	 * l'accueil) d'un type à choisir.
	 */
	function openCreate( typeName ) {
		var creatable = types.filter( function ( t ) {
			return t.canCreate && ! t.singleton;
		} );
		var fixed = typeName && typeIndex[ typeName ] && typeIndex[ typeName ].canCreate && ! typeIndex[ typeName ].singleton ? typeIndex[ typeName ] : null;
		if ( ! fixed && ! creatable.length ) {
			return;
		}
		var titleId = App.uid( 'new-title' );
		var typeFieldId = App.uid( 'new-type' );
		var title = h( 'input', { type: 'text', id: titleId, class: 'leiho-input', required: true, autofocus: true } );
		var typeSelect = fixed
			? null
			: h( 'select', { id: typeFieldId, class: 'leiho-input' }, creatable.map( function ( t ) {
				return h( 'option', { value: t.name, text: t.singular, selected: t.name === state.type } );
			} ) );
		var form = h( 'form', { class: 'leiho-form-grid', onSubmit: function ( event ) {
			event.preventDefault();
			submit();
		} }, [
			typeSelect ? h( 'label', { for: typeFieldId, class: 'leiho-label', text: __( 'Type', 'leiho' ) } ) : null,
			typeSelect,
			h( 'label', { for: titleId, class: 'leiho-label', text: __( 'Title', 'leiho' ) } ),
			title,
			h( 'p', { class: 'leiho-help', text: __( 'Created as a draft: nothing is visible on the site until you publish it.', 'leiho' ) } ),
			h( 'button', { type: 'submit', hidden: true } ),
		] );

		var dialog = App.modal( {
			title: fixed
				? /* translators: %s: content type (e.g. Services). */
				  sprintf( __( 'New entry in %s', 'leiho' ), fixed.label )
				: __( 'New content', 'leiho' ),
			body: form,
			actions: [
				{ label: __( 'Cancel', 'leiho' ) },
				{ label: __( 'Create and edit', 'leiho' ), primary: true, onClick: function () {
					submit();
				} },
			],
		} );
		title.focus();

		var busy = false;
		function submit() {
			if ( busy ) {
				return;
			}
			if ( ! title.value.trim() ) {
				title.setAttribute( 'aria-invalid', 'true' );
				title.focus();
				return;
			}
			var postType = fixed ? fixed.name : typeSelect.value;
			busy = true;
			guardEntry().then( function ( ok ) {
				if ( ! ok ) {
					busy = false;
					return;
				}
				App.api( { path: '/leiho/v1/posts', method: 'POST', data: { post_type: postType, title: title.value.trim() } } )
					.then( function ( post ) {
						dialog.close();
						return showCreated( post, postType );
					} )
					.catch( function ( err ) {
						busy = false;
						App.toast( App.errorMessage( err, __( 'Couldn’t create this content.', 'leiho' ) ), true );
					} );
			} );
		}
	}

	/** Entrée créée : la liste de son type se recharge et l'entrée s'ouvre. */
	function showCreated( post, postType ) {
		App.notifyChanged();
		if ( Editor && Editor.current() ) {
			Editor.close();
		}
		state.current = 0;
		if ( postType !== state.type ) {
			state.type = postType;
			state.page = 1;
		}
		state.panel = 'entries';
		applyPanel();
		loadCounts();
		return load().then( function () {
			selectEntry( post );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Démarrage
	 * ------------------------------------------------------------------ */

	if ( 'new' === state.panel || ( 'fields' === state.panel && ! currentType() ) ) {
		state.panel = 'entries';
	}
	applyPanel();
	loadCounts();
	load( { autoOpen: true } );

	if ( state.current && Editor ) {
		Editor.openId( state.current )
			.then( function ( post ) {
				if ( post.type !== state.type && typeIndex[ post.type ] ) {
					state.type = post.type;
					applyPanel();
					load();
				}
				markActive();
			} )
			.catch( function () {
				state.current = 0;
				syncUrl();
				App.toast( __( 'This content no longer exists or you’re not allowed to edit it.', 'leiho' ), true );
			} );
	}

	if ( '1' === params.get( 'new' ) ) {
		openCreate( typeIndex[ params.get( 'type' ) ] ? params.get( 'type' ) : '' );
	}
} )();
