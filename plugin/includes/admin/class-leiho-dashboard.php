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
								$title ? $title : ( $post_id ? __( 'deleted content', 'leiho' ) : __( 'site settings', 'leiho' ) ),
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

	/** Étapes du parcours pas à pas, dans l'ordre (progression). */
	const START_STEPS = array( 'url', 'frame', 'bridge', 'content', 'annotate', 'test' );

	/**
	 * Adresses utilisées dans les extraits de code de « Démarrer ».
	 *
	 * @return array{wp:string,front:string,export:string,content:string}
	 */
	public static function start_context() {
		$settings = Leiho_Plugin::settings();
		return array(
			'wp'      => Leiho_Admin::wp_origin(),
			'front'   => (string) $settings['front_url'],
			'export'  => rest_url( Leiho_Rest::NS . '/export' ),
			'content' => rest_url( Leiho_Rest::NS . '/content' ),
		);
	}

	/**
	 * Frameworks proposés par « Démarrer », avec leurs extraits de code.
	 *
	 * Une entrée par framework : libellé, précision, et pour chaque étape
	 * (frame, bridge, content, annotate) un groupe d'extraits :
	 *   text   phrase d'introduction ;
	 *   blocks liste de array( file, code ) ;
	 *   tabs   variantes (hébergeur…) : id => array( label, blocks, note ) ;
	 *   note   précision sous les extraits ;
	 *   download true : lien de téléchargement de leiho-bridge.js.
	 * Pour ajouter un framework, ajouter une entrée ici : l'écran, les onglets
	 * et la consigne pour l'agent IA en sont tirés.
	 *
	 * @param array $ctx Adresses (start_context()).
	 * @return array<string,array>
	 */
	public static function frameworks( $ctx ) {
		$vars = array(
			'{{WP}}'      => $ctx['wp'],
			'{{EXPORT}}'  => $ctx['export'],
			'{{CONTENT}}' => $ctx['content'],
		);
		$code = static function ( $key ) use ( $vars ) {
			return strtr( self::snippet( $key ), $vars );
		};
		$csp     = "frame-ancestors 'self' " . $ctx['wp'];
		$install = array(
			'file' => __( 'Terminal', 'leiho' ),
			'code' => 'npm install leiho',
		);
		$hosts   = array(
			'netlify' => array(
				'label'  => 'Netlify · Cloudflare Pages',
				'blocks' => array(
					array(
						'file' => 'public/_headers',
						'code' => "/*\n  Content-Security-Policy: " . $csp,
					),
				),
			),
			'vercel'  => array(
				'label'  => 'Vercel',
				'blocks' => array(
					array(
						'file' => 'vercel.json',
						'code' => $code( 'vercel' ),
					),
				),
			),
		);
		$serverless = __( 'Serverless host with a read-only disk? Pass snapshot: false to loadLeihoContent.', 'leiho' );

		return array(
			'next'      => array(
				'label'    => 'Next.js',
				'hint'     => __( 'App Router', 'leiho' ),
				'frame'    => array(
					'text'   => __( 'Copy this into next.config.ts.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'next.config.ts',
							'code' => $code( 'next-config' ),
						),
					),
				),
				'bridge'   => array(
					'text'   => __( 'Install the package, then add the component to app/layout.tsx. It’s already a client component.', 'leiho' ),
					'blocks' => array(
						$install,
						array(
							'file' => 'app/layout.tsx',
							'code' => $code( 'next-layout' ),
						),
					),
				),
				'content'  => array(
					'text'   => __( 'Load the content in lib/leiho.ts, then read each page by its path.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'lib/leiho.ts',
							'code' => $code( 'next-lib' ),
						),
						array(
							'file' => 'app/[[...slug]]/page.tsx',
							'code' => $code( 'next-page' ),
						),
					),
					'note'   => $serverless,
					'types'  => true,
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => 'JSX',
							'code' => $code( 'annotate-jsx' ),
						),
					),
				),
			),
			'astro'     => array(
				'label'    => 'Astro',
				'hint'     => __( 'Static or server output', 'leiho' ),
				'frame'    => array(
					'text' => __( 'Set the header where the site is hosted.', 'leiho' ),
					'tabs' => $hosts + array(
						'node' => array(
							'label'  => __( 'Node server', 'leiho' ),
							'blocks' => array(
								array(
									'file' => 'src/middleware.ts',
									'code' => $code( 'astro-middleware' ),
								),
							),
							'note'   => __( 'Applies to pages rendered on request. Prerendered pages take their headers from the host.', 'leiho' ),
						),
					),
				),
				'bridge'   => array(
					'text'   => __( 'Install the package, then add this script to your base layout. Astro bundles it, and the bridge only downloads inside the editor.', 'leiho' ),
					'blocks' => array(
						$install,
						array(
							'file' => 'src/layouts/Base.astro',
							'code' => $code( 'astro-layout' ),
						),
					),
				),
				'content'  => array(
					'text'   => __( 'Load the content in getStaticPaths: one request per build.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'src/pages/[...path].astro',
							'code' => $code( 'astro-page' ),
						),
					),
					'types'  => true,
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => '.astro',
							'code' => $code( 'annotate-astro' ),
						),
					),
				),
			),
			'nuxt'      => array(
				'label'    => 'Nuxt',
				'hint'     => __( 'Nuxt 3 and later', 'leiho' ),
				'frame'    => array(
					'text'   => __( 'Copy this into nuxt.config.ts.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'nuxt.config.ts',
							'code' => $code( 'nuxt-config' ),
						),
					),
				),
				'bridge'   => array(
					'text'   => __( 'Install the package, then create this client plugin.', 'leiho' ),
					'blocks' => array(
						$install,
						array(
							'file' => 'plugins/leiho.client.ts',
							'code' => $code( 'nuxt-plugin' ),
						),
					),
				),
				'content'  => array(
					'text'   => __( 'Serve each page from a server route, then fetch it in your page.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'server/api/page.get.ts',
							'code' => $code( 'nuxt-route' ),
						),
						array(
							'file' => 'pages/[...slug].vue',
							'code' => $code( 'nuxt-page' ),
						),
					),
					'note'   => $serverless,
					'types'  => true,
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => 'Vue',
							'code' => $code( 'annotate-vue' ),
						),
					),
				),
			),
			'sveltekit' => array(
				'label'    => 'SvelteKit',
				'hint'     => __( 'Svelte 5', 'leiho' ),
				'frame'    => array(
					'text'   => __( 'Copy this into src/hooks.server.ts.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'src/hooks.server.ts',
							'code' => $code( 'svelte-hooks' ),
						),
					),
					'note'   => __( 'Prerendered pages are served as static files: set the same header on your host.', 'leiho' ),
				),
				'bridge'   => array(
					'text'   => __( 'Install the package, then load the bridge from the root layout.', 'leiho' ),
					'blocks' => array(
						$install,
						array(
							'file' => 'src/routes/+layout.svelte',
							'code' => $code( 'svelte-layout' ),
						),
					),
				),
				'content'  => array(
					'text'   => __( 'Load each page in +page.server.ts.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'src/routes/[...path]/+page.server.ts',
							'code' => $code( 'svelte-load' ),
						),
					),
					'note'   => $serverless,
					'types'  => true,
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => 'Svelte',
							'code' => $code( 'annotate-svelte' ),
						),
					),
				),
			),
			'vite'      => array(
				'label'    => 'React + Vite',
				'hint'     => __( 'Also Remix and React Router', 'leiho' ),
				'frame'    => array(
					'text' => __( 'Set the header on your host, and in vite.config.ts for vite dev and vite preview.', 'leiho' ),
					'tabs' => $hosts + array(
						'dev' => array(
							'label'  => __( 'Dev server', 'leiho' ),
							'blocks' => array(
								array(
									'file' => 'vite.config.ts',
									'code' => $code( 'vite-config' ),
								),
							),
						),
					),
				),
				'bridge'   => array(
					'text'   => __( 'Install the package, then add the component next to your app in src/main.tsx.', 'leiho' ),
					'blocks' => array(
						$install,
						array(
							'file' => 'src/main.tsx',
							'code' => $code( 'vite-main' ),
						),
					),
					'note'   => __( 'Remix or React Router: put it in app/root.tsx.', 'leiho' ),
				),
				'content'  => array(
					'text'   => __( 'The app fetches one page at a time, from the browser.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => 'src/usePage.ts',
							'code' => $code( 'vite-hook' ),
						),
					),
					'note'   => __( 'Remix or React Router loaders: use loadLeihoContent from leiho/content instead.', 'leiho' ),
					'types'  => true,
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => 'JSX',
							'code' => $code( 'annotate-jsx' ),
						),
					),
				),
			),
			'other'     => array(
				'label'    => __( 'Other', 'leiho' ),
				'hint'     => __( 'Plain HTML, PHP, Eleventy, Hugo…', 'leiho' ),
				'frame'    => array(
					'text' => __( 'Set the header in your server configuration.', 'leiho' ),
					'tabs' => array(
						'nginx'  => array(
							'label'  => 'nginx',
							'blocks' => array(
								array(
									'file' => 'nginx.conf',
									'code' => 'add_header Content-Security-Policy "' . $csp . '" always;',
								),
							),
						),
						'apache' => array(
							'label'  => 'Apache',
							'blocks' => array(
								array(
									'file' => '.htaccess',
									'code' => "<IfModule mod_headers.c>\n  Header always set Content-Security-Policy \"" . $csp . "\"\n</IfModule>",
								),
							),
						),
						'header' => array(
							'label'  => __( 'Any server', 'leiho' ),
							'blocks' => array(
								array(
									'file' => __( 'HTTP header', 'leiho' ),
									'code' => 'Content-Security-Policy: ' . $csp,
								),
							),
						),
					),
				),
				'bridge'   => array(
					'text'     => __( 'Put leiho-bridge.js in the site’s public folder, then add this code to the layout’s <head>.', 'leiho' ),
					'blocks'   => array(
						array(
							'file' => '<head>',
							'code' => $code( 'loader' ),
						),
					),
					'download' => true,
				),
				'content'  => array(
					'text'   => __( 'Read the content from these URLs, at build time or on each request.', 'leiho' ),
					'blocks' => array(
						array(
							'file' => __( 'Content API', 'leiho' ),
							'code' => $code( 'rest' ),
						),
					),
					'note'   => __( 'Building with Node? npm install leiho gives you loadLeihoContent from leiho/content.', 'leiho' ),
				),
				'annotate' => array(
					'blocks' => array(
						array(
							'file' => 'HTML',
							'code' => $code( 'annotate-html' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Extraits de code bruts. {{WP}}, {{EXPORT}} et {{CONTENT}} sont remplacés
	 * par les adresses du site.
	 *
	 * @param string $key Extrait.
	 * @return string
	 */
	private static function snippet( $key ) {
		switch ( $key ) {
			case 'vercel':
				return <<<'CODE'
{
  "headers": [
    {
      "source": "/(.*)",
      "headers": [
        { "key": "Content-Security-Policy", "value": "frame-ancestors 'self' {{WP}}" }
      ]
    }
  ]
}
CODE;
			case 'next-config':
				return <<<'CODE'
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: "frame-ancestors 'self' {{WP}}" },
        ],
      },
    ];
  },
};

export default nextConfig;
CODE;
			case 'next-layout':
				return <<<'CODE'
import { LeihoBridge } from 'leiho/react';

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        {children}
        <LeihoBridge wpOrigin="{{WP}}" />
      </body>
    </html>
  );
}
CODE;
			case 'next-lib':
				return <<<'CODE'
