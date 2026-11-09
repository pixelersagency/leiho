<?php
/**
 * Tests des capacités d'administration, sans WordPress ni réseau :
 * php plugin/tests/test-abilities-admin.php
 *
 * Droits (manage_options, filtrable), secrets jamais rendus (ni par le
 * diagnostic, ni par les réglages, ni par set-secret), dry_run sans
 * écriture, mode de publication inconnu refusé, mise à jour partielle,
 * formulaire des réglages inchangé après la factorisation.
 */
define( 'ABSPATH', true );
define( 'LEIHO_VERSION', '1.0.0-test' );

/* ---------------------------------------------------------------------
 * Stubs WordPress
 * ------------------------------------------------------------------- */

$GLOBALS['caps']      = array( 'manage_options' => true );
$GLOBALS['options']   = array();
$GLOBALS['filters']   = array();
$GLOBALS['abilities'] = array();
$GLOBALS['ai_log']    = array();
$GLOBALS['http']      = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $d ) { $this->data = $d; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
class Leiho_Redirect extends Exception {}

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return new class() { public $display_name = 'Admin'; public function exists() { return true; } }; }
function current_time( $t, $gmt = false ) { return '2026-11-04 10:00:00'; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function esc_url_raw( $v ) { $v = trim( (string) $v ); return preg_match( '#^https?://#', $v ) ? $v : ''; }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function trailingslashit( $v ) { return untrailingslashit( $v ) . '/'; }
function get_post_types( $args = array(), $out = 'names' ) { return array( 'page' => 'page', 'post' => 'post', 'service' => 'service' ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function admin_url( $p = '' ) { return 'https://cms.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://cms.test/wp-json/' . $p; }
function get_bloginfo( $k ) { return '6.9'; }
function wp_count_posts( $t ) { return (object) array( 'publish' => 3 ); }
function wp_salt( $s = '' ) { return 'test-salt'; }
function wp_generate_password( $l = 12, $s = true ) { return str_repeat( 'g', $l ); }
function wp_unslash( $v ) { return $v; }
function check_admin_referer( $a ) { return true; }
function wp_die( $m = '' ) { throw new Exception( 'die: ' . $m ); }
function wp_safe_redirect( $url ) { throw new Leiho_Redirect( $url ); }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http'][] = $url;
	return array( 'headers' => array( 'content-security-policy' => "frame-ancestors 'self'" ), 'code' => 200 );
}
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }

/* ---------------------------------------------------------------------
 * Stubs Leiho
 * ------------------------------------------------------------------- */

class Leiho_Plugin {
	public static function remote( $method, $url, $args = array() ) { $GLOBALS['remote_args'] = $args; return wp_remote_get( $url, $args ); }
	const OPTION_SETTINGS = 'leiho_settings';
	const OPTION_SECRETS  = 'leiho_secrets';
	public static function settings() {
		$defaults = array(
			'front_url'           => '',
			'preview_url'         => '',
			'post_types'          => array( 'page' ),
			'url_patterns'        => array(),
			'path_meta_key'       => '',
			'deploy_driver'       => '',
			'build_hook_url'      => '',
			'build_hook_eta'      => 3,
			'coolify_url'         => '',
			'gh_repo'             => '',
			'gh_workflow'         => 'deploy.yml',
			'gh_branch'           => 'main',
			'min_deploy_interval' => 60,
			'auto_deploy'         => false,
			'auto_deploy_delay'   => 60,
			'content_access'      => 'public',
			'content_cache'       => true,
			'editor_landing'      => true,
			'headless_noindex'    => false,
			'headless_redirect'   => false,
			'headless_lockdown'   => false,
			'headless_comments'   => false,
			'headless_menus'      => false,
			'security_file_edit'  => false,
			'security_login'      => false,
		);
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}
}
class Leiho_Dashboard {
	public static $connection = null;
	public static function drivers() { return array_fill_keys( array( 'live', 'build_hook', 'github', 'github_commit', 'coolify' ), array() ); }
	public static function connection() { return self::$connection; }
}
class Leiho_Deploy_Manager {
	public static $history = array();
	public static function history() { return self::$history; }
	public static function auto_enabled() { return false; }
	public static function auto_delay() { return 60; }
	public static function pending_since() { return 0; }
}
class Leiho_Content {
	const SECRET_TOKEN = 'content_token';
	public static function post_types() { return Leiho_Plugin::settings()['post_types']; }
}
class Leiho_Cache {
	public static function get_export() { return null; }
	public static function backend() { return ''; }
}
class Leiho_AI { public static function status() { return array( 'client' => true, 'text' => true, 'image' => false ); } }
class Leiho_Legacy {
	const FLAG = 'leiho_migrated_from_okno';
	public static function okno_active() { return false; }
	public static function table( $s ) { return 'wp_leiho_' . $s; }
}
class Leiho_Headless {
	const FLAGS = array( 'headless_noindex', 'headless_redirect', 'headless_lockdown', 'security_file_edit', 'security_login' );
	const KEYS  = array( 'headless_noindex', 'headless_redirect', 'headless_comments', 'headless_menus', 'headless_lockdown', 'security_file_edit', 'security_login' );
}
class Leiho_App { public static function url( $v = '', $args = array() ) { return 'https://cms.test/wp-admin/admin.php?page=leiho&view=' . $v; } }
/** Contrôle GitHub factice : enregistre ses appels, lit le PAT comme le vrai. */
class Leiho_Github_Preflight {
	public static $calls  = array();
	public static $stored = array();
	public static function check( $repo, $branch, $workflow = '' ) {
		self::$calls[] = array( $repo, $branch, $workflow );
		if ( null === Leiho_Secrets::get( 'gh_pat' ) ) {
			return array( array( 'level' => 'error', 'message' => 'No GitHub PAT set.' ) );
		}
		return array(
			array( 'level' => 'ok', 'message' => 'Repository “' . $repo . '” is accessible.' ),
			array( 'level' => 'warning', 'message' => 'The PAT expires on 9 Nov 2026 (in 5 days).' ),
		);
	}
	public static function run_and_store( $repo, $branch, $workflow = '' ) { self::$stored[] = array( $repo, $branch, $workflow ); }
}

$GLOBALS['wpdb'] = new class() {
	public $prefix = 'wp_';
	public function insert( $table, $row ) { $GLOBALS['ai_log'][] = $row; return 1; }
};

require __DIR__ . '/../includes/class-leiho-secrets.php';
require __DIR__ . '/../includes/class-leiho-activity.php';
require __DIR__ . '/../includes/class-leiho-rest.php';
require __DIR__ . '/../includes/admin/class-leiho-admin.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-abilities.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-admin-abilities.php';

$GLOBALS['options'][ Leiho_Activity::OPTION_DB_VERSION ] = Leiho_Activity::DB_VERSION;

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/** Aucune trace d'un secret dans une sortie (même partielle). */
function leaks( $output, array $secrets ) {
	$json = json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	foreach ( $secrets as $secret ) {
		if ( false !== strpos( $json, $secret ) || false !== strpos( $json, substr( $secret, 0, 8 ) ) ) {
			return true;
		}
	}
	return false;
}

$pat     = 'github_pat_SECRET123456789abcdef';
$coolify = 'coolify-SECRET-token-987654';
$hook    = 'https://api.vercel.com/v1/integrations/deploy/prj_HOOKSECRET42/abcdef';

/* ---------------------------------------------------------------------
 * Enregistrement et droits
 * ------------------------------------------------------------------- */

function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; return true; }
Leiho_AI_Admin_Abilities::register();
check( 'capacités : quatre capacités d’administration', array( 'leiho/get-diagnostics', 'leiho/update-settings', 'leiho/set-secret', 'leiho/test-connection' ) === array_keys( $GLOBALS['abilities'] ) );
$shape = true;
foreach ( $GLOBALS['abilities'] as $name => $args ) {
	if ( 'leiho' !== $args['category'] || true !== $args['meta']['mcp']['public'] || array( 'Leiho_AI_Admin_Abilities', 'can_manage' ) !== $args['permission_callback'] || ! is_callable( $args['execute_callback'] ) ) {
		$shape = false;
	}
}
check( 'capacités : catégorie leiho, publiques pour MCP, réservées aux administrateurs', $shape );
check( 'capacités : aucune ne publie ni ne déploie', ! preg_grep( '/deploy|publish/', array_keys( $GLOBALS['abilities'] ) ) );
$a = $GLOBALS['abilities'];
check( 'annotations : diagnostic et test en lecture seule', true === $a['leiho/get-diagnostics']['meta']['annotations']['readonly'] && true === $a['leiho/test-connection']['meta']['annotations']['readonly'] );
check( 'annotations : secret destructif, non idempotent', true === $a['leiho/set-secret']['meta']['annotations']['destructive'] && false === $a['leiho/set-secret']['meta']['annotations']['idempotent'] && false === $a['leiho/update-settings']['meta']['annotations']['readonly'] );

