#!/usr/bin/env node
/**
 * leiho — outils du front pour un site Leiho.
 *
 *   npx leiho types [--url https://cms.example.com] [--out src/leiho.d.ts]
 *   npx leiho pull  [--url https://cms.example.com] [--out .leiho/content.json]
 *   npx leiho ai-ready --site https://example.com [--dir dist] [--locales en,fr]
 *
 * L'adresse du CMS et le jeton se lisent aussi dans LEIHO_URL et LEIHO_TOKEN.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { exportUrl, loadLeihoContent } from '../src/content.js';
import { buildAiFiles } from '../src/ai-ready.js';

const [ command, ...rest ] = process.argv.slice( 2 );
const args = {};
for ( let i = 0; i < rest.length; i++ ) {
	if ( rest[ i ].startsWith( '--' ) ) {
		const [ key, inline ] = rest[ i ].slice( 2 ).split( '=' );
		args[ key ] = inline ?? rest[ ++i ];
	}
}

// OKNO_URL / OKNO_TOKEN : noms d'avant le renommage en Leiho, encore lus.
const url = args.url || process.env.LEIHO_URL || process.env.OKNO_URL;
const token = args.token || process.env.LEIHO_TOKEN || process.env.OKNO_TOKEN || '';

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

// Après un build statique : pages en Markdown, llms.txt, llms-full.txt, sitemap.xml.
async function aiReady() {
	await buildAiFiles( {
		dir: args.dir || 'dist',
		site: args.site || process.env.SITE_URL,
		locales: args.locales ? args.locales.split( ',' ) : undefined,
		exclude: args.exclude ? args.exclude.split( ',' ) : undefined,
		sitemap: args.sitemap !== 'false',
		logger: { info: ( message ) => console.log( 'leiho: ' + message ) },
	} );
}

const commands = { types, pull, 'ai-ready': aiReady };

if ( ! commands[ command ] ) {
	console.log( 'Usage: leiho <types|pull> [--url https://cms.example.com] [--out file] [--token …]\n       leiho ai-ready --site https://example.com [--dir dist] [--locales en,fr] [--exclude /drafts] [--sitemap false]' );
	process.exit( command ? 1 : 0 );
}

commands[ command ]().catch( ( error ) => {
	console.error( 'leiho: ' + error.message );
	process.exit( 1 );
} );
