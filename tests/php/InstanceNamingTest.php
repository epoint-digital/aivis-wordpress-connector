<?php
declare( strict_types=1 );

use AivisOS\Admin\SettingsPage;
use AivisOS\Admin\StatusPage;
use AivisOS\Plugin;
use AivisOS\Storage\Options;
use PHPUnit\Framework\TestCase;

/** §11 — the two instances are named by their environment; their addresses are never shown. */
final class InstanceNamingTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_an_instance_host_is_named_by_its_environment(): void {
		foreach ( Options::ENVIRONMENTS as $key => $url ) {
			$host  = (string) parse_url( $url, PHP_URL_HOST );
			$label = Options::instance_label( $host );
			self::assertSame( 'test' === $key ? 'the Test environment' : 'the Production environment', $label );
			self::assertSame( $label, Options::instance_label( strtoupper( $host ) ), 'host names are case-insensitive' );
		}
		self::assertSame( 'staging.example.com', Options::instance_label( 'staging.example.com' ), 'a custom host is the site\'s own and is shown' );
		self::assertSame( '', Options::instance_label( '' ) );
	}

	public function test_free_text_loses_the_addresses(): void {
		foreach ( Options::ENVIRONMENTS as $url ) {
			$host = (string) parse_url( $url, PHP_URL_HOST );
			$out  = Options::name_instances( "cURL error 6: Could not resolve host: {$host} ({$url}/api/public/v1/me)" );
			self::assertStringNotContainsString( $host, $out );
			self::assertStringContainsString( 'environment', $out );
		}
		self::assertSame( 'nothing to name', Options::name_instances( 'nothing to name' ) );
	}

	public function test_no_admin_screen_spells_out_an_instance_address(): void {
		foreach ( array_keys( Options::ENVIRONMENTS ) as $env ) {
			WPStub::reset();
			WPStub::$options['aivis_os_environment'] = $env;
			foreach ( [ SettingsPage::class, StatusPage::class ] as $screen ) {
				ob_start();
				( new $screen( Plugin::instance() ) )->render();
				$html = (string) ob_get_clean();
				self::assertNotSame( '', $html, $screen );
				foreach ( Options::ENVIRONMENTS as $url ) {
					self::assertStringNotContainsString( (string) parse_url( $url, PHP_URL_HOST ), $html, "{$screen} on {$env}" );
				}
			}
		}
	}

	public function test_the_plugin_carries_its_registered_name(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/aivis-os.php', false, null, 0, 2000 );
		self::assertStringContainsString( 'Plugin Name:       JSON-LD Delivery for Aivis-OS', $header );
		self::assertStringContainsString( 'Text Domain:       aivis-os', $header, 'technical names do not change' );
	}
}
