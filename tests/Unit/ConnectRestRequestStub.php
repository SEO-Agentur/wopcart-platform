<?php
/**
 * WP_REST_Request double for the Connect unit tests.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress core class name.
	/**
	 * Request double holding parameters, headers and a body.
	 */
	class WP_REST_Request {

		/**
		 * Parameters.
		 *
		 * @var array<string,mixed>
		 */
		private array $params = array();

		/**
		 * Headers, keyed as WordPress normalises them (lower case, underscores).
		 *
		 * @var array<string,string>
		 */
		private array $headers = array();

		/**
		 * Raw body.
		 *
		 * @var string
		 */
		private string $body = '';

		/**
		 * Set a parameter.
		 *
		 * @param string $key   Name.
		 * @param mixed  $value Value.
		 */
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * Read a parameter.
		 *
		 * @param string $key Name.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Set a header.
		 *
		 * @param string $key   Name.
		 * @param string $value Value.
		 */
		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
		}

		/**
		 * Read a header.
		 *
		 * @param string $key Name.
		 *
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] ?? null;
		}

		/**
		 * Set the raw body.
		 *
		 * @param string $body Body.
		 */
		public function set_body( string $body ): void {
			$this->body = $body;
		}

		/**
		 * Read the raw body.
		 *
		 * @return string
		 */
		public function get_body(): string {
			return $this->body;
		}
	}
}
