<?php
/**
 * The controller properties new connected accounts are created with.
 *
 * Stripe rejects some combinations outright, and a rejected combination is
 * invisible until a merchant tries to connect: the account is never created and
 * the onboarding link never appears. That is exactly what happened with
 * `stripe_dashboard.type=express` alongside fees and losses that stayed with the
 * merchant — Stripe answered "your platform must collect fees and be liable for
 * negative balances or refunds and chargebacks", and no store was ever connected.
 *
 * So the combination is pinned here, with the rule that binds it: whoever changes
 * one of the three values has to change the others to match, or this fails.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\ConnectServer;

require_once __DIR__ . '/ConnectServerStubs.php';

/**
 * Controller properties for POST /v1/accounts.
 *
 * @covers \WopCartPlatform\ConnectServer
 */
final class ConnectAccountControllerTest extends TestCase {

	/**
	 * The merchant owns the account: their dashboard, their fees, Stripe's losses.
	 */
	public function test_merchant_keeps_dashboard_fees_and_stripe_keeps_losses(): void {
		$controller = ConnectServer::account_controller();

		$this->assertSame( 'full', $controller['stripe_dashboard']['type'] );
		$this->assertSame( 'account', $controller['fees']['payer'] );
		$this->assertSame( 'stripe', $controller['losses']['payments'] );
		$this->assertSame( 'stripe', $controller['requirement_collection'] );
	}

	/**
	 * Express is only legal when the platform takes both the fees and the losses.
	 *
	 * Stated as the rule rather than as "not express", so a future change that does
	 * move both to the platform may use the Express dashboard without this test
	 * standing in the way.
	 */
	public function test_express_dashboard_would_require_platform_fees_and_losses(): void {
		$controller = ConnectServer::account_controller();

		if ( 'express' !== ( $controller['stripe_dashboard']['type'] ?? '' ) ) {
			$this->assertTrue( true, 'Not an Express dashboard, so the rule does not apply.' );

			return;
		}

		$this->assertSame( 'application', $controller['fees']['payer'] );
		$this->assertSame( 'application', $controller['losses']['payments'] );
	}

	/**
	 * The same parameters keep the same key, so a timeout retry does not create a
	 * second account.
	 */
	public function test_identical_parameters_reuse_the_key(): void {
		$args = array( 'country' => 'US', 'controller' => ConnectServer::account_controller() );

		$this->assertSame(
			ConnectServer::account_idempotency_key( 'shop.example.com', $args ),
			ConnectServer::account_idempotency_key( 'shop.example.com', $args )
		);
	}

	/**
	 * Changed parameters change the key, so a refusal Stripe stored for 24 hours
	 * cannot lock a site out of retrying once the parameters are fixed.
	 */
	public function test_changed_parameters_change_the_key(): void {
		$before = array( 'country' => 'US', 'controller' => array( 'stripe_dashboard' => array( 'type' => 'express' ) ) );
		$after  = array( 'country' => 'US', 'controller' => ConnectServer::account_controller() );

		$this->assertNotSame(
			ConnectServer::account_idempotency_key( 'shop.example.com', $before ),
			ConnectServer::account_idempotency_key( 'shop.example.com', $after )
		);
	}

	/**
	 * Two sites never share a key, whatever their parameters.
	 */
	public function test_two_sites_never_share_a_key(): void {
		$args = array( 'country' => 'US' );

		$this->assertNotSame(
			ConnectServer::account_idempotency_key( 'one.example.com', $args ),
			ConnectServer::account_idempotency_key( 'two.example.com', $args )
		);
	}
}
