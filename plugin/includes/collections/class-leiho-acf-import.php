<?php
defined( 'ABSPATH' ) || exit;

/**
 * Import ACF → Leiho Collections, type de contenu par type de contenu.
 *
 * 1. Aperçu : groupes de champs ACF rattachés au type, ce que devient chaque
 *    champ dans Leiho, ce qui ne suit pas (logique conditionnelle, options
 *    ACF Pro, types sans équivalent, règles d'emplacement…), compteurs.
 * 2. Import : la collection est créée (« externe » : le type reste déclaré
 *    par le thème, une extension ou ACF, Leiho n'en possède que les champs),
 *    puis chaque entrée est copiée par lots, par la même validation que
 *    l'éditeur (Leiho_Collections::save_entry). Un échec n'arrête pas l'import :
 *    il est listé. Chaque entrée est vérifiée : la valeur servie par Leiho est
 *    comparée à celle que servait ACF.
 * 3. Bascule : l'import terminé sans échec ni écart, le type est servi par
 *    Leiho (option leiho_acf_import, post_type → état « done ») ; les groupes
 *    ACF restent en place mais ne s'appliquent plus à ce type. Avec un échec
 *    ou un écart, l'import s'arrête en « review » : ACF sert toujours le type,
 *    le rapport attend une décision (accept() : bascule quand même ;
 *    rollback() : retour à ACF, puis nouvel import une fois les valeurs
 *    corrigées).
 * 4. Retour : le type est rendu à ACF (valeurs d'origine, intactes) et la
 *    table Leiho supprimée. Ce qui a été modifié dans Leiho depuis l'import
 *    n'est pas réécrit dans ACF.
 *
 * Rien n'est jamais supprimé côté ACF : ni postmeta, ni groupe, ni type.
 */
class Leiho_ACF_Import {

	const OPTION = 'leiho_acf_import';

	/** Entrées traitées par requête. */
	const BATCH = 25;

	/** Durée maximale d'un lot, en secondes. */
	const TIME_BUDGET = 12;

	/** Échecs et écarts gardés dans le rapport (les compteurs restent exacts). */
	const MAX_REPORTED = 200;

	/** Entrées lues par l'aperçu pour relever les valeurs hors limites. */
	const SCAN_LIMIT = 1000;

	/** États d'un import : en cours, à examiner (ACF sert encore), basculé. */
	const STATUS_RUNNING = 'running';
	const STATUS_REVIEW  = 'review';
	const STATUS_DONE    = 'done';

	/** Types ACF repris tels quels ou presque : type ACF → type Leiho. */
	const TYPE_MAP = array(
		'text'             => 'text',
		'textarea'         => 'text',
		'wysiwyg'          => 'richtext',
		'number'           => 'number',
		'range'            => 'number',
		'email'            => 'email',
		'url'              => 'url',
		'oembed'           => 'url',
		'image'            => 'image',
		'file'             => 'file',
		'gallery'          => 'gallery',
		'select'           => 'select',
		'checkbox'         => 'select',
		'radio'            => 'select',
		'button_group'     => 'select',
		'true_false'       => 'boolean',
		'link'             => 'link',
		'post_object'      => 'reference',
		'relationship'     => 'reference',
		'date_picker'      => 'date',
		'date_time_picker' => 'datetime',
		'time_picker'      => 'text',
		'color_picker'     => 'color',
		'repeater'         => 'list',
		'group'            => 'group',
		'flexible_content' => 'blocks',
	);

	/** Post type en cours d'écriture par l'import (l'adapter natif le sert). */
	private static $importing = '';

