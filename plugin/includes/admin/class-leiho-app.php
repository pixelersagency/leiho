<?php
defined( 'ABSPATH' ) || exit;

/**
 * Leiho en application plein écran : une seule page wp-admin
 * (admin.php?page=leiho) qui masque toute l'interface WordPress (menu, barre
 * d'admin, pied de page, notices des autres extensions) et affiche sa propre
 * navigation. Chaque écran est une « vue » : admin.php?page=leiho&view=media.
 *
 * Les vues Accueil, Démarrer, Réglages et Publications sont rendues en PHP
 * (Leiho_Dashboard) ; Éditeur visuel et Collections réutilisent l'éditeur
 * (editor.js, en mode formulaire pour Collections, avec les volets de
 * app-content.js et le constructeur de app-collections.js) ; Médias est une
 * vue JavaScript (assets/admin/app-media.js) posée sur l'API REST.
 *
 * Les anciennes vues Contenus (view=content) et Formulaire (view=form)
 * redirigent vers Collections (voir merged_url()).
 */
class Leiho_App {

	const SLUG = 'leiho';

	/** Anciennes pages (avant la version application) => vue. */
	const LEGACY = array(
		'leiho-editor'   => 'editor',
		'leiho-start'    => 'start',
		'leiho-settings' => 'settings',
		'leiho-deploys'  => 'deploys',
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
				'label' => __( 'Home', 'leiho' ),
				'cap'   => 'edit_posts',
				'icon'  => 'home',
				'nav'   => true,
			),
			'editor'      => array(
				'label' => __( 'Visual editor', 'leiho' ),
				'cap'   => 'edit_posts',
				'icon'  => 'edit',
				'nav'   => true,
			),
			// Une seule vue pour tous les contenus, quel que soit leur moteur
			// (pages, articles, types ACF, collections Leiho) : types à gauche,
			// entrées au milieu, entrée éditée à droite. Le constructeur de
			// champs, lui, reste réservé à leiho_collections_capability.
			'collections' => array(
				'label' => __( 'Collections', 'leiho' ),
				'cap'   => 'edit_posts',
				'icon'  => 'database',
				'nav'   => true,
			),
			'media'       => array(
				'label' => __( 'Media', 'leiho' ),
				'cap'   => 'upload_files',
				'icon'  => 'image',
				'nav'   => true,
			),
			'deploys'     => array(
				'label' => _x( 'Publishing', 'navigation', 'leiho' ),
				'cap'   => 'edit_posts',
				'icon'  => 'rocket',
				'nav'   => true,
			),
			// Fournisseur, contexte de marque et des types, journal des actions
			// d'IA, outils des agents (administrateurs).
			'ai'          => array(
				'label' => __( 'AI', 'leiho' ),
				'cap'   => 'manage_options',
				'icon'  => 'sparkle',
				'nav'   => true,
			),
			'settings'    => array(
				'label' => __( 'Settings', 'leiho' ),
				'cap'   => 'manage_options',
				'icon'  => 'settings',
				'nav'   => true,
			),
			'start'       => array(
				'label' => __( 'Get started', 'leiho' ),
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
	 * URL de l'ancienne page (leiho-settings…) dans l'application, paramètres
	 * conservés (post, field, leiho_saved…). Fonction pure.
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
	 * Connexion : les éditeurs arrivent dans Leiho
	 * ------------------------------------------------------------------- */

	/**
	 * Après connexion, un utilisateur qui édite le contenu sans administrer
	 * le site (éditeur, auteur) arrive dans Leiho au lieu du tableau de bord
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
		$settings = Leiho_Plugin::settings();
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
	 * load-toplevel_page_leiho : avant tout affichage.
	 *
	 * @return void
	 */
	public static function on_load() {
		// Anciens liens de l'éditeur (admin.php?page=leiho&post=12).
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
		return $classes . ' leiho-app-body leiho-app-view-' . self::current_view() . ' leiho-theme-' . self::theme();
	}

	/* ---------------------------------------------------------------------
	 * Dates : langue, premier jour de la semaine, format horaire
	 * ------------------------------------------------------------------- */

	/**
	 * Réglages des champs de date de l'application (LeihoUI.date) : langue
	 * de l'utilisateur en balise BCP 47, premier jour de la semaine et
	 * horloge de 12 ou 24 heures, d'après les réglages de WordPress.
	 *
	 * @param string     $locale        Locale WordPress (fr_FR, en_US…).
	 * @param int|string $start_of_week Option start_of_week (0 = dimanche).
	 * @param string     $time_format   Option time_format (H:i, g:i a…).
	 * @return array { locale, startOfWeek, hour12 } ; hour12 absent si le format ne le dit pas.
	 */
	public static function date_config( $locale, $start_of_week, $time_format ) {
		$tag = str_replace( '_', '-', preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $locale ) );
		// Variantes WordPress (de_DE_formal, nl_NL_formal) : langue et pays seulement.
		$tag   = preg_match( '/^([a-z]{2,3}(?:-[A-Z]{2})?)(?:-|$)/', $tag, $m ) ? $m[1] : '';
		$start = is_numeric( $start_of_week ) ? (int) $start_of_week : 1;
		$out   = array(
			'locale'      => $tag,
			'startOfWeek' => $start >= 0 && $start <= 6 ? $start : 1,
		);
		// Lettres échappées (\h) ignorées ; g / h = 12 heures, G / H = 24 heures.
		$format = preg_replace( '/\\\\./', '', (string) $time_format );
		if ( preg_match( '/[gh]/', $format ) ) {
			$out['hour12'] = true;
		} elseif ( preg_match( '/[GH]/', $format ) ) {
			$out['hour12'] = false;
		}
		return $out;
	}

