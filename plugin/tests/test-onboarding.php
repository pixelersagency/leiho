<?php
/**
 * Écran « Démarrer » : php plugin/tests/test-onboarding.php
 *
 * Frameworks et extraits (adresses remplies, aucun gabarit oublié), consigne
 * pour l'agent IA, progression, et rendu HTML complet de l'écran (panneaux
 * cachés ou visibles selon ?fw=, identifiants uniques, boutons de test).
 */
define( 'ABSPATH', true );
define( 'LEIHO_DIR', dirname( __DIR__ ) . '/' );
define( 'LEIHO_URL', 'https://cms.exemple.fr/wp-content/plugins/leiho/' );

$GLOBALS['settings']   = array( 'front_url' => 'https://www.exemple.fr', 'preview_url' => '', 'content_access' => 'public' );
$GLOBALS['connection'] = false;
$GLOBALS['locale']     = 'en_US';

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function checked( $a, $b ) { echo (string) $a === (string) $b ? ' checked="checked"' : ''; }
function disabled( $c ) { echo $c ? ' disabled="disabled"' : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://cms.exemple.fr/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://cms.exemple.fr/wp-json/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function determine_locale() { return $GLOBALS['locale']; }
function get_option( $k, $d = false ) { return $GLOBALS['connection']; }
function human_time_diff( $t ) { return '5 mins'; }

final class Leiho_Plugin {
	public static function settings() { return $GLOBALS['settings']; }
}
final class Leiho_Admin {
	public static function wp_origin() { return 'https://cms.exemple.fr'; }
}
final class Leiho_App {
	const SLUG = 'leiho';
	public static function url( $view = 'home', $args = array() ) { return admin_url( 'admin.php' ) . '?page=leiho&view=' . $view; }
}
final class Leiho_Rest {
	const NS = 'leiho/v1';
}

require LEIHO_DIR . 'includes/admin/class-leiho-dashboard.php';

function check( $label, $cond, $dump = null ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		if ( null !== $dump ) { var_export( $dump ); echo "\n"; }
		$GLOBALS['failed'] = true;
	}
}

function render_start() {
	ob_start();
	Leiho_Dashboard::render_view( 'start' );
	return ob_get_clean();
}

/* --- Données des frameworks --- */

$ctx = Leiho_Dashboard::start_context();
check( 'adresses : origine wp-admin, export, contenu', 'https://cms.exemple.fr' === $ctx['wp'] && 'https://cms.exemple.fr/wp-json/leiho/v1/export' === $ctx['export'] && 'https://www.exemple.fr' === $ctx['front'], $ctx );

$frameworks = Leiho_Dashboard::frameworks( $ctx );
check( 'six frameworks, dans l’ordre', array( 'next', 'astro', 'nuxt', 'sveltekit', 'vite', 'other' ) === array_keys( $frameworks ) );

foreach ( $frameworks as $id => $fw ) {
	$codes = array();
	foreach ( array( 'frame', 'bridge', 'content', 'annotate' ) as $step ) {
		$group    = $fw[ $step ];
		$variants = ! empty( $group['tabs'] ) ? $group['tabs'] : array( array( 'blocks' => $group['blocks'] ) );
		$codes[ $step ] = '';
		foreach ( $variants as $variant ) {
			foreach ( $variant['blocks'] as $block ) {
				check( "$id/$step : fichier et code", '' !== $block['file'] && '' !== trim( $block['code'] ) );
				$codes[ $step ] .= $block['code'] . "\n";
			}
		}
	}
	$all = implode( "\n", $codes );
	check( "$id : aucun gabarit {{…}} oublié", ! preg_match( '/\{\{(WP|EXPORT|CONTENT)\}\}/', $all ) );
	check( "$id : en-tête frame-ancestors avec l’origine", false !== strpos( $codes['frame'], "frame-ancestors 'self' https://cms.exemple.fr" ) );
	check( "$id : bridge branché sur l’origine", false !== strpos( $codes['bridge'], 'https://cms.exemple.fr' ) );
	check( "$id : annotations data-wp-post et data-wp-field", false !== strpos( $codes['annotate'], 'data-wp-post' ) && false !== strpos( $codes['annotate'], 'data-wp-field' ) );
	check( "$id : lecture du contenu par l’API Leiho", false !== strpos( $codes['content'], 'https://cms.exemple.fr/wp-json/leiho/v1/' ) );
}

