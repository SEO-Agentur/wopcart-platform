<?php
/**
 * WP_REST_Response double for the Connect unit tests.
 *
 * @package WopCartPlatform
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Response' ) ) {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WordPress core class name.
	/**
	 * Response double exposing what a callback returned.
	 */
	class WP_REST_Response {

		/**
		 * Body.
		 *
		 * @var mixed
		 */
		private mixed $data;

		/**
		 * HTTP status.
		 *
		 * @var int
		 */
		private int $status;

		/**
		 * Headers set.
		 *
		 * @var array<string,string>
		 */
		private array $headers = array();

		/**
		 * Build a response.
		 *
		 * @param mixed $data   Body.
		 * @param int   $status HTTP status.
		 */
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Set a header.
		 *
		 * @param string $key   Name.
		 * @param string $value Value.
		 */
		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Headers that were set.
		 *
		 * @return array<string,string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}

		/**
		 * The body.
		 *
		 * @return mixed
		 */
		public function get_data(): mixed {
			return $this->data;
		}

		/**
		 * Replace the body.
		 *
		 * @param mixed $data Body.
		 */
		public function set_data( mixed $data ): void {
			$this->data = $data;
		}

		/**
		 * The HTTP status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}
