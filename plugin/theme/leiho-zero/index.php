<?php
/**
 * The only template. Visitors are normally sent to the front end by Leiho
 * before reaching it.
 *
 * @package Leiho_Zero
 */

defined( 'ABSPATH' ) || exit;

$leiho_zero_settings = get_option( 'leiho_settings' );
$leiho_zero_front    = is_array( $leiho_zero_settings ) && ! empty( $leiho_zero_settings['front_url'] ) ? (string) $leiho_zero_settings['front_url'] : '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#0b0b0b;color:#e8e8e8}a{color:#86c400}</style>
</head>
<body>
<main>
<?php if ( '' !== $leiho_zero_front ) : ?>
	<p><a href="<?php echo esc_url( $leiho_zero_front ); ?>"><?php echo esc_html( preg_replace( '#^https?://#', '', $leiho_zero_front ) ); ?></a></p>
<?php else : ?>
	<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
<?php endif; ?>
</main>
</body>
</html>
