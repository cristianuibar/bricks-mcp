<?php
/**
 * Router unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use BricksMCP\MCP\Router;

/**
 * Tests for the Router class — get_posts parameter allowlisting.
 *
 * WordPress function stubs are provided by tests/stubs/wp-functions.php
 * (loaded via bootstrap-simple.php) in the global namespace.
 */
final class RouterTest extends TestCase {

	private Router $router;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_bricks_mcp_test_current_user_can']    = true;
		$GLOBALS['_bricks_mcp_test_get_posts_return']     = [];
		$GLOBALS['_bricks_mcp_test_last_get_posts_args']  = [];
		$GLOBALS['_bricks_mcp_test_get_users_return']     = [];
		$GLOBALS['_bricks_mcp_test_last_get_users_args']  = [];

		$this->router = new Router();
	}

	/**
	 * Reset globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['_bricks_mcp_test_current_user_can'],
			$GLOBALS['_bricks_mcp_test_get_posts_return'],
			$GLOBALS['_bricks_mcp_test_last_get_posts_args'],
			$GLOBALS['_bricks_mcp_test_get_users_return'],
			$GLOBALS['_bricks_mcp_test_last_get_users_args']
		);

		parent::tearDown();
	}

	/**
	 * Helper to call tool_get_posts via the public tool_wordpress dispatcher.
	 *
	 * @param array<string, mixed> $extra_args Extra arguments to merge with defaults.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call_get_posts( array $extra_args = [] ): array|\WP_Error {
		return $this->router->tool_wordpress( array_merge( [ 'action' => 'get_posts' ], $extra_args ) );
	}

	/**
	 * Get the arguments that were passed to WP get_posts().
	 *
	 * @return array<string, mixed>
	 */
	private function get_captured_wp_query_args(): array {
		return $GLOBALS['_bricks_mcp_test_last_get_posts_args'] ?? [];
	}

	/**
	 * post_status must always be 'publish' even when attacker passes it.
	 */
	public function test_post_status_locked_to_publish(): void {
		$this->call_get_posts( [ 'post_status' => 'draft' ] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 'publish', $captured['post_status'] );
	}

	/**
	 * post_status 'any' must not override the publish default.
	 */
	public function test_post_status_any_blocked(): void {
		$this->call_get_posts( [ 'post_status' => 'any' ] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 'publish', $captured['post_status'] );
	}

	/**
	 * Dangerous WP_Query params must NOT pass through to the query.
	 */
	public function test_disallowed_params_stripped(): void {
		$this->call_get_posts( [
			'meta_key'     => 'secret_field',
			'meta_value'   => 'leaked',
			'meta_query'   => [ [ 'key' => 'x', 'value' => 'y' ] ],
			'tax_query'    => [ [ 'taxonomy' => 'hidden' ] ],
			'cache_results' => false,
		] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertArrayNotHasKey( 'meta_key', $captured );
		$this->assertArrayNotHasKey( 'meta_value', $captured );
		$this->assertArrayNotHasKey( 'meta_query', $captured );
		$this->assertArrayNotHasKey( 'tax_query', $captured );
		$this->assertArrayNotHasKey( 'cache_results', $captured );
	}

	/**
	 * Allowed params pass through with proper sanitization.
	 */
	public function test_allowed_params_pass_through(): void {
		$this->call_get_posts( [
			'post_type'      => 'page',
			'posts_per_page' => 5,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => 'hello',
			'paged'          => 2,
			'category_name'  => 'news',
			'tag'            => 'featured',
			'author'         => 3,
		] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 'page', $captured['post_type'] );
		$this->assertSame( 5, $captured['posts_per_page'] );
		$this->assertSame( 'title', $captured['orderby'] );
		$this->assertSame( 'ASC', $captured['order'] );
		$this->assertSame( 'hello', $captured['s'] );
		$this->assertSame( 2, $captured['paged'] );
		$this->assertSame( 'news', $captured['category_name'] );
		$this->assertSame( 'featured', $captured['tag'] );
		$this->assertSame( 3, $captured['author'] );
		$this->assertSame( 'publish', $captured['post_status'] );
	}

	/**
	 * posts_per_page is capped at 100.
	 */
	public function test_posts_per_page_capped_at_100(): void {
		$this->call_get_posts( [ 'posts_per_page' => 999 ] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 100, $captured['posts_per_page'] );
	}

	/**
	 * order only accepts ASC or DESC, defaults to DESC.
	 */
	public function test_order_only_accepts_asc_or_desc(): void {
		$this->call_get_posts( [ 'order' => 'RAND' ] );

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 'DESC', $captured['order'] );
	}

	/**
	 * Default values are correct when no args provided.
	 */
	public function test_defaults_when_no_args(): void {
		$this->call_get_posts();

		$captured = $this->get_captured_wp_query_args();
		$this->assertSame( 'post', $captured['post_type'] );
		$this->assertSame( 10, $captured['posts_per_page'] );
		$this->assertSame( 'date', $captured['orderby'] );
		$this->assertSame( 'DESC', $captured['order'] );
		$this->assertSame( 'publish', $captured['post_status'] );
	}

	// =========================================================================
	// get_users parameter allowlisting tests.
	// =========================================================================

	/**
	 * Helper to call tool_get_users via the public tool_wordpress dispatcher.
	 *
	 * @param array<string, mixed> $extra_args Extra arguments to merge with defaults.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call_get_users( array $extra_args = [] ): array|\WP_Error {
		return $this->router->tool_wordpress( array_merge( [ 'action' => 'get_users' ], $extra_args ) );
	}

	/**
	 * Get the arguments that were passed to WP get_users().
	 *
	 * @return array<string, mixed>
	 */
	private function get_captured_get_users_args(): array {
		return $GLOBALS['_bricks_mcp_test_last_get_users_args'] ?? [];
	}

	/**
	 * Dangerous params (search, meta_query, search_columns, login__in, include) must NOT pass through.
	 */
	public function test_get_users_disallowed_params_stripped(): void {
		$this->call_get_users( [
			'search'         => 'admin',
			'meta_query'     => [ [ 'key' => 'secret', 'value' => 'leaked' ] ],
			'search_columns' => [ 'user_login', 'user_email' ],
			'login__in'      => [ 'admin' ],
			'include'        => [ 1, 2, 3 ],
			'meta_key'       => 'secret_field',
			'meta_value'     => 'leaked',
		] );

		$captured = $this->get_captured_get_users_args();
		$this->assertArrayNotHasKey( 'search', $captured );
		$this->assertArrayNotHasKey( 'meta_query', $captured );
		$this->assertArrayNotHasKey( 'search_columns', $captured );
		$this->assertArrayNotHasKey( 'login__in', $captured );
		$this->assertArrayNotHasKey( 'include', $captured );
		$this->assertArrayNotHasKey( 'meta_key', $captured );
		$this->assertArrayNotHasKey( 'meta_value', $captured );
	}

	/**
	 * Allowed params pass through with proper values.
	 */
	public function test_get_users_allowed_params_pass_through(): void {
		$this->call_get_users( [
			'number'  => 25,
			'role'    => 'editor',
			'orderby' => 'registered',
			'order'   => 'DESC',
			'paged'   => 3,
		] );

		$captured = $this->get_captured_get_users_args();
		$this->assertSame( 25, $captured['number'] );
		$this->assertSame( 'editor', $captured['role'] );
		$this->assertSame( 'registered', $captured['orderby'] );
		$this->assertSame( 'DESC', $captured['order'] );
		$this->assertSame( 3, $captured['paged'] );
	}

	/**
	 * number is capped at 100.
	 */
	public function test_get_users_number_capped_at_100(): void {
		$this->call_get_users( [ 'number' => 999 ] );

		$captured = $this->get_captured_get_users_args();
		$this->assertSame( 100, $captured['number'] );
	}

	/**
	 * orderby only accepts allowlisted values.
	 */
	public function test_get_users_orderby_rejects_invalid(): void {
		$this->call_get_users( [ 'orderby' => 'meta_value' ] );

		$captured = $this->get_captured_get_users_args();
		$this->assertSame( 'display_name', $captured['orderby'] );
	}

	/**
	 * order only accepts ASC or DESC.
	 */
	public function test_get_users_order_rejects_invalid(): void {
		$this->call_get_users( [ 'order' => 'RAND' ] );

		$captured = $this->get_captured_get_users_args();
		$this->assertSame( 'ASC', $captured['order'] );
	}

	/**
	 * Default values are correct when no args provided.
	 */
	public function test_get_users_defaults(): void {
		$this->call_get_users();

		$captured = $this->get_captured_get_users_args();
		$this->assertSame( 10, $captured['number'] );
		$this->assertSame( '', $captured['role'] );
		$this->assertSame( 'display_name', $captured['orderby'] );
		$this->assertSame( 'ASC', $captured['order'] );
		$this->assertSame( 1, $captured['paged'] );
		// Only these 5 keys should exist.
		$this->assertCount( 5, $captured );
	}

	/**
	 * register_tool() accepts optional metadata and exposes it in tools/list.
	 *
	 * @return void
	 */
	public function test_register_tool_accepts_v2_metadata(): void {
		$this->router->register_tool(
			'test_tool',
			'Test tool',
			[ 'type' => 'object' ],
			static fn (): array => [],
			[ 'readOnlyHint' => true ],
			[
				'type'                 => 'object',
				'additionalProperties' => true,
			],
			[ 'limit' => 10 ]
		);

		$ref      = new \ReflectionProperty( $this->router, 'tools' );
		$ref->setAccessible( true );
		$registry = $ref->getValue( $this->router );
		$tool     = $registry['test_tool'] ?? null;

		$this->assertNotNull( $tool );
		$this->assertSame( [ 'readOnlyHint' => true ], $tool['annotations'] );
		$this->assertSame(
			[
				'type'                 => 'object',
				'additionalProperties' => true,
			],
			$tool['outputSchema']
		);
		$this->assertSame( [ 'limit' => 10 ], $tool['defaults'] );
	}

	/**
	 * register_tool() auto-populates metadata and response_format for consolidated tools.
	 *
	 * @return void
	 */
	public function test_register_tool_autopopulates_v2_metadata_when_omitted(): void {
		$this->router->register_tool(
			'legacy_tool',
			'Legacy tool',
			[ 'type' => 'object' ],
			static fn (): array => []
		);

		$ref      = new \ReflectionProperty( $this->router, 'tools' );
		$ref->setAccessible( true );
		$registry = $ref->getValue( $this->router );
		$tool     = $registry['legacy_tool'] ?? null;

		$this->assertNotNull( $tool );
		$this->assertArrayHasKey( 'annotations', $tool );
		$this->assertArrayHasKey( 'outputSchema', $tool );
		$this->assertArrayNotHasKey( 'defaults', $tool );
		$this->assertArrayHasKey( 'response_format', $tool['inputSchema']['properties'] );
	}

	/**
	 * tools/list exposes the canonical content tool and hides the legacy wordpress tool.
	 *
	 * @return void
	 */
	public function test_available_tools_prefer_canonical_content_tool(): void {
		$names = array_column( $this->router->get_available_tools(), 'name' );

		$this->assertContains( 'content', $names );
		$this->assertContains( 'get_site_info', $names );
		$this->assertNotContains( 'wordpress', $names );
	}

	/**
	 * The v2 visible tool surface is intentionally capped at 11 canonical tools.
	 *
	 * @return void
	 */
	public function test_visible_tool_surface_count_is_11(): void {
		$ref   = new \ReflectionClass( Router::class );
		$names = $ref->getConstant( 'VISIBLE_TOOL_NAMES' );

		$this->assertCount( 11, $names );
		$this->assertSame( 'content', $names[3] );
		$this->assertSame( 'design', $names[5] );
	}

	/**
	 * Legacy tool names resolve to canonical aliases with injected actions.
	 *
	 * @return void
	 */
	public function test_legacy_aliases_resolve_to_canonical_tools(): void {
		$method = new \ReflectionMethod( $this->router, 'resolve_tool_call' );
		$method->setAccessible( true );

		$get_posts = $method->invoke( $this->router, 'get_posts', [] );
		$page_list = $method->invoke( $this->router, 'list_pages', [] );

		$this->assertSame( 'content', $get_posts['name'] );
		$this->assertSame( 'get_posts', $get_posts['arguments']['action'] );
		$this->assertSame( 'content', $page_list['name'] );
		$this->assertSame( 'list', $page_list['arguments']['action'] );
	}

	/**
	 * Hidden page_diagnose is registered when Bricks tools load but excluded from tools/list.
	 *
	 * @return void
	 */
	public function test_page_diagnose_is_hidden_from_tools_list(): void {
		$visible = array_column( $this->router->get_available_tools(), 'name' );
		$this->assertNotContains( 'page_diagnose', $visible );

		$ref   = new \ReflectionClass( Router::class );
		$names = $ref->getConstant( 'VISIBLE_TOOL_NAMES' );
		$this->assertNotContains( 'page_diagnose', $names );

		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/MCP/Router.php'
		);
		$this->assertIsString( $source );
		$this->assertStringContainsString( "'page_diagnose'", $source );
		$this->assertStringContainsString( 'tool_page_diagnose', $source );
	}

	/**
	 * Content tool view enum includes visual for DIAG-04.
	 *
	 * @return void
	 */
	public function test_content_tool_view_enum_includes_visual(): void {
		$schema_method = new \ReflectionMethod( $this->router, 'get_content_tool_schema' );
		$schema_method->setAccessible( true );
		$schema = $schema_method->invoke( $this->router );
		$enum   = $schema['properties']['view']['enum'] ?? array();

		$this->assertContains( 'visual', $enum );
		$this->assertContains( 'detail', $enum );
		$this->assertContains( 'summary', $enum );
	}
}
