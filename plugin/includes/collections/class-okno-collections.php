<?php
defined( 'ABSPATH' ) || exit;

/**
 * Okno Collections : types de contenu et champs définis dans Okno, sans ACF.
 *
 * Point d'entrée PHP (schéma, migrations, entrées) et branchement sur
 * WordPress : un post type par collection, révisions des valeurs de champ,
 * types servis par l'API de contenu et proposés dans l'éditeur.
 *
 * Spécification : plugin/docs/collections-spec.md.
 */
class Okno_Collections {

	const OPTION_SCHEMA = 'okno_collections';

	/** Instantané des valeurs de champ d'une révision. */
	const META_SNAPSHOT = '_okno_fields';

	/** @var array|null Schéma mémorisé pour la requête. */
	private static $schema = null;

	/** @var array<string,int> Entrée de chaque singleton, mémorisée. */
	private static $entries = array();

	/** @var string[] Post types enregistrés par Okno dans cette requête. */
	private static $registered = array();

	/** @var int Incrémenté à chaque enregistrement du schéma (caches dérivés). */
	private static $generation = 0;

	/**
	 * Branche les hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_filter( 'okno_settings', array( __CLASS__, 'settings' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_action( 'deleted_post', array( __CLASS__, 'deleted_post' ), 10, 2 );
		add_action( '_wp_put_post_revision', array( __CLASS__, 'snapshot_revision' ), 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', array( __CLASS__, 'revision_has_changed' ), 10, 3 );
		add_action( 'wp_restore_post_revision', array( __CLASS__, 'restore_revision' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
		add_action( 'rest_api_init', array( new Okno_Collections_Rest(), 'register_routes' ) );
	}

	/* ---------------------------------------------------------------------
	 * Schéma
	 * ------------------------------------------------------------------- */

	/**
	 * Schéma enregistré (normalisé).
	 *
	 * @return array{version:int,collections:array}
	 */
	public static function schema() {
		if ( null === self::$schema ) {
			$saved        = get_option( self::OPTION_SCHEMA, array() );
			self::$schema = is_array( $saved ) && isset( $saved['collections'] ) && is_array( $saved['collections'] )
				? $saved
				: array(
					'version'     => Okno_Collections_Schema::VERSION,
					'collections' => array(),
				);
		}
		return self::$schema;
	}

	/**
	 * Génération du schéma dans la requête : change à chaque enregistrement.
	 *
	 * @return int
	 */
	public static function generation() {
		return self::$generation;
	}

	/**
	 * Une collection, ou null.
	 *
	 * @param string $id Collection.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::schema()['collections'] as $collection ) {
			if ( $collection['id'] === $id ) {
				return $collection;
			}
		}
		return null;
	}

	/**
	 * La collection d'un post type (le post type est l'id de la collection).
	 *
	 * @param string $post_type Post type.
	 * @return array|null
	 */
	public static function for_post_type( $post_type ) {
		return is_string( $post_type ) && '' !== $post_type ? self::get( $post_type ) : null;
	}

