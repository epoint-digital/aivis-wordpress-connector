<?php
/**
 * The Pages screen's query: request → filters → SQL. Pure, so the parsing
 * and the WHERE clause are unit-tested without WordPress.
 *
 * A human never reads the whole list. The screen exists to answer two
 * questions: "what needs me?" (the attention view, the default when it is
 * non-empty) and "what about this page?" (search). Everything else is a
 * filter on top of a paginated table.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

final class PagesQuery {

	public const STATES           = [ 'attention', 'active', 'stale', 'holding', 'suspended', 'retired', 'inactive', 'moved', 'conflict' ];
	public const ORDERBY          = [ 'source_url', 'source_generated_at', 'published_at', 'last_synced_at', 'verified_at', 'language_code', 'chain_id' ];
	public const PER_PAGE_DEFAULT = 50;
	public const PER_PAGE_MAX     = 200;
	/** Bulk actions touch at most this many rows per request. */
	public const BULK_MAX = 200;

	/**
	 * @param array<string,mixed> $get     Request parameters.
	 * @param int                 $per_page The user's screen-option value (0 = default).
	 * @return array{state:string,language:string,chain:string,search:string,page:int,per_page:int,orderby:string,order:string}
	 */
	public static function from_request( array $get, int $per_page = 0 ): array {
		$state = strtolower( trim( (string) ( $get['state'] ?? '' ) ) );
		$order = strtoupper( trim( (string) ( $get['order'] ?? 'ASC' ) ) );
		$by    = strtolower( trim( (string) ( $get['orderby'] ?? 'source_url' ) ) );
		$pp    = $per_page > 0 ? $per_page : self::PER_PAGE_DEFAULT;
		return [
			'state'    => in_array( $state, self::STATES, true ) ? $state : '',
			'language' => strtolower( trim( (string) ( $get['language'] ?? '' ) ) ),
			'chain'    => trim( (string) ( $get['chain'] ?? '' ) ),
			'search'   => trim( (string) ( $get['s'] ?? '' ) ),
			'page'     => max( 1, (int) ( $get['paged'] ?? 1 ) ),
			'per_page' => max( 1, min( self::PER_PAGE_MAX, $pp ) ),
			'orderby'  => in_array( $by, self::ORDERBY, true ) ? $by : 'source_url',
			'order'    => 'DESC' === $order ? 'DESC' : 'ASC',
		];
	}

	/**
	 * WHERE clause and its arguments for one filter set.
	 *
	 * @param array{state:string,language:string,chain:string,search:string} $f
	 * @param list<string> $moved_keys     url_keys of moved pages (from sync state).
	 * @param list<string> $conflict_urls  source URLs carrying foreign JSON-LD (from the conflict store).
	 * @return array{sql:string, args:list<string>}
	 */
	public static function where( array $f, array $moved_keys = [], array $conflict_urls = [] ): array {
		$parts = [];
		$args  = [];
		$in    = static function ( array $values ) use ( &$args ): string {
			if ( ! $values ) {
				return '(1=0)';
			}
			foreach ( $values as $v ) {
				$args[] = (string) $v;
			}
			return '(' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
		};
		$live = 'active = 1 AND retired_at IS NULL AND suspended_at IS NULL';
		switch ( $f['state'] ?? '' ) {
			case 'active':
				$parts[] = "({$live} AND last_error_code IS NULL AND source_stale = 0)";
				break;
			case 'stale':
				$parts[] = "({$live} AND source_stale = 1)";
				break;
			case 'holding':
				$parts[] = "({$live} AND last_error_code IS NOT NULL)";
				break;
			case 'suspended':
				$parts[] = '(suspended_at IS NOT NULL AND retired_at IS NULL)';
				break;
			case 'retired':
				$parts[] = '(retired_at IS NOT NULL)';
				break;
			case 'inactive':
				$parts[] = '(active = 0 AND retired_at IS NULL)';
				break;
			case 'moved':
				$parts[] = 'url_key IN ' . $in( $moved_keys );
				break;
			case 'conflict':
				$parts[] = 'source_url IN ' . $in( $conflict_urls );
				break;
			case 'attention':
				$or   = [
					'(suspended_at IS NOT NULL AND retired_at IS NULL)',
					"({$live} AND last_error_code IS NOT NULL)",
					'(active = 0 AND retired_at IS NULL)',
				];
				if ( $moved_keys ) {
					$or[] = 'url_key IN ' . $in( $moved_keys );
				}
				if ( $conflict_urls ) {
					$or[] = 'source_url IN ' . $in( $conflict_urls );
				}
				$parts[] = '(' . implode( ' OR ', $or ) . ')';
				break;
		}
		if ( '' !== ( $f['language'] ?? '' ) ) {
			$parts[] = 'language_code = %s';
			$args[]  = (string) $f['language'];
		}
		if ( '' !== ( $f['chain'] ?? '' ) ) {
			$parts[] = 'chain_id = %s';
			$args[]  = (string) $f['chain'];
		}
		if ( '' !== ( $f['search'] ?? '' ) ) {
			$parts[] = 'source_url LIKE %s';
			$args[]  = '%' . self::like( (string) $f['search'] ) . '%';
		}
		return [ 'sql' => $parts ? implode( ' AND ', $parts ) : '1=1', 'args' => $args ];
	}

	/** Escape LIKE wildcards in user input (what $wpdb->esc_like does). */
	public static function like( string $s ): string {
		return addcslashes( $s, '_%\\' );
	}

	/** @param list<mixed> $keys @return list<string> at most BULK_MAX clean url_keys */
	public static function bulk_keys( array $keys ): array {
		$out = [];
		foreach ( $keys as $k ) {
			$k = (string) $k;
			if ( preg_match( '/^[a-f0-9]{64}$/', $k ) ) {
				$out[ $k ] = $k;
			}
			if ( count( $out ) >= self::BULK_MAX ) {
				break;
			}
		}
		return array_values( $out );
	}

	/** Human labels for the views and the state filter. */
	public static function label( string $state ): string {
		return match ( $state ) {
			'attention' => 'Needs attention',
			'active'    => 'Injected',
			'stale'     => 'Stale but served',
			'holding'   => 'Holding last good',
			'suspended' => 'Suspended',
			'retired'   => 'Retired',
			'inactive'  => 'Not injected',
			'moved'     => 'Moved',
			'conflict'  => 'Other JSON-LD on page',
			default     => 'All',
		};
	}
}
