<?php
declare( strict_types=1 );

use AivisOS\Api\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase {

	public static function statuses(): array {
		return [
			'200'          => [ 200, null, 'ok' ],
			'400'          => [ 400, null, 'bad_request' ],
			'401'          => [ 401, null, 'auth' ],
			'403'          => [ 403, null, 'account' ],
			'429'          => [ 429, null, 'throttled' ],
			'500'          => [ 500, null, 'transport' ],
			'503'          => [ 503, null, 'transport' ],
			'0 network'    => [ 0, null, 'transport' ],
			'404 url gone' => [ 404, Response::NOT_FOUND, 'url_gone' ],
			'404 not gen'  => [ 404, Response::NOT_GENERATED, 'not_generated' ],
			'404 unknown'  => [ 404, 'Something else', 'unreadable' ],
			'404 no body'  => [ 404, null, 'unreadable' ],
		];
	}

	#[DataProvider( 'statuses' )]
	public function test_classification( int $status, ?string $msg, string $kind ): void {
		$r = new Response( $status, null === $msg ? null : [ 'error' => [ 'message' => $msg ] ] );
		self::assertSame( $kind, $r->kind() );
	}

	public function test_the_two_404s_are_distinguishable(): void {
		$a = new Response( 404, [ 'error' => [ 'message' => Response::NOT_FOUND ] ] );
		$b = new Response( 404, [ 'error' => [ 'message' => Response::NOT_GENERATED ] ] );
		self::assertNotSame( $a->kind(), $b->kind() );
	}
}
