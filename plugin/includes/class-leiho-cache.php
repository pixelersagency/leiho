<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cache de l'API de contenu, branché sur ce que le site a déjà.
 *
 * - Cache objet de WordPress (groupe « leiho ») : avec un cache persistant
 *   (Redis, Memcached, LiteSpeed, W3 Total Cache…), l'export, les contenus,
 *   les résolutions de chemin et les types y vivent d'une requête à l'autre.
 *   Sans cache persistant, l'export reste dans son option non autochargée et
 *   rien d'autre n'est écrit en base.
 * - Invalidation en O(1) : chaque clé est préfixée par une « génération »
 *   qu'on remplace à chaque modification au lieu d'effacer les clés une à une.
 * - En-têtes HTTP : ETag, Cache-Control, 304 sur If-None-Match.
 * - Purge des caches de page et CDN connus (LiteSpeed, WP Rocket…) quand le
 *   contenu change, une seule fois par requête, en fin de requête.
 */
class Leiho_Cache {

	const GROUP = 'leiho';

	/** Clé de la génération courante dans le groupe. */
	const GENERATION_KEY = 'generation';

	/** Durée de vie des entrées : borne la péremption si une invalidation échappe aux hooks (acf-json déployé). */
	const TTL = DAY_IN_SECONDS;

	/** Étiquette LiteSpeed posée sur les réponses de l'API, purgée à chaque changement. */
	const TAG = 'leiho';

	const CLEAR_ACTION = 'leiho_clear_content_cache';

	/** @var string|null Génération lue ou fixée dans cette requête. */
	private static $generation = null;

	/** Purge déjà programmée dans cette requête. */
	private static $purge_queued = false;

	/** Purge déjà faite dans cette requête. */
	private static $purged = false;

