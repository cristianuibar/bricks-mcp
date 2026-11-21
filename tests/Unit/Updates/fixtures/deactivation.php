<?php
/**
 * Deactivates bricks-mcp.php and reports which cached plugin update offers remain.
 *
 * Executed in its own PHP process by UpdateCheckerTest. Prints a JSON result
 * with the plugin files left in WordPress's cached update_plugins offers.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/load-plugin.php';

// WordPress cached an installable offer for this plugin and for another plugin.
set_site_transient(
	'update_plugins',
	(object) [
		'response' => [
			'bricks-mcp/bricks-mcp.php' => (object) [ 'package' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip' ],
			'other/other.php'           => (object) [ 'package' => 'https://downloads.wordpress.org/plugin/other.zip' ],
		],
	]
);

// WordPress runs the plugin's deactivation hook when the admin deactivates it.
if ( isset( $GLOBALS['_bricks_mcp_test_deactivation_hook'] ) ) {
	( $GLOBALS['_bricks_mcp_test_deactivation_hook'] )();
}

echo wp_json_encode( [ 'offers' => array_keys( get_site_transient( 'update_plugins' )->response ) ] );
