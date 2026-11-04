<?php
/**
 * Héritage d'Okno : Leiho s'appelait Okno jusqu'à la 1.0.0-beta.11.
 *
 * Deux rôles :
 *  - migrer une seule fois les données d'un site Okno (options, tables,
 *    métas, secrets, crons) vers leurs noms Leiho ;
 *  - garder compatibles, jusqu'à la 2.0, les fronts et intégrations écrits
 *    pour Okno : route REST okno/v1, en-tête X-Okno-Token, filtres et actions
 *    okno_*, classes Okno_* publiques, commande wp okno, page=okno.
 *    (Côté front, le bridge et le client de contenu lisent aussi les anciens
 *    noms : attributs data-okno-*, OKNO_URL, dossier .okno/.)
 *
 * Leiho ne tourne pas à côté d'Okno : tant qu'Okno est actif, il n'affiche
 * qu'un avis demandant de le désactiver, pour ne pas écrire deux fois dans
 * les mêmes contenus ni migrer des données qu'Okno utilise encore.
 *
 * @package Leiho
 */

defined( 'ABSPATH' ) || exit;

class Leiho_Legacy {

	/** Option posée une fois la migration faite (ou inutile). */
	const FLAG = 'leiho_migrated_from_okno';

	/** Verrou : deux requêtes simultanées ne migrent pas deux fois. */
	const LOCK = 'leiho_migration_lock';

	/** Version à partir de laquelle les noms okno_* sont dépréciés. */
	const SINCE = '1.0.0-beta.12';

	/** Filtres publics renommés (suffixe commun aux deux noms). */
	const FILTERS = array(
		'acf_import_batch',
		'adapters',
		'ai_ability_statuses',
		'ai_capability',
		'ai_rate_limit',
		'ai_status',
		'ai_timeout',
		'collection_post_type_args',
		'collections_capability',
		'collections_save_revision',
		'content_cache',
		'content_cache_control',
		'content_html',
		'content_item',
		'content_post_types',
		'deploy_capability',
		'deploy_driver',
		'purge_third_party_caches',
		'purge_urls',
		'settings',
		'wysiwyg_render',
	);

	/** Actions publiques renommées. */
	const ACTIONS = array(
		'acf_import_rolled_back',
		'acf_import_started',
		'acf_imported',
		'collections_saved',
		'content_changed',
		'purge_caches',
	);

	/** Classes de l'API PHP publique, encore joignables sous leur nom Okno_*. */
	const CLASSES = array( 'Plugin', 'Collections', 'Content', 'ACF_Import' );

	/**
	 * Okno est-il chargé sur ce site ? (Leiho attend alors.)
	 *
	 * @return bool
	 */
	public static function okno_active() {
		return defined( 'OKNO_VERSION' );
	}

	/**
	 * Le site garde-t-il des données Okno pas encore migrées ?
	 *
	 * @return bool
	 */
	public static function has_legacy_data() {
		global $wpdb;
		if ( false !== get_option( 'okno_settings' ) || false !== get_option( 'okno_collections' ) || false !== get_option( 'okno_secrets' ) ) {
			return true;
		}
		$like = $wpdb->esc_like( $wpdb->prefix . 'okno_' ) . '%';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	}

