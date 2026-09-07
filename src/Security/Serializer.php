<?php
/**
 * ZT-05 — safe re-serialization.
 *
 * The plugin never echoes the API's raw response substring into the page. It
 * re-serializes the parsed value itself with the flag set that makes a
 * <script> breakout impossible regardless of what AIVIS sent. The JS reference
 * (src/reference/serialize.mjs) reproduces this output byte for byte and is
 * fuzzed against it.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Security;

final class Serializer {

	public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		| JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	/** Characters that must never appear anywhere in the output. */
	public const FORBIDDEN = [ '<', '>', '&', "'" ];

	/**
	 * @param array<mixed> $value Decoded JSON-LD (object or array).
	 * @throws \RuntimeException If encoding fails or output contains a forbidden character.
	 */
	public static function serialize( array $value ): string {
		$out = json_encode( $value, self::FLAGS | JSON_THROW_ON_ERROR, Envelope::MAX_DEPTH + 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		// Defence in depth: the flags guarantee this, and we check anyway.
		// A false positive here costs one artifact; a miss is XSS.
		foreach ( self::FORBIDDEN as $c ) {
			if ( str_contains( $out, $c ) ) {
				throw new \RuntimeException( 'AIVIS_SCHEMA_INVALID: forbidden character survived serialization' );
			}
		}
		return $out;
	}

	/** The exact element the plugin emits — one script, carrying the §14 marker. */
	/**
	 * The one element the plugin prints. `data-aivis="1"` is the edge-worker
	 * marker (§14); `data-aivis-hash` is the SHA-256 of the exact content, so
	 * anyone fetching the public page — AIVIS included — can verify delivery
	 * without the plugin's help (§11).
	 */
	public static function script_tag( string $serialized, string $hash = '' ): string {
		$hash = strtolower( trim( $hash ) );
		$h    = preg_match( '/^[a-f0-9]{64}$/', $hash ) ? ' data-aivis-hash="' . $hash . '"' : '';
		return '<script type="application/ld+json" data-aivis="1"' . $h . '>' . $serialized . '</script>' . "\n";
	}
}
