<?php
/**
 * Import ACF → Leiho Collections sans WordPress : php plugin/tests/test-acf-import.php
 *
 * Correspondance des champs, conversion des valeurs, import par lots avec
 * la validation de l'éditeur, égalité du contenu servi avant / après (champ
 * par champ, JSON identique), échecs et écarts signalés, bascule des
 * adapters, retour à ACF. ACF est simulé (groupes, get_field) ; les tables
 * Leiho tournent sur une vraie base SQLite en mémoire (PDO).
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	fwrite( STDERR, "pdo_sqlite is required for this test.\n" );
	exit( 1 );
}

define( 'ABSPATH', true );
define( 'LEIHO_VERSION', 'test' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );

/* --- WordPress minimal --- */
$GLOBALS['options']   = array();
$GLOBALS['posts']     = array(); // id => [ type, status, name ].
$GLOBALS['revisions'] = 0;
$GLOBALS['filters']   = array();

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function add_data( $d ) { $this->data = $d; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $tag ][] = $cb; return true; }
function add_action( $tag, $cb = null, $p = 10, $n = 1 ) { return true; }
function remove_filter( $tag, $cb ) {
	foreach ( isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : array() as $i => $f ) {
		if ( $f === $cb ) { unset( $GLOBALS['filters'][ $tag ][ $i ] ); }
	}
	return true;
}
function apply_filters( $tag, $value, ...$args ) {
	foreach ( isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : array() as $cb ) { $value = call_user_func( $cb, $value, ...$args ); }
	return $value;
}
function __return_false() { return false; }
function has_filter( $tag ) { return false; }
function do_action( $tag, ...$a ) { $GLOBALS['actions'][] = $tag; }
function did_action( $h ) { return 0; }
function wp_is_post_revision( $p ) { return false; }
function wp_is_post_autosave( $p ) { return false; }
function wp_using_ext_object_cache() { return false; }
function wp_cache_get( $k, $g = '', $f = false, &$found = null ) { $found = isset( $GLOBALS['cache'][ $g ][ $k ] ); return $found ? $GLOBALS['cache'][ $g ][ $k ] : false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_add( $k, $v, $g = '', $t = 0 ) { return isset( $GLOBALS['cache'][ $g ][ $k ] ) ? false : wp_cache_set( $k, $v, $g ); }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache'][ $g ][ $k ] ); return true; }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : ''; }
function wp_kses_post( $v ) { return preg_replace( '#<script.*?</script>#is', '', (string) $v ); }
function esc_url_raw( $v ) { return (string) $v; }
function wpautop( $v ) { return '<p>' . $v . '</p>'; }
function absint( $v ) { return abs( (int) $v ); }
function wp_list_pluck( $l, $f ) { return array_column( $l, $f ); }
function current_user_can( $cap, $id = 0 ) { return true; }
function get_current_user_id() { return 1; }
function post_type_exists( $t ) { return in_array( $t, array( 'ville', 'pole', 'page', 'service' ), true ); }
function post_type_supports( $t, $f ) { return 'revisions' === $f; }
function get_post_types( $a = array() ) { return array( 'ville' => 'ville', 'pole' => 'pole', 'page' => 'page', 'service' => 'service', 'attachment' => 'attachment' ); }
function get_post_type_object( $t ) {
	$names = array( 'ville' => array( 'Villes', 'Ville' ), 'pole' => array( 'Pôles', 'Pôle' ), 'page' => array( 'Pages', 'Page' ), 'service' => array( 'Services', 'Service' ) );
	if ( ! isset( $names[ $t ] ) ) { return null; }
	return (object) array( '_builtin' => 'page' === $t, 'labels' => (object) array( 'name' => $names[ $t ][0], 'singular_name' => $names[ $t ][1] ) );
}
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ (int) $id ] ) ? $GLOBALS['posts'][ (int) $id ][0] : false; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return null; }
	$p = $GLOBALS['posts'][ $id ];
	return (object) array( 'ID' => $id, 'post_type' => $p[0], 'post_status' => $p[1], 'post_name' => $p[2], 'post_parent' => 0 );
}
function get_the_title( $p ) { return 'Titre ' . ( is_object( $p ) ? $p->ID : $p ); }
function get_post_meta( $id, $key = '', $single = false ) { return '_wp_attachment_image_alt' === $key ? 'Alt ' . $id : ''; }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function wp_get_attachment_url( $id ) { return 'https://cdn/' . $id . '.jpg'; }
function wp_get_attachment_image_url( $id, $s ) { return 'https://cdn/' . $id . '-' . $s . '.jpg'; }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 800, 'height' => 600, 'sizes' => array( 'medium' => array( 'file' => $id . '-300.jpg', 'width' => 300, 'height' => 225 ) ) ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function get_posts( $a ) { return array(); }
function get_terms( $a ) { return array(); }
function get_term( $id, $t = '' ) { return null; }
function wp_save_post_revision( $id ) { $GLOBALS['revisions']++; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_slash( $v ) { return $v; }

class Leiho_Plugin {
	public static function title( $p ) { return get_the_title( $p ); }
	public static function settings() {
		$settings = array( 'front_url' => '', 'url_patterns' => array( 'ville' => '/villes/{slug}', 'pole' => '/poles/{slug}' ), 'path_meta_key' => '', 'post_types' => array( 'page', 'ville', 'pole' ) );
		return Leiho_Collections::settings( $settings );
	}
}

/* --- ACF simulé : groupes, définitions, valeurs brutes (format=false) --- */
$GLOBALS['acf_groups'] = array();
$GLOBALS['acf_values'] = array(); // post_id => [ field key => raw ].
function acf_get_field_groups( $filter = array() ) {
	$out = array();
	foreach ( $GLOBALS['acf_groups'] as $group ) {
		if ( isset( $filter['post_id'] ) && ! acf_location_matches( $group['location'], (int) $filter['post_id'] ) ) {
			continue;
		}
		$out[] = $group;
	}
	return $out;
}
/** Règles d'emplacement évaluées comme ACF pour une entrée : OU de ET (post_type, post). */
function acf_location_matches( $location, $post_id ) {
	foreach ( $location as $branch ) {
		$ok = true;
		foreach ( $branch as $rule ) {
			if ( 'post_type' === $rule['param'] ) {
				$hit = get_post_type( $post_id ) === $rule['value'];
			} elseif ( 'post' === $rule['param'] ) {
				$hit = (int) $rule['value'] === $post_id;
			} else {
				$ok = false;
				break;
			}
			if ( $hit !== ( '==' === $rule['operator'] ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return true;
		}
	}
	return false;
}
function acf_get_fields( $group ) {
	$key = is_array( $group ) ? $group['key'] : $group;
	foreach ( $GLOBALS['acf_groups'] as $g ) {
		if ( $g['key'] === $key ) { return $g['fields']; }
	}
	return array();
}
function get_field( $key, $post_id, $format = true ) {
	return isset( $GLOBALS['acf_values'][ $post_id ][ $key ] ) ? $GLOBALS['acf_values'][ $post_id ][ $key ] : null;
}

/** $wpdb minimal sur SQLite en mémoire, avec wp_posts / wp_postmeta. */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';
	private $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->exec( 'CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT )' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT )' );
	}
	public function get_charset_collate() { return ''; }
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v );
			},
			$sql
		);
	}
	private function run( $sql ) {
		$sql              = str_replace( ' UNSIGNED', '', $sql );
		$sql              = preg_replace( '/^DROP INDEX (`\w+`) ON `\w+`$/', 'DROP INDEX $1', $sql );
		$this->last_error = '';
		$stmt             = $this->pdo->query( $sql );
		if ( false === $stmt ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			return false;
		}
		return $stmt;
	}
	public function exec( $sql ) { $this->pdo->exec( $sql ); }
	public function query( $sql ) { $stmt = $this->run( $sql ); return false === $stmt ? false : $stmt->rowCount(); }
	public function get_results( $sql, $o = null ) { $stmt = $this->run( $sql ); return false === $stmt ? array() : $stmt->fetchAll( PDO::FETCH_ASSOC ); }
	public function get_row( $sql, $o = null ) { $rows = $this->get_results( $sql ); return $rows ? $rows[0] : null; }
	public function get_var( $sql ) { $row = $this->get_row( $sql ); return $row ? reset( $row ) : null; }
	public function get_col( $sql ) { return array_map( function ( $r ) { return reset( $r ); }, $this->get_results( $sql ) ); }
	private function values( $data ) {
		$out = array();
		foreach ( $data as $k => $v ) { $out[ $k ] = null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : $this->pdo->quote( (string) $v ) ); }
		return $out;
	}
	public function insert( $table, $data, $formats = null ) {
		$v = $this->values( $data );
		return $this->query( "INSERT INTO `$table` (`" . implode( '`,`', array_keys( $v ) ) . '`) VALUES (' . implode( ',', $v ) . ')' );
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		$set = array();
		foreach ( $this->values( $data ) as $k => $v ) { $set[] = "`$k` = $v"; }
		$cond = array();
		foreach ( $this->values( $where ) as $k => $v ) { $cond[] = "`$k` = $v"; }
		return $this->query( "UPDATE `$table` SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
	}
	public function delete( $table, $where, $f = null ) {
		$cond = array();
		foreach ( $this->values( $where ) as $k => $v ) { $cond[] = "`$k` = $v"; }
		return $this->query( "DELETE FROM `$table` WHERE " . implode( ' AND ', $cond ) );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require __DIR__ . '/../includes/adapters/interface-leiho-adapter.php';
require __DIR__ . '/../includes/adapters/abstract-leiho-field-adapter.php';
require __DIR__ . '/../includes/adapters/class-leiho-acf-adapter.php';
require __DIR__ . '/../includes/adapters/class-leiho-native-adapter.php';
require __DIR__ . '/../includes/collections/class-leiho-collections-schema.php';
require __DIR__ . '/../includes/class-leiho-i18n.php';
require __DIR__ . '/../includes/collections/class-leiho-collections-values.php';
require __DIR__ . '/../includes/collections/class-leiho-collections-store.php';
require __DIR__ . '/../includes/collections/class-leiho-collections.php';
require __DIR__ . '/../includes/collections/class-leiho-acf-import.php';
require __DIR__ . '/../includes/class-leiho-url-mapper.php';
require __DIR__ . '/../includes/class-leiho-content.php';
require __DIR__ . '/../includes/class-leiho-cache.php';
require __DIR__ . '/../includes/class-leiho-types.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/* =====================================================================
 * Fixture : un site façon Dyese
 * =================================================================== */

function post( $id, $type, $status = 'publish', $name = '' ) {
	$GLOBALS['posts'][ $id ] = array( $type, $status, '' !== $name ? $name : 'slug-' . $id );
	$GLOBALS['wpdb']->exec( "INSERT INTO wp_posts (ID, post_type, post_status) VALUES ($id, '$type', '$status')" );
}
function f( $key, $name, $type, $extra = array() ) { return array_merge( array( 'key' => $key, 'name' => $name, 'label' => ucfirst( $name ), 'type' => $type ), $extra ); }

post( 7, 'attachment', 'inherit' );
post( 8, 'attachment', 'inherit' );
post( 20, 'pole', 'publish', 'securite' );
post( 21, 'pole', 'draft', 'brouillon' );
foreach ( array( 10, 11, 12, 13 ) as $id ) {
	post( $id, 'ville', 13 === $id ? 'draft' : 'publish', 'ville-' . $id );
}
post( 30, 'page' );

$GLOBALS['acf_groups'] = array(
	array(
		'key'      => 'group_contenu',
		'title'    => 'Contenu de page',
		'active'   => true,
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'ville' ) ), array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'pole' ) ) ),
		'fields'   => array(
			f( 'field_tab', '', 'tab' ),
			f( 'field_h1', 'h1', 'text', array( 'required' => 1, 'maxlength' => 60 ) ),
			f( 'field_hero', 'hero_body', 'textarea' ),
			f( 'field_camel', 'heroTitle', 'text' ),
			f( 'field_stats', 'hero_stats', 'repeater', array( 'sub_fields' => array( f( 'field_stats_n', 'nombre', 'text' ), f( 'field_stats_l', 'label', 'text' ) ) ) ),
			f( 'field_sections', 'sections', 'repeater', array( 'sub_fields' => array(
				f( 'field_s_label', 'label', 'text' ),
				f( 'field_s_cartes', 'cartes', 'repeater', array( 'sub_fields' => array(
					f( 'field_c_titre', 'titre', 'text' ),
					f( 'field_c_puces', 'puces', 'repeater', array( 'sub_fields' => array( f( 'field_p_texte', 'texte', 'text' ) ) ) ),
				) ) ),
				f( 'field_s_faq', 'faq', 'repeater', array( 'sub_fields' => array( f( 'field_f_q', 'question', 'text' ), f( 'field_f_r', 'reponse', 'wysiwyg' ) ) ) ),
			) ) ),
		),
	),
	array(
		'key'      => 'group_details',
		'title'    => 'Détails',
		'active'   => true,
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'ville' ) ) ),
		'fields'   => array(
			f( 'field_visuel', 'visuel', 'image', array( 'mime_types' => 'jpg' ) ),
			f( 'field_galerie', 'galerie', 'gallery' ),
			f( 'field_cta', 'cta', 'link' ),
			f( 'field_voisines', 'voisines', 'relationship', array( 'post_type' => array( 'ville' ), 'max' => 3 ) ),
			f( 'field_pole', 'pole', 'post_object', array( 'post_type' => array( 'pole' ) ) ),
			f( 'field_niveau', 'niveau', 'select', array( 'choices' => array( 'a' => 'A', 'b' => 'B', '1' => 'Un' ) ) ),
			f( 'field_options', 'options', 'checkbox', array( 'choices' => array( 'x' => 'X', 'y' => 'Y' ) ) ),
			f( 'field_format', 'format', 'radio', array( 'choices' => array( 'site' => 'Site', 'distance' => 'Distance' ) ) ),
			f( 'field_vedette', 'vedette', 'true_false' ),
			f( 'field_date', 'lancement', 'date_picker' ),
			f( 'field_maj', 'maj', 'date_time_picker' ),
			f( 'field_prix', 'prix', 'number', array( 'min' => 0 ) ),
			f( 'field_note', 'note', 'range', array( 'min' => 0, 'max' => 5, 'step' => 0.5 ) ),
			f( 'field_couleur', 'couleur', 'color_picker' ),
			f( 'field_heure', 'horaire', 'time_picker' ),
			f( 'field_intro', 'intro', 'wysiwyg' ),
			f( 'field_cond', 'detail', 'text', array( 'conditional_logic' => array( array( array( 'field' => 'field_vedette', 'operator' => '==', 'value' => '1' ) ) ) ) ),
			f( 'field_badge', 'badge', 'text', array( 'default_value' => 'Nouveau' ) ),
			f( 'field_coord', 'coordonnees', 'group', array( 'sub_fields' => array( f( 'field_tel', 'tel', 'text' ), f( 'field_cville', 'ville', 'post_object', array( 'post_type' => array( 'ville' ) ) ) ) ) ),
			f( 'field_blocs', 'blocs', 'flexible_content', array( 'layouts' => array(
				'l1' => array( 'key' => 'layout_texte', 'name' => 'texte', 'label' => 'Texte', 'sub_fields' => array( f( 'field_b_titre', 'titre', 'text' ), f( 'field_b_corps', 'corps', 'wysiwyg' ) ) ),
				'l2' => array( 'key' => 'layout_img', 'name' => 'image-pleine', 'label' => 'Image', 'sub_fields' => array( f( 'field_b_img', 'image', 'image' ), f( 'field_b_leg', 'legendeImage', 'text' ) ) ),
				'l3' => array( 'key' => 'layout_chiffres', 'name' => 'chiffres', 'label' => 'Chiffres', 'sub_fields' => array( f( 'field_b_items', 'items', 'repeater', array( 'sub_fields' => array( f( 'field_b_val', 'valeur', 'number' ) ) ) ) ) ),
			) ) ),
			f( 'field_lien', 'page_liee', 'page_link' ),
			f( 'field_clone', 'reprise', 'clone', array( 'clone' => array( 'field_prix' ) ) ),
			f( 'field_clone_g', 'tarif', 'clone', array( 'display' => 'group', 'clone' => array( 'field_prix' ), 'sub_fields' => array( f( 'field_clone_g_field_prix', 'prix', 'number' ) ) ) ),
		),
	),
	array(
		'key'      => 'group_page',
		'title'    => 'Page',
		'active'   => true,
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields'   => array( f( 'field_sous', 'sous_titre', 'text' ) ),
	),
	array(
		'key'      => 'group_options',
		'title'    => 'Réglages',
		'active'   => true,
		'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'reglages' ) ) ),
		'fields'   => array( f( 'field_tel_site', 'telephone', 'text' ) ),
	),
);

