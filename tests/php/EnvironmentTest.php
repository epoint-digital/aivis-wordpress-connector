<?php
declare( strict_types=1 );

use AivisOS\Admin\SettingsPage;
use AivisOS\Admin\SiteHealth;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Cli\Commands;
use AivisOS\Delivery\Language;
use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use PHPUnit\Framework\TestCase;

/** Test vs production instance switch (#68). */
final class EnvironmentTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		WP_CLI::reset();
		Language::$force_provider = Language::CORE;
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
	}

	public function test_production_is_the_default_and_only_the_two_instances_are_selectable(): void {
		$o = new Options();
		self::assertSame( 'production', $o->environment() );
		self::assertSame( 'https://app.aivis-os.com', $o->api_base() );
		self::assertSame( 'setting', $o->api_base_source() );
		$o->set_environment( 'test' );
		self::assertSame( 'https://aivis-new.dev.onepoint.ro', $o->api_base() );
		$o->set_environment( 'https://evil.example' );
		self::assertSame( 'test', $o->environment(), 'anything outside the list is ignored' );
		WPStub::$options['aivis_os_environment'] = 'staging';
		self::assertSame( 'production', $o->environment(), 'an unknown stored value falls back to production' );
		self::assertSame( 'aivis-new.dev.onepoint.ro', Options::environment_host( 'test' ) );
	}

	public function test_switching_lets_go_of_everything_from_the_other_instance(): void {
		$o = new Options();
		$o->set_business( 'biz_test', 'Test business', 'https://example.com', [] );
		$o->set_chain_languages( [ 'chain_t' => 'de' ] );
		$o->set_token_status( true, 'wp', 'a@b.c' );
		set_transient( 'aivis_os_businesses', [ [ 'id' => 'biz_test' ] ], 300 );
		$o->patch_sync_state( [ 'in_progress' => 'sync-1', 'pending' => [ 'k1' ], 'chain_summaries' => [ 'chain_t' => [] ], 'moved' => [ 'k' => [] ] ] );
		$GLOBALS['wpdb']->rows = [ [ 'source_url' => 'https://example.com/a/' ], [ 'source_url' => 'https://example.com/b/' ] ];

		$msg = ( new SettingsPage( Plugin::instance() ) )->save( [ 'environment' => 'test', 'business_id' => 'biz_test', 'injection' => '1', 'interval' => '900', 'cache_adapter' => 'auto' ] );

		self::assertSame( 'environment_switched', $msg );
		self::assertSame( 'test', $o->environment() );
		self::assertSame( '', $o->business()['business_id'], 'the binding is gone — and the posted business was not re-bound' );
		self::assertSame( [], $o->chain_languages() );
		self::assertNull( $o->token_status()['valid'] );
		self::assertFalse( get_transient( 'aivis_os_businesses' ) );
		$state = $o->sync_state();
		self::assertSame( '', $state['in_progress'] );
		self::assertSame( [], $state['pending'] );
		self::assertSame( [], $state['moved'] );
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( "SET active = 0, last_error_code = 'AIVIS_ENVIRONMENT_SWITCHED' WHERE active = 1 AND retired_at IS NULL", $sql, 'every row stops serving' );
		self::assertStringNotContainsString( 'chain_id NOT IN', $sql, 'no chain filter: all of them' );
		self::assertSame( 2, $state['last_purge']['count'], 'their caches are purged' );
		$last = end( WPStub::$options['aivis_os_diagnostics'] );
		self::assertSame( 'AIVIS_ENVIRONMENT_SWITCHED', $last['code'] );
		self::assertStringContainsString( 'from production to test (aivis-new.dev.onepoint.ro)', $last['message'] );
	}

	public function test_saving_the_same_environment_changes_nothing(): void {
		$o = new Options();
		$o->set_business( 'biz', 'B', 'https://example.com', [] );
		$msg = ( new SettingsPage( Plugin::instance() ) )->save( [ 'environment' => 'production', 'injection' => '1', 'interval' => '900', 'cache_adapter' => 'auto' ] );
		self::assertSame( 'saved', $msg );
		self::assertSame( 'biz', $o->business()['business_id'] );
		self::assertSame( [], $GLOBALS['wpdb']->queries );
	}

	public function test_site_health_and_status_document_name_the_instance(): void {
		$o = new Options();
		$h = new SiteHealth( Plugin::instance() );
		self::assertSame( 'good', $h->test_environment()['status'] );
		$o->set_environment( 'test' );
		$r = $h->test_environment();
		self::assertSame( 'recommended', $r['status'] );
		self::assertStringContainsString( 'test instance', $r['label'] );
		$d = ( new StatusDocument( $o, new Repository(), new AdapterFactory( $o ) ) )->build();
		self::assertSame( [ 'environment' => 'test', 'base' => 'https://aivis-new.dev.onepoint.ro', 'source' => 'setting' ], $d['api'] );
	}

	public function test_cli_environment_shows_and_switches(): void {
		$c = new Commands( Plugin::instance() );
		$c->environment( [] );
		self::assertStringContainsString( 'production — https://app.aivis-os.com', WP_CLI::messages( 'log' )[0] );
		try {
			$c->environment( [ 'staging' ] );
			self::fail( 'unknown environment must be refused' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'production, test', $e->getMessage() );
		}
		( new Options() )->set_business( 'biz', 'B', 'https://example.com', [] );
		$c->environment( [ 'test' ] );
		self::assertSame( 'test', ( new Options() )->environment() );
		self::assertSame( '', ( new Options() )->business()['business_id'] );
		self::assertStringContainsString( 'Switched to test', WP_CLI::messages( 'success' )[0] );
		$c->environment( [ 'test' ] );
		self::assertStringContainsString( 'Already on test', end( WP_CLI::$log )[1] );
	}
}
