<?php
/**
 * Tests that the wp-env debug log directory is not served over HTTP.
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
 * Anonymous requests for files in logs/.
 */
final class DebugLogAccessTest extends TestCase {

	/**
	 * The tests site's web server, by its wp-env service name.
	 */
	private const SERVER = 'tests-wordpress';

	private string $log_file = '';

	protected function setUp(): void {
		if ( gethostbyname( self::SERVER ) === self::SERVER ) {
			$this->markTestSkipped( 'Needs the wp-env tests site: run npm run test:integration.' );
		}

		// Same host directory as wp-content/logs, where WP_DEBUG_LOG writes.
		$this->log_file = dirname( __DIR__, 2 ) . '/logs/bricks-mcp-access-test.log';
		file_put_contents( $this->log_file, "diagnostic canary\n" );
	}

	protected function tearDown(): void {
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function log_urls(): array {
		return [
			'wp-content/logs mapping'     => [ '/wp-content/logs/bricks-mcp-access-test.log' ],
			'plugin checkout logs folder' => [ '/wp-content/plugins/bricks-mcp/logs/bricks-mcp-access-test.log' ],
		];
	}

	/**
	 * @dataProvider log_urls
	 */
	public function test_visitor_cannot_read_log_file( string $path ): void {
		[ $status, $body ] = $this->get( $path );

		$this->assertSame( 403, $status );
		$this->assertStringNotContainsString( 'diagnostic canary', $body );
	}

	/**
	 * @return array{int, string}
	 */
	private function get( string $path ): array {
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => 10 ] ] );
		$body    = file_get_contents( 'http://' . self::SERVER . $path, false, $context );
		$this->assertNotFalse( $body, 'The tests site did not answer.' );

		preg_match( '#^HTTP/\S+ (\d{3})#', $http_response_header[0], $matches );

		return [ (int) $matches[1], $body ];
	}
}
