<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schéma Leiho Collections : validation, normalisation, correspondance
 * type → colonne SQL et traduction vers le format de champ du moteur commun.
 *
 * Classe pure (aucun accès base, aucun hook) : testable sans WordPress.
 * Le format est décrit dans plugin/docs/collections-spec.md.
 */
class Leiho_Collections_Schema {

	/** Version du format de fichier leiho.schema.json. */
	const VERSION = 1;

	const KINDS = array( 'collection', 'singleton' );

	const TYPES = array(
		'text',
		'richtext',
		'code',
		'number',
		'boolean',
		'date',
		'datetime',
		'select',
		'slug',
		'email',
		'url',
		'color',
		'image',
		'file',
		'gallery',
		'link',
		'reference',
		'list',
		'blocks',
		'group',
	);

	/** Types conteneurs (sous-champs). */
	const CONTAINERS = array( 'list', 'blocks', 'group' );

	/** Types acceptant max_length. */
	const LENGTH_TYPES = array( 'text', 'code', 'slug', 'email', 'url' );

	/** Fonctionnalités de post activables en plus de title et revisions. */
	const SUPPORTS = array( 'editor', 'excerpt', 'thumbnail', 'page-attributes' );

	/** Post types que WordPress (ou Leiho) réserve. */
	const RESERVED_IDS = array(
		'l10n', // Table des traductions de Leiho ({prefix}leiho_l10n).
		'post',
		'page',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'action',
		'author',
		'order',
		'theme',
		'export',
		'import',
		'fields',
		// Table de Leiho lui-même (wp_leiho_activity).
		'activity',
	);

