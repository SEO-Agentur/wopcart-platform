<?php
/**
 * WordPress stubs needed to drive Server::handle() in the unit suite.
 *
 * Kept out of the shared bootstrap because only the plan server test needs them,
 * and each is the least behaviour that lets the request reach the code under
 * test: no post exists, and request input is taken as given.
 *
 * A copy of the core plugin's file of the same name, which several of its own
 * tests still use. Copied rather than reached across, because the two suites are
 * run separately and neither should fail because the other's fixtures moved.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Post lookup stub: no post exists by ID; a post object passed in is returned as is,
	 * as WordPress does.
	 *
	 * @param mixed $post Post ID or object.
	 *
	 * @return mixed
	 */
	function get_post( mixed $post = null ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $post instanceof \WP_Post ? $post : null;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitising stub: trims only.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	function sanitize_text_field( string $value ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Unslash stub: identity.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $value;
	}
}
