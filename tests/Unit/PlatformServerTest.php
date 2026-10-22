<?php
/**
 * Tests for the plan server's key lookup.
 *
 * The plan key is a bearer credential, so every way of not having a usable key
 * must look the same from outside. A key that exists but belongs to another
 * product used to answer differently, which told a prober the key was real.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use WopCart\Licensing\License;
use WopCartPlatform\Server;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PlatformServerStubs.php';
require_once __DIR__ . '/PlatformServerFakeDb.php';

/**
 * Plan server lookup parity.
 *
 * @covers \WopCartPlatform\Server
 */
final class PlatformServerTest extends TestCase {

	private const IP = '203.0.113.9';

	/**
	 * Install the fake database and a caller address.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb']                    = new PlatformServerFakeDb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the unit suite has no real $wpdb.
		$GLOBALS['wopcart_test_transients'] = array();
		$_SERVER['REMOTE_ADDR']             = self::IP;
	}

	/**
	 * Restore global state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'] );
		$GLOBALS['wopcart_test_transients'] = array();

		parent::tearDown();
	}

	/**
	 * A real key for another product is indistinguishable from an unknown key.
	 */
	public function test_other_product_key_matches_unknown_key(): void {
		$server = new Server();
		$params = array(
			'site'    => 'https://shop.example.com',
			'version' => '1.0.0',
		);

		$unknown = $server->handle( 'activate', array( 'key' => '11111111-22222222-33333333-44444444' ) + $params );
		$foreign = $server->handle( 'activate', array( 'key' => PlatformServerFakeDb::KNOWN_KEY ) + $params );

		$this->assertSame( 'missing_license', $foreign['error'] );
		$this->assertSame( $unknown, $foreign );
		$this->assertArrayNotHasKey( 'seats_limit', $foreign, 'Seat figures would reveal the key exists.' );
	}

	/**
	 * A key for another product counts towards the failed-lookup tally.
	 */
	public function test_other_product_key_counts_as_failure(): void {
		$server = new Server();

		$server->handle(
			'check',
			array(
				'key'  => PlatformServerFakeDb::KNOWN_KEY,
				'site' => 'https://shop.example.com',
			)
		);

		$this->assertSame( 1, $GLOBALS['wopcart_test_transients'][ 'wopcart_plan_miss_' . md5( self::IP ) ] ?? 0 );
	}

	/**
	 * A lifetime plan key (expires_at null) is an active paid plan, not an expired one.
	 */
	public function test_lifetime_plan_key_is_not_refused(): void {
		$license = new License(
			array(
				'id'               => 7,
				'status'           => License::ACTIVE,
				'activation_limit' => 10,
				'activation_count' => 1,
				'lifetime'         => 1,
				'expires_at'       => null,
			)
		);

		$payload = $this->call_private( 'success', $license, 'https://shop.example.com' );

		$this->assertNull( $this->call_private( 'refusal', $license ) );
		$this->assertNull( $this->call_private( 'seats', $license )['expires_at'] );
		$this->assertTrue( $payload['valid'] );
		$this->assertArrayNotHasKey( 'error', $payload );
	}

	/**
	 * A dated plan key whose term has passed is still refused as expired.
	 */
	public function test_lapsed_dated_plan_key_is_refused(): void {
		$license = new License(
			array(
				'id'         => 8,
				'status'     => License::ACTIVE,
				'lifetime'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'license_expired', $this->call_private( 'refusal', $license )['error'] ?? '' );
	}

	/**
	 * Call a private static method on the server.
	 *
	 * @param string $name    Method name.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return mixed
	 */
	private function call_private( string $name, mixed ...$args ): mixed {
		return ( new \ReflectionMethod( Server::class, $name ) )->invoke( null, ...$args );
	}

	/**
	 * A contended seat lock is an outage to the client, never a refusal.
	 *
	 * Checked on this side of the wire now: core deleted the class that used to
	 * classify the answer, so what can still be pinned here is the status this
	 * server sends. 503 is what makes a client treat it as an outage and keep its
	 * plan; a 200 would read as a verdict and demote a paying store.
	 */
	public function test_busy_is_sent_as_an_outage_not_a_verdict(): void {
		$response = ( new \ReflectionMethod( Server::class, 'respond' ) )->invoke(
			new Server(),
			array( 'valid' => false, 'error' => 'busy' )
		);

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( '5', $response->get_headers()['Retry-After'] ?? '' );
	}
}
