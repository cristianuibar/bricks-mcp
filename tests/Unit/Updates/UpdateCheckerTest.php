<?php
/**
 * UpdateChecker unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use BricksMCP\Updates\UpdateChecker;

/**
 * Tests for UpdateChecker::verify_download() SHA-256 integrity checks.
 *
 * WordPress function stubs are provided by tests/stubs/wp-functions.php
 * (loaded via bootstrap-simple.php) in the global namespace. Tests control
 * stub behavior through $GLOBALS arrays reset in setUp()/tearDown().
 */
final class UpdateCheckerTest extends TestCase {

	/**
	 * UpdateChecker instance under test.
	 *
	 * @var UpdateChecker
	 */
	private UpdateChecker $checker;

	/**
	 * Temp files created during tests that need cleanup.
	 *
	 * @var string[]
	 */
	private array $temp_files = [];

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->checker    = new UpdateChecker();
		$this->temp_files = [];

		$GLOBALS['_bricks_mcp_test_transients']        = [];
		$GLOBALS['_bricks_mcp_test_download_url_return'] = null;
		$GLOBALS['_bricks_mcp_test_deleted_files']     = [];
	}

	/**
	 * Clean up temp files and globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		$GLOBALS['_bricks_mcp_test_transients']        = [];
		$GLOBALS['_bricks_mcp_test_download_url_return'] = null;
		$GLOBALS['_bricks_mcp_test_deleted_files']     = [];
	}

	/**
	 * Helper: set expected_sha256 on the checker via reflection.
	 *
	 * @param string $hash Expected SHA-256 hash.
	 * @return void
	 */
	private function set_expected_hash( string $hash ): void {
		$ref = new \ReflectionProperty( UpdateChecker::class, 'expected_sha256' );
		$ref->setAccessible( true );
		$ref->setValue( $this->checker, $hash );
	}

	/**
	 * Helper: create a temp file with given content and register for cleanup.
	 *
	 * @param string $content File content.
	 * @return string Temp file path.
	 */
	private function make_temp_file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'bricks_mcp_test_' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;
		return $path;
	}

	// -----------------------------------------------------------------------
	// Passthrough tests — verify_download returns $reply unchanged.
	// -----------------------------------------------------------------------

	/**
	 * Test: returns $reply when $reply is not false (already handled by earlier filter).
	 *
	 * @return void
	 */
	public function test_check_update_ignores_other_github_hosted_plugins(): void {
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
			'version' => '99.0.0',
			'package' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip',
		];

		$result = $this->checker->check_update(
			false,
			[
				'Version'   => '1.0.0',
				'UpdateURI' => 'https://github.com/someone/other-plugin',
			],
			'other-plugin/other-plugin.php',
			[]
		);

		$this->assertFalse( $result );
	}

	public function test_verify_download_passthrough_when_already_handled(): void {
		$this->set_expected_hash( 'abc123' );

		$result = $this->checker->verify_download(
			'some-value',
			'https://example.com/file.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertSame( 'some-value', $result );
	}

	/**
	 * Test: returns false when plugin slug does not match 'bricks-mcp/bricks-mcp.php'.
	 *
	 * @return void
	 */
	public function test_verify_download_passthrough_when_not_our_plugin(): void {
		$this->set_expected_hash( 'abc123' );

		$result = $this->checker->verify_download(
			false,
			'https://example.com/file.zip',
			new \stdClass(),
			[ 'plugin' => 'some-other-plugin/plugin.php' ]
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test: returns false when expected_sha256 is empty (graceful degradation — no hash to verify).
	 *
	 * @return void
	 */
	public function test_verify_download_passthrough_when_no_hash(): void {
		// No hash set — default is empty string.
		$result = $this->checker->verify_download(
			false,
			'https://example.com/file.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertFalse( $result );
	}

	// -----------------------------------------------------------------------
	// Verification tests — hash mismatch and hash match.
	// -----------------------------------------------------------------------

	/**
	 * Test: returns WP_Error('checksum_mismatch') when downloaded file hash does not match expected.
	 *
	 * @return void
	 */
	public function test_verify_download_returns_error_on_checksum_mismatch(): void {
		$temp = $this->make_temp_file( 'fake zip content for mismatch test' );

		// download_url stub returns our temp file.
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

		// Set a wrong expected hash.
		$this->set_expected_hash( str_repeat( '0', 64 ) );

		$result = $this->checker->verify_download(
			false,
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v1.2.3/bricks-mcp.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );

		// Temp file should have been deleted on mismatch.
		$this->assertContains( $temp, $GLOBALS['_bricks_mcp_test_deleted_files'] );
	}

	/**
	 * Test: returns temp file path when downloaded file hash matches expected.
	 *
	 * @return void
	 */
	public function test_verify_download_returns_path_on_checksum_match(): void {
		$content = 'fake zip content for match test';
		$temp    = $this->make_temp_file( $content );

		// Compute the actual hash so we can set it as expected.
		$actual_hash = hash( 'sha256', $content );

		// download_url stub returns our temp file.
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

		$this->set_expected_hash( $actual_hash );

		$result = $this->checker->verify_download(
			false,
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v1.2.3/bricks-mcp.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertSame( $temp, $result );

		// Temp file should NOT be deleted on match — WP will use it.
		$this->assertNotContains( $temp, $GLOBALS['_bricks_mcp_test_deleted_files'] );
	}
}