function contenu( $id, $n ) {
	return array(
		'field_h1'       => 'Ville ' . $id,
		'field_hero'     => "Ligne 1\nLigne 2 « et » &",
		'field_camel'    => 'Titre ' . $n,
		'field_stats'    => array( array( 'field_stats_n' => '24/7', 'field_stats_l' => 'Astreinte' ), array( 'field_stats_n' => (string) ( 10 + $n ), 'field_stats_l' => '' ) ),
		'field_sections' => array(
			array(
				'field_s_label'  => 'Section 0',
				'field_s_cartes' => array(
					array( 'field_c_titre' => 'Carte 1', 'field_c_puces' => array( array( 'field_p_texte' => 'Puce 1' ), array( 'field_p_texte' => 'Puce 2' ) ) ),
					array( 'field_c_titre' => 'Carte 2', 'field_c_puces' => '' ),
				),
				'field_s_faq'    => '',
			),
			array(
				'field_s_label'  => '',
				'field_s_cartes' => '',
				'field_s_faq'    => array( array( 'field_f_q' => 'Pourquoi ?', 'field_f_r' => '<strong>Parce que</strong>' ) ),
			),
		),
	);
}
$GLOBALS['acf_values'][10] = array_merge(
	contenu( 10, 0 ),
	array(
		'field_visuel'   => '7',
		'field_galerie'  => array( '7', '999', '8' ), // 999 : média supprimé, ignoré par ACF comme par Leiho.
		'field_cta'      => array( 'title' => 'Devis', 'url' => 'https://dyese.fr/devis', 'target' => '_blank' ),
		'field_voisines' => array( '11', '13', '12' ), // 13 : brouillon, absent du contenu servi.
		'field_pole'     => '20',
		'field_niveau'   => '1',
		'field_options'  => array( 'x', 'y' ),
		'field_format'   => 'site',
		'field_vedette'  => '1',
		'field_date'     => '20250115',
		'field_maj'      => '2026-10-01 09:30:00',
		'field_prix'     => '1200',
		'field_note'     => '4.5',
		'field_couleur'  => '#2a9d8f',
		'field_heure'    => '08:30:00',
		'field_intro'    => '<p>Intro <em>riche</em></p>',
		'field_cond'     => 'Visible si vedette',
		'field_badge'    => 'Populaire',
		'field_coord'    => array( 'field_tel' => '04 72 00 00 00', 'field_cville' => '11' ),
		'field_blocs'    => array(
			array( 'acf_fc_layout' => 'texte', 'field_b_titre' => 'Bloc', 'field_b_corps' => '<p>Corps</p>' ),
			array( 'acf_fc_layout' => 'image-pleine', 'field_b_img' => '8', 'field_b_leg' => 'Légende' ),
			array( 'acf_fc_layout' => 'chiffres', 'field_b_items' => array( array( 'field_b_val' => '98' ), array( 'field_b_val' => '2.5' ) ) ),
			array( 'acf_fc_layout' => 'disparu', 'field_x' => 'layout retiré : ni servi ni importé' ),
		),
		'field_lien'     => '20',
		'field_clone'    => array(),
		'field_clone_g'  => array( 'field_clone_g_field_prix' => '7' ),
	)
);
$GLOBALS['acf_values'][11] = array_merge( contenu( 11, 1 ), array( 'field_prix' => '89.5', 'field_vedette' => '0', 'field_badge' => 'Nouveau', 'field_niveau' => '' ) );
$GLOBALS['acf_values'][12] = array_merge( contenu( 12, 2 ), array( 'field_niveau' => 'zzz', 'field_badge' => 'Nouveau' ) ); // Choix inconnu : échec.
$GLOBALS['acf_values'][13] = array( 'field_h1' => 'Brouillon <b>gras</b>', 'field_badge' => 'Nouveau' ); // Texte réécrit par la validation : écart.
$GLOBALS['acf_values'][20] = array_merge( contenu( 20, 3 ), array() );
$GLOBALS['acf_values'][21] = array( 'field_h1' => str_repeat( 'x', 80 ) ); // Plus long que maxlength : limite élargie par l'aperçu.

