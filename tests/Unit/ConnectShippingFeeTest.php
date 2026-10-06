<?php
/**
 * Tests that the proxy accepts physical orders and charges the fee on shipping.
 *
 * The merchant half of this file — that the calculated order total and the inline
 * line items sent to Stripe come to the same number — stays in the core plugin's
 * ConnectShippingFeeTest, because that is arithmetic every store runs. What is
 * here is the platform's side of the same bargain: the proxy recomputes the fee
 * from those lines and refuses anything lower.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Gateways\Connect;
use WopCart\Shipping\ShippingAddress;
use WopCartPlatform\ProxyPolicy;

/**
 * The fee is computed from the inline line items including the shipping line.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 */
final class ConnectShippingFeeTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * Reset pinned filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['wopcart_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * A payment session as Stripe::process() builds it for a mixed cart.
	 *
	 * @param int|null $fee      Fee to send.
	 * @param bool     $shipping Whether to add the shipping line and address.
	 *
	 * @return array<string,mixed>
	 */
	private function session( ?int $fee, bool $shipping = true ): array {
		$lines = array(
			$this->line( 'E-book', 5950 ),
			$this->line( 'Printed book', 2140 ),
		);

		if ( $shipping ) {
			$lines[] = $this->line( 'Shipping: Standard', 524 );
		}

		$body = array(
			'mode'                  => 'payment',
			'success_url'           => 'https://shop.example.com/?wopcart-return=stripe&order=42&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'            => 'https://shop.example.com/checkout/?wopcart-cancel=stripe&order=42',
			'customer_email'        => 'buyer@example.com',
			'client_reference_id'   => '42',
			'line_items'            => $lines,
			'metadata'              => array( 'order_id' => '42' ),
			'allow_promotion_codes' => false,
			'payment_intent_data'   => array(
				'description' => 'Shop order 42',
				'metadata'    => array( 'order_id' => '42' ),
			),
		);

		if ( $shipping ) {
			$body['payment_intent_data']['shipping'] = ShippingAddress::from_array(
				array(
					'first_name'  => 'Ada',
					'address_1'   => 'Unter den Linden 1',
					'city'        => 'Berlin',
					'postal_code' => '10117',
					'country'     => 'DE',
					'phone'       => '+49 30 1234',
				)
			)->stripe();
		}

		if ( null !== $fee ) {
			$body['payment_intent_data']['application_fee_amount'] = $fee;
		}

		return $body;
	}

	/**
	 * One inline line.
	 *
	 * @param string $name   Name.
	 * @param int    $amount Amount.
	 *
	 * @return array<string,mixed>
	 */
	private function line( string $name, int $amount ): array {
		return array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => 'eur',
				'unit_amount'  => $amount,
				'product_data' => array( 'name' => $name ),
			),
		);
	}

	/**
	 * The proxy accepts the shipping line and the address, and the fee includes shipping.
	 */
	public function test_proxy_accepts_shipping_and_fee_includes_it(): void {
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->session( 172 ), self::HOST );

		$this->assertIsArray( $result );
		$this->assertSame( 172, $result['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 'DE', $result['payment_intent_data']['shipping']['address']['country'] );
		$this->assertSame( '+49 30 1234', $result['payment_intent_data']['shipping']['phone'] );
	}

	/**
	 * A fee computed on the goods alone is too low once shipping is charged.
	 */
	public function test_fee_without_shipping_is_refused(): void {
		$goods_only = Connect::fee_for( 5950 + 2140 );

		$this->assertSame( 162, $goods_only );

		$refused = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->session( $goods_only ), self::HOST );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'wopcart_proxy_fee', $refused->get_error_code() );
	}

	/**
	 * Shipping rates that would be charged outside the line items stay off the allowlist.
	 */
	public function test_checkout_shipping_options_stay_refused(): void {
		$with_options                     = $this->session( 172 );
		$with_options['shipping_options'] = array( array( 'shipping_rate' => 'shr_123' ) );

		$this->assertInstanceOf( \WP_Error::class, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $with_options, self::HOST ) );

		$sneaky = $this->session( 172 );
		$sneaky['payment_intent_data']['shipping']['amount'] = 1;

		$this->assertInstanceOf( \WP_Error::class, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $sneaky, self::HOST ), 'The address object carries no amount.' );
	}

	/**
	 * Digital-only regression: the session without shipping is accepted exactly as before.
	 */
	public function test_digital_only_session_unchanged(): void {
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->session( 162, false ), self::HOST );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'shipping', $result['payment_intent_data'] );
		$this->assertSame( 162, $result['payment_intent_data']['application_fee_amount'] );
	}
}
