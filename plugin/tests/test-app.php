<?php
/**
 * Application plein écran : php plugin/tests/test-app.php
 *
 * Page unique et anciennes pages, vues et droits, arrivée des éditeurs dans
 * Leiho après connexion, routes REST ajoutées pour l'application (statut,
 * corbeille, état de la publication) et leurs fonctions pures.
 */
define( 'ABSPATH', true );
define( 'LEIHO_DIR', dirname( __DIR__ ) . '/' );
define( 'LEIHO_URL', 'https://wp.test/wp-content/plugins/leiho/' );
define( 'LEIHO_FILE', LEIHO_DIR . 'leiho.php' );
define( 'LEIHO_VERSION', 'test' );

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
final class Leiho_Plugin {
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
$GLOBALS['user_meta'] = array();
$GLOBALS['user_id']   = 1;
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return $GLOBALS['user_id']; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
}
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['user_meta'][ $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['user_meta'][ $id ][ $key ] = $value; return true; }
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
function plugin_basename( $f ) { return 'leiho/leiho.php'; }
function get_post_type( $id ) { return isset( $GLOBALS['post_types'][ $id ] ) ? $GLOBALS['post_types'][ $id ] : false; }
final class Leiho_Collections {
	public static function post_types() { return array( 'temoignage', 'service' ); }
}
$GLOBALS['post_types'] = array( 12 => 'temoignage', 30 => 'service', 40 => 'page' );

require LEIHO_DIR . 'includes/admin/class-leiho-icons.php';
require LEIHO_DIR . 'includes/admin/class-leiho-app.php';
require LEIHO_DIR . 'includes/admin/class-leiho-admin.php';
require LEIHO_DIR . 'includes/class-leiho-rest.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/* --- URLs et vues --- */

check( 'accueil : admin.php?page=leiho', 'https://wp.test/wp-admin/admin.php?page=leiho' === Leiho_App::url() );
check( 'vue : &view=', 'https://wp.test/wp-admin/admin.php?page=leiho&view=collections' === Leiho_App::url( 'collections' ) );
check( 'vue + paramètres', 'https://wp.test/wp-admin/admin.php?page=leiho&view=collections&type=service&id=12' === Leiho_App::url( 'collections', array( 'type' => 'service', 'id' => 12 ) ) );

check( 'vue inconnue : accueil', 'home' === Leiho_App::current_view( 'nope' ) );
check( 'collections : edit_posts suffit (contenus de tous les types)', 'collections' === Leiho_App::current_view( 'collections' ) );
check( 'ancienne vue Contenus : plus une vue', 'home' === Leiho_App::current_view( 'content' ) );
check( 'ancienne vue Formulaire : plus une vue', 'home' === Leiho_App::current_view( 'form' ) );
check( 'réglages sans manage_options : accueil', 'home' === Leiho_App::current_view( 'settings' ) );
check( 'médias sans upload_files : accueil', 'home' === Leiho_App::current_view( 'media' ) );
$GLOBALS['caps'] = array( 'edit_posts' => true, 'manage_options' => true, 'upload_files' => true );
check( 'collections avec manage_options', 'collections' === Leiho_App::current_view( 'collections' ) );
check( 'réglages avec manage_options', 'settings' === Leiho_App::current_view( 'settings' ) );
check( 'médias avec upload_files', 'media' === Leiho_App::current_view( 'media' ) );

$nav = array_keys(
	array_filter(
		Leiho_App::views(),
		static function ( $v ) {
			return $v['nav'];
		}
	)
);
check( 'navigation : sept vues (IA comprise), plus d\'onglet Contenus', array( 'home', 'editor', 'collections', 'media', 'deploys', 'ai', 'settings' ) === $nav, $nav );

/* --- Contenus et Formulaire fondus dans Collections --- */

$base = 'https://wp.test/wp-admin/admin.php?page=leiho&view=collections';
check( 'view=content → collections', $base === Leiho_App::merged_url( 'content', array( 'page' => 'leiho', 'view' => 'content' ) ) );
check( 'view=content, filtres gardés', $base . '&type=service&status=draft&s=plombier' === Leiho_App::merged_url( 'content', array( 'type' => 'service', 'status' => 'draft', 's' => 'plombier' ) ), Leiho_App::merged_url( 'content', array( 'type' => 'service', 'status' => 'draft', 's' => 'plombier' ) ) );
check( 'view=content&new=1 → création', $base . '&new=1' === Leiho_App::merged_url( 'content', array( 'new' => '1' ) ) );
check( 'view=form&post=12 → type et entrée', $base . '&type=temoignage&id=12' === Leiho_App::merged_url( 'form', array( 'post' => '12' ), 'temoignage' ) );
check( 'view=form, champ du journal gardé', $base . '&type=temoignage&id=12&field=sections.0.title' === Leiho_App::merged_url( 'form', array( 'post' => '12', 'field' => 'sections.0.title' ), 'temoignage' ), Leiho_App::merged_url( 'form', array( 'post' => '12', 'field' => 'sections.0.title' ), 'temoignage' ) );
check( 'view=form sans contenu → collections', $base === Leiho_App::merged_url( 'form', array() ) );
check( 'paramètres bizarres ignorés', $base === Leiho_App::merged_url( 'content', array( 'type' => array( 'x' ), 'post' => 'abc' ) ) );
check( 'autre vue : rien', null === Leiho_App::merged_url( 'media', array() ) );

