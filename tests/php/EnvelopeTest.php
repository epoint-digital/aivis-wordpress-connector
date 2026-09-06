<?php
declare( strict_types=1 );

use AivisOS\Security\Envelope;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase {

	private static function env( array $over = [] ): array {
		return $over + [
			'urlId' => 'u1', 'chainId' => 'c1', 'businessId' => 'b1', 'url' => 'https://example.com/p',
			'languageCode' => 'de', 'stale' => false, 'generatedAt' => '2026-09-01T10:00:00.000Z',
			'jsonLd' => [ '@type' => 'WebPage' ],
		];
	}

	private static function nest( int $n ): array {
		return $n <= 1 ? [] : [ 'child' => self::nest( $n - 1 ) ];
	}

	public function test_valid_passes(): void {
		self::assertTrue( Envelope::validate( self::env() )['ok'] );
	}

	public function test_each_field_required(): void {
		foreach ( [ 'urlId', 'chainId', 'businessId', 'url', 'languageCode', 'stale', 'generatedAt', 'jsonLd' ] as $f ) {
			$e = self::env();
			unset( $e[ $f ] );
			$v = Envelope::validate( $e );
			self::assertFalse( $v['ok'], $f );
			self::assertStringContainsString( $f, implode( ';', $v['errors'] ) );
		}
	}

	public function test_type_confusion_rejected(): void {
		self::assertFalse( Envelope::validate( self::env( [ 'stale' => 'false' ] ) )['ok'] );
		self::assertFalse( Envelope::validate( self::env( [ 'generatedAt' => 'not a timestamp' ] ) )['ok'] );
		self::assertFalse( Envelope::validate( self::env( [ 'url' => '/p' ] ) )['ok'] );
		self::assertFalse( Envelope::validate( self::env( [ 'jsonLd' => 'scalar' ] ) )['ok'] );
		self::assertFalse( Envelope::validate( self::env( [ 'jsonLd' => null ] ) )['ok'] );
	}

	public function test_array_graph_accepted_and_extra_fields_ignored(): void {
		self::assertTrue( Envelope::validate( self::env( [ 'jsonLd' => [ [ '@type' => 'A' ], [ '@type' => 'B' ] ] ] ) )['ok'] );
		self::assertTrue( Envelope::validate( self::env( [ 'suppressedAt' => '2026-09-05T00:00:00Z' ] ) )['ok'] );
	}

	public function test_non_object_rejected(): void {
		foreach ( [ null, 'str', 42, [ 'list' ] ] as $v ) {
			self::assertFalse( Envelope::validate( $v )['ok'] );
		}
	}

	public function test_depth_boundary(): void {
		self::assertTrue( Envelope::validate( self::env( [ 'jsonLd' => self::nest( 32 ) ] ) )['ok'], 'depth 32 accepted' );
		$v = Envelope::validate( self::env( [ 'jsonLd' => self::nest( 33 ) ] ) );
		self::assertFalse( $v['ok'], 'depth 33 rejected' );
		self::assertStringContainsString( 'depth', implode( ';', $v['errors'] ) );
	}

	public function test_size_boundary(): void {
		$overhead = strlen( json_encode( [ 'b' => '' ] ) );
		$exact    = [ 'b' => str_repeat( 'a', Envelope::MAX_BYTES - $overhead ) ];
		self::assertSame( Envelope::MAX_BYTES, strlen( json_encode( $exact ) ) );
		self::assertTrue( Envelope::validate( self::env( [ 'jsonLd' => $exact ] ) )['ok'], 'exactly 1 MiB accepted' );
		$over = [ 'b' => str_repeat( 'a', Envelope::MAX_BYTES - $overhead + 1 ) ];
		self::assertFalse( Envelope::validate( self::env( [ 'jsonLd' => $over ] ) )['ok'], '1 MiB + 1 rejected' );
	}
}
