import { test } from 'node:test';
import assert from 'node:assert/strict';
import { jsonLd, leihoGraph, seoMeta } from '../src/seo.js';

const item = {
	title: 'Install',
	modified: '2026-02-03T10:00:00+00:00',
	seo: {
		type: 'TechArticle',
		title: 'Install',
		description: 'Install it.',
		image: { url: 'https://cdn/c.jpg', alt: 'Cover' },
		jsonld: [
			{ '@type': 'WebPage', '@id': 'https://cms.ex.com/docs/install#webpage', url: 'https://cms.ex.com/docs/install', name: 'Install', inLanguage: 'en' },
			{ '@type': 'TechArticle', '@id': 'https://cms.ex.com/docs/install#article', headline: 'Install', mainEntityOfPage: { '@id': 'https://cms.ex.com/docs/install#webpage' } },
		],
	},
};

test( 'leihoGraph: site, organization, item nodes on the canonical address, breadcrumbs', () => {
	const g = leihoGraph( {
		url: '/docs/install',
		item,
		site: { url: 'https://ex.com/', name: 'Ex', locales: [ 'en', 'fr' ] },
		organization: { name: 'Ex Inc.', logo: '/logo.png' },
		breadcrumbs: [ { name: 'Docs', url: '/docs' }, { name: 'Install' } ],
	} );
	const types = g[ '@graph' ].map( ( n ) => n[ '@type' ] );
	assert.deepEqual( types, [ 'Organization', 'WebSite', 'WebPage', 'TechArticle', 'BreadcrumbList' ] );
	const [ org, , page, article, crumbs ] = g[ '@graph' ];
	assert.equal( org.logo.url, 'https://ex.com/logo.png' );
	assert.equal( page[ '@id' ], 'https://ex.com/docs/install#webpage' );
	assert.equal( page.url, 'https://ex.com/docs/install' );
	assert.deepEqual( page.isPartOf, { '@id': 'https://ex.com/#website' } );
	assert.deepEqual( page.breadcrumb, { '@id': 'https://ex.com/docs/install#breadcrumb' } );
	assert.deepEqual( article.mainEntityOfPage, { '@id': 'https://ex.com/docs/install#webpage' } );
	assert.deepEqual( article.publisher, { '@id': 'https://ex.com/#organization' } );
	assert.deepEqual( crumbs.itemListElement.map( ( c ) => c.item ), [ 'https://ex.com', 'https://ex.com/docs', 'https://ex.com/docs/install' ] );
} );

test( 'leihoGraph: page without item, empty values dropped', () => {
	const g = leihoGraph( { url: 'https://ex.com/about', site: { url: 'https://ex.com', name: 'Ex' }, title: 'About', type: 'AboutPage', description: '' } );
	assert.deepEqual( g[ '@graph' ][ 1 ], { '@type': 'AboutPage', '@id': 'https://ex.com/about#webpage', url: 'https://ex.com/about', name: 'About', isPartOf: { '@id': 'https://ex.com/#website' } } );
} );

test( 'jsonLd escapes closing tags', () => {
	assert.equal( jsonLd( { a: '</script>' } ), '{"a":"\\u003c/script>"}' );
} );

test( 'seoMeta: description, Open Graph, article type, large card with an image', () => {
	const m = seoMeta( item, { url: 'https://ex.com/docs/install', siteName: 'Ex' } );
	assert.equal( m.description, 'Install it.' );
	assert.ok( m.meta.some( ( t ) => t.property === 'og:type' && t.content === 'article' ) );
	assert.ok( m.meta.some( ( t ) => t.name === 'twitter:card' && t.content === 'summary_large_image' ) );
	assert.ok( m.meta.some( ( t ) => t.property === 'og:image:alt' && t.content === 'Cover' ) );
} );
