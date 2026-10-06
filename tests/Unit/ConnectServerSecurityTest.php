<?php
/**
 * Security tests for the platform's Connect server.
 *
 * The platform key can act on every connected account, so the checks in front of it
 * are pinned here: a site can only ever name its own install when it connects, a
 * site with no account cannot relay calls onto the platform's own balance, and
 * Stripe's webhook request is never held open while merchant sites are contacted.
 *
 * None of these paths may touch the network or the database; the stubs provide
 * neither, so a regression that reached for one fails the test outright.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\ConnectServer;

require_once __DIR__ . '/ConnectServerStubs.php';

/**
 * ConnectServer site identity, account guard and delivery timing.
 *
 * @covers \WopCartPlatform\ConnectServer
 */
final class ConnectServerSecurityTest extends TestCase {

	private const SITE = 'https://shop.example.com/a/';
	private const ID   = '0123456789abcdef0123456789abcdef';

	/**
	 * Fresh transients, schedule record and caller address.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wopcart_test_transients'] = array();
		$GLOBALS['wopcart_test_scheduled']  = array();
		$_SERVER['REMOTE_ADDR']             = '203.0.113.20';
	}

	/**
	 * Restore global state.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$GLOBALS['wopcart_test_transients'] = array();
		$GLOBALS['wopcart_test_scheduled']  = array();

		parent::tearDown();
	}

	/**
	 * The addresses the plugin itself sends, for a store in a subdirectory.
	 *
	 * @param array<string,string> $overrides Fields to replace.
	 *
	 * @return array<string,string>
	 */
	private function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'site_url'      => self::SITE,
				'return_url'    => 'https://shop.example.com/a/wp-admin/admin-post.php?action=wopcart_connect_return&_wpnonce=abc',
				'refresh_url'   => 'https://shop.example.com/a/wp-admin/admin-post.php?action=wopcart_connect_resume&_wpnonce=abc',
				'webhook_url'   => 'https://shop.example.com/a/wp-json/wopcart/v1/connect/events',
				'challenge_url' => 'https://shop.example.com/a/wp-json/wopcart/v1/connect/challenge/' . self::ID,
			),
			$overrides
		);
	}

	/**
	 * Both shapes rest_url() produces are accepted, and the site gains its trailing slash.
	 */
	public function test_plugin_urls_are_accepted(): void {
		$urls = ConnectServer::validate_urls( $this->fields( array( 'site_url' => 'https://Shop.Example.com/a' ) ) );

		$this->assertIsArray( $urls );
		$this->assertSame( self::SITE, $urls['site_url'] );

		foreach ( array(
			'https://shop.example.com/a/index.php?rest_route=/wopcart/v1/connect/challenge/' . self::ID,
			'https://shop.example.com/a/?rest_route=%2Fwopcart%2Fv1%2Fconnect%2Fchallenge%2F' . self::ID,
		) as $challenge ) {
			$this->assertIsArray( ConnectServer::validate_urls( $this->fields( array( 'challenge_url' => $challenge ) ) ), $challenge );
		}

		$root = ConnectServer::validate_urls(
			array(
				'site_url'      => 'https://shop.example.com',
				'return_url'    => 'https://shop.example.com/wp-admin/admin-post.php',
				'refresh_url'   => 'https://shop.example.com/wp-admin/admin-post.php',
				'webhook_url'   => 'https://shop.example.com/wp-json/wopcart/v1/connect/events',
				'challenge_url' => 'https://shop.example.com/wp-json/wopcart/v1/connect/challenge/' . self::ID,
			)
		);

		$this->assertIsArray( $root );
		$this->assertSame( 'https://shop.example.com/', $root['site_url'] );
	}

	/**
	 * Another install on the same host cannot name this one as its site.
	 */
	public function test_other_paths_on_the_same_host_are_refused(): void {
		$cases = array(
			'challenge from sibling install' => array( 'challenge_url' => 'https://shop.example.com/b/wp-json/wopcart/v1/connect/challenge/' . self::ID ),
			'events to sibling install'      => array( 'webhook_url' => 'https://shop.example.com/b/wp-json/wopcart/v1/connect/events' ),
			'return to sibling install'      => array( 'return_url' => 'https://shop.example.com/b/wp-admin/admin-post.php' ),
			'prefix without directory'       => array( 'refresh_url' => 'https://shop.example.com/ab/wp-admin/admin-post.php' ),
			'dot segments'                   => array( 'challenge_url' => 'https://shop.example.com/a/../b/wp-json/wopcart/v1/connect/challenge/' . self::ID ),
			'encoded dot segments'           => array( 'challenge_url' => 'https://shop.example.com/a/%2e%2e/b/wp-json/wopcart/v1/connect/challenge/' . self::ID ),
			'other host'                     => array( 'webhook_url' => 'https://evil.example.net/a/wp-json/wopcart/v1/connect/events' ),
			'challenge is any page'          => array( 'challenge_url' => 'https://shop.example.com/a/some-page/' ),
			'challenge on other route'       => array( 'challenge_url' => 'https://shop.example.com/a/wp-json/other/v1/connect/challenge/' . self::ID ),
			'challenge id too short'         => array( 'challenge_url' => 'https://shop.example.com/a/wp-json/wopcart/v1/connect/challenge/abc' ),
			'challenge with extra query'     => array( 'challenge_url' => 'https://shop.example.com/a/?rest_route=/wopcart/v1/connect/challenge/' . self::ID . '&x=1' ),
			'challenge rest_route elsewhere' => array( 'challenge_url' => 'https://shop.example.com/a/b/?rest_route=/wopcart/v1/connect/challenge/' . self::ID ),
			'site with query'                => array( 'site_url' => 'https://shop.example.com/a/?x=1' ),
			'plain http'                     => array( 'site_url' => 'http://shop.example.com/a/' ),
		);

		foreach ( $cases as $label => $override ) {
			$result = ConnectServer::validate_urls( $this->fields( $override ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( 'wopcart_platform_url', $result->get_error_code(), $label );
		}
	}

	/**
	 * The start route refuses a cross-path request before contacting anyone.
	 */
	public function test_start_refuses_cross_path_before_any_request(): void {
		$request = new \WP_REST_Request();

		$fields = $this->fields( array( 'challenge_url' => 'https://shop.example.com/b/wp-json/wopcart/v1/connect/challenge/' . self::ID ) );

		foreach ( $fields + array(
			'country'          => 'DE',
			'challenge_digest' => str_repeat( 'a', 64 ),
		) as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = ( new ConnectServer() )->start( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wopcart_platform_url', $response->get_data()['error']['code'] );
	}

	/**
	 * A site whose row has no connected account cannot relay a call.
	 */
	public function test_proxy_refuses_site_without_account(): void {
		foreach ( array( '', 'acct_', 'cus_123', 'acct_12 3' ) as $account ) {
			$server = new ConnectServer();
			$site   = new \ReflectionProperty( ConnectServer::class, 'site' );

			$site->setValue(
				$server,
				array(
					'id'         => 4,
					'site_key'   => 'shop.example.com/a',
					'site_url'   => self::SITE,
					'account_id' => $account,
				)
			);

			$request = new \WP_REST_Request();
			$request->set_param( 'method', 'GET' );
			$request->set_param( 'path', 'payment_intents/pi_1' );

			$response = $server->proxy( $request );

			$this->assertSame( 409, $response->get_status(), 'Account "' . $account . '"' );
			$this->assertSame( 'wopcart_platform_no_account', $response->get_data()['error']['code'] );
		}
	}

	/**
	 * Without a way to release Stripe's connection, deliveries are left to cron.
	 */
	public function test_flush_schedules_instead_of_blocking_stripe(): void {
		if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
			$this->markTestSkipped( 'This SAPI can finish the response, so deliveries run inline.' );
		}

		$server  = new ConnectServer();
		$pending = new \ReflectionProperty( ConnectServer::class, 'pending' );

		$pending->setValue( $server, array( 7, 3 ) );

		// No $wpdb exists, so an inline delivery attempt would fail this test.
		$server->flush_pending();

		$this->assertCount( 1, $GLOBALS['wopcart_test_scheduled'] );
		$this->assertSame( ConnectServer::DELIVERY_HOOK, $GLOBALS['wopcart_test_scheduled'][0]['hook'] );
		$this->assertSame( array( 3 ), $GLOBALS['wopcart_test_scheduled'][0]['args'] );
		$this->assertSame( array(), $pending->getValue( $server ) );

		$server->flush_pending();

		$this->assertCount( 1, $GLOBALS['wopcart_test_scheduled'], 'Nothing pending, nothing scheduled.' );
	}
}