check( 'Next.js : composant React dans app/layout.tsx', false !== strpos( $frameworks['next']['bridge']['blocks'][1]['code'], "from 'leiho/react'" ) && 'app/layout.tsx' === $frameworks['next']['bridge']['blocks'][1]['file'] );
check( 'Nuxt : plugin client initLeihoBridge', 'plugins/leiho.client.ts' === $frameworks['nuxt']['bridge']['blocks'][1]['file'] && false !== strpos( $frameworks['nuxt']['bridge']['blocks'][1]['code'], 'initLeihoBridge' ) );
check( 'Astro : hébergeurs en onglets', array( 'netlify', 'vercel', 'node' ) === array_keys( $frameworks['astro']['frame']['tabs'] ) );
check( 'Vite : en-têtes dev et preview', false !== strpos( $frameworks['vite']['frame']['tabs']['dev']['blocks'][0]['code'], 'preview:' ) );
check( 'Autre : nginx, Apache, en-tête brut', array( 'nginx', 'apache', 'header' ) === array_keys( $frameworks['other']['frame']['tabs'] ) );
check( 'Autre : lien de téléchargement du bridge', ! empty( $frameworks['other']['bridge']['download'] ) );
$vercel = json_decode( $frameworks['astro']['frame']['tabs']['vercel']['blocks'][0]['code'], true );
check( 'vercel.json valide', isset( $vercel['headers'][0]['headers'][0]['value'] ) && "frame-ancestors 'self' https://cms.exemple.fr" === $vercel['headers'][0]['headers'][0]['value'], $vercel );

/* --- Consigne pour l'agent --- */

$prompt = Leiho_Dashboard::start_prompt( $frameworks['sveltekit'], 'existing', $ctx );
check( 'consigne : framework et adresses', false !== strpos( $prompt, 'Framework: SvelteKit' ) && false !== strpos( $prompt, 'https://cms.exemple.fr' ) && false !== strpos( $prompt, 'Site address: https://www.exemple.fr' ) );
check( 'consigne : extraits du framework', false !== strpos( $prompt, 'src/hooks.server.ts' ) && false !== strpos( $prompt, '+layout.svelte' ) && false !== strpos( $prompt, "```\n" ) );
check( 'consigne : site existant, finit sur le dépôt', 'Site repository:' === substr( $prompt, -strlen( 'Site repository:' ) ) );
check( 'consigne : nouveau site, finit sur le brief', 'Site idea or brief:' === substr( Leiho_Dashboard::start_prompt( $frameworks['next'], 'new', $ctx ), -strlen( 'Site idea or brief:' ) ) );
check( 'consigne : variantes d’hébergeur toutes données', false !== strpos( Leiho_Dashboard::start_prompt( $frameworks['astro'], 'existing', $ctx ), 'Vercel — vercel.json' ) );
$GLOBALS['settings']['front_url'] = '';
check( 'consigne : adresse du site absente signalée', false !== strpos( Leiho_Dashboard::start_prompt( $frameworks['next'], 'existing', Leiho_Dashboard::start_context() ), 'not set yet' ) );
$GLOBALS['settings']['front_url'] = 'https://www.exemple.fr';

/* --- Progression --- */

$p = Leiho_Dashboard::start_progress( false, null );
check( 'rien de fait', array() === array_filter( $p ) && 6 === count( $p ) );
$p = Leiho_Dashboard::start_progress( true, array( 'ok' => false, 'fields' => 0 ) );
check( 'adresse seule', array( 'url' ) === array_keys( array_filter( $p ) ) );
$p = Leiho_Dashboard::start_progress( true, array( 'ok' => true, 'fields' => 0 ) );
check( 'bridge sans champ : en-tête, bridge, test', array( 'url', 'frame', 'bridge', 'test' ) === array_keys( array_filter( $p ) ) );
$p = Leiho_Dashboard::start_progress( true, array( 'ok' => true, 'fields' => 4 ) );
check( 'bridge et champs : tout', 6 === count( array_filter( $p ) ) );

/* --- Rendu --- */

function dom( $html ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
	$errors = array_filter(
		libxml_get_errors(),
		static function ( $e ) {
			// Balises HTML5 inconnues de libxml (section, progress…) : sans objet.
			return false === strpos( $e->message, 'invalid in Entity' ) && false === strpos( $e->message, 'Tag ' );
		}
	);
	libxml_clear_errors();
	return array( new DOMXPath( $doc ), $errors );
}

