<?php
/**
 * Plugin Name:       AIVIS OS
 * Plugin URI:        https://github.com/epoint-digital/aivis-wordpress-connector
 * Description:       Delivers the structured data AIVIS generates for this site into its pages — synced locally, injected in the head, never fetched on a public request.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            epoint.digital
 * Author URI:        https://epoint.ro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aivis-os
 * Domain Path:       /languages
 * Update URI:        https://github.com/epoint-digital/aivis-wordpress-connector
 *
 * The Update URI header is a security control, not an update feature. WordPress
 * matches installed plugins to wordpress.org by folder slug; without this line,
 * anyone who registered "aivis-os" in the directory could push their code to
 * every site running this plugin. With it, WordPress never consults the
 * directory for this plugin and instead fires the `update_plugins_github.com`
 * filter, which src/Update/GitHubReleases.php answers from our own releases.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A second copy in another folder (typically a GitHub "Download ZIP", which
// lands as aivis-wordpress-connector-main) must not load twice: the constants
// would be redefined and every hook registered a second time (#70). The copy
// WordPress loads first wins; this one only says where it is.
if ( defined( 'AIVIS_OS_FILE' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: this copy's plugin file, 2: the copy that is loaded */
					__( 'AIVIS OS is installed twice. This copy (%1$s) is not loaded — deactivate and delete it under Plugins, and keep %2$s.', 'aivis-os' ),
					plugin_basename( __FILE__ ),
					plugin_basename( AIVIS_OS_FILE )
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

// The header above is the single source of truth for the version. This
// constant is derived from it so the two cannot disagree; CI additionally
// verifies the git tag and CHANGELOG match (scripts/version-check.mjs).
define( 'AIVIS_OS_VERSION', '1.0.0' );
define( 'AIVIS_OS_FILE', __FILE__ );
define( 'AIVIS_OS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIVIS_OS_URL', plugin_dir_url( __FILE__ ) );
define( 'AIVIS_OS_BASENAME', plugin_basename( __FILE__ ) );

// Fail closed on an unsupported runtime: WordPress will still load the file to
// read headers, so guard before touching anything that needs PHP 8.1 syntax.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'AIVIS OS requires PHP 8.1 or newer. The plugin is installed but inactive.', 'aivis-os' );
			echo '</p></div>';
		}
	);
	return;
}

require_once AIVIS_OS_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, [ \AivisOS\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \AivisOS\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \AivisOS\Plugin::class, 'boot' ], 5 );
