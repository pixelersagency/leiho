<?php
/**
 * Formulaires du site, sans WordPress : php plugin/tests/test-forms.php
 *
 * Réglages nettoyés, origine, robots, limites, validation, modèles
 * d'e-mail, envoi complet (notification, confirmation, copie gardée).
 */
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['secrets'] = array();
$GLOBALS['turnstile'] = null;
$GLOBALS['filters'] = array();

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['mails']      = array();
$GLOBALS['posts']      = array();
$GLOBALS['mail_ok']    = true;
$GLOBALS['env']        = 'production';
$GLOBALS['actions']    = array();

class WP_Error {
	public $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	public $data, $status;
	public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
}
class Leiho_Plugin {
	public static function settings() { return array( 'front_url' => 'https://pixelers.fr', 'preview_url' => 'https://preview.pixelers.fr' ); }
}
class Leiho_Secrets {
	public static function get( $n ) { return isset( $GLOBALS['secrets'][ $n ] ) ? $GLOBALS['secrets'][ $n ] : null; }
}
class Leiho_Headless {
	public static function client_ip() { return isset( $GLOBALS['ip'] ) ? $GLOBALS['ip'] : '203.0.113.7'; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = '' ) { return $s; }
function get_option( $k, $d = false ) { if ( 'admin_email' === $k ) { return 'admin@cms.test'; } if ( 'date_format' === $k ) { return 'Y-m-d'; } if ( 'time_format' === $k ) { return 'H:i'; } return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag ) { $GLOBALS['actions'][] = $tag; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url() { return 'https://wp.pixelers.fr'; }
function site_url() { return 'https://wp.pixelers.fr'; }
function wp_get_environment_type() { return $GLOBALS['env']; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return strip_tags( (string) $s ); }
function sanitize_email( $s ) { return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $s ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function wp_salt( $s ) { return 'salt'; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function wp_specialchars_decode( $s ) { return $s; }
function get_bloginfo( $k ) { return 'Pixelers'; }
function wp_date( $f ) { return '2026-11-27 10:00'; }
function wp_mail( $to, $subject, $body, $headers = array() ) { $type = isset( $GLOBALS['filters']['wp_mail_content_type'] ) ? call_user_func( $GLOBALS['filters']['wp_mail_content_type'] ) : 'text/html'; $GLOBALS['mails'][] = compact( 'to', 'subject', 'body', 'headers', 'type' ); return $GLOBALS['mail_ok']; }
function wp_slash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function absint( $n ) { return abs( (int) $n ); }
function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ] = $cb; }
function remove_filter( $tag ) { unset( $GLOBALS['filters'][ $tag ] ); }
function wp_remote_post( $url, $args ) { $GLOBALS['turnstile_calls'][] = $args['body']; return $GLOBALS['turnstile']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_check_comment_disallowed_list( $a, $e, $u, $c ) { return false !== stripos( $c, 'casino' ); }
function wp_insert_post( $p ) { $GLOBALS['posts'][] = $p; return count( $GLOBALS['posts'] ); }

require __DIR__ . '/../includes/class-leiho-forms.php';

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
function reset_run() {
	$GLOBALS['transients'] = array();
	$GLOBALS['mails']      = array();
	$GLOBALS['posts']      = array();
	$GLOBALS['actions']    = array();
	$GLOBALS['mail_ok']    = true;
}

// --- Réglages -------------------------------------------------------------
$saved = Leiho_Forms::sanitize(
	array(
		'forms'   => array(
			array(
				'id'          => ' Contact ',
				'label'       => 'Contact',
				'required'    => 'name, email,project  bad!field',
				'email_field' => 'email',
				'name_field'  => 'name',
				'store'       => '1',
				'notify'      => array( 'on' => '1', 'to' => 'team@pixelers.fr, not-an-email; boss@pixelers.fr', 'subject' => "New\r\nBcc: x@evil.test", 'body' => '{fields}' ),
				'confirm'     => array( 'on' => '1', 'subject' => 'Merci {name}', 'body' => 'Bonjour {name}, {project}' ),
			),
			array( 'id' => 'contact', 'label' => 'Doublon' ),
			array( 'id' => '../etc' ),
			array( 'id' => 'devis', 'delete' => '1' ),
			array( 'id' => '' ),
		),
		'origins'    => "https://staging.pixelers.fr/contact/\nftp://x.test\nhttp://localhost:4321",
		'protection' => array( 'require_origin' => '1', 'max_links' => '5', 'turnstile_site' => 'bad key!' ),
	)
);
check( 'un seul formulaire gardé (doublon, id invalide, supprimé, vide écartés)', array( 'contact' ) === array_keys( $saved['forms'] ), array_keys( $saved['forms'] ) );
$c = $saved['forms']['contact'];
check( 'champs obligatoires nettoyés', array( 'name', 'email', 'project' ) === $c['required'], $c['required'] );
check( 'destinataires : adresses valides seulement', 'team@pixelers.fr, boss@pixelers.fr' === $c['notify']['to'], $c['notify']['to'] );
check( 'objet sur une ligne (pas d\'en-tête injecté)', false === strpos( $c['notify']['subject'], "\n" ), $c['notify']['subject'] );
check( 'protection : clé Turnstile invalide écartée, origine exigée', '' === $saved['protection']['turnstile_site'] && true === $saved['protection']['require_origin'] );
check( 'origines : scheme://hôte[:port] http(s) seulement', array( 'https://staging.pixelers.fr', 'http://localhost:4321' ) === $saved['origins'], $saved['origins'] );
$GLOBALS['options'][ Leiho_Forms::OPTION ] = $saved;

// --- Origine --------------------------------------------------------------
check( 'origine : le site', Leiho_Forms::origin_allowed( 'https://pixelers.fr' ) );
check( 'origine : www du site', Leiho_Forms::origin_allowed( 'https://www.pixelers.fr' ) );
check( 'origine : aperçu', Leiho_Forms::origin_allowed( 'https://preview.pixelers.fr' ) );
check( 'origine : ajoutée à la main', Leiho_Forms::origin_allowed( 'https://staging.pixelers.fr' ) );
check( 'origine : sans en-tête refusée par défaut (script)', ! Leiho_Forms::origin_allowed( '' ) );
$GLOBALS['options'][ Leiho_Forms::OPTION ]['protection']['require_origin'] = false;
check( 'origine : sans en-tête acceptée si le front envoie depuis son serveur', Leiho_Forms::origin_allowed( '' ) );
$GLOBALS['options'][ Leiho_Forms::OPTION ]['protection']['require_origin'] = true;
check( 'origine : autre site refusé', ! Leiho_Forms::origin_allowed( 'https://evil.test' ) );
check( 'origine : faux sous-domaine refusé', ! Leiho_Forms::origin_allowed( 'https://pixelers.fr.evil.test' ) );
check( 'origine : null refusée', ! Leiho_Forms::origin_allowed( 'null' ) );
check( 'origine : localhost refusé en production', ! Leiho_Forms::origin_allowed( 'http://localhost:3000' ) );
$GLOBALS['env'] = 'local';
check( 'origine : localhost accepté en local', Leiho_Forms::origin_allowed( 'http://localhost:3000' ) );
$GLOBALS['env'] = 'production';

// --- Champs, robots, validation ------------------------------------------
$f = Leiho_Forms::fields( array( 'name' => ' Ada ', '_hp' => '', 'Email' => 'ada@x.test', 'bad name' => 'x', 'tags' => array( 'a', 'b', array( 'c' ) ), 'msg' => '<b>Hi</b>', 'obj' => new stdClass() ) );
check( 'champs : réservés et invalides écartés, tableaux joints, texte brut gardé', array( 'name' => 'Ada', 'email' => 'ada@x.test', 'tags' => 'a, b', 'msg' => '<b>Hi</b>' ) === $f, $f );
check( 'texte : contrôles et inversions de direction retirés, « < » gardé', "a < b\nc" === Leiho_Forms::text( "a\x00 < b\r\n\xE2\x80\xAEc" ), Leiho_Forms::text( "a\x00 < b\r\n\xE2\x80\xAEc" ) );
check( 'champ Turnstile écarté des champs', ! isset( Leiho_Forms::fields( array( 'cf-turnstile-response' => 'tok', 'name' => 'A' ) )['cf-turnstile-response'] ) );
check( 'trop gros : trop de paramètres', Leiho_Forms::too_large( array_fill( 0, 150, 'x' ) ) );
check( 'trop gros : trop de texte', Leiho_Forms::too_large( array( 'a' => str_repeat( 'x', 250000 ) ) ) );
check( 'taille normale', ! Leiho_Forms::too_large( array( 'a' => str_repeat( 'x', 9000 ) ) ) );
check( 'champs : 30 au plus', Leiho_Forms::MAX_FIELDS === count( Leiho_Forms::fields( array_fill_keys( array_map( function ( $i ) { return 'f' . $i; }, range( 1, 50 ) ), 'x' ) ) ) );
check( 'robot : champ piège rempli', Leiho_Forms::is_bot( array( '_hp' => 'http://spam' ) ) );
check( 'robot : envoi trop rapide', Leiho_Forms::is_bot( array( '_elapsed' => 400 ) ) );
check( 'humain : piège vide, 8 s', ! Leiho_Forms::is_bot( array( '_hp' => '', '_elapsed' => 8000 ) ) );
check( 'humain : sans _elapsed', ! Leiho_Forms::is_bot( array( 'name' => 'Ada' ) ) );
$errors = Leiho_Forms::validate( $c, array( 'name' => 'Ada', 'email' => 'pas-une-adresse' ) );
check( 'validation : obligatoire manquant et e-mail invalide', array( 'project' => 'required', 'email' => 'email' ) == $errors, $errors );

// --- Modèles --------------------------------------------------------------
$fields = array( 'name' => 'Ada', 'email' => 'ada@x.test', 'project' => "Ligne 1\nLigne 2", 'site' => '' );
$out    = Leiho_Forms::render( "{fields}\n{form} {site} {date} {name} {inconnu}", $fields, $c );
check( 'modèle : {fields} saute les champs vides', false !== strpos( $out, 'Name : Ada' ) && false === strpos( $out, 'Site :' ) && false !== strpos( $out, "Project : \nLigne 1\nLigne 2" ), $out );
check( 'modèle : {form} {site} {date} {name}, inconnu vidé', false !== strpos( $out, 'Contact Pixelers 2026-11-27 10:00 Ada ' ) && false === strpos( $out, '{inconnu}' ), $out );
$short = Leiho_Forms::render( '{project}', array( 'project' => str_repeat( 'spam ', 100 ) ), $c, 80 );
check( 'modèle : valeur coupée pour la confirmation', mb_strlen( $short ) <= 81 && '…' === mb_substr( $short, -1 ), $short );

// --- Envoi complet ---------------------------------------------------------
reset_run();
$ok = Leiho_Forms::submit( 'contact', array( 'name' => "Ada\r\nBcc: x@evil.test", 'email' => 'ada@x.test', 'project' => 'Refonte', '_hp' => '', '_elapsed' => 9000 ), 'https://pixelers.fr' );
check( 'envoi : succès 200', $ok instanceof WP_REST_Response && 200 === $ok->status && true === $ok->data['ok'], $ok );
check( 'envoi : notification + confirmation', 2 === count( $GLOBALS['mails'] ), $GLOBALS['mails'] );
$n = $GLOBALS['mails'][0];
check( 'notification : aux destinataires réglés', array( 'team@pixelers.fr', 'boss@pixelers.fr' ) === $n['to'], $n['to'] );
check( 'notification : Reply-To du visiteur, sans retour à la ligne', 0 === strpos( $n['headers'][0], 'Reply-To: "Ada' ) && false === strpos( $n['headers'][0], "\n" ) && false !== strpos( $n['headers'][0], '<ada@x.test>' ), $n['headers'] );
$conf = $GLOBALS['mails'][1];
check( 'confirmation : au visiteur, réponse à l\'équipe', 'ada@x.test' === $conf['to'] && 'Reply-To: team@pixelers.fr' === $conf['headers'][0], $conf );
check( 'copie gardée, notification notée envoyée', 1 === count( $GLOBALS['posts'] ) && 'sent' === $GLOBALS['posts'][0]['meta_input']['_leiho_mail'] && 'private' === $GLOBALS['posts'][0]['post_status'], $GLOBALS['posts'] );
check( 'champs gardés en métadonnée, titre sans nom du visiteur', 'Refonte' === $GLOBALS['posts'][0]['meta_input']['_leiho_fields']['project'] && 'Contact' === $GLOBALS['posts'][0]['post_title'], $GLOBALS['posts'][0] );
check( 'e-mails en texte brut, même si une extension impose le HTML', 'text/plain' === $n['type'] && in_array( 'Content-Type: text/plain; charset=UTF-8', $n['headers'], true ) && ! isset( $GLOBALS['filters']['wp_mail_content_type'] ), $n );
check( 'action leiho_form_received', in_array( 'leiho_form_received', $GLOBALS['actions'], true ) );

// Deuxième message de la même adresse : pas de deuxième confirmation.
$GLOBALS['mails'] = array();
Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'ADA@x.test', 'project' => 'Encore' ), 'https://pixelers.fr' );
check( 'confirmation : une par heure et par adresse', 1 === count( $GLOBALS['mails'] ), $GLOBALS['mails'] );

// Limite par IP : 5 envois, le 6e est refusé (2 déjà faits).
for ( $i = 0; $i < 3; $i++ ) {
	Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'a' . $i . '@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
}
$limited = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'z@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
check( 'limite par IP : 429', is_wp_error( $limited ) && 'leiho_form_rate_limited' === $limited->code && 429 === $limited->data['status'], $limited );
$GLOBALS['ip'] = '198.51.100.9';
check( 'autre IP : acceptée', Leiho_Forms::submit( 'contact', array( 'name' => 'Bob', 'email' => 'bob@x.test', 'project' => 'x' ), 'https://pixelers.fr' ) instanceof WP_REST_Response );

// Erreurs.
reset_run();
$bad = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'nope' ), 'https://pixelers.fr' );
check( 'invalide : 400 et champs en erreur', is_wp_error( $bad ) && 400 === $bad->data['status'] && array( 'project' => 'required', 'email' => 'email' ) == $bad->data['fields'], $bad );
check( 'invalide : rien envoyé ni gardé', ! $GLOBALS['mails'] && ! $GLOBALS['posts'] );
$unknown = Leiho_Forms::submit( 'devis', array( 'name' => 'Ada' ) );
check( 'formulaire inconnu : 404', is_wp_error( $unknown ) && 404 === $unknown->data['status'] );
$origin = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada' ), 'https://evil.test' );
check( 'origine refusée : 403', is_wp_error( $origin ) && 403 === $origin->data['status'] );
reset_run();
$bot = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'ada@x.test', 'project' => 'x', '_hp' => 'spam' ), 'https://pixelers.fr' );
check( 'robot : réponse de succès, rien envoyé ni gardé', $bot instanceof WP_REST_Response && ! $GLOBALS['mails'] && ! $GLOBALS['posts'] );

