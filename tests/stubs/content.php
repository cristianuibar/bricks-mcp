<?php
/**
 * WordPress stubs used by the content ability tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return preg_match( '#^https?://#', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	function get_date_from_gmt( string $date_string ): string {
		return $date_string;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): object {
		return (object) [
			'name' => $post_type,
			'cap'  => (object) [ 'publish_posts' => 'publish_posts' ],
		];
	}
}

if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( string $post_type, string $feature ): bool {
		return ! in_array( $post_type, $GLOBALS['_bricks_mcp_test_no_thumbnail_types'] ?? [], true );
	}
}

if ( ! function_exists( 'wp_check_post_lock' ) ) {
	function wp_check_post_lock( int $post_id ): int|false {
		return $GLOBALS['_bricks_mcp_test_post_locks'][ $post_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post && str_starts_with( (string) ( $post->post_mime_type ?? '' ), 'image/' );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $post_id ): string|false {
		return get_post( $post_id ) ? 'https://example.test/uploads/' . $post_id . '.jpg' : false;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( string $url ): int {
		$id = preg_match( '#^https://example\.test/uploads/(\d+)\.jpg$#', $url, $match ) ? (int) $match[1] : 0;

		return get_post( $id ) ? $id : 0;
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $post_id ): int|false {
		$id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
		return $id > 0 ? $id : false;
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): int|bool {
		return update_post_meta( $post_id, '_thumbnail_id', $thumbnail_id );
	}
}

if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	function delete_post_thumbnail( int $post_id ): bool {
		return delete_post_meta( $post_id, '_thumbnail_id' );
	}
}
