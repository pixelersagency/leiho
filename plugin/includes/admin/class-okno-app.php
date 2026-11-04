<?php
defined( 'ABSPATH' ) || exit;

/**
 * Okno en application plein écran : une seule page wp-admin
 * (admin.php?page=okno) qui masque toute l'interface WordPress (menu, barre
 * d'admin, pied de page, notices des autres extensions) et affiche sa propre
 * navigation. Chaque écran est une « vue » : admin.php?page=okno&view=media.
 *
 * Les vues Accueil, Démarrer, Réglages et Publications sont rendues en PHP
 * (Okno_Dashboard) ; Éditeur visuel et Collections réutilisent l'éditeur
 * (editor.js, en mode formulaire pour Collections, avec les volets de
 * app-content.js et le constructeur de app-collections.js) ; Médias est une
 * vue JavaScript (assets/admin/app-media.js) posée sur l'API REST.
 *
 * Les anciennes vues Contenus (view=content) et Formulaire (view=form)
 * redirigent vers Collections (voir merged_url()).
 */
class Okno_App {

	const SLUG = 'okno';

	/** Anciennes pages (avant la version application) => vue. */
	const LEGACY = array(
		'okno-editor'   => 'editor',
		'okno-start'    => 'start',
		'okno-settings' => 'settings',
		'okno-deploys'  => 'deploys',
	);

	/** Anciennes vues fondues dans Collections (liens, favoris, journal). */
	const MERGED = array( 'content', 'form' );

	/**
	 * Crochets valables partout (wp-login.php compris, hors wp-admin).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
	}

	/**
	 * Vues de l'application : libellé, capability, icône, place dans la
	 * navigation (false = vue atteinte par un lien seulement).
	 *
	 * @return array<string,array{label:string,cap:string,icon:string,nav:bool}>
	 */
	public static function views() {
		return array(
			'home'        => array(
				'label' => __( 'Home', 'okno' ),
				'cap'   => 'edit_posts',
				'icon'  => 'home',
				'nav'   => true,
			),
			'editor'      => array(
				'label' => __( 'Visual editor', 'okno' ),
				'cap'   => 'edit_posts',
				'icon'  => 'edit',
				'nav'   => true,
			),
			// Une seule vue pour tous les contenus, quel que soit leur moteur
			// (pages, articles, types ACF, collections Okno) : types à gauche,
			// entrées au milieu, entrée éditée à droite. Le constructeur de
			// champs, lui, reste réservé à okno_collections_capability.
			'collections' => array(
				'label' => __( 'Collections', 'okno' ),
				'cap'   => 'edit_posts',
				'icon'  => 'database',
				'nav'   => true,
			),
			'media'       => array(
				'label' => __( 'Media', 'okno' ),
				'cap'   => 'upload_files',
				'icon'  => 'image',
				'nav'   => true,
			),
			'deploys'     => array(
				'label' => _x( 'Publishing', 'navigation', 'okno' ),
				'cap'   => 'edit_posts',
				'icon'  => 'rocket',
				'nav'   => true,
			),
			'settings'    => array(
				'label' => __( 'Settings', 'okno' ),
				'cap'   => 'manage_options',
				'icon'  => 'settings',
				'nav'   => true,
			),
			'start'       => array(
				'label' => __( 'Get started', 'okno' ),
				'cap'   => 'manage_options',
				'icon'  => 'plug',
				'nav'   => false,
			),
		);
	}

	/**
	 * Vue demandée, ramenée à l'accueil si elle n'existe pas ou si
	 * l'utilisateur n'y a pas accès.
	 *
	 * @param string|null $requested Vue demandée (null : lue dans l'URL).
	 * @return string
	 */
	public static function current_view( $requested = null ) {
		if ( null === $requested ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
			$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'home';
		}
		$views = self::views();
		if ( ! isset( $views[ $requested ] ) || ! current_user_can( $views[ $requested ]['cap'] ) ) {
			return 'home';
		}
		return $requested;
	}

