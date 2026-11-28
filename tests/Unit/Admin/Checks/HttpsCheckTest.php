<?php
/**
 * HttpsCheck and HostingProviderCheck HTTPS-advice unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin\Checks;

use BricksMCP\Admin\Checks\HostingProviderCheck;
use BricksMCP\Admin\Checks\HttpsCheck;
use PHPUnit\Framework\TestCase;

/**
 * Tests that plain HTTP is not reported as safe outside local environments.
 */
final class HttpsCheckTest extends TestCase {

	/**
	 * Markers of the hosting providers HostingProviderCheck tries before Pantheon.
	 *
	 * @var array<string>
	 */
	private const OTHER_HOSTING_MARKERS = [ 'IS_WP_ENGINE', 'KINSTA_CACHE_ZONE', 'FLYWHEEL_CONFIG_DIR', 'cw_allowed_ip', 'GD_SYSTEM_PLUGIN_DIR', 'SG_Security' ];

	/**
	 * Hosting markers inherited from the process, restored after each test.
	 *
	 * @var array<string, array{server: mixed, env: string|false}>
	 */
	private array $inherited_markers = [];

	/**
	 * Reset stubs and hide inherited hosting markers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset_admin();
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['PANTHEON_ENVIRONMENT'] );

		// A marker in the runner's environment would make the check select another provider.
		$this->inherited_markers = [];
		foreach ( self::OTHER_HOSTING_MARKERS as $marker ) {
			$this->inherited_markers[ $marker ] = [
				'server' => $_SERVER[ $marker ] ?? null,
				'env'    => getenv( $marker ),
			];
			unset( $_SERVER[ $marker ] );
			putenv( $marker );
		}
	}

	/**
	 * Clean up request globals and restore inherited hosting markers.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['PANTHEON_ENVIRONMENT'] );

		foreach ( $this->inherited_markers as $marker => $saved ) {
			if ( null !== $saved['server'] ) {
				$_SERVER[ $marker ] = $saved['server'];
			}
			if ( false !== $saved['env'] ) {
				putenv( $marker . '=' . $saved['env'] );
			}
		}
	}

	/**
	 * Whether any fix step recommends the force-enable filter.
	 *
	 * @param array<string, mixed> $result Check result.
	 * @return bool
	 */
	private function has_force_enable_step( array $result ): bool {
		foreach ( $result['fix_steps'] as $step ) {
			if ( str_contains( $step, '__return_true' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Status and force-enable advice for each environment on plain HTTP and HTTPS.
	 *
	 * @return void
	 */
	public function test_status_and_force_enable_advice_by_environment(): void {
		// [ is_ssl, environment, app passwords available, expected status, force-enable step shown ].
		$cases = [
			[ true, 'production', true, 'pass', false ],
			[ false, 'production', true, 'warn', false ],
			[ false, 'staging', true, 'warn', false ],
			[ false, 'production', false, 'fail', false ],
			[ false, 'local', true, 'pass', false ],
			[ false, 'development', true, 'pass', false ],
			[ false, 'local', false, 'fail', true ],
			[ false, 'development', false, 'fail', true ],
		];

		foreach ( $cases as [ $ssl, $env, $available, $status, $force_step ] ) {
			$label = sprintf( 'ssl=%s env=%s available=%s', var_export( $ssl, true ), $env, var_export( $available, true ) );

			$GLOBALS['_bricks_mcp_test_is_ssl']                  = $ssl;
			$GLOBALS['_bricks_mcp_test_environment_type']        = $env;
			$GLOBALS['_bricks_mcp_test_app_passwords_available'] = $available;

			$result = ( new HttpsCheck() )->run();

			$this->assertSame( $status, $result['status'], $label );
			$this->assertSame( $force_step, $this->has_force_enable_step( $result ), $label );
		}
	}

	/**
	 * The plain-HTTP warning describes this request, not every MCP request.
	 *
	 * @return void
	 */
	public function test_warn_message_describes_this_request_only(): void {
		$GLOBALS['_bricks_mcp_test_app_passwords_available'] = true;

		foreach ( [ 'production', 'staging' ] as $env ) {
			$GLOBALS['_bricks_mcp_test_environment_type'] = $env;

			$result = ( new HttpsCheck() )->run();

			$this->assertSame( 'warn', $result['status'], $env );
			$this->assertStringNotContainsString( 'Every MCP request', $result['message'], $env );
			$this->assertStringContainsString( 'cleartext', $result['message'], $env );
			$this->assertStringContainsString( 'Application Password', $result['message'], $env );
		}
	}

	/**
	 * The local force-enable step carries a cleartext warning.
	 *
	 * @return void
	 */
	public function test_local_force_enable_step_warns_about_cleartext(): void {
		$GLOBALS['_bricks_mcp_test_environment_type'] = 'local';

		$result = ( new HttpsCheck() )->run();
		$steps  = array_values( array_filter( $result['fix_steps'], static fn ( string $step ): bool => str_contains( $step, '__return_true' ) ) );

		$this->assertCount( 1, $steps );
		$this->assertStringContainsString( 'cleartext', $steps[0] );
	}

	/**
	 * A client-supplied X-Forwarded-Proto header does not count as HTTPS.
	 *
	 * @return void
	 */
	public function test_forwarded_proto_header_alone_is_not_https(): void {
		$_SERVER['HTTP_X_FORWARDED_PROTO']                   = 'https';
		$GLOBALS['_bricks_mcp_test_app_passwords_available'] = true;

		$result = ( new HttpsCheck() )->run();

		$this->assertSame( 'warn', $result['status'] );
	}

	/**
	 * Pantheon advice offers the force-enable filter only in local/development environments.
	 *
	 * @return void
	 */
	public function test_pantheon_force_enable_advice_is_local_only(): void {
		$_SERVER['PANTHEON_ENVIRONMENT'] = 'live';

		foreach ( [ 'production' => false, 'staging' => false, 'local' => true, 'development' => true ] as $env => $shown ) {
			$GLOBALS['_bricks_mcp_test_environment_type'] = $env;

			$result = ( new HostingProviderCheck() )->run();

			$this->assertSame( 'warn', $result['status'], $env );
			$this->assertStringContainsString( 'Pantheon', $result['message'], $env );
			$this->assertSame( $shown, $this->has_force_enable_step( $result ), $env );
		}
	}
}
