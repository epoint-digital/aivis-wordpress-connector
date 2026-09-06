<?php
/**
 * LiteSpeed Cache: purge is requested via action and completed by the server
 * asynchronously — we cannot confirm it, so we say "requested" (WP-I10).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache\Adapters;

use AivisOS\Cache\CacheAdapter;
use AivisOS\Cache\PurgeResult;

final class LiteSpeed implements CacheAdapter {
	public function id(): string {
		return 'litespeed';
	}
	public function label(): string {
		return 'LiteSpeed Cache';
	}
	public function is_available(): bool {
		return defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Purge' );
	}
	public function purge_urls( array $urls ): PurgeResult {
		foreach ( $urls as $u ) {
			do_action( 'litespeed_purge_url', $u );
		}
		return PurgeResult::requested( count( $urls ), __( 'LiteSpeed accepts purge requests but does not confirm them.', 'aivis-os' ) );
	}
	public function purge_all(): PurgeResult {
		do_action( 'litespeed_purge_all' );
		return PurgeResult::requested( 0 );
	}
}
