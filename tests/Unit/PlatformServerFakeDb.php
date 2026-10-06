<?php
/**
 * $wpdb double for the plan server test.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

namespace WopCartPlatform\Tests\Unit;

/**
 * Holds a single license, for a product that is not the plan product.
 */
final class PlatformServerFakeDb {

	public const KNOWN_KEY = 'AAAAAAAA-BBBBBBBB-CCCCCCCC-DDDDDDDD';

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Interpolate placeholders.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return string
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Return the known license row when its key is asked for.
	 *
	 * @param string $query  Query.
	 * @param string $output Output type, ignored.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, string $output = 'OBJECT' ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches wpdb's signature.
		if ( ! str_contains( $query, self::KNOWN_KEY ) ) {
			return null;
		}

		return array(
			'id'               => 5,
			'license_key'      => self::KNOWN_KEY,
			'product_id'       => 9999,
			'status'           => 'active',
			'activation_limit' => 1,
		);
	}

	/**
	 * No bundle children.
	 *
	 * @param string $query  Query, ignored.
	 * @param string $output Output type, ignored.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches wpdb's signature.
		return array();
	}
}
