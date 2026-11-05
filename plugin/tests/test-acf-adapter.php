<?php
require __DIR__ . '/stubs.php';

$GLOBALS['groups_meta'] = array( array( 'key' => 'group_1', 'title' => 'Contenu' ) );
$GLOBALS['group_fields'] = array(
	'group_1' => array(
		field( 'titre', 'text', array( 'required' => true, 'maxlength' => 20 ) ),
		field( 'sections', 'flexible_content', array(
			'layouts' => array(
				array( 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array(
					field( 'h1', 'text' ),
					field( 'image', 'image' ),
				) ),
				array( 'name' => 'faq', 'label' => 'FAQ', 'sub_fields' => array(
					field( 'questions', 'repeater', array( 'sub_fields' => array(
						field( 'q', 'text' ),
						field( 'r', 'wysiwyg' ),
					) ) ),
				) ),
			),
		) ),
		field( 'reglages', 'group', array( 'sub_fields' => array(
			field( 'adresse', 'text' ),
			field( 'lien', 'link' ),
			field( 'mode', 'radio' ),
		) ) ),
	),
);

$GLOBALS['store'] = array(
	'field_titre'    => 'Accueil',
	'field_sections' => array(
		array( 'acf_fc_layout' => 'hero', 'field_h1' => 'Bonjour', 'field_image' => 7 ),
		array( 'acf_fc_layout' => 'faq', 'field_questions' => array(
			array( 'field_q' => 'Prix ?', 'field_r' => 'Sur devis' ),
		) ),
	),
	'field_reglages' => array( 'field_adresse' => 'Paris', 'field_lien' => array( 'url' => 'https://a.fr', 'title' => 'A', 'target' => '' ) ),
);

$adapter = new Leiho_ACF_Adapter();

/* --- Lecture --- */
$schema = $adapter->get_schema( 1 );
$fields = $schema[0]['fields'];
$flex = $fields[1];
assert_true( 'flexible_content supporté', true === $flex['supported'] );
assert_true( 'layouts décrits', 2 === count( $flex['layouts'] ) );
assert_true( 'ligne 0 = hero', 'hero' === $flex['value'][0]['_layout'], $flex['value'][0] );
assert_true( 'valeur sous-champ lue', 'Bonjour' === $flex['value'][0]['h1'] );
assert_true( 'meta image', 'https://cdn/7.jpg' === $flex['meta'][0]['image']['url'] );
assert_true( 'repeater imbriqué dans layout', 'Prix ?' === $flex['value'][1]['questions'][0]['q'], $flex['value'][1] );
assert_true( 'wysiwyg rendu', '<p>Sur devis</p>' === $flex['meta'][1]['questions'][0]['r']['rendered'] );
$group = $fields[2];
assert_true( 'group lu', 'Paris' === $group['value']['adresse'] );
assert_true( 'link lu', 'https://a.fr' === $group['value']['lien']['url'] );

/* --- Écriture par chemin pointé --- */
$result = $adapter->save_values( 1, array( 'sections.0.h1' => 'Bonsoir' ) );
assert_true( 'patch pointé enregistré', array( 'sections.0.h1' ) === $result['saved'], $result );
assert_true( 'patch appliqué', 'Bonsoir' === $GLOBALS['store']['field_sections'][0]['field_h1'], $GLOBALS['store']['field_sections'][0] );
assert_true( 'autres lignes préservées', isset( $GLOBALS['store']['field_sections'][1]['field_questions'] ) );

$result = $adapter->save_values( 1, array( 'sections.1.questions.0.q' => 'Délais ?' ) );
assert_true( 'patch profond', 'Délais ?' === $GLOBALS['store']['field_sections'][1]['field_questions'][0]['field_q'], $result );

$result = $adapter->save_values( 1, array( 'reglages.adresse' => 'Lyon' ) );
assert_true( 'patch group', 'Lyon' === $GLOBALS['store']['field_reglages']['field_adresse'], $result );

