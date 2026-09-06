<?php
/**
 * §07 — the local index key. NOT what goes on the wire: outbound lookups send
 * the permalink unmodified, because AIVIS applies no normalization on purpose.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Domain;

final class UrlKey {

	private const TRACKING = [ '/^utm_/i', '/^gclid$/i', '/^fbclid$/i', '/^msclkid$/i', '/^_ga$/i' ];

	/**
	 * Normalise for the local index. Throws on anything that is not http(s).
	 */
	public static function normalize( string $raw ): string {
		$p = wp_parse_url( $raw );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			throw new \InvalidArgumentException( 'not an absolute URL' );
		}
		$scheme = strtolower( (string) $p['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			throw new \InvalidArgumentException( 'unsupported scheme' );
		}
		$host = strtolower( (string) $p['host'] );
		$port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}
		$path = (string) ( $p['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		// Trailing slash canonicalises to the form WITHOUT it (root stays '/').
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		$query = '';
		if ( ! empty( $p['query'] ) ) {
			parse_str( (string) $p['query'], $params );
			$keep = [];
			foreach ( $params as $k => $v ) {
				$k = (string) $k;
				foreach ( self::TRACKING as $re ) {
					if ( preg_match( $re, $k ) ) {
						continue 2;
					}
				}
				$keep[ $k ] = $v;
			}
			if ( $keep ) {
				ksort( $keep, SORT_STRING );
				$query = '?' . http_build_query( $keep, '', '&', PHP_QUERY_RFC3986 );
			}
		}

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . $query;
	}

	/** SHA-256 of the normalised form. */
	public static function of( string $raw ): string {
		return hash( 'sha256', self::normalize( $raw ) );
	}

	/** Exact plus trailing-slash variant — the same tolerance AIVIS applies. */
	public static function slash_aliases( string $raw ): array {
		return str_ends_with( $raw, '/' ) ? [ $raw, rtrim( $raw, '/' ) ] : [ $raw, $raw . '/' ];
	}
}
