<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capacités de Leiho pour l'Abilities API de WordPress (6.9+) : les agents
 * d'IA externes (adaptateur MCP, agents WordPress) lisent le contexte de
 * marque, les types de contenu et les entrées, cherchent des entrées,
 * créent des brouillons et modifient les champs d'un brouillon par le même
 * chemin d'écriture que l'éditeur (validation, journal). Aucune ne publie ;
 * aucune ne touche un contenu en ligne.
 *
 * Seule leiho/suggest-alt-text appelle un modèle (celui du site, s'il lit
 * les images) ; les autres restent disponibles sans fournisseur d'IA.
 */
class Leiho_AI_Abilities {

	const CATEGORY = 'leiho';

	/** Statuts qu'une capacité peut modifier (brouillons). */
	const DRAFT_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/** Statuts qu'une recherche peut viser (jamais la corbeille). */
	const SEARCH_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/** Résultats par page d'une recherche (maximum). */
	const MAX_PER_PAGE = 50;

	/** Capacité qui demande un fournisseur capable de lire les images. */
	const ABILITY_ALT = 'leiho/suggest-alt-text';

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
				'description' => __( 'Read, find and draft the content managed with Leiho: brand context, content types, entries and their fields.', 'leiho' ),
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
			'leiho/search-entries'      => array(
				'label'               => __( 'Search entries', 'leiho' ),
				'description'         => __( 'Finds entries of the content types edited in Leiho, by type, title text and status (all statuses except trash by default), most recently modified first. Returns for each entry its ID, type, title, status, last modified date, path on the front end, whether you can edit it and whether an agent can write to it (drafts and pending entries only). Only entries the connected user can edit are listed.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Optional content type (see leiho/list-collections).', 'leiho' ),
						),
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Optional text to look for in the titles.', 'leiho' ),
							'maxLength'   => 200,
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'Optional status: publish, future, draft, pending or private. All of them by default.', 'leiho' ),
							'enum'        => array_merge( array( 'any' ), self::SEARCH_STATUSES ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (default 20, at most 50).', 'leiho' ),
							'minimum'     => 1,
							'maximum'     => self::MAX_PER_PAGE,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => __( 'Page of results (default 1).', 'leiho' ),
							'minimum'     => 1,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'search_entries' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/create-draft'        => array(
				'label'               => __( 'Create a draft', 'leiho' ),
				'description'         => __( 'Creates a new draft of a content type edited in Leiho, with a title and optional field values by path (see leiho/list-collections). Values are validated like in the Leiho editor; refused fields are returned in errors. The entry is always a draft: this never publishes. Read leiho/get-brand-context first.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Content type of the new entry (for example page or service).', 'leiho' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'Title of the new entry.', 'leiho' ),
							'minLength'   => 1,
						),
						'fields'    => array(
							'type'                 => 'object',
							'description'          => __( 'Optional field values by path.', 'leiho' ),
							'additionalProperties' => true,
						),
					),
					'required'             => array( 'post_type', 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'create_draft' ),
				'permission_callback' => array( __CLASS__, 'can_create_draft' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			),
			self::ABILITY_ALT           => array(
				'label'               => __( 'Suggest an alternative text', 'leiho' ),
				'description'         => __( 'Describes an image of the media library with the AI provider set up in WordPress and returns a suggested alternative text, checked against the brand context. Saves nothing: to keep it, update the alt_text of the media item through the WordPress media endpoints. Available only when the provider can read images.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID of the image.', 'leiho' ),
							'minimum'     => 1,
						),
						'context' => array(
							'type'        => 'string',
							'description' => __( 'Optional: where and why the image is used (for example “hero of the radon audit page”).', 'leiho' ),
							'maxLength'   => 500,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'suggest_alt_text' ),
				'permission_callback' => array( __CLASS__, 'can_describe_image' ),
				'meta'                => $meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			),
			'leiho/list-translations'   => array(
				'label'               => __( 'List the translations of a content type', 'leiho' ),
				'description'         => __( 'For a Leiho collection with several languages, lists its entries with the state of each translation: missing (nothing translated), partial (some fields left), outdated (the original changed since) or complete. Use it to find what to translate next.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => __( 'Content type (collection ID).', 'leiho' ),
						),
						'locale'    => array(
							'type'        => 'string',
							'description' => __( 'Optional language code (for example fr). Default: every language except the default one.', 'leiho' ),
						),
					),
					'required'             => array( 'post_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'list_translations' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/get-translation'     => array(
				'label'               => __( 'Get the translation of an entry', 'leiho' ),
				'description'         => __( 'Returns every translatable text of an entry, by path: the original (default language) and the current translation in the requested language (empty when not translated yet), with its format (text, multiline or html: keep the tags) and maximum length. Translate the empty ones, then write them with leiho/update-translation.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'Entry ID.', 'leiho' ),
							'minimum'     => 1,
						),
						'locale' => array(
							'type'        => 'string',
							'description' => __( 'Language code to translate into (for example fr).', 'leiho' ),
						),
					),
					'required'             => array( 'id', 'locale' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_translation' ),
				'permission_callback' => array( __CLASS__, 'can_edit_entry' ),
				'meta'                => $meta( $readonly ),
			),
			'leiho/update-translation'  => array(
				'label'               => __( 'Write translations', 'leiho' ),
				'description'         => __( 'Writes translated texts of an entry in one language, by path (as returned by leiho/get-translation). The original language is never changed. On a draft any translation can be written; on a published entry only empty translations can be filled: texts already translated are refused (already_translated) so that reviewed work is never replaced. Never publishes.', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'Entry ID.', 'leiho' ),
							'minimum'     => 1,
						),
						'locale' => array(
							'type'        => 'string',
							'description' => __( 'Language code (for example fr).', 'leiho' ),
						),
						'values' => array(
							'type'                 => 'object',
							'description'          => __( 'Translated texts by path, for example { "_title": "…", "points.0.title": "…" }.', 'leiho' ),
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
					'required'             => array( 'id', 'locale', 'values' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'update_translation' ),
				'permission_callback' => array( __CLASS__, 'can_update_entry' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
			'leiho/translate-entry'     => array(
				'label'               => __( 'Translate an entry with the site AI', 'leiho' ),
				'description'         => __( 'Asks the AI provider configured on the site to translate the texts of an entry that are not translated yet (or all of them), with the brand context. Returns the proposed translations by path without saving them, unless save is true (same rules as leiho/update-translation).', 'leiho' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'Entry ID.', 'leiho' ),
							'minimum'     => 1,
						),
						'locale' => array(
							'type'        => 'string',
							'description' => __( 'Language code (for example fr).', 'leiho' ),
						),
						'mode'   => array(
							'type'        => 'string',
							'enum'        => array( 'missing', 'all' ),
							'description' => __( 'missing (default): only texts not translated yet. all: every text.', 'leiho' ),
						),
						'save'   => array(
							'type'        => 'boolean',
							'description' => __( 'Save the translations (default false).', 'leiho' ),
						),
					),
					'required'             => array( 'id', 'locale' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'translate_entry' ),
				'permission_callback' => array( __CLASS__, 'can_update_entry' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
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
			// Inutile d'annoncer aux agents une capacité que le fournisseur
			// ne peut pas servir.
			if ( self::ABILITY_ALT === $name && ! self::can_read_images() ) {
				continue;
			}
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
	 * Créer un brouillon : type édité dans Leiho et droit de créer ce type
	 * (même contrôle que la création dans l'éditeur).
	 *
	 * @param mixed $input Entrée.
	 * @return bool
	 */
	public static function can_create_draft( $input = null ) {
		$type = is_array( $input ) && isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		return '' !== $type
			&& Leiho_AI::user_can()
			&& in_array( $type, Leiho_Content::post_types(), true )
			&& ( new Leiho_Rest( Leiho_Plugin::instance() ) )->can_create_type( $type );
	}

	/**
	 * Décrire une image : droit de modifier la pièce jointe (comme la route
	 * REST du texte alternatif).
	 *
	 * @param mixed $input Entrée.
	 * @return bool
	 */
	public static function can_describe_image( $input = null ) {
		$id = is_array( $input ) && isset( $input['id'] ) ? (int) $input['id'] : 0;
		return $id > 0 && Leiho_AI::user_can() && 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Le fournisseur d'IA du site lit-il les images ?
	 *
	 * @return bool
	 */
	public static function can_read_images() {
		$status = Leiho_AI::status();
		return ! empty( $status['image'] );
	}

	/**
	 * Statut modifiable par un agent.
	 *
	 * @param string $status Statut.
	 * @return bool
	 */
	public static function is_draft( $status ) {
		/**
		 * Statuts qu'une capacité de Leiho peut modifier.
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
			if ( Leiho_I18n::enabled() && Leiho_Collections::for_post_type( $type['name'] ) ) {
				$item['languages'] = Leiho_I18n::codes(); // La première est la langue par défaut.
			}
			// Champs qui font la description et la FAQ : un agent sait lesquels soigner.
			$collection = Leiho_Collections::for_post_type( $type['name'] );
			if ( $collection && ! empty( $collection['seo'] ) ) {
				$item['seo'] = $collection['seo'];
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
		// Le statut, l'auteur et la date ne changent jamais par ici.
		unset( $values['_status'], $values['_author'], $values['_date'] );

		$revision = isset( $input['revision'] ) ? (string) $input['revision'] : '';
		if ( '' !== $revision && Leiho_Schema::revision( $id ) !== $revision ) {
			return new WP_Error( 'leiho_conflict', __( 'This content was changed elsewhere since you read it. Read it again with leiho/get-entry.', 'leiho' ), array( 'status' => 409 ) );
		}

		set_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );
		$result = self::save_filtered( $id, $values );
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

	/**
	 * @param mixed $input { post_type, search, status, per_page, page }.
	 * @return array|WP_Error
	 */
	public static function search_entries( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$types = Leiho_Content::post_types();
		$type  = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : '';
		if ( '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return new WP_Error( 'leiho_bad_request', __( 'This content type isn’t editable in Leiho.', 'leiho' ), array( 'status' => 400 ) );
			}
			$types = array( $type );
		}
		if ( ! $types ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
				'page'        => 1,
			);
		}

		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		$statuses = in_array( $status, self::SEARCH_STATUSES, true ) ? array( $status ) : self::SEARCH_STATUSES;
		$per_page = isset( $input['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 20;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$search   = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

		$args = array(
			'post_type'      => $types,
			'post_status'    => $statuses,
			// Le total ne compte que ce que l'utilisateur peut modifier.
			'perm'           => 'editable',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s']              = $search;
			$args['search_columns'] = array( 'post_title' );
		}
		$query = new WP_Query( $args );

		$mapper = new Leiho_Url_Mapper();
		$items  = array();
		foreach ( $query->posts as $post ) {
			// Ce que l'utilisateur connecté ne peut pas modifier n'est pas listé.
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'           => (int) $post->ID,
				'type'         => $post->post_type,
				'title'        => Leiho_Plugin::title( $post ),
				'status'       => $post->post_status,
				'modified'     => mysql2date( 'c', $post->post_modified_gmt . ' +0000', false ),
				'path'         => $mapper->path( $post ),
				'url'          => $mapper->front_url( $post ),
				'can_edit'     => true,
				'agent_writes' => self::is_draft( $post->post_status ) && self::is_leiho_type( $post->ID ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * @param mixed $input { post_type, title, fields }.
	 * @return array|WP_Error
	 */
	public static function create_draft( $input = null ) {
		$type   = sanitize_key( (string) $input['post_type'] );
		$title  = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '';
		$values = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'leiho_bad_request', __( 'A title is required.', 'leiho' ), array( 'status' => 400 ) );
		}
		// Le statut, l'auteur et la date ne changent jamais par ici ; le titre vient de « title ».
		unset( $values['_status'], $values['_author'], $values['_date'], $values[ Leiho_Schema::PATH_TITLE ] );

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
		$post_id = (int) $post_id;

		$result = array(
			'saved'  => array(),
			'errors' => array(),
		);
		if ( $values ) {
			set_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );
			$result = self::save_filtered( $post_id, $values );
			delete_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE );
		}
		Leiho_Activity::record_ai( $post_id, Leiho_Schema::PATH_TITLE, $title, 'draft' );
		Leiho_Plugin::bump_usage( 'save' );

		$post = get_post( $post_id );
		return array(
			'id'       => $post_id,
			'type'     => $type,
			'status'   => $post ? $post->post_status : 'draft',
			'saved'    => array_values( array_merge( array( Leiho_Schema::PATH_TITLE ), $result['saved'] ) ),
			'errors'   => (object) $result['errors'],
			'revision' => Leiho_Schema::revision( $post_id ),
		);
	}

	/**
	 * @param mixed $input { id, context }.
	 * @return array|WP_Error
	 */
	public static function suggest_alt_text( $input = null ) {
		if ( ! self::can_read_images() ) {
			return new WP_Error( 'leiho_ai_no_image', __( 'The configured AI provider can’t read images.', 'leiho' ), array( 'status' => 501 ) );
		}
		$id      = (int) $input['id'];
		$context = isset( $input['context'] ) ? (string) $input['context'] : '';
		$result  = Leiho_AI_Rest::suggest_alt( $id, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'       => $id,
			'alt_text' => $result['text'],
			'check'    => $result['check'],
			'saved'    => false,
		);
	}

	/* ---------------------------------------------------------------------
	 * Champs lisibles par un agent
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $input { post_type, locale }.
	 * @return array|WP_Error
	 */
	public static function list_translations( $input = null ) {
		$type = sanitize_key( (string) $input['post_type'] );
		if ( ! in_array( $type, Leiho_Content::post_types(), true ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown content type.', 'leiho' ), array( 'status' => 404 ) );
		}
		return Leiho_AI_Translate::overview( $type, isset( $input['locale'] ) ? (string) $input['locale'] : '' );
	}

	/**
	 * @param mixed $input { id, locale }.
	 * @return array|WP_Error
	 */
	public static function get_translation( $input = null ) {
		$data = Leiho_AI_Translate::units( (int) $input['id'], (string) $input['locale'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$post             = get_post( (int) $input['id'] );
		$data['id']       = (int) $input['id'];
		$data['default']  = Leiho_I18n::default_locale();
		$data['editable'] = self::is_draft( $post->post_status ) ? 'all' : 'empty_only';
		return $data;
	}

	/**
	 * @param mixed $input { id, locale, values }.
	 * @return array|WP_Error
	 */
	public static function update_translation( $input = null ) {
		$id     = (int) $input['id'];
		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		if ( ! $values ) {
			return new WP_Error( 'leiho_bad_request', __( 'Nothing to save.', 'leiho' ), array( 'status' => 400 ) );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		set_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );
		$result = Leiho_AI_Translate::write( $id, (string) $input['locale'], $values, self::is_draft( $post->post_status ) );
		delete_transient( Leiho_Deploy_Manager::TRANSIENT_SAVE );
		if ( ! is_wp_error( $result ) && $result['saved'] ) {
			Leiho_Activity::record_ai(
				$id,
				implode( ', ', array_slice( $result['saved'], 0, 5 ) ),
				/* translators: 1: language code, 2: number of fields. */
				sprintf( _n( '%1$s translation, %2$d field', '%1$s translation, %2$d fields', count( $result['saved'] ), 'leiho' ), $result['locale'], count( $result['saved'] ) ),
				'ability'
			);
		}
		return $result;
	}

	/**
	 * @param mixed $input { id, locale, mode, save }.
	 * @return array|WP_Error
	 */
	public static function translate_entry( $input = null ) {
		$throttle = Leiho_AI::throttle( get_current_user_id() );
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}
		$mode   = isset( $input['mode'] ) && 'all' === $input['mode'] ? 'all' : 'missing';
		$result = Leiho_AI_Translate::translate( (int) $input['id'], (string) $input['locale'], $mode );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out = array(
			'locale'       => $result['locale'],
			'translations' => $result['translations'],
			'remaining'    => $result['remaining'],
			'warnings'     => $result['warnings'],
		);
		if ( ! empty( $input['save'] ) ) {
			$out['saved'] = self::update_translation(
				array(
					'id'     => (int) $input['id'],
					'locale' => $result['locale'],
					'values' => (array) $result['translations'],
				)
			);
		}
		return $out;
	}

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
		foreach ( array( 'required', 'max_length', 'help', 'multiple', 'min', 'max', 'readonly', 'localized' ) as $key ) {
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

	/**
	 * Écriture d'un agent : le HTML passe toujours par wp_kses_post, même
	 * pour un compte qui a unfiltered_html. Un agent lit des textes qu'il n'a
	 * pas écrits (brouillons, pages web) : il ne doit pas pouvoir y glisser
	 * de script avec les droits d'un administrateur.
	 *
	 * @param int   $post_id Entrée.
	 * @param array $values  Valeurs.
	 * @return array
	 */
	private static function save_filtered( $post_id, $values ) {
		$deny = static function ( $caps ) {
			$caps['unfiltered_html'] = false;
			return $caps;
		};
		add_filter( 'user_has_cap', $deny );
		try {
			return ( new Leiho_Schema( Leiho_Plugin::instance() ) )->save( $post_id, $values );
		} finally {
			remove_filter( 'user_has_cap', $deny );
		}
	}
}
