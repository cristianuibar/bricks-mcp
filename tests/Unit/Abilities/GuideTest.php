<?php
/**
 * Guide ability unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\Guide;
use BricksMCP\Abilities\Registrar;
use BricksMCP\Options;
use PHPUnit\Framework\TestCase;

/**
 * Tests for bricks-mcp/get-guide and the Bricks start-here pointer.
 */
final class GuideTest extends TestCase {

	/**
	 * Reset stubs and register the guide ability.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset();

		foreach ( ( new Guide() )->abilities() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	public function test_guide_resource_returns_markdown_for_users_who_can_edit_posts(): void {
		$guide = wp_get_ability( 'bricks-mcp/get-guide' )->execute();

		$this->assertIsString( $guide );
		$this->assertStringStartsWith( '# ', $guide );
		$this->assertStringContainsString( 'bricks-mcp/diagnose-page', $guide );
	}

	public function test_guide_is_refused_without_edit_posts(): void {
		$GLOBALS['_bricks_mcp_test_caps']['edit_posts'] = false;

		$result = wp_get_ability( 'bricks-mcp/get-guide' )->execute();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_forbidden', $result->get_error_code() );
	}

	public function test_guide_documents_every_registered_ability(): void {
		$guide = wp_get_ability( 'bricks-mcp/get-guide' )->execute();

		foreach ( array_keys( Registrar::definitions() ) as $name ) {
			$this->assertStringContainsString( '`' . $name . '`', $guide, $name . ' is missing from the guide.' );
		}
	}

	public function test_start_here_pointer_keeps_the_original_text_and_names_the_guide(): void {
		$text = ( new Guide() )->append_start_here_pointer( "# Bricks: start here\n\nUse Bricks abilities." );

		$this->assertStringStartsWith( "# Bricks: start here\n\nUse Bricks abilities.", $text );
		$this->assertStringContainsString( 'bricks-mcp/get-guide', $text );
		$this->assertStringContainsString( 'bricks-mcp/diagnose-page', $text );
	}

	public function test_start_here_pointer_survives_a_filtered_non_string_text(): void {
		$text = ( new Guide() )->append_start_here_pointer( null );

		$this->assertStringContainsString( 'bricks-mcp/get-guide', $text );
	}

	public function test_start_here_pointer_lists_every_enabled_ability_and_how_to_reach_the_guide_and_prompt(): void {
		$text = ( new Guide() )->append_start_here_pointer( '# Start' );

		foreach ( array_keys( Registrar::definitions() ) as $name ) {
			$this->assertStringContainsString( '`' . $name . '`', $text, $name . ' is missing from the pointer.' );
		}

		$this->assertStringContainsString( 'bricks-mcp://guide', $text );
		$this->assertStringContainsString( 'bricks-mcp-audit-page', $text );
		$this->assertStringContainsString( 'mcp-adapter-execute-ability', $text );
	}

	public function test_start_here_pointer_omits_disabled_abilities(): void {
		update_option(
			Options::OPTION,
			[ 'disabled_abilities' => [ 'bricks-mcp/get-seo', 'bricks-mcp/get-guide', 'bricks-mcp/audit-page' ] ]
		);

		$text = ( new Guide() )->append_start_here_pointer( '# Start' );

		$this->assertStringNotContainsString( 'bricks-mcp/get-seo', $text );
		$this->assertStringNotContainsString( 'bricks-mcp://guide', $text );
		$this->assertStringNotContainsString( 'bricks-mcp-audit-page', $text );
		$this->assertStringContainsString( 'bricks-mcp/update-seo', $text );
		$this->assertStringContainsString( 'bricks-mcp/diagnose-page', $text );
	}

	public function test_start_here_text_is_left_alone_when_every_ability_is_disabled(): void {
		update_option( Options::OPTION, [ 'disabled_abilities' => array_keys( Registrar::definitions() ) ] );

		$this->assertSame( "# Start\n", ( new Guide() )->append_start_here_pointer( '# Start' ) );
	}
}
