<?php
declare( strict_types=1 );

use AivisOS\Admin\Menu;
use AivisOS\Admin\SettingsPage;
use AivisOS\Admin\SiteHealth;
use AivisOS\Api\Client;
use AivisOS\Api\Response;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Cli\Commands;
use AivisOS\Delivery\Language;
use AivisOS\Domain\UrlKey;
use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Synchronizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The connector against AIVIS Public API contract 1.9.0 (#20, #54, #69):
 * error codes, version headers and 426, 410 withdrawn, rate-limit headroom,
 * business pinning on inventory rows, the change feed, the per-URL refresh,
 * business-bound tokens, and a connection test that says what failed where.
 */
final class ContractV19Test extends TestCase {

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

	private static function chain( string $id, ?string $lang = 'de' ): array {
		return [ 'id' => $id, 'name' => $id, 'state' => 'ready', 'currentStep' => 9, 'knowledgeGraphReady' => true, 'urlCount' => 1, 'languageCode' => $lang, 'urlLanguageCodes' => $lang ? [ $lang ] : [], 'declaredLanguageCodes' => $lang ? [ $lang ] : [] ];
	}

	private static function row( string $id, string $url, array $over = [] ): array {
		return $over + [ 'id' => $id, 'url' => $url, 'chainId' => 'chain_de', 'businessId' => 'biz_live', 'languageCode' => 'de', 'layer' => 'editorial', 'captureStatus' => 'processed', 'jsonLd' => [ 'ready' => true, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'suppressedAt' => null ] ];
	}

	private static function envelope( string $id, string $url ): array {
		return [ 'urlId' => $id, 'chainId' => 'chain_de', 'businessId' => 'biz_live', 'url' => $url, 'languageCode' => 'de', 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ];
	}

	private static function local( string $url, array $over = [] ): array {
		return $over + [ 'url_key' => UrlKey::of( $url ), 'source_url' => $url, 'url_id' => 'u1', 'chain_id' => 'chain_de', 'business_id' => 'biz_live', 'active' => 1, 'retired_at' => null, 'suspended_at' => null, 'last_error_code' => null, 'source_generated_at' => '2026-08-01 00:00:00', 'source_stale' => 0, 'missing_complete_runs' => 0, 'content_hash' => str_repeat( 'a', 64 ) ];
	}

	private static function err( string $code, string $message = 'Human text, may change' ): array {
		return [ 'error' => [ 'code' => $code, 'message' => $message ] ];
	}

	private static function paths(): array {
		return array_map( fn( $l ) => parse_url( $l[0], PHP_URL_PATH ) . ( parse_url( $l[0], PHP_URL_QUERY ) ? '?' . parse_url( $l[0], PHP_URL_QUERY ) : '' ), WPStub::$http_log );
	}

	private static function codes(): array {
		return array_column( (array) ( WPStub::$options['aivis_os_diagnostics'] ?? [] ), 'code' );
	}

	/* ── Response: error.code decides (API-3) ─────────────────────────── */

	public static function classifications(): array {
		return [
			'200'                         => [ 200, null, null, 'ok' ],
			'404 url_not_found'           => [ 404, 'url_not_found', 'Reworded.', 'url_gone' ],
			'404 jsonld_not_generated'    => [ 404, 'jsonld_not_generated', 'Reworded.', 'not_generated' ],
			'404 unknown code'            => [ 404, 'business_not_found', null, 'unreadable' ],
			'404 code beats legacy text'  => [ 404, 'jsonld_not_generated', Response::NOT_FOUND, 'not_generated' ],
			'404 legacy text only'        => [ 404, null, Response::NOT_FOUND, 'url_gone' ],
			'404 legacy other text'       => [ 404, null, 'Something else', 'unreadable' ],
			'410 withdrawn'               => [ 410, 'withdrawn', null, 'withdrawn' ],
			'410 without the code'        => [ 410, 'not_found', null, 'unreadable' ],
			'426 client_too_old'          => [ 426, 'client_too_old', null, 'client_too_old' ],
			'426 no body'                 => [ 426, null, null, 'client_too_old' ],
			'429 rate_limited'            => [ 429, 'rate_limited', null, 'throttled' ],
			'401 missing_token'           => [ 401, 'missing_token', null, 'auth' ],
			'403 account_deactivated'     => [ 403, 'account_deactivated', null, 'account' ],
			'400 bad_request'             => [ 400, 'bad_request', null, 'bad_request' ],
			'500 internal_error'          => [ 500, 'internal_error', null, 'transport' ],
			'0 network'                   => [ 0, null, null, 'transport' ],
		];
	}

	#[DataProvider( 'classifications' )]
	public function test_classification_branches_on_error_code( int $status, ?string $code, ?string $message, string $kind ): void {
		$body = null;
		if ( null !== $code || null !== $message ) {
			$body = [ 'error' => array_filter( [ 'code' => $code, 'message' => $message ], static fn( $v ) => null !== $v ) ];
		}
		$r = new Response( $status, $body );
		self::assertSame( $kind, $r->kind() );
		self::assertSame( (string) $code, $r->code() );
	}

	public function test_response_exposes_the_contract_headers(): void {
		$r = new Response( 200, [], '', [ 'x-aivis-api-version' => '1.9.0', 'x-aivis-min-client' => '1.0.0', 'etag' => '"abc"', 'x-ratelimit-remaining' => '7', 'x-ratelimit-reset' => '1788949731', 'retry-after' => '12', 'deprecation' => '@1788825600' ] );
		self::assertSame( '1.9.0', $r->api_version() );
		self::assertSame( '1.0.0', $r->min_client() );
		self::assertSame( '"abc"', $r->etag() );
		self::assertSame( 7, $r->rate_remaining() );
		self::assertSame( 1788949731, $r->rate_reset() );
		self::assertSame( 12, $r->retry_after() );
		self::assertTrue( $r->deprecated() );
		$bare = new Response( 200, [] );
		self::assertNull( $bare->rate_remaining() );
		self::assertSame( '', $bare->api_version() );
		self::assertFalse( $bare->deprecated() );
	}

	/* ── Client: headers noted, 426 recorded once, new query parameters ── */

	public function test_client_notes_the_contract_and_records_a_426_once(): void {
		$c = new Client( $this->o );
		WPStub::queue( 200, [ 'tokenName' => 't' ], [ 'x-aivis-api-version' => '1.9.0', 'x-aivis-min-client' => '1.0.0', 'etag' => '"e"' ] );
		$r = $c->me();
		self::assertSame( '1.9.0', $r->api_version() );
		self::assertSame( '"e"', $r->etag() );
		self::assertSame( '1.9.0', $this->o->api_info()['version'] );
		self::assertSame( '1.0.0', $this->o->api_info()['min_client'] );
		self::assertFalse( $this->o->client_too_old() );

		WPStub::queue( 426, self::err( 'client_too_old' ), [ 'x-aivis-api-version' => '1.9.0', 'x-aivis-min-client' => '2.0.0', 'upgrade' => 'aivis-os/2.0.0' ] );
		$r = $c->me();
		self::assertSame( 'client_too_old', $r->kind() );
		self::assertTrue( $this->o->api_info()['too_old'] );
		self::assertSame( '2.0.0', $this->o->api_info()['min_client'] );
		self::assertTrue( $this->o->client_too_old() );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_CLIENT_TOO_OLD', true ) ) );
		self::assertStringContainsString( 'minimum client 2.0.0', end( WPStub::$options['aivis_os_diagnostics'] )['message'] );

		WPStub::queue( 426, self::err( 'client_too_old' ), [ 'x-aivis-min-client' => '2.0.0' ] );
		$c->me();
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_CLIENT_TOO_OLD', true ) ), 'recorded once per hour, not per request' );

		// After a plugin update the version comparison wins over the stale flag.
		$this->o->set_api_info( [ 'min_client' => '1.0.0' ] );
		self::assertFalse( $this->o->client_too_old() );
		self::assertTrue( $this->o->api_info()['too_old'], 'the flag itself is only rewritten by the next response' );
	}

	public function test_no_header_at_all_leaves_the_contract_unknown(): void {
		WPStub::queue( 200, [ 'tokenName' => 't' ] );
		( new Client( $this->o ) )->me();
		self::assertSame( '', $this->o->api_info()['version'] );
		self::assertNull( $this->o->client_too_old() );
	}

	public function test_client_sends_the_feed_and_per_url_parameters_and_the_new_routes(): void {
		$c = new Client( $this->o );
		WPStub::queue( 200, self::list( [] ) );
		$c->urls( 'chain_de', null, '2026-09-09T10:00:00Z', 'https://example.com/a/' );
		self::assertSame( '/api/public/v1/chains/chain_de/urls?limit=200&updatedSince=2026-09-09T10%3A00%3A00Z&url=https%3A%2F%2Fexample.com%2Fa%2F', self::paths()[0] );
		WPStub::queue( 200, [] );
		$c->url_row( 'u1' );
		WPStub::queue( 200, [] );
		$c->changelog();
		self::assertSame( '/api/public/v1/urls/u1', self::paths()[1] );
		self::assertSame( '/api/public/v1/changelog', self::paths()[2] );
	}

	/* ── Sync: 410, 426, rate headroom, business pin ──────────────────── */

	public function test_a_410_withdrawn_artifact_is_deactivated_at_once_r01b(): void {
		$url = 'https://example.com/angebot/';
		$GLOBALS['wpdb']->rows = [ self::local( $url ) ];
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', $url ) ] ) );
		WPStub::queue( 410, self::err( 'withdrawn', 'JSON-LD unpublished for this URL' ) );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( 1, $r['fetched'] );
		self::assertSame( '/api/public/v1/urls/u1/jsonld', self::paths()[2] );
		$deactivated = array_filter( $GLOBALS['wpdb']->writes, static fn( array $w ): bool => 'update' === $w[0] && 0 === ( $w[1]['active'] ?? 1 ) && 'AIVIS_RETRACTED' === ( $w[1]['last_error_code'] ?? '' ) );
		self::assertNotEmpty( $deactivated, 'active = 0 with the retraction code, no confirmation round' );
		$msgs = array_column( WPStub::$options['aivis_os_diagnostics'], 'message' );
		self::assertNotEmpty( array_filter( $msgs, static fn( string $m ): bool => str_contains( $m, '410 withdrawn' ) ) );
		self::assertGreaterThanOrEqual( 1, $this->o->sync_state()['last_purge']['count'], 'its cache is purged' );
	}

	public function test_a_426_aborts_the_walk_and_is_recorded_once(): void {
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 426, self::err( 'client_too_old' ), [ 'x-aivis-min-client' => '3.0.0' ] );
		$r = $this->sync()->run();
		self::assertSame( 'aborted', $r['skipped'] ?? null );
		self::assertTrue( $this->o->client_too_old() );
		self::assertSame( 1, count( array_keys( self::codes(), 'AIVIS_CLIENT_TOO_OLD', true ) ) );
		self::assertSame( 'critical', ( new SiteHealth( Plugin::instance() ) )->test_api()['status'] );
	}

	public function test_the_tick_yields_when_the_rate_window_runs_low(): void {
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', 'https://example.com/a/' ), self::row( 'u2', 'https://example.com/b/' ) ] ) );
		WPStub::queue( 200, self::envelope( 'u1', 'https://example.com/a/' ), [ 'x-ratelimit-remaining' => '5', 'x-ratelimit-limit' => '600' ] );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( 1, $r['fetched'], 'stopped after the fetch that reported 5 requests left' );
		self::assertSame( 1, $r['pending'] );
		self::assertNotEmpty( array_filter( array_keys( WPStub::$scheduled ), static fn( string $k ): bool => str_starts_with( $k, 'aivis_os_sync' ) ), 'continuation scheduled' );
	}

	public function test_inventory_rows_of_another_business_are_never_stored(): void {
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', 'https://example.com/a/' ), self::row( 'u2', 'https://example.com/b/', [ 'businessId' => 'biz_other' ] ) ] ) );
		WPStub::queue( 200, self::envelope( 'u1', 'https://example.com/a/' ) );
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertSame( 1, $r['fetched'], 'the foreign row is not a target' );
		self::assertTrue( $r['authoritative'], 'skipped rows still count towards seen == total' );
		self::assertContains( 'AIVIS_SCOPE_MISMATCH', self::codes() );
		self::assertCount( 3, self::paths() );
	}

	/* ── Sync: full walks and the change feed (API-6) ─────────────────── */

	public function test_the_change_feed_runs_between_full_walks_and_never_retires(): void {
		// Fresh install: a full walk.
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [] ) );
		$r = $this->sync()->run();
		self::assertSame( 'full', $r['mode'] );
		self::assertTrue( $r['authoritative'] );
		$state = $this->o->sync_state();
		self::assertGreaterThan( 0, $state['last_full_at'] );
		self::assertTrue( $state['last_authoritative'] );

		// Right after it: the feed, with updatedSince a few minutes before the last start.
		WPStub::$http_log = [];
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [] ) );
		$r = $this->sync()->run();
		self::assertSame( 'incremental', $r['mode'] );
		self::assertFalse( $r['authoritative'], 'a feed page is never authoritative for absence' );
		self::assertMatchesRegularExpression( '#/chains/chain_de/urls\?limit=200&updatedSince=\d{4}-\d\d-\d\dT\d\d%3A\d\d%3A\d\dZ$#', self::paths()[1] );
		$state = $this->o->sync_state();
		self::assertTrue( $state['last_authoritative'], 'the last full walk\'s verdict stands' );
		self::assertSame( 'incremental', $state['last_mode'] );
		self::assertNotContains( 'AIVIS_SYNC_PARTIAL', self::codes(), 'a feed walk is not a failed reconciliation' );

		// Forced, or after FULL_WALK_EVERY: full again.
		WPStub::$http_log = [];
		$this->o->patch_sync_state( [ 'force_full' => true ] );
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [] ) );
		self::assertSame( 'full', $this->sync()->run()['mode'] );
		self::assertStringNotContainsString( 'updatedSince', self::paths()[1] );
		$this->o->patch_sync_state( [ 'last_full_at' => time() - Synchronizer::FULL_WALK_EVERY - 1 ] );
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [] ) );
		self::assertSame( 'full', $this->sync()->run()['mode'] );
	}

	public function test_an_unpublished_row_in_the_feed_takes_the_block_down(): void {
		$url = 'https://example.com/angebot/';
		$this->o->patch_sync_state( [ 'last_full_at' => time(), 'last_run_started_at' => time() - 600, 'last_authoritative' => true ] );
		$GLOBALS['wpdb']->rows = [ self::local( $url ) ];
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', $url, [ 'captureStatus' => 'processing', 'jsonLd' => [ 'ready' => false, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'suppressedAt' => '2026-09-09T08:00:00Z' ] ] ) ] ) );
		$r = $this->sync()->run();
		self::assertSame( 'incremental', $r['mode'] );
		self::assertSame( 0, $r['fetched'], 'nothing to fetch: ready is false' );
		self::assertSame( 0, $r['retired'] );
		$deactivated = array_filter( $GLOBALS['wpdb']->writes, static fn( array $w ): bool => 'update' === $w[0] && 0 === ( $w[1]['active'] ?? 1 ) && 'AIVIS_RETRACTED' === ( $w[1]['last_error_code'] ?? '' ) );
		self::assertNotEmpty( $deactivated, 'suppressedAt wins over a processing capture' );
		$msgs = array_column( WPStub::$options['aivis_os_diagnostics'], 'message' );
		self::assertNotEmpty( array_filter( $msgs, static fn( string $m ): bool => str_contains( $m, 'unpublished in AIVIS' ) ) );
	}

	/* ── Refresh one URL through the inventory (API-5) ────────────────── */

	public function test_refresh_holds_or_deactivates_from_the_row_when_there_is_no_document(): void {
		$url = 'https://example.com/a/';
		$GLOBALS['wpdb']->rows = [ self::local( $url ) ];
		WPStub::queue( 200, self::list( [ self::row( 'u1', $url, [ 'captureStatus' => 'processing', 'jsonLd' => [ 'ready' => false, 'stale' => false, 'generatedAt' => null, 'suppressedAt' => null ] ] ) ] ) );
		$r = $this->sync()->refresh_url( $url );
		self::assertSame( 'hold', $r['action'] );
		self::assertSame( 'R-02a', $r['rule'] );
		self::assertCount( 1, self::paths(), 'no artifact request for a row without a document' );

		WPStub::queue( 200, self::list( [ self::row( 'u1', $url, [ 'captureStatus' => 'processed', 'jsonLd' => [ 'ready' => false, 'stale' => false, 'generatedAt' => null, 'suppressedAt' => '2026-09-09T08:00:00Z' ] ] ) ] ) );
		$r = $this->sync()->refresh_url( $url );
		self::assertSame( 'deactivate', $r['action'] );

		// An instance that ignores ?url= hands back an unfiltered page: only the page asked for counts.
		WPStub::queue( 200, self::list( [ self::row( 'u7', 'https://example.com/other/' ) ] ) );
		$r = $this->sync()->refresh_url( 'https://example.com/nowhere/' );
		self::assertSame( 'suspend', $r['action'], 'a foreign row is not this page' );
	}

	/* ── Connection test says what failed, and where (#69) ────────────── */

	public function test_connection_test_names_the_host_and_the_reason(): void {
		$menu = new Menu( Plugin::instance() );
		$o    = Plugin::instance()->options();

		unset( WPStub::$options['aivis_os_token'] );
		self::assertSame( 'no_token', $menu->test_connection() );
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );

		WPStub::queue_error( 'cURL error 6: Could not resolve host: app.aivis-os.com' );
		self::assertSame( 'unreachable', $menu->test_connection() );
		$f = $o->token_status()['failure'];
		self::assertSame( 'transport', $f['kind'] );
		self::assertSame( 'app.aivis-os.com', $f['host'], 'the production default, which is what the admin must learn' );
		self::assertStringContainsString( 'Could not resolve host', $f['detail'] );
		self::assertFalse( $o->token_status()['valid'] );
		self::assertStringContainsString( 'Could not reach app.aivis-os.com', SettingsPage::failure_label( $f ) );

		WPStub::queue( 401, self::err( 'invalid_token', 'Invalid API token' ) );
		self::assertSame( 'auth_failed', $menu->test_connection() );
		$f = $o->token_status()['failure'];
		self::assertSame( 'auth', $f['kind'] );
		self::assertSame( 'invalid_token — Invalid API token', $f['detail'] );
		self::assertSame( 'Token rejected by app.aivis-os.com', SettingsPage::failure_label( $f ) );

		WPStub::queue( 426, self::err( 'client_too_old' ), [ 'x-aivis-min-client' => '9.0.0' ] );
		self::assertSame( 'client_too_old', $menu->test_connection() );
		WPStub::queue( 403, self::err( 'account_deactivated' ) );
		self::assertSame( 'account_disabled', $menu->test_connection() );
		WPStub::queue( 500, self::err( 'internal_error' ) );
		self::assertSame( 'api_error', $menu->test_connection() );
		WPStub::queue( 429, self::err( 'rate_limited' ), [ 'retry-after' => '30' ] );
		self::assertSame( 'throttled', $menu->test_connection() );
	}

	public function test_connection_test_remembers_a_bound_token_and_reads_the_changelog(): void {
		$menu = new Menu( Plugin::instance() );
		$o    = Plugin::instance()->options();
		set_transient( 'aivis_os_businesses', [ [ 'id' => 'stale' ] ], 300 );
		WPStub::queue( 200, [ 'email' => null, 'name' => null, 'tokenName' => 'wp-bound', 'businessId' => 'biz_live', 'business' => [ 'id' => 'biz_live', 'name' => 'Example', 'baseUrl' => 'https://example.com' ], 'permissions' => [ 'jsonld:read' ] ], [ 'x-aivis-api-version' => '1.9.0', 'x-aivis-min-client' => '1.0.0' ] );
		WPStub::queue( 200, [ 'apiVersion' => '1.9.0', 'minClientVersion' => '1.0.0', 'nextMinClient' => [ 'version' => '1.2.0', 'effectiveFrom' => '2027-01-01' ], 'rateLimits' => [ 'perToken' => [ 'limit' => 600, 'windowSeconds' => 60 ], 'perIp' => [ 'limit' => 60, 'windowSeconds' => 60 ] ], 'deprecations' => [], 'entries' => [] ] );
		self::assertSame( 'connected', $menu->test_connection() );
		$ts = $o->token_status();
		self::assertTrue( $ts['valid'] );
		self::assertTrue( $ts['bound'] );
		self::assertSame( 'Example', $ts['business_name'] );
		self::assertSame( 'https://example.com', $ts['business_base_url'] );
		self::assertSame( [ 'jsonld:read' ], $ts['permissions'] );
		self::assertNull( $ts['failure'] );
		self::assertFalse( get_transient( 'aivis_os_businesses' ), 'the cached business list belongs to the previous token' );
		$api = $o->api_info();
		self::assertSame( '1.9.0', $api['version'] );
		self::assertSame( [ 'version' => '1.2.0', 'effective_from' => '2027-01-01' ], $api['next_min_client'] );
		self::assertSame( [ 'limit' => 600, 'window' => 60 ], $api['rate_limit'] );
		self::assertSame( 'recommended', ( new SiteHealth( Plugin::instance() ) )->test_api()['status'], 'a raised minimum is announced' );

		// An account-wide token on a 1.0.0 instance: no changelog route, still connected.
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 404, self::err( 'not_found' ) );
		self::assertSame( 'connected', $menu->test_connection() );
		$ts = $o->token_status();
		self::assertFalse( $ts['bound'] );
		self::assertSame( 'a@b.c', $ts['email'] );
	}

	public function test_saving_a_token_without_the_prefix_is_named_as_such(): void {
		self::assertSame( 'token_format', ( new SettingsPage( Plugin::instance() ) )->save( [ 'aivis_token' => 'sk-not-aivis', 'injection' => '1', 'interval' => '900', 'cache_adapter' => 'auto' ] ) );
	}

	public function test_settings_screen_is_one_form_whose_buttons_name_their_action(): void {
		unset( WPStub::$options['aivis_os_token'] );
		ob_start();
		( new SettingsPage( Plugin::instance() ) )->render();
		$html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $html, '<form' ), 'a nested form closes the outer one in every browser (#69)' );
		self::assertSame( 1, substr_count( $html, '</form>' ) );
		self::assertStringContainsString( 'name="do" value="save_and_test"', $html );
		self::assertStringContainsString( 'name="do" value="save_settings"', $html );
		self::assertStringNotContainsString( 'type="hidden" name="do"', $html, 'the buttons carry the action, not a hidden field that a second button would shadow' );
		self::assertStringNotContainsString( 'test_connection', $html );
	}

	/* ── Site Health, status document, CLI ────────────────────────────── */

	public function test_site_health_and_status_document_report_the_contract(): void {
		$h = new SiteHealth( Plugin::instance() );
		self::assertSame( 'recommended', $h->test_api()['status'], 'nothing heard yet' );
		$this->o->set_api_info( [ 'version' => '1.9.0', 'min_client' => '1.0.0', 'seen_at' => time() ] );
		$r = $h->test_api();
		self::assertSame( 'good', $r['status'] );
		self::assertStringContainsString( 'contract 1.9.0', $r['label'] );
		$this->o->set_api_info( [ 'min_client' => '9.0.0' ] );
		self::assertSame( 'critical', $h->test_api()['status'] );
		$d = ( new StatusDocument( $this->o, new Repository(), new AdapterFactory( $this->o ) ) )->build();
		self::assertSame( '1.9.0', $d['api']['contractVersion'] );
		self::assertSame( '9.0.0', $d['api']['minClient'] );
		self::assertTrue( $d['api']['clientTooOld'] );
	}

	public function test_cli_connection_test_reports_binding_and_contract(): void {
		$c = new Commands( Plugin::instance() );
		WPStub::queue( 200, [ 'email' => null, 'name' => null, 'tokenName' => 'wp-bound', 'businessId' => 'biz_live', 'business' => [ 'id' => 'biz_live', 'name' => 'Example', 'baseUrl' => 'https://example.com' ], 'permissions' => [ 'jsonld:read' ] ], [ 'x-aivis-api-version' => '1.9.0', 'x-aivis-min-client' => '1.0.0' ] );
		WPStub::queue( 404, self::err( 'not_found' ) );
		$c->connection( [ 'test' ] );
		$msg = WP_CLI::messages( 'success' )[0];
		self::assertStringContainsString( 'contract 1.9.0', $msg );
		self::assertStringContainsString( 'bound to business Example', $msg );

		WPStub::queue( 401, self::err( 'invalid_token', 'Invalid API token' ) );
		try {
			$c->connection( [ 'test' ] );
			self::fail( 'a rejected token must stop the command' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'Token rejected by app.aivis-os.com', $e->getMessage() );
			self::assertStringContainsString( 'invalid_token', $e->getMessage() );
		}
	}
}
