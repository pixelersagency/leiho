<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contrôle préalable de la configuration GitHub (B1).
 *
 * check_config() ne vérifie que la présence des réglages : un dépôt inexistant,
 * un workflow absent ou un PAT expiré ne se découvrent qu'au premier déploiement
 * raté, sous la forme d'un « Not Found » brut. Ce contrôle interroge l'API au
 * moment de l'enregistrement des réglages et rend des messages actionnables.
 */
class Leiho_Github_Preflight {

	const SECRET_NAME = 'gh_pat';
	const API_BASE    = 'https://api.github.com';
	const TRANSIENT   = 'leiho_gh_preflight';

	/**
	 * Vérifie PAT, dépôt, branche et (optionnellement) workflow.
	 *
	 * @param string $repo     owner/nom.
	 * @param string $branch   Branche de déploiement.
	 * @param string $workflow Fichier workflow, '' pour ne pas le vérifier.
	 * @return array<int,array{level:string,message:string}>
	 */
	public static function check( $repo, $branch, $workflow = '' ) {
		$results = array();

		if ( 'unreadable' === Leiho_Secrets::status( self::SECRET_NAME ) ) {
			return array( self::line( 'error', __( 'The GitHub PAT can’t be read (the WordPress salts have changed). Enter it again.', 'leiho' ) ) );
		}
		$pat = Leiho_Secrets::get( self::SECRET_NAME );
		if ( null === $pat ) {
			return array( self::line( 'error', __( 'No GitHub PAT set.', 'leiho' ) ) );
		}
		if ( '' === $repo || '' === $branch ) {
			return array( self::line( 'error', __( 'Repository and branch are required.', 'leiho' ) ) );
		}

		// 1. Dépôt : valide le PAT et l'accès en une requête.
		$repo_res = self::request( $pat, sprintf( '/repos/%s', $repo ) );
		if ( is_wp_error( $repo_res ) ) {
			return array( self::line( 'error', sprintf( /* translators: %s: error message. */ __( 'Can’t reach GitHub: %s', 'leiho' ), $repo_res->get_error_message() ) ) );
		}

		$code = wp_remote_retrieve_response_code( $repo_res );
		if ( 401 === $code ) {
			return array( self::line( 'error', __( 'GitHub rejected the PAT (401): the token is invalid, revoked or expired.', 'leiho' ) ) );
		}
		if ( 404 === $code ) {
			return array(
				self::line(
					'error',
					sprintf( /* translators: %s: repository (owner/name). */ __( 'Repository “%s” not found: it doesn’t exist, or the PAT has no access to it (a fine-grained PAT must list this repository explicitly).', 'leiho' ), $repo )
				),
			);
		}
		if ( 200 !== $code ) {
			return array( self::line( 'error', sprintf( /* translators: %d: HTTP status code. */ __( 'GitHub responded with %d for the repository.', 'leiho' ), $code ) ) );
		}

		$results[] = self::line( 'ok', sprintf( /* translators: %s: repository (owner/name). */ __( 'Repository “%s” is accessible.', 'leiho' ), $repo ) );

		$expiry = self::expiry_line( $repo_res );
		if ( $expiry ) {
			$results[] = $expiry;
		}

		// 2. Branche.
		$branch_res = self::request( $pat, sprintf( '/repos/%s/branches/%s', $repo, rawurlencode( $branch ) ) );
		if ( ! is_wp_error( $branch_res ) && 200 === wp_remote_retrieve_response_code( $branch_res ) ) {
			$results[] = self::line( 'ok', sprintf( /* translators: %s: branch name. */ __( 'Branch “%s” found.', 'leiho' ), $branch ) );
		} else {
			$results[] = self::line( 'error', sprintf( /* translators: %s: branch name. */ __( 'Branch “%s” not found in this repository.', 'leiho' ), $branch ) );
		}

		// 3. Workflow (driver github uniquement).
		if ( '' !== $workflow ) {
			$results[] = self::check_workflow( $pat, $repo, $workflow );
		}

		return $results;
	}

