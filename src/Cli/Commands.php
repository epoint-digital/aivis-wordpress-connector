<?php
/**
 * WP-CLI: wp aivis <command> (§11).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cli;

use AivisOS\Plugin;

final class Commands {

	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Test the API token and report what it reaches.
	 *
	 * ## EXAMPLES
	 *     wp aivis connection test
	 *
	 * @subcommand connection
	 */
	public function connection( array $args ): void {
		$sub = $args[0] ?? 'test';
		if ( 'test' !== $sub ) {
			\WP_CLI::error( "Unknown subcommand: {$sub}" );
		}
		$o = $this->plugin->options();
		if ( 'none' === $o->token_source() ) {
			\WP_CLI::error( 'No token. Define AIVIS_API_TOKEN in wp-config.php or set one under AIVIS OS → Settings.' );
		}
		$r = $this->plugin->client()->me();
		if ( ! $r->ok() ) {
			$o->set_token_status( false );
			\WP_CLI::error( sprintf( 'HTTP %d — %s', $r->status, $r->message() ?: $r->transport_error ) );
		}
		$o->set_token_status( true, (string) ( $r->body['tokenName'] ?? '' ), (string) ( $r->body['email'] ?? '' ) );
		\WP_CLI::success( sprintf( 'Connected as %s (token "%s", from %s). Account-scoped: this token reads every business on the account.', $r->body['email'] ?? '?', $r->body['tokenName'] ?? '?', $o->token_source() ) );
	}

	/**
	 * Run a sync tick. --all keeps ticking until nothing is pending.
	 *
	 * ## OPTIONS
	 * [--all]
	 * : Repeat until the run is complete and no artifacts are pending.
	 *
	 * ## EXAMPLES
	 *     wp aivis sync
	 *     wp aivis sync --all
	 */
	public function sync( array $args, array $assoc ): void {
		$all   = isset( $assoc['all'] );
		$guard = 0;
		do {
			$s = $this->plugin->synchronizer()->run();
			if ( ! ( $s['ok'] ?? false ) ) {
				\WP_CLI::warning( 'Skipped: ' . ( $s['skipped'] ?? $s['error'] ?? 'unknown' ) );
				return;
			}
			\WP_CLI::log( sprintf(
				'sync %s — phase %s, authoritative=%s, fetched=%d, retired=%d, pending=%d',
				(string) ( $s['sync_id'] ?? '' ),
				(string) ( $s['phase'] ?? 'done' ),
				isset( $s['authoritative'] ) ? ( $s['authoritative'] ? 'yes' : 'no' ) : '-',
				(int) ( $s['fetched'] ?? 0 ),
				(int) ( $s['retired'] ?? 0 ),
				(int) ( $s['pending'] ?? 0 )
			) );
			$more = $all && ( ! empty( $s['partial'] ) || (int) ( $s['pending'] ?? 0 ) > 0 );
		} while ( $more && ++$guard < 200 );
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Show connection, counts and recent problems.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : table|json. Default table.
	 */
	public function status( array $args, array $assoc ): void {
		$o      = $this->plugin->options();
		$biz    = $o->business();
		$state  = $o->sync_state();
		$counts = $this->plugin->repository()->counts();
		$data   = [
			'version'            => AIVIS_OS_VERSION,
			'token_source'       => $o->token_source(),
			'business'           => $biz['business_name'] ?: '-',
			'business_id'        => $biz['business_id'] ?: '-',
			'site_host'          => $o->site_host(),
			'interval_seconds'   => $o->sync_interval(),
			'last_complete_sync' => ! empty( $state['last_complete_at'] ) ? gmdate( 'c', (int) $state['last_complete_at'] ) : '-',
			'last_authoritative' => isset( $state['last_authoritative'] ) ? ( $state['last_authoritative'] ? 'yes' : 'no' ) : '-',
			'cache_adapter'      => $this->plugin->cache()->adapter()->id(),
			'last_purge'         => $state['last_purge']['state'] ?? '-',
		] + $counts;
		if ( ( $assoc['format'] ?? 'table' ) === 'json' ) {
			\WP_CLI::line( (string) wp_json_encode( $data + [ 'recent' => array_slice( $o->diagnostics(), -10 ) ], JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = [];
		foreach ( $data as $k => $v ) {
			$rows[] = [ 'key' => $k, 'value' => (string) $v ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
		foreach ( array_slice( $o->diagnostics(), -5 ) as $d ) {
			\WP_CLI::log( sprintf( '%s  %s  %s %s', gmdate( 'H:i', (int) $d['t'] ), $d['code'], $d['message'], $d['url'] ) );
		}
	}

	/**
	 * Fetch a page over loopback and confirm the block is live.
	 *
	 * ## OPTIONS
	 * [--url=<url>]
	 * : A specific page. Default: the first active artifact.
	 */
	public function verify( array $args, array $assoc ): void {
		$r = $this->plugin->verifier()->run( isset( $assoc['url'] ) ? (string) $assoc['url'] : null );
		$line = sprintf( '%s — %s (%s)', $r['result'], $r['detail'], $r['url'] ?? '-' );
		if ( 'live' === $r['result'] ) {
			\WP_CLI::success( $line );
		} elseif ( 'could-not-verify' === $r['result'] || 'nothing-to-verify' === $r['result'] ) {
			\WP_CLI::warning( $line );
		} else {
			\WP_CLI::error( $line );
		}
	}

	/**
	 * Re-check one URL against AIVIS right now.
	 *
	 * ## OPTIONS
	 * <url>
	 * : The absolute page URL.
	 */
	public function refresh( array $args ): void {
		$r = $this->plugin->synchronizer()->refresh_url( (string) $args[0] );
		\WP_CLI::log( (string) wp_json_encode( $r ) );
		( $r['ok'] ?? false ) ? \WP_CLI::success( 'Refreshed.' ) : \WP_CLI::error( 'Refresh failed.' );
	}
}
