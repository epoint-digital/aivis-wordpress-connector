<?php
declare( strict_types=1 );

use AivisOS\Api\Client;
use AivisOS\Storage\Options;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		update_option( 'aivis_os_token', 'aivis_' . str_repeat( 'x', 43 ) );
	}

	private function client(): Client {
		return new Client( new Options() );
	}

	public function test_no_token_is_401_without_a_request(): void {
		delete_option( 'aivis_os_token' );
		$r = $this->client()->me();
		self::assertSame( 401, $r->status );
		self::assertCount( 0, WPStub::$http_log );
	}

	public function test_transport_rules_on_every_request(): void {
		WPStub::queue( 200, [ 'email' => 'a@b.c', 'name' => 'A', 'tokenName' => 't' ] );
		$this->client()->me();
		[ $url, $args ] = WPStub::$http_log[0];
		self::assertStringStartsWith( Options::DEFAULT_API_BASE . '/api/public/v1/me', $url );
		self::assertSame( 0, $args['redirection'], 'a redirect would forward the bearer token' );
		self::assertTrue( $args['sslverify'] );
		self::assertSame( 10, $args['timeout'] );
		self::assertSame( 1048576, $args['limit_response_size'] );
		self::assertStringStartsWith( 'Bearer aivis_', $args['headers']['Authorization'] );
		self::assertSame( 'application/json', $args['headers']['Accept'] );
		self::assertStringStartsWith( 'aivis-os/1.0.0', $args['user-agent'] );
	}

	public function test_lookup_sends_permalink_unmodified(): void {
		WPStub::queue( 404, [ 'error' => [ 'message' => 'x' ] ] );
		$this->client()->jsonld_by_url( 'https://example.com/services/?id=7' );
		self::assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Fservices%2F%3Fid%3D7', WPStub::$http_log[0][0] );
	}

	public function test_pagination_requests_200(): void {
		WPStub::queue( 200, [ 'items' => [], 'nextCursor' => null, 'hasMore' => false, 'total' => 0 ] );
		$this->client()->businesses( 'abc' );
		self::assertStringContainsString( 'limit=200', WPStub::$http_log[0][0] );
		self::assertStringContainsString( 'cursor=abc', WPStub::$http_log[0][0] );
	}

	public function test_network_failure_is_transport_and_redacted(): void {
		WPStub::queue_error( 'cURL error 28 with token aivis_' . str_repeat( 'z', 40 ) );
		$r = $this->client()->me();
		self::assertSame( 'transport', $r->kind() );
		self::assertStringNotContainsString( 'zzzz', $r->transport_error );
		self::assertStringContainsString( '[redacted]', $r->transport_error );
	}

	public function test_non_json_content_type_is_not_trusted(): void {
		WPStub::queue( 200, '<html>login</html>', [ 'content-type' => 'text/html' ] );
		$r = $this->client()->me();
		self::assertNull( $r->body );
		self::assertSame( 'non-JSON content type', $r->transport_error );
	}

	public function test_malformed_json_is_reported(): void {
		WPStub::queue( 200, '{not json' );
		$r = $this->client()->me();
		self::assertNull( $r->body );
		self::assertStringStartsWith( 'malformed JSON', $r->transport_error );
	}

	public function test_429_carries_retry_after(): void {
		WPStub::queue( 429, [ 'error' => [ 'message' => 'slow down' ] ], [ 'retry-after' => '30' ] );
		$r = $this->client()->me();
		self::assertSame( 'throttled', $r->kind() );
		self::assertSame( 30, $r->retry_after() );
	}

	public function test_a_protocol_relative_path_cannot_move_the_request_off_the_aivis_host(): void {
		// The URL is always base + path; a path that starts with // is just a
		// path. The request must still go to the configured host, never elsewhere.
		WPStub::queue( 200, [] );
		$this->client()->get( '//evil.example/steal' );
		self::assertCount( 1, WPStub::$http_log );
		$host = parse_url( WPStub::$http_log[0][0], PHP_URL_HOST );
		self::assertSame( parse_url( Options::DEFAULT_API_BASE, PHP_URL_HOST ), $host );
		self::assertStringNotContainsString( 'evil.example/steal', (string) $host );
	}
}
