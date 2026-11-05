<?php
/**
 * Cache de l'API de contenu : php plugin/tests/test-cache.php
 *
 * Cache objet (persistant ou non), génération, export, en-têtes HTTP, 304,
 * invalidation et purge des caches tiers, sans WordPress.
 */
define( 'ABSPATH', true );
define( 'LEIHO_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

/* --- WordPress factice ------------------------------------------------- */

$GLOBALS['persistent'] = true;
$GLOBALS['cache']      = array();
$GLOBALS['options']    = array();
$GLOBALS['settings']   = array( 'content_cache' => true, 'content_access' => 'public', 'post_types' => array( 'page' ), 'front_url' => '' );
$GLOBALS['logged_in']  = false;
$GLOBALS['actions']    = array();
$GLOBALS['hooks']      = array();
$GLOBALS['calls']      = array();
$GLOBALS['get_posts']  = 0;

function wp_using_ext_object_cache() { return $GLOBALS['persistent']; }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$found = isset( $GLOBALS['cache'][ $group ] ) && array_key_exists( $key, $GLOBALS['cache'][ $group ] );
	return $found ? $GLOBALS['cache'][ $group ][ $key ] : false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { $GLOBALS['cache'][ $group ][ $key ] = $value; return true; }
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	if ( isset( $GLOBALS['cache'][ $group ] ) && array_key_exists( $key, $GLOBALS['cache'][ $group ] ) ) { return false; }
	return wp_cache_set( $key, $value, $group );
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $tag ][ $prio ][] = $cb; }
function add_filter( $tag, $cb, $prio = 10 ) { add_action( $tag, $cb, $prio ); }
function has_action( $tag ) { return ! empty( $GLOBALS['hooks'][ $tag ] ); }
function do_action( $tag, ...$args ) {
	$GLOBALS['actions'][] = array( $tag, $args );
	if ( empty( $GLOBALS['hooks'][ $tag ] ) ) { return; }
	ksort( $GLOBALS['hooks'][ $tag ] );
	foreach ( $GLOBALS['hooks'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $cb ) { call_user_func_array( $cb, $args ); }
	}
}
function apply_filters( $tag, $value ) { return $value; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function rest_url( $p ) { return 'https://cms.test/wp-json/' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_posts( $a ) { $GLOBALS['get_posts']++; return array(); }
function get_bloginfo( $k ) { return 'name' === $k ? 'Site' : 'fr-FR'; }
function get_site_icon_url( $s = 512 ) { return ''; }
function home_url() { return 'https://cms.test'; }
function __( $s, $d = '' ) { return $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }

// Caches tiers « installés ».
define( 'LSCWP_V', '7.0' );
function rocket_clean_files( $urls ) { $GLOBALS['calls'][] = array( 'rocket_clean_files', $urls ); }
function rocket_clean_domain() { $GLOBALS['calls'][] = array( 'rocket_clean_domain' ); }
function w3tc_flush_all() { $GLOBALS['calls'][] = array( 'w3tc_flush_all' ); }
add_action( 'rt_nginx_helper_purge_all', function () { $GLOBALS['calls'][] = array( 'nginx' ); } );

class WP_REST_Response {
	public $data; public $status; public $headers;
	public function __construct( $data = null, $status = 200, $headers = array() ) { $this->data = $data; $this->status = $status; $this->headers = $headers; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WP_REST_Request {
	public $h = array();
	public function __construct( $h = array() ) { $this->h = $h; }
	public function get_header( $k ) { return isset( $this->h[ $k ] ) ? $this->h[ $k ] : null; }
}
final class Leiho_Plugin {
	const OPTION_SETTINGS = 'leiho_settings';
	public static function settings() { return $GLOBALS['settings']; }
}
class Leiho_Rest { const NS = 'leiho/v1'; }

require __DIR__ . '/../includes/class-leiho-i18n.php';
require __DIR__ . '/../includes/class-leiho-content.php';
require __DIR__ . '/../includes/class-leiho-seo.php';
require __DIR__ . '/../includes/class-leiho-cache.php';

function assert_true( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond && null !== $dump ) { var_export( $dump ); echo "\n"; }
	if ( ! $cond ) { $GLOBALS['failed'] = true; }
}

function run_shutdown() {
	$hooks = isset( $GLOBALS['hooks']['shutdown'] ) ? $GLOBALS['hooks']['shutdown'] : array();
	unset( $GLOBALS['hooks']['shutdown'] );
	ksort( $hooks );
	foreach ( $hooks as $callbacks ) {
		foreach ( $callbacks as $cb ) { call_user_func( $cb ); }
	}
}

function reset_request() {
	foreach ( array( 'generation' => null, 'purge_queued' => false, 'purged' => false, 'changed' => array() ) as $prop => $value ) {
		$r = new ReflectionProperty( 'Leiho_Cache', $prop );
		$r->setAccessible( true );
		$r->setValue( null, $value );
	}
	$r = new ReflectionProperty( 'Leiho_Content', 'invalidated' );
	$r->setAccessible( true );
	$r->setValue( null, false );
	$GLOBALS['actions'] = array();
	$GLOBALS['calls']   = array();
}

/* --- Génération ---------------------------------------------------------- */

$g1 = Leiho_Cache::generation();
assert_true( 'génération créée et gardée', '' !== $g1 && $g1 === $GLOBALS['cache']['leiho']['generation'] );
reset_request();
assert_true( 'génération relue dans une autre requête', $g1 === Leiho_Cache::generation() );
$k1 = Leiho_Cache::key( 'export' );
Leiho_Cache::bump();
assert_true( 'bump change la génération et les clés', $g1 !== Leiho_Cache::generation() && $k1 !== Leiho_Cache::key( 'export' ) );
assert_true( 'clé versionnée (format, plugin)', 0 === strpos( Leiho_Cache::key( 'x' ), Leiho_Content::FORMAT . ':test:' ) );

/* --- remember ------------------------------------------------------------ */

$n    = 0;
$calc = function () use ( &$n ) { $n++; return array( 'n' => $n ); };
Leiho_Cache::remember( 'item:1', $calc );
$v = Leiho_Cache::remember( 'item:1', $calc );
assert_true( 'persistant : calculé une fois', 1 === $n && 1 === $v['n'] );
Leiho_Cache::bump();
Leiho_Cache::remember( 'item:1', $calc );
assert_true( 'après bump : recalculé', 2 === $n );
Leiho_Cache::remember( 'zero', function () { return 0; } );
assert_true( 'valeur 0 en cache (résolution de chemin introuvable)', 0 === Leiho_Cache::remember( 'zero', function () { return 99; } ) );

$GLOBALS['persistent'] = false;
$before                = count( $GLOBALS['cache']['leiho'] );
Leiho_Cache::remember( 'item:2', $calc );
Leiho_Cache::remember( 'item:2', $calc );
assert_true( 'sans cache persistant : aucun stockage, calcul à chaque fois', 4 === $n && count( $GLOBALS['cache']['leiho'] ) === $before );

$GLOBALS['persistent']                = true;
$GLOBALS['settings']['content_cache'] = false;
Leiho_Cache::remember( 'item:3', $calc );
Leiho_Cache::remember( 'item:3', $calc );
assert_true( 'cache désactivé : calcul à chaque fois', 6 === $n );
$GLOBALS['settings']['content_cache'] = true;

/* --- Export --------------------------------------------------------------- */

$GLOBALS['options'] = array();
$content            = new Leiho_Content( new Leiho_Plugin() );
$r1                 = $content->get_export( new WP_REST_Request() );
$r2                 = $content->get_export( new WP_REST_Request() );
assert_true( 'export persistant : construit une fois', 1 === $GLOBALS['get_posts'], $GLOBALS['get_posts'] );
assert_true( 'export persistant : pas d’option écrite', ! isset( $GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] ) );
assert_true( 'export : ETag et Cache-Control public', 200 === $r1->status && '"' === $r1->headers['ETag'][0] && 'public, max-age=0, must-revalidate' === $r1->headers['Cache-Control'], $r1->headers );
assert_true( 'export : LiteSpeed étiqueté', in_array( array( 'litespeed_tag_add', array( 'leiho' ) ), $GLOBALS['actions'], true ) );
$r3 = $content->get_export( new WP_REST_Request( array( 'if_none_match' => $r1->headers['ETag'] ) ) );
assert_true( 'export : 304 sur If-None-Match', 304 === $r3->status && null === $r3->data );

$GLOBALS['persistent'] = false;
$GLOBALS['get_posts']  = 0;
$content->get_export( new WP_REST_Request() );
$content->get_export( new WP_REST_Request() );
assert_true( 'export sans cache persistant : option non autochargée', 1 === $GLOBALS['get_posts'] && isset( $GLOBALS['options'][ Leiho_Content::OPTION_CACHE ]['etag'] ) );

$GLOBALS['settings']['content_cache'] = false;
$GLOBALS['options']                   = array();
$GLOBALS['get_posts']                 = 0;
$content->get_export( new WP_REST_Request() );
$content->get_export( new WP_REST_Request() );
assert_true( 'export, cache désactivé : recalculé, rien écrit', 2 === $GLOBALS['get_posts'] && empty( $GLOBALS['options'] ) );
$GLOBALS['settings']['content_cache'] = true;
$GLOBALS['persistent']                = true;

/* --- En-têtes ------------------------------------------------------------- */

$req = new WP_REST_Request( array( 'if_none_match' => 'W/"abc", "def"' ) );
assert_true( 'If-None-Match : liste et préfixe faible', Leiho_Cache::not_modified( $req, '"abc"' ) && Leiho_Cache::not_modified( $req, '"def"' ) && ! Leiho_Cache::not_modified( $req, '"xyz"' ) );
assert_true( 'If-None-Match : *', Leiho_Cache::not_modified( new WP_REST_Request( array( 'if_none_match' => '*' ) ), '"x"' ) );
assert_true( 'If-None-Match absent', ! Leiho_Cache::not_modified( new WP_REST_Request(), '"x"' ) );

$res = Leiho_Cache::response( new WP_REST_Request(), array( 'a' => 1 ) );
$etg = $res->headers['ETag'];
assert_true( 'réponse : ETag stable', $etg === Leiho_Cache::response( new WP_REST_Request(), array( 'a' => 1 ) )->headers['ETag'] && $etg !== Leiho_Cache::response( new WP_REST_Request(), array( 'a' => 2 ) )->headers['ETag'] );
assert_true( 'réponse : 304', 304 === Leiho_Cache::response( new WP_REST_Request( array( 'if_none_match' => $etg ) ), array( 'a' => 1 ) )->status );

$GLOBALS['actions']                    = array();
$GLOBALS['settings']['content_access'] = 'token';
$h                                     = Leiho_Cache::headers( '"x"' );
assert_true( 'jeton : privé', 0 === strpos( $h['Cache-Control'], 'private' ) && isset( $h['Vary'] ), $h );
assert_true( 'jeton : LiteSpeed ne met pas en cache', 'litespeed_control_set_nocache' === $GLOBALS['actions'][0][0] );
$GLOBALS['settings']['content_access'] = 'public';
$GLOBALS['logged_in']                  = true;
assert_true( 'utilisateur connecté : privé', 0 === strpos( Leiho_Cache::headers( '"x"' )['Cache-Control'], 'private' ) );
$GLOBALS['logged_in'] = false;
assert_true( 'brouillon : privé', 0 === strpos( Leiho_Cache::headers( '"x"', true )['Cache-Control'], 'private' ) );
assert_true( 'public', 0 === strpos( Leiho_Cache::headers( '"x"' )['Cache-Control'], 'public' ) );

/* --- Invalidation et purge ------------------------------------------------ */

reset_request();
Leiho_Cache::init();
$GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] = array( 'etag' => 'x' );
$gen = Leiho_Cache::generation();
// Un enregistrement ACF : un save_post et des dizaines de métas.
Leiho_Content::invalidate();
do_action( 'leiho_content_changed', 12 );
Leiho_Content::invalidate();
Leiho_Content::invalidate();
$mid = Leiho_Cache::generation();
assert_true( 'invalidation : génération changée, option supprimée', $gen !== $mid && ! isset( $GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] ) );
assert_true( 'invalidation : aucune purge avant la fin de requête', empty( $GLOBALS['calls'] ) );