	const ID_PATTERN   = '/^[a-z][a-z0-9_]{0,19}$/';
	const KEY_PATTERN  = '/^[a-z][a-z0-9_]{0,47}$/';
	const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Nom d'API d'un champ importé d'ACF : celui d'ACF est gardé tel quel
	 * (camelCase, tirets) pour que le front reçoive le même JSON.
	 */
	const ACF_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_\-]{0,63}$/';

	/** Origines possibles d'une collection (source). */
	const SOURCES = array( 'acf' );

	const MAX_FIELDS  = 200;
	const MAX_DEPTH   = 8;
	const MAX_VARCHAR = 60;

	/** Bornes des colonnes numériques (DECIMAL(20,6) et entiers sûrs en JS). */
	const DECIMAL_LIMIT = 99999999999999;
	const INTEGER_LIMIT = 9007199254740991;

	/** Longueur des colonnes VARCHAR. */
	const VARCHAR = 191;

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Valide et normalise un schéma complet.
	 *
	 * @param mixed $input  Schéma (tableau décodé de leiho.schema.json).
	 * @param array $errors Erreurs (sortie) : [{ path, message }].
	 * @return array{version:int,collections:array}
	 */
	public static function normalize( $input, &$errors ) {
		$errors = array();
		$out    = array(
			'version'     => self::VERSION,
			'collections' => array(),
		);

		if ( ! is_array( $input ) ) {
			self::error( $errors, '', __( 'The schema must be a JSON object.', 'leiho' ) );
			return $out;
		}
		if ( isset( $input['version'] ) && (int) $input['version'] > self::VERSION ) {
			/* translators: %d: schema format version. */
			self::error( $errors, 'version', sprintf( __( 'Schema version %d is newer than this Leiho supports. Update Leiho.', 'leiho' ), (int) $input['version'] ) );
			return $out;
		}

		$list = isset( $input['collections'] ) ? $input['collections'] : array();
		if ( ! is_array( $list ) ) {
			self::error( $errors, 'collections', __( 'Must be a list.', 'leiho' ) );
			return $out;
		}

		$ids = array();
		foreach ( array_values( $list ) as $i => $collection ) {
			if ( is_array( $collection ) && isset( $collection['id'] ) && is_string( $collection['id'] ) ) {
				$ids[] = $collection['id'];
			}
		}

		// Langues (localisation des champs) : voyagent avec le modèle de contenu.
		if ( isset( $input['i18n'] ) && class_exists( 'Leiho_I18n' ) ) {
			$i18n = Leiho_I18n::normalize( $input['i18n'], $errors );
			if ( $i18n ) {
				$out['i18n'] = $i18n;
			}
		}

		$seen = array();
		foreach ( array_values( $list ) as $i => $collection ) {
			$normalized = self::normalize_collection( $collection, 'collections.' . $i, $errors, $ids );
			if ( null === $normalized ) {
				continue;
			}
			if ( isset( $seen[ $normalized['id'] ] ) ) {
				/* translators: %s: collection id. */
				self::error( $errors, 'collections.' . $i . '.id', sprintf( __( 'The id “%s” is used twice.', 'leiho' ), $normalized['id'] ) );
				continue;
			}
			$seen[ $normalized['id'] ] = true;
			$out['collections'][]      = $normalized;
		}

		return $out;
	}

	/**
	 * Valide et normalise une collection.
	 *
	 * @param mixed    $input  Collection.
	 * @param string   $path   Chemin pour les erreurs.
	 * @param array    $errors Erreurs (sortie).
	 * @param string[] $ids    Ids des collections du schéma (cibles de références).
	 * @return array|null
	 */
	public static function normalize_collection( $input, $path, &$errors, $ids = array() ) {
		if ( ! is_array( $input ) ) {
			self::error( $errors, $path, __( 'A collection must be an object.', 'leiho' ) );
			return null;
		}

		$id = isset( $input['id'] ) && is_string( $input['id'] ) ? $input['id'] : '';
		if ( ! preg_match( self::ID_PATTERN, $id ) ) {
			self::error( $errors, self::join( $path, 'id' ), __( 'Use 1 to 20 lowercase letters, digits or underscores, starting with a letter.', 'leiho' ) );
			return null;
		}
		if ( in_array( $id, self::RESERVED_IDS, true ) ) {
			/* translators: %s: collection id. */
			self::error( $errors, self::join( $path, 'id' ), sprintf( __( '“%s” is reserved by WordPress.', 'leiho' ), $id ) );
			return null;
		}

		$kind = isset( $input['kind'] ) ? $input['kind'] : 'collection';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			self::error( $errors, self::join( $path, 'kind' ), __( 'Must be “collection” or “singleton”.', 'leiho' ) );
			$kind = 'collection';
		}

		// Type de contenu déclaré ailleurs (thème, extension, ACF) : Leiho ne
		// possède que les champs, jamais l'enregistrement du type.
		$external = ! empty( $input['external'] );
		if ( $external && 'singleton' === $kind ) {
			self::error( $errors, self::join( $path, 'kind' ), __( 'A content type registered outside Leiho can only be a collection.', 'leiho' ) );
			$kind = 'collection';
		}
		$source = isset( $input['source'] ) && in_array( $input['source'], self::SOURCES, true ) ? $input['source'] : '';
		$legacy = 'acf' === $source;

		$label    = self::text( isset( $input['label'] ) ? $input['label'] : '', $id );
		$singular = self::text( isset( $input['singular_label'] ) ? $input['singular_label'] : '', $label );

		$supports = array();
		foreach ( (array) ( isset( $input['supports'] ) ? $input['supports'] : array() ) as $feature ) {
			if ( in_array( $feature, self::SUPPORTS, true ) ) {
				$supports[] = $feature;
			} else {
				/* translators: %s: post feature name. */
				self::error( $errors, self::join( $path, 'supports' ), sprintf( __( 'Unknown feature “%s”.', 'leiho' ), is_scalar( $feature ) ? (string) $feature : '?' ) );
			}
		}

		$icon = isset( $input['icon'] ) && is_string( $input['icon'] ) && preg_match( '/^dashicons-[a-z0-9-]+$/', $input['icon'] ) ? $input['icon'] : '';

		$url_path = isset( $input['path'] ) && is_string( $input['path'] ) ? trim( $input['path'] ) : '';
		if ( '' !== $url_path && ! preg_match( '#^/[A-Za-z0-9_\-./{}]*$#', $url_path ) ) {
			self::error( $errors, self::join( $path, 'path' ), __( 'A path starts with “/” and may use {slug} and {id}.', 'leiho' ) );
			$url_path = '';
		}

		$fields = self::normalize_fields(
			isset( $input['fields'] ) ? $input['fields'] : array(),
			self::join( $path, 'fields' ),
			$errors,
			array_unique( array_merge( $ids, array( $id ) ) ),
			1,
			$legacy
		);

		if ( count( $fields ) > self::MAX_FIELDS ) {
			/* translators: %d: maximum number of fields. */
			self::error( $errors, self::join( $path, 'fields' ), sprintf( __( 'A collection holds at most %d fields.', 'leiho' ), self::MAX_FIELDS ) );
		}

		$varchar = 0;
		foreach ( $fields as $field ) {
			if ( 0 === strpos( self::column_type( $field ), 'VARCHAR' ) ) {
				$varchar++;
			}
		}
		if ( $varchar > self::MAX_VARCHAR ) {
			/* translators: %d: maximum number of short text fields. */
			self::error( $errors, self::join( $path, 'fields' ), sprintf( __( 'A collection holds at most %d short indexed text fields (text up to 191 characters, slug, email, select).', 'leiho' ), self::MAX_VARCHAR ) );
		}

		$seo = class_exists( 'Leiho_Seo' ) ? Leiho_Seo::normalize( isset( $input['seo'] ) ? $input['seo'] : null, $fields, self::join( $path, 'seo' ), $errors ) : null;

		return array(
			'id'             => $id,
			'kind'           => $kind,
			'label'          => $label,
			'singular_label' => $singular,
			'path'           => $url_path,
			'supports'       => array_values( array_unique( $supports ) ),
			'icon'           => $icon,
			'external'       => $external,
			'source'         => $source,
			'seo'            => $seo,
			'fields'         => $fields,
		);
	}

	/**
	 * Valide une liste de champs frères.
	 *
	 * @param mixed    $list   Champs.
	 * @param string   $path   Chemin pour les erreurs.
	 * @param array    $errors Erreurs (sortie).
	 * @param string[] $ids    Collections connues.
	 * @param int      $depth  Profondeur courante.
	 * @param bool     $legacy Noms d'API repris d'ACF (ACF_NAME_PATTERN).
	 * @return array[]
	 */
	public static function normalize_fields( $list, $path, &$errors, $ids, $depth, $legacy = false ) {
		if ( ! is_array( $list ) ) {
			self::error( $errors, $path, __( 'Must be a list.', 'leiho' ) );
			return array();
		}
		if ( $depth > self::MAX_DEPTH ) {
			/* translators: %d: maximum nesting depth. */
			self::error( $errors, $path, sprintf( __( 'Fields can be nested %d levels deep at most.', 'leiho' ), self::MAX_DEPTH ) );
			return array();
		}

		$out   = array();
		$keys  = array();
		$names = array();
		foreach ( array_values( $list ) as $i => $field ) {
			$normalized = self::normalize_field( $field, self::join( $path, (string) $i ), $errors, $ids, $depth, $legacy );
			if ( null === $normalized ) {
				continue;
			}
			if ( isset( $keys[ $normalized['key'] ] ) ) {
				/* translators: %s: field key. */
				self::error( $errors, self::join( $path, $i . '.key' ), sprintf( __( 'The key “%s” is used twice.', 'leiho' ), $normalized['key'] ) );
				continue;
			}
			if ( isset( $names[ $normalized['name'] ] ) ) {
				/* translators: %s: field name. */
				self::error( $errors, self::join( $path, $i . '.name' ), sprintf( __( 'The name “%s” is used twice.', 'leiho' ), $normalized['name'] ) );
				continue;
			}
			$keys[ $normalized['key'] ]   = true;
			$names[ $normalized['name'] ] = true;
			$out[]                        = $normalized;
		}
		return $out;
	}

	/**
	 * Valide un champ.
	 *
	 * @param mixed    $input  Champ.
	 * @param string   $path   Chemin pour les erreurs.
	 * @param array    $errors Erreurs (sortie).
	 * @param string[] $ids    Collections connues.
	 * @param int      $depth  Profondeur courante.
	 * @param bool     $legacy Noms d'API repris d'ACF.
	 * @return array|null
	 */
	public static function normalize_field( $input, $path, &$errors, $ids, $depth, $legacy = false ) {
		if ( ! is_array( $input ) ) {
			self::error( $errors, $path, __( 'A field must be an object.', 'leiho' ) );
			return null;
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? $input['name'] : '';
		if ( ! preg_match( $legacy ? self::ACF_NAME_PATTERN : self::NAME_PATTERN, $name ) ) {
			self::error( $errors, self::join( $path, 'name' ), __( 'Use snake_case: lowercase letters, digits and underscores, starting with a letter (64 characters max).', 'leiho' ) );
			return null;
		}
		// La clé est l'identité stable du champ : par défaut, son nom à la création.
		$key = isset( $input['key'] ) && is_string( $input['key'] ) && '' !== $input['key'] ? $input['key'] : $name;
		if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			self::error( $errors, self::join( $path, 'key' ), __( 'Use lowercase letters, digits and underscores, starting with a letter (48 characters max).', 'leiho' ) );
			return null;
		}

		$type = isset( $input['type'] ) ? $input['type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			/* translators: %s: field type. */
			self::error( $errors, self::join( $path, 'type' ), sprintf( __( 'Unknown field type “%s”.', 'leiho' ), is_scalar( $type ) ? (string) $type : '?' ) );
			return null;
		}

		$field = array(
			'key'      => $key,
			'name'     => $name,
			'label'    => self::text( isset( $input['label'] ) ? $input['label'] : '', $name ),
			'type'     => $type,
			'required' => ! empty( $input['required'] ),
			'help'     => self::text( isset( $input['help'] ) ? $input['help'] : '', '' ),
		);

		// Traduisible (champ racine, profondeur 1) : par défaut les textes et ce qui en contient.
		if ( 1 === $depth ) {
			$field['localized'] = array_key_exists( 'localized', $input ) ? (bool) $input['localized'] : Leiho_I18n::localized_by_default( $type );
		}

		if ( in_array( $type, self::LENGTH_TYPES, true ) ) {
			$field['max_length'] = isset( $input['max_length'] ) ? max( 0, (int) $input['max_length'] ) : 0;
			if ( in_array( $type, array( 'slug', 'email' ), true ) && ( 0 === $field['max_length'] || $field['max_length'] > self::VARCHAR ) ) {
				$field['max_length'] = self::VARCHAR;
			}
		}

		switch ( $type ) {
			case 'text':
				$field['multiline'] = ! empty( $input['multiline'] );
				break;

			case 'number':
				$field['integer'] = ! empty( $input['integer'] );
				foreach ( array( 'min', 'max', 'step' ) as $option ) {
					$field[ $option ] = isset( $input[ $option ] ) && is_numeric( $input[ $option ] ) ? 0 + $input[ $option ] : null;
				}
				if ( null !== $field['min'] && null !== $field['max'] && $field['min'] > $field['max'] ) {
					self::error( $errors, self::join( $path, 'min' ), __( 'min is greater than max.', 'leiho' ) );
				}
				break;

			case 'select':
				$field['multiple'] = ! empty( $input['multiple'] );
				$field['choices']  = self::normalize_choices( isset( $input['choices'] ) ? $input['choices'] : array(), self::join( $path, 'choices' ), $errors );
				break;

			case 'gallery':
				$field['max'] = isset( $input['max'] ) ? max( 0, (int) $input['max'] ) : 0;
				break;

			case 'reference':
				$to = isset( $input['to'] ) ? (array) $input['to'] : array();
				$targets = array();
				foreach ( $to as $target ) {
					if ( ! is_string( $target ) || ! preg_match( '/^[a-z0-9_\-]{1,20}$/', $target ) ) {
						self::error( $errors, self::join( $path, 'to' ), __( 'References point to collection ids or post types.', 'leiho' ) );
						continue;
					}
					// Ni collection du schéma ni type de contenu enregistré : faute de frappe.
					if ( ! in_array( $target, $ids, true ) && ! in_array( $target, array( 'post', 'page' ), true )
						&& function_exists( 'post_type_exists' ) && ! post_type_exists( $target ) ) {
						/* translators: %s: collection id or post type. */
						self::error( $errors, self::join( $path, 'to' ), sprintf( __( 'Unknown collection or content type “%s”.', 'leiho' ), $target ) );
						continue;
					}
					$targets[] = $target;
				}
				if ( ! $targets ) {
					self::error( $errors, self::join( $path, 'to' ), __( 'Say which collections this field points to.', 'leiho' ) );
				}
				$field['to']       = array_values( array_unique( $targets ) );
				$field['multiple'] = ! empty( $input['multiple'] );
				$field['max']      = isset( $input['max'] ) ? max( 0, (int) $input['max'] ) : 0;
				break;

			case 'list':
				$field['fields'] = self::normalize_fields( isset( $input['fields'] ) ? $input['fields'] : array(), self::join( $path, 'fields' ), $errors, $ids, $depth + 1, $legacy );
				$field['min']    = isset( $input['min'] ) ? max( 0, (int) $input['min'] ) : 0;
				$field['max']    = isset( $input['max'] ) ? max( 0, (int) $input['max'] ) : 0;
				if ( ! $field['fields'] ) {
					self::error( $errors, self::join( $path, 'fields' ), __( 'A list needs at least one field.', 'leiho' ) );
				}
				break;

			case 'group':
				$field['fields'] = self::normalize_fields( isset( $input['fields'] ) ? $input['fields'] : array(), self::join( $path, 'fields' ), $errors, $ids, $depth + 1, $legacy );
				if ( ! $field['fields'] ) {
					self::error( $errors, self::join( $path, 'fields' ), __( 'A group needs at least one field.', 'leiho' ) );
				}
				break;

			case 'blocks':
				$field['blocks'] = self::normalize_blocks( isset( $input['blocks'] ) ? $input['blocks'] : array(), self::join( $path, 'blocks' ), $errors, $ids, $depth, $legacy );
				$field['min']    = isset( $input['min'] ) ? max( 0, (int) $input['min'] ) : 0;
				$field['max']    = isset( $input['max'] ) ? max( 0, (int) $input['max'] ) : 0;
				break;
		}

		return $field;
	}

	/**
	 * Choix d'un select : liste ordonnée de { value, label }. Une liste de
	 * chaînes ou un objet { value: label } sont acceptés en entrée.
	 *
	 * Pas de tableau associatif en sortie : PHP convertit les clés "1" en
	 * entiers et json_encode réécrirait {"0":…,"1":…} en liste.
	 */
	private static function normalize_choices( $input, $path, &$errors ) {
		$out  = array();
		$seen = array();
		if ( ! is_array( $input ) ) {
			self::error( $errors, $path, __( 'Must be a list.', 'leiho' ) );
			return $out;
		}
		$is_list = array_keys( $input ) === range( 0, count( $input ) - 1 );
		foreach ( $input as $k => $choice ) {
			if ( is_array( $choice ) ) {
				$value = isset( $choice['value'] ) && is_scalar( $choice['value'] ) ? (string) $choice['value'] : '';
				$label = isset( $choice['label'] ) && is_scalar( $choice['label'] ) ? (string) $choice['label'] : $value;
			} elseif ( $is_list ) {
				$value = is_scalar( $choice ) ? (string) $choice : '';
				$label = $value;
			} else {
				$value = (string) $k;
				$label = is_scalar( $choice ) ? (string) $choice : $value;
			}
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( '' === $value || $length > self::VARCHAR ) {
				self::error( $errors, $path, __( 'Each choice needs a value of 1 to 191 characters.', 'leiho' ) );
				continue;
			}
			if ( isset( $seen[ $value ] ) ) {
				continue;
			}
			$seen[ $value ] = true;
			$out[]          = array(
				'value' => $value,
				'label' => self::text( $label, $value ),
			);
		}
		if ( ! $out ) {
			self::error( $errors, $path, __( 'A select needs at least one choice.', 'leiho' ) );
		}
		return $out;
	}

	/**
	 * Types de blocs d'un champ blocks.
	 */
	private static function normalize_blocks( $input, $path, &$errors, $ids, $depth, $legacy = false ) {
		$out   = array();
		$keys  = array();
		$names = array();
		if ( ! is_array( $input ) || ! $input ) {
			self::error( $errors, $path, __( 'A blocks field needs at least one block type.', 'leiho' ) );
			return $out;
		}
		foreach ( array_values( $input ) as $i => $block ) {
			$block_path = self::join( $path, (string) $i );
			$name       = is_array( $block ) && isset( $block['name'] ) && is_string( $block['name'] ) ? $block['name'] : '';
			if ( ! preg_match( $legacy ? self::ACF_NAME_PATTERN : self::NAME_PATTERN, $name ) ) {
				self::error( $errors, self::join( $block_path, 'name' ), __( 'Use snake_case: lowercase letters, digits and underscores, starting with a letter (64 characters max).', 'leiho' ) );
				continue;
			}
			$key = isset( $block['key'] ) && is_string( $block['key'] ) && '' !== $block['key'] ? $block['key'] : $name;
			if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
				self::error( $errors, self::join( $block_path, 'key' ), __( 'Use lowercase letters, digits and underscores, starting with a letter (48 characters max).', 'leiho' ) );
				continue;
			}
			if ( isset( $keys[ $key ] ) || isset( $names[ $name ] ) ) {
				/* translators: %s: block name. */
				self::error( $errors, $block_path, sprintf( __( 'The block type “%s” is declared twice.', 'leiho' ), $name ) );
				continue;
			}
			$keys[ $key ]   = true;
			$names[ $name ] = true;
			$out[]          = array(
				'key'    => $key,
				'name'   => $name,
				'label'  => self::text( isset( $block['label'] ) ? $block['label'] : '', $name ),
				'min'    => isset( $block['min'] ) ? max( 0, (int) $block['min'] ) : 0,
				'max'    => isset( $block['max'] ) ? max( 0, (int) $block['max'] ) : 0,
				'fields' => self::normalize_fields( isset( $block['fields'] ) ? $block['fields'] : array(), self::join( $block_path, 'fields' ), $errors, $ids, $depth + 1, $legacy ),
			);
		}
		return $out;
	}

	private static function text( $value, $fallback ) {
		$value = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
		return '' === $value ? (string) $fallback : $value;
	}

	private static function join( $path, $segment ) {
		return '' === $path ? $segment : $path . '.' . $segment;
	}

	private static function error( &$errors, $path, $message ) {
		$errors[] = array(
			'path'    => $path,
			'message' => $message,
		);
	}

	/* ---------------------------------------------------------------------
	 * Stockage
	 * ------------------------------------------------------------------- */

	/**
	 * Type SQL de la colonne d'un champ racine.
	 *
	 * @param array $field Champ normalisé.
	 * @return string
	 */
	public static function column_type( $field ) {
		switch ( $field['type'] ) {
			case 'text':
				return ( $field['max_length'] > 0 && $field['max_length'] <= self::VARCHAR ) ? 'VARCHAR(191)' : 'TEXT';
			case 'slug':
			case 'email':
				return 'VARCHAR(191)';
			case 'select':
				return empty( $field['multiple'] ) ? 'VARCHAR(191)' : 'LONGTEXT';
			case 'url':
			case 'code':
				return 'TEXT';
			case 'color':
				return 'VARCHAR(32)';
			case 'richtext':
				return 'LONGTEXT';
			case 'number':
				return empty( $field['integer'] ) ? 'DECIMAL(20,6)' : 'BIGINT(20)';
			case 'boolean':
				return 'TINYINT(1)';
			case 'date':
				return 'DATE';
			case 'datetime':
				return 'DATETIME';
			case 'image':
			case 'file':
				return 'BIGINT(20) UNSIGNED';
			case 'reference':
				return empty( $field['multiple'] ) ? 'BIGINT(20) UNSIGNED' : 'LONGTEXT';
			default:
				// gallery, link, list, blocks, group, select/reference multiples : JSON.
				return 'LONGTEXT';
		}
	}

	/**
	 * La colonne mérite-t-elle un index (tri, filtre) ?
	 *
	 * @param array $field Champ normalisé.
	 * @return bool
	 */
	public static function is_indexed( $field ) {
		$type = self::column_type( $field );
		return 'VARCHAR(32)' !== $type && ! in_array( $type, array( 'TEXT', 'LONGTEXT' ), true );
	}

	/**
	 * La valeur est-elle stockée en JSON ?
	 *
	 * @param array $field Champ normalisé.
	 * @return bool
	 */
	public static function is_json( $field ) {
		if ( in_array( $field['type'], array( 'gallery', 'link', 'list', 'blocks', 'group' ), true ) ) {
			return true;
		}
		return in_array( $field['type'], array( 'select', 'reference' ), true ) && ! empty( $field['multiple'] );
	}

	/* ---------------------------------------------------------------------
	 * Traduction vers le moteur de champs
	 * ------------------------------------------------------------------- */

	/**
	 * Champ au format du moteur commun (format ACF), récursif.
	 *
	 * La « key » du moteur est la clé stable Leiho : les lignes JSON sont
	 * indexées par clé, que le moteur sait lire. leiho_type garde le type
	 * d'origine pour la sanitization propre aux Collections.
	 *
	 * @param array $field Champ normalisé.
	 * @return array
	 */
	public static function engine_field( $field ) {
		$out = array(
			'key'          => $field['key'],
			'name'         => $field['name'],
			'label'        => $field['label'],
			'required'     => $field['required'] ? 1 : 0,
			'instructions' => $field['help'],
			'leiho_type'    => $field['type'],
		);
		if ( isset( $field['max_length'] ) && $field['max_length'] > 0 ) {
			$out['maxlength'] = $field['max_length'];
		}

		switch ( $field['type'] ) {
			case 'text':
				$out['type'] = $field['multiline'] ? 'textarea' : 'text';
				break;
			case 'slug':
				$out['type'] = 'text';
				break;
			case 'richtext':
				$out['type'] = 'wysiwyg';
				break;
			case 'code':
				// Code : texte sur plusieurs lignes gardé tel quel (balises comprises), en chasse fixe.
				$out['type'] = 'textarea';
				$out['code'] = 1;
				break;
			case 'boolean':
				$out['type'] = 'true_false';
				break;
			case 'date':
				$out['type'] = 'date_picker';
				break;
			case 'datetime':
				$out['type'] = 'date_time_picker';
				break;
			case 'color':
				$out['type'] = 'color_picker';
				break;
			case 'number':
				$out['type'] = 'number';
				$out['min']  = $field['min'];
				$out['max']  = $field['max'];
				$out['step'] = $field['step'];
				$out['leiho_integer'] = $field['integer'];
				break;
			case 'select':
				$out['type']     = 'select';
				$out['multiple'] = $field['multiple'] ? 1 : 0;
				$out['choices']  = array();
				foreach ( $field['choices'] as $choice ) {
					$out['choices'][ $choice['value'] ] = $choice['label'];
				}
				break;
			case 'gallery':
				$out['type'] = 'gallery';
				$out['max']  = $field['max'];
				break;
			case 'reference':
				$out['type']      = $field['multiple'] ? 'relationship' : 'post_object';
				$out['post_type'] = $field['to'];
				$out['multiple']  = $field['multiple'] ? 1 : 0;
				$out['max']       = $field['max'];
				break;
			case 'list':
				$out['type']       = 'repeater';
				$out['min']        = $field['min'];
				$out['max']        = $field['max'];
				$out['sub_fields'] = array_map( array( __CLASS__, 'engine_field' ), $field['fields'] );
				break;
			case 'group':
				$out['type']       = 'group';
				$out['sub_fields'] = array_map( array( __CLASS__, 'engine_field' ), $field['fields'] );
				break;
			case 'blocks':
				$out['type']    = 'flexible_content';
				$out['min']     = $field['min'];
				$out['max']     = $field['max'];
				$out['layouts'] = array();
				foreach ( $field['blocks'] as $block ) {
					$out['layouts'][] = array(
						'key'        => $block['key'],
						'name'       => $block['name'],
						'label'      => $block['label'],
						'min'        => $block['min'],
						'max'        => $block['max'],
						'sub_fields' => array_map( array( __CLASS__, 'engine_field' ), $block['fields'] ),
					);
				}
				break;
			default:
				// image, file, link, email, url : même nom.
				$out['type'] = $field['type'];
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------- */

	/**
	 * Schéma pour leiho.schema.json : options par défaut omises, pour un
	 * fichier court et des diffs lisibles. normalize() le relit à l'identique.
	 *
	 * @param array $schema Schéma normalisé.
	 * @return array
	 */
	public static function compact( $schema ) {
		$out = array(
			'leiho'        => 'collections',
			'version'     => self::VERSION,
			'collections' => array(),
		);
		foreach ( $schema['collections'] as $collection ) {
			$item = array( 'id' => $collection['id'] );
			if ( 'collection' !== $collection['kind'] ) {
				$item['kind'] = $collection['kind'];
			}
			$item['label'] = $collection['label'];
			if ( $collection['singular_label'] !== $collection['label'] ) {
				$item['singular_label'] = $collection['singular_label'];
			}
			foreach ( array( 'path', 'icon' ) as $prop ) {
				if ( '' !== $collection[ $prop ] ) {
					$item[ $prop ] = $collection[ $prop ];
				}
			}
			if ( $collection['supports'] ) {
				$item['supports'] = $collection['supports'];
			}
			if ( ! empty( $collection['external'] ) ) {
				$item['external'] = true;
			}
			if ( ! empty( $collection['source'] ) ) {
				$item['source'] = $collection['source'];
			}
			if ( ! empty( $collection['seo'] ) ) {
				$item['seo'] = $collection['seo'];
			}
			$item['fields']         = self::compact_fields( $collection['fields'], true );
			$out['collections'][] = $item;
		}
		if ( ! empty( $schema['i18n'] ) ) {
			$out['i18n'] = $schema['i18n'];
		}
		return $out;
	}

	private static function compact_fields( $fields, $root = false ) {
		$out = array();
		foreach ( $fields as $field ) {
			$item = array();
			foreach ( $field as $prop => $value ) {
				// « localized » n'est écrit que s'il diffère du réglage par défaut du type.
				if ( 'localized' === $prop ) {
					if ( $root && (bool) $value !== Leiho_I18n::localized_by_default( $field['type'] ) ) {
						$item['localized'] = (bool) $value;
					}
					continue;
				}
				if ( 'fields' === $prop ) {
					$item['fields'] = self::compact_fields( $value );
					continue;
				}
				if ( 'blocks' === $prop ) {
					$item['blocks'] = array();
					foreach ( $value as $block ) {
						$compact = array(
							'key'   => $block['key'],
							'name'  => $block['name'],
							'label' => $block['label'],
						);
						foreach ( array( 'min', 'max' ) as $bound ) {
							if ( $block[ $bound ] ) {
								$compact[ $bound ] = $block[ $bound ];
							}
						}
						$compact['fields'] = self::compact_fields( $block['fields'] );
						$item['blocks'][]  = $compact;
					}
					continue;
				}
				// 0 est une borne réelle pour un nombre (min/max à null par défaut).
				$default = ( false === $value || null === $value || '' === $value || ( 0 === $value && 'number' !== $field['type'] ) )
					|| ( 'max_length' === $prop && self::VARCHAR === $value && in_array( $field['type'], array( 'slug', 'email' ), true ) );
				if ( $default && ! in_array( $prop, array( 'key', 'name', 'label', 'type' ), true ) ) {
					continue;
				}
				$item[ $prop ] = $value;
			}
			$out[] = $item;
		}
		return $out;
	}
}
