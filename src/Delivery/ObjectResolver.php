<?php
/**
 * URL ↔ WordPress object, as a fact (§05 object reference).
 *
 * Identity stays the exact AIVIS URL. The object reference is a secondary
 * key the plugin records because only the site can know it: it lets the
 * edit screens show what is delivered, and it lets the daily check notice
 * that a page's address changed since AIVIS crawled it (§11 "moved").
 * Nothing here decides anything.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

final class ObjectResolver {

	public const POST    = 'post';
	public const TERM    = 'term';
	public const ARCHIVE = 'archive';

	/**
	 * Best effort, cheap: posts through WordPress's own resolver, post-type
	 * archives by comparing the handful that exist, terms only when the URL
	 * path contains the taxonomy's rewrite slug. Anything else is null —
	 * an unresolved reference is a fact too.
	 *
	 * @return array{type:string,id:string}|null
	 */
	public static function for_url( string $url ): ?array {
		$pid = (int) url_to_postid( $url );
		if ( $pid > 0 ) {
			return [ 'type' => self::POST, 'id' => (string) $pid ];
		}
		foreach ( (array) get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			if ( ! is_object( $pt ) || empty( $pt->has_archive ) ) {
				continue;
			}
			$link = get_post_type_archive_link( (string) $pt->name );
			if ( is_string( $link ) && self::same_url( $link, $url ) ) {
				return [ 'type' => self::ARCHIVE, 'id' => (string) $pt->name ];
			}
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return null;
		}
		$segments = explode( '/', $path );
		$last     = rawurldecode( (string) end( $segments ) );
		foreach ( (array) get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( ! is_object( $tax ) ) {
				continue;
			}
			$rewrite = $tax->rewrite ?? null;
			$slug    = is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? $tax->name ) : (string) $tax->name;
			$slug    = trim( $slug, '/' );
			if ( '' === $slug || ! str_contains( '/' . $path . '/', '/' . $slug . '/' ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $last, (string) $tax->name );
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && self::same_url( $link, $url ) ) {
					return [ 'type' => self::TERM, 'id' => (string) $term->term_id ];
				}
			}
		}
		return null;
	}

	/** The object's address today. Null when the object is gone. */
	public static function url_of( string $type, string $id ): ?string {
		$u = match ( $type ) {
			self::POST    => get_permalink( (int) $id ),
			self::TERM    => get_term_link( (int) $id ),
			self::ARCHIVE => get_post_type_archive_link( $id ),
			default       => null,
		};
		return is_string( $u ) && '' !== $u ? $u : null;
	}

	/** Same page modulo the trailing slash — the one variant AIVIS also treats as equal. */
	public static function same_url( string $a, string $b ): bool {
		return rtrim( $a, '/' ) === rtrim( $b, '/' );
	}

	public static function label( string $type ): string {
		return match ( $type ) {
			self::POST    => 'page',
			self::TERM    => 'term archive',
			self::ARCHIVE => 'post type archive',
			default       => 'page',
		};
	}
}
