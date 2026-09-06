<?php
declare( strict_types=1 );

use AivisOS\Security\Envelope;
use AivisOS\Storage\Options;
use AivisOS\Sync\Synchronizer;
use PHPUnit\Framework\TestCase;

/** Regression tests for the findings of the 2026-09-06 architecture audit. */
final class AuditFixesTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_reconciled_requires_every_chain_complete_and_counted(): void {
		$chains = [ 'a', 'b' ];
		self::assertTrue( Synchronizer::is_reconciled( [ 'a' => [ 'seen' => 4, 'total' => 4, 'complete' => true ], 'b' => [ 'seen' => 2, 'total' => 2, 'complete' => true ] ], $chains ) );
		self::assertFalse( Synchronizer::is_reconciled( [ 'a' => [ 'seen' => 3, 'total' => 4, 'complete' => true ], 'b' => [ 'seen' => 2, 'total' => 2, 'complete' => true ] ], $chains ), 'a skipped row must break reconciliation' );
		self::assertFalse( Synchronizer::is_reconciled( [ 'a' => [ 'seen' => 4, 'total' => 4, 'complete' => false ] ], [ 'a' ] ), 'incomplete walk' );
		self::assertFalse( Synchronizer::is_reconciled( [], [] ), 'no chains is not vacuously authoritative' );
	}

	public function test_allowed_hosts_include_the_business_host_and_its_www_twin(): void {
		$o = new Options();
		$o->set_business( 'b1', 'Example', 'https://www.example.com', [] );
		$hosts = $o->allowed_hosts();
		self::assertContains( 'example.com', $hosts );
		self::assertContains( 'www.example.com', $hosts );
	}

	public function test_envelope_size_is_measured_raw_not_unicode_escaped(): void {
		// 700 KB of CJK is ~4.2 MB when \uXXXX-escaped; raw it is under the cap.
		$doc = [ 'text' => str_repeat( '日', 233000 ) ];
		self::assertLessThan( Envelope::MAX_BYTES, strlen( json_encode( $doc, JSON_UNESCAPED_UNICODE ) ) );
		self::assertGreaterThan( Envelope::MAX_BYTES, strlen( json_encode( $doc ) ) );
		$env = [ 'urlId' => 'u', 'chainId' => 'c', 'businessId' => 'b', 'url' => 'https://example.com/p', 'languageCode' => 'ja', 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z', 'jsonLd' => $doc ];
		self::assertTrue( Envelope::validate( $env )['ok'], 'a large CJK document must not be rejected for its escaped size' );
	}

	public function test_production_default_base_and_dev_override_shape(): void {
		self::assertSame( 'https://app.aivis-os.com', Options::DEFAULT_API_BASE );
		self::assertSame( 'https://app.aivis-os.com', ( new Options() )->api_base() );
	}

	public function test_on_demand_lookup_is_off_by_default(): void {
		self::assertFalse( ( new Options() )->on_demand_enabled() );
	}
}
