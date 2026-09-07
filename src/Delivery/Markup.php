<?php
/**
 * Reading our own block back out of a page. Shared by the live verification
 * and the conflict scan so both apply the same rule (#59): the block counts
 * only as a real, uncommented script element whose type is JSON-LD and
 * which carries the data-aivis marker — never as two substrings that happen
 * to occur somewhere in the HTML.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

final class Markup {

	/** HTML comments cannot contain live markup; drop them before looking. */
	public static function strip_comments( string $html ): string {
		return (string) preg_replace( '/<!--.*?-->/s', '', $html );
	}

	/**
	 * The content of the first genuine AIVIS JSON-LD element, or null when
	 * the page has none. Untrimmed: the stored bytes are compared exactly.
	 */
	public static function aivis_block( string $html ): ?string {
		$clean = self::strip_comments( $html );
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $clean, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $m as $hit ) {
			$attrs = $hit[1];
			if ( ! preg_match( '#\btype\s*=\s*["\']?\s*application/ld\+json#i', $attrs ) ) {
				continue;
			}
			if ( ! preg_match( '#\bdata-aivis\s*=\s*["\']?1["\']?#i', $attrs ) ) {
				continue;
			}
			return $hit[2];
		}
		return null;
	}

	/**
	 * Does the page serve exactly these bytes as its AIVIS block?
	 * `present` distinguishes "block missing" from "block differs".
	 *
	 * @return array{present:bool, current:bool}
	 */
	public static function serves( string $html, string $stored ): array {
		$block = self::aivis_block( $html );
		if ( null === $block ) {
			return [ 'present' => false, 'current' => false ];
		}
		return [ 'present' => true, 'current' => $block === $stored ];
	}
}
