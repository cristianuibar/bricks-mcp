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

use PHPUnit\Framework\AssertionFailedError;
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
	 * It deletes itself before doing anything, so it runs once, and stops with a 500 when
	 * it cannot, so a probe left behind logs and reports nothing. It only appends to the
	 * log: the canary line stays there like any other notice. A non-empty LOG_PATH
	 * replaces the site's error_log the way wp_debug_mode() applies a WP_DEBUG_LOG path.
	 */
	private const PROBE = <<<'PHP'
<?php
if ( ! @unlink( __FILE__ ) ) {
	http_response_code( 500 );
	exit( 'The probe could not delete itself.' );
}
require $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';

if ( '' !== LOG_PATH ) {
	ini_set( 'error_log', LOG_PATH );
}

// A relative error_log resolves against the working directory: the web root for a root request.
chdir( ABSPATH );
$log     = (string) ini_get( 'error_log' );
$written = error_log( CANARY );
clearstatcache();

// Resolve the nearest existing folder, so a log that could not be created still resolves. '' is stderr.
if ( '' !== $log && 'syslog' !== $log ) {
	$path = str_starts_with( $log, '/' ) ? $log : getcwd() . '/' . $log;
	$rest = '';
	while ( false === realpath( $path ) && dirname( $path ) !== $path ) {
		$rest = '/' . basename( $path ) . $rest;
		$path = dirname( $path );
	}
	$log = rtrim( (string) realpath( $path ), '/' ) . $rest;
}

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

		$this->assert_configured_log_denied( $server, $site, $canary );
	}

	/**
	 * A relative WP_DEBUG_LOG is the file under the web root that root requests write, not a skip.
	 */
	public function test_visitor_cannot_read_relative_debug_log(): void {
		[ $site, $canary ] = $this->probe( self::SERVER, 'wp-content/logs/debug.log' );

		$this->assertSame( $site['root'] . 'wp-content/logs/debug.log', $site['log'] );
		$this->assert_logged( self::SERVER, $site );
		$this->assert_denied( self::SERVER, '/wp-content/logs/debug.log', $canary );
	}

	/**
	 * A probe the web server cannot delete stops on every request: it logs no canary and reports no paths.
	 */
	public function test_probe_stops_when_it_cannot_delete_itself(): void {
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		mkdir( $dir );
		mkdir( "{$dir}/logs" );
		[ $probe ] = $this->write_probe( $folder, "wp-content/plugins/bricks-mcp/{$folder}/logs/debug.log" );
		chmod( $dir, 0555 );

		try {
			foreach ( [ 1, 2 ] as $request ) {
				[ $status, $body ] = $this->get( self::SERVER, '/wp-content/plugins/bricks-mcp/' . $probe );
				$this->assertSame( [ 500, 'The probe could not delete itself.' ], [ $status, $body ], "Request {$request}." );
			}
			$this->assertFileDoesNotExist( "{$dir}/logs/debug.log" );
		} finally {
			chmod( $dir, 0755 );
			foreach ( [ "{$dir}/logs/debug.log", dirname( __DIR__, 2 ) . '/' . $probe ] as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( "{$dir}/logs" );
			rmdir( $dir );
		}
	}

	/**
	 * A configured log under the web root that WordPress cannot create fails the configured check, not a skip.
	 *
	 * The skip is an AssertionFailedError too, so only the creation failure's message passes.
	 */
	public function test_uncreatable_configured_debug_log_fails_the_check(): void {
		$path              = 'wp-content/logs/bricks-mcp-missing-' . bin2hex( random_bytes( 8 ) ) . '/debug.log';
		[ $site, $canary ] = $this->probe( self::SERVER, $path );

		try {
			$this->assert_configured_log_denied( self::SERVER, $site, $canary );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( "could not create {$site['root']}{$path}.", $e->getMessage() );
			return;
		}

		$this->fail( "The configured check passed for {$site['log']}, which was never created." );
	}

	/**
	 * The mapping "wp-content/logs" and the plugin mount "." serve the checkout's logs/ folder whatever WP_DEBUG_LOG says.
	 *
	 * The tests site's configured check skips while wp-env keeps its logging off, but the
	 * development site's notices still land in that shared folder, so both URLs are checked here.
	 *
	 * @dataProvider logs_folder_urls
	 */
	public function test_visitor_cannot_read_shared_logs_folder( string $path ): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$file   = dirname( __DIR__, 2 ) . '/logs/bricks-mcp-access-test.log';
		file_put_contents( $file, $canary . "\n" );

		try {
			$this->assert_denied( self::SERVER, $path . '/bricks-mcp-access-test.log', $canary );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function logs_folder_urls(): array {
		return [
			'wp-content/logs mapping'     => [ '/wp-content/logs' ],
			'plugin checkout logs folder' => [ '/wp-content/plugins/bricks-mcp/logs' ],
		];
	}

	/**
	 * A log name with URL syntax in it is requested as that file, not the part before the syntax.
	 *
	 * Only the file is denied, so a request that loses the rest of its name finds nothing (404).
	 *
	 * @dataProvider log_names
	 */
	public function test_visitor_cannot_read_log_named_with_url_syntax( string $name ): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		mkdir( $dir );
		file_put_contents( "{$dir}/.htaccess", "<Files \"{$name}\">\nRequire all denied\n</Files>\n" );
		file_put_contents( "{$dir}/{$name}", $canary . "\n" );

		try {
			$this->assert_denied( self::SERVER, "/wp-content/plugins/bricks-mcp/{$folder}/{$name}", $canary );
		} finally {
			unlink( "{$dir}/{$name}" );
			unlink( "{$dir}/.htaccess" );
			rmdir( $dir );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function log_names(): array {
		return [
			'fragment' => [ 'debug#local.log' ],
			'query'    => [ 'debug?local.log' ],
			'percent'  => [ 'debug%41.log' ],
			'space'    => [ 'debug local.log' ],
		];
	}

	/**
	 * A response larger than the memory left fails the check instead of ending the run before cleanup.
	 *
	 * @dataProvider large_responses
	 */
	public function test_large_response_fails_the_check_within_memory( string $htaccess, string $failure ): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		mkdir( $dir );
		file_put_contents( "{$dir}/.htaccess", str_replace( 'FOLDER', $folder, $htaccess ) );
		$this->write_large_file( "{$dir}/debug.log", $canary );
		$this->write_large_file( "{$dir}/denied.txt", $canary );
		$limit = ini_get( 'memory_limit' );

		try {
			$this->assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 16 * 1024 * 1024 ) ) );
			$this->assert_denied( self::SERVER, "/wp-content/plugins/bricks-mcp/{$folder}/debug.log", $canary );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( $failure, $e->getMessage() );
			return;
		} finally {
			ini_set( 'memory_limit', $limit );
			unlink( "{$dir}/debug.log" );
			unlink( "{$dir}/denied.txt" );
			unlink( "{$dir}/.htaccess" );
			rmdir( $dir );
		}

		$this->fail( 'The check passed for a log served with its canary.' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function large_responses(): array {
		return [
			'served log'  => [ "Require all granted\n", 'Failed asserting that 200 is identical to 403' ],
			'denial page' => [
				"<Files \"debug.log\">\nRequire all denied\n</Files>\nErrorDocument 403 /wp-content/plugins/bricks-mcp/FOLDER/denied.txt\n",
				'served the canary',
			],
		];
	}

	/**
	 * Runs the probe on a site.
	 *
	 * @return array{array{log: string, root: string, file: bool, written: bool}, string} What it reports, and its canary.
	 */
	private function probe( string $server, string $log_path = '' ): array {
		[ $probe, $canary ] = $this->write_probe( '', $log_path );
		$file               = dirname( __DIR__, 2 ) . '/' . $probe;

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

	/**
	 * Writes the probe into a folder of the plugin checkout, '' for its root.
	 *
	 * @return array{string, string} Its path in the checkout, and its canary.
	 */
	private function write_probe( string $folder, string $log_path ): array {
		$canary = 'DebugLogAccessTest diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$probe  = ltrim( $folder . '/bricks-mcp-log-probe-' . bin2hex( random_bytes( 8 ) ) . '.php', '/' );
		$code   = str_replace( [ 'CANARY', 'LOG_PATH' ], [ var_export( $canary, true ), var_export( $log_path, true ) ], self::PROBE );
		file_put_contents( dirname( __DIR__, 2 ) . '/' . $probe, $code );

		return [ $probe, $canary ];
	}

	/**
	 * The configured check: a log under the web root is written, created and denied; any other log skips.
	 *
	 * @param array{log: string, root: string, file: bool, written: bool} $site What the probe reports.
	 */
	private function assert_configured_log_denied( string $server, array $site, string $canary ): void {
		if ( ! str_starts_with( $site['log'], $site['root'] ) ) {
			$this->markTestSkipped( "{$server} logs to {$site['log']}, not a file under its web root {$site['root']}." );
		}

		$this->assert_logged( $server, $site );
		$this->assert_denied( $server, '/' . substr( $site['log'], strlen( $site['root'] ) ), $canary );
	}

	/**
	 * The probe's log exists: error_log() still returns true when it cannot create the file.
	 *
	 * @param array{log: string, root: string, file: bool, written: bool} $site What the probe reports.
	 */
	private function assert_logged( string $server, array $site ): void {
		$this->assertTrue( $site['written'], "WordPress on {$server} could not write {$site['log']}." );
		$this->assertTrue( $site['file'], "WordPress on {$server} could not create {$site['log']}." );
	}

	/**
	 * The request is refused and its body, read in chunks so a large log fits in memory, holds no canary.
	 */
	private function assert_denied( string $server, string $path, string $canary ): void {
		[ $status, $stream ] = $this->open( $server, $path );

		try {
			$this->assertSame( 403, $status, $server . $path );

			// Keep the end of each chunk, so a canary split across two chunks is still found.
			$tail = '';
			do {
				$read  = (string) fread( $stream, 65536 );
				$chunk = $tail . $read;
				$found = str_contains( $chunk, $canary );
				$tail  = substr( $chunk, -( strlen( $canary ) - 1 ) );
			} while ( ! $found && '' !== $read );
			$this->assertFalse( $found, "{$server}{$path} served the canary." );
		} finally {
			fclose( $stream );
		}
	}

	/**
	 * Requests a file by its path under the web root and reads the whole body.
	 *
	 * @return array{int, string}
	 */
	private function get( string $server, string $path ): array {
		[ $status, $stream ] = $this->open( $server, $path );

		try {
			return [ $status, (string) stream_get_contents( $stream ) ];
		} finally {
			fclose( $stream );
		}
	}

	/**
	 * Requests a file by its path under the web root, each segment URL-encoded, without reading its body.
	 *
	 * @return array{int, resource}
	 */
	private function open( string $server, string $path ): array {
		$url     = 'http://' . $server . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 10 ] ] );
		$stream  = fopen( $url, 'rb', false, $context );
		$this->assertNotFalse( $stream, "{$server} did not answer." );

		preg_match( '#^HTTP/\S+ (\d{3})#', stream_get_meta_data( $stream )['wrapper_data'][0], $matches );

		return [ (int) $matches[1], $stream ];
	}

	/**
	 * Writes a 64 MB file that ends with the canary, without holding it in memory.
	 */
	private function write_large_file( string $file, string $canary ): void {
		$handle = fopen( $file, 'wb' );
		$line   = str_repeat( 'x', 1023 ) . "\n";
		for ( $i = 0; $i < 64 * 1024; $i++ ) {
			fwrite( $handle, $line );
		}
		fwrite( $handle, $canary . "\n" );
		fclose( $handle );
	}
}
