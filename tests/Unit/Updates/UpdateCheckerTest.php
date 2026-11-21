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
	 * Release ZIP URL used by the tests.
	 *
	 * @var string
	 */
	private const PACKAGE_URL = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip';

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
	}

	/**
	 * Helper: seed the cached release data that verify_download() reads.
	 *
	 * @param string $hash SHA-256 hash recorded for the release ZIP.
	 * @return void
	 */
	private function cache_release_hash( string $hash ): void {
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
			'version' => '99.0.0',
			'package' => self::PACKAGE_URL,
			'url'     => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v99.0.0',
			'sha256'  => $hash,
		];
	}

	/**
	 * Helper: run verify_download() for this plugin on a fresh instance.
	 *
	 * A fresh instance mirrors the request in which WordPress installs the
	 * update from its cached update data without calling check_update().
	 *
	 * @return false|string|\WP_Error
	 */
	private function verify_on_fresh_instance() {
		return ( new UpdateChecker() )->verify_download(
			false,
			self::PACKAGE_URL,
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);
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

	/**
	 * Test: an error from an earlier filter (download already aborted) is passed through.
	 *
	 * @return void
	 */
	public function test_verify_download_passes_through_error_from_earlier_filter(): void {
		$this->cache_release_hash( str_repeat( 'a', 64 ) );
		$error = new \WP_Error( 'download_failed', 'Earlier filter aborted.' );

		$result = $this->checker->verify_download(
			$error,
			self::PACKAGE_URL,
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertSame( $error, $result );
	}

	/**
	 * Test: a ZIP supplied by an earlier filter is refused when the release has no checksum.
	 *
	 * @return void
	 */
	public function test_verify_download_refuses_supplied_zip_when_release_has_no_checksum(): void {
		$this->cache_release_hash( '' );
		$supplied = $this->make_temp_file( 'zip supplied by another filter' );

		$result = ( new UpdateChecker() )->verify_download(
			$supplied,
			self::PACKAGE_URL,
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_missing', $result->get_error_code() );
	}

	/**
	 * Test: a ZIP supplied by an earlier filter is checked against the release hash;
	 * a mismatch is refused without deleting the supplied file, a match is accepted.
	 *
	 * @return void
	 */
	public function test_verify_download_checks_supplied_zip_against_release_hash(): void {
		$content  = 'zip supplied by another filter';
		$supplied = $this->make_temp_file( $content );

		$this->cache_release_hash( str_repeat( '0', 64 ) );
		$mismatch = ( new UpdateChecker() )->verify_download( $supplied, self::PACKAGE_URL, new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $mismatch );
		$this->assertSame( 'checksum_mismatch', $mismatch->get_error_code() );
		$this->assertFileExists( $supplied );
		$this->assertSame( [], $GLOBALS['_bricks_mcp_test_deleted_files'] );

		$this->cache_release_hash( hash( 'sha256', $content ) );
		$match = ( new UpdateChecker() )->verify_download( $supplied, self::PACKAGE_URL, new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertSame( $supplied, $match );
	}

	/**
	 * Test: a reply from an earlier filter for another plugin is left untouched.
	 *
	 * @return void
	 */
	public function test_verify_download_passthrough_supplied_zip_for_other_plugin(): void {
		$this->cache_release_hash( '' );

		$result = $this->checker->verify_download(
			'/tmp/other-plugin.zip',
			'https://example.com/other-plugin.zip',
			new \stdClass(),
			[ 'plugin' => 'some-other-plugin/plugin.php' ]
		);

		$this->assertSame( '/tmp/other-plugin.zip', $result );
	}

	/**
	 * Test: returns false when plugin slug does not match 'bricks-mcp/bricks-mcp.php'.
	 *
	 * @return void
	 */
	public function test_verify_download_passthrough_when_not_our_plugin(): void {
		$this->cache_release_hash( str_repeat( 'a', 64 ) );

		$result = $this->checker->verify_download(
			false,
			'https://example.com/file.zip',
			new \stdClass(),
			[ 'plugin' => 'some-other-plugin/plugin.php' ]
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test: refuses the update when the cached release has no checksum.
	 *
	 * @return void
	 */
	public function test_verify_download_refuses_when_release_has_no_checksum(): void {
		$this->cache_release_hash( '' );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( 'unverified zip' );

		$result = $this->verify_on_fresh_instance();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_missing', $result->get_error_code() );
	}

	/**
	 * Test: refuses the update when the checksum file did not hold a SHA-256 hash.
	 *
	 * @return void
	 */
	public function test_verify_download_refuses_when_checksum_is_not_a_sha256(): void {
		$this->cache_release_hash( 'Not Found' );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( 'unverified zip' );

		$result = $this->verify_on_fresh_instance();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_missing', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Verification tests — hash mismatch and hash match.
	// -----------------------------------------------------------------------

	/**
	 * Test: returns WP_Error('checksum_mismatch') when downloaded file hash does not match the cached hash.
	 *
	 * @return void
	 */
	public function test_verify_download_returns_error_on_checksum_mismatch(): void {
		$temp = $this->make_temp_file( 'fake zip content for mismatch test' );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;
		$this->cache_release_hash( str_repeat( '0', 64 ) );

		$result = $this->verify_on_fresh_instance();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );

		// Temp file should have been deleted on mismatch.
		$this->assertContains( $temp, $GLOBALS['_bricks_mcp_test_deleted_files'] );
	}

	/**
	 * Test: returns temp file path when downloaded file hash matches the cached hash.
	 *
	 * @return void
	 */
	public function test_verify_download_returns_path_on_checksum_match(): void {
		$content = 'fake zip content for match test';
		$temp    = $this->make_temp_file( $content );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;
		$this->cache_release_hash( hash( 'sha256', $content ) );

		$result = $this->verify_on_fresh_instance();

		$this->assertSame( $temp, $result );

		// Temp file should NOT be deleted on match — WP will use it.
		$this->assertNotContains( $temp, $GLOBALS['_bricks_mcp_test_deleted_files'] );
	}

	/**
	 * Test: with no cached release data, refetches the release and checks the ZIP
	 * against the checksum asset named after that ZIP, not another .sha256 asset.
	 *
	 * @return void
	 */
	public function test_verify_download_refetches_release_and_uses_checksum_named_after_zip(): void {
		$content = 'fake zip content for refetch test';
		$temp    = $this->make_temp_file( $content );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

		$checksum_url = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip.sha256';
		$other_url    = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/source.tar.gz.sha256';
		$GLOBALS['_bricks_mcp_test_wp_remote_get_responses'] = [
			'https://api.github.com/repos/cristianuibar/bricks-mcp/releases/latest' => [
				'response' => [ 'code' => 200 ],
				'body'     => (string) wp_json_encode(
					[
						'tag_name' => 'v99.0.0',
						'html_url' => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v99.0.0',
						'assets'   => [
							[
								'name'                 => 'bricks-mcp.zip',
								'browser_download_url' => self::PACKAGE_URL,
							],
							[
								'name'                 => 'bricks-mcp.zip.sha256',
								'browser_download_url' => $checksum_url,
							],
							[
								'name'                 => 'source.tar.gz.sha256',
								'browser_download_url' => $other_url,
							],
						],
					]
				),
			],
			$checksum_url => [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', $content ) . "  bricks-mcp.zip\n",
			],
			$other_url    => [
				'response' => [ 'code' => 200 ],
				'body'     => str_repeat( 'f', 64 ) . "  source.tar.gz\n",
			],
		];

		$result = $this->verify_on_fresh_instance();

		$this->assertSame( $temp, $result );
	}

	/**
	 * Test: refuses the update when the release has a .sha256 asset for another file only.
	 *
	 * @return void
	 */
	public function test_verify_download_refuses_when_no_checksum_matches_zip(): void {
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( 'unverified zip' );

		$other_url = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/source.tar.gz.sha256';
		$GLOBALS['_bricks_mcp_test_wp_remote_get_responses'] = [
			'https://api.github.com/repos/cristianuibar/bricks-mcp/releases/latest' => [
				'response' => [ 'code' => 200 ],
				'body'     => (string) wp_json_encode(
					[
						'tag_name' => 'v99.0.0',
						'assets'   => [
							[
								'name'                 => 'bricks-mcp.zip',
								'browser_download_url' => self::PACKAGE_URL,
							],
							[
								'name'                 => 'source.tar.gz.sha256',
								'browser_download_url' => $other_url,
							],
						],
					]
				),
			],
			$other_url => [
				'response' => [ 'code' => 200 ],
				'body'     => str_repeat( 'f', 64 ) . "  source.tar.gz\n",
			],
		];

		$result = $this->verify_on_fresh_instance();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_missing', $result->get_error_code() );
	}

	/**
	 * Test: a plugin installed in a renamed folder is offered the update and its
	 * download is still checked, so a release without a checksum is refused.
	 *
	 * Runs in a separate PHP process with a different BRICKS_MCP_PLUGIN_BASENAME.
	 *
	 * @return void
	 */
	public function test_verify_download_checks_renamed_install_that_is_offered_the_update(): void {
		$output = [];
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/renamed-install.php' ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame(
			[
				'offered' => true,
				'result'  => 'checksum_missing',
			],
			json_decode( implode( "\n", $output ), true )
		);
	}
}
