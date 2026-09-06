<?php
/**
 * Site Health tests (§11).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;
use AivisOS\Storage\Schema;

final class SiteHealth {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'tests' ] );
	}

	/** @param array<string,array<string,mixed>> $tests */
	public function tests( array $tests ): array {
		$tests['direct']['aivis_os_token']  = [ 'label' => 'AIVIS OS: token custody', 'test' => [ $this, 'test_token' ] ];
		$tests['direct']['aivis_os_cron']   = [ 'label' => 'AIVIS OS: sync is running', 'test' => [ $this, 'test_cron' ] ];
		$tests['direct']['aivis_os_cache']  = [ 'label' => 'AIVIS OS: cache purge is confirmable', 'test' => [ $this, 'test_cache' ] ];
		$tests['direct']['aivis_os_schema'] = [ 'label' => 'AIVIS OS: storage', 'test' => [ $this, 'test_schema' ] ];
		$tests['direct']['aivis_os_conflicts'] = [ 'label' => 'AIVIS OS: single source of structured data', 'test' => [ $this, 'test_conflicts' ] ];
		return $tests;
	}

	public function test_token(): array {
		$src = $this->plugin->options()->token_source();
		return $this->result(
			'constant' === $src ? 'good' : ( 'option' === $src ? 'recommended' : 'critical' ),
			match ( $src ) {
				'constant' => 'The AIVIS token is held in wp-config.php',
				'option'   => 'The AIVIS token is stored in the database',
				default    => 'AIVIS OS has no API token',
			},
			match ( $src ) {
				'constant' => 'Held outside the database, so it does not travel in backups, staging clones or migrations.',
				'option'   => 'Define AIVIS_API_TOKEN in wp-config.php instead. An AIVIS token is account-scoped — it reads every business on the account — so where copies of it end up matters.',
				default    => 'Add a token under AIVIS OS → Settings. Nothing is injected until a business is bound.',
			}
		);
	}

	public function test_cron(): array {
		$o     = $this->plugin->options();
		$state = $o->sync_state();
		$last  = (int) ( $state['last_complete_at'] ?? 0 );
		$iv    = $o->sync_interval();
		if ( 0 === $iv ) {
			return $this->result( 'recommended', 'AIVIS OS syncs manually only', 'A page unpublished in AIVIS keeps being served until you sync. Set an interval, or sync from a system cron / WP-CLI.' );
		}
		if ( ! $last ) {
			return $this->result( 'recommended', 'AIVIS OS has not completed a sync yet', 'Run one from the Status screen, or wait for the next cron tick.' );
		}
		$age = time() - $last;
		if ( $age > 3 * $iv + 5 * MINUTE_IN_SECONDS ) {
			return $this->result( 'critical', 'AIVIS OS syncs are overdue', sprintf( 'The last complete sync was %s ago against a %d-minute interval. WordPress cron runs only when the site gets traffic — point a system cron at wp-cron.php.', human_time_diff( $last ), (int) ( $iv / 60 ) ) );
		}
		return $this->result( 'good', 'AIVIS OS is syncing on schedule', sprintf( 'Last complete sync %s ago.', human_time_diff( $last ) ) );
	}

	public function test_cache(): array {
		$a = $this->plugin->cache()->adapter();
		if ( 'manual' === $a->id() ) {
			return $this->result( 'recommended', 'AIVIS OS cannot confirm cache purges', 'No supported page cache was detected. Changed structured data is written locally, but the plugin will not report pages as live until you purge. Supported: WP Super Cache, W3 Total Cache, LiteSpeed Cache.' );
		}
		if ( 'litespeed' === $a->id() ) {
			return $this->result( 'good', 'AIVIS OS purges through LiteSpeed Cache', 'LiteSpeed accepts purge requests without confirming them, so pages are reported as “purge requested” rather than confirmed live.' );
		}
		return $this->result( 'good', 'AIVIS OS purges through ' . $a->label(), 'Purges are synchronous and confirmed.' );
	}

	public function test_conflicts(): array {
		$o = $this->plugin->options();
		$c = $o->conflicts();
		if ( empty( $c['items'] ) ) {
			return $this->result( 'good', 'AIVIS is the only source of structured data', $c['scanned_at'] ? sprintf( '%d pages scanned, no other JSON-LD found.', $c['pages_scanned'] ) : 'No scan yet — run one from AIVIS OS → Settings.' );
		}
		$srcs = [];
		foreach ( $c['items'] as $f ) {
			foreach ( (array) ( $f['sources'] ?? [] ) as $s ) {
				$srcs[ $s ] = \AivisOS\Delivery\Conflicts::label( (string) $s );
			}
		}
		$desc = sprintf( 'Other JSON-LD on %d of %d scanned pages, from %s. Two Organization or WebSite nodes on one page give search engines conflicting answers. Switch the other output off in that plugin — AIVIS OS → Settings says where.', count( $c['items'] ), $c['pages_scanned'], implode( ', ', $srcs ) );
		return $o->conflicts_unacknowledged()
			? $this->result( 'critical', 'Other plugins also emit structured data', $desc )
			: $this->result( 'recommended', 'Other structured data present (overridden by an administrator)', $desc );
	}

	public function test_schema(): array {
		$ok = ( new Schema() )->exists();
		return $this->result( $ok ? 'good' : 'critical', $ok ? 'AIVIS OS storage table is present' : 'AIVIS OS storage table is missing', $ok ? '' : 'Deactivate and reactivate the plugin to recreate it.' );
	}

	/** @return array<string,mixed> */
	private function result( string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [ 'label' => 'AIVIS OS', 'color' => 'good' === $status ? 'green' : ( 'critical' === $status ? 'red' : 'orange' ) ],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'aivis_os',
		];
	}
}
