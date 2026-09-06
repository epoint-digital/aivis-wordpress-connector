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

	/**
	 * R-01 / R-01a — what a single refresh attempt does to a stored row.
	 * `$api_reachable` must be confirmed within the same run; without it a 404
	 * proves nothing.
	 *
	 * @return array{action:string, rule:?string, kind:string, needs_confirmation:bool}
	 */
	public static function from_lookup( Response $r, bool $api_reachable ): array {
		$kind = $r->kind();
		return match ( $kind ) {
			'ok'            => self::d( Action::SERVE, null, $kind ),
			// The Url row is gone. Cascade delete is how a retraction looks today.
			'url_gone'      => $api_reachable
				? self::d( Action::SUSPEND, 'R-01', $kind )
				: self::d( Action::HOLD, 'R-01', $kind ),
			// The page still exists; only the artifact is missing. Never deactivate.
			'not_generated' => self::d( Action::HOLD, 'R-01a', $kind ),
			// A signal we cannot read is not evidence. Keep serving, confirm later.
			'unreadable'    => self::d( Action::HOLD, 'R-01a', $kind, true ),
			default         => self::d( Action::HOLD, null, $kind ),
		};
	}

	/**
	 * R-02 / R-02a — from an authoritative inventory pass. A partial traversal
	 * never retires or deactivates anything.
	 *
	 * @param array<string,mixed>|null $row Inventory row (null = absent).
	 * @return array{action:string, rule:?string, missing_runs:int}
	 */
	public static function from_inventory( bool $authoritative, ?array $row, int $missing_runs ): array {
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
				'action'       => $runs >= 2 ? Action::RETIRE : Action::HOLD,
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
		// ready:false — regeneration in flight is not a withdrawal.
		if ( ( $row['captureStatus'] ?? null ) === 'processing' ) {
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
