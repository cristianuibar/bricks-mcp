<?php
/**
 * Loads bricks-mcp.php without Bricks Builder and runs an update download for this plugin.
 *
 * Executed in its own PHP process by UpdateCheckerTest because the main plugin
 * file defines the plugin constants itself. Prints a JSON result with the
 * upgrader_pre_download reply WordPress would get for a cached update offer.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/load-plugin.php';

// Bricks Builder is not loaded (BRICKS_VERSION undefined), as after the admin switches away from it.
bricks_mcp_init();

// WordPress installs the update offer it cached earlier; the cached release hash does not match the ZIP.
$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
	'version'        => '99.0.0',
	'package'        => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
	'sha256'         => str_repeat( '0', 64 ),
	'sha256_package' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
];
$GLOBALS['_bricks_mcp_test_download_url_return'] = tempnam( sys_get_temp_dir(), 'bricks_mcp_test_' );

$result = bricks_mcp_test_run_filter(
	'upgrader_pre_download',
	false,
	'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
	new stdClass(),
	[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
);

if ( false === $result ) {
	wp_delete_file( $GLOBALS['_bricks_mcp_test_download_url_return'] );
}

echo wp_json_encode( [ 'result' => is_wp_error( $result ) ? $result->get_error_code() : $result ] );
