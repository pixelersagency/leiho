<?php
/**
 * Rôles : profils, nettoyage du formulaire, permissions par rôle.
 * Lancement : php plugin/tests/test-roles.php
 */

define( 'ABSPATH', true );

$GLOBALS['options'] = array();
$GLOBALS['current'] = null;

function __( $s, $d = '' ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function translate_user_role( $n ) { return $n; }
function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'edit_posts' => true, 'manage_options' => true ) ),
			'editor'        => array( 'name' => 'Editor', 'capabilities' => array( 'edit_posts' => true ) ),
			'author'        => array( 'name' => 'Author', 'capabilities' => array( 'edit_posts' => true ) ),
			'subscriber'    => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
		),
	);
}
class WP_User {
	public $roles;
	public $caps;
	public function __construct( $roles, $caps = array() ) { $this->roles = $roles; $this->caps = $caps; }
	public function exists() { return true; }
	public function has_cap( $cap ) { return ! empty( $this->caps[ $cap ] ); }
}
function wp_get_current_user() { return $GLOBALS['current']; }

require __DIR__ . '/../includes/class-leiho-roles.php';

function assert_true( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond && null !== $dump ) { var_export( $dump ); echo "\n"; }
	if ( ! $cond ) { $GLOBALS['failed'] = true; }
}

$admin  = new WP_User( array( 'administrator' ), array( 'manage_options' => true ) );
$editor = new WP_User( array( 'editor' ) );
$author = new WP_User( array( 'author' ) );

/* --- Rôles réglables et défauts --- */
$roles = Leiho_Roles::editable_roles();
assert_true( 'admin et abonné non réglables', array( 'editor', 'author' ) === array_keys( $roles ), $roles );
$config = Leiho_Roles::config();
assert_true( 'éditeur : gestionnaire par défaut', 'manager' === $config['editor']['profile'] );
assert_true( 'auteur : rédacteur par défaut', 'writer' === $config['author']['profile'] );

/* --- Défauts appliqués --- */
assert_true( 'admin : tout', Leiho_Roles::can( 'publish_site', $admin ) && Leiho_Roles::can( 'view_deploys', $admin ) );
assert_true( 'admin : jamais simplifié', false === Leiho_Roles::simple( $admin ) );
assert_true( 'éditeur : met en ligne', Leiho_Roles::can( 'publish_site', $editor ) );
assert_true( 'auteur : ne met pas en ligne', ! Leiho_Roles::can( 'publish_site', $author ) );
assert_true( 'auteur : pas de vue Publication', ! Leiho_Roles::can( 'view_deploys', $author ) );
assert_true( 'auteur : Collections', Leiho_Roles::can( 'view_collections', $author ) );
assert_true( 'auteur : tous les types', Leiho_Roles::can_type( 'page', $author ) );
assert_true( 'auteur : simplifié', Leiho_Roles::simple( $author ) );
assert_true( 'permission inconnue refusée', ! Leiho_Roles::can( 'delete_everything', $author ) );

/* --- Formulaire → option --- */
$saved = Leiho_Roles::sanitize( array(
	'editor' => array( 'views' => array( 'editor', 'collections', 'media', 'deploys', 'settings' ), 'publish_site' => '1', 'use_ai' => '1', 'all_types' => '1', 'simple' => '1' ),
	'author' => array( 'views' => array( 'collections' ), 'types' => array( 'article', 'Bad Type!' ) ),
	'administrator' => array( 'views' => array() ),
) );
assert_true( 'admin ignoré', ! isset( $saved['administrator'] ) );
assert_true( 'vue inconnue filtrée', ! in_array( 'settings', $saved['editor']['views'], true ) );
assert_true( 'profil reconnu', 'manager' === $saved['editor']['profile'], $saved['editor'] );
assert_true( 'personnalisé', 'custom' === $saved['author']['profile'] );
assert_true( 'types nettoyés', array( 'article', 'badtype' ) === $saved['author']['types'], $saved['author']['types'] );
assert_true( 'simplifié décoché', false === $saved['author']['simple'] );

$GLOBALS['options']['leiho_roles'] = $saved;
assert_true( 'auteur : article ouvert', Leiho_Roles::can_type( 'article', $author ) );
assert_true( 'auteur : page fermée', ! Leiho_Roles::can_type( 'page', $author ) );
assert_true( 'auteur : pas d\'éditeur visuel', ! Leiho_Roles::can( 'view_editor', $author ) );
assert_true( 'auteur : pas d\'IA', ! Leiho_Roles::can( 'use_ai', $author ) );
assert_true( 'auteur : plus simplifié', ! Leiho_Roles::simple( $author ) );

/* --- Plusieurs rôles : il suffit qu'un rôle accorde --- */
$both = new WP_User( array( 'author', 'editor' ) );
assert_true( 'cumul : page ouverte par éditeur', Leiho_Roles::can_type( 'page', $both ) );
assert_true( 'cumul : mise en ligne', Leiho_Roles::can( 'publish_site', $both ) );
assert_true( 'cumul : simplifié seulement si tous', ! Leiho_Roles::simple( $both ) );

/* --- Capability directe (extension de rôles) --- */
$granted = new WP_User( array( 'author' ), array( 'leiho_publish_site' => true ) );
assert_true( 'leiho_publish_site accordée', Leiho_Roles::can( 'publish_site', $granted ) );

/* --- Utilisateur courant --- */
$GLOBALS['current'] = $author;
assert_true( 'courant : can_view(home) ouvert', Leiho_Roles::can_view( 'home' ) );
assert_true( 'courant : can_view(editor) fermé', ! Leiho_Roles::can_view( 'editor' ) );

/* --- Profil « aucun accès » --- */
$none = Leiho_Roles::from_profile( 'none' );
assert_true( 'aucun accès : pas de types', array() === $none['types'] && array() === $none['views'] );
assert_true( 'profil inconnu → rédacteur', 'writer' === Leiho_Roles::from_profile( 'nope' )['profile'] );

exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
