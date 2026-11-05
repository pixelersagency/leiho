/**
 * Données structurées schema.org d'une page, en un seul graphe JSON-LD.
 *
 *   import { leihoGraph, jsonLd } from 'leiho/seo';
 *
 *   const graph = leihoGraph( {
 *     url: 'https://example.com/docs/install',
 *     item,                                   // contenu Leiho : item.seo.jsonld (page, article, FAQ)
 *     site: { url: 'https://example.com', name: 'Example', locales: [ 'en', 'fr' ] },
 *     organization: { name: 'Example Inc.', url: 'https://example.com', logo: 'https://example.com/logo.png' },
 *     breadcrumbs: [ { name: 'Docs', url: '/docs' }, { name: 'Install' } ],
 *   } );
 *   // <script type="application/ld+json">{ jsonLd( graph ) }</script>
 *
 * Le plugin calcule les nœuds propres au contenu (type déclaré dans la
 * collection, description, image, FAQ, dates, langue) ; ce module y ajoute le
 * site, l'organisation et le fil d'Ariane, reliés par des @id stables.
 */

const clean = ( value ) => {
	if ( Array.isArray( value ) ) {
		const list = value.map( clean ).filter( ( v ) => v !== undefined );
		return list.length ? list : undefined;
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		for ( const [ k, v ] of Object.entries( value ) ) {
			const c = clean( v );
			if ( c !== undefined ) {
				out[ k ] = c;
			}
		}
		return Object.keys( out ).length ? out : undefined;
	}
	return value === null || value === '' ? undefined : value;
};

const absolute = ( href, origin ) => {
	if ( ! href ) {
		return undefined;
	}
	return /^https?:/i.test( href ) ? href : origin + ( href.startsWith( '/' ) ? href : '/' + href );
};

/**
 * @param {object}  input
 * @param {string}  input.url                 Adresse canonique de la page.
 * @param {object}  [input.item]              Contenu Leiho (item.seo.jsonld).
 * @param {{url:string, name:string, description?:string, locales?:string[]}} input.site
 * @param {{name:string, url?:string, logo?:string, sameAs?:string[]}} [input.organization]
 * @param {string}  [input.title]             Nom de la page (sans item, ou pour le remplacer).
 * @param {string}  [input.description]
 * @param {string}  [input.locale]
 * @param {string}  [input.modified]          Date ISO.
 * @param {string}  [input.published]         Date ISO.
 * @param {string}  [input.type]              Type de la page sans item (WebPage, AboutPage…).
 * @param {{name:string, url?:string}[]} [input.breadcrumbs] Fil d'Ariane, accueil exclu : il est ajouté.
 * @param {object[]} [input.nodes]            Nœuds en plus (produit, logiciel, événement…).
 * @returns {{'@context':string, '@graph':object[]}}
 */
