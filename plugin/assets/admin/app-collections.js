/**
 * Okno — vue Collections : constructeur de schéma.
 *
 * Interface posée sur l'API REST okno/v1/collections (voir
 * docs/collections-spec.md) : liste des collections et singletons, édition
 * d'une collection (libellés, type, chemin, fonctionnalités), éditeur de
 * champs (glisser-déposer, 19 types et leurs options, sous-champs, blocs),
 * aperçu du plan de migration (dry_run) avant application, colonnes
 * archivées et purge, suppression, import / export d'okno.schema.json.
 *
 * La clé d'un champ (key) est son identité stable : générée à partir du nom
 * à la création, elle ne change plus une fois enregistrée. Seuls le nom (API)
 * et le libellé se modifient.
 */
( function () {
	'use strict';

	var App = window.OknoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	if ( ! App || ! App.view || 'collections' !== App.view.dataset.view ) {
		return;
	}

	var h = App.h, icon = App.icon, cfg = App.cfg;

	/* ------------------------------------------------------------------ *
	 * Types de champ
	 * ------------------------------------------------------------------ */

	var TYPES = [
		{ type: 'text', label: __( 'Text', 'okno' ), help: __( 'A line or a paragraph of plain text.', 'okno' ) },
		{ type: 'richtext', label: __( 'Rich text', 'okno' ), help: __( 'Formatted text: bold, links, lists.', 'okno' ) },
		{ type: 'number', label: __( 'Number', 'okno' ), help: __( 'A price, a quantity, a rating.', 'okno' ) },
		{ type: 'boolean', label: __( 'Yes / no', 'okno' ), help: __( 'A switch.', 'okno' ) },
		{ type: 'date', label: __( 'Date', 'okno' ), help: __( 'A day.', 'okno' ) },
		{ type: 'datetime', label: __( 'Date and time', 'okno' ), help: __( 'A day and an hour.', 'okno' ) },
		{ type: 'select', label: __( 'Choice', 'okno' ), help: __( 'One or several values from a list.', 'okno' ) },
		{ type: 'slug', label: __( 'Slug', 'okno' ), help: __( 'An identifier for URLs.', 'okno' ) },
		{ type: 'email', label: __( 'Email', 'okno' ), help: __( 'An email address.', 'okno' ) },
		{ type: 'url', label: __( 'URL', 'okno' ), help: __( 'A web address.', 'okno' ) },
		{ type: 'color', label: __( 'Color', 'okno' ), help: __( 'A color code.', 'okno' ) },
		{ type: 'image', label: __( 'Image', 'okno' ), help: __( 'One image from the media library.', 'okno' ) },
		{ type: 'file', label: __( 'File', 'okno' ), help: __( 'A PDF or any document.', 'okno' ) },
		{ type: 'gallery', label: __( 'Gallery', 'okno' ), help: __( 'Several images.', 'okno' ) },
		{ type: 'link', label: __( 'Link', 'okno' ), help: __( 'A URL with its text and target.', 'okno' ) },
		{ type: 'reference', label: __( 'Reference', 'okno' ), help: __( 'Points to entries of other collections.', 'okno' ) },
		{ type: 'list', label: __( 'List', 'okno' ), help: __( 'Repeated rows of sub-fields.', 'okno' ) },
		{ type: 'blocks', label: __( 'Blocks', 'okno' ), help: __( 'Sections of different kinds, in any order.', 'okno' ) },
		{ type: 'group', label: __( 'Group', 'okno' ), help: __( 'Sub-fields grouped together.', 'okno' ) },
	];
	var TYPE_INDEX = {};
	TYPES.forEach( function ( t ) {
		TYPE_INDEX[ t.type ] = t;
	} );

	var SUPPORTS = [
		{ value: 'editor', label: __( 'Main text (WordPress editor)', 'okno' ) },
		{ value: 'excerpt', label: __( 'Excerpt', 'okno' ) },
		{ value: 'thumbnail', label: __( 'Featured image', 'okno' ) },
		{ value: 'page-attributes', label: __( 'Order and parent', 'okno' ) },
	];

	var LENGTH_TYPES = [ 'text', 'slug', 'email', 'url' ];
	var CONTAINERS = [ 'list', 'group' ];
	var MAX_DEPTH = 8;

	/* ------------------------------------------------------------------ *
	 * État
	 * ------------------------------------------------------------------ */

	var state = {
		items: [], // Réponses GET /collections : { collection, table, columns, archived, entry }.
		current: null, // { isNew, item, draft, errors: [] }.
		dirty: false,
		busy: false,
	};

	var uidCount = 0;
	function nextUid() {
		uidCount += 1;
		return 'f' + uidCount;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	/** Copie de travail : chaque champ et bloc reçoit un _uid (DOM, glisser-déposer). */
	function prepare( fields ) {
		( fields || [] ).forEach( function ( field ) {
			field._uid = nextUid();
			if ( field.fields ) {
				prepare( field.fields );
			}
			( field.blocks || [] ).forEach( function ( block ) {
				block._uid = nextUid();
				prepare( block.fields );
			} );
		} );
		return fields;
	}

	/** Retire les propriétés internes (_uid, _new, _open…) avant l'envoi. */
	function strip( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( strip );
		}
		if ( value && 'object' === typeof value ) {
			var out = {};
			Object.keys( value ).forEach( function ( k ) {
				if ( '_' !== k.charAt( 0 ) ) {
					out[ k ] = strip( value[ k ] );
				}
			} );
			return out;
		}
		return value;
	}

	function markDirty() {
		state.dirty = true;
		if ( applyBtn ) {
			applyBtn.disabled = false;
		}
		if ( dirtyNote ) {
			dirtyNote.hidden = false;
		}
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	function confirmLeave() {
		return ! state.dirty || window.confirm( __( 'Your changes to this collection aren’t applied yet. Leave anyway?', 'okno' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Squelette de la vue
	 * ------------------------------------------------------------------ */

	var listEl = h( 'nav', { class: 'okno-builder__list', 'aria-label': __( 'Collections', 'okno' ) } );
	var editorEl = h( 'section', { class: 'okno-builder__editor', 'aria-live': 'off' } );
	var applyBtn = null;
	var dirtyNote = null;
	var importInput = h( 'input', { type: 'file', accept: 'application/json,.json', hidden: true } );

	App.clear( App.view );
	App.append( App.view, [
		h( 'div', { class: 'okno-view-head' }, [
			h( 'div', {}, [
				h( 'h1', { class: 'okno-view-title', text: __( 'Collections', 'okno' ) } ),
				h( 'p', { class: 'okno-muted', text: __( 'Your content model: the content types of the site and their fields. No plugin needed.', 'okno' ) } ),
			] ),
			h( 'div', { class: 'okno-actions' }, [
				h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet', onClick: exportSchema }, [ icon( 'download' ), __( 'Export', 'okno' ) ] ),
				h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet', onClick: function () {
					if ( confirmLeave() ) {
						importInput.click();
					}
				} }, [ icon( 'upload' ), __( 'Import', 'okno' ) ] ),
				importInput,
			] ),
		] ),
		h( 'div', { class: 'okno-builder' }, [ listEl, editorEl ] ),
	] );

	importInput.addEventListener( 'change', function () {
		var file = importInput.files && importInput.files[ 0 ];
		importInput.value = '';
		if ( file ) {
			importSchema( file );
		}
	} );

	/* ------------------------------------------------------------------ *
	 * Chargement et liste
	 * ------------------------------------------------------------------ */

	function load( selectId ) {
		return App.api( { path: '/okno/v1/collections' } )
			.then( function ( body ) {
				state.items = body.collections || [];
				renderList();
				var wanted = selectId || new URLSearchParams( window.location.search ).get( 'collection' );
				var item = state.items.find( function ( i ) {
					return i.collection.id === wanted;
				} );
				if ( item ) {
					open( item );
				} else if ( ! state.current ) {
					renderWelcome();
				}
			} )
			.catch( function ( err ) {
				App.clear( editorEl ).appendChild( h( 'p', { class: 'okno-empty-line', text: App.errorMessage( err, __( 'Couldn’t load the collections.', 'okno' ) ) } ) );
			} );
	}

	function renderList() {
		App.clear( listEl );
		var groups = [
			{ kind: 'collection', title: __( 'Collections', 'okno' ) },
			{ kind: 'singleton', title: __( 'Single pages', 'okno' ) },
		];

		listEl.appendChild(
			h( 'button', { type: 'button', class: 'okno-btn okno-builder__new', onClick: function () {
				if ( confirmLeave() ) {
					openNew();
				}
			} }, [ icon( 'plus' ), __( 'New collection', 'okno' ) ] )
		);

		groups.forEach( function ( group ) {
			var items = state.items.filter( function ( i ) {
				return group.kind === i.collection.kind;
			} );
			listEl.appendChild( h( 'h2', { class: 'okno-builder__group', text: group.title } ) );
			if ( ! items.length ) {
				listEl.appendChild( h( 'p', { class: 'okno-muted okno-builder__none', text: __( 'None yet.', 'okno' ) } ) );
				return;
			}
			listEl.appendChild(
				h(
					'ul',
					{},
					items.map( function ( item ) {
						var c = item.collection;
						var active = state.current && ! state.current.isNew && state.current.item.collection.id === c.id;
						return h( 'li', {}, h( 'a', {
							href: cfg.urls.collections + '&collection=' + c.id,
							class: 'okno-builder__item' + ( active ? ' is-active' : '' ),
							'aria-current': active ? 'page' : null,
							onClick: function ( event ) {
								event.preventDefault();
								if ( confirmLeave() ) {
									open( item );
								}
							},
						}, [
							icon( 'database' ),
							h( 'span', { class: 'okno-builder__item-label', text: c.label } ),
							h( 'span', { class: 'okno-builder__item-meta' }, [
								/* translators: %d: number of fields. */
								sprintf( _n( '%d field', '%d fields', c.fields.length, 'okno' ), c.fields.length ),
								item.archived && item.archived.length ? h( 'span', { class: 'okno-pill okno-pill--warn', title: __( 'Archived columns', 'okno' ), text: String( item.archived.length ) } ) : null,
							] ),
						] ) );
					} )
				)
			);
		} );
	}

	function renderWelcome() {
		state.current = null;
		state.dirty = false;
		App.clear( editorEl ).appendChild(
			h( 'div', { class: 'okno-card okno-empty' }, [
				icon( 'database', 32 ),
				h( 'h3', { text: state.items.length ? __( 'Pick a collection on the left', 'okno' ) : __( 'Define your first collection', 'okno' ) } ),
				h( 'p', { text: __( 'A collection is a content type with many entries (services, testimonials, team members). A single page has exactly one entry (home, contact, legal notice, site settings).', 'okno' ) } ),
				h( 'button', { type: 'button', class: 'okno-btn', onClick: openNew }, [ icon( 'plus' ), __( 'New collection', 'okno' ) ] ),
			] )
		);
	}

	function setUrl( id ) {
		var url = new URL( window.location.href );
		if ( id ) {
			url.searchParams.set( 'collection', id );
		} else {
			url.searchParams.delete( 'collection' );
		}
		window.history.replaceState( null, '', url.toString() );
	}

	function open( item ) {
		var draft = clone( item.collection );
		prepare( draft.fields );
		state.current = { isNew: false, item: item, draft: draft, errors: [] };
		state.dirty = false;
		setUrl( item.collection.id );
		renderList();
		renderEditor();
	}

	function openNew() {
		state.current = {
			isNew: true,
			item: null,
			draft: { id: '', kind: 'collection', label: '', singular_label: '', path: '', supports: [], icon: '', fields: [] },
			errors: [],
			idTouched: false,
		};
		state.dirty = false;
		setUrl( '' );
		renderList();
		renderEditor();
		var first = editorEl.querySelector( 'input' );
		if ( first ) {
			first.focus();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Éditeur d'une collection
	 * ------------------------------------------------------------------ */

	function renderEditor( focusSelector ) {
		var scroll = App.view.parentNode ? App.view.parentNode.scrollTop : 0;
		var cur = state.current;
		var d = cur.draft;
		App.clear( editorEl );

		var title = cur.isNew ? __( 'New collection', 'okno' ) : d.label || d.id;
		applyBtn = h( 'button', { type: 'button', class: 'okno-btn', disabled: ! state.dirty && ! cur.isNew, onClick: review }, cur.isNew ? __( 'Review and create', 'okno' ) : __( 'Review and apply', 'okno' ) );
		dirtyNote = h( 'span', { class: 'okno-dirty-note', hidden: ! state.dirty, text: __( 'Changes not applied yet', 'okno' ) } );

		var headActions = [ dirtyNote ];
		if ( ! cur.isNew ) {
			var entryLink = entryUrl( cur.item );
			if ( entryLink ) {
				headActions.push( h( 'a', { class: 'okno-btn okno-btn--quiet', href: entryLink }, [ icon( 'form' ), __( 'Edit the content', 'okno' ) ] ) );
			} else {
				headActions.push( h( 'a', { class: 'okno-btn okno-btn--quiet', href: cfg.urls.content + '&type=' + d.id }, [ icon( 'page' ), __( 'Entries', 'okno' ) ] ) );
			}
		}
		headActions.push( applyBtn );

		editorEl.appendChild(
			h( 'div', { class: 'okno-builder__head' }, [
				h( 'div', {}, [
					h( 'h2', { text: title } ),
					cur.isNew
						? null
						: h( 'p', { class: 'okno-muted' }, [
							'singleton' === d.kind ? __( 'Single page', 'okno' ) : __( 'Collection', 'okno' ),
							' · ',
							h( 'code', { text: d.id } ),
						] ),
				] ),
				h( 'div', { class: 'okno-actions' }, headActions ),
			] )
		);

		var general = errorsFor( '' );
		if ( general.length ) {
			editorEl.appendChild( h( 'div', { class: 'okno-notice okno-notice--error', role: 'alert' }, general.map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) );
		}

		editorEl.appendChild( renderGeneral( d, cur ) );

		editorEl.appendChild(
			h( 'div', { class: 'okno-card okno-builder__fields' }, [
				h( 'div', { class: 'okno-card__head' }, [
					h( 'h3', { text: __( 'Fields', 'okno' ) } ),
					h( 'p', { class: 'okno-muted', text: __( 'Drag the handle or use the arrows to reorder. The title (and the features ticked above) come with every entry.', 'okno' ) } ),
				] ),
				renderFieldList( d.fields, 1, 'fields' ),
			] )
		);

		if ( ! cur.isNew ) {
			editorEl.appendChild( renderArchived( cur.item ) );
			editorEl.appendChild(
				h( 'div', { class: 'okno-card okno-danger-zone' }, [
					h( 'div', {}, [
						h( 'h3', { text: __( 'Remove this collection', 'okno' ) } ),
						h( 'p', { class: 'okno-muted', text: __( 'It disappears from Okno and the content API. Its entries and their values are kept: create it again with the same id to get everything back.', 'okno' ) } ),
					] ),
					h( 'button', { type: 'button', class: 'okno-btn okno-btn--danger', onClick: removeCollection }, [ icon( 'trash' ), __( 'Remove', 'okno' ) ] ),
				] )
			);
		}

		if ( App.view.parentNode ) {
			App.view.parentNode.scrollTop = scroll;
		}
		if ( focusSelector ) {
			var target = editorEl.querySelector( focusSelector );
			if ( target ) {
				target.focus();
			}
		}
	}

	function entryUrl( item ) {
		if ( ! item || ! item.entry ) {
			return '';
		}
		var hasPath = '' !== ( item.collection.path || '' );
		return ( hasPath && cfg.frontUrl ? cfg.urls.editor : cfg.urls.form ) + '&post=' + item.entry;
	}

	function field( labelText, control, help, id ) {
		return h( 'div', { class: 'okno-setting' }, [
			h( 'label', { class: 'okno-label', for: id, text: labelText } ),
			control,
			help ? h( 'p', { class: 'okno-help', text: help } ) : null,
		] );
	}

	function renderGeneral( d, cur ) {
		var labelId = App.uid( 'label' );
		var singularId = App.uid( 'singular' );
		var idId = App.uid( 'id' );
		var pathId = App.uid( 'path' );

		var idInput = h( 'input', { type: 'text', id: idId, class: 'okno-input okno-mono', value: d.id, readOnly: ! cur.isNew, maxlength: '20', 'aria-describedby': idId + '-help' } );
		var label = h( 'input', { type: 'text', id: labelId, class: 'okno-input', value: d.label, placeholder: __( 'Services', 'okno' ) } );
		var singular = h( 'input', { type: 'text', id: singularId, class: 'okno-input', value: d.singular_label === d.label ? '' : d.singular_label, placeholder: __( 'Service', 'okno' ) } );
		var path = h( 'input', { type: 'text', id: pathId, class: 'okno-input okno-mono', value: d.path, placeholder: 'singleton' === d.kind ? '/contact' : '/services/{slug}' } );

		label.addEventListener( 'input', function () {
			d.label = label.value;
			if ( cur.isNew && ! cur.idTouched ) {
				d.id = App.slugify( label.value, 20 );
				idInput.value = d.id;
			}
			markDirty();
		} );
		singular.addEventListener( 'input', function () {
			d.singular_label = singular.value;
			markDirty();
		} );
		idInput.addEventListener( 'input', function () {
			cur.idTouched = true;
			d.id = idInput.value.trim();
			markDirty();
		} );
		path.addEventListener( 'input', function () {
			d.path = path.value.trim();
			markDirty();
		} );

		var kindName = App.uid( 'kind' );
		var kinds = h( 'fieldset', { class: 'okno-setting okno-radios' }, [
			h( 'legend', { class: 'okno-label', text: __( 'Kind', 'okno' ) } ),
			[
				{ value: 'collection', label: __( 'Collection', 'okno' ), help: __( 'Many entries (services, articles, testimonials).', 'okno' ) },
				{ value: 'singleton', label: __( 'Single page', 'okno' ), help: __( 'Exactly one entry (home, contact, site settings).', 'okno' ) },
			].map( function ( k ) {
				var input = h( 'input', { type: 'radio', name: kindName, value: k.value, checked: d.kind === k.value } );
				input.addEventListener( 'change', function () {
					d.kind = k.value;
					markDirty();
				} );
				return h( 'label', { class: 'okno-radio' }, [ input, h( 'span', {}, [ h( 'strong', { text: k.label } ), h( 'span', { class: 'okno-help', text: k.help } ) ] ) ] );
			} ),
		] );

		var features = h( 'fieldset', { class: 'okno-setting okno-checks-inline' }, [
			h( 'legend', { class: 'okno-label', text: __( 'Built-in features', 'okno' ) } ),
			SUPPORTS.map( function ( s ) {
				var input = h( 'input', { type: 'checkbox', value: s.value, checked: d.supports.indexOf( s.value ) !== -1 } );
				input.addEventListener( 'change', function () {
					d.supports = d.supports.filter( function ( v ) {
						return v !== s.value;
					} );
					if ( input.checked ) {
						d.supports.push( s.value );
					}
					markDirty();
				} );
				return h( 'label', { class: 'okno-check' }, [ input, s.label ] );
			} ),
		] );

		var idErrors = errorsFor( 'id' ).concat( errorsFor( 'label' ) );
		var pathErrors = errorsFor( 'path' );

		return h( 'div', { class: 'okno-card' }, [
			h( 'div', { class: 'okno-card__head' }, h( 'h3', { text: __( 'General', 'okno' ) } ) ),
			h( 'div', { class: 'okno-settings-grid' }, [
				field( __( 'Name (plural)', 'okno' ), label, null, labelId ),
				field( __( 'Name of one entry', 'okno' ), singular, __( 'Optional. Used in “New service”.', 'okno' ), singularId ),
				h( 'div', { class: 'okno-setting' + ( idErrors.length ? ' has-error' : '' ) }, [
					h( 'label', { class: 'okno-label', for: idId, text: __( 'Identifier', 'okno' ) } ),
					idInput,
					h( 'p', { class: 'okno-help', id: idId + '-help', text: cur.isNew ? __( 'Content type and table name. Lowercase letters, digits and underscores; it can’t change later.', 'okno' ) : __( 'The identifier never changes.', 'okno' ) } ),
					idErrors.map( errorLine ),
				] ),
				h( 'div', { class: 'okno-setting' + ( pathErrors.length ? ' has-error' : '' ) }, [
					h( 'label', { class: 'okno-label', for: pathId, text: __( 'URL on the site', 'okno' ) } ),
					path,
					h( 'p', { class: 'okno-help', text: __( 'Where an entry is shown on the site, with {slug} or {id}. Leave empty for content without a page (testimonials, settings): it is then edited in a form.', 'okno' ) } ),
					pathErrors.map( errorLine ),
				] ),
				kinds,
				features,
			] ),
		] );
	}

	function errorLine( message ) {
		return h( 'p', { class: 'okno-error', role: 'alert', text: message } );
	}

	/* ------------------------------------------------------------------ *
	 * Champs
	 * ------------------------------------------------------------------ */

	var drag = null; // { list, index } pendant un glisser.

	/**
	 * Liste de champs frères (racine, sous-champs d'une liste/d'un groupe,
	 * champs d'un type de bloc). pathPrefix : chemin des erreurs du serveur.
	 */
	function renderFieldList( list, depth, pathPrefix ) {
		var ol = h( 'ol', { class: 'okno-fields' } );
		list.forEach( function ( f, index ) {
			ol.appendChild( renderFieldRow( list, f, index, depth, pathPrefix + '.' + index ) );
		} );
		if ( ! list.length ) {
			ol.appendChild( h( 'li', { class: 'okno-fields__empty', text: depth > 1 ? __( 'No sub-fields yet.', 'okno' ) : __( 'No fields yet. Each entry still has a title.', 'okno' ) } ) );
		}
		var wrap = h( 'div', { class: 'okno-fields-wrap' }, [ ol ] );
		if ( depth <= MAX_DEPTH ) {
			wrap.appendChild(
				h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small okno-add-field', onClick: function ( event ) {
					pickType( event.currentTarget ).then( function ( type ) {
						if ( type ) {
							addField( list, type );
						}
					} );
				} }, [ icon( 'plus' ), depth > 1 ? __( 'Add a sub-field', 'okno' ) : __( 'Add a field', 'okno' ) ] )
			);
		}
		return wrap;
	}

	function siblingsTaken( list, prop, except ) {
		var taken = {};
		list.forEach( function ( f ) {
			if ( f !== except && f[ prop ] ) {
				taken[ f[ prop ] ] = true;
			}
		} );
		return taken;
	}

	function unique( base, taken, max ) {
		base = base || 'field';
		var out = base.slice( 0, max );
		var n = 2;
		while ( taken[ out ] ) {
			var suffix = '_' + n;
			out = base.slice( 0, max - suffix.length ) + suffix;
			n += 1;
		}
		return out;
	}

	/** Nom (et clé, tant que le champ n'est pas enregistré) déduits du libellé. */
	function syncName( list, f ) {
		var base = App.slugify( f.label, 64 ) || 'field';
		f.name = unique( base, siblingsTaken( list, 'name', f ), 64 );
		if ( f._new ) {
			f.key = unique( f.name.slice( 0, 48 ).replace( /_+$/, '' ), siblingsTaken( list, 'key', f ), 48 );
		}
	}

	function defaultsFor( type ) {
		var f = { type: type, required: false, help: '' };
		if ( LENGTH_TYPES.indexOf( type ) !== -1 ) {
			f.max_length = 0;
		}
		switch ( type ) {
			case 'text':
				f.multiline = false;
				break;
			case 'number':
				f.integer = false;
				f.min = null;
				f.max = null;
				f.step = null;
				break;
			case 'select':
				f.multiple = false;
				f.choices = [];
				break;
			case 'gallery':
				f.max = 0;
				break;
			case 'reference':
				f.to = [];
				f.multiple = false;
				f.max = 0;
				break;
			case 'list':
				f.fields = [];
				f.min = 0;
				f.max = 0;
				break;
			case 'group':
				f.fields = [];
				break;
			case 'blocks':
				f.blocks = [];
				f.min = 0;
				f.max = 0;
				break;
		}
		return f;
	}

	function addField( list, type ) {
		var f = defaultsFor( type );
		f._uid = nextUid();
		f._new = true;
		f._open = true;
		f.label = TYPE_INDEX[ type ].label;
		syncName( list, f );
		list.push( f );
		markDirty();
		renderEditor( '[data-uid="' + f._uid + '"] .okno-field-label-input' );
	}

	function setType( list, f, type ) {
		var fresh = defaultsFor( type );
		// Les options déjà saisies qui restent valables sont gardées.
		Object.keys( fresh ).forEach( function ( k ) {
			if ( undefined !== f[ k ] && 'type' !== k ) {
				fresh[ k ] = f[ k ];
			}
		} );
		Object.keys( f ).forEach( function ( k ) {
			if ( [ 'key', 'name', 'label', 'required', 'help' ].indexOf( k ) === -1 && '_' !== k.charAt( 0 ) ) {
				delete f[ k ];
			}
		} );
		Object.keys( fresh ).forEach( function ( k ) {
			f[ k ] = fresh[ k ];
		} );
		f.type = type;
		markDirty();
		renderEditor( '[data-uid="' + f._uid + '"] .okno-field-type-input' );
	}

	function move( list, from, to ) {
		if ( to < 0 || to >= list.length || from === to ) {
			return;
		}
		var item = list.splice( from, 1 )[ 0 ];
		list.splice( to, 0, item );
		markDirty();
		return item;
	}

	/** Le champ existe-t-il en base (enregistré) ? */
	function savedField( f ) {
		return ! f._new;
	}

	function renderFieldRow( list, f, index, depth, path ) {
		var bodyId = 'okno-fieldbody-' + f._uid;
		var meta = TYPE_INDEX[ f.type ] || { label: f.type };
		var errors = collectErrors( path );
		var hasNestedErrors = errors.nested;

		if ( errors.own.length || hasNestedErrors ) {
			f._open = true;
		}

		var toggle = h( 'button', {
			type: 'button',
			class: 'okno-fieldrow__toggle',
			'aria-expanded': f._open ? 'true' : 'false',
			'aria-controls': bodyId,
			onClick: function () {
				f._open = ! f._open;
				toggle.setAttribute( 'aria-expanded', f._open ? 'true' : 'false' );
				body.hidden = ! f._open;
				li.classList.toggle( 'is-open', f._open );
			},
		}, [
			icon( 'chevron', 14 ),
			h( 'span', { class: 'okno-type-badge', text: meta.label } ),
			h( 'span', { class: 'okno-fieldrow__label', text: ( f.label || f.name ) + ( f.required ? ' *' : '' ) } ),
			h( 'code', { class: 'okno-fieldrow__name', text: f.name } ),
		] );

		var grip = h( 'span', { class: 'okno-grip', title: __( 'Drag to reorder', 'okno' ), 'aria-hidden': 'true' }, icon( 'grip' ) );

		var label = f.label || f.name;
		var actions = h( 'div', { class: 'okno-fieldrow__actions' }, [
			h( 'button', {
				type: 'button',
				class: 'okno-icon-btn okno-move-up',
				disabled: 0 === index,
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Move “%s” up', 'okno' ), label ),
				title: __( 'Move up', 'okno' ),
				onClick: function () {
					move( list, index, index - 1 );
					renderEditor( '[data-uid="' + f._uid + '"] .okno-move-up:not([disabled]), [data-uid="' + f._uid + '"] .okno-move-down' );
				},
			}, icon( 'up' ) ),
			h( 'button', {
				type: 'button',
				class: 'okno-icon-btn okno-move-down',
				disabled: index === list.length - 1,
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Move “%s” down', 'okno' ), label ),
				title: __( 'Move down', 'okno' ),
				onClick: function () {
					move( list, index, index + 1 );
					renderEditor( '[data-uid="' + f._uid + '"] .okno-move-down:not([disabled]), [data-uid="' + f._uid + '"] .okno-move-up' );
				},
			}, icon( 'down' ) ),
			h( 'button', {
				type: 'button',
				class: 'okno-icon-btn okno-icon-btn--danger',
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Remove “%s”', 'okno' ), label ),
				title: __( 'Remove', 'okno' ),
				onClick: function () {
					removeField( list, index, f );
				},
			}, icon( 'trash' ) ),
		] );

		var body = h( 'div', { class: 'okno-fieldrow__body', id: bodyId, hidden: ! f._open }, renderFieldSettings( list, f, depth, path, errors.own ) );

		var li = h( 'li', {
			class: 'okno-fieldrow' + ( f._open ? ' is-open' : '' ) + ( errors.own.length || hasNestedErrors ? ' has-error' : '' ),
			dataset: { uid: f._uid },
		}, [ h( 'div', { class: 'okno-fieldrow__head' }, [ grip, toggle, actions ] ), body ] );

		bindDrag( li, grip, list, index );
		return li;
	}

	/**
	 * Glisser-déposer entre frères, avec la poignée. Événements pointer (souris,
	 * stylet, tactile) plutôt que le glisser-déposer HTML5, absent des écrans
	 * tactiles. Au clavier : les flèches monter / descendre.
	 */
	function bindDrag( li, grip, list, index ) {
		grip.addEventListener( 'pointerdown', function ( event ) {
			if ( 0 !== event.button ) {
				return;
			}
			event.preventDefault();
			var ol = li.parentNode;
			var rows = Array.prototype.filter.call( ol.children, function ( el ) {
				return el.classList.contains( 'okno-fieldrow' );
			} );
			var target = index;
			drag = { list: list, index: index };
			li.classList.add( 'is-dragging' );
			try {
				grip.setPointerCapture( event.pointerId );
			} catch ( e ) {
				/* Capture indisponible : le glisser marche quand même. */
			}

			function clearMarks() {
				rows.forEach( function ( row ) {
					row.classList.remove( 'drop-before', 'drop-after' );
				} );
			}

			function onMove( e ) {
				clearMarks();
				target = rows.length - 1;
				for ( var i = 0; i < rows.length; i++ ) {
					var rect = rows[ i ].getBoundingClientRect();
					if ( e.clientY < rect.top + rect.height / 2 ) {
						target = i;
						break;
					}
				}
				var last = rows[ rows.length - 1 ].getBoundingClientRect();
				var after = e.clientY >= last.top + last.height / 2;
				if ( after ) {
					rows[ rows.length - 1 ].classList.add( 'drop-after' );
					target = rows.length;
				} else {
					rows[ target ].classList.add( 'drop-before' );
				}
			}

			function onUp() {
				grip.removeEventListener( 'pointermove', onMove );
				grip.removeEventListener( 'pointerup', onUp );
				grip.removeEventListener( 'pointercancel', onUp );
				clearMarks();
				li.classList.remove( 'is-dragging' );
				drag = null;
				var to = target > index ? target - 1 : target;
				if ( move( list, index, to ) ) {
					App.announce( __( 'Field moved.', 'okno' ) );
					renderEditor();
				}
			}

			grip.addEventListener( 'pointermove', onMove );
			grip.addEventListener( 'pointerup', onUp );
			grip.addEventListener( 'pointercancel', onUp );
		} );
	}

	function removeField( list, index, f ) {
		var nested = !! list._nested;
		var message = f._new
			? __( 'This field isn’t saved yet: it is simply removed.', 'okno' )
			: nested
			? __( 'Its values disappear from each entry the next time the entry is saved. Revisions keep the previous values.', 'okno' )
			: __( 'Its column is archived, not deleted: add a field with the same key and type again to get the values back, or purge archived columns to delete them.', 'okno' );
		App.confirm( {
			/* translators: %s: field label. */
			title: sprintf( __( 'Remove “%s”?', 'okno' ), f.label || f.name ),
			message: message,
			confirmLabel: __( 'Remove', 'okno' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ok ) {
				list.splice( index, 1 );
				markDirty();
				renderEditor();
			}
		} );
	}

	function numberInput( id, value, onValue, attrs ) {
		var input = h( 'input', Object.assign( { type: 'number', id: id, class: 'okno-input okno-input--small', value: null === value || undefined === value ? '' : String( value ) }, attrs || {} ) );
		input.addEventListener( 'input', function () {
			onValue( '' === input.value ? null : Number( input.value ) );
			markDirty();
		} );
		return input;
	}

	function checkbox( labelText, checked, onValue ) {
		var input = h( 'input', { type: 'checkbox', checked: !! checked } );
		input.addEventListener( 'change', function () {
			onValue( input.checked );
			markDirty();
		} );
		return h( 'label', { class: 'okno-check' }, [ input, labelText ] );
	}

	function renderFieldSettings( list, f, depth, path, ownErrors ) {
		var ids = {
			label: App.uid( 'flabel' ),
			name: App.uid( 'fname' ),
			type: App.uid( 'ftype' ),
			help: App.uid( 'fhelp' ),
		};
		var out = [];

		if ( ownErrors.length ) {
			out.push( h( 'div', { class: 'okno-notice okno-notice--error', role: 'alert' }, ownErrors.map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) );
		}

		var labelInput = h( 'input', { type: 'text', id: ids.label, class: 'okno-input okno-field-label-input', value: f.label || '' } );
		var nameInput = h( 'input', { type: 'text', id: ids.name, class: 'okno-input okno-mono', value: f.name, maxlength: '64', spellcheck: 'false' } );
		var keyNote = h( 'p', { class: 'okno-help okno-key-note' } );

		function renderKeyNote() {
			keyNote.textContent = savedField( f )
				? /* translators: %s: field key. */
				  sprintf( __( 'Key: %s (fixed: it names the stored data, renaming is free).', 'okno' ), f.key )
				: /* translators: %s: field key. */
				  sprintf( __( 'Key: %s (set when you apply, then fixed).', 'okno' ), f.key );
		}
		renderKeyNote();

		function refreshHead() {
			var row = editorEl.querySelector( '[data-uid="' + f._uid + '"]' );
			if ( ! row ) {
				return;
			}
			var labelEl = row.querySelector( '.okno-fieldrow__label' );
			var nameEl = row.querySelector( '.okno-fieldrow__name' );
			if ( labelEl ) {
				labelEl.textContent = ( f.label || f.name ) + ( f.required ? ' *' : '' );
			}
			if ( nameEl ) {
				nameEl.textContent = f.name;
			}
		}

		labelInput.addEventListener( 'input', function () {
			f.label = labelInput.value;
			if ( f._new && ! f._nameTouched ) {
				syncName( list, f );
				nameInput.value = f.name;
				renderKeyNote();
			}
			refreshHead();
			markDirty();
		} );
		nameInput.addEventListener( 'input', function () {
			f._nameTouched = true;
			f.name = nameInput.value.trim();
			if ( f._new ) {
				var keyBase = App.slugify( f.name, 48 ) || 'field';
				f.key = unique( keyBase, siblingsTaken( list, 'key', f ), 48 );
				renderKeyNote();
			}
			refreshHead();
			markDirty();
		} );

		var typeSelect = h(
			'select',
			{ id: ids.type, class: 'okno-input okno-field-type-input' },
			TYPES.filter( function ( t ) {
				// Pas de conteneur au-delà de la profondeur maximale.
				return depth < MAX_DEPTH || [ 'list', 'group', 'blocks' ].indexOf( t.type ) === -1 || t.type === f.type;
			} ).map( function ( t ) {
				return h( 'option', { value: t.type, text: t.label, selected: t.type === f.type } );
			} )
		);
		typeSelect.addEventListener( 'change', function () {
			setType( list, f, typeSelect.value );
		} );

		var helpInput = h( 'input', { type: 'text', id: ids.help, class: 'okno-input', value: f.help || '', placeholder: __( 'Shown to editors under the field', 'okno' ) } );
		helpInput.addEventListener( 'input', function () {
			f.help = helpInput.value;
			markDirty();
		} );

		var typeHelp = ( TYPE_INDEX[ f.type ] || {} ).help;
		if ( savedField( f ) && depth === 1 ) {
			typeHelp = ( typeHelp ? typeHelp + ' ' : '' ) + __( 'Changing the type of a saved field moves its values to a new column; values that can’t be converted stay in the archived column.', 'okno' );
		}

		out.push(
			h( 'div', { class: 'okno-settings-grid' }, [
				field( __( 'Label', 'okno' ), labelInput, null, ids.label ),
				h( 'div', { class: 'okno-setting' }, [
					h( 'label', { class: 'okno-label', for: ids.name, text: __( 'Name in the API', 'okno' ) } ),
					nameInput,
					keyNote,
				] ),
				field( __( 'Type', 'okno' ), typeSelect, typeHelp, ids.type ),
				field( __( 'Instructions', 'okno' ), helpInput, null, ids.help ),
			] )
		);

		var options = h( 'div', { class: 'okno-settings-grid okno-settings-grid--options' } );
		options.appendChild( checkbox( __( 'Required', 'okno' ), f.required, function ( v ) {
			f.required = v;
			refreshHead();
		} ) );

		if ( 'text' === f.type ) {
			options.appendChild( checkbox( __( 'Several lines', 'okno' ), f.multiline, function ( v ) {
				f.multiline = v;
			} ) );
		}

		if ( LENGTH_TYPES.indexOf( f.type ) !== -1 ) {
			var lenId = App.uid( 'flen' );
			options.appendChild( field( __( 'Maximum length', 'okno' ), numberInput( lenId, f.max_length || null, function ( v ) {
				f.max_length = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'okno' ) } ), 'text' === f.type ? __( 'Up to 191 characters, the text is indexed (fast to sort and filter).', 'okno' ) : null, lenId ) );
		}

		if ( 'number' === f.type ) {
			options.appendChild( checkbox( __( 'Whole numbers only', 'okno' ), f.integer, function ( v ) {
				f.integer = v;
			} ) );
			[ [ 'min', __( 'Minimum', 'okno' ) ], [ 'max', __( 'Maximum', 'okno' ) ], [ 'step', __( 'Step', 'okno' ) ] ].forEach( function ( pair ) {
				var id = App.uid( 'fnum' );
				options.appendChild( field( pair[ 1 ], numberInput( id, f[ pair[ 0 ] ], function ( v ) {
					f[ pair[ 0 ] ] = v;
				}, { step: 'any' } ), null, id ) );
			} );
		}

		if ( 'select' === f.type ) {
			options.appendChild( checkbox( __( 'Several choices', 'okno' ), f.multiple, function ( v ) {
				f.multiple = v;
			} ) );
			var choicesId = App.uid( 'fchoices' );
			var choices = h( 'textarea', { id: choicesId, class: 'okno-input okno-mono', rows: '5', spellcheck: 'false', placeholder: 'light : Light\ndark : Dark' } );
			choices.value = ( f.choices || [] ).map( function ( c ) {
				return c.value === c.label ? c.value : c.value + ' : ' + c.label;
			} ).join( '\n' );
			choices.addEventListener( 'input', function () {
				f.choices = parseChoices( choices.value );
				markDirty();
			} );
			options.appendChild( h( 'div', { class: 'okno-setting okno-setting--wide' }, [
				h( 'label', { class: 'okno-label', for: choicesId, text: __( 'Choices', 'okno' ) } ),
				choices,
				h( 'p', { class: 'okno-help', text: __( 'One choice per line. Write “value : Label” to show a label different from the stored value.', 'okno' ) } ),
			] ) );
		}

		if ( 'gallery' === f.type ) {
			var gmax = App.uid( 'fmax' );
			options.appendChild( field( __( 'Maximum images', 'okno' ), numberInput( gmax, f.max || null, function ( v ) {
				f.max = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'okno' ) } ), null, gmax ) );
		}

		if ( 'reference' === f.type ) {
			options.appendChild( checkbox( __( 'Several entries', 'okno' ), f.multiple, function ( v ) {
				f.multiple = v;
			} ) );
			var rmax = App.uid( 'fmax' );
			options.appendChild( field( __( 'Maximum entries', 'okno' ), numberInput( rmax, f.max || null, function ( v ) {
				f.max = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'okno' ) } ), __( 'With “Several entries”.', 'okno' ), rmax ) );
			options.appendChild( renderTargets( f ) );
		}

		if ( 'list' === f.type || 'blocks' === f.type ) {
			[ [ 'min', __( 'Minimum rows', 'okno' ) ], [ 'max', __( 'Maximum rows', 'okno' ) ] ].forEach( function ( pair ) {
				var id = App.uid( 'frows' );
				options.appendChild( field( pair[ 1 ], numberInput( id, f[ pair[ 0 ] ] || null, function ( v ) {
					f[ pair[ 0 ] ] = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0, placeholder: 'max' === pair[ 0 ] ? __( 'No limit', 'okno' ) : '0' } ), null, id ) );
			} );
		}

		out.push( options );

		if ( CONTAINERS.indexOf( f.type ) !== -1 ) {
			f.fields = f.fields || [];
			f.fields._nested = true;
			out.push(
				h( 'div', { class: 'okno-subfields' }, [
					h( 'h4', { text: 'list' === f.type ? __( 'Fields of each row', 'okno' ) : __( 'Fields of the group', 'okno' ) } ),
					renderFieldList( f.fields, depth + 1, path + '.fields' ),
				] )
			);
		}

		if ( 'blocks' === f.type ) {
			f.blocks = f.blocks || [];
			out.push( renderBlocks( f, depth, path ) );
		}

		return out;
	}

	function parseChoices( text ) {
		var out = [];
		var seen = {};
		text.split( '\n' ).forEach( function ( line ) {
			line = line.trim();
			if ( ! line ) {
				return;
			}
			var at = line.indexOf( ' : ' );
			var value = at === -1 ? line : line.slice( 0, at ).trim();
			var label = at === -1 ? line : line.slice( at + 3 ).trim();
			if ( value && ! seen[ value ] ) {
				seen[ value ] = true;
				out.push( { value: value, label: label || value } );
			}
		} );
		return out;
	}

	function renderTargets( f ) {
		var d = state.current.draft;
		var options = [];
		var seen = {};
		state.items.forEach( function ( item ) {
			options.push( { name: item.collection.id, label: item.collection.label } );
			seen[ item.collection.id ] = true;
		} );
		if ( d.id && ! seen[ d.id ] ) {
			options.push( { name: d.id, label: d.label || d.id } );
		}
		( cfg.postTypes || [] ).forEach( function ( t ) {
			if ( ! seen[ t.name ] ) {
				options.push( { name: t.name, label: t.label } );
			}
		} );
		return h( 'fieldset', { class: 'okno-setting okno-setting--wide okno-checks-inline' }, [
			h( 'legend', { class: 'okno-label', text: __( 'Points to', 'okno' ) } ),
			options.map( function ( o ) {
				var input = h( 'input', { type: 'checkbox', value: o.name, checked: ( f.to || [] ).indexOf( o.name ) !== -1 } );
				input.addEventListener( 'change', function () {
					f.to = ( f.to || [] ).filter( function ( v ) {
						return v !== o.name;
					} );
					if ( input.checked ) {
						f.to.push( o.name );
					}
					markDirty();
				} );
				return h( 'label', { class: 'okno-check' }, [ input, o.label, ' ', h( 'code', { text: o.name } ) ] );
			} ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Types de bloc (champ blocks)
	 * ------------------------------------------------------------------ */

	function renderBlocks( f, depth, path ) {
		var wrap = h( 'div', { class: 'okno-subfields okno-blocktypes' }, [ h( 'h4', { text: __( 'Block types', 'okno' ) } ) ] );
		if ( ! f.blocks.length ) {
			wrap.appendChild( h( 'p', { class: 'okno-help', text: __( 'Add at least one block type: editors pick one each time they add a block.', 'okno' ) } ) );
		}
		f.blocks.forEach( function ( block, index ) {
			wrap.appendChild( renderBlockType( f, block, index, depth, path + '.blocks.' + index ) );
		} );
		wrap.appendChild(
			h( 'button', { type: 'button', class: 'okno-btn okno-btn--quiet okno-btn--small', onClick: function () {
				var block = { _uid: nextUid(), _new: true, _open: true, label: __( 'New block', 'okno' ), min: 0, max: 0, fields: [] };
				syncName( f.blocks, block );
				f.blocks.push( block );
				markDirty();
				renderEditor( '[data-uid="' + block._uid + '"] .okno-block-label-input' );
			} }, [ icon( 'plus' ), __( 'Add a block type', 'okno' ) ] )
		);
		return wrap;
	}

	function renderBlockType( f, block, index, depth, path ) {
		var errors = collectErrors( path );
		var labelId = App.uid( 'blabel' );
		var nameId = App.uid( 'bname' );
		var labelInput = h( 'input', { type: 'text', id: labelId, class: 'okno-input okno-block-label-input', value: block.label || '' } );
		var nameInput = h( 'input', { type: 'text', id: nameId, class: 'okno-input okno-mono', value: block.name || '', spellcheck: 'false' } );
		labelInput.addEventListener( 'input', function () {
			block.label = labelInput.value;
			if ( block._new && ! block._nameTouched ) {
				syncName( f.blocks, block );
				nameInput.value = block.name;
			}
			markDirty();
		} );
		nameInput.addEventListener( 'input', function () {
			block._nameTouched = true;
			block.name = nameInput.value.trim();
			if ( block._new ) {
				block.key = unique( App.slugify( block.name, 48 ) || 'block', siblingsTaken( f.blocks, 'key', block ), 48 );
			}
			markDirty();
		} );
		var minId = App.uid( 'bmin' );
		var maxId = App.uid( 'bmax' );
		block.fields = block.fields || [];
		block.fields._nested = true;

		var label = block.label || block.name;
		return h( 'div', { class: 'okno-blocktype' + ( errors.own.length || errors.nested ? ' has-error' : '' ), dataset: { uid: block._uid } }, [
			h( 'div', { class: 'okno-blocktype__head' }, [
				h( 'strong', { text: label } ),
				h( 'div', { class: 'okno-fieldrow__actions' }, [
					h( 'button', { type: 'button', class: 'okno-icon-btn', disabled: 0 === index, title: __( 'Move up', 'okno' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Move “%s” up', 'okno' ), label ),
						onClick: function () {
							move( f.blocks, index, index - 1 );
							renderEditor();
						} }, icon( 'up' ) ),
					h( 'button', { type: 'button', class: 'okno-icon-btn', disabled: index === f.blocks.length - 1, title: __( 'Move down', 'okno' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Move “%s” down', 'okno' ), label ),
						onClick: function () {
							move( f.blocks, index, index + 1 );
							renderEditor();
						} }, icon( 'down' ) ),
					h( 'button', { type: 'button', class: 'okno-icon-btn okno-icon-btn--danger', title: __( 'Remove', 'okno' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Remove “%s”', 'okno' ), label ),
						onClick: function () {
							App.confirm( {
								/* translators: %s: block type label. */
								title: sprintf( __( 'Remove the block type “%s”?', 'okno' ), label ),
								message: __( 'Blocks of this type disappear from each entry the next time the entry is saved. Revisions keep them.', 'okno' ),
								confirmLabel: __( 'Remove', 'okno' ),
								danger: true,
							} ).then( function ( ok ) {
								if ( ok ) {
									f.blocks.splice( index, 1 );
									markDirty();
									renderEditor();
								}
							} );
						} }, icon( 'trash' ) ),
				] ),
			] ),
			errors.own.length ? h( 'div', { class: 'okno-notice okno-notice--error', role: 'alert' }, errors.own.map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) : null,
			h( 'div', { class: 'okno-settings-grid' }, [
				field( __( 'Label', 'okno' ), labelInput, null, labelId ),
				field( __( 'Name in the API', 'okno' ), nameInput, block._new ? null : sprintf( /* translators: %s: block key. */ __( 'Key: %s', 'okno' ), block.key ), nameId ),
				field( __( 'Minimum', 'okno' ), numberInput( minId, block.min || null, function ( v ) {
					block.min = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0 } ), null, minId ),
				field( __( 'Maximum', 'okno' ), numberInput( maxId, block.max || null, function ( v ) {
					block.max = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0, placeholder: __( 'No limit', 'okno' ) } ), null, maxId ),
			] ),
			h( 'div', { class: 'okno-subfields' }, [
				h( 'h4', { text: __( 'Fields of this block', 'okno' ) } ),
				renderFieldList( block.fields, depth + 1, path + '.fields' ),
			] ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Erreurs du serveur (chemins relatifs à la collection)
	 * ------------------------------------------------------------------ */

	function errorsFor( path ) {
		return ( state.current ? state.current.errors : [] )
			.filter( function ( e ) {
				return e.path === path;
			} )
			.map( function ( e ) {
				return e.message;
			} );
	}

	var PROP_LABELS = {
		key: __( 'Key', 'okno' ),
		name: __( 'Name in the API', 'okno' ),
		type: __( 'Type', 'okno' ),
		fields: __( 'Sub-fields', 'okno' ),
		blocks: __( 'Block types', 'okno' ),
		choices: __( 'Choices', 'okno' ),
		to: __( 'Points to', 'okno' ),
		min: __( 'Minimum', 'okno' ),
		max: __( 'Maximum', 'okno' ),
	};

	/** Erreurs d'un champ (own : le champ lui-même et ses options ; nested : sous-champs). */
	function collectErrors( path ) {
		var own = [];
		var nested = false;
		( state.current ? state.current.errors : [] ).forEach( function ( e ) {
			if ( e.path === path ) {
				own.push( e.message );
				return;
			}
			if ( 0 !== e.path.indexOf( path + '.' ) ) {
				return;
			}
			var rest = e.path.slice( path.length + 1 );
			// fields.N… ou blocks.N… : erreur d'un descendant ; sinon une option du champ.
			if ( /^(fields|blocks)\.\d+/.test( rest ) ) {
				nested = true;
			} else {
				var prop = rest.split( '.' )[ 0 ];
				own.push( ( PROP_LABELS[ prop ] || prop ) + ' — ' + e.message );
			}
		} );
		return { own: own, nested: nested };
	}

	/* ------------------------------------------------------------------ *
	 * Colonnes archivées
	 * ------------------------------------------------------------------ */

	function renderArchived( item ) {
		var archived = item.archived || [];
		var card = h( 'div', { class: 'okno-card' }, [
			h( 'div', { class: 'okno-card__head' }, [
				h( 'h3', { text: __( 'Archived columns', 'okno' ) } ),
				h( 'p', { class: 'okno-muted', text: __( 'Values of removed or retyped fields. They are kept until you purge them.', 'okno' ) } ),
			] ),
		] );
		if ( ! archived.length ) {
			card.appendChild( h( 'p', { class: 'okno-help', text: __( 'No archived columns.', 'okno' ) } ) );
			return card;
		}
		card.appendChild(
			h( 'table', { class: 'okno-table' }, [
				h( 'thead', {}, h( 'tr', {}, [
					h( 'th', { scope: 'col', text: __( 'Field', 'okno' ) } ),
					h( 'th', { scope: 'col', text: __( 'Column', 'okno' ) } ),
					h( 'th', { scope: 'col', text: __( 'Why', 'okno' ) } ),
					h( 'th', { scope: 'col', text: __( 'Archived', 'okno' ) } ),
				] ) ),
				h( 'tbody', {}, archived.map( function ( col ) {
					return h( 'tr', {}, [
						h( 'td', {}, [ col.name || col.key, ' ', h( 'span', { class: 'okno-type-badge', text: ( TYPE_INDEX[ col.type ] || { label: col.type } ).label } ) ] ),
						h( 'td', {}, h( 'code', { text: col.column } ) ),
						h( 'td', { text: 'retyped' === col.reason ? __( 'Type changed', 'okno' ) : __( 'Field removed', 'okno' ) } ),
						h( 'td', { text: App.formatDate( col.archived_at ) } ),
					] );
				} ) ),
			] )
		);
		card.appendChild(
			h( 'div', { class: 'okno-actions' }, h( 'button', { type: 'button', class: 'okno-btn okno-btn--danger', onClick: function () {
				purge( item );
			} }, [ icon( 'archive' ), __( 'Purge archived columns', 'okno' ) ] ) )
		);
		return card;
	}

	function purge( item ) {
		var id = item.collection.id;
		App.confirm( {
			title: __( 'Purge archived columns?', 'okno' ),
			message: __( 'Their values are deleted from the database for good. Revisions of entries keep a copy, but the columns can’t be restored.', 'okno' ),
			confirmLabel: __( 'Purge', 'okno' ),
			danger: true,
			typeToConfirm: id,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/okno/v1/collections/' + id + '/purge', method: 'POST', data: { confirm: id } } )
				.then( function ( body ) {
					var count = ( body.dropped || [] ).length;
					/* translators: %d: number of columns. */
					App.toast( sprintf( _n( '%d column purged.', '%d columns purged.', count, 'okno' ), count ) );
					return load( id );
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Plan de migration et application
	 * ------------------------------------------------------------------ */

	/** Clés des champs enregistrés retirés du brouillon (pour l'avertissement). */
	function removedFields( before, after, prefix, out ) {
		var keep = {};
		( after || [] ).forEach( function ( f ) {
			keep[ f.key ] = f;
		} );
		( before || [] ).forEach( function ( f ) {
			var now = keep[ f.key ];
			if ( ! now ) {
				out.push( { label: ( prefix ? prefix + ' › ' : '' ) + ( f.label || f.name ), nested: !! prefix } );
				return;
			}
			if ( f.fields && now.fields ) {
				removedFields( f.fields, now.fields, ( prefix ? prefix + ' › ' : '' ) + ( f.label || f.name ), out );
			}
			if ( f.blocks && now.blocks ) {
				var blocks = {};
				now.blocks.forEach( function ( b ) {
					blocks[ b.key ] = b;
				} );
				f.blocks.forEach( function ( b ) {
					var label = ( prefix ? prefix + ' › ' : '' ) + ( f.label || f.name ) + ' › ' + ( b.label || b.name );
					if ( ! blocks[ b.key ] ) {
						out.push( { label: label, nested: true } );
					} else {
						removedFields( b.fields, blocks[ b.key ].fields, label, out );
					}
				} );
			}
		} );
		return out;
	}

	function fieldLabelByKey( key ) {
		var d = state.current.draft;
		var found = ( d.fields || [] ).find( function ( f ) {
			return f.key === key;
		} );
		if ( found ) {
			return found.label || found.name;
		}
		var archived = state.current.item && ( state.current.item.collection.fields || [] ).find( function ( f ) {
			return f.key === key;
		} );
		return archived ? archived.label || archived.name : key;
	}

	function describeOp( op ) {
		var label = op.key ? fieldLabelByKey( op.key ) : '';
		switch ( op.op ) {
			case 'create_table':
				/* translators: %s: collection id. */
				return sprintf( __( 'Create the table of “%s”.', 'okno' ), op.collection );
			case 'add_column':
				/* translators: 1: field label, 2: SQL column type. */
				return sprintf( __( 'Add a column for “%1$s” (%2$s).', 'okno' ), label, op.sql );
			case 'restore':
				/* translators: %s: field label. */
				return sprintf( __( 'Bring back the archived values of “%s”.', 'okno' ), label );
			case 'retype':
				/* translators: 1: field label, 2: previous type, 3: new type. */
				return sprintf( __( 'Convert “%1$s” from %2$s to %3$s: values move to a new column, the old one is archived.', 'okno' ), label, typeLabel( op.from_type ), typeLabel( op.to_type ) );
			case 'archive':
				/* translators: %s: field label. */
				return sprintf( __( 'Archive the column of “%s” (values kept).', 'okno' ), label );
			case 'remove_collection':
				/* translators: %s: collection id. */
				return sprintf( __( 'Remove the collection “%s” from Okno (entries and values kept).', 'okno' ), op.collection );
			case 'create_entry':
				/* translators: %s: collection id. */
				return sprintf( __( 'Create the entry of “%s”.', 'okno' ), op.collection );
		}
		return op.op;
	}

	function typeLabel( type ) {
		return ( TYPE_INDEX[ type ] || { label: type } ).label;
	}

	function payload() {
		var d = strip( state.current.draft );
		if ( ! d.singular_label ) {
			d.singular_label = d.label;
		}
		return d;
	}

	function request( dryRun ) {
		var cur = state.current;
		var d = payload();
		var path = cur.isNew ? '/okno/v1/collections' : '/okno/v1/collections/' + encodeURIComponent( cur.item.collection.id );
		return App.api( {
			path: path + ( dryRun ? '?dry_run=1' : '' ),
			method: cur.isNew ? 'POST' : 'PUT',
			data: d,
		} );
	}

	function showErrors( err ) {
		var errors = err && err.data && err.data.errors ? err.data.errors : null;
		if ( errors ) {
			state.current.errors = errors;
			renderEditor();
			var first = editorEl.querySelector( '.has-error, .okno-notice--error' );
			if ( first ) {
				first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
			/* translators: %d: number of problems. */
			App.toast( sprintf( _n( '%d problem to fix before applying.', '%d problems to fix before applying.', errors.length, 'okno' ), errors.length ), true );
			return;
		}
		App.toast( App.errorMessage( err ), true );
	}

	function review() {
		if ( state.busy ) {
			return;
		}
		state.busy = true;
		applyBtn.disabled = true;
		state.current.errors = [];
		request( true )
			.then( function ( report ) {
				state.busy = false;
				applyBtn.disabled = false;
				renderEditor();
				showPlan( report );
			} )
			.catch( function ( err ) {
				state.busy = false;
				applyBtn.disabled = false;
				showErrors( err );
			} );
	}

	function planBody( report, extra ) {
		var ops = report.operations || [];
		var children = [];
		if ( ops.length ) {
			children.push( h( 'p', { text: __( 'Okno will make these changes to the database:', 'okno' ) } ) );
			children.push( h( 'ul', { class: 'okno-plan' }, ops.map( function ( op ) {
				var danger = 'retype' === op.op || 'archive' === op.op || 'remove_collection' === op.op;
				return h( 'li', { class: danger ? 'is-warn' : '' }, [ icon( danger ? 'warning' : 'check', 14 ), describeOp( op ) ] );
			} ) ) );
		} else {
			children.push( h( 'p', { text: __( 'No database change needed: only the field definitions are updated (labels, options, sub-fields, order).', 'okno' ) } ) );
		}
		( report.warnings || [] ).forEach( function ( w ) {
			children.push( h( 'p', { class: 'okno-notice okno-notice--warn', text: 'string' === typeof w ? w : w.message || JSON.stringify( w ) } ) );
		} );
		( extra || [] ).forEach( function ( node ) {
			children.push( node );
		} );
		return children;
	}

	function showPlan( report ) {
		var cur = state.current;
		var extra = [];
		if ( ! cur.isNew ) {
			var removed = removedFields( cur.item.collection.fields, cur.draft.fields, '', [] ).filter( function ( r ) {
				return r.nested;
			} );
			if ( removed.length ) {
				extra.push( h( 'div', { class: 'okno-notice okno-notice--warn' }, [
					h( 'p', { text: __( 'Removed sub-fields lose their values the next time each entry is saved (revisions keep them):', 'okno' ) } ),
					h( 'ul', {}, removed.map( function ( r ) {
						return h( 'li', { text: r.label } );
					} ) ),
				] ) );
			}
		}
		App.modal( {
			title: cur.isNew ? __( 'Create this collection?', 'okno' ) : __( 'Apply these changes?', 'okno' ),
			body: planBody( report, extra ),
			wide: true,
			actions: [
				{ label: __( 'Keep editing', 'okno' ) },
				{
					label: cur.isNew ? __( 'Create', 'okno' ) : __( 'Apply', 'okno' ),
					primary: true,
					onClick: function ( close, btn ) {
						btn.disabled = true;
						apply().then( close, function () {
							btn.disabled = false;
							close();
						} );
					},
				},
			],
		} );
	}

	function apply() {
		var cur = state.current;
		var id = cur.isNew ? payload().id : cur.item.collection.id;
		return request( false )
			.then( function ( report ) {
				state.dirty = false;
				var converted = report.converted || {};
				var failed = 0;
				Object.keys( converted ).forEach( function ( k ) {
					failed += Number( converted[ k ].failed || 0 );
				} );
				if ( failed ) {
					/* translators: %d: number of values. */
					App.toast( sprintf( _n( '%d value couldn’t be converted: it stays in the archived column.', '%d values couldn’t be converted: they stay in the archived column.', failed, 'okno' ), failed ), true );
				}
				( report.warnings || [] ).forEach( function ( w ) {
					App.toast( 'string' === typeof w ? w : w.message || '', true );
				} );
				App.toast( cur.isNew ? __( 'Collection created.', 'okno' ) : __( 'Changes applied.', 'okno' ) );
				App.notifyChanged();
				state.current = null;
				return load( id );
			} )
			.catch( function ( err ) {
				showErrors( err );
				throw err;
			} );
	}

	function removeCollection() {
		var c = state.current.item.collection;
		App.confirm( {
			/* translators: %s: collection label. */
			title: sprintf( __( 'Remove “%s”?', 'okno' ), c.label ),
			message: __( 'The collection disappears from Okno, the editor and the content API. Its entries and their values are kept in the database: creating it again with the same identifier brings everything back.', 'okno' ),
			confirmLabel: __( 'Remove', 'okno' ),
			danger: true,
			typeToConfirm: c.id,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/okno/v1/collections/' + encodeURIComponent( c.id ), method: 'DELETE' } )
				.then( function () {
					App.toast( __( 'Collection removed. Its data is kept.', 'okno' ) );
					state.dirty = false;
					state.current = null;
					setUrl( '' );
					App.notifyChanged();
					return load();
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Import / export d'okno.schema.json
	 * ------------------------------------------------------------------ */

	function exportSchema() {
		App.api( { path: '/okno/v1/collections/export' } )
			.then( function ( schema ) {
				var blob = new Blob( [ JSON.stringify( schema, null, 2 ) + '\n' ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = h( 'a', { href: url, download: 'okno.schema.json', hidden: true } );
				document.body.appendChild( a );
				a.click();
				a.remove();
				setTimeout( function () {
					URL.revokeObjectURL( url );
				}, 1000 );
			} )
			.catch( function ( err ) {
				App.toast( App.errorMessage( err ), true );
			} );
	}

	function importSchema( file ) {
		var reader = new FileReader();
		reader.onload = function () {
			var data;
			try {
				data = JSON.parse( String( reader.result ) );
			} catch ( e ) {
				App.toast( __( 'The file is not valid JSON.', 'okno' ), true );
				return;
			}
			App.api( { path: '/okno/v1/collections/import?dry_run=1', method: 'POST', data: data } )
				.then( function ( report ) {
					var ids = ( data.collections || [] ).map( function ( c ) {
						return c && c.id;
					} ).filter( Boolean );
					App.modal( {
						/* translators: %s: file name. */
						title: sprintf( __( 'Import %s?', 'okno' ), file.name ),
						wide: true,
						body: [
							h( 'p', { text: sprintf(
								/* translators: %s: list of collection ids. */
								__( 'The file replaces the whole schema with: %s.', 'okno' ),
								ids.join( ', ' ) || '—'
							) } ),
						].concat( planBody( report ) ),
						actions: [
							{ label: __( 'Cancel', 'okno' ) },
							{
								label: __( 'Import', 'okno' ),
								primary: true,
								onClick: function ( close, btn ) {
									btn.disabled = true;
									App.api( { path: '/okno/v1/collections/import', method: 'POST', data: data } )
										.then( function () {
											close();
											App.toast( __( 'Schema imported.', 'okno' ) );
											state.dirty = false;
											state.current = null;
											App.notifyChanged();
											return load();
										} )
										.catch( function ( err ) {
											btn.disabled = false;
											App.toast( importError( err ), true );
										} );
								},
							},
						],
					} );
				} )
				.catch( function ( err ) {
					App.toast( importError( err ), true );
				} );
		};
		reader.readAsText( file );
	}

	function importError( err ) {
		var errors = err && err.data && err.data.errors;
		if ( errors && errors.length ) {
			return App.errorMessage( err ) + ' ' + errors.slice( 0, 5 ).map( function ( e ) {
				return ( e.path ? e.path + ': ' : '' ) + e.message;
			} ).join( ' · ' );
		}
		return App.errorMessage( err );
	}

	/* ------------------------------------------------------------------ *
	 * Choix du type d'un nouveau champ
	 * ------------------------------------------------------------------ */

	function pickType() {
		return new Promise( function ( resolve ) {
			var picked = null;
			var dialog = App.modal( {
				title: __( 'Add a field', 'okno' ),
				wide: true,
				body: h( 'ul', { class: 'okno-type-grid' }, TYPES.map( function ( t ) {
					return h( 'li', {}, h( 'button', { type: 'button', class: 'okno-type-card', onClick: function () {
						picked = t.type;
						dialog.close();
					} }, [ h( 'strong', { text: t.label } ), h( 'span', { text: t.help } ) ] ) );
				} ) ),
				onClose: function () {
					resolve( picked );
				},
				actions: [ { label: __( 'Cancel', 'okno' ) } ],
			} );
			var first = dialog.el.querySelector( '.okno-type-card' );
			if ( first ) {
				first.focus();
			}
		} );
	}

	load();
} )();
