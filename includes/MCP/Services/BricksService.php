<?php
/**
 * File: includes/MCP/Services/BricksService.php
 *
 * Bricks Builder data access service.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 * @version 1.5.2
 *
 * Changelog:
 * ----------
 * 1.5.2 — 2026-03-31
 *   - FIX (GitHub #11): update_page_css() — CSS is now sanitised before storing.
 *                       Added 100 KB hard cap. Blocks javascript:, expression(),
 *                       @import, data:, behaviour:, and -moz-binding: patterns that
 *                       can enable code execution or data exfiltration.
 *   - FIX (GitHub #9):  import_template_from_url() — SSRF hardening:
 *                       (a) Enforced HTTPS-only URLs.
 *                       (b) Added DNS-rebinding guard: resolves the hostname and
 *                         rejects requests that resolve to private/reserved IP ranges
 *                         (10.x, 172.16.x, 192.168.x, 127.x, 169.254.x).
 *                       (c) Enforced application/json Content-Type on response to
 *                         prevent importing arbitrary binary content.
 *   - FIX (Issue 3):    Removed stray extra closing brace at end of file (line 8238)
 *                       that was left over from the manual mid-session syntax fix
 *                       applied 2026-03-30 ~22:30. Brace balance was off by 1.
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BricksService class.
 *
 * Provides the data access layer for reading and writing Bricks Builder content.
 * All Bricks-specific operations go through this service.
 */
class BricksService {

	/**
	 * Element normalizer instance.
	 *
	 * @var ElementNormalizer
	 */
	private ElementNormalizer $normalizer;

	/**
	 * Validation service instance.
	 *
	 * Optional — when set, validates element settings against Bricks schemas before saving.
	 *
	 * @var ValidationService|null
	 */
	private ?ValidationService $validation_service = null;

	/**
	 * Constructor.
	 *
	 * Initializes the element normalizer with an ID generator.
	 */
	public function __construct() {
		$this->normalizer = new ElementNormalizer( new ElementIdGenerator() );
	}

	/**
	 * Set the validation service.
	 *
	 * When set, element settings are validated against Bricks schemas before every save.
	 * Invalid elements are rejected with detailed error messages including JSON paths.
	 *
	 * @param ValidationService $service Validation service instance.
	 * @return void
	 */
	public function set_validation_service( ValidationService $service ): void {
		$this->validation_service = $service;
	}

	/**
	 * Post meta key for Bricks page content.
	 *
	 * @var string
	 */
	public const META_KEY = '_bricks_page_content_2';

	/**
	 * Post meta key for Bricks editor mode.
	 *
	 * @var string
	 */
	public const EDITOR_MODE_KEY = '_bricks_editor_mode';

	/**
	 * Check if Bricks Builder is active.
	 *
	 * This is the single gate for all Bricks-specific functionality.
	 *
	 * @return bool True if Bricks Builder is installed and active.
	 */
	public function is_bricks_active(): bool {
		return class_exists( '\Bricks\Elements' );
	}

	/**
	 * Normalize element input using the ElementNormalizer.
	 *
	 * Detects input format (native flat array or simplified nested) and normalizes
	 * to the Bricks native flat array format. Exposed for use by the Router.
	 *
	 * @param array<int, array<string, mixed>> $input             Input elements.
	 * @param array<int, array<string, mixed>> $existing_elements Existing elements for collision-free IDs.
	 * @return array<int, array<string, mixed>> Normalized flat element array.
	 */
	public function normalize_elements( array $input, array $existing_elements = [] ): array {
		return $this->normalizer->normalize( $input, $existing_elements );
	}

	/**
	 * Check if a post is using the Bricks editor.
	 *
	 * @param int $post_id The post ID to check.
	 * @return bool True if the post uses Bricks editor.
	 */
	public function is_bricks_page( int $post_id ): bool {
		return get_post_meta( $post_id, self::EDITOR_MODE_KEY, true ) === 'bricks';
	}

	/**
	 * Get Bricks elements for a post.
	 *
	 * Reads the flat element array from post meta.
	 * WordPress automatically unserializes the stored data.
	 *
	 * @param int $post_id The post ID.
	 * @return array<int, array<string, mixed>> Flat array of elements, empty array if none.
	 */
	public function get_elements( int $post_id ): array {
		$elements = get_post_meta( $post_id, $this->resolve_elements_meta_key( $post_id ), true );

		if ( ! is_array( $elements ) ) {
			return [];
		}

		return $elements;
	}

	/**
	 * Resolve the correct Bricks meta key for reading or writing element content.
	 *
	 * Header and footer templates store their content under dedicated meta keys
	 * (_bricks_page_header_2 and _bricks_page_footer_2 respectively), not the
	 * default _bricks_page_content_2. This method checks the template type and
	 * returns the appropriate key.
	 *
	 * Public so that save_elements() and unhook_bricks_meta_filters() can resolve
	 * the correct key at write time, preventing phantom-section bugs on templates.
	 *
	 * @param int $post_id The post ID.
	 * @return string The meta key to use for reading or writing element content.
	 */
	public function resolve_elements_meta_key( int $post_id ): string {
		$template_type = get_post_meta( $post_id, '_bricks_template_type', true );
		return match ( $template_type ) {
			'header' => defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2',
			'footer' => defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2',
			default  => self::META_KEY,
		};
	}

