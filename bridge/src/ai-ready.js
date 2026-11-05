/**
 * Un site statique prêt pour les assistants IA, après le build (Node 18+) :
 *
 *   - chaque page en Markdown, à côté de son HTML (docs/install/index.html → docs/install.md) ;
 *   - llms.txt et llms-full.txt (llmstxt.org), un par langue ;
 *   - sitemap.xml avec les autres langues de chaque page (hreflang) et sa date.
 *
 *   import { buildAiFiles } from 'leiho/ai-ready';
 *   await buildAiFiles( { dir: 'dist', site: 'https://example.com' } );
 *
 *   // Astro : astro.config.mjs
 *   import { aiReady } from 'leiho/ai-ready';
 *   export default defineConfig( { site: 'https://example.com', integrations: [ aiReady() ] } );
 *
 *   // Tout autre build statique : npx leiho ai-ready --dir out --site https://example.com
 *
 * Le Markdown vient du <main> de chaque page déjà rendue : ce que lit un agent
 * est ce que lit un visiteur, sans second gabarit. Les illustrations
 * (aria-hidden, svg, canvas, data-md-skip) sont laissées de côté.
 */
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { findNode, findNodes, parseHtml, textOf } from './html.js';

const BLOCK = new Set( [
	'address', 'article', 'aside', 'blockquote', 'details', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure',
	'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
	'section', 'summary', 'table', 'ul',
] );
const SKIP_TAGS = new Set( [ 'script', 'style', 'svg', 'canvas', 'button', 'template', 'noscript', 'iframe', 'video', 'audio', 'input', 'select', 'textarea', 'aside', 'nav', 'dialog' ] );

const classes = ( n ) => ( n.attrs?.class || '' ).split( /\s+/ ).filter( Boolean );
const isElement = ( n, tag ) => n.type === 'element' && ( ! tag || n.tag === tag );

/**
 * Convertisseur HTML → Markdown.
 *
 * @param {object}   [options]
 * @param {string}   [options.site]        Adresse du site : liens relatifs rendus absolus.
 * @param {string[]} [options.skipClasses] Classes d'éléments à ignorer (illustrations).
 * @returns {(node:any)=>string}
 */
