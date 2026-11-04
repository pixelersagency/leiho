/**
 * Leiho — vue Collections : constructeur de schéma (onglet « Champs » d'une
 * collection Leiho, administrateurs seulement).
 *
 * Monté dans #leiho-builder par app-content.js, qui le pilote par
 * window.LeihoBuilder (show, showNew, confirmLeave, import / export) : la
 * liste des collections est celle du volet de gauche de la vue.
 *
 * Interface posée sur l'API REST leiho/v1/collections (voir
 * docs/collections-spec.md) : liste des collections et singletons, édition
 * d'une collection (libellés, type, chemin, fonctionnalités), éditeur de
 * champs (glisser-déposer, 20 types et leurs options, sous-champs, blocs),
 * aperçu du plan de migration (dry_run) avant application, colonnes
 * archivées et purge, suppression, import / export de leiho.schema.json.
 *
 * La clé d'un champ (key) est son identité stable : générée à partir du nom
 * à la création, elle ne change plus une fois enregistrée. Seuls le nom (API)
 * et le libellé se modifient.
 */
( function () {
	'use strict';

	var App = window.LeihoApp;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	var host = document.getElementById( 'leiho-builder' );
	if ( ! App || ! host ) {
		return;
	}

	var h = App.h, icon = App.icon, cfg = App.cfg;

	/* ------------------------------------------------------------------ *
	 * Types de champ
	 * ------------------------------------------------------------------ */

	var TYPES = [
		{ type: 'text', label: __( 'Text', 'leiho' ), help: __( 'A line or a paragraph of plain text.', 'leiho' ) },
		{ type: 'richtext', label: __( 'Rich text', 'leiho' ), help: __( 'Formatted text: bold, links, lists.', 'leiho' ) },
		{ type: 'code', label: __( 'Code', 'leiho' ), help: __( 'Code or any text kept exactly as typed, in a fixed-width font.', 'leiho' ) },
		{ type: 'number', label: __( 'Number', 'leiho' ), help: __( 'A price, a quantity, a rating.', 'leiho' ) },
		{ type: 'boolean', label: __( 'Yes / no', 'leiho' ), help: __( 'A switch.', 'leiho' ) },
		{ type: 'date', label: __( 'Date', 'leiho' ), help: __( 'A day.', 'leiho' ) },
		{ type: 'datetime', label: __( 'Date and time', 'leiho' ), help: __( 'A day and an hour.', 'leiho' ) },
		{ type: 'select', label: __( 'Choice', 'leiho' ), help: __( 'One or several values from a list.', 'leiho' ) },
		{ type: 'slug', label: __( 'Slug', 'leiho' ), help: __( 'An identifier for URLs.', 'leiho' ) },
		{ type: 'email', label: __( 'Email', 'leiho' ), help: __( 'An email address.', 'leiho' ) },
		{ type: 'url', label: __( 'URL', 'leiho' ), help: __( 'A web address.', 'leiho' ) },
		{ type: 'color', label: __( 'Color', 'leiho' ), help: __( 'A color code.', 'leiho' ) },
		{ type: 'image', label: __( 'Image', 'leiho' ), help: __( 'One image from the media library.', 'leiho' ) },
		{ type: 'file', label: __( 'File', 'leiho' ), help: __( 'A PDF or any document.', 'leiho' ) },
		{ type: 'gallery', label: __( 'Gallery', 'leiho' ), help: __( 'Several images.', 'leiho' ) },
		{ type: 'link', label: __( 'Link', 'leiho' ), help: __( 'A URL with its text and target.', 'leiho' ) },
		{ type: 'reference', label: __( 'Reference', 'leiho' ), help: __( 'Points to entries of other collections.', 'leiho' ) },
		{ type: 'list', label: __( 'List', 'leiho' ), help: __( 'Repeated rows of sub-fields.', 'leiho' ) },
		{ type: 'blocks', label: __( 'Blocks', 'leiho' ), help: __( 'Sections of different kinds, in any order.', 'leiho' ) },
		{ type: 'group', label: __( 'Group', 'leiho' ), help: __( 'Sub-fields grouped together.', 'leiho' ) },
	];
	var TYPE_INDEX = {};
	TYPES.forEach( function ( t ) {
		TYPE_INDEX[ t.type ] = t;
	} );

	var SUPPORTS = [
		{ value: 'editor', label: __( 'Main text (WordPress editor)', 'leiho' ) },
		{ value: 'excerpt', label: __( 'Excerpt', 'leiho' ) },
		{ value: 'thumbnail', label: __( 'Featured image', 'leiho' ) },
		{ value: 'page-attributes', label: __( 'Order and parent', 'leiho' ) },
	];

	var LENGTH_TYPES = [ 'text', 'code', 'slug', 'email', 'url' ];
	var CONTAINERS = [ 'list', 'group' ];
	var MAX_DEPTH = 8;

	/* ------------------------------------------------------------------ *
	 * État
	 * ------------------------------------------------------------------ */

	var state = {
		items: [], // Réponses GET /collections : { collection, table, columns, archived, entry }.
		loaded: null, // Promesse du premier chargement.
		current: null, // { isNew, item, draft, errors: [] }.
		dirty: false,
		busy: false,
	};

	// Branchements de la vue Collections (app-content.js).
	var hooks = {
		headTools: function () {
			return null;
		},
		onSaved: function () {},
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
		return ! state.dirty || window.confirm( __( 'Your changes to this collection aren’t applied yet. Leave anyway?', 'leiho' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Squelette : champs (milieu) et réglages (droite)
	 * ------------------------------------------------------------------ */

	var importInput = h( 'input', { type: 'file', accept: 'application/json,.json', hidden: true } );
	// Volets du milieu (champs) et de droite (réglages), refaits à chaque rendu.
	var editorEl = h( 'div', { class: 'leiho-builder__editor', 'aria-live': 'off' } );
	var applyBtn = null;
	var dirtyNote = null;

	App.append( App.clear( host ), [ editorEl, importInput ] );

	importInput.addEventListener( 'change', function () {
		var file = importInput.files && importInput.files[ 0 ];
		importInput.value = '';
		if ( file ) {
			importSchema( file );
		}
	} );

	/* ------------------------------------------------------------------ *
	 * Chargement
	 * ------------------------------------------------------------------ */

	function load() {
		state.loaded = App.api( { path: '/leiho/v1/collections' } )
			.then( function ( body ) {
				state.items = body.collections || [];
				return state.items;
			} )
			.catch( function ( err ) {
				state.loaded = null;
				App.clear( editorEl ).appendChild( h( 'p', { class: 'leiho-list-empty', text: App.errorMessage( err, __( 'Couldn’t load the collections.', 'leiho' ) ) } ) );
				throw err;
			} );
		return state.loaded;
	}

	/** Collection id ouverte dans le constructeur (true si elle existe). */
	function show( id ) {
		return ( state.loaded || load() ).then( function () {
			var item = state.items.find( function ( i ) {
				return i.collection.id === id;
			} );
			if ( ! item ) {
				renderMissing();
				return false;
			}
			if ( ! state.current || state.current.isNew || state.current.item.collection.id !== id ) {
				open( item );
			}
			return true;
		} );
	}

	function renderMissing() {
		state.current = null;
		state.dirty = false;
		App.clear( editorEl ).appendChild(
			h( 'section', { class: 'leiho-pane leiho-pane--detail' }, h( 'div', { class: 'leiho-detail__body' }, h( 'div', { class: 'leiho-empty-state' }, [
				h( 'span', { class: 'leiho-empty-state__icon' }, icon( 'database', 22 ) ),
				h( 'h3', { text: __( 'This collection doesn’t exist anymore', 'leiho' ) } ),
				h( 'p', { text: __( 'It may have been removed or renamed. Reload the page to see the current collections.', 'leiho' ) } ),
			] ) ) )
		);
	}

	function open( item ) {
		var draft = clone( item.collection );
		prepare( draft.fields );
		state.current = { isNew: false, item: item, draft: draft, errors: [], selected: 'general' };
		state.dirty = false;
		renderEditor();
	}

	function openNew() {
		state.current = {
			isNew: true,
			item: null,
			draft: { id: '', kind: 'collection', label: '', singular_label: '', path: '', supports: [], icon: '', fields: [] },
			errors: [],
			idTouched: false,
			selected: 'general',
		};
		state.dirty = false;
		renderEditor();
		var first = editorEl.querySelector( '.leiho-pane--detail input' );
		if ( first ) {
			first.focus();
		}
	}

	/** Après création, application, suppression ou import : la vue se recharge. */
	function done( id ) {
		state.dirty = false;
		hooks.onSaved( id || '' );
	}

	window.LeihoBuilder = {
		setHooks: function ( next ) {
			Object.keys( next || {} ).forEach( function ( k ) {
				hooks[ k ] = next[ k ];
			} );
		},
		show: show,
		showNew: openNew,
		isDirty: function () {
			return state.dirty;
		},
		confirmLeave: confirmLeave,
		/** Oublie les changements non appliqués (sortie confirmée). */
		discard: function () {
			state.dirty = false;
			state.current = null;
		},
		exportSchema: exportSchema,
		importSchema: function () {
			if ( confirmLeave() ) {
				importInput.click();
			}
		},
	};

	/* ------------------------------------------------------------------ *
	 * Éditeur d'une collection : champs (milieu) et réglages (droite)
	 * ------------------------------------------------------------------ */

	/** Champ racine sélectionné (ou null : réglages de la collection). */
	function selectedField() {
		var cur = state.current;
		if ( ! cur || 'general' === cur.selected ) {
			return null;
		}
		var index = cur.draft.fields.findIndex( function ( f ) {
			return f._uid === cur.selected;
		} );
		if ( -1 === index ) {
			cur.selected = 'general';
			return null;
		}
		return { field: cur.draft.fields[ index ], index: index };
	}

	function select( uidOrGeneral, focusSelector ) {
		state.current.selected = uidOrGeneral;
		renderEditor( focusSelector );
	}

	function renderEditor( focusSelector ) {
		var oldBody = editorEl.querySelector( '.leiho-detail__body' );
		var oldList = editorEl.querySelector( '.leiho-builder__fields .leiho-pane__body' );
		var sameSelection = editorEl.dataset.selected === ( state.current && state.current.selected );
		var scroll = oldBody && sameSelection ? oldBody.scrollTop : 0;
		var listScroll = oldList ? oldList.scrollTop : 0;
		var cur = state.current;
		var d = cur.draft;
		var sel = selectedField();
		App.clear( editorEl );
		editorEl.dataset.selected = cur.selected;

		var title = cur.isNew ? __( 'New collection', 'leiho' ) : d.label || d.id;
		applyBtn = h( 'button', { type: 'button', class: 'leiho-btn', disabled: ! state.dirty && ! cur.isNew, onClick: review }, cur.isNew ? __( 'Review and create', 'leiho' ) : __( 'Review and apply', 'leiho' ) );
		dirtyNote = h( 'span', { class: 'leiho-dirty-note', hidden: ! state.dirty, text: __( 'Changes not applied yet', 'leiho' ) } );

		/* Volet du milieu : réglages de la collection, puis ses champs. */
		var general = errorsFor( '' ).concat( errorsFor( 'id' ), errorsFor( 'label' ), errorsFor( 'path' ) );
		var fieldsBody = h( 'div', { class: 'leiho-pane__body' }, [
			h( 'ul', { class: 'leiho-rows' }, h( 'li', { class: 'leiho-item' + ( sel ? '' : ' is-active' ) + ( general.length ? ' has-error' : '' ) }, h( 'button', {
				type: 'button',
				class: 'leiho-item__link leiho-select-general',
				'aria-pressed': sel ? 'false' : 'true',
				onClick: function () {
					select( 'general' );
				},
			}, [
				h( 'span', { class: 'leiho-coll__icon' }, icon( 'settings', 14 ) ),
				h( 'span', { class: 'leiho-item__title', text: __( 'General', 'leiho' ) } ),
			] ) ) ),
			h( 'h3', { class: 'leiho-group-label', text: __( 'Fields', 'leiho' ) } ),
			renderRootList( d.fields ),
			h( 'p', { class: 'leiho-help leiho-pane__hint', text: __( 'Drag the handle or use the arrows to reorder. The title (and the features ticked above) come with every entry.', 'leiho' ) } ),
		] );

		var fieldsPane = h( 'section', { class: 'leiho-pane leiho-pane--list leiho-builder__fields', 'aria-label': __( 'Fields', 'leiho' ) }, [
			h( 'div', { class: 'leiho-pane__head' }, [
				h( 'h2', { class: 'leiho-pane__title', text: title, title: title } ),
				h( 'div', { class: 'leiho-pane__tools' }, [
					cur.isNew ? null : hooks.headTools(),
					h( 'button', { type: 'button', class: 'leiho-icon-btn', title: __( 'Add a field', 'leiho' ), 'aria-label': __( 'Add a field', 'leiho' ), onClick: function ( event ) {
						pickType( event.currentTarget ).then( function ( type ) {
							if ( type ) {
								addField( d.fields, type );
							}
						} );
					} }, icon( 'plus' ) ),
				] ),
			] ),
			fieldsBody,
		] );

		/* Volet de droite : réglages de la sélection. */
		var headActions = [ dirtyNote, applyBtn ];

		var detailTitle = sel ? sel.field.label || sel.field.name : __( 'General', 'leiho' );
		var head = h( 'div', { class: 'leiho-detail__head' }, [
			h( 'span', { class: 'leiho-detail__icon' + ( sel ? '' : ' leiho-detail__icon--purple' ) }, sel ? h( 'span', { class: 'leiho-type-tag', text: typeAbbr( sel.field.type ) } ) : icon( 'database', 15 ) ),
			h( 'h2', { class: 'leiho-detail__title', dataset: sel ? { uid: sel.field._uid } : {}, text: detailTitle } ),
			sel ? h( 'code', { class: 'leiho-detail__path leiho-fieldrow__name', text: sel.field.name } ) : cur.isNew ? null : h( 'span', { class: 'leiho-detail__path', text: ( 'singleton' === d.kind ? __( 'Single page', 'leiho' ) : __( 'Collection', 'leiho' ) ) + ' · ' + d.id } ),
			h( 'div', { class: 'leiho-detail__actions' }, headActions ),
		] );

		var body = h( 'div', { class: 'leiho-detail__body' } );
		if ( general.length && ! sel ) {
			body.appendChild( h( 'div', { class: 'leiho-detail__card leiho-notice leiho-notice--error', role: 'alert' }, errorsFor( '' ).map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) );
		}
		if ( sel ) {
			var path = 'fields.' + sel.index;
			var errors = collectErrors( path );
			body.appendChild( h( 'div', { class: 'leiho-detail__card', dataset: { uid: sel.field._uid } }, h( 'div', { class: 'leiho-field-settings' }, renderFieldSettings( d.fields, sel.field, 1, path, errors.own ) ) ) );
		} else {
			body.appendChild( renderGeneral( d, cur ) );
			if ( ! cur.isNew ) {
				// Contexte de la collection pour l'IA (ai.js), enregistré à part.
				var aiCard = window.LeihoAI ? window.LeihoAI.collectionCard( d.id ) : null;
				if ( aiCard ) {
					body.appendChild( aiCard );
				}
				body.appendChild( renderArchived( cur.item ) );
				var imported = cur.item.acf_import;
				if ( imported ) {
					body.appendChild( renderImported( cur.item ) );
				} else body.appendChild(
					h( 'div', { class: 'leiho-detail__card' }, h( 'div', { class: 'leiho-danger-zone' }, [
						h( 'div', {}, [
							h( 'h3', { text: __( 'Remove this collection', 'leiho' ) } ),
							h( 'p', { class: 'leiho-muted', text: __( 'It disappears from Leiho and the content API. Its entries and their values are kept: create it again with the same id to get everything back.', 'leiho' ) } ),
						] ),
						h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--danger', onClick: removeCollection }, [ icon( 'trash' ), __( 'Remove', 'leiho' ) ] ),
					] ) )
				);
			}
		}

		App.append( editorEl, [
			fieldsPane,
			h( 'section', { class: 'leiho-pane leiho-pane--detail', 'aria-label': detailTitle }, [ head, body ] ),
		] );

		body.scrollTop = scroll;
		fieldsBody.scrollTop = listScroll;
		if ( focusSelector ) {
			var target = editorEl.querySelector( focusSelector );
			if ( target ) {
				target.focus();
			}
		}
	}

	/**
	 * Collection importée d'ACF : retour à ACF (à la place de la suppression).
	 */
	function renderImported( item ) {
		var info = item.acf_import;
		var date = info.finished ? new Date( info.finished ).toLocaleDateString() : '';
		return h( 'div', { class: 'leiho-detail__card' }, h( 'div', { class: 'leiho-danger-zone' }, [
			h( 'div', {}, [
				h( 'h3', { text: __( 'Go back to ACF', 'leiho' ) } ),
				h( 'p', { class: 'leiho-muted', text: date
					? /* translators: %s: date of the import. */
					  sprintf( __( 'Imported from ACF on %s. ACF still has the values of that day: going back serves them again and deletes the Leiho values of this collection.', 'leiho' ), date )
					: __( 'ACF still has the values of the import day: going back serves them again and deletes the Leiho values of this collection.', 'leiho' ) } ),
			] ),
			h( 'button', {
				type: 'button',
				class: 'leiho-btn leiho-btn--danger',
				disabled: ! info.can_rollback,
				title: info.can_rollback ? null : __( 'Activate ACF (or Secure Custom Fields) first.', 'leiho' ),
				onClick: function () {
					if ( window.LeihoAcfImport ) {
						window.LeihoAcfImport.rollback( item.collection.id, item.collection.label );
					}
				},
			}, [ icon( 'restore' ), __( 'Go back to ACF', 'leiho' ) ] ),
		] ) );
	}

	/** Deux lettres pour la pastille de type (Te, Nu, Li…). */
	function typeAbbr( type ) {
		var label = ( TYPE_INDEX[ type ] || { label: type } ).label;
		return label.slice( 0, 2 );
	}

	function field( labelText, control, help, id ) {
		return h( 'div', { class: 'leiho-setting' }, [
			h( 'label', { class: 'leiho-label', for: id, text: labelText } ),
			control,
			help ? h( 'p', { class: 'leiho-help', text: help } ) : null,
		] );
	}

	function renderGeneral( d, cur ) {
		var labelId = App.uid( 'label' );
		var singularId = App.uid( 'singular' );
		var idId = App.uid( 'id' );
		var pathId = App.uid( 'path' );

		var idInput = h( 'input', { type: 'text', id: idId, class: 'leiho-input leiho-mono', value: d.id, readOnly: ! cur.isNew, maxlength: '20', 'aria-describedby': idId + '-help' } );
		var label = h( 'input', { type: 'text', id: labelId, class: 'leiho-input', value: d.label, placeholder: __( 'Services', 'leiho' ) } );
		var singular = h( 'input', { type: 'text', id: singularId, class: 'leiho-input', value: d.singular_label === d.label ? '' : d.singular_label, placeholder: __( 'Service', 'leiho' ) } );
		var path = h( 'input', { type: 'text', id: pathId, class: 'leiho-input leiho-mono', value: d.path, placeholder: 'singleton' === d.kind ? '/contact' : '/services/{slug}' } );

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
		var kinds = h( 'fieldset', { class: 'leiho-setting leiho-radios' }, [
			h( 'legend', { class: 'leiho-label', text: __( 'Kind', 'leiho' ) } ),
			[
				{ value: 'collection', label: __( 'Collection', 'leiho' ), help: __( 'Many entries (services, articles, testimonials).', 'leiho' ) },
				{ value: 'singleton', label: __( 'Single page', 'leiho' ), help: __( 'Exactly one entry (home, contact, site settings).', 'leiho' ) },
			].map( function ( k ) {
				var input = h( 'input', { type: 'radio', name: kindName, value: k.value, checked: d.kind === k.value } );
				input.addEventListener( 'change', function () {
					d.kind = k.value;
					markDirty();
				} );
				return h( 'label', { class: 'leiho-radio' }, [ input, h( 'span', {}, [ h( 'strong', { text: k.label } ), h( 'span', { class: 'leiho-help', text: k.help } ) ] ) ] );
			} ),
		] );

		var features = h( 'fieldset', { class: 'leiho-setting leiho-checks-inline' }, [
			h( 'legend', { class: 'leiho-label', text: __( 'Built-in features', 'leiho' ) } ),
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
				return h( 'label', { class: 'leiho-check' }, [ input, s.label ] );
			} ),
		] );

		var idErrors = errorsFor( 'id' ).concat( errorsFor( 'label' ) );
		var pathErrors = errorsFor( 'path' );

		// Type déclaré par le thème, une extension ou ACF : Leiho n'en gère que
		// les champs (libellés, nature, URL et fonctionnalités viennent d'ailleurs).
		if ( d.external ) {
			return h( 'div', { class: 'leiho-detail__card' }, [
				h( 'h3', { text: __( 'General', 'leiho' ) } ),
				h( 'p', { class: 'leiho-muted', text: 'acf' === d.source
					? __( 'These fields come from ACF. The content type itself (labels, URL, features) is still declared where it was: in ACF, the theme or a plugin. Leiho manages its fields.', 'leiho' )
					: __( 'This content type is declared by the theme or a plugin. Leiho manages its fields.', 'leiho' ) } ),
				h( 'div', { class: 'leiho-settings-grid' }, [
					h( 'div', { class: 'leiho-setting' }, [
						h( 'label', { class: 'leiho-label', for: idId, text: __( 'Identifier', 'leiho' ) } ),
						idInput,
					] ),
					field( __( 'Name (plural)', 'leiho' ), label, __( 'Shown in Leiho.', 'leiho' ), labelId ),
				] ),
			] );
		}

		return h( 'div', { class: 'leiho-detail__card' }, [
			h( 'h3', { text: __( 'General', 'leiho' ) } ),
			h( 'div', { class: 'leiho-settings-grid' }, [
				field( __( 'Name (plural)', 'leiho' ), label, null, labelId ),
				field( __( 'Name of one entry', 'leiho' ), singular, __( 'Optional. Used in “New service”.', 'leiho' ), singularId ),
				h( 'div', { class: 'leiho-setting' + ( idErrors.length ? ' has-error' : '' ) }, [
					h( 'label', { class: 'leiho-label', for: idId, text: __( 'Identifier', 'leiho' ) } ),
					idInput,
					h( 'p', { class: 'leiho-help', id: idId + '-help', text: cur.isNew ? __( 'Content type and table name. Lowercase letters, digits and underscores; it can’t change later.', 'leiho' ) : __( 'The identifier never changes.', 'leiho' ) } ),
					idErrors.map( errorLine ),
				] ),
				h( 'div', { class: 'leiho-setting' + ( pathErrors.length ? ' has-error' : '' ) }, [
					h( 'label', { class: 'leiho-label', for: pathId, text: __( 'URL on the site', 'leiho' ) } ),
					path,
					h( 'p', { class: 'leiho-help', text: __( 'Where an entry is shown on the site, with {slug} or {id}. Leave empty for content without a page (testimonials, settings): its entries are then edited in Collections only, without the visual editor.', 'leiho' ) } ),
					pathErrors.map( errorLine ),
				] ),
				kinds,
				features,
			] ),
		] );
	}

	function errorLine( message ) {
		return h( 'p', { class: 'leiho-error', role: 'alert', text: message } );
	}

	/* ------------------------------------------------------------------ *
	 * Champs
	 * ------------------------------------------------------------------ */

	var drag = null; // { list, index } pendant un glisser.

	/**
	 * Champs de premier niveau (volet du milieu) : une ligne de 32 px par
	 * champ ; un clic ouvre ses réglages dans le volet de droite.
	 */
	function renderRootList( list ) {
		var ol = h( 'ol', { class: 'leiho-rows leiho-fields leiho-fields--root' } );
		list.forEach( function ( f, index ) {
			ol.appendChild( renderRootRow( list, f, index ) );
		} );
		if ( ! list.length ) {
			ol.appendChild( h( 'li', { class: 'leiho-list-empty', text: __( 'No fields yet. Each entry still has a title.', 'leiho' ) } ) );
		}
		ol.appendChild( h( 'li', {}, h( 'button', { type: 'button', class: 'leiho-item leiho-item--add leiho-add-field', onClick: function ( event ) {
			pickType( event.currentTarget ).then( function ( type ) {
				if ( type ) {
					addField( list, type );
				}
			} );
		} }, [ icon( 'plus', 13 ), __( 'Add a field', 'leiho' ) ] ) ) );
		return ol;
	}

	function renderRootRow( list, f, index ) {
		var path = 'fields.' + index;
		var errors = collectErrors( path );
		var active = state.current.selected === f._uid;
		var label = f.label || f.name;
		var grip = h( 'span', { class: 'leiho-grip', title: __( 'Drag to reorder', 'leiho' ), 'aria-hidden': 'true' }, icon( 'grip', 12 ) );
		var li = h( 'li', {
			class: 'leiho-item' + ( active ? ' is-active' : '' ) + ( errors.own.length || errors.nested ? ' has-error' : '' ),
			dataset: { uid: f._uid },
		}, [
			grip,
			h( 'button', {
				type: 'button',
				class: 'leiho-item__link leiho-field-select',
				'aria-pressed': active ? 'true' : 'false',
				onClick: function () {
					select( f._uid, '[data-uid="' + f._uid + '"] .leiho-field-label-input' );
				},
			}, [
				h( 'span', { class: 'leiho-type-tag', title: ( TYPE_INDEX[ f.type ] || { label: f.type } ).label, text: typeAbbr( f.type ) } ),
				h( 'span', { class: 'leiho-item__title leiho-fieldrow__label', text: label + ( f.required ? ' *' : '' ) } ),
				h( 'code', { class: 'leiho-item__meta leiho-fieldrow__name', text: f.name } ),
			] ),
			rowActions( list, f, index, label ),
		] );
		bindDrag( li, grip, list, index );
		return li;
	}

	/** Monter, descendre, retirer : au survol de la ligne (et au clavier). */
	function rowActions( list, f, index, label ) {
		return h( 'div', { class: 'leiho-item__actions leiho-fieldrow__actions' }, [
			h( 'button', {
				type: 'button',
				class: 'leiho-icon-btn leiho-move-up',
				disabled: 0 === index,
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Move “%s” up', 'leiho' ), label ),
				title: __( 'Move up', 'leiho' ),
				onClick: function () {
					move( list, index, index - 1 );
					renderEditor( '[data-uid="' + f._uid + '"] .leiho-move-up:not([disabled]), [data-uid="' + f._uid + '"] .leiho-move-down' );
				},
			}, icon( 'up' ) ),
			h( 'button', {
				type: 'button',
				class: 'leiho-icon-btn leiho-move-down',
				disabled: index === list.length - 1,
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Move “%s” down', 'leiho' ), label ),
				title: __( 'Move down', 'leiho' ),
				onClick: function () {
					move( list, index, index + 1 );
					renderEditor( '[data-uid="' + f._uid + '"] .leiho-move-down:not([disabled]), [data-uid="' + f._uid + '"] .leiho-move-up' );
				},
			}, icon( 'down' ) ),
			h( 'button', {
				type: 'button',
				class: 'leiho-icon-btn leiho-icon-btn--danger',
				/* translators: %s: field label. */
				'aria-label': sprintf( __( 'Remove “%s”', 'leiho' ), label ),
				title: __( 'Remove', 'leiho' ),
				onClick: function () {
					removeField( list, index, f );
				},
			}, icon( 'trash' ) ),
		] );
	}

	/**
	 * Liste de sous-champs (d'une liste, d'un groupe, d'un type de bloc) :
	 * lignes repliables dans un cadre, ajout en dernière ligne.
	 * pathPrefix : chemin des erreurs du serveur.
	 */
	function renderFieldList( list, depth, pathPrefix ) {
		var ol = h( 'ol', { class: 'leiho-fields' } );
		list.forEach( function ( f, index ) {
			ol.appendChild( renderFieldRow( list, f, index, depth, pathPrefix + '.' + index ) );
		} );
		var wrap = h( 'div', { class: 'leiho-list-field leiho-fields-wrap' }, [
			list.length ? ol : h( 'div', { class: 'leiho-list-field__empty', text: __( 'No sub-fields yet.', 'leiho' ) } ),
		] );
		if ( depth <= MAX_DEPTH ) {
			wrap.appendChild(
				h( 'button', { type: 'button', class: 'leiho-list-field__add leiho-add-field', onClick: function ( event ) {
					pickType( event.currentTarget ).then( function ( type ) {
						if ( type ) {
							addField( list, type );
						}
					} );
				} }, [ icon( 'plus' ), __( 'Add a sub-field', 'leiho' ) ] )
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
		if ( list === state.current.draft.fields ) {
			state.current.selected = f._uid; // Champ de premier niveau : ses réglages à droite.
		}
		markDirty();
		renderEditor( '[data-uid="' + f._uid + '"] .leiho-field-label-input' );
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
		renderEditor( '[data-uid="' + f._uid + '"] .leiho-field-type-input' );
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
		var bodyId = 'leiho-fieldbody-' + f._uid;
		var meta = TYPE_INDEX[ f.type ] || { label: f.type };
		var errors = collectErrors( path );
		var hasNestedErrors = errors.nested;

		if ( errors.own.length || hasNestedErrors ) {
			f._open = true;
		}

		var toggle = h( 'button', {
			type: 'button',
			class: 'leiho-fieldrow__toggle',
			'aria-expanded': f._open ? 'true' : 'false',
			'aria-controls': bodyId,
			onClick: function () {
				f._open = ! f._open;
				toggle.setAttribute( 'aria-expanded', f._open ? 'true' : 'false' );
				body.hidden = ! f._open;
				li.classList.toggle( 'is-open', f._open );
			},
		}, [
			icon( 'chevron', 12 ),
			h( 'span', { class: 'leiho-type-badge', text: meta.label } ),
			h( 'span', { class: 'leiho-fieldrow__label', text: ( f.label || f.name ) + ( f.required ? ' *' : '' ) } ),
			h( 'code', { class: 'leiho-fieldrow__name', text: f.name } ),
		] );

		var grip = h( 'span', { class: 'leiho-grip', title: __( 'Drag to reorder', 'leiho' ), 'aria-hidden': 'true' }, icon( 'grip', 12 ) );
		var actions = rowActions( list, f, index, f.label || f.name );

		var body = h( 'div', { class: 'leiho-fieldrow__body', id: bodyId, hidden: ! f._open }, renderFieldSettings( list, f, depth, path, errors.own ) );

		var li = h( 'li', {
			class: 'leiho-fieldrow' + ( f._open ? ' is-open' : '' ) + ( errors.own.length || hasNestedErrors ? ' has-error' : '' ),
			dataset: { uid: f._uid },
		}, [ h( 'div', { class: 'leiho-fieldrow__head' }, [ grip, toggle, actions ] ), body ] );

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
				return !! el.dataset.uid;
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
					App.announce( __( 'Field moved.', 'leiho' ) );
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
			? __( 'This field isn’t saved yet: it is simply removed.', 'leiho' )
			: nested
			? __( 'Its values disappear from each entry the next time the entry is saved. Revisions keep the previous values.', 'leiho' )
			: __( 'Its column is archived, not deleted: add a field with the same key and type again to get the values back, or purge archived columns to delete them.', 'leiho' );
		App.confirm( {
			/* translators: %s: field label. */
			title: sprintf( __( 'Remove “%s”?', 'leiho' ), f.label || f.name ),
			message: message,
			confirmLabel: __( 'Remove', 'leiho' ),
			danger: true,
		} ).then( function ( ok ) {
			if ( ok ) {
				list.splice( index, 1 );
				if ( state.current.selected === f._uid ) {
					state.current.selected = 'general';
				}
				markDirty();
				renderEditor();
			}
		} );
	}

	function numberInput( id, value, onValue, attrs ) {
		var input = h( 'input', Object.assign( { type: 'number', id: id, class: 'leiho-input leiho-input--small', value: null === value || undefined === value ? '' : String( value ) }, attrs || {} ) );
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
		return h( 'label', { class: 'leiho-check' }, [ input, labelText ] );
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
			out.push( h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, ownErrors.map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) );
		}

		var labelInput = h( 'input', { type: 'text', id: ids.label, class: 'leiho-input leiho-field-label-input', value: f.label || '' } );
		var nameInput = h( 'input', { type: 'text', id: ids.name, class: 'leiho-input leiho-mono', value: f.name, maxlength: '64', spellcheck: 'false' } );
		var keyNote = h( 'p', { class: 'leiho-help leiho-key-note' } );

		function renderKeyNote() {
			keyNote.textContent = savedField( f )
				? /* translators: %s: field key. */
				  sprintf( __( 'Key: %s (fixed: it names the stored data, renaming is free).', 'leiho' ), f.key )
				: /* translators: %s: field key. */
				  sprintf( __( 'Key: %s (set when you apply, then fixed).', 'leiho' ), f.key );
		}
		renderKeyNote();

		function refreshHead() {
			var row = editorEl.querySelector( '[data-uid="' + f._uid + '"]' );
			if ( ! row ) {
				return;
			}
			var labelEl = row.querySelector( '.leiho-fieldrow__label' );
			var nameEl = row.querySelector( '.leiho-fieldrow__name' );
			if ( labelEl ) {
				labelEl.textContent = ( f.label || f.name ) + ( f.required ? ' *' : '' );
			}
			if ( nameEl ) {
				nameEl.textContent = f.name;
			}
			var titleEl = editorEl.querySelector( '.leiho-detail__title[data-uid="' + f._uid + '"]' );
			if ( titleEl ) {
				titleEl.textContent = f.label || f.name;
			}
			var pathEl = titleEl && titleEl.parentNode.querySelector( '.leiho-detail__path' );
			if ( pathEl ) {
				pathEl.textContent = f.name;
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
			{ id: ids.type, class: 'leiho-input leiho-field-type-input' },
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

		var helpInput = h( 'input', { type: 'text', id: ids.help, class: 'leiho-input', value: f.help || '', placeholder: __( 'Shown to editors under the field', 'leiho' ) } );
		helpInput.addEventListener( 'input', function () {
			f.help = helpInput.value;
			markDirty();
		} );

		var typeHelp = ( TYPE_INDEX[ f.type ] || {} ).help;
		if ( savedField( f ) && depth === 1 ) {
			typeHelp = ( typeHelp ? typeHelp + ' ' : '' ) + __( 'Changing the type of a saved field moves its values to a new column; values that can’t be converted stay in the archived column.', 'leiho' );
		}

		out.push(
			h( 'div', { class: 'leiho-settings-grid' }, [
				field( __( 'Label', 'leiho' ), labelInput, null, ids.label ),
				h( 'div', { class: 'leiho-setting' }, [
					h( 'label', { class: 'leiho-label', for: ids.name, text: __( 'Name in the API', 'leiho' ) } ),
					nameInput,
					keyNote,
				] ),
				field( __( 'Type', 'leiho' ), typeSelect, typeHelp, ids.type ),
				field( __( 'Instructions', 'leiho' ), helpInput, null, ids.help ),
			] )
		);

		// Cases à cocher propres au type, réglages chiffrés repliés (options
		// avancées), choix et cibles toujours visibles.
		var flags = h( 'div', { class: 'leiho-checks-inline' } );
		var advanced = h( 'div', { class: 'leiho-disclosure__body' } );
		var wide = [];

		if ( 'text' === f.type ) {
			flags.appendChild( checkbox( __( 'Several lines', 'leiho' ), f.multiline, function ( v ) {
				f.multiline = v;
			} ) );
		}

		if ( LENGTH_TYPES.indexOf( f.type ) !== -1 ) {
			var lenId = App.uid( 'flen' );
			advanced.appendChild( field( __( 'Maximum length', 'leiho' ), numberInput( lenId, f.max_length || null, function ( v ) {
				f.max_length = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'leiho' ) } ), 'text' === f.type ? __( 'Up to 191 characters, the text is indexed (fast to sort and filter).', 'leiho' ) : null, lenId ) );
		}

		if ( 'number' === f.type ) {
			flags.appendChild( checkbox( __( 'Whole numbers only', 'leiho' ), f.integer, function ( v ) {
				f.integer = v;
			} ) );
			[ [ 'min', __( 'Minimum', 'leiho' ) ], [ 'max', __( 'Maximum', 'leiho' ) ], [ 'step', __( 'Step', 'leiho' ) ] ].forEach( function ( pair ) {
				var id = App.uid( 'fnum' );
				advanced.appendChild( field( pair[ 1 ], numberInput( id, f[ pair[ 0 ] ], function ( v ) {
					f[ pair[ 0 ] ] = v;
				}, { step: 'any' } ), null, id ) );
			} );
		}

		if ( 'select' === f.type ) {
			flags.appendChild( checkbox( __( 'Several choices', 'leiho' ), f.multiple, function ( v ) {
				f.multiple = v;
			} ) );
			var choicesId = App.uid( 'fchoices' );
			var choices = h( 'textarea', { id: choicesId, class: 'leiho-input leiho-mono', rows: '5', spellcheck: 'false', placeholder: 'light : Light\ndark : Dark' } );
			choices.value = ( f.choices || [] ).map( function ( c ) {
				return c.value === c.label ? c.value : c.value + ' : ' + c.label;
			} ).join( '\n' );
			choices.addEventListener( 'input', function () {
				f.choices = parseChoices( choices.value );
				markDirty();
			} );
			wide.push( h( 'div', { class: 'leiho-setting leiho-setting--wide' }, [
				h( 'label', { class: 'leiho-label', for: choicesId, text: __( 'Choices', 'leiho' ) } ),
				choices,
				h( 'p', { class: 'leiho-help', text: __( 'One choice per line. Write “value : Label” to show a label different from the stored value.', 'leiho' ) } ),
			] ) );
		}

		if ( 'gallery' === f.type ) {
			var gmax = App.uid( 'fmax' );
			advanced.appendChild( field( __( 'Maximum images', 'leiho' ), numberInput( gmax, f.max || null, function ( v ) {
				f.max = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'leiho' ) } ), null, gmax ) );
		}

		if ( 'reference' === f.type ) {
			flags.appendChild( checkbox( __( 'Several entries', 'leiho' ), f.multiple, function ( v ) {
				f.multiple = v;
			} ) );
			var rmax = App.uid( 'fmax' );
			advanced.appendChild( field( __( 'Maximum entries', 'leiho' ), numberInput( rmax, f.max || null, function ( v ) {
				f.max = v ? Math.max( 0, Math.round( v ) ) : 0;
			}, { min: 0, placeholder: __( 'No limit', 'leiho' ) } ), __( 'With “Several entries”.', 'leiho' ), rmax ) );
			wide.push( renderTargets( f ) );
		}

		if ( 'list' === f.type || 'blocks' === f.type ) {
			[ [ 'min', __( 'Minimum rows', 'leiho' ) ], [ 'max', __( 'Maximum rows', 'leiho' ) ] ].forEach( function ( pair ) {
				var id = App.uid( 'frows' );
				advanced.appendChild( field( pair[ 1 ], numberInput( id, f[ pair[ 0 ] ] || null, function ( v ) {
					f[ pair[ 0 ] ] = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0, placeholder: 'max' === pair[ 0 ] ? __( 'No limit', 'leiho' ) : '0' } ), null, id ) );
			} );
		}

		if ( flags.children.length ) {
			out.push( flags );
		}
		wide.forEach( function ( node ) {
			out.push( node );
		} );
		if ( advanced.children.length ) {
			var details = h( 'details', { class: 'leiho-disclosure', open: f._advanced || ownErrors.length ? true : null }, [
				h( 'summary', {}, [ icon( 'chevron', 12 ), __( 'Advanced options', 'leiho' ) ] ),
				advanced,
			] );
			details.addEventListener( 'toggle', function () {
				f._advanced = details.open;
			} );
			out.push( details );
		}
		out.push( h( 'div', { class: 'leiho-field-settings__required' }, h( 'label', { class: 'leiho-switch' }, [
			( function () {
				var input = h( 'input', { type: 'checkbox', checked: !! f.required } );
				input.addEventListener( 'change', function () {
					f.required = input.checked;
					refreshHead();
					markDirty();
				} );
				return input;
			} )(),
			__( 'Required', 'leiho' ),
		] ) ) );

		if ( CONTAINERS.indexOf( f.type ) !== -1 ) {
			f.fields = f.fields || [];
			f.fields._nested = true;
			out.push(
				h( 'div', { class: 'leiho-subfields' }, [
					h( 'h4', { text: 'list' === f.type ? __( 'Fields of each row', 'leiho' ) : __( 'Fields of the group', 'leiho' ) } ),
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
		return h( 'fieldset', { class: 'leiho-setting leiho-setting--wide leiho-checks-inline' }, [
			h( 'legend', { class: 'leiho-label', text: __( 'Points to', 'leiho' ) } ),
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
				return h( 'label', { class: 'leiho-check' }, [ input, o.label, ' ', h( 'code', { text: o.name } ) ] );
			} ),
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Types de bloc (champ blocks)
	 * ------------------------------------------------------------------ */

	function renderBlocks( f, depth, path ) {
		var wrap = h( 'div', { class: 'leiho-subfields leiho-blocktypes' }, [ h( 'h4', { text: __( 'Block types', 'leiho' ) } ) ] );
		if ( ! f.blocks.length ) {
			wrap.appendChild( h( 'p', { class: 'leiho-help', text: __( 'Add at least one block type: editors pick one each time they add a block.', 'leiho' ) } ) );
		}
		f.blocks.forEach( function ( block, index ) {
			wrap.appendChild( renderBlockType( f, block, index, depth, path + '.blocks.' + index ) );
		} );
		wrap.appendChild(
			h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--quiet leiho-btn--small', onClick: function () {
				var block = { _uid: nextUid(), _new: true, _open: true, label: __( 'New block', 'leiho' ), min: 0, max: 0, fields: [] };
				syncName( f.blocks, block );
				f.blocks.push( block );
				markDirty();
				renderEditor( '[data-uid="' + block._uid + '"] .leiho-block-label-input' );
			} }, [ icon( 'plus' ), __( 'Add a block type', 'leiho' ) ] )
		);
		return wrap;
	}

	function renderBlockType( f, block, index, depth, path ) {
		var errors = collectErrors( path );
		var labelId = App.uid( 'blabel' );
		var nameId = App.uid( 'bname' );
		var labelInput = h( 'input', { type: 'text', id: labelId, class: 'leiho-input leiho-block-label-input', value: block.label || '' } );
		var nameInput = h( 'input', { type: 'text', id: nameId, class: 'leiho-input leiho-mono', value: block.name || '', spellcheck: 'false' } );
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
		return h( 'div', { class: 'leiho-blocktype' + ( errors.own.length || errors.nested ? ' has-error' : '' ), dataset: { uid: block._uid } }, [
			h( 'div', { class: 'leiho-blocktype__head' }, [
				h( 'strong', { text: label } ),
				h( 'div', { class: 'leiho-fieldrow__actions' }, [
					h( 'button', { type: 'button', class: 'leiho-icon-btn', disabled: 0 === index, title: __( 'Move up', 'leiho' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Move “%s” up', 'leiho' ), label ),
						onClick: function () {
							move( f.blocks, index, index - 1 );
							renderEditor();
						} }, icon( 'up' ) ),
					h( 'button', { type: 'button', class: 'leiho-icon-btn', disabled: index === f.blocks.length - 1, title: __( 'Move down', 'leiho' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Move “%s” down', 'leiho' ), label ),
						onClick: function () {
							move( f.blocks, index, index + 1 );
							renderEditor();
						} }, icon( 'down' ) ),
					h( 'button', { type: 'button', class: 'leiho-icon-btn leiho-icon-btn--danger', title: __( 'Remove', 'leiho' ),
						/* translators: %s: block type label. */
						'aria-label': sprintf( __( 'Remove “%s”', 'leiho' ), label ),
						onClick: function () {
							App.confirm( {
								/* translators: %s: block type label. */
								title: sprintf( __( 'Remove the block type “%s”?', 'leiho' ), label ),
								message: __( 'Blocks of this type disappear from each entry the next time the entry is saved. Revisions keep them.', 'leiho' ),
								confirmLabel: __( 'Remove', 'leiho' ),
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
			errors.own.length ? h( 'div', { class: 'leiho-notice leiho-notice--error', role: 'alert' }, errors.own.map( function ( m ) {
				return h( 'p', { text: m } );
			} ) ) : null,
			h( 'div', { class: 'leiho-settings-grid' }, [
				field( __( 'Label', 'leiho' ), labelInput, null, labelId ),
				field( __( 'Name in the API', 'leiho' ), nameInput, block._new ? null : sprintf( /* translators: %s: block key. */ __( 'Key: %s', 'leiho' ), block.key ), nameId ),
				field( __( 'Minimum', 'leiho' ), numberInput( minId, block.min || null, function ( v ) {
					block.min = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0 } ), null, minId ),
				field( __( 'Maximum', 'leiho' ), numberInput( maxId, block.max || null, function ( v ) {
					block.max = v ? Math.max( 0, Math.round( v ) ) : 0;
				}, { min: 0, placeholder: __( 'No limit', 'leiho' ) } ), null, maxId ),
			] ),
			h( 'div', { class: 'leiho-subfields' }, [
				h( 'h4', { text: __( 'Fields of this block', 'leiho' ) } ),
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
		key: __( 'Key', 'leiho' ),
		name: __( 'Name in the API', 'leiho' ),
		type: __( 'Type', 'leiho' ),
		fields: __( 'Sub-fields', 'leiho' ),
		blocks: __( 'Block types', 'leiho' ),
		choices: __( 'Choices', 'leiho' ),
		to: __( 'Points to', 'leiho' ),
		min: __( 'Minimum', 'leiho' ),
		max: __( 'Maximum', 'leiho' ),
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
		var card = h( 'div', { class: 'leiho-detail__card' }, [
			h( 'h3', { text: __( 'Archived columns', 'leiho' ) } ),
			h( 'p', { class: 'leiho-muted', text: __( 'Values of removed or retyped fields. They are kept until you purge them.', 'leiho' ) } ),
		] );
		if ( ! archived.length ) {
			card.appendChild( h( 'p', { class: 'leiho-help', text: __( 'No archived columns.', 'leiho' ) } ) );
			return card;
		}
		card.appendChild(
			h( 'table', { class: 'leiho-table' }, [
				h( 'thead', {}, h( 'tr', {}, [
					h( 'th', { scope: 'col', text: __( 'Field', 'leiho' ) } ),
					h( 'th', { scope: 'col', text: __( 'Column', 'leiho' ) } ),
					h( 'th', { scope: 'col', text: __( 'Why', 'leiho' ) } ),
					h( 'th', { scope: 'col', text: __( 'Archived', 'leiho' ) } ),
				] ) ),
				h( 'tbody', {}, archived.map( function ( col ) {
					return h( 'tr', {}, [
						h( 'td', {}, [ col.name || col.key, ' ', h( 'span', { class: 'leiho-type-badge', text: ( TYPE_INDEX[ col.type ] || { label: col.type } ).label } ) ] ),
						h( 'td', {}, h( 'code', { text: col.column } ) ),
						h( 'td', { text: 'retyped' === col.reason ? __( 'Type changed', 'leiho' ) : __( 'Field removed', 'leiho' ) } ),
						h( 'td', { text: App.formatDate( col.archived_at ) } ),
					] );
				} ) ),
			] )
		);
		card.appendChild(
			h( 'div', { class: 'leiho-actions leiho-archived-actions' }, h( 'button', { type: 'button', class: 'leiho-btn leiho-btn--danger', onClick: function () {
				purge( item );
			} }, [ icon( 'archive' ), __( 'Purge archived columns', 'leiho' ) ] ) )
		);
		return card;
	}

	function purge( item ) {
		var id = item.collection.id;
		App.confirm( {
			title: __( 'Purge archived columns?', 'leiho' ),
			message: __( 'Their values are deleted from the database for good. Revisions of entries keep a copy, but the columns can’t be restored.', 'leiho' ),
			confirmLabel: __( 'Purge', 'leiho' ),
			danger: true,
			typeToConfirm: id,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/leiho/v1/collections/' + id + '/purge', method: 'POST', data: { confirm: id } } )
				.then( function ( body ) {
					var count = ( body.dropped || [] ).length;
					/* translators: %d: number of columns. */
					App.toast( sprintf( _n( '%d column purged.', '%d columns purged.', count, 'leiho' ), count ) );
					state.current = null;
					return load().then( function () {
						return show( id );
					} );
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
				return sprintf( __( 'Create the table of “%s”.', 'leiho' ), op.collection );
			case 'add_column':
				/* translators: 1: field label, 2: SQL column type. */
				return sprintf( __( 'Add a column for “%1$s” (%2$s).', 'leiho' ), label, op.sql );
			case 'restore':
				/* translators: %s: field label. */
				return sprintf( __( 'Bring back the archived values of “%s”.', 'leiho' ), label );
			case 'retype':
				/* translators: 1: field label, 2: previous type, 3: new type. */
				return sprintf( __( 'Convert “%1$s” from %2$s to %3$s: values move to a new column, the old one is archived.', 'leiho' ), label, typeLabel( op.from_type ), typeLabel( op.to_type ) );
			case 'archive':
				/* translators: %s: field label. */
				return sprintf( __( 'Archive the column of “%s” (values kept).', 'leiho' ), label );
			case 'remove_collection':
				/* translators: %s: collection id. */
				return sprintf( __( 'Remove the collection “%s” from Leiho (entries and values kept).', 'leiho' ), op.collection );
			case 'create_entry':
				/* translators: %s: collection id. */
				return sprintf( __( 'Create the entry of “%s”.', 'leiho' ), op.collection );
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
		var path = cur.isNew ? '/leiho/v1/collections' : '/leiho/v1/collections/' + encodeURIComponent( cur.item.collection.id );
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
			// La sélection va à la première erreur : réglages généraux ou champ.
			var firstPath = errors[ 0 ] && errors[ 0 ].path ? String( errors[ 0 ].path ) : '';
			var match = /^fields\.(\d+)/.exec( firstPath );
			var target = match && state.current.draft.fields[ Number( match[ 1 ] ) ];
			state.current.selected = target ? target._uid : 'general';
			renderEditor();
			var first = editorEl.querySelector( '.leiho-pane--detail .has-error, .leiho-pane--detail .leiho-notice--error' );
			if ( first ) {
				first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
			/* translators: %d: number of problems. */
			App.toast( sprintf( _n( '%d problem to fix before applying.', '%d problems to fix before applying.', errors.length, 'leiho' ), errors.length ), true );
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
			children.push( h( 'p', { text: __( 'Leiho will make these changes to the database:', 'leiho' ) } ) );
			children.push( h( 'ul', { class: 'leiho-plan' }, ops.map( function ( op ) {
				var danger = 'retype' === op.op || 'archive' === op.op || 'remove_collection' === op.op;
				return h( 'li', { class: danger ? 'is-warn' : '' }, [ icon( danger ? 'warning' : 'check', 14 ), describeOp( op ) ] );
			} ) ) );
		} else {
			children.push( h( 'p', { text: __( 'No database change needed: only the field definitions are updated (labels, options, sub-fields, order).', 'leiho' ) } ) );
		}
		( report.warnings || [] ).forEach( function ( w ) {
			children.push( h( 'p', { class: 'leiho-notice leiho-notice--warn', text: 'string' === typeof w ? w : w.message || JSON.stringify( w ) } ) );
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
				extra.push( h( 'div', { class: 'leiho-notice leiho-notice--warn' }, [
					h( 'p', { text: __( 'Removed sub-fields lose their values the next time each entry is saved (revisions keep them):', 'leiho' ) } ),
					h( 'ul', {}, removed.map( function ( r ) {
						return h( 'li', { text: r.label } );
					} ) ),
				] ) );
			}
		}
		App.modal( {
			title: cur.isNew ? __( 'Create this collection?', 'leiho' ) : __( 'Apply these changes?', 'leiho' ),
			body: planBody( report, extra ),
			wide: true,
			actions: [
				{ label: __( 'Keep editing', 'leiho' ) },
				{
					label: cur.isNew ? __( 'Create', 'leiho' ) : __( 'Apply', 'leiho' ),
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
					App.toast( sprintf( _n( '%d value couldn’t be converted: it stays in the archived column.', '%d values couldn’t be converted: they stay in the archived column.', failed, 'leiho' ), failed ), true );
				}
				( report.warnings || [] ).forEach( function ( w ) {
					App.toast( 'string' === typeof w ? w : w.message || '', true );
				} );
				App.toast( cur.isNew ? __( 'Collection created.', 'leiho' ) : __( 'Changes applied.', 'leiho' ) );
				App.notifyChanged();
				done( id );
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
			title: sprintf( __( 'Remove “%s”?', 'leiho' ), c.label ),
			message: __( 'The collection disappears from Leiho, the editor and the content API. Its entries and their values are kept in the database: creating it again with the same identifier brings everything back.', 'leiho' ),
			confirmLabel: __( 'Remove', 'leiho' ),
			danger: true,
			typeToConfirm: c.id,
		} ).then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			App.api( { path: '/leiho/v1/collections/' + encodeURIComponent( c.id ), method: 'DELETE' } )
				.then( function () {
					App.toast( __( 'Collection removed. Its data is kept.', 'leiho' ) );
					state.current = null;
					App.notifyChanged();
					done( '' );
				} )
				.catch( function ( err ) {
					App.toast( App.errorMessage( err ), true );
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Import / export de leiho.schema.json
	 * ------------------------------------------------------------------ */

	function exportSchema() {
		App.api( { path: '/leiho/v1/collections/export' } )
			.then( function ( schema ) {
				var blob = new Blob( [ JSON.stringify( schema, null, 2 ) + '\n' ], { type: 'application/json' } );
				var url = URL.createObjectURL( blob );
				var a = h( 'a', { href: url, download: 'leiho.schema.json', hidden: true } );
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
				App.toast( __( 'The file is not valid JSON.', 'leiho' ), true );
				return;
			}
			App.api( { path: '/leiho/v1/collections/import?dry_run=1', method: 'POST', data: data } )
				.then( function ( report ) {
					var ids = ( data.collections || [] ).map( function ( c ) {
						return c && c.id;
					} ).filter( Boolean );
					App.modal( {
						/* translators: %s: file name. */
						title: sprintf( __( 'Import %s?', 'leiho' ), file.name ),
						wide: true,
						body: [
							h( 'p', { text: sprintf(
								/* translators: %s: list of collection ids. */
								__( 'The file replaces the whole schema with: %s.', 'leiho' ),
								ids.join( ', ' ) || '—'
							) } ),
						].concat( planBody( report ) ),
						actions: [
							{ label: __( 'Cancel', 'leiho' ) },
							{
								label: __( 'Import', 'leiho' ),
								primary: true,
								onClick: function ( close, btn ) {
									btn.disabled = true;
									App.api( { path: '/leiho/v1/collections/import', method: 'POST', data: data } )
										.then( function () {
											close();
											App.toast( __( 'Schema imported.', 'leiho' ) );
											state.current = null;
											App.notifyChanged();
											done( '' );
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
				title: __( 'Add a field', 'leiho' ),
				wide: true,
				body: h( 'ul', { class: 'leiho-type-grid' }, TYPES.map( function ( t ) {
					return h( 'li', {}, h( 'button', { type: 'button', class: 'leiho-type-card', onClick: function () {
						picked = t.type;
						dialog.close();
					} }, [ h( 'span', { class: 'leiho-type-card__icon' }, icon( 'type-' + t.type, 18 ) ), h( 'strong', { text: t.label } ), h( 'span', { text: t.help } ) ] ) );
				} ) ),
				onClose: function () {
					resolve( picked );
				},
				actions: [ { label: __( 'Cancel', 'leiho' ) } ],
			} );
			var first = dialog.el.querySelector( '.leiho-type-card' );
			if ( first ) {
				first.focus();
			}
		} );
	}

} )();
