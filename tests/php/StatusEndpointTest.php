<?php
declare( strict_types=1 );

use AivisOS\Cache\AdapterFactory;
use AivisOS\Plugin;
use AivisOS\Rest\StatusController;
use AivisOS\Rest\StatusDocument;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * §11a — status is stored in the plugin and fetched by AIVIS. Never pushed.
 */
final class StatusEndpointTest extends TestCase {

	private const TOKEN = 'aivis_SECRETTOKENxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

	protected function setUp(): void {
		WPStub::reset();
		update_option( 'aivis_os_token', self::TOKEN );
		( new Options() )->set_business( 'biz_live', 'Example', 'https://example.com', [] );
	}

	private function controller(): StatusController {
		return new StatusController( Plugin::instance() );
	}

	private static function row( int $id, array $o = [] ): array {
		return $o + [
			'id' => $id, 'source_url' => "https://example.com/p{$id}/", 'url_id' => "u{$id}", 'chain_id' => 'chain_de', 'language_code' => 'de',
			'content_hash' => str_repeat( 'a', 64 ), 'source_generated_at' => '2026-09-01 10:00:00', 'source_stale' => 0, 'active' => 1,
			'suspended_at' => null, 'retired_at' => null, 'last_error_code' => null, 'last_synced_at' => '2026-09-06 08:00:00',
			'published_at' => '2026-09-06 08:00:00', 'verified_at' => null, 'verified_hash' => null,
		];
	}

	public function test_key_is_issued_on_demand_and_the_endpoint_is_gated_by_it(): void {
		$o = new Options();
		self::assertSame( '', $o->status_key() );
		$key = $o->ensure_status_key();
		self::assertStringStartsWith( Options::STATUS_KEY_PREFIX, $key );
		self::assertSame( $key, $o->ensure_status_key(), 'stable until regenerated' );
		self::assertNotSame( self::TOKEN, $key );

		$c = $this->controller();
		$r = $c->permission( new WP_REST_Request( [] ) );
		self::assertInstanceOf( WP_Error::class, $r );
		self::assertSame( 401, $r->get_error_data()['status'] );
		self::assertInstanceOf( WP_Error::class, $c->permission( new WP_REST_Request( [ 'authorization' => 'Bearer ' . substr( $key, 0, -1 ) . 'X' ] ) ) );
		self::assertInstanceOf( WP_Error::class, $c->permission( new WP_REST_Request( [ 'authorization' => 'Bearer ' . self::TOKEN ] ) ), 'the API token is not the status key' );
		self::assertTrue( $c->permission( new WP_REST_Request( [ 'authorization' => 'bearer ' . $key ] ) ) );

		$o->disable_status_key();
		self::assertSame( '', $o->status_key() );
		self::assertSame( '', $o->ensure_status_key(), 'disabled stays disabled' );
		$r = $c->permission( new WP_REST_Request( [ 'authorization' => 'Bearer ' . $key ] ) );
		self::assertSame( 404, $r->get_error_data()['status'] );

		$new = $o->regenerate_status_key();
		self::assertNotSame( $key, $new );
		self::assertTrue( $c->permission( new WP_REST_Request( [ 'authorization' => 'Bearer ' . $new ] ) ) );
	}