// Spam probable : gardé à part, sans e-mail, réponse de succès.
reset_run();
$GLOBALS['ip'] = '192.0.2.50';
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Spam', 'email' => 's@x.test', 'project' => str_repeat( 'http://x.test ', 6 ) ), 'https://pixelers.fr' );
check( 'trop de liens : succès apparent, gardé en spam, aucun e-mail', $r instanceof WP_REST_Response && ! $GLOBALS['mails'] && 'links' === $GLOBALS['posts'][0]['meta_input']['_leiho_spam'], $GLOBALS['posts'] );
reset_run();
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Spam', 'email' => 's@x.test', 'project' => 'Best CASINO bonus' ), 'https://pixelers.fr' );
check( 'mot interdit (Réglages → Discussion) : spam', $r instanceof WP_REST_Response && ! $GLOBALS['mails'] && 'disallowed' === $GLOBALS['posts'][0]['meta_input']['_leiho_spam'] );
reset_run();
check( 'trop gros : 413', 413 === Leiho_Forms::submit( 'contact', array( 'project' => str_repeat( 'x', 250000 ) ), 'https://pixelers.fr' )->data['status'] );

// Turnstile.
reset_run();
$GLOBALS['options'][ Leiho_Forms::OPTION ]['protection']['turnstile_site'] = '0x4AAAAAAAtest';
$GLOBALS['secrets']['turnstile_secret'] = '0x4AAAAAAAsecret';
$GLOBALS['turnstile_calls'] = array();
$GLOBALS['ip'] = '192.0.2.60';
$ok_fields = array( 'name' => 'Ada', 'email' => 'ada9@x.test', 'project' => 'x' );
$r = Leiho_Forms::submit( 'contact', $ok_fields, 'https://pixelers.fr' );
check( 'Turnstile : jeton absent refusé sans appel à Cloudflare', is_wp_error( $r ) && 'leiho_form_captcha' === $r->code && ! $GLOBALS['turnstile_calls'], $r );
$GLOBALS['turnstile'] = array( 'code' => 200, 'body' => '{"success":false}' );
$r = Leiho_Forms::submit( 'contact', $ok_fields + array( 'cf-turnstile-response' => 'bad' ), 'https://pixelers.fr' );
check( 'Turnstile : jeton refusé par Cloudflare', is_wp_error( $r ) && 'leiho_form_captcha' === $r->code );
$GLOBALS['turnstile'] = new WP_Error( 'http', 'timeout' );
$r = Leiho_Forms::submit( 'contact', $ok_fields + array( 'cf-turnstile-response' => 'tok' ), 'https://pixelers.fr' );
check( 'Turnstile : Cloudflare injoignable, envoi refusé (pas d\'ouverture silencieuse)', is_wp_error( $r ) && 'leiho_form_captcha' === $r->code );
$GLOBALS['turnstile'] = array( 'code' => 200, 'body' => '{"success":true}' );
$r = Leiho_Forms::submit( 'contact', $ok_fields + array( 'cf-turnstile-response' => 'tok' ), 'https://pixelers.fr' );
check( 'Turnstile : jeton valide accepté, secret et IP transmis', $r instanceof WP_REST_Response && '0x4AAAAAAAsecret' === end( $GLOBALS['turnstile_calls'] )['secret'] && '192.0.2.60' === end( $GLOBALS['turnstile_calls'] )['remoteip'] );
check( 'Turnstile : le jeton n\'est pas gardé dans le message', ! isset( end( $GLOBALS['posts'] )['meta_input']['_leiho_fields']['cf-turnstile-response'] ) );
$GLOBALS['turnstile_calls'] = array();
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada' ) + array( 'cf-turnstile-response' => 'tok' ), 'https://pixelers.fr' );
check( 'Turnstile : champs invalides d\'abord, jeton pas gâché', is_wp_error( $r ) && 'leiho_form_invalid' === $r->code && ! $GLOBALS['turnstile_calls'] );
unset( $GLOBALS['secrets']['turnstile_secret'] );

