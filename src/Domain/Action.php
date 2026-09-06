<?php
/**
 * Actions a refresh or inventory pass can produce (§06).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Domain;

final class Action {
	public const SERVE      = 'serve';      // store and inject
	public const HOLD       = 'hold';       // keep injecting last-known-good, re-check next run
	public const SUSPEND    = 'suspend';    // stop injecting, keep the row, confirm via inventory
	public const DEACTIVATE = 'deactivate'; // active = 0 (R-02a)
	public const RETIRE     = 'retire';     // deactivate + retired_at (R-01 confirmed, R-02)
}
