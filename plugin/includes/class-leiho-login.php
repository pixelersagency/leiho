<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page de connexion : adresse déplacée et habillage Leiho.
 *
 *   login_slug   la connexion se fait à /<slug>/ ; wp-login.php et wp-admin
 *                répondent 404 aux visiteurs, ce qui coupe les robots qui
 *                essaient des mots de passe sur l'adresse connue. Vide : off.
 *   login_style  page de connexion aux couleurs de Leiho, avec le nom et
 *                l'icône du site.
 *
 * Bloqué dehors ? define( 'LEIHO_DEFAULT_LOGIN', true ); dans wp-config.php
 * rend l'adresse habituelle.
 */
class Leiho_Login {

	/** Adresses prises par WordPress ou trop évidentes. */
	const RESERVED = array( 'wp-admin', 'wp-login', 'wp-login-php', 'wp-content', 'wp-includes', 'wp-json', 'admin', 'login', 'dashboard', 'feed', 'xmlrpc', 'xmlrpc-php', 'leiho' );

	/** @var string Adresse de connexion active ('' : celle de WordPress). */
	private static $slug = '';

	/** @var string serve | block | '' */
	private static $route = '';

	/**
	 * @return void
	 */
	public static function init() {
		$settings = Leiho_Plugin::settings();

		if ( ! empty( $settings['login_style'] ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'styles' ) );
			add_filter( 'login_headerurl', array( __CLASS__, 'header_url' ) );
			add_filter( 'login_headertext', array( __CLASS__, 'header_text' ) );
			add_action( 'login_footer', array( __CLASS__, 'footer' ) );
		}

