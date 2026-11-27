<?php
/**
 * Envoi des e-mails, sans WordPress : php plugin/tests/test-mail.php
 *
 * Réglages SMTP nettoyés, configuration de PHPMailer, expéditeur, alertes
 * d'échec regroupées (Slack, Telegram) sans données personnelles.
 */
define( 'ABSPATH', '/tmp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options']   = array();
$GLOBALS['secrets']   = array();
$GLOBALS['remote']    = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['remote_code'] = 200;

class WP_Error {
	public $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class Leiho_Secrets {
	public static function get( $n ) { return isset( $GLOBALS['secrets'][ $n ] ) ? $GLOBALS['secrets'][ $n ] : null; }
}
class Leiho_Plugin {
	public static function remote( $method, $url, $args ) { $GLOBALS['remote'][] = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) ); return array( 'code' => $GLOBALS['remote_code'] ); }
}
class Leiho_App {
	public static function url( $view, $args = array() ) { return 'https://wp.test/wp-admin/admin.php?page=leiho&view=' . $view . '&tab=' . $args['tab']; }
}
class FakeMailer {
	public $smtp = false;
	public $Host, $Port, $SMTPSecure, $SMTPAutoTLS, $SMTPAuth, $Username, $Password, $Timeout;
	public function isSMTP() { $this->smtp = true; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function sanitize_email( $s ) { return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $s ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function absint( $n ) { return abs( (int) $n ); }
function get_bloginfo() { return 'Pixelers'; }
function wp_specialchars_decode( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_next_scheduled( $h ) { return isset( $GLOBALS['scheduled'][ $h ] ) ? $GLOBALS['scheduled'][ $h ] : false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'][ $h ] = $t; return true; }

require __DIR__ . '/../includes/class-leiho-mail.php';

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

// --- Réglages -------------------------------------------------------------
$s = Leiho_Mail::sanitize(
	array(
		'smtp'          => '1',
		'host'          => ' SMTP-relay.Brevo.com ',
		'port'          => '99999',
		'encryption'    => 'starttls',
		'auth'          => '1',
		'username'      => ' user@x.test ',
		'from_email'    => 'not an email',
		'from_name'     => "Pixelers\r\nBcc: <x@evil.test>",
		'log_days'      => '3',
		'telegram_chat' => '-1001234567890',
	)
);
check( 'hôte en minuscules', 'smtp-relay.brevo.com' === $s['host'], $s['host'] );
check( 'port borné', 65535 === $s['port'] );
check( 'chiffrement inconnu : tls', 'tls' === $s['encryption'] );
check( 'adresse invalide écartée', '' === $s['from_email'] );
check( 'nom sur une ligne, sans < > "', false === strpbrk( $s['from_name'], "\r\n<>\"" ), $s['from_name'] );
check( 'durée inconnue : 3 mois', 90 === $s['log_days'] );
check( 'durée « toujours » gardée', 0 === Leiho_Mail::sanitize( array( 'log_days' => '0' ) )['log_days'] );
check( 'durée 1 an gardée', 365 === Leiho_Mail::sanitize( array( 'log_days' => '365' ) )['log_days'] );
check( 'cases absentes = décochées', false === $s['force_from'] && false === $s['log'] && false === $s['alert_slack'] );
check( 'chat Telegram numérique gardé', '-1001234567890' === $s['telegram_chat'] );
check( 'hôte invalide refusé', '' === Leiho_Mail::sanitize( array( 'host' => 'smtp.x.test/evil' ) )['host'] );
check( 'chat Telegram invalide refusé', '' === Leiho_Mail::sanitize( array( 'telegram_chat' => 'abc def' ) )['telegram_chat'] );

// --- PHPMailer --------------------------------------------------------------
$m = new FakeMailer();
Leiho_Mail::configure( $m );
check( 'SMTP éteint : PHPMailer inchangé', ! $m->smtp );
$GLOBALS['options'][ Leiho_Mail::OPTION ] = array( 'smtp' => true, 'host' => 'smtp.x.test', 'port' => 465, 'encryption' => 'ssl', 'auth' => true, 'username' => 'u', 'from_email' => 'contact@x.test', 'from_name' => 'X', 'force_from' => false );
$GLOBALS['secrets']['smtp_password'] = 's3cret';
$m = new FakeMailer();
Leiho_Mail::configure( $m );
check( 'SMTP réglé', $m->smtp && 'smtp.x.test' === $m->Host && 465 === $m->Port && 'ssl' === $m->SMTPSecure && $m->SMTPAuth && 'u' === $m->Username && 's3cret' === $m->Password, $m );
check( 'expéditeur : wordpress@ remplacé', 'contact@x.test' === Leiho_Mail::from_email( 'wordpress@x.test' ) );
check( 'expéditeur : celui d\'une extension gardé (sans « pour tous »)', 'shop@x.test' === Leiho_Mail::from_email( 'shop@x.test' ) );
check( 'nom : WordPress remplacé', 'X' === Leiho_Mail::from_name( 'WordPress' ) && 'Shop' === Leiho_Mail::from_name( 'Shop' ) );
$GLOBALS['options'][ Leiho_Mail::OPTION ]['force_from'] = true;
check( 'expéditeur forcé pour tous', 'contact@x.test' === Leiho_Mail::from_email( 'shop@x.test' ) );

// --- Destinataires ----------------------------------------------------------
check( 'destinataires en texte', 'a@x.test, b@x.test' === Leiho_Mail::recipients( array( 'a@x.test', ' b@x.test ' ) ) && 'a@x.test, b@x.test' === Leiho_Mail::recipients( 'a@x.test,b@x.test' ) );

// --- Alertes ---------------------------------------------------------------
check( 'erreur sans adresse e-mail', 'SMTP Error: The following recipients failed: [e-mail]' === Leiho_Mail::scrub( 'SMTP Error: The following recipients failed: ada@x.test' ), Leiho_Mail::scrub( 'SMTP Error: The following recipients failed: ada@x.test' ) );

// Sans alerte réglée : compté, rien envoyé.
Leiho_Mail::note_failure( 'boom' );
check( 'échec compté pour l\'admin', 1 === Leiho_Mail::failures()['unseen'] );
check( 'aucune alerte réglée : rien envoyé', ! $GLOBALS['remote'] );

$GLOBALS['options'][ Leiho_Mail::OPTION ]['alert_slack']    = true;
$GLOBALS['options'][ Leiho_Mail::OPTION ]['alert_telegram'] = true;
$GLOBALS['options'][ Leiho_Mail::OPTION ]['telegram_chat']  = '-100123';
$GLOBALS['secrets']['slack_webhook']  = 'https://hooks.slack.com/services/T/B/x';
$GLOBALS['secrets']['telegram_token'] = '123456:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

Leiho_Mail::note_failure( 'SMTP connect() failed for ada@x.test' );
check( 'premier échec : Slack et Telegram', 2 === count( $GLOBALS['remote'] ), $GLOBALS['remote'] );
$slack = $GLOBALS['remote'][0];
check( 'Slack : 2 échecs regroupés (le précédent compris)', false !== strpos( $slack['body']['text'], '2 e-mails could not be sent' ), $slack['body'] );
check( 'alerte sans adresse du visiteur', false === strpos( $slack['body']['text'], 'ada@x.test' ) && false !== strpos( $slack['body']['text'], '[e-mail]' ), $slack['body'] );
check( 'alerte : lien vers l\'onglet', false !== strpos( $slack['body']['text'], 'view=forms&tab=mail' ) );
check( 'Telegram : bonne adresse et chat', 'https://api.telegram.org/bot123456:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage' === $GLOBALS['remote'][1]['url'] && '-100123' === $GLOBALS['remote'][1]['body']['chat_id'], $GLOBALS['remote'][1] );

// Dans l'heure : regroupé, pas d'envoi, un rattrapage programmé.
$GLOBALS['remote'] = array();
Leiho_Mail::note_failure( 'again' );
Leiho_Mail::note_failure( 'again' );
check( 'dans l\'heure : aucune nouvelle alerte', ! $GLOBALS['remote'] );
check( 'rattrapage programmé une heure après la dernière alerte', isset( $GLOBALS['scheduled'][ Leiho_Mail::ALERT_HOOK ] ) && abs( $GLOBALS['scheduled'][ Leiho_Mail::ALERT_HOOK ] - ( time() + 3600 ) ) < 5 );
check( 'deux échecs en attente', 2 === Leiho_Mail::failures()['pending'] );
check( 'quatre échecs non vus', 4 === Leiho_Mail::failures()['unseen'] );
Leiho_Mail::flush_alert();
check( 'rattrapage : une alerte groupée', 2 === count( $GLOBALS['remote'] ) && false !== strpos( $GLOBALS['remote'][0]['body']['text'], '2 e-mails' ), $GLOBALS['remote'] );
$GLOBALS['remote'] = array();
Leiho_Mail::flush_alert();
check( 'rien en attente : pas d\'alerte vide', ! $GLOBALS['remote'] );
Leiho_Mail::mark_seen();
check( 'vu : l\'avertissement disparaît', 0 === Leiho_Mail::failures()['unseen'] );

// Erreur du service.
$GLOBALS['remote_code'] = 404;
$r = Leiho_Mail::send_alert( 'test' );
check( 'service en erreur : message lisible', is_string( $r['slack'] ) && false !== strpos( $r['slack'], '404' ), $r );
$GLOBALS['remote_code']              = 200;
$GLOBALS['secrets']['slack_webhook'] = 'http://hooks.slack.com/x';
$r = Leiho_Mail::send_alert( 'test' );
$GLOBALS['secrets']['telegram_token'] = '123:ABC/../evil';
$bad = Leiho_Mail::send_alert( 'test' );
check( 'jeton Telegram mal formé : refusé', is_string( $bad['telegram'] ), $bad );
check( 'webhook en http : refusé', is_string( $r['slack'] ) && true === $r['telegram'], $r );

echo $failures ? "\n$failures échec(s)\n" : "\nTout est bon.\n";
exit( $failures ? 1 : 0 );
