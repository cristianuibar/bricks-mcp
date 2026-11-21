<?php
/**
 * McpEndpointCheck unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin\Checks;

use BricksMCP\Admin\Checks\McpEndpointCheck;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Tests that McpEndpointCheck probes the MCP Adapter default server route.
 */
final class McpEndpointCheckTest extends TestCase {

	/**
	 * Reset stubs before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset();
		bricks_mcp_test_reset_admin();
	}

	/**
	 * Test that 2xx and expected auth/method statuses (401/403/405) pass.
	 *
	 * @return void
	 */
	public function test_probes_adapter_route_and_passes_on_expected_statuses(): void {
		foreach ( [ 200, 401, 403, 405 ] as $code ) {
			$GLOBALS['_bricks_mcp_test_wp_remote_get_calls']  = [];
			$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = [
				'response' => [ 'code' => $code ],
				'body'     => '',
			];

			$result = ( new McpEndpointCheck() )->run();

			$this->assertSame( 'pass', $result['status'], 'HTTP ' . $code );
			$this->assertSame(
				'http://localhost/wp-json/mcp/mcp-adapter-default-server',
				$GLOBALS['_bricks_mcp_test_wp_remote_get_calls'][0]['url'] ?? null,
				'HTTP ' . $code
			);
		}
	}

	/**
	 * Test that a 404 response fails as a missing route.
	 *
	 * @return void
	 */
	public function test_fails_on_404(): void {
		$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = [
			'response' => [ 'code' => 404 ],
			'body'     => '',
		];

		$result = ( new McpEndpointCheck() )->run();

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'not registered', $result['message'] );
		$this->assertNotEmpty( $result['fix_steps'] );
	}

	/**
	 * Test that 500/503 and other unexpected non-2xx statuses do not pass and include the status code.
	 *
	 * @return void
	 */
	public function test_does_not_pass_on_500_503_or_unexpected_non_2xx(): void {
		$cases = [
			500 => 'fail',
			503 => 'fail',
			429 => 'warn',
			302 => 'warn',
		];

		foreach ( $cases as $code => $expected_status ) {
			$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = [
				'response' => [ 'code' => $code ],
				'body'     => '',
			];

			$result = ( new McpEndpointCheck() )->run();

			$this->assertSame( $expected_status, $result['status'], 'HTTP ' . $code );
			$this->assertStringContainsString( (string) $code, $result['message'], 'HTTP ' . $code );
			$this->assertNotEmpty( $result['fix_steps'], 'HTTP ' . $code );
		}
	}

	/**
	 * Test that a WP_Error loopback failure fails.
	 *
	 * @return void
	 */
	public function test_fails_on_wp_error(): void {
		$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = new WP_Error( 'http_request_failed', 'Connection refused' );

		$result = ( new McpEndpointCheck() )->run();

		$this->assertSame( 'fail', $result['status'] );
		$this->assertStringContainsString( 'Connection refused', $result['message'] );
	}
}
