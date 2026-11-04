/**
 * Okno — publication automatique dans le reste de wp-admin.
 *
 * Chargé sur les écrans d'administration (hors éditeur Okno, qui a sa propre
 * logique) pour les utilisateurs qui peuvent publier, quand la publication
 * automatique est active. Aucune interface :
 *  - une publication attend (écran rechargé après « Mettre à jour », ou
 *    enregistrement dans l'éditeur de blocs) : on publie à l'échéance ;
 *  - l'utilisateur quitte wp-admin (onglet fermé, autre site) avec une
 *    publication en attente : on l'envoie tout de suite (sendBeacon).
 * Le serveur ne publie qu'une fois, quel que soit le déclencheur.
 */
( function () {
	'use strict';

	var cfg = window.OknoAutoPublish;
	if ( ! cfg || ! cfg.flushUrl || ! window.fetch ) {
		return;
	}

	var pending = !! cfg.pending;
	var timer = null;
	var savedAt = 0; // Dernier enregistrement vu dans l'éditeur de blocs.
	var stayingInAdmin = false; // Navigation interne : la page suivante prend le relais.
	var stayingTimer = null;

	function nonce() {
		var api = window.wp && window.wp.apiFetch;
		return ( api && api.nonceMiddleware && api.nonceMiddleware.nonce ) || cfg.nonce;
	}

	function schedule( seconds ) {
		clearTimeout( timer );
		timer = setTimeout( flush, Math.max( 1, seconds ) * 1000 );
	}

	function editorIsSaving() {
		var data = window.wp && window.wp.data;
		var editor = data && data.select && data.select( 'core/editor' );
		return !! ( editor && editor.isSavingPost && editor.isSavingPost() );
	}

	function flush() {
		timer = null;
		if ( ! pending ) {
			return;
		}
		if ( editorIsSaving() ) {
			schedule( 5 );
			return;
		}
		window
			.fetch( cfg.flushUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce() },
			} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( body ) {
				if ( body && 'pending' === body.state ) {
					schedule( body.retry_in || 15 );
					return;
				}
				// Publié, déjà publié ailleurs, ou erreur définitive (visible
				// dans l'historique des publications).
				pending = false;
			} )
			.catch( function () {
				schedule( 30 );
			} );
	}

	function flushOnLeave() {
		if ( ! pending || stayingInAdmin ) {
			return;
		}
		pending = false;
		clearTimeout( timer );
		var body = new URLSearchParams( { _wpnonce: nonce() } );
		var sent = false;
		try {
			sent = !! ( navigator.sendBeacon && navigator.sendBeacon( cfg.flushUrl, body ) );
		} catch ( e ) {
			sent = false;
		}
		if ( ! sent ) {
			window.fetch( cfg.flushUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } ).catch( function () {} );
		}
	}

	function stayInAdmin() {
		stayingInAdmin = true;
		// Lien ou formulaire intercepté en JS (pas de navigation) : on réarme.
		clearTimeout( stayingTimer );
		stayingTimer = setTimeout( function () {
			stayingInAdmin = false;
		}, 5000 );
	}

	// Formulaire envoyé (« Mettre à jour », filtres de liste…) ou lien vers une
	// autre page de wp-admin : la page suivante connaîtra l'état en attente,
	// publier maintenant casserait le regroupement des changements.
	document.addEventListener( 'submit', stayInAdmin );
	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest && event.target.closest( 'a[href]' );
		if (
			! link ||
			event.defaultPrevented ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			( link.target && '_self' !== link.target )
		) {
			return;
		}
		if ( cfg.adminUrl && 0 === link.href.indexOf( cfg.adminUrl ) ) {
			stayInAdmin();
		}
	} );
	window.addEventListener( 'pagehide', flushOnLeave );

	// Heartbeat : état serveur à jour (publié par un autre onglet, par le
	// heartbeat lui-même ou par le cron), nonce REST renouvelé.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'heartbeat-tick', function ( event, data ) {
			if ( data && data.rest_nonce ) {
				cfg.nonce = data.rest_nonce;
			}
			var info = data && data.okno_autopublish;
			if ( ! info ) {
				return;
			}
			if ( info.pending ) {
				if ( ! pending || ! timer ) {
					pending = true;
					schedule( info.due_in || cfg.delay );
				}
			} else if ( Date.now() - savedAt > 10000 ) {
				// (Un heartbeat parti avant notre enregistrement ne compte pas.)
				pending = false;
				clearTimeout( timer );
			}
		} );
	}

	// Éditeur de blocs : « Mettre à jour » enregistre sans recharger la page.
	window.addEventListener( 'load', function () {
		var data = window.wp && window.wp.data;
		if ( ! data || ! data.subscribe || ! data.select( 'core/editor' ) ) {
			return;
		}
		var wasSaving = false;
		data.subscribe( function () {
			var editor = data.select( 'core/editor' );
			var saving = editor.isSavingPost() && ! editor.isAutosavingPost();
			if ( wasSaving && ! saving && editor.didPostSaveRequestSucceed() ) {
				pending = true;
				savedAt = Date.now();
				schedule( cfg.delay );
			}
			wasSaving = saving;
		} );
	} );

	if ( pending ) {
		schedule( cfg.dueIn );
	}
} )();
