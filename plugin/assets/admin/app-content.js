/**
 * Okno — vue Contenus : tous les contenus éditables (pages, articles, types
 * ACF, collections), en trois volets : types de contenu (avec leur nombre
 * d'entrées), entrées du type choisi (recherche, statut, actions au survol),
 * détail. Création, duplication, corbeille et ouverture dans l'éditeur
 * visuel ou dans le formulaire.
 *
 * Le même script pose les deux premiers volets à gauche du formulaire
 * (vue form, #okno-form-browser) : l'entrée ouverte y est mise en évidence et
 * on passe d'une entrée à l'autre sans revenir à Contenus.
 *
 * Portions derived from Stacki (https://github.com/flowtricks/stacki),
 * Copyright (c) 2026 Timothy Ricks, MIT License — see licenses/stacki-MIT.txt.
 * (Organisation en volets collections / entrées / détail.)
 */
( function () {
	'use strict';

	var App = window.OknoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! App ) {
		return;
	}

	var browser = document.getElementById( 'okno-form-browser' );
	var inView = !! ( App.view && 'content' === App.view.dataset.view );
	if ( ! inView && ! browser ) {
		return;
	}

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

	var params = new URLSearchParams( window.location.search );
	var types = cfg.types || [];
	var typeIndex = {};
	types.forEach( function ( t ) {
		typeIndex[ t.name ] = t;
	} );

	var currentPost = browser ? Number( browser.dataset.post || 0 ) : 0;
	var initialType = browser ? browser.dataset.type : params.get( 'type' );

	var state = {
		type: typeIndex[ initialType ] ? initialType : '',
		status: ! browser && STATUSES[ params.get( 'status' ) ] ? params.get( 'status' ) : '',
		search: browser ? '' : params.get( 's' ) || '',
		page: 1,
		totalPages: 1,
		total: 0,
		items: [],
		counts: {},
		request: 0,
	};

	/* ------------------------------------------------------------------ *
	 * URLs d'édition
	 * ------------------------------------------------------------------ */

	function canUseEditor( item ) {
		var type = typeIndex[ item.type ];
		return !! ( cfg.frontUrl && item.preview_url && ( ! type || type.hasPages ) );
	}

	function editorUrl( item ) {
		return cfg.urls.editor + '&post=' + item.id;
	}

	function formUrl( item ) {
		return cfg.urls.form + '&post=' + item.id;
	}

	function defaultUrl( item ) {
		return canUseEditor( item ) ? editorUrl( item ) : formUrl( item );
	}

	/* ------------------------------------------------------------------ *
	 * Squelette : volets
	 * ------------------------------------------------------------------ */

	var searchId = App.uid( 'search' );
	var statusId = App.uid( 'status' );

	var creatable = types.filter( function ( t ) {
		return t.canCreate && ! t.singleton;
	} );

	function newButton( small ) {
		if ( ! creatable.length ) {
			return null;
		}
		return small
			? h( 'button', { type: 'button', class: 'okno-icon-btn', title: __( 'New content', 'okno' ), 'aria-label': __( 'New content', 'okno' ), onClick: function () {
				openCreate();
			} }, icon( 'plus' ) )
			: h( 'button', { type: 'button', class: 'okno-btn', onClick: function () {
				openCreate();
			} }, [ icon( 'plus' ), __( 'New content', 'okno' ) ] );
	}

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

	var typeList = h( 'div', { class: 'okno-pane__body' } );
	var navPane = h( 'nav', { class: 'okno-pane okno-pane--nav', 'aria-label': __( 'Content types', 'okno' ) }, [
		h( 'div', { class: 'okno-pane__head' }, [
			h( inView ? 'h1' : 'h2', { class: 'okno-pane__title', text: __( 'Content', 'okno' ) } ),
			h( 'div', { class: 'okno-pane__tools' }, newButton( true ) ),
		] ),
		typeList,
	] );

	var listTitle = h( 'h2', { class: 'okno-pane__title' } );
	var list = h( 'ul', { class: 'okno-rows okno-entries', 'aria-busy': 'true' } );
	var summary = h( 'span', { 'aria-live': 'polite' } );
	var pager = h( 'nav', { class: 'okno-pager', 'aria-label': __( 'Pagination', 'okno' ) } );
	var listPane = h( 'section', { class: 'okno-pane okno-pane--list', 'aria-label': __( 'Entries', 'okno' ) }, [
		h( 'div', { class: 'okno-pane__head' }, [ listTitle, h( 'div', { class: 'okno-pane__tools' }, newButton( true ) ) ] ),
		h( 'div', { class: 'okno-pane__search', role: 'search' }, [
			h( 'label', { for: searchId, class: 'okno-sr', text: __( 'Search', 'okno' ) } ),
			h( 'span', { class: 'okno-input-icon' }, icon( 'search', 14 ) ),
			searchInput,
			h( 'label', { for: statusId, class: 'okno-sr', text: __( 'Status', 'okno' ) } ),
			statusSelect,
		] ),
		h( 'div', { class: 'okno-pane__body' }, list ),
		h( 'div', { class: 'okno-pane__foot' }, [ summary, pager ] ),
	] );

	if ( inView ) {
		App.view.classList.add( 'okno-panes' );
		App.clear( App.view );
		App.append( App.view, [
			navPane,
			listPane,
			h( 'section', { class: 'okno-pane okno-pane--detail', 'aria-label': __( 'Details', 'okno' ) }, [
				h( 'div', { class: 'okno-detail__body' }, h( 'div', { class: 'okno-empty-state' }, [
					h( 'span', { class: 'okno-empty-state__icon' }, icon( 'list', 22 ) ),
					h( 'h3', { text: __( 'Select an entry', 'okno' ) } ),
					h( 'p', { text: __( 'Open an entry from the list to edit it. Pages shown on the site open in the visual editor, the others in a form.', 'okno' ) } ),
					newButton( false ),
				] ) ),
			] ),
		] );
	} else {
		App.append( App.clear( browser ), [ navPane, listPane ] );
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

	/* ------------------------------------------------------------------ *
	 * Types de contenu (volet de gauche)
	 * ------------------------------------------------------------------ */

	function typeIcon( t ) {
		if ( ! t ) {
			return icon( 'list', 15 );
		}
		return icon( t.collection ? ( t.singleton ? 'page' : 'database' ) : 'page', 15 );
	}

	function typeRow( t ) {
		var name = t ? t.name : '';
		var active = state.type === name;
		var count = t ? state.counts[ name ] : state.counts[ '' ];
		return h( 'li', {}, h( 'button', {
			type: 'button',
			class: 'okno-coll' + ( active ? ' is-active' : '' ),
			'aria-current': active ? 'true' : null,
			onClick: function () {
				if ( state.type === name ) {
					return;
				}
				state.type = name;
				state.page = 1;
				renderTypes();
				load();
			},
		}, [
			h( 'span', { class: 'okno-coll__icon' + ( t && t.collection ? ' okno-coll__icon--collection' : '' ) }, typeIcon( t ) ),
			h( 'span', { class: 'okno-coll__name', text: t ? t.label : __( 'All types', 'okno' ) } ),
			undefined === count ? null : h( 'span', { class: 'okno-coll__count', text: String( count ) } ),
		] ) );
	}

	function renderTypes() {
		App.clear( typeList );
		var groups = [
			{ title: null, items: [ null ] },
			{ title: __( 'Site content', 'okno' ), items: types.filter( function ( t ) {
				return ! t.collection;
			} ) },
			{ title: __( 'Collections', 'okno' ), items: types.filter( function ( t ) {
				return t.collection && ! t.singleton;
			} ) },
			{ title: __( 'Single pages', 'okno' ), items: types.filter( function ( t ) {
				return t.singleton;
			} ) },
		];
		groups.forEach( function ( group ) {
			if ( ! group.items.length ) {
				return;
			}
			if ( group.title ) {
				typeList.appendChild( h( 'h3', { class: 'okno-group-label', text: group.title } ) );
			}
			typeList.appendChild( h( 'ul', { class: 'okno-rows' }, group.items.map( typeRow ) ) );
		} );
		var current = typeIndex[ state.type ];
		listTitle.textContent = current ? current.label : __( 'All types', 'okno' );
	}

	/** Nombre d'entrées de chaque type (une requête légère par type). */
	function loadCounts() {
		var all = [ '' ].concat( types.map( function ( t ) {
			return t.name;
		} ) );
		return Promise.all( all.map( function ( name ) {
			var query = new URLSearchParams( { per_page: '1', page: '1', type: name } );
			return App.api( { path: '/okno/v1/posts?' + query.toString(), parse: false } )
				.then( function ( response ) {
					state.counts[ name ] = Number( response.headers.get( 'X-WP-Total' ) || 0 );
				} )
				.catch( function () {
					/* Le nombre manque, la ligne reste utilisable. */
				} );
		} ) ).then( renderTypes );
	}

	/* ------------------------------------------------------------------ *
	 * Chargement des entrées
	 * ------------------------------------------------------------------ */

	function syncUrl() {
		if ( ! inView ) {
			return;
		}
		var url = new URL( window.location.href );
		[ [ 'type', state.type ], [ 'status', state.status ], [ 's', state.search ] ].forEach( function ( pair ) {
			if ( pair[ 1 ] ) {
				url.searchParams.set( pair[ 0 ], pair[ 1 ] );
			} else {
				url.searchParams.delete( pair[ 0 ] );
			}
		} );
		url.searchParams.delete( 'new' );
		window.history.replaceState( null, '', url.toString() );
	}

	function load() {
		var request = ++state.request;
		list.setAttribute( 'aria-busy', 'true' );
		syncUrl();

		var query = new URLSearchParams( {
			per_page: String( PER_PAGE ),
			page: String( state.page ),
			search: state.search,
			type: state.type,
			status: state.status,
			orderby: 'modified',
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

	/* ------------------------------------------------------------------ *
	 * Rendu des entrées
	 * ------------------------------------------------------------------ */

	function render() {
		App.clear( list );
		summary.textContent = sprintf(
			/* translators: %d: number of content items. */
			_n( '%d content', '%d contents', state.total, 'okno' ),
			state.total
		);

		if ( ! state.items.length ) {
			list.appendChild(
				h( 'li', { class: 'okno-list-empty' }, [
					h( 'p', { text: state.search || state.type || state.status ? __( 'Nothing matches these filters', 'okno' ) : __( 'No content yet', 'okno' ) } ),
					creatable.length && ! state.search
						? h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', onClick: function () {
							openCreate();
						} }, __( 'Create content', 'okno' ) )
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
				actions.appendChild( actionLink( 'form', __( 'Form', 'okno' ), title, formUrl( item ) ) );
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

		var active = currentPost && item.id === currentPost;
		/* translators: 1: content title, 2: status (e.g. Draft), 3: content type. */
		var label = sprintf( __( '%1$s — %2$s, %3$s', 'okno' ), title, statusText, type ? type.singular : item.type );
		var main = [
			h( 'span', { class: 'okno-status-dot okno-status-dot--' + statusLevel( item.status ), title: statusText } ),
			h( 'span', { class: 'okno-item__title' + ( item.title ? '' : ' is-blank' ), text: title } ),
		];

		return h( 'li', { class: 'okno-item' + ( active ? ' is-active' : '' ), dataset: { id: String( item.id ) } }, [
			trashed
				? h( 'span', { class: 'okno-item__link', 'aria-label': label }, main )
				: h( 'a', { class: 'okno-item__link', href: defaultUrl( item ), 'aria-label': label, 'aria-current': active ? 'page' : null }, main ),
			h( 'time', { class: 'okno-item__meta', datetime: item.modified || '', title: item.modified ? new Date( item.modified ).toLocaleString() : '', text: App.formatDate( item.modified ) } ),
			actions,
		] );
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

	function openCreate() {
		var titleId = App.uid( 'new-title' );
		var typeFieldId = App.uid( 'new-type' );
		var title = h( 'input', { type: 'text', id: titleId, class: 'okno-input', required: true, autofocus: true } );
		var preset = typeIndex[ state.type ] && typeIndex[ state.type ].canCreate && ! typeIndex[ state.type ].singleton ? state.type : '';
		var type = h(
			'select',
			{ id: typeFieldId, class: 'okno-input' },
			creatable.map( function ( t ) {
				return h( 'option', { value: t.name, text: t.singular, selected: t.name === preset } );
			} )
		);
		var form = h( 'form', { class: 'okno-form-grid', onSubmit: function ( event ) {
			event.preventDefault();
			submit();
		} }, [
			h( 'label', { for: typeFieldId, class: 'okno-label', text: __( 'Type', 'okno' ) } ),
			type,
			h( 'label', { for: titleId, class: 'okno-label', text: __( 'Title', 'okno' ) } ),
			title,
			h( 'p', { class: 'okno-help', text: __( 'Created as a draft: nothing is visible on the site until you publish it.', 'okno' ) } ),
			h( 'button', { type: 'submit', hidden: true } ),
		] );

		var dialog = App.modal( {
			title: __( 'New content', 'okno' ),
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
			busy = true;
			App.api( { path: '/okno/v1/posts', method: 'POST', data: { post_type: type.value, title: title.value.trim() } } )
				.then( function ( post ) {
					dialog.close();
					window.location.href = defaultUrl( post );
				} )
				.catch( function ( err ) {
					busy = false;
					App.toast( App.errorMessage( err, __( 'Couldn’t create this content.', 'okno' ) ), true );
				} );
		}
	}

	renderTypes();
	loadCounts();
	load();
	if ( inView && '1' === params.get( 'new' ) && creatable.length ) {
		openCreate();
	}
} )();
