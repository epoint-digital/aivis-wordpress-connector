<?php
declare( strict_types=1 );

use AivisOS\Delivery\Gates;
use AivisOS\Security\Serializer;
use AivisOS\Storage\Options;
use PHPUnit\Framework\TestCase;

final class GatesInjectorTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_open_on_a_plain_front_end_get(): void {
		self::assertTrue( ( new Gates( new Options() ) )->open() );
	}

	public function test_closed_when_injection_disabled(): void {
		( new Options() )->set_injection_enabled( false );
		self::assertFalse( ( new Gates( new Options() ) )->open() );
	}

	public function test_closed_for_every_non_page_context(): void {
		foreach ( [ 'is_admin', 'ajax', 'cron', 'feed', 'robots', 'trackback', 'preview', '404', 'embed', 'customize', 'favicon' ] as $flag ) {
			WPStub::$flags = [ $flag => true ];
			self::assertFalse( ( new Gates( new Options() ) )->open(), $flag );
		}
	}

	public function test_closed_for_non_get(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		self::assertFalse( ( new Gates( new Options() ) )->open() );
	}

	public function test_emitted_element_is_exactly_one_marked_script(): void {
		$json = Serializer::serialize( [ '@type' => 'Thing', 'name' => '</script>' ] );
		$tag  = Serializer::script_tag( $json, hash( 'sha256', $json ) );
		self::assertMatchesRegularExpression( '#^<script type="application/ld\+json" data-aivis="1" data-aivis-hash="[a-f0-9]{64}">[^<]*</script>\n$#', $tag );
		self::assertMatchesRegularExpression( '#^<script type="application/ld\+json" data-aivis="1">[^<]*</script>\n$#', Serializer::script_tag( $json ), 'without a hash: marker only' );
	}
}
