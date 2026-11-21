<?php
/**
 * Plugin activation handler.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records activation and runs version upgrades.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! get_option( 'bricks_mcp_activated_at' ) ) {
			update_option( 'bricks_mcp_activated_at', time() );
		}

		Plugin::maybe_upgrade();
	}
}
