<?php
/**
 * §07a — which languages this site has, and which language a page is in.
 *
 * WordPress core has one locale per site and no multilingual content model
 * (Gutenberg phase 4 is 2027+). Languages come from a plugin, each with its own
 * API and URL scheme. This class asks the one that is active — WPML, Polylang,
 * TranslatePress, Weglot — and falls back to the site locale. Everything here
 * must be callable from cron, where there is no request and no "current"
 * language: the sync resolves a URL's language from the post or the URL itself.
 *
 * Codes are normalised to lower-case BCP-47-ish strings as the provider gives
 * them (`de`, `en`, `pt-br`); a bare locale (`de_DE`) becomes its primary
 * subtag. Matching against AIVIS's `languageCode` compares primary subtags.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Delivery;

final class Language {

	public const WPML          = 'wpml';
	public const POLYLANG      = 'polylang';
	public const TRANSLATEPRESS = 'translatepress';
	public const WEGLOT        = 'weglot';
	public const CORE          = 'core';

	/** Test seam: force a provider. Null = detect. */
	public static ?string $force_provider = null;

	/** Which system manages languages on this site. */
	public static function provider(): string {
		if ( null !== self::$force_provider ) {
			return self::$force_provider;
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return self::WPML;
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			return self::POLYLANG;
		}
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			return self::TRANSLATEPRESS;
		}
		if ( function_exists( 'weglot_get_original_language' ) ) {
			return self::WEGLOT;
		}
		return self::CORE;
	}

	public static function provider_label( string $provider ): string {
		return match ( $provider ) {
			self::WPML           => 'WPML',
			self::POLYLANG       => 'Polylang',
			self::TRANSLATEPRESS => 'TranslatePress',
			self::WEGLOT         => 'Weglot',
			default              => 'WordPress core (one language)',
		};
	}

	/**
	 * Every language the site publishes in.
	 *
	 * @return array<string,array{code:string,name:string,locale:string,home:string,default:bool}> keyed by code
	 */
	public static function site_languages(): array {
		$list = match ( self::provider() ) {
			self::WPML           => self::wpml_languages(),
			self::POLYLANG       => self::polylang_languages(),
			self::TRANSLATEPRESS => self::translatepress_languages(),
			self::WEGLOT         => self::weglot_languages(),
			default              => [],
		};
		if ( ! $list ) {
			$locale = self::site_locale();
			$code   = self::from_locale( $locale );
			$list   = [
				$code => [
					'code'    => $code,
					'name'    => self::native_name( $code, $locale ),
					'locale'  => $locale,
					'home'    => home_url( '/' ),
					'default' => true,
				],
			];
		}
		/**
		 * Filter the languages the connector assigns chains to.
		 *
		 * @param array<string,array{code:string,name:string,locale:string,home:string,default:bool}> $languages
		 * @param string $provider wpml|polylang|translatepress|weglot|core
		 */
		$list = (array) apply_filters( 'aivis_connector_site_languages', $list, self::provider() );
		$out  = [];
		foreach ( $list as $l ) {
			$code = self::normalize( (string) ( $l['code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$out[ $code ] = [
				'code'    => $code,
				'name'    => (string) ( $l['name'] ?? $code ),
				'locale'  => (string) ( $l['locale'] ?? '' ),
				'home'    => (string) ( $l['home'] ?? '' ),
				'default' => ! empty( $l['default'] ),
			];
		}
		return $out;
	}

	public static function default_language(): string {
		foreach ( self::site_languages() as $code => $l ) {
			if ( $l['default'] ) {
				return $code;
			}
		}
		return (string) array_key_first( self::site_languages() );
	}

	/**
	 * The language a page is in, resolvable without a request (cron, CLI).
	 * Post-level language where the provider has one (WPML, Polylang);
	 * otherwise the language whose home URL is the longest prefix of the URL
	 * (subdirectory and subdomain schemes); otherwise the default language.
	 */
	public static function of_url( string $url ): string {
		$langs = self::site_languages();
		$code  = null;

		$post_id = (int) url_to_postid( $url );
		if ( $post_id > 0 ) {
			$code = self::of_post( $post_id );
		}
		if ( null === $code ) {
			$code = self::by_home_prefix( $url, $langs );
		}
		if ( null === $code ) {
			$code = self::default_language();
		}
		/**
		 * Filter the language the connector attributes to a URL.
		 *
		 * @param string $code Normalised language code.
		 * @param string $url  Absolute URL.
		 */
		$code = self::normalize( (string) apply_filters( 'aivis_connector_url_language', $code, $url ) );
		return isset( $langs[ $code ] ) ? $code : self::default_language();
	}

	/** Post-level language, when the provider tracks one. Null otherwise. */
	public static function of_post( int $post_id ): ?string {
		switch ( self::provider() ) {
			case self::WPML:
				$d = apply_filters( 'wpml_post_language_details', null, $post_id );
				$c = is_array( $d ) ? (string) ( $d['language_code'] ?? '' ) : '';
				return '' !== $c ? self::normalize( $c ) : null;
			case self::POLYLANG:
				if ( function_exists( 'pll_get_post_language' ) ) {
					$c = pll_get_post_language( $post_id, 'slug' );
					return is_string( $c ) && '' !== $c ? self::normalize( $c ) : null;
				}
				return null;
			default:
				// TranslatePress and Weglot translate the same post: the language
				// lives in the URL, not on the post.
				return null;
		}
	}

	/**
	 * Hosts that language home URLs use — subdomain schemes (`de.example.com`)
	 * must be allowed hosts, or every artifact for that language is rejected.
	 *
	 * @return list<string> lower-cased
	 */
	public static function hosts(): array {
		$hosts = [];
		foreach ( self::site_languages() as $l ) {
			$h = '' !== $l['home'] ? wp_parse_url( $l['home'], PHP_URL_HOST ) : null;
			if ( is_string( $h ) && '' !== $h ) {
				$hosts[] = strtolower( $h );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/* ── code helpers ────────────────────────────────────────────────── */

	/** `de_DE` → `de-de`, `PT-BR` → `pt-br`; whitespace trimmed. */
	public static function normalize( string $code ): string {
		return strtolower( str_replace( '_', '-', trim( $code ) ) );
	}

	/** `pt-br` → `pt`, `zh-hans` → `zh`. */
	public static function primary( string $code ): string {
		$n = self::normalize( $code );
		$p = strstr( $n, '-', true );
		return false === $p ? $n : $p;
	}

	/** A locale (`de_DE`, `de_CH_informal`) → its language code (`de`). */
	public static function from_locale( string $locale ): string {
		$p = self::primary( $locale );
		return '' !== $p ? $p : 'en';
	}

	/** Same language, ignoring region: `de` ~ `de-ch`, `pt-br` ~ `pt`. */
	public static function same( string $a, string $b ): bool {
		return '' !== self::primary( $a ) && self::primary( $a ) === self::primary( $b );
	}

	/** Best-effort display name for a code; the provider's native name wins when it has one. */
	public static function native_name( string $code, string $locale = '' ): string {
		$names = [
			'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano', 'es' => 'Español',
			'pt' => 'Português', 'nl' => 'Nederlands', 'ro' => 'Română', 'pl' => 'Polski', 'cs' => 'Čeština',
			'sk' => 'Slovenčina', 'hu' => 'Magyar', 'sv' => 'Svenska', 'da' => 'Dansk', 'nb' => 'Norsk bokmål',
			'fi' => 'Suomi', 'el' => 'Ελληνικά', 'tr' => 'Türkçe', 'ru' => 'Русский', 'uk' => 'Українська',
			'bg' => 'Български', 'hr' => 'Hrvatski', 'sl' => 'Slovenščina', 'ja' => '日本語', 'zh' => '中文',
			'ko' => '한국어', 'ar' => 'العربية', 'he' => 'עברית',
		];
		$p = self::primary( $code );
		if ( isset( $names[ $p ] ) ) {
			return $names[ $p ] . ( $p !== self::normalize( $code ) ? ' (' . self::normalize( $code ) . ')' : '' );
		}
		return '' !== $locale ? $locale : $code;
	}

	/* ── providers ───────────────────────────────────────────────────── */

	private static function site_locale(): string {
		$l = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return is_string( $l ) && '' !== $l ? $l : 'en_US';
	}

	/** @return array<string,array<string,mixed>> */
	private static function wpml_languages(): array {
		$active  = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		$default = (string) apply_filters( 'wpml_default_language', null );
		$out     = [];
		foreach ( is_array( $active ) ? $active : [] as $l ) {
			$code = (string) ( $l['code'] ?? $l['language_code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$out[ $code ] = [
				'code'    => $code,
				'name'    => (string) ( $l['native_name'] ?? $l['translated_name'] ?? $code ),
				'locale'  => (string) ( $l['default_locale'] ?? '' ),
				'home'    => (string) ( $l['url'] ?? '' ),
				'default' => '' !== $default ? $code === $default : ! empty( $l['active'] ),
			];
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private static function polylang_languages(): array {
		$slugs   = pll_languages_list( [ 'fields' => 'slug' ] );
		$names   = pll_languages_list( [ 'fields' => 'name' ] );
		$locales = pll_languages_list( [ 'fields' => 'locale' ] );
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$out     = [];
		foreach ( (array) $slugs as $i => $slug ) {
			$slug = (string) $slug;
			$out[ $slug ] = [
				'code'    => $slug,
				'name'    => (string) ( $names[ $i ] ?? $slug ),
				'locale'  => (string) ( $locales[ $i ] ?? '' ),
				'home'    => function_exists( 'pll_home_url' ) ? (string) pll_home_url( $slug ) : home_url( '/' ),
				'default' => $slug === $default,
			];
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private static function translatepress_languages(): array {
		$s       = (array) get_option( 'trp_settings', [] );
		$default = (string) ( $s['default-language'] ?? '' );
		$publish = (array) ( $s['publish-languages'] ?? [] );
		$slugs   = (array) ( $s['url-slugs'] ?? [] );
		$out     = [];
		foreach ( $publish as $locale ) {
			$locale = (string) $locale;
			$code   = self::from_locale( $locale );
			$slug   = (string) ( $slugs[ $locale ] ?? $code );
			$out[ $code ] = [
				'code'    => $code,
				'name'    => self::native_name( $code, $locale ),
				'locale'  => $locale,
				'home'    => $locale === $default ? home_url( '/' ) : home_url( '/' . trim( $slug, '/' ) . '/' ),
				'default' => $locale === $default,
			];
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private static function weglot_languages(): array {
		$orig = weglot_get_original_language();
		$dest = function_exists( 'weglot_get_destination_languages' ) ? weglot_get_destination_languages() : [];
		$code = static function ( mixed $v ): string {
			if ( is_object( $v ) ) {
				foreach ( [ 'getInternalCode', 'getIso639', 'getExternalCode' ] as $m ) {
					if ( method_exists( $v, $m ) ) {
						return (string) $v->$m();
					}
				}
				return '';
			}
			return (string) $v;
		};
		$out = [];
		$o   = $code( $orig );
		if ( '' !== $o ) {
			$out[ $o ] = [ 'code' => $o, 'name' => self::native_name( $o ), 'locale' => '', 'home' => home_url( '/' ), 'default' => true ];
		}
		foreach ( (array) $dest as $d ) {
			$c = $code( is_array( $d ) ? ( $d['language_to'] ?? '' ) : $d );
			if ( '' !== $c && ! isset( $out[ $c ] ) ) {
				$out[ $c ] = [ 'code' => $c, 'name' => self::native_name( $c ), 'locale' => '', 'home' => home_url( '/' . $c . '/' ), 'default' => false ];
			}
		}
		return $out;
	}

	/**
	 * @param array<string,array{home:string,default:bool}> $langs
	 */
	private static function by_home_prefix( string $url, array $langs ): ?string {
		$best     = null;
		$best_len = -1;
		$u        = strtolower( $url );
		foreach ( $langs as $code => $l ) {
			$home = strtolower( rtrim( $l['home'], '/' ) );
			if ( '' === $home ) {
				continue;
			}
			// A home is a prefix only at a path boundary: example.com/de must not
			// claim example.com/design/.
			if ( $u === $home || str_starts_with( $u, $home . '/' ) || str_starts_with( $u, $home . '?' ) ) {
				if ( strlen( $home ) > $best_len ) {
					$best     = (string) $code;
					$best_len = strlen( $home );
				}
			}
		}
		return $best;
	}
}
