<?php
/**
 * In-memory WordPress core stubs: Abilities API, posts and post meta.
 *
 * Tests reset state with bricks_mcp_test_reset() in setUp().
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Reset every in-memory store shared by the stubs.
 *
 * @return void
 */
function bricks_mcp_test_reset(): void {
	$GLOBALS['_bricks_mcp_test_options']            = [];
	$GLOBALS['_bricks_mcp_test_settings']           = [];
	$GLOBALS['_bricks_mcp_test_transients']         = [];
	$GLOBALS['_bricks_mcp_test_cache']              = [];
	$GLOBALS['_bricks_mcp_test_caps']               = [];
	$GLOBALS['_bricks_mcp_test_object_caps']        = [];
	$GLOBALS['_bricks_mcp_test_current_user_can']   = true;
	$GLOBALS['_bricks_mcp_test_current_user_id']    = 1;
	$GLOBALS['_bricks_mcp_test_posts']              = [];
	$GLOBALS['_bricks_mcp_test_post_meta']          = [];
	$GLOBALS['_bricks_mcp_test_abilities']          = [];
	$GLOBALS['_bricks_mcp_test_ability_categories'] = [];
	$GLOBALS['_bricks_mcp_test_ability_calls']      = [];
	$GLOBALS['_bricks_mcp_test_actions']            = [];
}

bricks_mcp_test_reset();

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub built from an array of fields.
	 */
	#[AllowDynamicProperties]
	class WP_Post {
		/** @var int */
		public int $ID = 0;
		/** @var string */
		public string $post_type = 'page';
		/** @var string */
		public string $post_status = 'publish';
		/** @var string */
		public string $post_title = '';
		/** @var string */
		public string $post_name = '';

		/**
		 * @param array<string, mixed> $fields Post fields.
		 */
		public function __construct( array $fields = [] ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

/**
 * Add a post to the in-memory store and return it.
 *
 * @param array<string, mixed> $fields Post fields; ID is required.
 * @param array<string, mixed> $meta   Post meta, key => single value.
 * @return WP_Post
 */
function bricks_mcp_test_add_post( array $fields, array $meta = [] ): WP_Post {
	$post                                                = new WP_Post( $fields );
	$GLOBALS['_bricks_mcp_test_posts'][ $post->ID ]     = $post;
	$GLOBALS['_bricks_mcp_test_post_meta'][ $post->ID ] = $meta;
	return $post;
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null ): ?WP_Post {
		$id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return $GLOBALS['_bricks_mcp_test_posts'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( mixed $post = null ): string|false {
		$found = get_post( $post );
		return $found ? $found->post_type : false;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$meta = $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] ?? [];
		if ( '' === $key ) {
			return array_map( static fn( $value ) => [ $value ], $meta );
		}
		if ( ! array_key_exists( $key, $meta ) ) {
			return $single ? '' : [];
		}
		return $single ? $meta[ $key ] : [ $meta[ $key ] ];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev_value = '' ): int|bool {
		$GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		$existed = isset( $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] ) && array_key_exists( $key, $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] );
		unset( $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ][ $key ] );
		return $existed;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array|object $postarr = [], bool $wp_error = false ): int|WP_Error {
		$fields = (array) $postarr;
		$post   = get_post( (int) ( $fields['ID'] ?? 0 ) );
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}
		foreach ( $fields as $key => $value ) {
			$post->$key = $value;
		}
		return $post->ID;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) $GLOBALS['_bricks_mcp_test_current_user_id'];
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, mixed ...$args ): void {
		$GLOBALS['_bricks_mcp_test_actions'][] = [ $hook_name, $args ];
	}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * Minimal WP_Ability stub: execute() runs the permission and execute callbacks.
	 */
	class WP_Ability {
		/** @var string */
		private string $name;
		/** @var array<string, mixed> */
		private array $args;

		/**
		 * @param string               $name Ability name.
		 * @param array<string, mixed> $args Registration arguments.
		 */
		public function __construct( string $name, array $args ) {
			$this->name = $name;
			$this->args = $args;
		}

		public function get_name(): string {
			return $this->name;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->args['meta'] ?? [];
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_args(): array {
			return $this->args;
		}

		public function execute( mixed $input = null ): mixed {
			$GLOBALS['_bricks_mcp_test_ability_calls'][] = [ $this->name, $input ];
			if ( isset( $this->args['permission_callback'] ) ) {
				$allowed = call_user_func( $this->args['permission_callback'], $input );
				if ( true !== $allowed ) {
					return $allowed instanceof WP_Error ? $allowed : new WP_Error( 'ability_invalid_permissions', 'Permission denied.' );
				}
			}
			return call_user_func( $this->args['execute_callback'], $input );
		}
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): ?WP_Ability {
		$ability                                       = new WP_Ability( $name, $args );
		$GLOBALS['_bricks_mcp_test_abilities'][ $name ] = $ability;
		return $ability;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): ?array {
		$GLOBALS['_bricks_mcp_test_ability_categories'][ $slug ] = $args;
		return $args;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?WP_Ability {
		return $GLOBALS['_bricks_mcp_test_abilities'][ $name ] ?? null;
	}
}
