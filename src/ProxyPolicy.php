<?php
/**
 * What a merchant site may ask the platform to send to Stripe.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

use WopCart\Gateways\Connect;

/**
 * Allowlist and fee rules for the platform's Stripe proxy.
 *
 * The proxy holds a key that can act on every connected account, so it is written
 * as a list of the exact requests the plugin makes rather than a filter over
 * everything Stripe offers. Each route names the parameters it accepts; anything
 * else is refused by name, never silently dropped, so a change in Stripe.php
 * fails loudly in testing instead of quietly losing a field in production.
 *
 * The fee is recomputed here from the amounts actually being charged. The
 * merchant's own figure is only compared against it: lower or missing is refused,
 * so a modified plugin gets an error rather than a cheaper payment. Parameters
 * that could redirect money (transfer_data, on_behalf_of) or create a charge the
 * fee cannot see (shipping_options rates, optional items, automatic tax) are simply
 * not on the list. Physical orders charge shipping as an ordinary inline line item,
 * which the fee is computed from, and only send the delivery address in
 * payment_intent_data.shipping, which moves no money.
 *
 * Pure functions with no database or HTTP, so every rule is unit tested.
 */
final class ProxyPolicy {

	/**
	 * A scalar value.
	 */
	private const SCALAR = 'scalar';

	/**
	 * A Stripe metadata map: string keys, scalar values.
	 */
	private const MAP = 'map';

	/**
	 * Key marking a list schema.
	 */
	private const LIST = '[]';

	/**
	 * Key marking an object schema that may also be sent as an empty string.
	 *
	 * Stripe unsets an object parameter when it is posted empty, which is the only
	 * way to resume a paused subscription. The key can never be a real parameter:
	 * its schema value is not a schema, so a body naming it is refused.
	 */
	private const EMPTYABLE = '?';

