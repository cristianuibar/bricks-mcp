<?php
/**
 * Tests for the shared permission checks.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\Access;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class AccessTest extends TestCase {

	private const POST_ID = 42;

	protected function setUp(): void {
		parent::setUp();
		bricks_mcp_test_reset();
		bricks_mcp_test_add_post( [ 'ID' => self::POST_ID ] );
	}

	/**
	 * The builder gate fails closed when Bricks no longer ships the Capabilities class.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_builder_gate_denies_when_capabilities_class_is_missing(): void {
		$GLOBALS['_bricks_mcp_test_without_bricks_capabilities'] = true;

		$result = Access::edit_post( self::POST_ID );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_builder_check_unavailable', $result->get_error_code() );
		$this->assertTrue( Access::edit_post( self::POST_ID, false ) );
	}

	/**
	 * The builder gate fails closed when the Capabilities class lacks the builder check.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_builder_gate_denies_when_builder_check_method_is_missing(): void {
		eval( 'namespace Bricks; class Capabilities {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a changed Bricks class in this separate test process only.

		$result = Access::edit_post( self::POST_ID );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_builder_check_unavailable', $result->get_error_code() );
	}

	/**
	 * Builder access is still decided by Bricks when its check is available.
	 *
	 * @return void
	 */
	public function test_builder_gate_follows_bricks_capabilities(): void {
		\Bricks\Capabilities::$can_use_builder = true;
		$this->assertTrue( Access::edit_post( self::POST_ID ) );

		\Bricks\Capabilities::$can_use_builder = false;
		$denied                                = Access::edit_post( self::POST_ID );
		\Bricks\Capabilities::$can_use_builder = true;

		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'bricks_mcp_forbidden_builder', $denied->get_error_code() );
	}
}