	/** @var array|null Enregistrements mémorisés pour la requête. */
	private static $records = null;

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Après ACF (acf/init, priorité 5 d'init) : n'enregistre un type repris
		// d'ACF que si ACF ne l'a pas fait (désactivé).
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 6 );
		// Écran d'édition classique : les groupes ACF d'un type importé n'y
		// apparaissent plus (leurs valeurs ne seraient plus servies).
		add_filter( 'acf/location/rule_match', array( __CLASS__, 'rule_match' ), 99, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'leiho acf-import', 'Leiho_ACF_Import_Command' );
		}
	}

	/* ---------------------------------------------------------------------
	 * État
	 * ------------------------------------------------------------------- */

	/**
	 * Imports enregistrés : post type → enregistrement.
	 *
	 * @return array<string,array>
	 */
	public static function records() {
		if ( null === self::$records ) {
			$saved         = get_option( self::OPTION, array() );
			self::$records = is_array( $saved ) ? $saved : array();
		}
		return self::$records;
	}

	/**
	 * @param string $post_type Type.
	 * @return array|null
	 */
	public static function record( $post_type ) {
		$records = self::records();
		return isset( $records[ $post_type ] ) && is_array( $records[ $post_type ] ) ? $records[ $post_type ] : null;
	}

	private static function save_record( $post_type, $record ) {
		$records = self::records();
		if ( null === $record ) {
			unset( $records[ $post_type ] );
		} else {
			$records[ $post_type ] = $record;
		}
		self::$records = $records;
		update_option( self::OPTION, $records, true );
	}

	/**
	 * Vide le cache de la requête (tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$records   = null;
		self::$importing = '';
	}

	/**
	 * L'import a-t-il fini de copier les entrées (basculé ou à examiner) ?
	 *
	 * @param array $record Enregistrement.
	 * @return bool
	 */
	public static function finished( $record ) {
		return is_array( $record ) && isset( $record['status'] ) && in_array( $record['status'], array( self::STATUS_DONE, self::STATUS_REVIEW ), true );
	}

	/**
	 * Moteur qui sert les champs d'un type : « leiho » une fois l'import
	 * basculé (« done »), « acf » pendant l'import et tant qu'un import en
	 * échec ou en écart attend d'être examiné (« review »), null si le type
	 * n'a jamais été importé.
	 *
	 * @param string $post_type Type.
	 * @return string|null
	 */
	public static function engine( $post_type ) {
		$record = self::record( (string) $post_type );
		if ( ! $record ) {
			return null;
		}
		// Collection retirée du schéma depuis (import d'un leiho.schema.json…) :
		// ACF reprend la main plutôt que de laisser le type sans champs.
		return self::STATUS_DONE === $record['status'] && class_exists( 'Leiho_Collections' ) && Leiho_Collections::get( (string) $post_type ) ? 'leiho' : 'acf';
	}

	/**
	 * L'adapter natif sert-il ce type ? Oui, sauf pendant son import (où
	 * seul l'import lui-même écrit et relit les valeurs Leiho).
	 *
	 * @param string $post_type Type.
	 * @return bool
	 */
	public static function native_serves( $post_type ) {
		return 'acf' !== self::engine( $post_type ) || self::$importing === $post_type;
	}

	/**
	 * Résumé pour l'interface (réglages d'une collection importée).
	 *
	 * @param string $post_type Type.
	 * @return array|null
	 */
	public static function summary( $post_type ) {
		$record = self::record( $post_type );
		if ( ! $record ) {
			return null;
		}
		return array(
			'status'       => $record['status'],
			'origin'       => $record['origin'],
			'started'      => gmdate( 'c', (int) $record['started'] ),
			'finished'     => $record['finished'] ? gmdate( 'c', (int) $record['finished'] ) : null,
			'total'        => (int) $record['total'],
			'done'         => (int) $record['done'],
			'imported'     => (int) $record['imported'],
			'failed'       => (int) $record['failed'],
			'differences'  => (int) $record['different'],
			'needs_review' => self::STATUS_REVIEW === $record['status'],
			'can_accept'   => self::STATUS_REVIEW === $record['status'],
			'can_rollback' => self::acf_available(),
		);
	}

	/**
	 * ACF (ou Secure Custom Fields) est-il actif ?
	 *
	 * @return bool
	 */
	public static function acf_available() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'get_field' );
	}

	/* ---------------------------------------------------------------------
	 * WordPress
	 * ------------------------------------------------------------------- */

	/**
	 * Un type déclaré par ACF (interface « Post Types ») et importé reste
	 * déclaré si ACF est désactivé : Leiho reprend son enregistrement, avec les
	 * arguments relevés au moment de l'import.
	 *
	 * @return void
	 */
	public static function register_post_types() {
		foreach ( self::records() as $post_type => $record ) {
			if ( 'acf' !== $record['origin'] || empty( $record['args'] ) || ! is_array( $record['args'] ) || post_type_exists( $post_type ) ) {
				continue;
			}
			register_post_type( $post_type, $record['args'] );
		}
	}

	/**
	 * Règles d'emplacement ACF : un type importé n'a plus de groupe ACF.
	 *
	 * @param bool  $result Résultat d'ACF.
	 * @param array $rule   Règle.
	 * @param array $screen Écran (post_id, post_type…).
	 * @param array $group  Groupe.
	 * @return bool
	 */
	public static function rule_match( $result, $rule = array(), $screen = array(), $group = array() ) {
		if ( ! $result || ! is_array( $screen ) || ! self::records() ) {
			return $result;
		}
		$type = '';
		if ( ! empty( $screen['post_type'] ) && is_string( $screen['post_type'] ) ) {
			$type = $screen['post_type'];
		} elseif ( ! empty( $screen['post_id'] ) && is_numeric( $screen['post_id'] ) ) {
			$type = (string) get_post_type( (int) $screen['post_id'] );
		}
		return '' !== $type && 'leiho' === self::engine( $type ) ? false : $result;
	}

	/* ---------------------------------------------------------------------
	 * Groupes et origine d'un type
	 * ------------------------------------------------------------------- */

	/**
	 * Groupes ACF actifs rattachés au type, dans l'ordre d'ACF (celui du
	 * contenu servi). Un groupe rattaché à des entrées précises du type
	 * (« post == 104 ») en fait partie : ACF le sert sur ces entrées.
	 *
	 * @param string $post_type Type.
	 * @return array<int,array{key:string,title:string,conditional:bool,entries:int[],location:array,fields:array}>
	 */
	public static function groups_for( $post_type ) {
		if ( ! self::acf_available() ) {
			return array();
		}
		$out = array();
		foreach ( acf_get_field_groups() as $group ) {
			if ( isset( $group['active'] ) && ! $group['active'] ) {
				continue;
			}
			$location = isset( $group['location'] ) ? $group['location'] : array();
			$target   = Leiho_ACF_Adapter::targets( $location, $post_type );
			$entries  = self::entry_ids( $location, $post_type );
			if ( null === $target && ! $entries ) {
				continue;
			}
			$fields = acf_get_fields( $group );
			$out[]  = array(
				'key'         => (string) $group['key'],
				'title'       => (string) $group['title'],
				// Rattaché à certaines entrées seulement : sous condition.
				'conditional' => null === $target ? true : (bool) $target,
				'entries'     => $entries,
				'location'    => $location,
				'fields'      => is_array( $fields ) ? $fields : array(),
			);
		}
		return $out;
	}

	/**
	 * Entrées du type auxquelles une branche des règles ACF rattache le
	 * groupe nommément (« post == 104 », « page == 12 »). Une branche est un
	 * ET : elle ne désigne une entrée que si aucune de ses règles ne l'exclut
	 * (« post_type == » un autre type, écran étranger au contenu, deux entrées
	 * différentes). « post != 104 » ne rattache rien : il restreint seulement.
	 *
	 * @param array  $location  Règles ACF.
	 * @param string $post_type Type.
	 * @return int[]
	 */
	public static function entry_ids( $location, $post_type ) {
		$content = array( 'post_type', 'post', 'page', 'post_template', 'post_status', 'page_template', 'page_type', 'page_parent', 'post_category', 'post_format', 'post_taxonomy' );
		$out     = array();
		foreach ( (array) $location as $branch ) {
			$id    = 0;
			$valid = true;
			foreach ( (array) $branch as $rule ) {
				$param    = isset( $rule['param'] ) ? (string) $rule['param'] : '';
				$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
				$value    = isset( $rule['value'] ) ? $rule['value'] : '';
				if ( ! in_array( $param, $content, true ) ) {
					$valid = false; // Options, utilisateur, taxonomie… : pas une entrée.
					break;
				}
				if ( 'post_type' === $param ) {
					if ( ( '==' === $operator && $value !== $post_type ) || ( '!=' === $operator && $value === $post_type ) ) {
						$valid = false;
						break;
					}
				} elseif ( ( 'post' === $param || 'page' === $param ) && '==' === $operator && is_numeric( $value ) ) {
					if ( $id && (int) $value !== $id ) {
						$valid = false; // Deux entrées dans un même ET : jamais vrai.
						break;
					}
					$id = (int) $value;
				}
			}
			if ( $valid && $id > 0 && $post_type === get_post_type( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Qui déclare le type : « acf » (interface Post Types d'ACF), « builtin »
	 * (WordPress), « external » (thème, extension, mu-plugin), « leiho »
	 * (collection Leiho), « missing » (inconnu).
	 *
	 * @param string $post_type Type.
	 * @return string
	 */
	public static function origin( $post_type ) {
		$record = self::record( $post_type );
		if ( $record ) {
			return $record['origin'];
		}
		if ( class_exists( 'Leiho_Collections' ) && Leiho_Collections::get( $post_type ) ) {
			return 'leiho';
		}
		$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
		if ( in_array( $post_type, array( 'post', 'page', 'attachment' ), true ) || ( $object && ! empty( $object->_builtin ) ) ) {
			return 'builtin';
		}
		if ( self::acf_post_type( $post_type ) ) {
			return 'acf';
		}
		return $object ? 'external' : 'missing';
	}

	/**
	 * Le type tel que déclaré dans l'interface Post Types d'ACF.
	 *
	 * @param string $post_type Type.
	 * @return array|null
	 */
	private static function acf_post_type( $post_type ) {
		if ( ! function_exists( 'acf_get_acf_post_types' ) ) {
			return null;
		}
		foreach ( (array) acf_get_acf_post_types( array( 'active' => true ) ) as $item ) {
			if ( is_array( $item ) && isset( $item['post_type'] ) && $item['post_type'] === $post_type ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Arguments de register_post_type() qu'ACF utilise pour ce type, sans ce
	 * qui ne se stocke pas en option (fonctions anonymes, objets).
	 *
	 * @param string $post_type Type.
	 * @return array
	 */
	private static function acf_post_type_args( $post_type ) {
		$item = self::acf_post_type( $post_type );
		if ( ! $item || ! function_exists( 'acf_get_internal_post_type_instance' ) ) {
			return array();
		}
		$instance = acf_get_internal_post_type_instance( 'acf-post-type' );
		if ( ! $instance || ! method_exists( $instance, 'get_post_type_args' ) ) {
			return array();
		}
		$args = $instance->get_post_type_args( $item );
		unset( $args['register_meta_box_cb'] );
		return self::storable( is_array( $args ) ? $args : array() );
	}

	private static function storable( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_array( $v ) || is_scalar( $v ) || null === $v ) {
					$out[ $k ] = self::storable( $v );
				}
			}
			return $out;
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Correspondance des champs (pure : testable sans WordPress)
	 * ------------------------------------------------------------------- */

	/**
	 * Collection Leiho construite à partir des groupes ACF d'un type.
	 *
	 * @param string $post_type Type.
	 * @param array  $groups    Groupes (groups_for()).
	 * @param array  $options   label, singular_label, targets (types visés par une relation ACF sans filtre).
	 * @return array{collection:array,groups:array,lost:array,counts:array}
	 */
	public static function build( $post_type, $groups, $options = array() ) {
		$options = array_merge(
			array(
				'label'          => $post_type,
				'singular_label' => '',
				'targets'        => array(),
			),
			$options
		);

		$ctx = array(
			'lost'    => array(),
			'targets' => $options['targets'],
			'fields'  => 0,
		);

		// Champs racine dans l'ordre des groupes. Même nom dans deux groupes :
		// ACF sert la dernière définition à la place de la première — quand les
		// deux groupes s'appliquent à toutes les entrées. Si l'un ne vaut que
		// pour certaines entrées (règle d'emplacement, entrée nommée), chaque
		// entrée a sa propre définition : deux formes différentes ne tiennent
		// pas dans un seul champ Leiho (conflit, import refusé).
		$roots      = array();
		$origins    = array(); // nom → { title, conditional, shape } de la définition retenue.
		$conflicts  = array();
		$report     = array();
		$root_taken = array();
		foreach ( $groups as $group ) {
			$entries = isset( $group['entries'] ) ? array_map( 'intval', (array) $group['entries'] ) : array();
			if ( $entries ) {
				self::lose( $ctx, 'entry_group', $group['title'] . ' (#' . implode( ', #', $entries ) . ')' );
			} elseif ( ! empty( $group['conditional'] ) ) {
				self::lose( $ctx, 'location', $group['title'] );
			}
			$nodes = array();
			foreach ( $group['fields'] as $acf ) {
				$mapped = self::map_field( $acf, $ctx, $root_taken, isset( $acf['name'] ) && isset( $roots[ $acf['name'] ] ) ? $roots[ $acf['name'] ]['key'] : '' );
				$nodes[] = $mapped['report'];
				if ( null === $mapped['field'] ) {
					continue;
				}
				$name  = $mapped['field']['name'];
				$shape = self::shape( $mapped['field'] );
				if ( isset( $roots[ $name ] ) ) {
					$previous = $origins[ $name ];
					if ( $previous['shape'] !== $shape && ( $previous['conditional'] || ! empty( $group['conditional'] ) ) ) {
						self::lose( $ctx, 'conflict', $name . ' (' . $previous['title'] . ': ' . $previous['type'] . ', ' . $group['title'] . ': ' . $mapped['field']['type'] . ')' );
						if ( ! in_array( $name, $conflicts, true ) ) {
							$conflicts[] = $name;
						}
					} else {
						self::lose( $ctx, 'duplicate', $name );
					}
				}
				$roots[ $name ]   = $mapped['field'];
				$origins[ $name ] = array(
					'title'       => (string) $group['title'],
					'conditional' => ! empty( $group['conditional'] ),
					'shape'       => $shape,
					'type'        => $mapped['field']['type'],
				);
			}
			$report[] = array(
				'key'         => $group['key'],
				'title'       => $group['title'],
				'conditional' => ! empty( $group['conditional'] ),
				'entries'     => $entries,
				'fields'      => $nodes,
			);
		}

		$collection = array(
			'id'       => $post_type,
			'label'    => (string) $options['label'],
			'external' => true,
			'source'   => 'acf',
			'fields'   => array_values( $roots ),
		);
		if ( '' !== (string) $options['singular_label'] && $options['singular_label'] !== $options['label'] ) {
			$collection['singular_label'] = (string) $options['singular_label'];
		}

		return array(
			'collection' => $collection,
			'groups'     => $report,
			'lost'       => $ctx['lost'],
			'conflicts'  => $conflicts,
			'counts'     => array(
				'fields' => $ctx['fields'],
			),
		);
	}

	/**
	 * Forme d'un champ Leiho : ce qui décide du JSON servi (type, multiple,
	 * sous-champs, blocs), sans libellés ni limites.
	 *
	 * @param array $field Champ Leiho (build()).
	 * @return string
	 */
	public static function shape( $field ) {
		return (string) json_encode( self::shape_of( $field ) );
	}

	private static function shape_of( $field ) {
		$out = array( 't' => isset( $field['type'] ) ? $field['type'] : '' );
		if ( ! empty( $field['multiple'] ) ) {
			$out['m'] = 1;
		}
		if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
			$out['f'] = array();
			foreach ( $field['fields'] as $sub ) {
				$out['f'][ $sub['name'] ] = self::shape_of( $sub );
			}
			ksort( $out['f'] );
		}
		if ( isset( $field['blocks'] ) && is_array( $field['blocks'] ) ) {
			$out['b'] = array();
			foreach ( $field['blocks'] as $block ) {
				$subs = array();
				foreach ( $block['fields'] as $sub ) {
					$subs[ $sub['name'] ] = self::shape_of( $sub );
				}
				ksort( $subs );
				$out['b'][ $block['name'] ] = $subs;
			}
			ksort( $out['b'] );
		}
		return $out;
	}

	/**
	 * Un champ ACF → champ Leiho (ou null) + sa ligne de rapport.
	 *
	 * @param array  $acf   Champ ACF.
	 * @param array  $ctx   Contexte (pertes, cibles par défaut, compteur).
	 * @param array  $taken Clés déjà prises parmi les frères (entrée/sortie).
	 * @param string $key   Clé à réutiliser (même nom qu'un champ précédent).
	 * @return array{field:array|null,report:array}
	 */
	public static function map_field( $acf, &$ctx, &$taken, $key = '' ) {
		$type  = isset( $acf['type'] ) ? (string) $acf['type'] : '';
		$name  = isset( $acf['name'] ) ? (string) $acf['name'] : '';
		$label = isset( $acf['label'] ) && '' !== (string) $acf['label'] ? (string) $acf['label'] : $name;

		$report = array(
			'name'   => $name,
			'label'  => $label,
			'acf'    => $type,
			'leiho'   => null,
			'status' => 'ok',
			'notes'  => array(),
		);

		if ( in_array( $type, Leiho_Field_Adapter::LAYOUT_TYPES, true ) ) {
			$report['status'] = 'ignored';
			$report['notes'][] = 'layout';
			return array(
				'field'  => null,
				'report' => $report,
			);
		}
		if ( '' === $name || ! preg_match( Leiho_Collections_Schema::ACF_NAME_PATTERN, $name ) ) {
			$report['status']  = 'dropped';
			$report['notes'][] = 'name';
			self::lose( $ctx, 'name', '' === $name ? $label : $name );
			return array(
				'field'  => null,
				'report' => $report,
			);
		}
		// Clone « seamless » : ACF le remplace déjà par les champs clonés. Clone
		// affiché en groupe : mêmes valeurs et même JSON qu'un groupe.
		if ( 'clone' === $type && ! empty( $acf['sub_fields'] ) ) {
			$type = 'group';
			$report['notes'][] = 'clone_group';
		} elseif ( 'clone' === $type ) {
			$report['status']  = 'dropped';
			$report['notes'][] = 'clone';
			self::lose( $ctx, 'clone', $name );
			return array(
				'field'  => null,
				'report' => $report,
			);
		}
		if ( ! isset( self::TYPE_MAP[ $type ] ) ) {
			$report['status']  = 'dropped';
			$report['notes'][] = 'unsupported';
			self::lose( $ctx, 'unsupported', $name . ' (' . $type . ')' );
			return array(
				'field'  => null,
				'report' => $report,
			);
		}

		$leiho = self::TYPE_MAP[ $type ];
		$field = array(
			'key'      => '' !== $key ? $key : self::key_for( $name, $taken ),
			'name'     => $name,
			'label'    => $label,
			'type'     => $leiho,
			'required' => ! empty( $acf['required'] ),
		);
		if ( ! empty( $acf['instructions'] ) && is_string( $acf['instructions'] ) ) {
			$field['help'] = $acf['instructions'];
		}
		$report['leiho'] = $leiho;

		// Options ACF sans équivalent : signalées, jamais bloquantes.
		if ( ! empty( $acf['conditional_logic'] ) ) {
			$report['notes'][] = 'conditional';
			self::lose( $ctx, 'conditional', $name );
		}
		if ( isset( $acf['default_value'] ) && ! in_array( $acf['default_value'], array( '', array(), false, 0, '0' ), true ) ) {
			$report['notes'][] = 'default';
			self::lose( $ctx, 'default', $name );
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
				$field['max_length'] = ! empty( $acf['maxlength'] ) ? (int) $acf['maxlength'] : 0;
				if ( 'textarea' === $type ) {
					$field['multiline'] = true;
				}
				break;

			case 'email':
			case 'url':
			case 'oembed':
			case 'time_picker':
				if ( 'oembed' === $type || 'time_picker' === $type ) {
					$report['notes'][] = $type;
				}
				break;

			case 'number':
			case 'range':
				foreach ( array( 'min', 'max', 'step' ) as $bound ) {
					if ( isset( $acf[ $bound ] ) && is_numeric( $acf[ $bound ] ) ) {
						$field[ $bound ] = 0 + $acf[ $bound ];
					}
				}
				if ( isset( $field['min'], $field['max'] ) && $field['min'] > $field['max'] ) {
					unset( $field['min'], $field['max'] );
				}
				if ( isset( $field['step'] ) && $field['step'] <= 0 ) {
					unset( $field['step'] );
				}
				break;

			case 'image':
			case 'file':
			case 'gallery':
				foreach ( array( 'min_width', 'min_height', 'min_size', 'max_width', 'max_height', 'max_size', 'mime_types' ) as $rule ) {
					if ( ! empty( $acf[ $rule ] ) ) {
						$report['notes'][] = 'media_rules';
						self::lose( $ctx, 'media_rules', $name );
						break;
					}
				}
				if ( 'gallery' === $type ) {
					$field['max'] = isset( $acf['max'] ) ? max( 0, (int) $acf['max'] ) : 0;
				}
				break;

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$choices = array();
				foreach ( isset( $acf['choices'] ) && is_array( $acf['choices'] ) ? $acf['choices'] : array() as $value => $choice_label ) {
					$value = (string) $value;
					$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
					if ( '' === $value || $length > Leiho_Collections_Schema::VARCHAR ) {
						continue;
					}
					$choices[] = array(
						'value' => $value,
						'label' => is_scalar( $choice_label ) && '' !== (string) $choice_label ? (string) $choice_label : $value,
					);
				}
				if ( ! $choices ) {
					$report['status']  = 'dropped';
					$report['leiho']    = null;
					$report['notes'][] = 'no_choices';
					self::lose( $ctx, 'unsupported', $name . ' (' . $type . ')' );
					return array(
						'field'  => null,
						'report' => $report,
					);
				}
				$field['choices']  = $choices;
				$field['multiple'] = 'checkbox' === $type || ( 'select' === $type && ! empty( $acf['multiple'] ) );
				if ( ! empty( $acf['allow_custom'] ) || ! empty( $acf['other_choice'] ) || ! empty( $acf['create_options'] ) ) {
					$report['notes'][] = 'custom_choices';
					self::lose( $ctx, 'custom_choices', $name );
				}
				break;

			case 'post_object':
			case 'relationship':
				$targets = array();
				foreach ( isset( $acf['post_type'] ) ? (array) $acf['post_type'] : array() as $target ) {
					if ( is_string( $target ) && preg_match( '/^[a-z0-9_\-]{1,20}$/', $target ) ) {
						$targets[] = $target;
					}
				}
				if ( ! $targets ) {
					$targets = $ctx['targets'];
				}
				if ( ! $targets ) {
					$report['status']  = 'dropped';
					$report['leiho']    = null;
					$report['notes'][] = 'unsupported';
					self::lose( $ctx, 'unsupported', $name . ' (' . $type . ')' );
					return array(
						'field'  => null,
						'report' => $report,
					);
				}
				$field['to']       = array_values( array_unique( $targets ) );
				$field['multiple'] = 'relationship' === $type || ! empty( $acf['multiple'] );
				$field['max']      = 'relationship' === $type && isset( $acf['max'] ) ? max( 0, (int) $acf['max'] ) : 0;
				if ( ! empty( $acf['taxonomy'] ) ) {
					$report['notes'][] = 'filters';
					self::lose( $ctx, 'filters', $name );
				}
				if ( ! empty( $acf['bidirectional'] ) ) {
					$report['notes'][] = 'bidirectional';
					self::lose( $ctx, 'bidirectional', $name );
				}
				break;

			case 'repeater':
			case 'group':
				$taken_subs = array();
				$subs       = array();
				$children   = array();
				foreach ( isset( $acf['sub_fields'] ) && is_array( $acf['sub_fields'] ) ? $acf['sub_fields'] : array() as $sub ) {
					$mapped     = self::map_field( $sub, $ctx, $taken_subs );
					$children[] = $mapped['report'];
					if ( null !== $mapped['field'] ) {
						$subs[ $mapped['field']['name'] ] = $mapped['field'];
					}
				}
				$report['fields'] = $children;
				if ( ! $subs ) {
					$report['status']  = 'dropped';
					$report['leiho']    = null;
					$report['notes'][] = 'empty';
					self::lose( $ctx, 'unsupported', $name . ' (' . $type . ')' );
					return array(
						'field'  => null,
						'report' => $report,
					);
				}
				$field['fields'] = array_values( $subs );
				if ( 'repeater' === $type ) {
					$field['min'] = isset( $acf['min'] ) ? max( 0, (int) $acf['min'] ) : 0;
					$field['max'] = isset( $acf['max'] ) ? max( 0, (int) $acf['max'] ) : 0;
					// Mise en page de l'écran ACF (table, bloc, libellé du bouton) : sans
					// effet sur le contenu, ignorée sans le signaler.
					if ( ! empty( $acf['collapsed'] ) || ! empty( $acf['pagination'] ) ) {
						$report['notes'][] = 'pro';
						self::lose( $ctx, 'pro', $name );
					}
				}
				break;

			case 'flexible_content':
				$blocks      = array();
				$block_taken = array();
				$names       = array();
				$children    = array();
				foreach ( isset( $acf['layouts'] ) && is_array( $acf['layouts'] ) ? $acf['layouts'] : array() as $layout ) {
					$layout_name = isset( $layout['name'] ) ? (string) $layout['name'] : '';
					$node        = array(
						'name'   => $layout_name,
						'label'  => isset( $layout['label'] ) && '' !== (string) $layout['label'] ? (string) $layout['label'] : $layout_name,
						'acf'    => 'layout',
						'leiho'   => 'block',
						'status' => 'ok',
						'notes'  => array(),
						'fields' => array(),
					);
					if ( '' === $layout_name || ! preg_match( Leiho_Collections_Schema::ACF_NAME_PATTERN, $layout_name ) || isset( $names[ $layout_name ] ) ) {
						$node['status']  = 'dropped';
						$node['leiho']    = null;
						$node['notes'][] = 'name';
						self::lose( $ctx, 'name', $name . '/' . $layout_name );
						$children[] = $node;
						continue;
					}
					$names[ $layout_name ] = true;
					$taken_subs            = array();
					$subs                  = array();
					foreach ( isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() as $sub ) {
						$mapped           = self::map_field( $sub, $ctx, $taken_subs );
						$node['fields'][] = $mapped['report'];
						if ( null !== $mapped['field'] ) {
							$subs[ $mapped['field']['name'] ] = $mapped['field'];
						}
					}
					$children[] = $node;
					$blocks[]   = array(
						'key'    => self::key_for( $layout_name, $block_taken ),
						'name'   => $layout_name,
						'label'  => $node['label'],
						'min'    => isset( $layout['min'] ) ? max( 0, (int) $layout['min'] ) : 0,
						'max'    => isset( $layout['max'] ) ? max( 0, (int) $layout['max'] ) : 0,
						'fields' => array_values( $subs ),
					);
				}
				$report['fields'] = $children;
				if ( ! $blocks ) {
					$report['status']  = 'dropped';
					$report['leiho']    = null;
					$report['notes'][] = 'empty';
					self::lose( $ctx, 'unsupported', $name . ' (' . $type . ')' );
					return array(
						'field'  => null,
						'report' => $report,
					);
				}
				$field['blocks'] = $blocks;
				$field['min']    = isset( $acf['min'] ) ? max( 0, (int) $acf['min'] ) : 0;
				$field['max']    = isset( $acf['max'] ) ? max( 0, (int) $acf['max'] ) : 0;
				break;
		}

		if ( $report['notes'] && 'ok' === $report['status'] ) {
			$report['status'] = 'changed';
		}
		$ctx['fields']++;

		return array(
			'field'  => $field,
			'report' => $report,
		);
	}

	/**
	 * Clé Leiho (identité stable, nom de colonne) déduite d'un nom ACF.
	 *
	 * @param string $name  Nom ACF.
	 * @param array  $taken Clés prises (entrée/sortie).
	 * @return string
	 */
	public static function key_for( $name, &$taken ) {
		$base = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $name ) ), '_' );
		if ( '' === $base || ! preg_match( '/^[a-z]/', $base ) ) {
			$base = 'f_' . $base;
		}
		$base = rtrim( substr( $base, 0, 48 ), '_' );
		$key  = $base;
		$n    = 2;
		while ( isset( $taken[ $key ] ) ) {
			$suffix = '_' . $n;
			$key    = rtrim( substr( $base, 0, 48 - strlen( $suffix ) ), '_' ) . $suffix;
			$n++;
		}
		$taken[ $key ] = true;
		return $key;
	}

	private static function lose( &$ctx, $code, $what ) {
		if ( ! isset( $ctx['lost'][ $code ] ) ) {
			$ctx['lost'][ $code ] = array();
		}
		if ( ! in_array( $what, $ctx['lost'][ $code ], true ) ) {
			$ctx['lost'][ $code ][] = $what;
		}
	}

	/**
	 * Valeur ACF brute (get_field( …, false )) → valeur d'écriture Leiho, au
	 * format de l'éditeur : lignes indexées par nom, « _layout » des blocs.
	 * Vide → null (rien à écrire : la lecture rend la même valeur vide).
	 * Les médias et contenus qui n'existent plus sont retirés : ACF ne les
	 * servait déjà pas.
	 *
	 * @param array $field Champ ACF.
	 * @param mixed $raw   Valeur brute.
	 * @return mixed
	 */
	public static function to_input( $field, $raw ) {
		$type = isset( $field['type'] ) ? $field['type'] : '';
		switch ( $type ) {
			case 'repeater':
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					if ( is_array( $row ) ) {
						$rows[] = self::row_input( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $row );
					}
				}
				return $rows ? $rows : null;

			case 'group':
			case 'clone':
				$row = self::row_input( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), is_array( $raw ) ? $raw : array() );
				return $row ? $row : null;

			case 'flexible_content':
				$layouts = array();
				foreach ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array() as $layout ) {
					if ( isset( $layout['name'] ) ) {
						$layouts[ (string) $layout['name'] ] = $layout;
					}
				}
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $row ) {
					$name = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
					if ( ! isset( $layouts[ $name ] ) ) {
						continue; // Layout disparu de la configuration : ACF ne le servait pas.
					}
					$rows[] = array_merge(
						array( '_layout' => $name ),
						self::row_input( isset( $layouts[ $name ]['sub_fields'] ) ? $layouts[ $name ]['sub_fields'] : array(), $row )
					);
				}
				return $rows ? $rows : null;

			case 'image':
			case 'file':
				$id = absint( is_array( $raw ) && isset( $raw['ID'] ) ? $raw['ID'] : ( is_scalar( $raw ) ? $raw : 0 ) );
				return $id && 'attachment' === get_post_type( $id ) ? $id : null;

			case 'gallery':
				$ids = array();
				foreach ( is_array( $raw ) ? $raw : array() as $item ) {
					$id = absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : ( is_scalar( $item ) ? $item : 0 ) );
					if ( $id && 'attachment' === get_post_type( $id ) ) {
						$ids[] = $id;
					}
				}
				return $ids ? $ids : null;

			case 'post_object':
			case 'relationship':
				$ids = array();
				foreach ( is_array( $raw ) ? $raw : ( $raw ? array( $raw ) : array() ) as $item ) {
					$id = absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : ( is_object( $item ) && isset( $item->ID ) ? $item->ID : ( is_scalar( $item ) ? $item : 0 ) ) );
					if ( $id && get_post( $id ) ) {
						$ids[] = $id;
					}
				}
				if ( 'post_object' === $type && empty( $field['multiple'] ) ) {
					return $ids ? $ids[0] : null;
				}
				return $ids ? $ids : null;

			case 'true_false':
				return $raw && '0' !== $raw ? true : null;

			case 'number':
			case 'range':
				return is_numeric( $raw ) ? 0 + $raw : null;

			case 'date_picker':
				$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) {
					return $m[1] . '-' . $m[2] . '-' . $m[3];
				}
				return '' === $raw ? null : $raw;

			case 'checkbox':
				$values = self::strings( $raw );
				return $values ? $values : null;

			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					$values = self::strings( $raw );
					return $values ? $values : null;
				}
				if ( is_array( $raw ) ) {
					$raw = reset( $raw );
				}
				return is_scalar( $raw ) && '' !== (string) $raw && false !== $raw ? (string) $raw : null;

			case 'link':
				if ( is_string( $raw ) ) {
					$raw = array( 'url' => $raw );
				}
				if ( ! is_array( $raw ) || ! isset( $raw['url'] ) || '' === trim( (string) $raw['url'] ) ) {
					return null;
				}
				return array(
					'url'    => (string) $raw['url'],
					'title'  => isset( $raw['title'] ) ? (string) $raw['title'] : '',
					'target' => isset( $raw['target'] ) ? (string) $raw['target'] : '',
				);

			default:
				if ( null === $raw || false === $raw || is_array( $raw ) || is_object( $raw ) ) {
					return null;
				}
				return '' === (string) $raw ? null : (string) $raw;
		}
	}

	private static function row_input( $subs, $row ) {
		$out = array();
		foreach ( (array) $subs as $sub ) {
			$name = isset( $sub['name'] ) ? (string) $sub['name'] : '';
			if ( '' === $name || in_array( isset( $sub['type'] ) ? $sub['type'] : '', Leiho_Field_Adapter::LAYOUT_TYPES, true ) ) {
				continue;
			}
			$raw = null;
			if ( isset( $sub['key'] ) && array_key_exists( $sub['key'], $row ) ) {
				$raw = $row[ $sub['key'] ];
			} elseif ( array_key_exists( $name, $row ) ) {
				$raw = $row[ $name ];
			}
			$value = self::to_input( $sub, $raw );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	private static function strings( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : ( null === $raw || '' === $raw || false === $raw ? array() : array( $raw ) ) as $item ) {
			if ( is_scalar( $item ) && '' !== (string) $item ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}

	/**
	 * Forme canonique d'une valeur servie, pour comparer avant / après :
	 * un nombre entier vaut le même entier qu'il soit écrit 5 ou 5.0.
	 *
	 * @param mixed $value Valeur servie au front.
	 * @return string JSON.
	 */
	public static function canonical( $value ) {
		return (string) json_encode( self::canonical_value( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	private static function canonical_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::canonical_value( $v );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::canonical_value( get_object_vars( $value ) );
		}
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 9007199254740992 ) {
			return (int) $value;
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Valeurs hors limites (pure : testable sans WordPress)
	 * ------------------------------------------------------------------- */

	/**
	 * Relève, dans les valeurs d'une entrée, ce qui dépasse une limite que
	 * Leiho_Collections::save_entry() appliquera : lignes d'une liste ou de
	 * blocs (max, min), blocs d'un type (max, min), éléments d'une galerie ou
	 * d'une relation (max), longueur d'un texte (max_length), bornes d'un
	 * nombre (min, max). ACF ne les vérifie que dans son écran d'édition : une
	 * valeur écrite par code (import, update_field) peut les dépasser.
	 * « required » n'est pas relevé : une valeur vide n'est jamais écrite par
	 * l'import, donc jamais refusée.
	 *
	 * @param array  $fields  Champs Leiho (build()).
	 * @param array  $values  nom => valeur d'écriture (to_input()).
	 * @param int    $post_id Entrée.
	 * @param array  $found   chemin|règle → { path, rule, limit, found, entries } (entrée/sortie).
	 * @param string $prefix  Chemin du parent (« sections/colonnes »).
	 * @return void
	 */
	public static function scan_limits( $fields, $values, $post_id, &$found, $prefix = '' ) {
		if ( ! is_array( $values ) ) {
			return;
		}
		foreach ( (array) $fields as $field ) {
			$name = $field['name'];
			if ( ! array_key_exists( $name, $values ) || null === $values[ $name ] ) {
				continue;
			}
			$value = $values[ $name ];
			$path  = '' === $prefix ? $name : $prefix . '/' . $name;
			$max   = isset( $field['max'] ) && is_numeric( $field['max'] ) ? 0 + $field['max'] : null;
			$min   = isset( $field['min'] ) && is_numeric( $field['min'] ) ? 0 + $field['min'] : null;
			switch ( $field['type'] ) {
				case 'text':
					if ( ! empty( $field['max_length'] ) && is_scalar( $value ) ) {
						// Même nettoyage que l'éditeur avant de compter.
						$text = (string) $value;
						if ( ! empty( $field['multiline'] ) && function_exists( 'sanitize_textarea_field' ) ) {
							$text = sanitize_textarea_field( $text );
						} elseif ( empty( $field['multiline'] ) && function_exists( 'sanitize_text_field' ) ) {
							$text = sanitize_text_field( $text );
						}
						$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
						if ( $length > (int) $field['max_length'] ) {
							self::over( $found, $path, 'max_length', (int) $field['max_length'], $length, $post_id );
						}
					}
					break;

				case 'number':
					if ( is_numeric( $value ) ) {
						if ( null !== $max && $value > $max ) {
							self::over( $found, $path, 'max', $max, 0 + $value, $post_id );
						}
						if ( null !== $min && $value < $min ) {
							self::over( $found, $path, 'min', $min, 0 + $value, $post_id );
						}
					}
					break;

				case 'gallery':
				case 'reference':
					if ( is_array( $value ) && $max > 0 && count( $value ) > $max ) {
						self::over( $found, $path, 'max', $max, count( $value ), $post_id );
					}
					break;

				case 'group':
					self::scan_limits( $field['fields'], $value, $post_id, $found, $path );
					break;

				case 'list':
					if ( ! is_array( $value ) ) {
						break;
					}
					if ( $max > 0 && count( $value ) > $max ) {
						self::over( $found, $path, 'max', $max, count( $value ), $post_id );
					}
					if ( $min > 0 && count( $value ) < $min ) {
						self::over( $found, $path, 'min', $min, count( $value ), $post_id );
					}
					foreach ( $value as $row ) {
						self::scan_limits( $field['fields'], $row, $post_id, $found, $path );
					}
					break;

				case 'blocks':
					if ( ! is_array( $value ) ) {
						break;
					}
					if ( $max > 0 && count( $value ) > $max ) {
						self::over( $found, $path, 'max', $max, count( $value ), $post_id );
					}
					$blocks = array();
					foreach ( $field['blocks'] as $block ) {
						$blocks[ $block['name'] ] = $block;
					}
					$counts = array();
					foreach ( $value as $row ) {
						$layout = is_array( $row ) && isset( $row['_layout'] ) ? (string) $row['_layout'] : '';
						if ( ! isset( $blocks[ $layout ] ) ) {
							continue;
						}
						$counts[ $layout ] = isset( $counts[ $layout ] ) ? $counts[ $layout ] + 1 : 1;
						self::scan_limits( $blocks[ $layout ]['fields'], $row, $post_id, $found, $path . '/' . $layout );
					}
					foreach ( $blocks as $layout => $block ) {
						$count = isset( $counts[ $layout ] ) ? $counts[ $layout ] : 0;
						if ( ! empty( $block['max'] ) && $count > (int) $block['max'] ) {
							self::over( $found, $path . '/' . $layout, 'max', (int) $block['max'], $count, $post_id );
						}
						if ( ! empty( $block['min'] ) && $count < (int) $block['min'] ) {
							self::over( $found, $path . '/' . $layout, 'min', (int) $block['min'], $count, $post_id );
						}
					}
					break;
			}
		}
	}

	private static function over( &$found, $path, $rule, $limit, $value, $post_id ) {
		$id = $path . '|' . $rule;
		if ( ! isset( $found[ $id ] ) ) {
			$found[ $id ] = array(
				'path'    => $path,
				'rule'    => $rule,
				'limit'   => $limit,
				'found'   => $value,
				'entries' => array(),
			);
		}
		// max, max_length : la plus grande valeur existante ; min : la plus petite.
		$found[ $id ]['found'] = 'min' === $rule ? min( $found[ $id ]['found'], $value ) : max( $found[ $id ]['found'], $value );
		if ( ! in_array( (int) $post_id, $found[ $id ]['entries'], true ) ) {
			$found[ $id ]['entries'][] = (int) $post_id;
		}
	}

	/**
	 * Élargit les limites des champs à ce qui existe déjà : aucune valeur
	 * n'est refusée par l'import, aucune ligne n'est perdue. Les limites
	 * élargies sont listées dans l'aperçu ; on peut les resserrer ensuite dans
	 * les réglages de la collection (les entrées hors limites devront alors
	 * être corrigées à leur prochaine modification).
	 *
	 * @param array  $fields Champs Leiho (entrée/sortie).
	 * @param array  $found  scan_limits().
	 * @param string $prefix Chemin du parent.
	 * @return void
	 */
	public static function relax_limits( &$fields, $found, $prefix = '' ) {
		foreach ( $fields as &$field ) {
			$path = '' === $prefix ? $field['name'] : $prefix . '/' . $field['name'];
			foreach ( array( 'max', 'min', 'max_length' ) as $rule ) {
				if ( isset( $found[ $path . '|' . $rule ] ) ) {
					$field[ $rule ] = $found[ $path . '|' . $rule ]['found'];
				}
			}
			if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
				self::relax_limits( $field['fields'], $found, $path );
			}
			if ( isset( $field['blocks'] ) && is_array( $field['blocks'] ) ) {
				foreach ( $field['blocks'] as &$block ) {
					$block_path = $path . '/' . $block['name'];
					foreach ( array( 'max', 'min' ) as $rule ) {
						if ( isset( $found[ $block_path . '|' . $rule ] ) ) {
							$block[ $rule ] = $found[ $block_path . '|' . $rule ]['found'];
						}
					}
					self::relax_limits( $block['fields'], $found, $block_path );
				}
				unset( $block );
			}
		}
		unset( $field );
	}

	/* ---------------------------------------------------------------------
	 * Aperçu
	 * ------------------------------------------------------------------- */

	/**
	 * Types qu'on peut proposer à l'import, avec leur état.
	 *
	 * @return array{acf:bool,types:array,elsewhere:array}
	 */
	public static function overview() {
		$types = array();
		if ( class_exists( 'Leiho_Plugin' ) ) {
			$types = (array) Leiho_Plugin::settings()['post_types'];
		}
		$groups = self::acf_available() ? acf_get_field_groups() : array();
		$all    = function_exists( 'get_post_types' ) ? array_values( get_post_types() ) : array();

		$elsewhere = array();
		foreach ( $groups as $group ) {
			$location = isset( $group['location'] ) ? $group['location'] : array();
			$used     = false;
			foreach ( $all as $type ) {
				if ( null !== Leiho_ACF_Adapter::targets( $location, $type ) || self::entry_ids( $location, $type ) ) {
					$used = true;
					if ( ! in_array( $type, $types, true ) ) {
						$types[] = $type;
					}
				}
			}
			if ( ! $used ) {
				$elsewhere[] = array(
					'key'   => (string) $group['key'],
					'title' => (string) $group['title'],
					'where' => self::location_params( $location ),
				);
			}
		}
		foreach ( array_keys( self::records() ) as $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		$out = array();
		foreach ( $types as $type ) {
			if ( 'attachment' === $type || 0 === strpos( $type, 'acf-' ) || 0 === strpos( $type, 'wp_' ) ) {
				continue;
			}
			$origin = self::origin( $type );
			if ( 'leiho' === $origin ) {
				continue; // Collection Leiho : rien à importer.
			}
			$reason = self::blocker( $type, $origin );
			$object = get_post_type_object( $type );
			$out[]  = array(
				'post_type'  => $type,
				'label'      => $object ? $object->labels->name : $type,
				'origin'     => $origin,
				'groups'     => count( self::groups_for( $type ) ),
				'entries'    => self::count_entries( $type ),
				'status'     => self::record( $type ) ? self::record( $type )['status'] : null,
				'importable' => '' === $reason,
				'reason'     => $reason,
			);
		}

		return array(
			'acf'       => self::acf_available(),
			'types'     => $out,
			'elsewhere' => $elsewhere,
		);
	}

	private static function location_params( $location ) {
		$params = array();
		foreach ( (array) $location as $branch ) {
			foreach ( (array) $branch as $rule ) {
				if ( isset( $rule['param'] ) && ! in_array( $rule['param'], $params, true ) ) {
					$params[] = (string) $rule['param'];
				}
			}
		}
		return $params;
	}

	/**
	 * Raison qui empêche l'import (vide si rien ne l'empêche, hors erreurs de
	 * schéma vues par preview()).
	 *
	 * @param string $post_type Type.
	 * @param string $origin    origin().
	 * @return string
	 */
	private static function blocker( $post_type, $origin ) {
		$record = self::record( $post_type );
		if ( $record ) {
			if ( self::STATUS_REVIEW === $record['status'] ) {
				return __( 'The import finished with failures or differences: ACF still serves this content type. Review the report, then switch to Leiho anyway or go back to ACF.', 'leiho' );
			}
			return self::STATUS_DONE === $record['status'] ? __( 'Already imported into Leiho.', 'leiho' ) : '';
		}
		if ( ! self::acf_available() ) {
			return __( 'ACF (or Secure Custom Fields) isn’t active.', 'leiho' );
		}
		if ( 'builtin' === $origin ) {
			return __( 'Pages and posts can’t become Leiho collections: Leiho fields belong to a collection’s own content type. Their ACF fields stay in ACF.', 'leiho' );
		}
		if ( 'missing' === $origin ) {
			return __( 'This content type isn’t registered.', 'leiho' );
		}
		if ( 'leiho' === $origin ) {
			return __( 'A Leiho collection already uses this identifier.', 'leiho' );
		}
		if ( ! preg_match( Leiho_Collections_Schema::ID_PATTERN, $post_type ) || in_array( $post_type, Leiho_Collections_Schema::RESERVED_IDS, true ) ) {
			return __( 'Its identifier can’t name a Leiho collection (1 to 20 lowercase letters, digits or underscores, not reserved by WordPress).', 'leiho' );
		}
		if ( ! self::groups_for( $post_type ) ) {
			return __( 'No ACF field group is attached to this content type.', 'leiho' );
		}
		return '';
	}

	/**
	 * Aperçu de l'import d'un type.
	 *
	 * @param string $post_type Type.
	 * @return array
	 */
	public static function preview( $post_type ) {
		$origin = self::origin( $post_type );
		$reason = self::blocker( $post_type, $origin );
		$object = get_post_type_object( $post_type );
		$groups = self::groups_for( $post_type );
		$built  = self::build(
			$post_type,
			$groups,
			array(
				'label'          => $object ? $object->labels->name : $post_type,
				'singular_label' => $object ? $object->labels->singular_name : '',
				'targets'        => self::default_targets(),
			)
		);

		$errors = array();
		$limits = array();
		$found  = array();
		$over   = array(); // Entrées ayant au moins une valeur hors limites.
		$scan   = 0;
		if ( '' === $reason && $built['conflicts'] ) {
			$reason = sprintf(
				/* translators: %s: field names. */
				__( 'Some fields have a different shape depending on the entry, because several ACF groups define them differently: %s. One Leiho field can’t hold both shapes. Rename one of them in ACF (and move its values), then preview again.', 'leiho' ),
				implode( ', ', $built['conflicts'] )
			);
		}
		if ( '' === $reason ) {
			// Valeurs existantes hors limites : les limites du schéma généré
			// sont élargies à ce qui existe, rien n'est refusé ni perdu.
			$scan = self::scan_entries( $post_type, $groups, $built['collection'], $found );
			self::relax_limits( $built['collection']['fields'], $found );
			foreach ( $found as $item ) {
				foreach ( $item['entries'] as $id ) {
					$over[ $id ] = true;
				}
				$item['count']   = count( $item['entries'] );
				$item['entries'] = array_slice( $item['entries'], 0, 20 );
				$limits[]        = $item;
			}
			if ( $limits ) {
				$items = array();
				foreach ( $limits as $item ) {
					$items[] = sprintf( '%s: %s %s → %s (#%s)', $item['path'], $item['rule'], $item['limit'], $item['found'], implode( ', #', array_slice( $item['entries'], 0, 5 ) ) . ( $item['count'] > 5 ? ', …' : '' ) );
				}
				$built['lost']['limits'] = $items;
			}

			$ids = array_merge( class_exists( 'Leiho_Collections' ) ? Leiho_Collections::post_types() : array(), array( $post_type ) );
			Leiho_Collections_Schema::normalize_collection( $built['collection'], '', $errors, $ids );
			if ( ! $built['collection']['fields'] ) {
				$reason = __( 'None of its ACF fields can be carried over.', 'leiho' );
			}
		}

		$record = self::record( $post_type );
		return array(
			'post_type'  => $post_type,
			'label'      => $object ? $object->labels->name : $post_type,
			'origin'     => $origin,
			'importable' => '' === $reason && ! $errors,
			'reason'     => $reason,
			'errors'     => $errors,
			'groups'     => $built['groups'],
			'lost'       => self::lost_messages( $built['lost'], $origin, $post_type ),
			'collection' => $built['collection'],
			'conflicts'  => $built['conflicts'],
			'limits'     => $limits,
			'counts'     => array(
				'entries' => self::count_entries( $post_type ),
				'fields'  => $built['counts']['fields'],
				'values'  => self::count_values( $post_type, $groups ),
				'scanned' => $scan,
				'limits'  => count( $over ),
			),
			'status'     => $record ? $record['status'] : null,
		);
	}

	/**
	 * Lit les valeurs ACF des entrées (SCAN_LIMIT au plus) et relève celles
	 * qui sortent des limites de la collection générée.
	 *
	 * @param string $post_type  Type.
	 * @param array  $groups     Groupes.
	 * @param array  $collection Collection générée.
	 * @param array  $found      scan_limits() (sortie).
	 * @return int Entrées lues.
	 */
	private static function scan_entries( $post_type, $groups, $collection, &$found ) {
		global $wpdb;
		$map = self::field_map_for( $groups, $collection );
		if ( ! $map ) {
			return 0;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID ASC LIMIT %d",
				$post_type,
				self::SCAN_LIMIT
			)
		);
		foreach ( $ids as $id ) {
			$values = array();
			foreach ( $map as $name => $field ) {
				$values[ $name ] = self::to_input( $field, get_field( $field['key'], (int) $id, false ) );
			}
			self::scan_limits( $collection['fields'], $values, (int) $id, $found );
		}
		return count( $ids );
	}

	/**
	 * Types visés par une relation ACF sans filtre de type : tous les types
	 * éditables (hors médias et types internes).
	 *
	 * @return string[]
	 */
	private static function default_targets() {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ) ) as $type ) {
			if ( 'attachment' === $type || 0 === strpos( $type, 'acf-' ) || 0 === strpos( $type, 'wp_' ) || ! preg_match( '/^[a-z0-9_\-]{1,20}$/', $type ) ) {
				continue;
			}
			$out[] = $type;
		}
		return $out;
	}

	/**
	 * Ce qui ne suit pas, en phrases.
	 *
	 * @param array  $lost      Pertes (code → noms).
	 * @param string $origin    Origine du type.
	 * @param string $post_type Type.
	 * @return array[] { code, message, items }
	 */
	private static function lost_messages( $lost, $origin, $post_type ) {
		$messages = array(
			'unsupported'    => __( 'Fields with no Leiho equivalent are not carried over; their values leave the content API (they stay in ACF).', 'leiho' ),
			'clone'          => __( 'Clone fields without cloned fields are not carried over (a seamless clone is replaced by its fields, a clone shown as a group becomes a group).', 'leiho' ),
			'name'           => __( 'Fields or layouts whose name can’t be used as an API name are not carried over.', 'leiho' ),
			'duplicate'      => __( 'Several groups define a field with the same name: Leiho keeps the last definition, as ACF served it.', 'leiho' ),
			'conflict'       => __( 'Several groups define a field with the same name but a different shape, and ACF picks one or the other depending on the entry: these fields block the import.', 'leiho' ),
			'entry_group'    => __( 'Some groups are attached to specific entries of this content type: their fields become available on every entry.', 'leiho' ),
			'limits'         => __( 'Some existing values exceed limits that ACF only checks in its own editor: the Leiho collection raises (or lowers) these limits to fit them, so nothing is refused or lost. You can tighten them later in the collection settings.', 'leiho' ),
			'conditional'    => __( 'Conditional logic is dropped: these fields are always shown in the editor.', 'leiho' ),
			'default'        => __( 'Default values are not carried over: existing entries keep their value, new entries start empty.', 'leiho' ),
			'location'       => __( 'Location rules other than the content type are dropped: the fields of these groups become available on every entry.', 'leiho' ),
			'media_rules'    => __( 'Media restrictions (size, dimensions, file types) are not enforced by Leiho.', 'leiho' ),
			'pro'            => __( 'Repeater options of ACF Pro (collapsed rows, pagination) are not carried over.', 'leiho' ),
			'bidirectional'  => __( 'Bidirectional relationships stop updating the other side.', 'leiho' ),
			'filters'        => __( 'Taxonomy filters of relation fields are not carried over.', 'leiho' ),
			'custom_choices' => __( 'Values typed by editors outside the declared choices (allow custom, other) are refused by Leiho: they are reported as failures.', 'leiho' ),
		);
		$out = array();
		foreach ( $messages as $code => $message ) {
			if ( ! empty( $lost[ $code ] ) ) {
				$out[] = array(
					'code'    => $code,
					'message' => $message,
					'items'   => array_values( $lost[ $code ] ),
				);
			}
		}
		// Toujours vrai : à dire avant d'importer.
		if ( 'external' === $origin ) {
			$out[] = array(
				'code'    => 'registration',
				'message' => __( 'The content type stays registered by the theme or the plugin that declares it; Leiho only takes over its fields.', 'leiho' ),
				'items'   => array(),
			);
		} elseif ( 'acf' === $origin ) {
			$out[] = array(
				'code'    => 'registration',
				'message' => __( 'The content type is declared in ACF: Leiho keeps it registered with the same settings if ACF is deactivated. Taxonomies declared in ACF are not taken over.', 'leiho' ),
				'items'   => array(),
			);
		}
		$out[] = array(
			'code'    => 'revisions',
			'message' => __( 'Past revisions keep their ACF values only: restoring one restores the post, not the Leiho fields.', 'leiho' ),
			'items'   => array(),
		);
		$out[] = array(
			'code'    => 'one_way',
			'message' => __( 'ACF data is never deleted. Values edited in Leiho after the import are not written back to ACF: going back to ACF restores the values of the import day.', 'leiho' ),
			'items'   => array(),
		);
		return $out;
	}

	/**
	 * Entrées du type (toutes, corbeille comprise).
	 *
	 * @param string $post_type Type.
	 * @return int
	 */
	public static function count_entries( $post_type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','inherit')", $post_type ) );
	}

	/**
	 * Valeurs ACF stockées pour ce type : une méta de référence (_nom → clé
	 * de champ) par valeur, sous-champs compris.
	 *
	 * @param string $post_type Type.
	 * @param array  $groups    Groupes.
	 * @return int
	 */
	private static function count_values( $post_type, $groups ) {
		global $wpdb;
		$keys = array();
		foreach ( $groups as $group ) {
			self::collect_keys( $group['fields'], $keys );
		}
		if ( ! $keys ) {
			return 0;
		}
		$keys  = array_values( array_unique( $keys ) );
		$count = 0;
		foreach ( array_chunk( $keys, 200 ) as $chunk ) {
			$in     = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args   = array_merge( array( $post_type ), $chunk );
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Liste de %s construite ci-dessus.
					"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft','inherit') AND m.meta_value IN ($in)",
					...$args
				)
			);
		}
		return $count;
	}

	private static function collect_keys( $fields, &$keys ) {
		foreach ( (array) $fields as $field ) {
			if ( isset( $field['key'] ) ) {
				$keys[] = (string) $field['key'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				self::collect_keys( $field['sub_fields'], $keys );
			}
			foreach ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array() as $layout ) {
				self::collect_keys( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array(), $keys );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------- */

	/**
	 * Crée la collection et commence l'import (aucune entrée copiée encore).
	 *
	 * @param string $post_type Type.
	 * @return array|WP_Error Enregistrement.
	 */
	public static function start( $post_type ) {
		$record = self::record( $post_type );
		if ( $record ) {
			return $record;
		}
		$preview = self::preview( $post_type );
		if ( ! $preview['importable'] ) {
			$message = '' !== $preview['reason'] ? $preview['reason'] : __( 'The Leiho collection built from these ACF fields is invalid.', 'leiho' );
			return new WP_Error(
				'leiho_not_importable',
				$message,
				array(
					'status' => 400,
					'errors' => $preview['errors'],
				)
			);
		}

		$origin = $preview['origin'];
		$args   = 'acf' === $origin ? self::acf_post_type_args( $post_type ) : array();

		$report = Leiho_Collections::put_collection( $preview['collection'] );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$record = array(
			'status'    => self::STATUS_RUNNING,
			'origin'    => $origin,
			'args'      => $args,
			'groups'    => wp_list_pluck( $preview['groups'], 'key' ),
			'started'   => time(),
			'finished'  => 0,
			'user'      => get_current_user_id(),
			'total'     => $preview['counts']['entries'],
			'values'    => $preview['counts']['values'],
			'done'      => 0,
			'cursor'    => 0,
			'imported'  => 0,
			'failed'    => 0,
			'different' => 0,
			'failures'  => array(),
			'diffs'     => array(),
			'limits'    => $preview['limits'],
		);
		self::save_record( $post_type, $record );

		/**
		 * Un import ACF → Leiho commence : la collection vient d'être créée.
		 *
		 * @param string $post_type Type.
		 * @param array  $record    Enregistrement.
		 */
		do_action( 'leiho_acf_import_started', $post_type, $record );
		return $record;
	}

	/**
	 * Copie le lot d'entrées suivant ; termine l'import après le dernier :
	 * « done » (bascule vers Leiho) si chaque entrée a été reprise à
	 * l'identique, « review » (ACF sert toujours) au moindre échec ou écart.
	 *
	 * @param string $post_type Type.
	 * @param int    $limit     Entrées par lot.
	 * @return array|WP_Error Enregistrement.
	 */
	public static function step( $post_type, $limit = self::BATCH ) {
		global $wpdb;

		$record = self::record( $post_type );
		if ( ! $record ) {
			return new WP_Error( 'leiho_not_started', __( 'This import hasn’t started.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( self::finished( $record ) ) {
			return $record;
		}
		if ( ! self::acf_available() ) {
			return new WP_Error( 'leiho_no_acf', __( 'ACF (or Secure Custom Fields) isn’t active.', 'leiho' ), array( 'status' => 400 ) );
		}
		$collection = Leiho_Collections::get( $post_type );
		if ( ! $collection ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown collection.', 'leiho' ), array( 'status' => 404 ) );
		}

		$map   = self::field_map( $post_type, $collection );
		$limit = max( 1, (int) $limit );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','inherit') AND ID > %d ORDER BY ID ASC LIMIT %d",
				$post_type,
				(int) $record['cursor'],
				$limit
			)
		);

		$acf     = new Leiho_ACF_Adapter();
		$native  = new Leiho_Native_Adapter();
		$started = microtime( true );
		$all     = count( $ids ) < $limit;

		add_filter( 'leiho_collections_save_revision', '__return_false' );
		self::$importing = $post_type;
		foreach ( $ids as $n => $id ) {
			self::import_entry( (int) $id, $collection, $map, $acf, $native, $record );
			$record['cursor'] = (int) $id;
			$record['done']++;
			if ( microtime( true ) - $started > self::TIME_BUDGET && $n < count( $ids ) - 1 ) {
				$all = false;
				break;
			}
		}
		self::$importing = '';
		remove_filter( 'leiho_collections_save_revision', '__return_false' );

		if ( $all ) {
			// Une valeur refusée ou servie autrement : jamais de bascule sans
			// décision. Le front publierait sinon des pages vidées.
			$record['status']   = $record['failed'] || $record['different'] ? self::STATUS_REVIEW : self::STATUS_DONE;
			$record['finished'] = time();
			$record['total']    = max( (int) $record['total'], (int) $record['done'] );
		}
		self::save_record( $post_type, $record );

		if ( self::STATUS_DONE === $record['status'] ) {
			self::switched( $post_type, $record );
		} elseif ( self::STATUS_REVIEW === $record['status'] ) {
			/**
			 * Un import ACF → Leiho a fini de copier les entrées avec des échecs
			 * ou des écarts : ACF sert toujours le type, le rapport attend une
			 * décision (accept() ou rollback()).
			 *
			 * @param string $post_type Type.
			 * @param array  $record    Enregistrement.
			 */
			do_action( 'leiho_acf_import_needs_review', $post_type, $record );
		}
		return $record;
	}

	/**
	 * Bascule quand même un import en « review » : Leiho sert le type avec
	 * les valeurs importées (les échecs restent vides, les écarts servis tels
	 * que Leiho les a gardés). Le rapport reste consultable.
	 *
	 * @param string $post_type Type.
	 * @return array|WP_Error Enregistrement.
	 */
	public static function accept( $post_type ) {
		$record = self::record( $post_type );
		if ( ! $record ) {
			return new WP_Error( 'leiho_not_imported', __( 'This content type wasn’t imported from ACF.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( self::STATUS_REVIEW !== $record['status'] ) {
			return new WP_Error( 'leiho_not_in_review', __( 'Only an import waiting for review can be switched to Leiho this way.', 'leiho' ), array( 'status' => 409 ) );
		}
		if ( ! class_exists( 'Leiho_Collections' ) || ! Leiho_Collections::get( $post_type ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown collection.', 'leiho' ), array( 'status' => 404 ) );
		}
		$record['status']      = self::STATUS_DONE;
		$record['accepted']    = time();
		$record['accepted_by'] = get_current_user_id();
		self::save_record( $post_type, $record );
		self::switched( $post_type, $record );
		return $record;
	}

	private static function switched( $post_type, $record ) {
		if ( class_exists( 'Leiho_Content' ) ) {
			Leiho_Content::invalidate();
		}
		/**
		 * Un import ACF → Leiho est terminé : le type est servi par Leiho.
		 *
		 * @param string $post_type Type.
		 * @param array  $record    Enregistrement.
		 */
		do_action( 'leiho_acf_imported', $post_type, $record );
	}

	/**
	 * Champs racine de la collection → définition ACF d'origine (par nom).
	 *
	 * @param string $post_type  Type.
	 * @param array  $collection Collection.
	 * @return array<string,array>
	 */
	private static function field_map( $post_type, $collection ) {
		return self::field_map_for( self::groups_for( $post_type ), $collection );
	}

	/**
	 * @param array $groups     Groupes (groups_for()).
	 * @param array $collection Collection.
	 * @return array<string,array>
	 */
	private static function field_map_for( $groups, $collection ) {
		$acf = array();
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( isset( $field['name'] ) && '' !== (string) $field['name'] ) {
					$acf[ (string) $field['name'] ] = $field; // Le dernier gagne, comme dans ACF.
				}
			}
		}
		$out = array();
		foreach ( $collection['fields'] as $field ) {
			if ( isset( $acf[ $field['name'] ] ) ) {
				$out[ $field['name'] ] = $acf[ $field['name'] ];
			}
		}
		return $out;
	}

	/**
	 * Copie une entrée, puis compare ce que Leiho sert à ce que servait ACF.
	 *
	 * @param int                 $post_id    Entrée.
	 * @param array               $collection Collection.
	 * @param array               $map        nom → champ ACF.
	 * @param Leiho_ACF_Adapter    $acf        Adapter ACF.
	 * @param Leiho_Native_Adapter $native     Adapter natif.
	 * @param array               $record     Enregistrement (entrée/sortie).
	 * @return void
	 */
	private static function import_entry( $post_id, $collection, $map, $acf, $native, &$record ) {
		$before = $acf->get_content( $post_id );

		// Ligne remise à vide : un ancien import ne laisse rien derrière lui.
		$reset = array();
		foreach ( $collection['fields'] as $field ) {
			$reset[ $field['key'] ] = null;
		}
		Leiho_Collections_Store::write( $collection['id'], $post_id, $reset );

		$values = array();
		foreach ( $map as $name => $field ) {
			$value = self::to_input( $field, get_field( $field['key'], $post_id, false ) );
			if ( null !== $value ) {
				$values[ $name ] = $value;
			}
		}

		$result = $values ? Leiho_Collections::save_entry( $post_id, $values ) : array(
			'saved'  => array(),
			'errors' => array(),
		);

		$title = class_exists( 'Leiho_Plugin' ) ? Leiho_Plugin::title( $post_id ) : (string) $post_id;
		foreach ( $result['errors'] as $path => $code ) {
			$record['failed']++;
			if ( count( $record['failures'] ) < self::MAX_REPORTED ) {
				$record['failures'][] = array(
					'post_id' => $post_id,
					'title'   => $title,
					'field'   => (string) $path,
					'code'    => (string) $code,
					'message' => self::error_message( (string) $code ),
				);
			}
		}
		if ( ! $result['errors'] ) {
			$record['imported']++;
		}

		// Vérification : même valeur servie qu'avec ACF, champ par champ.
		$after = $native->get_content( $post_id );
		foreach ( $before as $name => $value ) {
			if ( ! array_key_exists( $name, $after ) || isset( $result['errors'][ $name ] ) ) {
				continue; // Champ non repris (aperçu) ou en échec (déjà listé).
			}
			if ( self::canonical( $value ) === self::canonical( $after[ $name ] ) ) {
				continue;
			}
			$record['different']++;
			if ( count( $record['diffs'] ) < self::MAX_REPORTED ) {
				$record['diffs'][] = array(
					'post_id' => $post_id,
					'title'   => $title,
					'field'   => (string) $name,
					'before'  => self::excerpt( self::canonical( $value ) ),
					'after'   => self::excerpt( self::canonical( $after[ $name ] ) ),
				);
			}
		}
	}

	private static function excerpt( $json ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $json ) : strlen( $json );
		if ( $length <= 160 ) {
			return $json;
		}
		return ( function_exists( 'mb_substr' ) ? mb_substr( $json, 0, 157 ) : substr( $json, 0, 157 ) ) . '…';
	}

	/**
	 * Message lisible pour un code d'erreur de l'éditeur (« required:titre »).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function error_message( $code ) {
		$parts = explode( ':', $code, 2 );
		$messages = array(
			'required'           => __( 'A required value is empty.', 'leiho' ),
			'too_long'           => __( 'The text is longer than the maximum length.', 'leiho' ),
			'invalid_choice'     => __( 'The value isn’t one of the choices.', 'leiho' ),
			'invalid_attachment' => __( 'The media can’t be used.', 'leiho' ),
			'invalid_reference'  => __( 'A linked entry doesn’t exist or isn’t of an allowed type.', 'leiho' ),
			'invalid_post'       => __( 'A linked entry doesn’t exist.', 'leiho' ),
			'invalid_number'     => __( 'The number is invalid.', 'leiho' ),
			'out_of_range'       => __( 'The number is out of range.', 'leiho' ),
			'invalid_date'       => __( 'The date is invalid.', 'leiho' ),
			'invalid_email'      => __( 'The email address is invalid.', 'leiho' ),
			'invalid_color'      => __( 'The color is invalid.', 'leiho' ),
			'invalid_link'       => __( 'The link is invalid.', 'leiho' ),
			'too_many_rows'      => __( 'There are more rows than allowed.', 'leiho' ),
			'too_few_rows'       => __( 'There are fewer rows than required.', 'leiho' ),
			'too_many_items'     => __( 'There are more items than allowed.', 'leiho' ),
			'unknown_layout'     => __( 'A layout no longer exists.', 'leiho' ),
			'save_failed'        => __( 'The database refused the value.', 'leiho' ),
		);
		$base     = preg_replace( '/:.*$/', '', $parts[0] );
		$message  = isset( $messages[ $base ] ) ? $messages[ $base ] : __( 'The value was refused.', 'leiho' );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			/* translators: 1: error message, 2: sub-field name. */
			$message = sprintf( __( '%1$s (sub-field “%2$s”)', 'leiho' ), $message, $parts[1] );
		}
		return $message;
	}

	/* ---------------------------------------------------------------------
	 * Retour à ACF
	 * ------------------------------------------------------------------- */

	/**
	 * Rend le type à ACF : bascule annulée, collection retirée du schéma,
	 * table Leiho supprimée. Les valeurs ACF n'ont jamais bougé.
	 *
	 * @param string $post_type Type.
	 * @return array|WP_Error { post_type, dropped }
	 */
	public static function rollback( $post_type ) {
		$record = self::record( $post_type );
		if ( ! $record ) {
			return new WP_Error( 'leiho_not_imported', __( 'This content type wasn’t imported from ACF.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( ! self::acf_available() ) {
			return new WP_Error( 'leiho_no_acf', __( 'Activate ACF (or Secure Custom Fields) first: it serves the fields again after going back.', 'leiho' ), array( 'status' => 400 ) );
		}

		if ( Leiho_Collections::get( $post_type ) ) {
			$report = Leiho_Collections::delete_collection( $post_type );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
		}
		$dropped = Leiho_Collections_Store::drop( $post_type );
		self::save_record( $post_type, null );

		if ( class_exists( 'Leiho_Content' ) ) {
			Leiho_Content::invalidate();
		}
		/**
		 * Un type importé est rendu à ACF.
		 *
		 * @param string $post_type Type.
		 * @param array  $record    Enregistrement de l'import.
		 */
		do_action( 'leiho_acf_import_rolled_back', $post_type, $record );

		return array(
			'post_type' => $post_type,
			'dropped'   => (bool) $dropped,
		);
	}

	/* ---------------------------------------------------------------------
	 * REST : leiho/v1/collections/import-acf
	 * ------------------------------------------------------------------- */

	/**
	 * GET  /collections/import-acf                       types et état
	 * GET  /collections/import-acf/preview?post_type=    aperçu
	 * POST /collections/import-acf/run      {post_type}  démarre, puis copie un lot par appel
	 * GET  /collections/import-acf/status?post_type=     état d'un import
	 * POST /collections/import-acf/accept   {post_type, confirm}  bascule un import à examiner
	 * POST /collections/import-acf/rollback {post_type, confirm}
	 *
	 * @return void
	 */
	public static function register_routes() {
		$admin = array( 'Leiho_Collections_Rest', 'can_manage' );
		$type  = array(
			'post_type' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$base  = '/collections/import-acf';

		register_rest_route(
			Leiho_Rest::NS,
			$base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_overview' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			$base . '/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_preview' ),
				'permission_callback' => $admin,
				'args'                => $type,
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			$base . '/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_run' ),
				'permission_callback' => $admin,
				'args'                => $type,
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			$base . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => $admin,
				'args'                => $type,
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			$base . '/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_accept' ),
				'permission_callback' => $admin,
				'args'                => $type,
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			$base . '/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_rollback' ),
				'permission_callback' => $admin,
				'args'                => $type,
			)
		);
	}

	public static function rest_overview() {
		return rest_ensure_response( self::overview() );
	}

	public static function rest_preview( WP_REST_Request $request ) {
		return rest_ensure_response( self::preview( sanitize_key( (string) $request->get_param( 'post_type' ) ) ) );
	}

	/**
	 * Premier appel : crée la collection. Appels suivants : un lot chacun.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_run( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$record    = self::record( $post_type );
		/**
		 * Entrées copiées par appel de l'import ACF (une requête par lot).
		 *
		 * @param int    $batch     Défaut : 25.
		 * @param string $post_type Type importé.
		 */
		$batch  = max( 1, (int) apply_filters( 'leiho_acf_import_batch', self::BATCH, $post_type ) );
		$result = $record ? self::step( $post_type, $batch ) : self::start( $post_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::public_record( $post_type, $result ) );
	}

	public static function rest_status( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$record    = self::record( $post_type );
		if ( ! $record ) {
			return new WP_Error( 'leiho_not_imported', __( 'This content type wasn’t imported from ACF.', 'leiho' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::public_record( $post_type, $record ) );
	}

	public static function rest_accept( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( $post_type !== (string) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'leiho_confirm', __( 'Set confirm to the content type to switch it to Leiho despite the failures and differences of its import.', 'leiho' ), array( 'status' => 400 ) );
		}
		$result = self::accept( $post_type );
		return is_wp_error( $result ) ? $result : rest_ensure_response( self::public_record( $post_type, $result ) );
	}

	public static function rest_rollback( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( $post_type !== (string) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'leiho_confirm', __( 'Set confirm to the content type to go back to ACF. The Leiho values of this type are deleted.', 'leiho' ), array( 'status' => 400 ) );
		}
		$result = self::rollback( $post_type );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Enregistrement sans les arguments du type (internes).
	 *
	 * @param string $post_type Type.
	 * @param array  $record    Enregistrement.
	 * @return array
	 */
	private static function public_record( $post_type, $record ) {
		unset( $record['args'] );
		$record['post_type'] = $post_type;
		$record['started']   = gmdate( 'c', (int) $record['started'] );
		$record['finished']  = $record['finished'] ? gmdate( 'c', (int) $record['finished'] ) : null;
		if ( ! empty( $record['accepted'] ) ) {
			$record['accepted'] = gmdate( 'c', (int) $record['accepted'] );
		}
		$record['needs_review'] = self::STATUS_REVIEW === $record['status'];
		return $record;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Importe des champs ACF dans Leiho Collections.
	 *
	 * ## EXAMPLES
	 *
	 *     wp leiho acf-import list
	 *     wp leiho acf-import preview ville
	 *     wp leiho acf-import run ville
	 *     wp leiho acf-import accept ville --yes
	 *     wp leiho acf-import rollback ville --yes
	 */
	class Leiho_ACF_Import_Command extends WP_CLI_Command {

		/**
		 * Content types with ACF fields and their import state.
		 *
		 * @subcommand list
		 */
		public function list_( $args, $assoc ) {
			$rows = array();
			foreach ( Leiho_ACF_Import::overview()['types'] as $type ) {
				$rows[] = array(
					'post_type' => $type['post_type'],
					'origin'    => $type['origin'],
					'groups'    => $type['groups'],
					'entries'   => $type['entries'],
					'status'    => (string) $type['status'],
					'note'      => $type['reason'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'post_type', 'origin', 'groups', 'entries', 'status', 'note' ) );
		}

		/**
		 * What an import would do, as JSON.
		 *
		 * <post_type>
		 * : Content type.
		 */
		public function preview( $args ) {
			WP_CLI::line( (string) wp_json_encode( Leiho_ACF_Import::preview( sanitize_key( $args[0] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}

		/**
		 * Imports a content type (resumes an interrupted import).
		 *
		 * <post_type>
		 * : Content type.
		 *
		 * [--batch=<n>]
		 * : Entries per batch. Default 100.
		 *
		 * [--accept]
		 * : Switch to Leiho even if some values failed or differ. Without it, such an import waits for review and ACF keeps serving the type.
		 */
		public function run( $args, $assoc ) {
			$type   = sanitize_key( $args[0] );
			$batch  = isset( $assoc['batch'] ) ? max( 1, (int) $assoc['batch'] ) : 100;
			$record = Leiho_ACF_Import::record( $type ) ? Leiho_ACF_Import::record( $type ) : Leiho_ACF_Import::start( $type );
			while ( ! is_wp_error( $record ) && ! Leiho_ACF_Import::finished( $record ) ) {
				$record = Leiho_ACF_Import::step( $type, $batch );
				if ( ! is_wp_error( $record ) ) {
					WP_CLI::log( sprintf( '%d / %d', $record['done'], $record['total'] ) );
				}
			}
			if ( is_wp_error( $record ) ) {
				WP_CLI::error( $record->get_error_message() );
			}
			foreach ( $record['failures'] as $failure ) {
				WP_CLI::warning( sprintf( '#%d %s: %s', $failure['post_id'], $failure['field'], $failure['message'] ) );
			}
			foreach ( $record['diffs'] as $diff ) {
				WP_CLI::warning( sprintf( '#%d %s differs: %s → %s', $diff['post_id'], $diff['field'], $diff['before'], $diff['after'] ) );
			}
			foreach ( isset( $record['limits'] ) ? (array) $record['limits'] : array() as $limit ) {
				WP_CLI::log( sprintf( 'Limit widened to fit existing values: %s %s %s → %s (%d entries).', $limit['path'], $limit['rule'], $limit['limit'], $limit['found'], $limit['count'] ) );
			}
			if ( 'review' === $record['status'] && ! empty( $assoc['accept'] ) ) {
				$record = Leiho_ACF_Import::accept( $type );
				if ( is_wp_error( $record ) ) {
					WP_CLI::error( $record->get_error_message() );
				}
			}
			$summary = sprintf( '%d entries, %d imported, %d failures, %d differences.', $record['done'], $record['imported'], $record['failed'], $record['different'] );
			if ( 'review' === $record['status'] ) {
				WP_CLI::error( sprintf( '%s Not switched to Leiho: ACF still serves %s. Fix the values in ACF, then `wp leiho acf-import rollback %s --yes` and run again; or switch anyway with `wp leiho acf-import accept %s`.', $summary, $type, $type, $type ) );
			}
			WP_CLI::success( $summary . ' ' . sprintf( '%s is served by Leiho.', $type ) );
		}

		/**
		 * Switches an import that waits for review to Leiho, despite its failures and differences.
		 *
		 * <post_type>
		 * : Content type.
		 *
		 * [--yes]
		 * : Skip the confirmation.
		 */
		public function accept( $args, $assoc ) {
			$type   = sanitize_key( $args[0] );
			$record = Leiho_ACF_Import::record( $type );
			if ( $record ) {
				WP_CLI::confirm( sprintf( 'Switch %s to Leiho? %d failures (left empty) and %d differences are served as Leiho kept them.', $type, $record['failed'], $record['different'] ), $assoc );
			}
			$result = Leiho_ACF_Import::accept( $type );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%s is served by Leiho.', $type ) );
		}

		/**
		 * Goes back to ACF and deletes the Leiho values of the type.
		 *
		 * <post_type>
		 * : Content type.
		 *
		 * [--yes]
		 * : Skip the confirmation.
		 */
		public function rollback( $args, $assoc ) {
			$type = sanitize_key( $args[0] );
			WP_CLI::confirm( sprintf( 'Go back to ACF for %s? Values edited in Leiho since the import are lost.', $type ), $assoc );
			$result = Leiho_ACF_Import::rollback( $type );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( '%s is served by ACF again.', $type ) );
		}
	}
}
