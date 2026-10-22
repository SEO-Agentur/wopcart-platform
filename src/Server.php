<?php
/**
 * Plan verification endpoints, served by the platform itself.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

use WopCart\Contracts\Bootable;
use WopCart\Licensing\Api;
use WopCart\Licensing\License;
use WopCart\Licensing\LicenseKeys;
use WopCart\Licensing\LicenseManager;
use WopCart\Support\Logger;
use WopCart\Support\Util;

/**
 * The other half of Plan: answers merchant installs asking whether their plan key
 * is good, and counts the sites each key is used on.
 *
 * A plan key is an ordinary WopCart licence key sold by wopcart.com's own store,
 * so seat counting, expiry and bundles are exactly the Licensing module's. What
 * this class adds is a narrow, stable contract for the plan client: three routes,
 * one boolean the client acts on, and error codes that line up with the licensing
 * API so the same words mean the same thing on both surfaces.
 *
 * It only exists on the platform. A merchant install is itself a WopCart store and
 * may well sell software, but it must never answer plan questions — otherwise any
 * store could be pointed at as a plan server. The platform identifies itself by
 * defining WOPCART_PLATFORM_PRODUCT_ID (the product that plan keys are issued for);
 * without that constant not a single route is registered.
 *
 * Deliberately mirrors the protections of Licensing\Api rather than calling into
 * it: the key is the credential, malformed and unknown keys are indistinguishable,
 * callers are rate limited per address and nothing is cacheable.
 */
final class Server implements Bootable {

	/**
	 * Verdict codes this server emits, as the clients know them.
	 *
	 * Copied rather than imported. They used to come from `WopCart\Platform\PlanVerdict`
	 * in the core plugin, which core 1.4.0 deleted along with the rest of the plan
	 * logic — leaving this file referencing a class that no longer exists, so the
	 * plan route would fatal the moment a site still on 1.3.x called it. The codes
	 * are part of the wire protocol those sites speak, so they are written out here
	 * and must not change.
	 */
	private const VERDICT_SITE_INACTIVE = 'site_inactive';

	/**
	 * A contended seat lock. Sent as HTTP 503 so clients read it as an outage.
	 */
	private const VERDICT_BUSY = 'busy';


	/**
	 * REST namespace, shared with the licensing API.
	 */
	public const NAMESPACE = Api::NAMESPACE;

	/**
	 * Requests allowed per address per window.
	 */
	private const RATE_LIMIT = 60;

	/**
	 * Rate limit window, in seconds.
	 */
	private const RATE_WINDOW = 300;

	/**
	 * Whether this install is the WopCart platform.
	 *
	 * @return bool
	 */
	public static function is_platform_site(): bool {
		return self::product_id() > 0;
	}

	/**
	 * The product plan keys are issued for, or zero on every merchant install.
	 *
	 * A constant rather than a setting: a value in the options table can be set by
	 * anything with admin access or an import, and turning a merchant store into a
	 * plan server should take a deliberate edit to wp-config.php.
	 *
	 * @return int
	 */
	public static function product_id(): int {
		return defined( 'WOPCART_PLATFORM_PRODUCT_ID' ) ? max( 0, (int) constant( 'WOPCART_PLATFORM_PRODUCT_ID' ) ) : 0;
	}

	/**
	 * Register hooks — only on the platform.
	 */
	public function hooks(): void {
		if ( ! self::is_platform_site() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the plan routes.
	 */
	public function register_routes(): void {
		$args = array(
			'key'     => array(
				'type'     => 'string',
				'required' => true,
			),
			'site'    => array(
				'type'     => 'string',
				'required' => true,
			),
			'version' => array( 'type' => 'string' ),
		);

		foreach ( array( 'activate', 'check', 'deactivate' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/plan/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
						return $this->respond(
							$this->handle(
								$action,
								array(
									'key'     => (string) ( $request->get_param( 'key' ) ?? '' ),
									'site'    => (string) ( $request->get_param( 'site' ) ?? '' ),
									'version' => (string) ( $request->get_param( 'version' ) ?? '' ),
								)
							)
						);
					},
					// Public by design: the plan key is the credential.
					'permission_callback' => '__return_true',
					'args'                => $args,
				)
			);
		}
	}

