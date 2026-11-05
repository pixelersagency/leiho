<?php
/**
 * Leiho Collections sans WordPress : php plugin/tests/test-collections.php
 *
 * Schéma, plan de migration, conversions, adapter natif (lecture, chemins
 * pointés, sanitization, contenu, types). Les migrations et les lignes
 * tournent sur une vraie base SQLite en mémoire (PDO) derrière un $wpdb
 * minimal : le SQL émis est exécuté, pas seulement comparé à des chaînes.
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
$GLOBALS['options']    = array();
$GLOBALS['post_types'] = array(); // post_id => post type.
$GLOBALS['statuses']   = array(); // post_id => statut.
$GLOBALS['revisions']  = 0;

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
function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag, ...$a ) { $GLOBALS['actions'][] = array( $tag, $a ); }
function wp_is_post_revision( $p ) { return false; }
function wp_is_post_autosave( $p ) { return false; }
function wp_using_ext_object_cache() { return false; }
function wp_cache_get( $k, $g = '', $f = false, &$found = null ) { $found = isset( $GLOBALS['cache'][ $g ][ $k ] ); return $found ? $GLOBALS['cache'][ $g ][ $k ] : false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_add( $k, $v, $g = '', $t = 0 ) { return isset( $GLOBALS['cache'][ $g ][ $k ] ) ? false : wp_cache_set( $k, $v, $g ); }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache'][ $g ][ $k ] ); return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function did_action( $h ) { return 0; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t, $gmt = 0 ) { return gmdate( "Y-m-d H:i:s" ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : ''; }
function wp_kses_post( $v ) { return preg_replace( '#<script.*?</script>#is', '', (string) $v ); }
function esc_url_raw( $v ) { return (string) $v; }
function wpautop( $v ) { return '<p>' . $v . '</p>'; }
function absint( $v ) { return abs( (int) $v ); }
function wp_list_pluck( $l, $f ) { return array_column( $l, $f ); }
function current_user_can( $cap, $id = 0 ) { return true; }
function get_post_type( $id ) { return isset( $GLOBALS['post_types'][ (int) $id ] ) ? $GLOBALS['post_types'][ (int) $id ] : false; }
function get_post( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['post_types'][ $id ] ) ) { return null; }
	return (object) array( 'ID' => $id, 'post_type' => $GLOBALS['post_types'][ $id ], 'post_status' => isset( $GLOBALS['statuses'][ $id ] ) ? $GLOBALS['statuses'][ $id ] : 'publish', 'post_name' => 'slug-' . $id, 'post_title' => 'Titre ' . $id, 'post_parent' => 0 );
}
function get_the_title( $p ) { return 'Titre ' . ( is_object( $p ) ? $p->ID : $p ); }
function get_post_meta( $id, $key = '', $single = false ) { return ''; }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function wp_get_attachment_url( $id ) { return 'https://cdn/' . $id . '.jpg'; }
function wp_get_attachment_image_url( $id, $s ) { return 'https://cdn/' . $id . '-' . $s . '.jpg'; }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 800, 'height' => 600 ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function get_posts( $a ) { return array(); }
function get_terms( $a ) { return array(); }
function get_term( $id, $t = '' ) { return null; }
function post_type_supports( $t, $f ) { return 'revisions' === $f; }
function wp_save_post_revision( $id ) { $GLOBALS['revisions']++; }
function wp_insert_post( $a, $e = false ) { $GLOBALS['post_types'][1] = $a['post_type']; $GLOBALS['inserted'][] = $a; return 1; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }

class Leiho_Plugin {
	public static function title( $p ) { return get_the_title( $p ); }
	public static function settings() {
		$settings = array( 'front_url' => '', 'url_patterns' => array( 'service' => '/services/{slug}' ), 'path_meta_key' => '', 'post_types' => array() );
		// Le vrai Leiho_Plugin::settings() passe par le filtre leiho_settings.
		return class_exists( 'Leiho_Collections' ) ? Leiho_Collections::settings( $settings ) : $settings;
	}
}

/**
 * $wpdb minimal sur SQLite en mémoire (PDO). Le SQL de Leiho est du MySQL
 * portable : SQLite l'accepte tel quel (backticks, types, CREATE INDEX).
 */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	private $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
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
		// Différences de dialecte : entiers non signés, DROP INDEX … ON.
		$sql              = str_replace( ' UNSIGNED', '', $sql );
		$sql              = preg_replace( '/^DROP INDEX (`\w+`) ON `\w+`$/', 'DROP INDEX $1', $sql );
		$this->queries[]  = $sql;
		$this->last_error = '';
		$stmt             = $this->pdo->query( $sql );
		if ( false === $stmt ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			return false;
		}
		return $stmt;
	}
	public function query( $sql ) {
		$stmt = $this->run( $sql );
		return false === $stmt ? false : $stmt->rowCount();
	}
	public function get_results( $sql, $o = null ) {
		$stmt = $this->run( $sql );
		return false === $stmt ? array() : $stmt->fetchAll( PDO::FETCH_ASSOC );
	}
	public function get_row( $sql, $o = null ) {
		$rows = $this->get_results( $sql );
		return $rows ? $rows[0] : null;
	}
	public function get_var( $sql ) {
		$row = $this->get_row( $sql );
		return $row ? reset( $row ) : null;
	}
	private function values( $data ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : $this->pdo->quote( (string) $v ) );
		}
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
require __DIR__ . '/../includes/class-leiho-url-mapper.php';
require __DIR__ . '/../includes/class-leiho-content.php';
require __DIR__ . '/../includes/class-leiho-cache.php';
require __DIR__ . '/../includes/class-leiho-types.php';
require __DIR__ . '/../includes/class-leiho-seo.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}
function first_diff( $a, $b, $path = '' ) {
	if ( is_array( $a ) && is_array( $b ) ) {
		if ( array_keys( $a ) !== array_keys( $b ) ) { return $path . ' keys: ' . implode( ',', array_keys( $a ) ) . ' / ' . implode( ',', array_keys( $b ) ); }
		foreach ( $a as $k => $v ) { $d = first_diff( $v, $b[ $k ], $path . '.' . $k ); if ( $d ) { return $d; } }
		return '';
	}
	return $a === $b ? '' : $path . ': ' . var_export( $a, true ) . ' / ' . var_export( $b, true );
}
function paths( $errors ) { return array_column( $errors, 'path' ); }
function sql_columns( $table ) {
	$cols = array();
	foreach ( $GLOBALS['wpdb']->get_results( "PRAGMA table_info(`$table`)" ) as $c ) { $cols[ $c['name'] ] = strtoupper( $c['type'] ); }
	return $cols;
}