		$slug = self::clean( isset( $settings['login_slug'] ) ? $settings['login_slug'] : '' );
		if ( '' === $slug || self::rescue() || is_multisite() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::$slug  = $slug;
		self::$route = self::match( self::request_path(), $slug );

		foreach ( array( 'site_url', 'network_site_url', 'wp_redirect', 'login_url', 'logout_url', 'lostpassword_url', 'register_url' ) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'rewrite' ), 99 );
		}
		add_action( 'wp_loaded', array( __CLASS__, 'route' ), 1 );
		// /login, /admin, /dashboard menaient vers wp-login.php.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * @return bool L'adresse habituelle est forcée depuis wp-config.php.
	 */
	public static function rescue() {
		return defined( 'LEIHO_DEFAULT_LOGIN' ) && LEIHO_DEFAULT_LOGIN;
	}

	/**
	 * @return string Adresse de connexion active, '' si WordPress garde la sienne.
	 */
	public static function slug() {
		return self::$slug;
	}

	/**
	 * URL de connexion pour une adresse.
	 *
	 * @param string $slug Adresse.
	 * @return string
	 */
	public static function url( $slug ) {
		return home_url( '/' . $slug . '/' );
	}

	/**
	 * @param string $slug Saisie.
	 * @return string Adresse au format d'URL, '' si vide.
	 */
	public static function clean( $slug ) {
		return trim( sanitize_title( (string) $slug ), '-' );
	}

	/**
	 * Adresse acceptable ? Assez longue pour ne pas se deviner, libre.
	 *
	 * @param string $slug Adresse nettoyée.
	 * @return true|WP_Error
	 */
	public static function validate( $slug ) {
		if ( strlen( $slug ) < 4 || strlen( $slug ) > 50 ) {
			return new WP_Error( 'leiho_login_slug', __( 'The login address must be 4 to 50 characters long.', 'leiho' ) );
		}
		if ( in_array( $slug, self::RESERVED, true ) || 0 === strpos( $slug, 'wp-' ) ) {
			return new WP_Error( 'leiho_login_slug', __( 'This login address is reserved by WordPress or too easy to guess.', 'leiho' ) );
		}
		if ( function_exists( 'get_page_by_path' ) && get_page_by_path( $slug, 'OBJECT', get_post_types( array( 'public' => true ) ) ) ) {
			return new WP_Error( 'leiho_login_slug', __( 'A page already uses this address.', 'leiho' ) );
		}
		return true;
	}

	/**
	 * Chemin demandé, relatif à l'adresse du site, sans barre finale.
	 *
	 * @return string
	 */
	public static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Comparé seulement.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( $home, '/' );
		if ( '/' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . trim( $path, '/' );
		return $path;
	}

	/**
	 * @param string $path Chemin demandé (request_path()).
	 * @param string $slug Adresse de connexion.
	 * @return string serve | block | ''
	 */
	public static function match( $path, $slug ) {
		if ( '/' . $slug === $path ) {
			return 'serve';
		}
		// Le cœur peut vivre dans un sous-dossier (/wp/wp-login.php).
		if ( preg_match( '#/(wp-login|wp-register|wp-signup)\.php$#', $path ) ) {
			return 'block';
		}
		return '';
	}

	/**
	 * Les liens vers wp-login.php (formulaire, mot de passe oublié, e-mails,
	 * déconnexion, reconnexion dans wp-admin) pointent vers la nouvelle adresse.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function rewrite( $url ) {
		if ( '' === self::$slug || ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$parts  = explode( '?', $url, 2 );
		$scheme = wp_parse_url( $parts[0], PHP_URL_SCHEME );
		$target = self::url( self::$slug );
		if ( $scheme ) {
			$target = set_url_scheme( $target, $scheme );
		}
		return isset( $parts[1] ) ? $target . '?' . $parts[1] : $target;
	}

	/**
	 * Sert la page de connexion à la nouvelle adresse ; 404 sur l'ancienne
	 * et sur wp-admin pour qui n'est pas connecté.
	 *
	 * @return void
	 */
	public static function route() {
		if ( 'serve' === self::$route ) {
			// Variables globales que wp-login.php attend (il est d'habitude
			// chargé hors de toute fonction).
			global $error, $interim_login, $action, $user_login, $pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
		if ( 'block' === self::$route ) {
			self::not_found();
		}
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && ! wp_doing_cron() && 'admin-post.php' !== $GLOBALS['pagenow'] ) {
			self::not_found();
		}
	}

	/**
	 * @return void
	 */
	private static function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>404</title></head><body><h1>404</h1></body></html>';
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Habillage
	 * ------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	public static function styles() {
		wp_enqueue_style( 'leiho-login', LEIHO_URL . 'assets/admin/login.css', array( 'login' ), LEIHO_VERSION );
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 160 ) : '';
		$logo = '' !== $icon ? $icon : LEIHO_URL . 'assets/img/leiho-mark.svg';
		wp_add_inline_style( 'leiho-login', '.login h1 a{background-image:url("' . esc_url( $logo ) . '")}.login h1::after{content:"' . self::css_string( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '"}' );
	}

	/**
	 * Texte sûr dans une chaîne CSS : tout sauf lettres, chiffres et espaces
	 * passe en échappement hexadécimal (ni guillemet, ni </style> possible).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function css_string( $text ) {
		$out = '';
		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( preg_match( '/^[\p{L}\p{N} ]$/u', $char ) ) {
				$out .= $char;
			} else {
				$code = function_exists( 'mb_ord' ) ? mb_ord( $char, 'UTF-8' ) : ord( $char );
				$out .= '\\' . dechex( (int) $code ) . ' ';
			}
		}
		return $out;
	}

	/**
	 * @return string
	 */
	public static function header_url() {
		$settings = Leiho_Plugin::settings();
		return '' !== $settings['front_url'] ? $settings['front_url'] : home_url( '/' );
	}

	/**
	 * @return string
	 */
	public static function header_text() {
		return get_bloginfo( 'name' );
	}

	/**
	 * @return void
	 */
	public static function footer() {
		printf(
			'<p class="leiho-login-by"><a href="https://leiho.dev" target="_blank" rel="noopener">%s</a></p>',
			esc_html__( 'Edited with Leiho', 'leiho' )
		);
	}
}
