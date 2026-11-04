<?php
/**
 * Migration d'un site Okno vers Leiho : php plugin/tests/test-legacy.php
 *
 * Un vrai SQLite en mémoire (PDO) derrière un $wpdb minimal et l'API des
 * options branchée dessus : la migration exécute son SQL (SHOW TABLES, ALTER
 * TABLE … RENAME TO, UPDATE de clés) sur de vraies tables, index compris.
 */
if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	fwrite( STDERR, "pdo_sqlite is required for this test.\n" );
	exit( 1 );
}

define( 'ABSPATH', true );
define( 'MINUTE_IN_SECONDS', 60 );

class Fake_WPDB {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $postmeta = 'wp_postmeta';
	public $usermeta = 'wp_usermeta';
	public $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
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
		// SHOW TABLES LIKE (MySQL) -> sqlite_master ; LIKE avec échappement \.
		if ( preg_match( "/^SHOW TABLES LIKE (.+)$/", $sql, $m ) ) {
			$sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE {$m[1]} ESCAPE '\\'";
		} elseif ( false !== stripos( $sql, ' LIKE ' ) ) {
			$sql .= " ESCAPE '\\'";
		}
		$sql = preg_replace( '/^DROP INDEX (`\w+`) ON `\w+`$/', 'DROP INDEX $1', $sql );
		$stmt = $this->pdo->query( $sql );
		return $stmt;
	}
	public function query( $sql ) { $s = $this->run( $sql ); return false === $s ? false : $s->rowCount(); }
	public function get_results( $sql ) { $s = $this->run( $sql ); return false === $s ? array() : $s->fetchAll( PDO::FETCH_ASSOC ); }
	public function get_col( $sql ) { return array_map( function ( $r ) { return array_values( $r )[0]; }, $this->get_results( $sql ) ); }
	public function get_var( $sql ) { $r = $this->get_results( $sql ); return $r ? reset( $r[0] ) : null; }
	private function cond( $where ) {
		$c = array();
		foreach ( $where as $k => $v ) { $c[] = "`$k` = " . $this->pdo->quote( (string) $v ); }
		return implode( ' AND ', $c );
	}
	public function update( $table, $data, $where ) {
		$set = array();
		foreach ( $data as $k => $v ) { $set[] = "`$k` = " . $this->pdo->quote( (string) $v ); }
		return $this->query( "UPDATE `$table` SET " . implode( ', ', $set ) . ' WHERE ' . $this->cond( $where ) );
	}
	public function delete( $table, $where ) { return $this->query( "DELETE FROM `$table` WHERE " . $this->cond( $where ) ); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

/* --- API des options, sur la table ci-dessus --- */
function get_option( $name, $default = false ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', $name ) );
	return null === $v ? $default : unserialize( $v );
}
function add_option( $name, $value, $d = '', $autoload = 'yes' ) {
	global $wpdb;
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM wp_options WHERE option_name = %s', $name ) ) ) {
		return false;
	}
	$wpdb->query( $wpdb->prepare( 'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (%s, %s, %s)', $name, serialize( $value ), $autoload ) );
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	global $wpdb;
	if ( ! add_option( $name, $value ) ) {
		$wpdb->update( 'wp_options', array( 'option_value' => serialize( $value ) ), array( 'option_name' => $name ) );
	}
	return true;
}
function delete_option( $name ) { global $wpdb; return (bool) $wpdb->delete( 'wp_options', array( 'option_name' => $name ) ); }
function wp_cache_delete( ...$a ) { return true; }
function wp_cache_flush() { $GLOBALS['cache_flushed'] = true; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['cleared'][] = $hook; }
function wp_salt( $s ) { return 'salt-' . $s; }

/* --- Hooks minimaux --- */
$GLOBALS['hooks'] = array();
function add_filter( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['hooks'][ $tag ][ $prio ][] = $cb; return true; }
function add_action( ...$a ) { return add_filter( ...$a ); }
function has_filter( $tag ) { return ! empty( $GLOBALS['hooks'][ $tag ] ); }
function has_action( $tag ) { return has_filter( $tag ); }
function apply_filters( $tag, $value, ...$args ) {
	$h = $GLOBALS['hooks'][ $tag ] ?? array();
	ksort( $h );
	foreach ( $h as $cbs ) { foreach ( $cbs as $cb ) { $value = $cb( $value, ...$args ); } }
	return $value;
}
function do_action( $tag, ...$args ) {
	$h = $GLOBALS['hooks'][ $tag ] ?? array();
	ksort( $h );
	foreach ( $h as $cbs ) { foreach ( $cbs as $cb ) { $cb( ...$args ); } }
}
function apply_filters_deprecated( $tag, $args, $v, $repl ) { $GLOBALS['deprecated'][] = $tag; return apply_filters( $tag, ...$args ); }
function do_action_deprecated( $tag, $args, $v, $repl ) { $GLOBALS['deprecated'][] = $tag; do_action( $tag, ...$args ); }

