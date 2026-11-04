<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestration des deploys : locks, rate limit, historique.
 */
class Okno_Deploy_Manager {

	const OPTION_HISTORY  = 'okno_deploy_history';
	const TRANSIENT_SAVE  = 'okno_saving';
	const HISTORY_MAX     = 20;
	const CRON_HOOK       = 'okno_cleanup_deploys';
	const AUTO_HOOK       = 'okno_auto_deploy';
	const AUTO_ATTEMPTS   = 'okno_auto_deploy_attempts';
	const OPTION_PENDING  = 'okno_auto_deploy_pending';
	const OPTION_LOCK     = 'okno_deploy_lock';

	/** Au-delà, un verrou de deploy est abandonné (fatal en plein appel au driver). */
	const LOCK_TTL = 120;

	/** Codes d'erreur passagers : la publication reste en attente. */
	const RETRY_CODES = array( 'okno_save_in_progress', 'okno_deploy_in_progress', 'okno_rate_limited' );

	/** Essais quand le service de build ne répond pas, avant d'abandonner. */
	const SERVICE_RETRIES = 5;

	/** Publication automatique déjà replanifiée dans cette requête. */
	private static $auto_queued = false;

	/** Au-delà, un deploy sans statut connu est considéré comme perdu. */
	const STALE_AFTER = 1800;

	/** Statuts considérés comme "en cours". */
	const ACTIVE_STATUSES = array( 'pending', 'building' );

	/**
	 * Driver configuré.
	 *
	 * @return Okno_Deploy_Driver_Interface|null
	 */
	public static function driver() {
		$settings = Okno_Plugin::settings();
		switch ( $settings['deploy_driver'] ) {
			case 'build_hook':
				return new Okno_Build_Hook_Driver();
			case 'coolify':
				return new Okno_Coolify_Driver();
			case 'github':
				return new Okno_Github_Actions_Driver();
			case 'github_commit':
				return new Okno_Github_Commit_Driver();
		}
		/**
		 * Permet d'enregistrer un driver custom.
		 *
		 * @param Okno_Deploy_Driver_Interface|null $driver
		 * @param string                            $driver_id Valeur du réglage.
		 */
		return apply_filters( 'okno_deploy_driver', null, $settings['deploy_driver'] );
	}

