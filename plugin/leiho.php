<?php
/**
 * Plugin Name:       Leiho
 * Plugin URI:        https://github.com/pixelersagency/leiho
 * Description:       Visual editor and content API for headless front ends (Astro, Next.js, …), right inside wp-admin. Define your content types in Leiho, or keep your existing ACF fields.
 * Version:           1.0.0-beta.13
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pixelers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leiho
 * Domain Path:       /languages
 * Update URI:        https://github.com/pixelersagency/leiho
 */

defined( 'ABSPATH' ) || exit;

// Deux copies du plugin installées côte à côte (zip déballé dans un dossier
// versionné à côté du dossier leiho/, par exemple) : on sort proprement au lieu
// de fataler sur « Cannot redeclare class Leiho_Plugin » à l'activation.
// (Pas de class_exists() ici : PHP lie la classe de ce fichier dès la
// compilation, le test serait vrai dès le premier chargement.)
if ( defined( 'LEIHO_VERSION' ) ) {
	return;
}

define( 'LEIHO_VERSION', '1.0.0-beta.13' );
define( 'LEIHO_FILE', __FILE__ );
define( 'LEIHO_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEIHO_URL', plugin_dir_url( __FILE__ ) );

require_once LEIHO_DIR . 'includes/adapters/interface-leiho-adapter.php';
require_once LEIHO_DIR . 'includes/adapters/abstract-leiho-field-adapter.php';
require_once LEIHO_DIR . 'includes/adapters/class-leiho-acf-adapter.php';
require_once LEIHO_DIR . 'includes/adapters/class-leiho-native-adapter.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-collections-schema.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-collections-values.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-collections-store.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-collections-rest.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-collections.php';
require_once LEIHO_DIR . 'includes/class-leiho-i18n.php';
require_once LEIHO_DIR . 'includes/collections/class-leiho-acf-import.php';
require_once LEIHO_DIR . 'includes/deploy/interface-leiho-deploy-driver.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-build-hook-driver.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-coolify-driver.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-github-preflight.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-github-actions-driver.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-github-commit-driver.php';
require_once LEIHO_DIR . 'includes/deploy/class-leiho-deploy-manager.php';
require_once LEIHO_DIR . 'includes/class-leiho-secrets.php';
require_once LEIHO_DIR . 'includes/class-leiho-url-mapper.php';
require_once LEIHO_DIR . 'includes/class-leiho-activity.php';
require_once LEIHO_DIR . 'includes/class-leiho-native-fields.php';
require_once LEIHO_DIR . 'includes/class-leiho-schema.php';
require_once LEIHO_DIR . 'includes/class-leiho-rest.php';
require_once LEIHO_DIR . 'includes/class-leiho-content.php';
require_once LEIHO_DIR . 'includes/class-leiho-seo.php';
require_once LEIHO_DIR . 'includes/class-leiho-llms.php';
require_once LEIHO_DIR . 'includes/class-leiho-cache.php';
require_once LEIHO_DIR . 'includes/class-leiho-types.php';
require_once LEIHO_DIR . 'includes/class-leiho-headless.php';
require_once LEIHO_DIR . 'includes/class-leiho-updater.php';
require_once LEIHO_DIR . 'includes/class-leiho-legacy.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-prompt.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-brand.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-rest.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-translate.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-abilities.php';
require_once LEIHO_DIR . 'includes/ai/class-leiho-ai-admin-abilities.php';
require_once LEIHO_DIR . 'includes/admin/class-leiho-icons.php';
require_once LEIHO_DIR . 'includes/admin/class-leiho-dashboard.php';
require_once LEIHO_DIR . 'includes/admin/class-leiho-app.php';
require_once LEIHO_DIR . 'includes/admin/class-leiho-admin.php';

/**
 * Bootstrap.
 */
final class Leiho_Plugin {

	const OPTION_SETTINGS = 'leiho_settings';
	const OPTION_SECRETS  = 'leiho_secrets';
	const OPTION_USAGE    = 'leiho_usage';

	/** @var Leiho_Plugin|null */
	private static $instance = null;

	/** @var Leiho_Adapter_Interface[] */
	private $adapters = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		// Leiho s'appelait Okno : tant qu'Okno est actif, Leiho attend (un
		// avis demande de le désactiver) ; ensuite il reprend ses données.
		if ( Leiho_Legacy::okno_active() ) {
			Leiho_Legacy::wait_for_okno();
			return;
		}
		Leiho_Legacy::maybe_migrate();

		// Leiho Collections d'abord : les champs natifs priment sur un homonyme ACF.
		$this->adapters['collections'] = new Leiho_Native_Adapter();
		$this->adapters['acf']         = new Leiho_ACF_Adapter();

