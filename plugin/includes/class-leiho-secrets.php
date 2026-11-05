<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stockage chiffré des secrets de deploy.
 *
 * AES-256-CBC puis HMAC-SHA256 (encrypt-then-MAC), deux sous-clés dérivées
 * d'une clé tirée des salts WordPress : une pour chiffrer, une pour signer.
 * Format « v2: » ; les secrets plus anciens (une seule clé pour les deux) se
 * lisent toujours et sont rechiffrés à la première lecture.
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
		$plain = self::decrypt( $secrets[ $name ] );
		// Ancien format lisible : rechiffré avec des sous-clés séparées.
		if ( null !== $plain && 0 !== strpos( (string) $secrets[ $name ], self::PREFIX ) ) {
			$secrets[ $name ] = self::encrypt( $plain );
			update_option( Leiho_Plugin::OPTION_SECRETS, $secrets, false );
		}
		return $plain;
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

	/** Préfixe du format à sous-clés séparées. */
	const PREFIX = 'v2:';

	/**
	 * Sous-clé dédiée à un usage ('enc' ou 'mac').
	 *
	 * @param string $use Usage.
	 * @return string
	 */
	private static function subkey( $use ) {
		return hash_hmac( 'sha256', 'leiho-secrets-' . $use, self::key(), true );
	}

	private static function encrypt( $plain ) {
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::subkey( 'enc' ), OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, self::subkey( 'mac' ), true );
		return self::PREFIX . base64_encode( $iv . $mac . $cipher );
	}

	private static function decrypt( $blob ) {
		$blob = (string) $blob;
		if ( 0 === strpos( $blob, self::PREFIX ) ) {
			return self::decrypt_with( substr( $blob, strlen( self::PREFIX ) ), self::subkey( 'enc' ), self::subkey( 'mac' ) );
		}
		// Ancien format : une seule clé (Leiho, puis Okno).
		$plain = self::decrypt_with( $blob, self::key(), self::key() );
		return null !== $plain ? $plain : self::decrypt_with( $blob, self::key( true ), self::key( true ) );
	}

	private static function decrypt_with( $blob, $enc_key, $mac_key ) {
		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return null;
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $mac_key, true ), $mac ) ) {
			return null;
		}

		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? null : $plain;
	}

	/**
	 * Rechiffre au format actuel les secrets d'un format plus ancien (Okno,
	 * ou Leiho à clé unique). Un secret illisible (salts changés) est laissé tel quel :
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
			if ( ! is_string( $blob ) || '' === $blob || 0 === strpos( $blob, self::PREFIX ) ) {
				continue;
			}
			$plain = self::decrypt( $blob );
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
