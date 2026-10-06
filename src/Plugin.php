<?php
/**
 * Bootstrap for the platform plugin.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

/**
 * Boots the server-side modules, once it is sure it is allowed to.
 *
 * Static rather than a container like core's Plugin: there is no shared service
 * here, only two modules that register REST routes, so a singleton would be
 * ceremony around nothing.
 *
 * Everything this plugin does leans on the core WopCart plugin — the licence
 * tables the plan server answers from, the Stripe key the proxy signs with, the
 * mailer, the logger. So the whole boot is conditional, and when a condition is
 * not met the plugin says so in the admin and registers nothing at all. A half
 * booted platform is worse than an absent one: merchant sites treat an HTTP error
 * as an outage and keep working, but a route that answers wrongly demotes paying
 * stores or refuses their payments.
 */
final class Plugin {

	/**
	 * Modules that register hooks, in boot order.
	 *
	 * @var string[]
	 */
	private const MODULES = array(
		Server::class,
		ConnectServer::class,
	);

	/**
	 * Boot the plugin, or explain why it is not booting.
	 */
	public static function boot(): void {
		$problem = self::problem();

		if ( '' !== $problem ) {
			add_action(
				'admin_notices',
				static function () use ( $problem ): void {
					self::notice( $problem );
				}
			);

			return;
		}

		Schema::maybe_install();

		// Cheap: the cron array is an autoloaded option WordPress has already read.
		// Checked on every request rather than only on activation because this
		// plugin is updated by replacing its directory, which never fires an
		// activation hook, and a lost delivery schedule would silently stop every
		// merchant site receiving Stripe events.
		self::schedule_cron();

		foreach ( self::MODULES as $module ) {
			$instance = new $module();
			$instance->hooks();
		}
	}

	/**
	 * Create this plugin's tables and schedule its cron on activation.
	 */
	public static function activate(): void {
		if ( '' !== self::problem() ) {
			return;
		}

		Schema::install();
		self::schedule_cron();
	}

	/**
	 * Clear this plugin's cron on deactivation. Data is deliberately left intact.
	 *
	 * The two tables hold every merchant's connected account and the events still
	 * queued for them. Deactivating the plugin for ten minutes during a deployment
	 * must not lose that.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( ConnectServer::DELIVERY_HOOK );
	}

	/**
	 * Why this plugin cannot run, or an empty string when it can.
	 *
	 * Public so the suite can pin each refusal. There is one install of this plugin
	 * and no way to try it somewhere safe first, so the conditions that keep it from
	 * booting are worth asserting rather than trusting.
	 *
	 * @return string Human-readable reason, already translated.
	 */
	public static function problem(): string {
		if ( ! class_exists( '\WopCart\Plugin' ) ) {
			return __( 'WopCart Platform needs the WopCart plugin, which is not active on this site.', 'wopcart-platform' );
		}

		if ( ! \WopCart\Plugin::supports( MIN_CORE ) ) {
			return sprintf(
				/* translators: %s: minimum WopCart version. */
				__( 'WopCart Platform needs WopCart %s or newer.', 'wopcart-platform' ),
				MIN_CORE
			);
		}

		// The server half used to ship inside WopCart itself. Both halves registering
		// /plan/* and /platform/connect/* would mean two handlers racing for the same
		// routes, with whichever registered last quietly winning. There is no version
		// that expresses this, because the removal and this plugin are one change, so
		// the question is asked of the class directly.
		if ( class_exists( '\WopCart\Platform\Server' ) ) {
			return __( 'WopCart Platform is standing by: this copy of WopCart still serves the platform routes itself. Update WopCart and these routes move over on the next request.', 'wopcart-platform' );
		}

		return '';
	}

	/**
	 * Show the reason to somebody who can act on it.
	 *
	 * @param string $problem Reason from problem().
	 */
	private static function notice( string $problem ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $problem ) );
	}

	/**
	 * Make sure the event-forwarding queue is drained on a schedule.
	 *
	 * The same hook and recurrence core used to register, so an existing schedule on
	 * wopcart.com is recognised and left alone rather than duplicated.
	 */
	private static function schedule_cron(): void {
		if ( false !== wp_next_scheduled( ConnectServer::DELIVERY_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), 'wopcart_fifteen_minutes', ConnectServer::DELIVERY_HOOK );
	}

	/**
	 * Absolute path inside the plugin directory.
	 *
	 * @param string $relative Relative path.
	 *
	 * @return string
	 */
	public static function path( string $relative = '' ): string {
		return DIR . '/' . ltrim( $relative, '/' );
	}
}
