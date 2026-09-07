<?php
/**
 * WP Rocket: per-URL file purge is synchronous. RocketCDN, when enabled,
 * follows asynchronously — the plugin reports what it can confirm.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache\Adapters;

use AivisOS\Cache\CacheAdapter;
use AivisOS\Cache\PurgeResult;

final class WpRocket implements CacheAdapter {
	public function id(): string {
		return 'wp-rocket';
	}
	public function label(): string {
		return 'WP Rocket';
	}
	public function is_available(): bool {
		return function_exists( 'rocket_clean_files' ) || function_exists( 'rocket_clean_domain' );
	}
	public function purge_urls( array $urls ): PurgeResult {
		if ( ! function_exists( 'rocket_clean_files' ) ) {
			return PurgeResult::unsupported( 'rocket_clean_files() missing' );
		}
		try {
			rocket_clean_files( array_values( array_map( 'strval', $urls ) ) );
		} catch ( \Throwable ) {
			return PurgeResult::failed( array_values( array_map( 'strval', $urls ) ) );
		}
		return PurgeResult::confirmed( count( $urls ) );
	}
	public function purge_all(): PurgeResult {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			return PurgeResult::confirmed( 0 );
		}
		return PurgeResult::unsupported();
	}
}
