<?php
/**
 * Settings unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin;

use BricksMCP\Abilities\Registrar;
use BricksMCP\Admin\Settings;
use BricksMCP\Options;
use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;

/**
 * Tests settings registration, access control and form round-tripping through Options::sanitize().
 */
final class SettingsTest extends TestCase {

	/**
	 * Reset global state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset();
		bricks_mcp_test_reset_admin();
	}

	/**
	 * Test that register_settings registers Options::OPTION with Options::sanitize.
	 *
	 * @return void
	 */
	public function test_register_settings_registers_option_with_sanitize_callback(): void {
		( new Settings() )->register_settings();

		$registered = $GLOBALS['_bricks_mcp_test_registered_settings'][ Options::OPTION ] ?? null;

		$this->assertNotNull( $registered );
		$this->assertSame( [ Options::class, 'sanitize' ], $registered['args']['sanitize_callback'] ?? null );
	}

	/**
	 * Test that users without manage_options cannot render the settings page.
	 *
	 * @return void
	 */
	public function test_render_settings_page_blocks_unauthorized_user(): void {
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = false;

		ob_start();
		( new Settings() )->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
		$this->assertNotNull( $GLOBALS['_bricks_mcp_test_wp_die'] );
	}

	/**
	 * Test that the rendered settings form round-trips through Options::sanitize as intended.
	 *
	 * @return void
	 */
	public function test_settings_form_round_trips_through_options_sanitize(): void {
		$ability_names = array_keys( Registrar::definitions() );
		$this->assertNotEmpty( $ability_names );

		$target_ability = $ability_names[0];
		$settings       = new Settings();

		ob_start();
		$settings->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'admin.php?page=bricks-ai', $html );
		$this->assertStringContainsString( 'mcp/mcp-adapter-default-server', $html );

		$css_field    = Options::OPTION . '[css_guard]';
		$rpm_field    = Options::OPTION . '[rate_limit_rpm]';
		$target_field = Options::OPTION . '[disabled_abilities][' . $target_ability . ']';

		$all_enabled_no_css = $this->submit_form_through_sanitize(
			$html,
			[ $css_field => false ]
		);

		$this->assertFalse( $all_enabled_no_css['css_guard'] );
		$this->assertSame( [], $all_enabled_no_css['disabled_abilities'] );
		$this->assertSame( 120, $all_enabled_no_css['rate_limit_rpm'] );

		$one_disabled_rpm_off = $this->submit_form_through_sanitize(
			$html,
			[
				$css_field    => true,
				$target_field => false,
			],
			[ $rpm_field => '0' ]
		);

		$this->assertTrue( $one_disabled_rpm_off['css_guard'] );
		$this->assertSame( [ $target_ability ], $one_disabled_rpm_off['disabled_abilities'] );
		$this->assertSame( 0, $one_disabled_rpm_off['rate_limit_rpm'] );

		update_option(
			Options::OPTION,
			[
				'disabled_abilities' => [ $target_ability ],
				'rate_limit_rpm'     => 60,
				'css_guard'          => false,
			]
		);

		ob_start();
		$settings->render_settings_page();
		$reloaded_html = (string) ob_get_clean();

		$reenabled_all = $this->submit_form_through_sanitize(
			$reloaded_html,
			[
				$css_field    => true,
				$target_field => true,
			]
		);

		$this->assertTrue( $reenabled_all['css_guard'] );
		$this->assertSame( [], $reenabled_all['disabled_abilities'] );
		$this->assertSame( 60, $reenabled_all['rate_limit_rpm'] );
	}

	/**
	 * Test that the Updates card links to an update only when WordPress is offered it:
	 * a newer release without a checksum bound to its ZIP is reported as blocked, not up to date.
	 *
	 * @return void
	 */
	public function test_updates_card_advertises_only_an_offered_release(): void {
		$package = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/bricks-mcp.zip';
		$hash    = hash( 'sha256', 'zip' );

		$cases = [
			'no checksum'          => [ [ 'sha256' => '', 'sha256_package' => '' ], false ],
			'checksum not for zip' => [ [ 'sha256' => $hash, 'sha256_package' => '' ], false ],
			'bound checksum'       => [ [ 'sha256' => $hash, 'sha256_package' => $package ], true ],
		];

		foreach ( $cases as $label => [ $checksum, $advertised ] ) {
			$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
				'version' => '2.1.0',
				'package' => $package,
				'url'     => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v2.1.0',
			] + $checksum;

			ob_start();
			( new Settings() )->render_settings_page();
			$html = (string) ob_get_clean();

			$this->assertSame( $advertised, str_contains( $html, 'v2.1.0 available' ), $label );
			$this->assertSame( $advertised, str_contains( $html, 'href="http://localhost/wp-admin/update-core.php"' ), $label );
			$this->assertSame( ! $advertised, str_contains( $html, 'v2.1.0 found, but its checksum could not be verified.' ), $label );
			$this->assertFalse( str_contains( $html, 'up to date' ), $label );
		}

		// Up to date means a checked release is not newer, not an empty cache: an expired check proves nothing.
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
			'version'        => BRICKS_MCP_VERSION,
			'package'        => $package,
			'url'            => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v' . BRICKS_MCP_VERSION,
			'sha256'         => $hash,
			'sha256_package' => $package,
		];
		ob_start();
		( new Settings() )->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'up to date', $html );
		$this->assertStringNotContainsString( 'bricks-mcp-update-available', $html );
		$this->assertStringNotContainsString( 'bricks-mcp-update-blocked', $html );
	}

	/**
	 * Parse the rendered options.php form, serialize inputs as a browser does, and run Options::sanitize().
	 *
	 * @param string                $html            Rendered page HTML.
	 * @param array<string, bool>   $checkbox_states Explicit checked state overrides by input name.
	 * @param array<string, string> $value_overrides Explicit value overrides by input name.
	 * @return array{disabled_abilities: string[], rate_limit_rpm: int, css_guard: bool}
	 */
	private function submit_form_through_sanitize( string $html, array $checkbox_states = [], array $value_overrides = [] ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		$forms = $dom->getElementsByTagName( 'form' );
		$this->assertGreaterThan( 0, $forms->length );

		$pairs = [];
		foreach ( $forms->item( 0 )->getElementsByTagName( 'input' ) as $input ) {
			if ( ! $input instanceof DOMElement ) {
				continue;
			}

			$name = $input->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}

			$type = strtolower( $input->getAttribute( 'type' ) );
			if ( 'checkbox' === $type ) {
				$is_checked = array_key_exists( $name, $checkbox_states )
					? $checkbox_states[ $name ]
					: $input->hasAttribute( 'checked' );

				if ( ! $is_checked ) {
					continue;
				}
			}

			$value   = array_key_exists( $name, $value_overrides ) ? $value_overrides[ $name ] : $input->getAttribute( 'value' );
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $value );
		}

		$post = [];
		parse_str( implode( '&', $pairs ), $post );

		$raw = isset( $post[ Options::OPTION ] ) && is_array( $post[ Options::OPTION ] ) ? $post[ Options::OPTION ] : [];

		return Options::sanitize( $raw );
	}
}
