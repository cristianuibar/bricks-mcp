<?php
/**
 * ResourceRegistry unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\ResourceRegistry;
use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ResourceRegistry class.
 */
final class ResourceRegistryTest extends TestCase {

	/**
	 * Resource registry under test.
	 *
	 * @var ResourceRegistry
	 */
	private ResourceRegistry $registry;

	/**
	 * Set up registry instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new ResourceRegistry( new BricksService() );
	}

	/**
	 * Test: list_resources includes builder guide URI.
	 *
	 * @return void
	 */
	public function test_list_resources_includes_builder_guide(): void {
		$resources = $this->registry->list_resources();
		$uris      = array_column( $resources, 'uri' );

		$this->assertContains( 'bricks://builder-guide', $uris );
	}

	/**
	 * Test: read_resource returns markdown for builder guide.
	 *
	 * @return void
	 */
	public function test_read_builder_guide_returns_markdown(): void {
		$result = $this->registry->read_resource( 'bricks://builder-guide' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'contents', $result );
		$this->assertSame( 'text/markdown', $result['contents'][0]['mimeType'] );
		$this->assertNotEmpty( $result['contents'][0]['text'] );
		$this->assertStringContainsString( '## Element Settings Reference', $result['contents'][0]['text'] );
	}

	/**
	 * Test: unknown URI returns WP_Error.
	 *
	 * @return void
	 */
	public function test_read_unknown_uri_returns_wp_error(): void {
		$result = $this->registry->read_resource( 'bricks://unknown' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'unknown_resource', $result->get_error_code() );
	}

	/**
	 * Test: path traversal URI is rejected via allowlist.
	 *
	 * @return void
	 */
	public function test_read_path_traversal_uri_rejected(): void {
		$result = $this->registry->read_resource( 'bricks://../../wp-config.php' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'unknown_resource', $result->get_error_code() );
	}
}