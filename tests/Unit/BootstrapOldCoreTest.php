<?php
/**
 * What this plugin says when core is older than the helper it calls.
 *
 * `Plugin::problem()` asks core's own `supports()` whether it is new enough. That
 * helper only exists from core 1.3.0: on an older core the class is there and the
 * method is not, so calling it straight is a fatal error on every request —
 * including wp-admin, which is where the merchant would go to undo it. wopcart.com
 * ran core 1.2.3 when this plugin was first installed, so the case is real rather
 * than theoretical.
 *
 * The suite loads a current core checkout, so that core cannot be made old by
 * loading classes. `problem_for()` takes the three facts as arguments instead,
 * which is what makes every combination assertable here.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\Plugin;

/**
 * Refusals, as decisions with no environment in them.
 *
 * @covers \WopCartPlatform\Plugin::problem_for
 */
final class BootstrapOldCoreTest extends TestCase {

	/**
	 * No core plugin at all: say so, and name the plugin that is missing.
	 */
	public function test_without_core_it_names_the_missing_plugin(): void {
		$this->assertStringContainsString(
			'needs the WopCart plugin',
			Plugin::problem_for( false, false, false )
		);
	}

	/**
	 * A core too old to carry `supports()` is refused by version, not by a crash.
	 */
	public function test_a_core_without_the_helper_is_refused_by_version(): void {
		$problem = Plugin::problem_for( true, false, true );

		$this->assertStringContainsString( \WopCartPlatform\MIN_CORE, $problem );
		$this->assertStringNotContainsString( 'standing by', $problem, 'The version check comes first.' );
	}

	/**
	 * A current core that still serves the routes itself: stand by, do not race it.
	 */
	public function test_a_core_that_still_serves_the_routes_is_stood_down(): void {
		$this->assertStringContainsString(
			'standing by',
			Plugin::problem_for( true, true, true )
		);
	}

	/**
	 * A current core with the server half gone is the one case that boots.
	 */
	public function test_a_current_core_without_the_server_half_boots(): void {
		$this->assertSame( '', Plugin::problem_for( true, true, false ) );
	}

	/**
	 * The live check never calls the helper unless it exists.
	 *
	 * Asserted on the source, because the only other way to prove it is to run
	 * against a core old enough to crash, which the suite cannot load.
	 */
	public function test_the_live_check_guards_the_helper_call(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$this->assertMatchesRegularExpression(
			'/method_exists\([^)]*\'supports\'[^)]*\)\s*&&/',
			$source,
			'problem() must check that supports() exists before calling it.'
		);
	}
}
