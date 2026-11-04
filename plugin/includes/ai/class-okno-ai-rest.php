<?php
defined( 'ABSPATH' ) || exit;

/**
 * API REST de l'IA : okno/v1/ai/*.
 *
 * Chaque route vérifie le nonce REST (cookie wp_rest), la capability
 * d'IA (okno_ai_capability) et le droit sur le contenu visé ; les entrées
 * sont bornées, les générations comptées par utilisateur. Rien n'est
 * enregistré dans le contenu ici : le texte proposé revient à l'éditeur,
 * qui l'enregistre comme une saisie (sauf « Créer le brouillon » d'une
 * nouvelle entrée, qui passe par le même chemin d'écriture que l'éditeur).
 */
class Okno_AI_Rest {

	/** Bornes des entrées. */
	const MAX_BODY        = 200000;
	const MAX_VALUE       = 20000;
	const MAX_INSTRUCTION = 1000;
	const MAX_BRIEF       = 4000;

	/** Types de champ sur lesquels agissent les actions de texte. */
	const TEXT_TYPES = array( 'text', 'textarea', 'wysiwyg' );

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$ns = Okno_Rest::NS;

		register_rest_route(
			$ns,
			'/ai/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			$ns,
			'/ai/field',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'field' ),
				'permission_callback' => array( __CLASS__, 'can_edit_field' ),
				'args'                => array(
					'post_id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'path'        => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 191,
					),
					'action'      => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Okno_AI_Prompt::ACTIONS,
					),
					'value'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'instruction' => array(
						'type'    => 'string',
						'default' => '',
					),
					'tone'        => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 100,
					),
					'language'    => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 60,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/alt',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'alt' ),
				'permission_callback' => array( __CLASS__, 'can_edit_attachment' ),
				'args'                => array(
					'attachment_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'post_id'       => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'context'       => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 500,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/entry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'entry' ),
				'permission_callback' => array( __CLASS__, 'can_create_entry' ),
				'args'                => array(
					'post_type' => array(
						'type'     => 'string',
						'required' => true,
					),
					'brief'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/entry/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_entry' ),
				'permission_callback' => array( __CLASS__, 'can_create_entry' ),
				'args'                => array(
					'post_type' => array(
						'type'     => 'string',
						'required' => true,
					),
					'values'    => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/brand',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_brand' ),
					'permission_callback' => array( __CLASS__, 'can_manage_brand' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_brand' ),
					'permission_callback' => array( __CLASS__, 'can_manage_brand' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/collections/(?P<type>[a-z0-9_\-]{1,20})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_collection' ),
					'permission_callback' => array( __CLASS__, 'can_manage_collection' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_collection' ),
					'permission_callback' => array( __CLASS__, 'can_manage_collection' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------- */

	/**
	 * IA autorisée pour l'utilisateur et requête de taille raisonnable.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return true|WP_Error
	 */
	private static function can_use_ai( $request ) {
		if ( ! Okno_AI::user_can() ) {
			return new WP_Error( 'okno_forbidden', __( 'You’re not allowed to use AI here.', 'okno' ), array( 'status' => 403 ) );
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'okno_ai_too_large', __( 'This request is too large for the AI.', 'okno' ), array( 'status' => 413 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return bool|WP_Error
	 */
	public static function can_edit_field( $request ) {
		$ok = self::can_use_ai( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		return current_user_can( 'edit_post', (int) $request['post_id'] );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return bool|WP_Error
	 */
	public static function can_edit_attachment( $request ) {
		$ok = self::can_use_ai( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $request['attachment_id'];
		return 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return bool|WP_Error
	 */
	public static function can_create_entry( $request ) {
		$ok = self::can_use_ai( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$type = sanitize_key( (string) $request['post_type'] );
		return '' !== $type && ( new Okno_Rest( Okno_Plugin::instance() ) )->can_create_type( $type );
	}

	/**
	 * @return bool
	 */
	public static function can_manage_brand() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage_collection() {
		return Okno_Collections_Rest::can_manage();
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------- */

	/**
	 * État de l'IA.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		return rest_ensure_response( Okno_AI::client_config() );
	}

	/**
	 * Action sur un champ texte : proposition à prévisualiser.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function field( $request ) {
		$post_id     = (int) $request['post_id'];
		$path        = (string) $request['path'];
		$action      = (string) $request['action'];
		$value       = (string) $request['value'];
		$instruction = trim( (string) $request['instruction'] );

		if ( strlen( $value ) > self::MAX_VALUE * 4 || self::mb_len( $value ) > self::MAX_VALUE ) {
			return new WP_Error( 'okno_ai_too_long', __( 'This text is too long for the AI. Shorten it first.', 'okno' ), array( 'status' => 413 ) );
		}
		if ( self::mb_len( $instruction ) > self::MAX_INSTRUCTION ) {
			return new WP_Error( 'okno_ai_too_long', __( 'The instruction is too long.', 'okno' ), array( 'status' => 413 ) );
		}
		if ( 'generate' === $action && '' === $instruction ) {
			return new WP_Error( 'okno_ai_bad_request', __( 'Say what to write.', 'okno' ), array( 'status' => 400 ) );
		}
		if ( in_array( $action, Okno_AI_Prompt::NEEDS_TEXT, true ) && '' === trim( Okno_AI_Prompt::plain( $value ) ) ) {
			return new WP_Error( 'okno_ai_bad_request', __( 'This field is empty: there’s nothing to work from.', 'okno' ), array( 'status' => 400 ) );
		}

		$schema = ( new Okno_Schema( Okno_Plugin::instance() ) )->get( $post_id );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		$field = self::field_def( $schema, $path, $request->get_param( 'field' ) );
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		if ( 'suggest' === $action && '' === $field['seo'] ) {
			$field['seo'] = 'description';
		}

		$throttle = Okno_AI::throttle( get_current_user_id() );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		// Contexte : les valeurs en cours dans l'éditeur (non enregistrées
		// comprises), sinon celles de la base.
		$context = Okno_AI_Prompt::normalize_context( $request->get_param( 'context' ) );
		if ( ! $context ) {
			$context = Okno_AI_Prompt::entry_texts( $schema );
		}

		$brand  = Okno_AI_Brand::get();
		$system = Okno_AI_Prompt::system_instruction( $brand, Okno_AI_Brand::collection( (string) get_post_type( $post_id ) ) );
		$prompt = Okno_AI_Prompt::field_prompt(
			$action,
			array(
				'field'       => $field,
				'value'       => 'html' === Okno_AI_Prompt::field_spec( $field )['format'] ? $value : Okno_AI_Prompt::plain( $value, 'text' !== $field['type'] ),
				'instruction' => $instruction,
				'tone'        => sanitize_text_field( (string) $request['tone'] ),
				'language'    => sanitize_text_field( (string) $request['language'] ),
				'context'     => $context,
			)
		);

		$raw = Okno_AI::generate( $system, $prompt );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		Okno_Activity::record_ai( $post_id, $path, $field['label'], $action );

		return rest_ensure_response( self::proposal( $raw, $field, $brand ) );
	}

	/**
	 * Proposition prête pour l'aperçu : texte nettoyé et contrôles.
	 *
	 * @param string $raw   Réponse du modèle.
	 * @param array  $field Champ.
	 * @param array  $brand Contexte de marque.
	 * @return array
	 */
	public static function proposal( $raw, array $field, array $brand ) {
		$spec = Okno_AI_Prompt::field_spec( $field );
		$text = Okno_AI_Prompt::clean_output( $raw, $spec );
		if ( 'html' === $spec['format'] ) {
			$text = wp_kses_post( $text );
		}
		return array(
			'text'   => $text,
			'format' => $spec['format'],
			'max'    => $spec['max'],
			'check'  => Okno_AI_Prompt::check( $text, $brand, $spec['max'] ),
		);
	}

	/**
	 * Définition du champ visé : celle du schéma enregistré (autorité du
	 * serveur pour le type et la longueur), sinon — ligne ajoutée dans
	 * l'éditeur et pas encore enregistrée — les indications du navigateur,
	 * limitées aux types texte.
	 *
	 * @param array  $schema Schéma du contenu.
	 * @param string $path   Chemin pointé.
	 * @param mixed  $hint   Définition envoyée par le navigateur.
	 * @return array|WP_Error { path, type, label, help, max_length, required, seo }
	 */
	public static function field_def( array $schema, $path, $hint = null ) {
		$found = Okno_Activity::lookup( $schema, $path );
		$def   = is_array( $found['field'] ) ? $found['field'] : null;
		if ( null === $def && is_array( $hint ) ) {
			$def = array(
				'type'       => isset( $hint['type'] ) ? sanitize_key( (string) $hint['type'] ) : 'text',
				'label'      => isset( $hint['label'] ) ? sanitize_text_field( (string) $hint['label'] ) : $path,
				'help'       => isset( $hint['help'] ) ? sanitize_text_field( (string) $hint['help'] ) : '',
				'max_length' => isset( $hint['max_length'] ) ? absint( $hint['max_length'] ) : 0,
				'required'   => ! empty( $hint['required'] ),
			);
			$found['label'] = $def['label'];
		}
		if ( null === $def || ! in_array( isset( $def['type'] ) ? $def['type'] : '', self::TEXT_TYPES, true ) || ! empty( $def['readonly'] ) || ! empty( $def['blocks'] ) ) {
			return new WP_Error( 'okno_ai_bad_field', __( 'AI can only write in text fields.', 'okno' ), array( 'status' => 400 ) );
		}
		$label = (string) ( '' !== (string) $found['label'] ? $found['label'] : ( isset( $def['label'] ) ? $def['label'] : $path ) );
		return array(
			'path'       => (string) $path,
			'type'       => $def['type'],
			'label'      => $label,
			'help'       => isset( $def['help'] ) ? (string) $def['help'] : '',
			'max_length' => isset( $def['max_length'] ) ? (int) $def['max_length'] : 0,
			'required'   => ! empty( $def['required'] ),
			'seo'        => Okno_AI_Prompt::seo_kind( $path, isset( $def['label'] ) ? (string) $def['label'] : '' ),
		);
	}

	/**
	 * Texte alternatif d'une image.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function alt( $request ) {
		$status = Okno_AI::status();
		if ( ! $status['image'] ) {
			return new WP_Error( 'okno_ai_no_image', __( 'The configured AI provider can’t read images.', 'okno' ), array( 'status' => 501 ) );
		}
		$id    = (int) $request['attachment_id'];
		$image = Okno_AI::attachment_image( $id );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$throttle = Okno_AI::throttle( get_current_user_id() );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}
		$brand  = Okno_AI_Brand::get();
		$post   = get_post( $id );
		$prompt = Okno_AI_Prompt::alt_prompt(
			array(
				'title'   => $post ? $post->post_title : '',
				'caption' => $post ? $post->post_excerpt : '',
				'context' => sanitize_text_field( (string) $request['context'] ),
			)
		);
		$raw = Okno_AI::generate( Okno_AI_Prompt::system_instruction( $brand ), $prompt, array( 'image' => $image ) );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$post_id = (int) $request['post_id'];
		// Rattachée à l'entrée éditée, sinon à l'image elle-même.
		Okno_Activity::record_ai( $post_id && current_user_can( 'edit_post', $post_id ) ? $post_id : $id, 'alt:' . $id, $post ? $post->post_title : (string) $id, 'alt' );

		$field = array(
			'type'       => 'text',
			'label'      => 'alt',
			'max_length' => 0,
		);
		return rest_ensure_response( self::proposal( $raw, $field, $brand ) );
	}

	/**
	 * Nouvelle entrée : valeurs proposées pour tous les champs du type.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function entry( $request ) {
		$type  = sanitize_key( (string) $request['post_type'] );
		$brief = trim( sanitize_textarea_field( (string) $request['brief'] ) );
		if ( '' === $brief ) {
			return new WP_Error( 'okno_ai_bad_request', __( 'Describe the entry to write.', 'okno' ), array( 'status' => 400 ) );
		}
		if ( self::mb_len( $brief ) > self::MAX_BRIEF ) {
			return new WP_Error( 'okno_ai_too_long', __( 'The brief is too long.', 'okno' ), array( 'status' => 413 ) );
		}
		$throttle = Okno_AI::throttle( get_current_user_id() );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$result = self::generate_entry( $type, $brief );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Journalisée à la création du brouillon (rattachée à l'entrée).
		return rest_ensure_response( $result );
	}

	/**
	 * Génère les valeurs d'une nouvelle entrée.
	 *
	 * @param string $type  Type de contenu.
	 * @param string $brief Demande.
	 * @return array|WP_Error { values, fields, warnings }
	 */
	public static function generate_entry( $type, $brief ) {
		$fields   = Okno_AI_Prompt::fillable( Okno_AI::type_fields( $type ) );
		$brand    = Okno_AI_Brand::get();
		$context  = Okno_AI_Brand::collection( $type );
		$object   = get_post_type_object( $type );
		$singular = $object ? $object->labels->singular_name : $type;

		$raw = Okno_AI::generate(
			Okno_AI_Prompt::system_instruction( $brand, $context ),
			Okno_AI_Prompt::entry_prompt( $brief, $fields, $singular ),
			array( 'schema' => Okno_AI_Prompt::entry_schema( $fields ) )
		);
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$data = Okno_AI_Prompt::decode_json( $raw );
		if ( null === $data ) {
			return new WP_Error( 'okno_ai_bad_json', __( 'The AI answer couldn’t be read. Try again.', 'okno' ), array( 'status' => 502 ) );
		}
		$warnings = array();
		$values   = Okno_AI_Prompt::map_values( $fields, $data, $brand, 'wp_kses_post', $warnings );
		if ( ! $values ) {
			return new WP_Error( 'okno_ai_empty', __( 'The AI didn’t fill any field. Give more details and try again.', 'okno' ), array( 'status' => 502 ) );
		}
		return array(
			'values'   => (object) $values,
			'fields'   => $fields,
			'warnings' => (object) $warnings,
		);
	}

	/**
	 * Crée le brouillon d'une nouvelle entrée avec les valeurs acceptées,
	 * par le chemin d'écriture de l'éditeur (validation, journal).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_entry( $request ) {
		$type   = sanitize_key( (string) $request['post_type'] );
		$values = $request['values'];
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'okno_bad_request', __( 'Nothing to save.', 'okno' ), array( 'status' => 400 ) );
		}
		$title = isset( $values[ Okno_Schema::PATH_TITLE ] ) ? sanitize_text_field( (string) $values[ Okno_Schema::PATH_TITLE ] ) : '';
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'okno_bad_request', __( 'A title is required.', 'okno' ), array( 'status' => 400 ) );
		}
		unset( $values[ Okno_Schema::PATH_TITLE ] );

		// Seuls les champs que l'IA a le droit de remplir sont écrits.
		$allowed = array();
		foreach ( Okno_AI_Prompt::fillable( Okno_AI::type_fields( $type ) ) as $field ) {
			$allowed[ $field['path'] ] = true;
		}
		$values = array_intersect_key( $values, $allowed );

		$post_id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			$post_id->add_data( array( 'status' => 500 ) );
			return $post_id;
		}

		$result = array(
			'saved'  => array(),
			'errors' => array(),
		);
		if ( $values ) {
			set_transient( Okno_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );
			$result = ( new Okno_Schema( Okno_Plugin::instance() ) )->save( $post_id, $values );
			delete_transient( Okno_Deploy_Manager::TRANSIENT_SAVE );
		}
		Okno_Activity::record_ai( $post_id, Okno_Schema::PATH_TITLE, $title, 'entry' );
		Okno_Plugin::bump_usage( 'save' );

		$rest = new Okno_Rest( Okno_Plugin::instance() );
		return rest_ensure_response(
			array(
				'post'   => $rest->post_item( get_post( $post_id ), new Okno_Url_Mapper() ),
				'saved'  => $result['saved'],
				'errors' => (object) $result['errors'],
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function get_brand() {
		return rest_ensure_response( Okno_AI_Brand::export() );
	}

	/**
	 * Import / enregistrement du contexte de marque (JSON).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_brand( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'okno_bad_request', __( 'This file isn’t a brand context.', 'okno' ), array( 'status' => 400 ) );
		}
		if ( isset( $body['okno'] ) && 'brand' !== $body['okno'] ) {
			return new WP_Error( 'okno_bad_request', __( 'This file isn’t a brand context.', 'okno' ), array( 'status' => 400 ) );
		}
		Okno_AI_Brand::save( $body );
		return rest_ensure_response( Okno_AI_Brand::export() );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function get_collection( $request ) {
		$context = Okno_AI_Brand::collection( (string) $request['type'] );
		unset( $context['label'] );
		return rest_ensure_response( $context );
	}

	/**
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function save_collection( $request ) {
		$body = $request->get_json_params();
		return rest_ensure_response( Okno_AI_Brand::save_collection( (string) $request['type'], is_array( $body ) ? $body : array() ) );
	}

	/* ---------------------------------------------------------------------
	 * Utilitaires
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $text Texte.
	 * @return int
	 */
	private static function mb_len( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}
}
