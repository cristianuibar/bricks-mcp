<?php
/**
 * Router list pagination unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Default and explicit page sizes for template:list and global_class:list (#26).
 */
final class RouterListPaginationTest extends TestCase {

	/**
	 * Build a Router whose BricksService returns the given templates and classes.
	 *
	 * @param array<int, array<string, mixed>> $templates Templates get_templates() returns.
	 * @param array<int, array<string, mixed>> $classes   Classes get_global_classes() returns.
	 * @return Router
	 */
	private function router_with( array $templates, array $classes ): Router {
		$service = $this->createStub( BricksService::class );
		$service->method( 'is_bricks_active' )->willReturn( true );
		$service->method( 'get_templates' )->willReturn( $templates );
		$service->method( 'get_global_classes' )->willReturn( $classes );

		$router   = ( new \ReflectionClass( Router::class ) )->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty( Router::class, 'bricks_service' );
		$property->setValue( $router, $service );

		return $router;
	}

	/**
	 * Call a private Router tool method.
	 *
	 * @param Router               $router Router under test.
	 * @param string               $method Tool method name.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @return array<string, mixed>
	 */
	private function call( Router $router, string $method, array $args ): array {
		return ( new \ReflectionMethod( Router::class, $method ) )->invoke( $router, $args );
	}

	/**
	 * Build n numbered rows.
	 *
	 * @param int $n Number of rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $n ): array {
		return array_map( static fn ( int $i ): array => array( 'id' => $i ), range( 1, $n ) );
	}

	public function test_template_list_defaults_to_20_per_page(): void {
		$result = $this->call( $this->router_with( $this->rows( 45 ), array() ), 'tool_list_templates', array() );

		$this->assertSame( 45, $result['total'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertSame( 20, $result['per_page'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertCount( 20, $result['templates'] );
		$this->assertSame( 1, $result['templates'][0]['id'] );
	}

	public function test_template_list_explicit_page_and_per_page(): void {
		$result = $this->call( $this->router_with( $this->rows( 45 ), array() ), 'tool_list_templates', array( 'per_page' => 10, 'page' => 5 ) );

		$this->assertSame( 5, $result['page'] );
		$this->assertSame( 10, $result['per_page'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 41, 42, 43, 44, 45 ), array_column( $result['templates'], 'id' ) );
	}

	public function test_global_class_list_defaults_to_50_per_page(): void {
		$result = $this->call( $this->router_with( array(), $this->rows( 60 ) ), 'tool_get_global_classes', array() );

		$this->assertSame( 60, $result['total'] );
		$this->assertSame( 50, $result['per_page'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertCount( 50, $result['classes'] );
	}

	public function test_per_page_is_capped_at_100_and_page_starts_at_1(): void {
		$result = $this->call( $this->router_with( array(), $this->rows( 150 ) ), 'tool_get_global_classes', array( 'per_page' => 500, 'page' => 0 ) );

		$this->assertSame( 100, $result['per_page'] );
		$this->assertSame( 1, $result['page'] );
		$this->assertCount( 100, $result['classes'] );
	}

	public function test_list_defaults_are_published_in_discovery(): void {
		$router   = ( new \ReflectionClass( Router::class ) )->newInstanceWithoutConstructor();
		$defaults = new \ReflectionMethod( Router::class, 'get_default_tool_defaults' );

		$this->assertSame( 20, $defaults->invoke( $router, 'template' )['list']['per_page'] );
		$this->assertSame( 50, $defaults->invoke( $router, 'global_class' )['list']['per_page'] );
	}
}
