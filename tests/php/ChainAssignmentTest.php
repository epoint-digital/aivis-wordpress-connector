<?php
declare( strict_types=1 );

use AivisOS\Api\Client;
use AivisOS\Delivery\Language;
use AivisOS\Storage\Options;
use AivisOS\Sync\ChainAssignment;
use PHPUnit\Framework\TestCase;

final class ChainAssignmentTest extends TestCase {

	private Options $o;
	private ChainAssignment $a;

	protected function setUp(): void {
		WPStub::reset();
		WPStub::$options['aivis_os_token'] = 'aivis_' . str_repeat( 'x', 40 );
		$this->o = new Options();
		$this->o->set_business( 'biz_live', 'Example', 'https://example.com', [] );
		$this->a = new ChainAssignment( new Client( $this->o ), $this->o );
		Language::$force_provider = Language::CORE;
		WPStub::$locale = 'de_DE';
	}

	/**
	 * Chain catalogue with the language fields of contract ≥ 1.4.0 (API-10).
	 * Value per id: a code (languageCode), '' (nothing reported: no rows and
	 * no single declared language), or a list of codes (mixed: languageCode
	 * null, urlLanguageCodes = the list).
	 */
	private static function chains( array $spec ): array {
		$items = [];
		foreach ( $spec as $id => $lang ) {
			$items[] = [
				'id' => $id, 'name' => strtoupper( $id ), 'state' => 'ready', 'currentStep' => 9, 'knowledgeGraphReady' => true, 'urlCount' => 3,
				'languageCode'          => is_string( $lang ) && '' !== $lang ? $lang : null,
				'urlLanguageCodes'      => is_array( $lang ) ? $lang : ( '' === $lang ? [] : [ $lang ] ),
				'declaredLanguageCodes' => is_array( $lang ) ? $lang : ( '' === $lang ? [] : [ $lang ] ),
			];
		}
		return [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => count( $items ) ];
	}

	private static function rows( string $lang, int $n = 2 ): array {
		$items = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$items[] = [ 'id' => "u{$i}", 'url' => "https://example.com/p{$i}/", 'languageCode' => $lang, 'layer' => 'editorial', 'captureStatus' => 'processed', 'jsonLd' => [ 'ready' => true, 'stale' => false, 'generatedAt' => '2026-09-01T00:00:00Z' ] ];
		}
		return [ 'items' => $items, 'nextCursor' => null, 'hasMore' => false, 'total' => $n ];
	}

	public function test_assignment_round_trip_is_normalised_and_bound_to_the_business(): void {
		$this->o->set_chain_languages( [ ' chain_a ' => 'De_DE', 'chain_b' => '', 'chain_c' => 'en' ] );
		self::assertSame( [ 'chain_a' => 'de-de', 'chain_c' => 'en' ], $this->o->chain_languages() );
		self::assertSame( [ 'chain_a' ], $this->o->chains_for_language( 'de' ), 'region-insensitive' );
		self::assertSame( 'en', $this->o->language_for_chain( 'chain_c' ) );
		self::assertNull( $this->o->language_for_chain( 'chain_b' ) );
		// Re-binding the same business keeps the map; another business starts over.
		$this->o->set_business( 'biz_live', 'Example', 'https://example.com', [ 'alias.example.com' ] );
		self::assertCount( 2, $this->o->chain_languages() );
		$this->o->set_business( 'biz_other', 'Other', 'https://example.com', [] );
		self::assertSame( [], $this->o->chain_languages() );
	}

	public function test_one_language_assigns_every_compatible_chain(): void {
		WPStub::queue( 200, self::chains( [ 'chain_core' => 'de', 'chain_edit' => '', 'chain_en' => 'en' ] ) ); // chain_en contradicts the only site language
		$r = $this->a->auto_assign();
		self::assertCount( 1, WPStub::$http_log, 'the chain catalogue carries the language: no sampling requests' );
		self::assertTrue( $r['catalog_ok'] );
		self::assertSame( [ 'chain_core' => 'de', 'chain_edit' => 'de' ], $r['map'] );
		self::assertSame( [ 'chain_en' ], $r['unresolved'], 'a chain AIVIS reports as another language is never guessed' );
		self::assertSame( [ 'chain_core' => 'de', 'chain_edit' => 'de' ], $this->o->chain_languages(), 'persisted' );
	}

	public function test_several_languages_assign_only_on_an_unambiguous_hint(): void {
		WPStub::$filter_values['aivis_connector_site_languages'] = static fn( array $l ): array => $l + [ 'en' => [ 'code' => 'en', 'name' => 'English', 'home' => 'https://example.com/en/' ] ];
		WPStub::queue( 200, self::chains( [ 'chain_core' => 'de', 'chain_en' => 'en-GB', 'chain_mixed' => [ 'de', 'en' ], 'chain_empty' => '' ] ) );
		$r = $this->a->auto_assign();
		self::assertSame( [ 'chain_core' => 'de', 'chain_en' => 'en' ], $r['map'] );
		self::assertSame( [ 'chain_mixed', 'chain_empty' ], $r['unresolved'] );
		$mixed = $this->a->hint( 'chain_mixed' );
		self::assertTrue( $mixed['mixed'] );
		self::assertSame( [ 'de', 'en' ], $mixed['languages'], 'the admin sees what the chain holds' );
		self::assertNull( $this->a->hint( 'chain_empty' )['language'] );
		self::assertNull( $this->a->hint( 'chain_unknown' )['language'], 'an unknown chain reports nothing' );
	}

	public function test_existing_assignments_are_kept_and_deleted_chains_dropped(): void {
		$this->o->set_chain_languages( [ 'chain_core' => 'de', 'chain_gone' => 'de' ] );
		WPStub::queue( 200, self::chains( [ 'chain_core' => 'de', 'chain_new' => 'de' ] ) );
		$r = $this->a->auto_assign();
		self::assertSame( [ 'chain_core' => 'de', 'chain_new' => 'de' ], $r['map'] );
		self::assertSame( [ 'chain_new' ], $r['assigned'] );
		self::assertCount( 1, WPStub::$http_log, 'the catalogue alone' );
	}

	public function test_unreachable_api_changes_nothing(): void {
		$this->o->set_chain_languages( [ 'chain_core' => 'de' ] );
		WPStub::queue_error( 'timeout' );
		$r = $this->a->auto_assign();
		self::assertFalse( $r['catalog_ok'] );
		self::assertSame( [ 'chain_core' => 'de' ], $this->o->chain_languages() );
		self::assertFalse( get_transient( 'aivis_os_chains_' . md5( 'biz_live' ) ), 'a failed walk is not cached' );
	}

	public function test_summary_names_languages_without_a_chain(): void {
		WPStub::$filter_values['aivis_connector_site_languages'] = static fn( array $l ): array => $l + [ 'fr' => [ 'code' => 'fr', 'name' => 'Français', 'home' => 'https://example.com/fr/' ] ];
		$this->o->set_chain_languages( [ 'chain_core' => 'de' ] );
		$s = $this->a->summary( [ [ 'id' => 'chain_core' ], [ 'id' => 'chain_x' ] ] );
		self::assertSame( [ 'fr' ], $s['missing'] );
		self::assertSame( [ 'chain_x' ], $s['unassigned_chains'] );
		self::assertSame( [ 'chain_core' ], $s['languages']['de']['chains'] );
	}
}
