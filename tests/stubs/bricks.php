<?php
/**
 * Minimal Bricks Builder class stubs shared by unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Bricks;

// Capabilities loads on first use, so a separate-process test can set
// $GLOBALS['_bricks_mcp_test_without_bricks_capabilities'] to model a Bricks release without it.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 'Bricks\\Capabilities' === $class_name && empty( $GLOBALS['_bricks_mcp_test_without_bricks_capabilities'] ) ) {
			require_once __DIR__ . '/lazy/bricks-capabilities.php';
		}
	}
);

if ( ! class_exists( '\Bricks\Database' ) ) {
	/**
	 * Database stub backed by the `bricks_global_settings` test option.
	 */
	class Database {
		/** @var array<string, mixed> */
		public static array $global_settings = [];

		public static function get_setting( string $key, mixed $default = false ): mixed {
			return self::$global_settings[ $key ] ?? $default;
		}
	}
}
