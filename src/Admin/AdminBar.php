<?php
/**
 * Admin-bar warning for logged-in users who can manage the plugin — shown on
 * the front end as well as in wp-admin, so an admin browsing the site sees a
 * conflict or a withdrawn page without opening the dashboard. Visitors see
 * nothing; the bar is not rendered for them.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Admin;

use AivisOS\Plugin;

final class AdminBar {

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_bar_menu', [ $this, 'node' ], 90 );
	}

	public function node( \WP_Admin_Bar $bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( Menu::capability() ) ) {
			return;
		}
		$o        = $this->plugin->options();
		$problems = [];
		if ( $o->conflicts_unacknowledged() ) {
			$c          = $o->conflicts();
			$problems[] = sprintf(
				/* translators: %d: pages */
				_n( 'Other structured data on %d page', 'Other structured data on %d pages', count( $c['items'] ), 'aivis-os' ),
				count( $c['items'] )
			);
		}
		$counts = $this->plugin->repository()->counts();
		if ( $counts['suspended'] > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: pages */
				_n( '%d page withdrawn in AIVIS', '%d pages withdrawn in AIVIS', $counts['suspended'], 'aivis-os' ),
				$counts['suspended']
			);
		}
		if ( ! $problems ) {
			return;
		}
		$bar->add_node(
			[
				'id'    => 'aivis-os-warning',
				'title' => '<span class="ab-icon dashicons dashicons-warning" style="color:#f0b849"></span> AIVIS OS: ' . esc_html( implode( ' · ', $problems ) ),
				'href'  => admin_url( 'admin.php?page=' . Menu::SLUG_STATUS ),
				'meta'  => [ 'title' => __( 'AIVIS OS needs attention', 'aivis-os' ) ],
			]
		);
	}
}
