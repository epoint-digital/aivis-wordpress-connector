<?php
/**
 * Picks the adapter: the configured one, or auto-detects, or Manual.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache;

use AivisOS\Cache\Adapters\LiteSpeed;
use AivisOS\Cache\Adapters\Manual;
use AivisOS\Cache\Adapters\W3TotalCache;
use AivisOS\Cache\Adapters\WpRocket;
use AivisOS\Cache\Adapters\WpSuperCache;
use AivisOS\Storage\Options;

final class AdapterFactory {

	public function __construct( private readonly Options $options ) {}

	/** @return list<CacheAdapter> in detection order */
	public function all(): array {
		return [ new WpSuperCache(), new W3TotalCache(), new LiteSpeed(), new WpRocket(), new Manual() ];
	}

	/**
	 * Cloudflare in front of the site: detected, never purged. The official
	 * plugin has no stable public per-URL purge API; site operators hook
	 * `aivis_connector_purge_urls` for their own CDN purge.
	 */
	public static function cloudflare_detected(): bool {
		return class_exists( '\CF\WordPress\Hooks' ) || defined( 'CLOUDFLARE_PLUGIN_DIR' ) || ! empty( $_SERVER['HTTP_CF_RAY'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public function adapter(): CacheAdapter {
		$want = $this->options->cache_adapter();
		if ( 'auto' !== $want ) {
			foreach ( $this->all() as $a ) {
				if ( $a->id() === $want && $a->is_available() ) {
					return $a;
				}
			}
		}
		foreach ( $this->all() as $a ) {
			if ( $a->is_available() ) {
				return $a;
			}
		}
		return new Manual();
	}
}
