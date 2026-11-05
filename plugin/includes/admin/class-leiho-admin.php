<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administration : menu, assets, éditeur visuel (et formulaire),
 * enregistrement des réglages. La coque plein écran est dans Leiho_App, les
 * vues Accueil, Démarrer, Réglages et Publications dans Leiho_Dashboard.
 */
class Leiho_Admin {

	/** @var Leiho_Plugin */
	private $plugin;

	/** @var string Hook de la page de l'application. */
	private $app_hook = '';

	public function __construct( Leiho_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_leiho_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_leiho_save_ai', array( $this, 'handle_save_ai' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_filter( 'plugin_action_links_' . plugin_basename( LEIHO_FILE ), array( $this, 'action_links' ) );
	}

	public function register_menu() {
		// Une seule entrée dans le menu WordPress : Leiho s'ouvre en plein écran
		// et a sa propre navigation.
		$this->app_hook = add_menu_page(
			'Leiho',
			'Leiho',
			'edit_posts',
			Leiho_App::SLUG,
			array( $this, 'render_app' ),
			Leiho_Icons::menu_icon(),
			26
		);
		add_action( 'load-' . $this->app_hook, array( 'Leiho_App', 'on_load' ) );

		// Anciennes pages (favoris, liens des versions précédentes, liens
		// d'autres extensions) : pages cachées (parent options.php, hors de
		// tout menu), redirigées vers leur vue.
		foreach ( array_keys( Leiho_App::LEGACY ) as $slug ) {
			$hook = add_submenu_page( 'options.php', 'Leiho', 'Leiho', 'edit_posts', $slug, '__return_null' );
			add_action( 'load-' . $hook, array( $this, 'redirect_legacy_page' ) );
		}
	}

	/**
	 * Ancienne page (leiho-settings, leiho-editor…) : redirection vers sa vue.
	 *
	 * @return void
	 */
	public function redirect_legacy_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirection de navigation, aucune écriture.
		$query = wp_unslash( $_GET );
		$page  = isset( $query['page'] ) ? sanitize_key( $query['page'] ) : '';
		$url   = Leiho_App::legacy_url( $page, $query );
		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Page de l'application.
	 *
	 * @return void
	 */
	public function render_app() {
		Leiho_App::render( $this );
	}

