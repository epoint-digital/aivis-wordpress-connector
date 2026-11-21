<?php
declare( strict_types=1 );

use AivisOS\Admin\SettingsPage;
use AivisOS\Admin\SiteHealth;
use AivisOS\Admin\StatusPage;
use AivisOS\Api\Client;
use AivisOS\Api\Response;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Cli\Commands;
use AivisOS\Delivery\Language;
use AivisOS\Domain\Action;
use AivisOS\Domain\UrlKey;
use AivisOS\Plugin;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Decision;
use AivisOS\Sync\Synchronizer;
use PHPUnit\Framework\TestCase;

/**
 * §06a — which pipeline's JSON-LD the connector delivers. Contract 1.11.0 adds
 * the Universal Pipeline (v2) documents beside v1: the same envelope and
 * errors on two more routes, and nothing about v2 in the inventory or the
 * change feed. TC numbers are the test cases in the specification.
 */
final class PipelineTest extends TestCase {

	private const A = 'https://example.com/a/';
	private const B = 'https://example.com/b/';
	/** Response headers of an instance that offers v2. */
	private const H = [ 'x-aivis-api-version' => '1.11.0', 'x-aivis-min-client' => '1.0.0' ];

	private Options $o;

	protected function setUp(): void {
		WPStub::reset();
		WP_CLI::reset();
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$this->o = new Options();
		$this->o->set_business( 'biz_live', 'Example', 'https://example.com', [] );
		$this->o->set_chain_languages( [ 'chain_de' => 'de' ] );
	}

	private function sync(): Synchronizer {
		return new Synchronizer( new Client( $this->o ), new Repository(), $this->o, new AdapterFactory( $this->o ) );
	}

	private static function list( array $items ): array {
		return [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => count( $items ) ];
	}

	private static function chain(): array {
		return [ 'id' => 'chain_de', 'name' => 'chain_de', 'state' => 'ready', 'currentStep' => 9, 'knowledgeGraphReady' => true, 'urlCount' => 1, 'languageCode' => 'de', 'urlLanguageCodes' => [ 'de' ], 'declaredLanguageCodes' => [ 'de' ] ];
	}

	/** An inventory row; `jsonLd` describes the v1 document only. */
	private static function row( string $id, string $url, array $jsonld = [] ): array {
		return [ 'id' => $id, 'url' => $url, 'chainId' => 'chain_de', 'businessId' => 'biz_live', 'languageCode' => 'de', 'layer' => 'editorial', 'captureStatus' => 'processed', 'jsonLd' => $jsonld + [ 'ready' => true, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'suppressedAt' => null ] ];
	}

	private const NO_V1 = [ 'ready' => false, 'stale' => false, 'generatedAt' => null ];

	private static function envelope( string $id, string $url, string $generated = '2026-11-20T09:00:00Z' ): array {
		return [ 'urlId' => $id, 'chainId' => 'chain_de', 'businessId' => 'biz_live', 'url' => $url, 'languageCode' => 'de', 'stale' => false, 'generatedAt' => $generated, 'jsonLd' => [ '@type' => 'Thing' ] ];
	}

	private static function local( string $url, array $over = [] ): array {
		return $over + [ 'id' => 7, 'url_key' => UrlKey::of( $url ), 'source_url' => $url, 'url_id' => 'u1', 'chain_id' => 'chain_de', 'business_id' => 'biz_live', 'active' => 1, 'retired_at' => null, 'suspended_at' => null, 'last_error_code' => null, 'source_generated_at' => '2026-09-01 00:00:00', 'source_stale' => 0, 'missing_complete_runs' => 0, 'content_hash' => str_repeat( 'a', 64 ), 'published_at' => '2026-09-01 00:00:00', 'pipeline' => 'v1', 'source_etag' => null ];
	}

	private static function err( string $code ): array {
		return [ 'error' => [ 'code' => $code, 'message' => 'Human text, may change' ] ];
	}

	/** @return list<string> request paths, without the API prefix */
	private static function paths(): array {
		return array_map( static fn( $l ) => str_replace( '/api/public/v1', '', (string) parse_url( $l[0], PHP_URL_PATH ) ) . ( parse_url( $l[0], PHP_URL_QUERY ) ? '?' . parse_url( $l[0], PHP_URL_QUERY ) : '' ), WPStub::$http_log );
	}

	private static function codes(): array {
		return array_column( (array) ( WPStub::$options['aivis_os_diagnostics'] ?? [] ), 'code' );
	}

