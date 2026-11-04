<?php
defined( 'ABSPATH' ) || exit;

/**
 * Driver 4 : commit de publication GitHub.
 *
 * Pour les fronts déployés par un pipeline « au push » sans webhook exposé
 * (ex. Hostinger Web apps) : publier = créer un commit vide sur la branche
 * de déploiement via l'API Git de GitHub. Le pipeline rebuilde comme pour
 * n'importe quel push, et l'historique trace chaque publication.
 *
 * PAT fine-grained requis : repo ciblé, permission Contents (Read & write).
 */
class Leiho_Github_Commit_Driver implements Leiho_Deploy_Driver_Interface {

	const SECRET_NAME = 'gh_pat';
	const API_BASE    = 'https://api.github.com';

	public function get_id() {
		return 'github_commit';
	}

	public function supports_status() {
		return false; // Le build est côté hébergeur : on affiche l'ETA.
	}

	public function check_config() {
		$settings = Leiho_Plugin::settings();
		if ( '' === $settings['gh_repo'] || '' === $settings['gh_branch'] ) {
			return new WP_Error( 'leiho_deploy_config', __( 'Incomplete GitHub settings (repository, branch).', 'leiho' ) );
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
		$repo     = $settings['gh_repo'];
		$branch   = $settings['gh_branch'];

		// 1. SHA du HEAD de la branche.
		$ref = $this->request( 'GET', sprintf( '/repos/%s/git/ref/heads/%s', $repo, rawurlencode( $branch ) ) );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		$head_sha = isset( $ref['object']['sha'] ) ? $ref['object']['sha'] : '';
		if ( '' === $head_sha ) {
			return new WP_Error( 'leiho_deploy_failed', __( 'Branch not found on GitHub.', 'leiho' ) );
		}

		// 2. Tree du commit courant.
		$head = $this->request( 'GET', sprintf( '/repos/%s/git/commits/%s', $repo, $head_sha ) );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		$tree_sha = isset( $head['tree']['sha'] ) ? $head['tree']['sha'] : '';
		if ( '' === $tree_sha ) {
			return new WP_Error( 'leiho_deploy_failed', __( 'Can’t read the HEAD commit.', 'leiho' ) );
		}

		// 3. Commit vide (même tree) portant la trace de la publication.
		$user    = wp_get_current_user();
		$message = sprintf(
			"chore(leiho): publish content\n\nTriggered from WordPress by %s.",
			$user->display_name
		);
		$commit  = $this->request(
			'POST',
			sprintf( '/repos/%s/git/commits', $repo ),
			array(
				'message' => $message,
				'tree'    => $tree_sha,
				'parents' => array( $head_sha ),
			)
		);
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}
		if ( empty( $commit['sha'] ) ) {
			return new WP_Error( 'leiho_deploy_failed', __( 'Couldn’t create the commit.', 'leiho' ) );
		}

		// 4. Avance de la branche → le pipeline de l'hébergeur prend le relais.
		$update = $this->request(
			'PATCH',
			sprintf( '/repos/%s/git/refs/heads/%s', $repo, rawurlencode( $branch ) ),
			array( 'sha' => $commit['sha'] )
		);
		if ( is_wp_error( $update ) ) {
			return $update;
		}

		$record['status']          = 'triggered';
		$record['eta']             = max( 1, (int) $settings['build_hook_eta'] );
		$record['meta']['commit']  = $commit['sha'];
		$record['meta']['run_url'] = sprintf( 'https://github.com/%s/commit/%s', $repo, $commit['sha'] );
		return $record;
	}

	public function get_status( array $record ) {
		return $record;
	}

	/**
	 * Requête API GitHub, réponse JSON décodée ou WP_Error.
	 *
	 * @param string     $method Méthode HTTP.
	 * @param string     $path   Chemin API.
	 * @param array|null $body   Corps JSON.
	 * @return array|WP_Error
	 */
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

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'leiho_deploy_failed', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] )
				? $data['message']
				: sprintf( /* translators: %d: HTTP status code. */ __( 'GitHub responded with %d.', 'leiho' ), $code );
			return new WP_Error( 'leiho_deploy_failed', $message );
		}

		return is_array( $data ) ? $data : array();
	}
}