	/**
	 * Déclenche un déploiement (avec tous les garde-fous).
	 *
	 * @return array|WP_Error Record du deploy.
	 */
	public static function trigger() {
		// Un seul déclenchement à la fois : le bouton « Publier », le vidage
		// de la publication automatique (éditeur, onglet fermé, heartbeat) et
		// le cron peuvent tomber dans la même seconde.
		if ( ! self::lock() ) {
			return self::busy_error();
		}
		try {
			return self::run_trigger( false );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Déclenchement proprement dit, verrou déjà pris.
	 *
	 * @param bool $automatic Publication automatique (libellé de l'historique).
	 * @return array|WP_Error Record du deploy.
	 */
	private static function run_trigger( $automatic ) {
		// Changements en attente vus avant le déclenchement : le build les
		// contiendra, on pourra les effacer s'ils n'ont pas bougé entre-temps.
		$pending = self::pending_marker();

		// Jamais de deploy pendant qu'un save écrit en DB.
		if ( get_transient( self::TRANSIENT_SAVE ) ) {
			return new WP_Error(
				'okno_save_in_progress',
				__( 'A save is in progress. Try again in a few seconds.', 'okno' ),
				array( 'status' => 409 )
			);
		}

		// Un deploy actif bloque le suivant.
		$active = self::active_deploy();
		if ( $active ) {
			return self::busy_error();
		}

		// Rate limit.
		$settings = Okno_Plugin::settings();
		$history  = self::history();
		$last     = reset( $history );
		$interval = max( 0, (int) $settings['min_deploy_interval'] );
		if ( $last && $interval > 0 && ( time() - $last['started'] ) < $interval ) {
			return new WP_Error(
				'okno_rate_limited',
				sprintf( /* translators: %d: number of seconds. */ __( 'Please wait %d s between deployments.', 'okno' ), $interval ),
				array(
					'status'      => 429,
					'retry_after' => max( 1, $interval - ( time() - $last['started'] ) ),
				)
			);
		}

		$driver = self::driver();
		if ( ! $driver ) {
			return new WP_Error(
				'okno_deploy_config',
				__( 'No deployment method set in Okno settings.', 'okno' ),
				array( 'status' => 400 )
			);
		}

		$config = $driver->check_config();
		if ( is_wp_error( $config ) ) {
			$config->add_data( array( 'status' => 400 ) );
			return $config;
		}

		$user = wp_get_current_user();
		if ( $automatic || ! $user->ID ) {
			// Publication automatique : le cron n'a pas d'utilisateur, et un
			// vidage depuis le navigateur n'est pas un clic sur « Publier ».
			$user               = clone $user;
			$user->display_name = __( 'Automatic publishing', 'okno' );
		}
		$record = array(
			// G : identifiant non devinable (uniqid() est prévisible).
			'id'        => 'dep_' . wp_generate_password( 16, false ),
			'driver'    => $driver->get_id(),
			'user_id'   => $user->ID,
			'user_name' => $user->display_name,
			'started'   => time(),
			'status'    => 'pending',
			'message'   => '',
			'eta'       => 0,
			'meta'      => array(),
		);

		$result = $driver->trigger( $record );

		if ( is_wp_error( $result ) ) {
			$record['status']  = 'error';
			$record['message'] = $result->get_error_message();
			self::push_history( $record );
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		self::push_history( $result );
		Okno_Plugin::bump_usage( 'publish' );
		self::clear_pending( $pending );
		return $result;
	}

	/**
	 * Erreur « un deploy est déjà en cours ».
	 *
	 * @return WP_Error
	 */
	private static function busy_error() {
		return new WP_Error(
			'okno_deploy_in_progress',
			__( 'A deployment is already in progress.', 'okno' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Publication automatique possible : réglage coché et mode qui publie
	 * vraiment (pas « Contenu en direct », pas de mode vide).
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		$settings = Okno_Plugin::settings();
		return ! empty( $settings['auto_deploy'] ) && '' !== $settings['deploy_driver'] && null !== self::driver();
	}

	/**
	 * Délai de calme avant la publication automatique, en secondes.
	 *
	 * @return int
	 */
	public static function auto_delay() {
		$settings = Okno_Plugin::settings();
		return max( 15, (int) $settings['auto_deploy_delay'] );
	}

	/**
	 * L'utilisateur courant peut-il publier ?
	 *
	 * @return bool
	 */
	public static function user_can_deploy() {
		/**
		 * Capability requise pour publier (déclencher un deploy).
		 *
		 * @param string $capability Défaut : publish_pages (éditeur de site vitrine).
		 */
		return current_user_can( apply_filters( 'okno_deploy_capability', 'publish_pages' ) );
	}

	/**
	 * Contenu modifié : publie automatiquement après un délai de calme.
	 *
	 * Chaque modification repousse l'échéance : une série de changements
	 * (un client qui retouche dix champs) donne un seul déploiement.
	 *
	 * Trois déclencheurs, du plus rapide au plus sûr : l'éditeur Okno (minuterie
	 * côté navigateur, et envoi immédiat quand l'onglet se ferme), le heartbeat
	 * de wp-admin, puis le cron WordPress. Le marqueur « en attente » qu'ils
	 * partagent garantit un seul déploiement par lot de changements.
	 *
	 * @return void
	 */
	public static function queue_auto() {
		if ( self::$auto_queued ) {
			return;
		}
		// Sans publication automatique, le marqueur sert quand même : la barre
		// d'Okno signale les changements pas encore publiés (« Publier » l'efface).
		$auto = self::auto_enabled();
		if ( ! $auto && null === self::driver() ) {
			return;
		}
		self::$auto_queued = true;

		// Une seule replanification par requête, en fin de requête : un save
		// ACF écrit des dizaines de métas, chacune signalerait un changement.
		add_action(
			'shutdown',
			static function () use ( $auto ) {
				Okno_Deploy_Manager::mark_pending();
				if ( $auto ) {
					Okno_Deploy_Manager::reschedule_auto( 0 );
				}
			}
		);
	}

	/**
	 * Note l'heure du dernier changement non publié.
	 *
	 * Option non autoloadée : lue seulement quand on en a besoin.
	 *
	 * @return void
	 */
	public static function mark_pending() {
		update_option( self::OPTION_PENDING, sprintf( '%.4F', microtime( true ) ), false );
	}

	/**
	 * Valeur brute du marqueur, relue en base (un autre processus a pu
	 * publier depuis le début de la requête).
	 *
	 * @return string '' si rien n'attend.
	 */
	private static function pending_marker() {
		wp_cache_delete( self::OPTION_PENDING, 'options' );
		$value = get_option( self::OPTION_PENDING, '' );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Heure du dernier changement non publié.
	 *
	 * @return int Timestamp, 0 si rien n'attend.
	 */
	public static function pending_since() {
		return (int) floor( (float) self::pending_marker() );
	}

	/**
	 * Efface le marqueur après un déploiement réussi, sauf si un changement
	 * est arrivé pendant le déclenchement (il garde alors sa propre échéance).
	 *
	 * @param string $seen Valeur lue avant le déclenchement.
	 * @return void
	 */
	private static function clear_pending( $seen ) {
		if ( '' === $seen || self::pending_marker() !== $seen ) {
			return;
		}
		delete_option( self::OPTION_PENDING );
		delete_option( self::AUTO_ATTEMPTS );
		// Le cron de secours n'a plus rien à faire.
		wp_clear_scheduled_hook( self::AUTO_HOOK );
	}

	/**
	 * Abandonne la publication en attente (erreur définitive, réglage coupé).
	 *
	 * @return void
	 */
	private static function drop_pending() {
		delete_option( self::OPTION_PENDING );
		delete_option( self::AUTO_ATTEMPTS );
		wp_clear_scheduled_hook( self::AUTO_HOOK );
	}

	/**
	 * Publie maintenant si une publication automatique attend.
	 *
	 * Idempotent : appelé deux fois (minuterie de l'éditeur, onglet fermé,
	 * heartbeat, cron), il ne publie qu'une fois. Les garde-fous de trigger()
	 * (save en cours, deploy actif, rate limit) laissent la publication en
	 * attente : le cron ou le prochain vidage réessaiera.
	 *
	 * @param int $min_age Âge minimal du dernier changement, en secondes
	 *                     (0 : publier tout de suite).
	 * @return array{state:string,retry_in?:int,record?:array,error?:WP_Error}
	 *               state : idle (rien n'attend), waiting (trop tôt),
	 *               pending (obstacle passager), published, failed.
	 */
	public static function flush_pending( $min_age = 0 ) {
		$since = self::pending_since();
		if ( ! $since ) {
			return array( 'state' => 'idle' );
		}

		$age = time() - $since;
		if ( $min_age > 0 && $age < $min_age ) {
			return array(
				'state'    => 'waiting',
				'retry_in' => $min_age - $age,
			);
		}

		if ( ! self::auto_enabled() ) {
			// Réglage décoché depuis le changement : plus rien à publier tout seul.
			self::drop_pending();
			return array( 'state' => 'idle' );
		}

		if ( ! self::lock() ) {
			return array(
				'state'    => 'pending',
				'retry_in' => 15,
				'error'    => self::busy_error(),
			);
		}
		try {
			// Relecture sous verrou : un autre processus vient peut-être de publier.
			$result = self::pending_since() ? self::run_trigger( true ) : null;
		} finally {
			self::unlock();
		}

		if ( null === $result ) {
			return array( 'state' => 'idle' );
		}

		if ( ! is_wp_error( $result ) ) {
			return array(
				'state'  => 'published',
				'record' => $result,
			);
		}

		if ( in_array( $result->get_error_code(), self::RETRY_CODES, true ) ) {
			// Filet de sécurité : le cron réessaiera si plus personne n'appelle.
			if ( ! wp_next_scheduled( self::AUTO_HOOK ) ) {
				self::reschedule_auto( (int) get_option( self::AUTO_ATTEMPTS, 0 ) + 1 );
			}
			$data = $result->get_error_data();
			return array(
				'state'    => 'pending',
				'retry_in' => is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 15,
				'error'    => $result,
			);
		}

		// Service de build injoignable ou en erreur (HTTP 502 posé par
		// trigger()) : souvent passager (coupure réseau, GitHub ou Netlify
		// en difficulté). Les modifications restent en attente et le cron
		// retente plus tard, quelques fois seulement.
		$data     = $result->get_error_data();
		$attempts = (int) get_option( self::AUTO_ATTEMPTS, 0 );
		if ( is_array( $data ) && isset( $data['status'] ) && 502 === (int) $data['status'] && $attempts < self::SERVICE_RETRIES ) {
			self::reschedule_auto( $attempts + 1 );
			return array(
				'state'    => 'pending',
				'retry_in' => self::auto_delay(),
				'error'    => $result,
			);
		}

		// Erreur définitive (réglages, ou service toujours en échec après
		// plusieurs essais) : visible dans l'historique ; réessayer à chaque
		// heartbeat n'y changerait rien.
		self::drop_pending();
		return array(
			'state' => 'failed',
			'error' => $result,
		);
	}

	/**
	 * (Re)planifie la publication automatique.
	 *
	 * @param int $attempt Tentatives déjà faites pour ce lot de changements.
	 * @return void
	 */
	public static function reschedule_auto( $attempt ) {
		wp_clear_scheduled_hook( self::AUTO_HOOK );
		update_option( self::AUTO_ATTEMPTS, (int) $attempt, false );
		wp_schedule_single_event( time() + self::auto_delay(), self::AUTO_HOOK );
	}

	/**
	 * Cron : filet de sécurité de la publication automatique. Ne fait rien si
	 * le navigateur ou le heartbeat a déjà publié ; repousse si un build tourne
	 * déjà (il ne contiendrait pas les derniers changements).
	 *
	 * @return void
	 */
	public static function run_auto() {
		$attempt = (int) get_option( self::AUTO_ATTEMPTS, 0 );
		$outcome = self::flush_pending( 0 );

		if ( 'pending' === $outcome['state'] && $attempt < 20 ) {
			self::reschedule_auto( $attempt + 1 );
			return;
		}
		if ( 'pending' === $outcome['state'] ) {
			// Vingt tentatives sans succès : on abandonne ce lot.
			self::drop_pending();
		}
		delete_option( self::AUTO_ATTEMPTS );
	}

	/**
	 * Heartbeat de wp-admin (toutes les 15 à 120 s tant qu'un onglet est
	 * ouvert) : publie quand le délai de calme est écoulé, sans requête de
	 * plus ni dépendance au cron (désactivé ou bloqué chez certains hébergeurs).
	 *
	 * @param array $response Réponse heartbeat.
	 * @return array
	 */
	public static function heartbeat( $response ) {
		if ( ! is_array( $response ) || ! current_user_can( 'edit_posts' ) || ! self::auto_enabled() ) {
			return $response;
		}

		// Même travail que le cron, à la même échéance : pas besoin de la
		// capability de publication.
		$outcome = self::flush_pending( self::auto_delay() );
		$state   = array(
			'pending' => in_array( $outcome['state'], array( 'waiting', 'pending' ), true ),
		);
		if ( isset( $outcome['retry_in'] ) ) {
			$state['due_in'] = (int) $outcome['retry_in'];
		}
		if ( 'published' === $outcome['state'] ) {
			$state['deploy_id'] = $outcome['record']['id'];
		}
		$response['okno_autopublish'] = $state;
		return $response;
	}

	/**
	 * Verrou de déclenchement, atomique (INSERT IGNORE, comme le verrou des
	 * mises à jour de WordPress). Un transient ne suffit pas : lire puis
	 * écrire laisse passer deux processus dans la même milliseconde.
	 *
	 * @return bool Verrou obtenu.
	 */
	private static function lock() {
		global $wpdb;

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION_LOCK,
				$now
			)
		);
		if ( $inserted ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK ) );
		if ( null === $held ) {
			// Libéré entre-temps : on retente une fois.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
					self::OPTION_LOCK,
					$now
				)
			);
		}
		if ( $now - (int) $held < self::LOCK_TTL ) {
			return false;
		}

		// Verrou abandonné (fatal pendant l'appel au driver) : on le reprend,
		// à condition que personne ne l'ait repris avant nous.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$now,
				self::OPTION_LOCK,
				$held
			)
		);
	}

