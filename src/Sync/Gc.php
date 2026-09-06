<?php
/**
 * Daily: delete rows retired longer than the rollback window (§06 R-02).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

namespace AivisOS\Sync;

use AivisOS\Storage\Repository;

final class Gc {

	public const RETENTION_DAYS = 30;

	public function __construct( private readonly Repository $repository ) {}

	public function run(): int {
		return $this->repository->delete_retired_before( self::RETENTION_DAYS );
	}
}
