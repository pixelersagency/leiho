<?php
defined( 'ABSPATH' ) || exit;

/**
 * Texte brut d'un champ (text, textarea, libellé d'un lien) : gardé tel que
 * saisi, échappé à l'affichage selon le contexte (esc_html, textContent,
 * JSON…), jamais à l'enregistrement.
 *
 * sanitize_text_field() altérait des valeurs légitimes : un « < » isolé
 * devenait « &lt; » (affiché tel quel par le front), les espaces de début et
 * de fin disparaissaient (seul séparateur entre deux champs composés en une
 * phrase), les espaces multiples étaient resserrés et « %ce » ou « %de »
 * (octets encodés) retirés.
 *
 * Contrat (README, champ « text » des Collections) : du texte, pas du HTML.
 * - UTF-8 invalide : valeur vide (comme wp_check_invalid_utf8()).
 * - Caractères de contrôle C0 et DEL retirés, sauf tabulation et saut de
 *   ligne ; \r\n et \r deviennent \n.
 * - Balises retirées, texte et espaces autour gardés : « a <b>x</b> b » →
 *   « a x b ». script et style partent avec leur contenu, les commentaires
 *   aussi. Une balise ouverte et jamais fermée (« a <img src=x onerror=… »)
 *   est retirée jusqu'à la fin de la valeur, comme strip_tags() : recopiée
 *   telle quelle dans un gabarit qui n'échappe pas, elle deviendrait une
 *   vraie balise.
 * - Un « < » qui n'ouvre pas de balise (suivi d'un espace, d'un chiffre, d'un
 *   symbole) reste un « < » : « < 1 000 », « x<1 », « <3 », « 5 > 4 ».
 * - Ni entités ajoutées ni entités décodées : « &amp; » saisi reste « &amp; ».
 * - Pas de trim, pas d'espaces resserrés.
 * - Une ligne : chaque saut de ligne devient une espace.
 *
 * Classe pure : testable sans WordPress.
 */
class Leiho_Text {

	/** Balise complète (attributs entre guillemets compris) ou commentaire. */
	const TAG = '/<!--.*?-->|<[a-zA-Z\/!?](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s';

	/** Début de balise jamais fermée : retiré jusqu'à la fin. */
	const OPEN_TAG = '/<[a-zA-Z\/!?].*$/s';

	/**
	 * Texte brut prêt à stocker.
	 *
	 * @param mixed $value     Valeur reçue.
	 * @param bool  $multiline Sauts de ligne gardés (textarea).
	 * @return string
	 */
	public static function clean( $value, $multiline = false ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$text = wp_check_invalid_utf8( $text );
		} elseif ( ! preg_match( '//u', $text ) ) {
			$text = '';
		}
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text = self::strip_tags( $text );
		if ( ! $multiline ) {
			$text = str_replace( "\n", ' ', $text );
		}
		return $text;
	}

	/**
	 * Retire les balises sans toucher au reste : ni trim, ni entités, ni
	 * « < » isolé (contrairement à strip_tags(), qui mange « x<1 » et « <3 »).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function strip_tags( $text ) {
		$text = (string) $text;
		if ( false === strpos( $text, '<' ) ) {
			return $text;
		}
		$text = (string) preg_replace( '@<(script|style)\b[^>]*>.*?</\1\s*>@si', '', $text );
		// Jusqu'à stabilité : « <scr<b>ipt> » ne laisse pas « <script> ».
		do {
			$before = $text;
			$text   = (string) preg_replace( self::TAG, '', $text );
		} while ( $before !== $text );
		return (string) preg_replace( self::OPEN_TAG, '', $text );
	}
}
