<?php
/**
 * §11a — the read-only status endpoint AIVIS fetches. Nothing is pushed.
 *
 *   GET /wp-json/aivis-os/v1/status
 *   GET /wp-json/aivis-os/v1/status/urls?cursor=<id>&limit=<n>
 *   Authorization: Bearer aivis_status_…
 *
 * The key is issued by this site (Settings → Status for AIVIS), entered in
 * AIVIS by the admin, and reaches nothing but this document. It is compared
 * in constant time, accepted only from the Authorization header — never a
 * query parameter — and the responses are marked no-store.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Rest;

use AivisOS\Plugin;

final class StatusController {

	public const NS = 'aivis-os/v1';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/status/urls',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'urls' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'cursor' => [ 'type' => 'string', 'required' => false ],
					'limit'  => [ 'type' => 'integer', 'required' => false, 'default' => 200, 'minimum' => 1, 'maximum' => 500 ],
				],
			]
		);
	}

	/** @return true|\WP_Error */
	public function permission( \WP_REST_Request $request ): bool|\WP_Error {
		$key = $this->plugin->options()->status_key();
		if ( '' === $key ) {
			return new \WP_Error( 'aivis_status_disabled', 'The AIVIS OS status endpoint is disabled on this site.', [ 'status' => 404 ] );
		}
		$given = self::bearer( (string) $request->get_header( 'authorization' ) );
		if ( '' === $given || ! hash_equals( $key, $given ) ) {
			return new \WP_Error( 'aivis_status_unauthorized', 'A valid status key is required (Authorization: Bearer …).', [ 'status' => 401 ] );
		}
		return true;
	}

	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( $this->document()->build() );
	}

	public function urls( \WP_REST_Request $request ): \WP_REST_Response {
		$cursor = (string) ( $request->get_param( 'cursor' ) ?? '' );
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 200 );
		$after  = ctype_digit( $cursor ) ? (int) $cursor : 0;
		return $this->respond( $this->document()->urls( $after, $limit > 0 ? $limit : 200 ) );
	}

	public function document(): StatusDocument {
		return new StatusDocument( $this->plugin->options(), $this->plugin->repository(), $this->plugin->cache() );
	}

	/** @param array<string,mixed> $data */
	private function respond( array $data ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	public static function bearer( string $header ): string {
		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}
		return trim( substr( $header, 7 ) );
	}
}
