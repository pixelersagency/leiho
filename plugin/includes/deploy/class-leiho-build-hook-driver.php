<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 1 : build hook générique (Vercel, Netlify, Cloudflare Pages…).
 * Un POST vide vers une URL. Pas de statut : "déclenché, en ligne dans ~N min".
 */
class Leiho_Build_Hook_Driver implements Leiho_Deploy_Driver_Interface {

	public function get_id() {
		return 'build_hook';
	}

	public function supports_status() {
		return false;
	}

	public function check_config() {
		$settings = Leiho_Plugin::settings();
		if ( '' === $settings['build_hook_url'] ) {
			return new WP_Error( 'leiho_deploy_config', __( 'No build hook URL set.', 'leiho' ) );
		}
		return true;
	}

	public function trigger( array $record ) {
		$settings = Leiho_Plugin::settings();

		$response = wp_remote_post(
			$settings['build_hook_url'],
			array(
				'timeout' => 15,
				'body'    => '{}',
				'headers' => array( 'Content-Type' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'leiho_deploy_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'leiho_deploy_failed',
				sprintf( /* translators: %d: HTTP status code. */ __( 'The build hook responded with %d.', 'leiho' ), $code )
			);
		}

		$record['status'] = 'triggered';
		$record['eta']    = max( 1, (int) $settings['build_hook_eta'] );
		return $record;
	}

	public function get_status( array $record ) {
		return $record; // Pas de statut disponible.
	}
}
