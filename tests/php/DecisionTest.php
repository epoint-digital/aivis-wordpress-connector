<?php
declare( strict_types=1 );

use AivisOS\Api\Response;
use AivisOS\Domain\Action;
use AivisOS\Sync\Decision;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase {

	private static function r( int $status, ?string $msg = null ): Response {
		return new Response( $status, null === $msg ? null : [ 'error' => [ 'message' => $msg ] ] );
	}

	public function test_200_serves(): void {
		self::assertSame( Action::SERVE, Decision::from_lookup( new Response( 200, [] ), true )['action'] );
	}

	public function test_url_gone_reachable_suspends_r01(): void {
		$d = Decision::from_lookup( self::r( 404, Response::NOT_FOUND ), true );
		self::assertSame( Action::SUSPEND, $d['action'] );
		self::assertSame( 'R-01', $d['rule'] );
	}

	public function test_url_gone_unreachable_holds(): void {
		self::assertSame( Action::HOLD, Decision::from_lookup( self::r( 404, Response::NOT_FOUND ), false )['action'] );
	}

	public function test_not_generated_never_deactivates(): void {
		foreach ( [ true, false ] as $reach ) {
			$d = Decision::from_lookup( self::r( 404, Response::NOT_GENERATED ), $reach );
			self::assertSame( Action::HOLD, $d['action'] );
			self::assertSame( 'R-01a', $d['rule'] );
		}
	}

	public function test_reworded_message_degrades_to_hold_with_confirmation(): void {
		$d = Decision::from_lookup( self::r( 404, Response::NOT_FOUND . '.' ), true );
		self::assertSame( Action::HOLD, $d['action'] );
		self::assertTrue( $d['needs_confirmation'] );
	}

	public function test_no_non_200_branch_serves_or_retires(): void {
		foreach ( [ 400, 401, 403, 404, 429, 500, 0 ] as $s ) {
			foreach ( [ true, false ] as $reach ) {
				$a = Decision::from_lookup( self::r( $s, Response::NOT_FOUND ), $reach )['action'];
				self::assertNotContains( $a, [ Action::SERVE, Action::RETIRE ], "status {$s}" );
			}
		}
	}

	public function test_inventory_ready_serves_and_resets_counter(): void {
		$d = Decision::from_inventory( true, [ 'jsonLd' => [ 'ready' => true ], 'captureStatus' => 'processed' ], 1 );
		self::assertSame( Action::SERVE, $d['action'] );
		self::assertSame( 0, $d['missing_runs'] );
	}

	public function test_inventory_unready_processing_holds(): void {
		$d = Decision::from_inventory( true, [ 'jsonLd' => [ 'ready' => false ], 'captureStatus' => 'processing' ], 0 );
		self::assertSame( Action::HOLD, $d['action'] );
		self::assertSame( 'R-02a', $d['rule'] );
	}

	public function test_inventory_unready_processed_deactivates(): void {
		$d = Decision::from_inventory( true, [ 'jsonLd' => [ 'ready' => false ], 'captureStatus' => 'processed' ], 0 );
		self::assertSame( Action::DEACTIVATE, $d['action'] );
	}

	public function test_absent_once_holds_twice_retires(): void {
		$a = Decision::from_inventory( true, null, 0 );
		$b = Decision::from_inventory( true, null, $a['missing_runs'] );
		self::assertSame( Action::HOLD, $a['action'] );
		self::assertSame( 1, $a['missing_runs'] );
		self::assertSame( Action::RETIRE, $b['action'] );
	}

	public function test_reappearance_resets_counter(): void {
		$gone = Decision::from_inventory( true, null, 0 );
		$back = Decision::from_inventory( true, [ 'jsonLd' => [ 'ready' => true ] ], $gone['missing_runs'] );
		self::assertSame( 0, $back['missing_runs'] );
		self::assertSame( Action::HOLD, Decision::from_inventory( true, null, $back['missing_runs'] )['action'] );
	}

	public function test_partial_traversal_never_retires(): void {
		self::assertSame( Action::HOLD, Decision::from_inventory( false, null, 99 )['action'] );
		self::assertSame( Action::HOLD, Decision::from_inventory( false, [ 'jsonLd' => [ 'ready' => false ], 'captureStatus' => 'processed' ], 0 )['action'] );
	}
}
