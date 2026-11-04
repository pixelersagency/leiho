<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tables Leiho Collections : une table par collection, une ligne par entrée.
 *
 * L'état réel de la base (table, colonne de chaque clé de champ, colonnes
 * archivées) est tenu dans l'option leiho_collections_state. Une migration
 * compare le schéma demandé à cet état (plan(), pur), puis l'applique
 * (apply()) en mettant l'état à jour après chaque opération réussie : une
 * migration interrompue reprend où elle s'était arrêtée.
 *
 * Aucune donnée n'est jamais supprimée par une migration : un champ retiré ou
 * retypé garde sa colonne, archivée. Seul purge() supprime, sur demande.
 */
class Leiho_Collections_Store {

	const OPTION_STATE = 'leiho_collections_state';

	/** Lignes lues dans la requête : "collection:post_id" => ligne. */
	private static $rows = array();

	/** État mémorisé pour la requête. */
	private static $state = null;

	/* ---------------------------------------------------------------------
	 * État
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{collections:array}
	 */
	public static function state() {
		if ( null === self::$state ) {
			$state       = get_option( self::OPTION_STATE, array() );
			self::$state = is_array( $state ) && isset( $state['collections'] ) && is_array( $state['collections'] )
				? $state
				: array( 'collections' => array() );
		}
		return self::$state;
	}

	/**
	 * État d'une collection, null si sa table n'existe pas encore.
	 *
	 * @param string $id Collection.
	 * @return array|null
	 */
	public static function collection_state( $id ) {
		$state = self::state();
		return isset( $state['collections'][ $id ] ) ? $state['collections'][ $id ] : null;
	}

	private static function save_state( $state ) {
		self::$state = $state;
		update_option( self::OPTION_STATE, $state, true );
	}

	/**
	 * Vide les caches de la requête (tests, changement de schéma).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$rows  = array();
		self::$state = null;
	}

	/* ---------------------------------------------------------------------
	 * Identifiants SQL
	 * ------------------------------------------------------------------- */

