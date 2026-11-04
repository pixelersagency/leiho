<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adapter ACF : parse les field groups attachés à un post.
 *
 * Le stockage passe par get_field()/update_field() ; la description, la
 * lecture, le patch des chemins pointés et la sanitization viennent du moteur
 * commun (Leiho_Field_Adapter). Les chemins pointés sont ceux que le bridge
 * pose en data-wp-field : ils sont donc aussi acceptés en écriture (patch
 * ciblé), pas seulement en lecture.
 */
class Leiho_ACF_Adapter extends Leiho_Field_Adapter {

	/** Cache par requête : post_id => field groups. */
	private $groups_cache = array();

	/** Cache par requête : "post_id:path" => définition brute du champ racine. */
	private $roots_cache = array();

	public function get_id() {
		return 'acf';
	}

	public function is_available() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/* ---------------------------------------------------------------------
	 * Lecture
	 * ------------------------------------------------------------------- */

	public function get_schema( $post_id ) {
		$groups = array();

		foreach ( $this->field_groups( $post_id ) as $group ) {
			$mapped = array();
			foreach ( $group['fields'] as $field ) {
				$mapped[] = $this->map_field( $field, $post_id );
			}
			if ( empty( $mapped ) ) {
				continue;
			}

			$groups[] = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'source' => 'acf',
				'fields' => $mapped,
			);
		}