	public function test_the_document_describes_the_connector_and_never_the_token(): void {
		$o = new Options();
		$o->set_chain_languages( [ 'chain_de' => 'de' ] );
		$at = (int) strtotime( '2026-09-06 08:00:00 UTC' );
		$o->patch_sync_state( [ 'last_complete_at' => $at, 'last_authoritative' => true, 'last_purge' => [ 'state' => 'confirmed', 'at' => $at + 100 ] ] );
		$res = $this->controller()->status( new WP_REST_Request() );
		self::assertSame( 200, $res->status );
		self::assertSame( 'no-store', $res->headers['Cache-Control'] );
		$d = $res->get_data();
		self::assertSame( 'aivis-os', $d['connector'] );
		self::assertSame( StatusDocument::SCHEMA, $d['schema'] );
		self::assertSame( 'example.com', $d['site'] );
		self::assertSame( 'biz_live', $d['businessId'] );
		self::assertTrue( $d['sync']['authoritative'] );
		self::assertSame( '2026-09-06T08:00:00+00:00', $d['sync']['lastCompleteAt'] );
		self::assertSame( 'confirmed', $d['cache']['lastPurge'] );
		self::assertSame( [ 'chain_de' => 'de' ], (array) $d['languages']['chains'] );
		self::assertSame( 'https://example.com/wp-json/aivis-os/v1/status/urls', $d['urls'] );
		$flat = json_encode( $d );
		self::assertStringNotContainsString( self::TOKEN, $flat );
		self::assertStringNotContainsString( 'SECRETTOKEN', $flat );
		foreach ( [ '"ip"', '"userAgent"', '"visitor"', '"email"', '"cookie"' ] as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $flat, "nothing about people: {$forbidden}" );
		}
	}

	public function test_per_url_status_states_and_pagination(): void {
		$GLOBALS['wpdb']->rows = [
			self::row( 1 ),
			self::row( 2, [ 'source_stale' => 1 ] ),
			self::row( 3, [ 'last_error_code' => 'AIVIS_NOT_GENERATED' ] ),
			self::row( 4, [ 'suspended_at' => '2026-09-05 00:00:00', 'last_error_code' => 'AIVIS_RETRACTED' ] ),
			self::row( 5, [ 'active' => 0, 'retired_at' => '2026-09-01 00:00:00', 'last_error_code' => 'AIVIS_RETRACTED' ] ),
			self::row( 6, [ 'active' => 0, 'last_error_code' => 'AIVIS_LANGUAGE_UNASSIGNED' ] ),
			self::row( 7, [ 'verified_at' => '2026-09-06 09:00:00', 'verified_hash' => str_repeat( 'a', 64 ) ] ),
		];
		$res = $this->controller()->urls( new WP_REST_Request( [], [ 'limit' => 6 ] ) );
		$d   = $res->get_data();
		self::assertCount( 6, $d['items'] );
		self::assertTrue( $d['hasMore'] );
		self::assertSame( '6', $d['nextCursor'] );
		self::assertSame( [ 'published', 'stale', 'holding', 'suspended', 'retired', 'inactive' ], array_column( $d['items'], 'state' ) );
		$first = $d['items'][0];
		self::assertSame( 'https://example.com/p1/', $first['url'] );
		self::assertSame( 'u1', $first['urlId'] );
		self::assertSame( 'chain_de', $first['chainId'] );
		self::assertSame( '2026-09-06T08:00:00+00:00', $first['publishedAt'] );
		self::assertNull( $first['verifiedAt'] );
		self::assertNull( $first['errorCode'] );
		self::assertSame( 'AIVIS_NOT_GENERATED', $d['items'][2]['errorCode'] );
		self::assertSame( 'AIVIS_LANGUAGE_UNASSIGNED', $d['items'][5]['errorCode'] );

		// The seventh row: verified, and only reachable via the cursor.
		$GLOBALS['wpdb']->rows = [ self::row( 7, [ 'verified_at' => '2026-09-06 09:00:00', 'verified_hash' => str_repeat( 'a', 64 ) ] ) ];
		$d = $this->controller()->urls( new WP_REST_Request( [], [ 'cursor' => '6', 'limit' => 6 ] ) )->get_data();
		self::assertFalse( $d['hasMore'] );
		self::assertNull( $d['nextCursor'] );
		self::assertSame( '2026-09-06T09:00:00+00:00', $d['items'][0]['verifiedAt'] );
		self::assertStringContainsString( 'WHERE id > 6', $GLOBALS['wpdb']->queries[ count( $GLOBALS['wpdb']->queries ) - 1 ] ?? '', 'cursor is the last id' );
	}

	public function test_published_at_moves_only_when_the_served_content_changes(): void {
		$repo = new Repository();
		$GLOBALS['wpdb']->rows = [];
		$repo->upsert_artifact( [ 'url_key' => 'k', 'source_url' => 'https://example.com/p/', 'url_id' => 'u', 'chain_id' => 'c', 'business_id' => 'b', 'language_code' => 'de', 'source_generated_at' => null, 'source_stale' => false, 'local_post_id' => null, 'sync_id' => 's1' ], '{"a":1}' );
		$first = end( $GLOBALS['wpdb']->writes )[1];
		self::assertNotEmpty( $first['published_at'], 'a new row is published now' );

		// Same content, row already active: published_at is kept.
		$prev = $first + [ 'id' => 1, 'published_at' => '2026-01-01 00:00:00' ];
		$prev['published_at'] = '2026-01-01 00:00:00';
		$GLOBALS['wpdb']->rows = [ $prev ];
		$repo->upsert_artifact( [ 'url_key' => 'k', 'source_url' => 'https://example.com/p/', 'url_id' => 'u', 'chain_id' => 'c', 'business_id' => 'b', 'language_code' => 'de', 'source_generated_at' => null, 'source_stale' => false, 'local_post_id' => null, 'sync_id' => 's2' ], '{"a":1}' );
		self::assertSame( '2026-01-01 00:00:00', end( $GLOBALS['wpdb']->writes )[1]['published_at'] );

		// Changed content: published_at moves.
		$repo->upsert_artifact( [ 'url_key' => 'k', 'source_url' => 'https://example.com/p/', 'url_id' => 'u', 'chain_id' => 'c', 'business_id' => 'b', 'language_code' => 'de', 'source_generated_at' => null, 'source_stale' => false, 'local_post_id' => null, 'sync_id' => 's3' ], '{"a":2}' );
		self::assertNotSame( '2026-01-01 00:00:00', end( $GLOBALS['wpdb']->writes )[1]['published_at'] );

		// Same content but the row was suspended: coming back into service is a publication.
		$GLOBALS['wpdb']->rows = [ $prev + [ 'suspended_at' => '2026-02-01 00:00:00' ] ];
		$GLOBALS['wpdb']->rows[0]['suspended_at'] = '2026-02-01 00:00:00';
		$repo->upsert_artifact( [ 'url_key' => 'k', 'source_url' => 'https://example.com/p/', 'url_id' => 'u', 'chain_id' => 'c', 'business_id' => 'b', 'language_code' => 'de', 'source_generated_at' => null, 'source_stale' => false, 'local_post_id' => null, 'sync_id' => 's4' ], '{"a":1}' );
		self::assertNotSame( '2026-01-01 00:00:00', end( $GLOBALS['wpdb']->writes )[1]['published_at'] );
	}

	public function test_verification_records_what_the_page_actually_served(): void {
		$json = '{"@type":"Thing"}';
		$GLOBALS['wpdb']->rows = [ self::row( 1, [ 'url_key' => 'k1', 'json_ld' => $json, 'content_hash' => hash( 'sha256', $json ) ] ) ];
		WPStub::queue( 200, '<html><head><script type="application/ld+json" data-aivis="1">' . $json . '</script></head></html>', [ 'content-type' => 'text/html' ] );
		$v = ( new Verifier( new Repository(), new Options() ) )->run();
		self::assertSame( 'live', $v['result'] );
		$w = end( $GLOBALS['wpdb']->writes );
		self::assertSame( 'update', $w[0] );
		self::assertSame( hash( 'sha256', $json ), $w[1]['verified_hash'] );
		self::assertNotEmpty( $w[1]['verified_at'] );

		// Stale on the page: nothing is recorded as verified.
		$GLOBALS['wpdb']->writes = [];
		WPStub::queue( 200, '<html><head><script type="application/ld+json" data-aivis="1">{"@type":"Other"}</script></head></html>', [ 'content-type' => 'text/html' ] );
		$v = ( new Verifier( new Repository(), new Options() ) )->run();
		self::assertSame( 'stale-on-page', $v['result'] );
		self::assertSame( [], $GLOBALS['wpdb']->writes );
	}

	public function test_routes_are_registered_and_the_client_has_no_write_path(): void {
		$this->controller()->routes();
		self::assertArrayHasKey( 'aivis-os/v1/status', WPStub::$rest_routes );
		self::assertArrayHasKey( 'aivis-os/v1/status/urls', WPStub::$rest_routes );
		foreach ( WPStub::$rest_routes as $r ) {
			self::assertSame( 'GET', $r['methods'] );
			self::assertIsCallable( $r['permission_callback'] );
		}
		self::assertFalse( method_exists( \AivisOS\Api\Client::class, 'post' ), 'the connector only ever reads from AIVIS' );
		self::assertFalse( method_exists( \AivisOS\Api\Client::class, 'report_status' ) );
		self::assertSame( '', StatusController::bearer( 'Basic abc' ) );
		self::assertSame( 'k', StatusController::bearer( 'BEARER  k ' ) );
	}
}
