<?php
/**
 * Adapter JetEngine : définitions et formats de stockage relevés sur un site
 * réel (JetEngine 3.9) — repeater « item-N », média en ID, date en
 * timestamp, interrupteur « true » / « false ».
 * Lancement : php plugin/tests/test-jetengine-adapter.php
 */
require __DIR__ . '/stubs.php';
require __DIR__ . '/../includes/adapters/class-leiho-jetengine-adapter.php';

$GLOBALS['meta']    = array();
$GLOBALS['actions'] = array();

function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) {
	$value = wp_unslash( $value );
	if ( isset( $GLOBALS['meta'][ $id ][ $key ] ) && $GLOBALS['meta'][ $id ][ $key ] === $value ) {
		return false;
	}
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	return true;
}
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function attachment_url_to_postid( $url ) { return preg_match( '#/(\d+)\.jpg$#', $url, $m ) ? (int) $m[1] : 0; }
function do_action( $tag, ...$args ) { $GLOBALS['actions'][] = $tag; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) { return json_encode( $v ); }
}
// Méthode du formulaire de JetEngine qui réécrit les copies d'un repeater « en champs séparés ».
class Cherry_X_Post_Meta {
	public static function _save_repeater_separate_fields( $post_id, $key, $value, $field ) {
		$GLOBALS['separate'][] = array( $key, $value );
	}
}

class Jet_Engine_CPT_Meta {
	public function filter_options_list( $list, $field ) {
		$out = array();
		foreach ( isset( $field['options'] ) ? $field['options'] : array() as $o ) {
			$out[] = array( 'key' => $o['key'], 'value' => $o['value'] );
		}
		return $out;
	}
}
class Jet_Stub_Meta_Boxes {
	public $fields = array();
	public function get_meta_fields_for_object( $object ) { return isset( $this->fields[ $object ] ) ? $this->fields[ $object ] : array(); }
}
function jet_engine() {
	static $je = null;
	if ( null === $je ) {
		$je             = new stdClass();
		$je->meta_boxes = new Jet_Stub_Meta_Boxes();
	}
	return $je;
}

