<?php
/**
 * One API response, classified per §04.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Api;

final class Response {

	public const NOT_FOUND     = 'URL not found in your businesses';
	public const NOT_GENERATED = 'JSON-LD not generated yet for this URL';

	/**
	 * @param array<string,mixed>|null $body
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?array $body,
		public readonly string $transport_error = ''
	) {}

	/**
	 * ok | bad_request | auth | account | url_gone | not_generated | unreadable
	 * | throttled | transport
	 */
	public function kind(): string {
		$s = $this->status;
		if ( 200 === $s ) {
			return 'ok';
		}
		if ( 400 === $s ) {
			return 'bad_request';
		}
		if ( 401 === $s ) {
			return 'auth';
		}
		if ( 403 === $s ) {
			return 'account';
		}
		if ( 429 === $s ) {
			return 'throttled';
		}
		if ( 0 === $s || $s >= 500 ) {
			return 'transport';
		}
		if ( 404 === $s ) {
			// The messages are prose, not contract (API-3 asks for a code). Any
			// unrecognised body takes the conservative branch.
			$m = $this->message();
			if ( self::NOT_FOUND === $m ) {
				return 'url_gone';
			}
			if ( self::NOT_GENERATED === $m ) {
				return 'not_generated';
			}
			return 'unreadable';
		}
		return 'unreadable';
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
		return (int) ( $this->body['_retry_after'] ?? 0 );
	}
}
