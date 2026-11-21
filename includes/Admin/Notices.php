<?php
/**
 * Admin notices for upgrade reconnection and Bricks AI prerequisites.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays actionable admin notices and handles reconnect notice dismissal.
 */
final class Notices {

	public const DISMISS_ACTION = 'bricks_mcp_dismiss_reconnect';

	/**
	 * Register admin notice hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'handle_dismiss_reconnect' ] );
	}

	/**
	 * Render applicable admin notices for administrators.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( Plugin::RECONNECT_NOTICE_OPTION ) ) {
			$this->render_reconnect_notice();
		}

		if ( ! $this->are_bricks_abilities_enabled() ) {
			$this->render_bricks_abilities_disabled_notice();
		}
	}

	/**
	 * Handle nonce-checked dismissal of the 2.0 reconnect notice.
	 *
	 * @return void
	 */
	public function handle_dismiss_reconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'bricks-mcp' ), 403 );
			return;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'bricks-mcp' ), 403 );
			return;
		}

		delete_option( Plugin::RECONNECT_NOTICE_OPTION );

		$redirect = wp_get_referer();
		wp_safe_redirect( is_string( $redirect ) && '' !== $redirect ? $redirect : admin_url( 'admin.php?page=bricks-mcp' ) );
	}

	/**
	 * Determine whether Bricks AI abilities are currently enabled.
	 *
	 * @return bool
	 */
	public function are_bricks_abilities_enabled(): bool {
		if ( defined( 'BRICKS_DISABLE_MCP' ) && (bool) constant( 'BRICKS_DISABLE_MCP' ) ) {
			return false;
		}

		if ( class_exists( '\Bricks\Abilities\Manager' ) && is_callable( [ '\Bricks\Abilities\Manager', 'is_enabled' ] ) ) {
			return (bool) \Bricks\Abilities\Manager::is_enabled();
		}

		return ! empty( \Bricks\Database::$global_settings['abilitiesApi'] );
	}

	/**
	 * Render the 2.0 endpoint migration notice.
	 *
	 * @return void
	 */
	private function render_reconnect_notice(): void {
		$bricks_ai_url = admin_url( 'admin.php?page=bricks-ai' );
		$dismiss_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::DISMISS_ACTION
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Bricks MCP Extensions 2.0:', 'bricks-mcp' ); ?></strong>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: Old REST endpoint path, 2: Bricks AI settings URL */
						__( 'Version 2.0 removed the old endpoint %1$s. Reconnect your AI client once via <a href="%2$s">Bricks &gt; Settings &gt; AI</a>; that single connection reaches both Bricks and Bricks MCP Extensions abilities.', 'bricks-mcp' ),
						'<code>/wp-json/bricks-mcp/v1/mcp</code>',
						esc_url( $bricks_ai_url )
					),
					[
						'a'    => [ 'href' => [] ],
						'code' => [],
					]
				);
				?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link"><?php esc_html_e( 'Dismiss', 'bricks-mcp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the notice shown when Bricks AI abilities are disabled.
	 *
	 * @return void
	 */
	private function render_bricks_abilities_disabled_notice(): void {
		$bricks_ai_url = admin_url( 'admin.php?page=bricks-ai' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Bricks MCP Extensions:', 'bricks-mcp' ); ?></strong>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Bricks AI settings URL */
						__( 'Bricks AI abilities are currently switched off. Several Bricks MCP Extensions abilities delegate to Bricks abilities. Enable them in <a href="%s">Bricks &gt; Settings &gt; AI</a>.', 'bricks-mcp' ),
						esc_url( $bricks_ai_url )
					),
					[
						'a' => [ 'href' => [] ],
					]
				);
				?>
			</p>
		</div>
		<?php
	}
}
