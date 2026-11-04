/**
 * Client de contenu : cd bridge && npm test
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { exportUrl, indexLeihoContent, loadLeihoContent } from '../src/content.js';

const sample = {
	format: 1,
	version: 'abc123',
	generated: '2026-11-04T10:00:00+00:00',
	site: { name: 'Test', front_url: 'https://www.example.com', cms_url: 'https://cms.example.com', language: 'fr-FR' },
	items: [
		{ id: 2, type: 'page', slug: 'accueil', path: '/', title: 'Accueil', fields: {} },
		{ id: 9, type: 'service', slug: 'audit', path: '/services/audit', title: 'Audit', fields: {} },
	],
};

const silent = { warn() {} };

function respond( status, body ) {
	return { status, ok: status >= 200 && status < 300, json: async () => body };
}

async function tmpSnapshot() {
	const dir = await fs.mkdtemp( path.join( os.tmpdir(), 'leiho-' ) );
	return path.join( dir, 'content.json' );
}

test( 'exportUrl accepte l’adresse du CMS ou l’endpoint complet', () => {
	assert.equal( exportUrl( 'https://cms.example.com/' ), 'https://cms.example.com/wp-json/leiho/v1/export' );
	assert.equal( exportUrl( 'https://cms.example.com/wp-json/leiho/v1/export' ), 'https://cms.example.com/wp-json/leiho/v1/export' );
	assert.throws( () => exportUrl( '' ) );
} );

test( 'charge, envoie le jeton et écrit l’instantané', async () => {
	const snapshot = await tmpSnapshot();
	let seen;
	const result = await loadLeihoContent( {
		url: 'https://cms.example.com',
		token: 'secret',
		snapshot,
		fetch: async ( url, init ) => {
			seen = { url, init };
			return respond( 200, sample );
		},
	} );
	assert.equal( result.source, 'network' );
	assert.equal( seen.init.headers[ 'X-Leiho-Token' ], 'secret' );
	assert.deepEqual( JSON.parse( await fs.readFile( snapshot, 'utf8' ) ), sample );
} );

test( 'instantané impossible à écrire (serveur en lecture seule) : contenu servi quand même', async () => {
	const warnings = [];
	const result = await loadLeihoContent( {
		url: 'https://cms.example.com',
		snapshot: '/dev/null/impossible/content.json',
		logger: { warn: ( m ) => warnings.push( m ) },
		fetch: async () => respond( 200, sample ),
	} );
	assert.equal( result.source, 'network' );
	assert.equal( result.data.items.length, 2 );
	assert.match( warnings.join( ' ' ), /snapshot not written/ );
} );

test( '304 : réutilise l’instantané sans le retélécharger', async () => {
	const snapshot = await tmpSnapshot();
	await fs.writeFile( snapshot, JSON.stringify( sample ) );
	let sent;
	const result = await loadLeihoContent( {
		url: 'https://cms.example.com',
		snapshot,
		fetch: async ( url, init ) => {
			sent = init.headers[ 'If-None-Match' ];
			return respond( 304, null );
		},
	} );
	assert.equal( sent, '"abc123"' );
	assert.equal( result.source, 'unchanged' );
	assert.equal( result.data.items.length, 2 );
} );

test( 'WordPress en panne : reprises puis instantané signalé', async () => {
	const snapshot = await tmpSnapshot();
	await fs.writeFile( snapshot, JSON.stringify( sample ) );
	let calls = 0;
	const warnings = [];
	const result = await loadLeihoContent( {
		url: 'https://cms.example.com',
		snapshot,
		retries: 2,
		fetch: async () => {
			calls++;
			return respond( 500, null );
		},
		logger: { warn: ( message ) => warnings.push( message ) },
	} );
	assert.equal( calls, 2 );
	assert.equal( result.source, 'snapshot' );
	assert.equal( result.stale, true );
	assert.equal( warnings.length, 1 );
} );

test( 'WordPress en panne sans instantané : échec', async () => {
	await assert.rejects(
		loadLeihoContent( { url: 'https://cms.example.com', snapshot: false, retries: 1, fetch: async () => respond( 502, null ), logger: silent } ),
		/HTTP 502/
	);
} );

test( 'fallback error : pas d’instantané même s’il existe', async () => {
	const snapshot = await tmpSnapshot();
	await fs.writeFile( snapshot, JSON.stringify( sample ) );
	await assert.rejects(
		loadLeihoContent( { url: 'https://cms.example.com', snapshot, fallback: 'error', retries: 1, fetch: async () => respond( 503, null ), logger: silent } )
	);
} );

test( 'jeton refusé : pas de reprise ni d’instantané', async () => {
	const snapshot = await tmpSnapshot();
	await fs.writeFile( snapshot, JSON.stringify( sample ) );
	let calls = 0;
	await assert.rejects(
		loadLeihoContent( {
			url: 'https://cms.example.com',
			snapshot,
			retries: 3,
			fetch: async () => {
				calls++;
				return respond( 401, null );
			},
			logger: silent,
		} ),
		/LEIHO_TOKEN/
	);
	assert.equal( calls, 1 );
} );

test( 'index : par chemin, id, type et slug', () => {
	const content = indexLeihoContent( sample );
	assert.equal( content.byPath( '/services/audit/' ).id, 9 );
	assert.equal( content.byPath( '' ).id, 2 );
	assert.equal( content.byId( '9' ).slug, 'audit' );
	assert.equal( content.byType( 'service' ).length, 1 );
	assert.equal( content.bySlug( 'page', 'accueil' ).id, 2 );
	assert.equal( content.byPath( '/inconnu' ), null );
} );

test( 'field, block, entry : attributs d’annotation', async () => {
	const { field, block, entry } = await import( '../src/attrs.js' );
	const page = { id: 12, fields: {} };
	assert.deepEqual( field( page, 'hero_title' ), { 'data-wp-post': '12', 'data-wp-field': 'hero_title' } );
	assert.deepEqual( field( 12, 'intro', { html: true, refresh: true } ), { 'data-wp-post': '12', 'data-wp-field': 'intro', 'data-leiho-apply': 'html', 'data-leiho-refresh': 'save' } );
	assert.deepEqual( field( null, 'cards.0.title' ), { 'data-wp-field': 'cards.0.title' } );
	assert.deepEqual( block( page, 'sections', 2, 'Hero' ), { 'data-wp-post': '12', 'data-leiho-layout': 'sections.2', 'data-leiho-section': 'Hero' } );
	assert.deepEqual( entry( page ), { 'data-wp-post': '12' } );
	assert.deepEqual( entry( 'abc' ), {} );
	const root = await import( '../src/index.js' );
	const content = await import( '../src/content.js' );
	assert.equal( root.field, field );
	assert.equal( content.field, field );
} );

test( 'langue : ?locale=, instantané par langue, langue notée', async () => {
	const dir = await fs.mkdtemp( path.join( os.tmpdir(), 'leiho-' ) );
	const cwd = process.cwd();
	process.chdir( dir );
	try {
		let seen;
		const result = await loadLeihoContent( {
			url: 'https://cms.example.com',
			locale: 'fr',
			fetch: async ( url ) => {
				seen = url;
				return respond( 200, { ...sample, items: [ { ...sample.items[ 1 ], path: '/fr/services/audit', locale: 'fr' } ] } );
			},
		} );
		assert.equal( seen, 'https://cms.example.com/wp-json/leiho/v1/export?locale=fr' );
		assert.equal( result.data.locale, 'fr' );
		const saved = JSON.parse( await fs.readFile( path.join( dir, '.leiho/content.fr.json' ), 'utf8' ) );
		assert.equal( saved.items[ 0 ].path, '/fr/services/audit' );
		assert.ok( indexLeihoContent( result.data ).byPath( '/fr/services/audit' ) );
	} finally {
		process.chdir( cwd );
	}
} );

test( 'langue : un instantané d’une autre langue n’est pas servi', async () => {
	const snapshot = await tmpSnapshot();
	await fs.writeFile( snapshot, JSON.stringify( { ...sample, locale: 'fr' } ) );
	await assert.rejects(
		loadLeihoContent( { url: 'https://cms.example.com', snapshot, retries: 1, logger: silent, fetch: async () => respond( 500, {} ) } )
	);
} );
