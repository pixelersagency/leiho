<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capacités d'administration de Leiho (Abilities API, WordPress 6.9+) : un
 * agent connecté avec un compte administrateur diagnostique et règle la
 * partie technique (URL du site, mode de publication, GitHub, API de
 * contenu), avec la même validation que l'écran Réglages.
 *
 * Garde-fous : manage_options (filtre leiho_ai_admin_capability) ; aucun
 * secret n'est jamais rendu, ni en entier ni en partie (seulement son état) ;
 * les URL qui portent souvent un jeton (build hook, Coolify) ne sont rendues
 * que par leur hôte ; aucune capacité ne publie ni ne déploie.
 */
class Leiho_AI_Admin_Abilities {

	/**
	 * Réglages modifiables par leiho/update-settings. Les adresses où partent
	 * le contenu et les jetons (front, aperçu, build hook, Coolify, dépôt
	 * GitHub) n'y sont pas : un agent trompé par un texte qu'il lit pourrait
	 * sinon les envoyer ailleurs. Elles se changent dans les Réglages.
	 */
	const SETTINGS_KEYS = array(
		'post_types',
		'url_patterns',
		'path_meta_key',
		'deploy_driver',
		'build_hook_eta',
		'gh_branch',
		'gh_workflow',
		'min_deploy_interval',
		'auto_deploy',
		'auto_deploy_delay',
		'content_access',
		'content_cache',
		'editor_landing',
		'headless_noindex',
		'headless_redirect',
		'headless_lockdown',
		'headless_comments',
		'security_file_edit',
		'security_login',
	);

	/** Réglages lus par leiho/get-diagnostics mais jamais écrits par un agent. */
	const READ_ONLY_KEYS = array( 'front_url', 'preview_url', 'build_hook_url', 'coolify_url', 'gh_repo' );

	/** Réglages rendus par leur hôte seulement (l'URL porte souvent un jeton). */
	const HOST_ONLY = array( 'build_hook_url', 'coolify_url' );

	/** Secrets modifiables par leiho/set-secret. */
	const SECRETS = array( 'gh_pat', 'coolify_token', 'content_token' );

	/** Publications rendues par le diagnostic. */
	const DEPLOYS = 10;

