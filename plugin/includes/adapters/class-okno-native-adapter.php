<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adapter Okno Collections : champs définis dans Okno, valeurs dans la table
 * de la collection.
 *
 * Chaque champ est traduit au format du moteur commun (Okno_Field_Adapter),
 * le même que pour ACF : l'éditeur visuel, le journal, l'API de contenu et la
 * génération de types fonctionnent sans changement, chemins pointés compris
 * (sections.2.cartes.1.titre).
 */
class Okno_Native_Adapter extends Okno_Field_Adapter {

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
		return $type ? Okno_Collections::for_post_type( $type ) : null;
	}

	/**
	 * Champs racine d'une collection : définition normalisée + format moteur.
	 *
	 * @param array $collection Collection.
	 * @return array<string,array{field:array,engine:array}>
	 */
	private function fields( $collection ) {
		$id = $collection['id'];
		if ( Okno_Collections::generation() !== $this->generation ) {
			$this->fields_cache = array();
			$this->generation   = Okno_Collections::generation();
		}
		if ( ! isset( $this->fields_cache[ $id ] ) ) {
			$out = array();
			foreach ( $collection['fields'] as $field ) {
				$out[ $field['name'] ] = array(
					'field'  => $field,
					'engine' => Okno_Collections_Schema::engine_field( $field ),
				);
			}
			$this->fields_cache[ $id ] = $out;
		}
		return $this->fields_cache[ $id ];
	}

	/**
	 * Valeurs brutes (format moteur) des champs racine d'une entrée.
	 *
	 * @param array $collection Collection.
	 * @param int   $post_id    Entrée.
	 * @return array<string,mixed> nom => valeur brute.
	 */
	private function raw_values( $collection, $post_id ) {
		$stored = Okno_Collections_Store::values( $collection['id'], $post_id );
		$out    = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$key          = $def['field']['key'];
			$out[ $name ] = Okno_Collections_Values::decode( $def['field'], isset( $stored[ $key ] ) ? $stored[ $key ] : null );
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

		$raw    = $this->raw_values( $collection, $post_id );
		$fields = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$entry           = array_merge( $this->describe( $def['engine'] ), $this->read_value( $def['engine'], $raw[ $name ] ) );
			$entry['source'] = 'collections';
			$fields[]        = $entry;
		}

		return array(
			array(
				'key'    => 'okno_collection_' . $collection['id'],
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

		$raw     = $this->raw_values( $collection, $post_id );
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
			$columns[ $def['field']['key'] ] = Okno_Collections_Values::encode( $def['field'], $next );
			$touched                         = array_merge( $touched, $done );
		}

		if ( ! $columns ) {
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		// Une seule écriture pour tous les champs du lot.
		if ( ! Okno_Collections_Store::write( $collection['id'], $post_id, $columns ) ) {
			foreach ( $touched as $path ) {
				$errors[ $path ] = 'save_failed';
			}
			return array(
				'saved'  => $saved,
				'errors' => $errors,
			);
		}

		// Révision WordPress des champs (si le type les active).
		if ( function_exists( 'wp_save_post_revision' ) && post_type_supports( $collection['id'], 'revisions' ) ) {
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
		$out = array();
		foreach ( $this->fields( $collection ) as $name => $def ) {
			$out[ $name ] = $this->public_value( $def['engine'], $raw[ $name ] );
		}
		return $out;
	}

	public function get_definitions( $post_type ) {
		$collection = Okno_Collections::for_post_type( $post_type );
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
		$type  = isset( $field['okno_type'] ) ? $field['okno_type'] : '';

		if ( 'slug' === $type ) {
			if ( ! empty( $field['required'] ) && $this->is_empty( $value, 'text' ) ) {
				$error = 'required';
				return null;
			}
			return $this->within_length( $field, sanitize_title( (string) $value ), $error );
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
		$integer = ! empty( $field['okno_integer'] );
		$limit   = $integer ? Okno_Collections_Schema::INTEGER_LIMIT : Okno_Collections_Schema::DECIMAL_LIMIT;
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
