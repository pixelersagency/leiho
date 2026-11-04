<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion du schéma Leiho Collections (administrateurs) :
 *
 *   GET    /leiho/v1/collections             schéma + état des tables
 *   POST   /leiho/v1/collections             crée une collection (?dry_run=1)
 *   GET    /leiho/v1/collections/export      leiho.schema.json
 *   POST   /leiho/v1/collections/import      remplace le schéma (?dry_run=1)
 *   GET    /leiho/v1/collections/{id}        une collection + son état
 *   PUT    /leiho/v1/collections/{id}        remplace sa définition (?dry_run=1)
 *   DELETE /leiho/v1/collections/{id}        la retire du schéma (données gardées)
 *   POST   /leiho/v1/collections/{id}/purge  supprime ses colonnes archivées
 */
class Leiho_Collections_Rest {

	public function register_routes() {
		$admin = array( __CLASS__, 'can_manage' );
		$dry   = array(
			'dry_run' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/collections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_collections' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_collection' ),
					'permission_callback' => $admin,
					'args'                => $dry,
				),
			)
		);

		// Avant la route {id} : « export » et « import » sont des ids réservés.
		register_rest_route(
			Leiho_Rest::NS,
			'/collections/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/collections/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $admin,
				'args'                => $dry,
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/collections/(?P<id>[a-z][a-z0-9_]{0,19})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_collection' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_collection' ),
					'permission_callback' => $admin,
					'args'                => $dry,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_collection' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route(
			Leiho_Rest::NS,
			'/collections/(?P<id>[a-z][a-z0-9_]{0,19})/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Le schéma crée des tables : réservé aux administrateurs.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		/**
		 * Capacité requise pour modifier le schéma des Collections.
		 *
		 * @param string $capability Défaut : manage_options.
		 */
		return current_user_can( apply_filters( 'leiho_collections_capability', 'manage_options' ) );
	}

	public function list_collections() {
		$out = array();
		foreach ( Leiho_Collections::schema()['collections'] as $collection ) {
			$out[] = $this->item( $collection );
		}
		return rest_ensure_response(
			array(
				'version'     => Leiho_Collections_Schema::VERSION,
				'collections' => $out,
			)
		);
	}

	public function get_collection( WP_REST_Request $request ) {
		$collection = Leiho_Collections::get( (string) $request['id'] );
		if ( ! $collection ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown collection.', 'leiho' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->item( $collection ) );
	}

	public function create_collection( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'leiho_bad_request', __( 'Send the collection as JSON.', 'leiho' ), array( 'status' => 400 ) );
		}
		if ( isset( $body['id'] ) && Leiho_Collections::get( (string) $body['id'] ) ) {
			return new WP_Error( 'leiho_exists', __( 'A collection with this id already exists.', 'leiho' ), array( 'status' => 409 ) );
		}
		return $this->respond( Leiho_Collections::put_collection( $body, (bool) $request->get_param( 'dry_run' ) ), 201 );
	}

	public function update_collection( WP_REST_Request $request ) {
		$id   = (string) $request['id'];
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'leiho_bad_request', __( 'Send the collection as JSON.', 'leiho' ), array( 'status' => 400 ) );
		}
		if ( isset( $body['id'] ) && $body['id'] !== $id ) {
			return new WP_Error( 'leiho_bad_request', __( 'A collection id never changes.', 'leiho' ), array( 'status' => 400 ) );
		}
		if ( ! Leiho_Collections::get( $id ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown collection.', 'leiho' ), array( 'status' => 404 ) );
		}
		$body['id'] = $id;
		return $this->respond( Leiho_Collections::put_collection( $body, (bool) $request->get_param( 'dry_run' ) ) );
	}

	public function delete_collection( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! Leiho_Collections::get( $id ) ) {
			return new WP_Error( 'leiho_not_found', __( 'Unknown collection.', 'leiho' ), array( 'status' => 404 ) );
		}
		if ( class_exists( 'Leiho_ACF_Import' ) && Leiho_ACF_Import::record( $id ) ) {
			return new WP_Error( 'leiho_imported', __( 'This collection was imported from ACF: go back to ACF instead (collections/import-acf/rollback).', 'leiho' ), array( 'status' => 409 ) );
		}
		return $this->respond( Leiho_Collections::delete_collection( $id ) );
	}

	public function purge( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( $id !== (string) $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'leiho_confirm', __( 'Set confirm to the collection id to drop its archived columns. This deletes their data.', 'leiho' ), array( 'status' => 400 ) );
		}
		$columns = $request->get_param( 'columns' );
		$dropped = Leiho_Collections_Store::purge( $id, is_array( $columns ) ? array_map( 'strval', $columns ) : array() );
		return rest_ensure_response( array( 'dropped' => $dropped ) );
	}

	public function export() {
		$response = rest_ensure_response( Leiho_Collections::export() );
		$response->header( 'Content-Disposition', 'attachment; filename="leiho.schema.json"' );
		return $response;
	}

	public function import( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = (string) $request->get_body();
		}
		return $this->respond( Leiho_Collections::import( $body, (bool) $request->get_param( 'dry_run' ) ) );
	}

	/**
	 * Collection + état de sa table.
	 *
	 * @param array $collection Collection normalisée.
	 * @return array
	 */
	private function item( $collection ) {
		global $wpdb;
		$state    = Leiho_Collections_Store::collection_state( $collection['id'] );
		$archived = array();
		foreach ( $state ? $state['archived'] : array() as $col ) {
			$archived[] = array(
				'column'      => $col['column'],
				'key'         => $col['key'],
				'name'        => isset( $col['field']['name'] ) ? $col['field']['name'] : '',
				'type'        => isset( $col['field']['type'] ) ? $col['field']['type'] : '',
				'reason'      => $col['reason'],
				'archived_at' => $col['archived_at'],
			);
		}
		$columns = array();
		foreach ( $state ? $state['columns'] : array() as $key => $col ) {
			$columns[ $key ] = array(
				'column' => $col['column'],
				'sql'    => $col['sql'],
			);
		}
		return array(
			'collection' => $collection,
			'table'      => $state ? $wpdb->prefix . $state['table'] : null,
			'columns'    => (object) $columns,
			'archived'   => $archived,
			'entry'      => 'singleton' === $collection['kind'] ? Leiho_Collections::entry( $collection['id'] ) : null,
			// Collection importée d'ACF : état de l'import, retour possible.
			'acf_import' => class_exists( 'Leiho_ACF_Import' ) ? Leiho_ACF_Import::summary( $collection['id'] ) : null,
		);
	}

	/**
	 * @param array|WP_Error $report Rapport de migration.
	 * @param int            $status Statut HTTP en cas de succès.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $report, $status = 200 ) {
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		// Objet JSON même vide : {} et non [].
		$report['converted'] = (object) $report['converted'];
		$response            = rest_ensure_response( $report );
		$response->set_status( empty( $report['dry_run'] ) ? $status : 200 );
		return $response;
	}
}
