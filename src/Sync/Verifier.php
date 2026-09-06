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

use AivisOS\Storage\Options;
use AivisOS\Storage\Repository;

final class Verifier {

	public function __construct(
		private readonly Repository $repository,
		private readonly Options $options
	) {}

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
		$res    = wp_safe_remote_get( $target, [ 'timeout' => 10, 'redirection' => 2, 'sslverify' => false, 'user-agent' => 'aivis-os-verify/' . AIVIS_OS_VERSION ] );
		if ( is_wp_error( $res ) ) {
			return $this->done( 'could-not-verify', $target, 'loopback blocked: ' . $res->get_error_message() . ' — check in a browser or run wp aivis verify' );
		}
		$html = (string) wp_remote_retrieve_body( $res );
		if ( ! str_contains( $html, 'data-aivis="1"' ) ) {
			return $this->done( 'marker-missing', $target, 'page served without the AIVIS block — the page cache may not be purged, or the theme does not call wp_head()' );
		}
		if ( ! str_contains( $html, (string) $row['json_ld'] ) ) {
			return $this->done( 'stale-on-page', $target, 'marker present but content differs from the stored artifact — cache not yet purged' );
		}
		return $this->done( 'live', $target, 'marker and current content present' );
	}

	/** @return array{result:string, url:?string, detail:string} */
	private function done( string $result, ?string $url, string $detail ): array {
		$this->options->patch_sync_state( [ 'verify' => [ 'result' => $result, 'url' => $url, 'detail' => $detail, 'at' => time() ] ] );
		return [ 'result' => $result, 'url' => $url, 'detail' => $detail ];
	}
}
