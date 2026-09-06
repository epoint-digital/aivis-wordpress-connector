<?php
/**
 * Uninstall: runs when the plugin is deleted from the admin.
 *
 * Honours `aivis_os_uninstall_retention`. By default everything is removed —
 * the table, every option, every transient, the scheduled events. If the site
 * owner chose to keep data (for a reinstall or migration) only the schedules
 * are cleared. The token is removed regardless: a deleted plugin must not
 * leave a credential behind (WP-I6).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( [ 'aivis_os_sync', 'aivis_os_gc', 'aivis_os_verify' ] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

$aivis_os_keep = (bool) get_option( 'aivis_os_uninstall_retention', false );

// The credential never survives an uninstall, whatever the retention setting.
delete_option( 'aivis_os_token' );
delete_option( 'aivis_os_token_status' );

if ( ! $aivis_os_keep ) {
	foreach ( [
		'aivis_os_business',
		'aivis_os_delivery',
		'aivis_os_status_key',
		'aivis_os_status_disabled',
		'aivis_os_sync_state',
		'aivis_os_diagnostics',
		'aivis_os_schema_version',
		'aivis_os_uninstall_retention',
	] as $aivis_os_option ) {
		delete_option( $aivis_os_option );
	}

	// Transients: the two negative caches (§05).
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE '\_transient\_aivis\_os\_%'
		     OR option_name LIKE '\_transient\_timeout\_aivis\_os\_%'"
	);

	$aivis_os_table = $wpdb->prefix . 'aivis_jsonld';
	$wpdb->query( "DROP TABLE IF EXISTS `{$aivis_os_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
