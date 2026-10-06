<?php
/**
 * PSR-4 style autoloader for the plugin's own classes.
 *
 * Deliberately a copy of the core plugin's rather than a shared file: the two
 * plugins are deployed and updated separately, and an autoloader that only works
 * when the other plugin happens to be loaded first would make the "core is
 * missing" notice impossible to show.
 *
 * The prefix is WopCartPlatform\ rather than a branch of WopCart\, so core's
 * autoloader never even looks at these class names.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

/**
 * Maps the WopCartPlatform\ namespace prefix to the src/ directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Base directory the prefix maps to.
	 */
	private const BASE_DIR = __DIR__ . '/';

	/**
	 * Register the autoloader with the SPL autoload stack.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Resolve and require a class file for the given fully qualified class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	private static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = self::BASE_DIR . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