/* =====================================================================
 * Validation
 * =================================================================== */

$errors = array();
Leiho_Collections_Schema::normalize(
	array(
		'collections' => array(
			array( 'id' => 'Page' ),
			array( 'id' => 'page' ),
			array( 'id' => 'ok', 'kind' => 'many', 'fields' => array(
				array( 'name' => 'Title', 'type' => 'text' ),
				array( 'name' => 'a', 'type' => 'text' ),
				array( 'name' => 'b', 'key' => 'a', 'type' => 'text' ),
				array( 'name' => 'c', 'type' => 'wysiwyg' ),
				array( 'name' => 'd', 'type' => 'select' ),
				array( 'name' => 'e', 'type' => 'reference' ),
				array( 'name' => 'f', 'type' => 'list', 'fields' => array() ),
				array( 'name' => 'g', 'type' => 'blocks', 'blocks' => array( array( 'name' => 'x' ), array( 'name' => 'x' ) ) ),
			) ),
			array( 'id' => 'ok' ),
		),
	),
	$errors
);
$p = paths( $errors );
check( 'id invalide', in_array( 'collections.0.id', $p, true ), $p );
check( 'id réservé', in_array( 'collections.1.id', $p, true ) );
check( 'kind invalide', in_array( 'collections.2.kind', $p, true ) );
check( 'nom pas en snake_case', in_array( 'collections.2.fields.0.name', $p, true ) );
check( 'clé en double', in_array( 'collections.2.fields.2.key', $p, true ), $p );
check( 'type inconnu', in_array( 'collections.2.fields.3.type', $p, true ) );
check( 'select sans choix', in_array( 'collections.2.fields.4.choices', $p, true ) );
check( 'référence sans cible', in_array( 'collections.2.fields.5.to', $p, true ) );
check( 'liste vide', in_array( 'collections.2.fields.6.fields', $p, true ) );
check( 'bloc en double', in_array( 'collections.2.fields.7.blocks.1', $p, true ), $p );
check( 'collection en double', in_array( 'collections.3.id', $p, true ) );

/* --- Schéma de démonstration --- */
$home = array(
	'id'     => 'home',
	'kind'   => 'singleton',
	'label'  => 'Home',
	'path'   => '/',
	'fields' => array(
		array( 'name' => 'hero_title', 'label' => 'Hero title', 'type' => 'text', 'required' => true, 'max_length' => 40 ),
		array( 'name' => 'sections', 'type' => 'blocks', 'blocks' => array(
			array( 'name' => 'hero', 'fields' => array(
				array( 'name' => 'image', 'type' => 'image' ),
				array( 'name' => 'body', 'type' => 'richtext' ),
			) ),
			array( 'name' => 'cards', 'max' => 2, 'fields' => array(
				array( 'name' => 'items', 'type' => 'list', 'max' => 3, 'fields' => array(
					array( 'name' => 'title', 'type' => 'text', 'required' => true ),
					array( 'name' => 'service', 'type' => 'reference', 'to' => array( 'service' ) ),
				) ),
			) ),
		) ),
		array( 'name' => 'contact', 'type' => 'group', 'fields' => array(
			array( 'name' => 'email', 'type' => 'email' ),
			array( 'name' => 'cta', 'type' => 'link' ),
		) ),
	),
);
$service = array(
	'id'             => 'service',
	'label'          => 'Services',
	'singular_label' => 'Service',
	'path'           => '/services/{slug}',
	'supports'       => array( 'thumbnail' ),
	'fields'         => array(
		array( 'name' => 'price', 'type' => 'text', 'max_length' => 100 ),
		array( 'name' => 'featured', 'type' => 'boolean' ),
		array( 'name' => 'tone', 'type' => 'select', 'choices' => array( 'light' => 'Light', 'dark' => 'Dark' ) ),
		array( 'name' => 'tags', 'type' => 'select', 'multiple' => true, 'choices' => array( 'a', 'b' ) ),
		array( 'name' => 'starts', 'type' => 'date' ),
		array( 'name' => 'code', 'type' => 'slug' ),
		array( 'name' => 'related', 'type' => 'reference', 'to' => array( 'service' ) ),
		array( 'name' => 'gallery', 'type' => 'gallery', 'max' => 2 ),
		array( 'name' => 'count', 'type' => 'number', 'integer' => true, 'min' => 0, 'max' => 100 ),
	),
);

