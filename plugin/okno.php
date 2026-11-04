<?php
/**
 * Plugin Name:       Okno
 * Plugin URI:        https://github.com/pixelersagency/okno
 * Description:       Visual editor and content API for headless front ends (Astro, Next.js, …), right inside wp-admin. Define your content types in Okno, or keep your existing ACF fields.
 * Version:           1.0.0-beta.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pixelers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       okno
 * Domain Path:       /languages
 * Update URI:        https://github.com/pixelersagency/okno
 */

defined( 'ABSPATH' ) || exit;

// Deux copies du plugin installées côte à côte (zip déballé dans un dossier
// versionné à côté du dossier okno/, par exemple) : on sort proprement au lieu
// de fataler sur « Cannot redeclare class Okno_Plugin » à l'activation.
// (Pas de class_exists() ici : PHP lie la classe de ce fichier dès la
// compilation, le test serait vrai dès le premier chargement.)
if ( defined( 'OKNO_VERSION' ) ) {
	return;
}

define( 'OKNO_VERSION', '1.0.0-beta.5' );
define( 'OKNO_FILE', __FILE__ );
define( 'OKNO_DIR', plugin_dir_path( __FILE__ ) );
define( 'OKNO_URL', plugin_dir_url( __FILE__ ) );

require_once OKNO_DIR . 'includes/adapters/interface-okno-adapter.php';
require_once OKNO_DIR . 'includes/adapters/abstract-okno-field-adapter.php';
require_once OKNO_DIR . 'includes/adapters/class-okno-acf-adapter.php';
require_once OKNO_DIR . 'includes/adapters/class-okno-native-adapter.php';
require_once OKNO_DIR . 'includes/collections/class-okno-collections-schema.php';
require_once OKNO_DIR . 'includes/collections/class-okno-collections-values.php';
require_once OKNO_DIR . 'includes/collections/class-okno-collections-store.php';
require_once OKNO_DIR . 'includes/collections/class-okno-collections-rest.php';
require_once OKNO_DIR . 'includes/collections/class-okno-collections.php';
require_once OKNO_DIR . 'includes/deploy/interface-okno-deploy-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-build-hook-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-coolify-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-preflight.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-actions-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-github-commit-driver.php';
require_once OKNO_DIR . 'includes/deploy/class-okno-deploy-manager.php';
require_once OKNO_DIR . 'includes/class-okno-secrets.php';
require_once OKNO_DIR . 'includes/class-okno-url-mapper.php';
require_once OKNO_DIR . 'includes/class-okno-activity.php';
require_once OKNO_DIR . 'includes/class-okno-schema.php';
require_once OKNO_DIR . 'includes/class-okno-rest.php';
require_once OKNO_DIR . 'includes/class-okno-content.php';
require_once OKNO_DIR . 'includes/class-okno-cache.php';
require_once OKNO_DIR . 'includes/class-okno-types.php';
require_once OKNO_DIR . 'includes/class-okno-headless.php';
require_once OKNO_DIR . 'includes/class-okno-updater.php';
require_once OKNO_DIR . 'includes/admin/class-okno-icons.php';
require_once OKNO_DIR . 'includes/admin/class-okno-dashboard.php';
require_once OKNO_DIR . 'includes/admin/class-okno-admin.php';

/**
 * Bootstrap.
 */
final class Okno_Plugin {

	const OPTION_SETTINGS = 'okno_settings';
	const OPTION_SECRETS  = 'okno_secrets';
	const OPTION_USAGE    = 'okno_usage';

	/** @var Okno_Plugin|null */
	private static $instance = null;

	/** @var Okno_Adapter_Interface[] */
	private $adapters = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		// Okno Collections d'abord : les champs natifs priment sur un homonyme ACF.
		$this->adapters['collections'] = new Okno_Native_Adapter();
		$this->adapters['acf']         = new Okno_ACF_Adapter();

		/**
		 * Permet d'enregistrer des adapters supplémentaires (JetEngine, Metabox, …).
		 *
		 * @param Okno_Adapter_Interface[] $adapters Adapters indexés par id.
		 */
		$this->adapters = apply_filters( 'okno_adapters', $this->adapters );

