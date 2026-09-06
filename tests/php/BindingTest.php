<?php
declare( strict_types=1 );

use AivisOS\Security\Binding;
use PHPUnit\Framework\TestCase;

final class BindingTest extends TestCase {

	private static function env( array $o = [] ): array {
		return $o + [ 'urlId' => 'u', 'chainId' => 'c1', 'businessId' => 'b1', 'url' => 'https://example.com/p', 'languageCode' => 'de', 'stale' => false, 'generatedAt' => 'x', 'jsonLd' => [] ];
	}

	public function test_matching_binds(): void {
		self::assertTrue( Binding::check( self::env(), 'b1', [ 'example.com' ] )['ok'] );
	}

	public function test_foreign_business_rejected(): void {
		$v = Binding::check( self::env( [ 'businessId' => 'b2' ] ), 'b1', [ 'example.com' ] );
		self::assertFalse( $v['ok'] );
		self::assertStringStartsWith( 'AIVIS_SCOPE_MISMATCH', $v['errors'][0] );
	}

	public function test_foreign_host_rejected_and_case_insensitive(): void {
		self::assertFalse( Binding::check( self::env( [ 'url' => 'https://andere.de/p' ] ), 'b1', [ 'example.com' ] )['ok'] );
		self::assertTrue( Binding::check( self::env( [ 'url' => 'https://EXAMPLE.com/p' ] ), 'b1', [ 'example.com' ] )['ok'] );
	}

	public function test_chain_membership_only_with_context(): void {
		$e = self::env( [ 'chainId' => 'unknown' ] );
		self::assertTrue( Binding::check( $e, 'b1', [ 'example.com' ] )['ok'] );
		self::assertFalse( Binding::check( $e, 'b1', [ 'example.com' ], [ 'c1', 'c2' ] )['ok'] );
	}

	public function test_requested_url_or_slash_alias(): void {
		self::assertTrue( Binding::check( self::env(), 'b1', [ 'example.com' ], null, 'https://example.com/p' )['ok'] );
		self::assertTrue( Binding::check( self::env( [ 'url' => 'https://example.com/p/' ] ), 'b1', [ 'example.com' ], null, 'https://example.com/p' )['ok'] );
		self::assertFalse( Binding::check( self::env( [ 'url' => 'https://example.com/other' ] ), 'b1', [ 'example.com' ], null, 'https://example.com/p' )['ok'] );
	}

	public function test_unparseable_url_reported_not_thrown(): void {
		$v = Binding::check( self::env( [ 'url' => 'not a url' ] ), 'b1', [ 'example.com' ] );
		self::assertFalse( $v['ok'] );
		self::assertStringContainsString( 'AIVIS_SCHEMA_INVALID', implode( ';', $v['errors'] ) );
	}
}