import { cache } from 'react';
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';

// One request per render, shared by every component.
export const getContent = cache(async () => {
  const { data } = await loadLeihoContent({ url: '{{EXPORT}}' });
  return indexLeihoContent(data);
});
CODE;
			case 'next-page':
				return <<<'CODE'
import { notFound } from 'next/navigation';
import { getContent } from '@/lib/leiho';

export default async function Page({ params }: { params: Promise<{ slug?: string[] }> }) {
  const { slug = [] } = await params;
  const page = (await getContent()).byPath('/' + slug.join('/'));
  if (!page) notFound();

  return (
    <main data-wp-post={page.id}>
      <h1 data-wp-field="_title">{page.title}</h1>
    </main>
  );
}
CODE;
			case 'astro-middleware':
				return <<<'CODE'
import { defineMiddleware } from 'astro:middleware';

export const onRequest = defineMiddleware(async (_context, next) => {
  const response = await next();
  response.headers.set('Content-Security-Policy', "frame-ancestors 'self' {{WP}}");
  return response;
});
CODE;
			case 'astro-layout':
				return <<<'CODE'
<head>
  <!-- … -->
  <script>
    if (window.self !== window.top) {
      import('leiho').then(({ initLeihoBridge }) => {
        initLeihoBridge({ wpOrigin: '{{WP}}' });
      });
    }
  </script>
</head>
CODE;
			case 'astro-page':
				return <<<'CODE'
---
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';
import Base from '../layouts/Base.astro';

export async function getStaticPaths() {
  const { data } = await loadLeihoContent({ url: '{{EXPORT}}' });
  return indexLeihoContent(data).items.map((page) => ({
    params: { path: page.path.replace(/^\/|\/$/g, '') || undefined },
    props: { page },
  }));
}

const { page } = Astro.props;
---
<Base title={page.title}>
  <main data-wp-post={page.id}>
    <h1 data-wp-field="_title">{page.title}</h1>
  </main>
