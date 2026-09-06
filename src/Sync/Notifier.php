<?php
/**
 * Notifications inside WordPress: email to the site admin when the set of
 * structured-data conflicts changes, and the one-off conflict scan after the
 * first complete sync.
 *
 * Nothing here talks to AIVIS. The connector never pushes anything (WP-I9);
 * AIVIS fetches the status document from Rest\StatusController when it wants
 * it (§11a).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Delivery\Conflicts;
use AivisOS\Storage\Options;

final class Notifier {

	public function __construct( private readonly Options $options ) {}

	/** The conflict set changed (and is not the acknowledged one). */
	public function conflicts_changed( array $conflicts ): void {
		if ( $this->options->notify_email() ) {
			$this->email_conflicts( $conflicts );
		}
	}

	/** An authoritative sync finished. */
	public function sync_completed( array $summary ): void {
		if ( empty( $summary['authoritative'] ) ) {
			return;
		}
		$state = $this->options->sync_state();
		if ( empty( $state['first_scan_done'] ) ) {
			// One conflict scan after the first complete sync, off the sync path.
			$this->options->patch_sync_state( [ 'first_scan_done' => true ] );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'aivis_os_scan_conflicts' );
		}
	}

	private function email_conflicts( array $conflicts ): void {
		$to = (string) get_option( 'admin_email', '' );
		if ( '' === $to ) {
			return;
		}
		$items = (array) ( $conflicts['items'] ?? [] );
		$srcs  = [];
		foreach ( $items as $f ) {
			foreach ( (array) ( $f['sources'] ?? [] ) as $s ) {
				$srcs[ $s ] = Conflicts::label( (string) $s );
			}
		}
		$lines = [
			sprintf( /* translators: 1: count, 2: site host */ __( 'AIVIS OS found other structured data on %1$d page(s) of %2$s.', 'aivis-os' ), count( $items ), $this->options->site_host() ),
			'',
			__( 'AIVIS should be the only source of JSON-LD on this site. Sources found:', 'aivis-os' ),
		];
		foreach ( $srcs as $k => $label ) {
			$lines[] = '  - ' . $label . ' — ' . Conflicts::guidance( (string) $k );
		}
		$lines[] = '';
		$lines[] = __( 'Publishing continues. The connector never changes another plugin. Review under AIVIS OS → Status: switch the other output off where indicated, or use Override there.', 'aivis-os' );
		$lines[] = admin_url( 'admin.php?page=aivis-os' );
		wp_mail(
			$to,
			sprintf( /* translators: %s: site host */ __( '[AIVIS OS] Other structured data found on %s', 'aivis-os' ), $this->options->site_host() ),
			implode( "\n", $lines )
		);
	}
}
