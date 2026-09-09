<?php
/**
 * AIVIS Public API v1 client (§03, §04, ZT-01).
 *
 * Transport rules: wp_safe_remote_get only — the connector never writes to
 * AIVIS — TLS verified, ZERO redirects (a redirect would forward the bearer
 * token), fixed host, 10 s timeout, 1 MiB cap, versioned user agent. Never
 * called on the public render path.
 *
 * Every response's `X-Aivis-Api-Version` / `X-Aivis-Min-Client` headers are
 * noted (§03 compatibility policy), so the Status screen and Site Health can
 * say which contract the instance speaks and warn before a 426 happens.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Api;

use AivisOS\Domain\ErrorCode;
use AivisOS\Security\Envelope;
use AivisOS\Storage\Options;

final class Client {

	public const BASE_PATH = '/api/public/v1';
	public const TIMEOUT   = 10;
	public const MAX_BYTES = 1048576;
	public const PAGE      = 200;

	/** Response headers the connector reads; everything else is dropped. */
	private const HEADERS = [
		'content-type',
		'retry-after',
		'etag',
		'x-aivis-api-version',
		'x-aivis-min-client',
		'x-ratelimit-limit',
		'x-ratelimit-remaining',
		'x-ratelimit-reset',
		'deprecation',
		'sunset',
		'upgrade',
	];

	public function __construct( private readonly Options $options ) {}

	/* ── endpoints ───────────────────────────────────────────────────── */

	public function me(): Response {
		return $this->get( '/me' );
	}

	/** Contract version, minimum client, announced minimum and rate limits (contract ≥ 1.2.0). */
	public function changelog(): Response {
		return $this->get( '/changelog' );
	}

	public function businesses( ?string $cursor = null ): Response {
		return $this->get( '/businesses', $this->page( $cursor ) );
	}

	public function chains( string $business_id, ?string $cursor = null ): Response {
		return $this->get( '/businesses/' . rawurlencode( $business_id ) . '/chains', $this->page( $cursor ) );
	}

	/**
	 * Inventory rows of a chain. `$updated_since` (RFC 3339 with a zone) turns
	 * the walk into the change feed (API-6): rows changed strictly after that
	 * instant, never an authoritative inventory. `$url` narrows to one page
	 * (API-5): exact plus trailing-slash variant, 0–2 rows, a miss is an empty
	 * page.
	 */
	public function urls( string $chain_id, ?string $cursor = null, ?string $updated_since = null, ?string $url = null ): Response {
		$q = $this->page( $cursor );
		if ( null !== $updated_since && '' !== $updated_since ) {
			$q['updatedSince'] = $updated_since;
		}
		if ( null !== $url && '' !== $url ) {
			$q['url'] = $url;
		}
		return $this->get( '/chains/' . rawurlencode( $chain_id ) . '/urls', $q );
	}

	/** One inventory row by id (API-5, contract ≥ 1.6.0). */
	public function url_row( string $url_id ): Response {
		return $this->get( '/urls/' . rawurlencode( $url_id ) );
	}

	/** The hot path. The permalink is sent unmodified (§07). */
	public function jsonld_by_url( string $absolute_url ): Response {
		return $this->get( '/jsonld', [ 'url' => $absolute_url ] );
	}

	/**
	 * The inventory path (§07a): fetching by urlId pins the chain, so one chain
	 * per language holds even when two chains carry the same URL.
	 */
	public function jsonld_by_id( string $url_id ): Response {
		return $this->get( '/urls/' . rawurlencode( $url_id ) . '/jsonld' );
	}

	/** Reachability probe used by R-01 when no other request succeeded. */
	public function reachable(): bool {
		return 200 === $this->me()->status;
	}

	/* ── transport ───────────────────────────────────────────────────── */

	/**
	 * The connector only ever reads. There is no POST here on purpose (§11a):
	 * nothing about this site is pushed to AIVIS — AIVIS fetches the status
	 * document from the site when it wants it.
	 *
	 * @param array<string,string|int> $query
	 */
	public function get( string $path, array $query = [] ): Response {
		$token = $this->options->token();
		if ( '' === $token ) {
			return new Response( 401, [ 'error' => [ 'code' => 'missing_token', 'message' => 'Missing bearer token' ] ] );
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
			// `aivis-os/<semver>` first: the server compares it with X-Aivis-Min-Client (§03).
			'user-agent'          => 'aivis-os/' . AIVIS_OS_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . '; +https://github.com/epoint-digital/aivis-wordpress-connector)',
			'headers'             => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
		];
		$res = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $res ) ) {
			$msg = (string) $res->get_error_message();
			if ( str_contains( $msg, 'valid URL was not provided' ) ) {
				// wp_http_validate_url() refuses a host that does not resolve — that is
				// what an admin sees while app.aivis-os.com has no DNS record. Say so.
				$msg .= ' — the host name does not resolve (no DNS record), or this WordPress blocks external requests';
			}
			// Redact defensively: WP_Error messages can echo request details.
			return new Response( 0, null, $this->options->redact( $msg ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $res );
		$headers = [];
		foreach ( self::HEADERS as $h ) {
			$v = wp_remote_retrieve_header( $res, $h );
			if ( is_array( $v ) ) {
				$v = (string) reset( $v );
			}
			if ( '' !== (string) $v ) {
				$headers[ $h ] = (string) $v;
			}
		}
		$ctype = (string) ( $headers['content-type'] ?? '' );
		$raw   = (string) wp_remote_retrieve_body( $res );

		$this->note_contract( $headers, $status );

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new Response( 0, null, 'response exceeds ' . self::MAX_BYTES . ' bytes', $headers );
		}
		if ( '' !== $ctype && ! str_contains( strtolower( $ctype ), 'application/json' ) ) {
			return new Response( $status, null, 'non-JSON content type', $headers );
		}

		// Depth: 32 levels of jsonLd inside the envelope plus headroom, so a too-deep
		// document fails ZT-04 with a clear message rather than a decode error.
		$body = json_decode( $raw, true, Envelope::MAX_DEPTH + 8, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new Response( $status, null, 'malformed JSON: ' . json_last_error_msg(), $headers );
		}
		return new Response( $status, is_array( $body ) ? $body : null, '', $headers );
	}

	/**
	 * §03 — remember what the instance announced. Written only when it changed,
	 * so the option is not rewritten on every request. A 426 is recorded once
	 * per hour: the sync stops until the plugin is updated, and the admin must
	 * see why.
	 *
	 * @param array<string,string> $headers
	 */
	private function note_contract( array $headers, int $status ): void {
		$version = trim( (string) ( $headers['x-aivis-api-version'] ?? '' ) );
		$min     = trim( (string) ( $headers['x-aivis-min-client'] ?? '' ) );
		$too_old = 426 === $status;
		if ( '' === $version && '' === $min && ! $too_old ) {
			return;
		}
		$info = $this->options->api_info();
		$next = [
			'version'    => '' !== $version ? $version : (string) $info['version'],
			'min_client' => '' !== $min ? $min : (string) $info['min_client'],
			'too_old'    => $too_old,
			'seen_at'    => time(),
		];
		if ( $next['version'] !== $info['version'] || $next['min_client'] !== $info['min_client'] || $next['too_old'] !== $info['too_old'] || time() - (int) $info['seen_at'] > DAY_IN_SECONDS ) {
			$this->options->set_api_info( $next );
		}
		if ( $too_old && false === get_transient( 'aivis_os_too_old_noted' ) ) {
			set_transient( 'aivis_os_too_old_noted', 1, HOUR_IN_SECONDS );
			$this->options->record( ErrorCode::CLIENT_TOO_OLD, sprintf( 'AIVIS answered 426: connector %s is below the minimum client %s — update the plugin; nothing syncs until then', AIVIS_OS_VERSION, '' !== $min ? $min : '?' ) );
		}
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
