<?php
/**
 * Leiho Zero: nothing loads on the front, the back office keeps what it needs.
 *
 * @package Leiho_Zero
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function () {
		// Features of the back office that WordPress ties to the theme:
		// featured images, menus, the document title, HTML5 markup.
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'menus' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'html5', array( 'comment-list', 'comment-form', 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'responsive-embeds' );
	}
);
