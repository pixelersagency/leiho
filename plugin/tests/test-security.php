<?php
/**
 * Onglet Sécurité, sans WordPress : php plugin/tests/test-security.php
 *
 * Connexion protégée (erreur générique, essais limités par IP et
 * identifiant) et lecture du code du thème avant Leiho Zero.
 */
define( 'ABSPATH', '/tmp/' );

$GLOBALS['transients'] = array();
$GLOBALS['ip_filter']  = null;
$GLOBALS['theme_dir']  = '';
$GLOBALS['stylesheet'] = 'client-theme';

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_codes() { return array( $this->code ); }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class WP_User {}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = '' ) { return $s; }
function esc_url( $u ) { return $u; }
function wp_lostpassword_url() { return 'https://cms.test/wp-login.php?action=lostpassword'; }
function apply_filters( $tag, $value ) { return 'leiho_login_ip' === $tag && $GLOBALS['ip_filter'] ? $GLOBALS['ip_filter'] : $value; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_stylesheet() { return $GLOBALS['stylesheet']; }
function get_stylesheet_directory() { return $GLOBALS['theme_dir']; }
function get_template_directory() { return $GLOBALS['theme_dir']; }

require __DIR__ . '/../includes/class-leiho-headless.php';
require __DIR__ . '/../includes/class-leiho-zero.php';

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

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// Erreur générique : ni « identifiant inconnu » ni « mauvais mot de passe ».
foreach ( array( 'invalid_username', 'invalid_email', 'incorrect_password' ) as $code ) {
	$out = Leiho_Headless::login_gate( new WP_Error( $code, 'Unknown username ada.' ), 'ada' );
	check( "erreur $code : générique", is_wp_error( $out ) && 'leiho_login_failed' === $out->get_error_code() && false === strpos( $out->get_error_message(), 'ada' ), $out );
}
$other = new WP_Error( 'empty_password', 'The password field is empty.' );
check( 'autre erreur : inchangée', $other === Leiho_Headless::login_gate( $other, 'ada' ) );
$user = new WP_User();
check( 'connexion réussie : inchangée', $user === Leiho_Headless::login_gate( $user, 'ada' ) );

// Essais limités.
for ( $i = 1; $i < Leiho_Headless::LOGIN_MAX; $i++ ) {
	Leiho_Headless::login_failed( 'Ada', new WP_Error( 'leiho_login_failed' ) );
}
check( 'sous la limite : le bon mot de passe passe', $user === Leiho_Headless::login_gate( $user, 'ada' ) );
Leiho_Headless::login_failed( 'ada', new WP_Error( 'leiho_login_failed' ) );
$locked = Leiho_Headless::login_gate( $user, 'ADA ' );
check( 'à la limite : bloqué, même avec le bon mot de passe (casse et espaces ignorés)', is_wp_error( $locked ) && 'leiho_login_locked' === $locked->get_error_code(), $locked );
$count = (int) array_sum( $GLOBALS['transients'] );
Leiho_Headless::login_failed( 'ada', $locked );
check( 'essai bloqué : ne prolonge pas le compteur', $count === (int) array_sum( $GLOBALS['transients'] ) );
check( 'autre identifiant, même IP : libre', $user === Leiho_Headless::login_gate( $user, 'grace' ) );

$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
check( 'même identifiant, autre IP : libre', $user === Leiho_Headless::login_gate( $user, 'ada' ) );
$GLOBALS['ip_filter'] = '203.0.113.7';
check( 'filtre leiho_login_ip (proxy) : adresse réelle retenue', is_wp_error( Leiho_Headless::login_gate( $user, 'ada' ) ) );

Leiho_Headless::login_succeeded( 'ada' );
check( 'connexion réussie : compteur remis à zéro', $user === Leiho_Headless::login_gate( $user, 'ada' ) );

Leiho_Headless::login_failed( '', null );
check( 'identifiant vide : rien compté', array() === $GLOBALS['transients'], $GLOBALS['transients'] );

// Code du thème qui disparaîtrait avec lui.
$dir = sys_get_temp_dir() . '/leiho-theme-' . getmypid();
@mkdir( $dir . '/inc/deep/deeper', 0777, true );
@mkdir( $dir . '/vendor', 0777, true );
file_put_contents( $dir . '/functions.php', "<?php\nadd_action( 'init', function () { register_post_type( 'service', array() ); } );\nregister_nav_menus( array() );\n" );
file_put_contents( $dir . '/inc/fields.php', "<?php\nacf_add_local_field_group( array() );\n" );
file_put_contents( $dir . '/inc/deep/deeper/too-deep.php', "<?php\nregister_taxonomy( 'x', 'post' );\n" );
file_put_contents( $dir . '/vendor/lib.php', "<?php\nadd_shortcode( 'x', 'y' );\n" );
$GLOBALS['theme_dir'] = $dir;
$found = Leiho_Zero::theme_code();
sort( $found );
check( 'thème : types, champs ACF et menus trouvés (vendor et 3e niveau ignorés)', array( 'acf_add_local_field_group', 'register_nav_menus', 'register_post_type' ) === $found, $found );
$GLOBALS['stylesheet'] = 'leiho-zero';
check( 'Leiho Zero actif : rien à signaler', array() === Leiho_Zero::theme_code() );
array_map( 'unlink', array( $dir . '/functions.php', $dir . '/inc/fields.php', $dir . '/inc/deep/deeper/too-deep.php', $dir . '/vendor/lib.php' ) );

// Le thème livré se lit comme un thème.
$style = (string) file_get_contents( __DIR__ . '/../theme/leiho-zero/style.css' );
check( 'thème livré : en-tête Theme Name', false !== strpos( $style, 'Theme Name: Leiho Zero' ) );
check( 'thème livré : index.php présent', is_file( __DIR__ . '/../theme/leiho-zero/index.php' ) );

echo $failures ? "\nÉCHECS.\n" : "\nTOUS LES TESTS PASSENT\n";
exit( $failures ? 1 : 0 );
