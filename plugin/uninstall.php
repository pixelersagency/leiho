<?php
/**
 * Désinstallation (suppression du plugin depuis wp-admin, pas la simple
 * désactivation) : retire toutes les données d'Okno. Les champs ACF et les
 * contenus modifiés avec Okno ne sont pas touchés : ils appartiennent au site.
 * Les valeurs des champs Okno Collections vivent dans les tables d'Okno et
 * partent avec lui, sauf si OKNO_KEEP_DATA est défini ; les entrées restent
 * dans wp_posts.
 *
 * @package Okno
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Nettoie le site courant.
 */
function okno_uninstall_site() {
	global $wpdb;

	foreach ( array( 'okno_settings', 'okno_secrets', 'okno_deploy_history', 'okno_usage', 'okno_connection', 'okno_activity_db_version', 'okno_update_last_release', 'okno_export_cache', 'okno_auto_deploy_attempts', 'okno_auto_deploy_pending', 'okno_deploy_lock', 'okno_ai_brand' ) as $option ) {
		delete_option( $option );
	}

	// Transients, y compris ceux suffixés par utilisateur (okno_gh_preflight_12).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\_transient\_okno\_%'
		OR option_name LIKE '\_transient\_timeout\_okno\_%'"
	);

	// Métas internes : empreinte de révision et chemin de page personnalisé.
	delete_post_meta_by_key( '_okno_rev' );
	delete_post_meta_by_key( '_okno_path' );

	// Journal des modifications.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}okno_activity" );

	// Cache objet : changer de génération rend toutes les entrées illisibles,
	// elles expirent d'elles-mêmes.
	wp_cache_delete( 'generation', 'okno' );

	// Okno Collections : tables des collections, schéma, instantanés des
	// révisions. OKNO_KEEP_DATA (wp-config.php) les garde pour une réinstallation.
	if ( ! defined( 'OKNO_KEEP_DATA' ) || ! OKNO_KEEP_DATA ) {
		$state = get_option( 'okno_collections_state', array() );
		foreach ( array_keys( isset( $state['collections'] ) && is_array( $state['collections'] ) ? $state['collections'] : array() ) as $id ) {
			// Même garde que Okno_Collections_Schema::ID_PATTERN : jamais d'identifiant arbitraire en SQL.
			if ( preg_match( '/^[a-z][a-z0-9_]{0,19}$/', (string) $id ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}okno_{$id}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié ci-dessus.
			}
		}
		delete_option( 'okno_collections' );
		delete_option( 'okno_collections_state' );
		delete_option( 'okno_acf_import' );
		delete_post_meta_by_key( '_okno_fields' );
	}

	wp_clear_scheduled_hook( 'okno_cleanup_deploys' );
	wp_clear_scheduled_hook( 'okno_auto_deploy' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $okno_site_id ) {
		switch_to_blog( $okno_site_id );
		okno_uninstall_site();
		restore_current_blog();
	}
} else {
	okno_uninstall_site();
}
