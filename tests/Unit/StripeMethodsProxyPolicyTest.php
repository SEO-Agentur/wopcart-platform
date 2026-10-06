<?php
/**
 * Tests for the proxy allowlist additions made for payment methods, recovery and retention.
 *
 * Every new route and parameter widens what a free-plan store can ask the platform
 * key to do, so each addition is pinned twice: the plugin's own call passes, and the
 * nearby calls or values that could clear the fee or move money are still refused.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\ProxyPolicy;

/**
 * Allowlist additions for payment methods, recovery and retention.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 */
final class StripeMethodsProxyPolicyTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * Reset pinned filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['wopcart_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * The new calls route; their neighbours do not.
	 */
	public function test_new_routes_and_near_misses(): void {
		foreach ( array(
			array( 'GET', 'setup_intents/seti_1Abc' ),
			array( 'GET', 'payment_methods/pm_1Abc' ),
			array( 'POST', 'coupons' ),
			array( 'POST', 'invoices/in_1Abc/pay' ),
		) as $call ) {
			$this->assertNotNull( ProxyPolicy::route( $call[0], $call[1] ), $call[0] . ' ' . $call[1] );
		}

		foreach ( array(
			array( 'POST', 'setup_intents' ),
			array( 'POST', 'setup_intents/seti_1/confirm' ),
			array( 'POST', 'payment_methods/pm_1/detach' ),
			array( 'POST', 'payment_methods/pm_1' ),
			array( 'GET', 'customers/cus_1' ),
			array( 'POST', 'customers/cus_1' ),
			array( 'POST', 'coupons/abc' ),
			array( 'DELETE', 'coupons/abc' ),
			array( 'POST', 'promotion_codes' ),
			array( 'GET', 'payment_methods/seti_wrongprefix' ),
			array( 'POST', 'invoices/in_1/pay/extra' ),
		) as $call ) {
			$this->assertNull( ProxyPolicy::route( $call[0], $call[1] ), $call[0] . ' ' . $call[1] );
		}

		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'invoices/in_1/pay', array( 'paid_out_of_band' => 'true' ), self::HOST )->get_error_code() );
		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'GET', 'payment_methods/pm_1', array( 'expand' => array( 'customer' ) ), self::HOST )->get_error_code() );
	}

	/**
	 * Choosing payment methods leaves the one-off fee exactly as enforced before.
	 */
	public function test_payment_methods_do_not_affect_payment_fee(): void {
		$dashboard = $this->payment_session( 122 ) + array( 'payment_method_configuration' => 'pmc_1Abc' );
		$cards     = $this->payment_session( 122 ) + array( 'payment_method_types' => array( 'card' ) );

		$this->assertSame( 122, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $dashboard, self::HOST )['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 122, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $cards, self::HOST )['payment_intent_data']['application_fee_amount'] );

		$lower = $this->payment_session( 121 ) + array( 'payment_method_types' => array( 'sepa_debit' ) );

		$this->assertSame( 'wopcart_proxy_fee', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $lower, self::HOST )->get_error_code() );

		$bad = $this->payment_session( 122 ) + array( 'payment_method_configuration' => 'acct_evil' );

		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $bad, self::HOST )->get_error_code() );

		$nested = $this->payment_session( 122 ) + array( 'payment_method_types' => array( array( 'card' ) ) );

		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $nested, self::HOST )->get_error_code() );
	}

	/**
	 * And the subscription percentage likewise.
	 */
	public function test_payment_methods_do_not_affect_subscription_fee(): void {
		$ok = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->subscription_session( 2.0 ), self::HOST );

		$this->assertSame( 2.0, $ok['subscription_data']['application_fee_percent'] );
		$this->assertSame( 'wopcart_proxy_fee', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->subscription_session( 1.99 ), self::HOST )->get_error_code() );
	}

	/**
	 * A setup session is accepted only as the update-payment flow builds it.
	 */
	public function test_setup_session_rules(): void {
		$this->assertSame( $this->setup_session(), ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->setup_session(), self::HOST ) );

		$cases = array(
			'wopcart_proxy_customer'   => array( 'customer' => 'acct_evil' ),
			'wopcart_proxy_mode'       => array(
				'line_items' => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => 'eur',
							'unit_amount'  => 1000,
							'product_data' => array( 'name' => 'Sneaky' ),
						),
					),
				),
			),
			'wopcart_proxy_promotions' => array( 'allow_promotion_codes' => 'true' ),
		);

		foreach ( $cases as $code => $override ) {
			$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', array_replace( $this->setup_session(), $override ), self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $code );
			$this->assertSame( $code, $result->get_error_code() );
		}

		$missing = $this->setup_session();
		unset( $missing['customer'] );

		$this->assertSame( 'wopcart_proxy_customer', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $missing, self::HOST )->get_error_code() );

		$terms = $this->setup_session() + array( 'subscription_data' => array( 'application_fee_percent' => 0 ) );

		$this->assertSame( 'wopcart_proxy_mode', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $terms, self::HOST )->get_error_code() );

		$money                                      = $this->setup_session();
		$money['setup_intent_data']['on_behalf_of'] = 'acct_evil';

		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $money, self::HOST )->get_error_code() );
	}

	/**
	 * Setup-only parameters cannot ride along on a charging session.
	 */
	public function test_setup_parameters_refused_on_charging_sessions(): void {
		foreach ( array(
			'customer'          => 'cus_1',
			'currency'          => 'eur',
			'setup_intent_data' => array( 'description' => 'x' ),
		) as $param => $value ) {
			$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 122 ) + array( $param => $value ), self::HOST );

			$this->assertSame( 'wopcart_proxy_param', $result->get_error_code(), $param );
		}
	}

	/**
	 * Pause, resume, payment method and coupon updates pass; the fee and money routing do not.
	 */
	public function test_subscription_update_additions(): void {
		$path = 'subscriptions/sub_123';
		$ok   = array(
			array( 'cancel_at_period_end' => 'true' ),
			array(
				'pause_collection' => array(
					'behavior'   => 'void',
					'resumes_at' => 1808006400,
				),
			),
			array( 'pause_collection' => '' ),
			array( 'default_payment_method' => 'pm_1Abc' ),
			array( 'coupon' => 'Z4OV52SU' ),
		);

		foreach ( $ok as $index => $body ) {
			$this->assertSame( $body, ProxyPolicy::authorize( 'POST', $path, $body, self::HOST ), 'allowed update #' . $index );
		}

		$refused = array(
			'keep_as_draft'   => array( 'pause_collection' => array( 'behavior' => 'keep_as_draft' ) ),
			'resumes_at text' => array(
				'pause_collection' => array(
					'behavior'   => 'void',
					'resumes_at' => 'tomorrow',
				),
			),
			'marker key'      => array(
				'pause_collection' => array(
					'?'        => '1',
					'behavior' => 'void',
				),
			),
			'scalar pause'    => array( 'pause_collection' => 'void' ),
			'pm prefix'       => array( 'default_payment_method' => 'card_1Abc' ),
			'empty coupon'    => array( 'coupon' => '' ),
			'fee cleared'     => array(
				'pause_collection'        => '',
				'application_fee_percent' => '0',
			),
			'transfer'        => array(
				'default_payment_method' => 'pm_1Abc',
				'transfer_data'          => array( 'destination' => 'acct_evil' ),
			),
			'on_behalf_of'    => array( 'on_behalf_of' => 'acct_evil' ),
			'discounts'       => array( 'discounts' => array( array( 'coupon' => 'X' ) ) ),
		);

		foreach ( $refused as $name => $body ) {
			$result = ProxyPolicy::authorize( 'POST', $path, $body, self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'wopcart_proxy_param', $result->get_error_code(), $name );
		}
	}

	/**
	 * Only a once-only percentage coupon can be created.
	 */
	public function test_coupon_creation_is_once_only_percentage(): void {
		$body = array(
			'percent_off' => 20,
			'duration'    => 'once',
			'name'        => '20% off your next renewal',
			'metadata'    => array( 'wopcart' => 'retention' ),
		);

		$this->assertSame( $body, ProxyPolicy::authorize( 'POST', 'coupons', $body, self::HOST ) );

		foreach ( array( 'forever', 'repeating', '' ) as $duration ) {
			$this->assertSame( 'wopcart_proxy_coupon', ProxyPolicy::authorize( 'POST', 'coupons', array_replace( $body, array( 'duration' => $duration ) ), self::HOST )->get_error_code(), $duration );
		}

		foreach ( array( 0, 101, 'lots', -5 ) as $percent ) {
			$this->assertSame( 'wopcart_proxy_coupon', ProxyPolicy::authorize( 'POST', 'coupons', array_replace( $body, array( 'percent_off' => $percent ) ), self::HOST )->get_error_code(), (string) $percent );
		}

		foreach ( array(
			'amount_off'         => 500,
			'duration_in_months' => 12,
			'id'                 => 'FREE',
			'max_redemptions'    => 1,
		) as $param => $value ) {
			$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'coupons', $body + array( $param => $value ), self::HOST )->get_error_code(), $param );
		}
	}

	/**
	 * A setup session as Stripe::payment_method_session() builds it.
	 *
	 * @return array<string,mixed>
	 */
	private function setup_session(): array {
		return array(
			'mode'                         => 'setup',
			'customer'                     => 'cus_Na6dX7aXxi11N4',
			'success_url'                  => 'https://shop.example.com/?wopcart_payment_updated=7&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'                   => 'https://shop.example.com/account/',
			'metadata'                     => array(
				'wopcart_subscription_id' => '7',
				'subscription_id'         => 'sub_1',
			),
			'setup_intent_data'            => array( 'metadata' => array( 'wopcart_subscription_id' => '7' ) ),
			'currency'                     => 'eur',
			'payment_method_configuration' => 'pmc_1Abc',
		);
	}

	/**
	 * A one-off Checkout Session.
	 *
	 * @param mixed $fee application_fee_amount.
	 *
	 * @return array<string,mixed>
	 */
	private function payment_session( mixed $fee ): array {
		return array(
			'mode'                => 'payment',
			'success_url'         => 'https://shop.example.com/?session_id={CHECKOUT_SESSION_ID}',
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 1000,
						'product_data' => array( 'name' => 'Plugin' ),
					),
				),
				array(
					'quantity'   => 2,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 2550,
						'product_data' => array( 'name' => 'Theme' ),
					),
				),
			),
			'payment_intent_data' => array( 'application_fee_amount' => $fee ),
		);
	}

	/**
	 * A subscription Checkout Session.
	 *
	 * @param mixed $percent application_fee_percent.
	 *
	 * @return array<string,mixed>
	 */
	private function subscription_session( mixed $percent ): array {
		return array(
			'mode'                         => 'subscription',
			'payment_method_configuration' => 'pmc_1Abc',
			'line_items'                   => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 1900,
						'product_data' => array( 'name' => 'Membership' ),
						'recurring'    => array(
							'interval'       => 'month',
							'interval_count' => 1,
						),
					),
				),
			),
			'subscription_data'            => array( 'application_fee_percent' => $percent ),
		);
	}
}
