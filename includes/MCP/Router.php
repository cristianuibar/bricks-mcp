<?php
/**
 * File: includes/MCP/Router.php
 *
 * MCP Router implementation.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 * @version 1.5.5
 *
 * Changelog:
 * ----------
 * 1.5.5 — 2026-03-31
 *   - FIX (Code quality): Removed 4 orphaned/displaced PHPDoc blocks that had
 *                         been separated from their function declarations (lines
 *                         1877-1913). Restored correct @param/@return docblocks
 *                         on require_bricks(), enrich_error(), apply_smart_defaults(),
 *                         and apply_minimal_format(). No functional change.
 *
 * 1.5.4 — 2026-03-31
 *   - FIX (Critical): register_output_schemas() — 6 occurrences of corrupted
 *                     array access syntax ($this->toolsarray('key')) restored to
 *                     correct PHP array notation ($this->tools['key']). The
 *                     corruption caused a fatal PHP parse error on every request,
 *                     making the entire MCP endpoint return HTTP 500 and preventing
 *                     any tool from executing.
 *
 * 1.5.3 — 2026-03-31
 *   - FIX (Critical): tool_page_diagnose(), tool_page_spacing_audit(),
 *                     tool_page_screenshot(), tool_page_visual_review() —
 *                     All $args array accesses were corrupted as $argsarray('key')
 *                     (12 occurrences across 4 methods) causing a fatal PHP parse
 *                     error that crashed the entire MCP endpoint with HTTP 500.
 *                     Corrected to $args['key'] throughout.
 *   - FIX (Consistency): Changed tool_page_diagnose(), tool_page_spacing_audit(),
 *                        tool_page_screenshot(), tool_page_visual_review() from
 *                        public to private to match all other tool handler methods.
 *   - FIX (Code quality): Expanded single-line if/return blocks in the 4 new
 *                         handlers to proper multi-line format. Removed redundant
 *                         is_wp_error() ternary no-ops (result was returned either
 *                         way). Added proper PHPDoc blocks to all 4 methods.
 *   - FIX (Type safety): Added explicit (string) cast on $viewport and $focus
 *                        before passing to BricksService methods.
 *
 * 1.5.2 — 2026-03-31
 *   - FIX (Issue 9):    tool_get_posts() — replaced unsafe wp_list_pluck() +
 *                       update_postmeta_cache() with null-safe ID extraction to
 *                       prevent "Attempt to assign property thumbnails_cached on
 *                       array" crash caused by WP Rocket / Rank Math hook interference.
 *   - FIX (GitHub #5):  tool_get_posts() — added $allowed_orderby allowlist and
 *                       validated post_type against get_post_types() to prevent
 *                       WP_Query parameter injection.
 *   - FIX (GitHub #6):  tool_get_users() — validated role parameter against
 *                       wp_roles()->get_names() to prevent WP_User_Query returning
 *                       all users when an invalid role string is supplied.
 *   - FIX (Issue 11 /
 *     GitHub #8):       tool_get_users() — added server-side manage_options
 *                       capability check before exposing PII (email/login). Any
 *                       authenticated MCP client could previously pass include_pii=true
 *                       regardless of their WordPress role.
 *   - FIX (Issue 5a):   Corrected misplaced PHPDoc blocks — register_output_schemas()
 *                       and require_bricks() docblocks were displaced from their
 *                       function declarations.
 *   - FIX (Issue 5b):   register_bricks_tools() changed from public to private.
 *                       Updated add_action() calls to use closures so WordPress
 *                       can invoke the private method correctly.
 *   - FIX (Issue 5c):   Normalised array syntax to array() in register_output_schemas()
 *                       and PR#29 tool registrations / handlers (was using [] short
 *                       syntax inconsistently against codebase standard).
 *   - FIX (Issue 5d):   Moved apply_filters('bricks_mcp_tools') from
 *                       register_default_tools() (fired with only 2 tools registered)
 *                       to the end of register_bricks_tools() (fires after all tools
 *                       are registered). Third-party filter hooks now see the full list.
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ElementIdGenerator;
use BricksMCP\MCP\Services\MediaService;
use BricksMCP\MCP\Services\MenuService;
use BricksMCP\MCP\Services\SchemaGenerator;
use BricksMCP\MCP\Services\ValidationService;
use BricksMCP\Plugin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Router class.
 *
 * Handles routing of MCP tool calls to their respective handlers.
 */
final class Router {

	/**
	 * WordPress option name for Bricks component definitions.
	 *
	 * @var string
	 */
	private const COMPONENTS_OPTION = 'bricks_components';

	/**
	 * Registered tools.
	 *
	 * @var array<string, array{name: string, description: string, inputSchema: array, handler: callable}>
	 */
	private array $tools = array();

	/**
	 * Bricks service instance.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Schema generator instance.
	 *
	 * @var SchemaGenerator
	 */
	private SchemaGenerator $schema_generator;

	/**
	 * Validation service instance.
	 *
	 * @var ValidationService
	 */
	private ValidationService $validation_service;

	/**
	 * Media service instance.
	 *
	 * @var MediaService
	 */
	private MediaService $media_service;

	/**
	 * Menu service instance.
	 *
	 * @var MenuService
	 */
	private MenuService $menu_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->schema_generator   = new SchemaGenerator();
		$this->validation_service = new ValidationService( $this->schema_generator );
		$this->bricks_service     = new BricksService();
		$this->bricks_service->set_validation_service( $this->validation_service );
		$this->media_service = new MediaService();
		$this->menu_service  = new MenuService();

		$this->register_default_tools();

		// Defer Bricks tool registration until themes are loaded.
		// Bricks is a theme, so \Bricks\Elements isn't available on plugins_loaded.
		if ( did_action( 'after_setup_theme' ) ) {
			$this->register_bricks_tools();
			$this->register_output_schemas();
		} else {
			add_action( 'after_setup_theme', function(): void { $this->register_bricks_tools(); }, 20 );
			add_action( 'after_setup_theme', function(): void { $this->register_output_schemas(); }, 25 );
		}

