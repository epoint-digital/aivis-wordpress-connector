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
		$o      = $this->plugin->options();
		if ( $screen && in_array( (string) $screen->id, [ 'dashboard', 'plugins' ], true ) ) {
			// A one-line pointer where admins actually look; the full warning lives
			// on the plugin screens and in the admin bar.
			if ( $o->conflicts_unacknowledged() && current_user_can( Menu::capability() ) ) {
				echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf(
					/* translators: %s: link to the status screen */
					__( '<strong>Aivis-OS:</strong> other plugins are also emitting structured data on this site. Aivis-OS should be the only source — %s.', 'aivis-os' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_STATUS ) ) . '">' . esc_html__( 'review and decide', 'aivis-os' ) . '</a>'
				) ) . '</p></div>';
			}
			return;
		}
		if ( ! $screen || ! str_contains( (string) $screen->id, 'aivis-os' ) ) {
			return;
		}
		$host = $o->site_host();

		if ( isset( $_GET['aivis_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$m = sanitize_key( (string) $_GET['aivis_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ts   = $o->token_status();
			$f    = $ts['failure'] ?? [ 'kind' => '', 'status' => 0, 'detail' => '', 'host' => '' ];
			$api  = $o->api_info();
			// The two instances are named by environment, never by address (§11); this also
			// covers a failure stored by an earlier version.
			$f['host']   = \AivisOS\Storage\Options::instance_label( (string) $f['host'] );
			$f['detail'] = \AivisOS\Storage\Options::name_instances( (string) $f['detail'] );
			$instance    = \AivisOS\Storage\Options::instance_label( (string) wp_parse_url( $o->api_base(), PHP_URL_HOST ) );
			$text = match ( $m ) {
				'connected'      => $ts['bound']
					? sprintf( /* translators: 1: the environment, e.g. "the Test environment", 2: business name */ __( 'Connected to %1$s. The token is valid and bound to %2$s.', 'aivis-os' ), esc_html( $instance ), esc_html( $ts['business_name'] ) )
					: sprintf( /* translators: %s: the environment, e.g. "the Test environment" */ __( 'Connected to %s. The token is valid (account-wide).', 'aivis-os' ), esc_html( $instance ) ),
				'connected_bound' => sprintf( /* translators: 1: the environment, e.g. "the Test environment", 2: business name */ __( 'Connected to %1$s. Bound to %2$s — the business whose domain is this site. A first sync is requested; chains are assigned under Languages & chains where that is unambiguous.', 'aivis-os' ), esc_html( $instance ), esc_html( $o->business()['business_name'] ) ),
				'no_token'       => __( 'Add an API token first.', 'aivis-os' ),
				'token_format'   => __( 'That is not an Aivis-OS token — tokens start with aivis_. Nothing was saved.', 'aivis-os' ),
				'auth_failed'    => sprintf( /* translators: 1: the environment, 2: detail */ __( 'The token was rejected by %1$s (%2$s). Tokens are issued per instance — one created on Test does not work on Production and vice versa — and may have been revoked. Create one on this instance and paste it again.', 'aivis-os' ), esc_html( $f['host'] ), esc_html( $f['detail'] ?: '401' ) ),
				'account_disabled' => sprintf( /* translators: 1: the environment, 2: detail */ __( 'The Aivis-OS account behind this token is deactivated on %1$s (%2$s).', 'aivis-os' ), esc_html( $f['host'] ), esc_html( $f['detail'] ) ),
				'client_too_old' => sprintf( /* translators: 1: minimum version, 2: this version */ __( 'Aivis-OS requires a newer connector: minimum %1$s, this is %2$s. Update the plugin — nothing syncs until then.', 'aivis-os' ), esc_html( $api['min_client'] ?: '?' ), esc_html( AIVIS_OS_VERSION ) ),
				'throttled'      => sprintf( /* translators: %s: the environment */ __( 'This token is rate-limited by %s (429). Try again in a minute.', 'aivis-os' ), esc_html( $f['host'] ) ),
				'unreachable'    => sprintf( /* translators: 1: the environment, 2: detail */ __( 'Could not reach %1$s (%2$s). The token was not checked.', 'aivis-os' ), esc_html( $f['host'] ), esc_html( $f['detail'] ) )
					. ( 'production' === $o->environment() && 'constant' !== $o->api_base_source() ? ' ' . __( 'If the Production environment is not in service yet, switch Environment to Test and save again.', 'aivis-os' ) : '' ),
				'api_error'      => sprintf( /* translators: 1: the environment, 2: detail */ __( 'Unexpected answer from %1$s (%2$s). The token was not verified.', 'aivis-os' ), esc_html( $f['host'] ), esc_html( $f['detail'] ) ),
				'sync_requested' => __( 'Sync requested — it runs on the next cron tick.', 'aivis-os' ),
				'synced'         => __( 'Sync run finished.', 'aivis-os' ),
				'saved'          => __( 'Settings saved.', 'aivis-os' ),
				'disconnected'   => __( 'Disconnected. Local structured data removed and caches purged.', 'aivis-os' ),
				'overridden'     => __( 'Override recorded. The warning stays silent until the set of conflicts changes.', 'aivis-os' ),
				'scan_clean'     => __( 'Scan finished — no other structured data found on the sampled pages.', 'aivis-os' ),
				'scan_conflicts' => __( 'Scan finished — other structured data found. See the warning below.', 'aivis-os' ),
				'status_key_regenerated' => __( 'New status key issued. The old one stops working now — update it in Aivis-OS.', 'aivis-os' ),
				'status_key_disabled'    => __( 'Status endpoint disabled. Aivis-OS can no longer fetch this site’s publishing status.', 'aivis-os' ),
				'bulk_refresh'     => __( 'Refresh scheduled for the selected pages — they are re-checked against Aivis-OS on the next cron tick.', 'aivis-os' ),
				'bulk_disabled'    => __( 'Selected pages disabled here and their caches purged.', 'aivis-os' ),
				'bulk_restored'    => __( 'Selected pages restored and their caches purged.', 'aivis-os' ),
				'nothing_selected' => __( 'Nothing selected.', 'aivis-os' ),
				'environment_switched' => __( 'Environment switched. The business is unbound and everything synced from the other instance stopped being served — test the connection and bind again.', 'aivis-os' ),
				'refreshed'        => __( 'Page re-checked against Aivis-OS.', 'aivis-os' ),
				'updated'          => __( 'Page updated.', 'aivis-os' ),
				default          => '',
			};
			if ( '' !== $text ) {
				$kind = in_array( $m, [ 'auth_failed', 'account_disabled', 'client_too_old', 'unreachable', 'api_error', 'token_format', 'no_token' ], true ) ? 'error' : ( 'throttled' === $m ? 'warning' : 'success' );
				echo '<div class="notice notice-' . esc_attr( $kind ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}

		$this->contract_notice( $o );

		$this->conflict_notice( $o );

		if ( 'test' === $o->environment() && 'constant' !== $o->api_base_source() ) {
			$this->notice( 'info', __( '<strong>This site talks to the Aivis-OS test instance.</strong> Switch to Production under Settings → Connection before go-live.', 'aivis-os' ) );
		}

		if ( 'none' === $o->token_source() ) {
			$this->notice( 'warning', __( '<strong>Aivis-OS is not connected yet.</strong> Add an API token under Settings to start delivering structured data. Nothing is injected until a business is bound.', 'aivis-os' ) );
			return;
		}
		$biz = $o->business();
		if ( '' === $biz['business_id'] ) {
			$this->notice( 'warning', sprintf(
				/* translators: %s: site host */
				__( '<strong>No business is bound yet.</strong> Pick the Aivis-OS business whose domain is <code>%s</code> under Settings.', 'aivis-os' ),
				esc_html( $host )
			) );
		}
		$state = $o->sync_state();
		$diag  = array_slice( $o->diagnostics(), -20 );
		$recent_auth = array_filter( $diag, static fn( $d ) => 'AIVIS_AUTH_401' === $d['code'] && time() - $d['t'] < HOUR_IN_SECONDS );
		if ( $recent_auth ) {
			$this->notice( 'error', __( '<strong>The API token was rejected.</strong> Syncing has stopped; pages keep serving their last known good structured data. Create a new token in Aivis-OS and update it here.', 'aivis-os' ) );
		}
		$recent_transport = array_filter( $diag, static fn( $d ) => in_array( $d['code'], [ 'AIVIS_HTTP_TIMEOUT', 'AIVIS_HTTP_ERROR' ], true ) && time() - $d['t'] < 30 * MINUTE_IN_SECONDS );
		if ( count( $recent_transport ) >= 3 ) {
			$this->notice( 'warning', __( '<strong>Aivis-OS has been unreachable recently.</strong> Every page keeps serving its last known good structured data. Nothing is removed while the API is unreachable — an outage costs freshness, never coverage.', 'aivis-os' ) );
		}
		$counts = $this->plugin->repository()->counts();
		if ( $counts['suspended'] > 0 ) {
			$this->notice( 'error', sprintf(
				/* translators: %d: number of pages */
				_n( '<strong>%d page was withdrawn in Aivis-OS.</strong> Injection stopped and its cache was purged. The row is kept until the next complete sync confirms the removal.', '<strong>%d pages were withdrawn in Aivis-OS.</strong> Injection stopped and their caches were purged. The rows are kept until the next complete sync confirms the removal.', $counts['suspended'], 'aivis-os' ),
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

	/**
	 * §03 — the instance's minimum client. Red once Aivis-OS answers 426 (or the
	 * announced minimum is above this version); amber while a raised minimum
	 * is only announced (`nextMinClient`), with its date.
	 */
	private function contract_notice( \AivisOS\Storage\Options $o ): void {
		$api = $o->api_info();
		if ( true === $o->client_too_old() ) {
			$this->notice( 'error', sprintf(
				/* translators: 1: minimum version, 2: this version */
				__( '<strong>Update the Aivis-OS plugin.</strong> Aivis-OS requires connector %1$s or newer; this is %2$s. Syncing has stopped; pages keep serving their last known good structured data.', 'aivis-os' ),
				esc_html( $api['min_client'] ?: '?' ),
				esc_html( AIVIS_OS_VERSION )
			) );
			return;
		}
		$next = $api['next_min_client'];
		if ( null !== $next && version_compare( AIVIS_OS_VERSION, $next['version'], '<' ) ) {
			$this->notice( 'warning', sprintf(
				/* translators: 1: version, 2: date, 3: this version */
				__( '<strong>Aivis-OS will require connector %1$s from %2$s.</strong> This is %3$s — update the plugin before then.', 'aivis-os' ),
				esc_html( $next['version'] ),
				esc_html( $next['effective_from'] ?: '?' ),
				esc_html( AIVIS_OS_VERSION )
			) );
		}
	}

	/** §09a — Aivis-OS is the primary source. Red, with a way out and a way through. */
	private function conflict_notice( \AivisOS\Storage\Options $o ): void {
		$c = $o->conflicts();
		if ( ! empty( $c['items'] ) && $o->conflicts_unacknowledged() ) {
			$labels = [];
			foreach ( $c['items'] as $f ) {
				foreach ( (array) ( $f['sources'] ?? [] ) as $s ) {
					$labels[ (string) $s ] = \AivisOS\Delivery\Conflicts::label( (string) $s );
				}
			}
			echo '<div class="notice notice-error"><p>';
			echo wp_kses_post( sprintf(
				/* translators: 1: pages with conflicts, 2: pages scanned, 3: comma-separated sources */
				__( '<strong>Other structured data found on %1$d of %2$d scanned pages</strong> — from %3$s. Aivis-OS should be the only source of JSON-LD on this site: two <code>Organization</code> or <code>WebSite</code> nodes on one page give search engines conflicting answers.', 'aivis-os' ),
				count( $c['items'] ),
				(int) $c['pages_scanned'],
				esc_html( implode( ', ', $labels ) )
			) );
			echo '</p><p>';
			echo wp_kses_post( __( 'Switch the structured-data output off in the other plugin or theme — <strong>Settings → Structured data sources</strong> says where for each one. The connector never changes another plugin itself. Publishing continues either way.', 'aivis-os' ) );
			echo '</p><p>';
			echo Menu::action_form( 'acknowledge_conflicts', __( 'Override — I know, keep publishing', 'aivis-os' ), [], 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo ' ';
			echo Menu::action_form( 'scan_conflicts', __( 'Scan again', 'aivis-os' ), [], 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS . '#aivis-sources' ) ) . '">' . esc_html__( 'Structured data sources', 'aivis-os' ) . '</a>';
			echo '</p></div>';
			return;
		}
		$plugins = $c['plugins'] ?: \AivisOS\Delivery\Conflicts::active_plugins();
		if ( $plugins && 0 === (int) $c['scanned_at'] ) {
			$names = array_map( [ \AivisOS\Delivery\Conflicts::class, 'label' ], $plugins );
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses_post( sprintf(
				/* translators: %s: plugin names */
				__( '<strong>%s is active</strong> and usually emits its own JSON-LD. Aivis-OS should be the only source — run a scan to see what these pages actually carry.', 'aivis-os' ),
				esc_html( implode( ', ', $names ) )
			) );
			echo '</p><p>' . Menu::action_form( 'scan_conflicts', __( 'Scan for other structured data', 'aivis-os' ), [], 'button' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	private function notice( string $kind, string $html ): void {
		echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>' . wp_kses( $html, [ 'strong' => [], 'code' => [] ] ) . '</p></div>';
	}
}
