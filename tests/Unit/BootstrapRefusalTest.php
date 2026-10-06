<?php
/**
 * What stops this plugin from booting.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\Plugin;

/**
 * There is one install of this plugin, on the site that takes every free-plan
 * store's payments, and nowhere to try an upgrade first. So the two conditions
 * that keep it inert are pinned here rather than trusted: a core plugin too old to
 * have the helpers it calls, and a core plugin that still serves the platform
 * routes itself, which would mean two handlers racing for the same REST routes.
 *
 * Runs in separate processes: \WopCart\VERSION is a constant, and one defined for
 * the "current core" case cannot be undefined for the "too old" one.
 *
 * @covers \WopCartPlatform\Plugin::problem
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BootstrapRefusalTest extends TestCase {

	/**
	 * Without \WopCart\VERSION, Plugin::supports() reports version 0, which is what
	 * an install older than the helper itself looks like.
	 */
	public function test_an_older_core_is_refused_by_name(): void {
		$problem = Plugin::problem();

		$this->assertStringContainsString( '1.3.0', $problem );
		$this->assertStringNotContainsString( 'standing by', $problem, 'The version check comes first.' );
	}

	/**
	 * A current core that no longer carries the server half lets it boot.
	 */
	public function test_a_current_core_without_the_server_half_boots(): void {
		define( 'WopCart\VERSION', '1.3.0' );

		$this->assertFalse( class_exists( '\WopCart\Platform\Server' ), 'The server half moved into this plugin.' );
		$this->assertSame( '', Plugin::problem() );
	}
}
