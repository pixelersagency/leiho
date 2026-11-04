<?php
/**
 * Stubs WordPress/ACF minimaux pour tester l'adapter hors WordPress.
 * Lancement : php plugin/tests/test-acf-adapter.php
 */

define( 'ABSPATH', true );
define( 'LEIHO_VERSION', 'test' );

$GLOBALS['store'] = array();
$GLOBALS['update_ok'] = true;
$GLOBALS['update_calls'] = array();

function acf_get_field_groups( $args ) { return $GLOBALS['groups_meta']; }
function acf_get_fields( $key ) { return $GLOBALS['group_fields'][ $key ]; }
function get_field( $key, $post_id, $format = true ) {
	return isset( $GLOBALS['store'][ $key ] ) ? $GLOBALS['store'][ $key ] : null;
}
function update_field( $key, $value, $post_id ) {
	$GLOBALS['update_calls'][ $key ] = $value;
	if ( ! $GLOBALS['update_ok'] ) { return false; }
	$GLOBALS['store'][ $key ] = $value;
	// 'noop' : écrit mais renvoie false, comme update_metadata() sur une méta inchangée.
	return 'noop' === $GLOBALS['update_ok'] ? false : true;
}
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_kses_post( $v ) { return preg_replace( '#<script.*?</script>#is', '', (string) $v ); }
function esc_url_raw( $v ) { return (string) $v; }
function sanitize_email( $v ) { return strpos( $v, '@' ) ? $v : ''; }
function wpautop( $v ) { return '<p>' . $v . '</p>'; }
function apply_filters( $tag, $value ) { return $value; }
function absint( $v ) { return abs( (int) $v ); }
function current_user_can( $cap, $id = 0 ) { return true; }
function get_post_type( $id ) { return in_array( (int) $id, array( 7, 8 ), true ) ? 'attachment' : 'page'; }
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function get_the_title( $id ) { return 'Titre ' . ( is_object( $id ) ? $id->ID : $id ); }
class Leiho_Plugin { public static function title( $p ) { return html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ); } }
function get_edit_post_link( $id, $ctx = '' ) { return 'edit?' . $id; }
function wp_get_attachment_url( $id ) { return 'https://cdn/' . $id . '.jpg'; }
function wp_get_attachment_image_url( $id, $size ) { return 'https://cdn/' . $id . '-' . $size . '.jpg'; }
function get_terms( $args ) { return array(); }
function get_term( $id, $tax = '' ) { return (object) array( 'term_id' => $id, 'name' => 'Terme ' . $id ); }
function get_posts( $args ) { return array(); }
function is_wp_error( $t ) { return false; }
function __( $s, $d = '' ) { return $s; }
function _x( $s, $c, $d = '' ) { return $s; }

require __DIR__ . '/../includes/adapters/interface-leiho-adapter.php';
require __DIR__ . '/../includes/adapters/abstract-leiho-field-adapter.php';
require __DIR__ . '/../includes/adapters/class-leiho-acf-adapter.php';

function field( $name, $type, $extra = array() ) {
	return array_merge(
		array( 'name' => $name, 'key' => 'field_' . $name, 'type' => $type, 'label' => ucfirst( $name ), 'required' => false ),
		$extra
	);
}

function assert_true( $label, $cond, $dump = null ) {
	echo ( $cond ? "ok   " : "FAIL " ) . $label . "\n";
	if ( ! $cond && null !== $dump ) { var_export( $dump ); echo "\n"; }
	if ( ! $cond ) { $GLOBALS['failed'] = true; }
}
