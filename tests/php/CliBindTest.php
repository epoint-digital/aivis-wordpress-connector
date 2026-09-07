<?php
declare( strict_types=1 );

use AivisOS\Cli\Commands;
use AivisOS\Delivery\Language;
use AivisOS\Plugin;
use AivisOS\Storage\Options;
use PHPUnit\Framework\TestCase;

/** `wp aivis bind` — the headless path the install skill relies on. */
final class CliBindTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		WP_CLI::reset();
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		delete_transient( 'aivis_os_chains_' . md5( 'biz_live' ) );
	}

	private static function page( array $items ): array {
		return [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => count( $items ) ];
	}

	private static function biz( string $id, string $base, string $created = '2026-03-01T00:00:00Z' ): array {
		return [ 'id' => $id, 'name' => strtoupper( $id ), 'baseUrl' => $base, 'industry' => [ 'key' => 'g', 'name' => 'G' ], 'chainCount' => 1, 'createdAt' => $created ];
	}

	public function test_binds_the_one_business_on_this_domain_and_assigns_chains(): void {
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 200, self::page( [ self::biz( 'biz_live', 'https://www.example.com' ), self::biz( 'biz_other', 'https://andere.de' ) ] ) );
		WPStub::queue( 200, self::page( [ [ 'id' => 'chain_core', 'name' => 'Core', 'state' => 'ready', 'urlCount' => 3 ] ] ) );
		WPStub::queue( 200, self::page( [ [ 'id' => 'u1', 'url' => 'https://example.com/', 'languageCode' => 'de', 'jsonLd' => [ 'ready' => true, 'stale' => false, 'generatedAt' => null ] ] ] ) );
		( new Commands( Plugin::instance() ) )->bind( [], [] );
		$o = new Options();
		self::assertSame( 'biz_live', $o->business()['business_id'], 'www twin matches' );
		self::assertSame( [ 'chain_core' => 'de' ], $o->chain_languages(), 'one language, compatible chain: assigned' );
		self::assertTrue( $o->token_status()['valid'] );
		self::assertNotEmpty( WP_CLI::messages( 'success' ) );
		self::assertArrayHasKey( 'aivis_os_sync:' . md5( serialize( [ 'now' ] ) ), WPStub::$scheduled, 'sync requested' );
	}

	public function test_refuses_when_no_business_uses_this_domain(): void {
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 200, self::page( [ self::biz( 'biz_other', 'https://andere.de' ) ] ) );
		try {
			( new Commands( Plugin::instance() ) )->bind( [], [] );
			self::fail( 'must refuse' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'No business on this account uses example.com', $e->getMessage() );
		}
		self::assertSame( '', ( new Options() )->business()['business_id'] );
	}

	public function test_two_businesses_on_one_domain_need_an_explicit_choice_and_a_foreign_id_is_refused(): void {
		$two = self::page( [ self::biz( 'biz_live', 'https://example.com' ), self::biz( 'biz_rebuild', 'https://example.com', '2026-08-20T00:00:00Z' ), self::biz( 'biz_other', 'https://andere.de' ) ] );
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 200, $two );
		try {
			( new Commands( Plugin::instance() ) )->bind( [], [] );
			self::fail( 'must not guess' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( '2 businesses use example.com', $e->getMessage() );
			self::assertCount( 2, WP_CLI::messages( 'log' ), 'both candidates listed' );
		}
		WP_CLI::reset();
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 200, $two );
		try {
			( new Commands( Plugin::instance() ) )->bind( [], [ 'business' => 'biz_other' ] );
			self::fail( 'must refuse another domain' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'does not use example.com', $e->getMessage() );
		}
		WP_CLI::reset();
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 'wp' ] );
		WPStub::queue( 200, $two );
		WPStub::queue( 200, self::page( [] ) ); // no chains yet
		( new Commands( Plugin::instance() ) )->bind( [], [ 'business' => 'biz_rebuild', 'hosts' => 'alias.example.com' ] );
		$b = ( new Options() )->business();
		self::assertSame( 'biz_rebuild', $b['business_id'] );
		self::assertSame( [ 'alias.example.com' ], $b['allowed_hosts'] );
	}

	public function test_rejected_token_stops_before_anything_is_bound(): void {
		WPStub::queue( 401, [ 'error' => [ 'message' => 'Invalid API token' ] ] );
		try {
			( new Commands( Plugin::instance() ) )->bind( [], [] );
			self::fail( 'must stop' );
		} catch ( WP_CLI_Stop $e ) {
			self::assertStringContainsString( 'Token rejected: HTTP 401', $e->getMessage() );
		}
		self::assertFalse( ( new Options() )->token_status()['valid'] );
		self::assertCount( 1, WPStub::$http_log, 'no business listing after a rejected token' );
	}
}
