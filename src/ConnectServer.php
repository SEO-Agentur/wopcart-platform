<?php
/**
 * Stripe Connect onboarding, payment proxy and event relay, served by the platform.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

use WopCart\Contracts\Bootable;
use WopCart\Emails\Mailer;
use WopCart\Gateways\Connect;
use WopCart\Gateways\Stripe;
use WopCart\Platform\SiteToken;
use WopCart\Support\Http;
use WopCart\Support\Logger;
use WopCart\Support\Signature;
use WopCart\Support\Util;

/**
 * The platform half of the free plan.
 *
 * A merchant install holds no Stripe key, so everything that needs one happens
 * here: creating the merchant's connected account, sending them through Stripe's
 * onboarding, relaying their payment calls with the fee enforced, and passing the
 * events Stripe sends about their account back to their site.
 *
 * Nothing is registered unless this install is the platform (Server::is_platform_site())
 * and actually holds the platform key. A merchant store must never be usable as a
 * relay, and a platform without its key has nothing it could relay.
 *
 * Every route except two is authenticated by the site token issued at onboarding;
 * the token row pins the connected account, so a site can only ever act on its own
 * account whatever it sends. The two exceptions carry their own proof: /connect/start
 * makes the site answer a challenge on its own domain, and /connect/webhook is signed
 * by Stripe.
 */
final class ConnectServer implements Bootable {

	/**
	 * Cron hook draining the event forwarding queue.
	 */
	public const DELIVERY_HOOK = 'wopcart_platform_deliver';

	/**
	 * Stripe API base.
	 */
	private const STRIPE_API = 'https://api.stripe.com/v1/';

	/**
	 * Shape of a connected account id. Anything else in a site row means onboarding
	 * never finished, and a call made with it would act on the platform's own account.
	 */
	private const ACCOUNT_PATTERN = '/\Aacct_[A-Za-z0-9]+\z/';

	/**
	 * REST route of the merchant's challenge answer, below the site's own address.
	 * Pinned exactly, so the ownership proof can only come from the plugin's own
	 * route of the very WordPress install at site_url — not from any other page, or
	 * any other install, that happens to share the host.
	 */
	private const CHALLENGE_ROUTE = '/wopcart/v1/connect/challenge/';

	/**
	 * Forwarding attempts before a delivery is parked. With the backoff below this
	 * spans roughly three days, the same window Stripe itself retries for.
	 */
	private const MAX_ATTEMPTS = 8;

	/**
	 * Days finished deliveries are kept. They carry buyer email addresses, so they
	 * are pruned rather than kept as a permanent copy of every merchant's sales.
	 */
	private const RETENTION_DAYS = 30;

	/**
	 * Site record authenticated for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $site = null;

	/**
	 * Deliveries queued during this request.
	 *
	 * @var array<int,int>
	 */
	private array $pending = array();

	/**
	 * Whether this install serves the Connect routes.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Server::is_platform_site() && '' !== Connect::platform_key();
	}

	/**
	 * Whether the platform key is a live-mode key.
	 *
	 * Connect webhook endpoints in live mode receive test events too, so the platform
	 * uses this to ignore events from the other mode rather than forwarding them.
	 *
	 * The key itself still belongs to the core plugin — a merchant install reads it
	 * to decide whether it has to route through the platform at all. Only the
	 * live/test question is the platform's, which is why it lives here with its one
	 * caller rather than in a class every store ships.
	 *
	 * @return bool
	 */
	public static function platform_is_live(): bool {
		return 1 === preg_match( '/\A(sk|rk)_live_/', Connect::platform_key() );
	}

	/**
	 * Signing secret of the platform's Connect webhook endpoint.
	 *
	 * A constant only: the endpoint exists on one install, and a secret that lets
	 * anyone forge payment events for every store does not belong in an options row.
	 *
	 * @return string
	 */
	public static function connect_webhook_secret(): string {
		return defined( 'WOPCART_CONNECT_WEBHOOK_SECRET' ) ? trim( (string) WOPCART_CONNECT_WEBHOOK_SECRET ) : '';
	}

