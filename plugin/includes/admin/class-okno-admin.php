<?php
defined( 'ABSPATH' ) || exit;

/**
 * Administration : menu, assets, éditeur visuel (et formulaire),
 * enregistrement des réglages. La coque plein écran est dans Okno_App, les
 * vues Accueil, Démarrer, Réglages et Publications dans Okno_Dashboard.
 */
class Okno_Admin {

	/** @var Okno_Plugin */
	private $plugin;

	/** @var string Hook de la page de l'application. */
	private $app_hook = '';

	public function __construct( Okno_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_okno_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_filter( 'plugin_action_links_' . plugin_basename( OKNO_FILE ), array( $this, 'action_links' ) );
	}

	public function register_menu() {
		// Une seule entrée dans le menu WordPress : Okno s'ouvre en plein écran
		// et a sa propre navigation.
		$this->app_hook = add_menu_page(
			'Okno',
			'Okno',
			'edit_posts',
			Okno_App::SLUG,
			array( $this, 'render_app' ),
			Okno_Icons::menu_icon(),
			26
		);
		add_action( 'load-' . $this->app_hook, array( 'Okno_App', 'on_load' ) );

		// Anciennes pages (favoris, liens des versions précédentes, liens
		// d'autres extensions) : pages cachées (parent options.php, hors de
		// tout menu), redirigées vers leur vue.
		foreach ( array_keys( Okno_App::LEGACY ) as $slug ) {
			$hook = add_submenu_page( 'options.php', 'Okno', 'Okno', 'edit_posts', $slug, '__return_null' );
			add_action( 'load-' . $hook, array( $this, 'redirect_legacy_page' ) );
		}
	}

