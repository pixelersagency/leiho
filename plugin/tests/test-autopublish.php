<?php
/**
 * Tests de la publication automatique (marqueur en attente, vidage, verrou) :
 * php plugin/tests/test-autopublish.php
 */
define( 'ABSPATH', true );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['cron']       = array();
$GLOBALS['settings']   = array(
	'deploy_driver'       => 'fake',
	'min_deploy_interval' => 0,
	'auto_deploy'         => true,
	'auto_deploy_delay'   => 60,
);

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}
class WP_User { public $ID = 3; public $display_name = 'Claire'; }

function __( $s, $d = '' ) { return $s; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function current_user_can( $cap ) { return true; }
function wp_get_current_user() { return $GLOBALS['user']; }
function wp_generate_password( $l = 12, $s = true ) { static $n = 0; return 'x' . ( ++$n ); }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_cache_delete( ...$a ) { return true; }
function add_action( ...$a ) {}
function wp_next_scheduled( $h ) { return isset( $GLOBALS['cron'][ $h ] ) ? $GLOBALS['cron'][ $h ] : false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['cron'][ $h ] = $t; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['cron'][ $h ] ); }
function apply_filters( $tag, $value, ...$args ) {
	if ( 'okno_deploy_driver' === $tag && 'fake' === $args[0] ) {
		return $GLOBALS['driver'];
	}
	return $value;
}

