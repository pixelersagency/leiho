<?php
defined( 'ABSPATH' ) || exit;

/**
 * IA de Leiho, posée sur le client d'IA de WordPress (7.0+) : wp_ai_client_prompt()
 * et le fournisseur que le site a configuré dans Réglages → Connecteurs.
 * Leiho ne stocke aucune clé et ne choisit aucun fournisseur.
 *
 * Sur un WordPress sans client d'IA (avant 7.0), sans fournisseur capable de
 * générer du texte, ou quand le filtre wp_supports_ai le refuse, les outils
 * d'IA sont simplement absents ; les capacités (Abilities API, 6.9+) restent
 * enregistrées pour les agents externes, elles n'appellent pas de modèle.
 */
class Leiho_AI {

	/** Durée maximale d'une génération (secondes), filtre leiho_ai_timeout. */
	const TIMEOUT = 60;

	/** Taille maximale d'une image envoyée au modèle (octets). */
	const IMAGE_MAX_BYTES = 4194304;

	/**
	 * Fabrique du constructeur de prompt (tests : faux constructeur sans réseau).
	 *
	 * @var callable|null
	 */
	public static $factory = null;

	/** @var array|null Disponibilité calculée pour la requête. */
	private static $status = null;

	/**
	 * Crochets.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( 'Leiho_AI_Rest', 'register_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( 'Leiho_AI_Abilities', 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( 'Leiho_AI_Abilities', 'register' ) );
		add_action( 'wp_abilities_api_init', array( 'Leiho_AI_Admin_Abilities', 'register' ) );
	}

	/* ---------------------------------------------------------------------
	 * Disponibilité
	 * ------------------------------------------------------------------- */

	/**
	 * Le client d'IA de WordPress est-il là et autorisé ?
	 *
	 * @return bool
	 */
	public static function client_available() {
		if ( null !== self::$factory ) {
			return true;
		}
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	/**
	 * État de l'IA pour l'interface : client présent, texte et images
	 * pris en charge par un fournisseur configuré.
	 *
	 * @param bool $fresh Recalculer.
	 * @return array{client:bool,text:bool,image:bool}
	 */
	public static function status( $fresh = false ) {
		if ( null !== self::$status && ! $fresh ) {
			return self::$status;
		}
		$status = array(
			'client' => self::client_available(),
			'text'   => false,
			'image'  => false,
		);
		if ( $status['client'] ) {
			$status['text'] = self::supports( self::builder( 'Hello' ) );
			if ( $status['text'] ) {
				$probe = self::builder( 'Describe this image.' );
				if ( ! is_wp_error( $probe ) ) {
					self::call( $probe, 'with_file', array( self::probe_image(), 'image/png' ) );
				}
				$status['image'] = self::supports( $probe );
			}
		}
		/**
		 * Disponibilité de l'IA dans Leiho.
		 *
		 * @param array $status { client, text, image }.
		 */
		self::$status = apply_filters( 'leiho_ai_status', $status );
		return self::$status;
	}

	/**
	 * Remet à zéro l'état mémorisé (tests, changement de fournisseur).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$status = null;
	}

	/**
	 * Les outils d'IA sont-ils utilisables par l'utilisateur courant ?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$status = self::status();
		return $status['text'] && self::user_can();
	}

	/**
	 * Capability requise pour utiliser l'IA (filtre leiho_ai_capability).
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Capability nécessaire pour utiliser les outils d'IA de Leiho.
		 *
		 * @param string $capability Par défaut edit_posts.
		 */
		return (string) apply_filters( 'leiho_ai_capability', 'edit_posts' );
	}

	/**
	 * @return bool
	 */
	public static function user_can() {
		return current_user_can( self::capability() );
	}

