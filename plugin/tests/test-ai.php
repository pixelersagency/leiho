<?php
/**
 * Tests de l'IA de Leiho, sans WordPress ni réseau : php plugin/tests/test-ai.php
 *
 * Assemblage des prompts (contexte de marque + entrée), contrôle des
 * formulations interdites et de la longueur, réponse JSON → valeurs de
 * champs (nouvelle entrée), génération par un faux constructeur de prompt
 * (repli sans options natives, erreurs lisibles), capacités (enregistrement,
 * permissions) et routes REST (permissions, bornes, limite de débit).
 */
define( 'ABSPATH', true );

/* ---------------------------------------------------------------------
 * Stubs WordPress
 * ------------------------------------------------------------------- */

$GLOBALS['caps']       = array( 'edit_posts' => true, 'manage_options' => true );
$GLOBALS['post_caps']  = array();
$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['filters']    = array();
$GLOBALS['routes']     = array();
$GLOBALS['abilities']  = array();
$GLOBALS['categories'] = array();
$GLOBALS['posts']      = array();
$GLOBALS['ai_log']     = array();

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $d ) { $this->data = $d; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request implements ArrayAccess {
	private $params;
	private $body;
	public function __construct( array $params = array(), $body = null ) {
		$this->params = $params;
		$this->body   = null === $body ? json_encode( $params ) : $body;
	}
	public function get_param( $k ) { return isset( $this->params[ $k ] ) ? $this->params[ $k ] : null; }
	public function get_json_params() { return $this->params; }
	public function get_body() { return $this->body; }
	#[\ReturnTypeWillChange]
	public function offsetExists( $k ) { return isset( $this->params[ $k ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $k ) { return $this->get_param( $k ); }
	#[\ReturnTypeWillChange]
	public function offsetSet( $k, $v ) { $this->params[ $k ] = $v; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $k ) { unset( $this->params[ $k ] ); }
}

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === (int) $n ? $a : $b; }
function add_action( ...$a ) {}
function add_filter( $tag, $cb ) { $GLOBALS['filters'][ $tag ][] = $cb; }
function remove_filter( $tag, $cb ) {
	if ( isset( $GLOBALS['filters'][ $tag ] ) ) {
		$GLOBALS['filters'][ $tag ] = array_values( array_filter( $GLOBALS['filters'][ $tag ], static function ( $f ) use ( $cb ) { return $f !== $cb; } ) );
	}
}
function apply_filters( $tag, $value, ...$args ) {
	foreach ( isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function current_user_can( $cap, $id = 0 ) {
	if ( $id ) {
		return ! empty( $GLOBALS['post_caps'][ $cap . ':' . $id ] );
	}
	return ! empty( $GLOBALS['caps'][ $cap ] );
}
function get_current_user_id() { return 7; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $d = '', $a = null ) { if ( array_key_exists( $k, $GLOBALS['options'] ) ) { return false; } $GLOBALS['options'][ $k ] = $v; return true; }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; } }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_kses_post( $v ) { return preg_replace( '#<script.*?</script>#is', '', (string) $v ); }
function admin_url( $p = '' ) { return 'https://wp.test/wp-admin/' . $p; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][ $ns . $route ] = $args; }
function rest_ensure_response( $v ) { return $v; }
function get_post_type( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ]->post_type : false; }
function get_post( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null; }
function get_post_type_object( $t ) {
	return (object) array( 'labels' => (object) array( 'name' => ucfirst( $t ) . 's', 'singular_name' => ucfirst( $t ) ), 'cap' => (object) array( 'create_posts' => 'edit_posts' ) );
}
function wp_register_ability_category( $slug, $args ) { $GLOBALS['categories'][ $slug ] = $args; return true; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; return true; }

/* ---------------------------------------------------------------------
 * Stubs Leiho (le reste du plugin n'est pas chargé)
 * ------------------------------------------------------------------- */

