<?php
/**
 * Contenu en blocs ↔ HTML édité dans Leiho. Demande l'analyseur de blocs de
 * WordPress : WP_DIR=/chemin/vers/wordpress php plugin/tests/test-blocks.php
 * (sans WP_DIR, le test est sauté).
 */
$wp = getenv( 'WP_DIR' );
if ( ! $wp || ! is_file( $wp . '/wp-includes/class-wp-block-parser.php' ) ) {
	echo "WP_DIR absent : test sauté.\n";
	exit( 0 );
}
define( 'ABSPATH', rtrim( $wp, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
function apply_filters( $tag, $value ) { return $value; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return (string) $v; }
function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function _wp_array_get( $a, $p, $d = null ) { return $d; }
require ABSPATH . WPINC . '/class-wp-block-parser-block.php';
require ABSPATH . WPINC . '/class-wp-block-parser-frame.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
function parse_blocks( $c ) { return ( new WP_Block_Parser() )->parse( $c ); }
// serialize_block() et ses dépendances, tels que dans wp-includes/blocks.php.
function strip_core_block_namespace( $n = null ) { return is_string( $n ) && 0 === strpos( $n, 'core/' ) ? substr( $n, 5 ) : $n; }
function serialize_block_attributes( $a ) {
	$s = wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$s = preg_replace( '/--/', '\\u002d\\u002d', $s );
	$s = preg_replace( '/</', '\\u003c', $s );
	$s = preg_replace( '/>/', '\\u003e', $s );
	$s = preg_replace( '/&/', '\\u0026', $s );
	return preg_replace( '/\\\\"/', '\\u0022', $s );
}
function get_comment_delimited_block_content( $name, $attrs, $content ) {
	if ( null === $name ) { return $content; }
	$s = strip_core_block_namespace( $name );
	$a = empty( $attrs ) ? '' : serialize_block_attributes( $attrs ) . ' ';
	return empty( $content ) ? sprintf( '<!-- wp:%s %s/-->', $s, $a ) : sprintf( '<!-- wp:%s %s-->%s<!-- /wp:%s -->', $s, $a, $content, $s );
}
function serialize_block( $block ) {
	$out = ''; $i = 0;
	foreach ( $block['innerContent'] as $chunk ) { $out .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $i++ ] ); }
	if ( ! is_array( $block['attrs'] ) ) { $block['attrs'] = array(); }
	return get_comment_delimited_block_content( $block['blockName'], $block['attrs'], $out );
}
function wpautop( $v ) { return $v; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
require __DIR__ . '/../includes/class-leiho-blocks.php';

$failures = 0;
function check( $label, $ok, $debug = null ) {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) { $failures++; if ( null !== $debug ) { echo '     ' . var_export( $debug, true ) . "\n"; } }
}

$orig = "<!-- wp:paragraph {\"dropCap\":true} -->\n<p class=\"has-drop-cap\">Un <strong>gras</strong>.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Titre</h3>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Un</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Deux<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Deux A</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Fin.</p>\n<!-- /wp:paragraph -->";

check( 'blocs courants : éditables', array() === Leiho_Blocks::unsupported( $orig ) );
check( 'colonnes : non éditables', array( 'core/columns' ) === Leiho_Blocks::unsupported( '<!-- wp:columns --><div class="wp-block-columns"></div><!-- /wp:columns -->' ) );
$html = Leiho_Blocks::to_html( $orig );
check( 'HTML sans commentaires de blocs', false === strpos( $html, '<!--' ) );
check( 'aller-retour sans modification : contenu identique', $orig === Leiho_Blocks::from_html( $html, $orig ) );