</Base>
CODE;
			case 'nuxt-config':
				return <<<'CODE'
export default defineNuxtConfig({
  routeRules: {
    '/**': {
      headers: { 'Content-Security-Policy': "frame-ancestors 'self' {{WP}}" },
    },
  },
});
CODE;
			case 'nuxt-plugin':
				return <<<'CODE'
export default defineNuxtPlugin(() => {
  if (window.self === window.top) return;
  import('leiho').then(({ initLeihoBridge }) => {
    // Already on screen: don't hide the page.
    initLeihoBridge({ wpOrigin: '{{WP}}', cloakTimeout: 0 });
  });
});
CODE;
			case 'nuxt-route':
				return <<<'CODE'
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';

export default defineEventHandler(async (event) => {
  const { path = '/' } = getQuery(event);
  const { data } = await loadLeihoContent({ url: '{{EXPORT}}' });
  const page = indexLeihoContent(data).byPath(String(path));
  if (!page) throw createError({ statusCode: 404 });
  return page;
});
CODE;
			case 'nuxt-page':
				return <<<'CODE'
<script setup lang="ts">
const route = useRoute();
const { data: page } = await useFetch('/api/page', { query: { path: route.path } });
</script>

<template>
  <main v-if="page" :data-wp-post="page.id">
    <h1 data-wp-field="_title">{{ page.title }}</h1>
  </main>
</template>
CODE;
			case 'svelte-hooks':
				return <<<'CODE'
import type { Handle } from '@sveltejs/kit';

export const handle: Handle = async ({ event, resolve }) => {
  const response = await resolve(event);
  response.headers.set('Content-Security-Policy', "frame-ancestors 'self' {{WP}}");
  return response;
};
CODE;
			case 'svelte-layout':
				return <<<'CODE'
<script>
  import { onMount } from 'svelte';

  let { children } = $props();

  onMount(() => {
    if (window.self === window.top) return;
    import('leiho').then(({ initLeihoBridge }) => {
      // Already on screen: don't hide the page.
      initLeihoBridge({ wpOrigin: '{{WP}}', cloakTimeout: 0 });
    });
  });
</script>

{@render children()}
CODE;
			case 'svelte-load':
				return <<<'CODE'
import { error } from '@sveltejs/kit';
import { loadLeihoContent, indexLeihoContent } from 'leiho/content';

export async function load({ url }) {
  const { data } = await loadLeihoContent({ url: '{{EXPORT}}' });
  const page = indexLeihoContent(data).byPath(url.pathname);
  if (!page) error(404, 'Not found');
  return { page };
}
CODE;
			case 'vite-config':
				return <<<'CODE'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const csp = "frame-ancestors 'self' {{WP}}";

export default defineConfig({
  plugins: [react()],
  server: { headers: { 'Content-Security-Policy': csp } },
  preview: { headers: { 'Content-Security-Policy': csp } },
});
CODE;
			case 'vite-main':
				return <<<'CODE'
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LeihoBridge } from 'leiho/react';
import App from './App';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
    <LeihoBridge wpOrigin="{{WP}}" />
  </StrictMode>
);
CODE;
			case 'vite-hook':
				return <<<'CODE'
import { useEffect, useState } from 'react';

export function usePage(path: string) {
  const [page, setPage] = useState<any>(null);

  useEffect(() => {
    const url = new URL('{{CONTENT}}');
    url.searchParams.set('path', path);
    let cancelled = false;
    fetch(url)
      .then((res) => res.json())
      .then((items) => {
        if (!cancelled) setPage(items[0] ?? null);
      });
    return () => {
      cancelled = true;
    };
  }, [path]);

  return page;
}
CODE;
			case 'loader':
				return <<<'CODE'
<script>
  if (window.self !== window.top) {
    var s = document.createElement('script');
    s.src = '/leiho-bridge.js';
    s.setAttribute('data-wp-origin', '{{WP}}');
    document.head.appendChild(s);
  }
</script>
CODE;
			case 'rest':
				return <<<'CODE'
# All published content, in one request
GET {{EXPORT}}

# One page, by its path
GET {{CONTENT}}?path=/about
CODE;
			case 'annotate-jsx':
				return <<<'CODE'
<section data-wp-post={page.id} data-leiho-section="Hero">
  <h1 data-wp-field="hero_title">{page.fields.hero_title}</h1>
  <img data-wp-field="hero_image" src={page.fields.hero_image?.url} alt={page.fields.hero_image?.alt} />
  {page.fields.cards?.map((card, i) => (
    <article key={i} data-wp-field={`cards.${i}.title`}>{card.title}</article>
  ))}
</section>
CODE;
			case 'annotate-astro':
				return <<<'CODE'
<section data-wp-post={page.id} data-leiho-section="Hero">
  <h1 data-wp-field="hero_title">{page.fields.hero_title}</h1>
  <img data-wp-field="hero_image" src={page.fields.hero_image?.url} alt={page.fields.hero_image?.alt} />
  {page.fields.cards?.map((card, i) => (
    <article data-wp-field={`cards.${i}.title`}>{card.title}</article>
  ))}
</section>
CODE;
			case 'annotate-vue':
				return <<<'CODE'
<section :data-wp-post="page.id" data-leiho-section="Hero">
  <h1 data-wp-field="hero_title">{{ page.fields.hero_title }}</h1>
  <img data-wp-field="hero_image" :src="page.fields.hero_image?.url" :alt="page.fields.hero_image?.alt">
  <article v-for="(card, i) in page.fields.cards" :key="i" :data-wp-field="`cards.${i}.title`">
    {{ card.title }}
  </article>
</section>
CODE;
			case 'annotate-svelte':
				return <<<'CODE'
