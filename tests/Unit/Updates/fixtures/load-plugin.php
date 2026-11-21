<?php
/**
 * Loads bricks-mcp.php on the unit test stubs, without Bricks Builder.
 *
 * Shared by the fixtures UpdateCheckerTest runs in their own PHP process,
 * because the main plugin file defines the plugin constants itself.
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

/**
 * Stub register_deactivation_hook: records the callback for the fixture to run.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Deactivation callback.
 * @return void
 */
function register_deactivation_hook( string $file, callable $callback ): void {
	$GLOBALS['_bricks_mcp_test_deactivation_hook'] = $callback;
}

$stubs = dirname( __DIR__, 3 ) . '/stubs';
require_once $stubs . '/wp-functions.php';
foreach ( glob( $stubs . '/*.php' ) as $stub ) {
	require_once $stub;
}

require dirname( __DIR__, 4 ) . '/bricks-mcp.php';
