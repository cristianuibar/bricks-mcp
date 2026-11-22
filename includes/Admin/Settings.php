<?php
/**
 * Admin settings page.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\Abilities\Registrar;
use BricksMCP\Options;
use BricksMCP\Updates\UpdateChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and manages the Bricks MCP Extensions settings page.
 */
final class Settings {

	private const PAGE_SLUG    = 'bricks-mcp';
	private const OPTION_GROUP = 'bricks_mcp_extensions_group';

	/**
	 * Hook settings page actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 99 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_bricks_mcp_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
	}

	/**
	 * Add submenu page under Bricks.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_submenu_page( 'bricks', __( 'Bricks MCP Extensions', 'bricks-mcp' ), __( 'Bricks MCP Extensions', 'bricks-mcp' ), 'manage_options', self::PAGE_SLUG, [ $this, 'render_settings_page' ] );
	}

	/**
	 * Register the plugin settings option.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			Options::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Options::class, 'sanitize' ],
				'default'           => Options::defaults(),
			]
		);
	}

	/**
	 * Enqueue settings page CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		if ( 'bricks_page_bricks-mcp' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'bricks-mcp-admin-settings', BRICKS_MCP_PLUGIN_URL . 'assets/css/admin-settings.css', [], BRICKS_MCP_VERSION );
		wp_enqueue_script( 'bricks-mcp-admin-updates', BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-updates.js', [], BRICKS_MCP_VERSION, true );
		wp_localize_script(
			'bricks-mcp-admin-updates',
			'bricksMcpUpdates',
			[
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'bricks_mcp_settings_nonce' ),
				'currentVersion' => BRICKS_MCP_VERSION,
				'updateCoreUrl'  => admin_url( 'update-core.php' ),
				'i18n'           => [
					'error'       => __( 'An error occurred.', 'bricks-mcp' ),
					'requestFail' => __( 'Request failed. Please try again.', 'bricks-mcp' ),
					'howToFix'    => __( 'How to fix:', 'bricks-mcp' ),
					'copied'      => __( 'Copied!', 'bricks-mcp' ),
					'copyResults' => __( 'Copy Results', 'bricks-mcp' ),
				],
			]
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bricks-mcp' ) );
			return;
		}

		$settings    = Options::get();
		$status      = $this->get_connection_status();
		$option      = Options::OPTION;
		$update_data = ( new UpdateChecker() )->get_cached_update_data();
		$has_update  = ! empty( $update_data['version'] ) && UpdateChecker::is_newer_version( BRICKS_MCP_VERSION, (string) $update_data['version'] );
		?>
		<div class="wrap bricks-mcp-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>

			<div class="bricks-mcp-card">
				<h2><?php esc_html_e( 'Connection', 'bricks-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Client setup, Application Passwords, and Bricks abilities are managed in Bricks > Settings > AI.', 'bricks-mcp' ); ?> <span class="bricks-mcp-status bricks-mcp-status--<?php echo esc_attr( $status['state'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span></p>
				<p><strong><?php esc_html_e( 'Shared endpoint:', 'bricks-mcp' ); ?></strong> <code><?php echo esc_html( $status['endpoint_url'] ); ?></code></p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bricks-ai' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Open Bricks AI Settings', 'bricks-mcp' ); ?></a></p>
			</div>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<?php $this->render_abilities_card( $settings['disabled_abilities'] ); ?>
				<div class="bricks-mcp-card">
					<h2><?php esc_html_e( 'Safety', 'bricks-mcp' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bricks-mcp-rate-limit-rpm"><?php esc_html_e( 'Rate Limit (requests/min)', 'bricks-mcp' ); ?></label></th>
							<td>
								<input type="number" id="bricks-mcp-rate-limit-rpm" name="<?php echo esc_attr( $option ); ?>[rate_limit_rpm]" value="<?php echo esc_attr( (string) $settings['rate_limit_rpm'] ); ?>" min="0" max="<?php echo esc_attr( (string) Options::RATE_LIMIT_MAX ); ?>" step="1" class="small-text">
								<span><?php esc_html_e( '0 disables rate limiting; otherwise 10-1000.', 'bricks-mcp' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'CSS Guard', 'bricks-mcp' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $option ); ?>[css_guard]" value="0">
								<label for="bricks-mcp-css-guard"><input type="checkbox" id="bricks-mcp-css-guard" name="<?php echo esc_attr( $option ); ?>[css_guard]" value="1" <?php checked( $settings['css_guard'] ); ?>> <?php esc_html_e( 'Block unsafe CSS patterns in ability writes', 'bricks-mcp' ); ?></label>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button(); ?>
			</form>

			<div class="bricks-mcp-card bricks-mcp-diagnostics" id="bricks-mcp-diagnostics">
				<h2><?php esc_html_e( 'Diagnostics', 'bricks-mcp' ); ?></h2>
				<div class="bricks-mcp-diagnostics-actions">
					<button type="button" class="button" id="bricks-mcp-run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'bricks-mcp' ); ?></button>
					<button type="button" class="button" id="bricks-mcp-copy-results" hidden><?php esc_html_e( 'Copy Results', 'bricks-mcp' ); ?></button>
					<span class="spinner" id="bricks-mcp-diagnostics-spinner"></span>
				</div>
				<div id="bricks-mcp-diagnostics-results"></div>
			</div>

			<div class="bricks-mcp-card bricks-mcp-version-card<?php echo $has_update ? ' bricks-mcp-version-card--has-update' : ''; ?>">
				<h2><?php esc_html_e( 'Updates', 'bricks-mcp' ); ?></h2>
				<p id="bricks-mcp-version-text">
					<strong>v<?php echo esc_html( BRICKS_MCP_VERSION ); ?></strong> &mdash;
					<?php if ( $has_update ) : ?>
						<span class="bricks-mcp-update-available">v<?php echo esc_html( (string) $update_data['version'] ); ?> <?php esc_html_e( 'available', 'bricks-mcp' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Update', 'bricks-mcp' ); ?></a>
					<?php else : ?>
						<span class="bricks-mcp-up-to-date"><?php esc_html_e( 'up to date', 'bricks-mcp' ); ?></span>
					<?php endif; ?>
				</p>
				<p><button type="button" id="bricks-mcp-check-update-btn" class="button"><?php esc_html_e( 'Check Now', 'bricks-mcp' ); ?></button> <span id="bricks-mcp-check-update-spinner" class="spinner"></span></p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for running diagnostics.
	 *
	 * @return void
	 */
	public function ajax_run_diagnostics(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
			return;
		}

