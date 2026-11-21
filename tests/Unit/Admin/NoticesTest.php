<?php
/**
 * Notices unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin;

use Bricks\Database;
use BricksMCP\Admin\Notices;
use BricksMCP\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Tests admin notices and reconnect notice dismissal.
 */
final class NoticesTest extends TestCase {

	/**
	 * Reset global state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset();
		bricks_mcp_test_reset_admin();
		Database::$global_settings = [];
		$_REQUEST                  = [];
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_REQUEST                  = [];
		Database::$global_settings = [];
	}

	/**
	 * Test that dismissal rejects users without manage_options capability.
	 *
	 * @return void
	 */
	public function test_dismiss_reconnect_rejects_unauthorized_user(): void {
		update_option( Plugin::RECONNECT_NOTICE_OPTION, 1 );
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = false;
		$_REQUEST['_wpnonce']                               = wp_create_nonce( Notices::DISMISS_ACTION );

		( new Notices() )->handle_dismiss_reconnect();

		$this->assertSame( 403, $GLOBALS['_bricks_mcp_test_wp_die']['code'] ?? null );
		$this->assertSame( 1, get_option( Plugin::RECONNECT_NOTICE_OPTION ) );
		$this->assertNull( $GLOBALS['_bricks_mcp_test_redirect'] );
	}

	/**
	 * Test that dismissal rejects an invalid or missing nonce.
	 *
	 * @return void
	 */
	public function test_dismiss_reconnect_rejects_invalid_nonce(): void {
		update_option( Plugin::RECONNECT_NOTICE_OPTION, 1 );
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = true;
		$_REQUEST['_wpnonce']                               = 'invalid_token';

		( new Notices() )->handle_dismiss_reconnect();

		$this->assertSame( 403, $GLOBALS['_bricks_mcp_test_wp_die']['code'] ?? null );
		$this->assertSame( 1, get_option( Plugin::RECONNECT_NOTICE_OPTION ) );
		$this->assertNull( $GLOBALS['_bricks_mcp_test_redirect'] );
	}

	/**
	 * Test that dismissal deletes the option and redirects for valid admin and nonce.
	 *
	 * @return void
	 */
	public function test_dismiss_reconnect_deletes_option_for_valid_admin_and_nonce(): void {
		update_option( Plugin::RECONNECT_NOTICE_OPTION, 1 );
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = true;
		$GLOBALS['_bricks_mcp_test_referer']                = 'http://localhost/wp-admin/plugins.php';
		$_REQUEST['_wpnonce']                               = wp_create_nonce( Notices::DISMISS_ACTION );

		( new Notices() )->handle_dismiss_reconnect();

		$this->assertNull( $GLOBALS['_bricks_mcp_test_wp_die'] );
		$this->assertFalse( get_option( Plugin::RECONNECT_NOTICE_OPTION, false ) );
		$this->assertSame( 'http://localhost/wp-admin/plugins.php', $GLOBALS['_bricks_mcp_test_redirect']['location'] ?? null );
	}

	/**
	 * Test that reconnect and Bricks AI disabled notices render for admins and hide when resolved.
	 *
	 * @return void
	 */
	public function test_render_notices_reflects_option_and_bricks_setting(): void {
		update_option( Plugin::RECONNECT_NOTICE_OPTION, 1 );
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = true;

		ob_start();
		( new Notices() )->render_notices();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '/wp-json/bricks-mcp/v1/mcp', $html );
		$this->assertStringContainsString( 'admin.php?page=bricks-ai', $html );
		$this->assertStringContainsString( 'action=' . Notices::DISMISS_ACTION, $html );
		$this->assertStringContainsString( '_wpnonce=' . wp_create_nonce( Notices::DISMISS_ACTION ), $html );

		delete_option( Plugin::RECONNECT_NOTICE_OPTION );
		Database::$global_settings['abilitiesApi'] = true;

		ob_start();
		( new Notices() )->render_notices();
		$resolved_html = (string) ob_get_clean();

		$this->assertSame( '', trim( $resolved_html ) );
	}

	/**
	 * Test that notices are hidden from non-admin users.
	 *
	 * @return void
	 */
	public function test_render_notices_hidden_for_non_admin(): void {
		update_option( Plugin::RECONNECT_NOTICE_OPTION, 1 );
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = false;

		ob_start();
		( new Notices() )->render_notices();
		$html = (string) ob_get_clean();

		$this->assertSame( '', trim( $html ) );
	}
}
