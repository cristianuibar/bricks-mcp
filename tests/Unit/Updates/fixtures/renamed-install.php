<?php
/**
 * Runs one update offer and download for a plugin installed in a renamed folder.
 *
 * Executed in its own PHP process by UpdateCheckerTest because the unit
 * bootstrap fixes BRICKS_MCP_PLUGIN_BASENAME for the main test process.
 * Prints a JSON result: whether an update was offered and what
 * verify_download() returned for that plugin.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

define( 'BRICKS_MCP_PLUGIN_BASENAME', 'bricks-mcp-main/bricks-mcp.php' );
require dirname( __DIR__, 3 ) . '/bootstrap-simple.php';

// Cached release with no checksum: the update must be refused.
$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
	'version' => '99.0.0',
	'package' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
	'sha256'  => '',
];

$offer = ( new BricksMCP\Updates\UpdateChecker() )->check_update(
	false,
	[
		'Version'   => '1.0.0',
		'UpdateURI' => 'https://github.com/cristianuibar/bricks-mcp',
	],
	BRICKS_MCP_PLUGIN_BASENAME,
	[]
);

$result = ( new BricksMCP\Updates\UpdateChecker() )->verify_download(
	false,
	is_array( $offer ) ? $offer['package'] : '',
	new stdClass(),
	[ 'plugin' => BRICKS_MCP_PLUGIN_BASENAME ]
);

echo wp_json_encode(
	[
		'offered' => is_array( $offer ),
		'result'  => is_wp_error( $result ) ? $result->get_error_code() : $result,
	]
);
