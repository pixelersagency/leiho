<?php
/**
 * Désinstallation (suppression du plugin depuis wp-admin, pas la simple
 * désactivation) : retire toutes les données de Leiho. Les champs ACF et les
 * contenus modifiés avec Leiho ne sont pas touchés : ils appartiennent au site.
 * Les valeurs des champs Leiho Collections vivent dans les tables de Leiho et
 * partent avec lui, sauf si LEIHO_KEEP_DATA est défini ; les entrées restent
 * dans wp_posts.
 *
 * @package Leiho
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Nettoie le site courant.
 */
function leiho_uninstall_site() {
	global $wpdb;

	// Tables gardées sous leur nom Okno quand la base n'a pas su les renommer
	// (suffixe => nom complet) : lu avant que les options ne soient supprimées.
	$legacy_tables = get_option( 'leiho_legacy_tables', array() );
	$legacy_tables = is_array( $legacy_tables ) ? $legacy_tables : array();
	$drop_legacy   = static function ( $suffix ) use ( $wpdb, $legacy_tables ) {
		$name = isset( $legacy_tables[ $suffix ] ) ? (string) $legacy_tables[ $suffix ] : '';
		if ( '' !== $name && preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'okno_[a-z0-9_]+$/', $name ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nom vérifié ci-dessus.
		}
	};

	foreach ( array( 'leiho_settings', 'leiho_secrets', 'leiho_deploy_history', 'leiho_usage', 'leiho_connection', 'leiho_activity_db_version', 'leiho_update_last_release', 'leiho_export_cache', 'leiho_content_cache_more', 'leiho_auto_deploy_attempts', 'leiho_auto_deploy_pending', 'leiho_deploy_lock', 'leiho_ai_brand', 'leiho_migrated_from_okno', 'leiho_migration_lock', 'leiho_legacy_tables' ) as $option ) {
		delete_option( $option );
	}

	// Transients, y compris ceux suffixés par utilisateur (leiho_gh_preflight_12).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\_transient\_leiho\_%'
		OR option_name LIKE '\_transient\_timeout\_leiho\_%'"
	);

	// Métas internes : empreinte de révision et chemin de page personnalisé.
	delete_post_meta_by_key( '_leiho_rev' );
	delete_post_meta_by_key( '_leiho_path' );

	// Journal des modifications.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}leiho_activity" );
	$drop_legacy( 'activity' );

	// Cache objet : changer de génération rend toutes les entrées illisibles,
	// elles expirent d'elles-mêmes.
	wp_cache_delete( 'generation', 'leiho' );

	// Leiho Collections : tables des collections, schéma, instantanés des
	// révisions. LEIHO_KEEP_DATA (wp-config.php) les garde pour une réinstallation.
	// OKNO_KEEP_DATA : nom d'avant le renommage en Leiho, encore respecté.
	$keep = ( defined( 'LEIHO_KEEP_DATA' ) && LEIHO_KEEP_DATA ) || ( defined( 'OKNO_KEEP_DATA' ) && OKNO_KEEP_DATA );
	if ( ! $keep ) {
		$state = get_option( 'leiho_collections_state', array() );
		foreach ( array_keys( isset( $state['collections'] ) && is_array( $state['collections'] ) ? $state['collections'] : array() ) as $id ) {
			// Même garde que Leiho_Collections_Schema::ID_PATTERN : jamais d'identifiant arbitraire en SQL.
			if ( preg_match( '/^[a-z][a-z0-9_]{0,19}$/', (string) $id ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}leiho_{$id}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiant vérifié ci-dessus.
				$drop_legacy( $id );
			}
		}
		// Traductions des entrées (une table pour toutes les langues).
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}leiho_l10n`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Nom fixe.
		delete_option( 'leiho_collections' );
		delete_option( 'leiho_collections_state' );
		delete_option( 'leiho_acf_import' );
		delete_post_meta_by_key( '_leiho_fields' );
	}

	wp_clear_scheduled_hook( 'leiho_cleanup_deploys' );
	wp_clear_scheduled_hook( 'leiho_auto_deploy' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $leiho_site_id ) {
		switch_to_blog( $leiho_site_id );
		leiho_uninstall_site();
		restore_current_blog();
	}
} else {
	leiho_uninstall_site();
}
