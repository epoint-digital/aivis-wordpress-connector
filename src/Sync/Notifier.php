<?php
/**
 * After the first complete sync, schedule one conflict scan off the sync
 * path. That is all this class does now.
 *
 * It used to email the site admin when the set of structured-data conflicts
 * changed. Who gets told what is notification policy, and policy is AIVIS's
 * (WP-I12): AIVIS fetches the status document, which carries the conflicts,
 * and decides. Nothing here talks to anyone.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Storage\Options;

final class Notifier {

	public function __construct( private readonly Options $options ) {}

	/** An authoritative sync finished. */
	public function sync_completed( array $summary ): void {
		if ( empty( $summary['authoritative'] ) ) {
			return;
		}
		$state = $this->options->sync_state();
		if ( empty( $state['first_scan_done'] ) ) {
			$this->options->patch_sync_state( [ 'first_scan_done' => true ] );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'aivis_os_scan_conflicts' );
		}
	}
}
