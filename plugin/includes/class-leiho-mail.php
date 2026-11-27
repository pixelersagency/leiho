<?php
defined( 'ABSPATH' ) || exit;

/**
 * Envoi des e-mails de WordPress : serveur SMTP, expéditeur, journal des
 * envois et des échecs, statistiques. Couvre tout wp_mail() (formulaires,
 * mots de passe oubliés, commandes…), pas seulement les formulaires de Leiho.
 *
 * Le mot de passe SMTP est chiffré (Leiho_Secrets) ; la constante
 * LEIHO_SMTP_PASSWORD de wp-config.php, si elle existe, passe avant.
 */
class Leiho_Mail {

	const OPTION            = 'leiho_mail';
	const SECRET            = 'smtp_password';
	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'leiho_mail_db_version';
	const OPTION_FAILURES   = 'leiho_mail_failures';
	const ALERT_HOOK        = 'leiho_mail_alert_flush';

	/** Lignes du journal affichées par page (choix de l'utilisateur). */
	const PER_PAGE = array( 10, 25, 50, 100 );

	/** Durées de conservation du journal, en jours (0 : jusqu'à ce qu'on le vide). */
	const LOG_DAYS = array( 30, 90, 180, 365, 0 );

	/** Une alerte Slack / Telegram au plus sur cette durée ; les échecs suivants sont regroupés. */
	const ALERT_WINDOW = 3600;

	/** Extensions SMTP connues : dossier => nom. */
	const OTHER_PLUGINS = array(
		'wp-mail-smtp'      => 'WP Mail SMTP',
		'fluent-smtp'       => 'FluentSMTP',
		'post-smtp'         => 'Post SMTP',
		'easy-wp-smtp'      => 'Easy WP SMTP',
		'smtp-mailer'       => 'SMTP Mailer',
		'wp-smtp'           => 'WP SMTP',
		'gmail-smtp'        => 'Gmail SMTP',
		'mailgun'           => 'Mailgun',
		'sendgrid-email-delivery-simplified' => 'SendGrid',
		'wp-ses'            => 'WP Offload SES',
		'site-mailer'       => 'Site Mailer',
		'suremails'         => 'SureMail',
	);

