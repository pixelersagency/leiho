<?php
/**
 * Tests du diagnostic d'en-têtes : php plugin/tests/test-frame-verdict.php
 */
define( 'ABSPATH', true );
function __( $s, $d = '' ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
require __DIR__ . '/../includes/class-leiho-rest.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

$wp = 'https://cms.exemple.fr';
$v  = function ( $csp, $xfo = '' ) use ( $wp ) {
	return Leiho_Rest::frame_verdict( $csp, $xfo, $wp )['level'];
};

check( 'origine exacte autorisée', 'ok' === $v( "default-src 'self'; frame-ancestors 'self' https://cms.exemple.fr" ) );
check( 'origine avec slash final', 'ok' === $v( "frame-ancestors https://cms.exemple.fr/" ) );
check( 'joker de sous-domaine', 'ok' === $v( "frame-ancestors 'self' https://*.exemple.fr" ) );
check( 'étoile', 'ok' === $v( 'frame-ancestors *' ) );
check( "'none' bloque", 'error' === $v( "frame-ancestors 'none'" ) );
check( 'autre origine bloque', 'error' === $v( "frame-ancestors 'self' https://autre.fr" ) );
check( 'joker d’un autre domaine bloque', 'error' === $v( 'frame-ancestors https://*.autre.fr' ) );
check( 'X-Frame-Options DENY bloque', 'error' === $v( '', 'DENY' ) );
check( 'X-Frame-Options SAMEORIGIN bloque', 'error' === $v( '', 'sameorigin' ) );
check( 'frame-ancestors prime sur X-Frame-Options', 'ok' === $v( "frame-ancestors $wp", 'DENY' ) );
check( 'aucun en-tête : fonctionne, avec avertissement', 'warn' === $v( '' ) );
check( 'directive en majuscules', 'ok' === $v( "FRAME-ANCESTORS https://cms.exemple.fr" ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