	/**
	 * Save Bricks elements for a post.
	 *
	 * Validates structure via validate_element_linkage() (always runs), then validates
	 * element settings via ValidationService against Bricks schemas (when Bricks is active
	 * and ValidationService is set). Only proceeds to database write if all validation passes.
	 *
	 * Write pipeline:
	 * 1. Clear object cache to prevent stale comparison in update_post_meta.
	 * 2. Attempt update_post_meta; on false (no-op due to serialization match), fallback
	 *    to delete_post_meta + add_post_meta to force the write.
	 * 3. Verify write via cache-cleared read-back; return WP_Error if data did not persist.
	 *
	 * @param int                              $post_id  The post ID.
	 * @param array<int, array<string, mixed>> $elements Flat array of elements to save.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_elements( int $post_id, array $elements ): bool|\WP_Error {
		// Always run structural linkage validation.
		$linkage_validation = $this->validate_element_linkage( $elements );

		if ( is_wp_error( $linkage_validation ) ) {
			return $linkage_validation;
		}

		// Run schema validation when Bricks is active and ValidationService is available.
		if ( null !== $this->validation_service && $this->is_bricks_active() ) {
			$schema_validation = $this->validation_service->validate_elements( $elements );

			if ( is_wp_error( $schema_validation ) ) {
				return $schema_validation;
			}
		}

		// Resolve the correct meta key for this post type.
		// Header/footer templates use _bricks_page_header_2 / _bricks_page_footer_2;
		// regular pages/posts use _bricks_page_content_2 (self::META_KEY).
		// Using self::META_KEY here would create a phantom section on templates.
		$meta_key = $this->resolve_elements_meta_key( $post_id );

		// Clear stale object cache so update_post_meta sees current DB state.
		wp_cache_delete( $post_id, 'post_meta' );

		// Temporarily unhook Bricks sanitize/update filters that block programmatic meta writes.
		$this->unhook_bricks_meta_filters( $post_id );
		try {
			$updated = update_post_meta( $post_id, $meta_key, $elements );

			if ( false === $updated ) {
				// update_post_meta returns false when old === new (stale cache or serialization mismatch).
				// Force write via delete + add.
				delete_post_meta( $post_id, $meta_key );
				add_post_meta( $post_id, $meta_key, $elements, true );
			}

			update_post_meta( $post_id, self::EDITOR_MODE_KEY, 'bricks' );

			// Trigger CSS regeneration so frontend styles reflect new content.
			$this->trigger_css_regeneration( $post_id );

			// Verify write persisted — bypass cache, read raw from database.
			wp_cache_delete( $post_id, 'post_meta' );
			$stored = get_post_meta( $post_id, $meta_key, true );
		} finally {
			$this->rehook_bricks_meta_filters( $post_id );
		}

		if ( ! is_array( $stored ) || count( $stored ) !== count( $elements ) ) {
			return new \WP_Error(
				'save_elements_failed',
				__( 'Elements appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return true;
	}

	/**
	 * Enable the Bricks editor for a post.
	 *
	 * Sets the editor mode meta key without requiring elements.
	 * Called when creating pages without initial elements.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function enable_bricks_editor( int $post_id ): void {
		update_post_meta( $post_id, self::EDITOR_MODE_KEY, 'bricks' );
	}

	/**
	 * Disable the Bricks editor for a post.
	 *
	 * Removes the editor mode meta key. Bricks element content is preserved
	 * in the database and can be restored by re-enabling Bricks.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function disable_bricks_editor( int $post_id ): void {
		delete_post_meta( $post_id, self::EDITOR_MODE_KEY );
	}

	/**
	 * Stored Bricks meta filter callbacks for temporary removal.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored_filters = [];

	/**
	 * Remove Bricks meta sanitize/update filters that block programmatic writes.
	 *
	 * Bricks registers sanitize_post_meta and update_post_metadata filters via
	 * instance methods on its Ajax class. These reject writes outside the Bricks
	 * editor context. We temporarily unhook them so MCP can save validated data.
	 *
	 * Accepts $post_id so the correct sanitize filter key is removed for header/footer
	 * templates (_bricks_page_header_2 / _bricks_page_footer_2) rather than always
	 * removing only the content key (_bricks_page_content_2).
	 *
	 * @param int $post_id The post ID being written to.
	 * @return void
	 */
	public function unhook_bricks_meta_filters( int $post_id = 0 ): void {
		global $wp_filter;

		// Resolve the meta key being written so we remove the right sanitize filter.
		$resolved_key = $post_id > 0 ? $this->resolve_elements_meta_key( $post_id ) : self::META_KEY;
		$sanitize_key = 'sanitize_post_meta_' . $resolved_key;

		// Also always unhook the default content key in case Bricks registered it globally.
		$default_sanitize_key = 'sanitize_post_meta_' . self::META_KEY;

		// Store and remove the resolved sanitize filter.
		if ( isset( $wp_filter[ $sanitize_key ] ) ) {
			$this->stored_filters[ $sanitize_key ] = $wp_filter[ $sanitize_key ];
			unset( $wp_filter[ $sanitize_key ] );
		}

		// Store and remove the default content sanitize filter (no-op if same as resolved key).
		if ( $default_sanitize_key !== $sanitize_key && isset( $wp_filter[ $default_sanitize_key ] ) ) {
			$this->stored_filters[ $default_sanitize_key ] = $wp_filter[ $default_sanitize_key ];
			unset( $wp_filter[ $default_sanitize_key ] );
		}

		// Store and remove Bricks\Ajax callbacks from update_post_metadata.
		if ( isset( $wp_filter['update_post_metadata'] ) ) {
			$this->stored_filters['update_post_metadata_bricks'] = [];
			foreach ( $wp_filter['update_post_metadata']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && $callback['function'][0] instanceof \Bricks\Ajax ) {
						$this->stored_filters['update_post_metadata_bricks'][] = [
							'priority' => $priority,
							'id'       => $id,
							'callback' => $callback,
						];
						unset( $wp_filter['update_post_metadata']->callbacks[ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/**
	 * Re-hook Bricks meta filters after programmatic write.
	 *
	 * Accepts $post_id to restore the correct sanitize filter key for header/footer
	 * templates, mirroring the fix in unhook_bricks_meta_filters().
	 *
	 * @param int $post_id The post ID that was written to.
	 * @return void
	 */
	public function rehook_bricks_meta_filters( int $post_id = 0 ): void {
		global $wp_filter;

		// Resolve the same keys that were stored during unhook.
		$resolved_key         = $post_id > 0 ? $this->resolve_elements_meta_key( $post_id ) : self::META_KEY;
		$sanitize_key         = 'sanitize_post_meta_' . $resolved_key;
		$default_sanitize_key = 'sanitize_post_meta_' . self::META_KEY;

		// Restore the resolved sanitize filter.
		if ( isset( $this->stored_filters[ $sanitize_key ] ) ) {
			$wp_filter[ $sanitize_key ] = $this->stored_filters[ $sanitize_key ];
			unset( $this->stored_filters[ $sanitize_key ] );
		}

		// Restore the default content sanitize filter (no-op if same key).
		if ( $default_sanitize_key !== $sanitize_key && isset( $this->stored_filters[ $default_sanitize_key ] ) ) {
			$wp_filter[ $default_sanitize_key ] = $this->stored_filters[ $default_sanitize_key ];
			unset( $this->stored_filters[ $default_sanitize_key ] );
		}

		// Restore Bricks\Ajax callbacks to update_post_metadata.
		if ( ! empty( $this->stored_filters['update_post_metadata_bricks'] ) && isset( $wp_filter['update_post_metadata'] ) ) {
			foreach ( $this->stored_filters['update_post_metadata_bricks'] as $entry ) {
				$wp_filter['update_post_metadata']->callbacks[ $entry['priority'] ][ $entry['id'] ] = $entry['callback'];
			}
			unset( $this->stored_filters['update_post_metadata_bricks'] );
		}
	}

	/**
	 * Recursively sanitize a styles array for global classes.
	 *
	 * Walks the nested styles structure and sanitizes all scalar values
	 * using wp_strip_all_tags() to prevent stored XSS while preserving
	 * CSS values (units, variables, color functions).
	 *
	 * @param array<string, mixed> $styles The styles array to sanitize.
	 * @return array<string, mixed> Sanitized styles array.
	 */
	private function sanitize_styles_array( array $styles ): array {
		$sanitized = [];
		foreach ( $styles as $key => $value ) {
			$safe_key = wp_strip_all_tags( (string) $key );
			if ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = $this->sanitize_styles_array( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $safe_key ] = wp_strip_all_tags( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * Trigger Bricks CSS regeneration for a post after programmatic save.
	 * Needed when Bricks uses External Files CSS mode - API saves bypass the editor
	 * pipeline that normally regenerates static CSS files.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	private function trigger_css_regeneration( int $post_id ): void {
		if ( ! $this->is_bricks_active() ) {
			return;
		}
		try {
			do_action( 'bricks/save_post', $post_id );
			if ( class_exists( '\Bricks\Assets' ) && method_exists( '\Bricks\Assets', 'generate_css_from_elements' ) ) {
				$elements = $this->get_elements( $post_id );
				\Bricks\Assets::generate_css_from_elements( $elements, $post_id );
			}
		} catch ( \Throwable $e ) {
			error_log( 'BricksMCP: CSS regen failed for post ' . $post_id . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Validate Bricks element parent/children dual-linkage integrity.
	 *
	 * Checks:
	 * 1. Every element has required keys: id, name, parent, children
	 * 2. Element IDs are valid format: 6 lowercase alphanumeric chars
	 * 3. No duplicate element IDs
	 * 4. Every non-root element's parent exists in the elements array
	 * 5. Reciprocal check: parent's children array includes this element
	 * 6. No cycles (element cannot be its own ancestor)
	 *
	 * @param array<int, array<string, mixed>> $elements Flat array of elements.
	 * @return true|\WP_Error True if valid, WP_Error with code 'invalid_element_structure' on failure.
	 */
	public function validate_element_linkage( array $elements ): bool|\WP_Error {
		$id_map = [];

		// First pass: build ID map and check required keys and ID format.
		foreach ( $elements as $index => $element ) {
			// Check required keys.
			foreach ( [ 'id', 'name', 'parent', 'children' ] as $key ) {
				if ( ! array_key_exists( $key, $element ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element at index %d is missing required key "%s".', $index, $key ),
						[
							'path'   => "elements[{$index}]",
							'reason' => sprintf( 'Missing required key: "%s"', $key ),
						]
					);
				}
			}

			// Validate id is a string.
			if ( ! is_string( $element['id'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-string id.', $index ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => 'Element ID must be a string.',
					]
				);
			}

			// Validate ID format: 6 lowercase alphanumeric chars.
			if ( ! preg_match( '/^[a-z0-9]{6}$/', $element['id'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has an invalid ID format: "%s".', $index, $element['id'] ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => 'Element ID must be exactly 6 lowercase alphanumeric characters (a-z, 0-9).',
					]
				);
			}

			// Validate name is a string.
			if ( ! is_string( $element['name'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-string name.', $index ),
					[
						'path'   => "elements[{$index}].name",
						'reason' => 'Element name must be a string.',
					]
				);
			}

			// Validate children is an array.
			if ( ! is_array( $element['children'] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Element at index %d has a non-array children value.', $index ),
					[
						'path'   => "elements[{$index}].children",
						'reason' => 'Element children must be an array.',
					]
				);
			}

			// Check for duplicate IDs.
			if ( isset( $id_map[ $element['id'] ] ) ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Duplicate element ID "%s" found at index %d.', $element['id'], $index ),
					[
						'path'   => "elements[{$index}].id",
						'reason' => sprintf( 'Duplicate element ID: "%s" already used at index %d.', $element['id'], $id_map[ $element['id'] ] ),
					]
				);
			}

			$id_map[ $element['id'] ] = $index;
		}

		// Second pass: validate parent/children linkage.
		foreach ( $elements as $index => $element ) {
			$parent = $element['parent'];

			// Non-root elements (parent !== 0) must have a valid parent.
			if ( 0 !== $parent ) {
				$parent_str = (string) $parent;
				if ( ! isset( $id_map[ $parent_str ] ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" at index %d references non-existent parent "%s".', $element['id'], $index, $parent ),
						[
							'path'   => "elements[{$index}].parent",
							'reason' => sprintf( 'Parent element "%s" does not exist in the elements array.', $parent ),
						]
					);
				}

				// Reciprocal check: parent must list this element in its children.
				$parent_index    = $id_map[ $parent_str ];
				$parent_children = $elements[ $parent_index ]['children'];

				if ( ! in_array( $element['id'], $parent_children, true ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists parent "%s", but parent\'s children array does not include "%s".', $element['id'], $parent, $element['id'] ),
						[
							'path'   => "elements[{$index}].parent",
							'reason' => sprintf( 'Linkage mismatch: parent "%s" does not list "%s" in its children array.', $parent, $element['id'] ),
						]
					);
				}
			}

			// Validate children reciprocal: each child must list this element as parent.
			foreach ( $element['children'] as $child_index => $child_id ) {
				if ( ! isset( $id_map[ $child_id ] ) ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists non-existent child "%s".', $element['id'], $child_id ),
						[
							'path'   => "elements[{$index}].children[{$child_index}]",
							'reason' => sprintf( 'Child element "%s" does not exist in the elements array.', $child_id ),
						]
					);
				}

				$child_element = $elements[ $id_map[ $child_id ] ];
				if ( (string) $child_element['parent'] !== $element['id'] ) {
					return new \WP_Error(
						'invalid_element_structure',
						sprintf( 'Element "%s" lists "%s" as a child, but "%s" has a different parent.', $element['id'], $child_id, $child_id ),
						[
							'path'   => "elements[{$index}].children[{$child_index}]",
							'reason' => sprintf( 'Child "%s" does not list "%s" as its parent.', $child_id, $element['id'] ),
						]
					);
				}
			}
		}

		// Third pass: cycle detection using depth-first search.
		$visited  = [];
		$in_stack = [];

		foreach ( $elements as $element ) {
			if ( isset( $visited[ $element['id'] ] ) ) {
				continue;
			}

			$cycle_error = $this->detect_cycle( $element['id'], $elements, $id_map, $visited, $in_stack );
			if ( is_wp_error( $cycle_error ) ) {
				return $cycle_error;
			}
		}

		return true;
	}

	/**
	 * Detect cycles in element hierarchy using depth-first search.
	 *
	 * @param string                           $element_id  Current element ID.
	 * @param array<int, array<string, mixed>> $elements    All elements.
	 * @param array<string, int>               $id_map      Map of element ID to array index.
	 * @param array<string, bool>              $visited     Set of fully visited nodes.
	 * @param array<string, bool>              $in_stack    Set of nodes currently in recursion stack.
	 * @return true|\WP_Error True if no cycle, WP_Error if cycle detected.
	 */
	private function detect_cycle( string $element_id, array $elements, array $id_map, array &$visited, array &$in_stack ): bool|\WP_Error {
		$visited[ $element_id ]  = true;
		$in_stack[ $element_id ] = true;

		if ( ! isset( $id_map[ $element_id ] ) ) {
			$in_stack[ $element_id ] = false;
			return true;
		}

		$element = $elements[ $id_map[ $element_id ] ];

		foreach ( $element['children'] as $child_id ) {
			if ( ! isset( $visited[ $child_id ] ) ) {
				$result = $this->detect_cycle( $child_id, $elements, $id_map, $visited, $in_stack );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} elseif ( isset( $in_stack[ $child_id ] ) && $in_stack[ $child_id ] ) {
				return new \WP_Error(
					'invalid_element_structure',
					sprintf( 'Cycle detected: element "%s" is its own ancestor.', $child_id ),
					[
						'path'   => "elements[{$id_map[$element_id]}].children",
						'reason' => sprintf( 'Circular reference: "%s" creates a cycle in the element hierarchy.', $child_id ),
					]
				);
			}
		}

		$in_stack[ $element_id ] = false;
		return true;
	}

	/**
	 * Get all available responsive breakpoints.
	 *
	 * Returns breakpoint objects with key, label, width (px), and base indicator.
	 * Resolution: Bricks static property > bricks_breakpoints option > hardcoded defaults.
	 *
	 * @return array<int, array{key: string, label: string, width: int, base: bool}> Breakpoints list.
	 */
	public function get_breakpoints(): array {
		// 1. Try Bricks static Breakpoints class.
		if ( $this->is_bricks_active() && class_exists( '\Bricks\Breakpoints' ) ) {
			$bricks_bps = \Bricks\Breakpoints::$breakpoints ?? null;
			if ( is_array( $bricks_bps ) && ! empty( $bricks_bps ) ) {
				return $this->format_breakpoints( $bricks_bps );
			}
		}

		// 2. Try bricks_breakpoints option.
		$option_bps = get_option( 'bricks_breakpoints', [] );
		if ( is_array( $option_bps ) && ! empty( $option_bps ) ) {
			return $this->format_breakpoints( $option_bps );
		}

		// 3. Hardcoded defaults.
		return [
			[
				'key'   => 'desktop',
				'label' => 'Desktop',
				'width' => 1200,
				'base'  => true,
			],
			[
				'key'   => 'tablet_landscape',
				'label' => 'Tablet Landscape',
				'width' => 1024,
				'base'  => false,
			],
			[
				'key'   => 'tablet_portrait',
				'label' => 'Tablet Portrait',
				'width' => 768,
				'base'  => false,
			],
			[
				'key'   => 'mobile_landscape',
				'label' => 'Mobile Landscape',
				'width' => 480,
				'base'  => false,
			],
			[
				'key'   => 'mobile',
				'label' => 'Mobile',
				'width' => 0,
				'base'  => false,
			],
		];
	}

	/**
	 * Normalize Bricks breakpoint data into a standard format.
	 *
	 * Handles various formats Bricks may provide and normalizes to
	 * a consistent array of [key, label, width, base] objects.
	 *
	 * @param array<int|string, mixed> $breakpoints Raw breakpoint data from Bricks.
	 * @return array<int, array{key: string, label: string, width: int, base: bool}> Normalized breakpoints.
	 */
	private function format_breakpoints( array $breakpoints ): array {
		$result      = [];
		$has_desktop = false;

		foreach ( $breakpoints as $key => $bp ) {
			if ( is_array( $bp ) ) {
				$bp_key   = (string) ( $bp['key'] ?? $key );
				$bp_label = (string) ( $bp['label'] ?? ucwords( str_replace( '_', ' ', $bp_key ) ) );
				$bp_width = (int) ( $bp['width'] ?? 0 );
				$bp_base  = ! empty( $bp['base'] ) || 'desktop' === $bp_key;

				if ( $bp_base ) {
					$has_desktop = true;
				}

				$result[] = [
					'key'   => $bp_key,
					'label' => $bp_label,
					'width' => $bp_width,
					'base'  => $bp_base,
				];
			}
		}

		// If no breakpoint is marked as base, mark the first one.
		if ( ! $has_desktop && ! empty( $result ) ) {
			$result[0]['base'] = true;
		}

		return $result;
	}

	/**
	 * Get valid Bricks template type slugs.
	 *
	 * Returns the 9 core template types always. WooCommerce types are
	 * included when WooCommerce is active.
	 *
	 * @return array<int, string> Array of valid template type slugs.
	 */
	private function get_valid_template_types(): array {
		$types = [
			'header',
			'footer',
			'archive',
			'search',
			'error',
			'content',
			'section',
			'popup',
			'password_protection',
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$types = array_merge(
				$types,
				[
					'wc_product',
					'wc_archive',
					'wc_cart',
					'wc_cart_empty',
					'wc_checkout',
					'wc_account_form',
					'wc_account_page',
					'wc_thankyou',
				]
			);
		}

		return $types;
	}

	/**
	 * Create a new Bricks template.
	 *
	 * Inserts a bricks_template post, sets template type meta, enables Bricks editor,
	 * and optionally merges conditions into template settings.
	 *
	 * @param array<string, mixed> $args {
	 *     Template creation arguments.
	 *     @type string $title      Post title (required).
	 *     @type string $type       Template type slug (required, e.g., 'header', 'footer').
	 *     @type string $status     Post status (default: 'publish').
	 *     @type array  $conditions Optional Bricks condition objects to set on creation.
	 * }
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function create_template( array $args ): int|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'Template title is required. Provide a non-empty "title" parameter.', 'bricks-mcp' )
			);
		}

		if ( empty( $args['type'] ) ) {
			return new \WP_Error(
				'missing_type',
				__( 'Template type is required. Provide a "type" parameter (e.g., header, footer, content, section, popup).', 'bricks-mcp' )
			);
		}

		$type        = sanitize_key( $args['type'] );
		$valid_types = $this->get_valid_template_types();

		if ( ! in_array( $type, $valid_types, true ) ) {
			return new \WP_Error(
				'invalid_template_type',
				sprintf(
					/* translators: 1: Provided type, 2: Valid types list */
					__( 'Invalid template type "%1$s". Valid types: %2$s.', 'bricks-mcp' ),
					$type,
					implode( ', ', $valid_types )
				)
			);
		}

		$post_data = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_type'    => 'bricks_template',
			'post_status'  => sanitize_key( $args['status'] ?? 'publish' ),
			'post_content' => '',
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set template type meta.
		$this->unhook_bricks_meta_filters();
		try {
			update_post_meta( $post_id, '_bricks_template_type', $type );
		} finally {
			$this->rehook_bricks_meta_filters();
		}

		// Enable Bricks editor.
		$this->enable_bricks_editor( $post_id );

		// Set conditions if provided — merge into existing settings to preserve other keys.
		if ( ! empty( $args['conditions'] ) && is_array( $args['conditions'] ) ) {
			$this->unhook_bricks_meta_filters();
			try {
				$settings               = get_post_meta( $post_id, '_bricks_template_settings', true );
				$settings               = is_array( $settings ) ? $settings : [];
				$settings['conditions'] = $args['conditions'];
				update_post_meta( $post_id, '_bricks_template_settings', $settings );
			} finally {
				$this->rehook_bricks_meta_filters();
			}
		}

		return $post_id;
	}

	/**
	 * Update Bricks template metadata.
	 *
	 * Updates title, status, slug, type, tags, and bundles. Does not touch element content.
	 * Changing type returns a warning in the response.
	 *
	 * @param int                  $template_id Template post ID.
	 * @param array<string, mixed> $args        Fields to update: title, status, slug, type, tags, bundles.
	 * @return true|array<string, mixed>|\WP_Error True on success, array with warning when type changed, WP_Error on failure.
	 */
	public function update_template_meta( int $template_id, array $args ): array|\WP_Error {
		$post = get_post( $template_id );

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

		$post_data = [ 'ID' => $template_id ];
		$warning   = null;

		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $args['status'] );
		}

		if ( isset( $args['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $args['slug'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update template type if provided — validate and warn about potential incompatibilities.
		if ( isset( $args['type'] ) ) {
			$new_type    = sanitize_key( $args['type'] );
			$valid_types = $this->get_valid_template_types();

			if ( ! in_array( $new_type, $valid_types, true ) ) {
				return new \WP_Error(
					'invalid_template_type',
					sprintf(
						/* translators: 1: Provided type, 2: Valid types list */
						__( 'Invalid template type "%1$s". Valid types: %2$s.', 'bricks-mcp' ),
						$new_type,
						implode( ', ', $valid_types )
					)
				);
			}

			$old_type = get_post_meta( $template_id, '_bricks_template_type', true );
			if ( $old_type !== $new_type ) {
				$warning = sprintf(
					/* translators: 1: Old type, 2: New type */
					__( 'Template type changed from "%1$s" to "%2$s". Existing elements may need to be reviewed for compatibility with the new template slot.', 'bricks-mcp' ),
					$old_type,
					$new_type
				);
			}

			$this->unhook_bricks_meta_filters();
			try {
				update_post_meta( $template_id, '_bricks_template_type', $new_type );
			} finally {
				$this->rehook_bricks_meta_filters();
			}
		}

		// Update tags (template_tag taxonomy).
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			wp_set_object_terms( $template_id, $args['tags'], 'template_tag' );
		}

		// Update bundles (template_bundle taxonomy).
		if ( isset( $args['bundles'] ) && is_array( $args['bundles'] ) ) {
			wp_set_object_terms( $template_id, $args['bundles'], 'template_bundle' );
		}

		if ( null !== $warning ) {
			return [ 'warning' => $warning ];
		}

		return true;
	}

	/**
	 * Duplicate a Bricks template without conditions.
	 *
	 * Creates a draft copy of the template with all elements and meta.
	 * Conditions are stripped from the copy to prevent template slot conflicts.
	 *
	 * @param int $template_id Template post ID to duplicate.
	 * @return int|\WP_Error New template post ID on success, WP_Error on failure.
	 */
	public function duplicate_template( int $template_id ): int|\WP_Error {
		$post = get_post( $template_id );

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

		// Reuse existing duplicate_page logic — copies all meta including template type.
		$new_post_id = $this->duplicate_page( $template_id );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Strip conditions from the copy to prevent template slot conflicts.
		$this->unhook_bricks_meta_filters();
		try {
			$settings = get_post_meta( $new_post_id, '_bricks_template_settings', true );
			$settings = is_array( $settings ) ? $settings : [];
			unset( $settings['conditions'] );
			update_post_meta( $new_post_id, '_bricks_template_settings', $settings );
		} finally {
			$this->rehook_bricks_meta_filters();
		}

		return $new_post_id;
	}

	/**
	 * Get Bricks templates with metadata.
	 *
	 * Queries the `bricks_template` CPT for templates.
	 * Filterable by template type, status, tag, and bundle.
	 *
	 * @param string $type   Optional template type filter.
	 * @param string $status Post status filter (default: 'publish', accepts 'any', 'draft', 'trash').
	 * @param string $tag    Optional template_tag taxonomy slug filter.
	 * @param string $bundle Optional template_bundle taxonomy slug filter.
	 * @return array<int, array<string, mixed>> Array of template metadata.
	 */
	public function get_templates( string $type = '', string $status = 'publish', string $tag = '', string $bundle = '' ): array {
		$query_args = [
			'post_type'      => 'bricks_template',
			'post_status'    => '' !== $status ? sanitize_key( $status ) : 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		];

		$meta_query = [];
		if ( '' !== $type ) {
			$meta_query[] = [
				'key'   => '_bricks_template_type',
				'value' => sanitize_key( $type ),
			];
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$tax_query = [];
		if ( '' !== $tag ) {
			$tax_query[] = [
				'taxonomy' => 'template_tag',
				'field'    => 'slug',
				'terms'    => sanitize_key( $tag ),
			];
		}

		if ( '' !== $bundle ) {
			$tax_query[] = [
				'taxonomy' => 'template_bundle',
				'field'    => 'slug',
				'terms'    => sanitize_key( $bundle ),
			];
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query     = new \WP_Query( $query_args );
		$templates = [];

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$template_type = get_post_meta( $post->ID, '_bricks_template_type', true );
			$settings      = get_post_meta( $post->ID, '_bricks_template_settings', true );
			$elements      = $this->get_elements( $post->ID );

			// Get taxonomy terms.
			$tags_terms   = wp_get_object_terms( $post->ID, 'template_tag', [ 'fields' => 'slugs' ] );
			$bundle_terms = wp_get_object_terms( $post->ID, 'template_bundle', [ 'fields' => 'slugs' ] );

			$templates[] = [
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'status'        => $post->post_status,
				'type'          => ! empty( $template_type ) ? $template_type : 'content',
				'is_infobox'    => 'popup' === $template_type && ! empty( $settings['popupIsInfoBox'] ),
				'conditions'    => $this->format_conditions( $settings ),
				'element_count' => count( $elements ),
				'modified'      => $post->post_modified,
				'tags'          => is_array( $tags_terms ) ? $tags_terms : [],
				'bundles'       => is_array( $bundle_terms ) ? $bundle_terms : [],
			];
		}

		return $templates;
	}

	/**
	 * Get full Bricks template content with context.
	 *
	 * Returns the complete element data, template type, conditions,
	 * and global class names used by elements in the template.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|\WP_Error Template content or WP_Error if not found.
	 */
	public function get_template_content_data( int $template_id ): array|\WP_Error {
		$post = get_post( $template_id );

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

		$elements      = $this->get_elements( $template_id );
		$template_type = get_post_meta( $template_id, '_bricks_template_type', true );
		$settings      = get_post_meta( $template_id, '_bricks_template_settings', true );

		// Build class ID-to-name map.
		$global_classes = get_option( 'bricks_global_classes', [] );
		$class_map      = [];
		if ( is_array( $global_classes ) ) {
			foreach ( $global_classes as $class ) {
				if ( isset( $class['id'], $class['name'] ) ) {
					$class_map[ $class['id'] ] = $class['name'];
				}
			}
		}

		// Collect unique class names used across all elements.
		$used_class_names = [];
		foreach ( $elements as $element ) {
			$class_ids = $element['settings']['_cssGlobalClasses'] ?? [];
			if ( is_array( $class_ids ) ) {
				foreach ( $class_ids as $class_id ) {
					if ( isset( $class_map[ $class_id ] ) ) {
						$used_class_names[] = $class_map[ $class_id ];
					}
				}
			}
		}

		return [
			'id'           => $template_id,
			'title'        => $post->post_title,
			'type'         => ! empty( $template_type ) ? $template_type : 'content',
			'is_infobox'   => 'popup' === $template_type && ! empty( $settings['popupIsInfoBox'] ),
			'conditions'   => $this->format_conditions( $settings ),
			'elements'     => $elements,
			'classes_used' => array_values( array_unique( $used_class_names ) ),
		];
	}

	/**
	 * Format template conditions into human-readable strings.
	 *
	 * Converts raw Bricks template condition settings into an array of
	 * objects with a readable summary string and the raw condition data.
	 *
	 * @param mixed $settings Template settings (may be array, empty, or non-array).
	 * @return array<int, array{summary: string, raw: array}> Formatted conditions.
	 */
	public function format_conditions( mixed $settings ): array {
		if ( ! is_array( $settings ) || empty( $settings['conditions'] ) || ! is_array( $settings['conditions'] ) ) {
			return [];
		}

		$formatted = [];

		foreach ( $settings['conditions'] as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$main    = $condition['main'] ?? 'unknown';
			$summary = match ( $main ) {
				'ids'              => 'Specific posts: ' . implode( ', ', (array) ( $condition['ids'] ?? [] ) ),
				'postType'         => 'Post type: ' . ( $condition['postType'] ?? 'any' ),
				'any'              => 'Entire website',
				'frontpage'        => 'Front page',
				'archivePostType'  => 'Archive: ' . ( $condition['archivePostType'] ?? 'any' ),
				'terms'            => 'Terms: ' . implode( ', ', (array) ( $condition['terms'] ?? [] ) ),
				default            => 'Condition: ' . $main,
			};

			$formatted[] = [
				'summary' => $summary,
				'raw'     => $condition,
			];
		}

		return $formatted;
	}

	/**
	 * Get all global CSS classes, optionally filtered by search term.
	 *
	 * Reads from the `bricks_global_classes` WordPress option.
	 * Returns flat list of classes with id, name, and styles in Bricks composite key format.
	 *
	 * @param string $search Optional partial name match filter.
	 * @return array<int, array<string, mixed>> Array of global classes.
	 */
	public function get_global_classes( string $search = '', string $category = '' ): array {
		$classes = get_option( 'bricks_global_classes', [] );

		if ( ! is_array( $classes ) ) {
			return [];
		}

		if ( '' !== $category ) {
			$classes = array_filter(
				$classes,
				static fn( array $class ) => ( $class['category'] ?? '' ) === $category
			);
		}

		if ( '' !== $search ) {
			$classes = array_filter(
				$classes,
				static fn( array $class ) => false !== stripos( $class['name'] ?? '', $search )
			);
		}

		return array_values( $classes );
	}

	/**
	 * Create a new global CSS class.
	 *
	 * Generates a collision-free 6-char ID, validates name uniqueness,
	 * and appends the class to the bricks_global_classes option.
	 *
	 * @param array<string, mixed> $args {
	 *     Class creation arguments.
	 *     @type string $name     Class name (required).
	 *     @type string $color    Visual indicator color in Bricks editor (default: '#686868').
	 *     @type array  $styles   Bricks composite key styles (default: []).
	 *     @type string $category Category ID (optional).
	 * }
	 * @return array<string, mixed>|\WP_Error Created class array or WP_Error on failure.
	 */
	public function create_global_class( array $args ): array|\WP_Error {
		$classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		if ( empty( $args['name'] ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'Class name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		$name = sanitize_text_field( $args['name'] );

		if ( '' === $name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Class name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		// Check name uniqueness (case-sensitive).
		$existing_names = array_column( $classes, 'name' );
		if ( in_array( $name, $existing_names, true ) ) {
			return new \WP_Error(
				'duplicate_name',
				sprintf(
					/* translators: %s: Class name */
					__( 'A global class named "%s" already exists. Use update_global_class to modify it.', 'bricks-mcp' ),
					$name
				)
			);
		}

		// Generate collision-free ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $classes, 'id' );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		$new_class = [
			'id'     => $new_id,
			'name'   => $name,
			'color'  => isset( $args['color'] ) ? sanitize_text_field( $args['color'] ) : '#686868',
			'styles' => $this->sanitize_styles_array( $args['styles'] ?? [] ),
		];

		if ( ! empty( $args['category'] ) ) {
			$new_class['category'] = sanitize_text_field( $args['category'] );
		}

		$classes[] = $new_class;
		update_option( 'bricks_global_classes', $classes );
		update_option( 'bricks_global_classes_timestamp', time() );
		update_option( 'bricks_global_classes_user', get_current_user_id() );

		// Verify persistence — bypass object cache.
		wp_cache_delete( 'bricks_global_classes', 'options' );
		$stored = get_option( 'bricks_global_classes', null );
		if ( null === $stored || ! is_array( $stored ) ) {
			return new \WP_Error(
				'global_class_create_failed',
				__( 'Global class appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return $new_class;
	}

	/**
	 * Update an existing global CSS class.
	 *
	 * Finds the class by ID, updates provided fields, and writes back.
	 * Styles are merged by default; use replace_styles=true to overwrite entirely.
	 *
	 * @param string               $class_id Class ID to update.
	 * @param array<string, mixed> $args     Fields to update: name, color, category, styles, replace_styles.
	 * @return array<string, mixed>|\WP_Error Updated class array or WP_Error on failure.
	 */
	public function update_global_class( string $class_id, array $args ): array|\WP_Error {
		$classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		foreach ( $classes as &$class ) {
			if ( ( $class['id'] ?? '' ) !== $class_id ) {
				continue;
			}

			if ( isset( $args['name'] ) ) {
				$new_name = sanitize_text_field( $args['name'] );
				// Check name uniqueness excluding self.
				foreach ( $classes as $other ) {
					if ( ( $other['id'] ?? '' ) !== $class_id && ( $other['name'] ?? '' ) === $new_name ) {
						return new \WP_Error(
							'duplicate_name',
							sprintf(
								/* translators: %s: Class name */
								__( 'A global class named "%s" already exists.', 'bricks-mcp' ),
								$new_name
							)
						);
					}
				}
				$class['name'] = $new_name;
			}

			if ( isset( $args['color'] ) ) {
				$class['color'] = sanitize_text_field( $args['color'] );
			}

			if ( isset( $args['category'] ) ) {
				$class['category'] = sanitize_text_field( $args['category'] );
			}

			if ( isset( $args['styles'] ) ) {
				$sanitized_styles = $this->sanitize_styles_array( $args['styles'] );
				if ( ! empty( $args['replace_styles'] ) ) {
					$class['styles'] = $sanitized_styles;
				} else {
					$class['styles'] = array_merge( $class['styles'] ?? [], $sanitized_styles );
				}
			}

			update_option( 'bricks_global_classes', $classes );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );

			// Verify persistence — bypass object cache.
			wp_cache_delete( 'bricks_global_classes', 'options' );
			$stored = get_option( 'bricks_global_classes', null );
			if ( null === $stored || ! is_array( $stored ) ) {
				return new \WP_Error(
					'global_class_update_failed',
					__( 'Global class appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
				);
			}

			return $class;
		}
		unset( $class );

		return new \WP_Error(
			'class_not_found',
			sprintf(
				/* translators: %s: Class ID */
				__( 'Global class with ID "%s" not found.', 'bricks-mcp' ),
				$class_id
			)
		);
	}

	/**
	 * Soft-delete a global CSS class to trash.
	 *
	 * Moves the class from bricks_global_classes to bricks_global_classes_trash.
	 * Does not modify element references — use find_class_references() to discover usage.
	 *
	 * @param string $class_id Class ID to trash.
	 * @return true|\WP_Error True on success, WP_Error if class not found.
	 */
	public function trash_global_class( string $class_id ): bool|\WP_Error {
		$classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$trash = get_option( 'bricks_global_classes_trash', [] );
		if ( ! is_array( $trash ) ) {
			$trash = [];
		}

		$found = false;
		foreach ( $classes as $index => $class ) {
			if ( ( $class['id'] ?? '' ) === $class_id ) {
				$trash[] = $class;
				array_splice( $classes, $index, 1 );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'class_not_found',
				sprintf(
					/* translators: %s: Class ID */
					__( 'Global class with ID "%s" not found.', 'bricks-mcp' ),
					$class_id
				)
			);
		}

		update_option( 'bricks_global_classes', $classes );
		update_option( 'bricks_global_classes_trash', $trash );
		update_option( 'bricks_global_classes_timestamp', time() );
		update_option( 'bricks_global_classes_user', get_current_user_id() );

		// Verify persistence — bypass object cache.
		wp_cache_delete( 'bricks_global_classes', 'options' );
		$stored = get_option( 'bricks_global_classes', null );
		if ( null === $stored || ! is_array( $stored ) ) {
			return new \WP_Error(
				'global_class_trash_failed',
				__( 'Global class trash appeared to save but verification read-back failed. The database may have rejected the write.', 'bricks-mcp' )
			);
		}

		return true;
	}

	/**
	 * Find all posts that reference a global class by ID.
	 *
	 * Scans _bricks_page_content_2 meta across all posts for element
	 * _cssGlobalClasses arrays containing the given class ID.
	 * Hard-capped at 200 posts for performance.
	 *
	 * @param string $class_id Class ID to search for.
	 * @return array{references: array<int, array{post_id: int, title: string}>, truncated: bool} References data.
	 */
	public function find_class_references( string $class_id ): array {
		$query = new \WP_Query(
			[
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_bricks_page_content_2',
						'compare' => 'EXISTS',
					],
				],
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$truncated         = count( $query->posts ) >= 200;
		$referencing_posts = [];

		foreach ( $query->posts as $post_id ) {
			$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $element ) {
				$class_ids = $element['settings']['_cssGlobalClasses'] ?? [];
				if ( is_array( $class_ids ) && in_array( $class_id, $class_ids, true ) ) {
					$referencing_posts[] = [
						'post_id' => (int) $post_id,
						'title'   => get_the_title( $post_id ),
					];
					break; // Count each post once.
				}
			}
		}

		return [
			'references' => $referencing_posts,
			'truncated'  => $truncated,
		];
	}

	/**
	 * Create multiple global CSS classes in a single call.
	 *
	 * Reads the option once, validates and creates all valid items, writes once.
	 * Returns partial results: successfully created classes and errors for failed ones.
	 *
	 * @param array<int, array<string, mixed>> $class_definitions Array of class definition objects.
	 * @return array{created: array<int, array<string, mixed>>, errors: array<int|string, string>} Partial success result.
	 */
	public function batch_create_global_classes( array $class_definitions ): array {
		$classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$existing_ids   = array_column( $classes, 'id' );
		$existing_names = array_column( $classes, 'name' );
		$id_generator   = new ElementIdGenerator();
		$created        = [];
		$errors         = [];

		foreach ( $class_definitions as $index => $def ) {
			if ( empty( $def['name'] ) ) {
				$errors[ $index ] = 'Missing name';
				continue;
			}

			$name = sanitize_text_field( $def['name'] );

			if ( '' === $name ) {
				$errors[ $index ] = 'Empty name after sanitization';
				continue;
			}

			// Check name uniqueness against existing + already-created in this batch.
			if ( in_array( $name, $existing_names, true ) ) {
				$errors[ $index ] = sprintf( "Name '%s' already exists", $name );
				continue;
			}

			// Generate collision-free ID.
			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );
			$existing_ids[] = $new_id;

			$new_class = [
				'id'     => $new_id,
				'name'   => $name,
				'color'  => isset( $def['color'] ) ? sanitize_text_field( $def['color'] ) : '#686868',
				'styles' => $def['styles'] ?? [],
			];

			if ( ! empty( $def['category'] ) ) {
				$new_class['category'] = sanitize_text_field( $def['category'] );
			}

			$classes[]        = $new_class;
			$created[]        = $new_class;
			$existing_names[] = $name;
		}

		if ( ! empty( $created ) ) {
			update_option( 'bricks_global_classes', $classes );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );

			// Verify persistence — bypass object cache.
			wp_cache_delete( 'bricks_global_classes', 'options' );
			$stored = get_option( 'bricks_global_classes', null );
			if ( null === $stored || ! is_array( $stored ) ) {
				$errors['_readback'] = 'Batch create appeared to save but verification read-back failed.';
			}
		}

		return [
			'created' => $created,
			'errors'  => $errors,
		];
	}

	/**
	 * Soft-delete multiple global CSS classes to trash.
	 *
	 * Reads both options once, moves found classes to trash, writes once.
	 * Collects reference warnings for all successfully deleted classes.
	 *
	 * @param array<int, string> $class_ids Array of class IDs to trash.
	 * @return array{deleted: array<int, string>, errors: array<string, string>, references: array<int, array{post_id: int, title: string}>} Result data.
	 */
	public function batch_trash_global_classes( array $class_ids ): array {
		$classes = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$trash = get_option( 'bricks_global_classes_trash', [] );
		if ( ! is_array( $trash ) ) {
			$trash = [];
		}

		$deleted        = [];
		$errors         = [];
		$all_references = [];

		foreach ( $class_ids as $class_id ) {
			$found = false;
			foreach ( $classes as $index => $class ) {
				if ( ( $class['id'] ?? '' ) === $class_id ) {
					$trash[] = $class;
					array_splice( $classes, $index, 1 );
					$deleted[] = $class['name'] ?? $class_id;
					$found     = true;

					// Collect references for this class.
					$refs = $this->find_class_references( $class_id );
					foreach ( $refs['references'] as $ref ) {
						$all_references[] = $ref;
					}

					break;
				}
			}

			if ( ! $found ) {
				$errors[ $class_id ] = 'Class not found';
			}
		}

		if ( ! empty( $deleted ) ) {
			update_option( 'bricks_global_classes', $classes );
			update_option( 'bricks_global_classes_trash', $trash );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );

			// Verify persistence — bypass object cache.
			wp_cache_delete( 'bricks_global_classes', 'options' );
			$stored = get_option( 'bricks_global_classes', null );
			if ( null === $stored || ! is_array( $stored ) ) {
				$errors['_readback'] = 'Batch trash appeared to save but verification read-back failed.';
			}
		}

		return [
			'deleted'    => $deleted,
			'errors'     => $errors,
			'references' => $all_references,
		];
	}

	/**
	 * Get all global CSS class categories.
	 *
	 * @return array<int, array{id: string, name: string}> Category objects.
	 */
	public function get_global_class_categories(): array {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			return [];
		}

		return $categories;
	}

	/**
	 * Create a new global CSS class category.
	 *
	 * Generates a collision-free ID and appends the category.
	 *
	 * @param string $name Category name.
	 * @return array{id: string, name: string}|\WP_Error Created category or error.
	 */
	public function create_global_class_category( string $name ): array|\WP_Error {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Category name is required.', 'bricks-mcp' )
			);
		}

		// Check name uniqueness (case-sensitive).
		$existing_names = array_column( $categories, 'name' );
		if ( in_array( $sanitized_name, $existing_names, true ) ) {
			return new \WP_Error(
				'duplicate_name',
				sprintf(
					/* translators: %s: Category name */
					__( 'A category named "%s" already exists.', 'bricks-mcp' ),
					$sanitized_name
				)
			);
		}

		// Generate collision-free ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		$new_category = [
			'id'   => $new_id,
			'name' => $sanitized_name,
		];

		$categories[] = $new_category;
		update_option( 'bricks_global_classes_categories', $categories );

		return $new_category;
	}

	/**
	 * Delete a global CSS class category.
	 *
	 * Removes the category and unsets the category field on any classes
	 * that referenced the deleted category (moves them to uncategorized).
	 *
	 * @param string $category_id Category ID to delete.
	 * @return true|\WP_Error True on success, WP_Error if not found.
	 */
	public function delete_global_class_category( string $category_id ): bool|\WP_Error {
		$categories = get_option( 'bricks_global_classes_categories', [] );
		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$found = false;
		foreach ( $categories as $index => $category ) {
			if ( ( $category['id'] ?? '' ) === $category_id ) {
				array_splice( $categories, $index, 1 );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'category_not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category with ID "%s" not found. Use list_global_class_categories to find valid IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		update_option( 'bricks_global_classes_categories', $categories );

		// Clean up orphaned classes — remove category reference.
		$classes  = get_option( 'bricks_global_classes', [] );
		$modified = false;

		if ( is_array( $classes ) ) {
			foreach ( $classes as &$class ) {
				if ( ( $class['category'] ?? '' ) === $category_id ) {
					unset( $class['category'] );
					$modified = true;
				}
			}
			unset( $class );
		}

		if ( $modified ) {
			update_option( 'bricks_global_classes', $classes );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );
		}

		return true;
	}

	/**
	 * Import CSS class definitions from a raw CSS string.
	 *
	 * Parses class selectors, maps @media queries to Bricks breakpoint variants,
	 * maps :hover/:focus to state variants, and converts common CSS properties to
	 * Bricks structured style keys. Unmappable properties fall back to _cssCustom.
	 *
	 * @param string $css_string Raw CSS to parse.
	 * @return array{created: array, errors: array, mapped_properties: string[], custom_css_properties: string[]} Import results.
	 */
	public function import_classes_from_css( string $css_string ): array {
		$mapped_properties     = [];
		$custom_css_properties = [];
		$class_styles          = []; // class_name => [ 'key:breakpoint:pseudo' => value, ... ]

		// Step 1: Extract @media blocks and parse them separately.
		$media_blocks = [];
		$base_css     = preg_replace_callback(
			'/@media\s*\(([^)]+)\)\s*\{((?:[^{}]*|\{[^{}]*\})*)\}/s',
			static function ( array $matches ) use ( &$media_blocks ) {
				$media_blocks[] = [
					'query'   => trim( $matches[1] ),
					'content' => trim( $matches[2] ),
				];
				return '';
			},
			$css_string
		);

		// Step 2: Parse base CSS rules.
		$this->parse_css_rules( $base_css ?? '', '', $class_styles, $mapped_properties, $custom_css_properties );

		// Step 3: Parse media query blocks with breakpoint mapping.
		foreach ( $media_blocks as $block ) {
			$breakpoint = $this->resolve_media_query_to_breakpoint( $block['query'] );
			$this->parse_css_rules( $block['content'], $breakpoint, $class_styles, $mapped_properties, $custom_css_properties );
		}

		// Step 4: Build class definitions from parsed data.
		$class_definitions = [];
		foreach ( $class_styles as $class_name => $styles ) {
			$class_definitions[] = [
				'name'   => $class_name,
				'styles' => $styles,
			];
		}

		if ( empty( $class_definitions ) ) {
			return [
				'created'               => [],
				'errors'                => [],
				'mapped_properties'     => array_values( array_unique( $mapped_properties ) ),
				'custom_css_properties' => array_values( array_unique( $custom_css_properties ) ),
			];
		}

		// Step 5: Delegate to batch create.
		$result = $this->batch_create_global_classes( $class_definitions );

		$result['mapped_properties']     = array_values( array_unique( $mapped_properties ) );
		$result['custom_css_properties'] = array_values( array_unique( $custom_css_properties ) );

		return $result;
	}

	/**
	 * Parse CSS rules from a string into Bricks style keys.
	 *
	 * Extracts class selectors, splits pseudo-selectors, and maps CSS properties
	 * to Bricks composite keys with breakpoint/pseudo suffixes.
	 *
	 * @param string   $css                    CSS string to parse.
	 * @param string   $breakpoint             Bricks breakpoint key (empty for base).
	 * @param array    $class_styles           Reference to accumulated class styles map.
	 * @param string[] $mapped_properties      Reference to list of successfully mapped properties.
	 * @param string[] $custom_css_properties  Reference to list of custom CSS properties.
	 */
	private function parse_css_rules(
		string $css,
		string $breakpoint,
		array &$class_styles,
		array &$mapped_properties,
		array &$custom_css_properties
	): void {
		// Match: .class-name { declarations }
		// Also matches: .class-name:pseudo { declarations }
		preg_match_all(
			'/\.([a-zA-Z_-][\w-]*)(?::(\w+(?:-\w+)*))?[\s,]*\{([^}]*)\}/s',
			$css,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$class_name = $match[1];
			$pseudo     = $match[2] ?? '';
			$body       = trim( $match[3] );

			if ( '' === $body ) {
				continue;
			}

			// Parse declarations.
			$declarations = $this->parse_css_declarations( $body );

			foreach ( $declarations as $property => $value ) {
				$bricks_key = $this->css_property_to_bricks_key( $property, $value );

				if ( null === $bricks_key ) {
					// Unmappable — collect into _cssCustom.
					$custom_css_properties[] = $property;
					$suffix                  = $this->build_composite_suffix( $breakpoint, $pseudo );
					$custom_key              = '_cssCustom' . $suffix;

					if ( ! isset( $class_styles[ $class_name ] ) ) {
						$class_styles[ $class_name ] = [];
					}

					$existing_custom = $class_styles[ $class_name ][ $custom_key ] ?? '';
					$selector        = '.' . $class_name;
					if ( '' !== $pseudo ) {
						$selector .= ':' . $pseudo;
					}
					$class_styles[ $class_name ][ $custom_key ] = $existing_custom . $selector . ' { ' . $property . ': ' . $value . '; } ';
					continue;
				}

				$mapped_properties[] = $property;
				$suffix              = $this->build_composite_suffix( $breakpoint, $pseudo );

				if ( ! isset( $class_styles[ $class_name ] ) ) {
					$class_styles[ $class_name ] = [];
				}

				// Merge structured values (padding, margin, typography, etc.).
				$full_key = $bricks_key['key'] . $suffix;

				if ( is_array( $bricks_key['value'] ) ) {
					$existing                                 = $class_styles[ $class_name ][ $full_key ] ?? [];
					$class_styles[ $class_name ][ $full_key ] = array_merge(
						is_array( $existing ) ? $existing : [],
						$bricks_key['value']
					);
				} else {
					$class_styles[ $class_name ][ $full_key ] = $bricks_key['value'];
				}
			}
		}
	}

	/**
	 * Parse CSS declarations from a block body.
	 *
	 * @param string $body CSS declarations (without braces).
	 * @return array<string, string> Property => value map.
	 */
	private function parse_css_declarations( string $body ): array {
		$declarations = [];
		$parts        = explode( ';', $body );

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			$colon = strpos( $part, ':' );
			if ( false === $colon ) {
				continue;
			}

			$property = strtolower( trim( substr( $part, 0, $colon ) ) );
			$value    = trim( substr( $part, $colon + 1 ) );

			if ( '' !== $property && '' !== $value ) {
				$declarations[ $property ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * Map a CSS property and value to a Bricks style key.
	 *
	 * Returns null for unmappable properties (will fall back to _cssCustom).
	 *
	 * @param string $property CSS property name (lowercase).
	 * @param string $value    CSS property value.
	 * @return array{key: string, value: mixed}|null Bricks key and structured value, or null.
	 */
	private function css_property_to_bricks_key( string $property, string $value ): ?array {
		switch ( $property ) {
			// Spacing shorthand.
			case 'padding':
				return [
					'key'   => '_padding',
					'value' => $this->expand_spacing_shorthand( $value ),
				];
			case 'margin':
				return [
					'key'   => '_margin',
					'value' => $this->expand_spacing_shorthand( $value ),
				];

			// Spacing individual sides.
			case 'padding-top':
				return [
					'key'   => '_padding',
					'value' => [ 'top' => $value ],
				];
			case 'padding-right':
				return [
					'key'   => '_padding',
					'value' => [ 'right' => $value ],
				];
			case 'padding-bottom':
				return [
					'key'   => '_padding',
					'value' => [ 'bottom' => $value ],
				];
			case 'padding-left':
				return [
					'key'   => '_padding',
					'value' => [ 'left' => $value ],
				];
			case 'margin-top':
				return [
					'key'   => '_margin',
					'value' => [ 'top' => $value ],
				];
			case 'margin-right':
				return [
					'key'   => '_margin',
					'value' => [ 'right' => $value ],
				];
			case 'margin-bottom':
				return [
					'key'   => '_margin',
					'value' => [ 'bottom' => $value ],
				];
			case 'margin-left':
				return [
					'key'   => '_margin',
					'value' => [ 'left' => $value ],
				];

			// Background.
			case 'background-color':
				// Bricks color format is always an object: {hex:'#value'} or {raw:'var(--x)'}
				$color_val = ( str_starts_with( $value, 'var(' ) || str_starts_with( $value, 'rgba' ) || str_starts_with( $value, 'rgb(' ) || str_starts_with( $value, 'hsl' ) )
					? [ 'raw' => $value ] : [ 'hex' => $value ];
				return [ 'key' => '_background', 'value' => [ 'color' => $color_val ] ];

			// Text color.
			case 'color':
				// Bricks text color stored in _typography.color as an object
				$tc_val = ( str_starts_with( $value, 'var(' ) || str_starts_with( $value, 'rgba' ) || str_starts_with( $value, 'rgb(' ) || str_starts_with( $value, 'hsl' ) )
					? [ 'raw' => $value ] : [ 'hex' => $value ];
				return [ 'key' => '_typography', 'value' => [ 'color' => $tc_val ] ];

			// Typography.
			case 'font-size':
				return [
					'key'   => '_typography',
					'value' => [ 'font-size' => $value ],
				];
			case 'font-weight':
				return [
					'key'   => '_typography',
					'value' => [ 'font-weight' => $value ],
				];
			case 'line-height':
				return [
					'key'   => '_typography',
					'value' => [ 'line-height' => $value ],
				];
			case 'letter-spacing':
				return [
					'key'   => '_typography',
					'value' => [ 'letter-spacing' => $value ],
				];
			case 'font-style':
				return [
					'key'   => '_typography',
					'value' => [ 'font-style' => $value ],
				];
			case 'font-family':
				return [
					'key'   => '_typography',
					'value' => [ 'font-family' => $value ],
				];
			case 'text-transform':
				return [
					'key'   => '_typography',
					'value' => [ 'text-transform' => $value ],
				];
			case 'text-decoration':
				return [
					'key'   => '_typography',
					'value' => [ 'text-decoration' => $value ],
				];

			// Border radius.
			case 'border-radius':
				return [ 'key' => '_borderRadius', 'value' => $this->expand_spacing_shorthand( $value ) ];

			// Display.
			case 'display': return [ 'key' => '_display', 'value' => $value ];

			// Flex.
			case 'flex-direction': return [ 'key' => '_direction', 'value' => $value ];
			case 'align-items':    return [ 'key' => '_alignItems', 'value' => $value ];
			case 'justify-content': return [ 'key' => '_justifyContent', 'value' => $value ];
			case 'flex-grow':      return [ 'key' => '_flexGrow', 'value' => (int)$value ];
			case 'flex-shrink':    return [ 'key' => '_flexShrink', 'value' => (int)$value ];
			case 'gap':            return [ 'key' => '_gap', 'value' => $value ];

			// Dimensions.
			case 'width':      return [ 'key' => '_width', 'value' => $value ];
			case 'max-width':  return [ 'key' => '_widthMax', 'value' => $value ];
			case 'min-width':  return [ 'key' => '_widthMin', 'value' => $value ];
			case 'height':     return [ 'key' => '_height', 'value' => $value ];
			case 'max-height': return [ 'key' => '_heightMax', 'value' => $value ];
			case 'min-height': return [ 'key' => '_heightMin', 'value' => $value ];

			// Positioning.
			case 'position': return [ 'key' => '_position', 'value' => $value ];
			case 'z-index':  return [ 'key' => '_zIndex', 'value' => $value ];
			case 'top':      return [ 'key' => '_top', 'value' => $value ];
			case 'right':    return [ 'key' => '_right', 'value' => $value ];
			case 'bottom':   return [ 'key' => '_bottom', 'value' => $value ];
			case 'left':     return [ 'key' => '_left', 'value' => $value ];

			// Overflow, opacity.
			case 'overflow':   return [ 'key' => '_overflow', 'value' => $value ];
			case 'overflow-x': return [ 'key' => '_overflowX', 'value' => $value ];
			case 'overflow-y': return [ 'key' => '_overflowY', 'value' => $value ];
			case 'opacity':    return [ 'key' => '_opacity', 'value' => $value ];

			default:
				return null;
		}
	}

	/**
	 * Expand CSS spacing shorthand (1, 2, 3, or 4 values) into top/right/bottom/left.
	 *
	 * @param string $shorthand CSS shorthand value (e.g., '12px 24px', '10px').
	 * @return array{top: string, right: string, bottom: string, left: string} Expanded values.
	 */
	private function expand_spacing_shorthand( string $shorthand ): array {
		$parts = preg_split( '/\s+/', trim( $shorthand ) );

		if ( ! is_array( $parts ) || 0 === count( $parts ) ) {
			return [
				'top'    => $shorthand,
				'right'  => $shorthand,
				'bottom' => $shorthand,
				'left'   => $shorthand,
			];
		}

		switch ( count( $parts ) ) {
			case 1:
				return [
					'top'    => $parts[0],
					'right'  => $parts[0],
					'bottom' => $parts[0],
					'left'   => $parts[0],
				];
			case 2:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[0],
					'left'   => $parts[1],
				];
			case 3:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[2],
					'left'   => $parts[1],
				];
			default:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[2],
					'left'   => $parts[3],
				];
		}
	}

	/**
	 * Resolve a CSS media query string to a Bricks breakpoint key.
	 *
	 * Supports max-width and min-width queries with tolerance matching.
	 *
	 * @param string $query Media query string (e.g., 'max-width: 767px').
	 * @return string Bricks breakpoint key, or empty string for unmatchable queries.
	 */
	private function resolve_media_query_to_breakpoint( string $query ): string {
		// Bricks 2.x breakpoint keys (confirmed from Bricks\Breakpoints, v2.3.1):
		// desktop (base, 1279px), tablet_portrait (991px), mobile_landscape (767px), mobile_portrait (478px)
		$max_width_map = [
			478  => 'mobile_portrait',
			767  => 'mobile_landscape',
			768  => 'mobile_landscape',
			991  => 'tablet_portrait',
			1023 => 'tablet_portrait',
			1279 => 'desktop',
		];

		$min_width_map = [
			479  => 'mobile_landscape',
			768  => 'tablet_portrait',
			992  => 'desktop',
		];

		// Try max-width.
		if ( preg_match( '/max-width\s*:\s*(\d+)/', $query, $matches ) ) {
			$width = (int) $matches[1];

			// Exact match first.
			if ( isset( $max_width_map[ $width ] ) ) {
				return $max_width_map[ $width ];
			}

			// Closest match within 50px tolerance.
			$best_key  = '';
			$best_diff = 51;
			foreach ( $max_width_map as $px => $bp ) {
				$diff = abs( $width - $px );
				if ( $diff < $best_diff ) {
					$best_diff = $diff;
					$best_key  = $bp;
				}
			}

			return $best_key;
		}

		// Try min-width.
		if ( preg_match( '/min-width\s*:\s*(\d+)/', $query, $matches ) ) {
			$width = (int) $matches[1];

			// Desktop (1200+) is base — no suffix.
			if ( $width >= 1200 ) {
				return '';
			}

			// Exact match first.
			if ( isset( $min_width_map[ $width ] ) ) {
				return $min_width_map[ $width ];
			}

			// Closest match within 50px tolerance.
			$best_key  = '';
			$best_diff = 51;
			foreach ( $min_width_map as $px => $bp ) {
				$diff = abs( $width - $px );
				if ( $diff < $best_diff ) {
					$best_diff = $diff;
					$best_key  = $bp;
				}
			}

			return $best_key;
		}

		return '';
	}

	/**
	 * Build a Bricks composite key suffix from breakpoint and pseudo-state.
	 *
	 * @param string $breakpoint Bricks breakpoint key (e.g., 'mobile', 'tablet_portrait').
	 * @param string $pseudo     CSS pseudo-state (e.g., 'hover', 'focus').
	 * @return string Composite suffix (e.g., ':mobile', ':hover', ':mobile:hover', or '').
	 */
	private function build_composite_suffix( string $breakpoint, string $pseudo ): string {
		$suffix = '';

		if ( '' !== $breakpoint ) {
			$suffix .= ':' . $breakpoint;
		}

		if ( '' !== $pseudo ) {
			$suffix .= ':' . $pseudo;
		}

		return $suffix;
	}

	/**
	 * Resolve a global class name to its full class data.
	 *
	 * Performs an exact name match against the `bricks_global_classes` option.
	 * Returns the full class array (id, name, styles) or null if not found.
	 * This is the name-to-ID resolver: Bricks stores class references by ID,
	 * but users work by name.
	 *
	 * @param string $name Exact class name to resolve.
	 * @return array<string, mixed>|null Full class array or null if not found.
	 */
	public function resolve_class_name( string $name ): ?array {
		$classes = get_option( 'bricks_global_classes', [] );

		if ( ! is_array( $classes ) ) {
			return null;
		}

		foreach ( $classes as $class ) {
			if ( ( $class['name'] ?? '' ) === $name ) {
				return $class;
			}
		}

		return null;
	}

	/**
	 * Apply a global class to one or more elements on a page.
	 *
	 * Validates ALL element IDs exist before making any changes (fail-fast).
	 * Adds the class ID to each element's `_cssGlobalClasses` settings array
	 * if not already present. Saves via save_elements().
	 *
	 * @param int                $post_id     Post ID containing the elements.
	 * @param string             $class_id    Global class ID to apply.
	 * @param array<int, string> $element_ids Element IDs to apply the class to.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function apply_class_to_elements( int $post_id, string $class_id, array $element_ids ): bool|\WP_Error {
		$elements = $this->get_elements( $post_id );

		// Build element ID map for validation.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		// Validate ALL element IDs exist before applying any changes.
		$invalid_ids = [];
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $id_map[ $eid ] ) ) {
				$invalid_ids[] = $eid;
			}
		}

		if ( ! empty( $invalid_ids ) ) {
			return new \WP_Error(
				'invalid_element_ids',
				sprintf(
					/* translators: %s: Comma-separated list of invalid element IDs */
					__( 'Element IDs not found on post %1$d: %2$s. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
					$post_id,
					implode( ', ', $invalid_ids )
				)
			);
		}

		// Apply class to each element.
		foreach ( $element_ids as $eid ) {
			$index   = $id_map[ $eid ];
			$current = $elements[ $index ]['settings']['_cssGlobalClasses'] ?? [];

			if ( ! in_array( $class_id, $current, true ) ) {
				$current[] = $class_id;
				$elements[ $index ]['settings']['_cssGlobalClasses'] = $current;
			}
		}

		return $this->save_elements( $post_id, $elements );
	}

	/**
	 * Remove a global class from one or more elements on a page.
	 *
	 * Validates ALL element IDs exist before making any changes (fail-fast).
	 * Removes the class ID from each element's `_cssGlobalClasses` settings array.
	 * Saves via save_elements().
	 *
	 * @param int                $post_id     Post ID containing the elements.
	 * @param string             $class_id    Global class ID to remove.
	 * @param array<int, string> $element_ids Element IDs to remove the class from.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function remove_class_from_elements( int $post_id, string $class_id, array $element_ids ): bool|\WP_Error {
		$elements = $this->get_elements( $post_id );

		// Build element ID map for validation.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		// Validate ALL element IDs exist before removing any changes.
		$invalid_ids = [];
		foreach ( $element_ids as $eid ) {
			if ( ! isset( $id_map[ $eid ] ) ) {
				$invalid_ids[] = $eid;
			}
		}

		if ( ! empty( $invalid_ids ) ) {
			return new \WP_Error(
				'invalid_element_ids',
				sprintf(
					/* translators: %s: Comma-separated list of invalid element IDs */
					__( 'Element IDs not found on post %1$d: %2$s. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
					$post_id,
					implode( ', ', $invalid_ids )
				)
			);
		}

		// Remove class from each element.
		foreach ( $element_ids as $eid ) {
			$index   = $id_map[ $eid ];
			$current = $elements[ $index ]['settings']['_cssGlobalClasses'] ?? [];
			$current = array_values( array_filter( $current, static fn( string $cid ) => $cid !== $class_id ) );

			$elements[ $index ]['settings']['_cssGlobalClasses'] = $current;
		}

		return $this->save_elements( $post_id, $elements );
	}

	
	/**
	 * Get a human-readable visual layout tree for a page.
	 *
	 * Returns a nested tree with element IDs, names, labels, depth,
	 * global classes, text previews, and background color hints.
	 * Use this with view=visual on page:get to understand existing page structure before editing.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Visual layout tree.
	 */
	public function get_visual_layout( int $post_id ): array {
		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return [
				'tree'  => [],
				'total' => 0,
			];
		}

		// Build ID → element map and children map.
		$el_map      = [];
		$children_of = [];

		foreach ( $elements as $el ) {
			$id = $el['id'] ?? '';
			if ( ''  === $id ) {
				continue;
			}
			$el_map[ $id ] = $el;
		}

		foreach ( $elements as $el ) {
			$id     = $el['id'] ?? '';
			$parent = $el['parent'] ?? 0;
			if ( ''  === $id ) {
				continue;
			}
			$parent_key = ( 0 === $parent || '0' === $parent ) ? '__root__' : (string) $parent;
			$children_of[ $parent_key ][] = $id;
		}

		// Build tree from root elements.
		$root_ids = $children_of['__root__'] ?? [];
		$tree     = [];

		foreach ( $root_ids as $root_id ) {
			if ( isset( $el_map[ $root_id ] ) ) {
				$tree[] = $this->build_visual_node( $el_map[ $root_id ], $el_map, $children_of, 0 );
			}
		}

		return [
			'tree'  => $tree,
			'total' => count( $elements ),
		];
	}

	/**
	 * Build a single visual node for the layout tree.
	 *
	 * @param array<string, mixed>              $el          The element data.
	 * @param array<string, array<string,mixed>> $el_map      Map of all elements by ID.
	 * @param array<string, string[]>            $children_of Map of parent ID to child IDs.
	 * @param int                               $depth       Current nesting depth.
	 * @return array<string, mixed> Visual node.
	 */
	private function build_visual_node( array $el, array $el_map, array $children_of, int $depth ): array {
		$id       = $el['id'] ?? '';
		$name     = $el['name'] ?? 'unknown';
		$settings = $el['settings'] ?? [];

		// Label: prefer _label setting, fall back to element name.
		$label = $settings['_label'] ?? $name;

		// Global classes applied to this element.
		$css_classes = $settings['_cssGlobalClasses'] ?? [];
		if ( is_string( $css_classes ) ) {
			$css_classes = array_filter( array_map( 'trim', explode( ' ', $css_classes ) ) );
		}

		// Text preview from common text-bearing settings (max 80 chars).
		$text_preview = null;
		foreach ( [ 'text', 'content', 'heading', 'label' ] as $tk ) {
			if ( ! empty( $settings[ $tk ] ) && is_string( $settings[ $tk ] ) ) {
				$raw          = wp_strip_all_tags( $settings[ $tk ] );
				$text_preview = mb_strlen( $raw ) > 80 ? mb_substr( $raw, 0, 77 ) . '...' : $raw;
				break;
			}
		}

		// Background color hint.
		$bg_color = null;
		$bg       = $settings['_background'] ?? [];
		if ( is_array( $bg ) ) {
			$color = $bg['color'] ?? [];
			if ( is_array( $color ) ) {
				$bg_color = $color['hex'] ?? ( $color['raw'] ?? null );
			} elseif ( is_string( $color ) ) {
				$bg_color = $color;
			}
		}

		$node = [
			'id'    => $id,
			'name'  => $name,
			'label' => $label,
			'depth' => $depth,
		];

		if ( ! empty( $css_classes ) ) {
			$node['global_classes'] = array_values( $css_classes );
		}

		if ( null !== $text_preview ) {
			$node['text_preview'] = $text_preview;
		}

		if ( null !== $bg_color ) {
			$node['bg_color'] = $bg_color;
		}

		// Recurse into children.
		$child_ids = $children_of[ $id ] ?? [];
		if ( ! empty( $child_ids ) ) {
			$node['children'] = [];
			foreach ( $child_ids as $cid ) {
				if ( isset( $el_map[ $cid ] ) ) {
					$node['children'][] = $this->build_visual_node( $el_map[ $cid ], $el_map, $children_of, $depth + 1 );
				}
			}
		}

		return $node;
	}

