<?php
/**
 * Admin settings page.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Handles the plugin settings page in WordPress admin.
 */
final class Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'bricks-mcp';

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'bricks_mcp_settings';

	/**
	 * Settings option group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'bricks_mcp_settings_group';

	/**
	 * Initialize admin settings.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 99 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_bricks_mcp_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
		add_action( 'wp_ajax_bricks_mcp_generate_app_password', [ $this, 'ajax_generate_app_password' ] );
		// Phase 2 — ApiFlash key test handler.
		add_action( 'wp_ajax_bricks_mcp_test_screenshot', [ $this, 'ajax_test_screenshot' ] );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'bricks',
			__( 'MCP Settings', 'bricks-mcp' ),
			__( 'MCP', 'bricks-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_defaults(),
			]
		);

		// General settings section.
		add_settings_section(
			'bricks_mcp_general',
			__( 'General Settings', 'bricks-mcp' ),
			[ $this, 'render_general_section' ],
			self::PAGE_SLUG
		);

		// Enable/disable field.
		add_settings_field(
			'enabled',
			__( 'Enable MCP Server', 'bricks-mcp' ),
			[ $this, 'render_enabled_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Require authentication field.
		add_settings_field(
			'require_auth',
			__( 'Require Authentication', 'bricks-mcp' ),
			[ $this, 'render_require_auth_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Custom base URL field.
		add_settings_field(
			'custom_base_url',
			__( 'Custom Base URL', 'bricks-mcp' ),
			[ $this, 'render_custom_base_url_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Rate limit field.
		add_settings_field(
			'rate_limit_rpm',
			__( 'Rate Limit (requests/minute)', 'bricks-mcp' ),
			[ $this, 'render_rate_limit_rpm_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Dangerous actions field.
		add_settings_field(
			'dangerous_actions',
			__( 'Dangerous Actions', 'bricks-mcp' ),
			[ $this, 'render_dangerous_actions_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Phase 2 — ApiFlash API key (fallback screenshot service).
		add_settings_field(
			'bricks_mcp_apiflash_key',
			__( 'ApiFlash API Key', 'bricks-mcp' ),
			[ $this, 'render_apiflash_key_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);

		// Phase 2 — Anthropic API key (page_visual_review / Claude Vision).
		add_settings_field(
			'bricks_mcp_anthropic_api_key',
			__( 'Anthropic API Key', 'bricks-mcp' ),
			[ $this, 'render_anthropic_api_key_field' ],
			self::PAGE_SLUG,
			'bricks_mcp_general'
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	private function get_defaults(): array {
		return [
			'enabled'           => true,
			'require_auth'      => true,
			'custom_base_url'   => '',
			'dangerous_actions' => false,
			'rate_limit_rpm'    => 120,
			// Phase 2 — screenshot and visual review API keys.
			'apiflash_key'      => '',
			'anthropic_api_key' => '',
		];
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		$sanitized['enabled']         = ! empty( $input['enabled'] );
		$sanitized['require_auth']    = ! empty( $input['require_auth'] );
		$sanitized['custom_base_url'] = isset( $input['custom_base_url'] )
			? esc_url_raw( trim( $input['custom_base_url'] ) )
			: '';

		$sanitized['dangerous_actions'] = ! empty( $input['dangerous_actions'] );
		$sanitized['rate_limit_rpm']    = max( 10, min( 1000, (int) ( $input['rate_limit_rpm'] ?? 120 ) ) );
		// Phase 2 — sanitize new API key fields.
		$sanitized['apiflash_key']      = sanitize_text_field( $input['apiflash_key'] ?? '' );
		$sanitized['anthropic_api_key'] = sanitize_text_field( $input['anthropic_api_key'] ?? '' );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bricks-mcp' ) );
		}

		// Display activation notice if issues were detected on plugin activation.
		$activation_results = get_transient( 'bricks_mcp_activation_checks' );
		if ( false !== $activation_results && is_array( $activation_results ) ) {
			delete_transient( 'bricks_mcp_activation_checks' );
			$has_issues = false;
			foreach ( $activation_results as $check ) {
				if ( 'fail' === ( $check['status'] ?? '' ) ) {
					$has_issues = true;
					break;
				}
			}
			if ( $has_issues ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Bricks MCP: Some configuration issues were detected during activation.', 'bricks-mcp' ) . '</strong> ';
				echo esc_html__( 'Click "Run Diagnostics" below for details and fix instructions.', 'bricks-mcp' ) . '</p></div>';
			}
		}

		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>

			<div class="bricks-mcp-info">
				<h3><?php esc_html_e( 'MCP Server Endpoints', 'bricks-mcp' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'MCP Endpoint:', 'bricks-mcp' ); ?></strong>
					<code><?php echo esc_html( rest_url( 'bricks-mcp/v1/mcp' ) ); ?></code>
				</p>
				<p class="description">
					<?php esc_html_e( 'This single endpoint handles all MCP protocol communication via JSON-RPC 2.0.', 'bricks-mcp' ); ?>
				</p>
			</div>

			<?php
			// Diagnostic panel (replaces Test Connection per D-07).
			$this->render_diagnostic_panel();

			// Version card.
			$this->render_version_card();

			// MCP configuration tabs.
			$this->render_mcp_config();
			?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render general section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Configure general MCP server settings.', 'bricks-mcp' ) . '</p>';
	}

	/**
	 * Render enabled field.
	 *
	 * @return void
	 */
	public function render_enabled_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-enabled">
			<input type="checkbox" id="bricks-mcp-enabled" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
			<?php esc_html_e( 'Enable the MCP server endpoints', 'bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When disabled, all MCP endpoints will return a 503 Service Unavailable response.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render require authentication field.
	 *
	 * @return void
	 */
	public function render_require_auth_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-require-auth">
			<input type="checkbox" id="bricks-mcp-require-auth" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[require_auth]" value="1" <?php checked( ! empty( $settings['require_auth'] ) ); ?>>
			<?php esc_html_e( 'Require user authentication for MCP endpoints', 'bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, only authenticated users with manage_options capability can access the MCP server.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render custom base URL field.
	 *
	 * @return void
	 */
	public function render_custom_base_url_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = $settings['custom_base_url'] ?? '';
		?>
		<input type="url" id="bricks-mcp-custom-base-url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[custom_base_url]"
			value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			placeholder="<?php echo esc_attr( get_site_url() ); ?>">
		<p class="description">
			<?php esc_html_e( 'Override the site URL used in MCP config snippets. Useful for reverse proxies or custom domains. Leave empty to use the default site URL.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render dangerous actions field.
	 *
	 * @return void
	 */
	public function render_dangerous_actions_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="bricks-mcp-dangerous-actions">
			<input type="checkbox" id="bricks-mcp-dangerous-actions" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[dangerous_actions]" value="1" <?php checked( ! empty( $settings['dangerous_actions'] ) ); ?>>
			<?php esc_html_e( 'Enable dangerous actions mode', 'bricks-mcp' ); ?>
		</label>
		<div class="bricks-mcp-danger-notice">
			<strong><?php esc_html_e( 'Warning: This enables unrestricted write access', 'bricks-mcp' ); ?></strong>
			<p>
				<?php esc_html_e( 'When enabled, AI tools can: write to global Bricks settings, execute custom JavaScript on pages, and modify code execution settings. Only enable this on development sites or when running trusted AI agent teams. API keys and secrets remain masked regardless of this setting.', 'bricks-mcp' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render rate limit RPM field.
	 *
	 * @return void
	 */
	public function render_rate_limit_rpm_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = (int) ( $settings['rate_limit_rpm'] ?? 120 );
		?>
		<input type="number" id="bricks-mcp-rate-limit-rpm" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[rate_limit_rpm]"
			value="<?php echo esc_attr( (string) $value ); ?>" min="10" max="1000" step="10" class="small-text">
		<span><?php esc_html_e( 'requests per minute per user', 'bricks-mcp' ); ?></span>
		<p class="description">
			<?php esc_html_e( 'Maximum number of MCP requests allowed per authenticated user per minute. Default: 120. Increase to 300 for intensive AI building sessions. Applies only when authentication is required.', 'bricks-mcp' ); ?>
		</p>
		<?php
	}

	// =========================================================================
	// Phase 2 additions — screenshot and visual review API key fields + AJAX
	// =========================================================================

	/**
	 * Render ApiFlash API key field.
	 *
	 * ApiFlash is the fallback screenshot service for page_screenshot and
	 * page_visual_review. Primary service is Microlink (50 req/day, no key).
	 * ApiFlash provides 100 free screenshots/month with a free API key.
	 *
	 * @return void
	 */
	public function render_apiflash_key_field(): void {
		$options   = get_option( self::OPTION_NAME, $this->get_defaults() );
		$field_val = sanitize_text_field( $options['apiflash_key'] ?? '' );
		printf(
			'<input type="password" id="bricks_mcp_apiflash_key" name="%s[apiflash_key]" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $field_val )
		);
		echo ' <button type="button" class="button" id="bricks-mcp-test-apiflash">'
			. esc_html__( 'Test Key', 'bricks-mcp' )
			. '</button>';
		echo ' <span id="bricks-mcp-test-apiflash-result" aria-live="polite"></span>';
		echo '<p class="description">'
			. wp_kses(
				__( 'Fallback screenshot service for <code>page_screenshot</code> and <code>page_visual_review</code>. 100 free/month. Get key at <a href="https://apiflash.com" target="_blank" rel="noopener noreferrer">apiflash.com</a>. Leave empty to use Microlink only (50 free/day, no key needed).', 'bricks-mcp' ),
				[
					'code' => [],
					'a'    => [ 'href' => [], 'target' => [], 'rel' => [] ],
				]
			)
			. '</p>';
	}

	/**
	 * Render Anthropic API key field.
	 *
	 * Required for the page_visual_review tool which sends a page screenshot
	 * to Claude Vision (claude-sonnet-4-20250514) for structured visual feedback.
	 * The key is stored in wp_options and is never echoed to the browser.
	 *
	 * @return void
	 */
	public function render_anthropic_api_key_field(): void {
		$options   = get_option( self::OPTION_NAME, $this->get_defaults() );
		$field_val = sanitize_text_field( $options['anthropic_api_key'] ?? '' );
		$has_key   = ! empty( $field_val );
		printf(
			'<input type="password" id="bricks_mcp_anthropic_api_key" name="%s[anthropic_api_key]" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $field_val )
		);
		if ( $has_key ) {
			echo ' <span style="color:#46b450;font-weight:600;">&#10003; '
				. esc_html__( 'Key saved', 'bricks-mcp' )
				. '</span>';
		}
		echo '<p class="description">'
			. wp_kses(
				__( 'Required for <code>page_visual_review</code> (Claude Vision AI feedback). Get key at <a href="https://console.anthropic.com" target="_blank" rel="noopener noreferrer">console.anthropic.com</a>. Stored securely — never sent to the browser.', 'bricks-mcp' ),
				[
					'code' => [],
					'a'    => [ 'href' => [], 'target' => [], 'rel' => [] ],
				]
			)
			. '</p>';
	}

	/**
	 * AJAX handler: Test ApiFlash API key validity.
	 *
	 * Makes a minimal test request to ApiFlash (320×240 PNG of the homepage).
	 * Cached responses do not count against the monthly quota, so this is safe
	 * to call repeatedly. Returns JSON success/error with a human-readable message.
	 *
	 * @return void
	 */
	public function ajax_test_screenshot(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'bricks-mcp' ) ] );
			return;
		}

		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No API key provided.', 'bricks-mcp' ) ] );
			return;
		}

		$test_url = add_query_arg(
			[
				'access_key' => $api_key,
				'url'        => rawurlencode( home_url( '/' ) ),
				'format'     => 'png',
				'width'      => 320,
				'height'     => 240,
				'full_page'  => 'false',
			],
			'https://api.apiflash.com/v1/urltoimage'
		);

		$response = wp_remote_get(
			esc_url_raw( $test_url ),
			[
				'timeout'   => 20,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
			return;
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( 200 === $code && str_contains( $content_type, 'image' ) ) {
			wp_send_json_success( [ 'message' => __( 'Key valid — ApiFlash connection successful.', 'bricks-mcp' ) ] );
		} else {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %d: HTTP status code returned by ApiFlash */
						__( 'ApiFlash returned HTTP %d. Check your API key.', 'bricks-mcp' ),
						(int) $code
					),
				]
			);
		}
	}

	// =========================================================================
	// End Phase 2 additions
	// =========================================================================

	/**
	 * Enqueue admin scripts on the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		if ( 'bricks_page_bricks-mcp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'bricks-mcp-admin-settings',
			BRICKS_MCP_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			BRICKS_MCP_VERSION
		);

		wp_enqueue_script(
			'bricks-mcp-admin-updates',
			BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-updates.js',
			[],
			BRICKS_MCP_VERSION,
			true
		);

		$current_user = wp_get_current_user();

		wp_localize_script(
			'bricks-mcp-admin-updates',
			'bricksMcpUpdates',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'bricks_mcp_settings_nonce' ),
				'currentVersion'  => BRICKS_MCP_VERSION,
				'siteUrl'         => get_site_url(),
				'restBase'        => rest_url( 'bricks-mcp/v1/' ),
				'mcpUrl'          => rest_url( 'bricks-mcp/v1/mcp' ),
				'currentUsername' => $current_user->user_login,
				'profileUrl'      => admin_url( 'profile.php' ),
				'updateCoreUrl'   => admin_url( 'update-core.php' ),
			]
		);
	}

	/**
	 * Render the version info card.
	 *
	 * Shows current version, update availability, and a "Check Now" button.
	 *
	 * @return void
	 */
	private function render_version_card(): void {
		$current_version = BRICKS_MCP_VERSION;
		$update_checker  = \BricksMCP\Plugin::get_instance()->get_update_checker();
		$update_data     = null !== $update_checker ? $update_checker->get_cached_update_data() : [];
		$has_update      = ! empty( $update_data['version'] )
			&& version_compare( $current_version, $update_data['version'], '<' );

		?>
		<div class="bricks-mcp-version-card<?php echo $has_update ? ' bricks-mcp-version-card--has-update' : ''; ?>">
			<h2><?php esc_html_e( 'Version', 'bricks-mcp' ); ?></h2>
			<p id="bricks-mcp-version-text">
				<strong>v<?php echo esc_html( $current_version ); ?></strong>
				<?php if ( $has_update ) : ?>
					&mdash;
					<span class="bricks-mcp-update-available">
						v<?php echo esc_html( $update_data['version'] ); ?>
						<?php esc_html_e( 'available', 'bricks-mcp' ); ?>
					</span>
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">
						<?php esc_html_e( 'Update', 'bricks-mcp' ); ?>
					</a>
				<?php else : ?>
					&mdash; <span class="bricks-mcp-up-to-date"><?php esc_html_e( 'up to date', 'bricks-mcp' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" id="bricks-mcp-check-update-btn" class="button">
					<?php esc_html_e( 'Check Now', 'bricks-mcp' ); ?>
				</button>
				<span id="bricks-mcp-check-update-spinner" class="spinner"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render MCP configuration tabs with Claude Code and Gemini snippets.
	 *
	 * Includes copy-to-clipboard and brief instructions.
	 *
	 * @return void
	 */
	private function render_mcp_config(): void {
		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-mcp/v1/mcp' );
		}

		// Build Claude Code config snippet.
		$claude_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini config snippet.
		$gemini_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Claude Desktop config snippet.
		$desktop_config = json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'command' => 'npx',
						'args'    => [
							'-y',
							'mcp-remote@latest',
							$mcp_url,
							'--header',
							'Authorization: Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		?>
		<div class="bricks-mcp-config-section">
			<h2><?php esc_html_e( 'MCP Configuration', 'bricks-mcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add the following configuration to your AI tool to connect to this MCP server.', 'bricks-mcp' ); ?></p>

			<!-- Generate Setup Command -->
			<div class="bricks-mcp-quick-setup">
				<h3><?php esc_html_e( 'Quick Setup', 'bricks-mcp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Generate an Application Password and get a ready-to-paste setup command.', 'bricks-mcp' ); ?></p>
				<p class="bricks-mcp-generate-btn-wrap">
					<button type="button" id="bricks-mcp-generate-btn" class="button button-primary">
						<?php esc_html_e( 'Generate Setup Command', 'bricks-mcp' ); ?>
					</button>
					<span id="bricks-mcp-generate-spinner" class="spinner"></span>
				</p>
				<div id="bricks-mcp-generate-error" class="bricks-mcp-generate-error" style="display:none;"></div>

				<div id="bricks-mcp-generated-result" class="bricks-mcp-generated-result" style="display:none;">
					<div class="bricks-mcp-important-notice">
						<strong><?php esc_html_e( 'Important:', 'bricks-mcp' ); ?></strong>
						<?php esc_html_e( 'This password is shown once. Copy your command now -- it cannot be retrieved later.', 'bricks-mcp' ); ?>
					</div>

					<h4><?php esc_html_e( 'Claude Code (one-liner):', 'bricks-mcp' ); ?></h4>
					<div class="bricks-mcp-code-wrap bricks-mcp-code-wrap--breakall">
						<pre><code id="bricks-mcp-generated-command"></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-generated-command">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>

					<hr>

					<h4><?php esc_html_e( 'Claude Code (JSON config):', 'bricks-mcp' ); ?></h4>
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-generated-claude-config"></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-generated-claude-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>

					<h4><?php esc_html_e( 'Gemini (JSON config):', 'bricks-mcp' ); ?></h4>
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-generated-gemini-config"></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-generated-gemini-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>

					<h4><?php esc_html_e( 'Claude Desktop (JSON config):', 'bricks-mcp' ); ?></h4>
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-generated-desktop-config"></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-generated-desktop-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
				</div>
			</div>

			<h3><?php esc_html_e( 'Manual Setup', 'bricks-mcp' ); ?></h3>

			<div class="bricks-mcp-tabs bricks-mcp-tabs-wrap">
				<div role="tablist">
					<button type="button" role="tab" id="bricks-mcp-tab-claude" data-tab="claude" aria-selected="true" aria-controls="bricks-mcp-panel-claude" tabindex="0" class="active">
						<?php esc_html_e( 'Claude Code', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-gemini" data-tab="gemini" aria-selected="false" aria-controls="bricks-mcp-panel-gemini" tabindex="-1">
						<?php esc_html_e( 'Gemini', 'bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="bricks-mcp-tab-claude-desktop" data-tab="claude-desktop" aria-selected="false" aria-controls="bricks-mcp-panel-claude-desktop" tabindex="-1">
						<?php esc_html_e( 'Claude Desktop', 'bricks-mcp' ); ?>
					</button>
				</div>

				<!-- Claude Code Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-claude" aria-labelledby="bricks-mcp-tab-claude" data-panel="claude">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-claude-config"><?php echo esc_html( $claude_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-claude-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your .mcp.json file, or use:', 'bricks-mcp' ); ?>
						<code>claude mcp add bricks-mcp <?php echo esc_html( $mcp_url ); ?> --transport http --header "Authorization: Basic ..."</code>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>. Or use the <strong>Generate Setup Command</strong> button above for a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
				</div>

				<!-- Gemini Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-gemini" aria-labelledby="bricks-mcp-tab-gemini" data-panel="gemini" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-gemini-config"><?php echo esc_html( $gemini_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-gemini-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your ~/.gemini/settings.json file.', 'bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>. Or use the <strong>Generate Setup Command</strong> button above for a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
				</div>

				<!-- Claude Desktop Panel -->
				<div role="tabpanel" id="bricks-mcp-panel-claude-desktop" aria-labelledby="bricks-mcp-tab-claude-desktop" data-panel="claude-desktop" style="display:none;">
					<div class="bricks-mcp-code-wrap">
						<pre><code id="bricks-mcp-desktop-config"><?php echo esc_html( $desktop_config ); ?></code></pre>
						<button type="button" class="button bricks-mcp-copy-btn" data-target="bricks-mcp-desktop-config">
							<?php esc_html_e( 'Copy to Clipboard', 'bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your Claude Desktop config file:', 'bricks-mcp' ); ?>
						<code>~/Library/Application Support/Claude/claude_desktop_config.json</code> (Mac) or
						<code>%APPDATA%\\Claude\\claude_desktop_config.json</code> (Windows)
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>. Or use the <strong>Generate Setup Command</strong> button above for a ready-to-paste config.', 'bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
				</div>
			</div>

			</div>
		<?php
	}

	/**
	 * AJAX handler: Run all diagnostic checks and return structured results.
	 *
	 * @return void
	 */
	public function ajax_run_diagnostics(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$runner = new \BricksMCP\Admin\DiagnosticRunner();
		$runner->register_defaults();
		$results = $runner->run_all();

		wp_send_json_success( $results );
	}

	/**
	 * Render the System Status diagnostic panel.
	 *
	 * Replaces the old Test Connection panel per D-07. Provides a Run Diagnostics
	 * button that executes all checks via AJAX and renders a colored checklist.
	 * Also provides a Copy Results button for support ticket use.
	 *
	 * @return void
	 */
	private function render_diagnostic_panel(): void {
		?>
		<style>
			.bricks-mcp-diagnostics { margin: 20px 0; background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; }
			.bricks-mcp-diagnostics h3 { margin-top: 0; }
			.bricks-mcp-diagnostics-actions { margin: 10px 0; display: flex; align-items: center; gap: 8px; }
			.bricks-mcp-check { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
			.bricks-mcp-check:last-child { border-bottom: none; }
			.bricks-mcp-check .dashicons { margin-top: 2px; font-size: 20px; width: 20px; height: 20px; }
			.bricks-mcp-check--pass .dashicons { color: #00a32a; }
			.bricks-mcp-check--warn .dashicons { color: #dba617; }
			.bricks-mcp-check--fail .dashicons { color: #d63638; }
			.bricks-mcp-check--skipped .dashicons { color: #787c82; }
			.bricks-mcp-check-content p { margin: 2px 0 0; }
			.bricks-mcp-check-fixes { margin-top: 5px; padding: 8px 12px; background: #f6f7f7; border-radius: 3px; }
			.bricks-mcp-check-fixes ul { margin: 5px 0 0 15px; }
			.bricks-mcp-diagnostics-summary { font-size: 14px; margin: 10px 0; }
			#bricks-mcp-diagnostics-spinner { float: none; margin: 0; }
		</style>

		<div class="bricks-mcp-diagnostics" id="bricks-mcp-diagnostics">
			<h3><?php esc_html_e( 'System Status', 'bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Run diagnostics to check if your site is properly configured for MCP connections.', 'bricks-mcp' ); ?></p>
			<div class="bricks-mcp-diagnostics-actions">
				<button type="button" class="button button-primary" id="bricks-mcp-run-diagnostics">
					<?php esc_html_e( 'Run Diagnostics', 'bricks-mcp' ); ?>
				</button>
				<button type="button" class="button" id="bricks-mcp-copy-results" style="display:none;">
					<?php esc_html_e( 'Copy Results', 'bricks-mcp' ); ?>
				</button>
				<span class="spinner" id="bricks-mcp-diagnostics-spinner"></span>
			</div>
			<div id="bricks-mcp-diagnostics-results"></div>
		</div>

		<script>
		(function() {
			var iconMap = {
				pass:    'dashicons-yes-alt',
				warn:    'dashicons-warning',
				fail:    'dashicons-dismiss',
				skipped: 'dashicons-minus'
			};

			var diagnosticsData = null;

			function escHtml(s) {
				var d = document.createElement('div');
				d.appendChild(document.createTextNode(s));
				return d.innerHTML;
			}

			document.getElementById('bricks-mcp-run-diagnostics').addEventListener('click', function() {
				var btn      = this;
				var spinner  = document.getElementById('bricks-mcp-diagnostics-spinner');
				var results  = document.getElementById('bricks-mcp-diagnostics-results');
				var copyBtn  = document.getElementById('bricks-mcp-copy-results');

				btn.disabled = true;
				spinner.classList.add('is-active');
				results.innerHTML = '';
				copyBtn.style.display = 'none';

				var data = new FormData();
				data.append('action', 'bricks_mcp_run_diagnostics');
				data.append('nonce', bricksMcpUpdates.nonce);

				fetch(bricksMcpUpdates.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(response) {
						btn.disabled = false;
						spinner.classList.remove('is-active');

						if (!response.success) {
							results.innerHTML = '<p style="color:#d63638;">' + (response.data && response.data.message ? escHtml(response.data.message) : '<?php echo esc_js( __( 'An error occurred.', 'bricks-mcp' ) ); ?>') + '</p>';
							return;
						}

						diagnosticsData = response.data;
						var html = '<p class="bricks-mcp-diagnostics-summary"><strong>' + escHtml(response.data.summary) + '</strong></p>';

						response.data.checks.forEach(function(check) {
							var icon = iconMap[check.status] || 'dashicons-minus';
							var fixHtml = '';
							if (check.fix_steps && check.fix_steps.length > 0) {
								fixHtml = '<div class="bricks-mcp-check-fixes"><strong><?php echo esc_js( __( 'How to fix:', 'bricks-mcp' ) ); ?></strong><ul>';
								check.fix_steps.forEach(function(step) {
									fixHtml += '<li>' + escHtml(step) + '</li>';
								});
								fixHtml += '</ul></div>';
							}
							html += '<div class="bricks-mcp-check bricks-mcp-check--' + check.status + '">';
							html += '<span class="dashicons ' + icon + '"></span>';
							html += '<div class="bricks-mcp-check-content">';
							html += '<strong>' + escHtml(check.label) + '</strong>';
							html += '<p>' + escHtml(check.message) + '</p>';
							html += fixHtml;
							html += '</div></div>';
						});

						results.innerHTML = html;
						copyBtn.style.display = 'inline-block';
					})
					.catch(function(err) {
						btn.disabled = false;
						spinner.classList.remove('is-active');
						results.innerHTML = '<p style="color:#d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'bricks-mcp' ) ); ?></p>';
					});
			});

			document.getElementById('bricks-mcp-copy-results').addEventListener('click', function() {
				if (!diagnosticsData) return;
				var copyBtn = this;
				var text = '';
				diagnosticsData.checks.forEach(function(check) {
					text += '[' + check.status.toUpperCase() + '] ' + check.label + ': ' + check.message + '\n';
					if (check.fix_steps && check.fix_steps.length > 0) {
						check.fix_steps.forEach(function(step) {
							text += '  Fix: ' + step + '\n';
						});
					}
				});
				navigator.clipboard.writeText(text).then(function() {
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'bricks-mcp' ) ); ?>';
					setTimeout(function() {
						copyBtn.textContent = '<?php echo esc_js( __( 'Copy Results', 'bricks-mcp' ) ); ?>';
					}, 2000);
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: Generate an Application Password and return setup commands.
	 *
	 * Creates a WordPress Application Password for the current user and returns
	 * a complete claude mcp add command with auth headers, plus JSON configs
	 * for Claude Code and Gemini with real credentials.
	 *
	 * @return void
	 */
	public function ajax_generate_app_password(): void {
		check_ajax_referer( 'bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'bricks-mcp' ) ], 403 );
		}

		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Create Application Password.
		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			[
				'name'   => 'Bricks MCP - Claude Code',
				'app_id' => wp_generate_uuid4(),
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// $result is [ $password, $item ] -- the raw password is only available at creation time.
		$password = $result[0];

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'bricks-mcp/v1/mcp' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$auth_string = base64_encode( $username . ':' . $password );

		// Build the complete CLI command.
		$claude_command = sprintf(
			'claude mcp add bricks-mcp %s --transport http --header "Authorization: Basic %s"',
			$mcp_url,
			$auth_string
		);

		// Build Claude Code JSON config with real credentials.
		$claude_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini JSON config with real credentials.
		$gemini_config = wp_json_encode(
			[
				'mcpServers' => [
					'bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		wp_send_json_success(
			[
				'password'       => $password,
				'username'       => $username,
				'auth_string'    => $auth_string,
				'claude_command' => $claude_command,
				'claude_config'  => $claude_config,
				'gemini_config'  => $gemini_config,
				'mcp_url'        => $mcp_url,
			]
		);
	}
}