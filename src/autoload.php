<?php
/**
 * PSR-4 autoloader for the AI Provider for Osaurus plugin.
 *
 * Classes under the `OsaurusAi\Connector\` namespace map 1:1 to files under
 * `src/` using forward slashes in place of namespace separators. Composer is
 * intentionally avoided to keep the plugin installable as a single folder
 * drop-in, with no `composer install` step required before use.
 *
 * @package OsaurusAi\Connector
 * @since   0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

spl_autoload_register(
	/**
	 * Loads a class file for the `OsaurusAi\Connector\` namespace.
	 *
	 * Registered as a `static` closure so it does not capture `$this` and can
	 * be garbage-collected cleanly if the plugin is deactivated.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Fully-qualified class name being autoloaded.
	 * @return void
	 */
	static function ( string $class_name ): void {
		$prefix   = 'OsaurusAi\\Connector\\';
		$base_dir = __DIR__ . '/';

		$len = strlen( $prefix );

		// Not in our namespace — let other autoloaders handle it.
		if ( strncmp( $class_name, $prefix, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