/**
	 * Get a tree outline summary of a page's Bricks elements.
	 *
	 * Returns element names/IDs in tree structure with type counts.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Tree outline with type counts.
	 */
	public function get_page_summary( int $post_id ): array {
		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return [
				'type_counts' => [],
				'tree'        => [],
				'total'       => 0,
			];
		}

		// Count element types.
		$type_counts = [];
		$id_map      = [];

		foreach ( $elements as $index => $element ) {
			$name = $element['name'] ?? 'unknown';

			if ( ! isset( $type_counts[ $name ] ) ) {
				$type_counts[ $name ] = 0;
			}
			++$type_counts[ $name ];

			$id_map[ $element['id'] ] = $index;
		}

		// Build tree from root elements (parent === 0).
		$tree = [];
		foreach ( $elements as $element ) {
			if ( 0 === $element['parent'] ) {
				$tree[] = $this->build_tree_node( $element, $elements, $id_map, 0 );
			}
		}

		return [
			'type_counts' => $type_counts,
			'tree'        => $tree,
			'total'       => count( $elements ),
		];
	}

	/**
	 * Build a tree node for the page summary.
	 *
	 * @param array<string, mixed>             $element  The current element.
	 * @param array<int, array<string, mixed>> $elements All elements.
	 * @param array<string, int>               $id_map   Map of element ID to array index.
	 * @param int                              $depth    Current depth in the tree.
	 * @return array<string, mixed> Tree node with children.
	 */
	private function build_tree_node( array $element, array $elements, array $id_map, int $depth ): array {
		$node = [
			'id'    => $element['id'],
			'name'  => $element['name'] ?? 'unknown',
			'depth' => $depth,
		];

		if ( ! empty( $element['children'] ) ) {
			$node['children'] = [];
			foreach ( $element['children'] as $child_id ) {
				if ( isset( $id_map[ $child_id ] ) ) {
					$node['children'][] = $this->build_tree_node( $elements[ $id_map[ $child_id ] ], $elements, $id_map, $depth + 1 );
				}
			}
		}

		return $node;
	}

	/**
	 * Get standard metadata for a post.
	 *
	 * Returns title, status, slug, author, dates, featured image, and template.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed> Post metadata.
	 */
	public function get_page_metadata( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [];
		}

		return [
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'status'         => $post->post_status,
			'slug'           => $post->post_name,
			'type'           => $post->post_type,
			'permalink'      => get_permalink( $post->ID ),
			'author'         => [
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', (int) $post->post_author ),
			],
			'dates'          => [
				'created'  => $post->post_date,
				'modified' => $post->post_modified,
			],
			'featured_image' => get_the_post_thumbnail_url( $post->ID, 'full' ) ? get_the_post_thumbnail_url( $post->ID, 'full' ) : null,
			'template'       => get_page_template_slug( $post->ID ) ? get_page_template_slug( $post->ID ) : null,
		];
	}

	/**
	 * Get all post types that have Bricks editing enabled.
	 *
	 * Checks Bricks database settings if Bricks is active, falls back to defaults.
	 *
	 * @return array<int, string> Array of post type slugs.
	 */
	public function get_bricks_post_types(): array {
		if ( $this->is_bricks_active() && class_exists( '\Bricks\Database' ) ) {
			$post_types = \Bricks\Database::get_setting( 'postTypes' );
			if ( is_array( $post_types ) && ! empty( $post_types ) ) {
				return array_values( $post_types );
			}
		}

		return [ 'page', 'post' ];
	}

	/**
	 * Create a new post/page with Bricks editor enabled.
	 *
	 * Inserts the post, enables Bricks editor, optionally saves elements.
	 * Title is sanitized. Elements are normalized and validated before save.
	 *
	 * @param array<string, mixed> $args {
	 *     Page creation arguments.
	 *     @type string $title     Post title (required).
	 *     @type string $post_type Post type, default 'page'.
	 *     @type string $status    Post status, default 'draft'.
	 *     @type array  $elements  Optional initial elements (native or simplified format).
	 * }
	 * @return int|\WP_Error New post ID on success, WP_Error on failure.
	 */
	public function create_page( array $args ): int|\WP_Error {
		if ( empty( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'Post title is required. Provide a non-empty "title" parameter.', 'bricks-mcp' )
			);
		}

		$post_data = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_type'    => sanitize_key( $args['post_type'] ?? 'page' ),
			'post_status'  => sanitize_key( $args['status'] ?? 'draft' ),
			'post_content' => '',
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Always enable the Bricks editor.
		$this->enable_bricks_editor( $post_id );

		// Save elements if provided.
		if ( ! empty( $args['elements'] ) && is_array( $args['elements'] ) ) {
			$elements = $this->normalizer->normalize( $args['elements'] );
			$saved    = $this->save_elements( $post_id, $elements );

			if ( is_wp_error( $saved ) ) {
				// Clean up the post we just created.
				wp_delete_post( $post_id, true );
				return $saved;
			}
		}

		return $post_id;
	}

	/**
	 * Update WordPress post metadata (title, status, slug, featured image).
	 *
	 * Only updates fields present in $args. Does not touch Bricks content.
	 *
	 * @param int                  $post_id Post ID to update.
	 * @param array<string, mixed> $args    Fields to update: title, status, slug, featured_image.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_page_meta( int $post_id, array $args ): bool|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$post_data = [ 'ID' => $post_id ];

		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $args['status'] );
		}

		if ( isset( $args['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $args['slug'] );
		}

		// Only call wp_update_post if there is something to update beyond the ID.
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $args['featured_image'] ) ) {
			$attachment_id = (int) $args['featured_image'];
			if ( $attachment_id > 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		return true;
	}

	/**
	 * Move a post to trash.
	 *
	 * Does not permanently delete — post can be recovered from WordPress trash.
	 *
	 * @param int $post_id Post ID to trash.
	 * @return true|\WP_Error True on success, WP_Error if post not found.
	 */
	public function delete_page( int $post_id ): bool|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. The post may have already been deleted or the ID is incorrect.', 'bricks-mcp' ), $post_id )
			);
		}

		$trashed = wp_trash_post( $post_id );
		if ( ! $trashed ) {
			return new \WP_Error(
				'trash_failed',
				/* translators: %d: Post ID */
				sprintf( __( 'Failed to trash post %d. Check WordPress error logs for details.', 'bricks-mcp' ), $post_id )
			);
		}

		return true;
	}

	/**
	 * Duplicate a post including all Bricks content and meta.
	 *
	 * Creates a deep copy of the post. New post is always created as 'draft'
	 * with ' (Copy)' appended to the title. Copies ALL post meta including
	 * Bricks content and editor mode keys.
	 *
	 * @param int $post_id Post ID to duplicate.
	 * @return int|\WP_Error New post ID on success, WP_Error if original not found.
	 */
	public function duplicate_page( int $post_id ): int|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		// Create new post with same data but draft status.
		$new_post_data = [
			'post_title'   => $post->post_title . __( ' (Copy)', 'bricks-mcp' ),
			'post_type'    => $post->post_type,
			'post_status'  => 'draft',
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_author'  => $post->post_author,
		];

		$new_post_id = wp_insert_post( $new_post_data, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Copy all post meta.
		$all_meta = get_post_meta( $post_id );
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $meta_key => $meta_values ) {
				foreach ( $meta_values as $meta_value ) {
					$unserialized = maybe_unserialize( $meta_value );
					update_post_meta( $new_post_id, $meta_key, $unserialized );
				}
			}
		}

		return $new_post_id;
	}

	/**
	 * Get all available template condition types.
	 *
	 * Returns an array of known condition types with their metadata, keyed by the
	 * `main` value used in Bricks template settings. WooCommerce-specific types are
	 * included only when WooCommerce is active.
	 *
	 * @return array<string, mixed> Condition types with label, score, and extra_fields.
	 */
	public function get_condition_types(): array {
		$types = [
			'any'             => [
				'label'        => 'Entire website',
				'score'        => 2,
				'extra_fields' => [],
			],
			'frontpage'       => [
				'label'        => 'Front page',
				'score'        => 9,
				'extra_fields' => [],
			],
			'ids'             => [
				'label'        => 'Specific posts by ID',
				'score'        => 10,
				'extra_fields' => [
					'ids'             => 'array of post ID integers',
					'includeChildren' => 'bool (optional, include child pages)',
				],
			],
			'postType'        => [
				'label'        => 'All posts of post type',
				'score'        => 8,
				'extra_fields' => [
					'postType' => 'post type slug (e.g., post, page, product)',
				],
			],
			'terms'           => [
				'label'        => 'Specific taxonomy terms',
				'score'        => 8,
				'extra_fields' => [
					'terms' => 'array of "taxonomy::term_id" strings',
				],
			],
			'archivePostType' => [
				'label'        => 'Archive for post type',
				'score'        => 7,
				'extra_fields' => [
					'archivePostType' => 'post type slug',
				],
			],
			'archiveType'     => [
				'label'        => 'Archive by type',
				'score'        => 3,
				'extra_fields' => [
					'archiveType' => 'author|date|tag|category',
				],
			],
			'search'          => [
				'label'        => 'Search results page',
				'score'        => 8,
				'extra_fields' => [],
			],
			'error'           => [
				'label'        => '404 error page',
				'score'        => 8,
				'extra_fields' => [],
			],
		];

		if ( class_exists( 'WooCommerce' ) ) {
			$types['wc_product']      = [
				'label'        => 'WooCommerce single product',
				'score'        => 8,
				'extra_fields' => [],
			];
			$types['wc_archive']      = [
				'label'        => 'WooCommerce product archive',
				'score'        => 7,
				'extra_fields' => [],
			];
			$types['wc_cart']         = [
				'label'        => 'WooCommerce cart page',
				'score'        => 9,
				'extra_fields' => [],
			];
			$types['wc_cart_empty']   = [
				'label'        => 'WooCommerce empty cart',
				'score'        => 9,
				'extra_fields' => [],
			];
			$types['wc_checkout']     = [
				'label'        => 'WooCommerce checkout page',
				'score'        => 9,
				'extra_fields' => [],
			];
			$types['wc_account_form'] = [
				'label'        => 'WooCommerce account login/register form',
				'score'        => 9,
				'extra_fields' => [],
			];
			$types['wc_account_page'] = [
				'label'        => 'WooCommerce my account page',
				'score'        => 9,
				'extra_fields' => [],
			];
			$types['wc_thankyou']     = [
				'label'        => 'WooCommerce thank you page',
				'score'        => 9,
				'extra_fields' => [],
			];
		}

		return $types;
	}

	/**
	 * Set conditions on a Bricks template.
	 *
	 * Validates condition types against known types, then merges the conditions
	 * into the existing `_bricks_template_settings` — preserving all other keys
	 * (e.g., headerPosition, headerSticky). To remove all conditions, pass an empty array.
	 *
	 * @param int                              $template_id Template post ID.
	 * @param array<int, array<string, mixed>> $conditions  Array of Bricks condition objects.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function set_template_conditions( int $template_id, array $conditions ): bool|\WP_Error {
		$post = get_post( $template_id );

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

		// Validate each condition's main value against known types.
		$valid_types = array_keys( $this->get_condition_types() );

		foreach ( $conditions as $index => $condition ) {
			if ( ! is_array( $condition ) || ! isset( $condition['main'] ) ) {
				return new \WP_Error(
					'invalid_condition',
					sprintf(
						/* translators: %d: Condition index */
						__( 'Condition at index %d is missing required "main" key.', 'bricks-mcp' ),
						$index
					)
				);
			}

			if ( ! in_array( $condition['main'], $valid_types, true ) ) {
				return new \WP_Error(
					'invalid_condition_type',
					sprintf(
						/* translators: 1: Unknown type, 2: Valid types list */
						__( 'Unknown condition type "%1$s". Valid types: %2$s.', 'bricks-mcp' ),
						$condition['main'],
						implode( ', ', $valid_types )
					)
				);
			}
		}

		// Merge conditions into existing settings — preserve all other settings keys.
		$this->unhook_bricks_meta_filters();
		try {
			$settings               = get_post_meta( $template_id, '_bricks_template_settings', true );
			$settings               = is_array( $settings ) ? $settings : [];
			$settings['conditions'] = $conditions;
			update_post_meta( $template_id, '_bricks_template_settings', $settings );
		} finally {
			$this->rehook_bricks_meta_filters();
		}

		return true;
	}

	/**
	 * Resolve which Bricks templates would apply to a specific post.
	 *
	 * Queries all published bricks_template posts, evaluates each condition
	 * against the given post's context (type, front page status, terms), and
	 * returns a scoring-based resolution grouped by template type.
	 *
	 * Note: Archive, search, and error templates cannot be resolved by post_id —
	 * those contexts do not correspond to a single post.
	 *
	 * @param int $post_id Post ID to resolve templates for.
	 * @return array<string, mixed>|\WP_Error Resolution data or WP_Error if post not found.
	 */
	public function resolve_templates_for_post( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$post_type     = $post->post_type;
		$front_page_id = (int) get_option( 'page_on_front' );
		$is_front_page = $front_page_id > 0 && $post_id === $front_page_id;

		// Get all taxonomy terms for this post.
		$post_terms     = [];
		$all_taxonomies = get_object_taxonomies( $post_type );
		foreach ( $all_taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'id=>slug' ] );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term_id => $term_slug ) {
					$post_terms[] = $taxonomy . '::' . $term_id;
				}
			}
		}

		// Query ALL published bricks_template posts.
		$template_query = new \WP_Query(
			[
				'post_type'      => 'bricks_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);

		// Tracks candidates per template type.
		// $candidates[$type][] = ['template' => [...], 'score' => int]
		$candidates = [];

		foreach ( $template_query->posts as $tpl_post ) {
			if ( ! $tpl_post instanceof \WP_Post ) {
				continue;
			}

			$tpl_type     = get_post_meta( $tpl_post->ID, '_bricks_template_type', true );
			$tpl_type     = ! empty( $tpl_type ) ? (string) $tpl_type : 'content';
			$tpl_settings = get_post_meta( $tpl_post->ID, '_bricks_template_settings', true );
			$conditions   = ( is_array( $tpl_settings ) && ! empty( $tpl_settings['conditions'] ) ) ? $tpl_settings['conditions'] : [];

			if ( empty( $conditions ) ) {
				continue;
			}

			$max_score = 0;

			foreach ( $conditions as $condition ) {
				if ( ! is_array( $condition ) || ! isset( $condition['main'] ) ) {
					continue;
				}

				$score = $this->evaluate_condition_score( $condition, $post_id, $post_type, $is_front_page, $post_terms );

				if ( $score > $max_score ) {
					$max_score = $score;
				}
			}

			if ( $max_score > 0 ) {
				if ( ! isset( $candidates[ $tpl_type ] ) ) {
					$candidates[ $tpl_type ] = [];
				}

				$candidates[ $tpl_type ][] = [
					'template' => [
						'id'    => $tpl_post->ID,
						'title' => $tpl_post->post_title,
					],
					'score'    => $max_score,
				];
			}
		}

		// For each type, pick winner (highest score) and list all candidates.
		$resolved = [];

		foreach ( $candidates as $tpl_type => $type_candidates ) {
			usort( $type_candidates, static fn( array $a, array $b ) => $b['score'] - $a['score'] );

			$resolved[ $tpl_type ] = [
				'active'     => $type_candidates[0]['template'] + [ 'score' => $type_candidates[0]['score'] ],
				'candidates' => $type_candidates,
			];
		}

		return [
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'resolved'  => $resolved,
			'note'      => 'Resolution shows templates matching this specific post. Archive/search/error templates cannot be resolved by post_id.',
		];
	}

	/**
	 * Evaluate how well a single condition matches the given post context.
	 *
	 * Returns the condition's score if it matches, or 0 if it does not match.
	 *
	 * @param array<string, mixed> $condition    Condition array with 'main' key.
	 * @param int                  $post_id      Post ID being evaluated.
	 * @param string               $post_type    Post type slug.
	 * @param bool                 $is_front_page Whether this post is the front page.
	 * @param array<int, string>   $post_terms   Array of "taxonomy::term_id" strings for this post.
	 * @return int Score (0 = no match).
	 */
	private function evaluate_condition_score( array $condition, int $post_id, string $post_type, bool $is_front_page, array $post_terms ): int {
		$main = $condition['main'];

		switch ( $main ) {
			case 'any':
				return 2;

			case 'frontpage':
				return $is_front_page ? 9 : 0;

			case 'ids':
				$ids = isset( $condition['ids'] ) && is_array( $condition['ids'] ) ? $condition['ids'] : [];
				return in_array( $post_id, array_map( 'intval', $ids ), true ) ? 10 : 0;

			case 'postType':
				$required_type = $condition['postType'] ?? '';
				return $post_type === $required_type ? 8 : 0;

			case 'terms':
				$required_terms = isset( $condition['terms'] ) && is_array( $condition['terms'] ) ? $condition['terms'] : [];
				foreach ( $required_terms as $term_ref ) {
					if ( in_array( $term_ref, $post_terms, true ) ) {
						return 8;
					}
				}
				return 0;

			// Archive/search/error conditions cannot match a specific post_id.
			case 'archivePostType':
			case 'archiveType':
			case 'search':
			case 'error':
				return 0;

			default:
				return 0;
		}
	}

	/**
	 * Add a single element to an existing Bricks page.
	 *
	 * Gets current elements, normalizes the new element via ElementNormalizer,
	 * merges into existing, validates linkage, then saves.
	 * Supports both simplified and native format for the new element.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $element   Element data (simplified or native format).
	 * @param string               $parent_id Parent element ID ('0' for root).
	 * @param int|null             $position  Position in parent's children array (null = append at end).
	 * @return array<string, mixed>|\WP_Error Array with element_id on success, WP_Error on failure.
	 */
	public function add_element( int $post_id, array $element, string $parent_id = '0', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$existing = $this->get_elements( $post_id );

		// Wrap single element in array for normalization.
		$input     = [ $element ];
		$parent    = '0' !== $parent_id ? $parent_id : 0;
		$new_input = array_map(
			static function ( array $el ) use ( $parent ) {
				if ( ! array_key_exists( 'parent', $el ) ) {
					$el['parent'] = $parent;
				}
				return $el;
			},
			$input
		);

		$normalized = $this->normalizer->normalize( $new_input, $existing );

		// For simplified format output, set parent_id correctly on top-level elements.
		if ( ! empty( $normalized ) ) {
			$normalized[0]['parent'] = '0' !== $parent_id ? $parent_id : 0;

			// Regenerate children IDs in first element to correct any issues.
			$child_ids      = [];
			$normalized_len = count( $normalized );
			for ( $i = 1; $i < $normalized_len; $i++ ) {
				if ( (string) $normalized[ $i ]['parent'] === $normalized[0]['id'] ) {
					$child_ids[] = $normalized[ $i ]['id'];
				}
			}
			$normalized[0]['children'] = $child_ids;
		}

		$merged = $this->normalizer->merge_elements( $existing, $normalized, $parent_id, $position );
		$saved  = $this->save_elements( $post_id, $merged );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'element_id'    => $normalized[0]['id'] ?? '',
			'post_id'       => $post_id,
			'element_count' => count( $merged ),
		];
	}

	/**
	 * Update settings for a specific element on a Bricks page.
	 *
	 * Finds element by ID in the flat array, merges new settings into existing,
	 * validates, and saves. Returns error if element not found.
	 *
	 * @param int                  $post_id    Post ID.
	 * @param string               $element_id Element ID to update.
	 * @param array<string, mixed> $settings   Settings to merge with existing.
	 * @return array<string, mixed>|\WP_Error Updated info on success, WP_Error on failure.
	 */
	public function update_element( int $post_id, string $element_id, array $settings ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );
		$found    = false;

		foreach ( $elements as $index => $element ) {
			if ( $element['id'] === $element_id ) {
				$existing_settings              = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
				$elements[ $index ]['settings'] = array_merge( $existing_settings, $settings );
				$found                          = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'element_id'             => $element_id,
			'updated_settings_count' => count( $settings ),
		];
	}

	/**
	 * Get all terms for a Bricks template taxonomy.
	 *
	 * Returns term_id, name, slug, and count for each term.
	 * If the taxonomy is not registered (Bricks not active), returns empty array.
	 *
	 * @param string $taxonomy Taxonomy slug ('template_tag' or 'template_bundle').
	 * @return array<int, array{term_id: int, name: string, slug: string, count: int}>|\WP_Error Terms list or WP_Error for invalid taxonomy.
	 */
	public function get_template_terms( string $taxonomy ): array|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: Taxonomy slug */
					__( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ),
					$taxonomy
				)
			);
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			// Taxonomy not registered — Bricks not active or older version.
			return [];
		}

		return array_map(
			static function ( \WP_Term $term ): array {
				return [
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'count'   => $term->count,
				];
			},
			$terms
		);
	}

	/**
	 * Create a new term in a Bricks template taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug ('template_tag' or 'template_bundle').
	 * @param string $name     Term name.
	 * @return array{term_id: int, name: string, slug: string}|\WP_Error Term data on success, WP_Error on failure.
	 */
	public function create_template_term( string $taxonomy, string $name ): array|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: Taxonomy slug */
					__( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ),
					$taxonomy
				)
			);
		}

		$sanitized_name = sanitize_text_field( $name );
		$result         = wp_insert_term( $sanitized_name, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'term_id' => (int) $result['term_id'],
			'name'    => $sanitized_name,
			'slug'    => sanitize_title( $sanitized_name ),
		];
	}

	/**
	 * Delete a term from a Bricks template taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug ('template_tag' or 'template_bundle').
	 * @param int    $term_id  Term ID to delete.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function delete_template_term( string $taxonomy, int $term_id ): bool|\WP_Error {
		if ( ! in_array( $taxonomy, [ 'template_tag', 'template_bundle' ], true ) ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: Taxonomy slug */
					__( 'Invalid taxonomy "%s". Must be "template_tag" or "template_bundle".', 'bricks-mcp' ),
					$taxonomy
				)
			);
		}

		$result = wp_delete_term( $term_id, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new \WP_Error(
				'term_not_found',
				sprintf(
					/* translators: %d: Term ID */
					__( 'Term %d not found. Use list_template_tags or list_template_bundles to find valid term IDs.', 'bricks-mcp' ),
					$term_id
				)
			);
		}

		if ( 0 === $result ) {
			return new \WP_Error(
				'cannot_delete_default_term',
				sprintf(
					/* translators: %d: Term ID */
					__( 'Cannot delete term %d because it is the default term for this taxonomy.', 'bricks-mcp' ),
					$term_id
				)
			);
		}

		return true;
	}

	/**
	 * Remove an element from a Bricks page.
	 *
	 * Removes element by ID. Children of the removed element are re-parented
	 * to the removed element's parent, maintaining hierarchy integrity.
	 * Removes the element from its parent's children array.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $element_id Element ID to remove.
	 * @return array<string, mixed>|\WP_Error Removal info on success, WP_Error on failure.
	 */
	public function remove_element( int $post_id, string $element_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		// Find the element to remove.
		$target_index = null;
		$target       = null;

		foreach ( $elements as $index => $element ) {
			if ( $element['id'] === $element_id ) {
				$target_index = $index;
				$target       = $element;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		$target_parent   = $target['parent'];
		$target_children = $target['children'];

		// Re-parent children of the removed element to the removed element's parent.
		$updated_elements = [];
		foreach ( $elements as $index => $element ) {
			if ( $index === $target_index ) {
				// Skip the element being removed.
				continue;
			}

			// If this element is a child of the removed element, re-parent it.
			if ( in_array( $element['id'], $target_children, true ) ) {
				$element['parent'] = $target_parent;
			}

			// If this element is the parent, update its children array.
			if ( $element['id'] === (string) $target_parent ) {
				// Remove target from parent's children.
				$element['children'] = array_values(
					array_filter(
						$element['children'],
						static fn( string $cid ) => $cid !== $element_id
					)
				);
				// Add target's children to parent's children.
				$element['children'] = array_values(
					array_unique( array_merge( $element['children'], $target_children ) )
				);
			}

			$updated_elements[] = $element;
		}

		$saved = $this->save_elements( $post_id, $updated_elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'removed_element_id' => $element_id,
			'post_id'            => $post_id,
			'element_count'      => count( $updated_elements ),
		];
	}

	/**
	 * Move or reorder a Bricks element within a page's element tree.
	 *
	 * Supports both reparenting (changing parent) and reordering (changing position
	 * within same parent). Moving a parent element moves its entire subtree automatically
	 * since children reference their parent by ID.
	 *
	 * @param int      $post_id          Post ID.
	 * @param string   $element_id       Element ID to move.
	 * @param string   $target_parent_id Target parent ID ('' to keep current parent for reorder-only, '0' for root).
	 * @param int|null $position         0-indexed position among siblings (null to append at end).
	 * @return array<string, mixed>|\WP_Error Move result or WP_Error on failure.
	 */
	public function move_element( int $post_id, string $element_id, string $target_parent_id = '', ?int $position = null ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return new \WP_Error(
				'no_elements',
				sprintf(
					/* translators: %d: Post ID */
					__( 'No Bricks elements found on post %d.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		// Build id_map for O(1) lookups.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		// Validate element exists.
		if ( ! isset( $id_map[ $element_id ] ) ) {
			return new \WP_Error(
				'element_not_found',
				sprintf(
					/* translators: 1: Element ID, 2: Post ID */
					__( 'Element "%1$s" not found on post %2$d. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
					$element_id,
					$post_id
				)
			);
		}

		// Determine effective target parent.
		// '' (empty string) = reorder-only, keep current parent.
		// '0' = move to root level.
		// Otherwise = move to specified parent.
		$old_parent = $elements[ $id_map[ $element_id ] ]['parent'];

		if ( '' === $target_parent_id ) {
			// Reorder-only: keep current parent.
			$effective_target = $old_parent;
		} elseif ( '0' === $target_parent_id ) {
			// Move to root.
			$effective_target = 0;
		} else {
			// Validate target parent exists.
			if ( ! isset( $id_map[ $target_parent_id ] ) ) {
				return new \WP_Error(
					'parent_not_found',
					sprintf(
						/* translators: %s: Parent element ID */
						__( 'Target parent element "%s" not found. Use get_bricks_content to retrieve valid element IDs.', 'bricks-mcp' ),
						$target_parent_id
					)
				);
			}
			$effective_target = $target_parent_id;
		}

		// Remove element_id from old parent's children array (if old parent is not root).
		if ( 0 !== $old_parent && '' !== (string) $old_parent ) {
			$old_parent_str = (string) $old_parent;
			if ( isset( $id_map[ $old_parent_str ] ) ) {
				$old_parent_idx                          = $id_map[ $old_parent_str ];
				$elements[ $old_parent_idx ]['children'] = array_values(
					array_filter(
						$elements[ $old_parent_idx ]['children'],
						static fn( string $cid ) => $cid !== $element_id
					)
				);
			}
		}

		// Update element's parent field (reorder-only leaves parent unchanged).
		if ( '0' === $target_parent_id ) {
			$elements[ $id_map[ $element_id ] ]['parent'] = 0;
		} elseif ( '' !== $target_parent_id ) {
			$elements[ $id_map[ $element_id ] ]['parent'] = $target_parent_id;
		}

		// Insert into new parent's children array.
		if ( 0 === $effective_target || '0' === (string) $effective_target ) {
			// Root-level: reposition element within the flat array among root elements.
			// Extract element from current position.
			$el = array_splice( $elements, $id_map[ $element_id ], 1 )[0];

			// Rebuild id_map since indices shifted.
			$id_map = [];
			foreach ( $elements as $idx => $elem ) {
				$id_map[ $elem['id'] ] = $idx;
			}

			if ( null === $position ) {
				// Append after the last root element.
				$last_root_idx = -1;
				foreach ( $elements as $idx => $elem ) {
					if ( 0 === $elem['parent'] ) {
						$last_root_idx = $idx;
					}
				}
				array_splice( $elements, $last_root_idx + 1, 0, [ $el ] );
			} else {
				// Count root elements to find correct flat array insertion point.
				$root_count      = 0;
				$insertion_point = count( $elements ); // Default: append.
				foreach ( $elements as $idx => $elem ) {
					if ( 0 === $elem['parent'] ) {
						if ( $root_count === $position ) {
							$insertion_point = $idx;
							break;
						}
						++$root_count;
					}
				}
				array_splice( $elements, $insertion_point, 0, [ $el ] );
			}
		} else {
			// Non-root target: update target parent's children array.
			$target_parent_idx = $id_map[ (string) $effective_target ];
			if ( null === $position ) {
				$elements[ $target_parent_idx ]['children'][] = $element_id;
			} else {
				array_splice( $elements[ $target_parent_idx ]['children'], $position, 0, [ $element_id ] );
			}
		}

		// Save (validate_element_linkage runs automatically inside save_elements).
		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Rebuild id_map to get accurate new_parent from post-save state.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$moved_element = $elements[ $id_map[ $element_id ] ];

		return [
			'element_id'    => $element_id,
			'old_parent'    => $old_parent,
			'new_parent'    => $moved_element['parent'],
			'position'      => $position,
			'subtree_moved' => ! empty( $moved_element['children'] ),
		];
	}

	/**
	 * Bulk update settings on multiple elements in a single call.
	 *
	 * Applies all valid updates in memory, then saves once. Uses partial-success
	 * model: each item returns individual success/error status.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates Array of {element_id: string, settings: array} objects.
	 * @return array<string, mixed>|\WP_Error Partial result or WP_Error if all fail.
	 */
	public function bulk_update_elements( int $post_id, array $updates ): array|\WP_Error {
		if ( count( $updates ) > 50 ) {
			return new \WP_Error(
				'batch_too_large',
				__( 'Maximum 50 element updates per call. Split into multiple calls.', 'bricks-mcp' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				/* translators: %d: Post ID */
				sprintf( __( 'Post %d not found. Verify the post_id and try again.', 'bricks-mcp' ), $post_id )
			);
		}

		$elements = $this->get_elements( $post_id );

		if ( empty( $elements ) ) {
			return new \WP_Error(
				'no_elements',
				sprintf(
					/* translators: %d: Post ID */
					__( 'No Bricks elements found on post %d.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		// Build id_map for O(1) lookups.
		$id_map = [];
		foreach ( $elements as $index => $element ) {
			$id_map[ $element['id'] ] = $index;
		}

		$success = [];
		$errors  = [];

		foreach ( $updates as $update ) {
			$upd_element_id = $update['element_id'] ?? '';
			$upd_settings   = $update['settings'] ?? [];

			if ( '' === $upd_element_id ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Missing element_id',
				];
				continue;
			}

			if ( empty( $upd_settings ) ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Missing settings',
				];
				continue;
			}

			if ( ! isset( $id_map[ $upd_element_id ] ) ) {
				$errors[] = [
					'element_id' => $upd_element_id,
					'error'      => 'Element not found',
				];
				continue;
			}

			$idx      = $id_map[ $upd_element_id ];
			$existing = $elements[ $idx ]['settings'] ?? [];

			$elements[ $idx ]['settings'] = array_merge( $existing, $upd_settings );

			$success[] = [
				'element_id' => $upd_element_id,
				'status'     => 'updated',
			];
		}

		if ( empty( $success ) ) {
			return new \WP_Error(
				'all_failed',
				__( 'All element updates failed. Check element IDs and settings.', 'bricks-mcp' )
			);
		}

		$saved = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'success' => $success,
			'errors'  => $errors,
			'summary' => [
				'total'     => count( $updates ),
				'succeeded' => count( $success ),
				'failed'    => count( $errors ),
			],
		];
	}

	/**
	 * Get all global theme styles.
	 *
	 * Reads the `bricks_theme_styles` option and returns all styles
	 * formatted via format_theme_style_response().
	 *
	 * @return array<int, array<string, mixed>> Array of formatted theme style objects.
	 */
	public function get_theme_styles(): array {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			return [];
		}

		$result = [];

		foreach ( $styles as $style_id => $style ) {
			$result[] = $this->format_theme_style_response( (string) $style_id, $style );
		}

		return $result;
	}

	/**
	 * Get a single theme style by ID.
	 *
	 * @param string $style_id Theme style ID.
	 * @return array<string, mixed>|\WP_Error Formatted theme style or WP_Error if not found.
	 */
	public function get_theme_style( string $style_id ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		return $this->format_theme_style_response( $style_id, $styles[ $style_id ] );
	}

	/**
	 * Create a new global theme style.
	 *
	 * Generates a collision-free ID, builds the style entry with label,
	 * optional settings, and optional conditions, then writes to the option.
	 *
	 * @param string               $label      Style label (required).
	 * @param array<string, mixed> $settings   Settings organized by group (optional).
	 * @param array<int, mixed>    $conditions Condition objects (optional).
	 * @return array<string, mixed>|\WP_Error Created style or WP_Error on failure.
	 */
	public function create_theme_style( string $label, array $settings = [], array $conditions = [] ): array|\WP_Error {
		$sanitized_label = sanitize_text_field( $label );

		if ( '' === $sanitized_label ) {
			return new \WP_Error(
				'missing_label',
				__( 'Theme style label is required. Provide a non-empty "label" parameter.', 'bricks-mcp' )
			);
		}

		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		// Generate collision-free ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_keys( $styles );
		do {
			$new_id = $id_generator->generate();
		} while ( in_array( $new_id, $existing_ids, true ) );

		// Build style entry.
		$style = [
			'label'    => $sanitized_label,
			'settings' => $settings,
		];

		// Add conditions if provided.
		if ( ! empty( $conditions ) ) {
			$style['settings']['conditions'] = [ 'conditions' => $conditions ];
		}

		$styles[ $new_id ] = $style;
		update_option( 'bricks_theme_styles', $styles );

		return $this->format_theme_style_response( $new_id, $styles[ $new_id ] );
	}

	/**
	 * Update an existing theme style with partial merge support.
	 *
	 * Supports deep-merge (default) or section-replace for settings groups.
	 * Returns before/after snapshots and site-wide active detection.
	 *
	 * @param string                    $style_id        Style ID.
	 * @param string|null               $label           New label (null to skip).
	 * @param array<string, mixed>|null $settings        Settings groups to update (null to skip).
	 * @param array<int, mixed>|null    $conditions      Replacement conditions (null to skip, empty array to clear).
	 * @param bool                      $replace_section If true, replace entire group instead of merging.
	 * @return array<string, mixed>|\WP_Error Update result or WP_Error on failure.
	 */
	public function update_theme_style( string $style_id, ?string $label = null, ?array $settings = null, ?array $conditions = null, bool $replace_section = false ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		// Capture before snapshot.
		$before = $styles[ $style_id ]['settings'];

		// Update label if provided.
		if ( null !== $label ) {
			$styles[ $style_id ]['label'] = sanitize_text_field( $label );
		}

		// Update settings if provided.
		if ( null !== $settings ) {
			foreach ( $settings as $group_key => $group_settings ) {
				// Skip conditions key — handled separately below.
				if ( 'conditions' === $group_key ) {
					continue;
				}

				if ( $replace_section || ! isset( $styles[ $style_id ]['settings'][ $group_key ] ) ) {
					// Replace the entire group or create new group.
					$styles[ $style_id ]['settings'][ $group_key ] = $group_settings;
				} else {
					// Deep merge: merge within the group (control-level granularity).
					$styles[ $style_id ]['settings'][ $group_key ] = array_merge(
						$styles[ $style_id ]['settings'][ $group_key ],
						$group_settings
					);
				}
			}
		}

		// Update conditions if provided (including empty array to clear).
		if ( null !== $conditions ) {
			$styles[ $style_id ]['settings']['conditions'] = [ 'conditions' => $conditions ];
		}

		update_option( 'bricks_theme_styles', $styles );

		// Capture after snapshot.
		$after = $styles[ $style_id ]['settings'];

		// Detect site-wide active status.
		$style_conditions = $styles[ $style_id ]['settings']['conditions']['conditions'] ?? [];
		$is_sitewide      = ! empty(
			array_filter(
				$style_conditions,
				static fn( $c ) => ( $c['main'] ?? '' ) === 'any'
			)
		);

		return [
			'style'              => $this->format_theme_style_response( $style_id, $styles[ $style_id ] ),
			'before'             => $before,
			'after'              => $after,
			'changed_groups'     => array_keys( $settings ?? [] ),
			'is_sitewide_active' => $is_sitewide,
		];
	}

	/**
	 * Delete or deactivate a theme style.
	 *
	 * By default, deactivates by clearing conditions (soft delete).
	 * Set hard_delete to true to permanently remove the style.
	 *
	 * @param string $style_id    Style ID.
	 * @param bool   $hard_delete Whether to permanently delete (default: false).
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_theme_style( string $style_id, bool $hard_delete = false ): array|\WP_Error {
		$styles = get_option( 'bricks_theme_styles', [] );

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		if ( ! isset( $styles[ $style_id ] ) ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Style ID */
					__( 'Theme style "%s" not found. Use list_theme_styles to discover available style IDs.', 'bricks-mcp' ),
					$style_id
				)
			);
		}

		if ( $hard_delete ) {
			unset( $styles[ $style_id ] );
			update_option( 'bricks_theme_styles', $styles );

			return [
				'action'   => 'deleted',
				'style_id' => $style_id,
			];
		}

		// Soft delete: clear conditions only.
		$styles[ $style_id ]['settings']['conditions'] = [ 'conditions' => [] ];
		update_option( 'bricks_theme_styles', $styles );

		return [
			'action'   => 'deactivated',
			'style_id' => $style_id,
		];
	}

	/**
	 * Format a theme style for API response.
	 *
	 * Extracts conditions, detects site-wide active status, lists settings groups.
	 *
	 * @param string               $style_id Style ID.
	 * @param array<string, mixed> $style    Raw style data.
	 * @return array<string, mixed> Formatted theme style response.
	 */
	private function format_theme_style_response( string $style_id, array $style ): array {
		$conditions = $style['settings']['conditions']['conditions'] ?? [];
		$is_active  = ! empty(
			array_filter(
				$conditions,
				static fn( $c ) => ( $c['main'] ?? '' ) === 'any'
			)
		);

		// List settings groups, excluding the 'conditions' metadata key.
		$settings_groups = array_values(
			array_filter(
				array_keys( $style['settings'] ?? [] ),
				static fn( string $key ) => 'conditions' !== $key
			)
		);

		return [
			'id'              => $style_id,
			'label'           => $style['label'] ?? '',
			'conditions'      => $conditions,
			'is_active'       => $is_active,
			'settings_groups' => $settings_groups,
			'settings'        => $style['settings'] ?? [],
		];
	}

	/**
	 * Get all typography scale categories with their variables.
	 *
	 * Reads scale categories from bricks_global_variables_categories and
	 * their associated variables from bricks_global_variables.
	 * Only returns categories that have a 'scale' property.
	 *
	 * @return array<int, array<string, mixed>> Array of scale category objects with variables.
	 */
	public function get_typography_scales(): array {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			return [];
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$result = [];

		foreach ( $categories as $category ) {
			// Only include scale categories (those with a 'scale' property).
			if ( ! isset( $category['scale'] ) ) {
				continue;
			}

			$cat_id = $category['id'] ?? '';

			// Filter variables belonging to this category.
			$cat_variables = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) === $cat_id
				)
			);

			$formatted_vars = array_map(
				static fn( array $var ) => [
					'id'    => $var['id'] ?? '',
					'name'  => $var['name'] ?? '',
					'value' => $var['value'] ?? '',
				],
				$cat_variables
			);

			$result[] = [
				'id'              => $cat_id,
				'name'            => $category['name'] ?? '',
				'prefix'          => $category['scale']['prefix'] ?? '',
				'utility_classes' => $category['utilityClasses'] ?? [],
				'variables'       => $formatted_vars,
				'variable_count'  => count( $formatted_vars ),
			];
		}

		return $result;
	}

	/**
	 * Create a new typography scale category with variables.
	 *
	 * Creates a category with scale config and generates CSS variables
	 * for each step. Regenerates style manager CSS after creation.
	 *
	 * @param string                                         $name            Scale name.
	 * @param array<int, array{name: string, value: string}> $steps Steps with name and value.
	 * @param string                                         $prefix          CSS variable prefix (must start with --).
	 * @param array<int, array<string, mixed>>               $utility_classes Utility class configs (optional).
	 * @return array<string, mixed>|\WP_Error Created scale object or WP_Error on failure.
	 */
	public function create_typography_scale( string $name, array $steps, string $prefix, array $utility_classes = [] ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Scale name is required. Provide a non-empty "name" parameter.', 'bricks-mcp' )
			);
		}

		if ( ! str_starts_with( $prefix, '--' ) ) {
			return new \WP_Error(
				'invalid_prefix',
				__( 'CSS variable prefix must start with "--" (e.g., "--text-", "--heading-").', 'bricks-mcp' )
			);
		}

		if ( empty( $steps ) ) {
			return new \WP_Error(
				'missing_steps',
				__( 'At least one step is required. Each step must have "name" and "value" (e.g., {"name": "sm", "value": "0.875rem"}).', 'bricks-mcp' )
			);
		}

		// Validate steps.
		foreach ( $steps as $index => $step ) {
			if ( empty( $step['name'] ) || ! isset( $step['value'] ) ) {
				return new \WP_Error(
					'invalid_step',
					sprintf(
						/* translators: %d: Step index */
						__( 'Step at index %d must have both "name" and "value" properties.', 'bricks-mcp' ),
						$index
					)
				);
			}
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Generate collision-free category ID.
		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );
		do {
			$cat_id = $id_generator->generate();
		} while ( in_array( $cat_id, $existing_ids, true ) );

		// Default utility classes if empty.
		if ( empty( $utility_classes ) ) {
			$class_prefix    = str_replace( '--', '', $prefix );
			$class_prefix    = rtrim( $class_prefix, '-' );
			$utility_classes = [
				[
					'className'   => $class_prefix . '-*',
					'cssProperty' => 'font-size',
				],
			];
		}

		// Build category entry.
		$new_category = [
			'id'             => $cat_id,
			'name'           => $sanitized_name,
			'scale'          => [ 'prefix' => $prefix ],
			'utilityClasses' => $utility_classes,
		];

		$categories[] = $new_category;
		update_option( 'bricks_global_variables_categories', $categories );

		// Generate variables for each step.
		$existing_var_ids = array_column( $variables, 'id' );
		$new_variables    = [];

		foreach ( $steps as $step ) {
			do {
				$var_id = $id_generator->generate();
			} while ( in_array( $var_id, $existing_var_ids, true ) || in_array( $var_id, array_column( $new_variables, 'id' ), true ) );

			$new_var = [
				'id'       => $var_id,
				'name'     => $prefix . sanitize_text_field( $step['name'] ),
				'value'    => sanitize_text_field( $step['value'] ),
				'category' => $cat_id,
			];

			$new_variables[]    = $new_var;
			$existing_var_ids[] = $var_id;
		}

		$variables = array_merge( $variables, $new_variables );
		update_option( 'bricks_global_variables', $variables );

		// Regenerate style manager CSS.
		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $cat_id,
			'name'            => $sanitized_name,
			'prefix'          => $prefix,
			'utility_classes' => $utility_classes,
			'variables'       => array_map(
				static fn( array $var ) => [
					'id'    => $var['id'],
					'name'  => $var['name'],
					'value' => $var['value'],
				],
				$new_variables
			),
			'variable_count'  => count( $new_variables ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update a typography scale category and/or its variables.
	 *
	 * Supports renaming, prefix change (auto-renames existing variables),
	 * utility class updates, and step add/update/delete operations.
	 *
	 * @param string                                $category_id    Scale category ID.
	 * @param string|null                           $name           New name (null to skip).
	 * @param array<int, array<string, mixed>>|null $steps        Steps to add/update/delete (null to skip).
	 * @param string|null                           $prefix         New CSS variable prefix (null to skip).
	 * @param array<int, array<string, mixed>>|null $utility_classes New utility classes (null to skip).
	 * @return array<string, mixed>|\WP_Error Updated scale object or WP_Error on failure.
	 */
	public function update_typography_scale( string $category_id, ?string $name = null, ?array $steps = null, ?string $prefix = null, ?array $utility_classes = null ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		// Find the category.
		$cat_index = null;
		foreach ( $categories as $index => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $index;
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Typography scale category "%s" not found. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Verify it is a scale category.
		if ( ! isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'not_a_scale',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is not a typography scale (no scale property). Use get_typography_scales to find scale categories.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Update name if provided.
		if ( null !== $name ) {
			$categories[ $cat_index ]['name'] = sanitize_text_field( $name );
		}

		// Update prefix if provided — also rename existing variables.
		if ( null !== $prefix ) {
			if ( ! str_starts_with( $prefix, '--' ) ) {
				return new \WP_Error(
					'invalid_prefix',
					__( 'CSS variable prefix must start with "--" (e.g., "--text-", "--heading-").', 'bricks-mcp' )
				);
			}

			$old_prefix                                  = $categories[ $cat_index ]['scale']['prefix'] ?? '';
			$categories[ $cat_index ]['scale']['prefix'] = $prefix;

			// Rename existing variables for this category.
			if ( '' !== $old_prefix ) {
				foreach ( $variables as &$var ) {
					if ( ( $var['category'] ?? '' ) === $category_id && str_starts_with( $var['name'] ?? '', $old_prefix ) ) {
						$step_name   = substr( $var['name'], strlen( $old_prefix ) );
						$var['name'] = $prefix . $step_name;
					}
				}
				unset( $var );
			}
		}

		// Update utility classes if provided.
		if ( null !== $utility_classes ) {
			$categories[ $cat_index ]['utilityClasses'] = $utility_classes;
		}

		update_option( 'bricks_global_variables_categories', $categories );

		// Update steps if provided.
		if ( null !== $steps ) {
			$id_generator     = new ElementIdGenerator();
			$existing_var_ids = array_column( $variables, 'id' );
			$current_prefix   = $categories[ $cat_index ]['scale']['prefix'] ?? '';

			foreach ( $steps as $step ) {
				if ( ! empty( $step['id'] ) ) {
					if ( ! empty( $step['delete'] ) ) {
						// Delete the variable.
						$variables = array_values(
							array_filter(
								$variables,
								static fn( array $var ) => ( $var['id'] ?? '' ) !== $step['id']
							)
						);
					} else {
						// Update existing variable.
						foreach ( $variables as &$var ) {
							if ( ( $var['id'] ?? '' ) === $step['id'] ) {
								if ( isset( $step['name'] ) ) {
									$var['name'] = $current_prefix . sanitize_text_field( $step['name'] );
								}
								if ( isset( $step['value'] ) ) {
									$var['value'] = sanitize_text_field( $step['value'] );
								}
								break;
							}
						}
						unset( $var );
					}
				} else {
					// New step — create a new variable.
					if ( empty( $step['name'] ) || ! isset( $step['value'] ) ) {
						continue; // Skip invalid new steps.
					}

					do {
						$var_id = $id_generator->generate();
					} while ( in_array( $var_id, $existing_var_ids, true ) );

					$variables[]        = [
						'id'       => $var_id,
						'name'     => $current_prefix . sanitize_text_field( $step['name'] ),
						'value'    => sanitize_text_field( $step['value'] ),
						'category' => $category_id,
					];
					$existing_var_ids[] = $var_id;
				}
			}
		}

		update_option( 'bricks_global_variables', $variables );

		// Regenerate style manager CSS.
		$css_regenerated = $this->regenerate_style_manager_css();

		// Build response with current state.
		$cat_variables = array_values(
			array_filter(
				$variables,
				static fn( array $var ) => ( $var['category'] ?? '' ) === $category_id
			)
		);

		return [
			'id'              => $category_id,
			'name'            => $categories[ $cat_index ]['name'] ?? '',
			'prefix'          => $categories[ $cat_index ]['scale']['prefix'] ?? '',
			'utility_classes' => $categories[ $cat_index ]['utilityClasses'] ?? [],
			'variables'       => array_map(
				static fn( array $var ) => [
					'id'    => $var['id'] ?? '',
					'name'  => $var['name'] ?? '',
					'value' => $var['value'] ?? '',
				],
				$cat_variables
			),
			'variable_count'  => count( $cat_variables ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete a typography scale category and all its variables.
	 *
	 * Removes the category from bricks_global_variables_categories and
	 * removes all variables belonging to it from bricks_global_variables.
	 *
	 * @param string $category_id Scale category ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_typography_scale( string $category_id ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		// Find and verify the category.
		$found     = false;
		$cat_index = null;
		foreach ( $categories as $index => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $index;
				$found     = true;
				break;
			}
		}

		if ( ! $found ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Typography scale category "%s" not found. Use get_typography_scales to discover available scale IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Verify it is a scale category.
		if ( ! isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'not_a_scale',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is not a typography scale. Will not delete plain variable categories from this tool.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Remove category.
		array_splice( $categories, $cat_index, 1 );
		update_option( 'bricks_global_variables_categories', $categories );

		// Remove all variables belonging to this category.
		$variables     = get_option( 'bricks_global_variables', [] );
		$removed_count = 0;

		if ( is_array( $variables ) ) {
			$original_count = count( $variables );
			$variables      = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) !== $category_id
				)
			);
			$removed_count  = $original_count - count( $variables );
			update_option( 'bricks_global_variables', $variables );
		}

		// Regenerate style manager CSS.
		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'action'            => 'deleted',
			'category_id'       => $category_id,
			'variables_removed' => $removed_count,
			'css_regenerated'   => $css_regenerated,
		];
	}

	// =========================================================================
	// Color Palette CRUD
	// =========================================================================

	/**
	 * Get all color palettes with their colors.
	 *
	 * Reads the bricks_color_palette option and returns all palettes.
	 * Returns an empty array on fresh sites where the Bricks builder has not saved yet.
	 *
	 * @return array<int, array<string, mixed>> All palettes with their colors.
	 */
	public function get_color_palettes(): array {
		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			return [];
		}

		$result = [];

		foreach ( $palettes as $palette ) {
			$colors = [];

			foreach ( $palette['colors'] ?? [] as $color ) {
				$formatted = [
					'id'    => $color['id'] ?? '',
					'light' => $color['light'] ?? '',
					'raw'   => $color['raw'] ?? '',
				];

				if ( ! empty( $color['type'] ) ) {
					$formatted['type'] = $color['type'];
				}

				if ( ! empty( $color['parent'] ) ) {
					$formatted['parent'] = $color['parent'];
				}

				if ( ! empty( $color['utilityClasses'] ) ) {
					$formatted['utilityClasses'] = $color['utilityClasses'];
				}

				$colors[] = $formatted;
			}

			$result[] = [
				'id'          => $palette['id'] ?? '',
				'name'        => $palette['name'] ?? '',
				'colors'      => $colors,
				'color_count' => count( $colors ),
			];
		}

		return $result;
	}

	/**
	 * Derive a CSS variable name from a friendly color name.
	 *
	 * Algorithm: trim, lowercase, replace non-alphanumeric runs with hyphens,
	 * strip leading/trailing hyphens, prepend --, wrap in var().
	 *
	 * @param string $name Friendly color name (e.g., "Primary Blue").
	 * @return string CSS variable reference (e.g., "var(--primary-blue)").
	 */
	private function derive_css_variable_from_name( string $name ): string {
		$css_name = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( $name ) ) );
		$css_name = trim( $css_name, '-' );

		return 'var(--' . $css_name . ')';
	}

	/**
	 * Normalize a raw CSS variable reference to var(--name) format.
	 *
	 * Accepts: "var(--name)", "--name", "name".
	 * Returns: "var(--name)".
	 *
	 * @param string $raw The raw CSS variable reference.
	 * @return string Normalized CSS variable reference.
	 */
	private function normalize_raw_css_variable( string $raw ): string {
		// Already in var() format.
		if ( str_starts_with( $raw, 'var(' ) ) {
			return $raw;
		}

		// Has -- prefix but no var() wrapper.
		if ( str_starts_with( $raw, '--' ) ) {
			return 'var(' . $raw . ')';
		}

		// Bare name.
		return 'var(--' . $raw . ')';
	}

	/**
	 * Create a new color palette with optional initial colors.
	 *
	 * Generates palette ID and color IDs via ElementIdGenerator.
	 * Auto-derives CSS variable names from color names if not explicitly provided.
	 *
	 * @param string                           $name   Palette name.
	 * @param array<int, array<string, mixed>> $colors Optional initial colors.
	 * @return array<string, mixed>|\WP_Error Created palette or WP_Error on failure.
	 */
	public function create_color_palette( string $name, array $colors = [] ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Palette name is required.', 'bricks-mcp' )
			);
		}

		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$id_generator = new ElementIdGenerator();

		// Collect all existing IDs across all palettes for collision detection.
		$existing_ids = [];
		foreach ( $palettes as $p ) {
			$existing_ids[] = $p['id'] ?? '';
			foreach ( $p['colors'] ?? [] as $c ) {
				$existing_ids[] = $c['id'] ?? '';
			}
		}

		// Generate palette ID.
		do {
			$palette_id = $id_generator->generate();
		} while ( in_array( $palette_id, $existing_ids, true ) );
		$existing_ids[] = $palette_id;

		// Process initial colors.
		$palette_colors   = [];
		$color_name_to_id = [];

		// First pass: create root colors (no parent).
		foreach ( $colors as $color_def ) {
			if ( ! empty( $color_def['parent'] ) ) {
				continue; // Handle in second pass.
			}

			if ( empty( $color_def['name'] ) || empty( $color_def['light'] ) ) {
				continue; // Skip incomplete colors.
			}

			$hex = sanitize_hex_color( $color_def['light'] );

			if ( null === $hex ) {
				continue; // Skip invalid hex.
			}

			do {
				$color_id = $id_generator->generate();
			} while ( in_array( $color_id, $existing_ids, true ) );
			$existing_ids[] = $color_id;

			$raw = ! empty( $color_def['raw'] )
				? $this->normalize_raw_css_variable( $color_def['raw'] )
				: $this->derive_css_variable_from_name( $color_def['name'] );

			$color_obj = [
				'id'    => $color_id,
				'light' => $hex,
				'raw'   => $raw,
			];

			if ( ! empty( $color_def['utility_classes'] ) && is_array( $color_def['utility_classes'] ) ) {
				$color_obj['utilityClasses'] = array_values(
					array_intersect(
						$color_def['utility_classes'],
						[ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ]
					)
				);
			}

			$palette_colors[]                       = $color_obj;
			$color_name_to_id[ $color_def['name'] ] = $color_id;
		}

		// Second pass: create child colors (with parent).
		foreach ( $colors as $color_def ) {
			if ( empty( $color_def['parent'] ) ) {
				continue;
			}

			if ( empty( $color_def['name'] ) || empty( $color_def['light'] ) ) {
				continue;
			}

			$hex = sanitize_hex_color( $color_def['light'] );

			if ( null === $hex ) {
				continue;
			}

			// Resolve parent by name.
			$parent_id = $color_name_to_id[ $color_def['parent'] ] ?? '';

			if ( '' === $parent_id ) {
				continue; // Parent not found.
			}

			do {
				$color_id = $id_generator->generate();
			} while ( in_array( $color_id, $existing_ids, true ) );
			$existing_ids[] = $color_id;

			$raw = ! empty( $color_def['raw'] )
				? $this->normalize_raw_css_variable( $color_def['raw'] )
				: $this->derive_css_variable_from_name( $color_def['name'] );

			$palette_colors[] = [
				'id'     => $color_id,
				'light'  => $hex,
				'raw'    => $raw,
				'type'   => 'custom',
				'parent' => $parent_id,
			];
		}

		$new_palette = [
			'id'     => $palette_id,
			'name'   => $sanitized_name,
			'colors' => $palette_colors,
		];

		$palettes[] = $new_palette;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $palette_id,
			'name'            => $sanitized_name,
			'colors'          => $palette_colors,
			'color_count'     => count( $palette_colors ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Rename a color palette.
	 *
	 * @param string $palette_id Palette ID.
	 * @param string $name       New palette name.
	 * @return array<string, mixed>|\WP_Error Updated palette or WP_Error on failure.
	 */
	public function update_color_palette( string $palette_id, string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Palette name is required.', 'bricks-mcp' )
			);
		}

		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Palette ID */
					__( 'Palette "%s" not found. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' ),
					$palette_id
				)
			);
		}

		$palettes[ $palette_index ]['name'] = $sanitized_name;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $palette_id,
			'name'            => $sanitized_name,
			'color_count'     => count( $palettes[ $palette_index ]['colors'] ?? [] ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete a color palette and all its colors permanently.
	 *
	 * @param string $palette_id Palette ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_color_palette( string $palette_id ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		$deleted_name  = '';
		$color_count   = 0;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				$deleted_name  = $p['name'] ?? '';
				$color_count   = count( $p['colors'] ?? [] );
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Palette ID */
					__( 'Palette "%s" not found. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' ),
					$palette_id
				)
			);
		}

		array_splice( $palettes, $palette_index, 1 );
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'action'          => 'deleted',
			'id'              => $palette_id,
			'name'            => $deleted_name,
			'colors_removed'  => $color_count,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Add a color to an existing palette.
	 *
	 * Auto-derives CSS variable name from color name unless raw is specified.
	 * Supports parent/child relationships and utility class generation.
	 *
	 * @param string        $palette_id      Palette ID.
	 * @param string        $light           Hex color value (e.g., "#3498db").
	 * @param string        $name            Friendly color name (e.g., "Primary Blue").
	 * @param string        $raw             Optional CSS variable override.
	 * @param string        $parent          Optional parent color ID for child/shade.
	 * @param array<string> $utility_classes Optional utility class types.
	 * @return array<string, mixed>|\WP_Error Created color or WP_Error on failure.
	 */
	public function add_color_to_palette( string $palette_id, string $light, string $name, string $raw = '', string $parent = '', array $utility_classes = [] ): array|\WP_Error {
		$hex = sanitize_hex_color( $light );

		if ( null === $hex ) {
			return new \WP_Error(
				'invalid_hex',
				sprintf(
					/* translators: %s: Provided hex value */
					__( 'Invalid hex color "%s". Provide a valid hex color (e.g., "#3498db" or "#fff").', 'bricks-mcp' ),
					$light
				)
			);
		}

		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Color name is required.', 'bricks-mcp' )
			);
		}

		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Palette ID */
					__( 'Palette "%s" not found. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' ),
					$palette_id
				)
			);
		}

		$id_generator       = new ElementIdGenerator();
		$existing_color_ids = array_column( $palettes[ $palette_index ]['colors'] ?? [], 'id' );

		do {
			$color_id = $id_generator->generate();
		} while ( in_array( $color_id, $existing_color_ids, true ) );

		// Derive or normalize CSS variable.
		$css_raw = '' !== $raw
			? $this->normalize_raw_css_variable( $raw )
			: $this->derive_css_variable_from_name( $sanitized_name );

		$color_obj = [
			'id'    => $color_id,
			'light' => $hex,
			'raw'   => $css_raw,
		];

		// Handle parent/child relationship.
		if ( '' !== $parent ) {
			// Validate parent exists in this palette.
			$parent_found = false;
			foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $c ) {
				if ( ( $c['id'] ?? '' ) === $parent ) {
					$parent_found = true;
					break;
				}
			}

			if ( ! $parent_found ) {
				return new \WP_Error(
					'parent_not_found',
					sprintf(
						/* translators: %1$s: Parent color ID, %2$s: Palette ID */
						__( 'Parent color "%1$s" not found in palette "%2$s". Use list_color_palettes to see existing color IDs.', 'bricks-mcp' ),
						$parent,
						$palette_id
					)
				);
			}

			$color_obj['type']   = 'custom';
			$color_obj['parent'] = $parent;
		} elseif ( ! empty( $utility_classes ) ) {
			// Only set utility classes on root colors.
			$color_obj['utilityClasses'] = array_values(
				array_intersect(
					$utility_classes,
					[ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ]
				)
			);
		}

		$palettes[ $palette_index ]['colors'][] = $color_obj;
		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $color_id,
			'light'           => $hex,
			'raw'             => $css_raw,
			'palette_id'      => $palette_id,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update an existing color in a palette.
	 *
	 * Supports updating hex, name/raw, parent relationship, and utility classes.
	 *
	 * @param string               $palette_id Palette ID.
	 * @param string               $color_id   Color ID.
	 * @param array<string, mixed> $fields     Fields to update.
	 * @return array<string, mixed>|\WP_Error Updated color or WP_Error on failure.
	 */
	public function update_color_in_palette( string $palette_id, string $color_id, array $fields ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Palette ID */
					__( 'Palette "%s" not found. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' ),
					$palette_id
				)
			);
		}

		$color_index = null;
		foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $ci => $c ) {
			if ( ( $c['id'] ?? '' ) === $color_id ) {
				$color_index = $ci;
				break;
			}
		}

		if ( null === $color_index ) {
			return new \WP_Error(
				'color_not_found',
				sprintf(
					/* translators: %1$s: Color ID, %2$s: Palette ID */
					__( 'Color "%1$s" not found in palette "%2$s". Use list_color_palettes to see existing color IDs.', 'bricks-mcp' ),
					$color_id,
					$palette_id
				)
			);
		}

		$color = &$palettes[ $palette_index ]['colors'][ $color_index ];

		// Update hex value.
		if ( isset( $fields['light'] ) ) {
			$hex = sanitize_hex_color( $fields['light'] );

			if ( null === $hex ) {
				return new \WP_Error(
					'invalid_hex',
					sprintf(
						/* translators: %s: Provided hex value */
						__( 'Invalid hex color "%s". Provide a valid hex color (e.g., "#3498db" or "#fff").', 'bricks-mcp' ),
						$fields['light']
					)
				);
			}

			$color['light'] = $hex;
		}

		// Update name/raw — auto-derive raw from name if name changed but raw not provided.
		if ( isset( $fields['name'] ) ) {
			if ( isset( $fields['raw'] ) && '' !== $fields['raw'] ) {
				$color['raw'] = $this->normalize_raw_css_variable( $fields['raw'] );
			} else {
				$color['raw'] = $this->derive_css_variable_from_name( $fields['name'] );
			}
		} elseif ( isset( $fields['raw'] ) && '' !== $fields['raw'] ) {
			$color['raw'] = $this->normalize_raw_css_variable( $fields['raw'] );
		}

		// Update parent relationship.
		if ( array_key_exists( 'parent', $fields ) ) {
			if ( '' === $fields['parent'] || null === $fields['parent'] ) {
				// Promote to root — remove type and parent.
				unset( $color['type'], $color['parent'] );
			} else {
				// Validate parent exists in this palette.
				$parent_found = false;
				foreach ( $palettes[ $palette_index ]['colors'] ?? [] as $c ) {
					if ( ( $c['id'] ?? '' ) === $fields['parent'] && $c['id'] !== $color_id ) {
						$parent_found = true;
						break;
					}
				}

				if ( ! $parent_found ) {
					return new \WP_Error(
						'parent_not_found',
						sprintf(
							/* translators: %s: Parent color ID */
							__( 'Parent color "%s" not found in this palette.', 'bricks-mcp' ),
							$fields['parent']
						)
					);
				}

				$color['type']   = 'custom';
				$color['parent'] = $fields['parent'];
			}
		}

		// Update utility classes.
		if ( array_key_exists( 'utilityClasses', $fields ) ) {
			if ( empty( $fields['utilityClasses'] ) ) {
				unset( $color['utilityClasses'] );
			} else {
				$color['utilityClasses'] = array_values(
					array_intersect(
						$fields['utilityClasses'],
						[ 'bg', 'text', 'border', 'outline', 'fill', 'stroke' ]
					)
				);
			}
		}

		unset( $color );

		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		$updated_color = $palettes[ $palette_index ]['colors'][ $color_index ];

		return array_merge(
			$updated_color,
			[
				'palette_id'      => $palette_id,
				'css_regenerated' => $css_regenerated,
			]
		);
	}

	/**
	 * Delete a color from a palette permanently.
	 *
	 * If the color has children, they are also removed (cascade delete).
	 *
	 * @param string $palette_id Palette ID.
	 * @param string $color_id   Color ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_color_from_palette( string $palette_id, string $color_id ): array|\WP_Error {
		$palettes = get_option( 'bricks_color_palette', [] );

		if ( ! is_array( $palettes ) ) {
			$palettes = [];
		}

		$palette_index = null;
		foreach ( $palettes as $i => $p ) {
			if ( ( $p['id'] ?? '' ) === $palette_id ) {
				$palette_index = $i;
				break;
			}
		}

		if ( null === $palette_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Palette ID */
					__( 'Palette "%s" not found. Use list_color_palettes to discover available palette IDs.', 'bricks-mcp' ),
					$palette_id
				)
			);
		}

		$colors      = $palettes[ $palette_index ]['colors'] ?? [];
		$color_found = false;
		$deleted_raw = '';

		foreach ( $colors as $c ) {
			if ( ( $c['id'] ?? '' ) === $color_id ) {
				$color_found = true;
				$deleted_raw = $c['raw'] ?? '';
				break;
			}
		}

		if ( ! $color_found ) {
			return new \WP_Error(
				'color_not_found',
				sprintf(
					/* translators: %1$s: Color ID, %2$s: Palette ID */
					__( 'Color "%1$s" not found in palette "%2$s". Use list_color_palettes to see existing color IDs.', 'bricks-mcp' ),
					$color_id,
					$palette_id
				)
			);
		}

		// Find children of this color.
		$children_removed = 0;
		$ids_to_remove    = [ $color_id ];

		foreach ( $colors as $c ) {
			if ( ( $c['parent'] ?? '' ) === $color_id ) {
				$ids_to_remove[] = $c['id'] ?? '';
				++$children_removed;
			}
		}

		// Remove the color and its children.
		$palettes[ $palette_index ]['colors'] = array_values(
			array_filter(
				$colors,
				static fn( array $c ) => ! in_array( $c['id'] ?? '', $ids_to_remove, true )
			)
		);

		update_option( 'bricks_color_palette', $palettes );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'action'           => 'deleted',
			'id'               => $color_id,
			'raw'              => $deleted_raw,
			'palette_id'       => $palette_id,
			'children_removed' => $children_removed,
			'css_regenerated'  => $css_regenerated,
		];
	}

	// =========================================================================
	// Global Variables CRUD (Non-Scale)
	// =========================================================================

	/**
	 * Get all non-scale global variables organized by category.
	 *
	 * Excludes scale categories (Phase 10) — use get_typography_scales for those.
	 * Returns categories with their variables plus uncategorized variables.
	 *
	 * @return array<string, mixed> Variables organized by category.
	 */
	public function get_global_variables(): array {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Filter to non-scale categories only.
		$non_scale_categories = array_filter(
			$categories,
			static fn( array $cat ) => ! isset( $cat['scale'] )
		);

		$non_scale_cat_ids = array_column( $non_scale_categories, 'id' );
		$result_categories = [];

		foreach ( $non_scale_categories as $cat ) {
			$cat_id = $cat['id'] ?? '';

			$cat_vars = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) === $cat_id
				)
			);

			$formatted_vars = array_map(
				static fn( array $var ) => [
					'id'       => $var['id'] ?? '',
					'name'     => $var['name'] ?? '',
					'value'    => $var['value'] ?? '',
					'category' => $var['category'] ?? '',
				],
				$cat_vars
			);

			$result_categories[] = [
				'id'             => $cat_id,
				'name'           => $cat['name'] ?? '',
				'variables'      => $formatted_vars,
				'variable_count' => count( $formatted_vars ),
			];
		}

		// Collect uncategorized variables (empty category or category not found).
		$all_cat_ids   = array_column( $categories, 'id' );
		$uncategorized = array_values(
			array_filter(
				$variables,
				static fn( array $var ) => '' === ( $var['category'] ?? '' ) || ! in_array( $var['category'] ?? '', $all_cat_ids, true )
			)
		);

		$formatted_uncategorized = array_map(
			static fn( array $var ) => [
				'id'       => $var['id'] ?? '',
				'name'     => $var['name'] ?? '',
				'value'    => $var['value'] ?? '',
				'category' => $var['category'] ?? '',
			],
			$uncategorized
		);

		$total = 0;
		foreach ( $result_categories as $cat ) {
			$total += $cat['variable_count'];
		}
		$total += count( $formatted_uncategorized );

		return [
			'categories'      => $result_categories,
			'uncategorized'   => $formatted_uncategorized,
			'total_variables' => $total,
			'note'            => __( 'Plain global variables are stored as design tokens for AI reference. Only color palette colors and typography scale variables generate CSS output in style-manager.min.css.', 'bricks-mcp' ),
		];
	}

	/**
	 * Create a non-scale variable category.
	 *
	 * @param string $name Category name.
	 * @return array<string, mixed>|\WP_Error Created category or WP_Error on failure.
	 */
	public function create_variable_category( string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Category name is required.', 'bricks-mcp' )
			);
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $categories, 'id' );

		do {
			$cat_id = $id_generator->generate();
		} while ( in_array( $cat_id, $existing_ids, true ) );

		// Non-scale category: no 'scale' key.
		$new_category = [
			'id'   => $cat_id,
			'name' => $sanitized_name,
		];

		$categories[] = $new_category;
		update_option( 'bricks_global_variables_categories', $categories );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $cat_id,
			'name'            => $sanitized_name,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Rename a non-scale variable category.
	 *
	 * Refuses to modify scale categories (Phase 10 territory).
	 *
	 * @param string $category_id Category ID.
	 * @param string $name        New name.
	 * @return array<string, mixed>|\WP_Error Updated category or WP_Error on failure.
	 */
	public function update_variable_category( string $category_id, string $name ): array|\WP_Error {
		$sanitized_name = sanitize_text_field( $name );

		if ( '' === $sanitized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Category name is required.', 'bricks-mcp' )
			);
		}

		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$cat_index = null;
		foreach ( $categories as $i => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $i;
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Variable category "%s" not found. Use list_global_variables to discover available category IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Guard: refuse to modify scale categories.
		if ( isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'is_scale_category',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is a typography scale. Use update_typography_scale to modify scale categories.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		$categories[ $cat_index ]['name'] = $sanitized_name;
		update_option( 'bricks_global_variables_categories', $categories );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $category_id,
			'name'            => $sanitized_name,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete a non-scale variable category and all its variables.
	 *
	 * Refuses to delete scale categories (Phase 10 territory).
	 *
	 * @param string $category_id Category ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_variable_category( string $category_id ): array|\WP_Error {
		$categories = get_option( 'bricks_global_variables_categories', [] );

		if ( ! is_array( $categories ) ) {
			$categories = [];
		}

		$cat_index = null;
		$cat_name  = '';
		foreach ( $categories as $i => $cat ) {
			if ( ( $cat['id'] ?? '' ) === $category_id ) {
				$cat_index = $i;
				$cat_name  = $cat['name'] ?? '';
				break;
			}
		}

		if ( null === $cat_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Variable category "%s" not found. Use list_global_variables to discover available category IDs.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Guard: refuse to delete scale categories.
		if ( isset( $categories[ $cat_index ]['scale'] ) ) {
			return new \WP_Error(
				'is_scale_category',
				sprintf(
					/* translators: %s: Category ID */
					__( 'Category "%s" is a typography scale. Use delete_typography_scale to remove scale categories.', 'bricks-mcp' ),
					$category_id
				)
			);
		}

		// Remove category.
		array_splice( $categories, $cat_index, 1 );
		update_option( 'bricks_global_variables_categories', $categories );

		// Remove all variables in this category.
		$variables     = get_option( 'bricks_global_variables', [] );
		$removed_count = 0;

		if ( is_array( $variables ) ) {
			$original_count = count( $variables );
			$variables      = array_values(
				array_filter(
					$variables,
					static fn( array $var ) => ( $var['category'] ?? '' ) !== $category_id
				)
			);
			$removed_count  = $original_count - count( $variables );
			update_option( 'bricks_global_variables', $variables );
		}

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'action'            => 'deleted',
			'category_id'       => $category_id,
			'category_name'     => $cat_name,
			'variables_removed' => $removed_count,
			'css_regenerated'   => $css_regenerated,
		];
	}

	/**
	 * Normalize a variable name to include the -- prefix.
	 *
	 * @param string $name Variable name.
	 * @return string Normalized name (e.g., "--spacing-md").
	 */
	private function normalize_variable_name( string $name ): string {
		$name = sanitize_text_field( $name );

		if ( ! str_starts_with( $name, '--' ) ) {
			$name = '--' . $name;
		}

		return $name;
	}

	/**
	 * Create a global CSS custom property variable.
	 *
	 * Normalizes name to include -- prefix. Validates category if provided.
	 *
	 * @param string $name        Variable name (e.g., "spacing-md" or "--spacing-md").
	 * @param string $value       CSS value (e.g., "1rem", "clamp(1rem, 2.5vw, 2rem)").
	 * @param string $category_id Optional category ID.
	 * @return array<string, mixed>|\WP_Error Created variable or WP_Error on failure.
	 */
	public function create_global_variable( string $name, string $value, string $category_id = '' ): array|\WP_Error {
		$normalized_name = $this->normalize_variable_name( $name );

		if ( '--' === $normalized_name ) {
			return new \WP_Error(
				'missing_name',
				__( 'Variable name is required.', 'bricks-mcp' )
			);
		}

		$sanitized_value = sanitize_text_field( $value );

		if ( '' === $sanitized_value ) {
			return new \WP_Error(
				'missing_value',
				__( 'Variable value is required.', 'bricks-mcp' )
			);
		}

		// Validate category if provided.
		if ( '' !== $category_id ) {
			$categories = get_option( 'bricks_global_variables_categories', [] );

			if ( ! is_array( $categories ) ) {
				$categories = [];
			}

			$cat_found = false;
			foreach ( $categories as $cat ) {
				if ( ( $cat['id'] ?? '' ) === $category_id ) {
					if ( isset( $cat['scale'] ) ) {
						return new \WP_Error(
							'is_scale_category',
							sprintf(
								/* translators: %s: Category ID */
								__( 'Category "%s" is a typography scale. Use create_typography_scale to add variables to scale categories.', 'bricks-mcp' ),
								$category_id
							)
						);
					}
					$cat_found = true;
					break;
				}
			}

			if ( ! $cat_found ) {
				return new \WP_Error(
					'category_not_found',
					sprintf(
						/* translators: %s: Category ID */
						__( 'Category "%s" not found. Use list_global_variables to discover available category IDs, or create_variable_category to create one.', 'bricks-mcp' ),
						$category_id
					)
				);
			}
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$id_generator = new ElementIdGenerator();
		$existing_ids = array_column( $variables, 'id' );

		do {
			$var_id = $id_generator->generate();
		} while ( in_array( $var_id, $existing_ids, true ) );

		$new_variable = [
			'id'       => $var_id,
			'name'     => $normalized_name,
			'value'    => $sanitized_value,
			'category' => $category_id,
		];

		$variables[] = $new_variable;
		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'id'              => $var_id,
			'name'            => $normalized_name,
			'value'           => $sanitized_value,
			'category'        => $category_id,
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Update a global variable's name, value, or category.
	 *
	 * Warns about rename without site-wide reference update.
	 *
	 * @param string               $variable_id Variable ID.
	 * @param array<string, mixed> $fields      Fields to update (name, value, category).
	 * @return array<string, mixed>|\WP_Error Updated variable or WP_Error on failure.
	 */
	public function update_global_variable( string $variable_id, array $fields ): array|\WP_Error {
		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$var_index = null;
		foreach ( $variables as $i => $var ) {
			if ( ( $var['id'] ?? '' ) === $variable_id ) {
				$var_index = $i;
				break;
			}
		}

		if ( null === $var_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Variable ID */
					__( 'Variable "%s" not found. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' ),
					$variable_id
				)
			);
		}

		$old_name       = $variables[ $var_index ]['name'] ?? '';
		$rename_warning = '';

		// Update name.
		if ( isset( $fields['name'] ) ) {
			$new_name = $this->normalize_variable_name( $fields['name'] );

			if ( '--' === $new_name ) {
				return new \WP_Error(
					'missing_name',
					__( 'Variable name cannot be empty.', 'bricks-mcp' )
				);
			}

			if ( $new_name !== $old_name ) {
				$variables[ $var_index ]['name'] = $new_name;
				$rename_warning                  = sprintf(
					/* translators: %s: Old variable name */
					__( 'Variable renamed. Existing references to var(%s) in elements and styles will NOT be automatically updated.', 'bricks-mcp' ),
					$old_name
				);
			}
		}

		// Update value.
		if ( isset( $fields['value'] ) ) {
			$sanitized_value = sanitize_text_field( $fields['value'] );

			if ( '' === $sanitized_value ) {
				return new \WP_Error(
					'missing_value',
					__( 'Variable value cannot be empty.', 'bricks-mcp' )
				);
			}

			$variables[ $var_index ]['value'] = $sanitized_value;
		}

		// Update category.
		if ( array_key_exists( 'category', $fields ) ) {
			$new_category = $fields['category'] ?? '';

			if ( '' !== $new_category ) {
				$categories = get_option( 'bricks_global_variables_categories', [] );

				if ( ! is_array( $categories ) ) {
					$categories = [];
				}

				$cat_found = false;
				foreach ( $categories as $cat ) {
					if ( ( $cat['id'] ?? '' ) === $new_category ) {
						if ( isset( $cat['scale'] ) ) {
							return new \WP_Error(
								'is_scale_category',
								sprintf(
									/* translators: %s: Category ID */
									__( 'Category "%s" is a typography scale. Cannot assign plain variables to scale categories.', 'bricks-mcp' ),
									$new_category
								)
							);
						}
						$cat_found = true;
						break;
					}
				}

				if ( ! $cat_found ) {
					return new \WP_Error(
						'category_not_found',
						sprintf(
							/* translators: %s: Category ID */
							__( 'Category "%s" not found.', 'bricks-mcp' ),
							$new_category
						)
					);
				}
			}

			$variables[ $var_index ]['category'] = $new_category;
		}

		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->regenerate_style_manager_css();

		$result = [
			'id'              => $variable_id,
			'name'            => $variables[ $var_index ]['name'] ?? '',
			'value'           => $variables[ $var_index ]['value'] ?? '',
			'category'        => $variables[ $var_index ]['category'] ?? '',
			'css_regenerated' => $css_regenerated,
		];

		if ( '' !== $rename_warning ) {
			$result['warning'] = $rename_warning;
		}

		return $result;
	}

	/**
	 * Delete a global variable permanently.
	 *
	 * @param string $variable_id Variable ID.
	 * @return array<string, mixed>|\WP_Error Deletion result or WP_Error on failure.
	 */
	public function delete_global_variable( string $variable_id ): array|\WP_Error {
		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$var_index = null;
		$var_name  = '';
		foreach ( $variables as $i => $var ) {
			if ( ( $var['id'] ?? '' ) === $variable_id ) {
				$var_index = $i;
				$var_name  = $var['name'] ?? '';
				break;
			}
		}

		if ( null === $var_index ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: Variable ID */
					__( 'Variable "%s" not found. Use list_global_variables to discover available variable IDs.', 'bricks-mcp' ),
					$variable_id
				)
			);
		}

		array_splice( $variables, $var_index, 1 );
		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'action'          => 'deleted',
			'id'              => $variable_id,
			'name'            => $var_name,
			'note'            => sprintf(
				/* translators: %s: Variable name */
				__( 'Existing elements referencing var(%s) will show CSS fallback values.', 'bricks-mcp' ),
				$var_name
			),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Batch-create multiple global variables in one call.
	 *
	 * Follows partial-success model: valid items are created, invalid ones reported as errors.
	 *
	 * @param array<int, array{name: string, value: string}> $variable_defs Variable definitions.
	 * @param string                                         $category_id   Optional shared category ID.
	 * @return array<string, mixed> Result with created, errors, and css_regenerated.
	 */
	public function batch_create_global_variables( array $variable_defs, string $category_id = '' ): array {
		// Validate category if provided.
		if ( '' !== $category_id ) {
			$categories = get_option( 'bricks_global_variables_categories', [] );

			if ( ! is_array( $categories ) ) {
				$categories = [];
			}

			$cat_found = false;
			foreach ( $categories as $cat ) {
				if ( ( $cat['id'] ?? '' ) === $category_id ) {
					if ( isset( $cat['scale'] ) ) {
						return [
							'created'         => [],
							'errors'          => [ 'category' => __( 'Cannot add plain variables to a typography scale category.', 'bricks-mcp' ) ],
							'css_regenerated' => false,
						];
					}
					$cat_found = true;
					break;
				}
			}

			if ( ! $cat_found ) {
				return [
					'created'         => [],
					'errors'          => [
						'category' => sprintf(
							/* translators: %s: Category ID */
							__( 'Category "%s" not found.', 'bricks-mcp' ),
							$category_id
						),
					],
					'css_regenerated' => false,
				];
			}
		}

		$variables    = get_option( 'bricks_global_variables', [] );
		$existing_ids = is_array( $variables ) ? array_column( $variables, 'id' ) : [];

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$id_generator = new ElementIdGenerator();
		$created      = [];
		$errors       = [];

		foreach ( $variable_defs as $index => $def ) {
			if ( empty( $def['name'] ) ) {
				$errors[ $index ] = __( 'Missing name', 'bricks-mcp' );
				continue;
			}

			$normalized_name = $this->normalize_variable_name( $def['name'] );

			if ( '--' === $normalized_name ) {
				$errors[ $index ] = __( 'Empty name after normalization', 'bricks-mcp' );
				continue;
			}

			if ( ! isset( $def['value'] ) || '' === $def['value'] ) {
				$errors[ $index ] = __( 'Missing value', 'bricks-mcp' );
				continue;
			}

			$sanitized_value = sanitize_text_field( $def['value'] );

			do {
				$var_id = $id_generator->generate();
			} while ( in_array( $var_id, $existing_ids, true ) );
			$existing_ids[] = $var_id;

			$new_variable = [
				'id'       => $var_id,
				'name'     => $normalized_name,
				'value'    => $sanitized_value,
				'category' => $category_id,
			];

			$variables[] = $new_variable;
			$created[]   = $new_variable;
		}

		if ( ! empty( $created ) ) {
			update_option( 'bricks_global_variables', $variables );
		}

		$css_regenerated = ! empty( $created ) ? $this->regenerate_style_manager_css() : false;

		return [
			'created'         => $created,
			'errors'          => $errors,
			'created_count'   => count( $created ),
			'error_count'     => count( $errors ),
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Delete multiple global variables in a single operation.
	 *
	 * Reads the option once, removes matching variables, writes once, regenerates CSS once.
	 * Uses partial-success model per D-13.
	 *
	 * @param array<int, string> $variable_ids Array of variable ID strings.
	 * @return array<string, mixed>|\WP_Error Partial result or WP_Error if all fail.
	 */
	public function batch_delete_global_variables( array $variable_ids ): array|\WP_Error {
		if ( count( $variable_ids ) > 50 ) {
			return new \WP_Error( 'batch_too_large', __( 'Maximum 50 variable deletions per call.', 'bricks-mcp' ) );
		}

		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// Build a lookup map from ID to index.
		$var_map = [];
		foreach ( $variables as $i => $var ) {
			$var_map[ $var['id'] ?? '' ] = $i;
		}

		$success           = [];
		$errors            = [];
		$indices_to_remove = [];

		foreach ( $variable_ids as $vid ) {
			if ( isset( $var_map[ $vid ] ) ) {
				$success[]           = [ 'id' => $vid, 'status' => 'deleted' ];
				$indices_to_remove[] = $var_map[ $vid ];
			} else {
				$errors[] = [ 'id' => $vid, 'error' => 'Variable not found' ];
			}
		}

		if ( empty( $success ) ) {
			return new \WP_Error( 'all_failed', __( 'None of the specified variable IDs were found.', 'bricks-mcp' ) );
		}

		// Sort descending to avoid index shifting during splice.
		rsort( $indices_to_remove );
		foreach ( $indices_to_remove as $idx ) {
			array_splice( $variables, $idx, 1 );
		}

		update_option( 'bricks_global_variables', $variables );

		$css_regenerated = $this->regenerate_style_manager_css();

		return [
			'success'         => $success,
			'errors'          => $errors,
			'summary'         => [
				'total'     => count( $variable_ids ),
				'succeeded' => count( $success ),
				'failed'    => count( $errors ),
			],
			'css_regenerated' => $css_regenerated,
		];
	}

	/**
	 * Search global variables by name and/or value substring.
	 *
	 * Case-insensitive matching using stripos(). Returns flat array of matching variables.
	 *
	 * @param string $name        Name substring filter (empty = no filter).
	 * @param string $value       Value substring filter (empty = no filter).
	 * @param string $category_id Category ID filter (empty = no filter).
	 * @return array<string, mixed> Search results with count and variables.
	 */
	public function search_global_variables( string $name = '', string $value = '', string $category_id = '' ): array {
		$variables = get_option( 'bricks_global_variables', [] );

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		// If all filters are empty, return all variables.
		if ( '' === $name && '' === $value && '' === $category_id ) {
			return [
				'variables' => array_values( $variables ),
				'count'     => count( $variables ),
				'filters'   => [],
			];
		}

		$filtered = array_values(
			array_filter(
				$variables,
				function ( array $var ) use ( $name, $value, $category_id ): bool {
					if ( '' !== $name && false === stripos( $var['name'] ?? '', $name ) ) {
						return false;
					}

					if ( '' !== $value && false === stripos( $var['value'] ?? '', $value ) ) {
						return false;
					}

					if ( '' !== $category_id && ( $var['category'] ?? '' ) !== $category_id ) {
						return false;
					}

					return true;
				}
			)
		);

		return [
			'variables' => $filtered,
			'count'     => count( $filtered ),
			'filters'   => array_filter(
				[
					'name'        => $name,
					'value'       => $value,
					'category_id' => $category_id,
				]
			),
		];
	}

	/**
	 * Get Bricks global settings with optional category filtering and key masking.
	 *
	 * Returns build-relevant settings categorized by group. API keys are always
	 * masked as ****configured****. Restricted settings (code execution, SVG) are
	 * flagged but values hidden.
	 *
	 * @param string $category Optional category filter.
	 * @return array<string, mixed>|\WP_Error Settings data or error.
	 */
	public function get_bricks_settings( string $category = '' ): array|\WP_Error {
		$category_map         = $this->get_settings_category_map();
		$available_categories = array_keys( $category_map );

		if ( '' !== $category && ! isset( $category_map[ $category ] ) ) {
			return new \WP_Error(
				'invalid_category',
				sprintf(
					/* translators: 1: provided category, 2: valid categories */
					__( 'Invalid category "%1$s". Valid categories: %2$s', 'bricks-mcp' ),
					$category,
					implode( ', ', $available_categories )
				)
			);
		}

		$raw_settings = get_option( 'bricks_global_settings', [] );
		if ( ! is_array( $raw_settings ) ) {
			$raw_settings = [];
		}

		// Build allowed keys list.
		if ( '' !== $category ) {
			$allowed_keys = array_flip( $category_map[ $category ] );
		} else {
			$all_keys = [];
			foreach ( $category_map as $keys ) {
				$all_keys = array_merge( $all_keys, $keys );
			}
			$allowed_keys = array_flip( $all_keys );
		}

		// Filter to only allowed keys that exist in the option.
		$settings = array_intersect_key( $raw_settings, $allowed_keys );

		// Mask sensitive settings.
		$this->mask_sensitive_settings( $settings );

		// Build restricted flags.
		$restricted      = [];
		$restricted_keys = [ 'executeCodeEnabled', 'svgUploadEnabled' ];
		foreach ( $restricted_keys as $restricted_key ) {
			$restricted[ $restricted_key ] = [
				'restricted' => true,
				'configured' => ! empty( $raw_settings[ $restricted_key ] ),
			];
		}

		return [
			'settings'             => $settings,
			'restricted'           => $restricted,
			'category'             => '' !== $category ? $category : 'all',
			'available_categories' => $available_categories,
		];
	}

	/**
	 * Get the settings category map.
	 *
	 * Maps category names to arrays of setting keys that belong to each category.
	 * Only keys in this map are exposed via get_bricks_settings.
	 *
	 * @return array<string, array<int, string>> Category-to-keys map.
	 */
	private function get_settings_category_map(): array {
		return [
			'general'      => [
				'postTypes',
				'wp_to_bricks',
				'bricks_to_wp',
				'deleteBricksData',
				'duplicateContent',
				'searchResultsQueryBricksData',
			],
			'performance'  => [
				'disableEmojis',
				'disableEmbed',
				'disableJqueryMigrate',
				'disableLazyLoad',
				'offsetLazyLoad',
				'cssLoading',
				'webfontLoading',
				'disableGoogleFonts',
				'customFontsPreload',
				'cacheQueryLoops',
				'disableBricksCascadeLayer',
				'disableClassChaining',
				'disableSkipLinks',
				'smoothScroll',
				'elementAttsAsNeeded',
				'themeStylesLoadingMethod',
			],
			'builder'      => [
				'builderMode',
				'builderAutosaveDisabled',
				'builderAutosaveInterval',
				'builderToolbarLogoLink',
				'builderDisableGlobalClassesInterface',
				'builderDisableRestApi',
				'builderInsertElement',
				'builderInsertLayout',
				'builderGlobalClassesImport',
				'builderHtmlCssConverter',
				'customBreakpoints',
				'enableDynamicDataPreview',
				'enableQueryFilters',
				'bricksComponentsInBlockEditor',
			],
			'templates'    => [
				'publicTemplates',
				'defaultTemplatesDisabled',
				'convertTemplates',
				'generateTemplateScreenshots',
				'myTemplatesAccess',
				'remoteTemplates',
				'remoteTemplatesUrl',
			],
			'integrations' => [
				'apiKeyGoogleMaps',
				'apiKeyGoogleRecaptcha',
				'apiSecretKeyGoogleRecaptcha',
				'apiKeyHCaptcha',
				'apiSecretKeyHCaptcha',
				'apiKeyTurnstile',
				'apiSecretKeyTurnstile',
				'apiKeyMailchimp',
				'apiKeySendgrid',
				'apiKeyUnsplash',
				'instagramAccessToken',
				'adobeFontsProjectId',
				'facebookAppId',
			],
			'woocommerce'  => [
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
			],
		];
	}

	/**
	 * Mask sensitive settings in-place.
	 *
	 * Replaces non-empty API keys and secrets with ****configured**** to prevent
	 * exposure of credentials via the MCP interface.
	 *
	 * @param array<string, mixed> $settings Settings array to mask (modified in-place).
	 * @return void
	 */
	private function mask_sensitive_settings( array &$settings ): void {
		$masked_keys = [
			'apiKeyGoogleMaps',
			'apiKeyGoogleRecaptcha',
			'apiSecretKeyGoogleRecaptcha',
			'apiKeyHCaptcha',
			'apiSecretKeyHCaptcha',
			'apiKeyTurnstile',
			'apiSecretKeyTurnstile',
			'apiKeyMailchimp',
			'apiKeySendgrid',
			'apiKeyUnsplash',
			'instagramAccessToken',
			'adobeFontsProjectId',
			'facebookAppId',
			'myTemplatesPassword',
			'remoteTemplatesPassword',
		];

		foreach ( $masked_keys as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				$settings[ $key ] = '****configured****';
			}
		}
	}

	/**
	 * Get page-level Bricks settings for a specific post.
	 *
	 * Reads the _bricks_page_settings post meta and returns structured data
	 * with available setting groups.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error Page settings data or error.
	 */
	public function get_page_settings( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use list_pages or get_posts to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		return [
			'post_id'          => $post_id,
			'post_title'       => $post->post_title,
			'settings'         => $settings,
			'available_groups' => [ 'general', 'scroll-snap', 'seo', 'social-media', 'one-page', 'custom-code' ],
		];
	}

	/**
	 * Update page-level Bricks settings with allowlist validation.
	 *
	 * Validates each key against the page settings allowlist. Unknown keys are
	 * rejected. JS-related keys require dangerous actions mode. CSS writes include
	 * a Bricks-first principle warning. Null values delete individual settings.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $updates Key-value pairs to update.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_settings( int $post_id, array $updates ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use list_pages or get_posts to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$meta_key  = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings  = get_post_meta( $post_id, $meta_key, true );
		$allowlist = $this->get_page_settings_allowlist();

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$js_gated_keys   = [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ];
		$text_fields     = [ 'bodyClasses', 'postTitle', 'documentTitle', 'metaKeywords', 'sharingTitle' ];
		$textarea_fields = [ 'metaDescription', 'sharingDescription' ];

		$rejected      = [];
		$rejected_keys = [];
		$updated_keys  = [];
		$warnings      = [];
		$css_set       = false;
		$js_set        = false;

		foreach ( $updates as $key => $value ) {
			// Check key against allowlist.
			if ( ! in_array( $key, $allowlist, true ) ) {
				$rejected[]      = [
					'key'    => $key,
					'reason' => __( 'unknown key', 'bricks-mcp' ),
				];
				$rejected_keys[] = $key;
				continue;
			}

			// Check JS-gated keys.
			if ( in_array( $key, $js_gated_keys, true ) && ! $this->is_dangerous_actions_enabled() ) {
				$rejected[]      = [
					'key'    => $key,
					'reason' => __( 'requires dangerous actions mode (Settings > Bricks MCP > Enable Dangerous Actions)', 'bricks-mcp' ),
				];
				$rejected_keys[] = $key;
				continue;
			}

			// Null value = delete.
			if ( null === $value ) {
				unset( $settings[ $key ] );
				$updated_keys[] = $key;
				continue;
			}

			// Sanitize text fields.
			if ( in_array( $key, $text_fields, true ) && is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}

			// Sanitize textarea fields.
			if ( in_array( $key, $textarea_fields, true ) && is_string( $value ) ) {
				$value = sanitize_textarea_field( $value );
			}

			// Track CSS/JS writes for warnings.
			if ( 'customCss' === $key ) {
				$css_set = true;
			}
			if ( in_array( $key, $js_gated_keys, true ) ) {
				$js_set = true;
			}

			$settings[ $key ] = $value;
			$updated_keys[]   = $key;
		}

		update_post_meta( $post_id, $meta_key, $settings );

		// Build warnings.
		if ( $css_set ) {
			$warnings[] = __( 'Bricks-first principle: prefer native Bricks elements and classes over custom CSS. Only use custom CSS when the desired result cannot be achieved with Bricks features.', 'bricks-mcp' );
		}
		if ( $js_set ) {
			$warnings[] = __( 'Custom scripts execute on the frontend. Ensure code is safe and necessary.', 'bricks-mcp' );
		}

		return [
			'post_id'      => $post_id,
			'settings'     => $settings,
			'updated_keys' => $updated_keys,
			'rejected'     => $rejected,
			'warnings'     => $warnings,
		];
	}

	/**
	 * Get the page settings allowlist.
	 *
	 * Returns a flat array of all valid page setting keys accepted by
	 * update_page_settings. Organized by group for clarity.
	 *
	 * @return array<int, string> Flat array of allowed setting keys.
	 */
	private function get_page_settings_allowlist(): array {
		return [
			// General.
			'bodyClasses',
			'headerDisabled',
			'footerDisabled',
			'disableLazyLoad',
			'popupDisabled',
			'siteLayout',
			'siteLayoutBoxedMaxWidth',
			'contentBoxShadow',
			'contentBackground',
			'siteBackground',
			'contentMargin',
			'siteBorder',
			'elementMargin',
			'sectionMargin',
			'sectionPadding',
			'containerMaxWidth',
			'lightboxBackground',
			'lightboxCloseColor',
			'lightboxCloseSize',
			'lightboxWidth',
			'lightboxHeight',

			// Scroll snap.
			'scrollSnapType',
			'scrollSnapSelector',
			'scrollSnapAlign',
			'scrollMargin',
			'scrollPadding',
			'scrollSnapStop',

			// SEO.
			'postName',
			'postTitle',
			'documentTitle',
			'metaDescription',
			'metaKeywords',
			'metaRobots',

			// Social media.
			'sharingTitle',
			'sharingDescription',
			'sharingImage',

			// One-page navigation.
			'onePageNavigation',
			'onePageNavigationItemSpacing',
			'onePageNavigationItemHeight',
			'onePageNavigationItemWidth',
			'onePageNavigationItemColor',
			'onePageNavigationItemBorder',
			'onePageNavigationItemBoxShadow',
			'onePageNavigationItemHeightActive',
			'onePageNavigationItemWidthActive',
			'onePageNavigationItemColorActive',
			'onePageNavigationItemBorderActive',
			'onePageNavigationItemBoxShadowActive',

			// Custom code.
			'customCss',
			'customScriptsHeader',
			'customScriptsBodyHeader',
			'customScriptsBodyFooter',
		];
	}

	/**
	 * Get popup display settings for a popup-type template.
	 *
	 * Reads only popup-prefixed keys and template_interactions from
	 * `_bricks_template_settings`. Validates the template is type `popup`.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string, mixed>|\WP_Error Popup settings data or error.
	 */
	public function get_popup_settings( int $template_id ): array|\WP_Error {
		$post = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		$type = get_post_meta( $template_id, '_bricks_template_type', true );
		if ( 'popup' !== $type ) {
			return new \WP_Error(
				'wrong_type',
				sprintf(
					/* translators: 1: Template ID, 2: Actual type */
					__( "Template %1\$d is type '%2\$s', not 'popup'.", 'bricks-mcp' ),
					$template_id,
					$type
				)
			);
		}

		$settings = get_post_meta( $template_id, '_bricks_template_settings', true );
		$settings = is_array( $settings ) ? $settings : [];

		// Extract only popup* keys.
		$popup_keys = array_filter(
			$settings,
			fn( $key ) => str_starts_with( $key, 'popup' ),
			ARRAY_FILTER_USE_KEY
		);

		// Extract template_interactions if present.
		$template_interactions = $settings['template_interactions'] ?? [];

		return array(
			'template_id'            => $template_id,
			'title'                  => $post->post_title,
			'is_infobox'             => ! empty( $popup_keys['popupIsInfoBox'] ),
			'popup_settings'         => $popup_keys,
			'template_interactions'  => $template_interactions,
		);
	}

	/**
	 * Set popup display settings on a popup-type template.
	 *
	 * Validates keys against the popup settings allowlist, then merges into
	 * existing `_bricks_template_settings` — preserving all other keys
	 * (conditions, headerPosition, etc.). Null value on a key deletes it.
	 *
	 * @param int                    $template_id    Template post ID.
	 * @param array<string, mixed>   $popup_settings Key-value pairs of popup settings.
	 * @return array<string, mixed>|\WP_Error Updated settings data or error.
	 */
	public function set_popup_settings( int $template_id, array $popup_settings ): array|\WP_Error {
		$post = get_post( $template_id );

		if ( ! $post || 'bricks_template' !== $post->post_type ) {
			return new \WP_Error(
				'not_found',
				sprintf(
					/* translators: %d: Template ID */
					__( 'Template %d not found. Verify the template_id is a valid bricks_template post.', 'bricks-mcp' ),
					$template_id
				)
			);
		}

		$type = get_post_meta( $template_id, '_bricks_template_type', true );
		if ( 'popup' !== $type ) {
			return new \WP_Error(
				'wrong_type',
				sprintf(
					/* translators: 1: Template ID, 2: Actual type */
					__( "Template %1\$d is type '%2\$s', not 'popup'.", 'bricks-mcp' ),
					$template_id,
					$type
				)
			);
		}

		// Validate keys against allowlist.
		$allowed_keys = $this->get_popup_settings_allowlist();
		$unknown      = array_diff( array_keys( $popup_settings ), $allowed_keys );
		if ( ! empty( $unknown ) ) {
			return new \WP_Error(
				'unknown_keys',
				sprintf(
					/* translators: %s: Unknown key names */
					__( 'Unknown popup setting keys: %s', 'bricks-mcp' ),
					implode( ', ', $unknown )
				)
			);
		}

		// Read-merge-write pattern — preserve all other settings keys.
		$this->unhook_bricks_meta_filters();
		try {
			$settings = get_post_meta( $template_id, '_bricks_template_settings', true );
			$settings = is_array( $settings ) ? $settings : [];

			foreach ( $popup_settings as $key => $value ) {
				if ( null === $value ) {
					unset( $settings[ $key ] );
				} else {
					$settings[ $key ] = $value;
				}
			}

			update_post_meta( $template_id, '_bricks_template_settings', $settings );
		} finally {
			$this->rehook_bricks_meta_filters();
		}

		// Re-read to return current state.
		$updated = $this->get_popup_settings( $template_id );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'template_id'           => $template_id,
			'updated_keys'          => array_keys( $popup_settings ),
			'popup_settings'        => $updated['popup_settings'],
			'template_interactions' => $updated['template_interactions'],
		);
	}

	/**
	 * Get the allowlist of valid popup settings keys.
	 *
	 * Source: Bricks `includes/popups.php` — `Popups::set_controls()`.
	 *
	 * @return string[] Array of valid popup setting key names.
	 */
	private function get_popup_settings_allowlist(): array {
		return array(
			// Outer popup settings.
			'popupPadding',
			'popupJustifyContent',
			'popupAlignItems',
			'popupCloseOn',
			'popupZindex',
			'popupBodyScroll',
			'popupScrollToTop',
			'popupDisableAutoFocus',
			// Info box.
			'popupIsInfoBox',
			'popupInfoBoxWidth',
			// AJAX content loading.
			'popupAjax',
			'popupIsWoo',
			'popupAjaxLoaderAnimation',
			'popupAjaxLoaderColor',
			'popupAjaxLoaderScale',
			'popupAjaxLoaderSelector',
			// Breakpoint visibility.
			'popupBreakpointMode',
			'popupShowAt',
			'popupShowOn',
			// Backdrop.
			'popupDisableBackdrop',
			'popupBackground',
			'popupBackdropTransition',
			// Content box sizing.
			'popupContentPadding',
			'popupContentWidth',
			'popupContentMinWidth',
			'popupContentMaxWidth',
			'popupContentHeight',
			'popupContentMinHeight',
			'popupContentMaxHeight',
			'popupContentBackground',
			'popupContentBorder',
			'popupContentBoxShadow',
			// Display limits.
			'popupLimitWindow',
			'popupLimitSessionStorage',
			'popupLimitLocalStorage',
			'popupLimitTimeStorage',
			// Template-level interactions.
			'template_interactions',
		);
	}

	/**
	 * Check if dangerous actions mode is enabled.
	 *
	 * Reads the bricks_mcp_settings option to check the dangerous_actions toggle.
	 * Used to gate JS writes on page settings and global settings mutations.
	 *
	 * @return bool True if dangerous actions mode is enabled.
	 */
	public function is_dangerous_actions_enabled(): bool {
		$settings = get_option( 'bricks_mcp_settings', [] );
		return ! empty( $settings['dangerous_actions'] );
	}

	/**
	 * Regenerate the Bricks style manager CSS file.
	 *
	 * Feature-checked: only calls the Bricks method if it exists (v2.2+).
	 * Returns whether the CSS was successfully regenerated.
	 *
	 * @return bool True if CSS was regenerated, false if method not available.
	 */
	private function regenerate_style_manager_css(): bool {
		if ( class_exists( '\Bricks\Ajax' ) && method_exists( '\Bricks\Ajax', 'generate_style_manager_css_file' ) ) {
			\Bricks\Ajax::generate_style_manager_css_file();
			return true;
		}

		return false;
	}

	/**
	 * Detect the active SEO plugin.
	 *
	 * Priority order: Yoast > Rank Math > SEOPress > Slim SEO > Bricks native.
	 * Detection is centralized here so adding a new plugin requires one change.
	 *
	 * @return string Plugin key: 'yoast', 'rankmath', 'seopress', 'slimseo', or 'bricks'.
	 */
	private function detect_seo_plugin(): string {
		if ( class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( class_exists( 'SeoPress_Seo_Metabox' ) || function_exists( 'seopress_init' ) ) {
			return 'seopress';
		}
		if ( class_exists( 'SlimSEO\MetaTags\Title' ) ) {
			return 'slimseo';
		}
		return 'bricks';
	}

	/**
	 * Get unified SEO data from the active SEO plugin.
	 *
	 * Reads normalized SEO fields from whichever SEO plugin is active (Yoast, Rank Math,
	 * SEOPress, Slim SEO) or falls back to Bricks native page settings. Includes an inline
	 * SEO audit with title/description length checks and OG image detection.
	 *
	 * @param int $post_id Post ID to read SEO data for.
	 * @return array<string, mixed>|\WP_Error Normalized SEO data with audit, or error.
	 */
	public function get_seo_data( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		$plugin = $this->detect_seo_plugin();

		$data = array(
			'post_id'       => $post_id,
			'seo_plugin'    => $plugin,
			'plugin_active' => 'bricks' !== $plugin,
			'fields'        => array(),
			'audit'         => array(),
		);

		switch ( $plugin ) {
			case 'yoast':
				$noindex  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
				$nofollow = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

				$data['fields'] = array(
					'title'               => get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: '',
					'description'         => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: '',
					'robots_noindex'      => '1' === $noindex,
					'robots_nofollow'     => '1' === $nofollow,
					'canonical'           => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) ?: '',
					'og_title'            => get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ) ?: '',
					'og_description'      => get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ) ?: '',
					'og_image'            => get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) ?: '',
					'twitter_title'       => get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true ) ?: '',
					'twitter_description' => get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true ) ?: '',
					'twitter_image'       => get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ) ?: '',
					'focus_keyword'       => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: '',
				);
				break;

			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots = is_array( $robots ) ? $robots : array();

				$data['fields'] = array(
					'title'           => get_post_meta( $post_id, 'rank_math_title', true ) ?: '',
					'description'     => get_post_meta( $post_id, 'rank_math_description', true ) ?: '',
					'robots_noindex'  => in_array( 'noindex', $robots, true ),
					'robots_nofollow' => in_array( 'nofollow', $robots, true ),
					'canonical'       => get_post_meta( $post_id, 'rank_math_canonical_url', true ) ?: '',
					'focus_keyword'   => get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ?: '',
					'og_image'        => get_post_meta( $post_id, 'rank_math_facebook_image', true ) ?: '',
				);
				break;

			case 'seopress':
				$noindex  = get_post_meta( $post_id, '_seopress_robots_index', true );
				$nofollow = get_post_meta( $post_id, '_seopress_robots_follow', true );

				$data['fields'] = array(
					'title'               => get_post_meta( $post_id, '_seopress_titles_title', true ) ?: '',
					'description'         => get_post_meta( $post_id, '_seopress_titles_desc', true ) ?: '',
					'robots_noindex'      => 'yes' === $noindex,
					'robots_nofollow'     => 'yes' === $nofollow,
					'canonical'           => get_post_meta( $post_id, '_seopress_robots_canonical', true ) ?: '',
					'og_title'            => get_post_meta( $post_id, '_seopress_social_fb_title', true ) ?: '',
					'og_description'      => get_post_meta( $post_id, '_seopress_social_fb_desc', true ) ?: '',
					'og_image'            => get_post_meta( $post_id, '_seopress_social_fb_img', true ) ?: '',
					'twitter_title'       => get_post_meta( $post_id, '_seopress_social_twitter_title', true ) ?: '',
					'twitter_image'       => get_post_meta( $post_id, '_seopress_social_twitter_img', true ) ?: '',
				);
				break;

			case 'slimseo':
				$slim = get_post_meta( $post_id, 'slim_seo', true );
				$slim = is_array( $slim ) ? $slim : array();

				$data['fields'] = array(
					'title'       => $slim['title'] ?? '',
					'description' => $slim['description'] ?? '',
					'canonical'   => $slim['canonical'] ?? '',
				);
				break;

			case 'bricks':
			default:
				$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
				$settings = get_post_meta( $post_id, $meta_key, true );
				$settings = is_array( $settings ) ? $settings : array();

				$data['fields'] = array(
					'title'          => $settings['documentTitle'] ?? '',
					'description'    => $settings['metaDescription'] ?? '',
					'keywords'       => $settings['metaKeywords'] ?? '',
					'robots'         => $settings['metaRobots'] ?? '',
					'og_title'       => $settings['sharingTitle'] ?? '',
					'og_description' => $settings['sharingDescription'] ?? '',
					'og_image'       => $settings['sharingImage'] ?? '',
				);
				break;
		}

		// SEO audit: simple quality checks.
		$title       = $data['fields']['title'] ?? '';
		$description = $data['fields']['description'] ?? '';
		$title_len   = strlen( $title );
		$desc_len    = strlen( $description );

		$data['audit'] = array(
			'title_length'       => $title_len,
			'title_ok'           => $title_len >= 30 && $title_len <= 60,
			'title_issue'        => 0 === $title_len ? 'missing' : ( $title_len < 30 ? 'too_short' : ( $title_len > 60 ? 'too_long' : null ) ),
			'description_length' => $desc_len,
			'description_ok'     => $desc_len >= 120 && $desc_len <= 160,
			'description_issue'  => 0 === $desc_len ? 'missing' : ( $desc_len < 120 ? 'too_short' : ( $desc_len > 160 ? 'too_long' : null ) ),
			'has_og_image'       => ! empty( $data['fields']['og_image'] ),
		);

		return $data;
	}

	/**
	 * Update SEO data via the active SEO plugin.
	 *
	 * Writes normalized field names to the correct plugin meta keys. Sanitizes text
	 * fields via sanitize_text_field() and URL fields via esc_url_raw(). Tracks which
	 * fields were updated, unsupported, or skipped.
	 *
	 * @param int                  $post_id Post ID to update SEO data for.
	 * @param array<string, mixed> $fields  Normalized SEO field values to write.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_seo_data( int $post_id, array $fields ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found. Use page tool (action: list) to find valid post IDs.', 'bricks-mcp' )
			);
		}

		if ( empty( $fields ) ) {
			return new \WP_Error(
				'empty_fields',
				__( 'At least one SEO field must be provided. Accepted: title, description, robots_noindex, robots_nofollow, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, focus_keyword.', 'bricks-mcp' )
			);
		}

		$plugin = $this->detect_seo_plugin();

		// Accepted normalized field names.
		$text_fields = array( 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'focus_keyword' );
		$url_fields  = array( 'canonical', 'og_image', 'twitter_image' );
		$bool_fields = array( 'robots_noindex', 'robots_nofollow' );
		$all_fields  = array_merge( $text_fields, $url_fields, $bool_fields );

		// Sanitize inputs.
		$sanitized = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $all_fields, true ) ) {
				continue;
			}
			if ( in_array( $key, $text_fields, true ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( in_array( $key, $url_fields, true ) ) {
				$sanitized[ $key ] = esc_url_raw( (string) $value );
			} elseif ( in_array( $key, $bool_fields, true ) ) {
				$sanitized[ $key ] = (bool) $value;
			}
		}

		$updated     = array();
		$unsupported = array();

		$plugin_notes = array(
			'yoast'    => __( 'SEO fields written to Yoast SEO meta keys. Changes will appear in the Yoast metabox.', 'bricks-mcp' ),
			'rankmath' => __( 'SEO fields written to Rank Math meta keys. Changes will appear in the Rank Math metabox.', 'bricks-mcp' ),
			'seopress' => __( 'SEO fields written to SEOPress meta keys. Changes will appear in the SEOPress metabox.', 'bricks-mcp' ),
			'slimseo'  => __( 'SEO fields written to Slim SEO meta key. Only title, description, and canonical are supported.', 'bricks-mcp' ),
			'bricks'   => __( 'SEO fields written to Bricks native page settings. Only effective when no SEO plugin is active.', 'bricks-mcp' ),
		);

		switch ( $plugin ) {
			case 'yoast':
				$yoast_map = array(
					'title'               => '_yoast_wpseo_title',
					'description'         => '_yoast_wpseo_metadesc',
					'canonical'           => '_yoast_wpseo_canonical',
					'og_title'            => '_yoast_wpseo_opengraph-title',
					'og_description'      => '_yoast_wpseo_opengraph-description',
					'og_image'            => '_yoast_wpseo_opengraph-image',
					'twitter_title'       => '_yoast_wpseo_twitter-title',
					'twitter_description' => '_yoast_wpseo_twitter-description',
					'twitter_image'       => '_yoast_wpseo_twitter-image',
					'focus_keyword'       => '_yoast_wpseo_focuskw',
				);

				foreach ( $yoast_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// Robots booleans.
				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $sanitized['robots_noindex'] ? '1' : '' );
					$updated[] = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $sanitized['robots_nofollow'] ? '1' : '' );
					$updated[] = 'robots_nofollow';
				}
				break;

			case 'rankmath':
				$rm_map = array(
					'title'         => 'rank_math_title',
					'description'   => 'rank_math_description',
					'canonical'     => 'rank_math_canonical_url',
					'focus_keyword' => 'rank_math_focus_keyword',
					'og_image'      => 'rank_math_facebook_image',
				);

				foreach ( $rm_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// Rank Math robots: read-modify-write array.
				$robots_changed = false;
				$robots         = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots         = is_array( $robots ) ? $robots : array();

				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					if ( $sanitized['robots_noindex'] ) {
						$robots[] = 'noindex';
					} else {
						$robots = array_diff( $robots, array( 'noindex' ) );
					}
					$robots_changed = true;
					$updated[]      = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					if ( $sanitized['robots_nofollow'] ) {
						$robots[] = 'nofollow';
					} else {
						$robots = array_diff( $robots, array( 'nofollow' ) );
					}
					$robots_changed = true;
					$updated[]      = 'robots_nofollow';
				}

				if ( $robots_changed ) {
					update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $robots ) ) );
				}

				// Rank Math unsupported fields.
				$rm_unsupported = array( 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'twitter_image' );
				foreach ( $rm_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Rank Math uses the main title/description for OG/Twitter.', 'bricks-mcp' );
					}
				}
				break;

			case 'seopress':
				$sp_map = array(
					'title'               => '_seopress_titles_title',
					'description'         => '_seopress_titles_desc',
					'canonical'           => '_seopress_robots_canonical',
					'og_title'            => '_seopress_social_fb_title',
					'og_description'      => '_seopress_social_fb_desc',
					'og_image'            => '_seopress_social_fb_img',
					'twitter_title'       => '_seopress_social_twitter_title',
					'twitter_description' => '_seopress_social_twitter_desc',
					'twitter_image'       => '_seopress_social_twitter_img',
				);

				foreach ( $sp_map as $field => $meta_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						update_post_meta( $post_id, $meta_key, $sanitized[ $field ] );
						$updated[] = $field;
					}
				}

				// SEOPress robots booleans.
				if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
					update_post_meta( $post_id, '_seopress_robots_index', $sanitized['robots_noindex'] ? 'yes' : '' );
					$updated[] = 'robots_noindex';
				}
				if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
					update_post_meta( $post_id, '_seopress_robots_follow', $sanitized['robots_nofollow'] ? 'yes' : '' );
					$updated[] = 'robots_nofollow';
				}

				// SEOPress unsupported.
				if ( array_key_exists( 'focus_keyword', $sanitized ) ) {
					$unsupported['focus_keyword'] = __( 'SEOPress does not support focus keyword per post.', 'bricks-mcp' );
				}
				break;

			case 'slimseo':
				// Slim SEO: read-modify-write single serialized array.
				$slim              = get_post_meta( $post_id, 'slim_seo', true );
				$slim              = is_array( $slim ) ? $slim : array();
				$slim_allowed_keys = array( 'title', 'description', 'canonical' );

				foreach ( $slim_allowed_keys as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$slim[ $field ] = $sanitized[ $field ];
						$updated[]      = $field;
					}
				}

				if ( ! empty( $updated ) ) {
					update_post_meta( $post_id, 'slim_seo', $slim );
				}

				// Slim SEO unsupported fields.
				$slim_unsupported = array( 'robots_noindex', 'robots_nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'focus_keyword' );
				foreach ( $slim_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Slim SEO only supports title, description, and canonical per post.', 'bricks-mcp' );
					}
				}
				break;

			case 'bricks':
			default:
				$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
				$settings = get_post_meta( $post_id, $meta_key, true );
				$settings = is_array( $settings ) ? $settings : array();

				$bricks_map = array(
					'title'          => 'documentTitle',
					'description'    => 'metaDescription',
					'og_title'       => 'sharingTitle',
					'og_description' => 'sharingDescription',
					'og_image'       => 'sharingImage',
				);

				foreach ( $bricks_map as $field => $settings_key ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$settings[ $settings_key ] = $sanitized[ $field ];
						$updated[]                 = $field;
					}
				}

				// Bricks robots: map booleans to metaRobots string.
				if ( array_key_exists( 'robots_noindex', $sanitized ) || array_key_exists( 'robots_nofollow', $sanitized ) ) {
					$robots_parts = array();
					$noindex      = $sanitized['robots_noindex'] ?? false;
					$nofollow     = $sanitized['robots_nofollow'] ?? false;

					if ( $noindex ) {
						$robots_parts[] = 'noindex';
					}
					if ( $nofollow ) {
						$robots_parts[] = 'nofollow';
					}

					$settings['metaRobots'] = implode( ', ', $robots_parts );

					if ( array_key_exists( 'robots_noindex', $sanitized ) ) {
						$updated[] = 'robots_noindex';
					}
					if ( array_key_exists( 'robots_nofollow', $sanitized ) ) {
						$updated[] = 'robots_nofollow';
					}
				}

				if ( ! empty( $updated ) ) {
					update_post_meta( $post_id, $meta_key, $settings );
				}

				// Bricks unsupported fields.
				$bricks_unsupported = array( 'canonical', 'twitter_title', 'twitter_description', 'twitter_image', 'focus_keyword' );
				foreach ( $bricks_unsupported as $field ) {
					if ( array_key_exists( $field, $sanitized ) ) {
						$unsupported[ $field ] = __( 'Bricks native SEO does not support this field.', 'bricks-mcp' );
					}
				}
				break;
		}

		return array(
			'post_id'             => $post_id,
			'seo_plugin'          => $plugin,
			'updated_fields'      => array_unique( $updated ),
			'unsupported_fields'  => $unsupported,
			'note'                => $plugin_notes[ $plugin ] ?? '',
		);
	}

	/**
	 * Export a single template as Bricks-compatible JSON.
	 *
	 * @param int  $template_id    Template post ID.
	 * @param bool $include_classes Whether to include referenced global classes.
	 * @return array<string, mixed>|\WP_Error Export data or error.
	 */
	public function export_template( int $template_id, bool $include_classes = false ): array|\WP_Error {
		$post = get_post( $template_id );

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

		$content = get_post_meta( $template_id, $this->resolve_elements_meta_key( $template_id ), true ) ?: array();

		$template_type_key = defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ? BRICKS_DB_TEMPLATE_TYPE : '_bricks_template_type';
		$page_settings_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';

		$export = array(
			'title'            => get_the_title( $template_id ),
			'templateType'     => get_post_meta( $template_id, $template_type_key, true ) ?: '',
			'content'          => $content,
			'pageSettings'     => get_post_meta( $template_id, $page_settings_key, true ) ?: array(),
			'templateSettings' => get_post_meta( $template_id, '_bricks_template_settings', true ) ?: array(),
		);

		if ( $include_classes && is_array( $content ) && ! empty( $content ) ) {
			$referenced_ids = array();

			foreach ( $content as $element ) {
				if ( ! empty( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
					$referenced_ids = array_merge( $referenced_ids, $element['settings']['_cssGlobalClasses'] );
				}
			}

			$referenced_ids = array_unique( $referenced_ids );

			if ( ! empty( $referenced_ids ) ) {
				$all_classes = get_option( 'bricks_global_classes', array() );
				$used_classes = array_filter(
					$all_classes,
					fn( $class ) => in_array( $class['id'] ?? '', $referenced_ids, true )
				);
				$export['globalClasses'] = array_values( $used_classes );
			}
		}

		return $export;
	}

	/**
	 * Import a template from parsed JSON data.
	 *
	 * Creates a new bricks_template post with regenerated element IDs.
	 *
	 * @param array<string, mixed> $data Template data with title, content, and optional metadata.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	public function import_template( array $data ): array|\WP_Error {
		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			return new \WP_Error(
				'invalid_template',
				__( 'Template must have a non-empty title string.', 'bricks-mcp' )
			);
		}

		if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			return new \WP_Error(
				'invalid_template',
				__( 'Template must have a non-empty content array of Bricks elements.', 'bricks-mcp' )
			);
		}

		$template_id = wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( $data['title'] ),
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		if ( 0 === $template_id ) {
			return new \WP_Error(
				'insert_failed',
				__( 'Failed to create template post.', 'bricks-mcp' )
			);
		}

		// Set template type FIRST so that resolve_elements_meta_key() picks the right
		// meta key when save_elements() is called below.
		// Header/footer types use _bricks_page_header_2 / _bricks_page_footer_2;
		// all other types (section, page, wc_*, etc.) use _bricks_page_content_2.
		$template_type = sanitize_text_field( $data['templateType'] ?? 'section' );
		update_post_meta( $template_id, '_bricks_template_type', $template_type );

		// Regenerate element IDs to prevent collisions.
		$content = $this->normalizer->normalize( $data['content'] );

		// Save content via save_elements() so the correct meta key is resolved for
		// header/footer templates and Bricks sanitize filters are properly unhooked.
		$save_result = $this->save_elements( $template_id, $content );
		if ( is_wp_error( $save_result ) ) {
			wp_delete_post( $template_id, true );
			return new \WP_Error(
				'import_save_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Template created but element save failed: %s. Template has been rolled back.', 'bricks-mcp' ),
					$save_result->get_error_message()
				)
			);
		}

		// Save page settings if provided, stripping JS-capable keys when dangerous_actions is disabled.
		$page_settings_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$stripped_js_keys  = array();
		if ( ! empty( $data['pageSettings'] ) && is_array( $data['pageSettings'] ) ) {
			$js_gated_keys = array( 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' );
			$page_settings = $data['pageSettings'];
			if ( ! $this->is_dangerous_actions_enabled() ) {
				$stripped_js_keys = array_values( array_intersect( array_keys( $page_settings ), $js_gated_keys ) );
				$page_settings    = array_diff_key( $page_settings, array_flip( $js_gated_keys ) );
			}
			update_post_meta( $template_id, $page_settings_key, $page_settings );
		}

		// Save template settings if provided (allowlisted keys only).
		if ( ! empty( $data['templateSettings'] ) && is_array( $data['templateSettings'] ) ) {
			$allowed_template_keys = array(
				'templateConditions',
				'headerPosition',
				'headerSticky',
				'templateOrder',
				'templateIncludeChildren',
			);
			$safe_settings         = array_intersect_key(
				$data['templateSettings'],
				array_flip( $allowed_template_keys )
			);
			if ( ! empty( $safe_settings ) ) {
				update_post_meta( $template_id, '_bricks_template_settings', $safe_settings );
			}
		}

		// Merge global classes if present.
		$class_summary = array();
		if ( ! empty( $data['globalClasses'] ) && is_array( $data['globalClasses'] ) ) {
			$class_summary = $this->merge_imported_global_classes( $data['globalClasses'] );
		}

		$result = array(
			'template_id'    => $template_id,
			'title'          => get_the_title( $template_id ),
			'template_type'  => $template_type,
			'elements_count' => count( $content ),
			'global_classes' => $class_summary,
		);

		if ( ! empty( $stripped_js_keys ) ) {
			$result['warnings'] = array(
				sprintf(
					'Stripped JS-capable page settings keys (%s) because dangerous actions mode is disabled. Enable in Settings > Bricks MCP to allow.',
					implode( ', ', $stripped_js_keys )
				),
			);
		}

		return $result;
	}

	/**
	 * Fetch template JSON from a remote URL and import it.
	 *
	 * @param string $url Remote URL returning Bricks template JSON.
	 * @return array<string, mixed>|\WP_Error Import result or error.
	 */
	public function import_template_from_url( string $url ): array|\WP_Error {
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'invalid_url',
				__( 'The provided URL is not valid.', 'bricks-mcp' )
			);
		}

		// GitHub #9: enforce HTTPS-only to prevent plaintext interception.
		if ( 0 !== strpos( strtolower( $url ), 'https://' ) ) {
			return new \WP_Error(
				'https_required',
				__( 'Template import URLs must use HTTPS.', 'bricks-mcp' )
			);
		}

		// GitHub #9: DNS-rebinding guard — resolve the hostname and reject private/reserved IP ranges
		// before making the request. wp_http_validate_url() blocks obvious 127.0.0.1 literals but
		// does NOT protect against DNS rebinding where a public domain resolves to a private IP.
		$parsed_url = wp_parse_url( $url );
		$host       = $parsed_url['host'] ?? '';
		if ( $host ) {
			$resolved_ip = gethostbyname( $host );
			if ( $resolved_ip !== $host ) {
				// Check resolved IP against private/reserved ranges.
				$private_ranges = array(
					'10.0.0.0/8',
					'172.16.0.0/12',
					'192.168.0.0/16',
					'127.0.0.0/8',
					'169.254.0.0/16',
					'::1/128',
					'fc00::/7',
				);
				$ip_long = ip2long( $resolved_ip );
				if ( false !== $ip_long ) {
					foreach ( $private_ranges as $range ) {
						if ( false === strpos( $range, ':' ) ) {
							list( $subnet, $bits ) = explode( '/', $range );
							$mask         = ~( ( 1 << ( 32 - (int) $bits ) ) - 1 );
							$subnet_long  = ip2long( $subnet );
							if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
								return new \WP_Error(
									'ssrf_blocked',
									__( 'Template import URL resolves to a private or reserved IP address.', 'bricks-mcp' )
								);
							}
						}
					}
				}
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Remote URL returned HTTP %d. Expected 200.', 'bricks-mcp' ),
					$status_code
				)
			);
		}

		// GitHub #9: enforce application/json content-type to prevent importing arbitrary binary content.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type && false === strpos( strtolower( $content_type ), 'application/json' ) ) {
			return new \WP_Error(
				'invalid_content_type',
				sprintf(
					/* translators: %s: content-type header value */
					__( 'Remote URL returned unexpected content-type: %s. Expected application/json.', 'bricks-mcp' ),
					esc_html( $content_type )
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Size limit: 10MB.
		if ( strlen( $body ) > 10485760 ) {
			return new \WP_Error(
				'response_too_large',
				__( 'Remote response exceeds 10MB size limit.', 'bricks-mcp' )
			);
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'invalid_json',
				__( 'Remote URL did not return valid JSON.', 'bricks-mcp' )
			);
		}

		return $this->import_template( $data );
	}

	/**
	 * Export global classes as JSON (all or filtered by category).
	 *
	 * @param string $category Optional category ID to filter by.
	 * @return array<string, mixed> Export data with classes, categories, and count.
	 */
	public function export_global_classes( string $category = '' ): array {
		$classes = get_option( 'bricks_global_classes', array() );

		if ( ! empty( $category ) ) {
			$classes = array_filter(
				$classes,
				fn( $c ) => ( $c['category'] ?? '' ) === $category
			);
		}

		$categories = get_option( 'bricks_global_classes_categories', array() );

		return array(
			'classes'    => array_values( $classes ),
			'categories' => is_array( $categories ) ? $categories : array(),
			'count'      => count( $classes ),
		);
	}

	/**
	 * Import global classes from JSON data, merging by name.
	 *
	 * Existing classes (matched by name) are skipped. New classes get regenerated IDs.
	 *
	 * @param array<string, mixed> $data Classes data: either {classes: [...], categories: [...]} or raw array of class objects.
	 * @return array<string, mixed>|\WP_Error Import summary or error.
	 */
	public function import_global_classes_from_json( array $data ): array|\WP_Error {
		// Accept either wrapped format {classes: [...]} or raw array of class objects.
		if ( isset( $data['classes'] ) && is_array( $data['classes'] ) ) {
			$classes_to_import = $data['classes'];
		} elseif ( ! empty( $data ) && isset( $data[0]['name'] ) ) {
			$classes_to_import = $data;
		} else {
			return new \WP_Error(
				'invalid_classes_data',
				__( 'classes_data must be an object with a "classes" array or a raw array of class objects with "name" keys.', 'bricks-mcp' )
			);
		}

		$existing       = get_option( 'bricks_global_classes', array() );
		$existing_names = array_column( $existing, 'name' );
		$existing_ids   = array_column( $existing, 'id' );
		$id_generator   = new ElementIdGenerator();

		$added   = array();
		$skipped = array();

		foreach ( $classes_to_import as $class ) {
			if ( empty( $class['name'] ) ) {
				continue;
			}

			if ( in_array( $class['name'], $existing_names, true ) ) {
				$skipped[] = $class['name'];
				continue;
			}

			// Regenerate ID to prevent collisions.
			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );

			$class['id']    = $new_id;
			$existing_ids[] = $new_id;
			$existing[]     = $class;
			$added[]        = $class['name'];
		}

		if ( ! empty( $added ) ) {
			update_option( 'bricks_global_classes', $existing );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );
		}

		// Merge categories if present.
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$existing_categories = get_option( 'bricks_global_classes_categories', array() );
			if ( ! is_array( $existing_categories ) ) {
				$existing_categories = array();
			}
			$existing_cat_ids = array_column( $existing_categories, 'id' );

			foreach ( $data['categories'] as $cat ) {
				if ( ! empty( $cat['id'] ) && ! in_array( $cat['id'], $existing_cat_ids, true ) ) {
					$existing_categories[] = $cat;
					$existing_cat_ids[]    = $cat['id'];
				}
			}

			update_option( 'bricks_global_classes_categories', $existing_categories );
		}

		return array(
			'added'         => $added,
			'skipped'       => $skipped,
			'added_count'   => count( $added ),
			'skipped_count' => count( $skipped ),
			'total'         => count( $existing ),
		);
	}

	/**
	 * Get font configuration status overview.
	 *
	 * @return array<string, mixed> Font status data.
	 */
	public function get_font_status(): array {
		$settings    = get_option( 'bricks_global_settings', array() );
		$adobe_fonts = get_option( 'bricks_adobe_fonts', array() );

		return array(
			'google_fonts'         => array(
				'enabled' => empty( $settings['disableGoogleFonts'] ),
				'note'    => empty( $settings['disableGoogleFonts'] )
					? __( 'Google Fonts are loaded by default. Use font:update_settings with disable_google_fonts to disable.', 'bricks-mcp' )
					: __( 'Google Fonts are disabled. Use font:update_settings with disable_google_fonts to re-enable.', 'bricks-mcp' ),
			),
			'adobe_fonts'          => array(
				'configured'   => ! empty( $settings['adobeFontsProjectId'] ),
				'fonts_cached' => is_array( $adobe_fonts ) ? count( $adobe_fonts ) : 0,
				'note'         => __( 'Set Adobe Fonts project ID via bricks:update_settings (integrations category, adobeFontsProjectId key). Use font:get_adobe_fonts to list cached fonts.', 'bricks-mcp' ),
			),
			'webfont_loading'      => $settings['webfontLoading'] ?? 'swap',
			'custom_fonts_preload' => ! empty( $settings['customFontsPreload'] ),
			'usage_tip'            => __( 'Apply fonts via _typography["font-family"] in element settings or theme style typography group.', 'bricks-mcp' ),
		);
	}

	/**
	 * Get cached Adobe Fonts from Bricks option storage.
	 *
	 * @return array<string, mixed> Adobe Fonts data.
	 */
	public function get_adobe_fonts(): array {
		$settings    = get_option( 'bricks_global_settings', array() );
		$adobe_fonts = get_option( 'bricks_adobe_fonts', array() );

		if ( empty( $settings['adobeFontsProjectId'] ) ) {
			return array(
				'fonts' => array(),
				'count' => 0,
				'note'  => __( 'Adobe Fonts project ID is not configured. Set it via bricks:update_settings (integrations category, adobeFontsProjectId key).', 'bricks-mcp' ),
			);
		}

		if ( ! is_array( $adobe_fonts ) || empty( $adobe_fonts ) ) {
			return array(
				'fonts' => array(),
				'count' => 0,
				'note'  => __( 'Adobe Fonts project ID is configured but no fonts are cached. Open Bricks settings in the WordPress admin to trigger a refresh.', 'bricks-mcp' ),
			);
		}

		return array(
			'fonts' => $adobe_fonts,
			'count' => count( $adobe_fonts ),
			'note'  => __( 'These fonts are cached from your Adobe Fonts project. Refresh by re-saving the project ID in Bricks settings.', 'bricks-mcp' ),
		);
	}

	/**
	 * Update font-related Bricks settings.
	 *
	 * @param array<string, mixed> $fields Settings to update. Allowed: disableGoogleFonts, webfontLoading, customFontsPreload.
	 * @return array<string, mixed>|\WP_Error Update result.
	 */
	public function update_font_settings( array $fields ): array|\WP_Error {
		$allowed_keys = array( 'disableGoogleFonts', 'webfontLoading', 'customFontsPreload' );
		$valid_loading = array( 'swap', 'block', 'fallback', 'optional', 'auto', '' );

		$settings     = get_option( 'bricks_global_settings', array() );
		$updated      = array();
		$rejected     = array();

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				$rejected[ $key ] = __( 'Not a font setting. Use bricks:update_settings for other Bricks settings.', 'bricks-mcp' );
				continue;
			}

			if ( 'webfontLoading' === $key ) {
				if ( ! in_array( (string) $value, $valid_loading, true ) ) {
					$rejected[ $key ] = sprintf(
						/* translators: %s: Valid values */
						__( 'Invalid value. Must be one of: %s', 'bricks-mcp' ),
						implode( ', ', array_map( fn( $v ) => $v === '' ? '""' : $v, $valid_loading ) )
					);
					continue;
				}
				$settings[ $key ] = (string) $value;
				$updated[]        = $key;
			} else {
				// Boolean settings.
				$settings[ $key ] = ! empty( $value );
				$updated[]        = $key;
			}
		}

		if ( ! empty( $updated ) ) {
			update_option( 'bricks_global_settings', $settings );
		}

		return array(
			'updated'        => $updated,
			'rejected'       => $rejected,
			'current_values' => array(
				'disableGoogleFonts' => ! empty( $settings['disableGoogleFonts'] ),
				'webfontLoading'     => $settings['webfontLoading'] ?? 'swap',
				'customFontsPreload' => ! empty( $settings['customFontsPreload'] ),
			),
		);
	}

	/**
	 * Merge global classes during template import.
	 *
	 * @param array<int, array<string, mixed>> $import_classes Array of global class objects.
	 * @return array<string, array<int, string>> Summary with added and skipped class names.
	 */
	private function merge_imported_global_classes( array $import_classes ): array {
		$existing       = get_option( 'bricks_global_classes', array() );
		$existing_names = array_column( $existing, 'name' );
		$existing_ids   = array_column( $existing, 'id' );
		$id_generator   = new ElementIdGenerator();

		$added   = array();
		$skipped = array();

		foreach ( $import_classes as $class ) {
			if ( empty( $class['name'] ) ) {
				continue;
			}

			if ( in_array( $class['name'], $existing_names, true ) ) {
				$skipped[] = $class['name'];
				continue;
			}

			// Regenerate ID to prevent collisions.
			do {
				$new_id = $id_generator->generate();
			} while ( in_array( $new_id, $existing_ids, true ) );

			$class['id']    = $new_id;
			$existing_ids[] = $new_id;
			$existing[]     = $class;
			$added[]        = $class['name'];
		}

		if ( ! empty( $added ) ) {
			update_option( 'bricks_global_classes', $existing );
			update_option( 'bricks_global_classes_timestamp', time() );
			update_option( 'bricks_global_classes_user', get_current_user_id() );
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Get all custom code (CSS and scripts) for a page.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error Code data or error.
	 */
	public function get_page_code( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'post_id'                 => $post_id,
			'customCss'               => $settings['customCss'] ?? '',
			'customScriptsHeader'     => $settings['customScriptsHeader'] ?? '',
			'customScriptsBodyHeader' => $settings['customScriptsBodyHeader'] ?? '',
			'customScriptsBodyFooter' => $settings['customScriptsBodyFooter'] ?? '',
			'has_css'                 => ! empty( $settings['customCss'] ),
			'has_scripts'             => ! empty( $settings['customScriptsHeader'] )
				|| ! empty( $settings['customScriptsBodyHeader'] )
				|| ! empty( $settings['customScriptsBodyFooter'] ),
		);
	}

	/**
	 * Set page custom CSS.
	 *
	 * Requires dangerous_actions toggle to be enabled.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $css     Custom CSS code. Empty string removes CSS.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_css( int $post_id, string $css ): array|\WP_Error {
		if ( ! $this->is_dangerous_actions_enabled() ) {
			return new \WP_Error(
				'dangerous_actions_disabled',
				__( 'Custom CSS requires the Dangerous Actions toggle to be enabled in Bricks MCP settings. This is a security measure to prevent code injection.', 'bricks-mcp' )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		$meta_key = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( '' === $css ) {
			unset( $settings['customCss'] );
		} else {
			// GitHub #11: sanitize CSS before storing.
			// Enforce a 100 KB hard cap — no legitimate page CSS needs more.
			if ( strlen( $css ) > 102400 ) {
				return new \WP_Error(
					'css_too_large',
					__( 'CSS exceeds the 100 KB limit. Split into smaller blocks or use a stylesheet.', 'bricks-mcp' )
				);
			}

			// Strip patterns that enable code execution or data exfiltration:
			// - javascript: scheme in url() values
			// - IE expression() function
			// - @import rules (can pull in remote stylesheets)
			// - data: scheme in url() (can embed executable payloads)
			// - Behaviour property (IE)
			// - moz-binding (Firefox XBL injection, legacy)
			$dangerous_patterns = array(
				'/url\s*\(\s*["\']?\s*javascript:/i',
				'/expression\s*\(/i',
				'/@import\s/i',
				'/url\s*\(\s*["\']?\s*data:/i',
				'/behaviour\s*:/i',
				'/-moz-binding\s*:/i',
			);
			foreach ( $dangerous_patterns as $pattern ) {
				if ( preg_match( $pattern, $css ) ) {
					return new \WP_Error(
						'css_unsafe_content',
						__( 'CSS contains disallowed patterns (javascript:, expression(), @import, data:, behaviour:). Remove them and try again.', 'bricks-mcp' )
					);
				}
			}

			$settings['customCss'] = $css;
		}

		update_post_meta( $post_id, $meta_key, $settings );

		return array(
			'post_id'          => $post_id,
			'updated'          => true,
			'customCss_length' => strlen( $css ),
		);
	}

	/**
	 * Set page custom scripts.
	 *
	 * Requires dangerous_actions toggle to be enabled.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, string> $scripts Script keys: customScriptsHeader, customScriptsBodyHeader, customScriptsBodyFooter.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_page_scripts( int $post_id, array $scripts ): array|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		if ( ! $this->is_dangerous_actions_enabled() ) {
			return new \WP_Error(
				'dangerous_actions_disabled',
				__( 'Custom scripts require the Dangerous Actions toggle to be enabled in Bricks MCP settings. This is a security measure to prevent accidental code injection.', 'bricks-mcp' )
			);
		}

		$allowed_keys = array( 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' );
		$meta_key     = defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings';
		$settings     = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$updated  = array();
		$rejected = array();

		foreach ( $scripts as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				$rejected[] = $key;
				continue;
			}

			if ( '' === (string) $value ) {
				unset( $settings[ $key ] );
			} else {
				$settings[ $key ] = (string) $value;
			}

			$updated[] = $key;
		}

		if ( ! empty( $updated ) ) {
			update_post_meta( $post_id, $meta_key, $settings );
		}

		return array(
			'post_id'  => $post_id,
			'updated'  => $updated,
			'rejected' => $rejected,
			'warning'  => __( 'Scripts are executed on page load. Test carefully.', 'bricks-mcp' ),
		);
	}
	
	/**
	 * Diagnose a Bricks page for known issues before editing.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Diagnostic report.
	 */
	public function diagnose_page( int $post_id ): array {
		$post     = get_post( $post_id );
		$elements = $this->get_elements( $post_id );
		$issues   = array();
		$id_map   = array();
		foreach ( $elements as $el ) { $id_map[ $el['id'] ] = true; }
		$gcs    = $this->get_global_classes();
		$gc_ids = array();
		foreach ( $gcs as $gc ) { $gc_ids[ $gc['id'] ?? '' ] = true; }
		$ttype = get_post_meta( $post_id, '_bricks_template_type', true );
		if ( in_array( $ttype, array( 'header', 'footer' ), true ) ) {
			if ( ! empty( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) ) {
				$issues[] = array( 'element_id' => null, 'severity' => 'error', 'type' => 'phantom_content', 'message' => ucfirst( $ttype ) . ' has phantom _bricks_page_content_2.', 'fix' => 'Run delete_post_meta( ' . $post_id . ", '_bricks_page_content_2' )" );
			}
		}
		$bps = array_column( $this->get_breakpoints(), 'key' );
		if ( empty( $bps ) ) { $bps = array( 'tablet_landscape', 'tablet_portrait', 'mobile_landscape', 'mobile_portrait', 'mobile' ); }
		foreach ( $elements as $el ) {
			$el_id    = $el['id'] ?? 'unknown';
			$el_name  = $el['name'] ?? 'unknown';
			$settings = $el['settings'] ?? array();
			foreach ( array( 'customScriptsBodyFooter', 'customScriptsBodyHeader', 'customScriptsHeader' ) as $sf ) {
				if ( ! empty( $settings[ $sf ] ) && strpos( $settings[ $sf ], '<script' ) === false ) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'error', 'type' => 'script_missing_tags', 'message' => "Element {$el_id} ({$el_name}): {$sf} has JS without <script> tags.", 'fix' => 'Wrap in <script>...</script>.' );
				}
			}
			if ( 'code' === $el_name && ! empty( $settings['code'] ) && strpos( $settings['code'], '<?php' ) !== false && empty( $settings['executeCode'] ) ) {
				$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'php_execute_disabled', 'message' => "Element {$el_id}: code element has PHP but executeCode is not enabled.", 'fix' => 'Set settings.executeCode = true.' );
			}
			if ( ! empty( $settings['_cssCustom'] ) && strpos( $settings['_cssCustom'], '%root%' ) !== false ) {
				$issues[] = array( 'element_id' => $el_id, 'severity' => 'error', 'type' => 'root_placeholder', 'message' => "Element {$el_id}: _cssCustom uses %root%.", 'fix' => "Replace %root% with #brxe-{$el_id}" );
			}
			if ( array_key_exists( '_maxWidth', $settings ) ) {
				$issues[] = array( 'element_id' => $el_id, 'severity' => 'error', 'type' => 'wrong_key_maxwidth', 'message' => "Element {$el_id}: uses _maxWidth.", 'fix' => 'Rename to _widthMax.' );
			}
			foreach ( array( '_background', 'color', 'iconColor' ) as $ck ) {
				if ( isset( $settings[ $ck ] ) && is_string( $settings[ $ck ] ) && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $settings[ $ck ] ) ) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'plain_string_color', 'message' => "Element {$el_id}: {$ck} is a plain hex string.", 'fix' => 'Change ' . $ck . ' to {"hex":"' . $settings[ $ck ] . '"}.' );
				}
			}
			foreach ( array_keys( $settings ) as $key ) {
				if ( strpos( $key, ':' ) === false ) { continue; }
				$pseudo  = array( 'hover', 'focus', 'active', 'visited', 'placeholder', 'before', 'after' );
				$segs    = explode( ':', $key );
				array_shift( $segs );
				$bad     = false;
				$bad_seg = '';
				foreach ( $segs as $seg ) {
					if ( in_array( $seg, $pseudo, true ) ) { continue; }
					if ( strpos( $seg, 'variant-' ) === 0 ) { continue; }
					if ( ! in_array( $seg, $bps, true ) ) { $bad = true; $bad_seg = $seg; break; }
				}
				if ( $bad ) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'invalid_breakpoint', 'message' => "Element {$el_id}: key '{$key}' has unknown segment '{$bad_seg}'.", 'fix' => 'Valid IDs: ' . implode( ', ', $bps ) . '. Pseudo-states and variant-* are valid.' );
				}
			}
	    	foreach ( ( $el['children'] ?? array() ) as $child_id ) {
				if ( ! isset( $id_map[ $child_id ] ) ) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'error', 'type' => 'orphaned_child', 'message' => "Element {$el_id}: child '{$child_id}' does not exist.", 'fix' => "Remove '{$child_id}' from children." );
				}
			}
			if ( ! empty( $settings['_cssGlobalClasses'] ) ) {
				$used = is_array( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : explode( ' ', $settings['_cssGlobalClasses'] );
				foreach ( $used as $cid ) {
					$cid = trim( $cid );
					if ( ! empty( $cid ) && ! isset( $gc_ids[ $cid ] ) ) {
						$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'missing_global_class', 'message' => "Element {$el_id}: references missing class '{$cid}'.", 'fix' => 'Create via global_class:create or remove.' );
					}
				}
			}
			if ( ! empty( $settings['_border'] ) && is_array( $settings['_border'] ) ) {
				if ( isset( $settings['_border']['width'] ) && is_int( $settings['_border']['width'] ) ) {
					$w = $settings['_border']['width'];
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'integer_border', 'message' => "Element {$el_id}: _border.width is integer {$w}.", 'fix' => "Change to '{$w}px'." );
				}
			}
			
			// Check #11 — missing_wp_reset_postdata
			if ( in_array( $el_name, array( 'html', 'code' ), true ) && ! empty( $settings['executeCode'] ) ) {
				$code_content = '';
				if ( ! empty( $settings['content'] ) ) {
					$code_content = is_array( $settings['content'] ) ? ( $settings['content']['code'] ?? '' ) : $settings['content'];
				} elseif ( ! empty( $settings['code'] ) ) {
					$code_content = is_array( $settings['code'] ) ? ( $settings['code']['code'] ?? '' ) : $settings['code'];
				}
				if (
					! empty( $code_content ) &&
					( str_contains( $code_content, 'new WP_Query' ) || str_contains( $code_content, 'WP_Query(' ) ) &&
					! str_contains( $code_content, 'wp_reset_postdata' )
				) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'missing_wp_reset_postdata', 'message' => "Element {$el_id}: WP_Query found but wp_reset_postdata() is missing.", 'fix' => 'Add wp_reset_postdata() after endwhile; to prevent post data bleed.' );
				}
			}

			// Check #12 — missing_mobile_padding
			if ( in_array( $el_name, array( 'section', 'container', 'div', 'block' ), true ) ) {
				$has_desktop_padding  = ! empty( $settings['_padding'] );
				$has_mobile_portrait  = ! empty( $settings['_padding:mobile_portrait'] );
				$has_mobile_landscape = ! empty( $settings['_padding:mobile_landscape'] );
				$has_mobile_legacy    = ! empty( $settings['_padding:mobile'] );
				if ( $has_desktop_padding && ! $has_mobile_portrait && ! $has_mobile_landscape && ! $has_mobile_legacy ) {
					$issues[] = array( 'element_id' => $el_id, 'severity' => 'warning', 'type' => 'missing_mobile_padding', 'message' => "Element {$el_id} ({$el_name}): has desktop _padding but no mobile override.", 'fix' => 'Add _padding:mobile_portrait to preserve spacing rhythm on small screens.' );
				}
			}
		}
		// Check #13 — hardcoded_design_token
		$global_vars = get_option( 'bricks_global_variables', array() );
		if ( ! empty( $global_vars ) && is_array( $global_vars ) ) {
			$hex_to_var = array();
			foreach ( $global_vars as $gv ) {
				$var_value = $gv['value'] ?? '';
				$var_name  = $gv['name']  ?? '';
				if ( ! empty( $var_value ) && preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $var_value ) ) ) {
					$hex_to_var[ strtolower( trim( $var_value ) ) ] = '--' . sanitize_title( $var_name );
				}
			}
			if ( ! empty( $hex_to_var ) ) {
				$token_keys = array( '_background', '_typography', '_border', '_color', '_gradient' );
				foreach ( $elements as $scan_el ) {
					$scan_id       = $scan_el['id'] ?? '';
					$scan_settings = $scan_el['settings'] ?? array();
					$scan_json     = '';
					foreach ( $token_keys as $tk ) {
						if ( isset( $scan_settings[ $tk ] ) ) {
							$scan_json .= wp_json_encode( $scan_settings[ $tk ] );
						}
					}
					if ( empty( $scan_json ) ) { continue; }
					preg_match_all( '/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $scan_json, $hex_matches );
					foreach ( array_unique( $hex_matches[0] ?? array() ) as $found_hex ) {
						$normalized = strtolower( $found_hex );
						if ( isset( $hex_to_var[ $normalized ] ) ) {
							$issues[] = array( 'element_id' => $scan_id, 'severity' => 'warning', 'type' => 'hardcoded_design_token', 'message' => "Element {$scan_id}: {$found_hex} matches design token var({$hex_to_var[$normalized]}). Use the CSS variable.", 'fix' => "Replace {$found_hex} with {\"raw\":\"var({$hex_to_var[$normalized]})\"} in element settings." );
						}
					}
				}
			}
		}
		
		$errors   = count( array_filter( $issues, fn( $i ) => 'error' === $i['severity'] ) );
		$warnings = count( array_filter( $issues, fn( $i ) => 'warning' === $i['severity'] ) );
		return array(
			'post_id'       => $post_id,
			'title'         => $post ? $post->post_title : '',
			'element_count' => count( $elements ),
			'issue_count'   => count( $issues ),
			'error_count'   => $errors,
			'warning_count' => $warnings,
			'status'        => 0 === count( $issues ) ? 'clean' : ( $errors > 0 ? 'errors' : 'warnings' ),
			'issues'        => $issues,
		);
	}
	
	/**
	 * get_spacing_audit — Tool: page_spacing_audit
	 */
	public function get_spacing_audit( int $post_id ): array {
		$elements = $this->get_elements( $post_id );
		if ( empty( $elements ) || is_wp_error( $elements ) ) {
			return array( 'post_id' => $post_id, 'elements' => array(), 'flags' => array( 'No elements found.' ), 'summary' => array() );
		}
		$bp_suffixes  = array( 'desktop' => '', 'tablet_portrait' => ':tablet_portrait', 'mobile_landscape' => ':mobile_landscape', 'mobile_portrait' => ':mobile_portrait' );
		$layout_types = array( 'section', 'container', 'div', 'block' );
		$report = array(); $v_tops = array(); $flags = array();
		foreach ( $elements as $el ) {
			$el_name = $el['name'] ?? ( $el['element'] ?? '' );
			if ( ! in_array( $el_name, $layout_types, true ) ) { continue; }
			$el_id = $el['id'] ?? 'unknown'; $settings = $el['settings'] ?? array();
			$el_report = array( 'id' => $el_id, 'type' => $el_name, 'label' => $el['label'] ?? $el_name, 'padding' => array(), 'margin' => array(), 'flags' => array() );
			$has_any_padding = false; $has_mobile_pad = false;
			foreach ( $bp_suffixes as $bp => $suffix ) {
				$pad_key = '_padding' . $suffix; $mar_key = '_margin' . $suffix;
				if ( ! empty( $settings[ $pad_key ] ) ) {
					$el_report['padding'][ $bp ] = $settings[ $pad_key ]; $has_any_padding = true;
					if ( $bp !== 'desktop' ) { $has_mobile_pad = true; }
					$top_val = $settings[ $pad_key ]['top'] ?? null;
					if ( $top_val !== null && is_numeric( str_replace( 'px', '', $top_val ) ) ) { $v_tops[] = (int) str_replace( 'px', '', $top_val ); }
				}
				if ( ! empty( $settings[ $mar_key ] ) ) { $el_report['margin'][ $bp ] = $settings[ $mar_key ]; }
			}
			if ( empty( $el_report['padding'] ) ) { $el_report['flags'][] = 'No padding set — may appear cramped.'; }
			if ( $has_any_padding && ! $has_mobile_pad ) { $el_report['flags'][] = 'Desktop padding set but no mobile override.'; }
			$report[] = $el_report;
		}
		$rhythm_score = 'N/A';
		if ( count( $v_tops ) >= 2 ) {
			$mean = array_sum( $v_tops ) / count( $v_tops );
			$std  = sqrt( array_sum( array_map( fn( $x ) => ( $x - $mean ) ** 2, $v_tops ) ) / count( $v_tops ) );
			$rhythm_score = round( $std, 1 );
			if ( $std > 30 ) { $flags[] = sprintf( 'Inconsistent vertical rhythm (std dev: %spx). Values: %s.', $rhythm_score, implode( ', ', array_map( fn($v) => $v . 'px', $v_tops ) ) ); }
		}
		$flagged = array_values( array_filter( $report, fn( $r ) => ! empty( $r['flags'] ) ) );
		return array( 'post_id' => $post_id, 'total_layout_elements' => count( $report ), 'elements' => $report, 'elements_with_flags' => $flagged, 'page_flags' => $flags, 'summary' => array( 'rhythm_std_dev_px' => $rhythm_score, 'top_padding_values' => array_map( fn($v) => $v . 'px', $v_tops ), 'flag_count' => count( $flagged ) ) );
	}

	/**
	 * take_screenshot — Tool: page_screenshot
	 * Microlink primary (free, 50/day), ApiFlash fallback (key in settings).
	 */
	public function take_screenshot( int $post_id, string $viewport = 'desktop', bool $fresh = false ): array|\WP_Error {
		$page_url = get_permalink( $post_id );
		if ( empty( $page_url ) || is_wp_error( $page_url ) ) {
			return new \WP_Error( 'invalid_post', sprintf( 'Cannot resolve permalink for post ID %d.', $post_id ) );
		}
		$vp_map = array( 'desktop' => array( 'width' => 1280, 'height' => 900, 'device' => null ), 'tablet' => array( 'width' => 768, 'height' => 1024, 'device' => null ), 'mobile' => array( 'width' => 390, 'height' => 844, 'device' => 'iphone' ) );
		$vp = $vp_map[ $viewport ] ?? $vp_map['desktop'];
		$ml_args = array( 'url' => rawurlencode( $page_url ), 'screenshot' => 'true', 'meta' => 'false', 'embed' => 'screenshot.url' );
		if ( $vp['device'] ) { $ml_args['device'] = $vp['device']; } else { $ml_args['viewport.width'] = $vp['width']; $ml_args['viewport.height'] = $vp['height']; }
		if ( $fresh ) { $ml_args['force'] = 'true'; }
		$ml_response = wp_remote_get( add_query_arg( $ml_args, 'https://api.microlink.io/' ), array( 'timeout' => 30, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( ! is_wp_error( $ml_response ) && wp_remote_retrieve_response_code( $ml_response ) === 200 ) {
			$body     = json_decode( wp_remote_retrieve_body( $ml_response ), true );
			$shot_url = $body['data']['screenshot']['url'] ?? null;
			if ( ! empty( $shot_url ) ) {
				$img = wp_remote_get( esc_url_raw( $shot_url ), array( 'timeout' => 20 ) );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$b64 = ( ! is_wp_error( $img ) && wp_remote_retrieve_response_code( $img ) === 200 ) ? base64_encode( wp_remote_retrieve_body( $img ) ) : '';
				return array( 'image_url' => $shot_url, 'image_base64' => $b64, 'viewport' => $viewport, 'width' => $vp['width'], 'height' => $vp['height'], 'source' => 'microlink', 'cached' => ! $fresh, 'page_url' => $page_url );
			}
		}
		$mcp_settings = get_option( 'bricks_mcp_settings', array() );
		$apiflash_key = sanitize_text_field( $mcp_settings['apiflash_key'] ?? '' );
		if ( empty( $apiflash_key ) ) {
			return new \WP_Error( 'screenshot_failed', 'Microlink failed and no ApiFlash key configured. Add key in MCP Settings → Claude Desktop tab.' );
		}
		$af_args = array( 'access_key' => $apiflash_key, 'url' => rawurlencode( $page_url ), 'format' => 'png', 'width' => $vp['width'], 'height' => $vp['height'], 'full_page' => 'false', 'fresh' => $fresh ? 'true' : 'false' );
		if ( $viewport === 'mobile' ) { $af_args['mobile'] = 'true'; }
		$af_response = wp_remote_get( esc_url_raw( add_query_arg( $af_args, 'https://api.apiflash.com/v1/urltoimage' ) ), array( 'timeout' => 30, 'sslverify' => true ) );
		if ( is_wp_error( $af_response ) ) { return new \WP_Error( 'screenshot_failed', 'Both services failed: ' . $af_response->get_error_message() ); }
		$af_code = wp_remote_retrieve_response_code( $af_response );
		if ( $af_code !== 200 ) { return new \WP_Error( 'screenshot_failed', sprintf( 'ApiFlash returned HTTP %d. Check API key in Settings.', $af_code ) ); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array( 'image_url' => add_query_arg( $af_args, 'https://api.apiflash.com/v1/urltoimage' ), 'image_base64' => base64_encode( wp_remote_retrieve_body( $af_response ) ), 'viewport' => $viewport, 'width' => $vp['width'], 'height' => $vp['height'], 'source' => 'apiflash', 'cached' => false, 'page_url' => $page_url );
	}

	/**
	 * get_visual_review — Tool: page_visual_review
	 * Screenshot + Claude Vision → structured visual critique.
	 */
	public function get_visual_review( int $post_id, string $viewport = 'desktop', string $focus = 'all' ): array|\WP_Error {
		$mcp_settings  = get_option( 'bricks_mcp_settings', array() );
		$anthropic_key = sanitize_text_field( $mcp_settings['anthropic_api_key'] ?? '' );
		if ( empty( $anthropic_key ) ) {
			return new \WP_Error( 'no_anthropic_key', 'Anthropic API key not configured. Add it in MCP Settings → Claude Desktop tab.' );
		}
		$screenshot = $this->take_screenshot( $post_id, $viewport, false );
		if ( is_wp_error( $screenshot ) ) { return $screenshot; }
		$image_base64   = $screenshot['image_base64'] ?? '';
		$screenshot_url = $screenshot['image_url'] ?? '';
		if ( empty( $image_base64 ) ) { return new \WP_Error( 'empty_screenshot', 'Screenshot image data is empty.' ); }
		$focus_instructions = match ( $focus ) {
			'spacing'   => 'Focus on: section padding rhythm, gaps, vertical spacing.',
			'alignment' => 'Focus on: text alignment consistency, heading hierarchy.',
			'colors'    => 'Focus on: color contrast, design token consistency.',
			default     => 'Analyze all: spacing, alignment, colors, broken elements, mobile layout.',
		};
		$prompt = "You are a professional web design reviewer analyzing a WordPress page built with Bricks Builder.\n\n{$focus_instructions}\n\nReturn ONLY valid JSON (no markdown):\n{\"critique\":\"2-3 sentence assessment\",\"score\":1,\"issues\":[{\"area\":\"string\",\"category\":\"spacing|alignment|colors|broken|mobile\",\"problem\":\"string\",\"severity\":\"critical|warning|suggestion\",\"suggestion\":\"string\"}],\"positive_observations\":[\"string\"]}";
		$api_payload  = array( 'model' => 'claude-sonnet-4-20250514', 'max_tokens' => 1000, 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => 'image/png', 'data' => $image_base64 ) ), array( 'type' => 'text', 'text' => $prompt ) ) ) ) );
		$api_response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 'timeout' => 45, 'headers' => array( 'Content-Type' => 'application/json', 'x-api-key' => $anthropic_key, 'anthropic-version' => '2023-06-01' ), 'body' => wp_json_encode( $api_payload ) ) );
		if ( is_wp_error( $api_response ) ) { return new \WP_Error( 'anthropic_request_failed', $api_response->get_error_message() ); }
		$api_code = wp_remote_retrieve_response_code( $api_response );
		if ( $api_code !== 200 ) {
			$err = json_decode( wp_remote_retrieve_body( $api_response ), true );
			return new \WP_Error( 'anthropic_api_error', sprintf( 'Anthropic HTTP %d: %s', $api_code, $err['error']['message'] ?? 'Unknown' ) );
		}
		$raw_text    = json_decode( wp_remote_retrieve_body( $api_response ), true )['content'][0]['text'] ?? '';
		$review_data = json_decode( trim( $raw_text ), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			preg_match( '/\{[\s\S]+\}/m', $raw_text, $m );
			$review_data = ! empty( $m[0] ) ? json_decode( $m[0], true ) : null;
		}
		if ( empty( $review_data ) ) { return new \WP_Error( 'review_parse_failed', 'Could not parse Claude response: ' . substr( $raw_text, 0, 300 ) ); }
		return array( 'post_id' => $post_id, 'viewport' => $viewport, 'focus' => $focus, 'screenshot_url' => $screenshot_url, 'screenshot_source' => $screenshot['source'] ?? 'unknown', 'critique' => $review_data['critique'] ?? '', 'score' => $review_data['score'] ?? null, 'issues' => $review_data['issues'] ?? array(), 'positive_observations' => $review_data['positive_observations'] ?? array(), 'issue_count' => count( $review_data['issues'] ?? array() ), 'critical_count' => count( array_filter( $review_data['issues'] ?? array(), fn( $i ) => ( $i['severity'] ?? '' ) === 'critical' ) ) );
	}
    
}