<?php
/**
 * Ability module contract.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A group of abilities registered through the WordPress Abilities API.
 */
interface Module {

	/**
	 * Ability definitions keyed by ability name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array;
}
