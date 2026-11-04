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

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|leiho-secrets', true );
	}

	private static function encrypt( $plain ) {
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return base64_encode( $iv . $mac . $cipher );
	}

	private static function decrypt( $blob ) {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return null;
		}
		$key    = self::key();
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return null;
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? null : $plain;
	}
}
