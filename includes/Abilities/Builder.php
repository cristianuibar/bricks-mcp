<?php
/**
 * Bricks builder extensions.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

use Bricks\Abilities\Design;
use Bricks\Abilities\Design_Option_Store;
use Bricks\Abilities\Save_Pipeline;
use Bricks\Database;
use Bricks\Elements;
use Bricks\Password_Protection;
use Bricks\Templates;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Element moves, template resolution and global class categories.
 */
final class Builder implements Module {

	private const CATEGORY_OPTION = 'bricks_global_classes_categories';
	private const CLASSES_OPTION  = 'bricks_global_classes';

	/**
	 * Set the template database.
	 *
	 * @param string $database Bricks template database class.
	 */
	public function __construct( private readonly string $database = Database::class ) {}

	/**
	 * Register builder abilities.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		$post_id   = [
			'type'    => 'integer',
			'minimum' => 1,
		];
		$id        = [
			'type'      => 'string',
			'minLength' => 1,
		];
		$parent_id = [
			'type'        => [ 'string', 'integer' ],
			'anyOf'       => [
				[ 'type' => 'string' ],
				[
					'type' => 'integer',
					'enum' => [ 0 ],
				],
			],
			'description' => __( 'Destination element ID. Use 0 or an empty string for the root.', 'bricks-mcp' ),
		];
		$position  = [
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'Zero-based position in the final sibling list. Omit to append. Positions past the end also append.', 'bricks-mcp' ),
		];
		$slot      = [
			'type'                 => 'object',
			'properties'           => [
				'activeIds'    => [
					'type'  => 'array',
					'items' => $post_id,
				],
				'candidateIds' => [
					'type'  => 'array',
					'items' => $post_id,
				],
			],
			'required'             => [ 'activeIds' ],
			'additionalProperties' => false,
		];

		return [
			'bricks-mcp/move-element'                 => [
				'label'               => __( 'Move Bricks element', 'bricks-mcp' ),
				'description'         => __( 'Reorder or reparent an existing element and its subtree in one Bricks document. Use bricks/get-page-elements for IDs and bricks/restore-revision to undo the move.', 'bricks-mcp' ),
				'category'            => 'bricks-mcp-builder',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'postId'    => $post_id,
						'elementId' => $id,
						'parentId'  => $parent_id,
						'position'  => $position,
					],
					'required'             => [ 'postId', 'elementId', 'parentId' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'elementId'   => $id,
						'oldParentId' => $parent_id,
						'oldPosition' => [ 'type' => 'integer' ],
						'newParentId' => $parent_id,
						'newPosition' => [ 'type' => 'integer' ],
						'revisionId'  => [ 'type' => [ 'integer', 'null' ] ],
					],
					'required'   => [ 'elementId', 'oldParentId', 'oldPosition', 'newParentId', 'newPosition' ],
				],
				'permission_callback' => static fn( array $input ) => Access::edit_post( (int) $input['postId'] ),
				'execute_callback'    => [ $this, 'move_element' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			],
			'bricks-mcp/resolve-templates'            => [
				'label'               => __( 'Resolve Bricks templates', 'bricks-mcp' ),
				'description'         => __( 'See the templates Bricks selects for a specific post, with matching competitors where available. Use bricks/list-templates to browse the template library.', 'bricks-mcp' ),
				'category'            => 'bricks-mcp-builder',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [ 'postId' => $post_id ],
					'required'             => [ 'postId' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'postId'        => $post_id,
						'contentType'   => [ 'type' => 'string' ],
						'contentPostId' => [ 'type' => [ 'integer', 'null' ] ],
						'slots'         => [
							'type'                 => 'object',
							'additionalProperties' => $slot,
						],
					],
					'required'   => [ 'postId', 'contentType', 'contentPostId', 'slots' ],
				],
				'permission_callback' => static fn( array $input ) => Access::edit_post( (int) $input['postId'] ),
				'execute_callback'    => [ $this, 'resolve_templates' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
				],
			],
			'bricks-mcp/create-global-class-category' => [
				'label'               => __( 'Create global class category', 'bricks-mcp' ),
				'description'         => __( 'Create a named category for organizing global classes. Use bricks/list-global-classes to see categories and bricks/update-global-class to assign classes to them.', 'bricks-mcp' ),
				'category'            => 'bricks-mcp-builder',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [ 'name' => $id ],
					'required'             => [ 'name' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'   => $id,
						'name' => $id,
					],
					'required'   => [ 'id', 'name' ],
				],
				'permission_callback' => [ $this, 'category_permission' ],
				'execute_callback'    => [ $this, 'create_global_class_category' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
				],
			],
			'bricks-mcp/delete-global-class-category' => [
				'label'               => __( 'Delete global class category', 'bricks-mcp' ),
				'description'         => __( 'Delete a category and move its classes to Uncategorized. Find category IDs with bricks/list-global-classes.', 'bricks-mcp' ),
				'category'            => 'bricks-mcp-builder',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [ 'categoryId' => $id ],
					'required'             => [ 'categoryId' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'categoryId'         => $id,
						'uncategorizedCount' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
					'required'   => [ 'categoryId', 'uncategorizedCount' ],
				],
				'permission_callback' => [ $this, 'category_permission' ],
				'execute_callback'    => [ $this, 'delete_global_class_category' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
			],
		];
	}

	/**
	 * Move an element through Bricks' document save pipeline.
	 *
	 * @param array<string, mixed> $input Move input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function move_element( array $input ): array|WP_Error {
		$post_id    = (int) $input['postId'];
		$element_id = $input['elementId'];
		$new_parent = self::parent_id( $input['parentId'] );
		$document   = Access::run_bricks_ability( 'bricks/get-page-elements', [ 'postId' => $post_id ] );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$elements = $document['elements'] ?? null;
		$digest   = $document['documentDigest'] ?? '';

		if ( ! is_array( $elements ) || ! is_string( $digest ) || ! hash_equals( $digest, Save_Pipeline::document_digest( $elements ) ) ) {
			return new WP_Error( 'bricks_mcp_incomplete_tree', __( 'Bricks did not return the complete element tree. A full-tree save could overwrite hidden settings.', 'bricks-mcp' ), [ 'status' => 409 ] );
		}

		$by_id = array_column( $elements, null, 'id' );

		if ( ! isset( $by_id[ $element_id ] ) ) {
			return new WP_Error( 'bricks_mcp_element_not_found', __( 'Element not found in the Bricks document.', 'bricks-mcp' ), [ 'status' => 404 ] );
		}

		if ( 0 !== $new_parent && ! isset( $by_id[ $new_parent ] ) ) {
			return new WP_Error( 'bricks_mcp_parent_not_found', __( 'Destination parent not found in the Bricks document.', 'bricks-mcp' ), [ 'status' => 404 ] );
		}

		$visited = [ $element_id => true ];
		for ( $ancestor = $new_parent; 0 !== $ancestor; $ancestor = self::parent_id( $by_id[ $ancestor ]['parent'] ?? 0 ) ) {
			if ( isset( $visited[ $ancestor ] ) ) {
				return new WP_Error( 'bricks_mcp_cyclic_move', __( 'The destination is the element itself, a descendant, or part of a cyclic hierarchy.', 'bricks-mcp' ), [ 'status' => 400 ] );
			}
			$visited[ $ancestor ] = true;
		}

		$old_parent = self::parent_id( $by_id[ $element_id ]['parent'] ?? 0 );

		if ( ! empty( $by_id[ $new_parent ]['cid'] ) ) {
			return new WP_Error( 'bricks_mcp_component_slot_move', __( 'Use the Bricks builder to choose the destination slot inside a component.', 'bricks-mcp' ), [ 'status' => 400 ] );
		}

		if ( 0 !== $new_parent ) {
			$definition = Elements::get_element( $by_id[ $new_parent ] );

			if ( false === ( $definition['nestable'] ?? null ) ) {
				return new WP_Error( 'bricks_mcp_parent_not_nestable', __( 'The destination element cannot contain children.', 'bricks-mcp' ), [ 'status' => 400 ] );
			}
		}

		$siblings     = self::sibling_order( $by_id );
		$old_position = array_search( $element_id, $siblings[ $old_parent ], true );
		array_splice( $siblings[ $old_parent ], $old_position, 1 );

		$target       = $siblings[ $new_parent ] ?? [];
		$new_position = min( $input['position'] ?? count( $target ), count( $target ) );
		array_splice( $target, $new_position, 0, [ $element_id ] );
		$siblings[ $new_parent ] = $target;

		foreach ( array_unique( [ $old_parent, $new_parent ] ) as $parent_id ) {
			if ( isset( $by_id[ $parent_id ] ) ) {
				$by_id[ $parent_id ]['children'] = $siblings[ $parent_id ];
			}
		}

		foreach ( $by_id[ $old_parent ]['slotChildren'] ?? [] as $slot_id => $children ) {
			$by_id[ $old_parent ]['slotChildren'][ $slot_id ] = array_values( array_diff( $children, [ $element_id ] ) );
		}

		$by_id[ $element_id ]['parent'] = $new_parent;
		$offsets                        = [];
		$moved                          = [];

		foreach ( $by_id as $element ) {
			$parent_id             = self::parent_id( $element['parent'] ?? 0 );
			$offset                = $offsets[ $parent_id ] ?? 0;
			$moved[]               = $by_id[ $siblings[ $parent_id ][ $offset ] ];
			$offsets[ $parent_id ] = $offset + 1;
		}

		$saved = Access::run_bricks_ability(
			'bricks/set-page-elements',
			[
				'postId'                 => $post_id,
				'elements'               => $moved,
				'expectedDocumentDigest' => $digest,
			]
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result = [
			'elementId'   => $element_id,
			'oldParentId' => $old_parent,
			'oldPosition' => $old_position,
			'newParentId' => $new_parent,
			'newPosition' => $new_position,
		];

		if ( array_key_exists( 'revisionId', $saved ) ) {
			$result['revisionId'] = $saved['revisionId'];
		}

		return $result;
	}

	/**
	 * Normalize the root parent ID.
	 *
	 * @param int|string $parent_id Bricks parent value.
	 * @return int|string
	 */
	private static function parent_id( int|string $parent_id ): int|string {
		return in_array( $parent_id, [ 0, '', '0' ], true ) ? 0 : $parent_id;
	}

