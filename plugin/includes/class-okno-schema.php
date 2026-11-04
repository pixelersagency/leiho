<?php
defined( 'ABSPATH' ) || exit;

/**
 * Assemble le schéma d'un post : champs natifs (title, featured image)
 * + groupes fournis par les adapters. Orchestre aussi l'écriture.
 */
class Okno_Schema {

	const PATH_TITLE     = '_title';
	const PATH_THUMBNAIL = '_thumbnail';

	/** @var Okno_Plugin */
	private $plugin;

	public function __construct( Okno_Plugin $plugin ) {
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
			return new WP_Error( 'okno_not_found', __( 'Content not found.', 'okno' ), array( 'status' => 404 ) );
		}

		$groups = array( $this->native_group( $post ) );

		foreach ( $this->plugin->adapters() as $adapter ) {
			$groups = array_merge( $groups, $adapter->get_schema( $post_id ) );
		}

		$mapper = new Okno_Url_Mapper();

		return array(
			'post_id'     => (int) $post_id,
			'revision'    => self::revision( $post_id ),
			'title'       => Okno_Plugin::title( $post ),
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
	 * @param int   $post_id Post.
	 * @param array $values  Valeurs reçues.
	 * @return array { saved: string[], errors: array<string,string> }
	 */
	public function save( $post_id, array $values ) {
		$saved  = array();
		$errors = array();

		// Instantané avant écriture, pour le journal (valeur avant / après).
		$before = $this->get( $post_id );

		// Champs natifs.
		if ( array_key_exists( self::PATH_TITLE, $values ) ) {
			$title = sanitize_text_field( (string) $values[ self::PATH_TITLE ] );
			if ( '' === trim( $title ) ) {
				// Le titre est déclaré required dans le schéma — on le fait respecter.
				$errors[ self::PATH_TITLE ] = 'required';
			} else {
				$result = wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $title,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					$errors[ self::PATH_TITLE ] = 'save_failed';
				} else {
					$saved[] = self::PATH_TITLE;
				}
			}
			unset( $values[ self::PATH_TITLE ] );
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
				foreach ( Okno_Activity::diff( $before, $after, $saved ) as $row ) {
					Okno_Activity::record( $post_id, $row['path'], $row['label'], $row['type'], $row['old'], $row['new'] );
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
			(string) get_post_meta( $post_id, '_okno_rev', true ),
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
		update_post_meta( $post_id, '_okno_rev', (string) microtime( true ) );
	}

	/**
	 * Groupe "natif" : titre + image mise en avant.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private function native_group( $post ) {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		$fields = array(
			array(
				'path'      => self::PATH_TITLE,
				'key'       => self::PATH_TITLE,
				'type'      => 'text',
				'label'     => __( 'Title', 'okno' ),
				'required'  => true,
				'supported' => true,
				'source'    => 'native',
				'value'     => $post->post_title,
			),
		);

		// Image mise en avant : seulement si le type la prend en charge (une
		// collection sans la fonctionnalité « Image mise en avant » n'en a pas),
		// ou si le contenu en a déjà une.
		if ( $thumbnail_id || ! function_exists( 'post_type_supports' ) || post_type_supports( $post->post_type, 'thumbnail' ) ) {
			$fields[] = array(
				'path'      => self::PATH_THUMBNAIL,
				'key'       => self::PATH_THUMBNAIL,
				'type'      => 'image',
				'label'     => __( 'Featured image', 'okno' ),
				'required'  => false,
				'supported' => true,
				'source'    => 'native',
				'value'     => $thumbnail_id ? $thumbnail_id : null,
				'meta'      => array( 'url' => $thumbnail_id ? (string) wp_get_attachment_url( $thumbnail_id ) : '' ),
			);
		}

		return array(
			'key'    => 'okno_native',
			'title'  => __( 'Main content', 'okno' ),
			'source' => 'native',
			'fields' => $fields,
		);
	}
}
