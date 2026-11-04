<?php
defined( 'ABSPATH' ) || exit;

/**
 * Localisation des Leiho Collections, champ par champ (comme Sanity ou Contentful).
 *
 * Une entrée, plusieurs langues. La langue par défaut reste dans la table de la
 * collection, inchangée ; les autres langues vivent dans une table commune,
 * {prefix}leiho_l10n, une ligne par entrée et par langue : titre, valeurs des
 * champs traduisibles (format de stockage de leur colonne, en JSON) et empreinte
 * des valeurs d'origine au moment de la traduction (traduction « à revoir »
 * quand l'original a changé depuis).
 *
 * Les champs non traduisibles (image, prix, date…) sont partagés : les modifier
 * dans une langue les modifie partout.
 *
 * Les langues se déclarent dans le schéma (leiho.schema.json, clé « i18n ») :
 * elles voyagent avec le modèle de contenu.
 */
class Leiho_I18n {

	/** Code de langue : en, fr, pt-BR, zh-Hant… */
	const CODE_PATTERN = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

	/** Langue de la requête (null : langue par défaut). */
	private static $current = null;

	/** Lignes lues dans la requête : "post_id:locale" => ligne. */
	private static $rows = array();

	/** Table vérifiée dans la requête. */
	private static $table_ready = false;

	/* ---------------------------------------------------------------------
	 * Langues
	 * ------------------------------------------------------------------- */

	/**
	 * Réglage des langues du schéma, normalisé.
	 *
	 * @return array{default:string,locales:array<int,array{code:string,label:string}>}
	 */
	public static function config() {
		$schema = class_exists( 'Leiho_Collections' ) ? Leiho_Collections::schema() : array();
		$i18n   = isset( $schema['i18n'] ) && is_array( $schema['i18n'] ) ? $schema['i18n'] : array();
		if ( empty( $i18n['locales'] ) ) {
			return array(
				'default' => self::site_locale(),
				'locales' => array(
					array(
						'code'  => self::site_locale(),
						'label' => self::label( self::site_locale() ),
					),
				),
			);
		}
		return $i18n;
	}

	/** Plusieurs langues déclarées. */
	public static function enabled() {
		return count( self::config()['locales'] ) > 1;
	}

	/** @return string */
	public static function default_locale() {
		return self::config()['default'];
	}

	/** @return string[] Codes, langue par défaut en premier. */
	public static function codes() {
		return array_map(
			static function ( $l ) {
				return $l['code'];
			},
			self::config()['locales']
		);
	}

	/**
	 * Langue déclarée (code exact), sinon null.
	 *
	 * @param mixed $code Code reçu.
	 * @return string|null
	 */
	public static function valid( $code ) {
		if ( ! is_string( $code ) || '' === $code ) {
			return null;
		}
		foreach ( self::codes() as $known ) {
			if ( strtolower( $known ) === strtolower( $code ) ) {
				return $known;
			}
		}
		return null;
	}

	/** Langue du site WordPress, en code court (fr_FR → fr). */
	public static function site_locale() {
		$wp = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		return strtolower( substr( $wp, 0, 2 ) ) ?: 'en';
	}