	/**
	 * Read sibling order, preferring Bricks' stored child lists over flat order.
	 *
	 * @param array<string, array> $elements Elements indexed by ID in document order.
	 * @return array<int|string, list<string>>
	 */
	private static function sibling_order( array $elements ): array {
		$orders = [];

		foreach ( $elements as $element ) {
			$orders[ self::parent_id( $element['parent'] ?? 0 ) ][] = $element['id'];
		}

		foreach ( $orders as $parent_id => $children ) {
			$declared             = $elements[ $parent_id ]['children'] ?? [];
			$orders[ $parent_id ] = array_values( array_unique( array_merge( array_intersect( $declared, $children ), $children ) ) );
		}

		return $orders;
	}

	/**
	 * Resolve the post in an isolated WordPress query context.
	 *
	 * @param array<string, mixed> $input Template lookup input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resolve_templates( array $input ): array|WP_Error {
		$database = $this->database;
		if ( ! is_callable( [ $database, 'set_active_templates' ] ) ) {
			return new WP_Error( 'bricks_mcp_template_resolver_unavailable', __( 'The Bricks template resolver is unavailable.', 'bricks-mcp' ), [ 'status' => 501 ] );
		}

		$post_id = (int) $input['postId'];
		$target  = get_post( $post_id );
		if ( null === $target ) {
			return new WP_Error( 'bricks_mcp_post_not_found', __( 'Post not found.', 'bricks-mcp' ), [ 'status' => 404 ] );
		}

		$query    = new \WP_Query();
		$post_key = match ( $target->post_type ) {
			'page'       => 'page_id',
			'attachment' => 'attachment_id',
			default      => 'p',
		};
		$previous_query     = $GLOBALS['wp_query'] ?? null;
		$previous_main      = $GLOBALS['wp_the_query'] ?? null;
		$previous_post      = $GLOBALS['post'] ?? null;
		$previous_templates = $database::$active_templates;
		$previous_header    = $database::$header_position;

		try {
			$GLOBALS['wp_query']     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bricks reads WordPress conditional tags during resolution.
			$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Match the temporary main query during resolution.
			$GLOBALS['post']         = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Match the temporary query's post during resolution.
			$query->parse_query(
				[
					$post_key   => $post_id,
					'post_type' => $target->post_type,
				]
			);
			$query->queried_object    = $target;
			$query->queried_object_id = $post_id;
			$database::init_active_templates();
			$database::set_active_templates( $post_id );

			$active          = $database::$active_templates;
			$content_type    = (string) ( $active['content_type'] ?? 'content' );
			$content_post_id = null;
			$slots           = [];

			foreach ( array_unique( [ 'header', 'footer', 'content', 'archive', 'search', 'error', 'section', 'password_protection', 'popup', $content_type ] ) as $slot ) {
				if ( '' === $slot ) {
					continue;
				}

				$ids = array_values( array_filter( array_map( 'intval', (array) ( $active[ $slot ] ?? [] ) ) ) );

				if ( 'content' === $slot && $ids && 'bricks_template' !== get_post_type( $ids[0] ) ) {
					$content_post_id = $ids[0];
					$ids             = [];
				}

				$slots[ $slot ] = [ 'activeIds' => $ids ];
			}

			if ( 'bricks_template' !== $target->post_type && (int) ( $active['post_id'] ?? 0 ) === $post_id ) {
				$template_ids     = $database::get_all_templates_by_type();
				$content_type_ids = array_fill_keys( $template_ids[ $content_type ] ?? [], true );

				foreach ( [ 'header', 'footer', 'content' ] as $slot ) {
					$key        = 'content' === $slot ? 'body' : $slot;
					$candidates = [];

					foreach ( $template_ids[ $key ] ?? [] as $template_id ) {
						$only_template_by_type = [
							$content_type => isset( $content_type_ids[ $template_id ] ) ? [ $template_id ] : [],
							$key          => [ $template_id ],
						];
						$match                 = (int) $database::find_template_id( $only_template_by_type, $slot, $content_type, $post_id, '' );

						if ( ! $match || in_array( $match, $slots[ $slot ]['activeIds'], true ) ) {
							continue;
						}

						if ( 'password_protection' === Templates::get_template_type( $match ) && ! Password_Protection::is_active( $match, $post_id ) ) {
							continue;
						}

						$candidates[] = $match;
					}

					$slots[ $slot ]['candidateIds'] = $candidates;
				}
			}

			return [
				'postId'        => $post_id,
				'contentType'   => $content_type,
				'contentPostId' => $content_post_id,
				'slots'         => $slots,
			];
		} finally {
			$GLOBALS['wp_query']         = $previous_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore WordPress's original query.
			$GLOBALS['wp_the_query']     = $previous_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore WordPress's original main query.
			$GLOBALS['post']             = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore WordPress's original post.
			$database::$active_templates = $previous_templates;
			$database::$header_position  = $previous_header;
		}
	}

	/**
	 * Match Bricks' global class category write permission.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return bool|WP_Error
	 */
	public function category_permission( array $input ): bool|WP_Error {
		return Design::edit_global_class_permission( $input );
	}

