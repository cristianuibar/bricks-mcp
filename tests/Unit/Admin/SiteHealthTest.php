<?php
/**
 * SiteHealth unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin;

use BricksMCP\Admin\SiteHealth;
use PHPUnit\Framework\TestCase;

/**
 * Tests SiteHealth test registration and MCP Adapter check.
 */
final class SiteHealthTest extends TestCase {

	/**
	 * Test that SiteHealth registers MCP Adapter, REST API and App Passwords tests.
	 *
	 * @return void
	 */
	public function test_register_tests_replaces_bricks_active_with_adapter_active(): void {
		$tests = ( new SiteHealth() )->register_tests( [ 'direct' => [] ] );

		$this->assertArrayHasKey( 'bricks_mcp_rest_api', $tests['direct'] );
		$this->assertArrayHasKey( 'bricks_mcp_app_passwords', $tests['direct'] );
		$this->assertArrayHasKey( 'bricks_mcp_adapter_active', $tests['direct'] );
		$this->assertArrayNotHasKey( 'bricks_mcp_bricks_active', $tests['direct'] );
	}

	/**
	 * Test that MCP Adapter check reports critical when WP_MCP_VERSION is undefined.
	 *
	 * @return void
	 */
	public function test_mcp_adapter_active_reports_critical_when_missing(): void {
		$result = ( new SiteHealth() )->test_mcp_adapter_active();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'bricks_mcp_adapter_active', $result['test'] );
		$this->assertStringContainsString( 'admin.php?page=bricks-ai', $result['actions'] );
	}
}