	/**
	 * Écran des connecteurs de WordPress (configuration du fournisseur).
	 *
	 * @return string
	 */
	public static function connectors_url() {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * Données de l'interface (window.LeihoAIConfig).
	 *
	 * @return array
	 */
	public static function client_config() {
		$status = self::status();
		$brand  = Leiho_AI_Brand::get();
		$admin  = current_user_can( 'manage_options' );
		return array(
			'enabled'       => $status['text'] && self::user_can(),
			'image'         => $status['image'] && self::user_can(),
			'client'        => $status['client'],
			'canConfigure'  => $admin,
			'connectorsUrl' => $admin && function_exists( 'wp_ai_client_prompt' ) ? self::connectors_url() : '',
			'settingsUrl'   => $admin ? Leiho_App::url( 'ai' ) : '',
			'brandFilled'   => Leiho_AI_Brand::is_filled( $brand ),
			'language'      => $brand['language'],
			'canCollections' => class_exists( 'Leiho_Collections_Rest' ) && Leiho_Collections_Rest::can_manage(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Génération
	 * ------------------------------------------------------------------- */

	/**
	 * Nouveau constructeur de prompt.
	 *
	 * @param mixed $prompt Prompt initial.
	 * @return object|WP_Error WP_AI_Client_Prompt_Builder (ou faux constructeur).
	 */
	public static function builder( $prompt ) {
		if ( null !== self::$factory ) {
			return call_user_func( self::$factory, $prompt );
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'leiho_ai_unavailable', __( 'AI isn’t available on this site: it needs WordPress 7.0 or later.', 'leiho' ), array( 'status' => 501 ) );
		}
		try {
			return wp_ai_client_prompt( $prompt );
		} catch ( Exception $e ) {
			return new WP_Error( 'leiho_ai_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Le constructeur trouve-t-il un modèle pour générer ce texte ?
	 *
	 * @param object|WP_Error $builder Constructeur.
	 * @return bool
	 */
	private static function supports( $builder ) {
		if ( is_wp_error( $builder ) ) {
			return false;
		}
		$result = self::call( $builder, 'is_supported_for_text_generation', array() );
		return true === $result;
	}

	/**
	 * Appel protégé d'une méthode du constructeur.
	 *
	 * @param object $builder Constructeur.
	 * @param string $method  Méthode (snake_case).
	 * @param array  $args    Arguments.
	 * @return mixed|WP_Error
	 */
	private static function call( $builder, $method, array $args ) {
		try {
			return call_user_func_array( array( $builder, $method ), $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'leiho_ai_error', $e->getMessage(), array( 'status' => 500 ) );
		} catch ( Error $e ) {
			return new WP_Error( 'leiho_ai_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Génère un texte.
	 *
	 * Les options facultatives (consigne système, réponse JSON) font partie
	 * des exigences du modèle : si aucun modèle configuré ne les accepte, la
	 * demande est refaite sans elles (consigne et schéma passent dans le
	 * prompt), plutôt que d'échouer.
	 *
	 * @param string $system Consigne système.
	 * @param string $prompt Prompt.
	 * @param array  $opts   { schema: array|null (réponse JSON), image: array|null { data, mime } }.
	 * @return string|WP_Error
	 */
	public static function generate( $system, $prompt, array $opts = array() ) {
		if ( ! self::client_available() ) {
			return new WP_Error( 'leiho_ai_unavailable', __( 'AI isn’t available on this site.', 'leiho' ), array( 'status' => 503 ) );
		}
		$schema = isset( $opts['schema'] ) && is_array( $opts['schema'] ) ? $opts['schema'] : null;
		$image  = isset( $opts['image'] ) && is_array( $opts['image'] ) ? $opts['image'] : null;

		$attempts = array( true );
		if ( '' !== $system || null !== $schema ) {
			$attempts[] = false; // Repli : tout dans le prompt.
		}

		$timeout = self::timeout();
		$filter  = static function () use ( $timeout ) {
			return (float) $timeout;
		};
		add_filter( 'wp_ai_client_default_request_timeout', $filter );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hébergeurs qui l'interdisent.
		}

		$result = null;
		foreach ( $attempts as $native ) {
			$text = $prompt;
			if ( ! $native ) {
				$text = ( '' !== $system ? "INSTRUCTIONS\n" . $system . "\n\n" : '' ) . $prompt;
				if ( null !== $schema ) {
					$text .= "\n\nAnswer with a single JSON object matching this JSON schema, and nothing else:\n" . wp_json_encode( $schema );
				}
			}
			$builder = self::builder( $text );
			if ( is_wp_error( $builder ) ) {
				$result = $builder;
				break;
			}
			if ( $image ) {
				$builder = self::chain( $builder, 'with_file', array( $image['data'], $image['mime'] ) );
			}
			if ( $native && '' !== $system ) {
				$builder = self::chain( $builder, 'using_system_instruction', array( $system ) );
			}
			if ( $native && null !== $schema ) {
				$builder = self::chain( $builder, 'as_json_response', array( $schema ) );
			}
			if ( is_wp_error( $builder ) ) {
				$result = $builder;
				break;
			}
			if ( ! self::supports( $builder ) ) {
				$result = new WP_Error(
					$image ? 'leiho_ai_no_image' : 'leiho_ai_unsupported',
					$image
						? __( 'The configured AI provider can’t read images.', 'leiho' )
						: __( 'No configured AI provider can handle this request.', 'leiho' ),
					array( 'status' => 501 )
				);
				continue;
			}
			$result = self::call( $builder, 'generate_text', array() );
			// Schéma refusé par le fournisseur (sortie structurée plus stricte
			// que prévu) : nouvel essai avec le schéma dans le prompt.
			if ( $native && null !== $schema && is_wp_error( $result ) && 'prompt_client_error' === $result->get_error_code() ) {
				$data = $result->get_error_data();
				if ( is_array( $data ) && isset( $data['status'] ) && 400 === (int) $data['status'] ) {
					continue;
				}
			}
			break;
		}

		remove_filter( 'wp_ai_client_default_request_timeout', $filter );

		if ( is_wp_error( $result ) ) {
			return self::readable_error( $result );
		}
		if ( ! is_string( $result ) || '' === trim( $result ) ) {
			return new WP_Error( 'leiho_ai_empty', __( 'The AI returned an empty answer. Try again.', 'leiho' ), array( 'status' => 502 ) );
		}
		return $result;
	}

	/**
	 * Appel chaîné : renvoie le constructeur (ou l'erreur).
	 *
	 * @param object $builder Constructeur.
	 * @param string $method  Méthode.
	 * @param array  $args    Arguments.
	 * @return object|WP_Error
	 */
	private static function chain( $builder, $method, array $args ) {
		$result = self::call( $builder, $method, $args );
		return is_wp_error( $result ) ? $result : ( is_object( $result ) ? $result : $builder );
	}

	/**
	 * Durée maximale d'une génération.
	 *
	 * @return int
	 */
	public static function timeout() {
		/**
		 * Durée maximale d'une génération, en secondes.
		 *
		 * @param int $timeout Par défaut 60.
		 */
		return max( 5, (int) apply_filters( 'leiho_ai_timeout', self::TIMEOUT ) );
	}

	/**
	 * Erreur lisible pour l'éditeur (le message technique reste dans data).
	 *
	 * @param WP_Error $error Erreur du client d'IA.
	 * @return WP_Error
	 */
	public static function readable_error( WP_Error $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$http = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		switch ( $code ) {
			case 'prompt_prevented':
				$message = __( 'AI is turned off on this site.', 'leiho' );
				$status  = 503;
				break;
			case 'prompt_network_error':
				$message = __( 'The AI provider didn’t answer in time or couldn’t be reached. Try again in a moment.', 'leiho' );
				$status  = 504;
				break;
			case 'prompt_client_error':
				if ( 401 === $http || 403 === $http ) {
					$message = __( 'The AI provider refused the request: check its API key in Settings → Connectors.', 'leiho' );
				} elseif ( 429 === $http ) {
					$message = __( 'The AI provider is rate limiting this site. Wait a minute and try again.', 'leiho' );
				} else {
					$message = __( 'The AI provider rejected the request.', 'leiho' );
				}
				$status = 502;
				break;
			case 'prompt_upstream_server_error':
				$message = __( 'The AI provider had an error. Try again in a moment.', 'leiho' );
				$status  = 502;
				break;
			case 'prompt_token_limit_reached':
				$message = __( 'The text is too long for the AI model. Shorten it and try again.', 'leiho' );
				$status  = 413;
				break;
			default:
				if ( 0 === strpos( (string) $code, 'leiho_' ) ) {
					return $error;
				}
				$message = __( 'The AI couldn’t generate this text.', 'leiho' );
				$status  = 500;
		}
		return new WP_Error(
			'leiho_ai_' . preg_replace( '/^prompt_/', '', (string) $code ),
			$message,
			array(
				'status' => $status,
				'detail' => substr( (string) $error->get_error_message(), 0, 300 ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Limite de débit
	 * ------------------------------------------------------------------- */

	/**
	 * Compte une requête de l'utilisateur ; erreur au-delà de la limite.
	 *
	 * @param int $user_id Utilisateur.
	 * @return true|WP_Error
	 */
	public static function throttle( $user_id ) {
		/**
		 * Limite de requêtes d'IA par utilisateur.
		 *
		 * @param array $limit { requests: int, window: int (secondes) } ; requests 0 = aucune limite.
		 * @param int   $user_id Utilisateur.
		 */
		$user = apply_filters(
			'leiho_ai_rate_limit',
			array(
				'requests' => 20,
				'window'   => 60,
			),
			$user_id
		);
		/**
		 * Limite de requêtes d'IA pour tout le site (tous utilisateurs) : borne
		 * la facture du fournisseur même si plusieurs comptes s'y mettent.
		 *
		 * @param array $limit { requests: int, window: int (secondes) } ; requests 0 = aucune limite.
		 */
		$site = apply_filters(
			'leiho_ai_site_rate_limit',
			array(
				'requests' => 120,
				'window'   => 60,
			)
		);

		// Lire, vérifier et compter sous un verrou : des requêtes parallèles
		// passeraient sinon toutes avant que le compteur ne bouge.
		$locked = self::rate_lock();
		try {
			foreach ( array( 'leiho_ai_rl_' . (int) $user_id => $user, 'leiho_ai_rl_site' => $site ) as $key => $limit ) {
				$wait = self::over_limit( $key, $limit );
				if ( $wait ) {
					return new WP_Error(
						'leiho_ai_rate_limited',
						/* translators: %d: seconds to wait. */
						sprintf( _n( 'Too many AI requests. Try again in %d second.', 'Too many AI requests. Try again in %d seconds.', $wait, 'leiho' ), $wait ),
						array(
							'status'      => 429,
							'retry_after' => $wait,
						)
					);
				}
			}
			foreach ( array( 'leiho_ai_rl_' . (int) $user_id => $user, 'leiho_ai_rl_site' => $site ) as $key => $limit ) {
				self::count_request( $key, $limit );
			}
			return true;
		} finally {
			if ( $locked ) {
				delete_option( 'leiho_ai_rl_lock' );
			}
		}
	}

	/**
	 * Secondes à attendre si la fenêtre est pleine, 0 sinon.
	 *
	 * @param string $key   Transient du compteur.
	 * @param array  $limit { requests, window }.
	 * @return int
	 */
	private static function over_limit( $key, $limit ) {
		$requests = isset( $limit['requests'] ) ? (int) $limit['requests'] : 0;
		$window   = isset( $limit['window'] ) ? max( 1, (int) $limit['window'] ) : 60;
		if ( $requests <= 0 ) {
			return 0;
		}
		$state = self::rate_state( $key, $window );
		return (int) $state['count'] >= $requests ? max( 1, $window - ( time() - (int) $state['start'] ) ) : 0;
	}

	private static function count_request( $key, $limit ) {
		$requests = isset( $limit['requests'] ) ? (int) $limit['requests'] : 0;
		$window   = isset( $limit['window'] ) ? max( 1, (int) $limit['window'] ) : 60;
		if ( $requests <= 0 ) {
			return;
		}
		$state          = self::rate_state( $key, $window );
		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, $window );
	}

	private static function rate_state( $key, $window ) {
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['start'], $state['count'] ) || time() - (int) $state['start'] >= $window ) {
			$state = array(
				'start' => time(),
				'count' => 0,
			);
		}
		return $state;
	}

	/**
	 * Verrou court du compteur (add_option est atomique). Au-delà de 2 s
	 * d'attente, on compte quand même : mieux vaut une requête de trop qu'un
	 * éditeur bloqué par un verrou abandonné.
	 *
	 * @return bool Verrou pris.
	 */
	private static function rate_lock() {
		$until = microtime( true ) + 2;
		do {
			if ( add_option( 'leiho_ai_rl_lock', time() + 5, '', false ) ) {
				return true;
			}
			if ( (int) get_option( 'leiho_ai_rl_lock', 0 ) < time() ) {
				delete_option( 'leiho_ai_rl_lock' );
				continue;
			}
			usleep( 50000 );
		} while ( microtime( true ) < $until );
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Champs d'un type de contenu (nouvelle entrée, capacités)
	 * ------------------------------------------------------------------- */

	/**
	 * Champs d'un type de contenu au format de l'éditeur, sans valeurs :
	 * titre, contenu et extrait natifs, puis les champs des adapters.
	 *
	 * @param string $post_type Type.
	 * @return array
	 */
	public static function type_fields( $post_type ) {
		$fields = array(
			array(
				'path'      => Leiho_Schema::PATH_TITLE,
				'type'      => 'text',
				'label'     => __( 'Title', 'leiho' ),
				'required'  => true,
				'supported' => true,
			),
		);
		$post = (object) array(
			'ID'           => 0,
			'post_type'    => $post_type,
			'post_content' => '',
			'post_excerpt' => '',
		);
		foreach ( Leiho_Native_Fields::content_fields( $post ) as $field ) {
			unset( $field['value'] );
			$fields[] = $field;
		}
		foreach ( Leiho_Plugin::instance()->adapters() as $adapter ) {
			if ( $adapter instanceof Leiho_Field_Adapter ) {
				$fields = array_merge( $fields, $adapter->describe_type( $post_type ) );
			}
		}
		return $fields;
	}

	/* ---------------------------------------------------------------------
	 * Divers
	 * ------------------------------------------------------------------- */

	/**
	 * Image d'essai (PNG 1×1) pour savoir si un modèle lit les images.
	 *
	 * @return string
	 */
	private static function probe_image() {
		return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	}

	/**
	 * Image d'un fichier de la médiathèque, prête pour le modèle : la taille
	 * « large » si elle existe (moins lourde), en data URI.
	 *
	 * @param int $attachment_id Fichier.
	 * @return array{data:string,mime:string}|WP_Error
	 */
	public static function attachment_image( $attachment_id ) {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime, 'image/' ) || in_array( $mime, array( 'image/svg+xml' ), true ) ) {
			return new WP_Error( 'leiho_ai_not_image', __( 'This file isn’t an image the AI can read.', 'leiho' ), array( 'status' => 400 ) );
		}
		$path = '';
		if ( function_exists( 'image_get_intermediate_size' ) ) {
			$large = image_get_intermediate_size( $attachment_id, 'large' );
			if ( $large && ! empty( $large['path'] ) ) {
				$uploads = wp_get_upload_dir();
				$path    = trailingslashit( $uploads['basedir'] ) . $large['path'];
			}
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			$path = (string) get_attached_file( $attachment_id );
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'leiho_ai_no_file', __( 'The image file can’t be read on the server.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( filesize( $path ) > self::IMAGE_MAX_BYTES ) {
			return new WP_Error( 'leiho_ai_image_too_big', __( 'This image is too large to be sent to the AI.', 'leiho' ), array( 'status' => 413 ) );
		}
		$check = wp_check_filetype( $path );
		$type  = ! empty( $check['type'] ) ? $check['type'] : $mime;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fichier local de la médiathèque.
		return array(
			'data' => 'data:' . $type . ';base64,' . base64_encode( (string) file_get_contents( $path ) ),
			'mime' => $type,
		);
	}
}
