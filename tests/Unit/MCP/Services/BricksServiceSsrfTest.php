<?php
/**
 * Unit test: SSRF fix in BricksService::import_template_from_url().
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that import_template_from_url uses wp_safe_remote_get (not
 * wp_remote_get) so SSRF via DNS-rebinding is blocked at the connection level.
 */
class BricksServiceSsrfTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['_bricks_mcp_test_wp_http_validate_url_return'],
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'],
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'],
			$GLOBALS['_bricks_mcp_test_wp_remote_retrieve_header_return']
		);
	}

	public function test_import_template_from_url_uses_wp_safe_remote_get(): void {
		$GLOBALS['_bricks_mcp_test_wp_http_validate_url_return'] = 'https://example.com/template.json';
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']    = [];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return']   = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => json_encode( [ 'content' => [] ] ),
		];

		$service = new BricksService();
		$service->import_template_from_url( 'https://example.com/template.json' );

		$calls = $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'];
		$this->assertCount( 1, $calls, 'wp_safe_remote_get should be called exactly once' );
		$this->assertSame( 'https://example.com/template.json', $calls[0]['url'] );
	}

	public function test_import_template_from_url_rejects_non_https(): void {
		$GLOBALS['_bricks_mcp_test_wp_http_validate_url_return'] = 'http://example.com/template.json';
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']    = [];

		$service = new BricksService();
		$result  = $service->import_template_from_url( 'http://example.com/template.json' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'https_required', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}

	public function test_import_template_from_url_rejects_invalid_url(): void {
		$GLOBALS['_bricks_mcp_test_wp_http_validate_url_return'] = false;
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']    = [];

		$service = new BricksService();
		$result  = $service->import_template_from_url( 'http://169.254.169.254/metadata' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );

		// wp_safe_remote_get must NOT be called for rejected URLs.
		$this->assertCount( 0, $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}
}
