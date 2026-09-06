<?php
/**
 * ZT-03 — bind an artifact to this site before storing it.
 *
 * businessId always; chainId when inventory context exists; host allowlist;
 * requested vs returned URL. The business check is not ceremony: /jsonld?url=
 * gathers candidates across every business on the account and returns the
 * freshest, and Business.baseUrl is not unique.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Security;

use AivisOS\Domain\ErrorCode;
use AivisOS\Domain\UrlKey;

final class Binding {

	/**
	 * @param array<string,mixed> $env            Validated envelope.
	 * @param list<string>        $allowed_hosts  Lower-cased.
	 * @param list<string>|null   $known_chain_ids Null when no inventory context.
	 * @return array{ok:bool, errors:list<string>}
	 */
	public static function check(
		array $env,
		string $business_id,
		array $allowed_hosts,
		?array $known_chain_ids = null,
		?string $requested_url = null
	): array {
		$errors = [];
		if ( (string) $env['businessId'] !== $business_id ) {
			$errors[] = ErrorCode::SCOPE_MISMATCH . ": businessId {$env['businessId']} != {$business_id}";
		}
		if ( null !== $known_chain_ids && ! in_array( (string) $env['chainId'], $known_chain_ids, true ) ) {
			$errors[] = ErrorCode::SCOPE_MISMATCH . ": chainId {$env['chainId']} not in the selected business";
		}
		$host = wp_parse_url( (string) $env['url'], PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$errors[] = ErrorCode::SCHEMA_INVALID . ': url unparseable';
		} elseif ( ! in_array( strtolower( $host ), array_map( 'strtolower', $allowed_hosts ), true ) ) {
			$errors[] = ErrorCode::SCOPE_MISMATCH . ": host {$host} not allowed";
		}
		if ( null !== $requested_url && ! in_array( (string) $env['url'], UrlKey::slash_aliases( $requested_url ), true ) ) {
			$errors[] = ErrorCode::SCOPE_MISMATCH . ': returned url is not the requested url or its slash alias';
		}
		if ( '' === trim( (string) $env['urlId'] ) || '' === trim( (string) $env['chainId'] ) ) {
			$errors[] = ErrorCode::SCHEMA_INVALID . ': urlId/chainId empty';
		}
		return [
			'ok'     => [] === $errors,
			'errors' => $errors,
		];
	}
}
