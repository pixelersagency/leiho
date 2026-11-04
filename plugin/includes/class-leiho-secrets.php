<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stockage chiffré des secrets de deploy.
 *
 * AES-256-CBC + HMAC-SHA256, clé dérivée des salts WordPress.
 * Si les salts tournent (hébergeur, plugin de sécurité), le déchiffrement
 * échoue proprement : decrypt() retourne null et l'UI demande de re-saisir
 * le secret — jamais d'erreur opaque au moment du deploy.
 */
class Leiho_Secrets {

	/**
	 * Enregistre un secret chiffré.
	 *
	 * @param string $name  Identifiant ('coolify_token', 'gh_pat').
	 * @param string $value Valeur en clair. Chaîne vide = suppression.
	 */
	public static function set( $name, $value ) {
		$secrets = get_option( Leiho_Plugin::OPTION_SECRETS, array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		if ( '' === $value ) {
			unset( $secrets[ $name ] );
		} else {
			$secrets[ $name ] = self::encrypt( $value );
		}

		update_option( Leiho_Plugin::OPTION_SECRETS, $secrets, false );
	}

	/**
	 * Récupère un secret en clair.
	 *
	 * @param string $name Identifiant.
	 * @return string|null Null si absent OU indéchiffrable (salts changés).
	 */
	public static function get( $name ) {
		$secrets = get_option( Leiho_Plugin::OPTION_SECRETS, array() );
		if ( ! is_array( $secrets ) || empty( $secrets[ $name ] ) ) {
			return null;
		}
		return self::decrypt( $secrets[ $name ] );
	}

	/**
	 * État d'un secret pour l'UI des réglages.
	 *
	 * @param string $name Identifiant.
	 * @return string 'empty' | 'ok' | 'unreadable'
	 */
	public static function status( $name ) {
		$secrets = get_option( Leiho_Plugin::OPTION_SECRETS, array() );
		if ( ! is_array( $secrets ) || empty( $secrets[ $name ] ) ) {
			return 'empty';
		}
		return null === self::decrypt( $secrets[ $name ] ) ? 'unreadable' : 'ok';
	}

	/**
	 * @param bool $legacy Clé des secrets écrits par Okno (avant le renommage
	 *                     en Leiho) : seule la chaîne de dérivation change.
	 */
	private static function key( $legacy = false ) {
		return hash( 'sha256', wp_salt( 'auth' ) . ( $legacy ? '|okno-secrets' : '|leiho-secrets' ), true );
	}

	private static function encrypt( $plain ) {
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return base64_encode( $iv . $mac . $cipher );
	}

	private static function decrypt( $blob ) {
		$plain = self::decrypt_with( $blob, self::key() );
		return null !== $plain ? $plain : self::decrypt_with( $blob, self::key( true ) );
	}

	private static function decrypt_with( $blob, $key ) {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return null;
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return null;
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? null : $plain;
	}

	/**
	 * Rechiffre avec la clé Leiho les secrets hérités d'Okno (migration). Un
	 * secret illisible avec les deux clés (salts changés) est laissé tel quel :
	 * l'écran des réglages le signale déjà comme « illisible ».
	 *
	 * @return int Nombre de secrets rechiffrés.
	 */
	public static function upgrade_legacy() {
		$secrets = get_option( Leiho_Plugin::OPTION_SECRETS, array() );
		if ( ! is_array( $secrets ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $secrets as $name => $blob ) {
			if ( ! is_string( $blob ) || '' === $blob || null !== self::decrypt_with( $blob, self::key() ) ) {
				continue;
			}
			$plain = self::decrypt_with( $blob, self::key( true ) );
			if ( null !== $plain ) {
				$secrets[ $name ] = self::encrypt( $plain );
				$count++;
			}
		}
		if ( $count ) {
			update_option( Leiho_Plugin::OPTION_SECRETS, $secrets, false );
		}
		return $count;
	}
}
