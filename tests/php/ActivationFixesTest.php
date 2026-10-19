<?php
declare( strict_types=1 );

use AivisOS\Admin\SettingsPage;
use AivisOS\Admin\SiteHealth;
use AivisOS\Plugin;
use AivisOS\Storage\Options;
use AivisOS\Sync\Scheduler;
use PHPUnit\Framework\TestCase;

/** #72 — the recurring sync must exist after activation; #73 — one matching business binds on a successful test. */
final class ActivationFixesTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_the_stub_refuses_an_unknown_recurrence_like_wordpress_does(): void {
		$r = wp_schedule_event( time(), 'aivis_os_15min', 'probe' );
		self::assertInstanceOf( WP_Error::class, $r, 'without the cron_schedules filter the custom recurrence does not exist' );
		self::assertArrayNotHasKey( 'probe', WPStub::$scheduled );
	}

	public function test_register_events_schedules_the_sync_in_a_request_that_never_booted_the_plugin(): void {
		// Activation: plugins_loaded is over, Plugin::register() never ran, no filter is in place.
		self::assertSame( [], WPStub::$filters );
		Scheduler::register_events();
		self::assertSame( 'aivis_os_15min', WPStub::$scheduled['aivis_os_sync'][1] ?? null, 'the sync is scheduled with the plugin\'s own recurrence' );
		self::assertSame( 'daily', WPStub::$scheduled['aivis_os_gc'][1] );
	}

	public function test_ensure_recreates_a_missing_event_only_while_an_interval_is_set(): void {
		$o = new Options();
		$o->set_sync_interval( 900 );
		self::assertFalse( wp_next_scheduled( 'aivis_os_sync' ), 'an install activated before the fix has no event' );
		Scheduler::ensure();
		self::assertSame( 'aivis_os_15min', WPStub::$scheduled['aivis_os_sync'][1] );
		$ts = WPStub::$scheduled['aivis_os_sync'][0];
		Scheduler::ensure();
		self::assertSame( $ts, WPStub::$scheduled['aivis_os_sync'][0], 'an existing event is left alone' );
		unset( WPStub::$scheduled['aivis_os_sync'] );
		$o->set_sync_interval( 0 );
		Scheduler::ensure();
		self::assertArrayNotHasKey( 'aivis_os_sync', WPStub::$scheduled, 'manual-only stays manual' );
	}

	public function test_site_health_flags_an_interval_without_an_event(): void {
		( new Options() )->set_sync_interval( 900 );
		$r = ( new SiteHealth( Plugin::instance() ) )->test_cron();
		self::assertSame( 'critical', $r['status'] );
		self::assertStringContainsString( 'not scheduled', $r['label'] );
		Scheduler::reschedule_sync( 900 );
		self::assertNotSame( 'critical', ( new SiteHealth( Plugin::instance() ) )->test_cron()['status'] );
	}

	public function test_auto_bind_binds_the_single_business_on_this_domain_and_requests_a_sync(): void {
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$page = static fn( array $items ): array => [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => count( $items ) ];
		$biz  = static fn( string $id, string $url ): array => [ 'id' => $id, 'name' => strtoupper( $id ), 'baseUrl' => $url, 'chainCount' => 1, 'createdAt' => '2026-10-01T00:00:00Z' ];
		$sp   = new SettingsPage( Plugin::instance() );

		WPStub::queue( 200, $page( [ $biz( 'biz_here', 'https://www.example.com' ), $biz( 'biz_other', 'https://andere.de' ) ] ) );
		self::assertSame( 'BIZ_HERE', $sp->auto_bind() );
		self::assertSame( 'biz_here', ( new Options() )->business()['business_id'] );
		self::assertNotEmpty( array_filter( array_keys( WPStub::$scheduled ), static fn( string $k ): bool => str_starts_with( $k, 'aivis_os_sync:' ) ), 'a first sync is requested' );

		WPStub::$http_log = [];
		self::assertNull( $sp->auto_bind(), 'already bound: nothing happens' );
		self::assertCount( 0, WPStub::$http_log, 'and no request is made' );

		WPStub::reset();
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		WPStub::queue( 200, $page( [ $biz( 'biz_a', 'https://example.com' ), $biz( 'biz_b', 'https://example.com' ) ] ) );
		self::assertNull( $sp->auto_bind(), 'two candidates: the admin decides' );
		self::assertSame( '', ( new Options() )->business()['business_id'] );

		WPStub::reset();
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		WPStub::queue( 200, $page( [ $biz( 'biz_elsewhere', 'https://andere.de' ) ] ) );
		self::assertNull( $sp->auto_bind(), 'no business on this domain: nothing is bound' );
	}
}
