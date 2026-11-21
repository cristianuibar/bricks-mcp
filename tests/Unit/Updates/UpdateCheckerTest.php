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
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']  = [];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = null;
		bricks_mcp_test_reset_admin();
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
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']  = [];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = null;
		bricks_mcp_test_reset_admin();
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
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v1.2.3/bricks-mcp.zip',
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

	// -----------------------------------------------------------------------
	// Release JSON validation — check_update() with an empty cache fetches it.
	// -----------------------------------------------------------------------

	/**
	 * Helper: make the GitHub API stub return the given release JSON.
	 *
	 * @param array<string,mixed> $release Decoded release payload.
	 * @return void
	 */
	private function stub_release( array $release ): void {
		$GLOBALS['_bricks_mcp_test_wp_remote_get_return'] = [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $release ),
		];
	}

	/**
	 * Helper: run check_update() for this plugin at version 1.0.0.
	 *
	 * @return mixed
	 */
	private function run_check_update() {
		return $this->checker->check_update(
			false,
			[
				'Version'   => '1.0.0',
				'UpdateURI' => 'https://github.com/cristianuibar/bricks-mcp',
			],
			'bricks-mcp/bricks-mcp.php',
			[]
		);
	}

	/**
	 * Test: an untrusted package URL is dropped; an untrusted checksum URL is never fetched and blocks the update.
	 *
	 * @return void
	 */
	public function test_check_update_rejects_untrusted_asset_urls(): void {
		$untrusted = [
			'http://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
			'https://evil.example/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
			'https://github.com.evil.example/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
			'https://github.com@evil.example/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
			'https://github.com:8443/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
			'https://github.com/someone/bricks-mcp/releases/download/v2.1.0/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/../../../../someone/x/releases/download/v2.1.0/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/../',
			'http://127.0.0.1/cristianuibar/bricks-mcp/releases/download/v2.1.0/',
		];

		$trusted = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/';

		foreach ( $untrusted as $base ) {
			$GLOBALS['_bricks_mcp_test_transients'] = [];
			$this->stub_release(
				[
					'tag_name' => 'v2.1.0',
					'assets'   => [
						[
							'name'                 => 'bricks-mcp.zip',
							'browser_download_url' => $base . 'bricks-mcp.zip',
						],
					],
				]
			);

			$result = $this->run_check_update();

			$this->assertIsArray( $result, $base );
			$this->assertSame( '2.1.0', $result['version'], $base );
			$this->assertSame( '', $result['package'], $base );

			$GLOBALS['_bricks_mcp_test_transients']               = [];
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] = [];
			$this->stub_release(
				[
					'tag_name' => 'v2.1.0',
					'assets'   => [
						[
							'name'                 => 'bricks-mcp.zip',
							'browser_download_url' => $trusted . 'bricks-mcp.zip',
						],
						[
							'name'                 => 'bricks-mcp.zip.sha256',
							'browser_download_url' => $base . 'bricks-mcp.zip.sha256',
						],
					],
				]
			);

			$this->assertFalse( $this->run_check_update(), $base );
			$this->assertSame( [], $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'], $base );
		}
	}

	/**
	 * Test: trusted assets are used; the checksum is fetched safely and an uppercase hash still verifies the ZIP.
	 *
	 * @return void
	 */
	public function test_check_update_accepts_repository_assets_and_verifies_uppercase_hash(): void {
		$base = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/';
		$this->stub_release(
			[
				'tag_name' => 'v2.1.0',
				'assets'   => [
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => $base . 'bricks-mcp.zip',
					],
					[
						'name'                 => 'bricks-mcp.zip.sha256',
						'browser_download_url' => $base . 'bricks-mcp.zip.sha256',
					],
				],
			]
		);

		$content = 'fake zip content for release test';
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
			'response' => [ 'code' => 200 ],
			'body'     => strtoupper( hash( 'sha256', $content ) ) . "  bricks-mcp.zip\n",
		];

		$result = $this->run_check_update();

		$this->assertIsArray( $result );
		$this->assertSame( $base . 'bricks-mcp.zip', $result['package'] );
		$this->assertSame( $base . 'bricks-mcp.zip.sha256', $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'][0]['url'] ?? null );

		$temp = $this->make_temp_file( $content );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

		$verified = $this->checker->verify_download( false, $result['package'], new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertSame( $temp, $verified );
	}

	/**
	 * Test: a published checksum that is malformed, truncated or unreachable blocks the update instead of skipping verification.
	 *
	 * @return void
	 */
	public function test_check_update_fails_closed_on_unusable_checksum(): void {
		$base      = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/';
		$responses = [
			'truncated digest' => [
				'response' => [ 'code' => 200 ],
				'body'     => substr( hash( 'sha256', 'zip' ), 0, 63 ) . "  bricks-mcp.zip\n",
			],
			'html page'        => [
				'response' => [ 'code' => 200 ],
				'body'     => '<html>' . str_repeat( 'z', 80 ) . '</html>',
			],
			'not found'        => [
				'response' => [ 'code' => 404 ],
				'body'     => hash( 'sha256', 'zip' ),
			],
		];

		foreach ( $responses as $label => $response ) {
			$GLOBALS['_bricks_mcp_test_transients'] = [];
			$this->stub_release(
				[
					'tag_name' => 'v2.1.0',
					'assets'   => [
						[
							'name'                 => 'bricks-mcp.zip',
							'browser_download_url' => $base . 'bricks-mcp.zip',
						],
						[
							'name'                 => 'bricks-mcp.zip.sha256',
							'browser_download_url' => $base . 'bricks-mcp.zip.sha256',
						],
					],
				]
			);
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = $response;

			$this->assertFalse( $this->run_check_update(), $label );
			$this->assertSame( [], $GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] ?? null, $label );
		}
	}

	/**
	 * Test: non-string tag_name and asset fields do not throw and are treated as missing.
	 *
	 * @return void
	 */
	public function test_check_update_tolerates_non_string_release_fields(): void {
		$this->stub_release( [ 'tag_name' => 2 ] );

		$this->assertFalse( $this->run_check_update() );

		$GLOBALS['_bricks_mcp_test_transients'] = [];
		$this->stub_release(
			[
				'tag_name' => 'v2.1.0',
				'html_url' => [ 'x' ],
				'assets'   => [
					'not-an-asset',
					[
						'name'                 => 7,
						'browser_download_url' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/a.zip',
					],
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => [ 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/b.zip' ],
					],
				],
			]
		);

		$result = $this->run_check_update();

		$this->assertIsArray( $result );
		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertSame( '', $result['package'] );
		$this->assertSame( '', $result['url'] );
	}

	/**
	 * Test: verify_download refuses a package URL outside the repository's release downloads.
	 *
	 * @return void
	 */
	public function test_verify_download_rejects_untrusted_package_url(): void {
		$this->set_expected_hash( str_repeat( 'a', 64 ) );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( 'x' );

		$result = $this->checker->verify_download(
			false,
			'https://evil.example/bricks-mcp.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'untrusted_package', $result->get_error_code() );
	}
}
