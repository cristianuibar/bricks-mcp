<?php
/**
 * Admin-specific WordPress function stubs for unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

/**
 * Reset admin stub globals between tests.
 *
 * @return void
 */
function bricks_mcp_test_reset_admin(): void {
	$GLOBALS['_bricks_mcp_test_wp_die']              = null;
	$GLOBALS['_bricks_mcp_test_redirect']            = null;
	$GLOBALS['_bricks_mcp_test_referer']             = false;
	$GLOBALS['_bricks_mcp_test_wp_remote_get_calls'] = [];
	$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = [ 'response' => [ 'code' => 200 ], 'body' => '' ];
	$GLOBALS['_bricks_mcp_test_wp_remote_get_responses'] = [];
	$GLOBALS['_bricks_mcp_test_registered_settings'] = [];
	$GLOBALS['_bricks_mcp_test_json_response']       = null;
	$GLOBALS['_bricks_mcp_test_is_ssl']              = false;
	$GLOBALS['_bricks_mcp_test_environment_type']    = 'production';
	$GLOBALS['_bricks_mcp_test_app_passwords_available'] = false;
}

bricks_mcp_test_reset_admin();

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub esc_attr.
	 *
	 * @param string $text Attribute text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub esc_html_e.
	 *
	 * @param string $text   Text to output.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Stub esc_attr_e.
	 *
	 * @param string $text   Attribute text to output.
	 * @param string $domain Text domain.
	 * @return void
	 */
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Stub checked.
	 *
	 * @param mixed $checked First value.
	 * @param mixed $current Second value.
	 * @param bool  $display Whether to echo.
	 * @return string
	 */
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Stub rest_url.
	 *
	 * @param string $path REST route path.
	 * @return string
	 */
	function rest_url( string $path = '' ): string {
		return 'http://localhost/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub wp_unslash.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub wp_create_nonce.
	 *
	 * @param string|int $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( string|int $action = -1 ): string {
		return 'nonce_' . (string) $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Stub wp_verify_nonce.
	 *
	 * @param string     $nonce  Nonce token.
	 * @param string|int $action Nonce action.
	 * @return int|false
	 */
	function wp_verify_nonce( string $nonce, string|int $action = -1 ): int|false {
		return hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * Stub wp_nonce_url.
	 *
	 * @param string     $actionurl Base URL.
	 * @param string|int $action    Nonce action.
	 * @param string     $name      Query arg name.
	 * @return string
	 */
	function wp_nonce_url( string $actionurl, string|int $action = -1, string $name = '_wpnonce' ): string {
		$sep = str_contains( $actionurl, '?' ) ? '&' : '?';
		return $actionurl . $sep . $name . '=' . wp_create_nonce( $action );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Stub check_admin_referer.
	 *
	 * @param string|int $action    Nonce action.
	 * @param string     $query_arg Query argument name.
	 * @return int|false
	 */
	function check_admin_referer( string|int $action = -1, string $query_arg = '_wpnonce' ): int|false {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_REQUEST[ $query_arg ] ) ? (string) $_REQUEST[ $query_arg ] : '';
		return wp_verify_nonce( $nonce, $action );
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * Stub check_ajax_referer.
	 *
	 * @param string|int   $action    Nonce action.
	 * @param string|false $query_arg Query argument name.
	 * @return int|false
	 */
	function check_ajax_referer( string|int $action = -1, string|false $query_arg = false ): int|false {
		$key = false !== $query_arg ? $query_arg : '_ajax_nonce';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_REQUEST[ $key ] ) ? (string) $_REQUEST[ $key ] : '';
		return wp_verify_nonce( $nonce, $action );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Stub wp_die.
	 *
	 * @param string          $message Error message.
	 * @param string|int      $title   Title or HTTP code.
	 * @param array<mixed>|int $args   Optional args or HTTP code.
	 * @return void
	 */
	function wp_die( string $message = '', string|int $title = '', array|int $args = [] ): void {
		$code                                = is_int( $title ) ? $title : ( is_int( $args ) ? $args : 500 );
		$GLOBALS['_bricks_mcp_test_wp_die'] = [
			'message' => $message,
			'code'    => $code,
		];
	}
}

if ( ! function_exists( 'wp_get_referer' ) ) {
	/**
	 * Stub wp_get_referer.
	 *
	 * @return string|false
	 */
	function wp_get_referer(): string|false {
		return $GLOBALS['_bricks_mcp_test_referer'] ?? false;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Stub wp_safe_redirect.
	 *
	 * @param string $location Target URL.
	 * @param int    $status   HTTP status code.
	 * @return bool
	 */
	function wp_safe_redirect( string $location, int $status = 302 ): bool {
		$GLOBALS['_bricks_mcp_test_redirect'] = [
			'location' => $location,
			'status'   => $status,
		];
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub wp_remote_get.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request args.
	 * @return array<string, mixed>|\WP_Error
	 */
	function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
		$GLOBALS['_bricks_mcp_test_wp_remote_get_calls'][] = [
			'url'  => $url,
			'args' => $args,
		];
		if ( isset( $GLOBALS['_bricks_mcp_test_wp_remote_get_responses'][ $url ] ) ) {
			return $GLOBALS['_bricks_mcp_test_wp_remote_get_responses'][ $url ];
		}
		return $GLOBALS['_bricks_mcp_test_wp_remote_get_return'] ?? [
			'response' => [ 'code' => 200 ],
			'body'     => '',
		];
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	/**
	 * Stub is_ssl.
	 *
	 * @return bool
	 */
	function is_ssl(): bool {
		return (bool) ( $GLOBALS['_bricks_mcp_test_is_ssl'] ?? false );
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Stub wp_get_environment_type.
	 *
	 * @return string
	 */
	function wp_get_environment_type(): string {
		return (string) ( $GLOBALS['_bricks_mcp_test_environment_type'] ?? 'production' );
	}
}

if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
	/**
	 * Stub wp_is_application_passwords_available.
	 *
	 * @return bool
	 */
	function wp_is_application_passwords_available(): bool {
		return (bool) ( $GLOBALS['_bricks_mcp_test_app_passwords_available'] ?? false );
	}
}

if ( ! function_exists( 'wp_update_plugins' ) ) {
	/**
	 * Stub wp_update_plugins: no remote plugin update check in unit tests.
	 *
	 * @return void
	 */
	function wp_update_plugins(): void {}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Stub wp_send_json_success.
	 *
	 * @param mixed    $data        Response payload.
	 * @param int|null $status_code HTTP status code.
	 * @return void
	 */
	function wp_send_json_success( mixed $data = null, ?int $status_code = null ): void {
		$GLOBALS['_bricks_mcp_test_json_response'] = [
			'success' => true,
			'data'    => $data,
			'status'  => $status_code ?? 200,
		];
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Stub wp_send_json_error.
	 *
	 * @param mixed    $data        Error payload.
	 * @param int|null $status_code HTTP status code.
	 * @return void
	 */
	function wp_send_json_error( mixed $data = null, ?int $status_code = null ): void {
		$GLOBALS['_bricks_mcp_test_json_response'] = [
			'success' => false,
			'data'    => $data,
			'status'  => $status_code ?? 400,
		];
	}
}

if ( ! function_exists( 'get_admin_page_title' ) ) {
	/**
	 * Stub get_admin_page_title.
	 *
	 * @return string
	 */
	function get_admin_page_title(): string {
		return 'MCP Extensions';
	}
}

if ( ! function_exists( 'settings_errors' ) ) {
	/**
	 * Stub settings_errors.
	 *
	 * @return void
	 */
	function settings_errors(): void {
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	/**
	 * Stub settings_fields.
	 *
	 * @param string $option_group Option group.
	 * @return void
	 */
	function settings_fields( string $option_group ): void {
		echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '">';
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	/**
	 * Stub submit_button.
	 *
	 * @param string|null $text Button text.
	 * @return void
	 */
	function submit_button( ?string $text = null ): void {
		echo '<button type="submit">' . esc_html( $text ?? 'Save Changes' ) . '</button>';
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	/**
	 * Stub register_setting.
	 *
	 * @param string               $option_group Option group.
	 * @param string               $option_name  Option name.
	 * @param array<string, mixed> $args         Setting arguments.
	 * @return void
	 */
	function register_setting( string $option_group, string $option_name, array $args = [] ): void {
		$GLOBALS['_bricks_mcp_test_registered_settings'][ $option_name ] = [
			'group' => $option_group,
			'args'  => $args,
		];
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Stub wp_kses.
	 *
	 * @param string               $content      HTML content.
	 * @param array<string, mixed> $allowed_html Allowed HTML tags.
	 * @return string
	 */
	function wp_kses( string $content, array $allowed_html ): string {
		return $content;
	}
}
