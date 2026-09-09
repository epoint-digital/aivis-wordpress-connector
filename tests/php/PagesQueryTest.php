<?php
declare( strict_types=1 );

use AivisOS\Admin\Menu;
use AivisOS\Admin\PagesQuery;
use AivisOS\Plugin;
use AivisOS\Storage\Repository;
use PHPUnit\Framework\TestCase;

/** The Pages screen's query layer and bulk handling (#67). */
final class PagesQueryTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_request_parsing_is_whitelisted_and_bounded(): void {
		$f = PagesQuery::from_request( [ 'state' => 'SUSPENDED', 'language' => 'DE', 'chain' => ' c1 ', 's' => ' foo ', 'paged' => '3', 'orderby' => 'published_at', 'order' => 'desc' ], 25 );
		self::assertSame( [ 'state' => 'suspended', 'language' => 'de', 'chain' => 'c1', 'search' => 'foo', 'page' => 3, 'per_page' => 25, 'orderby' => 'published_at', 'order' => 'DESC' ], $f );
		$g = PagesQuery::from_request( [ 'state' => 'bogus', 'orderby' => 'id; DROP', 'order' => 'sideways', 'paged' => '-4' ], 9999 );
		self::assertSame( '', $g['state'] );
		self::assertSame( 'source_url', $g['orderby'] );
		self::assertSame( 'ASC', $g['order'] );
		self::assertSame( 1, $g['page'] );
		self::assertSame( PagesQuery::PER_PAGE_MAX, $g['per_page'] );
		self::assertSame( PagesQuery::PER_PAGE_DEFAULT, PagesQuery::from_request( [] )['per_page'] );
	}

	public function test_where_clauses_per_state(): void {
		$base = [ 'language' => '', 'chain' => '', 'search' => '' ];
		self::assertSame( '1=1', PagesQuery::where( $base + [ 'state' => '' ] )['sql'] );
		self::assertSame( '(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND last_error_code IS NULL AND source_stale = 0)', PagesQuery::where( $base + [ 'state' => 'active' ] )['sql'] );
		self::assertSame( '(suspended_at IS NOT NULL AND retired_at IS NULL)', PagesQuery::where( $base + [ 'state' => 'suspended' ] )['sql'] );
		self::assertSame( '(active = 0 AND retired_at IS NULL)', PagesQuery::where( $base + [ 'state' => 'inactive' ] )['sql'] );
		$m = PagesQuery::where( $base + [ 'state' => 'moved' ], [ 'k1', 'k2' ] );
		self::assertSame( 'url_key IN (%s,%s)', $m['sql'] );
		self::assertSame( [ 'k1', 'k2' ], $m['args'] );
		self::assertSame( 'url_key IN (1=0)', PagesQuery::where( $base + [ 'state' => 'moved' ] )['sql'], 'no moved pages: matches nothing, never everything' );
		$c = PagesQuery::where( $base + [ 'state' => 'conflict' ], [], [ 'https://example.com/a/' ] );
		self::assertSame( 'source_url IN (%s)', $c['sql'] );
	}

	public function test_attention_view_is_the_union_of_what_needs_a_human(): void {
		$w = PagesQuery::where( [ 'state' => 'attention', 'language' => '', 'chain' => '', 'search' => '' ], [ 'k1' ], [ 'https://example.com/x/' ] );
		self::assertStringContainsString( 'suspended_at IS NOT NULL AND retired_at IS NULL', $w['sql'] );
		self::assertStringContainsString( 'last_error_code IS NOT NULL', $w['sql'] );
		self::assertStringContainsString( 'active = 0 AND retired_at IS NULL', $w['sql'] );
		self::assertStringContainsString( 'url_key IN (%s)', $w['sql'] );
		self::assertStringContainsString( 'source_url IN (%s)', $w['sql'] );
		self::assertSame( [ 'k1', 'https://example.com/x/' ], $w['args'] );
		self::assertSame( 4, substr_count( $w['sql'], ' OR ' ) );
		$none = PagesQuery::where( [ 'state' => 'attention', 'language' => '', 'chain' => '', 'search' => '' ] );
		self::assertStringNotContainsString( 'IN (', $none['sql'], 'without moved or conflict sets the union has three members' );
	}

	public function test_filters_and_search_compose_and_search_escapes_like_wildcards(): void {
		$w = PagesQuery::where( [ 'state' => 'stale', 'language' => 'de', 'chain' => 'c1', 'search' => '50%_off' ] );
		self::assertSame( '(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND source_stale = 1) AND language_code = %s AND chain_id = %s AND source_url LIKE %s', $w['sql'] );
		self::assertSame( [ 'de', 'c1', '%50\\%\\_off%' ], $w['args'] );
	}

	public function test_repository_search_pages_and_counts(): void {
		$GLOBALS['wpdb']->rows = [ [ 'id' => 1, 'source_url' => 'https://example.com/a/' ] ];
		$GLOBALS['wpdb']->vars = [ '321' ];
		$r = ( new Repository() )->search( 'language_code = %s', [ 'de' ], 50, 3, 'published_at', 'desc' );
		self::assertSame( 321, $r['total'] );
		self::assertCount( 1, $r['rows'] );
		$sql = end( $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( "WHERE language_code = 'de' ORDER BY published_at DESC, id ASC LIMIT 50 OFFSET 100", $sql );
		( new Repository() )->search( '1=1', [], 5000, 0, 'id; DROP TABLE x', 'up' );
		self::assertStringContainsString( 'ORDER BY idx ASC, id ASC LIMIT 500 OFFSET 0', end( $GLOBALS['wpdb']->queries ), 'orderby is reduced to lower-case letters and underscores, per-page capped, page floored' );
	}

	public function test_state_chips_speak_the_status_document_vocabulary(): void {
		// #71: PagesTable labels rows with StatusDocument::state_of() — published /
		// holding / inactive / retired — and the chip renderer fell back to "Retired"
		// for anything it did not know, so every served page read "Retired".
		foreach ( [ 'published' => 'Active', 'active' => 'Active', 'stale' => 'Stale', 'holding' => 'Holding last good', 'hold' => 'Holding last good', 'suspended' => 'Suspended', 'inactive' => 'Not injected', 'retired' => 'Retired' ] as $state => $label ) {
			self::assertStringContainsString( '>' . $label . '<', \AivisOS\Admin\StatusPage::chip_for( $state ), $state );
		}
		self::assertStringContainsString( '>Active<', \AivisOS\Admin\StatusPage::chip_for( \AivisOS\Rest\StatusDocument::state_of( [ 'retired_at' => null, 'active' => 1, 'suspended_at' => null, 'last_error_code' => null, 'source_stale' => 0 ] ) ), 'a healthy row, end to end' );
		self::assertStringNotContainsString( 'Retired', \AivisOS\Admin\StatusPage::chip_for( 'something_new' ), 'an unknown state is never called Retired' );
	}

	public function test_bulk_keys_are_validated_and_capped(): void {
		$good = str_repeat( 'a', 64 );
		$keys = PagesQuery::bulk_keys( [ $good, 'not-a-key', $good, str_repeat( 'b', 64 ), "' OR 1=1" ] );
		self::assertSame( [ $good, str_repeat( 'b', 64 ) ], $keys, 'only sha256-shaped keys, deduplicated' );
		$many = array_map( static fn( int $i ): string => str_pad( dechex( $i ), 64, '0', STR_PAD_LEFT ), range( 1, PagesQuery::BULK_MAX + 50 ) );
		self::assertCount( PagesQuery::BULK_MAX, PagesQuery::bulk_keys( $many ) );
	}

	public function test_bulk_actions_touch_only_selected_rows_and_purge(): void {
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$menu = new Menu( Plugin::instance() );
		self::assertSame( 'nothing_selected', $menu->bulk( 'disable', [] ) );
		$GLOBALS['wpdb']->rows = [ [ 'source_url' => 'https://example.com/a/' ], [ 'source_url' => 'https://example.com/b/' ] ];
		$k1 = str_repeat( '1', 64 );
		$k2 = str_repeat( '2', 64 );
		self::assertSame( 'bulk_disabled', $menu->bulk( 'disable', [ $k1, $k2 ] ) );
		$retired = array_filter( $GLOBALS['wpdb']->writes, static fn( array $w ): bool => 'update' === $w[0] && isset( $w[1]['retired_at'] ) && 'AIVIS_ADMIN_DISABLED' === ( $w[1]['last_error_code'] ?? '' ) );
		self::assertCount( 2, $retired );
		self::assertSame( 2, ( new \AivisOS\Storage\Options() )->sync_state()['last_purge']['count'], 'both pages purged' );
		$GLOBALS['wpdb']->writes = [];
		self::assertSame( 'bulk_refresh', $menu->bulk( 'refresh', [ $k1 ] ) );
		$lookups = array_filter( array_keys( WPStub::$scheduled ), static fn( string $k ): bool => str_starts_with( $k, 'aivis_os_lookup' ) );
		self::assertCount( 2, $lookups, 'one lookup per selected URL (the stub returns both rows)' );
		self::assertSame( [], $GLOBALS['wpdb']->writes, 'refresh writes nothing itself' );
		self::assertSame( 'nothing_selected', $menu->bulk( 'explode', [ $k1 ] ), 'unknown actions do nothing' );
	}
}