	/**
	 * Post types des collections.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		return wp_list_pluck( self::schema()['collections'], 'id' );
	}

	/**
	 * Valide, migre et enregistre un schéma complet.
	 *
	 * @param array $input   Schéma (format okno.schema.json).
	 * @param bool  $dry_run Calculer le plan sans rien appliquer.
	 * @return array|WP_Error Rapport de migration.
	 */
	public static function save_schema( $input, $dry_run = false ) {
		$errors = array();
		$schema = Okno_Collections_Schema::normalize( $input, $errors );
		if ( $errors ) {
			return new WP_Error(
				'okno_invalid_schema',
				__( 'The schema is invalid.', 'okno' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$report = array(
			'dry_run'    => (bool) $dry_run,
			'operations' => array(),
			'converted'  => array(),
			'warnings'   => array(),
		);

		$plans = array();
		foreach ( $schema['collections'] as $collection ) {
			$current = Okno_Collections_Store::collection_state( $collection['id'] );
			if ( Okno_Collections_Store::varchar_count( $collection, $current ) > Okno_Collections_Schema::MAX_VARCHAR ) {
				return new WP_Error(
					'okno_too_many_columns',
					/* translators: %s: collection id. */
					sprintf( __( 'The table of “%s” would have too many short text columns, archived ones included. Purge archived columns first.', 'okno' ), $collection['id'] ),
					array( 'status' => 400 )
				);
			}
			// Un post type déjà déclaré par un autre plugin ou le thème (sauf
			// collection « externe » : Okno n'y possède que les champs).
			if ( empty( $collection['external'] ) && function_exists( 'post_type_exists' ) && post_type_exists( $collection['id'] ) && ! in_array( $collection['id'], self::$registered, true ) ) {
				return new WP_Error(
					'okno_post_type_taken',
					/* translators: %s: collection id. */
					sprintf( __( 'Another plugin or the theme already registers the content type “%s”.', 'okno' ), $collection['id'] ),
					array( 'status' => 400 )
				);
			}
			$plans[ $collection['id'] ] = Okno_Collections_Store::plan( $collection, $current );
		}

		foreach ( self::schema()['collections'] as $old ) {
			$still = false;
			foreach ( $schema['collections'] as $collection ) {
				$still = $still || $collection['id'] === $old['id'];
			}
			if ( ! $still ) {
				// Données conservées : la remettre dans le schéma restaure tout.
				$report['operations'][] = array(
					'op'         => 'remove_collection',
					'collection' => $old['id'],
				);
			}
		}

		if ( $dry_run ) {
			foreach ( $plans as $ops ) {
				$report['operations'] = array_merge( $report['operations'], $ops );
			}
			return $report;
		}

		foreach ( $schema['collections'] as $collection ) {
			$result = Okno_Collections_Store::apply( $collection, $plans[ $collection['id'] ], $report );
			if ( is_wp_error( $result ) ) {
				$result->add_data(
					array(
						'status' => 500,
						'report' => $report,
					)
				);
				return $result;
			}
		}

		update_option( self::OPTION_SCHEMA, $schema, true );
		self::$schema  = $schema;
		self::$entries = array();
		self::$generation++;

		// Post types disponibles tout de suite (création des singletons, API).
		if ( did_action( 'init' ) ) {
			self::register_post_types();
		}
		foreach ( $schema['collections'] as $collection ) {
			if ( 'singleton' === $collection['kind'] ) {
				$created = self::ensure_entry( $collection );
				if ( $created ) {
					$report['operations'][] = array(
						'op'         => 'create_entry',
						'collection' => $collection['id'],
						'post_id'    => $created,
					);
				}
			}
		}

		if ( class_exists( 'Okno_Content' ) ) {
			Okno_Content::invalidate();
		}
		// Le schéma change la forme du contenu servi (export, types) : le
		// front doit être reconstruit, comme après une modification de contenu.
		if ( ! empty( $report['operations'] ) && class_exists( 'Okno_Deploy_Manager' ) ) {
			Okno_Deploy_Manager::queue_auto();
		}

		/**
		 * Le schéma Okno Collections a été migré et enregistré.
		 *
		 * @param array $schema Schéma normalisé.
		 * @param array $report Rapport de migration.
		 */
		do_action( 'okno_collections_saved', $schema, $report );

		return $report;
	}

	/**
	 * Crée ou remplace une collection.
	 *
	 * @param array $collection Collection (format du fichier).
	 * @param bool  $dry_run    Plan seulement.
	 * @return array|WP_Error Rapport.
	 */
	public static function put_collection( $collection, $dry_run = false ) {
		$schema = self::export();
		$index  = null;
		foreach ( $schema['collections'] as $i => $existing ) {
			if ( is_array( $collection ) && isset( $collection['id'] ) && $existing['id'] === $collection['id'] ) {
				$schema['collections'][ $i ] = $collection;
				$index                       = $i;
			}
		}
		if ( null === $index ) {
			$index                   = count( $schema['collections'] );
			$schema['collections'][] = $collection;
		}

		$result = self::save_schema( $schema, $dry_run );

		// Erreurs relatives à la collection envoyée (fields.0.name), pas au schéma entier.
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['errors'] ) ) {
				$prefix = 'collections.' . $index;
				foreach ( $data['errors'] as $n => $error ) {
					if ( $prefix === $error['path'] ) {
						$data['errors'][ $n ]['path'] = '';
					} elseif ( 0 === strpos( $error['path'], $prefix . '.' ) ) {
						$data['errors'][ $n ]['path'] = substr( $error['path'], strlen( $prefix ) + 1 );
					}
				}
				$result->add_data( $data );
			}
		}
		return $result;
	}

