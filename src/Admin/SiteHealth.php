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
		$tests['direct']['aivis_os_languages'] = [ 'label' => 'AIVIS OS: every language has a chain', 'test' => [ $this, 'test_languages' ] ];
		$tests['direct']['aivis_os_moved']     = [ 'label' => 'AIVIS OS: pages still at the address AIVIS crawled', 'test' => [ $this, 'test_moved' ] ];
		$tests['direct']['aivis_os_environment'] = [ 'label' => 'AIVIS OS: environment', 'test' => [ $this, 'test_environment' ] ];
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
		$note = \AivisOS\Cache\AdapterFactory::cloudflare_detected() ? ' Cloudflare is in front of this site and is not purged by the connector — hook aivis_connector_purge_urls for a CDN purge, or purge there after changes.' : '';
		return $this->result( 'good', 'AIVIS OS purges through ' . $a->label(), 'Purges are synchronous and confirmed.' . $note );
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

	/** §07a — one chain per language; a language without one is silently empty, so say it loudly. */
	public function test_languages(): array {
		$o = $this->plugin->options();
		if ( '' === $o->business()['business_id'] ) {
			return $this->result( 'good', 'AIVIS OS: nothing to check until a business is bound', '' );
		}
		$summary  = $this->plugin->assignment()->summary( null );
		$state    = $o->sync_state();
		$known    = array_keys( (array) ( $state['chain_summaries'] ?? [] ) );
		$map      = $o->chain_languages();
		$orphans  = array_values( array_filter( $known, static fn( string $id ): bool => ! isset( $map[ $id ] ) ) );
		$mismatch = (array) ( $state['language_mismatch'] ?? [] );
		$provider = \AivisOS\Delivery\Language::provider_label( \AivisOS\Delivery\Language::provider() );
		if ( $summary['missing'] ) {
			$names = array_map( static fn( string $c ): string => $summary['languages'][ $c ]['name'], $summary['missing'] );
			return $this->result( 'critical', sprintf( 'AIVIS OS: %s has no chain', implode( ', ', $names ) ), sprintf( 'Pages in %s get no structured data. Each chain is one language in AIVIS. Assign a chain under AIVIS OS → Settings → Languages & chains, or create a chain for that language in AIVIS. Languages come from %s.', implode( ', ', $names ), $provider ) );
		}
		if ( $mismatch ) {
			$c = array_key_first( $mismatch );
			return $this->result( 'recommended', 'AIVIS OS: a chain\'s pages are not in the language it is assigned to', sprintf( 'Chain %s is assigned to %s, but AIVIS reports %d of its pages as %s. Check the assignment under Settings, or the chain\'s language in AIVIS.', $c, (string) $mismatch[ $c ]['assigned'], (int) $mismatch[ $c ]['count'], (string) $mismatch[ $c ]['aivis'] ) );
		}
		if ( $orphans ) {
			return $this->result( 'recommended', sprintf( 'AIVIS OS: %d chain(s) not assigned to a language', count( $orphans ) ), 'Unassigned chains are not synced. If their pages belong on this site, assign them under Settings → Languages & chains.' );
		}
		$parts = [];
		foreach ( $summary['languages'] as $l ) {
			$parts[] = sprintf( '%s → %d chain%s', $l['name'], count( $l['chains'] ), 1 === count( $l['chains'] ) ? '' : 's' );
		}
		return $this->result( 'good', 'AIVIS OS: every language has a chain', implode( '; ', $parts ) . ' (' . $provider . ').' );
	}

	/** §11 — moved pages are reported, never acted on. */
	public function test_moved(): array {
		$state = $this->plugin->options()->sync_state();
		$moved = (array) ( $state['moved'] ?? [] );
		if ( empty( $state['moved_checked_at'] ) ) {
			return $this->result( 'good', 'AIVIS OS: no page has been checked for a changed address yet', 'The daily check compares each page’s current address with the URL AIVIS crawled.' );
		}
		if ( ! $moved ) {
			return $this->result( 'good', 'AIVIS OS: every page is still at the address AIVIS crawled', sprintf( '%d pages checked.', (int) ( $state['moved_checked'] ?? 0 ) ) );
		}
		return $this->result( 'recommended', sprintf( 'AIVIS OS: %d page(s) moved since AIVIS crawled them', count( $moved ) ), 'The structured data stays with the old URL; the new address receives nothing until AIVIS re-crawls. The list is on the Status screen and in the status document AIVIS fetches. Nothing to change on this side.' );
	}

	public function test_environment(): array {
		$o = $this->plugin->options();
		if ( 'constant' === $o->api_base_source() ) {
			return $this->result( 'good', 'AIVIS OS talks to a host fixed in wp-config.php', sprintf( 'AIVIS_API_BASE_URL = %s. Remove the constant to switch instances under Settings.', $o->api_base() ) );
		}
		if ( 'test' === $o->environment() ) {
			return $this->result( 'recommended', 'AIVIS OS talks to the AIVIS test instance', sprintf( 'Structured data on this site comes from %s. Switch to Production under AIVIS OS → Settings → Connection before go-live; the switch unbinds the business and stops serving what came from the test instance.', \AivisOS\Storage\Options::environment_host( 'test' ) ) );
		}
		return $this->result( 'good', 'AIVIS OS talks to AIVIS production', \AivisOS\Storage\Options::environment_host( 'production' ) . '.' );
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
