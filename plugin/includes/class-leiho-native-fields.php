<?php
defined( 'ABSPATH' ) || exit;

/**
 * Champs natifs de WordPress dans le formulaire d'une entrée : contenu,
 * extrait, adresse (slug), taxonomies publiques, auteur, date de
 * publication, parent et ordre — selon ce que le type de contenu prend en
 * charge. Titre et image mise en avant restent dans Leiho_Schema (ils sont
 * aussi édités dans l'éditeur visuel) ; ces champs-ci portent form_only :
 * l'éditeur visuel ne les affiche pas.
 *
 * Contenu écrit avec l'éditeur de blocs : jamais réécrit ici. Le champ est
 * en lecture seule, avec un lien vers l'éditeur WordPress, et le serveur
 * refuse toute écriture (blocks_protected) — un éditeur classique ne
 * garantit pas de rendre les commentaires de blocs intacts.
 */
class Leiho_Native_Fields {

	const PATH_CONTENT = '_content';
	const PATH_EXCERPT = '_excerpt';
	const PATH_SLUG    = '_slug';
	const PATH_DATE    = '_date';
	const PATH_AUTHOR  = '_author';
	const PATH_PARENT  = '_parent';
	const PATH_ORDER   = '_menu_order';
	const TAX_PREFIX   = '_tax_';

	/** Au-delà, les listes (parents, auteurs) ne sont pas proposées en entier. */
	const MAX_CHOICES = 300;

	/**
	 * Le contenu a-t-il été écrit avec l'éditeur de blocs ?
	 *
	 * @param string $content post_content.
	 * @return bool
	 */
	public static function has_blocks( $content ) {
		if ( function_exists( 'has_blocks' ) ) {
			return has_blocks( $content );
		}
		return false !== strpos( (string) $content, '<!-- wp:' );
	}

	/**
	 * Taxonomies proposées pour un type : publiques et avec une interface.
	 *
	 * @param string $post_type Type.
	 * @return WP_Taxonomy[]
	 */
	public static function taxonomies( $post_type ) {
		$out = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $name => $tax ) {
			if ( empty( $tax->public ) || empty( $tax->show_ui ) || 'post_format' === $name ) {
				continue;
			}
			$out[ $name ] = $tax;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Schéma
	 * ------------------------------------------------------------------- */

	/**
	 * Champs du contenu principal (après le titre, avant l'image).
	 *
	 * @param WP_Post $post Post.
	 * @return array[]
	 */
	public static function content_fields( $post ) {
		$fields = array();
		$type   = $post->post_type;

		if ( post_type_supports( $type, 'editor' ) ) {
			$field = self::field( self::PATH_CONTENT, 'wysiwyg', __( 'Content', 'leiho' ), (string) $post->post_content );
			$field['toolbar'] = 'full';
			$field['media']   = current_user_can( 'upload_files' );
			if ( self::has_blocks( $post->post_content ) ) {
				// Lecture seule : un aperçu en texte, et l'éditeur de blocs.
				$field['blocks']   = true;
				$field['readonly'] = true;
				$field['value']    = null;
				$field['preview']  = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 60 );
				$field['edit_url'] = (string) get_edit_post_link( $post->ID, 'raw' );
			}
			$fields[] = $field;
		}

		if ( post_type_supports( $type, 'excerpt' ) ) {
			$field            = self::field( self::PATH_EXCERPT, 'textarea', __( 'Excerpt', 'leiho' ), (string) $post->post_excerpt );
			$field['counter'] = true;
			$field['rows']    = 3;
			$field['help']    = __( 'Short summary shown in lists and shared links. Leave empty to use the start of the content.', 'leiho' );
			$fields[]         = $field;
		}

		return $fields;
	}