	/**
	 * Retire une collection du schéma. Table, entrées et état sont conservés.
	 *
	 * @param string $id Collection.
	 * @return array|WP_Error Rapport.
	 */
	public static function delete_collection( $id ) {
		$schema = self::export();
		$schema['collections'] = array_values(
			array_filter(
				$schema['collections'],
				static function ( $collection ) use ( $id ) {
					return $collection['id'] !== $id;
				}
			)
		);
		return self::save_schema( $schema );
	}

	/**
	 * Contenu d'okno.schema.json.
	 *
	 * @return array
	 */
	public static function export() {
		return Okno_Collections_Schema::compact( self::schema() );
	}

	/**
	 * Remplace le schéma par le contenu d'un fichier okno.schema.json.
	 *
	 * @param string|array $data    JSON ou tableau décodé.
	 * @param bool         $dry_run Plan seulement.
	 * @return array|WP_Error Rapport.
	 */
	public static function import( $data, $dry_run = false ) {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'okno_invalid_schema', __( 'The file is not valid JSON.', 'okno' ), array( 'status' => 400 ) );
			}
		}
		if ( isset( $data['okno'] ) && 'collections' !== $data['okno'] ) {
			return new WP_Error( 'okno_invalid_schema', __( 'This is not an Okno Collections schema file.', 'okno' ), array( 'status' => 400 ) );
		}
		return self::save_schema( $data, $dry_run );
	}

	/* ---------------------------------------------------------------------
	 * Entrées
	 * ------------------------------------------------------------------- */

	/**
	 * L'entrée d'un singleton.
	 *
	 * @param string $id Collection.
	 * @return int Post, 0 si absent.
	 */
	public static function entry( $id ) {
		if ( isset( self::$entries[ $id ] ) ) {
			return self::$entries[ $id ];
		}
		$ids = get_posts(
			array(
				'post_type'        => $id,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		self::$entries[ $id ] = $ids ? (int) $ids[0] : 0;
		return self::$entries[ $id ];
	}

	/**
	 * Crée l'entrée d'un singleton si elle manque.
	 *
	 * @param array $collection Collection normalisée.
	 * @return int Post créé, 0 sinon.
	 */
	private static function ensure_entry( $collection ) {
		if ( self::entry( $collection['id'] ) ) {
			return 0;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => $collection['id'],
				'post_title'  => $collection['singular_label'],
				'post_name'   => str_replace( '_', '-', $collection['id'] ),
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
		self::$entries[ $collection['id'] ] = (int) $post_id;
		return (int) $post_id;
	}

	/**
	 * Écrit des valeurs de champ sur une entrée, par nom ou chemin pointé.
	 *
	 * Même validation que l'éditeur (required, max_length, droits sur les
	 * médias et références) : l'utilisateur courant doit pouvoir lire les
	 * médias et contenus référencés.
	 *
	 * @param int   $post_id Entrée.
	 * @param array $values  chemin => valeur.
	 * @return array{saved:string[],errors:array<string,string>}
	 */
	public static function save_entry( $post_id, $values ) {
		$adapter = new Okno_Native_Adapter();
		$result  = $adapter->save_values( $post_id, $values );
		if ( $result['saved'] && class_exists( 'Okno_Schema' ) ) {
			// Empreinte de version de l'éditeur + invalidation du cache d'export.
			Okno_Schema::touch( $post_id );
		}
		return $result;
	}

	/**
	 * Les champs d'une entrée ont changé. Ils vivent dans la table de la
	 * collection, pas en postmeta : les crochets de métas ne voient rien, on
	 * vide donc le cache de contenu et on signale le changement
	 * (okno_content_changed : publication automatique, purge des caches de page)
	 * explicitement.
	 *
	 * @param int $post_id Entrée.
	 * @return void
	 */
	public static function entry_changed( $post_id ) {
		if ( class_exists( 'Okno_Content' ) ) {
			Okno_Content::post_changed( (int) $post_id );
		}
	}

	/**
	 * Valeurs d'une entrée telles que servies au front.
	 *
	 * @param int $post_id Entrée.
	 * @return array<string,mixed>
	 */
	public static function get_entry( $post_id ) {
		return ( new Okno_Native_Adapter() )->get_content( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * WordPress
	 * ------------------------------------------------------------------- */

	/**
	 * Un post type par collection.
	 *
	 * @return void
	 */
	public static function register_post_types() {
		foreach ( self::schema()['collections'] as $collection ) {
			$id = $collection['id'];
			if ( ! empty( $collection['external'] ) ) {
				continue; // Type déclaré par le thème, une extension ou ACF (voir Okno_ACF_Import).
			}
			if ( post_type_exists( $id ) && ! in_array( $id, self::$registered, true ) ) {
				continue; // Déclaré ailleurs : on ne l'écrase pas.
			}

			$supports = array_merge( array( 'title', 'revisions' ), $collection['supports'] );
			$args     = array(
				'labels'              => array(
					'name'          => $collection['label'],
					'singular_name' => $collection['singular_label'],
					'menu_name'     => $collection['label'],
					'all_items'     => $collection['label'],
				),
				'description'         => '',
				// Public pour l'éditeur Okno et les extensions SEO ; jamais rendu par
				// WordPress (le front headless s'en charge).
				'public'              => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => in_array( 'page-attributes', $collection['supports'], true ),
				'menu_icon'           => '' !== $collection['icon'] ? $collection['icon'] : ( 'singleton' === $collection['kind'] ? 'dashicons-admin-page' : 'dashicons-database' ),
				'supports'            => $supports,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'delete_with_user'    => false,
			);
			if ( 'singleton' === $collection['kind'] ) {
				// Une seule entrée, créée par Okno.
				$args['capabilities'] = array( 'create_posts' => 'do_not_allow' );
			}

			/**
			 * Arguments d'enregistrement du post type d'une collection.
			 *
			 * @param array $args       Arguments de register_post_type().
			 * @param array $collection Collection normalisée.
			 */
			register_post_type( $id, apply_filters( 'okno_collection_post_type_args', $args, $collection ) );
			self::$registered[] = $id;
		}
	}

	/**
	 * Les collections sont éditables dans Okno et servies par l'API de contenu,
	 * avec leur chemin sur le front (sauf réglage contraire).
	 *
	 * @param array $settings Réglages Okno.
	 * @return array
	 */
	public static function settings( $settings ) {
		foreach ( self::schema()['collections'] as $collection ) {
			if ( ! empty( $collection['external'] ) ) {
				// Type déclaré ailleurs : servi (ou non) selon les réglages, comme
				// avant qu'Okno n'en reprenne les champs.
				continue;
			}
			if ( ! in_array( $collection['id'], (array) $settings['post_types'], true ) ) {
				$settings['post_types'][] = $collection['id'];
			}
			if ( '' !== $collection['path'] && empty( $settings['url_patterns'][ $collection['id'] ] ) ) {
				$settings['url_patterns'][ $collection['id'] ] = $collection['path'];
			}
		}
		return $settings;
	}

	/**
	 * L'entrée d'un singleton ne se supprime pas.
	 *
	 * @param string[] $caps    Capacités requises.
	 * @param string   $cap     Capacité demandée.
	 * @param int      $user_id Utilisateur.
	 * @param array    $args    Arguments (post id).
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}
		$post       = get_post( (int) $args[0] );
		$collection = $post ? self::for_post_type( $post->post_type ) : null;
		if ( $collection && 'singleton' === $collection['kind'] ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Suppression définitive d'une entrée : sa ligne part avec elle.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function deleted_post( $post_id, $post = null ) {
		if ( $post && self::for_post_type( $post->post_type ) ) {
			Okno_Collections_Store::delete( $post->post_type, $post_id );
		}
	}

	/**
	 * Lien vers l'éditeur Okno sur l'écran d'édition classique : les champs
	 * de collection s'y éditent.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public static function meta_box( $post_type, $post = null ) {
		if ( ! self::for_post_type( $post_type ) || ! $post ) {
			return;
		}
		add_meta_box(
			'okno-collection-fields',
			__( 'Fields', 'okno' ),
			static function ( $post ) {
				printf(
					'<p>%s</p><p><a class="button" href="%s">%s</a></p>',
					esc_html__( 'The fields of this entry are edited in Okno.', 'okno' ),
					esc_url( Okno_Admin::edit_url( (int) $post->ID ) ),
					esc_html__( 'Edit with Okno', 'okno' )
				);
			},
			$post_type,
			'side',
			'high'
		);
	}

	/* ---------------------------------------------------------------------
	 * Révisions
	 * ------------------------------------------------------------------- */

	/**
	 * Instantané des valeurs de champ d'une entrée, indexé par clé de champ.
	 *
	 * @param array $collection Collection.
	 * @param int   $post_id    Entrée.
	 * @return array{v:int,fields:array}
	 */
	public static function snapshot( $collection, $post_id ) {
		$state  = Okno_Collections_Store::collection_state( $collection['id'] );
		$values = Okno_Collections_Store::values( $collection['id'], $post_id );
		$fields = array();
		foreach ( $values as $key => $value ) {
			if ( null === $value || ! isset( $state['columns'][ $key ] ) ) {
				continue;
			}
			$field          = $state['columns'][ $key ]['field'];
			$fields[ $key ] = array(
				'type'     => $field['type'],
				'multiple' => ! empty( $field['multiple'] ),
				'value'    => $value,
			);
		}
		ksort( $fields );
		return array(
			'v'      => 1,
			'fields' => $fields,
		);
	}

	/**
	 * WordPress vient d'enregistrer une révision : on y joint les champs.
	 *
	 * @param int $revision_id Révision.
	 * @param int $post_id     Entrée.
	 * @return void
	 */
	public static function snapshot_revision( $revision_id, $post_id = 0 ) {
		$revision = get_post( $revision_id );
		if ( ! $revision || ! $revision->post_parent ) {
			return;
		}
		$parent     = get_post( $revision->post_parent );
		$collection = $parent ? self::for_post_type( $parent->post_type ) : null;
		if ( ! $collection ) {
			return;
		}
		// update_post_meta() redirigerait vers le parent : métadonnée de la révision elle-même.
		update_metadata( 'post', $revision_id, self::META_SNAPSHOT, wp_slash( Okno_Collections_Values::json( self::snapshot( $collection, $parent->ID ) ) ) );
	}

	/**
	 * Une modification de champ seule justifie une révision.
	 *
	 * @param bool    $changed  Le post a changé.
	 * @param WP_Post $latest   Dernière révision.
	 * @param WP_Post $post     Entrée.
	 * @return bool
	 */
	public static function revision_has_changed( $changed, $latest, $post ) {
		if ( $changed || ! $post || ! $latest ) {
			return $changed;
		}
		$collection = self::for_post_type( $post->post_type );
		if ( ! $collection ) {
			return $changed;
		}
		$current = Okno_Collections_Values::json( self::snapshot( $collection, $post->ID ) );
		$stored  = get_metadata( 'post', $latest->ID, self::META_SNAPSHOT, true );
		if ( '' === (string) $stored ) {
			return array() !== self::snapshot( $collection, $post->ID )['fields'];
		}
		return $current !== $stored;
	}

	/**
	 * Restauration d'une révision : les champs reviennent aussi.
	 *
	 * @param int $post_id     Entrée.
	 * @param int $revision_id Révision restaurée.
	 * @return void
	 */
	public static function restore_revision( $post_id, $revision_id ) {
		$post       = get_post( $post_id );
		$collection = $post ? self::for_post_type( $post->post_type ) : null;
		if ( ! $collection ) {
			return;
		}
		$snapshot = json_decode( (string) get_metadata( 'post', $revision_id, self::META_SNAPSHOT, true ), true );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['fields'] ) ) {
			return; // Révision antérieure aux Collections : rien à restaurer.
		}

		$values = array();
		foreach ( $collection['fields'] as $field ) {
			$key = $field['key'];
			if ( ! isset( $snapshot['fields'][ $key ] ) ) {
				$values[ $key ] = null; // Vide à l'époque.
				continue;
			}
			$old = $snapshot['fields'][ $key ];
			if ( $old['type'] === $field['type'] && ! empty( $old['multiple'] ) === ! empty( $field['multiple'] ) ) {
				$values[ $key ] = $old['value'];
				continue;
			}
			// Type changé depuis : même conversion qu'une migration.
			$ok             = true;
			$values[ $key ] = Okno_Collections_Values::convert( $old['value'], $old, $field, $ok );
		}
		Okno_Collections_Store::write( $collection['id'], $post_id, $values );
		self::entry_changed( $post_id );
		if ( class_exists( 'Okno_Schema' ) ) {
			Okno_Schema::touch( $post_id );
		}
		// La révision créée pendant la restauration porte les anciens champs.
		wp_save_post_revision( $post_id );
	}
}
