<?php
declare( strict_types=1 );

use AivisOS\Api\Client;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		update_option( 'aivis_os_token', 'aivis_' . str_repeat( 'x', 43 ) );
		update_option( 'admin_email', 'owner@example.com' );
		( new Options() )->set_business( 'biz_live', 'Example', 'https://example.com', [] );
	}

	private function notifier(): Notifier {
		$o = new Options();
		return new Notifier( $o, new Client( $o ), new Repository(), new AdapterFactory( $o ) );
	}

	public function test_report_carries_connector_state_and_nothing_about_people(): void {
		$r = $this->notifier()->build_report( 'sync' );
		self::assertSame( 'aivis-os', $r['connector'] );
		self::assertSame( 'example.com', $r['site'] );
		self::assertSame( 'biz_live', $r['businessId'] );
		foreach ( [ 'active', 'stale', 'hold', 'suspended', 'retired', 'total' ] as $k ) {
			self::assertArrayHasKey( $k, $r['sync']['counts'] );
		}
		$flat = strtolower( json_encode( $r ) );
		foreach ( [ 'ip', 'user_agent', 'useragent', 'visitor', 'email', 'wp_user', 'cookie' ] as $forbidden ) {
			self::assertStringNotContainsString( '"' . $forbidden . '"', $flat, "report must not carry {$forbidden}" );
		}
	}

	public function test_report_is_posted_with_the_transport_rules(): void {
		WPStub::queue( 202, [ 'accepted' => true ] );
		$this->notifier()->report( 'sync' );
		[ $url, $args ] = WPStub::$http_log[0];
		self::assertStringEndsWith( '/api/public/v1/businesses/biz_live/connector-status', $url );
		self::assertSame( 'POST', $args['_method'] );
		self::assertSame( 0, $args['redirection'] );
		self::assertSame( 'application/json', $args['headers']['Content-Type'] );
		self::assertStringStartsWith( 'Bearer aivis_', $args['headers']['Authorization'] );
		self::assertSame( 202, ( new Options() )->sync_state()['last_report']['status'] );
	}

	public function test_missing_endpoint_backs_off_for_a_day(): void {
		WPStub::queue( 404, [ 'error' => [ 'message' => 'Not found' ] ] );
		$this->notifier()->report( 'sync' );
		self::assertNotFalse( get_transient( 'aivis_os_report_unavailable' ) );
		$this->notifier()->report( 'sync' );
		self::assertCount( 1, WPStub::$http_log, 'no second request while backed off' );
	}

	public function test_opt_out_sends_nothing(): void {
		( new Options() )->set_report_to_aivis( false );
		$this->notifier()->report( 'sync' );
		self::assertCount( 0, WPStub::$http_log );
	}

	public function test_conflict_change_emails_the_admin(): void {
		WPStub::queue( 404, [] );
		$o = new Options();
		$o->patch_conflicts( [ 'fingerprint' => 'f1', 'pages_scanned' => 5, 'items' => [ 'https://example.com/p' => [ 'sources' => [ 'yoast' ], 'types' => [ 'Organization' ], 'blocks' => 1 ] ] ] );
		$this->notifier()->conflicts_changed( $o->conflicts() );
		self::assertCount( 1, WPStub::$mail );
		self::assertSame( 'owner@example.com', WPStub::$mail[0]['to'] );
		self::assertStringContainsString( 'Yoast SEO', WPStub::$mail[0]['message'] );
		self::assertStringContainsString( 'Publishing continues', WPStub::$mail[0]['message'] );
	}
}
