#!/usr/bin/env node
/**
 * leiho — outils du front pour un site Leiho.
 *
 *   npx leiho types [--url https://cms.example.com] [--out src/leiho.d.ts]
 *   npx leiho pull  [--url https://cms.example.com] [--out .leiho/content.json]
 *
 * L'adresse du CMS et le jeton se lisent aussi dans LEIHO_URL et LEIHO_TOKEN.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { exportUrl, loadLeihoContent } from '../src/content.js';

const [ command, ...rest ] = process.argv.slice( 2 );
const args = {};
for ( let i = 0; i < rest.length; i++ ) {
	if ( rest[ i ].startsWith( '--' ) ) {
		const [ key, inline ] = rest[ i ].slice( 2 ).split( '=' );
		args[ key ] = inline ?? rest[ ++i ];
	}
}

const url = args.url || process.env.LEIHO_URL;
const token = args.token || process.env.LEIHO_TOKEN || '';

async function types() {
	const endpoint = exportUrl( url ).replace( /\/export$/, '/types' );
	const headers = token ? { 'X-Leiho-Token': token } : {};
	const response = await fetch( endpoint, { headers } );
	if ( ! response.ok ) {
		throw new Error( 'HTTP ' + response.status + ' on ' + endpoint );
	}
	const out = args.out || 'src/leiho.d.ts';
	await fs.mkdir( path.dirname( out ), { recursive: true } );
	await fs.writeFile( out, await response.text() );
	console.log( 'leiho: types written to ' + out );
}

async function pull() {
	const out = args.out || '.leiho/content.json';
	const { data, source } = await loadLeihoContent( { url, token, snapshot: out, fallback: 'error' } );
	console.log( 'leiho: ' + data.items.length + ' items (' + source + ') in ' + out );
}

const commands = { types, pull };

if ( ! commands[ command ] ) {
	console.log( 'Usage: leiho <types|pull> [--url https://cms.example.com] [--out file] [--token …]' );
	process.exit( command ? 1 : 0 );
}

commands[ command ]().catch( ( error ) => {
	console.error( 'leiho: ' + error.message );
	process.exit( 1 );
} );
