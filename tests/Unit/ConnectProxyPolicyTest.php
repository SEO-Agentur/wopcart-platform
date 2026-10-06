<?php
/**
 * Tests for the platform's Stripe proxy rules.
 *
 * The proxy holds a key that can move money on every connected account, and it is
 * the only thing that makes the free-plan fee collectable. Both properties are
 * exercised here: nothing outside the plugin's own calls gets through, and a
 * missing or reduced fee is refused rather than quietly corrected.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCartPlatform\ProxyPolicy;

/**
 * Proxy allowlist and fee enforcement.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 * @covers \WopCart\Gateways\Connect
 */
final class ConnectProxyPolicyTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * Reset pinned filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['wopcart_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * Every call Stripe.php and ApplePay.php make is on the list.
	 */
	public function test_every_gateway_call_is_routable(): void {
		$calls = array(
			array( 'POST', 'checkout/sessions' ),
			array( 'GET', 'checkout/sessions/cs_test_a1B2c3' ),
			array( 'POST', 'payment_intents' ),
			array( 'GET', 'payment_intents/pi_3MtwBwLkdIwHu7ix' ),
			array( 'POST', 'refunds' ),
			array( 'GET', 'subscriptions/sub_1MowQVLkdIwHu7ix' ),
			array( 'POST', 'subscriptions/sub_1MowQVLkdIwHu7ix' ),
			array( 'DELETE', 'subscriptions/sub_1MowQVLkdIwHu7ix' ),
			array( 'POST', 'prices' ),
			array( 'POST', 'subscription_items/si_Na6dzxczY5fwHx' ),
			array( 'GET', 'invoices/in_1MowQWLkdIwHu7ix' ),
			array( 'POST', 'invoices/in_1MowQWLkdIwHu7ix/void' ),
			array( 'POST', 'payment_method_domains' ),
		);

		foreach ( $calls as $call ) {
			$this->assertNotNull( ProxyPolicy::route( $call[0], $call[1] ), $call[0] . ' ' . $call[1] );
		}
	}

	/**
	 * Anything else — including near misses — is not.
	 */
	public function test_unlisted_calls_are_refused(): void {
		$calls = array(
			array( 'POST', 'transfers' ),
			array( 'POST', 'accounts' ),
			array( 'GET', 'accounts/acct_123' ),
			array( 'POST', 'payouts' ),
			array( 'GET', 'customers' ),
			// Expiring is allowed (InvoiceProxyPolicyTest); other session writes are not.
			array( 'POST', 'checkout/sessions/cs_test_1' ),
			array( 'POST', 'checkout/sessions/cs_test_1/line_items' ),
			array( 'DELETE', 'prices' ),
			array( 'GET', "subscriptions/sub_1\n" ),
			array( 'GET', 'subscriptions/sub_1/../../accounts' ),
			array( 'GET', 'payment_intents/cs_wrongprefix' ),
			array( 'POST', 'application_fees/fee_1/refunds' ),
		);

		foreach ( $calls as $call ) {
			$this->assertNull( ProxyPolicy::route( $call[0], $call[1] ), $call[0] . ' ' . $call[1] );
		}

		$refused = ProxyPolicy::authorize( 'POST', 'transfers', array( 'amount' => 100 ), self::HOST );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'wopcart_proxy_route', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );
	}

	/**
	 * A one-off session gets the fee from the line items, however the merchant rounded.
	 */
	public function test_payment_session_fee_is_recomputed_from_line_items(): void {
		// 1000 + 2 x 2550 = 6100; 2% = 122.
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 122 ), self::HOST );

		$this->assertIsArray( $result );
		$this->assertSame( 122, $result['payment_intent_data']['application_fee_amount'] );

		// A higher figure is normalised to the rule, not kept.
		$higher = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 500 ), self::HOST );

		$this->assertSame( 122, $higher['payment_intent_data']['application_fee_amount'] );

		// Form-encoded bodies send numbers as strings.
		$string = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( '122' ), self::HOST );

		$this->assertSame( 122, $string['payment_intent_data']['application_fee_amount'] );
	}

	/**
	 * A reduced or missing fee is refused outright.
	 */
	public function test_payment_session_with_lower_or_missing_fee_is_refused(): void {
		$lower   = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 121 ), self::HOST );
		$missing = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( null ), self::HOST );
		$garbage = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( '1e9' ), self::HOST );

		foreach ( array( $lower, $missing, $garbage ) as $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wopcart_proxy_fee', $result->get_error_code() );
		}
	}

	/**
	 * Subscriptions carry the percentage, and nothing less.
	 */
	public function test_subscription_session_fee_percent(): void {
		$ok = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->subscription_session( 2.0 ), self::HOST );

		$this->assertIsArray( $ok );
		$this->assertSame( 2.0, $ok['subscription_data']['application_fee_percent'] );

		foreach ( array( 1.99, 0, null, 'two' ) as $supplied ) {
			$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->subscription_session( $supplied ), self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, var_export( $supplied, true ) );
			$this->assertSame( 'wopcart_proxy_fee', $result->get_error_code() );
		}
	}

	/**
	 * The fee follows the filtered basis points, so merchant and platform agree.
	 */
	public function test_fee_follows_basis_points_filter(): void {
		$GLOBALS['wopcart_test_filters'] = array( 'wopcart_platform_fee_bps' => 0 );

		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( null ), self::HOST );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'application_fee_amount', $result['payment_intent_data'] );

		$GLOBALS['wopcart_test_filters'] = array( 'wopcart_platform_fee_bps' => 350 );

		// 6100 x 3.5% = 213.5, rounded to 214.
		$this->assertInstanceOf( \WP_Error::class, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 122 ), self::HOST ) );
		$this->assertSame( 214, ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->payment_session( 214 ), self::HOST )['payment_intent_data']['application_fee_amount'] );
	}

	/**
	 * Parameters that move money elsewhere, or hide an amount from the fee, are refused by name.
	 */
	public function test_parameters_outside_the_allowlist_are_refused(): void {
		$cases = array(
			'payment_intent_data.transfer_data' => array( 'payment_intent_data' => array( 'transfer_data' => array( 'destination' => 'acct_evil' ) ) ),
			'payment_intent_data.on_behalf_of'  => array( 'payment_intent_data' => array( 'on_behalf_of' => 'acct_evil' ) ),
			'shipping_options'                  => array( 'shipping_options' => array( array( 'shipping_rate' => 'shr_1' ) ) ),
			'automatic_tax'                     => array( 'automatic_tax' => array( 'enabled' => 'true' ) ),
			'line_items.0.price'                => array(
				'line_items' => array(
					array(
						'price'    => 'price_1',
						'quantity' => 1,
					),
				),
			),
		);

		foreach ( $cases as $name => $override ) {
			$body   = array_replace_recursive( $this->payment_session( 122 ), $override );
			$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'wopcart_proxy_param', $result->get_error_code(), $name );
			$this->assertStringContainsString( $name, $result->get_error_message(), $name );
		}

		$sub    = $this->subscription_session( 2.0 );
		$sub['subscription_data']['transfer_data'] = array( 'destination' => 'acct_evil' );
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $sub, self::HOST );

		$this->assertSame( 'wopcart_proxy_param', $result->get_error_code() );
	}

	/**
	 * A subscription session may carry one first-payment coupon; the fee percent is kept.
	 */
	public function test_subscription_session_accepts_one_coupon(): void {
		$body              = $this->subscription_session( 2.0 );
		$body['discounts'] = array( array( 'coupon' => 'Wx1_first-20' ) );

		$ok = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

		$this->assertIsArray( $ok );
		$this->assertSame( array( array( 'coupon' => 'Wx1_first-20' ) ), $ok['discounts'] );
		$this->assertSame( 2.0, $ok['subscription_data']['application_fee_percent'], 'The percentage fee applies to the discounted invoice.' );

		// The fee still cannot be lowered alongside a coupon.
		$lower              = $this->subscription_session( 1.0 );
		$lower['discounts'] = $body['discounts'];

		$this->assertSame( 'wopcart_proxy_fee', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $lower, self::HOST )->get_error_code() );

		$refused = array(
			'two coupons'      => array( array( 'coupon' => 'a' ), array( 'coupon' => 'b' ) ),
			'none'             => array(),
			'bad id'           => array( array( 'coupon' => 'a b' ) ),
			'promotion code'   => array( array( 'promotion_code' => 'promo_1' ) ),
			'nested structure' => array( array( 'coupon' => array( 'id' => 'a' ) ) ),
		);

		foreach ( $refused as $name => $discounts ) {
			$bad              = $this->subscription_session( 2.0 );
			$bad['discounts'] = $discounts;
			$result           = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $bad, self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $name );
			$this->assertSame( 'wopcart_proxy_param', $result->get_error_code(), $name );
		}
	}

	/**
	 * A one-off payment has its fee fixed from the lines, so it takes no coupon.
	 */
	public function test_payment_session_refuses_coupon(): void {
		$body              = $this->payment_session( 122 );
		$body['discounts'] = array( array( 'coupon' => 'first20' ) );

		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_proxy_discounts', $result->get_error_code() );
	}

	/**
	 * Promotion codes would shrink the charge after the fee was fixed.
	 */
	public function test_promotion_codes_are_refused(): void {
		$body = $this->payment_session( 122 );

		$body['allow_promotion_codes'] = 'true';

		$this->assertSame( 'wopcart_proxy_promotions', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST )->get_error_code() );

		$body['allow_promotion_codes'] = false;

		$this->assertIsArray( ProxyPolicy::authorize( 'POST', 'checkout/sessions', $body, self::HOST ) );
	}

	/**
	 * The wallet's PaymentIntent gets the same rule.
	 */
	public function test_payment_intent_fee(): void {
		$body = array(
			'amount'                    => 999,
			'currency'                  => 'eur',
			'automatic_payment_methods' => array( 'enabled' => 'true' ),
			'metadata'                  => array( 'source' => 'wopcart_wallet' ),
			'application_fee_amount'    => 20,
		);

		$this->assertSame( 20, ProxyPolicy::authorize( 'POST', 'payment_intents', $body, self::HOST )['application_fee_amount'] );

		$body['application_fee_amount'] = 19;

		$this->assertSame( 'wopcart_proxy_fee', ProxyPolicy::authorize( 'POST', 'payment_intents', $body, self::HOST )->get_error_code() );

		unset( $body['application_fee_amount'] );
		$body['transfer_data'] = array( 'destination' => 'acct_evil' );

		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', 'payment_intents', $body, self::HOST )->get_error_code() );
	}

	/**
	 * A refund can never hand the application fee back or reverse a transfer.
	 */
	public function test_refunds_cannot_return_the_fee(): void {
		$body = array(
			'payment_intent' => 'pi_123',
			'amount'         => 500,
			'metadata'       => array( 'order_id' => '42' ),
		);

		$this->assertSame( $body, ProxyPolicy::authorize( 'POST', 'refunds', $body, self::HOST ) );

		foreach ( array( 'refund_application_fee', 'reverse_transfer' ) as $param ) {
			$result = ProxyPolicy::authorize( 'POST', 'refunds', $body + array( $param => 'true' ), self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $param );
			$this->assertSame( 'wopcart_proxy_param', $result->get_error_code() );
		}
	}

	/**
	 * A subscription update may cancel, and may not touch the fee.
	 */
	public function test_subscription_update_is_cancellation_only(): void {
		$path = 'subscriptions/sub_123';

		$this->assertIsArray( ProxyPolicy::authorize( 'POST', $path, array( 'cancel_at_period_end' => 'true' ), self::HOST ) );
		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'POST', $path, array( 'application_fee_percent' => '0' ), self::HOST )->get_error_code() );
	}

	/**
	 * Reads, cancellations and voids carry no parameters at all.
	 */
	public function test_bodyless_calls_refuse_parameters(): void {
		$this->assertSame( array(), ProxyPolicy::authorize( 'GET', 'checkout/sessions/cs_1', array(), self::HOST ) );
		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'GET', 'checkout/sessions/cs_1', array( 'expand' => array( 'x' ) ), self::HOST )->get_error_code() );
		$this->assertSame( 'wopcart_proxy_param', ProxyPolicy::authorize( 'DELETE', 'subscriptions/sub_1', array( 'prorate' => 'true' ), self::HOST )->get_error_code() );
	}

	/**
	 * Only the calling site's own domain can be registered for wallets.
	 */
	public function test_domain_registration_is_limited_to_the_site(): void {
		$this->assertSame( array( 'domain_name' => self::HOST ), ProxyPolicy::authorize( 'POST', 'payment_method_domains', array( 'domain_name' => 'Shop.Example.com' ), self::HOST ) );
		$this->assertSame( 'wopcart_proxy_domain', ProxyPolicy::authorize( 'POST', 'payment_method_domains', array( 'domain_name' => 'evil.example' ), self::HOST )->get_error_code() );
	}

	/**
	 * Idempotency keys are namespaced by account, cleaned, and capped at Stripe's limit.
	 */
	public function test_idempotency_keys_are_namespaced(): void {
		$this->assertSame( 'wopcart:acct_1:wopcart_session_42', ProxyPolicy::idempotency_key( 'acct_1', 'wopcart_session_42' ) );
		$this->assertNotSame( ProxyPolicy::idempotency_key( 'acct_1', 'k' ), ProxyPolicy::idempotency_key( 'acct_2', 'k' ) );
		$this->assertSame( '', ProxyPolicy::idempotency_key( 'acct_1', '' ) );
		$this->assertSame( 'wopcart:acct_1:abc', ProxyPolicy::idempotency_key( 'acct_1', "a\r\nb c" ) );
		$this->assertSame( 255, strlen( ProxyPolicy::idempotency_key( 'acct_1', str_repeat( 'x', 400 ) ) ) );
	}

	/**
	 * A one-off Checkout Session as Stripe.php builds it.
	 *
	 * @param mixed $fee application_fee_amount, or null to omit it.
	 *
	 * @return array<string,mixed>
	 */
	private function payment_session( mixed $fee ): array {
		$body = array(
			'mode'                  => 'payment',
			'success_url'           => 'https://shop.example.com/?wopcart-return=stripe&order=42&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'            => 'https://shop.example.com/checkout/?wopcart-cancel=stripe&order=42',
			'customer_email'        => 'buyer@example.com',
			'client_reference_id'   => '42',
			'line_items'            => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 1000,
						'product_data' => array( 'name' => 'Plugin' ),
					),
				),
				array(
					'quantity'   => '2',
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => '2550',
						'product_data' => array( 'name' => 'Theme' ),
					),
				),
			),
			'metadata'              => array(
				'order_id'  => '42',
				'order_key' => 'abc',
				'site'      => 'https://shop.example.com/',
			),
			'allow_promotion_codes' => false,
			'payment_intent_data'   => array(
				'description' => 'Shop order 42',
				'metadata'    => array( 'order_id' => '42' ),
			),
		);

		if ( null !== $fee ) {
			$body['payment_intent_data']['application_fee_amount'] = $fee;
		}

		return $body;
	}

	/**
	 * A subscription Checkout Session as Stripe.php builds it.
	 *
	 * @param mixed $percent application_fee_percent, or null to omit it.
	 *
	 * @return array<string,mixed>
	 */
	private function subscription_session( mixed $percent ): array {
		$body = array(
			'mode'              => 'subscription',
			'success_url'       => 'https://shop.example.com/?session_id={CHECKOUT_SESSION_ID}',
			'line_items'        => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'usd',
						'unit_amount'  => 1900,
						'product_data' => array( 'name' => 'Membership' ),
						'recurring'    => array(
							'interval'       => 'month',
							'interval_count' => 1,
						),
					),
				),
			),
			'subscription_data' => array(
				'metadata'          => array( 'order_id' => '7' ),
				'trial_period_days' => 14,
			),
		);

		if ( null !== $percent ) {
			$body['subscription_data']['application_fee_percent'] = $percent;
		}

		return $body;
	}
}
