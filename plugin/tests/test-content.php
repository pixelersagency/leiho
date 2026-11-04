<?php
/**
 * API de contenu et génération de types : php plugin/tests/test-content.php
 */
require __DIR__ . '/stubs.php';

function wp_get_attachment_metadata( $id ) {
	return array(
		'width'  => 1600,
		'height' => 900,
		'sizes'  => array( 'medium' => array( 'file' => $id . '-300x169.jpg', 'width' => 300, 'height' => 169 ) ),
	);
}
function get_post_meta( $id, $key = '', $single = false ) { return '_wp_attachment_image_alt' === $key ? 'Texte alt ' . $id : ''; }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }

require __DIR__ . '/../includes/class-okno-content.php';
require __DIR__ . '/../includes/class-okno-types.php';

$GLOBALS['groups_meta']  = array( array( 'key' => 'group_1', 'title' => 'Contenu' ) );
$GLOBALS['group_fields'] = array(
	'group_1' => array(
		field( 'onglet', 'tab' ),
		field( 'titre', 'text', array( 'required' => true ) ),
		field( 'visuel', 'image' ),
		field( 'prix', 'number' ),
		field( 'actif', 'true_false' ),
		field( 'date', 'date_picker' ),
		field( 'ton', 'select', array( 'choices' => array( 'clair' => 'Clair', 'sombre' => 'Sombre' ) ) ),
		field( 'cta', 'link' ),
		field( 'sections', 'flexible_content', array(
			'layouts' => array(
				array( 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array(
					field( 'h1', 'text' ),
					field( 'texte', 'wysiwyg' ),
				) ),
				array( 'name' => 'faq', 'label' => 'FAQ', 'sub_fields' => array(
					field( 'questions', 'repeater', array( 'sub_fields' => array(
						field( 'q', 'text' ),
						field( 'galerie', 'gallery' ),
					) ) ),
				) ),
			),
		) ),
	),
);

$GLOBALS['store'] = array(
	'field_titre'    => 'Accueil',
	'field_visuel'   => 7,
	'field_prix'     => '12.5',
	'field_actif'    => '1',
	'field_date'     => '20261104',
	'field_ton'      => 'sombre',
	'field_cta'      => array( 'url' => '', 'title' => '', 'target' => '' ),
	'field_sections' => array(
		array( 'acf_fc_layout' => 'hero', 'field_h1' => 'Bonjour', 'field_texte' => 'Salut' ),
		array( 'acf_fc_layout' => 'supprime', 'field_h1' => 'Fantôme' ),
		array( 'acf_fc_layout' => 'faq', 'field_questions' => array(
			array( 'field_q' => 'Prix ?', 'field_galerie' => array( 7, 8, 0 ) ),
		) ),
	),
);

$adapter = new Okno_ACF_Adapter();
$content = $adapter->get_content( 1 );

/* --- Valeurs servies au front --- */
assert_true( 'onglet ignoré', ! array_key_exists( 'onglet', $content ), array_keys( $content ) );
assert_true( 'texte', 'Accueil' === $content['titre'] );
assert_true( 'image = objet média', 7 === $content['visuel']['id'] && 'https://cdn/7.jpg' === $content['visuel']['url'], $content['visuel'] );
assert_true( 'image alt et dimensions', 'Texte alt 7' === $content['visuel']['alt'] && 1600 === $content['visuel']['width'] );
assert_true( 'tailles d’image', 'https://cdn/7-300x169.jpg' === $content['visuel']['sizes']->medium['url'], $content['visuel']['sizes'] );
assert_true( 'nombre', 12.5 === $content['prix'], $content['prix'] );
assert_true( 'booléen', true === $content['actif'] );
assert_true( 'date ISO', '2026-11-04' === $content['date'], $content['date'] );
assert_true( 'select', 'sombre' === $content['ton'] );
assert_true( 'lien vide = null', null === $content['cta'], $content['cta'] );
assert_true( 'flexible : layouts connus seulement', 2 === count( $content['sections'] ), $content['sections'] );
assert_true( 'flexible : _layout en tête', '_layout' === array_keys( $content['sections'][0] )[0], $content['sections'][0] );
assert_true( 'wysiwyg rendu', '<p>Salut</p>' === $content['sections'][0]['texte'], $content['sections'][0] );
assert_true( 'repeater imbriqué', 'Prix ?' === $content['sections'][1]['questions'][0]['q'] );
assert_true( 'galerie sans id vide', 2 === count( $content['sections'][1]['questions'][0]['galerie'] ), $content['sections'][1]['questions'][0]['galerie'] );