export function createConverter( options = {} ) {
	const site = ( options.site || '' ).replace( /\/+$/, '' );
	const skipClasses = new Set( options.skipClasses || [] );

	const skipped = ( n ) => {
		if ( n.type !== 'element' ) {
			return n.type !== 'text';
		}
		const a = n.attrs;
		return SKIP_TAGS.has( n.tag ) || a[ 'aria-hidden' ] === 'true' || 'hidden' in a || 'data-md-skip' in a || classes( n ).some( ( c ) => skipClasses.has( c ) );
	};
	const isBlock = ( n ) => n.type === 'element' && ( BLOCK.has( n.tag ) || n.attrs.role === 'table' || n.attrs.role === 'row' );

	const abs = ( href ) => {
		if ( ! href || href.startsWith( '#' ) || /^[a-z]+:/i.test( href ) ) {
			return href;
		}
		return site + ( href.startsWith( '/' ) ? href : '/' + href );
	};
	const squash = ( s ) => s.replace( /\s+/g, ' ' ).trim();
	const tidy = ( s ) => s.replace( /[ \t]+/g, ' ' ).replace( / *\n */g, '\n' ).trim();

	function inline( n ) {
		if ( n.type === 'text' ) {
			return n.value.replace( /\s+/g, ' ' );
		}
		if ( skipped( n ) ) {
			return '';
		}
		const inner = () => ( n.children || [] ).map( inline ).join( '' );
		switch ( n.tag ) {
			case 'br':
				return '\n';
			case 'strong':
			case 'b': {
				const t = inner().trim();
				return t ? `**${ t }**` : '';
			}
			case 'em':
			case 'i': {
				const t = inner().trim();
				return t ? `_${ t }_` : '';
			}
			case 'code': {
				const t = textOf( n );
				return t.includes( '`' ) ? '`` ' + t + ' ``' : '`' + t + '`';
			}
			case 'img':
				return n.attrs.alt ? `![${ n.attrs.alt }](${ abs( n.attrs.src ) })` : '';
			case 'a': {
				const t = squash( inner() );
				const href = n.attrs.href;
				return ! t || ! href || href.startsWith( '#' ) ? t : `[${ t }](${ abs( href ) })`;
			}
			default:
				return inner();
		}
	}

	// Enfants d'un conteneur : une suite d'éléments en ligne forme un paragraphe,
	// une suite de liens seuls (cartes, logos) une liste.
	function children( n ) {
		const out = [];
		let run = '';
		let links = [];
		let other = false;
		const flush = () => {
			if ( links.length > 1 && ! other ) {
				out.push( links.map( ( l ) => '- ' + l ).join( '\n' ) );
			} else if ( tidy( run ) ) {
				out.push( tidy( run ) );
			}
			run = '';
			links = [];
			other = false;
		};
		for ( const c of n.children || [] ) {
			if ( skipped( c ) ) {
				continue;
			}
			if ( isBlock( c ) || ( isElement( c, 'a' ) && findNode( { children: c.children }, isBlock ) ) ) {
				flush();
				out.push( ...block( c ) );
				continue;
			}
			const piece = inline( c );
			if ( isElement( c, 'a' ) && piece.trim() ) {
				links.push( piece.trim() );
			} else if ( piece.trim() ) {
				other = true;
			}
			run += run && /[\])]$/.test( run ) && piece.startsWith( '[' ) ? ' ' + piece : piece;
		}
		flush();
		return out;
	}

	function table( rows ) {
		const grid = rows.map( ( r ) => ( r.children || [] ).filter( ( c ) => c.type === 'element' && ! skipped( c ) ).map( ( c ) => children( c ).join( ' ' ).replace( /\|/g, '\\|' ).replace( /\n/g, ' ' ) ) );
		if ( ! grid.length ) {
			return [];
		}
		const width = Math.max( ...grid.map( ( r ) => r.length ) );
		const line = ( r ) => `| ${ [ ...r, ...Array( width - r.length ).fill( '' ) ].join( ' | ' ) } |`;
		return [ [ line( grid[ 0 ] ), line( Array( width ).fill( '---' ) ), ...grid.slice( 1 ).map( line ) ].join( '\n' ) ];
	}

	function list( n, ordered ) {
		const items = ( n.children || [] ).filter( ( c ) => isElement( c, 'li' ) && ! skipped( c ) );
		const lines = items.map( ( li, i ) => {
			const marker = ordered ? `${ i + 1 }. ` : '- ';
			return marker + children( li ).join( '\n' ).split( '\n' ).join( '\n' + ' '.repeat( marker.length ) );
		} );
		return lines.length ? [ lines.join( '\n' ) ] : [];
	}

	// Langage d'un bloc de code : class="language-x", data-language, ou légende de la figure (fichier ou langage).
	function codeLang( pre, figure ) {
		const code = findNode( pre, ( k ) => isElement( k, 'code' ) );
		for ( const el of [ code, pre ] ) {
			const lang = el?.attrs[ 'data-language' ] || classes( el || { attrs: {} } ).find( ( c ) => c.startsWith( 'language-' ) )?.slice( 9 );
			if ( lang ) {
				return lang;
			}
		}
		const cap = figure && findNode( figure, ( k ) => isElement( k, 'figcaption' ) );
		const label = cap ? textOf( findNode( cap, ( k ) => isElement( k, 'span' ) ) || cap ).trim() : '';
		if ( ! label || label === 'code' || /\s/.test( label ) ) {
			return '';
		}
		const ext = label.includes( '.' ) ? label.split( '.' ).pop() : label;
		return { mjs: 'js', cjs: 'js', sh: 'bash', yml: 'yaml', md: 'markdown' }[ ext ] || ext;
	}

	function block( n, figure ) {
		const role = n.attrs?.role;
		if ( role === 'table' ) {
			return table( findNodes( n, ( k ) => k.type === 'element' && k.attrs.role === 'row' ) );
		}
		switch ( n.tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6': {
				const t = squash( ( n.children || [] ).map( inline ).join( '' ) );
				return t ? [ '#'.repeat( Number( n.tag[ 1 ] ) ) + ' ' + t ] : [];
			}
			case 'p':
			case 'dt':
			case 'dd':
			case 'summary': {
				const t = tidy( ( n.children || [] ).map( inline ).join( '' ) );
				return t ? [ t ] : [];
			}
			case 'ul':
				return list( n, false );
			case 'ol':
				return list( n, true );
			case 'hr':
				return [ '---' ];
			case 'pre': {
				const code = textOf( n ).replace( /\n+$/, '' );
				const fence = code.includes( '```' ) ? '````' : '```';
				return [ `${ fence }${ codeLang( n, figure ) }\n${ code }\n${ fence }` ];
			}
			case 'blockquote':
				return [ children( n ).join( '\n\n' ).split( '\n' ).map( ( l ) => '> ' + l ).join( '\n' ) ];
			case 'table':
				return table( findNodes( n, ( k ) => isElement( k, 'tr' ) ) );
			case 'figure': {
				const pre = findNode( n, ( k ) => isElement( k, 'pre' ) );
				if ( pre ) {
					return block( pre, n );
				}
				return children( n );
			}
			case 'figcaption':
				return [];
			case 'a': {
				// Carte cliquable : son titre devient le lien.
				const parts = children( n );
				const href = abs( n.attrs.href );
				if ( ! parts.length || ! href || href.startsWith( '#' ) ) {
					return parts;
				}
				const m = parts[ 0 ].match( /^(#+) (.*)$/ );
				parts[ 0 ] = m ? `${ m[ 1 ] } [${ m[ 2 ] }](${ href })` : `[${ parts[ 0 ].replace( /\n/g, ' ' ) }](${ href })`;
				return parts;
			}
			default:
				return children( n );
		}
	}

	return ( node ) => block( node ).join( '\n\n' ).replace( /\n{3,}/g, '\n\n' ).trim() + '\n';
}

