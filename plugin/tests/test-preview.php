<?php
/**
 * Aperçu d'un contenu qui n'est pas encore sur le site : page modèle
 * (Leiho_Url_Mapper::stand_in). php plugin/tests/test-preview.php
 */
define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_name;
	public function __construct( array $a ) {
		foreach ( $a as $k => $v ) {
			$this->$k = $v;
		}
	}
}

$GLOBALS['options']  = array( 'page_on_front' => 5 );
$GLOBALS['meta']     = array();
$GLOBALS['queries']  = array();
$GLOBALS['found']    = array();
$GLOBALS['filters']  = array();
$GLOBALS['types']    = array(
	'post' => (object) array( 'hierarchical' => false ),
	'page' => (object) array( 'hierarchical' => true ),
);

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function get_post_meta( $id, $key = '', $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function get_post_type_object( $t ) { return isset( $GLOBALS['types'][ $t ] ) ? $GLOBALS['types'][ $t ] : null; }
function get_posts( $args ) {
	$GLOBALS['queries'][] = $args;
	return $GLOBALS['found'];
}
function apply_filters( $tag, $value ) {
	$args = func_get_args();
	return isset( $GLOBALS['filters'][ $tag ] ) ? call_user_func_array( $GLOBALS['filters'][ $tag ], array_slice( $args, 1 ) ) : $value;
}
function untrailingslashit( $v ) { return rtrim( (string) $v, '/' ); }
class Leiho_Plugin {
	public static function settings() {
		return array( 'front_url' => 'https://site.test', 'preview_url' => '', 'path_meta_key' => '', 'url_patterns' => array( 'post' => '/actualites/{slug}' ) );
	}
}
require __DIR__ . '/../includes/class-leiho-url-mapper.php';

$failures = 0;
function check( $label, $ok, $debug = null ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$failures++;
		if ( null !== $debug ) {
			echo '     ' . var_export( $debug, true ) . "\n";
		}
	}
}

$mapper    = new Leiho_Url_Mapper();
$published = new WP_Post( array( 'ID' => 40, 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'fete' ) );
$draft     = new WP_Post( array( 'ID' => 41, 'post_type' => 'post', 'post_status' => 'draft', 'post_name' => '' ) );

check( 'contenu publié : pas de page modèle, aucune requête', null === $mapper->stand_in( $published ) && array() === $GLOBALS['queries'] );

$GLOBALS['found'] = array( $published );
$stand_in         = $mapper->stand_in( $draft );
$query            = $GLOBALS['queries'][0];
check( 'brouillon : le dernier contenu publié du même type', $stand_in === $published );
check( 'requête : même type, publié, un seul, plus récent', 'post' === $query['post_type'] && 'publish' === $query['post_status'] && 1 === $query['posts_per_page'] && 'DESC' === $query['order'], $query );
check( 'requête : ni le brouillon ni la page d’accueil', array( 41, 5 ) === array_values( $query['post__not_in'] ), $query['post__not_in'] );
check( 'adresse de la page modèle', 'https://site.test/actualites/fete' === $mapper->preview_url( $stand_in ) );

foreach ( array( 'pending', 'future', 'private' ) as $status ) {
	$other = new WP_Post( array( 'ID' => 42, 'post_type' => 'post', 'post_status' => $status, 'post_name' => 'x' ) );
	check( "statut $status : page modèle", $published === $mapper->stand_in( $other ) );
}

$GLOBALS['found'] = array();
check( 'aucun contenu publié du type : pas de page modèle', null === $mapper->stand_in( $draft ) );

// Pages : seulement une page du même modèle de page.
$GLOBALS['queries'] = array();
$page_draft         = new WP_Post( array( 'ID' => 50, 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'nouvelle' ) );
check( 'page au modèle par défaut : pas de page modèle, aucune requête', null === $mapper->stand_in( $page_draft ) && array() === $GLOBALS['queries'] );
$GLOBALS['meta'][50]['_wp_page_template'] = 'page-rubrique.php';
$sibling                                  = new WP_Post( array( 'ID' => 51, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'culture' ) );
$GLOBALS['found']                         = array( $sibling );
check( 'page avec modèle : une page publiée du même modèle', $sibling === $mapper->stand_in( $page_draft ) && 'page-rubrique.php' === $GLOBALS['queries'][0]['meta_value'], $GLOBALS['queries'] );

// Filtre : désactiver (front qui affiche les brouillons), ou choisir.
$GLOBALS['found']                             = array( $published );
$GLOBALS['filters']['leiho_preview_stand_in'] = function ( $stand_in, $post ) { return null; };
check( 'filtre : null désactive la page modèle', null === $mapper->stand_in( $draft ) );
$GLOBALS['filters']['leiho_preview_stand_in'] = function ( $stand_in, $post ) { return $post; };
check( 'filtre : le contenu lui-même est refusé', null === $mapper->stand_in( $draft ) );
$GLOBALS['filters']['leiho_preview_stand_in'] = function ( $stand_in, $post ) { return new WP_Post( array( 'ID' => 60, 'post_type' => 'post', 'post_status' => 'draft' ) ); };
check( 'filtre : un autre brouillon est refusé (il n’a pas de page non plus)', null === $mapper->stand_in( $draft ) );

echo $failures ? "\nÉCHECS.\n" : "\nTOUS LES TESTS PASSENT\n";
exit( $failures ? 1 : 0 );
