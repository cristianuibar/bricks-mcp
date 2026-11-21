<?php
/**
 * Minimal Bricks Builder class stubs shared by unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Bricks;

if ( ! class_exists( '\Bricks\Capabilities' ) ) {
	/**
	 * Capabilities stub; tests set $can_use_builder.
	 */
	class Capabilities {
		/** @var bool */
		public static bool $can_use_builder = true;

		public static function current_user_can_use_builder( mixed $post_id = 0 ): bool {
			return self::$can_use_builder;
		}
	}
}

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
