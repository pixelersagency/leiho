/**
 * Okno — vue Contenus : tous les contenus éditables (pages, articles, types
 * ACF, collections), avec recherche, filtres par type et par statut,
 * création, duplication, corbeille et ouverture dans l'éditeur visuel ou
 * dans le formulaire.
 */
( function () {
	'use strict';

	var App = window.OknoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! App || ! App.view || 'content' !== App.view.dataset.view ) {
		return;
	}

	var h = App.h, icon = App.icon, cfg = App.cfg;
	var PER_PAGE = 30;

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

	var state = {
		type: typeIndex[ params.get( 'type' ) ] ? params.get( 'type' ) : '',
		status: STATUSES[ params.get( 'status' ) ] ? params.get( 'status' ) : '',
		search: params.get( 's' ) || '',
		page: 1,
		totalPages: 1,
		total: 0,
		items: [],
		loading: false,
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
	 * Squelette
	 * ------------------------------------------------------------------ */

	var searchId = App.uid( 'search' );
	var typeId = App.uid( 'type' );
	var statusId = App.uid( 'status' );

	var creatable = types.filter( function ( t ) {
		return t.canCreate && ! t.singleton;
	} );

	var newBtn = creatable.length
		? h( 'button', { type: 'button', class: 'okno-btn', onClick: function () {
			openCreate();
		} }, [ icon( 'plus' ), __( 'New content', 'okno' ) ] )
		: null;

	var searchInput = h( 'input', {
		type: 'search',
		id: searchId,
		class: 'okno-input',
		value: state.search,
		placeholder: __( 'Search by title…', 'okno' ),
	} );
	var typeSelect = h(
		'select',
		{ id: typeId, class: 'okno-input' },
		[ h( 'option', { value: '', text: __( 'All types', 'okno' ) } ) ].concat(
			types.map( function ( t ) {
				return h( 'option', { value: t.name, text: t.label, selected: t.name === state.type } );
			} )
		)
	);
	var statusSelect = h(
		'select',
		{ id: statusId, class: 'okno-input' },
		[ h( 'option', { value: '', text: __( 'All statuses', 'okno' ) } ) ].concat(
			Object.keys( STATUSES ).map( function ( key ) {
				return h( 'option', { value: key, text: STATUSES[ key ], selected: key === state.status } );
			} )
		)
	);

	var summary = h( 'p', { class: 'okno-muted okno-list-summary', 'aria-live': 'polite' } );
	var list = h( 'div', { class: 'okno-card okno-card--flush okno-entries', 'aria-busy': 'true' } );
	var pager = h( 'nav', { class: 'okno-pager', 'aria-label': __( 'Pagination', 'okno' ) } );

	App.clear( App.view );
	App.append( App.view, [
		h( 'div', { class: 'okno-view-head' }, [
			h( 'div', {}, [
				h( 'h1', { class: 'okno-view-title', text: __( 'Content', 'okno' ) } ),
				h( 'p', { class: 'okno-muted', text: __( 'Every page, article and collection entry you can edit.', 'okno' ) } ),
			] ),
			newBtn,
		] ),
		h( 'div', { class: 'okno-filters', role: 'search' }, [
			h( 'div', { class: 'okno-filter okno-filter--grow' }, [
				h( 'label', { for: searchId, class: 'okno-sr', text: __( 'Search', 'okno' ) } ),
				h( 'span', { class: 'okno-input-icon' }, icon( 'search' ) ),
				searchInput,
			] ),
			h( 'div', { class: 'okno-filter' }, [ h( 'label', { for: typeId, class: 'okno-sr', text: __( 'Type', 'okno' ) } ), typeSelect ] ),
			h( 'div', { class: 'okno-filter' }, [ h( 'label', { for: statusId, class: 'okno-sr', text: __( 'Status', 'okno' ) } ), statusSelect ] ),
		] ),
		summary,
		list,
		pager,
	] );

	var searchTimer = null;
	searchInput.addEventListener( 'input', function () {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			state.search = searchInput.value.trim();
			state.page = 1;
			load();
		}, 300 );
	} );
	typeSelect.addEventListener( 'change', function () {
		state.type = typeSelect.value;
		state.page = 1;
		load();
	} );
	statusSelect.addEventListener( 'change', function () {
		state.status = statusSelect.value;
		state.page = 1;
		load();
	} );

	/* ------------------------------------------------------------------ *
	 * Chargement
	 * ------------------------------------------------------------------ */

	function syncUrl() {
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
		state.loading = true;
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
				App.clear( list ).appendChild( h( 'p', { class: 'okno-empty-line', text: App.errorMessage( err, __( 'Couldn’t load the content list.', 'okno' ) ) } ) );
			} )
			.finally( function () {
				if ( request === state.request ) {
					state.loading = false;
					list.setAttribute( 'aria-busy', 'false' );
				}
			} );
	}

	/* ------------------------------------------------------------------ *
	 * Rendu
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
				h( 'div', { class: 'okno-empty' }, [
					icon( 'page', 28 ),
					h( 'h3', { text: state.search || state.type || state.status ? __( 'Nothing matches these filters', 'okno' ) : __( 'No content yet', 'okno' ) } ),
					newBtn && ! state.search
						? h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet', onClick: function () {
							openCreate();
						} }, __( 'Create content', 'okno' ) )
						: null,
				] )
			);
			renderPager();
			return;
		}

		var table = h( 'table', { class: 'okno-table okno-table--entries' }, [
			h( 'thead', {}, h( 'tr', {}, [
				h( 'th', { scope: 'col', text: __( 'Title', 'okno' ) } ),
				h( 'th', { scope: 'col', text: __( 'Type', 'okno' ) } ),
				h( 'th', { scope: 'col', text: __( 'Status', 'okno' ) } ),
				h( 'th', { scope: 'col', text: __( 'Modified', 'okno' ) } ),
				h( 'th', { scope: 'col', class: 'okno-col-actions' }, h( 'span', { class: 'okno-sr', text: __( 'Actions', 'okno' ) } ) ),
			] ) ),
			h( 'tbody', {}, state.items.map( row ) ),
		] );
		list.appendChild( table );
		renderPager();
	}

	function row( item ) {
		var type = typeIndex[ item.type ];
		var trashed = 'trash' === item.status;
		var title = item.title || __( '(no title)', 'okno' );

		var actions = h( 'div', { class: 'okno-row-actions' } );
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
			actions.appendChild( actionLink( 'form', __( 'Form', 'okno' ), title, formUrl( item ) ) );
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

		return h( 'tr', { dataset: { id: String( item.id ) } }, [
			h( 'td', { class: 'okno-cell-title', 'data-label': __( 'Title', 'okno' ) }, [
				trashed ? h( 'span', { text: title } ) : h( 'a', { href: defaultUrl( item ), text: title } ),
				type && type.singleton ? h( 'span', { class: 'okno-pill', text: __( 'Single', 'okno' ) } ) : null,
			] ),
			h( 'td', { 'data-label': __( 'Type', 'okno' ), text: type ? type.singular : item.type } ),
			h( 'td', { 'data-label': __( 'Status', 'okno' ) }, h( 'span', { class: 'okno-pill okno-pill--' + statusLevel( item.status ), text: STATUSES[ item.status ] || item.status } ) ),
			h( 'td', { 'data-label': __( 'Modified', 'okno' ) }, h( 'time', { datetime: item.modified || '', title: item.modified ? new Date( item.modified ).toLocaleString() : '', text: App.formatDate( item.modified ) } ) ),
			h( 'td', { class: 'okno-col-actions' }, actions ),
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
		return h( 'button', { type: 'button', class: 'okno-icon-btn', title: label, 'aria-label': aria, onClick: onClick }, icon( iconName ) );
	}

	function renderPager() {
		App.clear( pager );
		if ( state.totalPages <= 1 ) {
			return;
		}
		App.append( pager, [
			h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', disabled: state.page <= 1, onClick: function () {
				state.page -= 1;
				load();
			} }, __( 'Previous', 'okno' ) ),
			h( 'span', { class: 'okno-muted' }, sprintf(
				/* translators: 1: current page, 2: number of pages. */
				__( 'Page %1$d of %2$d', 'okno' ),
				state.page,
				state.totalPages
			) ),
			h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', disabled: state.page >= state.totalPages, onClick: function () {
				state.page += 1;
				load();
			} }, __( 'Next', 'okno' ) ),
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
				return load();
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
				return load();
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
					return load();
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
				return load();
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

	load();
	if ( '1' === params.get( 'new' ) && creatable.length ) {
		openCreate();
	}
} )();