// Limite globale par formulaire et par heure (robots qui changent d'IP).
reset_run();
for ( $i = 0; $i < 30; $i++ ) {
	$GLOBALS['ip'] = '10.0.' . $i . '.1';
	Leiho_Forms::submit( 'contact', array( 'name' => 'Bot', 'email' => 'b' . $i . '@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
}
$GLOBALS['ip'] = '10.9.9.9';
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Bot', 'email' => 'z@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
check( '31e message de l\'heure, nouvelle IP : 429', is_wp_error( $r ) && 429 === $r->data['status'], $r );
check( 'confirmations : 20 par heure au plus, toutes adresses', 20 === count( array_filter( $GLOBALS['mails'], function ( $m ) { return is_string( $m['to'] ) && 0 === strpos( $m['to'], 'b' ); } ) ), count( $GLOBALS['mails'] ) );
$GLOBALS['ip'] = '203.0.113.99';

// E-mail en échec : gardé quand même, noté « failed », succès pour le visiteur.
reset_run();
$GLOBALS['mail_ok'] = false;
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'ada@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
check( 'e-mail en échec, copie gardée : succès', $r instanceof WP_REST_Response && 'failed' === $GLOBALS['posts'][0]['meta_input']['_leiho_mail'], $GLOBALS['posts'] );
// Sans copie gardée : l'échec remonte au visiteur.
reset_run();
$GLOBALS['mail_ok'] = false;
$GLOBALS['options'][ Leiho_Forms::OPTION ]['forms']['contact']['store'] = false;
$r = Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'ada@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
check( 'e-mail en échec, rien de gardé : 500', is_wp_error( $r ) && 'leiho_form_mail' === $r->code && 500 === $r->data['status'], $r );
// Notification vers l'administrateur si aucun destinataire.
reset_run();
$GLOBALS['options'][ Leiho_Forms::OPTION ]['forms']['contact']['notify']['to'] = '';
Leiho_Forms::submit( 'contact', array( 'name' => 'Ada', 'email' => 'ada2@x.test', 'project' => 'x' ), 'https://pixelers.fr' );
check( 'sans destinataire : l\'administrateur', array( 'admin@cms.test' ) === $GLOBALS['mails'][0]['to'], $GLOBALS['mails'] );

echo $failures ? "\n$failures échec(s)\n" : "\nTout est bon.\n";
exit( $failures ? 1 : 0 );
