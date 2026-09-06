<?php
/**
 * §10 — what an adapter can honestly claim. Only CONFIRMED permits a
 * "live on the site" statement (WP-I10).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cache;

final class PurgeResult {

	public const CONFIRMED   = 'confirmed';
	public const REQUESTED   = 'requested';
	public const UNSUPPORTED = 'unsupported';
	public const FAILED      = 'failed';

	/** @param list<string> $failed_urls */
	public function __construct(
		public readonly string $state,
		public readonly int $purged = 0,
		public readonly array $failed_urls = [],
		public readonly ?string $message = null
	) {}

	public static function confirmed( int $n ): self {
		return new self( self::CONFIRMED, $n );
	}

	public static function requested( int $n, ?string $m = null ): self {
		return new self( self::REQUESTED, $n, [], $m );
	}

	public static function unsupported( ?string $m = null ): self {
		return new self( self::UNSUPPORTED, 0, [], $m );
	}

	/** @param list<string> $failed */
	public static function failed( array $failed, ?string $m = null ): self {
		return new self( self::FAILED, 0, $failed, $m );
	}

	public function live(): bool {
		return self::CONFIRMED === $this->state;
	}
}
