<?php
/**
 * W3 Total Cache: w3tc_flush_url() is synchronous.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache\Adapters;

use AivisOS\Cache\CacheAdapter;
use AivisOS\Cache\PurgeResult;

final class W3TotalCache implements CacheAdapter {
	public function id(): string {
		return 'w3-total-cache';
	}
	public function label(): string {
		return 'W3 Total Cache';
	}
	public function is_available(): bool {
		return function_exists( 'w3tc_flush_url' );
	}
	public function purge_urls( array $urls ): PurgeResult {
		if ( ! function_exists( 'w3tc_flush_url' ) ) {
			return PurgeResult::unsupported();
		}
		$failed = [];
		foreach ( $urls as $u ) {
			try {
				w3tc_flush_url( $u );
			} catch ( \Throwable ) {
				$failed[] = $u;
			}
		}
		return $failed ? PurgeResult::failed( $failed ) : PurgeResult::confirmed( count( $urls ) );
	}
	public function purge_all(): PurgeResult {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			return PurgeResult::confirmed( 0 );
		}
		return PurgeResult::unsupported();
	}
}
