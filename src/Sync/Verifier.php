<?php
/**
 * Live verification (§11): fetch a sample page over loopback and confirm the
 * marker and the expected hash are present. The one remaining
 * "verify at build": loopback is blocked on some hosts, so a failure to
 * connect is reported as "could not verify", never as "not live".
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Delivery\Conflicts;
use AivisOS\Delivery\Markup;
use AivisOS\Delivery\ObjectResolver;
use AivisOS\Domain\ErrorCode;
use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;

final class Verifier {

	public const SCAN_PAGES  = 10;
	public const MOVED_LIMIT = 2000;

	public function __construct(
		private readonly Repository $repository,
		private readonly Options $options
	) {}

	/** The daily job: verify one page, scan for conflicts, notice moved pages. */
	public function daily(): void {
		$this->run();
		$this->scan_conflicts();
		$this->check_moved();
	}

	/**
	 * §11 — pages whose address changed since AIVIS crawled them. A fact for
	 * the Status screen, Site Health and the status document; nothing is
	 * decided here. The row stays keyed to the URL AIVIS has, and AIVIS
	 * decides whether to re-crawl, redirect or retire.
	 *
	 * @return array{checked:int, moved:int}
	 */
	public function check_moved(): array {
		$prev  = (array) ( $this->options->sync_state()['moved'] ?? [] );
		$moved = [];
		$rows  = $this->repository->rows_with_objects( self::MOVED_LIMIT );
		foreach ( $rows as $row ) {
			$now = ObjectResolver::url_of( (string) $row['object_type'], (string) $row['object_id'] );
			if ( null === $now || ObjectResolver::same_url( $now, (string) $row['source_url'] ) ) {
				continue;
			}
			$key           = (string) $row['url_key'];
			$moved[ $key ] = [
				'from'  => (string) $row['source_url'],
				'to'    => $now,
				'since' => (int) ( $prev[ $key ]['since'] ?? time() ),
			];
		}
		$this->options->patch_sync_state( [ 'moved' => $moved, 'moved_checked_at' => time(), 'moved_checked' => count( $rows ) ] );
		$new = array_diff_key( $moved, $prev );
		if ( $new ) {
			$this->options->record( ErrorCode::URL_MOVED, sprintf( '%d page(s) changed address since AIVIS crawled them; AIVIS still has the old URL', count( $new ) ), (string) reset( $new )['from'] );
		}
		return [ 'checked' => count( $rows ), 'moved' => count( $moved ) ];
	}

	/**
	 * §09a — fetch up to SCAN_PAGES active pages over loopback and find every
	 * JSON-LD block that is not ours. Merges into the conflict store, computes
	 * the fingerprint, and notifies when the set changed and is not the
	 * acknowledged one.
	 *
	 * @return array{pages:int, conflicts:int, changed:bool, unreachable:int}
	 */
	public function scan_conflicts( int $limit = self::SCAN_PAGES ): array {
		$prev  = $this->options->conflicts();
		$items = [];
		$pages = 0;
		$down  = 0;
		foreach ( $this->repository->all_for_admin( 200 ) as $row ) {
			if ( $pages >= $limit ) {
				break;
			}
			if ( (int) $row['active'] !== 1 || ! empty( $row['retired_at'] ) ) {
				continue;
			}
			$url = (string) $row['source_url'];
			$res = wp_safe_remote_get( $url, self::request_args() );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				$down++;
				continue;
			}
			$pages++;
			$html = (string) wp_remote_retrieve_body( $res );
			if ( Markup::serves( $html, (string) $row['json_ld'] )['current'] ) {
				// Our element, uncommented, with exactly the stored bytes: that is what "published" means (§11a).
				$this->repository->mark_verified( (string) $row['url_key'], (string) $row['content_hash'] );
			}
			$scan = Conflicts::scan_html( $html );
			if ( $scan['blocks'] > 0 ) {
				$items[ $url ] = $scan + [ 'seen_at' => time() ];
			}
		}
		$fingerprint = Conflicts::fingerprint( $items );
		$changed     = $fingerprint !== $prev['fingerprint'];
		$this->options->patch_conflicts(
			[
				'fingerprint'   => $fingerprint,
				'scanned_at'    => time(),
				'pages_scanned' => $pages,
				'items'         => $items,
				'plugins'       => Conflicts::active_plugins(),
			]
		);
		return [ 'pages' => $pages, 'conflicts' => count( $items ), 'changed' => $changed, 'unreachable' => $down ];
	}

	/** @return array{result:string, url:?string, detail:string} */
	public function run( ?string $url = null ): array {
		$row = null;
		if ( null !== $url ) {
			try {
				$row = $this->repository->find_by_key( \AivisOS\Domain\UrlKey::of( $url ) );
			} catch ( \Throwable ) {
				$row = null;
			}
		} else {
			foreach ( $this->repository->all_for_admin( 50 ) as $cand ) {
				if ( (int) $cand['active'] === 1 && empty( $cand['retired_at'] ) && empty( $cand['suspended_at'] ) ) {
					$row = $cand;
					break;
				}
			}
		}
		if ( null === $row ) {
			return $this->done( 'nothing-to-verify', null, 'no active artifact' );
		}
		$target = (string) $row['source_url'];
		$res    = wp_safe_remote_get( $target, self::request_args() );
		if ( is_wp_error( $res ) ) {
			return $this->done( 'could-not-verify', $target, 'loopback blocked: ' . $res->get_error_message() . ' — check in a browser or run wp aivis verify' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			// A non-200 is not evidence about the block either way.
			return $this->done( 'could-not-verify', $target, "loopback answered HTTP {$code} — verify in a browser or run wp aivis verify" );
		}
		$served = Markup::serves( (string) wp_remote_retrieve_body( $res ), (string) $row['json_ld'] );
		if ( ! $served['present'] ) {
			return $this->done( 'marker-missing', $target, 'page served without a live AIVIS block — the page cache may not be purged, or the theme does not call wp_head()' );
		}
		if ( ! $served['current'] ) {
			return $this->done( 'stale-on-page', $target, 'AIVIS block present but its content differs from the stored artifact — cache not yet purged' );
		}
		$this->repository->mark_verified( (string) $row['url_key'], (string) $row['content_hash'] );
		return $this->done( 'live', $target, 'live AIVIS block with the current content' );
	}

	/**
	 * Loopback request settings. TLS is verified by default (#59); a host whose
	 * loopback presents a certificate for another name can relax it:
	 *
	 *     add_filter( 'aivis_connector_verify_sslverify', '__return_false' );
	 *
	 * @return array<string,mixed>
	 */
	public static function request_args(): array {
		return [
			'timeout'     => 10,
			'redirection' => 2,
			'sslverify'   => (bool) apply_filters( 'aivis_connector_verify_sslverify', true ),
			'user-agent'  => 'aivis-os-verify/' . AIVIS_OS_VERSION,
		];
	}

	/** @return array{result:string, url:?string, detail:string} */
	private function done( string $result, ?string $url, string $detail ): array {
		$this->options->patch_sync_state( [ 'verify' => [ 'result' => $result, 'url' => $url, 'detail' => $detail, 'at' => time() ] ] );
		return [ 'result' => $result, 'url' => $url, 'detail' => $detail ];
	}
}
