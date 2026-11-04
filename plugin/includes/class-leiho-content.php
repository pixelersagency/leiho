<?php
defined( 'ABSPATH' ) || exit;

/**
 * API de contenu pour le front headless.
 *
 *   GET /leiho/v1/export         tout le contenu publié, en une réponse mise en cache
 *   GET /leiho/v1/content        liste filtrable (type, path, slug), paginée
 *   GET /leiho/v1/content/{id}   un contenu
 *
 * Le front lit le contenu ici et nulle part ailleurs : WordPress est la
 * seule source de vérité, le dépôt du front ne garde que le code et les types.
 *
 * Un build statique fait une seule requête (/export) au lieu d'une par page :
 * la base de données ne sature plus sur un hébergement mutualisé. La réponse
 * est calculée une fois, gardée en cache (Leiho_Cache : cache objet persistant
 * s'il y en a un, sinon option) et invalidée à chaque modification de contenu.
 * Toutes les réponses portent un ETag : un client à jour reçoit un 304.
 */
class Leiho_Content {

	const OPTION_CACHE = 'leiho_export_cache';

	/** Secret donnant accès à l'API quand elle est restreinte. */
	const SECRET_TOKEN = 'content_token';

	/** Format de la réponse : incrémenté à chaque changement incompatible. */
	const FORMAT = 1;

	/** Métas dont l'écriture ne change pas le contenu publié. */
	const IGNORED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme' );

	/** @var Leiho_Plugin */
	private $plugin;

	/** @var Leiho_Url_Mapper|null */
	private static $mapper = null;

	/** Cache déjà vidé dans cette requête : un save ACF écrit des dizaines de métas. */
	private static $invalidated = false;

