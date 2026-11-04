<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contexte de marque : ce que l'IA doit savoir de la marque pour écrire à
 * sa place (offre, public, ton, vocabulaire, formulations interdites, faits
 * obligatoires, langue, exemples), et le contexte propre à chaque type de
 * contenu (à quoi sert la collection, pour qui).
 *
 * Une option, exportable et importable en JSON (même format que GET
 * /leiho/v1/ai/brand). Aucune clé d'API ici : le fournisseur d'IA est celui
 * que le site a configuré dans Réglages → Connecteurs.
 */
class Leiho_AI_Brand {

	const OPTION = 'leiho_ai_brand';

	/** Champs texte (une valeur). */
	const TEXTS = array( 'name', 'offer', 'audience', 'tone', 'language' );

	/** Listes (une entrée par ligne dans le formulaire). */
	const LISTS = array( 'prefer', 'avoid', 'forbidden', 'facts', 'samples' );

	/** Limites : longueur d'un texte, nombre et longueur des entrées d'une liste. */
	const TEXT_MAX   = 2000;
	const ITEM_MAX   = 300;
	const ITEMS_MAX  = 60;
	const SAMPLES    = 3;
	const SAMPLE_MAX = 4000;

	/**
	 * Contexte enregistré, complété des valeurs par défaut.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return self::normalize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Valeurs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array( 'version' => 1 );
		foreach ( self::TEXTS as $key ) {
			$out[ $key ] = '';
		}
		foreach ( self::LISTS as $key ) {
			$out[ $key ] = array();
		}
		$out['collections'] = array();
		return $out;
	}

	/**
	 * Nettoie un contexte reçu (formulaire, import JSON, REST).
	 *
	 * Les listes acceptent un tableau ou un texte à une entrée par ligne ;
	 * les exemples sont des textes entiers (paragraphes conservés).
	 *
	 * @param array $input Contexte brut.
	 * @return array
	 */
	public static function normalize( array $input ) {
		$out = self::defaults();

		foreach ( self::TEXTS as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
				$out[ $key ] = self::text( (string) $input[ $key ], 'language' === $key ? 60 : self::TEXT_MAX );
			}
		}

		foreach ( self::LISTS as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$raw = $input[ $key ];
			if ( 'samples' === $key ) {
				$items = is_array( $raw ) ? $raw : array( $raw );
				$clean = array();
				foreach ( $items as $sample ) {
					if ( is_scalar( $sample ) && '' !== trim( (string) $sample ) && count( $clean ) < self::SAMPLES ) {
						$clean[] = self::text( (string) $sample, self::SAMPLE_MAX, true );
					}
				}
				$out[ $key ] = $clean;
				continue;
			}
			$items = is_array( $raw ) ? $raw : preg_split( '/\r?\n/', (string) $raw );
			$clean = array();
			foreach ( (array) $items as $item ) {
				if ( ! is_scalar( $item ) ) {
					continue;
				}
				$item = self::text( (string) $item, self::ITEM_MAX );
				// Une règle /…/ invalide est écartée plutôt que de casser le contrôle.
				if ( '' === $item || ( 'forbidden' === $key && ! Leiho_AI_Prompt::valid_rule( $item ) ) ) {
					continue;
				}
				if ( ! in_array( $item, $clean, true ) && count( $clean ) < self::ITEMS_MAX ) {
					$clean[] = $item;
				}
			}
			$out[ $key ] = $clean;
		}

		if ( isset( $input['collections'] ) && is_array( $input['collections'] ) ) {
			foreach ( $input['collections'] as $type => $context ) {
				$type = self::type_key( $type );
				if ( '' === $type || ! is_array( $context ) ) {
					continue;
				}
				$clean = self::collection_normalize( $context );
				if ( '' !== $clean['purpose'] || '' !== $clean['audience'] ) {
					$out['collections'][ $type ] = $clean;
				}
			}
		}

		return $out;
	}

	/**
	 * Contexte d'un type de contenu.
	 *
	 * @param array $context { purpose, audience }.
	 * @return array{purpose:string,audience:string}
	 */
	public static function collection_normalize( array $context ) {
		return array(
			'purpose'  => isset( $context['purpose'] ) && is_scalar( $context['purpose'] ) ? self::text( (string) $context['purpose'], 1000, true ) : '',
			'audience' => isset( $context['audience'] ) && is_scalar( $context['audience'] ) ? self::text( (string) $context['audience'], 1000, true ) : '',
		);
	}

	/**
	 * Enregistre le contexte de marque (les contextes de collection sont gardés
	 * s'ils ne sont pas fournis).
	 *
	 * @param array $input Contexte brut.
	 * @return array Contexte enregistré.
	 */
	public static function save( array $input ) {
		$current = self::get();
		if ( ! isset( $input['collections'] ) ) {
			$input['collections'] = $current['collections'];
		}
		$clean = self::normalize( $input );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Contexte d'un type de contenu (libellé compris pour le prompt).
	 *
	 * @param string $post_type Type.
	 * @return array{purpose:string,audience:string,label:string}
	 */
	public static function collection( $post_type ) {
		$brand = self::get();
		$type  = self::type_key( $post_type );
		$out   = isset( $brand['collections'][ $type ] ) ? $brand['collections'][ $type ] : array(
			'purpose'  => '',
			'audience' => '',
		);
		$object       = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
		$out['label'] = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;
		return $out;
	}

	/**
	 * Enregistre le contexte d'un type de contenu.
	 *
	 * @param string $post_type Type.
	 * @param array  $context   { purpose, audience }.
	 * @return array{purpose:string,audience:string}
	 */
	public static function save_collection( $post_type, array $context ) {
		$brand = self::get();
		$type  = self::type_key( $post_type );
		$clean = self::collection_normalize( $context );
		if ( '' === $clean['purpose'] && '' === $clean['audience'] ) {
			unset( $brand['collections'][ $type ] );
		} else {
			$brand['collections'][ $type ] = $clean;
		}
		update_option( self::OPTION, self::normalize( $brand ), false );
		return $clean;
	}

	/**
	 * Le contexte est-il rempli (au moins le nom ou l'offre) ?
	 *
	 * @param array|null $brand Contexte.
	 * @return bool
	 */
	public static function is_filled( $brand = null ) {
		$brand = null === $brand ? self::get() : $brand;
		return '' !== $brand['name'] || '' !== $brand['offer'] || '' !== $brand['tone'];
	}

	/**
	 * Export JSON (fichier leiho-brand.json).
	 *
	 * @return array
	 */
	public static function export() {
		return array_merge( array( 'leiho' => 'brand' ), self::get() );
	}

	/**
	 * Texte nettoyé, borné.
	 *
	 * @param string $text      Texte.
	 * @param int    $max       Longueur maximale.
	 * @param bool   $multiline Garder les sauts de ligne.
	 * @return string
	 */
	private static function text( $text, $max, $multiline = false ) {
		$text = $multiline
			? ( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $text ) : trim( strip_tags( $text ) ) )
			: ( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( strip_tags( $text ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * @param mixed $type Type de contenu.
	 * @return string
	 */
	private static function type_key( $type ) {
		return substr( strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $type ) ), 0, 20 );
	}
}
