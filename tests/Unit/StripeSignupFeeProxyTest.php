<?php
/**
 * The platform proxy's rules for a subscription session that carries a sign-up fee.
 *
 * Split out of the core plugin's StripeSignupFeeTest, which keeps the arithmetic
 * every store runs — that the sign-up fee reaches the first Stripe invoice to the
 * cent. What is here is the other end of that session: the shapes the platform
 * will relay, and the ones it refuses because they would hide an amount the fee is
 * computed from.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\ProxyPolicy;

/**
 * A sign-up fee is a second, one-off line on a subscription session; the proxy has
 * to let that shape through while still refusing every way of hiding an amount in
 * it.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 */
final class StripeSignupFeeProxyTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * The proxy accepts a one-time fee line next to the recurring line, with a coupon, and keeps the fee percent.
	 */
	public function test_proxy_accepts_one_time_fee_line(): void {
		$body              = $this->session();
		$body['discounts'] = array( array( 'coupon' => 'wopcart_first_42_20' ) );

		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

		$this->assertIsArray( $result );
		$this->assertSame( $body['line_items'], $result['line_items'] );
		$this->assertSame( 2.0, $result['subscription_data']['application_fee_percent'], 'The percentage covers the whole first invoice, fee included.' );

		$strings   = $this->session();
		$quarterly = array(
			'interval'       => 'month',
			'interval_count' => '3',
		);

		$strings['line_items'][0]['price_data']['recurring']   = $quarterly;
		$strings['line_items'][1]['price_data']['unit_amount'] = '550';
		$strings['line_items'][1]['quantity']                  = '1';

		$this->assertIsArray( ProxyPolicy::authorize( 'POST', 'checkout/sessions', $strings, self::HOST ), 'Form-encoded numbers.' );
	}

	/**
	 * Malformed or amount-hiding shapes are refused.
	 */
	public function test_proxy_refuses_bad_line_shapes(): void {
		$cases = array(
			'negative fee'           => array( array( 1, 'price_data', 'unit_amount' ), -550, 'wopcart_proxy_lines' ),
			'fractional fee'         => array( array( 1, 'price_data', 'unit_amount' ), '5.50', 'wopcart_proxy_lines' ),
			'empty recurring'        => array( array( 1, 'price_data', 'recurring' ), array(), 'wopcart_proxy_lines' ),
			'recurring as scalar'    => array( array( 1, 'price_data', 'recurring' ), 'month', 'wopcart_proxy_param' ),
			'unknown interval'       => array( array( 0, 'price_data', 'recurring', 'interval' ), 'fortnight', 'wopcart_proxy_lines' ),
			'zero interval count'    => array( array( 0, 'price_data', 'recurring', 'interval_count' ), 0, 'wopcart_proxy_lines' ),
			'over a year'            => array( array( 0, 'price_data', 'recurring', 'interval_count' ), 13, 'wopcart_proxy_lines' ),
			'mismatched schedule'    => array(
				array( 1, 'price_data', 'recurring' ),
				array(
					'interval'       => 'year',
					'interval_count' => 1,
				),
				'wopcart_proxy_lines',
			),
			'extra recurring key'    => array( array( 0, 'price_data', 'recurring', 'usage_type' ), 'metered', 'wopcart_proxy_param' ),
			'fee as stored price'    => array( array( 1, 'price' ), 'price_123', 'wopcart_proxy_param' ),
			'tax rate on fee'        => array( array( 1, 'tax_rates' ), array( 'txr_1' ), 'wopcart_proxy_param' ),
			'other currency for fee' => array( array( 1, 'price_data', 'currency' ), 'eur', 'wopcart_proxy_lines' ),
		);

		foreach ( $cases as $name => $case ) {
			$body = $this->session();
			$ref  = &$body['line_items'];

			foreach ( $case[0] as $key ) {
				$ref = &$ref[ $key ];
			}

			$ref = $case[1];
			unset( $ref );

			$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( $case[2], $result->get_error_code(), $name );
		}

		// Only one-time lines is not a subscription.
		$one_time = $this->session();
		unset( $one_time['line_items'][0]['price_data']['recurring'] );

		$this->assertSame( 'wopcart_proxy_mode', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $one_time, self::HOST )->get_error_code() );
	}

	/**
	 * A subscription session with a sign-up fee line, as Stripe::process() builds it.
	 *
	 * @return array<string,mixed>
	 */
	private function session(): array {
		return array(
			'mode'              => 'subscription',
			'success_url'       => 'https://shop.example.com/?session_id={CHECKOUT_SESSION_ID}',
			'line_items'        => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'usd',
						'unit_amount'  => 2090,
						'product_data' => array( 'name' => 'Plan' ),
						'recurring'    => array(
							'interval'       => 'month',
							'interval_count' => 1,
						),
					),
				),
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'usd',
						'unit_amount'  => 550,
						'product_data' => array( 'name' => 'Plan — sign-up fee' ),
					),
				),
			),
			'subscription_data' => array(
				'metadata'                => array( 'order_id' => '42' ),
				'application_fee_percent' => 2.0,
			),
		);
	}
}
