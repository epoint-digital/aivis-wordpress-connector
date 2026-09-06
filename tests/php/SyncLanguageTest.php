<?php
declare( strict_types=1 );

use AivisOS\Api\Client;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Delivery\Language;
use AivisOS\Security\Binding;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Synchronizer;
use PHPUnit\Framework\TestCase;

/**
 * §07a end to end through the Synchronizer: only assigned chains sync,
 * inventory targets are fetched by urlId, and an artifact from a chain that is
 * not assigned to the page's language is rejected.
 */
final class SyncLanguageTest extends TestCase {

	private Options $o;

	protected function setUp(): void {
		WPStub::reset();
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$this->o = new Options();
		$this->o->set_business( 'biz_live', 'Example', 'https://example.com', [] );
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
	}

	private function sync(): Synchronizer {
		return new Synchronizer( new Client( $this->o ), new Repository(), $this->o, new AdapterFactory( $this->o ) );
	}

	private static function list( array $items ): array {
		return [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => count( $items ) ];
	}

	private static function chain( string $id ): array {
		return [ 'id' => $id, 'name' => $id, 'state' => 'ready', 'currentStep' => 9, 'knowledgeGraphReady' => true, 'urlCount' => 1 ];
	}

	private static function row( string $id, string $url, string $lang ): array {
		return [ 'id' => $id, 'url' => $url, 'languageCode' => $lang, 'layer' => 'editorial', 'captureStatus' => 'processed', 'jsonLd' => [ 'ready' => true, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z' ] ];
	}

	private static function envelope( string $id, string $chain, string $url, string $lang ): array {
		return [ 'urlId' => $id, 'chainId' => $chain, 'businessId' => 'biz_live', 'url' => $url, 'languageCode' => $lang, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => [ '@type' => 'Thing' ] ];
	}

	private static function paths(): array {
		return array_map( fn( $l ) => parse_url( $l[0], PHP_URL_PATH ) . ( parse_url( $l[0], PHP_URL_QUERY ) ? '?' . parse_url( $l[0], PHP_URL_QUERY ) : '' ), WPStub::$http_log );
	}

	public function test_nothing_runs_until_a_chain_is_assigned(): void {
		WPStub::$filter_values['aivis_connector_site_languages'] = static fn( array $l ): array => $l + [ 'en' => [ 'code' => 'en', 'name' => 'English', 'home' => 'https://example.com/en/' ] ];
		WPStub::queue( 200, self::list( [ self::chain( 'chain_x' ) ] ) );
		WPStub::queue( 200, self::list( [] ) ); // hint: empty chain — ambiguous with two languages
		$r = $this->sync()->run();
		self::assertSame( 'no chain assigned to a language', $r['skipped'] );
		self::assertSame( 'AIVIS_LANGUAGE_UNASSIGNED', end( WPStub::$options['aivis_os_diagnostics'] )['code'] );
		self::assertSame( [], $this->o->chain_languages() );
	}

	public function test_only_assigned_chains_are_walked_and_targets_fetched_by_id(): void {
		$this->o->set_chain_languages( [ 'chain_de' => 'de' ] );
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ), self::chain( 'chain_en' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', 'https://example.com/a/', 'de' ) ] ) ); // chain_de inventory
		WPStub::queue( 200, self::envelope( 'u1', 'chain_de', 'https://example.com/a/', 'de' ) );  // by id
		$r = $this->sync()->run();
		self::assertTrue( $r['ok'], json_encode( $r ) );
		self::assertTrue( $r['authoritative'], 'one assigned chain, fully walked, reconciles' );
		$paths = self::paths();
		self::assertSame( '/api/public/v1/businesses/biz_live/chains?limit=200', $paths[0] );
		self::assertSame( '/api/public/v1/chains/chain_de/urls?limit=200', $paths[1] );
		self::assertSame( '/api/public/v1/urls/u1/jsonld', $paths[2], 'by urlId, never /jsonld?url= for inventory targets' );
		self::assertCount( 3, $paths, 'chain_en is not assigned and is not walked' );
		self::assertSame( [ 'chain_de' ], WPStub::$options['aivis_os_sync_state']['chains'] ?? [ 'chain_de' ] );
	}

	public function test_an_assignment_to_a_deleted_chain_is_dropped(): void {
		$this->o->set_chain_languages( [ 'chain_de' => 'de', 'chain_gone' => 'de' ] );
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [] ) );
		$this->sync()->run();
		self::assertSame( [ 'chain_de' => 'de' ], $this->o->chain_languages() );
		$codes = array_column( WPStub::$options['aivis_os_diagnostics'], 'code' );
		self::assertContains( 'AIVIS_LANGUAGE_UNASSIGNED', $codes );
	}

