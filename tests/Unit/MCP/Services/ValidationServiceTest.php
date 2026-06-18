<?php
/**
 * ValidationService unit tests — fail-closed behavior.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use PHPUnit\Framework\TestCase;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\MCP\Services\SchemaGenerator;

/**
 * Tests for ValidationService::validate_arguments() fail-closed behavior.
 *
 * Verifies that:
 * - Valid arguments pass validation
 * - Invalid arguments return WP_Error
 * - The source code fails closed (no fail-open paths remain)
 */
final class ValidationServiceTest extends TestCase {

	private ValidationService $service;

	protected function setUp(): void {
		parent::setUp();

		$schema_generator = $this->createMock( SchemaGenerator::class );
		$this->service    = new ValidationService( $schema_generator );
	}

	/**
	 * Test that valid arguments pass validation when Opis is available.
	 */
	public function test_validate_arguments_returns_true_for_valid_args(): void {
		$result = $this->service->validate_arguments(
			[ 'post_type' => 'post' ],
			[
				'type'       => 'object',
				'properties' => [
					'post_type' => [ 'type' => 'string' ],
				],
			],
			'get_posts'
		);

		$this->assertTrue( $result );
	}

	/**
	 * Test that invalid arguments return WP_Error with 'invalid_arguments' code.
	 */
	public function test_validate_arguments_returns_error_for_invalid_args(): void {
		$result = $this->service->validate_arguments(
			[ 'post_type' => 12345 ],
			[
				'type'       => 'object',
				'properties' => [
					'post_type' => [ 'type' => 'string' ],
				],
			],
			'get_posts'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Test that validate_arguments never silently passes invalid arguments.
	 * Ensures the return type is either true or WP_Error, never a truthy non-true value.
	 */
	public function test_validate_arguments_return_type_is_strict(): void {
		$valid_result = $this->service->validate_arguments(
			[ 'count' => 5 ],
			[
				'type'       => 'object',
				'properties' => [
					'count' => [ 'type' => 'integer' ],
				],
			],
			'test_tool'
		);
		$this->assertIsBool( $valid_result );
		$this->assertTrue( $valid_result );

		$invalid_result = $this->service->validate_arguments(
			[ 'count' => 'not_a_number' ],
			[
				'type'       => 'object',
				'properties' => [
					'count' => [ 'type' => 'integer' ],
				],
			],
			'test_tool'
		);
		$this->assertInstanceOf( \WP_Error::class, $invalid_result );
	}

	/**
	 * Test that the source code contains fail-closed guards (no "return true" in error paths).
	 *
	 * This is a source-level assertion: the three error paths in validate_arguments()
	 * must return WP_Error, not true. This catches regressions if someone reverts to fail-open.
	 */
	public function test_source_code_has_fail_closed_guards(): void {
		$source = file_get_contents(
			BRICKS_MCP_PLUGIN_DIR . 'includes/MCP/Services/ValidationService.php'
		);
		$this->assertIsString( $source );

		// Extract the validate_arguments method body.
		// Find the method and extract until the next public/private/protected method or class end.
		$method_start = strpos( $source, 'public function validate_arguments(' );
		$this->assertNotFalse( $method_start, 'validate_arguments method must exist' );

		// Find the next method declaration after validate_arguments.
		$next_method = strpos( $source, "\tpublic function ", $method_start + 10 );
		if ( false === $next_method ) {
			$next_method = strpos( $source, "\tprivate function ", $method_start + 10 );
		}
		$this->assertNotFalse( $next_method, 'There should be another method after validate_arguments' );

		$method_body = substr( $source, $method_start, $next_method - $method_start );

		// Guard 1: class_exists check must NOT return true.
		$this->assertStringContainsString( "class_exists( Validator::class )", $method_body );
		// After the class_exists check, the next return must be WP_Error, not true.
		$class_exists_pos = strpos( $method_body, 'class_exists( Validator::class )' );
		$after_check      = substr( $method_body, $class_exists_pos, 200 );
		$this->assertStringContainsString( 'validation_unavailable', $after_check,
			'Missing Opis class must return validation_unavailable WP_Error'
		);
		$this->assertStringNotContainsString( "return true", $after_check,
			'Missing Opis class must NOT return true (fail-open)'
		);

		// Guard 2: null JSON decode must NOT return true.
		$null_check_pos = strpos( $method_body, 'null === $arguments_json' );
		$this->assertNotFalse( $null_check_pos, 'Null JSON check must exist' );
		$after_null = substr( $method_body, $null_check_pos, 200 );
		$this->assertStringContainsString( 'validation_error', $after_null,
			'Null JSON decode must return validation_error WP_Error'
		);
		$this->assertStringNotContainsString( "return true", $after_null,
			'Null JSON decode must NOT return true (fail-open)'
		);

		// Guard 3: catch(\Throwable) must NOT return true.
		$catch_pos = strpos( $method_body, 'catch ( \\Throwable' );
		$this->assertNotFalse( $catch_pos, 'Throwable catch block must exist' );
		$after_catch = substr( $method_body, $catch_pos, 200 );
		$this->assertStringContainsString( 'validation_error', $after_catch,
			'Throwable catch must return validation_error WP_Error'
		);
		$this->assertStringNotContainsString( "return true", $after_catch,
			'Throwable catch must NOT return true (fail-open)'
		);
	}

	/**
	 * validate_with_opis must fail closed on exceptions (DATA-04).
	 */
	public function test_validate_with_opis_fails_closed_on_exception(): void {
		$source = file_get_contents(
			BRICKS_MCP_PLUGIN_DIR . 'includes/MCP/Services/ValidationService.php'
		);
		$this->assertIsString( $source );

		$method_start = strpos( $source, 'private function validate_with_opis(' );
		$this->assertNotFalse( $method_start );

		$catch_pos = strpos( $source, 'catch ( \\Throwable', $method_start );
		$this->assertNotFalse( $catch_pos );

		$after_catch = substr( $source, $catch_pos, 300 );
		$this->assertStringContainsString( 'validation_internal_error', $after_catch );
		$this->assertStringContainsString( 'WP_Error', $after_catch );
		$this->assertStringNotContainsString( 'return []', $after_catch );
	}
}
