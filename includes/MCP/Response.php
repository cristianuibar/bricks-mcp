<?php
/**
 * MCP Response helper.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Response class.
 *
 * Provides helper methods for creating standardized REST API responses.
 */
final class Response {

	/**
	 * Create a success response.
	 *
	 * @param mixed $data    Response data.
	 * @param int   $status  HTTP status code (default: 200).
	 * @return \WP_REST_Response The REST response.
	 */
	public static function success( mixed $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'X-MCP-Server', 'bricks-mcp/' . BRICKS_MCP_VERSION );

		return $response;
	}

	/**
	 * Create an error response.
	 *
	 * @param string $code     Error code.
	 * @param string $message  Error message.
	 * @param int    $status   HTTP status code (default: 400).
	 * @param mixed  $data     Additional error data.
	 * @param string $guidance Optional remediation guidance for the client.
	 * @return \WP_REST_Response The REST response.
	 */
	public static function error( string $code, string $message, int $status = 400, mixed $data = null, string $guidance = '' ): \WP_REST_Response {
		$error_data = [
			'code'    => $code,
			'message' => $message,
		];

		if ( null !== $data ) {
			$error_data['data'] = $data;
		}

		if ( '' !== $guidance ) {
			$error_data['guidance'] = $guidance;
		}

		$response = new \WP_REST_Response(
			[
				'error'   => true,
				'content' => [
					[
						'type' => 'text',
						'text' => $message,
					],
				],
				'details' => $error_data,
			],
			$status
		);

		$response->header( 'X-MCP-Server', 'bricks-mcp/' . BRICKS_MCP_VERSION );

		return $response;
	}

	/**
	 * Create an MCP tool error response (isError: true, HTTP 200).
	 *
	 * Used when a tool handler returns WP_Error.
	 * Error text is structured JSON per MCP spec.
	 *
	 * @param \WP_Error $error    The WP_Error from the tool handler.
	 * @param string    $guidance Optional remediation guidance for the client.
	 * @return \WP_REST_Response The REST response.
	 */
	public static function tool_error( \WP_Error $error, string $guidance = '' ): \WP_REST_Response {
		$payload = [
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		];

		$data = $error->get_error_data();
		if ( null !== $data ) {
			if ( is_scalar( $data ) ) {
				$payload['data'] = $data;
			} elseif ( is_array( $data ) ) {
				// Only include safe scalar values from error data arrays.
				$payload['data'] = array_filter(
					$data,
					fn( $v ) => is_scalar( $v ) || is_null( $v )
				);
			}
		}

		if ( '' !== $guidance ) {
			$payload['guidance'] = $guidance;
		}

		return self::success(
			[
				'content' => [
					[
						'type' => 'text',
						'text' => wp_json_encode( $payload ),
					],
				],
				'isError' => true,
			]
		);
	}

	/**
	 * Create a validation error response with JSON path details.
	 *
	 * Returns a 400 response with structured error details including paths
	 * to the problematic fields and suggested fixes.
	 *
	 * @param array<int, array{path: string, message: string, suggestion: string}> $errors Array of validation errors.
	 * @return \WP_REST_Response The REST response.
	 */
	public static function validation_error( array $errors ): \WP_REST_Response {
		$message = __( 'Input validation failed. Fix the errors below and retry.', 'bricks-mcp' );

		$response = new \WP_REST_Response(
			[
				'error'   => true,
				'content' => [
					[
						'type' => 'text',
						'text' => $message,
					],
				],
				'details' => [
					'code'    => 'validation_failed',
					'message' => $message,
					'errors'  => $errors,
				],
			],
			400
		);

		$response->header( 'X-MCP-Server', 'bricks-mcp/' . BRICKS_MCP_VERSION );

		return $response;
	}
}
