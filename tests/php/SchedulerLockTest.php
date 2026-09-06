<?php
declare( strict_types=1 );

use AivisOS\Storage\Options;
use AivisOS\Sync\Lock;
use AivisOS\Sync\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerLockTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_recurrence_mapping(): void {
		Scheduler::reschedule_sync( 300 );
		self::assertSame( 'aivis_os_5min', WPStub::$scheduled['aivis_os_sync'][1] );
		Scheduler::reschedule_sync( 900 );
		self::assertSame( 'aivis_os_15min', WPStub::$scheduled['aivis_os_sync'][1] );
		Scheduler::reschedule_sync( 3600 );
		self::assertSame( 'hourly', WPStub::$scheduled['aivis_os_sync'][1] );
		Scheduler::reschedule_sync( 0 );
		self::assertArrayNotHasKey( 'aivis_os_sync', WPStub::$scheduled );
	}

	public function test_lock_is_exclusive_then_recovers_when_stale(): void {
		$o = new Options();
		$l = new Lock( $o );
		self::assertTrue( $l->acquire( 'a' ) );
		self::assertFalse( $l->acquire( 'b' ), 'second acquire must fail while held' );
		$o->patch_sync_state( [ 'lock' => [ 'owner' => 'a', 'at' => time() - Lock::STALE_AFTER - 1 ] ] );
		self::assertTrue( $l->acquire( 'b' ), 'stale lock must be recoverable' );
		self::assertContains( 'AIVIS_LOCK_RECOVERED', array_column( $o->diagnostics(), 'code' ), 'recovery must be recorded' );
		$l->release();
		self::assertFalse( $l->held() );
	}

	public function test_token_never_reaches_diagnostics(): void {
		$o = new Options();
		$o->record( 'X', 'failed with Bearer aivis_' . str_repeat( 'q', 43 ) );
		self::assertStringNotContainsString( 'qqqq', $o->diagnostics()[0]['message'] );
	}
}
