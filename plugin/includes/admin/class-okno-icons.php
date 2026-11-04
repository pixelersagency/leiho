<?php
defined( 'ABSPATH' ) || exit;

/**
 * Icônes SVG inline, partagées par toutes les pages d'Okno.
 *
 * Aucune police d'icônes : Dashicons peut être déchargée par un plugin ou un
 * thème d'admin, et ses glyphes manquants débordaient sur les libellés.
 * Les mêmes tracés sont repris dans editor.js.
 */
class Okno_Icons {

	const PATHS = array(
		'back'     => array( 'M15 5l-7 7 7 7' ),
		'arrow'    => array( 'M5 12h14', 'M13 6l6 6-6 6' ),
		'external' => array( 'M14 4h6v6', 'M20 4l-9 9', 'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5' ),
		'desktop'  => array( 'M3 4h18v12H3z', 'M8 20h8', 'M12 16v4' ),
		'tablet'   => array( 'M6 3h12v18H6z', 'M11 18h2' ),
		'mobile'   => array( 'M8 2h8v20H8z', 'M11 19h2' ),
		'page'     => array( 'M6 2h7l5 5v15H6z', 'M13 2v5h5' ),
		'section'  => array( 'M3 5h18', 'M3 12h18', 'M3 19h12' ),
		'undo'     => array( 'M9 7l-5 5 5 5', 'M4 12h10a5 5 0 0 1 0 10h-3' ),
		'redo'     => array( 'M15 7l5 5-5 5', 'M20 12H10a5 5 0 0 0 0 10h3' ),
		'moon'     => array( 'M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z' ),
		'history'  => array( 'M3 12a9 9 0 1 0 3-6.7', 'M3 4v5h5', 'M12 8v4l3 2' ),
		'check'    => array( 'M5 12.5l4.5 4.5L19 7.5' ),
		'plug'     => array( 'M9 3v5', 'M15 3v5', 'M6 8h12v3a6 6 0 0 1-12 0z', 'M12 17v4' ),
		'rocket'   => array( 'M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2', 'M9 15l-3-3a13 13 0 0 1 9-8.5 4 4 0 0 1 4.5 4.5A13 13 0 0 1 12 18z', 'M14.5 9.5h.01' ),
		'sparkle'  => array( 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z', 'M19 17l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z' ),
		'copy'     => array( 'M9 9h11v11H9z', 'M5 15H4V4h11v1' ),
		'home'     => array( 'M3 11l9-7 9 7', 'M5 10v10h14V10', 'M10 20v-6h4v6' ),
		'edit'     => array( 'M4 20h4L19 9l-4-4L4 16z', 'M13 7l4 4' ),
		'list'     => array( 'M8 6h13', 'M8 12h13', 'M8 18h13', 'M3.5 6h.01', 'M3.5 12h.01', 'M3.5 18h.01' ),
		'database' => array( 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z', 'M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6', 'M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3' ),
		'image'    => array( 'M3 5h18v14H3z', 'M3 16l5-5 4 4 3-3 6 6', 'M15.5 9h.01' ),
		'settings' => array( 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z', 'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z' ),
		'wordpress' => array( 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M5 8l4.5 12', 'M9 8l4 11 4-11', 'M4 8h7', 'M14 8h5' ),
		'panel'    => array( 'M3 4h18v16H3z', 'M9 4v16' ),
		'menu'     => array( 'M4 6h16', 'M4 12h16', 'M4 18h16' ),
		'plus'     => array( 'M12 5v14', 'M5 12h14' ),
		'user'     => array( 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 21a8 8 0 0 1 16 0' ),
		'logout'   => array( 'M15 4h4v16h-4', 'M10 8l-4 4 4 4', 'M6 12h11' ),
		'upload'   => array( 'M12 16V4', 'M7 9l5-5 5 5', 'M4 16v4h16v-4' ),
	);

	/**
	 * Balisage SVG d'une icône (déjà échappé).
	 *
	 * @param string $name Nom.
	 * @param int    $size Taille en px.
	 * @return string
	 */
	public static function svg( $name, $size = 16 ) {
		$d = '';
		foreach ( isset( self::PATHS[ $name ] ) ? self::PATHS[ $name ] : array() as $path ) {
			$d .= '<path d="' . esc_attr( $path ) . '" />';
		}

		return sprintf(
			'<svg class="okno-icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			(int) $size,
			$d
		);
	}

	/**
	 * Affiche une icône.
	 *
	 * @param string $name Nom.
	 * @param int    $size Taille en px.
	 */
	public static function render( $name, $size = 16 ) {
		echo self::svg( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Construit à partir de constantes, attributs échappés.
	}

	/**
	 * Icône du menu WordPress en data URI : WordPress la recolore selon le
	 * schéma de couleurs de l'utilisateur.
	 *
	 * @return string
	 */
	public static function menu_icon() {
		$path = OKNO_DIR . 'assets/img/okno-menu.svg';
		if ( ! file_exists( $path ) ) {
			return 'dashicons-admin-generic';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fichier local du plugin.
		return 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $path ) );
	}
}