	/**
	 * Raccourci « Modifier avec Leiho » dans la barre d'admin, sur le site.
	 */
	public function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture de l'ID du contenu affiché.
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$settings = Leiho_Plugin::settings();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $settings['post_types'], true ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'leiho-edit',
				'title' => __( 'Edit with Leiho', 'leiho' ),
				'href'  => self::edit_url( $post_id ),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( Leiho_App::url( 'start' ) ), esc_html__( 'Get started', 'leiho' ) )
		);
		return $links;
	}

	/**
	 * Version d'un asset : date de modification du fichier. Une mise à jour du
	 * plugin change l'URL, donc le navigateur ne sert jamais un CSS périmé.
	 */
	public static function asset_version( $relative ) {
		$path = LEIHO_DIR . $relative;
		return file_exists( $path ) ? LEIHO_VERSION . '.' . filemtime( $path ) : LEIHO_VERSION;
	}

	/**
	 * Où éditer un contenu dans Leiho : l'éditeur visuel s'il a une page sur
	 * le site, sinon sa collection (entrée ouverte à droite).
	 *
	 * @param int $post_id Contenu.
	 * @return string
	 */
	public static function edit_url( $post_id ) {
		$type = (string) get_post_type( $post_id );
		if ( '' !== $type && in_array( $type, self::form_types(), true ) ) {
			return Leiho_App::url(
				'collections',
				array(
					'type' => $type,
					'id'   => (int) $post_id,
				)
			);
		}
		return Leiho_App::url( 'editor', array( 'post' => (int) $post_id ) );
	}

	public function enqueue( $hook ) {
		if ( $hook !== $this->app_hook ) {
			$this->enqueue_autopublish();
			return;
		}

		$view = Leiho_App::current_view();
		wp_enqueue_style( 'leiho-app', LEIHO_URL . 'assets/admin/app.css', array(), self::asset_version( 'assets/admin/app.css' ) );
		// Composants partagés (listes déroulantes, dates, pastilles, thème).
		wp_register_script( 'leiho-ui', LEIHO_URL . 'assets/admin/ui.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/ui.js' ), true );
		wp_set_script_translations( 'leiho-ui', 'leiho', LEIHO_DIR . 'languages' );
		wp_add_inline_script( 'leiho-ui', Leiho_App::ui_config_script(), 'before' );

		if ( 'editor' === $view || 'collections' === $view ) {
			// L'éditeur gère lui-même la publication automatique.
			$this->enqueue_editor( 'collections' === $view ? 'form' : 'visual' );
			$this->enqueue_shell( $view );
			$this->enqueue_ai();
			if ( 'collections' === $view ) {
				// Constructeur de champs des collections Leiho (administrateurs).
				$deps = array( 'leiho-app', 'leiho-ai', 'leiho-editor', 'wp-api-fetch', 'wp-i18n' );
				if ( Leiho_Collections_Rest::can_manage() ) {
					wp_enqueue_script( 'leiho-app-collections', LEIHO_URL . 'assets/admin/app-collections.js', array( 'leiho-app', 'leiho-ai', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app-collections.js' ), true );
					wp_set_script_translations( 'leiho-app-collections', 'leiho', LEIHO_DIR . 'languages' );
					$deps[] = 'leiho-app-collections';
				}
				// Volets types / entrées, entrée éditée à droite.
				wp_enqueue_script( 'leiho-app-content', LEIHO_URL . 'assets/admin/app-content.js', $deps, self::asset_version( 'assets/admin/app-content.js' ), true );
				wp_set_script_translations( 'leiho-app-content', 'leiho', LEIHO_DIR . 'languages' );
			}
			return;
		}

		if ( in_array( $view, array( 'home', 'start', 'settings', 'deploys', 'ai' ), true ) ) {
			$this->enqueue_dashboard();
			$this->enqueue_shell( $view );
			if ( 'ai' === $view ) {
				// Contexte de marque : export / import JSON.
				$this->enqueue_ai();
			}
		} else {
			$this->enqueue_shell( $view );
			$this->enqueue_ai();
			wp_enqueue_script( 'leiho-app-' . $view, LEIHO_URL . 'assets/admin/app-' . $view . '.js', array( 'leiho-app', 'leiho-ai', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app-' . $view . '.js' ), true );
			wp_set_script_translations( 'leiho-app-' . $view, 'leiho', LEIHO_DIR . 'languages' );
		}
		$this->enqueue_autopublish();
	}

	/**
	 * Coque de l'application (navigation, barre du haut, publication) et
	 * utilitaires partagés par les vues JavaScript (window.LeihoApp).
	 *
	 * @param string $view Vue courante.
	 * @return void
	 */
	private function enqueue_shell( $view ) {
		wp_enqueue_script( 'leiho-app', LEIHO_URL . 'assets/admin/app.js', array( 'leiho-ui', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app.js' ), true );
		wp_set_script_translations( 'leiho-app', 'leiho', LEIHO_DIR . 'languages' );
		wp_add_inline_script( 'leiho-app', 'window.LeihoAppConfig = ' . wp_json_encode( Leiho_App::config( $view ) ) . ';', 'before' );
	}

	/**
	 * Outils d'IA (window.LeihoAI) : actions sur les champs, nouvelle entrée,
	 * texte alternatif, contexte de marque. Le script est chargé même sans
	 * fournisseur : il affiche alors le lien vers les connecteurs.
	 *
	 * @return void
	 */
	private function enqueue_ai() {
		wp_enqueue_script( 'leiho-ai', LEIHO_URL . 'assets/admin/ai.js', array( 'leiho-app', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/ai.js' ), true );
		wp_set_script_translations( 'leiho-ai', 'leiho', LEIHO_DIR . 'languages' );
		wp_add_inline_script( 'leiho-ai', 'window.LeihoAIConfig = ' . wp_json_encode( Leiho_AI::client_config() ) . ';', 'before' );
	}

	/**
	 * Publication automatique hors de l'éditeur Leiho (liste des pages, écran
	 * de modification classique ou éditeur de blocs) : publie quand le délai
	 * de calme est écoulé, et tout de suite si l'utilisateur quitte wp-admin
	 * avec une publication en attente.
	 */
	private function enqueue_autopublish() {
		if ( ! Leiho_Deploy_Manager::user_can_deploy() || ! Leiho_Deploy_Manager::auto_enabled() ) {
			return;
		}

		wp_enqueue_script( 'leiho-autopublish', LEIHO_URL . 'assets/admin/autopublish.js', array(), self::asset_version( 'assets/admin/autopublish.js' ), true );
		wp_add_inline_script( 'leiho-autopublish', 'window.LeihoAutoPublish = ' . wp_json_encode( self::autopublish_config() ) . ';', 'before' );
	}

	/**
	 * État de la publication automatique pour le navigateur.
	 *
	 * @return array
	 */
	private static function autopublish_config() {
		$delay = Leiho_Deploy_Manager::auto_delay();
		$since = Leiho_Deploy_Manager::pending_since();
		return array(
			'pending'  => (bool) $since,
			'dueIn'    => $since ? max( 0, $delay - ( time() - $since ) ) : 0,
			'delay'    => $delay,
			'flushUrl' => esc_url_raw( rest_url( Leiho_Rest::NS . '/deploy/flush' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url(),
		);
	}

	private function enqueue_dashboard() {
		$settings = Leiho_Plugin::settings();
		$mapper   = new Leiho_Url_Mapper();

		wp_enqueue_style( 'leiho-admin', LEIHO_URL . 'assets/admin/admin.css', array(), self::asset_version( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'leiho-admin', LEIHO_URL . 'assets/admin/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/admin.js' ), true );
		wp_set_script_translations( 'leiho-admin', 'leiho', LEIHO_DIR . 'languages' );

		$preview = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];

		wp_add_inline_script(
			'leiho-admin',
			'window.LeihoAdmin = ' . wp_json_encode(
				array(
					'previewUrl'  => $preview ? trailingslashit( $preview ) : '',
					'frontOrigin' => $mapper->preview_origin(),
					'strings'     => array(
						'copied'          => __( 'Copied', 'leiho' ),
						'copy'            => __( 'Copy', 'leiho' ),
						'checking'        => __( 'Checking…', 'leiho' ),
						'testing'         => __( 'Connecting to the site…', 'leiho' ),
						/* translators: 1: bridge version, 2: number of fields, 3: number of sections. */
						'bridgeOk'        => __( 'Bridge v%1$s detected: %2$d annotated field(s) and %3$d section(s) on the home page. The editor is ready.', 'leiho' ),
						'bridgeNoFields'  => __( 'The bridge responds, but no field is annotated on the home page. Add data-wp-post and data-wp-field to your content elements.', 'leiho' ),
						'bridgeTimeout'   => __( 'The site didn’t respond within 10 seconds. Either the frame-ancestors header blocks display (step 2), or the bridge isn’t loaded (step 3).', 'leiho' ),
						'headersFailed'   => __( 'Can’t reach the site from the WordPress server.', 'leiho' ),
					),
				)
			) . ';',
			'before'
		);
	}

	private function enqueue_editor( $mode = 'visual' ) {
		wp_enqueue_media();
		if ( function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		wp_enqueue_style( 'leiho-editor', LEIHO_URL . 'assets/admin/editor.css', array(), self::asset_version( 'assets/admin/editor.css' ) );
		wp_enqueue_script( 'leiho-editor', LEIHO_URL . 'assets/admin/editor.js', array( 'leiho-ui', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/editor.js' ), true );
		wp_set_script_translations( 'leiho-editor', 'leiho', LEIHO_DIR . 'languages' );

		$mapper = new Leiho_Url_Mapper();
		$driver = Leiho_Deploy_Manager::driver();
		$active = Leiho_Deploy_Manager::active_deploy();

		$settings   = Leiho_Plugin::settings();
		$post_types = array();
		foreach ( $settings['post_types'] as $type_name ) {
			$object = get_post_type_object( $type_name );
			if ( ! $object ) {
				continue;
			}
			$post_types[] = array(
				'name'       => $type_name,
				'label'      => $object->labels->singular_name,
				'canCreate'  => current_user_can( $object->cap->create_posts ),
				'canPublish' => current_user_can( $object->cap->publish_posts ),
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Paramètres de navigation.
		$config = array(
			'postTypes'            => $post_types,
			'frontOrigin'          => $mapper->preview_origin(),
			'wpOrigin'             => self::wp_origin(),
			// Brouillons locaux propres à l'utilisateur et au site (poste partagé, multisite).
			'draftScope'           => get_current_blog_id() . '-' . get_current_user_id(),
			'initialPost'          => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // Collections : app-content.js ouvre l'entrée (id).
			'initialField'         => isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : '',
			'mode'                 => $mode,
			'homeUrl'              => Leiho_App::url(),
			'collectionsUrl'       => Leiho_App::url( 'collections' ),
			'editorUrl'            => Leiho_App::url( 'editor' ),
			'startUrl'             => Leiho_App::url( 'start' ),
			'settingsUrl'          => Leiho_App::url( 'settings' ),
			'liveMode'             => 'live' === $settings['deploy_driver'],
			'driverConfigured'     => (bool) $driver,
			'driverSupportsStatus' => $driver ? $driver->supports_status() : false,
			'activeDeploy'         => $active ? $active['id'] : '',
			'handshakeTimeout'     => 8000,
			'canDeploy'            => Leiho_Deploy_Manager::user_can_deploy(),
			'autoDeploy'           => Leiho_Deploy_Manager::auto_enabled() ? self::autopublish_config() : null,
			'formTypes'            => self::form_types(),
			'i18n'                 => Leiho_I18n::client_config(),
		);
		// phpcs:enable

		wp_add_inline_script( 'leiho-editor', 'window.LeihoConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Types édités en formulaire : collections sans page sur le site.
	 *
	 * @return string[]
	 */
	public static function form_types() {
		$settings = Leiho_Plugin::settings();
		$out      = array();
		foreach ( Leiho_Collections::post_types() as $type ) {
			if ( empty( $settings['url_patterns'][ $type ] ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Origine de wp-admin, pour frame-ancestors et le bridge.
	 */
	public static function wp_origin() {
		$parts  = wp_parse_url( admin_url() );
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	/* ---------------------------------------------------------------------
	 * Page éditeur
	 * ------------------------------------------------------------------- */

	/**
	 * Éditeur : « visual » (aperçu du site + panneau) ou « collections »
	 * (vue Collections : types et entrées à gauche, tous les champs de
	 * l'entrée ouverte à droite, sans aperçu, et le constructeur de champs).
	 *
	 * @param string $mode visual | collections.
	 * @return void
	 */
	public function render_editor_page( $mode = 'visual' ) {
		$settings   = Leiho_Plugin::settings();
		$configured = '' !== $settings['front_url'];
		$form       = 'collections' === $mode;

		if ( ! $configured && ! $form ) {
			?>
			<div class="leiho-editor-setup">
				<img src="<?php echo esc_url( LEIHO_URL . 'assets/img/leiho-mark.svg' ); ?>" alt="" width="40" height="40">
				<h1><?php esc_html_e( 'Leiho isn’t connected to your site yet', 'leiho' ); ?></h1>
				<p><?php esc_html_e( 'Enter your site’s URL and install the bridge. The editor will then open right on your pages.', 'leiho' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="leiho-setup-btn" href="<?php echo esc_url( Leiho_App::url( 'start' ) ); ?>"><?php esc_html_e( 'Connect the site', 'leiho' ); ?></a>
				<?php else : ?>
					<p><?php esc_html_e( 'Ask the site administrator to finish the setup.', 'leiho' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( ! $form ) {
			?>
			<div id="leiho-editor" class="leiho-editor" data-mode="visual" data-theme="<?php echo esc_attr( Leiho_App::theme() ); ?>">
				<?php Leiho_App::print_theme_sync( 'leiho-editor' ); ?>
				<?php $this->render_toolbar( false ); ?>
				<div class="leiho-main">
					<?php $this->render_sidebar(); ?>
					<?php $this->render_stage(); ?>
					<aside class="leiho-panel" id="leiho-panel" aria-label="<?php esc_attr_e( 'Selection fields', 'leiho' ); ?>"></aside>
				</div>
			</div>
			<?php
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation.
		$leiho_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$leiho_id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( $leiho_id && '' === $leiho_type ) {
			$leiho_type = (string) get_post_type( $leiho_id );
		}
		?>
		<div id="leiho-editor" class="leiho-editor leiho-editor--collections" data-mode="form" data-theme="<?php echo esc_attr( Leiho_App::theme() ); ?>">
			<?php Leiho_App::print_theme_sync( 'leiho-editor' ); ?>
			<div class="leiho-main">
				<?php // Types et entrées (app-content.js). ?>
				<div id="leiho-form-browser" class="leiho-form-browser" data-type="<?php echo esc_attr( $leiho_type ); ?>" data-post="<?php echo esc_attr( $leiho_id ); ?>"></div>
				<section class="leiho-entry" id="leiho-entry" aria-label="<?php esc_attr_e( 'Entry', 'leiho' ); ?>">
					<?php $this->render_toolbar( true ); ?>
					<div class="leiho-entry__body">
						<aside class="leiho-panel" id="leiho-panel" aria-label="<?php esc_attr_e( 'Entry fields', 'leiho' ); ?>"></aside>
						<?php $this->render_sidebar(); ?>
					</div>
					<?php $this->render_stage(); ?>
				</section>
				<?php // Champs d'une collection Leiho (app-collections.js). ?>
				<div id="leiho-builder" class="leiho-builder-host" hidden></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Barre d'outils de l'éditeur. Dans Collections, elle coiffe le volet de
	 * l'entrée : titre, statut, historique, ouverture dans l'éditeur visuel
	 * ou sur le site, enregistrement.
	 *
	 * @param bool $form Vue Collections.
	 * @return void
	 */
	private function render_toolbar( $form ) {
		?>
		<header class="leiho-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Editor actions', 'leiho' ); ?>">
			<div class="leiho-toolbar-start">
				<?php if ( $form ) : ?>
					<button type="button" id="leiho-entry-back" class="leiho-icon-btn leiho-entry-back" title="<?php esc_attr_e( 'Back to the entries', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Back to the entries', 'leiho' ); ?>">
						<?php Leiho_Icons::render( 'back', 16 ); ?>
					</button>
				<?php else : ?>
					<a class="leiho-exit" href="<?php echo esc_url( Leiho_App::url() ); ?>" title="<?php esc_attr_e( 'Back to Leiho home', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Back to Leiho home', 'leiho' ); ?>">
						<?php Leiho_Icons::render( 'back', 16 ); ?>
					</a>
					<img class="leiho-mark" src="<?php echo esc_url( LEIHO_URL . 'assets/img/leiho-mark.svg' ); ?>" alt="Leiho" width="22" height="22">
				<?php endif; ?>
				<div class="leiho-doc">
					<span class="leiho-doc-row">
						<span id="leiho-current-title" class="leiho-current-title"><?php echo esc_html( $form ? '' : __( 'No page open', 'leiho' ) ); ?></span>
						<label class="screen-reader-text" for="leiho-status"><?php esc_html_e( 'Status', 'leiho' ); ?></label>
						<select id="leiho-status" class="leiho-status-select" hidden></select>
						<label class="screen-reader-text" for="leiho-locale"><?php esc_html_e( 'Language', 'leiho' ); ?></label>
						<select id="leiho-locale" class="leiho-status-select leiho-locale-select" hidden></select>
						<button type="button" id="leiho-translate" class="leiho-btn leiho-btn--quiet leiho-btn--small leiho-translate-btn" hidden><?php esc_html_e( 'Translate with AI', 'leiho' ); ?></button>
					</span>
					<span id="leiho-save-state" class="leiho-save-state" aria-live="polite"></span>
				</div>
			</div>

			<?php if ( ! $form ) : ?>
				<div class="leiho-toolbar-center">
					<span class="leiho-viewport-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'leiho' ); ?>">
						<button type="button" class="leiho-vw active" data-width="" title="<?php esc_attr_e( 'Desktop', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Desktop preview', 'leiho' ); ?>"><?php Leiho_Icons::render( 'desktop', 16 ); ?></button>
						<button type="button" class="leiho-vw" data-width="768" title="<?php esc_attr_e( 'Tablet', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Tablet preview', 'leiho' ); ?>"><?php Leiho_Icons::render( 'tablet', 16 ); ?></button>
						<button type="button" class="leiho-vw" data-width="390" title="<?php esc_attr_e( 'Mobile', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Mobile preview', 'leiho' ); ?>"><?php Leiho_Icons::render( 'mobile', 16 ); ?></button>
					</span>
				</div>
			<?php endif; ?>

			<div class="leiho-toolbar-end">
				<?php if ( $form ) : ?>
					<a id="leiho-open-visual" class="leiho-btn leiho-btn--quiet" href="#" title="<?php esc_attr_e( 'Visual editor', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Visual editor', 'leiho' ); ?>" hidden>
						<?php Leiho_Icons::render( 'edit', 14 ); ?>
						<span><?php esc_html_e( 'Visual editor', 'leiho' ); ?></span>
					</a>
					<a id="leiho-open-site" class="leiho-icon-btn" href="#" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View on the site', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'View on the site (new tab)', 'leiho' ); ?>" hidden>
						<?php Leiho_Icons::render( 'external', 15 ); ?>
					</a>
					<button type="button" id="leiho-history-toggle" class="leiho-icon-btn" title="<?php esc_attr_e( 'History', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Show the history of this entry', 'leiho' ); ?>" aria-pressed="false" aria-controls="leiho-history-list" disabled>
						<?php Leiho_Icons::render( 'history', 16 ); ?>
					</button>
				<?php endif; ?>
				<span class="leiho-history" role="group" aria-label="<?php esc_attr_e( 'Undo and redo', 'leiho' ); ?>">
					<button type="button" id="leiho-undo" class="leiho-icon-btn" title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Undo the last change', 'leiho' ); ?>" disabled><?php Leiho_Icons::render( 'undo', 16 ); ?></button>
					<button type="button" id="leiho-redo" class="leiho-icon-btn" title="<?php esc_attr_e( 'Redo (Ctrl+Shift+Z)', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Redo the undone change', 'leiho' ); ?>" disabled><?php Leiho_Icons::render( 'redo', 16 ); ?></button>
				</span>
				<button type="button" id="leiho-theme" class="leiho-icon-btn" title="<?php esc_attr_e( 'Light or dark theme', 'leiho' ); ?>" aria-label="<?php esc_attr_e( 'Switch to dark theme', 'leiho' ); ?>" aria-pressed="false"><?php Leiho_Icons::render( 'moon', 16 ); ?></button>
				<span id="leiho-deploy-banner" class="leiho-deploy-banner" hidden></span>
				<button type="button" id="leiho-save" class="leiho-btn leiho-btn--quiet" disabled><?php esc_html_e( 'Save', 'leiho' ); ?></button>
				<button type="button" id="leiho-publish" class="leiho-btn" disabled><?php esc_html_e( 'Publish', 'leiho' ); ?></button>
			</div>
		</header>
		<?php
	}

	/**
	 * Barre latérale : pages, structure, historique (Collections : l'historique
	 * seul, affiché à la demande à droite des champs).
	 *
	 * @return void
	 */
	private function render_sidebar() {
		?>
		<aside class="leiho-sidebar" aria-label="<?php esc_attr_e( 'Site navigation', 'leiho' ); ?>">
			<div class="leiho-sidebar-tabs">
				<button type="button" id="leiho-tab-pages" class="leiho-sidebar-tab active">
					<span><?php esc_html_e( 'Pages', 'leiho' ); ?></span>
				</button>
				<button type="button" id="leiho-tab-structure" class="leiho-sidebar-tab" disabled>
					<span><?php esc_html_e( 'Structure', 'leiho' ); ?></span>
				</button>
				<button type="button" id="leiho-tab-history" class="leiho-sidebar-tab" disabled>
					<span><?php esc_html_e( 'History', 'leiho' ); ?></span>
				</button>
			</div>
			<div id="leiho-pages-list" class="leiho-sidebar-body"></div>
			<div id="leiho-structure-tree" class="leiho-sidebar-body" hidden></div>
			<div id="leiho-history-list" class="leiho-sidebar-body" hidden></div>
		</aside>
		<?php
	}

	/**
	 * Aperçu du site (iframe) et ses messages d'aide.
	 *
	 * @return void
	 */
	private function render_stage() {
		?>
		<div class="leiho-stage">
			<div class="leiho-frame-wrap" id="leiho-frame-wrap">
				<div class="leiho-frame-placeholder" id="leiho-frame-placeholder">
					<img src="<?php echo esc_url( LEIHO_URL . 'assets/img/leiho-mark.svg' ); ?>" alt="" width="36" height="36">
					<p><?php esc_html_e( 'Pick a page on the left to show it here.', 'leiho' ); ?></p>
				</div>
				<iframe id="leiho-frame" title="<?php esc_attr_e( 'Site preview', 'leiho' ); ?>" hidden></iframe>

				<div id="leiho-frame-help" class="leiho-frame-help" hidden>
					<h2><?php esc_html_e( 'The site isn’t responding', 'leiho' ); ?></h2>
					<p><?php esc_html_e( 'The preview loaded, but the Leiho bridge didn’t check in. Two possible causes:', 'leiho' ); ?></p>
					<ol>
						<li><strong><?php esc_html_e( 'The site refuses to be displayed in wp-admin.', 'leiho' ); ?></strong> <?php esc_html_e( 'Its frame-ancestors header must allow this address.', 'leiho' ); ?></li>
						<li><strong><?php esc_html_e( 'The bridge isn’t loaded on this page.', 'leiho' ); ?></strong> <?php esc_html_e( 'Check the installation.', 'leiho' ); ?></li>
					</ol>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="leiho-btn" href="<?php echo esc_url( Leiho_App::url( 'start' ) ); ?>"><?php esc_html_e( 'Test the connection', 'leiho' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Enregistrement des réglages
	 * ------------------------------------------------------------------- */

	/**
	 * Écran IA : contexte de marque et contexte de chaque type de contenu.
	 * Formulaire à part des réglages : l'enregistrer ne touche à rien d'autre.
	 */
	public function handle_save_ai() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leiho' ) );
		}
		check_admin_referer( 'leiho_save_ai' );

		if ( isset( $_POST['leiho_brand'] ) && is_array( $_POST['leiho_brand'] ) ) {
			Leiho_AI_Brand::save( wp_unslash( $_POST['leiho_brand'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Leiho_AI_Brand::normalize() nettoie chaque valeur.
		}

		// Contextes des types : seulement ceux que Leiho édite.
		$types = isset( $_POST['leiho_ai_types'] ) && is_array( $_POST['leiho_ai_types'] ) ? wp_unslash( $_POST['leiho_ai_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Leiho_AI_Brand::collection_normalize() nettoie chaque valeur.
		$known = Leiho_Content::post_types();
		foreach ( $types as $type => $context ) {
			$type = sanitize_key( (string) $type );
			if ( in_array( $type, $known, true ) && is_array( $context ) ) {
				Leiho_AI_Brand::save_collection( $type, $context );
			}
		}

		wp_safe_redirect( Leiho_App::url( 'ai', array( 'leiho_saved' => 1 ) ) );
		exit;
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leiho' ) );
		}
		check_admin_referer( 'leiho_save_settings' );

		$input = isset( $_POST['leiho_settings'] ) && is_array( $_POST['leiho_settings'] ) ? wp_unslash( $_POST['leiho_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$settings = self::sanitize_settings( $input, Leiho_Plugin::settings() );

		update_option( Leiho_Plugin::OPTION_SETTINGS, $settings );

		// Secrets : champ vide = inchangé, valeur = remplacement.
		$secrets = isset( $_POST['leiho_secret'] ) && is_array( $_POST['leiho_secret'] ) ? wp_unslash( $_POST['leiho_secret'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( 'coolify_token', 'gh_pat' ) as $name ) {
			if ( isset( $secrets[ $name ] ) && '' !== trim( $secrets[ $name ] ) ) {
				Leiho_Secrets::set( $name, trim( $secrets[ $name ] ) );
			}
			if ( ! empty( $_POST[ 'leiho_clear_' . $name ] ) ) {
				Leiho_Secrets::set( $name, '' );
			}
		}

		// Jeton de l'API de contenu : créé au passage en accès restreint,
		// renouvelé sur demande (l'ancien cesse aussitôt de fonctionner).
		self::ensure_content_token( $settings, ! empty( $_POST['leiho_regen_content_token'] ) );

		// Contrôle préalable GitHub (dépôt, branche, workflow, token) : sans lui,
		// un « Not Found » ne se découvre qu'au premier déploiement raté.
		if ( 'github' === $settings['deploy_driver'] ) {
			Leiho_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'], $settings['gh_workflow'] );
		} elseif ( 'github_commit' === $settings['deploy_driver'] ) {
			Leiho_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'] );
		}

		wp_safe_redirect( Leiho_App::url( 'settings', array( 'leiho_saved' => '1' ) ) );
		exit;
	}

	/**
	 * Réglages techniques validés, depuis une saisie au format du formulaire
	 * des réglages. Partagé par le formulaire et la capacité
	 * leiho/update-settings : une clé absente de la saisie prend la valeur
	 * d'une case décochée ou d'un champ vide, comme dans le formulaire.
	 *
	 * @param array $input    Saisie (non échappée).
	 * @param array $settings Réglages actuels (clés hors formulaire gardées).
	 * @return array
	 */
	public static function sanitize_settings( array $input, array $settings ) {
		$settings['front_url']   = esc_url_raw( isset( $input['front_url'] ) ? untrailingslashit( trim( $input['front_url'] ) ) : '' );
		$settings['preview_url'] = esc_url_raw( isset( $input['preview_url'] ) ? untrailingslashit( trim( $input['preview_url'] ) ) : '' );

		$public_types           = get_post_types( array( 'public' => true ), 'names' );
		$requested_types        = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$settings['post_types'] = array_values( array_intersect( $requested_types, $public_types ) );
		if ( empty( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'page' );
		}

		$patterns = array();
		if ( isset( $input['url_patterns'] ) && is_array( $input['url_patterns'] ) ) {
			foreach ( $input['url_patterns'] as $type => $pattern ) {
				$pattern = sanitize_text_field( $pattern );
				if ( '' !== $pattern && in_array( $type, $public_types, true ) ) {
					$patterns[ $type ] = $pattern;
				}
			}
		}
		$settings['url_patterns'] = $patterns;

		$settings['path_meta_key'] = sanitize_key( isset( $input['path_meta_key'] ) ? $input['path_meta_key'] : '' );

		$driver                    = isset( $input['deploy_driver'] ) ? sanitize_key( $input['deploy_driver'] ) : '';
		$settings['deploy_driver'] = array_key_exists( $driver, Leiho_Dashboard::drivers() ) ? $driver : '';

		$settings['build_hook_url']      = esc_url_raw( isset( $input['build_hook_url'] ) ? trim( $input['build_hook_url'] ) : '' );
		$settings['build_hook_eta']      = max( 1, absint( isset( $input['build_hook_eta'] ) ? $input['build_hook_eta'] : 3 ) );
		// Le jeton Coolify part avec chaque appel : jamais en clair (http).
		$coolify                         = esc_url_raw( isset( $input['coolify_url'] ) ? trim( $input['coolify_url'] ) : '', array( 'https' ) );
		$settings['coolify_url']         = $coolify;
		$repo                            = trim( sanitize_text_field( isset( $input['gh_repo'] ) ? $input['gh_repo'] : '' ) );
		$settings['gh_repo']             = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
		$settings['gh_workflow']         = sanitize_text_field( isset( $input['gh_workflow'] ) ? $input['gh_workflow'] : '' );
		$settings['gh_branch']           = sanitize_text_field( isset( $input['gh_branch'] ) ? $input['gh_branch'] : '' );
		// 15 s au moins : un enregistrement ne doit jamais pouvoir lancer des builds en rafale.
		$settings['min_deploy_interval'] = max( 15, absint( isset( $input['min_deploy_interval'] ) ? $input['min_deploy_interval'] : 60 ) );
		$settings['auto_deploy']         = ! empty( $input['auto_deploy'] );
		$settings['auto_deploy_delay']   = max( 15, absint( isset( $input['auto_deploy_delay'] ) ? $input['auto_deploy_delay'] : 60 ) );
		$settings['content_access']      = isset( $input['content_access'] ) && 'token' === $input['content_access'] ? 'token' : 'public';
		$settings['content_cache']       = ! empty( $input['content_cache'] );
		$settings['editor_landing']      = ! empty( $input['editor_landing'] );
		foreach ( Leiho_Headless::FLAGS as $flag ) {
			$settings[ $flag ] = ! empty( $input[ $flag ] );
		}

		return $settings;
	}

	/**
	 * Jeton de l'API de contenu : créé au passage en accès restreint,
	 * renouvelé sur demande (l'ancien cesse aussitôt de fonctionner).
	 *
	 * @param array $settings Réglages enregistrés.
	 * @param bool  $regen    Renouveler le jeton.
	 * @return bool Jeton créé ou renouvelé.
	 */
	public static function ensure_content_token( array $settings, $regen = false ) {
		$token_status = Leiho_Secrets::status( Leiho_Content::SECRET_TOKEN );
		if ( $regen || ( 'token' === $settings['content_access'] && 'ok' !== $token_status ) ) {
			Leiho_Secrets::set( Leiho_Content::SECRET_TOKEN, wp_generate_password( 40, false ) );
			return true;
		}
		return false;
	}
}
