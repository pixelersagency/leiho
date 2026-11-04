/**
 * Okno — vue Médias : la médiathèque WordPress (API REST wp/v2/media) en
 * grille, avec recherche, filtre par type, envoi de fichiers (bouton ou
 * glisser-déposer) et panneau de détails (texte alternatif, titre, lien).
 */
( function () {
	'use strict';

	var App = window.OknoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! App || ! App.view || 'media' !== App.view.dataset.view ) {
		return;
	}

	var h = App.h, icon = App.icon;
	var PER_PAGE = 40;

	var state = { items: [], page: 1, totalPages: 1, total: 0, search: '', kind: '', request: 0, selected: null };

	var searchId = App.uid( 'msearch' );
	var kindId = App.uid( 'mkind' );
	var fileInput = h( 'input', { type: 'file', multiple: true, hidden: true } );
	var searchInput = h( 'input', { type: 'search', id: searchId, class: 'okno-input', placeholder: __( 'Search media…', 'okno' ) } );
	var kindSelect = h( 'select', { id: kindId, class: 'okno-input' }, [
		h( 'option', { value: '', text: __( 'All media', 'okno' ) } ),
		h( 'option', { value: 'image', text: __( 'Images', 'okno' ) } ),
		h( 'option', { value: 'video', text: __( 'Videos', 'okno' ) } ),
		h( 'option', { value: 'audio', text: __( 'Audio', 'okno' ) } ),
		h( 'option', { value: 'application', text: __( 'Documents', 'okno' ) } ),
	] );
	var summary = h( 'span', { 'aria-live': 'polite' } );
	var grid = h( 'ul', { class: 'okno-media-grid', 'aria-label': __( 'Media library', 'okno' ) } );
	var more = h( 'div', { class: 'okno-pager' } );
	var details = h( 'aside', { class: 'okno-pane okno-pane--aside okno-media-details', 'aria-label': __( 'Media details', 'okno' ) } );
	var dropzone = h( 'div', { class: 'okno-media-drop', hidden: true }, [ icon( 'upload', 28 ), h( 'p', { text: __( 'Drop files to upload them', 'okno' ) } ) ] );
	var progress = h( 'p', { class: 'okno-media-progress', role: 'status', 'aria-live': 'polite' } );

	App.view.classList.add( 'okno-panes' );
	App.clear( App.view );
	App.append( App.view, [
		h( 'section', { class: 'okno-pane okno-media-main', 'aria-label': __( 'Media', 'okno' ) }, [
			h( 'div', { class: 'okno-pane__head' }, [
				h( 'h1', { class: 'okno-pane__title', title: __( 'Images and files of the site. Drop files anywhere on this page to upload them.', 'okno' ), text: __( 'Media', 'okno' ) } ),
				h( 'div', { class: 'okno-pane__tools' }, [
					App.cfg.canUpload
						? h( 'button', { type: 'button', class: 'okno-btn okno-btn--small', onClick: function () {
							fileInput.click();
						} }, [ icon( 'upload' ), __( 'Upload', 'okno' ) ] )
						: null,
					fileInput,
				] ),
			] ),
			h( 'div', { class: 'okno-pane__search okno-media-filters', role: 'search' }, [
				h( 'label', { for: searchId, class: 'okno-sr', text: __( 'Search', 'okno' ) } ),
				h( 'span', { class: 'okno-input-icon' }, icon( 'search', 14 ) ),
				searchInput,
				h( 'label', { for: kindId, class: 'okno-sr', text: __( 'Type', 'okno' ) } ),
				kindSelect,
			] ),
			progress,
			h( 'div', { class: 'okno-pane__body okno-media__main' }, [ grid, more ] ),
			h( 'div', { class: 'okno-pane__foot' }, summary ),
		] ),
		details,
		dropzone,
	] );

	var timer = null;
	searchInput.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			state.search = searchInput.value.trim();
			load( false );
		}, 300 );
	} );
	kindSelect.addEventListener( 'change', function () {
		state.kind = kindSelect.value;
		load( false );
	} );

	/* ------------------------------------------------------------------ *
	 * Liste
	 * ------------------------------------------------------------------ */

	function load( append ) {
		var request = ++state.request;
		var page = append ? state.page + 1 : 1;
		var query = new URLSearchParams( {
			per_page: String( PER_PAGE ),
			page: String( page ),
			orderby: 'date',
			order: 'desc',
			_fields: 'id,date_gmt,title,alt_text,caption,mime_type,media_type,source_url,media_details,link',
		} );
		if ( state.search ) {
			query.set( 'search', state.search );
		}
		if ( state.kind ) {
			query.set( 'media_type', state.kind );
		}
		grid.setAttribute( 'aria-busy', 'true' );
		return App.api( { path: '/wp/v2/media?' + query.toString(), parse: false } )
			.then( function ( response ) {
				state.totalPages = Number( response.headers.get( 'X-WP-TotalPages' ) || 1 );
				state.total = Number( response.headers.get( 'X-WP-Total' ) || 0 );
				return response.json();
			} )
			.then( function ( items ) {
				if ( request !== state.request ) {
					return;
				}
				state.page = page;
				state.items = append ? state.items.concat( items ) : items;
				render();
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err, __( 'Couldn’t load the media library.', 'okno' ) ), true );
			} )
			.finally( function () {
				grid.setAttribute( 'aria-busy', 'false' );
			} );
	}

	function thumb( item ) {
		var sizes = ( item.media_details && item.media_details.sizes ) || {};
		var size = sizes.medium || sizes.thumbnail || null;
		return 'image' === item.media_type ? ( size ? size.source_url : item.source_url ) : '';
	}

	function title( item ) {
		var t = item.title && ( item.title.rendered || item.title.raw );
		// DOMParser : un document inerte (ni script ni chargement d'image).
		var text = t ? new window.DOMParser().parseFromString( t, 'text/html' ).body.textContent : '';
		return text || __( '(no title)', 'okno' );
	}

	function render() {
		App.clear( grid );
		/* translators: %d: number of media items. */
		summary.textContent = sprintf( _n( '%d item', '%d items', state.total, 'okno' ), state.total );
		if ( ! state.items.length ) {
			grid.appendChild( h( 'li', { class: 'okno-media-empty' }, [ icon( 'image', 28 ), h( 'p', { text: __( 'No media found.', 'okno' ) } ) ] ) );
		}
		state.items.forEach( function ( item ) {
			var src = thumb( item );
			var name = title( item );
			var selected = state.selected && state.selected.id === item.id;
			grid.appendChild(
				h( 'li', {}, h( 'button', {
					type: 'button',
					class: 'okno-media-tile' + ( selected ? ' is-selected' : '' ),
					'aria-pressed': selected ? 'true' : 'false',
					title: name,
					onClick: function () {
						select( item );
					},
				}, [
					src
						? h( 'img', { src: src, alt: item.alt_text || '', loading: 'lazy' } )
						: h( 'span', { class: 'okno-media-file' }, [ icon( 'page', 28 ), h( 'span', { text: ( item.mime_type || '' ).split( '/' ).pop() } ) ] ),
					h( 'span', { class: 'okno-media-tile__name', text: name } ),
				] ) )
			);
		} );
		App.clear( more );
		if ( state.page < state.totalPages ) {
			more.appendChild( h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', onClick: function () {
				load( true );
			} }, __( 'Load more', 'okno' ) ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Détails
	 * ------------------------------------------------------------------ */

	function select( item ) {
		state.selected = item;
		render();
		renderDetails();
	}

	function renderDetails() {
		var item = state.selected;
		App.clear( details );
		if ( ! item ) {
			details.appendChild( h( 'div', { class: 'okno-empty-state' }, [
				h( 'span', { class: 'okno-empty-state__icon' }, icon( 'image', 22 ) ),
				h( 'p', { text: __( 'Images and files of the site. Drop files anywhere on this page to upload them.', 'okno' ) } ),
			] ) );
			return;
		}
		var altId = App.uid( 'alt' );
		var titleId = App.uid( 'mtitle' );
		var urlId = App.uid( 'murl' );
		var src = thumb( item );
		var alt = h( 'textarea', { id: altId, class: 'okno-input', rows: '3' } );
		alt.value = item.alt_text || '';
		var name = h( 'input', { type: 'text', id: titleId, class: 'okno-input', value: title( item ) } );
		var url = h( 'input', { type: 'text', id: urlId, class: 'okno-input okno-mono', value: item.source_url, readOnly: true } );
		var md = item.media_details || {};

		var save = h( 'button', { type: 'button', class: 'okno-btn', onClick: function () {
			save.disabled = true;
			App.api( { path: '/wp/v2/media/' + item.id, method: 'POST', data: { alt_text: alt.value, title: name.value } } )
				.then( function ( fresh ) {
					Object.assign( item, { alt_text: fresh.alt_text, title: fresh.title } );
					App.toast( __( 'Media saved.', 'okno' ) );
					render();
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} )
				.finally( function () {
					save.disabled = false;
				} );
		} }, __( 'Save', 'okno' ) );

		App.append( details, [
			h( 'div', { class: 'okno-pane__head okno-media-details__head' }, [
				h( 'h2', { class: 'okno-pane__title', text: __( 'Details', 'okno' ) } ),
				h( 'button', { type: 'button', class: 'okno-icon-btn', 'aria-label': __( 'Close the details', 'okno' ), onClick: function () {
					state.selected = null;
					render();
					renderDetails();
				} }, icon( 'close' ) ),
			] ),
			h( 'div', { class: 'okno-media-details__body' }, [
			src ? h( 'img', { class: 'okno-media-details__preview', src: item.source_url, alt: '' } ) : null,
			h( 'p', { class: 'okno-media-details__meta' }, [
				( item.mime_type || '' ),
				md.width ? ' · ' + md.width + ' × ' + md.height : '',
				md.filesize ? ' · ' + Math.round( md.filesize / 1024 ) + ' KB' : '',
			] ),
			'image' === item.media_type
				? h( 'div', { class: 'okno-setting' }, [
					h( 'label', { class: 'okno-label', for: altId, text: __( 'Alternative text', 'okno' ) } ),
					alt,
					h( 'p', { class: 'okno-help', text: __( 'Describes the image for people who can’t see it, and for search engines.', 'okno' ) } ),
				] )
				: null,
			h( 'div', { class: 'okno-setting' }, [ h( 'label', { class: 'okno-label', for: titleId, text: __( 'Title', 'okno' ) } ), name ] ),
			h( 'div', { class: 'okno-setting' }, [
				h( 'label', { class: 'okno-label', for: urlId, text: __( 'File URL', 'okno' ) } ),
				url,
				h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', onClick: function () {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( item.source_url ).then( function () {
							App.toast( __( 'Copied', 'okno' ) );
						} );
					} else {
						url.select();
					}
				} }, __( 'Copy', 'okno' ) ),
			] ),
			h( 'div', { class: 'okno-actions' }, [
				save,
				h( 'a', { class: 'okno-btn okno-btn--quiet', href: item.source_url, target: '_blank', rel: 'noopener' }, [ __( 'Open', 'okno' ), icon( 'external', 14 ) ] ),
				h( 'button', { type: 'button', class: 'okno-btn okno-btn--danger', onClick: function () {
					remove( item );
				} }, __( 'Delete', 'okno' ) ),
			] ),
			] ),
		] );
	}

	function remove( item ) {
		App.confirm( {
			/* translators: %s: media title. */
			title: sprintf( __( 'Delete “%s”?', 'okno' ), title( item ) ),
			message: __( 'The file is deleted for good (media have no trash). Pages that use it will show an empty spot.', 'okno' ),
			confirmLabel: __( 'Delete', 'okno' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/wp/v2/media/' + item.id + '?force=true', method: 'DELETE' } )
				.then( function () {
					App.toast( __( 'Media deleted.', 'okno' ) );
					state.selected = null;
					renderDetails();
					return load( false );
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Envoi
	 * ------------------------------------------------------------------ */

	function upload( files ) {
		files = Array.prototype.slice.call( files || [] );
		if ( ! files.length || ! App.cfg.canUpload ) {
			return;
		}
		var done = 0;
		var failed = 0;
		function step() {
			/* translators: 1: number of files sent, 2: total number of files. */
			progress.textContent = sprintf( __( 'Uploading %1$d / %2$d…', 'okno' ), done + 1, files.length );
			var file = files[ done ];
			var body = new FormData();
			body.append( 'file', file, file.name );
			return App.api( { path: '/wp/v2/media', method: 'POST', body: body } )
				.catch( function ( err ) {
					failed += 1;
					/* translators: 1: file name, 2: error message. */
					App.toast( sprintf( __( '%1$s: %2$s', 'okno' ), file.name, App.errorMessage( err ) ), true );
				} )
				.then( function () {
					done += 1;
					if ( done < files.length ) {
						return step();
					}
				} );
		}
		step().then( function () {
			progress.textContent = '';
			var ok = files.length - failed;
			if ( ok ) {
				/* translators: %d: number of files. */
				App.toast( sprintf( _n( '%d file uploaded.', '%d files uploaded.', ok, 'okno' ), ok ) );
			}
			load( false );
		} );
	}

	fileInput.addEventListener( 'change', function () {
		upload( fileInput.files );
		fileInput.value = '';
	} );

	var dragDepth = 0;
	App.root.addEventListener( 'dragenter', function ( event ) {
		if ( ! App.cfg.canUpload || ! event.dataTransfer || Array.prototype.indexOf.call( event.dataTransfer.types || [], 'Files' ) === -1 ) {
			return;
		}
		dragDepth += 1;
		dropzone.hidden = false;
	} );
	App.root.addEventListener( 'dragleave', function () {
		dragDepth = Math.max( 0, dragDepth - 1 );
		if ( ! dragDepth ) {
			dropzone.hidden = true;
		}
	} );
	App.root.addEventListener( 'dragover', function ( event ) {
		if ( ! dropzone.hidden ) {
			event.preventDefault();
		}
	} );
	App.root.addEventListener( 'drop', function ( event ) {
		if ( dropzone.hidden ) {
			return;
		}
		event.preventDefault();
		dragDepth = 0;
		dropzone.hidden = true;
		upload( event.dataTransfer.files );
	} );

	renderDetails();
	load( false );
} )();
