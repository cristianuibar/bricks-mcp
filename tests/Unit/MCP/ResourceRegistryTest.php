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
	 * Create a BricksService stub with deterministic outputs.
	 *
	 * @return BricksService
	 */
	private function create_bricks_service_stub(): BricksService {
		return new class() extends BricksService {
			public function get_color_palettes(): array {
				return array(
					array(
						'id'     => 'palette-1',
						'name'   => 'Primary',
						'colors' => array(
							array(
								'id'    => 'color-1',
								'name'  => 'Brand Blue',
								'light' => '#336699',
							),
						),
					),
				);
			}

			public function get_breakpoints(): array {
				return array(
					array(
						'name'  => 'tablet_portrait',
						'width' => 768,
					),
				);
			}

			public function get_global_classes( string $search = '', string $category = '' ): array {
				return array(
					array(
						'id'   => 'class-1',
						'name' => 'btn-primary',
					),
				);
			}

			public function get_global_variables(): array {
				return array(
					array(
						'id'    => 'var-1',
						'name'  => '--space-m',
						'value' => '24px',
					),
				);
			}

			public function get_theme_styles(): array {
				return array(
					array(
						'id'    => 'style-1',
						'label' => 'Default Theme Style',
					),
				);
			}
		};
	}

	/**
	 * Test that the default registry exposes the expected design resources.
	 *
	 * @return void
	 */
	public function test_list_resources_returns_default_design_uris(): void {
		$registry = new ResourceRegistry( $this->create_bricks_service_stub() );
		$uris     = array_column( $registry->list_resources(), 'uri' );

		$this->assertContains( 'bricks://design/colors', $uris );
		$this->assertContains( 'bricks://design/breakpoints', $uris );
		$this->assertContains( 'bricks://design/global-classes', $uris );
		$this->assertContains( 'bricks://design/variables', $uris );
		$this->assertContains( 'bricks://design/theme-styles', $uris );
		$this->assertCount( 5, $uris );
	}

	/**
	 * Test that reading a known resource returns MCP contents with JSON text.
	 *
	 * @return void
	 */
	public function test_read_resource_returns_json_payload(): void {
		$registry = new ResourceRegistry( $this->create_bricks_service_stub() );
		$result   = $registry->read_resource( 'bricks://design/colors' );

		$this->assertIsArray( $result );
		$this->assertSame( 'bricks://design/colors', $result['contents'][0]['uri'] );
		$this->assertSame( 'application/json', $result['contents'][0]['mimeType'] );
		$this->assertStringContainsString( 'Brand Blue', $result['contents'][0]['text'] );
	}

	/**
	 * Test that unknown URIs return a WP_Error.
	 *
	 * @return void
	 */
	public function test_read_resource_returns_error_for_unknown_uri(): void {
		$registry = new ResourceRegistry( $this->create_bricks_service_stub() );
		$result   = $registry->read_resource( 'bricks://design/unknown' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unknown_resource', $result->get_error_code() );
	}
}