$errors = array();
$schema = Leiho_Collections_Schema::normalize( array( 'collections' => array( $home, $service ) ), $errors );
check( 'schéma valide', array() === $errors, $errors );
check( 'clé = nom par défaut', 'hero_title' === $schema['collections'][0]['fields'][0]['key'] );
check( 'choix objet → liste', array( 'value' => 'dark', 'label' => 'Dark' ) === $schema['collections'][1]['fields'][2]['choices'][1] );
check( 'choix liste de chaînes', 'b' === $schema['collections'][1]['fields'][3]['choices'][1]['value'] );
check( 'slug plafonné à 191', 191 === $schema['collections'][1]['fields'][5]['max_length'] );

$again = array();
$reread = Leiho_Collections_Schema::normalize( Leiho_Collections_Schema::compact( $schema ), $again );
check( 'export relu à l’identique', $schema === $reread && ! $again, first_diff( $schema, $reread ) . json_encode( $again ) );
$with_seo = Leiho_Collections_Schema::normalize( array( 'collections' => array( array_merge( $home, array( 'seo' => array( 'type' => 'AboutPage', 'title' => 'hero_title' ) ) ) ) ), $again );
check( 'bloc seo validé et gardé', array( 'type' => 'AboutPage', 'title' => 'hero_title' ) === $with_seo['collections'][0]['seo'] && ! $again, $again );
check( 'bloc seo relu à l’identique', $with_seo === Leiho_Collections_Schema::normalize( Leiho_Collections_Schema::compact( $with_seo ), $again ) );
check( 'pas de bloc seo : rien d’écrit', ! isset( Leiho_Collections_Schema::compact( $schema )['collections'][0]['seo'] ) );
check( 'export compact (pas de required:false)', false === strpos( json_encode( Leiho_Collections_Schema::compact( $schema ) ), '"required":false' ) );

/* --- Colonnes --- */
$f = function ( $def ) { $e = array(); return Leiho_Collections_Schema::normalize_field( $def, '', $e, array( 'service' ), 1 ); };
check( 'texte court indexé', 'VARCHAR(191)' === Leiho_Collections_Schema::column_type( $f( array( 'name' => 'a', 'type' => 'text', 'max_length' => 80 ) ) ) );
check( 'texte long', 'TEXT' === Leiho_Collections_Schema::column_type( $f( array( 'name' => 'a', 'type' => 'text' ) ) ) );
check( 'nombre décimal', 'DECIMAL(20,6)' === Leiho_Collections_Schema::column_type( $f( array( 'name' => 'a', 'type' => 'number' ) ) ) );
check( 'référence unique', 'BIGINT(20) UNSIGNED' === Leiho_Collections_Schema::column_type( $f( array( 'name' => 'a', 'type' => 'reference', 'to' => 'service' ) ) ) );
check( 'référence multiple en JSON', Leiho_Collections_Schema::is_json( $f( array( 'name' => 'a', 'type' => 'reference', 'to' => 'service', 'multiple' => true ) ) ) );
check( 'date indexée', Leiho_Collections_Schema::is_indexed( $f( array( 'name' => 'a', 'type' => 'date' ) ) ) );
check( 'JSON non indexé', ! Leiho_Collections_Schema::is_indexed( $f( array( 'name' => 'a', 'type' => 'list', 'fields' => array( array( 'name' => 'b', 'type' => 'text' ) ) ) ) ) );

/* =====================================================================
 * Migrations sur SQLite
 * =================================================================== */

$report = Leiho_Collections::save_schema( array( 'collections' => array( $home, $service ) ), true );
check( 'dry run : plan sans table', ! is_wp_error( $report ) && 'create_table' === $report['operations'][0]['op'] && ! Leiho_Collections_Store::collection_state( 'home' ), $report );

$report = Leiho_Collections::save_schema( array( 'collections' => array( $home, $service ) ) );
check( 'schéma enregistré', ! is_wp_error( $report ) && Leiho_Collections::get( 'service' ), $report );
$cols = sql_columns( 'wp_leiho_service' );
check( 'table créée avec colonnes typées', isset( $cols['f_price'], $cols['f_featured'], $cols['f_tags'] ) && 'TINYINT(1)' === $cols['f_featured'] && 'DATE' === $cols['f_starts'], $cols );
check( 'index créés', (bool) $GLOBALS['wpdb']->get_var( "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='wp_leiho_service' AND sql LIKE '%f_tone%'" ) );
check( 'post types', array( 'home', 'service' ) === Leiho_Collections::post_types() );
check( 'entrée du singleton créée', 'create_entry' === end( $report['operations'] )['op'] && 'home' === $GLOBALS['inserted'][0]['post_name'], $report['operations'] );

