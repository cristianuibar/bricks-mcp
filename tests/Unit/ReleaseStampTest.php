<?php
/**
 * Tests for the release workflow's tag validation and version stamping.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs .github/scripts/stamp-release-version.sh on a copy of the plugin files.
 */
final class ReleaseStampTest extends TestCase {

	/**
	 * Temporary copy of the repository files the script touches.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		$root      = dirname( __DIR__, 2 );
		$this->dir = sys_get_temp_dir() . '/bricks-mcp-stamp-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir . '/.github/scripts', 0777, true );
		copy( $root . '/.github/scripts/stamp-release-version.sh', $this->dir . '/.github/scripts/stamp-release-version.sh' );
		copy( $root . '/bricks-mcp.php', $this->dir . '/bricks-mcp.php' );
		copy( $root . '/readme.txt', $this->dir . '/readme.txt' );
	}

	protected function tearDown(): void {
		foreach ( [ '/.github/scripts/stamp-release-version.sh', '/bricks-mcp.php', '/readme.txt', '/bricks-mcp.php.bak', '/readme.txt.bak' ] as $file ) {
			if ( file_exists( $this->dir . $file ) ) {
				unlink( $this->dir . $file );
			}
		}
		rmdir( $this->dir . '/.github/scripts' );
		rmdir( $this->dir . '/.github' );
		rmdir( $this->dir );
	}

	/**
	 * Runs the stamp script with one tag argument.
	 *
	 * @param string $tag Tag name as GITHUB_REF_NAME would hold it.
	 * @return array{0:int,1:string} Exit status and combined output.
	 */
	private function stamp( string $tag ): array {
		$output = [];
		$status = 1;
		exec( 'bash ' . escapeshellarg( $this->dir . '/.github/scripts/stamp-release-version.sh' ) . ' ' . escapeshellarg( $tag ) . ' 2>&1', $output, $status );
		return [ $status, implode( "\n", $output ) ];
	}

	public function test_valid_tags_stamp_header_constant_and_stable_tag(): void {
		foreach ( [ 'v2.1.0', 'v10.0.12-rc.1', 'v3.0.0-beta2' ] as $tag ) {
			[ $status, $output ] = $this->stamp( $tag );
			$version             = substr( $tag, 1 );

			$this->assertSame( 0, $status, $tag . ': ' . $output );
			$plugin = (string) file_get_contents( $this->dir . '/bricks-mcp.php' );
			$this->assertStringContainsString( "\n * Version:           {$version}\n", $plugin, $tag );
			$this->assertStringContainsString( "\ndefine( 'BRICKS_MCP_VERSION', '{$version}' );\n", $plugin, $tag );
			$this->assertMatchesRegularExpression( '/^Stable tag: ' . preg_quote( $version, '/' ) . '$/m', (string) file_get_contents( $this->dir . '/readme.txt' ), $tag );
		}

		$lint = [];
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $this->dir . '/bricks-mcp.php' ) . ' 2>&1', $lint, $status );
		$this->assertSame( 0, $status, implode( "\n", $lint ) );
	}

	public function test_malformed_tags_are_refused_before_any_file_changes(): void {
		$plugin = (string) file_get_contents( $this->dir . '/bricks-mcp.php' );
		$readme = (string) file_get_contents( $this->dir . '/readme.txt' );

		$tags = [
			'',
			'2.1.0',
			'v2.1',
			'v2.1.0.1',
			'v2.1.0-',
			'v2.1.0+build',
			"v2.1.0');exit;//",
			'v2.1.0-x/e',
			'v2.1.0-a&b',
			'v2.1.0-$(id)',
			"v2.1.0\nv2.1.1",
			'release-2.1.0',
			'v٢.1.0',
		];
		foreach ( $tags as $tag ) {
			[ $status, $output ] = $this->stamp( $tag );

			$this->assertSame( 1, $status, var_export( $tag, true ) . ': ' . $output );
			$this->assertStringContainsString( 'Refusing release tag', $output, var_export( $tag, true ) );
			$this->assertSame( $plugin, file_get_contents( $this->dir . '/bricks-mcp.php' ), var_export( $tag, true ) );
			$this->assertSame( $readme, file_get_contents( $this->dir . '/readme.txt' ), var_export( $tag, true ) );
		}
	}

	public function test_stamping_fails_when_a_stamp_target_is_missing(): void {
		$plugin = (string) file_get_contents( $this->dir . '/bricks-mcp.php' );
		file_put_contents( $this->dir . '/bricks-mcp.php', str_replace( "define( 'BRICKS_MCP_VERSION',", "define('BRICKS_MCP_VERSION',", $plugin ) );

		[ $status, $output ] = $this->stamp( 'v2.1.0' );

		$this->assertSame( 1, $status, $output );
		$this->assertStringContainsString( "Stamping failed: bricks-mcp.php must contain exactly one line 'define( 'BRICKS_MCP_VERSION', '2.1.0' );'", $output );
	}
}