// Un build pendant la requête remet l'export en cache…
$GLOBALS['persistent'] = false;
$content->get_export( new WP_REST_Request() );
$GLOBALS['persistent'] = true;
run_shutdown();
assert_true( 'fin de requête : export à moitié enregistré oublié', ! isset( $GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] ) && $mid !== Leiho_Cache::generation() );

$names = array_map( function ( $c ) { return $c[0]; }, $GLOBALS['calls'] );
assert_true( 'purge : une seule fois par cache', array( 'rocket_clean_files', 'w3tc_flush_all', 'nginx' ) === $names, $names );
assert_true( 'purge WP Rocket par URL, contenu modifié inclus', in_array( 'https://cms.test/wp-json/leiho/v1/content/12', $GLOBALS['calls'][0][1], true ) && in_array( 'https://cms.test/wp-json/leiho/v1/export', $GLOBALS['calls'][0][1], true ) );
$lsc = array_values( array_filter( $GLOBALS['actions'], function ( $a ) { return 0 === strpos( $a[0], 'litespeed_purge' ); } ) );
assert_true( 'purge LiteSpeed par étiquette puis URL', 'litespeed_purge' === $lsc[0][0] && array( 'leiho' ) === $lsc[0][1] && 'litespeed_purge_url' === $lsc[1][0] );
$hook = array_values( array_filter( $GLOBALS['actions'], function ( $a ) { return 'leiho_purge_caches' === $a[0]; } ) );
assert_true( 'action leiho_purge_caches une fois, avec les caches purgés', 1 === count( $hook ) && in_array( 'litespeed', $hook[0][1][1], true ) && in_array( 'nginx-helper', $hook[0][1][1], true ), $hook );
assert_true( 'seconde purge ignorée', array() === Leiho_Cache::purge() );

/* --- Libellé du cache ----------------------------------------------------- */

assert_true( 'libellé Redis', 'Redis' === Leiho_Cache::backend_label( 'Redis Object Cache Drop-In', 'WP_Object_Cache' ) );
assert_true( 'libellé Memcached', 'Memcached' === Leiho_Cache::backend_label( '', 'WP_Object_Cache_Memcached' ) );
assert_true( 'libellé inconnu : nom du drop-in', 'Mon cache' === Leiho_Cache::backend_label( 'Mon cache', '' ) );
$GLOBALS['persistent'] = false;
assert_true( 'pas de cache persistant : libellé vide', '' === Leiho_Cache::backend() );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