	/**
	 * Script imprimé avant ui.js : window.LeihoUIConfig.
	 *
	 * @return string
	 */
	public static function ui_config_script() {
		$config = self::date_config(
			function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
			get_option( 'start_of_week', 1 ),
			get_option( 'time_format', 'H:i' )
		);
		return 'window.LeihoUIConfig = ' . wp_json_encode( $config ) . ';';
	}

	/* ---------------------------------------------------------------------
	 * Thème clair / sombre
	 * ------------------------------------------------------------------- */

	const THEME_META   = 'leiho_theme';
	const THEME_COOKIE = 'leiho_theme';

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
		<script id="leiho-theme-boot">
		( function () {
			var t = <?php echo wp_json_encode( $stored ); ?>;
			if ( ! t ) {
				try { t = window.localStorage.getItem( 'leiho-theme' ); } catch ( e ) {}
			}
			t = 'light' === t ? 'light' : 'dark';
			document.documentElement.setAttribute( 'data-leiho-theme', t );
			document.documentElement.classList.add( 'leiho-app-html' );
			window.LeihoTheme = { saved: <?php echo wp_json_encode( $stored ); ?>, theme: t };
		} )();
		</script>
		<style id="leiho-theme-paint">
			html[data-leiho-theme="dark"], html[data-leiho-theme="dark"] body.leiho-app-body { background: <?php echo esc_html( $dark ); ?>; color-scheme: dark; }
			html[data-leiho-theme="light"], html[data-leiho-theme="light"] body.leiho-app-body { background: <?php echo esc_html( $light ); ?>; color-scheme: light; }
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
			'<script>( function () { var r = document.getElementById( %1$s ), t = document.documentElement.getAttribute( \'data-leiho-theme\' ); if ( r && t ) { r.setAttribute( \'data-theme\', t ); } } )();</script>',
			wp_json_encode( (string) $id )
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendu
	 * ------------------------------------------------------------------- */

