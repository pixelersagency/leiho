/**
 * Attributs d'annotation Leiho, à étaler sur un élément au lieu de les écrire
 * à la main :
 *
 *   import { field, block } from 'leiho';
 *
 *   <section {...block( page, 'sections', i, 'Hero' )}>
 *     <h1 {...field( page, 'hero_title' )}>{ page.fields.hero_title }</h1>
 *   </section>
 *
 * JSX (React, Next.js, Astro, Solid), Vue (v-bind="field( page, 'x' )") et
 * Svelte ({...field( page, 'x' )}) acceptent tous un objet d'attributs.
 * Les fonctions sont pures : aucun accès au DOM, utilisables au rendu
 * serveur comme au build.
 */

/**
 * Identifiant WordPress d'une entrée de l'export (ou l'identifiant lui-même).
 *
 * @param {object|number|string} entry
 * @returns {string} '' si introuvable.
 */
function postId( entry ) {
	const id = entry && 'object' === typeof entry ? entry.id : entry;
	return 'number' === typeof id || ( 'string' === typeof id && /^\d+$/.test( id ) ) ? String( id ) : '';
}

/**
 * @param {object|number|string} entry
 * @returns {Record<string,string>}
 */
function withPost( entry ) {
	const id = postId( entry );
	return id ? { 'data-wp-post': id } : {};
}

/**
 * Élément qui affiche un champ.
 *
 * @param {object|number|string} entry   Entrée de l'export (ou son id WordPress).
 * @param {string}               path    Chemin du champ : 'hero_title', 'cards.0.title', '_title', '_thumbnail'.
 * @param {object}               [options]
 * @param {boolean}              [options.html]    La valeur contient du balisage (injectée en HTML filtré).
 * @param {boolean|string}       [options.refresh] Pas d'aperçu en direct : la page est rechargée après l'enregistrement.
 * @param {string}               [options.style]   Champ couleur : propriété CSS à modifier (défaut : color).
 * @returns {Record<string,string>}
 */
export function field( entry, path, options = {} ) {
	const attrs = { ...withPost( entry ), 'data-wp-field': String( path ) };
	if ( options.html ) {
		attrs[ 'data-leiho-apply' ] = 'html';
	}
	if ( options.refresh ) {
		attrs[ 'data-leiho-refresh' ] = true === options.refresh ? 'save' : String( options.refresh );
	}
	if ( options.style ) {
		attrs[ 'data-leiho-style' ] = String( options.style );
	}
	return attrs;
}

/**
 * Ligne d'un contenu flexible (ou bloc d'une liste de blocs) : l'éditeur peut
 * l'ajouter, la déplacer, la dupliquer et la supprimer.
 *
 * @param {object|number|string} entry Entrée de l'export (ou son id WordPress).
 * @param {string}               path  Champ de blocs : 'sections'.
 * @param {number}               index Rang de la ligne.
 * @param {string}               [label] Nom affiché dans l'arbre de structure.
 * @returns {Record<string,string>}
 */
export function block( entry, path, index, label ) {
	const attrs = { ...withPost( entry ), 'data-leiho-layout': String( path ) + '.' + Number( index ) };
	if ( label ) {
		attrs[ 'data-leiho-section' ] = String( label );
	}
	return attrs;
}

/**
 * Conteneur d'une entrée (les champs à l'intérieur peuvent alors se passer
 * de l'entrée : field( null, 'x' ) n'écrit que data-wp-field).
 *
 * @param {object|number|string} entry
 * @param {string}               [label] Section nommée dans l'arbre de structure.
 * @returns {Record<string,string>}
 */
export function entry( entry, label ) {
	const attrs = withPost( entry );
	if ( label ) {
		attrs[ 'data-leiho-section' ] = String( label );
	}
	return attrs;
}
