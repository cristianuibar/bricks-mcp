<?php
/**
 * PromptRegistry unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\PromptRegistry;
use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PromptRegistry class.
 */
final class PromptRegistryTest extends TestCase {

	/**
	 * Create a BricksService stub with deterministic counts.
	 *
	 * @return BricksService
	 */
	private function create_bricks_service_stub(): BricksService {
		return new class() extends BricksService {
			public function get_theme_styles(): array {
				return array( array( 'id' => 'style-1' ) );
			}

			public function get_color_palettes(): array {
				return array( array( 'id' => 'palette-1' ), array( 'id' => 'palette-2' ) );
			}

			public function get_breakpoints(): array {
				return array( array( 'id' => 'mobile' ), array( 'id' => 'tablet' ) );
			}
		};
	}

	/**
	 * Test that the required workflow prompts are listed.
	 *
	 * @return void
	 */
	public function test_list_prompts_returns_required_workflows(): void {
		$registry = new PromptRegistry( $this->create_bricks_service_stub() );
		$names    = array_column( $registry->list_prompts(), 'name' );

		$this->assertContains( 'build_landing_page', $names );
		$this->assertContains( 'build_template', $names );
		$this->assertContains( 'audit_page', $names );
		$this->assertContains( 'migrate_content', $names );
		$this->assertCount( 4, $names );
	}

	/**
	 * Test that a prompt message includes contextual data and the passed argument.
	 *
	 * @return void
	 */
	public function test_get_prompt_returns_contextual_message(): void {
		$registry = new PromptRegistry( $this->create_bricks_service_stub() );
		$result   = $registry->get_prompt(
			'audit_page',
			array(
				'post_id' => 123,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertStringContainsString( 'post ID 123', $result['messages'][0]['content']['text'] );
		$this->assertStringContainsString( '1 theme styles, 2 color palettes, and 2 responsive breakpoints', $result['messages'][0]['content']['text'] );
	}

	/**
	 * Test that unknown prompt names return a WP_Error.
	 *
	 * @return void
	 */
	public function test_get_prompt_returns_error_for_unknown_name(): void {
		$registry = new PromptRegistry( $this->create_bricks_service_stub() );
		$result   = $registry->get_prompt( 'unknown_prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unknown_prompt', $result->get_error_code() );
	}
}
