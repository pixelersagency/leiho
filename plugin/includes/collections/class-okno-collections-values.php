<?php
defined( 'ABSPATH' ) || exit;

/**
 * Valeurs Okno Collections : passage entre la base (colonnes typées, JSON
 * indexé par clé de champ) et les valeurs brutes du moteur de champs (format
 * ACF : lignes indexées par clé, acf_fc_layout), et conversions de type pour
 * les migrations et la restauration des révisions.
 *
 * Classe pure : testable sans WordPress.
 */
class Okno_Collections_Values {

	/** Marqueur de type de bloc dans le JSON stocké (clé du bloc, stable). */
	const BLOCK = '_block';

	/* ---------------------------------------------------------------------
	 * Base → moteur
	 * ------------------------------------------------------------------- */

	/**
	 * Valeur brute (format moteur) d'une colonne lue en base.
	 *
	 * @param array $field Champ normalisé.
	 * @param mixed $db    Valeur de la colonne (chaîne ou null).
	 * @return mixed
	 */
	public static function decode( $field, $db ) {
		if ( null === $db ) {
			return null;
		}
		if ( Okno_Collections_Schema::is_json( $field ) ) {
			$data = json_decode( (string) $db, true );
			return self::to_raw( $field, $data );
		}
		return self::scalar_to_raw( $field, $db );
	}