	/**
	 * Run one plan action.
	 *
	 * @param string               $action activate|check|deactivate.
	 * @param array<string,string> $params key, site, version.
	 *
	 * @return array<string,mixed>
	 */
	public function handle( string $action, array $params ): array {
		if ( ! self::within_rate_limit() ) {
			// Sent with HTTP 429 by respond(). The client treats that as "no answer"
			// and keeps its current plan, which is the point: being throttled is
			// not a verdict on anybody's key.
			return self::error( 'rate_limited', __( 'Too many requests. Try again shortly.', 'wopcart-platform' ) );
		}

		$key = LicenseKeys::normalize( $params['key'] ?? '' );

		if ( ! LicenseKeys::is_well_formed( $key ) ) {
			// Identical to the unknown-key response below, so a probe cannot tell
			// a malformed guess from a well-formed key that does not exist.
			return self::error( 'missing_license', __( 'That plan key was not recognised.', 'wopcart-platform' ) );
		}

		$license = License::find_by_key( $key );

		if ( null === $license ) {
			self::record_failure();

			return self::error( 'missing_license', __( 'That plan key was not recognised.', 'wopcart-platform' ) );
		}

		// Any licence sold on the platform is a well-formed key that exists. Only
		// one for the plan product — directly or through a bundle — may unlock a
		// plan, or a key for some unrelated download would do.
		//
		// The refusal is the unknown-key response word for word, and counts as a
		// failed lookup. A distinct code told a prober that a guessed key was real
		// and merely for another product, which is exactly the oracle the
		// malformed/unknown parity above exists to deny. The licensing API keeps
		// invalid_item_id because EDD-style clients depend on it; plan clients do
		// not, and a merchant pasting the wrong key is told to check their account.
		if ( ! Api::covers_product( $license, self::product_id() ) ) {
			self::record_failure();

			return self::error( 'missing_license', __( 'That plan key was not recognised.', 'wopcart-platform' ) );
		}

		$site = (string) ( $params['site'] ?? '' );

		if ( '' === LicenseKeys::normalize_site( $site ) ) {
			return self::error( 'missing_url', __( 'A site address is required.', 'wopcart-platform' ), $license );
		}

		return match ( $action ) {
			'activate'   => $this->activate( $license, $site, (string) ( $params['version'] ?? '' ) ),
			'deactivate' => $this->deactivate( $license, $site ),
			default      => $this->check( $license, $site, (string) ( $params['version'] ?? '' ) ),
		};
	}

