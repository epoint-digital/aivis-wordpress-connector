<?php
declare( strict_types=1 );

use AivisOS\Storage\Options;
use AivisOS\Sync\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		update_option( 'aivis_os_token', 'aivis_' . str_repeat( 'x', 43 ) );
		update_option( 'admin_email', 'owner@example.com' );
		( new Options() )->set_business( 'biz_live', 'Example', 'https://example.com', [] );
	}

	public function test_conflict_change_emails_the_admin_and_sends_nothing_to_aivis(): void {
		$o = new Options();
		$o->patch_conflicts( [ 'fingerprint' => 'f1', 'pages_scanned' => 5, 'items' => [ 'https://example.com/p' => [ 'sources' => [ 'yoast' ], 'types' => [ 'Organization' ], 'blocks' => 1 ] ] ] );
		( new Notifier( $o ) )->conflicts_changed( $o->conflicts() );
		self::assertCount( 1, WPStub::$mail );
		self::assertSame( 'owner@example.com', WPStub::$mail[0]['to'] );
		self::assertStringContainsString( 'Yoast SEO', WPStub::$mail[0]['message'] );
		self::assertStringContainsString( 'Publishing continues', WPStub::$mail[0]['message'] );
		self::assertStringContainsString( 'never changes another plugin', WPStub::$mail[0]['message'] );
		self::assertSame( [], WPStub::$http_log, 'nothing is pushed to AIVIS — ever (WP-I9, §11a)' );
	}

	public function test_email_opt_out(): void {
		$o = new Options();
		$o->set_notify_email( false );
		$o->patch_conflicts( [ 'fingerprint' => 'f1', 'items' => [ 'https://example.com/p' => [ 'sources' => [ 'yoast' ], 'types' => [], 'blocks' => 1 ] ] ] );
		( new Notifier( $o ) )->conflicts_changed( $o->conflicts() );
		self::assertSame( [], WPStub::$mail );
	}

	public function test_first_authoritative_sync_schedules_one_conflict_scan(): void {
		$n = new Notifier( new Options() );
		$n->sync_completed( [ 'authoritative' => true ] );
		$n->sync_completed( [ 'authoritative' => true ] );
		$scans = array_filter( array_keys( WPStub::$scheduled ), static fn( string $k ): bool => str_starts_with( $k, 'aivis_os_scan_conflicts' ) );
		self::assertCount( 1, $scans );
		self::assertSame( [], WPStub::$http_log );
	}
}
