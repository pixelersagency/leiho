<?php
/**
 * Tests des mises à jour GitHub : php plugin/tests/test-updater.php
 */
define( 'ABSPATH', true );
define( 'LEIHO_FILE', '/var/www/wp-content/plugins/leiho/leiho.php' );
define( 'LEIHO_URL', 'https://wp.example.com/wp-content/plugins/leiho/' );
define( 'LEIHO_VERSION', '1.0.0-beta.3' );
define( 'HOUR_IN_SECONDS', 3600 );
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return preg_match( '#^https?://#', $s ) ? htmlspecialchars( $s, ENT_QUOTES ) : ''; }
function plugin_basename( $f ) { return 'leiho/leiho.php'; }
function home_url() { return 'https://wp.example.com'; }
function is_wp_error( $x ) { return false; }
$GLOBALS['transients'] = array();
$GLOBALS['http_calls'] = 0;
$GLOBALS['options'] = array();
$GLOBALS['readme'] = array( 'code' => 200, 'body' => "=== Leiho ===\nRequires at least: 6.0\nTested up to: 7.1\nStable tag: 1.0.0-beta.4\n" );
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_remote_get( $url, $args ) {
	$GLOBALS['last_headers'] = $args['headers'];
	if ( false !== strpos( $url, 'raw.githubusercontent.com' ) ) {
		$GLOBALS['readme_url'] = $url;
		return $GLOBALS['readme'];
	}
	$GLOBALS['http_calls']++;
	return $GLOBALS['http_response'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
require __DIR__ . '/../includes/class-leiho-updater.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

$release = array(
	'tag_name'     => 'v1.0.0-beta.4',
	'html_url'     => 'https://github.com/pixelersagency/leiho/releases/tag/v1.0.0-beta.4',
	'published_at' => '2026-09-26T10:00:00Z',
	'body'         => "### Added\n\n> Install by hand once.\n\n- Updates from **GitHub** in `wp-admin`\n- See [docs](https://github.com/pixelersagency/leiho)\n\n<script>alert(1)</script>",
	'assets'       => array(
		array( 'name' => 'leiho-1.0.0-beta.4.zip', 'browser_download_url' => 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0-beta.4/leiho-1.0.0-beta.4.zip' ),
		array( 'name' => 'leiho.zip', 'browser_download_url' => 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0-beta.4/leiho.zip' ),
	),
);

/* --- from_release --- */
$u = Leiho_Updater::from_release( $release );
check( 'version sans le v', '1.0.0-beta.4' === $u['version'], $u );
check( 'paquet = leiho.zip', 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0-beta.4/leiho.zip' === $u['package'], $u );
// Signature des archives (Ed25519, clé de test) : nom, version et empreinte.
$kp   = sodium_crypto_sign_keypair();
$pub  = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$zip  = 'PK fake zip';
$sig  = base64_encode( sodium_crypto_sign_detached( Leiho_Updater::signed_message( '1.0.0', $zip ), sodium_crypto_sign_secretkey( $kp ) ) );
check( 'signature valide acceptée', Leiho_Updater::verify( $zip, $sig, '1.0.0', $pub ) );
check( 'archive modifiée refusée', ! Leiho_Updater::verify( $zip . 'x', $sig, '1.0.0', $pub ) );
check( 'ancienne archive signée sous une autre version refusée', ! Leiho_Updater::verify( $zip, $sig, '9.0.0', $pub ) );
check( 'signature absente ou illisible refusée', ! Leiho_Updater::verify( $zip, '', '1.0.0', $pub ) && ! Leiho_Updater::verify( $zip, 'pas du base64 !', '1.0.0', $pub ) );
check( 'autre clé refusée', ! Leiho_Updater::verify( $zip, $sig, '1.0.0' ) );
check( 'version lue dans l\'adresse du paquet', '1.0.0-rc.1' === Leiho_Updater::package_version( 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0-rc.1/leiho.zip' ) );
check( 'clé publique de Leiho bien formée', 32 === strlen( base64_decode( Leiho_Updater::SIGNING_KEY, true ) ) );
check( 'paquet officiel reconnu, autres ignorés', Leiho_Updater::is_release_package( 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0/leiho.zip' ) && ! Leiho_Updater::is_release_package( 'https://github.com/pixelersagency/leiho/releases/download/v1.0.0/other.zip' ) && ! Leiho_Updater::is_release_package( 'https://evil.example/pixelersagency/leiho/releases/download/leiho.zip' ) );
check( 'autres extensions : filtre sans effet', false === Leiho_Updater::download_verified( false, 'https://downloads.wordpress.org/plugin/acf.zip' ) );

$foreign = $release;
$foreign['assets'][1]['browser_download_url'] = 'https://evil.example/leiho.zip';
check( 'archive hors des releases du dépôt officiel : ignorée', false === Leiho_Updater::from_release( $foreign ) );
check( 'slug leiho', 'leiho' === $u['slug'] );
check( 'release sans leiho.zip ignorée', false === Leiho_Updater::from_release( array( 'tag_name' => 'v2.0.0', 'assets' => array() ) ) );
check( 'brouillon ignoré', false === Leiho_Updater::from_release( array_merge( $release, array( 'draft' => true ) ) ) );
check( 'bêta plus récente détectée', version_compare( $u['version'], LEIHO_VERSION, '>' ) );
check( 'stable plus récente que la bêta', version_compare( '1.0.0', '1.0.0-beta.9', '>' ) );

/* --- check() : seulement pour Leiho --- */
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => json_encode( $release + array( 'name' => 'x', 'extra' => str_repeat( 'x', 10 ) ) ) );
check( 'autre plugin intouché', 'other' === Leiho_Updater::check( 'other', array(), 'hello/hello.php' ) );
$r = Leiho_Updater::check( false, array( 'Version' => LEIHO_VERSION ), 'leiho/leiho.php' );
check( 'mise à jour proposée', is_array( $r ) && '1.0.0-beta.4' === $r['version'], $r );
Leiho_Updater::check( false, array(), 'leiho/leiho.php' );
check( 'API appelée une seule fois (cache)', 1 === $GLOBALS['http_calls'], $GLOBALS['http_calls'] );
check( 'cache allégé', ! isset( $GLOBALS['transients']['leiho_update_release']['extra'] ) );
check( 'tested lu dans le readme de la release', isset( $r['tested'] ) && '7.1' === $r['tested'], $r );
check( 'readme lu au bon tag', false !== strpos( $GLOBALS['readme_url'], '/v1.0.0-beta.4/plugin/readme.txt' ), $GLOBALS['readme_url'] );
check( 'User-Agent sans adresse du site', 'Leiho/' . LEIHO_VERSION === $GLOBALS['last_headers']['User-Agent'], $GLOBALS['last_headers'] );
check( 'dernière release mémorisée', isset( $GLOBALS['options']['leiho_update_last_release']['tag_name'] ) );

/* --- erreur API : on garde la dernière release connue --- */
Leiho_Updater::flush();
$GLOBALS['http_response'] = array( 'code' => 403, 'body' => '{"message":"rate limit"}' );
$r = Leiho_Updater::check( false, array(), 'leiho/leiho.php' );
check( 'erreur API : dernière release connue proposée', is_array( $r ) && '1.0.0-beta.4' === $r['version'], $r );
Leiho_Updater::check( false, array(), 'leiho/leiho.php' );
check( 'échec mis en cache', 2 === $GLOBALS['http_calls'], $GLOBALS['http_calls'] );
$GLOBALS['options'] = array();
Leiho_Updater::flush();
check( 'erreur API sans release connue : rien', false === Leiho_Updater::check( false, array(), 'leiho/leiho.php' ) );

/* --- readme introuvable : pas de tested, mise à jour quand même --- */
Leiho_Updater::flush();
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => json_encode( $release ) );
$GLOBALS['readme'] = array( 'code' => 404, 'body' => '' );
$r = Leiho_Updater::check( false, array(), 'leiho/leiho.php' );
check( 'readme absent : mise à jour sans tested', is_array( $r ) && ! isset( $r['tested'] ), $r );

/* --- détails et notes --- */
Leiho_Updater::flush();
$GLOBALS['http_response'] = array( 'code' => 200, 'body' => json_encode( $release ) );
$d = Leiho_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'leiho' ) );
check( 'détails : version', '1.0.0-beta.4' === $d->version, $d );
check( 'détails : autre slug intouché', 'keep' === Leiho_Updater::details( 'keep', 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
$html = $d->sections['changelog'];
check( 'notes : titre et liste', false !== strpos( $html, '<h4>Added</h4>' ) && false !== strpos( $html, '<li>Updates from <strong>GitHub</strong> in <code>wp-admin</code></li>' ), $html );
check( 'notes : lien', false !== strpos( $html, '<a href="https://github.com/pixelersagency/leiho">docs</a>' ), $html );
check( 'notes : HTML échappé', false === strpos( $html, '<script>' ), $html );
check( 'notes : citation', false !== strpos( $html, '<blockquote><p>Install by hand once.</p></blockquote>' ) && false === strpos( $html, '&gt; Install' ), $html );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