/* Sous-champ que Leiho n'édite pas : renvoyé par l'éditeur, filtré quand même. */
$result = $adapter->save_values( 1, array( 'reglages' => array( 'adresse' => 'Lyon', 'mode' => 'R&D<script>alert(1)</script>' ) ) );
assert_true( 'sous-champ non pris en charge : HTML filtré', 'R&D' === $GLOBALS['store']['field_reglages']['field_mode'], array( $result, $GLOBALS['store']['field_reglages'] ) );

/* --- Écriture du champ entier (réordonnancement + nouveau bloc) --- */
$rows = array(
	array( '_layout' => 'faq', 'questions' => array( array( 'q' => 'Q1', 'r' => 'R1' ) ) ),
	array( '_layout' => 'hero', 'h1' => 'Nouveau', 'image' => 7 ),
);
$result = $adapter->save_values( 1, array( 'sections' => $rows ) );
assert_true( 'remplacement complet accepté', array( 'sections' ) === $result['saved'], $result );
$written = $GLOBALS['store']['field_sections'];
assert_true( 'layout écrit en acf_fc_layout', 'faq' === $written[0]['acf_fc_layout'], $written[0] );
assert_true( 'ordre appliqué', 'hero' === $written[1]['acf_fc_layout'] );
assert_true( 'sous-repeater écrit par key', 'Q1' === $written[0]['field_questions'][0]['field_q'], $written[0] );

/* --- Layout inconnu refusé --- */
$result = $adapter->save_values( 1, array( 'sections' => array( array( '_layout' => 'nope' ) ) ) );
assert_true( 'layout inconnu refusé', 'unknown_layout' === $result['errors']['sections'], $result );

/* --- required --- */
$result = $adapter->save_values( 1, array( 'titre' => '   ' ) );
assert_true( 'champ requis vide refusé', 'required' === $result['errors']['titre'], $result );

/* --- update_field qui échoue --- */
$GLOBALS['update_ok'] = false;
$result = $adapter->save_values( 1, array( 'reglages.adresse' => 'Marseille' ) );
assert_true( 'échec ACF rapporté', 'save_failed' === $result['errors']['reglages.adresse'], $result );
assert_true( 'échec non compté comme saved', empty( $result['saved'] ), $result );
$GLOBALS['update_ok'] = true;

/* --- update_field renvoie false mais la base a la bonne valeur ---
 * (repeater/groupe : la méta parente ne change pas, ACF renvoie false). */
$GLOBALS['update_ok'] = 'noop';
$result = $adapter->save_values( 1, array( 'reglages.adresse' => 'Nantes' ) );
assert_true( 'false sans changement réel = enregistré', array( 'reglages.adresse' ) === $result['saved'] && empty( $result['errors'] ), $result );
$GLOBALS['update_ok'] = true;

/* --- chemin inconnu --- */
$result = $adapter->save_values( 1, array( 'sections.9.h1' => 'x' ) );
assert_true( 'ligne inexistante refusée', 'row_not_found' === $result['errors']['sections.9.h1'], $result );
$result = $adapter->save_values( 1, array( 'inexistant' => 'x' ) );
assert_true( 'champ étranger ignoré', empty( $result['saved'] ) && empty( $result['errors'] ), $result );

/* --- maxlength ACF --- */
$schema = $adapter->get_schema( 1 );
assert_true( 'max_length exposé dans le schéma', 20 === $schema[0]['fields'][0]['max_length'], $schema[0]['fields'][0] );
$result = $adapter->save_values( 1, array( 'titre' => str_repeat( 'é', 21 ) ) );
assert_true( 'texte trop long refusé', 'too_long' === $result['errors']['titre'], $result );
$result = $adapter->save_values( 1, array( 'titre' => str_repeat( 'é', 20 ) ) );
assert_true( 'limite comptée en caractères, pas en octets', array( 'titre' ) === $result['saved'], $result );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