$settings = Leiho_Collections::settings( array( 'post_types' => array( 'page' ), 'url_patterns' => array( 'home' => '/accueil' ) ) );
check( 'réglages : types ajoutés', array( 'page', 'home', 'service' ) === $settings['post_types'] );
check( 'réglages : chemin du réglage prioritaire', '/accueil' === $settings['url_patterns']['home'] && '/services/{slug}' === $settings['url_patterns']['service'] );

/* =====================================================================
 * Entrées : écriture, lecture, chemins pointés
 * =================================================================== */

$GLOBALS['post_types'] = array( 1 => 'home', 2 => 'service', 3 => 'service', 7 => 'attachment', 8 => 'attachment', 9 => 'page' );
$adapter = new Leiho_Native_Adapter();

$result = $adapter->save_values(
	1,
	array(
		'hero_title' => 'Bonjour',
		'sections'   => array(
			array( '_layout' => 'hero', 'image' => 7, 'body' => '<b>Hi</b><script>x</script>' ),
			array( '_layout' => 'cards', 'items' => array(
				array( 'title' => 'Audit', 'service' => 2 ),
				array( 'title' => 'Refonte', 'service' => 3 ),
			) ),
		),
		'contact'    => array( 'email' => 'a@b.fr', 'cta' => array( 'url' => 'https://x.fr', 'title' => 'Go', 'target' => '_blank' ) ),
		'ailleurs'   => 'ignoré',
	)
);
check( 'écriture des champs racine', array( 'hero_title', 'sections', 'contact' ) === $result['saved'] && ! $result['errors'], $result );
check( 'chemin inconnu laissé aux autres adapters', ! isset( $result['errors']['ailleurs'] ) );
check( 'révision demandée', 1 === $GLOBALS['revisions'] );
// Les champs vivent dans la table de la collection, pas en postmeta : le
// cache de contenu et la publication automatique doivent l'apprendre quand même.
$changed = array_values( array_filter( $GLOBALS['actions'], function ( $a ) { return 'leiho_content_changed' === $a[0]; } ) );
check( 'écriture : leiho_content_changed', $changed && 1 === $changed[ count( $changed ) - 1 ][1][0], $changed );
$gen = Leiho_Cache::generation();
$inv = new ReflectionProperty( 'Leiho_Content', 'invalidated' );
$inv->setAccessible( true );
$inv->setValue( null, false );
$GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] = array( 'stale' => true );
$adapter->save_values( 1, array( 'hero_title' => 'Bonjour !' ) );
check( 'écriture : cache de contenu invalidé', $gen !== Leiho_Cache::generation() && ! isset( $GLOBALS['options'][ Leiho_Content::OPTION_CACHE ] ) );
$adapter->save_values( 1, array( 'hero_title' => 'Bonjour' ) );
$GLOBALS['revisions'] = 1;

$row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM wp_leiho_home WHERE post_id = 1' );
$stored = json_decode( $row['f_sections'], true );
check( 'JSON indexé par clé, bloc par _block', 'cards' === $stored[1]['_block'] && 'Audit' === $stored[1]['items'][0]['title'] && 2 === $stored[1]['items'][0]['service'], $stored );
check( 'richtext nettoyé', '<b>Hi</b>' === $stored[0]['body'] );

$groups = $adapter->get_schema( 1 );
$fields = $groups[0]['fields'];
check( 'groupe de l’éditeur', 'collections' === $groups[0]['source'] && 'hero_title' === $fields[0]['path'] && 40 === $fields[0]['max_length'], $groups[0] );
check( 'blocs = flexible_content', 'flexible_content' === $fields[1]['type'] && 'cards' === $fields[1]['value'][1]['_layout'], $fields[1]['value'] );
check( 'méta image', 'https://cdn/7.jpg' === $fields[1]['meta'][0]['image']['url'] );
check( 'référence lisible', 2 === $fields[1]['value'][1]['items'][0]['service'] && 'Titre 2' === $fields[1]['meta'][1]['items'][0]['service']['label'], $fields[1]['meta'][1] );

/* --- Chemin pointé --- */
$result = $adapter->save_values( 1, array( 'sections.1.items.1.title' => 'Refonte web', 'contact.email' => 'c@d.fr' ) );
check( 'patch pointé', array( 'sections.1.items.1.title', 'contact.email' ) === $result['saved'], $result );
$content = $adapter->get_content( 1 );
check( 'patch appliqué, le reste intact', 'Refonte web' === $content['sections'][1]['items'][1]['title'] && 'Audit' === $content['sections'][1]['items'][0]['title'], $content['sections'] );
check( 'groupe patché', 'c@d.fr' === $content['contact']['email'] && 'https://x.fr' === $content['contact']['cta']['url'] );
check( 'contenu : _layout et média', 'hero' === $content['sections'][0]['_layout'] && 7 === $content['sections'][0]['image']['id'] );
check( 'contenu : HTML rendu', '<p><b>Hi</b></p>' === $content['sections'][0]['body'] );
check( 'contenu : référence', 2 === $content['sections'][1]['items'][0]['service']['id'] && '/services/slug-2' === $content['sections'][1]['items'][0]['service']['path'], $content['sections'][1]['items'][0] );

