<?php
defined( 'ABSPATH' ) || exit;

/**
 * Traduction des entrées de Collections : textes à traduire (original et
 * traduction actuelle, par chemin pointé), traduction par l'IA du site, et
 * écriture contrôlée pour les agents.
 *
 * Rien n'est enregistré par la traduction IA : l'éditeur reçoit des valeurs
 * à relire, les agents écrivent par leiho/update-translation.
 */
class Leiho_AI_Translate {

	/** Textes envoyés en une seule demande. */
	const MAX_UNITS = 150;

	/** Caractères envoyés en une seule demande. */
	const MAX_CHARS = 40000;

	/**
	 * Entrée traduisible : Collections, plusieurs langues, langue déclarée.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue cible.
	 * @return true|WP_Error
	 */
	public static function check( $post_id, $locale ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( ! Leiho_I18n::enabled() || ! Leiho_Collections::for_post_type( $post->post_type ) ) {
			return new WP_Error( 'leiho_i18n_off', __( 'This content isn’t translatable: declare languages in the Leiho Collections schema first.', 'leiho' ), array( 'status' => 400 ) );
		}
		$code = Leiho_I18n::valid( (string) $locale );
		if ( ! $code || $code === Leiho_I18n::default_locale() ) {
			return new WP_Error(
				'leiho_bad_locale',
				/* translators: %s: comma-separated language codes. */
				sprintf( __( 'Choose a language to translate into: %s.', 'leiho' ), implode( ', ', array_diff( Leiho_I18n::codes(), array( Leiho_I18n::default_locale() ) ) ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Schéma de l'entrée dans une langue.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue.
	 * @return array|WP_Error
	 */
	private static function schema( $post_id, $locale ) {
		return Leiho_I18n::in_locale(
			$locale,
			function () use ( $post_id ) {
				return ( new Leiho_Schema( Leiho_Plugin::instance() ) )->get( $post_id );
			}
		);
	}

	/**
	 * Champs racine traduisibles d'un schéma, par chemin.
	 *
	 * @param array $schema Schéma.
	 * @return array<string,array>
	 */
	private static function localized_fields( array $schema ) {
		$out = array();
		foreach ( $schema['groups'] as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! empty( $field['localized'] ) && ( ! isset( $field['supported'] ) || false !== $field['supported'] ) ) {
					$out[ (string) $field['path'] ] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * Textes traduisibles d'une entrée : original et traduction actuelle.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue cible.
	 * @return array|WP_Error { locale, status, units: [{ path, label, format, max_length, source, translation }] }
	 */
	public static function units( $post_id, $locale ) {
		$check = self::check( $post_id, $locale );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$locale = Leiho_I18n::valid( $locale );
		$source = self::schema( $post_id, Leiho_I18n::default_locale() );
		$target = self::schema( $post_id, $locale );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$translated = self::localized_fields( $target );
		$units      = array();
		foreach ( self::localized_fields( $source ) as $path => $field ) {
			$current = isset( $translated[ $path ] ) && isset( $translated[ $path ]['value'] ) ? $translated[ $path ]['value'] : null;
			self::walk( $field, isset( $field['value'] ) ? $field['value'] : null, $current, $path, (string) $field['label'], $units );
		}
		return array(
			'locale' => $locale,
			'status' => Leiho_I18n::status( $post_id, $locale ),
			'units'  => $units,
		);
	}

	/**
	 * Parcourt un champ et ses sous-champs (groupes, listes, sections).
	 *
	 * @param array  $def     Champ (format éditeur).
	 * @param mixed  $value   Valeur d'origine.
	 * @param mixed  $current Valeur traduite (null si absente).
	 * @param string $path    Chemin pointé.
	 * @param string $label   Libellé composé.
	 * @param array  $out     Sortie.
	 * @return void
	 */
	private static function walk( array $def, $value, $current, $path, $label, array &$out ) {
		$type = isset( $def['type'] ) ? $def['type'] : '';
		if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
			if ( ! is_scalar( $value ) || '' === trim( wp_strip_all_tags( (string) $value ) ) || ! empty( $def['code'] ) ) {
				return; // Vide ou code : rien à traduire.
			}
			$out[] = array(
				'path'        => $path,
				'label'       => $label,
				'format'      => 'wysiwyg' === $type ? 'html' : ( 'textarea' === $type ? 'multiline' : 'text' ),
				'max_length'  => isset( $def['max_length'] ) ? (int) $def['max_length'] : 0,
				'source'      => (string) $value,
				'translation' => is_scalar( $current ) ? (string) $current : '',
			);
			return;
		}
		if ( 'link' === $type ) {
			// Lien : le libellé se traduit, l'adresse reste celle de l'original.
			if ( ! is_array( $value ) || ! isset( $value['title'] ) || '' === trim( (string) $value['title'] ) ) {
				return;
			}
			$out[] = array(
				'path'        => $path,
				'label'       => $label,
				'format'      => 'link',
				'max_length'  => 0,
				'source'      => (string) $value['title'],
				'translation' => is_array( $current ) && isset( $current['title'] ) ? (string) $current['title'] : '',
				'link'        => is_array( $current ) && ! empty( $current['url'] ) ? $current : $value,
			);
			return;
		}
		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			foreach ( isset( $def['sub_fields'] ) ? (array) $def['sub_fields'] : array() as $sub ) {
				$key = $sub['path'];
				self::walk( $sub, isset( $value[ $key ] ) ? $value[ $key ] : null, is_array( $current ) && isset( $current[ $key ] ) ? $current[ $key ] : null, $path . '.' . $key, $label . ' › ' . $sub['label'], $out );
			}
			return;
		}
		if ( in_array( $type, array( 'repeater', 'flexible_content' ), true ) && is_array( $value ) ) {
			$rows = is_array( $current ) ? array_values( $current ) : array();
			foreach ( array_values( $value ) as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$subs = isset( $def['sub_fields'] ) ? (array) $def['sub_fields'] : array();
				if ( 'flexible_content' === $type ) {
					$subs = array();
					foreach ( isset( $def['layouts'] ) ? (array) $def['layouts'] : array() as $layout ) {
						if ( isset( $row['_layout'] ) && $layout['name'] === $row['_layout'] ) {
							$subs = $layout['sub_fields'];
						}
					}
				}
				// Traduction d'une autre forme (rangée retirée, autre section) : pas de valeur actuelle.
				$twin = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) && ( 'flexible_content' !== $type || ( isset( $rows[ $i ]['_layout'] ) && $rows[ $i ]['_layout'] === $row['_layout'] ) ) ? $rows[ $i ] : null;
				foreach ( $subs as $sub ) {
					$key = $sub['path'];
					self::walk( $sub, isset( $row[ $key ] ) ? $row[ $key ] : null, $twin && isset( $twin[ $key ] ) ? $twin[ $key ] : null, $path . '.' . $i . '.' . $key, $label . ' › #' . ( $i + 1 ) . ' › ' . $sub['label'], $out );
				}
			}
		}
	}

	/**
	 * Traduction par l'IA du site.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue cible.
	 * @param string $mode    missing (champs pas encore traduits) ou all.
	 * @return array|WP_Error { locale, translations: { path: texte }, values: { champ racine: valeur }, items, warnings }
	 */
	public static function translate( $post_id, $locale, $mode = 'missing' ) {
		$data = self::units( $post_id, $locale );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$locale = $data['locale'];
		$units  = array_values(
			array_filter(
				$data['units'],
				function ( $unit ) use ( $mode ) {
					return 'all' === $mode || '' === trim( wp_strip_all_tags( $unit['translation'] ) );
				}
			)
		);
		if ( ! $units ) {
			return new WP_Error( 'leiho_nothing_to_translate', __( 'Everything is already translated.', 'leiho' ), array( 'status' => 400 ) );
		}

		$chars = 0;
		$batch = array();
		foreach ( $units as $unit ) {
			$chars += strlen( $unit['source'] );
			if ( count( $batch ) >= self::MAX_UNITS || $chars > self::MAX_CHARS ) {
				break; // Le reste au prochain passage (mode missing).
			}
			$batch[] = $unit;
		}

		$items      = array();
		$properties = array();
		foreach ( $batch as $i => $unit ) {
			$id                = 't' . ( $i + 1 );
			$items[ $id ]      = array_filter(
				array(
					'field'      => $unit['label'],
					'format'     => $unit['format'],
					'max_length' => $unit['max_length'] ? $unit['max_length'] : null,
					'text'       => $unit['source'],
				)
			);
			$properties[ $id ] = array( 'type' => 'string' );
		}

		$post    = get_post( $post_id );
		$brand   = Leiho_AI_Brand::get();
		$context = Leiho_AI_Brand::collection( $post->post_type );
		$raw     = Leiho_AI::generate(
			Leiho_AI_Prompt::system_instruction( $brand, $context ),
			self::prompt( $locale, $items ),
			array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array_keys( $properties ),
					'additionalProperties' => false,
				),
			)
		);
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$answer = Leiho_AI_Prompt::decode_json( $raw );
		if ( ! is_array( $answer ) ) {
			return new WP_Error( 'leiho_ai_bad_json', __( 'The AI answer couldn’t be read. Try again.', 'leiho' ), array( 'status' => 502 ) );
		}

		$translations = array();
		$values       = array();
		$warnings     = array();
		$out_items    = array();
		foreach ( $batch as $i => $unit ) {
			$id = 't' . ( $i + 1 );
			if ( ! isset( $answer[ $id ] ) || ! is_string( $answer[ $id ] ) || '' === trim( $answer[ $id ] ) ) {
				$warnings[ $unit['path'] ] = 'missing';
				continue;
			}
			$text = self::clean( $answer[ $id ], $unit['format'] );
			if ( $unit['max_length'] && Leiho_AI_Prompt::length( wp_strip_all_tags( $text ) ) > $unit['max_length'] ) {
				$warnings[ $unit['path'] ] = 'too_long';
			}
			$forbidden = Leiho_AI_Prompt::forbidden_matches( $text, isset( $brand['forbidden'] ) ? (array) $brand['forbidden'] : array() );
			if ( $forbidden ) {
				$warnings[ $unit['path'] ] = 'forbidden';
			}
			$translations[ $unit['path'] ] = $text;
			$values[ $unit['path'] ]       = self::value_for( $unit, $text );
			$out_items[]                   = array(
				'path'   => $unit['path'],
				'label'  => $unit['label'],
				'source' => $unit['source'],
				'value'  => $text,
			);
		}
		if ( ! $translations ) {
			return new WP_Error( 'leiho_ai_empty', __( 'The AI didn’t translate anything. Try again.', 'leiho' ), array( 'status' => 502 ) );
		}

		return array(
			'locale'       => $locale,
			'translations' => (object) $translations,
			'values'       => (object) self::root_values( $post_id, $locale, $values ),
			'items'        => $out_items,
			'remaining'    => count( $units ) - count( $batch ),
			'warnings'     => (object) $warnings,
		);
	}

	/**
	 * Demande de traduction.
	 *
	 * @param string $locale Langue cible.
	 * @param array  $items  Textes { id: { field, format, max_length, text } }.
	 * @return string
	 */
	private static function prompt( $locale, array $items ) {
		$from  = Leiho_I18n::label( Leiho_I18n::default_locale() );
		$to    = Leiho_I18n::label( $locale );
		$lines = array(
			'Translate the texts of this web page from ' . $from . ' (' . Leiho_I18n::default_locale() . ') to ' . $to . ' (' . $locale . ').',
			'',
			'Rules:',
			'- Translate the meaning for a native ' . $to . ' reader, in the brand tone: natural, not word for word.',
			'- Keep product names, brand names, code, URLs, file names, numbers and units unchanged.',
			'- "html" texts: keep every HTML tag and attribute exactly as they are; translate only the text between tags.',
			'- "multiline" texts: keep the line breaks. "link" texts are button or link labels: keep them short.',
			'- Respect max_length (characters, without HTML tags) when given.',
			'- Translate every text, even short labels. Do not add, drop or explain anything.',
			'',
			'Texts (JSON, by id):',
			wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'',
			'Answer with a JSON object that maps each id to its translation, and nothing else.',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Nettoie un texte traduit selon son format.
	 *
	 * @param string $text   Texte.
	 * @param string $format text, multiline ou html.
	 * @return string
	 */
	public static function clean( $text, $format ) {
		if ( 'html' === $format ) {
			return trim( wp_kses_post( $text ) );
		}
		if ( 'multiline' === $format ) {
			return trim( sanitize_textarea_field( $text ) );
		}
		return trim( sanitize_text_field( $text ) );
	}

	/**
	 * Valeur à écrire pour un texte traduit : le texte, ou le lien complet
	 * avec son libellé traduit.
	 *
	 * @param array  $unit Texte traduisible.
	 * @param string $text Traduction nettoyée.
	 * @return mixed
	 */
	private static function value_for( array $unit, $text ) {
		if ( 'link' !== $unit['format'] ) {
			return $text;
		}
		$link          = isset( $unit['link'] ) && is_array( $unit['link'] ) ? $unit['link'] : array();
		$link['title'] = $text;
		return $link;
	}

	/**
	 * Valeurs complètes des champs racine (format éditeur) une fois les
	 * traductions posées : traduction actuelle, sinon structure d'origine.
	 *
	 * @param int    $post_id      Entrée.
	 * @param string $locale       Langue.
	 * @param array  $translations { chemin pointé: valeur }.
	 * @return array<string,mixed>
	 */
	public static function root_values( $post_id, $locale, array $translations ) {
		$source = self::localized_fields( self::schema( $post_id, Leiho_I18n::default_locale() ) );
		$target = self::localized_fields( self::schema( $post_id, $locale ) );
		$out    = array();
		foreach ( $translations as $path => $text ) {
			$segments = explode( '.', (string) $path );
			$root     = array_shift( $segments );
			if ( ! isset( $source[ $root ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $root, $out ) ) {
				$current      = isset( $target[ $root ]['value'] ) ? $target[ $root ]['value'] : null;
				$out[ $root ] = self::blank( $current ) ? ( isset( $source[ $root ]['value'] ) ? $source[ $root ]['value'] : null ) : $current;
			}
			$out[ $root ] = self::set( $out[ $root ], $segments, $text );
		}
		return $out;
	}

	/**
	 * @param mixed $value Valeur.
	 * @return bool
	 */
	private static function blank( $value ) {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * Pose une valeur dans une structure imbriquée.
	 *
	 * @param mixed  $node     Structure.
	 * @param array  $segments Chemin.
	 * @param string $text     Valeur.
	 * @return mixed
	 */
	private static function set( $node, array $segments, $text ) {
		if ( ! $segments ) {
			return $text;
		}
		$key  = array_shift( $segments );
		$node = is_array( $node ) ? $node : array();
		$node[ $key ] = self::set( isset( $node[ $key ] ) ? $node[ $key ] : null, $segments, $text );
		return $node;
	}

	/**
	 * Écrit des traductions (agents) : un brouillon librement ; une entrée
	 * en ligne seulement là où la traduction est encore vide, pour qu'un
	 * agent complète sans jamais remplacer un texte relu.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue.
	 * @param array  $values  { chemin pointé: texte }.
	 * @param bool   $draft   L'entrée est un brouillon.
	 * @return array|WP_Error { saved, errors, status }
	 */
	public static function write( $post_id, $locale, array $values, $draft ) {
		$data = self::units( $post_id, $locale );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$locale = $data['locale'];
		$units  = array();
		foreach ( $data['units'] as $unit ) {
			$units[ $unit['path'] ] = $unit;
		}
		$errors = array();
		$clean  = array();
		foreach ( $values as $path => $text ) {
			$path = (string) $path;
			if ( ! isset( $units[ $path ] ) ) {
				$errors[ $path ] = 'not_translatable';
				continue;
			}
			if ( ! is_string( $text ) ) {
				$errors[ $path ] = 'invalid';
				continue;
			}
			if ( ! $draft && '' !== trim( wp_strip_all_tags( $units[ $path ]['translation'] ) ) ) {
				$errors[ $path ] = 'already_translated';
				continue;
			}
			$clean[ $path ] = self::value_for( $units[ $path ], self::clean( $text, $units[ $path ]['format'] ) );
		}
		$saved = array();
		if ( $clean ) {
			$result = Leiho_I18n::in_locale(
				$locale,
				function () use ( $post_id, $clean ) {
					return ( new Leiho_Schema( Leiho_Plugin::instance() ) )->save( $post_id, $clean );
				}
			);
			$saved  = array_values( $result['saved'] );
			$errors = array_merge( $errors, $result['errors'] );
		}
		return array(
			'locale' => $locale,
			'saved'  => $saved,
			'errors' => (object) $errors,
			'status' => Leiho_I18n::status( $post_id, $locale ),
		);
	}

	/**
	 * État des traductions d'un type de contenu.
	 *
	 * @param string $post_type Type.
	 * @param string $locale    Langue (vide : toutes).
	 * @return array|WP_Error
	 */
	public static function overview( $post_type, $locale = '' ) {
		if ( ! Leiho_I18n::enabled() || ! Leiho_Collections::for_post_type( $post_type ) ) {
			return new WP_Error( 'leiho_i18n_off', __( 'This content type isn’t translatable.', 'leiho' ), array( 'status' => 400 ) );
		}
		$codes = '' !== $locale ? array( Leiho_I18n::valid( $locale ) ) : array_diff( Leiho_I18n::codes(), array( Leiho_I18n::default_locale() ) );
		$codes = array_filter( $codes );
		$ids   = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $ids as $id ) {
			$statuses = array();
			foreach ( $codes as $code ) {
				$statuses[ $code ] = Leiho_I18n::status( $id, $code );
			}
			$out[] = array(
				'id'           => (int) $id,
				'title'        => get_the_title( $id ),
				'status'       => get_post_status( $id ),
				'translations' => $statuses,
			);
		}
		return array(
			'default' => Leiho_I18n::default_locale(),
			'locales' => array_values( $codes ),
			'entries' => $out,
		);
	}
}