	/**
	 * URL d'une vue.
	 *
	 * @param string $view Vue.
	 * @param array  $args Paramètres en plus (post, field…).
	 * @return string
	 */
	public static function url( $view = 'home', $args = array() ) {
		$query = array( 'page' => self::SLUG );
		if ( 'home' !== $view ) {
			$query['view'] = $view;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL dans Collections d'une ancienne vue Contenus ou Formulaire, avec ses
	 * filtres (type, statut, recherche, création) ou son entrée (post, champ).
	 * Fonction pure : le type du contenu est passé par l'appelant.
	 *
	 * @param string $view      Ancienne vue (content, form).
	 * @param array  $query     Paramètres de la requête.
	 * @param string $post_type Type du contenu demandé (vue form), '' sinon.
	 * @return string|null null si ce n'est pas une ancienne vue.
	 */
	public static function merged_url( $view, $query, $post_type = '' ) {
		if ( ! in_array( $view, self::MERGED, true ) ) {
			return null;
		}
		$query = (array) $query;
		$args  = array();
		foreach ( array( 'type', 'status', 's', 'new', 'field' ) as $key ) {
			if ( isset( $query[ $key ] ) && is_scalar( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
				// Recherche et chemin de champ (sections.0.title) : texte ; le reste : clés.
				$args[ $key ] = in_array( $key, array( 's', 'field' ), true ) ? sanitize_text_field( (string) $query[ $key ] ) : sanitize_key( (string) $query[ $key ] );
			}
		}
		$post = isset( $query['post'] ) && is_scalar( $query['post'] ) ? (int) $query['post'] : 0;
		if ( $post > 0 ) {
			if ( '' !== $post_type ) {
				$args['type'] = sanitize_key( $post_type );
			}
			$args['id'] = $post;
		}
		// Ordre stable : type, puis id, puis le reste.
		$ordered = array();
		foreach ( array( 'type', 'id', 'status', 's', 'new', 'field' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$ordered[ $key ] = $args[ $key ];
			}
		}
		return self::url( 'collections', $ordered );
	}

	/**
	 * La requête courante affiche-t-elle l'application ?
	 *
	 * @return bool
	 */
	public static function is_app_request() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return is_admin() && 'admin.php' === $pagenow && self::SLUG === $page;
	}

	/**
	 * URL de l'ancienne page (okno-settings…) dans l'application, paramètres
	 * conservés (post, field, okno_saved…). Fonction pure.
	 *
	 * @param string $page  Slug de l'ancienne page.
	 * @param array  $query Paramètres de la requête.
	 * @return string|null null si ce n'est pas une ancienne page.
	 */
	public static function legacy_url( $page, $query ) {
		if ( ! isset( self::LEGACY[ $page ] ) ) {
			return null;
		}
		$args = array();
		foreach ( (array) $query as $key => $value ) {
			if ( 'page' !== $key && is_scalar( $value ) && preg_match( '/^[a-z0-9_]{1,32}$/', (string) $key ) ) {
				$args[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return self::url( self::LEGACY[ $page ], $args );
	}

	/* ---------------------------------------------------------------------
	 * Connexion : les éditeurs arrivent dans Okno
	 * ------------------------------------------------------------------- */

	/**
	 * Après connexion, un utilisateur qui édite le contenu sans administrer
	 * le site (éditeur, auteur) arrive dans Okno au lieu du tableau de bord
	 * WordPress. Une destination explicite (redirect_to vers un autre écran)
	 * est respectée.
	 *
	 * @param string           $redirect_to Destination calculée.
	 * @param string           $requested   Destination demandée (redirect_to).
	 * @param WP_User|WP_Error $user        Utilisateur.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) || ! method_exists( $user, 'has_cap' ) ) {
			return $redirect_to;
		}
		$settings = Okno_Plugin::settings();
		if ( empty( $settings['editor_landing'] ) ) {
			return $redirect_to;
		}
		if ( $user->has_cap( 'manage_options' ) || ! $user->has_cap( 'edit_posts' ) ) {
			return $redirect_to;
		}
		// Destination par défaut de WordPress : vide, wp-admin/ ou son profil.
		$defaults = array( '', admin_url(), admin_url( '/' ), admin_url( 'index.php' ), admin_url( 'profile.php' ) );
		$requested = (string) $requested;
		if ( '' !== $requested && ! in_array( untrailingslashit( $requested ), array_map( 'untrailingslashit', $defaults ), true ) ) {
			return $redirect_to;
		}
		return self::url();
	}

	/* ---------------------------------------------------------------------
	 * Chargement de la page : interface WordPress retirée
	 * ------------------------------------------------------------------- */

	/**
	 * load-toplevel_page_okno : avant tout affichage.
	 *
	 * @return void
	 */
	public static function on_load() {
		// Anciens liens de l'éditeur (admin.php?page=okno&post=12).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation, aucune écriture.
		if ( isset( $_GET['post'] ) && ! isset( $_GET['view'] ) ) {
			wp_safe_redirect( self::url( 'editor', array( 'post' => absint( $_GET['post'] ) ) ) );
			exit;
		}
		// Contenus et Formulaire sont devenus Collections.
		$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( in_array( $requested, self::MERGED, true ) ) {
			$query = wp_unslash( $_GET );
			$post  = isset( $query['post'] ) ? absint( $query['post'] ) : 0;
			$type  = $post ? (string) get_post_type( $post ) : '';
			wp_safe_redirect( self::merged_url( $requested, $query, $type ) );
			exit;
		}
		// phpcs:enable

		// La barre d'admin n'est pas rendue (et non seulement masquée).
		remove_action( 'in_admin_header', 'wp_admin_bar_render', 0 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_theme_head' ), 0 );
		add_filter( 'admin_footer_text', '__return_empty_string', 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );
		// Notices des autres extensions : retirées juste avant leur affichage
		// (après que tout le monde s'est accroché), et masquées en CSS pour
		// celles qui s'affichent par d'autres chemins.
		add_action( 'in_admin_header', array( __CLASS__, 'remove_notices' ), 1000 );
	}

	/**
	 * Retire tous les rappels des crochets de notices.
	 *
	 * @return void
	 */
	public static function remove_notices() {
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * @param string $classes Classes du body.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return $classes . ' okno-app-body okno-app-view-' . self::current_view() . ' okno-theme-' . self::theme();
	}

	/* ---------------------------------------------------------------------
	 * Thème clair / sombre
	 * ------------------------------------------------------------------- */

	const THEME_META   = 'okno_theme';
	const THEME_COOKIE = 'okno_theme';

	/**
	 * Thème choisi par l'utilisateur, '' s'il n'en a pas encore choisi :
	 * méta utilisateur (enregistrée par l'interrupteur), sinon cookie.
	 *
	 * @return string light | dark | ''
	 */
	public static function stored_theme() {
		$theme = (string) get_user_meta( get_current_user_id(), self::THEME_META, true );
		if ( '' === $theme && isset( $_COOKIE[ self::THEME_COOKIE ] ) ) {
			$theme = sanitize_key( wp_unslash( $_COOKIE[ self::THEME_COOKIE ] ) );
		}
		return in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : '';
	}

	/**
	 * Thème à imprimer au premier rendu : sombre par défaut.
	 *
	 * @return string light | dark
	 */
	public static function theme() {
		$theme = self::stored_theme();
		return '' === $theme ? 'dark' : $theme;
	}

	/**
	 * Enregistre le choix de l'utilisateur.
	 *
	 * @param string $theme light | dark.
	 * @return string|false Thème enregistré, false s'il est invalide.
	 */
	public static function save_theme( $theme ) {
		$theme = sanitize_key( (string) $theme );
		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) || ! get_current_user_id() ) {
			return false;
		}
		update_user_meta( get_current_user_id(), self::THEME_META, $theme );
		return $theme;
	}

	/**
	 * Premier rendu dans le bon thème : le thème est posé sur <html> avant
	 * que le navigateur peigne quoi que ce soit (sinon wp-admin peint son
	 * fond clair, puis le JavaScript bascule). Sans choix enregistré côté
	 * serveur, le choix gardé dans le navigateur est repris.
	 *
	 * @return void
	 */
	public static function print_theme_head() {
		$stored = self::stored_theme();
		$light  = '#f7f7f7';
		$dark   = '#111111';
		?>
		<script id="okno-theme-boot">
		( function () {
			var t = <?php echo wp_json_encode( $stored ); ?>;
			if ( ! t ) {
				try { t = window.localStorage.getItem( 'okno-theme' ); } catch ( e ) {}
			}
			t = 'light' === t ? 'light' : 'dark';
			document.documentElement.setAttribute( 'data-okno-theme', t );
			document.documentElement.classList.add( 'okno-app-html' );
			window.OknoTheme = { saved: <?php echo wp_json_encode( $stored ); ?>, theme: t };
		} )();
		</script>
		<style id="okno-theme-paint">
			html[data-okno-theme="dark"], html[data-okno-theme="dark"] body.okno-app-body { background: <?php echo esc_html( $dark ); ?>; color-scheme: dark; }
			html[data-okno-theme="light"], html[data-okno-theme="light"] body.okno-app-body { background: <?php echo esc_html( $light ); ?>; color-scheme: light; }
		</style>
		<?php
	}

	/**
	 * Aligne une racine (coque, éditeur) sur le thème de <html> dès son
	 * ouverture, avant que la suite de la page soit peinte.
	 *
	 * @param string $id Id de l'élément.
	 * @return void
	 */
	public static function print_theme_sync( $id ) {
		printf(
			'<script>( function () { var r = document.getElementById( %1$s ), t = document.documentElement.getAttribute( \'data-okno-theme\' ); if ( r && t ) { r.setAttribute( \'data-theme\', t ); } } )();</script>',
			wp_json_encode( (string) $id )
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendu
	 * ------------------------------------------------------------------- */

	/**
	 * Coque de l'application et vue courante.
	 *
	 * @param Okno_Admin $admin Administration (rendu de l'éditeur).
	 * @return void
	 */
	public static function render( Okno_Admin $admin ) {
		$view     = self::current_view();
		$views    = self::views();
		$embedded = in_array( $view, array( 'editor', 'collections' ), true );
		$user     = wp_get_current_user();
		?>
		<div id="okno-app" class="okno-app okno-app--collapsed<?php echo $embedded ? ' okno-app--embedded' : ''; ?>" data-view="<?php echo esc_attr( $view ); ?>" data-theme="<?php echo esc_attr( self::theme() ); ?>">
			<?php self::print_theme_sync( 'okno-app' ); ?>
			<a class="okno-skip" href="#okno-view"><?php esc_html_e( 'Skip to content', 'okno' ); ?></a>

			<nav id="okno-nav" class="okno-nav" aria-label="<?php esc_attr_e( 'Okno navigation', 'okno' ); ?>">
				<div class="okno-nav__brand">
					<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="26" height="26">
					<span class="okno-nav__name">Okno</span>
				</div>
				<ul class="okno-nav__list">
					<?php foreach ( $views as $slug => $item ) : ?>
						<?php
						if ( ! $item['nav'] || ! current_user_can( $item['cap'] ) ) {
							continue;
						}
						$current = $slug === $view || ( 'start' === $view && 'settings' === $slug );
						?>
						<li>
							<a class="okno-nav__link" href="<?php echo esc_url( self::url( $slug ) ); ?>" <?php echo $current ? 'aria-current="page"' : ''; ?> title="<?php echo esc_attr( $item['label'] ); ?>">
								<?php Okno_Icons::render( $item['icon'], 18 ); ?>
								<span class="okno-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="okno-nav__foot">
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="okno-nav__link okno-nav__link--quiet" href="<?php echo esc_url( admin_url() ); ?>" title="<?php esc_attr_e( 'Back to WordPress', 'okno' ); ?>">
							<?php Okno_Icons::render( 'wordpress', 18 ); ?>
							<span class="okno-nav__label"><?php esc_html_e( 'Back to WordPress', 'okno' ); ?></span>
						</a>
					<?php endif; ?>
					<button type="button" class="okno-nav__link okno-nav__link--quiet okno-nav__collapse" id="okno-nav-collapse" aria-controls="okno-nav" aria-expanded="true" title="<?php esc_attr_e( 'Collapse the menu', 'okno' ); ?>">
						<?php Okno_Icons::render( 'panel', 18 ); ?>
						<span class="okno-nav__label"><?php esc_html_e( 'Collapse the menu', 'okno' ); ?></span>
					</button>
				</div>
			</nav>

			<div class="okno-app__main">
				<?php if ( ! $embedded ) : ?>
					<?php self::render_topbar( $user ); ?>
				<?php endif; ?>
				<main id="okno-view" class="okno-view okno-view--<?php echo esc_attr( $view ); ?>" tabindex="-1">
					<?php self::render_view( $view, $admin ); ?>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * Barre du haut : site, publication, thème, utilisateur.
	 *
	 * @param WP_User $user Utilisateur courant.
	 * @return void
	 */
	private static function render_topbar( $user ) {
		$settings = Okno_Plugin::settings();
		$name     = get_bloginfo( 'name' );
		$parts    = preg_split( '/\s+/', trim( (string) $user->display_name ) );
		$initials = '';
		foreach ( array_slice( array_filter( (array) $parts ), 0, 2 ) as $part ) {
			$initials .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
		}
		?>
		<header class="okno-topbar">
			<button type="button" class="okno-icon-btn okno-topbar__menu" id="okno-nav-toggle" aria-controls="okno-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open the menu', 'okno' ); ?>">
				<?php Okno_Icons::render( 'menu', 18 ); ?>
			</button>
			<div class="okno-topbar__site">
				<strong><?php echo esc_html( '' !== $name ? $name : __( 'Your site', 'okno' ) ); ?></strong>
				<?php if ( '' !== $settings['front_url'] ) : ?>
					<a href="<?php echo esc_url( $settings['front_url'] ); ?>" target="_blank" rel="noopener" class="okno-topbar__visit">
						<?php esc_html_e( 'View site', 'okno' ); ?>
						<?php Okno_Icons::render( 'external', 13 ); ?>
					</a>
				<?php endif; ?>
			</div>
			<div class="okno-topbar__end">
				<span id="okno-app-deploy" class="okno-topbar__status" role="status" aria-live="polite"></span>
				<?php if ( Okno_Deploy_Manager::user_can_deploy() && Okno_Deploy_Manager::driver() && 'live' !== $settings['deploy_driver'] ) : ?>
					<button type="button" class="okno-btn okno-topbar__publish" id="okno-app-publish">
						<span class="okno-pending-dot" id="okno-app-pending" hidden></span>
						<?php esc_html_e( 'Publish', 'okno' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="okno-icon-btn" id="okno-app-theme" aria-pressed="false" aria-label="<?php esc_attr_e( 'Switch to dark theme', 'okno' ); ?>" title="<?php esc_attr_e( 'Light or dark theme', 'okno' ); ?>">
					<?php Okno_Icons::render( 'moon', 16 ); ?>
				</button>
				<div class="okno-user">
					<button type="button" class="okno-user__button" id="okno-user-button" aria-haspopup="menu" aria-expanded="false" aria-controls="okno-user-menu">
						<span class="okno-avatar" aria-hidden="true"><?php echo esc_html( '' !== $initials ? $initials : '?' ); ?></span>
						<span class="okno-user__name"><?php echo esc_html( $user->display_name ); ?></span>
					</button>
					<div class="okno-user__menu" id="okno-user-menu" role="menu" hidden>
						<a role="menuitem" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">
							<?php Okno_Icons::render( 'user', 16 ); ?>
							<?php esc_html_e( 'Profile', 'okno' ); ?>
						</a>
						<a role="menuitem" href="<?php echo esc_url( wp_logout_url( wp_login_url() ) ); ?>">
							<?php Okno_Icons::render( 'logout', 16 ); ?>
							<?php esc_html_e( 'Log out', 'okno' ); ?>
						</a>
					</div>
				</div>
			</div>
		</header>
		<?php
	}

	/**
	 * Contenu d'une vue.
	 *
	 * @param string     $view  Vue.
	 * @param Okno_Admin $admin Administration.
	 * @return void
	 */
	private static function render_view( $view, Okno_Admin $admin ) {
		switch ( $view ) {
			case 'editor':
				$admin->render_editor_page( 'visual' );
				return;

			case 'collections':
				$admin->render_editor_page( 'collections' );
				return;

			case 'media':
				// Vue JavaScript (app-*.js) : un conteneur et un état de chargement.
				printf(
					'<div id="okno-app-view" class="okno-app-view" data-view="%1$s"><p class="okno-app-loading">%2$s</p></div><noscript><p>%3$s</p></noscript>',
					esc_attr( $view ),
					esc_html__( 'Loading…', 'okno' ),
					esc_html__( 'Okno needs JavaScript.', 'okno' )
				);
				return;

			default:
				$views = self::views();
				echo '<div class="okno-admin okno-admin--app">';
				printf( '<div class="okno-page okno-page--head"><h1 class="okno-view-title">%s</h1></div>', esc_html( $views[ $view ]['label'] ) );
				Okno_Dashboard::render_view( $view );
				echo '</div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Données pour le navigateur
	 * ------------------------------------------------------------------- */

	/**
	 * Types de contenu éditables, pour la vue Collections.
	 *
	 * @return array[]
	 */
	public static function content_types() {
		$settings   = Okno_Plugin::settings();
		$out        = array();
		$acf_groups = class_exists( 'Okno_ACF_Import' ) && Okno_ACF_Import::acf_available() && Okno_Collections_Rest::can_manage();
		foreach ( $settings['post_types'] as $name ) {
			$object = get_post_type_object( $name );
			if ( ! $object || ! current_user_can( $object->cap->edit_posts ) ) {
				continue;
			}
			$collection = class_exists( 'Okno_Collections' ) ? Okno_Collections::for_post_type( $name ) : null;
			$import     = class_exists( 'Okno_ACF_Import' ) ? Okno_ACF_Import::record( $name ) : null;
			// Pendant son import, un type reste servi (et présenté) par ACF.
			if ( $import && 'done' !== $import['status'] ) {
				$collection = null;
			}
			$out[] = array(
				'name'       => $name,
				'label'      => $object->labels->name,
				'singular'   => $object->labels->singular_name,
				'canCreate'  => current_user_can( $object->cap->create_posts ),
				'canPublish' => current_user_can( $object->cap->publish_posts ),
				'collection' => (bool) $collection,
				'singleton'  => $collection && 'singleton' === $collection['kind'],
				// Une collection sans chemin (témoignages, réglages…) n'a pas de page
				// sur le site : elle s'édite en formulaire.
				'hasPages'   => $collection && empty( $collection['external'] ) ? ! empty( $settings['url_patterns'][ $name ] ) : true,
				// Type déclaré hors d'Okno (thème, extension, ACF) dont Okno a repris les champs.
				'external'   => $collection && ! empty( $collection['external'] ),
				// Champs ACF importables dans Okno Collections (administrateurs).
				'acfImport'  => ! $collection && $acf_groups ? self::acf_import_state( $name, $import ) : null,
			);
		}
		return $out;
	}

	/**
	 * Le type a-t-il des champs ACF à importer, et où en est l'import ?
	 *
	 * @param string     $name   Type.
	 * @param array|null $import Enregistrement de l'import.
	 * @return array|null { status } ; null sans groupe ACF.
	 */
	private static function acf_import_state( $name, $import ) {
		if ( ! $import && ! Okno_ACF_Import::groups_for( $name ) ) {
			return null;
		}
		return array( 'status' => $import ? $import['status'] : null );
	}

	/**
	 * Configuration commune des vues JavaScript.
	 *
	 * @param string $view Vue courante.
	 * @return array
	 */
	public static function config( $view ) {
		$settings = Okno_Plugin::settings();
		$user     = wp_get_current_user();
		$since    = Okno_Deploy_Manager::pending_since();
		$active   = Okno_Deploy_Manager::active_deploy();
		$driver   = Okno_Deploy_Manager::driver();

		$urls = array();
		foreach ( array_keys( self::views() ) as $slug ) {
			$urls[ $slug ] = self::url( $slug );
		}

		$targets = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || ( class_exists( 'Okno_Collections' ) && Okno_Collections::for_post_type( $type->name ) ) ) {
				continue;
			}
			$targets[] = array(
				'name'  => $type->name,
				'label' => $type->labels->name,
			);
		}

		return array(
			'view'           => $view,
			'urls'           => $urls,
			'adminUrl'       => admin_url(),
			'siteName'       => get_bloginfo( 'name' ),
			'frontUrl'       => $settings['front_url'],
			'types'          => self::content_types(),
			'postTypes'      => $targets,
			'userId'         => (int) $user->ID,
			'canDeploy'      => Okno_Deploy_Manager::user_can_deploy(),
			'canCollections' => Okno_Collections_Rest::can_manage(),
			// Champs des types qui ne sont pas des collections Okno : ACF (ou le thème).
			'acf'            => function_exists( 'acf_get_field_groups' ),
			'acfUrl'         => function_exists( 'acf_get_field_groups' ) && current_user_can( 'manage_options' ) ? admin_url( 'edit.php?post_type=acf-field-group' ) : '',
			'canUpload'      => current_user_can( 'upload_files' ),
			'publish'        => array(
				'available' => (bool) $driver && 'live' !== $settings['deploy_driver'],
				'auto'      => Okno_Deploy_Manager::auto_enabled(),
				'pending'   => (bool) $since,
				'active'    => $active ? $active['id'] : '',
				'tracks'    => $driver ? $driver->supports_status() : false,
			),
		);
	}
}
