/**
 * Lecture d'HTML rendu (pages d'un build) en arbre, sans dépendance. Pensé pour
 * le HTML produit par un framework, bien formé : balises vides, texte brut
 * (script, style), fermetures implicites courantes (p, li), entités.
 *
 * Nœuds : { type: 'element', tag, attrs, children } | { type: 'text', value }.
 */

const VOID = new Set( [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ] );
const RAW = new Set( [ 'script', 'style', 'textarea', 'title' ] );
// Un de ces éléments ferme un <p> resté ouvert.
const CLOSES_P = new Set( [ 'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ] );

const NAMED = {
	amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', hellip: '…', mdash: '—', ndash: '–',
	rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', laquo: '«', raquo: '»', copy: '©', reg: '®', trade: '™',
	rarr: '→', larr: '←', times: '×', middot: '·', bull: '•', deg: '°', euro: '€', shy: '',
};

/**
 * @param {string} text
 * @returns {string}
 */
export function decodeEntities( text ) {
	return text.replace( /&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, ( match, code ) => {
		if ( code[ 0 ] === '#' ) {
			const n = code[ 1 ] === 'x' || code[ 1 ] === 'X' ? parseInt( code.slice( 2 ), 16 ) : parseInt( code.slice( 1 ), 10 );
			return Number.isFinite( n ) ? String.fromCodePoint( n ) : match;
		}
		const value = NAMED[ code.toLowerCase() ];
		return value === undefined ? match : value;
	} );
}

const TAG = /<(\/?)([a-zA-Z][\w:-]*)((?:\s+[^\s"'>/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*)\s*(\/?)>/y;
const ATTR = /([^\s"'>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;

function attributes( source ) {
	const attrs = {};
	for ( const m of source.matchAll( ATTR ) ) {
		attrs[ m[ 1 ].toLowerCase() ] = decodeEntities( m[ 2 ] ?? m[ 3 ] ?? m[ 4 ] ?? '' );
	}
	return attrs;
}

/**
 * @param {string} html
 * @returns {{type:'root', children: any[]}}
 */
export function parseHtml( html ) {
	const root = { type: 'root', children: [] };
	const stack = [ root ];
	const top = () => stack[ stack.length - 1 ];
	const close = ( tag ) => {
		for ( let i = stack.length - 1; i > 0; i-- ) {
			if ( stack[ i ].tag === tag ) {
				stack.length = i;
				return;
			}
		}
	};
	let i = 0;
	let text = '';
	const flush = () => {
		if ( text ) {
			top().children.push( { type: 'text', value: decodeEntities( text ) } );
			text = '';
		}
	};

	while ( i < html.length ) {
		const lt = html.indexOf( '<', i );
		if ( lt === -1 ) {
			text += html.slice( i );
			break;
		}
		text += html.slice( i, lt );
		i = lt;

		if ( html.startsWith( '<!--', i ) ) {
			const end = html.indexOf( '-->', i + 4 );
			i = end === -1 ? html.length : end + 3;
			continue;
		}
		if ( html[ i + 1 ] === '!' || html[ i + 1 ] === '?' ) {
			const end = html.indexOf( '>', i );
			i = end === -1 ? html.length : end + 1;
			continue;
		}

		TAG.lastIndex = i;
		const m = TAG.exec( html );
		if ( ! m ) {
			text += '<';
			i++;
			continue;
		}
		flush();
		i = TAG.lastIndex;
		const tag = m[ 2 ].toLowerCase();

		if ( m[ 1 ] ) {
			close( tag );
			continue;
		}

		if ( CLOSES_P.has( tag ) && top().tag === 'p' ) {
			stack.pop();
		}
		if ( tag === 'li' ) {
			closeSibling( stack, 'li', [ 'ul', 'ol' ] );
		}
		if ( tag === 'dt' || tag === 'dd' ) {
			closeSibling( stack, tag === 'dt' ? 'dd' : 'dt', [ 'dl' ] );
			closeSibling( stack, tag, [ 'dl' ] );
		}
		if ( tag === 'tr' || tag === 'td' || tag === 'th' ) {
			if ( tag === 'tr' ) {
				closeSibling( stack, 'tr', [ 'table', 'thead', 'tbody', 'tfoot' ] );
			} else {
				closeSibling( stack, 'td', [ 'tr' ] );
				closeSibling( stack, 'th', [ 'tr' ] );
			}
		}

		const node = { type: 'element', tag, attrs: attributes( m[ 3 ] ), children: [] };
		top().children.push( node );

		if ( RAW.has( tag ) ) {
			const end = html.toLowerCase().indexOf( '</' + tag, i );
			const raw = html.slice( i, end === -1 ? html.length : end );
			if ( raw ) {
				node.children.push( { type: 'text', value: tag === 'script' || tag === 'style' ? raw : decodeEntities( raw ) } );
			}
			const gt = end === -1 ? -1 : html.indexOf( '>', end );
			i = gt === -1 ? html.length : gt + 1;
			continue;
		}
		if ( ! VOID.has( tag ) && ! m[ 4 ] ) {
			stack.push( node );
		}
	}
	flush();
	return root;
}

// Ferme un frère resté ouvert (<li> sans </li>), sans remonter au-delà de son conteneur.
function closeSibling( stack, tag, containers ) {
	for ( let i = stack.length - 1; i > 0; i-- ) {
		if ( containers.includes( stack[ i ].tag ) ) {
			return;
		}
		if ( stack[ i ].tag === tag ) {
			stack.length = i;
			return;
		}
	}
}

/**
 * Texte d'un nœud et de ses descendants.
 *
 * @param {any} node
 * @returns {string}
 */
export function textOf( node ) {
	if ( node.type === 'text' ) {
		return node.value;
	}
	if ( node.type === 'element' && node.tag === 'br' ) {
		return '\n';
	}
	return ( node.children || [] ).map( textOf ).join( '' );
}

/**
 * Premier nœud qui satisfait le test (parcours en profondeur).
 *
 * @param {any} node
 * @param {(n:any)=>boolean} test
 * @returns {any|null}
 */
export function findNode( node, test ) {
	if ( test( node ) ) {
		return node;
	}
	for ( const child of node.children || [] ) {
		const hit = findNode( child, test );
		if ( hit ) {
			return hit;
		}
	}
	return null;
}

/**
 * Tous les nœuds qui satisfont le test.
 *
 * @param {any} node
 * @param {(n:any)=>boolean} test
 * @param {any[]} [out]
 * @returns {any[]}
 */
export function findNodes( node, test, out = [] ) {
	if ( test( node ) ) {
		out.push( node );
	}
	for ( const child of node.children || [] ) {
		findNodes( child, test, out );
	}
	return out;
}
