<?php
defined( 'ABSPATH' ) || exit;

/**
 * Assemble le schéma d'un post : champs natifs (title, featured image)
 * + groupes fournis par les adapters. Orchestre aussi l'écriture.
 */
class Leiho_Schema {

	const PATH_TITLE     = '_title';
	const PATH_THUMBNAIL = '_thumbnail';

	/** @var Leiho_Plugin */
	private $plugin;

	public function __construct( Leiho_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Schéma complet + valeurs pour un post.
	 *
	 * @param int $post_id Post.
	 * @return array|WP_Error
	 */
	public function get( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}

		$groups = array_merge( array( $this->native_group( $post ) ), Leiho_Native_Fields::groups( $post ) );

		foreach ( $this->plugin->adapters() as $adapter ) {
			$groups = array_merge( $groups, $adapter->get_schema( $post_id ) );
		}

		$mapper = new Leiho_Url_Mapper();

		// Langues de l'entrée (Collections, plusieurs langues déclarées).
		$i18n = null;
		if ( Leiho_I18n::enabled() && Leiho_Collections::for_post_type( $post->post_type ) ) {
			$i18n = array(
				'locale'   => Leiho_I18n::current(),
				'default'  => Leiho_I18n::default_locale(),
				'locales'  => Leiho_I18n::config()['locales'],
				'statuses' => Leiho_I18n::statuses( $post_id ),
			);
		}

		return array(
			'i18n'        => $i18n,
			'post_id'     => (int) $post_id,
			'revision'    => self::revision( $post_id ),
			'title'       => Leiho_Plugin::title( $post ),
			'status'      => $post->post_status,
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'front_url'   => $mapper->front_url( $post ),
			'preview_url' => $mapper->preview_url( $post ),
			'groups'      => $groups,
		);
	}

	/**
	 * Écrit un lot de valeurs { path => valeur } sur un post.
	 *
	 * Un enregistrement à la fois par contenu : deux éditeurs (ou un éditeur
	 * et un agent) qui modifient deux lignes d'une même liste ne s'écrasent
	 * pas, le second relit ce que le premier vient d'écrire.
	 *
	 * @param int         $post_id  Post.
	 * @param array       $values   Valeurs reçues.
	 * @param string|null $revision Empreinte attendue (Leiho_Schema::revision) : vérifiée sous le verrou.
	 * @return array { saved: string[], errors: array<string,string>, conflict?: bool }
	 */
	public function save( $post_id, array $values, $revision = null ) {
		if ( ! self::lock( $post_id ) ) {
			return array(
				'saved'    => array(),
				'errors'   => array_fill_keys( array_map( 'strval', array_keys( $values ) ), 'busy' ),
				'schema'   => null,
				'conflict' => true,
			);
		}
		try {
			if ( null !== $revision && self::revision( $post_id ) !== $revision ) {
				return array(
					'saved'    => array(),
					'errors'   => array(),
					'schema'   => null,
					'conflict' => true,
				);
			}
			return $this->write( $post_id, $values );
		} finally {
			self::unlock( $post_id );
		}
	}

	/** Durée maximale d'un verrou d'enregistrement (secondes) : au-delà, il est périmé. */
	const LOCK_TTL = 30;

