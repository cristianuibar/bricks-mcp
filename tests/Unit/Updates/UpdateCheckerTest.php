<?php
/**
 * UpdateChecker unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Updates;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
		$GLOBALS['_bricks_mcp_test_remote_get']        = [];
		$GLOBALS['_bricks_mcp_test_active_plugins']    = [ 'mcp-adapter/mcp-adapter.php' ];
		$GLOBALS['wp_version']                         = '7.1';
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

	// -----------------------------------------------------------------------
	// Release discovery and offer tests, through check_update().
	// -----------------------------------------------------------------------

	private const LIST_URL = 'https://api.github.com/repos/cristianuibar/bricks-mcp/releases?per_page=30';

	/**
	 * Helper: stub the releases list and each release's assets.
	 *
	 * @param array<int,array<string,mixed>> $releases Each: tag, draft?, prerelease?.
	 * @return void
	 */
	private function stub_releases( array $releases ): void {
		$list = [];
		foreach ( $releases as $release ) {
			$tag    = $release['tag'];
			$base   = "https://github.com/cristianuibar/bricks-mcp/releases/download/{$tag}/bricks-mcp.zip";
			$list[] = [
				'tag_name'   => $tag,
				'draft'      => $release['draft'] ?? false,
				'prerelease' => $release['prerelease'] ?? false,
				'html_url'   => "https://github.com/cristianuibar/bricks-mcp/releases/tag/{$tag}",
				'assets'     => [
					[ 'name' => 'bricks-mcp.zip', 'browser_download_url' => $base ],
					[ 'name' => 'bricks-mcp.zip.sha256', 'browser_download_url' => $base . '.sha256' ],
				],
			];
			$GLOBALS['_bricks_mcp_test_remote_get'][ $base . '.sha256' ] = [
				'response' => [ 'code' => 200 ],
				'body'     => str_repeat( 'a', 64 ) . '  bricks-mcp.zip',
			];
		}
		$GLOBALS['_bricks_mcp_test_remote_get'][ self::LIST_URL ] = [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( $list ),
		];
	}

	/**
	 * Helper: run the updater as WordPress does for an installed 1.5.1.
	 *
	 * @return mixed
	 */
	private function offer() {
		return $this->checker->check_update(
			false,
			[ 'Version' => '1.5.1', 'UpdateURI' => 'https://github.com/cristianuibar/bricks-mcp' ],
			'bricks-mcp/bricks-mcp.php',
			[]
		);
	}

	/**
	 * Helper: make the site look like a Bricks 2.5 install.
	 *
	 * @return void
	 */
	private function with_bricks( string $version = '2.5' ): void {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			define( 'BRICKS_VERSION', $version );
		}
	}

	public function test_offer_does_not_force_auto_update(): void {
		$this->stub_releases( [ [ 'tag' => 'v1.5.2' ], [ 'tag' => 'v1.5.1' ] ] );

		$offer = $this->offer();

		$this->assertSame( '1.5.2', $offer['version'] );
		$this->assertArrayNotHasKey( 'autoupdate', $offer );
		$this->assertArrayNotHasKey( 'requires', $offer );
		$this->assertSame( str_repeat( 'a', 64 ), $this->get_expected_hash() );
	}

	public function test_eligible_site_is_offered_2x_with_requirement(): void {
		$this->with_bricks();
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );

		$offer = $this->offer();

		$this->assertSame( '2.0.0', $offer['version'] );
		$this->assertSame( '7.1', $offer['requires'] );
		$this->assertSame( 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.0.0/bricks-mcp.zip', $offer['package'] );
	}

	public function test_old_wordpress_is_offered_1x_only(): void {
		$this->with_bricks();
		$GLOBALS['wp_version'] = '7.0.2';
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );

		$this->assertSame( '1.5.2', $this->offer()['version'] );
	}

	public function test_inactive_adapter_is_offered_1x_only(): void {
		$this->with_bricks();
		$GLOBALS['_bricks_mcp_test_active_plugins'] = [];
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );

		$this->assertSame( '1.5.2', $this->offer()['version'] );
	}

	#[RunInSeparateProcess]
	public function test_old_bricks_is_offered_1x_only(): void {
		define( 'BRICKS_VERSION', '2.4.9' );
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );

		$this->assertSame( '1.5.2', $this->offer()['version'] );
	}

	#[RunInSeparateProcess]
	public function test_bricks_beta_counts_as_eligible(): void {
		define( 'BRICKS_VERSION', '2.5-beta1' );
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );

		$this->assertSame( '2.0.0', $this->offer()['version'] );
	}

	public function test_drafts_and_prereleases_are_ignored(): void {
		$this->with_bricks();
		$this->stub_releases(
			[
				[ 'tag' => 'v2.1.0', 'draft' => true ],
				[ 'tag' => 'v2.0.0', 'prerelease' => true ],
				[ 'tag' => 'v1.5.2' ],
			]
		);

		$this->assertSame( '1.5.2', $this->offer()['version'] );
	}

	public function test_malformed_releases_response_offers_nothing_and_caches_briefly(): void {
		$GLOBALS['_bricks_mcp_test_remote_get'][ self::LIST_URL ] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"message":"rate limited"}',
		];

		$this->assertFalse( $this->offer() );
		$this->assertSame( [], $GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] );
	}

	public function test_cached_2x_is_not_offered_once_the_site_stops_being_eligible(): void {
		$this->with_bricks();
		$this->stub_releases( [ [ 'tag' => 'v2.0.0' ], [ 'tag' => 'v1.5.2' ] ] );
		$this->assertSame( '2.0.0', $this->offer()['version'] );

		$GLOBALS['_bricks_mcp_test_active_plugins'] = [];

		$this->assertFalse( $this->offer() );
	}

	public function test_major_jump_is_refused_by_the_auto_update_filter(): void {
		$major = (object) [ 'plugin' => 'bricks-mcp/bricks-mcp.php', 'new_version' => '2.0.0' ];
		$minor = (object) [ 'plugin' => 'bricks-mcp/bricks-mcp.php', 'new_version' => '1.5.2' ];
		$other = (object) [ 'plugin' => 'other/other.php', 'new_version' => '3.0.0' ];

		$this->assertFalse( $this->checker->block_major_auto_update( true, $major ) );
		$this->assertTrue( $this->checker->block_major_auto_update( true, $minor ) );
		$this->assertTrue( $this->checker->block_major_auto_update( true, $other ) );
	}

	private function get_expected_hash(): string {
		$ref = new \ReflectionProperty( UpdateChecker::class, 'expected_sha256' );
		$ref->setAccessible( true );
		return $ref->getValue( $this->checker );
	}
}