class Leiho_Rest {
	const NS = 'leiho/v1';
	public function __construct( $p = null ) {}
	public function can_create_type( $t ) { return in_array( $t, Leiho_Plugin::settings()['post_types'], true ) && current_user_can( 'edit_posts' ); }
	public function post_item( $post ) { return array( 'id' => $post->ID, 'title' => $post->post_title, 'type' => $post->post_type, 'status' => $post->post_status ); }
}
class Leiho_Plugin {
	public static function instance() { return new self(); }
	public static function settings() { return array( 'post_types' => array( 'page', 'service' ) ); }
	public function adapters() { return array(); }
	public static function bump_usage( $e ) { $GLOBALS['usage'][] = $e; }
	public static function title( $post ) { return $post->post_title; }
}
class Leiho_Schema {
	const PATH_TITLE = '_title';
	public static $fixture = null;
	public static $saved   = array();
	public function __construct( $p = null ) {}
	public static $by_locale = array();
	public function get( $id ) { return isset( self::$by_locale[ Leiho_I18n::current() ] ) ? self::$by_locale[ Leiho_I18n::current() ] : self::$fixture; }
	public function save( $id, $values, $revision = null ) {
		if ( null !== $revision && self::revision( $id ) !== $revision ) {
			return array( 'saved' => array(), 'errors' => array(), 'schema' => null, 'conflict' => true );
		}
		self::$saved[ $id ] = $values;
		return array( 'saved' => array_keys( $values ), 'errors' => array(), 'schema' => null );
	}
	public static function revision( $id ) { return 'rev1'; }
}
/** Langues : en par défaut, fr déclarée. */
class Leiho_I18n {
	public static $suggested = array();
	public static function suggest( $id, $locale, $values ) { self::$suggested[ $id ][ $locale ] = $values; }
	public static $current = 'en';
	public static $statuses = array();
	public static function enabled() { return true; }
	public static function default_locale() { return 'en'; }
	public static function codes() { return array( 'en', 'fr' ); }
	public static function valid( $c ) { $c = strtolower( (string) $c ); return in_array( $c, self::codes(), true ) ? $c : null; }
	public static function label( $c ) { return 'fr' === $c ? 'French' : 'English'; }
	public static function current() { return self::$current; }
	public static function status( $id, $c ) { return isset( self::$statuses[ $c ] ) ? self::$statuses[ $c ] : 'partial'; }
	public static function in_locale( $c, $fn ) { $prev = self::$current; self::$current = $c; try { return $fn(); } finally { self::$current = $prev; } }
}
class Leiho_Collections {
	public static function for_post_type( $t ) { return 'service' === $t ? array( 'id' => 'service' ) : null; }
}
class Leiho_Native_Fields {
	public static function content_fields( $post ) {
		return array( array( 'path' => '_excerpt', 'type' => 'textarea', 'label' => 'Excerpt', 'value' => '' ) );
	}
}
class Leiho_App {
	public static function url( $v = '' ) { return 'https://wp.test/wp-admin/admin.php?page=leiho&view=' . $v; }
	public static function content_types() { return array(); }
}
class Leiho_Collections_Rest { public static function can_manage() { return current_user_can( 'manage_options' ); } }
class Leiho_Deploy_Manager { const TRANSIENT_SAVE = 'leiho_saving'; }
class Leiho_Url_Mapper {
	public function path( $post ) { return '/' . $post->post_type . '/' . $post->ID; }
	public function front_url( $post ) { return 'https://site.test' . $this->path( $post ); }
}
class Leiho_Content {
	public static function post_types() { return Leiho_Plugin::settings()['post_types']; }
}
/** Requête : filtre les faux contenus comme WP_Query le ferait (type, statut, titre). */
class WP_Query {
	public static $last = null;
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 0;
	public function __construct( $args ) {
		self::$last = $args;
		foreach ( $GLOBALS['posts'] as $post ) {
			if ( ! in_array( $post->post_type, (array) $args['post_type'], true ) || ! in_array( $post->post_status, (array) $args['post_status'], true ) ) {
				continue;
			}
			if ( ! empty( $args['s'] ) && false === stripos( $post->post_title, $args['s'] ) ) {
				continue;
			}
			$this->posts[] = $post;
		}
		$this->found_posts   = count( $this->posts );
		$this->max_num_pages = (int) ceil( $this->found_posts / $args['posts_per_page'] );
		$this->posts         = array_slice( $this->posts, ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
	}
}
function mysql2date( $f, $d, $t = true ) { return '2026-11-04T10:00:00+00:00'; }
abstract class Leiho_Field_Adapter {}

require __DIR__ . '/../includes/class-leiho-activity.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-prompt.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-brand.php';
require __DIR__ . '/../includes/ai/class-leiho-ai.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-rest.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-translate.php';
require __DIR__ . '/../includes/ai/class-leiho-ai-abilities.php';

/**
 * Faux constructeur de prompt : mêmes méthodes (snake_case) que
 * WP_AI_Client_Prompt_Builder, aucune requête.
 */
class Fake_Builder {
	public static $log       = array();
	public static $answers   = array();
	public static $native_ok = true;
	public static $image_ok  = false;
	public static $error     = null;
	public $calls = array();
	public $prompt;
	public function __construct( $prompt ) { $this->prompt = $prompt; }
	public function __call( $name, $args ) {
		$this->calls[] = $name;
		if ( 'is_supported_for_text_generation' === $name ) {
			$native = in_array( 'using_system_instruction', $this->calls, true ) || in_array( 'as_json_response', $this->calls, true );
			$image  = in_array( 'with_file', $this->calls, true );
			return ( ! $native || self::$native_ok ) && ( ! $image || self::$image_ok );
		}
		if ( 'generate_text' === $name ) {
			self::$log[] = array( 'prompt' => $this->prompt, 'calls' => $this->calls );
			if ( self::$error ) {
				return self::$error;
			}
			return array_shift( self::$answers );
		}
		return $this;
	}
}
Leiho_AI::$factory = static function ( $prompt ) {
	return new Fake_Builder( $prompt );
};

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

/* ---------------------------------------------------------------------
 * Contexte de marque
 * ------------------------------------------------------------------- */

$brand = Leiho_AI_Brand::normalize(
	array(
		'name'      => 'Dyese',
		'offer'     => 'Audits de qualité de l’air intérieur.',
		'audience'  => 'Gestionnaires de bâtiments',
		'tone'      => 'Direct, expert, chaleureux.',
		'language'  => 'French (France)',
		'prefer'    => "mesure\nrapport clair",
		'avoid'     => array( 'révolutionnaire', '' ),
		'forbidden' => "COFRAC\ncertifié\n4 h\n/audit[- ]ready/i\n/(unclosed/\nobligation légale",
		'facts'     => array( 'Téléphone : 01 23 45 67 89' ),
		'samples'   => array( 'Un exemple de texte.', '', 'Deux', 'Trois', 'Quatre' ),
		'collections' => array( 'service' => array( 'purpose' => 'Une page par prestation.', 'audience' => '' ), 'Bad Key!' => 'x' ),
	)
);
check( 'marque : listes depuis un texte à une entrée par ligne', array( 'mesure', 'rapport clair' ) === $brand['prefer'], $brand['prefer'] );
check( 'marque : entrées vides retirées', array( 'révolutionnaire' ) === $brand['avoid'] );
check( 'marque : règle /…/ invalide écartée', ! in_array( '/(unclosed/', $brand['forbidden'], true ) && in_array( '/audit[- ]ready/i', $brand['forbidden'], true ), $brand['forbidden'] );
check( 'marque : trois exemples au plus', 3 === count( $brand['samples'] ) && 'Trois' === $brand['samples'][2], $brand['samples'] );
check( 'marque : contexte de collection gardé', 'Une page par prestation.' === $brand['collections']['service']['purpose'] && 1 === count( $brand['collections'] ), $brand['collections'] );

Leiho_AI_Brand::save( $brand );
$saved = Leiho_AI_Brand::get();
check( 'marque : enregistrée et relue', 'Dyese' === $saved['name'] && $saved['forbidden'] === $brand['forbidden'] );
Leiho_AI_Brand::save( array( 'name' => 'Dyese 2' ) );
check( 'marque : les contextes de collection survivent à un enregistrement sans eux', isset( Leiho_AI_Brand::get()['collections']['service'] ) );
Leiho_AI_Brand::save( $brand );
Leiho_AI_Brand::save_collection( 'service', array( 'purpose' => 'Prestations', 'audience' => 'Syndics' ) );
check( 'collection : contexte enregistré', 'Syndics' === Leiho_AI_Brand::collection( 'service' )['audience'] );
Leiho_AI_Brand::save_collection( 'page', array( 'purpose' => '', 'audience' => '' ) );
check( 'collection : contexte vide retiré', ! isset( Leiho_AI_Brand::get()['collections']['page'] ) );
check( 'export : marqueur leiho=brand', 'brand' === Leiho_AI_Brand::export()['leiho'] );
$brand = Leiho_AI_Brand::get();

/* ---------------------------------------------------------------------
 * Assemblage des prompts
 * ------------------------------------------------------------------- */

$system = Leiho_AI_Prompt::system_instruction( $brand, Leiho_AI_Brand::collection( 'service' ) );
check( 'système : nom de la marque', false !== strpos( $system, 'for Dyese' ) );
check( 'système : ton', false !== strpos( $system, 'Tone of voice: Direct, expert, chaleureux.' ) );
check( 'système : interdits listés', false !== strpos( $system, 'FORBIDDEN CLAIMS' ) && false !== strpos( $system, '- "COFRAC"' ) && false !== strpos( $system, 'the pattern /audit[- ]ready/i' ) );
check( 'système : faits obligatoires', false !== strpos( $system, '- Téléphone : 01 23 45 67 89' ) );
check( 'système : langue', false !== strpos( $system, 'Write in French (France)' ) );
check( 'système : contexte de la collection', false !== strpos( $system, 'Purpose: Prestations' ) && false !== strpos( $system, 'Readers: Syndics' ) );
check( 'système : exemples', false !== strpos( $system, '--- Example 1 ---' ) );
check( 'système : sans marque, pas de nom inventé', 0 === strpos( Leiho_AI_Prompt::system_instruction( array() ), 'You write website content.' ) );

$schema = array(
	'groups' => array(
		array(
			'fields' => array(
				array( 'path' => '_title', 'label' => 'Titre', 'type' => 'text', 'value' => 'Audit QAI' ),
				array( 'path' => 'hero_title', 'label' => 'Titre du hero', 'type' => 'text', 'max_length' => 60, 'help' => 'Court.', 'value' => 'Respirez mieux' ),
				array( 'path' => 'intro', 'label' => 'Introduction', 'type' => 'wysiwyg', 'value' => '<p>Nous <strong>mesurons</strong> l’air.</p>' ),
				array( 'path' => 'seo_description', 'label' => 'Meta description', 'type' => 'textarea', 'value' => '' ),
				array(
					'path' => 'sections', 'label' => 'Sections', 'type' => 'flexible_content',
					'layouts' => array( array( 'name' => 'text', 'label' => 'Texte', 'sub_fields' => array( array( 'path' => 'body', 'label' => 'Corps', 'type' => 'textarea' ) ) ) ),
					'value' => array( array( '_layout' => 'text', 'body' => 'Section un.' ) ),
				),
				array( 'path' => 'photo', 'label' => 'Photo', 'type' => 'image', 'value' => 12 ),
				array( 'path' => 'map', 'label' => 'Carte', 'type' => 'google_map', 'supported' => false, 'value' => 'x' ),
			),
		),
	),
);
$texts = Leiho_AI_Prompt::entry_texts( $schema );
$paths = array_column( $texts, 'path' );
check( 'contexte : champs texte de l’entrée', array( '_title', 'hero_title', 'intro', 'sections.0.body' ) === $paths, $paths );
check( 'contexte : HTML retiré', 'Nous mesurons l’air.' === $texts[2]['value'], $texts[2] );
check( 'contexte : libellé composé', 'Sections › #1 › Corps' === $texts[3]['label'], $texts[3] );

$field  = Leiho_AI_Rest::field_def( $schema, 'hero_title' );
$prompt = Leiho_AI_Prompt::field_prompt( 'shorten', array( 'field' => $field, 'value' => 'Respirez mieux', 'context' => $texts ) );
check( 'prompt : tâche', 0 === strpos( $prompt, "TASK: shorten\n" ) );
check( 'prompt : libellé et aide', false !== strpos( $prompt, '- Label: Titre du hero' ) && false !== strpos( $prompt, 'Instructions for editors: Court.' ) );
check( 'prompt : longueur maximale', false !== strpos( $prompt, 'Maximum length: 60 characters' ) );
check( 'prompt : texte brut sur une ligne', false !== strpos( $prompt, 'plain text on a single line' ) );
check( 'prompt : texte actuel', false !== strpos( $prompt, "\"\"\"\nRespirez mieux\n\"\"\"" ) );
check( 'prompt : contexte sans le champ lui-même', false !== strpos( $prompt, '- Titre: Audit QAI' ) && false === strpos( $prompt, '- Titre du hero: Respirez mieux' ) );

$html = Leiho_AI_Prompt::field_prompt( 'rewrite', array( 'field' => Leiho_AI_Rest::field_def( $schema, 'intro' ), 'value' => '<p>x</p>' ) );
check( 'prompt : format HTML pour un texte riche', false !== strpos( $html, 'Format: HTML fragment' ) );
$gen = Leiho_AI_Prompt::field_prompt( 'generate', array( 'field' => $field, 'instruction' => 'Parle des écoles' ) );
check( 'prompt : générer avec la demande', false !== strpos( $gen, 'following this request: Parle des écoles' ) && false !== strpos( $gen, '(empty)' ) );
$tr = Leiho_AI_Prompt::field_prompt( 'translate', array( 'field' => $field, 'value' => 'x', 'language' => 'English' ) );
check( 'prompt : traduire', false !== strpos( $tr, 'Translate the current text into English' ) );

$seo = Leiho_AI_Rest::field_def( $schema, 'seo_description' );
check( 'SEO : description détectée par le nom', 'description' === $seo['seo'] );
check( 'SEO : longueur conseillée appliquée', 155 === Leiho_AI_Prompt::field_spec( $seo )['max'] );
check( 'SEO : titre détecté par le libellé', 'title' === Leiho_AI_Prompt::seo_kind( 'x_title', 'Titre SEO' ) );
check( 'SEO : extrait = description', 'description' === Leiho_AI_Prompt::seo_kind( '_excerpt' ) );
check( 'SEO : un titre ordinaire ne l’est pas', '' === Leiho_AI_Prompt::seo_kind( 'hero_title', 'Titre du hero' ) );
check( 'champ : cellule de section trouvée', 'Sections › #1 › Corps' === Leiho_AI_Rest::field_def( $schema, 'sections.0.body' )['label'] );
check( 'champ : image refusée', is_wp_error( Leiho_AI_Rest::field_def( $schema, 'photo' ) ) );
check( 'champ : ligne pas encore enregistrée → indications du navigateur', 'textarea' === Leiho_AI_Rest::field_def( $schema, 'sections.3.body', array( 'type' => 'textarea', 'label' => 'Corps' ) )['type'] );
check( 'champ : indication d’un type non texte refusée', is_wp_error( Leiho_AI_Rest::field_def( $schema, 'zz', array( 'type' => 'image' ) ) ) );

$ctx = Leiho_AI_Prompt::normalize_context( array( 'a' => '<b>Gras</b>', array( 'path' => 'b<script>', 'label' => 'B', 'value' => '' ), array( 'path' => 'c', 'label' => 'C', 'value' => str_repeat( 'x', 5000 ) ) ) );
check( 'contexte reçu : nettoyé, vides retirés', 2 === count( $ctx ) && 'Gras' === $ctx[0]['value'], $ctx );
$lines = Leiho_AI_Prompt::context_lines( $ctx );
check( 'contexte reçu : valeurs bornées', strlen( $lines[1] ) < 1300 );

/* ---------------------------------------------------------------------
 * Formulations interdites
 * ------------------------------------------------------------------- */

$rules = $brand['forbidden'];
$found = static function ( $text ) use ( $rules ) {
	return array_column( Leiho_AI_Prompt::forbidden_matches( $text, $rules ), 'found' );
};
check( 'interdit : casse et accents', array( 'Certifiée' ) === $found( 'Une équipe Certifiée.' ), $found( 'Une équipe Certifiée.' ) );
check( 'interdit : sans accent dans le texte', array( 'CERTIFIE' ) === $found( 'Labo CERTIFIE' ) );
check( 'interdit : « 4 h » trouve « 4h »', array( '4h' ) === $found( 'Résultats en 4h.' ), $found( 'Résultats en 4h.' ) );
check( 'interdit : « 4 h » trouve « 4 heures »', array( '4 heures' ) === $found( 'sous 4 heures' ), $found( 'sous 4 heures' ) );
check( 'interdit : espace insécable', array( '4 h' ) === $found( "en 4\u{00A0}h chrono" ), $found( "en 4\u{00A0}h chrono" ) );
check( 'interdit : « 14 h » n’est pas « 4 h »', array() === $found( 'Rendez-vous à 14 h.' ), $found( 'Rendez-vous à 14 h.' ) );
check( 'interdit : pas au milieu d’un mot', array() === $found( 'Un produit décertifié.' ) );
check( 'interdit : expression régulière', array( 'Audit-ready' ) === $found( 'Soyez Audit-ready !' ) );
check( 'interdit : plusieurs mots', array( 'Obligation légale' ) === $found( '<p>Obligation légale dès 2026</p>' ) );
check( 'interdit : rien dans un texte propre', array() === $found( 'Mesures et rapport clair.' ) );
$m = Leiho_AI_Prompt::forbidden_matches( 'COFRAC et cofrac', $rules );
check( 'interdit : règle d’origine rapportée', 'COFRAC' === $m[0]['rule'] && 2 === count( $m ), $m );

$c = Leiho_AI_Prompt::check( 'Nous sommes certifiés COFRAC', $brand, 20 );
check( 'contrôle : interdits + trop long', 2 === count( $c['forbidden'] ) && $c['too_long'] && 28 === $c['length'], $c );
$c = Leiho_AI_Prompt::check( '<p>Court</p>', $brand, 20 );
check( 'contrôle : longueur visible (HTML retiré)', 5 === $c['length'] && ! $c['too_long'] && ! $c['empty'] );

/* ---------------------------------------------------------------------
 * Longueur et nettoyage de la réponse
 * ------------------------------------------------------------------- */

check( 'coupe : au dernier mot', 'Une phrase assez' === Leiho_AI_Prompt::trim_to_length( 'Une phrase assez longue pour être coupée', 18 ), Leiho_AI_Prompt::trim_to_length( 'Une phrase assez longue pour être coupée', 18 ) );
check( 'coupe : rien si ça tient', 'Court' === Leiho_AI_Prompt::trim_to_length( 'Court', 18 ) );
check( 'coupe : caractères multioctets', 5 >= mb_strlen( Leiho_AI_Prompt::trim_to_length( 'ééééééééé', 5 ) ) );
check( 'coupe : sans ponctuation finale pendante', 'Bonjour' === Leiho_AI_Prompt::trim_to_length( 'Bonjour, monde entier', 9 ) );

$plain_spec = array( 'format' => 'plain', 'single_line' => true, 'max' => 0 );
check( 'nettoyage : guillemets retirés', 'Respirez mieux' === Leiho_AI_Prompt::clean_output( '« Respirez mieux »', $plain_spec ) );
check( 'nettoyage : bloc de code retiré', 'Titre' === Leiho_AI_Prompt::clean_output( "```\nTitre\n```", $plain_spec ) );
check( 'nettoyage : préambule retiré', 'Texte' === Leiho_AI_Prompt::clean_output( "Voici le texte :\n\nTexte", $plain_spec ) );
check( 'nettoyage : une ligne', 'a b' === Leiho_AI_Prompt::clean_output( "a\n\nb", $plain_spec ) );
check( 'nettoyage : Markdown gras retiré', 'Très bien' === Leiho_AI_Prompt::clean_output( '**Très** bien', $plain_spec ) );
check( 'nettoyage : HTML retiré d’un texte brut', 'Gras' === Leiho_AI_Prompt::clean_output( '<b>Gras</b>', $plain_spec ) );
$multi = Leiho_AI_Prompt::clean_output( "Un.\n\nDeux.", array( 'format' => 'plain', 'single_line' => false, 'max' => 0 ) );
check( 'nettoyage : paragraphes gardés (zone de texte)', "Un.\n\nDeux." === $multi, $multi );
$rich = Leiho_AI_Prompt::clean_output( "Un **fort**.\n\nDeux.", array( 'format' => 'html', 'single_line' => false, 'max' => 0 ) );
check( 'nettoyage : texte riche sans balises → paragraphes', '<p>Un <strong>fort</strong>.</p>' . "\n" . '<p>Deux.</p>' === $rich, $rich );
check( 'nettoyage : HTML gardé tel quel', '<p>Ok</p>' === Leiho_AI_Prompt::clean_output( '<p>Ok</p>', array( 'format' => 'html', 'single_line' => false, 'max' => 0 ) ) );

/* ---------------------------------------------------------------------
 * Nouvelle entrée : schéma JSON et correspondance
 * ------------------------------------------------------------------- */

$fields = array(
	array( 'path' => '_title', 'type' => 'text', 'label' => 'Title', 'required' => true ),
	array( 'path' => 'tagline', 'type' => 'text', 'label' => 'Accroche', 'max_length' => 20 ),
	array( 'path' => 'body', 'type' => 'wysiwyg', 'label' => 'Texte' ),
	array( 'path' => 'tone', 'type' => 'select', 'label' => 'Ton', 'options' => array( 'light' => 'Clair', 'dark' => 'Sombre' ) ),
	array( 'path' => 'featured', 'type' => 'true_false', 'label' => 'À la une' ),
	array( 'path' => 'price', 'type' => 'number', 'label' => 'Prix', 'min' => 0, 'max' => 500 ),
	array( 'path' => 'photo', 'type' => 'image', 'label' => 'Photo' ),
	array( 'path' => 'tags', 'type' => 'select', 'label' => 'Tags', 'multiple' => true, 'options' => array( 'a' => 'A' ) ),
	array( 'path' => 'steps', 'type' => 'repeater', 'label' => 'Étapes', 'max' => 2, 'sub_fields' => array(
		array( 'path' => 'title', 'type' => 'text', 'label' => 'Titre' ),
		array( 'path' => 'icon', 'type' => 'image', 'label' => 'Icône' ),
	) ),
	array( 'path' => 'gallery_only', 'type' => 'repeater', 'label' => 'Images', 'sub_fields' => array( array( 'path' => 'img', 'type' => 'image', 'label' => 'Img' ) ) ),
	array( 'path' => 'sections', 'type' => 'flexible_content', 'label' => 'Sections', 'layouts' => array(
		array( 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array( array( 'path' => 'h1', 'type' => 'text', 'label' => 'H1' ) ) ),
		array( 'name' => 'faq', 'label' => 'FAQ', 'sub_fields' => array( array( 'path' => 'items', 'type' => 'repeater', 'label' => 'Questions', 'sub_fields' => array(
			array( 'path' => 'q', 'type' => 'text', 'label' => 'Question' ),
			array( 'path' => 'a', 'type' => 'textarea', 'label' => 'Réponse' ),
		) ) ) ),
	) ),
	array( 'path' => 'contact', 'type' => 'group', 'label' => 'Contact', 'sub_fields' => array( array( 'path' => 'phone', 'type' => 'text', 'label' => 'Téléphone' ) ) ),
	array( 'path' => '_content', 'type' => 'wysiwyg', 'label' => 'Content', 'blocks' => true, 'readonly' => true ),
);

$fillable = array_column( Leiho_AI_Prompt::fillable( $fields ), 'path' );
check( 'remplissables : ni image, ni choix multiple, ni liste d’images, ni blocs Gutenberg', array( '_title', 'tagline', 'body', 'tone', 'featured', 'price', 'steps', 'sections', 'contact' ) === $fillable, $fillable );

$js = Leiho_AI_Prompt::entry_schema( $fields );
$props = (array) $js['properties'];
check( 'schéma : objet sans propriétés en trop', 'object' === $js['type'] && false === $js['additionalProperties'] );
check( 'schéma : requis', array( '_title' ) === $js['required'] );
check( 'schéma : longueur maximale', 20 === $props['tagline']['maxLength'] );
check( 'schéma : choix en enum', array( 'light', 'dark' ) === $props['tone']['enum'] );
check( 'schéma : bornes du nombre', 0.0 === $props['price']['minimum'] && 500.0 === $props['price']['maximum'] );
check( 'schéma : liste bornée', 'array' === $props['steps']['type'] && 2 === $props['steps']['maxItems'] && isset( ( (array) $props['steps']['items']['properties'] )['title'] ) && ! isset( ( (array) $props['steps']['items']['properties'] )['icon'] ) );
$sec = (array) $props['sections']['items']['properties'];
check( 'schéma : sections avec _layout', array( 'hero', 'faq' ) === $sec['_layout']['enum'] && isset( $sec['h1'], $sec['items'] ) && array( '_layout' ) === $props['sections']['items']['required'] );
check( 'schéma : JSON valide', is_string( json_encode( $js ) ) && false !== strpos( json_encode( $js ), '"properties":{' ) );

$answer = "```json\n" . json_encode(
	array(
		'_title'   => 'Audit de l’air',
		'tagline'  => 'Une accroche bien trop longue pour le champ',
		'body'     => "Un texte **riche**.\n\nAvec <script>alert(1)</script> deux paragraphes. Certifié !",
		'tone'     => 'neon',
		'featured' => true,
		'price'    => 900,
		'photo'    => 12,
		'unknown'  => 'x',
		'steps'    => array( array( 'title' => 'Un', 'icon' => 3 ), array( 'title' => 'Deux' ), array( 'title' => 'Trois' ) ),
		'sections' => array(
			array( '_layout' => 'hero', 'h1' => 'Respirez' ),
			array( '_layout' => 'nope', 'h1' => 'x' ),
			array( '_layout' => 'faq', 'items' => array( array( 'q' => 'Combien ?', 'a' => 'Résultats en 4 h.' ) ) ),
		),
		'contact'  => array( 'phone' => '01 23 45 67 89' ),
	)
) . "\n```";
$data = Leiho_AI_Prompt::decode_json( $answer );
check( 'JSON : bloc de code toléré', is_array( $data ) && 'Audit de l’air' === $data['_title'] );
check( 'JSON : texte autour toléré', array( 'a' => 1 ) === Leiho_AI_Prompt::decode_json( 'Voici : {"a":1} merci' ) );
check( 'JSON : une liste n’est pas un objet', null === Leiho_AI_Prompt::decode_json( '[1,2]' ) );
check( 'JSON : illisible', null === Leiho_AI_Prompt::decode_json( 'pas du json' ) );

$warnings = array();
$values   = Leiho_AI_Prompt::map_values( $fields, $data, $brand, 'wp_kses_post', $warnings );
check( 'valeurs : titre', 'Audit de l’air' === $values['_title'] );
check( 'valeurs : texte coupé à sa longueur', mb_strlen( $values['tagline'] ) <= 20 && ! empty( $warnings['tagline']['trimmed'] ), array( $values['tagline'], $warnings ) );
check( 'valeurs : texte riche nettoyé et mis en paragraphes', 0 === strpos( $values['body'], '<p>Un texte <strong>riche</strong>.</p>' ) && false === strpos( $values['body'], '<script' ), $values['body'] );
check( 'valeurs : interdits signalés au bon chemin', 'Certifié' === $warnings['body']['forbidden'][0]['found'] && '4 h' === $warnings['sections.1.items.0.a']['forbidden'][0]['found'], $warnings );
check( 'valeurs : choix invalide ignoré', ! isset( $values['tone'] ) );
check( 'valeurs : booléen', true === $values['featured'] );
check( 'valeurs : nombre borné', 500 === $values['price'] );
check( 'valeurs : image et clé inconnue ignorées', ! isset( $values['photo'] ) && ! isset( $values['unknown'] ) );
check( 'valeurs : liste bornée, sous-champs non remplissables retirés', array( array( 'title' => 'Un' ), array( 'title' => 'Deux' ) ) === $values['steps'], $values['steps'] );
check( 'valeurs : section inconnue retirée, _layout gardé', 2 === count( $values['sections'] ) && 'hero' === $values['sections'][0]['_layout'] && 'faq' === $values['sections'][1]['_layout'], $values['sections'] );
check( 'valeurs : liste dans une section', 'Combien ?' === $values['sections'][1]['items'][0]['q'] );
check( 'valeurs : groupe', array( 'phone' => '01 23 45 67 89' ) === $values['contact'] );

$prompt = Leiho_AI_Prompt::entry_prompt( 'Une page sur l’audit', $fields, 'Service' );
check( 'prompt d’entrée : brief et champs décrits', false !== strpos( $prompt, 'Une page sur l’audit' ) && false !== strpos( $prompt, '- tagline: Accroche (text, max 20 characters)' ) && false !== strpos( $prompt, '_layout "faq"' ) );
check( 'prompt d’entrée : champs non remplissables absents', false === strpos( $prompt, 'photo' ) );
check( 'prompt d’entrée : schéma joint en repli', false !== strpos( Leiho_AI_Prompt::entry_prompt( 'x', $fields, 'S', true ), '"additionalProperties":false' ) );

/* ---------------------------------------------------------------------
 * Génération (faux constructeur)
 * ------------------------------------------------------------------- */

Fake_Builder::$answers = array( 'Bonjour' );
$out = Leiho_AI::generate( 'SYS', 'PROMPT' );
$log = array_pop( Fake_Builder::$log );
check( 'génération : texte rendu', 'Bonjour' === $out );
check( 'génération : consigne système native', in_array( 'using_system_instruction', $log['calls'], true ) && 'PROMPT' === $log['prompt'] );
check( 'génération : délai rendu au client d’IA puis retiré', empty( $GLOBALS['filters']['wp_ai_client_default_request_timeout'] ) );

Fake_Builder::$native_ok = false;
Fake_Builder::$answers   = array( '{"_title":"x"}' );
$out = Leiho_AI::generate( 'SYS', 'PROMPT', array( 'schema' => array( 'type' => 'object' ) ) );
$log = array_pop( Fake_Builder::$log );
check( 'génération : repli sans options natives', '{"_title":"x"}' === $out && ! in_array( 'as_json_response', $log['calls'], true ) );
check( 'génération : consigne et schéma passés dans le prompt', 0 === strpos( $log['prompt'], "INSTRUCTIONS\nSYS" ) && false !== strpos( $log['prompt'], '{"type":"object"}' ) );
Fake_Builder::$native_ok = true;

$out = Leiho_AI::generate( 'SYS', 'P', array( 'image' => array( 'data' => 'data:image/png;base64,AA==', 'mime' => 'image/png' ) ) );
check( 'génération : image refusée sans modèle qui lit les images', is_wp_error( $out ) && 'leiho_ai_no_image' === $out->code );

// Schéma refusé par le fournisseur (400) : nouvel essai, schéma dans le prompt.
class Fake_Strict_Builder extends Fake_Builder {
	public function __call( $name, $args ) {
		if ( 'generate_text' === $name && in_array( 'as_json_response', $this->calls, true ) ) {
			return new WP_Error( 'prompt_client_error', 'Invalid schema', array( 'status' => 400 ) );
		}
		return parent::__call( $name, $args );
	}
}
$factory          = Leiho_AI::$factory;
Leiho_AI::$factory = static function ( $prompt ) {
	return new Fake_Strict_Builder( $prompt );
};
Fake_Builder::$answers = array( '{"_title":"y"}' );
$out = Leiho_AI::generate( 'SYS', 'P', array( 'schema' => array( 'type' => 'object' ) ) );
$log = array_pop( Fake_Builder::$log );
check( 'génération : schéma refusé (400) → schéma dans le prompt', '{"_title":"y"}' === $out && false !== strpos( $log['prompt'], 'JSON schema' ), $out );
Leiho_AI::$factory = $factory;

Fake_Builder::$error = new WP_Error( 'prompt_network_error', 'cURL error 28: timed out', array( 'status' => 503 ) );
$out = Leiho_AI::generate( '', 'P' );
check( 'génération : délai dépassé → message lisible, 504', is_wp_error( $out ) && 504 === $out->data['status'] && false !== strpos( $out->message, 'didn’t answer in time' ) && 'cURL error 28: timed out' === $out->data['detail'], $out );
Fake_Builder::$error = new WP_Error( 'prompt_client_error', 'Unauthorized', array( 'status' => 401 ) );
$out = Leiho_AI::generate( '', 'P' );
check( 'génération : clé refusée → connecteurs', false !== strpos( $out->message, 'Settings → Connectors' ) );
Fake_Builder::$error = new WP_Error( 'prompt_prevented', 'x', array( 'status' => 503 ) );
check( 'génération : IA coupée par filtre', 'leiho_ai_prevented' === Leiho_AI::generate( '', 'P' )->code );
Fake_Builder::$error   = null;
Fake_Builder::$answers = array( '   ' );
check( 'génération : réponse vide', 'leiho_ai_empty' === Leiho_AI::generate( '', 'P' )->code );

$status = Leiho_AI::status( true );
check( 'état : texte disponible, images non', $status['client'] && $status['text'] && ! $status['image'], $status );
Fake_Builder::$image_ok = true;
check( 'état : images si le modèle les lit', Leiho_AI::status( true )['image'] );
$GLOBALS['filters']['leiho_ai_status'][] = static function ( $s ) { $s['text'] = false; return $s; };
Leiho_AI::reset();
check( 'état : filtrable, IA masquée', ! Leiho_AI::enabled() && ! Leiho_AI::client_config()['enabled'] );
unset( $GLOBALS['filters']['leiho_ai_status'] );
Leiho_AI::reset();
check( 'configuration : lien des connecteurs pour un administrateur', false !== strpos( Leiho_AI::client_config()['connectorsUrl'], 'options-connectors.php' ) || '' === Leiho_AI::client_config()['connectorsUrl'] );

/* ---------------------------------------------------------------------
 * Limite de débit
 * ------------------------------------------------------------------- */

$GLOBALS['filters']['leiho_ai_rate_limit'][] = static function () { return array( 'requests' => 2, 'window' => 60 ); };
$GLOBALS['transients'] = array();
$a = Leiho_AI::throttle( 7 );
$b = Leiho_AI::throttle( 7 );
$c = Leiho_AI::throttle( 7 );
check( 'débit : deux requêtes passent, la troisième non', true === $a && true === $b && is_wp_error( $c ) && 429 === $c->data['status'] );
check( 'débit : par utilisateur', true === Leiho_AI::throttle( 8 ) );
$GLOBALS['filters']['leiho_ai_rate_limit'] = array( static function () { return array( 'requests' => 0 ); } );
check( 'débit : 0 = aucune limite', true === Leiho_AI::throttle( 7 ) );
$GLOBALS['filters']['leiho_ai_site_rate_limit'] = array( static function () { return array( 'requests' => 3, 'window' => 60 ); } );
$GLOBALS['transients'] = array();
$site = array( Leiho_AI::throttle( 21 ), Leiho_AI::throttle( 22 ), Leiho_AI::throttle( 23 ), Leiho_AI::throttle( 24 ) );
check( 'débit : limite du site, tous utilisateurs confondus', true === $site[0] && true === $site[2] && is_wp_error( $site[3] ), $site );
check( 'débit : verrou rendu après chaque requête', ! array_key_exists( 'leiho_ai_rl_lock', $GLOBALS['options'] ) );
unset( $GLOBALS['filters']['leiho_ai_rate_limit'], $GLOBALS['filters']['leiho_ai_site_rate_limit'] );
$GLOBALS['transients'] = array();

/* ---------------------------------------------------------------------
 * Routes REST
 * ------------------------------------------------------------------- */

Leiho_AI_Rest::register_routes();
$expected = array( '/ai/status', '/ai/field', '/ai/alt', '/ai/entry', '/ai/entry/create', '/ai/brand', '/ai/collections/(?P<type>[a-z0-9_\-]{1,20})' );
foreach ( $expected as $route ) {
	check( 'REST : route leiho/v1' . $route, isset( $GLOBALS['routes'][ 'leiho/v1' . $route ] ) );
}
$all_have_permission = true;
foreach ( $GLOBALS['routes'] as $args ) {
	foreach ( isset( $args['methods'] ) ? array( $args ) : $args as $endpoint ) {
		if ( empty( $endpoint['permission_callback'] ) || '__return_true' === $endpoint['permission_callback'] ) {
			$all_have_permission = false;
		}
	}
}
check( 'REST : chaque route a un contrôle de permission', $all_have_permission );
check( 'REST : actions bornées par une enum', Leiho_AI_Prompt::ACTIONS === $GLOBALS['routes']['leiho/v1/ai/field']['args']['action']['enum'] );

$GLOBALS['posts'][42]           = (object) array( 'ID' => 42, 'post_type' => 'service', 'post_status' => 'draft', 'post_title' => 'Audit', 'post_excerpt' => '' );
$GLOBALS['post_caps']['edit_post:42'] = true;
$req = new WP_REST_Request( array( 'post_id' => 42, 'path' => 'hero_title', 'action' => 'rewrite', 'value' => 'Respirez mieux' ) );
check( 'REST : champ autorisé', true === Leiho_AI_Rest::can_edit_field( $req ) );
$GLOBALS['post_caps']['edit_post:42'] = false;
check( 'REST : pas le droit sur le contenu', false === Leiho_AI_Rest::can_edit_field( $req ) );
$GLOBALS['post_caps']['edit_post:42'] = true;
$GLOBALS['filters']['leiho_ai_capability'][] = static function () { return 'use_leiho_ai'; };
$denied = Leiho_AI_Rest::can_edit_field( $req );
check( 'REST : capability d’IA filtrable', is_wp_error( $denied ) && 403 === $denied->data['status'] );
unset( $GLOBALS['filters']['leiho_ai_capability'] );
$big = new WP_REST_Request( array( 'post_id' => 42 ), str_repeat( 'x', Leiho_AI_Rest::MAX_BODY + 1 ) );
$too = Leiho_AI_Rest::can_edit_field( $big );
check( 'REST : corps trop gros refusé (413)', is_wp_error( $too ) && 413 === $too->data['status'] );
check( 'REST : marque réservée aux administrateurs', Leiho_AI_Rest::can_manage_brand() );
$GLOBALS['caps']['manage_options'] = false;
check( 'REST : marque refusée sans manage_options', ! Leiho_AI_Rest::can_manage_brand() && ! Leiho_AI_Rest::can_manage_collection() );
$GLOBALS['caps']['manage_options'] = true;

Leiho_Schema::$fixture = $schema;
$long = Leiho_AI_Rest::field( new WP_REST_Request( array( 'post_id' => 42, 'path' => 'hero_title', 'action' => 'rewrite', 'value' => str_repeat( 'é', Leiho_AI_Rest::MAX_VALUE + 1 ), 'instruction' => '', 'tone' => '', 'language' => '' ) ) );
check( 'REST : texte trop long refusé (413)', is_wp_error( $long ) && 413 === $long->data['status'] );
$empty = Leiho_AI_Rest::field( new WP_REST_Request( array( 'post_id' => 42, 'path' => 'hero_title', 'action' => 'generate', 'value' => '', 'instruction' => '', 'tone' => '', 'language' => '' ) ) );
check( 'REST : générer sans demande refusé (400)', is_wp_error( $empty ) && 400 === $empty->data['status'] );
$nothing = Leiho_AI_Rest::field( new WP_REST_Request( array( 'post_id' => 42, 'path' => 'hero_title', 'action' => 'shorten', 'value' => '  ', 'instruction' => '', 'tone' => '', 'language' => '' ) ) );
check( 'REST : raccourcir un champ vide refusé', is_wp_error( $nothing ) && 400 === $nothing->data['status'] );

// Action complète : prompt envoyé, proposition contrôlée, journal.
$wpdb_rows = array();
class Fake_WPDB {
	public $prefix = 'wp_';
	public function insert( $table, $row ) { $GLOBALS['ai_log'][] = $row; return 1; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
$GLOBALS['options']['leiho_activity_db_version'] = Leiho_Activity::DB_VERSION;
function wp_get_current_user() { return new class() { public $display_name = 'Marie'; public function exists() { return true; } }; }
function current_time( $t, $gmt = false ) { return '2026-11-04 10:00:00'; }

Fake_Builder::$answers = array( '"Respirez mieux, nous sommes certifiés COFRAC et rapides en 4 h, partout en France"' );
$res = Leiho_AI_Rest::field( new WP_REST_Request( array( 'post_id' => 42, 'path' => 'hero_title', 'action' => 'rewrite', 'value' => 'Respirez mieux', 'instruction' => '', 'tone' => '', 'language' => '', 'context' => array( array( 'path' => 'intro', 'label' => 'Intro', 'value' => 'Contexte non enregistré' ) ) ) ) );
$log = array_pop( Fake_Builder::$log );
check( 'REST : proposition nettoyée', 0 === strpos( $res['text'], 'Respirez mieux, nous' ) && 'plain' === $res['format'], $res );
check( 'REST : interdits et longueur contrôlés', 3 === count( $res['check']['forbidden'] ) && $res['check']['too_long'] && 60 === $res['max'], $res['check'] );
check( 'REST : contexte du navigateur utilisé', false !== strpos( $log['prompt'], 'Contexte non enregistré' ) );
check( 'REST : marque dans la consigne système', in_array( 'using_system_instruction', $log['calls'], true ) );
$row = end( $GLOBALS['ai_log'] );
check( 'journal : action d’IA (qui, champ, action), sans le texte', 'ai' === $row['field_type'] && 'hero_title' === $row['field_path'] && 'rewrite' === $row['new_value'] && 'Marie' === $row['user_name'] && '' === $row['old_value'], $row );

// Nouvelle entrée : génération puis brouillon.
$GLOBALS['caps']['edit_posts'] = true;
check( 'REST : nouvelle entrée autorisée pour un type géré', true === Leiho_AI_Rest::can_create_entry( new WP_REST_Request( array( 'post_type' => 'service' ) ) ) );
check( 'REST : type hors de Leiho refusé', false === Leiho_AI_Rest::can_create_entry( new WP_REST_Request( array( 'post_type' => 'product' ) ) ) );
Fake_Builder::$answers = array( json_encode( array( '_title' => 'Audit radon', '_excerpt' => 'Mesure du radon.' ) ) );
$entry = Leiho_AI_Rest::entry( new WP_REST_Request( array( 'post_type' => 'service', 'brief' => 'Le radon' ) ) );
$log   = array_pop( Fake_Builder::$log );
check( 'REST : entrée générée en JSON structuré', ! is_wp_error( $entry ) && 'Audit radon' === $entry['values']->_title && in_array( 'as_json_response', $log['calls'], true ), $entry );
function wp_insert_post( $arr, $err = false ) {
	$id = 99;
	$GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $arr['post_type'], 'post_status' => $arr['post_status'], 'post_title' => $arr['post_title'] );
	return $id;
}
$created = Leiho_AI_Rest::create_entry( new WP_REST_Request( array( 'post_type' => 'service', 'values' => array( '_title' => 'Audit radon', '_excerpt' => 'Mesure.', '_status' => 'publish', 'evil' => 'x' ) ) ) );
check( 'REST : brouillon créé', 'draft' === $created['post']['status'] && 99 === $created['post']['id'], $created );
check( 'REST : seuls les champs remplissables écrits, par le chemin de l’éditeur', array( '_excerpt' => 'Mesure.' ) === Leiho_Schema::$saved[99], Leiho_Schema::$saved );
$notitle = Leiho_AI_Rest::create_entry( new WP_REST_Request( array( 'post_type' => 'service', 'values' => array( '_excerpt' => 'x' ) ) ) );
check( 'REST : titre obligatoire', is_wp_error( $notitle ) && 400 === $notitle->data['status'] );

/* ---------------------------------------------------------------------
 * Capacités (Abilities API)
 * ------------------------------------------------------------------- */

Leiho_AI_Abilities::register_category();
Leiho_AI_Abilities::register();
check( 'capacités : catégorie leiho', isset( $GLOBALS['categories']['leiho'] ) );
check( 'capacités : toutes enregistrées quand le fournisseur lit les images', array_keys( Leiho_AI_Abilities::definitions() ) === array_keys( $GLOBALS['abilities'] ), array_keys( $GLOBALS['abilities'] ) );
$ok_shape = true;
foreach ( $GLOBALS['abilities'] as $name => $args ) {
	if ( 'leiho' !== $args['category'] || ! is_callable( $args['execute_callback'] ) || ! is_callable( $args['permission_callback'] ) || empty( $args['meta']['show_in_rest'] ) || '' === $args['description'] ) {
		$ok_shape = false;
	}
}
check( 'capacités : catégorie, rappels, exposées (REST, MCP)', $ok_shape );
check( 'capacités : lecture seule annoncée', true === $GLOBALS['abilities']['leiho/get-entry']['meta']['annotations']['readonly'] && false === $GLOBALS['abilities']['leiho/update-entry-fields']['meta']['annotations']['readonly'] );
check( 'capacités : écriture exige id et valeurs', array( 'id', 'values' ) === $GLOBALS['abilities']['leiho/update-entry-fields']['input_schema']['required'] );

check( 'permission : lire avec edit_posts', true === Leiho_AI_Abilities::can_read() );
$GLOBALS['caps']['edit_posts'] = false;
check( 'permission : lire refusé sans edit_posts', false === Leiho_AI_Abilities::can_read() );
$GLOBALS['caps']['edit_posts'] = true;
check( 'permission : entrée d’un type géré, avec le droit', true === Leiho_AI_Abilities::can_edit_entry( array( 'id' => 42 ) ) );
check( 'permission : sans id', false === Leiho_AI_Abilities::can_edit_entry( array() ) );
$GLOBALS['post_caps']['edit_post:42'] = false;
check( 'permission : sans le droit sur l’entrée', false === Leiho_AI_Abilities::can_update_entry( array( 'id' => 42 ) ) );
$GLOBALS['post_caps']['edit_post:42'] = true;
check( 'permission : modifier un brouillon', true === Leiho_AI_Abilities::can_update_entry( array( 'id' => 42, 'values' => array() ) ) );
$GLOBALS['posts'][42]->post_status = 'publish';
$pub = Leiho_AI_Abilities::update_entry_fields( array( 'id' => 42, 'values' => array( 'hero_title' => 'x' ) ) );
check( 'écriture : contenu en ligne refusé avec sa raison (brouillons seulement)', is_wp_error( $pub ) && 'leiho_not_draft' === $pub->code && 403 === $pub->data['status'] );
$GLOBALS['posts'][42]->post_status = 'future';
check( 'écriture : contenu programmé refusé', is_wp_error( Leiho_AI_Abilities::update_entry_fields( array( 'id' => 42, 'values' => array( 'x' => 1 ) ) ) ) );
$GLOBALS['posts'][43] = (object) array( 'ID' => 43, 'post_type' => 'product', 'post_status' => 'draft' );
$GLOBALS['post_caps']['edit_post:43'] = true;
check( 'permission : type hors de Leiho refusé', false === Leiho_AI_Abilities::can_edit_entry( array( 'id' => 43 ) ) );
$GLOBALS['posts'][42]->post_status = 'draft';

$upd = Leiho_AI_Abilities::update_entry_fields( array( 'id' => 42, 'values' => array( 'hero_title' => 'Nouveau', '_status' => 'publish' ), 'revision' => 'rev1' ) );
check( 'écriture : valeurs passées au chemin de l’éditeur, statut ignoré', array( 'hero_title' => 'Nouveau' ) === Leiho_Schema::$saved[42] && array( 'hero_title' ) === $upd['saved'], $upd );
$conflict = Leiho_AI_Abilities::update_entry_fields( array( 'id' => 42, 'values' => array( 'x' => 1 ), 'revision' => 'old' ) );
check( 'écriture : conflit de révision (409)', is_wp_error( $conflict ) && 409 === $conflict->data['status'] );
$row = end( $GLOBALS['ai_log'] );
check( 'écriture : journalisée comme action d’agent', 'ability' === $row['new_value'] && 42 === $row['post_id'] );
$ctx = Leiho_AI_Abilities::get_brand_context( array( 'post_type' => 'service' ) );
check( 'contexte de marque : interdits et consigne', in_array( 'COFRAC', $ctx['brand']['forbidden'], true ) && false !== strpos( $ctx['instructions'], 'FORBIDDEN CLAIMS' ) && 'Syndics' === $ctx['collection']['audience'] );

/* ---------------------------------------------------------------------
 * Capacités : recherche, nouveau brouillon, texte alternatif
 * ------------------------------------------------------------------- */

check( 'capacités : onze capacités de contenu', array( 'leiho/get-brand-context', 'leiho/list-collections', 'leiho/get-entry', 'leiho/update-entry-fields', 'leiho/search-entries', 'leiho/create-draft', 'leiho/suggest-alt-text', 'leiho/list-translations', 'leiho/get-translation', 'leiho/update-translation', 'leiho/translate-entry' ) === array_keys( Leiho_AI_Abilities::definitions() ), array_keys( Leiho_AI_Abilities::definitions() ) );
check( 'capacités : traduction lue en lecture seule, écrite sans destruction', true === Leiho_AI_Abilities::definitions()['leiho/get-translation']['meta']['annotations']['readonly'] && false === Leiho_AI_Abilities::definitions()['leiho/update-translation']['meta']['annotations']['destructive'] );
Fake_Builder::$image_ok = false;
Leiho_AI::status( true );
$GLOBALS['abilities'] = array();
Leiho_AI_Abilities::register();
check( 'capacités : texte alternatif non annoncé si le fournisseur ne lit pas les images', ! isset( $GLOBALS['abilities']['leiho/suggest-alt-text'] ) && isset( $GLOBALS['abilities']['leiho/create-draft'] ) );
$defs = Leiho_AI_Abilities::definitions();
check( 'capacités : recherche en lecture seule, brouillon en écriture non idempotente', true === $defs['leiho/search-entries']['meta']['annotations']['readonly'] && false === $defs['leiho/create-draft']['meta']['annotations']['readonly'] && false === $defs['leiho/create-draft']['meta']['annotations']['idempotent'] && false === $defs['leiho/create-draft']['meta']['annotations']['destructive'] );
check( 'capacités : toutes publiques pour MCP', ! in_array( false, array_map( static function ( $d ) { return ! empty( $d['meta']['mcp']['public'] ); }, $defs ), true ) );
check( 'capacités : recherche bornée à 50 par page', 50 === $defs['leiho/search-entries']['input_schema']['properties']['per_page']['maximum'] );

$GLOBALS['posts'][44] = (object) array( 'ID' => 44, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Audit radon', 'post_modified_gmt' => '2026-11-04 10:00:00' );
$GLOBALS['posts'][45] = (object) array( 'ID' => 45, 'post_type' => 'page', 'post_status' => 'trash', 'post_title' => 'Audit jeté', 'post_modified_gmt' => '2026-11-04 10:00:00' );
$GLOBALS['posts'][46] = (object) array( 'ID' => 46, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Audit secret', 'post_modified_gmt' => '2026-11-04 10:00:00' );
$GLOBALS['posts'][42]->post_modified_gmt = '2026-11-04 10:00:00';
$GLOBALS['post_caps']['edit_post:44'] = true;
$GLOBALS['post_caps']['edit_post:45'] = true;
$GLOBALS['post_caps']['edit_post:46'] = false;
$found = Leiho_AI_Abilities::search_entries( array( 'search' => 'Audit' ) );
$ids   = array_column( $found['items'], 'id' );
check( 'recherche : titres, hors corbeille, hors types non gérés, sans les entrées interdites', array( 42, 44 ) === $ids, $found );
check( 'recherche : titre seulement', array( 'post_title' ) === WP_Query::$last['search_columns'] && 'modified' === WP_Query::$last['orderby'] );
$first = $found['items'][0];
check( 'recherche : champs rendus (chemin, droits)', '/service/42' === $first['path'] && 'draft' === $first['status'] && true === $first['can_edit'] && true === $first['agent_writes'] && false === $found['items'][1]['agent_writes'], $first );
$only = Leiho_AI_Abilities::search_entries( array( 'post_type' => 'page', 'status' => 'publish', 'per_page' => 500 ) );
check( 'recherche : filtre type et statut, page bornée', array( 44 ) === array_column( $only['items'], 'id' ) && 50 === WP_Query::$last['posts_per_page'] && array( 'publish' ) === WP_Query::$last['post_status'], $only );
Leiho_AI_Abilities::search_entries( array( 'status' => 'trash' ) );
check( 'recherche : la corbeille n’est jamais visée', ! in_array( 'trash', WP_Query::$last['post_status'], true ) );
$bad = Leiho_AI_Abilities::search_entries( array( 'post_type' => 'product' ) );
check( 'recherche : type hors de Leiho refusé', is_wp_error( $bad ) && 400 === $bad->data['status'] );

check( 'brouillon : autorisé pour un type géré', true === Leiho_AI_Abilities::can_create_draft( array( 'post_type' => 'service', 'title' => 'x' ) ) );
check( 'brouillon : type hors de Leiho refusé', false === Leiho_AI_Abilities::can_create_draft( array( 'post_type' => 'product' ) ) );
check( 'brouillon : sans type refusé', false === Leiho_AI_Abilities::can_create_draft( array() ) );
$GLOBALS['caps']['edit_posts'] = false;
check( 'brouillon : refusé sans le droit de créer', false === Leiho_AI_Abilities::can_create_draft( array( 'post_type' => 'service' ) ) );
$GLOBALS['caps']['edit_posts'] = true;
$GLOBALS['filters']['leiho_ai_capability'][] = static function () { return 'use_leiho_ai'; };
check( 'brouillon : refusé sans la capability d’IA', false === Leiho_AI_Abilities::can_create_draft( array( 'post_type' => 'service' ) ) );
unset( $GLOBALS['filters']['leiho_ai_capability'] );

Leiho_Schema::$saved = array();
$draft = Leiho_AI_Abilities::create_draft( array( 'post_type' => 'service', 'title' => 'Audit amiante', 'fields' => array( 'hero_title' => 'Respirez', '_status' => 'publish', '_title' => 'Autre' ) ) );
check( 'brouillon : créé en brouillon, jamais publié', ! is_wp_error( $draft ) && 'draft' === $draft['status'] && 'draft' === $GLOBALS['posts'][99]->post_status && 'Audit amiante' === $GLOBALS['posts'][99]->post_title, $draft );
check( 'brouillon : champs par le chemin de l’éditeur, statut et titre retirés', array( 'hero_title' => 'Respirez' ) === Leiho_Schema::$saved[99] && array( '_title', 'hero_title' ) === $draft['saved'], Leiho_Schema::$saved );
$row = end( $GLOBALS['ai_log'] );
check( 'brouillon : journalisé comme action d’agent', 'draft' === $row['new_value'] && 99 === $row['post_id'] && 'AI agent · Draft created' === Leiho_Activity::ai_label( 'draft' ), $row );
$notitle = Leiho_AI_Abilities::create_draft( array( 'post_type' => 'service', 'title' => '  ' ) );
check( 'brouillon : titre obligatoire', is_wp_error( $notitle ) && 400 === $notitle->data['status'] );

$GLOBALS['posts'][50] = (object) array( 'ID' => 50, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Capteur', 'post_excerpt' => '' );
$GLOBALS['post_caps']['edit_post:50'] = true;
check( 'texte alternatif : image modifiable', true === Leiho_AI_Abilities::can_describe_image( array( 'id' => 50 ) ) );
check( 'texte alternatif : un contenu n’est pas une image', false === Leiho_AI_Abilities::can_describe_image( array( 'id' => 42 ) ) );
$GLOBALS['post_caps']['edit_post:50'] = false;
check( 'texte alternatif : sans le droit sur l’image', false === Leiho_AI_Abilities::can_describe_image( array( 'id' => 50 ) ) );
$GLOBALS['post_caps']['edit_post:50'] = true;
Fake_Builder::$image_ok = false;
Leiho_AI::status( true );
$noimg = Leiho_AI_Abilities::suggest_alt_text( array( 'id' => 50 ) );
check( 'texte alternatif : erreur claire sans fournisseur qui lit les images', is_wp_error( $noimg ) && 'leiho_ai_no_image' === $noimg->code && 501 === $noimg->data['status'] );
Fake_Builder::$image_ok = true;
Leiho_AI::status( true );
$GLOBALS['abilities'] = array();
Leiho_AI_Abilities::register();
check( 'texte alternatif : annoncé quand le fournisseur lit les images', isset( $GLOBALS['abilities']['leiho/suggest-alt-text'] ) );
function get_post_mime_type( $id ) { return 50 === $id ? 'image/png' : 'text/plain'; }
function image_get_intermediate_size( $id, $size ) { return false; }
function get_attached_file( $id ) { return __FILE__; }
function wp_check_filetype( $p ) { return array( 'type' => 'image/png' ); }
Fake_Builder::$answers = array( '« Capteur de radon posé sur une étagère »' );
$alt = Leiho_AI_Abilities::suggest_alt_text( array( 'id' => 50, 'context' => 'Page radon' ) );
if ( ! is_wp_error( $alt ) ) {
	$log = array_pop( Fake_Builder::$log );
	check( 'texte alternatif : proposé par la même logique que l’éditeur, rien d’enregistré', 'Capteur de radon posé sur une étagère' === $alt['alt_text'] && false === $alt['saved'] && in_array( 'with_file', $log['calls'], true ) && false !== strpos( $log['prompt'], 'Page radon' ), $alt );
	$row = end( $GLOBALS['ai_log'] );
	check( 'texte alternatif : journalisé', 'alt' === $row['new_value'] && 'alt:50' === $row['field_path'] );
} else {
	check( 'texte alternatif : image lisible dans le test', false, $alt );
}

/* ---------------------------------------------------------------------
 * Traduction
 * ------------------------------------------------------------------- */

$GLOBALS['posts'][90] = (object) array( 'ID' => 90, 'post_type' => 'service', 'post_status' => 'publish', 'post_title' => 'Audit' );
$GLOBALS['posts'][91] = (object) array( 'ID' => 91, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page' );
$tr_schema = function ( $title, $intro, $rows, $code ) {
	return array(
		'post_id' => 90, 'title' => $title, 'status' => 'publish', 'revision' => 'r',
		'groups'  => array(
			array( 'fields' => array(
				array( 'path' => '_title', 'type' => 'text', 'label' => 'Title', 'localized' => true, 'value' => $title ),
				array( 'path' => 'intro', 'type' => 'wysiwyg', 'label' => 'Intro', 'localized' => true, 'value' => $intro ),
				array( 'path' => 'code', 'type' => 'textarea', 'label' => 'Snippet', 'code' => true, 'localized' => true, 'value' => $code ),
				array( 'path' => 'price', 'type' => 'text', 'label' => 'Price', 'localized' => false, 'value' => '90 €' ),
				array( 'path' => 'cta', 'type' => 'link', 'label' => 'Button', 'localized' => true, 'value' => '' === $intro ? null : array( 'url' => '/contact', 'title' => 'Book an audit', 'target' => '' ) ),
				array( 'path' => 'steps', 'type' => 'repeater', 'label' => 'Steps', 'localized' => true, 'value' => $rows, 'sub_fields' => array(
					array( 'path' => 'title', 'type' => 'text', 'label' => 'Title', 'max_length' => 20 ),
					array( 'path' => 'image', 'type' => 'image', 'label' => 'Image' ),
				) ),
			) ),
		),
	);
};
Leiho_Schema::$by_locale = array(
	'en' => $tr_schema( 'Air audit', '<p>We <strong>measure</strong> the air.</p>', array( array( 'title' => 'Visit', 'image' => 5 ), array( 'title' => 'Report', 'image' => 6 ) ), '<div>x</div>' ),
	'fr' => $tr_schema( 'Audit de l’air', '', array( array( 'title' => 'Visite', 'image' => 5 ), array( 'title' => '', 'image' => 6 ) ), '' ),
);
$units = Leiho_AI_Translate::units( 90, 'fr' );
$by    = array_column( $units['units'], null, 'path' );
check( 'traduction : textes traduisibles seulement (pas de code, pas de champ partagé)', array( '_title', 'intro', 'cta', 'steps.0.title', 'steps.1.title' ) === array_keys( $by ), array_keys( $by ) );
check( 'traduction : original et traduction actuelle', 'Report' === $by['steps.1.title']['source'] && '' === $by['steps.1.title']['translation'] );
check( 'traduction : format html et longueur', 'html' === $by['intro']['format'] && 20 === $by['steps.0.title']['max_length'] );
check( 'traduction : langue par défaut refusée', is_wp_error( Leiho_AI_Translate::units( 90, 'en' ) ) && is_wp_error( Leiho_AI_Translate::units( 90, 'de' ) ) );
check( 'traduction : type sans langues refusé', is_wp_error( Leiho_AI_Translate::units( 91, 'fr' ) ) );

Fake_Builder::$log     = array();
Fake_Builder::$error   = null;
Fake_Builder::$answers = array( '{"t1":"<p>Nous <strong>mesurons</strong> l’air.</p><script>x</script>","t2":"Réserver un audit","t3":"Rapport"}' );
$res = Leiho_AI_Translate::translate( 90, 'fr' );
check( 'traduction IA : seulement les textes manquants', ! is_wp_error( $res ) && array( 'intro', 'cta', 'steps.1.title' ) === array_keys( (array) $res['translations'] ), $res );
check( 'traduction IA : lien complet, adresse d\'origine', array( 'url' => '/contact', 'title' => 'Réserver un audit', 'target' => '' ) === ( (array) $res['values'] )['cta'], $res['values'] );
$prompt = end( Fake_Builder::$log )['prompt'];
check( 'traduction IA : langues et règle des balises dans la demande', false !== strpos( $prompt, 'to French (fr)' ) && false !== strpos( $prompt, 'keep every HTML tag' ) && false !== strpos( $prompt, 'We <strong>measure</strong>' ) );
check( 'traduction IA : HTML nettoyé', false === strpos( $res['translations']->intro, '<script' ) );
$values = (array) $res['values'];
check( 'traduction IA : valeur complète de la liste, traduction existante gardée', 'Visite' === $values['steps'][0]['title'] && 'Rapport' === $values['steps'][1]['title'] && 6 === $values['steps'][1]['image'], $values );
Fake_Builder::$answers = array( '{"t1":"x"}' );
$res = Leiho_AI_Translate::translate( 90, 'fr', 'all' );
check( 'traduction IA : texte manquant signalé', ! is_wp_error( $res ) && 'missing' === $res['warnings']->intro, $res );

Leiho_Schema::$saved = array();
$w = Leiho_AI_Translate::write( 90, 'fr', array( '_title' => 'Autre', 'steps.1.title' => 'Rapport', 'price' => '1 €' ), false );
check( 'agent : entrée en ligne, texte déjà traduit refusé', 'already_translated' === $w['errors']->_title, $w );
check( 'agent : champ partagé refusé', 'not_translatable' === $w['errors']->price );
check( 'agent : entrée en ligne, rien d\'écrit dans la traduction servie', ! isset( Leiho_Schema::$saved[90] ) && array() === $w['saved'], Leiho_Schema::$saved );
check( 'agent : texte vide proposé comme suggestion à relire', array( 'steps.1.title' => 'Rapport' ) === Leiho_I18n::$suggested[90]['fr'] && array( 'steps.1.title' ) === $w['pending'], array( Leiho_I18n::$suggested, $w ) );
$w = Leiho_AI_Translate::write( 90, 'fr', array( '_title' => 'Autre' ), true );
check( 'agent : brouillon, tout modifiable', array( '_title' ) === $w['saved'] );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
