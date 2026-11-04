<?php
defined( 'ABSPATH' ) || exit;

/**
 * Assemblage des prompts et contrôles du texte généré.
 *
 * Fonctions pures, testables hors WordPress (test-ai.php) : consigne système
 * tirée du contexte de marque, prompt d'une action sur un champ, contexte de
 * l'entrée, contrôle des formulations interdites et de la longueur, schéma
 * JSON d'une nouvelle entrée et remise en forme de la réponse du modèle en
 * valeurs de champs (format de l'éditeur, chemins racine).
 *
 * Les prompts sont écrits en anglais (les modèles les suivent le mieux) ; la
 * langue du texte produit est celle du contexte de marque.
 */
class Leiho_AI_Prompt {

	/** Actions sur un champ texte. */
	const ACTIONS = array( 'generate', 'rewrite', 'shorten', 'expand', 'fix', 'tone', 'translate', 'suggest' );

	/** Actions qui partent du texte actuel (il doit exister). */
	const NEEDS_TEXT = array( 'rewrite', 'shorten', 'expand', 'fix', 'tone', 'translate' );

	/** Longueurs conseillées des champs SEO. */
	const SEO_TITLE_MAX       = 60;
	const SEO_DESCRIPTION_MAX = 155;

	/** Contexte de l'entrée : au plus tant de champs, tant de caractères chacun. */
	const CONTEXT_FIELDS     = 40;
	const CONTEXT_FIELD_MAX  = 1200;
	const CONTEXT_TOTAL_MAX  = 12000;

	/** Types de champ que l'IA remplit dans une nouvelle entrée. */
	const FILLABLE_TYPES = array( 'text', 'textarea', 'wysiwyg', 'select', 'true_false', 'number', 'range', 'repeater', 'group', 'clone', 'flexible_content' );

	/** Lignes au plus par liste générée (sans maximum déclaré). */
	const MAX_ROWS = 8;

	/* ---------------------------------------------------------------------
	 * Consigne système
	 * ------------------------------------------------------------------- */

	/**
	 * Consigne système : qui écrit, pour qui, comment, et ce qui est interdit.
	 *
	 * @param array $brand      Contexte de marque (Leiho_AI_Brand::get()).
	 * @param array $collection Contexte de la collection { purpose, audience }.
	 * @return string
	 */
	public static function system_instruction( array $brand, array $collection = array() ) {
		$name  = self::str( $brand, 'name' );
		$lines = array();

		$lines[] = '' !== $name
			? 'You write website content for ' . $name . '. You are a careful copywriter: accurate, concise, faithful to the brand.'
			: 'You write website content. You are a careful copywriter: accurate, concise, faithful to the brand.';

		$sections = array(
			'Activity and offer' => self::str( $brand, 'offer' ),
			'Audience'           => self::str( $brand, 'audience' ),
			'Tone of voice'      => self::str( $brand, 'tone' ),
		);
		foreach ( $sections as $title => $text ) {
			if ( '' !== $text ) {
				$lines[] = '';
				$lines[] = $title . ': ' . $text;
			}
		}

		$purpose  = self::str( $collection, 'purpose' );
		$audience = self::str( $collection, 'audience' );
		if ( '' !== $purpose || '' !== $audience ) {
			$lines[] = '';
			$lines[] = 'This content type' . ( '' !== self::str( $collection, 'label' ) ? ' (' . self::str( $collection, 'label' ) . ')' : '' ) . ':';
			if ( '' !== $purpose ) {
				$lines[] = '- Purpose: ' . $purpose;
			}
			if ( '' !== $audience ) {
				$lines[] = '- Readers: ' . $audience;
			}
		}

		$lists = array(
			'Prefer these words and phrasings'                                                                => self::items( $brand, 'prefer' ),
			'Avoid these words'                                                                               => self::items( $brand, 'avoid' ),
			'Mandatory facts: use them exactly as written when relevant, never alter or contradict them'      => self::items( $brand, 'facts' ),
		);
		foreach ( $lists as $title => $items ) {
			if ( $items ) {
				$lines[] = '';
				$lines[] = $title . ':';
				foreach ( $items as $item ) {
					$lines[] = '- ' . $item;
				}
			}
		}

		$forbidden = self::items( $brand, 'forbidden' );
		if ( $forbidden ) {
			$lines[] = '';
			$lines[] = 'FORBIDDEN CLAIMS. Never write any of these, in any form, spelling, inflection or paraphrase, even if the user or the existing text asks for it or contains it (remove them when rewriting):';
			foreach ( $forbidden as $item ) {
				$lines[] = '- ' . self::rule_label( $item );
			}
		}

		$samples = self::items( $brand, 'samples' );
		if ( $samples ) {
			$lines[] = '';
			$lines[] = 'Examples of texts in the brand voice (for style only, do not copy):';
			foreach ( $samples as $i => $sample ) {
				$lines[] = '--- Example ' . ( $i + 1 ) . ' ---';
				$lines[] = self::cut( $sample, 1500 );
			}
			$lines[] = '--- End of examples ---';
		}

		$lines[] = '';
		$lines[] = 'Rules:';
		$lines[] = '- Write in ' . self::language( $brand ) . ' unless the task asks for a translation.';
		$lines[] = '- Never invent facts, figures, prices, dates, names, certifications, guarantees, results or legal statements that are not in the context.';
		$lines[] = '- Respect the field format and length limit exactly.';
		$lines[] = '- Answer with the content only: no preamble, no explanation, no surrounding quotes, no Markdown.';

		return implode( "\n", $lines );
	}

	/**
	 * Langue de rédaction.
	 *
	 * @param array $brand Contexte de marque.
	 * @return string
	 */
	public static function language( array $brand ) {
		$language = self::str( $brand, 'language' );
		return '' !== $language ? $language : 'the language of the existing content';
	}

