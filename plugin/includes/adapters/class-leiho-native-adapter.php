<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adapter Leiho Collections : champs définis dans Leiho, valeurs dans la table
 * de la collection.
 *
 * Chaque champ est traduit au format du moteur commun (Leiho_Field_Adapter),
 * le même que pour ACF : l'éditeur visuel, le journal, l'API de contenu et la
 * génération de types fonctionnent sans changement, chemins pointés compris
 * (sections.2.cartes.1.titre).
 */
class Leiho_Native_Adapter extends Leiho_Field_Adapter {

	/** Types du moteur éditables ici : ceux d'ACF + la couleur. */
	const SUPPORTED_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
		'image',
		'file',
		'gallery',
		'url',
		'link',
		'email',
		'select',
		'true_false',
		'number',
		'date_picker',
		'date_time_picker',
		'color_picker',
		'post_object',
		'relationship',
		'repeater',
		'group',
		'flexible_content',
	);

	/** Cache par requête : collection => champs { field, engine } indexés par nom. */
	private $fields_cache = array();

	/** Génération du schéma du cache (un enregistrement du schéma le périme). */
	private $generation = 0;

	public function get_id() {
		return 'collections';
	}

	public function is_available() {
		return true;
	}

	/**
	 * Collection d'un post.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	private function collection( $post_id ) {
		$type = get_post_type( $post_id );
		return $type ? $this->served( $type ) : null;
	}

	/**
	 * Collection d'un type, si Leiho en sert les champs : pendant un import
	 * ACF, c'est encore ACF qui sert le type (sauf pour l'import lui-même).
	 *
	 * @param string $post_type Type.
	 * @return array|null
	 */
	private function served( $post_type ) {
		$collection = Leiho_Collections::for_post_type( $post_type );
		if ( $collection && class_exists( 'Leiho_ACF_Import' ) && ! Leiho_ACF_Import::native_serves( $post_type ) ) {
			return null;
		}
		return $collection;
	}

	/**
	 * Champs racine d'une collection : définition normalisée + format moteur.
	 *
	 * @param array $collection Collection.
	 * @return array<string,array{field:array,engine:array}>
	 */
	private function fields( $collection ) {
		$id = $collection['id'];
		if ( Leiho_Collections::generation() !== $this->generation ) {
			$this->fields_cache = array();
			$this->generation   = Leiho_Collections::generation();
		}
		if ( ! isset( $this->fields_cache[ $id ] ) ) {
			$out = array();
			foreach ( $collection['fields'] as $field ) {
				$engine = Leiho_Collections_Schema::engine_field( $field );
				if ( isset( $collection['source'] ) && 'acf' === $collection['source'] ) {
					self::mark_acf( $engine );
				}
				$out[ $field['name'] ] = array(
					'field'  => $field,
					'engine' => $engine,
				);
			}
			$this->fields_cache[ $id ] = $out;
		}
		return $this->fields_cache[ $id ];
	}

	/**
	 * Champ d'une collection importée d'ACF : ses wysiwyg gardent le rendu
	 * d'ACF même ACF désactivé (Leiho_Content::html()).
	 *
	 * @param array $engine Champ moteur (entrée/sortie).
	 * @return void
	 */
	private static function mark_acf( &$engine ) {
		$engine['leiho_acf'] = true;
		if ( isset( $engine['sub_fields'] ) ) {
			foreach ( $engine['sub_fields'] as &$sub ) {
				self::mark_acf( $sub );
			}
			unset( $sub );
		}
		if ( isset( $engine['layouts'] ) ) {
			foreach ( $engine['layouts'] as &$layout ) {
				foreach ( $layout['sub_fields'] as &$sub ) {
					self::mark_acf( $sub );
				}
				unset( $sub );
			}
			unset( $layout );
		}
	}

	/**
	 * Valeurs brutes (format moteur) des champs racine d'une entrée.
	 *
	 * @param array $collection Collection.
	 * @param int   $post_id    Entrée.
	 * @return array<string,mixed> nom => valeur brute.
	 */
	private function raw_values( $collection, $post_id, $locale = null ) {
		$stored = Leiho_Collections_Store::values( $collection['id'], $post_id );
		// Autre langue que celle par défaut : les champs traduisibles viennent de
		// la traduction (vides si pas encore traduits), les autres sont partagés.
		$locale = null === $locale ? Leiho_I18n::current() : $locale;
		if ( $locale !== Leiho_I18n::default_locale() ) {
			$row = Leiho_I18n::row( $post_id, $locale );
			foreach ( $collection['fields'] as $field ) {
				if ( Leiho_I18n::is_localized( $field ) ) {
					$stored[ $field['key'] ] = $row && isset( $row['data'][ $field['key'] ] ) ? $row['data'][ $field['key'] ] : null;
				}
			}
		}
		$out    = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$key          = $def['field']['key'];
			$out[ $name ] = Leiho_Collections_Values::decode( $def['field'], isset( $stored[ $key ] ) ? $stored[ $key ] : null );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Éditeur
	 * ------------------------------------------------------------------- */

	public function get_schema( $post_id ) {
		$collection = $this->collection( $post_id );
		if ( ! $collection || ! $collection['fields'] ) {
			return array();
		}

		$raw = $this->raw_values( $collection, $post_id );
		// Traduction : le texte d'origine sert d'indication dans les champs vides.
		$source = Leiho_I18n::is_translation() ? $this->raw_values( $collection, $post_id, Leiho_I18n::default_locale() ) : null;
		$fields = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$entry              = array_merge( $this->describe( $def['engine'] ), $this->read_value( $def['engine'], $raw[ $name ] ) );
			$entry['source']    = 'collections';
			$entry['localized'] = Leiho_I18n::is_localized( $def['field'] );
			if ( $source && $entry['localized'] && is_string( $source[ $name ] ) && '' !== $source[ $name ] ) {
				$entry['original'] = wp_strip_all_tags( $source[ $name ] );
			}
			$fields[] = $entry;
		}

		return array(
			array(
				'key'    => 'leiho_collection_' . $collection['id'],
				'title'  => $collection['singular_label'],
				'source' => 'collections',
				'fields' => $fields,
			),
		);
	}

	/**
	 * Description d'un champ, avec son aide pour les éditeurs.
	 *
	 * @param array $field Champ moteur.
	 * @return array
	 */
	protected function describe( $field ) {
		$entry = parent::describe( $field );
		if ( ! empty( $field['code'] ) ) {
			$entry['code'] = true;
		}
		if ( ! empty( $field['instructions'] ) ) {
			$entry['help'] = $field['instructions'];
		}
		return $entry;
	}

	public function save_values( $post_id, $values ) {
		$saved      = array();
		$errors     = array();
		$collection = $this->collection( $post_id );
		if ( ! $collection ) {
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		$defs    = $this->fields( $collection );
		$batches = array();
		foreach ( (array) $values as $path => $value ) {
			$root = explode( '.', (string) $path )[0];
			if ( ! isset( $defs[ $root ] ) ) {
				continue; // Pas un champ de la collection : un autre adapter s'en charge peut-être.
			}
			$batches[ $root ][ (string) $path ] = $value;
		}
		if ( ! $batches ) {
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		$raw = $this->raw_values( $collection, $post_id );
		// Traduction partielle (un élément de liste, un champ de groupe) : on part
		// de la valeur d'origine tant que le champ n'est pas encore traduit.
		if ( Leiho_I18n::is_translation() ) {
			$source = null;
			foreach ( array_keys( $batches ) as $root ) {
				if ( ! Leiho_I18n::is_localized( $defs[ $root ]['field'] ) ) {
					continue;
				}
				if ( $this->is_blank( $raw[ $root ] ) ) {
					$source       = null === $source ? $this->raw_values( $collection, $post_id, Leiho_I18n::default_locale() ) : $source;
					$raw[ $root ] = $this->blank_texts( $defs[ $root ]['engine'], $source[ $root ] );
					continue;
				}
				// Lignes ajoutées à l'original après la traduction : la liste traduite
				// reçoit leurs pendants (textes vidés), pour qu'on puisse les traduire.
				$engine_type = isset( $defs[ $root ]['engine']['type'] ) ? $defs[ $root ]['engine']['type'] : '';
				if ( in_array( $engine_type, array( 'repeater', 'flexible_content' ), true ) && is_array( $raw[ $root ] ) && array_values( $raw[ $root ] ) === $raw[ $root ] ) {
					$source   = null === $source ? $this->raw_values( $collection, $post_id, Leiho_I18n::default_locale() ) : $source;
					$original = isset( $source[ $root ] ) && is_array( $source[ $root ] ) ? array_values( $source[ $root ] ) : array();
					if ( count( $original ) > count( $raw[ $root ] ) ) {
						$extra        = array_slice( $original, count( $raw[ $root ] ) );
						$raw[ $root ] = array_merge( $raw[ $root ], $this->blank_texts( $defs[ $root ]['engine'], $extra ) );
					}
				}
			}
		}
		$columns = array();
		$touched = array();
		foreach ( $batches as $root => $paths ) {
			$def    = $defs[ $root ];
			$next   = $raw[ $root ];
			$failed = false;
			$done   = array();
			foreach ( $paths as $path => $value ) {
				$error    = null;
				$segments = array_slice( explode( '.', $path ), 1 );
				$next     = $this->patch( $def['engine'], $next, $segments, $value, $error, $post_id );
				if ( null !== $error ) {
					$errors[ $path ] = $error;
					$failed          = true;
					break;
				}
				$done[] = $path;
			}
			if ( $failed ) {
				continue;
			}
			$columns[ $def['field']['key'] ] = Leiho_Collections_Values::encode( $def['field'], $next );
			$touched                         = array_merge( $touched, $done );
		}

		if ( ! $columns ) {
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		// Autre langue : les champs traduisibles vont dans la traduction, les
		// champs partagés restent dans la table de la collection.
		$ok = true;
		if ( Leiho_I18n::is_translation() ) {
			$localized = array();
			foreach ( $collection['fields'] as $field ) {
				if ( array_key_exists( $field['key'], $columns ) && Leiho_I18n::is_localized( $field ) ) {
					$localized[ $field['key'] ] = $columns[ $field['key'] ];
					unset( $columns[ $field['key'] ] );
				}
			}
			if ( $localized ) {
				$ok = Leiho_I18n::write( $post_id, Leiho_I18n::current(), $localized );
			}
		}

		// Une seule écriture pour tous les champs du lot.
		if ( ! $ok || ( $columns && ! Leiho_Collections_Store::write( $collection['id'], $post_id, $columns ) ) ) {
			foreach ( $touched as $path ) {
				$errors[ $path ] = 'save_failed';
			}
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		Leiho_Collections::entry_changed( $post_id );

		// Révision WordPress des champs (si le type les active).
		/**
		 * Révision WordPress après l'écriture des champs d'une entrée.
		 *
		 * L'import ACF l'éteint : copier les valeurs ne change pas l'entrée.
		 *
		 * @param bool $save    Enregistrer une révision.
		 * @param int  $post_id Entrée.
		 */
		if ( function_exists( 'wp_save_post_revision' ) && post_type_supports( $collection['id'], 'revisions' ) && apply_filters( 'leiho_collections_save_revision', true, $post_id ) ) {
			wp_save_post_revision( $post_id );
		}

		return array(
			'saved'  => $touched,
			'errors' => $errors,
		);
	}

	/* ---------------------------------------------------------------------
	 * Contenu servi au front
	 * ------------------------------------------------------------------- */

	public function get_content( $post_id ) {
		$collection = $this->collection( $post_id );
		if ( ! $collection ) {
			return array();
		}
		$raw = $this->raw_values( $collection, $post_id );
		// Servi au front : un champ pas encore traduit retombe sur la langue par défaut.
		$fallback = Leiho_I18n::is_translation() ? $this->raw_values( $collection, $post_id, Leiho_I18n::default_locale() ) : null;
		$out      = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$value = $raw[ $name ];
			if ( $fallback && Leiho_I18n::is_localized( $def['field'] ) ) {
				$value = $this->fill( $value, $fallback[ $name ] );
			}
			$out[ $name ] = $this->public_value( $def['engine'], $value );
		}
		return $out;
	}

	/**
	 * Structure d'origine aux textes vidés : point de départ d'une traduction
	 * partielle (images, liens et choix gardés, textes à traduire).
	 *
	 * @param array $field Champ moteur.
	 * @param mixed $value Valeur brute d'origine.
	 * @return mixed
	 */
	private function blank_texts( $field, $value ) {
		$type = isset( $field['type'] ) ? $field['type'] : '';
		if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) && empty( $field['code'] ) ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( 'link' === $type ) {
			$value['title'] = ''; // Libellé à traduire, adresse gardée.
			return $value;
		}
		if ( 'group' === $type ) {
			return $this->blank_row( $field['sub_fields'], $value );
		}
		if ( 'repeater' === $type ) {
			return array_map(
				function ( $row ) use ( $field ) {
					return is_array( $row ) ? $this->blank_row( $field['sub_fields'], $row ) : $row;
				},
				$value
			);
		}
		if ( 'flexible_content' === $type ) {
			$layouts = array();
			foreach ( $field['layouts'] as $layout ) {
				$layouts[ $layout['name'] ] = $layout['sub_fields'];
			}
			return array_map(
				function ( $row ) use ( $layouts ) {
					$name = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
					return isset( $layouts[ $name ] ) ? $this->blank_row( $layouts[ $name ], $row ) : $row;
				},
				$value
			);
		}
		return $value;
	}

	/**
	 * @param array $subs Sous-champs moteur.
	 * @param array $row  Ligne brute.
	 * @return array
	 */
	private function blank_row( $subs, $row ) {
		foreach ( $subs as $sub ) {
			if ( array_key_exists( $sub['key'], $row ) ) {
				$row[ $sub['key'] ] = $this->blank_texts( $sub, $row[ $sub['key'] ] );
			}
		}
		return $row;
	}

	/**
	 * Valeur traduite complétée par l'original là où elle est vide (champ,
	 * ligne ou sous-champ pas encore traduit).
	 *
	 * @param mixed $value    Valeur traduite.
	 * @param mixed $fallback Valeur d'origine.
	 * @return mixed
	 */
	private function fill( $value, $fallback ) {
		if ( $this->is_blank( $value ) ) {
			return $fallback;
		}
		if ( is_array( $value ) && is_array( $fallback ) ) {
			if ( isset( $value['acf_fc_layout'], $fallback['acf_fc_layout'] ) && $value['acf_fc_layout'] !== $fallback['acf_fc_layout'] ) {
				return $value; // Section d'un autre type : rien à reprendre.
			}
			$row = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			foreach ( $fallback as $key => $item ) {
				if ( array_key_exists( $key, $value ) ) {
					$value[ $key ] = $this->fill( $value[ $key ], $item );
				} elseif ( $row ) {
					$value[ $key ] = $item; // Sous-champ vidé (non stocké) : l'original.
				}
			}
		}
		return $value;
	}

	/**
	 * Valeur brute vide (pas encore traduite).
	 *
	 * @param mixed $value Valeur brute.
	 * @return bool
	 */
	private function is_blank( $value ) {
		return null === $value || '' === $value || array() === $value || false === $value;
	}

	public function get_definitions( $post_type ) {
		$collection = $this->served( $post_type );
		if ( ! $collection || ! $collection['fields'] ) {
			return array();
		}
		$fields = array();
		foreach ( $this->fields( $collection ) as $def ) {
			$fields[] = $def['engine'];
		}
		return array(
			array(
				'conditional' => false,
				'fields'      => $fields,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Sanitization propre aux Collections
	 * ------------------------------------------------------------------- */

	/**
	 * Stockage des dates en colonnes DATE / DATETIME.
	 *
	 * @param string $type Type moteur.
	 * @return string
	 */
	protected function storage_format( $type ) {
		return 'date_picker' === $type ? 'Y-m-d' : parent::storage_format( $type );
	}

	/**
	 * Règles du moteur + celles des colonnes typées : slug, select multiple,
	 * bornes numériques, longueur des colonnes, type des références.
	 *
	 * @param array  $field   Champ moteur.
	 * @param mixed  $value   Valeur reçue.
	 * @param string $error   Code d'erreur (sortie).
	 * @param int    $post_id Entrée.
	 * @return mixed
	 */
	protected function sanitize( $field, $value, &$error, $post_id = 0 ) {
		$error = null;
		$type  = isset( $field['leiho_type'] ) ? $field['leiho_type'] : '';

		if ( 'slug' === $type ) {
			if ( ! empty( $field['required'] ) && $this->is_empty( $value, 'text' ) ) {
				$error = 'required';
				return null;
			}
			return $this->within_length( $field, sanitize_title( (string) $value ), $error );
		}

		if ( 'code' === $type ) {
			// Gardé tel quel : un front affiche ce texte comme du texte, jamais comme du HTML.
			if ( ! empty( $field['required'] ) && $this->is_empty( $value, 'textarea' ) ) {
				$error = 'required';
				return null;
			}
			$code = is_scalar( $value ) ? str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), (string) $value ) : '';
			// Du balisage gardé tel quel : réservé à qui peut déjà publier du HTML
			// sans filtre (unfiltered_html). Un auteur, un contributeur ou un agent
			// écrit du code sans balise ; un front qui l'afficherait comme du HTML
			// par erreur ne recevrait jamais de script de leur part.
			if ( false !== strpos( $code, '<' ) && ! current_user_can( 'unfiltered_html' ) ) {
				$error = 'markup_not_allowed';
				return null;
			}
			return $this->within_length( $field, wp_check_invalid_utf8( $code ), $error );
		}

		if ( 'select' === $type && ! empty( $field['multiple'] ) ) {
			$items = is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : array( $value ) );
			if ( ! empty( $field['required'] ) && ! $items ) {
				$error = 'required';
				return null;
			}
			$out = array();
			foreach ( $items as $item ) {
				$item = is_scalar( $item ) ? (string) $item : '';
				if ( ! array_key_exists( $item, $field['choices'] ) ) {
					$error = 'invalid_choice';
					return null;
				}
				$out[] = $item;
			}
			return array_values( array_unique( $out ) );
		}

		$clean = parent::sanitize( $field, $value, $error, $post_id );
		if ( null !== $error ) {
			return $clean;
		}

		switch ( $type ) {
			case 'email':
			case 'url':
				return '' === $clean ? $clean : $this->within_length( $field, $clean, $error );

			case 'number':
				return $this->check_number( $field, $clean, $error );

			case 'reference':
				$targets = isset( $field['post_type'] ) ? (array) $field['post_type'] : array();
				foreach ( is_array( $clean ) ? $clean : ( $clean ? array( $clean ) : array() ) as $id ) {
					if ( $targets && ! in_array( get_post_type( $id ), $targets, true ) ) {
						$error = 'invalid_reference';
						return null;
					}
				}
				return $clean;
		}

		return $clean;
	}

	private function check_number( $field, $value, &$error ) {
		if ( '' === $value || null === $value ) {
			return $value;
		}
		$integer = ! empty( $field['leiho_integer'] );
		$limit   = $integer ? Leiho_Collections_Schema::INTEGER_LIMIT : Leiho_Collections_Schema::DECIMAL_LIMIT;
		if ( abs( $value ) > $limit ) {
			$error = 'out_of_range';
			return null;
		}
		if ( isset( $field['min'] ) && null !== $field['min'] && $value < $field['min'] ) {
			$error = 'out_of_range';
			return null;
		}
		if ( isset( $field['max'] ) && null !== $field['max'] && $value > $field['max'] ) {
			$error = 'out_of_range';
			return null;
		}
		if ( $integer && floor( $value ) !== $value ) {
			$error = 'invalid_number';
			return null;
		}
		return $value;
	}
}