/* --- Où éditer un contenu --- */

$GLOBALS['settings']['url_patterns'] = array( 'service' => '/services/{slug}' );
check( 'collection sans URL : son entrée dans Collections', $base . '&type=temoignage&id=12' === Leiho_Admin::edit_url( 12 ), Leiho_Admin::edit_url( 12 ) );
check( 'collection avec URL : éditeur visuel', 'https://wp.test/wp-admin/admin.php?page=leiho&view=editor&post=30' === Leiho_Admin::edit_url( 30 ) );
check( 'page : éditeur visuel', 'https://wp.test/wp-admin/admin.php?page=leiho&view=editor&post=40' === Leiho_Admin::edit_url( 40 ) );
$GLOBALS['settings']['url_patterns'] = array();

/* --- Anciennes pages --- */

check( 'leiho-settings → vue réglages, drapeau gardé', 'https://wp.test/wp-admin/admin.php?page=leiho&view=settings&leiho_saved=1' === Leiho_App::legacy_url( 'leiho-settings', array( 'page' => 'leiho-settings', 'leiho_saved' => '1' ) ) );
check( 'leiho-editor → éditeur, post et champ gardés', 'https://wp.test/wp-admin/admin.php?page=leiho&view=editor&post=12&field=sections.0.title' === Leiho_App::legacy_url( 'leiho-editor', array( 'page' => 'leiho-editor', 'post' => '12', 'field' => 'sections.0.title' ) ) );
check( 'leiho-deploys → publications', false !== strpos( Leiho_App::legacy_url( 'leiho-deploys', array() ), 'view=deploys' ) );
check( 'leiho-start → démarrer', false !== strpos( Leiho_App::legacy_url( 'leiho-start', array() ), 'view=start' ) );
check( 'page inconnue : rien', null === Leiho_App::legacy_url( 'autre', array() ) );
check( 'paramètre au nom bizarre ignoré', false === strpos( Leiho_App::legacy_url( 'leiho-start', array( 'a[b]' => 'x', '<s>' => 'y' ) ), 'x' ) );

/* --- Menu WordPress : une entrée, anciennes pages cachées --- */

$admin = new Leiho_Admin( new Leiho_Plugin() );
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
check( 'une seule page de menu : leiho', 1 === count( $top ) && 'leiho' === $top[0]['slug'] && 'edit_posts' === $top[0]['cap'], $GLOBALS['pages'] );
check( 'aucun sous-menu visible', count( $GLOBALS['pages'] ) === 1 + count( $hidden ) );
check( 'anciennes pages enregistrées hors menu', array( 'leiho-editor', 'leiho-start', 'leiho-settings', 'leiho-deploys' ) === array_column( $hidden, 'slug' ), $hidden );
check( 'chargement de la page : interface retirée', in_array( 'load-toplevel_page_leiho', $GLOBALS['hooks'], true ) );
check( 'chargement des anciennes pages : redirection', in_array( 'load-admin_page_leiho-settings', $GLOBALS['hooks'], true ) );

/* --- Arrivée dans Leiho après connexion --- */

$editor = new Fake_User( array( 'edit_posts' => true ) );
$owner  = new Fake_User( array( 'edit_posts' => true, 'manage_options' => true ) );
$reader = new Fake_User( array( 'read' => true ) );
$home   = 'https://wp.test/wp-admin/';

check( 'éditeur : Leiho', 'https://wp.test/wp-admin/admin.php?page=leiho' === Leiho_App::login_redirect( $home, '', $editor ) );
check( 'éditeur, redirect_to = wp-admin : Leiho', 'https://wp.test/wp-admin/admin.php?page=leiho' === Leiho_App::login_redirect( $home, 'https://wp.test/wp-admin/', $editor ) );
check( 'éditeur, destination précise : respectée', 'https://wp.test/wp-admin/post.php?post=3' === Leiho_App::login_redirect( 'https://wp.test/wp-admin/post.php?post=3', 'https://wp.test/wp-admin/post.php?post=3', $editor ) );
check( 'administrateur : tableau de bord', $home === Leiho_App::login_redirect( $home, '', $owner ) );
check( 'abonné : inchangé', $home === Leiho_App::login_redirect( $home, '', $reader ) );
check( 'échec de connexion (WP_Error) : inchangé', $home === Leiho_App::login_redirect( $home, '', new WP_Error() ) );
$GLOBALS['settings']['editor_landing'] = false;
check( 'réglage décoché : inchangé', $home === Leiho_App::login_redirect( $home, '', $editor ) );
$GLOBALS['settings']['editor_landing'] = true;

/* --- REST : routes de l'application --- */