	/* ---------------------------------------------------------------------
	 * Prompt d'une action sur un champ
	 * ------------------------------------------------------------------- */

	/**
	 * Description du champ pour le modèle : format, limite, aide.
	 *
	 * @param array $field Définition (format éditeur) : type, label, help, max_length, required, seo.
	 * @return array { format: plain|html, single_line: bool, max: int }
	 */
	public static function field_spec( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$max  = isset( $field['max_length'] ) ? (int) $field['max_length'] : 0;
		$seo  = isset( $field['seo'] ) ? (string) $field['seo'] : '';
		if ( 'title' === $seo ) {
			$max = $max > 0 ? min( $max, self::SEO_TITLE_MAX ) : self::SEO_TITLE_MAX;
		} elseif ( 'description' === $seo ) {
			$max = $max > 0 ? min( $max, self::SEO_DESCRIPTION_MAX ) : self::SEO_DESCRIPTION_MAX;
		}
		return array(
			'format'      => 'wysiwyg' === $type ? 'html' : 'plain',
			'single_line' => 'text' === $type,
			'max'         => max( 0, $max ),
		);
	}

	/**
	 * Champ « SEO » d'après son nom ou son libellé : titre ou description.
	 *
	 * @param string $path  Chemin du champ.
	 * @param string $label Libellé.
	 * @return string title | description | ''
	 */
	public static function seo_kind( $path, $label = '' ) {
		$path = strtolower( (string) $path );
		if ( '_excerpt' === $path ) {
			return 'description';
		}
		$last = strtolower( (string) preg_replace( '/^.*\./', '', $path ) );
		$text = $last . ' ' . strtolower( self::fold( (string) $label ) );
		$seo  = (bool) preg_match( '/(^|[^a-z])(seo|meta|og|twitter)([^a-z]|$)|seo_|_seo|meta_|og_/', $text );
		if ( ! $seo ) {
			return '';
		}
		if ( preg_match( '/desc/', $text ) ) {
			return 'description';
		}
		if ( preg_match( '/title|titre/', $text ) ) {
			return 'title';
		}
		return '';
	}

	/**
	 * Prompt utilisateur d'une action sur un champ.
	 *
	 * @param string $action Action (ACTIONS).
	 * @param array  $args   { field: array, value: string, instruction: string, tone: string, language: string, context: array<int,array{label,value}> }.
	 * @return string
	 */
	public static function field_prompt( $action, array $args ) {
		$field       = isset( $args['field'] ) && is_array( $args['field'] ) ? $args['field'] : array();
		$spec        = self::field_spec( $field );
		$value       = isset( $args['value'] ) ? (string) $args['value'] : '';
		$instruction = trim( isset( $args['instruction'] ) ? (string) $args['instruction'] : '' );
		$seo         = isset( $field['seo'] ) ? (string) $field['seo'] : '';

		switch ( $action ) {
			case 'generate':
				$task = 'Write the content of this field' . ( '' !== $instruction ? ' following this request: ' . $instruction : ' from the entry context.' );
				break;
			case 'rewrite':
				$task = 'Rewrite the current text: same meaning and facts, clearer and better written, in the brand tone.';
				break;
			case 'shorten':
				$task = 'Make the current text noticeably shorter (about half as long), keeping the key information.';
				break;
			case 'expand':
				$task = 'Develop the current text with more substance, using only facts present in the current text or the entry context.';
				break;
			case 'fix':
				$task = 'Correct spelling, grammar, punctuation and typography only. Keep the wording, meaning and length otherwise unchanged.';
				break;
			case 'tone':
				$tone = trim( isset( $args['tone'] ) ? (string) $args['tone'] : '' );
				$task = 'Rewrite the current text in this tone: ' . ( '' !== $tone ? $tone : 'the brand tone' ) . '. Keep the meaning and facts.';
				break;
			case 'translate':
				$lang = trim( isset( $args['language'] ) ? (string) $args['language'] : '' );
				$task = 'Translate the current text into ' . ( '' !== $lang ? $lang : 'English' ) . '. Keep the meaning, formatting and facts; adapt idioms naturally.';
				break;
			case 'suggest':
				if ( 'title' === $seo ) {
					$task = 'Propose a search engine title (title tag) for this entry: specific, natural, with the main topic first.';
				} elseif ( 'description' === $seo ) {
					$task = 'Propose a search engine meta description (or summary) for this entry: one or two sentences that make people want to read it.';
				} else {
					$task = 'Propose a value for this field from the entry context.';
				}
				break;
			default:
				$task = 'Improve the current text.';
		}
		if ( '' !== $instruction && 'generate' !== $action ) {
			$task .= ' Additional request: ' . $instruction;
		}

		$lines   = array( 'TASK: ' . $action, $task, '', 'FIELD' );
		$lines[] = '- Label: ' . self::str( $field, 'label' );
		if ( '' !== self::str( $field, 'help' ) ) {
			$lines[] = '- Instructions for editors: ' . self::str( $field, 'help' );
		}
		if ( 'html' === $spec['format'] ) {
			$lines[] = '- Format: HTML fragment. Allowed tags: p, h2, h3, h4, strong, em, a, ul, ol, li, blockquote, br. No other markup, no Markdown.';
		} elseif ( $spec['single_line'] ) {
			$lines[] = '- Format: plain text on a single line, no line breaks, no HTML, no Markdown.';
		} else {
			$lines[] = '- Format: plain text, line breaks allowed, no HTML, no Markdown.';
		}
		if ( $spec['max'] > 0 ) {
			$lines[] = '- Maximum length: ' . $spec['max'] . ' characters (hard limit, count carefully).';
		}
		if ( ! empty( $field['required'] ) ) {
			$lines[] = '- Required: the field must not be empty.';
		}

		$lines[] = '';
		$lines[] = 'CURRENT TEXT';
		$lines[] = '"""';
		$lines[] = '' !== trim( $value ) ? $value : '(empty)';
		$lines[] = '"""';

		$context = self::context_lines( isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array(), self::str( $field, 'path' ) );
		if ( $context ) {
			$lines[] = '';
			$lines[] = 'ENTRY CONTEXT (other fields of the same entry, for consistency; do not copy them verbatim)';
			$lines   = array_merge( $lines, $context );
		}

		$lines[] = '';
		$lines[] = 'Answer with the new content of the field only.';
		return implode( "\n", $lines );
	}

