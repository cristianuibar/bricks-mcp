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
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']  = [];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = null;
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [];
		$GLOBALS['_bricks_mcp_test_now']                = null;
		$GLOBALS['_bricks_mcp_test_transient_expiries'] = [];
		bricks_mcp_test_reset_admin();
		$GLOBALS['_bricks_mcp_test_hooks'] = [];
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
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [];
		$GLOBALS['_bricks_mcp_test_now']                = null;
		$GLOBALS['_bricks_mcp_test_transient_expiries'] = [];
		bricks_mcp_test_reset_admin();
	}

	/**
	 * Helper: seed the cached release data that verify_download() reads.
	 *
	 * @param string $hash SHA-256 hash recorded for the release ZIP.
	 * @return void
	 */
	private function cache_release_hash( string $hash ): void {
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = [
			'version'        => '99.0.0',
			'package'        => self::PACKAGE_URL,
			'url'            => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v99.0.0',
			'sha256'         => $hash,
			'sha256_package' => self::PACKAGE_URL,
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
	 * Test: another plugin on the github.com update hostname gets no offer from a
	 * cached release that this plugin is offered.
	 *
	 * @return void
	 */
	public function test_check_update_ignores_other_github_hosted_plugins(): void {
		$this->cache_release_hash( str_repeat( 'a', 64 ) );

		$this->assertIsArray( $this->run_check_update() );

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
		];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [
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
		];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [
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
	 * Test: a checksum published minutes after the release unblocks the install,
	 * instead of the first check caching the missing checksum for 12 hours.
	 *
	 * @return void
	 */
	public function test_verify_download_accepts_checksum_published_after_first_check(): void {
		$content = 'release zip uploaded before its checksum';
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( $content );
		$GLOBALS['_bricks_mcp_test_now']                 = 1_700_000_000;

		$checksum_url = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/bricks-mcp.zip.sha256';
		$assets       = [
			[
				'name'                 => 'bricks-mcp.zip',
				'browser_download_url' => self::PACKAGE_URL,
			],
		];
		$this->stub_release(
			[
				'tag_name' => 'v99.0.0',
				'assets'   => $assets,
			]
		);

		$first = $this->verify_on_fresh_instance();
		$this->assertInstanceOf( \WP_Error::class, $first );
		$this->assertSame( 'checksum_missing', $first->get_error_code() );

		// The release workflow uploads the checksum shortly after the ZIP.
		$assets[] = [
			'name'                 => 'bricks-mcp.zip.sha256',
			'browser_download_url' => $checksum_url,
		];
		$this->stub_release(
			[
				'tag_name' => 'v99.0.0',
				'assets'   => $assets,
			]
		);
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [
			$checksum_url => [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', $content ) . "  bricks-mcp.zip\n",
			],
		];
		$GLOBALS['_bricks_mcp_test_now'] += 5 * MINUTE_IN_SECONDS;

		$this->assertSame( $GLOBALS['_bricks_mcp_test_download_url_return'], $this->verify_on_fresh_instance() );

		// Once the checksum is read, the release stays cached for the full TTL.
		$GLOBALS['_bricks_mcp_test_now'] += 11 * HOUR_IN_SECONDS;
		$this->assertSame( $GLOBALS['_bricks_mcp_test_download_url_return'], $this->verify_on_fresh_instance() );
		$this->assertCount( 2, $GLOBALS['_bricks_mcp_test_wp_remote_get_calls'] );
	}

	/**
	 * Test: a ZIP that a later download filter (priority 20, e.g. a package cache)
	 * supplies is still verified, because the verifier runs after it.
	 *
	 * @return void
	 */
	public function test_verify_download_checks_zip_supplied_by_later_filter(): void {
		$released = 'released zip';
		$this->cache_release_hash( hash( 'sha256', $released ) );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( $released );
		$corrupt = $this->make_temp_file( 'corrupt cached zip' );

		( new UpdateChecker() )->init();
		add_filter( 'upgrader_pre_download', static fn() => $corrupt, 20, 4 );

		$result = bricks_mcp_test_run_filter( 'upgrader_pre_download', false, self::PACKAGE_URL, new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );
	}

	/**
	 * Test: a release without a checksum is not offered, so WordPress never caches an
	 * update it could later install without the verifier.
	 *
	 * @return void
	 */
	public function test_check_update_does_not_offer_release_without_checksum(): void {
		$this->stub_release(
			[
				'tag_name' => 'v2.1.0',
				'assets'   => [
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/bricks-mcp.zip',
					],
				],
			]
		);

		$this->assertFalse( $this->run_check_update() );
	}

	/**
	 * Test: with Bricks Builder inactive, loading the plugin still registers the
	 * download verifier, so a cached update offer is not installed unchecked.
	 *
	 * Runs bricks-mcp.php in a separate PHP process.
	 *
	 * @return void
	 */
	public function test_verifier_stays_registered_when_bricks_is_inactive(): void {
		$output = [];
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/bricks-inactive.php' ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( [ 'result' => 'checksum_mismatch' ], json_decode( implode( "\n", $output ), true ) );
	}

	/**
	 * Test: the cached checksum belongs to the cached release, so a package from an
	 * older offer WordPress still has cached is refused, even when the supplied ZIP
	 * matches the newer release's checksum.
	 *
	 * @return void
	 */
	public function test_verify_download_refuses_package_from_older_cached_offer(): void {
		$newer_zip = 'v99.0.0 zip';
		$this->cache_release_hash( hash( 'sha256', $newer_zip ) );
		$supplied = $this->make_temp_file( $newer_zip );

		$result = ( new UpdateChecker() )->verify_download(
			$supplied,
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v98.0.0/bricks-mcp.zip',
			new \stdClass(),
			[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_package', $result->get_error_code() );
	}

	/**
	 * Test: deactivating the plugin drops its cached update offer (an inactive plugin
	 * loads no verifier) and keeps other plugins' offers.
	 *
	 * Runs bricks-mcp.php in a separate PHP process.
	 *
	 * @return void
	 */
	public function test_deactivation_removes_cached_update_offer(): void {
		$output = [];
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/deactivation.php' ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( [ 'offers' => [ 'other/other.php' ] ], json_decode( implode( "\n", $output ), true ) );
	}

	/**
	 * Test: an offer a concurrent refresh writes after the deactivation cleanup cannot
	 * be installed without the verifier, and installs verified while it is active.
	 *
	 * WordPress downloads packages with wp_safe_remote_get(), whose URL validation
	 * (wp_http_validate_url) accepts only http and https URLs.
	 *
	 * @return void
	 */
	public function test_offer_written_after_deactivation_is_only_downloadable_through_verifier(): void {
		$content = 'v99.0.0 zip';
		$this->cache_release_hash( hash( 'sha256', $content ) );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( $content );

		// Deactivation cleanup runs, then a cron refresh that started earlier writes the offer.
		UpdateChecker::forget_update_offer();
		$offer = $this->run_check_update();
		$this->assertIsArray( $offer );

		$scheme = strtolower( (string) wp_parse_url( $offer['package'], PHP_URL_SCHEME ) );
		$this->assertNotContains( $scheme, [ 'http', 'https' ], 'WordPress could download the offer without the verifier.' );

		( new UpdateChecker() )->init();
		$result = bricks_mcp_test_run_filter( 'upgrader_pre_download', false, $offer['package'], new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertSame( $GLOBALS['_bricks_mcp_test_download_url_return'], $result );
	}

	/**
	 * Test: release data cached by an older version, which paired the plugin ZIP URL
	 * with the debug ZIP's digest, does not authenticate the debug ZIP. The cache is
	 * refetched; when that fails the update is refused, and when it succeeds only the
	 * plugin ZIP's own checksum is accepted.
	 *
	 * @return void
	 */
	public function test_verify_download_rejects_debug_zip_digest_from_legacy_cache(): void {
		$plugin_zip = 'plugin zip';
		$debug_zip  = 'debug zip';
		$legacy     = [
			'version' => '99.0.0',
			'package' => self::PACKAGE_URL,
			'url'     => 'https://github.com/cristianuibar/bricks-mcp/releases/tag/v99.0.0',
			'sha256'  => hash( 'sha256', $debug_zip ),
		];
		$supplied_debug = $this->make_temp_file( $debug_zip );
		$verify         = static fn( string $zip ) => ( new UpdateChecker() )->verify_download( $zip, self::PACKAGE_URL, new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		// Refetch fails (GitHub unreachable): fail closed.
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = $legacy;
		$GLOBALS['_bricks_mcp_test_wp_remote_get_return']                 = new \WP_Error( 'http_request_failed', 'Connection refused' );

		$result = $verify( $supplied_debug );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_missing', $result->get_error_code() );

		// Refetch succeeds: the plugin ZIP's own checksum replaces the debug digest.
		$GLOBALS['_bricks_mcp_test_transients']['bricks_mcp_update_data'] = $legacy;
		$base = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v99.0.0/';
		$this->stub_release(
			[
				'tag_name' => 'v99.0.0',
				'assets'   => [
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => self::PACKAGE_URL,
					],
					[
						'name'                 => 'bricks-mcp.zip.sha256',
						'browser_download_url' => $base . 'bricks-mcp.zip.sha256',
					],
					[
						'name'                 => 'bricks-mcp-debug.zip.sha256',
						'browser_download_url' => $base . 'bricks-mcp-debug.zip.sha256',
					],
				],
			]
		);
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_responses'] = [
			$base . 'bricks-mcp.zip.sha256'       => [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', $plugin_zip ) . "  bricks-mcp.zip\n",
			],
			$base . 'bricks-mcp-debug.zip.sha256' => [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', $debug_zip ) . "  bricks-mcp-debug.zip\n",
			],
		];

		$result = $verify( $supplied_debug );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'checksum_mismatch', $result->get_error_code() );

		$supplied_plugin = $this->make_temp_file( $plugin_zip );
		$this->assertSame( $supplied_plugin, $verify( $supplied_plugin ) );
	}

	/**
	 * Test: a plugin installed in a renamed folder is offered the update and its
	 * download is still checked, so a ZIP with the wrong checksum is refused.
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
				'result'  => 'checksum_mismatch',
			],
			json_decode( implode( "\n", $output ), true )
		);
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
	 * Test: an untrusted package URL is not offered; an untrusted checksum URL is never fetched and blocks the update.
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
			'https://github.com/cristianuibar/bricks-mcp/releases/download/%2e%2e/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/%2E%2E/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/.%2e/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/%2e./',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/%2e/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/..%2f..%2f..%2f..%2fsomeone%2fx%2freleases%2fdownload%2fv2.1.0/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/..\\..\\..\\..\\someone\\x\\releases\\download\\v2.1.0/',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/%2e%2e%2f',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0%2B%2f..%2f..%2f..%2f..%2fsomeone%2fx%2freleases%2fdownload%2fv2.1.0/',
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

			$this->assertFalse( $this->run_check_update(), $base );

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
		$this->assertSame( 'bricks-mcp-verified+' . $base . 'bricks-mcp.zip', $result['package'] );
		$this->assertSame( $base . 'bricks-mcp.zip.sha256', $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'][0]['url'] ?? null );

		$temp = $this->make_temp_file( $content );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

		$verified = $this->checker->verify_download( false, $result['package'], new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

		$this->assertSame( $temp, $verified );
	}

	/**
	 * Test: a release tagged with build metadata (v2.1.0+build.7) is offered to a 2.0.0 site and its ZIP verifies.
	 *
	 * GitHub percent-encodes "+" in browser_download_url; the literal form is accepted too.
	 *
	 * @return void
	 */
	public function test_check_update_offers_release_tagged_with_build_metadata(): void {
		$content = 'fake zip content for build metadata release';

		foreach ( [ 'v2.1.0%2Bbuild.7', 'v2.1.0%2bbuild.7', 'v2.1.0+build.7' ] as $tag_segment ) {
			$GLOBALS['_bricks_mcp_test_transients']               = [];
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] = [];

			$base = 'https://github.com/cristianuibar/bricks-mcp/releases/download/' . $tag_segment . '/';
			$this->stub_release(
				[
					'tag_name' => 'v2.1.0+build.7',
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
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', $content ) . "  bricks-mcp.zip\n",
			];

			$result = $this->checker->check_update(
				false,
				[
					'Version'   => '2.0.0',
					'UpdateURI' => 'https://github.com/cristianuibar/bricks-mcp',
				],
				'bricks-mcp/bricks-mcp.php',
				[]
			);

			$this->assertIsArray( $result, $tag_segment );
			$this->assertSame( '2.1.0+build.7', $result['version'], $tag_segment );
			$this->assertSame( 'bricks-mcp-verified+' . $base . 'bricks-mcp.zip', $result['package'], $tag_segment );

			$temp = $this->make_temp_file( $content );
			$GLOBALS['_bricks_mcp_test_download_url_return'] = $temp;

			$verified = $this->checker->verify_download( false, $result['package'], new \stdClass(), [ 'plugin' => 'bricks-mcp/bricks-mcp.php' ] );

			$this->assertSame( $temp, $verified, $tag_segment );
		}
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
			'overlong digest'  => [
				'response' => [ 'code' => 200 ],
				'body'     => hash( 'sha256', 'zip' ) . "0  bricks-mcp.zip\n",
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
					[
						'name'                 => 'bricks-mcp.zip',
						'browser_download_url' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/bricks-mcp.zip',
					],
					[
						'name'                 => 'bricks-mcp.zip.sha256',
						'browser_download_url' => 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/bricks-mcp.zip.sha256',
					],
				],
			]
		);
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
			'response' => [ 'code' => 200 ],
			'body'     => hash( 'sha256', 'zip' ) . "  bricks-mcp.zip\n",
		];

		$result = $this->run_check_update();

		$this->assertIsArray( $result );
		$this->assertSame( '2.1.0', $result['version'] );
		$this->assertSame( 'bricks-mcp-verified+https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/bricks-mcp.zip', $result['package'] );
		$this->assertSame( '', $result['url'] );
	}

	/**
	 * Test: verify_download refuses a package URL outside the repository's release downloads.
	 *
	 * @return void
	 */
	public function test_verify_download_rejects_untrusted_package_url(): void {
		$this->cache_release_hash( str_repeat( 'a', 64 ) );
		$GLOBALS['_bricks_mcp_test_download_url_return'] = $this->make_temp_file( 'x' );

		$untrusted = [
			'https://evil.example/bricks-mcp.zip',
			'https://github.com/cristianuibar/bricks-mcp/releases/download/%2e%2e/bricks-mcp.zip',
		];

		foreach ( $untrusted as $package ) {
			$result = $this->checker->verify_download(
				false,
				$package,
				new \stdClass(),
				[ 'plugin' => 'bricks-mcp/bricks-mcp.php' ]
			);

			$this->assertInstanceOf( \WP_Error::class, $result, $package );
			$this->assertSame( 'untrusted_package', $result->get_error_code(), $package );
		}
	}

	/**
	 * Test: Check Now reports a newer release only when WordPress is offered it, so the
	 * Settings card shows no Update link for a release without a matching checksum.
	 *
	 * @return void
	 */
	public function test_check_now_reports_only_a_release_wordpress_is_offered(): void {
		$base   = 'https://github.com/cristianuibar/bricks-mcp/releases/download/v2.1.0/';
		$zip    = [
			'name'                 => 'bricks-mcp.zip',
			'browser_download_url' => $base . 'bricks-mcp.zip',
		];
		$sha256 = [
			'name'                 => 'bricks-mcp.zip.sha256',
			'browser_download_url' => $base . 'bricks-mcp.zip.sha256',
		];
		$other  = [
			'name'                 => 'other.zip.sha256',
			'browser_download_url' => $base . 'other.zip.sha256',
		];

		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
			'response' => [ 'code' => 200 ],
			'body'     => hash( 'sha256', 'zip' ) . "  bricks-mcp.zip\n",
		];
		$_REQUEST['nonce'] = wp_create_nonce( 'bricks_mcp_settings_nonce' );

		$cases = [
			'no checksum'               => [ [ $zip ], null ],
			'checksum for another file' => [ [ $zip, $other ], null ],
			'matching checksum'         => [ [ $zip, $sha256 ], '2.1.0' ],
		];

		foreach ( $cases as $label => [ $assets, $expected ] ) {
			$this->stub_release(
				[
					'tag_name' => 'v2.1.0',
					'assets'   => $assets,
				]
			);

			$this->checker->ajax_check_update();

			$this->assertTrue( $GLOBALS['_bricks_mcp_test_json_response']['success'] ?? null, $label );
			$this->assertSame( $expected, $GLOBALS['_bricks_mcp_test_json_response']['data']['version'] ?? null, $label );
			$this->assertSame( null !== $expected, is_array( $this->run_check_update() ), $label );
		}

		unset( $_REQUEST['nonce'] );
	}
}
