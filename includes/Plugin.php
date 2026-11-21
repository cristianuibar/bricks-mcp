<?php
/**
 * Main plugin class.
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
 * Boots the abilities, safety layer, updater and admin screens.
 */
final class Plugin {

	public const RECONNECT_NOTICE_OPTION = 'bricks_mcp_reconnect_notice';

	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Update checker instance.
	 *
	 * @var Updates\UpdateChecker|null
	 */
	private ?Updates\UpdateChecker $update_checker = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self The plugin instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception When attempting to unserialize.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function init(): void {
		self::maybe_upgrade();

		( new I18n() )->init();

		$this->update_checker = new Updates\UpdateChecker();
		$this->update_checker->init();

		( new Abilities\Registrar() )->init();
		( new Abilities\Guide() )->init();
		( new Safety\RateLimiter() )->init();
		( new Safety\CssGuard() )->init();

		if ( is_admin() ) {
			( new Admin\Settings() )->init();
			( new Admin\SiteHealth() )->init();
			( new Admin\Notices() )->init();
		}

		add_filter( 'plugin_action_links_' . BRICKS_MCP_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );
	}

	/**
	 * Run version upgrades once per version change.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( 'bricks_mcp_version', '' );

		if ( BRICKS_MCP_VERSION === $stored ) {
			return;
		}

		if ( is_string( $stored ) && '' !== $stored && version_compare( $stored, '2.0.0', '<' ) ) {
			update_option( self::RECONNECT_NOTICE_OPTION, 1 );
			Options::migrate_legacy();
		}

		update_option( 'bricks_mcp_version', BRICKS_MCP_VERSION );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string> Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=bricks-mcp' ) ),
			esc_html__( 'Settings', 'bricks-mcp' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Get the update checker instance.
	 *
	 * @return Updates\UpdateChecker|null The update checker instance.
	 */
	public function get_update_checker(): ?Updates\UpdateChecker {
		return $this->update_checker;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string The plugin version.
	 */
	public function get_version(): string {
		return BRICKS_MCP_VERSION;
	}
}