check( 'droits : administrateur', true === Leiho_AI_Admin_Abilities::can_manage() );
$GLOBALS['caps'] = array( 'edit_posts' => true );
check( 'droits : un éditeur est refusé', false === Leiho_AI_Admin_Abilities::can_manage() );
$GLOBALS['filters']['leiho_ai_admin_capability'][] = static function () { return 'edit_posts'; };
check( 'droits : capability filtrable', true === Leiho_AI_Admin_Abilities::can_manage() );
unset( $GLOBALS['filters']['leiho_ai_admin_capability'] );
$GLOBALS['caps'] = array( 'manage_options' => true );

/* ---------------------------------------------------------------------
 * Réglages : validation partagée avec le formulaire
 * ------------------------------------------------------------------- */

update_option(
	'leiho_settings',
	array(
		'front_url'      => 'https://site.test',
		'post_types'     => array( 'page', 'service' ),
		'url_patterns'   => array( 'service' => '/services/{slug}' ),
		'deploy_driver'  => 'build_hook',
		'build_hook_url' => $hook,
		'gh_repo'        => 'acme/site',
		'auto_deploy'    => true,
		'extra_key'      => 'kept',
	)
);
Leiho_Secrets::set( 'gh_pat', $pat );
Leiho_Secrets::set( 'coolify_token', $coolify );
$before_option = get_option( 'leiho_settings' );