	/**
	 * Prend le verrou d'enregistrement d'un contenu (add_option est atomique :
	 * clé unique en base). Attend au plus 5 secondes qu'il se libère.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function lock( $post_id ) {
		$name  = 'leiho_save_lock_' . (int) $post_id;
		$until = microtime( true ) + 5;
		do {
			if ( add_option( $name, time() + self::LOCK_TTL, '', false ) ) {
				return true;
			}
			$expires = (int) get_option( $name, 0 );
			if ( $expires && $expires < time() ) {
				delete_option( $name ); // Requête interrompue : verrou abandonné.
				continue;
			}
			usleep( 100000 );
		} while ( microtime( true ) < $until );
		return false;
	}

	/**
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function unlock( $post_id ) {
		delete_option( 'leiho_save_lock_' . (int) $post_id );
	}

	/**
	 * Écriture proprement dite (sous verrou).
	 *
	 * @param int   $post_id Post.
	 * @param array $values  Valeurs reçues.
	 * @return array
	 */
	private function write( $post_id, array $values ) {
		$saved  = array();
		$errors = array();

		// Instantané avant écriture, pour le journal (valeur avant / après).
		$before = $this->get( $post_id );

		// Champs natifs : titre et colonnes du post (contenu, extrait,
		// adresse, date, auteur, parent, ordre) en un seul wp_update_post(),
		// taxonomies juste avant (wp_update_post relit les termes à jour).
		$post    = get_post( $post_id );
		$native  = $post ? Leiho_Native_Fields::prepare( $post, $values ) : array(
			'postarr' => array(),
			'saved'   => array(),
			'errors'  => array(),
		);
		$postarr = $native['postarr'];
		$errors  = array_merge( $errors, $native['errors'] );

		// Traduction d'une entrée de Collections : le titre va dans la traduction
		// (vide : la page retombe sur le titre d'origine), pas dans le post.
		if ( array_key_exists( self::PATH_TITLE, $values ) && $post && Leiho_I18n::is_translation() && Leiho_Collections::for_post_type( $post->post_type ) ) {
			$title = sanitize_text_field( (string) $values[ self::PATH_TITLE ] );
			if ( Leiho_I18n::write( $post_id, Leiho_I18n::current(), array(), $title ) ) {
				$saved[] = self::PATH_TITLE;
			} else {
				$errors[ self::PATH_TITLE ] = 'save_failed';
			}
			unset( $values[ self::PATH_TITLE ] );
		}

		if ( array_key_exists( self::PATH_TITLE, $values ) ) {
			$title = sanitize_text_field( (string) $values[ self::PATH_TITLE ] );
			if ( '' === trim( $title ) ) {
				// Le titre est déclaré required dans le schéma — on le fait respecter.
				$errors[ self::PATH_TITLE ] = 'required';
			} else {
				$postarr['post_title'] = $title;
				$native['saved'][]     = self::PATH_TITLE;
			}
			unset( $values[ self::PATH_TITLE ] );
		}

		if ( $native['saved'] ) {
			// wp_update_post() attend des données échappées (wp_slash).
			$columns = array_diff( array_keys( $postarr ), array( 'edit_date' ) );
			$data    = wp_slash( array_intersect_key( $postarr, array_flip( $columns ) ) );
			if ( isset( $postarr['edit_date'] ) ) {
				$data['edit_date'] = true;
			}
			$data['ID'] = $post_id;
			$result     = wp_update_post( $data, true );
			if ( is_wp_error( $result ) ) {
				foreach ( $native['saved'] as $path ) {
					if ( 0 !== strpos( $path, Leiho_Native_Fields::TAX_PREFIX ) ) {
						$errors[ $path ] = 'save_failed';
					} else {
						$saved[] = $path; // Termes déjà écrits.
					}
				}
			} else {
				$saved = array_merge( $saved, $native['saved'] );
			}
		}

		if ( array_key_exists( self::PATH_THUMBNAIL, $values ) ) {
			$attachment_id = absint( $values[ self::PATH_THUMBNAIL ] );
			if ( 0 === $attachment_id ) {
				delete_post_thumbnail( $post_id );
				$saved[] = self::PATH_THUMBNAIL;
			} elseif ( 'attachment' === get_post_type( $attachment_id ) && current_user_can( 'read_post', $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
				$saved[] = self::PATH_THUMBNAIL;
			} else {
				$errors[ self::PATH_THUMBNAIL ] = 'invalid_attachment';
			}
			unset( $values[ self::PATH_THUMBNAIL ] );
		}

		// Adapters.
		$remaining = $values;
		foreach ( $this->plugin->adapters() as $adapter ) {
			if ( empty( $remaining ) ) {
				break;
			}
			$result = $adapter->save_values( $post_id, $remaining );
			$saved  = array_merge( $saved, $result['saved'] );
			$errors = array_merge( $errors, $result['errors'] );
			foreach ( array_merge( $result['saved'], array_keys( $result['errors'] ) ) as $path ) {
				unset( $remaining[ $path ] );
			}
		}

		// Paths que personne n'a reconnus.
		foreach ( array_keys( $remaining ) as $path ) {
			$errors[ $path ] = 'unknown_field';
		}

		$after = null;
		if ( $saved ) {
			self::touch( $post_id );

			$after = $this->get( $post_id );
			if ( ! is_wp_error( $before ) && ! is_wp_error( $after ) ) {
				foreach ( Leiho_Activity::diff( $before, $after, $saved ) as $row ) {
					Leiho_Activity::record( $post_id, $row['path'], $row['label'], $row['type'], $row['old'], $row['new'] );
				}
			}
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
			// Schéma frais déjà calculé : l'appelant n'a pas à le reconstruire.
			'schema' => ( $after && ! is_wp_error( $after ) ) ? $after : null,
		);
	}

	/**
	 * Empreinte de la version courante d'un contenu.
	 *
	 * Un éditeur charge le schéma avec cette empreinte et la renvoie à
	 * l'enregistrement : si elle a changé entre-temps (autre éditeur, autre
	 * onglet, modification depuis wp-admin), l'écriture est refusée plutôt
	 * que d'écraser en silence.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function revision( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$parts = array(
			$post->post_modified_gmt,
			$post->post_status,
			(string) get_post_meta( $post_id, '_leiho_rev', true ),
		);

		return substr( md5( implode( '|', $parts ) ), 0, 16 );
	}

	/**
	 * Marque le contenu comme modifié : ACF n'update pas post_modified, une
	 * écriture de champ doit quand même invalider l'empreinte.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function touch( $post_id ) {
		update_post_meta( $post_id, '_leiho_rev', (string) microtime( true ) );
	}

	/**
	 * Groupe "natif" : titre, contenu et extrait (formulaire), image mise en avant.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function native_group( $post ) {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		// Traduction d'une entrée de Collections : titre traduit (vide si pas encore).
		$translated = Leiho_I18n::is_translation() && Leiho_Collections::for_post_type( $post->post_type );
		$l10n       = $translated ? Leiho_I18n::row( $post->ID, Leiho_I18n::current() ) : null;

		$fields = array(
			array(
				'path'      => self::PATH_TITLE,
				'key'       => self::PATH_TITLE,
				'type'      => 'text',
				'label'     => __( 'Title', 'leiho' ),
				'required'  => ! $translated,
				'supported' => true,
				'source'    => 'native',
				'value'     => $translated ? ( $l10n ? (string) $l10n['title'] : '' ) : $post->post_title,
				'localized' => true,
				'original'  => $translated ? $post->post_title : null,
			),
		);

		// Contenu et extrait : formulaire de Collections seulement (form_only).
		$fields = array_merge( $fields, Leiho_Native_Fields::content_fields( $post ) );

		// Image mise en avant : seulement si le type la prend en charge (une
		// collection sans la fonctionnalité « Image mise en avant » n'en a pas),
		// ou si le contenu en a déjà une.
		if ( $thumbnail_id || ! function_exists( 'post_type_supports' ) || post_type_supports( $post->post_type, 'thumbnail' ) ) {
			$fields[] = array(
				'path'      => self::PATH_THUMBNAIL,
				'key'       => self::PATH_THUMBNAIL,
				'type'      => 'image',
				'label'     => __( 'Featured image', 'leiho' ),
				'required'  => false,
				'supported' => true,
				'source'    => 'native',
				'value'     => $thumbnail_id ? $thumbnail_id : null,
				'meta'      => array( 'url' => $thumbnail_id ? (string) wp_get_attachment_url( $thumbnail_id ) : '' ),
			);
		}

		return array(
			'key'    => 'leiho_native',
			'title'  => __( 'Main content', 'leiho' ),
			'source' => 'native',
			'fields' => $fields,
		);
	}
}
