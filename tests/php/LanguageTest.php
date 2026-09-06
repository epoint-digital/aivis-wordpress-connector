<?php
declare( strict_types=1 );

use AivisOS\Delivery\Language;
use PHPUnit\Framework\TestCase;

/* Provider stubs — what the real plugins expose, driven by WPStub state. */
if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( array $args = [] ): array {
		$f = $args['fields'] ?? 'slug';
		return array_values( array_map( fn( $l ) => $l[ $f ], WPStub::$flags['pll'] ?? [] ) );
	}
	function pll_default_language( string $field = 'slug' ): string { return WPStub::$flags['pll_default'] ?? ''; }
	function pll_home_url( string $slug ): string { return WPStub::$flags['pll_home'][ $slug ] ?? WPStub::$home . '/' . $slug . '/'; }
	function pll_get_post_language( int $id, string $field = 'slug' ): string|false { return WPStub::$flags['pll_post'][ $id ] ?? false; }
	function weglot_get_original_language(): string { return WPStub::$flags['weglot_orig'] ?? 'de'; }
	function weglot_get_destination_languages(): array { return WPStub::$flags['weglot_dest'] ?? []; }
}

final class LanguageTest extends TestCase {

	protected function setUp(): void {
		WPStub::reset();
	}

	public function test_code_normalisation_and_primary_subtag(): void {
		self::assertSame( 'de-de', Language::normalize( ' De_DE ' ) );
		self::assertSame( 'pt', Language::primary( 'pt-BR' ) );
		self::assertSame( 'de', Language::from_locale( 'de_CH_informal' ) );
		self::assertTrue( Language::same( 'de', 'de-ch' ) );
		self::assertTrue( Language::same( 'PT_BR', 'pt' ) );
		self::assertFalse( Language::same( 'de', 'en' ) );
		self::assertFalse( Language::same( '', '' ), 'empty is never the same language' );
	}

	public function test_core_site_has_exactly_the_site_locale(): void {
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'ro_RO';
		$langs = Language::site_languages();
		self::assertSame( [ 'ro' ], array_keys( $langs ) );
		self::assertTrue( $langs['ro']['default'] );
		self::assertSame( 'Română', $langs['ro']['name'] );
		self::assertSame( 'https://example.com/', $langs['ro']['home'] );
		self::assertSame( 'ro', Language::of_url( 'https://example.com/whatever/' ) );
	}

	public function test_wpml_languages_come_from_the_filters_with_their_home_urls(): void {
		Language::$force_provider = Language::WPML;
		WPStub::$filter_values['wpml_active_languages'] = [
			'de' => [ 'code' => 'de', 'native_name' => 'Deutsch', 'default_locale' => 'de_DE', 'url' => 'https://example.com/' ],
			'en' => [ 'code' => 'en', 'native_name' => 'English', 'default_locale' => 'en_GB', 'url' => 'https://example.com/en/' ],
			'fr' => [ 'code' => 'fr', 'native_name' => 'Français', 'default_locale' => 'fr_FR', 'url' => 'https://fr.example.com/' ],
		];
		WPStub::$filter_values['wpml_default_language'] = 'de';
		$langs = Language::site_languages();
		self::assertSame( [ 'de', 'en', 'fr' ], array_keys( $langs ) );
		self::assertTrue( $langs['de']['default'] );
		self::assertSame( 'de', Language::default_language() );
		// Subdirectory and subdomain schemes both resolve from the URL alone.
		self::assertSame( 'en', Language::of_url( 'https://example.com/en/services/' ) );
		self::assertSame( 'fr', Language::of_url( 'https://fr.example.com/services/' ) );
		self::assertSame( 'de', Language::of_url( 'https://example.com/services/' ) );
		self::assertSame( 'de', Language::of_url( 'https://example.com/english-garden/' ), 'prefix match only at a path boundary' );
		self::assertSame( [ 'example.com', 'fr.example.com' ], Language::hosts() );
	}