$dry = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'url_patterns' => array( 'service' => 'services/{slug}/' ), 'auto_deploy_delay' => 5 ), 'dry_run' => true ) );
check( 'dry_run : rien d’enregistré', $before_option === get_option( 'leiho_settings' ) && false === $dry['saved'] && true === $dry['dry_run'], $dry );
$changes = (array) $dry['changes'];
check( 'dry_run : changements validés comme le formulaire (délai minimal 15 s)', 15 === $changes['auto_deploy_delay']['after'] && isset( $changes['url_patterns'] ), $changes );

// Les adresses où partent contenu et jetons ne se changent pas par un agent.
foreach ( array( 'front_url', 'preview_url', 'build_hook_url', 'coolify_url', 'gh_repo' ) as $key ) {
	$bad = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( $key => 'https://attacker.test/x' ) ) );
	check( "réglages : $key refusé à un agent", is_wp_error( $bad ) && false !== strpos( $bad->message, $key ), $bad );
}

$bad = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'deploy_driver' => 'ftp' ) ) );
check( 'réglages : mode de publication inconnu refusé', is_wp_error( $bad ) && 'leiho_bad_setting' === $bad->code && 400 === $bad->data['status'] );
$bad = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'gh_pat' => 'x' ) ) );
check( 'réglages : clé inconnue (ou secret) refusée', is_wp_error( $bad ) && false !== strpos( $bad->message, 'gh_pat' ) );
$bad = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'content_access' => 'open' ) ) );
check( 'réglages : accès inconnu refusé', is_wp_error( $bad ) );
check( 'réglages : rien à enregistrer', is_wp_error( Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array() ) ) ) );
check( 'réglages : refus = rien d’enregistré', $before_option === get_option( 'leiho_settings' ) );

$GLOBALS['ai_log'] = array();
$up = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'deploy_driver' => 'github', 'gh_branch' => 'prod', 'post_types' => array( 'page', 'product' ) ) ) );
$saved = get_option( 'leiho_settings' );
check( 'réglages : partiel, les autres clés gardées', 'github' === $saved['deploy_driver'] && 'prod' === $saved['gh_branch'] && 'https://site.test' === $saved['front_url'] && $hook === $saved['build_hook_url'] && true === $saved['auto_deploy'] && 'kept' === $saved['extra_key'] && array( 'service' => '/services/{slug}' ) === $saved['url_patterns'], $saved );
check( 'réglages : clé non modifiable absente de l’option (pas de valeur par défaut écrite)', ! isset( $saved['gh_workflow'] ) );
check( 'réglages : type non public ignoré et signalé', array( 'page' ) === $saved['post_types'] && 1 === count( $up['warnings'] ) && false !== strpos( $up['warnings'][0], 'product' ), $up );
check( 'réglages : contrôle GitHub relancé après changement', isset( $up['checks']->github ) && array( 'acme/site', 'prod', 'deploy.yml' ) === end( Leiho_Github_Preflight::$calls ) );
$row = end( $GLOBALS['ai_log'] );
check( 'réglages : journalisés comme action d’agent, sans valeur', 'settings' === $row['new_value'] && false !== strpos( $row['field_label'], 'deploy_driver' ) && false === strpos( $row['field_label'], 'prod' ) && 'AI agent · Settings changed' === Leiho_Activity::ai_label( 'settings' ), $row );


