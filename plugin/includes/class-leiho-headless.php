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
 *   headless_lockdown  surface d'attaque réduite : XML-RPC et pingbacks
 *                      coupés, liste des utilisateurs et pages d'auteur
 *                      fermées aux visiteurs, version masquée.
 *
 * Et, dans l'onglet Sécurité :
 *
 *   security_file_edit éditeur de fichiers des thèmes et extensions coupé
 *                      (DISALLOW_FILE_EDIT) ;
 *   security_login     erreurs de connexion qui ne disent plus si
 *                      l'identifiant existe, essais limités par IP ;
 *   headless_comments  commentaires coupés (hors réglages d'office : un
 *                      front peut s'en servir).
 *
 * Rien de tout ça ne touche wp-admin, la connexion des comptes, l'API REST du
 * contenu, les mots de passe d'application (agents) ni l'aperçu des brouillons.
 */
class Leiho_Headless {

	/** Réglages activés d'office à la première installation. */
	const FLAGS = array( 'headless_noindex', 'headless_redirect', 'headless_lockdown', 'security_file_edit', 'security_login' );

	/** Tous les réglages de l'onglet Sécurité. */
	const KEYS = array( 'headless_noindex', 'headless_redirect', 'headless_comments', 'headless_lockdown', 'security_file_edit', 'security_login' );

	/** Essais de connexion ratés avant blocage, par IP et identifiant. */
	const LOGIN_MAX = 5;

	/** Durée du blocage, en secondes. */
	const LOGIN_WINDOW = 900;

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
			add_filter( 'wp_headers', array( __CLASS__, 'drop_pingback_header' ) );
			add_action( 'template_redirect', array( __CLASS__, 'hide_author_pages' ), -1 );
		}

		// Constante lue par map_meta_cap() à chaque vérification : la définir
		// ici suffit, et décocher la case la retire à la requête suivante.
		if ( ! empty( $settings['security_file_edit'] ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( ! empty( $settings['security_login'] ) ) {
			add_filter( 'authenticate', array( __CLASS__, 'login_gate' ), 99, 2 );
			add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 2 );
			add_action( 'wp_login', array( __CLASS__, 'login_succeeded' ) );
		}

		if ( ! empty( $settings['headless_comments'] ) ) {
			add_filter( 'comments_open', '__return_false', 20 );
			add_filter( 'pings_open', '__return_false', 20 );
			add_action( 'init', array( __CLASS__, 'drop_comment_support' ), 100 );
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_comments_endpoint' ) );
			add_action( 'admin_menu', array( __CLASS__, 'hide_comments_menu' ) );
			add_action( 'admin_bar_menu', array( __CLASS__, 'hide_comments_bar' ), 999 );
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
	/**
	 * @param array $headers En-têtes HTTP.
	 * @return array
	 */
	public static function drop_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * ?author=1 puis /author/admin/ donnent l'identifiant de connexion :
	 * page introuvable pour les visiteurs.
	 *
	 * @return void
	 */
	public static function hide_author_pages() {
		if ( is_user_logged_in() || ! is_author() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/* ---------------------------------------------------------------------
	 * Connexion
	 * ------------------------------------------------------------------- */

	/**
	 * Adresse du visiteur. REMOTE_ADDR seule : un en-tête X-Forwarded-For se
	 * falsifie. Derrière un proxy (Cloudflare…), le filtre leiho_login_ip
	 * donne la vraie adresse.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Haché, jamais affiché.
		/**
		 * Adresse retenue pour limiter les essais de connexion.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'leiho_login_ip', $ip );
	}

	/**
	 * Clé du compteur : IP et identifiant ensemble, pour qu'un attaquant ne
	 * bloque pas tout un réseau partagé, ni un compte depuis une autre IP.
	 *
	 * @param string $username Identifiant saisi.
	 * @return string
	 */
	private static function login_key( $username ) {
		return 'leiho_login_' . md5( self::client_ip() . '|' . strtolower( trim( (string) $username ) ) );
	}

	/**
	 * Avant la vérification du mot de passe… et après : WordPress a déjà
	 * répondu (priorité 20), Leiho bloque ou rend l'erreur générique.
	 *
	 * @param WP_User|WP_Error|null $user     Résultat de l'authentification.
	 * @param string                $username Identifiant saisi.
	 * @return WP_User|WP_Error|null
	 */
	public static function login_gate( $user, $username ) {
		if ( '' === trim( (string) $username ) ) {
			return $user;
		}
		if ( (int) get_transient( self::login_key( $username ) ) >= self::LOGIN_MAX ) {
			return new WP_Error(
				'leiho_login_locked',
				/* translators: %d: minutes. */
				sprintf( __( '<strong>Error:</strong> too many failed attempts. Try again in %d minutes.', 'leiho' ), (int) ( self::LOGIN_WINDOW / 60 ) )
			);
		}
		if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
			return new WP_Error(
				'leiho_login_failed',
				__( '<strong>Error:</strong> the username or password is incorrect.', 'leiho' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . __( 'Lost your password?', 'leiho' ) . '</a>'
			);
		}
		return $user;
	}

	/**
	 * @param string        $username Identifiant saisi.
	 * @param WP_Error|null $error    Erreur (WordPress 5.4+).
	 * @return void
	 */
	public static function login_failed( $username, $error = null ) {
		if ( '' === trim( (string) $username ) || ( is_wp_error( $error ) && in_array( 'leiho_login_locked', $error->get_error_codes(), true ) ) ) {
			return;
		}
		$key = self::login_key( $username );
		set_transient( $key, (int) get_transient( $key ) + 1, self::LOGIN_WINDOW );
	}

	/**
	 * @param string $username Identifiant.
	 * @return void
	 */
	public static function login_succeeded( $username ) {
		delete_transient( self::login_key( $username ) );
	}

	/* ---------------------------------------------------------------------
	 * Commentaires
	 * ------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	public static function drop_comment_support() {
		foreach ( get_post_types() as $type ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
	}

	/**
	 * @param array $endpoints Routes REST.
	 * @return array
	 */
	public static function hide_comments_endpoint( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * @return void
	 */
	public static function hide_comments_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/**
	 * @param WP_Admin_Bar $bar Barre d'admin.
	 * @return void
	 */
	public static function hide_comments_bar( $bar ) {
		$bar->remove_node( 'comments' );
	}
}
