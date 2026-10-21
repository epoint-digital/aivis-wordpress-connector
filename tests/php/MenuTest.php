<?php
declare( strict_types=1 );

use AivisOS\Admin\Menu;
use AivisOS\Plugin;
use PHPUnit\Framework\TestCase;

/** #74 — the Status screen rendered twice: two callbacks on one page hook. */
final class MenuTest extends TestCase {

	public function test_the_status_submenu_carries_no_second_callback_for_the_shared_slug(): void {
		WPStub::reset();
		( new Menu( Plugin::instance() ) )->menu();
		$regs = (array) ( WPStub::$flags['menu'] ?? [] );
		$status = array_values( array_filter( $regs, static fn( array $r ): bool => Menu::SLUG_STATUS === $r[1] ) );
		self::assertCount( 2, $status, 'top-level entry plus its "Status" submenu, same slug' );
		$with_callback = array_filter( $status, static fn( array $r ): bool => '' !== $r[2] && null !== $r[2] );
		self::assertCount( 1, $with_callback, 'exactly one callable serves the shared page hook — a second one renders the screen twice' );
		self::assertSame( 'top', reset( $with_callback )[0], 'the top-level registration is the one that renders' );
		foreach ( [ Menu::SLUG_PAGES, Menu::SLUG_SETTINGS ] as $slug ) {
			$own = array_values( array_filter( $regs, static fn( array $r ): bool => $slug === $r[1] ) );
			self::assertCount( 1, $own, $slug );
			self::assertIsCallable( $own[0][2], $slug . ' has its own callback' );
		}
	}
}
