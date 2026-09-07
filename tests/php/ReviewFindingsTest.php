<?php
declare( strict_types=1 );

use AivisOS\Admin\SettingsPage;
use AivisOS\Api\Client;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Delivery\Conflicts;
use AivisOS\Delivery\Language;
use AivisOS\Delivery\Markup;
use AivisOS\Domain\Action;
use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Decision;
use AivisOS\Sync\Lock;
use AivisOS\Sync\Synchronizer;
use AivisOS\Sync\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Regressions for the independent review of 2026-09-07 (#55–#62). Each test
 * names the issue it guards.
 */
final class ReviewFindingsTest extends TestCase {

	private Options $o;

	protected function setUp(): void {
		WPStub::reset();
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$this->o = new Options();
		$this->o->set_business( 'biz', 'Example', 'https://example.com', [] );
		$this->o->set_chain_languages( [ 'de-chain' => 'de' ] );
	}

	private function sync(): Synchronizer {
		return new Synchronizer( new Client( $this->o ), new Repository(), $this->o, new AdapterFactory( $this->o ) );
	}

	private static function list( array $items ): array {
		return [ 'items' => $items, 'total' => count( $items ), 'hasMore' => false, 'nextCursor' => null ];
	}

	private static function row( string $id, string $url, bool $ready = true, bool $stale = false, ?string $gen = '2026-09-01T00:00:00Z' ): array {
		return [ 'id' => $id, 'url' => $url, 'languageCode' => 'de', 'layer' => 'editorial', 'captureStatus' => 'processed', 'jsonLd' => [ 'ready' => $ready, 'stale' => $stale, 'generatedAt' => $ready ? $gen : null ] ];
	}

	/* ── #55 ── */

	public function test_55_lock_is_an_atomic_claim_and_release_is_owner_checked(): void {
		$db = $GLOBALS['wpdb'];
		$a  = new Lock( $this->o );
		$b  = new Lock( $this->o );

		$db->query_results = [ 1 ];               // A's INSERT IGNORE creates the row
		self::assertTrue( $a->acquire( 'worker-A' ) );
		self::assertStringContainsString( 'INSERT IGNORE INTO wp_options', end( $db->queries ) );

		$db->query_results = [ 0 ];               // B's INSERT IGNORE does nothing…
		$db->vars          = [ 'worker-A|' . time() . '|abc' ]; // …because A's fresh row exists
		self::assertFalse( $b->acquire( 'worker-B' ), 'two workers cannot both acquire' );

		$db->queries = [];
		$b->release();
		self::assertSame( [], $db->queries, 'a worker that holds nothing releases nothing' );

		$db->query_results = [ 0, 1 ];            // insert fails, compare-and-swap succeeds
		$db->vars          = [ 'worker-A|' . ( time() - Lock::STALE_AFTER - 1 ) . '|abc' ];
		self::assertTrue( $b->acquire( 'worker-B' ), 'a stale lock is taken over' );
		self::assertStringContainsString( "AND option_value = 'worker-A|", end( $db->queries ), 'takeover is conditional on the exact value seen' );
		self::assertContains( 'AIVIS_LOCK_RECOVERED', array_column( $this->o->diagnostics(), 'code' ) );

		$db->query_results = [ 0, 0 ];            // insert fails, swap loses to another recoverer
		$db->vars          = [ 'worker-A|' . ( time() - Lock::STALE_AFTER - 1 ) . '|abc' ];
		self::assertFalse( ( new Lock( $this->o ) )->acquire( 'worker-C' ), 'losing the compare-and-swap means not acquired' );

		$db->queries = [];
		$b->release();
		self::assertStringContainsString( "DELETE FROM wp_options WHERE option_name = 'aivis_os_sync_lock' AND option_value = 'worker-B|", end( $db->queries ) );
	}

	/* ── #56 ── */

	public function test_56_an_empty_chain_reconciles_to_zero_and_is_authoritative(): void {
		WPStub::queue( 200, self::list( [ [ 'id' => 'de-chain', 'name' => 'DE', 'state' => 'ready' ] ] ) );
		WPStub::queue( 200, self::list( [] ) );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertTrue( $r['authoritative'], 'a fully enumerated empty chain must reconcile' );
	}

	public function test_56_a_chain_that_vanished_from_aivis_retires_its_rows_and_purges(): void {
		$GLOBALS['wpdb']->rows = [ [ 'source_url' => 'https://example.com/old/' ] ];
		WPStub::queue( 200, self::list( [ [ 'id' => 'other-chain', 'name' => 'X', 'state' => 'ready' ] ] ) );
		$r = $this->sync()->run();
		self::assertSame( 'aborted', $r['skipped'] ?? null, 'no assigned chain is left to walk' );
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		self::assertMatchesRegularExpression( "/UPDATE wp_aivis_jsonld SET active = 0, retired_at = '[^']+', last_error_code = 'AIVIS_RETRACTED' WHERE retired_at IS NULL AND chain_id IN \('de-chain'\)/", $sql );
		self::assertSame( [], $this->o->chain_languages(), 'the assignment to the vanished chain is dropped' );
		self::assertSame( 'https://example.com/old/', $this->o->sync_state()['last_purge']['url'] ?? 'https://example.com/old/' );
		self::assertSame( 1, $this->o->sync_state()['last_purge']['count'] ?? 0, 'its page was purged' );
	}

	/* ── #57 ── */

	public function test_57_a_known_language_without_a_chain_rejects_every_artifact(): void {
		WPStub::$filter_values['aivis_connector_site_languages'] = static fn( array $l ): array => $l + [ 'en' => [ 'code' => 'en', 'name' => 'English', 'home' => 'https://example.com/en/' ] ];
		WPStub::queue( 200, [ 'urlId' => 'u', 'chainId' => 'de-chain', 'businessId' => 'biz', 'url' => 'https://example.com/en/about/', 'languageCode' => 'de', 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ] );
		$r = $this->sync()->refresh_url( 'https://example.com/en/about/' );
		self::assertSame( 'reject', $r['action'] );
		self::assertSame( 'AIVIS_LANGUAGE_UNASSIGNED', $r['code'] );
		self::assertNotFalse( get_transient( 'aivis_os_miss_' . \AivisOS\Domain\UrlKey::of( 'https://example.com/en/about/' ) ), 'remembered as a miss' );
		self::assertSame( [], $GLOBALS['wpdb']->writes, 'nothing stored' );
		// The German page still accepts its own chain.
		WPStub::queue( 200, [ 'urlId' => 'u2', 'chainId' => 'de-chain', 'businessId' => 'biz', 'url' => 'https://example.com/ueber/', 'languageCode' => 'de', 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ] );
		self::assertSame( 'serve', $this->sync()->refresh_url( 'https://example.com/ueber/' )['action'] );
	}

	/* ── #58 ── */

	public function test_58_a_row_without_an_artifact_never_displaces_a_servable_one(): void {
		$m       = new ReflectionMethod( Synchronizer::class, 'wins' );
		$ready   = self::row( 'a', 'https://example.com/p/', true, true, '2026-09-01T00:00:00Z' );
		$missing = self::row( 'b', 'https://example.com/p/', false );
		self::assertFalse( $m->invoke( null, $missing, $ready ), 'no-artifact row must not beat a stale but servable artifact' );
		self::assertTrue( $m->invoke( null, $ready, $missing ) );
		// Among servable rows the page's own language wins, then the API tie-break.
		$fresh = self::row( 'c', 'https://example.com/p/', true, false, '2026-09-05T00:00:00Z' );
		self::assertTrue( $m->invoke( null, $fresh, $ready ), 'non-stale and newer wins on equal language' );
		self::assertFalse( $m->invoke( null, $fresh, $ready, false, true ), 'the chain assigned to the page language beats a fresher foreign one' );
	}

	public function test_58_end_to_end_the_servable_duplicate_is_the_target(): void {
		$this->o->set_chain_languages( [ 'de-chain' => 'de', 'de-edit' => 'de' ] );
		WPStub::queue( 200, self::list( [ [ 'id' => 'de-chain', 'name' => 'A', 'state' => 'ready' ], [ 'id' => 'de-edit', 'name' => 'B', 'state' => 'ready' ] ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'a', 'https://example.com/p/', true, true ) ] ) );   // stale but servable
		WPStub::queue( 200, self::list( [ self::row( 'b', 'https://example.com/p/', false ) ] ) );        // no artifact
		WPStub::queue( 200, [ 'urlId' => 'a', 'chainId' => 'de-chain', 'businessId' => 'biz', 'url' => 'https://example.com/p/', 'languageCode' => 'de', 'stale' => true, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ] );
		$r = $this->sync()->run();
		self::assertSame( 1, $r['fetched'], 'the servable row is fetched' );
		self::assertStringContainsString( '/urls/a/jsonld', WPStub::$http_log[3][0] );
	}

	/* ── #59 ── */

	public function test_59_commented_out_or_non_200_pages_are_not_live_and_tls_is_verified(): void {
		$json = '{"@type":"Thing"}';
		$GLOBALS['wpdb']->rows = [ [ 'url_key' => 'k', 'source_url' => 'https://example.com/', 'json_ld' => $json, 'content_hash' => hash( 'sha256', $json ), 'active' => 1, 'retired_at' => null, 'suspended_at' => null ] ];
		WPStub::queue( 200, '<html><head><!-- <script type="application/ld+json" data-aivis="1">' . $json . '</script> --></head></html>', [ 'content-type' => 'text/html' ] );
		$v = ( new Verifier( new Repository(), $this->o ) )->run();
		self::assertSame( 'marker-missing', $v['result'] );
		self::assertSame( [], $GLOBALS['wpdb']->writes, 'nothing recorded as verified' );
		self::assertTrue( WPStub::$http_log[0][1]['sslverify'], 'certificates are verified by default' );

		WPStub::queue( 503, '<html><head><script type="application/ld+json" data-aivis="1">' . $json . '</script></head></html>', [ 'content-type' => 'text/html' ] );
		self::assertSame( 'could-not-verify', ( new Verifier( new Repository(), $this->o ) )->run()['result'] );

		// Substrings in the wrong places do not count; the real element does.
		self::assertNull( Markup::aivis_block( '<p>data-aivis="1"</p><script>var x = \'' . $json . '\'</script>' ) );
		self::assertSame( $json, Markup::aivis_block( '<script type="application/ld+json" data-aivis="1">' . $json . '</script>' ) );
		self::assertSame( 0, Conflicts::scan_html( '<!-- <script type="application/ld+json">{"@type":"Organization"}</script> -->' )['blocks'], 'the conflict scan applies the same rule' );
	}

	/* ── #60 ── */

	public function test_60_status_page_of_500_asks_for_the_lookahead_row(): void {
		( new StatusDocument( $this->o, new Repository(), new AdapterFactory( $this->o ) ) )->urls( 0, 500 );
		self::assertStringContainsString( 'LIMIT 501', end( $GLOBALS['wpdb']->queries ) );
	}

	/* ── #61 ── */

	public function test_61_switching_injection_purges_the_affected_pages(): void {
		$GLOBALS['wpdb']->rows = [ [ 'source_url' => 'https://example.com/a/' ], [ 'source_url' => 'https://example.com/b/' ] ];
		self::assertTrue( $this->o->injection_enabled() );
		( new SettingsPage( Plugin::instance() ) )->save( [ 'injection' => '', 'interval' => '900', 'cache_adapter' => 'auto' ] );
		self::assertFalse( $this->o->injection_enabled() );
		$purge = $this->o->sync_state()['last_purge'] ?? null;
		self::assertNotNull( $purge, 'the switch purged' );
		self::assertSame( 2, $purge['count'] );
		// Saving again without a change does not purge again.
		$this->o->patch_sync_state( [ 'last_purge' => null ] );
		( new SettingsPage( Plugin::instance() ) )->save( [ 'injection' => '', 'interval' => '900', 'cache_adapter' => 'auto' ] );
		self::assertNull( $this->o->sync_state()['last_purge'] );
	}

	public function test_61_deactivation_purges_everything_and_says_how_it_went(): void {
		Plugin::deactivate();
		$purge = $this->o->sync_state()['last_purge'];
		self::assertSame( 'deactivation', $purge['reason'] );
		self::assertNotEmpty( $purge['state'], 'the adapter reported honestly (manual here)' );
		self::assertStringContainsString( "DELETE FROM wp_options WHERE option_name = 'aivis_os_sync_lock'", implode( "\n", $GLOBALS['wpdb']->queries ) );
	}

	/* ── #62 ── */

	public function test_62_first_authoritative_absence_suspends_on_an_idle_chain_and_holds_on_a_busy_one(): void {
		self::assertSame( Action::SUSPEND, Decision::from_inventory( true, null, 0, true )['action'] );
		self::assertSame( Action::HOLD, Decision::from_inventory( true, null, 0, false )['action'] );
		self::assertSame( Action::RETIRE, Decision::from_inventory( true, null, 1, true )['action'] );
		self::assertSame( Action::RETIRE, Decision::from_inventory( true, null, 1, false )['action'] );
		self::assertSame( Action::HOLD, Decision::from_inventory( false, null, 0, true )['action'], 'never on a partial walk' );
	}

	public function test_62_a_withdrawn_page_stops_being_served_after_one_authoritative_pass(): void {
		$local = [ 'id' => 1, 'url_key' => 'k', 'source_url' => 'https://example.com/gone/', 'chain_id' => 'de-chain', 'active' => 1, 'suspended_at' => null, 'retired_at' => null, 'missing_complete_runs' => 1, 'last_error_code' => null, 'content_hash' => 'h', 'source_generated_at' => null, 'source_stale' => 0 ];
		$GLOBALS['wpdb']->rows = [ $local ];
		WPStub::queue( 200, self::list( [ [ 'id' => 'de-chain', 'name' => 'DE', 'state' => 'ready' ] ] ) );
		WPStub::queue( 200, self::list( [] ) );
		$r = $this->sync()->run();
		self::assertTrue( $r['authoritative'] );
		$suspends = array_filter( $GLOBALS['wpdb']->writes, static fn( array $w ): bool => 'update' === $w[0] && isset( $w[1]['suspended_at'] ) && null !== $w[1]['suspended_at'] );
		self::assertCount( 1, $suspends, 'suspended on the first absence' );
		self::assertSame( 1, $this->o->sync_state()['last_purge']['count'], 'and purged' );
	}

	public function test_62_a_backlog_schedules_a_continuation_instead_of_waiting_an_interval(): void {
		WPStub::$filter_values['aivis_connector_artifacts_per_job'] = 1;
		WPStub::queue( 200, self::list( [ [ 'id' => 'de-chain', 'name' => 'DE', 'state' => 'ready' ] ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'a', 'https://example.com/a/' ), self::row( 'b', 'https://example.com/b/' ) ] ) );
		WPStub::queue( 200, [ 'urlId' => 'a', 'chainId' => 'de-chain', 'businessId' => 'biz', 'url' => 'https://example.com/a/', 'languageCode' => 'de', 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ] );
		$r = $this->sync()->run();
		self::assertSame( 1, $r['fetched'] );
		self::assertSame( 1, $r['pending'] );
		$key = 'aivis_os_sync:' . md5( serialize( [ 'continue' ] ) );
		self::assertArrayHasKey( $key, WPStub::$scheduled, 'a continuation tick is scheduled' );
		self::assertLessThanOrEqual( time() + 60, WPStub::$scheduled[ $key ][0] );
	}
}