	/**
	 * Take a seat for a site.
	 *
	 * @param License $license License.
	 * @param string  $site    Site URL.
	 * @param string  $version Plugin version reported.
	 *
	 * @return array<string,mixed>
	 */
	private function activate( License $license, string $site, string $version ): array {
		$license->touch( $version );

		// Disabled and expired are also enforced inside activate(), but checked in
		// the same order here so the codes match /plan/check exactly.
		$refusal = self::refusal( $license );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$result = LicenseManager::activate( $license, $site, $version );

		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_code(), $result->get_error_message(), $license );
		}

		return self::success( $license, $site, array( 're_activated' => ! empty( $result['re_activated'] ) ) );
	}

	/**
	 * Confirm a key is good *for this site*, without creating anything.
	 *
	 * A valid key alone is not enough: that is precisely the loophole that let one
	 * single-site key unlock any number of stores.
	 *
	 * @param License $license License.
	 * @param string  $site    Site URL.
	 * @param string  $version Plugin version reported.
	 *
	 * @return array<string,mixed>
	 */
	private function check( License $license, string $site, string $version ): array {
		$license->touch( $version );

		$refusal = self::refusal( $license );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$activation = $license->activation_for( $site );

		if ( null === $activation || empty( $activation['is_active'] ) ) {
			return self::error(
				self::VERDICT_SITE_INACTIVE,
				__( 'This plan key is valid but is not activated for this site.', 'wopcart-platform' ),
				$license
			);
		}

		return self::success( $license, $site );
	}

	/**
	 * Free a site's seat.
	 *
	 * Allowed even on an expired or disabled key: releasing a seat never grants
	 * anything, and refusing it would leave a lapsed customer unable to tidy up
	 * before renewing.
	 *
	 * @param License $license License.
	 * @param string  $site    Site URL.
	 *
	 * @return array<string,mixed>
	 */
	private function deactivate( License $license, string $site ): array {
		$result = LicenseManager::deactivate( $license, $site );

		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_code(), $result->get_error_message(), $license );
		}

		return self::success( $license, $site, array( 'deactivated' => true ) );
	}

	/**
	 * The refusal for a key that cannot hold a plan, if any.
	 *
	 * @param License $license License.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function refusal( License $license ): ?array {
		$status = $license->status();

		if ( License::DISABLED === $status ) {
			return self::error( 'license_disabled', __( 'This plan key has been disabled.', 'wopcart-platform' ), $license );
		}

		if ( License::EXPIRED === $status ) {
			return self::error( 'license_expired', __( 'This plan has expired and must be renewed.', 'wopcart-platform' ), $license );
		}

		return null;
	}

	/**
	 * Build a success payload.
	 *
	 * @param License             $license License.
	 * @param string              $site    Site URL.
	 * @param array<string,mixed> $extra   Extra fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function success( License $license, string $site, array $extra = array() ): array {
		return array_merge(
			array(
				'valid' => true,
				'site'  => LicenseKeys::normalize_site( $site ),
			),
			self::seats( $license ),
			$extra
		);
	}

	/**
	 * Build a refusal payload.
	 *
	 * Seat and expiry figures ride along once a real key has been resolved, so the
	 * client can say "in use on 5 of 5 sites" or "expired on 3 March" rather than
	 * a bare code. They are never included for an unknown key.
	 *
	 * @param string       $code    Stable machine-readable code.
	 * @param string       $message Human message.
	 * @param License|null $license License, when one was resolved.
	 *
	 * @return array<string,mixed>
	 */
	private static function error( string $code, string $message, ?License $license = null ): array {
		$payload = array(
			'valid'   => false,
			'error'   => $code,
			'message' => $message,
		);

		return null === $license ? $payload : array_merge( $payload, self::seats( $license ) );
	}

	/**
	 * Seat and term figures.
	 *
	 * @param License $license License.
	 *
	 * @return array{seats_limit:int,seats_used:int,expires_at:string|null}
	 */
	private static function seats( License $license ): array {
		$expires = $license->expires_at();

		return array(
			// Zero means unlimited, matching the licensing API.
			'seats_limit' => $license->activation_limit(),
			'seats_used'  => $license->activation_count(),
			'expires_at'  => $expires > 0 ? gmdate( 'c', $expires ) : null,
		);
	}

	/**
	 * Wrap a payload in a REST response that is never cached.
	 *
	 * Refusals are HTTP 200 on purpose: the client only demotes on an explicit
	 * `valid: false` in a successful response, and treats any HTTP error as an
	 * outage. Rate limiting and a contended seat lock are the refusals that must
	 * read as an outage — neither is a verdict on the key. They are sent as HTTP
	 * errors so that clients released before the `busy` code existed
	 * still keep their plan, and the code is also listed as transient there in
	 * case a proxy rewrites the status.
	 *
	 * @param array<string,mixed> $payload Response payload.
	 *
	 * @return \WP_REST_Response
	 */
	private function respond( array $payload ): \WP_REST_Response {
		$status = match ( $payload['error'] ?? '' ) {
			'rate_limited'     => 429,
			self::VERDICT_BUSY => 503,
			default            => 200,
		};

		$response = new \WP_REST_Response( $payload, $status );

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		if ( 503 === $status ) {
			$response->header( 'Retry-After', '5' );
		}

		return $response;
	}

	/**
	 * Whether the caller is inside the rate limit.
	 *
	 * Counted per address, so guessing many keys from one host is bounded even
	 * though each individual key looks new. Separate counters from the licensing
	 * API, so a busy customer updater cannot throttle plan checks or vice versa.
	 *
	 * @return bool
	 */
	private static function within_rate_limit(): bool {
		$ip = Util::ip();

		if ( '' === $ip ) {
			return true;
		}

		// Atomic counter: a transient read-then-write let parallel bursts through.
		return \WopCart\Security\RateLimiter::hit( 'platform_plan', $ip, self::RATE_LIMIT, self::RATE_WINDOW );
	}

	/**
	 * Record a failed lookup and log a sustained run of them.
	 */
	private static function record_failure(): void {
		$ip = Util::ip();

		if ( '' === $ip ) {
			return;
		}

		$transient = 'wopcart_plan_miss_' . md5( $ip );
		$misses    = (int) get_transient( $transient ) + 1;

		set_transient( $transient, $misses, self::RATE_WINDOW );

		if ( 20 === $misses ) {
			Logger::warning(
				'platform',
				'Repeated invalid plan key lookups from one address.',
				array(
					'ip'     => $ip,
					'misses' => $misses,
				)
			);
		}
	}
}
