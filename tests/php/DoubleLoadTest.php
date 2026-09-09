<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * #70 — a second copy of the plugin in another folder (a GitHub "Download
 * ZIP" lands as aivis-wordpress-connector-main) must not load twice.
 */
final class DoubleLoadTest extends TestCase {

	public function test_a_second_copy_returns_before_defining_anything(): void {
		// The first copy is already loaded: its file constant exists.
		define( 'AIVIS_OS_FILE', '/srv/site/wp-content/plugins/aivis-os/aivis-os.php' );
		$second = dirname( __DIR__, 2 ) . '/aivis-os.php';
		// The test bootstrap already defines every AIVIS_OS_* constant, so a copy
		// that ran past the guard would redefine them — PHP raises a warning,
		// which PHPUnit turns into a test error. Reaching the assertion at all
		// proves the early return; the null return value proves it was a
		// top-level `return;` rather than the end of the file (which yields 1).
		$result = include $second;
		self::assertNull( $result, 'a top-level `return;` makes include yield null; running to the end would yield 1' );
	}
}