	/**
	 * Register hooks — only on the platform.
	 */
	public function hooks(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::DELIVERY_HOOK, array( $this, 'drain' ) );
		add_action( 'shutdown', array( $this, 'flush_pending' ) );
	}

	/**
	 * Register the platform routes.
	 */
	public function register_routes(): void {
		$authenticated = array( $this, 'authenticate' );

		$routes = array(
			// Public: ownership of the site is proven by the challenge instead.
			'connect/start'      => array( 'start', '__return_true' ),
			'connect/link'       => array( 'link', $authenticated ),
			'connect/status'     => array( 'status', $authenticated ),
			'connect/disconnect' => array( 'disconnect', $authenticated ),
			'stripe'             => array( 'proxy', $authenticated ),
			// Public: the Stripe-Signature header is the credential.
			'connect/webhook'    => array( 'webhook', '__return_true' ),
		);

		foreach ( $routes as $route => $spec ) {
			register_rest_route(
				Server::NAMESPACE,
				'/platform/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $spec[0] ),
					'permission_callback' => $spec[1],
				)
			);
		}
	}

	/**
	 * Authenticate a site by its token.
	 *
	 * Failed attempts are counted per address, so guessing tokens is bounded even
	 * though each guess names a different site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function authenticate( \WP_REST_Request $request ): true|\WP_Error {
		$this->site = null;

		$ip = Util::ip();

		if ( self::limited( 'auth_fail', $ip, 30 ) ) {
			return new \WP_Error( 'wopcart_platform_rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ), array( 'status' => 429 ) );
		}

		$token = trim( (string) $request->get_header( 'x_wopcart_site_token' ) );
		$site  = self::site_by_id( SiteToken::site_id( $token ) );

		if ( null === $site || 'active' !== $site['status'] || ! SiteToken::matches( (string) $site['token_hash'], $token ) ) {
			self::hit( 'auth_fail', $ip, 5 * MINUTE_IN_SECONDS );

			return new \WP_Error(
				'wopcart_platform_unauthorized',
				__( 'This site is not connected to the WopCart platform.', 'wopcart-platform' ),
				array( 'status' => 401 )
			);
		}

		$this->site = $site;

		return true;
	}

	/**
	 * Connect a site: create or reuse its Express account and start onboarding.
	 *
	 * Request: site_url, return_url, refresh_url, webhook_url, challenge_url,
	 * challenge_digest, country, email (optional), business_name (optional).
	 * Response: account_id, onboarding_url, expires_at, site_token.
	 *
	 * The token is returned exactly once and only its hash is kept. Calling this
	 * again for a site that already has an account reuses the account and issues a
	 * fresh token, which is how a merchant who lost theirs recovers; that is safe
	 * only because the challenge proves the caller controls the site.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::within_limit( 'start', Util::ip(), 10, HOUR_IN_SECONDS ) ) {
			return self::error( 'wopcart_platform_rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ), 429 );
		}

		$fields = array();

		foreach ( array( 'site_url', 'return_url', 'refresh_url', 'webhook_url', 'challenge_url' ) as $field ) {
			$fields[ $field ] = (string) $request->get_param( $field );
		}

		$urls = self::validate_urls( $fields );

		if ( is_wp_error( $urls ) ) {
			return self::from_wp_error( $urls );
		}

		$site_url = $urls['site_url'];

		$country  = strtoupper( (string) $request->get_param( 'country' ) );
		$email    = sanitize_email( (string) $request->get_param( 'email' ) );
		$business = substr( sanitize_text_field( (string) $request->get_param( 'business_name' ) ), 0, 100 );
		$digest   = strtolower( (string) $request->get_param( 'challenge_digest' ) );

		if ( 1 !== preg_match( '/\A[A-Z]{2}\z/', $country ) ) {
			return self::error( 'wopcart_platform_country', __( 'A two-letter country code is required.', 'wopcart-platform' ), 400 );
		}

		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $digest ) ) {
			return self::error( 'wopcart_platform_challenge', __( 'The ownership challenge is missing.', 'wopcart-platform' ), 400 );
		}

		if ( ! self::owns_site( $urls['challenge_url'], $digest ) ) {
			return self::error(
				'wopcart_platform_ownership',
				__( 'The platform could not confirm that this request came from the site itself. Check that the site is publicly reachable over HTTPS, then try again.', 'wopcart-platform' ),
				403
			);
		}

		$key      = self::site_key( $site_url );
		$existing = self::site_by_key( $key );
		$site     = $existing ?? self::create_site( $key, $site_url );

		if ( null === $site ) {
			return self::error( 'wopcart_platform_storage', __( 'The platform could not record this site. Please try again.', 'wopcart-platform' ), 500 );
		}

		// A live connection being handed a new token is the one moment control of a
		// merchant's account can change hands, so it is recorded and the merchant told.
		$reissue = null !== $existing && 'active' === (string) $existing['status'] && self::is_account_id( (string) $existing['account_id'] );

		if ( '' === (string) $site['account_id'] ) {
			$account = self::stripe(
				'POST',
				'accounts',
				self::account_args( $site_url, $key, $country, is_email( $email ) ? $email : '', $business ),
				'',
				// A retry after a timeout returns the account Stripe already created
				// rather than a second one.
				'wopcart_account_' . hash( 'sha256', $key )
			);

			$account_id = (string) ( $account['body']['id'] ?? '' );

			if ( $account['status'] >= 300 || ! self::is_account_id( $account_id ) ) {
				return self::stripe_failure( $account );
			}

			self::update_site( (int) $site['id'], array( 'account_id' => $account_id ) );

			// Read back rather than trusting the write: an active token on a row with no
			// account would relay calls without a Stripe-Account header, i.e. against
			// the platform's own balance. Stripe's idempotency key hands the same
			// account back on the retry this failure asks for.
			$site = self::site_by_id( (int) $site['id'] );

			if ( null === $site || (string) $site['account_id'] !== $account_id ) {
				return self::error( 'wopcart_platform_storage', __( 'The platform could not record this site. Please try again.', 'wopcart-platform' ), 500 );
			}
		}

		if ( ! self::is_account_id( (string) $site['account_id'] ) ) {
			return self::error( 'wopcart_platform_no_account', __( 'This site has no Stripe account yet.', 'wopcart-platform' ), 409 );
		}

		$token   = SiteToken::generate( (int) $site['id'] );
		$changes = array(
			'site_url'    => $site_url,
			'token_hash'  => SiteToken::hash( $token ),
			'forward_key' => SiteToken::forward_key( $token ),
			'webhook_url' => $urls['webhook_url'],
			'return_url'  => $urls['return_url'],
			'refresh_url' => $urls['refresh_url'],
			'status'      => 'active',
			'updated_at'  => Util::now(),
		);

		// A token whose hash was never stored would fail every call it is used for, so
		// it is not handed out.
		if ( ! self::update_site( (int) $site['id'], $changes ) ) {
			return self::error( 'wopcart_platform_storage', __( 'The platform could not record this site. Please try again.', 'wopcart-platform' ), 500 );
		}

		if ( $reissue ) {
			self::notify_reissue( $site, $site_url, $urls['webhook_url'] );
		}

		$link = self::account_link( array_merge( $site, $changes ) );

		if ( is_wp_error( $link ) ) {
			return self::from_wp_error( $link );
		}

		Logger::info(
			'platform.connect',
			'A site started Stripe onboarding.',
			array(
				'site'    => $key,
				'account' => $site['account_id'],
			)
		);

		return self::respond(
			array(
				'account_id'     => (string) $site['account_id'],
				'onboarding_url' => $link['url'],
				'expires_at'     => $link['expires_at'],
				'site_token'     => $token,
			)
		);
	}

	/**
	 * A fresh onboarding link, for Stripe's refresh_url flow.
	 *
	 * Response: onboarding_url, expires_at.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function link( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$site = (array) $this->site;

		if ( ! self::within_limit( 'link', (string) $site['id'], 30, 5 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'wopcart_platform_rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ), 429 );
		}

		$link = self::account_link( $site );

		if ( is_wp_error( $link ) ) {
			return self::from_wp_error( $link );
		}

		return self::respond(
			array(
				'onboarding_url' => $link['url'],
				'expires_at'     => $link['expires_at'],
			)
		);
	}

	/**
	 * The connected account's state, read from Stripe.
	 *
	 * Response: account_id, charges_enabled, payouts_enabled, details_submitted,
	 * requirements{currently_due,past_due,eventually_due,disabled_reason,current_deadline},
	 * publishable_key, livemode.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		$site    = (array) $this->site;
		$account = (string) $site['account_id'];

		if ( ! self::within_limit( 'status', (string) $site['id'], 30, 5 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'wopcart_platform_rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ), 429 );
		}

		if ( 1 !== preg_match( '/\Aacct_[A-Za-z0-9]+\z/', $account ) ) {
			return self::error( 'wopcart_platform_no_account', __( 'This site has no Stripe account yet.', 'wopcart-platform' ), 409 );
		}

		$result = self::stripe( 'GET', 'accounts/' . $account );

		if ( $result['status'] >= 300 ) {
			return self::stripe_failure( $result );
		}

		$summary = self::summary( $result['body'] );

		self::update_site( (int) $site['id'], self::flags( $summary ) );

		return self::respond(
			array_merge(
				array( 'account_id' => $account ),
				$summary,
				array(
					'publishable_key' => Connect::platform_publishable(),
					'livemode'        => self::platform_is_live(),
				)
			)
		);
	}

	/**
	 * Disconnect a site.
	 *
	 * The Stripe account is deliberately left alone: it belongs to the merchant, holds
	 * their balance and payment history, and deleting it is not our decision to make.
	 * The token stops working at once and queued events are dropped.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature.
		global $wpdb;

		$site = (array) $this->site;

		self::update_site( (int) $site['id'], self::revoked() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::table( 'platform_deliveries' ),
			array(
				'status'       => 'cancelled',
				'completed_at' => Util::now(),
			),
			array(
				'site_id' => (int) $site['id'],
				'status'  => 'pending',
			)
		);

		Logger::info( 'platform.connect', 'A site disconnected from the platform.', array( 'site' => $site['site_key'] ) );

		return self::respond( array( 'disconnected' => true ) );
	}

	/**
	 * Relay one Stripe call for the authenticated site.
	 *
	 * Request: method, path (below /v1), body (object), idempotency_key.
	 * Response: Stripe's JSON with Stripe's HTTP status, or a refusal shaped like a
	 * Stripe error so the merchant's existing error handling reads it unchanged.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function proxy( \WP_REST_Request $request ): \WP_REST_Response {
		$site = (array) $this->site;

		if ( ! self::within_limit( 'proxy', (string) $site['id'], 600, 5 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'wopcart_platform_rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ), 429 );
		}

		// Checked before anything else: without a connected account the call would go
		// out on the platform's own Stripe account.
		if ( ! self::is_account_id( (string) ( $site['account_id'] ?? '' ) ) ) {
			return self::error( 'wopcart_platform_no_account', __( 'This site has no Stripe account yet.', 'wopcart-platform' ), 409 );
		}

		$method = strtoupper( (string) $request->get_param( 'method' ) );
		$path   = ltrim( (string) $request->get_param( 'path' ), '/' );
		$body   = $request->get_param( 'body' ) ?? array();

		if ( ! is_array( $body ) ) {
			return self::error( 'wopcart_proxy_param', __( 'The request body must be an object.', 'wopcart-platform' ), 400 );
		}

		$clean = ProxyPolicy::authorize( $method, $path, $body, self::host( (string) $site['site_url'] ) );

		if ( is_wp_error( $clean ) ) {
			Logger::warning(
				'platform.proxy',
				'Refused a proxied Stripe request.',
				array(
					'site'   => $site['site_key'],
					'method' => $method,
					'path'   => substr( $path, 0, 120 ),
					'code'   => $clean->get_error_code(),
				)
			);

			return self::from_wp_error( $clean );
		}

		// The account comes from the token's own record, never from the request, so a
		// site cannot act on another site's account by naming it.
		$account = (string) $site['account_id'];
		$result  = self::stripe(
			$method,
			$path,
			$clean,
			$account,
			'POST' === $method ? ProxyPolicy::idempotency_key( $account, (string) $request->get_param( 'idempotency_key' ) ) : '',
			true
		);

		return self::respond( $result['body'], $result['status'] );
	}

	/**
	 * The platform's Connect webhook endpoint.
	 *
	 * Verifies Stripe's signature, keeps the site record current from account.updated,
	 * and queues the event for the merchant's site. The merchant's site is never
	 * contacted while Stripe waits: flush_pending() first closes Stripe's connection
	 * where the server allows it, and otherwise leaves the delivery to cron, so a slow
	 * store cannot hold up or time out Stripe's delivery to the platform.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = self::connect_webhook_secret();

		if ( '' === $secret ) {
			Logger::error( 'platform.connect', 'Connect webhook received but WOPCART_CONNECT_WEBHOOK_SECRET is not defined.' );

			return self::error( 'wopcart_platform_unconfigured', 'not configured', 503 );
		}

		$payload = $request->get_body();

		if ( ! Signature::verify( $payload, (string) $request->get_header( 'stripe_signature' ), $secret ) ) {
			Logger::warning( 'platform.connect', 'Connect webhook signature verification failed.' );

			return self::error( 'wopcart_platform_signature', 'invalid signature', 400 );
		}

		$event    = json_decode( $payload, true );
		$type     = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
		$event_id = is_array( $event ) ? (string) ( $event['id'] ?? '' ) : '';

		if ( '' === $type || 1 !== preg_match( '/\Aevt_[A-Za-z0-9]{1,60}\z/', $event_id ) ) {
			return self::error( 'wopcart_platform_payload', 'malformed payload', 400 );
		}

		$account = (string) ( $event['account'] ?? '' );

		// Acknowledged but ignored: events for the platform's own account, and events
		// from the other mode, which a live Connect endpoint also receives.
		if ( 1 !== preg_match( '/\Aacct_[A-Za-z0-9]+\z/', $account ) || (bool) ( $event['livemode'] ?? false ) !== self::platform_is_live() ) {
			return self::respond( array( 'received' => true ) );
		}

		$site = self::site_by_account( $account );

		if ( null === $site ) {
			return self::respond( array( 'received' => true ) );
		}

		$object  = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		$changes = array( 'last_event_at' => Util::now() );

		if ( 'account.updated' === $type && $account === (string) ( $object['id'] ?? '' ) ) {
			$changes = array_merge( $changes, self::flags( self::summary( $object ) ) );
		}

		if ( 'account.application.deauthorized' === $type ) {
			$changes        = array_merge( $changes, self::revoked() );
			$site['status'] = 'disconnected';
		}

		self::update_site( (int) $site['id'], $changes );

		if ( 'active' === $site['status'] && '' !== (string) $site['forward_key'] ) {
			$this->enqueue( (int) $site['id'], $event_id, $type, $payload );
		}

		return self::respond( array( 'received' => true ) );
	}

	/**
	 * Attempt the deliveries queued during this request, after Stripe has its answer.
	 *
	 * Runs on shutdown, but on a plain PHP-FPM or LiteSpeed request the client is
	 * still connected at that point: forwarding inline would keep Stripe waiting on
	 * the merchant's site (up to 12 seconds per delivery), and Stripe times out and
	 * retries a slow endpoint. So the response is finished first. Where the SAPI
	 * cannot do that, nothing is sent from this request at all; a single cron event
	 * is scheduled for now instead, and the recurring drain is the backstop if even
	 * that cannot be scheduled.
	 */
	public function flush_pending(): void {
		$ids = $this->pending;

		$this->pending = array();

		if ( array() === $ids ) {
			return;
		}

		if ( ! self::finish_response() ) {
			// Unique args, so WordPress's ten-minute duplicate guard cannot swallow it.
			wp_schedule_single_event( time(), self::DELIVERY_HOOK, array( (int) min( $ids ) ) );

			return;
		}

		foreach ( $ids as $delivery_id ) {
			$this->attempt( $delivery_id );
		}
	}

	/**
	 * Drain the forwarding queue on cron and prune old deliveries.
	 */
	public function drain(): void {
		global $wpdb;

		$table = Schema::table( 'platform_deliveries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status IN ('pending','sending') AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now()
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->attempt( (int) $id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('delivered','failed','cancelled') AND created_at < %s LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now( time() - self::RETENTION_DAYS * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Queue an event for a site.
	 *
	 * Unique per site and event id, so Stripe retrying its own delivery to the
	 * platform does not send the merchant the same event twice.
	 *
	 * @param int    $site_id  Site record id.
	 * @param string $event_id Stripe event id.
	 * @param string $type     Event type.
	 * @param string $payload  Raw event JSON, forwarded byte for byte.
	 */
	private function enqueue( int $site_id, string $event_id, string $type, string $payload ): void {
		global $wpdb;

		$table = Schema::table( 'platform_deliveries' );
		$now   = Util::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (site_id, event_id, event_type, payload, status, attempts, next_attempt_at, created_at) VALUES (%d, %s, %s, %s, 'pending', 0, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site_id,
				$event_id,
				substr( $type, 0, 100 ),
				$payload,
				$now,
				$now
			)
		);

		if ( 1 === (int) $inserted ) {
			$this->pending[] = (int) $wpdb->insert_id;
		}
	}

	/**
	 * Attempt one delivery.
	 *
	 * The row is claimed with a conditional update first, so the end-of-request flush
	 * and a cron run can never send the same delivery at once. A claim that is never
	 * finished (a crashed request) becomes eligible again after ten minutes.
	 *
	 * @param int $delivery_id Delivery id.
	 *
	 * @return bool Whether it was delivered.
	 */
	private function attempt( int $delivery_id ): bool {
		global $wpdb;

		$table = Schema::table( 'platform_deliveries' );
		$now   = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', next_attempt_at = %s WHERE id = %d AND status IN ('pending','sending') AND next_attempt_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now( $now + 10 * MINUTE_IN_SECONDS ),
				$delivery_id,
				Util::now( $now )
			)
		);

		if ( 1 !== (int) $claimed ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$delivery = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $delivery_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $delivery ) ) {
			return false;
		}

		$site = self::site_by_id( (int) $delivery['site_id'] );

		// Re-checked at send time, not only when the URL was stored — including the
		// path, so a row written before paths were enforced cannot send one install's
		// events to another install on the same host.
		if ( null === $site || 'active' !== $site['status'] || '' === (string) $site['forward_key']
			|| ! self::within_site( self::https_url( (string) $site['webhook_url'] ), self::site_base( (string) $site['site_url'] ) ) ) {
			self::finish_delivery( $delivery_id, 'cancelled', (int) $delivery['attempts'], 0 );

			return false;
		}

		$body = (string) $delivery['payload'];

		// wp_safe_remote_post refuses private and loopback addresses at resolution
		// time, which a hostname check alone cannot, and no redirect is followed.
		$response = wp_safe_remote_post(
			(string) $site['webhook_url'],
			array(
				'timeout'             => 12,
				'redirection'         => 0,
				'limit_response_size' => 2048,
				'headers'             => array(
					'Content-Type'        => 'application/json',
					'User-Agent'          => 'WopCart-Platform/' . \WopCart\VERSION,
					'X-WopCart-Signature' => Signature::sign( $body, (string) $site['forward_key'], time() ),
					'X-WopCart-Event-Id'  => (string) $delivery['event_id'],
				),
				'body'                => $body,
			)
		);

		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$attempts = (int) $delivery['attempts'] + 1;

		if ( $code >= 200 && $code < 300 ) {
			self::finish_delivery( $delivery_id, 'delivered', $attempts, $code );

			return true;
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::finish_delivery( $delivery_id, 'failed', $attempts, $code );

			Logger::error(
				'platform.connect',
				'Gave up forwarding a Stripe event to a site.',
				array(
					'site'  => $site['site_key'],
					'event' => $delivery['event_id'],
					'type'  => $delivery['event_type'],
					'code'  => $code,
				)
			);

			return false;
		}

		// Roughly one minute, four, sixteen and so on, capped at twelve hours.
		$delay = (int) min( 12 * HOUR_IN_SECONDS, 60 * ( 4 ** ( $attempts - 1 ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'          => 'pending',
				'attempts'        => $attempts,
				'response_code'   => $code,
				'next_attempt_at' => Util::now( time() + $delay ),
			),
			array( 'id' => $delivery_id )
		);

		return false;
	}

	/**
	 * Mark a delivery finished.
	 *
	 * @param int    $delivery_id Delivery id.
	 * @param string $status      delivered|failed|cancelled.
	 * @param int    $attempts    Attempts made.
	 * @param int    $code        Last HTTP status.
	 */
	private static function finish_delivery( int $delivery_id, string $status, int $attempts, int $code ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::table( 'platform_deliveries' ),
			array(
				'status'        => $status,
				'attempts'      => $attempts,
				'response_code' => $code,
				'completed_at'  => Util::now(),
			),
			array( 'id' => $delivery_id )
		);
	}

	/**
	 * Parameters for a new connected account.
	 *
	 * @param string $site_url Site URL.
	 * @param string $key      Site key.
	 * @param string $country  Country code.
	 * @param string $email    Account email, possibly empty.
	 * @param string $business Business name, possibly empty.
	 *
	 * @return array<string,mixed>
	 */
	private static function account_args( string $site_url, string $key, string $country, string $email, string $business ): array {
		$args = array(
			'country'          => $country,
			'controller'       => self::account_controller(),
			'capabilities'     => array(
				'card_payments' => array( 'requested' => 'true' ),
				'transfers'     => array( 'requested' => 'true' ),
			),
			'business_profile' => array( 'url' => $site_url ),
			'metadata'         => array( 'wopcart_site' => $key ),
		);

		if ( '' !== $email ) {
			$args['email'] = $email;
		}

		if ( '' !== $business ) {
			$args['business_profile']['name'] = $business;
		}

		return $args;
	}

	/**
	 * Controller properties for new accounts.
	 *
	 * The Express Dashboard, but with Stripe's processing fees paid by the connected
	 * account. `fees.payer=application` would make the platform pay every direct
	 * charge's processing and dispute fees, which a 2% application fee does not cover
	 * (docs.stripe.com/connect/direct-charges-fee-payer-behavior). The legacy
	 * `type=express` is avoided for the same reason: Stripe advises against direct
	 * charges on that setting. Losses stay with Stripe, consistent with the merchant
	 * being merchant of record; a platform that prefers otherwise changes the filter.
	 *
	 * @return array<string,mixed>
	 */
	private static function account_controller(): array {
		/**
		 * Filter the controller properties used to create connected accounts.
		 *
		 * @param array<string,mixed> $controller Controller hash for POST /v1/accounts.
		 */
		return (array) apply_filters(
			'wopcart_platform_account_controller',
			array(
				'stripe_dashboard' => array( 'type' => 'express' ),
				'fees'             => array( 'payer' => 'account' ),
				'losses'           => array( 'payments' => 'stripe' ),
			)
		);
	}

	/**
	 * Create an onboarding link for a site.
	 *
	 * @param array<string,mixed> $site Site record.
	 *
	 * @return array{url:string,expires_at:int}|\WP_Error
	 */
	private static function account_link( array $site ): array|\WP_Error {
		$account = (string) ( $site['account_id'] ?? '' );

		if ( 1 !== preg_match( '/\Aacct_[A-Za-z0-9]+\z/', $account ) ) {
			return new \WP_Error( 'wopcart_platform_no_account', __( 'This site has no Stripe account yet.', 'wopcart-platform' ), array( 'status' => 409 ) );
		}

		$result = self::stripe(
			'POST',
			'account_links',
			array(
				'account'     => $account,
				'refresh_url' => (string) $site['refresh_url'],
				'return_url'  => (string) $site['return_url'],
				'type'        => 'account_onboarding',
			)
		);

		$url = (string) ( $result['body']['url'] ?? '' );

		if ( $result['status'] >= 300 || ! str_starts_with( $url, 'https://' ) ) {
			return new \WP_Error(
				'wopcart_platform_stripe',
				(string) ( $result['body']['error']['message'] ?? __( 'Stripe could not create an onboarding link.', 'wopcart-platform' ) ),
				array( 'status' => $result['status'] >= 500 || 0 === $result['status'] ? 502 : 400 )
			);
		}

		return array(
			'url'        => $url,
			'expires_at' => (int) ( $result['body']['expires_at'] ?? 0 ),
		);
	}

	/**
	 * Confirm the caller controls the site it names.
	 *
	 * The merchant stored a random value and sent only its hash; the platform fetches
	 * the value from the merchant's own domain. Someone who does not control that
	 * domain cannot make it answer, so they cannot connect it, and — more importantly
	 * — cannot have a fresh token issued for a site that is already connected.
	 *
	 * @param string $url    Challenge URL on the site's host.
	 * @param string $digest SHA-256 of the expected value.
	 *
	 * @return bool
	 */
	private static function owns_site( string $url, string $digest ): bool {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$value = is_array( $data ) && is_string( $data['challenge'] ?? null ) ? $data['challenge'] : '';

		return '' !== $value && hash_equals( $digest, hash( 'sha256', $value ) );
	}

	/**
	 * Call Stripe with the platform key.
	 *
	 * @param string              $method      HTTP verb.
	 * @param string              $path        Path below /v1.
	 * @param array<string,mixed> $body        Parameters.
	 * @param string              $account     Connected account for the Stripe-Account header.
	 * @param string              $idempotency Idempotency key.
	 * @param bool                $for_site    Whether the call is made on a site's behalf. Such a
	 *                                         call must act on a connected account; without one
	 *                                         it would run against the platform's own balance.
	 *
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function stripe( string $method, string $path, array $body = array(), string $account = '', string $idempotency = '', bool $for_site = false ): array {
		if ( $for_site && ! self::is_account_id( $account ) ) {
			return array(
				'status' => 409,
				'body'   => array(
					'error' => array(
						'code'    => 'wopcart_platform_no_account',
						'message' => __( 'This site has no Stripe account yet.', 'wopcart-platform' ),
					),
				),
			);
		}

		$method  = strtoupper( $method );
		$headers = array(
			'Authorization'  => 'Bearer ' . Connect::platform_key(),
			'Stripe-Version' => Stripe::API_VERSION,
		);

		if ( '' !== $account ) {
			$headers['Stripe-Account'] = $account;
		}

		if ( '' !== $idempotency && 'POST' === $method ) {
			$headers['Idempotency-Key'] = $idempotency;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 30,
			'redirection' => 0,
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = Http::flatten( $body );
		}

		$response = wp_remote_request( self::STRIPE_API . ltrim( $path, '/' ), $args );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'platform.stripe',
				'Could not reach Stripe.',
				array(
					'path'  => substr( $path, 0, 120 ),
					'error' => $response->get_error_message(),
				)
			);

			return array(
				'status' => 502,
				'body'   => array(
					'error' => array(
						'type'    => 'api_connection_error',
						'message' => __( 'The payment platform could not reach Stripe. Please try again.', 'wopcart-platform' ),
					),
				),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array(
				'error' => array(
					'type'    => 'api_error',
					'message' => __( 'Stripe returned an unexpected response.', 'wopcart-platform' ),
				),
			);
			$status  = $status >= 400 ? $status : 502;
		}

		if ( $status >= 400 ) {
			// The error type and code only: request and response bodies carry buyer
			// details and are not copied into the platform's log.
			Logger::warning(
				'platform.stripe',
				'Stripe refused a platform request.',
				array(
					'path'   => substr( $path, 0, 120 ),
					'status' => $status,
					'type'   => (string) ( $decoded['error']['type'] ?? '' ),
					'code'   => (string) ( $decoded['error']['code'] ?? '' ),
				)
			);
		}

		return array(
			'status' => $status > 0 ? $status : 502,
			'body'   => $decoded,
		);
	}

	/**
	 * The parts of an Account the merchant needs, and nothing else.
	 *
	 * @param array<string,mixed> $account Account object.
	 *
	 * @return array<string,mixed>
	 */
	private static function summary( array $account ): array {
		$requirements = is_array( $account['requirements'] ?? null ) ? $account['requirements'] : array();

		$list = static function ( mixed $value ): array {
			$items = is_array( $value ) ? array_filter( $value, 'is_string' ) : array();

			return array_slice( array_values( $items ), 0, 50 );
		};

		return array(
			'charges_enabled'   => ! empty( $account['charges_enabled'] ),
			'payouts_enabled'   => ! empty( $account['payouts_enabled'] ),
			'details_submitted' => ! empty( $account['details_submitted'] ),
			'requirements'      => array(
				'currently_due'    => $list( $requirements['currently_due'] ?? null ),
				'past_due'         => $list( $requirements['past_due'] ?? null ),
				'eventually_due'   => $list( $requirements['eventually_due'] ?? null ),
				'disabled_reason'  => is_string( $requirements['disabled_reason'] ?? null ) ? $requirements['disabled_reason'] : '',
				'current_deadline' => (int) ( $requirements['current_deadline'] ?? 0 ),
			),
		);
	}

	/**
	 * Site record columns for an account summary.
	 *
	 * @param array<string,mixed> $summary Output of summary().
	 *
	 * @return array<string,mixed>
	 */
	private static function flags( array $summary ): array {
		return array(
			'charges_enabled'   => (int) $summary['charges_enabled'],
			'payouts_enabled'   => (int) $summary['payouts_enabled'],
			'details_submitted' => (int) $summary['details_submitted'],
			'requirements'      => (string) wp_json_encode( $summary['requirements'] ),
			'updated_at'        => Util::now(),
		);
	}

	/**
	 * Site record columns that revoke a site.
	 *
	 * @return array<string,mixed>
	 */
	private static function revoked(): array {
		return array(
			'status'      => 'disconnected',
			'token_hash'  => '',
			'forward_key' => '',
			'updated_at'  => Util::now(),
		);
	}

	/**
	 * A URL the platform is willing to call or redirect to, or '' when it is not.
	 *
	 * HTTPS on the default port, no credentials, and no host that names the local
	 * network. Resolution to a private address is caught separately, at request time,
	 * by the wp_safe_remote_* functions.
	 *
	 * @param string $raw Candidate URL.
	 *
	 * @return string
	 */
	private static function https_url( string $raw ): string {
		$url = trim( $raw );

		if ( '' === $url || strlen( $url ) > 500 || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return '';
		}

		$host = strtolower( trim( (string) $parts['host'], '[]' ) );

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return '';
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) && false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Lower-case host of a URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function host( string $url ): string {
		$host = '' === $url ? null : wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Stable identity of a site: host plus path, so two stores in subdirectories of
	 * one domain are two sites.
	 *
	 * @param string $site_url Site URL.
	 *
	 * @return string
	 */
	private static function site_key( string $site_url ): string {
		$path = wp_parse_url( $site_url, PHP_URL_PATH );

		return substr( self::host( $site_url ) . rtrim( is_string( $path ) ? $path : '', '/' ), 0, 190 );
	}

	/**
	 * Load a site record by id.
	 *
	 * @param int $id Site id.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function site_by_id( int $id ): ?array {
		return $id > 0 ? self::site_where( 'id = %d', $id ) : null;
	}

	/**
	 * Load a site record by key.
	 *
	 * @param string $key Site key.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function site_by_key( string $key ): ?array {
		return self::site_where( 'site_key = %s', $key );
	}

	/**
	 * Load a site record by connected account.
	 *
	 * @param string $account Account id.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function site_by_account( string $account ): ?array {
		return self::site_where( 'account_id = %s', $account );
	}

	/**
	 * Load one site record.
	 *
	 * @param string     $where Condition with one placeholder; never built from input.
	 * @param int|string $value Placeholder value.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function site_where( string $where, int|string $value ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'platform_sites' ) . ' WHERE ' . $where . ' LIMIT 1', $value ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create a pending site record, or return the one a concurrent request made.
	 *
	 * @param string $key      Site key.
	 * @param string $site_url Site URL.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function create_site( string $key, string $site_url ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::table( 'platform_sites' ),
			array(
				'site_key'   => $key,
				'site_url'   => $site_url,
				'status'     => 'pending',
				'created_at' => Util::now(),
				'updated_at' => Util::now(),
			)
		);

		return self::site_by_key( $key );
	}

	/**
	 * Update a site record.
	 *
	 * @param int                 $id      Site id.
	 * @param array<string,mixed> $changes Columns.
	 *
	 * @return bool Whether the query ran. Zero rows changed is still success.
	 */
	private static function update_site( int $id, array $changes ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( Schema::table( 'platform_sites' ), $changes, array( 'id' => $id ) );
	}

	/**
	 * Validate and normalise the addresses a site sends to /connect/start.
	 *
	 * The site's identity is host plus path, so a host match alone would let one
	 * install on a shared host (shop.com/b) name another (shop.com/a) as its site,
	 * answer the challenge from its own path, and take over the other install's row,
	 * connected account and events. Every address must therefore lie below the site's
	 * own path, and the challenge must be the plugin's own route of that install.
	 *
	 * @param array<string,string> $fields site_url, return_url, refresh_url, webhook_url, challenge_url.
	 *
	 * @return array<string,string>|\WP_Error Normalised URLs, site_url with a trailing slash.
	 */
	public static function validate_urls( array $fields ): array|\WP_Error {
		$site_url = self::site_base( (string) ( $fields['site_url'] ?? '' ) );

		if ( '' === $site_url ) {
			return new \WP_Error( 'wopcart_platform_url', __( 'The site address must be a public HTTPS URL.', 'wopcart-platform' ), array( 'status' => 400 ) );
		}

		$urls = array( 'site_url' => $site_url );

		foreach ( array( 'return_url', 'refresh_url', 'webhook_url', 'challenge_url' ) as $field ) {
			$url = self::https_url( (string) ( $fields[ $field ] ?? '' ) );
			$ok  = 'challenge_url' === $field ? self::is_challenge_url( $url, $site_url ) : self::within_site( $url, $site_url );

			if ( ! $ok ) {
				return new \WP_Error(
					'wopcart_platform_url',
					sprintf(
						/* translators: %s: request field name. */
						__( '%s must be an HTTPS URL below the site address.', 'wopcart-platform' ),
						$field
					),
					array( 'status' => 400 )
				);
			}

			$urls[ $field ] = $url;
		}

		return $urls;
	}

	/**
	 * A site address reduced to scheme, host and path with exactly one trailing slash.
	 *
	 * The trailing slash is what makes the path check a directory check: without it
	 * "/shop" would be a prefix of "/shop-evil".
	 *
	 * @param string $raw Candidate site URL.
	 *
	 * @return string Normalised URL, or '' when unusable.
	 */
	private static function site_base( string $raw ): string {
		$url   = self::https_url( $raw );
		$parts = '' === $url ? false : wp_parse_url( $url );

		if ( ! is_array( $parts ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '' );

		if ( ! self::is_plain_path( $path ) ) {
			return '';
		}

		return 'https://' . self::host( $url ) . rtrim( $path, '/' ) . '/';
	}

	/**
	 * Whether a URL lies on the site's host and below its path.
	 *
	 * @param string $url  URL already passed through https_url().
	 * @param string $base Output of site_base().
	 *
	 * @return bool
	 */
	private static function within_site( string $url, string $base ): bool {
		if ( '' === $url || '' === $base || self::host( $url ) !== self::host( $base ) ) {
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		if ( ! self::is_plain_path( $path ) ) {
			return false;
		}

		$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );

		// The site root itself is allowed with or without its trailing slash.
		return str_starts_with( $path, $base_path ) || $path . '/' === $base_path;
	}

	/**
	 * Whether a URL is exactly the plugin's challenge route of the install at $base.
	 *
	 * Accepts the two shapes rest_url() produces: pretty permalinks
	 * (<site>/wp-json/wopcart/v1/connect/challenge/<id>) and plain permalinks
	 * (<site>/index.php?rest_route=/wopcart/v1/connect/challenge/<id>, the index.php
	 * optional).
	 *
	 * @param string $url  URL already passed through https_url().
	 * @param string $base Output of site_base().
	 *
	 * @return bool
	 */
	private static function is_challenge_url( string $url, string $base ): bool {
		if ( ! self::within_site( $url, $base ) ) {
			return false;
		}

		$parts     = wp_parse_url( $url );
		$base_path = (string) wp_parse_url( $base, PHP_URL_PATH );
		$path      = (string) ( $parts['path'] ?? '' );
		$route     = '/\A' . preg_quote( self::CHALLENGE_ROUTE, '/' ) . '[a-f0-9]{32}\z/';

		if ( isset( $parts['fragment'] ) ) {
			return false;
		}

		if ( ! isset( $parts['query'] ) ) {
			return str_starts_with( $path, $base_path . 'wp-json/' )
				&& 1 === preg_match( $route, substr( $path, strlen( $base_path ) + strlen( 'wp-json' ) ) );
		}

		if ( ! in_array( $path, array( $base_path, $base_path . 'index.php' ), true ) ) {
			return false;
		}

		parse_str( (string) $parts['query'], $query );

		return array( 'rest_route' ) === array_keys( $query )
			&& is_string( $query['rest_route'] )
			&& 1 === preg_match( $route, $query['rest_route'] );
	}

	/**
	 * Whether a URL path is free of anything a server could resolve to another
	 * directory than it literally names: dot segments, encoded slashes or dots,
	 * backslashes and empty segments.
	 *
	 * @param string $path URL path.
	 *
	 * @return bool
	 */
	private static function is_plain_path( string $path ): bool {
		if ( '' === $path ) {
			return true;
		}

		if ( ! str_starts_with( $path, '/' ) || str_contains( $path, '//' ) || str_contains( $path, '\\' ) || 1 === preg_match( '/%(2e|2f|5c)/i', $path ) ) {
			return false;
		}

		return ! in_array( '.', explode( '/', $path ), true ) && ! in_array( '..', explode( '/', $path ), true );
	}

	/**
	 * Whether a value is a connected account id.
	 *
	 * @param string $account Candidate.
	 *
	 * @return bool
	 */
	private static function is_account_id( string $account ): bool {
		return 1 === preg_match( self::ACCOUNT_PATTERN, $account );
	}

	/**
	 * Record and announce that a live connection was handed a new token.
	 *
	 * Re-issuing is legitimate — it is how a merchant who lost their token recovers —
	 * but it also replaces whoever held the old one, so it must never happen silently.
	 * The notice goes to the email on the Stripe account, which the requester cannot
	 * choose, rather than the email in the request, which they can. The platform's
	 * own admin address stands in when Stripe has none.
	 *
	 * @param array<string,mixed> $site        Site record before the change.
	 * @param string              $site_url    Site address.
	 * @param string              $webhook_url New event address.
	 */
	private static function notify_reissue( array $site, string $site_url, string $webhook_url ): void {
		$account = (string) $site['account_id'];

		Logger::warning(
			'platform.connect',
			'A new site token was issued for an already connected site.',
			array(
				'site'             => $site['site_key'],
				'account'          => $account,
				'webhook_changed'  => (string) $site['webhook_url'] !== $webhook_url,
				'previous_updated' => (string) $site['updated_at'],
			)
		);

		$details = self::stripe( 'GET', 'accounts/' . $account );
		$email   = $details['status'] < 300 ? (string) ( $details['body']['email'] ?? '' ) : '';

		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		Mailer::send(
			$email,
			__( 'Your store was reconnected to WopCart payments', 'wopcart-platform' ),
			sprintf(
				/* translators: 1: site address, 2: Stripe account id, 3: date and time (UTC). */
				__( 'The store at %1$s (Stripe account %2$s) was just connected to WopCart payments again, on %3$s UTC. The previous connection\'s credential no longer works.', 'wopcart-platform' ),
				$site_url,
				$account,
				gmdate( 'Y-m-d H:i' )
			) . "\n\n" . __( 'If you did this yourself, no action is needed. If you did not, disconnect Stripe in your store\'s WopCart settings, reconnect it, and contact WopCart support.', 'wopcart-platform' )
		);
	}

	/**
	 * Close the client's connection while PHP keeps running, where the SAPI allows it.
	 *
	 * @return bool Whether the response has been sent and the connection released.
	 */
	private static function finish_response(): bool {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			return fastcgi_finish_request();
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();

			return true;
		}

		return false;
	}

	/**
	 * Count a request against a limit and report whether it is allowed.
	 *
	 * @param string $bucket  Limit name.
	 * @param string $subject Address or site id.
	 * @param int    $limit   Requests allowed per window.
	 * @param int    $window  Window in seconds.
	 *
	 * @return bool
	 */
	private static function within_limit( string $bucket, string $subject, int $limit, int $window ): bool {
		if ( self::limited( $bucket, $subject, $limit ) ) {
			return false;
		}

		self::hit( $bucket, $subject, $window );

		return true;
	}

	/**
	 * Whether a subject has used up a limit.
	 *
	 * @param string $bucket  Limit name.
	 * @param string $subject Address or site id; empty is never limited.
	 * @param int    $limit   Allowed count.
	 *
	 * @return bool
	 */
	private static function limited( string $bucket, string $subject, int $limit ): bool {
		return '' !== $subject && (int) get_transient( self::limit_key( $bucket, $subject ) ) >= $limit;
	}

	/**
	 * Record one hit against a limit.
	 *
	 * @param string $bucket  Limit name.
	 * @param string $subject Address or site id.
	 * @param int    $window  Window in seconds.
	 */
	private static function hit( string $bucket, string $subject, int $window ): void {
		if ( '' === $subject ) {
			return;
		}

		$key = self::limit_key( $bucket, $subject );

		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}

	/**
	 * Transient name for a limit counter.
	 *
	 * @param string $bucket  Limit name.
	 * @param string $subject Subject.
	 *
	 * @return string
	 */
	private static function limit_key( string $bucket, string $subject ): string {
		return 'wopcart_cx_' . md5( $bucket . '|' . $subject );
	}

	/**
	 * A response that is never cached.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param int                 $status HTTP status.
	 *
	 * @return \WP_REST_Response
	 */
	private static function respond( array $body, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * An error in Stripe's own shape, so the merchant's error handling reads it unchanged.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 *
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return self::respond(
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Error response from a WP_Error carrying a status.
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return \WP_REST_Response
	 */
	private static function from_wp_error( \WP_Error $error ): \WP_REST_Response {
		$data = $error->get_error_data();

		return self::error( $error->get_error_code(), $error->get_error_message(), is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400 );
	}

	/**
	 * Error response for a failed Stripe call made on the site's behalf.
	 *
	 * @param array{status:int,body:array<string,mixed>} $result Stripe result.
	 *
	 * @return \WP_REST_Response
	 */
	private static function stripe_failure( array $result ): \WP_REST_Response {
		return self::error(
			'wopcart_platform_stripe',
			(string) ( $result['body']['error']['message'] ?? __( 'Stripe refused the request.', 'wopcart-platform' ) ),
			$result['status'] >= 500 || 0 === $result['status'] ? 502 : 400
		);
	}
}
