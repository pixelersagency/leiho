<?php
defined( 'ABSPATH' ) || exit;

/**
 * Formulaires du site (contact, devis…) reçus par WordPress.
 *
 * Le front (autre origine, souvent statique) envoie les champs à
 * POST /wp-json/leiho/v1/forms/{id}. Leiho vérifie l'origine, écarte les
 * robots (champ piège, envoi trop rapide, limite par adresse IP), valide les
 * champs obligatoires, garde une copie du message (type de contenu privé
 * leiho_entry) et envoie les e-mails réglés dans l'écran Formulaires :
 * notification à l'équipe, confirmation au visiteur.
 */
class Leiho_Forms {

	const OPTION    = 'leiho_forms';
	const POST_TYPE = 'leiho_entry';

	/** Envois acceptés par adresse IP et par formulaire, sur WINDOW secondes. */
	const RATE_MAX    = 5;
	const RATE_WINDOW = 600;

	/** Une confirmation au plus par adresse e-mail sur cette durée (secondes). */
	const CONFIRM_WINDOW = 3600;

	/** Envoi plus rapide que ça après l'affichage du formulaire : un robot (ms). */
	const MIN_ELAPSED = 2500;

	const MAX_FIELDS = 30;
	const MAX_VALUE  = 10000;

	/** Taille totale d'un message (caractères) et paramètres reçus au plus. */
	const MAX_TOTAL  = 50000;
	const MAX_PARAMS = 100;

	/** Messages acceptés par formulaire et par heure, toutes adresses IP confondues. */
	const FORM_HOUR_MAX = 30;

	/** Confirmations envoyées par formulaire et par heure, toutes adresses confondues. */
	const CONFIRM_HOUR_MAX = 20;

	/** Secret Cloudflare Turnstile (Leiho_Secrets). */
	const SECRET_TURNSTILE = 'turnstile_secret';

	/** Champ ajouté par le widget Turnstile. */
	const TURNSTILE_FIELD = 'cf-turnstile-response';

	/** Longueur maximale d'un champ recopié dans la confirmation au visiteur. */
	const CONFIRM_VALUE_MAX = 80;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Messages reçus : privés, hors de l'API REST de WordPress, hors des menus.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Form messages', 'leiho' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------- */

	/**
	 * Valeurs par défaut d'un formulaire.
	 *
	 * @param string $id Identifiant.
	 * @return array
	 */
	public static function defaults( $id = 'contact' ) {
		return array(
			'id'          => $id,
			'label'       => 'contact' === $id ? __( 'Contact', 'leiho' ) : $id,
			'required'    => array(),
			'email_field' => 'email',
			'name_field'  => 'name',
			'store'       => true,
			'notify'      => array(
				'on'      => true,
				'to'      => '',
				/* translators: {form} and {name} are placeholders, keep them as is. */
				'subject' => __( '[{site}] New message: {form}', 'leiho' ),
				'body'    => "{fields}\n\n-- \n" . __( 'Sent from the {form} form of {site}. Reply to this e-mail to answer the visitor.', 'leiho' ),
			),
			'confirm'     => array(
				'on'      => false,
				/* translators: {site} is a placeholder, keep it as is. */
				'subject' => __( 'We received your message', 'leiho' ),
				'body'    => __( "Hello {name},\n\nThank you for your message. We will get back to you soon.\n\n{site}", 'leiho' ),
			),
		);
	}