// Valeurs stockées par ACF : une méta de référence par valeur (compteur de l'aperçu).
$GLOBALS['wpdb']->exec( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (10, '_h1', 'field_h1'), (10, '_prix', 'field_prix'), (10, '_sections_0_label', 'field_s_label'), (11, '_h1', 'field_h1'), (30, '_sous_titre', 'field_sous')" );

/** Contenu servi, tel que l'export le construit (adapters dans l'ordre de Leiho). */
function served( $id ) {
	$fields = array();
	foreach ( array( new Leiho_Native_Adapter(), new Leiho_ACF_Adapter() ) as $adapter ) {
		$fields = array_merge( $fields, $adapter->get_content( $id ) );
	}
	return $fields;
}
function json( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }

/* =====================================================================
 * Correspondance des champs
 * =================================================================== */

$preview = Leiho_ACF_Import::preview( 'ville' );
check( 'aperçu : ville importable', $preview['importable'] && 'external' === $preview['origin'], array( $preview['reason'], $preview['errors'] ) );
check( 'aperçu : compteurs', 4 === $preview['counts']['entries'] && 4 === $preview['counts']['values'] && $preview['counts']['fields'] > 30, $preview['counts'] );

$c = $preview['collection'];
$by = array();
foreach ( $c['fields'] as $field ) { $by[ $field['name'] ] = $field; }
check( 'collection externe, source acf', true === $c['external'] && 'acf' === $c['source'] && 'Villes' === $c['label'] && 'Ville' === $c['singular_label'] );
check( 'ordre des groupes et des champs', array( 'h1', 'hero_body', 'heroTitle', 'hero_stats', 'sections', 'visuel' ) === array_slice( array_keys( $by ), 0, 6 ), array_keys( $by ) );
$types = array();
foreach ( array( 'h1' => 'text', 'hero_body' => 'text', 'hero_stats' => 'list', 'visuel' => 'image', 'galerie' => 'gallery', 'cta' => 'link', 'voisines' => 'reference', 'pole' => 'reference', 'niveau' => 'select', 'options' => 'select', 'format' => 'select', 'vedette' => 'boolean', 'lancement' => 'date', 'maj' => 'datetime', 'prix' => 'number', 'note' => 'number', 'couleur' => 'color', 'horaire' => 'text', 'intro' => 'richtext', 'coordonnees' => 'group', 'blocs' => 'blocks' ) as $name => $type ) {
	$types[ $name ] = isset( $by[ $name ] ) ? $by[ $name ]['type'] : null;
	check( "type : $name → $type", isset( $by[ $name ] ) && $type === $by[ $name ]['type'], $types[ $name ] );
}
check( 'textarea → texte multiligne', ! empty( $by['hero_body']['multiline'] ) );
check( 'maxlength → max_length, required', 60 === $by['h1']['max_length'] && $by['h1']['required'] );
check( 'nom camelCase gardé, clé dérivée', 'heroTitle' === $by['heroTitle']['name'] && 'herotitle' === $by['heroTitle']['key'] );
check( 'checkbox → choix multiple', true === $by['options']['multiple'] && false === $by['niveau']['multiple'] );
check( 'choix : valeur "1" en chaîne', array( 'a', 'b', '1' ) === array_column( $by['niveau']['choices'], 'value' ) );
check( 'relation : cibles et max', array( 'ville' ) === $by['voisines']['to'] && $by['voisines']['multiple'] && 3 === $by['voisines']['max'] );
check( 'post object simple', array( 'pole' ) === $by['pole']['to'] && ! $by['pole']['multiple'] );
check( 'range : bornes et pas', 0 === $by['note']['min'] && 5 === $by['note']['max'] && 0.5 === $by['note']['step'] );
$blocs = $by['blocs']['blocks'];
check( 'flexible : layouts → blocs, nom d\'API gardé', array( 'texte', 'image-pleine', 'chiffres' ) === array_column( $blocs, 'name' ) && 'image_pleine' === $blocs[1]['key'] );
check( 'sous-champ camelCase d\'un bloc', 'legendeImage' === $blocs[1]['fields'][1]['name'] );
check( 'repeater imbriqué', 'puces' === $by['sections']['fields'][1]['fields'][1]['name'] );
check( 'page_link, clone vide, onglet : non repris', ! isset( $by['page_liee'] ) && ! isset( $by['reprise'] ) && ! isset( $by[''] ) );
check( 'clone affiché en groupe → groupe', 'group' === $by['tarif']['type'] && 'prix' === $by['tarif']['fields'][0]['name'] );

$lost = array();
foreach ( $preview['lost'] as $l ) { $lost[ $l['code'] ] = $l['items']; }
check( 'aperçu : non repris listés', isset( $lost['unsupported'], $lost['clone'] ) && in_array( 'page_liee (page_link)', $lost['unsupported'], true ) && array( 'reprise' ) === $lost['clone'], $lost );
check( 'aperçu : logique conditionnelle, valeurs par défaut, restrictions de médias', array( 'detail' ) === $lost['conditional'] && array( 'badge' ) === $lost['default'] && array( 'visuel' ) === $lost['media_rules'], $lost );
check( 'aperçu : rappels (aller simple, révisions)', isset( $lost['one_way'], $lost['revisions'] ) );
$status = array();
foreach ( $preview['groups'][1]['fields'] as $node ) { $status[ $node['name'] ] = $node['status']; }
check( 'statuts : même, adapté, non repris', 'ok' === $status['prix'] && 'changed' === $status['detail'] && 'dropped' === $status['page_liee'], $status );
check( 'onglet ignoré', 'ignored' === $preview['groups'][0]['fields'][0]['status'] );

$errors = array();
Leiho_Collections_Schema::normalize_collection( $c, '', $errors, array( 'ville', 'pole' ) );
check( 'schéma généré valide', ! $errors, $errors );
$errors = array();
Leiho_Collections_Schema::normalize_collection( array_merge( $c, array( 'source' => '' ) ), '', $errors, array( 'ville', 'pole' ) );
check( 'noms ACF refusés hors import (snake_case exigé)', in_array( 'fields.2.name', array_column( $errors, 'path' ), true ), $errors );
$errors = array();
Leiho_Collections_Schema::normalize_collection( array( 'id' => 'x', 'kind' => 'singleton', 'external' => true, 'fields' => array() ), '', $errors, array() );
check( 'type externe : jamais singleton', in_array( 'kind', array_column( $errors, 'path' ), true ) );

$page = Leiho_ACF_Import::preview( 'page' );
check( 'pages : non importables, raison donnée', ! $page['importable'] && 'builtin' === $page['origin'] && false !== strpos( $page['reason'], 'Pages and posts' ) );
$overview = Leiho_ACF_Import::overview();
check( 'vue d\'ensemble : groupes hors contenu signalés', array( 'Réglages' ) === array_column( $overview['elsewhere'], 'title' ), $overview['elsewhere'] );
check( 'vue d\'ensemble : types', array( 'page', 'ville', 'pole' ) === array_column( $overview['types'], 'post_type' ), array_column( $overview['types'], 'post_type' ) );

$taken = array();
check( 'clés uniques', array( 'hero', 'hero_2', 'f_1x' ) === array( Leiho_ACF_Import::key_for( 'hero', $taken ), Leiho_ACF_Import::key_for( 'Hero', $taken ), Leiho_ACF_Import::key_for( '1x', $taken ) ) );

/* =====================================================================
 * Conversion des valeurs
 * =================================================================== */

$acf = array();
foreach ( $GLOBALS['acf_groups'][1]['fields'] as $field ) { $acf[ $field['name'] ] = $field; }
foreach ( $GLOBALS['acf_groups'][0]['fields'] as $field ) { $acf[ $field['name'] ] = $field; }
$raw = $GLOBALS['acf_values'][10];
check( 'date ACF Ymd → Y-m-d', '2025-01-15' === Leiho_ACF_Import::to_input( $acf['lancement'], '20250115' ) );
check( 'vrai / faux', true === Leiho_ACF_Import::to_input( $acf['vedette'], '1' ) && null === Leiho_ACF_Import::to_input( $acf['vedette'], '0' ) );
check( 'galerie : médias disparus retirés', array( 7, 8 ) === Leiho_ACF_Import::to_input( $acf['galerie'], $raw['field_galerie'] ) );
check( 'relation : contenus existants (brouillons compris)', array( 11, 13, 12 ) === Leiho_ACF_Import::to_input( $acf['voisines'], $raw['field_voisines'] ) );
check( 'post object : un id', 20 === Leiho_ACF_Import::to_input( $acf['pole'], '20' ) );
check( 'nombre', 4.5 === Leiho_ACF_Import::to_input( $acf['note'], '4.5' ) && null === Leiho_ACF_Import::to_input( $acf['prix'], '' ) );
check( 'vide → rien à écrire', null === Leiho_ACF_Import::to_input( $acf['h1'], '' ) && null === Leiho_ACF_Import::to_input( $acf['options'], array() ) && null === Leiho_ACF_Import::to_input( $acf['cta'], array( 'url' => '' ) ) );
$rows = Leiho_ACF_Import::to_input( $acf['sections'], $raw['field_sections'] );
check( 'repeaters imbriqués : lignes par nom, vides omis', array( array( 'label' => 'Section 0', 'cartes' => array( array( 'titre' => 'Carte 1', 'puces' => array( array( 'texte' => 'Puce 1' ), array( 'texte' => 'Puce 2' ) ) ), array( 'titre' => 'Carte 2' ) ) ), array( 'faq' => array( array( 'question' => 'Pourquoi ?', 'reponse' => '<strong>Parce que</strong>' ) ) ) ) === $rows, $rows );
$blocks = Leiho_ACF_Import::to_input( $acf['blocs'], $raw['field_blocs'] );
check( 'flexible : _layout, layout disparu ignoré', array( 'texte', 'image-pleine', 'chiffres' ) === array_column( $blocks, '_layout' ) && 8 === $blocks[1]['image'] && 'Légende' === $blocks[1]['legendeImage'], $blocks );
check( 'groupe', array( 'tel' => '04 72 00 00 00', 'ville' => 11 ) === Leiho_ACF_Import::to_input( $acf['coordonnees'], $raw['field_coord'] ) );
check( 'forme canonique : 5 et 5.0 égaux', Leiho_ACF_Import::canonical( array( 'a' => 5.0 ) ) === Leiho_ACF_Import::canonical( array( 'a' => 5 ) ) );
check( 'DECIMAL entier relu en entier', 5 === Leiho_Collections_Values::number( '5.000000' ) && 89.5 === Leiho_Collections_Values::number( '89.500000' ) );

/* =====================================================================
 * Import : égalité du contenu servi, échecs, écarts
 * =================================================================== */

$before = array();
foreach ( array( 10, 11, 12, 13, 20, 21 ) as $id ) { $before[ $id ] = served( $id ); }
check( 'avant : servi par ACF', 'Ville 10' === $before[10]['h1'] && 'Section 0' === $before[10]['sections'][0]['label'] );

$record = Leiho_ACF_Import::start( 'ville' );
check( 'démarrage : collection créée, import en cours', ! is_wp_error( $record ) && 'running' === $record['status'] && Leiho_Collections::get( 'ville' ) && 4 === $record['total'], $record );
check( 'pendant l\'import : ACF sert toujours', 'acf' === Leiho_ACF_Import::engine( 'ville' ) && ! Leiho_ACF_Import::native_serves( 'ville' ) && json( $before[10] ) === json( served( 10 ) ) );
check( 'type déjà déclaré : accepté (collection externe)', post_type_exists( 'ville' ) && Leiho_Collections::get( 'ville' )['external'] );
check( 'type externe : pas ajouté aux types servis', ! in_array( 'ville', Leiho_Plugin::settings()['post_types'], true ) || in_array( 'ville', array( 'page', 'ville', 'pole' ), true ) );

$GLOBALS['revisions'] = 0;
$GLOBALS['actions']   = array();
$steps = 0;
while ( ! Leiho_ACF_Import::finished( $record ) && $steps < 10 ) {
	$record = Leiho_ACF_Import::step( 'ville', 1 );
	$steps++;
}
check( 'import par lots (reprise au curseur)', Leiho_ACF_Import::finished( $record ) && 4 === $record['done'] && $steps >= 4, $record );
check( 'pas de révision par entrée importée', 0 === $GLOBALS['revisions'], $GLOBALS['revisions'] );
check( 'échec signalé sans arrêter l\'import', 1 === $record['failed'] && 12 === $record['failures'][0]['post_id'] && 'niveau' === $record['failures'][0]['field'] && 'invalid_choice' === $record['failures'][0]['code'] && 3 === $record['imported'], $record['failures'] );
check( 'écart signalé (texte réécrit par la validation)', 1 === $record['different'] && 13 === $record['diffs'][0]['post_id'] && 'h1' === $record['diffs'][0]['field'], $record['diffs'] );

// Échec ou écart : pas de bascule, l'import attend d'être examiné.
check( 'échec / écart : état « review », pas « done »', 'review' === $record['status'] && $record['finished'] > 0, $record['status'] );
check( 'review : ACF sert toujours le type', 'acf' === Leiho_ACF_Import::engine( 'ville' ) && ! Leiho_ACF_Import::native_serves( 'ville' ) && ! Leiho_ACF_Adapter::retired( 'ville' ) && json( $before[10] ) === json( served( 10 ) ) && json( $before[12] ) === json( served( 12 ) ) );
check( 'review : groupes ACF toujours affichés', true === Leiho_ACF_Import::rule_match( true, array(), array( 'post_id' => 10 ), array() ) );
check( 'review : bascule non annoncée', ! in_array( 'leiho_acf_imported', $GLOBALS['actions'], true ) && in_array( 'leiho_acf_import_needs_review', $GLOBALS['actions'], true ) );
$again = Leiho_ACF_Import::step( 'ville', 10 );
check( 'review : un lot de plus ne relance rien (boucle CLI / admin terminée)', 'review' === $again['status'] && 4 === $again['done'] && 1 === $again['failed'] );
$summary = Leiho_ACF_Import::summary( 'ville' );
check( 'review : résumé (à examiner, bascule possible)', 'review' === $summary['status'] && $summary['needs_review'] && $summary['can_accept'] && 1 === $summary['failed'] && 1 === $summary['differences'], $summary );
$p = Leiho_ACF_Import::preview( 'ville' );
check( 'review : aperçu non importable, raison donnée', ! $p['importable'] && false !== strpos( $p['reason'], 'failures or differences' ) && 'review' === $p['status'], $p['reason'] );
$o = array_column( Leiho_ACF_Import::overview()['types'], null, 'post_type' );
check( 'review : vue d\'ensemble', 'review' === $o['ville']['status'] && ! $o['ville']['importable'] );
check( 'accepter : refusé sans import, ou pendant l\'import', is_wp_error( Leiho_ACF_Import::accept( 'pole' ) ) );

// Décision : basculer quand même.
$accepted = Leiho_ACF_Import::accept( 'ville' );
check( 'accepter : bascule vers Leiho, rapport gardé', ! is_wp_error( $accepted ) && 'done' === $accepted['status'] && $accepted['accepted'] > 0 && 1 === count( $accepted['failures'] ) && in_array( 'leiho_acf_imported', $GLOBALS['actions'], true ), $accepted );
check( 'accepter : une seule fois', is_wp_error( Leiho_ACF_Import::accept( 'ville' ) ) );
check( 'bascule : Leiho sert le type, ACF ne le sert plus', 'leiho' === Leiho_ACF_Import::engine( 'ville' ) && Leiho_ACF_Adapter::retired( 'ville' ) && array() === ( new Leiho_ACF_Adapter() )->get_content( 10 ) );
check( 'groupes ACF masqués pour ce type', false === Leiho_ACF_Import::rule_match( true, array(), array( 'post_id' => 10 ), array() ) && true === Leiho_ACF_Import::rule_match( true, array(), array( 'post_type' => 'pole' ), array() ) );

$after = array();
foreach ( array( 10, 11, 12, 13 ) as $id ) { $after[ $id ] = served( $id ); }
$dropped = array( 'page_liee', 'reprise' );
foreach ( array( 10, 11 ) as $id ) {
	$expected = $before[ $id ];
	foreach ( $dropped as $name ) { unset( $expected[ $name ] ); }
	$diff = array();
	foreach ( $expected as $name => $value ) {
		if ( ! array_key_exists( $name, $after[ $id ] ) || json( $value ) !== json( $after[ $id ][ $name ] ) ) {
			$diff[] = $name;
		}
	}
	check( "entrée $id : même JSON champ par champ (" . count( $expected ) . ' champs)', ! $diff && array_keys( $expected ) === array_keys( $after[ $id ] ), $diff );
	check( "entrée $id : même JSON d'ensemble", json( $expected ) === json( $after[ $id ] ) );
}
check( 'entrée 12 : tout sauf le champ en échec', json( array_diff_key( $before[12], array_flip( array_merge( $dropped, array( 'niveau' ) ) ) ) ) === json( array_diff_key( $after[12], array( 'niveau' => 1 ) ) ) && '' === $after[12]['niveau'] );
check( 'nombres : entier et décimal', 1200 === $after[10]['prix'] && 4.5 === $after[10]['note'] && 89.5 === $after[11]['prix'] );
check( 'image servie en objet média', 7 === $after[10]['visuel']['id'] && 'Alt 7' === $after[10]['visuel']['alt'] );
check( 'relations servies en références (brouillon exclu)', array( 11, 12 ) === array_column( $after[10]['voisines'], 'id' ) && '/poles/securite' === $after[10]['pole']['path'] );
check( 'blocs : _layout en tête', 'image-pleine' === $after[10]['blocs'][1]['_layout'] && 'Légende' === $after[10]['blocs'][1]['legendeImage'] && 2.5 === $after[10]['blocs'][2]['items'][1]['valeur'] );
check( 'clone en groupe servi à l\'identique', array( 'prix' => 7 ) === $after[10]['tarif'] && array( 'prix' => null ) === $after[11]['tarif'] );
check( 'date, booléen, choix multiples', '2025-01-15' === $after[10]['lancement'] && true === $after[10]['vedette'] && false === $after[11]['vedette'] && array( 'x', 'y' ) === $after[10]['options'] );

// Types TypeScript identiques pour un groupe sans condition d'emplacement.
$ts_after  = Leiho_Types::render( array( 'ville' => ( new Leiho_Native_Adapter() )->get_definitions( 'ville' ) ) );
Leiho_ACF_Import::flush();
$saved = $GLOBALS['options'][ Leiho_ACF_Import::OPTION ];
unset( $GLOBALS['options'][ Leiho_ACF_Import::OPTION ] );
$acf_defs = ( new Leiho_ACF_Adapter() )->get_definitions( 'ville' );
foreach ( $acf_defs as &$group ) {
	$group['fields'] = array_values( array_filter( $group['fields'], function ( $f ) { return ! in_array( $f['name'], array( 'page_liee', 'reprise' ), true ); } ) );
}
unset( $group );
$ts_before = Leiho_Types::render( array( 'ville' => $acf_defs ) );
$GLOBALS['options'][ Leiho_ACF_Import::OPTION ] = $saved;
Leiho_ACF_Import::flush();
check( '/types : déclarations identiques', $ts_before === $ts_after, array( $ts_before, $ts_after ) );

/* =====================================================================
 * Éditeur : chemins pointés après import
 * =================================================================== */

$saved = Leiho_Collections::save_entry( 10, array( 'sections.0.cartes.1.titre' => 'Carte éditée', 'hero_stats.1.label' => 'Techniciens' ) );
check( 'chemins pointés : écriture', array( 'sections.0.cartes.1.titre', 'hero_stats.1.label' ) === $saved['saved'] && ! $saved['errors'], $saved );
$now = served( 10 );
check( 'chemins pointés : relus', 'Carte éditée' === $now['sections'][0]['cartes'][1]['titre'] && 'Techniciens' === $now['hero_stats'][1]['label'] && 'Puce 2' === $now['sections'][0]['cartes'][0]['puces'][1]['texte'] );
check( 'ACF intact', 'Carte 2' === $GLOBALS['acf_values'][10]['field_sections'][0]['field_s_cartes'][1]['field_c_titre'] );
$schema = ( new Leiho_Native_Adapter() )->get_schema( 10 );
check( 'éditeur : un groupe Leiho, aucun groupe ACF', 1 === count( $schema ) && 'collections' === $schema[0]['source'] && array() === ( new Leiho_ACF_Adapter() )->get_schema( 10 ) );

/* =====================================================================
 * Retour à ACF
 * =================================================================== */

$deleted = Leiho_Collections::delete_collection( 'ville' );
check( 'collection retirée du schéma : ACF reprend la main', 'acf' === Leiho_ACF_Import::engine( 'ville' ) && json( array_diff_key( $before[11], array() ) ) === json( served( 11 ) ) );
Leiho_Collections::put_collection( $preview['collection'] );
check( 'collection remise : Leiho sert à nouveau', 'leiho' === Leiho_ACF_Import::engine( 'ville' ) );

$result = Leiho_ACF_Import::rollback( 'ville' );
check( 'retour à ACF', ! is_wp_error( $result ) && $result['dropped'] && null === Leiho_ACF_Import::engine( 'ville' ) && null === Leiho_Collections::get( 'ville' ), $result );
$tables = $GLOBALS['wpdb']->get_col( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'wp_leiho_ville'" );
check( 'table Leiho supprimée, état effacé', ! $tables && null === Leiho_Collections_Store::collection_state( 'ville' ) );
foreach ( array( 10, 11, 12, 13 ) as $id ) {
	check( "entrée $id : valeurs ACF d'origine servies à nouveau", json( $before[ $id ] ) === json( served( $id ) ) );
}
check( 'retour impossible sans import', is_wp_error( Leiho_ACF_Import::rollback( 'ville' ) ) );

// Réimport après retour : rien ne reste du précédent.
$record = Leiho_ACF_Import::start( 'ville' );
while ( ! is_wp_error( $record ) && ! Leiho_ACF_Import::finished( $record ) ) { $record = Leiho_ACF_Import::step( 'ville', 10 ); }
check( 'réimport : de nouveau à examiner', 'review' === $record['status'] && 'acf' === Leiho_ACF_Import::engine( 'ville' ) );
Leiho_ACF_Import::accept( 'ville' );
check( 'réimport : modifications Leiho précédentes oubliées', 'Carte 2' === served( 10 )['sections'][0]['cartes'][1]['titre'] );
Leiho_ACF_Import::rollback( 'ville' );

// Retour à ACF directement depuis « review » (sans bascule).
$record = Leiho_ACF_Import::start( 'ville' );
while ( ! is_wp_error( $record ) && ! Leiho_ACF_Import::finished( $record ) ) { $record = Leiho_ACF_Import::step( 'ville', 10 ); }
$result = Leiho_ACF_Import::rollback( 'ville' );
check( 'retour à ACF depuis « review »', 'review' === $record['status'] && ! is_wp_error( $result ) && $result['dropped'] && null === Leiho_ACF_Import::record( 'ville' ) && null === Leiho_Collections::get( 'ville' ) && json( $before[10] ) === json( served( 10 ) ) );

/* =====================================================================
 * Valeurs hors limites : relevées par l'aperçu, limites élargies
 * =================================================================== */

// Pôle 21 : h1 de 80 caractères pour un maxlength de 60 (ACF ne le vérifie
// que dans son écran). Sans élargissement, l'import le refuserait.
$preview = Leiho_ACF_Import::preview( 'pole' );
$limits  = array_column( $preview['limits'], null, 'path' );
check( 'aperçu : valeur hors limite relevée', isset( $limits['h1'] ) && 'max_length' === $limits['h1']['rule'] && 60 === $limits['h1']['limit'] && 80 === $limits['h1']['found'] && array( 21 ) === $limits['h1']['entries'] && 1 === $preview['counts']['limits'] && 2 === $preview['counts']['scanned'], $preview['limits'] );
$by = array_column( $preview['collection']['fields'], null, 'name' );
check( 'aperçu : limite élargie dans le schéma généré', 80 === $by['h1']['max_length'] && $preview['importable'], $by['h1'] );
$lost = array_column( $preview['lost'], 'items', 'code' );
check( 'aperçu : élargissement annoncé', isset( $lost['limits'] ) && false !== strpos( $lost['limits'][0], 'h1: max_length 60 → 80 (#21)' ), $lost );
$before = array( 20 => served( 20 ), 21 => served( 21 ) );
$record = Leiho_ACF_Import::start( 'pole' );
while ( ! is_wp_error( $record ) && ! Leiho_ACF_Import::finished( $record ) ) { $record = Leiho_ACF_Import::step( 'pole', 10 ); }
check( 'import sans échec ni écart : basculé directement', 'done' === $record['status'] && 0 === $record['failed'] && 0 === $record['different'] && 2 === $record['imported'] && 'leiho' === Leiho_ACF_Import::engine( 'pole' ), $record );
check( 'valeur hors limite importée entière', str_repeat( 'x', 80 ) === served( 21 )['h1'] && json( $before[20] ) === json( served( 20 ) ) );
check( 'limites gardées dans le rapport', 'h1' === $record['limits'][0]['path'] );
Leiho_ACF_Import::rollback( 'pole' );

// Pure : listes, blocs, galerie, nombre.
$fields = array(
	array( 'name' => 'rows', 'type' => 'list', 'min' => 2, 'max' => 3, 'fields' => array( array( 'name' => 't', 'type' => 'text', 'max_length' => 5 ) ) ),
	array( 'name' => 'b', 'type' => 'blocks', 'min' => 0, 'max' => 0, 'blocks' => array( array( 'name' => 'x', 'min' => 1, 'max' => 1, 'fields' => array() ), array( 'name' => 'y', 'min' => 0, 'max' => 0, 'fields' => array( array( 'name' => 'n', 'type' => 'number', 'min' => 0, 'max' => 10 ) ) ) ) ),
	array( 'name' => 'g', 'type' => 'gallery', 'max' => 1 ),
);
$found = array();
Leiho_ACF_Import::scan_limits( $fields, array( 'rows' => array( array( 't' => 'abcdefgh' ) ), 'b' => array( array( '_layout' => 'y', 'n' => 12 ), array( '_layout' => 'y', 'n' => -1 ) ), 'g' => array( 7, 8 ) ), 1, $found );
Leiho_ACF_Import::scan_limits( $fields, array( 'rows' => array( 1, 2, 3, 4, 5 ), 'b' => array( array( '_layout' => 'x' ), array( '_layout' => 'x' ) ) ), 2, $found );
check( 'limites : lignes, longueur, blocs, nombre, galerie', array( 'rows|min', 'rows/t|max_length', 'b/y/n|max', 'b/y/n|min', 'b/x|min', 'g|max', 'rows|max', 'b/x|max' ) === array_keys( $found ), array_keys( $found ) );
check( 'limites : valeurs extrêmes et entrées', 1 === $found['rows|min']['found'] && 5 === $found['rows|max']['found'] && 8 === $found['rows/t|max_length']['found'] && 12 === $found['b/y/n|max']['found'] && -1 === $found['b/y/n|min']['found'] && 0 === $found['b/x|min']['found'] && array( 1 ) === $found['b/x|min']['entries'] && 2 === $found['b/x|max']['found'] );
Leiho_ACF_Import::relax_limits( $fields, $found );
check( 'limites élargies à l\'existant', 1 === $fields[0]['min'] && 5 === $fields[0]['max'] && 8 === $fields[0]['fields'][0]['max_length'] && 0 === $fields[1]['blocks'][0]['min'] && 2 === $fields[1]['blocks'][0]['max'] && 12 === $fields[1]['blocks'][1]['fields'][0]['max'] && -1 === $fields[1]['blocks'][1]['fields'][0]['min'] && 2 === $fields[2]['max'], $fields );

/* =====================================================================
 * Groupes rattachés à une entrée : un site façon Dyese (type service)
 * =================================================================== */

foreach ( array( 104, 105, 106 ) as $id ) {
	post( $id, 'service' );
}
$contenu_location = $GLOBALS['acf_groups'][0]['location'];
// « Contenu de page » : villes, pôles… et le service 104 nommément.
$GLOBALS['acf_groups'][0]['location'][] = array( array( 'param' => 'post', 'operator' => '==', 'value' => '104' ) );
$GLOBALS['acf_groups'][] = array(
	'key'      => 'group_prestation',
	'title'    => 'Prestation',
	'active'   => true,
	'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'service' ), array( 'param' => 'post', 'operator' => '!=', 'value' => '104' ) ) ),
	'fields'   => array(
		f( 'field_pr_hero', 'hero', 'text' ),
		f( 'field_pr_sections', 'sections', 'flexible_content', array( 'layouts' => array(
			array( 'key' => 'layout_colonnes', 'name' => 'colonnes', 'label' => 'Colonnes', 'sub_fields' => array(
				f( 'field_pr_blocs', 'blocs', 'repeater', array( 'max' => 3, 'sub_fields' => array( f( 'field_pr_texte', 'texte', 'text' ) ) ) ),
			) ),
		) ) ),
		f( 'field_pr_cta', 'cta', 'link' ),
	),
);
$GLOBALS['acf_groups'][] = array(
	'key'      => 'group_service',
	'title'    => 'Service — contenu',
	'active'   => true,
	'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'service' ) ) ),
	'fields'   => array( f( 'field_sv_extrait', 'extrait', 'text' ), f( 'field_sv_icone', 'icone', 'text' ) ),
);
$GLOBALS['acf_groups'][] = array(
	'key'      => 'group_sauf',
	'title'    => 'Sauf 104',
	'active'   => true,
	'location' => array( array( array( 'param' => 'post', 'operator' => '!=', 'value' => '104' ) ) ),
	'fields'   => array( f( 'field_sauf', 'sauf', 'text' ) ),
);
function blocs( $n ) {
	$rows = array();
	for ( $i = 1; $i <= $n; $i++ ) { $rows[] = array( 'field_pr_texte' => 'Bloc ' . $i ); }
	return array( array( 'acf_fc_layout' => 'colonnes', 'field_pr_blocs' => $rows ) );
}
$GLOBALS['acf_values'][104] = array( 'field_h1' => 'Service 104', 'field_hero' => 'Accroche', 'field_sections' => array( array( 'field_s_label' => 'Section' ) ), 'field_sv_extrait' => 'Extrait 104' );
$GLOBALS['acf_values'][105] = array( 'field_pr_hero' => 'Hero 105', 'field_pr_sections' => blocs( 2 ), 'field_sv_extrait' => 'Extrait 105' );
$GLOBALS['acf_values'][106] = array( 'field_pr_hero' => 'Hero 106', 'field_pr_sections' => blocs( 5 ), 'field_sv_extrait' => 'Extrait 106' ); // 5 lignes pour un max de 3.

check( 'règles : post == 104 rattache le groupe à l\'entrée 104', array( 104 ) === Leiho_ACF_Import::entry_ids( $GLOBALS['acf_groups'][0]['location'], 'service' ) && array() === Leiho_ACF_Import::entry_ids( $GLOBALS['acf_groups'][0]['location'], 'ville' ) );
check( 'règles : post != 104 ne rattache rien', array() === Leiho_ACF_Import::entry_ids( $GLOBALS['acf_groups'][4]['location'], 'service' ) && array() === Leiho_ACF_Import::entry_ids( $GLOBALS['acf_groups'][3]['location'], 'service' ) );
check( 'règles : post == 104 ET post_type == autre type → rien', array() === Leiho_ACF_Import::entry_ids( array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'ville' ), array( 'param' => 'post', 'operator' => '==', 'value' => '104' ) ) ), 'service' ) );
check( 'règles : post == 104 ET page d\'options → rien', array() === Leiho_ACF_Import::entry_ids( array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'x' ), array( 'param' => 'post', 'operator' => '==', 'value' => '104' ) ) ), 'service' ) );

