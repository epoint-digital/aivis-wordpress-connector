<?php
declare( strict_types=1 );

use AivisOS\Domain\UrlKey;
use PHPUnit\Framework\TestCase;

final class UrlKeyTest extends TestCase {

	public function test_trailing_slash_collapses(): void {
		self::assertSame( UrlKey::of( 'https://example.com/services/' ), UrlKey::of( 'https://example.com/services' ) );
	}

	public function test_root_preserved(): void {
		self::assertSame( 'https://example.com/', UrlKey::normalize( 'https://example.com/' ) );
		self::assertSame( 'https://example.com/', UrlKey::normalize( 'https://example.com' ) );
	}

	public function test_tracking_stripped_rest_sorted(): void {
		self::assertSame( 'https://example.com/p?a=1&b=2', UrlKey::normalize( 'https://example.com/p?utm_source=x&b=2&a=1&fbclid=z&gclid=q&msclkid=w&_ga=1' ) );
		self::assertSame( 'https://example.com/p?id=7', UrlKey::normalize( 'https://example.com/p?id=7' ) );
	}

	public function test_host_case_fragment_ports(): void {
		self::assertSame( 'https://example.com/p', UrlKey::normalize( 'https://EXAMPLE.com:443/p#frag' ) );
		self::assertSame( 'http://example.com/p', UrlKey::normalize( 'http://example.com:80/p' ) );
		self::assertSame( 'https://example.com:8443/p', UrlKey::normalize( 'https://example.com:8443/p' ) );
	}

	public function test_percent_encoding_preserved(): void {
		self::assertSame( 'https://example.com/caf%C3%A9', UrlKey::normalize( 'https://example.com/caf%C3%A9' ) );
	}

	public function test_non_http_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		UrlKey::normalize( 'ftp://example.com/x' );
	}

	public function test_idempotent(): void {
		$once = UrlKey::normalize( 'https://EXAMPLE.com:443/services/?utm_source=x&b=2#f' );
		self::assertSame( $once, UrlKey::normalize( $once ) );
	}

	public function test_slash_aliases(): void {
		self::assertSame( [ 'https://e.com/a/', 'https://e.com/a' ], UrlKey::slash_aliases( 'https://e.com/a/' ) );
		self::assertSame( [ 'https://e.com/a', 'https://e.com/a/' ], UrlKey::slash_aliases( 'https://e.com/a' ) );
	}
}
