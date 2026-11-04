<?php
defined( 'ABSPATH' ) || exit;

/**
 * API REST du plugin. Namespace : okno/v1. Aucun endpoint public.
 */
class Okno_Rest {

	const NS = 'okno/v1';

	/** @var Okno_Plugin */
	private $plugin;

	public function __construct( Okno_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_posts' ),
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
						),
						'search'   => array(
							'type'    => 'string',
							'default' => '',
						),
						// Filtres de la vue Collections (facultatifs : l'éditeur ne les envoie pas).
						'type'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'status'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'orderby'  => array(
							'type'    => 'string',
							'enum'    => array( 'title', 'modified', 'date' ),
							'default' => 'title',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_post' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post' ),
					'permission_callback' => array( $this, 'can_delete_post' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_status' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'enum'     => array( 'publish', 'draft', 'pending', 'private' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_post' ),
				'permission_callback' => array( $this, 'can_delete_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/connection',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'check_headers' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store_connection' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);

		register_rest_route(
			self::NS,
			'/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_activity' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_post' ),
				'permission_callback' => array( $this, 'can_duplicate_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/schema/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/save/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
			)
		);

		register_rest_route(
			self::NS,
			'/deploy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deploy' ),
				'permission_callback' => array( $this, 'can_deploy' ),
			)
		);

		// Avant la route /deploy/{id} : « state » et « flush » y correspondraient aussi.
		register_rest_route(
			self::NS,
			'/deploy/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'deploy_state' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/deploy/flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deploy_flush' ),
				'permission_callback' => array( $this, 'can_deploy' ),
			)
		);

		register_rest_route(
			self::NS,
			'/deploy/(?P<deploy_id>[A-Za-z0-9_\-.]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'deploy_status' ),
				'permission_callback' => array( $this, 'can_deploy' ),
			)
		);
		// Préférences d'interface de l'utilisateur (thème clair / sombre).
		register_rest_route(
			self::NS,
			'/preferences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_preferences' ),
				'permission_callback' => static function () {
					return is_user_logged_in() && current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'theme' => array(
						'type' => 'string',
						'enum' => array( 'light', 'dark' ),
					),
				),
			)
		);
	}

	/**
	 * POST /preferences : { theme: light|dark }, gardé en méta utilisateur
	 * pour que la page s'affiche directement dans le bon thème.
	 */
	public function save_preferences( WP_REST_Request $request ) {
		$out = array();
		if ( null !== $request->get_param( 'theme' ) ) {
			$theme = Okno_App::save_theme( $request->get_param( 'theme' ) );
			if ( false === $theme ) {
				return new WP_Error( 'okno_bad_request', __( 'Unknown theme.', 'okno' ), array( 'status' => 400 ) );
			}
			$out['theme'] = $theme;
		}
		return rest_ensure_response( $out );
	}

	public function can_edit_post( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	public function can_delete_post( WP_REST_Request $request ) {
		return current_user_can( 'delete_post', (int) $request['id'] );
	}

	public function can_duplicate_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		return $this->can_create_type( $post->post_type );
	}

	public function can_create( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		return '' !== $type && $this->can_create_type( $type );
	}

	private function can_create_type( $post_type ) {
		$settings = Okno_Plugin::settings();
		if ( ! in_array( $post_type, $settings['post_types'], true ) ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		return $object && current_user_can( $object->cap->create_posts );
	}

	public function can_deploy() {
		return Okno_Deploy_Manager::user_can_deploy();
	}

	/**
	 * GET /posts : contenus éditables des post types configurés, avec URL mappée.
	 */
	public function get_posts( WP_REST_Request $request ) {
		$settings = Okno_Plugin::settings();
		$mapper   = new Okno_Url_Mapper();

		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$types = $settings['post_types'];
		$type  = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return new WP_Error( 'okno_bad_request', __( 'This content type isn’t editable in Okno.', 'okno' ), array( 'status' => 400 ) );
			}
			$types = array( $type );
		}

		$statuses = self::query_statuses( (string) $request->get_param( 'status' ) );
		$orderby  = (string) $request->get_param( 'orderby' );
		$orderby  = in_array( $orderby, array( 'modified', 'date' ), true ) ? $orderby : 'title';

		$query = new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => $statuses,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				's'              => $search,
				'orderby'        => $orderby,
				'order'          => 'title' === $orderby ? 'ASC' : 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = $this->post_item( $post, $mapper );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
		return $response;
	}

	/**
	 * Statuts listés : tous ceux qu'on édite par défaut, un seul sur demande,
	 * la corbeille seulement quand on la demande. Fonction pure.
	 *
	 * @param string $status Statut demandé ('' = tous sauf corbeille).
	 * @return string[]
	 */
	public static function query_statuses( $status ) {
		$editable = array( 'publish', 'future', 'draft', 'pending', 'private' );
		if ( 'trash' === $status || in_array( $status, $editable, true ) ) {
			return array( $status );
		}
		return $editable;
	}

	/**
	 * GET /posts/{id} : un contenu, même absent de la page de liste chargée
	 * (lien profond ?post=…&field=… depuis le journal).
	 */
	public function get_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new WP_Error( 'okno_not_found', __( 'Content not found.', 'okno' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->post_item( $post, new Okno_Url_Mapper() ) );
	}

	/**
	 * GET /connection : le serveur WordPress interroge le site et lit ses
	 * en-têtes. Un navigateur ne peut pas lire les en-têtes d'une autre origine ;
	 * le serveur, si.
	 */
	public function check_headers() {
		$settings = Okno_Plugin::settings();
		$url      = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];
		if ( '' === $url ) {
			return new WP_Error( 'okno_no_url', __( 'No site URL set.', 'okno' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			trailingslashit( $url ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array( 'User-Agent' => 'Okno/' . OKNO_VERSION . ' (connection check)' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response(
				array(
					'reachable' => false,
					'level'     => 'error',
					'message'   => sprintf( /* translators: %s: network error message. */ __( 'Can’t reach the site from the server: %s', 'okno' ), $response->get_error_message() ),
				)
			);
		}

		$verdict = self::frame_verdict(
			(string) wp_remote_retrieve_header( $response, 'content-security-policy' ),
			(string) wp_remote_retrieve_header( $response, 'x-frame-options' ),
			Okno_Admin::wp_origin()
		);

		$verdict['reachable'] = true;
		$verdict['status']    = (int) wp_remote_retrieve_response_code( $response );
		return rest_ensure_response( $verdict );
	}

	/**
	 * Le site accepte-t-il d'être affiché dans wp-admin ? Fonction pure.
	 *
	 * @param string $csp       En-tête Content-Security-Policy.
	 * @param string $xfo       En-tête X-Frame-Options.
	 * @param string $wp_origin Origine de wp-admin.
	 * @return array{level:string,message:string}
	 */
	public static function frame_verdict( $csp, $xfo, $wp_origin ) {
		$ancestors = null;
		foreach ( explode( ';', $csp ) as $directive ) {
			$directive = trim( $directive );
			if ( 0 === stripos( $directive, 'frame-ancestors' ) ) {
				$ancestors = preg_split( '/\s+/', trim( substr( $directive, strlen( 'frame-ancestors' ) ) ) );
				break;
			}
		}

		if ( null !== $ancestors ) {
			$wp_host = wp_parse_url( $wp_origin, PHP_URL_HOST );
			foreach ( $ancestors as $source ) {
				$source = trim( $source, "'" );
				if ( '*' === $source || rtrim( $source, '/' ) === $wp_origin ) {
					return array(
						'level'   => 'ok',
						'message' => __( 'The site allows wp-admin in its frame-ancestors header.', 'okno' ),
					);
				}
				// Joker de sous-domaine : https://*.exemple.fr.
				if ( false !== strpos( $source, '*.' ) ) {
					$suffix = substr( $source, strpos( $source, '*.' ) + 1 );
					if ( $wp_host && substr( $wp_host, -strlen( $suffix ) ) === $suffix ) {
						return array(
							'level'   => 'ok',
							'message' => __( 'The site allows wp-admin in its frame-ancestors header.', 'okno' ),
						);
					}
				}
			}
			return array(
				'level'   => 'error',
				/* translators: 1: current frame-ancestors value, 2: wp-admin origin. */
				'message' => sprintf( __( 'The site’s frame-ancestors header (%1$s) doesn’t allow %2$s. Add this address.', 'okno' ), implode( ' ', $ancestors ), $wp_origin ),
			);
		}

		$xfo = strtoupper( trim( $xfo ) );
		if ( 'DENY' === $xfo || 'SAMEORIGIN' === $xfo ) {
			return array(
				'level'   => 'error',
				/* translators: %s: X-Frame-Options value. */
				'message' => sprintf( __( 'The site sends X-Frame-Options: %s, which blocks any display in wp-admin. Remove this header and use frame-ancestors.', 'okno' ), $xfo ),
			);
		}

		return array(
			'level'   => 'warn',
			'message' => __( 'No header blocks display, so the editor will work. To stop other sites from embedding yours, add frame-ancestors anyway.', 'okno' ),
		);
	}

	/**
	 * POST /connection : mémorise le résultat du test du bridge (fait par le
	 * navigateur, seul à pouvoir charger le site dans une iframe).
	 */
	public function store_connection( WP_REST_Request $request ) {
		$value = array(
			'ok'       => (bool) $request->get_param( 'ok' ),
			'version'  => sanitize_text_field( (string) $request->get_param( 'version' ) ),
			'fields'   => absint( $request->get_param( 'fields' ) ),
			'sections' => absint( $request->get_param( 'sections' ) ),
			'at'       => time(),
		);
		update_option( Okno_Dashboard::OPTION_CONNECTION, $value, false );
		return rest_ensure_response( $value );
	}

	/**
	 * GET /activity?post=ID : historique des modifications d'un contenu.
	 */
	public function get_activity( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post' ) );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'okno_forbidden', __( 'Access denied.', 'okno' ), array( 'status' => 403 ) );
		}

		$items = array();
		foreach ( Okno_Activity::recent( 30, $post_id ) as $row ) {
			if ( ! current_user_can( 'edit_post', (int) $row->post_id ) ) {
				continue;
			}
			$items[] = array(
				'path'  => $row->field_path,
				'label' => $row->field_label,
				'type'  => $row->field_type,
				'old'   => $row->old_value,
				'new'   => $row->new_value,
				'user'  => $row->user_name,
				'when'  => mysql2date( 'c', $row->created_at . ' +0000' ),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * POST /posts : crée un contenu — le client n'avait aucun moyen de
	 * créer une page sans quitter l'éditeur.
	 */
	public function create_post( WP_REST_Request $request ) {
		$type  = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === trim( $title ) ) {
			return new WP_Error( 'okno_bad_request', __( 'A title is required.', 'okno' ), array( 'status' => 400 ) );
		}

		$status = 'publish' === $request->get_param( 'status' ) ? 'publish' : 'draft';
		$object = get_post_type_object( $type );
		if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => $status,
				'post_parent' => absint( $request->get_param( 'parent' ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$post_id->add_data( array( 'status' => 500 ) );
			return $post_id;
		}

		return rest_ensure_response( $this->post_item( get_post( $post_id ), new Okno_Url_Mapper() ) );
	}

	/**
	 * DELETE /posts/{id} : corbeille (ou suppression définitive si la corbeille
	 * est désactivée côté WordPress).
	 */
	public function delete_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'okno_not_found', __( 'Content not found.', 'okno' ), array( 'status' => 404 ) );
		}

		$result = wp_trash_post( $post_id );
		if ( ! $result ) {
			return new WP_Error( 'okno_delete_failed', __( 'Couldn’t delete this content.', 'okno' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			)
		);
	}

	/**
	 * Statut applicable : publier (ou rendre privé) demande le droit de
	 * publier ce type ; sans lui, « publish » devient « pending » (relecture),
	 * comme dans l'éditeur de WordPress. Fonction pure.
	 *
	 * @param string $requested   Statut demandé.
	 * @param bool   $can_publish Droit de publier ce type de contenu.
	 * @return string|null null si le statut n'est pas accepté.
	 */
	public static function allowed_status( $requested, $can_publish ) {
		if ( ! in_array( $requested, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return null;
		}
		if ( 'private' === $requested && ! $can_publish ) {
			return null;
		}
		if ( 'publish' === $requested && ! $can_publish ) {
			return 'pending';
		}
		return $requested;
	}

	/**
	 * POST /posts/{id}/status : publie, repasse en brouillon, soumet à relecture.
	 */
	public function set_status( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'okno_not_found', __( 'Content not found.', 'okno' ), array( 'status' => 404 ) );
		}
		$object = get_post_type_object( $post->post_type );
		$status = self::allowed_status( (string) $request->get_param( 'status' ), $object && current_user_can( $object->cap->publish_posts ) );
		if ( null === $status ) {
			return new WP_Error( 'okno_forbidden', __( 'You’re not allowed to give this status.', 'okno' ), array( 'status' => 403 ) );
		}
		if ( $status !== $post->post_status ) {
			$result = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 500 ) );
				return $result;
			}
		}
		return rest_ensure_response( $this->post_item( get_post( $post_id ), new Okno_Url_Mapper() ) );
	}

	/**
	 * POST /posts/{id}/restore : sort un contenu de la corbeille (en brouillon,
	 * comme WordPress depuis la 5.6).
	 */
	public function restore_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'trash' !== $post->post_status ) {
			return new WP_Error( 'okno_not_found', __( 'This content isn’t in the trash.', 'okno' ), array( 'status' => 404 ) );
		}
		if ( ! wp_untrash_post( $post_id ) ) {
			return new WP_Error( 'okno_restore_failed', __( 'Couldn’t restore this content.', 'okno' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( $this->post_item( get_post( $post_id ), new Okno_Url_Mapper() ) );
	}

	/**
	 * POST /posts/{id}/duplicate : copie le contenu, ses métas et ses champs ACF.
	 */
	public function duplicate_post( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'okno_not_found', __( 'Content not found.', 'okno' ), array( 'status' => 404 ) );
		}

		$copy_id = wp_insert_post(
			array(
				'post_type'      => $post->post_type,
				'post_title'     => sprintf( /* translators: %s: original title. */ __( '%s (copy)', 'okno' ), $post->post_title ),
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => 'draft',
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
			),
			true
		);

		if ( is_wp_error( $copy_id ) ) {
			$copy_id->add_data( array( 'status' => 500 ) );
			return $copy_id;
		}

		// Métas (ACF compris : les valeurs et leurs références de field key).
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( '_edit_lock' === $key || '_edit_last' === $key || '_okno_rev' === $key ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $copy_id, $key, maybe_unserialize( $value ) );
			}
		}

		// Champs Okno Collections : ils vivent dans la table de la collection.
		if ( class_exists( 'Okno_Collections' ) && Okno_Collections::for_post_type( $post->post_type ) ) {
			Okno_Collections_Store::write( $post->post_type, $copy_id, Okno_Collections_Store::values( $post->post_type, $post_id ) );
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $copy_id, $terms, $taxonomy );
			}
		}

		return rest_ensure_response( $this->post_item( get_post( $copy_id ), new Okno_Url_Mapper() ) );
	}

	private function post_item( $post, Okno_Url_Mapper $mapper ) {
		return array(
			'id'          => $post->ID,
			'title'       => Okno_Plugin::title( $post ),
			'type'        => $post->post_type,
			'status'      => $post->post_status,
			'modified'    => mysql2date( 'c', $post->post_modified_gmt . ' +0000', false ),
			'preview_url' => $mapper->preview_url( $post ),
			'front_url'   => $mapper->front_url( $post ),
			'can_delete'  => current_user_can( 'delete_post', $post->ID ),
		);
	}

	/**
	 * GET /schema/{id} : schéma + valeurs actuelles.
	 */
	public function get_schema( WP_REST_Request $request ) {
		$schema = ( new Okno_Schema( $this->plugin ) )->get( (int) $request['id'] );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		return rest_ensure_response( $schema );
	}

	/**
	 * POST /save/{id} : écrit { values: { path: value } }.
	 * Retourne le schéma frais (valeurs sanitizées) pour resynchroniser l'iframe.
	 */
	public function save( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$values  = $request->get_json_params();
		$values  = isset( $values['values'] ) && is_array( $values['values'] ) ? $values['values'] : null;

		if ( null === $values || empty( $values ) ) {
			return new WP_Error( 'okno_bad_request', __( 'Nothing to save.', 'okno' ), array( 'status' => 400 ) );
		}

		// Refus d'écraser une version plus récente (autre éditeur, autre onglet).
		$body     = $request->get_json_params();
		$revision = isset( $body['revision'] ) ? (string) $body['revision'] : '';
		$current  = Okno_Schema::revision( $post_id );
		if ( '' !== $revision && $revision !== $current ) {
			$fresh = ( new Okno_Schema( $this->plugin ) )->get( $post_id );
			return new WP_Error(
				'okno_conflict',
				__( 'This content was changed elsewhere since you opened it. Reload to get the latest version.', 'okno' ),
				array(
					'status' => 409,
					'schema' => is_wp_error( $fresh ) ? null : $fresh,
				)
			);
		}

		// Lock : un deploy déclenché pendant l'écriture serait un état intermédiaire.
		set_transient( Okno_Deploy_Manager::TRANSIENT_SAVE, 1, 30 );

		$schema_service = new Okno_Schema( $this->plugin );
		$result         = $schema_service->save( $post_id, $values );

		delete_transient( Okno_Deploy_Manager::TRANSIENT_SAVE );

		Okno_Plugin::bump_usage( 'save' );

		$fresh = $result['schema'] ? $result['schema'] : $schema_service->get( $post_id );

		return rest_ensure_response(
			array(
				'saved'  => $result['saved'],
				'errors' => (object) $result['errors'],
				'schema' => is_wp_error( $fresh ) ? null : $fresh,
			)
		);
	}

	/**
	 * POST /deploy : déclenche le driver configuré.
	 */
	public function deploy() {
		$record = Okno_Deploy_Manager::trigger();
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		return rest_ensure_response( $this->public_record( $record ) );
	}

	/**
	 * POST /deploy/flush : publie maintenant si une publication automatique
	 * attend (minuterie de l'éditeur, onglet qui se ferme). Idempotent.
	 *
	 * Accepte aussi un envoi navigator.sendBeacon(), qui ne peut pas poser
	 * l'en-tête X-WP-Nonce : le nonce REST arrive alors en paramètre _wpnonce,
	 * que l'authentification par cookie de WordPress lit aussi.
	 */
	public function deploy_flush() {
		$outcome = Okno_Deploy_Manager::flush_pending( 0 );
		if ( 'failed' === $outcome['state'] ) {
			return $outcome['error'];
		}

		$body = array(
			'state'    => $outcome['state'],
			'retry_in' => isset( $outcome['retry_in'] ) ? (int) $outcome['retry_in'] : 0,
			'message'  => isset( $outcome['error'] ) ? $outcome['error']->get_error_message() : '',
			'deploy'   => null,
		);

		if ( isset( $outcome['record'] ) ) {
			$body['deploy'] = $this->public_record( $outcome['record'] );
		} else {
			// Déjà publié par un autre déclencheur : l'éditeur affiche son statut.
			$history = Okno_Deploy_Manager::history();
			$latest  = reset( $history );
			if ( $latest && ( time() - (int) $latest['started'] ) < 600 ) {
				$fresh          = Okno_Deploy_Manager::get_status( $latest['id'] );
				$body['deploy'] = is_wp_error( $fresh ) ? null : $this->public_record( $fresh );
			}
		}

		return rest_ensure_response( $body );
	}

	/**
	 * GET /deploy/state : barre du haut de l'application (changements non
	 * publiés, déploiement en cours, dernier déploiement).
	 */
	public function deploy_state() {
		return rest_ensure_response( self::publish_state() );
	}

	/**
	 * État de la publication, sans données sensibles.
	 *
	 * @return array
	 */
	public static function publish_state() {
		$since   = Okno_Deploy_Manager::pending_since();
		$active  = Okno_Deploy_Manager::active_deploy();
		$history = Okno_Deploy_Manager::history();
		$last    = reset( $history );
		$auto    = Okno_Deploy_Manager::auto_enabled();
		return array(
			'pending' => (bool) $since,
			'auto'    => $auto,
			'due_in'  => $since && $auto ? max( 0, Okno_Deploy_Manager::auto_delay() - ( time() - $since ) ) : 0,
			'active'  => $active ? $active['id'] : '',
			'last'    => $last ? array(
				'status'  => $last['status'],
				'started' => (int) $last['started'],
				'user'    => $last['user_name'],
			) : null,
		);
	}

	/**
	 * GET /deploy/{deploy_id} : statut.
	 */
	public function deploy_status( WP_REST_Request $request ) {
		$record = Okno_Deploy_Manager::get_status( (string) $request['deploy_id'] );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		return rest_ensure_response( $this->public_record( $record ) );
	}

	/**
	 * Version exposable d'un record de deploy.
	 */
	private function public_record( array $record ) {
		return array(
			'deploy_id' => $record['id'],
			'driver'    => $record['driver'],
			'status'    => $record['status'],
			'message'   => $record['message'],
			'started'   => $record['started'],
			'eta'       => isset( $record['eta'] ) ? (int) $record['eta'] : 0,
			'run_url'   => isset( $record['meta']['run_url'] ) ? $record['meta']['run_url'] : '',
			'user'      => $record['user_name'],
		);
	}
}
