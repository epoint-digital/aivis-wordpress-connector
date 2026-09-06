<?php
/**
 * WP Super Cache: per-URL delete is synchronous and confirmable.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache\Adapters;

use AivisOS\Cache\CacheAdapter;
use AivisOS\Cache\PurgeResult;

final class WpSuperCache implements CacheAdapter {
	public function id(): string {
		return 'wp-super-cache';
	}
	public function label(): string {
		return 'WP Super Cache';
	}
	public function is_available(): bool {
		return function_exists( 'wpsc_delete_url_cache' ) || function_exists( 'wp_cache_clear_cache' );
	}
	public function purge_urls( array $urls ): PurgeResult {
		if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
			return PurgeResult::unsupported( 'wpsc_delete_url_cache() missing' );
		}
		$failed = [];
		foreach ( $urls as $u ) {
			try {
				wpsc_delete_url_cache( $u );
			} catch ( \Throwable ) {
				$failed[] = $u;
			}
		}
		return $failed ? PurgeResult::failed( $failed ) : PurgeResult::confirmed( count( $urls ) );
	}
	public function purge_all(): PurgeResult {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			return PurgeResult::confirmed( 0 );
		}
		return PurgeResult::unsupported();
	}
}
