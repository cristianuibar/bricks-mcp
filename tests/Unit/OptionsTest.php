<?php
/**
 * Tests for settings storage and the 1.x migration.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use BricksMCP\Options;
use BricksMCP\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Options and upgrade behaviour.
 */
final class OptionsTest extends TestCase {

	protected function setUp(): void {
		bricks_mcp_test_reset();
	}

	public function test_sanitize_clamps_rate_limit_and_keeps_zero_as_off(): void {
		$this->assertSame( 10, Options::sanitize( [ 'rate_limit_rpm' => 3 ] )['rate_limit_rpm'] );
		$this->assertSame( 1000, Options::sanitize( [ 'rate_limit_rpm' => 99999 ] )['rate_limit_rpm'] );
		$this->assertSame( 0, Options::sanitize( [ 'rate_limit_rpm' => '0' ] )['rate_limit_rpm'] );
	}

	public function test_sanitize_drops_ability_names_outside_our_namespace(): void {
		$settings = Options::sanitize( [ 'disabled_abilities' => [ 'bricks-mcp/get-seo', 'bricks/set-page-elements', '<script>' ] ] );

		$this->assertSame( [ 'bricks-mcp/get-seo' ], $settings['disabled_abilities'] );
	}

	public function test_migration_deletes_legacy_option_holding_only_our_keys_and_keeps_rate_limit(): void {
		update_option(
			Options::LEGACY_OPTION,
			[
				'enabled'        => false,
				'require_auth'   => true,
				'rate_limit_rpm' => 300,
			]
		);

		Options::migrate_legacy();

		$this->assertFalse( get_option( Options::LEGACY_OPTION, false ) );
		$this->assertSame( 300, Options::get()['rate_limit_rpm'] );
	}

	public function test_migration_strips_only_our_keys_when_bricks_owns_the_option(): void {
		update_option(
			Options::LEGACY_OPTION,
			[
				'enabled'           => true,
				'disabledAbilities' => [ 'bricks/delete-post' ],
				'enabledAbilities'  => [],
				'dangerous_actions' => true,
				'custom_base_url'   => 'https://example.com',
			]
		);

		Options::migrate_legacy();

		$this->assertSame(
			[
				'enabled'           => true,
				'disabledAbilities' => [ 'bricks/delete-post' ],
				'enabledAbilities'  => [],
			],
			get_option( Options::LEGACY_OPTION )
		);
	}

	public function test_migration_leaves_a_bricks_only_option_untouched(): void {
		$bricks = [
			'enabled'           => false,
			'disabledAbilities' => [],
			'enabledAbilities'  => [ 'bricks/upsert-builder-capability' ],
		];
		update_option( Options::LEGACY_OPTION, $bricks );

		Options::migrate_legacy();

		$this->assertSame( $bricks, get_option( Options::LEGACY_OPTION ) );
	}

	public function test_migration_keeps_unknown_keys_and_drops_our_enabled_flag(): void {
		update_option(
			Options::LEGACY_OPTION,
			[
				'enabled'      => false,
				'require_auth' => true,
				'futureKey'    => 'x',
			]
		);

		Options::migrate_legacy();

		$this->assertSame( [ 'futureKey' => 'x' ], get_option( Options::LEGACY_OPTION ) );
	}

	public function test_fresh_install_never_touches_the_bricks_option(): void {
		update_option( Options::LEGACY_OPTION, [ 'enabled' => false ] );

		Plugin::maybe_upgrade();

		$this->assertSame( [ 'enabled' => false ], get_option( Options::LEGACY_OPTION ) );
	}

	public function test_upgrade_from_1x_flags_the_reconnect_notice(): void {
		update_option( 'bricks_mcp_version', '1.5.1' );

		Plugin::maybe_upgrade();

		$this->assertSame( 1, get_option( Plugin::RECONNECT_NOTICE_OPTION ) );
		$this->assertSame( BRICKS_MCP_VERSION, get_option( 'bricks_mcp_version' ) );
	}

	public function test_fresh_install_does_not_flag_the_reconnect_notice(): void {
		Plugin::maybe_upgrade();

		$this->assertFalse( get_option( Plugin::RECONNECT_NOTICE_OPTION, false ) );
		$this->assertSame( BRICKS_MCP_VERSION, get_option( 'bricks_mcp_version' ) );
	}
}