Leiho_Secrets::set( 'content_token', '' );
$tok = Leiho_AI_Admin_Abilities::update_settings( array( 'settings' => array( 'content_access' => 'token' ) ) );
check( 'réglages : accès restreint → jeton de contenu créé, jamais rendu', 'ok' === Leiho_Secrets::status( 'content_token' ) && ! leaks( $tok, array( Leiho_Secrets::get( 'content_token' ) ) ) );

/* ---------------------------------------------------------------------
 * Formulaire des réglages : même comportement
 * ------------------------------------------------------------------- */

$GLOBALS['options']['leiho_settings'] = array( 'front_url' => 'https://old.test', 'extra_key' => 'kept', 'gh_workflow' => 'old.yml' );
$_POST = array(
	'leiho_settings' => array(
		'front_url'     => 'https://form.test/',
		'post_types'    => array( 'page', 'nope' ),
		'url_patterns'  => array( 'page' => '/{slug}', 'nope' => '/x' ),
		'deploy_driver' => 'nope',
		'gh_repo'       => 'acme/form',
		'auto_deploy'   => '1',
	),
	'leiho_secret'   => array( 'gh_pat' => '  ' . $pat . '2  ' ),
);
$form = ( new ReflectionClass( 'Leiho_Admin' ) )->newInstanceWithoutConstructor();
try {
	$form->handle_save_settings();
	$redirect = '';
} catch ( Leiho_Redirect $e ) {
	$redirect = $e->getMessage();
}
$saved = get_option( 'leiho_settings' );
check( 'formulaire : redirection vers les réglages', false !== strpos( $redirect, 'view=settings' ) );
check( 'formulaire : validation inchangée', 'https://form.test' === $saved['front_url'] && array( 'page' ) === $saved['post_types'] && array( 'page' => '/{slug}' ) === $saved['url_patterns'] && '' === $saved['deploy_driver'] && true === $saved['auto_deploy'], $saved );
check( 'formulaire : champs absents = vides / décochés, comme avant', '' === $saved['gh_workflow'] && false === $saved['content_cache'] && 3 === $saved['build_hook_eta'] && 'public' === $saved['content_access'] && 'kept' === $saved['extra_key'], $saved );
check( 'formulaire : secret remplacé (espaces retirés)', $pat . '2' === Leiho_Secrets::get( 'gh_pat' ) );
$_POST = array();
Leiho_Secrets::set( 'gh_pat', $pat );

/* ---------------------------------------------------------------------
 * Secrets
 * ------------------------------------------------------------------- */

$GLOBALS['options']['leiho_settings'] = array( 'deploy_driver' => 'github', 'gh_repo' => 'acme/site', 'front_url' => 'https://site.test' );
$new_pat = 'github_pat_NEWSECRET000111222';
$set     = Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'gh_pat', 'value' => $new_pat ) );
check( 'secret : enregistré, état rendu, contrôle GitHub', $new_pat === Leiho_Secrets::get( 'gh_pat' ) && 'set' === $set['status'] && isset( $set['github'] ), $set );
check( 'secret : jamais rendu', ! leaks( $set, array( $new_pat, $pat ) ) );
$row = end( $GLOBALS['ai_log'] );
check( 'secret : journalisé sans valeur', 'secret' === $row['new_value'] && 'secret:gh_pat' === $row['field_path'] && ! leaks( $row, array( $new_pat ) ) );
$empty = Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'gh_pat', 'value' => '' ) );
check( 'secret : valeur vide sans clear refusée, secret gardé', is_wp_error( $empty ) && $new_pat === Leiho_Secrets::get( 'gh_pat' ) );
$cleared = Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'coolify_token', 'clear' => true ) );
check( 'secret : clear explicite', 'empty' === $cleared['status'] && null === Leiho_Secrets::get( 'coolify_token' ) );
check( 'secret : nom inconnu refusé', is_wp_error( Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'db_password', 'value' => 'x' ) ) ) );
$old_token = Leiho_Secrets::get( 'content_token' );
check( 'secret : jeton de contenu non saisissable', is_wp_error( Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'content_token', 'value' => 'mine' ) ) ) && $old_token === Leiho_Secrets::get( 'content_token' ) );
$regen = Leiho_AI_Admin_Abilities::set_secret( array( 'name' => 'content_token', 'regenerate' => true ) );
check( 'secret : jeton de contenu renouvelé, jamais rendu', 'set' === $regen['status'] && ! leaks( $regen, array( Leiho_Secrets::get( 'content_token' ) ) ) && ! empty( $regen['note'] ) );
Leiho_Secrets::set( 'coolify_token', $coolify );