	public function test_language_disagreement_is_reported_not_acted_on(): void {
		$this->o->set_chain_languages( [ 'chain_de' => 'de' ] );
		WPStub::queue( 200, self::list( [ self::chain( 'chain_de' ) ] ) );
		WPStub::queue( 200, self::list( [ self::row( 'u1', 'https://example.com/a/', 'en' ), self::row( 'u2', 'https://example.com/b/', 'de' ) ] ) );
		WPStub::queue( 200, self::envelope( 'u1', 'chain_de', 'https://example.com/a/', 'en' ) );
		WPStub::queue( 200, self::envelope( 'u2', 'chain_de', 'https://example.com/b/', 'de' ) );
		$r = $this->sync()->run();
		self::assertSame( 2, $r['fetched'], 'both stored: the assignment is the admin\'s call' );
		$m = WPStub::$options['aivis_os_sync_state']['language_mismatch'];
		self::assertSame( 1, $m['chain_de']['count'] );
		self::assertSame( 'en', $m['chain_de']['aivis'] );
		self::assertSame( 'de', $m['chain_de']['assigned'] );
		self::assertContains( 'AIVIS_LANGUAGE_MISMATCH', array_column( WPStub::$options['aivis_os_diagnostics'], 'code' ) );
	}

	public function test_refresh_rejects_an_artifact_from_a_chain_not_assigned_to_the_pages_language(): void {
		WPStub::$filter_values['aivis_connector_site_languages'] = static fn( array $l ): array => $l + [ 'en' => [ 'code' => 'en', 'name' => 'English', 'home' => 'https://example.com/en/' ] ];
		$this->o->set_chain_languages( [ 'chain_de' => 'de', 'chain_en' => 'en' ] );
		// The German chain answers for an English page: /jsonld?url= picked the freshest.
		WPStub::queue( 200, self::envelope( 'u9', 'chain_de', 'https://example.com/en/about/', 'de' ) );
		$r = $this->sync()->refresh_url( 'https://example.com/en/about/' );
		self::assertSame( 'reject', $r['action'] );
		self::assertSame( 'AIVIS_SCOPE_MISMATCH', $r['code'] );
		$last = end( WPStub::$options['aivis_os_diagnostics'] );
		self::assertStringContainsString( 'chainId chain_de is not an assigned chain for language en', $last['message'] );

		// The English chain answering for the English page is accepted.
		WPStub::queue( 200, self::envelope( 'u9', 'chain_en', 'https://example.com/en/about/', 'en' ) );
		$r = $this->sync()->refresh_url( 'https://example.com/en/about/' );
		self::assertSame( 'serve', $r['action'] );
	}

	public function test_refresh_needs_an_assignment_too(): void {
		$r = $this->sync()->refresh_url( 'https://example.com/a/' );
		self::assertFalse( $r['ok'] );
		self::assertSame( [], WPStub::$http_log, 'no request without an assigned chain' );
	}

	public function test_binding_message_names_the_language_context(): void {
		$env = self::envelope( 'u', 'c9', 'https://example.com/p', 'de' );
		$v   = Binding::check( $env, 'biz_live', [ 'example.com' ], [ 'c1' ], null, 'language de' );
		self::assertFalse( $v['ok'] );
		self::assertSame( 'AIVIS_SCOPE_MISMATCH: chainId c9 is not an assigned chain for language de', $v['errors'][0] );
	}

	public function test_unassigning_a_chain_deactivates_its_rows_now(): void {
		$GLOBALS['wpdb']->rows = [ [ 'source_url' => 'https://example.com/old/' ] ];
		$urls = ( new Repository() )->deactivate_chains_not_in( [ 'chain_de', 'chain_en' ], 'AIVIS_LANGUAGE_UNASSIGNED' );
		self::assertSame( [ 'https://example.com/old/' ], $urls );
		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( "chain_id NOT IN ('chain_de','chain_en')", $sql );
		self::assertStringContainsString( "SET active = 0, last_error_code = 'AIVIS_LANGUAGE_UNASSIGNED'", $sql );
		self::assertStringNotContainsString( 'retired_at =', $sql, 'deactivated, not retired: re-assigning brings the rows back' );
	}

	public function test_language_subdomains_are_allowed_hosts(): void {
		Language::$force_provider = Language::WPML;
		WPStub::$filter_values['wpml_active_languages'] = [
			'de' => [ 'code' => 'de', 'native_name' => 'Deutsch', 'url' => 'https://example.com/' ],
			'en' => [ 'code' => 'en', 'native_name' => 'English', 'url' => 'https://en.example.com/' ],
		];
		self::assertContains( 'en.example.com', $this->o->allowed_hosts() );
	}
}