	/**
	 * Branche la compatibilité. Appelé par Leiho_Plugin au démarrage, après la
	 * migration et seulement si Okno n'est pas actif.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_legacy_routes' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_pages' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'migration_notice' ) );
		self::bridge_hooks();
		self::alias_classes();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'Leiho_ACF_Import_Command' ) ) {
			WP_CLI::add_command( 'okno acf-import', 'Leiho_ACF_Import_Command' );
		}
	}

	/**
	 * Leiho en attente : Okno est encore actif. Rien ne démarre, sauf l'avis.
	 */
	public static function wait_for_okno() {
		add_action( 'admin_notices', array( __CLASS__, 'okno_active_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'okno_active_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Migration
	 * ------------------------------------------------------------------- */

	/**
	 * Migre les données d'Okno si besoin. Sûr à appeler à chaque chargement :
	 * une option autochargée suffit à court-circuiter.
	 *
	 * @return array|null Rapport de la migration faite maintenant, sinon null.
	 */
	public static function maybe_migrate() {
		if ( false !== get_option( self::FLAG ) || self::okno_active() ) {
			return null;
		}
		if ( ! self::has_legacy_data() ) {
			add_option( self::FLAG, array( 'from' => 'none', 'at' => time() ) );
			return null;
		}

		if ( ! add_option( self::LOCK, time(), '', 'no' ) ) {
			// Verrou abandonné par une requête morte en route : on le reprend après 10 min.
			if ( time() - (int) get_option( self::LOCK ) < 10 * MINUTE_IN_SECONDS ) {
				return null;
			}
			update_option( self::LOCK, time(), false );
		}

		$report = self::migrate();
		update_option( self::FLAG, $report );
		delete_option( self::LOCK );
		return $report;
	}

	/**
	 * Déplace tout ce qu'Okno a écrit vers les noms Leiho.
	 *
	 * Les options et métas sont renommées en place (UPDATE de la clé) : valeur,
	 * sérialisation et autoload sont conservés tels quels. En cas de doublon,
	 * la donnée Okno l'emporte : c'est la seule qui contient le travail du site.
	 *
	 * @return array Rapport.
	 */
	public static function migrate() {
		global $wpdb;

		$report = array(
			'from'      => 'okno',
			'at'        => time(),
			'options'   => 0,
			'tables'    => array(),
			'conflicts' => array(),
			'meta'      => 0,
			'user_meta' => 0,
			'secrets'   => 0,
		);

		// 1. Options okno_* (les transients commencent par _transient_ : exclus).
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'okno_' ) . '%' ) );
		foreach ( (array) $names as $old ) {
			$new = 'leiho_' . substr( $old, 5 );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $new ) );
			$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) );
			wp_cache_delete( $old, 'options' );
			wp_cache_delete( $new, 'options' );
			$report['options']++;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// 2. Transients : ce ne sont que des caches, ils se reconstruisent.
		foreach ( array( '_transient_okno_', '_transient_timeout_okno_', '_site_transient_okno_', '_site_transient_timeout_okno_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}

		// 3. Tables {prefix}okno_* : historique et collections. ALTER TABLE …
		// RENAME TO garde données et index (MySQL, MariaDB et SQLite).
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'okno_' ) . '%' ) );
		foreach ( (array) $tables as $old ) {
			$new = $wpdb->prefix . 'leiho_' . substr( $old, strlen( $wpdb->prefix . 'okno_' ) );
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $old ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $new ) ) {
				$report['conflicts'][] = $old;
				continue;
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) ) {
				// Une table Leiho vide (créée avant la migration) cède la place ;
				// une table déjà remplie est gardée, et le conflit signalé.
				if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new}`" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié ci-dessus.
					$report['conflicts'][] = $old;
					continue;
				}
				$wpdb->query( "DROP TABLE `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié ci-dessus.
			}
			if ( false !== $wpdb->query( "ALTER TABLE `{$old}` RENAME TO `{$new}`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié ci-dessus.
				$report['tables'][] = $new;
			} else {
				$report['conflicts'][] = $old;
			}
		}

		// 4. Métas de contenu (_okno_rev, _okno_path, _okno_fields…) et
		// d'utilisateur (okno_theme…).
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_okno_' ) . '%' ) );
		foreach ( (array) $keys as $old ) {
			$report['meta'] += (int) $wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_leiho_' . substr( $old, 6 ) ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'okno_' ) . '%' ) );
		foreach ( (array) $keys as $old ) {
			$report['user_meta'] += (int) $wpdb->update( $wpdb->usermeta, array( 'meta_key' => 'leiho_' . substr( $old, 5 ) ), array( 'meta_key' => $old ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		// 5. Secrets : chiffrés avec une clé dérivée du nom, on les rechiffre.
		if ( class_exists( 'Leiho_Secrets' ) ) {
			$report['secrets'] = Leiho_Secrets::upgrade_legacy();
		}

		// 6. Crons d'Okno : plus personne ne les écoute.
		wp_clear_scheduled_hook( 'okno_cleanup_deploys' );
		wp_clear_scheduled_hook( 'okno_auto_deploy' );

		// 7. Caches : tout ce qui précède a été écrit sans passer par l'API
		// des options et des métas.
		wp_cache_flush();

		// 8. Nettoyage des publications : le cron Leiho remplace celui d'Okno.
		if ( class_exists( 'Leiho_Deploy_Manager' ) ) {
			Leiho_Deploy_Manager::schedule_cleanup();
		}

		return $report;
	}

	/* ---------------------------------------------------------------------
	 * Compatibilité
	 * ------------------------------------------------------------------- */

	/**
	 * Route REST okno/v1 : mêmes routes que leiho/v1, pour les fronts
	 * (export du contenu au build, aperçu) écrits pour Okno.
	 */
	public static function register_legacy_routes() {
		$server = rest_get_server();
		$from   = '/' . Leiho_Rest::NS;
		foreach ( $server->get_routes( Leiho_Rest::NS ) as $route => $handlers ) {
			if ( $route === $from ) {
				continue; // Index du namespace : WordPress le crée lui-même.
			}
			$endpoints = array();
			foreach ( $handlers as $handler ) {
				$handler['methods']       = array_keys( (array) $handler['methods'] );
				$handler['show_in_index'] = false;
				$endpoints[]              = $handler;
			}
			register_rest_route( 'okno/v1', substr( $route, strlen( $from ) ), $endpoints );
		}
	}

	/**
	 * Favoris des rédacteurs : admin.php?page=okno… mène à la page Leiho.
	 */
	public static function redirect_legacy_pages() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple redirection, aucune action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page || 0 !== strpos( $page, 'okno' ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'page', 'leiho' . substr( $page, 4 ) ) );
		exit;
	}

	/**
	 * Filtres et actions okno_* : encore appelés, avec un avis de dépréciation
	 * (WP_DEBUG) qui donne le nouveau nom. Ils passent avant les leiho_*.
	 */
	public static function bridge_hooks() {
		foreach ( self::FILTERS as $suffix ) {
			add_filter(
				'leiho_' . $suffix,
				static function ( ...$args ) use ( $suffix ) {
					if ( ! has_filter( 'okno_' . $suffix ) ) {
						return $args[0];
					}
					return apply_filters_deprecated( 'okno_' . $suffix, $args, self::SINCE, 'leiho_' . $suffix );
				},
				0,
				10
			);
		}
		foreach ( self::ACTIONS as $suffix ) {
			add_action(
				'leiho_' . $suffix,
				static function ( ...$args ) use ( $suffix ) {
					if ( has_action( 'okno_' . $suffix ) ) {
						do_action_deprecated( 'okno_' . $suffix, $args, self::SINCE, 'leiho_' . $suffix );
					}
				},
				0,
				10
			);
		}
	}

	/**
	 * Okno_Collections::import() et consorts, pour les scripts existants.
	 * Jamais quand Okno est chargé : ses classes porteraient le même nom.
	 */
	private static function alias_classes() {
		if ( class_exists( 'Okno_Plugin', false ) ) {
			return;
		}
		foreach ( self::CLASSES as $name ) {
			if ( class_exists( 'Leiho_' . $name ) && ! class_exists( 'Okno_' . $name, false ) ) {
				class_alias( 'Leiho_' . $name, 'Okno_' . $name );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Avis
	 * ------------------------------------------------------------------- */

	public static function okno_active_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Leiho replaces Okno.', 'leiho' ),
			esc_html__( 'Deactivate Okno: Leiho will then take over its settings, content types, history and tokens. Your front end keeps working.', 'leiho' ),
			esc_url( self_admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to Plugins', 'leiho' )
		);
	}

	/**
	 * Une fois, après la migration : ce qui a été repris, et ce qui reste à faire.
	 */
	public static function migration_notice() {
		$flag = get_option( self::FLAG );
		if ( ! is_array( $flag ) || 'okno' !== ( $flag['from'] ?? '' ) || ! empty( $flag['seen'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flag['seen'] = time();
		update_option( self::FLAG, $flag );

		$conflicts = (array) ( $flag['conflicts'] ?? array() );
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'Okno is now Leiho.', 'leiho' ),
			esc_html__( 'Settings, content types, history and tokens were carried over. You can delete the Okno plugin: its uninstall leaves Leiho’s data alone.', 'leiho' ),
			$conflicts
				? '<p>' . esc_html(
					sprintf(
						/* translators: %s: comma-separated database table names. */
						__( 'These tables were left as they were, because a Leiho table with the same name already holds data: %s', 'leiho' ),
						implode( ', ', $conflicts )
					)
				) . '</p>'
				: ''
		);
	}
}
