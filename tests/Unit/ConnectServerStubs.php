<?php
/**
 * WordPress stubs needed to drive ConnectServer's REST callbacks in the unit suite.
 *
 * Kept out of the shared bootstrap because only the Connect tests need them. Each
 * is the least behaviour that lets a request reach the code under test; nothing
 * here talks to the network, so a code path that tried to would fail loudly.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

require_once __DIR__ . '/PlatformServerStubs.php';
require_once __DIR__ . '/ConnectRestRequestStub.php';
require_once __DIR__ . '/ConnectRestResponseStub.php';

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Cron stub recording what was scheduled.
	 *
	 * @param int              $timestamp When.
	 * @param string           $hook      Hook.
	 * @param array<int,mixed> $args      Arguments.
	 *
	 * @return bool
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wopcart_test_scheduled'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);

		return true;
	}
}