	/**
	 * Valeur stockée (scalaire JSON ou arbre) → valeur brute du moteur.
	 *
	 * @param array $field  Champ normalisé.
	 * @param mixed $stored Valeur stockée.
	 * @return mixed
	 */
	public static function to_raw( $field, $stored ) {
		if ( null === $stored ) {
			return null;
		}
		switch ( $field['type'] ) {
			case 'list':
				$rows = array();
				foreach ( is_array( $stored ) ? $stored : array() as $row ) {
					if ( is_array( $row ) ) {
						$rows[] = self::row_to_raw( $field['fields'], $row );
					}
				}
				return $rows;

			case 'group':
				return self::row_to_raw( $field['fields'], is_array( $stored ) ? $stored : array() );

			case 'blocks':
				$blocks = array();
				foreach ( $field['blocks'] as $block ) {
					$blocks[ $block['key'] ] = $block;
				}
				$rows = array();
				foreach ( is_array( $stored ) ? $stored : array() as $row ) {
					$key = is_array( $row ) && isset( $row[ self::BLOCK ] ) ? (string) $row[ self::BLOCK ] : '';
					// Type de bloc retiré du schéma : la ligne n'est pas servie, et les
					// index lus par l'éditeur restent ceux qu'il patche.
					if ( ! isset( $blocks[ $key ] ) ) {
						continue;
					}
					$raw                  = self::row_to_raw( $blocks[ $key ]['fields'], $row );
					$raw['acf_fc_layout'] = $blocks[ $key ]['name'];
					$rows[]               = $raw;
				}
				return $rows;

			case 'gallery':
				return self::ids( $stored );

			case 'reference':
				if ( ! empty( $field['multiple'] ) ) {
					return self::ids( $stored );
				}
				return self::scalar_to_raw( $field, is_array( $stored ) ? reset( $stored ) : $stored );

			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					return array_values( array_map( 'strval', array_filter( (array) $stored, 'is_scalar' ) ) );
				}
				return self::scalar_to_raw( $field, $stored );

			case 'link':
				$link = array(
					'url'    => '',
					'title'  => '',
					'target' => '',
				);
				foreach ( array_keys( $link ) as $part ) {
					if ( is_array( $stored ) && isset( $stored[ $part ] ) && is_scalar( $stored[ $part ] ) ) {
						$link[ $part ] = (string) $stored[ $part ];
					}
				}
				return $link;

			default:
				return is_array( $stored ) ? null : self::scalar_to_raw( $field, $stored );
		}
	}

	private static function row_to_raw( $fields, $row ) {
		$out = array();
		foreach ( $fields as $sub ) {
			if ( array_key_exists( $sub['key'], $row ) ) {
				$out[ $sub['key'] ] = self::to_raw( $sub, $row[ $sub['key'] ] );
			}
		}
		return $out;
	}

	private static function scalar_to_raw( $field, $value ) {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}
		switch ( $field['type'] ) {
			case 'number':
				return is_numeric( $value ) ? self::number( $value ) : null;
			case 'boolean':
				return (bool) $value ? 1 : 0;
			case 'image':
			case 'file':
			case 'reference':
				$id = abs( (int) $value );
				return $id ? $id : null;
			default:
				return (string) $value;
		}
	}

	/**
	 * Nombre lu en base. DECIMAL rend « 5.000000 » : un entier reste un
	 * entier (5 et non 5.0 dans le JSON servi), comme ACF qui rend 0 + "5".
	 *
	 * @param string|int|float $value Valeur numérique.
	 * @return int|float
	 */
	public static function number( $value ) {
		$number = 0 + $value;
		if ( is_float( $number ) && floor( $number ) === $number && abs( $number ) <= Okno_Collections_Schema::INTEGER_LIMIT ) {
			return (int) $number;
		}
		return $number;
	}

	private static function ids( $value ) {
		$ids = array();
		foreach ( (array) $value as $id ) {
			$id = is_scalar( $id ) ? abs( (int) $id ) : 0;
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Moteur → base
	 * ------------------------------------------------------------------- */

	/**
	 * Valeur de colonne pour une valeur brute sanitizée. Vide → NULL.
	 *
	 * @param array $field Champ normalisé.
	 * @param mixed $raw   Valeur brute (sortie de la sanitization).
	 * @return string|int|null
	 */
	public static function encode( $field, $raw ) {
		if ( Okno_Collections_Schema::is_json( $field ) ) {
			$stored = self::to_stored( $field, $raw );
			if ( null === $stored || array() === $stored ) {
				return null;
			}
			return self::json( $stored );
		}
		return self::scalar_to_stored( $field, $raw );
	}

	/**
	 * Valeur brute → valeur stockée (JSON indexé par clé).
	 *
	 * @param array $field Champ normalisé.
	 * @param mixed $raw   Valeur brute.
	 * @return mixed
	 */
	public static function to_stored( $field, $raw ) {
		switch ( $field['type'] ) {
			case 'list':
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					if ( is_array( $row ) ) {
						$rows[] = self::row_to_stored( $field['fields'], $row );
					}
				}
				return $rows;

			case 'group':
				return is_array( $raw ) ? self::row_to_stored( $field['fields'], $raw ) : null;

			case 'blocks':
				$blocks = array();
				foreach ( $field['blocks'] as $block ) {
					$blocks[ $block['name'] ] = $block;
				}
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$name = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
					if ( ! isset( $blocks[ $name ] ) ) {
						continue;
					}
					$rows[] = array_merge(
						array( self::BLOCK => $blocks[ $name ]['key'] ),
						self::row_to_stored( $blocks[ $name ]['fields'], $row )
					);
				}
				return $rows;

			case 'gallery':
				return self::ids( $raw );

			case 'reference':
				if ( ! empty( $field['multiple'] ) ) {
					return self::ids( $raw );
				}
				return self::scalar_to_stored( $field, $raw );

			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					return array_values( array_map( 'strval', array_filter( (array) $raw, 'is_scalar' ) ) );
				}
				return self::scalar_to_stored( $field, $raw );

			case 'link':
				if ( ! is_array( $raw ) || '' === (string) ( isset( $raw['url'] ) ? $raw['url'] : '' ) ) {
					return null;
				}
				return array(
					'url'    => (string) $raw['url'],
					'title'  => isset( $raw['title'] ) ? (string) $raw['title'] : '',
					'target' => isset( $raw['target'] ) ? (string) $raw['target'] : '',
				);

			default:
				$value = self::scalar_to_stored( $field, $raw );
				if ( null === $value ) {
					return null;
				}
				// Dans le JSON, nombres et booléens restent typés.
				if ( 'number' === $field['type'] ) {
					return 0 + $value;
				}
				if ( 'boolean' === $field['type'] ) {
					return (bool) $value;
				}
				return $value;
		}
	}

	/**
	 * Une ligne de conteneur, indexée par clé de champ. Les sous-champs
	 * absents de la ligne brute sont omis (lecture : null).
	 */
	private static function row_to_stored( $fields, $row ) {
		$out = array();
		foreach ( $fields as $sub ) {
			$value = null;
			if ( array_key_exists( $sub['key'], $row ) ) {
				$value = $row[ $sub['key'] ];
			} elseif ( array_key_exists( $sub['name'], $row ) ) {
				$value = $row[ $sub['name'] ];
			} else {
				continue;
			}
			$stored = self::to_stored( $sub, $value );
			if ( null !== $stored ) {
				$out[ $sub['key'] ] = $stored;
			}
		}
		return $out;
	}

	private static function scalar_to_stored( $field, $raw ) {
		if ( null === $raw || is_array( $raw ) || '' === $raw ) {
			return null;
		}
		switch ( $field['type'] ) {
			case 'number':
				if ( ! is_numeric( $raw ) ) {
					return null;
				}
				if ( ! empty( $field['integer'] ) ) {
					return (string) (int) round( (float) $raw );
				}
				// Pas de notation scientifique : DECIMAL(20,6) n'en veut pas.
				return rtrim( rtrim( sprintf( '%.6F', (float) $raw ), '0' ), '.' );
			case 'boolean':
				return $raw ? 1 : 0;
			case 'image':
			case 'file':
			case 'reference':
				$id = abs( (int) $raw );
				return $id ? $id : null;
			default:
				return (string) $raw;
		}
	}

	/**
	 * JSON stable et compact (UTF-8 et slashes non échappés).
	 *
	 * @param mixed $data Données.
	 * @return string
	 */
	public static function json( $data ) {
		return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/* ---------------------------------------------------------------------
	 * Conversions de type (migrations, révisions)
	 * ------------------------------------------------------------------- */

	/**
	 * Convertit une valeur de colonne d'un type de champ vers un autre.
	 *
	 * @param mixed $db   Valeur de la colonne d'origine.
	 * @param array $from Champ d'origine (au moins type, multiple).
	 * @param array $to   Champ cible normalisé.
	 * @param bool  $ok   Sortie : false si la valeur n'a pas pu être convertie.
	 * @return string|int|null Valeur de la colonne cible.
	 */
	public static function convert( $db, $from, $to, &$ok ) {
		$ok = true;
		if ( null === $db || '' === $db ) {
			return null;
		}

		$from = array_merge(
			array(
				'multiple' => false,
				'fields'   => array(),
				'blocks'   => array(),
			),
			$from
		);
		$raw = self::decode( $from, $db );

		// Même type de conteneur : rien à convertir.
		if ( $from['type'] === $to['type'] && in_array( $to['type'], Okno_Collections_Schema::CONTAINERS, true ) ) {
			return self::encode( $to, $raw );
		}

		$value = self::coerce( $raw, $to, $ok );
		if ( ! $ok ) {
			return null;
		}
		return self::encode( $to, $value );
	}

	/**
	 * Valeur brute quelconque → valeur brute du type cible.
	 */
	private static function coerce( $raw, $to, &$ok ) {
		$ok = true;

		// Les liens se lisent par leur URL.
		if ( is_array( $raw ) && isset( $raw['url'] ) && ! isset( $raw[0] ) && 'link' !== $to['type'] ) {
			$raw = $raw['url'];
		}

		$listish = in_array( $to['type'], array( 'gallery' ), true )
			|| ( in_array( $to['type'], array( 'select', 'reference' ), true ) && ! empty( $to['multiple'] ) );

		if ( $listish ) {
			$items = is_array( $raw ) ? array_values( $raw ) : array( $raw );
			$out   = array();
			foreach ( $items as $item ) {
				if ( ! is_scalar( $item ) || '' === (string) $item ) {
					continue;
				}
				if ( 'select' === $to['type'] ) {
					if ( ! self::is_choice( $to, (string) $item ) ) {
						$ok = false;
						return null;
					}
					$out[] = (string) $item;
				} else {
					$id = abs( (int) $item );
					if ( ! $id || (string) $id !== trim( (string) $item ) ) {
						$ok = false;
						return null;
					}
					$out[] = $id;
				}
			}
			return $out;
		}

		if ( in_array( $to['type'], Okno_Collections_Schema::CONTAINERS, true ) ) {
			$ok = false;
			return null;
		}

		// Cible scalaire : une liste donne son premier élément.
		if ( is_array( $raw ) ) {
			$first = reset( $raw );
			if ( 'link' !== $to['type'] && ( ! is_scalar( $first ) || count( $raw ) < 1 ) ) {
				$ok = false;
				return null;
			}
			if ( 'link' !== $to['type'] ) {
				$raw = $first;
			}
		}

		$text = is_array( $raw ) ? '' : trim( (string) $raw );

		switch ( $to['type'] ) {
			case 'number':
				if ( ! is_numeric( $text ) ) {
					$ok = false;
					return null;
				}
				return 0 + $text;

			case 'boolean':
				return in_array( strtolower( $text ), array( '', '0', 'false', 'no', 'off' ), true ) ? 0 : 1;

			case 'date':
			case 'datetime':
				$time = strtotime( $text );
				if ( false === $time ) {
					$ok = false;
					return null;
				}
				return gmdate( 'date' === $to['type'] ? 'Y-m-d' : 'Y-m-d H:i:s', $time );

			case 'image':
			case 'file':
			case 'reference':
				$id = abs( (int) $text );
				if ( ! $id || (string) $id !== $text ) {
					$ok = false;
					return null;
				}
				return $id;

			case 'select':
				if ( ! self::is_choice( $to, $text ) ) {
					$ok = false;
					return null;
				}
				return $text;

			case 'slug':
				$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
				return self::fits( $to, $slug, $ok );

			case 'email':
				if ( false === filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
					$ok = false;
					return null;
				}
				return self::fits( $to, $text, $ok );

			case 'color':
				if ( ! preg_match( '/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $text ) ) {
					$ok = false;
					return null;
				}
				return $text;

			case 'link':
				if ( is_array( $raw ) ) {
					return $raw;
				}
				return array(
					'url'    => $text,
					'title'  => '',
					'target' => '',
				);

			default:
				// text, richtext, url.
				return self::fits( $to, $text, $ok );
		}
	}

	private static function is_choice( $field, $value ) {
		foreach ( isset( $field['choices'] ) ? $field['choices'] : array() as $choice ) {
			if ( $choice['value'] === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Une chaîne trop longue pour la colonne cible n'est pas tronquée : elle
	 * reste dans la colonne archivée.
	 */
	private static function fits( $field, $text, &$ok ) {
		$max = isset( $field['max_length'] ) ? (int) $field['max_length'] : 0;
		if ( 0 === strpos( Okno_Collections_Schema::column_type( $field ), 'VARCHAR' ) ) {
			$max = $max > 0 ? min( $max, Okno_Collections_Schema::VARCHAR ) : Okno_Collections_Schema::VARCHAR;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $max > 0 && $length > $max ) {
			$ok = false;
			return null;
		}
		return $text;
	}
}
