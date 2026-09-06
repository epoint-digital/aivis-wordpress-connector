<?php
/**
 * §10 — the cache adapter contract. Adapters never see AIVIS credentials.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache;

interface CacheAdapter {
	public function id(): string;
	public function label(): string;
	public function is_available(): bool;

	/** @param list<string> $urls */
	public function purge_urls( array $urls ): PurgeResult;

	/** First activation / deactivation only. */
	public function purge_all(): PurgeResult;
}
