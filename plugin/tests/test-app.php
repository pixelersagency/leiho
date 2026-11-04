<?php
/**
 * Application plein écran : php plugin/tests/test-app.php
 *
 * Page unique et anciennes pages, vues et droits, arrivée des éditeurs dans
 * Okno après connexion, routes REST ajoutées pour l'application (statut,
 * corbeille, état de la publication) et leurs fonctions pures.
 */
define( 'ABSPATH', true );
define( 'OKNO_DIR', dirname( __DIR__ ) . '/' );
define( 'OKNO_URL', 'https://wp.test/wp-content/plugins/okno/' );
define( 'OKNO_FILE', OKNO_DIR . 'okno.php' );
define( 'OKNO_VERSION', 'test' );

$GLOBALS['caps']     = array( 'edit_posts' => true );
$GLOBALS['settings'] = array( 'editor_landing' => true, 'post_types' => array( 'page' ), 'url_patterns' => array() );
$GLOBALS['hooks']    = array();
$GLOBALS['routes']   = array();
$GLOBALS['pages']    = array();

class WP_Error {
	public function __construct( $c = '', $m = '', $d = null ) {}
}
class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const DELETABLE = 'DELETE';
	const EDITABLE  = 'POST, PUT, PATCH';
}
final class Okno_Plugin {
	public static function settings() { return $GLOBALS['settings']; }
}
class Fake_User {
	public $ID = 7;
	private $caps;
	public function __construct( $caps ) { $this->caps = $caps; }
	public function has_cap( $cap ) { return ! empty( $this->caps[ $cap ] ); }
}

function __( $s, $d = '' ) { return $s; }
function _x( $s, $c, $d = '' ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function add_action( $hook, $cb = null ) { $GLOBALS['hooks'][] = $hook; }
function add_filter( $hook, $cb = null ) { $GLOBALS['hooks'][] = $hook; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function admin_url( $path = '' ) { return 'https://wp.test/wp-admin/' . ltrim( $path, '/' ); }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function is_admin() { return true; }
function add_query_arg( $args, $url ) {
	$parts = explode( '?', $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
	}
	return $parts[0] . '?' . http_build_query( array_merge( $query, $args ) );
}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][] = $route; }
function add_menu_page( $title, $menu, $cap, $slug ) {
	$GLOBALS['pages'][] = array( 'parent' => null, 'slug' => $slug, 'cap' => $cap );
	return 'toplevel_page_' . $slug;
}
function add_submenu_page( $parent, $title, $menu, $cap, $slug ) {
	$GLOBALS['pages'][] = array( 'parent' => $parent, 'slug' => $slug, 'cap' => $cap );
	return 'admin_page_' . $slug;
}
function plugin_basename( $f ) { return 'okno/okno.php'; }
function get_post_type( $id ) { return isset( $GLOBALS['post_types'][ $id ] ) ? $GLOBALS['post_types'][ $id ] : false; }
final class Okno_Collections {
	public static function post_types() { return array( 'temoignage', 'service' ); }
}
$GLOBALS['post_types'] = array( 12 => 'temoignage', 30 => 'service', 40 => 'page' );

require OKNO_DIR . 'includes/admin/class-okno-icons.php';
require OKNO_DIR . 'includes/admin/class-okno-app.php';
require OKNO_DIR . 'includes/admin/class-okno-admin.php';
require OKNO_DIR . 'includes/class-okno-rest.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/* --- URLs et vues --- */

check( 'accueil : admin.php?page=okno', 'https://wp.test/wp-admin/admin.php?page=okno' === Okno_App::url() );
check( 'vue : &view=', 'https://wp.test/wp-admin/admin.php?page=okno&view=collections' === Okno_App::url( 'collections' ) );
check( 'vue + paramètres', 'https://wp.test/wp-admin/admin.php?page=okno&view=collections&type=service&id=12' === Okno_App::url( 'collections', array( 'type' => 'service', 'id' => 12 ) ) );

check( 'vue inconnue : accueil', 'home' === Okno_App::current_view( 'nope' ) );
check( 'collections : edit_posts suffit (contenus de tous les types)', 'collections' === Okno_App::current_view( 'collections' ) );
check( 'ancienne vue Contenus : plus une vue', 'home' === Okno_App::current_view( 'content' ) );
check( 'ancienne vue Formulaire : plus une vue', 'home' === Okno_App::current_view( 'form' ) );
check( 'réglages sans manage_options : accueil', 'home' === Okno_App::current_view( 'settings' ) );
check( 'médias sans upload_files : accueil', 'home' === Okno_App::current_view( 'media' ) );
$GLOBALS['caps'] = array( 'edit_posts' => true, 'manage_options' => true, 'upload_files' => true );
check( 'collections avec manage_options', 'collections' === Okno_App::current_view( 'collections' ) );
check( 'réglages avec manage_options', 'settings' === Okno_App::current_view( 'settings' ) );
check( 'médias avec upload_files', 'media' === Okno_App::current_view( 'media' ) );

