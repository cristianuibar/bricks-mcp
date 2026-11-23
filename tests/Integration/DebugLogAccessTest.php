<?php
/**
 * Tests that the wp-env debug log is not served over HTTP.
 *
 * Runs in wp-env (`npm run test:integration`) against both sites' Apache.
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

	/**
	 * Logs a canary through the requesting site's WordPress and reports where it went.
	 *
	 * Both sites serve the plugin checkout, so each one runs it with its own wp-config.php.
	 * It deletes itself before doing anything, so it runs once, and it only appends
	 * to the log: the canary line stays there like any other notice. A non-empty LOG_PATH
	 * replaces the site's error_log the way wp_debug_mode() applies a WP_DEBUG_LOG path.
	 */
	private const PROBE = <<<'PHP'
<?php
unlink( __FILE__ );
require dirname( __DIR__, 3 ) . '/wp-load.php';

if ( '' !== LOG_PATH ) {
	ini_set( 'error_log', LOG_PATH );
}

// A relative error_log resolves against the working directory: the web root for a root request.
chdir( ABSPATH );
$log     = (string) ini_get( 'error_log' );
$written = error_log( CANARY );
clearstatcache();
$log = (string) realpath( $log ) ?: $log;

echo PHP_EOL, wp_json_encode(
	[
		'log'     => $log,
		'root'    => realpath( ABSPATH ) . '/',
		'file'    => is_file( $log ),
		'written' => $written,
	]
);
PHP;

	protected function setUp(): void {
		if ( gethostbyname( self::SERVER ) === self::SERVER ) {
			$this->markTestSkipped( 'Needs the wp-env tests site: run npm run test:integration.' );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function sites(): array {
		return [
			'development site' => [ 'wordpress' ],
			'tests site'       => [ self::SERVER ],
		];
	}

	/**
	 * The file a site's WordPress logs to under its WP_DEBUG_LOG, at its web root URL on that site.
	 *
	 * @dataProvider sites
	 */
	public function test_visitor_cannot_read_configured_debug_log( string $server ): void {
		[ $site, $canary ] = $this->probe( $server );

		if ( ! $site['file'] || ! str_starts_with( $site['log'], $site['root'] ) ) {
			$this->markTestSkipped( "{$server} logs to {$site['log']}, not a file under its web root {$site['root']}." );
		}

		$this->assertTrue( $site['written'], "WordPress on {$server} could not write {$site['log']}." );
		$this->assert_denied( $server, '/' . substr( $site['log'], strlen( $site['root'] ) ), $canary );
	}

	/**
	 * A relative WP_DEBUG_LOG is the file under the web root that root requests write, not a skip.
	 */
	public function test_visitor_cannot_read_relative_debug_log(): void {
		[ $site, $canary ] = $this->probe( self::SERVER, 'wp-content/logs/debug.log' );

		$this->assertSame( $site['root'] . 'wp-content/logs/debug.log', $site['log'] );
		$this->assertTrue( $site['written'], 'WordPress on ' . self::SERVER . " could not write {$site['log']}." );
		$this->assert_denied( self::SERVER, '/wp-content/logs/debug.log', $canary );
	}

	/**
	 * The plugin mount "." serves the checkout's logs/ folder whatever WP_DEBUG_LOG says.
	 */
	public function test_visitor_cannot_read_plugin_checkout_logs_folder(): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$file   = dirname( __DIR__, 2 ) . '/logs/bricks-mcp-access-test.log';
		file_put_contents( $file, $canary . "\n" );

		try {
			$this->assert_denied( self::SERVER, '/wp-content/plugins/bricks-mcp/logs/bricks-mcp-access-test.log', $canary );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Runs the probe on a site.
	 *
	 * @return array{array{log: string, root: string, file: bool, written: bool}, string} What it reports, and its canary.
	 */
	private function probe( string $server, string $log_path = '' ): array {
		$canary = 'DebugLogAccessTest diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$probe  = 'bricks-mcp-log-probe-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$file   = dirname( __DIR__, 2 ) . '/' . $probe;
		$code   = str_replace( [ 'CANARY', 'LOG_PATH' ], [ var_export( $canary, true ), var_export( $log_path, true ) ], self::PROBE );
		file_put_contents( $file, $code );

		try {
			[ $status, $body ] = $this->get( $server, '/wp-content/plugins/bricks-mcp/' . $probe );
			$this->assertSame( 200, $status, "The probe failed on {$server}:\n{$body}" );

			$lines = explode( "\n", trim( $body ) );
			$site  = json_decode( (string) end( $lines ), true );
			$this->assertIsArray( $site, "Unexpected probe output on {$server}:\n{$body}" );

			return [ $site, $canary ];
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function assert_denied( string $server, string $path, string $canary ): void {
		[ $status, $body ] = $this->get( $server, $path );

		$this->assertSame( 403, $status, $server . $path );
		$this->assertStringNotContainsString( $canary, $body, $server . $path );
	}

	/**
	 * @return array{int, string}
	 */
	private function get( string $server, string $path ): array {
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 10 ] ] );
		$body    = file_get_contents( 'http://' . $server . $path, false, $context );
		$this->assertNotFalse( $body, "{$server} did not answer." );

		preg_match( '#^HTTP/\S+ (\d{3})#', $http_response_header[0], $matches );

		return [ (int) $matches[1], $body ];
	}
}
