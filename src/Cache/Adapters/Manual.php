<?php
/**
 * No supported cache detected — reports honestly, never claims live.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache\Adapters;

use AivisOS\Cache\CacheAdapter;
use AivisOS\Cache\PurgeResult;

final class Manual implements CacheAdapter {
	public function id(): string {
		return 'manual';
	}
	public function label(): string {
		return __( 'No supported cache plugin detected', 'aivis-os' );
	}
	public function is_available(): bool {
		return true;
	}
	public function purge_urls( array $urls ): PurgeResult {
		return PurgeResult::unsupported( __( 'Purge the affected pages manually or use WP-CLI.', 'aivis-os' ) );
	}
	public function purge_all(): PurgeResult {
		return PurgeResult::unsupported();
	}
}