		$runner = new DiagnosticRunner();
		$runner->register_defaults();
		wp_send_json_success( $runner->run_all() );
	}

	/**
	 * Render per-ability toggles grouped by category.
	 *
	 * @param array<int, string> $disabled_abilities Currently disabled ability names.
	 * @return void
	 */
	private function render_abilities_card( array $disabled_abilities ): void {
		$grouped         = array_fill_keys( array_keys( Registrar::CATEGORIES ), [] );
		$category_labels = [
			'bricks-mcp-content'   => __( 'Content', 'bricks-mcp' ),
			'bricks-mcp-builder'   => __( 'Builder', 'bricks-mcp' ),
			'bricks-mcp-media'     => __( 'Media & Fonts', 'bricks-mcp' ),
			'bricks-mcp-qa'        => __( 'Quality', 'bricks-mcp' ),
			'bricks-mcp-reference' => __( 'Reference', 'bricks-mcp' ),
		];

		foreach ( Registrar::definitions() as $name => $args ) {
			$category                      = isset( $args['category'] ) && is_string( $args['category'] ) ? $args['category'] : '';
			$grouped[ $category ][ $name ] = $args;
		}
		?>
		<div class="bricks-mcp-card">
			<h2><?php esc_html_e( 'Abilities', 'bricks-mcp' ); ?></h2>
			<?php foreach ( $grouped as $slug => $items ) : ?>
				<?php if ( [] === $items ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<fieldset class="bricks-mcp-ability-group">
					<legend><?php echo esc_html( $category_labels[ $slug ] ?? ucwords( str_replace( '-', ' ', str_replace( 'bricks-mcp-', '', (string) $slug ) ) ) ); ?></legend>
					<ul class="bricks-mcp-ability-list">
						<?php foreach ( $items as $name => $args ) : ?>
							<?php
							$field_name  = Options::OPTION . '[disabled_abilities][' . $name . ']';
							$label       = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : $name;
							$description = isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '';
							?>
							<li class="bricks-mcp-ability-item">
								<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $name ); ?>">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" value="" <?php checked( ! in_array( $name, $disabled_abilities, true ) ); ?>>
									<strong><?php echo esc_html( $label ); ?></strong> <code><?php echo esc_html( $name ); ?></code>
									<?php if ( ! empty( $args['meta']['annotations']['destructive'] ) ) : ?>
										<span class="bricks-mcp-badge bricks-mcp-badge--destructive"><?php esc_html_e( 'Destructive', 'bricks-mcp' ); ?></span>
									<?php endif; ?>
								</label>
								<?php if ( '' !== $description ) : ?>
									<p class="bricks-mcp-ability-desc"><?php echo esc_html( $description ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Resolve MCP adapter status and endpoint URL.
	 *
	 * @return array{state: string, label: string, endpoint_url: string}
	 */
	private function get_connection_status(): array {
		$state        = defined( 'WP_MCP_VERSION' ) ? 'connected' : 'not_installed';
		$endpoint_url = '';

		if ( class_exists( '\Bricks\Helpers' ) && is_callable( [ '\Bricks\Helpers', 'get_mcp_server_status' ] ) ) {
			$status       = \Bricks\Helpers::get_mcp_server_status();
			$state        = isset( $status['state'] ) && is_string( $status['state'] ) ? $status['state'] : $state;
			$endpoint_url = isset( $status['endpoint_url'] ) && is_string( $status['endpoint_url'] ) ? $status['endpoint_url'] : '';
		}

		if ( '' === $endpoint_url ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Bricks' own filter name.
			$route        = (string) apply_filters( 'bricks_mcp_adapter_rest_route', 'mcp/mcp-adapter-default-server' );
			$endpoint_url = class_exists( '\Bricks\Helpers' ) && is_callable( [ '\Bricks\Helpers', 'get_mcp_adapter_endpoint_url' ] )
				? (string) \Bricks\Helpers::get_mcp_adapter_endpoint_url()
				: rtrim( rest_url( $route ), '/' );
		}

		$labels = [
			'connected'     => __( 'Adapter connected', 'bricks-mcp' ),
			'route_missing' => __( 'Adapter route missing', 'bricks-mcp' ),
			'inactive'      => __( 'Adapter inactive', 'bricks-mcp' ),
			'not_installed' => __( 'Adapter not installed', 'bricks-mcp' ),
		];

		return [
			'state'        => $state,
			'label'        => $labels[ $state ] ?? $labels['not_installed'],
			'endpoint_url' => $endpoint_url,
		];
	}
}
