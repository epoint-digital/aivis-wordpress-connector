<?php
declare( strict_types=1 );

use AivisOS\Security\Serializer;
use PHPUnit\Framework\TestCase;

/**
 * ZT-05. The JS reference (tests/unit/serializer.mjs) fuzzes the same
 * contract; the exact-string assertions here pin PHP's json_encode output so
 * the two implementations cannot drift apart unnoticed.
 */
final class SerializerTest extends TestCase {

	private const NASTY = [
		'</script>', '</script >', '</SCRIPT>', '<script>alert(1)</script>', '<!--', '-->', '<![CDATA[', ']]>',
		'<svg onload=alert(1)>', '"', "'", '&', '&amp;', '&lt;script&gt;', '\\', '\\u003c', '\\\\', '', ' ',
		"\n", "\r\n", "\t", 'https://example.com/a/b?x=1&y=2', 'Grüße', '日本語', '🎉👍', 'O’Brien', '@context',
	];

	public function test_flag_parity_uppercase_hex(): void {
		// JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT — uppercase hex digits, as PHP emits them.
		$expected = '["\u003C","\u003E","\u0026","\u0027","\u0022"]';
		self::assertSame( $expected, Serializer::serialize( [ '<', '>', '&', "'", '"' ] ) );
	}

	public function test_slashes_and_unicode_unescaped(): void {
		$out = Serializer::serialize( [ 'u' => 'https://example.com/a/b', 't' => 'Grüße 日本 🎉' ] );
		self::assertStringContainsString( 'https://example.com/a/b', $out );
		self::assertStringContainsString( '日本', $out );
	}

	public function test_control_chars_lowercase_hex(): void {
		// The input carries a real U+001F; the output must carry the six ASCII characters backslash-u-0-0-1-f.
		$expected = '["a\u001fb"]';
		self::assertSame( $expected, Serializer::serialize( [ "a\x1fb" ] ) );
	}

	public function test_zero_fraction_preserved(): void {
		self::assertSame( '[1.0]', Serializer::serialize( [ 1.0 ] ) );
	}

	public function test_known_breakouts_are_neutralised_and_round_trip(): void {
		foreach ( self::NASTY as $s ) {
			$out = Serializer::serialize( [ 'v' => $s, $s => 1 ] );
			foreach ( Serializer::FORBIDDEN as $c ) {
				self::assertStringNotContainsString( $c, $out, json_encode( $s ) );
			}
			$back = json_decode( $out, true );
			self::assertSame( $s, $back['v'] );
			self::assertArrayHasKey( $s, $back );
		}
	}

	public function test_script_tag_has_exactly_one_element_and_the_marker(): void {
		$tag = Serializer::script_tag( Serializer::serialize( [ 'a' => '</script><script>alert(1)</script>' ] ) );
		self::assertSame( 1, preg_match_all( '#</script>#i', $tag ) );
		self::assertSame( 1, preg_match_all( '#<script#i', $tag ) );
		self::assertStringContainsString( 'data-aivis="1"', $tag );
	}

	public function test_non_finite_refused(): void {
		$this->expectException( \Throwable::class );
		Serializer::serialize( [ NAN ] );
	}

	public function test_fuzz_safety_and_fidelity(): void {
		mt_srand( 0x5EED1 );
		$runs = (int) ( getenv( 'FUZZ_RUNS' ) ?: 2000 );
		for ( $i = 0; $i < $runs; $i++ ) {
			$v   = $this->random_value();
			$out = Serializer::serialize( $v );
			foreach ( Serializer::FORBIDDEN as $c ) {
				self::assertStringNotContainsString( $c, $out, "case {$i}" );
			}
			$back = json_decode( $out, true, 64 );
			self::assertSame( JSON_ERROR_NONE, json_last_error(), "case {$i}" );
			self::assertSame( $v, $back, "case {$i} round-trip" );
		}
	}

	private function random_string(): string {
		if ( mt_rand( 0, 99 ) < 55 ) {
			return self::NASTY[ mt_rand( 0, count( self::NASTY ) - 1 ) ];
		}
		$s = '';
		$n = mt_rand( 0, 20 );
		for ( $i = 0; $i < $n; $i++ ) {
			$cp = mt_rand( 0, 1 ) ? mt_rand( 32, 126 ) : mt_rand( 0x80, 0x1FFF );
			$s .= mb_chr( $cp, 'UTF-8' );
		}
		return $s;
	}

	/** Values PHP arrays round-trip losslessly: strings, ints, bools, null, nested arrays. */
	private function random_value( int $depth = 0 ): array {
		$n     = mt_rand( 0, 4 );
		$out   = [];
		$assoc = 1 === mt_rand( 0, 1 );
		for ( $i = 0; $i < $n; $i++ ) {
			$leaf = $depth > 3 || mt_rand( 0, 99 ) < 60;
			$val  = $leaf
				? match ( mt_rand( 0, 3 ) ) { 0 => $this->random_string(), 1 => mt_rand( -500, 500 ), 2 => (bool) mt_rand( 0, 1 ), default => null }
				: $this->random_value( $depth + 1 );
			if ( $assoc ) {
				$k = $this->random_string();
				// PHP coerces numeric-string keys to ints and json re-reads them as strings; avoid that noise.
				if ( '' === $k || is_numeric( $k ) ) {
					$k = 'k' . $i;
				}
				$out[ $k ] = $val;
			} else {
				$out[] = $val;
			}
		}
		return $out;
	}
}