	/**
	 * Allowed requests: method, path pattern and the rule applied to the body.
	 *
	 * Derived from every api() call in Stripe.php and ApplePay.php. Object ids are
	 * pinned to their Stripe prefixes, which also rules out path traversal.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private const ROUTES = array(
		array( 'POST', '#\Acheckout/sessions\z#', 'checkout_session' ),
		array( 'GET', '#\Acheckout/sessions/cs_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		// Closing an unpaid invoice session before a new attempt starts. It moves no
		// money and takes no parameters, so the fee rules have nothing to check.
		array( 'POST', '#\Acheckout/sessions/cs_[A-Za-z0-9_]{1,255}/expire\z#', 'empty' ),
		array( 'POST', '#\Apayment_intents\z#', 'payment_intent' ),
		array( 'GET', '#\Apayment_intents/pi_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		array( 'POST', '#\Arefunds\z#', 'refund' ),
		array( 'GET', '#\Asubscriptions/sub_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		array( 'POST', '#\Asubscriptions/sub_[A-Za-z0-9_]{1,255}\z#', 'subscription_update' ),
		array( 'DELETE', '#\Asubscriptions/sub_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		array( 'POST', '#\Aprices\z#', 'price' ),
		array( 'POST', '#\Asubscription_items/si_[A-Za-z0-9_]{1,255}\z#', 'subscription_item' ),
		// Re-reading an invoice from a webhook sent with a newer API version, so the
		// response has the pinned shape (StripePayload::invoice_needs_refetch()).
		array( 'GET', '#\Ainvoices/in_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		array( 'POST', '#\Ainvoices/in_[A-Za-z0-9_]{1,255}/void\z#', 'empty' ),
		// Paying an open renewal invoice after the customer replaced their card. The
		// invoice already carries the fee Stripe fixed from application_fee_percent
		// when it was finalised, and the call takes no parameters that could change it.
		array( 'POST', '#\Ainvoices/in_[A-Za-z0-9_]{1,255}/pay\z#', 'empty' ),
		array( 'POST', '#\Apayment_method_domains\z#', 'payment_method_domain' ),
		// Update-payment-method flow: the SetupIntent a setup-mode session created,
		// and the saved method's card expiry for the pre-expiry reminder.
		array( 'GET', '#\Asetup_intents/seti_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		array( 'GET', '#\Apayment_methods/pm_[A-Za-z0-9_]{1,255}\z#', 'empty' ),
		// The one-time retention discount of the cancellation flow.
		array( 'POST', '#\Acoupons\z#', 'coupon' ),
	);

	/**
	 * Parameters accepted per rule.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const SCHEMAS = array(
		'checkout_session'      => array(
			'mode'                         => self::SCALAR,
			'success_url'                  => self::SCALAR,
			'cancel_url'                   => self::SCALAR,
			'customer_email'               => self::SCALAR,
			'client_reference_id'          => self::SCALAR,
			'allow_promotion_codes'        => self::SCALAR,
			// When an unpaid session closes. No amount; bounds checked in checkout_session().
			'expires_at'                   => self::SCALAR,
			'metadata'                     => self::MAP,
			// Which methods Checkout offers. Neither touches an amount: the fee is
			// still recomputed from the line items whatever the buyer pays with.
			'payment_method_types'         => array( self::LIST => self::SCALAR ),
			'payment_method_configuration' => self::SCALAR,
			// Setup mode only (enforced in checkout_session()).
			'customer'                     => self::SCALAR,
			'currency'                     => self::SCALAR,
			'setup_intent_data'            => array(
				'description' => self::SCALAR,
				'metadata'    => self::MAP,
			),
			'line_items'                   => array(
				self::LIST => array(
					'quantity'   => self::SCALAR,
					'price_data' => array(
						'currency'     => self::SCALAR,
						'unit_amount'  => self::SCALAR,
						'product_data' => array( 'name' => self::SCALAR ),
						'recurring'    => array(
							'interval'       => self::SCALAR,
							'interval_count' => self::SCALAR,
						),
					),
				),
			),
			'payment_intent_data'          => array(
				'description'                 => self::SCALAR,
				'metadata'                    => self::MAP,
				'statement_descriptor'        => self::SCALAR,
				'statement_descriptor_suffix' => self::SCALAR,
				'application_fee_amount'      => self::SCALAR,
				// Delivery address only; no amount.
				'shipping'                    => array(
					'name'    => self::SCALAR,
					'phone'   => self::SCALAR,
					'address' => array(
						'line1'       => self::SCALAR,
						'line2'       => self::SCALAR,
						'city'        => self::SCALAR,
						'state'       => self::SCALAR,
						'postal_code' => self::SCALAR,
						'country'     => self::SCALAR,
					),
				),
			),
			'subscription_data'            => array(
				'metadata'                => self::MAP,
				'trial_period_days'       => self::SCALAR,
				'application_fee_percent' => self::SCALAR,
			),
			// One coupon id, subscription mode only (enforced in checkout_session()).
			'discounts'                    => array(
				self::LIST => array( 'coupon' => self::SCALAR ),
			),
		),
		'payment_intent'        => array(
			'amount'                    => self::SCALAR,
			'currency'                  => self::SCALAR,
			'automatic_payment_methods' => array( 'enabled' => self::SCALAR ),
			'metadata'                  => self::MAP,
			'receipt_email'             => self::SCALAR,
			'description'               => self::SCALAR,
			'application_fee_amount'    => self::SCALAR,
		),
		// refund_application_fee and reverse_transfer are absent on purpose. Stripe
		// leaves the application fee in place on a refund unless told otherwise,
		// and returning the 2% on ordinary refunds is not the business terms.
		'refund'                => array(
			'payment_intent' => self::SCALAR,
			'amount'         => self::SCALAR,
			'reason'         => self::SCALAR,
			'metadata'       => self::MAP,
		),
		// Cancellation, pausing, the payment method and a one-time coupon only.
		// application_fee_percent can be changed on an existing subscription, so
		// accepting arbitrary updates would let the fee be cleared; transfer_data and
		// on_behalf_of would move the money. A coupon lowers an invoice, and the
		// percentage fee then applies to what is actually collected.
		'subscription_update'   => array(
			'cancel_at_period_end'   => self::SCALAR,
			'pause_collection'       => array(
				self::EMPTYABLE => true,
				'behavior'      => self::SCALAR,
				'resumes_at'    => self::SCALAR,
			),
			'default_payment_method' => self::SCALAR,
			'coupon'                 => self::SCALAR,
		),
		'coupon'                => array(
			'percent_off' => self::SCALAR,
			'duration'    => self::SCALAR,
			'name'        => self::SCALAR,
			'metadata'    => self::MAP,
		),
		'price'                 => array(
			'currency'    => self::SCALAR,
			'unit_amount' => self::SCALAR,
			'product'     => self::SCALAR,
			'nickname'    => self::SCALAR,
			'recurring'   => array(
				'interval'       => self::SCALAR,
				'interval_count' => self::SCALAR,
			),
			'metadata'    => self::MAP,
		),
		'subscription_item'     => array(
			'price'              => self::SCALAR,
			'quantity'           => self::SCALAR,
			'proration_behavior' => self::SCALAR,
			'payment_behavior'   => self::SCALAR,
		),
		'payment_method_domain' => array(
			'domain_name' => self::SCALAR,
		),
		'empty'                 => array(),
	);

	/**
	 * The rule a request falls under, or null when it is not allowed at all.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Path below /v1, without a leading slash.
	 *
	 * @return string|null
	 */
	public static function route( string $method, string $path ): ?string {
		$method = strtoupper( $method );

		foreach ( self::ROUTES as $route ) {
			if ( $route[0] === $method && 1 === preg_match( $route[1], $path ) ) {
				return $route[2];
			}
		}

		return null;
	}