	/**
	 * Coque de l'application et vue courante.
	 *
	 * @param Leiho_Admin $admin Administration (rendu de l'éditeur).
	 * @return void
	 */
	public static function render( Leiho_Admin $admin ) {
		$view     = self::current_view();
		$views    = self::views();
		$embedded = in_array( $view, array( 'editor', 'collections' ), true );
		$user     = wp_get_current_user();
		?>
		<div id="leiho-app" class="leiho-app leiho-app--collapsed leiho-app--booting<?php echo $embedded ? ' leiho-app--embedded' : ''; ?>" data-view="<?php echo esc_attr( $view ); ?>" data-theme="<?php echo esc_attr( self::theme() ); ?>">
			<?php self::print_theme_sync( 'leiho-app' ); ?>
			<?php if ( ! $embedded ) : ?>
			<script>
			// Menu déplié si l'utilisateur l'a choisi, avant le premier affichage :
			// sinon chaque page s'ouvrait repliée puis se dépliait, et tout glissait.
			( function () { try { if ( 'expanded' === window.localStorage.getItem( 'leiho-nav' ) ) { document.getElementById( 'leiho-app' ).classList.remove( 'leiho-app--collapsed' ); } } catch ( e ) {} } )();
			</script>
			<?php endif; ?>
			<a class="leiho-skip" href="#leiho-view"><?php esc_html_e( 'Skip to content', 'leiho' ); ?></a>

			<nav id="leiho-nav" class="leiho-nav" aria-label="<?php esc_attr_e( 'Leiho navigation', 'leiho' ); ?>">
				<div class="leiho-nav__brand">
					<img src="<?php echo esc_url( LEIHO_URL . 'assets/img/leiho-mark.svg' ); ?>" alt="" width="26" height="26">
					<span class="leiho-nav__name">Leiho</span>
				</div>
				<ul class="leiho-nav__list">
					<?php foreach ( $views as $slug => $item ) : ?>
						<?php
						if ( ! $item['nav'] || ! current_user_can( $item['cap'] ) ) {
							continue;
						}
						$current = $slug === $view || ( 'start' === $view && 'settings' === $slug );
						?>
						<li>
							<a class="leiho-nav__link" href="<?php echo esc_url( self::url( $slug ) ); ?>" <?php echo $current ? 'aria-current="page"' : ''; ?> title="<?php echo esc_attr( $item['label'] ); ?>">
								<?php Leiho_Icons::render( $item['icon'], 18 ); ?>
								<span class="leiho-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="leiho-nav__foot">
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="leiho-nav__link leiho-nav__link--quiet" href="<?php echo esc_url( admin_url() ); ?>" title="<?php esc_attr_e( 'Back to WordPress', 'leiho' ); ?>">
							<?php Leiho_Icons::render( 'wordpress', 18 ); ?>
							<span class="leiho-nav__label"><?php esc_html_e( 'Back to WordPress', 'leiho' ); ?></span>
						</a>
					<?php endif; ?>
					<button type="button" class="leiho-nav__link leiho-nav__link--quiet leiho-nav__collapse" id="leiho-nav-collapse" aria-controls="leiho-nav" aria-expanded="true" title="<?php esc_attr_e( 'Collapse the menu', 'leiho' ); ?>">
						<?php Leiho_Icons::render( 'panel', 18 ); ?>
						<span class="leiho-nav__label"><?php esc_html_e( 'Collapse the menu', 'leiho' ); ?></span>
					</button>
				</div>
			</nav>

			<div class="leiho-app__main">
				<?php if ( ! $embedded ) : ?>
					<?php self::render_topbar( $user ); ?>
				<?php endif; ?>
				<main id="leiho-view" class="leiho-view leiho-view--<?php echo esc_attr( $view ); ?>" tabindex="-1">
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
		$settings = Leiho_Plugin::settings();
		$name     = get_bloginfo( 'name' );
		$parts    = preg_split( '/\s+/', trim( (string) $user->display_name ) );
		$initials = '';
		foreach ( array_slice( array_filter( (array) $parts ), 0, 2 ) as $part ) {
			$initials .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
		}
		?>
		<header class="leiho-topbar">
			<button type="button" class="leiho-icon-btn leiho-topbar__menu" id="leiho-nav-toggle" aria-controls="leiho-nav" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open the menu', 'leiho' ); ?>">
				<?php Leiho_Icons::render( 'menu', 18 ); ?>
			</button>
			<div class="leiho-topbar__site">
				<strong><?php echo esc_html( '' !== $name ? $name : __( 'Your site', 'leiho' ) ); ?></strong>
				<?php if ( '' !== $settings['front_url'] ) : ?>
					<a href="<?php echo esc_url( $settings['front_url'] ); ?>" target="_blank" rel="noopener" class="leiho-topbar__visit">
						<?php esc_html_e( 'View site', 'leiho' ); ?>
						<?php Leiho_Icons::render( 'external', 13 ); ?>
					</a>
				<?php endif; ?>
			</div>
			<div class="leiho-topbar__end">
				<span id="leiho-app-deploy" class="leiho-topbar__status" role="status" aria-live="polite"></span>
				<?php if ( Leiho_Deploy_Manager::user_can_deploy() && Leiho_Deploy_Manager::driver() && 'live' !== $settings['deploy_driver'] ) : ?>
					<button type="button" class="leiho-btn leiho-topbar__publish" id="leiho-app-publish">
						<span class="leiho-pending-dot" id="leiho-app-pending" hidden></span>
						<?php esc_html_e( 'Publish', 'leiho' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="leiho-icon-btn" id="leiho-app-theme" aria-pressed="false" aria-label="<?php esc_attr_e( 'Switch to dark theme', 'leiho' ); ?>" title="<?php esc_attr_e( 'Light or dark theme', 'leiho' ); ?>">
					<?php Leiho_Icons::render( 'moon', 16 ); ?>
				</button>
				<div class="leiho-user">
					<button type="button" class="leiho-user__button" id="leiho-user-button" aria-haspopup="menu" aria-expanded="false" aria-controls="leiho-user-menu">
						<span class="leiho-avatar" aria-hidden="true"><?php echo esc_html( '' !== $initials ? $initials : '?' ); ?></span>
						<span class="leiho-user__name"><?php echo esc_html( $user->display_name ); ?></span>
					</button>
					<div class="leiho-user__menu" id="leiho-user-menu" role="menu" hidden>
						<a role="menuitem" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">
							<?php Leiho_Icons::render( 'user', 16 ); ?>
							<?php esc_html_e( 'Profile', 'leiho' ); ?>
						</a>
						<a role="menuitem" href="<?php echo esc_url( wp_logout_url( wp_login_url() ) ); ?>">
							<?php Leiho_Icons::render( 'logout', 16 ); ?>
							<?php esc_html_e( 'Log out', 'leiho' ); ?>
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
	 * @param Leiho_Admin $admin Administration.
	 * @return void
	 */
	private static function render_view( $view, Leiho_Admin $admin ) {
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
					'<div id="leiho-app-view" class="leiho-app-view" data-view="%1$s"><p class="leiho-app-loading">%2$s</p></div><noscript><p>%3$s</p></noscript>',
					esc_attr( $view ),
					esc_html__( 'Loading…', 'leiho' ),
					esc_html__( 'Leiho needs JavaScript.', 'leiho' )
				);
				return;

			default:
				$views = self::views();
				echo '<div class="leiho-admin leiho-admin--app">';
				printf( '<div class="leiho-page leiho-page--head"><h1 class="leiho-view-title">%s</h1></div>', esc_html( $views[ $view ]['label'] ) );
				Leiho_Dashboard::render_view( $view );
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
		$settings   = Leiho_Plugin::settings();
		$out        = array();
		$acf_groups = class_exists( 'Leiho_ACF_Import' ) && Leiho_ACF_Import::acf_available() && Leiho_Collections_Rest::can_manage();
		foreach ( $settings['post_types'] as $name ) {
			$object = get_post_type_object( $name );
			if ( ! $object || ! current_user_can( $object->cap->edit_posts ) ) {
				continue;
			}
			$collection = class_exists( 'Leiho_Collections' ) ? Leiho_Collections::for_post_type( $name ) : null;
			$import     = class_exists( 'Leiho_ACF_Import' ) ? Leiho_ACF_Import::record( $name ) : null;
			// Pendant son import, et tant qu'un import en échec ou en écart
			// attend d'être examiné (« review »), un type reste servi (et
			// présenté) par ACF.
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
				'hasPages'   => $collection && empty( $collection['external'] ) ? Leiho_Admin::has_pages( $name ) : true,
				// Type déclaré hors de Leiho (thème, extension, ACF) dont Leiho a repris les champs.
				'external'   => $collection && ! empty( $collection['external'] ),
				// Champs ACF importables dans Leiho Collections (administrateurs).
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
		if ( ! $import && ! Leiho_ACF_Import::groups_for( $name ) ) {
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
		$settings = Leiho_Plugin::settings();
		$user     = wp_get_current_user();
		$since    = Leiho_Deploy_Manager::pending_since();
		$active   = Leiho_Deploy_Manager::active_deploy();
		$driver   = Leiho_Deploy_Manager::driver();

		$urls = array();
		foreach ( array_keys( self::views() ) as $slug ) {
			$urls[ $slug ] = self::url( $slug );
		}

		$targets = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || ( class_exists( 'Leiho_Collections' ) && Leiho_Collections::for_post_type( $type->name ) ) ) {
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
			'canDeploy'      => Leiho_Deploy_Manager::user_can_deploy(),
			'canCollections' => Leiho_Collections_Rest::can_manage(),
			// Champs des types qui ne sont pas des collections Leiho : ACF (ou le thème).
			'acf'            => function_exists( 'acf_get_field_groups' ),
			'acfUrl'         => function_exists( 'acf_get_field_groups' ) && current_user_can( 'manage_options' ) ? admin_url( 'edit.php?post_type=acf-field-group' ) : '',
			'canUpload'      => current_user_can( 'upload_files' ),
			'publish'        => array(
				'available' => (bool) $driver && 'live' !== $settings['deploy_driver'],
				'auto'      => Leiho_Deploy_Manager::auto_enabled(),
				'pending'   => (bool) $since,
				'active'    => $active ? $active['id'] : '',
				'tracks'    => $driver ? $driver->supports_status() : false,
			),
		);
	}
}