/* ---------------------------------------------------------------------
 * Diagnostic et test
 * ------------------------------------------------------------------- */

$GLOBALS['options']['leiho_settings'] = array( 'deploy_driver' => 'github', 'gh_repo' => 'acme/site', 'front_url' => 'https://site.test', 'build_hook_url' => $hook, 'coolify_url' => 'https://coolify.test/api/v1/deploy?uuid=COOLSECRET77' );
Leiho_Deploy_Manager::$history = array(
	'dep_2' => array( 'id' => 'dep_2', 'driver' => 'github', 'user_name' => 'Marie', 'started' => 1793000000, 'status' => 'error', 'message' => 'Workflow failed', 'meta' => array( 'token' => $pat ) ),
);
$GLOBALS['http'] = array();
$diag = Leiho_AI_Admin_Abilities::get_diagnostics( array() );
check( 'diagnostic : aucun secret, aucune URL à jeton', ! leaks( $diag, array( $new_pat, $coolify, 'prj_HOOKSECRET42', 'COOLSECRET77', Leiho_Secrets::get( 'content_token' ) ) ), $diag );
check( 'diagnostic : état des secrets', array( 'gh_pat' => 'set', 'coolify_token' => 'set', 'content_token' => 'set' ) === $diag['secrets'], $diag['secrets'] );
check( 'diagnostic : hôtes des hooks', 'api.vercel.com' === $diag['settings']['build_hook_url']['host'] && 'coolify.test' === $diag['settings']['coolify_url']['host'] );
check( 'diagnostic : en-têtes vérifiés depuis le serveur', array( 'https://site.test/' ) === $GLOBALS['http'] && 'error' === $diag['headers']['level'], $diag['headers'] );
check( 'diagnostic : contrôle GitHub', is_array( $diag['github'] ) && 2 === count( $diag['github'] ) );
check( 'diagnostic : publications sans métadonnées du pilote', 'Workflow failed' === $diag['deploys'][0]['message'] && ! isset( $diag['deploys'][0]['meta'] ) );
$codes = array_column( $diag['problems'], 'code' );
check( 'diagnostic : problèmes détectés', in_array( 'headers', $codes, true ) && in_array( 'github', $codes, true ) && in_array( 'deploy_failed', $codes, true ) && in_array( 'bridge_untested', $codes, true ), $codes );
$fixes = true;
foreach ( $diag['problems'] as $p ) {
	if ( '' === $p['fix'] || '' === $p['message'] ) {
		$fixes = false;
	}
}
check( 'diagnostic : chaque problème a sa correction', $fixes );
check( 'diagnostic : frame-ancestors à ajouter, avec l’origine de wp-admin', false !== strpos( $diag['problems'][ array_search( 'headers', $codes, true ) ]['fix'], 'https://cms.test' ) );
$GLOBALS['http'] = array();
$quiet = Leiho_AI_Admin_Abilities::get_diagnostics( array( 'network' => false ) );
check( 'diagnostic : sans réseau sur demande', array() === $GLOBALS['http'] && null === $quiet['github'] );

$GLOBALS['options']['leiho_settings'] = array();
$none = Leiho_AI_Admin_Abilities::get_diagnostics( array( 'network' => false ) );
$codes = array_column( $none['problems'], 'code' );
check( 'diagnostic : URL du site et mode de publication manquants', in_array( 'front_url_missing', $codes, true ) && in_array( 'driver_missing', $codes, true ), $codes );

$GLOBALS['options']['leiho_settings'] = array( 'deploy_driver' => 'github_commit', 'gh_repo' => 'acme/site', 'front_url' => 'https://site.test' );
$test = Leiho_AI_Admin_Abilities::test_connection();
check( 'test : en-têtes, GitHub (sans workflow pour un commit), bridge non testé ici', isset( $test['headers']['level'] ) && array( 'acme/site', 'main', '' ) === end( Leiho_Github_Preflight::$calls ) && false === $test['bridge']['tested_here'] && false !== strpos( $test['bridge']['message'], 'Get started' ) );
check( 'test : aucun secret', ! leaks( $test, array( $new_pat, $coolify ) ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
