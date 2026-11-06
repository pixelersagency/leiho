<?php
defined( 'ABSPATH' ) || exit;

/**
 * Moteur de champs commun aux adapters (ACF, Leiho Collections…).
 *
 * Travaille sur des définitions de champ au format ACF (key, name, label,
 * type, sub_fields, layouts, choices, maxlength…) et des valeurs brutes au
 * format ACF « format=false » (ids, lignes indexées par key ou par name,
 * acf_fc_layout). Il ne lit ni n'écrit rien : chaque adapter fournit le
 * stockage, ce moteur décrit, lit, patche les chemins pointés, sanitize et
 * met en forme pour le front.
 *
 * Le modèle est récursif : un champ conteneur (repeater, group, clone en mode
 * groupe, flexible_content) décrit ses sous-champs, qui peuvent eux-mêmes être
 * des conteneurs. Les chemins pointés désignent une cellule précise :
 *
 *   rep.0.titre              ligne 0 du repeater « rep »
 *   sections.2.cartes.1.lien ligne 1 du repeater « cartes » de la section 2
 *   reglages.adresse         sous-champ d'un group
 */
abstract class Leiho_Field_Adapter implements Leiho_Adapter_Interface, Leiho_Content_Adapter_Interface {

	/**
	 * Types éditables. Leiho édite le contenu, pas la mise en forme : les
	 * propriétés de style (color_picker) restent en sommeil côté bridge.
	 */
	const SUPPORTED_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
		'image',
		'file',
		'gallery',
		'url',
		'link',
		'page_link',
		'oembed',
		'email',
		'select',
		'true_false',
		'number',
		'range',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'post_object',
		'relationship',
		'taxonomy',
		'repeater',
		'group',
		'clone',
		'flexible_content',
	);

	/** Types conteneurs : décrits par leurs sous-champs, jamais écrits en bloc « à plat ». */
	const CONTAINER_TYPES = array( 'repeater', 'group', 'clone', 'flexible_content' );

	/** Nombre maximal de choix listés pour les champs relationnels. */
	const MAX_CHOICES = 100;

	/**
	 * Description (sans valeur) d'un champ, récursive.
	 *
	 * @param array $field Champ ACF brut.
	 * @return array
	 */
	protected function describe( $field ) {
		$type = $field['type'];

		$entry = array(
			'path'      => $field['name'],
			'key'       => $field['key'],
			'type'      => $type,
			'label'     => $field['label'],
			'required'  => ! empty( $field['required'] ),
			'supported' => in_array( $type, static::SUPPORTED_TYPES, true ),
		);

		// Un select/post_object/taxonomy multiple reste lisible mais pas éditable en v1.
		if ( ! empty( $field['multiple'] ) && in_array( $type, array( 'select', 'post_object' ), true ) ) {
			$entry['multiple'] = true;
		}

		if ( ! $entry['supported'] ) {
			return $entry;
		}

		// Limite de caractères ACF : l'intégrateur l'a posée pour protéger la mise
		// en page (titre sur deux lignes max…). Elle était ignorée par Leiho.
		if ( in_array( $type, array( 'text', 'textarea' ), true ) && ! empty( $field['maxlength'] ) ) {
			$entry['max_length'] = (int) $field['maxlength'];
		}

		switch ( $type ) {
			case 'repeater':
				$entry['sub_fields'] = $this->describe_all( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array() );
				$entry['min']        = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$entry['max']        = isset( $field['max'] ) ? (int) $field['max'] : 0;
				$entry['supported']  = ! empty( $entry['sub_fields'] );
				break;

			case 'group':
			case 'clone':
				$subs = isset( $field['sub_fields'] ) ? $field['sub_fields'] : array();
				// Un clone « seamless » est déjà aplati par ACF : rien à décrire.
				$entry['sub_fields'] = $this->describe_all( $subs );
				$entry['supported']  = ! empty( $entry['sub_fields'] );
				break;

			case 'flexible_content':
				$layouts = array();
				foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
					$layouts[] = array(
						'name'       => $layout['name'],
						'label'      => isset( $layout['label'] ) ? $layout['label'] : $layout['name'],
						'min'        => isset( $layout['min'] ) ? (int) $layout['min'] : 0,
						'max'        => isset( $layout['max'] ) ? (int) $layout['max'] : 0,
						'sub_fields' => $this->describe_all( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() ),
					);
				}
				$entry['layouts']   = $layouts;
				$entry['min']       = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$entry['max']       = isset( $field['max'] ) ? (int) $field['max'] : 0;
				$entry['supported'] = ! empty( $layouts );
				break;

			case 'select':
				$entry['options'] = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
				break;

			case 'taxonomy':
				$entry['taxonomy'] = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'category';
				$entry['options']  = $this->taxonomy_choices( $entry['taxonomy'] );
				$entry['multiple'] = in_array( isset( $field['field_type'] ) ? $field['field_type'] : '', array( 'checkbox', 'multi_select' ), true );
				break;

			case 'post_object':
			case 'relationship':
				$entry['post_types'] = array_values( (array) ( isset( $field['post_type'] ) ? $field['post_type'] : array() ) );
				$entry['options']    = $this->post_choices( $entry['post_types'] );
				if ( 'relationship' === $type ) {
					$entry['multiple'] = true;
					$entry['max']      = isset( $field['max'] ) ? (int) $field['max'] : 0;
				}
				break;

			case 'gallery':
				$entry['multiple'] = true;
				$entry['max']      = isset( $field['max'] ) ? (int) $field['max'] : 0;
				break;

			case 'date_picker':
			case 'date_time_picker':
			case 'time_picker':
				// Format de stockage ACF, pour que l'éditeur écrive la bonne chaîne.
				$entry['storage_format'] = $this->storage_format( $type );
				break;

			case 'range':
				$entry['min']  = isset( $field['min'] ) ? (float) $field['min'] : 0;
				$entry['max']  = isset( $field['max'] ) ? (float) $field['max'] : 100;
				$entry['step'] = isset( $field['step'] ) && $field['step'] ? (float) $field['step'] : 1;
				break;
		}

		return $entry;
	}

	/**
	 * Champs d'un type de contenu au format de l'éditeur, sans valeurs
	 * (nouvelle entrée générée par l'IA, capacités pour les agents).
	 *
	 * @param string $post_type Type de contenu.
	 * @return array
	 */
	public function describe_type( $post_type ) {
		$out = array();
		foreach ( $this->get_definitions( $post_type ) as $group ) {
			foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $field ) {
				$entry = $this->describe( $field );
				if ( ! empty( $group['conditional'] ) ) {
					$entry['conditional'] = true;
				}
				$out[] = $entry;
			}
		}
		return $out;
	}

	protected function describe_all( $fields ) {
		$out = array();
		foreach ( (array) $fields as $sub ) {
			$out[] = $this->describe( $sub );
		}
		return $out;
	}

	/**
	 * Valeur normalisée d'un champ + méta d'affichage.
	 *
	 * meta porte ce que l'aperçu doit afficher sans re-interroger WordPress :
	 * url d'une image, HTML rendu d'un wysiwyg, libellé d'une relation.
	 *
	 * @param array $field Champ ACF brut.
	 * @param mixed $raw   Valeur brute (format=false).
	 * @return array{value:mixed,meta:mixed}
	 */
	protected function read_value( $field, $raw ) {
		$type = $field['type'];

		switch ( $type ) {
			case 'repeater':
				$rows = array();
				$meta = array();
				foreach ( is_array( $raw ) ? $raw : array() as $raw_row ) {
					if ( ! is_array( $raw_row ) ) {
						continue;
					}
					$read   = $this->read_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $raw_row );
					$rows[] = $read['value'];
					$meta[] = $read['meta'];
				}
				return array(
					'value' => $rows,
					'meta'  => $meta,
				);

			case 'group':
			case 'clone':
				$read = $this->read_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), is_array( $raw ) ? $raw : array() );
				return array(
					'value' => $read['value'],
					'meta'  => $read['meta'],
				);

			case 'flexible_content':
				$rows    = array();
				$meta    = array();
				$layouts = array();
				foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				foreach ( is_array( $raw ) ? $raw : array() as $raw_row ) {
					if ( ! is_array( $raw_row ) ) {
						continue;
					}
					$name = isset( $raw_row['acf_fc_layout'] ) ? $raw_row['acf_fc_layout'] : '';
					if ( ! isset( $layouts[ $name ] ) ) {
						continue; // Layout supprimé de la config ACF : ignoré (et préservé à l'écriture).
					}
					$read          = $this->read_row( $layouts[ $name ]['sub_fields'], $raw_row );
					$row           = $read['value'];
					$row['_layout'] = $name;
					$rows[]        = $row;
					$meta[]        = $read['meta'];
				}
				return array(
					'value' => $rows,
					'meta'  => $meta,
				);

			case 'image':
			case 'file':
				$id = $raw ? absint( $raw ) : 0;
				return array(
					'value' => $id ? $id : null,
					'meta'  => array(
						'url'   => $id ? (string) wp_get_attachment_url( $id ) : '',
						'title' => $id ? Leiho_Plugin::title( $id ) : '',
					),
				);

			case 'gallery':
				$ids   = array();
				$items = array();
				foreach ( is_array( $raw ) ? $raw : array() as $item ) {
					$id = absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : $item );
					if ( ! $id ) {
						continue;
					}
					$ids[]   = $id;
					$items[] = array(
						'id'    => $id,
						'url'   => (string) wp_get_attachment_image_url( $id, 'medium' ),
						'title' => Leiho_Plugin::title( $id ),
					);
				}
				return array(
					'value' => $ids,
					'meta'  => array( 'items' => $items ),
				);

			case 'link':
				$value = array(
					'url'    => '',
					'title'  => '',
					'target' => '',
				);
				if ( is_array( $raw ) ) {
					$value['url']    = isset( $raw['url'] ) ? (string) $raw['url'] : '';
					$value['title']  = isset( $raw['title'] ) ? (string) $raw['title'] : '';
					$value['target'] = isset( $raw['target'] ) ? (string) $raw['target'] : '';
				} elseif ( is_string( $raw ) ) {
					$value['url'] = $raw;
				}
				return array(
					'value' => $value,
					'meta'  => array( 'label' => '' !== $value['title'] ? $value['title'] : $value['url'] ),
				);

			case 'wysiwyg':
				$html = $raw ? (string) $raw : '';
				return array(
					'value' => $html,
					/**
					 * HTML injecté dans l'aperçu pour un wysiwyg.
					 *
					 * Par défaut wpautop(), qui n'est qu'une approximation du rendu
					 * d'un front headless. Un front Astro/Next qui applique son propre
					 * pipeline (rehype, prose…) peut s'aligner via ce filtre.
					 *
					 * @param string $rendered HTML rendu.
					 * @param string $html     Valeur brute stockée.
					 * @param array  $field    Champ ACF.
					 */
					'meta'  => array( 'rendered' => apply_filters( 'leiho_wysiwyg_render', $html ? wpautop( $html ) : '', $html, $field ) ),
				);

			case 'true_false':
				return array(
					'value' => (bool) $raw,
					'meta'  => null,
				);

			case 'number':
			case 'range':
				return array(
					'value' => ( '' === $raw || null === $raw ) ? null : (float) $raw,
					'meta'  => null,
				);

			case 'post_object':
				$id = $raw ? absint( is_array( $raw ) ? reset( $raw ) : $raw ) : 0;
				return array(
					'value' => $id ? $id : null,
					'meta'  => array( 'label' => $id ? Leiho_Plugin::title( $id ) : '' ),
				);

			case 'relationship':
				$ids    = array();
				$labels = array();
				foreach ( is_array( $raw ) ? $raw : array() as $item ) {
					$id = absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : $item );
					if ( ! $id ) {
						continue;
					}
					$ids[]    = $id;
					$labels[] = array(
						'id'    => $id,
						'label' => Leiho_Plugin::title( $id ),
					);
				}
				return array(
					'value' => $ids,
					'meta'  => array( 'items' => $labels ),
				);

			case 'taxonomy':
				$ids    = array();
				$labels = array();
				foreach ( is_array( $raw ) ? $raw : ( $raw ? array( $raw ) : array() ) as $item ) {
					$id = absint( is_array( $item ) && isset( $item['term_id'] ) ? $item['term_id'] : $item );
					if ( ! $id ) {
						continue;
					}
					$term     = get_term( $id );
					$ids[]    = $id;
					$labels[] = array(
						'id'    => $id,
						'label' => ( $term && ! is_wp_error( $term ) ) ? $term->name : (string) $id,
					);
				}
				return array(
					'value' => $ids,
					'meta'  => array( 'items' => $labels ),
				);

			case 'select':
				// Select multiple : une liste de choix, pas une chaîne « Array ».
				if ( ! empty( $field['multiple'] ) ) {
					return array(
						'value' => array_values( array_map( 'strval', array_filter( (array) $raw, 'is_scalar' ) ) ),
						'meta'  => null,
					);
				}
				return array(
					'value' => null === $raw || is_array( $raw ) ? '' : (string) $raw,
					'meta'  => null,
				);

			default:
				return array(
					'value' => null === $raw ? '' : (string) $raw,
					'meta'  => null,
				);
		}
	}

	/**
	 * Lit une ligne (repeater, group, layout flexible) : valeurs + méta par sous-champ.
	 *
	 * @param array $subs    Sous-champs ACF bruts.
	 * @param array $raw_row Ligne brute, indexée par key ou par name selon le contexte.
	 * @return array{value:array,meta:array}
	 */
	protected function read_row( $subs, $raw_row ) {
		$value = array();
		$meta  = array();

		foreach ( (array) $subs as $sub ) {
			$raw = null;
			if ( array_key_exists( $sub['key'], $raw_row ) ) {
				$raw = $raw_row[ $sub['key'] ];
			} elseif ( array_key_exists( $sub['name'], $raw_row ) ) {
				$raw = $raw_row[ $sub['name'] ];
			}

			$read                   = $this->read_value( $sub, $raw );
			$value[ $sub['name'] ]  = $read['value'];
			$meta[ $sub['name'] ]   = $read['meta'];
		}

		return array(
			'value' => $value,
			'meta'  => $meta,
		);
	}

	protected function storage_format( $type ) {
		switch ( $type ) {
			case 'date_picker':
				return 'Ymd';
			case 'date_time_picker':
				return 'Y-m-d H:i:s';
			default:
				return 'H:i:s';
		}
	}

	protected function taxonomy_choices( $taxonomy ) {
		$choices = array();
		$terms   = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_CHOICES,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $choices;
		}
		foreach ( $terms as $term ) {
			$choices[ (string) $term->term_id ] = $term->name;
		}
		return $choices;
	}

	protected function post_choices( $post_types ) {
		$choices = array();
		$posts   = get_posts(
			array(
				'post_type'      => $post_types ? $post_types : 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::MAX_CHOICES,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			// Brouillons d'autres auteurs, contenus privés : pas pour qui ne peut pas les lire.
			if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$choices[ (string) $post->ID ] = Leiho_Plugin::title( $post );
		}
		return $choices;
	}

	/** Types sans valeur (mise en forme de l'écran d'édition ACF). */
	const LAYOUT_TYPES = array( 'tab', 'message', 'accordion' );

	/**
	 * Valeur d'un champ telle que le front la consomme.
	 *
	 * Lit la valeur brute (format=false), comme l'éditeur, puis la met en
	 * forme une seule fois ici : le front n'a plus à connaître ACF (valeur
	 * formatée ou brute, clés field_… ou noms, cache par requête).
	 *
	 * @param array $field Champ ACF brut.
	 * @param mixed $raw   Valeur brute.
	 * @return mixed
	 */
	public function public_value( $field, $raw ) {
		$type = $field['type'];

		switch ( $type ) {
			case 'repeater':
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $raw_row ) {
					if ( is_array( $raw_row ) ) {
						$rows[] = $this->public_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $raw_row );
					}
				}
				return $rows;

			case 'group':
			case 'clone':
				return $this->public_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), is_array( $raw ) ? $raw : array() );

			case 'flexible_content':
				$layouts = array();
				foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				$rows = array();
				foreach ( is_array( $raw ) ? $raw : array() as $raw_row ) {
					$name = is_array( $raw_row ) && isset( $raw_row['acf_fc_layout'] ) ? $raw_row['acf_fc_layout'] : '';
					if ( ! isset( $layouts[ $name ] ) ) {
						continue;
					}
					$rows[] = array_merge(
						array( '_layout' => $name ),
						$this->public_row( isset( $layouts[ $name ]['sub_fields'] ) ? $layouts[ $name ]['sub_fields'] : array(), $raw_row )
					);
				}
				return $rows;

			case 'image':
			case 'file':
				return Leiho_Content::media( $raw ? absint( is_array( $raw ) && isset( $raw['ID'] ) ? $raw['ID'] : $raw ) : 0 );

			case 'gallery':
				$items = array();
				foreach ( is_array( $raw ) ? $raw : array() as $item ) {
					$media = Leiho_Content::media( absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : $item ) );
					if ( $media ) {
						$items[] = $media;
					}
				}
				return $items;

			case 'link':
				$link = array(
					'url'    => '',
					'title'  => '',
					'target' => '',
				);
				if ( is_array( $raw ) ) {
					foreach ( array_keys( $link ) as $part ) {
						$link[ $part ] = isset( $raw[ $part ] ) ? (string) $raw[ $part ] : '';
					}
				} elseif ( is_string( $raw ) ) {
					$link['url'] = $raw;
				}
				return '' === $link['url'] ? null : $link;

			case 'page_link':
				if ( is_array( $raw ) ) {
					$raw = reset( $raw );
				}
				if ( is_numeric( $raw ) ) {
					$ref = Leiho_Content::ref( absint( $raw ) );
					return $ref ? $ref['path'] : '';
				}
				return null === $raw || false === $raw ? '' : (string) $raw;

			case 'wysiwyg':
				return Leiho_Content::html( $raw ? (string) $raw : '', $field );

			case 'true_false':
				return (bool) $raw;

			case 'number':
			case 'range':
				return is_numeric( $raw ) ? 0 + $raw : null;

			case 'date_picker':
				// Stockage ACF Ymd → ISO 8601, lisible par Date et par les humains.
				$raw = (string) $raw;
				return preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ? $m[1] . '-' . $m[2] . '-' . $m[3] : $raw;

			case 'post_object':
			case 'relationship':
				$ids  = is_array( $raw ) ? $raw : ( $raw ? array( $raw ) : array() );
				$refs = array();
				foreach ( $ids as $item ) {
					$ref = Leiho_Content::ref( absint( is_array( $item ) && isset( $item['ID'] ) ? $item['ID'] : $item ) );
					if ( $ref ) {
						$refs[] = $ref;
					}
				}
				if ( 'post_object' === $type && empty( $field['multiple'] ) ) {
					return $refs ? $refs[0] : null;
				}
				return $refs;

			case 'taxonomy':
				$ids   = is_array( $raw ) ? $raw : ( $raw ? array( $raw ) : array() );
				$terms = array();
				foreach ( $ids as $item ) {
					$term = Leiho_Content::term( absint( is_array( $item ) && isset( $item['term_id'] ) ? $item['term_id'] : $item ) );
					if ( $term ) {
						$terms[] = $term;
					}
				}
				if ( ! self::taxonomy_is_multiple( $field ) ) {
					return $terms ? $terms[0] : null;
				}
				return $terms;

			case 'select':
				if ( ! empty( $field['multiple'] ) ) {
					return array_values( array_map( 'strval', (array) ( $raw ? $raw : array() ) ) );
				}
				return null === $raw || false === $raw ? '' : (string) $raw;

			case 'checkbox':
				return array_values( array_map( 'strval', (array) ( $raw ? $raw : array() ) ) );

			case 'password':
				// Un champ « mot de passe » d'ACF n'est jamais servi : l'API de contenu est publique.
				return '';

			default:
				// Types simples (text, textarea, url, email…) et types inconnus
				// de Leiho : la valeur brute, sans la tronquer en chaîne.
				if ( null === $raw || false === $raw ) {
					return in_array( $type, array( 'text', 'textarea', 'url', 'email', 'oembed', 'password', 'color_picker', 'radio', 'button_group', 'date_time_picker', 'time_picker' ), true ) ? '' : null;
				}
				return is_scalar( $raw ) ? (string) $raw : $raw;
		}
	}

	/**
	 * Ligne de conteneur en valeurs front, indexée par nom de sous-champ.
	 *
	 * @param array $subs    Sous-champs ACF bruts.
	 * @param array $raw_row Ligne brute (clés field_… ou noms).
	 * @return array<string,mixed>
	 */
	protected function public_row( $subs, $raw_row ) {
		$out = array();
		foreach ( (array) $subs as $sub ) {
			if ( '' === (string) $sub['name'] || in_array( $sub['type'], self::LAYOUT_TYPES, true ) ) {
				continue;
			}
			$raw = null;
			if ( array_key_exists( $sub['key'], $raw_row ) ) {
				$raw = $raw_row[ $sub['key'] ];
			} elseif ( array_key_exists( $sub['name'], $raw_row ) ) {
				$raw = $raw_row[ $sub['name'] ];
			}
			$out[ $sub['name'] ] = $this->public_value( $sub, $raw );
		}
		return $out;
	}

	/**
	 * Un champ taxonomie renvoie-t-il plusieurs termes ?
	 *
	 * @param array $field Champ ACF brut.
	 * @return bool
	 */
	public static function taxonomy_is_multiple( $field ) {
		return in_array( isset( $field['field_type'] ) ? $field['field_type'] : 'checkbox', array( 'checkbox', 'multi_select' ), true );
	}

	/**
	 * Compare deux valeurs brutes ACF sans tenir compte des types scalaires
	 * (la base rend "5" là où on a écrit 5) ni des clés de ligne ACF.
	 *
	 * @param mixed $a Valeur relue.
	 * @param mixed $b Valeur écrite.
	 * @return bool
	 */
	protected function same_value( $a, $b ) {
		return $this->normalize_value( $a ) === $this->normalize_value( $b );
	}

	/**
	 * @param mixed $value Valeur brute.
	 * @return mixed
	 */
	protected function normalize_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = $this->normalize_value( $v );
			}
			ksort( $out );
			return $out;
		}
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}
		return (string) $value;
	}

	/**
	 * Applique une valeur à un chemin dans l'arbre d'un champ.
	 *
	 * @param array  $field    Définition ACF du champ courant.
	 * @param mixed  $current  Valeur brute courante du champ.
	 * @param array  $segments Segments de chemin restants ([] = le champ entier).
	 * @param mixed  $value    Valeur reçue.
	 * @param string $error    Code d'erreur (sortie).
	 * @param int    $post_id  Post (contrôle des droits sur les médias).
	 * @return mixed Nouvelle valeur brute du champ.
	 */
	protected function patch( $field, $current, $segments, $value, &$error, $post_id ) {
		$error = null;

		if ( empty( $segments ) ) {
			return $this->sanitize( $field, $value, $error, $post_id );
		}

		$type = $field['type'];

		if ( 'repeater' === $type || 'flexible_content' === $type ) {
			$index = $segments[0];
			if ( ! is_numeric( $index ) ) {
				$error = 'unknown_field';
				return $current;
			}
			$index = (int) $index;
			$rows  = is_array( $current ) ? array_values( $current ) : array();
			if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) ) {
				$error = 'row_not_found';
				return $current;
			}

			$subs = $this->row_subs( $field, $rows[ $index ] );
			$rows[ $index ] = $this->patch_row( $subs, $rows[ $index ], array_slice( $segments, 1 ), $value, $error, $post_id );
			return $rows;
		}

		if ( 'group' === $type || 'clone' === $type ) {
			$subs = isset( $field['sub_fields'] ) ? $field['sub_fields'] : array();
			return $this->patch_row( $subs, is_array( $current ) ? $current : array(), $segments, $value, $error, $post_id );
		}

		$error = 'unknown_field';
		return $current;
	}

	/**
	 * Sous-champs applicables à une ligne : ceux du layout pour un flexible.
	 */
	protected function row_subs( $field, $row ) {
		if ( 'flexible_content' !== $field['type'] ) {
			return isset( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		}
		$name = isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : '';
		foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
			if ( $layout['name'] === $name ) {
				return isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
			}
		}
		return array();
	}

	/**
	 * Patch d'un sous-champ dans une ligne (indexée par key ou par name).
	 */
	protected function patch_row( $subs, $row, $segments, $value, &$error, $post_id ) {
		$name = array_shift( $segments );

		foreach ( (array) $subs as $sub ) {
			if ( $sub['name'] !== $name ) {
				continue;
			}

			$current = null;
			$slot    = $sub['key'];
			if ( array_key_exists( $sub['key'], $row ) ) {
				$current = $row[ $sub['key'] ];
			} elseif ( array_key_exists( $sub['name'], $row ) ) {
				$current = $row[ $sub['name'] ];
				$slot    = $sub['name'];
			}

			$row[ $slot ] = $this->patch( $sub, $current, $segments, $value, $error, $post_id );
			return $row;
		}

		$error = 'unknown_field';
		return $row;
	}

	/**
	 * Sanitize une valeur selon le type ACF.
	 *
	 * @param array  $field   Champ ACF.
	 * @param mixed  $value   Valeur reçue.
	 * @param string $error   Code erreur (sortie), null si OK.
	 * @param int    $post_id Post.
	 * @return mixed Valeur à écrire.
	 */
	protected function sanitize( $field, $value, &$error, $post_id = 0 ) {
		$error = null;
		$type  = $field['type'];

		// « required » est vérifié côté serveur : l'API ne peut pas vider un
		// champ obligatoire, quel que soit le client.
		if ( ! empty( $field['required'] ) && $this->is_empty( $value, $type ) ) {
			$error = 'required';
			return null;
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
				// Texte gardé tel que saisi (ni entités, ni trim) : balises et
				// caractères de contrôle retirés, échappement à l'affichage.
				// Voir Leiho_Text::clean().
				return $this->within_length( $field, Leiho_Text::clean( $value, 'textarea' === $type ), $error );

			case 'wysiwyg':
				return wp_kses_post( (string) $value );

			case 'oembed':
			case 'page_link':
			case 'url':
				$value = trim( (string) $value );
				return '' === $value ? '' : esc_url_raw( $value );

			case 'link':
				return $this->sanitize_link( $value, $error );

			case 'email':
				$value = trim( (string) $value );
				if ( '' === $value ) {
					return '';
				}
				$clean = sanitize_email( $value );
				if ( '' === $clean ) {
					$error = 'invalid_email';
					return null;
				}
				return $clean;

			case 'number':
			case 'range':
				if ( '' === $value || null === $value ) {
					return '';
				}
				if ( ! is_numeric( $value ) ) {
					$error = 'invalid_number';
					return null;
				}
				return (float) $value;

			case 'select':
				$choices = isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array();
				$value   = (string) $value;
				if ( '' === $value ) {
					return '';
				}
				if ( ! array_key_exists( $value, $choices ) ) {
					$error = 'invalid_choice';
					return null;
				}
				return $value;

			case 'true_false':
				return $value ? 1 : 0;

			case 'image':
			case 'file':
				$id = absint( $value );
				if ( 0 === $id ) {
					return '';
				}
				if ( ! $this->can_use_attachment( $id ) ) {
					$error = 'invalid_attachment';
					return null;
				}
				return $id;

			case 'gallery':
				return $this->sanitize_ids(
					$value,
					isset( $field['max'] ) ? (int) $field['max'] : 0,
					array( $this, 'can_use_attachment' ),
					$error
				);

			case 'relationship':
				return $this->sanitize_ids(
					$value,
					isset( $field['max'] ) ? (int) $field['max'] : 0,
					array( $this, 'is_readable_post' ),
					$error
				);

			case 'post_object':
				$id = absint( is_array( $value ) ? reset( $value ) : $value );
				if ( 0 === $id ) {
					return '';
				}
				if ( ! $this->is_readable_post( $id ) ) {
					$error = 'invalid_post';
					return null;
				}
				return $id;

			case 'taxonomy':
				$taxonomy = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'category';
				$ids      = array();
				foreach ( is_array( $value ) ? $value : ( '' === $value || null === $value ? array() : array( $value ) ) as $item ) {
					$id   = absint( $item );
					$term = $id ? get_term( $id, $taxonomy ) : null;
					if ( ! $term || is_wp_error( $term ) ) {
						$error = 'invalid_term';
						return null;
					}
					$ids[] = $id;
				}
				return $ids;

			case 'date_picker':
			case 'date_time_picker':
			case 'time_picker':
				return $this->sanitize_date( $type, $value, $error );

			case 'color_picker':
				$value = trim( (string) $value );
				if ( '' === $value ) {
					return '';
				}
				if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value )
					|| preg_match( '/^rgba?\(\s*[\d.]+%?(\s*,\s*[\d.]+%?){2}(\s*,\s*[\d.]+\s*)?\)$/', $value ) ) {
					return $value;
				}
				$error = 'invalid_color';
				return null;

			case 'repeater':
				return $this->sanitize_rows( $field, $value, $error, $post_id );

			case 'group':
			case 'clone':
				if ( ! is_array( $value ) ) {
					$error = 'invalid_group';
					return null;
				}
				return $this->sanitize_row( isset( $field['sub_fields'] ) ? $field['sub_fields'] : array(), $value, $error, $post_id );

			case 'flexible_content':
				return $this->sanitize_layouts( $field, $value, $error, $post_id );
		}

		$error = 'unsupported_type';
		return null;
	}

	protected function within_length( $field, $value, &$error ) {
		$max = isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 0;
		if ( $max > 0 ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( $length > $max ) {
				$error = 'too_long';
				return null;
			}
		}
		return $value;
	}

	protected function is_empty( $value, $type ) {
		if ( null === $value ) {
			return true;
		}
		if ( in_array( $type, array( 'true_false' ), true ) ) {
			return false; // Un booléen « faux » est une valeur.
		}
		if ( is_array( $value ) ) {
			if ( 'link' === $type ) {
				return '' === trim( (string) ( isset( $value['url'] ) ? $value['url'] : '' ) );
			}
			return empty( $value );
		}
		return '' === trim( (string) $value );
	}

	protected function sanitize_link( $value, &$error ) {
		if ( is_string( $value ) ) {
			$value = array( 'url' => $value );
		}
		if ( ! is_array( $value ) ) {
			$error = 'invalid_link';
			return null;
		}

		$url = trim( (string) ( isset( $value['url'] ) ? $value['url'] : '' ) );
		$target = isset( $value['target'] ) ? (string) $value['target'] : '';

		return array(
			'url'    => '' === $url ? '' : esc_url_raw( $url ),
			// Libellé : texte brut sur une ligne, comme un champ text.
			'title'  => Leiho_Text::clean( isset( $value['title'] ) ? $value['title'] : '' ),
			'target' => '_blank' === $target ? '_blank' : '',
		);
	}

	protected function sanitize_date( $type, $value, &$error ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$format    = $this->storage_format( $type );
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			$error = 'invalid_date';
			return null;
		}
		return gmdate( $format, $timestamp );
	}

	protected function sanitize_ids( $value, $max, $validator, &$error ) {
		if ( ! is_array( $value ) ) {
			$value = ( '' === $value || null === $value ) ? array() : array( $value );
		}
		$ids = array();
		foreach ( $value as $item ) {
			$id = absint( is_array( $item ) && isset( $item['id'] ) ? $item['id'] : $item );
			if ( ! $id ) {
				continue;
			}
			if ( ! call_user_func( $validator, $id ) ) {
				$error = 'invalid_reference';
				return null;
			}
			$ids[] = $id;
		}
		if ( $max > 0 && count( $ids ) > $max ) {
			$error = 'too_many_items';
			return null;
		}
		return $ids;
	}

	/**
	 * G : un attachement doit exister ET être lisible par l'utilisateur courant —
	 * sur un site multi-auteurs, pointer le média privé d'un autre était possible.
	 */
	protected function can_use_attachment( $id ) {
		return 'attachment' === get_post_type( $id ) && current_user_can( 'read_post', $id );
	}

	protected function is_readable_post( $id ) {
		$post = get_post( $id );
		return $post && current_user_can( 'read_post', $id );
	}

	/**
	 * Lignes d'un repeater : le payload remplace tout (ajout, suppression et
	 * réordonnancement passent par le même chemin).
	 */
	protected function sanitize_rows( $field, $value, &$error, $post_id ) {
		if ( ! is_array( $value ) ) {
			$error = 'invalid_rows';
			return null;
		}

		$subs = isset( $field['sub_fields'] ) ? $field['sub_fields'] : array();
		$max  = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$min  = isset( $field['min'] ) ? (int) $field['min'] : 0;
		$rows = array_values( $value );

		if ( $max > 0 && count( $rows ) > $max ) {
			$error = 'too_many_rows';
			return null;
		}
		if ( $min > 0 && count( $rows ) < $min ) {
			$error = 'too_few_rows';
			return null;
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sub_error = null;
			$clean_row = $this->sanitize_row( $subs, $row, $sub_error, $post_id );
			if ( null !== $sub_error ) {
				$error = $sub_error;
				return null;
			}
			$clean[] = $clean_row;
		}

		return $clean;
	}

	/**
	 * Lignes d'un flexible content : chaque ligne porte son layout.
	 */
	protected function sanitize_layouts( $field, $value, &$error, $post_id ) {
		if ( ! is_array( $value ) ) {
			$error = 'invalid_rows';
			return null;
		}

		$layouts = array();
		foreach ( (array) ( isset( $field['layouts'] ) ? $field['layouts'] : array() ) as $layout ) {
			$layouts[ $layout['name'] ] = $layout;
		}

		$max  = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$rows = array_values( $value );
		if ( $max > 0 && count( $rows ) > $max ) {
			$error = 'too_many_rows';
			return null;
		}

		$counts = array();
		$clean  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = '';
			if ( isset( $row['_layout'] ) ) {
				$name = (string) $row['_layout'];
			} elseif ( isset( $row['acf_fc_layout'] ) ) {
				$name = (string) $row['acf_fc_layout'];
			}
			if ( ! isset( $layouts[ $name ] ) ) {
				$error = 'unknown_layout';
				return null;
			}

			unset( $row['_layout'], $row['acf_fc_layout'] );

			$sub_error = null;
			$clean_row = $this->sanitize_row( $layouts[ $name ]['sub_fields'], $row, $sub_error, $post_id );
			if ( null !== $sub_error ) {
				$error = $sub_error;
				return null;
			}

			$counts[ $name ] = isset( $counts[ $name ] ) ? $counts[ $name ] + 1 : 1;
			$layout_max      = isset( $layouts[ $name ]['max'] ) ? (int) $layouts[ $name ]['max'] : 0;
			if ( $layout_max > 0 && $counts[ $name ] > $layout_max ) {
				$error = 'too_many_rows:' . $name;
				return null;
			}

			$clean_row['acf_fc_layout'] = $name;
			$clean[]                    = $clean_row;
		}

		foreach ( $layouts as $name => $layout ) {
			$layout_min = isset( $layout['min'] ) ? (int) $layout['min'] : 0;
			if ( $layout_min > 0 && ( ! isset( $counts[ $name ] ) || $counts[ $name ] < $layout_min ) ) {
				$error = 'too_few_rows:' . $name;
				return null;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize les sous-champs d'une ligne. Les sous-champs absents du payload
	 * ne sont pas touchés ; ceux que Leiho ne sait pas éditer repassent pour ne
	 * pas être effacés par le remplacement de la ligne, mais filtrés : la valeur
	 * vient de la requête, pas de la base.
	 */
	protected function sanitize_row( $subs, $row, &$error, $post_id ) {
		$clean = array();

		foreach ( (array) $subs as $sub ) {
			$has_name = array_key_exists( $sub['name'], $row );
			$has_key  = array_key_exists( $sub['key'], $row );
			if ( ! $has_name && ! $has_key ) {
				continue;
			}

			$value = $has_name ? $row[ $sub['name'] ] : $row[ $sub['key'] ];

			if ( ! in_array( $sub['type'], static::SUPPORTED_TYPES, true ) ) {
				$clean[ $sub['key'] ] = $this->sanitize_unknown( $value );
				continue;
			}

			$sub_error = null;
			$sanitized = $this->sanitize( $sub, $value, $sub_error, $post_id );
			if ( null !== $sub_error ) {
				$error = $sub_error . ':' . $sub['name'];
				return null;
			}
			$clean[ $sub['key'] ] = $sanitized;
		}

		return $clean;
	}

	/**
	 * Valeur d'un sous-champ que Leiho n'édite pas (radio, case à cocher,
	 * carte, utilisateur…), renvoyée telle quelle par l'éditeur : scalaires
	 * gardés, HTML filtré comme un contenu, profondeur bornée.
	 *
	 * @param mixed $value Valeur reçue.
	 * @param int   $depth Profondeur.
	 * @return mixed
	 */
	protected function sanitize_unknown( $value, $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth >= 5 ) {
				return array();
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] = $this->sanitize_unknown( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			// Sans balise, rien à filtrer (et R&D reste R&D).
			return false === strpos( $value, '<' ) ? $value : wp_kses_post( $value );
		}
		return is_scalar( $value ) || null === $value ? $value : null;
	}
}