$nav = array_keys(
	array_filter(
		Okno_App::views(),
		static function ( $v ) {
			return $v['nav'];
		}
	)
);
check( 'navigation : six vues, plus d\'onglet Contenus', array( 'home', 'editor', 'collections', 'media', 'deploys', 'settings' ) === $nav, $nav );

/* --- Contenus et Formulaire fondus dans Collections --- */

$base = 'https://wp.test/wp-admin/admin.php?page=okno&view=collections';
check( 'view=content → collections', $base === Okno_App::merged_url( 'content', array( 'page' => 'okno', 'view' => 'content' ) ) );
check( 'view=content, filtres gardés', $base . '&type=service&status=draft&s=plombier' === Okno_App::merged_url( 'content', array( 'type' => 'service', 'status' => 'draft', 's' => 'plombier' ) ), Okno_App::merged_url( 'content', array( 'type' => 'service', 'status' => 'draft', 's' => 'plombier' ) ) );
check( 'view=content&new=1 → création', $base . '&new=1' === Okno_App::merged_url( 'content', array( 'new' => '1' ) ) );
check( 'view=form&post=12 → type et entrée', $base . '&type=temoignage&id=12' === Okno_App::merged_url( 'form', array( 'post' => '12' ), 'temoignage' ) );
check( 'view=form, champ du journal gardé', $base . '&type=temoignage&id=12&field=sections.0.title' === Okno_App::merged_url( 'form', array( 'post' => '12', 'field' => 'sections.0.title' ), 'temoignage' ), Okno_App::merged_url( 'form', array( 'post' => '12', 'field' => 'sections.0.title' ), 'temoignage' ) );
check( 'view=form sans contenu → collections', $base === Okno_App::merged_url( 'form', array() ) );
check( 'paramètres bizarres ignorés', $base === Okno_App::merged_url( 'content', array( 'type' => array( 'x' ), 'post' => 'abc' ) ) );
check( 'autre vue : rien', null === Okno_App::merged_url( 'media', array() ) );

/* --- Où éditer un contenu --- */

$GLOBALS['settings']['url_patterns'] = array( 'service' => '/services/{slug}' );
check( 'collection sans URL : son entrée dans Collections', $base . '&type=temoignage&id=12' === Okno_Admin::edit_url( 12 ), Okno_Admin::edit_url( 12 ) );
check( 'collection avec URL : éditeur visuel', 'https://wp.test/wp-admin/admin.php?page=okno&view=editor&post=30' === Okno_Admin::edit_url( 30 ) );
check( 'page : éditeur visuel', 'https://wp.test/wp-admin/admin.php?page=okno&view=editor&post=40' === Okno_Admin::edit_url( 40 ) );
$GLOBALS['settings']['url_patterns'] = array();

/* --- Anciennes pages --- */

check( 'okno-settings → vue réglages, drapeau gardé', 'https://wp.test/wp-admin/admin.php?page=okno&view=settings&okno_saved=1' === Okno_App::legacy_url( 'okno-settings', array( 'page' => 'okno-settings', 'okno_saved' => '1' ) ) );
check( 'okno-editor → éditeur, post et champ gardés', 'https://wp.test/wp-admin/admin.php?page=okno&view=editor&post=12&field=sections.0.title' === Okno_App::legacy_url( 'okno-editor', array( 'page' => 'okno-editor', 'post' => '12', 'field' => 'sections.0.title' ) ) );
check( 'okno-deploys → publications', false !== strpos( Okno_App::legacy_url( 'okno-deploys', array() ), 'view=deploys' ) );
check( 'okno-start → démarrer', false !== strpos( Okno_App::legacy_url( 'okno-start', array() ), 'view=start' ) );
check( 'page inconnue : rien', null === Okno_App::legacy_url( 'autre', array() ) );
check( 'paramètre au nom bizarre ignoré', false === strpos( Okno_App::legacy_url( 'okno-start', array( 'a[b]' => 'x', '<s>' => 'y' ) ), 'x' ) );

/* --- Menu WordPress : une entrée, anciennes pages cachées --- */

