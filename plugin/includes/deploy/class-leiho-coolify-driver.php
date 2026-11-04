<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 2 : webhook Coolify (fronts hébergés sur VPS).
 * POST avec token Bearer. Pas de statut en v1.
 */
class Leiho_Coolify_Driver implements Leiho_Deploy_Driver_Interface {

	const SECRET_NAME = 'coolify_token';

	public function get_id() {
		return 'coolify';
	}

	public function supports_status() {
		return false;
	}

	public function check_config() {
		$settings = Leiho_Plugin::settings();
		if ( '' === $settings['coolify_url'] ) {
			return new WP_Error( 'leiho_deploy_config', __( 'No Coolify webhook URL set.', 'leiho' ) );
		}
		if ( 'unreadable' === Leiho_Secrets::status( self::SECRET_NAME ) ) {
			return new WP_Error(
				'leiho_secret_unreadable',
				__( 'The Coolify token can’t be read (the WordPress salts have probably changed). Enter it again in Leiho settings.', 'leiho' )
			);
		}
		if ( null === Leiho_Secrets::get( self::SECRET_NAME ) ) {
			return new WP_Error( 'leiho_deploy_config', __( 'No Coolify token set.', 'leiho' ) );
		}
		return true;
	}

	public function trigger( array $record ) {
		$settings = Leiho_Plugin::settings();
		$token    = Leiho_Secrets::get( self::SECRET_NAME );

		$response = wp_remote_post(
			$settings['coolify_url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'leiho_deploy_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'leiho_deploy_failed',
				sprintf( /* translators: %d: HTTP status code. */ __( 'Coolify responded with %d.', 'leiho' ), $code )
			);
		}

		$record['status'] = 'triggered';
		$record['eta']    = max( 1, (int) Leiho_Plugin::settings()['build_hook_eta'] );
		return $record;
	}

	public function get_status( array $record ) {
		return $record;
	}
}
