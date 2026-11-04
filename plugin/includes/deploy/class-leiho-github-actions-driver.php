<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 3 : GitHub Actions via workflow_dispatch (fine-grained PAT).
 * Supporte le polling du statut du run pour affichage temps réel.
 */
class Leiho_Github_Actions_Driver implements Leiho_Deploy_Driver_Interface {

	const SECRET_NAME = 'gh_pat';
	const API_BASE    = 'https://api.github.com';

	public function get_id() {
		return 'github';
	}

	public function supports_status() {
		return true;
	}

	public function check_config() {
		$settings = Leiho_Plugin::settings();
		if ( '' === $settings['gh_repo'] || '' === $settings['gh_workflow'] || '' === $settings['gh_branch'] ) {
			return new WP_Error( 'leiho_deploy_config', __( 'Incomplete GitHub settings (repository, workflow, branch).', 'leiho' ) );
		}
		if ( 'unreadable' === Leiho_Secrets::status( self::SECRET_NAME ) ) {
			return new WP_Error(
				'leiho_secret_unreadable',
				__( 'The GitHub PAT can’t be read (the WordPress salts have probably changed). Enter it again in Leiho settings.', 'leiho' )
			);
		}
		if ( null === Leiho_Secrets::get( self::SECRET_NAME ) ) {
			return new WP_Error( 'leiho_deploy_config', __( 'No GitHub PAT set.', 'leiho' ) );
		}
		return true;
	}

	public function trigger( array $record ) {
		$settings = Leiho_Plugin::settings();

		// B2 : borne haute des runs existants AVANT le dispatch. Le run déclenché
		// est forcément d'id supérieur — l'ancienne heuristique « premier run créé
		// après started - 120 s » suivait le mauvais run quand deux publications
		// se succédaient.
		$record['meta']['since_run_id'] = $this->latest_run_id( $settings );

		$response = $this->request(
			'POST',
			sprintf(
				'/repos/%s/actions/workflows/%s/dispatches',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] )
			),
			array( 'ref' => $settings['gh_branch'] )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 204 !== $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$raw  = isset( $body['message'] ) ? $body['message'] : sprintf( /* translators: %d: HTTP status code. */ __( 'GitHub responded with %d.', 'leiho' ), $code );

			// Le « Not Found » brut de GitHub n'aide personne : on dit quoi vérifier.
			switch ( $code ) {
				case 404:
					$message = sprintf(
						/* translators: 1: workflow file, 2: repository (owner/name). */ __( 'GitHub couldn’t find the workflow “%1$s” in %2$s (or the PAT has no access to this repository). Open Leiho settings and save them to run the configuration check.', 'leiho' ),
						$settings['gh_workflow'],
						$settings['gh_repo']
					);
					break;
				case 401:
					$message = __( 'GitHub rejected the PAT: the token is invalid, revoked or expired. Enter it again in Leiho settings.', 'leiho' );
					break;
				case 403:
					$message = sprintf( /* translators: %s: error message from GitHub. */ __( 'GitHub refused the request (403): the PAT probably lacks the “Actions: write” permission. Details: %s', 'leiho' ), $raw );
					break;
				case 422:
					$message = sprintf( /* translators: 1: branch name, 2: error message from GitHub. */ __( 'GitHub rejected the trigger (422): the branch “%1$s” doesn’t exist, or the workflow doesn’t accept workflow_dispatch. Details: %2$s', 'leiho' ), $settings['gh_branch'], $raw );
					break;
				default:
					$message = $raw;
			}

			return new WP_Error( 'leiho_deploy_failed', $message );
		}

		$record['status'] = 'pending';
		return $record;
	}

	public function get_status( array $record ) {
		$settings = Leiho_Plugin::settings();

		if ( empty( $record['meta']['run_id'] ) ) {
			$run = $this->find_run( $settings, $record );
			if ( ! $run ) {
				return $record; // Run pas encore visible côté GitHub, ou API injoignable.
			}
			$record['meta']['run_id']  = $run['id'];
			$record['meta']['run_url'] = $run['html_url'];
		}

		$response = $this->request(
			'GET',
			sprintf( '/repos/%s/actions/runs/%d', $settings['gh_repo'], $record['meta']['run_id'] )
		);
		if ( is_wp_error( $response ) ) {
			return $record;
		}

		$run = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $run['status'] ) ) {
			return $record;
		}

		if ( 'completed' === $run['status'] ) {
			$record['status']  = ( 'success' === $run['conclusion'] ) ? 'success' : 'error';
			$record['message'] = ( 'success' === $run['conclusion'] )
				? __( 'Deployment complete.', 'leiho' )
				: sprintf( /* translators: %s: GitHub run conclusion. */ __( 'The workflow failed (%s).', 'leiho' ), (string) $run['conclusion'] );
		} else {
			$record['status'] = 'building'; // queued | in_progress.
		}

		return $record;
	}

	/**
	 * Id du run le plus récent du workflow, tous événements confondus.
	 *
	 * @param array $settings Réglages.
	 * @return int 0 si inconnu (API injoignable, aucun run).
	 */
	private function latest_run_id( $settings ) {
		$response = $this->request(
			'GET',
			sprintf(
				'/repos/%s/actions/workflows/%s/runs?per_page=1',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] )
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 0;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$runs = isset( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();
		return $runs ? (int) $runs[0]['id'] : 0;
	}

	/**
	 * Retrouve le run déclenché par ce record : le plus ancien run
	 * workflow_dispatch d'id supérieur à la borne prise au déclenchement.
	 *
	 * @param array $settings Réglages.
	 * @param array $record   Record du deploy.
	 * @return array|null
	 */
	private function find_run( $settings, array $record ) {
		$response = $this->request(
			'GET',
			sprintf(
				'/repos/%s/actions/workflows/%s/runs?branch=%s&event=workflow_dispatch&per_page=20',
				$settings['gh_repo'],
				rawurlencode( $settings['gh_workflow'] ),
				rawurlencode( $settings['gh_branch'] )
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$runs  = isset( $body['workflow_runs'] ) ? $body['workflow_runs'] : array();
		$since = isset( $record['meta']['since_run_id'] ) ? (int) $record['meta']['since_run_id'] : 0;

		$match = null;
		foreach ( $runs as $run ) {
			if ( $since > 0 ) {
				if ( (int) $run['id'] <= $since ) {
					continue;
				}
			} elseif ( strtotime( $run['created_at'] ) < $record['started'] - 120 ) {
				// Record d'avant B2 (ou borne inconnue) : on retombe sur la date.
				continue;
			}
			// La liste est triée du plus récent au plus ancien : le dernier
			// candidat retenu est le premier run postérieur au déclenchement.
			$match = $run;
		}

		return $match;
	}

	private function request( $method, $path, $body = null ) {
		$pat = Leiho_Secrets::get( self::SECRET_NAME );
		if ( null === $pat ) {
			return new WP_Error( 'leiho_secret_unreadable', __( 'GitHub PAT missing or unreadable.', 'leiho' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $pat,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'Leiho/' . LEIHO_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		return wp_remote_request( self::API_BASE . $path, $args );
	}
}
