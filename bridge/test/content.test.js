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
