<?php
/**
 * PSR-4 autoloader for the AivisOS namespace.
 *
 * Deliberately not Composer: a site owner installs a ZIP, and a plugin that
 * needs `composer install` at runtime is a plugin that does not work. Composer
 * is used for development tooling only (composer.json).
 *
 * @package AivisOS
 */

declare( strict_types=1 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AivisOS\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
