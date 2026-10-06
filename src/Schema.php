<?php
/**
 * The two tables the platform owns.
 *
 * Both used to be created by the core plugin on every merchant site, where they
 * stayed empty forever: only the platform ever writes to them. They moved here
 * with their statements unchanged, down to the column order, so dbDelta sees the
 * live tables on wopcart.com as already correct and alters nothing.
 *
 * The statements are written for dbDelta, which is unusually strict: one field per
 * line, two spaces after PRIMARY KEY, and KEY instead of INDEX.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform;

use WopCart\Support\Tables;

/**
 * Produces and applies the CREATE TABLE statements for the platform's tables.
 */
final class Schema {

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'wopcart_platform_db_version';

	/**
	 * Logical table keys this plugin owns.
	 *
	 * @var string[]
	 */
	public const KEYS = array( 'platform_sites', 'platform_deliveries' );

	/**
	 * Fully qualified name for one of this plugin's tables.
	 *
	 * The prefix is core's, deliberately: these tables were created by core on
	 * wopcart.com years of rows ago, and the point of the move is that the data is
	 * found exactly where it already is. Taking the prefix from the constant rather
	 * than repeating the literal means the two can never drift apart.
	 *
	 * @param string $key One of self::KEYS.
	 *
	 * @return string
	 */
	public static function table( string $key ): string {
		global $wpdb;

		return $wpdb->prefix . Tables::PREFIX . $key;
	}

	/**
	 * Every table this plugin owns.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array_map( array( self::class, 'table' ), self::KEYS );
	}

	/**
	 * Run dbDelta over both definitions.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Create the tables when the stored schema version is not the current one.
	 *
	 * Called on every boot and not only on activation, because this plugin is
	 * deployed by replacing its directory, which never fires an activation hook.
	 * The version option is only written once both tables are really there, so a
	 * database user without CREATE privilege is retried on the next request rather
	 * than recorded as done — the same rule core's migration steps follow.
	 */
	public static function maybe_install(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === DB_VERSION ) {
			return;
		}

		self::install();

		if ( ! Tables::exist( self::tables() ) ) {
			return;
		}

		update_option( self::VERSION_OPTION, DB_VERSION, true );
	}

	/**
	 * Both CREATE TABLE statements keyed by logical table name.
	 *
	 * @return array<string,string>
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		return array(
			'platform_sites'      => self::platform_sites( $collate ),
			'platform_deliveries' => self::platform_deliveries( $collate ),
		);
	}

	/**
	 * Merchant sites connected to the Stripe Connect platform.
	 *
	 * The site token is stored as a hash only. forward_key is the key that signs events
	 * sent back to the site; it has to be readable to sign retries, which is why it is a
	 * one-way derivative of the token rather than the token itself. Account ids are not
	 * unique at the database level because rows still onboarding have none yet.
	 *
	 * @param string $collate Charset/collation clause.
	 *
	 * @return string
	 */
	private static function platform_sites( string $collate ): string {
		$table = self::table( 'platform_sites' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_key varchar(190) NOT NULL DEFAULT '',
			site_url varchar(500) NOT NULL DEFAULT '',
			account_id varchar(64) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL DEFAULT '',
			forward_key char(64) NOT NULL DEFAULT '',
			webhook_url varchar(500) NOT NULL DEFAULT '',
			return_url varchar(500) NOT NULL DEFAULT '',
			refresh_url varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			charges_enabled tinyint(1) NOT NULL DEFAULT 0,
			payouts_enabled tinyint(1) NOT NULL DEFAULT 0,
			details_submitted tinyint(1) NOT NULL DEFAULT 0,
			requirements longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_event_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_key (site_key),
			KEY account_id (account_id),
			KEY status (status)
		) {$collate};";
	}

	/**
	 * Stripe events queued for forwarding to merchant sites.
	 *
	 * Unique per site and event, so Stripe redelivering an event to the platform
	 * cannot send the merchant a second copy.
	 *
	 * @param string $collate Charset/collation clause.
	 *
	 * @return string
	 */
	private static function platform_deliveries( string $collate ): string {
		$table = self::table( 'platform_deliveries' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id varchar(64) NOT NULL DEFAULT '',
			event_type varchar(100) NOT NULL DEFAULT '',
			payload longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int(11) NOT NULL DEFAULT 0,
			response_code int(11) NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_event (site_id,event_id),
			KEY status_next (status,next_attempt_at),
			KEY created_at (created_at)
		) {$collate};";
	}
}