	/** Rows written with a document, as [ pipeline, source_etag ]. */
	private static function stored(): array {
		$out = [];
		foreach ( $GLOBALS['wpdb']->writes as [ , $data ] ) {
			if ( isset( $data['json_ld'] ) ) {
				$out[] = [ $data['pipeline'] ?? null, $data['source_etag'] ?? null ];
			}
		}
		return $out;
	}

	/** True when any write took a block out of service. */
	private static function took_down(): bool {
		foreach ( $GLOBALS['wpdb']->writes as [ , $data ] ) {
			if ( array_key_exists( 'active', $data ) && 0 === (int) $data['active'] ) {
				return true;
			}
		}
		return (bool) preg_grep( '/SET active = 0/', $GLOBALS['wpdb']->queries );
	}

	/* ── the setting ──────────────────────────────────────────────────── */

	/** TC-01 */
	public function test_v1_is_the_default_and_only_known_pipelines_are_accepted(): void {
		self::assertSame( 'v1', $this->o->pipeline() );
		self::assertSame( [ 'v1', 'v2' ], Options::PIPELINES );
		self::assertFalse( $this->o->set_pipeline( 'v3' ), 'an unknown pipeline is refused' );
		self::assertSame( 'v1', $this->o->pipeline() );
		self::assertTrue( $this->o->set_pipeline( 'v2' ), 'a change says so' );
		self::assertFalse( $this->o->set_pipeline( 'v2' ), 'the same value is not a change' );
		self::assertSame( 'v2', $this->o->pipeline() );
		WPStub::$options['aivis_os_pipeline'] = 'garbage';
		self::assertSame( 'v1', $this->o->pipeline(), 'a stored value nobody knows reads as the default' );
	}

	/** TC-02 */
	public function test_the_client_asks_the_v2_routes_and_sends_if_none_match_only_when_given(): void {
		$c = new Client( $this->o );
		WPStub::queue( 200, self::envelope( 'u1', self::A ) );
		WPStub::queue( 200, self::envelope( 'u1', self::A ) );
		WPStub::queue( 200, self::envelope( 'u1', self::A ) );
		WPStub::queue( 200, self::envelope( 'u1', self::A ) );
		$c->jsonld_by_id( 'u1' );
		$c->jsonld_by_id( 'u1', 'v2' );
		$c->jsonld_by_url( self::A, 'v2' );
		$c->jsonld_by_id( 'u1', 'v2', '"abc"' );
		self::assertSame( [ '/urls/u1/jsonld', '/urls/u1/jsonld/v2', '/jsonld/v2?url=https%3A%2F%2Fexample.com%2Fa%2F', '/urls/u1/jsonld/v2' ], self::paths() );
		self::assertArrayNotHasKey( 'If-None-Match', WPStub::$http_log[1][1]['headers'] );
		self::assertSame( '"abc"', WPStub::$http_log[3][1]['headers']['If-None-Match'] );
		self::assertArrayHasKey( 'Authorization', WPStub::$http_log[3][1]['headers'], 'the extra header replaces nothing' );
	}

	/** TC-03 */
	public function test_a_304_is_not_modified_and_never_a_reason_to_change_anything(): void {
		$r = new Response( 304, null, '', [ 'etag' => '"abc"' ] );
		self::assertSame( 'not_modified', $r->kind() );
		self::assertFalse( $r->ok() );
		self::assertSame( Action::HOLD, Decision::from_lookup( $r, true )['action'] );
	}

	/** TC-04 */
	public function test_with_v2_the_inventory_row_decides_presence_and_unpublish_never_readiness(): void {
		$no_v1 = [ 'captureStatus' => 'processed', 'jsonLd' => self::NO_V1 + [ 'suppressedAt' => null ] ];
		self::assertSame( Action::DEACTIVATE, Decision::from_inventory( true, $no_v1, 0 )['action'], 'v1: no document, no capture running' );
		$d = Decision::from_inventory( true, $no_v1, 0, false, false );
		self::assertSame( Action::HOLD, $d['action'], 'v2: the row does not describe the v2 document' );
		self::assertSame( 'R-02b', $d['rule'] );
		$withdrawn = [ 'captureStatus' => 'processed', 'jsonLd' => self::NO_V1 + [ 'suppressedAt' => '2026-11-20T10:00:00Z' ] ];
		self::assertSame( Action::DEACTIVATE, Decision::from_inventory( true, $withdrawn, 0, false, false )['action'], 'unpublish is one decision for both pipelines' );
		self::assertSame( Action::RETIRE, Decision::from_inventory( true, null, 1, true, false )['action'], 'absence is absence' );
		self::assertSame( Action::HOLD, Decision::from_inventory( false, null, 1, true, false )['action'], 'a partial walk proves nothing' );
	}

