import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseHtml, findNode } from '../src/html.js';
import { buildAiFiles, createConverter, markdownPath } from '../src/ai-ready.js';

const md = ( html ) => createConverter( { site: 'https://ex.com' } )( findNode( parseHtml( html ), ( n ) => n.tag === 'main' ) );

test( 'parseHtml: entities, void and raw elements, implicit closes', () => {
	const tree = parseHtml( '<!doctype html><p>a &amp; b<br>c<p>d</p><script>if (a<b) {}</script><ul><li>x<li>y</ul>' );
	const kids = tree.children.map( ( n ) => n.tag );
	assert.deepEqual( kids, [ 'p', 'p', 'script', 'ul' ] );
	assert.equal( tree.children[ 0 ].children[ 0 ].value, 'a & b' );
	assert.equal( tree.children[ 2 ].children[ 0 ].value, 'if (a<b) {}' );
	assert.equal( tree.children[ 3 ].children.length, 2 );
} );

test( 'converter: headings, emphasis, links, lists, code, tables', () => {
	const out = md( `<main>
		<h1>Title <span aria-hidden="true">#</span></h1>
		<p>Some <strong>bold</strong> and <a href="/docs">docs</a>.</p>
		<ol><li>One<ul><li>sub</li></ul></li><li>Two</li></ol>
		<figure class="codeblock"><figcaption><span>astro.config.mjs</span><button>Copy</button></figcaption><pre><code>let a = 1;
</code></pre></figure>
		<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>x | y</td></tr></table>
		<svg><text>no</text></svg><nav><a href="/x">nav</a></nav>
	</main>` );
	assert.equal( out, [
		'# Title',
		'Some **bold** and [docs](https://ex.com/docs).',
		'1. One\n   - sub\n2. Two',
		'```js\nlet a = 1;\n```',
		'| A | B |\n| --- | --- |\n| 1 | x \\| y |',
	].join( '\n\n' ) + '\n' );
} );

test( 'converter: card links, link rows, ARIA tables', () => {
	const out = md( `<main>
		<a href="/f/a"><h3>Card</h3><p>Text</p></a>
		<div><a href="/a">A</a><a href="/b">B</a></div>
		<div role="table"><div role="row"><span>K</span><span>V</span></div><div role="row"><span>k</span><span>v</span></div></div>
	</main>` );
	assert.match( out, /### \[Card\]\(https:\/\/ex\.com\/f\/a\)\n\nText/ );
	assert.match( out, /- \[A\]\(https:\/\/ex\.com\/a\)\n- \[B\]\(https:\/\/ex\.com\/b\)/ );
	assert.match( out, /\| K \| V \|\n\| --- \| --- \|\n\| k \| v \|/ );
} );

test( 'markdownPath', () => {
	assert.equal( markdownPath( '/' ), '/index.md' );
	assert.equal( markdownPath( '/docs/x/' ), '/docs/x.md' );
	assert.equal( markdownPath( '/fr' ), '/fr.md' );
} );

const page = ( lang, title, description, body, extra = '' ) =>
	`<!doctype html><html lang="${ lang }"><head><title>${ title }</title><meta name="description" content="${ description }"><meta property="og:site_name" content="Ex">${ extra }</head><body><header><a href="/docs">Docs</a><a href="/about">About</a></header><main>${ body }</main></body></html>`;

test( 'buildAiFiles: Markdown, llms.txt per language, sitemap with hreflang', async () => {
	const dir = await mkdtemp( join( tmpdir(), 'leiho-ai-' ) );
	const write = async ( path, html ) => {
		await mkdir( join( dir, path ), { recursive: true } );
		await writeFile( join( dir, path, 'index.html' ), html );
	};
	await write( '', page( 'en', 'Ex · Home', 'Home text', '<h1>Home</h1>' ) );
	await write( 'docs', page( 'en', 'Docs · Ex', 'All docs', '<h1>Docs</h1>' ) );
	await write( 'docs/install', page( 'en', 'Install · Ex', 'Install it', '<h1>Install</h1><p>Run it.</p>', '<meta property="article:modified_time" content="2026-03-04T10:00:00Z">' ) );
	await write( 'about', page( 'en', 'About · Ex', 'Who', '<h1>About</h1>' ) );
	await write( 'drafts/x', page( 'en', 'Draft', '', '<h1>Draft</h1>' ) );
	await write( 'hidden', page( 'en', 'Hidden', '', '<h1>Hidden</h1>', '<meta name="robots" content="noindex">' ) );
	await write( 'fr', page( 'fr', 'Ex · Accueil', 'Accueil', '<h1>Accueil</h1>' ) );
	await write( 'fr/docs/install', page( 'fr', 'Installer · Ex', 'Installez', '<h1>Installer</h1>' ) );

	const { pages } = await buildAiFiles( { dir, site: 'https://ex.com/', exclude: [ '/drafts' ], intro: { en: 'Ex is a thing.' }, sections: { en: { docs: 'Documentation' } } } );
	assert.equal( pages, 6 );

	const install = await readFile( join( dir, 'docs/install.md' ), 'utf8' );
	assert.equal( install, '---\ntitle: "Install · Ex"\ndescription: "Install it"\nurl: https://ex.com/docs/install\nlang: en\nupdated: 2026-03-04\n---\n# Install\n\nRun it.\n' );
	assert.ok( ( await readFile( join( dir, 'index.md' ), 'utf8' ) ).includes( '# Home' ) );
	assert.ok( ( await readFile( join( dir, 'fr.md' ), 'utf8' ) ).includes( '# Accueil' ) );

	const llms = await readFile( join( dir, 'llms.txt' ), 'utf8' );
	assert.equal( llms, [
		'# Ex',
		'',
		'> Home text',
		'',
		'Ex is a thing.',
		'',
		'## Documentation',
		'',
		'- [Docs](https://ex.com/docs.md): All docs',
		'- [Install](https://ex.com/docs/install.md): Install it',
		'',
		'## Pages',
		'',
		'- [About](https://ex.com/about.md): Who',
		'',
		'## Other languages',
		'',
		'- [FR](https://ex.com/fr/llms.txt)',
		'',
	].join( '\n' ) );
	assert.ok( ( await readFile( join( dir, 'fr/llms.txt' ), 'utf8' ) ).includes( '- [Installer](https://ex.com/fr/docs/install.md): Installez' ) );
	assert.ok( ( await readFile( join( dir, 'llms-full.txt' ), 'utf8' ) ).includes( 'Source: https://ex.com/docs/install (updated 2026-03-04)\n\n# Install' ) );

	const sitemap = await readFile( join( dir, 'sitemap.xml' ), 'utf8' );
	assert.ok( sitemap.includes( '<loc>https://ex.com/docs/install</loc>\n    <lastmod>2026-03-04</lastmod>\n    <xhtml:link rel="alternate" hreflang="en" href="https://ex.com/docs/install"/>' ) );
	assert.ok( sitemap.includes( 'hreflang="fr" href="https://ex.com/fr/docs/install"' ) );
	assert.ok( ! sitemap.includes( 'drafts' ) && ! sitemap.includes( 'hidden' ) );
	assert.ok( sitemap.includes( '<loc>https://ex.com/about</loc>\n  </url>' ) );
} );

test( 'buildAiFiles: needs the site address', async () => {
	await assert.rejects( buildAiFiles( { dir: tmpdir() } ), /site/ );
} );
