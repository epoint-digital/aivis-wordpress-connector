<?php
/**
 * Settings screen (§11, §13; docs/admin-ui.html).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

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
		return 'saved';
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
