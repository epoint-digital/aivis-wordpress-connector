<?php
/**
 * ZT-02 / ZT-04 — validate the artifact envelope from /jsonld.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Security;

final class Envelope {

	public const MAX_BYTES = 1048576; // 1 MiB
	public const MAX_DEPTH = 32;

	private const FIELDS = [
		'urlId'        => 'string',
		'chainId'      => 'string',
		'businessId'   => 'string',
		'url'          => 'string',
		'languageCode' => 'string',
		'stale'        => 'boolean',
		'generatedAt'  => 'string',
		'jsonLd'       => 'object-or-array',
	];

	/**
	 * @param mixed $body Decoded JSON (assoc arrays).
	 * @return array{ok:bool, errors:list<string>}
	 */
	public static function validate( mixed $body ): array {
		$errors = [];
		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			return [
				'ok'     => false,
				'errors' => [ 'envelope must be a JSON object' ],
			];
		}
		foreach ( self::FIELDS as $field => $type ) {
			if ( ! array_key_exists( $field, $body ) ) {
				$errors[] = "missing field: {$field}";
				continue;
			}
			$v = $body[ $field ];
			if ( 'string' === $type && ! is_string( $v ) ) {
				$errors[] = "{$field} must be a string";
			}
			if ( 'boolean' === $type && ! is_bool( $v ) ) {
				$errors[] = "{$field} must be a boolean";
			}
			if ( 'object-or-array' === $type && ! is_array( $v ) ) {
				$errors[] = 'jsonLd must be an object or array';
			}
		}
		if ( isset( $body['generatedAt'] ) && is_string( $body['generatedAt'] ) && false === strtotime( $body['generatedAt'] ) ) {
			$errors[] = 'generatedAt must be a valid timestamp';
		}
		if ( isset( $body['url'] ) && is_string( $body['url'] ) && ! preg_match( '#^https?://#i', $body['url'] ) ) {
			$errors[] = 'url must be absolute';
		}
		if ( ! $errors ) {
			$depth = self::depth( $body['jsonLd'] );
			if ( $depth > self::MAX_DEPTH ) {
				$errors[] = 'jsonLd nesting exceeds depth ' . self::MAX_DEPTH;
			}
			// Measure the document as it is, not unicode-escaped: wp_json_encode
			// turns every non-ASCII character into six bytes and would reject a
			// 700 KB CJK document as over the cap.
			$bytes = strlen( (string) json_encode( $body['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( $bytes > self::MAX_BYTES ) {
				$errors[] = 'jsonLd exceeds ' . self::MAX_BYTES . ' bytes';
			}
		}
		return [
			'ok'     => [] === $errors,
			'errors' => $errors,
		];
	}

	/** Objects and arrays both count as a level; {} is depth 1. */
	public static function depth( mixed $v, int $d = 1 ): int {
		if ( ! is_array( $v ) ) {
			return $d;
		}
		$max = $d;
		foreach ( $v as $child ) {
			$cd = self::depth( $child, $d + 1 );
			if ( $cd > $max ) {
				$max = $cd;
				if ( $max > self::MAX_DEPTH + 1 ) {
					return $max; // no need to walk further
				}
			}
		}
		return $max;
	}
}