/* --- Nombre stocké invalide --- */
assert_true( 'nombre invalide = null', null === $adapter->public_value( field( 'n', 'number' ), 'abc' ) );
assert_true( 'nombre vide = null', null === $adapter->public_value( field( 'n', 'number' ), '' ) );

/* --- Rattachement des groupes ACF à un type --- */
$page_only = array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) );
$template  = array( array( array( 'param' => 'page_template', 'operator' => '==', 'value' => 'tpl-service.php' ) ) );
$mixed     = array(
	array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ), array( 'param' => 'post_category', 'operator' => '==', 'value' => 'category:actus' ) ),
	array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
);
$options   = array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'reglages' ) ) );
assert_true( 'groupe des pages : toujours', false === Okno_ACF_Adapter::targets( $page_only, 'page' ) );
assert_true( 'groupe des pages : pas les articles', null === Okno_ACF_Adapter::targets( $page_only, 'post' ) );
assert_true( 'modèle de page : sous condition', true === Okno_ACF_Adapter::targets( $template, 'page' ) );
assert_true( 'catégorie : article sous condition', true === Okno_ACF_Adapter::targets( $mixed, 'post' ) );
assert_true( 'une branche inconditionnelle suffit', false === Okno_ACF_Adapter::targets( $mixed, 'page' ) );
assert_true( 'page d’options ignorée', null === Okno_ACF_Adapter::targets( $options, 'page' ) );

/* --- Types TypeScript --- */
$ts = Okno_Types::render(
	array(
		'page'          => array(
			array(
				'conditional' => false,
				'fields'      => $GLOBALS['group_fields']['group_1'],
			),
		),
		'dyese_service' => array(
			array(
				'conditional' => true,
				'fields'      => array( field( 'accroche-courte', 'textarea' ), field( 'liens', 'relationship' ) ),
			),
		),
	),
	'https://cms.example.com'
);
assert_true( 'en-tête', 0 === strpos( $ts, '// Generated by Okno test from https://cms.example.com' ) );
assert_true( 'interface de champs', false !== strpos( $ts, 'export interface PageFields {' ) );
assert_true( 'nom de type en PascalCase', false !== strpos( $ts, "export type DyeseService = OknoItem<'dyese_service', DyeseServiceFields>;" ) );
assert_true( 'union des contenus', false !== strpos( $ts, 'export type OknoContent = Page | DyeseService;' ) );
assert_true( 'onglet absent des types', false === strpos( $ts, 'onglet' ) );
assert_true( 'image', false !== strpos( $ts, "\tvisuel: OknoMedia | null;" ) );
assert_true( 'select en littéraux', false !== strpos( $ts, "\tton: 'clair' | 'sombre' | '';" ), $ts );
assert_true( 'flexible discriminé', false !== strpos( $ts, "_layout: 'hero';" ) && false !== strpos( $ts, "_layout: 'faq';" ) );
assert_true( 'repeater dans layout', false !== strpos( $ts, 'questions: Array<{' ) );
assert_true( 'champ conditionnel optionnel', false !== strpos( $ts, "\t'accroche-courte'?: string;" ), $ts );
assert_true( 'relation', false !== strpos( $ts, "\tliens?: OknoRef[];" ) );
assert_true( 'libellé en commentaire', false !== strpos( $ts, "\t/** Titre */" ) );

// Le fichier généré doit être du TypeScript valide : vérifié par tsc s'il est là.
$tsc = trim( (string) shell_exec( 'command -v tsc 2>/dev/null' ) );
if ( '' !== $tsc ) {
	$file = sys_get_temp_dir() . '/okno-types-' . getmypid() . '.d.ts';
	file_put_contents( $file, $ts );
	exec( escapeshellarg( $tsc ) . ' --noEmit --strict ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
	unlink( $file );
	assert_true( 'tsc accepte le fichier', 0 === $code, implode( "\n", $output ) );
}

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