$admin = new Okno_Admin( new Okno_Plugin() );
$GLOBALS['hooks'] = array();
$admin->register_menu();
$top    = array_values(
	array_filter(
		$GLOBALS['pages'],
		static function ( $p ) {
			return null === $p['parent'];
		}
	)
);
$hidden = array_values(
	array_filter(
		$GLOBALS['pages'],
		static function ( $p ) {
			return 'options.php' === $p['parent'];
		}
	)
);
check( 'une seule page de menu : okno', 1 === count( $top ) && 'okno' === $top[0]['slug'] && 'edit_posts' === $top[0]['cap'], $GLOBALS['pages'] );
check( 'aucun sous-menu visible', count( $GLOBALS['pages'] ) === 1 + count( $hidden ) );
check( 'anciennes pages enregistrées hors menu', array( 'okno-editor', 'okno-start', 'okno-settings', 'okno-deploys' ) === array_column( $hidden, 'slug' ), $hidden );
check( 'chargement de la page : interface retirée', in_array( 'load-toplevel_page_okno', $GLOBALS['hooks'], true ) );
check( 'chargement des anciennes pages : redirection', in_array( 'load-admin_page_okno-settings', $GLOBALS['hooks'], true ) );

/* --- Arrivée dans Okno après connexion --- */

$editor = new Fake_User( array( 'edit_posts' => true ) );
$owner  = new Fake_User( array( 'edit_posts' => true, 'manage_options' => true ) );
$reader = new Fake_User( array( 'read' => true ) );
$home   = 'https://wp.test/wp-admin/';

check( 'éditeur : Okno', 'https://wp.test/wp-admin/admin.php?page=okno' === Okno_App::login_redirect( $home, '', $editor ) );
check( 'éditeur, redirect_to = wp-admin : Okno', 'https://wp.test/wp-admin/admin.php?page=okno' === Okno_App::login_redirect( $home, 'https://wp.test/wp-admin/', $editor ) );
check( 'éditeur, destination précise : respectée', 'https://wp.test/wp-admin/post.php?post=3' === Okno_App::login_redirect( 'https://wp.test/wp-admin/post.php?post=3', 'https://wp.test/wp-admin/post.php?post=3', $editor ) );
check( 'administrateur : tableau de bord', $home === Okno_App::login_redirect( $home, '', $owner ) );
check( 'abonné : inchangé', $home === Okno_App::login_redirect( $home, '', $reader ) );
check( 'échec de connexion (WP_Error) : inchangé', $home === Okno_App::login_redirect( $home, '', new WP_Error() ) );
$GLOBALS['settings']['editor_landing'] = false;
check( 'réglage décoché : inchangé', $home === Okno_App::login_redirect( $home, '', $editor ) );
$GLOBALS['settings']['editor_landing'] = true;

/* --- REST : routes de l'application --- */

$rest = new Okno_Rest( new Okno_Plugin() );
$rest->register_routes();
$routes = $GLOBALS['routes'];
check( 'route statut', in_array( '/posts/(?P<id>\d+)/status', $routes, true ) );
check( 'route restauration', in_array( '/posts/(?P<id>\d+)/restore', $routes, true ) );
check( 'route état de la publication', in_array( '/deploy/state', $routes, true ) );
check(
	'/deploy/state avant /deploy/{id} (sinon « state » serait pris pour un id)',
	array_search( '/deploy/state', $routes, true ) < array_search( '/deploy/(?P<deploy_id>[A-Za-z0-9_\-.]+)', $routes, true )
);

check( 'statuts par défaut : sans la corbeille', ! in_array( 'trash', Okno_Rest::query_statuses( '' ), true ) && in_array( 'draft', Okno_Rest::query_statuses( '' ), true ) );
check( 'statut demandé seul', array( 'draft' ) === Okno_Rest::query_statuses( 'draft' ) );
check( 'corbeille sur demande', array( 'trash' ) === Okno_Rest::query_statuses( 'trash' ) );
check( 'statut inconnu : par défaut', Okno_Rest::query_statuses( '' ) === Okno_Rest::query_statuses( 'auto-draft' ) );

check( 'publier avec le droit', 'publish' === Okno_Rest::allowed_status( 'publish', true ) );
check( 'publier sans le droit : relecture', 'pending' === Okno_Rest::allowed_status( 'publish', false ) );
check( 'privé sans le droit : refusé', null === Okno_Rest::allowed_status( 'private', false ) );
check( 'brouillon toujours permis', 'draft' === Okno_Rest::allowed_status( 'draft', false ) );
check( 'statut inconnu : refusé', null === Okno_Rest::allowed_status( 'trash', true ) );

echo empty( $GLOBALS['failed'] ) ? "\nTout est vert.\n" : "\nÉCHECS.\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
