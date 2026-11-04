<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pages d'administration d'Okno : Accueil, Démarrer, Réglages, Publications.
 *
 * Chaque onglet est une vraie page WordPress (slug propre) : le menu latéral
 * reste synchronisé et chaque onglet a une URL partageable.
 */
class Okno_Dashboard {

	const OPTION_CONNECTION = 'okno_connection';

	/**
	 * Onglets : slug de page => libellé, capability.
	 *
	 * @return array<string,array{label:string,cap:string}>
	 */
	public static function tabs() {
		return array(
			'okno'          => array(
				'label' => __( 'Home', 'okno' ),
				'cap'   => 'edit_posts',
			),
			'okno-start'    => array(
				'label' => __( 'Get started', 'okno' ),
				'cap'   => 'manage_options',
			),
			'okno-settings' => array(
				'label' => __( 'Settings', 'okno' ),
				'cap'   => 'manage_options',
			),
			'okno-deploys'  => array(
				'label' => __( 'Deployments', 'okno' ),
				'cap'   => 'edit_posts',
			),
		);
	}

	/**
	 * Point d'entrée : rend l'onglet correspondant à la page courante.
	 */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lecture du slug de page.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'okno';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $page ] ) || ! current_user_can( $tabs[ $page ]['cap'] ) ) {
			$page = 'okno';
		}
		?>
		<div class="okno-admin">
			<?php self::render_bar( $page ); ?>
			<?php
			switch ( $page ) {
				case 'okno-start':
					self::render_start();
					break;
				case 'okno-settings':
					self::render_settings();
					break;
				case 'okno-deploys':
					self::render_deploys();
					break;
				default:
					self::render_home();
			}
			?>
		</div>
		<?php
	}

	private static function render_bar( $current ) {
		?>
		<header class="okno-bar">
			<div class="okno-bar__brand">
				<img src="<?php echo esc_url( OKNO_URL . 'assets/img/okno-mark.svg' ); ?>" alt="" width="24" height="24">
				<h1 class="okno-bar__title">Okno</h1>
				<span class="okno-bar__version">v<?php echo esc_html( OKNO_VERSION ); ?></span>
			</div>
			<nav class="okno-tabs" aria-label="<?php esc_attr_e( 'Okno sections', 'okno' ); ?>">
				<?php foreach ( self::tabs() as $slug => $tab ) : ?>
					<?php
					if ( ! current_user_can( $tab['cap'] ) ) {
						continue;
					}
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</header>
		<?php
	}

	/* =====================================================================
	 * Accueil
	 * =================================================================== */

	private static function render_home() {
		$settings   = Okno_Plugin::settings();
		$configured = '' !== $settings['front_url'];
		$connection = self::connection();
		$history    = Okno_Deploy_Manager::history();
		$last       = reset( $history );
		$week_count = Okno_Activity::count_since( time() - WEEK_IN_SECONDS );
		$activities = Okno_Activity::recent( 40 );
		?>
		<div class="okno-page">

			<?php if ( ! $configured && current_user_can( 'manage_options' ) ) : ?>
				<div class="okno-card okno-card--accent okno-open">
					<div class="okno-open__text">
						<span class="okno-eyebrow"><?php esc_html_e( 'First step', 'okno' ); ?></span>
						<h2><?php esc_html_e( 'Connect your site to Okno', 'okno' ); ?></h2>
						<p><?php esc_html_e( 'Enter your site’s URL, allow the editor to display it, install the bridge: four guided steps, each with a check.', 'okno' ); ?></p>
					</div>
					<a class="okno-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-start' ) ); ?>">
						<?php esc_html_e( 'Get started', 'okno' ); ?>
						<?php Okno_Icons::render( 'arrow', 16 ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="okno-card okno-card--accent okno-open">
					<div class="okno-open__text">
						<h2><?php esc_html_e( 'Edit your site as you see it', 'okno' ); ?></h2>
						<p><?php esc_html_e( 'Click any text or image on your site to edit it. You see the result right away, before you save.', 'okno' ); ?></p>
					</div>
					<div class="okno-actions" style="margin-top:0">
						<a class="okno-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-editor' ) ); ?>">
							<?php esc_html_e( 'Open the editor', 'okno' ); ?>
						</a>
						<?php if ( $configured ) : ?>
							<a class="okno-btn okno-btn--quiet" href="<?php echo esc_url( $settings['front_url'] ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View site', 'okno' ); ?>
								<?php Okno_Icons::render( 'external', 14 ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="okno-stats">
				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Site connection', 'okno' ); ?></span>
					<?php if ( ! $connection ) : ?>
						<span class="okno-stat__value"><span class="okno-dot"></span><?php esc_html_e( 'Not tested yet', 'okno' ); ?></span>
					<?php elseif ( $connection['ok'] ) : ?>
						<span class="okno-stat__value"><span class="okno-dot okno-dot--ok"></span><?php esc_html_e( 'Working', 'okno' ); ?></span>
						<span class="okno-stat__hint">
							<?php
							/* translators: %s: relative time (e.g. 5 mins). */
							echo esc_html( sprintf( __( 'Checked %s ago', 'okno' ), human_time_diff( $connection['at'] ) ) );
							?>
						</span>
					<?php else : ?>
						<span class="okno-stat__value"><span class="okno-dot okno-dot--error"></span><?php esc_html_e( 'Needs attention', 'okno' ); ?></span>
						<span class="okno-stat__hint"><?php esc_html_e( 'The last test failed.', 'okno' ); ?></span>
					<?php endif; ?>
				</div>

				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Publishing', 'okno' ); ?></span>
					<span class="okno-stat__value">
						<span class="okno-dot <?php echo '' === $settings['deploy_driver'] ? '' : 'okno-dot--ok'; ?>"></span>
						<?php echo esc_html( self::driver_label( $settings['deploy_driver'] ) ); ?>
					</span>
					<?php if ( $last ) : ?>
						<span class="okno-stat__hint">
							<?php
							/* translators: 1: deployment status, 2: relative time (e.g. 5 mins). */
							echo esc_html( sprintf( __( 'Last deployment: %1$s, %2$s ago', 'okno' ), self::status_label( $last['status'] ), human_time_diff( $last['started'] ) ) );
							?>
						</span>
					<?php endif; ?>
				</div>

				<div class="okno-stat">
					<span class="okno-stat__label"><?php esc_html_e( 'Changes this week', 'okno' ); ?></span>
					<span class="okno-stat__value"><?php echo esc_html( number_format_i18n( $week_count ) ); ?></span>
				</div>
			</div>

			<h2 class="okno-heading" style="margin-top:40px"><?php esc_html_e( 'Recent changes', 'okno' ); ?></h2>

			<div class="okno-card okno-card--flush">
				<?php if ( ! $activities ) : ?>
					<div class="okno-empty">
						<?php Okno_Icons::render( 'history', 32 ); ?>
						<h3><?php esc_html_e( 'No changes yet', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'Every save made in the editor shows up here: who changed which field, when, with the before and after values.', 'okno' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="okno-log">
						<?php foreach ( $activities as $activity ) : ?>
							<?php
							if ( ! current_user_can( 'edit_post', (int) $activity->post_id ) ) {
								continue;
							}
							self::render_activity( $activity );
							?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_activity( $activity ) {
		$post_id   = (int) $activity->post_id;
		$title     = $post_id ? Okno_Plugin::title( $post_id ) : '';
		$when      = strtotime( $activity->created_at . ' UTC' );
		$open_url  = add_query_arg(
			array(
				'page'  => 'okno-editor',
				'post'  => $post_id,
				'field' => $activity->field_path,
			),
			admin_url( 'admin.php' )
		);
		$initials  = self::initials( $activity->user_name );
		?>
		<li class="okno-log__item">
			<div class="okno-log__head">
				<div>
					<strong class="okno-log__field" title="<?php echo esc_attr( $activity->field_path ); ?>"><?php echo esc_html( $activity->field_label ); ?></strong>
					<span class="okno-log__meta">
						<span class="okno-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: author, 2: content title, 3: relative time (e.g. 5 mins). */
								__( '%1$s · %2$s · %3$s ago', 'okno' ),
								$activity->user_name ? $activity->user_name : __( 'Unknown user', 'okno' ),
								$title ? $title : __( 'deleted content', 'okno' ),
								$when ? human_time_diff( $when ) : '?'
							)
						);
						?>
					</span>
				</div>
				<?php if ( $post_id && get_post( $post_id ) ) : ?>
					<a class="okno-btn okno-btn--quiet okno-btn--small" href="<?php echo esc_url( $open_url ); ?>">
						<?php esc_html_e( 'Open', 'okno' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<div class="okno-diff">
				<div class="okno-diff__value">
					<span class="okno-diff__label"><?php esc_html_e( 'Before', 'okno' ); ?></span>
					<?php self::render_value( $activity->old_value, $activity->field_type ); ?>
				</div>
				<span class="okno-diff__arrow"><?php Okno_Icons::render( 'arrow', 16 ); ?></span>
				<div class="okno-diff__value okno-diff__value--after">
					<span class="okno-diff__label"><?php esc_html_e( 'After', 'okno' ); ?></span>
					<?php self::render_value( $activity->new_value, $activity->field_type ); ?>
				</div>
			</div>
		</li>
		<?php
	}

	/**
	 * Affichage lisible d'une valeur journalisée, selon son type.
	 */
	private static function render_value( $value, $type ) {
		if ( '' === $value || '[]' === $value || '{}' === $value ) {
			echo '<span class="okno-diff__empty">' . esc_html__( 'empty', 'okno' ) . '</span>';
			return;
		}

		switch ( $type ) {
			case 'image':
			case 'file':
				$url = wp_get_attachment_image_url( (int) $value, 'thumbnail' );
				if ( $url && 'image' === $type ) {
					echo '<img src="' . esc_url( $url ) . '" alt="">';
				} else {
					echo esc_html( Okno_Plugin::title( (int) $value ) );
				}
				return;

			case 'wysiwyg':
				echo wp_kses_post( $value );
				return;

			case 'true_false':
				echo esc_html( '1' === $value ? __( 'Yes', 'okno' ) : __( 'No', 'okno' ) );
				return;

			case 'link':
				$link = json_decode( $value, true );
				if ( is_array( $link ) ) {
					echo esc_html( trim( ( isset( $link['title'] ) ? $link['title'] . ' — ' : '' ) . ( isset( $link['url'] ) ? $link['url'] : '' ), ' —' ) );
					return;
				}
				break;

			case 'repeater':
			case 'flexible_content':
			case 'gallery':
			case 'relationship':
				$items = json_decode( $value, true );
				if ( is_array( $items ) ) {
					/* translators: %d: number of items. */
					echo esc_html( sprintf( _n( '%d item', '%d items', count( $items ), 'okno' ), count( $items ) ) );
					return;
				}
				break;
		}

		echo esc_html( wp_html_excerpt( wp_strip_all_tags( $value ), 400, '…' ) );
	}

	private static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( array_filter( (array) $parts ), 0, 2 ) as $part ) {
			$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
		}
		return '' !== $out ? $out : '?';
	}

	/* =====================================================================
	 * Démarrer
	 * =================================================================== */

	private static function render_start() {
		$settings    = Okno_Plugin::settings();
		$wp_origin   = Okno_Admin::wp_origin();
		$has_url     = '' !== $settings['front_url'];
		$connection  = self::connection();
		$bridge_file = OKNO_URL . 'assets/bridge/okno-bridge.js';

		$loader = "<script>\n  if (window.self !== window.top) {\n    var s = document.createElement('script');\n    s.src = '/okno-bridge.js';\n    s.setAttribute('data-wp-origin', '" . $wp_origin . "');\n    document.head.appendChild(s);\n  }\n</script>";
		$react  = "npm install @pixelersagency/okno-bridge\n\n// In the root layout (Next.js: app/layout.tsx, Remix: root.tsx, Vite: App.tsx)\nimport { OknoBridge } from '@pixelersagency/okno-bridge/react';\n\n<OknoBridge wpOrigin=\"" . $wp_origin . '" />';
		$csp    = "Content-Security-Policy: frame-ancestors 'self' " . $wp_origin;

		// Version française du guide pour un wp-admin en français, anglaise sinon.
		$instructions_file = OKNO_DIR . 'docs/agent-instructions.md';
		if ( 0 === strpos( determine_locale(), 'fr' ) && file_exists( OKNO_DIR . 'docs/agent-instructions.fr.md' ) ) {
			$instructions_file = OKNO_DIR . 'docs/agent-instructions.fr.md';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local du plugin.
		$instructions = file_exists( $instructions_file ) ? file_get_contents( $instructions_file ) : '';
		$instructions = str_replace( 'https://ADRESSE-DU-WORDPRESS', $wp_origin, $instructions );

		$intro        = __( 'We’re going to make this site editable with Okno, the visual editor for WordPress. Okno has precise instructions to follow throughout the project; you’ll find them below. Read them in full before writing any code, and follow them for every component.', 'okno' );
		$prompt_exist = $intro . "\n\n" . __( 'The site already exists. Install the bridge, then annotate every piece of displayed content with its matching WordPress field, following the instructions. Finish with the checklist.', 'okno' ) . "\n\n" . __( 'Site repository:', 'okno' ) . "\n\n---\n\n" . $instructions;
		$prompt_new   = $intro . "\n\n" . __( 'We’re starting from scratch. Build the site and its ACF content model, following the instructions from the very first component. Finish with the checklist.', 'okno' ) . "\n\n" . __( 'Site idea or brief:', 'okno' ) . "\n\n---\n\n" . $instructions;
		?>
		<div class="okno-page okno-page--narrow">
			<span class="okno-eyebrow"><?php esc_html_e( 'Connect a site', 'okno' ); ?></span>
			<h2 style="margin:0 0 8px;font-size:22px;color:var(--okno-ink)"><?php esc_html_e( 'Make your site editable', 'okno' ); ?></h2>
			<p class="okno-lead"><?php esc_html_e( 'Okno works with any site that reads its content from WordPress: React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit or plain HTML. Four steps, each one verifiable.', 'okno' ); ?></p>

			<ol class="okno-steps">
				<li class="okno-step <?php echo $has_url ? 'okno-step--done' : 'okno-step--current'; ?>">
					<h3>
						<?php esc_html_e( 'Site URL', 'okno' ); ?>
						<?php if ( $has_url ) : ?>
							<span class="okno-pill okno-pill--ok"><?php echo esc_html( $settings['front_url'] ); ?></span>
						<?php endif; ?>
					</h3>
					<p><?php esc_html_e( 'The site’s public URL and, if you have one, the preview URL to show in the editor.', 'okno' ); ?></p>
					<a class="okno-btn <?php echo $has_url ? 'okno-btn--quiet okno-btn--small' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=okno-settings' ) ); ?>">
						<?php echo $has_url ? esc_html__( 'Edit', 'okno' ) : esc_html__( 'Enter the URL', 'okno' ); ?>
					</a>
				</li>

				<li class="okno-step">
					<h3><?php esc_html_e( 'Allow the editor to display the site', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'The site must allow being displayed in wp-admin. Add this HTTP header to its responses (host settings, middleware or the framework’s headers file).', 'okno' ); ?></p>
					<div class="okno-code">
						<pre><?php echo esc_html( $csp ); ?></pre>
						<button type="button" data-okno-copy="<?php echo esc_attr( $csp ); ?>"><?php esc_html_e( 'Copy', 'okno' ); ?></button>
					</div>
					<div class="okno-actions">
						<button type="button" class="okno-btn okno-btn--quiet okno-btn--small" data-okno-check-headers <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Check headers', 'okno' ); ?>
						</button>
					</div>
					<div class="okno-check" data-okno-headers-result hidden></div>
				</li>

				<li class="okno-step">
					<h3><?php esc_html_e( 'Install the bridge', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'A small script that only loads inside the editor. Your visitors never download it.', 'okno' ); ?></p>
					<div class="okno-segmented" role="tablist" data-okno-segmented>
						<button type="button" role="tab" aria-selected="true" data-panel="html"><?php esc_html_e( 'HTML · Astro · Nuxt · SvelteKit', 'okno' ); ?></button>
						<button type="button" role="tab" aria-selected="false" data-panel="react"><?php esc_html_e( 'React · Next.js · Remix · Vite', 'okno' ); ?></button>
					</div>
					<div data-panel-id="html">
						<p class="okno-help"><?php esc_html_e( 'Put okno-bridge.js in the site’s public folder, then add this code to the layout’s <head>.', 'okno' ); ?></p>
						<div class="okno-code">
							<pre><?php echo esc_html( $loader ); ?></pre>
							<button type="button" data-okno-copy="<?php echo esc_attr( $loader ); ?>"><?php esc_html_e( 'Copy', 'okno' ); ?></button>
						</div>
						<div class="okno-actions">
							<a class="okno-btn okno-btn--quiet okno-btn--small" href="<?php echo esc_url( $bridge_file ); ?>" download="okno-bridge.js"><?php esc_html_e( 'Download okno-bridge.js', 'okno' ); ?></a>
						</div>
					</div>
					<div data-panel-id="react" hidden>
						<p class="okno-help"><?php esc_html_e( 'The component loads the bridge only inside the editor, through a dynamic import.', 'okno' ); ?></p>
						<div class="okno-code">
							<pre><?php echo esc_html( $react ); ?></pre>
							<button type="button" data-okno-copy="<?php echo esc_attr( $react ); ?>"><?php esc_html_e( 'Copy', 'okno' ); ?></button>
						</div>
					</div>
				</li>

				<li class="okno-step <?php echo ( $connection && $connection['ok'] ) ? 'okno-step--done' : ''; ?>">
					<h3><?php esc_html_e( 'Test the connection', 'okno' ); ?></h3>
					<p><?php esc_html_e( 'Okno opens your site in the background and waits for the bridge to check in. If it responds, the editor is ready.', 'okno' ); ?></p>
					<div class="okno-actions">
						<button type="button" class="okno-btn" data-okno-test-bridge <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Test the connection', 'okno' ); ?>
						</button>
					</div>
					<div class="okno-check <?php echo $connection ? ( $connection['ok'] ? 'okno-check--ok' : 'okno-check--error' ) : ''; ?>" data-okno-bridge-result <?php echo $connection ? '' : 'hidden'; ?>>
						<?php if ( $connection ) : ?>
							<span class="okno-dot <?php echo $connection['ok'] ? 'okno-dot--ok' : 'okno-dot--error'; ?>"></span>
							<span>
								<?php
								echo esc_html(
									$connection['ok']
										/* translators: 1: bridge version, 2: number of fields, 3: relative time (e.g. 5 mins). */
										? sprintf( __( 'Bridge v%1$s detected, %2$d annotated field(s) on the home page. Checked %3$s ago.', 'okno' ), $connection['version'], $connection['fields'], human_time_diff( $connection['at'] ) )
										/* translators: %s: relative time (e.g. 5 mins). */
										: sprintf( __( 'The bridge didn’t respond to the last test, %s ago.', 'okno' ), human_time_diff( $connection['at'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			</ol>

			<h2 class="okno-heading" style="margin-top:48px"><?php esc_html_e( 'Annotate your content', 'okno' ); ?></h2>
			<div class="okno-card">
				<p><?php esc_html_e( 'Every element that displays a WordPress field carries that field’s name. The bridge uses it to know what to update, and the editor to know which field to open on click.', 'okno' ); ?></p>
				<div class="okno-code">
					<pre><?php echo esc_html( "<section data-wp-post=\"12\" data-okno-section=\"Hero\">\n  <h1 data-wp-field=\"hero_title\">…</h1>\n  <img data-wp-field=\"hero_image\" src=\"…\" alt=\"…\">\n  <article data-wp-field=\"cards.0.title\">…</article>\n</section>" ); ?></pre>
				</div>
				<p class="okno-help" style="margin-top:12px"><?php esc_html_e( 'All the annotations, rules and the checklist are in the guide below.', 'okno' ); ?></p>
			</div>

			<h2 class="okno-heading" style="margin-top:48px"><?php esc_html_e( 'With an AI agent', 'okno' ); ?></h2>
			<div class="okno-card">
				<span class="okno-eyebrow"><?php esc_html_e( 'Claude, Codex, Cursor…', 'okno' ); ?></span>
				<h2><?php esc_html_e( 'Let the agent do the integration', 'okno' ); ?></h2>
				<p><?php esc_html_e( 'Okno gives your agent a precise contract: where to load the bridge, how to name and annotate fields, which mistakes to avoid, what to check before shipping. Copy the right prompt, then add your repository or brief.', 'okno' ); ?></p>

				<div class="okno-prompts">
					<div class="okno-prompt">
						<h3><?php esc_html_e( 'Integrate an existing site', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'The site already reads its content from WordPress. The agent installs the bridge and annotates all the content.', 'okno' ); ?></p>
						<button type="button" class="okno-btn okno-btn--small" data-okno-copy-from="okno-prompt-existing"><?php esc_html_e( 'Copy prompt', 'okno' ); ?></button>
						<textarea id="okno-prompt-existing" class="okno-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_exist ); ?></textarea>
					</div>
					<div class="okno-prompt">
						<h3><?php esc_html_e( 'Build a new site', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'The agent designs the site and its ACF content model, editable with Okno from the very first component.', 'okno' ); ?></p>
						<button type="button" class="okno-btn okno-btn--small" data-okno-copy-from="okno-prompt-new"><?php esc_html_e( 'Copy prompt', 'okno' ); ?></button>
						<textarea id="okno-prompt-new" class="okno-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_new ); ?></textarea>
					</div>
				</div>

				<details style="margin-top:20px">
					<summary style="cursor:pointer;color:var(--okno-ink);font-weight:600"><?php esc_html_e( 'Read the full instructions', 'okno' ); ?></summary>
					<textarea class="okno-instructions" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Agent instructions', 'okno' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
				</details>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Réglages
	 * =================================================================== */

	private static function render_settings() {
		$settings     = Okno_Plugin::settings();
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $public_types['attachment'] );
		$preflight    = Okno_Github_Preflight::consume();
		$drivers      = self::drivers();
		?>
		<div class="okno-page">

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau d'affichage. ?>
			<?php if ( isset( $_GET['okno_saved'] ) ) : ?>
				<div class="okno-notice okno-notice--ok" role="status">
					<span class="okno-dot okno-dot--ok"></span>
					<strong><?php esc_html_e( 'Settings saved.', 'okno' ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( $preflight ) : ?>
				<?php
				$levels = wp_list_pluck( $preflight, 'level' );
				$level  = in_array( 'error', $levels, true ) ? 'error' : ( in_array( 'warning', $levels, true ) ? 'warn' : 'ok' );
				?>
				<div class="okno-notice okno-notice--<?php echo esc_attr( $level ); ?>" role="status">
					<span class="okno-dot okno-dot--<?php echo esc_attr( $level ); ?>"></span>
					<div>
						<strong><?php esc_html_e( 'GitHub configuration check', 'okno' ); ?></strong>
						<ul>
							<?php foreach ( $preflight as $item ) : ?>
								<?php $item_level = 'warning' === $item['level'] ? 'warn' : $item['level']; ?>
								<li><span class="okno-pill okno-pill--<?php echo esc_attr( $item_level ); ?>"><?php echo esc_html( 'ok' === $item['level'] ? __( 'OK', 'okno' ) : ( 'error' === $item['level'] ? __( 'Error', 'okno' ) : __( 'Warning', 'okno' ) ) ); ?></span> <?php echo esc_html( $item['message'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-okno-settings>
				<input type="hidden" name="action" value="okno_save_settings">
				<?php wp_nonce_field( 'okno_save_settings' ); ?>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Your site', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'The site your clients edit, as visitors see it.', 'okno' ); ?></p>

					<div class="okno-row">
						<label for="okno_front_url"><?php esc_html_e( 'Site URL', 'okno' ); ?></label>
						<div>
							<input type="url" id="okno_front_url" name="okno_settings[front_url]" value="<?php echo esc_attr( $settings['front_url'] ); ?>" placeholder="https://www.example.com">
						</div>
					</div>
					<div class="okno-row">
						<label for="okno_preview_url"><?php esc_html_e( 'Preview URL', 'okno' ); ?></label>
						<div>
							<input type="url" id="okno_preview_url" name="okno_settings[preview_url]" value="<?php echo esc_attr( $settings['preview_url'] ); ?>" placeholder="https://preview.example.com">
							<span class="okno-help"><?php esc_html_e( 'Optional. Shown in the editor instead of the public site: useful when the public site is generated at build time and an up-to-date version is served elsewhere.', 'okno' ); ?></span>
						</div>
					</div>
				</section>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Editable content', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'The content types offered in the editor, and the URL of each one on the site.', 'okno' ); ?></p>

					<div class="okno-row">
						<span class="okno-row__label"><?php esc_html_e( 'Content types', 'okno' ); ?></span>
						<div class="okno-checks">
							<?php foreach ( $public_types as $type ) : ?>
								<label>
									<input type="checkbox" name="okno_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="okno-row">
						<span class="okno-row__label"><?php esc_html_e( 'URLs on the site', 'okno' ); ?></span>
						<div>
							<div class="okno-patterns">
								<?php foreach ( $public_types as $type ) : ?>
									<div>
										<code><?php echo esc_html( $type->name ); ?></code>
										<input type="text" name="okno_settings[url_patterns][<?php echo esc_attr( $type->name ); ?>]" value="<?php echo esc_attr( isset( $settings['url_patterns'][ $type->name ] ) ? $settings['url_patterns'][ $type->name ] : '' ); ?>" placeholder="/{slug}" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post type name. */ __( 'URL pattern for %s', 'okno' ), $type->labels->name ) ); ?>">
									</div>
								<?php endforeach; ?>
							</div>
							<span class="okno-help"><?php esc_html_e( 'Available variables: {slug}, {id}. Empty = /{slug}. The home page always points to /.', 'okno' ); ?></span>
						</div>
					</div>

					<div class="okno-row">
						<label for="okno_path_meta_key"><?php esc_html_e( 'Field holding the URL', 'okno' ); ?></label>
						<div>
							<input type="text" id="okno_path_meta_key" name="okno_settings[path_meta_key]" value="<?php echo esc_attr( $settings['path_meta_key'] ); ?>" placeholder="page_path">
							<span class="okno-help"><?php esc_html_e( 'Optional. Name of an ACF field or meta key that holds each item’s actual URL (for example /services/audit). Takes priority over the patterns above.', 'okno' ); ?></span>
						</div>
					</div>
				</section>

				<section class="okno-form-section">
					<h2><?php esc_html_e( 'Publishing', 'okno' ); ?></h2>
					<p class="okno-help"><?php esc_html_e( 'What happens when a client clicks “Publish”.', 'okno' ); ?></p>

					<div class="okno-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Publishing mode', 'okno' ); ?>">
						<?php foreach ( $drivers as $value => $driver ) : ?>
							<label class="okno-choice">
								<input type="radio" name="okno_settings[deploy_driver]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['deploy_driver'], $value ); ?> data-okno-driver>
								<strong><?php echo esc_html( $driver['label'] ); ?></strong>
								<span><?php echo esc_html( $driver['help'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook">
						<div class="okno-row">
							<label for="okno_build_hook_url"><?php esc_html_e( 'Build hook URL', 'okno' ); ?></label>
							<div>
								<input type="url" id="okno_build_hook_url" name="okno_settings[build_hook_url]" value="<?php echo esc_attr( $settings['build_hook_url'] ); ?>" placeholder="https://api.netlify.com/build_hooks/…">
							</div>
						</div>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="coolify">
						<div class="okno-row">
							<label for="okno_coolify_url"><?php esc_html_e( 'Coolify webhook URL', 'okno' ); ?></label>
							<div><input type="url" id="okno_coolify_url" name="okno_settings[coolify_url]" value="<?php echo esc_attr( $settings['coolify_url'] ); ?>"></div>
						</div>
						<?php self::secret_row( 'coolify_token', __( 'Coolify token', 'okno' ) ); ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook coolify github_commit">
						<div class="okno-row">
							<label for="okno_build_hook_eta"><?php esc_html_e( 'Build time', 'okno' ); ?></label>
							<div>
								<input type="number" id="okno_build_hook_eta" name="okno_settings[build_hook_eta]" value="<?php echo esc_attr( $settings['build_hook_eta'] ); ?>" min="1" max="60"> <span class="okno-muted"><?php esc_html_e( 'minutes', 'okno' ); ?></span>
								<span class="okno-help"><?php esc_html_e( 'Shown to the client after “Publish”, since the build can’t be tracked live.', 'okno' ); ?></span>
							</div>
						</div>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="github github_commit">
						<div class="okno-row">
							<label for="okno_gh_repo"><?php esc_html_e( 'GitHub repository', 'okno' ); ?></label>
							<div><input type="text" id="okno_gh_repo" name="okno_settings[gh_repo]" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" placeholder="organization/site"></div>
						</div>
						<div class="okno-driver-fields" data-okno-driver-fields="github">
							<div class="okno-row">
								<label for="okno_gh_workflow"><?php esc_html_e( 'Workflow file', 'okno' ); ?></label>
								<div><input type="text" id="okno_gh_workflow" name="okno_settings[gh_workflow]" value="<?php echo esc_attr( $settings['gh_workflow'] ); ?>" placeholder="deploy.yml"></div>
							</div>
						</div>
						<div class="okno-row">
							<label for="okno_gh_branch"><?php esc_html_e( 'Branch', 'okno' ); ?></label>
							<div><input type="text" id="okno_gh_branch" name="okno_settings[gh_branch]" value="<?php echo esc_attr( $settings['gh_branch'] ); ?>" placeholder="main"></div>
						</div>
						<?php self::secret_row( 'gh_pat', __( 'GitHub token', 'okno' ), __( 'Fine-grained token limited to this repository only: “Actions: Read and write” for GitHub Actions, “Contents: Read and write” for the deployment commit. Don’t use a classic token: it grants access to all your repositories.', 'okno' ) ); ?>
					</div>

					<div class="okno-driver-fields" data-okno-driver-fields="build_hook coolify github github_commit">
						<div class="okno-row">
							<span class="okno-row__label"><?php esc_html_e( 'Automatic publishing', 'okno' ); ?></span>
							<div>
								<label class="okno-inline-check"><input type="checkbox" name="okno_settings[auto_deploy]" value="1" <?php checked( $settings['auto_deploy'] ); ?>> <?php esc_html_e( 'Publish on its own after each change', 'okno' ); ?></label>
								<span class="okno-help">
									<?php
									printf(
										/* translators: %s: number input for the delay in seconds. */
										esc_html__( 'Publishes %s seconds after the last change, so a series of edits gives a single deployment. Changes made in wp-admin count too.', 'okno' ),
										'<input type="number" name="okno_settings[auto_deploy_delay]" value="' . esc_attr( $settings['auto_deploy_delay'] ) . '" min="15" style="width:5em" aria-label="' . esc_attr__( 'Delay before automatic publishing, in seconds', 'okno' ) . '">'
									);
									?>
								</span>
							</div>
						</div>
						<div class="okno-row">
							<label for="okno_min_interval"><?php esc_html_e( 'Delay between deployments', 'okno' ); ?></label>
							<div>
								<input type="number" id="okno_min_interval" name="okno_settings[min_deploy_interval]" value="<?php echo esc_attr( $settings['min_deploy_interval'] ); ?>" min="0"> <span class="okno-muted"><?php esc_html_e( 'seconds', 'okno' ); ?></span>
							</div>
						</div>
					</div>
				</section>

				<?php self::render_content_api_settings( $settings ); ?>

				<div class="okno-savebar">
					<span class="okno-muted"><?php esc_html_e( 'Secrets are encrypted in the database with a key derived from the WordPress salts.', 'okno' ); ?></span>
					<div class="okno-actions" style="margin:0">
						<?php if ( in_array( $settings['deploy_driver'], array( 'github', 'github_commit' ), true ) ) : ?>
							<button type="submit" class="okno-btn okno-btn--quiet" name="okno_test" value="1"><?php esc_html_e( 'Save and test GitHub', 'okno' ); ?></button>
						<?php endif; ?>
						<button type="submit" class="okno-btn"><?php esc_html_e( 'Save', 'okno' ); ?></button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Réglages de l'API de contenu et du durcissement headless.
	 *
	 * @param array $settings Réglages.
	 * @return void
	 */
	private static function render_content_api_settings( $settings ) {
		$token = 'ok' === Okno_Secrets::status( Okno_Content::SECRET_TOKEN ) ? (string) Okno_Secrets::get( Okno_Content::SECRET_TOKEN ) : '';
		?>
		<section class="okno-form-section">
			<h2><?php esc_html_e( 'Content API', 'okno' ); ?></h2>
			<p class="okno-help"><?php esc_html_e( 'Your front end reads its content here, in a single request at build time. WordPress stays the only source of truth.', 'okno' ); ?></p>

			<div class="okno-row">
				<span class="okno-row__label"><?php esc_html_e( 'Endpoints', 'okno' ); ?></span>
				<div>
					<code><?php echo esc_html( rest_url( Okno_Rest::NS . '/export' ) ); ?></code>
					<span class="okno-help"><?php esc_html_e( 'All published content, cached and refreshed on every change. An unchanged build gets a 304.', 'okno' ); ?></span>
					<code><?php echo esc_html( rest_url( Okno_Rest::NS . '/types' ) ); ?></code>
					<span class="okno-help"><?php esc_html_e( 'TypeScript types of that content, generated from your fields. In the front-end project: npx okno types.', 'okno' ); ?></span>
				</div>
			</div>

			<div class="okno-row">
				<span class="okno-row__label"><?php esc_html_e( 'Access', 'okno' ); ?></span>
				<div class="okno-checks">
					<label><input type="radio" name="okno_settings[content_access]" value="public" <?php checked( $settings['content_access'], 'public' ); ?>> <?php esc_html_e( 'Public: published content only, as on the site', 'okno' ); ?></label>
					<label><input type="radio" name="okno_settings[content_access]" value="token" <?php checked( $settings['content_access'], 'token' ); ?>> <?php esc_html_e( 'Restricted: the front end sends a token', 'okno' ); ?></label>
				</div>
			</div>

			<?php if ( '' !== $token ) : ?>
				<div class="okno-row">
					<label for="okno_content_token"><?php esc_html_e( 'Content token', 'okno' ); ?></label>
					<div>
						<input type="text" id="okno_content_token" value="<?php echo esc_attr( $token ); ?>" readonly onfocus="this.select()">
						<label class="okno-inline-check"><input type="checkbox" name="okno_regen_content_token" value="1"> <?php esc_html_e( 'Generate a new token (the current one stops working)', 'okno' ); ?></label>
						<span class="okno-help"><?php esc_html_e( 'Set it as OKNO_TOKEN in the front end’s build environment. Sent in the X-Okno-Token header.', 'okno' ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</section>

		<section class="okno-form-section">
			<h2><?php esc_html_e( 'WordPress as a back office', 'okno' ); ?></h2>
			<p class="okno-help"><?php esc_html_e( 'Your visitors use the front end. These options keep WordPress out of their way and out of search engines. wp-admin, login, the content API and draft previews are not affected.', 'okno' ); ?></p>

			<div class="okno-row">
				<span class="okno-row__label"><?php esc_html_e( 'Protection', 'okno' ); ?></span>
				<div class="okno-checks">
					<label><input type="checkbox" name="okno_settings[headless_noindex]" value="1" <?php checked( $settings['headless_noindex'] ); ?>> <?php esc_html_e( 'Keep WordPress out of search engines (noindex, robots.txt, no sitemap)', 'okno' ); ?></label>
					<label><input type="checkbox" name="okno_settings[headless_redirect]" value="1" <?php checked( $settings['headless_redirect'] ); ?>> <?php esc_html_e( 'Redirect WordPress pages to the same page on the site', 'okno' ); ?></label>
					<label><input type="checkbox" name="okno_settings[headless_lockdown]" value="1" <?php checked( $settings['headless_lockdown'] ); ?>> <?php esc_html_e( 'Lock down WordPress (XML-RPC off, user list hidden from visitors, version hidden)', 'okno' ); ?></label>
				</div>
			</div>
		</section>
		<?php
	}

	private static function secret_row( $name, $label, $help = '' ) {
		$status = Okno_Secrets::status( $name );
		?>
		<div class="okno-row">
			<label for="okno_secret_<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div>
				<input type="password" id="okno_secret_<?php echo esc_attr( $name ); ?>" name="okno_secret[<?php echo esc_attr( $name ); ?>]" value="" autocomplete="new-password"
					placeholder="<?php echo 'ok' === $status ? esc_attr__( 'Saved — leave empty to keep it', 'okno' ) : esc_attr__( 'Not set', 'okno' ); ?>">
				<?php if ( 'unreadable' === $status ) : ?>
					<span class="okno-help" style="color:var(--okno-error)"><?php esc_html_e( 'This secret can’t be read anymore: the WordPress salts have changed (host or security plugin). Enter it again.', 'okno' ); ?></span>
				<?php elseif ( 'ok' === $status ) : ?>
					<label class="okno-inline-check"><input type="checkbox" name="okno_clear_<?php echo esc_attr( $name ); ?>" value="1"> <?php esc_html_e( 'Delete this secret', 'okno' ); ?></label>
				<?php endif; ?>
				<?php if ( $help ) : ?>
					<span class="okno-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Publications
	 * =================================================================== */

	private static function render_deploys() {
		$history = Okno_Deploy_Manager::history();
		$usage   = get_option( Okno_Plugin::OPTION_USAGE, array() );
		$usage   = is_array( $usage ) ? $usage : array();
		krsort( $usage );
		$usage = array_slice( $usage, 0, 8, true );
		?>
		<div class="okno-page">
			<h2 class="okno-heading"><?php esc_html_e( 'Deployment history', 'okno' ); ?></h2>
			<div class="okno-card okno-card--flush">
				<?php if ( ! $history ) : ?>
					<div class="okno-empty">
						<?php Okno_Icons::render( 'rocket', 32 ); ?>
						<h3><?php esc_html_e( 'No deployments yet', 'okno' ); ?></h3>
						<p><?php esc_html_e( 'Every click on “Publish” in the editor shows up here with its result.', 'okno' ); ?></p>
					</div>
				<?php else : ?>
					<table class="okno-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Method', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'okno' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $record ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $record['started'] ) ); ?></td>
									<td><?php echo esc_html( $record['user_name'] ); ?></td>
									<td><?php echo esc_html( self::driver_label( $record['driver'] ) ); ?></td>
									<td>
										<span class="okno-pill okno-pill--<?php echo esc_attr( self::status_level( $record['status'] ) ); ?>"><?php echo esc_html( self::status_label( $record['status'] ) ); ?></span>
										<?php if ( $record['message'] ) : ?>
											<div class="okno-table__message"><?php echo esc_html( $record['message'] ); ?></div>
										<?php endif; ?>
										<?php if ( ! empty( $record['meta']['run_url'] ) ) : ?>
											<div class="okno-table__message"><a href="<?php echo esc_url( $record['meta']['run_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View build', 'okno' ); ?></a></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $usage ) : ?>
				<h2 class="okno-heading" style="margin-top:40px"><?php esc_html_e( 'Weekly activity', 'okno' ); ?></h2>
				<div class="okno-card okno-card--flush">
					<table class="okno-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Week', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Saves', 'okno' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Deployments', 'okno' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $usage as $week => $counts ) : ?>
								<tr>
									<td><?php echo esc_html( $week ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $counts['save'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $counts['publish'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Libellés partagés
	 * =================================================================== */

	/**
	 * Modes de mise en ligne proposés.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function drivers() {
		return array(
			'live'          => array(
				'label' => __( 'Live content', 'okno' ),
				'help'  => __( 'The site reads WordPress on every visit. Saving is enough, nothing to publish.', 'okno' ),
			),
			'build_hook'    => array(
				'label' => __( 'Build hook', 'okno' ),
				'help'  => __( 'Vercel, Netlify, Cloudflare Pages… Okno calls the rebuild URL.', 'okno' ),
			),
			'github'        => array(
				'label' => __( 'GitHub Actions', 'okno' ),
				'help'  => __( 'Triggers a workflow and tracks the build live.', 'okno' ),
			),
			'github_commit' => array(
				'label' => __( 'GitHub commit', 'okno' ),
				'help'  => __( 'For hosts that deploy on every push.', 'okno' ),
			),
			'coolify'       => array(
				'label' => __( 'Coolify', 'okno' ),
				'help'  => __( 'Coolify deployment webhook.', 'okno' ),
			),
		);
	}

	public static function driver_label( $driver ) {
		$drivers = self::drivers();
		if ( isset( $drivers[ $driver ] ) ) {
			return $drivers[ $driver ]['label'];
		}
		return '' === $driver ? __( 'Not set up', 'okno' ) : $driver;
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'okno' ),
			'building'  => __( 'Building', 'okno' ),
			'triggered' => __( 'Triggered', 'okno' ),
			'success'   => __( 'Live', 'okno' ),
			'error'     => __( 'Failed', 'okno' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function status_level( $status ) {
		if ( 'success' === $status || 'triggered' === $status ) {
			return 'ok';
		}
		if ( 'error' === $status ) {
			return 'error';
		}
		return 'accent';
	}

	/**
	 * Résultat du dernier test de connexion.
	 *
	 * @return array{ok:bool,version:string,fields:int,sections:int,at:int}|null
	 */
	public static function connection() {
		$value = get_option( self::OPTION_CONNECTION );
		return is_array( $value ) && isset( $value['at'] ) ? $value : null;
	}
}
