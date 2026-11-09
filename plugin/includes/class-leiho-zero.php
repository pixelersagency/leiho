<?php
defined( 'ABSPATH' ) || exit;

/**
 * Leiho Zero : thème vide livré avec Leiho, pour un WordPress qui ne sert que
 * de back-office. Aucun CSS, aucun JS, aucun gabarit à charger ; l'admin
 * garde les images à la une et les menus.
 *
 * Leiho le copie dans wp-content/themes et l'active ; l'ancien thème est
 * retenu pour revenir en arrière en un clic. Avant, Leiho lit le code du
 * thème actif : un type de contenu, un groupe de champs ou un menu déclaré
 * dans functions.php disparaîtrait avec lui.
 */
class Leiho_Zero {

	const SLUG            = 'leiho-zero';
	const OPTION_PREVIOUS = 'leiho_previous_theme';

	/** Code d'un thème qui disparaît avec lui (noms de fonctions et de filtres). */
	const PATTERNS = array(
		'register_post_type',
		'register_taxonomy',
		'acf_add_local_field_group',
		'acf/settings/save_json',
		'acf/settings/load_json',
		'register_nav_menus',
		'register_block_type',
		'register_rest_route',
		'register_rest_field',
		'add_image_size',
		'add_shortcode',
	);

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_leiho_zero_install', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_leiho_zero_restore', array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * @return bool
	 */
	public static function is_active() {
		return self::SLUG === get_stylesheet();
	}

	/**
	 * L'utilisateur peut-il installer et activer un thème ?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'switch_themes' ) && current_user_can( 'install_themes' );
	}

	/**
	 * Thème retenu avant Leiho Zero, s'il existe encore.
	 *
	 * @return WP_Theme|null
	 */
	public static function previous() {
		$slug = (string) get_option( self::OPTION_PREVIOUS, '' );
		if ( '' === $slug || self::SLUG === $slug ) {
			return null;
		}
		$theme = wp_get_theme( $slug );
		return $theme->exists() ? $theme : null;
	}

	/**
	 * Ce que le thème actif déclare dans son code (thème parent compris) et
	 * qui disparaîtrait en changeant de thème.
	 *
	 * @return string[] Fonctions trouvées.
	 */
	public static function theme_code() {
		if ( self::is_active() ) {
			return array();
		}
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$found = array();
		foreach ( $dirs as $dir ) {
			foreach ( self::php_files( $dir ) as $file ) {
				$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local du thème.
				foreach ( self::PATTERNS as $needle ) {
					if ( false !== strpos( $code, $needle ) ) {
						$found[ $needle ] = $needle;
					}
				}
			}
		}
		return array_values( $found );
	}

	/**
	 * Fichiers PHP d'un thème, hors dépendances et gabarits de blocs : deux
	 * niveaux, 300 fichiers et 1 Mo par fichier au plus.
	 *
	 * @param string $dir Dossier du thème.
	 * @return string[]
	 */
	private static function php_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$queue = array( array( $dir, 0 ) );
		while ( $queue && count( $files ) < 300 ) {
			list( $current, $depth ) = array_shift( $queue );
			$entries                 = scandir( $current );
			foreach ( false === $entries ? array() : $entries as $entry ) {
				if ( '.' === $entry[0] || in_array( $entry, array( 'vendor', 'node_modules' ), true ) ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( is_dir( $path ) ) {
					if ( $depth < 2 ) {
						$queue[] = array( $path, $depth + 1 );
					}
				} elseif ( '.php' === substr( $entry, -4 ) && filesize( $path ) < 1048576 ) {
					$files[] = $path;
				}
			}
		}
		return $files;
	}

	/**
	 * Copie le thème livré avec Leiho dans le dossier des thèmes (il est
	 * remplacé s'il y est déjà) et l'active.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$target = trailingslashit( get_theme_root() ) . self::SLUG;
		if ( ! WP_Filesystem() || 'direct' !== get_filesystem_method( array(), get_theme_root() ) ) {
			return new WP_Error(
				'leiho_zero_fs',
				__( 'WordPress can’t write to the themes folder on this server. Copy the theme/leiho-zero folder of the Leiho plugin into wp-content/themes, then activate Leiho Zero under Appearance → Themes.', 'leiho' )
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem->is_dir( $target ) && ! $wp_filesystem->mkdir( $target, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'leiho_zero_fs', __( 'The Leiho Zero folder could not be created in wp-content/themes.', 'leiho' ) );
		}
		$copied = copy_dir( LEIHO_DIR . 'theme/' . self::SLUG, $target );
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}
		wp_clean_themes_cache();

		$theme = wp_get_theme( self::SLUG );
		if ( ! $theme->exists() || $theme->errors() ) {
			return new WP_Error( 'leiho_zero_missing', __( 'Leiho Zero was copied but WordPress can’t read it.', 'leiho' ) );
		}

		if ( ! self::is_active() ) {
			update_option( self::OPTION_PREVIOUS, get_stylesheet(), false );
			switch_theme( self::SLUG );
		}
		return true;
	}

	/**
	 * Revient au thème d'avant Leiho Zero.
	 *
	 * @return true|WP_Error
	 */
	public static function restore() {
		$previous = self::previous();
		if ( ! $previous ) {
			return new WP_Error( 'leiho_zero_no_previous', __( 'The previous theme is no longer installed. Choose one under Appearance → Themes.', 'leiho' ) );
		}
		switch_theme( $previous->get_stylesheet() );
		delete_option( self::OPTION_PREVIOUS );
		return true;
	}

	/**
	 * @return void
	 */
	public static function handle_install() {
		self::handle( 'leiho_zero_install', array( __CLASS__, 'install' ), 'installed' );
	}

	/**
	 * @return void
	 */
	public static function handle_restore() {
		self::handle( 'leiho_zero_restore', array( __CLASS__, 'restore' ), 'restored' );
	}

	/**
	 * @param string   $action Action et nonce.
	 * @param callable $run    Opération.
	 * @param string   $done   Drapeau d'affichage en cas de réussite.
	 * @return void
	 */
	private static function handle( $action, $run, $done ) {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Access denied.', 'leiho' ) );
		}
		check_admin_referer( $action );
		$result = call_user_func( $run );
		if ( is_wp_error( $result ) ) {
			// Le message passe par un transient, jamais par l'URL.
			set_transient( self::error_key(), $result->get_error_message(), 120 );
			$done = 'error';
		}
		wp_safe_redirect( Leiho_App::url( 'security', array( 'leiho_zero' => $done ) ) );
		exit;
	}

	/**
	 * @return string
	 */
	private static function error_key() {
		return 'leiho_zero_error_' . get_current_user_id();
	}

	/**
	 * Erreur de la dernière opération, lue une seule fois.
	 *
	 * @return string
	 */
	public static function consume_error() {
		$message = get_transient( self::error_key() );
		delete_transient( self::error_key() );
		return is_string( $message ) ? $message : '';
	}
}