	/**
	 * Identifiant SQL vérifié. Les noms viennent d'ids et de clés déjà
	 * validés ; cette garde refuse tout le reste avant qu'il n'atteigne SQL.
	 *
	 * @param string $name Nom de table, de colonne ou d'index.
	 * @return string
	 * @throws InvalidArgumentException Identifiant invalide.
	 */
	public static function ident( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name ) ) {
			throw new InvalidArgumentException( 'Invalid SQL identifier.' );
		}
		return '`' . $name . '`';
	}

	/**
	 * Nom complet de la table d'une collection.
	 *
	 * @param string $id Collection.
	 * @return string
	 */
	public static function table( $id ) {
		global $wpdb;
		return $wpdb->prefix . 'leiho_' . $id;
	}

	/**
	 * Nom d'index unique dans la base : SQLite partage les noms d'index entre tables.
	 *
	 * L'empreinte est calculée sur l'ancien nom de table (okno_…) : les index
	 * créés avant le renommage d'Okno en Leiho gardent leur nom après le
	 * renommage des tables, et restent donc retrouvables (DROP INDEX).
	 */
	public static function index_name( $table, $column ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
		$stable = 0 === strpos( $table, $prefix . 'leiho_' ) ? $prefix . 'okno_' . substr( $table, strlen( $prefix . 'leiho_' ) ) : $table;
		return 'k' . substr( md5( $stable . '.' . $column ), 0, 10 ) . '_' . substr( $column, 0, 50 );
	}

	/* ---------------------------------------------------------------------
	 * Plan de migration (pur)
	 * ------------------------------------------------------------------- */

	/**
	 * Opérations qui amènent la base au schéma d'une collection.
	 *
	 * @param array      $collection Collection normalisée.
	 * @param array|null $current    État de la collection (null : pas de table).
	 * @return array[] Opérations { op, collection, key?, column?, from?, sql?, index? }.
	 */
	public static function plan( $collection, $current ) {
		$id  = $collection['id'];
		$ops = array();

		$columns  = $current ? $current['columns'] : array();
		$archived = $current ? $current['archived'] : array();

		if ( null === $current ) {
			$ops[] = array(
				'op'         => 'create_table',
				'collection' => $id,
			);
		}

		// Colonnes déjà prises (actives ou archivées) : jamais réutilisées.
		$taken = array();
		foreach ( $columns as $col ) {
			$taken[ $col['column'] ] = true;
		}
		foreach ( $archived as $col ) {
			$taken[ $col['column'] ] = true;
		}

		$wanted = array();
		foreach ( $collection['fields'] as $field ) {
			$wanted[ $field['key'] ] = true;
			$sql                     = Leiho_Collections_Schema::column_type( $field );

			if ( isset( $columns[ $field['key'] ] ) ) {
				$col = $columns[ $field['key'] ];
				if ( $col['sql'] === $sql && $col['field']['type'] === $field['type'] ) {
					continue; // Renommage, libellé, options : rien en base.
				}
				$new   = self::free_column( $field['key'], $taken );
				$ops[] = array(
					'op'         => 'retype',
					'collection' => $id,
					'key'        => $field['key'],
					'from'       => $col['column'],
					'column'     => $new,
					'sql'        => $sql,
					'index'      => Leiho_Collections_Schema::is_indexed( $field ),
					'from_type'  => $col['field']['type'],
					'to_type'    => $field['type'],
				);
				continue;
			}

			// Champ retiré puis rajouté : sa colonne archivée revient, intacte.
			$restore = null;
			foreach ( array_reverse( $archived ) as $col ) {
				if ( $col['key'] === $field['key'] && $col['sql'] === $sql && $col['field']['type'] === $field['type'] ) {
					$restore = $col['column'];
					break;
				}
			}
			if ( null !== $restore ) {
				$ops[] = array(
					'op'         => 'restore',
					'collection' => $id,
					'key'        => $field['key'],
					'column'     => $restore,
				);
				continue;
			}

			$ops[] = array(
				'op'         => 'add_column',
				'collection' => $id,
				'key'        => $field['key'],
				'column'     => self::free_column( $field['key'], $taken ),
				'sql'        => $sql,
				'index'      => Leiho_Collections_Schema::is_indexed( $field ),
			);
		}

		foreach ( $columns as $key => $col ) {
			if ( ! isset( $wanted[ $key ] ) ) {
				$ops[] = array(
					'op'         => 'archive',
					'collection' => $id,
					'key'        => (string) $key,
					'column'     => $col['column'],
				);
			}
		}

		return $ops;
	}

	private static function free_column( $key, &$taken ) {
		$column = 'f_' . $key;
		$n      = 2;
		while ( isset( $taken[ $column ] ) ) {
			$column = 'f_' . $key . '__' . $n;
			$n++;
		}
		$taken[ $column ] = true;
		return $column;
	}

	/**
	 * Colonnes VARCHAR (actives et archivées) après un plan : la taille de
	 * ligne InnoDB compte aussi les colonnes archivées.
	 *
	 * @param array      $collection Collection normalisée.
	 * @param array|null $current    État.
	 * @return int
	 */
	public static function varchar_count( $collection, $current ) {
		$count = 0;
		foreach ( $collection['fields'] as $field ) {
			if ( 0 === strpos( Leiho_Collections_Schema::column_type( $field ), 'VARCHAR' ) ) {
				$count++;
			}
		}
		foreach ( $current ? $current['archived'] : array() as $col ) {
			if ( 0 === strpos( $col['sql'], 'VARCHAR' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Application
	 * ------------------------------------------------------------------- */

	/**
	 * Applique un plan. L'état est enregistré après chaque opération.
	 *
	 * @param array   $collection Collection normalisée.
	 * @param array[] $ops        Opérations de plan().
	 * @param array   $report     Rapport (entrée/sortie) : converted, warnings.
	 * @return true|WP_Error
	 */
	public static function apply( $collection, $ops, &$report ) {
		global $wpdb;

		$id     = $collection['id'];
		$table  = self::table( $id );
		$fields = array();
		foreach ( $collection['fields'] as $field ) {
			$fields[ $field['key'] ] = $field;
		}

		$state = self::state();
		if ( ! isset( $state['collections'][ $id ] ) ) {
			$state['collections'][ $id ] = array(
				'table'    => 'leiho_' . $id,
				'columns'  => array(),
				'archived' => array(),
			);
		}
		$entry = &$state['collections'][ $id ];

		foreach ( $ops as $op ) {
			switch ( $op['op'] ) {
				case 'create_table':
					$ok = self::query(
						'CREATE TABLE IF NOT EXISTS ' . self::ident( $table ) . ' ( `post_id` BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (`post_id`) ) ' . $wpdb->get_charset_collate()
					);
					break;

				case 'add_column':
					$ok = self::add_column( $table, $op['column'], $op['sql'], $op['index'] );
					if ( true === $ok ) {
						$entry['columns'][ $op['key'] ] = array(
							'column' => $op['column'],
							'sql'    => $op['sql'],
							'field'  => $fields[ $op['key'] ],
						);
					}
					break;

				case 'restore':
					$ok = true;
					foreach ( $entry['archived'] as $i => $col ) {
						if ( $col['column'] === $op['column'] ) {
							$entry['columns'][ $op['key'] ] = array(
								'column' => $col['column'],
								'sql'    => $col['sql'],
								'field'  => $fields[ $op['key'] ],
							);
							unset( $entry['archived'][ $i ] );
							$entry['archived'] = array_values( $entry['archived'] );
							break;
						}
					}
					break;

				case 'retype':
					$ok = self::add_column( $table, $op['column'], $op['sql'], $op['index'] );
					if ( true !== $ok ) {
						break;
					}
					$old    = $entry['columns'][ $op['key'] ];
					$counts = self::copy_column( $table, $old, $op['column'], $fields[ $op['key'] ] );
					if ( is_wp_error( $counts ) ) {
						$ok = $counts;
						break;
					}
					$report['converted'][ $id . '.' . $op['key'] ] = $counts;
					if ( $counts['failed'] > 0 ) {
						$report['warnings'][] = sprintf(
							/* translators: 1: number of entries, 2: field key, 3: archived column name. */
							__( '%1$d value(s) of “%2$s” could not be converted; they are kept in the archived column %3$s.', 'leiho' ),
							$counts['failed'],
							$op['key'],
							$old['column']
						);
					}
					$entry['archived'][]            = self::archived( $op['key'], $old, 'retyped' );
					$entry['columns'][ $op['key'] ] = array(
						'column' => $op['column'],
						'sql'    => $op['sql'],
						'field'  => $fields[ $op['key'] ],
					);
					break;

				case 'archive':
					$ok                  = true;
					$entry['archived'][] = self::archived( $op['key'], $entry['columns'][ $op['key'] ], 'removed' );
					unset( $entry['columns'][ $op['key'] ] );
					break;

				default:
					$ok = true;
			}

			if ( true !== $ok ) {
				self::save_state( $state );
				return is_wp_error( $ok ) ? $ok : new WP_Error(
					'leiho_migration_failed',
					/* translators: 1: operation, 2: database error. */
					sprintf( __( 'Migration stopped at “%1$s”: %2$s', 'leiho' ), $op['op'] . ( isset( $op['key'] ) ? ' ' . $op['key'] : '' ), $wpdb->last_error ),
					array( 'status' => 500 )
				);
			}
			$report['operations'][] = $op;
		}

		// Définitions à jour (libellés, options, renommages) même sans opération.
		foreach ( $entry['columns'] as $key => $col ) {
			if ( isset( $fields[ $key ] ) ) {
				$entry['columns'][ $key ]['field'] = $fields[ $key ];
			}
		}
		unset( $entry );

		self::save_state( $state );
		self::$rows = array();
		return true;
	}

	private static function archived( $key, $col, $reason ) {
		return array(
			'key'         => (string) $key,
			'column'      => $col['column'],
			'sql'         => $col['sql'],
			'field'       => $col['field'],
			'reason'      => $reason,
			'archived_at' => gmdate( 'c' ),
		);
	}

	private static function add_column( $table, $column, $sql, $index ) {
		// Type SQL issu de column_type() uniquement (liste fermée).
		if ( ! preg_match( '/^(VARCHAR\(\d+\)|TEXT|LONGTEXT|DECIMAL\(20,6\)|BIGINT\(20\)( UNSIGNED)?|TINYINT\(1\)|DATE|DATETIME)$/', $sql ) ) {
			return false;
		}
		$null = in_array( $sql, array( 'TEXT', 'LONGTEXT' ), true ) ? 'NULL' : 'NULL DEFAULT NULL';
		$ok   = self::query( 'ALTER TABLE ' . self::ident( $table ) . ' ADD COLUMN ' . self::ident( $column ) . ' ' . $sql . ' ' . $null );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( $index ) {
			return self::query( 'CREATE INDEX ' . self::ident( self::index_name( $table, $column ) ) . ' ON ' . self::ident( $table ) . ' (' . self::ident( $column ) . ')' );
		}
		return true;
	}

	/**
	 * Copie convertie d'une colonne vers une nouvelle, par lots.
	 *
	 * @return array{converted:int,failed:int}|WP_Error
	 */
	private static function copy_column( $table, $old, $column, $field ) {
		global $wpdb;

		$counts = array(
			'converted' => 0,
			'failed'    => 0,
		);
		$last   = 0;
		$t      = self::ident( $table );
		$from   = self::ident( $old['column'] );

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiants vérifiés par ident().
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `post_id`, {$from} AS v FROM {$t} WHERE `post_id` > %d AND {$from} IS NOT NULL ORDER BY `post_id` ASC LIMIT 500", $last ), ARRAY_A );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'leiho_migration_failed', $wpdb->last_error, array( 'status' => 500 ) );
			}
			foreach ( (array) $rows as $row ) {
				$last  = (int) $row['post_id'];
				$ok    = true;
				$value = Leiho_Collections_Values::convert( $row['v'], $old['field'], $field, $ok );
				if ( ! $ok ) {
					$counts['failed']++;
					continue;
				}
				if ( null === $value ) {
					continue;
				}
				$wpdb->update( $table, array( $column => $value ), array( 'post_id' => $last ), array( is_int( $value ) ? '%d' : '%s' ), array( '%d' ) );
				if ( $wpdb->last_error ) {
					return new WP_Error( 'leiho_migration_failed', $wpdb->last_error, array( 'status' => 500 ) );
				}
				$counts['converted']++;
			}
		} while ( count( (array) $rows ) === 500 );

		return $counts;
	}

	/**
	 * Supprime les colonnes archivées d'une collection (action explicite).
	 *
	 * @param string   $id      Collection.
	 * @param string[] $columns Colonnes à supprimer ; vide = toutes.
	 * @return string[]|WP_Error Colonnes supprimées.
	 */
	public static function purge( $id, $columns = array() ) {
		$state = self::state();
		if ( ! isset( $state['collections'][ $id ] ) ) {
			return array();
		}
		$entry   = $state['collections'][ $id ];
		$table   = self::table( $id );
		$dropped = array();
		$keep    = array();
		foreach ( $entry['archived'] as $col ) {
			if ( $columns && ! in_array( $col['column'], $columns, true ) ) {
				$keep[] = $col;
				continue;
			}
			// MySQL supprime l'index avec la colonne, SQLite exige de le retirer avant.
			if ( ! in_array( $col['sql'], array( 'TEXT', 'LONGTEXT', 'VARCHAR(32)' ), true ) ) {
				self::query( 'DROP INDEX ' . self::ident( self::index_name( $table, $col['column'] ) ) . ' ON ' . self::ident( $table ) );
			}
			$ok = self::query( 'ALTER TABLE ' . self::ident( $table ) . ' DROP COLUMN ' . self::ident( $col['column'] ) );
			if ( true !== $ok ) {
				$keep[] = $col;
				continue;
			}
			$dropped[] = $col['column'];
		}
		$state['collections'][ $id ]['archived'] = $keep;
		self::save_state( $state );
		self::$rows = array();
		return $dropped;
	}

	/**
	 * Supprime la table d'une collection et son état (retour à ACF d'une
	 * collection importée). La collection doit déjà être retirée du schéma.
	 *
	 * @param string $id Collection.
	 * @return bool
	 */
	public static function drop( $id ) {
		$state = self::state();
		if ( ! isset( $state['collections'][ $id ] ) ) {
			return true;
		}
		$ok = self::query( 'DROP TABLE IF EXISTS ' . self::ident( self::table( $id ) ) );
		if ( ! $ok ) {
			return false;
		}
		unset( $state['collections'][ $id ] );
		self::save_state( $state );
		foreach ( array_keys( self::$rows ) as $cache ) {
			if ( 0 === strpos( $cache, $id . ':' ) ) {
				unset( self::$rows[ $cache ] );
			}
		}
		return true;
	}

	/**
	 * @param string $sql Requête DDL (identifiants vérifiés, aucune valeur).
	 * @return bool
	 */
	private static function query( $sql ) {
		global $wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL sans valeur, identifiants vérifiés.
		return false !== $result && '' === (string) $wpdb->last_error;
	}

	/* ---------------------------------------------------------------------
	 * Lignes
	 * ------------------------------------------------------------------- */

	/**
	 * Ligne brute d'une entrée : colonne => valeur (vide si aucune ligne).
	 *
	 * @param string $id      Collection.
	 * @param int    $post_id Entrée.
	 * @return array<string,mixed>
	 */
	public static function row( $id, $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$cache   = $id . ':' . $post_id;
		if ( isset( self::$rows[ $cache ] ) ) {
			return self::$rows[ $cache ];
		}
		if ( ! self::collection_state( $id ) || $post_id <= 0 ) {
			return array();
		}
		$t   = self::ident( self::table( $id ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE `post_id` = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié.

		self::$rows[ $cache ] = is_array( $row ) ? $row : array();
		return self::$rows[ $cache ];
	}

	/**
	 * Valeurs stockées d'une entrée, indexées par clé de champ (colonnes actives).
	 *
	 * @param string $id      Collection.
	 * @param int    $post_id Entrée.
	 * @return array<string,mixed> clé => valeur de colonne.
	 */
	public static function values( $id, $post_id ) {
		$state = self::collection_state( $id );
		$row   = self::row( $id, $post_id );
		$out   = array();
		foreach ( $state ? $state['columns'] : array() as $key => $col ) {
			$out[ $key ] = array_key_exists( $col['column'], $row ) ? $row[ $col['column'] ] : null;
		}
		return $out;
	}

	/**
	 * Écrit des valeurs de colonne (clé de champ => valeur) sur une entrée.
	 *
	 * @param string $id      Collection.
	 * @param int    $post_id Entrée.
	 * @param array  $values  clé => valeur de colonne (null = vide).
	 * @return bool
	 */
	public static function write( $id, $post_id, $values ) {
		global $wpdb;

		$state = self::collection_state( $id );
		if ( ! $state || $post_id <= 0 ) {
			return false;
		}

		$data    = array();
		$formats = array();
		foreach ( $values as $key => $value ) {
			if ( ! isset( $state['columns'][ $key ] ) ) {
				continue;
			}
			$column = $state['columns'][ $key ]['column'];
			self::ident( $column );
			$data[ $column ] = $value;
			$formats[]       = is_int( $value ) ? '%d' : '%s';
		}
		if ( ! $data ) {
			return true;
		}

		$table  = self::table( $id );
		$exists = (bool) self::row( $id, $post_id );
		if ( ! $exists ) {
			// Ligne absente du cache ? Vérification en base (autre requête entre-temps).
			$t      = self::ident( $table );
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT `post_id` FROM {$t} WHERE `post_id` = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié.
		}

		if ( $exists ) {
			$result = $wpdb->update( $table, $data, array( 'post_id' => (int) $post_id ), $formats, array( '%d' ) );
		} else {
			$result = $wpdb->insert( $table, array_merge( array( 'post_id' => (int) $post_id ), $data ), array_merge( array( '%d' ), $formats ) );
		}

		unset( self::$rows[ $id . ':' . (int) $post_id ] );
		return false !== $result && '' === (string) $wpdb->last_error;
	}

	/**
	 * Supprime la ligne d'une entrée (suppression définitive du post).
	 *
	 * @param string $id      Collection.
	 * @param int    $post_id Entrée.
	 * @return void
	 */
	public static function delete( $id, $post_id ) {
		global $wpdb;
		if ( ! self::collection_state( $id ) ) {
			return;
		}
		$wpdb->delete( self::table( $id ), array( 'post_id' => (int) $post_id ), array( '%d' ) );
		unset( self::$rows[ $id . ':' . (int) $post_id ] );
	}
}
