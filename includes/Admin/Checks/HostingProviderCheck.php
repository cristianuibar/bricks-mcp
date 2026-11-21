<?php
/**
 * Hosting provider detection check.
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
 * Detects the hosting provider and returns provider-specific fix instructions.
 *
 * Detection is informational — this check returns 'warn' at most, never 'fail'.
 * Providers are detected via $_SERVER environment variables, PHP constants,
 * getenv(), or known functions.
 */
class HostingProviderCheck implements DiagnosticCheck {

	/**
	 * Known hosting providers and their detection signatures.
	 *
	 * Each entry has:
	 * - detect: $_SERVER or getenv() key to check
	 * - detect_fn: optional function name to test with function_exists()
	 * - risk: 'low' | 'medium' | 'high'
	 * - fix_steps: provider-specific guidance
	 *
	 * @var array<string, array{name: string, detect: string, detect_fn?: string|null, risk: string, fix_steps: array<string>}>
	 */
	private const KNOWN_PROVIDERS = array(
		'wpengine'   => array(
			'name'      => 'WP Engine',
			'detect'    => 'IS_WP_ENGINE',
			'detect_fn' => 'is_wpe',
			'risk'      => 'medium',
			'fix_steps' => array(
				'WP Engine may strip Authorization headers, breaking Application Password authentication to the MCP Adapter. Add to .htaccess: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
				'If using WP Engine Smart Plugin Manager, ensure Bricks MCP Extensions and MCP Adapter are not disabled.',
			),
		),
		'kinsta'     => array(
			'name'      => 'Kinsta',
			'detect'    => 'KINSTA_CACHE_ZONE',
			'detect_fn' => null,
			'risk'      => 'low',
			'fix_steps' => array(
				'Kinsta supports REST API by default. If blocked, check Kinsta MyKinsta > Site > Tools > IP Deny.',
			),
		),
		'flywheel'   => array(
			'name'      => 'Flywheel',
			'detect'    => 'FLYWHEEL_CONFIG_DIR',
			'detect_fn' => null,
			'risk'      => 'medium',
			'fix_steps' => array(
				'Flywheel may strip Authorization headers, breaking Application Password authentication to the MCP Adapter. Add to .htaccess: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
			),
		),
		'cloudways'  => array(
			'name'      => 'Cloudways',
			'detect'    => 'cw_allowed_ip',
			'detect_fn' => null,
			'risk'      => 'low',
			'fix_steps' => array(
				'Cloudways supports REST API and Application Passwords. If issues persist, check Cloudways > Application Settings > Varnish.',
			),
		),
		'godaddy'    => array(
			'name'      => 'GoDaddy Managed WordPress',
			'detect'    => 'GD_SYSTEM_PLUGIN_DIR',
			'detect_fn' => null,
			'risk'      => 'low',
			'fix_steps' => array(
				'GoDaddy Managed WordPress supports REST API by default.',
			),
		),
		'siteground' => array(
			'name'      => 'SiteGround',
			'detect'    => 'SG_Security',
			'detect_fn' => null,
			'risk'      => 'medium',
			'fix_steps' => array(
				'If SiteGround Security plugin is active, check Settings > Login Security > Disable Application Passwords is OFF.',
				'If SG Optimizer is active, ensure it does not block /wp-json/ paths.',
			),
		),
		'pantheon'   => array(
			'name'      => 'Pantheon',
			'detect'    => 'PANTHEON_ENVIRONMENT',
			'detect_fn' => null,
			'risk'      => 'high',
			'fix_steps' => array(
				'Pantheon previously disabled Application Passwords by default. Check wp-config.php and mu-plugins for a "wp_is_application_passwords_available" filter that returns false, and serve the site over HTTPS before re-enabling them.',
				'Ensure Pantheon Advanced Page Cache does not cache /wp-json/ responses.',
			),
		),
	);

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'hosting_provider';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Hosting Provider Compatibility', 'bricks-mcp' );
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
	 * Run the hosting provider detection check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		foreach ( self::KNOWN_PROVIDERS as $provider_key => $provider ) {
			if ( ! $this->detect_provider( $provider_key, $provider ) ) {
				continue;
			}

			if ( 'high' === $provider['risk'] || 'medium' === $provider['risk'] ) {
				$fix_steps = $provider['fix_steps'];

				// The force-enable filter removes the HTTPS requirement, so only offer it locally.
				$force_enable_step = HttpsCheck::force_enable_step();
				if ( 'pantheon' === $provider_key && null !== $force_enable_step ) {
					$fix_steps[] = $force_enable_step;
				}

				return array(
					'id'        => $this->id(),
					'label'     => $this->label(),
					'status'    => 'warn',
					'message'   => sprintf(
						// translators: %s is the hosting provider name.
						__( 'Detected hosting: %s. This provider may require additional configuration for Application Password authentication to the MCP Adapter.', 'bricks-mcp' ),
						$provider['name']
					),
					'fix_steps' => $fix_steps,
					'category'  => $this->category(),
				);
			}

			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => sprintf(
					// translators: %s is the hosting provider name.
					__( 'Detected hosting: %s. No known compatibility issues.', 'bricks-mcp' ),
					$provider['name']
				),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'No specific hosting provider detected. Standard WordPress configuration assumed.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}

	/**
	 * Detect a hosting provider using multiple detection strategies.
	 *
	 * @param string                                                                                               $provider_key Provider array key.
	 * @param array{name: string, detect: string, detect_fn?: string|null, risk: string, fix_steps: array<string>} $provider     Provider configuration.
	 * @return bool True if the provider is detected.
	 */
	private function detect_provider( string $provider_key, array $provider ): bool {
		$detect_key = $provider['detect'];

		// Check $_SERVER.
		if ( isset( $_SERVER[ $detect_key ] ) ) {
			return true;
		}

		// Check PHP constants.
		if ( defined( $detect_key ) ) {
			return true;
		}

		// Check environment variables.
		if ( false !== getenv( $detect_key ) ) {
			return true;
		}

		// Check optional detection function.
		if ( ! empty( $provider['detect_fn'] ) && function_exists( $provider['detect_fn'] ) ) {
			return true;
		}

		// SiteGround-specific: also check plugin version constant or active plugin.
		if ( 'siteground' === $provider_key ) {
			if ( defined( 'SG_Security\\VERSION' ) ) {
				return true;
			}
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'sg-security/sg-security.php' ) ) {
				return true;
			}
		}

		return false;
	}
}
