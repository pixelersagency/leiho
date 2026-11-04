/**
 * Leiho — vue Médias : la médiathèque WordPress (API REST wp/v2/media) en
 * grille, avec recherche, filtre par type, envoi de fichiers (bouton ou
 * glisser-déposer) et panneau de détails (texte alternatif, titre, lien).
 */
( function () {
	'use strict';

	var App = window.LeihoApp;
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
	var searchInput = h( 'input', { type: 'search', id: searchId, class: 'leiho-input', placeholder: __( 'Search media…', 'leiho' ) } );
	var kindSelect = h( 'select', { id: kindId, class: 'leiho-input' }, [
		h( 'option', { value: '', text: __( 'All media', 'leiho' ) } ),
		h( 'option', { value: 'image', text: __( 'Images', 'leiho' ) } ),
		h( 'option', { value: 'video', text: __( 'Videos', 'leiho' ) } ),
		h( 'option', { value: 'audio', text: __( 'Audio', 'leiho' ) } ),
		h( 'option', { value: 'application', text: __( 'Documents', 'leiho' ) } ),
	] );
	var summary = h( 'span', { 'aria-live': 'polite' } );
	var grid = h( 'ul', { class: 'leiho-media-grid', 'aria-label': __( 'Media library', 'leiho' ) } );
	var more = h( 'div', { class: 'leiho-pager' } );
	var details = h( 'aside', { class: 'leiho-pane leiho-pane--aside leiho-media-details', 'aria-label': __( 'Media details', 'leiho' ) } );
	var dropzone = h( 'div', { class: 'leiho-media-drop', hidden: true }, [ icon( 'upload', 28 ), h( 'p', { text: __( 'Drop files to upload them', 'leiho' ) } ) ] );
	var progress = h( 'p', { class: 'leiho-media-progress', role: 'status', 'aria-live': 'polite' } );

	App.view.classList.add( 'leiho-panes' );
	App.clear( App.view );
	App.append( App.view, [
		h( 'section', { class: 'leiho-pane leiho-media-main', 'aria-label': __( 'Media', 'leiho' ) }, [
			h( 'div', { class: 'leiho-pane__head' }, [
				h( 'h1', { class: 'leiho-pane__title', title: __( 'Images and files of the site. Drop files anywhere on this page to upload them.', 'leiho' ), text: __( 'Media', 'leiho' ) } ),
				h( 'div', { class: 'leiho-pane__tools' }, [
					App.cfg.canUpload
						? h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--small', onClick: function () {
							fileInput.click();
						} }, [ icon( 'upload' ), __( 'Upload', 'leiho' ) ] )
						: null,
					fileInput,
				] ),
			] ),
			h( 'div', { class: 'leiho-pane__search leiho-media-filters', role: 'search' }, [
				h( 'label', { for: searchId, class: 'leiho-sr', text: __( 'Search', 'leiho' ) } ),
				h( 'span', { class: 'leiho-input-icon' }, icon( 'search', 14 ) ),
				searchInput,
				h( 'label', { for: kindId, class: 'leiho-sr', text: __( 'Type', 'leiho' ) } ),
				kindSelect,
			] ),
			progress,
			h( 'div', { class: 'leiho-pane__body leiho-media__main' }, [ grid, more ] ),
			h( 'div', { class: 'leiho-pane__foot' }, summary ),
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
				App.toast( App.errorMessage( err, __( 'Couldn’t load the media library.', 'leiho' ) ), true );
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
		return text || __( '(no title)', 'leiho' );
	}

	function render() {
		App.clear( grid );
		/* translators: %d: number of media items. */
		summary.textContent = sprintf( _n( '%d item', '%d items', state.total, 'leiho' ), state.total );
		if ( ! state.items.length ) {
			grid.appendChild( h( 'li', { class: 'leiho-media-empty' }, [ icon( 'image', 28 ), h( 'p', { text: __( 'No media found.', 'leiho' ) } ) ] ) );
		}
		state.items.forEach( function ( item ) {
			var src = thumb( item );
			var name = title( item );
			var selected = state.selected && state.selected.id === item.id;
			grid.appendChild(
				h( 'li', {}, h( 'button', {
					type: 'button',
					class: 'leiho-media-tile' + ( selected ? ' is-selected' : '' ),
					'aria-pressed': selected ? 'true' : 'false',
					title: name,
					onClick: function () {
						select( item );
					},
				}, [
					src
						? h( 'img', { src: src, alt: item.alt_text || '', loading: 'lazy' } )
						: h( 'span', { class: 'leiho-media-file' }, [ icon( 'page', 28 ), h( 'span', { text: ( item.mime_type || '' ).split( '/' ).pop() } ) ] ),
					h( 'span', { class: 'leiho-media-tile__name', text: name } ),
				] ) )
			);
		} );
		App.clear( more );
		if ( state.page < state.totalPages ) {
			more.appendChild( h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet leiho-btn--small', onClick: function () {
				load( true );
			} }, __( 'Load more', 'leiho' ) ) );
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
			details.appendChild( h( 'div', { class: 'leiho-empty-state' }, [
				h( 'span', { class: 'leiho-empty-state__icon' }, icon( 'image', 22 ) ),
				h( 'p', { text: __( 'Images and files of the site. Drop files anywhere on this page to upload them.', 'leiho' ) } ),
			] ) );
			return;
		}
		var altId = App.uid( 'alt' );
		var titleId = App.uid( 'mtitle' );
		var urlId = App.uid( 'murl' );
		var src = thumb( item );
		var alt = h( 'textarea', { id: altId, class: 'leiho-input', rows: '3' } );
		alt.value = item.alt_text || '';
		var name = h( 'input', { type: 'text', id: titleId, class: 'leiho-input', value: title( item ) } );
		var url = h( 'input', { type: 'text', id: urlId, class: 'leiho-input leiho-mono', value: item.source_url, readOnly: true } );
		var md = item.media_details || {};

		var save = h( 'button', { type: 'button', class: 'leiho-btn', onClick: function () {
			save.disabled = true;
			App.api( { path: '/wp/v2/media/' + item.id, method: 'POST', data: { alt_text: alt.value, title: name.value } } )
				.then( function ( fresh ) {
					Object.assign( item, { alt_text: fresh.alt_text, title: fresh.title } );
					App.toast( __( 'Media saved.', 'leiho' ) );
					render();
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} )
				.finally( function () {
					save.disabled = false;
				} );
		} }, __( 'Save', 'leiho' ) );

		App.append( details, [
			h( 'div', { class: 'leiho-pane__head leiho-media-details__head' }, [
				h( 'h2', { class: 'leiho-pane__title', text: __( 'Details', 'leiho' ) } ),
				h( 'button', { type: 'button', class: 'leiho-icon-btn', 'aria-label': __( 'Close the details', 'leiho' ), onClick: function () {
					state.selected = null;
					render();
					renderDetails();
				} }, icon( 'close' ) ),
			] ),
			h( 'div', { class: 'leiho-media-details__body' }, [
			src ? h( 'img', { class: 'leiho-media-details__preview', src: item.source_url, alt: '' } ) : null,
			h( 'p', { class: 'leiho-media-details__meta' }, [
				( item.mime_type || '' ),
				md.width ? ' · ' + md.width + ' × ' + md.height : '',
				md.filesize ? ' · ' + Math.round( md.filesize / 1024 ) + ' KB' : '',
			] ),
			'image' === item.media_type
				? h( 'div', { class: 'leiho-setting' }, [
					h( 'div', { class: 'leiho-setting__head' }, [
						h( 'label', { class: 'leiho-label', for: altId, text: __( 'Alternative text', 'leiho' ) } ),
						// Proposition de l'IA (ai.js) : remplit le champ, « Enregistrer » la garde.
						window.LeihoAI ? window.LeihoAI.altTrigger( {
							attachmentId: function () {
								return item.id;
							},
							small: true,
							onAccept: function ( text ) {
								alt.value = text;
								alt.focus();
							},
						} ) : null,
					] ),
					alt,
					h( 'p', { class: 'leiho-help', text: __( 'Describes the image for people who can’t see it, and for search engines.', 'leiho' ) } ),
				] )
				: null,
			h( 'div', { class: 'leiho-setting' }, [ h( 'label', { class: 'leiho-label', for: titleId, text: __( 'Title', 'leiho' ) } ), name ] ),
			h( 'div', { class: 'leiho-setting' }, [
				h( 'label', { class: 'leiho-label', for: urlId, text: __( 'File URL', 'leiho' ) } ),
				url,
				h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet leiho-btn--small', onClick: function () {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( item.source_url ).then( function () {
							App.toast( __( 'Copied', 'leiho' ) );
						} );
					} else {
						url.select();
					}
				} }, __( 'Copy', 'leiho' ) ),
			] ),
			h( 'div', { class: 'leiho-actions' }, [
				save,
				h( 'a', { class: 'leiho-btn leiho-btn--quiet', href: item.source_url, target: '_blank', rel: 'noopener' }, [ __( 'Open', 'leiho' ), icon( 'external', 14 ) ] ),
				h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--danger', onClick: function () {
					remove( item );
				} }, __( 'Delete', 'leiho' ) ),
			] ),
			] ),
		] );
	}

	function remove( item ) {
		App.confirm( {
			/* translators: %s: media title. */
			title: sprintf( __( 'Delete “%s”?', 'leiho' ), title( item ) ),
			message: __( 'The file is deleted for good (media have no trash). Pages that use it will show an empty spot.', 'leiho' ),
			confirmLabel: __( 'Delete', 'leiho' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/wp/v2/media/' + item.id + '?force=true', method: 'DELETE' } )
				.then( function () {
					App.toast( __( 'Media deleted.', 'leiho' ) );
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
			progress.textContent = sprintf( __( 'Uploading %1$d / %2$d…', 'leiho' ), done + 1, files.length );
			var file = files[ done ];
			var body = new FormData();
			body.append( 'file', file, file.name );
			return App.api( { path: '/wp/v2/media', method: 'POST', body: body } )
				.catch( function ( err ) {
					failed += 1;
					/* translators: 1: file name, 2: error message. */
					App.toast( sprintf( __( '%1$s: %2$s', 'leiho' ), file.name, App.errorMessage( err ) ), true );
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
				App.toast( sprintf( _n( '%d file uploaded.', '%d files uploaded.', ok, 'leiho' ), ok ) );
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
