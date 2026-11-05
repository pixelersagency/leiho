<?php
/**
 * Mises à jour depuis les releases GitHub.
 *
 * Leiho ne vient pas de wordpress.org : sans ce fichier, WordPress ne saurait
 * jamais qu'une version plus récente existe. L'en-tête « Update URI » du plugin
 * pointe vers github.com, ce qui (WordPress 5.8+) :
 *  - exclut Leiho de la vérification wordpress.org, qui pourrait sinon proposer
 *    un plugin homonyme ;
 *  - déclenche le filtre update_plugins_github.com, où l'on répond avec la
 *    dernière release du dépôt.
 *
 * La release doit contenir leiho.zip (dossier leiho/ à la racine), ce que produit
 * le workflow .github/workflows/release.yml.
 *
 * @package Leiho
 */

defined( 'ABSPATH' ) || exit;

class Leiho_Updater {

	const REPO      = 'pixelersagency/leiho';
	const ASSET     = 'leiho.zip';
	const TRANSIENT = 'leiho_update_release';
	const OPTION    = 'leiho_update_last_release';
	const SLUG      = 'leiho';

	/**
	 * Clé publique Ed25519 des releases. Chaque leiho.zip est publié avec
	 * leiho.zip.sig : la signature de « leiho », de la version et de
	 * l'empreinte SHA-256 de l'archive (Leiho_Updater::signed_message), faite
	 * par le workflow de release avec la clé privée
	 * correspondante (secret GitHub, jamais dans le dépôt). Une archive dont
	 * la signature manque ou ne correspond pas n'est pas installée : un dépôt
	 * ou un compte GitHub compromis ne suffit pas à pousser du code sur les sites.
	 */
	const SIGNING_KEY = 'ZOkhOTE4TsBPfUo9N3utm8ejHv1H57H1aVKCWOXHf5I=';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_verified' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		// « Vérifier à nouveau » (Tableau de bord → Mises à jour) : sans ça,
		// la release gardée en cache masquait une version publiée entre-temps.
		add_action( 'load-update-core.php', array( __CLASS__, 'flush_on_force_check' ) );
	}

	/**
	 * Vide le cache de la dernière release quand l'admin force la vérification.
	 *
	 * @return void
	 */
	public static function flush_on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau de lecture, comme WordPress.
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			self::flush();
		}
	}

	/**
	 * Réponse au filtre update_plugins_{hostname}.
	 *
	 * @param array|false $update      Réponse d'un autre filtre, ou false.
	 * @param array       $plugin_data En-têtes du plugin.
	 * @param string      $plugin_file Chemin relatif du plugin.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LEIHO_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest_release();
		return $release ? self::from_release( $release ) : $update;
	}

	/**
	 * Archive d'une release officielle ? (adresse des releases du dépôt)
	 *
	 * @param string $url Adresse.
	 * @return bool
	 */
	public static function is_release_package( $url ) {
		$url = (string) $url;
		return 0 === strpos( $url, 'https://github.com/' . self::REPO . '/releases/download/' ) && '/' . self::ASSET === substr( $url, -strlen( '/' . self::ASSET ) );
	}

	/**
	 * Télécharge une mise à jour de Leiho et vérifie sa signature avant que
	 * WordPress ne l'installe. Les autres paquets ne sont pas touchés.
	 *
	 * @param false|string|WP_Error $reply   Réponse d'un filtre précédent.
	 * @param string                $package Adresse du paquet.
	 * @return false|string|WP_Error Chemin du fichier vérifié, ou erreur.
	 */
	public static function download_verified( $reply, $package ) {
		if ( false !== $reply || ! self::is_release_package( $package ) ) {
			return $reply;
		}
		/**
		 * Vérifier la signature des mises à jour de Leiho. À ne couper que pour
		 * tester une archive construite à la main.
		 *
		 * @param bool $verify Défaut true.
		 */
		if ( ! apply_filters( 'leiho_verify_updates', true ) ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$signature = wp_safe_remote_get( $package . '.sig', array( 'timeout' => 30 ) );
		if ( is_wp_error( $signature ) || 200 !== wp_remote_retrieve_response_code( $signature ) ) {
			return new WP_Error( 'leiho_update_unsigned', __( 'This Leiho update has no signature, so it was not installed. Download it from the Leiho releases on GitHub if you trust it.', 'leiho' ) );
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		// La version vient de l'adresse (/releases/download/v1.2.3/leiho.zip) : une
		// ancienne archive signée republiée sous un nouveau tag ne passe pas.
		if ( ! self::verify( (string) file_get_contents( $file ), wp_remote_retrieve_body( $signature ), self::package_version( $package ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local téléchargé.
			wp_delete_file( $file );
			return new WP_Error( 'leiho_update_bad_signature', __( 'The signature of this Leiho update doesn’t match, so it was not installed. Please report it at github.com/pixelersagency/leiho.', 'leiho' ) );
		}
		return $file;
	}

	/**
	 * Version d'un paquet d'après son adresse de release.
	 *
	 * @param string $url Adresse (/releases/download/v1.2.3/leiho.zip).
	 * @return string
	 */
	public static function package_version( $url ) {
		return preg_match( '#/releases/download/v?([^/]+)/[^/]+$#', (string) $url, $m ) ? $m[1] : '';
	}

	/**
	 * Message signé pour une release : le nom, la version et l'empreinte de
	 * l'archive. Même calcul dans le workflow de release.
	 *
	 * @param string $version  Version (sans v).
	 * @param string $contents Contenu de l'archive.
	 * @return string
	 */
	public static function signed_message( $version, $contents ) {
		return "leiho\n" . $version . "\n" . hash( 'sha256', (string) $contents );
	}

	/**
	 * Signature Ed25519 (base64) d'une archive pour une version, avec la clé
	 * publique de Leiho.
	 *
	 * @param string      $contents  Contenu de l'archive.
	 * @param string      $signature Signature en base64.
	 * @param string      $version   Version attendue.
	 * @param string|null $key       Clé publique en base64 (tests) ; défaut SIGNING_KEY.
	 * @return bool
	 */
	public static function verify( $contents, $signature, $version, $key = null ) {
		$sig = base64_decode( trim( (string) $signature ), true );
		$pub = base64_decode( null === $key ? self::SIGNING_KEY : $key, true );
		if ( '' === (string) $version || false === $sig || false === $pub || 64 !== strlen( $sig ) || 32 !== strlen( $pub ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, self::signed_message( $version, $contents ), $pub );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Traduit une release GitHub en réponse de mise à jour WordPress. Toujours
	 * renvoyée, même si la version n'est pas plus récente : WordPress compare
	 * lui-même, et range le plugin dans « à jour » (utile pour le bouton de
	 * mises à jour automatiques).
	 *
	 * @param array $release Release telle que renvoyée par l'API GitHub.
	 * @return array|false False si la release est inexploitable.
	 */
	public static function from_release( $release ) {
		if ( empty( $release['tag_name'] ) || ! empty( $release['draft'] ) ) {
			return false;
		}
		$package = '';
		foreach ( (array) ( isset( $release['assets'] ) ? $release['assets'] : array() ) as $asset ) {
			// Seulement une archive publiée sur les releases du dépôt officiel.
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] && self::is_release_package( $asset['browser_download_url'] ) ) {
				$package = $asset['browser_download_url'];
			}
		}
		if ( '' === $package ) {
			return false; // Release sans zip installable : on ne propose rien.
		}

		$update = array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => ltrim( (string) $release['tag_name'], 'vV' ),
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $package,
			'requires_php' => '7.4',
			'icons'        => array( 'svg' => LEIHO_URL . 'assets/img/leiho-mark.svg' ),
		);
		// « Tested up to » du readme.txt de cette release : sans lui, WordPress
		// affiche une compatibilité « inconnue » avec sa version.
		if ( ! empty( $release['tested'] ) ) {
			$update['tested'] = $release['tested'];
		}
		return $update;
	}

	/**
	 * Dernière release, mise en cache 6 h (1 h après une erreur, pour ne pas
	 * épuiser la limite de 60 requêtes/heure de l'API GitHub sans jeton).
	 *
	 * Si GitHub ne répond pas (limite atteinte sur un hébergement mutualisé,
	 * panne), on renvoie la dernière release connue : sans ça, une mise à jour
	 * déjà proposée disparaîtrait de la page Extensions jusqu'au prochain
	 * succès.
	 *
	 * @return array|null
	 */
	public static function latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['tag_name'] ) ? self::last_known() : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					// Version seulement : l'adresse du site ne regarde pas GitHub.
					'User-Agent' => 'Leiho/' . LEIHO_VERSION,
				),
			)
		);

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$release = array_intersect_key( $body, array_flip( array( 'tag_name', 'name', 'body', 'html_url', 'published_at', 'draft', 'assets' ) ) );
				$release['assets'] = array_map(
					function ( $asset ) {
						return array_intersect_key( (array) $asset, array_flip( array( 'name', 'browser_download_url' ) ) );
					},
					isset( $release['assets'] ) ? (array) $release['assets'] : array()
				);
			}
		}

		if ( $release ) {
			$release['tested'] = self::tested_up_to( $release['tag_name'] );
			update_option( self::OPTION, $release, false );
		}

		// Échec mis en cache aussi (tableau vide), plus brièvement.
		set_transient( self::TRANSIENT, $release ? $release : array(), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release ? $release : self::last_known();
	}

	/**
	 * Dernière release obtenue avec succès, ou null.
	 *
	 * @return array|null
	 */
	private static function last_known() {
		$last = get_option( self::OPTION );
		return is_array( $last ) && ! empty( $last['tag_name'] ) ? $last : null;
	}

	/**
	 * « Tested up to » du readme.txt publié avec la release (fichier brut,
	 * servi par un CDN hors limite de l'API).
	 *
	 * @param string $tag Tag de la release.
	 * @return string Version de WordPress, ou '' si introuvable.
	 */
	private static function tested_up_to( $tag ) {
		// Lecture du readme.txt de la release (version de WordPress testée), pas un contenu chargé dans les pages.
		$response = wp_remote_get( // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( $tag ) . '/plugin/readme.txt',
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'Leiho/' . LEIHO_VERSION ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return preg_match( '/^Tested up to:\s*([0-9.]+)\s*$/mi', wp_remote_retrieve_body( $response ), $m ) ? $m[1] : '';
	}

	/**
	 * Fenêtre « Afficher les détails » de la page Extensions.
	 *
	 * @param false|object|array $result Résultat d'un autre filtre.
	 * @param string             $action Action demandée.
	 * @param object             $args   Arguments (slug…).
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		$update  = $release ? self::from_release( $release ) : false;
		if ( ! $update ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Leiho',
			'slug'          => self::SLUG,
			'version'       => $update['version'],
			'author'        => '<a href="https://pixelers.fr">Pixelers</a>',
			'homepage'      => $update['url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => isset( $release['published_at'] ) ? $release['published_at'] : '',
			'download_link' => $update['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Visual editing for headless WordPress: edit your ACF fields live on your real front end, from wp-admin.', 'leiho' ) . '</p>',
				'changelog'   => self::markdown( isset( $release['body'] ) ? (string) $release['body'] : '' ) .
					'<p><a href="' . esc_url( isset( $release['html_url'] ) ? $release['html_url'] : $update['url'] ) . '">' . esc_html__( 'Release notes on GitHub', 'leiho' ) . '</a></p>',
			),
		);
	}

	/**
	 * Markdown minimal des notes de release (titres, listes, code, liens) vers
	 * du HTML sûr : tout est échappé avant d'être balisé.
	 *
	 * @param string $text Markdown.
	 * @return string
	 */
	public static function markdown( $text ) {
		$html    = '';
		$in_list = false;
		foreach ( preg_split( '/\r?\n/', trim( $text ) ) as $line ) {
			$inline = esc_html( trim( $line ) );
			$inline = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $inline );
			$inline = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $inline );
			$inline = preg_replace_callback(
				'/\[([^\]]+)\]\((https?:[^)\s]+)\)/',
				function ( $m ) {
					return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
				},
				$inline
			);

			if ( preg_match( '/^[-*] (.*)$/', $inline, $m ) ) {
				$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . $m[1] . '</li>';
				$in_list = true;
				continue;
			}
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( preg_match( '/^#{1,6} (.*)$/', $inline, $m ) ) {
				$html .= '<h4>' . $m[1] . '</h4>';
			} elseif ( preg_match( '/^&gt; ?(.*)$/', $inline, $m ) ) {
				// Citation (« > … ») : « > » est déjà échappé en &gt; à ce stade.
				$html .= '<blockquote><p>' . $m[1] . '</p></blockquote>';
			} elseif ( '' !== $inline ) {
				$html .= '<p>' . $inline . '</p>';
			}
		}
		return $html . ( $in_list ? '</ul>' : '' );
	}

	/**
	 * Le zip contient leiho/ ; si le plugin a été installé dans un autre dossier
	 * (leiho-main, leiho-1.0.0…), on renomme la source pour que la mise à jour
	 * remplace l'installation existante au lieu d'en créer une seconde.
	 *
	 * @param string      $source        Dossier extrait.
	 * @param string      $remote_source Dossier parent.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $extra         Contexte (plugin mis à jour).
	 * @return string|WP_Error
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;

		if ( empty( $extra['plugin'] ) || plugin_basename( LEIHO_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( $extra['plugin'] ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || ! $wp_filesystem ) {
			return $source;
		}
		if ( ! $wp_filesystem->move( $source, $wanted ) ) {
			return new WP_Error( 'leiho_update_folder', __( 'Couldn’t prepare the Leiho update folder.', 'leiho' ) );
		}
		return $wanted;
	}

	/**
	 * Après une mise à jour, on oublie la release en cache.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}
}
