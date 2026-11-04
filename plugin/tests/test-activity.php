<?php
/**
 * Tests du diff du journal : php plugin/tests/test-activity.php
 */
define( 'ABSPATH', true );
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
require __DIR__ . '/../includes/class-leiho-activity.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

function schema( $h1, $cards, $adresse ) {
	return array(
		'groups' => array(
			array(
				'fields' => array(
					array( 'path' => '_title', 'label' => 'Titre', 'type' => 'text', 'value' => 'Accueil' ),
					array(
						'path' => 'sections', 'label' => 'Sections', 'type' => 'flexible_content',
						'layouts' => array(
							array( 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array(
								array( 'path' => 'h1', 'label' => 'Titre H1', 'type' => 'text' ),
							) ),
						),
						'value' => array( array( '_layout' => 'hero', 'h1' => $h1 ) ),
					),
					array(
						'path' => 'cartes', 'label' => 'Cartes', 'type' => 'repeater',
						'sub_fields' => array( array( 'path' => 'titre', 'label' => 'Titre carte', 'type' => 'text' ) ),
						'value' => $cards,
					),
					array(
						'path' => 'reglages', 'label' => 'Réglages', 'type' => 'group',
						'sub_fields' => array( array( 'path' => 'adresse', 'label' => 'Adresse', 'type' => 'text' ) ),
						'value' => array( 'adresse' => $adresse ),
					),
				),
			),
		),
	);
}

$before = schema( 'Bonjour', array( array( 'titre' => 'A' ) ), 'Paris' );
$after  = schema( 'Bonsoir', array( array( 'titre' => 'A' ), array( 'titre' => 'B' ) ), 'Lyon' );

$rows = Leiho_Activity::diff( $before, $after, array( 'sections.0.h1', 'cartes', 'reglages.adresse', '_title', 'inconnu' ) );
$by   = array();
foreach ( $rows as $r ) { $by[ $r['path'] ] = $r; }

check( 'chemin flexible : avant', 'Bonjour' === $by['sections.0.h1']['old'], $by );
check( 'chemin flexible : après', 'Bonsoir' === $by['sections.0.h1']['new'] );
check( 'libellé composé lisible', 'Sections › #1 › Titre H1' === $by['sections.0.h1']['label'], $by['sections.0.h1']['label'] );
check( 'type du sous-champ', 'text' === $by['sections.0.h1']['type'] );
check( 'repeater entier : 1 → 2 lignes', 2 === count( $by['cartes']['new'] ) && 1 === count( $by['cartes']['old'] ) );
check( 'group : libellé', 'Réglages › Adresse' === $by['reglages.adresse']['label'], $by['reglages.adresse']['label'] );
check( 'group : valeurs', 'Paris' === $by['reglages.adresse']['old'] && 'Lyon' === $by['reglages.adresse']['new'] );
check( 'chemin inconnu ignoré', ! isset( $by['inconnu'] ) );

check( 'sérialisation structure en JSON', '[{"titre":"A"}]' === Leiho_Activity::serialize( array( array( 'titre' => 'A' ) ) ), Leiho_Activity::serialize( array( array( 'titre' => 'A' ) ) ) );
check( 'sérialisation booléen', '1' === Leiho_Activity::serialize( true ) );
check( 'sérialisation accents conservés', '"é"' === Leiho_Activity::serialize( array( 'é' ) ) || '["é"]' === Leiho_Activity::serialize( array( 'é' ) ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
