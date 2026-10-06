<?php
/**
 * PHPUnit bootstrap for the platform plugin's unit suite.
 *
 * Like the core plugin's suite, these tests run without WordPress loaded, which is
 * what keeps them fast enough to run on every save. They do need the core WopCart
 * plugin's source, though — the proxy computes its fee with Gateways\Connect and
 * the plan server answers from the Licensing tables — so the core checkout has to
 * be findable. It is looked for next to this one, or at WOPCART_CORE_DIR.
 *
 * The WordPress stubs are the core suite's, included rather than copied. They are
 * the same handful of no-ops and identity functions, and two copies would drift
 * the first time either suite needed one more; a stub that drifted into pretending
 * to do something real would make these tests lie.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

/**
 * Locate the core WopCart plugin checkout.
 *
 * @return string Absolute path without a trailing slash.
 */
function wopcart_platform_core_dir(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	$candidates = array( (string) getenv( 'WOPCART_CORE_DIR' ) );

	// Both plugins are checked out side by side, under the directory name the
	// repository uses (digital-cart) or the one WordPress installs them under.
	foreach ( array( 'digital-cart', 'wopcart' ) as $sibling ) {
		$candidates[] = dirname( __DIR__, 3 ) . '/' . $sibling;
	}

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_readable( $candidate . '/src/Autoloader.php' ) ) {
			return rtrim( $candidate, '/' );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A CLI harness; WordPress, and so WP_Filesystem, is exactly what is not loaded.
	fwrite(
		STDERR,
		"The core WopCart plugin was not found.\n"
		. "Check it out next to this plugin, or set WOPCART_CORE_DIR to its directory.\n"
	);

	exit( 1 );
}

$wopcart_platform_core = wopcart_platform_core_dir();

// Defines ABSPATH, registers the core autoloader and installs the WordPress stubs.
require_once $wopcart_platform_core . '/tests/bootstrap/bootstrap.php';

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';

\WopCartPlatform\Autoloader::register();

/*
 * Constants the main plugin file defines. Declared here because the tests load
 * classes directly rather than running the plugin file, which would need
 * WordPress's hook API.
 */
defined( 'WOPCART_PLATFORM_TEST_SUITE' ) || define( 'WOPCART_PLATFORM_TEST_SUITE', true );

if ( ! defined( 'WopCartPlatform\VERSION' ) ) {
	define( 'WopCartPlatform\VERSION', '1.0.0' );
	define( 'WopCartPlatform\DB_VERSION', '1.0.0' );
	define( 'WopCartPlatform\MIN_CORE', '1.3.0' );
}
