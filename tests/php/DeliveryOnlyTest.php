<?php
declare( strict_types=1 );

use AivisOS\Admin\ObjectBox;
use AivisOS\Admin\SettingsPage;
use AivisOS\Cache\AdapterFactory;
use AivisOS\Cache\Adapters\WpRocket;
use AivisOS\Delivery\Language;
use AivisOS\Delivery\Markup;
use AivisOS\Delivery\ObjectResolver;
use AivisOS\Plugin;
use AivisOS\Rest\StatusDocument;
use AivisOS\Security\Serializer;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Verifier;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'rocket_clean_files' ) ) {
	function rocket_clean_files( array $urls ): void { WPStub::$flags['rocket'][] = $urls; }
	function rocket_clean_domain(): void { WPStub::$flags['rocket_domain'] = true; }
}

/**
 * WP-I12 — delivery, not judgment: the items merged from Norbert's plugin
 * (#65/#66) as facts and mechanics, and the email policy removed.
 */
final class DeliveryOnlyTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		Language::$force_provider = Language::CORE;
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		( new Options() )->set_business( 'biz', 'Example', 'https://example.com', [] );
	}

	/* T1 */
	public function test_wp_rocket_purges_and_is_detected_before_manual(): void {
		$a = new WpRocket();
		self::assertTrue( $a->is_available() );
		$r = $a->purge_urls( [ 'https://example.com/a/', 'https://example.com/b/' ] );
		self::assertSame( 'confirmed', $r->state );
		self::assertSame( [ [ 'https://example.com/a/', 'https://example.com/b/' ] ], WPStub::$flags['rocket'] );
		self::assertSame( 'wp-rocket', ( new AdapterFactory( new Options() ) )->adapter()->id(), 'auto-detected (no other cache in the test process)' );
		self::assertFalse( AdapterFactory::cloudflare_detected() );
		$_SERVER['HTTP_CF_RAY'] = 'abc';
		self::assertTrue( AdapterFactory::cloudflare_detected() );
		unset( $_SERVER['HTTP_CF_RAY'] );
	}

	/* T2 */
	public function test_script_element_carries_the_content_hash_and_the_marker(): void {
		$json = Serializer::serialize( [ '@type' => 'Thing', 'x' => '</script>' ] );
		$hash = hash( 'sha256', $json );
		$tag  = Serializer::script_tag( $json, strtoupper( $hash ) );
		self::assertStringContainsString( 'data-aivis="1"', $tag );
		self::assertStringContainsString( 'data-aivis-hash="' . $hash . '"', $tag, 'lower-cased' );
		self::assertStringNotContainsString( 'data-aivis-hash', Serializer::script_tag( $json, 'not-a-hash' ), 'anything but a full sha256 is dropped, never printed' );
		self::assertSame( $hash, Markup::aivis_hash( '<html><head>' . $tag . '</head></html>' ) );
		self::assertNull( Markup::aivis_hash( '<!-- ' . $tag . ' -->' ) );
		self::assertSame( $json, Markup::aivis_block( $tag ), 'the extra attribute does not break block detection' );
		self::assertStringNotContainsString( 'data-aivis-hash', Serializer::script_tag( $json ), 'no hash, no attribute' );
	}

	/* A1 */
	public function test_object_resolver_finds_posts_archives_and_terms_and_their_current_address(): void {
		WPStub::$post_ids['https://example.com/hello/'] = 7;
		WPStub::$objects = [
			'posts'      => [ 7 => 'https://example.com/hello/' ],
			'archives'   => [ 'product' => 'https://example.com/products/' ],
			'terms'      => [ 3 => [ 'category', 'news', 'https://example.com/category/news/' ] ],
			'taxonomies' => [ 'category' => 'category', 'post_tag' => 'tag' ],
		];
		self::assertSame( [ 'type' => 'post', 'id' => '7' ], ObjectResolver::for_url( 'https://example.com/hello/' ) );
		self::assertSame( [ 'type' => 'archive', 'id' => 'product' ], ObjectResolver::for_url( 'https://example.com/products' ) );
		self::assertSame( [ 'type' => 'term', 'id' => '3' ], ObjectResolver::for_url( 'https://example.com/category/news/' ) );
		self::assertNull( ObjectResolver::for_url( 'https://example.com/tag/news/' ), 'right slug, wrong taxonomy path: no guess' );
		self::assertNull( ObjectResolver::for_url( 'https://example.com/unknown/' ) );
		self::assertSame( 'https://example.com/hello/', ObjectResolver::url_of( 'post', '7' ) );
		self::assertNull( ObjectResolver::url_of( 'post', '99' ) );
	}

	/* A2 (report only) */
	public function test_moved_pages_are_reported_with_their_first_seen_time_and_nothing_else_happens(): void {
		$o = new Options();
		WPStub::$objects['posts'] = [ 7 => 'https://example.com/hello-new/', 8 => 'https://example.com/same/' ];
		$GLOBALS['wpdb']->rows = [
			[ 'url_key' => 'k7', 'source_url' => 'https://example.com/hello/', 'object_type' => 'post', 'object_id' => '7' ],
			[ 'url_key' => 'k8', 'source_url' => 'https://example.com/same', 'object_type' => 'post', 'object_id' => '8' ],
			[ 'url_key' => 'k9', 'source_url' => 'https://example.com/gone/', 'object_type' => 'post', 'object_id' => '9' ],
		];
		$o->patch_sync_state( [ 'moved' => [ 'k7' => [ 'from' => 'https://example.com/hello/', 'to' => 'https://example.com/hello-old-new/', 'since' => 1000 ] ] ] );
		$r = ( new Verifier( new Repository(), $o ) )->check_moved();
		self::assertSame( [ 'checked' => 3, 'moved' => 1 ], $r );
		$moved = $o->sync_state()['moved'];
		self::assertSame( [ 'k7' ], array_keys( $moved ), 'trailing slash is not a move; a vanished object is not a move' );
		self::assertSame( 'https://example.com/hello-new/', $moved['k7']['to'] );
		self::assertSame( 1000, $moved['k7']['since'], 'first-seen time is kept across checks' );
		self::assertSame( [], $GLOBALS['wpdb']->writes, 'no row is changed: the report is the whole action' );
		self::assertNotContains( 'AIVIS_URL_MOVED', array_column( $o->diagnostics(), 'code' ), 'already known: no new diagnostic' );

		// The status document carries it, per page and as a list.
		$GLOBALS['wpdb']->rows = [ [ 'id' => 1, 'url_key' => 'k7', 'source_url' => 'https://example.com/hello/', 'url_id' => 'u', 'chain_id' => 'c', 'language_code' => 'de', 'content_hash' => str_repeat( 'a', 64 ), 'source_generated_at' => null, 'source_stale' => 0, 'active' => 1, 'suspended_at' => null, 'retired_at' => null, 'last_error_code' => null, 'last_synced_at' => null, 'published_at' => null, 'verified_at' => null, 'verified_hash' => null, 'object_type' => 'post', 'object_id' => '7' ] ];
		$doc = new StatusDocument( $o, new Repository(), new AdapterFactory( $o ) );
		self::assertSame( 'https://example.com/hello-new/', $doc->urls()['items'][0]['currentUrl'] );
		self::assertSame( 'post', $doc->urls()['items'][0]['objectType'] );
		self::assertSame( [ [ 'url' => 'https://example.com/hello/', 'currentUrl' => 'https://example.com/hello-new/', 'since' => gmdate( 'c', 1000 ) ] ], $doc->build()['delivery']['moved'] );
	}

	/* T4 */
	public function test_edit_screen_box_shows_facts_and_offers_nothing_to_click(): void {
		$box = new ObjectBox( Plugin::instance() );
		$GLOBALS['wpdb']->rows = [];
		self::assertStringContainsString( 'Not managed by AIVIS', $box->render( 'post', '7' ) );
		$GLOBALS['wpdb']->rows = [ [ 'url_key' => 'k7', 'source_url' => 'https://example.com/hello/', 'content_hash' => str_repeat( 'b', 64 ), 'source_generated_at' => '2026-09-01 10:00:00', 'published_at' => '2026-09-06 08:00:00', 'verified_at' => null, 'active' => 1, 'suspended_at' => null, 'retired_at' => null, 'last_error_code' => null, 'source_stale' => 0 ] ];
		( new Options() )->patch_sync_state( [ 'moved' => [ 'k7' => [ 'from' => 'https://example.com/hello/', 'to' => 'https://example.com/hello-new/', 'since' => 1 ] ] ] );
		$html = $box->render( 'post', '7' );
		self::assertStringContainsString( 'Injected', $html );
		self::assertStringContainsString( 'bbbbbbbbbbbb…', $html );
		self::assertStringContainsString( 'This page moved since AIVIS crawled it.', $html );
		self::assertStringContainsString( 'page=aivis-os', $html );
		self::assertStringNotContainsString( '<input', $html, 'read-only: no controls' );
		self::assertStringNotContainsString( '<button', $html );
		$box->add_boxes();
		self::assertSame( 'aivis-os', WPStub::$flags['meta_boxes'][0][0] );
	}

	/* removed: email policy */
	public function test_settings_have_no_email_switch_and_saving_sends_no_mail(): void {
		( new SettingsPage( Plugin::instance() ) )->save( [ 'injection' => '1', 'interval' => '900', 'cache_adapter' => 'auto', 'notify_email' => '1' ] );
		self::assertArrayNotHasKey( 'notify_email', (array) get_option( 'aivis_os_delivery', [] ) );
		self::assertSame( [], WPStub::$mail );
	}
}
