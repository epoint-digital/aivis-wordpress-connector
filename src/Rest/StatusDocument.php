<?php
/**
 * §11a — the publishing status AIVIS fetches. Built here, served by the REST
 * controller and printed by `wp aivis status --format=json`, so both say the
 * same thing.
 *
 * Nothing in this document is about people (WP-I9): it is the connector's own
 * state — what is published on which page, with which content, since when,
 * and when the site last saw it there. The API token never appears in it.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Rest;

use AivisOS\Cache\AdapterFactory;
use AivisOS\Delivery\Language;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;
use AivisOS\Sync\Scheduler;

final class StatusDocument {

	/** Bump when the document's shape changes incompatibly. */
	public const SCHEMA = 1;

	public function __construct(
		private readonly Options $options,
		private readonly Repository $repository,
		private readonly AdapterFactory $cache
	) {}

	/** @return array<string,mixed> */
	public function build(): array {
		$state     = $this->options->sync_state();
		$conflicts = $this->options->conflicts();
		$items     = [];
		foreach ( (array) ( $conflicts['items'] ?? [] ) as $url => $f ) {
			$items[] = [
				'url'     => (string) $url,
				'sources' => array_values( (array) ( $f['sources'] ?? [] ) ),
				'types'   => array_values( (array) ( $f['types'] ?? [] ) ),
				'blocks'  => (int) ( $f['blocks'] ?? 0 ),
			];
		}
		$verify = (array) ( $state['verify'] ?? [] );
		$next   = Scheduler::next_sync();
		$moved  = [];
		foreach ( (array) ( $state['moved'] ?? [] ) as $m ) {
			$moved[] = [ 'url' => (string) $m['from'], 'currentUrl' => (string) $m['to'], 'since' => gmdate( 'c', (int) $m['since'] ) ];
		}
		return [
			'connector'   => 'aivis-os',
			'version'     => AIVIS_OS_VERSION,
			'schema'      => self::SCHEMA,
			'site'        => $this->options->site_host(),
			'businessId'  => $this->options->business()['business_id'],
			'generatedAt' => gmdate( 'c' ),
			'sync'        => [
				'lastCompleteAt' => ! empty( $state['last_complete_at'] ) ? gmdate( 'c', (int) $state['last_complete_at'] ) : null,
				'authoritative'  => (bool) ( $state['last_authoritative'] ?? false ),
				'intervalSec'    => $this->options->sync_interval(),
				'nextAt'         => $next ? gmdate( 'c', $next ) : null,
				'counts'         => $this->repository->counts(),
			],
			'delivery'    => [
				'injection'    => $this->options->injection_enabled(),
				'lastVerified' => $verify ? [
					'result' => (string) ( $verify['result'] ?? '' ),
					'url'    => $verify['url'] ?? null,
					'at'     => ! empty( $verify['at'] ) ? gmdate( 'c', (int) $verify['at'] ) : null,
				] : null,
				// Pages whose address changed since AIVIS crawled them (§11). A fact; AIVIS decides.
				'moved'        => $moved,
				'movedCheckedAt' => ! empty( $state['moved_checked_at'] ) ? gmdate( 'c', (int) $state['moved_checked_at'] ) : null,
			],
			'cache'       => [
				'adapter'   => $this->cache->adapter()->id(),
				'lastPurge' => $state['last_purge']['state'] ?? null,
				'purgedAt'  => ! empty( $state['last_purge']['at'] ) ? gmdate( 'c', (int) $state['last_purge']['at'] ) : null,
			],
			'languages'   => [
				'provider' => Language::provider(),
				'site'     => array_keys( Language::site_languages() ),
				'chains'   => (object) $this->options->chain_languages(),
				'mismatch' => (object) ( $state['language_mismatch'] ?? [] ),
			],
			'conflicts'   => [
				'fingerprint'   => (string) ( $conflicts['fingerprint'] ?? '' ),
				'acknowledged'  => ! empty( $conflicts['fingerprint'] ) && ( $conflicts['acknowledged'] ?? null ) === $conflicts['fingerprint'],
				'scannedAt'     => ! empty( $conflicts['scanned_at'] ) ? gmdate( 'c', (int) $conflicts['scanned_at'] ) : null,
				'pagesScanned'  => (int) ( $conflicts['pages_scanned'] ?? 0 ),
				'activePlugins' => array_values( (array) ( $conflicts['plugins'] ?? [] ) ),
				'items'         => $items,
			],
			'urls'        => rest_url( StatusController::NS . '/status/urls' ),
		];
	}

	/**
	 * One page of per-URL status.
	 *
	 * @return array{items:list<array<string,mixed>>, nextCursor:?string, hasMore:bool}
	 */
	public function urls( int $after_id = 0, int $limit = 200 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $this->repository->status_rows( $after_id, $limit + 1 );
		$more  = count( $rows ) > $limit;
		$rows  = array_slice( $rows, 0, $limit );
		$moved = (array) ( $this->options->sync_state()['moved'] ?? [] );
		$items = array_map( static fn( array $r ): array => self::item( $r, isset( $moved[ (string) ( $r['url_key'] ?? '' ) ] ) ? (string) $moved[ (string) $r['url_key'] ]['to'] : null ), $rows );
		$last  = $rows ? (int) end( $rows )['id'] : null;
		return [
			'items'      => $items,
			'nextCursor' => $more && null !== $last ? (string) $last : null,
			'hasMore'    => $more,
		];
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	public static function item( array $r, ?string $current_url = null ): array {
		return [
			'url'          => (string) $r['source_url'],
			'currentUrl'   => $current_url,
			'objectType'   => isset( $r['object_type'] ) && '' !== (string) $r['object_type'] ? (string) $r['object_type'] : null,
			'objectId'     => isset( $r['object_id'] ) && '' !== (string) $r['object_id'] ? (string) $r['object_id'] : null,
			'urlId'        => (string) $r['url_id'],
			'chainId'      => (string) $r['chain_id'],
			'languageCode' => (string) ( $r['language_code'] ?? '' ),
			'state'        => self::state_of( $r ),
			'contentHash'  => (string) $r['content_hash'],
			'generatedAt'  => self::ts( $r['source_generated_at'] ?? null ),
			'publishedAt'  => self::ts( $r['published_at'] ?? null ),
			'lastSyncedAt' => self::ts( $r['last_synced_at'] ?? null ),
			'verifiedAt'   => self::ts( $r['verified_at'] ?? null ),
			'verifiedHash' => isset( $r['verified_hash'] ) && '' !== (string) $r['verified_hash'] ? (string) $r['verified_hash'] : null,
			'errorCode'    => isset( $r['last_error_code'] ) && '' !== (string) $r['last_error_code'] ? (string) $r['last_error_code'] : null,
		];
	}

	/**
	 * published | stale | holding | suspended | retired | inactive — the same
	 * vocabulary the Status screen uses, spelled for a machine.
	 *
	 * @param array<string,mixed> $r
	 */
	public static function state_of( array $r ): string {
		if ( ! empty( $r['retired_at'] ) ) {
			return 'retired';
		}
		if ( (int) $r['active'] === 0 ) {
			return 'inactive';
		}
		if ( ! empty( $r['suspended_at'] ) ) {
			return 'suspended';
		}
		if ( isset( $r['last_error_code'] ) && '' !== (string) $r['last_error_code'] ) {
			return 'holding';
		}
		if ( (int) ( $r['source_stale'] ?? 0 ) === 1 ) {
			return 'stale';
		}
		return 'published';
	}

	private static function ts( mixed $mysql ): ?string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}
		$t = strtotime( $mysql . ' UTC' );
		return false === $t ? null : gmdate( 'c', $t );
	}
}