		// Flush schema cache when plugins are updated.
		add_action(
			'upgrader_process_complete',
			function (): void {
				$this->schema_generator->flush_cache();
			},
			10,
			0
		);
	}

	/**
	 * Register default tools.
	 *
	 * @return void
	 */
	private function register_default_tools(): void {
		// Get site info tool.
		$this->register_tool(
			'get_site_info',
			__( "Get WordPress site info or run MCP connection diagnostics. Call with action=diagnose when troubleshooting connection issues.\n\nActions:\n- info: Site name, URL, version, timezone, charset (default)\n- diagnose: Full connection health check — REST API, App Passwords, security plugins, hosting, MCP endpoint status", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'enum'        => array( 'info', 'diagnose' ),
						'description' => __( 'Action to perform (default: info)', 'bricks-mcp' ),
					),
				),
			),
			array( $this, 'tool_get_site_info' ),
		array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		)
		);

		// WordPress consolidated tool (replaces get_posts, get_post, get_users, get_plugins).
		$this->register_tool(
			'wordpress',
			__( "Read WordPress posts, users, and plugins. Use this for general WordPress data queries not specific to Bricks.\n\nWHEN: Use get_posts to list content before editing. Use get_plugins to check if a plugin is active before using its features. Use get_users to find author IDs.\n\nActions:\n- get_posts: List posts (optional: post_type, posts_per_page=20, orderby, order)\n- get_post: Single post by ID (requires: id)\n- get_users: List users (optional: role, number)\n- get_plugins: List all plugins with active status (optional: status=all|active|inactive)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'get_posts', 'get_post', 'get_users', 'get_plugins' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_type'      => array(
						'type'        => 'string',
						'description' => __( 'Post type to query (get_posts: default post)', 'bricks-mcp' ),
					),
					'posts_per_page' => array(
						'type'        => 'integer',
						'description' => __( 'Number of posts to return (get_posts: default 10, max 100)', 'bricks-mcp' ),
					),
					'orderby'        => array(
						'type'        => 'string',
						'description' => __( 'Order by field (get_posts: date, title, modified, etc.)', 'bricks-mcp' ),
					),
					'order'          => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'description' => __( 'Sort order (get_posts: ASC or DESC)', 'bricks-mcp' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post ID (get_post: required)', 'bricks-mcp' ),
					),
					'role'           => array(
						'type'        => 'string',
						'description' => __( 'Filter by user role (get_users)', 'bricks-mcp' ),
					),
					'number'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of users to return (get_users: default 10)', 'bricks-mcp' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive' ),
						'description' => __( 'Filter by plugin status (get_plugins)', 'bricks-mcp' ),
					),
					'include_pii'    => array(
						'type'        => 'boolean',
						'description' => __( 'Include sensitive fields (email, login). Warning: data may be logged by AI services. (get_users: default false)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_wordpress' ),
		array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		)
		);

		// NOTE: apply_filters( 'bricks_mcp_tools' ) is intentionally NOT called here.
		// It is called at the end of register_bricks_tools() after ALL tools have been
		// registered, so third-party plugins see the complete tool list. (Issue 5d fix)
	}

	/**
	 * Register a tool.
	 *
	 * @param string   $name             Tool name.
	 * @param string   $description      Tool description.
	 * @param array    $input_schema     Tool input schema.
	 * @param callable $handler          Tool handler callback.
	 * @return void
	 */
	public function register_tool( string $name, string $description, array $input_schema, callable $handler, array $annotations = [], array $output_schema = [] ): void {
		$entry = array(
			'name'        => $name,
			'description' => $description,
			'inputSchema' => $input_schema,
			'handler'     => $handler,
		);
		if ( ! empty( $annotations ) ) {
			$entry['annotations'] = $annotations;
		}
		if ( ! empty( $output_schema ) ) {
			$entry['outputSchema'] = $output_schema;
		}
		$this->tools[ $name ] = $entry;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Additional routes can be registered here.
	}

	/**
	 * Get available tools in MCP format.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}> Tools list.
	 */
	public function get_available_tools( string $protocol_version = '2025-03-26' ): array {
		$tools = array();

		// outputSchema was introduced in MCP spec 2025-11-25.
		// Clients negotiating the older 2025-03-26 spec do not understand it
		// and will silently fail on any tool that includes it.
		$include_output_schema = ( '2025-11-25' === $protocol_version );

		foreach ( $this->tools as $tool ) {
			$entry = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
			if ( ! empty( $tool['annotations'] ) ) {
				$entry['annotations'] = $tool['annotations'];
			}
			if ( $include_output_schema && ! empty( $tool['outputSchema'] ) ) {
				$entry['outputSchema'] = $tool['outputSchema'];
			}
			$tools[] = $entry;
		}

		return $tools;
	}

	/**
	 * Execute a tool.
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return \WP_REST_Response The response.
	 */
	public function execute_tool( string $name, array $arguments ): \WP_REST_Response {
		if ( ! isset( $this->tools[ $name ] ) ) {
			return Response::error(
				'unknown_tool',
				/* translators: %s: Tool name */
				sprintf( __( 'Unknown tool: %s', 'bricks-mcp' ), $name ),
				404
			);
		}

		$tool = $this->tools[ $name ];

		$capability = $this->get_tool_capability( $name );
		if ( null !== $capability && ! current_user_can( $capability ) ) {
			return Response::error(
				'bricks_mcp_forbidden',
				/* translators: %s: Required capability */
				sprintf( __( 'You do not have the required capability (%s) to use this tool.', 'bricks-mcp' ), $capability ),
				403
			);
		}

		// Apply smart defaults to reduce required parameters (Issue #26).
		$arguments = $this->apply_smart_defaults( $name, $arguments );

		// Strip meta-params before passing to handlers (response_format is consumed above).
		$handler_args = $arguments;
		unset( $handler_args['response_format'] );

		// Validate tool arguments against inputSchema.
		$validation = $this->validation_service->validate_arguments( $arguments, $tool['inputSchema'], $name );
		if ( is_wp_error( $validation ) ) {
			return Response::error(
				'invalid_arguments',
				$validation->get_error_message(),
				422
			);
		}

		try {
			$result = call_user_func( $tool['handler'], $handler_args );

			if ( is_wp_error( $result ) ) {
				return Response::tool_error( $result );
			}

			// Apply response_format optimization (Issue #23).
			$response_format = $arguments['response_format'] ?? 'full';
			if ( 'minimal' === $response_format && is_array( $result ) ) {
				$result = $this->apply_minimal_format( $result );
			}

			return Response::success(
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT ),
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			return Response::error(
				'tool_execution_error',
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Get the required WordPress capability for a tool.
	 *
	 * Returns null for public tools (no capability required).
	 *
	 * @param string $tool_name The tool name.
	 * @return string|null The required capability, or null if no capability is required.
	 */
	private function get_tool_capability( string $tool_name ): ?string {
		$public_tools = array(
			'get_builder_guide',
			'wordpress', // Per-action checks handled inside tool_wordpress().
		);

		if ( in_array( $tool_name, $public_tools, true ) ) {
			return null;
		}

		$read_tools = array(
			'get_site_info',
		);

		if ( in_array( $tool_name, $read_tools, true ) ) {
			return 'read';
		}

		// All other tools (bricks, page, element, template, etc.) require manage_options.
		return 'manage_options';
	}

	/**
	 * Tool: Get site info.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed> Site information.
	 */
	public function tool_get_site_info( array $args ): array {
		$action = $args['action'] ?? 'info';

		if ( 'diagnose' === $action ) {
			$runner = new \BricksMCP\Admin\DiagnosticRunner();
			$runner->register_defaults();
			return $runner->run_all();
		}

		// Default: return site info (existing behavior).
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => get_bloginfo( 'url' ),
			'language'    => get_bloginfo( 'language' ),
			'version'     => get_bloginfo( 'version' ),
			'charset'     => get_bloginfo( 'charset' ),
			'timezone'    => wp_timezone_string(),
		);
	}

	/**
	 * Tool: Get posts.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Posts list.
	 */
	private function tool_get_posts( array $args ): array {
		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		// GitHub #5: validate orderby against an allowlist to prevent WP_Query parameter injection.
		$allowed_orderby = array( 'date', 'title', 'modified', 'ID', 'author', 'name', 'menu_order' );
		$requested_orderby = isset( $args['orderby'] ) ? sanitize_text_field( (string) $args['orderby'] ) : 'date';
		$orderby = in_array( $requested_orderby, $allowed_orderby, true ) ? $requested_orderby : 'date';

		// GitHub #5: validate post_type against registered post types to prevent parameter injection.
		$requested_post_type = isset( $args['post_type'] ) ? sanitize_text_field( (string) $args['post_type'] ) : 'post';
		$registered_types    = array_keys( get_post_types() );
		$post_type           = in_array( $requested_post_type, $registered_types, true ) ? $requested_post_type : 'post';

		$query_args = array(
			'post_type'      => $post_type,
			'posts_per_page' => isset( $args['posts_per_page'] ) ? min( absint( $args['posts_per_page'] ), 100 ) : 10,
			'orderby'        => $orderby,
			'order'          => $order,
			'post_status'    => 'publish',
			's'              => isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '',
			'paged'          => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
			'category_name'  => isset( $args['category_name'] ) ? sanitize_text_field( (string) $args['category_name'] ) : '',
			'tag'            => isset( $args['tag'] ) ? sanitize_text_field( (string) $args['tag'] ) : '',
			'author'         => isset( $args['author'] ) ? absint( $args['author'] ) : 0,
		);

		$posts = get_posts( $query_args );

		// Issue 9: null-safe meta cache priming — third-party plugins (e.g. WP Rocket, Rank Math)
		// can modify the posts array mid-hook so that entries are plain arrays rather than WP_Post
		// objects. wp_list_pluck() + update_postmeta_cache() then crashes with
		// "Attempt to assign property thumbnails_cached on array". Extract IDs safely instead.
		$post_ids = array_values(
			array_filter(
				array_map(
					function ( $p ) {
						return is_object( $p ) && isset( $p->ID ) ? (int) $p->ID : 0;
					},
					$posts
				),
				function ( $id ) {
					return $id > 0;
				}
			)
		);
		if ( ! empty( $post_ids ) ) {
			update_postmeta_cache( $post_ids );
		}

		$result = array();

		foreach ( $posts as $post ) {
			$result[] = array(
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'slug'           => $post->post_name,
				'status'         => $post->post_status,
				'type'           => $post->post_type,
				'date'           => $post->post_date,
				'modified'       => $post->post_modified,
				'excerpt'        => $post->post_excerpt,
				'author'         => (int) $post->post_author,
				'permalink'      => get_permalink( $post->ID ),
				'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		return $result;
	}

	/**
	 * Tool: Get single post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Post data or error.
	 */
	private function tool_get_post( array $args ): array|\WP_Error {
		if ( empty( $args['id'] ) ) {
			return new \WP_Error( 'missing_id', __( 'Post ID is required. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post = get_post( (int) $args['id'] );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use get_posts or list_pages to find valid post IDs.', 'bricks-mcp' ),
					(int) $args['id']
				)
			);
		}

		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'author'         => (int) $post->post_author,
			'author_name'    => get_the_author_meta( 'display_name', $post->post_author ),
			'permalink'      => get_permalink( $post->ID ),
			'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
			'categories'     => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Tool: Get users.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>> Users list.
	 */
	private function tool_get_users( array $args ): array|\WP_Error {
		$allowed_orderby = array( 'display_name', 'registered', 'ID' );
		$allowed_order   = array( 'ASC', 'DESC' );

		// GitHub #6: validate role against registered WordPress roles to prevent WP_User_Query
		// returning all users when an invalid or empty role string is supplied.
		$requested_role    = isset( $args['role'] ) ? sanitize_text_field( (string) $args['role'] ) : '';
		$registered_roles  = array_keys( wp_roles()->get_names() );
		$role              = ( '' === $requested_role || in_array( $requested_role, $registered_roles, true ) )
			? $requested_role
			: '';

		$query_args = array(
			'number'  => min( isset( $args['number'] ) ? absint( $args['number'] ) : 10, 100 ),
			'role'    => $role,
			'orderby' => isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed_orderby, true )
				? $args['orderby']
				: 'display_name',
			'order'   => isset( $args['order'] ) && in_array( strtoupper( (string) $args['order'] ), $allowed_order, true )
				? strtoupper( (string) $args['order'] )
				: 'ASC',
			'paged'   => isset( $args['paged'] ) ? absint( $args['paged'] ) : 1,
		);

		$users  = get_users( $query_args );
		$result = array();

		// Issue 11 / GitHub #8: include_pii requires the caller to hold manage_options capability.
		// Without this server-side check any authenticated MCP client could pass include_pii=true
		// and extract all user emails and logins regardless of their WordPress role.
		$include_pii = ! empty( $args['include_pii'] ) && current_user_can( 'manage_options' );

		foreach ( $users as $user ) {
			$user_data = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'registered'   => $user->user_registered,
				'roles'        => $user->roles,
			);

			if ( $include_pii ) {
				$user_data['login'] = $user->user_login;
				$user_data['email'] = $user->user_email;
			}

			$result[] = $user_data;
		}

		return $result;
	}

	/**
	 * Tool: Get plugins.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, array<string, mixed>> Plugins list.
	 */
	private function tool_get_plugins( array $args ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$status         = $args['status'] ?? 'all';

		$result = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = in_array( $plugin_file, $active_plugins, true );

			if ( 'active' === $status && ! $is_active ) {
				continue;
			}

			if ( 'inactive' === $status && $is_active ) {
				continue;
			}

			$result[ $plugin_file ] = array(
				'name'        => $plugin_data['Name'],
				'version'     => $plugin_data['Version'],
				'description' => $plugin_data['Description'],
				'author'      => $plugin_data['Author'],
				'is_active'   => $is_active,
			);
		}

		return $result;
	}

	/**
	 * Register output schemas for key tools (Issue #25).
	 *
	 * Adds structured output type hints so MCP clients can validate
	 * and display responses more intelligently.
	 *
	 * @return void
	 */
	public function register_output_schemas(): void {
		$page_schema = array(
			'type' => 'object',
			'properties' => array(
				'id'            => array('type'=>'integer'),
				'title'         => array('type'=>'string'),
				'status'        => array('type'=>'string'),
				'permalink'     => array('type'=>'string'),
				'element_count' => array('type'=>'integer'),
			),
		);
		$element_schema = array(
			'type' => 'object',
			'properties' => array(
				'id'         => array('type'=>'string'),
				'name'       => array('type'=>'string'),
				'parent'     => array('type'=>array('string','integer')),
				'children'   => array('type'=>'array'),
				'settings'   => array('type'=>'object'),
			),
		);
		$diagnose_schema = array(
			'type' => 'object',
			'properties' => array(
				'post_id'       => array('type'=>'integer'),
				'status'        => array('type'=>'string','enum'=>array('clean','warnings','errors')),
				'issue_count'   => array('type'=>'integer'),
				'error_count'   => array('type'=>'integer'),
				'warning_count' => array('type'=>'integer'),
				'issues'        => array(
					'type'=>'array',
					'items'=>array(
						'type'=>'object',
						'properties'=>array(
							'element_id'=>array('type'=>array('string','null')),
							'severity'  =>array('type'=>'string','enum'=>array('error','warning')),
							'type'      =>array('type'=>'string'),
							'message'   =>array('type'=>'string'),
							'fix'       =>array('type'=>'string'),
						),
					),
				),
			),
		);

		// Attach output schemas to registered tools.
		if ( isset( $this->tools['page'] ) ) {
			$this->tools['page']['outputSchema'] = array( 'type' => 'object', 'description' => 'Page data, element tree, or list of pages depending on action' );
		}
		if ( isset( $this->tools['page_diagnose'] ) ) {
			$this->tools['page_diagnose']['outputSchema'] = $diagnose_schema;
		}
		if ( isset( $this->tools['element'] ) ) {
			$this->tools['element']['outputSchema'] = array( 'type' => 'object', 'description' => 'Element operation result with affected element ID and page element count' );
		}
		if ( isset( $this->tools['template'] ) ) {
			$this->tools['template']['outputSchema'] = array( 'type' => 'object', 'description' => 'Template data including id, title, type, status, and element array' );
		}
		if ( isset( $this->tools['global_class'] ) ) {
			$this->tools['global_class']['outputSchema'] = array( 'type' => 'object', 'description' => 'Global class operation result with class name, id, and styles' );
		}
		if ( isset( $this->tools['get_site_info'] ) ) {
			$this->tools['get_site_info']['outputSchema'] = array(
				'type'       => 'object',
				'properties' => array(
					'name'     => array( 'type' => 'string' ),
					'url'      => array( 'type' => 'string' ),
					'version'  => array( 'type' => 'string' ),
					'language' => array( 'type' => 'string' ),
					'timezone' => array( 'type' => 'string' ),
				),
			);
		}
	}

	/**
	 * Register Bricks Builder-specific tools.
	 *
	 * Only registers tools if Bricks Builder is active (STNG-05 gate).
	 * Non-Bricks tools continue working regardless of Bricks status.
	 * Called via add_action( 'after_setup_theme' ) from the constructor.
	 *
	 * Issue 5b: private — only invoked from the constructor / action hook.
	 * Issue 5d: apply_filters( 'bricks_mcp_tools' ) fires HERE, after ALL
	 *           tools are registered, so third-party code sees the full list.
	 *
	 * @return void
	 */
	private function register_bricks_tools(): void {
		// Gate: skip registration when Bricks is not installed.
		if ( ! $this->bricks_service->is_bricks_active() ) {
			return;
		}

		// Tool: get_builder_guide.
		$this->register_tool(
			'get_builder_guide',
			__( 'Essential reference for building with Bricks via MCP. Call this FIRST in any new conversation before building or editing pages. Contains element settings format, CSS composite keys, animation patterns, workflow tips, and common gotchas. Use section= to fetch only what you need.', 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'section' => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'settings', 'animations', 'interactions', 'dynamic_data', 'forms', 'components', 'popups', 'element_conditions', 'woocommerce', 'seo', 'custom_code', 'fonts', 'import_export', 'workflows', 'gotchas', 'connection_troubleshooting' ),
						'description' => __( 'Optional: return only a specific section of the guide. Use "woocommerce" for WooCommerce, "seo" for SEO optimization, "custom_code" for custom code, "fonts" for font management, "import_export" for import/export. Defaults to all.', 'bricks-mcp' ),
					),
				),
			),
			array( $this, 'tool_get_builder_guide' ),
		array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		)
		);

		// Bricks consolidated tool (replaces enable_bricks, disable_bricks, get_bricks_settings, get_breakpoints, get_element_schemas).
		$this->register_tool(
			'bricks',
			__( "Manage Bricks Builder settings, schemas, and global queries. Use schema actions to learn element/form/interaction formats before building.\n\nWHEN: Call get_element_schemas(catalog_only=true) to browse available elements. Call get_breakpoints to understand responsive keys. Call get_interaction_schema before adding animations. Call get_condition_schema before setting element visibility.\n\nActions:\n- enable/disable: Toggle Bricks editor on a post (requires: post_id)\n- get_settings: Global Bricks settings (optional: category)\n- get_breakpoints: Responsive breakpoint keys and composite key format\n- get_element_schemas: Element schemas (optional: element=name for one; catalog_only=true for name list)\n- get_dynamic_tags: Available dynamic data tags (optional: group filter)\n- get_query_types: Query loop object types and settings keys\n- get_form_schema: Form field types, action keys, and examples\n- get_interaction_schema: Interaction triggers, actions, animation types\n- get_component_schema: Component property types and slot mechanics\n- get_popup_schema: Popup display settings and trigger patterns\n- get_filter_schema: Query filter element types and setup workflow\n- get_condition_schema: Element visibility condition types and operators\n- get_global_queries: List reusable global query definitions\n- set_global_query: Create/update global query (requires: name, settings)\n- delete_global_query: Delete global query (requires: query_id)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array(
						'type'        => 'string',
						'enum'        => array( 'enable', 'disable', 'get_settings', 'get_breakpoints', 'get_element_schemas', 'get_dynamic_tags', 'get_query_types', 'get_form_schema', 'get_interaction_schema', 'get_component_schema', 'get_popup_schema', 'get_filter_schema', 'get_condition_schema', 'get_global_queries', 'set_global_query', 'delete_global_query' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (enable, disable: required)', 'bricks-mcp' ),
					),
					'category'     => array(
						'type'        => 'string',
						'enum'        => array( 'general', 'performance', 'builder', 'templates', 'integrations', 'woocommerce' ),
						'description' => __( 'Filter settings by category (get_settings: optional) or group global query by category (set_global_query: optional)', 'bricks-mcp' ),
					),
					'element'      => array(
						'type'        => 'string',
						'description' => __( "Specific element type name (get_element_schemas: optional, e.g. 'heading')", 'bricks-mcp' ),
					),
					'catalog_only' => array(
						'type'        => 'boolean',
						'description' => __( 'Return only element names, labels, and categories without full schemas (get_element_schemas: optional)', 'bricks-mcp' ),
					),
					'group'        => array(
						'type'        => 'string',
						'description' => __( 'Filter dynamic tags by group name (get_dynamic_tags: optional, e.g. "Post", "Terms", "User")', 'bricks-mcp' ),
					),
					'query_id'     => array(
						'type'        => 'string',
						'description' => __( 'Global query ID (set_global_query: optional for update; delete_global_query: required)', 'bricks-mcp' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'Global query name (set_global_query: required)', 'bricks-mcp' ),
					),
					'settings'     => array(
						'type'        => 'object',
						'description' => __( 'Query settings object — same structure as element query settings (set_global_query: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_bricks' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Page consolidated tool (replaces list_pages, search_pages, get_bricks_content, create_bricks_page, update_bricks_content, update_page, delete_page, duplicate_page, get_page_settings, update_page_settings + SEO).
		$this->register_tool(
			'page',
			__( "Read and write Bricks pages, posts, and their element content. Use this for every page-level operation.\n\nWHEN: Use page:get view=visual FIRST before editing any existing page to see the full layout tree with element IDs. Use page:list to discover pages. Use page:update_content to replace all elements.\n\nActions:\n- list: List pages (optional: post_type, status, posts_per_page, bricks_only=true)\n- search: Search Bricks pages (requires: search)\n- get: Get page with elements (requires: post_id; optional: view=visual|summary|detail)\n- create: Create page (requires: title; optional: post_type, status, elements)\n- update_content: Replace all Bricks elements (requires: post_id, elements) — destructive\n- update_meta: Update title/slug/status (requires: post_id)\n- delete: Trash page (requires: post_id) — destructive\n- duplicate: Clone page (requires: post_id)\n- get_settings: Get page-level Bricks settings (requires: post_id)\n- update_settings: Update page-level settings (requires: post_id, settings)\n- get_seo: Get SEO data with audit (requires: post_id)\n- update_seo: Write SEO fields (requires: post_id; optional: title, description, og_title, og_image, focus_keyword)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'              => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'search', 'get', 'create', 'update_content', 'update_meta', 'delete', 'duplicate', 'get_settings', 'update_settings', 'get_seo', 'update_seo' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'             => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (get, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo: required)', 'bricks-mcp' ),
					),
					'post_type'           => array(
						'type'        => 'string',
						'description' => __( 'Post type (list, search, create: optional; default page)', 'bricks-mcp' ),
					),
					'status'              => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status (list: filter; create/update_meta: new status)', 'bricks-mcp' ),
					),
					'posts_per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (list, search: max 100)', 'bricks-mcp' ),
					),
					'paged'               => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list, search)', 'bricks-mcp' ),
					),
					'bricks_only'         => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to only Bricks-enabled pages (list: default true)', 'bricks-mcp' ),
					),
					'search'              => array(
						'type'        => 'string',
						'description' => __( 'Search query string (search: required)', 'bricks-mcp' ),
					),
					'view'                => array(
						'type'        => 'string',
						'enum'        => array( 'detail', 'summary', 'visual' ),
						'description' => __( 'Detail level for page:get. visual = human-readable nested tree with element IDs, labels, text previews, bg colors — USE THIS before editing existing pages. summary = element type counts + tree structure. detail = full raw settings (large).', 'bricks-mcp' ),
					),
					'title'               => array(
						'type'        => 'string',
						'description' => __( 'Page/post title (create: required; update_meta: optional; update_seo: SEO title)', 'bricks-mcp' ),
					),
					'elements'            => array(
						'type'        => 'array',
						'description' => __( 'Element content array (create: optional, update_content: required)', 'bricks-mcp' ),
					),
					'slug'                => array(
						'type'        => 'string',
						'description' => __( 'URL slug (update_meta: optional)', 'bricks-mcp' ),
					),
					'settings'            => array(
						'type'        => 'object',
						'description' => __( 'Settings key-value pairs (update_settings: required)', 'bricks-mcp' ),
					),
					'description'         => array(
						'type'        => 'string',
						'description' => __( 'SEO meta description (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_noindex'      => array(
						'type'        => 'boolean',
						'description' => __( 'Set noindex robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'robots_nofollow'     => array(
						'type'        => 'boolean',
						'description' => __( 'Set nofollow robots directive (update_seo: optional)', 'bricks-mcp' ),
					),
					'canonical'           => array(
						'type'        => 'string',
						'description' => __( 'Canonical URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_title'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph title (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_description'      => array(
						'type'        => 'string',
						'description' => __( 'Open Graph description (update_seo: optional)', 'bricks-mcp' ),
					),
					'og_image'            => array(
						'type'        => 'string',
						'description' => __( 'Open Graph image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_title'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card title (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_description' => array(
						'type'        => 'string',
						'description' => __( 'Twitter card description (update_seo: optional)', 'bricks-mcp' ),
					),
					'twitter_image'       => array(
						'type'        => 'string',
						'description' => __( 'Twitter card image URL (update_seo: optional)', 'bricks-mcp' ),
					),
					'focus_keyword'       => array(
						'type'        => 'string',
						'description' => __( 'Focus keyword for SEO analysis (update_seo: optional; Yoast/Rank Math only)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_page' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// page_diagnose: scan existing pages for known issues before editing.
		$this->register_tool(
			'page_diagnose',
			__( 'Pre-edit health check for any Bricks page or template. Returns issues with severity, element ID, and actionable fix. Call this before editing existing pages to avoid building on a broken foundation. Read-only — never modifies data. Checks 10 common issues: missing script tags, %root% in CSS, wrong keys (_maxWidth), plain hex colors, invalid breakpoints, orphaned children, phantom content on headers/footers, missing global classes, integer border values, PHP without executeCode.', 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Post/page/template ID to diagnose (required)', 'bricks-mcp' ),
					),
				),
				'required' => array( 'post_id' ),
			),
			array( $this, 'tool_page_diagnose' ),
			array(
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			)
		);

		$this->register_tool(
			'page_spacing_audit',
			__( 'Audit padding/margin rhythm across all sections and containers on a page. Returns per-element spacing values, flags missing mobile overrides, and calculates vertical rhythm consistency score. Read-only.', 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID to audit (required)', 'bricks-mcp' ),
					),
				),
				'required' => array( 'post_id' ),
			),
			array( $this, 'tool_page_spacing_audit' ),
			array(
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			)
		);

		$this->register_tool( 'page_screenshot', __( 'Screenshots a published page via Microlink API (free, 50/day, no key needed) with ApiFlash fallback (free key in MCP Settings). Returns base64 image and CDN URL. Use before page_visual_review or to visually verify changes. Read-only — calls external API.', 'bricks-mcp' ), array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => 'Post/page ID to screenshot (required)' ), 'viewport' => array( 'type' => 'string', 'enum' => array( 'desktop', 'tablet', 'mobile' ), 'description' => 'Viewport size (default: desktop)' ), 'fresh' => array( 'type' => 'boolean', 'description' => 'Bypass CDN cache for a fresh capture (default: false)' ) ), 'required' => array( 'post_id' ) ), array( $this, 'tool_page_screenshot' ), array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ) );

		$this->register_tool( 'page_visual_review', __( 'Screenshots a page and sends the image to Claude Vision (Anthropic API) for structured visual review. Returns a natural language critique, issues list with severity, and positive observations. Requires Anthropic API key in MCP Settings. Depends on page_screenshot working correctly.', 'bricks-mcp' ), array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => 'Post/page ID to review (required)' ), 'viewport' => array( 'type' => 'string', 'enum' => array( 'desktop', 'tablet', 'mobile' ), 'description' => 'Viewport size (default: desktop)' ), 'focus' => array( 'type' => 'string', 'enum' => array( 'spacing', 'alignment', 'colors', 'all' ), 'description' => 'Review focus area (default: all)' ) ), 'required' => array( 'post_id' ) ), array( $this, 'tool_page_visual_review' ), array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ) );

		// Element consolidated tool (replaces add_element, update_element, remove_element).
		$this->register_tool(
			'element',
			__( "Add, update, move, or remove individual elements on a Bricks page. Use page:get view=visual first to get element IDs before calling this.\n\nWHEN: Use element:add to insert new elements. Use element:update to change settings on a specific element. Use element:bulk_update to change multiple elements in one call (more efficient). Use element:remove to delete an element and all its children.\n\nActions:\n- add: Insert element (requires: post_id, name; optional: parent_id=0, position, settings)\n- update: Update element settings — merges with existing (requires: post_id, element_id, settings)\n- remove: Delete element + children (requires: post_id, element_id) — destructive\n- get_conditions: Get visibility conditions (requires: post_id, element_id)\n- set_conditions: Set visibility conditions (requires: post_id, element_id, conditions)\n- move: Reorder element (requires: post_id, element_id; optional: target_parent_id, position)\n- bulk_update: Update many elements at once (requires: post_id, updates array; max 50)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'           => array(
						'type'        => 'string',
						'enum'        => array( 'add', 'update', 'remove', 'get_conditions', 'set_conditions', 'move', 'bulk_update' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'          => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (all actions: required)', 'bricks-mcp' ),
					),
					'element'          => array(
						'type'        => 'object',
						'description' => __( 'Element object with name and optional settings (add: used as source for element data)', 'bricks-mcp' ),
					),
					'name'             => array(
						'type'        => 'string',
						'description' => __( "Bricks element type name (add: required, e.g. 'heading', 'container', 'section')", 'bricks-mcp' ),
					),
					'element_id'       => array(
						'type'        => 'string',
						'description' => __( 'Element ID (update, remove, move: required; 6-char alphanumeric)', 'bricks-mcp' ),
					),
					'settings'         => array(
						'type'        => 'object',
						'description' => __( 'Element settings (add: optional, update: required)', 'bricks-mcp' ),
					),
					'position'         => array(
						'type'        => 'integer',
						'description' => __( "Position in parent's children array (add, move: 0-indexed, omit to append)", 'bricks-mcp' ),
					),
					'parent_id'        => array(
						'type'        => 'string',
						'description' => __( "Parent element ID (add: optional, use '0' for root level)", 'bricks-mcp' ),
					),
					'conditions'       => array(
						'type'        => 'array',
						'description' => __( 'Condition sets array — array of arrays of condition objects with key/compare/value (set_conditions: required)', 'bricks-mcp' ),
					),
					'target_parent_id' => array(
						'type'        => 'string',
						'description' => __( "Target parent element ID for move (move: optional; use '0' for root level, omit to reorder within current parent)", 'bricks-mcp' ),
					),
					'updates'          => array(
						'type'        => 'array',
						'description' => __( 'Array of {element_id, settings} objects (bulk_update: required; max 50 items)', 'bricks-mcp' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'element_id' => array(
									'type'        => 'string',
									'description' => __( 'Element ID to update', 'bricks-mcp' ),
								),
								'settings'   => array(
									'type'        => 'object',
									'description' => __( 'Settings to merge', 'bricks-mcp' ),
								),
							),
						),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_element' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Template consolidated tool (replaces list_templates, get_template_content, create_template, update_template, delete_template, duplicate_template).
		$this->register_tool(
			'template',
			__( "Create, read, update, and delete Bricks templates (headers, footers, popups, sections). Use template_condition to control which pages each template appears on.\n\nWHEN: Use template:create then template_condition:set to deploy a header or footer. Use template:get to inspect element structure. Use template:export/import to move templates between sites.\n\nActions:\n- list: List templates (optional: type, status, tag, bundle)\n- get: Get full element content (requires: template_id)\n- create: Create template (requires: title, type; optional: elements, status)\n- update: Update title/status/type (requires: template_id)\n- delete: Trash template (requires: template_id) — destructive\n- duplicate: Clone template (requires: template_id)\n- get_popup_settings / set_popup_settings: Manage popup display rules (requires: template_id)\n- export: Export as JSON (requires: template_id)\n- import: Import from JSON data (requires: template_data)\n- import_url: Import from remote URL (requires: url)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'duplicate', 'get_popup_settings', 'set_popup_settings', 'export', 'import', 'import_url' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Template post ID (get, update, delete, duplicate, export: required)', 'bricks-mcp' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'Template title (create: required; update, duplicate: optional)', 'bricks-mcp' ),
					),
					'type'        => array(
						'type'        => 'string',
						'enum'        => array( 'header', 'footer', 'archive', 'search', 'error', 'content', 'section', 'popup', 'password_protection' ),
						'description' => __( 'Template type (create: required; list, update: optional)', 'bricks-mcp' ),
					),
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ),
						'description' => __( 'Post status (list: filter; create/update: new status)', 'bricks-mcp' ),
					),
					'elements'    => array(
						'type'        => 'array',
						'description' => __( 'Element content array (create: optional)', 'bricks-mcp' ),
					),
					'tags'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of template_tag taxonomy slugs (list: filter; create, update: assign)', 'bricks-mcp' ),
					),
					'bundles'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of template_bundle taxonomy slugs (list: filter; create, update: assign)', 'bricks-mcp' ),
					),
					'tag'         => array(
						'type'        => 'string',
						'description' => __( 'Filter by template_tag taxonomy slug (list: optional)', 'bricks-mcp' ),
					),
					'bundle'      => array(
						'type'        => 'string',
						'description' => __( 'Filter by template_bundle taxonomy slug (list: optional)', 'bricks-mcp' ),
					),
					'post_type'   => array(
						'type'        => 'string',
						'description' => __( 'Post type for the template (create: optional)', 'bricks-mcp' ),
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => __( 'Array of Bricks condition objects (create: optional)', 'bricks-mcp' ),
					),
					'settings'    => array(
						'type'        => 'object',
						'description' => __( 'Popup settings key-value pairs (set_popup_settings: required). Null value deletes key. Use bricks:get_popup_schema for valid keys.', 'bricks-mcp' ),
					),
					'include_classes' => array(
						'type'        => 'boolean',
						'description' => __( 'Include used global classes in export (export: optional, default false)', 'bricks-mcp' ),
					),
					'template_data' => array(
						'type'        => 'object',
						'description' => __( 'Template JSON data to import (import: required). Must contain title (string) and content (array of Bricks elements). Optional: templateType, pageSettings, templateSettings, globalClasses.', 'bricks-mcp' ),
					),
					'url'         => array(
						'type'        => 'string',
						'description' => __( 'Remote URL to fetch template JSON from (import_url: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template' ),
		array('readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>false,'openWorldHint'=>false)
		);

		// Template condition consolidated tool (replaces get_condition_types, set_template_conditions, resolve_templates).
		$this->register_tool(
			'template_condition',
			__( "Control which pages each Bricks template targets. Higher score conditions win when multiple templates compete. Essential after creating headers, footers, and WooCommerce templates.\n\nWHEN: After creating any template with template:create, call template_condition:set to activate it. Use get_types to see all condition formats. Use resolve to debug which templates are loading on a specific page.\n\nActions:\n- get_types: All condition types with scoring explanation (no params)\n- set: Activate template on pages (requires: template_id, conditions array; empty array deactivates)\n- resolve: Which templates win for a post (optional: post_id, post_type)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'get_types', 'set', 'resolve' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'template_id' => array(
						'type'        => 'integer',
						'description' => __( 'Template post ID (set: required)', 'bricks-mcp' ),
					),
					'conditions'  => array(
						'type'        => 'array',
						'description' => __( 'Array of condition objects with "main" key and type-specific fields. Pass empty array to remove all conditions. (set: required)', 'bricks-mcp' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Post ID to resolve templates for (resolve: optional)', 'bricks-mcp' ),
					),
					'post_type'   => array(
						'type'        => 'string',
						'description' => __( 'Post type context for resolution (resolve: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template_condition' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		)
		);

		// Template taxonomy consolidated tool (replaces list_template_tags, list_template_bundles, create_template_tag, create_template_bundle, delete_template_tag, delete_template_bundle).
		$this->register_tool(
			'template_taxonomy',
			__( "Manage template tags and bundles for organising and filtering Bricks templates. Tags and bundles are taxonomy terms assigned to templates.\n\nWHEN: Create tags/bundles first, then assign to templates via template:create or template:update. Use list_tags to see existing terms before creating duplicates.\n\nActions:\n- list_tags / list_bundles: List all terms\n- create_tag / create_bundle: New term (requires: name)\n- delete_tag / delete_bundle: Remove term (requires: term_id) — unassigned from all templates", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array(
						'type'        => 'string',
						'enum'        => array( 'list_tags', 'list_bundles', 'create_tag', 'create_bundle', 'delete_tag', 'delete_bundle' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'Tag or bundle name (create_tag, create_bundle: required)', 'bricks-mcp' ),
					),
					'term_id' => array(
						'type'        => 'integer',
						'description' => __( 'Term ID to delete (delete_tag, delete_bundle: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_template_taxonomy' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Global class consolidated tool (replaces get_global_classes, create_global_class, update_global_class, delete_global_class, apply_global_class, remove_global_class, batch_create_global_classes, batch_delete_global_classes, import_classes_from_css, list_global_class_categories, create_global_class_category, delete_global_class_category).
		$this->register_tool(
			'global_class',
			__( "Manage reusable Bricks CSS classes that apply styles across multiple elements. Changes here affect every element using the class sitewide.\n\nWHEN: Create classes for repeated styles (buttons, cards, spacing). Use apply/remove to assign classes to elements instead of duplicating inline styles. Use import_css to bulk-create from existing stylesheets.\n\nActions:\n- list: List classes (optional: search, category)\n- create: Create class (requires: name; optional: styles, color, category)\n- update: Merge styles into class (requires: class_name; optional: styles, replace_styles=false)\n- delete: Trash class (requires: class_name) — destructive\n- apply: Add class to elements (requires: post_id, element_ids, class_name)\n- remove: Remove class from elements (requires: post_id, element_ids, class_name)\n- batch_create: Create multiple classes at once (requires: classes array)\n- batch_delete: Delete multiple classes (requires: classes array)\n- import_css: Parse raw CSS into global classes (requires: css)\n- list_categories / create_category / delete_category: Manage class groupings\n- export: Export as JSON (optional: category)\n- import_json: Import from JSON (requires: classes_data)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'         => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete', 'apply', 'remove', 'batch_create', 'batch_delete', 'import_css', 'list_categories', 'create_category', 'delete_category', 'export', 'import_json' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'class_name'     => array(
						'type'        => 'string',
						'description' => __( 'CSS class name (update, delete, apply, remove: required; list filter: optional)', 'bricks-mcp' ),
					),
					'name'           => array(
						'type'        => 'string',
						'description' => __( 'New class name (create: required; update: optional for rename)', 'bricks-mcp' ),
					),
					'styles'         => array(
						'type'        => 'object',
						'description' => __( 'Bricks composite key styles: _padding, _background, _margin:hover, etc. (create, update: optional)', 'bricks-mcp' ),
					),
					'color'          => array(
						'type'        => 'string',
						'description' => __( 'Visual indicator color in Bricks editor, hex format like #3498db (create, update: optional)', 'bricks-mcp' ),
					),
					'category'       => array(
						'type'        => 'string',
						'description' => __( 'Category ID (create, update: assign; list: filter by category)', 'bricks-mcp' ),
					),
					'replace_styles' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, replace entire styles object instead of merging (update: default false)', 'bricks-mcp' ),
					),
					'post_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID containing the elements (apply, remove: required)', 'bricks-mcp' ),
					),
					'element_ids'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Array of element IDs (apply, remove: required)', 'bricks-mcp' ),
					),
					'classes'        => array(
						'type'        => 'array',
						'description' => __( 'Array of class objects for batch_create, or array of class name strings for batch_delete', 'bricks-mcp' ),
					),
					'css'            => array(
						'type'        => 'string',
						'description' => __( 'Raw CSS string to parse and import as global classes (import_css: required)', 'bricks-mcp' ),
					),
					'category_name'  => array(
						'type'        => 'string',
						'description' => __( 'Category name (create_category: required)', 'bricks-mcp' ),
					),
					'category_id'    => array(
						'type'        => 'string',
						'description' => __( 'Category ID to delete (delete_category: required)', 'bricks-mcp' ),
					),
					'search'         => array(
						'type'        => 'string',
						'description' => __( 'Filter classes by partial name match (list: optional)', 'bricks-mcp' ),
					),
					'classes_data'   => array(
						'type'        => 'object',
						'description' => __( 'Global classes JSON data to import (import_json: required). Array of class objects with "name" key, or {classes: array(...), categories: array(...)}.', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_global_class' ),
		array('readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>false,'openWorldHint'=>false)
		);

		// Theme style consolidated tool (replaces list_theme_styles, get_theme_style, create_theme_style, update_theme_style, delete_theme_style).
		$this->register_tool(
			'theme_style',
			__( "Manage site-wide Bricks theme styles — typography, colors, spacing, and button defaults. Styles apply conditionally based on template conditions. Changes affect every page the style targets.\n\nWHEN: Create a style with conditions to apply different typography per page type. Use update(replace_section=true) to completely swap a settings group. Use list to check active styles before creating new ones to avoid conflicts.\n\nActions:\n- list: All theme styles with condition_types reference\n- get: Full style details (requires: style_id)\n- create: New style (requires: name; optional: styles object, conditions)\n- update: Modify style (requires: style_id; optional: name, styles, conditions, active, replace_section)\n- delete: Remove/deactivate style (requires: style_id; optional: hard_delete=false)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'style_id'        => array(
						'type'        => 'string',
						'description' => __( 'Theme style ID (get, update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Style label/name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'styles'          => array(
						'type'        => 'object',
						'description' => __( 'Settings organized by group: typography, links, colors, general, contextualSpacing, css, heading, button, section, etc. (create, update: optional)', 'bricks-mcp' ),
					),
					'conditions'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => __( 'Array of condition objects with "main" key (create, update: optional)', 'bricks-mcp' ),
					),
					'active'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the style should be active (update: optional)', 'bricks-mcp' ),
					),
					'replace_section' => array(
						'type'        => 'boolean',
						'description' => __( 'If true, fully replace each provided settings group instead of merging (update: default false)', 'bricks-mcp' ),
					),
					'hard_delete'     => array(
						'type'        => 'boolean',
						'description' => __( 'If true, permanently delete the style; if false (default), only remove conditions to deactivate (delete: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_theme_style' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Typography scale consolidated tool (replaces get_typography_scales, create_typography_scale, update_typography_scale, delete_typography_scale).
		$this->register_tool(
			'typography_scale',
			__( "Manage Bricks typography scales — CSS custom property steps (--text-sm, --text-md, etc.) that generate utility classes. Use var(--prefix-step) in typography settings to reference scales.\n\nWHEN: Create a scale to establish a consistent type system across the site. Use list to check existing scales before creating. Scales generate both CSS variables and utility classes automatically.\n\nActions:\n- list: All scales with prefix and steps\n- create: New scale (requires: name, prefix=--text-, steps=array({name,value}))\n- update: Modify scale (requires: scale_id)\n- delete: Remove scale (requires: scale_id) — CSS variables removed after Bricks regenerates", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'scale_id'        => array(
						'type'        => 'string',
						'description' => __( 'Scale category ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'Scale name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'settings'        => array(
						'type'        => 'object',
						'description' => __( 'Typography scale settings including prefix, steps, and utility_classes (create: required; update: optional)', 'bricks-mcp' ),
					),
					'prefix'          => array(
						'type'        => 'string',
						'description' => __( 'CSS variable prefix starting with -- (e.g., "--text-"). Used in create if not inside settings.', 'bricks-mcp' ),
					),
					'steps'           => array(
						'type'        => 'array',
						'description' => __( 'Array of scale steps, each with name and value (create: required if not inside settings)', 'bricks-mcp' ),
					),
					'utility_classes' => array(
						'type'        => 'array',
						'description' => __( 'Utility class definitions (create, update: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_typography_scale' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Color palette consolidated tool (replaces list_color_palettes, create_color_palette, update_color_palette, delete_color_palette, add_color_to_palette, update_color_in_palette, delete_color_from_palette).
		$this->register_tool(
			'color_palette',
			__( "Manage Bricks color palettes shown in the color picker. Colors can have CSS variable aliases (raw) and utility classes.\n\nWHEN: Use list to see existing palettes before adding colors. Use add_color with raw=var(--my-color) to link palette colors to CSS variables. Use hex as alias for light field.\n\nActions:\n- list: All palettes with colors\n- create: New palette (requires: name; optional: initial colors array)\n- update: Rename palette (requires: palette_id, name)\n- delete: Remove palette (requires: palette_id) — destructive\n- add_color: Add color (requires: palette_id, name, light/hex; optional: raw=CSS var, parent)\n- update_color: Modify color (requires: palette_id, color_id)\n- delete_color: Remove color (requires: palette_id, color_id)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'     => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create', 'update', 'delete', 'add_color', 'update_color', 'delete_color' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'palette_id' => array(
						'type'        => 'string',
						'description' => __( 'Palette ID (update, delete, add_color, update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'Palette name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'colors'     => array(
						'type'        => 'array',
						'description' => __( 'Initial colors for palette (create: optional)', 'bricks-mcp' ),
					),
					'color_id'   => array(
						'type'        => 'string',
						'description' => __( 'Color ID (update_color, delete_color: required)', 'bricks-mcp' ),
					),
					'color'      => array(
						'type'        => 'object',
						'description' => __( 'Color object with light (hex value), name, raw (CSS variable) fields (add_color: required; update_color: required). "hex" accepted as alias for "light"', 'bricks-mcp' ),
					),
					'position'   => array(
						'type'        => 'integer',
						'description' => __( 'Position in palette (add_color: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_color_palette' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Global variable consolidated tool (replaces list_global_variables, create_variable_category, update_variable_category, delete_variable_category, create_global_variable, update_global_variable, delete_global_variable, batch_create_global_variables).
		$this->register_tool(
			'global_variable',
			__( "Manage Bricks CSS custom properties (--variables) used in theme styles and element settings. Changes propagate sitewide instantly.\n\nWHEN: Use to define design tokens like spacing, font sizes, and brand colors. Use search to find variables before creating duplicates. Use batch_create to import a full design token set at once.\n\nActions:\n- list: List all variables with categories\n- search: Search by name or value (optional: query, value_query, category_id)\n- create: Create variable (requires: name, value; optional: category)\n- update: Update variable (requires: variable_id)\n- delete: Delete variable (requires: variable_id) — destructive\n- batch_create: Create many at once (requires: variables array)\n- batch_delete: Delete many at once (requires: variable_ids; max 50) — destructive\n- create_category / update_category / delete_category: Manage groupings", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'create_category', 'update_category', 'delete_category', 'create', 'update', 'delete', 'batch_create', 'batch_delete', 'search' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category_id'   => array(
						'type'        => 'string',
						'description' => __( 'Category ID (update_category, delete_category: required; create: optional; search: optional filter)', 'bricks-mcp' ),
					),
					'category_name' => array(
						'type'        => 'string',
						'description' => __( 'Category name (create_category: required; update_category: required)', 'bricks-mcp' ),
					),
					'variable_id'   => array(
						'type'        => 'string',
						'description' => __( 'Variable ID (update, delete: required)', 'bricks-mcp' ),
					),
					'name'          => array(
						'type'        => 'string',
						'description' => __( 'Variable name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'value'         => array(
						'type'        => 'string',
						'description' => __( 'CSS value (create: required; update: optional)', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Category ID for variable assignment (create: optional)', 'bricks-mcp' ),
					),
					'variables'     => array(
						'type'        => 'array',
						'description' => __( 'Array of {name, value} variable objects (batch_create: required)', 'bricks-mcp' ),
					),
					'variable_ids'  => array(
						'type'        => 'array',
						'description' => __( 'Array of variable ID strings (batch_delete: required; max 50)', 'bricks-mcp' ),
						'items'       => array( 'type' => 'string' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Name substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
					'value_query'   => array(
						'type'        => 'string',
						'description' => __( 'Value substring to search for (search: optional, case-insensitive)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_global_variable' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Media consolidated tool (replaces search_unsplash, sideload_image, get_media_library, set_featured_image, remove_featured_image, get_image_element_settings).
		$this->register_tool(
			'media',
			__( "Search, upload, and assign images. Connects to Unsplash for free stock photos and WordPress media library.\n\nWHEN: Use search_unsplash then sideload to add stock photos to the media library. Use get_image_settings to get the correct Bricks format for image/background/gallery settings. Use set_featured to assign a post thumbnail.\n\nActions:\n- search_unsplash: Find stock photos (requires: query) — calls external API\n- sideload: Download URL into media library (requires: url; optional: filename, alt_text)\n- list: Browse media library (optional: per_page, mime_type)\n- set_featured: Set post thumbnail (requires: post_id, attachment_id)\n- remove_featured: Remove post thumbnail (requires: post_id)\n- get_image_settings: Get Bricks-format image object (requires: attachment_id, target=image|background|gallery)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'search_unsplash', 'sideload', 'list', 'set_featured', 'remove_featured', 'get_image_settings' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'query'         => array(
						'type'        => 'string',
						'description' => __( 'Search query for Unsplash photos (search_unsplash: required)', 'bricks-mcp' ),
					),
					'url'           => array(
						'type'        => 'string',
						'description' => __( 'Image URL to download (sideload: required)', 'bricks-mcp' ),
					),
					'filename'      => array(
						'type'        => 'string',
						'description' => __( 'Filename for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'alt_text'      => array(
						'type'        => 'string',
						'description' => __( 'Alt text for sideloaded image (sideload: optional)', 'bricks-mcp' ),
					),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (set_featured, remove_featured: required)', 'bricks-mcp' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID from media library (set_featured: required; get_image_settings: optional)', 'bricks-mcp' ),
					),
					'image_size'    => array(
						'type'        => 'string',
						'description' => __( 'WordPress image size (get_image_settings: optional, e.g. full, large, medium)', 'bricks-mcp' ),
					),
					'per_page'      => array(
						'type'        => 'integer',
						'description' => __( 'Results per page (search_unsplash, list: optional)', 'bricks-mcp' ),
					),
					'page'          => array(
						'type'        => 'integer',
						'description' => __( 'Page number for pagination (list: optional)', 'bricks-mcp' ),
					),
					'mime_type'     => array(
						'type'        => 'string',
						'description' => __( "MIME type filter (list: optional, e.g. 'image', 'image/jpeg')", 'bricks-mcp' ),
					),
					'target'        => array(
						'type'        => 'string',
						'enum'        => array( 'image', 'background', 'gallery' ),
						'description' => __( 'Image usage target (get_image_settings: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_media' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		)
		);

		// Menu consolidated tool (replaces create_menu, update_menu, delete_menu, get_menu, list_menus, set_menu_items, assign_menu, unassign_menu, list_menu_locations).
		$this->register_tool(
			'menu',
			__( "Create and manage WordPress navigation menus and assign them to theme locations. Use set_items to replace an entire menu tree in one call.\n\nWHEN: Use list then list_locations to see current assignments. Use set_items with a nested array to define the full menu structure at once. Use assign after creating a menu to make it appear in the header/footer nav element.\n\nActions:\n- list: All menus with item counts\n- get: Full menu with nested item tree (requires: menu_id)\n- create: New menu (requires: name)\n- update: Rename menu (requires: menu_id, name)\n- delete: Permanently delete menu (requires: menu_id) — destructive\n- set_items: Replace all items with new tree (requires: menu_id, items)\n- assign: Assign to theme location (requires: menu_id, location)\n- unassign: Remove from location (requires: location)\n- list_locations: Available theme locations with current assignments", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'set_items', 'assign', 'unassign', 'list_locations' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => __( 'Menu ID (get, update, delete, set_items, assign: required)', 'bricks-mcp' ),
					),
					'name'     => array(
						'type'        => 'string',
						'description' => __( 'Menu name (create: required; update: required)', 'bricks-mcp' ),
					),
					'items'    => array(
						'type'        => 'array',
						'description' => __( 'Array of menu item objects as nested tree (set_items: required)', 'bricks-mcp' ),
					),
					'location' => array(
						'type'        => 'string',
						'description' => __( 'Theme menu location slug (assign: required; unassign: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_menu' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Component consolidated tool (component definition CRUD + instance operations).
		$this->register_tool(
			'component',
			__( "Create and manage reusable Bricks components — element trees with configurable properties and content slots. Changes to a component definition propagate to all instances automatically.\n\nWHEN: Build a card, hero, or testimonial once as a component, then instantiate it on multiple pages. Use fill_slot to inject custom content into component placeholders. Use update_properties to change text/image per instance.\n\nActions:\n- list: List all components (optional: category)\n- get: Get full component definition (requires: component_id)\n- create: Build component from element tree (requires: label, elements)\n- update: Update definition — affects all instances (requires: component_id)\n- delete: Delete definition (requires: component_id) — instances render empty\n- instantiate: Place instance on a page (requires: component_id, post_id)\n- update_properties: Set per-instance property values (requires: post_id, instance_id, properties)\n- fill_slot: Inject content into a slot (requires: post_id, instance_id, slot_id, slot_elements)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'get', 'create', 'update', 'delete', 'instantiate', 'update_properties', 'fill_slot' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'component_id'  => array(
						'type'        => 'string',
						'description' => __( 'Component ID — 6-char alphanumeric (get, update, delete, instantiate: required)', 'bricks-mcp' ),
					),
					'label'         => array(
						'type'        => 'string',
						'description' => __( 'Component display name (create: required; update: optional)', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Category name for grouping (create/update: optional; list: filter)', 'bricks-mcp' ),
					),
					'description'   => array(
						'type'        => 'string',
						'description' => __( 'Component description (create/update: optional)', 'bricks-mcp' ),
					),
					'elements'      => array(
						'type'        => 'array',
						'description' => __( 'Flat element array — same structure as page content (create: required; update: optional). Root element ID will be auto-set to match component ID.', 'bricks-mcp' ),
					),
					'properties'    => array(
						'type'        => 'array',
						'description' => __( 'Property definitions array (create/update: optional) or property values object (instantiate/update_properties: set instance values). Each definition: {id, name, type, default, description, connections}', 'bricks-mcp' ),
					),
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (instantiate, update_properties, fill_slot: required)', 'bricks-mcp' ),
					),
					'parent_id'     => array(
						'type'        => 'string',
						'description' => __( "Parent element ID for instance placement (instantiate: optional, default '0' for root)", 'bricks-mcp' ),
					),
					'position'      => array(
						'type'        => 'integer',
						'description' => __( "Position in parent's children array (instantiate: 0-indexed, omit to append)", 'bricks-mcp' ),
					),
					'instance_id'   => array(
						'type'        => 'string',
						'description' => __( 'Instance element ID — 6-char alphanumeric (update_properties, fill_slot: required)', 'bricks-mcp' ),
					),
					'slot_id'       => array(
						'type'        => 'string',
						'description' => __( 'Slot element ID from the component definition (fill_slot: required)', 'bricks-mcp' ),
					),
					'slot_elements' => array(
						'type'        => 'array',
						'description' => __( 'Flat element array to fill into the slot (fill_slot: required)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_component' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// WooCommerce consolidated tool (status, elements, dynamic tags, template scaffolding).
		$this->register_tool(
			'woocommerce',
			__( "Build WooCommerce pages using Bricks. Requires WooCommerce active. Use scaffold_store to create all 8 required templates in one call.\n\nWHEN: Run woocommerce:status first to confirm WooCommerce is active. Use scaffold_store(skip_existing=true) to generate all templates without overwriting existing ones. Use get_elements to discover WooCommerce-specific Bricks elements. Use get_dynamic_tags for product data tag reference.\n\nActions:\n- status: WooCommerce version, page IDs, and Bricks WooCommerce settings\n- get_elements: WooCommerce-specific Bricks elements (optional: category=product|cart|checkout|account|archive)\n- get_dynamic_tags: Dynamic data tag reference (optional: category=product_price|product_display|cart|order)\n- scaffold_template: Create one pre-populated template (requires: template_type)\n- scaffold_store: Create all 8 WooCommerce templates at once (optional: types, skip_existing=true)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'status', 'get_elements', 'get_dynamic_tags', 'scaffold_template', 'scaffold_store' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Filter category (get_elements: product, cart, checkout, account, archive, utility; get_dynamic_tags: product_price, product_display, product_info, cart, order, post_compatible)', 'bricks-mcp' ),
					),
					'template_type' => array(
						'type'        => 'string',
						'enum'        => array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' ),
						'description' => __( 'WooCommerce template type (scaffold_template: required)', 'bricks-mcp' ),
					),
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'Custom template title (scaffold_template: optional, defaults to human-readable name)', 'bricks-mcp' ),
					),
					'status'        => array(
						'type'        => 'string',
						'enum'        => array( 'publish', 'draft' ),
						'description' => __( 'Template post status (scaffold_template: optional, default publish)', 'bricks-mcp' ),
					),
					'types'         => array(
						'type'        => 'array',
						'description' => __( 'Specific template types to scaffold (scaffold_store: optional, defaults to all 8 types)', 'bricks-mcp' ),
					),
					'skip_existing' => array(
						'type'        => 'boolean',
						'description' => __( 'Skip types that already have a template (scaffold_store: optional, default true)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_woocommerce' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Font management consolidated tool.
		$this->register_tool(
			'font',
			__( "Read and update Bricks font configuration including Google Fonts, Adobe Fonts, and webfont loading strategy.\n\nWHEN: Use get_status to audit current font setup. Use update_settings to disable Google Fonts for performance or set font-display strategy (swap is recommended for CLS). Use get_adobe_fonts to see Adobe Fonts project fonts.\n\nActions:\n- get_status: Font config overview (Google Fonts enabled, webfont loading, custom preload)\n- get_adobe_fonts: List cached Adobe Fonts\n- update_settings: Update font config (optional: disable_google_fonts, webfont_loading=swap|block|optional, custom_fonts_preload)", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'               => array(
						'type'        => 'string',
						'enum'        => array( 'get_status', 'get_adobe_fonts', 'update_settings' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'disable_google_fonts' => array(
						'type'        => 'boolean',
						'description' => __( 'Disable Google Fonts loading (update_settings: optional)', 'bricks-mcp' ),
					),
					'webfont_loading'      => array(
						'type'        => 'string',
						'enum'        => array( 'swap', 'block', 'fallback', 'optional', 'auto', '' ),
						'description' => __( 'Font display strategy (update_settings: optional)', 'bricks-mcp' ),
					),
					'custom_fonts_preload' => array(
						'type'        => 'boolean',
						'description' => __( 'Preload custom fonts for performance (update_settings: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_font' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		// Custom code consolidated tool.
		$this->register_tool(
			'code',
			__( "Read and write page-level custom CSS and JavaScript. Scripts require Dangerous Actions enabled in MCP settings.\n\nWHEN: Use set_page_css for page-specific overrides. Use set_page_scripts to inject GSAP, analytics, or other scripts into head/body. Always get first before setting to avoid overwriting existing code. Use code:get_page_css to check what CSS is already on a page.\n\nActions:\n- get_page_css: Read current custom CSS + scripts (requires: post_id)\n- set_page_css: Write/replace custom CSS (requires: post_id, css; empty string clears)\n- get_page_scripts: Read custom scripts only (requires: post_id)\n- set_page_scripts: Write scripts to head/body (requires: post_id; optional: header, body_header, body_footer) — requires Dangerous Actions enabled", 'bricks-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'      => array(
						'type'        => 'string',
						'enum'        => array( 'get_page_css', 'set_page_css', 'get_page_scripts', 'set_page_scripts' ),
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Post/page ID (all actions: required)', 'bricks-mcp' ),
					),
					'css'         => array(
						'type'        => 'string',
						'description' => __( 'Custom CSS code (set_page_css: required). Empty string removes CSS.', 'bricks-mcp' ),
					),
					'header'      => array(
						'type'        => 'string',
						'description' => __( 'Script for document head (set_page_scripts: optional)', 'bricks-mcp' ),
					),
					'body_header' => array(
						'type'        => 'string',
						'description' => __( 'Script after opening body tag (set_page_scripts: optional)', 'bricks-mcp' ),
					),
					'body_footer' => array(
						'type'        => 'string',
						'description' => __( 'Script before closing body tag (set_page_scripts: optional)', 'bricks-mcp' ),
					),
				),
				'required'   => array( 'action' ),
			),
			array( $this, 'tool_code' ),
		array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		)
		);

		/**
		 * Filter the registered MCP tools.
		 *
		 * Fires after ALL default and Bricks-specific tools have been registered,
		 * so third-party plugins see the complete tool list. (Issue 5d fix)
		 *
		 * @param array $tools Registered tools.
		 */
		$this->tools = apply_filters( 'bricks_mcp_tools', $this->tools );
	}

	/**
	 * Apply minimal response format to strip verbose/redundant fields.
	 *
	 * When response_format=minimal is passed, removes large nested fields
	 * that are rarely needed, reducing token usage by 40-60%.
	 *
	 * @param array<string, mixed> $result Full result array.
	 * @return array<string, mixed> Slimmed result.
	 */
	private function apply_minimal_format( array $result ): array {
		// Strip known verbose fields from common responses.
		$strip_keys = [
			'inputSchema',   // Tool schema definitions — large
			'schemas',       // Element schemas — very large
			'catalog',       // Full element catalog
			'guide',         // Builder guide text
			'content'        // Raw element arrays on list responses
		];

		foreach ( $strip_keys as $key ) {
			if ( isset( $result[ $key ] ) && ( is_array( $result[ $key ] ) || strlen( (string) $result[ $key ] ) > 500 ) ) {
				$result[ $key ] = '[omitted — use response_format=full to include]';
			}
		}

		// For element arrays, return only IDs and names.
		if ( isset( $result['elements'] ) && is_array( $result['elements'] ) ) {
			$result['elements'] = array_map(
				function( $el ) {
					return [
						'id'   => $el['id'] ?? null,
						'name' => $el['name'] ?? null,
					];
				},
				$result['elements']
			);
		}

		// For templates list, strip element content.
		if ( isset( $result['templates'] ) && is_array( $result['templates'] ) ) {
			$result['templates'] = array_map(
				function( $t ) {
					unset( $t['elements'] );
					return $t;
				},
				$result['templates']
			);
		}

		$result['_format'] = 'minimal';
		return $result;
	}

	/**
	 * Apply smart defaults to tool arguments to reduce required parameters.
	 *
	 * Injects sensible defaults so AI clients can call tools with fewer params.
	 *
	 * @param string               $tool_name Tool name.
	 * @param array<string, mixed> $args      Raw arguments.
	 * @return array<string, mixed> Arguments with defaults applied.
	 */
	private function apply_smart_defaults( string $tool_name, array $args ): array {
		switch ( $tool_name ) {
			case 'page':
				// Default action to 'list' when omitted.
				if ( ! isset( $args['action'] ) ) {
					$args['action'] = 'list';
				}
				// Default view to 'visual' for get action (best for editing).
				if ( isset( $args['action'] ) && 'get' === $args['action'] && ! isset( $args['view'] ) ) {
					$args['view'] = 'summary';
				}
				// Default posts_per_page to 20 for list.
				if ( isset( $args['action'] ) && 'list' === $args['action'] && ! isset( $args['posts_per_page'] ) ) {
					$args['posts_per_page'] = 20;
				}
				// Default status to 'any' for list so drafts are included.
				if ( isset( $args['action'] ) && 'list' === $args['action'] && ! isset( $args['status'] ) ) {
					$args['status'] = 'any';
				}
				break;

			case 'bricks':
				// Default action to 'get_settings'.
				if ( ! isset( $args['action'] ) ) {
					$args['action'] = 'get_settings';
				}
				// Default get_element_schemas to catalog_only=true to reduce response size.
				if ( isset( $args['action'] ) && 'get_element_schemas' === $args['action'] && ! isset( $args['catalog_only'] ) && ! isset( $args['element'] ) ) {
					$args['catalog_only'] = true;
				}
				break;

			case 'element':
				// Default parent_id to '0' (root) for add action.
				if ( isset( $args['action'] ) && 'add' === $args['action'] && ! isset( $args['parent_id'] ) ) {
					$args['parent_id'] = '0';
				}
				break;

			case 'template':
				// Default status to 'publish' for create.
				if ( isset( $args['action'] ) && 'create' === $args['action'] && ! isset( $args['status'] ) ) {
					$args['status'] = 'publish';
				}
				// Default list to show all statuses.
				if ( isset( $args['action'] ) && 'list' === $args['action'] && ! isset( $args['status'] ) ) {
					$args['status'] = 'publish';
				}
				break;

			case 'wordpress':
				// Default posts_per_page to 20.
				if ( isset( $args['action'] ) && 'get_posts' === $args['action'] && ! isset( $args['posts_per_page'] ) ) {
					$args['posts_per_page'] = 20;
				}
				break;

			case 'global_class':
				// Default replace_styles to false (merge, not replace).
				if ( isset( $args['action'] ) && 'update' === $args['action'] && ! isset( $args['replace_styles'] ) ) {
					$args['replace_styles'] = false;
				}
				break;
		}

		return $args;
	}

	/**
	 * Enrich a WP_Error with structured next-step guidance.
	 *
	 * Wraps the raw error in a structured array so AI clients get
	 * actionable guidance alongside the error message.
	 *
	 * @param \WP_Error $error The original error.
	 * @param string    $hint  What the AI should do next.
	 * @param string    $tool  Suggested tool to call next (optional).
	 * @return \WP_Error The same error with enriched message.
	 */
	private function enrich_error( \WP_Error $error, string $hint = '', string $tool = '' ): \WP_Error {
		$data = array(
			'error'      => $error->get_error_code(),
			'message'    => $error->get_error_message(),
		);
		if ( ! empty( $hint ) ) {
			$data['next_step'] = $hint;
		}
		if ( ! empty( $tool ) ) {
			$data['suggested_tool'] = $tool;
		}
		$error->add_data( $data );
		return $error;
	}

	/**
	 * Require Bricks Builder to be active for a tool.
	 *
	 * Returns a WP_Error if Bricks is not active, null if it is active.
	 *
	 * @return \WP_Error|null WP_Error if Bricks required but not active, null if active.
	 */
	private function require_bricks(): ?\WP_Error {
		if ( ! $this->bricks_service->is_bricks_active() ) {
			return new \WP_Error(
				'bricks_required',
				__( 'Bricks Builder must be installed and active. Install and activate it, then retry.', 'bricks-mcp' ),
				[ 'next_step' => 'Install Bricks Builder from Plugins > Add New, activate it, then retry this tool.', 'suggested_tool' => 'get_site_info' ]
			);
		}
		return null;
	}

	/**
	 * Tool: WordPress dispatcher — routes to get_posts, get_post, get_users, get_plugins.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_wordpress( array $args ): array|\WP_Error {
		$action = $args['action'] ?? '';

		$action_caps = array(
			'get_posts'   => 'read',
			'get_post'    => 'read',
			'get_users'   => 'list_users',
			'get_plugins' => 'activate_plugins',
		);

		$required_cap = $action_caps[ $action ] ?? null;
		if ( null !== $required_cap && ! current_user_can( $required_cap ) ) {
			return new \WP_Error(
				'bricks_mcp_forbidden',
				sprintf(
					/* translators: %s: Required capability */
					__( 'You do not have the required capability (%s) to perform this action.', 'bricks-mcp' ),
					$required_cap
				)
			);
		}

		return match ( $action ) {
			'get_posts'   => $this->tool_get_posts( $args ),
			'get_post'    => $this->tool_get_post( $args ),
			'get_users'   => $this->tool_get_users( $args ),
			'get_plugins' => $this->tool_get_plugins( $args ),
			default       => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_posts, get_post, get_users, get_plugins', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Bricks dispatcher — routes to enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_global_queries, set_global_query, delete_global_query.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_bricks( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'enable'                  => $this->tool_enable_bricks( $args ),
			'disable'                 => $this->tool_disable_bricks( $args ),
			'get_settings'            => $this->tool_get_bricks_settings( $args ),
			'get_breakpoints'         => $this->tool_get_breakpoints( $args ),
			'get_element_schemas'     => $this->tool_get_element_schemas( $args ),
			'get_dynamic_tags'        => $this->tool_get_dynamic_tags( $args ),
			'get_query_types'         => $this->tool_get_query_types( $args ),
			'get_form_schema'         => $this->tool_get_form_schema( $args ),
			'get_interaction_schema'  => $this->tool_get_interaction_schema( $args ),
			'get_component_schema'    => $this->tool_get_component_schema( $args ),
			'get_popup_schema'        => $this->tool_get_popup_schema( $args ),
			'get_filter_schema'       => $this->tool_get_filter_schema( $args ),
			'get_condition_schema'    => $this->tool_get_condition_schema( $args ),
			'get_global_queries'      => $this->tool_get_global_queries( $args ),
			'set_global_query'        => $this->tool_set_global_query( $args ),
			'delete_global_query'     => $this->tool_delete_global_query( $args ),
			default                   => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: enable, disable, get_settings, get_breakpoints, get_element_schemas, get_dynamic_tags, get_query_types, get_form_schema, get_interaction_schema, get_component_schema, get_popup_schema, get_filter_schema, get_condition_schema, get_global_queries, set_global_query, delete_global_query', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get element schemas.
	 *
	 * Returns Bricks element type schemas with settings definitions and working examples.
	 * Supports full catalog, single element, or catalog-only (names/categories only).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Schema data or error.
	 */
	private function tool_get_element_schemas( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$catalog_only = isset( $args['catalog_only'] ) && true === $args['catalog_only'];
		$element_name = $args['element'] ?? '';

		if ( $catalog_only ) {
			$catalog = $this->schema_generator->get_element_catalog();
			return array(
				'total_elements' => count( $catalog ),
				'bricks_version' => $this->schema_generator->get_bricks_version(),
				'catalog'        => $catalog,
			);
		}

		if ( ! empty( $element_name ) ) {
			$schema = $this->schema_generator->get_element_schema( $element_name );
			if ( is_wp_error( $schema ) ) {
				return $schema;
			}
			return array(
				'total_elements' => 1,
				'bricks_version' => $this->schema_generator->get_bricks_version(),
				'cached'         => false,
				'schema'         => $schema,
			);
		}

		// Full catalog with schemas.
		$all_schemas = $this->schema_generator->get_all_schemas();
		return array(
			'total_elements' => count( $all_schemas ),
			'bricks_version' => $this->schema_generator->get_bricks_version(),
			'cached'         => false,
			'schemas'        => $all_schemas,
		);
	}

	/**
	 * Tool: Get dynamic data tags.
	 *
	 * Enumerates all available dynamic data tags via the bricks/dynamic_tags_list filter,
	 * including tags from third-party plugins (ACF, MetaBox, JetEngine, etc.).
	 * Results are grouped by tag group and can be filtered by group name.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'group' to filter by group name.
	 * @return array<string, mixed>|\WP_Error Grouped tag data or error.
	 */
	private function tool_get_dynamic_tags( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Bricks core filter uses slash separator.
		$all_tags = apply_filters( 'bricks/dynamic_tags_list', array() );

		// Group tags by their group key.
		$grouped     = array();
		$total_count = 0;

		foreach ( $all_tags as $tag ) {
			$name = $tag['name'] ?? '';

			// Security: strip any tags related to query editor PHP execution.
			if ( stripos( $name, 'queryEditor' ) !== false || stripos( $name, 'useQueryEditor' ) !== false ) {
				continue;
			}

			$group = $tag['group'] ?? 'Other';
			$label = $tag['label'] ?? $name;

			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}

			$grouped[ $group ][] = array(
				'name'  => $name,
				'label' => $label,
			);

			++$total_count;
		}

		// Filter by group if requested.
		$filter_group = $args['group'] ?? '';
		if ( '' !== $filter_group ) {
			$filtered = array();
			foreach ( $grouped as $group_name => $tags ) {
				if ( strcasecmp( $group_name, $filter_group ) === 0 ) {
					$filtered[ $group_name ] = $tags;
				}
			}
			$grouped     = $filtered;
			$total_count = 0;
			foreach ( $grouped as $tags ) {
				$total_count += count( $tags );
			}
		}

		// Sort groups alphabetically.
		ksort( $grouped );

		return array(
			'total_tags' => $total_count,
			'groups'     => $grouped,
			'usage_hint' => 'Embed tags directly in element settings values. Text fields use bare tag string e.g. "{post_title}". Image fields use {"useDynamicData": "{featured_image}"}. Link fields use {"type": "dynamic", "dynamicData": "{post_url}"}.',
		);
	}

	/**
	 * Tool: Get query loop types.
	 *
	 * Returns a static reference of the three query object types (post, term, user)
	 * and their available settings keys for configuring query loops on Bricks elements.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Query type reference or error.
	 */
	private function tool_get_query_types( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return array(
			'query_types'   => array(
				array(
					'objectType'  => 'post',
					'label'       => 'Posts (WP_Query)',
					'description' => 'Query WordPress posts, pages, and custom post types',
					'settings'    => array(
						'postType'           => array(
							'type'        => 'array',
							'description' => 'Post type slugs, e.g. ["post"], ["portfolio"]',
						),
						'orderby'            => array(
							'type'        => 'string',
							'description' => 'Sort by: date, title, ID, modified, comment_count, rand, menu_order',
						),
						'order'              => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'postsPerPage'       => array(
							'type'        => 'integer',
							'description' => 'Posts per page (-1 for all)',
						),
						'offset'             => array(
							'type'        => 'integer',
							'description' => 'Number of posts to skip',
						),
						'is_main_query'      => array(
							'type'        => 'boolean',
							'description' => 'REQUIRED true for archive templates to prevent 404 on pagination',
						),
						'ignoreStickyPosts'  => array(
							'type'        => 'boolean',
							'description' => 'Ignore sticky posts',
						),
						'excludeCurrentPost' => array(
							'type'        => 'boolean',
							'description' => 'Exclude current post from results',
						),
						'taxonomyQuery'      => array(
							'type'        => 'array',
							'description' => 'Taxonomy filter objects',
						),
						'metaQuery'          => array(
							'type'        => 'array',
							'description' => 'Custom field filter objects',
						),
					),
				),
				array(
					'objectType'  => 'term',
					'label'       => 'Terms (WP_Term_Query)',
					'description' => 'Query taxonomy terms (categories, tags, custom taxonomies)',
					'settings'    => array(
						'taxonomies' => array(
							'type'        => 'array',
							'description' => 'Taxonomy slugs, e.g. ["category"]',
						),
						'orderby'    => array(
							'type'        => 'string',
							'description' => 'Sort by: name, count, term_id, parent',
						),
						'order'      => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'number'     => array(
							'type'        => 'integer',
							'description' => 'Terms per page',
						),
						'offset'     => array(
							'type'        => 'integer',
							'description' => 'Number of terms to skip',
						),
						'hideEmpty'  => array(
							'type'        => 'boolean',
							'description' => 'Hide terms with no posts',
						),
					),
				),
				array(
					'objectType'  => 'user',
					'label'       => 'Users (WP_User_Query)',
					'description' => 'Query WordPress users by role',
					'settings'    => array(
						'roles'   => array(
							'type'        => 'array',
							'description' => 'Role slugs, e.g. ["author"]',
						),
						'orderby' => array(
							'type'        => 'string',
							'description' => 'Sort by: display_name, registered, post_count, user_login',
						),
						'order'   => array(
							'type'        => 'string',
							'description' => 'ASC or DESC',
						),
						'number'  => array(
							'type'        => 'integer',
							'description' => 'Users per page',
						),
						'offset'  => array(
							'type'        => 'integer',
							'description' => 'Number of users to skip',
						),
					),
				),
				array(
					'objectType'  => 'api',
					'label'       => 'REST API (Query_API)',
					'description' => 'Fetch data from any external REST API endpoint. Available since Bricks 2.1.',
					'settings'    => array(
						'api_url'            => array(
							'type'        => 'string',
							'description' => 'Full API endpoint URL. Supports dynamic tags.',
							'required'    => true,
						),
						'api_method'         => array(
							'type'        => 'string',
							'description' => 'HTTP method: GET (default), POST, PUT, PATCH, DELETE',
						),
						'response_path'      => array(
							'type'        => 'string',
							'description' => 'Dot-notation path to extract array from JSON response, e.g. "data.items". Required for most APIs.',
						),
						'api_auth_type'      => array(
							'type'        => 'string',
							'description' => 'Auth type: none (default), apiKey, bearer, basic',
						),
						'api_params'         => array(
							'type'        => 'array',
							'description' => 'Query parameter repeater: [{key: string, value: string}]',
						),
						'api_headers'        => array(
							'type'        => 'array',
							'description' => 'Request headers repeater: [{key: string, value: string}]',
						),
						'cache_time'         => array(
							'type'        => 'integer',
							'description' => 'Cache duration in seconds (default 300 = 5 min; 0 = disable cache)',
						),
						'pagination_enabled' => array(
							'type'        => 'boolean',
							'description' => 'Enable AJAX pagination for API results',
						),
					),
				),
				array(
					'objectType'  => 'array',
					'label'       => 'Array (static data)',
					'description' => 'Loop over a static PHP/JSON array. Available since Bricks 2.2. WARNING: May require code execution permission in Bricks settings.',
					'settings'    => array(
						'arrayEditor' => array(
							'type'        => 'string',
							'description' => 'PHP code returning an array, or JSON array literal. Requires code execution enabled in Bricks settings.',
						),
					),
				),
			),
			'pagination_options'  => array(
				'infinite_scroll'        => array(
					'type'        => 'boolean',
					'description' => 'Enable infinite scroll (auto-loads next page on scroll). Set in query.infinite_scroll.',
					'key'         => 'query.infinite_scroll',
				),
				'infinite_scroll_margin' => array(
					'type'        => 'string',
					'description' => 'Trigger distance from bottom: "200px", "10%". Default: 0px',
					'key'         => 'query.infinite_scroll_margin',
				),
				'infinite_scroll_delay'  => array(
					'type'        => 'integer',
					'description' => 'Delay in ms before loading next page (since Bricks 1.12)',
					'key'         => 'query.infinite_scroll_delay',
				),
				'ajax_loader_animation'  => array(
					'type'        => 'string',
					'description' => 'AJAX loader animation type while loading',
					'key'         => 'query.ajax_loader_animation',
				),
				'load_more_button'       => array(
					'description' => 'Use interaction action=loadMore on a button element for manual load more. Set loadMoreQuery to the query element ID in _interactions.',
					'pattern'     => '{"trigger":"click","action":"loadMore","loadMoreQuery":"<element_id>"}',
				),
				'note'                   => 'Infinite scroll and load more button are mutually exclusive. Choose one per query loop.',
			),
			'global_query_hint'   => 'Set query.id to a global query ID instead of inline settings. Bricks resolves the global query at render time. Use bricks:get_global_queries to list available global queries.',
			'setup_hint'          => 'To create a query loop, set hasLoop: true and a query object on any layout element (container, div, block). For archive templates, ALWAYS set is_main_query: true in the query object.',
			'security_note'       => 'Never set useQueryEditor or queryEditor — these enable PHP execution and are a security risk.',
		);
	}

	/**
	 * Tool: Get filter schema reference.
	 *
	 * Returns Bricks filter element types, required settings, common settings,
	 * and setup workflow for AJAX-powered query filtering.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Filter schema or error.
	 */
	private function tool_get_filter_schema( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$filters_enabled = class_exists( '\Bricks\Helpers' ) ? \Bricks\Helpers::enabled_query_filters() : false;

		return array(
			'filters_enabled'     => $filters_enabled,
			'enable_filters_hint' => 'Query filters must be enabled in Bricks > Settings > Performance > "Enable query sort / filter / live search". Without this, filter elements render as empty.',
			'filter_elements'     => array(
				'filter-checkbox'       => array(
					'label'           => 'Filter - Checkbox',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-radio'          => array(
					'label'           => 'Filter - Radio',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-select'         => array(
					'label'           => 'Filter - Select',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'choices_js' => array(
							'description'     => 'Enhanced select powered by Choices.js library (@since 2.3). Adds search, multiple selection, and advanced styling.',
							'choicesJs'       => 'Enable enhanced select (checkbox). Requires filterAction=filter or empty.',
							'choicesPosition' => 'Dropdown position: auto (default) | bottom | top.',
							'search'          => array(
								'choicesSearch'            => 'Enable search within dropdown (checkbox).',
								'choicesSearchPlaceholder' => 'Search input placeholder text. Default: "Search".',
								'choicesNoResultsText'     => 'Text shown when search returns no results. Default: "No results found".',
								'choicesNoChoicesText'     => 'Text shown when no choices exist or all are selected.',
								'styling_note'             => 'Styling: choicesSearchBackground, choicesSearchTypography, choicesSearchInputTypography, choicesSearchInputPadding.',
							),
							'multiple'        => array(
								'enableMultiple'   => 'Enable multiple option selection (checkbox). Requires choicesJs + filterAction=filter.',
								'filterMultiLogic' => 'Logic for combining multiple values (now requires choicesJs + enableMultiple in 2.3).',
								'styling_note'     => 'Pill styling: choicesPillGap, choicesPillBackground, choicesPillBorder, choicesPillTypography.',
							),
							'styling_note'    => 'General: choicesPadding, choicesBackgroundColor, choicesBorderBase, choicesBorderColor, choicesBorderRadius, choicesFontSize, choicesTextColor, choicesArrowColor. Item: choicesItemPadding, choicesDropdownBackground, choicesHighlightBackground, choicesHighlightTextColor, choicesDisabledBackground, choicesDisabledTextColor.',
						),
					),
				),
				'filter-search'         => array(
					'label'           => 'Filter - Search (Live Search)',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
				),
				'filter-range'          => array(
					'label'           => 'Filter - Range (slider)',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'decimalPlaces'         => 'Number of decimal places to display (number, default: 0). Applies to both slider and input modes.',
						'inputUseCustomStepper' => 'Show custom +/- stepper buttons (checkbox, requires displayMode=input). Renders increment/decrement buttons next to min and max inputs.',
						'stepper_styling_note'  => 'Stepper styling controls (require inputUseCustomStepper): inputStepperGap, inputStepperMarginStart, inputStepperBackground, inputStepperBorder, inputStepperTypography.',
					),
				),
				'filter-datepicker'     => array(
					'label'           => 'Filter - Datepicker',
					'supports_source' => array( 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'dateFormat' => 'Custom display format for the datepicker using flatpickr tokens (e.g. "d/m/Y", "M j, Y"). Default: "Y-m-d". Only affects the visual display — database storage format is determined separately. @since 2.3.',
					),
				),
				'filter-submit'         => array(
					'label'           => 'Filter - Submit button',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Use when filterApplyOn=click on other filters. Triggers filter application.',
				),
				'filter-active-filters' => array(
					'label'           => 'Filter - Active Filters display',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Shows currently active filters as removable tags.',
				),
			),
			'common_settings'     => array(
				'filterQueryId'  => 'Element ID of the target query loop element (the container/posts element with hasLoop: true)',
				'filterSource'   => 'taxonomy | wpField | customField — what data type to filter by',
				'filterAction'   => 'filter (default) | sort | per_page — what the filter does',
				'filterApplyOn'  => 'change (default, instant) | click (requires filter-submit element)',
				'filterNiceName' => 'URL parameter name (optional, e.g. "_color"). Use unique prefix to avoid conflicts.',
				'filterTaxonomy' => 'Taxonomy slug when filterSource=taxonomy, e.g. "category"',
				'wpPostField'    => 'WordPress post field when filterSource=wpField: post_id | post_date | post_author | post_type | post_status | post_modified',
			),
			'filterQueryId_note'  => 'filterQueryId must be the 6-character Bricks element ID of the query loop container, NOT a post ID. Get it from the element array (element["id"]).',
			'workflow_example'    => array(
				'1. Create posts query loop'     => 'Add container element with hasLoop:true and query.objectType:post, query.post_type:["post"]',
				'2. Note the element ID'         => 'The container element ID (e.g. "abc123") is the filterQueryId for all filters on this page',
				'3. Add filter elements'         => 'Add filter-checkbox, set filterQueryId="abc123", filterSource="taxonomy", filterTaxonomy="category"',
				'4. Enable in Bricks settings'   => 'Bricks > Settings > Performance > Enable query sort / filter / live search',
				'5. Rebuild index'               => 'Bricks automatically indexes on post save. May need manual reindex after enabling.',
			),
		);
	}

	/**
	 * Tool: Get element condition schema.
	 *
	 * Returns the complete element condition reference — groups, keys, compare operators,
	 * value types, and usage examples. Hardcoded because Bricks' Conditions::$options is
	 * only populated in builder context (bricks_is_builder() check in conditions.php).
	 *
	 * Element conditions (_conditions in element settings) are distinct from template
	 * conditions (template_condition tool). Template conditions use "main" key and control
	 * page targeting. Element conditions use "key/compare/value" and control per-element
	 * render visibility.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Condition schema or error.
	 */
	private function tool_get_condition_schema( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return array(
			'description'    => 'Element visibility conditions — show/hide elements based on runtime context. Stored in element settings[\'_conditions\']. Distinct from template conditions (which control page targeting).',
			'data_structure' => array(
				'description' => '_conditions is an array of condition SETS. Outer array = OR logic (any set passing renders element). Inner arrays = AND logic (all conditions in a set must pass).',
				'format'      => '[[{key, compare, value}, {key, compare, value}], [{key, compare, value}]]',
				'example'     => 'Show if (logged in AND admin) OR (post author): [[{"key":"user_logged_in","compare":"==","value":"1"},{"key":"user_role","compare":"==","value":["administrator"]}],[{"key":"post_author","compare":"==","value":"1"}]]',
			),
			'groups'         => array(
				array(
					'name'  => 'post',
					'label' => 'Post',
					'keys'  => array(
						array(
							'key'         => 'post_id',
							'label'       => 'Post ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current post ID',
						),
						array(
							'key'         => 'post_title',
							'label'       => 'Post title',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current post title',
						),
						array(
							'key'         => 'post_parent',
							'label'       => 'Post parent',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric, default 0)',
							'description' => 'Parent post ID',
						),
						array(
							'key'         => 'post_status',
							'label'       => 'Post status',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (status slugs, e.g. ["publish","draft"])',
							'description' => 'Post status',
						),
						array(
							'key'         => 'post_author',
							'label'       => 'Post author',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (user ID)',
							'description' => 'Post author user ID',
						),
						array(
							'key'         => 'post_date',
							'label'       => 'Post date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Post publish date',
						),
						array(
							'key'         => 'featured_image',
							'label'       => 'Featured image',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = set, "0" = not set)',
							'description' => 'Whether post has featured image',
						),
					),
				),
				array(
					'name'  => 'user',
					'label' => 'User',
					'keys'  => array(
						array(
							'key'         => 'user_logged_in',
							'label'       => 'User login',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string ("1" = logged in, "0" = logged out)',
							'description' => 'Login status',
						),
						array(
							'key'         => 'user_id',
							'label'       => 'User ID',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (numeric)',
							'description' => 'Current user ID',
						),
						array(
							'key'         => 'user_registered',
							'label'       => 'User registered',
							'compare'     => array( '<', '>' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Registration date (< = after date, > = before date)',
						),
						array(
							'key'         => 'user_role',
							'label'       => 'User role',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'array (role slugs, e.g. ["administrator","editor"])',
							'description' => 'User role(s)',
						),
					),
				),
				array(
					'name'  => 'date',
					'label' => 'Date & time',
					'keys'  => array(
						array(
							'key'         => 'weekday',
							'label'       => 'Weekday',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (1-7, Monday=1 through Sunday=7)',
							'description' => 'Day of week',
						),
						array(
							'key'         => 'date',
							'label'       => 'Date',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d)',
							'description' => 'Current date (uses WP timezone)',
						),
						array(
							'key'         => 'time',
							'label'       => 'Time',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (H:i, e.g. "09:00")',
							'description' => 'Current time (uses WP timezone)',
						),
						array(
							'key'         => 'datetime',
							'label'       => 'Datetime',
							'compare'     => array( '==', '!=', '>=', '<=', '>', '<' ),
							'value_type'  => 'string (Y-m-d h:i a)',
							'description' => 'Date and time combined (uses WP timezone)',
						),
					),
				),
				array(
					'name'  => 'other',
					'label' => 'Other',
					'keys'  => array(
						array(
							'key'          => 'dynamic_data',
							'label'        => 'Dynamic data',
							'compare'      => array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' ),
							'value_type'   => 'string (comparison value; can contain dynamic data tags)',
							'description'  => 'Compare any dynamic data tag output. IMPORTANT: Set the dynamic data tag in the "dynamic_data" field (e.g. "{acf_my_field}"), and the comparison target in "value".',
							'extra_fields' => array( 'dynamic_data' => 'string — the dynamic data tag to evaluate (e.g. "{acf_my_field}", "{post_author_id}")' ),
						),
						array(
							'key'         => 'browser',
							'label'       => 'Browser',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (chrome, firefox, safari, edge, opera, msie)',
							'description' => 'Browser detection via user agent',
						),
						array(
							'key'         => 'operating_system',
							'label'       => 'Operating system',
							'compare'     => array( '==', '!=' ),
							'value_type'  => 'string (windows, mac, linux, ubuntu, iphone, ipad, ipod, android, blackberry, webos)',
							'description' => 'OS detection via user agent',
						),
						array(
							'key'         => 'current_url',
							'label'       => 'Current URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'Current page URL including query parameters',
						),
						array(
							'key'         => 'referer',
							'label'       => 'Referrer URL',
							'compare'     => array( '==', '!=', 'contains', 'contains_not' ),
							'value_type'  => 'string',
							'description' => 'HTTP referrer URL',
						),
					),
				),
			),
			'woocommerce_group' => array(
				'note'  => 'WooCommerce conditions are available only when WooCommerce is active.',
				'name'  => 'woocommerce',
				'label' => 'WooCommerce',
				'keys'  => array(
					array(
						'key'        => 'woo_product_type',
						'label'      => 'Product type',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (simple, grouped, external, variable)',
					),
					array(
						'key'        => 'woo_product_sale',
						'label'      => 'Product sale status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = on sale, "0" = not)',
					),
					array(
						'key'        => 'woo_product_new',
						'label'      => 'Product new status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = new, "0" = not)',
					),
					array(
						'key'        => 'woo_product_stock_status',
						'label'      => 'Product stock status',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string (instock, outofstock, onbackorder)',
					),
					array(
						'key'        => 'woo_product_stock_quantity',
						'label'      => 'Product stock quantity',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric)',
					),
					array(
						'key'        => 'woo_product_stock_management',
						'label'      => 'Product stock management',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_sold_individually',
						'label'      => 'Product sold individually',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = enabled, "0" = disabled)',
					),
					array(
						'key'        => 'woo_product_purchased_by_user',
						'label'      => 'Product purchased by user',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_featured',
						'label'      => 'Product featured',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'string ("1" = true, "0" = false)',
					),
					array(
						'key'        => 'woo_product_rating',
						'label'      => 'Product rating',
						'compare'    => array( '==', '!=', '>=', '<=', '>', '<' ),
						'value_type' => 'string (numeric, average rating)',
					),
					array(
						'key'        => 'woo_product_category',
						'label'      => 'Product category',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
					array(
						'key'        => 'woo_product_tag',
						'label'      => 'Product tag',
						'compare'    => array( '==', '!=' ),
						'value_type' => 'array (term IDs)',
					),
				),
			),
			'examples'          => array(
				'logged_in_only'          => array(
					'description' => 'Show element only to logged-in users',
					'conditions'  => array( array( array( 'key' => 'user_logged_in', 'compare' => '==', 'value' => '1' ) ) ),
				),
				'admin_or_editor'         => array(
					'description' => 'Show element to administrators or editors',
					'conditions'  => array( array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator', 'editor' ) ) ) ),
				),
				'weekday_business_hours'  => array(
					'description' => 'Show element Monday-Friday between 9am-5pm',
					'conditions'  => array(
						array(
							array( 'key' => 'weekday', 'compare' => '>=', 'value' => '1' ),
							array( 'key' => 'weekday', 'compare' => '<=', 'value' => '5' ),
							array( 'key' => 'time', 'compare' => '>=', 'value' => '09:00' ),
							array( 'key' => 'time', 'compare' => '<=', 'value' => '17:00' ),
						),
					),
				),
				'dynamic_data_acf'        => array(
					'description' => 'Show element when ACF field "show_banner" is true',
					'conditions'  => array( array( array( 'key' => 'dynamic_data', 'compare' => '==', 'value' => '1', 'dynamic_data' => '{acf_show_banner}' ) ) ),
				),
				'or_logic'                => array(
					'description' => 'Show to admins OR when post has featured image (two condition sets = OR)',
					'conditions'  => array(
						array( array( 'key' => 'user_role', 'compare' => '==', 'value' => array( 'administrator' ) ) ),
						array( array( 'key' => 'featured_image', 'compare' => '==', 'value' => '1' ) ),
					),
				),
			),
			'notes'             => array(
				'Element conditions (this schema) are DIFFERENT from template conditions (template_condition tool). Template conditions use "main" key and control which pages a template targets. Element conditions use "key/compare/value" and control whether an individual element renders.',
				'Third-party plugins can register custom condition keys via the bricks/conditions/options filter. Unknown keys are accepted with a warning.',
				'Conditions are evaluated server-side at render time by Bricks Conditions::check(). The MCP only configures conditions — it does not evaluate them.',
			),
		);
	}

	/**
	 * Tool: Get global queries.
	 *
	 * Returns all reusable global query definitions stored in bricks_global_queries option.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Global queries list or error.
	 */
	private function tool_get_global_queries( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$queries = get_option( 'bricks_global_queries', array() );
		$queries = is_array( $queries ) ? $queries : array();

		return array(
			'global_queries' => $queries,
			'count'          => count( $queries ),
			'usage_hint'     => 'Reference a global query on any loop element: set query.id to the global query ID. Bricks resolves the settings at runtime.',
		);
	}

	/**
	 * Tool: Set global query (create or update).
	 *
	 * Creates a new global query or updates an existing one by query_id.
	 *
	 * @param array<string, mixed> $args Tool arguments including name, settings, optional query_id and category.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_set_global_query( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$queries  = get_option( 'bricks_global_queries', array() );
		$queries  = is_array( $queries ) ? $queries : array();
		$query_id = isset( $args['query_id'] ) ? sanitize_text_field( (string) $args['query_id'] ) : '';
		$name     = sanitize_text_field( $args['name'] ?? '' );
		$settings = $args['settings'] ?? array();
		$category = sanitize_text_field( $args['category'] ?? '' );

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', __( 'name is required for set_global_query.', 'bricks-mcp' ) );
		}
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return new \WP_Error( 'missing_settings', __( 'settings (object with query configuration) is required for set_global_query.', 'bricks-mcp' ) );
		}

		// Security: strip queryEditor/useQueryEditor from settings.
		unset( $settings['queryEditor'], $settings['useQueryEditor'] );

		$existing_index = false;
		if ( ! empty( $query_id ) ) {
			foreach ( $queries as $idx => $q ) {
				if ( isset( $q['id'] ) && $q['id'] === $query_id ) {
					$existing_index = $idx;
					break;
				}
			}
		}

		$id_generator = new ElementIdGenerator();
		$entry        = array(
			'id'       => ! empty( $query_id ) && false !== $existing_index
				? $query_id
				: $id_generator->generate_unique( $queries ),
			'name'     => $name,
			'settings' => $settings,
		);
		if ( ! empty( $category ) ) {
			$entry['category'] = $category;
		}

		if ( false !== $existing_index ) {
			$queries[ $existing_index ] = $entry;
			$action_taken               = 'updated';
		} else {
			$queries[]    = $entry;
			$action_taken = 'created';
		}

		update_option( 'bricks_global_queries', $queries );

		return array(
			'action'     => $action_taken,
			'query'      => $entry,
			'usage_hint' => sprintf( 'Reference this global query on any loop element: set query.id to "%s".', $entry['id'] ),
		);
	}

	/**
	 * Tool: Delete global query.
	 *
	 * Deletes a global query by ID and warns about orphaned element references.
	 *
	 * @param array<string, mixed> $args Tool arguments including query_id.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_delete_global_query( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$query_id = isset( $args['query_id'] ) ? sanitize_text_field( (string) $args['query_id'] ) : '';
		if ( empty( $query_id ) ) {
			return new \WP_Error( 'missing_query_id', __( 'query_id is required for delete_global_query.', 'bricks-mcp' ) );
		}

		$queries = get_option( 'bricks_global_queries', array() );
		$queries = is_array( $queries ) ? $queries : array();

		$found_index = false;
		$found_query = null;
		foreach ( $queries as $idx => $q ) {
			if ( isset( $q['id'] ) && $q['id'] === $query_id ) {
				$found_index = $idx;
				$found_query = $q;
				break;
			}
		}

		if ( false === $found_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Query ID */
					__( 'Global query "%s" not found. Use bricks:get_global_queries to list available queries.', 'bricks-mcp' ),
					$query_id
				)
			);
		}

		array_splice( $queries, $found_index, 1 );
		update_option( 'bricks_global_queries', $queries );

		return array(
			'deleted'  => true,
			'query_id' => $query_id,
			'name'     => $found_query['name'] ?? '',
			'warning'  => 'Any elements referencing this global query ID will fall back to empty query settings. Check for elements with query.id set to this ID.',
		);
	}

	/**
	 * Tool: Get form schema reference.
	 *
	 * Returns form element field types, action settings keys, and example patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Form schema reference.
	 */
	private function tool_get_form_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'description'              => 'Bricks form element settings reference. Forms are standard elements (name: "form") added via element:add or page:update_content.',
			'field_types'              => array(
				'text'       => array(
					'description' => 'Single-line text input',
					'properties'  => array( 'placeholder', 'required', 'minLength', 'maxLength', 'pattern', 'width' ),
				),
				'email'      => array(
					'description' => 'Email input with validation',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'textarea'   => array(
					'description' => 'Multi-line text',
					'properties'  => array( 'placeholder', 'required', 'height', 'width' ),
				),
				'richtext'   => array(
					'description' => 'TinyMCE rich text editor (since 2.1)',
					'properties'  => array( 'height', 'width' ),
				),
				'tel'        => array(
					'description' => 'Telephone input',
					'properties'  => array( 'placeholder', 'pattern', 'width' ),
				),
				'number'     => array(
					'description' => 'Numeric input',
					'properties'  => array( 'min', 'max', 'step', 'width' ),
				),
				'url'        => array(
					'description' => 'URL input',
					'properties'  => array( 'placeholder', 'width' ),
				),
				'password'   => array(
					'description' => 'Password with optional toggle',
					'properties'  => array( 'placeholder', 'required', 'width' ),
				),
				'select'     => array(
					'description' => 'Dropdown select',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'checkbox'   => array(
					'description' => 'Checkbox group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'radio'      => array(
					'description' => 'Radio button group',
					'properties'  => array( 'options (newline-separated string)', 'valueLabelOptions (bool)', 'required', 'width' ),
				),
				'file'       => array(
					'description' => 'File upload',
					'properties'  => array( 'fileUploadLimit', 'fileUploadSize', 'fileUploadAllowedTypes', 'fileUploadStorage', 'width' ),
				),
				'datepicker' => array(
					'description' => 'Date/time picker (Flatpickr)',
					'properties'  => array( 'time (bool)', 'l10n (language code)', 'width' ),
				),
				'image'      => array(
					'description' => 'Image picker (since 2.1)',
					'properties'  => array( 'width' ),
				),
				'gallery'    => array(
					'description' => 'Gallery picker',
					'properties'  => array( 'width' ),
				),
				'hidden'     => array(
					'description' => 'Hidden field',
					'properties'  => array( 'value' ),
				),
				'html'       => array(
					'description' => 'Static HTML output (not an input)',
					'properties'  => array(),
				),
				'rememberme' => array(
					'description' => 'Remember me checkbox (for login forms)',
					'properties'  => array(),
				),
			),
			'field_required_properties' => array(
				'id'   => '6-char lowercase alphanumeric (e.g. abc123) — REQUIRED on every field',
				'type' => 'One of the field types listed above — REQUIRED',
			),
			'field_common_properties'  => array(
				'label'        => 'string — displayed above the field',
				'placeholder'  => 'string — hint text inside the field',
				'value'        => 'string — default value',
				'required'     => 'bool — marks field as required',
				'width'        => 'number (0-100) — column width as percentage (100 = full width)',
				'name'         => 'string — custom name attribute (defaults to form-field-{id})',
				'errorMessage' => 'string — custom validation error message',
				'isHoneypot'   => 'bool — invisible spam trap (always available, no API key needed)',
			),
			'actions'                  => array(
				'email'        => array(
					'description'   => 'Send email notification',
					'required_keys' => array( 'emailSubject', 'emailTo' ),
					'optional_keys' => array( 'emailToCustom (when emailTo=custom)', 'emailBcc', 'fromEmail', 'fromName', 'replyToEmail', 'emailContent (use {{field_id}} or {{all_fields}})', 'htmlEmail (bool, default true)', 'emailErrorMessage' ),
					'confirmation'  => 'For confirmation email to submitter: confirmationEmailSubject, confirmationEmailContent, confirmationEmailTo',
				),
				'redirect'     => array(
					'description'   => 'Redirect after submission (always runs LAST regardless of position in actions array)',
					'required_keys' => array( 'redirect (URL)' ),
					'optional_keys' => array( 'redirectTimeout (ms delay)' ),
				),
				'webhook'      => array(
					'description'    => 'POST data to external URL (since 2.0)',
					'required_keys'  => array( 'webhooks (array of objects)' ),
					'webhook_object' => array(
						'name'         => 'string — endpoint label',
						'url'          => 'string — endpoint URL',
						'contentType'  => 'json or form-data (default: json)',
						'dataTemplate' => 'string — JSON template with {{field_id}} placeholders; empty sends all fields',
						'headers'      => 'string — JSON headers e.g. {"Authorization": "Bearer token"}',
					),
					'optional_keys'  => array( 'webhookMaxSize (KB, default 1024)', 'webhookErrorIgnore (bool)' ),
				),
				'login'        => array(
					'description'   => 'User login',
					'required_keys' => array( 'loginName (field ID for username/email)', 'loginPassword (field ID for password)' ),
					'optional_keys' => array( 'loginRemember (field ID for remember me)', 'loginErrorMessage' ),
				),
				'registration' => array(
					'description'   => 'User registration',
					'required_keys' => array( 'registrationEmail (field ID)', 'registrationPassword (field ID)' ),
					'optional_keys' => array( 'registrationUserName (field ID)', 'registrationFirstName (field ID)', 'registrationLastName (field ID)', 'registrationRole (slug, NEVER administrator)', 'registrationAutoLogin (bool)', 'registrationPasswordMinLength (default 6)', 'registrationWPNotification (bool)' ),
				),
				'create-post'  => array(
					'description'   => 'Create a WordPress post from form data (since 2.1)',
					'required_keys' => array( 'createPostType (post type slug)', 'createPostTitle (field ID)' ),
					'optional_keys' => array( 'createPostContent (field ID)', 'createPostExcerpt (field ID)', 'createPostFeaturedImage (field ID)', 'createPostStatus (draft/publish)', 'createPostMeta (repeater: metaKey, metaValue, sanitizationMethod)', 'createPostTaxonomies (repeater: taxonomy, fieldId)' ),
				),
				'custom'       => array(
					'description'   => 'Custom action via bricks/form/custom_action hook',
					'required_keys' => array(),
				),
			),
			'general_settings'         => array(
				'successMessage'            => 'string — shown after successful submit',
				'submitButtonText'          => 'string — button text (default: Send)',
				'requiredAsterisk'          => 'bool — show asterisk on required fields',
				'showLabels'                => 'bool — show field labels',
				'enableRecaptcha'           => 'bool — Google reCAPTCHA v3 (needs API key in Bricks settings)',
				'enableHCaptcha'            => 'bool — hCaptcha (needs API key in Bricks settings)',
				'enableTurnstile'           => 'bool — Cloudflare Turnstile (needs API key in Bricks settings)',
				'disableBrowserValidation'  => 'bool — add novalidate attribute',
				'validateAllFieldsOnSubmit' => 'bool — show all errors on submit, not just first',
			),
			'examples'                 => array(
				'contact_form'      => array(
					'fields'           => array(
						array(
							'id'          => 'abc123',
							'type'        => 'text',
							'label'       => 'Name',
							'placeholder' => 'Your Name',
							'width'       => 100,
						),
						array(
							'id'          => 'def456',
							'type'        => 'email',
							'label'       => 'Email',
							'placeholder' => 'you@example.com',
							'required'    => true,
							'width'       => 100,
						),
						array(
							'id'          => 'ghi789',
							'type'        => 'textarea',
							'label'       => 'Message',
							'placeholder' => 'Your Message',
							'required'    => true,
							'width'       => 100,
						),
					),
					'actions'          => array( 'email' ),
					'emailSubject'     => 'Contact form request',
					'emailTo'          => 'admin_email',
					'htmlEmail'        => true,
					'successMessage'   => 'Thank you! We will get back to you soon.',
					'submitButtonText' => 'Send Message',
				),
				'login_form'        => array(
					'fields'           => array(
						array(
							'id'       => 'lgn001',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'lgn002',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'    => 'lgn003',
							'type'  => 'rememberme',
							'label' => 'Remember Me',
						),
					),
					'actions'          => array( 'login', 'redirect' ),
					'loginName'        => 'lgn001',
					'loginPassword'    => 'lgn002',
					'loginRemember'    => 'lgn003',
					'redirect'         => '/account',
					'submitButtonText' => 'Log In',
				),
				'registration_form' => array(
					'fields'                => array(
						array(
							'id'       => 'reg001',
							'type'     => 'text',
							'label'    => 'Username',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg002',
							'type'     => 'email',
							'label'    => 'Email',
							'required' => true,
							'width'    => 100,
						),
						array(
							'id'       => 'reg003',
							'type'     => 'password',
							'label'    => 'Password',
							'required' => true,
							'width'    => 100,
						),
					),
					'actions'               => array( 'registration', 'redirect' ),
					'registrationUserName'  => 'reg001',
					'registrationEmail'     => 'reg002',
					'registrationPassword'  => 'reg003',
					'registrationRole'      => 'subscriber',
					'registrationAutoLogin' => true,
					'redirect'              => '/welcome',
					'successMessage'        => 'Registration successful!',
					'submitButtonText'      => 'Create Account',
				),
			),
			'notes'                    => array(
				'Field IDs must be 6-char lowercase alphanumeric (same format as element IDs). Bricks uses form-field-{id} as the submission key.',
				'Options for select/checkbox/radio use newline-separated strings: "Option 1\nOption 2\nOption 3" — NOT arrays.',
				'Redirect action always runs last regardless of position in the actions array.',
				'CAPTCHA (reCAPTCHA, hCaptcha, Turnstile) requires API keys configured in Bricks > Settings > API Keys. Honeypot (isHoneypot: true) works without any configuration.',
				'Never set registrationRole to "administrator" — Bricks blocks this for security.',
				'Use {{field_id}} in emailContent/dataTemplate to reference field values. Use {{all_fields}} to include all fields.',
			),
		);
	}

	/**
	 * Tool: Get interaction schema reference.
	 *
	 * Returns element interaction/animation triggers, actions, animation types, and example patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Interaction schema reference.
	 */
	private function tool_get_interaction_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'description'       => 'Bricks element interaction/animation settings reference. Interactions are stored in settings._interactions as a repeater array on any element. Use element:update or page:update_content to add interactions.',
			'important'         => 'NEVER use deprecated _animation/_animationDuration/_animationDelay keys. Always use _interactions array. Each interaction needs a unique 6-char lowercase alphanumeric id field.',
			'triggers'          => array(
				'click'            => 'Element clicked',
				'mouseover'        => 'Mouse over element',
				'mouseenter'       => 'Mouse enters element',
				'mouseleave'       => 'Mouse leaves element',
				'focus'            => 'Element receives focus',
				'blur'             => 'Element loses focus',
				'enterView'        => 'Element enters viewport (IntersectionObserver)',
				'leaveView'        => 'Element leaves viewport',
				'animationEnd'     => 'Another interaction\'s animation ends (chain via animationId)',
				'contentLoaded'    => 'DOM content loaded (optional delay field)',
				'scroll'           => 'Window scroll reaches scrollOffset value',
				'mouseleaveWindow' => 'Mouse leaves browser window',
				'ajaxStart'        => 'Query loop AJAX starts (requires ajaxQueryId)',
				'ajaxEnd'          => 'Query loop AJAX ends (requires ajaxQueryId)',
				'formSubmit'       => 'Form submitted (requires formId)',
				'formSuccess'      => 'Form submission succeeded (requires formId)',
				'formError'        => 'Form submission failed (requires formId)',
			),
			'actions'           => array(
				'startAnimation'   => 'Run Animate.css animation (requires animationType)',
				'show'             => 'Show target element (remove display:none)',
				'hide'             => 'Hide target element (set display:none)',
				'click'            => 'Programmatically click target element',
				'setAttribute'     => 'Set HTML attribute on target',
				'removeAttribute'  => 'Remove HTML attribute from target',
				'toggleAttribute'  => 'Toggle HTML attribute on target',
				'toggleOffCanvas'  => 'Toggle Bricks off-canvas element',
				'loadMore'         => 'Load more results in query loop (requires loadMoreQuery)',
				'loadMoreGallery'  => 'Load more images in Image Gallery element (Bricks 2.3+). Configure loadMoreInitial, loadMoreStep, loadMoreInfiniteScroll, loadMoreInfiniteScrollDelay, loadMoreInfiniteScrollOffset on the Image Gallery element settings, then add this interaction action on the trigger element (e.g. a button)',
				'scrollTo'         => 'Smooth scroll to target element',
				'javascript'       => 'Call a global JS function (GSAP bridge, requires jsFunction)',
				'openAddress'      => 'Open map info box',
				'closeAddress'     => 'Close map info box',
				'clearForm'        => 'Clear form fields',
				'storageAdd'       => 'Add to browser storage',
				'storageRemove'    => 'Remove from browser storage',
				'storageCount'     => 'Count browser storage items',
			),
			'target_options'    => array(
				'self'   => 'The element the interaction is on (default)',
				'custom' => 'CSS selector in targetSelector field (e.g. "#brxe-abc123", ".my-class")',
				'popup'  => 'Popup template by templateId',
			),
			'interaction_fields' => array(
				'id'                    => 'Required. 6-char lowercase alphanumeric, unique per interaction',
				'trigger'               => 'Required. See triggers list',
				'action'                => 'Required. See actions list',
				'target'                => 'Optional. "self" (default), "custom", or "popup"',
				'targetSelector'        => 'Required when target="custom". Full CSS selector',
				'animationType'         => 'Required when action="startAnimation". See animation_types',
				'animationDuration'     => 'Optional. CSS time value, e.g. "0.8s" or "800ms" (default "1s")',
				'animationDelay'        => 'Optional. CSS time value, e.g. "0.3s" (default "0s")',
				'rootMargin'            => 'Optional for enterView. IntersectionObserver rootMargin, e.g. "0px 0px -80px 0px"',
				'runOnce'               => 'Optional boolean. Animate only on first trigger occurrence',
				'delay'                 => 'Optional for contentLoaded. Delay before execution, e.g. "0.5s"',
				'scrollOffset'          => 'Optional for scroll trigger. Offset value in px/vh/%',
				'animationId'           => 'Required for animationEnd trigger. ID of the interaction to wait for',
				'jsFunction'            => 'Required for javascript action. Global function name, e.g. "myAnimations.parallax"',
				'jsFunctionArgs'        => 'Optional for javascript action. Array of {id, jsFunctionArg} objects. Use "%brx%" for Bricks params object',
				'disablePreventDefault' => 'Optional boolean for click trigger. Allow link default behavior',
				'ajaxQueryId'           => 'Required for ajaxStart/ajaxEnd triggers',
				'formId'                => 'Required for formSubmit/formSuccess/formError triggers',
				'templateId'            => 'Required for target="popup"',
				'loadMoreQuery'         => 'Required for loadMore action. Typically "main"',
				'loadMoreTargetSelector' => 'Required for loadMoreGallery action. CSS selector of the Image Gallery element, e.g. "#brxe-abc123"',
				'interactionConditions' => 'Optional. Array of condition objects for conditional execution',
			),
			'animation_types'   => array(
				'attention'  => array( 'bounce', 'flash', 'pulse', 'rubberBand', 'shakeX', 'shakeY', 'headShake', 'swing', 'tada', 'wobble', 'jello', 'heartBeat' ),
				'back'       => array( 'backInDown', 'backInLeft', 'backInRight', 'backInUp', 'backOutDown', 'backOutLeft', 'backOutRight', 'backOutUp' ),
				'bounce'     => array( 'bounceIn', 'bounceInDown', 'bounceInLeft', 'bounceInRight', 'bounceInUp', 'bounceOut', 'bounceOutDown', 'bounceOutLeft', 'bounceOutRight', 'bounceOutUp' ),
				'fade'       => array( 'fadeIn', 'fadeInDown', 'fadeInDownBig', 'fadeInLeft', 'fadeInLeftBig', 'fadeInRight', 'fadeInRightBig', 'fadeInUp', 'fadeInUpBig', 'fadeInTopLeft', 'fadeInTopRight', 'fadeInBottomLeft', 'fadeInBottomRight', 'fadeOut', 'fadeOutDown', 'fadeOutDownBig', 'fadeOutLeft', 'fadeOutLeftBig', 'fadeOutRight', 'fadeOutRightBig', 'fadeOutUp', 'fadeOutUpBig', 'fadeOutTopLeft', 'fadeOutTopRight', 'fadeOutBottomRight', 'fadeOutBottomLeft' ),
				'flip'       => array( 'flip', 'flipInX', 'flipInY', 'flipOutX', 'flipOutY' ),
				'lightspeed' => array( 'lightSpeedInRight', 'lightSpeedInLeft', 'lightSpeedOutRight', 'lightSpeedOutLeft' ),
				'rotate'     => array( 'rotateIn', 'rotateInDownLeft', 'rotateInDownRight', 'rotateInUpLeft', 'rotateInUpRight', 'rotateOut', 'rotateOutDownLeft', 'rotateOutDownRight', 'rotateOutUpLeft', 'rotateOutUpRight' ),
				'special'    => array( 'hinge', 'jackInTheBox', 'rollIn', 'rollOut' ),
				'zoom'       => array( 'zoomIn', 'zoomInDown', 'zoomInLeft', 'zoomInRight', 'zoomInUp', 'zoomOut', 'zoomOutDown', 'zoomOutLeft', 'zoomOutRight', 'zoomOutUp' ),
				'slide'      => array( 'slideInUp', 'slideInDown', 'slideInLeft', 'slideInRight', 'slideOutUp', 'slideOutDown', 'slideOutLeft', 'slideOutRight' ),
			),
			'examples'          => array(
				'scroll_reveal'  => array(
					'description'   => 'Fade in element when scrolled into view',
					'_interactions' => array(
						array(
							'id'                => 'aa1bb2',
							'trigger'           => 'enterView',
							'rootMargin'        => '0px 0px -80px 0px',
							'action'            => 'startAnimation',
							'animationType'     => 'fadeInUp',
							'animationDuration' => '0.8s',
							'animationDelay'    => '0s',
							'target'            => 'self',
							'runOnce'           => true,
						),
					),
				),
				'stagger_cards'  => array(
					'description'          => 'Three cards fade in with incremental delays (apply to each card element)',
					'card_1_interactions'  => array(
						array( 'id' => 'cc3dd4', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_2_interactions'  => array(
						array( 'id' => 'ee5ff6', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.15s', 'target' => 'self', 'runOnce' => true ),
					),
					'card_3_interactions'  => array(
						array( 'id' => 'gg7hh8', 'trigger' => 'enterView', 'action' => 'startAnimation', 'animationType' => 'fadeInUp', 'animationDuration' => '0.8s', 'animationDelay' => '0.3s', 'target' => 'self', 'runOnce' => true ),
					),
				),
				'chained_hero'   => array(
					'description'            => 'Hero title animates on load, then subtitle fades in after title finishes',
					'title_interactions'     => array(
						array( 'id' => 'ii9jj0', 'trigger' => 'contentLoaded', 'action' => 'startAnimation', 'animationType' => 'fadeInDown', 'animationDuration' => '0.8s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
					'subtitle_interactions'  => array(
						array( 'id' => 'kk1ll2', 'trigger' => 'animationEnd', 'animationId' => 'ii9jj0', 'action' => 'startAnimation', 'animationType' => 'fadeIn', 'animationDuration' => '0.6s', 'animationDelay' => '0s', 'target' => 'self' ),
					),
				),
				'native_parallax' => array(
					'description'          => 'Native parallax (Bricks 2.3+). Style properties under Transform group — no GSAP or interactions needed. Prefer this over GSAP for simple parallax.',
					'element_parallax'     => array(
						'_motionElementParallax'       => true,
						'_motionElementParallaxSpeedX'  => 0,
						'_motionElementParallaxSpeedY'  => -20,
						'_motionStartVisiblePercent'    => 0,
					),
					'background_parallax'  => array(
						'_motionBackgroundParallax'      => true,
						'_motionBackgroundParallaxSpeed' => -15,
						'_motionStartVisiblePercent'     => 0,
					),
					'notes'                => array(
						'Speed values are percentages. Negative = opposite scroll direction.',
						'_motionStartVisiblePercent: 0 = element entering viewport, 50 = near center.',
						'Not visible in builder preview — only on live frontend.',
						'These are style properties, NOT interactions. Set directly on element settings.',
					),
				),
				'gsap_parallax'  => array(
					'description'                => 'GSAP ScrollTrigger parallax via javascript action. Requires GSAP loaded on page. For simple parallax, prefer native_parallax example above. Use GSAP only for advanced control (custom easing, scrub values, timeline sequencing).',
					'step_1_page_script'         => 'Use page:update_settings with customScriptsBodyFooter to add: <script>document.addEventListener("DOMContentLoaded",function(){if(typeof gsap==="undefined")return;gsap.registerPlugin(ScrollTrigger);window.brxGsap={parallax:function(b){gsap.to(b.source,{yPercent:-20,ease:"none",scrollTrigger:{trigger:b.source,scrub:1}})}}});</script>',
					'step_2_element_interaction'  => array(
						array( 'id' => 'mm3nn4', 'trigger' => 'contentLoaded', 'action' => 'javascript', 'jsFunction' => 'brxGsap.parallax', 'jsFunctionArgs' => array( array( 'id' => 'oo5pp6', 'jsFunctionArg' => '%brx%' ) ), 'target' => 'self' ),
					),
				),
			),
			'image_gallery_load_more' => array(
					'description'               => 'Image Gallery with load more + infinite scroll (Bricks 2.3+). Step 1: Set load more settings on the Image Gallery element. Step 2: Add a button with loadMoreGallery interaction targeting the gallery.',
					'step_1_gallery_settings'   => array(
						'note'                          => 'Set these on the Image Gallery element settings (not in _interactions)',
						'loadMoreInitial'               => 6,
						'loadMoreStep'                  => 3,
						'loadMoreInfiniteScroll'        => true,
						'loadMoreInfiniteScrollDelay'   => '600ms',
						'loadMoreInfiniteScrollOffset'  => '200px',
					),
					'step_2_button_interactions' => array(
						'note'           => 'Add this interaction on the button or trigger element. loadMoreGallery does not use target/targetSelector — it uses loadMoreTargetSelector instead.',
						'_interactions'  => array(
							array(
								'id'                     => 'pp7qq8',
								'trigger'                => 'click',
								'action'                 => 'loadMoreGallery',
								'loadMoreTargetSelector' => '#brxe-{galleryElementId}',
							),
						),
					),
				),
			'notes'             => array(
				'Each interaction id must be unique — 6-char lowercase alphanumeric (same format as element IDs).',
				'Animation types containing "In" (case-sensitive) automatically hide the element on page load and reveal on animation.',
				'Use "In" types for enterView/contentLoaded triggers. "Out" types are for exit animations or click-triggered hiding.',
				'Bricks auto-enqueues Animate.css when startAnimation action is detected — no manual enqueue needed.',
				'For GSAP: the plugin does NOT enqueue GSAP. The site owner must load it (CDN or local). AI should inject via page:update_settings customScriptsBodyFooter.',
				'The deprecated _animation, _animationDuration, _animationDelay keys still work but show converter warnings. Never generate them.',
			),
		);
	}

	/**
	 * Tool: Enable the Bricks editor for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_enable_bricks( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to enable Bricks on.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$was_already_enabled = $this->bricks_service->is_bricks_page( $post_id );
		$this->bricks_service->enable_bricks_editor( $post_id );
		$elements = $this->bricks_service->get_elements( $post_id );

		return array(
			'post_id'             => $post_id,
			'title'               => $post->post_title,
			'bricks_enabled'      => true,
			'was_already_enabled' => $was_already_enabled,
			'element_count'       => count( $elements ),
			'edit_url'            => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Tool: Disable the Bricks editor for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_disable_bricks( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to disable Bricks on.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$was_already_disabled = ! $this->bricks_service->is_bricks_page( $post_id );
		$this->bricks_service->disable_bricks_editor( $post_id );

		return array(
			'post_id'              => $post_id,
			'title'                => $post->post_title,
			'bricks_enabled'       => false,
			'was_already_disabled' => $was_already_disabled,
			'note'                 => __( 'Bricks content preserved in database. Re-enable with bricks tool (action: enable).', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Page dispatcher — routes to list, search, get, create, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		// Map 'search' param alias: the schema uses 'search' but tool_search_pages reads 'query'.
		if ( 'search' === $action && isset( $args['search'] ) && ! isset( $args['query'] ) ) {
			$args['query'] = $args['search'];
		}

		// Map 'posts_per_page' alias to 'per_page' for list/search.
		if ( isset( $args['posts_per_page'] ) && ! isset( $args['per_page'] ) ) {
			$args['per_page'] = $args['posts_per_page'];
		}

		// Map 'paged' alias to 'page' for list.
		if ( isset( $args['paged'] ) && ! isset( $args['page'] ) ) {
			$args['page'] = $args['paged'];
		}

		return match ( $action ) {
			'list'            => $this->tool_list_pages( $args ),
			'search'          => $this->tool_search_pages( $args ),
			'get'             => $this->tool_get_bricks_content( $args ),
			'create'          => $this->tool_create_bricks_page( $args ),
			'update_content'  => $this->tool_update_bricks_content( $args ),
			'update_meta'     => $this->tool_update_page( $args ),
			'delete'          => $this->tool_delete_page( $args ),
			'duplicate'       => $this->tool_duplicate_page( $args ),
			'get_settings'    => $this->tool_get_page_settings( $args ),
			'update_settings' => $this->tool_update_page_settings( $args ),
			'get_seo'         => $this->tool_get_page_seo( $args ),
			'update_seo'      => $this->tool_update_page_seo( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, search, get, create, update_content, update_meta, delete, duplicate, get_settings, update_settings, get_seo, update_seo', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Element dispatcher — routes to add, update, remove.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_element( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';

		return match ( $action ) {
			'add'            => $this->tool_add_element( $args ),
			'update'         => $this->tool_update_element( $args ),
			'remove'         => $this->tool_remove_element( $args ),
			'get_conditions' => $this->tool_get_conditions( $args ),
			'set_conditions' => $this->tool_set_conditions( $args ),
			'move'           => $this->tool_move_element( $args ),
			'bulk_update'    => $this->tool_bulk_update_elements( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: add, update, remove, get_conditions, set_conditions, move, bulk_update', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Format a post for list/search responses.
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, mixed> Formatted post data.
	 */
	private function format_post_for_list( \WP_Post $post ): array {
		$elements   = $this->bricks_service->get_elements( $post->ID );
		$has_bricks = $this->bricks_service->is_bricks_page( $post->ID );

		return array(
			'id'                 => $post->ID,
			'title'              => $post->post_title,
			'status'             => $post->post_status,
			'type'               => $post->post_type,
			'slug'               => $post->post_name,
			'date'               => $post->post_date,
			'modified'           => $post->post_modified,
			'author_name'        => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'permalink'          => get_permalink( $post->ID ),
			'has_bricks_content' => $has_bricks,
			'element_count'      => count( $elements ),
		);
	}

	/**
	 * Tool: Get Bricks content for a post.
	 *
	 * Returns element JSON in native flat array format with page metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Content data or error.
	 */
	private function tool_get_bricks_content( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ), [ 'next_step' => 'Call page:list to find valid post IDs.', 'suggested_tool' => 'page' ] );
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ),
				[ 'next_step' => 'Call page:list to find valid post IDs.', 'suggested_tool' => 'page' ]
			);
		}

		if ( ! $this->bricks_service->is_bricks_page( $post_id ) ) {
			return new \WP_Error(
				'not_bricks_page',
				sprintf( __( 'Post %d is not using the Bricks editor.', 'bricks-mcp' ), $post_id ),
				[ 'next_step' => 'Call bricks:enable(post_id=' . $post_id . ') to enable Bricks on this post first.', 'suggested_tool' => 'bricks' ]
			);
		}

		$view     = $args['view'] ?? 'detail';
		$metadata = $this->bricks_service->get_page_metadata( $post_id );

		if ( 'summary' === $view ) {
			return array(
				'metadata' => $metadata,
				'summary'  => $this->bricks_service->get_page_summary( $post_id ),
			);
		}

		if ( 'visual' === $view ) {
			return array(
				'metadata' => $metadata,
				'visual'   => $this->bricks_service->get_visual_layout( $post_id ),
			);
		}

		return array(
			'metadata' => $metadata,
			'elements' => $this->bricks_service->get_elements( $post_id ),
		);
	}

	/**
	 * Tool: List pages/posts with optional Bricks filter.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Posts list or error.
	 */
	private function tool_list_pages( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$post_type   = $args['post_type'] ?? 'page';
		$status      = $args['status'] ?? 'any';
		$per_page    = min( (int) ( $args['per_page'] ?? 20 ), 100 );
		$page        = (int) ( $args['page'] ?? 1 );
		$bricks_only = isset( $args['bricks_only'] ) ? (bool) $args['bricks_only'] : true;

		$query_args = array(
			'post_type'      => sanitize_key( $post_type ),
			'post_status'    => sanitize_key( $status ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		if ( $bricks_only ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			);
		}

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Tool: Search Bricks pages by title or content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
	 */
	private function tool_search_pages( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['query'] ) ) {
			return new \WP_Error( 'missing_query', __( 'query parameter is required.', 'bricks-mcp' ) );
		}

		$search_query = sanitize_text_field( $args['query'] );
		$post_type    = $args['post_type'] ?? 'page';
		$per_page     = min( (int) ( $args['per_page'] ?? 20 ), 100 );

		$query_args = array(
			'post_type'      => sanitize_key( $post_type ),
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			's'              => $search_query,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => BricksService::EDITOR_MODE_KEY,
					'value' => 'bricks',
				),
			),
		);

		$query      = new \WP_Query( $query_args );
		$result     = array();

		// Prime user cache to avoid N+1 queries for get_the_author_meta().
		$author_ids = array_unique( array_map( fn( $p ) => (int) $p->post_author, $query->posts ) );
		cache_users( $author_ids );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$result[] = $this->format_post_for_list( $post );
			}
		}

		return $result;
	}

	/**
	 * Tool: Create a new Bricks page/post.
	 *
	 * Creates a post with Bricks editor enabled and optionally saves elements.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created post data or error.
	 */
	private function tool_create_bricks_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'title is required. Provide a non-empty page title.', 'bricks-mcp' )
			);
		}

		$post_id = $this->bricks_service->create_page( $args );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post     = get_post( $post_id );
		$elements = $this->bricks_service->get_elements( $post_id );

		return array(
			'post_id'       => $post_id,
			'title'         => $post ? $post->post_title : $args['title'],
			'status'        => $post ? $post->post_status : ( $args['status'] ?? 'draft' ),
			'permalink'     => get_permalink( $post_id ),
			'element_count' => count( $elements ),
			'edit_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Tool: Replace full Bricks element content for a page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated content info or error.
	 */
	private function tool_update_bricks_content( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements array is required. Provide an array of Bricks elements.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Normalize via ElementNormalizer (handles both native and simplified format).
		$elements = $this->bricks_service->normalize_elements( $args['elements'] );
		$saved    = $this->bricks_service->save_elements( $post_id, $elements );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$metadata = $this->bricks_service->get_page_metadata( $post_id );

		return array(
			'post_id'       => $post_id,
			'element_count' => count( $elements ),
			'metadata'      => $metadata,
		);
	}

	/**
	 * Tool: Update page/post metadata (title, status, slug).
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated metadata or error.
	 */
	private function tool_update_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$result  = $this->bricks_service->update_page_meta( $post_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->bricks_service->get_page_metadata( $post_id );
	}

	/**
	 * Tool: Move a page/post to trash.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id = (int) $args['post_id'];
		$result  = $this->bricks_service->delete_page( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id' => $post_id,
			'status'  => 'trash',
			'message' => __( 'Post moved to trash. It can be recovered from the WordPress trash.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Duplicate a page/post including all Bricks content.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error New post data or error.
	 */
	private function tool_duplicate_page( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$new_post_id = $this->bricks_service->duplicate_page( $post_id );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$post     = get_post( $new_post_id );
		$elements = $this->bricks_service->get_elements( $new_post_id );

		return array(
			'post_id'       => $new_post_id,
			'title'         => $post ? $post->post_title : '',
			'status'        => $post ? $post->post_status : 'draft',
			'permalink'     => get_permalink( $new_post_id ),
			'element_count' => count( $elements ),
		);
	}

	/**
	 * Tool: Add a single element to an existing Bricks page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Element info or error.
	 */
	private function tool_add_element( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error( 'missing_name', __( 'name is required. Provide the Bricks element type (e.g. heading, container, section).', 'bricks-mcp' ) );
		}

		$post_id   = (int) $args['post_id'];
		$parent_id = isset( $args['parent_id'] ) ? (string) $args['parent_id'] : '0';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;
		$element   = array(
			'name'     => sanitize_text_field( $args['name'] ),
			'settings' => isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array(),
		);

		return $this->bricks_service->add_element( $post_id, $element, $parent_id, $position );
	}

	/**
	 * Tool: Update settings for a specific element.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update info or error.
	 */
	private function tool_update_element( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required. Use get_bricks_content to retrieve element IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['settings'] ) || ! is_array( $args['settings'] ) ) {
			return new \WP_Error( 'missing_settings', __( 'settings object is required. Provide the settings keys and values to update.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$settings   = $args['settings'];

		return $this->bricks_service->update_element( $post_id, $element_id, $settings );
	}

	/**
	 * Tool: Remove an element from a Bricks page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal info or error.
	 */
	private function tool_remove_element( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required. Use get_bricks_content to retrieve element IDs.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );

		return $this->bricks_service->remove_element( $post_id, $element_id );
	}

	/**
	 * Tool: Move or reorder element within page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_move_element( array $args ): array|\WP_Error {
		$post_id          = (int) ( $args['post_id'] ?? 0 );
		$element_id       = $args['element_id'] ?? '';
		$target_parent_id = $args['target_parent_id'] ?? '';
		$position         = isset( $args['position'] ) ? (int) $args['position'] : null;

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( '' === $element_id ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		return $this->bricks_service->move_element( $post_id, $element_id, $target_parent_id, $position );
	}

	/**
	 * Tool: Bulk update element settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_bulk_update_elements( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$updates = $args['updates'] ?? [];

		if ( 0 === $post_id ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return new \WP_Error( 'missing_updates', __( 'updates array is required with at least one {element_id, settings} object.', 'bricks-mcp' ) );
		}

		return $this->bricks_service->bulk_update_elements( $post_id, $updates );
	}

	/**
	 * Tool: Get element visibility conditions.
	 *
	 * Returns the raw _conditions settings from a specific element on a page.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and 'element_id'.
	 * @return array<string, mixed>|\WP_Error Conditions data or error.
	 */
	private function tool_get_conditions( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$elements = $this->bricks_service->get_elements( $post_id );
		$target   = null;

		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				$target = $element;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$conditions = $target['settings']['_conditions'] ?? array();

		return array(
			'post_id'        => $post_id,
			'element_id'     => $element_id,
			'element_name'   => $target['name'] ?? 'unknown',
			'has_conditions' => ! empty( $conditions ),
			'condition_sets' => count( $conditions ),
			'conditions'     => $conditions,
			'note'           => empty( $conditions )
				? __( 'No conditions set on this element. Use element:set_conditions to add visibility conditions. Call bricks:get_condition_schema for available condition types.', 'bricks-mcp' )
				: __( 'Outer array = OR logic (any set renders element). Inner arrays = AND logic (all conditions in a set must pass). Use element:set_conditions to replace.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Set element visibility conditions.
	 *
	 * Validates condition structure (2-level array nesting, key whitelist, user role
	 * validation) and sets _conditions on a specific element. Accepts full Bricks
	 * condition format only — no simplified shorthand.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id', 'element_id', 'conditions'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	private function tool_set_conditions( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_id'] ) ) {
			return new \WP_Error( 'missing_element_id', __( 'element_id is required.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['conditions'] ) ) {
			return new \WP_Error( 'missing_conditions', __( 'conditions is required. Pass an array of condition sets, or an empty array [] to clear all conditions.', 'bricks-mcp' ) );
		}

		if ( ! is_array( $args['conditions'] ) ) {
			return new \WP_Error( 'invalid_conditions', __( 'conditions must be an array. Pass an array of condition sets (array of arrays of condition objects), or an empty array [] to clear.', 'bricks-mcp' ) );
		}

		$post_id    = (int) $args['post_id'];
		$element_id = sanitize_text_field( $args['element_id'] );
		$conditions = $args['conditions'];
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$elements = $this->bricks_service->get_elements( $post_id );
		$target_index = null;

		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				$target_index = $index;
				break;
			}
		}

		if ( null === $target_index ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$warnings = array();

		// Known condition keys from Bricks conditions.php.
		$known_keys = array(
			'post_id', 'post_title', 'post_parent', 'post_status', 'post_author', 'post_date', 'featured_image',
			'user_logged_in', 'user_id', 'user_registered', 'user_role',
			'weekday', 'date', 'time', 'datetime',
			'dynamic_data', 'browser', 'operating_system', 'current_url', 'referer',
			'woo_product_type', 'woo_product_sale', 'woo_product_new', 'woo_product_stock_status',
			'woo_product_stock_quantity', 'woo_product_stock_management', 'woo_product_sold_individually',
			'woo_product_purchased_by_user', 'woo_product_featured', 'woo_product_rating',
			'woo_product_category', 'woo_product_tag',
		);

		$valid_compare = array( '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' );

		// Validate condition structure: must be array of arrays of condition objects.
		foreach ( $conditions as $set_index => $condition_set ) {
			if ( ! is_array( $condition_set ) ) {
				return new \WP_Error(
					'invalid_condition_structure',
					sprintf(
						/* translators: %d: Set index */
						__( 'Condition set at index %d must be an array of condition objects. Expected format: [[{key, compare, value}, ...], ...]. Each outer element is a condition set (OR logic), each inner element is a condition (AND logic within set).', 'bricks-mcp' ),
						$set_index
					)
				);
			}

			foreach ( $condition_set as $cond_index => $condition ) {
				if ( ! is_array( $condition ) ) {
					return new \WP_Error(
						'invalid_condition_object',
						sprintf(
							/* translators: 1: Condition index, 2: Set index */
							__( 'Condition at index %1$d in set %2$d must be an object with at least a "key" field. Example: {"key": "user_logged_in", "compare": "==", "value": "1"}', 'bricks-mcp' ),
							$cond_index,
							$set_index
						)
					);
				}

				$key = $condition['key'] ?? null;

				if ( null === $key || '' === $key ) {
					return new \WP_Error(
						'missing_condition_key',
						sprintf(
							/* translators: 1: Condition index, 2: Set index */
							__( 'Condition at index %1$d in set %2$d is missing required "key" field.', 'bricks-mcp' ),
							$cond_index,
							$set_index
						)
					);
				}

				// Validate key against known keys — warn on unknown (3rd-party plugins may add custom keys).
				if ( ! in_array( $key, $known_keys, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Key name, 2: Set index, 3: Condition index */
						__( 'Unknown condition key "%1$s" at set %2$d, condition %3$d. This may be from a third-party plugin — saving anyway.', 'bricks-mcp' ),
						$key,
						$set_index,
						$cond_index
					);
				}

				// Validate user_role values against wp_roles — reject unknown roles per CONTEXT.md decision.
				if ( 'user_role' === $key && isset( $condition['value'] ) ) {
					$role_values = is_array( $condition['value'] ) ? $condition['value'] : array( $condition['value'] );
					$valid_roles = array_keys( wp_roles()->get_names() );
					$invalid     = array_diff( $role_values, $valid_roles );

					if ( ! empty( $invalid ) ) {
						return new \WP_Error(
							'invalid_user_role',
							sprintf(
								/* translators: 1: Invalid role names, 2: Valid role names */
								__( 'Unknown user role(s): %1$s. Valid roles: %2$s.', 'bricks-mcp' ),
								implode( ', ', $invalid ),
								implode( ', ', $valid_roles )
							)
						);
					}
				}

				// Validate dynamic_data field presence when key is dynamic_data.
				if ( 'dynamic_data' === $key && empty( $condition['dynamic_data'] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Set index, 2: Condition index */
						__( 'Condition at set %1$d, condition %2$d has key "dynamic_data" but no "dynamic_data" field for the tag. The "dynamic_data" field should contain the tag to evaluate (e.g. "{acf_my_field}"), and "value" should contain the comparison target.', 'bricks-mcp' ),
						$set_index,
						$cond_index
					);
				}

				// Validate compare operator — warn on unknown.
				if ( isset( $condition['compare'] ) && ! in_array( $condition['compare'], $valid_compare, true ) ) {
					$warnings[] = sprintf(
						/* translators: 1: Operator, 2: Set index, 3: Condition index */
						__( 'Unknown compare operator "%1$s" at set %2$d, condition %3$d. Known operators: ==, !=, >=, <=, >, <, contains, contains_not, empty, empty_not.', 'bricks-mcp' ),
						$condition['compare'],
						$set_index,
						$cond_index
					);
				}
			}
		}

		// Set conditions on the element. Empty array clears all conditions.
		if ( empty( $conditions ) ) {
			unset( $elements[ $target_index ]['settings']['_conditions'] );
		} else {
			$elements[ $target_index ]['settings']['_conditions'] = $conditions;
		}

		// Save elements back to post meta.
		$this->bricks_service->unhook_bricks_meta_filters();
		update_post_meta( $post_id, BricksService::META_KEY, $elements );
		$this->bricks_service->rehook_bricks_meta_filters();

		$result = array(
			'post_id'        => $post_id,
			'element_id'     => $element_id,
			'element_name'   => $elements[ $target_index ]['name'] ?? 'unknown',
			'condition_sets' => count( $conditions ),
			'action'         => empty( $conditions ) ? 'cleared' : 'set',
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Tool: Template dispatcher — routes to list, get, create, update, delete, duplicate.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'list'                => $this->tool_list_templates( $args ),
			'get'                 => $this->tool_get_template_content( $args ),
			'create'              => $this->tool_create_template( $args ),
			'update'              => $this->tool_update_template( $args ),
			'delete'              => $this->tool_delete_template( $args ),
			'duplicate'           => $this->tool_duplicate_template( $args ),
			'get_popup_settings'  => $this->tool_get_popup_settings( $args ),
			'set_popup_settings'  => $this->tool_set_popup_settings( $args ),
			'export'              => $this->tool_export_template( $args ),
			'import'              => $this->tool_import_template( $args ),
			'import_url'          => $this->tool_import_template_url( $args ),
			default               => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, duplicate, get_popup_settings, set_popup_settings, export, import, import_url', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get popup display settings for a popup-type template.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id'.
	 * @return array<string, mixed>|\WP_Error Popup settings data or error.
	 */
	private function tool_get_popup_settings( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for get_popup_settings.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_popup_settings( (int) $template_id );
	}

	/**
	 * Tool: Set popup display settings on a popup-type template.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id' and 'settings'.
	 * @return array<string, mixed>|\WP_Error Updated settings data or error.
	 */
	private function tool_set_popup_settings( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;
		$settings    = $args['settings'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for set_popup_settings.', 'bricks-mcp' )
			);
		}

		if ( null === $settings || ! is_array( $settings ) ) {
			return new \WP_Error(
				'missing_settings',
				__( 'settings (object) is required for set_popup_settings. Use bricks:get_popup_schema to see valid keys.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->set_popup_settings( (int) $template_id, $settings );
	}

	/**
	 * Tool: Export a template as Bricks-compatible JSON.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_id' and optional 'include_classes'.
	 * @return array<string, mixed>|\WP_Error Export data or error.
	 */
	private function tool_export_template( array $args ): array|\WP_Error {
		$template_id = $args['template_id'] ?? null;

		if ( null === $template_id ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required for export.', 'bricks-mcp' )
			);
		}

		$include_classes = ! empty( $args['include_classes'] );

		return $this->bricks_service->export_template( (int) $template_id, $include_classes );
	}

	/**
	 * Tool: Import a template from JSON data.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'template_data'.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	private function tool_import_template( array $args ): array|\WP_Error {
		$template_data = $args['template_data'] ?? null;

		if ( null === $template_data || ! is_array( $template_data ) ) {
			return new \WP_Error(
				'missing_template_data',
				__( 'template_data (object with title and content) is required for import.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_template( $template_data );
	}

	/**
	 * Tool: Import a template from a remote URL.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'url'.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	private function tool_import_template_url( array $args ): array|\WP_Error {
		$url = $args['url'] ?? null;

		if ( empty( $url ) || ! is_string( $url ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'url is required for import_url.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_template_from_url( $url );
	}

	/**
	 * Tool: Template condition dispatcher — routes to get_types, set, resolve.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template_condition( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'get_types' => $this->tool_get_condition_types( $args ),
			'set'       => $this->tool_set_template_conditions( $args ),
			'resolve'   => $this->tool_resolve_templates( $args ),
			default     => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_types, set, resolve', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Template taxonomy dispatcher — routes to list_tags, list_bundles, create_tag, create_bundle, delete_tag, delete_bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_template_taxonomy( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'list_tags'      => $this->tool_list_template_tags( $args ),
			'list_bundles'   => $this->tool_list_template_bundles( $args ),
			'create_tag'     => $this->tool_create_template_tag( $args ),
			'create_bundle'  => $this->tool_create_template_bundle( $args ),
			'delete_tag'     => $this->tool_delete_template_tag( $args ),
			'delete_bundle'  => $this->tool_delete_template_bundle( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list_tags, list_bundles, create_tag, create_bundle, delete_tag, delete_bundle', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Global class dispatcher — routes to list, create, update, delete, apply, remove, batch_create, batch_delete, import_css, list_categories, create_category, delete_category.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		// Param aliasing: category_name -> name for create_category handler.
		if ( 'create_category' === $action && isset( $args['category_name'] ) && ! isset( $args['name'] ) ) {
			$args['name'] = $args['category_name'];
		}

		// Param aliasing: classes -> class_names for batch_delete handler.
		if ( 'batch_delete' === $action && isset( $args['classes'] ) && ! isset( $args['class_names'] ) ) {
			$args['class_names'] = $args['classes'];
		}

		return match ( $action ) {
			'list'            => $this->tool_get_global_classes( $args ),
			'create'          => $this->tool_create_global_class( $args ),
			'update'          => $this->tool_update_global_class( $args ),
			'delete'          => $this->tool_delete_global_class( $args ),
			'apply'           => $this->tool_apply_global_class( $args ),
			'remove'          => $this->tool_remove_global_class( $args ),
			'batch_create'    => $this->tool_batch_create_global_classes( $args ),
			'batch_delete'    => $this->tool_batch_delete_global_classes( $args ),
			'import_css'      => $this->tool_import_classes_from_css( $args ),
			'list_categories' => $this->tool_list_global_class_categories( $args ),
			'create_category' => $this->tool_create_global_class_category( $args ),
			'delete_category' => $this->tool_delete_global_class_category( $args ),
			'export'          => $this->tool_export_global_classes( $args ),
			'import_json'     => $this->tool_import_global_classes_json( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete, apply, remove, batch_create, batch_delete, import_css, list_categories, create_category, delete_category, export, import_json', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Theme style dispatcher — routes to list, get, create, update, delete.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';

		// Map 'name' param to 'label' for theme style handlers that expect 'label'.
		if ( isset( $args['name'] ) && ! isset( $args['label'] ) ) {
			$args['label'] = $args['name'];
		}

		return match ( $action ) {
			'list'   => $this->tool_list_theme_styles( $args ),
			'get'    => $this->tool_get_theme_style( $args ),
			'create' => $this->tool_create_theme_style( $args ),
			'update' => $this->tool_update_theme_style( $args ),
			'delete' => $this->tool_delete_theme_style( $args ),
			default  => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Typography scale dispatcher — routes to list, create, update, delete.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_typography_scale( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		// Map 'scale_id' param to 'category_id' for handlers that expect 'category_id'.
		if ( isset( $args['scale_id'] ) && ! isset( $args['category_id'] ) ) {
			$args['category_id'] = $args['scale_id'];
		}

		return match ( $action ) {
			'list'   => $this->tool_get_typography_scales( $args ),
			'create' => $this->tool_create_typography_scale( $args ),
			'update' => $this->tool_update_typography_scale( $args ),
			'delete' => $this->tool_delete_typography_scale( $args ),
			default  => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Color palette dispatcher — routes to list, create, update, delete, add_color, update_color, delete_color.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_color_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		// Map consolidated 'color' object to flat params for underlying handlers.
		if ( isset( $args['color'] ) && is_array( $args['color'] ) ) {
			foreach ( $args['color'] as $k => $v ) {
				if ( ! isset( $args[ $k ] ) ) {
					$args[ $k ] = $v;
				}
			}
		}

		return match ( $action ) {
			'list'         => $this->tool_list_color_palettes( $args ),
			'create'       => $this->tool_create_color_palette( $args ),
			'update'       => $this->tool_update_color_palette( $args ),
			'delete'       => $this->tool_delete_color_palette( $args ),
			'add_color'    => $this->tool_add_color_to_palette( $args ),
			'update_color' => $this->tool_update_color_in_palette( $args ),
			'delete_color' => $this->tool_delete_color_from_palette( $args ),
			default        => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create, update, delete, add_color, update_color, delete_color', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Global variable dispatcher — routes to list, create_category, update_category, delete_category, create, update, delete, batch_create, batch_delete, search.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_global_variable( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';

		// Map 'category_name' to 'name' for category handlers.
		if ( isset( $args['category_name'] ) && ! isset( $args['name'] ) ) {
			$args['name'] = $args['category_name'];
		}

		// Map 'category' to 'category_id' for create handler.
		if ( isset( $args['category'] ) && ! isset( $args['category_id'] ) ) {
			$args['category_id'] = $args['category'];
		}

		return match ( $action ) {
			'list'            => $this->tool_list_global_variables( $args ),
			'create_category' => $this->tool_create_variable_category( $args ),
			'update_category' => $this->tool_update_variable_category( $args ),
			'delete_category' => $this->tool_delete_variable_category( $args ),
			'create'          => $this->tool_create_global_variable( $args ),
			'update'          => $this->tool_update_global_variable( $args ),
			'delete'          => $this->tool_delete_global_variable( $args ),
			'batch_create'    => $this->tool_batch_create_global_variables( $args ),
			'batch_delete'    => $this->tool_batch_delete_global_variables( $args ),
			'search'          => $this->tool_search_global_variables( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, create_category, update_category, delete_category, create, update, delete, batch_create, batch_delete, search', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Media dispatcher — routes to search_unsplash, sideload, list, set_featured, remove_featured, get_image_settings.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_media( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		// Map 'image_size' to 'size' for get_image_settings handler.
		if ( isset( $args['image_size'] ) && ! isset( $args['size'] ) ) {
			$args['size'] = $args['image_size'];
		}

		return match ( $action ) {
			'search_unsplash'  => $this->tool_search_unsplash( $args ),
			'sideload'         => $this->tool_sideload_image( $args ),
			'list'             => $this->tool_get_media_library( $args ),
			'set_featured'     => $this->tool_set_featured_image( $args ),
			'remove_featured'  => $this->tool_remove_featured_image( $args ),
			'get_image_settings' => $this->tool_get_image_element_settings( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: search_unsplash, sideload, list, set_featured, remove_featured, get_image_settings', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Menu dispatcher — routes to list, get, create, update, delete, set_items, assign, unassign, list_locations.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_menu( array $args ): array|\WP_Error {
		$action = $args['action'] ?? '';


		return match ( $action ) {
			'list'           => $this->tool_list_menus( $args ),
			'get'            => $this->tool_get_menu( $args ),
			'create'         => $this->tool_create_menu( $args ),
			'update'         => $this->tool_update_menu( $args ),
			'delete'         => $this->tool_delete_menu( $args ),
			'set_items'      => $this->tool_set_menu_items( $args ),
			'assign'         => $this->tool_assign_menu( $args ),
			'unassign'       => $this->tool_unassign_menu( $args ),
			'list_locations' => $this->tool_list_menu_locations( $args ),
			default          => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, set_items, assign, unassign, list_locations', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get responsive breakpoints.
	 *
	 * Returns all available breakpoints with composite key format and examples.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Breakpoint data or error.
	 */
	private function tool_get_breakpoints( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$breakpoints = $this->bricks_service->get_breakpoints();

		// Detect custom breakpoints setting.
		$is_custom = false;
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' ) ) {
			$is_custom = ! empty( \Bricks\Database::get_setting( 'customBreakpoints' ) );
		} else {
			$global_settings = get_option( 'bricks_global_settings', array() );
			$is_custom       = ! empty( $global_settings['customBreakpoints'] );
		}

		// Add sort_order and is_custom to each breakpoint.
		$base_key   = 'desktop';
		$base_width = 0;

		foreach ( $breakpoints as $index => &$bp ) {
			$bp['sort_order'] = $index;
			$bp['is_custom']  = $is_custom;

			if ( ! empty( $bp['base'] ) ) {
				$base_key   = $bp['key'];
				$base_width = $bp['width'];
			}
		}
		unset( $bp );

		// Determine approach.
		$max_width = 0;
		$min_width = PHP_INT_MAX;
		foreach ( $breakpoints as $bp ) {
			if ( $bp['width'] > $max_width ) {
				$max_width = $bp['width'];
			}
			if ( $bp['width'] < $min_width ) {
				$min_width = $bp['width'];
			}
		}

		if ( $base_width >= $max_width ) {
			$approach = 'desktop-first';
		} elseif ( $base_width <= $min_width ) {
			$approach = 'mobile-first';
		} else {
			$approach = 'custom';
		}

		return array(
			'breakpoints'                => $breakpoints,
			'base_breakpoint'            => $base_key,
			'approach'                   => $approach,
			'custom_breakpoints_enabled' => $is_custom,
			'composite_key_format'       => '{property}:{breakpoint}:{pseudo}',
			'examples'                   => array(
				'_margin:tablet_portrait' => 'Margin on tablet portrait',
				'_padding:mobile'         => 'Padding on mobile',
				'_background:hover'       => 'Background on hover state',
				'_margin:mobile:hover'    => 'Margin on mobile hover',
			),
		);
	}

	/**
	 * Tool: List Bricks templates.
	 *
	 * Returns template metadata with optional type, status, tag, and bundle filters.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Templates data or error.
	 */
	private function tool_list_templates( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$type      = sanitize_key( $args['type'] ?? '' );
		$status    = sanitize_key( $args['status'] ?? 'publish' );
		$tag       = sanitize_key( $args['tag'] ?? '' );
		$bundle    = sanitize_key( $args['bundle'] ?? '' );
		$templates = $this->bricks_service->get_templates( $type, $status, $tag, $bundle );

		return array(
			'total'     => count( $templates ),
			'templates' => $templates,
		);
	}

	/**
	 * Tool: Create a new Bricks template.
	 *
	 * Creates a bricks_template post with type, optional conditions.
	 * Returns full template data to save an extra get_template_content call.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Template data or error.
	 */
	private function tool_create_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'title is required. Provide a non-empty template title.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['type'] ) ) {
			return new \WP_Error(
				'missing_type',
				__( 'type is required. Provide a template type (e.g., header, footer, content, section, popup).', 'bricks-mcp' )
			);
		}

		$template_id = $this->bricks_service->create_template( $args );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		$template_data = $this->bricks_service->get_template_content_data( $template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $template_id );

		return array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : ( $args['status'] ?? 'publish' ),
				'permalink' => get_permalink( $template_id ),
				'edit_url'  => admin_url( 'post.php?post=' . $template_id . '&action=edit' ),
			)
		);
	}

	/**
	 * Tool: Update Bricks template metadata.
	 *
	 * Updates title, status, type, slug, tags, and bundles.
	 * Does not modify element content. Returns warning if type changed.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated template data or error.
	 */
	private function tool_update_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use list_templates to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$result      = $this->bricks_service->update_template_meta( $template_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$template_data = $this->bricks_service->get_template_content_data( $template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $template_id );
		$data = array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : '',
				'permalink' => get_permalink( $template_id ),
			)
		);

		// Append warning if type was changed.
		if ( is_array( $result ) && isset( $result['warning'] ) ) {
			$data['warning'] = $result['warning'];
		}

		return $data;
	}

	/**
	 * Tool: Move a Bricks template to trash.
	 *
	 * Soft-delete — template can be recovered from WordPress trash.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use list_templates to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$post        = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'template_not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Bricks template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		$trashed = wp_trash_post( $template_id );

		if ( ! $trashed ) {
			return new \WP_Error(
				'trash_failed',
				/* translators: %d: Template ID */
				sprintf( __( 'Failed to trash template %d. Check WordPress error logs for details.', 'bricks-mcp' ), $template_id )
			);
		}

		return array(
			'template_id' => $template_id,
			'title'       => $post->post_title,
			'status'      => 'trash',
			'message'     => __( 'Template moved to trash. It can be recovered from the WordPress trash.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Duplicate a Bricks template.
	 *
	 * Creates a draft copy without conditions to prevent activation conflicts.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error New template data or error.
	 */
	private function tool_duplicate_template( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use list_templates to find valid template IDs.', 'bricks-mcp' )
			);
		}

		$template_id     = (int) $args['template_id'];
		$new_template_id = $this->bricks_service->duplicate_template( $template_id );

		if ( is_wp_error( $new_template_id ) ) {
			return $new_template_id;
		}

		$template_data = $this->bricks_service->get_template_content_data( $new_template_id );

		if ( is_wp_error( $template_data ) ) {
			return $template_data;
		}

		$post = get_post( $new_template_id );

		return array_merge(
			$template_data,
			array(
				'status'    => $post ? $post->post_status : 'draft',
				'permalink' => get_permalink( $new_template_id ),
				'warning'   => __( 'Template conditions were not copied. Use set_template_conditions on the new template to configure where it should apply.', 'bricks-mcp' ),
			)
		);
	}

	/**
	 * Tool: Get full template content.
	 *
	 * Returns complete element data with template context and class names.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Template content or error.
	 */
	private function tool_get_template_content( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error( 'missing_template_id', __( 'template_id is required. Provide a valid Bricks template post ID.', 'bricks-mcp' ) );
		}

		return $this->bricks_service->get_template_content_data( (int) $args['template_id'] );
	}

	/**
	 * Tool: Get global CSS classes.
	 *
	 * Returns all global classes with their full styles in Bricks composite key format.
	 * Supports optional search parameter for partial name match filtering.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Classes data or error.
	 */
	private function tool_get_global_classes( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$search   = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
		$classes  = $this->bricks_service->get_global_classes( $search, $category );

		return array(
			'total'   => count( $classes ),
			'classes' => $classes,
		);
	}

	/**
	 * Tool: Apply a global CSS class to elements.
	 *
	 * Resolves class name to ID, validates all element IDs, and applies the class.
	 * Returns the class CSS properties so AI can confirm the visual outcome.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Application result or error.
	 */
	private function tool_apply_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error( 'missing_class_name', __( 'class_name is required. Provide the name of a global CSS class.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_ids'] ) || ! is_array( $args['element_ids'] ) ) {
			return new \WP_Error( 'missing_element_ids', __( 'element_ids is required. Provide a non-empty array of element IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$class_name  = sanitize_text_field( $args['class_name'] );
		$element_ids = array_map( 'sanitize_text_field', $args['element_ids'] );

		// Resolve class name to ID.
		$class = $this->bricks_service->resolve_class_name( $class_name );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Global class '%s' not found. Use get_global_classes to see available classes.", 'bricks-mcp' ),
					$class_name
				)
			);
		}

		$result = $this->bricks_service->apply_class_to_elements( $post_id, $class['id'], $element_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'class_name' => $class['name'],
			'class_id'   => $class['id'],
			'styles'     => $class['styles'] ?? array(),
			'applied_to' => $element_ids,
			'post_id'    => $post_id,
		);
	}

	/**
	 * Tool: Get all available template condition types.
	 *
	 * Returns condition type metadata to guide AI in writing valid conditions.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Condition types or error.
	 */
	private function tool_get_condition_types( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$types = $this->bricks_service->get_condition_types();

		return array(
			'condition_types'     => $types,
			'scoring_explanation' => 'Higher score wins when multiple templates match. Score 10 (specific IDs) beats score 8 (post type) beats score 2 (entire site).',
			'usage_note'          => 'Pass conditions as objects with "main" key plus any required extra_fields. Example: {"main":"any"} or {"main":"ids","ids":[42,99]}.',
		);
	}

	/**
	 * Tool: Set conditions on a Bricks template.
	 *
	 * Validates condition types and writes the complete set of conditions.
	 * Merges into existing template settings to preserve non-condition keys.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated conditions or error.
	 */
	private function tool_set_template_conditions( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['template_id'] ) ) {
			return new \WP_Error(
				'missing_template_id',
				__( 'template_id is required. Use list_templates to find valid template IDs.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['conditions'] ) || ! is_array( $args['conditions'] ) ) {
			return new \WP_Error(
				'missing_conditions',
				__( 'conditions is required. Pass an array of condition objects (use get_condition_types to discover valid formats). Pass empty array [] to remove all conditions.', 'bricks-mcp' )
			);
		}

		$template_id = (int) $args['template_id'];
		$conditions  = $args['conditions'];

		$result = $this->bricks_service->set_template_conditions( $template_id, $conditions );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Return the updated conditions via format_conditions() for confirmation.
		$settings  = get_post_meta( $template_id, '_bricks_template_settings', true );
		$formatted = $this->bricks_service->format_conditions( $settings );

		return array(
			'template_id' => $template_id,
			'conditions'  => $formatted,
			'count'       => count( $conditions ),
			'message'     => 0 === count( $conditions )
				? __( 'All conditions removed. Template is now inactive.', 'bricks-mcp' )
				: sprintf(
					/* translators: %d: Number of conditions set */
					__( '%d condition(s) set successfully.', 'bricks-mcp' ),
					count( $conditions )
				),
		);
	}

	/**
	 * Tool: Resolve which Bricks templates apply to a given post.
	 *
	 * Evaluates all published template conditions against the post context
	 * and returns the winning template for each slot based on scoring.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Resolution data or error.
	 */
	private function tool_resolve_templates( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Provide the ID of the post to resolve templates for.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];

		return $this->bricks_service->resolve_templates_for_post( $post_id );
	}

	/**
	 * Tool: List all template tags.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Tags data or error.
	 */
	private function tool_list_template_tags( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$tags = $this->bricks_service->get_template_terms( 'template_tag' );

		if ( is_wp_error( $tags ) ) {
			return $tags;
		}

		return array(
			'total' => count( $tags ),
			'tags'  => $tags,
		);
	}

	/**
	 * Tool: List all template bundles.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed>|\WP_Error Bundles data or error.
	 */
	private function tool_list_template_bundles( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$bundles = $this->bricks_service->get_template_terms( 'template_bundle' );

		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		return array(
			'total'   => count( $bundles ),
			'bundles' => $bundles,
		);
	}

	/**
	 * Tool: Create a new template tag.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created term data or error.
	 */
	private function tool_create_template_tag( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty tag name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_template_term( 'template_tag', sanitize_text_field( $args['name'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( $result, array( 'message' => __( 'Tag created. Assign it to templates via update_template\'s tags parameter.', 'bricks-mcp' ) ) );
	}

	/**
	 * Tool: Create a new template bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created term data or error.
	 */
	private function tool_create_template_bundle( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty bundle name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_template_term( 'template_bundle', sanitize_text_field( $args['name'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( $result, array( 'message' => __( "Bundle created. Assign it to templates via update_template's bundles parameter.", 'bricks-mcp' ) ) );
	}

	/**
	 * Tool: Delete a template tag.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template_tag( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['term_id'] ) ) {
			return new \WP_Error(
				'missing_term_id',
				__( 'term_id is required. Use list_template_tags to find valid term IDs.', 'bricks-mcp' )
			);
		}

		$term_id = (int) $args['term_id'];
		$result  = $this->bricks_service->delete_template_term( 'template_tag', $term_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'term_id' => $term_id,
			'message' => __( 'Tag deleted and removed from all templates that had it assigned.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Delete a template bundle.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Confirmation or error.
	 */
	private function tool_delete_template_bundle( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['term_id'] ) ) {
			return new \WP_Error(
				'missing_term_id',
				__( 'term_id is required. Use list_template_bundles to find valid term IDs.', 'bricks-mcp' )
			);
		}

		$term_id = (int) $args['term_id'];
		$result  = $this->bricks_service->delete_template_term( 'template_bundle', $term_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'term_id' => $term_id,
			'message' => __( 'Bundle deleted and removed from all templates that had it assigned.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Remove a global CSS class from elements.
	 *
	 * Resolves class name to ID, validates all element IDs, and removes the class.
	 * Returns the class CSS properties for confirmation.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal result or error.
	 */
	private function tool_remove_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error( 'missing_class_name', __( 'class_name is required. Provide the name of a global CSS class.', 'bricks-mcp' ) );
		}

		if ( empty( $args['element_ids'] ) || ! is_array( $args['element_ids'] ) ) {
			return new \WP_Error( 'missing_element_ids', __( 'element_ids is required. Provide a non-empty array of element IDs.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$class_name  = sanitize_text_field( $args['class_name'] );
		$element_ids = array_map( 'sanitize_text_field', $args['element_ids'] );

		// Resolve class name to ID.
		$class = $this->bricks_service->resolve_class_name( $class_name );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Global class '%s' not found. Use get_global_classes to see available classes.", 'bricks-mcp' ),
					$class_name
				)
			);
		}

		$result = $this->bricks_service->remove_class_from_elements( $post_id, $class['id'], $element_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'class_name'   => $class['name'],
			'class_id'     => $class['id'],
			'styles'       => $class['styles'] ?? array(),
			'removed_from' => $element_ids,
			'post_id'      => $post_id,
		);
	}

	/**
	 * Tool: Create a global CSS class.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created class data or error.
	 */
	private function tool_create_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a non-empty class name (e.g., btn-primary).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_class( $args );
	}

	/**
	 * Tool: Update a global CSS class by name.
	 *
	 * Resolves class name to ID, then delegates to BricksService.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated class data or error.
	 */
	private function tool_update_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error(
				'missing_class_name',
				__( 'class_name is required. Use get_global_classes to find class names.', 'bricks-mcp' )
			);
		}

		$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $args['class_name'] ) );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found. Use get_global_classes to list available classes.", 'bricks-mcp' ),
					$args['class_name']
				)
			);
		}

		return $this->bricks_service->update_global_class( $class['id'], $args );
	}

	/**
	 * Tool: Soft-delete a global CSS class.
	 *
	 * Resolves class name to ID, finds references, then trashes the class.
	 * Returns deletion confirmation with reference warnings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_class( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['class_name'] ) ) {
			return new \WP_Error(
				'missing_class_name',
				__( 'class_name is required. Use get_global_classes to find class names.', 'bricks-mcp' )
			);
		}

		$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $args['class_name'] ) );

		if ( null === $class ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found. Use get_global_classes to list available classes.", 'bricks-mcp' ),
					$args['class_name']
				)
			);
		}

		$refs   = $this->bricks_service->find_class_references( $class['id'] );
		$result = $this->bricks_service->trash_global_class( $class['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'deleted'    => $class['name'],
			'references' => $refs['references'],
			'truncated'  => $refs['truncated'],
			'note'       => __( 'Class moved to trash. References above still use this class ID — consider using remove_global_class to clean them up.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Batch create multiple global CSS classes.
	 *
	 * Validates the classes array and delegates to BricksService.
	 * Returns partial results — successfully created classes and errors for failed ones.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch creation results or error.
	 */
	private function tool_batch_create_global_classes( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['classes'] ) || ! is_array( $args['classes'] ) ) {
			return new \WP_Error(
				'missing_classes',
				__( 'classes is required and must be a non-empty array of class definitions. Each object needs at least a name property.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->batch_create_global_classes( $args['classes'] );
	}

	/**
	 * Tool: Batch delete multiple global CSS classes.
	 *
	 * Resolves class names to IDs, then delegates to BricksService batch trash.
	 * Returns combined results with reference warnings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch deletion results or error.
	 */
	private function tool_batch_delete_global_classes( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['class_names'] ) || ! is_array( $args['class_names'] ) ) {
			return new \WP_Error(
				'missing_class_names',
				__( 'class_names is required and must be a non-empty array of class name strings. Use get_global_classes to find names.', 'bricks-mcp' )
			);
		}

		$class_ids         = array();
		$resolution_errors = array();

		foreach ( $args['class_names'] as $name ) {
			$class = $this->bricks_service->resolve_class_name( sanitize_text_field( $name ) );

			if ( null === $class ) {
				$resolution_errors[ $name ] = sprintf(
					/* translators: %s: Class name */
					__( "Class '%s' not found.", 'bricks-mcp' ),
					$name
				);
			} else {
				$class_ids[] = $class['id'];
			}
		}

		$result = $this->bricks_service->batch_trash_global_classes( $class_ids );

		// Merge resolution errors with trash errors.
		$result['errors'] = array_merge( $resolution_errors, $result['errors'] );

		$result['note'] = __( 'Classes moved to trash. Check references above — consider using remove_global_class to clean them up.', 'bricks-mcp' );

		return $result;
	}

	/**
	 * Tool: List all global CSS class categories.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Categories list or error.
	 */
	private function tool_list_global_class_categories( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$categories = $this->bricks_service->get_global_class_categories();

		return array(
			'total'      => count( $categories ),
			'categories' => $categories,
		);
	}

	/**
	 * Tool: Create a new global CSS class category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created category or error.
	 */
	private function tool_create_global_class_category( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a category name (e.g., Buttons, Typography, Layout).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_class_category( sanitize_text_field( $args['name'] ) );
	}

	/**
	 * Tool: Delete a global CSS class category.
	 *
	 * Classes in the deleted category are moved to uncategorized.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_class_category( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_class_categories to find category IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->delete_global_class_category( sanitize_text_field( $args['category_id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'deleted' => true,
			'note'    => __( 'Classes in this category have been moved to uncategorized.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Import CSS class definitions from a raw CSS string.
	 *
	 * Parses CSS selectors, maps media queries and pseudo-selectors to Bricks
	 * breakpoint/state variants, and creates global classes via batch create.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Import results or error.
	 */
	private function tool_import_classes_from_css( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['css'] ) || ! is_string( $args['css'] ) ) {
			return new \WP_Error(
				'missing_css',
				__( 'css is required and must be a non-empty CSS string containing class selectors.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_classes_from_css( $args['css'] );
	}

	/**
	 * Tool: Export global classes as JSON.
	 *
	 * @param array<string, mixed> $args Tool arguments with optional 'category'.
	 * @return array<string, mixed> Export data with classes, categories, and count.
	 */
	private function tool_export_global_classes( array $args ): array {
		$category = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';

		return $this->bricks_service->export_global_classes( $category );
	}

	/**
	 * Tool: Import global classes from JSON data.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'classes_data'.
	 * @return array<string, mixed>|\WP_Error Import summary or error.
	 */
	private function tool_import_global_classes_json( array $args ): array|\WP_Error {
		$classes_data = $args['classes_data'] ?? null;

		if ( null === $classes_data || ! is_array( $classes_data ) ) {
			return new \WP_Error(
				'missing_classes_data',
				__( 'classes_data is required for import_json. Provide an object with "classes" array or a raw array of class objects.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->import_global_classes_from_json( $classes_data );
	}

	/**
	 * Tool: Font dispatcher — routes to get_status, get_adobe_fonts, update_settings.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_font( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'get_status'      => $this->tool_get_font_status( $args ),
			'get_adobe_fonts' => $this->tool_get_adobe_fonts( $args ),
			'update_settings' => $this->tool_update_font_settings( $args ),
			default           => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_status, get_adobe_fonts, update_settings', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get font configuration status overview.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Font status data.
	 */
	private function tool_get_font_status( array $args ): array {
		return $this->bricks_service->get_font_status();
	}

	/**
	 * Tool: Get cached Adobe Fonts.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Adobe Fonts data.
	 */
	private function tool_get_adobe_fonts( array $args ): array {
		return $this->bricks_service->get_adobe_fonts();
	}

	/**
	 * Tool: Update font-related settings.
	 *
	 * @param array<string, mixed> $args Tool arguments with font setting fields.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_font_settings( array $args ): array|\WP_Error {
		$fields = array();

		if ( array_key_exists( 'disable_google_fonts', $args ) ) {
			$fields['disableGoogleFonts'] = $args['disable_google_fonts'];
		}

		if ( array_key_exists( 'webfont_loading', $args ) ) {
			$fields['webfontLoading'] = $args['webfont_loading'];
		}

		if ( array_key_exists( 'custom_fonts_preload', $args ) ) {
			$fields['customFontsPreload'] = $args['custom_fonts_preload'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'No font settings provided. Use disable_google_fonts (boolean), webfont_loading (string), or custom_fonts_preload (boolean).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_font_settings( $fields );
	}

	/**
	 * Tool: Code dispatcher — routes to get_page_css, set_page_css, get_page_scripts, set_page_scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_code( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		if ( 'set_page_scripts' === $action ) {
			if ( ! $this->bricks_service->is_dangerous_actions_enabled() ) {
				return new \WP_Error(
					'dangerous_actions_disabled',
					__( 'Custom scripts require the Dangerous Actions toggle to be enabled in Settings > Bricks MCP.', 'bricks-mcp' )
				);
			}
		}

		return match ( $action ) {
			'get_page_css'     => $this->tool_get_page_css( $args ),
			'set_page_css'     => $this->tool_set_page_css( $args ),
			'get_page_scripts' => $this->tool_get_page_scripts( $args ),
			'set_page_scripts' => $this->tool_set_page_scripts( $args ),
			default            => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: get_page_css, set_page_css, get_page_scripts, set_page_scripts', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: Get page custom CSS and scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Code data or error.
	 */
	private function tool_get_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_css.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_code( (int) $post_id );
	}

	/**
	 * Tool: Set page custom CSS.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and 'css'.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_css( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_css.', 'bricks-mcp' )
			);
		}

		if ( ! array_key_exists( 'css', $args ) ) {
			return new \WP_Error(
				'missing_css',
				__( 'css is required for set_page_css. Send empty string to remove custom CSS.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_css( (int) $post_id, (string) $args['css'] );
	}

	/**
	 * Tool: Get page custom scripts only.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id'.
	 * @return array<string, mixed>|\WP_Error Script data or error.
	 */
	private function tool_get_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for get_page_scripts.', 'bricks-mcp' )
			);
		}

		$code = $this->bricks_service->get_page_code( (int) $post_id );

		if ( is_wp_error( $code ) ) {
			return $code;
		}

		return array(
			'post_id'                 => $code['post_id'],
			'customScriptsHeader'     => $code['customScriptsHeader'],
			'customScriptsBodyHeader' => $code['customScriptsBodyHeader'],
			'customScriptsBodyFooter' => $code['customScriptsBodyFooter'],
			'has_scripts'             => $code['has_scripts'],
		);
	}

	/**
	 * Tool: Set page custom scripts.
	 *
	 * @param array<string, mixed> $args Tool arguments with 'post_id' and script placement params.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_set_page_scripts( array $args ): array|\WP_Error {
		$post_id = $args['post_id'] ?? null;

		if ( null === $post_id ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required for set_page_scripts.', 'bricks-mcp' )
			);
		}

		$scripts = array();

		if ( array_key_exists( 'header', $args ) ) {
			$scripts['customScriptsHeader'] = (string) $args['header'];
		}

		if ( array_key_exists( 'body_header', $args ) ) {
			$scripts['customScriptsBodyHeader'] = (string) $args['body_header'];
		}

		if ( array_key_exists( 'body_footer', $args ) ) {
			$scripts['customScriptsBodyFooter'] = (string) $args['body_footer'];
		}

		if ( empty( $scripts ) ) {
			return new \WP_Error(
				'no_scripts',
				__( 'At least one script parameter is required: header, body_header, or body_footer.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_scripts( (int) $post_id, $scripts );
	}

	/**
	 * Tool: Get builder guide.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array{guide: string}|array{section: string, content: string} Guide content.
	 */
	public function tool_get_builder_guide( array $args ): array {
		$guide_path = BRICKS_MCP_PLUGIN_DIR . 'docs/BUILDER_GUIDE.md';

		if ( ! file_exists( $guide_path ) ) {
			return array( 'guide' => 'Builder guide not found. Use get_element_schemas to discover available elements.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$content = file_get_contents( $guide_path );

		if ( false === $content ) {
			return array( 'guide' => 'Failed to read builder guide.' );
		}

		$section = $args['section'] ?? 'all';

		if ( 'all' === $section ) {
			return array( 'guide' => $content );
		}

		$section_map = array(
			'settings'           => '## Element Settings Reference',
			'animations'         => '## Animations',
			'interactions'       => '## Animations',
			'dynamic_data'       => '## Dynamic Data & Query Loops',
			'forms'              => '## Forms',
			'components'         => '## Components',
			'popups'             => '## Popups',
			'element_conditions' => '## Element Conditions & Visibility',
			'woocommerce'        => '## WooCommerce',
			'seo'                => '## SEO Optimization',
			'custom_code'        => '## Custom Code',
			'fonts'              => '## Font Management',
			'import_export'      => '## Import & Export',
			'workflows'          => '## Common Workflows',
			'gotchas'                   => '## Key Gotchas',
			'connection_troubleshooting' => '## Connection Troubleshooting',
		);

		if ( ! isset( $section_map[ $section ] ) ) {
			return array( 'guide' => $content );
		}

		$heading = $section_map[ $section ];
		$pos     = strpos( $content, $heading );

		if ( false === $pos ) {
			return array( 'guide' => $content );
		}

		// Extract from heading to next ## heading or end of file.
		$rest      = substr( $content, $pos );
		$next_h2   = strpos( $rest, "\n## ", strlen( $heading ) );
		$extracted = false !== $next_h2 ? substr( $rest, 0, $next_h2 ) : $rest;

		return array(
			'section' => $section,
			'content' => trim( $extracted ),
		);
	}

	/**
	 * Tool: List all global theme styles.
	 *
	 * Returns all theme styles with labels, conditions, active status,
	 * settings groups, and a condition_types reference for building conditions.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Theme styles list or error.
	 */
	private function tool_list_theme_styles( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$result = $this->bricks_service->get_theme_styles();

		$condition_types = array(
			'any'         => array(
				'label'        => 'Entire website',
				'score'        => 2,
				'extra_fields' => array(),
			),
			'frontpage'   => array(
				'label'        => 'Front page',
				'score'        => 9,
				'extra_fields' => array(),
			),
			'postType'    => array(
				'label'        => 'Post type',
				'score'        => 7,
				'extra_fields' => array( 'postType' => 'array of post type slugs' ),
			),
			'archiveType' => array(
				'label'        => 'Archive',
				'score'        => '3-8',
				'extra_fields' => array( 'archiveType' => 'any|author|date|term' ),
			),
			'terms'       => array(
				'label'        => 'Terms',
				'score'        => 8,
				'extra_fields' => array( 'terms' => 'array of taxonomy::term_id strings' ),
			),
			'ids'         => array(
				'label'        => 'Individual posts',
				'score'        => 10,
				'extra_fields' => array(
					'ids'                => 'array of post IDs',
					'idsIncludeChildren' => 'boolean',
				),
			),
			'search'      => array(
				'label'        => 'Search results',
				'score'        => 0,
				'extra_fields' => array(),
			),
			'error'       => array(
				'label'        => '404 error page',
				'score'        => 0,
				'extra_fields' => array(),
			),
		);

		return array(
			'styles'          => $result,
			'count'           => count( $result ),
			'condition_types' => $condition_types,
		);
	}

	/**
	 * Tool: Get a single theme style by ID.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Theme style data or error.
	 */
	private function tool_get_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_theme_style( $args['style_id'] );
	}

	/**
	 * Tool: Create a new theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created style or error.
	 */
	private function tool_create_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['label'] ) ) {
			return new \WP_Error(
				'missing_label',
				__( 'label is required. Provide a human-readable name for the theme style.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_theme_style(
			$args['label'],
			$args['settings'] ?? array(),
			$args['conditions'] ?? array()
		);
	}

	/**
	 * Tool: Update an existing theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->update_theme_style(
			$args['style_id'],
			$args['label'] ?? null,
			$args['settings'] ?? null,
			isset( $args['conditions'] ) ? $args['conditions'] : null,
			! empty( $args['replace_section'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add warning if modifying the site-wide active style.
		if ( ! empty( $result['is_sitewide_active'] ) ) {
			$result['warning'] = __( 'This style applies to the entire website. Changes are live immediately.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Delete or deactivate a theme style.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_theme_style( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['style_id'] ) ) {
			return new \WP_Error(
				'missing_style_id',
				__( 'style_id is required. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->delete_theme_style(
			$args['style_id'],
			! empty( $args['hard_delete'] )
		);
	}

	/**
	 * Tool: Get all typography scales.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Scales list or error.
	 */
	private function tool_get_typography_scales( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$result = $this->bricks_service->get_typography_scales();

		return array(
			'scales' => $result,
			'count'  => count( $result ),
			'note'   => __( 'Use var(--prefix-step) syntax in typography settings. Scales generate both CSS variables and utility classes.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Create a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created scale or error.
	 */
	private function tool_create_typography_scale( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a scale name (e.g., "Typography Scale").', 'bricks-mcp' )
			);
		}

		if ( empty( $args['prefix'] ) ) {
			return new \WP_Error(
				'missing_prefix',
				__( 'prefix is required. Provide a CSS variable prefix starting with -- (e.g., "--text-").', 'bricks-mcp' )
			);
		}

		if ( empty( $args['steps'] ) || ! is_array( $args['steps'] ) ) {
			return new \WP_Error(
				'missing_steps',
				__( 'steps is required. Provide an array of {name, value} objects (e.g., [{"name": "sm", "value": "0.875rem"}]).', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_typography_scale(
			$args['name'],
			$args['steps'],
			$args['prefix'],
			$args['utility_classes'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated — Bricks version may not support style manager. Variables are saved but may not appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Update a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated scale or error.
	 */
	private function tool_update_typography_scale( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->update_typography_scale(
			$args['category_id'],
			$args['name'] ?? null,
			$args['steps'] ?? null,
			$args['prefix'] ?? null,
			$args['utility_classes'] ?? null
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated — Bricks version may not support style manager. Variables are saved but may not appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Delete a typography scale.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_typography_scale( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->delete_typography_scale( $args['category_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated — Bricks version may not support style manager. Variables are saved but removed scale will still appear in frontend CSS until Bricks regenerates styles.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: List all color palettes.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Palettes list or error.
	 */
	private function tool_list_color_palettes( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$palettes = $this->bricks_service->get_color_palettes();

		return array(
			'palettes' => $palettes,
			'count'    => count( $palettes ),
		);
	}

	/**
	 * Tool: Create a new color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created palette or error.
	 */
	private function tool_create_color_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a palette name.', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->create_color_palette(
			$args['name'],
			$args['colors'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated — Bricks version may not support style manager.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Rename a color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated palette or error.
	 */
	private function tool_update_color_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_color_palette( $args['palette_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a color palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_color_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->delete_color_palette( $args['palette_id'] );
	}

	/**
	 * Tool: Add a color to a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created color or error.
	 */
	private function tool_add_color_to_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a color name (e.g., "Primary Blue").', 'bricks-mcp' )
			);
		}

		$light_value = $args['light'] ?? $args['hex'] ?? '';

		if ( empty( $light_value ) ) {
			return new \WP_Error(
				'missing_light',
				__( 'light (or hex) is required. Provide a hex color value (e.g., "#3498db").', 'bricks-mcp' )
			);
		}

		$result = $this->bricks_service->add_color_to_palette(
			$args['palette_id'],
			$light_value,
			$args['name'],
			$args['raw'] ?? '',
			$args['parent_color_id'] ?? $args['parent'] ?? '',
			$args['utility_classes'] ?? array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['css_regenerated'] ) ) {
			$result['note'] = __( 'CSS file not regenerated — Bricks version may not support style manager.', 'bricks-mcp' );
		}

		return $result;
	}

	/**
	 * Tool: Update a color in a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated color or error.
	 */
	private function tool_update_color_in_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['color_id'] ) ) {
			return new \WP_Error(
				'missing_color_id',
				__( 'color_id is required. Use list_color_palettes to discover available color IDs.', 'bricks-mcp' )
			);
		}

		// Build fields array mapping tool params to BricksService field names.
		$fields = array();

		$light_value = $args['light'] ?? $args['hex'] ?? null;
		if ( null !== $light_value ) {
			$fields['light'] = $light_value;
		}

		if ( isset( $args['name'] ) ) {
			$fields['name'] = $args['name'];
		}

		if ( isset( $args['raw'] ) ) {
			$fields['raw'] = $args['raw'];
		}

		if ( array_key_exists( 'parent_color_id', $args ) ) {
			$fields['parent'] = $args['parent_color_id'];
		}

		if ( array_key_exists( 'utility_classes', $args ) ) {
			$fields['utilityClasses'] = $args['utility_classes'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'At least one field to update is required (name, light (or hex), raw, parent_color_id, or utility_classes).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_color_in_palette(
			$args['palette_id'],
			$args['color_id'],
			$fields
		);
	}

	/**
	 * Tool: Delete a color from a palette.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_color_from_palette( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['palette_id'] ) ) {
			return new \WP_Error(
				'missing_palette_id',
				__( 'palette_id is required. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['color_id'] ) ) {
			return new \WP_Error(
				'missing_color_id',
				__( 'color_id is required. Use list_color_palettes to discover available color IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->delete_color_from_palette(
			$args['palette_id'],
			$args['color_id']
		);
	}

	/**
	 * Tool: List all global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Variables list or error.
	 */
	private function tool_list_global_variables( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->bricks_service->get_global_variables();
	}

	/**
	 * Tool: Create a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created category or error.
	 */
	private function tool_create_variable_category( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a category name (e.g., "Spacing").', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_variable_category( $args['name'] );
	}

	/**
	 * Tool: Update a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated category or error.
	 */
	private function tool_update_variable_category( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_variables to discover available category IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_variable_category( $args['category_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a variable category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_variable_category( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['category_id'] ) ) {
			return new \WP_Error(
				'missing_category_id',
				__( 'category_id is required. Use list_global_variables to discover available category IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->delete_variable_category( $args['category_id'] );
	}

	/**
	 * Tool: Create a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created variable or error.
	 */
	private function tool_create_global_variable( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name is required. Provide a CSS property name (e.g., "spacing-md").', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['value'] ) || '' === $args['value'] ) {
			return new \WP_Error(
				'missing_value',
				__( 'value is required. Provide a CSS value (e.g., "1rem").', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->create_global_variable(
			$args['name'],
			$args['value'],
			$args['category_id'] ?? ''
		);
	}

	/**
	 * Tool: Update a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated variable or error.
	 */
	private function tool_update_global_variable( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['variable_id'] ) ) {
			return new \WP_Error(
				'missing_variable_id',
				__( 'variable_id is required. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' )
			);
		}

		$fields = array();

		if ( isset( $args['name'] ) ) {
			$fields['name'] = $args['name'];
		}

		if ( isset( $args['value'] ) ) {
			$fields['value'] = $args['value'];
		}

		if ( array_key_exists( 'category_id', $args ) ) {
			$fields['category'] = $args['category_id'];
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'no_fields',
				__( 'At least one field to update is required (name, value, or category_id).', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_global_variable( $args['variable_id'], $fields );
	}

	/**
	 * Tool: Delete a global variable.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_global_variable( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['variable_id'] ) ) {
			return new \WP_Error(
				'missing_variable_id',
				__( 'variable_id is required. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->delete_global_variable( $args['variable_id'] );
	}

	/**
	 * Tool: Batch-create global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Batch result or error.
	 */
	private function tool_batch_create_global_variables( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['variables'] ) || ! is_array( $args['variables'] ) ) {
			return new \WP_Error(
				'missing_variables',
				__( 'variables is required. Provide an array of {name, value} objects.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->batch_create_global_variables(
			$args['variables'],
			$args['category_id'] ?? ''
		);
	}

	/**
	 * Handler: Batch delete global variables.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_batch_delete_global_variables( array $args ): array|\WP_Error {
		$variable_ids = $args['variable_ids'] ?? [];

		if ( empty( $variable_ids ) || ! is_array( $variable_ids ) ) {
			return new \WP_Error( 'missing_variable_ids', __( 'variable_ids array is required with at least one variable ID string.', 'bricks-mcp' ) );
		}

		return $this->bricks_service->batch_delete_global_variables( $variable_ids );
	}

	/**
	 * Handler: Search global variables by name/value/category.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed> Search results.
	 */
	private function tool_search_global_variables( array $args ): array {
		$name        = $args['query'] ?? '';
		$value       = $args['value_query'] ?? '';
		$category_id = $args['category_id'] ?? '';

		return $this->bricks_service->search_global_variables( $name, $value, $category_id );
	}

	/**
	 * Tool: Get Bricks global settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Settings data or error.
	 */
	private function tool_get_bricks_settings( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$category = sanitize_key( $args['category'] ?? '' );

		return $this->bricks_service->get_bricks_settings( $category );
	}

	/**
	 * Tool: Get page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Page settings or error.
	 */
	private function tool_get_page_settings( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_page_settings( (int) $args['post_id'] );
	}

	/**
	 * Tool: Update page-level Bricks settings.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_page_settings( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use list_pages to find valid post IDs.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['settings'] ) || ! is_array( $args['settings'] ) ) {
			return new \WP_Error(
				'missing_settings',
				__( 'settings object is required. Provide key-value pairs of page settings to update.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['settings'] ) ) {
			return new \WP_Error(
				'empty_settings',
				__( 'settings object must contain at least one key-value pair.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_page_settings( (int) $args['post_id'], $args['settings'] );
	}

	/**
	 * Tool: Get SEO data from active SEO plugin.
	 *
	 * Returns normalized SEO fields from whichever SEO plugin is active
	 * (Yoast, Rank Math, SEOPress, Slim SEO, or Bricks native) with inline audit.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error SEO data with audit or error.
	 */
	private function tool_get_page_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->get_seo_data( (int) $args['post_id'] );
	}

	/**
	 * Tool: Update SEO fields via active SEO plugin.
	 *
	 * Writes normalized SEO field names to the correct plugin meta keys.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	private function tool_update_page_seo( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		// Extract all SEO fields from args.
		$seo_field_names = array(
			'title', 'description', 'robots_noindex', 'robots_nofollow', 'canonical',
			'og_title', 'og_description', 'og_image',
			'twitter_title', 'twitter_description', 'twitter_image',
			'focus_keyword',
		);

		$seo_fields = array();
		foreach ( $seo_field_names as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$seo_fields[ $field ] = $args[ $field ];
			}
		}

		if ( empty( $seo_fields ) ) {
			return new \WP_Error(
				'missing_seo_fields',
				__( 'At least one SEO field must be provided. Accepted: title, description, robots_noindex, robots_nofollow, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, focus_keyword.', 'bricks-mcp' )
			);
		}

		return $this->bricks_service->update_seo_data( (int) $args['post_id'], $seo_fields );
	}

	/**
	 * Tool: Search Unsplash photos.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Search results or error.
	 */
	private function tool_search_unsplash( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['query'] ) || ! is_string( $args['query'] ) ) {
			return new \WP_Error(
				'missing_query',
				__( 'query parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->media_service->search_photos( $args['query'] );
	}

	/**
	 * Tool: Sideload image from URL into WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Sideload result or error.
	 */
	private function tool_sideload_image( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['url'] ) || ! is_string( $args['url'] ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'url parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		$url               = $args['url'];
		$alt_text          = isset( $args['alt_text'] ) && is_string( $args['alt_text'] ) ? $args['alt_text'] : '';
		$title             = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';
		$unsplash_id       = isset( $args['unsplash_id'] ) && is_string( $args['unsplash_id'] ) ? $args['unsplash_id'] : null;
		$download_location = isset( $args['download_location'] ) && is_string( $args['download_location'] ) ? $args['download_location'] : null;

		return $this->media_service->sideload_from_url( $url, $alt_text, $title, $unsplash_id, $download_location );
	}

	/**
	 * Tool: Browse the WordPress media library.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Media library results or error.
	 */
	private function tool_get_media_library( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$search    = isset( $args['search'] ) && is_string( $args['search'] ) ? $args['search'] : '';
		$mime_type = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? $args['mime_type'] : 'image';
		$per_page  = isset( $args['per_page'] ) && is_int( $args['per_page'] ) ? $args['per_page'] : 20;
		$page      = isset( $args['page'] ) && is_int( $args['page'] ) ? $args['page'] : 1;

		return $this->media_service->get_media_library_items( $search, $mime_type, $per_page, $page );
	}

	/**
	 * Tool: Set or replace the featured image for a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Featured image result or error.
	 */
	private function tool_set_featured_image( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id       = (int) $args['post_id'];
		$attachment_id = (int) $args['attachment_id'];

		// Validate post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Validate post type supports thumbnails.
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return new \WP_Error(
				'thumbnails_not_supported',
				/* translators: %s: post type name */
				sprintf( __( 'Post type "%s" does not support featured images (thumbnails).', 'bricks-mcp' ), $post->post_type )
			);
		}

		// Validate attachment exists and is an attachment.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found in media library. Use sideload_image to upload an image first, or get_media_library to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		// Get old thumbnail before replacing.
		$old_thumbnail_id = get_post_thumbnail_id( $post_id );

		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( ! $result ) {
			return new \WP_Error(
				'set_thumbnail_failed',
				__( 'Failed to set the featured image. The post or attachment may be invalid.', 'bricks-mcp' )
			);
		}

		$response = array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ) ? wp_get_attachment_url( $attachment_id ) : '',
			'title'         => get_the_title( $post_id ),
		);

		if ( $old_thumbnail_id && (int) $old_thumbnail_id !== $attachment_id ) {
			$response['replaced_attachment_id'] = (int) $old_thumbnail_id;
			$response['warning']                = sprintf(
				/* translators: %d: old attachment ID */
				__( 'Previous featured image (attachment ID %d) was replaced.', 'bricks-mcp' ),
				(int) $old_thumbnail_id
			);
		}

		return $response;
	}

	/**
	 * Tool: Remove the featured image from a post.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Removal result or error.
	 */
	private function tool_remove_featured_image( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) || ! is_numeric( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		$post_id = (int) $args['post_id'];

		// Validate post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'Post %d not found. Use list_pages to find valid post IDs.', 'bricks-mcp' ), $post_id )
			);
		}

		// Check if post has a featured image.
		$current_thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $current_thumbnail_id ) {
			return array(
				'post_id' => $post_id,
				'removed' => false,
				'message' => __( 'Post has no featured image.', 'bricks-mcp' ),
			);
		}

		delete_post_thumbnail( $post_id );

		return array(
			'post_id'               => $post_id,
			'removed'               => true,
			'removed_attachment_id' => (int) $current_thumbnail_id,
		);
	}

	/**
	 * Tool: Get Bricks image element settings for an attachment.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Image settings or error.
	 */
	private function tool_get_image_element_settings( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['attachment_id'] ) || ! is_numeric( $args['attachment_id'] ) ) {
			return new \WP_Error(
				'missing_attachment_id',
				__( 'attachment_id parameter is required and must be an integer.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['target'] ) || ! is_string( $args['target'] ) ) {
			return new \WP_Error(
				'missing_target',
				__( 'target parameter is required. Use "image", "background", or "gallery".', 'bricks-mcp' )
			);
		}

		$attachment_id = (int) $args['attachment_id'];
		$target        = $args['target'];
		$size          = isset( $args['size'] ) && is_string( $args['size'] ) ? $args['size'] : 'full';

		// Validate attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found in media library. Use sideload_image to upload an image first, or get_media_library to find existing images.', 'bricks-mcp' ), $attachment_id )
			);
		}

		// Validate target.
		$valid_targets = array( 'image', 'background', 'gallery' );
		if ( ! in_array( $target, $valid_targets, true ) ) {
			return new \WP_Error(
				'invalid_target',
				/* translators: %s: provided target value */
				sprintf( __( 'Invalid target "%s". Use "image", "background", or "gallery".', 'bricks-mcp' ), $target )
			);
		}

		$image_obj = $this->media_service->build_bricks_image_object( $attachment_id, $size );
		if ( is_wp_error( $image_obj ) ) {
			return $image_obj;
		}

		$response = array(
			'attachment_id'       => $attachment_id,
			'bricks_image_object' => $image_obj,
		);

		switch ( $target ) {
			case 'image':
				$response['target']       = 'image';
				$response['usage']        = __( 'Set as settings.image on an Image element', 'bricks-mcp' );
				$response['settings_key'] = 'image';
				$response['value']        = $image_obj;
				break;

			case 'background':
				$response['target']       = 'background';
				$response['usage']        = __( 'Set as settings._background.image on a section or container', 'bricks-mcp' );
				$response['settings_key'] = '_background';
				$response['value']        = array( 'image' => $image_obj );
				$response['note']         = __( "You can add 'position': 'center center', 'size': 'cover', 'repeat': 'no-repeat' alongside the image key inside _background.", 'bricks-mcp' );
				break;

			case 'gallery':
				$response['target']       = 'gallery';
				$response['usage']        = __( 'Add to settings.images array on a Gallery element', 'bricks-mcp' );
				$response['settings_key'] = 'images';
				$response['value']        = $image_obj;
				$response['note']         = __( 'This is one item. For a gallery, collect multiple items into an array and set as settings.images.', 'bricks-mcp' );
				break;
		}

		return $response;
	}

	/**
	 * Tool: Create a new navigation menu.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Created menu data or error.
	 */
	private function tool_create_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['name'] ) || ! is_string( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->create_menu( $args['name'] );
	}

	/**
	 * Tool: Update a navigation menu's name.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Updated menu data or error.
	 */
	private function tool_update_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['name'] ) || ! is_string( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'name parameter is required and must be a non-empty string.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->update_menu( (int) $args['menu_id'], $args['name'] );
	}

	/**
	 * Tool: Delete a navigation menu.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	private function tool_delete_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->delete_menu( (int) $args['menu_id'] );
	}

	/**
	 * Tool: Get a navigation menu with its items as a nested tree.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Menu data or error.
	 */
	private function tool_get_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->get_menu( (int) $args['menu_id'] );
	}

	/**
	 * Tool: List all navigation menus.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed> List of menus with counts and locations.
	 */
	private function tool_list_menus( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return array(
				'error' => $bricks_error->get_error_message(),
				'menus' => array(),
				'total' => 0,
			);
		}

		return $this->menu_service->list_menus();
	}

	/**
	 * Tool: Replace all items in a navigation menu with a new nested tree.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	private function tool_set_menu_items( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( ! isset( $args['items'] ) || ! is_array( $args['items'] ) ) {
			return new \WP_Error(
				'missing_items',
				__( 'items parameter is required and must be an array.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->set_menu_items( (int) $args['menu_id'], $args['items'] );
	}

	/**
	 * Tool: Assign a navigation menu to a theme menu location.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Assignment result or error.
	 */
	private function tool_assign_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['menu_id'] ) || ! is_numeric( $args['menu_id'] ) || (int) $args['menu_id'] <= 0 ) {
			return new \WP_Error(
				'missing_menu_id',
				__( 'menu_id parameter is required and must be a positive integer. Use list_menus to find valid menu IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['location'] ) || ! is_string( $args['location'] ) ) {
			return new \WP_Error(
				'missing_location',
				__( 'location parameter is required and must be a non-empty string. Use list_menu_locations to see available slugs.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->assign_menu( (int) $args['menu_id'], $args['location'] );
	}

	/**
	 * Tool: Remove a menu from a theme location without deleting it.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Unassignment result or error.
	 */
	private function tool_unassign_menu( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['location'] ) || ! is_string( $args['location'] ) ) {
			return new \WP_Error(
				'missing_location',
				__( 'location parameter is required and must be a non-empty string. Use list_menu_locations to see current assignments.', 'bricks-mcp' )
			);
		}

		return $this->menu_service->unassign_menu( $args['location'] );
	}

	/**
	 * Tool: List all registered theme menu locations with current assignments.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused for this tool).
	 * @return array<string, mixed> Locations list with assignment data.
	 */
	private function tool_list_menu_locations( array $args ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		return $this->menu_service->list_locations();
	}

	/**
	 * Tool: Component dispatcher — routes to list, get, create, update, delete, instantiate, update_properties, fill_slot.
	 *
	 * @param array<string, mixed> $args Tool arguments including 'action'.
	 * @return array<string, mixed>|\WP_Error Result data or error.
	 */
	public function tool_component( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'list'              => $this->tool_list_components( $args ),
			'get'               => $this->tool_get_component( $args ),
			'create'            => $this->tool_create_component( $args ),
			'update'            => $this->tool_update_component( $args ),
			'delete'            => $this->tool_delete_component( $args ),
			'instantiate'       => $this->tool_instantiate_component( $args ),
			'update_properties' => $this->tool_update_instance_properties( $args ),
			'fill_slot'         => $this->tool_fill_slot( $args ),
			default             => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: list, get, create, update, delete, instantiate, update_properties, fill_slot', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * Tool: List all component definitions with summary metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments (optional: category filter).
	 * @return array<string, mixed> Components list with total count.
	 */
	private function tool_list_components( array $args ): array {
		$components      = get_option( self::COMPONENTS_OPTION, array() );
		$category_filter = isset( $args['category'] ) ? strtolower( sanitize_text_field( $args['category'] ) ) : '';

		$result = array();
		foreach ( $components as $component ) {
			if ( '' !== $category_filter ) {
				$comp_category = strtolower( $component['category'] ?? '' );
				if ( $comp_category !== $category_filter ) {
					continue;
				}
			}

			$elements = $component['elements'] ?? array();
			$result[] = array(
				'id'             => $component['id'],
				'label'          => $component['label'] ?? '',
				'category'       => $component['category'] ?? '',
				'description'    => $component['description'] ?? '',
				'element_count'  => count( $elements ),
				'slot_count'     => count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) ),
				'property_count' => count( $component['properties'] ?? array() ),
			);
		}

		return array(
			'components' => $result,
			'total'      => count( $result ),
		);
	}

	/**
	 * Tool: Get a single component's full definition.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id).
	 * @return array<string, mixed>|\WP_Error Component data or error.
	 */
	private function tool_get_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$component = $components[ $index ];
		$elements  = $component['elements'] ?? array();

		// Enrich with computed metadata.
		$component['element_count']  = count( $elements );
		$component['slot_count']     = count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) );
		$component['slot_ids']       = array_values( array_map(
			fn( $el ) => $el['id'],
			array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' )
		) );
		$component['property_count'] = count( $component['properties'] ?? array() );

		return $component;
	}

	/**
	 * Tool: Create a new component from a label and element tree.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: label, elements).
	 * @return array<string, mixed>|\WP_Error Created component summary or error.
	 */
	private function tool_create_component( array $args ): array|\WP_Error {
		if ( empty( $args['label'] ) ) {
			return new \WP_Error( 'missing_label', __( 'label is required. Provide a display name for the component.', 'bricks-mcp' ) );
		}

		if ( empty( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'missing_elements', __( 'elements is required. Provide a non-empty flat element array (same structure as page content).', 'bricks-mcp' ) );
		}

		$label      = sanitize_text_field( $args['label'] );
		$category   = isset( $args['category'] ) ? sanitize_text_field( $args['category'] ) : '';
		$desc       = isset( $args['description'] ) ? sanitize_text_field( $args['description'] ) : '';
		$elements   = $args['elements'];
		$properties = isset( $args['properties'] ) && is_array( $args['properties'] ) ? $args['properties'] : array();

		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$id_generator = new ElementIdGenerator();
		$component_id = $id_generator->generate_unique( $components );

		// Prevent collision with registered Bricks element names.
		if ( class_exists( '\Bricks\Elements' ) && isset( \Bricks\Elements::$elements ) ) {
			$registered_names = array_keys( \Bricks\Elements::$elements );
			$max_retries      = 50;
			$retries          = 0;
			while ( in_array( $component_id, $registered_names, true ) && $retries < $max_retries ) {
				$component_id = $id_generator->generate_unique( $components );
				++$retries;
			}
		}

		// Set root element ID to match component ID.
		$elements[0]['id']     = $component_id;
		$elements[0]['parent'] = 0;

		$new_component = array(
			'id'          => $component_id,
			'label'       => $label,
			'category'    => $category,
			'description' => $desc,
			'elements'    => $elements,
			'properties'  => $properties,
		);

		$components[] = $new_component;
		update_option( self::COMPONENTS_OPTION, $components );

		$slot_count = count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) );

		return array(
			'created'        => true,
			'id'             => $component_id,
			'label'          => $label,
			'category'       => $category,
			'element_count'  => count( $elements ),
			'slot_count'     => $slot_count,
			'property_count' => count( $properties ),
		);
	}

	/**
	 * Tool: Update an existing component definition.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id; optional: label, category, description, elements, properties).
	 * @return array<string, mixed>|\WP_Error Updated component summary or error.
	 */
	private function tool_update_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		// Merge allowed fields.
		$allowed_fields = array( 'label', 'category', 'description', 'elements', 'properties' );
		foreach ( $allowed_fields as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				if ( 'label' === $field || 'category' === $field || 'description' === $field ) {
					$components[ $index ][ $field ] = sanitize_text_field( $args[ $field ] );
				} else {
					$components[ $index ][ $field ] = $args[ $field ];
				}
			}
		}

		// Enforce root element ID = component ID if elements were updated.
		if ( isset( $args['elements'] ) && is_array( $args['elements'] ) && ! empty( $args['elements'] ) ) {
			$components[ $index ]['elements'][0]['id']     = $component_id;
			$components[ $index ]['elements'][0]['parent'] = 0;
		}

		update_option( self::COMPONENTS_OPTION, $components );

		$updated  = $components[ $index ];
		$elements = $updated['elements'] ?? array();

		return array(
			'updated'        => true,
			'id'             => $component_id,
			'label'          => $updated['label'] ?? '',
			'category'       => $updated['category'] ?? '',
			'element_count'  => count( $elements ),
			'slot_count'     => count( array_filter( $elements, fn( $el ) => ( $el['name'] ?? '' ) === 'slot' ) ),
			'property_count' => count( $updated['properties'] ?? array() ),
		);
	}

	/**
	 * Tool: Delete a component definition by ID.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id).
	 * @return array<string, mixed>|\WP_Error Deletion confirmation or error.
	 */
	private function tool_delete_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$index        = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$label = $components[ $index ]['label'] ?? '';
		array_splice( $components, $index, 1 );
		update_option( self::COMPONENTS_OPTION, $components );

		return array(
			'deleted'      => true,
			'component_id' => $component_id,
			'label'        => $label,
			'note'         => __( 'Existing instances will render empty. Remove instances manually from pages.', 'bricks-mcp' ),
		);
	}

	/**
	 * Tool: Instantiate a component on a page.
	 *
	 * Creates a component instance element in the page's element array.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: component_id, post_id; optional: parent_id, position, properties).
	 * @return array<string, mixed>|\WP_Error Instantiation result or error.
	 */
	private function tool_instantiate_component( array $args ): array|\WP_Error {
		if ( empty( $args['component_id'] ) ) {
			return new \WP_Error( 'missing_component_id', __( 'component_id is required. Use component:list to find component IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		$component_id = sanitize_text_field( $args['component_id'] );
		$post_id      = (int) $args['post_id'];
		$parent_id    = isset( $args['parent_id'] ) ? sanitize_text_field( $args['parent_id'] ) : '0';
		$position     = isset( $args['position'] ) ? (int) $args['position'] : null;

		// Verify component exists.
		$components = get_option( self::COMPONENTS_OPTION, array() );
		$comp_index = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $comp_index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component "%s" not found. Use component:list to see available components.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$component_label = $components[ $comp_index ]['label'] ?? '';

		// Get existing page elements.
		$elements = $this->bricks_service->get_elements( $post_id );

		// Generate unique instance element ID.
		$id_generator = new ElementIdGenerator();
		$instance_id  = $id_generator->generate_unique( $elements );

		// Normalize root-level parent: string '0' → integer 0 (validate_element_linkage uses strict comparison).
		$parent = '0' !== $parent_id ? $parent_id : 0;

		// Build instance element.
		$instance_element = array(
			'id'           => $instance_id,
			'name'         => $component_id,
			'cid'          => $component_id,
			'parent'       => $parent,
			'children'     => array(),
			'settings'     => array(),
			'properties'   => isset( $args['properties'] ) && is_array( $args['properties'] ) ? $args['properties'] : array(),
			'slotChildren' => array(),
		);

		// If parent is specified and not root, validate parent exists and update its children.
		if ( '0' !== $parent_id ) {
			$parent_found = false;
			foreach ( $elements as &$el ) {
				if ( $el['id'] === $parent_id ) {
					$parent_found = true;
					if ( ! isset( $el['children'] ) || ! is_array( $el['children'] ) ) {
						$el['children'] = array();
					}
					if ( null !== $position && $position >= 0 && $position <= count( $el['children'] ) ) {
						array_splice( $el['children'], $position, 0, array( $instance_id ) );
					} else {
						$el['children'][] = $instance_id;
					}
					break;
				}
			}
			unset( $el );

			if ( ! $parent_found ) {
				return new \WP_Error(
					'parent_not_found',
					sprintf(
						/* translators: %s: Parent element ID */
						__( 'Parent element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
						$parent_id,
						$post_id
					)
				);
			}
		}

		$elements[] = $instance_element;

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return array(
			'instantiated'    => true,
			'instance_id'     => $instance_id,
			'component_id'    => $component_id,
			'component_label' => $component_label,
			'post_id'         => $post_id,
			'parent_id'       => $parent_id,
		);
	}

	/**
	 * Tool: Update property values on a component instance.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: post_id, instance_id, properties).
	 * @return array<string, mixed>|\WP_Error Updated properties or error.
	 */
	private function tool_update_instance_properties( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['instance_id'] ) ) {
			return new \WP_Error( 'missing_instance_id', __( 'instance_id is required. Use page:get to find component instance element IDs.', 'bricks-mcp' ) );
		}

		if ( ! isset( $args['properties'] ) || ! is_array( $args['properties'] ) ) {
			return new \WP_Error( 'missing_properties', __( 'properties object is required. Provide property ID to value mappings.', 'bricks-mcp' ) );
		}

		$post_id     = (int) $args['post_id'];
		$instance_id = sanitize_text_field( $args['instance_id'] );
		$elements    = $this->bricks_service->get_elements( $post_id );

		$found = false;
		foreach ( $elements as &$element ) {
			if ( $element['id'] === $instance_id ) {
				if ( ! isset( $element['cid'] ) ) {
					return new \WP_Error(
						'not_component_instance',
						sprintf(
							/* translators: %s: Element ID */
							__( 'Element "%s" is not a component instance (missing cid key).', 'bricks-mcp' ),
							$instance_id
						)
					);
				}
				$element['properties'] = array_merge( $element['properties'] ?? array(), $args['properties'] );
				$found                 = true;
				break;
			}
		}
		unset( $element );

		if ( ! $found ) {
			return new \WP_Error(
				'instance_not_found',
				sprintf(
					/* translators: %s: Instance ID */
					__( 'Instance element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
					$instance_id,
					$post_id
				)
			);
		}

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		// Re-find element for response.
		foreach ( $elements as $el ) {
			if ( $el['id'] === $instance_id ) {
				return array(
					'updated'     => true,
					'instance_id' => $instance_id,
					'properties'  => $el['properties'],
				);
			}
		}

		return array(
			'updated'     => true,
			'instance_id' => $instance_id,
			'properties'  => $args['properties'],
		);
	}

	/**
	 * Tool: Fill a slot on a component instance with element content.
	 *
	 * Atomically adds content elements to the page array and updates the
	 * instance element's slotChildren map.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: post_id, instance_id, slot_id, slot_elements).
	 * @return array<string, mixed>|\WP_Error Fill result or error.
	 */
	private function tool_fill_slot( array $args ): array|\WP_Error {
		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error( 'missing_post_id', __( 'post_id is required. Use page:list to find valid post IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['instance_id'] ) ) {
			return new \WP_Error( 'missing_instance_id', __( 'instance_id is required. Use page:get to find component instance element IDs.', 'bricks-mcp' ) );
		}

		if ( empty( $args['slot_id'] ) ) {
			return new \WP_Error( 'missing_slot_id', __( 'slot_id is required. Use component:get to find slot element IDs in the component definition.', 'bricks-mcp' ) );
		}

		if ( empty( $args['slot_elements'] ) || ! is_array( $args['slot_elements'] ) ) {
			return new \WP_Error( 'missing_slot_elements', __( 'slot_elements is required. Provide a non-empty flat element array for the slot content.', 'bricks-mcp' ) );
		}

		$post_id       = (int) $args['post_id'];
		$instance_id   = sanitize_text_field( $args['instance_id'] );
		$slot_id       = sanitize_text_field( $args['slot_id'] );
		$slot_elements = $args['slot_elements'];

		$elements = $this->bricks_service->get_elements( $post_id );

		// Find instance element.
		$instance_index = null;
		foreach ( $elements as $idx => $element ) {
			if ( $element['id'] === $instance_id ) {
				if ( ! isset( $element['cid'] ) ) {
					return new \WP_Error(
						'not_component_instance',
						sprintf(
							/* translators: %s: Element ID */
							__( 'Element "%s" is not a component instance (missing cid key).', 'bricks-mcp' ),
							$instance_id
						)
					);
				}
				$instance_index = $idx;
				break;
			}
		}

		if ( null === $instance_index ) {
			return new \WP_Error(
				'instance_not_found',
				sprintf(
					/* translators: %s: Instance ID */
					__( 'Instance element "%s" not found on post %d. Use page:get to inspect elements.', 'bricks-mcp' ),
					$instance_id,
					$post_id
				)
			);
		}

		// Verify the slot exists in the component definition.
		$component_id = $elements[ $instance_index ]['cid'];
		$components   = get_option( self::COMPONENTS_OPTION, array() );
		$comp_index   = array_search( $component_id, array_column( $components, 'id' ), true );

		if ( false === $comp_index ) {
			return new \WP_Error(
				'component_not_found',
				sprintf(
					/* translators: %s: Component ID */
					__( 'Component definition "%s" not found. The component may have been deleted.', 'bricks-mcp' ),
					$component_id
				)
			);
		}

		$comp_elements = $components[ $comp_index ]['elements'] ?? array();
		$slot_found    = false;
		foreach ( $comp_elements as $comp_el ) {
			if ( ( $comp_el['id'] ?? '' ) === $slot_id && ( $comp_el['name'] ?? '' ) === 'slot' ) {
				$slot_found = true;
				break;
			}
		}

		if ( ! $slot_found ) {
			return new \WP_Error(
				'slot_not_found',
				sprintf(
					/* translators: %1$s: Slot ID, %2$s: Component ID */
					__( 'Slot element "%1$s" not found in component "%2$s". Use component:get to find slot IDs.', 'bricks-mcp' ),
					$slot_id,
					$component_id
				)
			);
		}

		// Generate IDs for slot content elements and set parent to instance.
		$id_generator   = new ElementIdGenerator();
		$new_element_ids = array();

		foreach ( $slot_elements as &$slot_el ) {
			// Generate new ID if missing or conflicting.
			$needs_new_id = empty( $slot_el['id'] ) || ! is_string( $slot_el['id'] );
			if ( ! $needs_new_id ) {
				// Check for conflict with existing elements.
				foreach ( $elements as $existing ) {
					if ( $existing['id'] === $slot_el['id'] ) {
						$needs_new_id = true;
						break;
					}
				}
			}

			if ( $needs_new_id ) {
				$slot_el['id'] = $id_generator->generate_unique( $elements );
			}

			// Top-level slot content elements get parent = instance_id.
			if ( ! isset( $slot_el['parent'] ) || 0 === $slot_el['parent'] || '0' === $slot_el['parent'] ) {
				$slot_el['parent'] = $instance_id;
			}

			if ( ! isset( $slot_el['children'] ) ) {
				$slot_el['children'] = array();
			}

			$new_element_ids[] = $slot_el['id'];

			// Add to the tracking array for conflict checking.
			$elements[] = $slot_el;
		}
		unset( $slot_el );

		// Update instance element's slotChildren.
		$elements[ $instance_index ]['slotChildren']              = $elements[ $instance_index ]['slotChildren'] ?? array();
		$elements[ $instance_index ]['slotChildren'][ $slot_id ] = $new_element_ids;

		$save_result = $this->bricks_service->save_elements( $post_id, $elements );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return array(
			'filled'         => true,
			'instance_id'    => $instance_id,
			'slot_id'        => $slot_id,
			'elements_added' => count( $slot_elements ),
			'element_ids'    => $new_element_ids,
		);
	}

	/**
	 * Tool: Get component schema reference.
	 *
	 * Returns a static reference for component property types, connection wiring,
	 * slot mechanics, and instantiation patterns.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Component schema reference.
	 */
	private function tool_get_component_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'property_types'       => array(
				array(
					'type'         => 'text',
					'description'  => 'Text, textarea, or rich-text controls',
					'value_format' => 'string',
				),
				array(
					'type'         => 'icon',
					'description'  => 'Icon picker controls',
					'value_format' => 'object with library and icon keys',
				),
				array(
					'type'         => 'image',
					'description'  => 'Image controls',
					'value_format' => 'object with id and url keys',
				),
				array(
					'type'         => 'gallery',
					'description'  => 'Image gallery controls',
					'value_format' => 'array of image objects',
				),
				array(
					'type'         => 'link',
					'description'  => 'Link controls',
					'value_format' => 'object with url, type, and newTab keys',
				),
				array(
					'type'         => 'select',
					'description'  => 'Select/radio controls (with options array)',
					'value_format' => 'string (selected option value)',
				),
				array(
					'type'         => 'toggle',
					'description'  => 'Toggle controls',
					'value_format' => "'on' or 'off'",
				),
				array(
					'type'         => 'query',
					'description'  => 'Query loop controls',
					'value_format' => 'object with query parameters',
				),
				array(
					'type'         => 'class',
					'description'  => 'Global class pickers',
					'value_format' => 'array of global class IDs',
				),
			),
			'connections_format'   => array(
				'description' => 'Each property has a "connections" object mapping element IDs to arrays of setting keys. When an instance sets a property value, Bricks applies it to the connected element settings.',
				'example'     => array(
					'element_id' => array( 'text' ),
				),
				'note'        => 'Without connections, property values have no effect on rendering.',
			),
			'slot_mechanics'       => array(
				'description'        => "Slots are special elements with name='slot' placed inside a component definition. Instance slot content is stored in the page element array, referenced via slotChildren on the instance element.",
				'slot_element'       => array(
					'name'     => 'slot',
					'parent'   => '<parent_in_component>',
					'children' => array(),
					'settings' => array(),
				),
				'instance_slot_fill' => array(
					'<slot_element_id>' => array( '<content_element_id_1>', '<content_element_id_2>' ),
				),
				'fill_note'          => 'Slot content elements live in the page\'s flat element array with parent = instance element ID. Use component:fill_slot action to manage this atomically.',
			),
			'instantiation_pattern' => array(
				'description'      => 'To place a component on a page, use component:instantiate. The instance is an element with name=cid=component_id.',
				'instance_keys'    => array(
					'name'         => '<component_id>',
					'cid'          => '<component_id>',
					'properties'   => array(),
					'slotChildren' => array(),
				),
				'propagation_note' => 'Changes to the component definition automatically affect all instances at render time.',
			),
			'important_notes'      => array(
				'Root element ID in component elements array MUST equal the component ID',
				'Element name for instances equals the component ID (not a human-readable element type)',
				'Properties without connections have no effect on rendering — always set connections',
				'Slot elements MUST use name=\'slot\' — other nestable elements do not trigger slot behavior',
				'Component IDs use the same 6-char alphanumeric format as element IDs',
				'Nested components are supported up to 10 levels deep',
			),
		);
	}

	/**
	 * Tool: Get popup schema.
	 *
	 * Returns all popup display settings keys organized by category, trigger patterns,
	 * popup creation workflow, and important notes.
	 *
	 * @param array<string, mixed> $args Tool arguments (unused).
	 * @return array<string, mixed> Popup schema data.
	 */
	private function tool_get_popup_schema( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return array(
			'popup_settings'   => array(
				'outer'                => array(
					'popupPadding'          => array( 'type' => 'spacing object', 'default' => null, 'description' => 'Padding of the .brx-popup container (skipped when popupIsInfoBox=true)' ),
					'popupJustifyContent'   => array( 'type' => 'string', 'default' => null, 'description' => 'justify-content CSS value for popup main axis alignment (skipped when popupIsInfoBox=true)' ),
					'popupAlignItems'       => array( 'type' => 'string', 'default' => null, 'description' => 'align-items CSS value for popup cross axis (skipped when popupIsInfoBox=true)' ),
					'popupCloseOn'          => array( 'type' => 'string', 'default' => 'both (unset)', 'description' => "Close behavior. 'backdrop' = click only, 'esc' = ESC only, 'none' = neither. Unset = both backdrop+ESC. Do NOT pass 'both'. (skipped when popupIsInfoBox=true)" ),
					'popupZindex'           => array( 'type' => 'number', 'default' => 10000, 'description' => 'CSS z-index of the popup (skipped when popupIsInfoBox=true)' ),
					'popupBodyScroll'       => array( 'type' => 'boolean', 'default' => false, 'description' => 'Allow body scroll when popup is open (skipped when popupIsInfoBox=true)' ),
					'popupScrollToTop'      => array( 'type' => 'boolean', 'default' => false, 'description' => 'Scroll popup to top on open (skipped when popupIsInfoBox=true)' ),
					'popupDisableAutoFocus' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Do not auto-focus first focusable element on open (skipped when popupIsInfoBox=true)' ),
				),
				'info_box'             => array(
					'popupIsInfoBox'    => array( 'type' => 'boolean', 'default' => false, 'description' => 'Enable Map Info Box mode (disables many other settings)' ),
					'popupInfoBoxWidth' => array( 'type' => 'number (px)', 'default' => 300, 'description' => 'Width of info box in pixels' ),
				),
				'ajax'                 => array(
					'popupAjax'                => array( 'type' => 'boolean', 'default' => false, 'description' => 'Fetch popup content via AJAX. Only supports Post, Term, and User context types.' ),
					'popupIsWoo'               => array( 'type' => 'boolean', 'default' => false, 'description' => 'WooCommerce Quick View mode (requires popupAjax=true)' ),
					'popupAjaxLoaderAnimation' => array( 'type' => 'string', 'default' => null, 'description' => 'Loading animation type from ajaxLoaderAnimations option set' ),
					'popupAjaxLoaderColor'     => array( 'type' => 'color object', 'default' => null, 'description' => 'AJAX loader color' ),
					'popupAjaxLoaderScale'     => array( 'type' => 'number', 'default' => 1, 'description' => 'AJAX loader scale factor' ),
					'popupAjaxLoaderSelector'  => array( 'type' => 'string', 'default' => '.brx-popup-content', 'description' => 'CSS selector to inject loader into' ),
				),
				'breakpoint_visibility' => array(
					'popupBreakpointMode' => array( 'type' => 'string', 'default' => null, 'description' => "'at' = show starting at breakpoint, 'on' = show on specific breakpoints only" ),
					'popupShowAt'         => array( 'type' => 'string', 'default' => null, 'description' => "Breakpoint key (e.g. 'tablet_portrait'). Used when mode='at'" ),
					'popupShowOn'         => array( 'type' => 'string[]', 'default' => null, 'description' => "Array of breakpoint keys. Used when mode='on'" ),
				),
				'backdrop'             => array(
					'popupDisableBackdrop'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'Remove backdrop element (enables page interaction while popup open)' ),
					'popupBackground'        => array( 'type' => 'background object', 'default' => null, 'description' => 'Backdrop background (color, image, etc.)' ),
					'popupBackdropTransition' => array( 'type' => 'string', 'default' => null, 'description' => 'CSS transition value for backdrop' ),
				),
				'content_sizing'       => array(
					'popupContentPadding'    => array( 'type' => 'spacing object', 'default' => '30px all sides', 'description' => 'Padding inside .brx-popup-content' ),
					'popupContentWidth'      => array( 'type' => 'number+unit', 'default' => 'container width', 'description' => 'Width of content box' ),
					'popupContentMinWidth'   => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Min-width of content box' ),
					'popupContentMaxWidth'   => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Max-width of content box' ),
					'popupContentHeight'     => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Height of content box' ),
					'popupContentMinHeight'  => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Min-height of content box' ),
					'popupContentMaxHeight'  => array( 'type' => 'number+unit', 'default' => null, 'description' => 'Max-height of content box' ),
					'popupContentBackground' => array( 'type' => 'background object', 'default' => null, 'description' => 'Content box background' ),
					'popupContentBorder'     => array( 'type' => 'border object', 'default' => null, 'description' => 'Content box border' ),
					'popupContentBoxShadow'  => array( 'type' => 'box-shadow object', 'default' => null, 'description' => 'Content box shadow' ),
				),
				'display_limits'       => array(
					'popupLimitWindow'         => array( 'type' => 'number', 'default' => null, 'description' => 'Max times per page load (window variable)' ),
					'popupLimitSessionStorage' => array( 'type' => 'number', 'default' => null, 'description' => 'Max times per session (sessionStorage)' ),
					'popupLimitLocalStorage'   => array( 'type' => 'number', 'default' => null, 'description' => 'Max times across sessions (localStorage)' ),
					'popupLimitTimeStorage'    => array( 'type' => 'number (hours)', 'default' => null, 'description' => 'Show again only after N hours' ),
				),
				'template_interactions' => array(
					'type'        => 'repeater (same structure as element _interactions)',
					'description' => "Popup-level interactions. Stored in _bricks_template_settings.template_interactions. Supports special triggers 'showPopup' (fires when popup is shown) and 'hidePopup' (fires after popup is hidden). Used for chaining animations or running JS on popup open/close — NOT for making the popup open itself.",
				),
			),
			'infobox_behavior' => array(
				'description'      => 'An infobox is a popup with popupIsInfoBox=true. Infoboxes are lightweight popups designed for Google Maps info windows. They skip many popup display settings.',
				'skipped_settings' => array(
					'popupPadding'        => 'Outer padding (infobox has no backdrop/overlay)',
					'popupJustifyContent' => 'Main axis alignment (infobox positioned by map marker)',
					'popupAlignItems'     => 'Cross axis alignment (infobox positioned by map marker)',
					'popupCloseOn'        => 'Close behavior (infobox closes when map marker deselected)',
					'popupZindex'         => 'Z-index (infobox managed by map layer)',
					'popupBodyScroll'     => 'Body scroll lock (infobox does not overlay page)',
				),
				'active_settings'  => array(
					'popupIsInfoBox'         => 'Must be true',
					'popupInfoBoxWidth'      => 'Width in px (default 300)',
					'popupContentPadding'    => 'Content padding',
					'popupContentWidth'      => 'Content width',
					'popupContentBackground' => 'Content background',
					'popupContentBorder'     => 'Content border',
					'popupContentBoxShadow'  => 'Content shadow',
					'popupAjax'              => 'AJAX loading supported',
					'template_interactions'  => 'Popup-level interactions supported',
				),
				'creation_workflow' => array(
					'step_1' => "template:create with type='popup' and title='My Infobox'",
					'step_2' => "template:set_popup_settings with settings={popupIsInfoBox: true, popupInfoBoxWidth: 300}",
					'step_3' => 'page:update_content to add elements to the infobox template',
					'step_4' => 'Use in a Bricks Map element by referencing the template ID',
				),
			),
			'trigger_patterns' => array(
				'click'      => array(
					'description' => 'Open popup when a button is clicked. Set on the button element _interactions.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'click',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
					),
				),
				'page_load'  => array(
					'description' => 'Auto-open popup on page load with optional delay. Set on any element or in popup template_interactions.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'contentLoaded',
						'delay'      => '2s',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
					),
				),
				'scroll'     => array(
					'description' => 'Open popup when user scrolls to a percentage. scrollOffset accepts px or % values.',
					'interaction' => array(
						'id'           => '<6-char-id>',
						'trigger'      => 'scroll',
						'scrollOffset' => '50%',
						'action'       => 'show',
						'target'       => 'popup',
						'templateId'   => '<popup_template_id>',
					),
				),
				'exit_intent' => array(
					'description' => 'Open popup when mouse leaves browser window. Use runOnce to fire only once.',
					'interaction' => array(
						'id'         => '<6-char-id>',
						'trigger'    => 'mouseleaveWindow',
						'action'     => 'show',
						'target'     => 'popup',
						'templateId' => '<popup_template_id>',
						'runOnce'    => true,
					),
				),
			),
			'workflow'         => array(
				'step_1' => 'template:create — type=popup, title="My Popup"',
				'step_2' => 'page:update_content — add elements (heading, text, form, button, etc.) to the popup template',
				'step_3' => 'template:set_popup_settings — set popupCloseOn, popupContentMaxWidth, popupLimitLocalStorage, etc.',
				'step_4' => 'element:update — add _interactions to a trigger element on any page: {trigger: "click", action: "show", target: "popup", templateId: <popup_id>}',
				'step_5' => 'template_condition:set — set conditions to control which pages include the popup',
			),
			'important_notes'  => array(
				'Popup display settings go in _bricks_template_settings (template level), NOT in element settings',
				"Triggers go in _interactions on OTHER elements (or template_interactions on the popup itself for showPopup/hidePopup reactions)",
				'Template conditions control WHICH PAGES show the popup. Interactions control WHEN it opens.',
				"popupCloseOn: unset=both backdrop+ESC, 'backdrop'=click only, 'esc'=key only, 'none'=disabled. Do NOT pass 'both'.",
				'popupAjax only supports Post, Term, and User context types',
				'Use bricks:get_interaction_schema for full trigger/action reference',
				'Use template:get_popup_settings to read current popup config, template:set_popup_settings to write',
				'Null value in set_popup_settings deletes that key (reverts to default)',
				'An infobox is a popup sub-type (popupIsInfoBox=true). Create as popup, then set popupIsInfoBox via set_popup_settings. See infobox_behavior for which settings apply.',
				'template:list and template:get responses include is_infobox boolean for quick identification.',
				),
		);
	}

	/**
	 * Tool: WooCommerce builder tools.
	 *
	 * Consolidated dispatcher for WooCommerce status, element discovery,
	 * dynamic data tags, and template scaffolding.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Response data or error.
	 */
	public function tool_woocommerce( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or not active. Install and activate WooCommerce before using WooCommerce builder tools.', 'bricks-mcp' )
			);
		}

		$action        = $args['action'] ?? '';


		return match ( $action ) {
			'status'            => $this->tool_woocommerce_status(),
			'get_elements'      => $this->tool_woocommerce_get_elements( $args ),
			'get_dynamic_tags'  => $this->tool_woocommerce_get_dynamic_tags( $args ),
			'scaffold_template' => $this->tool_woocommerce_scaffold_template( $args ),
			'scaffold_store'    => $this->tool_woocommerce_scaffold_store( $args ),
			default             => new \WP_Error(
				'invalid_action',
				sprintf(
					/* translators: %s: Action name */
					__( 'Invalid action "%s". Valid actions: status, get_elements, get_dynamic_tags, scaffold_template, scaffold_store', 'bricks-mcp' ),
					$action
				)
			),
		};
	}

	/**
	 * WooCommerce: Get status.
	 *
	 * Returns WooCommerce version, page assignments, Bricks WooCommerce settings,
	 * available template types, and count of existing WooCommerce templates.
	 *
	 * @return array<string, mixed> WooCommerce status data.
	 */
	private function tool_woocommerce_status(): array {
		$global_settings = get_option( 'bricks_global_settings', array() );
		$woo_settings    = array();
		$woo_keys        = array(
			'woocommerceEnableAjaxAddToCart',
			'woocommerceDisableBuilder',
			'woocommerceAjaxAddedText',
			'woocommerceAjaxAddingText',
			'woocommerceAjaxHideViewCart',
			'woocommerceAjaxShowNotice',
			'woocommerceBadgeNew',
			'woocommerceBadgeSale',
			'woocommerceUseQtyInLoop',
			'woocommerceUseVariationSwatches',
			'woocommerceDisableProductGalleryLightbox',
			'woocommerceDisableProductGalleryZoom',
		);

		foreach ( $woo_keys as $key ) {
			if ( isset( $global_settings[ $key ] ) ) {
				$woo_settings[ $key ] = $global_settings[ $key ];
			}
		}

		// Get WooCommerce template types (wc_ prefixed only).
		$all_types = $this->bricks_service->get_condition_types();
		$wc_types  = array();
		foreach ( $all_types as $slug => $type_data ) {
			if ( str_starts_with( $slug, 'wc_' ) ) {
				$wc_types[] = array(
					'slug'  => $slug,
					'label' => $type_data['label'],
				);
			}
		}

		// Count existing WooCommerce templates.
		$existing_query = new \WP_Query(
			array(
				'post_type'      => 'bricks_template',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_bricks_template_type',
						'value'   => 'wc_',
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array(
			'woocommerce_version'         => WC()->version,
			'woocommerce_active'          => true,
			'shop_page_id'                => wc_get_page_id( 'shop' ),
			'cart_page_id'                => wc_get_page_id( 'cart' ),
			'checkout_page_id'            => wc_get_page_id( 'checkout' ),
			'myaccount_page_id'           => wc_get_page_id( 'myaccount' ),
			'terms_page_id'               => wc_get_page_id( 'terms' ),
			'bricks_woocommerce_settings' => $woo_settings,
			'template_types_available'    => $wc_types,
			'existing_woo_templates'      => $existing_query->found_posts,
		);
	}

	/**
	 * WooCommerce: Get WooCommerce-specific elements.
	 *
	 * Filters the Bricks element catalog to WooCommerce elements only.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'category'.
	 * @return array<string, mixed> Filtered element catalog.
	 */
	private function tool_woocommerce_get_elements( array $args ): array {
		$catalog       = $this->schema_generator->get_element_catalog();
		$category      = $args['category'] ?? '';
		$woo_elements  = array();
		$woo_prefixes  = array( 'product-', 'cart-', 'checkout-', 'account-', 'woocommerce-', 'products' );

		foreach ( $catalog as $element ) {
			$name = $element['name'];
			$cat  = strtolower( $element['category'] ?? '' );

			// Match by Bricks category or name prefix.
			$is_woo = str_contains( $cat, 'woocommerce' );
			if ( ! $is_woo ) {
				foreach ( $woo_prefixes as $prefix ) {
					if ( str_starts_with( $name, $prefix ) ) {
						$is_woo = true;
						break;
					}
				}
			}

			if ( ! $is_woo ) {
				continue;
			}

			// Assign a normalized category for filtering.
			$norm_cat = 'utility';
			if ( str_contains( $name, 'product-' ) || str_starts_with( $name, 'product' ) ) {
				$norm_cat = 'product';
			} elseif ( str_contains( $name, 'cart-' ) || str_starts_with( $name, 'cart' ) ) {
				$norm_cat = 'cart';
			} elseif ( str_contains( $name, 'checkout-' ) || str_starts_with( $name, 'checkout' ) ) {
				$norm_cat = 'checkout';
			} elseif ( str_contains( $name, 'account-' ) || str_starts_with( $name, 'account' ) ) {
				$norm_cat = 'account';
			} elseif ( str_starts_with( $name, 'products' ) ) {
				$norm_cat = 'archive';
			}

			if ( '' !== $category && $norm_cat !== $category ) {
				continue;
			}

			$woo_elements[] = array(
				'name'            => $name,
				'label'           => $element['label'],
				'bricks_category' => $element['category'],
				'woo_category'    => $norm_cat,
			);
		}

		return array(
			'total_elements' => count( $woo_elements ),
			'note'           => 'WooCommerce-specific elements available when WooCommerce is active. Use these element names with page:create, element:add, and scaffold_template.',
			'elements'       => $woo_elements,
		);
	}

	/**
	 * WooCommerce: Get dynamic data tags reference.
	 *
	 * Returns a categorized reference of WooCommerce dynamic data tags.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional 'category'.
	 * @return array<string, mixed> Dynamic data tags reference.
	 */
	private function tool_woocommerce_get_dynamic_tags( array $args ): array {
		$category = $args['category'] ?? '';

		$tags = array(
			'product_price'   => array(
				'label'   => 'Product Price',
				'context' => 'Single product templates, product archive loops',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_price}',
						'description' => 'Full product price with currency and HTML (shows sale + regular when on sale)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_regular_price}',
						'description' => 'Regular price with currency and HTML',
						'modifiers'   => array( ':plain (no HTML)', ':value (numeric only)' ),
					),
					array(
						'tag'         => '{woo_product_sale_price}',
						'description' => 'Sale price (empty if not on sale)',
						'modifiers'   => array( ':plain', ':value' ),
					),
				),
			),
			'product_display' => array(
				'label'   => 'Product Display',
				'context' => 'Single product templates',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_images}',
						'description' => 'Featured + gallery images',
						'modifiers'   => array( ':value (comma-separated attachment IDs)' ),
					),
					array(
						'tag'         => '{woo_product_gallery_images}',
						'description' => 'Gallery images only (excludes featured image)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_cat_image}',
						'description' => 'Product category image',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_add_to_cart}',
						'description' => 'Renders add to cart button',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_on_sale}',
						'description' => 'On-sale badge (empty if not on sale)',
						'modifiers'   => array(),
					),
				),
			),
			'product_info'    => array(
				'label'   => 'Product Information',
				'context' => 'Single product templates, product archive loops',
				'tags'    => array(
					array(
						'tag'         => '{woo_product_rating}',
						'description' => 'Star rating display',
						'modifiers'   => array( ':plain (text)', ':format (shows even without reviews)' ),
					),
					array(
						'tag'         => '{woo_product_sku}',
						'description' => 'Product SKU',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_excerpt}',
						'description' => 'Product short description',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_product_stock}',
						'description' => 'Stock info text',
						'modifiers'   => array( ':value (quantity number)', ':status (instock/outofstock/onbackorder)' ),
					),
					array(
						'tag'         => '{woo_product_badge_new}',
						'description' => 'New product badge',
						'modifiers'   => array( ':plain (text only)' ),
					),
				),
			),
			'cart'            => array(
				'label'   => 'Cart (for Cart Contents query loop)',
				'context' => 'Cart template with Cart Contents query loop',
				'tags'    => array(
					array(
						'tag'         => '{woo_cart_product_name}',
						'description' => 'Product name with link to product page',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_remove_link}',
						'description' => 'Remove from cart anchor element',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_quantity}',
						'description' => 'Quantity input field',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_subtotal}',
						'description' => 'Line item subtotal (price x quantity)',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_cart_update}',
						'description' => 'Update cart button',
						'modifiers'   => array(),
					),
				),
			),
			'order'           => array(
				'label'   => 'Order (for Thank You / Order templates)',
				'context' => 'Thank you template, order receipt, pay template',
				'tags'    => array(
					array(
						'tag'         => '{woo_order_id}',
						'description' => 'Order ID number',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_order_total}',
						'description' => 'Order total with currency',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{woo_order_email}',
						'description' => 'Customer email address',
						'modifiers'   => array(),
					),
				),
			),
			'post_compatible' => array(
				'label'   => 'Standard Post Tags (WooCommerce compatible)',
				'context' => 'Any WooCommerce template (products are posts)',
				'tags'    => array(
					array(
						'tag'         => '{post_id}',
						'description' => 'Product/post ID',
						'modifiers'   => array(),
					),
					array(
						'tag'         => '{post_title}',
						'description' => 'Product/post title',
						'modifiers'   => array( ':link (as hyperlink)' ),
					),
					array(
						'tag'         => '{post_terms_product_cat}',
						'description' => 'Product categories',
						'modifiers'   => array( ':plain (no links)' ),
					),
					array(
						'tag'         => '{post_terms_product_tag}',
						'description' => 'Product tags',
						'modifiers'   => array( ':plain (no links)' ),
					),
				),
			),
		);

		$template_hooks = array(
			'single_product'  => array(
				'woocommerce_before_single_product',
				'woocommerce_before_single_product_summary',
				'woocommerce_single_product_summary',
				'woocommerce_after_single_product_summary',
				'woocommerce_after_single_product',
			),
			'product_archive' => array(
				'woocommerce_archive_description',
				'woocommerce_before_shop_loop',
				'woocommerce_after_shop_loop',
			),
			'cart'            => array(
				'woocommerce_before_cart',
				'woocommerce_before_cart_collaterals',
				'woocommerce_after_cart',
			),
			'empty_cart'      => array(
				'woocommerce_cart_is_empty',
			),
		);

		if ( '' !== $category ) {
			if ( ! isset( $tags[ $category ] ) ) {
				return array(
					'note'            => 'These dynamic data tags can be used in text fields of any element by wrapping them in curly braces. Modifiers are appended with colon, e.g. {woo_product_price:value}.',
					'categories'      => array_keys( $tags ),
					'tags'            => $tags,
					'template_hooks'  => $template_hooks,
				);
			}
			$tags = array( $category => $tags[ $category ] );
		}

		return array(
			'note'           => 'These dynamic data tags can be used in text fields of any element by wrapping them in curly braces. Use in product templates for dynamic product data, in cart templates within Cart Contents query loops, and in order templates for order details. Modifiers are appended with colon, e.g. {woo_product_price:value}.',
			'tags'           => $tags,
			'template_hooks' => $template_hooks,
		);
	}

	/**
	 * Get default titles for WooCommerce template types.
	 *
	 * @return array<string, string> Map of template type slug to default title.
	 */
	private function get_woocommerce_default_titles(): array {
		return array(
			'wc_product'      => 'Single Product',
			'wc_archive'      => 'Product Archive',
			'wc_cart'         => 'Shopping Cart',
			'wc_cart_empty'   => 'Empty Cart',
			'wc_checkout'     => 'Checkout',
			'wc_account_form' => 'Account Login / Register',
			'wc_account_page' => 'My Account',
			'wc_thankyou'     => 'Thank You',
		);
	}

	/**
	 * Get pre-populated element scaffold for a WooCommerce template type.
	 *
	 * Returns a simplified nested element array for the given template type.
	 * Element names are based on Bricks Builder documentation and may need
	 * updating if Bricks changes its internal registration names.
	 *
	 * @param string $template_type WooCommerce template type slug.
	 * @return array<int, array<string, mixed>> Simplified nested element array.
	 */
	private function get_woocommerce_scaffold( string $template_type ): array {
		return match ( $template_type ) {
			'wc_product'      => $this->get_scaffold_wc_product(),
			'wc_archive'      => $this->get_scaffold_wc_archive(),
			'wc_cart'         => $this->get_scaffold_wc_cart(),
			'wc_cart_empty'   => $this->get_scaffold_wc_cart_empty(),
			'wc_checkout'     => $this->get_scaffold_wc_checkout(),
			'wc_account_form' => $this->get_scaffold_wc_account_form(),
			'wc_account_page' => $this->get_scaffold_wc_account_page(),
			'wc_thankyou'     => $this->get_scaffold_wc_thankyou(),
			default           => array(),
		};
	}

	/**
	 * Scaffold: Single Product template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_product(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                  => 'row',
							'_justifyContent'             => 'space-between',
							'_direction:mobile_portrait'  => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '50%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'product-gallery', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '45%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-breadcrumbs', 'settings' => array() ),
									array( 'name' => 'product-title', 'settings' => array() ),
									array( 'name' => 'product-rating', 'settings' => array() ),
									array( 'name' => 'product-price', 'settings' => array() ),
									array( 'name' => 'product-short-description', 'settings' => array() ),
									array( 'name' => 'product-add-to-cart', 'settings' => array() ),
									array( 'name' => 'product-meta', 'settings' => array() ),
								),
							),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-tabs', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-upsells', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'product-related', 'settings' => array() ),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Product Archive template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_archive(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
							array( 'name' => 'woocommerce-breadcrumbs', 'settings' => array() ),
							array( 'name' => 'woocommerce-products-archive-description', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'      => 'row',
							'_justifyContent' => 'space-between',
							'_alignItems'     => 'center',
						),
						'children' => array(
							array( 'name' => 'woocommerce-products-total-results', 'settings' => array() ),
							array( 'name' => 'woocommerce-products-orderby', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '25%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-products-filter', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '75%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-products', 'settings' => array() ),
									array( 'name' => 'woocommerce-products-pagination', 'settings' => array() ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Cart template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_cart(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array(
									'tag'  => 'h1',
									'text' => 'Shopping Cart',
								),
							),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '65%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-cart-items', 'settings' => array() ),
									array( 'name' => 'woocommerce-cart-coupon', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '30%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-cart-collaterals', 'settings' => array() ),
									array(
										'name'     => 'container',
										'settings' => array(
											'_padding'    => array( 'top' => '20px', 'right' => '20px', 'bottom' => '20px', 'left' => '20px' ),
											'_background' => array( 'color' => array( 'hex' => '#F8F9FA' ) ),
											'_border'     => array( 'radius' => array( 'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px' ) ),
											'_direction'  => 'column',
											'_alignItems' => 'center',
										),
										'children' => array(
											array(
												'name'     => 'heading',
												'settings' => array(
													'tag'         => 'h4',
													'text'        => 'Secure Checkout',
													'_typography' => array( 'font-size' => '16px', 'font-weight' => '600' ),
												),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => 'Your payment information is processed securely. We do not store credit card details.' ),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => '30-Day Money-Back Guarantee' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Empty Cart template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_cart_empty(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
							'_padding'    => array( 'top' => '80px', 'bottom' => '80px' ),
						),
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h2', 'text' => 'Your cart is empty' ),
							),
							array(
								'name'     => 'text-basic',
								'settings' => array( 'text' => 'Browse our products and find something you love.' ),
							),
							array(
								'name'     => 'button',
								'settings' => array(
									'text' => 'Return to Shop',
									'link' => array( 'type' => 'external', 'url' => '/shop' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Checkout template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_checkout(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'Checkout' ),
							),
							array( 'name' => 'woocommerce-account-form-login', 'settings' => array() ),
							array( 'name' => 'woocommerce-cart-coupon', 'settings' => array() ),
						),
					),
				),
			),
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'                 => 'row',
							'_direction:mobile_portrait' => 'column',
						),
						'children' => array(
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '60%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-checkout-customer-details', 'settings' => array() ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'                 => '35%',
									'_width:mobile_portrait' => '100%',
								),
								'children' => array(
									array( 'name' => 'woocommerce-checkout-order-review', 'settings' => array() ),
									array(
										'name'     => 'container',
										'settings' => array(
											'_padding'    => array( 'top' => '20px', 'right' => '20px', 'bottom' => '20px', 'left' => '20px' ),
											'_background' => array( 'color' => array( 'hex' => '#F8F9FA' ) ),
											'_border'     => array( 'radius' => array( 'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px' ) ),
											'_direction'  => 'column',
											'_alignItems' => 'center',
										),
										'children' => array(
											array(
												'name'     => 'heading',
												'settings' => array(
													'tag'         => 'h4',
													'text'        => 'Secure Payment',
													'_typography' => array( 'font-size' => '16px', 'font-weight' => '600' ),
												),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => 'SSL encrypted payment. Your information is safe.' ),
											),
											array(
												'name'     => 'text-basic',
												'settings' => array( 'text' => '100% Satisfaction Guarantee' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Account Login / Register template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_account_form(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
						),
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'My Account' ),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_direction'                 => 'row',
									'_direction:mobile_portrait' => 'column',
									'_justifyContent'            => 'center',
								),
								'children' => array(
									array(
										'name'     => 'container',
										'settings' => array(
											'_width'                 => '45%',
											'_width:mobile_portrait' => '100%',
										),
										'children' => array(
											array( 'name' => 'woocommerce-account-form-login', 'settings' => array() ),
										),
									),
									array(
										'name'     => 'container',
										'settings' => array(
											'_width'                 => '45%',
											'_width:mobile_portrait' => '100%',
										),
										'children' => array(
											array( 'name' => 'woocommerce-account-form-register', 'settings' => array() ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: My Account page template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_account_page(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'children' => array(
		array(
								'name'     => 'heading',
								'settings' => array( 'tag' => 'h1', 'text' => 'My Account' ),
							),
							array( 'name' => 'woocommerce-account-page', 'settings' => array() ),
						),
					),
				),
			),
		);
	}

	/**
	 * Scaffold: Thank You template.
	 *
	 * @return array<int, array<string, mixed>> Element array.
	 */
	private function get_scaffold_wc_thankyou(): array {
		return array(
			array(
				'name'     => 'section',
				'children' => array(
					array(
						'name'     => 'container',
						'settings' => array(
							'_direction'  => 'column',
							'_alignItems' => 'center',
						),
						'children' => array(
							array(
								'name'     => 'heading',
								'settings' => array(
									'tag'          => 'h1',
									'text'         => 'Thank You!',
									'_typography'  => array( 'font-size' => '32px', 'font-weight' => '700' ),
								),
							),
							array(
								'name'     => 'text-basic',
								'settings' => array(
									'text'        => 'Your order has been placed successfully.',
									'_typography' => array( 'color' => array( 'hex' => '#666666' ) ),
								),
							),
							array(
								'name'     => 'container',
								'settings' => array(
									'_width'     => '100%',
									'_direction' => 'column',
								),
								'children' => array(
									array(
										'name'     => 'woocommerce-checkout-thankyou',
										'settings' => array(
											// Message section — success notice styling.
											'messageMargin'     => array( 'top' => '30px', 'right' => '0', 'bottom' => '30px', 'left' => '0' ),
											'messagePadding'    => array( 'top' => '20px', 'right' => '24px', 'bottom' => '20px', 'left' => '24px' ),
											'messageBackground' => array( 'hex' => '#DFF0D8' ),
											'messageBorder'     => array(
												'radius' => array( 'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px' ),
											),
											'messageTypography' => array( 'font-size' => '16px', 'font-weight' => '500', 'color' => array( 'hex' => '#3C763D' ) ),

											// Order details section.
											'detailsMargin'  => array( 'top' => '30px', 'right' => '0', 'bottom' => '30px', 'left' => '0' ),
											'detailsPadding' => array( 'top' => '16px', 'right' => '20px', 'bottom' => '16px', 'left' => '20px' ),
											'detailsBorder'  => array(
												'radius' => array( 'top' => '8px', 'right' => '8px', 'bottom' => '8px', 'left' => '8px' ),
											),

											// Action buttons in order details table (Bricks 2.3).
											'detailsActionButtonGap'        => '8px',
											'detailsActionButtonPadding'    => array( 'top' => '8px', 'right' => '16px', 'bottom' => '8px', 'left' => '16px' ),
											'detailsActionButtonBackground' => array( 'hex' => '#333333' ),
											'detailsActionButtonBorder'     => array(
												'radius' => array( 'top' => '4px', 'right' => '4px', 'bottom' => '4px', 'left' => '4px' ),
											),
											'detailsActionButtonTypography' => array( 'font-size' => '13px', 'font-weight' => '500', 'color' => array( 'hex' => '#FFFFFF' ) ),

											// Order again button (Bricks 2.3).
											'orderAgainButtonPadding'    => array( 'top' => '12px', 'right' => '24px', 'bottom' => '12px', 'left' => '24px' ),
											'orderAgainButtonBackground' => array( 'hex' => '#333333' ),
											'orderAgainButtonBorder'     => array(
												'radius' => array( 'top' => '6px', 'right' => '6px', 'bottom' => '6px', 'left' => '6px' ),
											),
											'orderAgainButtonTypography' => array( 'font-size' => '14px', 'font-weight' => '600', 'color' => array( 'hex' => '#FFFFFF' ) ),

											// Failed order buttons (Bricks 2.3).
											'failedOrderButtonPadding'    => array( 'top' => '10px', 'right' => '20px', 'bottom' => '10px', 'left' => '20px' ),
											'failedOrderButtonBackground' => array( 'hex' => '#D9534F' ),
											'failedOrderButtonBorder'     => array(
												'radius' => array( 'top' => '4px', 'right' => '4px', 'bottom' => '4px', 'left' => '4px' ),
											),
											'failedOrderButtonTypography' => array( 'font-size' => '14px', 'font-weight' => '500', 'color' => array( 'hex' => '#FFFFFF' ) ),

											// Billing address section.
											'addressMargin' => array( 'top' => '30px', 'right' => '0', 'bottom' => '30px', 'left' => '0' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * WooCommerce: Scaffold a single template.
	 *
	 * Creates a pre-populated WooCommerce template with standard elements,
	 * auto-assigned conditions, and responsive settings.
	 *
	 * @param array<string, mixed> $args Tool arguments. Required: template_type.
	 * @return array<string, mixed>|\WP_Error Created template data or error.
	 */
	private function tool_woocommerce_scaffold_template( array $args ): array|\WP_Error {
		$template_type = $args['template_type'] ?? '';
		$valid_types   = array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' );

		if ( '' === $template_type ) {
			return new \WP_Error(
				'missing_template_type',
				sprintf(
					__( 'template_type is required. Valid types: %s', 'bricks-mcp' ),
					implode( ', ', $valid_types )
				)
			);
		}

		if ( ! in_array( $template_type, $valid_types, true ) ) {
			return new \WP_Error(
				'invalid_template_type',
				sprintf(
					__( 'Invalid template_type "%s". Valid types: %s', 'bricks-mcp' ),
					$template_type,
					implode( ', ', $valid_types )
				)
			);
		}

		$default_titles = $this->get_woocommerce_default_titles();
		$title          = $args['title'] ?? $default_titles[ $template_type ] ?? $template_type;
		$status         = $args['status'] ?? 'publish';

		// Check for existing templates of this type.
		$existing_warning = null;
		$existing_query   = new \WP_Query(
			array(
				'post_type'      => 'bricks_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_bricks_template_type',
						'value' => $template_type,
					),
				),
			)
		);

		if ( $existing_query->found_posts > 0 ) {
			$existing_id      = $existing_query->posts[0];
			$existing_warning = sprintf(
				'A %s template already exists (ID: %d, title: "%s"). Creating another — the one with the higher condition score or later creation date will take priority.',
				$template_type,
				$existing_id,
				get_the_title( $existing_id )
			);
		}

		// Create template.
		$template_id = $this->bricks_service->create_template(
			array(
				'title'  => $title,
				'type'   => $template_type,
				'status' => $status,
			)
		);

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Save pre-populated elements (scaffold uses simplified nested format, normalize to flat).
		$elements    = $this->get_woocommerce_scaffold( $template_type );
		$elements    = $this->bricks_service->normalize_elements( $elements );
		$save_result = $this->bricks_service->save_elements( $template_id, $elements );

		if ( is_wp_error( $save_result ) ) {
			wp_delete_post( $template_id, true );
			return new \WP_Error(
				'scaffold_save_failed',
				sprintf(
					__( 'Template created but element save failed: %s. Template has been rolled back.', 'bricks-mcp' ),
					$save_result->get_error_message()
				)
			);
		}

		// Auto-assign condition.
		$condition_result = $this->bricks_service->set_template_conditions(
			$template_id,
			array( array( 'main' => $template_type ) )
		);

		// Count elements saved.
		$saved_content = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );
		$element_count = is_array( $saved_content ) ? count( $saved_content ) : 0;

		$result = array(
			'template_id'         => $template_id,
			'title'               => $title,
			'type'                => $template_type,
			'status'              => $status,
			'condition_assigned'  => $template_type,
			'element_count'       => $element_count,
			'customization_hints' => array(
				'Use template:get to view the full element tree',
				'Use element:update to modify individual element settings',
				'Use element:add to insert additional elements',
				'Use element:remove to remove unwanted elements',
				'Use get_builder_guide(section="woocommerce") for WooCommerce building patterns',
			),
		);

		if ( null !== $existing_warning ) {
			$result['warning'] = $existing_warning;
		}

		if ( is_wp_error( $condition_result ) ) {
			$result['condition_warning'] = 'Template created but condition assignment failed: ' . $condition_result->get_error_message();
		}

		return $result;
	}

	/**
	 * WooCommerce: Scaffold all essential templates.
	 *
	 * Creates pre-populated templates for all (or specified) WooCommerce types.
	 *
	 * @param array<string, mixed> $args Tool arguments. Optional: types, skip_existing.
	 * @return array<string, mixed>|\WP_Error Summary of created/skipped templates.
	 */
	private function tool_woocommerce_scaffold_store( array $args ): array|\WP_Error {
		$all_types     = array( 'wc_product', 'wc_archive', 'wc_cart', 'wc_cart_empty', 'wc_checkout', 'wc_account_form', 'wc_account_page', 'wc_thankyou' );
		$types         = $args['types'] ?? $all_types;
		$skip_existing = $args['skip_existing'] ?? true;

		// Validate types.
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $all_types, true ) ) {
				return new \WP_Error(
					'invalid_template_type',
					sprintf(
						__( 'Invalid template type "%s" in types array. Valid types: %s', 'bricks-mcp' ),
						$type,
						implode( ', ', $all_types )
					)
				);
			}
		}

		$created = array();
		$skipped = array();
		$failed  = array();

		foreach ( $types as $type ) {
			// Check for existing.
			if ( $skip_existing ) {
				$existing_query = new \WP_Query(
					array(
						'post_type'      => 'bricks_template',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => '_bricks_template_type',
								'value' => $type,
							),
						),
					)
				);

				if ( $existing_query->found_posts > 0 ) {
					$skipped[] = array(
						'type'                 => $type,
						'reason'               => 'Template already exists',
						'existing_template_id' => $existing_query->posts[0],
					);
					continue;
				}
			}

			// Create scaffold.
			$result = $this->tool_woocommerce_scaffold_template(
				array(
					'template_type' => $type,
					'status'        => $args['status'] ?? 'publish',
				)
			);

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'type'  => $type,
					'error' => $result->get_error_message(),
				);
			} else {
				$created[] = array(
					'template_id' => $result['template_id'],
					'title'       => $result['title'],
					'type'        => $result['type'],
					'condition'   => $result['condition_assigned'],
				);
			}
		}

		if ( count( $created ) === 0 && count( $skipped ) === 0 ) {
			return new \WP_Error(
				'scaffold_store_failed',
				__( 'All template scaffolds failed. Check WooCommerce and Bricks are properly configured.', 'bricks-mcp' )
			);
		}

		return array(
			'created'       => $created,
			'skipped'       => $skipped,
			'failed'        => $failed,
			'total_created' => count( $created ),
			'total_skipped' => count( $skipped ),
			'total_failed'  => count( $failed ),
			'next_steps'    => 'Use template:get to view and customize individual templates. Use page:update_content or element:add/update to modify elements. Use get_builder_guide(section="woocommerce") for patterns.',
		);
	}
	
	/**
	 * Tool handler: page_diagnose — pre-edit health check.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Diagnostics report or error.
	 */
	private function tool_page_diagnose( array $args ): array|\WP_Error {
		$bricks_error = $this->require_bricks();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		if ( empty( $args['post_id'] ) ) {
			return new \WP_Error(
				'missing_post_id',
				__( 'post_id is required.', 'bricks-mcp' ),
				array( 'next_step' => 'Call page:list to find valid post IDs.', 'suggested_tool' => 'page' )
			);
		}

		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( __( 'Post %d not found.', 'bricks-mcp' ), $post_id ),
				array( 'next_step' => 'Call page:list to find valid post IDs.', 'suggested_tool' => 'page' )
			);
		}

		return $this->bricks_service->diagnose_page( $post_id );
	}

	/**
	 * Tool handler: page_spacing_audit — spacing rhythm audit.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Audit result or error.
	 */
	private function tool_page_spacing_audit( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_param', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->get_spacing_audit( $post_id );
	}

	/**
	 * Tool handler: page_screenshot — capture page via external API.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Screenshot data or error.
	 */
	private function tool_page_screenshot( array $args ): array|\WP_Error {
		$post_id  = absint( $args['post_id'] ?? 0 );
		$viewport = in_array( $args['viewport'] ?? '', array( 'desktop', 'tablet', 'mobile' ), true ) ? (string) $args['viewport'] : 'desktop';
		$fresh    = (bool) ( $args['fresh'] ?? false );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_param', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->take_screenshot( $post_id, $viewport, $fresh );
	}

	/**
	 * Tool handler: page_visual_review — screenshot + Claude Vision critique.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|\WP_Error Review data or error.
	 */
	private function tool_page_visual_review( array $args ): array|\WP_Error {
		$post_id  = absint( $args['post_id'] ?? 0 );
		$viewport = in_array( $args['viewport'] ?? '', array( 'desktop', 'tablet', 'mobile' ), true ) ? (string) $args['viewport'] : 'desktop';
		$focus    = in_array( $args['focus'] ?? '', array( 'spacing', 'alignment', 'colors', 'all' ), true ) ? (string) $args['focus'] : 'all';
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'missing_param', __( 'post_id is required.', 'bricks-mcp' ) );
		}
		return $this->bricks_service->get_visual_review( $post_id, $viewport, $focus );
	}
}