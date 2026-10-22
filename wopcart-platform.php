<?php
/**
 * Plugin Name:       WopCart Platform
 * Plugin URI:        https://wopcart.com
 * Description:       The wopcart.com server side of WopCart: plan key verification, Stripe Connect onboarding, the payment proxy and the Connect event relay. Belongs on wopcart.com alone and is never distributed to merchants.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Requires Plugins:  wopcart
 * Author:            Cyrus Badde
 * Author URI:        https://wopcart.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wopcart-platform
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '1.0.1';
const BASENAME = 'wopcart-platform/wopcart-platform.php';
const FILE     = __FILE__;
const DIR      = __DIR__;

/**
 * Schema version of the two tables this plugin owns. Bumped when either definition
 * changes, which is what makes Schema::maybe_install() run dbDelta again.
 */
const DB_VERSION = '1.0.0';

/**
 * Lowest core WopCart release this plugin can run against.
 *
 * 1.3.0 is where \WopCart\Plugin::supports() landed, which is the only version
 * check an add-on is supposed to make. The harder precondition — that core no
 * longer carries the server half itself — cannot be expressed as a version, so
 * Plugin::core_conflict() asks that question directly.
 */
const MIN_CORE = '1.3.1';

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

// Priority 6: one step after core, which boots its own modules at 5. Everything
// here reads classes core has already loaded.
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 6 );
