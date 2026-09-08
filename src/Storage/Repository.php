<?php
/**
 * Persistence for artifacts in {prefix}aivis_jsonld.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Storage;

use AivisOS\Domain\ErrorCode;

final class Repository {

	private function table(): string {
		return Schema::table();
	}

	/** @return array<string,mixed>|null */
	public function find_by_key( string $url_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE url_key = %s LIMIT 1", $url_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * The one query the public render path is allowed to make (WP-I2): an
	 * indexed lookup returning only what injection needs.
	 *
	 * @return array{json_ld:string, content_hash:string}|null
	 */
	public function find_active_for_render( string $url_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT json_ld, content_hash FROM {$this->table()}
				  WHERE url_key = %s AND active = 1 AND retired_at IS NULL AND suspended_at IS NULL
				  LIMIT 1",
				$url_key
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * ZT-06 atomic commit. `$serialized` is already validated and re-serialized
	 * by the caller; the hash is computed from the exact bytes stored. On any
	 * failure the previous row is untouched.
	 *
	 * @param array<string,mixed> $fields url_key, source_url, url_id, chain_id,
	 *        business_id, language_code, source_generated_at, source_stale,
	 *        local_post_id, sync_id.
	 * @return array{changed:bool, activated:bool, hash:string}|null null on DB failure.
	 */
	public function upsert_artifact( array $fields, string $serialized ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', $serialized );
		$now  = current_time( 'mysql', true );
		$prev = $this->find_by_key( (string) $fields['url_key'] );

		$was_active = $prev && (int) $prev['active'] === 1 && empty( $prev['retired_at'] ) && empty( $prev['suspended_at'] );
		$changed    = ! $prev || $prev['content_hash'] !== $hash;
		// published_at (§11a): when what the page serves last changed — a new
		// hash, or a row coming back into service. Unchanged content keeps it.
		$published = ( $changed || ! $was_active || empty( $prev['published_at'] ) ) ? $now : (string) $prev['published_at'];

		$data = [
			'url_key'               => (string) $fields['url_key'],
			'source_url'            => (string) $fields['source_url'],
			'url_id'                => (string) $fields['url_id'],
			'chain_id'              => (string) $fields['chain_id'],
			'business_id'           => (string) $fields['business_id'],
			'language_code'         => (string) ( $fields['language_code'] ?? '' ),
			'json_ld'               => $serialized,
			'content_hash'          => $hash,
			'source_generated_at'   => $fields['source_generated_at'] ?? null,
			'source_stale'          => ! empty( $fields['source_stale'] ) ? 1 : 0,
			'active'                => 1,
			'local_post_id'         => $fields['local_post_id'] ?? null,
			'last_seen_sync_id'     => (string) ( $fields['sync_id'] ?? '' ),
			'missing_complete_runs' => 0,
			'last_synced_at'        => $now,
			'suspended_at'          => null,
			'retired_at'            => null,
			'last_error_code'       => null,
			'published_at'          => $published,
			'object_type'           => $fields['object_type'] ?? null,
			'object_id'             => isset( $fields['object_id'] ) ? (string) $fields['object_id'] : null,
		];
		$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $prev ) {
			$ok = $wpdb->update( $this->table(), $data, [ 'id' => (int) $prev['id'] ], $format, [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$ok = $wpdb->insert( $this->table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return null;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return [
			'changed'   => $changed,
			'activated' => ! $was_active,
			'hash'      => $hash,
		];
	}

	/** The row delivered for a WordPress object, if any (§11 edit-screen box). */
	public function find_by_object( string $type, string $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE object_type = %s AND object_id = %s ORDER BY id DESC LIMIT 1", $type, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Active rows with an object reference — the daily "moved" check compares
	 * each object's current address with the URL AIVIS crawled (§11).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function rows_with_objects( int $limit = 2000 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT url_key, source_url, object_type, object_id FROM {$this->table()}
				  WHERE active = 1 AND retired_at IS NULL AND object_type IS NOT NULL ORDER BY id ASC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);
	}

	/** §11a — a loopback fetch found this exact content on the page. */
	public function mark_verified( string $url_key, string $hash ): void {
		$this->patch( $url_key, [ 'verified_at' => current_time( 'mysql', true ), 'verified_hash' => $hash ] );
	}

	/**
	 * §11a — the per-page publishing status AIVIS fetches, paged by id so a
	 * consumer can walk the whole table without offsets drifting.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function status_rows( int $after_id = 0, int $limit = 200 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, url_key, source_url, url_id, chain_id, language_code, content_hash, source_generated_at, source_stale,
				        active, suspended_at, retired_at, last_error_code, last_synced_at, published_at, verified_at, verified_hash,
				        object_type, object_id
				   FROM {$this->table()} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$after_id,
				max( 1, min( 501, $limit ) ) // 500 is the API page cap; +1 is the lookahead row (#60)
			),
			ARRAY_A
		);
	}

	/** R-01a / transport: keep serving, note why. */
	public function mark_hold( string $url_key, string $code, string $sync_id ): void {
		$this->patch( $url_key, [ 'last_error_code' => $code, 'last_seen_sync_id' => $sync_id ] );
	}

	/** R-01: stop injecting, keep the row, await inventory confirmation. */
	public function suspend( string $url_key, string $sync_id ): void {
		$this->patch(
			$url_key,
			[
				'suspended_at'      => current_time( 'mysql', true ),
				'last_error_code'   => ErrorCode::RETRACTED,
				'last_seen_sync_id' => $sync_id,
			]
		);
	}

	/** Suspicion not confirmed (URL reappeared): serve again. */
	public function unsuspend( string $url_key ): void {
		$this->patch( $url_key, [ 'suspended_at' => null, 'last_error_code' => null ] );
	}

	/** R-02a: ready:false on an authoritative pass. */
	public function deactivate( string $url_key, string $code ): void {
		$this->patch( $url_key, [ 'active' => 0, 'last_error_code' => $code ] );
	}

	/** R-02 / confirmed R-01: retire; kept 30 days for rollback, never injected. */
	public function retire( string $url_key, string $code ): void {
		$this->patch(
			$url_key,
			[
				'active'          => 0,
				'retired_at'      => current_time( 'mysql', true ),
				'last_error_code' => $code,
			]
		);
	}

	/** Admin "Restore". */
	public function restore( string $url_key ): void {
		$this->patch(
			$url_key,
			[
				'active'                => 1,
				'retired_at'            => null,
				'suspended_at'          => null,
				'missing_complete_runs' => 0,
				'last_error_code'       => null,
			]
		);
	}

	public function mark_seen( string $url_key, string $sync_id ): void {
		$this->patch( $url_key, [ 'last_seen_sync_id' => $sync_id, 'missing_complete_runs' => 0 ] );
	}

	/** @return int the new count */
	public function increment_missing( string $url_key ): int {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$this->table()} SET missing_complete_runs = missing_complete_runs + 1 WHERE url_key = %s",
				$url_key
			)
		);
		$row = $this->find_by_key( $url_key );
		return $row ? (int) $row['missing_complete_runs'] : 0;
	}

	/**
	 * Rows for the business that a given authoritative sync did NOT see.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function unseen_in_sync( string $business_id, string $sync_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table()}
				  WHERE business_id = %s AND retired_at IS NULL AND last_seen_sync_id <> %s",
				$business_id,
				$sync_id
			),
			ARRAY_A
		);
	}

	/**
	 * §07a — a chain that is no longer assigned to a language stops serving at
	 * once; its rows are deactivated (not retired: re-assigning brings them
	 * back on the next sync) and their URLs returned for the cache purge.
	 *
	 * @param list<string> $assigned_chain_ids
	 * @return list<string> source URLs deactivated
	 */
	public function deactivate_chains_not_in( array $assigned_chain_ids, string $code ): array {
		global $wpdb;
		$t   = $this->table();
		$ids = array_values( array_filter( array_map( 'strval', $assigned_chain_ids ) ) );
		$not = $ids ? 'AND chain_id NOT IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')' : '';
		$sql = "SELECT source_url FROM {$t} WHERE active = 1 AND retired_at IS NULL {$not}";
		$urls = (array) $wpdb->get_col( $ids ? $wpdb->prepare( $sql, ...$ids ) : $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		if ( ! $urls ) {
			return [];
		}
		$upd = "UPDATE {$t} SET active = 0, last_error_code = %s WHERE active = 1 AND retired_at IS NULL {$not}";
		$wpdb->query( $wpdb->prepare( $upd, $code, ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return array_values( array_map( 'strval', $urls ) );
	}

	/**
	 * A chain that disappeared from AIVIS's own listing took its pages with it
	 * (#56): retire every row of those chains now — kept 30 days for rollback,
	 * never injected — and return the URLs for the cache purge.
	 *
	 * @param list<string> $chain_ids
	 * @return list<string> source URLs retired
	 */
	public function retire_chains( array $chain_ids, string $code ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'strval', $chain_ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$t    = $this->table();
		$in   = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$urls = (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_url FROM {$t} WHERE retired_at IS NULL AND chain_id IN ({$in})", ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		if ( ! $urls ) {
			return [];
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET active = 0, retired_at = %s, last_error_code = %s WHERE retired_at IS NULL AND chain_id IN ({$in})", $now, $code, ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return array_values( array_map( 'strval', $urls ) );
	}

	/**
	 * Every URL currently injectable — what a cache purge has to cover when
	 * injection is switched off or on (#61).
	 *
	 * @return list<string>
	 */
	public function active_urls(): array {
		global $wpdb;
		return array_values( array_map( 'strval', (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT source_url FROM {$this->table()} WHERE active = 1 AND retired_at IS NULL AND suspended_at IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) ) );
	}

	/**
	 * Per-chain counts for the languages table (§11).
	 *
	 * @return array<string,array{active:int,hold:int,suspended:int,inactive:int,total:int}> chain_id => counts
	 */
	public function counts_by_chain(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT chain_id,
				SUM(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND last_error_code IS NULL) AS active,
				SUM(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND last_error_code IS NOT NULL) AS hold,
				SUM(suspended_at IS NOT NULL AND retired_at IS NULL) AS suspended,
				SUM(active = 0 AND retired_at IS NULL) AS inactive,
				COUNT(*) AS total
			 FROM {$this->table()} GROUP BY chain_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (string) ( $r['chain_id'] ?? '' ) ] = [
				'active'    => (int) ( $r['active'] ?? 0 ),
				'hold'      => (int) ( $r['hold'] ?? 0 ),
				'suspended' => (int) ( $r['suspended'] ?? 0 ),
				'inactive'  => (int) ( $r['inactive'] ?? 0 ),
				'total'     => (int) ( $r['total'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * The Pages screen's query (§11). $where comes from Admin\PagesQuery,
	 * $orderby is whitelisted there.
	 *
	 * @param list<string> $args
	 * @return array{rows:list<array<string,mixed>>, total:int}
	 */
	public function search( string $where, array $args, int $per_page, int $page, string $orderby, string $order ): array {
		global $wpdb;
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, ( max( 1, $page ) - 1 ) * $per_page );
		$orderby  = preg_replace( '/[^a-z_]/', '', $orderby ) ?: 'source_url';
		$order    = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';
		$total    = $this->count_where( $where, $args );
		$rows     = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT * FROM {$this->table()} WHERE {$where} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d",
				...array_merge( $args, [ $per_page, $offset ] )
			),
			ARRAY_A
		);
		return [ 'rows' => array_values( $rows ), 'total' => $total ];
	}

	/** @param list<string> $args */
	public function count_where( string $where, array $args ): int {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/** Distinct values of a filterable column, for the Pages screen's dropdowns. @return list<string> */
	public function distinct( string $column ): array {
		global $wpdb;
		$column = in_array( $column, [ 'language_code', 'chain_id', 'business_id' ], true ) ? $column : 'language_code';
		return array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$this->table()} ORDER BY {$column} ASC" ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param list<string> $keys @return int rows touched */
	public function retire_many( array $keys, string $code ): int {
		$n = 0;
		foreach ( $keys as $k ) {
			$this->retire( (string) $k, $code );
			$n++;
		}
		return $n;
	}

	/** @param list<string> $keys @return int rows touched */
	public function restore_many( array $keys ): int {
		$n = 0;
		foreach ( $keys as $k ) {
			$this->restore( (string) $k );
			$n++;
		}
		return $n;
	}

	/** @param list<string> $keys @return list<string> source URLs */
	public function urls_for_keys( array $keys ): array {
		global $wpdb;
		$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );
		if ( ! $keys ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return array_values( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT source_url FROM {$this->table()} WHERE url_key IN ({$in})", ...$keys ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/** @return list<array<string,mixed>> */
	public function all_for_admin( int $limit = 500 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY source_url ASC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/** @return array{active:int, stale:int, hold:int, suspended:int, retired:int, total:int} */
	public function counts(): array {
		global $wpdb;
		$t = $this->table();
		$r = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT
				SUM(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND source_stale = 0 AND last_error_code IS NULL) AS active,
				SUM(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND source_stale = 1) AS stale,
				SUM(active = 1 AND retired_at IS NULL AND suspended_at IS NULL AND last_error_code IS NOT NULL AND source_stale = 0) AS hold,
				SUM(suspended_at IS NOT NULL AND retired_at IS NULL) AS suspended,
				SUM(retired_at IS NOT NULL) AS retired,
				COUNT(*) AS total
			 FROM {$t}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: [];
		return [
			'active'    => (int) ( $r['active'] ?? 0 ),
			'stale'     => (int) ( $r['stale'] ?? 0 ),
			'hold'      => (int) ( $r['hold'] ?? 0 ),
			'suspended' => (int) ( $r['suspended'] ?? 0 ),
			'retired'   => (int) ( $r['retired'] ?? 0 ),
			'total'     => (int) ( $r['total'] ?? 0 ),
		];
	}

	/** GC: rows retired longer ago than $days. @return int deleted */
	public function delete_retired_before( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$this->table()} WHERE retired_at IS NOT NULL AND retired_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table()}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string,mixed> $patch */
	private function patch( string $url_key, array $patch ): void {
		global $wpdb;
		$wpdb->update( $this->table(), $patch, [ 'url_key' => $url_key ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
