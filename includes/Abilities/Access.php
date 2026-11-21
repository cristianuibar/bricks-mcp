<?php
/**
 * Permission checks and Bricks ability calls shared by ability modules.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission checks and Bricks ability calls shared by ability modules.
 */
final class Access {

	/**
	 * Whether the current user may edit the post, and use the Bricks builder on it when required.
	 *
	 * @param int  $post_id       Post ID.
	 * @param bool $needs_builder Whether Bricks builder access is also required.
	 * @return true|\WP_Error
	 */
	public static function edit_post( int $post_id, bool $needs_builder = true ): bool|\WP_Error {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'bricks_mcp_post_not_found', __( 'Post not found.', 'bricks-mcp' ), [ 'status' => 404 ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'bricks_mcp_forbidden', __( 'You are not allowed to edit this post.', 'bricks-mcp' ), [ 'status' => 403 ] );
		}

		if ( $needs_builder && class_exists( '\Bricks\Capabilities' ) && ! \Bricks\Capabilities::current_user_can_use_builder( $post_id ) ) {
			return new \WP_Error( 'bricks_mcp_forbidden_builder', __( 'You are not allowed to use the Bricks builder on this post.', 'bricks-mcp' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Whether the current user may change site-wide settings.
	 *
	 * @return true|\WP_Error
	 */
	public static function manage_site(): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error( 'bricks_mcp_forbidden', __( 'You are not allowed to manage site settings.', 'bricks-mcp' ), [ 'status' => 403 ] );
	}

	/**
	 * Execute a Bricks ability, so writes go through its validation, revisions, drafts and history.
	 *
	 * @param string               $name  Ability name, e.g. `bricks/set-page-elements`.
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed|\WP_Error
	 */
	public static function run_bricks_ability( string $name, array $input ): mixed {
		$ability = wp_get_ability( $name );

		if ( null === $ability ) {
			return new \WP_Error(
				'bricks_mcp_bricks_ability_missing',
				/* translators: %s: ability name */
				sprintf( __( 'The Bricks ability %s is not available. Enable it under Bricks > Settings > AI.', 'bricks-mcp' ), $name ),
				[ 'status' => 501 ]
			);
		}

		return $ability->execute( $input );
	}
}
