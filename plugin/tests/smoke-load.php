<?php
/**
 * Smoke test de chargement : php plugin/tests/smoke-load.php
 *
 * Charge tous les fichiers du plugin dans un WordPress factice pour attraper
 * les fatals de chargement (redéclaration, signature d'interface, constante…).
 */
define( 'ABSPATH', '/tmp/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error { public function __construct( $c = '', $m = '', $d = null ) {} public function add_data( $d ) {} public function get_error_message() { return ''; } }
class WP_REST_Request { public function get_param( $k ) {} public function get_json_params() { return array(); } }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; const EDITABLE = 'POST, PUT, PATCH'; }
class WP_Query { public $posts = array(); public $found_posts = 0; public $max_num_pages = 1; public function __construct( $a = array() ) {} }

$called = array();
function add_action( ...$a ) { $GLOBALS['called'][] = 'add_action:' . ( is_string( $a[0] ) ? $a[0] : '?' ); }
function add_filter( ...$a ) {}
function apply_filters( $tag, $value ) { return $value; }
function register_activation_hook( ...$a ) { $GLOBALS['called'][] = 'activation'; }
function register_deactivation_hook( ...$a ) { $GLOBALS['called'][] = 'deactivation'; }
function register_rest_route( ...$a ) {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://wp.test/plugins/leiho/'; }
function get_option( $k, $d = false ) { return $d; }
function add_option( ...$a ) { return true; }
// Base vide : aucune donnée Okno à migrer.
$GLOBALS['wpdb'] = new class() {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
};
function update_option( ...$a ) { return true; }
function get_post_meta( ...$a ) { return ''; }
function update_post_meta( ...$a ) { return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function is_admin() { return false; }
function __( $s, $d = '' ) { return $s; }
function _x( $s, $c, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo $s; }
function esc_attr_e( $s, $d = '' ) { echo $s; }
function function_exists_stub() {}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) {}
function wp_unschedule_event( ...$a ) {}
function wp_generate_password( $l = 12, $s = true ) { return str_repeat( 'a', $l ); }
function current_user_can( ...$a ) { return true; }
function get_post_type_object( $t ) { return null; }
function get_post_types( ...$a ) { return array(); }
function admin_url( $p = '' ) { return 'https://wp.test/wp-admin/' . $p; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function get_transient( $k ) { return false; }
function set_transient( ...$a ) {}
function delete_transient( ...$a ) {}
function wp_remote_request( ...$a ) { return array(); }
function wp_remote_get( ...$a ) { return array(); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return '{}'; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { return $v; }
function wp_kses_post( $v ) { return $v; }
function esc_url_raw( $v ) { return $v; }
function wpautop( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function get_post( $id = 0 ) { return null; }
function get_post_type( $id ) { return 'page'; }
function get_the_title( $p ) { return ''; }
function get_edit_post_link( ...$a ) { return ''; }
function get_post_thumbnail_id( $p ) { return 0; }
function set_post_thumbnail( ...$a ) {}
function delete_post_thumbnail( ...$a ) {}
function wp_get_attachment_url( $id ) { return ''; }
function wp_get_attachment_image_url( ...$a ) { return ''; }
function wp_update_post( ...$a ) { return 1; }
function wp_insert_post( ...$a ) { return 1; }
function wp_trash_post( $id ) { return true; }
function get_terms( $a ) { return array(); }
function get_term( ...$a ) { return null; }
function get_posts( $a ) { return array(); }
function get_object_taxonomies( $t ) { return array(); }
function wp_get_object_terms( ...$a ) { return array(); }
function wp_set_object_terms( ...$a ) {}
function add_post_meta( ...$a ) {}
function maybe_unserialize( $v ) { return $v; }
function rest_ensure_response( $v ) { return $v; }
function date_i18n( $f, $t ) { return date( $f, $t ); }
function wp_get_current_user() { return (object) array( 'ID' => 1, 'display_name' => 'Test' ); }
function get_current_user_id() { return 1; }
function wp_safe_redirect( $u ) {}
function check_admin_referer( ...$a ) {}
function wp_nonce_field( ...$a ) {}
function submit_button( ...$a ) {}
function checked( ...$a ) {}
function selected( ...$a ) {}
function wp_list_pluck( $l, $f ) { return array_column( $l, $f ); }
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_enqueue_media( ...$a ) {}
function wp_enqueue_editor( ...$a ) {}
function wp_add_inline_script( ...$a ) {}
function add_menu_page( ...$a ) {}
function add_submenu_page( ...$a ) {}
function wp_die( ...$a ) {}
function wp_unslash( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function esc_attr( $v ) { return $v; }
function esc_html( $v ) { return $v; }

require __DIR__ . '/../leiho.php';

echo "chargement OK\n";
echo 'adapter chargé ? ' . ( class_exists( 'Leiho_ACF_Adapter' ) ? 'oui' : 'NON' ) . "\n";
echo 'fichiers inclus : ' . count( get_included_files() ) . "\n";
foreach ( get_included_files() as $f ) { if ( false !== strpos( $f, 'Leiho' ) || false !== strpos( $f, 'leiho' ) ) { echo '  - ' . basename( $f ) . "\n"; } }
$plugin = Leiho_Plugin::instance();
echo "boot OK — hooks: " . implode( ', ', array_slice( $GLOBALS['called'], 0, 6 ) ) . "\n";
echo "settings OK — driver='" . Leiho_Plugin::settings()['deploy_driver'] . "'\n";
new Leiho_Rest( $plugin );
new Leiho_Schema( $plugin );
new Leiho_ACF_Adapter();
new Leiho_Native_Adapter();
new Leiho_Collections_Rest();
echo 'collections : ' . count( Leiho_Collections::post_types() ) . "\n";
new Leiho_Github_Actions_Driver();
new Leiho_Github_Commit_Driver();
new Leiho_Build_Hook_Driver();
new Leiho_Coolify_Driver();
echo "instanciations OK\n";
Leiho_Deploy_Manager::cleanup_stale();
Leiho_Deploy_Manager::schedule_cleanup();
echo "cron OK\n";

// IA : sans le client d'IA de WordPress 7 (WordPress plus ancien), Leiho se
// charge et les outils d'IA sont masqués ; les capacités ne s'enregistrent
// que si l'Abilities API existe. (Stubs déclarés dans des blocs : une
// fonction déclarée au niveau du fichier existerait dès la compilation.)
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function remove_filter( ...$a ) {}
$smoke_ai_ok = true;
$ai          = Leiho_AI::status( true );
$smoke_ai_ok = $smoke_ai_ok && ! $ai['client'] && ! $ai['text'] && ! Leiho_AI::client_config()['enabled'] && '' === Leiho_AI::client_config()['connectorsUrl'];
echo 'IA sans WordPress 7 : ' . ( $smoke_ai_ok ? 'masquée' : 'ÉCHEC' ) . "\n";
Leiho_AI_Abilities::register_category();
Leiho_AI_Abilities::register();
Leiho_AI_Admin_Abilities::register();
echo "capacités sans Abilities API : OK\n";
$err         = Leiho_AI::generate( '', 'x' );
$smoke_ai_ok = $smoke_ai_ok && $err instanceof WP_Error;
echo 'génération sans client : ' . ( $err instanceof WP_Error ? 'erreur propre' : 'ÉCHEC' ) . "\n";

// Avec le client d'IA (stubs), mais sans fournisseur configuré : toujours masquée.
if ( true ) {
	class Smoke_AI_Builder {
		public function __call( $name, $args ) {
			return 'is_supported_for_text_generation' === $name ? false : $this;
		}
	}
	function wp_supports_ai() { return true; }
	function wp_ai_client_prompt( $prompt = null ) { return new Smoke_AI_Builder(); }
	function wp_register_ability_category( $slug, $args ) { $GLOBALS['registered'][] = $slug; }
	function wp_register_ability( $name, $args ) { $GLOBALS['registered'][] = $name; }
}
$ai          = Leiho_AI::status( true );
$no_provider = $ai['client'] && ! $ai['text'] && ! Leiho_AI::client_config()['enabled'] && '' !== Leiho_AI::client_config()['connectorsUrl'];
$smoke_ai_ok = $smoke_ai_ok && $no_provider;
echo 'IA sans fournisseur : ' . ( $no_provider ? 'masquée, lien des connecteurs' : 'ÉCHEC' ) . "\n";
$GLOBALS['registered'] = array();
Leiho_AI_Abilities::register_category();
Leiho_AI_Abilities::register();
Leiho_AI_Admin_Abilities::register();
// Catégorie + 10 capacités de contenu (pas de texte alternatif sans
// fournisseur qui lit les images) + 4 capacités d'administration.
$smoke_abilities = 15 === count( $GLOBALS['registered'] ) && ! in_array( 'leiho/suggest-alt-text', $GLOBALS['registered'], true );
$smoke_ai_ok     = $smoke_ai_ok && $smoke_abilities;
echo 'capacités : ' . ( $smoke_abilities ? 'enregistrées' : 'ÉCHEC' ) . "\n";
exit( $smoke_ai_ok ? 0 : 1 );