	/**
	 * Groupes « Classement » (taxonomies) et « Publication » (adresse,
	 * date, auteur, parent, ordre).
	 *
	 * @param WP_Post $post Post.
	 * @return array[]
	 */
	public static function groups( $post ) {
		$groups = array();
		$type   = $post->post_type;
		$object = get_post_type_object( $type );

		// Taxonomies.
		$fields = array();
		foreach ( self::taxonomies( $type ) as $name => $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $name,
					'hide_empty' => false,
					'number'     => self::MAX_CHOICES,
					'orderby'    => 'name',
				)
			);
			$options = array();
			$order   = array();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$options[ (string) $term->term_id ] = $term->name;
					$order[]                            = (string) $term->term_id;
				}
			}
			$current = wp_get_object_terms( $post->ID, $name, array( 'fields' => 'ids' ) );
			$current = is_array( $current ) ? array_map( 'intval', $current ) : array();

			$field                  = self::field( self::TAX_PREFIX . $name, 'taxonomy', $tax->labels->name, $current );
			$field['options']       = (object) $options;
			$field['options_order'] = $order;
			$field['taxonomy']      = $name;
			$field['multiple']      = true;
			$field['readonly']      = ! current_user_can( $tax->cap->assign_terms );
			// Comme l'éditeur classique : une étiquette s'ajoute en la tapant ;
			// une catégorie demande le droit de gérer les catégories.
			$field['create'] = ! $field['readonly'] && ( empty( $tax->hierarchical ) || current_user_can( $tax->cap->edit_terms ) );
			$field['meta']   = array( 'items' => self::term_items( $current, $options ) );
			$fields[]        = $field;
		}
		if ( $fields ) {
			$groups[] = array(
				'key'    => 'leiho_native_terms',
				'title'  => __( 'Classification', 'leiho' ),
				'source' => 'native',
				'fields' => $fields,
			);
		}

		// Publication.
		$fields = array();
		if ( self::viewable( $type ) ) {
			$field          = self::field( self::PATH_SLUG, 'slug', __( 'URL slug', 'leiho' ), (string) $post->post_name );
			$field['meta']  = array( 'url' => self::permalink( $post ) );
			$field['help']  = __( 'The last part of the address. Lowercase letters, digits and hyphens.', 'leiho' );
			$fields[]       = $field;
		}
		if ( empty( $object->hierarchical ) ) {
			$field         = self::field( self::PATH_DATE, 'date_time_picker', __( 'Publish date', 'leiho' ), (string) $post->post_date );
			$field['help'] = __( 'A future date schedules a published entry.', 'leiho' );
			$fields[]      = $field;
		}
		if ( post_type_supports( $type, 'author' ) && $object && current_user_can( $object->cap->edit_others_posts ) ) {
			$field            = self::field( self::PATH_AUTHOR, 'select', __( 'Author', 'leiho' ), (string) $post->post_author );
			$field['options'] = (object) self::authors( $object, (int) $post->post_author );
			$field['required'] = true;
			$fields[]         = $field;
		}
		if ( ! empty( $object->hierarchical ) ) {
			$field            = self::field( self::PATH_PARENT, 'post_object', __( 'Parent', 'leiho' ), $post->post_parent ? (string) $post->post_parent : '' );
			$field['options'] = (object) self::parents( $post );
			$fields[]         = $field;
		}
		if ( ! empty( $object->hierarchical ) || post_type_supports( $type, 'page-attributes' ) ) {
			$field         = self::field( self::PATH_ORDER, 'number', __( 'Order', 'leiho' ), (int) $post->menu_order );
			$field['step'] = 1;
			$fields[]      = $field;
		}
		if ( $fields ) {
			$groups[] = array(
				'key'    => 'leiho_native_publish',
				'title'  => __( 'Publishing', 'leiho' ),
				'source' => 'native',
				'fields' => $fields,
			);
		}

		return $groups;
	}

	private static function field( $path, $type, $label, $value ) {
		return array(
			'path'      => $path,
			'key'       => $path,
			'type'      => $type,
			'label'     => $label,
			'required'  => false,
			'supported' => true,
			'source'    => 'native',
			'form_only' => true,
			'value'     => $value,
		);
	}

	private static function term_items( array $ids, array $options ) {
		$items = array();
		foreach ( $ids as $id ) {
			$items[] = array(
				'id'    => (int) $id,
				'label' => isset( $options[ (string) $id ] ) ? $options[ (string) $id ] : (string) $id,
			);
		}
		return $items;
	}

	private static function viewable( $type ) {
		if ( function_exists( 'is_post_type_viewable' ) ) {
			return is_post_type_viewable( $type );
		}
		$object = get_post_type_object( $type );
		return $object && ! empty( $object->public );
	}

	/**
	 * Adresse de l'entrée sur le site (Leiho), sinon le permalien WordPress.
	 */
	private static function permalink( $post ) {
		$url = '';
		if ( class_exists( 'Leiho_Url_Mapper' ) ) {
			$url = ( new Leiho_Url_Mapper() )->front_url( $post );
		}
		if ( ! $url ) {
			$url = (string) get_permalink( $post );
		}
		return (string) $url;
	}

	/**
	 * Utilisateurs qui peuvent écrire ce type (auteur actuel toujours inclus).
	 */
	private static function authors( $object, $current ) {
		$users = get_users(
			array(
				'capability' => array( $object->cap->edit_posts ),
				'number'     => self::MAX_CHOICES,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[ (string) $user->ID ] = $user->display_name;
		}
		if ( $current && ! isset( $out[ (string) $current ] ) ) {
			$user = get_userdata( $current );
			if ( $user ) {
				$out[ (string) $current ] = $user->display_name;
			}
		}
		return $out;
	}

	/**
	 * Parents possibles : même type, ni l'entrée ni ses descendants.
	 */
	private static function parents( $post ) {
		$posts = get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::MAX_CHOICES,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'exclude'        => array( $post->ID ),
			)
		);
		$out = array();
		foreach ( $posts as $candidate ) {
			if ( self::is_descendant( (int) $candidate->ID, (int) $post->ID ) ) {
				continue;
			}
			$out[ (string) $candidate->ID ] = '' !== $candidate->post_title ? $candidate->post_title : sprintf( '#%d', $candidate->ID );
		}
		return $out;
	}

	/**
	 * $id descend-il de $ancestor ?
	 */
	private static function is_descendant( $id, $ancestor ) {
		$seen = array();
		while ( $id && ! isset( $seen[ $id ] ) ) {
			if ( $id === $ancestor ) {
				return true;
			}
			$seen[ $id ] = true;
			$parent      = get_post( $id );
			$id          = $parent ? (int) $parent->post_parent : 0;
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Enregistrement
	 * ------------------------------------------------------------------- */

	/**
	 * Le chemin est-il un champ natif géré ici ?
	 *
	 * @param string $path Chemin.
	 * @return bool
	 */
	public static function owns( $path ) {
		return in_array( $path, array( self::PATH_CONTENT, self::PATH_EXCERPT, self::PATH_SLUG, self::PATH_DATE, self::PATH_AUTHOR, self::PATH_PARENT, self::PATH_ORDER ), true )
			|| 0 === strpos( (string) $path, self::TAX_PREFIX );
	}

	/**
	 * Valide et prépare les valeurs natives. Les taxonomies sont écrites
	 * tout de suite ; les colonnes du post sont renvoyées pour un seul
	 * wp_update_post() (Leiho_Schema::save, avec le titre).
	 *
	 * Le droit de modifier le post est vérifié par la route ; ici, les
	 * droits propres à chaque champ (auteur, taxonomies, HTML non filtré).
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $values Valeurs reçues (les chemins natifs en sont retirés).
	 * @return array { postarr: array, saved: string[], errors: array<string,string> }
	 */
	public static function prepare( $post, array &$values ) {
		$postarr = array();
		$saved   = array();
		$errors  = array();
		$object  = get_post_type_object( $post->post_type );
		$type    = $post->post_type;

		foreach ( array_keys( $values ) as $path ) {
			if ( ! self::owns( $path ) ) {
				continue;
			}
			$value = $values[ $path ];
			unset( $values[ $path ] );

			$error = null;
			switch ( $path ) {
				case self::PATH_CONTENT:
					if ( ! post_type_supports( $type, 'editor' ) ) {
						$error = 'unknown_field';
					} elseif ( self::has_blocks( $post->post_content ) ) {
						$error = 'blocks_protected';
					} elseif ( ! is_string( $value ) ) {
						$error = 'invalid';
					} else {
						$postarr['post_content'] = self::filter_html( $value );
					}
					break;

				case self::PATH_EXCERPT:
					if ( ! post_type_supports( $type, 'excerpt' ) ) {
						$error = 'unknown_field';
					} elseif ( ! is_string( $value ) ) {
						$error = 'invalid';
					} else {
						$postarr['post_excerpt'] = self::filter_html( trim( $value ) );
					}
					break;

				case self::PATH_SLUG:
					$slug = sanitize_title( is_scalar( $value ) ? (string) $value : '' );
					if ( '' === $slug ) {
						$slug = sanitize_title( $post->post_title );
					}
					if ( '' === $slug ) {
						$error = 'required';
					} else {
						$postarr['post_name'] = $slug;
					}
					break;

				case self::PATH_DATE:
					$date = self::parse_date( $value );
					if ( null === $date ) {
						$error = 'invalid_date';
					} else {
						$postarr['post_date']     = $date;
						$postarr['post_date_gmt'] = get_gmt_from_date( $date );
						$postarr['edit_date']     = true;
					}
					break;

				case self::PATH_AUTHOR:
					$user_id = absint( $value );
					$user    = $user_id ? get_userdata( $user_id ) : false;
					if ( ! $object || ! post_type_supports( $type, 'author' ) || ! current_user_can( $object->cap->edit_others_posts ) ) {
						$error = 'forbidden';
					} elseif ( ! $user || ! user_can( $user, $object->cap->edit_posts ) ) {
						$error = 'invalid_user';
					} else {
						$postarr['post_author'] = $user_id;
					}
					break;

				case self::PATH_PARENT:
					$parent_id = absint( $value );
					if ( ! $object || empty( $object->hierarchical ) ) {
						$error = 'unknown_field';
					} elseif ( $parent_id && ( get_post_type( $parent_id ) !== $type || self::is_descendant( $parent_id, (int) $post->ID ) ) ) {
						$error = 'invalid_parent';
					} else {
						$postarr['post_parent'] = $parent_id;
					}
					break;

				case self::PATH_ORDER:
					if ( ! is_numeric( $value ) && '' !== $value && null !== $value ) {
						$error = 'invalid';
					} else {
						$postarr['menu_order'] = (int) $value;
					}
					break;

				default: // Taxonomie.
					$error = self::save_terms( $post, substr( $path, strlen( self::TAX_PREFIX ) ), $value );
			}

			if ( null === $error ) {
				$saved[] = $path;
			} else {
				$errors[ $path ] = $error;
			}
		}

		return array(
			'postarr' => $postarr,
			'saved'   => $saved,
			'errors'  => $errors,
		);
	}

	/**
	 * HTML de l'utilisateur : filtré comme WordPress le ferait s'il n'a pas
	 * le droit d'écrire du HTML libre.
	 */
	private static function filter_html( $html ) {
		return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
	}

	/**
	 * « 2026-11-04 09:30 », « 2026-11-04T09:30 » ou avec les secondes.
	 *
	 * @param mixed $value Valeur reçue.
	 * @return string|null Y-m-d H:i:s, null si invalide.
	 */
	public static function parse_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) ) {
			return null;
		}
		$h = isset( $m[4] ) ? (int) $m[4] : 0;
		$i = isset( $m[5] ) ? (int) $m[5] : 0;
		$s = isset( $m[6] ) ? (int) $m[6] : 0;
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $h > 23 || $i > 59 || $s > 59 ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $h, $i, $s );
	}

	/**
	 * Termes d'une taxonomie : ids existants, ou noms à créer.
	 *
	 * @return string|null Code d'erreur, null si enregistré.
	 */
	private static function save_terms( $post, $taxonomy, $value ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! isset( self::taxonomies( $post->post_type )[ $taxonomy ] ) ) {
			return 'unknown_field';
		}
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			return 'forbidden';
		}
		if ( null === $value || '' === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) ) {
			return 'invalid';
		}

		$can_create = empty( $tax->hierarchical ) || current_user_can( $tax->cap->edit_terms );
		$ids        = array();
		foreach ( $value as $item ) {
			if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
				$term = get_term( (int) $item, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return 'invalid_term';
				}
				$ids[] = (int) $term->term_id;
				continue;
			}
			$name = is_string( $item ) ? sanitize_text_field( $item ) : '';
			if ( '' === trim( $name ) ) {
				continue;
			}
			$existing = get_term_by( 'name', $name, $taxonomy );
			if ( $existing ) {
				$ids[] = (int) $existing->term_id;
				continue;
			}
			if ( ! $can_create ) {
				return 'forbidden';
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				$existing_id = $created->get_error_data( 'term_exists' );
				if ( ! $existing_id ) {
					return 'save_failed';
				}
				$ids[] = (int) $existing_id;
				continue;
			}
			$ids[] = (int) $created['term_id'];
		}

		$result = wp_set_object_terms( $post->ID, array_values( array_unique( $ids ) ), $taxonomy );
		return is_wp_error( $result ) ? 'save_failed' : null;
	}
}