// Ce que renvoie TinyMCE : retours à la ligne entre les <li>, classes gardées.
$tiny = "<p class=\"has-drop-cap\">Un <strong>gras</strong>.</p>\n<h3 class=\"wp-block-heading\">Titre</h3>\n<ul class=\"wp-block-list\">\n<li>Un</li>\n<li>Deux\n<ul class=\"wp-block-list\">\n<li>Deux A</li>\n</ul>\n</li>\n</ul>\n<p>Fin modifiée.</p>";
$out  = Leiho_Blocks::from_html( $tiny, $orig );
check( 'blocs inchangés repris tels quels (lettrine, liste imbriquée)', false !== strpos( $out, '{"dropCap":true}' ) && false !== strpos( $out, "<li>Deux<!-- wp:list -->\n<ul" ) );
check( 'bloc modifié : nouveau paragraphe', false !== strpos( $out, '<!-- wp:paragraph --><p>Fin modifiée.</p><!-- /wp:paragraph -->' ), $out );

$new = Leiho_Blocks::from_html( "<h2>Nouveau</h2>\n<h4 style=\"text-align: center;\">Centré</h4>\n<p style=\"text-align: right;\">Droite</p>\n<ol><li>x</li></ol>\n<blockquote><p>Cite</p></blockquote>\n<hr />\n<p><img class=\"alignnone size-medium wp-image-12\" src=\"/a.jpg\" alt=\"A\" /></p>\n<div class=\"x\">libre</div>", '' );
check( 'titre 2 sans attribut de niveau', false !== strpos( $new, '<!-- wp:heading --><h2 class="wp-block-heading">Nouveau</h2>' ) );
check( 'titre centré', false !== strpos( $new, '<!-- wp:heading {"level":4,"textAlign":"center"} --><h4 class="wp-block-heading has-text-align-center">' ) );
check( 'paragraphe aligné', false !== strpos( $new, '<!-- wp:paragraph {"align":"right"} --><p class="has-text-align-right">Droite</p>' ) );
check( 'liste numérotée', false !== strpos( $new, '<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><!-- wp:list-item --><li>x</li>' ) );
check( 'citation', false !== strpos( $new, '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Cite</p>' ) );
check( 'séparateur', false !== strpos( $new, '<!-- wp:separator -->' ) );
check( 'image de la médiathèque', false !== strpos( $new, '<!-- wp:image {"id":12,"sizeSlug":"medium","linkDestination":"none"} --><figure class="wp-block-image size-medium"><img src="/a.jpg" alt="A" class="wp-image-12"/></figure>' ), $new );
check( 'élément sans équivalent : HTML libre gardé', false !== strpos( $new, '<div class="x">libre</div>' ) && false === strpos( $new, 'wp:html' ) );

// Bloc non convertible : encadré verrouillé, remis intact.
$mixed = "<!-- wp:paragraph -->\n<p>Avant.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>A</td><td>B</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n\n<!-- wp:paragraph -->\n<p>Après.</p>\n<!-- /wp:paragraph -->";
$html  = Leiho_Blocks::to_html( $mixed );
check( 'bloc non convertible : encadré verrouillé avec son rang', false !== strpos( $html, 'data-leiho-block="2"' ) && false !== strpos( $html, 'contenteditable="false"' ) && false === strpos( $html, '<table' ), $html );
check( 'contenu mixte : aller-retour identique', $mixed === Leiho_Blocks::from_html( $html, $mixed ), Leiho_Blocks::from_html( $html, $mixed ) );
// TinyMCE retire contenteditable et kses aussi : data-leiho-block suffit.
$edited = str_replace( array( 'Après.', ' contenteditable="false"' ), array( 'Après modifié.', '' ), $html );
$out    = Leiho_Blocks::from_html( $edited, $mixed );
check( 'contenu mixte modifié : tableau intact, paragraphe modifié', false !== strpos( $out, "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table>" ) && false !== strpos( $out, '<p>Après modifié.</p>' ), $out );
check( 'encadré supprimé : bloc retiré', false === strpos( Leiho_Blocks::from_html( '<p>Avant.</p>', $mixed ), 'wp:table' ) );
check( 'rang inventé : ignoré', false === strpos( Leiho_Blocks::from_html( '<div data-leiho-block="99">x</div><div data-leiho-block="1">x</div>', $mixed ), '<!--' ) );

echo $failures ? "\nÉCHECS.\n" : "\nTOUS LES TESTS PASSENT\n";
exit( $failures ? 1 : 0 );
