<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mapping contenu WordPress → URL sur le front headless.
 *
 * Ordre de résolution du chemin :
 *   1. Override manuel par post (meta _leiho_path).
 *   2. Meta configurée dans les réglages (path_meta_key) — permet de
 *      réutiliser un champ ACF existant type « chemin de la page ».
 *   3. Page d'accueil (page_on_front) → '/'.
 *   4. Pattern par post type ({slug}, {id}), défaut '/{slug}'.
 */
class Leiho_Url_Mapper {

	const META_PATH = '_leiho_path';

	/**
	 * Chemin relatif du post sur le front.
	 *
	 * @param WP_Post $post Post.
	 * @return string Chemin commençant par '/'.
	 */
	public function path( $post ) {
		$path = $this->default_path( $post );
		// Entrée de Collections lue dans une autre langue : chemin préfixé (/fr/…).
		if ( class_exists( 'Leiho_I18n' ) && Leiho_I18n::is_translation() && Leiho_Collections::for_post_type( $post->post_type ) ) {
			return Leiho_I18n::path( $path, Leiho_I18n::current() );
		}
		return $path;
	}

	/**
	 * Chemin dans la langue par défaut.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function default_path( $post ) {
		$override = get_post_meta( $post->ID, self::META_PATH, true );
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			return '/' . ltrim( trim( $override ), '/' );
		}

		$settings = Leiho_Plugin::settings();
		if ( '' !== $settings['path_meta_key'] ) {
			// Type servi par Leiho Collections : le champ vit dans la table de la
			// collection (la postmeta d'avant un import ACF n'est plus tenue à jour).
			$meta_path = self::collection_value( $post, $settings['path_meta_key'] );
			if ( null === $meta_path ) {
				$meta_path = get_post_meta( $post->ID, $settings['path_meta_key'], true );
			}
			if ( is_string( $meta_path ) && '' !== trim( $meta_path ) ) {
				return '/' . ltrim( trim( $meta_path ), '/' );
			}
		}

		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			return '/';
		}

		$settings = Leiho_Plugin::settings();
		$patterns = $settings['url_patterns'];
		$pattern  = isset( $patterns[ $post->post_type ] ) && '' !== $patterns[ $post->post_type ]
			? $patterns[ $post->post_type ]
			: '/{slug}';

		$path = str_replace(
			array( '{slug}', '{id}' ),
			array( $post->post_name, (string) $post->ID ),
			$pattern
		);

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Champ de collection qui porte ce nom, si le type est servi par Leiho
	 * Collections (collection native, ou import ACF basculé).
	 *
	 * @param string $post_type Post type.
	 * @param string $name      Nom du champ (réglage path_meta_key).
	 * @return array{id:string,key:string,column:string}|null null : lire la postmeta.
	 */
	public static function collection_field( $post_type, $name ) {
		if ( '' === (string) $name || ! class_exists( 'Leiho_Collections' ) || ! class_exists( 'Leiho_Collections_Store' ) ) {
			return null;
		}
		$collection = Leiho_Collections::for_post_type( (string) $post_type );
		if ( ! $collection ) {
			return null;
		}
		// Type importé depuis ACF mais pas encore basculé (ou à examiner) : ACF sert encore.
		if ( class_exists( 'Leiho_ACF_Import' ) && 'acf' === Leiho_ACF_Import::engine( (string) $post_type ) ) {
			return null;
		}
		$state = Leiho_Collections_Store::collection_state( $collection['id'] );
		foreach ( isset( $collection['fields'] ) ? $collection['fields'] : array() as $field ) {
			if ( isset( $field['name'], $field['key'] ) && $name === $field['name'] && isset( $state['columns'][ $field['key'] ]['column'] ) ) {
				return array(
					'id'     => $collection['id'],
					'key'    => $field['key'],
					'column' => $state['columns'][ $field['key'] ]['column'],
				);
			}
		}
		return null;
	}

	/**
	 * Valeur du champ « chemin » lue dans la collection.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $name Nom du champ.
	 * @return string|null null si le type n'a pas ce champ dans une collection servie.
	 */
	private static function collection_value( $post, $name ) {
		$field = self::collection_field( $post->post_type, $name );
		if ( ! $field ) {
			return null;
		}
		$values = Leiho_Collections_Store::values( $field['id'], (int) $post->ID );
		return isset( $values[ $field['key'] ] ) && is_scalar( $values[ $field['key'] ] ) ? (string) $values[ $field['key'] ] : '';
	}

	/**
	 * URL publique du post sur le front de prod.
	 *
	 * @param WP_Post $post Post.
	 * @return string Vide si le front n'est pas configuré.
	 */
	public function front_url( $post ) {
		$settings = Leiho_Plugin::settings();
		if ( '' === $settings['front_url'] ) {
			return '';
		}
		return untrailingslashit( $settings['front_url'] ) . $this->path( $post );
	}

	/**
	 * URL chargée dans l'iframe (preview si configurée, sinon prod).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function preview_url( $post ) {
		$settings = Leiho_Plugin::settings();
		$base     = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];
		if ( '' === $base ) {
			return '';
		}
		return untrailingslashit( $base ) . $this->path( $post );
	}

	/**
	 * Page modèle d'un contenu qui n'est pas encore sur le site (brouillon,
	 * en attente, programmé) : un contenu publié du même type, le plus
	 * récent, dans la page duquel l'éditeur montre le brouillon (le bridge
	 * rattache la page au brouillon et y applique ses valeurs).
	 *
	 * Type hiérarchique (pages) : seulement une page de même modèle de page,
	 * hors modèle par défaut (deux pages n'ont sinon aucune raison de partager
	 * une mise en page). Aucune page modèle : null, l'éditeur charge
	 * l'adresse du brouillon.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Post|null
	 */
	public function stand_in( $post ) {
		$stand_in = null;
		if ( 'publish' !== $post->post_status ) {
			$args = array(
				'post_type'        => $post->post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'post__not_in'     => array_filter( array( (int) $post->ID, (int) get_option( 'page_on_front' ) ) ),
				'no_found_rows'    => true,
				'suppress_filters' => false,
			);
			$object = get_post_type_object( $post->post_type );
			if ( $object && ! empty( $object->hierarchical ) ) {
				$template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
				if ( '' === $template || 'default' === $template ) {
					$args = null;
				} else {
					$args['meta_key']   = '_wp_page_template'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$args['meta_value'] = $template; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				}
			}
			if ( $args ) {
				$found    = get_posts( $args );
				$stand_in = $found ? $found[0] : null;
			}
		}

		/**
		 * Page modèle dans laquelle l'éditeur montre un contenu qui n'est pas
		 * encore sur le site. null : aucune (un front qui sait afficher les
		 * brouillons à leur propre adresse, par exemple).
		 *
		 * @param WP_Post|null $stand_in Contenu publié choisi.
		 * @param WP_Post      $post     Contenu édité.
		 */
		$stand_in = apply_filters( 'leiho_preview_stand_in', $stand_in, $post );
		return $stand_in instanceof WP_Post && (int) $stand_in->ID !== (int) $post->ID && 'publish' === $stand_in->post_status ? $stand_in : null;
	}

	/**
	 * Origin (scheme + host [+ port]) de l'URL chargée dans l'iframe,
	 * pour la vérification postMessage côté admin.
	 *
	 * @return string
	 */
	public function preview_origin() {
		$settings = Leiho_Plugin::settings();
		$base     = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];
		if ( '' === $base ) {
			return '';
		}
		$parts = wp_parse_url( $base );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}
}
