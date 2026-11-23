<?php
/**
 * Runs the Check Now AJAX handler for a given installed version and published release.
 *
 * Executed in its own PHP process by UpdateCheckerTest because the unit
 * bootstrap fixes BRICKS_MCP_VERSION for the main test process.
 * Usage: php check-now.php <installed version> <release tag> [checksum-timeout].
 * Prints the JSON body WordPress would send to the Settings page script.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

define( 'BRICKS_MCP_VERSION', (string) $argv[1] );
require dirname( __DIR__, 3 ) . '/bootstrap-simple.php';

$bricks_mcp_tag  = (string) $argv[2];
$bricks_mcp_base = 'https://github.com/cristianuibar/bricks-mcp/releases/download/' . rawurlencode( $bricks_mcp_tag ) . '/';

$GLOBALS['_bricks_mcp_test_wp_remote_get_return']      = [
	'response' => [ 'code' => 200 ],
	'body'     => (string) wp_json_encode(
		[
			[
				'tag_name' => $bricks_mcp_tag,
				'assets'   => [
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => $bricks_mcp_base . 'bricks-mcp.zip',
					],
					[
						'name'                 => 'bricks-mcp.zip.sha256',
						'browser_download_url' => $bricks_mcp_base . 'bricks-mcp.zip.sha256',
					],
				],
			],
		]
	),
];
$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
	'response' => [ 'code' => 200 ],
	'body'     => hash( 'sha256', 'zip' ) . "  bricks-mcp.zip\n",
];
if ( 'checksum-timeout' === ( $argv[3] ?? '' ) ) {
	$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
}
$_REQUEST['nonce'] = wp_create_nonce( 'bricks_mcp_settings_nonce' );

( new BricksMCP\Updates\UpdateChecker() )->ajax_check_update();

echo wp_json_encode(
	[
		'success' => $GLOBALS['_bricks_mcp_test_json_response']['success'],
		'data'    => $GLOBALS['_bricks_mcp_test_json_response']['data'],
	]
);
