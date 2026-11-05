<?php
defined( 'ABSPATH' ) || exit;

/**
 * Référencement et citations IA, sans champ en plus : une collection déclare
 * quels champs existants servent de description, d'image et de FAQ, et quel
 * type schema.org la décrit. Chaque contenu servi au front porte alors un
 * bloc `seo` (titre, description, image, FAQ) et ses nœuds JSON-LD, dans la
 * langue de la requête puisqu'ils sont lus dans les champs déjà traduits.
 *
 *   "seo": { "type": "TechArticle", "description": "summary", "image": "cover", "faq": "faq" }
 *
 * Hors Collections (ACF, pages), la description vient de Yoast, Rank Math ou
 * SEOPress s'ils sont installés, sinon de l'extrait.
 */
class Leiho_Seo {

	/** Pages : le type remplace celui de la page (WebPage). */
	const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'ProfilePage' );

	/** Articles : un nœud à part, rattaché à la page. */
	const ARTICLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' );

	/** Autres sujets : un nœud à part, dont la page parle (mainEntity). */
	const THING_TYPES = array( 'Product', 'Service', 'Event', 'Course', 'SoftwareApplication', 'Person', 'Organization', 'LocalBusiness', 'Place', 'Recipe', 'CreativeWork' );

	/** Clés de la configuration et types de champ acceptés pour chacune. */
	const ROLES = array(
		'title'       => array( 'text' ),
		'description' => array( 'text', 'richtext' ),
		'image'       => array( 'image' ),
		'faq'         => array( 'list' ),
	);

	/**
	 * Tous les types schema.org proposés.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array_merge( self::PAGE_TYPES, self::ARTICLE_TYPES, self::THING_TYPES );
	}

	/* ---------------------------------------------------------------------
	 * Schéma
	 * ------------------------------------------------------------------- */

	/**
	 * Valide le bloc `seo` d'une collection.
	 *
	 * @param mixed  $input  Bloc reçu.
	 * @param array  $fields Champs normalisés de la collection.
	 * @param string $path   Chemin pour les erreurs (collections.0.seo).
	 * @param array  $errors Erreurs (sortie).
	 * @return array|null Null si rien n'est déclaré.
	 */
	public static function normalize( $input, $fields, $path, &$errors ) {
		if ( null === $input || array() === $input ) {
			return null;
		}
		if ( ! is_array( $input ) ) {
			$errors[] = array(
				'path'    => $path,
				'message' => __( 'Must be an object.', 'leiho' ),
			);
			return null;
		}

		$out = array();

		if ( isset( $input['type'] ) && '' !== $input['type'] ) {
			if ( in_array( $input['type'], self::types(), true ) ) {
				$out['type'] = $input['type'];
			} else {
				$errors[] = array(
					'path'    => $path . '.type',
					/* translators: %s: list of schema.org types. */
					'message' => sprintf( __( 'Use one of: %s.', 'leiho' ), implode( ', ', self::types() ) ),
				);
			}
		}

		$by_name = array();
		foreach ( $fields as $field ) {
			$by_name[ $field['name'] ] = $field;
		}

		foreach ( self::ROLES as $role => $types ) {
			if ( ! isset( $input[ $role ] ) || '' === $input[ $role ] ) {
				continue;
			}
			$name  = $input[ $role ];
			$field = is_string( $name ) && isset( $by_name[ $name ] ) ? $by_name[ $name ] : null;
			if ( ! $field ) {
				$errors[] = array(
					'path'    => $path . '.' . $role,
					/* translators: %s: field name. */
					'message' => sprintf( __( 'No field named “%s” at the top level of this collection.', 'leiho' ), is_scalar( $name ) ? (string) $name : '?' ),
				);
				continue;
			}
			if ( ! in_array( $field['type'], $types, true ) ) {
				$errors[] = array(
					'path'    => $path . '.' . $role,
					/* translators: 1: field name, 2: allowed field types. */
					'message' => sprintf( __( '“%1$s” must be a field of type %2$s.', 'leiho' ), $name, implode( ' or ', $types ) ),
				);
				continue;
			}
			if ( 'faq' === $role && ! self::faq_fields( $field ) ) {
				$errors[] = array(
					'path'    => $path . '.faq',
					/* translators: %s: field name. */
					'message' => sprintf( __( '“%s” needs a “question” and an “answer” text field.', 'leiho' ), $name ),
				);
				continue;
			}
			$out[ $role ] = $name;
		}

		if ( isset( $input['llms'] ) && false === $input['llms'] ) {
			$out['llms'] = false;
		}

		return $out ? $out : null;
	}

	/**
	 * Une liste de questions : sous-champs « question » et « answer » en texte.
	 *
	 * @param array $field Champ list.
	 * @return bool
	 */
	private static function faq_fields( $field ) {
		$names = array();
		foreach ( isset( $field['fields'] ) ? $field['fields'] : array() as $sub ) {
			if ( in_array( $sub['type'], array( 'text', 'richtext' ), true ) ) {
				$names[] = $sub['name'];
			}
		}
		return in_array( 'question', $names, true ) && in_array( 'answer', $names, true );
	}

	/* ---------------------------------------------------------------------
	 * Contenu servi au front
	 * ------------------------------------------------------------------- */

	/**
	 * Bloc `seo` d'un contenu, calculé sur l'item déjà mis en forme (champs
	 * traduits, médias résolus).
	 *
	 * @param WP_Post $post Post.
	 * @param array   $item Item de l'API de contenu.
	 * @return array
	 */
	public static function item( $post, $item ) {
		$collection = class_exists( 'Leiho_Collections' ) ? Leiho_Collections::for_post_type( $post->post_type ) : null;
		$config     = $collection && ! empty( $collection['seo'] ) ? $collection['seo'] : array();
		$fields     = is_object( $item['fields'] ) ? (array) $item['fields'] : (array) $item['fields'];

		$title = isset( $config['title'] ) ? self::plain( isset( $fields[ $config['title'] ] ) ? $fields[ $config['title'] ] : '' ) : '';
		if ( '' === $title ) {
			$title = (string) $item['title'];
		}

		$description = isset( $config['description'] ) ? self::plain( isset( $fields[ $config['description'] ] ) ? $fields[ $config['description'] ] : '' ) : '';
		if ( '' === $description && $collection && ! isset( $config['description'] ) ) {
			$description = self::guess_description( $collection, $fields );
		}
		if ( '' === $description ) {
			$description = self::plugin_description( $post->ID );
		}
		if ( '' === $description ) {
			$description = (string) $item['excerpt'];
		}
		$description = self::shorten( $description, 300 );

		$image = isset( $config['image'], $fields[ $config['image'] ] ) && is_array( $fields[ $config['image'] ] ) ? $fields[ $config['image'] ] : null;
		if ( ! $image && ! empty( $item['featured_image'] ) ) {
			$image = $item['featured_image'];
		}

		$faq = array();
		if ( isset( $config['faq'], $fields[ $config['faq'] ] ) && is_array( $fields[ $config['faq'] ] ) ) {
			foreach ( $fields[ $config['faq'] ] as $row ) {
				$row      = (array) $row;
				$question = self::plain( isset( $row['question'] ) ? $row['question'] : '' );
				$answer   = self::plain( isset( $row['answer'] ) ? $row['answer'] : '' );
				if ( '' !== $question && '' !== $answer ) {
					$faq[] = array(
						'question' => $question,
						'answer'   => $answer,
					);
				}
			}
		}

		$type = isset( $config['type'] ) ? $config['type'] : ( 'post' === $post->post_type ? 'BlogPosting' : 'WebPage' );

		$seo = array(
			'type'        => $type,
			'title'       => $title,
			'description' => $description,
			'image'       => $image,
			'faq'         => $faq,
			'llms'        => ! isset( $config['llms'] ) || false !== $config['llms'],
		);

		$seo['jsonld'] = self::nodes( $post, $item, $seo );

		/**
		 * Bloc SEO d'un contenu : compléter le JSON-LD (prix d'un produit,
		 * lieu d'un événement…) ou corriger la description.
		 *
		 * @param array   $seo  Bloc seo.
		 * @param WP_Post $post Post.
		 * @param array   $item Item servi au front.
		 */
		return apply_filters( 'leiho_seo', $seo, $post, $item );
	}

	/**
	 * Nœuds JSON-LD propres au contenu. Le front les place dans le graphe de
	 * la page, à côté du site et de l'organisation (leiho/seo).
	 *
	 * @param WP_Post $post Post.
	 * @param array   $item Item.
	 * @param array   $seo  Bloc seo (sans jsonld).
	 * @return array[]
	 */
	public static function nodes( $post, $item, $seo ) {
		$url   = (string) $item['url'];
		$lang  = isset( $item['locale'] ) ? (string) $item['locale'] : self::language();
		$type  = $seo['type'];
		$image = $seo['image'] && ! empty( $seo['image']['url'] ) ? $seo['image']['url'] : null;
		$nodes = array();

		$page = array(
			'@type'         => in_array( $type, self::PAGE_TYPES, true ) ? $type : 'WebPage',
			'@id'           => $url . '#webpage',
			'url'           => $url,
			'name'          => $seo['title'],
			'description'   => $seo['description'],
			'inLanguage'    => $lang,
			'datePublished' => $item['date'],
			'dateModified'  => $item['modified'],
		);
		if ( $image ) {
			$page['primaryImageOfPage'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}

		if ( in_array( $type, self::ARTICLE_TYPES, true ) ) {
			$article = array(
				'@type'            => $type,
				'@id'              => $url . '#article',
				'headline'         => self::shorten( $seo['title'], 110 ),
				'description'      => $seo['description'],
				'inLanguage'       => $lang,
				'datePublished'    => $item['date'],
				'dateModified'     => $item['modified'],
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			);
			if ( $image ) {
				$article['image'] = $image;
			}
			$author = post_type_supports( $post->post_type, 'author' ) ? get_the_author_meta( 'display_name', $post->post_author ) : '';
			if ( $author ) {
				$article['author'] = array(
					'@type' => 'Person',
					'name'  => $author,
				);
			}
			$nodes[] = $article;
		} elseif ( in_array( $type, self::THING_TYPES, true ) ) {
			$thing = array(
				'@type'       => $type,
				'@id'         => $url . '#' . strtolower( $type ),
				'name'        => $seo['title'],
				'description' => $seo['description'],
				'url'         => $url,
			);
			if ( $image ) {
				$thing['image'] = $image;
			}
			$page['mainEntity'] = array( '@id' => $thing['@id'] );
			$nodes[]            = $thing;
		}

		array_unshift( $nodes, $page );

		if ( $seo['faq'] ) {
			$faq = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#faq',
				'inLanguage' => $lang,
				'mainEntity' => array(),
			);
			foreach ( $seo['faq'] as $row ) {
				$faq['mainEntity'][] = array(
					'@type'          => 'Question',
					'name'           => $row['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $row['answer'],
					),
				);
			}
			// Page FAQ : la page est la FAQ. Sinon la FAQ en fait partie.
			if ( 'FAQPage' === $page['@type'] ) {
				$nodes[0]['mainEntity'] = $faq['mainEntity'];
			} else {
				$faq['isPartOf'] = array( '@id' => $url . '#webpage' );
				$nodes[]         = $faq;
			}
		}

		return array_map( array( __CLASS__, 'prune' ), $nodes );
	}

	/** Noms de champ qui portent d'habitude le résumé d'une entrée. */
	const DESCRIPTION_NAMES = array( 'description', 'summary', 'excerpt', 'intro', 'lead', 'subtitle', 'tagline' );

	/**
	 * Sans champ déclaré : un champ texte nommé comme un résumé, sinon le
	 * premier texte racine assez long pour en être un.
	 *
	 * @param array $collection Collection.
	 * @param array $fields     Valeurs de l'item.
	 * @return string
	 */
	private static function guess_description( $collection, $fields ) {
		$texts = array();
		foreach ( $collection['fields'] as $field ) {
			if ( in_array( $field['type'], array( 'text', 'richtext' ), true ) && isset( $fields[ $field['name'] ] ) ) {
				$texts[ $field['name'] ] = self::plain( $fields[ $field['name'] ] );
			}
		}
		foreach ( self::DESCRIPTION_NAMES as $name ) {
			if ( ! empty( $texts[ $name ] ) ) {
				return $texts[ $name ];
			}
		}
		foreach ( $texts as $text ) {
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) >= 50 ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * Description saisie dans une extension SEO (Yoast, Rank Math, SEOPress),
	 * si elle ne contient pas de variables de modèle.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private static function plugin_description( $post_id ) {
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_aioseo_description' ) as $key ) {
			$value = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $value && false === strpos( $value, '%' ) && false === strpos( $value, '#' ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Description du site, pour le site et llms.txt.
	 *
	 * @return string
	 */
	public static function site_description() {
		return html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Langue du site (fr-FR → fr-FR, tel que WordPress la déclare).
	 *
	 * @return string
	 */
	private static function language() {
		return (string) get_bloginfo( 'language' );
	}

	/**
	 * Texte brut d'une valeur de champ (texte riche compris).
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	public static function plain( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Coupe au dernier mot avant la limite.
	 *
	 * @param string $text  Texte.
	 * @param int    $limit Caractères.
	 * @return string
	 */
	public static function shorten( $text, $limit ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $limit : strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit - 1 ) : substr( $text, 0, $limit - 1 );
		$cut = preg_replace( '/\s+\S*$/u', '', $cut );
		return rtrim( $cut, " ,;:.-" ) . '…';
	}

	/**
	 * Retire les propriétés vides d'un nœud.
	 *
	 * @param array $node Nœud.
	 * @return array
	 */
	private static function prune( $node ) {
		return array_filter(
			$node,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}
}
