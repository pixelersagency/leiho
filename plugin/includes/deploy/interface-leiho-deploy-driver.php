<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interface des drivers de déploiement.
 *
 * Un enregistrement de deploy (record) est un tableau :
 * {
 *   id        : identifiant Leiho du deploy,
 *   driver    : id du driver,
 *   user_id   : déclencheur,
 *   user_name : affichage,
 *   started   : timestamp UTC,
 *   status    : pending|building|triggered|success|error,
 *   message   : texte affichable,
 *   eta       : minutes estimées (drivers sans statut),
 *   meta      : données propres au driver (run_id, run_url…),
 * }
 */
interface Leiho_Deploy_Driver_Interface {

	/** @return string Identifiant ('build_hook', 'coolify', 'github'). */
	public function get_id();

	/** @return bool Le driver sait-il rapporter un statut de build ? */
	public function supports_status();

	/**
	 * Vérifie que la configuration est complète et lisible.
	 *
	 * @return true|WP_Error
	 */
	public function check_config();

	/**
	 * Déclenche un déploiement.
	 *
	 * @param array $record Enregistrement en cours de création (modifiable).
	 * @return array|WP_Error Record complété (status, meta) ou erreur.
	 */
	public function trigger( array $record );

	/**
	 * Rafraîchit le statut d'un deploy en cours.
	 *
	 * @param array $record Enregistrement.
	 * @return array Record mis à jour.
	 */
	public function get_status( array $record );
}
