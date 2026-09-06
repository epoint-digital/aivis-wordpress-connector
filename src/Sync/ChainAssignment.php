<?php
/**
 * §07a — chains and the languages they serve.
 *
 * AIVIS has no multilingual model: a chain is one language, because intents
 * and forensic prompts are bound per language. The connector therefore treats
 * "which chain serves which WordPress language" as configuration the admin
 * owns, with two helpers around it: a cached chain catalogue, and a per-chain
 * language *hint* sampled from inventory — the API exposes `languageCode` per
 * URL only (API-10 asks for it on the chain). Assignment is automatic only
 * where there is nothing to decide.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Api\Client;
use AivisOS\Delivery\Language;
use AivisOS\Storage\Options;

final class ChainAssignment {

	public const CATALOG_TTL = 5 * MINUTE_IN_SECONDS;
	public const HINT_TTL    = 15 * MINUTE_IN_SECONDS;
	/** Rows sampled per chain for the language hint. */
	public const HINT_SAMPLE = 5;

	public function __construct(
		private readonly Client $client,
		private readonly Options $options
	) {}

	/**
	 * The bound business's chains, briefly cached. Null when the API could
	 * not be reached (a failed walk is never cached).
	 *
	 * @return list<array{id:string,name:string,state:string,kg:bool,step:int,urlCount:int}>|null
	 */
	public function catalog( bool $fresh = false ): ?array {
		$biz = $this->options->business()['business_id'];
		if ( '' === $biz ) {
			return [];
		}
		$key = 'aivis_os_chains_' . md5( $biz );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$out    = [];
		$cursor = null;
		do {
			$r = $this->client->chains( $biz, $cursor );
			if ( ! $r->ok() ) {
				return null;
			}
			foreach ( (array) ( $r->body['items'] ?? [] ) as $c ) {
				if ( empty( $c['id'] ) ) {
					continue;
				}
				$out[] = [
					'id'       => (string) $c['id'],
					'name'     => (string) ( $c['name'] ?? '' ),
					'state'    => (string) ( $c['state'] ?? '' ),
					'kg'       => ! empty( $c['knowledgeGraphReady'] ),
					'step'     => (int) ( $c['currentStep'] ?? 0 ),
					'urlCount' => (int) ( $c['urlCount'] ?? 0 ),
				];
			}
			$cursor = ( ! empty( $r->body['hasMore'] ) && is_string( $r->body['nextCursor'] ?? null ) ) ? $r->body['nextCursor'] : null;
		} while ( null !== $cursor );
		set_transient( $key, $out, self::CATALOG_TTL );
		return $out;
	}

	/**
	 * What AIVIS says the chain's language is, sampled from its first rows.
	 * Until API-10 this is the only signal. `mixed` means the sample disagreed
	 * with itself, which the admin should know before assigning.
	 *
	 * @return array{language:?string, mixed:bool, sampled:int}
	 */
	public function hint( string $chain_id, bool $fresh = false ): array {
		$key = 'aivis_os_chain_lang_' . md5( $chain_id );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$r = $this->client->get( '/chains/' . rawurlencode( $chain_id ) . '/urls', [ 'limit' => self::HINT_SAMPLE ] );
		if ( ! $r->ok() ) {
			return [ 'language' => null, 'mixed' => false, 'sampled' => 0 ];
		}
		$seen = [];
		foreach ( (array) ( $r->body['items'] ?? [] ) as $row ) {
			$c = Language::normalize( (string) ( $row['languageCode'] ?? '' ) );
			if ( '' !== $c ) {
				$seen[ Language::primary( $c ) ] = $c;
			}
		}
		$hint = [
			'language' => 1 === count( $seen ) ? (string) reset( $seen ) : ( $seen ? (string) reset( $seen ) : null ),
			'mixed'    => count( $seen ) > 1,
			'sampled'  => count( (array) ( $r->body['items'] ?? [] ) ),
		];
		set_transient( $key, $hint, self::HINT_TTL );
		return $hint;
	}

	/**
	 * Assign chains where there is nothing to decide, leave the rest to the
	 * admin. Rules, in order:
	 *  - a chain already assigned stays as it is;
	 *  - one site language: every chain whose hint is unknown or the same
	 *    language is assigned to it;
	 *  - several site languages: a chain is assigned only when its hint names
	 *    exactly one of them.
	 * A chain whose hint contradicts the only site language is left alone and
	 * reported, never guessed.
	 *
	 * @return array{map:array<string,string>, assigned:list<string>, unresolved:list<string>, catalog_ok:bool}
	 */
	public function auto_assign( bool $persist = true ): array {
		$catalog = $this->catalog();
		if ( null === $catalog ) {
			return [ 'map' => $this->options->chain_languages(), 'assigned' => [], 'unresolved' => [], 'catalog_ok' => false ];
		}
		$langs      = Language::site_languages();
		$map        = $this->options->chain_languages();
		$known_ids  = array_map( static fn( array $c ): string => $c['id'], $catalog );
		// Assignments for chains that no longer exist are dropped.
		$map        = array_intersect_key( $map, array_flip( $known_ids ) );
		$assigned   = [];
		$unresolved = [];
		foreach ( $catalog as $c ) {
			if ( isset( $map[ $c['id'] ] ) ) {
				continue;
			}
			$hint   = $this->hint( $c['id'] );
			$target = null;
			if ( 1 === count( $langs ) ) {
				$only = (string) array_key_first( $langs );
				if ( null === $hint['language'] || ( ! $hint['mixed'] && Language::same( $hint['language'], $only ) ) ) {
					$target = $only;
				}
			} elseif ( null !== $hint['language'] && ! $hint['mixed'] ) {
				$matches = array_values( array_filter( array_keys( $langs ), static fn( string $code ): bool => Language::same( $hint['language'], $code ) ) );
				if ( 1 === count( $matches ) ) {
					$target = (string) $matches[0];
				}
			}
			if ( null !== $target ) {
				$map[ $c['id'] ] = $target;
				$assigned[]      = $c['id'];
			} else {
				$unresolved[] = $c['id'];
			}
		}
		if ( $persist && ( $assigned || $map !== $this->options->chain_languages() ) ) {
			$this->options->set_chain_languages( $map );
		}
		return [ 'map' => $map, 'assigned' => $assigned, 'unresolved' => $unresolved, 'catalog_ok' => true ];
	}

	/**
	 * The admin-facing summary: every site language with its chains, and
	 * which languages have none.
	 *
	 * @return array{languages:array<string,array{code:string,name:string,default:bool,chains:list<string>}>, unassigned_chains:list<string>, missing:list<string>}
	 */
	public function summary( ?array $catalog = null ): array {
		$langs = Language::site_languages();
		$map   = $this->options->chain_languages();
		$out   = [];
		foreach ( $langs as $code => $l ) {
			$out[ $code ] = [
				'code'    => $code,
				'name'    => $l['name'],
				'default' => $l['default'],
				'chains'  => $this->options->chains_for_language( $code ),
			];
		}
		$catalog_ids = null === $catalog ? array_keys( $map ) : array_map( static fn( array $c ): string => $c['id'], $catalog );
		$unassigned  = array_values( array_filter( $catalog_ids, static fn( string $id ): bool => ! isset( $map[ $id ] ) ) );
		$missing     = array_keys( array_filter( $out, static fn( array $l ): bool => [] === $l['chains'] ) );
		return [ 'languages' => $out, 'unassigned_chains' => $unassigned, 'missing' => array_values( $missing ) ];
	}
}
