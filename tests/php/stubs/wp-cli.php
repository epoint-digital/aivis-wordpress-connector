<?php
/**
 * Minimal WP-CLI stand-ins so the command class can be exercised without
 * WP-CLI. error() throws, so a test can assert the refusal and its message.
 */

declare( strict_types=1 );

namespace WP_CLI\Utils {
	function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI::$tables[] = [ $format, $items, $fields ];
	}
}

namespace {
	final class WP_CLI_Stop extends \RuntimeException {}

	final class WP_CLI {
		public static array $log = [];
		public static array $tables = [];

		public static function reset(): void {
			self::$log = self::$tables = [];
		}
		public static function log( string $m ): void { self::$log[] = [ 'log', $m ]; }
		public static function line( string $m ): void { self::$log[] = [ 'line', $m ]; }
		public static function success( string $m ): void { self::$log[] = [ 'success', $m ]; }
		public static function warning( string $m ): void { self::$log[] = [ 'warning', $m ]; }
		public static function error( string $m ): never { self::$log[] = [ 'error', $m ]; throw new WP_CLI_Stop( $m ); }
		public static function add_command( string $name, mixed $cb ): void {}
		public static function messages( string $kind ): array {
			return array_values( array_map( static fn( array $e ): string => $e[1], array_filter( self::$log, static fn( array $e ): bool => $e[0] === $kind ) ) );
		}
	}
}
