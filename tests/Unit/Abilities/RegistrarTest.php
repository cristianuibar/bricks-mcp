<?php
/**
 * Tests that every module's abilities register on the shared MCP surface.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\Registrar;
use BricksMCP\Options;
use PHPUnit\Framework\TestCase;

/**
 * Registrar behaviour.
 */
final class RegistrarTest extends TestCase {

	protected function setUp(): void {
		bricks_mcp_test_reset();
	}

	public function test_every_ability_is_well_formed_and_registered_as_public(): void {
		$registrar = new Registrar();
		$registrar->register_categories();
		$registrar->register_abilities();

		$definitions = Registrar::definitions();
		$this->assertNotEmpty( $definitions );

		foreach ( $definitions as $name => $args ) {
			$this->assertMatchesRegularExpression( '#^bricks-mcp/[a-z0-9-]+$#', $name );
			$this->assertArrayHasKey( $args['category'], Registrar::CATEGORIES, $name );
			$this->assertIsCallable( $args['execute_callback'], $name );
			$this->assertIsCallable( $args['permission_callback'], $name );
			$this->assertNotSame( '', trim( (string) $args['description'] ), $name );

			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertTrue( $ability->get_meta()['public'], $name );
		}

		$this->assertSame( array_keys( Registrar::CATEGORIES ), array_keys( $GLOBALS['_bricks_mcp_test_ability_categories'] ) );
	}

	public function test_disabled_abilities_are_not_registered(): void {
		$name = array_key_first( Registrar::definitions() );
		update_option( Options::OPTION, [ 'disabled_abilities' => [ $name ] ] );

		( new Registrar() )->register_abilities();

		$this->assertNull( wp_get_ability( $name ) );
		$this->assertCount( count( Registrar::definitions() ) - 1, $GLOBALS['_bricks_mcp_test_abilities'] );
	}
}