final class Leiho_Plugin { const OPTION_SECRETS = 'leiho_secrets'; }
require __DIR__ . '/../includes/class-leiho-secrets.php';
require __DIR__ . '/../includes/collections/class-leiho-collections-store.php';
require __DIR__ . '/../includes/class-leiho-legacy.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/** Chiffrement tel qu'Okno le faisait (clé dérivée de « okno-secrets »). */
function okno_encrypt( $plain ) {
	$key    = hash( 'sha256', wp_salt( 'auth' ) . '|okno-secrets', true );
	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	return base64_encode( $iv . hash_hmac( 'sha256', $iv . $cipher, $key, true ) . $cipher );
}

/** Nom d'index tel qu'Okno le calculait. */
function okno_index_name( $table, $column ) {
	return 'k' . substr( md5( $table . '.' . $column ), 0, 10 ) . '_' . substr( $column, 0, 50 );
}

global $wpdb;

/* --- 1. Site neuf : rien à migrer --- */
check( 'site neuf : pas de données Okno', ! Leiho_Legacy::has_legacy_data() );
check( 'site neuf : rien migré', null === Leiho_Legacy::maybe_migrate() );
check( 'site neuf : drapeau « none »', 'none' === get_option( Leiho_Legacy::FLAG )['from'] );
delete_option( Leiho_Legacy::FLAG );

/* --- 2. Un site Okno beta.11 --- */
$settings = array( 'front_url' => 'https://www.dyese.fr', 'deploy_driver' => 'github', 'headless_redirect' => false );
add_option( 'okno_settings', $settings );
add_option( 'okno_secrets', array( 'gh_pat' => okno_encrypt( 'github_pat_SECRET' ), 'broken' => 'xxx' ), '', 'no' );
add_option( 'okno_collections', array( 'produit' => array( 'id' => 'produit' ) ) );
add_option( 'okno_collection_produit', array( 'v' => 3 ) );
add_option( 'okno_activity_db_version', '1' );
add_option( '_transient_okno_gh_preflight_1', 'cache' );
add_option( '_transient_timeout_okno_gh_preflight_1', 123 );
add_option( 'autre_plugin', 'intact' );