	/**
	 * Check a request and return the body to send, with the fee applied.
	 *
	 * @param string              $method    HTTP verb.
	 * @param string              $path      Path below /v1.
	 * @param array<string,mixed> $body      Parameters from the merchant site.
	 * @param string              $site_host Host of the calling site, for domain registration.
	 *
	 * @return array<string,mixed>|\WP_Error Body to forward, or the refusal.
	 */
	public static function authorize( string $method, string $path, array $body, string $site_host ): array|\WP_Error {
		$rule = self::route( $method, ltrim( $path, '/' ) );

		if ( null === $rule ) {
			return self::refuse(
				'wopcart_proxy_route',
				__( 'This Stripe request is not available through the WopCart platform.', 'wopcart-platform' ),
				403
			);
		}

		$conforms = self::conforms( $body, self::SCHEMAS[ $rule ], '' );

		if ( is_wp_error( $conforms ) ) {
			return $conforms;
		}

		return match ( $rule ) {
			'checkout_session'      => self::checkout_session( $body ),
			'payment_intent'        => self::payment_intent( $body ),
			'payment_method_domain' => self::payment_method_domain( $body, $site_host ),
			'subscription_update'   => self::subscription_update( $body ),
			'coupon'                => self::coupon( $body ),
			default                 => $body,
		};
	}

	/**
	 * Idempotency key to send to Stripe for a merchant's key.
	 *
	 * Prefixed with the connected account so two sites choosing the same key (every
	 * store has an order 1) can never collide, whatever Stripe's own scoping is.
	 * Stripe caps keys at 255 characters.
	 *
	 * @param string $account_id Connected account id.
	 * @param string $client_key Key the merchant site supplied.
	 *
	 * @return string Empty when the merchant sent none.
	 */
	public static function idempotency_key( string $account_id, string $client_key ): string {
		$clean = (string) preg_replace( '/[^A-Za-z0-9_\-.:]/', '', $client_key );

		if ( '' === $clean ) {
			return '';
		}

		return substr( 'wopcart:' . $account_id . ':' . $clean, 0, 255 );
	}