// Champs d'un type « page » (le stub get_post_type renvoie « page »).
jet_engine()->meta_boxes->fields['page'] = array(
	array( 'title' => 'Onglet', 'name' => 'tab_1', 'object_type' => 'tab', 'type' => 'text' ),
	array( 'title' => 'Description courte', 'name' => 'short_description', 'object_type' => 'field', 'type' => 'text', 'is_required' => true ),
	array( 'title' => 'Salaire', 'name' => 'min_salary', 'object_type' => 'field', 'type' => 'number' ),
	array( 'title' => 'Date', 'name' => 'created', 'object_type' => 'field', 'type' => 'date', 'is_timestamp' => true ),
	array( 'title' => 'Date texte', 'name' => 'day', 'object_type' => 'field', 'type' => 'date' ),
	array( 'title' => 'Compétences', 'name' => 'skills', 'object_type' => 'field', 'type' => 'repeater', 'repeater-fields' => array(
		array( 'title' => 'Compétence', 'name' => 'skill', 'type' => 'text' ),
		array( 'title' => 'Icône', 'name' => 'icon', 'type' => 'media', 'value_format' => 'id' ),
	) ),
	array( 'title' => 'Image', 'name' => 'job_image', 'object_type' => 'field', 'type' => 'media', 'value_format' => 'id' ),
	array( 'title' => 'Logo', 'name' => 'logo', 'object_type' => 'field', 'type' => 'media', 'value_format' => 'both' ),
	array( 'title' => 'Photos', 'name' => 'photos', 'object_type' => 'field', 'type' => 'gallery', 'value_format' => 'id' ),
	array( 'title' => 'Cacher', 'name' => 'hide', 'object_type' => 'field', 'type' => 'switcher' ),
	array( 'title' => 'Formule', 'name' => 'plan', 'object_type' => 'field', 'type' => 'select', 'options' => array(
		array( 'key' => 'basic', 'value' => 'Basic' ),
		array( 'key' => 'pro', 'value' => 'Pro' ),
	) ),
	array( 'title' => 'Options', 'name' => 'opts', 'object_type' => 'field', 'type' => 'checkbox', 'options' => array(
		array( 'key' => 'a', 'value' => 'A' ),
		array( 'key' => 'b', 'value' => 'B' ),
	) ),
	array( 'title' => 'Tags', 'name' => 'tags', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => true, 'options' => array(
		array( 'key' => 'x', 'value' => 'X' ),
		array( 'key' => 'y', 'value' => 'Y' ),
	) ),
	array( 'title' => 'Zones', 'name' => 'zones', 'object_type' => 'field', 'type' => 'select', 'is_multiple' => true, 'options' => array(
		array( 'key' => 'n', 'value' => 'Nord' ),
		array( 'key' => 's', 'value' => 'Sud' ),
	) ),
	array( 'title' => 'FAQ', 'name' => 'faq', 'object_type' => 'field', 'type' => 'repeater', 'repeater_save_separate' => true, 'repeater-fields' => array(
		array( 'title' => 'Q', 'name' => 'q', 'type' => 'text' ),
	) ),
	array( 'title' => 'Aide', 'name' => 'help', 'object_type' => 'field', 'type' => 'html', 'html' => '<p>Texte</p>' ),
	array( 'title' => 'Couleur', 'name' => 'color', 'object_type' => 'field', 'type' => 'colorpicker' ),
	array( 'title' => 'Icône', 'name' => 'icon_class', 'object_type' => 'field', 'type' => 'iconpicker' ),
	array( 'title' => 'Lieu', 'name' => 'place', 'object_type' => 'field', 'type' => 'map' ),
	array( 'title' => 'Lieu JSON', 'name' => 'place_json', 'object_type' => 'field', 'type' => 'map', 'map_value_format' => 'location_array' ),
	array( 'title' => 'Adresse', 'name' => 'place_addr', 'object_type' => 'field', 'type' => 'map', 'map_value_format' => 'location_address' ),
	array( 'title' => 'Lieu Hash', 'name' => md5( 'place' ) . '_hash', 'object_type' => 'field', 'type' => 'hidden' ),
	array( 'title' => 'Agences', 'name' => 'offices', 'object_type' => 'field', 'type' => 'repeater', 'repeater-fields' => array(
		array( 'title' => 'Ville', 'name' => 'city', 'type' => 'text' ),
		array( 'title' => 'Position', 'name' => 'pos', 'type' => 'map' ),
	) ),
);

$GLOBALS['meta'][1] = array(
	'short_description' => 'Femme de chambre',
	'min_salary'        => '1800',
	'created'           => '1795168408',
	'day'               => '2026-11-20',
	'skills'            => array(
		'item-0' => array( 'skill' => 'repassage', 'icon' => '7' ),
		'item-1' => array( 'skill' => 'gestion du linge', 'icon' => '' ),
	),
	'job_image'         => '7922',
	'logo'              => array( 'id' => '8', 'url' => 'https://cdn/8.jpg' ),
	'photos'            => '7,8',
	'hide'              => 'false',
	'plan'              => 'pro',
	'opts'              => array( 'a' => 'true', 'b' => 'false' ),
	'faq'               => array( 'item-0' => array( 'q' => 'Prix ?' ) ),
	'color'             => '#ff0000',
	'icon_class'        => 'fas fa-star',
	'place'             => '48.8566,2.3522',
	'place_json'        => '{"lat":43.2965,"lng":5.3698}',
	'place_addr'        => '1 rue de Rivoli, Paris',
	'offices'           => array( 'item-0' => array( 'city' => 'Lyon', 'pos' => '45.764,4.8357', 'pos_hash' => 'x' ) ),
);