$wpdb->pdo->exec( 'CREATE TABLE wp_okno_activity (activity_id INTEGER PRIMARY KEY, field_path TEXT)' );
$wpdb->pdo->exec( "INSERT INTO wp_okno_activity (field_path) VALUES ('hero_title'), ('intro')" );
$wpdb->pdo->exec( 'CREATE TABLE wp_okno_produit (post_id INTEGER PRIMARY KEY, prix REAL)' );
$wpdb->pdo->exec( 'CREATE INDEX ' . okno_index_name( 'wp_okno_produit', 'prix' ) . ' ON wp_okno_produit (prix)' );
$wpdb->pdo->exec( 'INSERT INTO wp_okno_produit VALUES (12, 49.9)' );
$wpdb->pdo->exec( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (12, '_okno_rev', '3'), (12, '_okno_path', '/services'), (40, '_okno_fields', 'a:0:{}'), (12, '_edit_lock', 'x')" );
$wpdb->pdo->exec( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'okno_theme', 'light'), (1, 'nickname', 'admin')" );

// Une table d'historique Leiho vide, créée avant la migration (Leiho activé tôt).
$wpdb->pdo->exec( 'CREATE TABLE wp_leiho_activity (activity_id INTEGER PRIMARY KEY, field_path TEXT)' );

check( 'site Okno : données détectées', Leiho_Legacy::has_legacy_data() );
$report = Leiho_Legacy::maybe_migrate();
check( 'migration faite', is_array( $report ) && 'okno' === $report['from'], $report );

check( 'réglages repris à l’identique', $settings === get_option( 'leiho_settings' ), get_option( 'leiho_settings' ) );
check( 'ancienne option retirée', false === get_option( 'okno_settings' ) );
check( 'options par collection reprises', array( 'v' => 3 ) === get_option( 'leiho_collection_produit' ) );
check( 'version de l’historique reprise', '1' === get_option( 'leiho_activity_db_version' ) );
check( 'autoload conservé (secrets)', 'no' === $wpdb->get_var( "SELECT autoload FROM wp_options WHERE option_name = 'leiho_secrets'" ) );
check( 'option d’un autre plugin intacte', 'intact' === get_option( 'autre_plugin' ) );
check( 'transients Okno supprimés', null === $wpdb->get_var( "SELECT option_id FROM wp_options WHERE option_name LIKE '%transient%okno%'" ) );

check( 'secret lisible avec la clé Leiho', 'github_pat_SECRET' === Leiho_Secrets::get( 'gh_pat' ) );
check( 'secret rechiffré pendant la migration', 1 === $report['secrets'], $report );
check( 'plus rien à rechiffrer ensuite', 0 === Leiho_Secrets::upgrade_legacy() );
check( 'secret illisible laissé tel quel', 'unreadable' === Leiho_Secrets::status( 'broken' ) );

check( 'historique : table renommée avec ses lignes', 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_leiho_activity' ) );
check( 'historique : la table Leiho vide a cédé la place', in_array( 'wp_leiho_activity', $report['tables'], true ), $report );
check( 'collection : table renommée avec ses lignes', 49.9 === (float) $wpdb->get_var( 'SELECT prix FROM wp_leiho_produit WHERE post_id = 12' ) );
check( 'plus aucune table okno_', null === $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'wp_okno%'" ) );
$index = Leiho_Collections_Store::index_name( 'wp_leiho_produit', 'prix' );
check( 'index retrouvé sous le nom calculé par Leiho', (bool) $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type = 'index' AND name = '$index'" ), $index );
check( 'DROP INDEX possible après le renommage', false !== $wpdb->query( 'DROP INDEX `' . $index . '` ON `wp_leiho_produit`' ) );

check( 'métas de contenu renommées', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '\\_leiho\\_%'" ) );
check( 'valeur de méta conservée', '/services' === $wpdb->get_var( "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_leiho_path'" ) );
check( 'méta étrangère intacte', 'x' === $wpdb->get_var( "SELECT meta_value FROM wp_postmeta WHERE meta_key = '_edit_lock'" ) );
check( 'méta utilisateur (thème) renommée', 'light' === $wpdb->get_var( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'leiho_theme'" ) );
check( 'crons Okno retirés', array( 'okno_cleanup_deploys', 'okno_auto_deploy' ) === $GLOBALS['cleared'] );
check( 'caches vidés', ! empty( $GLOBALS['cache_flushed'] ) );
check( 'aucun conflit', array() === $report['conflicts'], $report );

check( 'une seule fois : deuxième appel sans effet', null === Leiho_Legacy::maybe_migrate() );
check( 'verrou relâché', false === get_option( Leiho_Legacy::LOCK ) );

/* --- 3. Conflit : une table Leiho déjà remplie est gardée --- */
delete_option( Leiho_Legacy::FLAG );
$wpdb->pdo->exec( 'CREATE TABLE wp_okno_activity (activity_id INTEGER PRIMARY KEY, field_path TEXT)' );
$wpdb->pdo->exec( "INSERT INTO wp_okno_activity (field_path) VALUES ('ancien')" );
$report = Leiho_Legacy::maybe_migrate();
check( 'conflit signalé', array( 'wp_okno_activity' ) === $report['conflicts'], $report );
check( 'table Leiho remplie gardée', 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_leiho_activity' ) );
check( 'table Okno laissée en place', 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_okno_activity' ) );

/* --- 4. Filtres et actions okno_* encore appelés --- */
Leiho_Legacy::bridge_hooks();
add_filter( 'okno_settings', function ( $s ) { $s['from_okno'] = true; return $s; } );
add_filter( 'leiho_settings', function ( $s ) { $s['from_leiho'] = isset( $s['from_okno'] ); return $s; } );
$out = apply_filters( 'leiho_settings', array() );
check( 'filtre okno_ appelé, avant le filtre leiho_', ! empty( $out['from_okno'] ) && ! empty( $out['from_leiho'] ), $out );
check( 'dépréciation signalée', in_array( 'okno_settings', $GLOBALS['deprecated'], true ) );
$seen = null;
add_action( 'okno_content_changed', function ( $id ) use ( &$seen ) { $seen = $id; } );
do_action( 'leiho_content_changed', 12 );
check( 'action okno_ reçoit les arguments', 12 === $seen );
check( 'filtre sans écouteur okno_ : valeur intacte', 'v' === apply_filters( 'leiho_content_html', 'v' ) );

/* --- 5. Okno encore actif : Leiho attend --- */
delete_option( Leiho_Legacy::FLAG );
add_option( 'okno_settings', array( 'front_url' => 'x' ) );
define( 'OKNO_VERSION', '1.0.0-beta.11' );
check( 'Okno actif : pas de migration', null === Leiho_Legacy::maybe_migrate() );
check( 'Okno actif : ses données intactes', array( 'front_url' => 'x' ) === get_option( 'okno_settings' ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
