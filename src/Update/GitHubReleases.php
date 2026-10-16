<?php
/**
 * Answers WordPress's update check from our GitHub Releases.
 *
 * Because the plugin header carries `Update URI: https://github.com/…`,
 * WordPress skips wordpress.org for this plugin and fires
 * `update_plugins_github.com` instead. We look up the newest release, and if
 * its tag is newer than the installed version, hand back the release asset
 * `aivis-os.zip` (built reproducibly by .github/workflows/release.yml, with a
 * SHA-256SUMS file next to it). WordPress then shows the update on the
 * Plugins screen, applies it on click, or on its own when the admin enabled
 * auto-updates for this plugin.
 *
 * Channels: `stable` (default) follows GitHub's "latest" release, which never
 * is a pre-release; `prerelease` — `define( 'AIVIS_OS_UPDATE_CHANNEL',
 * 'prerelease' )` in wp-config.php — also takes release candidates, for pilot
 * sites. Drafts are never offered on either channel.
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

	public const CHANNEL_STABLE     = 'stable';
	public const CHANNEL_PRERELEASE = 'prerelease';

	/** The channel this site follows (§19). */
	public static function channel(): string {
		return defined( 'AIVIS_OS_UPDATE_CHANNEL' ) && self::CHANNEL_PRERELEASE === AIVIS_OS_UPDATE_CHANNEL ? self::CHANNEL_PRERELEASE : self::CHANNEL_STABLE;
	}

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
		$latest = self::version_of( $rel );
		if ( '' === $latest || version_compare( $latest, AIVIS_OS_VERSION, '<=' ) ) {
			return $update; // up to date; returning $update (false) means "no update"
		}
		$package = self::asset_url( $rel );
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

	/**
	 * The newest release this site may take, or null when GitHub did not
	 * answer (cached briefly so an outage does not hammer the API on every
	 * admin page load). Stable: GitHub's own "latest". Pre-release channel:
	 * the highest version among the recent non-draft releases.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest(): ?array {
		$channel = self::channel();
		$key     = self::CACHE_KEY . '_' . $channel;
		$cached  = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$path = self::CHANNEL_PRERELEASE === $channel ? '/releases?per_page=20' : '/releases/latest';
		$res  = wp_safe_remote_get(
			'https://api.github.com/repos/' . self::REPO . $path,
			[
				'timeout'    => 8,
				'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
				'user-agent' => 'aivis-os/' . AIVIS_OS_VERSION,
			]
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_site_transient( $key, [], HOUR_IN_SECONDS );
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$rel  = self::CHANNEL_PRERELEASE === $channel ? self::newest( is_array( $body ) ? $body : [] ) : ( is_array( $body ) ? $body : null );
		if ( null === $rel || ! empty( $rel['draft'] ) ) {
			set_site_transient( $key, [], HOUR_IN_SECONDS );
			return null;
		}
		set_site_transient( $key, $rel, self::CACHE_TTL );
		return $rel;
	}

	/**
	 * @param list<array<string,mixed>> $releases
	 * @return array<string,mixed>|null
	 */
	public static function newest( array $releases ): ?array {
		$best = null;
		foreach ( $releases as $r ) {
			if ( ! is_array( $r ) || ! empty( $r['draft'] ) || '' === self::version_of( $r ) ) {
				continue;
			}
			if ( null === $best || version_compare( self::version_of( $r ), self::version_of( $best ), '>' ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	/** @param array<string,mixed> $rel */
	public static function version_of( array $rel ): string {
		return ltrim( (string) ( $rel['tag_name'] ?? '' ), 'v' );
	}

	/** @param array<string,mixed> $rel */
	public static function asset_url( array $rel ): string {
		foreach ( (array) ( $rel['assets'] ?? [] ) as $a ) {
			if ( ( $a['name'] ?? '' ) === self::ASSET ) {
				return (string) $a['browser_download_url'];
			}
		}
		return '';
	}
}
