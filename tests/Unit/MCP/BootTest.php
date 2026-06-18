<?php
/**
 * MCP Server boot integration tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\PromptRegistry;
use BricksMCP\MCP\ResourceRegistry;
use BricksMCP\MCP\Router;
use BricksMCP\MCP\Server;
use BricksMCP\MCP\StreamableHttpHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests proving end-to-end Server construction without constructor workarounds.
 */
final class BootTest extends TestCase {

	/**
	 * Read a private property via reflection.
	 *
	 * @param object $object   Object instance.
	 * @param string $property Property name.
	 * @return mixed
	 */
	private function get_private_property( object $object, string $property ): mixed {
		$ref = new \ReflectionProperty( $object, $property );
		$ref->setAccessible( true );
		return $ref->getValue( $object );
	}

	/**
	 * Test: Server constructs without fatal error.
	 *
	 * @return void
	 */
	public function test_server_constructs_without_fatal(): void {
		$server = new Server();

		$this->assertInstanceOf( Server::class, $server );
	}

	/**
	 * Test: Server exposes handler and router via reflection.
	 *
	 * @return void
	 */
	public function test_server_has_handler_and_router_via_reflection(): void {
		$server  = new Server();
		$handler = $this->get_private_property( $server, 'handler' );
		$router  = $this->get_private_property( $server, 'router' );

		$this->assertInstanceOf( StreamableHttpHandler::class, $handler );
		$this->assertInstanceOf( Router::class, $router );
	}

	/**
	 * Test: Handler wires ResourceRegistry and PromptRegistry.
	 *
	 * @return void
	 */
	public function test_handler_registry_properties_exist(): void {
		$server            = new Server();
		$handler           = $this->get_private_property( $server, 'handler' );
		$resource_registry = $this->get_private_property( $handler, 'resource_registry' );
		$prompt_registry   = $this->get_private_property( $handler, 'prompt_registry' );

		$this->assertInstanceOf( ResourceRegistry::class, $resource_registry );
		$this->assertInstanceOf( PromptRegistry::class, $prompt_registry );
	}

	/**
	 * Test: Wired resource registry lists builder guide.
	 *
	 * @return void
	 */
	public function test_resource_registry_lists_builder_guide(): void {
		$server            = new Server();
		$handler           = $this->get_private_property( $server, 'handler' );
		$resource_registry = $this->get_private_property( $handler, 'resource_registry' );
		$resources         = $resource_registry->list_resources();
		$uris              = array_column( $resources, 'uri' );

		$this->assertContains( 'bricks://builder-guide', $uris );
	}
}