	/**
	 * Libellé d'une langue dans cette langue (Français, English, Deutsch…).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function label( $code ) {
		$names = array(
			'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español', 'it' => 'Italiano',
			'pt' => 'Português', 'pt-BR' => 'Português (Brasil)', 'nl' => 'Nederlands', 'pl' => 'Polski',
			'sv' => 'Svenska', 'da' => 'Dansk', 'nb' => 'Norsk bokmål', 'fi' => 'Suomi', 'cs' => 'Čeština',
			'ro' => 'Română', 'hu' => 'Magyar', 'el' => 'Ελληνικά', 'tr' => 'Türkçe', 'ru' => 'Русский',
			'uk' => 'Українська', 'ar' => 'العربية', 'he' => 'עברית', 'ja' => '日本語', 'ko' => '한국어',
			'zh' => '中文', 'zh-Hans' => '简体中文', 'zh-Hant' => '繁體中文', 'eu' => 'Euskara', 'ca' => 'Català',
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : $code;
	}

	/**
	 * Normalise la clé « i18n » d'un schéma.
	 *
	 * Accepte ["en", "fr"], [{ code, label }] ou { default, locales }. La
	 * première langue est la langue par défaut, sauf « default » explicite.
	 *
	 * @param mixed $input  Valeur reçue.
	 * @param array $errors Erreurs (sortie, format du schéma).
	 * @return array|null Null : pas de localisation.
	 */
	public static function normalize( $input, &$errors ) {
		if ( null === $input || array() === $input ) {
			return null;
		}
		$list    = isset( $input['locales'] ) ? $input['locales'] : $input;
		$default = isset( $input['default'] ) && is_string( $input['default'] ) ? $input['default'] : '';
		if ( ! is_array( $list ) ) {
			$errors[] = array(
				'path'    => 'i18n.locales',
				'message' => __( 'Must be a list of languages.', 'leiho' ),
			);
			return null;
		}
		$locales = array();
		$seen    = array();
		foreach ( array_values( $list ) as $i => $item ) {
			$code  = is_array( $item ) ? ( isset( $item['code'] ) ? $item['code'] : '' ) : $item;
			$label = is_array( $item ) && isset( $item['label'] ) && is_string( $item['label'] ) ? trim( wp_strip_all_tags( $item['label'] ) ) : '';
			if ( ! is_string( $code ) || ! preg_match( self::CODE_PATTERN, $code ) ) {
				$errors[] = array(
					'path'    => 'i18n.locales.' . $i,
					'message' => __( 'Use a language code such as en, fr or pt-BR.', 'leiho' ),
				);
				continue;
			}
			if ( isset( $seen[ strtolower( $code ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $code ) ] = true;
			$locales[]                   = array(
				'code'  => $code,
				'label' => '' !== $label ? $label : self::label( $code ),
			);
		}
		if ( ! $locales ) {
			return null;
		}
		$codes = array_column( $locales, 'code' );
		if ( '' === $default || ! in_array( $default, $codes, true ) ) {
			$default = $codes[0];
		}
		// Langue par défaut en premier : l'ordre sert aux sélecteurs.
		usort(
			$locales,
			static function ( $a, $b ) use ( $default ) {
				return ( $b['code'] === $default ) - ( $a['code'] === $default );
			}
		);
		return array(
			'default' => $default,
			'locales' => $locales,
		);
	}

	/* ---------------------------------------------------------------------
	 * Langue de la requête
	 * ------------------------------------------------------------------- */

	/**
	 * Branche la langue des requêtes REST de Leiho (?locale=fr) : éditeur,
	 * Collections, IA et API de contenu lisent et écrivent dans cette langue.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'from_request' ), 10, 3 );
	}

	/**
	 * @param mixed           $response Réponse (inchangée).
	 * @param array           $handler  Route.
	 * @param WP_REST_Request $request  Requête.
	 * @return mixed
	 */
	public static function from_request( $response, $handler, $request ) {
		$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
		if ( 0 === strpos( $route, '/leiho/v1/' ) || 0 === strpos( $route, '/okno/v1/' ) ) {
			$locale = $request->get_param( 'locale' );
			if ( is_string( $locale ) && '' !== $locale ) {
				self::set_current( $locale );
			}
		}
		return $response;
	}

	/** @return string Langue courante (par défaut si aucune). */
	public static function current() {
		return null === self::$current ? self::default_locale() : self::$current;
	}

	/** La requête porte sur une autre langue que la langue par défaut. */
	public static function is_translation() {
		return null !== self::$current && self::$current !== self::default_locale();
	}

	/**
	 * Change la langue de la requête.
	 *
	 * @param string|null $code Code (null ou inconnu : langue par défaut).
	 * @return string|null Langue précédente, pour la restaurer.
	 */
	public static function set_current( $code ) {
		$previous      = self::$current;
		self::$current = self::valid( $code );
		if ( self::$current === self::default_locale() ) {
			self::$current = null;
		}
		return $previous;
	}

	/**
	 * Exécute un rappel dans une langue, puis restaure la précédente.
	 *
	 * @param string   $code     Langue.
	 * @param callable $callback Rappel.
	 * @return mixed
	 */
	public static function in_locale( $code, $callback ) {
		$previous      = self::set_current( $code );
		try {
			return $callback();
		} finally {
			self::$current = $previous;
		}
	}

	/* ---------------------------------------------------------------------
	 * Champs traduisibles
	 * ------------------------------------------------------------------- */

	/**
	 * Traduisible par défaut : les textes et ce qui en contient. Partagé : le
	 * reste (image, nombre, date, choix, référence, code…).
	 *
	 * @param string $type Type de champ Collections.
	 * @return bool
	 */
	public static function localized_by_default( $type ) {
		return in_array( $type, array( 'text', 'richtext', 'link', 'list', 'blocks', 'group' ), true );
	}

	/**
	 * Le champ (racine) se traduit.
	 *
	 * @param array $field Champ normalisé.
	 * @return bool
	 */
	public static function is_localized( $field ) {
		return array_key_exists( 'localized', $field ) ? (bool) $field['localized'] : self::localized_by_default( $field['type'] );
	}

	/**
	 * Clés des champs traduisibles d'une collection.
	 *
	 * @param array $collection Collection normalisée.
	 * @return string[]
	 */
	public static function localized_keys( $collection ) {
		$keys = array();
		foreach ( $collection['fields'] as $field ) {
			if ( self::is_localized( $field ) ) {
				$keys[] = $field['key'];
			}
		}
		return $keys;
	}

	/* ---------------------------------------------------------------------
	 * Chemins
	 * ------------------------------------------------------------------- */

	/**
	 * Chemin du front dans une langue : la langue par défaut n'a pas de
	 * préfixe, les autres en ont un (/fr/…, /fr pour l'accueil).
	 *
	 * @param string $path   Chemin dans la langue par défaut.
	 * @param string $locale Langue.
	 * @return string
	 */
	public static function path( $path, $locale ) {
		if ( ! is_string( $path ) || '' === $path || $locale === self::default_locale() || ! self::valid( $locale ) ) {
			return $path;
		}
		$prefix = '/' . strtolower( $locale );
		return '/' === $path ? $prefix : $prefix . $path;
	}

	/**
	 * Chemins d'une entrée dans chaque langue.
	 *
	 * @param string $path Chemin dans la langue par défaut.
	 * @return array<string,string>
	 */
	public static function paths( $path ) {
		$out = array();
		foreach ( self::codes() as $code ) {
			$out[ $code ] = self::path( $path, $code );
		}
		return $out;
	}

	/**
	 * Sépare la langue d'un chemin du front (/fr/services → [fr, /services]).
	 *
	 * @param string $path Chemin.
	 * @return array{0:string,1:string}
	 */
	public static function split_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		if ( preg_match( '#^/([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(/.*)?$#i', $path, $m ) ) {
			$code = self::valid( $m[1] );
			if ( $code && $code !== self::default_locale() ) {
				return array( $code, isset( $m[2] ) && '' !== $m[2] ? $m[2] : '/' );
			}
		}
		return array( self::default_locale(), $path );
	}

	/* ---------------------------------------------------------------------
	 * Table des traductions
	 * ------------------------------------------------------------------- */

	/** @return string Nom de la table. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'leiho_l10n';
	}

	/**
	 * Crée la table si besoin (une fois par requête).
	 *
	 * @return bool
	 */
	public static function ensure_table() {
		global $wpdb;
		if ( self::$table_ready ) {
			return true;
		}
		$t = Leiho_Collections_Store::ident( self::table() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- DDL, identifiant vérifié.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$t} ( `post_id` BIGINT(20) UNSIGNED NOT NULL, `locale` VARCHAR(20) NOT NULL, `title` TEXT NULL, `data` LONGTEXT NULL, `source_hash` VARCHAR(40) NULL, `modified` DATETIME NULL, PRIMARY KEY (`post_id`, `locale`) ) " . $wpdb->get_charset_collate() );
		self::$table_ready = '' === (string) $wpdb->last_error;
		return self::$table_ready;
	}

	/**
	 * Traduction d'une entrée : { title, data (clé => valeur stockée), source_hash, modified }.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue.
	 * @return array|null Null : pas encore traduite.
	 */
	public static function row( $post_id, $locale ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$cache   = $post_id . ':' . $locale;
		if ( array_key_exists( $cache, self::$rows ) ) {
			return self::$rows[ $cache ];
		}
		if ( $post_id <= 0 || ! self::ensure_table() ) {
			return null;
		}
		$t   = Leiho_Collections_Store::ident( self::table() );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE `post_id` = %d AND `locale` = %s", $post_id, $locale ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié.
		if ( is_array( $row ) ) {
			$data        = json_decode( (string) $row['data'], true );
			$row['data'] = is_array( $data ) ? $data : array();
		} else {
			$row = null;
		}
		self::$rows[ $cache ] = $row;
		return $row;
	}

	/**
	 * Écrit une traduction : valeurs fusionnées avec celles déjà traduites.
	 *
	 * @param int         $post_id Entrée.
	 * @param string      $locale  Langue (pas la langue par défaut).
	 * @param array       $values  Clé de champ => valeur stockée (null = vide).
	 * @param string|null $title   Titre traduit (null : inchangé).
	 * @param string|null $hash    Empreinte de l'original (null : recalculée).
	 * @return bool
	 */
	public static function write( $post_id, $locale, $values, $title = null, $hash = null ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! self::valid( $locale ) || $locale === self::default_locale() || ! self::ensure_table() ) {
			return false;
		}
		$current = self::row( $post_id, $locale );
		$data    = $current ? $current['data'] : array();
		foreach ( (array) $values as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}
		$row = array(
			'post_id'     => $post_id,
			'locale'      => $locale,
			'title'       => null !== $title ? (string) $title : ( $current ? $current['title'] : null ),
			'data'        => wp_json_encode( (object) $data ),
			'source_hash' => null !== $hash ? $hash : self::source_hash( $post_id ),
			'modified'    => current_time( 'mysql', true ),
		);
		$t      = Leiho_Collections_Store::ident( self::table() );
		$exists = (bool) $current || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT `post_id` FROM {$t} WHERE `post_id` = %d AND `locale` = %s", $post_id, $locale ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié.
		$result = $exists
			? $wpdb->update( self::table(), $row, array( 'post_id' => $post_id, 'locale' => $locale ), array( '%d', '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) )
			: $wpdb->insert( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
		unset( self::$rows[ $post_id . ':' . $locale ] );
		$ok = false !== $result && '' === (string) $wpdb->last_error;
		if ( $ok && class_exists( 'Leiho_Collections' ) ) {
			// Contenu servi changé (caches, publication automatique), comme pour l'original.
			Leiho_Collections::entry_changed( $post_id );
		}
		return $ok;
	}

	/**
	 * Supprime les traductions d'une entrée (suppression définitive du post).
	 *
	 * @param int $post_id Entrée.
	 * @return void
	 */
	public static function delete( $post_id ) {
		global $wpdb;
		if ( ! self::ensure_table() ) {
			return;
		}
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
		foreach ( array_keys( self::$rows ) as $cache ) {
			if ( 0 === strpos( $cache, (int) $post_id . ':' ) ) {
				unset( self::$rows[ $cache ] );
			}
		}
	}

	/** Vide les caches de la requête (tests). */
	public static function flush() {
		self::$rows        = array();
		self::$table_ready = false;
	}

	/* ---------------------------------------------------------------------
	 * État des traductions
	 * ------------------------------------------------------------------- */

	/**
	 * Empreinte des valeurs d'origine traduisibles (titre compris).
	 *
	 * @param int $post_id Entrée.
	 * @return string
	 */
	public static function source_hash( $post_id ) {
		$post       = get_post( $post_id );
		$collection = $post ? Leiho_Collections::for_post_type( $post->post_type ) : null;
		if ( ! $collection ) {
			return '';
		}
		$stored = Leiho_Collections_Store::values( $collection['id'], $post_id );
		$parts  = array( 'title' => $post->post_title );
		foreach ( self::localized_keys( $collection ) as $key ) {
			$parts[ $key ] = isset( $stored[ $key ] ) ? $stored[ $key ] : null;
		}
		return sha1( wp_json_encode( $parts ) );
	}

	/**
	 * État d'une entrée dans une langue : missing (rien de traduit), partial
	 * (des champs traduisibles remplis dans l'original manquent), outdated
	 * (l'original a changé depuis), complete.
	 *
	 * @param int    $post_id Entrée.
	 * @param string $locale  Langue.
	 * @return string
	 */
	public static function status( $post_id, $locale ) {
		if ( $locale === self::default_locale() ) {
			return 'complete';
		}
		$row = self::row( $post_id, $locale );
		if ( ! $row || ( ! $row['data'] && '' === (string) $row['title'] ) ) {
			return 'missing';
		}
		$post       = get_post( $post_id );
		$collection = $post ? Leiho_Collections::for_post_type( $post->post_type ) : null;
		if ( $collection ) {
			$stored = Leiho_Collections_Store::values( $collection['id'], $post_id );
			foreach ( self::localized_keys( $collection ) as $key ) {
				$source = isset( $stored[ $key ] ) ? $stored[ $key ] : null;
				if ( null !== $source && '' !== $source && ! isset( $row['data'][ $key ] ) ) {
					return 'partial';
				}
			}
		}
		if ( $row['source_hash'] && $row['source_hash'] !== self::source_hash( $post_id ) ) {
			return 'outdated';
		}
		return 'complete';
	}

	/**
	 * État de toutes les langues d'une entrée.
	 *
	 * @param int $post_id Entrée.
	 * @return array<string,string>
	 */
	public static function statuses( $post_id ) {
		$out = array();
		foreach ( self::codes() as $code ) {
			$out[ $code ] = self::status( $post_id, $code );
		}
		return $out;
	}

	/**
	 * Données pour l'éditeur et les écrans : langues, langue par défaut.
	 *
	 * @return array
	 */
	public static function client_config() {
		$config = self::config();
		return array(
			'enabled' => self::enabled(),
			'default' => $config['default'],
			'locales' => $config['locales'],
		);
	}
}

Leiho_I18n::init();