/* --- Validation à l'écriture --- */
$r = $adapter->save_values( 1, array( 'hero_title' => '' ) );
check( 'required', 'required' === $r['errors']['hero_title'] );
$r = $adapter->save_values( 1, array( 'hero_title' => str_repeat( 'x', 41 ) ) );
check( 'max_length', 'too_long' === $r['errors']['hero_title'] );
$r = $adapter->save_values( 1, array( 'sections.1.items.0.service' => 9 ) );
check( 'référence vers un autre type refusée', 'invalid_reference' === $r['errors']['sections.1.items.0.service'], $r );
$r = $adapter->save_values( 1, array( 'sections.0.image' => 2 ) );
check( 'image : un média seulement', 'invalid_attachment' === $r['errors']['sections.0.image'], $r );
$r = $adapter->save_values( 1, array( 'sections.5.image' => 7 ) );
check( 'ligne absente', 'row_not_found' === $r['errors']['sections.5.image'] );
$r = $adapter->save_values( 1, array( 'sections' => array( array( '_layout' => 'cards', 'items' => array() ), array( '_layout' => 'cards', 'items' => array() ), array( '_layout' => 'cards', 'items' => array() ) ) ) );
check( 'max par type de bloc', 'too_many_rows:cards' === $r['errors']['sections'], $r );
check( 'valeur intacte après erreur', 'Bonjour' === $adapter->get_content( 1 )['hero_title'] );

$r = $adapter->save_values(
	2,
	array(
		'price'    => '120 €',
		'featured' => true,
		'tone'     => 'dark',
		'tags'     => array( 'b', 'a', 'b' ),
		'starts'   => '4 November 2026',
		'code'     => 'Audit SEO !',
		'related'  => 3,
		'gallery'  => array( 7, 8 ),
		'count'    => 12,
	)
);
check( 'entrée de collection écrite', 9 === count( $r['saved'] ), $r );
$c = $adapter->get_content( 2 );
check( 'booléen', true === $c['featured'] );
check( 'select multiple', array( 'b', 'a' ) === $c['tags'], $c['tags'] );
check( 'date stockée en Y-m-d', '2026-11-04' === $c['starts'], $c['starts'] );
check( 'slug', 'audit-seo' === $c['code'] );
check( 'référence unique', 3 === $c['related']['id'] );
check( 'galerie', 2 === count( $c['gallery'] ) && 8 === $c['gallery'][1]['id'] );
check( 'entier', 12 === $c['count'], $c['count'] );

$bad = array(
	array( 'tags', array( 'z' ), 'invalid_choice' ),
	array( 'tone', 'z', 'invalid_choice' ),
	array( 'count', 101, 'out_of_range' ),
	array( 'count', 1.5, 'invalid_number' ),
	array( 'gallery', array( 7, 8, 7 ), 'too_many_items' ),
	array( 'starts', 'pas une date', 'invalid_date' ),
);
foreach ( $bad as $case ) {
	$r = $adapter->save_values( 2, array( $case[0] => $case[1] ) );
	check( 'refus ' . $case[0] . ' → ' . $case[2], isset( $r['errors'][ $case[0] ] ) && $case[2] === $r['errors'][ $case[0] ], $r );
}
$tags = array_values( array_filter( $adapter->get_schema( 2 )[0]['fields'], function ( $x ) { return 'tags' === $x['path']; } ) )[0];
check( 'éditeur : select multiple en liste', true === $tags['multiple'] && array( 'b', 'a' ) === $tags['value'], $tags );
check( 'vider un champ = NULL', array( 'tone' ) === $adapter->save_values( 2, array( 'tone' => '' ) )['saved'] && null === $GLOBALS['wpdb']->get_var( 'SELECT f_tone FROM wp_leiho_service WHERE post_id = 2' ) );
check( 'select vide servi en chaîne vide', '' === $adapter->get_content( 2 )['tone'] );
check( 'autre type de post ignoré', array() === $adapter->get_schema( 9 ) && array() === $adapter->get_content( 9 ) );

/* =====================================================================
 * Migrations avec données
 * =================================================================== */

$GLOBALS['wpdb']->insert( 'wp_leiho_service', array( 'post_id' => 3, 'f_price' => '99.5' ) );
$v2              = $service;
$v2['fields'][0] = array( 'key' => 'price', 'name' => 'price', 'type' => 'number' );                // texte → nombre.
$v2['fields'][1] = array( 'key' => 'featured', 'name' => 'highlighted', 'type' => 'boolean' );       // renommé.
$v2['fields'][6] = array( 'name' => 'related', 'type' => 'reference', 'to' => array( 'service' ), 'multiple' => true ); // unique → multiple.
unset( $v2['fields'][7] );                                                                             // galerie retirée.
$v2['fields'][]  = array( 'name' => 'summary', 'type' => 'text', 'multiline' => true );            // ajouté.

