<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adapter JetEngine : les champs des meta boxes et des types de contenu
 * JetEngine (Crocoblock).
 *
 * JetEngine enregistre chaque champ dans une méta à son nom, avec ses propres
 * formats : repeater indexé « item-0 », « item-1 »… ; média en ID, en URL ou
 * les deux selon value_format ; galerie en IDs séparés par des virgules ;
 * interrupteur « true » / « false » ; date en timestamp quand is_timestamp.
 * L'adapter traduit ses définitions au format ACF du moteur commun
 * (Leiho_Field_Adapter) et convertit les valeurs dans les deux sens, pour que
 * le front (listings JetEngine, GraphQL, API REST) relise exactement ce que
 * JetEngine aurait écrit.
 *
 * Équivalents Leiho des champs propres à JetEngine : couleur → couleur,
 * icône → texte (sa classe, « fas fa-star »), carte → groupe latitude /
 * longitude (ou adresse). Les métas annexes de JetEngine (empreinte et
 * coordonnées d'une carte, copies d'un repeater « en champs séparés ») sont
 * réécrites comme le ferait son formulaire.
 */
class Leiho_JetEngine_Adapter extends Leiho_Field_Adapter {

	/** Types éditables : ceux du moteur commun + la couleur. */
	const SUPPORTED_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
		'image',
		'file',
		'gallery',
		'url',
		'link',
		'page_link',
		'oembed',
		'email',
		'select',
		'true_false',
		'number',
		'range',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
		'post_object',
		'relationship',
		'taxonomy',
		'repeater',
		'group',
		'clone',
		'flexible_content',
	);

	/** Cache par requête : post_type => champs au format ACF. */
	private $fields_cache = array();

	public function get_id() {
		return 'jetengine';
	}

	public function is_available() {
		return function_exists( 'jet_engine' ) && is_object( jet_engine() ) && isset( jet_engine()->meta_boxes ) && is_object( jet_engine()->meta_boxes );
	}

	/* ---------------------------------------------------------------------
	 * Définitions
	 * ------------------------------------------------------------------- */

	/**
	 * Champs JetEngine d'un type de contenu, au format ACF du moteur commun.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array<string,array> Indexés par nom.
	 */
	private function fields( $post_type ) {
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return array();
		}
		if ( isset( $this->fields_cache[ $post_type ] ) ) {
			return $this->fields_cache[ $post_type ];
		}

		$raw = jet_engine()->meta_boxes->get_meta_fields_for_object( $post_type );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $jet ) {
			$field = $this->map_definition( $jet );
			if ( null !== $field ) {
				$out[ $field['name'] ] = $field;
			}
		}

		$this->fields_cache[ $post_type ] = $out;
		return $out;
	}

	/**
	 * Définition JetEngine → définition ACF. null pour les éléments de mise en
	 * page (onglets, accordéons, fin de groupe, blocs HTML) et les champs sans nom.
	 *
	 * @param array $jet Champ JetEngine.
	 * @return array|null
	 */
	private function map_definition( $jet ) {
		if ( ! is_array( $jet ) || empty( $jet['name'] ) ) {
			return null;
		}
		if ( isset( $jet['object_type'] ) && 'field' !== $jet['object_type'] ) {
			return null;
		}

		$type = isset( $jet['type'] ) ? (string) $jet['type'] : 'text';
		// Bloc « HTML » (texte affiché, sans valeur) et champs cachés (les
		// métas annexes d'une carte) : rien à éditer.
		if ( 'html' === $type || 'hidden' === $type ) {
			return null;
		}
		$field = array(
			'key'      => (string) $jet['name'],
			'name'     => (string) $jet['name'],
			'label'    => isset( $jet['title'] ) && '' !== $jet['title'] ? (string) $jet['title'] : (string) $jet['name'],
			'required' => ! empty( $jet['is_required'] ) && filter_var( $jet['is_required'], FILTER_VALIDATE_BOOLEAN ),
			'jet'      => $jet,
		);

		$multiple = ! empty( $jet['is_multiple'] ) && filter_var( $jet['is_multiple'], FILTER_VALIDATE_BOOLEAN );

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'number':
				$field['type'] = $type;
				if ( 'number' === $type ) {
					$field['step'] = isset( $jet['step_value'] ) && is_numeric( $jet['step_value'] ) && $jet['step_value'] > 0 ? $jet['step_value'] : 'any';
					foreach ( array( 'min_value' => 'min', 'max_value' => 'max' ) as $from => $to ) {
						if ( isset( $jet[ $from ] ) && is_numeric( $jet[ $from ] ) ) {
							$field[ $to ] = 0 + $jet[ $from ];
						}
					}
				}
				foreach ( array( 'max_length', 'maxlength' ) as $limit ) {
					if ( ! empty( $jet[ $limit ] ) ) {
						$field['maxlength'] = (int) $jet[ $limit ];
					}
				}
				break;

			case 'media':
				$field['type'] = self::images_only( $jet ) ? 'image' : 'file';
				break;

			case 'gallery':
				$field['type'] = 'gallery';
				break;

			case 'switcher':
				$field['type'] = 'true_false';
				break;

			case 'date':
				$field['type'] = 'date_picker';
				break;

			case 'datetime-local':
			case 'datetime':
				$field['type'] = 'date_time_picker';
				break;

			case 'time':
				$field['type'] = 'time_picker';
				break;

			case 'select':
			case 'radio':
				$field['type']     = 'select';
				$field['choices']  = $this->choices( $jet );
				$field['multiple'] = 'select' === $type && $multiple;
				break;

			case 'checkbox':
				$field['type']     = 'select';
				$field['choices']  = $this->choices( $jet );
				$field['multiple'] = true;
				break;

			case 'posts':
				$field['type']      = $multiple ? 'relationship' : 'post_object';
				$field['post_type'] = isset( $jet['search_post_type'] ) ? array_values( array_filter( (array) $jet['search_post_type'] ) ) : array();
				break;

			case 'repeater':
				$field['type']       = 'repeater';
				$field['sub_fields'] = array();
				foreach ( isset( $jet['repeater-fields'] ) && is_array( $jet['repeater-fields'] ) ? $jet['repeater-fields'] : array() as $sub ) {
					$sub_field = $this->map_definition( array_merge( array( 'object_type' => 'field' ), (array) $sub ) );
					if ( null !== $sub_field ) {
						$field['sub_fields'][] = $sub_field;
					}
				}
				break;

			case 'colorpicker':
				$field['type'] = 'color_picker';
				break;

			case 'iconpicker':
				// La classe de l'icône (« fas fa-star ») : un texte.
				$field['type'] = 'text';
				break;

			case 'map':
				$field['type']       = 'group';
				$field['sub_fields'] = 'location_address' === self::map_format( $jet )
					? array(
						array(
							'key'   => 'address',
							'name'  => 'address',
							'label' => __( 'Address', 'leiho' ),
							'type'  => 'text',
							'jet'   => array( 'type' => 'text' ),
						),
					)
					: array(
						array(
							'key'   => 'lat',
							'name'  => 'lat',
							'label' => __( 'Latitude', 'leiho' ),
							'type'  => 'number',
							'min'   => -90,
							'max'   => 90,
							'step'  => 'any',
							'jet'   => array( 'type' => 'number' ),
						),
						array(
							'key'   => 'lng',
							'name'  => 'lng',
							'label' => __( 'Longitude', 'leiho' ),
							'type'  => 'number',
							'min'   => -180,
							'max'   => 180,
							'step'  => 'any',
							'jet'   => array( 'type' => 'number' ),
						),
					);
				break;

			default:
				// Type inconnu (ajouté par une extension) : lu tel quel, non édité.
				$field['type'] = 'jetengine_' . $type;
		}

		return $field;
	}

	/**
	 * Un champ média qui n'accepte que des images ?
	 */
	private static function images_only( $jet ) {
		$mimes = isset( $jet['allowed_mime_types'] ) ? array_filter( (array) $jet['allowed_mime_types'] ) : array();
		if ( empty( $mimes ) ) {
			return true; // Réglage par défaut de JetEngine : tout fichier, mais c'est presque toujours une image.
		}
		foreach ( $mimes as $mime ) {
			if ( 0 !== strpos( (string) $mime, 'image/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Choix d'un select, radio ou checkbox, quelle que soit leur source
	 * (saisie, saisie en bloc, glossaire, taxonomie, requête) : JetEngine
	 * les résout lui-même.
	 *
	 * @param array $jet Champ JetEngine.
	 * @return array<string,string>
	 */
	private function choices( $jet ) {
		$list = array();
		if ( class_exists( 'Jet_Engine_CPT_Meta' ) ) {
			$meta = new Jet_Engine_CPT_Meta();
			if ( method_exists( $meta, 'filter_options_list' ) ) {
				$list = $meta->filter_options_list( array(), $jet );
			}
		}
		if ( empty( $list ) && isset( $jet['options'] ) ) {
			$list = $jet['options'];
		}

		$choices = array();
		foreach ( is_array( $list ) ? $list : array() as $key => $option ) {
			if ( is_array( $option ) && isset( $option['key'] ) ) {
				$choices[ (string) $option['key'] ] = isset( $option['value'] ) ? (string) $option['value'] : (string) $option['key'];
			} elseif ( is_scalar( $option ) ) {
				$choices[ (string) $key ] = (string) $option;
			}
		}
		return $choices;
	}

	/**
	 * Description : un repeater que JetEngine enregistre aussi en champs
	 * séparés reste en lecture seule (ces méta annexes ne seraient pas
	 * tenues à jour).
	 */
	protected function describe( $field ) {
		$entry = parent::describe( $field );
		$jet   = isset( $field['jet'] ) ? $field['jet'] : array();
		// Repeater « en champs séparés » : éditable si JetEngine fournit de
		// quoi réécrire ses copies (méthode de son propre formulaire).
		if ( self::saves_separate( $field ) && ! is_callable( array( 'Cherry_X_Post_Meta', '_save_repeater_separate_fields' ) ) ) {
			$entry['supported'] = false;
		}
		if ( 'number' === $field['type'] && $entry['supported'] ) {
			foreach ( array( 'min', 'max', 'step' ) as $prop ) {
				if ( isset( $field[ $prop ] ) ) {
					$entry[ $prop ] = $field[ $prop ];
				}
			}
		}
		return $entry;
	}

	/* ---------------------------------------------------------------------
	 * Lecture
	 * ------------------------------------------------------------------- */

	public function get_schema( $post_id ) {
		$fields = $this->fields( get_post_type( $post_id ) );
		if ( empty( $fields ) ) {
			return array();
		}

		$mapped = array();
		foreach ( $fields as $field ) {
			$entry           = $this->describe( $field );
			$entry['source'] = 'jetengine';
			if ( ! $entry['supported'] ) {
				$entry['edit_url'] = get_edit_post_link( $post_id, 'raw' );
				$mapped[]          = $entry;
				continue;
			}
			$entry           = array_merge( $entry, $this->read_value( $field, $this->to_acf( $field, get_post_meta( $post_id, $field['name'], true ) ) ) );
			$entry['source'] = 'jetengine';
			$mapped[]        = $entry;
		}

		return array(
			array(
				'key'    => 'jetengine',
				'title'  => 'JetEngine',
				'source' => 'jetengine',
				'fields' => $mapped,
			),
		);
	}

	public function get_content( $post_id ) {
		$out = array();
		foreach ( $this->fields( get_post_type( $post_id ) ) as $field ) {
			$out[ $field['name'] ] = $this->public_value( $field, $this->to_acf( $field, get_post_meta( $post_id, $field['name'], true ) ) );
		}
		return $out;
	}

	public function get_definitions( $post_type ) {
		$fields = array_values( $this->fields( $post_type ) );
		if ( empty( $fields ) ) {
			return array();
		}
		return array(
			array(
				'conditional' => false,
				'fields'      => $fields,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Écriture
	 * ------------------------------------------------------------------- */

	public function save_values( $post_id, $values ) {
		$saved  = array();
		$errors = array();
		$roots  = $this->fields( get_post_type( $post_id ) );

		$batches = array();
		foreach ( $values as $path => $value ) {
			$root = explode( '.', $path )[0];
			if ( ! isset( $roots[ $root ] ) ) {
				continue; // Pas un champ JetEngine de ce contenu.
			}
			$batches[ $root ][ $path ] = $value;
		}

		foreach ( $batches as $root => $paths ) {
			$field = $roots[ $root ];

			if ( ! $this->describe( $field )['supported'] ) {
				foreach ( array_keys( $paths ) as $path ) {
					$errors[ $path ] = 'unsupported_type';
				}
				continue;
			}

			$current = $this->to_acf( $field, get_post_meta( $post_id, $field['name'], true ) );
			$next    = $current;
			$failed  = false;
			$touched = array();

			foreach ( $paths as $path => $value ) {
				$error    = null;
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

			$stored = $this->from_acf( $field, $next );
			// update_post_meta() renvoie false quand rien ne change : on relit.
			$ok = update_post_meta( $post_id, $field['name'], wp_slash( $stored ) );
			if ( false === $ok && get_post_meta( $post_id, $field['name'], true ) != $stored ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Méta relue en chaînes.
				foreach ( $touched as $path ) {
					$errors[ $path ] = 'save_failed';
				}
				continue;
			}

			/**
			 * Après l'écriture d'un champ JetEngine par Leiho (même rôle que le
			 * hook de JetEngine à l'enregistrement d'un champ).
			 *
			 * @param int    $post_id Contenu.
			 * @param mixed  $stored  Valeur enregistrée, au format JetEngine.
			 * @param string $name    Nom du champ.
			 * @param array  $jet     Définition JetEngine.
			 */
			$this->save_companions( $post_id, $field, $stored, $next );

			do_action( 'leiho_jetengine_field_saved', $post_id, $stored, $field['name'], $field['jet'] );

			$saved = array_merge( $saved, $touched );
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}

	/* ---------------------------------------------------------------------
	 * Conversions JetEngine ↔ format ACF du moteur commun
	 * ------------------------------------------------------------------- */

	/**
	 * Valeur stockée par JetEngine → valeur brute ACF (format=false).
	 *
	 * @param array $field Champ (format ACF, avec 'jet').
	 * @param mixed $raw   Méta.
	 * @return mixed
	 */
	public function to_acf( $field, $raw ) {
		$jet    = isset( $field['jet'] ) ? $field['jet'] : array();
		$format = isset( $jet['value_format'] ) ? $jet['value_format'] : 'id';

		switch ( $field['type'] ) {
			case 'repeater':
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $raw_row ) {
					if ( ! is_array( $raw_row ) ) {
						continue;
					}
					$row = array();
					foreach ( $field['sub_fields'] as $sub ) {
						$row[ $sub['name'] ] = $this->to_acf( $sub, isset( $raw_row[ $sub['name'] ] ) ? $raw_row[ $sub['name'] ] : null );
					}
					$rows[] = $row;
				}
				return $rows;

			case 'image':
			case 'file':
				return self::attachment_id( $raw, $format );

			case 'gallery':
				$items = is_array( $raw ) ? $raw : ( '' === (string) $raw ? array() : explode( ',', (string) $raw ) );
				$ids   = array();
				foreach ( $items as $item ) {
					$id = self::attachment_id( $item, is_array( $item ) ? 'both' : ( is_numeric( trim( (string) $item ) ) ? 'id' : 'url' ) );
					if ( $id ) {
						$ids[] = $id;
					}
				}
				return $ids;

			case 'true_false':
				return true === $raw || 'true' === $raw || '1' === (string) $raw ? 1 : 0;

			case 'group':
				return self::is_map( $jet ) ? self::map_to_acf( $jet, $raw ) : ( is_array( $raw ) ? $raw : array() );

			case 'date_picker':
				$time = self::to_time( $raw, $jet );
				return $time ? gmdate( 'Ymd', $time ) : '';

			case 'date_time_picker':
				$time = self::to_time( $raw, $jet );
				return $time ? gmdate( 'Y-m-d H:i:s', $time ) : '';

			case 'time_picker':
				$time = '' === (string) $raw ? false : strtotime( '1970-01-01 ' . $raw . ' UTC' );
				return $time ? gmdate( 'H:i:s', $time ) : '';

			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					if ( ! is_array( $raw ) ) {
						return '' === (string) $raw ? array() : array( (string) $raw );
					}
					// Checkbox sans « is_array » : value => 'true' / 'false'.
					$list = array();
					foreach ( $raw as $key => $value ) {
						if ( is_int( $key ) ) {
							$list[] = (string) $value;
						} elseif ( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ) {
							$list[] = (string) $key;
						}
					}
					return $list;
				}
				return is_array( $raw ) ? '' : (string) $raw;

			case 'relationship':
				return array_values( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : ( '' === (string) $raw ? array() : explode( ',', (string) $raw ) ) ) ) );

			case 'post_object':
				return absint( is_array( $raw ) ? reset( $raw ) : $raw );

			default:
				return $raw;
		}
	}

	/**
	 * Valeur brute ACF (sanitizée par le moteur) → format que JetEngine stocke.
	 *
	 * @param array $field Champ (format ACF, avec 'jet').
	 * @param mixed $value Valeur.
	 * @return mixed
	 */
	public function from_acf( $field, $value ) {
		$jet    = isset( $field['jet'] ) ? $field['jet'] : array();
		$format = isset( $jet['value_format'] ) ? $jet['value_format'] : 'id';

		switch ( $field['type'] ) {
			case 'repeater':
				$rows  = array();
				$index = 0;
				foreach ( is_array( $value ) ? array_values( $value ) : array() as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$out = array();
					foreach ( $field['sub_fields'] as $sub ) {
						$cell                = array_key_exists( $sub['key'], $row ) ? $row[ $sub['key'] ] : ( isset( $row[ $sub['name'] ] ) ? $row[ $sub['name'] ] : null );
						$out[ $sub['name'] ] = $this->from_acf( $sub, $cell );
						// Carte dans un repeater : ses champs cachés vont dans la ligne.
						if ( isset( $sub['jet'] ) && self::is_map( $sub['jet'] ) ) {
							foreach ( self::map_companions( $sub['jet'], $out[ $sub['name'] ], $cell ) as $suffix => $companion ) {
								$out[ $sub['name'] . $suffix ] = $companion;
							}
						}
					}
					$rows[ 'item-' . $index ] = $out;
					++$index;
				}
				return $rows;

			case 'image':
			case 'file':
				return self::attachment_value( absint( $value ), $format );

			case 'gallery':
				$ids = array_values( array_filter( array_map( 'absint', (array) $value ) ) );
				if ( 'both' === $format ) {
					return array_values( array_filter( array_map( static function ( $id ) {
						return self::attachment_value( $id, 'both' );
					}, $ids ) ) );
				}
				return implode( ',', array_filter( array_map( static function ( $id ) use ( $format ) {
					return self::attachment_value( $id, $format );
				}, $ids ) ) );

			case 'true_false':
				return $value ? 'true' : 'false';

			case 'group':
				return self::is_map( $jet ) ? self::map_from_acf( $jet, $value ) : $value;

			case 'date_picker':
			case 'date_time_picker':
				if ( '' === (string) $value ) {
					return '';
				}
				$time = 'date_picker' === $field['type']
					? strtotime( substr( (string) $value, 0, 4 ) . '-' . substr( (string) $value, 4, 2 ) . '-' . substr( (string) $value, 6, 2 ) . ' 00:00:00 UTC' )
					: strtotime( $value . ' UTC' );
				if ( ! $time ) {
					return '';
				}
				if ( ! empty( $jet['is_timestamp'] ) && filter_var( $jet['is_timestamp'], FILTER_VALIDATE_BOOLEAN ) ) {
					return (string) $time;
				}
				return gmdate( 'date_picker' === $field['type'] ? 'Y-m-d' : 'Y-m-d\TH:i', $time );

			case 'time_picker':
				return '' === (string) $value ? '' : substr( (string) $value, 0, 5 );

			case 'select':
				if ( empty( $field['multiple'] ) ) {
					return (string) $value;
				}
				$list = array_values( array_map( 'strval', (array) $value ) );
				// Checkbox sans « is_array » : chaque choix => 'true' / 'false',
				// comme le formulaire de JetEngine.
				if ( isset( $jet['type'] ) && 'checkbox' === $jet['type'] && empty( $jet['is_array'] ) ) {
					$out = array();
					foreach ( array_keys( $field['choices'] ) as $key ) {
						$out[ (string) $key ] = in_array( (string) $key, $list, true ) ? 'true' : 'false';
					}
					return $out;
				}
				return $list;

			case 'relationship':
				return array_map( 'strval', array_values( array_filter( array_map( 'absint', (array) $value ) ) ) );

			case 'post_object':
				return $value ? (string) absint( $value ) : '';

			case 'number':
				return '' === $value || null === $value ? '' : (string) ( 0 + $value );

			default:
				return $value;
		}
	}

	/**
	 * Métas annexes que le formulaire de JetEngine écrit avec certains
	 * champs : empreinte et coordonnées d'une carte, copies d'un repeater
	 * « en champs séparés ».
	 *
	 * @param int   $post_id Contenu.
	 * @param array $field   Champ (format ACF, avec 'jet').
	 * @param mixed $stored  Valeur enregistrée, au format JetEngine.
	 * @param mixed $value   Même valeur, au format ACF.
	 * @return void
	 */
	private function save_companions( $post_id, $field, $stored, $value ) {
		$jet = $field['jet'];

		if ( self::is_map( $jet ) ) {
			$prefix = apply_filters( 'jet-engine/maps-listings/map-field-prefix', md5( $field['name'] ), $field['name'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtre de JetEngine.
			foreach ( self::map_companions( $jet, $stored, $value ) as $suffix => $companion ) {
				if ( '' === $companion ) {
					delete_post_meta( $post_id, $prefix . $suffix );
				} else {
					update_post_meta( $post_id, $prefix . $suffix, $companion );
				}
			}
		}

		if ( self::saves_separate( $field ) && is_callable( array( 'Cherry_X_Post_Meta', '_save_repeater_separate_fields' ) ) ) {
			Cherry_X_Post_Meta::_save_repeater_separate_fields( $post_id, $field['name'], $stored, $jet );
		}
	}

	/**
	 * Repeater enregistré aussi en champs séparés ?
	 */
	private static function saves_separate( $field ) {
		return 'repeater' === $field['type']
			&& ! empty( $field['jet']['repeater_save_separate'] )
			&& filter_var( $field['jet']['repeater_save_separate'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Champ carte (module Maps de JetEngine) ?
	 */
	private static function is_map( $jet ) {
		return isset( $jet['type'] ) && 'map' === $jet['type'];
	}

	/**
	 * Format d'une carte : « lat,lng », JSON { lat, lng } ou adresse.
	 */
	private static function map_format( $jet ) {
		$format = isset( $jet['map_value_format'] ) ? (string) $jet['map_value_format'] : '';
		return in_array( $format, array( 'location_array', 'location_address' ), true ) ? $format : 'location_string';
	}

	/**
	 * Valeur d'une carte → groupe { lat, lng } ou { address }.
	 */
	private static function map_to_acf( $jet, $raw ) {
		if ( 'location_address' === self::map_format( $jet ) ) {
			return array( 'address' => is_scalar( $raw ) ? (string) $raw : '' );
		}
		$point = is_array( $raw ) ? $raw : null;
		if ( null === $point && is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
			if ( is_array( $decoded ) ) {
				$point = $decoded;
			} elseif ( preg_match( '/^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*$/', $raw, $m ) ) {
				$point = array( 'lat' => $m[1], 'lng' => $m[2] );
			}
		}
		$lat = isset( $point['lat'] ) && is_numeric( $point['lat'] ) ? (float) $point['lat'] : null;
		$lng = isset( $point['lng'] ) && is_numeric( $point['lng'] ) ? (float) $point['lng'] : null;
		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	/**
	 * Groupe { lat, lng } ou { address } → valeur de la carte, comme l'écrit
	 * son champ dans l'administration ('' sans position complète).
	 */
	private static function map_from_acf( $jet, $value ) {
		$value  = is_array( $value ) ? $value : array();
		$format = self::map_format( $jet );
		if ( 'location_address' === $format ) {
			return isset( $value['address'] ) && is_scalar( $value['address'] ) ? trim( (string) $value['address'] ) : '';
		}
		$lat = isset( $value['lat'] ) && is_numeric( $value['lat'] ) ? 0 + $value['lat'] : null;
		$lng = isset( $value['lng'] ) && is_numeric( $value['lng'] ) ? 0 + $value['lng'] : null;
		if ( null === $lat || null === $lng ) {
			return '';
		}
		if ( 'location_array' === $format ) {
			return wp_json_encode(
				array(
					'lat' => $lat,
					'lng' => $lng,
				)
			);
		}
		return $lat . ',' . $lng;
	}

	/**
	 * Champs cachés d'une carte : empreinte de la valeur (md5, comme la
	 * route « get-map-location-hash » de JetEngine) et coordonnées. Une
	 * adresse n'a pas de coordonnées connues : JetEngine la géocode à
	 * l'affichage, l'empreinte ayant changé.
	 *
	 * @param array  $jet      Définition de la carte.
	 * @param string $location Valeur enregistrée.
	 * @param mixed  $value    Groupe au format ACF.
	 * @return array suffixe => valeur ('' : à effacer).
	 */
	private static function map_companions( $jet, $location, $value ) {
		$location = is_scalar( $location ) ? (string) $location : '';
		$coords   = 'location_address' !== self::map_format( $jet ) && '' !== $location && is_array( $value );
		return array(
			'_hash' => '' === $location ? '' : md5( $location ),
			'_lat'  => $coords ? (string) ( 0 + $value['lat'] ) : '',
			'_lng'  => $coords ? (string) ( 0 + $value['lng'] ) : '',
		);
	}

	/**
	 * ID d'un média stocké en ID, en URL ou en { id, url }.
	 */
	private static function attachment_id( $raw, $format ) {
		if ( is_array( $raw ) ) {
			return isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( is_numeric( $raw ) ) {
			return absint( $raw );
		}
		return absint( attachment_url_to_postid( $raw ) );
	}

	/**
	 * Média au format de stockage JetEngine.
	 */
	private static function attachment_value( $id, $format ) {
		if ( ! $id ) {
			return '';
		}
		$url = (string) wp_get_attachment_url( $id );
		switch ( $format ) {
			case 'url':
				return $url;
			case 'both':
				return array(
					'id'  => (string) $id,
					'url' => $url,
				);
			default:
				return (string) $id;
		}
	}

	/**
	 * Date stockée (timestamp ou chaîne) → timestamp.
	 */
	private static function to_time( $raw, $jet ) {
		if ( null === $raw || '' === (string) $raw ) {
			return 0;
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		$time = strtotime( str_replace( 'T', ' ', (string) $raw ) . ' UTC' );
		return $time ? $time : 0;
	}
}
