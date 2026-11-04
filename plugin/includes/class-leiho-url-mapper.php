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
		$override = get_post_meta( $post->ID, self::META_PATH, true );
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			return '/' . ltrim( trim( $override ), '/' );
		}

		$settings = Leiho_Plugin::settings();
		if ( '' !== $settings['path_meta_key'] ) {
			$meta_path = get_post_meta( $post->ID, $settings['path_meta_key'], true );
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