$rest = new Leiho_Rest( new Leiho_Plugin() );
$rest->register_routes();
$routes = $GLOBALS['routes'];
check( 'route statut', in_array( '/posts/(?P<id>\d+)/status', $routes, true ) );
check( 'route restauration', in_array( '/posts/(?P<id>\d+)/restore', $routes, true ) );
check( 'route état de la publication', in_array( '/deploy/state', $routes, true ) );
check(
	'/deploy/state avant /deploy/{id} (sinon « state » serait pris pour un id)',
	array_search( '/deploy/state', $routes, true ) < array_search( '/deploy/(?P<deploy_id>[A-Za-z0-9_\-.]+)', $routes, true )
);

check( 'statuts par défaut : sans la corbeille', ! in_array( 'trash', Leiho_Rest::query_statuses( '' ), true ) && in_array( 'draft', Leiho_Rest::query_statuses( '' ), true ) );
check( 'statut demandé seul', array( 'draft' ) === Leiho_Rest::query_statuses( 'draft' ) );
check( 'corbeille sur demande', array( 'trash' ) === Leiho_Rest::query_statuses( 'trash' ) );
check( 'statut inconnu : par défaut', Leiho_Rest::query_statuses( '' ) === Leiho_Rest::query_statuses( 'auto-draft' ) );

check( 'publier avec le droit', 'publish' === Leiho_Rest::allowed_status( 'publish', true ) );
check( 'publier sans le droit : relecture', 'pending' === Leiho_Rest::allowed_status( 'publish', false ) );
check( 'privé sans le droit : refusé', null === Leiho_Rest::allowed_status( 'private', false ) );
check( 'brouillon toujours permis', 'draft' === Leiho_Rest::allowed_status( 'draft', false ) );
check( 'statut inconnu : refusé', null === Leiho_Rest::allowed_status( 'trash', true ) );

check( 'route des préférences (thème)', in_array( '/preferences', $routes, true ) );

/* --- Thème clair / sombre gardé côté serveur --- */

check( 'sans choix : sombre par défaut', 'dark' === Leiho_App::theme() && '' === Leiho_App::stored_theme() );
$_COOKIE['leiho_theme'] = 'light';
check( 'sans méta : cookie', 'light' === Leiho_App::theme() );
$_COOKIE['leiho_theme'] = '<script>';
check( 'cookie invalide ignoré', 'dark' === Leiho_App::theme() );
unset( $_COOKIE['leiho_theme'] );
check( 'thème enregistré', 'light' === Leiho_App::save_theme( 'light' ) && 'light' === Leiho_App::theme() );
$_COOKIE['leiho_theme'] = 'dark';
check( 'la méta passe avant le cookie', 'light' === Leiho_App::theme() );
unset( $_COOKIE['leiho_theme'] );
check( 'thème inconnu refusé', false === Leiho_App::save_theme( 'sepia' ) && 'light' === Leiho_App::theme() );
$GLOBALS['user_id'] = 0;
check( 'déconnecté : rien enregistré', false === Leiho_App::save_theme( 'dark' ) );
$GLOBALS['user_id'] = 1;
ob_start();
Leiho_App::print_theme_head();
$head = ob_get_clean();
check( 'tête : thème posé sur <html> avant le rendu', false !== strpos( $head, "setAttribute( 'data-leiho-theme'" ) && false !== strpos( $head, '"light"' ), $head );

/* --- Champs de date : langue, premier jour, horloge --- */

$fr = Leiho_App::date_config( 'fr_FR', '1', 'H:i' );
check( 'dates fr : balise BCP 47, lundi, 24 h', array( 'locale' => 'fr-FR', 'startOfWeek' => 1, 'hour12' => false ) === $fr, $fr );
$us = Leiho_App::date_config( 'en_US', 0, 'g:i a' );
check( 'dates en_US : dimanche, 12 h', 'en-US' === $us['locale'] && 0 === $us['startOfWeek'] && true === $us['hour12'], $us );
$formal = Leiho_App::date_config( 'de_DE_formal', 6, 'G:i' );
check( 'variante formelle : langue et pays', 'de-DE' === $formal['locale'] && 6 === $formal['startOfWeek'] && false === $formal['hour12'], $formal );
check( 'langue seule (fr)', 'fr' === Leiho_App::date_config( 'fr', 1, 'H:i' )['locale'] );
check( 'jour invalide : lundi', 1 === Leiho_App::date_config( 'fr_FR', 9, 'H:i' )['startOfWeek'] && 1 === Leiho_App::date_config( 'fr_FR', 'x', 'H:i' )['startOfWeek'] );
check( 'lettre échappée ignorée (\\h)', false === Leiho_App::date_config( 'fr_FR', 1, 'G\\hi' )['hour12'] );
check( 'format sans heure : horloge laissée au navigateur', ! array_key_exists( 'hour12', Leiho_App::date_config( 'fr_FR', 1, 'i' ) ) );
check( 'locale bizarre : vide', '' === Leiho_App::date_config( '<script>', 1, 'H:i' )['locale'] );

echo empty( $GLOBALS['failed'] ) ? "\nTout est vert.\n" : "\nÉCHECS.\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
