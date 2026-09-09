<?php
/**
 * One API response, classified per §04.
 *
 * Since contract 1.1.0 every error envelope carries a stable `error.code`
 * (API-3); the classification branches on it and falls back to the two 1.0.0
 * prose messages only when no code is present. Unknown codes are treated like
 * their HTTP status alone, as the compatibility policy (§03) asks.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Api;

final class Response {

	/** 1.0.0 messages, kept as the fallback for an envelope without a code. */
	public const NOT_FOUND     = 'URL not found in your businesses';
	public const NOT_GENERATED = 'JSON-LD not generated yet for this URL';

	/** `error.code` values the connector acts on (contract ≥ 1.1.0). */
	public const CODE_URL_NOT_FOUND  = 'url_not_found';
	public const CODE_NOT_GENERATED  = 'jsonld_not_generated';
	public const CODE_WITHDRAWN      = 'withdrawn';
	public const CODE_CLIENT_TOO_OLD = 'client_too_old';
	public const CODE_RATE_LIMITED   = 'rate_limited';

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string>     $headers Lower-case header names → values.
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?array $body,
		public readonly string $transport_error = '',
		public readonly array $headers = []
	) {}

	/**
	 * ok | bad_request | auth | account | url_gone | not_generated | withdrawn
	 * | client_too_old | throttled | transport | unreadable
	 */
	public function kind(): string {
		$s = $this->status;
		$c = $this->code();
		if ( 200 === $s ) {
			return 'ok';
		}
		if ( 0 === $s || $s >= 500 ) {
			return 'transport';
		}
		// Explicit signals first: their codes are unambiguous whatever the status.
		if ( self::CODE_CLIENT_TOO_OLD === $c || 426 === $s ) {
			return 'client_too_old';
		}
		if ( self::CODE_WITHDRAWN === $c ) {
			return 'withdrawn';
		}
		if ( self::CODE_RATE_LIMITED === $c || 429 === $s ) {
			return 'throttled';
		}
		if ( 401 === $s ) {
			return 'auth';
		}
		if ( 403 === $s ) {
			return 'account';
		}
		if ( 400 === $s ) {
			return 'bad_request';
		}
		if ( 404 === $s ) {
			if ( self::CODE_URL_NOT_FOUND === $c ) {
				return 'url_gone';
			}
			if ( self::CODE_NOT_GENERATED === $c ) {
				return 'not_generated';
			}
			if ( '' === $c ) {
				// 1.0.0 contract: prose only. Any unrecognised body takes the
				// conservative branch.
				$m = $this->message();
				if ( self::NOT_FOUND === $m ) {
					return 'url_gone';
				}
				if ( self::NOT_GENERATED === $m ) {
					return 'not_generated';
				}
			}
			// business_not_found, chain_not_found, not_found, or a code added
			// later: a 404 we cannot attribute is not evidence of withdrawal.
			return 'unreadable';
		}
		return 'unreadable';
	}

	/** The stable `error.code`, or '' on a 1.0.0 envelope / no body. */
	public function code(): string {
		$c = $this->body['error']['code'] ?? null;
		return is_string( $c ) ? $c : '';
	}

	public function message(): string {
		$m = $this->body['error']['message'] ?? null;
		return is_string( $m ) ? $m : '';
	}

	public function ok(): bool {
		return 200 === $this->status && is_array( $this->body );
	}

	/** Seconds to wait when throttled, if the server said. */
	public function retry_after(): int {
		$h = (int) ( $this->headers['retry-after'] ?? 0 );
		return $h > 0 ? $h : (int) ( $this->body['_retry_after'] ?? 0 );
	}

	/** Contract version the server announced (`X-Aivis-Api-Version`), or ''. */
	public function api_version(): string {
		return trim( (string) ( $this->headers['x-aivis-api-version'] ?? '' ) );
	}

	/** Oldest connector version the server still answers (`X-Aivis-Min-Client`), or ''. */
	public function min_client(): string {
		return trim( (string) ( $this->headers['x-aivis-min-client'] ?? '' ) );
	}

	public function etag(): string {
		return trim( (string) ( $this->headers['etag'] ?? '' ) );
	}

	/** Requests left in the current rate-limit window, or null when not announced. */
	public function rate_remaining(): ?int {
		$v = $this->headers['x-ratelimit-remaining'] ?? null;
		return null === $v || '' === $v ? null : (int) $v;
	}

	/** Epoch seconds at which the current rate-limit window ends, or null. */
	public function rate_reset(): ?int {
		$v = $this->headers['x-ratelimit-reset'] ?? null;
		return null === $v || '' === $v ? null : (int) $v;
	}

	/** Deprecation signal (RFC 9745): true when the server flagged this endpoint. */
	public function deprecated(): bool {
		return isset( $this->headers['deprecation'] ) || isset( $this->headers['sunset'] );
	}
}