		// Traductions livrées avec le plugin (languages/) : indispensable hors
		// wordpress.org, où WordPress ne les télécharge pas tout seul.
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'okno', false, dirname( plugin_basename( OKNO_FILE ) ) . '/languages' );
			}
		);
		add_action( 'rest_api_init', array( new Okno_Rest( $this ), 'register_routes' ) );
		add_action( 'rest_api_init', array( new Okno_Content( $this ), 'register_routes' ) );
		add_action( Okno_Deploy_Manager::CRON_HOOK, array( 'Okno_Deploy_Manager', 'cleanup_stale' ) );
		add_action( Okno_Deploy_Manager::AUTO_HOOK, array( 'Okno_Deploy_Manager', 'run_auto' ) );
		add_action( 'okno_content_changed', array( 'Okno_Deploy_Manager', 'queue_auto' ) );
		add_filter( 'heartbeat_received', array( 'Okno_Deploy_Manager', 'heartbeat' ) );
		Okno_Content::init();
		Okno_Cache::init();
		Okno_Collections::init();
		Okno_Headless::init();
		Okno_Activity::init();
		Okno_Updater::init();

		if ( is_admin() ) {
			new Okno_Admin( $this );
		}
	}

	/** @return Okno_Adapter_Interface[] Adapters disponibles (dépendance présente). */
	public function adapters() {
		return array_filter(
			$this->adapters,
			static function ( $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Réglages avec valeurs par défaut.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'front_url'           => '',
			'preview_url'         => '',
			'post_types'          => array( 'page' ),
			'url_patterns'        => array(), // post_type => pattern, ex. 'page' => '/{slug}'.
			'path_meta_key'       => '', // Meta (ex. champ ACF) contenant le chemin de la page sur le front.
			'deploy_driver'       => '',
			'build_hook_url'      => '',
			'build_hook_eta'      => 3, // minutes.
			'coolify_url'         => '',
			'gh_repo'             => '',
			'gh_workflow'         => 'deploy.yml',
			'gh_branch'           => 'main',
			'min_deploy_interval' => 60, // secondes.
			'auto_deploy'         => false, // Publier tout seul après chaque modification.
			'auto_deploy_delay'   => 60, // secondes de calme avant de publier.
			'content_access'      => 'public', // 'public' ou 'token'.
			'content_cache'       => true, // Cache objet de l'API de contenu (Okno_Cache).
			// Durcissement headless : activé d'office à la première installation
			// (Okno_Headless::seed_defaults), éteint sur les sites déjà équipés.
			'headless_noindex'    => false,
			'headless_redirect'   => false,
			'headless_lockdown'   => false,
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );

		/**
		 * Réglages effectifs (Okno Collections y ajoute ses types et chemins).
		 *
		 * @param array $settings Réglages enregistrés, valeurs par défaut comprises.
		 */
		return apply_filters( 'okno_settings', wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults ) );
	}

	/**
	 * Titre lisible d'un contenu.
	 *
	 * get_the_title() passe par wptexturize() et rend des entités (&rsquo;,
	 * &amp;…). L'éditeur écrit ces titres en textContent : sans décodage,
	 * « l’eau » s'affiche « l&rsquo;eau ».
	 *
	 * @param int|WP_Post $post Post.
	 * @return string
	 */
	public static function title( $post ) {
		return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Incrémente un compteur d'usage (objectiver le "0 ticket").
	 *
	 * @param string $event 'save' ou 'publish'.
	 */
	public static function bump_usage( $event ) {
		$usage = get_option( self::OPTION_USAGE, array() );
		if ( ! is_array( $usage ) ) {
			$usage = array();
		}
		$week = gmdate( 'o-\WW' );
		if ( ! isset( $usage[ $week ] ) ) {
			$usage[ $week ] = array(
				'save'    => 0,
				'publish' => 0,
			);
		}
		if ( isset( $usage[ $week ][ $event ] ) ) {
			$usage[ $week ][ $event ]++;
		}
		// On garde 26 semaines glissantes.
		if ( count( $usage ) > 26 ) {
			ksort( $usage );
			$usage = array_slice( $usage, -26, null, true );
		}
		update_option( self::OPTION_USAGE, $usage, false );
	}
}

add_action( 'plugins_loaded', array( 'Okno_Plugin', 'instance' ) );

register_activation_hook( __FILE__, array( 'Okno_Headless', 'seed_defaults' ) );
register_activation_hook( __FILE__, array( 'Okno_Deploy_Manager', 'schedule_cleanup' ) );
register_activation_hook( __FILE__, array( 'Okno_Activity', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Okno_Deploy_Manager', 'unschedule_cleanup' ) );
