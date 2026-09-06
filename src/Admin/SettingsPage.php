<?php
/**
 * Settings screen (§11, §13; docs/admin-ui.html).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Delivery\Language;
use AivisOS\Domain\ErrorCode;
use AivisOS\Plugin;
use AivisOS\Sync\Scheduler;

final class SettingsPage {

	public function __construct( private readonly Plugin $plugin ) {}

	public function render(): void {
		$o      = $this->plugin->options();
		$source = $o->token_source();
		$status = $o->token_status();
		$biz    = $o->business();
		$host   = $o->site_host();
		$businesses = $this->businesses();
		$matching   = array_values( array_filter( $businesses, fn( $b ) => $this->host_eq( (string) wp_parse_url( (string) $b['baseUrl'], PHP_URL_HOST ), $host ) ) );
		$interval   = $o->sync_interval();
		$adapters   = $this->plugin->cache()->all();
		$current    = $this->plugin->cache()->adapter();
		?>
		<div class="wrap aivis-os">
			<h1>AIVIS OS</h1>
			<p class="description"><?php esc_html_e( 'Delivers the structured data AIVIS generates for this site into its pages.', 'aivis-os' ); ?></p>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG_STATUS ) ); ?>"><?php esc_html_e( 'Status', 'aivis-os' ); ?></a>
				<a class="nav-tab nav-tab-active" href="#"><?php esc_html_e( 'Settings', 'aivis-os' ); ?></a>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aivis_os_action' ); ?>
				<input type="hidden" name="action" value="aivis_os_action"><input type="hidden" name="do" value="save_settings">

				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Connection', 'aivis-os' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="aivis_token"><?php esc_html_e( 'API token', 'aivis-os' ); ?></label></th><td>
							<?php if ( 'constant' === $source ) : ?>
								<p><code>AIVIS_API_TOKEN</code> <?php esc_html_e( 'is defined in wp-config.php', 'aivis-os' ); ?> <span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'Recommended', 'aivis-os' ); ?></span></p>
								<p class="description"><?php esc_html_e( 'Held outside the database, so it does not travel in backups, staging clones or migrations. To change it, edit wp-config.php.', 'aivis-os' ); ?></p>
							<?php else : ?>
								<input type="password" id="aivis_token" name="aivis_token" class="regular-text" autocomplete="off" value="" placeholder="<?php echo esc_attr( '' !== $o->token_display() ? $o->token_display() : 'aivis_…' ); ?>">
								<p class="description"><?php esc_html_e( 'Stored in this site’s database. Prefer defining AIVIS_API_TOKEN in wp-config.php — see the warning below. Leave blank to keep the current token.', 'aivis-os' ); ?></p>
							<?php endif; ?>
							<p style="margin-top:10px"><?php echo Menu::action_form( 'test_connection', __( 'Test connection', 'aivis-os' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( true === $status['valid'] ) : ?>
								<span class="aivis-chip aivis-chip--ok"><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Connected as %s', 'aivis-os' ), $status['email'] ) ); ?></span>
							<?php elseif ( false === $status['valid'] ) : ?>
								<span class="aivis-chip aivis-chip--bad"><?php esc_html_e( 'Token invalid or revoked', 'aivis-os' ); ?></span>
							<?php endif; ?></p>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'What this token can reach', 'aivis-os' ); ?></th><td>
							<div class="notice notice-warning inline" style="margin:0"><p>
								<?php echo wp_kses_post( sprintf(
									/* translators: %d: number of businesses */
									__( 'An AIVIS API token is scoped to your <strong>account</strong>, not to one business. This token can read structured data for <strong>all %d businesses</strong> on the account, not only this site’s.', 'aivis-os' ),
									count( $businesses )
								) ); ?></p>
								<p><?php echo wp_kses_post( __( 'Because of that, install this plugin only on sites you control. Keeping the token in <code>wp-config.php</code> rather than the database limits where copies of it end up.', 'aivis-os' ) ); ?></p>
							</div>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Business', 'aivis-os' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'This site serves', 'aivis-os' ); ?></th><td>
							<?php if ( 'none' === $source || true !== $status['valid'] ) : ?>
								<p class="description"><?php esc_html_e( 'Add a valid token and test the connection first — the list of businesses comes from AIVIS.', 'aivis-os' ); ?></p>
							<?php elseif ( 0 === count( $matching ) ) : ?>
								<p><?php echo wp_kses_post( sprintf( /* translators: %s: host */ __( 'No business on this account uses <code>%s</code>.', 'aivis-os' ), esc_html( $host ) ) ); ?></p>
								<p class="description"><?php esc_html_e( 'A business has exactly one domain in AIVIS. Create one for this site, or correct its base URL there, then test the connection again. Businesses on other domains are not offered — binding one would serve another site’s data here.', 'aivis-os' ); ?></p>
							<?php elseif ( 1 === count( $matching ) ) : ?>
								<input type="hidden" name="business_id" value="<?php echo esc_attr( (string) $matching[0]['id'] ); ?>">
								<p><strong><?php echo esc_html( (string) $matching[0]['name'] ); ?></strong> <span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'Matched automatically', 'aivis-os' ); ?></span></p>
								<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: host */ __( 'The only AIVIS business whose domain is <code>%s</code>. Nothing to choose.', 'aivis-os' ), esc_html( $host ) ) ); ?></p>
							<?php else : ?>
								<?php foreach ( $matching as $b ) : ?>
									<label style="display:block;margin-bottom:8px"><input type="radio" name="business_id" value="<?php echo esc_attr( (string) $b['id'] ); ?>" <?php checked( $biz['business_id'], $b['id'] ); ?>>
										<strong><?php echo esc_html( (string) $b['name'] ); ?></strong>
										<span class="description"> · <?php echo esc_html( sprintf( /* translators: 1: chains, 2: date */ __( '%1$d chains · created %2$s', 'aivis-os' ), (int) $b['chainCount'], substr( (string) $b['createdAt'], 0, 10 ) ) ); ?></span></label>
								<?php endforeach; ?>
								<p class="description"><?php echo wp_kses_post( sprintf( /* translators: %s: host */ __( 'More than one business uses <code>%s</code>. The plugin will not guess between them — binding the wrong one would publish another business’s structured data on these pages, with no visible error.', 'aivis-os' ), esc_html( $host ) ) ); ?></p>
							<?php endif; ?>
						</td></tr>
						<tr><th scope="row"><label for="aivis_hosts"><?php esc_html_e( 'Allowed hosts', 'aivis-os' ); ?></label></th><td>
							<input type="text" id="aivis_hosts" name="allowed_hosts" class="regular-text" value="<?php echo esc_attr( implode( ', ', $biz['allowed_hosts'] ) ); ?>" placeholder="<?php echo esc_attr( $host . ', www.' . $host ); ?>">
							<p class="description"><?php esc_html_e( 'Structured data is rejected unless the page it names is on one of these hosts (this site’s own host is always included). The check is what stops another business’s data appearing on this site.', 'aivis-os' ); ?></p>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox" id="aivis-languages"><h2 class="hndle"><?php esc_html_e( 'Languages & chains', 'aivis-os' ); ?></h2><div class="inside">
					<?php $this->languages_box( $biz ); ?>
				</div></div>

				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Delivery', 'aivis-os' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Injection', 'aivis-os' ); ?></th><td>
							<label><input type="checkbox" name="injection" value="1" <?php checked( $o->injection_enabled() ); ?>> <?php esc_html_e( 'Add AIVIS structured data to pages on this site', 'aivis-os' ); ?></label>
							<p class="description"><?php echo wp_kses_post( __( 'One <code>&lt;script type="application/ld+json" data-aivis="1"&gt;</code> in the page head. Existing structured data from your theme or SEO plugin is never read or changed.', 'aivis-os' ) ); ?></p>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Unknown pages', 'aivis-os' ); ?></th><td>
							<label><input type="checkbox" name="on_demand" value="1" <?php checked( $o->on_demand_enabled() ); ?>> <?php esc_html_e( 'When a page is viewed that has no structured data yet, ask AIVIS about it in the background', 'aivis-os' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. The regular sync already picks up every page AIVIS knows; this only shortens the wait for brand-new pages, at the cost of a small database write the first time each unknown page is viewed.', 'aivis-os' ); ?></p>
						</td></tr>
						<tr><th scope="row"><label for="aivis_interval"><?php esc_html_e( 'Check AIVIS for changes', 'aivis-os' ); ?></label></th><td>
							<select id="aivis_interval" name="interval">
								<option value="300" <?php selected( $interval, 300 ); ?>><?php esc_html_e( 'Every 5 minutes', 'aivis-os' ); ?></option>
								<option value="900" <?php selected( $interval, 900 ); ?>><?php esc_html_e( 'Every 15 minutes (recommended)', 'aivis-os' ); ?></option>
								<option value="3600" <?php selected( $interval, 3600 ); ?>><?php esc_html_e( 'Hourly', 'aivis-os' ); ?></option>
								<option value="0" <?php selected( $interval, 0 ); ?>><?php esc_html_e( 'Manually only', 'aivis-os' ); ?></option>
							</select>
							<p class="description"><?php
							if ( 0 === $interval ) {
								esc_html_e( 'With manual syncing, a page unpublished in AIVIS keeps being served here until you sync. Only choose this if you sync from WP-CLI or a system cron.', 'aivis-os' );
							} else {
								echo wp_kses_post( sprintf( /* translators: %d: minutes */ __( 'A page unpublished in AIVIS stops being served here within <strong>%d minutes plus your cache purge</strong>.', 'aivis-os' ), (int) ( $interval / 60 ) ) );
								if ( 300 === $interval ) {
									echo ' ' . esc_html__( 'Five minutes needs a real system cron — WordPress’s built-in cron only runs when someone visits the site.', 'aivis-os' );
								}
							}
							?></p>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox" id="aivis-sources"><h2 class="hndle"><?php esc_html_e( 'Structured data sources', 'aivis-os' ); ?></h2><div class="inside">
					<p class="description"><?php esc_html_e( 'AIVIS is the primary source of structured data on this site. Anything else that emits JSON-LD is flagged here and in the admin bar. The connector never changes another plugin’s behaviour — it tells you where to switch that output off. Publishing continues either way.', 'aivis-os' ); ?></p>
					<?php
					$conf     = $o->conflicts();
					$active   = $conf['plugins'] ?: \AivisOS\Delivery\Conflicts::active_plugins();
					$found    = [];
					foreach ( (array) $conf['items'] as $f ) {
						foreach ( (array) ( $f['sources'] ?? [] ) as $s ) {
							$found[ (string) $s ] = ( $found[ (string) $s ] ?? 0 ) + 1;
						}
					}
					$rows = array_values( array_unique( array_merge( $active, array_keys( $found ) ) ) );
					if ( ! $rows ) :
					?>
						<p><span class="aivis-chip aivis-chip--ok"><?php esc_html_e( 'No other emitter detected', 'aivis-os' ); ?></span>
						<?php if ( $conf['scanned_at'] ) : ?><span class="description"> <?php echo esc_html( sprintf( /* translators: 1: pages, 2: time ago */ __( '%1$d pages scanned %2$s ago', 'aivis-os' ), $conf['pages_scanned'], human_time_diff( $conf['scanned_at'] ) ) ); ?></span><?php endif; ?></p>
					<?php else : ?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'aivis-os' ); ?></th><th><?php esc_html_e( 'Found on', 'aivis-os' ); ?></th><th><?php esc_html_e( 'How to switch it off there', 'aivis-os' ); ?></th></tr></thead><tbody>
					<?php foreach ( $rows as $key ) : ?>
						<tr>
							<td><strong><?php echo esc_html( \AivisOS\Delivery\Conflicts::label( $key ) ); ?></strong>
								<?php if ( in_array( $key, $active, true ) ) : ?><span class="description"> · <?php esc_html_e( 'plugin active', 'aivis-os' ); ?></span><?php endif; ?></td>
							<td><?php echo isset( $found[ $key ] ) ? '<span class="aivis-chip aivis-chip--bad">' . esc_html( sprintf( /* translators: %d: pages */ _n( '%d page', '%d pages', $found[ $key ], 'aivis-os' ), $found[ $key ] ) ) . '</span>' : '<span class="description">' . esc_html__( 'not seen in the last scan', 'aivis-os' ) . '</span>'; ?></td>
							<td><span class="description"><?php echo esc_html( \AivisOS\Delivery\Conflicts::guidance( $key ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
					<?php endif; ?>
					<p style="margin-top:10px"><?php echo Menu::action_form( 'scan_conflicts', __( 'Scan pages now', 'aivis-os' ), [], 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Notifications', 'aivis-os' ); ?></th><td>
							<label class="check"><input type="checkbox" name="notify_email" value="1" <?php checked( $o->notify_email() ); ?>> <?php echo esc_html( sprintf( /* translators: %s: admin email */ __( 'Email %s when the set of conflicts changes', 'aivis-os' ), (string) get_option( 'admin_email' ) ) ); ?></label><br>
							<label class="check"><input type="checkbox" name="report_to_aivis" value="1" <?php checked( $o->report_to_aivis() ); ?>> <?php esc_html_e( 'Report connector status to AIVIS after each complete sync and when conflicts change', 'aivis-os' ); ?></label>
							<p class="description"><?php esc_html_e( 'The report carries the plugin version, this site’s host, sync counts, cache state and the conflicts found. Nothing about visitors, crawlers or users — ever.', 'aivis-os' ); ?></p>
						</td></tr>
					</table>
				</div></div>

				<div class="postbox"><h2 class="hndle"><?php esc_html_e( 'Page cache', 'aivis-os' ); ?></h2><div class="inside">
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="aivis_cache"><?php esc_html_e( 'Cache plugin', 'aivis-os' ); ?></label></th><td>
							<select id="aivis_cache" name="cache_adapter">
								<option value="auto" <?php selected( $o->cache_adapter(), 'auto' ); ?>><?php echo esc_html( sprintf( /* translators: %s: adapter label */ __( 'Detect automatically (currently: %s)', 'aivis-os' ), $current->label() ) ); ?></option>
								<?php foreach ( $adapters as $a ) : ?>
									<option value="<?php echo esc_attr( $a->id() ); ?>" <?php selected( $o->cache_adapter(), $a->id() ); ?> <?php disabled( ! $a->is_available() ); ?>><?php echo esc_html( $a->label() ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Changed structured data purges the affected pages. The plugin reports a page as live only when the cache plugin confirmed the purge — never before.', 'aivis-os' ); ?></p>
						</td></tr>
						<tr><th scope="row"><?php esc_html_e( 'On uninstall', 'aivis-os' ); ?></th><td>
							<label><input type="checkbox" name="keep_data" value="1" <?php checked( $o->keep_data_on_uninstall() ); ?>> <?php esc_html_e( 'Keep synced structured data and settings when the plugin is deleted (the token is always removed)', 'aivis-os' ); ?></label>
						</td></tr>
					</table>
				</div></div>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'aivis-os' ); ?></button>
				<?php if ( 'none' !== $source ) : ?>
					&nbsp; <?php echo Menu::action_form( 'disconnect', __( 'Disconnect and remove local data', 'aivis-os' ), [], 'button button-link-delete', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?></p>
			</form>
		</div>
		<?php
	}

	/** @param array<string,mixed> $post */
	public function save( array $post ): string {
		$o = $this->plugin->options();
		if ( 'constant' !== $o->token_source() ) {
			$tok = trim( (string) ( $post['aivis_token'] ?? '' ) );
			if ( '' !== $tok ) {
				if ( ! str_starts_with( $tok, 'aivis_' ) ) {
					return 'auth_failed';
				}
				$o->set_token( $tok );
				$o->set_token_status( null );
			}
		}
		$o->set_injection_enabled( ! empty( $post['injection'] ) );
		$o->set_on_demand_enabled( ! empty( $post['on_demand'] ) );
		$o->set_notify_email( ! empty( $post['notify_email'] ) );
		$o->set_report_to_aivis( ! empty( $post['report_to_aivis'] ) );
		$interval = (int) ( $post['interval'] ?? 900 );
		if ( $interval !== $o->sync_interval() ) {
			$o->set_sync_interval( $interval );
			Scheduler::reschedule_sync( $interval );
		}
		$o->set_cache_adapter( sanitize_key( (string) ( $post['cache_adapter'] ?? 'auto' ) ) );
		$o->set_keep_data_on_uninstall( ! empty( $post['keep_data'] ) );

		$hosts = array_filter( array_map( 'trim', explode( ',', (string) ( $post['allowed_hosts'] ?? '' ) ) ) );
		$bid   = sanitize_text_field( (string) ( $post['business_id'] ?? '' ) );
		if ( '' !== $bid ) {
			$b = null;
			foreach ( $this->businesses() as $cand ) {
				if ( (string) $cand['id'] === $bid ) {
					$b = $cand;
				}
			}
			// Hard rule (§07): the business domain must match this site.
			if ( $b && $this->host_eq( (string) wp_parse_url( (string) $b['baseUrl'], PHP_URL_HOST ), $o->site_host() ) ) {
				$o->set_business( $bid, (string) $b['name'], (string) $b['baseUrl'], $hosts );
				Scheduler::request_sync_now();
			} else {
				$o->record( 'AIVIS_SCOPE_MISMATCH', 'refused to bind a business whose domain does not match this site' );
				return 'auth_failed';
			}
		} elseif ( '' !== $o->business()['business_id'] ) {
			$cur = $o->business();
			$o->set_business( $cur['business_id'], $cur['business_name'], $cur['base_url'], $hosts );
		}
		if ( isset( $post['chain_lang'] ) && is_array( $post['chain_lang'] ) ) {
			$this->save_chain_languages( (array) $post['chain_lang'] );
		}
		return 'saved';
	}

	/**
	 * §07a — persist the chain → language assignment. Only chains of the bound
	 * business and only languages this site has; a chain that loses its language
	 * stops serving now (rows deactivated, caches purged) rather than on the
	 * next sync.
	 *
	 * @param array<mixed,mixed> $posted chain_id => language code ('' = unassigned)
	 */
	public function save_chain_languages( array $posted ): void {
		$o       = $this->plugin->options();
		$catalog = $this->plugin->assignment()->catalog();
		$ids     = null === $catalog ? null : array_map( static fn( array $c ): string => $c['id'], $catalog );
		$langs   = Language::site_languages();
		$map     = [];
		foreach ( $posted as $chain => $code ) {
			$chain = sanitize_text_field( (string) $chain );
			$code  = Language::normalize( sanitize_text_field( (string) $code ) );
			if ( '' === $chain || '' === $code || ! isset( $langs[ $code ] ) ) {
				continue;
			}
			if ( null !== $ids && ! in_array( $chain, $ids, true ) ) {
				continue;
			}
			$map[ $chain ] = $code;
		}
		if ( $map === $o->chain_languages() ) {
			return;
		}
		$o->set_chain_languages( $map );
		$urls = $this->plugin->repository()->deactivate_chains_not_in( array_keys( $map ), ErrorCode::LANGUAGE_UNASSIGNED );
		if ( $urls ) {
			$this->plugin->synchronizer()->purge( $urls );
		}
		Scheduler::request_sync_now();
	}

	/** @param array<string,mixed> $biz */
	private function languages_box( array $biz ): void {
		$o        = $this->plugin->options();
		$provider = Language::provider();
		$langs    = Language::site_languages();
		$names    = array_map( static fn( array $l ): string => $l['name'] . ' (' . $l['code'] . ')' . ( $l['default'] ? ' · ' . __( 'default', 'aivis-os' ) : '' ), $langs );
		?>
		<p><?php echo wp_kses_post( sprintf(
			/* translators: 1: comma-separated languages, 2: provider name */
			_n( 'This site publishes in <strong>%1$s</strong> — %2$s.', 'This site publishes in <strong>%1$s</strong> — managed by %2$s.', count( $langs ), 'aivis-os' ),
			esc_html( implode( ', ', $names ) ),
			esc_html( Language::provider_label( $provider ) )
		) ); ?></p>
		<p class="description"><?php esc_html_e( 'Each chain is one language in AIVIS — intents and forensic prompts are bound per language. Assign every chain to the WordPress language its pages are in. A language without a chain receives no structured data; a chain without a language is not synced. Language subdomains (de.example.com) are fine; a separate domain per language needs its own AIVIS business and its own WordPress site.', 'aivis-os' ); ?></p>
		<?php
		if ( '' === $biz['business_id'] ) {
			echo '<p class="description">' . esc_html__( 'Bind a business first — the chains come from AIVIS.', 'aivis-os' ) . '</p>';
			return;
		}
		$assignment = $this->plugin->assignment();
		$catalog    = $assignment->catalog();
		if ( null === $catalog ) {
			echo '<div class="notice notice-warning inline" style="margin:0"><p>' . esc_html__( 'AIVIS could not be reached, so the chain list is unavailable right now. Existing assignments are unchanged.', 'aivis-os' ) . '</p></div>';
			return;
		}
		if ( ! $catalog ) {
			echo '<p>' . esc_html__( 'This business has no chains yet. Create one in AIVIS for each language of this site.', 'aivis-os' ) . '</p>';
			return;
		}
		$map      = $o->chain_languages();
		$counts   = $this->plugin->repository()->counts_by_chain();
		$mismatch = (array) ( $o->sync_state()['language_mismatch'] ?? [] );
		?>
		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Chain', 'aivis-os' ); ?></th>
			<th><?php esc_html_e( 'AIVIS reports', 'aivis-os' ); ?></th>
			<th><?php esc_html_e( 'Serves WordPress language', 'aivis-os' ); ?></th>
			<th><?php esc_html_e( 'Pages', 'aivis-os' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $catalog as $c ) :
			$hint    = $assignment->hint( $c['id'] );
			$current = $map[ $c['id'] ] ?? '';
			$suggest = '';
			if ( '' === $current && null !== $hint['language'] && ! $hint['mixed'] ) {
				foreach ( $langs as $code => $l ) {
					if ( Language::same( $hint['language'], $code ) ) {
						$suggest = $code;
						break;
					}
				}
			}
			$cnt = $counts[ $c['id'] ] ?? [ 'active' => 0, 'hold' => 0, 'suspended' => 0, 'inactive' => 0, 'total' => 0 ];
			?>
			<tr>
				<td><strong><?php echo esc_html( $c['name'] ); ?></strong><br><span class="description"><code><?php echo esc_html( $c['id'] ); ?></code> · <?php echo esc_html( sprintf( /* translators: 1: state, 2: url count */ __( '%1$s · %2$d URLs', 'aivis-os' ), ucfirst( str_replace( '_', ' ', $c['state'] ) ), $c['urlCount'] ) ); ?></span></td>
				<td><?php
					if ( null === $hint['language'] ) {
						echo '<span class="description">' . esc_html__( 'no pages yet', 'aivis-os' ) . '</span>';
					} elseif ( $hint['mixed'] ) {
						echo '<span class="aivis-chip aivis-chip--warn">' . esc_html( sprintf( /* translators: %s: language code */ __( 'mixed (%s and more)', 'aivis-os' ), $hint['language'] ) ) . '</span>';
					} else {
						echo '<code>' . esc_html( $hint['language'] ) . '</code>';
					}
					if ( isset( $mismatch[ $c['id'] ] ) ) {
						echo ' <span class="aivis-chip aivis-chip--bad">' . esc_html( sprintf( /* translators: 1: count, 2: code */ __( '%1$d pages reported as %2$s', 'aivis-os' ), (int) $mismatch[ $c['id'] ]['count'], (string) $mismatch[ $c['id'] ]['aivis'] ) ) . '</span>';
					}
				?></td>
				<td>
					<select name="chain_lang[<?php echo esc_attr( $c['id'] ); ?>]">
						<option value=""><?php esc_html_e( '— not assigned (not synced) —', 'aivis-os' ); ?></option>
						<?php foreach ( $langs as $code => $l ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( '' !== $current ? $current : $suggest, $code ); ?>><?php echo esc_html( $l['name'] . ' (' . $code . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( '' === $current && '' !== $suggest ) : ?><br><span class="description"><?php esc_html_e( 'suggested from what AIVIS reports — save to confirm', 'aivis-os' ); ?></span><?php endif; ?>
				</td>
				<td><?php echo esc_html( sprintf( /* translators: 1: active, 2: holding */ __( '%1$d injected · %2$d holding', 'aivis-os' ), $cnt['active'], $cnt['hold'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<p style="margin-top:10px">
		<?php foreach ( $assignment->summary( $catalog )['languages'] as $l ) :
			if ( $l['chains'] ) : ?>
				<span class="aivis-chip aivis-chip--ok"><?php echo esc_html( sprintf( /* translators: 1: language, 2: chain count */ _n( '%1$s — %2$d chain', '%1$s — %2$d chains', count( $l['chains'] ), 'aivis-os' ), $l['name'], count( $l['chains'] ) ) ); ?></span>
			<?php else : ?>
				<span class="aivis-chip aivis-chip--bad"><?php echo esc_html( sprintf( /* translators: %s: language */ __( '%s — no chain: nothing is injected on these pages', 'aivis-os' ), $l['name'] ) ); ?></span>
			<?php endif;
		endforeach; ?>
		</p>
		<?php
	}

	/** @return list<array<string,mixed>> cached briefly; this is admin-only. */
	private function businesses(): array {
		$o = $this->plugin->options();
		if ( 'none' === $o->token_source() ) {
			return [];
		}
		$cached = get_transient( 'aivis_os_businesses' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$all    = [];
		$cursor = null;
		$ok     = false;
		do {
			$r = $this->plugin->client()->businesses( $cursor );
			if ( ! $r->ok() ) {
				break;
			}
			$ok = true;
			foreach ( (array) ( $r->body['items'] ?? [] ) as $b ) {
				$all[] = $b;
			}
			$cursor = ( ! empty( $r->body['hasMore'] ) && is_string( $r->body['nextCursor'] ?? null ) ) ? $r->body['nextCursor'] : null;
		} while ( null !== $cursor );
		// Cache only a successful walk: a failed one must not hide the fixed
		// token behind five minutes of an empty list.
		if ( $ok ) {
			set_transient( 'aivis_os_businesses', $all, 5 * MINUTE_IN_SECONDS );
		}
		return $all;
	}

	private function host_eq( string $a, string $b ): bool {
		$s = static fn( string $h ): string => (string) preg_replace( '/^www\./', '', strtolower( $h ) );
		return '' !== $a && $s( $a ) === $s( $b );
	}
}
