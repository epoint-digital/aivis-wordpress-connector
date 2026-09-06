<?php
/**
 * §09 — wp_head priority 100. One indexed query, one script element, fail
 * open on absolutely anything (WP-I1, WP-I2, WP-I4).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

use AivisOS\Domain\UrlKey;
use AivisOS\Security\Serializer;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;

final class Injector {

	private static bool $emitted = false;

	public function __construct(
		private readonly Repository $repository,
		private readonly Options $options
	) {}

	public function render(): void {
		if ( self::$emitted ) {
			return;
		}
		try {
			$out = $this->markup();
			if ( null !== $out ) {
				self::$emitted = true;
				echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ZT-05 output, escaped by construction.
			}
		} catch ( \Throwable ) {
			// Fail open: the page is never the place a connector error shows.
			return;
		}
	}

	/** The markup for the current request, or null when nothing should be injected. */
	public function markup(): ?string {
		if ( ! ( new Gates( $this->options ) )->open() ) {
			return null;
		}
		$url = ( new UrlResolver() )->current();
		if ( null === $url ) {
			return null;
		}
		$row = $this->repository->find_active_for_render( UrlKey::of( $url ) );
		if ( null === $row ) {
			return null;
		}
		// Integrity: the stored bytes must still hash to what we recorded. A
		// mismatch means the row was edited outside the plugin — do not serve it.
		if ( ! hash_equals( (string) $row['content_hash'], hash( 'sha256', (string) $row['json_ld'] ) ) ) {
			return null;
		}
		// Last line of defence, independent of storage: the forbidden characters
		// cannot be in what we are about to print.
		foreach ( Serializer::FORBIDDEN as $c ) {
			if ( str_contains( (string) $row['json_ld'], $c ) ) {
				return null;
			}
		}
		return Serializer::script_tag( (string) $row['json_ld'] );
	}

	/** Test seam. */
	public static function reset(): void {
		self::$emitted = false;
	}
}
