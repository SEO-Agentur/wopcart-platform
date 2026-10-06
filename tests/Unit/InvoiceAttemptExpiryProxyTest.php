<?php
/**
 * The platform accepts every expiry an invoice payment attempt can produce.
 *
 * Split out of the core plugin's InvoicePaymentAttemptTest, which keeps the
 * merchant side: how long an attempt's Checkout Session lives and how a filtered
 * window is clamped. This file is the pairing assertion — whatever that clamping
 * produces, including the extremes, the proxy still relays. The two halves have to
 * be kept in step by hand now that they live in different plugins, which is why
 * both ends are asserted rather than one trusting the other.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WopCart\Invoices\InvoicePaymentAttempt as Attempt;
use WopCart\Invoices\ManualInvoices;
use WopCartPlatform\ProxyPolicy;

/**
 * Every expiry the merchant end can produce is one the proxy accepts.
 *
 * @covers \WopCartPlatform\ProxyPolicy::valid_expiry
 */
final class InvoiceAttemptExpiryProxyTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * Reset pinned filters.
	 */
	protected function tearDown(): void {
		$GLOBALS['wopcart_test_filters'] = array();

		parent::tearDown();
	}

	/**
	 * The expiry the default attempt window produces is inside Stripe's range.
	 */
	public function test_default_attempt_expiry_is_accepted(): void {
		$expiry = Attempt::session_expiry( self::NOW, ManualInvoices::attempt_window() );

		$this->assertTrue( ProxyPolicy::valid_expiry( $expiry, self::NOW ) );
	}

	/**
	 * Both ends of a filtered window are accepted: a window shorter than Stripe's
	 * minimum is raised to it, and a two-day one is capped, so neither can produce
	 * a session the platform would refuse.
	 */
	public function test_clamped_attempt_expiries_are_accepted(): void {
		$GLOBALS['wopcart_test_filters']['wopcart_invoice_payment_attempt_window'] = 10 * 60;

		$short = Attempt::session_expiry( self::NOW, ManualInvoices::attempt_window() );
		$long  = Attempt::session_expiry( self::NOW, 48 * 3600 );

		$this->assertTrue( ProxyPolicy::valid_expiry( $short, self::NOW ) );
		$this->assertTrue( ProxyPolicy::valid_expiry( $long, self::NOW ) );
	}
}