	/**
	 * Lance le contrôle et mémorise le résultat pour l'afficher après redirection.
	 *
	 * @return void
	 */
	public static function run_and_store( $repo, $branch, $workflow = '' ) {
		set_transient( self::TRANSIENT . '_' . get_current_user_id(), self::check( $repo, $branch, $workflow ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Résultat mémorisé (consommé une seule fois).
	 *
	 * @return array<int,array{level:string,message:string}>
	 */
	public static function consume() {
		$key    = self::TRANSIENT . '_' . get_current_user_id();
		$stored = get_transient( $key );
		delete_transient( $key );
		return is_array( $stored ) ? $stored : array();
	}

	private static function check_workflow( $pat, $repo, $workflow ) {
		$res = self::request( $pat, sprintf( '/repos/%s/actions/workflows?per_page=100', $repo ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return self::line( 'warning', __( 'Can’t list the repository’s workflows (missing Actions permission?).', 'leiho' ) );
		}

		$body      = json_decode( wp_remote_retrieve_body( $res ), true );
		$workflows = isset( $body['workflows'] ) && is_array( $body['workflows'] ) ? $body['workflows'] : array();

		$names = array();
		foreach ( $workflows as $item ) {
			$file = isset( $item['path'] ) ? basename( $item['path'] ) : '';
			if ( '' === $file ) {
				continue;
			}
			$names[] = $file;
			if ( $file === $workflow || ( isset( $item['name'] ) && $item['name'] === $workflow ) ) {
				if ( isset( $item['state'] ) && 'active' !== $item['state'] ) {
					return self::line( 'warning', sprintf( /* translators: 1: workflow file, 2: workflow state. */ __( 'Workflow “%1$s” found but disabled (state: %2$s).', 'leiho' ), $workflow, $item['state'] ) );
				}
				return self::line( 'ok', sprintf( __( 'Workflow “%s” found and active.', 'leiho' ), $workflow ) );
			}
		}

		if ( empty( $names ) ) {
			return self::line( 'error', sprintf( /* translators: %s: workflow file. */ __( 'This repository has no GitHub Actions workflows: “%s” can never be triggered.', 'leiho' ), $workflow ) );
		}

		return self::line(
			'error',
			sprintf(
				/* translators: 1: workflow file, 2: comma-separated list of workflows. */ __( 'Workflow “%1$s” not found. Available workflows: %2$s.', 'leiho' ),
				$workflow,
				implode( ', ', $names )
			)
		);
	}

	/**
	 * Date d'expiration du PAT, exposée par GitHub sur les tokens fine-grained.
	 */
	private static function expiry_line( $response ) {
		$header = wp_remote_retrieve_header( $response, 'github-authentication-token-expiration' );
		if ( ! $header ) {
			return null;
		}
		$timestamp = strtotime( $header );
		if ( ! $timestamp ) {
			return null;
		}

		$date = date_i18n( get_option( 'date_format' ), $timestamp );
		$days = (int) floor( ( $timestamp - time() ) / DAY_IN_SECONDS );

		if ( $days < 0 ) {
			return self::line( 'error', sprintf( /* translators: %s: date. */ __( 'The PAT expired on %s.', 'leiho' ), $date ) );
		}
		if ( $days <= 14 ) {
			return self::line( 'warning', sprintf( /* translators: 1: date, 2: number of days. */ _n( 'The PAT expires on %1$s (in %2$d day).', 'The PAT expires on %1$s (in %2$d days).', $days, 'leiho' ), $date, $days ) );
		}
		return self::line( 'ok', sprintf( /* translators: %s: date. */ __( 'PAT valid until %s.', 'leiho' ), $date ) );
	}

	private static function line( $level, $message ) {
		return array(
			'level'   => $level,
			'message' => $message,
		);
	}

	private static function request( $pat, $path ) {
		return wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization'        => 'Bearer ' . $pat,
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'Leiho/' . LEIHO_VERSION,
				),
			)
		);
	}
}
