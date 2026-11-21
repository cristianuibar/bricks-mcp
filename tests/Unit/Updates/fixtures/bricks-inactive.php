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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

define( 'ABSPATH', '/tmp/wordpress/' );
$GLOBALS['wp_version'] = '7.1';

/**
 * Stub plugin_dir_path.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

/**
 * Stub plugin_dir_url.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_url( string $file ): string {
	return 'http://localhost/wp-content/plugins/bricks-mcp/';
}

/**
 * Stub plugin_basename.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_basename( string $file ): string {
	return 'bricks-mcp/bricks-mcp.php';
}

/**
 * Stub register_activation_hook.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Activation callback.
 * @return void
 */
function register_activation_hook( string $file, callable $callback ): void {
}

$stubs = dirname( __DIR__, 3 ) . '/stubs';
require_once $stubs . '/wp-functions.php';
foreach ( glob( $stubs . '/*.php' ) as $stub ) {
	require_once $stub;
}

require dirname( __DIR__, 4 ) . '/bricks-mcp.php';

// Bricks Builder is not loaded (BRICKS_VERSION undefined), as after the admin switches away from it.
bricks_mcp_init();

// WordPress installs the update offer it cached earlier; the cached release hash does not match the ZIP.
$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
	'version' => '99.0.0',
	'package' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
	'sha256'  => str_repeat( '0', 64 ),
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
