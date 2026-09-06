<?php
/**
 * Table creation and upgrades for {prefix}aivis_jsonld.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Storage;

final class Schema {

	/** Bump when the table definition changes; dbDelta reconciles the rest. */
	public const VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aivis_jsonld';
	}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// Column list per SPECIFICATION §05, plus two the retraction rules in §06
		// need but the data-model table omitted: `suspended_at` (R-01 suspends
		// before it retires) and `last_error_code` (the status screen shows why
		// a row is holding or suspended).
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_key char(64) NOT NULL,
			source_url text NOT NULL,
			url_id varchar(191) NOT NULL,
			chain_id varchar(191) NOT NULL,
			business_id varchar(191) NOT NULL,
			language_code varchar(16) NOT NULL DEFAULT '',
			json_ld longtext NOT NULL,
			content_hash char(64) NOT NULL,
			source_generated_at datetime NULL,
			source_stale tinyint(1) unsigned NOT NULL DEFAULT 0,
			active tinyint(1) unsigned NOT NULL DEFAULT 1,
			local_post_id bigint(20) unsigned NULL,
			last_seen_sync_id char(36) NOT NULL DEFAULT '',
			missing_complete_runs smallint(5) unsigned NOT NULL DEFAULT 0,
			last_synced_at datetime NULL,
			suspended_at datetime NULL,
			retired_at datetime NULL,
			last_error_code varchar(32) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_key (url_key),
			KEY business_active (business_id,active),
			KEY url_id (url_id),
			KEY last_seen_sync_id (last_seen_sync_id),
			KEY retired_at (retired_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'aivis_os_schema_version', self::VERSION, false );
	}

	public function maybe_upgrade(): void {
		if ( (string) get_option( 'aivis_os_schema_version', '' ) !== self::VERSION ) {
			$this->install();
		}
	}

	public function exists(): bool {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