		return $groups;
	}

	/**
	 * Field groups + champs du post, mémorisés pour la requête (get_schema()
	 * et l'écriture parcouraient chacun tous les groupes).
	 *
	 * @param int $post_id Post.
	 * @return array<int,array{key:string,title:string,fields:array}>
	 */
	private function field_groups( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( $this->groups_cache[ $post_id ] ) ) {
			return $this->groups_cache[ $post_id ];
		}

		// Type importé dans Leiho Collections : ses champs ne viennent plus d'ACF.
		if ( self::retired( get_post_type( $post_id ) ) ) {
			$this->groups_cache[ $post_id ] = array();
			return array();
		}

		$out = array();
		foreach ( acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( empty( $fields ) ) {
				continue;
			}
			$out[] = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => $fields,
			);
		}

		$this->groups_cache[ $post_id ] = $out;
		return $out;
	}

	/**
	 * Définitions brutes des champs racine, indexées par name.
	 *
	 * @param int $post_id Post.
	 * @return array<string,array>
	 */
	private function root_fields( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( $this->roots_cache[ $post_id ] ) ) {
			return $this->roots_cache[ $post_id ];
		}

		$by_name = array();
		foreach ( $this->field_groups( $post_id ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				$by_name[ $field['name'] ] = $field;
			}
		}

		$this->roots_cache[ $post_id ] = $by_name;
		return $by_name;
	}

	/**
	 * Normalise un champ ACF (et ses descendants) vers le schéma Leiho.
	 *
	 * @param array $field   Champ ACF brut.
	 * @param int   $post_id Post.
	 * @return array
	 */
	private function map_field( $field, $post_id ) {
		$entry           = $this->describe( $field );
		$entry['source'] = 'acf';

		if ( ! $entry['supported'] ) {
			$entry['edit_url'] = get_edit_post_link( $post_id, 'raw' );
			return $entry;
		}

		$raw       = get_field( $field['key'], $post_id, false );
		$valued    = $this->read_value( $field, $raw );
		$entry     = array_merge( $entry, $valued );
		$entry['source'] = 'acf';

		return $entry;
	}

	/* ---------------------------------------------------------------------
	 * Contenu servi au front
	 * ------------------------------------------------------------------- */

	public function get_content( $post_id ) {
		$out = array();
		foreach ( $this->field_groups( $post_id ) as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( '' === (string) $field['name'] || in_array( $field['type'], self::LAYOUT_TYPES, true ) ) {
					continue;
				}
				$out[ $field['name'] ] = $this->public_value( $field, get_field( $field['key'], $post_id, false ) );
			}
		}
		return $out;
	}

	public function get_definitions( $post_type ) {
		$out = array();
		if ( self::retired( $post_type ) ) {
			return $out;
		}
		foreach ( acf_get_field_groups() as $group ) {
			$target = self::targets( isset( $group['location'] ) ? $group['location'] : array(), $post_type );
			if ( null === $target ) {
				continue;
			}
			$fields = acf_get_fields( $group['key'] );
			if ( empty( $fields ) ) {
				continue;
			}
			$out[] = array(
				'conditional' => $target,
				'fields'      => $fields,
			);
		}
		return $out;
	}

	/**
	 * Les champs ACF de ce type ont-ils été importés dans Leiho Collections ?
	 * Les groupes ACF restent intacts (retour possible), mais ne servent plus.
	 *
	 * @param string|false $post_type Type.
	 * @return bool
	 */
	public static function retired( $post_type ) {
		return is_string( $post_type ) && '' !== $post_type && class_exists( 'Leiho_ACF_Import' ) && 'leiho' === Leiho_ACF_Import::engine( $post_type );
	}

	/**
	 * Le groupe s'applique-t-il à ce type de contenu, et sous condition ?
	 *
	 * Les règles ACF sont un OU de ET. Une branche vise le type si elle le
	 * nomme (post_type ==), ou si elle ne porte que sur des critères propres
	 * aux pages (modèle, parent…) ou aux articles (catégorie, format…). Elle
	 * est inconditionnelle quand « post_type == type » est sa seule règle.
	 *
	 * @param array  $location  Règles ACF.
	 * @param string $post_type Type de contenu.
	 * @return bool|null null = ne s'applique pas ; true = sous condition ; false = toujours.
	 */
	public static function targets( $location, $post_type ) {
		$page_params = array( 'page_template', 'page_type', 'page', 'page_parent' );
		$post_params = array( 'post_category', 'post_format', 'post_taxonomy' );

		$applies     = false;
		$conditional = true;

		foreach ( (array) $location as $branch ) {
			$named   = null; // Type nommé par la branche (null = aucun).
			$implied = null;
			$other   = 0;
			$valid   = true;

			foreach ( (array) $branch as $rule ) {
				$param    = isset( $rule['param'] ) ? $rule['param'] : '';
				$operator = isset( $rule['operator'] ) ? $rule['operator'] : '==';
				$value    = isset( $rule['value'] ) ? $rule['value'] : '';

				if ( 'post_type' === $param ) {
					if ( '==' === $operator ) {
						$named = $value;
					} elseif ( '!=' === $operator && $value === $post_type ) {
						$valid = false;
					} else {
						$other++;
					}
				} elseif ( in_array( $param, $page_params, true ) ) {
					$implied = 'page';
					$other++;
				} elseif ( in_array( $param, $post_params, true ) ) {
					$implied = 'post';
					$other++;
				} elseif ( 'post_template' === $param || 'post_status' === $param || 'post' === $param ) {
					$other++;
				} else {
					// Écran étranger au contenu (options, utilisateur, taxonomie…).
					$valid = false;
				}
			}

			$type = null !== $named ? $named : $implied;
			if ( ! $valid || $type !== $post_type ) {
				continue;
			}

			$applies = true;
			if ( 0 === $other ) {
				$conditional = false;
			}
		}

		return $applies ? $conditional : null;
	}

	/* ---------------------------------------------------------------------
	 * Écriture
	 * ------------------------------------------------------------------- */

	public function save_values( $post_id, $values ) {
		$saved  = array();
		$errors = array();
		$roots  = $this->root_fields( $post_id );

		// Les chemins pointés (rep.0.titre) patchent la valeur du champ racine :
		// on regroupe par racine pour n'écrire qu'une fois par champ.
		$batches = array();
		foreach ( $values as $path => $value ) {
			$root = explode( '.', $path )[0];
			if ( ! isset( $roots[ $root ] ) ) {
				continue; // Pas un champ ACF de ce post : un autre handler s'en charge peut-être.
			}
			if ( ! isset( $batches[ $root ] ) ) {
				$batches[ $root ] = array();
			}
			$batches[ $root ][ $path ] = $value;
		}

		foreach ( $batches as $root => $paths ) {
			$field = $roots[ $root ];

			if ( ! in_array( $field['type'], self::SUPPORTED_TYPES, true ) ) {
				foreach ( array_keys( $paths ) as $path ) {
					$errors[ $path ] = 'unsupported_type';
				}
				continue;
			}

			// Valeur de départ : l'existant, pour qu'un patch partiel n'efface rien.
			$current = get_field( $field['key'], $post_id, false );
			$next    = $current;
			$failed  = false;
			$touched = array();

			foreach ( $paths as $path => $value ) {
				$error   = null;
				$segments = array_slice( explode( '.', $path ), 1 );
				$next     = $this->patch( $field, $next, $segments, $value, $error, $post_id );
				if ( null !== $error ) {
					$errors[ $path ] = $error;
					$failed          = true;
					break;
				}
				$touched[] = $path;
			}

			if ( $failed ) {
				continue;
			}

			// Update_field() peut échouer (post verrouillé, filtre tiers).
			// Mais il renvoie aussi false quand la méta ne change pas : c'est le
			// cas de tout repeater, groupe ou flexible dont on édite un sous-champ
			// (seul le nombre de lignes est stocké sur le champ parent). On relit
			// donc la base avant de conclure à un échec.
			$ok = update_field( $field['key'], $next, $post_id );
			if ( false === $ok && ! $this->same_value( get_field( $field['key'], $post_id, false ), $next ) ) {
				foreach ( $touched as $path ) {
					$errors[ $path ] = 'save_failed';
				}
				continue;
			}

			$saved = array_merge( $saved, $touched );
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}
}