	/**
	 * Apply the fee rules to a Checkout Session.
	 *
	 * @param array<string,mixed> $body Validated body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function checkout_session( array $body ): array|\WP_Error {
		$mode  = (string) ( $body['mode'] ?? '' );
		$lines = (array) ( $body['line_items'] ?? array() );

		if ( ! in_array( $mode, array( 'payment', 'subscription', 'setup' ), true ) ) {
			return self::refuse( 'wopcart_proxy_mode', __( 'Only payment, subscription and setup checkouts are supported.', 'wopcart-platform' ) );
		}

		if ( array_key_exists( 'expires_at', $body ) && ! self::valid_expiry( $body['expires_at'], time() ) ) {
			return self::param_refusal( 'expires_at' );
		}

		$configuration = (string) ( $body['payment_method_configuration'] ?? '' );

		if ( isset( $body['payment_method_configuration'] ) && 1 !== preg_match( '/\Apmc_[A-Za-z0-9]{1,100}\z/', $configuration ) ) {
			return self::param_refusal( 'payment_method_configuration' );
		}

		if ( 'setup' === $mode ) {
			return self::setup_session( $body );
		}

		// Setup-only parameters stay out of charging sessions, so the surface a
		// payment can reach is exactly what it was before setup mode existed.
		foreach ( array( 'customer', 'currency', 'setup_intent_data' ) as $setup_only ) {
			if ( array_key_exists( $setup_only, $body ) ) {
				return self::param_refusal( $setup_only );
			}
		}

		if ( array() === $lines ) {
			return self::refuse( 'wopcart_proxy_lines', __( 'A checkout needs at least one line item.', 'wopcart-platform' ) );
		}

		// A promotion code lowers the charge after the fee was fixed from the full
		// price, which would take more than 2% of what the buyer paid. Refused so
		// the merchant is never charged a fee on money they did not receive.
		if ( self::truthy( $body['allow_promotion_codes'] ?? false ) ) {
			return self::refuse( 'wopcart_proxy_promotions', __( 'Promotion codes are not supported through the WopCart platform.', 'wopcart-platform' ) );
		}

		$total     = 0;
		$currency  = '';
		$recurring = false;
		$schedule  = null;

		foreach ( $lines as $line ) {
			$quantity = self::amount( $line['quantity'] ?? null );
			$price    = $line['price_data'] ?? null;
			$unit     = is_array( $price ) ? self::amount( $price['unit_amount'] ?? null ) : null;

			// Only inline prices: a stored Price id hides the amount from the fee.
			if ( null === $quantity || $quantity < 1 || null === $unit ) {
				return self::refuse( 'wopcart_proxy_lines', __( 'Every line item needs a quantity and an inline unit amount.', 'wopcart-platform' ) );
			}

			$line_currency = strtolower( (string) ( $price['currency'] ?? '' ) );

			if ( '' === $line_currency || ( '' !== $currency && $currency !== $line_currency ) ) {
				return self::refuse( 'wopcart_proxy_lines', __( 'Line items must share one currency.', 'wopcart-platform' ) );
			}

			$currency = $line_currency;

			// A line is one-time (a one-off product, shipping, a sign-up fee) or
			// recurring with a real interval; Stripe bills every recurring line of a
			// subscription on one schedule, so they must all share it.
			if ( array_key_exists( 'recurring', $price ) ) {
				$terms = self::recurring_terms( $price['recurring'] );

				if ( null === $terms || ( null !== $schedule && $schedule !== $terms ) ) {
					return self::refuse( 'wopcart_proxy_lines', __( 'Recurring line items need one valid billing interval.', 'wopcart-platform' ) );
				}

				$schedule  = $terms;
				$recurring = true;
			}

			// Checked before multiplying, because PHP silently turns an overflowing
			// integer into a float and the fee would then be computed from garbage.
			if ( ( $unit > 0 && intdiv( PHP_INT_MAX, $unit ) < $quantity ) || ( $total > PHP_INT_MAX - ( $unit * $quantity ) ) ) {
				return self::refuse( 'wopcart_proxy_lines', __( 'The checkout amount is out of range.', 'wopcart-platform' ) );
			}

			$total += $unit * $quantity;
		}

		if ( 'payment' === $mode ) {
			if ( isset( $body['subscription_data'] ) || $recurring ) {
				return self::refuse( 'wopcart_proxy_mode', __( 'A one-off payment cannot carry subscription terms.', 'wopcart-platform' ) );
			}

			// The fee amount is fixed from the lines; a coupon would lower the charge under it.
			if ( array_key_exists( 'discounts', $body ) ) {
				return self::refuse( 'wopcart_proxy_discounts', __( 'A one-off payment carries its discount in the line items, not as a coupon.', 'wopcart-platform' ) );
			}

			$expected = Connect::fee_for( $total );
			$supplied = self::amount( $body['payment_intent_data']['application_fee_amount'] ?? null );

			if ( $expected > 0 && ( null === $supplied || $supplied < $expected ) ) {
				return self::fee_refusal();
			}

			if ( $expected > 0 ) {
				$body['payment_intent_data']['application_fee_amount'] = $expected;
			} else {
				unset( $body['payment_intent_data']['application_fee_amount'] );
			}

			return $body;
		}

		if ( isset( $body['payment_intent_data'] ) || ! $recurring ) {
			return self::refuse( 'wopcart_proxy_mode', __( 'A subscription checkout needs a recurring line item and no one-off payment terms.', 'wopcart-platform' ) );
		}

		// One coupon at most, the first-payment discount. The fee is a percentage of
		// what each invoice collects, so it follows the discounted charge.
		if ( array_key_exists( 'discounts', $body ) ) {
			$discounts = (array) $body['discounts'];

			if ( 1 !== count( $discounts ) || 1 !== preg_match( '/\A[A-Za-z0-9_\-]{1,200}\z/', (string) ( $discounts[0]['coupon'] ?? '' ) ) ) {
				return self::param_refusal( 'discounts' );
			}
		}

		// A percentage, because Stripe applies it to renewals it has not billed yet.
		$expected = Connect::fee_percent_for();
		$supplied = $body['subscription_data']['application_fee_percent'] ?? null;

		if ( $expected > 0 && ( ! is_numeric( $supplied ) || (float) $supplied + 0.000001 < $expected ) ) {
			return self::fee_refusal();
		}

		if ( $expected > 0 ) {
			$body['subscription_data']['application_fee_percent'] = $expected;
		} else {
			unset( $body['subscription_data']['application_fee_percent'] );
		}

		return $body;
	}

	/**
	 * Apply the fee rules to a PaymentIntent, as created for the Apple Pay sheet.
	 *
	 * @param array<string,mixed> $body Validated body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function payment_intent( array $body ): array|\WP_Error {
		$amount = self::amount( $body['amount'] ?? null );

		if ( null === $amount || $amount < 1 || '' === (string) ( $body['currency'] ?? '' ) ) {
			return self::refuse( 'wopcart_proxy_amount', __( 'A payment needs a positive amount and a currency.', 'wopcart-platform' ) );
		}

		$expected = Connect::fee_for( $amount );
		$supplied = self::amount( $body['application_fee_amount'] ?? null );

		if ( $expected > 0 && ( null === $supplied || $supplied < $expected ) ) {
			return self::fee_refusal();
		}

		if ( $expected > 0 ) {
			$body['application_fee_amount'] = $expected;
		} else {
			unset( $body['application_fee_amount'] );
		}

		return $body;
	}

	/**
	 * Only the calling site's own domain may be registered for wallets.
	 *
	 * @param array<string,mixed> $body      Validated body.
	 * @param string              $site_host Host of the calling site.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function payment_method_domain( array $body, string $site_host ): array|\WP_Error {
		$domain = strtolower( trim( (string) ( $body['domain_name'] ?? '' ) ) );

		if ( '' === $site_host || $domain !== strtolower( $site_host ) ) {
			return self::refuse( 'wopcart_proxy_domain', __( 'Only this site\'s own domain can be registered.', 'wopcart-platform' ) );
		}

		return array( 'domain_name' => $domain );
	}

	/**
	 * Rules for a setup-mode Checkout Session, used to replace a subscription's card.
	 *
	 * Setup mode moves no money, so there is no fee to apply — which is exactly why
	 * it must not be able to carry anything that does. Line items and payment or
	 * subscription terms are refused outright, and the customer must be named, so
	 * the session can only attach a payment method to a customer that already
	 * exists on the connected account.
	 *
	 * @param array<string,mixed> $body Validated body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function setup_session( array $body ): array|\WP_Error {
		foreach ( array( 'line_items', 'payment_intent_data', 'subscription_data', 'discounts' ) as $charging ) {
			if ( array_key_exists( $charging, $body ) ) {
				return self::refuse( 'wopcart_proxy_mode', __( 'A setup checkout cannot carry line items or payment terms.', 'wopcart-platform' ) );
			}
		}

		if ( self::truthy( $body['allow_promotion_codes'] ?? false ) ) {
			return self::refuse( 'wopcart_proxy_promotions', __( 'Promotion codes are not supported through the WopCart platform.', 'wopcart-platform' ) );
		}

		if ( 1 !== preg_match( '/\Acus_[A-Za-z0-9]{1,255}\z/', (string) ( $body['customer'] ?? '' ) ) ) {
			return self::refuse( 'wopcart_proxy_customer', __( 'A setup checkout must name an existing customer.', 'wopcart-platform' ) );
		}

		return $body;
	}

	/**
	 * Rules for updating a subscription.
	 *
	 * The schema already limits which fields may be sent; this pins their values.
	 * Pausing is only allowed with behavior=void, the retention flow's choice: the
	 * other behaviours leave invoices that can be collected later outside the
	 * flow the plugin tracks. Ids are pinned to their prefixes.
	 *
	 * @param array<string,mixed> $body Validated body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function subscription_update( array $body ): array|\WP_Error {
		if ( array_key_exists( 'pause_collection', $body ) && '' !== $body['pause_collection'] ) {
			$pause = (array) $body['pause_collection'];

			if ( 'void' !== (string) ( $pause['behavior'] ?? '' ) ) {
				return self::param_refusal( 'pause_collection.behavior' );
			}

			if ( array_key_exists( 'resumes_at', $pause ) && null === self::amount( $pause['resumes_at'] ) ) {
				return self::param_refusal( 'pause_collection.resumes_at' );
			}
		}

		if ( array_key_exists( 'default_payment_method', $body ) && 1 !== preg_match( '/\Apm_[A-Za-z0-9]{1,255}\z/', (string) $body['default_payment_method'] ) ) {
			return self::param_refusal( 'default_payment_method' );
		}

		// Never empty: posting an empty coupon would clear a discount, which no
		// plugin flow does, and a stray empty value should fail loudly.
		if ( array_key_exists( 'coupon', $body ) && 1 !== preg_match( '/\A[A-Za-z0-9_\-]{1,200}\z/', (string) $body['coupon'] ) ) {
			return self::param_refusal( 'coupon' );
		}

		return $body;
	}

	/**
	 * Whether a Checkout Session expires_at is within what Stripe accepts.
	 *
	 * @param mixed $value Posted value.
	 * @param int   $now   Current Unix time.
	 *
	 * @return bool
	 */
	public static function valid_expiry( mixed $value, int $now ): bool {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/\A\d{1,12}\z/', $value ) ) ) {
			return false;
		}

