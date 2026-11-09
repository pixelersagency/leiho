<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contenu écrit avec l'éditeur de blocs, éditable dans Leiho et de nouveau
 * dans WordPress.
 *
 * Leiho édite du HTML (TinyMCE). Le contenu d'un article fait de blocs
 * courants (paragraphes, titres, listes, citations, images, séparateurs)
 * passe en HTML sans ses commentaires de blocs ; à l'enregistrement, le
 * HTML redevient des blocs :
 *
 *   - un élément identique à un bloc d'origine (même HTML, à l'ordre des
 *     attributs et aux espaces près) reprend ce bloc tel quel, réglages
 *     compris ;
 *   - un élément nouveau ou modifié devient le bloc natif qui lui
 *     correspond, écrit comme l'éditeur de blocs l'écrirait ;
 *   - ce qui n'a pas d'équivalent sûr reste en HTML libre (bloc
 *     « Classique » dans l'éditeur de blocs), sans rien perdre.
 *
 * Les autres blocs (colonnes, tableaux, boutons, blocs d'extensions…) ne
 * passent pas par le HTML : ils apparaissent dans l'éditeur comme un encadré
 * verrouillé (data-leiho-block = leur rang dans le contenu d'origine) et
 * sont remis tels quels à l'enregistrement. Le reste de l'article reste
 * éditable.
 */
class Leiho_Blocks {

	/** Blocs qui se convertissent sans perte, au premier niveau. */
	const TOP = array( 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/image', 'core/separator' );

	/** Blocs admis à l'intérieur d'une liste ou d'une citation. */
	const INNER = array( 'core/list-item', 'core/list', 'core/paragraph', 'core/heading' );

	/**
	 * Blocs du contenu qu'on ne sait pas convertir (vide : éditable).
	 *
	 * @param string $content post_content.
	 * @return string[] Noms de blocs, sans doublon.
	 */
	public static function unsupported( $content ) {
		$out = array();
		foreach ( parse_blocks( (string) $content ) as $block ) {
			self::collect( $block, true, $out );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array $block Bloc analysé.
	 * @param bool  $top   Premier niveau.
	 * @param array $out   Noms refusés.
	 * @return void
	 */
	private static function collect( array $block, $top, array &$out ) {
		$name = $block['blockName'];
		if ( null === $name ) {
			return; // HTML libre (bloc Classique) ou espaces : éditable.
		}
		if ( ! in_array( $name, $top ? self::TOP : self::INNER, true ) ) {
			$out[] = $name;
			return;
		}
		foreach ( $block['innerBlocks'] as $inner ) {
			self::collect( $inner, false, $out );
		}
	}

	/**
	 * HTML à éditer : blocs courants sans leurs commentaires, autres blocs
	 * en encadrés verrouillés.
	 *
	 * @param string $content post_content.
	 * @return string
	 */
	public static function to_html( $content ) {
		$parts = array();
		foreach ( parse_blocks( (string) $content ) as $index => $block ) {
			$unsupported = array();
			self::collect( $block, true, $unsupported );
			$parts[] = $unsupported ? self::locked( $block, $index ) : self::strip( serialize_block( $block ) );
		}
		$html = implode( '', $parts );
		// Espaces laissés entre les blocs.
		$html = preg_replace( "/>\s*\n\s*</", ">\n<", $html );
		return trim( (string) $html );
	}

	/**
	 * @param string $serialized Bloc sérialisé.
	 * @return string Son HTML sans commentaires de blocs.
	 */
	private static function strip( $serialized ) {
		return (string) preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', (string) $serialized );
	}

	/**
	 * Encadré d'un bloc que Leiho ne modifie pas : son nom et le début de
	 * son texte.
	 *
	 * @param array $block Bloc.
	 * @param int   $index Rang dans le contenu d'origine.
	 * @return string
	 */
	private static function locked( array $block, $index ) {
		$label = (string) $block['blockName'];
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
			if ( $type && ! empty( $type->title ) ) {
				$label = (string) $type->title;
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', self::strip( serialize_block( $block ) ) ) ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 140 ) {
			$text = mb_substr( $text, 0, 140 ) . '…';
		}
		/* translators: %s: block name (Table, Buttons…). */
		$title = sprintf( __( '%s block — edit it in WordPress', 'leiho' ), $label );
		return '<div class="leiho-locked-block" contenteditable="false" data-leiho-block="' . (int) $index . '"><strong>' . esc_html( $title ) . '</strong>'
			. ( '' !== $text ? '<br>' . esc_html( $text ) : '' ) . '</div>';
	}

	/**
	 * HTML édité → contenu en blocs.
	 *
	 * @param string $html     HTML reçu (avec ses paragraphes).
	 * @param string $original post_content avant modification.
	 * @return string
	 */
	public static function from_html( $html, $original ) {
		// Blocs d'origine, retrouvés par leur HTML : une file par forme (deux
		// paragraphes identiques gardent chacun leur bloc).
		$known  = array();
		$blocks = parse_blocks( (string) $original );
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$serialized = serialize_block( $block );
			$key        = self::canonical( self::strip( $serialized ) );
			if ( '' !== $key ) {
				$known[ $key ][] = $serialized;
			}
		}

		$out = array();
		foreach ( self::nodes( $html ) as $node ) {
			// Bloc verrouillé : remis tel quel (supprimé si l'encadré l'a été).
			if ( XML_ELEMENT_NODE === $node->nodeType && $node->hasAttribute( 'data-leiho-block' ) ) {
				$index = $node->getAttribute( 'data-leiho-block' );
				if ( ctype_digit( $index ) && isset( $blocks[ (int) $index ] ) && null !== $blocks[ (int) $index ]['blockName'] ) {
					$out[] = serialize_block( $blocks[ (int) $index ] );
				}
				continue;
			}
			$outer = self::outer( $node );
			$key   = self::canonical( $outer );
			if ( '' === $key ) {
				continue;
			}
			if ( ! empty( $known[ $key ] ) ) {
				$out[] = array_shift( $known[ $key ] );
				continue;
			}
			$block = self::block( $node, $outer );
			$out[] = null === $block ? $outer : serialize_block( $block );
		}
		return implode( "\n\n", $out );
	}

	/* ---------------------------------------------------------------------
	 * HTML
	 * ------------------------------------------------------------------- */

	/**
	 * Nœuds de premier niveau d'un fragment HTML.
	 *
	 * @param string $html Fragment.
	 * @return DOMNode[]
	 */
	private static function nodes( $html ) {
		$doc = self::doc( $html );
		$out = array();
		$box = $doc->getElementById( 'leiho-root' );
		if ( ! $box ) {
			return $out;
		}
		foreach ( $box->childNodes as $node ) {
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * @param string $html Fragment.
	 * @return DOMDocument
	 */
	private static function doc( $html ) {
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><html><body><div id="leiho-root">' . $html . '</div></body></html>', LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	/**
	 * @param DOMNode $node Nœud.
	 * @return string
	 */
	private static function outer( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return htmlspecialchars( $node->nodeValue, ENT_NOQUOTES, 'UTF-8' );
		}
		return (string) $node->ownerDocument->saveHTML( $node );
	}

	/**
	 * @param DOMNode $node Nœud.
	 * @return string HTML intérieur.
	 */
	private static function inner( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= self::outer( $child );
		}
		return $html;
	}

	/**
	 * Forme comparable d'un HTML : balises en minuscules, attributs triés
	 * (classes triées aussi), espaces réduits, commentaires ignorés.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function canonical( $html ) {
		$out = '';
		foreach ( self::nodes( $html ) as $node ) {
			$out .= self::canon_node( $node );
		}
		$out = (string) preg_replace( '/\s+/u', ' ', $out );
		// Espaces autour des balises de bloc : sans effet à l'affichage, et
		// TinyMCE en ajoute (retours à la ligne entre les <li>).
		$out = (string) preg_replace( '#\s*(</?(?:p|h[1-6]|ul|ol|li|blockquote|figure|figcaption|hr|div)\b[^>]*>)\s*#i', '$1', $out );
		return trim( $out );
	}

	/**
	 * @param DOMNode $node Nœud.
	 * @return string
	 */
	private static function canon_node( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return htmlspecialchars( $node->nodeValue, ENT_NOQUOTES, 'UTF-8' );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$attrs = array();
		foreach ( $node->attributes as $attr ) {
			$value = trim( (string) preg_replace( '/\s+/', ' ', $attr->value ) );
			if ( 'class' === $attr->name ) {
				$classes = array_filter( explode( ' ', $value ) );
				sort( $classes );
				$value = implode( ' ', $classes );
			} elseif ( 'style' === $attr->name ) {
				$value = rtrim( str_replace( array( ': ', '; ' ), array( ':', ';' ), $value ), ';' );
			}
			$attrs[ $attr->name ] = $value;
		}
		ksort( $attrs );
		$tag  = strtolower( $node->nodeName );
		$open = '<' . $tag;
		foreach ( $attrs as $name => $value ) {
			$open .= ' ' . $name . '="' . $value . '"';
		}
		$open .= '>';
		$kids  = '';
		foreach ( $node->childNodes as $child ) {
			$kids .= self::canon_node( $child );
		}
		if ( in_array( $tag, array( 'img', 'hr', 'br' ), true ) ) {
			return $open;
		}
		return $open . trim( $kids ) . '</' . $tag . '>';
	}

	/* ---------------------------------------------------------------------
	 * Nouveaux blocs
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $name        Nom du bloc.
	 * @param array  $attrs       Attributs.
	 * @param string $html        HTML du bloc (sans blocs intérieurs).
	 * @param array  $inner       Blocs intérieurs.
	 * @param array  $content     innerContent (null = place d'un bloc intérieur).
	 * @return array
	 */
	private static function make( $name, array $attrs, $html, array $inner = array(), array $content = array() ) {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content ? $content : array( $html ),
		);
	}

	/**
	 * Bloc natif équivalent à un nœud, null s'il reste en HTML libre.
	 *
	 * @param DOMNode $node  Nœud de premier niveau.
	 * @param string  $outer Son HTML.
	 * @return array|null
	 */
	private static function block( DOMNode $node, $outer ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return self::make( 'core/paragraph', array(), '<p>' . trim( $outer ) . '</p>' );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return null;
		}
		$tag = strtolower( $node->nodeName );
		switch ( $tag ) {
			case 'p':
				return self::paragraph( $node );
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return self::heading( $node, (int) $tag[1] );
			case 'ul':
			case 'ol':
				return self::lists( $node );
			case 'blockquote':
				return self::quote( $node );
			case 'hr':
				return self::make( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
			case 'figure':
			case 'img':
				return self::image( $node );
			case 'strong':
			case 'em':
			case 'b':
			case 'i':
			case 'a':
			case 'span':
			case 'code':
				return self::make( 'core/paragraph', array(), '<p>' . $outer . '</p>' );
		}
		return null;
	}

	/**
	 * Alignement d'un paragraphe ou d'un titre : style="text-align: …" posé
	 * par TinyMCE, ou classe has-text-align-… venue de l'éditeur de blocs.
	 * Autres attributs : pas d'équivalent sûr.
	 *
	 * @param DOMElement $node    Élément.
	 * @param string[]   $allowed Classes admises en plus.
	 * @return string|false '' sans alignement, false si d'autres attributs.
	 */
	private static function align( DOMElement $node, array $allowed = array() ) {
		$align = '';
		foreach ( $node->attributes as $attr ) {
			if ( 'style' === $attr->name && preg_match( '/^\s*text-align\s*:\s*(left|center|right)\s*;?\s*$/i', $attr->value, $m ) ) {
				$align = strtolower( $m[1] );
				continue;
			}
			if ( 'class' === $attr->name ) {
				foreach ( preg_split( '/\s+/', trim( $attr->value ) ) as $class ) {
					if ( preg_match( '/^has-text-align-(left|center|right)$/', $class, $m ) ) {
						$align = '' === $align ? $m[1] : $align;
					} elseif ( '' !== $class && ! in_array( $class, $allowed, true ) ) {
						return false;
					}
				}
				continue;
			}
			return false;
		}
		return $align;
	}

	/**
	 * @param DOMElement $node <p>.
	 * @return array|null
	 */
	private static function paragraph( DOMElement $node ) {
		$align = self::align( $node, array( 'has-drop-cap' ) );
		if ( false === $align ) {
			return null;
		}
		$inner = self::inner( $node );
		if ( '' === trim( wp_strip_all_tags( $inner ) ) && false === strpos( $inner, '<img' ) ) {
			return null; // Paragraphe vide : ignoré (HTML libre vide).
		}
		// Image seule dans un paragraphe (insertion depuis la médiathèque).
		if ( 1 === $node->childNodes->length && 'img' === strtolower( $node->firstChild->nodeName ) ) {
			return self::image( $node->firstChild );
		}
		$attrs   = array();
		$classes = array();
		if ( '' !== $align ) {
			$attrs['align'] = $align;
			$classes[]      = 'has-text-align-' . $align;
		}
		if ( false !== strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' has-drop-cap ' ) ) {
			$attrs['dropCap'] = true;
			$classes[]        = 'has-drop-cap';
		}
		$open = $classes ? '<p class="' . implode( ' ', $classes ) . '">' : '<p>';
		return self::make( 'core/paragraph', $attrs, $open . $inner . '</p>' );
	}

	/**
	 * @param DOMElement $node  <hN>.
	 * @param int        $level Niveau.
	 * @return array|null
	 */
	private static function heading( DOMElement $node, $level ) {
		$align = self::align( $node, array( 'wp-block-heading' ) );
		if ( false === $align ) {
			return null;
		}
		$attrs   = 2 === $level ? array() : array( 'level' => $level );
		$classes = 'wp-block-heading';
		if ( '' !== $align ) {
			$attrs['textAlign'] = $align;
			$classes           .= ' has-text-align-' . $align;
		}
		$html = '<h' . $level . ' class="' . $classes . '">' . self::inner( $node ) . '</h' . $level . '>';
		return self::make( 'core/heading', $attrs, $html );
	}

	/**
	 * @param DOMElement $node <ul> ou <ol>.
	 * @return array|null
	 */
	private static function lists( DOMElement $node ) {
		foreach ( $node->attributes as $attr ) {
			if ( 'class' !== $attr->name || 'wp-block-list' !== trim( $attr->value ) ) {
				return null;
			}
		}
		$ordered = 'ol' === strtolower( $node->nodeName );
		$tag     = $ordered ? 'ol' : 'ul';
		$items   = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->nodeValue ) ) {
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) || $child->attributes->length ) {
				return null;
			}
			$item = self::item( $child );
			if ( null === $item ) {
				return null;
			}
			$items[] = $item;
		}
		$open    = '<' . $tag . ' class="wp-block-list">';
		$close   = '</' . $tag . '>';
		$content = array_merge( array( $open ), array_fill( 0, count( $items ), null ), array( $close ) );
		return self::make( 'core/list', $ordered ? array( 'ordered' => true ) : array(), $open . $close, $items, $content );
	}

	/**
	 * @param DOMElement $li <li>.
	 * @return array|null
	 */
	private static function item( DOMElement $li ) {
		$text   = '';
		$nested = array();
		foreach ( $li->childNodes as $child ) {
			$name = strtolower( $child->nodeName );
			if ( XML_ELEMENT_NODE === $child->nodeType && ( 'ul' === $name || 'ol' === $name ) ) {
				$list = self::lists( $child );
				if ( null === $list ) {
					return null;
				}
				$nested[] = $list;
				continue;
			}
			if ( $nested ) {
				if ( XML_TEXT_NODE !== $child->nodeType || '' !== trim( (string) $child->nodeValue ) ) {
					return null; // Texte après une sous-liste : pas d'équivalent.
				}
				continue;
			}
			$text .= self::outer( $child );
		}
		$text = trim( $text );
		if ( ! $nested ) {
			return self::make( 'core/list-item', array(), '<li>' . $text . '</li>' );
		}
		$content = array_merge( array( '<li>' . $text ), array_fill( 0, count( $nested ), null ), array( '</li>' ) );
		return self::make( 'core/list-item', array(), '<li>' . $text . '</li>', $nested, $content );
	}

	/**
	 * @param DOMElement $node <blockquote>.
	 * @return array|null
	 */
	private static function quote( DOMElement $node ) {
		foreach ( $node->attributes as $attr ) {
			if ( 'class' !== $attr->name || 'wp-block-quote' !== trim( $attr->value ) ) {
				return null;
			}
		}
		$inner = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( $child->nodeValue ) ) {
					$inner[] = self::make( 'core/paragraph', array(), '<p>' . trim( self::outer( $child ) ) . '</p>' );
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$block = 'p' === strtolower( $child->nodeName ) ? self::paragraph( $child ) : null;
			if ( null === $block ) {
				return null;
			}
			$inner[] = $block;
		}
		$open    = '<blockquote class="wp-block-quote">';
		$content = array_merge( array( $open ), array_fill( 0, count( $inner ), null ), array( '</blockquote>' ) );
		return self::make( 'core/quote', array(), $open . '</blockquote>', $inner, $content );
	}

	/**
	 * Image de la médiathèque (classe wp-image-ID), avec sa légende.
	 *
	 * @param DOMElement $node <img> ou <figure>.
	 * @return array|null
	 */
	private static function image( DOMElement $node ) {
		$img     = 'img' === strtolower( $node->nodeName ) ? $node : null;
		$caption = '';
		if ( ! $img ) {
			foreach ( $node->childNodes as $child ) {
				$name = strtolower( $child->nodeName );
				if ( 'img' === $name ) {
					$img = $child;
				} elseif ( 'figcaption' === $name ) {
					$caption = trim( self::inner( $child ) );
				} elseif ( XML_TEXT_NODE !== $child->nodeType || '' !== trim( $child->nodeValue ) ) {
					return null;
				}
			}
		}
		if ( ! $img || ! preg_match( '/\bwp-image-(\d+)\b/', (string) $img->getAttribute( 'class' ), $m ) ) {
			return null;
		}
		$id   = (int) $m[1];
		$src  = (string) $img->getAttribute( 'src' );
		$alt  = (string) $img->getAttribute( 'alt' );
		$size = preg_match( '/\bsize-([a-z0-9_-]+)\b/', (string) $img->getAttribute( 'class' ), $s ) ? $s[1] : 'full';
		$html = '<figure class="wp-block-image size-' . esc_attr( $size ) . '"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/>'
			. ( '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '' )
			. '</figure>';
		return self::make(
			'core/image',
			array(
				'id'              => $id,
				'sizeSlug'        => $size,
				'linkDestination' => 'none',
			),
			$html
		);
	}
}
