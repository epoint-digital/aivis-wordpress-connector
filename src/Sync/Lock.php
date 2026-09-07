<?php
/**
 * One sync at a time per site — an atomic claim in the options table, the
 * same mechanism WordPress core uses for its own upgrade lock
 * (WP_Upgrader::create_lock): INSERT IGNORE of a dedicated row is the claim,
 * a compare-and-swap UPDATE is the stale takeover, and an owner-checked
 * DELETE is the release. Two workers can no longer both "read unlocked, both
 * write" (#55), and a worker can never release or overwrite a newer owner.
 *
 * Lock state lives in its own option row, never inside the mutable sync
 * state, so a resumable run's cursors and the lock cannot clobber each other.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Storage\Options;

final class Lock {

	public const OPTION      = 'aivis_os_sync_lock';
	public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	/** The exact value this instance wrote — its proof of ownership. */
	private ?string $token = null;

	public function __construct( private readonly Options $options ) {}

	public function acquire( string $owner ): bool {
		global $wpdb;
		$value = self::value( $owner );
		// The claim. INSERT IGNORE either creates the row (1) or does nothing (0)
		// because another worker's row exists — atomically, in the database.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				$value
			)
		);
		if ( $inserted ) {
			$this->token = $value;
			$this->forget();
			return true;
		}
		$current = $this->current();
		if ( null === $current ) {
			// Row vanished between the insert and the read (released). Try once more.
			return $this->retry( $owner );
		}
		[ $who, $at ] = self::parse( $current );
		$age          = time() - $at;
		if ( $age < self::STALE_AFTER ) {
			return false;
		}
		// Abandoned (a fatal or a killed worker). Take it over only if it is still
		// exactly the value we saw: a compare-and-swap, so two recoverers cannot
		// both succeed.
		$swapped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$current
			)
		);
		if ( ! $swapped ) {
			return false;
		}
		$this->token = $value;
		$this->forget();
		$this->options->record( 'AIVIS_LOCK_RECOVERED', sprintf( 'sync lock held %d s by %s recovered', $age, $who ) );
		return true;
	}

	/** Extend the holder's lease. Owner-checked: a stale worker cannot refresh a newer owner's lock. */
	public function touch(): void {
		global $wpdb;
		if ( null === $this->token ) {
			return;
		}
		[ $owner ] = self::parse( $this->token );
		$fresh     = self::value( $owner );
		$ok        = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$fresh,
				self::OPTION,
				$this->token
			)
		);
		if ( $ok ) {
			$this->token = $fresh;
			$this->forget();
		}
	}

	/** Release only what this instance holds. Someone else's lock is left alone. */
	public function release(): void {
		global $wpdb;
		if ( null === $this->token ) {
			return;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $this->token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->token = null;
		$this->forget();
	}

	/** Administrative: clear whatever lock exists (deactivation, disconnect). */
	public static function force_release(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::OPTION, 'options' );
	}

	public function held(): bool {
		$current = $this->current();
		if ( null === $current ) {
			return false;
		}
		[ , $at ] = self::parse( $current );
		return ( time() - $at ) < self::STALE_AFTER;
	}

	/** @return array{owner:string, at:int}|null */
	public function holder(): ?array {
		$current = $this->current();
		if ( null === $current ) {
			return null;
		}
		[ $owner, $at ] = self::parse( $current );
		return [ 'owner' => $owner, 'at' => $at ];
	}

	/* ── internals ───────────────────────────────────────────────────── */

	private function retry( string $owner ): bool {
		global $wpdb;
		$value    = self::value( $owner );
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $inserted ) {
			$this->token = $value;
			$this->forget();
			return true;
		}
		return false;
	}

	/** Read straight from the table — the option cache must not answer this. */
	private function current(): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_string( $v ) && '' !== $v ? $v : null;
	}

	private static function value( string $owner ): string {
		return $owner . '|' . time() . '|' . bin2hex( random_bytes( 6 ) );
	}

	/** @return array{0:string,1:int} */
	private static function parse( string $value ): array {
		$parts = explode( '|', $value );
		return [ (string) ( $parts[0] ?? '?' ), (int) ( $parts[1] ?? 0 ) ];
	}

	/** Keep the object cache from serving a stale copy of the row. */
	private function forget(): void {
		wp_cache_delete( self::OPTION, 'options' );
	}
}