/** $wpdb factice : juste ce que le verrou utilise, sur le tableau d'options. */
class Fake_Wpdb {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		if ( preg_match( "/^INSERT IGNORE .* VALUES \( '([^']*)', '([^']*)'/", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $GLOBALS['options'] ) ) {
				return 0;
			}
			$GLOBALS['options'][ $m[1] ] = $m[2];
			return 1;
		}
		if ( preg_match( "/^UPDATE .* SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'/", $sql, $m ) ) {
			if ( isset( $GLOBALS['options'][ $m[2] ] ) && (string) $GLOBALS['options'][ $m[2] ] === $m[3] ) {
				$GLOBALS['options'][ $m[2] ] = $m[1];
				return 1;
			}
			return 0;
		}
		throw new Exception( 'SQL inattendu : ' . $sql );
	}
	public function get_var( $sql ) {
		preg_match( "/option_name = '([^']*)'/", $sql, $m );
		return isset( $GLOBALS['options'][ $m[1] ] ) ? (string) $GLOBALS['options'][ $m[1] ] : null;
	}
	public function delete( $table, $where ) {
		unset( $GLOBALS['options'][ $where['option_name'] ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();

final class Okno_Plugin {
	public static function settings() { return $GLOBALS['settings']; }
	public static function bump_usage( $e ) {}
}

class Fake_Driver {
	public $calls  = 0;
	public $result = null; // WP_Error à renvoyer, sinon record « triggered ».
	public $during = null; // Callback exécuté pendant l'appel au service.
	public function get_id() { return 'fake'; }
	public function check_config() { return true; }
	public function supports_status() { return false; }
	public function trigger( array $record ) {
		$this->calls++;
		if ( $this->during ) {
			call_user_func( $this->during );
		}
		if ( $this->result ) {
			return $this->result;
		}
		$record['status'] = 'triggered';
		return $record;
	}
}

require __DIR__ . '/../includes/deploy/class-okno-deploy-manager.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

function reset_state() {
	$GLOBALS['options']    = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['cron']       = array();
	$GLOBALS['driver']     = new Fake_Driver();
	$GLOBALS['user']       = new WP_User();
	$GLOBALS['settings']['min_deploy_interval'] = 0;
	$GLOBALS['settings']['auto_deploy']         = true;
}

function pend( $ago = 0 ) {
	Okno_Deploy_Manager::mark_pending();
	if ( $ago ) {
		$GLOBALS['options'][ Okno_Deploy_Manager::OPTION_PENDING ] = sprintf( '%.4F', microtime( true ) - $ago );
	}
	Okno_Deploy_Manager::reschedule_auto( 0 );
}

$M = 'Okno_Deploy_Manager';

// Rien en attente : rien ne part.
reset_state();
$out = $M::flush_pending( 0 );
check( 'rien en attente : idle', 'idle' === $out['state'] && 0 === $GLOBALS['driver']->calls, $out );

// Vidage : publie une fois, efface le marqueur et le cron de secours.
reset_state();
pend();
check( 'marqueur posé', $M::pending_since() > 0 );
check( 'cron de secours planifié', (bool) wp_next_scheduled( $M::AUTO_HOOK ) );
$out = $M::flush_pending( 0 );
check( 'vidage : publié', 'published' === $out['state'] && 1 === $GLOBALS['driver']->calls, $out );
check( 'vidage : marqueur effacé', 0 === $M::pending_since() );
check( 'vidage : cron de secours retiré', false === wp_next_scheduled( $M::AUTO_HOOK ) );
check( 'vidage : verrou libéré', ! isset( $GLOBALS['options'][ $M::OPTION_LOCK ] ) );
check( 'vidage : historique « automatique »', 'Automatic publishing' === $out['record']['user_name'], $out['record'] );
check( 'vidage : utilisateur courant intact', 'Claire' === $GLOBALS['user']->display_name );
$out = $M::flush_pending( 0 );
check( 'second vidage : idle, pas de second deploy', 'idle' === $out['state'] && 1 === $GLOBALS['driver']->calls, $out );
$M::run_auto();
check( 'cron après vidage : pas de second deploy', 1 === $GLOBALS['driver']->calls );

// Heartbeat : attend la fin du délai de calme.
reset_state();
pend();
$hb = $M::heartbeat( array() );
check( 'heartbeat trop tôt : rien', 0 === $GLOBALS['driver']->calls && $hb['okno_autopublish']['pending'], $hb );
check( 'heartbeat trop tôt : échéance', $hb['okno_autopublish']['due_in'] > 50, $hb );
$GLOBALS['options'][ $M::OPTION_PENDING ] = sprintf( '%.4F', microtime( true ) - 61 );
$hb = $M::heartbeat( array() );
check( 'heartbeat après le délai : publié', 1 === $GLOBALS['driver']->calls && ! $hb['okno_autopublish']['pending'] && ! empty( $hb['okno_autopublish']['deploy_id'] ), $hb );
$hb = $M::heartbeat( array() );
check( 'heartbeat suivant : rien', 1 === $GLOBALS['driver']->calls && ! $hb['okno_autopublish']['pending'], $hb );

// Verrou tenu par un autre processus (cron en plein appel au driver).
reset_state();
pend();
$GLOBALS['options'][ $M::OPTION_LOCK ] = (string) time();
$out = $M::flush_pending( 0 );
check( 'verrou tenu : en attente, pas de deploy', 'pending' === $out['state'] && 0 === $GLOBALS['driver']->calls, $out );
$manual = $M::trigger();
check( 'verrou tenu : Publier refusé (409)', is_wp_error( $manual ) && 'okno_deploy_in_progress' === $manual->get_error_code() );
$M::run_auto();
check( 'verrou tenu : le cron se replanifie', 1 === get_option( $M::AUTO_ATTEMPTS ) && wp_next_scheduled( $M::AUTO_HOOK ) );
check( 'verrou tenu : marqueur gardé', $M::pending_since() > 0 );

// Verrou abandonné (fatal) : repris après LOCK_TTL.
$GLOBALS['options'][ $M::OPTION_LOCK ] = (string) ( time() - $M::LOCK_TTL - 5 );
$out = $M::flush_pending( 0 );
check( 'verrou abandonné : repris, publié', 'published' === $out['state'] && 1 === $GLOBALS['driver']->calls, $out );
check( 'verrou abandonné : libéré ensuite', ! isset( $GLOBALS['options'][ $M::OPTION_LOCK ] ) );

// Course : le second processus entre pendant l'appel au driver du premier.
reset_state();
pend();
$inner = null;
$GLOBALS['driver']->during = function () use ( &$inner ) {
	$inner = Okno_Deploy_Manager::flush_pending( 0 );
};
$out = $M::flush_pending( 0 );
check( 'course : un seul deploy', 1 === $GLOBALS['driver']->calls, $GLOBALS['driver']->calls );
check( 'course : le second attend', is_array( $inner ) && 'pending' === $inner['state'], $inner );

// Changement pendant le déclenchement : il garde sa publication.
reset_state();
pend();
$GLOBALS['driver']->during = function () {
	usleep( 1000 );
	Okno_Deploy_Manager::mark_pending();
	Okno_Deploy_Manager::reschedule_auto( 0 );
};
$out = $M::flush_pending( 0 );
check( 'changement en cours de route : publié', 'published' === $out['state'] );
check( 'changement en cours de route : reste en attente', $M::pending_since() > 0 );
check( 'changement en cours de route : cron gardé', (bool) wp_next_scheduled( $M::AUTO_HOOK ) );

// Garde-fous de trigger() : la publication reste en attente.
reset_state();
pend();
$GLOBALS['transients'][ $M::TRANSIENT_SAVE ] = 1;
$out = $M::flush_pending( 0 );
check( 'save en cours : en attente', 'pending' === $out['state'] && 0 === $GLOBALS['driver']->calls && $M::pending_since() > 0, $out );
$GLOBALS['transients'] = array();

$GLOBALS['settings']['min_deploy_interval'] = 300;
$GLOBALS['options'][ $M::OPTION_HISTORY ] = array( 'dep_old' => array( 'id' => 'dep_old', 'status' => 'triggered', 'started' => time() - 100 ) );
wp_clear_scheduled_hook( $M::AUTO_HOOK );
$out = $M::flush_pending( 0 );
check( 'rate limit : en attente', 'pending' === $out['state'] && 0 === $GLOBALS['driver']->calls, $out );
check( 'rate limit : délai restant', $out['retry_in'] >= 199 && $out['retry_in'] <= 201, $out['retry_in'] );
check( 'rate limit : cron de secours replanifié', (bool) wp_next_scheduled( $M::AUTO_HOOK ) );

// Service de build injoignable : passager, la publication reste en attente
// et le cron retente, un nombre limité de fois.
reset_state();
pend();
$GLOBALS['driver']->result = new WP_Error( 'okno_http', 'Service down' );
$out = $M::flush_pending( 0 );
check( 'service injoignable : en attente', 'pending' === $out['state'] && 1 === $GLOBALS['driver']->calls, $out );
check( 'service injoignable : marqueur gardé, cron replanifié', $M::pending_since() > 0 && (bool) wp_next_scheduled( $M::AUTO_HOOK ) );

// Toujours en échec après les essais : abandon (visible dans l'historique), pas de boucle.
$GLOBALS['options'][ $M::AUTO_ATTEMPTS ] = $M::SERVICE_RETRIES;
$out = $M::flush_pending( 0 );
check( 'erreur définitive : failed', 'failed' === $out['state'], $out );
check( 'erreur définitive : marqueur abandonné', 0 === $M::pending_since() && false === wp_next_scheduled( $M::AUTO_HOOK ) );

// Réglage décoché depuis le changement.
reset_state();
pend();
$GLOBALS['settings']['auto_deploy'] = false;
$out = $M::flush_pending( 0 );
check( 'réglage décoché : rien', 'idle' === $out['state'] && 0 === $GLOBALS['driver']->calls && 0 === $M::pending_since(), $out );
$GLOBALS['settings']['auto_deploy'] = true;

// « Publier » à la main emporte les changements en attente.
reset_state();
pend();
$record = $M::trigger();
check( 'Publier : deploy', ! is_wp_error( $record ) && 1 === $GLOBALS['driver']->calls );
check( 'Publier : marqueur effacé', 0 === $M::pending_since() && false === wp_next_scheduled( $M::AUTO_HOOK ) );
check( 'Publier : nom de l’utilisateur', 'Claire' === $record['user_name'] );
$M::run_auto();
check( 'cron après Publier : rien', 1 === $GLOBALS['driver']->calls );

// Cron : publie quand personne d'autre ne l'a fait.
reset_state();
pend();
$M::run_auto();
check( 'cron seul : publié', 1 === $GLOBALS['driver']->calls && 0 === $M::pending_since() );

echo empty( $GLOBALS['failed'] ) ? "\nTout est vert.\n" : "\nÉCHECS.\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
