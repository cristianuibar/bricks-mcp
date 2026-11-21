<?php
/**
 * Plugin settings storage.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, sanitizes and migrates the plugin settings option.
 */
final class Options {

	public const OPTION = 'bricks_mcp_extensions_settings';

	public const LEGACY_OPTION = 'bricks_mcp_settings';

	public const LEGACY_KEYS = [ 'require_auth', 'custom_base_url', 'dangerous_actions', 'rate_limit_rpm', 'rate_limit', 'rate_limit_window', 'allowed_endpoints' ];

	public const BRICKS_KEYS = [ 'disabledAbilities', 'enabledAbilities' ];

	public const RATE_LIMIT_MIN = 10;

	public const RATE_LIMIT_MAX = 1000;

	/**
	 * Default settings.
	 *
	 * @return array{disabled_abilities: string[], rate_limit_rpm: int, css_guard: bool}
	 */
	public static function defaults(): array {
		return [
			'disabled_abilities' => [],
			'rate_limit_rpm'     => 120,
			'css_guard'          => true,
		];
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array{disabled_abilities: string[], rate_limit_rpm: int, css_guard: bool}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );

		return self::sanitize( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Sanitize raw settings input.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array{disabled_abilities: string[], rate_limit_rpm: int, css_guard: bool}
	 */
	public static function sanitize( array $input ): array {
		$settings = self::defaults();

		if ( isset( $input['disabled_abilities'] ) && is_array( $input['disabled_abilities'] ) ) {
			$names = array_filter(
				array_map( 'strval', $input['disabled_abilities'] ),
				static fn( string $name ): bool => 1 === preg_match( '#^bricks-mcp/[a-z0-9-]+$#', $name )
			);

			$settings['disabled_abilities'] = array_values( array_unique( $names ) );
		}

		if ( isset( $input['rate_limit_rpm'] ) && is_numeric( $input['rate_limit_rpm'] ) ) {
			$rpm = (int) $input['rate_limit_rpm'];

			$settings['rate_limit_rpm'] = 0 === $rpm ? 0 : max( self::RATE_LIMIT_MIN, min( self::RATE_LIMIT_MAX, $rpm ) );
		}

		if ( array_key_exists( 'css_guard', $input ) ) {
			$settings['css_guard'] = (bool) $input['css_guard'];
		}

		return $settings;
	}

	/**
	 * Whether one of our abilities is enabled.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	public static function is_ability_enabled( string $name ): bool {
		return ! in_array( $name, self::get()['disabled_abilities'], true );
	}

	/**
	 * Move 1.x settings out of `bricks_mcp_settings`, which Bricks 2.5 owns.
	 *
	 * @return void
	 */
	public static function migrate_legacy(): void {
		$legacy = get_option( self::LEGACY_OPTION, null );

		if ( ! is_array( $legacy ) || [] === array_intersect( array_keys( $legacy ), self::LEGACY_KEYS ) ) {
			return;
		}

		if ( false === get_option( self::OPTION, false ) && isset( $legacy['rate_limit_rpm'] ) ) {
			update_option( self::OPTION, self::sanitize( [ 'rate_limit_rpm' => $legacy['rate_limit_rpm'] ] ) );
		}

		$remaining = array_diff_key( $legacy, array_flip( self::LEGACY_KEYS ) );

		if ( [] === array_intersect( array_keys( $remaining ), self::BRICKS_KEYS ) ) {
			unset( $remaining['enabled'] );
		}

		if ( [] === $remaining ) {
			delete_option( self::LEGACY_OPTION );
			return;
		}

		update_option( self::LEGACY_OPTION, $remaining );
	}
}