$groups = Leiho_ACF_Import::groups_for( 'service' );
check( 'groupes du type : groupe rattaché à une entrée compris', array( 'Contenu de page', 'Prestation', 'Service — contenu' ) === array_column( $groups, 'title' ) && array( 104 ) === $groups[0]['entries'] && $groups[0]['conditional'] && array() === $groups[1]['entries'], array_column( $groups, 'title' ) );
$preview = Leiho_ACF_Import::preview( 'service' );
check( 'aperçu : groupe rattaché à l\'entrée listé', array( 104 ) === $preview['groups'][0]['entries'] && 'Contenu de page' === $preview['groups'][0]['title'] );
$lost = array_column( $preview['lost'], 'items', 'code' );
check( 'aperçu : groupes par entrée signalés', isset( $lost['entry_group'] ) && array( 'Contenu de page (#104)' ) === $lost['entry_group'], $lost );
check( 'aperçu : même nom, formes différentes selon l\'entrée → conflit', array( 'sections' ) === $preview['conflicts'] && isset( $lost['conflict'] ) && 'sections (Contenu de page: list, Prestation: blocks)' === $lost['conflict'][0], array( $preview['conflicts'], $lost ) );
check( 'aperçu : conflit → non importable, raison donnée', ! $preview['importable'] && false !== strpos( $preview['reason'], 'sections' ), $preview['reason'] );
check( 'démarrage refusé', is_wp_error( Leiho_ACF_Import::start( 'service' ) ) && null === Leiho_ACF_Import::record( 'service' ) );
$o = array_column( Leiho_ACF_Import::overview()['types'], null, 'post_type' );
check( 'vue d\'ensemble : service listé avec ses trois groupes', isset( $o['service'] ) && 3 === $o['service']['groups'], isset( $o['service'] ) ? $o['service'] : null );

