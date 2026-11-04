'use client';

/**
 * Point d'entrée React : <LeihoBridge wpOrigin="https://admin.monsite.fr" />
 *
 * À placer une fois, dans le layout racine (Next.js : app/layout.tsx ou
 * pages/_app.tsx ; Remix / React Router : root.tsx ; Vite : App.tsx).
 *
 * Le composant ne rend rien et ne charge le bridge que dans l'éditeur Leiho
 * (iframe) : les visiteurs ne téléchargent pas le code du bridge, grâce à
 * l'import dynamique.
 */
import { useEffect } from 'react';

export function LeihoBridge( { wpOrigin, cloakTimeout = 0 } ) {
	useEffect( () => {
		if ( typeof window === 'undefined' || window.self === window.top || ! wpOrigin ) {
			return undefined;
		}

		let cancelled = false;
		import( './index.js' ).then( ( module ) => {
			if ( ! cancelled ) {
				// Pas de masquage par défaut : en React le bridge arrive après
				// l'hydratation, masquer la page à ce moment provoquerait un clignotement.
				module.initLeihoBridge( { wpOrigin, cloakTimeout } );
			}
		} );

		return () => {
			cancelled = true;
		};
	}, [ wpOrigin, cloakTimeout ] );

	return null;
}

export default LeihoBridge;