$adapter = new Leiho_JetEngine_Adapter();
assert_true( 'disponible avec JetEngine', $adapter->is_available() );

/* --- Lecture --- */
$schema = $adapter->get_schema( 1 );
assert_true( 'un groupe JetEngine', 1 === count( $schema ) && 'jetengine' === $schema[0]['source'], $schema );
$by = array();
foreach ( $schema[0]['fields'] as $f ) {
	$by[ $f['path'] ] = $f;
}
assert_true( 'onglet ignoré', ! isset( $by['tab_1'] ) );
assert_true( 'bloc HTML ignoré', ! isset( $by['help'] ) );
assert_true( 'texte lu', 'Femme de chambre' === $by['short_description']['value'] );
assert_true( 'obligatoire repris', true === $by['short_description']['required'] );
assert_true( 'nombre lu', 1800.0 === $by['min_salary']['value'], $by['min_salary'] );
assert_true( 'timestamp → date', '20261119' === $by['created']['value'] || '20261120' === $by['created']['value'], $by['created'] );
assert_true( 'date texte lue', '20261120' === $by['day']['value'], $by['day'] );
assert_true( 'repeater item-N → lignes', array( 'repassage', 'gestion du linge' ) === array_column( $by['skills']['value'], 'skill' ), $by['skills'] );
assert_true( 'image du repeater', 7 === $by['skills']['value'][0]['icon'], $by['skills']['value'] );
assert_true( 'média en ID', 7922 === $by['job_image']['value'] );
assert_true( 'média { id, url }', 8 === $by['logo']['value'], $by['logo'] );
assert_true( 'galerie « 7,8 »', array( 7, 8 ) === $by['photos']['value'], $by['photos'] );
assert_true( 'interrupteur « false »', false === $by['hide']['value'] );
assert_true( 'select et ses choix', 'pro' === $by['plan']['value'] && array( 'basic' => 'Basic', 'pro' => 'Pro' ) === $by['plan']['options'], $by['plan'] );
assert_true( 'cases à cocher : liste multiple', ! empty( $by['opts']['multiple'] ) );
assert_true( 'repeater en champs séparés : éditable', true === $by['faq']['supported'] );
assert_true( 'couleur', 'color_picker' === $by['color']['type'] && $by['color']['supported'] && '#ff0000' === $by['color']['value'], $by['color'] );
assert_true( 'icône : sa classe en texte', 'text' === $by['icon_class']['type'] && 'fas fa-star' === $by['icon_class']['value'], $by['icon_class'] );
assert_true( 'carte « lat,lng » → groupe', 'group' === $by['place']['type'] && $by['place']['supported'] && array( 'lat' => 48.8566, 'lng' => 2.3522 ) == $by['place']['value'], $by['place'] );
assert_true( 'carte JSON → groupe', array( 'lat' => 43.2965, 'lng' => 5.3698 ) == $by['place_json']['value'], $by['place_json'] );
assert_true( 'carte adresse → groupe', array( 'address' => '1 rue de Rivoli, Paris' ) == $by['place_addr']['value'], $by['place_addr'] );
assert_true( 'champ caché de la carte ignoré', ! isset( $by[ md5( 'place' ) . '_hash' ] ) );
assert_true( 'carte dans un repeater', array( 'lat' => 45.764, 'lng' => 4.8357 ) == $by['offices']['value'][0]['pos'], $by['offices'] );

