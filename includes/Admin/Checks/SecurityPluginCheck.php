<?php
/**
 * Security plugin compatibility check.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin\Checks;

use BricksMCP\Admin\DiagnosticCheck;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects known security plugins that may interfere with REST API or App Passwords.
 *
 * Plugins are classified by risk level:
 * - critical: Blocks REST API or App Passwords entirely (fail)
 * - high: May block depending on configuration (warn)
 * - medium/low: Informational (pass)
 */
class SecurityPluginCheck implements DiagnosticCheck {

	/**
	 * Known security plugins and their risk profiles.
	 *
	 * @var array<string, array{name: string, risk: string, note: string}>
	 */
	private const KNOWN_PLUGINS = array(
		'wordfence/wordfence.php'                     => array(
			'name' => 'Wordfence',
			'risk' => 'low',
			'note' => 'Wordfence does not block REST API or App Passwords.',
		),
		'better-wp-security/better-wp-security.php'   => array(
			'name' => 'iThemes/Solid Security',
			'risk' => 'high',
			'note' => 'May disable Application Passwords or restrict REST API access.',
		),
		'sucuri-scanner/sucuri.php'                   => array(
			'name' => 'Sucuri Security',
			'risk' => 'medium',
			'note' => 'WAF rules may block /wp-json/ at network level (not detectable from PHP).',
		),
		'all-in-one-wp-security-and-firewall/wp-security.php' => array(
			'name' => 'All In One WP Security',
			'risk' => 'high',
			'note' => 'May block REST API for non-logged-in users via Firewall > PHP rules.',
		),
		'perfmatters/perfmatters.php'                 => array(
			'name' => 'Perfmatters',
			'risk' => 'high',
			'note' => 'May disable REST API. Add "mcp" to Perfmatters > REST API > Allowed Routes.',
		),
		'wp-cerber/wp-cerber.php'                     => array(
			'name' => 'WP Cerber',
			'risk' => 'high',
			'note' => 'May block REST API and Application Passwords. Check Hardening settings.',
		),
		'disable-wp-rest-api/disable-wp-rest-api.php' => array(
			'name' => 'Disable WP REST API',
			'risk' => 'critical',
			'note' => 'Blocks REST API for non-authenticated users. Deactivate or whitelist the mcp REST namespace.',
		),
		'disable-json-api/disable-json-api.php'       => array(
			'name' => 'Disable REST API',
			'risk' => 'critical',
			'note' => 'Blocks REST API entirely. Deactivate this plugin.',
		),
		'disable-application-passwords/disable-application-passwords.php' => array(
			'name' => 'Disable Application Passwords',
			'risk' => 'critical',
			'note' => 'Disables Application Passwords entirely. Deactivate this plugin.',
		),
	);

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'security_plugins';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Security Plugin Compatibility', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'compatibility';
	}

	/**
	 * Get dependencies.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Run the security plugin check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$detected_critical = array();
		$detected_high     = array();
		$detected_other    = array();

		foreach ( self::KNOWN_PLUGINS as $slug => $info ) {
			if ( ! is_plugin_active( $slug ) ) {
				continue;
			}

			if ( 'critical' === $info['risk'] ) {
				$detected_critical[] = $info;
			} elseif ( 'high' === $info['risk'] ) {
				$detected_high[] = $info;
			} else {
				$detected_other[] = $info;
			}
		}

		if ( ! empty( $detected_critical ) ) {
			$fix_steps = array();
			foreach ( $detected_critical as $plugin ) {
				$fix_steps[] = $plugin['name'] . ': ' . $plugin['note'];
			}

			$names = implode( ', ', array_column( $detected_critical, 'name' ) );

			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => sprintf(
					// translators: %s is a comma-separated list of plugin names.
					__( 'Detected plugin(s) that block REST API or Application Passwords: %s', 'bricks-mcp' ),
					$names
				),
				'fix_steps' => $fix_steps,
				'category'  => $this->category(),
			);
		}

		if ( ! empty( $detected_high ) ) {
			$fix_steps = array();
			foreach ( $detected_high as $plugin ) {
				$fix_steps[] = $plugin['name'] . ': ' . $plugin['note'];
			}

			$names = implode( ', ', array_column( $detected_high, 'name' ) );

			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'warn',
				'message'   => sprintf(
					// translators: %s is a comma-separated list of plugin names.
					__( 'Detected security plugin(s) that may restrict REST API or Application Passwords: %s', 'bricks-mcp' ),
					$names
				),
				'fix_steps' => $fix_steps,
				'category'  => $this->category(),
			);
		}

		if ( ! empty( $detected_other ) ) {
			$names = implode( ', ', array_column( $detected_other, 'name' ) );

			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => sprintf(
					// translators: %s is a comma-separated list of plugin names.
					__( 'Detected security plugin(s) with low compatibility risk: %s', 'bricks-mcp' ),
					$names
				),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'No known conflicting security plugins detected.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
