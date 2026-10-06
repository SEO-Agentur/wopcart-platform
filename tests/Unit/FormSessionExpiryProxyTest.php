<?php
/**
 * What the platform proxy accepts as a form payment session's expires_at.
 *
 * Split out of the core plugin's FormHoldAbuseTest, which keeps the merchant side:
 * that a form's stock hold and its Checkout Session close at the same moment. The
 * assertion that moved is the one about the platform — a session that outlives the
 * hold, or one with a nonsense expiry, never reaches Stripe.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Gateways\Connect;
use WopCart\Gateways\Stripe;
use WopCartPlatform\ProxyPolicy;

/**
 * A form's Checkout Session must not outlive the stock hold behind it.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 */
final class FormSessionExpiryProxyTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * The platform proxy passes a sane expires_at and refuses others.
	 */
	public function test_proxy_accepts_session_expiry(): void {
		$body = array(
			'mode'                  => 'payment',
			'success_url'           => 'https://shop.example.com/return',
			'cancel_url'            => 'https://shop.example.com/cancel',
			'customer_email'        => 'donor@example.com',
			'client_reference_id'   => '12',
			'expires_at'            => Stripe::form_session_expiry( time(), 30 ),
			'line_items'            => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 5000,
						'product_data' => array( 'name' => 'Donation' ),
					),
				),
			),
			'metadata'              => array( 'order_id' => '12' ),
			'allow_promotion_codes' => false,
			'payment_intent_data'   => array(
				'description'            => 'Order 12',
				'metadata'               => array( 'order_id' => '12' ),
				'application_fee_amount' => Connect::fee_for( 5000 ),
			),
		);

		$accepted = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

		$this->assertIsArray( $accepted );
		$this->assertSame( $body['expires_at'], $accepted['expires_at'] );

		foreach ( array( time() + 60, time() + 3 * 86400, 'soon', array( 1 ) ) as $bad ) {
			$body['expires_at'] = $bad;
			$this->assertInstanceOf( \WP_Error::class, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST ) );
		}
	}
}