/* --- Écriture : chaque format revient tel que JetEngine l'écrit --- */
$r = $adapter->save_values( 1, array( 'short_description' => 'Gouvernante', 'skills.1.skill' => 'linge \\ maison', 'hide' => true ) );
assert_true( 'trois chemins enregistrés', 3 === count( $r['saved'] ) && empty( $r['errors'] ), $r );
assert_true( 'texte écrit', 'Gouvernante' === $GLOBALS['meta'][1]['short_description'] );
assert_true( 'repeater réécrit en item-N', array( 'item-0', 'item-1' ) === array_keys( $GLOBALS['meta'][1]['skills'] ), $GLOBALS['meta'][1]['skills'] );
assert_true( 'ligne patchée, barre oblique gardée', 'linge \\ maison' === $GLOBALS['meta'][1]['skills']['item-1']['skill'], $GLOBALS['meta'][1]['skills'] );
assert_true( 'autre ligne intacte, média en ID', array( 'skill' => 'repassage', 'icon' => '7' ) === $GLOBALS['meta'][1]['skills']['item-0'], $GLOBALS['meta'][1]['skills'] );
assert_true( 'interrupteur « true »', 'true' === $GLOBALS['meta'][1]['hide'] );

$r = $adapter->save_values( 1, array( 'created' => '20261201', 'day' => '20261202', 'job_image' => 8, 'logo' => 7, 'photos' => array( 8 ), 'min_salary' => 2000 ) );
assert_true( 'six champs enregistrés', 6 === count( $r['saved'] ), $r );
assert_true( 'date → timestamp', (string) strtotime( '2026-12-01 00:00:00 UTC' ) === $GLOBALS['meta'][1]['created'], $GLOBALS['meta'][1]['created'] );
assert_true( 'date texte → Y-m-d', '2026-12-02' === $GLOBALS['meta'][1]['day'], $GLOBALS['meta'][1]['day'] );
assert_true( 'média ID écrit en chaîne', '8' === $GLOBALS['meta'][1]['job_image'] );
assert_true( 'média { id, url }', array( 'id' => '7', 'url' => 'https://cdn/7.jpg' ) === $GLOBALS['meta'][1]['logo'], $GLOBALS['meta'][1]['logo'] );
assert_true( 'galerie « 8 »', '8' === $GLOBALS['meta'][1]['photos'], $GLOBALS['meta'][1]['photos'] );
assert_true( 'nombre en chaîne', '2000' === $GLOBALS['meta'][1]['min_salary'], $GLOBALS['meta'][1]['min_salary'] );

/* --- Refus --- */
$r = $adapter->save_values( 1, array( 'short_description' => '' ) );
assert_true( 'obligatoire vide refusé', isset( $r['errors']['short_description'] ) && 'Gouvernante' === $GLOBALS['meta'][1]['short_description'], $r );
$r = $adapter->save_values( 1, array( 'plan' => 'gold' ) );
assert_true( 'choix inconnu refusé', 'invalid_choice' === $r['errors']['plan'], $r );
$r = $adapter->save_values( 1, array( 'color' => 'rouge' ) );
assert_true( 'couleur invalide refusée', 'invalid_color' === $r['errors']['color'] && '#ff0000' === $GLOBALS['meta'][1]['color'], $r );

/* --- Types propres à JetEngine --- */
$GLOBALS['separate'] = array();
$r = $adapter->save_values( 1, array( 'faq.0.q' => 'Délai ?' ) );
assert_true( 'repeater séparé écrit', empty( $r['errors'] ) && 'Délai ?' === $GLOBALS['meta'][1]['faq']['item-0']['q'], $r );
assert_true( 'copies séparées réécrites par JetEngine', array( array( 'faq', array( 'item-0' => array( 'q' => 'Délai ?' ) ) ) ) === $GLOBALS['separate'], $GLOBALS['separate'] );