$plan = Leiho_Collections::save_schema( array( 'collections' => array( $home, $v2 ) ), true );
$ops  = array();
foreach ( $plan['operations'] as $op ) { $ops[] = $op['op'] . ( isset( $op['key'] ) ? ':' . $op['key'] : '' ); }
check( 'plan', array( 'retype:price', 'retype:related', 'add_column:summary', 'archive:gallery' ) === $ops, $ops );

$report = Leiho_Collections::save_schema( array( 'collections' => array( $home, $v2 ) ) );
check( 'migration appliquée', ! is_wp_error( $report ), $report );
check( 'conversion comptée', array( 'converted' => 1, 'failed' => 1 ) === $report['converted']['service.price'], $report['converted'] );
check( 'échec signalé', 1 === count( $report['warnings'] ), $report['warnings'] );
$cols = sql_columns( 'wp_leiho_service' );
check( 'nouvelle colonne, ancienne gardée', isset( $cols['f_price__2'], $cols['f_price'], $cols['f_gallery'] ), $cols );
$c2 = $adapter->get_content( 2 );
$c3 = $adapter->get_content( 3 );
check( 'nombre converti', 99.5 === $c3['price'], $c3 );
check( 'texte non numérique : null, gardé en archive', null === $c2['price'] && '120 €' === $GLOBALS['wpdb']->get_var( 'SELECT f_price FROM wp_leiho_service WHERE post_id = 2' ) );
check( 'renommage sans perte', true === $c2['highlighted'] && ! isset( $c2['featured'] ) );
check( 'unique → multiple', array( 3 ) === array_column( $c2['related'], 'id' ), $c2['related'] );
check( 'champ retiré absent du contenu', ! isset( $c2['gallery'] ) );
$state = Leiho_Collections_Store::collection_state( 'service' );
check( 'colonnes archivées listées', array( 'f_price', 'f_related', 'f_gallery' ) === array_column( $state['archived'], 'column' ), $state['archived'] );

/* --- Champ rajouté : sa colonne revient --- */
$v3            = $v2;
$v3['fields'][] = array( 'name' => 'gallery', 'type' => 'gallery', 'max' => 2 );
$report = Leiho_Collections::save_schema( array( 'collections' => array( $home, $v3 ) ) );
$ops    = array_column( $report['operations'], 'op' );
check( 'restauration de colonne archivée', in_array( 'restore', $ops, true ) && ! in_array( 'add_column', $ops, true ), $report['operations'] );
check( 'valeurs retrouvées', 2 === count( ( new Leiho_Native_Adapter() )->get_content( 2 )['gallery'] ) );

/* --- Retrait d'une collection : données gardées --- */
$report = Leiho_Collections::save_schema( array( 'collections' => array( $v3 ) ) );
check( 'collection retirée sans DROP', 'remove_collection' === $report['operations'][0]['op'] && null === Leiho_Collections::get( 'home' ) && Leiho_Collections_Store::collection_state( 'home' ) );
Leiho_Collections::save_schema( array( 'collections' => array( $home, $v3 ) ) );
check( 'collection remise : contenu intact', 'Bonjour' === ( new Leiho_Native_Adapter() )->get_content( 1 )['hero_title'] );

/* --- Purge explicite --- */
$dropped = Leiho_Collections_Store::purge( 'service', array( 'f_related' ) );
check( 'purge d’une colonne archivée', array( 'f_related' ) === $dropped && ! isset( sql_columns( 'wp_leiho_service' )['f_related'] ), $dropped );

/* --- Erreurs d'une collection seule : chemins relatifs --- */
$err = Leiho_Collections::put_collection( array( 'id' => 'team', 'fields' => array( array( 'name' => 'Bad', 'type' => 'text' ) ) ) );
check( 'put_collection : erreurs relatives', is_wp_error( $err ) && 'fields.0.name' === $err->get_error_data()['errors'][0]['path'], $err );
$err = Leiho_Collections::put_collection( array( 'id' => 'activity' ) );
check( 'id de la table du journal réservé', is_wp_error( $err ) && 'id' === $err->get_error_data()['errors'][0]['path'] );

