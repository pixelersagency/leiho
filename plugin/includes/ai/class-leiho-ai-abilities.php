<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capacités d'Leiho pour l'Abilities API de WordPress (6.9+) : les agents
 * d'IA externes (adaptateur MCP, agents WordPress) lisent le contexte de
 * marque, les types de contenu et les entrées, et modifient les champs d'un
 * brouillon par le même chemin d'écriture que l'éditeur (validation,
 * journal). Aucune ne publie ; aucune ne touche un contenu en ligne.
 *
 * Elles n'appellent aucun modèle : elles restent disponibles sans
 * fournisseur d'IA configuré.
 */
class Leiho_AI_Abilities {

	const CATEGORY = 'leiho';

	/** Statuts qu'une capacité peut modifier (brouillons). */
	const DRAFT_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Catégorie « leiho ».
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Leiho', 'leiho' ),
				'description' => __( 'Read and edit the content managed with Leiho: brand context, content types, entries and their fields.', 'leiho' ),
			)
		);
	}

	/**
	 * Définitions des capacités (nom => arguments de wp_register_ability).
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$readonly = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$meta     = static function ( $annotations ) {
			return array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			);
		};

		return array(
			'leiho/get-brand-context'   => array(
				'label'               => __( 'Get the brand context', 'leiho' ),
				'description'         => __( 'Returns the brand context set in Leiho: name, offer, audience, tone of voice, words to prefer and avoid, forbidden claims (never write them), mandatory facts, language and sample texts. Pass a content type to get its own context too. Read it before writing any content for this site.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Optional content type (for example page or service) whose own context should be included.', 'leiho' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_brand_context' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/list-collections'    => array(
				'label'               => __( 'List the content types', 'leiho' ),
				'description'         => __( 'Lists the content types editable in Leiho (pages, ACF types, Leiho collections) with their fields: path, type, label, required, maximum length, choices, sub-fields and block layouts. Field paths are the keys to use with leiho/update-entry-fields.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_fields' => array(
							'type'        => 'boolean',
							'description' => __( 'Include the field definitions (default true).', 'leiho' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => array( __CLASS__, 'list_collections' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/get-entry'           => array(
				'label'               => __( 'Get an entry', 'leiho' ),
				'description'         => __( 'Returns one entry (page, post, collection item) with its title, status, revision and the current value of each field, by path.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Entry ID.', 'leiho' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_entry' ),
				'permission_callback' => array( __CLASS__, 'can_edit_entry' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/update-entry-fields' => array(
				'label'               => __( 'Update the fields of a draft', 'leiho' ),
				'description'         => __( 'Writes field values of a draft or pending entry, by path (for example _title, hero_title, sections.0.text). Values are validated like in the Leiho editor (types, required fields, maximum lengths); refused fields are returned in errors. Never publishes, and refuses entries that are published, scheduled or private. Pass the revision from leiho/get-entry to avoid overwriting someone else’s changes.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'Entry ID.', 'leiho' ),
							'minimum'     => 1,
						),
						'values'   => array(
							'type'                 => 'object',
							'description'          => __( 'Field values by path.', 'leiho' ),
							'additionalProperties' => true,
						),
						'revision' => array(
							'type'        => 'string',
							'description' => __( 'Revision returned by leiho/get-entry (optional, recommended).', 'leiho' ),
						),
					),
					'required'             => array( 'id', 'values' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'update_entry_fields' ),
				'permission_callback' => array( __CLASS__, 'can_update_entry' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
		);
	}

	/**
	 * Enregistre les capacités.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------- */

	/**
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'edit_posts' ) && Leiho_AI::user_can();
	}

	/**
	 * @param mixed $input Entrée.
	 * @return bool
	 */
	public static function can_edit_entry( $input = null ) {
		$id = is_array( $input ) && isset( $input['id'] ) ? (int) $input['id'] : 0;
		return $id > 0 && Leiho_AI::user_can() && current_user_can( 'edit_post', $id ) && self::is_leiho_type( $id );
	}

	/**
	 * Modifier les champs : droit sur l'entrée. Le statut (brouillon) est
	 * vérifié à l'exécution, pour que l'agent reçoive la raison du refus
	 * (WordPress ne transmet pas l'erreur d'une vérification de permission).
	 *
	 * @param mixed $input Entrée.
	 * @return bool
	 */
	public static function can_update_entry( $input = null ) {
		return self::can_edit_entry( $input );
	}

	/**
	 * Statut modifiable par un agent.
	 *
	 * @param string $status Statut.
	 * @return bool
	 */
	public static function is_draft( $status ) {
		/**
		 * Statuts qu'une capacité d'Leiho peut modifier.
		 *
		 * @param string[] $statuses Par défaut draft, pending, auto-draft.
		 */
		$allowed = (array) apply_filters( 'leiho_ai_ability_statuses', self::DRAFT_STATUSES );
		return in_array( (string) $status, $allowed, true );
	}

	/**
	 * Le contenu est-il d'un type édité dans Leiho ?
	 *
	 * @param int $id Contenu.
	 * @return bool
	 */
	private static function is_leiho_type( $id ) {
		$settings = Leiho_Plugin::settings();
		return in_array( get_post_type( $id ), (array) $settings['post_types'], true );
	}

	/* ---------------------------------------------------------------------
	 * Exécution
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $input { post_type }.
	 * @return array
	 */
	public static function get_brand_context( $input = null ) {
		$brand = Leiho_AI_Brand::get();
		unset( $brand['collections'] );
		$out = array(
			'brand'        => $brand,
			'instructions' => Leiho_AI_Prompt::system_instruction( $brand ),
		);
		$type = is_array( $input ) && isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		if ( '' !== $type ) {
			$context            = Leiho_AI_Brand::collection( $type );
			$out['collection']  = $context;
			$out['instructions'] = Leiho_AI_Prompt::system_instruction( $brand, $context );
		}
		return $out;
	}

	/**
	 * @param mixed $input { include_fields }.
	 * @return array
	 */
	public static function list_collections( $input = null ) {
		$fields = ! ( is_array( $input ) && isset( $input['include_fields'] ) && false === $input['include_fields'] );
		$out    = array();
		foreach ( Leiho_App::content_types() as $type ) {
			$item = array(
				'name'       => $type['name'],
				'label'      => $type['label'],
				'singular'   => $type['singular'],
				'collection' => $type['collection'],
				'singleton'  => $type['singleton'],
				'has_pages'  => $type['hasPages'],
				'can_create' => $type['canCreate'],
			);
			$context = Leiho_AI_Brand::collection( $type['name'] );
			if ( '' !== $context['purpose'] || '' !== $context['audience'] ) {
				$item['context'] = array(
					'purpose'  => $context['purpose'],
					'audience' => $context['audience'],
				);
			}
			if ( $fields ) {
				$item['fields'] = self::public_fields( Leiho_AI::type_fields( $type['name'] ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * @param mixed $input { id }.
	 * @return array|WP_Error
	 */
	public static function get_entry( $input = null ) {
		$schema = ( new Leiho_Schema( Leiho_Plugin::instance() ) )->get( (int) $input['id'] );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$fields = array();
		foreach ( $schema['groups'] as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( isset( $field['supported'] ) && false === $field['supported'] ) {
					continue;
				}
				$fields[] = self::public_field( $field, true );
			}
		}
		$post = get_post( (int) $input['id'] );
		return array(
			'id'       => (int) $schema['post_id'],
			'type'     => $post ? $post->post_type : '',
			'title'    => $schema['title'],
			'status'   => $schema['status'],
			'revision' => $schema['revision'],
			'editable' => self::is_draft( $schema['status'] ),
			'fields'   => $fields,
		);
	}

	/**
	 * @param mixed $input { id, values, revision }.
	 * @return array|WP_Error
	 */
	public static function update_entry_fields( $input = null ) {
		$id     = (int) $input['id'];
		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		$post   = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_draft( $post->post_status ) ) {
			return new WP_Error(
				'leiho_not_draft',
				__( 'This entry is published, scheduled or private: changes would go live. Only drafts and pending entries can be edited by an agent.', 'leiho' ),
				array( 'status' => 403 )
			);
		}
		if ( ! $values ) {
			return new WP_Error( 'leiho_bad_request', __( 'Nothing to save.', 'leiho' ), array( 'status' => 400 ) );
		}
		// Le statut ne change jamais par ici.
		unset( $values['_status'] );

		$revision = isset( $input['revision'] ) ? (string) $input['revision'] : '';
		if ( '' !== $revision && Leiho_Schema::revision( $id ) !== $revision ) {
			return new WP_Error( 'leiho_conflict', __( 'This content was changed elsewhere since you read it. Read it again with leiho/get-entry.', 'leiho' ), array( 'status' => 409 ) );
		}

		set_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );
		$result = ( new Leiho_Schema( Leiho_Plugin::instance() ) )->save( $id, $values );
		delete_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE );

		if ( $result['saved'] ) {
			Leiho_Activity::record_ai(
				$id,
				implode( ', ', array_slice( $result['saved'], 0, 5 ) ),
				/* translators: %d: number of fields. */
				sprintf( _n( '%d field', '%d fields', count( $result['saved'] ), 'leiho' ), count( $result['saved'] ) ),
				'ability'
			);
		}

		return array(
			'saved'    => array_values( $result['saved'] ),
			'errors'   => (object) $result['errors'],
			'revision' => Leiho_Schema::revision( $id ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Champs lisibles par un agent
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $fields Champs (format éditeur).
	 * @return array
	 */
	public static function public_fields( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( isset( $field['supported'] ) && false === $field['supported'] ) {
				continue;
			}
			$out[] = self::public_field( $field, false );
		}
		return $out;
	}

	/**
	 * Champ sans les données d'affichage de l'éditeur.
	 *
	 * @param array $field      Champ.
	 * @param bool  $with_value Avec sa valeur.
	 * @return array
	 */
	private static function public_field( array $field, $with_value ) {
		$out = array(
			'path'  => (string) $field['path'],
			'type'  => (string) $field['type'],
			'label' => isset( $field['label'] ) ? (string) $field['label'] : (string) $field['path'],
		);
		foreach ( array( 'required', 'max_length', 'help', 'multiple', 'min', 'max', 'readonly' ) as $key ) {
			if ( isset( $field[ $key ] ) && '' !== $field[ $key ] && false !== $field[ $key ] && 0 !== $field[ $key ] ) {
				$out[ $key ] = $field[ $key ];
			}
		}
		if ( 'select' === $field['type'] && ! empty( $field['options'] ) ) {
			$out['choices'] = $field['options'];
		}
		if ( ! empty( $field['sub_fields'] ) ) {
			$out['sub_fields'] = self::public_fields( $field['sub_fields'] );
		}
		if ( ! empty( $field['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$out['layouts'][] = array(
					'name'       => $layout['name'],
					'label'      => isset( $layout['label'] ) ? $layout['label'] : $layout['name'],
					'sub_fields' => self::public_fields( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() ),
				);
			}
		}
		if ( $with_value ) {
			$out['value'] = isset( $field['value'] ) ? $field['value'] : null;
		}
		return $out;
	}
}
