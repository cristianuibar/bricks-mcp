<?php
/**
 * Streamable HTTP transport handler for MCP.
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
 * StreamableHttpHandler class.
 *
 * Implements the MCP Streamable HTTP transport (protocol version 2025-03-26).
 * Handles JSON-RPC 2.0 messages over Server-Sent Events.
 */
final class StreamableHttpHandler {

	/**
	 * MCP protocol version.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION = '2025-03-26';

	/**
	 * JSON-RPC parse error code.
	 *
	 * @var int
	 */
	public const PARSE_ERROR = -32700;

	/**
	 * JSON-RPC invalid request error code.
	 *
	 * @var int
	 */
	public const INVALID_REQUEST = -32600;

	/**
	 * JSON-RPC method not found error code.
	 *
	 * @var int
	 */
	public const METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC invalid params error code.
	 *
	 * @var int
	 */
	public const INVALID_PARAMS = -32602;

	/**
	 * JSON-RPC internal error code.
	 *
	 * @var int
	 */
	public const INTERNAL_ERROR = -32603;

	/**
	 * Maximum number of messages allowed in a JSON-RPC batch request.
	 *
	 * @var int
	 */
	public const MAX_BATCH_SIZE = 20;

	/**
	 * Maximum allowed request body size in bytes (1 MB).
	 *
	 * @var int
	 */
	public const MAX_BODY_SIZE = 1048576;

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Resource registry.
	 *
	 * @var ResourceRegistry
	 */
	private ResourceRegistry $resource_registry;

	/**
	 * Prompt registry.
	 *
	 * @var PromptRegistry
	 */
	private PromptRegistry $prompt_registry;

	/**
	 * Constructor.
	 *
	 * @param Router $router The MCP router instance.
	 */
	public function __construct( Router $router ) {
		$this->router            = $router;
		$this->resource_registry = new ResourceRegistry( $router->get_bricks_service() );
		$this->prompt_registry   = new PromptRegistry( $router->get_bricks_service() );
	}

	/**
	 * Handle POST requests (JSON-RPC dispatch).
	 *
	 * Validates Content-Type, decodes JSON, detects batch vs single message,
	 * handles notifications (202 no body), and emits SSE responses.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Outputs SSE stream and exits.
	 */
	public function handle_post( \WP_REST_Request $request ): void {
		// Rate limiting is handled upstream by Server::check_permissions() (permission_callback).

		// Validate Content-Type header contains application/json.
		$content_type = $request->get_header( 'Content-Type' );
		if ( empty( $content_type ) || false === strpos( $content_type, 'application/json' ) ) {
			status_header( 415 );
			header( 'Content-Type: application/json' );
			header( 'Connection: close' );
			echo wp_json_encode(
				$this->jsonrpc_error( null, self::INVALID_REQUEST, 'Unsupported Media Type' )
			);
			exit;
		}

		// Check body size before parsing.
		$body     = $request->get_body();
		$max_body = (int) apply_filters( 'bricks_mcp_max_body_size', self::MAX_BODY_SIZE );
		if ( strlen( $body ) > $max_body ) {
			status_header( 413 );
			header( 'Content-Type: application/json' );
			header( 'Connection: close' );
			echo wp_json_encode(
				$this->jsonrpc_error( null, self::INVALID_REQUEST, 'Request body too large' )
			);
			exit;
		}

		// Decode JSON body.
		$decoded = json_decode( $body, true );

		// Handle JSON parse errors.
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->emit_sse_headers();
			$this->emit_sse_event( $this->jsonrpc_error( null, self::PARSE_ERROR, 'Parse error' ) );
			exit;
		}

		// Detect batch vs single message.
		if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
			// Reject oversized batches.
			if ( count( $decoded ) > self::MAX_BATCH_SIZE ) {
				$this->emit_sse_headers();
				$this->emit_sse_event(
					$this->jsonrpc_error( null, self::INVALID_REQUEST, 'Batch too large (max 20 messages)' )
				);
				exit;
			}

			// Batch request — initialize must not be batched.
			foreach ( $decoded as $message ) {
				if ( is_array( $message ) && isset( $message['method'] ) && 'initialize' === $message['method'] ) {
					$this->emit_sse_headers();
					$this->emit_sse_event(
						$this->jsonrpc_error( null, self::INVALID_REQUEST, 'initialize must not be batched' )
					);
					exit;
				}
			}

