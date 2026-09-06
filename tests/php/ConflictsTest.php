<?php
declare( strict_types=1 );

use AivisOS\Delivery\Conflicts;
use AivisOS\Storage\Options;
use PHPUnit\Framework\TestCase;

final class ConflictsTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	private const PAGE = <<<'HTML'
<html><head>
<script type="application/ld+json" data-aivis="1">{"@context":"https://schema.org","@type":"WebPage","name":"ours"}</script>
<script type="application/ld+json" class="yoast-schema-graph">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"X"},{"@type":"WebSite"}]}</script>
<!-- Rank Math -->
<script type="application/ld+json" class="rank-math-schema">{"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem"}]}</script>
<script type='application/ld+json'>{"@type":"Product","name":"hand-written"}</script>
<script type="text/javascript">var x = 1;</script>
</head><body></body></html>
HTML;

	public function test_scan_finds_foreign_blocks_and_ignores_ours(): void {
		$r = Conflicts::scan_html( self::PAGE );
		self::assertSame( 3, $r['blocks'], 'our own block must not count' );
		self::assertSame( [ 'yoast', 'rank-math', 'unknown' ], $r['sources'] );
		foreach ( [ 'Organization', 'WebSite', 'BreadcrumbList', 'ListItem', 'Product' ] as $t ) {
			self::assertContains( $t, $r['types'] );
		}
		self::assertNotContains( 'WebPage', $r['types'], 'types from our own block must not be reported' );
	}

	public function test_scan_on_a_clean_page_is_empty(): void {
		$r = Conflicts::scan_html( '<html><head><script type="application/ld+json" data-aivis="1">{}</script></head></html>' );
		self::assertSame( 0, $r['blocks'] );
	}

	public function test_fingerprint_is_order_independent_and_change_sensitive(): void {
		$a = [ 'https://e.com/a' => [ 'sources' => [ 'yoast' ], 'types' => [ 'WebSite', 'Organization' ], 'blocks' => 1 ], 'https://e.com/b' => [ 'sources' => [ 'unknown' ], 'types' => [ 'Product' ], 'blocks' => 1 ] ];
		$b = [ 'https://e.com/b' => [ 'sources' => [ 'unknown' ], 'types' => [ 'Product' ], 'blocks' => 1 ], 'https://e.com/a' => [ 'sources' => [ 'yoast' ], 'types' => [ 'Organization', 'WebSite' ], 'blocks' => 1 ] ];
		self::assertSame( Conflicts::fingerprint( $a ), Conflicts::fingerprint( $b ) );
		$c = $a;
		$c['https://e.com/a']['sources'] = [ 'rank-math' ];
		self::assertNotSame( Conflicts::fingerprint( $a ), Conflicts::fingerprint( $c ) );
		self::assertSame( '', Conflicts::fingerprint( [] ) );
	}

	public function test_active_plugin_detection(): void {
		update_option( 'active_plugins', [ 'wordpress-seo/wp-seo.php', 'akismet/akismet.php' ] );
		self::assertSame( [ 'yoast' ], Conflicts::active_plugins() );
	}

	public function test_the_connector_never_alters_another_plugin(): void {
		// Decision 2026-09-06 (#38): warnings only. Every known source carries
		// guidance for the admin, and touching the registry registers nothing.
		foreach ( Conflicts::registry() as $key => $e ) {
			self::assertNotEmpty( $e['how'], $key );
			self::assertArrayNotHasKey( 'filter', $e, $key );
		}
		self::assertSame( [], WPStub::$filters, 'no filter may be registered on behalf of the admin' );
		self::assertFalse( method_exists( Conflicts::class, 'apply_suppressions' ) );
	}

	public function test_override_silences_until_the_set_changes(): void {
		$o = new Options();
		$o->patch_conflicts( [ 'fingerprint' => 'abc', 'items' => [ 'u' => [] ] ] );
		self::assertTrue( $o->conflicts_unacknowledged() );
		$o->acknowledge_conflicts();
		self::assertFalse( $o->conflicts_unacknowledged(), 'override recorded' );
		$o->patch_conflicts( [ 'fingerprint' => 'def' ] );
		self::assertTrue( $o->conflicts_unacknowledged(), 'a changed set re-arms the warning' );
	}
}
