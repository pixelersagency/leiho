/**
 * Génère dist/leiho-bridge.js (IIFE + auto-init) depuis src/index.js.
 * Aucune dépendance : node bridge/build.js (ESM, comme le package).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname( fileURLToPath( import.meta.url ) );
const src = fs.readFileSync( path.join( dir, 'src/index.js' ), 'utf8' );

const body = src
	.replace( /^export function initLeihoBridge/m, 'function initLeihoBridge' )
	.replace( /\/\/ Pas d'auto-init en ESM[\s\S]*$/m, '' )
	.trimEnd();

const out = `/*!
 * leiho-bridge v5 — standalone (auto-init).
 * Généré par bridge/build.js depuis src/index.js — ne pas éditer à la main.
 *
 * Chargement recommandé (le bridge ne sert qu'à l'éditeur) :
 *   <script>
 *     if (window.self !== window.top) {
 *       var s = document.createElement('script');
 *       s.src = '/leiho-bridge.js';
 *       s.setAttribute('data-wp-origin', 'https://admin.monsite.fr');
 *       document.head.appendChild(s);
 *     }
 *   </script>
 */
( function () {
	'use strict';

${ body
	.split( '\n' )
	.map( ( line ) => ( line.trim() ? '\t' + line : line ) )
	.join( '\n' ) }

	window.initLeihoBridge = initLeihoBridge;
	window.initOknoBridge = initLeihoBridge; // Nom d'avant le renommage en Leiho.

	// Auto-init depuis l'attribut du tag <script>.
	var script = document.currentScript;
	var origin = script && script.getAttribute( 'data-wp-origin' );
	if ( origin ) {
		initLeihoBridge( {
			wpOrigin: origin,
			cloakTimeout: Number( script.getAttribute( 'data-leiho-cloak-timeout' ) || script.getAttribute( 'data-okno-cloak-timeout' ) ) || undefined,
		} );
	}
} )();
`;

fs.writeFileSync( path.join( dir, 'dist/leiho-bridge.js' ), out );
console.log( 'dist/leiho-bridge.js écrit (' + out.length + ' octets)' );

// Copie livrée avec le plugin : téléchargeable depuis wp-admin (Leiho → Démarrer).
const pluginCopy = path.join( dir, '../plugin/assets/bridge/leiho-bridge.js' );
fs.mkdirSync( path.dirname( pluginCopy ), { recursive: true } );
fs.writeFileSync( pluginCopy, out );
console.log( 'plugin/assets/bridge/leiho-bridge.js mis à jour' );