$r = $adapter->save_values( 1, array( 'color' => '#00ff00', 'icon_class' => 'fas fa-heart', 'place.lat' => 45.5, 'place_json' => array( 'lat' => 1.5, 'lng' => -2.25 ), 'place_addr.address' => '2 place Bellecour, Lyon' ) );
assert_true( 'couleur, icône, cartes enregistrées', empty( $r['errors'] ), $r );
$m = $GLOBALS['meta'][1];
assert_true( 'couleur écrite', '#00ff00' === $m['color'] );
assert_true( 'icône écrite', 'fas fa-heart' === $m['icon_class'] );
assert_true( 'carte « lat,lng » écrite', '45.5,2.3522' === $m['place'], $m['place'] );
$p = md5( 'place' );
assert_true( 'empreinte et coordonnées de la carte', md5( '45.5,2.3522' ) === $m[ $p . '_hash' ] && '45.5' === $m[ $p . '_lat' ] && '2.3522' === $m[ $p . '_lng' ], array( $m[ $p . '_hash' ] ?? null, $m[ $p . '_lat' ] ?? null ) );
assert_true( 'carte JSON écrite', '{"lat":1.5,"lng":-2.25}' === $m['place_json'], $m['place_json'] );
$a = md5( 'place_addr' );
assert_true( 'carte adresse écrite, sans coordonnées', '2 place Bellecour, Lyon' === $m['place_addr'] && md5( '2 place Bellecour, Lyon' ) === $m[ $a . '_hash' ] && ! isset( $m[ $a . '_lat' ] ), $m['place_addr'] );

$r = $adapter->save_values( 1, array( 'offices.0.pos.lng' => 4.9 ) );
$row = $GLOBALS['meta'][1]['offices']['item-0'];
assert_true( 'carte du repeater : valeur et champs cachés dans la ligne', empty( $r['errors'] ) && '45.764,4.9' === $row['pos'] && md5( '45.764,4.9' ) === $row['pos_hash'] && '4.9' === $row['pos_lng'], array( $r, $row ) );

$r = $adapter->save_values( 1, array( 'place' => array( 'lat' => '', 'lng' => '' ) ) );
assert_true( 'carte vidée : champs cachés effacés', empty( $r['errors'] ) && '' === $GLOBALS['meta'][1]['place'] && ! isset( $GLOBALS['meta'][1][ $p . '_hash' ] ), array( $r, $GLOBALS['meta'][1]['place'] ) );
$r = $adapter->save_values( 1, array( 'opts' => array( 'b', 'zzz' ) ) );
assert_true( 'case inconnue refusée', isset( $r['errors']['opts'] ) && array( 'a' => 'true', 'b' => 'false' ) === $GLOBALS['meta'][1]['opts'], $r );

/* --- Choix multiples --- */
$r = $adapter->save_values( 1, array( 'opts' => array( 'b' ) ) );
assert_true( 'cases à cocher : valeur => true / false', empty( $r['errors'] ) && array( 'a' => 'false', 'b' => 'true' ) === $GLOBALS['meta'][1]['opts'], array( $r, $GLOBALS['meta'][1]['opts'] ) );
$r = $adapter->save_values( 1, array( 'tags' => array( 'y', 'x' ) ) );
assert_true( 'cases « is_array » : liste', empty( $r['errors'] ) && array( 'y', 'x' ) === $GLOBALS['meta'][1]['tags'], array( $r, $GLOBALS['meta'][1]['tags'] ?? null ) );
$r = $adapter->save_values( 1, array( 'zones' => array( 'n' ) ) );
assert_true( 'select multiple : liste', empty( $r['errors'] ) && array( 'n' ) === $GLOBALS['meta'][1]['zones'], array( $r, $GLOBALS['meta'][1]['zones'] ?? null ) );
$r = $adapter->save_values( 1, array( 'autre_champ' => 'x' ) );
assert_true( 'champ inconnu laissé aux autres adapters', empty( $r['saved'] ) && empty( $r['errors'] ), $r );

/* --- Définitions sans valeur (agents, IA) --- */
$defs = $adapter->describe_type( 'page' );
assert_true( 'describe_type : champs décrits', count( $defs ) === count( $schema[0]['fields'] ), count( $defs ) );
assert_true( 'aucun champ hors des types JetEngine', array() === $adapter->get_definitions( 'post_inconnu' ) );

echo empty( $GLOBALS['failed'] ) ? "\nTOUS LES TESTS PASSENT\n" : "\nÉCHECS\n";
exit( empty( $GLOBALS['failed'] ) ? 0 : 1 );
