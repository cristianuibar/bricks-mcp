<?php
/**
 * Simple bootstrap for unit tests without WordPress.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Define plugin constants for testing.
define( 'ABSPATH', '/tmp/wordpress/' );
// A fixture may define a different installed version first (e.g. one with build metadata).
defined( 'BRICKS_MCP_VERSION' ) || define( 'BRICKS_MCP_VERSION', '2.0.0' );
define( 'BRICKS_MCP_MIN_PHP_VERSION', '8.2' );
define( 'BRICKS_MCP_MIN_WP_VERSION', '7.1' );
define( 'BRICKS_MCP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BRICKS_MCP_PLUGIN_URL', 'http://localhost/wp-content/plugins/bricks-mcp/' );
// A fixture may define a different basename first (e.g. a renamed plugin folder).
defined( 'BRICKS_MCP_PLUGIN_BASENAME' ) || define( 'BRICKS_MCP_PLUGIN_BASENAME', 'bricks-mcp/bricks-mcp.php' );

// Like wp_set_internal_encoding(): WordPress sets mbstring's encoding to the blog charset, not the runner's default_charset.
if ( function_exists( 'mb_internal_encoding' ) ) {
	mb_internal_encoding( 'UTF-8' );
}

// Load global-namespace WordPress function stubs; wp-functions.php first, then the rest alphabetically.
require_once __DIR__ . '/stubs/wp-functions.php';
foreach ( glob( __DIR__ . '/stubs/*.php' ) as $bricks_mcp_stub ) {
	require_once $bricks_mcp_stub;
}

// Load the autoloader.
require_once BRICKS_MCP_PLUGIN_DIR . 'includes/Autoloader.php';
BricksMCP\Autoloader::register();
