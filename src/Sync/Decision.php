<?php
/**
 * §06 — the retraction decision rules. Pure functions; the PHP port of
 * src/reference/connector-logic.mjs, held to the same behaviour by tests.
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Api\Response;
use AivisOS\Domain\Action;

final class Decision {

	/** `captureStatus` values the contract names; anything else means hold (§03 open enums). */
	public const KNOWN_CAPTURE = [ 'draft', 'processing', 'processed', 'failed' ];

	/**
	 * R-01 / R-01a / R-01b — what a single refresh attempt does to a stored row.
	 * `$api_reachable` must be confirmed within the same run; without it a 404
	 * proves nothing. A 410 `withdrawn` needs no such proof: only AIVIS emits
	 * that code, and it is the explicit retraction signal (API-2).
	 *
	 * @return array{action:string, rule:?string, kind:string, needs_confirmation:bool}
	 */
	public static function from_lookup( Response $r, bool $api_reachable ): array {
		$kind = $r->kind();
		return match ( $kind ) {
			'ok'             => self::d( Action::SERVE, null, $kind ),
			// The Url row is gone. Cascade delete is how a deletion looks.
			'url_gone'       => $api_reachable
				? self::d( Action::SUSPEND, 'R-01', $kind )
				: self::d( Action::HOLD, 'R-01', $kind ),
			// The page still exists; only the artifact is missing. Never deactivate.
			'not_generated'  => self::d( Action::HOLD, 'R-01a', $kind ),
			// Unpublished in AIVIS: take the block down now, keep the row, keep polling.
			'withdrawn'      => self::d( Action::DEACTIVATE, 'R-01b', $kind ),
			// A signal we cannot read is not evidence. Keep serving, confirm later.
			'unreadable'     => self::d( Action::HOLD, 'R-01a', $kind, true ),
			default          => self::d( Action::HOLD, null, $kind ),
		};
	}

	/**
	 * R-02 / R-02a — from an authoritative inventory pass. A partial traversal
	 * never retires anything; a row it did see is still authoritative for
	 * itself (the change feed, API-6, delivers unpublish this way).
	 *
	 * @param array<string,mixed>|null $row        Inventory row (null = absent).
	 * @param bool                     $chain_idle The row's chain is `ready` or `empty` — not building or
	 *        re-ingesting. On the first authoritative absence an idle chain's URL is suspended at once
	 *        (injection stops, cache purged) and retired on the second; a rebuilding chain's URL is held
	 *        through the first absence, because rows can be transiently missing while a pipeline runs.
	 * @return array{action:string, rule:?string, missing_runs:int}
	 */
	public static function from_inventory( bool $authoritative, ?array $row, int $missing_runs, bool $chain_idle = false ): array {
		if ( ! $authoritative ) {
			return [
				'action'       => Action::HOLD,
				'rule'         => null,
				'missing_runs' => $missing_runs,
			];
		}
		if ( null === $row ) {
			$runs = $missing_runs + 1;
			return [
				'action'       => $runs >= 2 ? Action::RETIRE : ( $chain_idle ? Action::SUSPEND : Action::HOLD ),
				'rule'         => 'R-02',
				'missing_runs' => $runs,
			];
		}
		$ready = ! empty( $row['jsonLd']['ready'] );
		if ( $ready ) {
			return [
				'action'       => Action::SERVE,
				'rule'         => null,
				'missing_runs' => 0,
			];
		}
		// Unpublished in AIVIS (suppressedAt, contract ≥ 1.5.0): down, whatever the capture is doing.
		if ( ! empty( $row['jsonLd']['suppressedAt'] ) ) {
			return [
				'action'       => Action::DEACTIVATE,
				'rule'         => 'R-02a',
				'missing_runs' => 0,
			];
		}
		// ready:false — regeneration in flight is not a withdrawal, and neither is
		// a captureStatus this connector does not know (§03: unknown means hold).
		$capture = $row['captureStatus'] ?? null;
		if ( 'processing' === $capture || ( null !== $capture && ! in_array( (string) $capture, self::KNOWN_CAPTURE, true ) ) ) {
			return [
				'action'       => Action::HOLD,
				'rule'         => 'R-02a',
				'missing_runs' => 0,
			];
		}
		return [
			'action'       => Action::DEACTIVATE,
			'rule'         => 'R-02a',
			'missing_runs' => 0,
		];
	}

	/** @return array{action:string, rule:?string, kind:string, needs_confirmation:bool} */
	private static function d( string $action, ?string $rule, string $kind, bool $confirm = false ): array {
		return [
			'action'             => $action,
			'rule'               => $rule,
			'kind'               => $kind,
			'needs_confirmation' => $confirm,
		];
	}
}