$html = render_start();
list( $xp, $errors ) = dom( $html );
check( 'HTML bien formé', array() === $errors, array_map( static function ( $e ) { return trim( $e->message ) . ' @' . $e->line; }, $errors ) );

$ids = array();
foreach ( $xp->query( '//*[@id]' ) as $node ) {
	$ids[] = $node->getAttribute( 'id' );
}
check( 'identifiants uniques', count( $ids ) === count( array_unique( $ids ) ), array_diff_assoc( $ids, array_unique( $ids ) ) );
check( 'un seul bouton « Check headers »', 1 === $xp->query( '//*[@data-leiho-check-headers]' )->length );
check( 'un seul bouton de test', 1 === $xp->query( '//*[@data-leiho-test-bridge]' )->length );
check( 'six étapes', 6 === $xp->query( '//*[@data-leiho-step]' )->length );
check( 'six frameworks au choix', 6 === $xp->query( '//input[@data-leiho-fw-choice]' )->length );
check( 'sans choix : aucun framework coché', 0 === $xp->query( '//input[@data-leiho-fw-choice][@checked]' )->length );
check( 'sans choix : la suite est cachée', 1 === $xp->query( '//*[@data-leiho-after-fw][@hidden]' )->length );
check( 'sans choix : tous les panneaux cachés', 0 === $xp->query( '//*[@data-leiho-fw][not(@hidden)]' )->length );
foreach ( $xp->query( '//*[@data-leiho-copy-from]' ) as $button ) {
	foreach ( preg_split( '/\s+/', $button->getAttribute( 'data-leiho-copy-from' ) ) as $source ) {
		if ( ! in_array( $source, $ids, true ) ) {
			check( "source de copie $source présente", false );
		}
	}
}
check( 'douze consignes (6 frameworks × 2 cas)', 12 === $xp->query( '//textarea[contains(@id, "leiho-prompt-")]' )->length );
check( 'guide complet présent', 1 === $xp->query( '//textarea[@id="leiho-instructions"]' )->length && false !== strpos( $html, 'https://cms.exemple.fr' ) );
foreach ( $xp->query( '//*[@aria-controls]' ) as $tab ) {
	if ( ! in_array( $tab->getAttribute( 'aria-controls' ), $ids, true ) ) {
		check( 'onglet relié à son panneau : ' . $tab->getAttribute( 'aria-controls' ), false );
	}
}
check( 'progression : 1 sur 6 (adresse)', false !== strpos( $html, '1 of 6 steps done' ) );

$_GET['fw']               = 'astro';
$_GET['path']             = 'ai';
$GLOBALS['connection']    = array( 'ok' => true, 'fields' => 3, 'version' => '5', 'at' => time() );
$html                     = render_start();
list( $xp )               = dom( $html );
$visible                  = array();
foreach ( $xp->query( '//*[@data-leiho-fw][not(@hidden)]' ) as $node ) {
	$visible[] = $node->getAttribute( 'data-leiho-fw' );
}
check( '?fw=astro : seuls les panneaux Astro visibles', array( 'astro' ) === array_values( array_unique( $visible ) ) && 5 === count( $visible ), $visible );
check( '?fw=astro : carte cochée', 'astro' === $xp->query( '//input[@data-leiho-fw-choice][@checked]' )->item( 0 )->getAttribute( 'value' ) );
check( '?path=ai : parcours IA visible, pas à pas caché', 0 === $xp->query( '//*[@data-leiho-path-panel="ai"][@hidden]' )->length && 1 === $xp->query( '//*[@data-leiho-path-panel="steps"][@hidden]' )->length );
check( 'connexion réussie : 6 sur 6', false !== strpos( $html, '6 of 6 steps done' ) && 6 === $xp->query( '//*[contains(@class, "leiho-step--done")]' )->length );

$_GET['fw'] = '<script>';
list( $xp ) = dom( render_start() );
check( '?fw inconnu : ignoré', 0 === $xp->query( '//input[@data-leiho-fw-choice][@checked]' )->length );

$GLOBALS['locale'] = 'fr_FR';
check( 'wp-admin en français : guide en français', false !== strpos( render_start(), 'Ton rôle' ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
