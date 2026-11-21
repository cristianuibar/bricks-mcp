<?php
/**
 * HTTPS check.
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
 * Checks whether the site is served over HTTPS.
 *
 * Application Passwords require HTTPS by default in WordPress.
 */
class HttpsCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'https';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'HTTPS / SSL', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'connectivity';
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
	 * Run the HTTPS check.
	 *
	 * Relies on is_ssl() only: a raw X-Forwarded-Proto header is client-supplied
	 * unless a trusted proxy sets it, so it is not treated as HTTPS here.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		if ( is_ssl() ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'Site is served over HTTPS.', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		$fix_steps = array(
			__( 'Enable HTTPS/SSL on your site via your hosting provider.', 'bricks-mcp' ),
			__( 'If behind a reverse proxy that terminates HTTPS, configure WordPress to trust it (e.g. set $_SERVER["HTTPS"] = "on" in wp-config.php only when the request comes from your proxy) so is_ssl() reports HTTPS.', 'bricks-mcp' ),
		);

		$force_enable_step = self::force_enable_step();
		if ( null !== $force_enable_step ) {
			$fix_steps[] = $force_enable_step;
		}

		if ( wp_is_application_passwords_available() ) {
			// Local/development sites may use App Passwords over plain HTTP on purpose.
			if ( self::is_local_environment() ) {
				return array(
					'id'        => $this->id(),
					'label'     => $this->label(),
					'status'    => 'pass',
					'message'   => __( 'No HTTPS detected, but Application Passwords are available in this local/development environment.', 'bricks-mcp' ),
					'fix_steps' => array(),
					'category'  => $this->category(),
				);
			}

			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'warn',
				'message'   => __( 'Your site is not served over HTTPS, but Application Passwords are enabled. Every MCP request sends the username and Application Password in cleartext.', 'bricks-mcp' ),
				'fix_steps' => $fix_steps,
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'fail',
			'message'   => __( 'Your site is not served over HTTPS. Application Passwords require HTTPS by default.', 'bricks-mcp' ),
			'fix_steps' => $fix_steps,
			'category'  => $this->category(),
		);
	}

	/**
	 * Whether the site runs in a local or development environment.
	 *
	 * @return bool
	 */
	public static function is_local_environment(): bool {
		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Fix step that force-enables Application Passwords without HTTPS.
	 *
	 * Only offered for local/development environments, with a cleartext warning,
	 * because it removes the HTTPS requirement site-wide.
	 *
	 * @return string|null The fix step, or null outside local/development environments.
	 */
	public static function force_enable_step(): ?string {
		if ( ! self::is_local_environment() ) {
			return null;
		}

		return __( 'Local/development only: force-enable App Passwords with add_filter( "wp_is_application_passwords_available", "__return_true" ); Warning: without HTTPS the username and Application Password are sent in cleartext with every request. Never use this on a production site.', 'bricks-mcp' );
	}
}
