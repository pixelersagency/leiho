<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rôles : ce que chaque rôle WordPress voit et fait dans Leiho.
 *
 * Leiho ne crée pas d'utilisateurs ni de rôles : il règle, pour chaque rôle
 * WordPress qui édite du contenu, les vues de l'application, les types de
 * contenu, les actions (mettre le site en ligne, outils d'IA) et le mode
 * simplifié (rien de technique à l'écran). Les droits WordPress restent
 * la base (edit_post, publish_posts…) : Leiho ne fait que restreindre.
 *
 * Les administrateurs (manage_options) ont toujours tout, pour qu'aucun
 * réglage ne puisse fermer la porte à qui les modifie. Une extension de
 * rôles (Members, User Role Editor) peut aussi accorder une permission
 * directement : capability « leiho_<permission> » (leiho_publish_site…).
 */
class Leiho_Roles {

	const OPTION = 'leiho_roles';

	/** Vues de l'application réglables par rôle (l'accueil reste ouvert). */
	const VIEWS = array( 'editor', 'collections', 'media', 'deploys', 'forms' );

	/**
	 * Actions réglables par rôle. Formulaires : supprimer les messages
	 * reçus, régler les formulaires et leurs e-mails, voir le journal des
	 * e-mails. Le serveur SMTP, les alertes et la protection anti-spam
	 * restent aux administrateurs (mots de passe, jetons).
	 */
	const ACTIONS = array( 'publish_site', 'use_ai', 'delete_messages', 'manage_forms', 'view_mail_log' );

	/**
	 * Profils prêts à l'emploi : un point de départ, que les cases à cocher
	 * ajustent ensuite (le profil devient alors « personnalisé »).
	 *
	 * @return array<string,array>
	 */
	public static function profiles() {
		return array(
			'manager' => array(
				'label'        => __( 'Content manager', 'leiho' ),
				'help'         => __( 'All content, media and publishing, including putting the site online, plus form messages and the e-mail log. No settings.', 'leiho' ),
				'views'           => self::VIEWS,
				'publish_site'    => true,
				'use_ai'          => true,
				'delete_messages' => true,
				'manage_forms'    => false,
				'view_mail_log'   => true,
				'types'        => '*',
				'simple'       => true,
			),
			'writer'  => array(
				'label'        => __( 'Writer', 'leiho' ),
				'help'         => __( 'Writes and edits content. Doesn’t put the site online.', 'leiho' ),
				'views'           => array( 'editor', 'collections', 'media' ),
				'publish_site'    => false,
				'use_ai'          => true,
				'delete_messages' => false,
				'manage_forms'    => false,
				'view_mail_log'   => false,
				'types'        => '*',
				'simple'       => true,
			),
			'none'    => array(
				'label'        => __( 'No access', 'leiho' ),
				'help'         => __( 'Only the home screen of Leiho.', 'leiho' ),
				'views'           => array(),
				'publish_site'    => false,
				'use_ai'          => false,
				'delete_messages' => false,
				'manage_forms'    => false,
				'view_mail_log'   => false,
				'types'        => array(),
				'simple'       => true,
			),
		);
	}

	/**
	 * Profil par défaut d'un rôle WordPress.
	 *
	 * @param string $role Nom du rôle.
	 * @return string
	 */
	public static function default_profile( $role ) {
		return 'editor' === $role ? 'manager' : 'writer';
	}

	/**
	 * Rôles réglables : ceux qui éditent du contenu sans administrer le site.
	 *
	 * @return array<string,string> nom => libellé traduit.
	 */
	public static function editable_roles() {
		$out = array();
		foreach ( wp_roles()->roles as $name => $role ) {
			$caps = isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array();
			if ( empty( $caps['edit_posts'] ) || ! empty( $caps['manage_options'] ) ) {
				continue;
			}
			$out[ $name ] = translate_user_role( $role['name'] );
		}
		return $out;
	}

	/**
	 * Réglages de chaque rôle réglable, complétés par le profil par défaut.
	 *
	 * @return array<string,array{profile:string,views:array,publish_site:bool,use_ai:bool,types:string|array,simple:bool}>
	 */
	public static function config() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( array_keys( self::editable_roles() ) as $role ) {
			$out[ $role ] = isset( $stored[ $role ] ) && is_array( $stored[ $role ] )
				? self::normalize( $stored[ $role ] )
				: self::from_profile( self::default_profile( $role ) );
		}
		return $out;
	}

	/**
	 * Réglages d'un profil.
	 *
	 * @param string $profile Profil.
	 * @return array
	 */
	public static function from_profile( $profile ) {
		$profiles = self::profiles();
		$base     = isset( $profiles[ $profile ] ) ? $profiles[ $profile ] : $profiles['writer'];
		unset( $base['label'], $base['help'] );
		$base['profile'] = isset( $profiles[ $profile ] ) ? $profile : 'writer';
		return $base;
	}

	/**
	 * Réglages d'un rôle, nettoyés (option ou formulaire).
	 *
	 * @param array $raw Réglages bruts.
	 * @return array
	 */
	public static function normalize( $raw ) {
		// Réglage d'avant les formulaires (1.0.1) resté sur un profil : il
		// suit le profil, qui comprend maintenant les formulaires.
		if ( ! array_key_exists( 'manage_forms', $raw ) && isset( $raw['profile'] ) && array_key_exists( (string) $raw['profile'], self::profiles() ) ) {
			return self::from_profile( (string) $raw['profile'] );
		}
		$views = isset( $raw['views'] ) ? array_values( array_intersect( self::VIEWS, array_map( 'strval', (array) $raw['views'] ) ) ) : array();
		$types = isset( $raw['types'] ) ? $raw['types'] : '*';
		if ( '*' !== $types ) {
			$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $types ) ) ) );
		}
		$out = array(
			'views'        => $views,
			'publish_site' => ! empty( $raw['publish_site'] ),
			'use_ai'       => ! empty( $raw['use_ai'] ),
			'delete_messages' => ! empty( $raw['delete_messages'] ),
			'manage_forms'    => ! empty( $raw['manage_forms'] ),
			'view_mail_log'   => ! empty( $raw['view_mail_log'] ),
			'types'        => $types,
			'simple'       => ! empty( $raw['simple'] ),
		);
		$out['profile'] = self::match_profile( $out );
		return $out;
	}

	/**
	 * Profil dont les réglages sont exactement ceux-ci, sinon « custom ».
	 *
	 * @param array $settings Réglages nettoyés.
	 * @return string
	 */
	private static function match_profile( $settings ) {
		foreach ( array_keys( self::profiles() ) as $profile ) {
			$base = self::from_profile( $profile );
			unset( $base['profile'] );
			$same = true;
			foreach ( $base as $key => $value ) {
				$mine = $settings[ $key ];
				if ( is_array( $value ) && is_array( $mine ) ) {
					sort( $value );
					sort( $mine );
				}
				if ( $value !== $mine ) {
					$same = false;
					break;
				}
			}
			if ( $same ) {
				return $profile;
			}
		}
		return 'custom';
	}

	/**
	 * Formulaire de l'écran Rôles → option.
	 *
	 * @param array $input role => réglages bruts.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$out = array();
		foreach ( array_keys( self::editable_roles() ) as $role ) {
			$raw = isset( $input[ $role ] ) && is_array( $input[ $role ] ) ? $input[ $role ] : array();
			// « Tous les types » coché : '*' ; sinon la liste cochée.
			$raw['types'] = ! empty( $raw['all_types'] ) ? '*' : ( isset( $raw['types'] ) ? (array) $raw['types'] : array() );
			$out[ $role ] = self::normalize( $raw );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Questions posées par le reste de Leiho
	 * ------------------------------------------------------------------- */

	/**
	 * @param WP_User|null $user Utilisateur (courant par défaut).
	 * @return WP_User|null
	 */
	private static function user( $user = null ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$current = wp_get_current_user();
		return $current && $current->exists() ? $current : null;
	}

	/**
	 * Administrateur : aucune restriction de Leiho.
	 *
	 * @param WP_User|null $user Utilisateur.
	 * @return bool
	 */
	public static function unrestricted( $user = null ) {
		$user = self::user( $user );
		return $user && $user->has_cap( 'manage_options' );
	}

	/**
	 * Réglages des rôles réglables de l'utilisateur ('' : aucun).
	 *
	 * @param WP_User $user Utilisateur.
	 * @return array[]
	 */
	private static function settings_for( $user ) {
		$config = self::config();
		$out    = array();
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $config[ $role ] ) ) {
				$out[] = $config[ $role ];
			}
		}
		return $out;
	}

	/**
	 * Permission accordée ? Vue (« view_collections »…) ou action
	 * (« publish_site », « use_ai »). Avec plusieurs rôles, il suffit qu'un
	 * rôle l'accorde.
	 *
	 * @param string       $permission Permission.
	 * @param WP_User|null $user       Utilisateur.
	 * @return bool
	 */
	public static function can( $permission, $user = null ) {
		$user = self::user( $user );
		if ( ! $user ) {
			return false;
		}
		$granted = self::unrestricted( $user ) || $user->has_cap( 'leiho_' . $permission );
		if ( ! $granted ) {
			foreach ( self::settings_for( $user ) as $settings ) {
				if ( 0 === strpos( $permission, 'view_' ) ) {
					$granted = in_array( substr( $permission, 5 ), $settings['views'], true );
				} elseif ( in_array( $permission, self::ACTIONS, true ) ) {
					$granted = ! empty( $settings[ $permission ] );
				}
				if ( $granted ) {
					break;
				}
			}
		}
		/**
		 * Permission de Leiho accordée à un utilisateur.
		 *
		 * @param bool    $granted    Accordée.
		 * @param string  $permission view_editor, view_collections, view_media, view_deploys, publish_site, use_ai.
		 * @param WP_User $user       Utilisateur.
		 */
		return (bool) apply_filters( 'leiho_user_can', $granted, $permission, $user );
	}

	/**
	 * Vue de l'application ouverte à l'utilisateur ? (Les vues d'administration
	 * restent gardées par leur capability WordPress.)
	 *
	 * @param string $view Vue.
	 * @return bool
	 */
	public static function can_view( $view ) {
		return ! in_array( $view, self::VIEWS, true ) || self::can( 'view_' . $view );
	}

	/**
	 * Type de contenu ouvert à l'utilisateur dans Leiho ?
	 *
	 * @param string       $post_type Type.
	 * @param WP_User|null $user      Utilisateur.
	 * @return bool
	 */
	public static function can_type( $post_type, $user = null ) {
		$user = self::user( $user );
		if ( ! $user ) {
			return false;
		}
		$granted = self::unrestricted( $user );
		if ( ! $granted ) {
			foreach ( self::settings_for( $user ) as $settings ) {
				if ( '*' === $settings['types'] || in_array( (string) $post_type, (array) $settings['types'], true ) ) {
					$granted = true;
					break;
				}
			}
		}
		/**
		 * Type de contenu ouvert à un utilisateur dans Leiho.
		 *
		 * @param bool    $granted   Ouvert.
		 * @param string  $post_type Type.
		 * @param WP_User $user      Utilisateur.
		 */
		return (bool) apply_filters( 'leiho_user_can_type', $granted, (string) $post_type, $user );
	}

	/**
	 * Mode simplifié : rien de technique à l'écran (noms de types de champ,
	 * code HTML, détails des builds, wp-admin). Actif si tous les rôles
	 * réglables de l'utilisateur le demandent.
	 *
	 * @param WP_User|null $user Utilisateur.
	 * @return bool
	 */
	public static function simple( $user = null ) {
		$user = self::user( $user );
		if ( ! $user || self::unrestricted( $user ) ) {
			return false;
		}
		$settings = self::settings_for( $user );
		if ( ! $settings ) {
			return false;
		}
		foreach ( $settings as $role_settings ) {
			if ( empty( $role_settings['simple'] ) ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Mode simplifié : rester dans Leiho
	 * ------------------------------------------------------------------- */

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'keep_in_leiho' ) );
	}

	/**
	 * Le tableau de bord de WordPress renvoie vers Leiho en mode simplifié.
	 * Les écrans d'édition de WordPress restent ouverts (contenu de l'éditeur
	 * de blocs, profil), seul l'accueil technique est évité.
	 *
	 * @return void
	 */
	public static function keep_in_leiho() {
		global $pagenow;
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 'index.php' !== $pagenow || ! self::simple() ) {
			return;
		}
		wp_safe_redirect( Leiho_App::url() );
		exit;
	}
}
