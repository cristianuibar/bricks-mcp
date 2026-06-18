<?php
/**
 * Resolve the on-disk path to the Bricks builder guide.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Support;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates BUILDER_GUIDE.md across shipped and development paths.
 */
final class BuilderGuideLocator {

	/**
	 * Return the first readable builder guide path, or null if unavailable.
	 *
	 * @return string|null
	 */
	public static function get_path(): ?string {
		$candidates = array(
			BRICKS_MCP_PLUGIN_DIR . 'includes/data/BUILDER_GUIDE.md',
			BRICKS_MCP_PLUGIN_DIR . 'docs/BUILDER_GUIDE.md',
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}
}