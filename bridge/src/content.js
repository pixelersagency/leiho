/**
 * Client de l'API de contenu Leiho, pour le build du front (Node 18+).
 *
 *   import { loadLeihoContent, indexLeihoContent } from 'leiho/content';
 *
 *   const { data } = await loadLeihoContent( { url: 'https://cms.example.com' } );
 *   const content  = indexLeihoContent( data );
 *   content.byPath( '/services/audit' );
 *
 * Une seule requête (/wp-json/leiho/v1/export), avec reprises. Chaque réponse
 * réussie est gardée dans un instantané local : si WordPress ne répond pas
 * (base saturée, maintenance), le build continue avec le dernier contenu
 * connu au lieu d'échouer, et le signale. Un contenu inchangé renvoie un 304 :
 * rien n'est retéléchargé.
 *
 * Le contenu vit dans WordPress et nulle part ailleurs : pas de JSON local qui
 * le double, l'instantané n'est qu'un cache.
 */

const DEFAULT_SNAPSHOT = '.leiho/content.json';
const LEGACY_SNAPSHOT = '.okno/content.json';

/**
 * URL de l'export depuis l'adresse du CMS (ou l'URL complète de l'endpoint).
 *
 * @param {string} url
 * @returns {string}
 */
export function exportUrl( url ) {
	if ( ! url ) {
		throw new Error( 'Leiho: missing CMS url (option `url` or LEIHO_URL).' );
	}
	const clean = String( url ).replace( /\/+$/, '' );
	if ( clean.includes( '/wp-json/' ) || clean.includes( 'rest_route=' ) ) {
		return clean;
	}
	return clean + '/wp-json/leiho/v1/export';
}

/**
 * Charge tout le contenu publié.
 *
 * @param {object}   [options]
 * @param {string}   [options.url]      Adresse du CMS. Défaut : process.env.LEIHO_URL.
 * @param {string}   [options.token]    Jeton (accès restreint). Défaut : process.env.LEIHO_TOKEN.
 * @param {string|false} [options.snapshot] Fichier d'instantané, false pour s'en passer.
 * @param {'snapshot'|'error'} [options.fallback] Si WordPress ne répond pas : instantané (défaut) ou échec.
 * @param {number}   [options.retries]  Tentatives (défaut 3).
 * @param {number}   [options.timeout]  Délai par tentative en ms (défaut 30 000).
 * @param {Function} [options.fetch]    fetch à utiliser (tests).
 * @param {{warn:Function}} [options.logger]
 * @returns {Promise<{data: object, source: 'network'|'unchanged'|'snapshot', stale: boolean}>}
 */
