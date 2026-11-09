<?php
/**
 * Champs natifs du formulaire (contenu, extrait, adresse, date, auteur,
 * parent, ordre, taxonomies) : schéma, nettoyage, droits, contenu en blocs
 * protégé. php plugin/tests/test-native-fields.php
 */
define( 'ABSPATH', true );

$GLOBALS['caps']       = array();      // Droits refusés : cap => false.
$GLOBALS['posts']      = array();
$GLOBALS['terms']      = array();      // id => (object) { term_id, name, taxonomy }
$GLOBALS['obj_terms']  = array();      // post => tax => ids
$GLOBALS['inserted']   = array();
$GLOBALS['users']      = array( 1 => 'Admin', 2 => 'Rédactrice', 3 => 'Abonné' );
$GLOBALS['supports']   = array(
	'post' => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail' ),
	'page' => array( 'title', 'editor', 'page-attributes' ),
	'ville' => array( 'title' ),
);

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function current_user_can( $cap, $id = 0 ) { return ! isset( $GLOBALS['caps'][ $cap ] ); }
function user_can( $user, $cap ) { return 3 !== (int) $user->ID; }
function post_type_supports( $type, $feature ) { return in_array( $feature, $GLOBALS['supports'][ $type ] ?? array(), true ); }
function has_blocks( $c ) { return false !== strpos( (string) $c, '<!-- wp:' ); }
function sanitize_title( $v ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_kses_post( $v ) { return preg_replace( '#<script.*?</script>#is', '', (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function get_gmt_from_date( $d ) { return $d; }
function get_userdata( $id ) { return isset( $GLOBALS['users'][ $id ] ) ? (object) array( 'ID' => $id, 'display_name' => $GLOBALS['users'][ $id ] ) : false; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : false; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error {
	public $code; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->data = $d; }
	public function get_error_data( $c = '' ) { return $this->data; }
}
function get_post_type_object( $type ) {
	return (object) array(
		'name'         => $type,
		'hierarchical' => 'page' === $type,
		'public'       => true,
		'cap'          => (object) array( 'edit_posts' => 'edit_posts', 'edit_others_posts' => 'edit_others_posts', 'publish_posts' => 'publish_posts' ),
	);
}
function tax_object( $name ) {
	return (object) array(
		'name'         => $name,
		'public'       => true,
		'show_ui'      => true,
		'hierarchical' => 'category' === $name,
		'labels'       => (object) array( 'name' => ucfirst( $name ) ),
		'cap'          => (object) array( 'assign_terms' => 'assign_' . $name, 'edit_terms' => 'edit_' . $name ),
	);
}
function get_object_taxonomies( $type, $out = 'names' ) {
	if ( 'post' !== $type ) {
		return array();
	}
	return array( 'category' => tax_object( 'category' ), 'post_tag' => tax_object( 'post_tag' ), 'post_format' => tax_object( 'post_format' ) );
}
function get_taxonomy( $name ) { return in_array( $name, array( 'category', 'post_tag' ), true ) ? tax_object( $name ) : false; }
function get_term( $id, $tax ) {
	$t = $GLOBALS['terms'][ $id ] ?? null;
	return $t && $t->taxonomy === $tax ? $t : null;
}
function get_term_by( $field, $value, $tax ) {
	foreach ( $GLOBALS['terms'] as $t ) {
		if ( $t->taxonomy === $tax && $t->name === $value ) {
			return $t;
		}
	}
	return false;
}
function wp_insert_term( $name, $tax ) {
	$id                      = 100 + count( $GLOBALS['terms'] );
	$GLOBALS['terms'][ $id ] = (object) array( 'term_id' => $id, 'name' => $name, 'taxonomy' => $tax );
	$GLOBALS['inserted'][]   = $name;
	return array( 'term_id' => $id );
}
function wp_set_object_terms( $post_id, $ids, $tax ) { $GLOBALS['obj_terms'][ $post_id ][ $tax ] = $ids; return $ids; }

/** Conversion en blocs factice : la vraie demande l'analyseur de blocs de WordPress. */
class Leiho_Blocks {
	public static function unsupported( $c ) { return false !== strpos( (string) $c, 'wp:columns' ) ? array( 'core/columns' ) : array(); }
	public static function to_html( $c ) { return trim( preg_replace( '/<!--.*?-->/s', '', (string) $c ) ); }
	public static function from_html( $html, $original ) { return 'BLOCKS(' . $html . ')'; }
}
if ( ! function_exists( 'wpautop' ) ) {
	function wpautop( $v ) { return false === strpos( $v, '<p' ) ? '<p>' . $v . '</p>' : $v; }
}
require __DIR__ . '/../includes/class-leiho-native-fields.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

function post( $id, $type, $extra = array() ) {
	$GLOBALS['posts'][ $id ] = (object) array_merge(
		array( 'ID' => $id, 'post_type' => $type, 'post_title' => 'Un titre', 'post_content' => 'Texte', 'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'un-titre' ),
		$extra
	);
	return $GLOBALS['posts'][ $id ];
}

$GLOBALS['terms'][5] = (object) array( 'term_id' => 5, 'name' => 'Actualités', 'taxonomy' => 'category' );
$GLOBALS['terms'][6] = (object) array( 'term_id' => 6, 'name' => 'cloud', 'taxonomy' => 'post_tag' );

/* Contenu, extrait, adresse, date, auteur ---------------------------- */

$article = post( 10, 'post' );
$values  = array(
	'_content'     => "Bonjour <strong>monde</strong><script>alert(1)</script>",
	'_excerpt'     => '  Résumé  ',
	'_slug'        => 'Mon Article Été',
	'_date'        => '2026-11-04T09:30',
	'_author'      => '2',
	'_tax_category' => array( 5 ),
	'_tax_post_tag' => array( 6, 'nouvelle étiquette' ),
	'autre'        => 'pour un adapter',
);
$GLOBALS['caps']['unfiltered_html'] = false;
$r = Leiho_Native_Fields::prepare( $article, $values );
check( 'aucune erreur', array() === $r['errors'], $r['errors'] );
check( 'chemins natifs retirés, les autres laissés', array( 'autre' ) === array_keys( $values ), $values );
check( 'contenu filtré (pas de droit unfiltered_html)', 'Bonjour <strong>monde</strong>' === $r['postarr']['post_content'], $r['postarr'] );
check( 'extrait nettoyé', 'Résumé' === $r['postarr']['post_excerpt'] );
check( 'slug normalisé', 'mon-article-t' === $r['postarr']['post_name'], $r['postarr']['post_name'] );
check( 'date au format MySQL', '2026-11-04 09:30:00' === $r['postarr']['post_date'] && ! empty( $r['postarr']['edit_date'] ) );
check( 'auteur', 2 === $r['postarr']['post_author'] );
check( 'catégorie écrite', array( 5 ) === $GLOBALS['obj_terms'][10]['category'] );
check( 'étiquette créée à l’enregistrement', in_array( 'nouvelle étiquette', $GLOBALS['inserted'], true ) && 2 === count( $GLOBALS['obj_terms'][10]['post_tag'] ), $GLOBALS['obj_terms'] );
check( 'chemins enregistrés', 7 === count( $r['saved'] ), $r['saved'] );
unset( $GLOBALS['caps']['unfiltered_html'] );

/* Droits ------------------------------------------------------------- */

$values = array( '_author' => 2 );
$GLOBALS['caps']['edit_others_posts'] = false;
$r = Leiho_Native_Fields::prepare( $article, $values );
check( 'auteur : refusé sans edit_others_posts', 'forbidden' === ( $r['errors']['_author'] ?? '' ) && ! isset( $r['postarr']['post_author'] ) );
unset( $GLOBALS['caps']['edit_others_posts'] );

$values = array( '_author' => 3 );
$r      = Leiho_Native_Fields::prepare( $article, $values );
check( 'auteur : utilisateur qui ne peut pas écrire refusé', 'invalid_user' === ( $r['errors']['_author'] ?? '' ) );

$values = array( '_tax_category' => array( 'Nouvelle catégorie' ) );
$GLOBALS['caps']['edit_category'] = false;
$GLOBALS['inserted']              = array();
$r = Leiho_Native_Fields::prepare( $article, $values );
check( 'catégorie : création refusée sans edit_terms', 'forbidden' === ( $r['errors']['_tax_category'] ?? '' ) && ! $GLOBALS['inserted'] );
unset( $GLOBALS['caps']['edit_category'] );

$values = array( '_tax_post_tag' => array( 6 ) );
$GLOBALS['caps']['assign_post_tag'] = false;
$r = Leiho_Native_Fields::prepare( $article, $values );
check( 'étiquettes : refusé sans assign_terms', 'forbidden' === ( $r['errors']['_tax_post_tag'] ?? '' ) );
unset( $GLOBALS['caps']['assign_post_tag'] );

$values = array( '_tax_post_format' => array( 1 ), '_tax_inconnue' => array( 1 ) );
$r      = Leiho_Native_Fields::prepare( $article, $values );
check( 'taxonomie non proposée refusée', 'unknown_field' === ( $r['errors']['_tax_post_format'] ?? '' ) && 'unknown_field' === ( $r['errors']['_tax_inconnue'] ?? '' ), $r['errors'] );

$values = array( '_tax_category' => array( 6 ) );
$r      = Leiho_Native_Fields::prepare( $article, $values );
check( 'terme d’une autre taxonomie refusé', 'invalid_term' === ( $r['errors']['_tax_category'] ?? '' ) );

/* Contenu en blocs protégé ------------------------------------------- */

$blocks = post( 11, 'post', array( 'post_content' => "<!-- wp:paragraph -->\n<p>Blocs</p>\n<!-- /wp:paragraph -->" ) );
$values = array( '_content' => '<p>écrasé</p>' );
$r      = Leiho_Native_Fields::prepare( $blocks, $values );
check( 'contenu en blocs courants : réécrit en blocs', 'BLOCKS(<p>écrasé</p>)' === ( $r['postarr']['post_content'] ?? '' ) && empty( $r['errors'] ), $r );

$columns = post( 12, 'post', array( 'post_content' => "<!-- wp:columns -->\n<div class=\"wp-block-columns\"></div>\n<!-- /wp:columns -->" ) );
$values  = array( '_content' => '<p>écrasé</p>' );
$r       = Leiho_Native_Fields::prepare( $columns, $values );
check( 'contenu en blocs non convertibles : écriture refusée', 'blocks_protected' === ( $r['errors']['_content'] ?? '' ) && ! isset( $r['postarr']['post_content'] ) );

/* Validation --------------------------------------------------------- */

foreach ( array( '2026-02-30 10:00', '2026-11-04 25:00', 'demain', 12 ) as $bad ) {
	$values = array( '_date' => $bad );
	$r      = Leiho_Native_Fields::prepare( $article, $values );
	check( 'date invalide refusée : ' . var_export( $bad, true ), 'invalid_date' === ( $r['errors']['_date'] ?? '' ) );
}
check( 'date sans heure acceptée', '2026-11-04 00:00:00' === Leiho_Native_Fields::parse_date( '2026-11-04' ) );

$values = array( '_slug' => '   ' );
$r      = Leiho_Native_Fields::prepare( $article, $values );
check( 'slug vide : repris du titre', 'un-titre' === $r['postarr']['post_name'] );

$values = array( '_excerpt' => 'x' );
$ville  = post( 12, 'ville' );
$r      = Leiho_Native_Fields::prepare( $ville, $values );
check( 'extrait sur un type sans extrait refusé', 'unknown_field' === ( $r['errors']['_excerpt'] ?? '' ) );

/* Parent et ordre (pages) -------------------------------------------- */

$root  = post( 20, 'page' );
$child = post( 21, 'page', array( 'post_parent' => 20 ) );
post( 22, 'page', array( 'post_parent' => 21 ) );

$values = array( '_parent' => 22 );
$r      = Leiho_Native_Fields::prepare( $root, $values );
check( 'parent : un descendant refusé (boucle)', 'invalid_parent' === ( $r['errors']['_parent'] ?? '' ) );

$values = array( '_parent' => 20 );
$r      = Leiho_Native_Fields::prepare( $root, $values );
check( 'parent : soi-même refusé', 'invalid_parent' === ( $r['errors']['_parent'] ?? '' ) );

$values = array( '_parent' => 10 );
$r      = Leiho_Native_Fields::prepare( $child, $values );
check( 'parent : autre type refusé', 'invalid_parent' === ( $r['errors']['_parent'] ?? '' ) );

$values = array( '_parent' => 0, '_menu_order' => '3' );
$r      = Leiho_Native_Fields::prepare( $child, $values );
check( 'parent retiré et ordre', 0 === $r['postarr']['post_parent'] && 3 === $r['postarr']['menu_order'] && ! $r['errors'] );

$values = array( '_parent' => 20 );
$r      = Leiho_Native_Fields::prepare( $article, $values );
check( 'parent sur un type non hiérarchique refusé', 'unknown_field' === ( $r['errors']['_parent'] ?? '' ) );

check( 'owns : chemins natifs', Leiho_Native_Fields::owns( '_content' ) && Leiho_Native_Fields::owns( '_tax_post_tag' ) && ! Leiho_Native_Fields::owns( '_title' ) && ! Leiho_Native_Fields::owns( 'titre' ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
