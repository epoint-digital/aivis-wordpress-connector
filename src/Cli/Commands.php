<?php
/**
 * WP-CLI: wp aivis <command> (§11).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Cli;

use AivisOS\Plugin;
use AivisOS\Storage\Options;

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
	 * Bind this site to the AIVIS business whose domain matches it, then
	 * assign chains to languages where that is unambiguous. Headless
	 * counterpart of Settings → Business (§06, §07a).
	 *
	 * ## OPTIONS
	 * [--business=<id>]
	 * : Required only when more than one business on the account uses this domain.
	 *
	 * [--hosts=<list>]
	 * : Comma-separated additional allowed hosts (aliases of this site).
	 *
	 * ## EXAMPLES
	 *     wp aivis bind
	 *     wp aivis bind --business=biz_live
	 */
	public function bind( array $args, array $assoc ): void {
		$o = $this->plugin->options();
		if ( 'none' === $o->token_source() ) {
			\WP_CLI::error( 'No token. Define AIVIS_API_TOKEN in wp-config.php first.' );
		}
		$me = $this->plugin->client()->me();
		if ( ! $me->ok() ) {
			$o->set_token_status( false );
			\WP_CLI::error( sprintf( 'Token rejected: HTTP %d — %s', $me->status, $me->message() ?: $me->transport_error ) );
		}
		$o->set_token_status( true, (string) ( $me->body['tokenName'] ?? '' ), (string) ( $me->body['email'] ?? '' ) );

		$host   = $o->site_host();
		$all    = [];
		$cursor = null;
		do {
			$r = $this->plugin->client()->businesses( $cursor );
			if ( ! $r->ok() ) {
				\WP_CLI::error( sprintf( 'Could not list businesses: HTTP %d — %s', $r->status, $r->message() ?: $r->transport_error ) );
			}
			foreach ( (array) ( $r->body['items'] ?? [] ) as $b ) {
				$all[] = $b;
			}
			$cursor = ( ! empty( $r->body['hasMore'] ) && is_string( $r->body['nextCursor'] ?? null ) ) ? $r->body['nextCursor'] : null;
		} while ( null !== $cursor );

		$matching = array_values( array_filter( $all, static fn( array $b ): bool => Options::same_host( (string) wp_parse_url( (string) ( $b['baseUrl'] ?? '' ), PHP_URL_HOST ), $host ) ) );
		if ( ! $matching ) {
			\WP_CLI::error( sprintf( 'No business on this account uses %s (%d businesses on the account). A business has exactly one domain in AIVIS: create one for this site, or correct its base URL there. Businesses on other domains are never bound.', $host, count( $all ) ) );
		}
		$want = isset( $assoc['business'] ) ? (string) $assoc['business'] : '';
		if ( '' === $want && count( $matching ) > 1 ) {
			foreach ( $matching as $b ) {
				\WP_CLI::log( sprintf( '  %s  %s  (created %s, %d chains)', $b['id'], $b['name'], substr( (string) ( $b['createdAt'] ?? '' ), 0, 10 ), (int) ( $b['chainCount'] ?? 0 ) ) );
			}
			\WP_CLI::error( sprintf( '%d businesses use %s. Pass --business=<id> for the live one; the plugin will not guess.', count( $matching ), $host ) );
		}
		$chosen = null;
		foreach ( $matching as $b ) {
			if ( '' === $want || (string) $b['id'] === $want ) {
				$chosen = $b;
				break;
			}
		}
		if ( null === $chosen ) {
			\WP_CLI::error( sprintf( 'Business %s does not use %s — refusing to bind a business on another domain.', $want, $host ) );
		}
		$hosts = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['hosts'] ?? '' ) ) ) ) );
		$o->set_business( (string) $chosen['id'], (string) $chosen['name'], (string) $chosen['baseUrl'], $hosts );
		\WP_CLI::log( sprintf( 'Bound to %s (%s) — domain %s.', $chosen['name'], $chosen['id'], $host ) );

		$auto = $this->plugin->assignment()->auto_assign();
		if ( ! $auto['catalog_ok'] ) {
			\WP_CLI::warning( 'Could not list chains right now; run `wp aivis languages auto` later.' );
		} else {
			foreach ( $auto['map'] as $chain => $lang ) {
				\WP_CLI::log( sprintf( '  chain %s → %s', $chain, $lang ) );
			}
			foreach ( $auto['unresolved'] as $chain ) {
				\WP_CLI::warning( sprintf( 'chain %s is not assigned to a language — decide with `wp aivis languages assign %s <lang>`.', $chain, $chain ) );
			}
		}
		\AivisOS\Sync\Scheduler::request_sync_now();
		\WP_CLI::success( 'Bound. Sync requested — run `wp aivis sync --all` to do it now.' );
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
			'moved_pages'        => count( (array) ( $state['moved'] ?? [] ) ),
			'language_provider'  => \AivisOS\Delivery\Language::provider(),
			'languages'          => implode( ', ', array_map(
				static fn( array $l ): string => $l['code'] . ':' . count( $l['chains'] ),
				$this->plugin->assignment()->summary( null )['languages']
			) ),
		] + $counts;
		if ( ( $assoc['format'] ?? 'table' ) === 'json' ) {
			// The same document AIVIS fetches from the status endpoint (§11a).
			$doc = new \AivisOS\Rest\StatusDocument( $o, $this->plugin->repository(), $this->plugin->cache() );
			\WP_CLI::line( (string) wp_json_encode( $doc->build() + [ 'recent' => array_slice( $o->diagnostics(), -10 ) ], JSON_PRETTY_PRINT ) );
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
	 * Languages on this site and the chains that serve them (one chain per language).
	 *
	 * ## OPTIONS
	 * [<action>]
	 * : list (default) | assign | auto
	 *
	 * [<chain>]
	 * : For assign: the chain id.
	 *
	 * [<language>]
	 * : For assign: a language code this site has, or "none" to unassign.
	 *
	 * ## EXAMPLES
	 *     wp aivis languages
	 *     wp aivis languages assign chain_core de
	 *     wp aivis languages assign chain_edit none
	 *     wp aivis languages auto
	 */
	public function languages( array $args, array $assoc ): void {
		$o     = $this->plugin->options();
		$a     = $this->plugin->assignment();
		$langs = \AivisOS\Delivery\Language::site_languages();
		$act   = (string) ( $args[0] ?? 'list' );

		if ( 'assign' === $act ) {
			$chain = (string) ( $args[1] ?? '' );
			$code  = \AivisOS\Delivery\Language::normalize( (string) ( $args[2] ?? '' ) );
			if ( '' === $chain || '' === $code ) {
				\WP_CLI::error( 'Usage: wp aivis languages assign <chain> <language|none>' );
			}
			if ( 'none' !== $code && ! isset( $langs[ $code ] ) ) {
				\WP_CLI::error( sprintf( 'Unknown language "%s". This site has: %s', $code, implode( ', ', array_keys( $langs ) ) ) );
			}
			$map = $o->chain_languages();
			if ( 'none' === $code ) {
				unset( $map[ $chain ] );
			} else {
				$map[ $chain ] = $code;
			}
			( new \AivisOS\Admin\SettingsPage( $this->plugin ) )->save_chain_languages( $map );
			\WP_CLI::success( 'none' === $code ? "Chain {$chain} unassigned; its pages stop being served now." : "Chain {$chain} serves {$code}. Sync requested." );
			return;
		}
		if ( 'auto' === $act ) {
			$r = $a->auto_assign();
			if ( ! $r['catalog_ok'] ) {
				\WP_CLI::error( 'AIVIS could not be reached.' );
			}
			\WP_CLI::log( sprintf( 'assigned: %s; left to you: %s', $r['assigned'] ? implode( ', ', $r['assigned'] ) : '-', $r['unresolved'] ? implode( ', ', $r['unresolved'] ) : '-' ) );
		}

		\WP_CLI::log( sprintf( 'Languages (%s): %s', \AivisOS\Delivery\Language::provider_label( \AivisOS\Delivery\Language::provider() ), implode( ', ', array_map( static fn( array $l ): string => $l['name'] . ' [' . $l['code'] . ']' . ( $l['default'] ? '*' : '' ), $langs ) ) ) );
		$catalog = $a->catalog();
		$map     = $o->chain_languages();
		$counts  = $this->plugin->repository()->counts_by_chain();
		$rows    = [];
		foreach ( $catalog ?? array_map( static fn( string $id ): array => [ 'id' => $id, 'name' => $id, 'state' => '?', 'urlCount' => 0 ], array_keys( $map ) ) as $c ) {
			$h      = null === $catalog ? [ 'language' => null, 'mixed' => false ] : $a->hint( $c['id'] );
			$rows[] = [
				'chain'    => $c['id'],
				'name'     => $c['name'],
				'state'    => $c['state'],
				'serves'   => $map[ $c['id'] ] ?? '(not assigned — not synced)',
				'aivis'    => $h['mixed'] ? 'mixed' : ( $h['language'] ?? '-' ),
				'injected' => (string) ( $counts[ $c['id'] ]['active'] ?? 0 ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'chain', 'name', 'state', 'serves', 'aivis', 'injected' ] );
		foreach ( $a->summary( $catalog )['missing'] as $code ) {
			\WP_CLI::warning( sprintf( '%s has no chain — nothing is injected on its pages.', $langs[ $code ]['name'] ) );
		}
	}

	/**
	 * List pages — the same views, filters and search as the Pages screen.
	 *
	 * ## OPTIONS
	 * [--state=<state>]
	 * : attention | active | stale | holding | suspended | retired | inactive | moved | conflict
	 *
	 * [--language=<code>]
	 * [--chain=<id>]
	 * [--search=<text>]
	 * : Substring of the URL.
	 *
	 * [--page=<n>]
	 * [--per-page=<n>]
	 * : Default 50, max 200.
	 *
	 * [--format=<format>]
	 * : table|json|csv. Default table.
	 *
	 * ## EXAMPLES
	 *     wp aivis pages --state=attention
	 *     wp aivis pages --search=/services/ --format=json
	 */
	public function pages( array $args, array $assoc ): void {
		$o = $this->plugin->options();
		$f = \AivisOS\Admin\PagesQuery::from_request(
			[ 'state' => $assoc['state'] ?? '', 'language' => $assoc['language'] ?? '', 'chain' => $assoc['chain'] ?? '', 's' => $assoc['search'] ?? '', 'paged' => $assoc['page'] ?? 1 ],
			(int) ( $assoc['per-page'] ?? 0 )
		);
		$moved = (array) ( $o->sync_state()['moved'] ?? [] );
		$conf  = (array) $o->conflicts()['items'];
		$w     = \AivisOS\Admin\PagesQuery::where( $f, array_keys( $moved ), array_keys( $conf ) );
		$r     = $this->plugin->repository()->search( $w['sql'], $w['args'], $f['per_page'], $f['page'], $f['orderby'], $f['order'] );
		$rows  = [];
		foreach ( $r['rows'] as $row ) {
			$rows[] = [
				'url'       => (string) $row['source_url'],
				'state'     => \AivisOS\Rest\StatusDocument::state_of( $row ),
				'language'  => (string) $row['language_code'],
				'chain'     => (string) $row['chain_id'],
				'generated' => (string) ( $row['source_generated_at'] ?? '' ),
				'published' => (string) ( $row['published_at'] ?? '' ),
				'moved_to'  => (string) ( $moved[ (string) $row['url_key'] ]['to'] ?? '' ),
				'error'     => (string) ( $row['last_error_code'] ?? '' ),
			];
		}
		\WP_CLI::log( sprintf( '%d of %d pages (page %d, %d per page)%s', count( $rows ), $r['total'], $f['page'], $f['per_page'], '' !== $f['state'] ? ' — ' . $f['state'] : '' ) );
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'url', 'state', 'language', 'chain', 'generated', 'published', 'moved_to', 'error' ] );
	}

	/**
	 * The read-only key AIVIS presents to fetch this site's publishing status.
	 *
	 * ## OPTIONS
	 * [<action>]
	 * : show (default) | regenerate | disable
	 *
	 * ## EXAMPLES
	 *     wp aivis status-key
	 *     wp aivis status-key regenerate
	 *
	 * @subcommand status-key
	 */
	public function status_key( array $args ): void {
		$o   = $this->plugin->options();
		$act = (string) ( $args[0] ?? 'show' );
		if ( 'regenerate' === $act ) {
			$o->regenerate_status_key();
			\WP_CLI::success( 'New status key issued; the old one stops working now. Update it in AIVIS.' );
		} elseif ( 'disable' === $act ) {
			$o->disable_status_key();
			\WP_CLI::success( 'Status endpoint disabled.' );
			return;
		}
		$key = $o->status_key();
		\WP_CLI::log( 'endpoint: ' . rest_url( \AivisOS\Rest\StatusController::NS . '/status' ) );
		\WP_CLI::log( 'key:      ' . ( '' !== $key ? $key : '(disabled — run: wp aivis status-key regenerate)' ) );
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