	/**
	 * Create an empty class category.
	 *
	 * @param array<string, mixed> $input Category name.
	 * @return array<string, string>|WP_Error
	 */
	public function create_global_class_category( array $input ): array|WP_Error {
		$name = sanitize_text_field( $input['name'] );

		if ( '' === $name ) {
			return new WP_Error( 'bricks_mcp_empty_category_name', __( 'Category name is required.', 'bricks-mcp' ), [ 'status' => 400 ] );
		}

		$authority = self::local_category_store( [ self::CATEGORY_OPTION ] );
		if ( is_wp_error( $authority ) ) {
			return $authority;
		}

		$snapshot = Design_Option_Store::read_versioned( self::CATEGORY_OPTION, Design::DESIGN_SYSTEM_VERSION_OPTION, [] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$raw          = $snapshot['value'];
		$categories   = is_array( $raw ) ? $raw : [];
		$name_pattern = '/\A' . preg_quote( $name, '/' ) . '\z/iu';

		foreach ( array_column( $categories, 'name' ) as $existing ) {
			if ( preg_match( $name_pattern, $existing ) ) {
				return new WP_Error( 'bricks_mcp_duplicate_category', __( 'A category with this name already exists.', 'bricks-mcp' ), [ 'status' => 409 ] );
			}
		}

		$ids = array_column( $categories, 'id' );

		do {
			$id = '';
			for ( $index = 0; $index < 6; ++$index ) {
				$id .= chr( random_int( 97, 122 ) );
			}
		} while ( in_array( $id, $ids, true ) );

		$category     = [
			'id'   => $id,
			'name' => $name,
		];
		$categories[] = $category;

		$write = self::write_category_option( $snapshot, $categories );
		if ( is_wp_error( $write ) ) {
			return $write;
		}
		if ( $categories !== $write['value'] ) {
			return new WP_Error( 'bricks_mcp_category_write_failed', __( 'The category was not saved as requested. Re-read the categories before retrying.', 'bricks-mcp' ), [ 'status' => 500 ] );
		}

		return $category;
	}

	/**
	 * Remove the category and its references from active global classes.
	 *
	 * @param array<string, mixed> $input Category ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete_global_class_category( array $input ): array|WP_Error {
		$authority = self::local_category_store( [ self::CATEGORY_OPTION, self::CLASSES_OPTION ] );
		if ( is_wp_error( $authority ) ) {
			return $authority;
		}

		$category_snapshot = Design_Option_Store::read_versioned( self::CATEGORY_OPTION, Design::DESIGN_SYSTEM_VERSION_OPTION, [] );
		if ( is_wp_error( $category_snapshot ) ) {
			return $category_snapshot;
		}

		$category_id = $input['categoryId'];
		$raw         = $category_snapshot['value'];
		$categories  = is_array( $raw ) ? $raw : [];
		$remaining   = array_values(
			array_filter( $categories, static fn( $category ) => ( $category['id'] ?? '' ) !== $category_id )
		);

		if ( count( $remaining ) === count( $categories ) ) {
			return new WP_Error( 'bricks_mcp_category_not_found', __( 'Global class category not found.', 'bricks-mcp' ), [ 'status' => 404 ] );
		}

		$class_snapshot = Design_Option_Store::read( self::CLASSES_OPTION, [] );
		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$raw     = $class_snapshot['value'];
		$classes = is_array( $raw ) ? $raw : [];
		$count   = 0;

		foreach ( $classes as $index => $class ) {
			if ( ( $class['category'] ?? '' ) === $category_id ) {
				unset( $classes[ $index ]['category'] );
				++$count;
			}
		}

		$after_classes = $class_snapshot;
		if ( $count > 0 ) {
			$class_snapshot['designSystemVersion'] = $category_snapshot['designSystemVersion'];
			$write                                 = self::write_category_option( $class_snapshot, $classes, [ $category_snapshot ] );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			if ( 'no_change' === $write['status'] ) {
				return new WP_Error( 'bricks_mcp_class_write_failed', __( 'The class changes were not saved. The category is still present.', 'bricks-mcp' ), [ 'status' => 500 ] );
			}

			$after_classes['raw']   = (string) maybe_serialize( $write['value'] );
			$after_classes['value'] = $write['value'];
			++$category_snapshot['designSystemVersion'];
		}

		$write = $count > 0 && $classes !== $after_classes['value']
			? new WP_Error( 'bricks_mcp_class_write_failed', __( 'The class changes differed from the requested changes.', 'bricks-mcp' ), [ 'status' => 500 ] )
			: self::write_category_option( $category_snapshot, $remaining, [ $after_classes ] );
		if ( ! is_wp_error( $write ) && $remaining !== $write['value'] ) {
			$write = new WP_Error( 'bricks_mcp_category_write_failed', __( 'The category list was not saved as requested. Re-read the categories before retrying.', 'bricks-mcp' ), [ 'status' => 500 ] );
		}
		if ( is_wp_error( $write ) && $count > 0 ) {
			return self::restore_categories_on_classes( $write, $class_snapshot, $after_classes, $category_id );
		}
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		if ( $count > 0 ) {
			Design_Option_Store::update_related( self::CLASSES_OPTION, 'bricks_global_classes_timestamp', time() );
			Design_Option_Store::update_related( self::CLASSES_OPTION, 'bricks_global_classes_user', get_current_user_id() );
		}

		return [
			'categoryId'         => $category_id,
			'uncategorizedCount' => $count,
		];
	}

	/**
	 * Save one versioned option with exact related row guards.
	 *
	 * @param array<string, mixed>   $snapshot Exact option snapshot.
	 * @param array<mixed>           $value New option value.
	 * @param array<string, mixed>[] $related Exact snapshots of related rows.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function write_category_option( array $snapshot, array $value, array $related = [] ): array|WP_Error {
		$write = Design_Option_Store::compare_and_swap(
			$snapshot,
			$value,
			false,
			Design::DESIGN_SYSTEM_VERSION_OPTION,
			$snapshot['designSystemVersion'] ?? null,
			$related
		);

		if ( is_wp_error( $write ) ) {
			$code     = $write->get_error_code();
			$conflict = str_starts_with( $code, 'bricks_conflict_' ) || 'bricks_draft_busy' === $code;
			return new WP_Error( $code, $write->get_error_message(), [ 'status' => $conflict ? 409 : 500 ] + (array) $write->get_error_data() );
		}

		return $write;
	}

	/**
	 * Restore class assignments if the category still exists and the classes match the saved value.
	 *
	 * @param WP_Error             $error Category write error.
	 * @param array<string, mixed> $before Original class snapshot.
	 * @param array<string, mixed> $after Exact snapshot of the saved classes.
	 * @param string               $category_id Category ID being deleted.
	 * @return WP_Error
	 */
	private static function restore_categories_on_classes( WP_Error $error, array $before, array $after, string $category_id ): WP_Error {
		$current = Design_Option_Store::read( self::CATEGORY_OPTION, [] );
		if ( ! is_wp_error( $current ) ) {
			$categories = is_array( $current['value'] ) ? $current['value'] : [];
			if ( ! in_array( $category_id, array_column( $categories, 'id' ), true ) ) {
				return $error;
			}

			$restore = self::write_category_option( $after, $before['value'], [ $current ] );
			if ( ! is_wp_error( $restore ) && $before['value'] === $restore['value'] ) {
				return $error;
			}
		}

		return new WP_Error(
			'bricks_mcp_category_recovery_conflict',
			__( 'The category deletion could not finish and the original class assignments could not be safely restored. Re-read the category and global classes before retrying.', 'bricks-mcp' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * Ensure the affected options belong to the current site.
	 *
	 * @param string[] $options Option names.
	 * @return true|WP_Error
	 */
	private static function local_category_store( array $options ): bool|WP_Error {
		foreach ( $options as $option ) {
			if ( get_current_blog_id() !== Design_Option_Store::authority_site_id( $option ) ) {
				return new WP_Error( 'bricks_mcp_shared_class_store', __( 'Manage inherited global classes and categories from the main site.', 'bricks-mcp' ), [ 'status' => 403 ] );
			}
		}
		return true;
	}
}