export function leihoGraph( input ) {
	const siteUrl = String( input.site.url ).replace( /\/+$/, '' );
	const url = absolute( input.url, siteUrl );
	const websiteId = siteUrl + '/#website';
	const orgId = siteUrl + '/#organization';
	const pageId = url + '#webpage';
	const graph = [];

	if ( input.organization ) {
		const o = input.organization;
		graph.push( {
			'@type': 'Organization',
			'@id': orgId,
			name: o.name,
			url: o.url,
			logo: o.logo ? { '@type': 'ImageObject', url: absolute( o.logo, siteUrl ) } : undefined,
			sameAs: o.sameAs,
		} );
	}

	graph.push( {
		'@type': 'WebSite',
		'@id': websiteId,
		url: siteUrl,
		name: input.site.name,
		description: input.site.description,
		inLanguage: input.site.locales,
		publisher: input.organization ? { '@id': orgId } : undefined,
	} );

	const crumbs = input.breadcrumbs || [];
	const fromItem = input.item?.seo?.jsonld || [];
	const page = fromItem.find( ( n ) => n[ '@id' ] === pageId ) || fromItem[ 0 ];
	const shared = {
		isPartOf: { '@id': websiteId },
		publisher: input.organization ? { '@id': orgId } : undefined,
		breadcrumb: crumbs.length ? { '@id': url + '#breadcrumb' } : undefined,
	};

	if ( page ) {
		// Nœuds du plugin : la page prend l'adresse canonique donnée ici (le
		// front peut servir l'entrée sous une autre adresse que celle du CMS).
		const itemUrl = page.url;
		const relink = ( node ) =>
			JSON.parse( JSON.stringify( node ), ( k, v ) => {
				if ( typeof v !== 'string' || ! itemUrl ) {
					return v;
				}
				if ( v === itemUrl ) {
					return url;
				}
				return v.startsWith( itemUrl + '#' ) ? url + v.slice( itemUrl.length ) : v;
			} );
		for ( const node of fromItem ) {
			const copy = relink( node );
			if ( node === page ) {
				Object.assign( copy, shared, input.title ? { name: input.title } : {}, input.description ? { description: input.description } : {} );
			}
			if ( input.organization && copy[ '@type' ] && /Article|Posting/.test( copy[ '@type' ] ) ) {
				copy.publisher = { '@id': orgId };
				copy.author = copy.author || { '@id': orgId };
			}
			graph.push( copy );
		}
	} else {
		graph.push( {
			'@type': input.type || 'WebPage',
			'@id': pageId,
			url,
			name: input.title,
			description: input.description,
			inLanguage: input.locale,
			datePublished: input.published,
			dateModified: input.modified,
			...shared,
		} );
	}

	if ( crumbs.length ) {
		const list = [ { name: input.site.name, url: siteUrl }, ...crumbs ];
		graph.push( {
			'@type': 'BreadcrumbList',
			'@id': url + '#breadcrumb',
			itemListElement: list.map( ( c, i ) => ( {
				'@type': 'ListItem',
				position: i + 1,
				name: c.name,
				item: i === list.length - 1 ? url : absolute( c.url, siteUrl ),
			} ) ),
		} );
	}

	graph.push( ...( input.nodes || [] ) );
	return { '@context': 'https://schema.org', '@graph': clean( graph ) };
}

/**
 * JSON sûr dans une balise <script type="application/ld+json"> (pas de </script> possible).
 *
 * @param {unknown} data
 * @returns {string}
 */
export function jsonLd( data ) {
	return JSON.stringify( data ).replace( /</g, '\\u003c' ).replace( /\u2028/g, '\\u2028' ).replace( /\u2029/g, '\\u2029' );
}

/**
 * Balises <head> d'une page tirées de item.seo : titre, description, Open Graph.
 * Renvoie des objets à rendre avec le framework (pas de HTML à échapper).
 *
 * @param {object} item     Contenu Leiho.
 * @param {object} [options]
 * @param {string} [options.url]       Adresse canonique.
 * @param {string} [options.siteName]
 * @returns {{title:string, description:string, image?:string, meta: {name?:string, property?:string, content:string}[]}}
 */
export function seoMeta( item, options = {} ) {
	const seo = item?.seo || {};
	const title = seo.title || item?.title || '';
	const description = seo.description || '';
	const image = seo.image?.url;
	const isArticle = /Article|Posting/.test( seo.type || '' );
	const meta = [
		description && { name: 'description', content: description },
		{ property: 'og:type', content: isArticle ? 'article' : 'website' },
		{ property: 'og:title', content: title },
		description && { property: 'og:description', content: description },
		options.url && { property: 'og:url', content: options.url },
		options.siteName && { property: 'og:site_name', content: options.siteName },
		image && { property: 'og:image', content: image },
		image && seo.image?.alt && { property: 'og:image:alt', content: seo.image.alt },
		{ name: 'twitter:card', content: image ? 'summary_large_image' : 'summary' },
		item?.modified && { property: 'article:modified_time', content: item.modified },
	].filter( Boolean );
	return { title, description, image, meta };
}
