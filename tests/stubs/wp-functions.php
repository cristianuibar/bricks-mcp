<?php
/**
 * WordPress function stubs for unit tests without WordPress.
 *
 * Defined in the GLOBAL namespace so that namespaced plugin code
 * (BricksMCP\MCP\*, BricksMCP\Admin\*, etc.) can find them via
 * PHP's namespace fallback resolution.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

// ---------------------------------------------------------------------------
// WordPress time constants.
// ---------------------------------------------------------------------------
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------------------------------------------------------------------------
// Controllable globals — tests set these in setUp() to drive stub behavior.
// ---------------------------------------------------------------------------
$GLOBALS['_bricks_mcp_test_cache']            = [];
$GLOBALS['_bricks_mcp_test_settings']         = [];
$GLOBALS['_bricks_mcp_test_ext_object_cache'] = true;
$GLOBALS['_bricks_mcp_test_transients']       = [];

// ---------------------------------------------------------------------------
// WP_Error class.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		/** @var string */
		public string $code;
		/** @var string */
		public string $message;
		/** @var mixed */
		public mixed $data;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Additional data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ): string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub.
	 */
	class WP_REST_Response {
		/** @var mixed */
		protected mixed $data;

		/** @var int */
		protected int $status;

		/** @var array<string, string> */
		protected array $headers = [];

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

// ---------------------------------------------------------------------------
// Core WordPress functions.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		if ( 'bricks_mcp_settings' === $option ) {
			return $GLOBALS['_bricks_mcp_test_settings'] ?? $default;
		}
		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value ): bool {
		if ( 'bricks_mcp_settings' === $option ) {
			$GLOBALS['_bricks_mcp_test_settings'] = $value;
		}
		return true;
	}
}

// ---------------------------------------------------------------------------
// Object cache functions.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		$full_key = $group . ':' . $key;
		if ( array_key_exists( $full_key, $GLOBALS['_bricks_mcp_test_cache'] ) ) {
			return false;
		}
		$GLOBALS['_bricks_mcp_test_cache'][ $full_key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( string $key, int $offset = 1, string $group = '' ): int|false {
		$full_key = $group . ':' . $key;
		if ( ! array_key_exists( $full_key, $GLOBALS['_bricks_mcp_test_cache'] ) ) {
			return false;
		}
		$GLOBALS['_bricks_mcp_test_cache'][ $full_key ] += $offset;
		return $GLOBALS['_bricks_mcp_test_cache'][ $full_key ];
	}
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush(): bool {
		$GLOBALS['_bricks_mcp_test_cache'] = [];
		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache( ?bool $using = null ): bool {
		if ( null !== $using ) {
			$GLOBALS['_bricks_mcp_test_ext_object_cache'] = $using;
		}
		return (bool) ( $GLOBALS['_bricks_mcp_test_ext_object_cache'] ?? false );
	}
}

// ---------------------------------------------------------------------------
// Transient functions.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['_bricks_mcp_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['_bricks_mcp_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['_bricks_mcp_test_transients'][ $transient ] );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Error / sanitization / i18n helpers.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		// No-op in tests.
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return 0;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		$GLOBALS['_bricks_mcp_test_deleted_files'][] = $file;
		if ( file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( array|string $args, array $defaults = [] ): array {
		if ( is_string( $args ) ) {
			parse_str( $args, $args );
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array {
		$GLOBALS['_bricks_mcp_test_last_get_posts_args'] = $args;
		return $GLOBALS['_bricks_mcp_test_get_posts_return'] ?? [];
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = [] ): array {
		$GLOBALS['_bricks_mcp_test_last_get_users_args'] = $args;
		return $GLOBALS['_bricks_mcp_test_get_users_return'] ?? [];
	}
}

if ( ! function_exists( 'update_postmeta_cache' ) ) {
	function update_postmeta_cache( array $post_ids ): void {
		// No-op in tests.
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( array $input_list, string $field ): array {
		$output = [];
		foreach ( $input_list as $item ) {
			if ( is_object( $item ) ) {
				$output[] = $item->$field ?? null;
			} elseif ( is_array( $item ) ) {
				$output[] = $item[ $field ] ?? null;
			}
		}
		return $output;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string {
		return 'https://example.com/?p=' . $post_id;
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( int $post_id, string $size = 'thumbnail' ): string|false {
		return false;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): string|int|array|null|false {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'download_url' ) ) {
	function download_url( string $url, int $timeout = 300 ): string|\WP_Error {
		return $GLOBALS['_bricks_mcp_test_download_url_return'] ?? '/tmp/test-file.jpg';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $url ): string|false {
		return $GLOBALS['_bricks_mcp_test_wp_http_validate_url_return'] ?? $url;
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	function wp_safe_remote_get( string $url, array $args = [] ): array|\WP_Error {
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'][] = [ 'url' => $url, 'args' => $args ];
		return $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] ?? [];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int|string {
		return $response['response']['code'] ?? 200;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array|\WP_Error $response, string $name ): string {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$headers = $response['headers'] ?? array();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				if ( strtolower( (string) $key ) === strtolower( $name ) ) {
					return is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
				}
			}
		}
		return $GLOBALS['_bricks_mcp_test_wp_remote_retrieve_header_return'] ?? 'application/json';
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['_bricks_mcp_test_current_user_can'] ?? true;
	}
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub.
	 */
	class WP_REST_Request {
		/** @var array<string, string> */
		private array $headers = [];
		/** @var string */
		private string $body = '';

		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}

		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}
	}
}
// phpcs:enable