		/**
		 * Permet d'enregistrer des adapters supplémentaires (JetEngine, Metabox, …).
		 *
		 * @param Leiho_Adapter_Interface[] $adapters Adapters indexés par id.
		 */
		$this->adapters = apply_filters( 'leiho_adapters', $this->adapters );

		// Traductions livrées avec le plugin (languages/) : indispensable hors
		// wordpress.org, où WordPress ne les télécharge pas tout seul.
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'leiho', false, dirname( plugin_basename( LEIHO_FILE ) ) . '/languages' );
			}
		);
		add_action( 'rest_api_init', array( new Leiho_Rest( $this ), 'register_routes' ) );
		add_action( 'rest_api_init', array( new Leiho_Content( $this ), 'register_routes' ) );
		add_action( 'rest_api_init', array( 'Leiho_Llms', 'register_routes' ) );
		add_action( Leiho_Deploy_Manager::CRON_HOOK, array( 'Leiho_Deploy_Manager', 'cleanup_stale' ) );
		add_action( Leiho_Deploy_Manager::AUTO_HOOK, array( 'Leiho_Deploy_Manager', 'run_auto' ) );
		add_action( 'leiho_content_changed', array( 'Leiho_Deploy_Manager', 'queue_auto' ) );
		add_filter( 'heartbeat_received', array( 'Leiho_Deploy_Manager', 'heartbeat' ) );
		Leiho_Content::init();
		Leiho_Cache::init();
		Leiho_Collections::init();
		Leiho_ACF_Import::init();
		Leiho_Headless::init();
		Leiho_Activity::init();
		Leiho_Updater::init();
		Leiho_AI::init();
		Leiho_App::init();
		Leiho_Legacy::init();

		if ( is_admin() ) {
			new Leiho_Admin( $this );
		}
	}

	/** @return Leiho_Adapter_Interface[] Adapters disponibles (dépendance présente). */
	public function adapters() {
		return array_filter(
			$this->adapters,
			static function ( $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Requête vers une adresse saisie dans les réglages (site, build hook,
	 * Coolify). Hors développement local, les adresses privées et locales
	 * (réseau interne, 169.254.169.254…) sont refusées et les redirections
	 * non suivies : la réponse ne doit pas servir à sonder le réseau du serveur.
	 *
	 * @param string $method GET ou POST.
	 * @param string $url    Adresse.
	 * @param array  $args   Arguments de wp_remote_request().
	 * @return array|WP_Error
	 */
	public static function remote( $method, $url, $args = array() ) {
		$args['method'] = $method;
		$local          = function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		/**
		 * Autoriser les adresses privées ou locales dans les réglages (front
		 * sur le même réseau qu'un WordPress de production, par exemple).
		 *
		 * @param bool   $allow Défaut : en environnement local ou de développement.
		 * @param string $url   Adresse demandée.
		 */
		if ( apply_filters( 'leiho_allow_private_urls', $local, $url ) ) {
			return wp_remote_request( $url, $args );
		}
		$args['reject_unsafe_urls'] = true;
		return wp_safe_remote_request( $url, $args );
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
			'content_cache'       => true, // Cache objet de l'API de contenu (Leiho_Cache).
			'editor_landing'      => true, // Les éditeurs (sans manage_options) arrivent dans Leiho après connexion.
			// Durcissement headless : activé d'office à la première installation
			// (Leiho_Headless::seed_defaults), éteint sur les sites déjà équipés.
			'headless_noindex'    => false,
			'headless_redirect'   => false,
			'headless_lockdown'   => false,
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );

		/**
		 * Réglages effectifs (Leiho Collections y ajoute ses types et chemins).
		 *
		 * @param array $settings Réglages enregistrés, valeurs par défaut comprises.
		 */
		return apply_filters( 'leiho_settings', wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults ) );
	}

	/**
	 * Activation. La migration depuis Okno passe en premier : sans elle, un
	 * site Okno serait pris pour une installation neuve (réglages par défaut,
	 * durcissement headless activé d'office, historique vide).
	 */
	public static function activate() {
		if ( Leiho_Legacy::okno_active() ) {
			return; // Leiho attend : la migration se fera quand Okno sera désactivé.
		}
		Leiho_Legacy::maybe_migrate();
		Leiho_Headless::seed_defaults();
		Leiho_Deploy_Manager::schedule_cleanup();
		Leiho_Activity::install();
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

add_action( 'plugins_loaded', array( 'Leiho_Plugin', 'instance' ) );

register_activation_hook( __FILE__, array( 'Leiho_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Leiho_Deploy_Manager', 'unschedule_cleanup' ) );