	/**
	 * Définitions des capacités (nom => arguments de wp_register_ability).
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$meta = static function ( $annotations ) {
			return array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			);
		};

		return array(
			'leiho/get-diagnostics'  => array(
				'label'               => __( 'Get the diagnostics', 'leiho' ),
				'description'         => __( 'Administrators only. One report on the technical side of Leiho: versions, settings (secrets reduced to set / empty / unreadable, hook URLs reduced to their host), last bridge test, a fresh check of the site’s frame-ancestors / X-Frame-Options headers, GitHub check (repository, branch, workflow, token and its expiry), last deployments, automatic publishing, content API, AI provider, migration from Okno, and the problems found, each with a fix in plain English. Read it before changing any setting.', 'leiho' ),
				'category'            => Leiho_AI_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'network' => array(
							'type'        => 'boolean',
							'description' => __( 'Run the checks that call the site and GitHub (default true).', 'leiho' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_diagnostics' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
			'leiho/update-settings'  => array(
				'label'               => __( 'Update the settings', 'leiho' ),
				'description'         => __( 'Administrators only. Changes technical settings of Leiho, validated like the Settings screen. Only the keys given change. Keys: post_types (array), url_patterns (object: content type => pattern with {slug} or {id}), path_meta_key, deploy_driver (live, build_hook, github, github_commit, coolify or empty), build_hook_eta (minutes), gh_branch, gh_workflow (file name), min_deploy_interval (seconds), auto_deploy, auto_deploy_delay (seconds, at least 15), content_access (public or token), content_cache, editor_landing, headless_noindex, headless_redirect, headless_lockdown, headless_comments, security_file_edit, security_login. The site, preview, build hook and Coolify addresses and the GitHub repository are not changed by agents: an administrator sets them in Leiho → Settings. Pass dry_run to see the changes without saving. Returns the changes and the result of the related checks. Secrets go through leiho/set-secret.', 'leiho' ),
				'category'            => Leiho_AI_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'settings' => array(
							'type'                 => 'object',
							'description'          => __( 'Settings to change, by key.', 'leiho' ),
							'additionalProperties' => true,
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => __( 'Return the changes without saving them (default false).', 'leiho' ),
						),
					),
					'required'             => array( 'settings' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'update_settings' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			),
			'leiho/set-secret'       => array(
				'label'               => __( 'Set a secret', 'leiho' ),
				'description'         => __( 'Administrators only. Write-only: stores the GitHub token (gh_pat) or the Coolify token (coolify_token) encrypted, or renews the content API token (content_token, with regenerate: the front end must then get the new one from the Settings screen). The value is never returned. An empty value clears a secret only with clear: true. Returns the new state and, for gh_pat, the GitHub check.', 'leiho' ),
				'category'            => Leiho_AI_Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'       => array(
							'type'        => 'string',
							'description' => __( 'Secret to set.', 'leiho' ),
							'enum'        => self::SECRETS,
						),
						'value'      => array(
							'type'        => 'string',
							'description' => __( 'New value (gh_pat, coolify_token).', 'leiho' ),
						),
						'clear'      => array(
							'type'        => 'boolean',
							'description' => __( 'Delete the secret (default false).', 'leiho' ),
						),
						'regenerate' => array(
							'type'        => 'boolean',
							'description' => __( 'content_token only: generate a new token; the current one stops working.', 'leiho' ),
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'set_secret' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			),
			'leiho/test-connection'  => array(
				'label'               => __( 'Test the connection', 'leiho' ),
				'description'         => __( 'Administrators only. Runs the checks a server can run: the site’s headers (can wp-admin display it?) and, with GitHub publishing, the GitHub check. Returns each verdict with a fix. The bridge handshake needs a browser: it isn’t tested here.', 'leiho' ),
				'category'            => Leiho_AI_Abilities::CATEGORY,
				// Sans paramètre : pas de schéma d'entrée (l'adaptateur MCP en
				// fournit un objet vide).
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'test_connection' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => $meta(
					array(
						'readonly'    => true,
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
	 * Capability requise (filtre leiho_ai_admin_capability).
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Capability nécessaire aux capacités d'administration de Leiho.
		 *
		 * @param string $capability Par défaut manage_options.
		 */
		return (string) apply_filters( 'leiho_ai_admin_capability', 'manage_options' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	/* ---------------------------------------------------------------------
	 * Exécution
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $input { network }.
	 * @return array
	 */
	public static function get_diagnostics( $input = null ) {
		$network  = ! ( is_array( $input ) && isset( $input['network'] ) && false === $input['network'] );
		$settings = Leiho_Plugin::settings();

		$out = array(
			'versions'   => array(
				'leiho'         => defined( 'LEIHO_VERSION' ) ? LEIHO_VERSION : '',
				'wordpress'     => (string) get_bloginfo( 'version' ),
				'php'           => PHP_VERSION,
				'abilities_api' => function_exists( 'wp_register_ability' ),
				'mcp_adapter'   => self::mcp_adapter_active(),
			),
			'settings'   => self::public_settings( $settings ),
			'secrets'    => self::secrets_status(),
			'bridge'     => self::bridge(),
			'headers'    => null,
			'github'     => null,
			'deploys'    => self::deploys(),
			'publishing' => array(
				'driver'        => $settings['deploy_driver'],
				'auto_enabled'  => Leiho_Deploy_Manager::auto_enabled(),
				'auto_delay'    => Leiho_Deploy_Manager::auto_delay(),
				'pending_since' => self::date( Leiho_Deploy_Manager::pending_since() ),
			),
			'content'    => self::content_api( $settings ),
			'ai'         => Leiho_AI::status(),
			'migration'  => array(
				'migrated_from_okno' => (bool) get_option( Leiho_Legacy::FLAG ),
				'okno_active'        => Leiho_Legacy::okno_active(),
			),
		);

		if ( $network ) {
			$out['headers'] = self::headers();
			$out['github']  = self::github( $settings );
		} else {
			$out['headers'] = array(
				'skipped' => true,
			);
		}
		$out['problems'] = self::problems( $out, $settings );
		return $out;
	}

	/**
	 * @param mixed $input { settings, dry_run }.
	 * @return array|WP_Error
	 */
	public static function update_settings( $input = null ) {
		$changes = is_array( $input ) && isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$dry_run = is_array( $input ) && ! empty( $input['dry_run'] );
		if ( ! $changes ) {
			return new WP_Error( 'leiho_bad_request', __( 'Nothing to save.', 'leiho' ), array( 'status' => 400 ) );
		}

		$locked = array_intersect( array_keys( $changes ), self::READ_ONLY_KEYS );
		if ( $locked ) {
			return new WP_Error(
				'leiho_setting_locked',
				/* translators: %s: setting keys. */
				sprintf( __( 'These addresses can only be changed by an administrator in Leiho → Settings: %s.', 'leiho' ), implode( ', ', $locked ) ),
				array( 'status' => 403 )
			);
		}

		$unknown = array_diff( array_keys( $changes ), self::SETTINGS_KEYS );
		if ( $unknown ) {
			return new WP_Error(
				'leiho_bad_setting',
				/* translators: 1: unknown keys, 2: allowed keys. */
				sprintf( __( 'Unknown settings: %1$s. Allowed: %2$s.', 'leiho' ), implode( ', ', $unknown ), implode( ', ', self::SETTINGS_KEYS ) ),
				array( 'status' => 400 )
			);
		}
		if ( isset( $changes['deploy_driver'] ) && '' !== $changes['deploy_driver'] && ! array_key_exists( (string) $changes['deploy_driver'], Leiho_Dashboard::drivers() ) ) {
			return new WP_Error(
				'leiho_bad_setting',
				/* translators: 1: requested publishing mode, 2: allowed modes. */
				sprintf( __( 'Unknown publishing mode “%1$s”. Use one of: %2$s (or an empty value for none).', 'leiho' ), (string) $changes['deploy_driver'], implode( ', ', array_keys( Leiho_Dashboard::drivers() ) ) ),
				array( 'status' => 400 )
			);
		}
		if ( isset( $changes['content_access'] ) && ! in_array( $changes['content_access'], array( 'public', 'token' ), true ) ) {
			return new WP_Error( 'leiho_bad_setting', __( 'content_access must be public or token.', 'leiho' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'post_types', 'url_patterns' ) as $key ) {
			if ( isset( $changes[ $key ] ) && ! is_array( $changes[ $key ] ) ) {
				/* translators: %s: setting key. */
				return new WP_Error( 'leiho_bad_setting', sprintf( __( '%s must be a list.', 'leiho' ), $key ), array( 'status' => 400 ) );
			}
		}

		// Même validation que le formulaire : la saisie complète part des
		// réglages actuels, seules les clés données changent.
		$before = Leiho_Plugin::settings();
		$input  = array_merge( $before, $changes );
		$after  = Leiho_Admin::sanitize_security( $input, Leiho_Admin::sanitize_settings( $input, $before ) );

		$warnings = array();
		if ( isset( $changes['post_types'] ) ) {
			$dropped = array_diff( array_map( 'strval', $changes['post_types'] ), $after['post_types'] );
			if ( $dropped ) {
				/* translators: %s: content type names. */
				$warnings[] = sprintf( __( 'Ignored content types (not public, or not registered): %s.', 'leiho' ), implode( ', ', $dropped ) );
			}
		}

		$diff = array();
		foreach ( array_keys( $changes ) as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				$diff[ $key ] = array(
					'before' => self::public_value( $key, $before[ $key ] ),
					'after'  => self::public_value( $key, $after[ $key ] ),
				);
			}
		}

		$out = array(
			'dry_run'  => $dry_run,
			'saved'    => false,
			'changes'  => (object) $diff,
			'warnings' => $warnings,
		);
		if ( $dry_run || ! $diff ) {
			return $out;
		}

		// Seules les clés données sont écrites dans l'option enregistrée.
		$saved = get_option( Leiho_Plugin::OPTION_SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array_keys( $diff ) as $key ) {
			$saved[ $key ] = $after[ $key ];
		}
		update_option( Leiho_Plugin::OPTION_SETTINGS, $saved );
		$settings = Leiho_Plugin::settings();
		Leiho_Admin::ensure_content_token( $settings );

		Leiho_Activity::record_ai( 0, 'settings', implode( ', ', array_keys( $diff ) ), 'settings' );
		$out['saved'] = true;

		// Contrôles liés aux réglages changés.
		$checks = array();
		if ( array_intersect( array_keys( $diff ), array( 'front_url', 'preview_url' ) ) ) {
			$checks['headers'] = self::headers();
		}
		if ( array_intersect( array_keys( $diff ), array( 'deploy_driver', 'gh_repo', 'gh_branch', 'gh_workflow' ) ) ) {
			$github = self::github( $settings );
			if ( null !== $github ) {
				$checks['github'] = $github;
			}
		}
		$out['checks'] = (object) $checks;
		return $out;
	}

	/**
	 * @param mixed $input { name, value, clear, regenerate }.
	 * @return array|WP_Error
	 */
	public static function set_secret( $input = null ) {
		$name  = is_array( $input ) && isset( $input['name'] ) ? (string) $input['name'] : '';
		$value = is_array( $input ) && isset( $input['value'] ) ? trim( (string) $input['value'] ) : '';
		$clear = is_array( $input ) && ! empty( $input['clear'] );
		$regen = is_array( $input ) && ! empty( $input['regenerate'] );
		if ( ! in_array( $name, self::SECRETS, true ) ) {
			return new WP_Error( 'leiho_bad_request', __( 'Unknown secret.', 'leiho' ), array( 'status' => 400 ) );
		}
		$key = 'content_token' === $name ? Leiho_Content::SECRET_TOKEN : $name;

		if ( $clear ) {
			Leiho_Secrets::set( $key, '' );
		} elseif ( 'content_token' === $name ) {
			if ( ! $regen ) {
				return new WP_Error( 'leiho_bad_request', __( 'The content API token is generated by Leiho: pass regenerate: true to renew it.', 'leiho' ), array( 'status' => 400 ) );
			}
			Leiho_Admin::ensure_content_token( Leiho_Plugin::settings(), true );
		} elseif ( '' === $value ) {
			return new WP_Error( 'leiho_bad_request', __( 'Empty value: nothing was changed. To delete this secret, pass clear: true.', 'leiho' ), array( 'status' => 400 ) );
		} else {
			Leiho_Secrets::set( $key, $value );
		}

		Leiho_Activity::record_ai( 0, 'secret:' . $name, $name, 'secret' );

		$out = array(
			'name'   => $name,
			'status' => self::secret_state( $key ),
		);
		if ( 'gh_pat' === $name && ! $clear ) {
			$github = self::github( Leiho_Plugin::settings() );
			if ( null !== $github ) {
				$out['github'] = $github;
			}
		}
		if ( 'content_token' === $name && $regen ) {
			$out['note'] = __( 'The previous token stopped working. Copy the new one from Leiho → Settings → Content API into the front end’s LEIHO_TOKEN.', 'leiho' );
		}
		return $out;
	}

	/**
	 * @return array
	 */
	public static function test_connection() {
		$settings = Leiho_Plugin::settings();
		$out      = array(
			'headers' => self::headers(),
			'github'  => self::github( $settings ),
			'bridge'  => array(
				'tested_here' => false,
				'last'        => self::bridge(),
				'message'     => __( 'The bridge handshake needs a browser: open Leiho → Get started → Test the connection.', 'leiho' ),
			),
		);
		$out['problems'] = self::problems(
			array(
				'headers' => $out['headers'],
				'github'  => $out['github'],
			),
			$settings,
			true
		);
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Morceaux du diagnostic
	 * ------------------------------------------------------------------- */

	/**
	 * L'adaptateur MCP officiel (WordPress/mcp-adapter) est-il actif ?
	 *
	 * @return bool
	 */
	public static function mcp_adapter_active() {
		return class_exists( 'WP\\MCP\\Core\\McpAdapter' );
	}

	/**
	 * Réglages sans secret : les URL à jeton réduites à leur hôte.
	 *
	 * @param array $settings Réglages.
	 * @return array
	 */
	public static function public_settings( array $settings ) {
		$out = array();
		foreach ( array_merge( self::READ_ONLY_KEYS, self::SETTINGS_KEYS ) as $key ) {
			$out[ $key ] = self::public_value( $key, isset( $settings[ $key ] ) ? $settings[ $key ] : null );
		}
		return $out;
	}

	/**
	 * Valeur d'un réglage telle qu'un agent peut la lire.
	 *
	 * @param string $key   Clé.
	 * @param mixed  $value Valeur.
	 * @return mixed
	 */
	private static function public_value( $key, $value ) {
		if ( in_array( $key, self::HOST_ONLY, true ) ) {
			$host = '' !== (string) $value ? (string) wp_parse_url( (string) $value, PHP_URL_HOST ) : '';
			return array(
				'set'  => '' !== (string) $value,
				'host' => $host,
			);
		}
		return $value;
	}

	/**
	 * @return array<string,string> Nom => set | empty | unreadable.
	 */
	public static function secrets_status() {
		$out = array();
		foreach ( self::SECRETS as $name ) {
			$out[ $name ] = self::secret_state( 'content_token' === $name ? Leiho_Content::SECRET_TOKEN : $name );
		}
		return $out;
	}

	/**
	 * @param string $key Identifiant du secret.
	 * @return string set | empty | unreadable
	 */
	private static function secret_state( $key ) {
		$status = Leiho_Secrets::status( $key );
		return 'ok' === $status ? 'set' : $status;
	}

	/**
	 * Dernier test du bridge (fait par un navigateur).
	 *
	 * @return array|null
	 */
	private static function bridge() {
		$connection = Leiho_Dashboard::connection();
		if ( ! $connection ) {
			return null;
		}
		$connection['at'] = self::date( (int) $connection['at'] );
		return $connection;
	}

	/**
	 * Contrôle des en-têtes du site, sans exception.
	 *
	 * @return array
	 */
	private static function headers() {
		$verdict = Leiho_Rest::headers_check();
		if ( is_wp_error( $verdict ) ) {
			return array(
				'reachable' => false,
				'level'     => 'error',
				'message'   => $verdict->get_error_message(),
			);
		}
		return $verdict;
	}

	/**
	 * Contrôle GitHub quand le mode de publication passe par GitHub.
	 *
	 * @param array $settings Réglages.
	 * @return array|null Lignes { level, message }, null hors GitHub.
	 */
	private static function github( array $settings ) {
		if ( 'github' === $settings['deploy_driver'] ) {
			return Leiho_Github_Preflight::check( $settings['gh_repo'], $settings['gh_branch'], $settings['gh_workflow'] );
		}
		if ( 'github_commit' === $settings['deploy_driver'] ) {
			return Leiho_Github_Preflight::check( $settings['gh_repo'], $settings['gh_branch'] );
		}
		return null;
	}

	/**
	 * Dernières publications, sans les métadonnées du pilote.
	 *
	 * @return array
	 */
	private static function deploys() {
		$out = array();
		foreach ( array_slice( Leiho_Deploy_Manager::history(), 0, self::DEPLOYS ) as $record ) {
			$out[] = array(
				'id'      => isset( $record['id'] ) ? (string) $record['id'] : '',
				'driver'  => isset( $record['driver'] ) ? (string) $record['driver'] : '',
				'by'      => isset( $record['user_name'] ) ? (string) $record['user_name'] : '',
				'started' => self::date( isset( $record['started'] ) ? (int) $record['started'] : 0 ),
				'status'  => isset( $record['status'] ) ? (string) $record['status'] : '',
				'message' => isset( $record['message'] ) ? (string) $record['message'] : '',
			);
		}
		return $out;
	}

	/**
	 * État de l'API de contenu.
	 *
	 * @param array $settings Réglages.
	 * @return array
	 */
	private static function content_api( array $settings ) {
		$published = 0;
		foreach ( Leiho_Content::post_types() as $type ) {
			$counts     = wp_count_posts( $type );
			$published += $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return array(
			'export_url'      => rest_url( Leiho_Rest::NS . '/export' ),
			'types_url'       => rest_url( Leiho_Rest::NS . '/types' ),
			'access'          => $settings['content_access'],
			'published_items' => $published,
			'export_cached'   => null !== Leiho_Cache::get_export(),
			'object_cache'    => Leiho_Cache::backend(),
		);
	}

	/**
	 * @param int $timestamp Horodatage.
	 * @return string ISO 8601, vide si absent.
	 */
	private static function date( $timestamp ) {
		return $timestamp > 0 ? gmdate( 'c', (int) $timestamp ) : '';
	}

	/**
	 * Problèmes détectés, chacun avec sa correction.
	 *
	 * @param array $report   Rapport (morceaux déjà calculés).
	 * @param array $settings Réglages.
	 * @param bool  $checks   Seulement les contrôles réseau (test-connection).
	 * @return array<int,array{level:string,code:string,message:string,fix:string}>
	 */
	public static function problems( array $report, array $settings, $checks = false ) {
		$problems = array();
		$add      = static function ( $level, $code, $message, $fix ) use ( &$problems ) {
			$problems[] = array(
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
				'fix'     => $fix,
			);
		};

		if ( '' === $settings['front_url'] ) {
			$add( 'error', 'front_url_missing', __( 'The front-end URL isn’t set: the editor has no site to show.', 'leiho' ), __( 'Ask an administrator to set it in Leiho → Settings → Site URL.', 'leiho' ) );
		}

		$headers = isset( $report['headers'] ) && is_array( $report['headers'] ) ? $report['headers'] : array();
		if ( ! empty( $headers['level'] ) && 'ok' !== $headers['level'] && ( '' !== $settings['front_url'] || '' !== $settings['preview_url'] ) ) {
			$fix = empty( $headers['reachable'] )
				? __( 'Check that the site URL is right and that the site answers from the WordPress server.', 'leiho' )
				/* translators: %s: wp-admin origin. */
				: sprintf( __( 'Send this header from the site: Content-Security-Policy: frame-ancestors \'self\' %s', 'leiho' ), Leiho_Admin::wp_origin() );
			$add( 'error' === $headers['level'] ? 'error' : 'warning', 'headers', (string) $headers['message'], $fix );
		}

		$github = isset( $report['github'] ) && is_array( $report['github'] ) ? $report['github'] : array();
		foreach ( $github as $line ) {
			if ( in_array( $line['level'], array( 'error', 'warning' ), true ) ) {
				$add( $line['level'], 'github', $line['message'], __( 'Fix the branch or workflow with leiho/update-settings (the repository is set by an administrator in Leiho → Settings), or set a new token with leiho/set-secret (gh_pat). The token needs access to the repository, with Contents and Actions permissions.', 'leiho' ) );
			}
		}

		if ( $checks ) {
			return $problems;
		}

		$bridge = isset( $report['bridge'] ) ? $report['bridge'] : null;
		if ( null === $bridge ) {
			$add( 'info', 'bridge_untested', __( 'The bridge connection was never tested.', 'leiho' ), __( 'Open Leiho → Get started → Test the connection in a browser.', 'leiho' ) );
		} elseif ( empty( $bridge['ok'] ) ) {
			$add( 'error', 'bridge_failed', __( 'The last bridge test failed: the site didn’t answer the editor.', 'leiho' ), __( 'Check the frame-ancestors header and that the bridge script is loaded on the site, then test again from Get started.', 'leiho' ) );
		}

		$driver  = $settings['deploy_driver'];
		$secrets = isset( $report['secrets'] ) ? $report['secrets'] : self::secrets_status();
		if ( '' === $driver ) {
			$add( 'warning', 'driver_missing', __( 'No publishing mode is chosen: changes can’t be published.', 'leiho' ), __( 'Set deploy_driver with leiho/update-settings (live, build_hook, github, github_commit or coolify).', 'leiho' ) );
		} elseif ( 'build_hook' === $driver && '' === $settings['build_hook_url'] ) {
			$add( 'error', 'build_hook_missing', __( 'Build hook publishing is chosen but no build hook URL is set.', 'leiho' ), __( 'Ask an administrator to paste the build hook URL in Leiho → Settings (from the host’s deploy hooks).', 'leiho' ) );
		} elseif ( 'coolify' === $driver && ( '' === $settings['coolify_url'] || 'set' !== $secrets['coolify_token'] ) ) {
			$add( 'error', 'coolify_missing', __( 'Coolify publishing needs both the webhook URL and the API token.', 'leiho' ), __( 'Ask an administrator to set the webhook URL in Leiho → Settings, and set the token with leiho/set-secret (coolify_token).', 'leiho' ) );
		} elseif ( in_array( $driver, array( 'github', 'github_commit' ), true ) && 'set' !== $secrets['gh_pat'] ) {
			$add( 'error', 'gh_pat_missing', __( 'GitHub publishing needs a GitHub token.', 'leiho' ), __( 'Set it with leiho/set-secret (gh_pat).', 'leiho' ) );
		}

		if ( 'token' === $settings['content_access'] && 'set' !== $secrets['content_token'] ) {
			$add( 'error', 'content_token_missing', __( 'The content API is restricted but has no token: the front end can’t read the content.', 'leiho' ), __( 'Renew it with leiho/set-secret (content_token, regenerate: true), then give it to the front end as LEIHO_TOKEN.', 'leiho' ) );
		}
		foreach ( $secrets as $name => $state ) {
			if ( 'unreadable' === $state ) {
				/* translators: %s: secret name. */
				$add( 'error', 'secret_unreadable', sprintf( __( 'The secret %s can’t be read anymore (the WordPress salts changed).', 'leiho' ), $name ), __( 'Enter it again with leiho/set-secret.', 'leiho' ) );
			}
		}

		$deploys = isset( $report['deploys'] ) ? $report['deploys'] : array();
		if ( $deploys && 'error' === $deploys[0]['status'] ) {
			/* translators: %s: error message. */
			$add( 'error', 'deploy_failed', sprintf( __( 'The last deployment failed: %s', 'leiho' ), $deploys[0]['message'] ), __( 'Fix its cause (publishing settings, token, build logs on the host), then publish again from Leiho.', 'leiho' ) );
		}

		$publishing = isset( $report['publishing'] ) ? $report['publishing'] : array();
		if ( ! empty( $publishing['pending_since'] ) && empty( $publishing['auto_enabled'] ) ) {
			$add( 'info', 'changes_pending', __( 'Changes are waiting to be published.', 'leiho' ), __( 'Publish them from Leiho, or turn on auto_deploy.', 'leiho' ) );
		}

		$ai = isset( $report['ai'] ) ? $report['ai'] : array();
		if ( isset( $ai['text'] ) && ! $ai['text'] ) {
			$add( 'info', 'ai_not_configured', __( 'No AI provider is set up: the AI actions of the editor are hidden.', 'leiho' ), __( 'Set up a provider in Settings → Connectors (WordPress 7.0 and later).', 'leiho' ) );
		}

		if ( ! empty( $report['migration']['okno_active'] ) ) {
			$add( 'warning', 'okno_active', __( 'The Okno plugin is still active: Leiho waits before migrating its data.', 'leiho' ), __( 'Deactivate Okno in Plugins; Leiho then carries its settings and history over.', 'leiho' ) );
		}

		return $problems;
	}
}
