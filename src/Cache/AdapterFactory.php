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
use AivisOS\Cache\Adapters\WpSuperCache;
use AivisOS\Storage\Options;

final class AdapterFactory {

	public function __construct( private readonly Options $options ) {}

	/** @return list<CacheAdapter> in detection order */
	public function all(): array {
		return [ new WpSuperCache(), new W3TotalCache(), new LiteSpeed(), new Manual() ];
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
