<?php
/**
 * The platform proxy accepts form payments with the free-plan fee.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Forms\Frequency;
use WopCart\Gateways\Connect;
use WopCartPlatform\ProxyPolicy;

/**
 * Form orders reach Stripe as ordinary inline price_data lines, so the existing
 * allowlist and fee recomputation apply unchanged.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 * @covers \WopCart\Forms\Frequency::stripe_recurring
 */
final class FormProxyPolicyTest extends TestCase {

	/**
	 * Reset pinned filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['wopcart_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * Session body as Stripe::process() builds it for a one-off form order.
	 *
	 * @param int      $total Line total, minor units.
	 * @param int|null $fee   Fee sent by the site.
	 *
	 * @return array<string,mixed>
	 */
	private function one_off( int $total, ?int $fee ): array {
		$body = array(
			'mode'                  => 'payment',
			'success_url'           => 'https://shop.example.com/?wopcart-return=stripe&order=12&key=abc&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'            => 'https://shop.example.com/?wopcart-cancel=1&order=12&key=abc',
			'customer_email'        => 'donor@example.com',
			'client_reference_id'   => '12',
			'line_items'            => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => $total,
						'product_data' => array( 'name' => 'Donation — Charity Donation (x1)' ),
					),
				),
			),
			'metadata'              => array(
				'order_id'  => '12',
				'order_key' => 'abc',
				'site'      => 'https://shop.example.com/',
			),
			'allow_promotion_codes' => false,
			'payment_intent_data'   => array(
				'description' => 'Order 12',
				'metadata'    => array( 'order_id' => '12' ),
			),
		);

		if ( null !== $fee ) {
			$body['payment_intent_data']['application_fee_amount'] = $fee;
		}

		return $body;
	}

	/**
	 * A custom-amount form payment is accepted and carries exactly the fee.
	 */
	public function test_custom_amount_payment_is_accepted_with_fee(): void {
		$total  = 10330;
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->one_off( $total, Connect::fee_for( $total ) ), 'shop.example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( Connect::fee_for( $total ), $result['payment_intent_data']['application_fee_amount'] );
		$this->assertGreaterThan( 0, $result['payment_intent_data']['application_fee_amount'] );
	}

	/**
	 * A form order cannot lower or drop the fee.
	 */
	public function test_reduced_fee_is_refused(): void {
		$total = 5000;

		$lower   = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->one_off( $total, Connect::fee_for( $total ) - 1 ), 'shop.example.com' );
		$missing = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->one_off( $total, null ), 'shop.example.com' );

		$this->assertInstanceOf( \WP_Error::class, $lower );
		$this->assertSame( 'wopcart_proxy_fee', $lower->get_error_code() );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'wopcart_proxy_fee', $missing->get_error_code() );
	}

	/**
	 * A higher fee sent by the site is corrected down to the rule.
	 */
	public function test_fee_is_recomputed_from_the_lines(): void {
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->one_off( 2500, 9999 ), 'shop.example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( Connect::fee_for( 2500 ), $result['payment_intent_data']['application_fee_amount'] );
	}

	/**
	 * A recurring donation, e.g. quarterly, uses the percentage fee.
	 */
	public function test_recurring_form_payment_is_accepted(): void {
		$recurring = Frequency::stripe_recurring( 'quarter' );
		$this->assertNotNull( $recurring );

		$body = array(
			'mode'                  => 'subscription',
			'success_url'           => 'https://shop.example.com/return',
			'cancel_url'            => 'https://shop.example.com/cancel',
			'customer_email'        => 'donor@example.com',
			'client_reference_id'   => '13',
			'line_items'            => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'usd',
						'unit_amount'  => 2500,
						'product_data' => array( 'name' => 'Donation — Charity Donation' ),
						'recurring'    => $recurring,
					),
				),
			),
			'metadata'              => array( 'order_id' => '13' ),
			'allow_promotion_codes' => false,
			'subscription_data'     => array(
				'metadata'                => array( 'order_id' => '13' ),
				'application_fee_percent' => Connect::fee_percent_for(),
			),
		);

		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, 'shop.example.com' );

		$this->assertIsArray( $result );
		$this->assertSame( Connect::fee_percent_for(), $result['subscription_data']['application_fee_percent'] );

		unset( $body['subscription_data']['application_fee_percent'] );
		$refused = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, 'shop.example.com' );
		$this->assertInstanceOf( \WP_Error::class, $refused );
	}

	/**
	 * A stored Price id would hide the amount from the fee and is refused.
	 */
	public function test_price_id_line_is_refused(): void {
		$body                           = $this->one_off( 5000, Connect::fee_for( 5000 ) );
		$body['line_items'][0]['price'] = 'price_123';
		$result                         = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, 'shop.example.com' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_proxy_param', $result->get_error_code() );
	}
}