	public function test_wpml_post_language_wins_over_the_url_shape(): void {
		Language::$force_provider = Language::WPML;
		WPStub::$filter_values['wpml_active_languages'] = [
			'de' => [ 'code' => 'de', 'native_name' => 'Deutsch', 'url' => 'https://example.com/' ],
			'en' => [ 'code' => 'en', 'native_name' => 'English', 'url' => 'https://example.com/?lang=en' ],
		];
		WPStub::$filter_values['wpml_default_language'] = 'de';
		WPStub::$post_ids['https://example.com/?lang=en&p=7'] = 7;
		WPStub::$filter_values['wpml_post_language_details'] = static fn( $v, $id ) => 7 === $id ? [ 'language_code' => 'en' ] : null;
		self::assertSame( 'en', Language::of_url( 'https://example.com/?lang=en&p=7' ), 'parameter scheme: only the post knows' );
	}

	public function test_polylang_languages_and_post_language(): void {
		Language::$force_provider = Language::POLYLANG;
		WPStub::$flags['pll'] = [
			[ 'slug' => 'de', 'name' => 'Deutsch', 'locale' => 'de_DE' ],
			[ 'slug' => 'en', 'name' => 'English', 'locale' => 'en_US' ],
		];
		WPStub::$flags['pll_default'] = 'de';
		WPStub::$flags['pll_home']    = [ 'de' => 'https://example.com/', 'en' => 'https://example.com/en/' ];
		WPStub::$flags['pll_post']    = [ 42 => 'en' ];
		WPStub::$post_ids['https://example.com/en/about/'] = 42;
		$langs = Language::site_languages();
		self::assertSame( [ 'de', 'en' ], array_keys( $langs ) );
		self::assertSame( 'en_US', $langs['en']['locale'] );
		self::assertSame( 'en', Language::of_post( 42 ) );
		self::assertSame( 'en', Language::of_url( 'https://example.com/en/about/' ) );
		self::assertNull( Language::of_post( 99 ) );
	}

	public function test_translatepress_reads_its_settings_option(): void {
		Language::$force_provider = Language::TRANSLATEPRESS;
		WPStub::$options['trp_settings'] = [
			'default-language'  => 'de_DE',
			'publish-languages' => [ 'de_DE', 'en_GB' ],
			'url-slugs'         => [ 'de_DE' => 'de', 'en_GB' => 'en' ],
		];
		$langs = Language::site_languages();
		self::assertSame( [ 'de', 'en' ], array_keys( $langs ) );
		self::assertSame( 'https://example.com/en/', $langs['en']['home'] );
		self::assertTrue( $langs['de']['default'] );
		self::assertSame( 'en', Language::of_url( 'https://example.com/en/kontakt/' ) );
		self::assertNull( Language::of_post( 1 ), 'TranslatePress has no post-level language' );
	}

	public function test_weglot_original_plus_destinations(): void {
		Language::$force_provider = Language::WEGLOT;
		WPStub::$flags['weglot_orig'] = 'de';
		WPStub::$flags['weglot_dest'] = [ [ 'language_to' => 'en' ], 'fr' ];
		$langs = Language::site_languages();
		self::assertSame( [ 'de', 'en', 'fr' ], array_keys( $langs ) );
		self::assertTrue( $langs['de']['default'] );
		self::assertSame( 'https://example.com/fr/', $langs['fr']['home'] );
	}

	public function test_filters_can_add_languages_and_override_a_urls_language(): void {
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
		WPStub::$filter_values['aivis_connector_site_languages'] = static function ( array $l ): array {
			$l['en'] = [ 'code' => 'EN', 'name' => 'English', 'home' => 'https://example.com/en/' ];
			return $l;
		};
		self::assertSame( [ 'de', 'en' ], array_keys( Language::site_languages() ) );
		WPStub::$filter_values['aivis_connector_url_language'] = static fn( string $code, string $url ): string => str_contains( $url, 'special' ) ? 'en' : $code;
		self::assertSame( 'en', Language::of_url( 'https://example.com/special/' ) );
		WPStub::$filter_values['aivis_connector_url_language'] = 'xx';
		self::assertSame( 'de', Language::of_url( 'https://example.com/other/' ), 'an unknown code falls back to the default language' );
	}
}