	/* ── the sync with v2 selected ────────────────────────────────────── */

	/** TC-05 */
	public function test_v2_asks_every_page_itself_and_never_uses_the_change_feed(): void {
		$this->o->set_pipeline( 'v2' );
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ), self::row( 'u2', self::B, self::NO_V1 ) ] ), self::H );
		WPStub::queue( 200, self::envelope( 'u1', self::A ), self::H + [ 'etag' => '"e1"' ] );
		WPStub::queue( 200, self::envelope( 'u2', self::B ), self::H + [ 'etag' => '"e2"' ] );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( 'v2', $r['pipeline'] );
		self::assertSame( 'full', $r['mode'] );
		self::assertSame( 2, $r['fetched'], 'the page v1 calls not ready is asked too' );
		self::assertSame( [ '/urls/u1/jsonld/v2', '/urls/u2/jsonld/v2' ], array_slice( self::paths(), 2 ) );
		self::assertSame( [ [ 'v2', '"e1"' ], [ 'v2', '"e2"' ] ], self::stored(), 'each block is stored with its pipeline and its ETag' );
		self::assertFalse( self::took_down() );

		// Straight after a full walk v1 would read the change feed. A v2 document
		// never appears in it, so v2 walks the whole inventory again.
		WPStub::$http_log = [];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [] ), self::H );
		$r = $this->sync()->run();
		self::assertSame( 'full', $r['mode'] );
		self::assertStringNotContainsString( 'updatedSince', implode( ' ', self::paths() ) );
	}

	/** TC-06 */
	public function test_v1_stays_what_it_was(): void {
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ), self::row( 'u2', self::B, self::NO_V1 ) ] ), self::H );
		WPStub::queue( 200, self::envelope( 'u1', self::A, '2026-09-01T00:00:00Z' ), self::H + [ 'etag' => '"e1"' ] );
		$r = $this->sync()->run();
		self::assertSame( 'v1', $r['pipeline'] );
		self::assertSame( 1, $r['fetched'], 'only the row that is ready' );
		self::assertSame( [ '/urls/u1/jsonld' ], array_slice( self::paths(), 2 ) );
		self::assertSame( [ [ 'v1', '"e1"' ] ], self::stored() );
		self::assertArrayNotHasKey( 'If-None-Match', WPStub::$http_log[2][1]['headers'], 'v1 fetches only what the row says changed' );
	}

	/** TC-07 */
	public function test_a_page_without_a_v2_document_keeps_the_block_it_has(): void {
		$this->o->set_pipeline( 'v2' );
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v1', 'source_etag' => '"v1tag"' ] ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 404, self::err( 'jsonld_not_generated' ), self::H );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( '/urls/u1/jsonld/v2', self::paths()[2] );
		self::assertArrayNotHasKey( 'If-None-Match', WPStub::$http_log[2][1]['headers'], 'the stored ETag belongs to the v1 document' );
		self::assertFalse( self::took_down(), 'nothing is removed for a missing v2 document (R-01a)' );
		self::assertSame( [], self::stored() );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_NOT_GENERATED', true ) ), 'said once' );

		// The next sync finds the row already marked: no second entry.
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v1', 'last_error_code' => 'AIVIS_NOT_GENERATED' ] ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 404, self::err( 'jsonld_not_generated' ), self::H );
		$this->sync()->run();
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_NOT_GENERATED', true ) ), 'and not again every quarter hour' );
	}

	/** TC-08 */
	public function test_a_page_nobody_has_a_v2_document_for_is_asked_quietly(): void {
		$this->o->set_pipeline( 'v2' );
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 404, self::err( 'jsonld_not_generated' ), self::H );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertNotContains( 'AIVIS_NOT_GENERATED', self::codes(), 'a page with nothing stored and nothing to store is not a problem to report' );
		self::assertSame( [], $GLOBALS['wpdb']->writes );
	}

	/** TC-09 */
	public function test_an_unchanged_v2_document_costs_a_304_and_changes_nothing(): void {
		$this->o->set_pipeline( 'v2' );
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v2', 'source_etag' => '"e1"', 'source_generated_at' => '2026-11-20 09:00:00' ] ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 304, '', self::H + [ 'etag' => '"e1"' ] );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( '"e1"', WPStub::$http_log[2][1]['headers']['If-None-Match'] );
		self::assertSame( [], self::stored(), 'no document is written' );
		self::assertFalse( self::took_down() );
		self::assertNotContains( 'AIVIS_HTTP_ERROR', self::codes(), 'a 304 is not an error' );
		self::assertNotContains( 'AIVIS_NOT_GENERATED', self::codes() );
		$seen = array_filter( $GLOBALS['wpdb']->writes, static fn( array $w ): bool => ( $w[1]['last_seen_sync_id'] ?? '' ) === $r['sync_id'] );
		self::assertNotEmpty( $seen, 'the page counts as seen in this run' );
		self::assertSame( 0, $r['pending'] );
	}

	/** TC-10 */
	public function test_unpublish_takes_the_block_down_under_v2_as_under_v1(): void {
		$this->o->set_pipeline( 'v2' );
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v2' ] ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A, self::NO_V1 + [ 'suppressedAt' => '2026-11-20T10:00:00Z' ] ) ] ), self::H );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertCount( 2, self::paths(), 'an unpublished page is not asked for a document' );
		self::assertTrue( self::took_down(), 'R-02a' );

		// And the 410 of the v2 route, for a page the list did not flag (R-01b).
		$GLOBALS['wpdb']->writes  = [];
		$GLOBALS['wpdb']->queries = [];
		$this->o->patch_sync_state( [ 'force_full' => true ] );
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 410, self::err( 'withdrawn' ), self::H );
		$this->sync()->run();
		self::assertTrue( self::took_down(), 'R-01b' );
	}

	/** TC-17 */
	public function test_an_unpublish_does_not_wait_behind_the_queue_of_pages_to_ask(): void {
		$this->o->set_pipeline( 'v2' );
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v2' ] ) ];
		$rows = [ self::row( 'u1', self::A, self::NO_V1 + [ 'suppressedAt' => '2026-11-20T10:00:00Z' ] ) ];
		for ( $i = 2; $i <= 26; $i++ ) {
			$rows[] = self::row( 'u' . $i, 'https://example.com/p' . $i . '/' );
		}
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( $rows ), self::H );
		for ( $i = 0; $i < Synchronizer::MAX_ARTIFACTS_PER_JOB; $i++ ) {
			WPStub::queue( 404, self::err( 'jsonld_not_generated' ), self::H );
		}
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertGreaterThan( 0, $r['pending'], 'the round is not finished: more pages than one tick asks' );
		self::assertTrue( self::took_down(), 'yet the unpublished page is already down (R-03)' );
		self::assertContains( 'AIVIS_RETRACTED', self::codes() );
	}

	/** TC-11 */
	public function test_an_instance_without_v2_is_not_asked_and_keeps_what_is_served(): void {
		$this->o->set_pipeline( 'v2' );
		$old = [ 'x-aivis-api-version' => '1.10.0', 'x-aivis-min-client' => '1.0.0' ];
		$GLOBALS['wpdb']->rows = [ self::local( self::A ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), $old );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), $old );
		$r = $this->sync()->run();
		self::assertFalse( $this->o->pipeline_available( 'v2' ) );
		self::assertCount( 2, self::paths(), 'no request to a route the instance does not have' );
		self::assertSame( 0, $r['fetched'] );
		self::assertFalse( self::took_down(), 'what is served stays served' );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_PIPELINE_UNAVAILABLE', true ) ) );
		self::assertSame( 'critical', ( new SiteHealth( Plugin::instance() ) )->test_pipeline()['status'] );

		$this->o->patch_sync_state( [ 'force_full' => true ] );
		WPStub::queue( 200, self::list( [ self::chain() ] ), $old );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), $old );
		$this->sync()->run();
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_PIPELINE_UNAVAILABLE', true ) ), 'recorded once, not every sync' );
	}

	/** TC-12 */
	public function test_back_on_v1_a_block_from_v2_is_fetched_again_even_when_the_dates_agree(): void {
		// Stored from v2, with the very timestamp the v1 row carries.
		$GLOBALS['wpdb']->rows = [ self::local( self::A, [ 'pipeline' => 'v2', 'source_etag' => '"e2"', 'source_generated_at' => '2026-09-01 00:00:00' ] ) ];
		WPStub::queue( 200, self::list( [ self::chain() ] ), self::H );
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A ) ] ), self::H );
		WPStub::queue( 200, self::envelope( 'u1', self::A, '2026-09-01T00:00:00Z' ), self::H + [ 'etag' => '"e1"' ] );
		$r = $this->sync()->run();
		self::assertSame( 1, $r['fetched'] );
		self::assertSame( '/urls/u1/jsonld', self::paths()[2] );
		self::assertArrayNotHasKey( 'If-None-Match', WPStub::$http_log[2][1]['headers'], 'the v2 ETag is never offered to the v1 route' );
		self::assertSame( [ [ 'v1', '"e1"' ] ], self::stored() );
	}

	/** TC-13 */
	public function test_a_single_page_recheck_uses_the_selected_pipeline(): void {
		$this->o->set_pipeline( 'v2' );
		// v1 says "no document": with v1 selected that would be the answer.
		WPStub::queue( 200, self::list( [ self::row( 'u1', self::A, self::NO_V1 ) ] ), self::H );
		WPStub::queue( 200, self::envelope( 'u1', self::A ), self::H + [ 'etag' => '"e1"' ] );
		$out = $this->sync()->refresh_url( self::A );
		self::assertSame( 'serve', $out['action'] ?? null, json_encode( $out ) );
		self::assertSame( '/urls/u1/jsonld/v2', self::paths()[1] );
		self::assertSame( [ [ 'v2', '"e1"' ] ], self::stored() );
	}

	/* ── choosing it ──────────────────────────────────────────────────── */

	/** TC-14 */
	public function test_settings_offer_the_choice_and_a_change_asks_for_a_full_sync(): void {
		$page = new SettingsPage( Plugin::instance() );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'name="pipeline"', $html );
		self::assertMatchesRegularExpression( '#<option value="v1"\s+selected#', $html );
		self::assertStringContainsString( '<option value="v2"', $html );

		$post = [ 'injection' => '1', 'interval' => '900', 'cache_adapter' => 'auto' ];
		self::assertSame( 'saved', $page->save( $post ) );
		self::assertSame( 'v1', $this->o->pipeline(), 'a form without the field changes nothing' );
		self::assertNotContains( 'AIVIS_PIPELINE_SWITCHED', self::codes() );

		self::assertSame( 'saved', $page->save( $post + [ 'pipeline' => 'v2' ] ) );
		self::assertSame( 'v2', $this->o->pipeline() );
		self::assertTrue( ! empty( $this->o->sync_state()['force_full'] ), 'the next sync walks everything' );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_PIPELINE_SWITCHED', true ) ) );
		self::assertFalse( self::took_down(), 'switching removes nothing by itself' );

		self::assertSame( 'saved', $page->save( $post + [ 'pipeline' => 'v2' ] ) );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_PIPELINE_SWITCHED', true ) ), 'saving the same choice is not a switch' );
		self::assertSame( 'saved', $page->save( $post + [ 'pipeline' => 'v9' ] ) );
		self::assertSame( 'v2', $this->o->pipeline(), 'an unknown value is ignored' );
	}

	/** TC-15 */
	public function test_the_cli_shows_and_switches_the_pipeline(): void {
		$c = new Commands( Plugin::instance() );
		$c->pipeline( [] );
		self::assertSame( 'v1', WP_CLI::messages( 'log' )[0] );
		$c->pipeline( [ 'v2' ] );
		self::assertSame( 'v2', $this->o->pipeline() );
		self::assertStringContainsString( 'v2', WP_CLI::messages( 'success' )[0] );
		try {
			$c->pipeline( [ 'v3' ] );
			self::fail( 'an unknown pipeline must stop the command' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'v1, v2', $e->getMessage() );
		}
		self::assertSame( 'v2', $this->o->pipeline() );
	}

	/** TC-16 */
	public function test_status_and_site_health_say_which_pipeline_is_delivered(): void {
		self::assertSame( 'good', ( new SiteHealth( Plugin::instance() ) )->test_pipeline()['status'] );
		$this->o->set_pipeline( 'v2' );
		$this->o->set_api_info( [ 'version' => '1.11.0', 'min_client' => '1.0.0', 'seen_at' => time() ] );
		ob_start();
		( new StatusPage( Plugin::instance() ) )->render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Universal Pipeline (V2)', $html );
		$health = ( new SiteHealth( Plugin::instance() ) )->test_pipeline();
		self::assertSame( 'recommended', $health['status'], 'a test pipeline on a live site is worth a line, not an alarm' );
		self::assertStringContainsString( 'Universal Pipeline (V2)', $health['label'] );
	}
}