	/**
	 * Réglages enregistrés : formulaires et sites autorisés.
	 *
	 * @return array{forms:array<string,array>,origins:string[]}
	 */
	public static function config() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$forms = array();
		foreach ( isset( $saved['forms'] ) && is_array( $saved['forms'] ) ? $saved['forms'] : array() as $id => $form ) {
			$forms[ $id ] = array_replace_recursive( self::defaults( $id ), is_array( $form ) ? $form : array() );
		}
		$protection = isset( $saved['protection'] ) && is_array( $saved['protection'] ) ? $saved['protection'] : array();
		return array(
			'forms'      => $forms,
			'origins'    => isset( $saved['origins'] ) && is_array( $saved['origins'] ) ? $saved['origins'] : array(),
			'protection' => array_merge( self::protection_defaults(), $protection ),
		);
	}

	/**
	 * Protection commune à tous les formulaires.
	 *
	 * @return array{require_origin:bool,turnstile_site:string,max_links:int}
	 */
	public static function protection_defaults() {
		return array(
			// Un navigateur envoie toujours l'en-tête Origin avec un POST : sans
			// lui, l'envoi vient d'un script. Décocher pour un front qui envoie
			// depuis son serveur (action serveur Next.js, route Astro…).
			'require_origin' => true,
			'turnstile_site' => '', // Clé de site Cloudflare Turnstile (publique).
			'max_links'      => 5, // Au-delà : message classé spam (0 = pas de limite).
		);
	}

	/**
	 * Formulaire réglé.
	 *
	 * @param string $id Identifiant.
	 * @return array|null
	 */
	public static function form( $id ) {
		$config = self::config();
		return isset( $config['forms'][ $id ] ) ? $config['forms'][ $id ] : null;
	}

	/**
	 * Identifiant de formulaire ou de champ : lettres, chiffres, - et _.
	 *
	 * @param string $name Nom saisi.
	 * @return string '' si invalide.
	 */
	public static function clean_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		return preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $name ) ? $name : '';
	}

	/**
	 * Saisie de l'écran Formulaires, nettoyée.
	 *
	 * @param array $input Saisie (non échappée).
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$forms = array();
		$rows  = isset( $input['forms'] ) && is_array( $input['forms'] ) ? $input['forms'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}
			$id = self::clean_name( isset( $row['id'] ) ? $row['id'] : '' );
			if ( '' === $id || isset( $forms[ $id ] ) ) {
				continue;
			}
			$required = array();
			foreach ( preg_split( '/[\s,]+/', isset( $row['required'] ) ? (string) $row['required'] : '' ) as $field ) {
				$field = self::clean_name( $field );
				if ( '' !== $field && ! in_array( $field, $required, true ) ) {
					$required[] = $field;
				}
			}
			$notify = isset( $row['notify'] ) && is_array( $row['notify'] ) ? $row['notify'] : array();
			$conf   = isset( $row['confirm'] ) && is_array( $row['confirm'] ) ? $row['confirm'] : array();
			$label  = sanitize_text_field( isset( $row['label'] ) ? $row['label'] : '' );

			$forms[ $id ] = array(
				'id'          => $id,
				'label'       => '' !== $label ? $label : $id,
				'required'    => $required,
				'email_field' => self::clean_name( isset( $row['email_field'] ) ? $row['email_field'] : '' ),
				'name_field'  => self::clean_name( isset( $row['name_field'] ) ? $row['name_field'] : '' ),
				'store'       => ! empty( $row['store'] ),
				'notify'      => array(
					'on'      => ! empty( $notify['on'] ),
					'to'      => implode( ', ', self::emails( isset( $notify['to'] ) ? $notify['to'] : '' ) ),
					'subject' => self::one_line( isset( $notify['subject'] ) ? $notify['subject'] : '' ),
					'body'    => sanitize_textarea_field( isset( $notify['body'] ) ? $notify['body'] : '' ),
				),
				'confirm'     => array(
					'on'      => ! empty( $conf['on'] ),
					'subject' => self::one_line( isset( $conf['subject'] ) ? $conf['subject'] : '' ),
					'body'    => sanitize_textarea_field( isset( $conf['body'] ) ? $conf['body'] : '' ),
				),
			);
		}

		$origins = array();
		foreach ( preg_split( '/[\s,]+/', isset( $input['origins'] ) ? (string) $input['origins'] : '' ) as $origin ) {
			$origin = self::origin_of( $origin );
			if ( '' !== $origin && ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}

		$protection = isset( $input['protection'] ) && is_array( $input['protection'] ) ? $input['protection'] : array();
		$site_key   = trim( (string) ( isset( $protection['turnstile_site'] ) ? $protection['turnstile_site'] : '' ) );

		return array(
			'forms'      => $forms,
			'origins'    => $origins,
			'protection' => array(
				'require_origin' => ! empty( $protection['require_origin'] ),
				'turnstile_site' => preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $site_key ) ? $site_key : '',
				'max_links'      => min( 100, absint( isset( $protection['max_links'] ) ? $protection['max_links'] : 5 ) ),
			),
		);
	}

	/**
	 * Adresses e-mail valides d'une liste (virgules, espaces, lignes).
	 *
	 * @param string $list Liste.
	 * @return string[]
	 */
	public static function emails( $list ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $list ) as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $out, true ) ) {
				$out[] = $email;
			}
		}
		return $out;
	}

	/**
	 * Texte sur une ligne (objet d'e-mail) : pas de retour à la ligne.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function one_line( $text ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', sanitize_text_field( (string) $text ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Origine
	 * ------------------------------------------------------------------- */

	/**
	 * scheme://hôte[:port] d'une adresse, '' si ce n'en est pas une.
	 *
	 * @param string $url Adresse.
	 * @return string
	 */
	public static function origin_of( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
	}

	/**
	 * Sites dont le navigateur peut envoyer un formulaire : le site et son
	 * aperçu (Réglages), WordPress lui-même et les adresses ajoutées dans
	 * l'écran Formulaires.
	 *
	 * @return string[]
	 */
	public static function allowed_origins() {
		$settings = Leiho_Plugin::settings();
		$config   = self::config();
		$list     = array_merge(
			array( $settings['front_url'], $settings['preview_url'], home_url(), site_url() ),
			$config['origins']
		);
		$out      = array();
		foreach ( $list as $url ) {
			$origin = self::origin_of( $url );
			if ( '' !== $origin ) {
				$out[] = $origin;
				// www.exemple.fr et exemple.fr : le même site pour le visiteur.
				$alt   = false !== strpos( $origin, '://www.' ) ? str_replace( '://www.', '://', $origin ) : preg_replace( '#://#', '://www.', $origin, 1 );
				$out[] = $alt;
			}
		}
		/**
		 * Origines (scheme://hôte[:port]) autorisées à envoyer les formulaires.
		 *
		 * @param string[] $origins Origines.
		 */
		return array_values( array_unique( (array) apply_filters( 'leiho_forms_origins', $out ) ) );
	}

	/**
	 * Envoi accepté depuis cette origine ? Sans en-tête Origin (appel de
	 * serveur à serveur, rendu côté serveur du front), l'envoi passe : la
	 * vérification vise les autres sites qui posteraient depuis un navigateur.
	 *
	 * @param string $origin En-tête Origin.
	 * @return bool
	 */
	public static function origin_allowed( $origin ) {
		if ( '' === (string) $origin ) {
			$config = self::config();
			return ! $config['protection']['require_origin'];
		}
		$origin = self::origin_of( $origin );
		if ( '' === $origin ) {
			return false;
		}
		if ( in_array( $origin, self::allowed_origins(), true ) ) {
			return true;
		}
		// Développement local : le front tourne sur localhost:4321, 3000…
		$local = function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		return $local && (bool) preg_match( '#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#', $origin );
	}

	/* ---------------------------------------------------------------------
	 * Envoi
	 * ------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Leiho_Rest::NS,
			'/forms/(?P<form>[a-z0-9][a-z0-9_-]{0,39})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_submit' ),
				// Formulaire public : n'importe quel visiteur du site l'envoie.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Champs envoyés : noms valides, valeurs texte, limites de taille. Les
	 * noms commençant par _ sont réservés (_hp, _elapsed) et écartés.
	 *
	 * @param array $params Paramètres de la requête.
	 * @return array<string,string>
	 */
	public static function fields( array $params ) {
		$out = array();
		foreach ( $params as $name => $value ) {
			if ( count( $out ) >= self::MAX_FIELDS ) {
				break;
			}
			if ( '_' === substr( (string) $name, 0, 1 ) || 'form' === $name || self::TURNSTILE_FIELD === $name ) {
				continue;
			}
			$name = self::clean_name( $name );
			if ( '' === $name ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? '1' : '';
			} elseif ( ! is_scalar( $value ) ) {
				continue;
			}
			$out[ $name ] = self::text( $value );
		}
		return $out;
	}

	/**
	 * Valeur d'un champ en texte brut : UTF-8 valide, sans caractères de
	 * contrôle (retours à la ligne gardés), fins de ligne unifiées, longueur
	 * bornée. Le texte est gardé tel quel (« a < b » reste lisible) : il n'est
	 * jamais interprété comme du HTML, chaque sortie l'échappe (écran,
	 * export) ou l'envoie en texte brut (e-mails).
	 *
	 * @param string $value Valeur reçue.
	 * @return string
	 */
	public static function text( $value ) {
		$value = (string) $value;
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		} elseif ( function_exists( 'mb_convert_encoding' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		// Contrôles C0 / C1 et caractères invisibles de direction (sauf \n et \t).
		$value = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8E\x8F\xAA-\xAE]|\xE2\x81[\xA6-\xA9]/', '', $value );
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_VALUE ) : substr( $value, 0, self::MAX_VALUE );
		return trim( $value );
	}

	/**
	 * Envoi manifestement trop gros : trop de paramètres ou de texte.
	 *
	 * @param array $params Paramètres de la requête.
	 * @return bool
	 */
	public static function too_large( array $params ) {
		if ( count( $params ) > self::MAX_PARAMS ) {
			return true;
		}
		$size = 0;
		array_walk_recursive(
			$params,
			static function ( $value ) use ( &$size ) {
				$size += is_scalar( $value ) ? strlen( (string) $value ) : 0;
			}
		);
		return $size > self::MAX_TOTAL * 4; // Octets : jusqu'à 4 par caractère UTF-8.
	}

	/**
	 * Spam probable dans un message valide : trop de liens, ou un mot de la
	 * liste « Clés de commentaires non autorisées » (Réglages → Discussion).
	 * Ces messages sont gardés à part, sans notification : un vrai visiteur
	 * pris pour un robot ne se perd pas.
	 *
	 * @param array $fields Champs.
	 * @param array $form   Formulaire.
	 * @return string Raison, '' si rien.
	 */
	public static function spam_reason( array $fields, array $form ) {
		$config = self::config();
		$max    = (int) $config['protection']['max_links'];
		$text   = implode( "\n", $fields );
		if ( $max > 0 && preg_match_all( '#(https?://|www\.|\[url[=\]])#i', $text ) > $max ) {
			return 'links';
		}
		if ( function_exists( 'wp_check_comment_disallowed_list' ) ) {
			$name  = '' !== $form['name_field'] && isset( $fields[ $form['name_field'] ] ) ? $fields[ $form['name_field'] ] : '';
			$email = '' !== $form['email_field'] && isset( $fields[ $form['email_field'] ] ) ? $fields[ $form['email_field'] ] : '';
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Comparé à une liste, jamais affiché.
			if ( wp_check_comment_disallowed_list( $name, $email, '', $text, Leiho_Headless::client_ip(), $agent ) ) {
				return 'disallowed';
			}
		}
		/**
		 * Spam probable dans un message (anti-spam d'une autre extension).
		 *
		 * @param string $reason Raison ('' : pas de spam).
		 * @param array  $fields Champs.
		 * @param array  $form   Formulaire.
		 */
		return (string) apply_filters( 'leiho_form_spam', '', $fields, $form );
	}

	/**
	 * Turnstile (Cloudflare) réglé ?
	 *
	 * @return bool
	 */
	public static function turnstile_on() {
		$config = self::config();
		return '' !== $config['protection']['turnstile_site'] && '' !== (string) Leiho_Secrets::get( self::SECRET_TURNSTILE );
	}

	/**
	 * Vérifie le jeton Turnstile auprès de Cloudflare. En cas de panne de
	 * Cloudflare, l'envoi est refusé : la protection ne s'éteint pas toute seule.
	 *
	 * @param string $token Jeton du widget.
	 * @return bool
	 */
	public static function turnstile_ok( $token ) {
		$token = (string) $token;
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}
		$res = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Appel serveur à l'API de vérification Turnstile, aucun fichier chargé.
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => (string) Leiho_Secrets::get( self::SECRET_TURNSTILE ),
					'response' => $token,
					'remoteip' => Leiho_Headless::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}

	/**
	 * Champs en erreur : obligatoire vide, adresse e-mail invalide.
	 *
	 * @param array $form   Formulaire.
	 * @param array $fields Champs.
	 * @return array<string,string> Champ => 'required' | 'email'.
	 */
	public static function validate( array $form, array $fields ) {
		$errors = array();
		foreach ( $form['required'] as $name ) {
			if ( ! isset( $fields[ $name ] ) || '' === $fields[ $name ] ) {
				$errors[ $name ] = 'required';
			}
		}
		$email = $form['email_field'];
		if ( '' !== $email && ! isset( $errors[ $email ] ) && isset( $fields[ $email ] ) && '' !== $fields[ $email ] && ! is_email( $fields[ $email ] ) ) {
			$errors[ $email ] = 'email';
		}
		if ( ! $errors && ! array_filter( $fields, 'strlen' ) ) {
			$errors['_form'] = 'empty';
		}
		return $errors;
	}

	/**
	 * Robot ? Champ piège rempli, ou envoi trop rapide après l'affichage.
	 *
	 * @param array $params Paramètres de la requête.
	 * @return bool
	 */
	public static function is_bot( array $params ) {
		if ( isset( $params['_hp'] ) && '' !== trim( (string) ( is_scalar( $params['_hp'] ) ? $params['_hp'] : 'x' ) ) ) {
			return true;
		}
		return isset( $params['_elapsed'] ) && is_numeric( $params['_elapsed'] ) && (int) $params['_elapsed'] < self::MIN_ELAPSED;
	}

	/**
	 * Compte un envoi pour cette clé et dit si la limite est dépassée.
	 *
	 * @param string $key    Clé (hachée).
	 * @param int    $max    Envois permis.
	 * @param int    $window Fenêtre (secondes).
	 * @return bool Limite dépassée.
	 */
	public static function throttled( $key, $max, $window ) {
		$key   = 'leiho_form_' . substr( hash( 'sha256', wp_salt( 'nonce' ) . $key ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * POST /forms/{form}.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_submit( $request ) {
		$params = $request->get_params();
		unset( $params['form'] );
		return self::submit( (string) $request['form'], is_array( $params ) ? $params : array(), (string) $request->get_header( 'origin' ) );
	}

	/**
	 * Traite un envoi.
	 *
	 * @param string $id     Formulaire.
	 * @param array  $params Champs envoyés (avec _hp, _elapsed).
	 * @param string $origin En-tête Origin.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( $id, array $params, $origin = '' ) {
		$form = self::form( self::clean_name( $id ) );
		if ( ! $form ) {
			return new WP_Error( 'leiho_form_unknown', __( 'This form does not exist.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( ! self::origin_allowed( $origin ) ) {
			return new WP_Error( 'leiho_form_origin', __( 'This site may not send this form.', 'leiho' ), array( 'status' => 403 ) );
		}
		if ( self::too_large( $params ) ) {
			return new WP_Error( 'leiho_form_too_large', __( 'This message is too long.', 'leiho' ), array( 'status' => 413 ) );
		}

		$ok = new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Thank you, your message has been sent.', 'leiho' ),
			),
			200
		);

		// Robot : même réponse qu'un envoi réussi, rien n'est gardé ni envoyé.
		if ( self::is_bot( $params ) ) {
			return $ok;
		}

		/**
		 * Messages acceptés par formulaire et par heure, toutes adresses IP
		 * confondues (un robot qui change d'adresse à chaque envoi).
		 *
		 * @param int   $max  Défaut 30.
		 * @param array $form Formulaire.
		 */
		$hour_max = max( 1, (int) apply_filters( 'leiho_form_hourly_limit', self::FORM_HOUR_MAX, $form ) );
		if ( self::throttled( 'ip|' . $form['id'] . '|' . Leiho_Headless::client_ip(), self::RATE_MAX, self::RATE_WINDOW )
			|| self::throttled( 'form|' . $form['id'] . '|' . gmdate( 'YmdH' ), $hour_max, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'leiho_form_rate_limited', __( 'Too many messages in a short time. Please try again in a few minutes.', 'leiho' ), array( 'status' => 429 ) );
		}

		$fields = self::fields( $params );
		$errors = self::validate( $form, $fields );
		if ( $errors ) {
			return new WP_Error(
				'leiho_form_invalid',
				__( 'Some fields are missing or invalid.', 'leiho' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		// Vérification Turnstile après les champs : un jeton ne sert qu'une
		// fois, il n'est pas gâché par une simple faute de saisie.
		if ( self::turnstile_on() && ! self::turnstile_ok( isset( $params[ self::TURNSTILE_FIELD ] ) && is_scalar( $params[ self::TURNSTILE_FIELD ] ) ? $params[ self::TURNSTILE_FIELD ] : '' ) ) {
			return new WP_Error( 'leiho_form_captcha', __( 'The anti-spam check failed. Please try again.', 'leiho' ), array( 'status' => 400 ) );
		}

		// Spam probable : gardé à part, sans e-mail, même réponse qu'un succès.
		$spam = self::spam_reason( $fields, $form );
		if ( '' !== $spam ) {
			if ( $form['store'] ) {
				self::store( $form, $fields, null, $spam );
			}
			return $ok;
		}

		/**
		 * Dernier mot sur un envoi valide (anti-spam d'une autre extension,
		 * par exemple). Renvoyer un WP_Error le refuse.
		 *
		 * @param true|WP_Error $verdict Accepté.
		 * @param array         $fields  Champs.
		 * @param array         $form    Formulaire.
		 */
		$verdict = apply_filters( 'leiho_form_accept', true, $fields, $form );
		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		$notified = $form['notify']['on'] ? self::notify( $form, $fields ) : null;
		$entry    = $form['store'] ? self::store( $form, $fields, $notified ) : 0;

		// Message ni gardé ni parti : le visiteur doit le savoir.
		if ( false === $notified && ! $entry ) {
			return new WP_Error( 'leiho_form_mail', __( 'Your message could not be sent. Please try again later or write to us directly.', 'leiho' ), array( 'status' => 500 ) );
		}

		if ( $form['confirm']['on'] ) {
			self::confirm( $form, $fields );
		}

		/**
		 * Un message de formulaire vient d'être reçu.
		 *
		 * @param array $fields Champs.
		 * @param array $form   Formulaire.
		 * @param int   $entry  Message gardé (0 : non gardé).
		 */
		do_action( 'leiho_form_received', $fields, $form, $entry );

		return $ok;
	}

	/* ---------------------------------------------------------------------
	 * E-mails
	 * ------------------------------------------------------------------- */

	/**
	 * Libellé d'un champ à partir de son nom : project_type → Project type.
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	public static function label( $name ) {
		return ucfirst( str_replace( array( '_', '-' ), ' ', (string) $name ) );
	}

	/**
	 * Remplace {champ}, {fields}, {form}, {site} et {date} dans un modèle.
	 *
	 * @param string   $template Modèle.
	 * @param array    $fields   Champs.
	 * @param array    $form     Formulaire.
	 * @param int|null $limit    Longueur maximale de chaque valeur recopiée.
	 * @return string
	 */
	public static function render( $template, array $fields, array $form, $limit = null ) {
		$cut = static function ( $value ) use ( $limit ) {
			$value = (string) $value;
			if ( null === $limit ) {
				return $value;
			}
			$value = preg_replace( '/\s+/', ' ', $value );
			$short = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
			return $short === $value ? $value : rtrim( $short ) . '…';
		};
		$lines = array();
		foreach ( $fields as $name => $value ) {
			if ( '' !== $value ) {
				$value   = $cut( $value );
				$lines[] = self::label( $name ) . ' : ' . ( false !== strpos( $value, "\n" ) ? "\n" . $value : $value );
			}
		}
		$vars = array(
			'{fields}' => implode( "\n", $lines ),
			'{form}'   => $form['label'],
			'{site}'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{date}'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
		$out  = strtr( (string) $template, $vars );
		return preg_replace_callback(
			'/\{([a-z0-9][a-z0-9_-]{0,39})\}/',
			static function ( $m ) use ( $fields, $cut ) {
				return isset( $fields[ $m[1] ] ) ? $cut( $fields[ $m[1] ] ) : '';
			},
			$out
		);
	}

	/**
	 * Nom pour un en-tête d'e-mail : une ligne, sans caractères spéciaux.
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	private static function header_name( $name ) {
		return trim( preg_replace( '/[\r\n"<>,;:]+/', ' ', (string) $name ) );
	}

	/**
	 * Notification à l'équipe, avec le visiteur en Reply-To.
	 *
	 * @param array $form   Formulaire.
	 * @param array $fields Champs.
	 * @return bool Envoyée.
	 */
	public static function notify( array $form, array $fields ) {
		$to = self::emails( $form['notify']['to'] );
		if ( ! $to ) {
			$to = self::emails( get_option( 'admin_email' ) );
		}
		$headers = array();
		$email   = '' !== $form['email_field'] && isset( $fields[ $form['email_field'] ] ) ? $fields[ $form['email_field'] ] : '';
		if ( '' !== $email && is_email( $email ) ) {
			$name      = '' !== $form['name_field'] && isset( $fields[ $form['name_field'] ] ) ? self::header_name( $fields[ $form['name_field'] ] ) : '';
			$headers[] = 'Reply-To: ' . ( '' !== $name ? '"' . $name . '" <' . $email . '>' : $email );
		}
		$subject = self::one_line( self::render( $form['notify']['subject'], $fields, $form, self::CONFIRM_VALUE_MAX ) );
		$body    = self::render( '' !== trim( $form['notify']['body'] ) ? $form['notify']['body'] : '{fields}', $fields, $form );
		return self::mail( $to, $subject, $body, $headers );
	}

	/**
	 * Confirmation au visiteur. Une par adresse et par heure au plus, et
	 * chaque valeur recopiée est courte : quelqu'un pourrait saisir l'adresse
	 * d'un inconnu pour lui faire envoyer du texte par le site.
	 *
	 * @param array $form   Formulaire.
	 * @param array $fields Champs.
	 * @return bool Envoyée.
	 */
	public static function confirm( array $form, array $fields ) {
		$email = '' !== $form['email_field'] && isset( $fields[ $form['email_field'] ] ) ? $fields[ $form['email_field'] ] : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		if ( self::throttled( 'confirm|' . strtolower( $email ), 1, self::CONFIRM_WINDOW )
			|| self::throttled( 'confirm-hour|' . $form['id'] . '|' . gmdate( 'YmdH' ), self::CONFIRM_HOUR_MAX, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$headers = array();
		$team    = self::emails( $form['notify']['to'] );
		if ( $team ) {
			$headers[] = 'Reply-To: ' . $team[0];
		}
		$subject = self::one_line( self::render( $form['confirm']['subject'], $fields, $form, self::CONFIRM_VALUE_MAX ) );
		$body    = self::render( $form['confirm']['body'], $fields, $form, self::CONFIRM_VALUE_MAX );
		return self::mail( $email, $subject, $body, $headers );
	}

	/**
	 * wp_mail() en texte brut, quoi qu'aient réglé les autres extensions
	 * (wp_mail_content_type) : le texte du visiteur n'est jamais lu comme du
	 * HTML par la messagerie (liens déguisés, images de pistage).
	 *
	 * @param string|string[] $to      Destinataires.
	 * @param string          $subject Objet.
	 * @param string          $body    Texte.
	 * @param string[]        $headers En-têtes.
	 * @return bool
	 */
	private static function mail( $to, $subject, $body, array $headers ) {
		$plain = static function () {
			return 'text/plain';
		};
		add_filter( 'wp_mail_content_type', $plain, PHP_INT_MAX );
		$headers[] = 'Content-Type: text/plain; charset=UTF-8';
		$ok        = (bool) wp_mail( $to, $subject, $body, $headers );
		remove_filter( 'wp_mail_content_type', $plain, PHP_INT_MAX );
		return $ok;
	}

	/* ---------------------------------------------------------------------
	 * Messages gardés
	 * ------------------------------------------------------------------- */

	/**
	 * Garde une copie du message.
	 *
	 * @param array     $form     Formulaire.
	 * @param array     $fields   Champs.
	 * @param bool|null $notified Notification envoyée (null : désactivée).
	 * @param string    $spam     Raison du classement en spam ('' : aucun).
	 * @return int Message (0 en cas d'échec).
	 */
	public static function store( array $form, array $fields, $notified, $spam = '' ) {
		$email = '' !== $form['email_field'] && isset( $fields[ $form['email_field'] ] ) ? $fields[ $form['email_field'] ] : '';
		$name  = '' !== $form['name_field'] && isset( $fields[ $form['name_field'] ] ) ? $fields[ $form['name_field'] ] : '';
		$id    = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => $form['label'],
				// Champs en métadonnée (tableau) : le contenu d'un article passe
				// par les filtres HTML de WordPress, qui abîmeraient le texte.
				'post_content' => '',
				'meta_input'   => array(
					'_leiho_form'   => $form['id'],
					'_leiho_fields' => wp_slash( $fields ),
					'_leiho_email'  => strtolower( $email ),
					'_leiho_mail'   => null === $notified ? 'off' : ( $notified ? 'sent' : 'failed' ),
					'_leiho_spam'   => (string) $spam,
				),
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Derniers messages reçus.
	 *
	 * @param int $limit Nombre.
	 * @return array<int,array{id:int,form:string,date:string,fields:array,mail:string}>
	 */
	public static function entries( $limit = 100 ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'     => (int) $post->ID,
				'form'   => (string) get_post_meta( $post->ID, '_leiho_form', true ),
				'date'   => (string) $post->post_date,
				'fields' => self::entry_fields( $post->ID ),
				'mail'   => (string) get_post_meta( $post->ID, '_leiho_mail', true ),
				'spam'   => (string) get_post_meta( $post->ID, '_leiho_spam', true ),
			);
		}
		return $out;
	}

	/**
	 * Champs d'un message gardé.
	 *
	 * @param int $id Message.
	 * @return array<string,string>
	 */
	public static function entry_fields( $id ) {
		$fields = get_post_meta( $id, '_leiho_fields', true );
		$out    = array();
		foreach ( is_array( $fields ) ? $fields : array() as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				$out[ $name ] = (string) $value;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Données personnelles (Outils → Exporter / Effacer)
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $exporters Exporteurs.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['leiho-forms'] = array(
			'exporter_friendly_name' => __( 'Leiho form messages', 'leiho' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array $erasers Effaceurs.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['leiho-forms'] = array(
			'eraser_friendly_name' => __( 'Leiho form messages', 'leiho' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Messages envoyés depuis cette adresse.
	 *
	 * @param string $email Adresse.
	 * @return int[]
	 */
	private static function entries_of( $email ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_leiho_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Requête ponctuelle (demande RGPD).
				'meta_value'     => strtolower( (string) $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * @param string $email Adresse.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		$data = array();
		foreach ( self::entries_of( $email ) as $id ) {
			$fields = self::entry_fields( $id );
			$items  = array(
				array(
					'name'  => __( 'Date', 'leiho' ),
					'value' => get_post_field( 'post_date', $id ),
				),
			);
			foreach ( $fields as $name => $value ) {
				$items[] = array(
					'name'  => self::label( $name ),
					'value' => $value,
				);
			}
			$data[] = array(
				'group_id'    => 'leiho-forms',
				'group_label' => __( 'Form messages', 'leiho' ),
				'item_id'     => 'leiho-entry-' . $id,
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @param string $email Adresse.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		$ids = self::entries_of( $email );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < 200,
		);
	}
}
