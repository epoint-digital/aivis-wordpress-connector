<?php
/**
 * §09 — where injection is allowed. Anything not a normal public front-end
 * HTML GET is out, including a WordPress 404 page (not to be confused with the
 * HTTP 404 from AIVIS that §06 uses as a retraction signal).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

use AivisOS\Storage\Options;

final class Gates {

	public function __construct( private readonly Options $options ) {}

	public function open(): bool {
		if ( ! $this->options->injection_enabled() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_404() || is_embed() || is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return false;
		}
		return true;
	}
}
