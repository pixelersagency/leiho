<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress en back-office seul : le site public est le front headless.
 *
 * Trois réglages, activés à la première installation :
 *
 *   headless_noindex   le CMS n'est jamais indexé (X-Robots-Tag, robots.txt,
 *                      plus de sitemap) : sans ça, Google peut référencer
 *                      cms.monsite.fr en double du vrai site ;
 *   headless_redirect  une page publique de WordPress redirige (301) vers la
 *                      même page sur le front ;
 *   headless_lockdown  surface d'attaque réduite : XML-RPC coupé, liste des
 *                      utilisateurs fermée aux visiteurs, version masquée.
 *
 * Rien de tout ça ne touche wp-admin, la connexion, l'API REST du contenu ni
 * l'aperçu des brouillons.
 */
class Leiho_Headless {

	const FLAGS = array( 'headless_noindex', 'headless_redirect', 'headless_lockdown' );

	/**
	 * @return void
	 */
	public static function init() {
		$settings = Leiho_Plugin::settings();

		if ( $settings['headless_noindex'] ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99 );
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
			add_action( 'send_headers', array( __CLASS__, 'noindex_header' ) );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'noindex_rest' ) );
		}

		if ( $settings['headless_redirect'] && '' !== $settings['front_url'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'redirect_to_front' ), 0 );
		}

		if ( $settings['headless_lockdown'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_users_endpoint' ) );
			add_filter( 'the_generator', '__return_empty_string' );
			remove_action( 'wp_head', 'wp_generator' );
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
	}

	/**
	 * Première installation : le CMS est durci d'emblée. Un site qui avait
	 * déjà Leiho garde son comportement tant que l'admin ne coche rien.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		if ( false !== get_option( Leiho_Plugin::OPTION_SETTINGS ) ) {
			return;
		}
		$settings = array();
		foreach ( self::FLAGS as $flag ) {
			$settings[ $flag ] = true;
		}
		add_option( Leiho_Plugin::OPTION_SETTINGS, $settings );
	}

	public static function noindex_header() {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * @param WP_REST_Response $response Réponse.
	 * @return WP_REST_Response
	 */
	public static function noindex_rest( $response ) {
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}
		return $response;
	}

	/**
	 * Rien à explorer sur le CMS… sauf les médias : un front headless sert
	 * souvent ses images depuis /wp-content/uploads/ du CMS, elles doivent
	 * rester indexables (Google Images, aperçus). Les pages du CMS restent
	 * hors index par X-Robots-Tag.
	 *
	 * @return string
	 */
	public static function robots_txt() {
		return "User-agent: *\nAllow: " . self::uploads_path() . "\nDisallow: /\n";
	}

	/**
	 * Chemin des médias sur le CMS (« /wp-content/uploads/ » par défaut).
	 *
	 * @return string
	 */
	private static function uploads_path() {
		$path = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['baseurl'] ) ) {
				$path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			}
		}
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/wp-content/uploads/' : $path . '/';
	}

	/**
	 * Visiteur sur une page publique de WordPress : direction le front.
	 *
	 * @return void
	 */
	public static function redirect_to_front() {
		// Aperçu d'un brouillon, robots.txt, favicon : WordPress répond lui-même.
		if ( is_preview() || is_robots() || ( function_exists( 'is_favicon' ) && is_favicon() ) || is_customize_preview() ) {
			return;
		}

		$target = self::front_target();
		if ( '' === $target ) {
			return;
		}

		// 301 pour les visiteurs (le front est l'adresse officielle) ; 302 pour
		// les utilisateurs connectés, dont le navigateur ne doit rien mémoriser.
		wp_redirect( $target, is_user_logged_in() ? 302 : 301, 'Leiho' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Hôte du front, réglé par l'admin.
		exit;
	}

	/**
	 * URL du front qui correspond à la requête courante.
	 *
	 * @return string
	 */
	public static function front_target() {
		$settings = Leiho_Plugin::settings();
		$front    = untrailingslashit( $settings['front_url'] );
		if ( '' === $front ) {
			return '';
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				return $front . ( new Leiho_Url_Mapper() )->path( $post );
			}
		}

		if ( is_front_page() || is_home() ) {
			return $front . '/';
		}

		// Ailleurs (archive, 404…) : même chemin sur le front, sans la requête
		// (?p=12, ?author=1 n'ont pas de sens hors de WordPress).
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		return $front . '/' . ltrim( $path, '/' );
	}

	/**
	 * /wp/v2/users liste les identifiants de connexion : fermé aux visiteurs.
	 * Les utilisateurs connectés le gardent (l'éditeur de blocs lit /users/me).
	 *
	 * @param array $endpoints Routes REST.
	 * @return array
	 */
	public static function hide_users_endpoint( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}
}