		$at = (int) $value;

		// Stripe's own window is 30 minutes to 24 hours; a minute of slack either
		// way for the time the relay takes.
		return $at >= $now + 29 * 60 && $at <= $now + 24 * 3600;
	}

	/**
	 * Rules for creating the retention coupon.
	 *
	 * Only a percentage that applies once. A forever or repeating coupon would keep
	 * lowering every future invoice from a single "stay with us" click, and an
	 * amount-off coupon could exceed a small invoice entirely.
	 *
	 * @param array<string,mixed> $body Validated body.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function coupon( array $body ): array|\WP_Error {
		if ( 'once' !== (string) ( $body['duration'] ?? '' ) ) {
			return self::refuse( 'wopcart_proxy_coupon', __( 'Only a one-time percentage coupon can be created through the WopCart platform.', 'wopcart-platform' ) );
		}

		$percent = $body['percent_off'] ?? null;

		if ( ! is_numeric( $percent ) || (float) $percent <= 0 || (float) $percent > 100 ) {
			return self::refuse( 'wopcart_proxy_coupon', __( 'Only a one-time percentage coupon can be created through the WopCart platform.', 'wopcart-platform' ) );
		}

		return $body;
	}

	/**
	 * Check a value against a schema, naming the first parameter that does not fit.
	 *
	 * @param mixed  $value  Value.
	 * @param mixed  $schema self::SCALAR, self::MAP, a list schema or an object schema.
	 * @param string $name   Dotted parameter name, for the error.
	 *
	 * @return true|\WP_Error
	 */
	private static function conforms( mixed $value, mixed $schema, string $name ): true|\WP_Error {
		if ( self::SCALAR === $schema ) {
			return is_scalar( $value ) ? true : self::param_refusal( $name );
		}

		if ( self::MAP === $schema ) {
			if ( ! is_array( $value ) || count( $value ) > 50 ) {
				return self::param_refusal( $name );
			}

			foreach ( $value as $key => $item ) {
				if ( strlen( (string) $key ) > 40 || ! is_scalar( $item ) ) {
					return self::param_refusal( $name . '.' . $key );
				}
			}

			return true;
		}

		if ( is_array( $schema ) && array_key_exists( self::EMPTYABLE, $schema ) && '' === $value ) {
			return true;
		}

		if ( ! is_array( $value ) || ! is_array( $schema ) ) {
			return self::param_refusal( $name );
		}

		if ( array_key_exists( self::LIST, $schema ) ) {
			if ( ! array_is_list( $value ) || count( $value ) > 100 ) {
				return self::param_refusal( $name );
			}

			foreach ( $value as $index => $item ) {
				$result = self::conforms( $item, $schema[ self::LIST ], $name . '.' . $index );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			return true;
		}

		foreach ( $value as $key => $item ) {
			$child = '' === $name ? (string) $key : $name . '.' . $key;

			if ( ! is_string( $key ) || ! array_key_exists( $key, $schema ) ) {
				return self::param_refusal( $child );
			}

			$result = self::conforms( $item, $schema[ $key ], $child );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * A line's billing schedule as "interval:count", or null when it is not a valid one.
	 *
	 * Stripe caps a recurring interval at one year.
	 *
	 * @param mixed $recurring price_data.recurring as sent.
	 *
	 * @return string|null
	 */
	private static function recurring_terms( mixed $recurring ): ?string {
		$limits   = array(
			'day'   => 365,
			'week'  => 52,
			'month' => 12,
			'year'  => 1,
		);
		$interval = is_array( $recurring ) ? ( $recurring['interval'] ?? null ) : null;

		if ( ! is_string( $interval ) || ! isset( $limits[ $interval ] ) ) {
			return null;
		}

		$count = self::amount( $recurring['interval_count'] ?? 1 );

		if ( null === $count || $count < 1 || $count > $limits[ $interval ] ) {
			return null;
		}

		return $interval . ':' . $count;
	}

	/**
	 * A non-negative integer amount, accepting the string form a form body produces.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null Null when the value is not a whole non-negative number.
	 */
	private static function amount( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_string( $value ) && '' !== $value && strlen( $value ) < 19 && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * Whether a form-style boolean is set.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return bool
	 */
	private static function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || in_array( $value, array( 'true', '1' ), true );
	}

	/**
	 * Refusal naming a parameter outside the allowlist.
	 *
	 * @param string $name Dotted parameter name.
	 *
	 * @return \WP_Error
	 */
	private static function param_refusal( string $name ): \WP_Error {
		return self::refuse(
			'wopcart_proxy_param',
			sprintf(
				/* translators: %s: Stripe parameter name. */
				__( 'The parameter "%s" is not accepted by the WopCart platform.', 'wopcart-platform' ),
				'' === $name ? 'body' : $name
			)
		);
	}

	/**
	 * Refusal for a missing or reduced platform fee.
	 *
	 * @return \WP_Error
	 */
	private static function fee_refusal(): \WP_Error {
		return self::refuse(
			'wopcart_proxy_fee',
			__( 'The platform fee on this payment is missing or lower than the free plan requires.', 'wopcart-platform' )
		);
	}

	/**
	 * Build a refusal carrying an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return \WP_Error
	 */
	private static function refuse( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