/* --- Identifiants --- */
$threw = false;
try { Leiho_Collections_Store::ident( 'x`; DROP TABLE wp_posts; --' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'identifiant SQL refusé', $threw );

/* =====================================================================
 * Conversions
 * =================================================================== */

$ok = true;
$to = function ( $def ) use ( $f ) { return $f( $def ); };
check( 'texte → booléen', 0 === Leiho_Collections_Values::convert( 'no', array( 'type' => 'text' ), $to( array( 'name' => 'a', 'type' => 'boolean' ) ), $ok ) && $ok );
check( 'texte → date', '2026-11-04' === Leiho_Collections_Values::convert( '2026-11-04 10:00', array( 'type' => 'text' ), $to( array( 'name' => 'a', 'type' => 'date' ) ), $ok ) );
check( 'liste → unique', 5 === Leiho_Collections_Values::convert( '[5,6]', array( 'type' => 'reference', 'multiple' => true ), $to( array( 'name' => 'a', 'type' => 'reference', 'to' => 'service' ) ), $ok ) );
Leiho_Collections_Values::convert( 'zzz', array( 'type' => 'text' ), $to( array( 'name' => 'a', 'type' => 'select', 'choices' => array( 'a' ) ) ), $ok );
check( 'choix inconnu : échec', false === $ok );
Leiho_Collections_Values::convert( str_repeat( 'x', 300 ), array( 'type' => 'text' ), $to( array( 'name' => 'a', 'type' => 'text', 'max_length' => 100 ) ), $ok );
check( 'trop long pour la colonne : échec, pas de troncature', false === $ok );

/* =====================================================================
 * Révisions
 * =================================================================== */

$snap = Leiho_Collections::snapshot( Leiho_Collections::get( 'home' ), 1 );
check( 'instantané indexé par clé', isset( $snap['fields']['hero_title'], $snap['fields']['sections'] ) && 'text' === $snap['fields']['hero_title']['type'], $snap );

/* =====================================================================
 * Langues : champs traduits, champs partagés, repli, chemins, état
 * =================================================================== */

$i18n_home = $home;
foreach ( $i18n_home['fields'] as $i => $field ) {
	if ( 'contact' === $field['name'] ) {
		$i18n_home['fields'][ $i ]['localized'] = false; // Groupe partagé entre les langues.
	}
}
$langs  = array( 'default' => 'en', 'locales' => array( array( 'code' => 'en' ), array( 'code' => 'fr', 'label' => 'Français' ) ) );
$report = Leiho_Collections::save_schema( array( 'i18n' => $langs, 'collections' => array( $i18n_home, $v3 ) ) );
check( 'langues enregistrées', ! is_wp_error( $report ) && Leiho_I18n::enabled(), $report );
check( 'langue inconnue refusée', null === Leiho_I18n::valid( 'de' ) && 'fr' === Leiho_I18n::valid( 'FR' ) );
$compact = Leiho_Collections::export();
check( 'i18n exporté', isset( $compact['i18n'] ) && 'en' === $compact['i18n']['default'], $compact );
$compact_contact = array_values( array_filter( $compact['collections'][0]['fields'], function ( $f ) { return 'contact' === $f['name']; } ) );
check( 'localized écrit seulement s\'il change', isset( $compact_contact[0]['localized'] ) && false === $compact_contact[0]['localized'] && ! isset( $compact['collections'][0]['fields'][0]['localized'] ), $compact_contact );
$errors = array();
$norm   = Leiho_I18n::normalize( array( 'default' => 'xx', 'locales' => array( 'fr', 'en', 'pt BR' ) ), $errors );
check( 'défaut hors liste : première langue', 'fr' === $norm['default'] && 2 === count( $norm['locales'] ), $norm );
check( 'code invalide signalé', 'i18n.locales.2' === $errors[0]['path'], $errors );

$adapter = new Leiho_Native_Adapter();
$adapter->save_values( 1, array( 'contact.email' => 'hello@example.com' ) );
Leiho_I18n::set_current( 'fr' );
check( 'traduction vide au départ', null === $adapter->get_schema( 1 )[0]['fields'][0]['value'] || '' === $adapter->get_schema( 1 )[0]['fields'][0]['value'] );
check( 'statut manquant', 'missing' === Leiho_I18n::status( 1, 'fr' ) );
$r = $adapter->save_values( 1, array( 'hero_title' => 'Salut', 'contact.email' => 'bonjour@example.com' ) );
check( 'traduction enregistrée', array() === $r['errors'], $r );
check( 'original intact', 'Bonjour' === $GLOBALS['wpdb']->get_var( 'SELECT f_hero_title FROM wp_leiho_home WHERE post_id = 1' ) );
check( 'champ partagé écrit dans la table', false !== strpos( (string) $GLOBALS['wpdb']->get_var( 'SELECT f_contact FROM wp_leiho_home WHERE post_id = 1' ), 'bonjour@example.com' ) );
$fr = $adapter->get_content( 1 );
check( 'contenu traduit', 'Salut' === $fr['hero_title'], $fr );
check( 'champ partagé identique', 'bonjour@example.com' === $fr['contact']['email'], $fr['contact'] );
Leiho_I18n::set_current( 'en' );
$en = $adapter->get_content( 1 );
check( 'langue par défaut inchangée', 'Bonjour' === $en['hero_title'] && 'bonjour@example.com' === $en['contact']['email'], $en );

/* Repli : un champ traduisible vide dans la traduction reprend l'original. */
$adapter->save_values( 1, array( 'sections' => array( array( '_layout' => 'hero', 'body' => '<p>Hello</p>' ), array( '_layout' => 'hero', 'body' => '<p>Second</p>' ) ) ) );
Leiho_I18n::set_current( 'fr' );
check( 'repli sur l\'original', false !== strpos( $adapter->get_content( 1 )['sections'][0]['body'], 'Hello' ), $adapter->get_content( 1 )['sections'] );
check( 'statut partiel', 'partial' === Leiho_I18n::status( 1, 'fr' ) );
$r = $adapter->save_values( 1, array( 'sections.0.body' => '<p>Bonjour</p>' ) );
check( 'traduction partielle d\'une liste : part de l\'original', array() === $r['errors'] && false !== strpos( $adapter->get_content( 1 )['sections'][0]['body'], 'Bonjour' ), $r );
$fr = $adapter->get_content( 1 );
check( 'ligne pas encore traduite : repli par élément', false !== strpos( $fr['sections'][1]['body'], 'Second' ), $fr['sections'] );
$stored = Leiho_I18n::row( 1, 'fr' )['data']['sections'];
check( 'texte d\'origine pas copié dans la traduction', false === strpos( (string) $stored, 'Second' ), $stored );
check( 'statut partiel tant qu\'une ligne manque', 'partial' === Leiho_I18n::status( 1, 'fr' ) );
Leiho_I18n::set_current( 'en' );
check( 'original de la liste intact', false !== strpos( $adapter->get_content( 1 )['sections'][0]['body'], 'Hello' ) );

/* Ligne ajoutée à l'original après la traduction : elle se traduit aussi. */
$adapter->save_values( 1, array( 'sections' => array( array( '_layout' => 'hero', 'body' => '<p>Hello</p>' ), array( '_layout' => 'hero', 'body' => '<p>Second</p>' ), array( '_layout' => 'hero', 'body' => '<p>Third</p>' ) ) ) );
Leiho_I18n::set_current( 'fr' );
$r = $adapter->save_values( 1, array( 'sections.2.body' => '<p>Troisième</p>' ) );
$fr = $adapter->get_content( 1 );
check( 'nouvelle ligne de l\'original traduisible', array() === $r['errors'] && false !== strpos( $fr['sections'][2]['body'], 'Troisième' ), $r );
check( 'traductions existantes gardées', false !== strpos( $fr['sections'][0]['body'], 'Bonjour' ) && false !== strpos( $fr['sections'][1]['body'], 'Second' ), $fr['sections'] );
check( 'ligne intermédiaire pas copiée', false === strpos( (string) Leiho_I18n::row( 1, 'fr' )['data']['sections'], 'Second' ) );
Leiho_I18n::set_current( 'en' );

/* Chemins. */
check( 'chemin préfixé', '/fr/services/a' === Leiho_I18n::path( '/services/a', 'fr' ) && '/fr' === Leiho_I18n::path( '/', 'fr' ) );
check( 'langue par défaut sans préfixe', '/services/a' === Leiho_I18n::path( '/services/a', 'en' ) );
check( 'chemin découpé', array( 'fr', '/services/a' ) === Leiho_I18n::split_path( '/fr/services/a' ) && array( 'fr', '/' ) === Leiho_I18n::split_path( '/fr' ) );
check( 'pas une langue', array( 'en', '/faq' ) === Leiho_I18n::split_path( '/faq' ) && array( 'en', '/de/x' ) === Leiho_I18n::split_path( '/de/x' ) );
check( 'in_locale rétablit la langue', 'fr' === Leiho_I18n::in_locale( 'fr', function () { return Leiho_I18n::current(); } ) && 'en' === Leiho_I18n::current() );

/* Suppression de l'entrée : traductions effacées. */
Leiho_I18n::delete( 1 );
Leiho_I18n::flush();
check( 'traductions supprimées', null === Leiho_I18n::row( 1, 'fr' ) );
Leiho_Collections::save_schema( array( 'collections' => array( $home, $v3 ) ) );
check( 'langues retirées', ! Leiho_I18n::enabled() );

/* =====================================================================
 * Types TypeScript
 * =================================================================== */

$ts = Leiho_Types::render(
	array(
		'home'    => $adapter->get_definitions( 'home' ),
		'service' => ( new Leiho_Native_Adapter() )->get_definitions( 'service' ),
	)
);
check( 'singleton typé', false !== strpos( $ts, "export type Home = LeihoItem<'home', HomeFields>;" ) );
check( 'blocs discriminés', false !== strpos( $ts, "_layout: 'cards';" ) );
check( 'référence dans une liste', false !== strpos( $ts, 'service: LeihoRef | null;' ), $ts );
check( 'select multiple', false !== strpos( $ts, "tags: Array<'a' | 'b'>;" ), $ts );
check( 'référence multiple', false !== strpos( $ts, 'related: LeihoRef[];' ) );
check( 'nombre', false !== strpos( $ts, 'price: number | null;' ) );
$tsc = trim( (string) shell_exec( 'command -v tsc 2>/dev/null' ) );
if ( '' !== $tsc ) {
	$file = sys_get_temp_dir() . '/leiho-collections-' . getmypid() . '.d.ts';
	file_put_contents( $file, $ts );
	exec( escapeshellarg( $tsc ) . ' --noEmit --strict ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
	unlink( $file );
	check( 'tsc accepte les types', 0 === $code, implode( "\n", $output ) );
}

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
