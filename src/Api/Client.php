<?php
/**
 * AIVIS Public API v1 client (§03, §04, ZT-01).
 *
 * Transport rules: wp_safe_remote_get, TLS verified, ZERO redirects (a redirect
 * would forward the bearer token), fixed host, 10 s timeout, 1 MiB cap,
 * versioned user agent. Never called on the public render path.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Api;

use AivisOS\Security\Envelope;
use AivisOS\Storage\Options;

final class Client {

	public const BASE_PATH = '/api/public/v1';
	public const TIMEOUT   = 10;
	public const MAX_BYTES = 1048576;
	public const PAGE      = 200;

	public function __construct( private readonly Options $options ) {}

	/* ── endpoints ───────────────────────────────────────────────────── */

	public function me(): Response {
		return $this->get( '/me' );
	}

	public function businesses( ?string $cursor = null ): Response {
		return $this->get( '/businesses', $this->page( $cursor ) );
	}

	public function chains( string $business_id, ?string $cursor = null ): Response {
		return $this->get( '/businesses/' . rawurlencode( $business_id ) . '/chains', $this->page( $cursor ) );
	}

	public function urls( string $chain_id, ?string $cursor = null ): Response {
		return $this->get( '/chains/' . rawurlencode( $chain_id ) . '/urls', $this->page( $cursor ) );
	}

	/** The hot path. The permalink is sent unmodified (§07). */
	public function jsonld_by_url( string $absolute_url ): Response {
		return $this->get( '/jsonld', [ 'url' => $absolute_url ] );
	}

	/** Diagnostics only. */
	public function jsonld_by_id( string $url_id ): Response {
		return $this->get( '/urls/' . rawurlencode( $url_id ) . '/jsonld' );
	}

	/** Reachability probe used by R-01 when no other request succeeded. */
	public function reachable(): bool {
		return 200 === $this->me()->status;
	}

	/**
	 * API-9 (proposed) — connector status report. Until the platform ships
	 * the endpoint this returns 404 and the Notifier backs off for a day.
	 *
	 * @param array<string,mixed> $report
	 */
	public function report_status( string $business_id, array $report ): Response {
		return $this->post( '/businesses/' . rawurlencode( $business_id ) . '/connector-status', $report );
	}

	/* ── transport ───────────────────────────────────────────────────── */

	/**
	 * @param array<string,mixed> $json
	 */
	public function post( string $path, array $json ): Response {
		return $this->request( 'POST', $path, [], $json );
	}

	/**
	 * @param array<string,string|int> $query
	 */
	public function get( string $path, array $query = [] ): Response {
		return $this->request( 'GET', $path, $query, null );
	}

	/**
	 * @param array<string,string|int> $query
	 * @param array<string,mixed>|null $json
	 */
	private function request( string $method, string $path, array $query, ?array $json ): Response {
		$token = $this->options->token();
		if ( '' === $token ) {
			return new Response( 401, [ 'error' => [ 'message' => 'Missing bearer token' ] ] );
		}

		$base = $this->options->api_base();
		$url  = $base . self::BASE_PATH . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'strval', $query ), $url );
		}

		// Fixed-host pin: the request must go to the configured base and nowhere else.
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( $base, PHP_URL_HOST ) ) {
			return new Response( 0, null, 'host mismatch' );
		}

		$args = [
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'aivis-os/' . AIVIS_OS_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; +https://github.com/epoint-digital/aivis-wordpress-connector)',
			'headers'             => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
		];
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $json ?? [] );
			$res                             = wp_safe_remote_post( $url, $args );
		} else {
			$res = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $res ) ) {
			// Redact defensively: WP_Error messages can echo request details.
			return new Response( 0, null, $this->options->redact( $res->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $res );
		$ctype  = (string) wp_remote_retrieve_header( $res, 'content-type' );
		$raw    = (string) wp_remote_retrieve_body( $res );

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new Response( 0, null, 'response exceeds ' . self::MAX_BYTES . ' bytes' );
		}
		if ( '' !== $ctype && ! str_contains( strtolower( $ctype ), 'application/json' ) ) {
			return new Response( $status, null, 'non-JSON content type' );
		}

		// Depth: 32 levels of jsonLd inside the envelope plus headroom, so a too-deep
		// document fails ZT-04 with a clear message rather than a decode error.
		$body = json_decode( $raw, true, Envelope::MAX_DEPTH + 8, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new Response( $status, null, 'malformed JSON: ' . json_last_error_msg() );
		}
		if ( 429 === $status ) {
			$body                 = is_array( $body ) ? $body : [];
			$body['_retry_after'] = (int) wp_remote_retrieve_header( $res, 'retry-after' );
		}
		return new Response( $status, is_array( $body ) ? $body : null );
	}

	/** @return array<string,string|int> */
	private function page( ?string $cursor ): array {
		$q = [ 'limit' => self::PAGE ];
		if ( null !== $cursor && '' !== $cursor ) {
			$q['cursor'] = $cursor;
		}
		return $q;
	}
}