// Même nom, même forme, groupe rattaché à une entrée : doublon, pas conflit.
$GLOBALS['acf_groups'][] = array(
	'key'      => 'group_seo',
	'title'    => 'SEO 105',
	'active'   => true,
	'location' => array( array( array( 'param' => 'post', 'operator' => '==', 'value' => '105' ) ) ),
	'fields'   => array( f( 'field_seo_extrait', 'extrait', 'text' ) ),
);
$built = Leiho_ACF_Import::build( 'service', Leiho_ACF_Import::groups_for( 'service' ) );
check( 'même forme selon l\'entrée : doublon signalé, pas de conflit', array( 'sections' ) === $built['conflicts'] && in_array( 'extrait', $built['lost']['duplicate'], true ), $built['lost'] );
array_pop( $GLOBALS['acf_groups'] );

// Conflit levé (groupe rendu aux villes et pôles) : import, lignes au-delà du max gardées.
$GLOBALS['acf_groups'][0]['location'] = $contenu_location;
$preview = Leiho_ACF_Import::preview( 'service' );
$limits  = array_column( $preview['limits'], null, 'path' );
check( 'sans conflit : importable', $preview['importable'] && array() === $preview['conflicts'], array( $preview['reason'], $preview['errors'] ) );
check( 'aperçu : lignes au-delà du max relevées (sous-champ de bloc)', isset( $limits['sections/colonnes/blocs'] ) && 3 === $limits['sections/colonnes/blocs']['limit'] && 5 === $limits['sections/colonnes/blocs']['found'] && array( 106 ) === $limits['sections/colonnes/blocs']['entries'], $preview['limits'] );
$by = array_column( $preview['collection']['fields'], null, 'name' );
check( 'aperçu : max relevé à 5 dans le schéma généré', 5 === $by['sections']['blocks'][0]['fields'][0]['max'] );
$before = served( 106 );
$record = Leiho_ACF_Import::start( 'service' );
while ( ! is_wp_error( $record ) && ! Leiho_ACF_Import::finished( $record ) ) { $record = Leiho_ACF_Import::step( 'service', 10 ); }
check( 'import : aucune ligne refusée, basculé', 'done' === $record['status'] && 0 === $record['failed'] && 0 === $record['different'] && 'leiho' === Leiho_ACF_Import::engine( 'service' ), $record );
check( 'entrée 106 : cinq lignes servies, même JSON', 5 === count( served( 106 )['sections'][0]['blocs'] ) && json( $before['sections'] ) === json( served( 106 )['sections'] ) );
Leiho_ACF_Import::rollback( 'service' );

