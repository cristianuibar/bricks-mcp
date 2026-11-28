<?php
/**
 * Bricks MCP
 *
 * @package           BricksMCP
 * @author            Uibar Ion-Cristian
 * @copyright         2025 BUFF UP MEDIA S.R.L.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Bricks MCP Extensions
 * Plugin URI:        https://github.com/cristianuibar/bricks-mcp
 * Description:       Adds SEO, media, template and page-QA abilities to the AI abilities built into Bricks Builder. AI agents reach them through the same MCP connection as Bricks.
 * Version:           2.0.1
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Requires Plugins:  mcp-adapter
 * Author:            Uibar Ion-Cristian
 * Author URI:        https://github.com/cristianuibar/bricks-mcp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bricks-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/cristianuibar/bricks-mcp
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version.
define( 'BRICKS_MCP_VERSION', '2.0.1' );

// Minimum PHP version.
define( 'BRICKS_MCP_MIN_PHP_VERSION', '8.2' );

// Minimum WordPress version.
define( 'BRICKS_MCP_MIN_WP_VERSION', '7.1' );

// Minimum Bricks Builder version.
define( 'BRICKS_MCP_MIN_BRICKS_VERSION', '2.5' );

// Plugin directory path.
define( 'BRICKS_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'BRICKS_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'BRICKS_MCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version is sufficient.
 */
function bricks_mcp_check_php_version(): bool {
	return version_compare( PHP_VERSION, BRICKS_MCP_MIN_PHP_VERSION, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version is sufficient.
 */
function bricks_mcp_check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, BRICKS_MCP_MIN_WP_VERSION, '>=' );
}

/**
 * Display admin notice for PHP version requirement.
 *
 * @return void
 */
function bricks_mcp_php_version_notice(): void {
	$message = sprintf(
		/* translators: 1: Required PHP version, 2: Current PHP version */
		esc_html__( 'Bricks MCP Extensions requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to use this plugin.', 'bricks-mcp' ),
		BRICKS_MCP_MIN_PHP_VERSION,
		PHP_VERSION
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Display admin notice for WordPress version requirement.
 *
 * @return void
 */
function bricks_mcp_wp_version_notice(): void {
	global $wp_version;
	$message = sprintf(
		/* translators: 1: Required WordPress version, 2: Current WordPress version */
		esc_html__( 'Bricks MCP Extensions requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress to use this plugin.', 'bricks-mcp' ),
		BRICKS_MCP_MIN_WP_VERSION,
		$wp_version
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Check Bricks Builder version requirement.
 *
 * @return bool True if Bricks is installed and version is sufficient.
 */
function bricks_mcp_check_bricks_version(): bool {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		return false;
	}
	if ( ! class_exists( '\Bricks\Abilities\Manager' ) ) {
		return false;
	}
	return version_compare( BRICKS_VERSION, BRICKS_MCP_MIN_BRICKS_VERSION . '-dev', '>=' );
}

/**
 * Display admin notice for Bricks Builder requirement.
 *
 * @return void
 */
function bricks_mcp_bricks_version_notice(): void {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		$message = sprintf(
			/* translators: %s: Required Bricks version */
			esc_html__( 'Bricks MCP Extensions requires Bricks Builder %s or higher. Bricks Builder is not installed or not activated.', 'bricks-mcp' ),
			BRICKS_MCP_MIN_BRICKS_VERSION
		);
	} else {
		$message = sprintf(
			/* translators: 1: Required Bricks version, 2: Current Bricks version */
			esc_html__( 'Bricks MCP Extensions requires Bricks Builder %1$s or higher. You are running Bricks %2$s. Please upgrade Bricks Builder to use this plugin.', 'bricks-mcp' ),
			BRICKS_MCP_MIN_BRICKS_VERSION,
			BRICKS_VERSION
		);
	}
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

// Check requirements before loading the plugin.
if ( ! bricks_mcp_check_php_version() ) {
	add_action( 'admin_notices', 'bricks_mcp_php_version_notice' );
	return;
}

if ( ! bricks_mcp_check_wp_version() ) {
	add_action( 'admin_notices', 'bricks_mcp_wp_version_notice' );
	return;
}

// Load the autoloader.
require_once BRICKS_MCP_PLUGIN_DIR . 'includes/Autoloader.php';

// Initialize autoloader.
BricksMCP\Autoloader::register();


/**
 * Run plugin activation routine.
 *
 * @return void
 */
function bricks_mcp_activate(): void {
	BricksMCP\Activator::activate();
}
register_activation_hook( __FILE__, 'bricks_mcp_activate' );

/**
 * Run plugin deactivation routine.
 *
 * An inactive plugin loads no update verifier, so drop its cached update offer.
 *
 * @return void
 */
function bricks_mcp_deactivate(): void {
	BricksMCP\Updates\UpdateChecker::forget_update_offer();
}
register_deactivation_hook( __FILE__, 'bricks_mcp_deactivate' );

/**
 * Initialize the plugin after theme is loaded (Bricks version available).
 *
 * @return void
 */
function bricks_mcp_init(): void {
	if ( ! bricks_mcp_check_bricks_version() ) {
		add_action( 'admin_notices', 'bricks_mcp_bricks_version_notice' );
		// Keep the updater and its download verifier, so an update offer WordPress cached earlier is never installed unchecked.
		( new BricksMCP\Updates\UpdateChecker() )->init();
		return;
	}
	BricksMCP\Plugin::get_instance();
}
add_action( 'after_setup_theme', 'bricks_mcp_init' );
