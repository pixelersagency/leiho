/*!
 * leiho-bridge v5 — standalone (auto-init).
 * Généré par bridge/build.js depuis src/index.js — ne pas éditer à la main.
 *
 * Chargement recommandé (le bridge ne sert qu'à l'éditeur) :
 *   <script>
 *     if (window.self !== window.top) {
 *       var s = document.createElement('script');
 *       s.src = '/leiho-bridge.js';
 *       s.setAttribute('data-wp-origin', 'https://admin.monsite.fr');
 *       document.head.appendChild(s);
 *     }
 *   </script>
 */
( function () {
	'use strict';

	/**
	 * leiho v5 — pont postMessage entre un site et l'éditeur Leiho.
	 *
	 * Fonctionne avec n'importe quel front : HTML statique, Astro, Next.js, Remix,
	 * React (SPA ou rendu serveur), Vue/Nuxt, Svelte/SvelteKit.
	 *
	 * Usage (ESM) :
	 *   if ( window.self !== window.top ) {
	 *     const { initLeihoBridge } = await import( 'leiho' );
	 *     initLeihoBridge( { wpOrigin: 'https://admin.monsite.fr' } );
	 *   }
	 *
	 * React : import { LeihoBridge } from 'leiho/react' — voir src/react.js.
	 *
	 * Le bridge ne fait RIEN hors iframe. Il n'appelle jamais l'API WordPress :
	 * les valeurs arrivent de wp-admin par postMessage, avec vérification stricte
	 * de l'origine des deux côtés.
	 *
	 * Annotations :
	 *   data-wp-post="12"              conteneur : ID du contenu WordPress
	 *   data-wp-field="chemin"         élément éditable (chemins pointés : cartes.0.titre)
	 *   data-leiho-apply="html"         la valeur est du HTML (balisage interne conservé)
	 *   data-leiho-section="Libellé"    section logique (arbre de structure)
	 *   data-leiho-layout="sections.2"  section portée par une ligne de flexible content
	 *   data-leiho-refresh="save"       pas d'aperçu instantané, rechargement après enregistrement
	 *   data-leiho-style="prop"         champ couleur : propriété CSS ciblée
	 *   data-leiho-managed="menu"       contenu géré ailleurs : l'éditeur explique où le modifier
	 *   data-leiho-managed-label="…"    libellé de la région gérée
	 *   data-leiho-edit-url="…"         écran wp-admin où modifier ce contenu
	 *
	 * Sites React / SPA — ce qui change par rapport à une page statique :
	 *   - aucun écouteur n'est posé sur les éléments : tout passe par délégation
	 *     sur document, donc un nœud recréé par un re-render reste cliquable ;
	 *   - les valeurs d'aperçu sont gardées en mémoire et réappliquées après chaque
	 *     mutation du DOM : un re-render qui remet la valeur serveur ne l'efface pas ;
	 *   - le texte est écrit dans le nœud texte existant (nodeValue) plutôt qu'en
	 *     remplaçant les enfants, pour ne pas casser la réconciliation de React ;
	 *   - la navigation client (pushState, popstate) déclenche un nouveau handshake.
	 */

	const NS = 'leiho';
	const VERSION = 5;
	const HOVER_CLASS = 'leiho-hover';
	const MANAGED_CLASS = 'leiho-managed-hover';
	const CLOAK_CLASS = 'leiho-cloak';

	/**
	 * Attributs d'annotation : data-leiho-*, ou data-okno-* sur les sites annotés
	 * avant le renommage d'Okno en Leiho (lus tant que la 2.0 n'est pas sortie).
	 */
	function attr( el, name ) {
		const value = el.getAttribute( 'data-leiho-' + name );
		return null !== value ? value : el.getAttribute( 'data-okno-' + name );
	}

	function sel( name ) {
		return '[data-leiho-' + name + '], [data-okno-' + name + ']';
	}

	/** Attente de stabilisation du DOM avant re-collecte (rendus successifs de React). */
	const SETTLE_MS = 120;

	/** Balises retirées de tout HTML injecté dans l'aperçu. */
	const BANNED_TAGS = [ 'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form' ];

	function initLeihoBridge( options ) {
		if ( typeof window === 'undefined' || window.self === window.top ) {
			return; // Jamais actif en navigation publique ni côté serveur.
		}

		// Idempotent : React StrictMode monte les effets deux fois en développement.
		if ( window.__leihoBridge ) {
			return;
		}

		const wpOrigin = options && options.wpOrigin;
		if ( ! wpOrigin ) {
			console.warn( '[leiho] Missing wpOrigin: bridge inactive.' );
			return;
		}

		if ( document.referrer ) {
			try {
				if ( new URL( document.referrer ).origin !== wpOrigin ) {
					return;
				}
			} catch ( e ) {
				/* referrer illisible : les contrôles d'origine suffisent. */
			}
		}

		window.__leihoBridge = { version: VERSION };

		/* ------------------------------------------------------------------ *
		 * État
		 * ------------------------------------------------------------------ */

		/** Valeurs d'aperçu en cours : "postId:path" -> { value, fieldType }. */
		const overrides = new Map();

		/** Dernière collecte : champs et sections présents dans le DOM. */
		let fields = new Map();
		let sections = [];
		let signature = '';
		let currentUrl = window.location.href;
		let applying = false;
		let settleTimer = null;

		/* ------------------------------------------------------------------ *
		 * Cloak : page masquée jusqu'aux valeurs de l'éditeur (chargement initial)
		 * ------------------------------------------------------------------ */

		const style = document.createElement( 'style' );
		style.textContent =
			'.' + CLOAK_CLASS + '{opacity:0 !important}' +
			'.' + HOVER_CLASS + '{outline:2px solid #86c400 !important;outline-offset:2px;cursor:pointer !important}' +
			'.' + MANAGED_CLASS + '{outline:2px dashed #b7791f !important;outline-offset:2px}';
		document.head.appendChild( style );

		let cloakTimer = null;
		const cloakDelay = options && 'cloakTimeout' in options ? Number( options.cloakTimeout ) : 4000;

		function uncloak() {
			clearTimeout( cloakTimer );
			document.documentElement.classList.remove( CLOAK_CLASS );
		}

		if ( cloakDelay > 0 ) {
			document.documentElement.classList.add( CLOAK_CLASS );
			cloakTimer = setTimeout( () => {
				uncloak();
				send( { type: 'cloakTimeout' } );
				console.warn( '[leiho] wp-admin did not respond: preview shown without hydration.' );
			}, cloakDelay );
		}

		/* ------------------------------------------------------------------ *
		 * Protocole
		 * ------------------------------------------------------------------ */

		function send( message ) {
			message.ns = NS;
			window.parent.postMessage( message, wpOrigin );
		}

		/* ------------------------------------------------------------------ *
		 * Collecte
		 * ------------------------------------------------------------------ */

		function postIdOf( el ) {
			const host = el.closest( '[data-wp-post]' );
			return host ? host.getAttribute( 'data-wp-post' ) : '';
		}

		// Libellé vide = libellé par défaut, composé et traduit côté éditeur
		// (le bridge ne connaît pas la langue de wp-admin) ; voir sectionAuto().
		function sectionLabel( el ) {
			const explicit = attr( el, 'section' ) || el.getAttribute( 'aria-label' );
			if ( explicit ) {
				return explicit;
			}
			const tag = el.tagName.toLowerCase();
			if ( 'header' === tag || 'footer' === tag ) {
				return '';
			}
			const heading = el.querySelector( 'h1, h2, h3' );
			if ( heading && heading.textContent.trim() ) {
				const text = heading.textContent.trim().replace( /\s+/g, ' ' );
				return text.length > 48 ? text.slice( 0, 45 ) + '…' : text;
			}
			return '';
		}

		/** Nature du libellé par défaut : 'header', 'footer' ou 'section' (numérotée). */
		function sectionAuto( el ) {
			const tag = el.tagName.toLowerCase();
			return 'header' === tag || 'footer' === tag ? tag : 'section';
		}

		function sectionLayout( el ) {
			const raw = attr( el, 'layout' );
			if ( ! raw ) {
				return null;
			}
			const parts = raw.split( '.' );
			const index = Number( parts[ parts.length - 1 ] );
			if ( parts.length < 2 || isNaN( index ) ) {
				return null;
			}
			return { post: postIdOf( el ) || null, path: parts.slice( 0, -1 ).join( '.' ), index };
		}

		function collect() {
			const nextFields = new Map();
			const nextSections = [];

			document.querySelectorAll( '[data-leiho-sid]' ).forEach( ( el ) => el.removeAttribute( 'data-leiho-sid' ) );

			// 1. Sections annotées explicitement : elles priment, même à l'intérieur
			//    d'une balise <section> générique (sauf imbriquées dans une autre annotée).
			const EXPLICIT = sel( 'section' ) + ', ' + sel( 'layout' );
			const explicit = [ ...document.querySelectorAll( EXPLICIT ) ].filter(
				( el ) => ! ( el.parentElement && el.parentElement.closest( EXPLICIT ) )
			);

			// 2. Détection automatique (section, header, footer) là où rien n'est annoté :
			//    ni à l'intérieur d'une section retenue, ni autour d'une section annotée.
			const auto = [];
			document.querySelectorAll( 'section, header, footer' ).forEach( ( el ) => {
				if ( el.matches( EXPLICIT ) || el.closest( EXPLICIT ) || el.querySelector( EXPLICIT ) ) {
					return;
				}
				if ( auto.some( ( kept ) => kept.contains( el ) ) ) {
					return;
				}
				auto.push( el );
			} );

			// Ordre du document, identifiants positionnels.
			explicit
				.concat( auto )
				.sort( ( a, b ) => ( a.compareDocumentPosition( b ) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1 ) )
				.forEach( ( el ) => {
					const id = 's' + nextSections.length;
					el.setAttribute( 'data-leiho-sid', id );
					nextSections.push( {
						id,
						label: sectionLabel( el ),
						auto: sectionAuto( el ),
						layout: sectionLayout( el ),
						el,
						fields: [],
					} );
				} );

			document.querySelectorAll( '[data-wp-field]' ).forEach( ( el ) => {
				const postId = postIdOf( el );
				const path = el.getAttribute( 'data-wp-field' );
				if ( ! postId || ! path ) {
					return;
				}
				const key = postId + ':' + path;
				let entry = nextFields.get( key );
				if ( ! entry ) {
					entry = {
						postId,
						path,
						refresh: 'save' === attr( el, 'refresh' ),
						els: [],
					};
					nextFields.set( key, entry );

					const host = el.closest( '[data-leiho-sid]' );
					const section = host && nextSections.find( ( s ) => s.id === host.getAttribute( 'data-leiho-sid' ) );
					if ( section ) {
						section.fields.push( { post: postId, path, refresh: entry.refresh } );
					}
				}
				entry.els.push( el );
			} );

			fields = nextFields;
			sections = nextSections;
		}

		function computeSignature() {
			return JSON.stringify( [
				window.location.pathname,
				[ ...fields.keys() ].sort(),
				sections.map( ( s ) => [ s.label, s.auto, s.layout && s.layout.path, s.layout && s.layout.index ] ),
			] );
		}

		/* ------------------------------------------------------------------ *
		 * Application des valeurs
		 * ------------------------------------------------------------------ */

		// Liste blanche : seul ce qu'un texte riche contient passe. Une liste noire
		// laisse toujours une porte (noscript, svg/math, schémas d'URL exotiques).
		const ALLOWED_TAGS = new Set( [
			'A', 'ABBR', 'B', 'BLOCKQUOTE', 'BR', 'CAPTION', 'CITE', 'CODE', 'DD', 'DEL', 'DIV', 'DL', 'DT', 'EM', 'FIGCAPTION',
			'FIGURE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HR', 'I', 'IMG', 'INS', 'KBD', 'LI', 'MARK', 'OL', 'P', 'PRE', 'Q',
			'S', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TABLE', 'TBODY', 'TD', 'TFOOT', 'TH', 'THEAD', 'TR', 'U', 'UL',
		] );
		// Hors liste blanche et retirées avec leur contenu (code, documents imbriqués).
		const DROPPED_TAGS = new Set( [ ...BANNED_TAGS.map( ( t ) => t.toUpperCase() ), 'NOSCRIPT', 'NOEMBED', 'NOFRAMES', 'TEMPLATE', 'SVG', 'MATH', 'XMP', 'TEXTAREA', 'TITLE', 'SELECT', 'OBJECT' ] );
		const ALLOWED_ATTRS = new Set( [ 'href', 'src', 'alt', 'title', 'class', 'width', 'height', 'target', 'rel', 'colspan', 'rowspan', 'lang', 'dir', 'start', 'reversed', 'loading', 'decoding', 'srcset', 'sizes', 'id', 'style' ] );

		/** Adresse sûre : http(s), mailto, tel ou relative. */
		function safeUrl( value ) {
			const url = String( null == value ? '' : value ).replace( /[\u0000-\u0020\u007f-\u009f]/g, '' );
			const scheme = url.match( /^([a-z][a-z0-9+.-]*):/i );
			return ! scheme || /^(https?|mailto|tel)$/i.test( scheme[ 1 ] ) ? String( value ) : '';
		}

		function sanitizeHtml( html ) {
			const source = new DOMParser().parseFromString( '<body>' + String( null == html ? '' : html ) + '</body>', 'text/html' ).body;
			const clean = ( node, into ) => {
				node.childNodes.forEach( ( child ) => {
					if ( child.nodeType === Node.TEXT_NODE ) {
						into.appendChild( document.createTextNode( child.nodeValue ) );
						return;
					}
					if ( child.nodeType !== Node.ELEMENT_NODE ) {
						return;
					}
					if ( ! ALLOWED_TAGS.has( child.tagName ) ) {
						// Balise inconnue : son texte reste, sauf pour ce qui n'en a pas à montrer.
						if ( ! DROPPED_TAGS.has( child.tagName.toUpperCase() ) ) {
							clean( child, into );
						}
						return;
					}
					const el = document.createElement( child.tagName );
					[ ...child.attributes ].forEach( ( a ) => {
						const name = a.name.toLowerCase();
						if ( ! ALLOWED_ATTRS.has( name ) ) {
							return;
						}
						if ( 'href' === name || 'src' === name ) {
							const url = safeUrl( a.value );
							if ( url ) {
								el.setAttribute( name, url );
							}
						} else if ( 'srcset' === name ) {
							if ( ! /(^|,)\s*[a-z][a-z0-9+.-]*:/i.test( a.value ) || a.value.split( ',' ).every( ( part ) => safeUrl( part.trim().split( /\s+/ )[ 0 ] ) ) ) {
								el.setAttribute( name, a.value );
							}
						} else {
							el.setAttribute( name, a.value );
						}
					} );
					clean( child, el );
					into.appendChild( el );
				} );
			};
			const out = document.createElement( 'div' );
			clean( source, out );
			return out.innerHTML;
		}

		/**
		 * Écrit un texte sans détruire les nœuds que React référence : si l'élément
		 * contient un seul nœud texte, on change sa valeur en place.
		 */
		function setText( el, value ) {
			const text = null == value ? '' : String( value );
			const only = el.childNodes.length === 1 ? el.firstChild : null;
			if ( only && only.nodeType === Node.TEXT_NODE ) {
				if ( only.nodeValue !== text ) {
					only.nodeValue = text;
				}
			} else if ( el.textContent !== text ) {
				el.textContent = text;
			}
		}

		/**
		 * @param {Element} el        Élément annoté.
		 * @param {*}       value     Valeur du champ.
		 * @param {string}  fieldType Type de champ.
		 * @param {boolean} explicit  Valeur changée par l'utilisateur pendant cette
		 *                            édition (fieldUpdate), pas seulement chargée.
		 */
		function applyToElement( el, value, fieldType, explicit ) {
			if ( 'image' === fieldType ) {
				const url = value && 'object' === typeof value ? value.url || '' : value || '';
				// Champ jamais rempli : le front affiche souvent une image de repli
				// (src, srcset ou fond CSS du gabarit). On n'y touche pas, sauf si
				// l'utilisateur vient de vider le champ.
				if ( ! url && ! explicit ) {
					return;
				}
				const src = safeUrl( url );
				const img = 'IMG' === el.tagName ? el : el.querySelector( 'img' );
				if ( img ) {
					if ( img.getAttribute( 'src' ) !== src ) {
						img.setAttribute( 'src', src );
						img.removeAttribute( 'srcset' );
					}
				} else {
					el.style.backgroundImage = src ? 'url("' + src.replace( /["\\\n]/g, '' ) + '")' : '';
				}
			} else if ( 'color_picker' === fieldType ) {
				el.style.setProperty( attr( el, 'style' ) || 'color', value || '' );
			} else if ( 'link' === fieldType ) {
				const link = 'A' === el.tagName ? el : el.querySelector( 'a' );
				const data = value && 'object' === typeof value ? value : { url: value };
				if ( link ) {
					link.setAttribute( 'href', safeUrl( data.url || '' ) );
					if ( data.target ) {
						link.setAttribute( 'target', data.target );
					} else {
						link.removeAttribute( 'target' );
					}
					if ( data.title ) {
						setText( link, data.title );
					}
				}
			} else if ( 'wysiwyg' === fieldType || 'html' === attr( el, 'apply' ) ) {
				const html = sanitizeHtml( value );
				if ( el.innerHTML !== html ) {
					el.innerHTML = html;
				}
			} else {
				setText( el, value );
			}
		}

		function applyOverrides() {
			applying = true;
			try {
				overrides.forEach( ( item, key ) => {
					const entry = fields.get( key );
					if ( entry && ! entry.refresh ) {
						entry.els.forEach( ( el ) => applyToElement( el, item.value, item.fieldType, !! item.explicit ) );
					}
				} );
			} finally {
				// Les mutations que l'on vient de provoquer arrivent en microtâche.
				Promise.resolve().then( () => {
					applying = false;
				} );
			}
		}

		/* ------------------------------------------------------------------ *
		 * Cycle de vie : handshake, mutations, navigation client
		 * ------------------------------------------------------------------ */

		function announce() {
			const posts = {};
			fields.forEach( ( entry ) => {
				if ( ! posts[ entry.postId ] ) {
					posts[ entry.postId ] = [];
				}
				posts[ entry.postId ].push( { path: entry.path, refresh: entry.refresh, ratio: slotRatio( entry ) } );
			} );
			signature = computeSignature();
			send( {
				type: 'ready',
				version: VERSION,
				url: window.location.href,
				posts,
				sections: sections.map( ( s ) => ( { id: s.id, label: s.label, auto: s.auto, layout: s.layout, fields: s.fields } ) ),
			} );
		}

		function slotRatio( entry ) {
			const el = entry.els[ 0 ];
			if ( ! el ) {
				return 0;
			}
			const target = 'IMG' === el.tagName ? el : el.querySelector( 'img' ) || el;
			const box = target.getBoundingClientRect();
			if ( box.width < 8 || box.height < 8 ) {
				return 0;
			}
			return Math.round( ( box.width / box.height ) * 1000 ) / 1000;
		}

		function settle() {
			clearTimeout( settleTimer );
			settleTimer = setTimeout( () => {
				if ( window.location.href !== currentUrl ) {
					// Changement de page côté client : les valeurs d'aperçu de la page
					// précédente ne concernent plus rien.
					currentUrl = window.location.href;
					overrides.clear();
				}
				collect();
				applyOverrides();
				if ( computeSignature() !== signature ) {
					announce();
				}
			}, SETTLE_MS );
		}

		const observer = new MutationObserver( () => {
			if ( ! applying ) {
				settle();
			}
		} );

		[ 'pushState', 'replaceState' ].forEach( ( method ) => {
			const original = window.history[ method ];
			window.history[ method ] = function () {
				const result = original.apply( this, arguments );
				settle();
				return result;
			};
		} );
		window.addEventListener( 'popstate', settle );

		/* ------------------------------------------------------------------ *
		 * Interactions, par délégation (survivent aux re-renders)
		 * ------------------------------------------------------------------ */

		function fieldTarget( node ) {
			const el = node && node.closest ? node.closest( '[data-wp-field]' ) : null;
			return el && postIdOf( el ) ? el : null;
		}

		document.addEventListener( 'mouseover', ( event ) => {
			const field = fieldTarget( event.target );
			const managed = ! field && event.target.closest ? event.target.closest( sel( 'managed' ) ) : null;
			document.querySelectorAll( '.' + HOVER_CLASS + ', .' + MANAGED_CLASS ).forEach( ( el ) => {
				if ( el !== field && el !== managed ) {
					el.classList.remove( HOVER_CLASS, MANAGED_CLASS );
				}
			} );
			if ( field ) {
				field.classList.add( HOVER_CLASS );
			} else if ( managed ) {
				managed.classList.add( MANAGED_CLASS );
			}
		} );

		document.addEventListener(
			'click',
			( event ) => {
				const target = event.target;
				if ( ! target || ! target.closest ) {
					return;
				}

				// 1. Un champ éditable, même à l'intérieur d'un lien : c'est lui qu'on vise.
				const field = fieldTarget( target );
				if ( field ) {
					event.preventDefault();
					event.stopPropagation();
					send( { type: 'fieldFocus', postId: postIdOf( field ), path: field.getAttribute( 'data-wp-field' ) } );
					return;
				}

				// 2. Un lien : l'éditeur décide (bascule de page, lien externe…). Capté en
				//    phase de capture, donc avant le routeur du framework (Next, React Router).
				const link = target.closest( 'a' );
				if ( link ) {
					event.preventDefault();
					event.stopPropagation();
					const href = link.getAttribute( 'href' ) || '';
					if ( ! href || 0 === href.indexOf( '#' ) ) {
						return;
					}
					let url;
					try {
						url = new URL( href, window.location.href );
					} catch ( e ) {
						return;
					}
					if ( ! /^https?:$/.test( url.protocol ) ) {
						return;
					}
					send( { type: 'navigate', href: url.href, path: url.pathname, external: url.origin !== window.location.origin } );
					return;
				}

				// 3. Un contenu géré ailleurs.
				const managed = target.closest( sel( 'managed' ) );
				if ( managed ) {
					event.preventDefault();
					event.stopPropagation();
					const provider = attr( managed, 'managed' ) || '';
					send( {
						type: 'managedFocus',
						provider,
						label: attr( managed, 'managed-label' ) || '',
						editUrl: attr( managed, 'edit-url' ) || '',
					} );
					return;
				}

				// 4. Une section.
				const section = target.closest( '[data-leiho-sid]' );
				if ( section ) {
					event.preventDefault();
					send( { type: 'sectionFocus', id: section.getAttribute( 'data-leiho-sid' ) } );
				}
			},
			true
		);

		// Les formulaires du site ne doivent rien envoyer depuis l'éditeur.
		document.addEventListener( 'submit', ( event ) => event.preventDefault(), true );

		function flash( el ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			el.classList.add( HOVER_CLASS );
			setTimeout( () => el.classList.remove( HOVER_CLASS ), 1500 );
		}

		window.addEventListener( 'message', ( event ) => {
			if ( event.origin !== wpOrigin || event.source !== window.parent || ! event.data || event.data.ns !== NS ) {
				return;
			}
			const data = event.data;

			if ( 'init' === data.type ) {
				overrides.clear();
				Object.keys( data.values || {} ).forEach( ( postId ) => {
					const values = data.values[ postId ];
					Object.keys( values ).forEach( ( path ) => {
						overrides.set( postId + ':' + path, values[ path ] );
					} );
				} );
				applyOverrides();
				uncloak();
			} else if ( 'fieldUpdate' === data.type ) {
				overrides.set( data.postId + ':' + data.path, { value: data.value, fieldType: data.fieldType, explicit: true } );
				applyOverrides();
			} else if ( 'focusField' === data.type ) {
				const entry = fields.get( data.postId + ':' + data.path );
				if ( entry && entry.els[ 0 ] ) {
					flash( entry.els[ 0 ] );
				}
			} else if ( 'focusSection' === data.type ) {
				const section = sections.find( ( s ) => s.id === data.id );
				if ( section ) {
					flash( section.el );
				}
			}
		} );

		function start() {
			collect();
			announce();
			observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}

	window.initLeihoBridge = initLeihoBridge;
	window.initOknoBridge = initLeihoBridge; // Nom d'avant le renommage en Leiho.

	// Auto-init depuis l'attribut du tag <script>.
	var script = document.currentScript;
	var origin = script && script.getAttribute( 'data-wp-origin' );
	if ( origin ) {
		initLeihoBridge( {
			wpOrigin: origin,
			cloakTimeout: Number( script.getAttribute( 'data-leiho-cloak-timeout' ) || script.getAttribute( 'data-okno-cloak-timeout' ) ) || undefined,
		} );
	}
} )();
