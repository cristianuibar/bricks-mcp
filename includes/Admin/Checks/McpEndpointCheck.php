<?php
/**
 * MCP Adapter endpoint reachability check.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin\Checks;

use BricksMCP\Admin\DiagnosticCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether the WordPress MCP Adapter default server route is registered and responding.
 */
class McpEndpointCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'mcp_endpoint';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'MCP Endpoint Registered', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'connectivity';
	}

	/**
	 * Get dependencies.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return [ 'rest_api_reachable', 'app_passwords' ];
	}

	/**
	 * Run the MCP Adapter endpoint check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$endpoint_url = $this->endpoint_url();

		$response = wp_remote_get(
			$endpoint_url,
			[
				'timeout'   => 5,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => sprintf(
					/* translators: %s: WP_Error message */
					__( 'MCP Adapter endpoint loopback request failed: %s', 'bricks-mcp' ),
					$response->get_error_message()
				),
				'fix_steps' => [
					__( 'Check that loopback requests are allowed on your server.', 'bricks-mcp' ),
					__( 'Check the WordPress Site Health page for loopback request status.', 'bricks-mcp' ),
				],
				'category'  => $this->category(),
			];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $http_code ) {
			return [
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'MCP Adapter endpoint not found. The mcp/mcp-adapter-default-server route is not registered.', 'bricks-mcp' ),
				'fix_steps' => [
					__( 'Ensure the WordPress MCP Adapter plugin is installed and activated.', 'bricks-mcp' ),
					__( 'Check Bricks > Settings > AI to verify the MCP server status.', 'bricks-mcp' ),
				],
				'category'  => $this->category(),
			];
		}

		if ( ( $http_code >= 200 && $http_code < 300 ) || in_array( $http_code, [ 401, 403, 405 ], true ) ) {
			return [
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'MCP Adapter endpoint is registered and responding.', 'bricks-mcp' ),
				'fix_steps' => [],
				'category'  => $this->category(),
			];
		}

		$is_server_error = $http_code >= 500 || $http_code <= 0;

		return [
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => $is_server_error ? 'fail' : 'warn',
			'message'   => sprintf(
				/* translators: %d: HTTP status code returned by the MCP Adapter endpoint */
				__( 'MCP Adapter endpoint returned unexpected HTTP status %d.', 'bricks-mcp' ),
				$http_code
			),
			'fix_steps' => [
				__( 'Check your PHP and server error logs for errors on the /wp-json/mcp/mcp-adapter-default-server route.', 'bricks-mcp' ),
				__( 'Check Bricks > Settings > AI and confirm the WordPress MCP Adapter plugin is functioning properly.', 'bricks-mcp' ),
			],
			'category'  => $this->category(),
		];
	}

	/**
	 * Resolve the MCP Adapter default server URL.
	 *
	 * @return string
	 */
	private function endpoint_url(): string {
		if ( class_exists( '\Bricks\Helpers' ) && is_callable( [ '\Bricks\Helpers', 'get_mcp_adapter_endpoint_url' ] ) ) {
			return (string) \Bricks\Helpers::get_mcp_adapter_endpoint_url();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Bricks' own filter name.
		$route = (string) apply_filters( 'bricks_mcp_adapter_rest_route', 'mcp/mcp-adapter-default-server' );

		return rtrim( rest_url( $route ), '/' );
	}
}