/**
 * Adresse Markdown d'une page : / → /index.md, /docs/install → /docs/install.md.
 *
 * @param {string} path
 * @returns {string}
 */
export function markdownPath( path ) {
	const clean = path.replace( /\/+$/, '' );
	return ( clean || '/index' ) + '.md';
}

async function htmlFiles( dir ) {
	const out = [];
	for ( const e of await readdir( dir, { withFileTypes: true } ) ) {
		const p = join( dir, e.name );
		if ( e.isDirectory() ) {
			out.push( ...( await htmlFiles( p ) ) );
		} else if ( e.name === 'index.html' ) {
			out.push( p );
		}
	}
	return out;
}

const xml = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
const meta = ( tree, test ) => findNode( tree, ( n ) => n.type === 'element' && test( n.tag, n.attrs ) );

/**
 * Écrit les fichiers pour les IA dans un dossier de build.
 *
 * @param {object}   options
 * @param {string}   options.dir             Dossier du build (dist, out…).
 * @param {string}   options.site            Adresse publique du site.
 * @param {string[]} [options.locales]       Langues, la première étant celle sans préfixe. Défaut : lues dans <html lang>.
 * @param {string}   [options.defaultLocale] Langue sans préfixe d'adresse. Défaut : locales[0].
 * @param {string}   [options.name]          Nom du site en tête de llms.txt. Défaut : og:site_name de l'accueil.
 * @param {Record<string,string>|string} [options.intro] Paragraphe d'introduction de llms.txt, par langue.
 * @param {Record<string,Record<string,string>>} [options.sections] Titre de rubrique par premier segment d'adresse et par langue ({ en: { docs: 'Docs' } }).
 * @param {string[]} [options.optional]      Rubriques secondaires, rangées sous « Optional ».
 * @param {string[]} [options.exclude]       Chemins (ou débuts de chemin) à laisser de côté.
 * @param {string[]} [options.skipClasses]   Classes d'éléments à ignorer dans le Markdown.
 * @param {string}   [options.selector]      Élément qui porte le contenu : 'main' (défaut).
 * @param {boolean}  [options.sitemap]       Écrire sitemap.xml (défaut true).
 * @param {{info:Function}} [options.logger]
 * @returns {Promise<{pages:number}>}
 */