	/**
	 * Prompt du texte alternatif d'une image.
	 *
	 * @param array $args { title, caption, context: string }.
	 * @return string
	 */
	public static function alt_prompt( array $args ) {
		$lines = array(
			'TASK: alt text',
			'Write the alternative text of the attached image for visually impaired visitors: describe what is visible and useful in context, in one sentence of at most 125 characters.',
			'Do not start with "Image of" or "Photo of". No quotes, no final period if it is a fragment.',
		);
		foreach ( array( 'title' => 'File title', 'caption' => 'Caption', 'context' => 'Where it is used' ) as $key => $label ) {
			$text = trim( self::str( $args, $key ) );
			if ( '' !== $text ) {
				$lines[] = $label . ': ' . self::cut( $text, 300 );
			}
		}
		$lines[] = '';
		$lines[] = 'Answer with the alternative text only.';
		return implode( "\n", $lines );
	}

	/**
	 * Prompt d'une nouvelle entrée.
	 *
	 * @param string $brief  Demande de l'utilisateur.
	 * @param array  $fields Définitions (format éditeur) des champs à remplir.
	 * @param string $label  Nom du type (« Service »).
	 * @param bool   $inline Le schéma JSON n'est pas transmis au fournisseur : il est joint au prompt.
	 * @return string
	 */
	public static function entry_prompt( $brief, array $fields, $label, $inline = false ) {
		$lines   = array( 'TASK: new entry' );
		$lines[] = 'Write a complete new ' . ( '' !== (string) $label ? '"' . $label . '"' : 'entry' ) . ' from this brief:';
		$lines[] = '"""';
		$lines[] = trim( (string) $brief );
		$lines[] = '"""';
		$lines[] = '';
		$lines[] = 'Fill every field you can from the brief, in the brand voice. Leave a field out rather than inventing facts. Respect each field format: plain text without HTML unless the field says HTML; HTML fields use only p, h2, h3, strong, em, a, ul, ol, li. Respect maximum lengths. For lists, write between 2 and the maximum number of items. For "_layout", pick one of the allowed values and fill only that layout\'s fields.';
		$lines[] = '';
		$lines[] = 'FIELDS';
		foreach ( self::describe_lines( $fields, 0 ) as $line ) {
			$lines[] = $line;
		}
		$lines[] = '';
		if ( $inline ) {
			$lines[] = 'Answer with a single JSON object matching this JSON schema, and nothing else (no Markdown fence):';
			$lines[] = (string) json_encode( self::entry_schema( $fields ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} else {
			$lines[] = 'Answer with the JSON object only.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Description lisible des champs (le schéma JSON ne porte pas tout).
	 *
	 * @param array $fields Champs.
	 * @param int   $depth  Profondeur.
	 * @return string[]
	 */
	private static function describe_lines( array $fields, $depth ) {
		$out    = array();
		$indent = str_repeat( '  ', $depth );
		foreach ( self::fillable( $fields ) as $field ) {
			$bits = array( $field['type'] );
			$spec = self::field_spec( $field );
			if ( $spec['max'] > 0 ) {
				$bits[] = 'max ' . $spec['max'] . ' characters';
			}
			if ( ! empty( $field['required'] ) ) {
				$bits[] = 'required';
			}
			$line = $indent . '- ' . $field['path'] . ': ' . self::str( $field, 'label' ) . ' (' . implode( ', ', $bits ) . ')';
			if ( '' !== self::str( $field, 'help' ) ) {
				$line .= ' — ' . self::cut( self::str( $field, 'help' ), 200 );
			}
			$out[] = $line;
			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) && $depth < 4 ) {
				$out = array_merge( $out, self::describe_lines( $field['sub_fields'], $depth + 1 ) );
			}
			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) && $depth < 4 ) {
				foreach ( $field['layouts'] as $layout ) {
					$out[] = $indent . '  * _layout "' . $layout['name'] . '": ' . ( isset( $layout['label'] ) ? $layout['label'] : $layout['name'] );
					$out   = array_merge( $out, self::describe_lines( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array(), $depth + 2 ) );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Contexte de l'entrée
	 * ------------------------------------------------------------------- */

	/**
	 * Textes d'une entrée à partir de son schéma (Leiho_Schema::get) :
	 * titre, champs texte, cellules des listes et sections.
	 *
	 * @param array $schema Schéma (groups → fields, avec value).
	 * @return array<int,array{path:string,label:string,value:string}>
	 */
	public static function entry_texts( array $schema ) {
		$out = array();
		foreach ( isset( $schema['groups'] ) && is_array( $schema['groups'] ) ? $schema['groups'] : array() as $group ) {
			foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $field ) {
				if ( isset( $field['supported'] ) && false === $field['supported'] ) {
					continue;
				}
				self::walk_texts( $field, isset( $field['value'] ) ? $field['value'] : null, (string) $field['path'], (string) $field['label'], $out );
			}
		}
		return $out;
	}

	/**
	 * @param array  $def   Définition.
	 * @param mixed  $value Valeur.
	 * @param string $path  Chemin pointé.
	 * @param string $label Libellé composé.
	 * @param array  $out   Sortie.
	 * @return void
	 */
	private static function walk_texts( $def, $value, $path, $label, array &$out ) {
		if ( count( $out ) >= self::CONTEXT_FIELDS * 2 ) {
			return;
		}
		$type = isset( $def['type'] ) ? $def['type'] : '';
		if ( in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
			$text = self::plain( is_scalar( $value ) ? (string) $value : '' );
			if ( '' !== $text ) {
				$out[] = array(
					'path'  => $path,
					'label' => $label,
					'value' => $text,
				);
			}
			return;
		}
		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			foreach ( isset( $def['sub_fields'] ) ? (array) $def['sub_fields'] : array() as $sub ) {
				self::walk_texts( $sub, isset( $value[ $sub['path'] ] ) ? $value[ $sub['path'] ] : null, $path . '.' . $sub['path'], $label . ' › ' . $sub['label'], $out );
			}
			return;
		}
		if ( in_array( $type, array( 'repeater', 'flexible_content' ), true ) && is_array( $value ) ) {
			foreach ( array_values( $value ) as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$subs = isset( $def['sub_fields'] ) ? (array) $def['sub_fields'] : array();
				if ( 'flexible_content' === $type ) {
					$subs = array();
					foreach ( isset( $def['layouts'] ) ? (array) $def['layouts'] : array() as $layout ) {
						if ( isset( $row['_layout'] ) && $layout['name'] === $row['_layout'] ) {
							$subs = $layout['sub_fields'];
						}
					}
				}
				foreach ( $subs as $sub ) {
					self::walk_texts( $sub, isset( $row[ $sub['path'] ] ) ? $row[ $sub['path'] ] : null, $path . '.' . $i . '.' . $sub['path'], $label . ' › #' . ( $i + 1 ) . ' › ' . $sub['label'], $out );
				}
			}
		}
	}

	/**
	 * Contexte reçu du navigateur ({ path: texte } ou liste de { path, label, value }),
	 * nettoyé et borné.
	 *
	 * @param mixed $raw Contexte reçu.
	 * @return array<int,array{path:string,label:string,value:string}>
	 */
	public static function normalize_context( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $key => $item ) {
			if ( count( $out ) >= self::CONTEXT_FIELDS * 2 ) {
				break;
			}
			if ( is_array( $item ) ) {
				$path  = isset( $item['path'] ) ? (string) $item['path'] : '';
				$label = isset( $item['label'] ) ? (string) $item['label'] : $path;
				$value = isset( $item['value'] ) && is_scalar( $item['value'] ) ? (string) $item['value'] : '';
			} else {
				$path  = (string) $key;
				$label = $path;
				$value = is_scalar( $item ) ? (string) $item : '';
			}
			$value = self::plain( $value );
			if ( '' === $value ) {
				continue;
			}
			$out[] = array(
				'path'  => substr( preg_replace( '/[^A-Za-z0-9_.\-]/', '', $path ), 0, 191 ),
				'label' => self::cut( self::plain( $label ), 150 ),
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Lignes de contexte bornées, sans le champ traité lui-même.
	 *
	 * @param array  $context Contexte normalisé.
	 * @param string $skip    Chemin du champ traité.
	 * @return string[]
	 */
	public static function context_lines( array $context, $skip = '' ) {
		$lines = array();
		$total = 0;
		foreach ( $context as $item ) {
			if ( count( $lines ) >= self::CONTEXT_FIELDS ) {
				break;
			}
			if ( '' !== $skip && isset( $item['path'] ) && $item['path'] === $skip ) {
				continue;
			}
			$value = self::cut( (string) $item['value'], self::CONTEXT_FIELD_MAX );
			$total += strlen( $value );
			if ( $total > self::CONTEXT_TOTAL_MAX ) {
				break;
			}
			$lines[] = '- ' . ( '' !== (string) $item['label'] ? $item['label'] : $item['path'] ) . ': ' . str_replace( array( "\r", "\n" ), ' ', $value );
		}
		return $lines;
	}

	/* ---------------------------------------------------------------------
	 * Contrôles du texte généré
	 * ------------------------------------------------------------------- */

	/**
	 * Formulations interdites trouvées dans un texte.
	 *
	 * Une ligne se cherche sans tenir compte de la casse ni des accents, au
	 * début d'un mot (« certifié » trouve « certifiée », « 4 h » trouve
	 * « 4h » et « 4 heures ») ; les espaces de la règle acceptent espaces,
	 * espaces insécables, tirets ou rien. Une ligne /motif/i est une
	 * expression régulière.
	 *
	 * @param string   $text  Texte (HTML accepté : on cherche dans le texte).
	 * @param string[] $rules Règles.
	 * @return array<int,array{rule:string,found:string}>
	 */
	public static function forbidden_matches( $text, array $rules ) {
		$text  = self::plain( (string) $text );
		$found = array();
		if ( '' === $text ) {
			return $found;
		}
		$folded = self::fold_map( $text );

		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			$hits = array();
			if ( self::is_regex( $rule ) ) {
				if ( @preg_match_all( self::regex_of( $rule ), $text, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Motif saisi par l'administrateur.
					$hits = $m[0];
				}
			} else {
				$pattern = self::phrase_pattern( $rule );
				if ( '' !== $pattern && preg_match_all( $pattern, $folded['text'], $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $hit ) {
						$hits[] = self::unfold( $text, $folded, $hit[1], strlen( $hit[0] ) );
					}
				}
			}
			foreach ( array_unique( $hits ) as $hit ) {
				if ( '' !== trim( $hit ) ) {
					$found[] = array(
						'rule'  => $rule,
						'found' => $hit,
					);
				}
			}
		}
		return $found;
	}

	/**
	 * La règle est-elle une expression régulière (/…/flags) ?
	 *
	 * @param string $rule Règle.
	 * @return bool
	 */
	public static function is_regex( $rule ) {
		return (bool) preg_match( '#^/.+/[imsux]*$#s', (string) $rule );
	}

	/**
	 * Motif PCRE d'une règle /…/ (toujours en UTF-8).
	 *
	 * @param string $rule Règle.
	 * @return string
	 */
	private static function regex_of( $rule ) {
		$flags = substr( $rule, strrpos( $rule, '/' ) + 1 );
		$body  = substr( $rule, 1, strrpos( $rule, '/' ) - 1 );
		$flags = str_replace( 'u', '', $flags ) . 'u';
		return '/' . str_replace( '/', '\/', str_replace( '\/', '/', $body ) ) . '/' . $flags;
	}

	/**
	 * Une règle /…/ est-elle valide ?
	 *
	 * @param string $rule Règle.
	 * @return bool
	 */
	public static function valid_rule( $rule ) {
		if ( ! self::is_regex( $rule ) ) {
			return '' !== trim( (string) $rule );
		}
		return false !== @preg_match( self::regex_of( $rule ), '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Libellé d'une règle pour le modèle (une regex est montrée telle quelle).
	 *
	 * @param string $rule Règle.
	 * @return string
	 */
	private static function rule_label( $rule ) {
		return self::is_regex( $rule ) ? 'anything matching the pattern ' . $rule : '"' . $rule . '"';
	}

	/**
	 * Motif d'une phrase interdite, sur le texte replié (minuscules sans accents).
	 *
	 * @param string $phrase Phrase.
	 * @return string
	 */
	private static function phrase_pattern( $phrase ) {
		$tokens = preg_split( '/[\s\x{00A0}\x{202F}\-]+/u', self::fold( $phrase ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $tokens ) {
			return '';
		}
		$parts = array_map(
			static function ( $token ) {
				return preg_quote( $token, '/' );
			},
			$tokens
		);
		return '/(?<![\p{L}\p{N}])' . implode( '[\s\x{00A0}\x{202F}\-]*', $parts ) . '/u';
	}

	/**
	 * Texte replié (minuscules, sans accents) et correspondance de chaque
	 * octet du texte replié avec l'octet du caractère d'origine.
	 *
	 * @param string $text Texte.
	 * @return array{text:string,map:int[]}
	 */
	private static function fold_map( $text ) {
		$chars  = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$folded = '';
		$map    = array();
		$offset = 0;
		foreach ( $chars ? $chars : array() as $char ) {
			$piece = self::fold( $char );
			for ( $i = 0, $n = strlen( $piece ); $i < $n; $i++ ) {
				$map[] = $offset;
			}
			$folded .= $piece;
			$offset += strlen( $char );
		}
		$map[] = $offset;
		return array(
			'text' => $folded,
			'map'  => $map,
		);
	}

	/**
	 * Extrait du texte d'origine correspondant à un passage du texte replié.
	 *
	 * @param string $text   Texte d'origine.
	 * @param array  $folded Résultat de fold_map().
	 * @param int    $start  Début (octets, texte replié).
	 * @param int    $length Longueur (octets, texte replié).
	 * @return string
	 */
	private static function unfold( $text, array $folded, $start, $length ) {
		$map  = $folded['map'];
		$from = isset( $map[ $start ] ) ? $map[ $start ] : 0;
		$end  = $start + $length;
		// Fin : début du caractère suivant le dernier octet trouvé.
		$last = isset( $map[ $end - 1 ] ) ? $map[ $end - 1 ] : $from;
		$to   = $last;
		while ( $to < strlen( $text ) && ( $to === $last || ( ord( $text[ $to ] ) & 0xC0 ) === 0x80 ) ) {
			$to++;
		}
		// Jusqu'à la fin du mot : « certifié » montre « certifiée ».
		if ( preg_match( '/^[\p{L}\p{N}]+/u', substr( $text, $to ), $rest ) ) {
			$to += strlen( $rest[0] );
		}
		return substr( $text, $from, $to - $from );
	}

	/**
	 * Minuscules sans accents (« Certifiée » → « certifiee »).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = (string) $text;
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $text, Normalizer::FORM_D );
			if ( is_string( $normalized ) ) {
				$text = (string) preg_replace( '/\p{Mn}+/u', '', $normalized );
			}
		}
		$text = strtr(
			$text,
			array(
				'œ' => 'oe',
				'Œ' => 'oe',
				'æ' => 'ae',
				'Æ' => 'ae',
				'ß' => 'ss',
				'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Á' => 'a',
				'ç' => 'c', 'Ç' => 'c',
				'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
				'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
				'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
				'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
				'ÿ' => 'y', 'ñ' => 'n', 'Ñ' => 'n',
			)
		);
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Longueur visible d'un texte (HTML retiré).
	 *
	 * @param string $text Texte.
	 * @return int
	 */
	public static function length( $text ) {
		$plain = self::plain( (string) $text );
		return function_exists( 'mb_strlen' ) ? mb_strlen( $plain, 'UTF-8' ) : strlen( $plain );
	}

	/**
	 * Contrôle complet : interdits, longueur, vide.
	 *
	 * @param string $text  Texte proposé.
	 * @param array  $brand Contexte de marque.
	 * @param int    $max   Longueur maximale (0 : aucune).
	 * @return array{forbidden:array,length:int,max:int,too_long:bool,empty:bool}
	 */
	public static function check( $text, array $brand, $max = 0 ) {
		$length = self::length( $text );
		return array(
			'forbidden' => self::forbidden_matches( $text, self::items( $brand, 'forbidden' ) ),
			'length'    => $length,
			'max'       => (int) $max,
			'too_long'  => $max > 0 && $length > $max,
			'empty'     => 0 === $length,
		);
	}

	/**
	 * Coupe un texte brut à une longueur, au dernier espace (sans points de suspension).
	 *
	 * @param string $text Texte.
	 * @param int    $max  Longueur maximale.
	 * @return string
	 */
	public static function trim_to_length( $text, $max ) {
		$text = (string) $text;
		$max  = (int) $max;
		if ( $max <= 0 || self::mb_len( $text ) <= $max ) {
			return $text;
		}
		$cut   = self::mb_sub( $text, 0, $max + 1 );
		$space = max( strrpos( $cut, ' ' ), 0 );
		$cut   = $space > 0 && self::mb_len( substr( $cut, 0, $space ) ) >= $max * 0.6 ? substr( $cut, 0, $space ) : self::mb_sub( $text, 0, $max );
		return rtrim( $cut, " \t\n\r\0\x0B,;:–—-" );
	}

	/**
	 * Nettoie la réponse du modèle selon le format du champ.
	 *
	 * @param string $raw  Réponse brute.
	 * @param array  $spec field_spec().
	 * @return string Texte brut, ou HTML à passer à wp_kses_post().
	 */
	public static function clean_output( $raw, array $spec ) {
		$text = trim( (string) $raw );
		// Bloc de code Markdown autour de la réponse.
		if ( preg_match( '/^```[a-z]*\s*\n(.*)\n```$/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		// Préambule du type « Voici le texte : ».
		$text = (string) preg_replace( '/^(here is|here\'s|voici)[^\n:]{0,60}:\s*\n+/i', '', $text );
		// Guillemets autour de toute la réponse.
		$pairs = array( array( '"', '"' ), array( '“', '”' ), array( '«', '»' ), array( "'", "'" ), array( '„', '“' ) );
		foreach ( $pairs as $pair ) {
			$open  = $pair[0];
			$close = $pair[1];
			if ( strlen( $text ) > strlen( $open ) + strlen( $close ) && 0 === strpos( $text, $open ) && substr( $text, -strlen( $close ) ) === $close
				&& false === strpos( substr( $text, strlen( $open ), -strlen( $close ) ), $close ) ) {
				$text = trim( substr( $text, strlen( $open ), -strlen( $close ) ) );
				break;
			}
		}

		if ( 'html' === $spec['format'] ) {
			if ( ! preg_match( '/<(p|h[2-4]|ul|ol|blockquote)\b/i', $text ) ) {
				// Texte sans balises de bloc : un paragraphe par bloc de lignes.
				$text = self::markdown_light( $text );
				$paras = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
				$text  = implode(
					"\n",
					array_map(
						static function ( $p ) {
							return '<p>' . str_replace( "\n", '<br>', trim( $p ) ) . '</p>';
						},
						$paras ? $paras : array()
					)
				);
			}
			return $text;
		}

		$text = self::plain( $text, ! $spec['single_line'] );
		// Gras / italique Markdown restés dans un texte brut.
		$text = (string) preg_replace( '/(\*\*|__)(.+?)\1/u', '$2', $text );
		if ( $spec['single_line'] ) {
			$text = trim( (string) preg_replace( '/\s*\n+\s*/u', ' ', $text ) );
		}
		return $text;
	}

	/**
	 * Gras et italique Markdown en HTML (réponse en texte pour un champ riche).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function markdown_light( $text ) {
		$text = htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
		return (string) preg_replace( '/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?!\*)/u', '<em>$1</em>', $text );
	}

	/**
	 * Texte brut : balises retirées, entités décodées, espaces resserrés.
	 *
	 * @param string $text       Texte ou HTML.
	 * @param bool   $keep_lines Garder les sauts de ligne (paragraphes).
	 * @return string
	 */
	public static function plain( $text, $keep_lines = false ) {
		$text = (string) $text;
		if ( false !== strpos( $text, '<' ) ) {
			$text = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $text );
			$text = (string) preg_replace( '#<br\s*/?>|</(p|h[1-6]|li|blockquote|div)>#i', "\n", $text );
			$text = strip_tags( $text );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $keep_lines ) {
			$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
			$text = (string) preg_replace( '/ *\n */', "\n", $text );
			$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
			return trim( $text );
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/* ---------------------------------------------------------------------
	 * Nouvelle entrée : schéma JSON et correspondance avec les champs
	 * ------------------------------------------------------------------- */

	/**
	 * Champs que l'IA sait remplir.
	 *
	 * @param array $fields Champs (format éditeur).
	 * @return array
	 */
	public static function fillable( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['path'] ) || ! in_array( isset( $field['type'] ) ? $field['type'] : '', self::FILLABLE_TYPES, true ) ) {
				continue;
			}
			if ( ( isset( $field['supported'] ) && false === $field['supported'] ) || ! empty( $field['readonly'] ) || ! empty( $field['blocks'] ) ) {
				continue;
			}
			if ( 'select' === $field['type'] && ( ! empty( $field['multiple'] ) || empty( $field['options'] ) ) ) {
				continue;
			}
			if ( in_array( $field['type'], array( 'repeater', 'group', 'clone' ), true ) && ! self::fillable( isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array() ) ) {
				continue;
			}
			if ( 'flexible_content' === $field['type'] && empty( $field['layouts'] ) ) {
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Schéma JSON de la réponse attendue pour une nouvelle entrée.
	 *
	 * @param array $fields Champs (format éditeur).
	 * @return array
	 */
	public static function entry_schema( array $fields ) {
		$properties = array();
		$required   = array();
		foreach ( self::fillable( $fields ) as $field ) {
			$properties[ $field['path'] ] = self::value_schema( $field, 0 );
			if ( ! empty( $field['required'] ) ) {
				$required[] = $field['path'];
			}
		}
		$schema = array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Schéma JSON d'une valeur de champ.
	 *
	 * @param array $field Champ.
	 * @param int   $depth Profondeur.
	 * @return array
	 */
	private static function value_schema( array $field, $depth ) {
		$description = self::str( $field, 'label' );
		if ( '' !== self::str( $field, 'help' ) ) {
			$description .= ' — ' . self::cut( self::str( $field, 'help' ), 200 );
		}
		$spec = self::field_spec( $field );

		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
				$schema = array( 'type' => 'string' );
				if ( $spec['max'] > 0 ) {
					$schema['maxLength'] = $spec['max'];
					$description        .= ' (max ' . $spec['max'] . ' characters)';
				}
				break;
			case 'wysiwyg':
				$schema       = array( 'type' => 'string' );
				$description .= ' (HTML)';
				break;
			case 'select':
				$schema = array(
					'type' => 'string',
					'enum' => array_map( 'strval', array_keys( (array) $field['options'] ) ),
				);
				break;
			case 'true_false':
				$schema = array( 'type' => 'boolean' );
				break;
			case 'number':
			case 'range':
				$schema = array( 'type' => 'number' );
				if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
					$schema['minimum'] = (float) $field['min'];
				}
				if ( isset( $field['max'] ) && is_numeric( $field['max'] ) && (float) $field['max'] > 0 ) {
					$schema['maximum'] = (float) $field['max'];
				}
				break;
			case 'group':
			case 'clone':
				$schema = self::object_schema( isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array(), $depth + 1 );
				break;
			case 'repeater':
				$schema = array(
					'type'     => 'array',
					'items'    => self::object_schema( isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array(), $depth + 1 ),
					'maxItems' => self::max_rows( $field ),
				);
				if ( ! empty( $field['min'] ) ) {
					$schema['minItems'] = (int) $field['min'];
				}
				break;
			case 'flexible_content':
				// Union des champs de toutes les sections, et « _layout » pour
				// choisir : plus portable entre fournisseurs qu'un anyOf.
				$names      = array();
				$properties = array();
				foreach ( (array) $field['layouts'] as $layout ) {
					$names[] = (string) $layout['name'];
					foreach ( self::fillable( isset( $layout['sub_fields'] ) ? (array) $layout['sub_fields'] : array() ) as $sub ) {
						if ( ! isset( $properties[ $sub['path'] ] ) ) {
							$properties[ $sub['path'] ] = $depth < 6 ? self::value_schema( $sub, $depth + 1 ) : array( 'type' => 'string' );
						}
					}
				}
				$properties = array_merge(
					array(
						'_layout' => array(
							'type' => 'string',
							'enum' => $names,
						),
					),
					$properties
				);
				$schema = array(
					'type'     => 'array',
					'items'    => array(
						'type'                 => 'object',
						'properties'           => (object) $properties,
						'required'             => array( '_layout' ),
						'additionalProperties' => false,
					),
					'maxItems' => self::max_rows( $field ),
				);
				break;
			default:
				$schema = array( 'type' => 'string' );
		}
		$schema['description'] = $description;
		return $schema;
	}

	/**
	 * @param array $subs  Sous-champs.
	 * @param int   $depth Profondeur.
	 * @return array
	 */
	private static function object_schema( array $subs, $depth ) {
		$properties = array();
		foreach ( self::fillable( $subs ) as $sub ) {
			$properties[ $sub['path'] ] = $depth < 6 ? self::value_schema( $sub, $depth ) : array( 'type' => 'string' );
		}
		return array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * @param array $field Liste.
	 * @return int
	 */
	private static function max_rows( array $field ) {
		$max = isset( $field['max'] ) ? (int) $field['max'] : 0;
		return $max > 0 ? min( $max, 20 ) : self::MAX_ROWS;
	}

	/**
	 * Décode la réponse JSON du modèle (bloc de code ou texte autour tolérés).
	 *
	 * @param string $raw Réponse.
	 * @return array|null
	 */
	public static function decode_json( $raw ) {
		$text = trim( (string) $raw );
		if ( preg_match( '/```(?:json)?\s*(.*?)```/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		// Un objet JSON, pas une liste.
		if ( ! is_array( $data ) || ( array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Valeurs de champs (chemins racine, format de l'éditeur) tirées de la
	 * réponse du modèle : types vérifiés, textes nettoyés et coupés à la
	 * longueur maximale, choix et sections validés. Ce qui ne correspond à
	 * rien est ignoré.
	 *
	 * @param array    $fields   Champs (format éditeur).
	 * @param array    $data     Réponse décodée.
	 * @param array    $brand    Contexte de marque (contrôle des interdits).
	 * @param callable $kses     Nettoyage HTML (wp_kses_post).
	 * @param array    $warnings Sortie : chemin pointé => { forbidden, trimmed }.
	 * @return array<string,mixed>
	 */
	public static function map_values( array $fields, array $data, array $brand, $kses, array &$warnings ) {
		$values = array();
		foreach ( self::fillable( $fields ) as $field ) {
			if ( ! array_key_exists( $field['path'], $data ) ) {
				continue;
			}
			$value = self::map_value( $field, $data[ $field['path'] ], $field['path'], $brand, $kses, $warnings );
			if ( null !== $value ) {
				$values[ $field['path'] ] = $value;
			}
		}
		return $values;
	}

	/**
	 * @param array    $field    Champ.
	 * @param mixed    $raw      Valeur proposée.
	 * @param string   $path     Chemin pointé (avertissements).
	 * @param array    $brand    Contexte de marque.
	 * @param callable $kses     Nettoyage HTML.
	 * @param array    $warnings Avertissements.
	 * @return mixed|null null : rien d'utilisable.
	 */
	private static function map_value( array $field, $raw, $path, array $brand, $kses, array &$warnings ) {
		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
				if ( ! is_scalar( $raw ) ) {
					return null;
				}
				$spec = self::field_spec( $field );
				$text = self::clean_output( (string) $raw, $spec );
				if ( 'html' === $spec['format'] ) {
					$text = is_callable( $kses ) ? (string) call_user_func( $kses, $text ) : strip_tags( $text, '<p><h2><h3><h4><strong><em><a><ul><ol><li><blockquote><br>' );
				} elseif ( $spec['max'] > 0 && self::length( $text ) > $spec['max'] ) {
					$text = self::trim_to_length( $text, $spec['max'] );
					$warnings[ $path ]['trimmed'] = true;
				}
				if ( '' === trim( self::plain( $text ) ) ) {
					return null;
				}
				$forbidden = self::forbidden_matches( $text, self::items( $brand, 'forbidden' ) );
				if ( $forbidden ) {
					$warnings[ $path ]['forbidden'] = $forbidden;
				}
				return $text;

			case 'select':
				$raw = is_scalar( $raw ) ? (string) $raw : '';
				return array_key_exists( $raw, (array) $field['options'] ) ? $raw : null;

			case 'true_false':
				return is_bool( $raw ) || is_numeric( $raw ) ? (bool) $raw : null;

			case 'number':
			case 'range':
				if ( ! is_numeric( $raw ) ) {
					return null;
				}
				$number = (float) $raw;
				if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
					$number = max( (float) $field['min'], $number );
				}
				if ( isset( $field['max'] ) && is_numeric( $field['max'] ) && (float) $field['max'] > 0 ) {
					$number = min( (float) $field['max'], $number );
				}
				return floor( $number ) === $number ? (int) $number : $number;

			case 'group':
			case 'clone':
				if ( ! is_array( $raw ) ) {
					return null;
				}
				$row = self::map_row( isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array(), $raw, $path, $brand, $kses, $warnings );
				return $row ? $row : null;

			case 'repeater':
			case 'flexible_content':
				if ( ! is_array( $raw ) ) {
					return null;
				}
				$layouts = array();
				foreach ( isset( $field['layouts'] ) ? (array) $field['layouts'] : array() as $layout ) {
					$layouts[ (string) $layout['name'] ] = isset( $layout['sub_fields'] ) ? (array) $layout['sub_fields'] : array();
				}
				$rows = array();
				foreach ( array_values( $raw ) as $item ) {
					if ( count( $rows ) >= self::max_rows( $field ) ) {
						break;
					}
					if ( ! is_array( $item ) ) {
						continue;
					}
					$index = count( $rows );
					if ( 'flexible_content' === $field['type'] ) {
						$name = isset( $item['_layout'] ) && is_scalar( $item['_layout'] ) ? (string) $item['_layout'] : '';
						if ( ! isset( $layouts[ $name ] ) ) {
							continue;
						}
						$row = self::map_row( $layouts[ $name ], $item, $path . '.' . $index, $brand, $kses, $warnings );
						$row = array_merge( array( '_layout' => $name ), $row );
					} else {
						$row = self::map_row( isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array(), $item, $path . '.' . $index, $brand, $kses, $warnings );
						if ( ! $row ) {
							continue;
						}
					}
					$rows[] = $row;
				}
				return $rows ? $rows : null;
		}
		return null;
	}

	/**
	 * @param array    $subs     Sous-champs.
	 * @param array    $raw      Ligne proposée.
	 * @param string   $path     Chemin de la ligne.
	 * @param array    $brand    Contexte de marque.
	 * @param callable $kses     Nettoyage HTML.
	 * @param array    $warnings Avertissements.
	 * @return array
	 */
	private static function map_row( array $subs, array $raw, $path, array $brand, $kses, array &$warnings ) {
		$row = array();
		foreach ( self::fillable( $subs ) as $sub ) {
			if ( ! array_key_exists( $sub['path'], $raw ) ) {
				continue;
			}
			$value = self::map_value( $sub, $raw[ $sub['path'] ], $path . '.' . $sub['path'], $brand, $kses, $warnings );
			if ( null !== $value ) {
				$row[ $sub['path'] ] = $value;
			}
		}
		return $row;
	}

	/* ---------------------------------------------------------------------
	 * Utilitaires
	 * ------------------------------------------------------------------- */

	/**
	 * @param array  $data Tableau.
	 * @param string $key  Clé.
	 * @return string
	 */
	private static function str( array $data, $key ) {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}

	/**
	 * @param array  $data Tableau.
	 * @param string $key  Clé.
	 * @return string[]
	 */
	private static function items( array $data, $key ) {
		$items = isset( $data[ $key ] ) ? $data[ $key ] : array();
		if ( is_string( $items ) ) {
			$items = preg_split( '/\r?\n/', $items );
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$out[] = trim( (string) $item );
			}
		}
		return $out;
	}

	/**
	 * @param string $text Texte.
	 * @param int    $max  Longueur maximale (caractères).
	 * @return string
	 */
	private static function cut( $text, $max ) {
		return self::mb_len( $text ) > $max ? rtrim( self::mb_sub( $text, 0, $max ) ) . '…' : $text;
	}

	private static function mb_len( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	private static function mb_sub( $text, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, $start, $length, 'UTF-8' ) : substr( (string) $text, $start, $length );
	}
}
