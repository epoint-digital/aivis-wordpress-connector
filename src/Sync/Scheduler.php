<?php
/**
 * Cron hooks and schedules (§05).
 *
 * WP-Cron is traffic-driven; INSTALL.md recommends a system cron hitting
 * wp-cron.php or WP-CLI, and the 5-minute interval requires one.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Storage\Options;

final class Scheduler {

	public const HOOK_SYNC   = 'aivis_os_sync';
	public const HOOK_GC     = 'aivis_os_gc';
	public const HOOK_VERIFY = 'aivis_os_verify';

	/** @param array<string,array{interval:int,display:string}> $schedules */
	public static function add_schedules( array $schedules ): array {
		$schedules['aivis_os_5min']  = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (AIVIS OS)', 'aivis-os' ),
		];
		$schedules['aivis_os_15min'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (AIVIS OS)', 'aivis-os' ),
		];
		return $schedules;
	}

	public static function register_events(): void {
		$options = new Options();
		self::reschedule_sync( $options->sync_interval() );
		if ( ! wp_next_scheduled( self::HOOK_GC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_GC );
		}
		if ( ! wp_next_scheduled( self::HOOK_VERIFY ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::HOOK_VERIFY );
		}
	}

	public static function clear_events(): void {
		foreach ( [ self::HOOK_SYNC, self::HOOK_GC, self::HOOK_VERIFY ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Re-arm the sync clock for a new interval; 0 = manual only. */
	public static function reschedule_sync( int $seconds ): void {
		wp_clear_scheduled_hook( self::HOOK_SYNC );
		if ( $seconds <= 0 ) {
			return;
		}
		$recurrence = match ( true ) {
			$seconds <= 300 => 'aivis_os_5min',
			$seconds <= 900 => 'aivis_os_15min',
			default         => 'hourly',
		};
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::HOOK_SYNC );
	}

	public static function next_sync(): ?int {
		$t = wp_next_scheduled( self::HOOK_SYNC );
		return $t ? (int) $t : null;
	}

	/**
	 * A run that still has work (an unfinished inventory walk, or artifacts left
	 * under the per-tick cap) continues a minute later instead of waiting a whole
	 * discovery interval per batch (#62). One continuation at a time.
	 */
	public static function request_continuation(): void {
		if ( ! wp_next_scheduled( self::HOOK_SYNC, [ 'continue' ] ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_SYNC, [ 'continue' ] );
		}
	}

	/** Kick a sync as soon as cron runs, without waiting for the interval. */
	public static function request_sync_now(): void {
		if ( ! wp_next_scheduled( self::HOOK_SYNC, [ 'now' ] ) ) {
			wp_schedule_single_event( time(), self::HOOK_SYNC, [ 'now' ] );
		}
	}
}
