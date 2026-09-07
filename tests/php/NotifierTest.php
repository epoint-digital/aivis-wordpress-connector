<?php
declare( strict_types=1 );

use AivisOS\Storage\Options;
use AivisOS\Sync\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_first_authoritative_sync_schedules_one_conflict_scan_and_talks_to_nobody(): void {
		update_option( 'admin_email', 'owner@example.com' );
		$n = new Notifier( new Options() );
		$n->sync_completed( [ 'authoritative' => true ] );
		$n->sync_completed( [ 'authoritative' => true ] );
		$n->sync_completed( [ 'authoritative' => false ] );
		$scans = array_filter( array_keys( WPStub::$scheduled ), static fn( string $k ): bool => str_starts_with( $k, 'aivis_os_scan_conflicts' ) );
		self::assertCount( 1, $scans );
		self::assertSame( [], WPStub::$mail, 'no email: notification policy is AIVIS\'s (WP-I12)' );
		self::assertSame( [], WPStub::$http_log, 'nothing pushed anywhere' );
		self::assertFalse( method_exists( Notifier::class, 'conflicts_changed' ) );
		self::assertFalse( method_exists( Options::class, 'notify_email' ) );
	}
}
