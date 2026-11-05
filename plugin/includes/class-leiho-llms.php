<?php
defined( 'ABSPATH' ) || exit;

/**
 * Le contenu pour les assistants IA, depuis WordPress :
 *
 *   GET /wp-json/leiho/v1/llms.txt           index du site (llmstxt.org)
 *   GET /wp-json/leiho/v1/llms.txt?full=1    tout le contenu en Markdown, en un fichier
 *   GET /wp-json/leiho/v1/markdown?path=/x   une entrée en Markdown
 *
 * Pour un front rendu à la demande (Next.js, Nuxt…) : une réécriture de
 * /llms.txt et de /page.md vers ces adresses suffit. Un site statique peut
 * aussi tirer ces fichiers de ses pages au build (leiho/ai-ready).
 * Mêmes droits de lecture que l'API de contenu, même cache, même langue (?locale=).
 */
class Leiho_Llms {

	/** Types de champ sans texte à lire. */
	const SKIP_TYPES = array( 'number', 'boolean', 'date', 'datetime', 'select', 'slug', 'email', 'url', 'color', 'file', 'reference', 'gallery' );

	public static function register_routes() {
		$locale = array(
			'type'    => 'string',
			'default' => '',
		);
		register_rest_route(
			Leiho_Rest::NS,
			'/llms.txt',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_llms' ),
				'permission_callback' => array( 'Leiho_Content', 'can_read' ),
				'args'                => array(
					'locale' => $locale,
					'full'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// Liens vers page.md quand le front sert le Markdown.
					'md'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Leiho_Rest::NS,
			'/markdown',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_markdown' ),
				'permission_callback' => array( 'Leiho_Content', 'can_read' ),
				'args'                => array(
					'path'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'locale' => $locale,
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------- */

	/**
	 * GET /llms.txt
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function get_llms( WP_REST_Request $request ) {
		// Langue validée : elle entre dans la clé de cache, qui doit rester bornée.
		$locale = (string) Leiho_I18n::valid( (string) $request->get_param( 'locale' ) );
		$full   = (bool) $request->get_param( 'full' );
		$md     = (bool) $request->get_param( 'md' );
		$body   = (string) Leiho_Cache::remember_stored(
			'llms:' . $locale . ':' . (int) $full . ':' . (int) $md,
			static function () use ( $locale, $full, $md, $request ) {
				return self::llms( self::export( $locale, $request ), $full, $md );
			}
		);
		return self::text( $request, $body, 'text/plain' );
	}

	/**
	 * GET /markdown?path=
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_markdown( WP_REST_Request $request ) {
		$inner = self::inner( '/content', $request );
		$inner->set_param( 'path', (string) $request->get_param( 'path' ) );
		$inner->set_param( 'locale', (string) $request->get_param( 'locale' ) );
		$items = self::data( rest_do_request( $inner ) );
		if ( empty( $items[0] ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Content not found.', 'leiho' ), array( 'status' => 404 ) );
		}
		$response = self::text( $request, self::markdown( $items[0] ), 'text/markdown' );
		if ( ! empty( $items[0]['url'] ) ) {
			$response->header( 'Link', '<' . esc_url_raw( $items[0]['url'] ) . '>; rel="canonical"' );
		}
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Mise en forme
	 * ------------------------------------------------------------------- */

	/**
	 * llms.txt depuis l'export : le site, puis ses contenus par type.
	 *
	 * @param array $export Export (tableaux).
	 * @param bool  $full   Tout le contenu à la suite (llms-full.txt).
	 * @param bool  $md     Lier les versions Markdown (page.md).
	 * @return string
	 */
	public static function llms( $export, $full = false, $md = false ) {
		$site  = isset( $export['site'] ) ? $export['site'] : array();
		$items = isset( $export['items'] ) ? $export['items'] : array();
		$lines = array( '# ' . ( isset( $site['name'] ) ? $site['name'] : '' ), '' );
		if ( ! empty( $site['description'] ) ) {
			$lines[] = '> ' . $site['description'];
			$lines[] = '';
		}

		/**
		 * Texte d'introduction de llms.txt (ce qu'est le site, à qui il s'adresse).
		 *
		 * @param string $intro Texte Markdown.
		 * @param array  $site  Site (nom, adresse, langue).
		 */
		$intro = (string) apply_filters( 'leiho_llms_intro', '', $site );
		if ( '' !== $intro ) {
			$lines[] = $intro;
			$lines[] = '';
		}

		$groups = array();
		foreach ( $items as $item ) {
			if ( empty( $item['url'] ) || ( isset( $item['seo']['llms'] ) && false === $item['seo']['llms'] ) ) {
				continue;
			}
			$groups[ $item['type'] ][] = $item;
		}

		foreach ( $groups as $type => $list ) {
			$object  = get_post_type_object( $type );
			$lines[] = '## ' . ( $object ? $object->labels->name : $type );
			$lines[] = '';
			foreach ( $list as $item ) {
				$description = isset( $item['seo']['description'] ) ? $item['seo']['description'] : '';
				$url         = $md ? self::markdown_url( $item['url'] ) : $item['url'];
				$lines[]     = '- [' . self::title( $item ) . '](' . $url . ')' . ( '' !== $description ? ': ' . $description : '' );
			}
			$lines[] = '';
		}

		if ( $full ) {
			foreach ( $groups as $list ) {
				foreach ( $list as $item ) {
					$lines[] = '---';
					$lines[] = '';
					$lines[] = 'Source: ' . $item['url'] . ( ! empty( $item['modified'] ) ? ' (updated ' . substr( $item['modified'], 0, 10 ) . ')' : '' );
					$lines[] = '';
					$lines[] = self::body( $item );
				}
			}
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Une entrée en Markdown : en-tête (titre, description, adresse, langue, date), puis son texte.
	 *
	 * @param array $item Item de l'API de contenu (tableaux).
	 * @return string
	 */
	public static function markdown( $item ) {
		$front = array( '---', 'title: ' . wp_json_encode( self::title( $item ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( ! empty( $item['seo']['description'] ) ) {
			$front[] = 'description: ' . wp_json_encode( $item['seo']['description'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$front[] = 'url: ' . $item['url'];
		if ( ! empty( $item['locale'] ) ) {
			$front[] = 'lang: ' . $item['locale'];
		}
		if ( ! empty( $item['modified'] ) ) {
			$front[] = 'updated: ' . substr( $item['modified'], 0, 10 );
		}
		$front[] = '---';
		return implode( "\n", $front ) . "\n\n" . self::body( $item );
	}

	/**
	 * Texte d'une entrée : titre, description, contenu, puis ses champs dans l'ordre du schéma.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	public static function body( $item ) {
		$blocks = array( '# ' . self::title( $item ) );
		$seo    = isset( $item['seo'] ) ? $item['seo'] : array();

		$collection = class_exists( 'Leiho_Collections' ) ? Leiho_Collections::for_post_type( $item['type'] ) : null;
		$config     = $collection && ! empty( $collection['seo'] ) ? $collection['seo'] : array();
		$fields     = isset( $item['fields'] ) ? (array) $item['fields'] : array();

		// Description qui ne vient pas d'un champ (extrait, extension SEO) : en tête.
		if ( ! empty( $seo['description'] ) && empty( $config['description'] ) ) {
			$blocks[] = $seo['description'];
		}
		if ( ! empty( $item['content'] ) ) {
			$blocks[] = self::html( $item['content'] );
		}

		$defs = array();
		if ( $collection ) {
			foreach ( $collection['fields'] as $def ) {
				$defs[ $def['name'] ] = $def;
			}
		}
		// Ordre du schéma, puis les champs qu'il ne décrit pas (ACF).
		$names = array_unique( array_merge( array_keys( $defs ), array_keys( $fields ) ) );
		foreach ( $names as $name ) {
			if ( ! array_key_exists( $name, $fields ) || ( isset( $config['title'] ) && $config['title'] === $name ) ) {
				continue;
			}
			$blocks = array_merge( $blocks, self::walk( $fields[ $name ], isset( $defs[ $name ] ) ? $defs[ $name ] : null, isset( $config['faq'] ) && $config['faq'] === $name ) );
		}

		$text = implode( "\n\n", array_filter( array_map( 'trim', $blocks ), 'strlen' ) );
		return preg_replace( "/\n{3,}/", "\n\n", $text ) . "\n";
	}

	/**
	 * Blocs Markdown d'une valeur de champ.
	 *
	 * @param mixed      $value Valeur.
	 * @param array|null $def   Définition du champ (Collections), null sinon.
	 * @param bool       $faq   Liste de questions / réponses.
	 * @return string[]
	 */
	private static function walk( $value, $def = null, $faq = false ) {
		$type = $def ? $def['type'] : '';
		if ( null === $value || '' === $value || ( $type && in_array( $type, self::SKIP_TYPES, true ) ) ) {
			return array();
		}

		if ( is_string( $value ) ) {
			if ( 'code' === $type ) {
				$fence = false !== strpos( $value, '```' ) ? '````' : '```';
				return array( $fence . "\n" . rtrim( $value ) . "\n" . $fence );
			}
			if ( 'richtext' === $type || ( ! $type && preg_match( '/<(p|h[1-6]|ul|ol|div|br|strong|em|a)\b/i', $value ) ) ) {
				return array( self::html( $value ) );
			}
			// Sans schéma : ignore les valeurs techniques (adresses, identifiants, couleurs).
			if ( ! $type && ( preg_match( '#^(https?:|/|\#)#', $value ) || ! preg_match( '/\s/u', trim( $value ) ) && strlen( $value ) < 40 && ! preg_match( '/^\p{Lu}/u', $value ) ) ) {
				return array();
			}
			return array( trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		// Lien et image : forme connue de l'API de contenu.
		if ( 'link' === $type || ( ! $type && isset( $value['url'], $value['title'] ) && ! isset( $value['mime_type'] ) ) ) {
			return ! empty( $value['url'] ) && ! empty( $value['title'] ) ? array( '[' . $value['title'] . '](' . $value['url'] . ')' ) : array();
		}
		if ( 'image' === $type || ( ! $type && isset( $value['url'], $value['mime_type'] ) ) ) {
			return ! empty( $value['url'] ) && ! empty( $value['alt'] ) ? array( '![' . $value['alt'] . '](' . $value['url'] . ')' ) : array();
		}

		$subs = array();
		foreach ( $def && isset( $def['fields'] ) ? $def['fields'] : array() as $sub ) {
			$subs[ $sub['name'] ] = $sub;
		}

		// Liste de lignes.
		if ( array_values( $value ) === $value ) {
			$out = array();
			foreach ( $value as $row ) {
				if ( $faq && is_array( $row ) && isset( $row['question'], $row['answer'] ) ) {
					if ( '' === Leiho_Seo::plain( $row['question'] ) ) {
						continue;
					}
					$out[] = '### ' . Leiho_Seo::plain( $row['question'] );
					$out   = array_merge( $out, self::walk( $row['answer'], isset( $subs['answer'] ) ? $subs['answer'] : null ) );
					continue;
				}
				if ( ! is_array( $row ) ) {
					$text = self::walk( $row, $def && 'list' !== $type ? $def : null );
					if ( $text ) {
						$out[] = '- ' . implode( ' ', $text );
					}
					continue;
				}
				$out = array_merge( $out, self::row( $row, $subs ) );
			}
			return $out;
		}

		return self::row( $value, $subs );
	}

	/**
	 * Une ligne de liste ou un groupe : un premier texte court devient un titre.
	 *
	 * @param array $row  Valeurs.
	 * @param array $subs Définitions des sous-champs, par nom.
	 * @return string[]
	 */
	private static function row( $row, $subs ) {
		$parts = array();
		foreach ( $row as $key => $value ) {
			if ( '_' === substr( (string) $key, 0, 1 ) || 'acf_fc_layout' === $key ) {
				continue;
			}
			$parts = array_merge( $parts, self::walk( $value, isset( $subs[ $key ] ) ? $subs[ $key ] : null ) );
		}
		if ( count( $parts ) > 1 && false === strpos( $parts[0], "\n" ) && strlen( $parts[0] ) <= 80 && ! preg_match( '/^(#|-|!|\[|```)/', $parts[0] ) ) {
			$parts[0] = '### ' . $parts[0];
		}
		return $parts;
	}

	/**
	 * Texte riche (HTML) en Markdown : titres, paragraphes, listes, liens,
	 * emphase, code, citations, images et tableaux.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		}
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="leiho-md">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementById( 'leiho-md' );
		if ( ! $root ) {
			$root = $doc->documentElement;
		}
		$out = implode( "\n\n", self::blocks( $root ) );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $out ) );
	}

	/**
	 * @param DOMNode $node Nœud conteneur.
	 * @return string[]
	 */
	private static function blocks( $node ) {
		$block = array( 'p', 'div', 'section', 'article', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'blockquote', 'table', 'hr', 'header', 'footer', 'main', 'details', 'summary', 'dl', 'dt', 'dd', 'figcaption' );
		$out   = array();
		$run   = '';
		foreach ( $node->childNodes as $child ) {
			$name = XML_ELEMENT_NODE === $child->nodeType ? strtolower( $child->nodeName ) : '';
			if ( in_array( $name, array( 'script', 'style', 'svg', 'button', 'iframe', 'template' ), true ) ) {
				continue;
			}
			if ( in_array( $name, $block, true ) ) {
				if ( '' !== trim( $run ) ) {
					$out[] = trim( $run );
				}
				$run = '';
				$out = array_merge( $out, self::block( $child, $name ) );
			} else {
				$run .= self::inline( $child );
			}
		}
		if ( '' !== trim( $run ) ) {
			$out[] = trim( $run );
		}
		return $out;
	}

	/**
	 * @param DOMElement $el   Élément de bloc.
	 * @param string     $name Balise.
	 * @return string[]
	 */
	private static function block( $el, $name ) {
		switch ( $name ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = self::squash( self::inline( $el ) );
				return '' === $text ? array() : array( str_repeat( '#', (int) $name[1] ) . ' ' . $text );
			case 'p':
			case 'dt':
			case 'dd':
			case 'summary':
			case 'figcaption':
				$text = trim( preg_replace( '/[ \t]+/', ' ', self::inline( $el ) ) );
				return '' === $text ? array() : array( preg_replace( '/ *\n */', "\n", $text ) );
			case 'hr':
				return array( '---' );
			case 'pre':
				$code  = rtrim( $el->textContent );
				$lang  = '';
				$inner = $el->getElementsByTagName( 'code' )->item( 0 );
				if ( $inner && preg_match( '/language-([a-z0-9]+)/', (string) $inner->getAttribute( 'class' ), $m ) ) {
					$lang = $m[1];
				}
				$fence = false !== strpos( $code, '```' ) ? '````' : '```';
				return array( $fence . $lang . "\n" . $code . "\n" . $fence );
			case 'blockquote':
				$text = implode( "\n\n", self::blocks( $el ) );
				return array( '> ' . str_replace( "\n", "\n> ", $text ) );
			case 'ul':
			case 'ol':
				$lines = array();
				$i     = 0;
				foreach ( $el->childNodes as $li ) {
					if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) {
						continue;
					}
					$marker  = 'ol' === $name ? ( ++$i ) . '. ' : '- ';
					$body    = implode( "\n", self::blocks( $li ) );
					$lines[] = $marker . str_replace( "\n", "\n" . str_repeat( ' ', strlen( $marker ) ), $body );
				}
				return $lines ? array( implode( "\n", $lines ) ) : array();
			case 'table':
				$rows = array();
				foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
					$cells = array();
					foreach ( $tr->childNodes as $cell ) {
						if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
							$cells[] = str_replace( '|', '\|', self::squash( self::inline( $cell ) ) );
						}
					}
					$rows[] = $cells;
				}
				if ( ! $rows ) {
					return array();
				}
				$width = max( array_map( 'count', $rows ) );
				$lines = array();
				foreach ( $rows as $i => $cells ) {
					$lines[] = '| ' . implode( ' | ', array_pad( $cells, $width, '' ) ) . ' |';
					if ( 0 === $i ) {
						$lines[] = '|' . str_repeat( ' --- |', $width );
					}
				}
				return array( implode( "\n", $lines ) );
			default:
				return self::blocks( $el );
		}
	}

	/**
	 * @param DOMNode $node Nœud en ligne.
	 * @return string
	 */
	private static function inline( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return preg_replace( '/\s+/u', ' ', $node->nodeValue );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$name  = strtolower( $node->nodeName );
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= self::inline( $child );
		}
		switch ( $name ) {
			case 'br':
				return "\n";
			case 'strong':
			case 'b':
				return '' === trim( $inner ) ? '' : '**' . trim( $inner ) . '**';
			case 'em':
			case 'i':
				return '' === trim( $inner ) ? '' : '_' . trim( $inner ) . '_';
			case 'code':
				$text = $node->textContent;
				return false !== strpos( $text, '`' ) ? '`` ' . $text . ' ``' : '`' . $text . '`';
			case 'img':
				$alt = $node->getAttribute( 'alt' );
				return '' === $alt ? '' : '![' . $alt . '](' . $node->getAttribute( 'src' ) . ')';
			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = self::squash( $inner );
				return '' === $href || '#' === $href[0] || '' === $text ? $text : '[' . $text . '](' . $href . ')';
			case 'script':
			case 'style':
			case 'svg':
			case 'button':
				return '';
			default:
				return $inner;
		}
	}

	/* ---------------------------------------------------------------------
	 * Outils
	 * ------------------------------------------------------------------- */

	private static function squash( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private static function title( $item ) {
		return ! empty( $item['seo']['title'] ) ? $item['seo']['title'] : (string) $item['title'];
	}

	/**
	 * Adresse de la version Markdown d'une page : /docs/x → /docs/x.md, / → /index.md.
	 *
	 * @param string $url Adresse de la page.
	 * @return string
	 */
	public static function markdown_url( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$base  = substr( $url, 0, strlen( $url ) - strlen( isset( $parts['path'] ) ? $parts['path'] : '' ) - ( isset( $parts['query'] ) ? strlen( $parts['query'] ) + 1 : 0 ) - ( isset( $parts['fragment'] ) ? strlen( $parts['fragment'] ) + 1 : 0 ) );
		return $base . ( '' === $path ? '/index' : $path ) . '.md';
	}

	/**
	 * Export dans la langue demandée, en tableaux.
	 *
	 * @param string          $locale  Langue ('' : par défaut).
	 * @param WP_REST_Request $request Requête d'origine (jeton).
	 * @return array
	 */
	private static function export( $locale, $request ) {
		$inner = self::inner( '/export', $request );
		$inner->set_param( 'locale', $locale );
		return self::data( rest_do_request( $inner ) );
	}

	/**
	 * Requête interne vers l'API de contenu, avec le jeton de la requête
	 * d'origine (accès restreint) : mêmes droits, ni plus ni moins.
	 *
	 * @param string          $route   Route (/export, /content).
	 * @param WP_REST_Request $request Requête d'origine.
	 * @return WP_REST_Request
	 */
	private static function inner( $route, $request ) {
		$inner = new WP_REST_Request( 'GET', '/' . Leiho_Rest::NS . $route );
		foreach ( array( 'x_leiho_token', 'x_okno_token', 'authorization' ) as $header ) {
			$value = (string) $request->get_header( $header );
			if ( '' !== $value ) {
				$inner->set_header( $header, $value );
			}
		}
		return $inner;
	}

	/**
	 * Données d'une réponse interne, objets compris, en tableaux.
	 *
	 * @param WP_REST_Response|WP_Error $response Réponse.
	 * @return array
	 */
	private static function data( $response ) {
		if ( is_wp_error( $response ) || $response->is_error() ) {
			return array();
		}
		$data = json_decode( wp_json_encode( $response->get_data() ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Réponse en texte brut (WordPress encoderait une chaîne en JSON), avec ETag.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @param string          $body    Corps.
	 * @param string          $type    Type MIME.
	 * @return WP_REST_Response
	 */
	private static function text( $request, $body, $type ) {
		$etag    = Leiho_Cache::etag( $body );
		$headers = Leiho_Cache::headers( $etag );
		if ( Leiho_Cache::not_modified( $request, $etag ) ) {
			return new WP_REST_Response( null, 304, $headers );
		}
		$response = new WP_REST_Response( null, 200, $headers );
		$response->header( 'Content-Type', $type . '; charset=utf-8' );
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result ) use ( $response, $body ) {
				if ( $result !== $response ) {
					return $served;
				}
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown, pas du HTML.
				return true;
			},
			10,
			2
		);
		return $response;
	}
}