<section data-wp-post={page.id} data-leiho-section="Hero">
  <h1 data-wp-field="hero_title">{page.fields.hero_title}</h1>
  <img data-wp-field="hero_image" src={page.fields.hero_image?.url} alt={page.fields.hero_image?.alt} />
  {#each page.fields.cards ?? [] as card, i}
    <article data-wp-field="cards.{i}.title">{card.title}</article>
  {/each}
</section>
CODE;
			case 'annotate-html':
				return <<<'CODE'
<section data-wp-post="12" data-leiho-section="Hero">
  <h1 data-wp-field="hero_title">…</h1>
  <img data-wp-field="hero_image" src="…" alt="…">
  <article data-wp-field="cards.0.title">…</article>
</section>
CODE;
		}
		return '';
	}

	/**
	 * Titres des étapes à extraits (écran et consigne pour l'agent).
	 *
	 * @return array<string,string>
	 */
	private static function start_step_titles() {
		return array(
			'frame'    => __( 'Allow the editor to display the site', 'leiho' ),
			'bridge'   => __( 'Install the bridge', 'leiho' ),
			'content'  => __( 'Read the content', 'leiho' ),
			'annotate' => __( 'Annotate your content', 'leiho' ),
		);
	}

	/**
	 * Début de la consigne pour l'agent IA, propre à un framework : contexte,
	 * adresses et extraits déjà remplis. Le guide complet est ajouté à la copie.
	 *
	 * @param array  $fw   Framework (frameworks()).
	 * @param string $case existing | new.
	 * @param array  $ctx  Adresses (start_context()).
	 * @return string
	 */
	public static function start_prompt( $fw, $case, $ctx ) {
		$lines   = array();
		$lines[] = __( 'We’re going to make this site editable with Leiho, the visual editor for WordPress. Leiho has precise instructions to follow throughout the project; you’ll find them below. Read them in full before writing any code, and follow them for every component.', 'leiho' );
		$lines[] = 'new' === $case
			? __( 'We’re starting from scratch. Build the site and its ACF content model, following the instructions from the very first component. Finish with the checklist.', 'leiho' )
			: __( 'The site already exists. Install the bridge, then annotate every piece of displayed content with its matching WordPress field, following the instructions. Finish with the checklist.', 'leiho' );

		$facts = array(
			/* translators: %s: framework name (Next.js, Astro…). */
			sprintf( __( 'Framework: %s', 'leiho' ), $fw['label'] . ( '' !== $fw['hint'] ? ' (' . $fw['hint'] . ')' : '' ) ),
			/* translators: %s: WordPress address. */
			sprintf( __( 'WordPress address (wp-admin origin): %s', 'leiho' ), $ctx['wp'] ),
			'' !== $ctx['front']
				/* translators: %s: site address. */
				? sprintf( __( 'Site address: %s', 'leiho' ), $ctx['front'] )
				: __( 'Site address: not set yet in Leiho.', 'leiho' ),
		);
		$lines[] = '- ' . implode( "\n- ", $facts );
		$lines[] = __( 'Here are the snippets for this framework, already filled in with these addresses. Adapt file names and paths to the project.', 'leiho' );

		foreach ( self::start_step_titles() as $step => $title ) {
			$group   = $fw[ $step ];
			$lines[] = '## ' . $title;
			if ( ! empty( $group['text'] ) ) {
				$lines[] = $group['text'];
			}
			$variants = ! empty( $group['tabs'] ) ? $group['tabs'] : array( array( 'blocks' => $group['blocks'] ) );
			if ( ! empty( $group['tabs'] ) ) {
				$lines[] = __( 'Use the variant that matches the host:', 'leiho' );
			}
			foreach ( $variants as $variant ) {
				foreach ( $variant['blocks'] as $block ) {
					$label   = isset( $variant['label'] ) ? $variant['label'] . ' — ' : '';
					$lines[] = $label . $block['file'] . ":\n\n```\n" . $block['code'] . "\n```";
				}
				if ( ! empty( $variant['note'] ) ) {
					$lines[] = $variant['note'];
				}
			}
			if ( ! empty( $group['download'] ) ) {
				$lines[] = __( 'Copy leiho-bridge.js from the leiho npm package (dist folder) to the site’s public folder.', 'leiho' );
			}
			if ( ! empty( $group['note'] ) ) {
				$lines[] = $group['note'];
			}
		}

		$lines[] = __( 'When you’re done, tell me: I’ll click “Test the connection” in Leiho → Get started to check that the editor sees the site.', 'leiho' );
		$lines[] = 'new' === $case ? __( 'Site idea or brief:', 'leiho' ) : __( 'Site repository:', 'leiho' );

		return implode( "\n\n", $lines );
	}

	/**
	 * Framework demandé dans l'URL (?fw=), '' si absent ou inconnu.
	 *
	 * @param array $frameworks Frameworks connus.
	 * @return string
	 */
	private static function requested_framework( $frameworks ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple choix d'affichage.
		$fw = isset( $_GET['fw'] ) ? sanitize_key( wp_unslash( $_GET['fw'] ) ) : '';
		return isset( $frameworks[ $fw ] ) ? $fw : '';
	}

	/**
	 * Étapes faites, d'après ce que le serveur sait : adresse du site et
	 * dernier test du bridge (un bridge qui répond prouve aussi l'en-tête).
	 *
	 * @param bool       $has_url    Adresse du site renseignée.
	 * @param array|null $connection Dernier test (connection()).
	 * @return array<string,bool>
	 */
	public static function start_progress( $has_url, $connection ) {
		$ok     = $connection && ! empty( $connection['ok'] );
		$fields = $ok && ! empty( $connection['fields'] );
		return array(
			'url'      => (bool) $has_url,
			'frame'    => (bool) $ok,
			'bridge'   => (bool) $ok,
			'content'  => (bool) $fields,
			'annotate' => (bool) $fields,
			'test'     => (bool) $ok,
		);
	}

	private static function render_start() {
		$settings   = Leiho_Plugin::settings();
		$ctx        = self::start_context();
		$has_url    = '' !== $settings['front_url'];
		$connection = self::connection();
		$frameworks = self::frameworks( $ctx );
		$current    = self::requested_framework( $frameworks );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple choix d'affichage.
		$path       = isset( $_GET['path'] ) ? sanitize_key( wp_unslash( $_GET['path'] ) ) : '';
		$path       = in_array( $path, array( 'steps', 'ai' ), true ) ? $path : '';
		$done       = self::start_progress( $has_url, $connection );
		$count      = count( array_filter( $done ) );
		$total      = count( self::START_STEPS );
		$next_step  = '';
		foreach ( self::START_STEPS as $step ) {
			if ( empty( $done[ $step ] ) ) {
				$next_step = $step;
				break;
			}
		}
		$titles = self::start_step_titles();

		// Version française du guide pour un wp-admin en français, anglaise sinon.
		$instructions_file = LEIHO_DIR . 'docs/agent-instructions.md';
		if ( 0 === strpos( determine_locale(), 'fr' ) && file_exists( LEIHO_DIR . 'docs/agent-instructions.fr.md' ) ) {
			$instructions_file = LEIHO_DIR . 'docs/agent-instructions.fr.md';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fichier local du plugin.
		$instructions = file_exists( $instructions_file ) ? file_get_contents( $instructions_file ) : '';
		$instructions = str_replace( 'https://ADRESSE-DU-WORDPRESS', $ctx['wp'], $instructions );

		$step_class = static function ( $step ) use ( $done, $next_step ) {
			if ( ! empty( $done[ $step ] ) ) {
				return 'leiho-step leiho-step--done';
			}
			return $step === $next_step ? 'leiho-step leiho-step--current' : 'leiho-step';
		};
		?>
		<div class="leiho-page leiho-page--narrow leiho-onboard" data-leiho-onboarding data-fw="<?php echo esc_attr( $current ); ?>">
			<span class="leiho-eyebrow"><?php esc_html_e( 'Connect a site', 'leiho' ); ?></span>
			<h2 style="margin:0 0 8px;font-size:22px;color:var(--leiho-ink)"><?php esc_html_e( 'Make your site editable', 'leiho' ); ?></h2>
			<p class="leiho-lead"><?php esc_html_e( 'Leiho works with any site that reads its content from WordPress. Pick your framework: the steps and the code adapt to it.', 'leiho' ); ?></p>

			<form class="leiho-onboard__section" id="leiho-fw-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" data-leiho-fw-form>
				<input type="hidden" name="page" value="<?php echo esc_attr( Leiho_App::SLUG ); ?>">
				<input type="hidden" name="view" value="start">
				<fieldset>
					<legend class="leiho-onboard__title"><span class="leiho-onboard__num">1</span><?php esc_html_e( 'Pick your framework.', 'leiho' ); ?></legend>
					<div class="leiho-choices leiho-choices--fw">
						<?php foreach ( $frameworks as $id => $fw ) : ?>
							<label class="leiho-choice">
								<input type="radio" name="fw" value="<?php echo esc_attr( $id ); ?>" <?php checked( $current, $id ); ?> data-leiho-fw-choice>
								<strong><?php echo esc_html( $fw['label'] ); ?></strong>
								<span><?php echo esc_html( $fw['hint'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<noscript><p><button type="submit" class="leiho-btn leiho-btn--small"><?php esc_html_e( 'Show the steps', 'leiho' ); ?></button></p></noscript>
			</form>

			<div data-leiho-after-fw <?php echo '' === $current ? 'hidden' : ''; ?>>
				<fieldset class="leiho-onboard__section">
					<legend class="leiho-onboard__title"><span class="leiho-onboard__num">2</span><?php esc_html_e( 'Choose how.', 'leiho' ); ?></legend>
					<div class="leiho-choices leiho-choices--paths">
						<label class="leiho-choice">
							<input type="radio" name="path" value="steps" form="leiho-fw-form" <?php checked( $path, 'steps' ); ?> data-leiho-path-choice>
							<strong><?php esc_html_e( 'Set it up step by step', 'leiho' ); ?></strong>
							<span><?php esc_html_e( 'Six short steps with the code for your framework, each one checked.', 'leiho' ); ?></span>
						</label>
						<label class="leiho-choice">
							<input type="radio" name="path" value="ai" form="leiho-fw-form" <?php checked( $path, 'ai' ); ?> data-leiho-path-choice>
							<strong><?php esc_html_e( 'Let my AI do it', 'leiho' ); ?></strong>
							<span><?php esc_html_e( 'Copy one prompt into your coding agent. It gets the same code and the full rules.', 'leiho' ); ?></span>
						</label>
					</div>
				</fieldset>

				<noscript><p><button type="submit" form="leiho-fw-form" class="leiho-btn leiho-btn--small"><?php esc_html_e( 'Show this path', 'leiho' ); ?></button></p></noscript>

				<section class="leiho-onboard__section" data-leiho-path-panel="steps" aria-labelledby="leiho-steps-title" <?php echo 'steps' === $path ? '' : 'hidden'; ?>>
					<div class="leiho-onboard__head">
						<h2 class="leiho-onboard__title" id="leiho-steps-title"><?php esc_html_e( 'Step by step', 'leiho' ); ?></h2>
						<div class="leiho-onboard__progress">
							<progress class="leiho-progress" max="<?php echo esc_attr( $total ); ?>" value="<?php echo esc_attr( $count ); ?>" data-leiho-progress-bar aria-labelledby="leiho-progress-label"></progress>
							<span id="leiho-progress-label" aria-live="polite" data-leiho-progress-label>
								<?php
								/* translators: 1: steps done, 2: total steps. */
								echo esc_html( sprintf( __( '%1$d of %2$d steps done', 'leiho' ), $count, $total ) );
								?>
							</span>
						</div>
					</div>

					<ol class="leiho-steps">
						<li class="<?php echo esc_attr( $step_class( 'url' ) ); ?>" data-leiho-step="url">
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

						<li class="<?php echo esc_attr( $step_class( 'frame' ) ); ?>" data-leiho-step="frame">
							<h3><?php echo esc_html( $titles['frame'] ); ?></h3>
							<p><?php esc_html_e( 'The site must allow being displayed in wp-admin. Already sending a Content-Security-Policy? Add frame-ancestors to it, and don’t send X-Frame-Options.', 'leiho' ); ?></p>
							<?php self::render_fw_groups( $frameworks, 'frame', $current ); ?>
							<div class="leiho-actions">
								<button type="button" class="leiho-btn leiho-btn--quiet leiho-btn--small" data-leiho-check-headers <?php disabled( ! $has_url ); ?>>
									<?php esc_html_e( 'Check headers', 'leiho' ); ?>
								</button>
							</div>
							<div class="leiho-check" data-leiho-headers-result role="status" aria-live="polite" hidden></div>
						</li>

						<li class="<?php echo esc_attr( $step_class( 'bridge' ) ); ?>" data-leiho-step="bridge">
							<h3><?php echo esc_html( $titles['bridge'] ); ?></h3>
							<p><?php esc_html_e( 'A small script that only loads inside the editor. Your visitors never download it.', 'leiho' ); ?></p>
							<?php self::render_fw_groups( $frameworks, 'bridge', $current ); ?>
						</li>

						<li class="<?php echo esc_attr( $step_class( 'content' ) ); ?>" data-leiho-step="content">
							<h3><?php echo esc_html( $titles['content'] ); ?></h3>
							<p><?php esc_html_e( 'Read your content from Leiho’s content API: one request, all published content, fields already shaped.', 'leiho' ); ?></p>
							<?php self::render_fw_groups( $frameworks, 'content', $current, $ctx ); ?>
							<?php if ( 'token' === $settings['content_access'] ) : ?>
								<p class="leiho-help"><?php esc_html_e( 'Restricted access is on: set LEIHO_TOKEN in the build environment. The token is in Settings, under Content API.', 'leiho' ); ?></p>
							<?php endif; ?>
							<p class="leiho-help"><?php esc_html_e( 'Site built at deploy time? In Settings, choose how Publish rebuilds it. Rendered on each request? Choose Live content.', 'leiho' ); ?></p>
						</li>

						<li class="<?php echo esc_attr( $step_class( 'annotate' ) ); ?>" data-leiho-step="annotate">
							<h3><?php echo esc_html( $titles['annotate'] ); ?></h3>
							<p><?php esc_html_e( 'Every element that displays a WordPress field carries that field’s name. The bridge uses it to know what to update, and the editor to know which field to open on click.', 'leiho' ); ?></p>
							<?php self::render_fw_groups( $frameworks, 'annotate', $current ); ?>
							<p class="leiho-help"><?php esc_html_e( 'Always render annotated elements, even when empty. All the annotations, rules and the checklist are in the agent instructions, below.', 'leiho' ); ?></p>
						</li>

						<li class="<?php echo esc_attr( $step_class( 'test' ) ); ?>" data-leiho-step="test" id="leiho-step-test">
							<h3><?php esc_html_e( 'Test the connection', 'leiho' ); ?></h3>
							<p><?php esc_html_e( 'Leiho opens your site in the background and waits for the bridge to check in. If it responds, the editor is ready.', 'leiho' ); ?></p>
							<div class="leiho-actions">
								<button type="button" class="leiho-btn" data-leiho-test-bridge <?php disabled( ! $has_url ); ?>>
									<?php esc_html_e( 'Test the connection', 'leiho' ); ?>
								</button>
							</div>
							<div class="leiho-check <?php echo $connection ? ( $connection['ok'] ? 'leiho-check--ok' : 'leiho-check--error' ) : ''; ?>" data-leiho-bridge-result role="status" aria-live="polite" <?php echo $connection ? '' : 'hidden'; ?>>
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
				</section>

				<section class="leiho-onboard__section" data-leiho-path-panel="ai" aria-labelledby="leiho-ai-title" <?php echo 'ai' === $path ? '' : 'hidden'; ?>>
					<h2 class="leiho-onboard__title" id="leiho-ai-title"><?php esc_html_e( 'With your AI agent', 'leiho' ); ?></h2>
					<div class="leiho-card">
						<p><?php esc_html_e( 'One prompt with your framework, your addresses, the code from the steps and Leiho’s full rules. Paste it into your agent, then add your repository or brief.', 'leiho' ); ?></p>
						<?php foreach ( $frameworks as $id => $fw ) : ?>
							<div data-leiho-fw="<?php echo esc_attr( $id ); ?>" <?php echo $id === $current ? '' : 'hidden'; ?>>
								<?php
								$prompt_id = 'leiho-prompt-' . $id;
								self::render_tabs(
									$prompt_id,
									array(
										'existing' => array(
											'label' => __( 'Existing site', 'leiho' ),
											'html'  => self::prompt_panel( $prompt_id . '-existing', self::start_prompt( $fw, 'existing', $ctx ) ),
										),
										'new'      => array(
											'label' => __( 'New site', 'leiho' ),
											'html'  => self::prompt_panel( $prompt_id . '-new', self::start_prompt( $fw, 'new', $ctx ) ),
										),
									)
								);
								?>
							</div>
						<?php endforeach; ?>
						<p class="leiho-help"><?php esc_html_e( 'Works with Claude Code, Cursor, Codex, Copilot and any agent that edits your code.', 'leiho' ); ?></p>
						<div class="leiho-check leiho-check--ok" data-leiho-after-copy role="status" aria-live="polite" hidden>
							<span class="leiho-dot leiho-dot--ok"></span>
							<span>
								<?php esc_html_e( 'Prompt copied. When your agent is done, test the connection.', 'leiho' ); ?>
								<button type="button" class="leiho-btn leiho-btn--quiet leiho-btn--small" data-leiho-goto-test><?php esc_html_e( 'Go to the test', 'leiho' ); ?></button>
							</span>
						</div>
					</div>
				</section>
			</div>

			<details class="leiho-onboard__guide">
				<summary><?php esc_html_e( 'Read the full agent instructions', 'leiho' ); ?></summary>
				<textarea id="leiho-instructions" class="leiho-instructions" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Agent instructions', 'leiho' ); ?>"><?php echo esc_textarea( $instructions ); ?></textarea>
			</details>
		</div>
		<?php
	}

	/**
	 * Panneaux d'une étape, un par framework ; seul le framework choisi est
	 * visible (le JS bascule les autres).
	 *
	 * @param array  $frameworks Frameworks.
	 * @param string $step       frame | bridge | content | annotate.
	 * @param string $current    Framework choisi, '' si aucun.
	 * @param array  $ctx        Adresses (étape content : commande des types).
	 * @return void
	 */
	private static function render_fw_groups( $frameworks, $step, $current, $ctx = array() ) {
		foreach ( $frameworks as $id => $fw ) {
			$group = $fw[ $step ];
			?>
			<div class="leiho-fw-panel" data-leiho-fw="<?php echo esc_attr( $id ); ?>" <?php echo $id === $current ? '' : 'hidden'; ?>>
				<?php if ( ! empty( $group['text'] ) ) : ?>
					<p><?php echo esc_html( $group['text'] ); ?></p>
				<?php endif; ?>
				<?php
				if ( ! empty( $group['tabs'] ) ) {
					$tabs = array();
					foreach ( $group['tabs'] as $tab_id => $tab ) {
						$tabs[ $tab_id ] = array(
							'label' => $tab['label'],
							'html'  => self::blocks_html( $tab['blocks'] ) . ( ! empty( $tab['note'] ) ? '<p class="leiho-help">' . esc_html( $tab['note'] ) . '</p>' : '' ),
						);
					}
					self::render_tabs( 'leiho-' . $step . '-' . $id, $tabs );
				} else {
					echo self::blocks_html( $group['blocks'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Échappé dans blocks_html().
				}
				?>
				<?php if ( ! empty( $group['download'] ) ) : ?>
					<div class="leiho-actions">
						<a class="leiho-btn leiho-btn--quiet leiho-btn--small" href="<?php echo esc_url( LEIHO_URL . 'assets/bridge/leiho-bridge.js' ); ?>" download="leiho-bridge.js"><?php esc_html_e( 'Download leiho-bridge.js', 'leiho' ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $group['note'] ) ) : ?>
					<p class="leiho-help"><?php echo esc_html( $group['note'] ); ?></p>
				<?php endif; ?>
				<?php
				if ( ! empty( $group['types'] ) && ! empty( $ctx['export'] ) ) {
					echo '<p class="leiho-help">' . esc_html__( 'Using TypeScript? Generate the types of your fields, and run it again after changing a field group:', 'leiho' ) . '</p>';
					echo self::blocks_html( array( array( 'file' => __( 'Terminal', 'leiho' ), 'code' => 'npx leiho types --url ' . $ctx['export'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Échappé dans blocks_html().
				}
				?>
			</div>
			<?php
		}
	}

	/**
	 * Blocs de code à copier, avec le nom du fichier au-dessus.
	 *
	 * @param array $blocks Liste de array( file, code ).
	 * @return string HTML échappé.
	 */
	private static function blocks_html( $blocks ) {
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= '<div class="leiho-code leiho-code--file">'
				. '<span class="leiho-code__file">' . esc_html( $block['file'] ) . '</span>'
				. '<pre>' . esc_html( $block['code'] ) . '</pre>'
				. '<button type="button" data-leiho-copy="' . esc_attr( $block['code'] ) . '">' . esc_html__( 'Copy', 'leiho' ) . '</button>'
				. '</div>';
		}
		return $html;
	}

	/**
	 * Bouton de copie d'une consigne et son texte (caché). La copie y ajoute le
	 * guide complet (#leiho-instructions).
	 *
	 * @param string $id     Identifiant du texte.
	 * @param string $prompt Début de la consigne.
	 * @return string HTML échappé.
	 */
	private static function prompt_panel( $id, $prompt ) {
		return '<div class="leiho-actions">'
			. '<button type="button" class="leiho-btn" data-leiho-copy-from="' . esc_attr( $id . ' leiho-instructions' ) . '" data-leiho-prompt-copy>' . esc_html__( 'Copy prompt', 'leiho' ) . '</button>'
			. '</div>'
			. '<textarea id="' . esc_attr( $id ) . '" class="leiho-copy-source" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( $prompt ) . '</textarea>';
	}

	/**
	 * Onglets (.leiho-segmented) et leurs panneaux, le premier ouvert.
	 *
	 * @param string $prefix Préfixe unique des identifiants.
	 * @param array  $tabs   id => array( label, html ) ; html déjà échappé.
	 * @return void
	 */
	private static function render_tabs( $prefix, $tabs ) {
		$first = (string) array_key_first( $tabs );
		?>
		<div class="leiho-codetabs">
			<div class="leiho-segmented" role="tablist" data-leiho-segmented>
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<button type="button" role="tab" id="<?php echo esc_attr( $prefix . '-tab-' . $tab_id ); ?>" aria-controls="<?php echo esc_attr( $prefix . '-' . $tab_id . '-panel' ); ?>" aria-selected="<?php echo $tab_id === $first ? 'true' : 'false'; ?>" tabindex="<?php echo $tab_id === $first ? '0' : '-1'; ?>" data-panel="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $tab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>
			<?php foreach ( $tabs as $tab_id => $tab ) : ?>
				<div role="tabpanel" id="<?php echo esc_attr( $prefix . '-' . $tab_id . '-panel' ); ?>" aria-labelledby="<?php echo esc_attr( $prefix . '-tab-' . $tab_id ); ?>" data-panel-id="<?php echo esc_attr( $tab_id ); ?>" <?php echo $tab_id === $first ? '' : 'hidden'; ?>>
					<?php echo $tab['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Échappé par l'appelant. ?>
				</div>
			<?php endforeach; ?>
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
							// Sans contenu (post_id 0) : réglages changés par un agent.
							$allowed = (int) $activity->post_id ? current_user_can( 'edit_post', (int) $activity->post_id ) : current_user_can( 'manage_options' );
							if ( $allowed ) {
								self::render_activity( $activity );
							}
						}
						?>
					</ul>
				<?php endif; ?>
			</section>

			<?php self::render_ai_agents( $status ); ?>
		</div>
		<?php
	}

	/**
	 * Écran IA : agents externes (MCP). Guide de connexion pas à pas, avec
	 * l'adresse réelle du serveur MCP du site et l'identifiant de
	 * l'utilisateur courant (jamais de mot de passe), puis la liste des
	 * capacités.
	 *
	 * @param array $status État de l'IA (Leiho_AI::status()).
	 * @return void
	 */
	private static function render_ai_agents( array $status ) {
		?>
		<section class="leiho-form-section" id="leiho-ai-agents">
			<h2><?php esc_html_e( 'AI agents', 'leiho' ); ?></h2>
			<?php if ( ! function_exists( 'wp_register_ability' ) ) : ?>
				<p class="leiho-muted"><?php esc_html_e( 'Leiho offers abilities to external AI agents from WordPress 6.9 (Abilities API).', 'leiho' ); ?></p>
			</section>
				<?php
				return;
			endif;

			$adapter  = Leiho_AI_Admin_Abilities::mcp_adapter_active();
			$install  = current_user_can( 'install_plugins' ) ? admin_url( 'plugin-install.php?s=mcp-adapter&tab=search&type=term' ) : 'https://wordpress.org/plugins/mcp-adapter/';
			$endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
			$user     = wp_get_current_user();
			$login    = $user && $user->exists() ? $user->user_login : 'your-username';
			$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$server   = sanitize_key( str_replace( '.', '-', '' !== $host ? $host : 'wordpress' ) );
			// Jamais de vrai mot de passe : un modèle au format des mots de passe d'application.
			$password = 'xxxx xxxx xxxx xxxx xxxx xxxx';
			$package  = '@automattic/mcp-wordpress-remote@latest';

			$json = wp_json_encode(
				array(
					'mcpServers' => array(
						$server => array(
							'command' => 'npx',
							'args'    => array( '-y', $package ),
							'env'     => array(
								'WP_API_URL'      => $endpoint,
								'WP_API_USERNAME' => $login,
								'WP_API_PASSWORD' => $password,
							),
						),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			$quote = static function ( $value ) {
				return "'" . str_replace( "'", "'\\''", $value ) . "'";
			};
			// --env doit être suivi d'une autre option avant le nom du serveur.
			$cli = 'claude mcp add --scope user'
				. ' --env ' . $quote( 'WP_API_URL=' . $endpoint )
				. ' --env ' . $quote( 'WP_API_USERNAME=' . $login )
				. ' --env ' . $quote( 'WP_API_PASSWORD=' . $password )
				. ' --transport stdio ' . $server . ' -- npx -y ' . $package;

			$prompts = array(
				__( 'Read the brand context of my site, then write a draft service page about indoor air audits.', 'leiho' ),
				__( 'Find my drafts in Leiho and fill in the empty fields of the most recent one.', 'leiho' ),
				__( 'List the content types and their fields, then create a draft FAQ entry from these notes: …', 'leiho' ),
			);
			if ( current_user_can( 'manage_options' ) ) {
				$prompts[] = __( 'Run the Leiho diagnostics and tell me what to fix, step by step.', 'leiho' );
			}
			?>
			<p class="leiho-help"><?php esc_html_e( 'Claude, Cursor and other AI agents can work on this site through MCP, with the rights of the user they connect as. Content abilities never publish: they read, and they write drafts only.', 'leiho' ); ?></p>

			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'Abilities API', 'leiho' ); ?></span>
				<div><span class="leiho-pill leiho-pill--ok"><?php esc_html_e( 'Available', 'leiho' ); ?></span></div>
			</div>
			<div class="leiho-row">
				<span class="leiho-row__label"><?php esc_html_e( 'MCP Adapter', 'leiho' ); ?></span>
				<div>
					<?php if ( $adapter ) : ?>
						<span class="leiho-pill leiho-pill--ok"><?php esc_html_e( 'Active', 'leiho' ); ?></span>
						<code><?php echo esc_html( $endpoint ); ?></code>
					<?php else : ?>
						<span class="leiho-pill leiho-pill--warn"><?php esc_html_e( 'Not active', 'leiho' ); ?></span>
						<span class="leiho-help"><?php esc_html_e( 'The official MCP Adapter plugin, by WordPress.org, turns these abilities into MCP tools. Install and activate it first.', 'leiho' ); ?></span>
						<a class="leiho-btn leiho-btn--quiet" href="<?php echo esc_url( $install ); ?>"><?php esc_html_e( 'Install MCP Adapter', 'leiho' ); ?></a>
					<?php endif; ?>
				</div>
			</div>

			<h3 class="leiho-ai-agents__title"><?php esc_html_e( 'Connect an AI agent', 'leiho' ); ?></h3>
			<ol class="leiho-steps leiho-ai-connect">
				<li class="leiho-step <?php echo $adapter ? 'leiho-step--done' : 'leiho-step--current'; ?>">
					<h3><?php esc_html_e( 'Install the MCP Adapter plugin', 'leiho' ); ?></h3>
					<?php if ( $adapter ) : ?>
						<p><?php esc_html_e( 'Done: the adapter is active and serves this site’s MCP server at the address above.', 'leiho' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Search for “MCP Adapter” in Plugins → Add New Plugin, install it and activate it. It adds the address AI clients connect to.', 'leiho' ); ?></p>
						<div class="leiho-actions">
							<a class="leiho-btn leiho-btn--small" href="<?php echo esc_url( $install ); ?>"><?php esc_html_e( 'Install MCP Adapter', 'leiho' ); ?></a>
						</div>
					<?php endif; ?>
				</li>

				<li class="leiho-step">
					<h3><?php esc_html_e( 'Create an application password', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'In your profile, under Application Passwords, add one named after the client (for example “Claude on my laptop”) and copy it: WordPress shows it only once. The site must use HTTPS.', 'leiho' ); ?></p>
					<p class="leiho-help"><?php esc_html_e( 'The agent acts with the rights of that user. Prefer a dedicated user with the Editor role or lower: content abilities only write drafts, and you can revoke the password at any time from the profile.', 'leiho' ); ?></p>
					<p class="leiho-help"><?php esc_html_e( 'Administration abilities (diagnostics, settings, secrets) work only when the agent connects as an administrator. Create an administrator application password just for a setup or debugging session, then revoke it.', 'leiho' ); ?></p>
					<div class="leiho-actions">
						<a class="leiho-btn leiho-btn--quiet leiho-btn--small" href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Open my application passwords', 'leiho' ); ?></a>
					</div>
				</li>

				<li class="leiho-step">
					<h3><?php esc_html_e( 'Add the site to your AI client', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'The configuration below is filled in with this site’s address and your username. Replace xxxx xxxx xxxx xxxx xxxx xxxx with the application password. Node.js must be installed on your computer: the client starts a small bridge with npx.', 'leiho' ); ?></p>
					<div class="leiho-ai-tabs">
						<div class="leiho-segmented" role="tablist" data-leiho-segmented>
							<button type="button" role="tab" aria-selected="true" data-panel="claude-desktop"><?php esc_html_e( 'Claude Desktop', 'leiho' ); ?></button>
							<button type="button" role="tab" aria-selected="false" data-panel="claude-code"><?php esc_html_e( 'Claude Code', 'leiho' ); ?></button>
							<button type="button" role="tab" aria-selected="false" data-panel="cursor"><?php esc_html_e( 'Cursor', 'leiho' ); ?></button>
						</div>
						<div data-panel-id="claude-desktop">
							<p class="leiho-help"><?php esc_html_e( 'In Claude Desktop, open Settings → Developer → Edit Config. Add this server to claude_desktop_config.json (inside mcpServers if the file already has some), save, then restart Claude.', 'leiho' ); ?></p>
							<div class="leiho-code">
								<pre><?php echo esc_html( $json ); ?></pre>
								<button type="button" data-leiho-copy="<?php echo esc_attr( $json ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
							</div>
						</div>
						<div data-panel-id="claude-code" hidden>
							<p class="leiho-help"><?php esc_html_e( 'Run this command in a terminal. --scope user makes the server available in all your projects; remove it to add it to the current project only.', 'leiho' ); ?></p>
							<div class="leiho-code">
								<pre><?php echo esc_html( $cli ); ?></pre>
								<button type="button" data-leiho-copy="<?php echo esc_attr( $cli ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
							</div>
						</div>
						<div data-panel-id="cursor" hidden>
							<p class="leiho-help"><?php esc_html_e( 'Add this server to .cursor/mcp.json in your project, or to ~/.cursor/mcp.json for all your projects, then reload Cursor.', 'leiho' ); ?></p>
							<div class="leiho-code">
								<pre><?php echo esc_html( $json ); ?></pre>
								<button type="button" data-leiho-copy="<?php echo esc_attr( $json ); ?>"><?php esc_html_e( 'Copy', 'leiho' ); ?></button>
							</div>
						</div>
					</div>
				</li>

				<li class="leiho-step">
					<h3><?php esc_html_e( 'Ask the agent', 'leiho' ); ?></h3>
					<p><?php esc_html_e( 'Start a new conversation and check the connection with “List the WordPress abilities you can use on my site.” Then, for example:', 'leiho' ); ?></p>
					<ul class="leiho-ai-prompts">
						<?php foreach ( $prompts as $prompt ) : ?>
							<li><?php echo esc_html( $prompt ); ?></li>
						<?php endforeach; ?>
					</ul>
				</li>
			</ol>

			<h3 class="leiho-ai-agents__title"><?php esc_html_e( 'Content', 'leiho' ); ?></h3>
			<ul class="leiho-ai-abilities">
				<?php foreach ( Leiho_AI_Abilities::definitions() as $name => $ability ) : ?>
					<li>
						<code><?php echo esc_html( $name ); ?></code>
						<span><?php echo esc_html( $ability['label'] ); ?></span>
						<?php if ( Leiho_AI_Abilities::ABILITY_ALT === $name && empty( $status['image'] ) ) : ?>
							<span class="leiho-muted"><?php esc_html_e( 'Needs an AI provider that reads images.', 'leiho' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h3 class="leiho-ai-agents__title"><?php esc_html_e( 'Site administration (administrators)', 'leiho' ); ?></h3>
			<ul class="leiho-ai-abilities">
				<?php foreach ( Leiho_AI_Admin_Abilities::definitions() as $name => $ability ) : ?>
					<li><code><?php echo esc_html( $name ); ?></code> <span><?php echo esc_html( $ability['label'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<p class="leiho-help"><?php esc_html_e( 'They never return a secret and never publish. Every change made by an agent is logged in Recent AI actions.', 'leiho' ); ?></p>
		</section>
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
