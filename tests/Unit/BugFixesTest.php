<?php
/**
 * Tests for Phase 38 bug fixes in BricksService.php.
 *
 * Verifies structural presence of:
 * - BUG-01: Meta key resolution for header/footer templates (resolve_elements_meta_key)
 * - BUG-02: Read-back verification for all 5 global class write methods
 * - D-05: try/finally wrapping for all 7 unhook/rehook call sites
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-assertion tests for Phase 38 bug fixes.
 *
 * These tests verify the structural presence of fixes by inspecting the
 * BricksService.php source code for required patterns. This matches the
 * established pattern from SaveElementsTest.php.
 */
final class BugFixesTest extends TestCase {

	/**
	 * Path to BricksService.php.
	 *
	 * @var string
	 */
	private string $service_path;

	/**
	 * Source code of BricksService.php.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service_path = dirname( __DIR__, 2 ) . '/includes/MCP/Services/BricksService.php';
		$this->assertFileExists( $this->service_path, 'BricksService.php must exist' );
		$this->source = (string) file_get_contents( $this->service_path );
	}

	/**
	 * Extract a method body from the source by method name.
	 *
	 * @param string $method_name Method name (without parentheses).
	 * @param int    $length      Number of characters to extract from the method start.
	 * @return string Substring of source starting at the method declaration.
	 */
	private function extract_method_body( string $method_name, int $length = 2000 ): string {
		$start = strpos( $this->source, 'function ' . $method_name . '(' );
		$this->assertNotFalse( $start, "Method {$method_name}() must exist in BricksService.php" );
		return substr( $this->source, $start, $length );
	}

	// -------------------------------------------------------------------------
	// BUG-01: Meta key resolution for header/footer templates
	// -------------------------------------------------------------------------

	/**
	 * Test that get_elements() delegates meta key resolution to resolve_elements_meta_key().
	 *
	 * @return void
	 */
	public function test_get_elements_uses_resolve_meta_key(): void {
		$body = $this->extract_method_body( 'get_elements' );
		$this->assertStringContainsString(
			'resolve_elements_meta_key',
			$body,
			'get_elements() must call $this->resolve_elements_meta_key() instead of self::META_KEY directly'
		);
	}

	/**
	 * Test that resolve_elements_meta_key() method exists and handles header templates.
	 *
	 * @return void
	 */
	public function test_resolve_meta_key_handles_header(): void {
		$body = $this->extract_method_body( 'resolve_elements_meta_key' );
		$this->assertStringContainsString(
			'_bricks_page_header_2',
			$body,
			'resolve_elements_meta_key() must contain the header meta key _bricks_page_header_2'
		);
	}

	/**
	 * Test that resolve_elements_meta_key() method exists and handles footer templates.
	 *
	 * @return void
	 */
	public function test_resolve_meta_key_handles_footer(): void {
		$body = $this->extract_method_body( 'resolve_elements_meta_key' );
		$this->assertStringContainsString(
			'_bricks_page_footer_2',
			$body,
			'resolve_elements_meta_key() must contain the footer meta key _bricks_page_footer_2'
		);
	}

	/**
	 * Test that export_template() uses resolve_elements_meta_key() for content retrieval.
	 *
	 * @return void
	 */
	public function test_export_template_uses_resolve_meta_key(): void {
		$body = $this->extract_method_body( 'export_template', 1000 );
		$this->assertStringContainsString(
			'resolve_elements_meta_key',
			$body,
			'export_template() must call resolve_elements_meta_key() instead of self::META_KEY directly'
		);
	}

	// -------------------------------------------------------------------------
	// BUG-02: Read-back verification for global class write methods
	// -------------------------------------------------------------------------

	/**
	 * Test that create_global_class() performs read-back verification after update_option.
	 *
	 * @return void
	 */
	public function test_create_global_class_has_readback(): void {
		$body = $this->extract_method_body( 'create_global_class', 3000 );
		$this->assertStringContainsString(
			'wp_cache_delete',
			$body,
			'create_global_class() must call wp_cache_delete() for read-back verification'
		);
		$this->assertStringContainsString(
			'global_class_create_failed',
			$body,
			'create_global_class() must return WP_Error with code global_class_create_failed on verification failure'
		);
	}

	/**
	 * Test that update_global_class() performs read-back verification after update_option.
	 *
	 * @return void
	 */
	public function test_update_global_class_has_readback(): void {
		$body = $this->extract_method_body( 'update_global_class', 3000 );
		$this->assertStringContainsString(
			'wp_cache_delete',
			$body,
			'update_global_class() must call wp_cache_delete() for read-back verification'
		);
		$this->assertStringContainsString(
			'global_class_update_failed',
			$body,
			'update_global_class() must return WP_Error with code global_class_update_failed on verification failure'
		);
	}

	/**
	 * Test that trash_global_class() performs read-back verification after update_option.
	 *
	 * @return void
	 */
	public function test_trash_global_class_has_readback(): void {
		$body = $this->extract_method_body( 'trash_global_class', 2500 );
		$this->assertStringContainsString(
			'wp_cache_delete',
			$body,
			'trash_global_class() must call wp_cache_delete() for read-back verification'
		);
		$this->assertStringContainsString(
			'global_class_trash_failed',
			$body,
			'trash_global_class() must return WP_Error with code global_class_trash_failed on verification failure'
		);
	}

	/**
	 * Test that batch_create_global_classes() performs read-back verification.
	 *
	 * @return void
	 */
	public function test_batch_create_has_readback(): void {
		$body = $this->extract_method_body( 'batch_create_global_classes', 3000 );
		$this->assertStringContainsString(
			'wp_cache_delete',
			$body,
			'batch_create_global_classes() must call wp_cache_delete() for read-back verification'
		);
	}

	/**
	 * Test that batch_trash_global_classes() performs read-back verification.
	 *
	 * @return void
	 */
	public function test_batch_trash_has_readback(): void {
		$body = $this->extract_method_body( 'batch_trash_global_classes', 3000 );
		$this->assertStringContainsString(
			'wp_cache_delete',
			$body,
			'batch_trash_global_classes() must call wp_cache_delete() for read-back verification'
		);
	}

	// -------------------------------------------------------------------------
	// D-05: try/finally wrapping for all 7 unhook/rehook call sites
	// -------------------------------------------------------------------------

	/**
	 * Test that all unhook_bricks_meta_filters() call sites are wrapped in try/finally.
	 *
	 * Counts occurrences where unhook is immediately followed by a try block.
	 * There must be at least 7 such sites (excluding the method definition itself).
	 *
	 * @return void
	 */
	public function test_all_unhook_sites_have_try_finally(): void {
		$count = preg_match_all(
			'/unhook_bricks_meta_filters\(\);\s*\n\s*try\s*\{/',
			$this->source,
			$matches
		);
		$this->assertGreaterThanOrEqual(
			7,
			$count,
			"All 7 unhook_bricks_meta_filters() call sites must be immediately followed by try { (found {$count})"
		);
	}

	/**
	 * Test that all try/finally blocks call rehook_bricks_meta_filters() in the finally clause.
	 *
	 * Counts occurrences of finally blocks that call rehook. Must be >= 7.
	 *
	 * @return void
	 */
	public function test_all_finally_blocks_call_rehook(): void {
		$count = preg_match_all(
			'/finally\s*\{\s*\n\s*\$this->rehook_bricks_meta_filters/',
			$this->source,
			$matches
		);
		$this->assertGreaterThanOrEqual(
			7,
			$count,
			"All 7 finally blocks must call \$this->rehook_bricks_meta_filters() (found {$count})"
		);
	}
}
