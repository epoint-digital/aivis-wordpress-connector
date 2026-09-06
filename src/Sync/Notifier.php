<?php
/**
 * Notifications: inside WordPress (email to the site admin) and toward AIVIS
 * (the connector status report, API-9).
 *
 * WP-I9 still holds — nothing about visitors, crawlers or users ever leaves
 * the site. What the report carries is the connector's own state: version,
 * site host, sync counts, cache state, and the structured-data conflicts it
 * found on pages AIVIS already knows.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Api\Client;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Delivery\Conflicts;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;

final class Notifier {

	public const UNAVAILABLE_TTL = DAY_IN_SECONDS;

	public function __construct(
		private readonly Options $options,
		private readonly Client $client,
		private readonly Repository $repository,
		private readonly AdapterFactory $cache
	) {}

	/** The conflict set changed (and is not the acknowledged one). */
	public function conflicts_changed( array $conflicts ): void {
		if ( $this->options->notify_email() ) {
			$this->email_conflicts( $conflicts );
		}
		$this->report( 'conflicts' );
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
		$this->report( 'sync' );
	}

	/** @return array<string,mixed> */
	public function build_report( string $trigger ): array {
		$state     = $this->options->sync_state();
		$conflicts = $this->options->conflicts();
		$items     = [];
		foreach ( (array) ( $conflicts['items'] ?? [] ) as $url => $f ) {
			$items[] = [
				'url'     => (string) $url,
				'sources' => array_values( (array) ( $f['sources'] ?? [] ) ),
				'types'   => array_values( (array) ( $f['types'] ?? [] ) ),
				'blocks'  => (int) ( $f['blocks'] ?? 0 ),
			];
		}
		return [
			'connector'  => 'aivis-os',
			'version'    => AIVIS_OS_VERSION,
			'site'       => $this->options->site_host(),
			'businessId' => $this->options->business()['business_id'],
			'trigger'    => $trigger,
			'reportedAt' => gmdate( 'c' ),
			'sync'       => [
				'lastCompleteAt' => ! empty( $state['last_complete_at'] ) ? gmdate( 'c', (int) $state['last_complete_at'] ) : null,
				'authoritative'  => (bool) ( $state['last_authoritative'] ?? false ),
				'intervalSec'    => $this->options->sync_interval(),
				'counts'         => $this->repository->counts(),
			],
			'cache'      => [
				'adapter'   => $this->cache->adapter()->id(),
				'lastPurge' => $state['last_purge']['state'] ?? null,
			],
			'conflicts'  => [
				'fingerprint'  => (string) ( $conflicts['fingerprint'] ?? '' ),
				'acknowledged' => ! empty( $conflicts['fingerprint'] ) && ( $conflicts['acknowledged'] ?? null ) === $conflicts['fingerprint'],
				'pagesScanned' => (int) ( $conflicts['pages_scanned'] ?? 0 ),
				'activePlugins' => array_values( (array) ( $conflicts['plugins'] ?? [] ) ),
				'items'        => $items,
			],
		];
	}

	/** Send the status report, if enabled and the endpoint is not known to be missing. */
	public function report( string $trigger ): void {
		if ( ! $this->options->report_to_aivis() ) {
			return;
		}
		$biz = $this->options->business()['business_id'];
		if ( '' === $biz || false !== get_transient( 'aivis_os_report_unavailable' ) ) {
			return;
		}
		$r = $this->client->report_status( $biz, $this->build_report( $trigger ) );
		if ( in_array( $r->status, [ 404, 405, 501 ], true ) ) {
			// API-9 not shipped yet on this AIVIS. Try again tomorrow.
			set_transient( 'aivis_os_report_unavailable', 1, self::UNAVAILABLE_TTL );
		}
		$this->options->patch_sync_state(
			[
				'last_report' => [
					'at'      => time(),
					'status'  => $r->status,
					'trigger' => $trigger,
				],
			]
		);
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