/* =====================================================================
 * Type sans ACF déclaré par ACF : enregistrement repris
 * =================================================================== */

$GLOBALS['registered'] = array();
function register_post_type( $t, $a ) { $GLOBALS['registered'][ $t ] = $a; }
update_option( Leiho_ACF_Import::OPTION, array( 'atelier' => array( 'status' => 'done', 'origin' => 'acf', 'args' => array( 'public' => true, 'rewrite' => array( 'slug' => 'ateliers' ) ) ) ) );
Leiho_ACF_Import::flush();
Leiho_ACF_Import::register_post_types();
check( 'type déclaré dans ACF : Leiho l\'enregistre si ACF ne le fait plus', isset( $GLOBALS['registered']['atelier'] ) && 'ateliers' === $GLOBALS['registered']['atelier']['rewrite']['slug'] );
$GLOBALS['registered'] = array();
update_option( Leiho_ACF_Import::OPTION, array( 'ville' => array( 'status' => 'done', 'origin' => 'acf', 'args' => array( 'public' => true ) ) ) );
Leiho_ACF_Import::flush();
Leiho_ACF_Import::register_post_types();
check( 'type encore déclaré (ACF actif) : pas de double enregistrement', ! $GLOBALS['registered'] );

/* =====================================================================
 * robots.txt d'un CMS headless : médias explorables
 * =================================================================== */

require __DIR__ . '/../includes/class-leiho-headless.php';
check( 'robots.txt : tout fermé sauf les médias', "User-agent: *\nAllow: /wp-content/uploads/\nDisallow: /\n" === Leiho_Headless::robots_txt(), Leiho_Headless::robots_txt() );
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create = true ) { return array( 'baseurl' => 'https://cms.test/wp/wp-content/uploads' ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
check( 'robots.txt : dossier des médias du site', false !== strpos( Leiho_Headless::robots_txt(), "Allow: /wp/wp-content/uploads/\n" ), Leiho_Headless::robots_txt() );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