export async function buildAiFiles( options ) {
	const root = options.dir instanceof URL ? fileURLToPath( options.dir ) : options.dir;
	const site = String( options.site || '' ).replace( /\/+$/, '' );
	if ( ! site ) {
		throw new Error( 'Leiho: buildAiFiles needs the public address of the site (option `site`).' );
	}
	const selector = options.selector || 'main';
	const exclude = options.exclude || [];
	const toMarkdown = createConverter( { site, skipClasses: options.skipClasses } );
	const pages = [];

	for ( const file of await htmlFiles( root ) ) {
		const path = '/' + relative( root, file ).split( sep ).slice( 0, -1 ).join( '/' );
		if ( exclude.some( ( x ) => path === x || path.startsWith( x.replace( /\/?$/, '/' ) ) ) ) {
			continue;
		}
		const tree = parseHtml( await readFile( file, 'utf8' ) );
		const main = findNode( tree, ( n ) => isElement( n, selector ) );
		const robots = meta( tree, ( t, a ) => t === 'meta' && a.name === 'robots' )?.attrs.content || '';
		if ( ! main || /noindex/i.test( robots ) ) {
			continue;
		}

		const lang = findNode( tree, ( n ) => isElement( n, 'html' ) )?.attrs.lang || '';
		const title = textOf( meta( tree, ( t ) => t === 'title' ) || { children: [] } ).trim();
		const description = meta( tree, ( t, a ) => t === 'meta' && a.name === 'description' )?.attrs.content || '';
		const modified = meta( tree, ( t, a ) => t === 'meta' && a.property === 'article:modified_time' )?.attrs.content || '';
		const siteName = meta( tree, ( t, a ) => t === 'meta' && a.property === 'og:site_name' )?.attrs.content || '';
		const url = site + ( path === '/' ? '' : path );
		const md = markdownPath( path );
		const body = toMarkdown( main );

		const front = [ '---', `title: ${ JSON.stringify( title ) }` ];
		if ( description ) {
			front.push( `description: ${ JSON.stringify( description ) }` );
		}
		front.push( `url: ${ url }` );
		if ( lang ) {
			front.push( `lang: ${ lang }` );
		}
		if ( modified ) {
			front.push( `updated: ${ modified.slice( 0, 10 ) }` );
		}
		front.push( '---', '' );
		await writeFile( join( root, md.slice( 1 ) ), front.join( '\n' ) + body );

		pages.push( { path, url, md: site + md, lang, title, description, modified, siteName, body } );
	}

	// Langues : celles données, sinon celles des pages (la langue de l'accueil d'abord).
	const home = pages.find( ( p ) => p.path === '/' );
	const short = ( l ) => String( l || '' ).toLowerCase().split( '-' )[ 0 ];
	const locales = options.locales || [ ...new Set( [ short( home?.lang ), ...pages.map( ( p ) => short( p.lang ) ) ].filter( Boolean ) ) ];
	const defaultLocale = options.defaultLocale || locales[ 0 ] || '';
	const localeOf = ( path ) => {
		const first = path.split( '/' )[ 1 ];
		return locales.includes( first ) && first !== defaultLocale ? first : defaultLocale;
	};
	const base = ( path ) => {
		const l = localeOf( path );
		return l === defaultLocale ? path : path.slice( l.length + 1 ) || '/';
	};

	// Ordre : celui des liens de l'accueil (menus, pied de page), puis alphabétique.
	const order = new Map();
	for ( const p of pages.filter( ( q ) => base( q.path ) === '/' || base( q.path ).split( '/' ).length === 2 ) ) {
		const tree = parseHtml( await readFile( join( root, p.path.slice( 1 ), 'index.html' ), 'utf8' ) );
		for ( const a of findNodes( tree, ( n ) => isElement( n, 'a' ) ) ) {
			const href = String( a.attrs.href || '' ).replace( /^https?:\/\/[^/]+/, '' ).replace( /\/$/, '' ) || '/';
			if ( href.startsWith( '/' ) && ! order.has( href ) ) {
				order.set( href, order.size );
			}
		}
	}
	const rank = ( p ) => ( order.has( p.path ) ? order.get( p.path ) : 1e6 );
	pages.sort( ( a, b ) => rank( a ) - rank( b ) || a.path.localeCompare( b.path ) );

	const label = ( p ) => p.title.replace( /\s+[·|–—-]\s+[^·|–—-]+$/, '' ) || p.path;
	const optional = options.optional || [];

	for ( const locale of locales.length ? locales : [ '' ] ) {
		const mine = pages.filter( ( p ) => localeOf( p.path ) === locale );
		const prefix = locale && locale !== defaultLocale ? '/' + locale : '';
		const homePage = mine.find( ( p ) => base( p.path ) === '/' );
		const name = options.name || homePage?.siteName || label( homePage || { title: '', path: site } );
		const intro = typeof options.intro === 'string' ? options.intro : options.intro?.[ locale ] || '';
		const sections = options.sections?.[ locale ] || {};
		const head = [ `# ${ name }`, '' ];
		if ( homePage?.description ) {
			head.push( `> ${ homePage.description }`, '' );
		}
		if ( intro ) {
			head.push( intro, '' );
		}

		const groups = new Map();
		for ( const p of mine ) {
			const b = base( p.path );
			if ( b === '/' ) {
				continue;
			}
			const seg = b.split( '/' )[ 1 ];
			const key = optional.includes( seg ) ? '\u0000optional' : b.split( '/' ).length > 2 || sections[ seg ] ? seg : '\u0000pages';
			if ( ! groups.has( key ) ) {
				groups.set( key, [] );
			}
			groups.get( key ).push( p );
		}
		const title = ( key ) => {
			if ( key === '\u0000optional' ) {
				return 'Optional';
			}
			if ( key === '\u0000pages' ) {
				return sections.pages || 'Pages';
			}
			return sections[ key ] || key.replace( /[-_]+/g, ' ' ).replace( /^./, ( c ) => c.toUpperCase() );
		};
		const keys = [ ...Object.keys( sections ).filter( ( k ) => groups.has( k ) ), ...[ ...groups.keys() ].filter( ( k ) => ! ( k in sections ) && ! k.startsWith( '\u0000' ) ), '\u0000pages', '\u0000optional' ];

		const lines = [ ...head ];
		for ( const key of keys ) {
			// Page de la rubrique d'abord, sans doublon de titre (/docs = /docs/introduction).
			const list = ( groups.get( key ) || [] )
				.sort( ( a, b ) => base( a.path ).split( '/' ).length - base( b.path ).split( '/' ).length )
				.filter( ( p, i, all ) => all.findIndex( ( q ) => label( q ) === label( p ) ) === i );
			if ( ! list.length ) {
				continue;
			}
			lines.push( `## ${ title( key ) }`, '' );
			for ( const p of list ) {
				lines.push( `- [${ label( p ) }](${ p.md })${ p.description ? ': ' + p.description.replace( /\s+/g, ' ' ) : '' }` );
			}
			lines.push( '' );
		}
		const others = locales.filter( ( l ) => l !== locale );
		if ( others.length ) {
			lines.push( `## ${ sections.languages || 'Other languages' }`, '' );
			for ( const l of others ) {
				lines.push( `- [${ l.toUpperCase() }](${ site }${ l === defaultLocale ? '' : '/' + l }/llms.txt)` );
			}
			lines.push( '' );
		}
		await writeFile( join( root, prefix.slice( 1 ), 'llms.txt' ), lines.join( '\n' ) );

		const full = [ ...head ];
		for ( const p of mine ) {
			full.push( '---', '', `Source: ${ p.url }${ p.modified ? ` (updated ${ p.modified.slice( 0, 10 ) })` : '' }`, '', p.body );
		}
		await writeFile( join( root, prefix.slice( 1 ), 'llms-full.txt' ), full.join( '\n' ) );
	}

	if ( options.sitemap !== false ) {
		const byBase = new Map();
		for ( const p of pages ) {
			const b = base( p.path );
			if ( ! byBase.has( b ) ) {
				byBase.set( b, {} );
			}
			byBase.get( b )[ localeOf( p.path ) ] = p;
		}
		const urls = [];
		for ( const set of byBase.values() ) {
			const many = Object.keys( set ).length > 1;
			for ( const p of Object.values( set ) ) {
				const alts = many ? Object.entries( set ).map( ( [ l, q ] ) => `    <xhtml:link rel="alternate" hreflang="${ l }" href="${ xml( q.url ) }"/>` ) : [];
				if ( many && set[ defaultLocale ] ) {
					alts.push( `    <xhtml:link rel="alternate" hreflang="x-default" href="${ xml( set[ defaultLocale ].url ) }"/>` );
				}
				urls.push( [ '  <url>', `    <loc>${ xml( p.url ) }</loc>`, ...( p.modified ? [ `    <lastmod>${ p.modified.slice( 0, 10 ) }</lastmod>` ] : [] ), ...alts, '  </url>' ].join( '\n' ) );
			}
		}
		await writeFile(
			join( root, 'sitemap.xml' ),
			`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n${ urls.join( '\n' ) }\n</urlset>\n`
		);
	}

	options.logger?.info( `${ pages.length } pages in Markdown, llms.txt and llms-full.txt${ locales.length > 1 ? ' (' + locales.join( ', ' ) + ')' : '' }${ options.sitemap === false ? '' : ', sitemap.xml' }` );
	return { pages: pages.length };
}

/**
 * Intégration Astro : buildAiFiles() à la fin du build, avec l'adresse `site` de la configuration.
 *
 * @param {Omit<Parameters<typeof buildAiFiles>[0], 'dir'|'site'> & {site?:string}} [options]
 */
export function aiReady( options = {} ) {
	let site = options.site || '';
	return {
		name: 'leiho-ai-ready',
		hooks: {
			'astro:config:done': ( { config } ) => {
				site = site || config.site || '';
			},
			'astro:build:done': async ( { dir, logger } ) => {
				await buildAiFiles( { ...options, dir, site, logger } );
			},
		},
	};
}