	/**
	 * Libère le verrou.
	 *
	 * @return void
	 */
	private static function unlock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION_LOCK ) );
	}

	/**
	 * Statut d'un deploy, rafraîchi via le driver si nécessaire.
	 *
	 * @param string $deploy_id Identifiant Okno.
	 * @return array|WP_Error
	 */
	public static function get_status( $deploy_id ) {
		$history = self::history();
		if ( ! isset( $history[ $deploy_id ] ) ) {
			return new WP_Error( 'okno_not_found', __( 'Unknown deployment.', 'okno' ), array( 'status' => 404 ) );
		}

		$record = $history[ $deploy_id ];

		if ( in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
			$before = $record;
			$driver = self::driver();
			if ( $driver && $driver->get_id() === $record['driver'] && $driver->supports_status() ) {
				$record = $driver->get_status( $record );
			}

			$record = self::expire_if_stale( $record );

			// B4 : le polling écrivait en base à chaque appel, pendant tout le build.
			if ( $record !== $before ) {
				$history[ $deploy_id ] = $record;
				update_option( self::OPTION_HISTORY, $history, false );
			}
		}

		return $record;
	}

	/**
	 * Un deploy actif trop vieux est perdu : sans cette sortie, `trigger()`
	 * refuse tout nouveau déploiement pour toujours.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private static function expire_if_stale( array $record ) {
		if ( ! in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
			return $record;
		}
		if ( ( time() - $record['started'] ) <= self::STALE_AFTER ) {
			return $record;
		}

		$record['status']  = 'error';
		$record['message'] = __( 'No status after 30 minutes: deployment marked as lost.', 'okno' );
		return $record;
	}

	/**
	 * B3 : nettoyage planifié. Sans lui, un onglet fermé pendant un build laisse
	 * un record « pending » éternel, et le client ne peut plus publier.
	 *
	 * @return void
	 */
	public static function cleanup_stale() {
		$history = self::history();
		$changed = false;

		foreach ( $history as $id => $record ) {
			$fresh = self::expire_if_stale( $record );
			if ( $fresh !== $record ) {
				$history[ $id ] = $fresh;
				$changed        = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_HISTORY, $history, false );
		}
	}

	/**
	 * Planifie le nettoyage (activation du plugin).
	 *
	 * @return void
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Déplanifie le nettoyage (désactivation).
	 *
	 * @return void
	 */
	public static function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::AUTO_HOOK );
		delete_option( self::OPTION_PENDING );
	}

	/**
	 * Deploy actif (pending/building), statut rafraîchi.
	 *
	 * @return array|null
	 */
	public static function active_deploy() {
		foreach ( self::history() as $record ) {
			if ( in_array( $record['status'], self::ACTIVE_STATUSES, true ) ) {
				$fresh = self::get_status( $record['id'] );
				if ( ! is_wp_error( $fresh ) && in_array( $fresh['status'], self::ACTIVE_STATUSES, true ) ) {
					return $fresh;
				}
			}
		}
		return null;
	}

	/**
	 * Historique, plus récent en premier, indexé par id.
	 *
	 * @return array<string,array>
	 */
	public static function history() {
		$history = get_option( self::OPTION_HISTORY, array() );
		return is_array( $history ) ? $history : array();
	}

	private static function push_history( array $record ) {
		$history = self::history();
		$history = array( $record['id'] => $record ) + $history;
		if ( count( $history ) > self::HISTORY_MAX ) {
			$history = array_slice( $history, 0, self::HISTORY_MAX, true );
		}
		update_option( self::OPTION_HISTORY, $history, false );
	}
}