export async function loadLeihoContent( options = {} ) {
	const env = typeof process !== 'undefined' ? process.env : {};
	// OKNO_URL / OKNO_TOKEN : noms d'avant le renommage en Leiho, encore lus.
	const url = exportUrl( options.url || env.LEIHO_URL || env.OKNO_URL );
	const token = options.token ?? env.LEIHO_TOKEN ?? env.OKNO_TOKEN ?? '';
	const snapshotPath = options.snapshot === false ? null : options.snapshot || DEFAULT_SNAPSHOT;
	const fallback = options.fallback || 'snapshot';
	const retries = Math.max( 1, options.retries ?? 3 );
	const timeout = options.timeout ?? 30000;
	const doFetch = options.fetch || globalThis.fetch;
	const logger = options.logger || console;

	// Instantané d'avant le renommage (.okno/) : repris tant qu'aucun .leiho/ n'existe.
	const snapshot = snapshotPath
		? ( await readSnapshot( snapshotPath ) ) ?? ( snapshotPath === DEFAULT_SNAPSHOT ? await readSnapshot( LEGACY_SNAPSHOT ) : null )
		: null;

	const headers = { Accept: 'application/json' };
	if ( token ) {
		headers[ 'X-Leiho-Token' ] = token;
	}
	if ( snapshot && snapshot.version ) {
		headers[ 'If-None-Match' ] = '"' + snapshot.version + '"';
	}

	let lastError = null;
	for ( let attempt = 1; attempt <= retries; attempt++ ) {
		try {
			const response = await doFetch( url, {
				headers,
				signal: typeof AbortSignal !== 'undefined' && AbortSignal.timeout ? AbortSignal.timeout( timeout ) : undefined,
			} );

			if ( response.status === 304 && snapshot ) {
				return { data: snapshot, source: 'unchanged', stale: false };
			}
			if ( response.status === 401 || response.status === 403 ) {
				// Inutile d'insister : le jeton est faux ou absent.
				throw Object.assign( new Error( 'Leiho: access refused (' + response.status + '). Check LEIHO_TOKEN.' ), { fatal: true } );
			}
			if ( ! response.ok ) {
				throw new Error( 'Leiho: HTTP ' + response.status + ' on ' + url );
			}

			const data = await response.json();
			if ( ! data || ! Array.isArray( data.items ) ) {
				throw new Error( 'Leiho: unexpected response from ' + url + ' (is Leiho 1.0.0-beta.6+ active?)' );
			}
			if ( snapshotPath ) {
				await writeSnapshot( snapshotPath, data );
			}
			return { data, source: 'network', stale: false };
		} catch ( error ) {
			lastError = error;
			if ( error.fatal ) {
				break;
			}
			if ( attempt < retries ) {
				await sleep( 1000 * 2 ** ( attempt - 1 ) );
			}
		}
	}

	if ( snapshot && fallback === 'snapshot' && ! lastError?.fatal ) {
		logger.warn(
			'[leiho] WordPress did not answer (' + ( lastError && lastError.message ) + '). ' +
				'Using the snapshot from ' + ( snapshot.generated || 'an earlier build' ) + '.'
		);
		return { data: snapshot, source: 'snapshot', stale: true };
	}
	throw lastError || new Error( 'Leiho: no content.' );
}

/**
 * Index de recherche sur un export.
 *
 * @param {{items: Array<object>}} data
 */
export function indexLeihoContent( data ) {
	const items = ( data && data.items ) || [];
	const byId = new Map();
	const byPath = new Map();
	for ( const item of items ) {
		byId.set( item.id, item );
		byPath.set( normalizePath( item.path ), item );
	}
	return {
		items,
		byId: ( id ) => byId.get( Number( id ) ) || null,
		byPath: ( path ) => byPath.get( normalizePath( path ) ) || null,
		byType: ( type ) => items.filter( ( item ) => item.type === type ),
		bySlug: ( type, slug ) => items.find( ( item ) => item.type === type && item.slug === slug ) || null,
	};
}

function normalizePath( path ) {
	const clean = '/' + String( path || '' ).split( /[?#]/ )[ 0 ].replace( /^\/+|\/+$/g, '' );
	return clean === '/' ? '/' : clean;
}

async function readSnapshot( file ) {
	const fs = await import( 'node:fs/promises' );
	try {
		return JSON.parse( await fs.readFile( file, 'utf8' ) );
	} catch {
		return null;
	}
}

async function writeSnapshot( file, data ) {
	const fs = await import( 'node:fs/promises' );
	const path = await import( 'node:path' );
	await fs.mkdir( path.dirname( file ), { recursive: true } );
	// Écriture atomique : un build interrompu ne laisse pas d'instantané tronqué.
	const tmp = file + '.' + process.pid + '.tmp';
	await fs.writeFile( tmp, JSON.stringify( data ) );
	await fs.rename( tmp, file );
}

function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * Noms d'avant le renommage d'Okno en Leiho : gardés jusqu'à la 2.0.
 * @deprecated Utiliser loadLeihoContent / indexLeihoContent.
 */
export const loadOknoContent = loadLeihoContent;
/** @deprecated Utiliser indexLeihoContent. */
export const indexOknoContent = indexLeihoContent;
