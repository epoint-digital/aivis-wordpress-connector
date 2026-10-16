<?php
declare( strict_types=1 );

use AivisOS\Update\GitHubReleases;
use PHPUnit\Framework\TestCase;

/** §19 — updates from GitHub Releases: stable follows "latest", drafts never, pre-releases only on the channel. */
final class GitHubReleasesTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	private static function rel( string $tag, bool $draft = false, bool $pre = false, bool $asset = true ): array {
		return [ 'tag_name' => $tag, 'draft' => $draft, 'prerelease' => $pre, 'html_url' => "https://github.com/x/y/releases/tag/{$tag}", 'assets' => $asset ? [ [ 'name' => 'aivis-os.zip', 'browser_download_url' => "https://github.com/x/y/releases/download/{$tag}/aivis-os.zip" ] ] : [] ];
	}

	public function test_default_channel_is_stable_and_asks_github_for_latest(): void {
		self::assertSame( 'stable', GitHubReleases::channel() );
		WPStub::queue( 200, self::rel( 'v1.2.0' ) );
		$u = ( new GitHubReleases() )->check( false, [], AIVIS_OS_BASENAME, [] );
		self::assertStringEndsWith( '/releases/latest', parse_url( WPStub::$http_log[0][0], PHP_URL_PATH ) );
		self::assertSame( '1.2.0', $u['version'] );
		self::assertSame( 'https://github.com/x/y/releases/download/v1.2.0/aivis-os.zip', $u['package'] );
	}

	public function test_same_or_older_release_and_missing_asset_are_not_updates(): void {
		WPStub::queue( 200, self::rel( 'v1.0.0' ) );
		self::assertFalse( ( new GitHubReleases() )->check( false, [], AIVIS_OS_BASENAME, [] ), 'same version' );
		WPStub::reset();
		WPStub::queue( 200, self::rel( 'v9.0.0', false, false, false ) );
		self::assertFalse( ( new GitHubReleases() )->check( false, [], AIVIS_OS_BASENAME, [] ), 'no built asset' );
	}

	public function test_newest_skips_drafts_and_picks_the_highest_version(): void {
		$best = GitHubReleases::newest( [ self::rel( 'v1.0.0' ), self::rel( 'v1.1.0-rc.1', false, true ), self::rel( 'v2.0.0', true ), self::rel( 'v1.0.1' ) ] );
		self::assertSame( 'v1.1.0-rc.1', $best['tag_name'], 'a draft 2.0.0 is never offered; the rc is the newest non-draft' );
		self::assertNull( GitHubReleases::newest( [ self::rel( 'v3.0.0', true ) ] ) );
	}

	public function test_github_outage_is_cached_briefly_and_offers_nothing(): void {
		WPStub::queue( 404, [ 'message' => 'Not Found' ] );
		self::assertFalse( ( new GitHubReleases() )->check( false, [], AIVIS_OS_BASENAME, [] ) );
		self::assertFalse( ( new GitHubReleases() )->check( false, [], AIVIS_OS_BASENAME, [] ), 'second call served from the cached miss' );
		self::assertCount( 1, WPStub::$http_log );
	}
}
