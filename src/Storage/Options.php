<?php
/**
 * Typed access to the plugin's options and constants.
 *
 * Every option is stored with autoload=false (§05): none of them is needed
 * on the public render path, which reads only the artifact table.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Storage;

final class Options {

	/** Production. Dev/staging override via the AIVIS_API_BASE_URL constant (Q-01). */
	public const DEFAULT_API_BASE = 'https://app.aivis-os.com';
	public const DIAGNOSTICS_CAP  = 100;

	/* ── token (§13) ──────────────────────────────────────────────────── */

	/** 'constant' | 'option' | 'none' */
	public function token_source(): string {
		if ( defined( 'AIVIS_API_TOKEN' ) && is_string( AIVIS_API_TOKEN ) && '' !== AIVIS_API_TOKEN ) {
			return 'constant';
		}
		return '' !== (string) get_option( 'aivis_os_token', '' ) ? 'option' : 'none';
	}

	/** Never log, echo or expose the return value (WP-I6). */
	public function token(): string {
		if ( 'constant' === $this->token_source() ) {
			return (string) AIVIS_API_TOKEN;
		}
		return (string) get_option( 'aivis_os_token', '' );
	}

	public function set_token( string $token ): void {
		if ( '' === $token ) {
			delete_option( 'aivis_os_token' );
			return;
		}
		update_option( 'aivis_os_token', $token, false );
	}

	/** Safe display handle: prefix and last four, never the middle. */
	public function token_display(): string {
		$t = $this->token();
		if ( strlen( $t ) < 12 ) {
			return '' === $t ? '' : 'aivis_…';
		}
		return 'aivis_…' . substr( $t, -4 );
	}

	/** @return array{valid:bool|null, token_name:string, email:string, checked_at:int} */
	public function token_status(): array {
		$d = (array) get_option( 'aivis_os_token_status', [] );
		return [
			'valid'      => $d['valid'] ?? null,
			'token_name' => (string) ( $d['token_name'] ?? '' ),
			'email'      => (string) ( $d['email'] ?? '' ),
			'checked_at' => (int) ( $d['checked_at'] ?? 0 ),
		];
	}

	public function set_token_status( ?bool $valid, string $token_name = '', string $email = '' ): void {
		update_option(
			'aivis_os_token_status',
			[
				'valid'      => $valid,
				'token_name' => $token_name,
				'email'      => $email,
				'checked_at' => time(),
			],
			false
		);
	}

	/* ── API base (§03) ───────────────────────────────────────────────── */

	/**
	 * Deliberately not editable in the UI: an arbitrary endpoint would receive
	 * the bearer token. Override only via the constant, for development.
	 */
	public function api_base(): string {
		$base = defined( 'AIVIS_API_BASE_URL' ) && is_string( AIVIS_API_BASE_URL ) && '' !== AIVIS_API_BASE_URL
			? AIVIS_API_BASE_URL
			: self::DEFAULT_API_BASE;
		return rtrim( $base, '/' );
	}

	/* ── business binding (§06, §07) ─────────────────────────────────── */

	/** @return array{business_id:string, business_name:string, base_url:string, allowed_hosts:list<string>} */
	public function business(): array {
		$d = (array) get_option( 'aivis_os_business', [] );
		return [
			'business_id'   => (string) ( $d['business_id'] ?? '' ),
			'business_name' => (string) ( $d['business_name'] ?? '' ),
			'base_url'      => (string) ( $d['base_url'] ?? '' ),
			'allowed_hosts' => array_values( array_filter( array_map( 'strval', (array) ( $d['allowed_hosts'] ?? [] ) ) ) ),
		];
	}

	public function set_business( string $id, string $name, string $base_url, array $allowed_hosts ): void {
		update_option(
			'aivis_os_business',
			[
				'business_id'   => $id,
				'business_name' => $name,
				'base_url'      => $base_url,
				'allowed_hosts' => array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $allowed_hosts ) ) ) ),
			],
			false
		);
	}

	public function clear_business(): void {
		delete_option( 'aivis_os_business' );
	}

	/** Hosts this site answers to: home_url, site_url, plus configured aliases. */
	public function allowed_hosts(): array {
		$biz   = $this->business();
		$hosts = $biz['allowed_hosts'];
		// This site's own hosts, and the bound business's host as AIVIS knows it —
		// with the www/non-www twin of each, the one variant that arises from
		// ordinary WordPress configuration rather than from a different page.
		foreach ( [ home_url(), site_url(), $biz['base_url'] ] as $u ) {
			$h = is_string( $u ) && '' !== $u ? wp_parse_url( $u, PHP_URL_HOST ) : null;
			if ( is_string( $h ) && '' !== $h ) {
				$h       = strtolower( $h );
				$hosts[] = $h;
				$hosts[] = str_starts_with( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
			}
		}
		/**
		 * Filter the hosts an artifact's URL may name.
		 *
		 * @param list<string> $hosts Lower-cased host names.
		 */
		$hosts = (array) apply_filters( 'aivis_connector_allowed_hosts', array_values( array_unique( $hosts ) ) );
		return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) );
	}

	public function site_host(): string {
		$h = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}

	/* ── delivery (§09) ──────────────────────────────────────────────── */

	public function injection_enabled(): bool {
		$d = (array) get_option( 'aivis_os_delivery', [] );
		return (bool) ( $d['enabled'] ?? true );
	}

	/** §05 on-demand miss lookups. Off by default: on a large site every unknown URL would write a transient. */
	public function on_demand_enabled(): bool {
		$d = (array) get_option( 'aivis_os_delivery', [] );
		return (bool) ( $d['on_demand'] ?? false );
	}

	public function set_on_demand_enabled( bool $on ): void {
		$d              = (array) get_option( 'aivis_os_delivery', [] );
		$d['on_demand'] = $on;
		update_option( 'aivis_os_delivery', $d, false );
	}

	public function set_injection_enabled( bool $on ): void {
		$d            = (array) get_option( 'aivis_os_delivery', [] );
		$d['enabled'] = $on;
		update_option( 'aivis_os_delivery', $d, false );
	}

	/* ── sync (§05 Cron, §06) ────────────────────────────────────────── */

	/** Seconds between syncs; 0 = manual only. */
	public function sync_interval(): int {
		$d       = (array) get_option( 'aivis_os_sync_state', [] );
		$seconds = (int) ( $d['interval'] ?? 900 );
		/**
		 * Filter the sync interval in seconds.
		 *
		 * @param int $seconds Interval; 0 disables scheduled syncs.
		 */
		return max( 0, (int) apply_filters( 'aivis_connector_sync_interval', $seconds ) );
	}

	public function set_sync_interval( int $seconds ): void {
		$this->patch_sync_state( [ 'interval' => max( 0, $seconds ) ] );
	}

	/** @return array<string,mixed> */
	public function sync_state(): array {
		return (array) get_option( 'aivis_os_sync_state', [] );
	}

	/** @param array<string,mixed> $patch */
	public function patch_sync_state( array $patch ): void {
		$d = array_merge( $this->sync_state(), $patch );
		update_option( 'aivis_os_sync_state', $d, false );
	}

	/* ── cache (§10) ─────────────────────────────────────────────────── */

	public function cache_adapter(): string {
		$d = (array) get_option( 'aivis_os_delivery', [] );
		return (string) ( $d['cache_adapter'] ?? 'auto' );
	}

	public function set_cache_adapter( string $slug ): void {
		$d                  = (array) get_option( 'aivis_os_delivery', [] );
		$d['cache_adapter'] = $slug;
		update_option( 'aivis_os_delivery', $d, false );
	}

	/* ── diagnostics ring buffer (§05) ───────────────────────────────── */

	/** @return list<array{t:int, code:string, message:string, url:string}> */
	public function diagnostics(): array {
		return array_values( (array) get_option( 'aivis_os_diagnostics', [] ) );
	}

	public function record( string $code, string $message, string $url = '' ): void {
		$d   = $this->diagnostics();
		$d[] = [
			't'       => time(),
			'code'    => $code,
			'message' => $this->redact( $message ),
			'url'     => $url,
		];
		if ( count( $d ) > self::DIAGNOSTICS_CAP ) {
			$d = array_slice( $d, -self::DIAGNOSTICS_CAP );
		}
		update_option( 'aivis_os_diagnostics', $d, false );
	}

	public function clear_diagnostics(): void {
		delete_option( 'aivis_os_diagnostics' );
	}

	/** Bearer-shaped strings never reach the diagnostics (WP-I6). */
	public function redact( string $s ): string {
		return (string) preg_replace( '/aivis_[A-Za-z0-9_\-]{8,}/', 'aivis_[redacted]', $s );
	}

	/* ── uninstall ───────────────────────────────────────────────────── */

	public function keep_data_on_uninstall(): bool {
		return (bool) get_option( 'aivis_os_uninstall_retention', false );
	}

	public function set_keep_data_on_uninstall( bool $keep ): void {
		update_option( 'aivis_os_uninstall_retention', $keep, false );
	}
}
