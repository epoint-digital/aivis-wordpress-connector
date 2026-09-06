<?php
/**
 * §09a — AIVIS is the primary source of structured data.
 *
 * Any other JSON-LD on a page (SEO plugins, the theme, hand-written blocks)
 * is a conflict: it is identified, attributed to its source where a marker
 * allows, and flagged red in the admin. Publishing is never blocked. For
 * plugins whose output can be switched off through their own public filters,
 * the connector offers a suppression toggle — off by default, the admin's call.
 *
 * Detection runs on the background loopback scan (Verifier), never on the
 * render path.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

final class Conflicts {

	public const UNKNOWN = 'unknown';

	/**
	 * Known emitters. `marker` is matched against the script tag's attributes
	 * and the 200 characters before it; `filter` registers the plugin's own
	 * off-switch; `how` is the manual alternative shown to the admin.
	 *
	 * @return array<string,array{label:string,plugin:string,marker:string,filter:?\Closure,how:string}>
	 */
	public static function registry(): array {
		return [
			'yoast'      => [
				'label'  => 'Yoast SEO',
				'plugin' => 'wordpress-seo/wp-seo.php',
				'marker' => 'yoast-schema-graph',
				'filter' => static function (): void {
					add_filter( 'wpseo_json_ld_output', '__return_false', 99 );
				},
				'how'    => __( 'Yoast has no setting for this; the connector can switch its schema output off through the wpseo_json_ld_output filter.', 'aivis-os' ),
			],
			'rank-math'  => [
				'label'  => 'Rank Math',
				'plugin' => 'seo-by-rank-math/rank-math.php',
				'marker' => 'rank-math-schema',
				'filter' => static function (): void {
					add_filter( 'rank_math/json_ld', static fn(): array => [], 99 );
				},
				'how'    => __( 'Rank Math → Titles & Meta → disable schema per post type, or let the connector suppress it through the rank_math/json_ld filter.', 'aivis-os' ),
			],
			'aioseo'     => [
				'label'  => 'All in One SEO',
				'plugin' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'marker' => 'aioseo-schema',
				'filter' => static function (): void {
					add_filter( 'aioseo_schema_disable', '__return_true', 99 );
				},
				'how'    => __( 'All in One SEO → Search Appearance → disable schema markup, or let the connector suppress it through the aioseo_schema_disable filter.', 'aivis-os' ),
			],
			'seopress'   => [
				'label'  => 'SEOPress',
				'plugin' => 'wp-seopress/seopress.php',
				'marker' => 'seopress',
				'filter' => null,
				'how'    => __( 'SEOPress → PRO → Structured Data Types: disable the schemas, or deactivate the Schemas feature.', 'aivis-os' ),
			],
			'slim-seo'   => [
				'label'  => 'Slim SEO',
				'plugin' => 'slim-seo/slim-seo.php',
				'marker' => 'slim-seo',
				'filter' => static function (): void {
					add_filter( 'slim_seo_schema_graph', static fn(): array => [], 99 );
				},
				'how'    => __( 'Slim SEO → Schema: turn it off, or let the connector suppress it through the slim_seo_schema_graph filter.', 'aivis-os' ),
			],
			'schema-pro' => [
				'label'  => 'Schema Pro',
				'plugin' => 'wp-schema-pro/wp-schema-pro.php',
				'marker' => 'schema-pro',
				'filter' => null,
				'how'    => __( 'Schema Pro → Configuration: disable the schema types you have set up.', 'aivis-os' ),
			],
			'wpsso'      => [
				'label'  => 'WPSSO',
				'plugin' => 'wpsso/wpsso.php',
				'marker' => 'wpsso',
				'filter' => null,
				'how'    => __( 'WPSSO → Advanced Settings → disable Schema markup.', 'aivis-os' ),
			],
		];
	}

	/** Registry keys of emitters whose plugin is active on this site. */
	public static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', [] );
		$out    = [];
		foreach ( self::registry() as $key => $e ) {
			if ( in_array( $e['plugin'], $active, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** Register the off-switches the admin turned on. Runs at init, before wp_head. */
	public static function apply_suppressions( array $keys ): void {
		$reg = self::registry();
		foreach ( $keys as $k ) {
			if ( isset( $reg[ $k ] ) && null !== $reg[ $k ]['filter'] ) {
				( $reg[ $k ]['filter'] )();
			}
		}
	}

	/**
	 * Find every JSON-LD block that is not ours.
	 *
	 * @return array{blocks:int, sources:list<string>, types:list<string>}
	 */
	public static function scan_html( string $html ): array {
		$blocks  = 0;
		$sources = [];
		$types   = [];
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return [ 'blocks' => 0, 'sources' => [], 'types' => [] ];
		}
		$reg = self::registry();
		foreach ( $m as $hit ) {
			$attrs = $hit[1][0];
			$body  = $hit[2][0];
			if ( ! preg_match( '#type\s*=\s*["\']?\s*application/ld\+json#i', $attrs ) ) {
				continue;
			}
			if ( str_contains( $attrs, 'data-aivis' ) ) {
				continue; // ours
			}
			$blocks++;
			// Attribute by the tag's own attributes plus whatever sits between the
			// previous </script> and this tag (SEOPress and others announce
			// themselves in an HTML comment there). Never look further back, or
			// the previous block's marker would be blamed for this one.
			$before = substr( $html, 0, $hit[0][1] );
			$cut    = strripos( $before, '</script>' );
			$lead   = false !== $cut ? substr( $before, $cut ) : substr( $before, -200 );
			$context = strtolower( $attrs . ' ' . substr( $lead, -200 ) );
			$source  = self::UNKNOWN;
			foreach ( $reg as $key => $e ) {
				if ( str_contains( $context, $e['marker'] ) ) {
					$source = $key;
					break;
				}
			}
			$sources[] = $source;
			$decoded   = json_decode( trim( $body ), true );
			if ( is_array( $decoded ) ) {
				self::collect_types( $decoded, $types );
			}
		}
		return [
			'blocks'  => $blocks,
			'sources' => array_values( array_unique( $sources ) ),
			'types'   => array_values( array_unique( array_slice( $types, 0, 12 ) ) ),
		];
	}

	/** @param array<string,mixed> $node */
	private static function collect_types( array $node, array &$types, int $depth = 0 ): void {
		if ( $depth > 4 ) {
			return;
		}
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $t ) {
				if ( is_string( $t ) ) {
					$types[] = $t;
				}
			}
		}
		foreach ( [ '@graph', 'mainEntity', 'itemListElement' ] as $k ) {
			if ( isset( $node[ $k ] ) && is_array( $node[ $k ] ) ) {
				foreach ( array_is_list( $node[ $k ] ) ? $node[ $k ] : [ $node[ $k ] ] as $child ) {
					if ( is_array( $child ) ) {
						self::collect_types( $child, $types, $depth + 1 );
					}
				}
			}
		}
		if ( array_is_list( $node ) ) {
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					self::collect_types( $child, $types, $depth + 1 );
				}
			}
		}
	}

	/**
	 * Order-independent identity of a conflict set, so "changed" means changed.
	 *
	 * @param array<string,array{sources:list<string>,types:list<string>,blocks:int}> $items url => finding
	 */
	public static function fingerprint( array $items ): string {
		$lines = [];
		foreach ( $items as $url => $f ) {
			$s = (array) ( $f['sources'] ?? [] );
			$t = (array) ( $f['types'] ?? [] );
			sort( $s );
			sort( $t );
			$lines[] = $url . '|' . implode( ',', $s ) . '|' . implode( ',', $t );
		}
		sort( $lines );
		return $lines ? sha1( implode( "\n", $lines ) ) : '';
	}

	public static function label( string $key ): string {
		return self::registry()[ $key ]['label'] ?? __( 'Unknown source (theme or hand-written block)', 'aivis-os' );
	}

	public static function guidance( string $key ): string {
		return self::registry()[ $key ]['how'] ?? __( 'Look for a JSON-LD block in the theme or a custom snippet and remove it.', 'aivis-os' );
	}

	public static function suppressible( string $key ): bool {
		return null !== ( self::registry()[ $key ]['filter'] ?? null );
	}
}
