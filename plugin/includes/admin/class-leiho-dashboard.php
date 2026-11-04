<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vues de l'application Leiho rendues en PHP : Accueil, Démarrer, Réglages,
 * Publications. La coque (navigation, barre du haut) est dans Leiho_App.
 */
class Leiho_Dashboard {

	const OPTION_CONNECTION = 'leiho_connection';

	/**
	 * Rend une vue de l'application (Leiho_App) : home, start, settings, deploys.
	 *
	 * @param string $view Vue.
	 * @return void
	 */
	public static function render_view( $view ) {
		switch ( $view ) {
			case 'start':
				self::render_start();
				break;
			case 'settings':
				self::render_settings();
				break;
			case 'deploys':
				self::render_deploys();
				break;
			case 'ai':
				self::render_ai();
				break;
			default:
				self::render_home();
		}
	}

	/* =====================================================================
	 * Accueil
	 * =================================================================== */

	private static function render_home() {
		$settings   = Leiho_Plugin::settings();
		$configured = '' !== $settings['front_url'];
		$connection = self::connection();
		$history    = Leiho_Deploy_Manager::history();
		$last       = reset( $history );
		$week_count = Leiho_Activity::count_since( time() - WEEK_IN_SECONDS );
		$activities = Leiho_Activity::recent( 40 );
		?>
		<div class="leiho-home">
			<div class="leiho-home__main">
				<?php if ( ! $configured && current_user_can( 'manage_options' ) ) : ?>
					<div class="leiho-home__intro">
						<div>
							<h2><?php esc_html_e( 'Connect your site to Leiho', 'leiho' ); ?></h2>
							<p><?php esc_html_e( 'Enter your site’s URL, allow the editor to display it, install the bridge: four guided steps, each with a check.', 'leiho' ); ?></p>
						</div>
						<a class="leiho-btn" href="<?php echo esc_url( Leiho_App::url( 'start' ) ); ?>">
							<?php esc_html_e( 'Get started', 'leiho' ); ?>
							<?php Leiho_Icons::render( 'arrow', 14 ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="leiho-home__intro">
						<div>
							<h2><?php esc_html_e( 'Edit your site as you see it', 'leiho' ); ?></h2>
							<p><?php esc_html_e( 'Click any text or image on your site to edit it. You see the result right away, before you save.', 'leiho' ); ?></p>
						</div>
						<div class="leiho-actions" style="margin-top:0">
							<?php if ( $configured ) : ?>
								<a class="leiho-btn leiho-btn--quiet" href="<?php echo esc_url( $settings['front_url'] ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'View site', 'leiho' ); ?>
									<?php Leiho_Icons::render( 'external', 13 ); ?>
								</a>
							<?php endif; ?>
							<a class="leiho-btn" href="<?php echo esc_url( Leiho_App::url( 'editor' ) ); ?>">
								<?php esc_html_e( 'Open the editor', 'leiho' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<?php
				// Aperçu en direct de l'accueil du site, seulement quand le test de
				// connexion a réussi : le site accepte alors d'être affiché ici.
				$preview_url = '' !== $settings['preview_url'] ? $settings['preview_url'] : $settings['front_url'];
				$front_page  = (int) get_option( 'page_on_front' );
				?>
				<?php if ( $configured && $connection && ! empty( $connection['ok'] ) ) : ?>
					<section class="leiho-home__section">
						<h2 class="leiho-home__label"><?php esc_html_e( 'Your site', 'leiho' ); ?></h2>
						<a class="leiho-site-preview" href="<?php echo esc_url( Leiho_App::url( 'editor', $front_page ? array( 'post' => $front_page ) : array() ) ); ?>" aria-label="<?php esc_attr_e( 'Edit the home page in the visual editor', 'leiho' ); ?>">
							<span class="leiho-site-preview__viewport" data-leiho-site-preview>
								<iframe src="<?php echo esc_url( trailingslashit( $preview_url ) ); ?>" title="<?php esc_attr_e( 'Live preview of the site’s home page', 'leiho' ); ?>" tabindex="-1" loading="lazy" aria-hidden="true"></iframe>
							</span>
							<span class="leiho-site-preview__cta"><?php Leiho_Icons::render( 'edit', 14 ); ?> <?php esc_html_e( 'Edit this page', 'leiho' ); ?></span>
						</a>
					</section>
				<?php endif; ?>

				<section class="leiho-home__section">
					<h2 class="leiho-home__label"><?php esc_html_e( 'Recent changes', 'leiho' ); ?></h2>
					<div class="leiho-card leiho-card--flush">
						<?php if ( ! $activities ) : ?>
							<div class="leiho-empty">
								<?php Leiho_Icons::render( 'history', 24 ); ?>
								<h3><?php esc_html_e( 'No changes yet', 'leiho' ); ?></h3>
								<p><?php esc_html_e( 'Every save made in the editor shows up here: who changed which field, when, with the before and after values.', 'leiho' ); ?></p>
							</div>
						<?php else : ?>
							<ul class="leiho-log">
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
				</section>
			</div>

			<aside class="leiho-home__side">
				<section class="leiho-home__section">
					<h2 class="leiho-home__label"><?php esc_html_e( 'Publishing', 'leiho' ); ?></h2>
					<ul class="leiho-status-list">
						<li>
							<?php if ( ! $connection ) : ?>
								<span class="leiho-dot"></span>
								<span class="leiho-status-list__text"><strong><?php esc_html_e( 'Site connection', 'leiho' ); ?></strong><span><?php esc_html_e( 'Not tested yet', 'leiho' ); ?></span></span>
							<?php elseif ( $connection['ok'] ) : ?>
								<span class="leiho-dot leiho-dot--ok"></span>
								<span class="leiho-status-list__text"><strong><?php esc_html_e( 'Site connection', 'leiho' ); ?></strong><span>
									<?php esc_html_e( 'Working', 'leiho' ); ?> ·
									<?php
									/* translators: %s: relative time (e.g. 5 mins). */
									echo esc_html( sprintf( __( 'Checked %s ago', 'leiho' ), human_time_diff( $connection['at'] ) ) );
									?>
								</span></span>
							<?php else : ?>
								<span class="leiho-dot leiho-dot--error"></span>
								<span class="leiho-status-list__text"><strong><?php esc_html_e( 'Site connection', 'leiho' ); ?></strong><span><?php esc_html_e( 'Needs attention', 'leiho' ); ?> · <?php esc_html_e( 'The last test failed.', 'leiho' ); ?></span></span>
							<?php endif; ?>
						</li>
						<li>
							<span class="leiho-dot <?php echo '' === $settings['deploy_driver'] ? '' : 'leiho-dot--ok'; ?>"></span>
							<span class="leiho-status-list__text">
								<strong><?php echo esc_html( self::driver_label( $settings['deploy_driver'] ) ); ?></strong>
								<?php if ( $last ) : ?>
									<span>
										<?php
										/* translators: 1: deployment status, 2: relative time (e.g. 5 mins). */
										echo esc_html( sprintf( __( 'Last deployment: %1$s, %2$s ago', 'leiho' ), self::status_label( $last['status'] ), human_time_diff( $last['started'] ) ) );
										?>
									</span>
								<?php endif; ?>
							</span>
						</li>
						<li>
							<span class="leiho-dot leiho-dot--accent"></span>
							<span class="leiho-status-list__text"><strong><?php echo esc_html( number_format_i18n( $week_count ) ); ?></strong><span><?php esc_html_e( 'Changes this week', 'leiho' ); ?></span></span>
						</li>
					</ul>
				</section>

				<section class="leiho-home__section">
					<h2 class="leiho-home__label"><?php esc_html_e( 'Quick links', 'leiho' ); ?></h2>
					<ul class="leiho-rows">
						<?php
						$links = array(
							array( 'collections', array( 'new' => '1' ), 'plus', __( 'New content', 'leiho' ), __( 'A page, an article or a collection entry.', 'leiho' ), 'edit_posts' ),
							array( 'collections', array(), 'database', __( 'Collections', 'leiho' ), __( 'All your content, by type: search, edit, duplicate, restore.', 'leiho' ), 'edit_posts' ),
							array( 'editor', array(), 'edit', __( 'Visual editor', 'leiho' ), __( 'Edit your pages as visitors see them.', 'leiho' ), 'edit_posts' ),
							array( 'media', array(), 'image', __( 'Media', 'leiho' ), __( 'Images and files of the site.', 'leiho' ), 'upload_files' ),
						);
						foreach ( $links as $link ) :
							if ( ! current_user_can( $link[5] ) ) {
								continue;
							}
							?>
							<li>
								<a class="leiho-coll" href="<?php echo esc_url( Leiho_App::url( $link[0], $link[1] ) ); ?>" title="<?php echo esc_attr( $link[4] ); ?>">
									<span class="leiho-coll__icon<?php echo 'database' === $link[2] ? ' leiho-coll__icon--collection' : ''; ?>"><?php Leiho_Icons::render( $link[2], 15 ); ?></span>
									<span class="leiho-coll__name"><?php echo esc_html( $link[3] ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			</aside>
		</div>
		<?php
	}

	private static function render_activity( $activity ) {
		$post_id   = (int) $activity->post_id;
		$title     = $post_id ? Leiho_Plugin::title( $post_id ) : '';
		$when      = strtotime( $activity->created_at . ' UTC' );
		$open_url  = add_query_arg( 'field', rawurlencode( $activity->field_path ), Leiho_Admin::edit_url( $post_id ) );
		$initials  = self::initials( $activity->user_name );
		?>
		<li class="leiho-log__item">
			<div class="leiho-log__head">
				<div>
					<strong class="leiho-log__field" title="<?php echo esc_attr( $activity->field_path ); ?>"><?php echo esc_html( $activity->field_label ); ?></strong>
					<span class="leiho-log__meta">
						<span class="leiho-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: author, 2: content title, 3: relative time (e.g. 5 mins). */
								__( '%1$s · %2$s · %3$s ago', 'leiho' ),
								$activity->user_name ? $activity->user_name : __( 'Unknown user', 'leiho' ),
								$title ? $title : __( 'deleted content', 'leiho' ),
								$when ? human_time_diff( $when ) : '?'
							)
						);
						?>
					</span>
				</div>
				<?php if ( $post_id && get_post( $post_id ) && 'attachment' !== get_post_type( $post_id ) ) : ?>
					<a class="leiho-btn leiho-btn--quiet leiho-btn--small" href="<?php echo esc_url( $open_url ); ?>">
						<?php esc_html_e( 'Open', 'leiho' ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php if ( Leiho_Activity::TYPE_AI === $activity->field_type ) : ?>
				<p class="leiho-log__ai"><?php Leiho_Icons::render( 'sparkle', 14 ); ?> <?php echo esc_html( Leiho_Activity::ai_label( $activity->new_value ) ); ?></p>
			</li>
				<?php
				return;
			endif;
			?>
			<div class="leiho-diff">
				<div class="leiho-diff__value">
					<span class="leiho-diff__label"><?php esc_html_e( 'Before', 'leiho' ); ?></span>
					<?php self::render_value( $activity->old_value, $activity->field_type ); ?>
				</div>
				<span class="leiho-diff__arrow"><?php Leiho_Icons::render( 'arrow', 16 ); ?></span>
				<div class="leiho-diff__value leiho-diff__value--after">
					<span class="leiho-diff__label"><?php esc_html_e( 'After', 'leiho' ); ?></span>
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
			echo '<span class="leiho-diff__empty">' . esc_html__( 'empty', 'leiho' ) . '</span>';
			return;
		}

		switch ( $type ) {
			case 'image':
			case 'file':
				$url = wp_get_attachment_image_url( (int) $value, 'thumbnail' );
				if ( $url && 'image' === $type ) {
					echo '<img src="' . esc_url( $url ) . '" alt="">';
				} else {
					echo esc_html( Leiho_Plugin::title( (int) $value ) );
				}
				return;

			case 'wysiwyg':
				echo wp_kses_post( $value );
				return;

			case 'true_false':
				echo esc_html( '1' === $value ? __( 'Yes', 'leiho' ) : __( 'No', 'leiho' ) );
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
					echo esc_html( sprintf( _n( '%d item', '%d items', count( $items ), 'leiho' ), count( $items ) ) );
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
		$settings    = Leiho_Plugin::settings();
		$wp_origin   = Leiho_Admin::wp_origin();
		$has_url     = '' !== $settings['front_url'];
		$connection  = self::connection();
		$bridge_file = LEIHO_URL . 'assets/bridge/leiho-bridge.js';

		$loader = "<script>\n  if (window.self !== window.top) {\n    var s = document.createElement('script');\n    s.src = '/leiho-bridge.js';\n    s.setAttribute('data-wp-origin', '" . $wp_origin . "');\n    document.head.appendChild(s);\n  }\n</script>";
		$react  = "npm install leiho\n\n// In the root layout (Next.js: app/layout.tsx, Remix: root.tsx, Vite: App.tsx)\nimport { LeihoBridge } from 'leiho/react';\n\n<LeihoBridge wpOrigin=\"" . $wp_origin . '" />';
		$csp    = "Content-Security-Policy: frame-ancestors 'self' " . $wp_origin;

		// Version française du guide pour un wp-admin en français, anglaise sinon.
		$instructions_file = LEIHO_DIR . 'docs/agent-instructions.md';
		if ( 0 === strpos( determine_locale(), 'fr' ) && file_exists( LEIHO_DIR . 'docs/agent-instructions.fr.md' ) ) {
			$instructions_file = LEIHO_DIR . 'docs/agent-instructions.fr.md';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local du plugin.
		$instructions = file_exists( $instructions_file ) ? file_get_contents( $instructions_file ) : '';
		$instructions = str_replace( 'https://ADRESSE-DU-WORDPRESS', $wp_origin, $instructions );

		$intro        = __( 'We’re going to make this site editable with Leiho, the visual editor for WordPress. Leiho has precise instructions to follow throughout the project; you’ll find them below. Read them in full before writing any code, and follow them for every component.', 'leiho' );
		$prompt_exist = $intro . "\n\n" . __( 'The site already exists. Install the bridge, then annotate every piece of displayed content with its matching WordPress field, following the instructions. Finish with the checklist.', 'leiho' ) . "\n\n" . __( 'Site repository:', 'leiho' ) . "\n\n---\n\n" . $instructions;
		$prompt_new   = $intro . "\n\n" . __( 'We’re starting from scratch. Build the site and its ACF content model, following the instructions from the very first component. Finish with the checklist.', 'leiho' ) . "\n\n" . __( 'Site idea or brief:', 'leiho' ) . "\n\n---\n\n" . $instructions;
		?>
		<div class="leiho-page leiho-page--narrow">
			<span class="leiho-eyebrow"><?php esc_html_e( 'Connect a site', 'leiho' ); ?></span>
			<h2 style="margin:0 0 8px;font-size:22px;color:var(--leiho-ink)"><?php esc_html_e( 'Make your site editable', 'leiho' ); ?></h2>
			<p class="leiho-lead"><?php esc_html_e( 'Leiho works with any site that reads its content from WordPress: React, Next.js, Remix, Vite, Astro, Nuxt, SvelteKit or plain HTML. Four steps, each one verifiable.', 'leiho' ); ?></p>

			<ol class="leiho-steps">
				<li class="leiho-step <?php echo $has_url ? 'leiho-step--done' : 'leiho-step--current'; ?>">
					<h3>
						<?php esc_html_e( 'Site URL', 'leiho' ); ?>
						<?php if ( $has_url ) : ?>
							<span class="leiho-pill leiho-pill--ok"><?php echo esc_html( $settings['front_url'] ); ?></span>
						<?php endif; ?>
					</h3>
					<p><?php esc_html_e( 'The site’s public URL and, if you have one, the preview URL to show in the editor.', 'leiho' ); ?></p>
					<a class="leiho-btn <?php echo $has_url ? 'leiho-btn--quiet leiho-btn--small' : ''; ?>" href="<?php echo esc_url( Leiho_App::url( 'settings' ) ); ?>">
						<?php echo $has_url ? esc_html__( 'Edit', 'leiho' ) : esc_html__( 'Enter the URL', 'leiho' ); ?>
					</a>
				</li>

				<li class="leiho-step">
					<h3><?php esc_html_e( 'Allow the editor to display the site', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'The site must allow being displayed in wp-admin. Add this HTTP header to its responses (host settings, middleware or the framework’s headers file).', 'leiho' ); ?></p>
					<div class="leiho-code">
						<pre><?php echo esc_html( $csp ); ?></pre>
						<button type="button" data-leiho-copy="<?php echo esc_attr( $csp ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
					</div>
					<div class="leiho-actions">
						<button type="button" class="leiho-btn leiho-btn--quiet leiho-btn--small" data-leiho-check-headers <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Check headers', 'leiho' ); ?>
						</button>
					</div>
					<div class="leiho-check" data-leiho-headers-result hidden></div>
				</li>

				<li class="leiho-step">
					<h3><?php esc_html_e( 'Install the bridge', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'A small script that only loads inside the editor. Your visitors never download it.', 'leiho' ); ?></p>
					<div class="leiho-segmented" role="tablist" data-leiho-segmented>
						<button type="button" role="tab" aria-selected="true" data-panel="html"><?php esc_html_e( 'HTML · Astro · Nuxt · SvelteKit', 'leiho' ); ?></button>
						<button type="button" role="tab" aria-selected="false" data-panel="react"><?php esc_html_e( 'React · Next.js · Remix · Vite', 'leiho' ); ?></button>
					</div>
					<div data-panel-id="html">
						<p class="leiho-help"><?php esc_html_e( 'Put leiho-bridge.js in the site’s public folder, then add this code to the layout’s <head>.', 'leiho' ); ?></p>
						<div class="leiho-code">
							<pre><?php echo esc_html( $loader ); ?></pre>
							<button type="button" data-leiho-copy="<?php echo esc_attr( $loader ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
						</div>
						<div class="leiho-actions">
							<a class="leiho-btn leiho-btn--quiet leiho-btn--small" href="<?php echo esc_url( $bridge_file ); ?>" download="leiho-bridge.js"><?php esc_html_e( 'Download leiho-bridge.js', 'leiho' ); ?></a>
						</div>
					</div>
					<div data-panel-id="react" hidden>
						<p class="leiho-help"><?php esc_html_e( 'The component loads the bridge only inside the editor, through a dynamic import.', 'leiho' ); ?></p>
						<div class="leiho-code">
							<pre><?php echo esc_html( $react ); ?></pre>
							<button type="button" data-leiho-copy="<?php echo esc_attr( $react ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
						</div>
					</div>
				</li>

				<li class="leiho-step <?php echo ( $connection && $connection['ok'] ) ? 'leiho-step--done' : ''; ?>">
					<h3><?php esc_html_e( 'Test the connection', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'Leiho opens your site in the background and waits for the bridge to check in. If it responds, the editor is ready.', 'leiho' ); ?></p>
					<div class="leiho-actions">
						<button type="button" class="leiho-btn" data-leiho-test-bridge <?php disabled( ! $has_url ); ?>>
							<?php esc_html_e( 'Test the connection', 'leiho' ); ?>
						</button>
					</div>
					<div class="leiho-check <?php echo $connection ? ( $connection['ok'] ? 'leiho-check--ok' : 'leiho-check--error' ) : ''; ?>" data-leiho-bridge-result <?php echo $connection ? '' : 'hidden'; ?>>
						<?php if ( $connection ) : ?>
							<span class="leiho-dot <?php echo $connection['ok'] ? 'leiho-dot--ok' : 'leiho-dot--error'; ?>"></span>
							<span>
								<?php
								echo esc_html(
									$connection['ok']
										/* translators: 1: bridge version, 2: number of fields, 3: relative time (e.g. 5 mins). */
										? sprintf( __( 'Bridge v%1$s detected, %2$d annotated field(s) on the home page. Checked %3$s ago.', 'leiho' ), $connection['version'], $connection['fields'], human_time_diff( $connection['at'] ) )
										/* translators: %s: relative time (e.g. 5 mins). */
										: sprintf( __( 'The bridge didn’t respond to the last test, %s ago.', 'leiho' ), human_time_diff( $connection['at'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			</ol>

			<h2 class="leiho-heading" style="margin-top:48px"><?php esc_html_e( 'Annotate your content', 'leiho' ); ?></h2>
			<div class="leiho-card">
				<p><?php esc_html_e( 'Every element that displays a WordPress field carries that field’s name. The bridge uses it to know what to update, and the editor to know which field to open on click.', 'leiho' ); ?></p>
				<div class="leiho-code">
					<pre><?php echo esc_html( "<section data-wp-post=\"12\" data-leiho-section=\"Hero\">\n  <h1 data-wp-field=\"hero_title\">…</h1>\n  <img data-wp-field=\"hero_image\" src=\"…\" alt=\"…\">\n  <article data-wp-field=\"cards.0.title\">…</article>\n</section>" ); ?></pre>
				</div>
				<p class="leiho-help" style="margin-top:12px"><?php esc_html_e( 'All the annotations, rules and the checklist are in the guide below.', 'leiho' ); ?></p>
			</div>

			<h2 class="leiho-heading" style="margin-top:48px"><?php esc_html_e( 'With an AI agent', 'leiho' ); ?></h2>
			<div class="leiho-card">
				<span class="leiho-eyebrow"><?php esc_html_e( 'Claude, Codex, Cursor…', 'leiho' ); ?></span>
				<h2><?php esc_html_e( 'Let the agent do the integration', 'leiho' ); ?></h2>
				<p><?php esc_html_e( 'Leiho gives your agent a precise contract: where to load the bridge, how to name and annotate fields, which mistakes to avoid, what to check before shipping. Copy the right prompt, then add your repository or brief.', 'leiho' ); ?></p>

				<div class="leiho-prompts">
					<div class="leiho-prompt">
						<h3><?php esc_html_e( 'Integrate an existing site', 'leiho' ); ?></h3>
						<p><?php esc_html_e( 'The site already reads its content from WordPress. The agent installs the bridge and annotates all the content.', 'leiho' ); ?></p>
						<button type="button" class="leiho-btn leiho-btn--small" data-leiho-copy-from="leiho-prompt-existing"><?php esc_html_e( 'Copy prompt', 'leiho' ); ?></button>
						<textarea id="leiho-prompt-existing" class="leiho-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_exist ); ?></textarea>
					</div>
					<div class="leiho-prompt">
						<h3><?php esc_html_e( 'Build a new site', 'leiho' ); ?></h3>
						<p><?php esc_html_e( 'The agent designs the site and its ACF content model, editable with Leiho from the very first component.', 'leiho' ); ?></p>
						<button type="button" class="leiho-btn leiho-btn--small" data-leiho-copy-from="leiho-prompt-new"><?php esc_html_e( 'Copy prompt', 'leiho' ); ?></button>
						<textarea id="leiho-prompt-new" class="leiho-copy-source" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $prompt_new ); ?></textarea>
					</div>
				</div>

				<details style="margin-top:20px">
					<summary style="cursor:pointer;color:var(--leiho-ink);font-weight:600"><?php esc_html_e( 'Read the full instructions', 'leiho' ); ?></summary>
					<textarea class="leiho-instructions" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Agent instructions', 'leiho' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
				</details>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Réglages
	 * =================================================================== */

	private static function render_settings() {
		$settings     = Leiho_Plugin::settings();
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $public_types['attachment'] );
		$preflight    = Leiho_Github_Preflight::consume();
		$drivers      = self::drivers();
		?>
		<div class="leiho-page">

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau d'affichage. ?>
			<?php if ( isset( $_GET['leiho_saved'] ) ) : ?>
				<div class="leiho-notice leiho-notice--ok" role="status">
					<span class="leiho-dot leiho-dot--ok"></span>
					<strong><?php esc_html_e( 'Settings saved.', 'leiho' ); ?></strong>
				</div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau d'affichage. ?>
			<?php if ( isset( $_GET['leiho_cache_cleared'] ) ) : ?>
				<div class="leiho-notice leiho-notice--ok" role="status">
					<span class="leiho-dot leiho-dot--ok"></span>
					<strong><?php esc_html_e( 'Content cache cleared. Page caches were asked to purge the content API.', 'leiho' ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( $preflight ) : ?>
				<?php
				$levels = wp_list_pluck( $preflight, 'level' );
				$level  = in_array( 'error', $levels, true ) ? 'error' : ( in_array( 'warning', $levels, true ) ? 'warn' : 'ok' );
				?>
				<div class="leiho-notice leiho-notice--<?php echo esc_attr( $level ); ?>" role="status">
					<span class="leiho-dot leiho-dot--<?php echo esc_attr( $level ); ?>"></span>
					<div>
						<strong><?php esc_html_e( 'GitHub configuration check', 'leiho' ); ?></strong>
						<ul>
							<?php foreach ( $preflight as $item ) : ?>
								<?php $item_level = 'warning' === $item['level'] ? 'warn' : $item['level']; ?>
								<li><span class="leiho-pill leiho-pill--<?php echo esc_attr( $item_level ); ?>"><?php echo esc_html( 'ok' === $item['level'] ? __( 'OK', 'leiho' ) : ( 'error' === $item['level'] ? __( 'Error', 'leiho' ) : __( 'Warning', 'leiho' ) ) ); ?></span> <?php echo esc_html( $item['message'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-leiho-settings>
				<input type="hidden" name="action" value="leiho_save_settings">
				<?php wp_nonce_field( 'leiho_save_settings' ); ?>

				<section class="leiho-form-section">
					<h2><?php esc_html_e( 'Your site', 'leiho' ); ?></h2>
					<p class="leiho-help"><?php esc_html_e( 'The site your clients edit, as visitors see it.', 'leiho' ); ?></p>

					<div class="leiho-row">
						<label for="leiho_front_url"><?php esc_html_e( 'Site URL', 'leiho' ); ?></label>
						<div>
							<input type="url" id="leiho_front_url" name="leiho_settings[front_url]" value="<?php echo esc_attr( $settings['front_url'] ); ?>" placeholder="https://www.example.com">
						</div>
					</div>
					<div class="leiho-row">
						<label for="leiho_preview_url"><?php esc_html_e( 'Preview URL', 'leiho' ); ?></label>
						<div>
							<input type="url" id="leiho_preview_url" name="leiho_settings[preview_url]" value="<?php echo esc_attr( $settings['preview_url'] ); ?>" placeholder="https://preview.example.com">
							<span class="leiho-help"><?php esc_html_e( 'Optional. Shown in the editor instead of the public site: useful when the public site is generated at build time and an up-to-date version is served elsewhere.', 'leiho' ); ?></span>
						</div>
					</div>
				</section>

				<section class="leiho-form-section">
					<h2><?php esc_html_e( 'Editable content', 'leiho' ); ?></h2>
					<p class="leiho-help"><?php esc_html_e( 'The content types offered in the editor, and the URL of each one on the site.', 'leiho' ); ?></p>

					<div class="leiho-row">
						<span class="leiho-row__label"><?php esc_html_e( 'Content types', 'leiho' ); ?></span>
						<div class="leiho-checks">
							<?php foreach ( $public_types as $type ) : ?>
								<label>
									<input type="checkbox" name="leiho_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="leiho-row">
						<span class="leiho-row__label"><?php esc_html_e( 'URLs on the site', 'leiho' ); ?></span>
						<div>
							<div class="leiho-patterns">
								<?php foreach ( $public_types as $type ) : ?>
									<div>
										<code><?php echo esc_html( $type->name ); ?></code>
										<input type="text" name="leiho_settings[url_patterns][<?php echo esc_attr( $type->name ); ?>]" value="<?php echo esc_attr( isset( $settings['url_patterns'][ $type->name ] ) ? $settings['url_patterns'][ $type->name ] : '' ); ?>" placeholder="/{slug}" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post type name. */ __( 'URL pattern for %s', 'leiho' ), $type->labels->name ) ); ?>">
									</div>
								<?php endforeach; ?>
							</div>
							<span class="leiho-help"><?php esc_html_e( 'Available variables: {slug}, {id}. Empty = /{slug}. The home page always points to /.', 'leiho' ); ?></span>
						</div>
					</div>

					<div class="leiho-row">
						<label for="leiho_path_meta_key"><?php esc_html_e( 'Field holding the URL', 'leiho' ); ?></label>
						<div>
							<input type="text" id="leiho_path_meta_key" name="leiho_settings[path_meta_key]" value="<?php echo esc_attr( $settings['path_meta_key'] ); ?>" placeholder="page_path">
							<span class="leiho-help"><?php esc_html_e( 'Optional. Name of an ACF field or meta key that holds each item’s actual URL (for example /services/audit). Takes priority over the patterns above.', 'leiho' ); ?></span>
						</div>
					</div>
				</section>

				<section class="leiho-form-section">
					<h2><?php esc_html_e( 'Publishing', 'leiho' ); ?></h2>
					<p class="leiho-help"><?php esc_html_e( 'What happens when a client clicks “Publish”.', 'leiho' ); ?></p>

					<div class="leiho-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Publishing mode', 'leiho' ); ?>">
						<?php foreach ( $drivers as $value => $driver ) : ?>
							<label class="leiho-choice">
								<input type="radio" name="leiho_settings[deploy_driver]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['deploy_driver'], $value ); ?> data-leiho-driver>
								<strong><?php echo esc_html( $driver['label'] ); ?></strong>
								<span><?php echo esc_html( $driver['help'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="leiho-driver-fields" data-leiho-driver-fields="build_hook">
						<div class="leiho-row">
							<label for="leiho_build_hook_url"><?php esc_html_e( 'Build hook URL', 'leiho' ); ?></label>
							<div>
								<input type="url" id="leiho_build_hook_url" name="leiho_settings[build_hook_url]" value="<?php echo esc_attr( $settings['build_hook_url'] ); ?>" placeholder="https://api.netlify.com/build_hooks/…">
							</div>
						</div>
					</div>

					<div class="leiho-driver-fields" data-leiho-driver-fields="coolify">
						<div class="leiho-row">
							<label for="leiho_coolify_url"><?php esc_html_e( 'Coolify webhook URL', 'leiho' ); ?></label>
							<div><input type="url" id="leiho_coolify_url" name="leiho_settings[coolify_url]" value="<?php echo esc_attr( $settings['coolify_url'] ); ?>"></div>
						</div>
						<?php self::secret_row( 'coolify_token', __( 'Coolify token', 'leiho' ) ); ?>
					</div>

					<div class="leiho-driver-fields" data-leiho-driver-fields="build_hook coolify github_commit">
						<div class="leiho-row">
							<label for="leiho_build_hook_eta"><?php esc_html_e( 'Build time', 'leiho' ); ?></label>
							<div>
								<input type="number" id="leiho_build_hook_eta" name="leiho_settings[build_hook_eta]" value="<?php echo esc_attr( $settings['build_hook_eta'] ); ?>" min="1" max="60"> <span class="leiho-muted"><?php esc_html_e( 'minutes', 'leiho' ); ?></span>
								<span class="leiho-help"><?php esc_html_e( 'Shown to the client after “Publish”, since the build can’t be tracked live.', 'leiho' ); ?></span>
							</div>
						</div>
					</div>

					<div class="leiho-driver-fields" data-leiho-driver-fields="github github_commit">
						<div class="leiho-row">
							<label for="leiho_gh_repo"><?php esc_html_e( 'GitHub repository', 'leiho' ); ?></label>
							<div><input type="text" id="leiho_gh_repo" name="leiho_settings[gh_repo]" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" placeholder="organization/site"></div>
						</div>
						<div class="leiho-driver-fields" data-leiho-driver-fields="github">
							<div class="leiho-row">
								<label for="leiho_gh_workflow"><?php esc_html_e( 'Workflow file', 'leiho' ); ?></label>
								<div><input type="text" id="leiho_gh_workflow" name="leiho_settings[gh_workflow]" value="<?php echo esc_attr( $settings['gh_workflow'] ); ?>" placeholder="deploy.yml"></div>
							</div>
						</div>
						<div class="leiho-row">
							<label for="leiho_gh_branch"><?php esc_html_e( 'Branch', 'leiho' ); ?></label>
							<div><input type="text" id="leiho_gh_branch" name="leiho_settings[gh_branch]" value="<?php echo esc_attr( $settings['gh_branch'] ); ?>" placeholder="main"></div>
						</div>
						<?php self::secret_row( 'gh_pat', __( 'GitHub token', 'leiho' ), __( 'Fine-grained token limited to this repository only: “Actions: Read and write” for GitHub Actions, “Contents: Read and write” for the deployment commit. Don’t use a classic token: it grants access to all your repositories.', 'leiho' ) ); ?>
					</div>

					<div class="leiho-driver-fields" data-leiho-driver-fields="build_hook coolify github github_commit">
						<div class="leiho-row">
							<span class="leiho-row__label"><?php esc_html_e( 'Automatic publishing', 'leiho' ); ?></span>
							<div>
								<label class="leiho-inline-check"><input type="checkbox" name="leiho_settings[auto_deploy]" value="1" <?php checked( $settings['auto_deploy'] ); ?>> <?php esc_html_e( 'Publish on its own after each change', 'leiho' ); ?></label>
								<span class="leiho-help">
									<?php
									printf(
										/* translators: %s: number input for the delay in seconds. */
										esc_html__( 'Publishes %s seconds after the last change, so a series of edits gives a single deployment. Changes made in wp-admin count too.', 'leiho' ),
										'<input type="number" name="leiho_settings[auto_deploy_delay]" value="' . esc_attr( $settings['auto_deploy_delay'] ) . '" min="15" style="width:5em" aria-label="' . esc_attr__( 'Delay before automatic publishing, in seconds', 'leiho' ) . '">'
									);
									?>
								</span>
							</div>
						</div>
						<div class="leiho-row">
							<label for="leiho_min_interval"><?php esc_html_e( 'Delay between deployments', 'leiho' ); ?></label>
							<div>
								<input type="number" id="leiho_min_interval" name="leiho_settings[min_deploy_interval]" value="<?php echo esc_attr( $settings['min_deploy_interval'] ); ?>" min="0"> <span class="leiho-muted"><?php esc_html_e( 'seconds', 'leiho' ); ?></span>
							</div>
						</div>
					</div>
				</section>

				<section class="leiho-form-section">
					<h2><?php esc_html_e( 'Editors', 'leiho' ); ?></h2>
					<p class="leiho-help"><?php esc_html_e( 'Leiho is the whole back office for the people who edit the site.', 'leiho' ); ?></p>
					<div class="leiho-row">
						<span class="leiho-row__label"><?php esc_html_e( 'After logging in', 'leiho' ); ?></span>
						<div>
							<input type="hidden" name="leiho_settings[editor_landing]" value="0">
							<label class="leiho-inline-check"><input type="checkbox" name="leiho_settings[editor_landing]" value="1" <?php checked( ! empty( $settings['editor_landing'] ) ); ?>> <?php esc_html_e( 'Open Leiho for users who aren’t administrators', 'leiho' ); ?></label>
							<span class="leiho-help"><?php esc_html_e( 'Editors and authors land in Leiho instead of the WordPress dashboard. Administrators keep the usual dashboard and reach Leiho from the menu.', 'leiho' ); ?></span>
						</div>
					</div>
				</section>

				<?php self::render_content_api_settings( $settings ); ?>

				<div class="leiho-savebar">
					<span class="leiho-muted"><?php esc_html_e( 'Secrets are encrypted in the database with a key derived from the WordPress salts.', 'leiho' ); ?></span>
					<div class="leiho-actions" style="margin:0">
						<?php if ( in_array( $settings['deploy_driver'], array( 'github', 'github_commit' ), true ) ) : ?>
							<button type="submit" class="leiho-btn leiho-btn--quiet" name="leiho_test" value="1"><?php esc_html_e( 'Save and test GitHub', 'leiho' ); ?></button>
						<?php endif; ?>
						<button type="submit" class="leiho-btn"><?php esc_html_e( 'Save', 'leiho' ); ?></button>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Contexte de marque : ce que l'IA doit savoir pour écrire à la place de
	 * la marque, et l'état du fournisseur d'IA.
	 *
	 * @return void
	 */
	private static function render_brand_settings() {
		$brand = Leiho_AI_Brand::get();
		$lines = static function ( $items ) {
			return implode( "\n", (array) $items );
		};
		$fields = array(
			'prefer'    => array( __( 'Words to prefer', 'leiho' ), __( 'One per line: your terms, how you name your offer.', 'leiho' ) ),
			'avoid'     => array( __( 'Words to avoid', 'leiho' ), __( 'One per line: jargon, competitors’ terms, overused words.', 'leiho' ) ),
			'forbidden' => array( __( 'Forbidden claims', 'leiho' ), __( 'One per line. Never written by the AI, and flagged in every proposal before you accept it. Matched without case or accents, at the start of a word (“guarantee” also flags “guaranteed”, “4 h” also flags “4h”); write /pattern/i for a regular expression.', 'leiho' ) ),
			'facts'     => array( __( 'Mandatory facts', 'leiho' ), __( 'One per line: phone number, legal mentions, figures that must stay exact.', 'leiho' ) ),
		);
		?>
		<section class="leiho-form-section" id="leiho-brand" data-leiho-brand>
			<h2><?php esc_html_e( 'Brand context', 'leiho' ); ?></h2>
			<p class="leiho-help"><?php esc_html_e( 'What the AI needs to know to write like you. It’s sent with every AI request, together with the entry being edited.', 'leiho' ); ?></p>

			<div class="leiho-row">
				<label for="leiho_brand_name"><?php esc_html_e( 'Brand name', 'leiho' ); ?></label>
				<div><input type="text" id="leiho_brand_name" name="leiho_brand[name]" value="<?php echo esc_attr( $brand['name'] ); ?>" data-brand-key="name"></div>
			</div>
			<div class="leiho-row">
				<label for="leiho_brand_offer"><?php esc_html_e( 'Activity and offer', 'leiho' ); ?></label>
				<div>
					<textarea id="leiho_brand_offer" name="leiho_brand[offer]" rows="3" data-brand-key="offer"><?php echo esc_textarea( $brand['offer'] ); ?></textarea>
					<span class="leiho-help"><?php esc_html_e( 'What you do and sell, in a few sentences.', 'leiho' ); ?></span>
				</div>
			</div>
			<div class="leiho-row">
				<label for="leiho_brand_audience"><?php esc_html_e( 'Audience', 'leiho' ); ?></label>
				<div><textarea id="leiho_brand_audience" name="leiho_brand[audience]" rows="2" data-brand-key="audience"><?php echo esc_textarea( $brand['audience'] ); ?></textarea></div>
			</div>
			<div class="leiho-row">
				<label for="leiho_brand_tone"><?php esc_html_e( 'Tone of voice', 'leiho' ); ?></label>
				<div>
					<textarea id="leiho_brand_tone" name="leiho_brand[tone]" rows="2" data-brand-key="tone" placeholder="<?php esc_attr_e( 'Direct, expert, warm; short sentences; “vous”.', 'leiho' ); ?>"><?php echo esc_textarea( $brand['tone'] ); ?></textarea>
				</div>
			</div>
			<div class="leiho-row">
				<label for="leiho_brand_language"><?php esc_html_e( 'Language', 'leiho' ); ?></label>
				<div>
					<input type="text" id="leiho_brand_language" name="leiho_brand[language]" value="<?php echo esc_attr( $brand['language'] ); ?>" placeholder="<?php esc_attr_e( 'French (France)', 'leiho' ); ?>" data-brand-key="language">
					<span class="leiho-help"><?php esc_html_e( 'Language of the texts written by the AI. Empty: the language of the existing content.', 'leiho' ); ?></span>
				</div>
			</div>
			<?php foreach ( $fields as $key => $field ) : ?>
				<div class="leiho-row">
					<label for="leiho_brand_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label>
					<div>
						<textarea id="leiho_brand_<?php echo esc_attr( $key ); ?>" name="leiho_brand[<?php echo esc_attr( $key ); ?>]" rows="<?php echo 'forbidden' === $key ? 5 : 3; ?>" data-brand-key="<?php echo esc_attr( $key ); ?>" data-brand-list><?php echo esc_textarea( $lines( $brand[ $key ] ) ); ?></textarea>
						<span class="leiho-help"><?php echo esc_html( $field[1] ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Sample texts', 'leiho' ); ?></span>
				<div class="leiho-brand-samples">
					<?php for ( $i = 0; $i < Leiho_AI_Brand::SAMPLES; $i++ ) : ?>
						<textarea name="leiho_brand[samples][]" rows="3" data-brand-key="samples" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: sample number. */ __( 'Sample text %d', 'leiho' ), $i + 1 ) ); ?>"><?php echo esc_textarea( isset( $brand['samples'][ $i ] ) ? $brand['samples'][ $i ] : '' ); ?></textarea>
					<?php endfor; ?>
					<span class="leiho-help"><?php esc_html_e( 'Up to three texts written in your voice (a page intro, a product description…). The AI takes their style, not their content.', 'leiho' ); ?></span>
				</div>
			</div>
			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Share', 'leiho' ); ?></span>
				<div class="leiho-brand-tools">
					<button type="button" class="leiho-btn leiho-btn--quiet" data-leiho-brand-export><?php esc_html_e( 'Export (JSON)', 'leiho' ); ?></button>
					<button type="button" class="leiho-btn leiho-btn--quiet" data-leiho-brand-import><?php esc_html_e( 'Import…', 'leiho' ); ?></button>
					<span class="leiho-help"><?php esc_html_e( 'Reuse the same brand context on another site. Importing fills the fields above: check them, then save.', 'leiho' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	/* =====================================================================
	 * IA
	 * =================================================================== */

	/**
	 * Écran IA : fournisseur, contexte de marque, contexte de chaque type de
	 * contenu, actions récentes, outils offerts aux agents.
	 *
	 * @return void
	 */
	private static function render_ai() {
		$status = Leiho_AI::status();
		$types  = array_filter( Leiho_Content::post_types(), 'post_type_exists' );
		?>
		<div class="leiho-page">

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple drapeau d'affichage. ?>
			<?php if ( isset( $_GET['leiho_saved'] ) ) : ?>
				<div class="leiho-notice leiho-notice--ok" role="status">
					<span class="leiho-dot leiho-dot--ok"></span>
					<strong><?php esc_html_e( 'AI settings saved.', 'leiho' ); ?></strong>
				</div>
			<?php endif; ?>

			<section class="leiho-form-section" id="leiho-ai-provider">
				<h2><?php esc_html_e( 'AI provider', 'leiho' ); ?></h2>
				<p class="leiho-help"><?php esc_html_e( 'Leiho writes with the AI provider set up in WordPress. It stores no API key and picks no provider.', 'leiho' ); ?></p>
				<div class="leiho-row">
					<span class="leiho-row__label"><?php esc_html_e( 'Status', 'leiho' ); ?></span>
					<div>
						<?php if ( ! $status['client'] ) : ?>
							<span class="leiho-pill leiho-pill--warn"><?php esc_html_e( 'Unavailable', 'leiho' ); ?></span>
							<span class="leiho-help"><?php esc_html_e( 'AI in Leiho uses the AI client of WordPress 7.0 and later. Update WordPress to use it.', 'leiho' ); ?></span>
						<?php elseif ( ! $status['text'] ) : ?>
							<span class="leiho-pill leiho-pill--warn"><?php esc_html_e( 'Not configured', 'leiho' ); ?></span>
							<span class="leiho-help"><?php esc_html_e( 'No AI provider is set up for text generation yet. Leiho never stores API keys: it uses the provider configured in WordPress.', 'leiho' ); ?></span>
							<a class="leiho-btn leiho-btn--quiet" href="<?php echo esc_url( Leiho_AI::connectors_url() ); ?>"><?php Leiho_Icons::render( 'sparkle', 14 ); ?> <?php esc_html_e( 'Set up an AI provider', 'leiho' ); ?></a>
						<?php else : ?>
							<span class="leiho-pill leiho-pill--ok"><?php esc_html_e( 'Ready', 'leiho' ); ?></span>
							<span class="leiho-help">
								<?php
								echo esc_html( $status['image'] ? __( 'Text generation and image descriptions (alternative text) are available.', 'leiho' ) : __( 'Text generation is available. The provider can’t read images, so alternative text isn’t offered.', 'leiho' ) );
								?>
								<a href="<?php echo esc_url( Leiho_AI::connectors_url() ); ?>"><?php esc_html_e( 'Settings → Connectors', 'leiho' ); ?></a>
							</span>
						<?php endif; ?>
						<span class="leiho-help leiho-ai-privacy"><?php esc_html_e( 'Privacy: when someone uses an AI action, the brand context, the text of the field and the other fields of the entry (or the image, for alternative text) are sent to the provider configured in Settings → Connectors, under its terms. Leiho keeps a line in the change log (who, which field, which action), never the prompt.', 'leiho' ); ?></span>
					</div>
				</div>

			</section>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leiho_save_ai">
				<?php wp_nonce_field( 'leiho_save_ai' ); ?>

				<?php self::render_brand_settings(); ?>

				<section class="leiho-form-section" id="leiho-ai-types">
					<h2><?php esc_html_e( 'Content types', 'leiho' ); ?></h2>
					<p class="leiho-help"><?php esc_html_e( 'What each type of content is for, and who reads it. Sent with the brand context when the AI writes an entry of that type.', 'leiho' ); ?></p>
					<?php if ( ! $types ) : ?>
						<p class="leiho-muted"><?php esc_html_e( 'No content type is edited in Leiho yet. Choose them in Settings.', 'leiho' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $types as $type ) : ?>
						<?php $context = Leiho_AI_Brand::collection( $type ); ?>
						<div class="leiho-row">
							<span class="leiho-row__label"><?php echo esc_html( $context['label'] ); ?></span>
							<div class="leiho-ai-type">
								<textarea name="leiho_ai_types[<?php echo esc_attr( $type ); ?>][purpose]" rows="2" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: content type name. */ __( 'Purpose of %s', 'leiho' ), $context['label'] ) ); ?>" placeholder="<?php esc_attr_e( 'Purpose: what these entries are for', 'leiho' ); ?>"><?php echo esc_textarea( $context['purpose'] ); ?></textarea>
								<textarea name="leiho_ai_types[<?php echo esc_attr( $type ); ?>][audience]" rows="2" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: content type name. */ __( 'Readers of %s', 'leiho' ), $context['label'] ) ); ?>" placeholder="<?php esc_attr_e( 'Readers: who they are written for', 'leiho' ); ?>"><?php echo esc_textarea( $context['audience'] ); ?></textarea>
							</div>
						</div>
					<?php endforeach; ?>
				</section>

				<div class="leiho-savebar">
					<span class="leiho-muted"><?php esc_html_e( 'Nothing written by the AI is saved until someone accepts it in the editor.', 'leiho' ); ?></span>
					<button type="submit" class="leiho-btn"><?php esc_html_e( 'Save', 'leiho' ); ?></button>
				</div>
			</form>

			<?php $actions = Leiho_Activity::recent_ai( 10 ); ?>
			<section class="leiho-form-section" id="leiho-ai-log">
				<h2><?php esc_html_e( 'Recent AI actions', 'leiho' ); ?></h2>
				<?php if ( ! $actions ) : ?>
					<p class="leiho-muted"><?php esc_html_e( 'No AI action yet. Each one is logged here: who, which field, which action. Prompts and texts are never kept.', 'leiho' ); ?></p>
				<?php else : ?>
					<ul class="leiho-log">
						<?php
						foreach ( $actions as $activity ) {
							if ( current_user_can( 'edit_post', (int) $activity->post_id ) ) {
								self::render_activity( $activity );
							}
						}
						?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="leiho-form-section" id="leiho-ai-agents">
				<h2><?php esc_html_e( 'AI agents', 'leiho' ); ?></h2>
				<?php if ( function_exists( 'wp_register_ability' ) ) : ?>
					<p class="leiho-help"><?php esc_html_e( 'External agents (through the WordPress MCP adapter, for example) can use these abilities, with the rights of the user they connect as. They never publish.', 'leiho' ); ?></p>
					<ul class="leiho-ai-abilities">
						<?php foreach ( Leiho_AI_Abilities::definitions() as $name => $ability ) : ?>
							<li><code><?php echo esc_html( $name ); ?></code> <span><?php echo esc_html( $ability['label'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="leiho-muted"><?php esc_html_e( 'Leiho offers abilities to external AI agents from WordPress 6.9 (Abilities API).', 'leiho' ); ?></p>
				<?php endif; ?>
			</section>
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
		$token = 'ok' === Leiho_Secrets::status( Leiho_Content::SECRET_TOKEN ) ? (string) Leiho_Secrets::get( Leiho_Content::SECRET_TOKEN ) : '';
		?>
		<section class="leiho-form-section">
			<h2><?php esc_html_e( 'Content API', 'leiho' ); ?></h2>
			<p class="leiho-help"><?php esc_html_e( 'Your front end reads its content here, in a single request at build time. WordPress stays the only source of truth.', 'leiho' ); ?></p>

			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Endpoints', 'leiho' ); ?></span>
				<div>
					<code><?php echo esc_html( rest_url( Leiho_Rest::NS . '/export' ) ); ?></code>
					<span class="leiho-help"><?php esc_html_e( 'All published content, cached and refreshed on every change. An unchanged build gets a 304.', 'leiho' ); ?></span>
					<code><?php echo esc_html( rest_url( Leiho_Rest::NS . '/types' ) ); ?></code>
					<span class="leiho-help"><?php esc_html_e( 'TypeScript types of that content, generated from your fields. In the front-end project: npx leiho types.', 'leiho' ); ?></span>
				</div>
			</div>

			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Access', 'leiho' ); ?></span>
				<div class="leiho-checks">
					<label><input type="radio" name="leiho_settings[content_access]" value="public" <?php checked( $settings['content_access'], 'public' ); ?>> <?php esc_html_e( 'Public: published content only, as on the site', 'leiho' ); ?></label>
					<label><input type="radio" name="leiho_settings[content_access]" value="token" <?php checked( $settings['content_access'], 'token' ); ?>> <?php esc_html_e( 'Restricted: the front end sends a token', 'leiho' ); ?></label>
				</div>
			</div>

			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Cache', 'leiho' ); ?></span>
				<div>
					<label class="leiho-inline-check"><input type="checkbox" name="leiho_settings[content_cache]" value="1" <?php checked( ! empty( $settings['content_cache'] ) ); ?>> <?php esc_html_e( 'Keep API responses in cache until the content changes', 'leiho' ); ?></label>
					<?php $backend = Leiho_Cache::backend(); ?>
					<span class="leiho-help">
						<?php
						if ( '' !== $backend ) {
							/* translators: %s: object cache name (Redis, Memcached…). */
							echo esc_html( sprintf( __( 'Persistent object cache detected: %s. Leiho stores the export, items and types there.', 'leiho' ), $backend ) );
						} else {
							esc_html_e( 'No persistent object cache: Leiho keeps its own copy of the export.', 'leiho' );
						}
						?>
					</span>
					<span class="leiho-help"><?php esc_html_e( 'Responses carry an ETag, and page caches (LiteSpeed, WP Rocket, W3 Total Cache…) are purged for the API whenever content changes.', 'leiho' ); ?></span>
					<a class="leiho-btn leiho-btn--quiet" href="<?php echo esc_url( Leiho_Cache::clear_url() ); ?>"><?php esc_html_e( 'Clear the content cache', 'leiho' ); ?></a>
				</div>
			</div>

			<?php if ( '' !== $token ) : ?>
				<div class="leiho-row">
					<label for="leiho_content_token"><?php esc_html_e( 'Content token', 'leiho' ); ?></label>
					<div>
						<input type="text" id="leiho_content_token" value="<?php echo esc_attr( $token ); ?>" readonly onfocus="this.select()">
						<label class="leiho-inline-check"><input type="checkbox" name="leiho_regen_content_token" value="1"> <?php esc_html_e( 'Generate a new token (the current one stops working)', 'leiho' ); ?></label>
						<span class="leiho-help"><?php esc_html_e( 'Set it as LEIHO_TOKEN in the front end’s build environment. Sent in the X-Leiho-Token header.', 'leiho' ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</section>

		<section class="leiho-form-section">
			<h2><?php esc_html_e( 'WordPress as a back office', 'leiho' ); ?></h2>
			<p class="leiho-help"><?php esc_html_e( 'Your visitors use the front end. These options keep WordPress out of their way and out of search engines. wp-admin, login, the content API and draft previews are not affected.', 'leiho' ); ?></p>

			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Protection', 'leiho' ); ?></span>
				<div class="leiho-checks">
					<label><input type="checkbox" name="leiho_settings[headless_noindex]" value="1" <?php checked( $settings['headless_noindex'] ); ?>> <?php esc_html_e( 'Keep WordPress out of search engines (noindex, robots.txt, no sitemap)', 'leiho' ); ?></label>
					<label><input type="checkbox" name="leiho_settings[headless_redirect]" value="1" <?php checked( $settings['headless_redirect'] ); ?>> <?php esc_html_e( 'Redirect WordPress pages to the same page on the site', 'leiho' ); ?></label>
					<label><input type="checkbox" name="leiho_settings[headless_lockdown]" value="1" <?php checked( $settings['headless_lockdown'] ); ?>> <?php esc_html_e( 'Lock down WordPress (XML-RPC off, user list hidden from visitors, version hidden)', 'leiho' ); ?></label>
				</div>
			</div>
		</section>
		<?php
	}

	private static function secret_row( $name, $label, $help = '' ) {
		$status = Leiho_Secrets::status( $name );
		?>
		<div class="leiho-row">
			<label for="leiho_secret_<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<div>
				<input type="password" id="leiho_secret_<?php echo esc_attr( $name ); ?>" name="leiho_secret[<?php echo esc_attr( $name ); ?>]" value="" autocomplete="new-password"
					placeholder="<?php echo 'ok' === $status ? esc_attr__( 'Saved — leave empty to keep it', 'leiho' ) : esc_attr__( 'Not set', 'leiho' ); ?>">
				<?php if ( 'unreadable' === $status ) : ?>
					<span class="leiho-help" style="color:var(--leiho-error)"><?php esc_html_e( 'This secret can’t be read anymore: the WordPress salts have changed (host or security plugin). Enter it again.', 'leiho' ); ?></span>
				<?php elseif ( 'ok' === $status ) : ?>
					<label class="leiho-inline-check"><input type="checkbox" name="leiho_clear_<?php echo esc_attr( $name ); ?>" value="1"> <?php esc_html_e( 'Delete this secret', 'leiho' ); ?></label>
				<?php endif; ?>
				<?php if ( $help ) : ?>
					<span class="leiho-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Publications
	 * =================================================================== */

	private static function render_deploys() {
		$history = Leiho_Deploy_Manager::history();
		$usage   = get_option( Leiho_Plugin::OPTION_USAGE, array() );
		$usage   = is_array( $usage ) ? $usage : array();
		krsort( $usage );
		$usage = array_slice( $usage, 0, 8, true );
		?>
		<div class="leiho-page">
			<h2 class="leiho-heading"><?php esc_html_e( 'Deployment history', 'leiho' ); ?></h2>
			<div class="leiho-card leiho-card--flush">
				<?php if ( ! $history ) : ?>
					<div class="leiho-empty">
						<?php Leiho_Icons::render( 'rocket', 32 ); ?>
						<h3><?php esc_html_e( 'No deployments yet', 'leiho' ); ?></h3>
						<p><?php esc_html_e( 'Every click on “Publish” in the editor shows up here with its result.', 'leiho' ); ?></p>
					</div>
				<?php else : ?>
					<table class="leiho-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'leiho' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'leiho' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Method', 'leiho' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'leiho' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history as $record ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $record['started'] ) ); ?></td>
									<td><?php echo esc_html( $record['user_name'] ); ?></td>
									<td><?php echo esc_html( self::driver_label( $record['driver'] ) ); ?></td>
									<td>
										<span class="leiho-pill leiho-pill--<?php echo esc_attr( self::status_level( $record['status'] ) ); ?>"><?php echo esc_html( self::status_label( $record['status'] ) ); ?></span>
										<?php if ( $record['message'] ) : ?>
											<div class="leiho-table__message"><?php echo esc_html( $record['message'] ); ?></div>
										<?php endif; ?>
										<?php if ( ! empty( $record['meta']['run_url'] ) ) : ?>
											<div class="leiho-table__message"><a href="<?php echo esc_url( $record['meta']['run_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View build', 'leiho' ); ?></a></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $usage ) : ?>
				<h2 class="leiho-heading" style="margin-top:40px"><?php esc_html_e( 'Weekly activity', 'leiho' ); ?></h2>
				<div class="leiho-card leiho-card--flush">
					<table class="leiho-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Week', 'leiho' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Saves', 'leiho' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Deployments', 'leiho' ); ?></th>
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
				'label' => __( 'Live content', 'leiho' ),
				'help'  => __( 'The site reads WordPress on every visit. Saving is enough, nothing to publish.', 'leiho' ),
			),
			'build_hook'    => array(
				'label' => __( 'Build hook', 'leiho' ),
				'help'  => __( 'Vercel, Netlify, Cloudflare Pages… Leiho calls the rebuild URL.', 'leiho' ),
			),
			'github'        => array(
				'label' => __( 'GitHub Actions', 'leiho' ),
				'help'  => __( 'Triggers a workflow and tracks the build live.', 'leiho' ),
			),
			'github_commit' => array(
				'label' => __( 'GitHub commit', 'leiho' ),
				'help'  => __( 'For hosts that deploy on every push.', 'leiho' ),
			),
			'coolify'       => array(
				'label' => __( 'Coolify', 'leiho' ),
				'help'  => __( 'Coolify deployment webhook.', 'leiho' ),
			),
		);
	}

	public static function driver_label( $driver ) {
		$drivers = self::drivers();
		if ( isset( $drivers[ $driver ] ) ) {
			return $drivers[ $driver ]['label'];
		}
		return '' === $driver ? __( 'Not set up', 'leiho' ) : $driver;
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'leiho' ),
			'building'  => __( 'Building', 'leiho' ),
			'triggered' => __( 'Triggered', 'leiho' ),
			'success'   => __( 'Live', 'leiho' ),
			'error'     => __( 'Failed', 'leiho' ),
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
