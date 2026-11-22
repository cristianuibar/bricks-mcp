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
	 * to the log: the canary line stays there like any other notice.
	 */
	private const PROBE = <<<'PHP'
<?php
unlink( __FILE__ );
require dirname( __DIR__, 3 ) . '/wp-load.php';

$log     = (string) ini_get( 'error_log' );
$written = error_log( CANARY );
clearstatcache( true, $log );

echo PHP_EOL, wp_json_encode(
	[
		'log'     => $log,
		'root'    => ABSPATH,
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
		$canary = 'DebugLogAccessTest diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$probe  = 'bricks-mcp-log-probe-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$file   = dirname( __DIR__, 2 ) . '/' . $probe;
		$url    = '/wp-content/plugins/bricks-mcp/' . $probe;
		file_put_contents( $file, str_replace( 'CANARY', var_export( $canary, true ), self::PROBE ) );

		try {
			[ $status, $body ] = $this->get( $server, $url );
			$this->assertSame( 200, $status, "The probe failed on {$server}:\n{$body}" );

			$lines = explode( "\n", trim( $body ) );
			$site  = json_decode( (string) end( $lines ), true );
			$this->assertIsArray( $site, "Unexpected probe output on {$server}:\n{$body}" );

			if ( ! $site['file'] || ! str_starts_with( $site['log'], $site['root'] ) ) {
				$this->markTestSkipped( "{$server} logs to {$site['log']}, not a file under its web root {$site['root']}." );
			}

			$this->assertTrue( $site['written'], "WordPress on {$server} could not write {$site['log']}." );
			$this->assert_denied( $server, '/' . substr( $site['log'], strlen( $site['root'] ) ), $canary );
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
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
			$this->assert_denied( self::SERVER, '/wp-content/plugins/bricks-mcp/logs/bricks-mcp-access-test.log', $canary );
		} finally {
			unlink( $file );
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
