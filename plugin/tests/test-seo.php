<?php
/**
 * SEO et contenu pour les IA, sans WordPress : php plugin/tests/test-seo.php
 *
 * Bloc seo d'une collection (validation), bloc seo et JSON-LD d'un contenu,
 * llms.txt, Markdown d'une entrée et conversion HTML → Markdown.
 */
define( 'ABSPATH', true );

$GLOBALS['meta']        = array();
$GLOBALS['collections'] = array();

function __( $s, $d = '' ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url_raw( $v ) { return (string) $v; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function post_type_supports( $type, $feature ) { return 'post' === $type && 'author' === $feature; }
function get_the_author_meta( $field, $user ) { return 'user_login' === $field ? ( 2 === (int) $user ? 'Ada' : 'ada_admin' ) : 'Ada'; }
function get_bloginfo( $key ) { return 'description' === $key ? 'Sites & builds' : 'en-US'; }
function get_post_type_object( $type ) { return (object) array( 'labels' => (object) array( 'name' => ucfirst( $type ) . 's' ) ); }
class Leiho_Collections {
	public static function for_post_type( $type ) { return isset( $GLOBALS['collections'][ $type ] ) ? $GLOBALS['collections'][ $type ] : null; }
}

require __DIR__ . '/../includes/class-leiho-text.php';
require __DIR__ . '/../includes/class-leiho-seo.php';
require __DIR__ . '/../includes/class-leiho-llms.php';

$failed = 0;
function check( $label, $cond, $dump = null ) {
	global $failed;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$failed++;
		if ( null !== $dump ) {
			echo '     ' . var_export( $dump, true ) . "\n";
		}
	}
}

/* --- Validation du bloc seo --- */

$fields = array(
	array( 'name' => 'summary', 'type' => 'text' ),
	array( 'name' => 'body', 'type' => 'richtext' ),
	array( 'name' => 'cover', 'type' => 'image' ),
	array( 'name' => 'count', 'type' => 'number' ),
	array(
		'name'   => 'faq',
		'type'   => 'list',
		'fields' => array(
			array( 'name' => 'question', 'type' => 'text' ),
			array( 'name' => 'answer', 'type' => 'richtext' ),
		),
	),
	array( 'name' => 'items', 'type' => 'list', 'fields' => array( array( 'name' => 'label', 'type' => 'text' ) ) ),
);

$errors = array();
$seo    = Leiho_Seo::normalize( array( 'type' => 'TechArticle', 'description' => 'summary', 'image' => 'cover', 'faq' => 'faq' ), $fields, 'collections.0.seo', $errors );
check( 'bloc valide gardé tel quel', array( 'type' => 'TechArticle', 'description' => 'summary', 'image' => 'cover', 'faq' => 'faq' ) === $seo && ! $errors, array( $seo, $errors ) );

$errors = array();
Leiho_Seo::normalize( array( 'type' => 'Blog', 'description' => 'nope', 'image' => 'summary', 'faq' => 'items' ), $fields, 'collections.0.seo', $errors );
$paths = array_column( $errors, 'path' );
check( 'type, champ inconnu, mauvais type et FAQ sans question refusés', array( 'collections.0.seo.type', 'collections.0.seo.description', 'collections.0.seo.image', 'collections.0.seo.faq' ) === $paths, $errors );

$errors = array();
check( 'bloc vide : null', null === Leiho_Seo::normalize( array(), $fields, 'seo', $errors ) && null === Leiho_Seo::normalize( null, $fields, 'seo', $errors ) );
check( 'llms: false gardé', array( 'llms' => false ) === Leiho_Seo::normalize( array( 'llms' => false ), $fields, 'seo', $errors ) );

/* --- Bloc seo d'un contenu --- */

$GLOBALS['collections']['doc'] = array(
	'id'     => 'doc',
	'fields' => $fields,
	'seo'    => array( 'type' => 'TechArticle', 'description' => 'summary', 'image' => 'cover', 'faq' => 'faq' ),
);

$item = array(
	'id'             => 5,
	'type'           => 'doc',
	'url'            => 'https://example.com/docs/install',
	'title'          => 'Install',
	'date'           => '2026-01-02T10:00:00+00:00',
	'modified'       => '2026-02-03T10:00:00+00:00',
	'excerpt'        => 'From the excerpt',
	'content'        => '',
	'featured_image' => null,
	'locale'         => 'fr',
	'fields'         => (object) array(
		'summary' => 'Install the plugin in two minutes.',
		'body'    => '<h2>Steps</h2><p>Upload <code>leiho.zip</code>, then <a href="https://example.com/x">connect</a>.</p><ul><li>One</li><li>Two</li></ul><pre><code class="language-bash">npm i leiho</code></pre>',
		'cover'   => array( 'url' => 'https://cdn/c.jpg', 'alt' => 'Cover', 'mime_type' => 'image/jpeg' ),
		'count'   => 3,
		'faq'     => array(
			array( 'question' => 'Free?', 'answer' => '<p>Yes.</p>' ),
			array( 'question' => '', 'answer' => 'ignored' ),
		),
		'items'   => array( array( 'label' => 'Alpha' ) ),
	),
);
$post = (object) array( 'ID' => 5, 'post_type' => 'doc', 'post_author' => 1 );
$seo  = Leiho_Seo::item( $post, $item );

check( 'description tirée du champ, en texte brut', 'Install the plugin in two minutes.' === $seo['description'], $seo['description'] );
check( 'image tirée du champ', 'https://cdn/c.jpg' === $seo['image']['url'] );
check( 'FAQ : lignes complètes seulement, réponse en texte', array( array( 'question' => 'Free?', 'answer' => 'Yes.' ) ) === $seo['faq'], $seo['faq'] );
$types = array_column( $seo['jsonld'], '@type' );
check( 'JSON-LD : page, article, FAQ', array( 'WebPage', 'TechArticle', 'FAQPage' ) === $types, $types );
check( 'article : dates, langue, page', '2026-02-03T10:00:00+00:00' === $seo['jsonld'][1]['dateModified'] && 'fr' === $seo['jsonld'][1]['inLanguage'] && 'https://example.com/docs/install#webpage' === $seo['jsonld'][1]['mainEntityOfPage']['@id'], $seo['jsonld'][1] );
check( 'pas d\'auteur hors articles de blog', ! isset( $seo['jsonld'][1]['author'] ) );
check( 'FAQ rattachée à la page', 'https://example.com/docs/install#webpage' === $seo['jsonld'][2]['isPartOf']['@id'] );

// Contenu sans collection : description d'une extension SEO, puis extrait.
$page = array_merge( $item, array( 'type' => 'page', 'fields' => (object) array() ) );
$GLOBALS['meta'][9]['_yoast_wpseo_metadesc'] = 'From Yoast';
check( 'description Yoast', 'From Yoast' === Leiho_Seo::item( (object) array( 'ID' => 9, 'post_type' => 'page', 'post_author' => 1 ), $page )['description'] );
$GLOBALS['meta'][9]['_yoast_wpseo_metadesc'] = '%%excerpt%%';
$plain = Leiho_Seo::item( (object) array( 'ID' => 9, 'post_type' => 'page', 'post_author' => 1 ), $page );
check( 'modèle Yoast ignoré : extrait', 'From the excerpt' === $plain['description'] );
check( 'page sans type : WebPage seul', array( 'WebPage' ) === array_column( $plain['jsonld'], '@type' ) );

$blog = Leiho_Seo::item( (object) array( 'ID' => 9, 'post_type' => 'post', 'post_author' => 1 ), array_merge( $page, array( 'type' => 'post' ) ) );
$anon = Leiho_Seo::item( (object) array( 'ID' => 9, 'post_type' => 'post', 'post_author' => 2 ), array_merge( $page, array( 'type' => 'post' ) ) );
check( 'nom affiché = identifiant de connexion : pas d\'auteur publié', ! isset( $anon['jsonld'][1]['author'] ), $anon['jsonld'][1] );
check( 'article de blog : BlogPosting avec auteur', 'BlogPosting' === $blog['jsonld'][1]['@type'] && 'Ada' === $blog['jsonld'][1]['author']['name'], $blog['jsonld'] );

$GLOBALS['collections']['faq'] = array( 'id' => 'faq', 'fields' => $fields, 'seo' => array( 'type' => 'FAQPage', 'faq' => 'faq' ) );
$faq = Leiho_Seo::item( (object) array( 'ID' => 6, 'post_type' => 'faq', 'post_author' => 1 ), array_merge( $item, array( 'type' => 'faq' ) ) );
check( 'page FAQ : les questions sont l\'objet de la page', array( 'FAQPage' ) === array_column( $faq['jsonld'], '@type' ) && 1 === count( $faq['jsonld'][0]['mainEntity'] ), $faq['jsonld'] );

$GLOBALS['collections']['guess'] = array( 'id' => 'guess', 'fields' => array( array( 'name' => 'kicker', 'type' => 'text' ), array( 'name' => 'intro', 'type' => 'text' ), array( 'name' => 'long', 'type' => 'text' ) ) );
$guess = array_merge( $item, array( 'type' => 'guess', 'fields' => (object) array( 'kicker' => 'Hi', 'intro' => 'Short intro.', 'long' => str_repeat( 'word ', 12 ) ) ) );
check( 'sans bloc seo : champ nommé comme un résumé', 'Short intro.' === Leiho_Seo::item( (object) array( 'ID' => 7, 'post_type' => 'guess', 'post_author' => 1 ), $guess )['description'] );
$guess['fields'] = (object) array( 'kicker' => 'Hi', 'long' => str_repeat( 'word ', 12 ) );
check( 'sinon premier texte assez long', 'word word' === substr( Leiho_Seo::item( (object) array( 'ID' => 7, 'post_type' => 'guess', 'post_author' => 1 ), $guess )['description'], 0, 9 ) );

check( 'description coupée au mot', 'aaa bbb…' === Leiho_Seo::shorten( 'aaa bbb ccc', 9 ), Leiho_Seo::shorten( 'aaa bbb ccc', 9 ) );

/* --- Markdown --- */

$item['seo'] = $seo;
$item['fields'] = (array) $item['fields'];
$md = Leiho_Llms::markdown( $item );
check( 'en-tête : titre, description, adresse, langue, date', 0 === strpos( $md, "---\ntitle: \"Install\"\ndescription: \"Install the plugin in two minutes.\"\nurl: https://example.com/docs/install\nlang: fr\nupdated: 2026-02-03\n---\n\n# Install\n" ), $md );
check( 'description issue d\'un champ : écrite une fois dans le texte', 1 === substr_count( substr( $md, strpos( $md, '# Install' ) ), 'in two minutes' ), $md );
check( 'texte riche en Markdown', false !== strpos( $md, "## Steps\n\nUpload `leiho.zip`, then [connect](https://example.com/x).\n\n- One\n- Two\n\n```bash\nnpm i leiho\n```" ), $md );
check( 'image avec texte alternatif', false !== strpos( $md, '![Cover](https://cdn/c.jpg)' ) );
check( 'nombre ignoré', false === strpos( $md, "\n3\n" ) );
check( 'FAQ en questions', false !== strpos( $md, "### Free?\n\nYes." ), $md );

check( 'tableau HTML', "| A | B |\n| --- | --- |\n| 1 | 2 \\| 3 |" === Leiho_Llms::html( '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2 | 3</td></tr></table>' ), Leiho_Llms::html( '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2 | 3</td></tr></table>' ) );
check( 'liste numérotée imbriquée', "1. A\n   - b\n2. C" === Leiho_Llms::html( '<ol><li>A<ul><li>b</li></ul></li><li>C</li></ol>' ), Leiho_Llms::html( '<ol><li>A<ul><li>b</li></ul></li><li>C</li></ol>' ) );
check( 'adresse Markdown', 'https://ex.com/docs/x.md' === Leiho_Llms::markdown_url( 'https://ex.com/docs/x/' ) && 'https://ex.com/index.md' === Leiho_Llms::markdown_url( 'https://ex.com' ) );

/* --- llms.txt --- */

$hidden = array_merge( $item, array( 'url' => 'https://example.com/docs/secret', 'title' => 'Secret', 'seo' => array_merge( $seo, array( 'llms' => false ) ) ) );
$export = array(
	'site'  => array( 'name' => 'Example', 'description' => 'Sites & builds' ),
	'items' => array( $item, $hidden ),
);
$llms = Leiho_Llms::llms( $export );
check( 'llms.txt : nom, description, rubrique, entrée', "# Example\n\n> Sites & builds\n\n## Docs\n\n- [Install](https://example.com/docs/install): Install the plugin in two minutes.\n" === $llms, $llms );
check( 'llms.txt : liens .md sur demande', false !== strpos( Leiho_Llms::llms( $export, false, true ), '(https://example.com/docs/install.md)' ) );
$full = Leiho_Llms::llms( $export, true );
check( 'llms-full : contenu à la suite, entrée exclue absente', false !== strpos( $full, "Source: https://example.com/docs/install (updated 2026-02-03)\n\n# Install" ) && false === strpos( $full, 'Secret' ), $full );

check( 'texte brut : « x<1 » et « <3 » ne sont pas des balises', 'x<1 et <3 : < 1 000 UFC/L' === Leiho_Seo::plain( 'x<1 et <3 : &lt; 1 000 UFC/L' ) );
check( 'texte brut : balises retirées', 'Bonjour le monde' === Leiho_Seo::plain( '<p>Bonjour <b>le</b> monde</p><script>x()</script>' ) );

echo $failed ? "\n$failed ÉCHEC(S)\n" : "\nTOUS LES TESTS PASSENT\n";
exit( $failed ? 1 : 0 );