	/** @var string|null Dernière erreur d'envoi (e-mail de test). */
	private static $last_error = null;

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 99 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ), 99 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 99 );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'log_sent' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failed' ) );
		add_action( 'leiho_cleanup_deploys', array( __CLASS__, 'prune' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( self::ALERT_HOOK, array( __CLASS__, 'flush_alert' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------- */

	/**
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'smtp'       => false,
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls', // none | ssl | tls.
			'auth'       => true,
			'username'   => '',
			'from_email' => '',
			'from_name'  => '',
			'force_from' => true, // Cet expéditeur pour tous les e-mails, même ceux d'autres extensions.
			'log'        => true,
			'log_days'   => 90, // Durée de conservation du journal (0 : toujours).
			'alert_slack'    => false,
			'alert_telegram' => false,
			'telegram_chat'  => '',
		);
		$saved    = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Saisie de l'écran, nettoyée. Une case absente = décochée.
	 *
	 * @param array $input Saisie.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$encryption = isset( $input['encryption'] ) ? (string) $input['encryption'] : 'tls';
		$from       = sanitize_email( isset( $input['from_email'] ) ? (string) $input['from_email'] : '' );
		$host       = strtolower( trim( (string) ( isset( $input['host'] ) ? $input['host'] : '' ) ) );
		return array(
			'smtp'       => ! empty( $input['smtp'] ),
			'host'       => preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ? $host : '',
			'port'       => min( 65535, max( 1, absint( isset( $input['port'] ) ? $input['port'] : 587 ) ) ),
			'encryption' => in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ? $encryption : 'tls',
			'auth'       => ! empty( $input['auth'] ),
			'username'   => trim( sanitize_text_field( isset( $input['username'] ) ? $input['username'] : '' ) ),
			'from_email' => is_email( $from ) ? $from : '',
			'from_name'  => trim( preg_replace( '/[\r\n"<>]+/', ' ', sanitize_text_field( isset( $input['from_name'] ) ? $input['from_name'] : '' ) ) ),
			'force_from' => ! empty( $input['force_from'] ),
			'log'        => ! empty( $input['log'] ),
			'log_days'   => isset( $input['log_days'] ) && in_array( (int) $input['log_days'], self::LOG_DAYS, true ) ? (int) $input['log_days'] : 90,
			'alert_slack'    => ! empty( $input['alert_slack'] ),
			'alert_telegram' => ! empty( $input['alert_telegram'] ),
			'telegram_chat'  => preg_match( '/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', trim( (string) ( isset( $input['telegram_chat'] ) ? $input['telegram_chat'] : '' ) ) ) ? trim( (string) $input['telegram_chat'] ) : '',
		);
	}

	/**
	 * Mot de passe SMTP : constante de wp-config.php, sinon secret chiffré.
	 *
	 * @return string
	 */
	public static function password() {
		if ( defined( 'LEIHO_SMTP_PASSWORD' ) ) {
			return (string) LEIHO_SMTP_PASSWORD;
		}
		return (string) Leiho_Secrets::get( self::SECRET );
	}

	/**
	 * Le SMTP de Leiho est actif et complet ?
	 *
	 * @return bool
	 */
	public static function active() {
		$s = self::settings();
		return $s['smtp'] && '' !== $s['host'];
	}

	/**
	 * Configure PHPMailer pour le serveur SMTP réglé.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer.
	 * @return void
	 */
	public static function configure( $phpmailer ) {
		if ( ! self::active() ) {
			return;
		}
		$s = self::settings();
		$phpmailer->isSMTP();
		$phpmailer->Host        = $s['host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Port        = (int) $s['port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPSecure  = 'none' === $s['encryption'] ? '' : $s['encryption']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAutoTLS = 'none' !== $s['encryption']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAuth    = (bool) $s['auth']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( $s['auth'] ) {
			$phpmailer->Username = $s['username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->Password = self::password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$phpmailer->Timeout = 15; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Adresse d'expéditeur réglée. Sans « pour tous les e-mails », seule
	 * l'adresse par défaut de WordPress (wordpress@…) est remplacée.
	 *
	 * @param string $email Adresse proposée.
	 * @return string
	 */
	public static function from_email( $email ) {
		$s = self::settings();
		if ( '' === $s['from_email'] ) {
			return $email;
		}
		return $s['force_from'] || 0 === strpos( (string) $email, 'wordpress@' ) ? $s['from_email'] : $email;
	}

	/**
	 * Nom d'expéditeur réglé (même règle que l'adresse).
	 *
	 * @param string $name Nom proposé.
	 * @return string
	 */
	public static function from_name( $name ) {
		$s = self::settings();
		if ( '' === $s['from_name'] ) {
			return $name;
		}
		return $s['force_from'] || 'WordPress' === $name ? $s['from_name'] : $name;
	}

	/**
	 * Autres extensions d'envoi actives : elles peuvent prendre la main sur
	 * wp_mail() avant ou après Leiho.
	 *
	 * @return string[] Noms.
	 */
	public static function other_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $active as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( isset( self::OTHER_PLUGINS[ $dir ] ) ) {
				$out[] = self::OTHER_PLUGINS[ $dir ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * wp_mail() remplacée par une extension (la fonction n'est plus celle de
	 * WordPress) : les réglages de Leiho ne s'appliquent alors peut-être pas.
	 *
	 * @return string Fichier qui la définit, '' si c'est celle de WordPress.
	 */
	public static function wp_mail_override() {
		if ( ! function_exists( 'wp_mail' ) || ! class_exists( 'ReflectionFunction' ) ) {
			return '';
		}
		$file = (string) ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
		return false !== strpos( wp_normalize_path( $file ), '/wp-includes/' ) ? '' : $file;
	}

	/* ---------------------------------------------------------------------
	 * Journal
	 * ------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'leiho_mail_log';
	}

	/**
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::install();
		}
	}

	/**
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				to_addr varchar(500) NOT NULL DEFAULT '',
				subject varchar(255) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT '',
				error text NOT NULL,
				PRIMARY KEY  (log_id),
				KEY created_at (created_at),
				KEY status (status)
			) {$collate};"
		);
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Destinataires d'un envoi, en texte.
	 *
	 * @param mixed $to Destinataires (chaîne ou liste).
	 * @return string
	 */
	public static function recipients( $to ) {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		$list = array_filter( array_map( 'trim', array_map( 'strval', $list ) ), 'strlen' );
		return implode( ', ', $list );
	}

	/**
	 * Ajoute une ligne au journal.
	 *
	 * @param string $status sent | failed.
	 * @param mixed  $to     Destinataires.
	 * @param string $subject Objet.
	 * @param string $error  Message d'erreur.
	 * @return void
	 */
	public static function log( $status, $to, $subject, $error = '' ) {
		$s = self::settings();
		if ( ! $s['log'] || self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table de Leiho.
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'to_addr'    => substr( self::recipients( $to ), 0, 500 ),
				'subject'    => substr( wp_strip_all_tags( (string) $subject ), 0, 255 ),
				'status'     => 'sent' === $status ? 'sent' : 'failed',
				'error'      => substr( (string) $error, 0, 2000 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		// Une fois sur vingt, en plus du cron horaire.
		if ( 0 === ( (int) $wpdb->insert_id % 20 ) ) {
			self::prune();
		}
	}

	/**
	 * wp_mail_succeeded.
	 *
	 * @param array $data to, subject, message, headers, attachments.
	 * @return void
	 */
	public static function log_sent( $data ) {
		$data = is_array( $data ) ? $data : array();
		self::log( 'sent', isset( $data['to'] ) ? $data['to'] : '', isset( $data['subject'] ) ? $data['subject'] : '' );
	}

	/**
	 * wp_mail_failed.
	 *
	 * @param WP_Error $error Erreur (données : to, subject…).
	 * @return void
	 */
	public static function log_failed( $error ) {
		$data             = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
		$message          = is_wp_error( $error ) ? $error->get_error_message() : '';
		self::$last_error = '' !== $message ? $message : __( 'Unknown error.', 'leiho' );
		self::log( 'failed', isset( $data['to'] ) ? $data['to'] : '', isset( $data['subject'] ) ? $data['subject'] : '', self::$last_error );
		self::note_failure( self::$last_error );
	}

	/**
	 * Supprime du journal les lignes plus vieilles que la durée choisie
	 * (réglage « Garder »). Rien n'est supprimé avec « jusqu'à ce que je le vide ».
	 *
	 * @return void
	 */
	public static function prune() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		global $wpdb;
		$days = (int) self::settings()['log_days'];
		if ( $days <= 0 ) {
			return;
		}
		$table  = self::table_name();
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho.
	}

	/**
	 * Libellé d'une durée de conservation du journal.
	 *
	 * @param int $days Jours (0 : jusqu'à ce qu'on le vide).
	 * @return string
	 */
	public static function retention_label( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return __( 'Until I clear it', 'leiho' );
		}
		if ( 365 === $days ) {
			return __( '1 year', 'leiho' );
		}
		if ( 0 === $days % 30 ) {
			$months = intdiv( $days, 30 );
			/* translators: %d: number of months. */
			return sprintf( _n( '%d month', '%d months', $months, 'leiho' ), $months );
		}
		/* translators: %d: number of days. */
		return sprintf( _n( '%d day', '%d days', $days, 'leiho' ), $days );
	}

	/**
	 * Lignes par page du journal : celle demandée (gardée pour
	 * l'utilisateur), sinon la dernière choisie, sinon 25.
	 *
	 * @param int $requested Choix dans l'URL (0 : aucun).
	 * @return int
	 */
	public static function per_page( $requested = 0 ) {
		$user = get_current_user_id();
		if ( in_array( (int) $requested, self::PER_PAGE, true ) ) {
			if ( $user ) {
				update_user_meta( $user, 'leiho_mail_per_page', (int) $requested );
			}
			return (int) $requested;
		}
		$saved = $user ? (int) get_user_meta( $user, 'leiho_mail_per_page', true ) : 0;
		return in_array( $saved, self::PER_PAGE, true ) ? $saved : 25;
	}

	/**
	 * Vide le journal.
	 *
	 * @return void
	 */
	public static function clear() {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho.
	}

	/**
	 * Dernières lignes du journal.
	 *
	 * @param string $status '' (tout) | sent | failed.
	 * @param int    $limit  Nombre.
	 * @param int    $offset Décalage.
	 * @return array<int,array{created_at:string,to_addr:string,subject:string,status:string,error:string}>
	 */
	public static function entries( $status = '', $limit = 50, $offset = 0 ) {
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return array();
		}
		global $wpdb;
		$table = self::table_name();
		$where = in_array( $status, array( 'sent', 'failed' ), true ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho, $where préparé.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, to_addr, subject, status, error FROM `{$table}` {$where} ORDER BY log_id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Statistiques : envoyés et échecs sur 7 et 30 jours, et par jour sur 14 jours.
	 *
	 * @return array{sent_7:int,failed_7:int,sent_30:int,failed_30:int,days:array<string,array{sent:int,failed:int}>,last_failed:string}
	 */
	public static function stats() {
		$out = array(
			'sent_7'      => 0,
			'failed_7'    => 0,
			'sent_30'     => 0,
			'failed_30'   => 0,
			'days'        => array(),
			'last_failed' => '',
		);
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		for ( $i = 13; $i >= 0; $i-- ) {
			$out['days'][ gmdate( 'Y-m-d', time() + $offset - $i * DAY_IN_SECONDS ) ] = array(
				'sent'   => 0,
				'failed' => 0,
			);
		}
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return $out;
		}
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, status, COUNT(*) AS n FROM `{$table}` WHERE created_at >= %s GROUP BY day, status", $offset, $since ), ARRAY_A );
		$week  = gmdate( 'Y-m-d', time() + $offset - 6 * DAY_IN_SECONDS );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = 'sent' === $row['status'] ? 'sent' : 'failed';
			$n   = (int) $row['n'];
			$out[ $key . '_30' ] += $n;
			if ( $row['day'] >= $week ) {
				$out[ $key . '_7' ] += $n;
			}
			if ( isset( $out['days'][ $row['day'] ] ) ) {
				$out['days'][ $row['day'] ][ $key ] += $n;
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho.
		$out['last_failed'] = (string) $wpdb->get_var( "SELECT created_at FROM `{$table}` WHERE status = 'failed' ORDER BY log_id DESC LIMIT 1" );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Alertes d'échec (wp-admin, Slack, Telegram)
	 * ------------------------------------------------------------------- */

	/**
	 * État des échecs : non vus dans l'admin, en attente d'alerte.
	 *
	 * @return array{unseen:int,last:int,last_error:string,pending:int,alerted_at:int}
	 */
	public static function failures() {
		$saved = get_option( self::OPTION_FAILURES, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'unseen'     => 0,
				'last'       => 0,
				'last_error' => '',
				'pending'    => 0,
				'alerted_at' => 0,
			)
		);
	}

	/**
	 * Erreur sans adresse e-mail (celle du visiteur, souvent) : l'alerte
	 * part vers un service tiers, elle ne transporte aucune donnée personnelle.
	 *
	 * @param string $error Erreur.
	 * @return string
	 */
	public static function scrub( $error ) {
		$error = preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[e-mail]', (string) $error );
		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 300 ) : substr( $error, 0, 300 );
	}

	/**
	 * Un envoi vient d'échouer : compté pour l'admin, et alerte Slack /
	 * Telegram tout de suite, ou regroupée si une alerte est partie il y a
	 * moins d'une heure (un SMTP en panne ne doit pas envoyer cent alertes).
	 *
	 * @param string $error Erreur.
	 * @return void
	 */
	public static function note_failure( $error ) {
		$state               = self::failures();
		$state['unseen']    += 1;
		$state['pending']   += 1;
		$state['last']       = time();
		$state['last_error'] = self::scrub( $error );
		update_option( self::OPTION_FAILURES, $state, false );

		if ( ! self::alerts_on() ) {
			return;
		}
		if ( time() - (int) $state['alerted_at'] >= self::ALERT_WINDOW ) {
			self::flush_alert();
		} elseif ( ! wp_next_scheduled( self::ALERT_HOOK ) ) {
			wp_schedule_single_event( (int) $state['alerted_at'] + self::ALERT_WINDOW, self::ALERT_HOOK );
		}
	}

	/**
	 * Une alerte au moins est réglée ?
	 *
	 * @return bool
	 */
	public static function alerts_on() {
		$s = self::settings();
		return ( $s['alert_slack'] && '' !== (string) Leiho_Secrets::get( 'slack_webhook' ) )
			|| ( $s['alert_telegram'] && '' !== $s['telegram_chat'] && '' !== (string) Leiho_Secrets::get( 'telegram_token' ) );
	}

	/**
	 * Envoie l'alerte des échecs en attente.
	 *
	 * @return void
	 */
	public static function flush_alert() {
		$state = self::failures();
		if ( $state['pending'] < 1 || ! self::alerts_on() ) {
			return;
		}
		$text                = self::alert_text( (int) $state['pending'], $state['last_error'] );
		$state['pending']    = 0;
		$state['alerted_at'] = time();
		update_option( self::OPTION_FAILURES, $state, false );
		self::send_alert( $text );
	}

	/**
	 * Texte de l'alerte : site, nombre d'échecs, dernière erreur, lien.
	 *
	 * @param int    $count Échecs.
	 * @param string $error Dernière erreur (sans adresse).
	 * @return string
	 */
	public static function alert_text( $count, $error ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$text = sprintf(
			/* translators: 1: site name, 2: number of e-mails. */
			_n( '%1$s: %2$d e-mail could not be sent.', '%1$s: %2$d e-mails could not be sent.', $count, 'leiho' ),
			$site,
			$count
		);
		if ( '' !== $error ) {
			/* translators: %s: error message. */
			$text .= "\n" . sprintf( __( 'Last error: %s', 'leiho' ), $error );
		}
		$text .= "\n" . __( 'Form messages are kept in WordPress. Details:', 'leiho' ) . ' ' . Leiho_App::url( 'forms', array( 'tab' => 'mail' ) );
		return $text;
	}

	/**
	 * Envoie un texte aux alertes réglées.
	 *
	 * @param string $text Texte.
	 * @return array<string,true|string> Canal => true ou erreur.
	 */
	public static function send_alert( $text ) {
		$s   = self::settings();
		$out = array();
		$hook = (string) Leiho_Secrets::get( 'slack_webhook' );
		if ( $s['alert_slack'] && '' !== $hook ) {
			$out['slack'] = self::post_json( $hook, array( 'text' => $text ) );
		}
		$token = (string) Leiho_Secrets::get( 'telegram_token' );
		if ( $s['alert_telegram'] && '' !== $token && '' !== $s['telegram_chat'] ) {
			// Jeton de @BotFather : 123456:ABC-def… (rien d'autre dans l'adresse).
			$out['telegram'] = ! preg_match( '/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/', $token ) ? __( 'The bot token does not look right (expected 123456:ABC…).', 'leiho' ) : self::post_json(
				'https://api.telegram.org/bot' . $token . '/sendMessage',
				array(
					'chat_id'                  => $s['telegram_chat'],
					'text'                     => $text,
					'disable_web_page_preview' => true,
				)
			);
		}
		return $out;
	}

	/**
	 * POST JSON vers un service d'alerte.
	 *
	 * @param string $url  Adresse (https).
	 * @param array  $body Corps.
	 * @return true|string
	 */
	private static function post_json( $url, array $body ) {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return __( 'The address must start with https://.', 'leiho' );
		}
		$res = Leiho_Plugin::remote(
			'POST',
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'The service answered with error %d.', 'leiho' ), $code );
		}
		return true;
	}

	/**
	 * Échecs vus : l'avertissement de wp-admin disparaît.
	 *
	 * @return void
	 */
	public static function mark_seen() {
		$state = self::failures();
		if ( $state['unseen'] ) {
			$state['unseen'] = 0;
			update_option( self::OPTION_FAILURES, $state, false );
		}
	}

	/**
	 * Avertissement dans wp-admin tant que des échecs n'ont pas été vus.
	 *
	 * @return void
	 */
	public static function admin_notice() {
		$state = self::failures();
		if ( ! $state['unseen'] || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of e-mails. */
					_n( 'Leiho: %d e-mail could not be sent.', 'Leiho: %d e-mails could not be sent.', (int) $state['unseen'], 'leiho' ),
					(int) $state['unseen']
				)
			),
			esc_html__( 'Form messages are kept in WordPress.', 'leiho' ),
			esc_url( Leiho_App::url( 'forms', array( 'tab' => 'mail' ) ) ),
			esc_html__( 'See the e-mail log', 'leiho' )
		);
	}

	/* ---------------------------------------------------------------------
	 * E-mail de test
	 * ------------------------------------------------------------------- */

	/**
	 * Envoie un e-mail de test.
	 *
	 * @param string $to Destinataire.
	 * @return true|string True, ou le message d'erreur.
	 */
	public static function send_test( $to ) {
		if ( ! is_email( $to ) ) {
			return __( 'Enter a valid e-mail address.', 'leiho' );
		}
		self::$last_error = null;
		$site             = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$ok               = wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Test e-mail from Leiho', 'leiho' ), $site ),
			/* translators: %s: site name. */
			sprintf( __( "This test e-mail was sent by Leiho from %s.\n\nIf you are reading it, e-mail sending works.", 'leiho' ), home_url() )
		);
		if ( $ok ) {
			return true;
		}
		return null !== self::$last_error ? self::$last_error : __( 'The e-mail could not be sent.', 'leiho' );
	}

	/* ---------------------------------------------------------------------
	 * Données personnelles
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $erasers Effaceurs.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['leiho-mail-log'] = array(
			'eraser_friendly_name' => __( 'Leiho e-mail log', 'leiho' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Lignes du journal envoyées à cette adresse.
	 *
	 * @param string $email Adresse.
	 * @return array
	 */
	public static function erase_personal_data( $email ) {
		$removed = 0;
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) && is_email( $email ) ) {
			global $wpdb;
			$table   = self::table_name();
			$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE to_addr LIKE %s", '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table de Leiho.
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
