<?php
/**
 * Status screen (§11; docs/admin-ui.html).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Cache\PurgeResult;
use AivisOS\Delivery\Language;
use AivisOS\Plugin;
use AivisOS\Sync\Scheduler;

final class StatusPage {

	public function __construct( private readonly Plugin $plugin ) {}

	public function render(): void {
		$o        = $this->plugin->options();
		$biz      = $o->business();
		$state    = $o->sync_state();
		$counts   = $this->plugin->repository()->counts();
		$interval = $o->sync_interval();
		$next     = Scheduler::next_sync();
		$purge    = (array) ( $state['last_purge'] ?? [] );
		$adapter  = $this->plugin->cache()->adapter();
		$chains   = (array) ( $state['chain_summaries'] ?? [] );
		$diag     = array_reverse( array_slice( $o->diagnostics(), -10 ) );
		$last     = (int) ( $state['last_complete_at'] ?? 0 );
		$auth     = $state['last_authoritative'] ?? null;
		$verify   = (array) ( $state['verify'] ?? [] );
		$conf     = $o->conflicts();
		$conflict_by_url = (array) $conf['items'];
		$map      = $o->chain_languages();
		$langs    = Language::site_languages();
		$summary  = $this->plugin->assignment()->summary( null );
		$by_chain = $this->plugin->repository()->counts_by_chain();
		$mismatch = (array) ( $state['language_mismatch'] ?? [] );
		$unassigned_chains = array_values( array_filter( array_keys( $chains ), static fn( string $id ): bool => ! isset( $map[ $id ] ) ) );
		?>
		<div class="wrap aivis-os">
			<h1>AIVIS OS</h1>
			<p class="description"><?php esc_html_e( 'Delivers the structured data AIVIS generates for this site into its pages.', 'aivis-os' ); ?></p>
			<?php echo Menu::tabs( Menu::SLUG_STATUS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="aivis-cols">
				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Connection', 'aivis-os' ); ?>
					<?php if ( $last && false !== $auth ) : ?><span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'Syncing', 'aivis-os' ); ?></span>
					<?php elseif ( false === $auth ) : ?><span class="aivis-chip aivis-chip--warn"><?php esc_html_e( 'Last sync incomplete', 'aivis-os' ); ?></span><?php endif; ?></h2>
					<div class="inside"><dl class="aivis-kv">
						<dt><?php esc_html_e( 'Business', 'aivis-os' ); ?></dt><dd><?php echo '' !== $biz['business_name'] ? esc_html( $biz['business_name'] ) : '<span class="description">' . esc_html__( 'not bound', 'aivis-os' ) . '</span>'; ?></dd>
						<dt><?php esc_html_e( 'Domain', 'aivis-os' ); ?></dt><dd><code><?php echo esc_html( $o->site_host() ); ?></code> <?php esc_html_e( 'matched to', 'aivis-os' ); ?> <code><?php echo esc_html( (string) wp_parse_url( $biz['base_url'], PHP_URL_HOST ) ?: '—' ); ?></code></dd>
						<dt><?php esc_html_e( 'Languages', 'aivis-os' ); ?></dt><dd><?php
							$parts = [];
							foreach ( $summary['languages'] as $l ) {
								$parts[] = $l['chains']
									? esc_html( $l['name'] ) . ' → ' . esc_html( implode( ', ', array_map( static fn( string $id ) => (string) ( $chains[ $id ]['name'] ?? $id ), $l['chains'] ) ) )
									: '<span class="aivis-chip aivis-chip--bad">' . esc_html( sprintf( /* translators: %s: language */ __( '%s — no chain', 'aivis-os' ), $l['name'] ) ) . '</span>';
							}
							echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo ' <span class="description">(' . esc_html( Language::provider_label( Language::provider() ) ) . ')</span>';
						?></dd>
						<dt><?php esc_html_e( 'Token', 'aivis-os' ); ?></dt><dd><?php echo 'constant' === $o->token_source() ? esc_html__( 'From wp-config.php', 'aivis-os' ) : ( 'option' === $o->token_source() ? esc_html__( 'From the database', 'aivis-os' ) : esc_html__( 'None', 'aivis-os' ) ); ?> · <code><?php echo esc_html( $o->token_display() ); ?></code></dd>
						<dt><?php esc_html_e( 'Last complete sync', 'aivis-os' ); ?></dt><dd><?php echo $last ? esc_html( human_time_diff( $last ) . ' ' . __( 'ago', 'aivis-os' ) ) : '—'; ?> <?php if ( false === $auth ) : ?><span class="description">(<?php esc_html_e( 'incomplete — nothing retired', 'aivis-os' ); ?>)</span><?php endif; ?></dd>
						<dt><?php esc_html_e( 'Next sync', 'aivis-os' ); ?></dt><dd><?php echo $next ? esc_html( sprintf( /* translators: %s: time */ __( 'in %s', 'aivis-os' ), human_time_diff( $next ) ) ) : esc_html__( 'manual only', 'aivis-os' ); ?></dd>
					</dl></div>
				</div>
				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Withdrawal latency', 'aivis-os' ); ?></h2><div class="inside">
					<p><?php esc_html_e( 'If a page is unpublished in AIVIS, it stops being served here within:', 'aivis-os' ); ?></p>
					<div class="aivis-big"><?php echo $interval > 0 ? esc_html( (string) (int) ( $interval / 60 ) ) . ' min' : esc_html__( 'next manual sync', 'aivis-os' ); ?> <span class="description">+ <?php esc_html_e( 'cache purge', 'aivis-os' ); ?></span></div>
					<p class="description"><?php esc_html_e( 'Worst case with an idle pipeline and no backlog: the sync interval plus the time your cache takes to drop the page. While a chain is rebuilding in AIVIS it can take two intervals. Shorten the interval under Settings if this site publishes offers or prices that must be withdrawable quickly.', 'aivis-os' ); ?></p>
					<?php $backlog = count( (array) ( $state['pending'] ?? [] ) ); if ( $backlog > 0 ) : ?>
						<p><span class="aivis-chip aivis-chip--warn"><?php echo esc_html( sprintf( /* translators: %d: artifacts */ _n( 'Backlog: %d artifact pending', 'Backlog: %d artifacts pending', $backlog, 'aivis-os' ), $backlog ) ); ?></span> <span class="description"><?php esc_html_e( 'draining 20 per minute — withdrawals wait until it is done', 'aivis-os' ); ?></span></p>
					<?php endif; ?>
				</div></div>
			</div>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Structured data on this site', 'aivis-os' ); ?></h2>
				<div class="aivis-tiles">
					<?php $tile = static fn( string $state, int $n, string $label, string $kind = '' ): string => '<a class="aivis-tile ' . esc_attr( $kind ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PAGES . '&state=' . $state ) ) . '"><div class="n">' . $n . '</div><div class="l">' . esc_html( $label ) . '</div></a>'; ?>
					<?php echo $tile( 'active', (int) $counts['active'], __( 'Injected', 'aivis-os' ), 'aivis-tile--ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tile( 'stale', (int) $counts['stale'], __( 'Stale but served', 'aivis-os' ), 'aivis-tile--warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tile( 'holding', (int) $counts['hold'], __( 'Awaiting AIVIS', 'aivis-os' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tile( 'suspended', (int) $counts['suspended'], __( 'Suspended', 'aivis-os' ), 'aivis-tile--bad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tile( 'retired', (int) $counts['retired'], __( 'Retired', 'aivis-os' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $tile( 'conflict', count( $conflict_by_url ), __( 'Other JSON-LD found', 'aivis-os' ), $conflict_by_url && $o->conflicts_unacknowledged() ? 'aivis-tile--bad' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="aivis-legend">
					<span><span class="aivis-chip aivis-chip--ok">Active</span> <?php esc_html_e( 'injected on the page', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--warn">Stale</span> <?php esc_html_e( 'source changed; still served', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--info">Holding</span> <?php esc_html_e( 'not generated yet; nothing removed', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--bad">Suspended</span> <?php esc_html_e( 'withdrawn upstream', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip">Retired</span> <?php esc_html_e( 'gone; kept 30 days', 'aivis-os' ); ?></span>
				</div>
			</div>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Languages', 'aivis-os' ); ?> <span class="description" style="font-weight:400"><?php esc_html_e( 'one chain per language', 'aivis-os' ); ?></span></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Language', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Chains', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Injected', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Holding', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Suspended', 'aivis-os' ); ?></th></tr></thead><tbody>
				<?php foreach ( $summary['languages'] as $l ) :
					$tot = [ 'active' => 0, 'hold' => 0, 'suspended' => 0 ];
					foreach ( $l['chains'] as $id ) {
						foreach ( $tot as $k => $v ) {
							$tot[ $k ] += (int) ( $by_chain[ $id ][ $k ] ?? 0 );
						}
					}
					?>
					<tr><td><strong><?php echo esc_html( $l['name'] ); ?></strong> <code><?php echo esc_html( $l['code'] ); ?></code><?php if ( $l['default'] ) : ?> <span class="description"><?php esc_html_e( 'default', 'aivis-os' ); ?></span><?php endif; ?></td>
					<td><?php echo $l['chains'] ? esc_html( implode( ', ', array_map( static fn( string $id ) => (string) ( $chains[ $id ]['name'] ?? $id ), $l['chains'] ) ) ) : '<span class="aivis-chip aivis-chip--bad">' . esc_html__( 'no chain — nothing is injected on these pages', 'aivis-os' ) . '</span>'; ?></td>
					<td><?php echo (int) $tot['active']; ?></td><td><?php echo (int) $tot['hold']; ?></td><td><?php echo (int) $tot['suspended']; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php if ( $unassigned_chains ) : ?><p class="description" style="padding:8px 12px"><?php echo esc_html( sprintf( /* translators: %s: chain names */ _n( 'Not synced — no language assigned: %s', 'Not synced — no language assigned: %s', count( $unassigned_chains ), 'aivis-os' ), implode( ', ', array_map( static fn( string $id ) => (string) ( $chains[ $id ]['name'] ?? $id ), $unassigned_chains ) ) ) ); ?> — <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS . '#aivis-languages' ) ); ?>"><?php esc_html_e( 'assign under Settings', 'aivis-os' ); ?></a></p><?php endif; ?>
			</div>

			<?php $moved = (array) ( $state['moved'] ?? [] ); if ( $moved ) : ?>
			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Moved pages', 'aivis-os' ); ?> <span class="aivis-chip aivis-chip--warn"><?php echo (int) count( $moved ); ?></span></h2>
				<div class="inside"><p class="description"><?php esc_html_e( 'These pages changed their address since AIVIS crawled them. The structured data stays with the URL AIVIS has, so the new address gets nothing until AIVIS re-crawls. Nothing is changed here — this is a fact for AIVIS, and it is in the status document.', 'aivis-os' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PAGES . '&state=moved' ) ); ?>"><?php esc_html_e( 'List them', 'aivis-os' ); ?></a></p></div>
			</div>
			<?php endif; ?>

			<?php if ( $chains ) : ?>
			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Pipelines', 'aivis-os' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Chain', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Language', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Pipeline state', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Knowledge graph', 'aivis-os' ); ?></th><th><?php esc_html_e( 'URLs', 'aivis-os' ); ?></th></tr></thead><tbody>
				<?php foreach ( $chains as $id => $c ) : ?>
					<tr><td><strong><?php echo esc_html( (string) $c['name'] ); ?></strong></td>
					<td><?php
						if ( isset( $map[ $id ] ) ) {
							echo esc_html( ( $langs[ $map[ $id ] ]['name'] ?? $map[ $id ] ) ) . ' <code>' . esc_html( $map[ $id ] ) . '</code>';
							if ( isset( $mismatch[ $id ] ) ) {
								echo ' <span class="aivis-chip aivis-chip--bad">' . esc_html( sprintf( /* translators: 1: count, 2: code */ __( 'AIVIS reports %1$d pages as %2$s', 'aivis-os' ), (int) $mismatch[ $id ]['count'], (string) $mismatch[ $id ]['aivis'] ) ) . '</span>';
							}
						} else {
							echo '<span class="aivis-chip aivis-chip--warn">' . esc_html__( 'not assigned — not synced', 'aivis-os' ) . '</span>';
						}
					?></td>
					<td><?php echo 'ready' === $c['state'] ? '<span class="aivis-chip aivis-chip--ok">' . esc_html__( 'Ready', 'aivis-os' ) . '</span>' : '<span class="aivis-chip aivis-chip--info">' . esc_html( ucfirst( str_replace( '_', ' ', (string) $c['state'] ) ) ) . '</span>'; ?> <span class="description"><?php echo esc_html( sprintf( /* translators: %d: step */ __( 'step %d of 9', 'aivis-os' ), (int) $c['step'] ) ); ?></span></td>
					<td><?php echo $c['kg'] ? esc_html__( 'Built', 'aivis-os' ) : '<span class="description">' . esc_html__( 'Not built', 'aivis-os' ) . '</span>'; ?></td>
					<td><?php echo (int) $c['urlCount']; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<?php endif; ?>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Needs attention', 'aivis-os' ); ?>
				<span style="float:right;font-weight:400">
					<?php echo $this->purge_chip( (string) ( $purge['state'] ?? '' ), $adapter->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo Menu::action_form( 'sync_run', __( 'Sync now', 'aivis-os' ), [], 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span></h2>
				<div class="inside">
				<?php
				$pages_url = static fn( string $state ): string => admin_url( 'admin.php?page=' . Menu::SLUG_PAGES . '&state=' . $state );
				$items     = [
					[ (int) $counts['suspended'], __( 'pages suspended — withdrawn in AIVIS, awaiting confirmation', 'aivis-os' ), $pages_url( 'suspended' ), 'bad' ],
					[ (int) $counts['hold'], __( 'pages holding last good — AIVIS has not generated them yet, or was unreachable', 'aivis-os' ), $pages_url( 'holding' ), 'info' ],
					[ count( (array) ( $state['moved'] ?? [] ) ), __( 'pages moved since AIVIS crawled them — AIVIS needs to re-crawl', 'aivis-os' ), $pages_url( 'moved' ), 'warn' ],
					[ $o->conflicts_unacknowledged() ? count( $conflict_by_url ) : 0, __( 'pages carry other JSON-LD — switch it off in that plugin', 'aivis-os' ), $pages_url( 'conflict' ), 'bad' ],
					[ count( $summary['missing'] ), __( 'languages without a chain — assign under Settings', 'aivis-os' ), admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS . '#aivis-languages' ), 'bad' ],
					[ count( $unassigned_chains ), __( 'chains not assigned to a language — not synced', 'aivis-os' ), admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS . '#aivis-languages' ), 'warn' ],
				];
				$items = array_values( array_filter( $items, static fn( array $i ): bool => $i[0] > 0 ) );
				if ( ! $items ) :
				?>
					<p><span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'Nothing needs you', 'aivis-os' ); ?></span> <span class="description"><?php echo esc_html( sprintf( /* translators: %d: pages */ __( '%d pages are delivered as AIVIS generated them.', 'aivis-os' ), (int) $counts['active'] + (int) $counts['stale'] ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PAGES ) ); ?>"><?php esc_html_e( 'Find a page', 'aivis-os' ); ?></a></span></p>
				<?php else : ?>
					<ul style="margin:0">
					<?php foreach ( $items as [ $n, $text, $href, $kind ] ) : ?>
						<li><span class="aivis-chip aivis-chip--<?php echo esc_attr( $kind ); ?>"><?php echo (int) $n; ?></span> <?php echo esc_html( $text ); ?> — <a href="<?php echo esc_url( $href ); ?>"><?php esc_html_e( 'show', 'aivis-os' ); ?></a></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="description" style="margin-top:8px"><?php echo wp_kses_post( sprintf( /* translators: %s: link */ __( 'The full list — paginated, searchable, filterable by state, language and chain — is under %s. You do not have to read it.', 'aivis-os' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_PAGES ) ) . '">' . esc_html__( 'Pages', 'aivis-os' ) . '</a>' ) ); ?></p>
				</div>
			</div>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Recent problems', 'aivis-os' ); ?>
				<?php if ( $diag ) : ?><span style="float:right;font-weight:400"><?php echo Menu::action_form( 'clear_diagnostics', __( 'Clear', 'aivis-os' ), [], 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php endif; ?></h2>
				<div class="inside">
				<?php if ( ! $diag ) : ?><p class="description"><?php esc_html_e( 'Nothing recorded.', 'aivis-os' ); ?></p>
				<?php else : ?><dl class="aivis-kv"><?php foreach ( $diag as $d ) : ?>
					<dt><code><?php echo esc_html( (string) $d['code'] ); ?></code></dt><dd><?php echo esc_html( human_time_diff( (int) $d['t'] ) . ' ago — ' . (string) $d['message'] . ( $d['url'] ? ' (' . $d['url'] . ')' : '' ) ); ?></dd>
				<?php endforeach; ?></dl><?php endif; ?>
				<?php if ( $verify ) : ?><p class="description"><?php echo esc_html( sprintf( /* translators: 1: result, 2: time */ __( 'Last live verification: %1$s, %2$s ago.', 'aivis-os' ), (string) ( $verify['result'] ?? '' ), human_time_diff( (int) ( $verify['at'] ?? time() ) ) ) ); ?></p><?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $r */
	private function state_of( array $r ): string {
		if ( ! empty( $r['retired_at'] ) || (int) $r['active'] === 0 ) {
			return 'retired';
		}
		if ( ! empty( $r['suspended_at'] ) ) {
			return 'suspended';
		}
		if ( null !== $r['last_error_code'] ) {
			return 'hold';
		}
		if ( (int) $r['source_stale'] === 1 ) {
			return 'stale';
		}
		return 'active';
	}

	public static function chip_for( string $st ): string {
		return match ( $st ) {
			'active'    => '<span class="aivis-chip aivis-chip--ok">' . esc_html__( 'Active', 'aivis-os' ) . '</span>',
			'stale'     => '<span class="aivis-chip aivis-chip--warn">' . esc_html__( 'Stale', 'aivis-os' ) . '</span>',
			'hold'      => '<span class="aivis-chip aivis-chip--info">' . esc_html__( 'Holding last good', 'aivis-os' ) . '</span>',
			'suspended' => '<span class="aivis-chip aivis-chip--bad">' . esc_html__( 'Suspended', 'aivis-os' ) . '</span>',
			default     => '<span class="aivis-chip">' . esc_html__( 'Retired', 'aivis-os' ) . '</span>',
		};
	}

	private function purge_chip( string $state, string $adapter ): string {
		if ( 'manual' === $adapter ) {
			return '<span class="aivis-chip">' . esc_html__( 'Manual purge required', 'aivis-os' ) . '</span>';
		}
		return match ( $state ) {
			PurgeResult::CONFIRMED => '<span class="aivis-chip aivis-chip--ok">' . esc_html__( 'Purge confirmed', 'aivis-os' ) . '</span>',
			PurgeResult::REQUESTED => '<span class="aivis-chip aivis-chip--warn">' . esc_html__( 'Purge requested, not confirmed', 'aivis-os' ) . '</span>',
			PurgeResult::FAILED    => '<span class="aivis-chip aivis-chip--bad">' . esc_html__( 'Purge failed', 'aivis-os' ) . '</span>',
			default                => '<span class="aivis-chip">' . esc_html__( 'No purge yet', 'aivis-os' ) . '</span>',
		};
	}

	public static function explain_code( string $code, string $st ): string {
		return match ( $code ) {
			'AIVIS_NOT_GENERATED' => __( 'Not generated yet in AIVIS — nothing injected, nothing removed', 'aivis-os' ),
			'AIVIS_RETRACTED'     => 'suspended' === $st ? __( 'AIVIS returned “URL not found in your businesses” — injection stopped, cache purged, awaiting inventory confirmation', 'aivis-os' ) : __( 'Withdrawn upstream', 'aivis-os' ),
			'AIVIS_HTTP_TIMEOUT', 'AIVIS_HTTP_ERROR' => __( 'Serving last known good — AIVIS unreachable at last check', 'aivis-os' ),
			'AIVIS_AUTH_401'      => __( 'Serving last known good — token rejected', 'aivis-os' ),
			'AIVIS_SCHEMA_INVALID' => __( 'Last response failed validation — previous artifact kept', 'aivis-os' ),
			'AIVIS_ADMIN_DISABLED' => __( 'Disabled on this site by an administrator', 'aivis-os' ),
			'AIVIS_LANGUAGE_UNASSIGNED' => __( 'Its chain is not assigned to a language — not synced, not injected', 'aivis-os' ),
			default               => $code,
		};
	}
}
