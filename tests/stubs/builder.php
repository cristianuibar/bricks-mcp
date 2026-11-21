<?php
/**
 * Builder ability test doubles.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id(): int {
			return 1;
		}
	}

	if ( ! function_exists( 'maybe_serialize' ) ) {
		function maybe_serialize( mixed $value ): mixed {
			return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
		}
	}

	if ( ! class_exists( WP_Query::class ) ) {
		class WP_Query {
			public ?WP_Post $queried_object = null;
			public int $queried_object_id = 0;

			public function parse_query( array $query ): void {}
		}
	}
}

namespace Bricks {
	if ( ! class_exists( Elements::class ) ) {
		class Elements {
			public static function get_element( array $element ): array {
				return [ 'nestable' => 'div' === $element['name'] ];
			}
		}
	}

	if ( ! class_exists( Templates::class ) ) {
		class Templates {
			public static function get_template_type( int $template_id ): string {
				return get_post_meta( $template_id, '_bricks_template_type', true );
			}
		}
	}

	if ( ! class_exists( Password_Protection::class ) ) {
		class Password_Protection {
			public static function is_active( int $template_id, int $post_id ): bool {
				return '' !== ( get_post( $post_id )->post_password ?? '' );
			}
		}
	}
}

namespace Bricks\Abilities {
	if ( ! class_exists( Design::class ) ) {
		class Design {
			public const DESIGN_SYSTEM_VERSION_OPTION = 'bricks_mcp_design_system_version';

			public static bool $can_edit_global_classes = true;

			public static function edit_global_class_permission( array $input ): bool|\WP_Error {
				if ( current_user_can( 'edit_posts' ) && self::$can_edit_global_classes ) {
					return true;
				}

				return new \WP_Error( 'bricks_forbidden', 'Permission denied.', [ 'status' => 403 ] );
			}
		}
	}

	if ( ! class_exists( Design_Option_Store::class ) ) {
		class Design_Option_Store {
			public static ?\Closure $before_swap = null;

			public static function authority_site_id( string $option ): int {
				return get_current_blog_id();
			}

			public static function read( string $option, mixed $default = false ): array {
				$exists = array_key_exists( $option, $GLOBALS['_bricks_mcp_test_options'] );
				$value  = get_option( $option, $default );
				return [
					'option' => $option,
					'exists' => $exists,
					'raw'    => $exists ? (string) maybe_serialize( $value ) : null,
					'value'  => $value,
					'siteId' => self::authority_site_id( $option ),
				];
			}

			public static function read_versioned( string $option, string $version_option, mixed $default = false ): array {
				return self::read( $option, $default ) + [ 'designSystemVersion' => (int) get_option( $version_option, 0 ) ];
			}

			public static function compare_and_swap( array $snapshot, mixed $value, bool $delete = false, string $version_option = '', ?int $expected_version = null, array $related_snapshots = [] ): array|\WP_Error {
				if ( null !== self::$before_swap ) {
					( self::$before_swap )( $snapshot['option'] );
				}

				$conflict = new \WP_Error( 'bricks_conflict_design_option_write', 'The design option changed. Re-read it and retry.' );
				if ( null !== $expected_version && $expected_version !== (int) get_option( $version_option, 0 ) ) {
					return $conflict;
				}

				foreach ( array_merge( [ $snapshot ], $related_snapshots ) as $expected ) {
					$current = self::read( $expected['option'] );
					if ( $expected['exists'] !== $current['exists'] || $expected['raw'] !== $current['raw'] ) {
						return $conflict;
					}
				}

				if ( $delete ) {
					delete_option( $snapshot['option'] );
				} else {
					update_option( $snapshot['option'], $value );
				}
				if ( '' !== $version_option ) {
					update_option( $version_option, (int) get_option( $version_option, 0 ) + 1 );
				}
				return [ 'success' => true, 'status' => $delete ? 'deleted' : 'updated', 'value' => $delete ? null : $value ];
			}

			public static function update_related( string $anchor_option, string $option, mixed $value ): bool {
				return update_option( $option, $value );
			}
		}
	}

	if ( ! class_exists( Save_Pipeline::class ) ) {
		class Save_Pipeline {
			public static function document_digest( array $elements ): string {
				return hash( 'sha256', (string) wp_json_encode( array_values( $elements ) ) );
			}
		}
	}
}

namespace BricksMCP\Tests\Support {
	if ( ! class_exists( BuilderDatabase::class ) ) {
		class BuilderDatabase extends \Bricks\Database {
			public static array $active_templates = [];
			public static string $header_position = 'top';
			public static array $template_ids = [];
			public static array $matches = [];
			public static array $resolved = [];
			public static array $query_context = [];

			public static function init_active_templates(): void {
				self::$active_templates = [];
			}

			public static function set_active_templates( int $post_id ): void {
				self::$query_context = [ $GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'] ];
				self::$active_templates = [ 'post_id' => $post_id ] + self::$resolved;
				self::$header_position  = 'left';
			}

			public static function get_all_templates_by_type(): array {
				return self::$template_ids;
			}

			public static function find_template_id( array $template_ids, string $slot, string $content_type, int $post_id, string $preview_type ): int {
				return array_values( array_intersect( self::$matches[ $post_id ] ?? [], $template_ids[ 'content' === $slot ? 'body' : $slot ] ?? [] ) )[0] ?? 0;
			}
		}
	}
}
