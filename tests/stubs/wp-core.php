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

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( mixed $post ): int|false {
		$found = get_post( $post );
		return $found && 'revision' === $found->post_type ? (int) ( $found->post_parent ?? 0 ) : false;
	}
}

if ( ! function_exists( 'update_metadata' ) ) {
	function update_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		// WordPress unslashes meta values before storing them.
		$GLOBALS['_bricks_mcp_test_post_meta'][ $object_id ][ $meta_key ] = wp_unslash( $meta_value );
		return true;
	}
}

if ( ! function_exists( 'delete_metadata' ) ) {
	function delete_metadata( string $meta_type, int $object_id, string $meta_key, mixed $meta_value = '', bool $delete_all = false ): bool {
		$meta_value = wp_unslash( $meta_value );
		$meta       = $GLOBALS['_bricks_mcp_test_post_meta'][ $object_id ] ?? [];
		if ( ! array_key_exists( $meta_key, $meta ) ) {
			return false;
		}
		// Like WordPress, a given value selects the row only while its stored, serialized value matches.
		if ( '' !== $meta_value && null !== $meta_value && false !== $meta_value && (string) maybe_serialize( $meta_value ) !== (string) maybe_serialize( $meta[ $meta_key ] ) ) {
			return false;
		}
		// Then, like WordPress, it deletes the selected row by ID, whatever the row holds by now.
		return (bool) $GLOBALS['wpdb']->delete(
			$GLOBALS['wpdb']->postmeta,
			[
				'post_id'  => $object_id,
				'meta_key' => $meta_key,
			]
		);
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal wpdb stub: deletes post meta rows from the in-memory store.
	 *
	 * Like MySQL's default utf8mb4 collations, a meta_value comparison ignores letter case and trailing spaces unless it is cast to BINARY.
	 * The connection is utf8mb4; a BINARY cast of the column compares the bytes of its own character set.
	 */
	class wpdb {
		/** @var string */
		public string $postmeta = 'wp_postmeta';

		/** @var string Character set of the meta_value column: utf8mb4 or latin1. */
		public string $meta_value_charset = 'utf8mb4';

		/**
		 * Delete the post meta row matching post_id and meta_key, and meta_value when given.
		 *
		 * @param string               $table  Table name.
		 * @param array<string, mixed> $where  Column values.
		 * @param string[]|null        $format Formats.
		 * @return int|false Rows deleted.
		 */
		public function delete( string $table, array $where, ?array $format = null ): int|false {
			if ( $this->postmeta !== $table ) {
				return 0;
			}
			return $this->delete_post_meta_row( (int) $where['post_id'], (string) $where['meta_key'], isset( $where['meta_value'] ) ? (string) $where['meta_value'] : null, false );
		}

		/**
		 * Replace %d and %s placeholders like wpdb::prepare(), quoting and escaping strings.
		 *
		 * @param string $query   Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( string $query, mixed ...$args ): string {
			return (string) preg_replace_callback(
				'/%[ds]/',
				static function ( array $placeholder ) use ( &$args ): string {
					$arg = array_shift( $args );
					return '%d' === $placeholder[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$query
			);
		}

		/**
		 * Run a post meta DELETE on post_id, meta_key and meta_value; any other query fails the test.
		 *
		 * @param string $query Prepared query.
		 * @return int|false Rows deleted.
		 */
		public function query( string $query ): int|false {
			$quoted = "'((?:[^'\\\\]|\\\\.)*)'";
			$prefix = "DELETE FROM {$this->postmeta} WHERE post_id = (\\d+) AND meta_key = {$quoted} AND ";
			if ( preg_match( "/^{$prefix}CAST\\( CONVERT\\( meta_value USING utf8mb4 \\) AS BINARY \\) = CAST\\( CONVERT\\( {$quoted} USING utf8mb4 \\) AS BINARY \\)$/", $query, $matches ) ) {
				return $this->delete_post_meta_row( (int) $matches[1], stripslashes( $matches[2] ), stripslashes( $matches[3] ), true );
			}
			if ( ! preg_match( "/^{$prefix}meta_value = (CAST\\( )?{$quoted}( AS BINARY \\))?$/", $query, $matches ) ) {
				throw new LogicException( "Unsupported query: {$query}" );
			}
			$value  = stripslashes( $matches[4] );
			$binary = '' !== $matches[3] && '' !== ( $matches[5] ?? '' );
			// The utf8mb4 bytes of the literal, read as the column's character set, are what the column bytes must equal.
			if ( $binary && 'latin1' === $this->meta_value_charset ) {
				$value = mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
			}
			return $this->delete_post_meta_row( (int) $matches[1], stripslashes( $matches[2] ), $value, $binary );
		}

		/**
		 * Delete one post meta row in one step, like one DELETE statement.
		 *
		 * @param int         $post_id  Post ID.
		 * @param string      $meta_key Meta key.
		 * @param string|null $value    Serialized value to match, or null for any.
		 * @param bool        $binary   Whether the value is compared byte for byte.
		 * @return int Rows deleted.
		 */
		protected function delete_post_meta_row( int $post_id, string $meta_key, ?string $value, bool $binary ): int {
			$meta = $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] ?? [];
			if ( ! array_key_exists( $meta_key, $meta ) ) {
				return 0;
			}
			$stored  = (string) maybe_serialize( $meta[ $meta_key ] );
			$collate = static fn( string $text ): string => $binary ? $text : mb_strtolower( rtrim( $text, ' ' ) );
			if ( null !== $value && $collate( $value ) !== $collate( $stored ) ) {
				return 0;
			}
			unset( $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ][ $meta_key ] );
			return 1;
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new wpdb();
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev_value = '' ): int|bool {
		// Like WordPress, write a revision's meta to its parent.
		return update_metadata( 'post', wp_is_post_revision( $post_id ) ?: $post_id, $key, $value, $prev_value );
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		// Like WordPress, delete a revision's meta from its parent.
		return delete_metadata( 'post', wp_is_post_revision( $post_id ) ?: $post_id, $key, $value );
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( string $path, string $suffix = '' ): string {
		// WordPress core's implementation: a multibyte-safe basename().
		return urldecode( basename( str_replace( [ '%2F', '%5C' ], '/', urlencode( $path ) ), $suffix ) );
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( int $post_id, bool $force_delete = false ): WP_Post|false|null {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}
		unset( $GLOBALS['_bricks_mcp_test_posts'][ $post_id ], $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] );
		return $post;
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
			if ( is_array( $input ) && isset( $this->args['input_schema'] ) ) {
				$valid = rest_validate_value_from_schema( $input, $this->args['input_schema'], 'input' );
				if ( is_wp_error( $valid ) ) {
					return new WP_Error( 'ability_invalid_input', sprintf( 'Ability "%s" has invalid input. Reason: %s', $this->name, $valid->get_error_message() ) );
				}
			}
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

if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
	/**
	 * Subset of core's schema validation that WP_Ability::execute() runs before the callbacks: object
	 * required/additionalProperties/properties and string minLength/maxLength (counted like core, with mb_strlen).
	 * Other keywords are not checked, so the stub only ever rejects input core rejects too.
	 *
	 * @param mixed                $value Value to validate.
	 * @param array<string, mixed> $args  Schema.
	 * @param string               $param Parameter name for messages.
	 * @return true|WP_Error
	 */
	function rest_validate_value_from_schema( mixed $value, array $args, string $param = '' ): bool|WP_Error {
		if ( is_array( $value ) && 'object' === ( $args['type'] ?? null ) ) {
			foreach ( (array) ( $args['required'] ?? [] ) as $name ) {
				if ( ! array_key_exists( $name, $value ) ) {
					return new WP_Error( 'rest_property_required', sprintf( '%s is a required property of %s.', $name, $param ) );
				}
			}
			$properties = $args['properties'] ?? [];
			foreach ( $value as $name => $property ) {
				if ( isset( $properties[ $name ] ) ) {
					$valid = rest_validate_value_from_schema( $property, $properties[ $name ], $param . '[' . $name . ']' );
					if ( is_wp_error( $valid ) ) {
						return $valid;
					}
				} elseif ( false === ( $args['additionalProperties'] ?? true ) ) {
					return new WP_Error( 'rest_additional_properties_forbidden', sprintf( '%s is not a valid property of Object.', $name ) );
				}
			}
		}
		if ( is_string( $value ) && 'string' === ( $args['type'] ?? null ) ) {
			if ( isset( $args['minLength'] ) && mb_strlen( $value ) < $args['minLength'] ) {
				return new WP_Error( 'rest_too_short', sprintf( '%s must be at least %d characters long.', $param, $args['minLength'] ) );
			}
			if ( isset( $args['maxLength'] ) && mb_strlen( $value ) > $args['maxLength'] ) {
				return new WP_Error( 'rest_too_long', sprintf( '%s must be at most %d characters long.', $param, $args['maxLength'] ) );
			}
		}
		return true;
	}
}
