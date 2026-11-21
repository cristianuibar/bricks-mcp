<?php
/**
 * Plugin uninstallation handler.
 *
 * Removes only data this plugin owns. `bricks_mcp_settings` belongs to Bricks 2.5+ and is left alone.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Prevent direct access and ensure this is an uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( [ 'bricks_mcp_extensions_settings', 'bricks_mcp_version', 'bricks_mcp_activated_at', 'bricks_mcp_reconnect_notice' ] as $bricks_mcp_option ) {
	delete_option( $bricks_mcp_option );
}

foreach ( [ 'bricks_mcp_update_data', 'bricks_mcp_activation_checks' ] as $bricks_mcp_transient ) {
	delete_transient( $bricks_mcp_transient );
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rate-limit transients have per-user keys, so they are removed by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bricks_mcp_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bricks_mcp_rl_' ) . '%'
	)
);
