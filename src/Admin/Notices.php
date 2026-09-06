<?php
/**
 * Admin notices — the plugin's states, said plainly (§11, docs/admin-ui.html).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;

final class Notices {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, 'aivis-os' ) ) {
			return;
		}
		$o    = $this->plugin->options();
		$host = $o->site_host();

		if ( isset( $_GET['aivis_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$m = sanitize_key( (string) $_GET['aivis_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$text = match ( $m ) {
				'connected'      => __( 'Connected. The token is valid.', 'aivis-os' ),
				'auth_failed'    => __( 'Token invalid or revoked.', 'aivis-os' ),
				'sync_requested' => __( 'Sync requested — it runs on the next cron tick.', 'aivis-os' ),
				'synced'         => __( 'Sync run finished.', 'aivis-os' ),
				'saved'          => __( 'Settings saved.', 'aivis-os' ),
				'disconnected'   => __( 'Disconnected. Local structured data removed and caches purged.', 'aivis-os' ),
				default          => '',
			};
			if ( '' !== $text ) {
				$kind = 'auth_failed' === $m ? 'error' : 'success';
				echo '<div class="notice notice-' . esc_attr( $kind ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}

		if ( 'none' === $o->token_source() ) {
			$this->notice( 'warning', __( '<strong>AIVIS OS is not connected yet.</strong> Add an API token under Settings to start delivering structured data. Nothing is injected until a business is bound.', 'aivis-os' ) );
			return;
		}
		$biz = $o->business();
		if ( '' === $biz['business_id'] ) {
			$this->notice( 'warning', sprintf(
				/* translators: %s: site host */
				__( '<strong>No business is bound yet.</strong> Pick the AIVIS business whose domain is <code>%s</code> under Settings.', 'aivis-os' ),
				esc_html( $host )
			) );
		}
		$state = $o->sync_state();
		$diag  = array_slice( $o->diagnostics(), -20 );
		$recent_auth = array_filter( $diag, static fn( $d ) => 'AIVIS_AUTH_401' === $d['code'] && time() - $d['t'] < HOUR_IN_SECONDS );
		if ( $recent_auth ) {
			$this->notice( 'error', __( '<strong>The API token was rejected.</strong> Syncing has stopped; pages keep serving their last known good structured data. Create a new token in AIVIS and update it here.', 'aivis-os' ) );
		}
		$recent_transport = array_filter( $diag, static fn( $d ) => in_array( $d['code'], [ 'AIVIS_HTTP_TIMEOUT', 'AIVIS_HTTP_ERROR' ], true ) && time() - $d['t'] < 30 * MINUTE_IN_SECONDS );
		if ( count( $recent_transport ) >= 3 ) {
			$this->notice( 'warning', __( '<strong>AIVIS has been unreachable recently.</strong> Every page keeps serving its last known good structured data. Nothing is removed while the API is unreachable — an outage costs freshness, never coverage.', 'aivis-os' ) );
		}
		$counts = $this->plugin->repository()->counts();
		if ( $counts['suspended'] > 0 ) {
			$this->notice( 'error', sprintf(
				/* translators: %d: number of pages */
				_n( '<strong>%d page was withdrawn in AIVIS.</strong> Injection stopped and its cache was purged. The row is kept until the next complete sync confirms the removal.', '<strong>%d pages were withdrawn in AIVIS.</strong> Injection stopped and their caches were purged. The rows are kept until the next complete sync confirms the removal.', $counts['suspended'], 'aivis-os' ),
				$counts['suspended']
			) );
		}
		if ( 'manual' === $this->plugin->cache()->adapter()->id() ) {
			$this->notice( 'warning', __( '<strong>No supported cache plugin detected.</strong> Changed structured data is written locally, but the plugin cannot confirm your cache was purged, so it will not claim pages are live. Purge manually after a change, or use WP-CLI.', 'aivis-os' ) );
		}
		if ( ! $o->injection_enabled() ) {
			$this->notice( 'warning', __( '<strong>Injection is switched off.</strong> Artifacts keep syncing, but no structured data is added to any page.', 'aivis-os' ) );
		}
		if ( ( $state['last_authoritative'] ?? null ) === false ) {
			$this->notice( 'info', __( 'The last sync did not complete fully, so nothing was retired. It will retry on the next tick.', 'aivis-os' ) );
		}
	}

	private function notice( string $kind, string $html ): void {
		echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>' . wp_kses( $html, [ 'strong' => [], 'code' => [] ] ) . '</p></div>';
	}
}
