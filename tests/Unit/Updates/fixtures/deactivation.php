<?php
/**
 * Deactivates bricks-mcp.php and reports WordPress's cached plugin update offers.
 *
 * Executed in its own PHP process by UpdateCheckerTest. With the argument
 * "concurrent-refresh", a cron update check stores a fresh update_plugins
 * transient right after the deactivation hook reads it. Prints a JSON result
 * with the cached offers (plugin file => version), or null when WordPress has
 * no cached update data and checks again.
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
			'bricks-mcp/bricks-mcp.php' => (object) [
				'new_version' => '99.0.0',
				'package'     => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
			],
			'other/other.php'           => (object) [
				'new_version' => '1.0.0',
				'package'     => 'https://downloads.wordpress.org/plugin/other.1.0.0.zip',
			],
		],
	]
);

if ( 'concurrent-refresh' === ( $argv[1] ?? '' ) ) {
	$refreshed = false;
	add_filter(
		'site_transient_update_plugins',
		static function ( $value ) use ( &$refreshed ) {
			// Cron publishes another plugin's security release once the cached data has been read.
			if ( ! $refreshed ) {
				$refreshed = true;
				set_site_transient(
					'update_plugins',
					(object) [
						'response' => [
							'other/other.php' => (object) [
								'new_version' => '1.0.1',
								'package'     => 'https://downloads.wordpress.org/plugin/other.1.0.1.zip',
							],
						],
					]
				);
			}
			return $value;
		}
	);
}

// WordPress runs the plugin's deactivation hook when the admin deactivates it.
if ( isset( $GLOBALS['_bricks_mcp_test_deactivation_hook'] ) ) {
	( $GLOBALS['_bricks_mcp_test_deactivation_hook'] )();
}

$updates = get_site_transient( 'update_plugins' );

echo wp_json_encode( [ 'offers' => is_object( $updates ) ? array_map( static fn( $offer ) => $offer->new_version, $updates->response ) : null ] );
