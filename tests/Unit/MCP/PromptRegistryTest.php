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
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PromptRegistry class.
 */
final class PromptRegistryTest extends TestCase {

	/**
	 * Prompt registry under test.
	 *
	 * @var PromptRegistry
	 */
	private PromptRegistry $registry;

	/**
	 * Set up registry instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new PromptRegistry();
	}

	/**
	 * Test: list_prompts includes bricks-audit-page.
	 *
	 * @return void
	 */
	public function test_list_prompts_includes_bricks_audit_page(): void {
		$prompts = $this->registry->list_prompts();
		$names   = array_column( $prompts, 'name' );

		$this->assertContains( 'bricks-audit-page', $names );
	}

	/**
	 * Test: get_prompt returns MCP message structure.
	 *
	 * @return void
	 */
	public function test_get_bricks_audit_page_returns_messages(): void {
		$result = $this->registry->get_prompt( 'bricks-audit-page', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'messages', $result );
		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertSame( 'text', $result['messages'][0]['content']['type'] );
		$this->assertNotEmpty( $result['messages'][0]['content']['text'] );
		$this->assertStringContainsString( 'get_builder_guide', $result['messages'][0]['content']['text'] );
	}

	/**
	 * Test: unknown prompt returns WP_Error.
	 *
	 * @return void
	 */
	public function test_get_unknown_prompt_returns_wp_error(): void {
		$result = $this->registry->get_prompt( 'nonexistent', array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'unknown_prompt', $result->get_error_code() );
	}
}