			$results = $this->dispatch_batch( $decoded );
			$this->emit_sse_headers();
			$this->emit_sse_event( $results );
			exit;
		}

		// Single message.
		if ( ! is_array( $decoded ) ) {
			$this->emit_sse_headers();
			$this->emit_sse_event( $this->jsonrpc_error( null, self::INVALID_REQUEST, 'Invalid JSON-RPC request' ) );
			exit;
		}

		// Notification (no id field) — return 202 with no body.
		if ( ! array_key_exists( 'id', $decoded ) ) {
			status_header( 202 );
			exit;
		}

		// Single request — dispatch and emit SSE response.
		$result = $this->dispatch_single( $decoded );
		$this->emit_sse_headers();
		if ( null !== $result ) {
			$this->emit_sse_event( $result );
		}
		exit;
	}

	/**
	 * Handle GET requests (persistent SSE keepalive loop).
	 *
	 * Emits SSE keepalive comments every 25 seconds to keep the connection open
	 * through PHP-FPM idle timeouts. Checks for client disconnects via
	 * connection_aborted() before and after each sleep interval, exiting cleanly
	 * within one keepalive interval when the client disconnects.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Outputs SSE keepalive stream and exits.
	 */
	public function handle_get( \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->emit_sse_headers();
		while ( true ) {
			if ( connection_aborted() ) {
				break;
			}
			echo ": keepalive\n\n";
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
			sleep( 25 );
			if ( connection_aborted() ) {
				break;
			}
		}
		exit;
	}

	/**
	 * Emit a JSON-RPC parse error as SSE and exit.
	 *
	 * Called by Server when WordPress detects an invalid JSON body before our callback runs.
	 * This allows us to return a spec-compliant JSON-RPC -32700 parse error over SSE
	 * instead of WordPress's own rest_invalid_json error.
	 *
	 * @return void Outputs SSE parse error and exits.
	 */
	public function emit_parse_error_and_exit(): void {
		$this->emit_sse_headers();
		$this->emit_sse_event( $this->jsonrpc_error( null, self::PARSE_ERROR, 'Parse error' ) );
		exit;
	}

	/**
	 * Handle DELETE requests (session termination no-op).
	 *
	 * Returns HTTP 200 with no body per MCP protocol decision.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return void Sets 200 status and exits.
	 */
	public function handle_delete( \WP_REST_Request $request ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		status_header( 200 );
		exit;
	}

	/**
	 * Dispatch a single JSON-RPC message.
	 *
	 * Routes the message to the appropriate handler based on method name.
	 * Returns null for notifications (no id).
	 *
	 * @param array<string, mixed> $message The decoded JSON-RPC message.
	 * @return array<string, mixed>|null Response array, or null for notifications.
	 */
	private function dispatch_single( array $message ): ?array {
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? '';
		$params = $message['params'] ?? [];

		// Notification — no response needed.
		if ( ! array_key_exists( 'id', $message ) ) {
			return null;
		}

		// Validate JSON-RPC version.
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->jsonrpc_error( $id, self::INVALID_REQUEST, 'Invalid JSON-RPC version' );
		}

		// Route to method handler.
		return match ( $method ) {
			'initialize'              => $this->handle_initialize( $id, is_array( $params ) ? $params : [] ),
			'notifications/initialized' => null,
			'tools/list'              => $this->handle_tools_list( $id, is_array( $params ) ? $params : [] ),
			'tools/call'              => $this->handle_tools_call( $id, is_array( $params ) ? $params : [] ),
			'resources/list'          => $this->handle_resources_list( $id, is_array( $params ) ? $params : [] ),
			'resources/read'          => $this->handle_resources_read( $id, is_array( $params ) ? $params : [] ),
			'prompts/list'            => $this->handle_prompts_list( $id, is_array( $params ) ? $params : [] ),
			'prompts/get'             => $this->handle_prompts_get( $id, is_array( $params ) ? $params : [] ),
			'ping'                    => $this->jsonrpc_success( $id, [] ),
			default                   => $this->jsonrpc_error( $id, self::METHOD_NOT_FOUND, 'Method not found' ),
		};
	}

	/**
	 * Dispatch a batch of JSON-RPC messages.
	 *
	 * Processes each message via dispatch_single and filters out null results
	 * (notifications that require no response).
	 *
	 * @param array<int, mixed> $messages The array of decoded JSON-RPC messages.
	 * @return array<int, array<string, mixed>> Array of response objects.
	 */
	private function dispatch_batch( array $messages ): array {
		$results = array_map( [ $this, 'dispatch_single' ], $messages );
		return array_values( array_filter( $results, fn( $r ) => null !== $r ) );
	}

	/**
	 * Handle the initialize JSON-RPC method.
	 *
	 * Returns protocol version, server capabilities, and server info.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (unused but required by spec).
	 * @return array<string, mixed> JSON-RPC success response.
	 */
	private function handle_initialize( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$capabilities = [
			'tools' => [
				'listChanged' => true,
			],
			'resources' => new \stdClass(),
			'prompts'   => new \stdClass(),
		];

		$server_info = [
			'name'    => 'bricks-mcp',
			'version' => BRICKS_MCP_VERSION,
		];

		$instructions = 'Bricks MCP connects AI assistants to a WordPress site running Bricks Builder. '
			. 'IMPORTANT: Before creating or modifying any Bricks page, call the get_builder_guide tool to learn element settings, CSS gotchas, animation formats, and workflow patterns. '
			. 'This avoids common mistakes like using wrong style property names or deprecated settings. '
			. 'Use get_site_info to understand the site context (theme, plugins, Bricks version) before making changes.';

		return $this->jsonrpc_success(
			$id,
			[
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => $capabilities,
				'serverInfo'      => $server_info,
				'instructions'    => $instructions,
			]
		);
	}

	/**
	 * Handle the tools/list JSON-RPC method.
	 *
	 * Returns all available MCP tools from the router.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (cursor for pagination, currently unused).
	 * @return array<string, mixed> JSON-RPC success response with tools array.
	 */
	private function handle_tools_list( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$tools = $this->router->get_available_tools();

		return $this->jsonrpc_success( $id, [ 'tools' => $tools ] );
	}

	/**
	 * Handle the tools/call JSON-RPC method.
	 *
	 * Extracts tool name and arguments, executes via router, and returns result.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (name, arguments).
	 * @return array<string, mixed> JSON-RPC success or error response.
	 */
	private function handle_tools_call( int|string $id, array $params ): array {
		$name      = $params['name'] ?? '';
		$arguments = $params['arguments'] ?? [];

		if ( empty( $name ) ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, 'Missing required parameter: name' );
		}

		$result = $this->router->execute_tool( $name, is_array( $arguments ) ? $arguments : [] );

		$data   = $result->get_data();
		$status = $result->get_status();

		// Check for error conditions.
		if ( $status >= 400 || ( is_array( $data ) && ! empty( $data['error'] ) ) ) {
			$error_text = '';
			if ( is_array( $data ) && isset( $data['content'][0]['text'] ) ) {
				$error_text = wp_strip_all_tags( $data['content'][0]['text'] );
			}

			return $this->jsonrpc_error(
				$id,
				self::INTERNAL_ERROR,
				$error_text ? $error_text : 'Tool execution failed'
			);
		}

		return $this->jsonrpc_success( $id, $data );
	}

	/**
	 * Handle the resources/list JSON-RPC method.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (unused).
	 * @return array<string, mixed>
	 */
	private function handle_resources_list( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->jsonrpc_success(
			$id,
			[
				'resources' => $this->resource_registry->list_resources(),
			]
		);
	}

	/**
	 * Handle the resources/read JSON-RPC method.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params.
	 * @return array<string, mixed>
	 */
	private function handle_resources_read( int|string $id, array $params ): array {
		$uri = isset( $params['uri'] ) ? (string) $params['uri'] : '';

		if ( '' === $uri ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, 'Missing required parameter: uri' );
		}

		$result = $this->resource_registry->read_resource( $uri );

		if ( is_wp_error( $result ) ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, $result->get_error_message() );
		}

		return $this->jsonrpc_success( $id, $result );
	}

	/**
	 * Handle the prompts/list JSON-RPC method.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params (unused).
	 * @return array<string, mixed>
	 */
	private function handle_prompts_list( int|string $id, array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->jsonrpc_success(
			$id,
			[
				'prompts' => $this->prompt_registry->list_prompts(),
			]
		);
	}

	/**
	 * Handle the prompts/get JSON-RPC method.
	 *
	 * @param int|string           $id     The JSON-RPC request id.
	 * @param array<string, mixed> $params The request params.
	 * @return array<string, mixed>
	 */
	private function handle_prompts_get( int|string $id, array $params ): array {
		$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, 'Missing required parameter: name' );
		}

		$result = $this->prompt_registry->get_prompt( $name, $arguments );

		if ( is_wp_error( $result ) ) {
			return $this->jsonrpc_error( $id, self::INVALID_PARAMS, $result->get_error_message() );
		}

		return $this->jsonrpc_success( $id, $result );
	}

	/**
	 * Emit SSE response headers.
	 *
	 * Flushes output buffers, extends PHP execution time via the filterable
	 * bricks_mcp_sse_timeout filter (default 1800 seconds), enables
	 * connection_aborted() polling via ignore_user_abort( true ), sets SSE
	 * headers, and registers a shutdown function to emit a stream-end comment
	 * so proxies know the stream closed unexpectedly.
	 *
	 * @return void
	 */
	private function emit_sse_headers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		$timeout = (int) apply_filters( 'bricks_mcp_sse_timeout', 1800 );
		set_time_limit( $timeout );
		ignore_user_abort( true );

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		register_shutdown_function(
			function () {
				echo ": stream-end\n\n";
				if ( ob_get_level() > 0 ) {
					ob_flush();
				}
				flush();
			}
		);
	}

	/**
	 * Emit a single SSE event with JSON payload.
	 *
	 * @param array<string, mixed>|array<int, mixed> $payload The data to encode as JSON in the event.
	 * @return void
	 */
	private function emit_sse_event( array $payload ): void {
		echo "event: message\n";
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";

		if ( ob_get_level() > 0 ) {
			ob_flush();
		}

		flush();
	}

	/**
	 * Build a JSON-RPC 2.0 success response.
	 *
	 * @param int|string $id     The JSON-RPC request id.
	 * @param mixed      $result The result data.
	 * @return array<string, mixed> The JSON-RPC response array.
	 */
	private function jsonrpc_success( int|string $id, mixed $result ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * Build a JSON-RPC 2.0 error response.
	 *
	 * @param int|string|null $id      The JSON-RPC request id (null for parse errors).
	 * @param int             $code    The JSON-RPC error code.
	 * @param string          $message The error message.
	 * @return array<string, mixed> The JSON-RPC error response array.
	 */
	private function jsonrpc_error( int|string|null $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