	/** @var int[] Contenus servis modifiés dans cette requête (purge par URL). */
	private static $changed = array();

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'leiho_content_changed', array( __CLASS__, 'note_change' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( __CLASS__, 'handle_clear' ) );
	}

	/* ---------------------------------------------------------------------
	 * État
	 * ------------------------------------------------------------------- */

	/**
	 * Cache activé (réglage, filtrable).
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = Leiho_Plugin::settings();
		/**
		 * Active ou coupe le cache de l'API de contenu.
		 *
		 * @param bool $enabled Valeur du réglage.
		 */
		return (bool) apply_filters( 'leiho_content_cache', ! empty( $settings['content_cache'] ) );
	}

	/**
	 * Un cache objet persistant est en place (drop-in object-cache.php).
	 *
	 * @return bool
	 */
	public static function persistent() {
		return function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache();
	}

	/**
	 * Nom lisible du cache persistant, '' s'il n'y en a pas.
	 *
	 * @return string
	 */
	public static function backend() {
		if ( ! self::persistent() ) {
			return '';
		}
		$name = '';
		$file = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
		if ( $file && is_readable( $file ) && function_exists( 'get_file_data' ) ) {
			$data = get_file_data( $file, array( 'name' => 'Plugin Name' ) );
			$name = trim( (string) $data['name'] );
		}
		$class = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ? get_class( $GLOBALS['wp_object_cache'] ) : '';
		return self::backend_label( $name, $class );
	}

	/**
	 * Libellé à partir de l'en-tête du drop-in et de la classe du cache.
	 *
	 * @param string $name  « Plugin Name » du drop-in.
	 * @param string $class Classe de $wp_object_cache.
	 * @return string
	 */
	public static function backend_label( $name, $class ) {
		$haystack = strtolower( $name . ' ' . $class );
		$known    = array(
			'redis'     => 'Redis',
			'memcache'  => 'Memcached',
			'litespeed' => 'LiteSpeed',
			'w3 total'  => 'W3 Total Cache',
			'w3tc'      => 'W3 Total Cache',
			'apcu'      => 'APCu',
			'sqlite'    => 'SQLite',
		);
		foreach ( $known as $needle => $label ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $label;
			}
		}
		return '' !== $name ? $name : ( '' !== $class ? $class : 'object-cache.php' );
	}

	/* ---------------------------------------------------------------------
	 * Lecture et écriture
	 * ------------------------------------------------------------------- */

	/**
	 * Génération courante. Absente (cache vidé, évincée) : on en crée une,
	 * ce qui revient à repartir d'un cache vide.
	 *
	 * @return string
	 */
	public static function generation() {
		if ( null === self::$generation ) {
			$generation = wp_cache_get( self::GENERATION_KEY, self::GROUP );
			if ( ! is_string( $generation ) || '' === $generation ) {
				$generation = self::new_generation();
				// add() et non set() : deux requêtes simultanées gardent la même.
				if ( ! wp_cache_add( self::GENERATION_KEY, $generation, self::GROUP ) ) {
					$stored     = wp_cache_get( self::GENERATION_KEY, self::GROUP );
					$generation = is_string( $stored ) && '' !== $stored ? $stored : $generation;
				}
			}
			self::$generation = $generation;
		}
		return self::$generation;
	}

	/**
	 * Invalide tout le cache objet d'Leiho d'un coup : les anciennes clés ne
	 * sont plus jamais lues et expirent d'elles-mêmes.
	 *
	 * @return void
	 */
	public static function bump() {
		self::$generation = self::new_generation();
		wp_cache_set( self::GENERATION_KEY, self::$generation, self::GROUP );
	}

	private static function new_generation() {
		return str_replace( '.', '', uniqid( '', true ) );
	}

	/**
	 * Clé complète : la version du plugin et du format en font partie, une
	 * mise à jour repart donc d'un cache vide.
	 *
	 * @param string $name Nom court (export, item:12…).
	 * @return string
	 */
	public static function key( $name ) {
		return Leiho_Content::FORMAT . ':' . LEIHO_VERSION . ':' . self::generation() . ':' . $name;
	}

	/**
	 * Valeur en cache, sinon calculée puis gardée. Sans cache persistant, on
	 * calcule à chaque fois : un cache le temps d'une requête ne sert à rien
	 * ici, et on n'écrit rien en base pour ces entrées.
	 *
	 * @param string   $name     Nom court.
	 * @param callable $callback Calcul.
	 * @return mixed
	 */
	public static function remember( $name, $callback ) {
		if ( ! self::enabled() || ! self::persistent() ) {
			return call_user_func( $callback );
		}
		$key   = self::key( $name );
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found ) {
			return $value;
		}
		$value = call_user_func( $callback );
		wp_cache_set( $key, $value, self::GROUP, self::TTL );
		return $value;
	}

	/**
	 * Export en cache : cache objet s'il est persistant, sinon l'option.
	 *
	 * @return array|null
	 */
	public static function get_export() {
		if ( ! self::enabled() ) {
			return null;
		}
		$cache = self::persistent()
			? wp_cache_get( self::key( 'export' ), self::GROUP )
			: get_option( Leiho_Content::OPTION_CACHE );
		return is_array( $cache ) ? $cache : null;
	}

	/**
	 * @param array $cache Export calculé.
	 * @return void
	 */
	public static function set_export( $cache ) {
		if ( ! self::enabled() ) {
			return;
		}
		if ( self::persistent() ) {
			wp_cache_set( self::key( 'export' ), $cache, self::GROUP, self::TTL );
		} else {
			update_option( Leiho_Content::OPTION_CACHE, $cache, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------- */

	/**
	 * ETag fort d'un contenu.
	 *
	 * @param string $source Contenu (JSON, texte).
	 * @return string Entre guillemets.
	 */
	public static function etag( $source ) {
		return '"' . substr( md5( (string) $source ), 0, 20 ) . '"';
	}

	/**
	 * La réponse ne doit être gardée par aucun cache partagé : accès par
	 * jeton (un proxy la resservirait à qui n'en a pas), utilisateur connecté
	 * (brouillons), ou demandé par l'appelant.
	 *
	 * @param bool $private Forcer.
	 * @return bool
	 */
	public static function is_private( $private = false ) {
		if ( $private ) {
			return true;
		}
		$settings = Leiho_Plugin::settings();
		return 'token' === $settings['content_access'] || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() );
	}

	/**
	 * En-têtes de cache d'une réponse de l'API. Toujours revalidée : le
	 * contenu change sans préavis, l'ETag rend la revalidation presque gratuite.
	 * Prévient aussi LiteSpeed (étiquette pour la purge, ou pas de cache).
	 *
	 * @param string $etag    ETag entre guillemets.
	 * @param bool   $private Réponse privée.
	 * @return array<string,string>
	 */
	public static function headers( $etag, $private = false ) {
		$private = self::is_private( $private );
		if ( $private ) {
			// LiteSpeed met en cache l'API REST par défaut et ignore nos en-têtes.
			do_action( 'litespeed_control_set_nocache', 'leiho: private content API response' );
		} else {
			do_action( 'litespeed_tag_add', self::TAG );
		}

		/**
		 * Cache-Control des réponses de l'API de contenu.
		 *
		 * @param string $value   Valeur.
		 * @param bool   $private Réponse privée (jeton, utilisateur connecté).
		 */
		$control = (string) apply_filters(
			'leiho_content_cache_control',
			$private ? 'private, max-age=0, must-revalidate' : 'public, max-age=0, must-revalidate',
			$private
		);

		$headers = array(
			'ETag'          => $etag,
			'Cache-Control' => $control,
		);
		if ( $private ) {
			$headers['Vary'] = 'Origin, Authorization, X-Leiho-Token, Cookie';
		}
		return $headers;
	}

	/**
	 * Le client a déjà cette version (If-None-Match).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @param string          $etag    ETag entre guillemets.
	 * @return bool
	 */
	public static function not_modified( $request, $etag ) {
		$given = trim( (string) $request->get_header( 'if_none_match' ) );
		if ( '' === $given ) {
			return false;
		}
		if ( '*' === $given ) {
			return true;
		}
		foreach ( explode( ',', $given ) as $candidate ) {
			$candidate = trim( $candidate );
			// Comparaison faible (RFC 9110) : un proxy peut avoir ajouté W/.
			if ( 0 === strpos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			if ( $candidate === $etag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Réponse JSON avec ETag, Cache-Control et 304.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @param mixed           $data    Données.
	 * @param bool            $private Réponse privée.
	 * @return WP_REST_Response
	 */
	public static function response( $request, $data, $private = false ) {
		$etag    = self::etag( wp_json_encode( $data ) );
		$headers = self::headers( $etag, $private );
		if ( self::not_modified( $request, $etag ) ) {
			return new WP_REST_Response( null, 304, $headers );
		}
		return new WP_REST_Response( $data, 200, $headers );
	}

	/* ---------------------------------------------------------------------
	 * Purge des caches de page et CDN
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $post_id Contenu servi modifié.
	 * @return void
	 */
	public static function note_change( $post_id ) {
		self::$changed[ (int) $post_id ] = (int) $post_id;
	}

	/**
	 * Programme la purge en fin de requête : un enregistrement ACF écrit des
	 * dizaines de métas, on ne purge qu'une fois, contenu complet.
	 *
	 * @return void
	 */
	public static function queue_purge() {
		if ( self::$purge_queued ) {
			return;
		}
		self::$purge_queued = true;
		// Après la dernière invalidation de fin de requête (priorité 10).
		add_action( 'shutdown', array( __CLASS__, 'purge' ), 20 );
	}

	/**
	 * URLs de l'API à purger.
	 *
	 * @return string[]
	 */
	public static function urls() {
		$urls = array(
			rest_url( Leiho_Rest::NS . '/export' ),
			rest_url( Leiho_Rest::NS . '/types' ),
			rest_url( Leiho_Rest::NS . '/content' ),
		);
		foreach ( self::$changed as $post_id ) {
			$urls[] = rest_url( Leiho_Rest::NS . '/content/' . $post_id );
		}
		/**
		 * URLs purgées dans les caches de page quand le contenu change.
		 *
		 * @param string[] $urls    URLs de l'API de contenu.
		 * @param int[]    $changed Contenus servis modifiés dans la requête.
		 */
		return array_values( array_unique( (array) apply_filters( 'leiho_purge_urls', $urls, array_values( self::$changed ) ) ) );
	}

	/**
	 * Purge les caches tiers actifs : par URL ou étiquette quand leur API le
	 * permet, sinon tout. Une seule fois par requête.
	 *
	 * @return string[] Caches purgés.
	 */
	public static function purge() {
		if ( self::$purged ) {
			return array();
		}
		self::$purged = true;

		$urls   = self::urls();
		$purged = array();

		/**
		 * Purge des caches de page et CDN connus quand le contenu change.
		 *
		 * @param bool $purge Purger.
		 */
		if ( apply_filters( 'leiho_purge_third_party_caches', true ) ) {
			$purged = self::purge_third_party( $urls );
		}

		/**
		 * Le contenu servi a changé et les caches connus ont été purgés :
		 * purger ici un CDN ou un cache maison.
		 *
		 * @param string[] $urls   URLs de l'API de contenu.
		 * @param string[] $purged Caches déjà purgés par Leiho.
		 */
		do_action( 'leiho_purge_caches', $urls, $purged );

		return $purged;
	}

	/**
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	private static function purge_third_party( $urls ) {
		$purged = array();

		// LiteSpeed Cache (et l'hébergement Hostinger, QUIC.cloud) : par étiquette
		// et par URL (réponses mises en cache avant l'étiquetage).
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge' ) ) {
			do_action( 'litespeed_purge', self::TAG );
			foreach ( $urls as $url ) {
				do_action( 'litespeed_purge_url', $url );
			}
			$purged[] = 'litespeed';
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
			$purged[] = 'wp-rocket';
		} elseif ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'wp-rocket';
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_url' ) ) {
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
			$purged[] = 'w3-total-cache';
		} elseif ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3-total-cache';
		}

		// WP Super Cache.
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			foreach ( $urls as $url ) {
				wpsc_delete_url_cache( $url );
			}
			$purged[] = 'wp-super-cache';
		} elseif ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'wp-super-cache';
		}

		// SiteGround Speed Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'siteground';
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache();
			$purged[] = 'wp-fastest-cache';
		}

		// Actions publiques : Cloudflare, Breeze (Cloudways), Nginx Helper.
		$actions = array(
			'cloudflare_purge_everything' => 'cloudflare',
			'breeze_clear_all_cache'      => 'breeze',
			'rt_nginx_helper_purge_all'   => 'nginx-helper',
		);
		foreach ( $actions as $action => $name ) {
			if ( has_action( $action ) ) {
				do_action( $action );
				$purged[] = $name;
			}
		}

		return $purged;
	}

	/* ---------------------------------------------------------------------
	 * Administration
	 * ------------------------------------------------------------------- */

	/**
	 * Bouton « Vider le cache de contenu ».
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leiho' ) );
		}
		check_admin_referer( self::CLEAR_ACTION );

		Leiho_Content::invalidate();

		wp_safe_redirect( Leiho_App::url( 'settings', array( 'leiho_cache_cleared' => '1' ) ) );
		exit;
	}

	/**
	 * URL du bouton, avec nonce.
	 *
	 * @return string
	 */
	public static function clear_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CLEAR_ACTION ), self::CLEAR_ACTION );
	}
}
