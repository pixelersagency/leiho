import { test } from 'node:test';
import assert from 'node:assert/strict';
import { field, block, entry } from '../src/attrs.js';

test( 'field : identifiant et chemin', () => {
	assert.deepEqual( field( { id: 12 }, 'intro' ), { 'data-wp-post': '12', 'data-wp-field': 'intro' } );
	assert.deepEqual( field( null, 'intro' ), { 'data-wp-field': 'intro' } );
} );

test( 'field : options html, refresh, style', () => {
	assert.equal( field( 1, 'x', { html: true } )[ 'data-leiho-apply' ], 'html' );
	assert.equal( field( 1, 'x', { refresh: true } )[ 'data-leiho-refresh' ], 'save' );
	assert.equal( field( 1, 'x', { style: 'background-color' } )[ 'data-leiho-style' ], 'background-color' );
} );

test( 'field : galerie', () => {
	assert.equal( field( 1, 'photos', { gallery: true } )[ 'data-leiho-apply' ], 'gallery' );
	// gallery l'emporte sur html : la valeur est une liste d'images, pas du HTML.
	assert.equal( field( 1, 'photos', { gallery: true, html: true } )[ 'data-leiho-apply' ], 'gallery' );
} );

test( 'block et entry', () => {
	assert.deepEqual( block( 3, 'sections', 2, 'Hero' ), { 'data-wp-post': '3', 'data-leiho-layout': 'sections.2', 'data-leiho-section': 'Hero' } );
	assert.deepEqual( entry( { id: 4 }, 'Article' ), { 'data-wp-post': '4', 'data-leiho-section': 'Article' } );
} );
