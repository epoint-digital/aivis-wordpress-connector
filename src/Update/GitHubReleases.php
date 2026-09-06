<?php
/**
 * Answers WordPress's update check from our GitHub Releases.
 *
 * Because the plugin header carries `Update URI: https://github.com/…`,
 * WordPress skips wordpress.org for this plugin and fires
 * `update_plugins_github.com` instead. We look up the latest release, and if
 * its tag is newer than the installed version, hand back the release asset
 * `aivis-os.zip` (built reproducibly by .github/workflows/release.yml, with a
 * SHA-256SUMS file next to it).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Update;

final class GitHubReleases {

	public const REPO      = 'epoint-digital/aivis-wordpress-connector';
	public const ASSET     = 'aivis-os.zip';
	public const CACHE_KEY = 'aivis_os_latest_release';
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * @param array<string,mixed>|false $update
	 * @param array<string,mixed>       $plugin_data
	 * @param array<int,string>         $locales
	 * @return array<string,mixed>|false
	 */
	public function check( $update, array $plugin_data, string $plugin_file, array $locales ) {
		if ( AIVIS_OS_BASENAME !== $plugin_file ) {
			return $update;
		}
		$rel = $this->latest();
		if ( null === $rel ) {
			return $update;
		}
		$latest = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'v' );
		if ( '' === $latest || version_compare( $latest, AIVIS_OS_VERSION, '<=' ) ) {
			return $update; // up to date; returning $update (false) means "no update"
		}
		$package = '';
		foreach ( (array) ( $rel['assets'] ?? [] ) as $a ) {
			if ( ( $a['name'] ?? '' ) === self::ASSET ) {
				$package = (string) $a['browser_download_url'];
			}
		}
		if ( '' === $package ) {
			return $update; // a release without the built asset is not an update
		}
		return [
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'aivis-os',
			'plugin'       => $plugin_file,
			'version'      => $latest,
			'url'          => (string) ( $rel['html_url'] ?? 'https://github.com/' . self::REPO ),
			'package'      => $package,
			'requires'     => '6.5',
			'requires_php' => '8.1',
			'tested'       => '7.1',
		];
	}

	/** @return array<string,mixed>|null */
	public function latest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout'    => 8,
				'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
				'user-agent' => 'aivis-os/' . AIVIS_OS_VERSION,
			]
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			// Cache the miss briefly so a private repo or an outage does not
			// hammer the API on every admin page load.
			set_site_transient( self::CACHE_KEY, [], HOUR_IN_SECONDS );
			return null;
		}
		$rel = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $rel ) ) {
			return null;
		}
		set_site_transient( self::CACHE_KEY, $rel, self::CACHE_TTL );
		return $rel;
	}
}