	public function __construct( Leiho_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Branche l'invalidation du cache sur toutes les écritures de contenu.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'edit_attachment' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'post_changed' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 3 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ) );
		}
		// Réglages qui changent les chemins ou la page d'accueil.
		foreach ( array( 'update_option_page_on_front', 'update_option_' . Leiho_Plugin::OPTION_SETTINGS, 'update_option_blogname' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route(
			Leiho_Rest::NS,
			'/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_export' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'type'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'path'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'slug'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 100,
					),
				),
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/content/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_types' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * Accès en lecture : public par défaut (le contenu publié l'est déjà sur
	 * le site), ou réservé au porteur du jeton quand l'accès est restreint.
	 * Un utilisateur connecté qui peut éditer passe toujours.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool|WP_Error
	 */
	public static function can_read( $request ) {
		$settings = Leiho_Plugin::settings();
		if ( 'token' !== $settings['content_access'] || current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$expected = (string) Leiho_Secrets::get( self::SECRET_TOKEN );
		$given    = self::request_token( $request );
		if ( '' !== $expected && '' !== $given && hash_equals( $expected, $given ) ) {
			return true;
		}

		return new WP_Error( 'leiho_forbidden', __( 'A valid Leiho content token is required.', 'leiho' ), array( 'status' => 401 ) );
	}

	/**
	 * Jeton envoyé par le front : en-tête X-Leiho-Token (Apache retire souvent
	 * Authorization chez les hébergeurs mutualisés), sinon Bearer.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return string
	 */
	public static function request_token( $request ) {
		$token = (string) $request->get_header( 'x_leiho_token' );
		if ( '' === $token ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $auth, 'Bearer ' ) ) {
				$token = substr( $auth, 7 );
			}
		}
		return trim( $token );
	}

	/**
	 * GET /export : tout le contenu publié des types configurés.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_export( WP_REST_Request $request ) {
		$cache = Leiho_Cache::get_export();
		if ( ! is_array( $cache ) || ! isset( $cache['etag'], $cache['body'] ) || self::FORMAT !== ( isset( $cache['format'] ) ? $cache['format'] : 0 ) ) {
			$cache = $this->build_export();
			Leiho_Cache::set_export( $cache );
		}

		$etag    = '"' . $cache['etag'] . '"';
		$headers = Leiho_Cache::headers( $etag );

		if ( Leiho_Cache::not_modified( $request, $etag ) ) {
			return new WP_REST_Response( null, 304, $headers );
		}

		// Décodé en objets : un champ vide reste {} et non [] une fois réencodé.
		return new WP_REST_Response( json_decode( $cache['body'] ), 200, $headers );
	}

	/**
	 * Calcule l'export (sans cache).
	 *
	 * @return array{format:int,etag:string,body:string}
	 */
	public function build_export() {
		$items = array();
		$query = array(
			'post_type'              => self::post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => array(
				'post_type'  => 'ASC',
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'suppress_filters'       => false,
		);
		foreach ( get_posts( $query ) as $post ) {
			$items[] = $this->item( $post );
		}

		$data = array(
			'format'    => self::FORMAT,
			'generated' => gmdate( 'c' ),
			'site'      => array(
				'name'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'front_url' => Leiho_Plugin::settings()['front_url'],
				'cms_url'   => home_url(),
				'language'  => get_bloginfo( 'language' ),
			),
			'items'     => $items,
		);

		// L'empreinte ne dépend que du contenu, pas de la date de génération :
		// un cache recalculé sans changement garde le même ETag.
		self::$invalidated = false;
		$etag            = substr( md5( wp_json_encode( $items ) . wp_json_encode( $data['site'] ) ), 0, 20 );
		$data['version'] = $etag;

		return array(
			'format' => self::FORMAT,
			'etag'   => $etag,
			'body'   => wp_json_encode( $data ),
		);
	}

	/**
	 * GET /content : liste filtrée et paginée.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ) {
		$types = self::post_types();
		$type  = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return new WP_Error( 'leiho_unknown_type', __( 'This content type is not served by Leiho.', 'leiho' ), array( 'status' => 400 ) );
			}
			$types = array( $type );
		}

		$path = (string) $request->get_param( 'path' );
		if ( '' !== $path ) {
			// Résolution gardée en cache (id ou 0) : le repli parcourt tous les contenus.
			$id   = (int) Leiho_Cache::remember(
				'path:' . md5( implode( ',', $types ) . '|' . $path ),
				static function () use ( $path, $types ) {
					$found = self::find_by_path( $path, $types );
					return $found ? (int) $found->ID : 0;
				}
			);
			$post = $id ? get_post( $id ) : null;
			return Leiho_Cache::response( $request, $post ? array( $this->cached_item( $post ) ) : array() );
		}

		$per_page = min( 500, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$args     = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$slug     = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' !== $slug ) {
			$args['name'] = $slug;
		}

		$query    = new WP_Query( $args );
		$items    = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->cached_item( $post );
		}

		$response = Leiho_Cache::response( $request, $items );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
		return $response;
	}

	/**
	 * GET /content/{id}.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		// Brouillons : seulement pour qui peut les éditer (aperçu).
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		return Leiho_Cache::response( $request, $this->cached_item( $post ), 'publish' !== $post->post_status );
	}

	/**
	 * GET /types : déclarations TypeScript du contenu.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_types( WP_REST_Request $request ) {
		$body    = (string) Leiho_Cache::remember(
			'types',
			static function () {
				return Leiho_Types::generate( Leiho_Plugin::instance() );
			}
		);
		$etag    = Leiho_Cache::etag( $body );
		$headers = Leiho_Cache::headers( $etag );
		if ( Leiho_Cache::not_modified( $request, $etag ) ) {
			return new WP_REST_Response( null, 304, $headers );
		}

		$response = new WP_REST_Response( null, 200, $headers );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		// Corps texte brut : WordPress encoderait une chaîne en JSON.
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result ) use ( $response, $body ) {
				if ( $result !== $response ) {
					return $served;
				}
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Code source, pas du HTML.
				return true;
			},
			10,
			2
		);
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Mise en forme
	 * ------------------------------------------------------------------- */

	/**
	 * Un contenu tel que le front le consomme.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public function item( $post ) {
		// Filtres de the_content, extraits, shortcodes : beaucoup lisent le post
		// global (get_the_ID()…). On le positionne le temps du rendu.
		$previous        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$item = $this->render_item( $post );

		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}

		/**
		 * Contenu servi au front : ajouter un champ (SEO, auteur…) ou en retirer.
		 *
		 * @param array   $item Contenu.
		 * @param WP_Post $post Post.
		 */
		return apply_filters( 'leiho_content_item', $item, $post );
	}

	/**
	 * Contenu publié : depuis le cache objet (persistant). Brouillon : jamais
	 * mis en cache, il n'est servi qu'à qui peut l'éditer.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function cached_item( $post ) {
		if ( 'publish' !== $post->post_status ) {
			return $this->item( $post );
		}
		return Leiho_Cache::remember(
			'item:' . (int) $post->ID,
			function () use ( $post ) {
				return $this->item( $post );
			}
		);
	}

	/**
	 * @param WP_Post $post Post (déjà positionné en global).
	 * @return array
	 */
	private function render_item( $post ) {
		$fields = array();
		foreach ( $this->plugin->adapters() as $adapter ) {
			if ( $adapter instanceof Leiho_Content_Adapter_Interface ) {
				$fields = array_merge( $fields, $adapter->get_content( $post->ID ) );
			}
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || ! $object->public ) {
				continue;
			}
			$list = get_the_terms( $post, $taxonomy );
			if ( ! $list || is_wp_error( $list ) ) {
				continue;
			}
			foreach ( $list as $term ) {
				$terms[ $taxonomy ][] = self::term_data( $term );
			}
		}

		$mapper = self::mapper();
		$item   = array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'path'           => $mapper->path( $post ),
			'url'            => $mapper->front_url( $post ),
			'title'          => Leiho_Plugin::title( $post ),
			'date'           => mysql_to_rfc3339( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : $post->post_date ),
			'modified'       => mysql_to_rfc3339( $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ),
			'parent'         => $post->post_parent ? (int) $post->post_parent : null,
			'menu_order'     => (int) $post->menu_order,
			'excerpt'        => html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'content'        => post_type_supports( $post->post_type, 'editor' ) && '' !== $post->post_content
				? self::html( $post->post_content, null )
				: '',
			'featured_image' => self::media( (int) get_post_thumbnail_id( $post ) ),
			'terms'          => (object) $terms,
			'fields'         => (object) $fields,
		);

		return $item;
	}

	/**
	 * Image ou fichier de la médiathèque.
	 *
	 * @param int $id Pièce jointe.
	 * @return array|null
	 */
	public static function media( $id ) {
		$id = (int) $id;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}

		$url  = (string) wp_get_attachment_url( $id );
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$media = array(
			'id'     => $id,
			'url'    => $url,
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'  => Leiho_Plugin::title( $id ),
			'mime'   => (string) get_post_mime_type( $id ),
			'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'sizes'  => new stdClass(),
		);

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$sizes = array();
			$base  = trailingslashit( dirname( $url ) );
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$sizes[ $name ] = array(
					'url'    => $base . $size['file'],
					'width'  => isset( $size['width'] ) ? (int) $size['width'] : null,
					'height' => isset( $size['height'] ) ? (int) $size['height'] : null,
				);
			}
			$media['sizes'] = (object) $sizes;
		}

		return $media;
	}

	/**
	 * Référence vers un autre contenu (relation, lien de page).
	 *
	 * @param int $id Post.
	 * @return array|null
	 */
	public static function ref( $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$mapper = self::mapper();
		return array(
			'id'    => (int) $post->ID,
			'type'  => $post->post_type,
			'slug'  => $post->post_name,
			'title' => Leiho_Plugin::title( $post ),
			'path'  => $mapper->path( $post ),
		);
	}

	/**
	 * Terme de taxonomie.
	 *
	 * @param int $id Terme.
	 * @return array|null
	 */
	public static function term( $id ) {
		$term = $id ? get_term( $id ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return self::term_data( $term );
	}

	private static function term_data( $term ) {
		return array(
			'id'       => (int) $term->term_id,
			'slug'     => (string) $term->slug,
			'name'     => html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ),
			'taxonomy' => (string) $term->taxonomy,
		);
	}

	/**
	 * HTML rendu d'un wysiwyg ou du contenu principal.
	 *
	 * Champ ACF : mêmes filtres que get_field() formaté (acf_the_content :
	 * paragraphes, shortcodes). Contenu principal : the_content.
	 *
	 * @param string     $html  Valeur stockée.
	 * @param array|null $field Champ ACF, null pour le contenu principal.
	 * @return string
	 */
	public static function html( $html, $field ) {
		if ( '' === $html ) {
			return '';
		}
		if ( null === $field ) {
			$rendered = apply_filters( 'the_content', $html );
		} elseif ( function_exists( 'has_filter' ) && has_filter( 'acf_the_content' ) ) {
			$rendered = apply_filters( 'acf_the_content', $html );
		} elseif ( ! empty( $field['leiho_acf'] ) ) {
			// Champ importé d'ACF, ACF désactivé : même rendu qu'ACF.
			$rendered = self::acf_render( $html );
		} else {
			$rendered = wpautop( $html );
		}

		/**
		 * HTML servi au front pour un wysiwyg ou le contenu principal.
		 *
		 * @param string     $rendered HTML rendu.
		 * @param string     $html     Valeur stockée.
		 * @param array|null $field    Champ ACF (null : contenu principal).
		 */
		return (string) apply_filters( 'leiho_content_html', $rendered, $html, $field );
	}

	/**
	 * Rendu d'un wysiwyg tel qu'ACF le fait (filtre acf_the_content : ceux de
	 * the_content sans les blocs), pour un champ importé d'ACF quand ACF n'est
	 * plus actif : le front reçoit le même HTML (paragraphes, guillemets,
	 * shortcodes, embeds, images responsives).
	 *
	 * @param string $html Valeur stockée.
	 * @return string
	 */
	public static function acf_render( $html ) {
		$embed = isset( $GLOBALS['wp_embed'] ) ? $GLOBALS['wp_embed'] : null;
		if ( $embed && method_exists( $embed, 'run_shortcode' ) ) {
			$html = $embed->run_shortcode( $html );
			$html = $embed->autoembed( $html );
		}
		foreach ( array( 'wptexturize', 'wpautop', 'shortcode_unautop', 'wp_filter_content_tags', 'capital_P_dangit', 'do_shortcode', 'convert_smilies' ) as $filter ) {
			if ( function_exists( $filter ) ) {
				$html = call_user_func( $filter, $html );
			}
		}
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Outils
	 * ------------------------------------------------------------------- */

	/**
	 * Types servis : ceux édités dans Leiho (réglage), filtrables.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$settings = Leiho_Plugin::settings();
		/**
		 * Types de contenu servis par l'API de contenu.
		 *
		 * @param string[] $types Par défaut, les types édités dans Leiho.
		 */
		return array_values( array_unique( (array) apply_filters( 'leiho_content_post_types', $settings['post_types'] ) ) );
	}

	/**
	 * Contenu publié dont le chemin sur le front est $path.
	 *
	 * @param string   $path  Chemin (/services/audit).
	 * @param string[] $types Types cherchés.
	 * @return WP_Post|null
	 */
	public static function find_by_path( $path, $types ) {
		$path   = '/' . trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
		$mapper = self::mapper();

		// Raccourci : le dernier segment est presque toujours le slug.
		$slug       = sanitize_title( basename( $path ) );
		$candidates = '/' === $path
			? array_filter( array( get_post( (int) get_option( 'page_on_front' ) ) ) )
			: get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'name'           => $slug,
					'posts_per_page' => 20,
				)
			);

		foreach ( $candidates as $post ) {
			if ( in_array( $post->post_type, $types, true ) && untrailingslashit( $mapper->path( $post ) ) === untrailingslashit( $path ) ) {
				return $post;
			}
		}

		// Chemin porté par une méta (réglage « champ qui contient l'URL ») :
		// le slug n'y figure pas forcément.
		foreach ( get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		) as $post ) {
			if ( untrailingslashit( $mapper->path( $post ) ) === untrailingslashit( $path ) ) {
				return $post;
			}
		}
		return null;
	}

	private static function mapper() {
		if ( null === self::$mapper ) {
			self::$mapper = new Leiho_Url_Mapper();
		}
		return self::$mapper;
	}

	/* ---------------------------------------------------------------------
	 * Invalidation
	 * ------------------------------------------------------------------- */

	/**
	 * Vide le cache de contenu (option d'export et génération du cache
	 * objet) et programme la purge des caches de page en fin de requête.
	 *
	 * @return void
	 */
	public static function invalidate() {
		if ( ! self::$invalidated ) {
			self::$invalidated = true;
			delete_option( self::OPTION_CACHE );
			Leiho_Cache::bump();
			// Encore une fois en fin de requête : un build lancé entre deux
			// écritures aurait remis en cache un état à moitié enregistré.
			add_action(
				'shutdown',
				static function () {
					delete_option( Leiho_Content::OPTION_CACHE );
					Leiho_Cache::bump();
				}
			);
			Leiho_Cache::queue_purge();
		}
	}

	/**
	 * Un post a changé : vide le cache, et signale le changement s'il touche
	 * le contenu servi (publication automatique).
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function post_changed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		self::invalidate();

		// Un média seul (téléversé, recadré) ne change rien tant qu'aucun
		// contenu ne l'utilise : il vide le cache sans déclencher de publication.
		if ( in_array( $post->post_type, self::post_types(), true ) ) {
			/**
			 * Le contenu servi au front a changé.
			 *
			 * @param int $post_id Post modifié.
			 */
			do_action( 'leiho_content_changed', (int) $post_id );
		}
	}

	/**
	 * Une méta a changé (ACF écrit des métas sans toucher au post).
	 *
	 * @param int|int[] $meta_id  Méta(s).
	 * @param int       $post_id  Post.
	 * @param string    $meta_key Clé.
	 * @return void
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, self::IGNORED_META, true ) ) {
			return;
		}
		self::post_changed( $post_id );
	}
}
