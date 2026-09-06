<?php
/**
 * One sync at a time per site, with abandoned-lock recovery after 15 minutes.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Storage\Options;

final class Lock {

	public const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	public function __construct( private readonly Options $options ) {}

	public function acquire( string $owner ): bool {
		$s    = $this->options->sync_state();
		$lock = $s['lock'] ?? null;
		if ( is_array( $lock ) && ! empty( $lock['at'] ) ) {
			$age = time() - (int) $lock['at'];
			if ( $age < self::STALE_AFTER ) {
				return false;
			}
			// Abandoned (a fatal or a killed worker). Recover and say so.
			$this->options->record( 'AIVIS_LOCK_RECOVERED', sprintf( 'sync lock held %d s by %s recovered', $age, (string) ( $lock['owner'] ?? '?' ) ) );
		}
		$this->options->patch_sync_state(
			[
				'lock' => [
					'owner' => $owner,
					'at'    => time(),
				],
			]
		);
		return true;
	}

	public function touch(): void {
		$s = $this->options->sync_state();
		if ( isset( $s['lock'] ) && is_array( $s['lock'] ) ) {
			$s['lock']['at'] = time();
			$this->options->patch_sync_state( [ 'lock' => $s['lock'] ] );
		}
	}

	public function release(): void {
		$this->options->patch_sync_state( [ 'lock' => null ] );
	}

	public function held(): bool {
		$lock = $this->options->sync_state()['lock'] ?? null;
		return is_array( $lock ) && ! empty( $lock['at'] ) && ( time() - (int) $lock['at'] ) < self::STALE_AFTER;
	}
}
