<?php
/**
 * The platform proxy relays invoice payment sessions on the free plan.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Gateways\Connect;
use WopCart\Invoices\InvoicePaymentAttempt;
use WopCart\Invoices\ManualInvoices;
use WopCartPlatform\ProxyPolicy;

/**
 * Invoice sessions carry a short expires_at, and the payment page reads and
 * expires them.
 *
 * @covers \WopCartPlatform\ProxyPolicy
 */
final class InvoiceProxyPolicyTest extends TestCase {

	private const HOST = 'shop.example.com';

	/**
	 * Session body as Stripe::process() builds it for an invoice order.
	 *
	 * @param int $expires_at Session expires_at.
	 *
	 * @return array<string,mixed>
	 */
	private function invoice_session( int $expires_at ): array {
		return array(
			'mode'                => 'payment',
			'success_url'         => 'https://shop.example.com/?wopcart-return=stripe&order=40&key=abc&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => 'https://shop.example.com/?wopcart-cancel=1&order=40&key=abc',
			'customer_email'      => 'buyer@example.com',
			'client_reference_id' => '40',
			'expires_at'          => $expires_at,
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => 'eur',
						'unit_amount'  => 12100,
						'product_data' => array( 'name' => 'Invoice INV-2026-0012' ),
					),
				),
			),
			'metadata'            => array( 'order_id' => '40' ),
			'payment_intent_data' => array(
				'description'            => 'Order 40',
				'metadata'               => array( 'order_id' => '40' ),
				'application_fee_amount' => Connect::fee_for( 12100 ),
			),
		);
	}

	/**
	 * The invoice expiry is accepted and the fee is still enforced.
	 */
	public function test_invoice_session_expiry_is_accepted(): void {
		$expires = InvoicePaymentAttempt::session_expiry( time(), ManualInvoices::attempt_window() );
		$result  = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->invoice_session( $expires ), self::HOST );

		$this->assertIsArray( $result );
		$this->assertSame( $expires, $result['expires_at'] );
		$this->assertSame( Connect::fee_for( 12100 ), $result['payment_intent_data']['application_fee_amount'] );

		$no_fee = $this->invoice_session( $expires );
		unset( $no_fee['payment_intent_data']['application_fee_amount'] );
		$this->assertSame( 'wopcart_proxy_fee', ProxyPolicy::authorize( 'POST', 'checkout/sessions', $no_fee, self::HOST )->get_error_code() );
	}

	/**
	 * An expiry from a stale attempt start would be refused, which is why Stripe
	 * falls back to the current time for one.
	 */
	public function test_stale_invoice_expiry_is_refused(): void {
		$stale  = InvoicePaymentAttempt::session_expiry( time() - 3 * 3600, ManualInvoices::attempt_window() );
		$result = ProxyPolicy::authorize( 'POST', 'checkout/sessions', $this->invoice_session( $stale ), self::HOST );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wopcart_proxy_param', $result->get_error_code() );
	}

	/**
	 * Reading and expiring a session are allowed, without parameters.
	 */
	public function test_session_read_and_expire_routes(): void {
		$this->assertSame( array(), ProxyPolicy::authorize( 'GET', 'checkout/sessions/cs_test_a1B2c3', array(), self::HOST ) );
		$this->assertSame( array(), ProxyPolicy::authorize( 'POST', 'checkout/sessions/cs_test_a1B2c3/expire', array(), self::HOST ) );
		$this->assertSame( array(), ProxyPolicy::authorize( 'POST', '/checkout/sessions/cs_live_Z9/expire', array(), self::HOST ) );

		$with_param = ProxyPolicy::authorize( 'POST', 'checkout/sessions/cs_test_a1B2c3/expire', array( 'metadata' => array( 'a' => 'b' ) ), self::HOST );
		$this->assertSame( 'wopcart_proxy_param', $with_param->get_error_code() );
	}

	/**
	 * Near misses of the new route stay refused.
	 */
	public function test_other_session_writes_are_refused(): void {
		$refused = array(
			array( 'POST', 'checkout/sessions/cs_test_a1B2c3' ),
			array( 'GET', 'checkout/sessions/cs_test_a1B2c3/expire' ),
			array( 'DELETE', 'checkout/sessions/cs_test_a1B2c3' ),
			array( 'POST', 'checkout/sessions/pi_wrongprefix/expire' ),
			array( 'POST', 'checkout/sessions/cs_1/expire/../../../accounts' ),
			array( 'POST', "checkout/sessions/cs_1/expire\n" ),
			array( 'POST', 'payment_intents/pi_1/cancel' ),
		);

		foreach ( $refused as $call ) {
			$result = ProxyPolicy::authorize( $call[0], $call[1], array(), self::HOST );

			$this->assertInstanceOf( \WP_Error::class, $result, $call[0] . ' ' . $call[1] );
			$this->assertSame( 'wopcart_proxy_route', $result->get_error_code() );
		}
	}
}
