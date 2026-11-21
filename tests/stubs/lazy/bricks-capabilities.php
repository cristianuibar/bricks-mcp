<?php
/**
 * Bricks Capabilities stub, autoloaded on first use by tests/stubs/bricks.php.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

namespace Bricks;

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
