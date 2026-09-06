<?php
/**
 * Status screen (§11; docs/admin-ui.html).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Cache\PurgeResult;
use AivisOS\Plugin;
use AivisOS\Sync\Scheduler;

final class StatusPage {

	public function __construct( private readonly Plugin $plugin ) {}

	public function render(): void {
		$o        = $this->plugin->options();
		$biz      = $o->business();
		$state    = $o->sync_state();
		$counts   = $this->plugin->repository()->counts();
		$rows     = $this->plugin->repository()->all_for_admin();
		$interval = $o->sync_interval();
		$next     = Scheduler::next_sync();
		$purge    = (array) ( $state['last_purge'] ?? [] );
		$adapter  = $this->plugin->cache()->adapter();
		$chains   = (array) ( $state['chain_summaries'] ?? [] );
		$diag     = array_reverse( array_slice( $o->diagnostics(), -10 ) );
		$last     = (int) ( $state['last_complete_at'] ?? 0 );
		$auth     = $state['last_authoritative'] ?? null;
		$verify   = (array) ( $state['verify'] ?? [] );
		?>
		<div class="wrap aivis-os">
			<h1>AIVIS OS</h1>
			<p class="description"><?php esc_html_e( 'Delivers the structured data AIVIS generates for this site into its pages.', 'aivis-os' ); ?></p>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab nav-tab-active" href="#"><?php esc_html_e( 'Status', 'aivis-os' ); ?></a>
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_SETTINGS ) ); ?>"><?php esc_html_e( 'Settings', 'aivis-os' ); ?></a>
			</nav>

			<div class="aivis-cols">
				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Connection', 'aivis-os' ); ?>
					<?php if ( $last && false !== $auth ) : ?><span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'Syncing', 'aivis-os' ); ?></span>
					<?php elseif ( false === $auth ) : ?><span class="aivis-chip aivis-chip--warn"><?php esc_html_e( 'Last sync incomplete', 'aivis-os' ); ?></span><?php endif; ?></h2>
					<div class="inside"><dl class="aivis-kv">
						<dt><?php esc_html_e( 'Business', 'aivis-os' ); ?></dt><dd><?php echo '' !== $biz['business_name'] ? esc_html( $biz['business_name'] ) : '<span class="description">' . esc_html__( 'not bound', 'aivis-os' ) . '</span>'; ?></dd>
						<dt><?php esc_html_e( 'Domain', 'aivis-os' ); ?></dt><dd><code><?php echo esc_html( $o->site_host() ); ?></code> <?php esc_html_e( 'matched to', 'aivis-os' ); ?> <code><?php echo esc_html( (string) wp_parse_url( $biz['base_url'], PHP_URL_HOST ) ?: '—' ); ?></code></dd>
						<dt><?php esc_html_e( 'Token', 'aivis-os' ); ?></dt><dd><?php echo 'constant' === $o->token_source() ? esc_html__( 'From wp-config.php', 'aivis-os' ) : ( 'option' === $o->token_source() ? esc_html__( 'From the database', 'aivis-os' ) : esc_html__( 'None', 'aivis-os' ) ); ?> · <code><?php echo esc_html( $o->token_display() ); ?></code></dd>
						<dt><?php esc_html_e( 'Last complete sync', 'aivis-os' ); ?></dt><dd><?php echo $last ? esc_html( human_time_diff( $last ) . ' ' . __( 'ago', 'aivis-os' ) ) : '—'; ?> <?php if ( false === $auth ) : ?><span class="description">(<?php esc_html_e( 'incomplete — nothing retired', 'aivis-os' ); ?>)</span><?php endif; ?></dd>
						<dt><?php esc_html_e( 'Next sync', 'aivis-os' ); ?></dt><dd><?php echo $next ? esc_html( sprintf( /* translators: %s: time */ __( 'in %s', 'aivis-os' ), human_time_diff( $next ) ) ) : esc_html__( 'manual only', 'aivis-os' ); ?></dd>
					</dl></div>
				</div>
				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Withdrawal latency', 'aivis-os' ); ?></h2><div class="inside">
					<p><?php esc_html_e( 'If a page is unpublished in AIVIS, it stops being served here within:', 'aivis-os' ); ?></p>
					<div class="aivis-big"><?php echo $interval > 0 ? esc_html( (string) (int) ( $interval / 60 ) ) . ' min' : esc_html__( 'next manual sync', 'aivis-os' ); ?> <span class="description">+ <?php esc_html_e( 'cache purge', 'aivis-os' ); ?></span></div>
					<p class="description"><?php esc_html_e( 'Worst case, not typical. It is the sync interval plus the time your cache takes to drop the page. Shorten the interval under Settings if this site publishes offers or prices that must be withdrawable quickly.', 'aivis-os' ); ?></p>
				</div></div>
			</div>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Structured data on this site', 'aivis-os' ); ?></h2>
				<div class="aivis-tiles">
					<div class="aivis-tile aivis-tile--ok"><div class="n"><?php echo (int) $counts['active']; ?></div><div class="l"><?php esc_html_e( 'Injected', 'aivis-os' ); ?></div></div>
					<div class="aivis-tile aivis-tile--warn"><div class="n"><?php echo (int) $counts['stale']; ?></div><div class="l"><?php esc_html_e( 'Stale but served', 'aivis-os' ); ?></div></div>
					<div class="aivis-tile"><div class="n"><?php echo (int) $counts['hold']; ?></div><div class="l"><?php esc_html_e( 'Awaiting AIVIS', 'aivis-os' ); ?></div></div>
					<div class="aivis-tile aivis-tile--bad"><div class="n"><?php echo (int) $counts['suspended']; ?></div><div class="l"><?php esc_html_e( 'Suspended', 'aivis-os' ); ?></div></div>
					<div class="aivis-tile"><div class="n"><?php echo (int) $counts['retired']; ?></div><div class="l"><?php esc_html_e( 'Retired', 'aivis-os' ); ?></div></div>
				</div>
				<div class="aivis-legend">
					<span><span class="aivis-chip aivis-chip--ok">Active</span> <?php esc_html_e( 'injected on the page', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--warn">Stale</span> <?php esc_html_e( 'source changed; still served', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--info">Holding</span> <?php esc_html_e( 'not generated yet; nothing removed', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip aivis-chip--bad">Suspended</span> <?php esc_html_e( 'withdrawn upstream', 'aivis-os' ); ?></span>
					<span><span class="aivis-chip">Retired</span> <?php esc_html_e( 'gone; kept 30 days', 'aivis-os' ); ?></span>
				</div>
			</div>

			<?php if ( $chains ) : ?>
			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Pipelines', 'aivis-os' ); ?></h2>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Chain', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Pipeline state', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Knowledge graph', 'aivis-os' ); ?></th><th><?php esc_html_e( 'URLs', 'aivis-os' ); ?></th></tr></thead><tbody>
				<?php foreach ( $chains as $c ) : ?>
					<tr><td><strong><?php echo esc_html( (string) $c['name'] ); ?></strong></td>
					<td><?php echo 'ready' === $c['state'] ? '<span class="aivis-chip aivis-chip--ok">' . esc_html__( 'Ready', 'aivis-os' ) . '</span>' : '<span class="aivis-chip aivis-chip--info">' . esc_html( ucfirst( str_replace( '_', ' ', (string) $c['state'] ) ) ) . '</span>'; ?> <span class="description"><?php echo esc_html( sprintf( /* translators: %d: step */ __( 'step %d of 9', 'aivis-os' ), (int) $c['step'] ) ); ?></span></td>
					<td><?php echo $c['kg'] ? esc_html__( 'Built', 'aivis-os' ) : '<span class="description">' . esc_html__( 'Not built', 'aivis-os' ) . '</span>'; ?></td>
					<td><?php echo (int) $c['urlCount']; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<?php endif; ?>

			<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Pages', 'aivis-os' ); ?>
				<span style="float:right;font-weight:400">
					<?php echo $this->purge_chip( (string) ( $purge['state'] ?? '' ), $adapter->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo Menu::action_form( 'sync_run', __( 'Sync now', 'aivis-os' ), [], 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span></h2>
				<table class="widefat striped aivis-pages"><thead><tr><th style="width:44%"><?php esc_html_e( 'Page', 'aivis-os' ); ?></th><th><?php esc_html_e( 'State', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Chain', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Generated', 'aivis-os' ); ?></th></tr></thead><tbody>
				<?php if ( ! $rows ) : ?><tr><td colspan="4" class="description"><?php esc_html_e( 'Nothing synced yet.', 'aivis-os' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $rows as $r ) : $st = $this->state_of( $r ); ?>
					<tr><td>
						<div class="aivis-url"><?php echo esc_html( (string) wp_parse_url( (string) $r['source_url'], PHP_URL_PATH ) ?: '/' ); ?></div>
						<?php if ( $r['last_error_code'] ) : ?><div class="description"><?php echo esc_html( $this->explain( (string) $r['last_error_code'], $st ) ); ?></div><?php endif; ?>
						<div class="row-actions">
							<?php echo Menu::action_form( 'refresh_url', __( 'Refresh now', 'aivis-os' ), [ 'url' => $r['source_url'] ], 'button-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> |
							<?php echo Menu::action_form( 'retired' === $st ? 'restore_url' : 'disable_url', 'retired' === $st ? __( 'Restore', 'aivis-os' ) : __( 'Disable here', 'aivis-os' ), [ 'url_key' => $r['url_key'] ], 'button-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> |
							<a href="<?php echo esc_url( (string) $r['source_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open page', 'aivis-os' ); ?></a>
						</div></td>
					<td><?php echo $this->chip( $st ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><code><?php echo esc_html( (string) ( $chains[ $r['chain_id'] ]['name'] ?? $r['chain_id'] ) ); ?></code></td>
					<td class="description"><?php echo $r['source_generated_at'] ? esc_html( (string) $r['source_generated_at'] ) : '—'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
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

	private function chip( string $st ): string {
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

	private function explain( string $code, string $st ): string {
		return match ( $code ) {
			'AIVIS_NOT_GENERATED' => __( 'Not generated yet in AIVIS — nothing injected, nothing removed', 'aivis-os' ),
			'AIVIS_RETRACTED'     => 'suspended' === $st ? __( 'AIVIS returned “URL not found in your businesses” — injection stopped, cache purged, awaiting inventory confirmation', 'aivis-os' ) : __( 'Withdrawn upstream', 'aivis-os' ),
			'AIVIS_HTTP_TIMEOUT', 'AIVIS_HTTP_ERROR' => __( 'Serving last known good — AIVIS unreachable at last check', 'aivis-os' ),
			'AIVIS_AUTH_401'      => __( 'Serving last known good — token rejected', 'aivis-os' ),
			'AIVIS_SCHEMA_INVALID' => __( 'Last response failed validation — previous artifact kept', 'aivis-os' ),
			'AIVIS_ADMIN_DISABLED' => __( 'Disabled on this site by an administrator', 'aivis-os' ),
			default               => $code,
		};
	}
}
