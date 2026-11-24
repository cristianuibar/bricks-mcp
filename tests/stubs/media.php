<?php
/**
 * WordPress media function stubs for Media ability unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable Universal.Namespaces.OneDeclarationPerFile.MultipleFound

namespace {
	if ( ! function_exists( 'wp_get_attachment_url' ) ) {
		/**
		 * Return a deterministic attachment URL when the post exists.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return string|false
		 */
		function wp_get_attachment_url( int $attachment_id = 0 ): string|false {
			return get_post( $attachment_id ) ? 'https://example.test/uploads/' . $attachment_id . '.jpg' : false;
		}
	}

	if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
		/**
		 * Return attachment metadata from `_wp_attachment_metadata`, as WordPress core does.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return array<string, mixed>|false
		 */
		function wp_get_attachment_metadata( int $attachment_id = 0 ): array|false {
			$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
			return is_array( $metadata ) ? $metadata : false;
		}
	}

	if ( ! function_exists( 'wp_max_upload_size' ) ) {
		/**
		 * Return configurable site max upload size for unit tests.
		 *
		 * @return int
		 */
		function wp_max_upload_size(): int {
			return (int) ( $GLOBALS['_bricks_mcp_test_max_upload_size'] ?? 10485760 );
		}
	}
}

namespace BricksMCP\Abilities {
	if ( ! function_exists( __NAMESPACE__ . '\wp_safe_remote_get' ) ) {
		/**
		 * Dispatch wp_safe_remote_get calls through a per-URL handler when configured.
		 *
		 * @param string               $url  Request URL.
		 * @param array<string, mixed> $args Request arguments.
		 * @return array<string, mixed>|\WP_Error
		 */
		function wp_safe_remote_get( string $url, array $args = [] ): array|\WP_Error {
			if ( isset( $GLOBALS['_bricks_mcp_test_media_http_handler'] ) && is_callable( $GLOBALS['_bricks_mcp_test_media_http_handler'] ) ) {
				$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'][] = [
					'url'  => $url,
					'args' => $args,
				];

				return ( $GLOBALS['_bricks_mcp_test_media_http_handler'] )( $url, $args );
			}

			return \wp_safe_remote_get( $url, $args );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\update_option' ) ) {
		/**
		 * Allow Media tests to simulate failed option persistence without affecting other tests.
		 *
		 * @param string $option   Option name.
		 * @param mixed  $value    Option value.
		 * @param mixed  $autoload Autoload setting.
		 * @return bool
		 */
		function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
			if ( ! empty( $GLOBALS['_bricks_mcp_test_media_update_option_fail'] ) ) {
				return false;
			}

			return \update_option( $option, $value, $autoload );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\update_post_meta' ) ) {
		/**
		 * Allow Media tests to simulate failed post meta writes without affecting other tests.
		 * The fail flag is true for every key, or a list of the meta keys whose writes fail. A callable in
		 * `_bricks_mcp_test_media_on_update_post_meta_fail` runs once when a write fails, as a concurrent request would.
		 *
		 * @param int    $post_id    Post ID.
		 * @param string $key        Meta key.
		 * @param mixed  $value      Meta value.
		 * @param mixed  $prev_value Previous value.
		 * @return int|bool
		 */
		function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev_value = '' ): int|bool {
			$fail = $GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] ?? false;
			if ( true === $fail || ( is_array( $fail ) && in_array( $key, $fail, true ) ) ) {
				$concurrent = $GLOBALS['_bricks_mcp_test_media_on_update_post_meta_fail'] ?? null;
				if ( is_callable( $concurrent ) ) {
					$GLOBALS['_bricks_mcp_test_media_on_update_post_meta_fail'] = null;
					$concurrent( $post_id, $key );
				}

				return false;
			}

			return \update_post_meta( $post_id, $key, $value, $prev_value );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\wp_delete_attachment' ) ) {
		/**
		 * Allow Media tests to simulate a failed attachment delete without affecting other tests.
		 * The fail flag 'after_meta' models WordPress removing the post meta before the post delete fails.
		 * A callable in `_bricks_mcp_test_media_before_delete_attachment` runs first, as a concurrent request would.
		 *
		 * @param int  $post_id      Attachment ID.
		 * @param bool $force_delete Whether to bypass the trash.
		 * @return \WP_Post|false|null
		 */
		function wp_delete_attachment( int $post_id, bool $force_delete = false ): \WP_Post|false|null {
			$concurrent = $GLOBALS['_bricks_mcp_test_media_before_delete_attachment'] ?? null;
			if ( is_callable( $concurrent ) ) {
				$GLOBALS['_bricks_mcp_test_media_before_delete_attachment'] = null;
				$concurrent( $post_id );
			}

			$fail = $GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] ?? false;
			if ( 'after_meta' === $fail ) {
				unset( $GLOBALS['_bricks_mcp_test_post_meta'][ $post_id ] );
			}
			if ( ! empty( $fail ) ) {
				return false;
			}

			return \wp_delete_attachment( $post_id, $force_delete );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\wp_update_post' ) ) {
		/**
		 * Allow Media tests to simulate failed post updates without affecting other tests.
		 *
		 * @param array<string, mixed>|object $postarr  Post fields.
		 * @param bool                        $wp_error Whether to return WP_Error on failure.
		 * @return int|\WP_Error
		 */
		function wp_update_post( array|object $postarr = [], bool $wp_error = false ): int|\WP_Error {
			if ( ! empty( $GLOBALS['_bricks_mcp_test_media_wp_update_post_fail'] ) ) {
				return $wp_error ? new \WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0;
			}

			return \wp_update_post( $postarr, $wp_error );
		}
	}
}