	/**
	 * Ancienne page (okno-settings, okno-editor…) : redirection vers sa vue.
	 *
	 * @return void
	 */
	public function redirect_legacy_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirection de navigation, aucune écriture.
		$query = wp_unslash( $_GET );
		$page  = isset( $query['page'] ) ? sanitize_key( $query['page'] ) : '';
		$url   = Okno_App::legacy_url( $page, $query );
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
		Okno_App::render( $this );
	}

	/**
	 * Raccourci « Modifier avec Okno » dans la barre d'admin, sur le site.
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
		$settings = Okno_Plugin::settings();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $settings['post_types'], true ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'okno-edit',
				'title' => __( 'Edit with Okno', 'okno' ),
				'href'  => self::edit_url( $post_id ),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( Okno_App::url( 'start' ) ), esc_html__( 'Get started', 'okno' ) )
		);
		return $links;
	}

	/**
	 * Version d'un asset : date de modification du fichier. Une mise à jour du
	 * plugin change l'URL, donc le navigateur ne sert jamais un CSS périmé.
	 */
	public static function asset_version( $relative ) {
		$path = OKNO_DIR . $relative;
		return file_exists( $path ) ? OKNO_VERSION . '.' . filemtime( $path ) : OKNO_VERSION;
	}

	/**
	 * Où éditer un contenu dans Okno : l'éditeur visuel s'il a une page sur
	 * le site, sinon sa collection (entrée ouverte à droite).
	 *
	 * @param int $post_id Contenu.
	 * @return string
	 */
	public static function edit_url( $post_id ) {
		$type = (string) get_post_type( $post_id );
		if ( '' !== $type && in_array( $type, self::form_types(), true ) ) {
			return Okno_App::url(
				'collections',
				array(
					'type' => $type,
					'id'   => (int) $post_id,
				)
			);
		}
		return Okno_App::url( 'editor', array( 'post' => (int) $post_id ) );
	}

	public function enqueue( $hook ) {
		if ( $hook !== $this->app_hook ) {
			$this->enqueue_autopublish();
			return;
		}

		$view = Okno_App::current_view();
		wp_enqueue_style( 'okno-app', OKNO_URL . 'assets/admin/app.css', array(), self::asset_version( 'assets/admin/app.css' ) );

		if ( 'editor' === $view || 'collections' === $view ) {
			// L'éditeur gère lui-même la publication automatique.
			$this->enqueue_editor( 'collections' === $view ? 'form' : 'visual' );
			$this->enqueue_shell( $view );
			if ( 'collections' === $view ) {
				// Constructeur de champs des collections Okno (administrateurs).
				$deps = array( 'okno-app', 'okno-editor', 'wp-api-fetch', 'wp-i18n' );
				if ( Okno_Collections_Rest::can_manage() ) {
					wp_enqueue_script( 'okno-app-collections', OKNO_URL . 'assets/admin/app-collections.js', array( 'okno-app', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app-collections.js' ), true );
					wp_set_script_translations( 'okno-app-collections', 'okno', OKNO_DIR . 'languages' );
					$deps[] = 'okno-app-collections';
				}
				// Volets types / entrées, entrée éditée à droite.
				wp_enqueue_script( 'okno-app-content', OKNO_URL . 'assets/admin/app-content.js', $deps, self::asset_version( 'assets/admin/app-content.js' ), true );
				wp_set_script_translations( 'okno-app-content', 'okno', OKNO_DIR . 'languages' );
			}
			return;
		}

		if ( in_array( $view, array( 'home', 'start', 'settings', 'deploys' ), true ) ) {
			$this->enqueue_dashboard();
			$this->enqueue_shell( $view );
		} else {
			wp_enqueue_script( 'okno-app-' . $view, OKNO_URL . 'assets/admin/app-' . $view . '.js', array( 'okno-app', 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app-' . $view . '.js' ), true );
			wp_set_script_translations( 'okno-app-' . $view, 'okno', OKNO_DIR . 'languages' );
			$this->enqueue_shell( $view );
		}
		$this->enqueue_autopublish();
	}

	/**
	 * Coque de l'application (navigation, barre du haut, publication) et
	 * utilitaires partagés par les vues JavaScript (window.OknoApp).
	 *
	 * @param string $view Vue courante.
	 * @return void
	 */
	private function enqueue_shell( $view ) {
		wp_enqueue_script( 'okno-app', OKNO_URL . 'assets/admin/app.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/app.js' ), true );
		wp_set_script_translations( 'okno-app', 'okno', OKNO_DIR . 'languages' );
		wp_add_inline_script( 'okno-app', 'window.OknoAppConfig = ' . wp_json_encode( Okno_App::config( $view ) ) . ';', 'before' );
	}

	/**
	 * Publication automatique hors de l'éditeur Okno (liste des pages, écran
	 * de modification classique ou éditeur de blocs) : publie quand le délai
	 * de calme est écoulé, et tout de suite si l'utilisateur quitte wp-admin
	 * avec une publication en attente.
	 */
	private function enqueue_autopublish() {
		if ( ! Okno_Deploy_Manager::user_can_deploy() || ! Okno_Deploy_Manager::auto_enabled() ) {
			return;
		}

		wp_enqueue_script( 'okno-autopublish', OKNO_URL . 'assets/admin/autopublish.js', array(), self::asset_version( 'assets/admin/autopublish.js' ), true );
		wp_add_inline_script( 'okno-autopublish', 'window.OknoAutoPublish = ' . wp_json_encode( self::autopublish_config() ) . ';', 'before' );
	}

	/**
	 * État de la publication automatique pour le navigateur.
	 *
	 * @return array
	 */
	private static function autopublish_config() {
		$delay = Okno_Deploy_Manager::auto_delay();
		$since = Okno_Deploy_Manager::pending_since();
		return array(
			'pending'  => (bool) $since,
			'dueIn'    => $since ? max( 0, $delay - ( time() - $since ) ) : 0,
			'delay'    => $delay,
			'flushUrl' => esc_url_raw( rest_url( Okno_Rest::NS . '/deploy/flush' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'adminUrl' => admin_url(),
		);
	}

	private function enqueue_dashboard() {
		$settings = Okno_Plugin::settings();
		$mapper   = new Okno_Url_Mapper();

		wp_enqueue_style( 'okno-admin', OKNO_URL . 'assets/admin/admin.css', array(), self::asset_version( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'okno-admin', OKNO_URL . 'assets/admin/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/admin.js' ), true );
		wp_set_script_translations( 'okno-admin', 'okno', OKNO_DIR . 'languages' );

		$preview = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];

		wp_add_inline_script(
			'okno-admin',
			'window.OknoAdmin = ' . wp_json_encode(
				array(
					'previewUrl'  => $preview ? trailingslashit( $preview ) : '',
					'frontOrigin' => $mapper->preview_origin(),
					'strings'     => array(
						'copied'          => __( 'Copied', 'okno' ),
						'copy'            => __( 'Copy', 'okno' ),
						'checking'        => __( 'Checking…', 'okno' ),
						'testing'         => __( 'Connecting to the site…', 'okno' ),
						/* translators: 1: bridge version, 2: number of fields, 3: number of sections. */
						'bridgeOk'        => __( 'Bridge v%1$s detected: %2$d annotated field(s) and %3$d section(s) on the home page. The editor is ready.', 'okno' ),
						'bridgeNoFields'  => __( 'The bridge responds, but no field is annotated on the home page. Add data-wp-post and data-wp-field to your content elements.', 'okno' ),
						'bridgeTimeout'   => __( 'The site didn’t respond within 10 seconds. Either the frame-ancestors header blocks display (step 2), or the bridge isn’t loaded (step 3).', 'okno' ),
						'headersFailed'   => __( 'Can’t reach the site from the WordPress server.', 'okno' ),
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

		wp_enqueue_style( 'okno-editor', OKNO_URL . 'assets/admin/editor.css', array(), self::asset_version( 'assets/admin/editor.css' ) );
		wp_enqueue_script( 'okno-editor', OKNO_URL . 'assets/admin/editor.js', array( 'wp-api-fetch', 'wp-i18n' ), self::asset_version( 'assets/admin/editor.js' ), true );
		wp_set_script_translations( 'okno-editor', 'okno', OKNO_DIR . 'languages' );

		$mapper = new Okno_Url_Mapper();
		$driver = Okno_Deploy_Manager::driver();
		$active = Okno_Deploy_Manager::active_deploy();

		$settings   = Okno_Plugin::settings();
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
			'initialPost'          => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // Collections : app-content.js ouvre l'entrée (id).
			'initialField'         => isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : '',
			'mode'                 => $mode,
			'homeUrl'              => Okno_App::url(),
			'collectionsUrl'       => Okno_App::url( 'collections' ),
			'editorUrl'            => Okno_App::url( 'editor' ),
			'startUrl'             => Okno_App::url( 'start' ),
			'settingsUrl'          => Okno_App::url( 'settings' ),
			'liveMode'             => 'live' === $settings['deploy_driver'],
			'driverConfigured'     => (bool) $driver,
			'driverSupportsStatus' => $driver ? $driver->supports_status() : false,
			'activeDeploy'         => $active ? $active['id'] : '',
			'handshakeTimeout'     => 8000,
			'canDeploy'            => Okno_Deploy_Manager::user_can_deploy(),
			'autoDeploy'           => Okno_Deploy_Manager::auto_enabled() ? self::autopublish_config() : null,
			'formTypes'            => self::form_types(),
		);
		// phpcs:enable

		wp_add_inline_script( 'okno-editor', 'window.OknoConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Types édités en formulaire : collections sans page sur le site.
	 *
	 * @return string[]
	 */
	public static function form_types() {
		$settings = Okno_Plugin::settings();
		$out      = array();
		foreach ( Okno_Collections::post_types() as $type ) {
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
		$settings   = Okno_Plugin::settings();
		$configured = '' !== $settings['front_url'];
		$form       = 'collections' === $mode;

		if ( ! $configured && ! $form ) {
			?>
			<div class="okno-editor-setup">
				<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="40" height="40">
				<h1><?php esc_html_e( 'Okno isn’t connected to your site yet', 'okno' ); ?></h1>
				<p><?php esc_html_e( 'Enter your site’s URL and install the bridge. The editor will then open right on your pages.', 'okno' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="okno-setup-btn" href="<?php echo esc_url( Okno_App::url( 'start' ) ); ?>"><?php esc_html_e( 'Connect the site', 'okno' ); ?></a>
				<?php else : ?>
					<p><?php esc_html_e( 'Ask the site administrator to finish the setup.', 'okno' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( ! $form ) {
			?>
			<div id="okno-editor" class="okno-editor" data-mode="visual">
				<?php $this->render_toolbar( false ); ?>
				<div class="okno-main">
					<?php $this->render_sidebar(); ?>
					<?php $this->render_stage(); ?>
					<aside class="okno-panel" id="okno-panel" aria-label="<?php esc_attr_e( 'Selection fields', 'okno' ); ?>"></aside>
				</div>
			</div>
			<?php
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation.
		$okno_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$okno_id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( $okno_id && '' === $okno_type ) {
			$okno_type = (string) get_post_type( $okno_id );
		}
		?>
		<div id="okno-editor" class="okno-editor okno-editor--collections" data-mode="form">
			<div class="okno-main">
				<?php // Types et entrées (app-content.js). ?>
				<div id="okno-form-browser" class="okno-form-browser" data-type="<?php echo esc_attr( $okno_type ); ?>" data-post="<?php echo esc_attr( $okno_id ); ?>"></div>
				<section class="okno-entry" id="okno-entry" aria-label="<?php esc_attr_e( 'Entry', 'okno' ); ?>">
					<?php $this->render_toolbar( true ); ?>
					<div class="okno-entry__body">
						<aside class="okno-panel" id="okno-panel" aria-label="<?php esc_attr_e( 'Entry fields', 'okno' ); ?>"></aside>
						<?php $this->render_sidebar(); ?>
					</div>
					<?php $this->render_stage(); ?>
				</section>
				<?php // Champs d'une collection Okno (app-collections.js). ?>
				<div id="okno-builder" class="okno-builder-host" hidden></div>
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
		<header class="okno-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Editor actions', 'okno' ); ?>">
			<div class="okno-toolbar-start">
				<?php if ( $form ) : ?>
					<button type="button" id="okno-entry-back" class="okno-icon-btn okno-entry-back" title="<?php esc_attr_e( 'Back to the entries', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Back to the entries', 'okno' ); ?>">
						<?php Okno_Icons::render( 'back', 16 ); ?>
					</button>
				<?php else : ?>
					<a class="okno-exit" href="<?php echo esc_url( Okno_App::url() ); ?>" title="<?php esc_attr_e( 'Back to Okno home', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Back to Okno home', 'okno' ); ?>">
						<?php Okno_Icons::render( 'back', 16 ); ?>
					</a>
					<img class="okno-mark" src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="Okno" width="22" height="22">
				<?php endif; ?>
				<div class="okno-doc">
					<span class="okno-doc-row">
						<span id="okno-current-title" class="okno-current-title"><?php echo esc_html( $form ? '' : __( 'No page open', 'okno' ) ); ?></span>
						<label class="screen-reader-text" for="okno-status"><?php esc_html_e( 'Status', 'okno' ); ?></label>
						<select id="okno-status" class="okno-status-select" hidden></select>
					</span>
					<span id="okno-save-state" class="okno-save-state" aria-live="polite"></span>
				</div>
			</div>

			<?php if ( ! $form ) : ?>
				<div class="okno-toolbar-center">
					<span class="okno-viewport-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'okno' ); ?>">
						<button type="button" class="okno-vw active" data-width="" title="<?php esc_attr_e( 'Desktop', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Desktop preview', 'okno' ); ?>"><?php Okno_Icons::render( 'desktop', 16 ); ?></button>
						<button type="button" class="okno-vw" data-width="768" title="<?php esc_attr_e( 'Tablet', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Tablet preview', 'okno' ); ?>"><?php Okno_Icons::render( 'tablet', 16 ); ?></button>
						<button type="button" class="okno-vw" data-width="390" title="<?php esc_attr_e( 'Mobile', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Mobile preview', 'okno' ); ?>"><?php Okno_Icons::render( 'mobile', 16 ); ?></button>
					</span>
				</div>
			<?php endif; ?>

			<div class="okno-toolbar-end">
				<?php if ( $form ) : ?>
					<a id="okno-open-visual" class="okno-btn okno-btn--quiet" href="#" hidden>
						<?php Okno_Icons::render( 'edit', 14 ); ?>
						<span><?php esc_html_e( 'Visual editor', 'okno' ); ?></span>
					</a>
					<a id="okno-open-site" class="okno-icon-btn" href="#" target="_blank" rel="noopener" title="<?php esc_attr_e( 'View on the site', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'View on the site (new tab)', 'okno' ); ?>" hidden>
						<?php Okno_Icons::render( 'external', 15 ); ?>
					</a>
					<button type="button" id="okno-history-toggle" class="okno-icon-btn" title="<?php esc_attr_e( 'History', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Show the history of this entry', 'okno' ); ?>" aria-pressed="false" aria-controls="okno-history-list" disabled>
						<?php Okno_Icons::render( 'history', 16 ); ?>
					</button>
				<?php endif; ?>
				<span class="okno-history" role="group" aria-label="<?php esc_attr_e( 'Undo and redo', 'okno' ); ?>">
					<button type="button" id="okno-undo" class="okno-icon-btn" title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Undo the last change', 'okno' ); ?>" disabled><?php Okno_Icons::render( 'undo', 16 ); ?></button>
					<button type="button" id="okno-redo" class="okno-icon-btn" title="<?php esc_attr_e( 'Redo (Ctrl+Shift+Z)', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Redo the undone change', 'okno' ); ?>" disabled><?php Okno_Icons::render( 'redo', 16 ); ?></button>
				</span>
				<button type="button" id="okno-theme" class="okno-icon-btn" title="<?php esc_attr_e( 'Light or dark theme', 'okno' ); ?>" aria-label="<?php esc_attr_e( 'Switch to dark theme', 'okno' ); ?>" aria-pressed="false"><?php Okno_Icons::render( 'moon', 16 ); ?></button>
				<span id="okno-deploy-banner" class="okno-deploy-banner" hidden></span>
				<button type="button" id="okno-save" class="okno-btn okno-btn--quiet" disabled><?php esc_html_e( 'Save', 'okno' ); ?></button>
				<button type="button" id="okno-publish" class="okno-btn" disabled><?php esc_html_e( 'Publish', 'okno' ); ?></button>
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
		<aside class="okno-sidebar" aria-label="<?php esc_attr_e( 'Site navigation', 'okno' ); ?>">
			<div class="okno-sidebar-tabs">
				<button type="button" id="okno-tab-pages" class="okno-sidebar-tab active">
					<span><?php esc_html_e( 'Pages', 'okno' ); ?></span>
				</button>
				<button type="button" id="okno-tab-structure" class="okno-sidebar-tab" disabled>
					<span><?php esc_html_e( 'Structure', 'okno' ); ?></span>
				</button>
				<button type="button" id="okno-tab-history" class="okno-sidebar-tab" disabled>
					<span><?php esc_html_e( 'History', 'okno' ); ?></span>
				</button>
			</div>
			<div id="okno-pages-list" class="okno-sidebar-body"></div>
			<div id="okno-structure-tree" class="okno-sidebar-body" hidden></div>
			<div id="okno-history-list" class="okno-sidebar-body" hidden></div>
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
		<div class="okno-stage">
			<div class="okno-frame-wrap" id="okno-frame-wrap">
				<div class="okno-frame-placeholder" id="okno-frame-placeholder">
					<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="36" height="36">
					<p><?php esc_html_e( 'Pick a page on the left to show it here.', 'okno' ); ?></p>
				</div>
				<iframe id="okno-frame" title="<?php esc_attr_e( 'Site preview', 'okno' ); ?>" hidden></iframe>

				<div id="okno-frame-help" class="okno-frame-help" hidden>
					<h2><?php esc_html_e( 'The site isn’t responding', 'okno' ); ?></h2>
					<p><?php esc_html_e( 'The preview loaded, but the Okno bridge didn’t check in. Two possible causes:', 'okno' ); ?></p>
					<ol>
						<li><strong><?php esc_html_e( 'The site refuses to be displayed in wp-admin.', 'okno' ); ?></strong> <?php esc_html_e( 'Its frame-ancestors header must allow this address.', 'okno' ); ?></li>
						<li><strong><?php esc_html_e( 'The bridge isn’t loaded on this page.', 'okno' ); ?></strong> <?php esc_html_e( 'Check the installation.', 'okno' ); ?></li>
					</ol>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="okno-btn" href="<?php echo esc_url( Okno_App::url( 'start' ) ); ?>"><?php esc_html_e( 'Test the connection', 'okno' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Enregistrement des réglages
	 * ------------------------------------------------------------------- */

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'okno' ) );
		}
		check_admin_referer( 'okno_save_settings' );

		$input = isset( $_POST['okno_settings'] ) && is_array( $_POST['okno_settings'] ) ? wp_unslash( $_POST['okno_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$settings = Okno_Plugin::settings();

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
		$settings['deploy_driver'] = array_key_exists( $driver, Okno_Dashboard::drivers() ) ? $driver : '';

		$settings['build_hook_url']      = esc_url_raw( isset( $input['build_hook_url'] ) ? trim( $input['build_hook_url'] ) : '' );
		$settings['build_hook_eta']      = max( 1, absint( isset( $input['build_hook_eta'] ) ? $input['build_hook_eta'] : 3 ) );
		$settings['coolify_url']         = esc_url_raw( isset( $input['coolify_url'] ) ? trim( $input['coolify_url'] ) : '' );
		$settings['gh_repo']             = sanitize_text_field( isset( $input['gh_repo'] ) ? $input['gh_repo'] : '' );
		$settings['gh_workflow']         = sanitize_text_field( isset( $input['gh_workflow'] ) ? $input['gh_workflow'] : '' );
		$settings['gh_branch']           = sanitize_text_field( isset( $input['gh_branch'] ) ? $input['gh_branch'] : '' );
		$settings['min_deploy_interval'] = absint( isset( $input['min_deploy_interval'] ) ? $input['min_deploy_interval'] : 60 );
		$settings['auto_deploy']         = ! empty( $input['auto_deploy'] );
		$settings['auto_deploy_delay']   = max( 15, absint( isset( $input['auto_deploy_delay'] ) ? $input['auto_deploy_delay'] : 60 ) );
		$settings['content_access']      = isset( $input['content_access'] ) && 'token' === $input['content_access'] ? 'token' : 'public';
		$settings['content_cache']       = ! empty( $input['content_cache'] );
		$settings['editor_landing']      = ! empty( $input['editor_landing'] );
		foreach ( Okno_Headless::FLAGS as $flag ) {
			$settings[ $flag ] = ! empty( $input[ $flag ] );
		}

		update_option( Okno_Plugin::OPTION_SETTINGS, $settings );

		// Secrets : champ vide = inchangé, valeur = remplacement.
		$secrets = isset( $_POST['okno_secret'] ) && is_array( $_POST['okno_secret'] ) ? wp_unslash( $_POST['okno_secret'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array( 'coolify_token', 'gh_pat' ) as $name ) {
			if ( isset( $secrets[ $name ] ) && '' !== trim( $secrets[ $name ] ) ) {
				Okno_Secrets::set( $name, trim( $secrets[ $name ] ) );
			}
			if ( ! empty( $_POST[ 'okno_clear_' . $name ] ) ) {
				Okno_Secrets::set( $name, '' );
			}
		}

		// Jeton de l'API de contenu : créé au passage en accès restreint,
		// renouvelé sur demande (l'ancien cesse aussitôt de fonctionner).
		$token_status = Okno_Secrets::status( Okno_Content::SECRET_TOKEN );
		if ( ! empty( $_POST['okno_regen_content_token'] ) || ( 'token' === $settings['content_access'] && 'ok' !== $token_status ) ) {
			Okno_Secrets::set( Okno_Content::SECRET_TOKEN, wp_generate_password( 40, false ) );
		}

				// Contrôle préalable GitHub (dépôt, branche, workflow, token) : sans lui,
		// un « Not Found » ne se découvre qu'au premier déploiement raté.
		if ( 'github' === $settings['deploy_driver'] ) {
			Okno_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'], $settings['gh_workflow'] );
		} elseif ( 'github_commit' === $settings['deploy_driver'] ) {
			Okno_Github_Preflight::run_and_store( $settings['gh_repo'], $settings['gh_branch'] );
		}

		wp_safe_redirect( Okno_App::url( 'settings', array( 'okno_saved' => '1' ) ) );
		exit;
	}
}
