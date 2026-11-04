<?php
defined( 'ABSPATH' ) || exit;

/**
 * Journal des modifications : qui a changé quel champ, sur quel contenu,
 * quand, et la valeur avant / après.
 *
 * Table dédiée plutôt qu'une option : le journal grossit sans limite et ne
 * doit jamais être relu en entier pour ajouter une ligne.
 */
class Leiho_Activity {

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'leiho_activity_db_version';

	/** Au-delà, une valeur est tronquée dans le journal (le contenu, lui, est intact). */
	const MAX_VALUE_BYTES = 20000;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	public static function table_name() {
		global $wpdb;
		// Site venu d'Okno dont la base n'a pas pu renommer la table : on garde l'ancienne.
		return class_exists( 'Leiho_Legacy' ) ? Leiho_Legacy::table( 'activity' ) : $wpdb->prefix . 'leiho_activity';
	}

	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				activity_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				field_path varchar(191) NOT NULL,
				field_label varchar(191) NOT NULL DEFAULT '',
				field_type varchar(40) NOT NULL DEFAULT '',
				old_value longtext NOT NULL,
				new_value longtext NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_name varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (activity_id),
				KEY created_at (created_at),
				KEY post_id (post_id)
			) {$collate};"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Enregistre une modification. Ignorée si la valeur n'a pas changé.
	 *
	 * @param int    $post_id Contenu.
	 * @param string $path    Chemin du champ.
	 * @param string $label   Libellé lisible.
	 * @param string $type    Type Leiho du champ.
	 * @param mixed  $old     Valeur avant.
	 * @param mixed  $new     Valeur après.
	 * @return bool
	 */
	public static function record( $post_id, $path, $label, $type, $old, $new ) {
		global $wpdb;

		$old = self::serialize( $old );
		$new = self::serialize( $new );
		if ( $old === $new ) {
			return false;
		}

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table propre au plugin, insert() prépare les valeurs.
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'post_id'     => (int) $post_id,
				'field_path'  => substr( (string) $path, 0, 191 ),
				'field_label' => substr( sanitize_text_field( (string) $label ), 0, 191 ),
				'field_type'  => sanitize_key( (string) $type ),
				'old_value'   => $old,
				'new_value'   => $new,
				'user_id'     => get_current_user_id(),
				'user_name'   => $user && $user->exists() ? sanitize_text_field( $user->display_name ) : '',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $ok;
	}

	/** Type des lignes « action d'IA » du journal. */
	const TYPE_AI = 'ai';

	/**
	 * Enregistre une action d'IA : qui, sur quel champ, quelle action.
	 * Jamais le prompt ni le texte produit (le texte accepté apparaît dans
	 * le journal à l'enregistrement, comme toute modification).
	 *
	 * @param int    $post_id Contenu (0 : hors contenu, texte alternatif…).
	 * @param string $path    Chemin du champ.
	 * @param string $label   Libellé lisible.
	 * @param string $action  Action (rewrite, shorten, entry…).
	 * @return bool
	 */
	public static function record_ai( $post_id, $path, $label, $action ) {
		global $wpdb;

		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return false;
		}

		$user = wp_get_current_user();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table propre au plugin, insert() prépare les valeurs.
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'post_id'     => (int) $post_id,
				'field_path'  => substr( (string) $path, 0, 191 ),
				'field_label' => substr( sanitize_text_field( (string) $label ), 0, 191 ),
				'field_type'  => self::TYPE_AI,
				'old_value'   => '',
				'new_value'   => sanitize_key( (string) $action ),
				'user_id'     => get_current_user_id(),
				'user_name'   => $user && $user->exists() ? sanitize_text_field( $user->display_name ) : '',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Libellé lisible d'une action d'IA du journal.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public static function ai_label( $action ) {
		$labels = array(
			'generate'  => __( 'AI · Generate', 'leiho' ),
			'rewrite'   => __( 'AI · Rewrite', 'leiho' ),
			'shorten'   => __( 'AI · Shorten', 'leiho' ),
			'expand'    => __( 'AI · Expand', 'leiho' ),
			'fix'       => __( 'AI · Fix', 'leiho' ),
			'tone'      => __( 'AI · Change the tone', 'leiho' ),
			'translate' => __( 'AI · Translate', 'leiho' ),
			'suggest'   => __( 'AI · Suggest', 'leiho' ),
			'alt'       => __( 'AI · Alternative text', 'leiho' ),
			'entry'     => __( 'AI · New entry', 'leiho' ),
			'ability'   => __( 'AI agent · Fields updated', 'leiho' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : __( 'AI', 'leiho' );
	}

	/**
	 * Dernières modifications, plus récentes d'abord.
	 *
	 * @param int $limit   Nombre maximal.
	 * @param int $post_id Filtre optionnel sur un contenu.
	 * @return array<int,object>
	 */
	public static function recent( $limit = 50, $post_id = 0 ) {
		global $wpdb;

		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return array();
		}

		$limit = min( 200, max( 1, (int) $limit ) );
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nom de table interne.
		if ( $post_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY created_at DESC, activity_id DESC LIMIT %d", (int) $post_id, $limit )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, activity_id DESC LIMIT %d", $limit )
			);
		}
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Nombre de modifications depuis une date (tableau de bord).
	 *
	 * @param int $since Timestamp.
	 * @return int
	 */
	public static function count_since( $since ) {
		global $wpdb;

		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			return 0;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nom de table interne.
		// Les actions d'IA ne sont pas des modifications du contenu.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND field_type <> %s", gmdate( 'Y-m-d H:i:s', (int) $since ), self::TYPE_AI ) );
	}

	/**
	 * Valeur stockable : scalaire tel quel, structure en JSON lisible.
	 */
	public static function serialize( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_scalar( $value ) ) {
			$out = (string) $value;
		} else {
			$out = (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( strlen( $out ) > self::MAX_VALUE_BYTES ) {
			$out = substr( $out, 0, self::MAX_VALUE_BYTES ) . '…';
		}
		return $out;
	}

	/**
	 * Diff entre deux schémas pour les chemins écrits.
	 *
	 * Fonction pure, testable hors WordPress : prend les schémas avant/après
	 * (format Leiho_Schema::get) et rend les lignes à journaliser.
	 *
	 * @param array    $before Schéma avant écriture.
	 * @param array    $after  Schéma après écriture.
	 * @param string[] $paths  Chemins déclarés enregistrés.
	 * @return array<int,array{path:string,label:string,type:string,old:mixed,new:mixed}>
	 */
	public static function diff( array $before, array $after, array $paths ) {
		$rows = array();

		foreach ( $paths as $path ) {
			$old = self::lookup( $before, $path );
			$new = self::lookup( $after, $path );
			if ( null === $new['field'] ) {
				continue;
			}
			$rows[] = array(
				'path'  => $path,
				'label' => $new['label'],
				'type'  => $new['type'],
				'old'   => $old['value'],
				'new'   => $new['value'],
			);
		}

		return $rows;
	}

	/**
	 * Trouve un chemin pointé dans un schéma : valeur, libellé composé, type.
	 *
	 * @param array  $schema Schéma (format Leiho_Schema::get).
	 * @param string $path   Chemin pointé.
	 * @return array{field:array|null,value:mixed,label:string,type:string}
	 */
	public static function lookup( array $schema, $path ) {
		$parts = explode( '.', (string) $path );
		$root  = array_shift( $parts );
		$found = array(
			'field' => null,
			'value' => null,
			'label' => $path,
			'type'  => '',
		);

		foreach ( isset( $schema['groups'] ) ? $schema['groups'] : array() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( $field['path'] !== $root ) {
					continue;
				}
				$def    = $field;
				$value  = isset( $field['value'] ) ? $field['value'] : null;
				$labels = array( $field['label'] );

				while ( $parts ) {
					$seg = array_shift( $parts );
					if ( is_numeric( $seg ) && is_array( $value ) ) {
						$row      = isset( $value[ (int) $seg ] ) ? $value[ (int) $seg ] : null;
						$labels[] = '#' . ( (int) $seg + 1 );
						$value    = $row;
						$subs     = self::row_subs( $def, $row );
						$seg      = array_shift( $parts );
						if ( null === $seg ) {
							break;
						}
					} else {
						$subs = isset( $def['sub_fields'] ) ? $def['sub_fields'] : array();
					}
					$sub = null;
					foreach ( $subs as $candidate ) {
						if ( $candidate['path'] === $seg ) {
							$sub = $candidate;
							break;
						}
					}
					if ( ! $sub ) {
						return $found;
					}
					$def      = $sub;
					$labels[] = $sub['label'];
					$value    = is_array( $value ) && array_key_exists( $seg, $value ) ? $value[ $seg ] : null;
				}

				return array(
					'field' => $def,
					'value' => $value,
					'label' => implode( ' › ', $labels ),
					'type'  => $def['type'],
				);
			}
		}

		return $found;
	}

	private static function row_subs( $def, $row ) {
		if ( 'flexible_content' !== $def['type'] ) {
			return isset( $def['sub_fields'] ) ? $def['sub_fields'] : array();
		}
		$name = is_array( $row ) && isset( $row['_layout'] ) ? $row['_layout'] : '';
		foreach ( isset( $def['layouts'] ) ? $def['layouts'] : array() as $layout ) {
			if ( $layout['name'] === $name ) {
				return $layout['sub_fields'];
			}
		}
		return array();
	}
}
