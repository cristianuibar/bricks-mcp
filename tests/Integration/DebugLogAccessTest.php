<?php
/**
 * Tests that the wp-env debug log is not served over HTTP.
 *
 * Runs in wp-env (`npm run test:integration`) against the tests site's Apache.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Anonymous requests for the debug log.
 */
final class DebugLogAccessTest extends TestCase {

	/**
	 * The tests site's web server, by its wp-env service name.
	 */
	private const SERVER = 'tests-wordpress';

	protected function setUp(): void {
		if ( gethostbyname( self::SERVER ) === self::SERVER ) {
			$this->markTestSkipped( 'Needs the wp-env tests site: run npm run test:integration.' );
		}
	}

	/**
	 * The file the development site logs to under WP_DEBUG_LOG, at its web root URL on the tests site.
	 */
	public function test_visitor_cannot_read_configured_debug_log(): void {
		$site = $this->debug_log_path();
		$this->assertNotSame( '', $site['log'], 'WordPress does not log: set WP_DEBUG_LOG.' );

		if ( ! str_starts_with( $site['log'], $site['root'] ) ) {
			$this->markTestSkipped( $site['log'] . ' is outside the web root ' . $site['root'] . ', so Apache does not serve it.' );
		}

		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) ) . "\n";
		$size   = file_exists( $site['log'] ) ? filesize( $site['log'] ) : null;
		$this->assertNotFalse( file_put_contents( $site['log'], $canary, FILE_APPEND ), 'Cannot write ' . $site['log'] . '.' );

		try {
			$this->assert_denied( '/' . substr( $site['log'], strlen( $site['root'] ) ), trim( $canary ) );
		} finally {
			$this->remove_canary( $site['log'], $size, $canary );
		}
	}

	/**
	 * The plugin mount "." serves the checkout's logs/ folder whatever WP_DEBUG_LOG says.
	 */
	public function test_visitor_cannot_read_plugin_checkout_logs_folder(): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$file   = dirname( __DIR__, 2 ) . '/logs/bricks-mcp-access-test.log';
		file_put_contents( $file, $canary . "\n" );

		try {
			$this->assert_denied( '/wp-content/plugins/bricks-mcp/logs/bricks-mcp-access-test.log', $canary );
		} finally {
			unlink( $file );
		}
	}

	private function assert_denied( string $path, string $canary ): void {
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 10 ] ] );
		$body    = file_get_contents( 'http://' . self::SERVER . $path, false, $context );
		$this->assertNotFalse( $body, 'The tests site did not answer.' );

		preg_match( '#^HTTP/\S+ (\d{3})#', $http_response_header[0], $matches );

		$this->assertSame( 403, (int) $matches[1], $path );
		$this->assertStringNotContainsString( $canary, $body, $path );
	}

	/**
	 * Where wp_debug_mode() sends PHP errors with the tests site's WP_DEBUG_LOG.
	 *
	 * wp-env forces WP_DEBUG off on the tests site only, so it is turned back on
	 * here as the development site has it; both sites share WP_DEBUG_LOG, the
	 * wp-content layout and the logs mapping.
	 *
	 * @return array{log: string, root: string}
	 */
	private function debug_log_path(): array {
		$exec = 'define( "WP_DEBUG", true );';
		$code = 'echo PHP_EOL, wp_json_encode( [ "log" => (string) ini_get( "error_log" ), "root" => ABSPATH ] );';

		exec( 'wp --exec=' . escapeshellarg( $exec ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, "wp eval failed:\n" . implode( "\n", $output ) );

		$site = json_decode( (string) end( $output ), true );
		$this->assertIsArray( $site, "Unexpected wp eval output:\n" . implode( "\n", $output ) );

		return $site;
	}

	/**
	 * Takes the canary back out of the log unless something was logged after it.
	 */
	private function remove_canary( string $log, ?int $size, string $canary ): void {
		clearstatcache( true, $log );

		if ( null === $size ) {
			if ( file_get_contents( $log ) === $canary ) {
				unlink( $log );
			}
			return;
		}

		if ( filesize( $log ) === $size + strlen( $canary ) ) {
			$handle = fopen( $log, 'r+' );
			ftruncate( $handle, $size );
			fclose( $handle );
		}
	}
}
