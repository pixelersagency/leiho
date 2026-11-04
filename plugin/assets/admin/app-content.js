/**
 * Okno — vue Collections : tous les contenus éditables, quel que soit leur
 * moteur (pages et articles WordPress, types ACF ou déclarés par le thème,
 * collections et pages uniques Okno), en trois volets :
 *
 * - à gauche, les types de contenu groupés (Site, Collections Okno, Pages
 *   uniques) avec leur nombre d'entrées, et « Nouvelle collection » ;
 * - au milieu, les entrées du type choisi (recherche, statut, tri, actions
 *   au survol) ;
 * - à droite, l'entrée choisie, éditée sur place par l'éditeur en mode
 *   formulaire (editor.js, window.OknoEditor) : mêmes champs, annuler /
 *   rétablir, brouillons locaux, protection des conflits, historique.
 *
 * L'onglet « Champs » d'une collection Okno remplace les deux volets de
 * droite par le constructeur de schéma (app-collections.js,
 * window.OknoBuilder) ; les types ACF y renvoient vers ACF.
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

	var App = window.OknoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	var browser = document.getElementById( 'okno-form-browser' );
	if ( ! App || ! browser ) {
		return;
	}

	var Editor = window.OknoEditor || null;
	var Builder = window.OknoBuilder || null;
	var editorRoot = document.getElementById( 'okno-editor' );
	var builderHost = document.getElementById( 'okno-builder' );
	var entryBack = document.getElementById( 'okno-entry-back' );

	var h = App.h, icon = App.icon, cfg = App.cfg;
	var PER_PAGE = 50;

	var STATUSES = {
		publish: __( 'Published', 'okno' ),
		future: __( 'Scheduled', 'okno' ),
		draft: __( 'Draft', 'okno' ),
		pending: __( 'Pending review', 'okno' ),
		private: __( 'Private', 'okno' ),
		trash: __( 'Trash', 'okno' ),
	};

	var SORTS = {
		modified: __( 'Last modified', 'okno' ),
		date: __( 'Newest first', 'okno' ),
		title: __( 'Title (A–Z)', 'okno' ),
	};

	var params = new URLSearchParams( window.location.search );
	var types = cfg.types || [];
	var typeIndex = {};
	types.forEach( function ( t ) {
		typeIndex[ t.name ] = t;
	} );

	var GROUPS = [
		{ id: 'site', title: __( 'Site', 'okno' ), test: function ( t ) {
			return ! t.collection;
		} },
		{ id: 'collections', title: __( 'Okno collections', 'okno' ), test: function ( t ) {
			return t.collection && ! t.singleton;
		} },
		{ id: 'singletons', title: __( 'Single pages', 'okno' ), test: function ( t ) {
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

	/** Collection Okno dont le constructeur gère les champs. */
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
			title: __( 'Discard your changes?', 'okno' ),
			message: post && post.title
				? /* translators: %s: entry title. */
				  sprintf( __( '“%s” has unsaved changes. If you continue, they are lost.', 'okno' ), post.title )
				: __( 'This entry has unsaved changes. If you continue, they are lost.', 'okno' ),
			confirmLabel: __( 'Discard changes', 'okno' ),
			cancelLabel: __( 'Keep editing', 'okno' ),
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

	var typeList = h( 'div', { class: 'okno-pane__body' } );
	var navTools = h( 'div', { class: 'okno-pane__tools' } );
	if ( cfg.canCollections && Builder ) {
		App.append( navTools, [
			h( 'button', { type: 'button', class: 'okno-icon-btn', title: __( 'Export the schema', 'okno' ), 'aria-label': __( 'Export the schema (okno.schema.json)', 'okno' ), onClick: Builder.exportSchema }, icon( 'download' ) ),
			h( 'button', { type: 'button', class: 'okno-icon-btn', title: __( 'Import a schema', 'okno' ), 'aria-label': __( 'Import a schema (okno.schema.json)', 'okno' ), onClick: Builder.importSchema }, icon( 'upload' ) ),
			h( 'button', { type: 'button', class: 'okno-icon-btn', title: __( 'New collection', 'okno' ), 'aria-label': __( 'New collection', 'okno' ), onClick: newCollection }, icon( 'plus' ) ),
		] );
	}
	var navPane = h( 'nav', { class: 'okno-pane okno-pane--nav', 'aria-label': __( 'Content types', 'okno' ) }, [
		h( 'div', { class: 'okno-pane__head' }, [
			h( 'h1', { class: 'okno-pane__title', text: __( 'Collections', 'okno' ) } ),
			navTools,
		] ),
		typeList,
	] );

	var searchInput = h( 'input', {
		type: 'search',
		id: searchId,
		class: 'okno-input',
		value: state.search,
		placeholder: __( 'Search by title…', 'okno' ),
	} );
	var statusSelect = h(
		'select',
		{ id: statusId, class: 'okno-input', title: __( 'Status', 'okno' ) },
		[ h( 'option', { value: '', text: __( 'All statuses', 'okno' ) } ) ].concat(
			Object.keys( STATUSES ).map( function ( key ) {
				return h( 'option', { value: key, text: STATUSES[ key ], selected: key === state.status } );
			} )
		)
	);
	var sortSelect = h(
		'select',
		{ id: sortId, class: 'okno-input', title: __( 'Sort', 'okno' ) },
		Object.keys( SORTS ).map( function ( key ) {
			return h( 'option', { value: key, text: SORTS[ key ], selected: key === state.orderby } );
		} )
	);

	var listTitle = h( 'h2', { class: 'okno-pane__title' } );
	var listTools = h( 'div', { class: 'okno-pane__tools' } );
	var list = h( 'ul', { class: 'okno-rows okno-entries', 'aria-busy': 'true' } );
	var summary = h( 'span', { 'aria-live': 'polite' } );
	var pager = h( 'nav', { class: 'okno-pager', 'aria-label': __( 'Pagination', 'okno' ) } );
	var listPane = h( 'section', { class: 'okno-pane okno-pane--list', 'aria-label': __( 'Entries', 'okno' ) }, [
		h( 'div', { class: 'okno-pane__head' }, [ listTitle, listTools ] ),
		h( 'div', { class: 'okno-pane__search', role: 'search' }, [
			h( 'label', { for: searchId, class: 'okno-sr', text: __( 'Search', 'okno' ) } ),
			h( 'span', { class: 'okno-input-icon' }, icon( 'search', 14 ) ),
			searchInput,
		] ),
		h( 'div', { class: 'okno-pane__filters' }, [
			h( 'label', { for: statusId, class: 'okno-sr', text: __( 'Status', 'okno' ) } ),
			statusSelect,
			h( 'label', { for: sortId, class: 'okno-sr', text: __( 'Sort', 'okno' ) } ),
			sortSelect,
		] ),
		h( 'div', { class: 'okno-pane__body' }, list ),
		h( 'div', { class: 'okno-pane__foot' }, [ summary, pager ] ),
	] );

	// Champs d'un type qui n'est pas une collection Okno (ACF, thème).
	var notePane = h( 'section', { class: 'okno-pane okno-pane--detail okno-fields-note', hidden: true, 'aria-label': __( 'Fields', 'okno' ) } );

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
		return h( 'span', { class: 'okno-segment okno-coll-tabs', role: 'group', 'aria-label': t ? t.label : __( 'Collection', 'okno' ) }, [
			h( 'button', { type: 'button', 'aria-pressed': 'entries' === state.panel ? 'true' : 'false', onClick: function () {
				setPanel( 'entries' );
			} }, __( 'Entries', 'okno' ) ),
			h( 'button', { type: 'button', 'aria-pressed': 'fields' === state.panel ? 'true' : 'false', onClick: function () {
				setPanel( 'fields' );
			} }, __( 'Fields', 'okno' ) ),
		] );
	}

	function newEntryButton() {
		var t = currentType();
		if ( ! t || ! t.canCreate || t.singleton ) {
			return null;
		}
		var label = __( 'New entry', 'okno' );
		return h( 'button', { type: 'button', class: 'okno-icon-btn', title: label, 'aria-label': label, onClick: function () {
			openCreate( t.name );
		} }, icon( 'plus' ) );
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
			h( 'div', { class: 'okno-pane__head okno-fields-note__head' }, [
				h( 'h2', { class: 'okno-pane__title', text: t ? t.label : '' } ),
				h( 'div', { class: 'okno-pane__tools' }, tabsControl() ),
			] ),
			h( 'div', { class: 'okno-detail__body' }, h( 'div', { class: 'okno-empty-state' }, [
				h( 'span', { class: 'okno-empty-state__icon' }, icon( 'settings', 22 ) ),
				h( 'h3', { text: acf ? __( 'Fields managed in ACF', 'okno' ) : __( 'Fields managed by WordPress', 'okno' ) } ),
				h( 'p', { text: acf
					? __( 'This content type and its fields are defined in Advanced Custom Fields (or by the theme). Okno edits their values; change the fields themselves in ACF.', 'okno' )
					: __( 'This content type and its fields are defined by WordPress, the theme or a plugin. Okno edits their values.', 'okno' ) } ),
				acf && cfg.acfUrl
					? h( 'a', { class: 'okno-btn okno-btn--quiet', href: cfg.acfUrl }, [ icon( 'external' ), __( 'Open the ACF field groups', 'okno' ) ] )
					: null,
			] ) ),
		] );
	}

	function renderListHead() {
		var t = currentType();
		listTitle.textContent = t ? t.label : __( 'No content type', 'okno' );
		App.append( App.clear( listTools ), [ tabsControl(), newEntryButton() ] );
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
			class: 'okno-coll' + ( active ? ' is-active' : '' ),
			'aria-current': active ? 'page' : null,
			'aria-label': undefined === count
				? t.label
				: /* translators: 1: content type, 2: number of entries. */
				  sprintf( __( '%1$s, %2$s', 'okno' ), t.label, sprintf( _n( '%d entry', '%d entries', count, 'okno' ), count ) ),
			onClick: function ( event ) {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				selectType( t.name );
			},
		}, [
			h( 'span', { class: 'okno-coll__icon' + ( t.collection ? ' okno-coll__icon--collection' : '' ) }, typeIcon( t ) ),
			h( 'span', { class: 'okno-coll__name', text: t.label } ),
			undefined === count ? null : h( 'span', { class: 'okno-coll__count', 'aria-hidden': 'true', text: String( count ) } ),
		] ) );
	}

	function renderTypes() {
		App.clear( typeList );
		GROUPS.forEach( function ( group ) {
			var items = types.filter( group.test );
			var rows = items.map( typeRow );
			if ( 'collections' === group.id && 'new' === state.panel ) {
				rows.push( h( 'li', {}, h( 'span', { class: 'okno-coll is-active', 'aria-current': 'true' }, [
					h( 'span', { class: 'okno-coll__icon okno-coll__icon--collection' }, icon( 'plus', 15 ) ),
					h( 'span', { class: 'okno-coll__name', text: __( 'New collection', 'okno' ) } ),
				] ) ) );
			} else if ( 'collections' === group.id && cfg.canCollections && Builder ) {
				rows.push( h( 'li', {}, h( 'button', { type: 'button', class: 'okno-coll okno-coll--add', onClick: newCollection }, [
					h( 'span', { class: 'okno-coll__icon' }, icon( 'plus', 15 ) ),
					h( 'span', { class: 'okno-coll__name', text: __( 'New collection', 'okno' ) } ),
				] ) ) );
			}
			if ( ! rows.length ) {
				return;
			}
			typeList.appendChild( h( 'h2', { class: 'okno-group-label', text: group.title } ) );
			typeList.appendChild( h( 'ul', { class: 'okno-rows' }, rows ) );
		} );
		if ( ! types.length ) {
			typeList.appendChild( h( 'p', { class: 'okno-list-empty', text: __( 'No content type is editable in Okno yet. Choose them in the settings.', 'okno' ) } ) );
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
			return App.api( { path: '/okno/v1/posts?' + query.toString(), parse: false } )
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
			App.clear( list ).appendChild( h( 'li', { class: 'okno-list-empty', text: __( 'Nothing to show.', 'okno' ) } ) );
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

		return App.api( { path: '/okno/v1/posts?' + query.toString(), parse: false } )
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
				App.clear( list ).appendChild( h( 'li', { class: 'okno-list-empty', text: App.errorMessage( err, __( 'Couldn’t load the content list.', 'okno' ) ) } ) );
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
			_n( '%d entry', '%d entries', state.total, 'okno' ),
			state.total
		);

		if ( ! state.items.length ) {
			var t = currentType();
			list.appendChild(
				h( 'li', { class: 'okno-list-empty' }, [
					h( 'p', { text: state.search || state.status ? __( 'Nothing matches these filters', 'okno' ) : __( 'No entries yet', 'okno' ) } ),
					t && t.canCreate && ! t.singleton && ! state.search
						? h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', onClick: function () {
							openCreate( t.name );
						} }, __( 'Create an entry', 'okno' ) )
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

	function row( item ) {
		var type = typeIndex[ item.type ];
		var trashed = 'trash' === item.status;
		var title = item.title || __( '(no title)', 'okno' );
		var statusText = STATUSES[ item.status ] || item.status;

		var actions = h( 'div', { class: 'okno-item__actions' } );
		if ( trashed ) {
			if ( item.can_delete ) {
				actions.appendChild( actionBtn( 'restore', __( 'Restore', 'okno' ), title, function () {
					restore( item );
				} ) );
			}
		} else {
			if ( canUseEditor( item ) ) {
				actions.appendChild( actionLink( 'edit', __( 'Visual editor', 'okno' ), title, editorUrl( item ) ) );
			}
			if ( 'publish' === item.status ) {
				if ( ! type || ! type.singleton ) {
					actions.appendChild( actionBtn( 'draft', __( 'Back to draft', 'okno' ), title, function () {
						setStatus( item, 'draft' );
					} ) );
				}
			} else if ( 'future' !== item.status && 'private' !== item.status ) {
				var publishable = ! type || type.canPublish;
				actions.appendChild( actionBtn( 'check', publishable ? __( 'Mark as published', 'okno' ) : __( 'Submit for review', 'okno' ), title, function () {
					setStatus( item, publishable ? 'publish' : 'pending' );
				} ) );
			}
			if ( type && type.canCreate && ! type.singleton ) {
				actions.appendChild( actionBtn( 'copy', __( 'Duplicate', 'okno' ), title, function () {
					duplicate( item );
				} ) );
			}
			if ( item.can_delete ) {
				actions.appendChild( actionBtn( 'trash', __( 'Move to trash', 'okno' ), title, function () {
					trash( item );
				} ) );
			}
		}

		var active = state.current && item.id === state.current;
		/* translators: 1: content title, 2: status (e.g. Draft), 3: content type. */
		var label = sprintf( __( '%1$s — %2$s, %3$s', 'okno' ), title, statusText, type ? type.singular : item.type );
		var main = [
			h( 'span', { class: 'okno-status-dot okno-status-dot--' + statusLevel( item.status ), title: statusText } ),
			h( 'span', { class: 'okno-item__title' + ( item.title ? '' : ' is-blank' ), text: title } ),
		];

		return h( 'li', { class: 'okno-item' + ( active ? ' is-active' : '' ), dataset: { id: String( item.id ) } }, [
			trashed
				? h( 'span', { class: 'okno-item__link', 'aria-label': label }, main )
				: h( 'a', {
					class: 'okno-item__link',
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
			h( 'time', { class: 'okno-item__meta', datetime: item.modified || '', title: item.modified ? new Date( item.modified ).toLocaleString() : '', text: App.formatDate( item.modified ) } ),
			actions,
		] );
	}

	function markActive() {
		Array.prototype.forEach.call( list.querySelectorAll( '.okno-item' ), function ( li ) {
			var on = Number( li.dataset.id ) === state.current;
			li.classList.toggle( 'is-active', on );
			var link = li.querySelector( 'a.okno-item__link' );
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
		var aria = sprintf( __( '%1$s: %2$s', 'okno' ), label, title );
		return h( 'a', { class: 'okno-icon-btn', href: href, title: label, 'aria-label': aria }, icon( iconName ) );
	}

	function actionBtn( iconName, label, title, onClick ) {
		/* translators: 1: action (e.g. Duplicate), 2: content title. */
		var aria = sprintf( __( '%1$s: %2$s', 'okno' ), label, title );
		return h( 'button', { type: 'button', class: 'okno-icon-btn' + ( 'trash' === iconName ? ' okno-icon-btn--danger' : '' ), title: label, 'aria-label': aria, onClick: onClick }, icon( iconName ) );
	}

	function renderPager() {
		App.clear( pager );
		if ( state.totalPages <= 1 ) {
			return;
		}
		App.append( pager, [
			h( 'button', { type: 'button', class: 'okno-icon-btn okno-icon-btn--small', 'aria-label': __( 'Previous', 'okno' ), title: __( 'Previous', 'okno' ), disabled: state.page <= 1, onClick: function () {
				state.page -= 1;
				load();
			} }, icon( 'up' ) ),
			h( 'span', {}, sprintf(
				/* translators: 1: current page, 2: number of pages. */
				__( 'Page %1$d of %2$d', 'okno' ),
				state.page,
				state.totalPages
			) ),
			h( 'button', { type: 'button', class: 'okno-icon-btn okno-icon-btn--small', 'aria-label': __( 'Next', 'okno' ), title: __( 'Next', 'okno' ), disabled: state.page >= state.totalPages, onClick: function () {
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
				App.toast( App.errorMessage( err, __( 'Couldn’t open this entry.', 'okno' ) ), true );
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
	document.addEventListener( 'okno:entry-changed', function () {
		App.notifyChanged();
		reload();
	} );

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	function setStatus( item, status ) {
		App.api( { path: '/okno/v1/posts/' + item.id + '/status', method: 'POST', data: { status: status } } )
			.then( function ( fresh ) {
				/* translators: 1: content title, 2: status (e.g. Published). */
				App.toast( sprintf( __( '“%1$s”: %2$s.', 'okno' ), fresh.title, STATUSES[ fresh.status ] || fresh.status ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t change the status.', 'okno' ) ), true );
			} );
	}

	function duplicate( item ) {
		App.api( { path: '/okno/v1/posts/' + item.id + '/duplicate', method: 'POST' } )
			.then( function ( copy ) {
				/* translators: %s: content title. */
				App.toast( sprintf( __( '“%s” created as a draft.', 'okno' ), copy.title ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t duplicate this content.', 'okno' ) ), true );
			} );
	}

	function trash( item ) {
		App.confirm( {
			title: __( 'Move to trash?', 'okno' ),
			/* translators: %s: content title. */
			message: sprintf( __( '“%s” will be moved to the trash. You can restore it from the Trash filter.', 'okno' ), item.title ),
			confirmLabel: __( 'Move to trash', 'okno' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/okno/v1/posts/' + item.id, method: 'DELETE' } )
				.then( function () {
					/* translators: %s: content title. */
					App.toast( sprintf( __( '“%s” moved to the trash.', 'okno' ), item.title ) );
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
					App.toast( App.errorMessage( err, __( 'Couldn’t delete this content.', 'okno' ) ), true );
				} );
		} );
	}

	function restore( item ) {
		App.api( { path: '/okno/v1/posts/' + item.id + '/restore', method: 'POST' } )
			.then( function () {
				/* translators: %s: content title. */
				App.toast( sprintf( __( '“%s” restored as a draft.', 'okno' ), item.title ) );
				App.notifyChanged();
				return reload();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t restore this content.', 'okno' ) ), true );
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
		var title = h( 'input', { type: 'text', id: titleId, class: 'okno-input', required: true, autofocus: true } );
		var typeSelect = fixed
			? null
			: h( 'select', { id: typeFieldId, class: 'okno-input' }, creatable.map( function ( t ) {
				return h( 'option', { value: t.name, text: t.singular, selected: t.name === state.type } );
			} ) );
		var form = h( 'form', { class: 'okno-form-grid', onSubmit: function ( event ) {
			event.preventDefault();
			submit();
		} }, [
			typeSelect ? h( 'label', { for: typeFieldId, class: 'okno-label', text: __( 'Type', 'okno' ) } ) : null,
			typeSelect,
			h( 'label', { for: titleId, class: 'okno-label', text: __( 'Title', 'okno' ) } ),
			title,
			h( 'p', { class: 'okno-help', text: __( 'Created as a draft: nothing is visible on the site until you publish it.', 'okno' ) } ),
			h( 'button', { type: 'submit', hidden: true } ),
		] );

		var dialog = App.modal( {
			title: fixed
				? /* translators: %s: content type (e.g. Services). */
				  sprintf( __( 'New entry in %s', 'okno' ), fixed.label )
				: __( 'New content', 'okno' ),
			body: form,
			actions: [
				{ label: __( 'Cancel', 'okno' ) },
				{ label: __( 'Create and edit', 'okno' ), primary: true, onClick: function () {
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
				App.api( { path: '/okno/v1/posts', method: 'POST', data: { post_type: postType, title: title.value.trim() } } )
					.then( function ( post ) {
						dialog.close();
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
					} )
					.catch( function ( err ) {
						busy = false;
						App.toast( App.errorMessage( err, __( 'Couldn’t create this content.', 'okno' ) ), true );
					} );
			} );
		}
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
				App.toast( __( 'This content no longer exists or you’re not allowed to edit it.', 'okno' ), true );
			} );
	}

	if ( '1' === params.get( 'new' ) ) {
		openCreate( typeIndex[ params.get( 'type' ) ] ? params.get( 'type' ) : '' );
	}
} )();
