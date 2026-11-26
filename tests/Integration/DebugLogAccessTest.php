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
use PHPUnit\Framework\SkippedTest;
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
	 * Seconds a request may wait for its response or for the next part of its body.
	 */
	private const TIMEOUT = 10;

	/**
	 * Seconds the stalled response test waits for the next part of its body, so its run does not wait for TIMEOUT.
	 */
	private const STALL_TIMEOUT = 1;

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

// Drop . and .. by name without following symlinks: a URL maps to the path, not to where a link points.
$normalize = static function ( string $path ): string {
	$parts = [];
	foreach ( explode( '/', $path ) as $part ) {
		if ( '..' === $part ) {
			array_pop( $parts );
		} elseif ( '' !== $part && '.' !== $part ) {
			$parts[] = $part;
		}
	}
	return '/' . implode( '/', $parts );
};

// '' is stderr.
$root   = rtrim( $normalize( ABSPATH ), '/' ) . '/';
$target = $log;
$opened = $log;
if ( '' !== $log && 'syslog' !== $log ) {
	// The OS opens the path as given: a missing folder before .. fails the write even when the normalized path exists.
	$opened = str_starts_with( $log, '/' ) ? $log : getcwd() . '/' . $log;
	$log    = $normalize( $opened );
	// The file a symlinked log writes to has a URL of its own, at its path under the real web root.
	$real      = realpath( $opened );
	$real_root = realpath( ABSPATH ) . '/';
	$target    = false === $real ? $log : ( str_starts_with( $real, $real_root ) ? $root . substr( $real, strlen( $real_root ) ) : $real );
}

echo PHP_EOL, wp_json_encode(
	[
		'log'     => $log,
		'target'  => $target,
		'root'    => $root,
		'file'    => is_file( $opened ),
		'written' => $written,
	]
);
PHP;

	protected function setUp(): void {
		if ( ! in_array( $this->name(), [ 'test_fixtures_left_in_the_checkout_are_ignored_by_git', 'test_run_during_another_leaves_the_earlier_target_alone', 'test_cleanup_that_cannot_put_the_log_back_reports_it', 'test_planted_file_cut_short_leaves_the_log_to_go_back', 'test_directory_the_site_cannot_move_is_checked_in_place', 'test_lost_planting_response_still_puts_the_log_back' ], true ) && gethostbyname( self::SERVER ) === self::SERVER ) {
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
	 * A log under the web root that is a symlink to a file outside it is checked at its URL, not skipped.
	 */
	public function test_visitor_cannot_read_debug_log_symlinked_outside_the_web_root(): void {
		$this->assert_symlinked_outside_log_denied();
	}

	/**
	 * A directory left at the target an earlier run used does not stop the next run's HTTP check.
	 *
	 * The case once logged to this one fixed /tmp file, so a directory there failed the probe's
	 * write before the log was requested. The file such a run logged to is moved aside for the
	 * run and put back after it, so a reused tests site runs the check instead of skipping it.
	 *
	 * Every run starts from that file: whatever the site holds at the path is kept aside and a
	 * regular file takes its place, so a fresh site does not pass by making the directory alone.
	 * A directory the site cannot move aside, such as a root-owned one in the sticky /tmp, is
	 * checked in place, as it was before that file was planted.
	 */
	public function test_directory_at_an_earlier_target_does_not_stop_the_symlink_check(): void {
		$path     = '/tmp/bricks-mcp-access-test.log';
		$steps    = self::earlier_target_steps( $path );
		$planting = null;
		$swapping = '';

		try {
			$planting    = $this->run_on_server( self::SERVER, $steps['plant'] );
			[ $planted ] = explode( ' ', $planting );
			$this->assertNotSame( 'busy', $planted, "Another run holds {$path}.lock on the tests site, or one stopped before its cleanup: with no run going, put back what it kept under {$path}-<hex>, then remove the lock folder." );
			$this->assertNotSame( 'blocked', $planted, "Could not put a regular file at {$path} on the tests site." );
			$swapping       = $this->run_on_server( self::SERVER, $steps['swap'] );
			[ $dir, $file ] = explode( ' ', $swapping ) + [ '', '' ];
			$this->assertNotSame( 'blocked', $dir, "Could not make a directory at {$path} on the tests site." );
			$this->assertSame( 'planted' === $planted ? 'moved' : 'kept', $file, "The regular file at {$path} was not moved aside." );
			$this->assert_symlinked_outside_log_denied();
		} catch ( SkippedTest $e ) {
			// A skip ends the run as OK without the HTTP check, the way the case once skipped on this file.
			$this->fail( "The check skipped on the regular file at {$path} instead of running: {$e->getMessage()}" );
		} finally {
			$restored = self::undo_earlier_target( $steps, $planting, $swapping, fn ( string $code ): string => $this->run_on_server( self::SERVER, $code ) );
		}

		$this->assertSame( 'back', $restored, "The regular file at {$path} did not come back as it was, or what the tests site held there is still under {$path}-<hex> with {$path}.lock." );
	}

	/**
	 * A run that starts while another uses the earlier target is refused before it touches the path.
	 *
	 * Each run keeps aside what it finds there and puts it back after, so two runs at once kept
	 * each other's files: in some orders both cleanups reported success with the site's log left
	 * under a -<hex> name. The second run now changes nothing and has nothing to undo, and the
	 * first one's cleanup puts the log back and leaves nothing else behind.
	 *
	 * Needs no web server: it runs each step's PHP here, on a path of its own.
	 */
	public function test_run_during_another_leaves_the_earlier_target_alone(): void {
		$folder = sys_get_temp_dir() . '/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$path   = "{$folder}/bricks-mcp-access-test.log";
		$log    = "The site's own log\n";
		mkdir( $folder );
		file_put_contents( $path, $log );
		$first  = self::earlier_target_steps( $path );
		$second = self::earlier_target_steps( $path );

		try {
			$first_planting  = self::run_here( $first['plant'] );
			$first_swapping  = self::run_here( $first['swap'] );
			$second_planting = self::run_here( $second['plant'] );
			$this->assertSame( [ 'planted held', 'made moved', 'busy' ], [ $first_planting, $first_swapping, $second_planting ] );
			$this->assertSame( '', $second['restore']( $second_planting, '' ), 'The refused run has something to undo.' );
			$first_restored = self::run_here( $first['restore']( $first_planting, $first_swapping ) );

			clearstatcache();
			$this->assertSame( 'back', $first_restored );
			$this->assertSame( [ $path ], glob( "{$folder}/*" ), "The runs left more than the log in {$folder}." );
			$this->assertSame( $log, file_get_contents( $path ) );
		} finally {
			foreach ( glob( "{$folder}/*" ) as $left ) {
				is_dir( $left ) ? rmdir( $left ) : unlink( $left );
			}
			rmdir( $folder );
		}
	}

	/**
	 * A cleanup that cannot put the site's log back reports it, and keeps the lock so the next run stops too.
	 *
	 * A probe logging to the path can recreate it right after the cleanup removes its planted
	 * file, so the log kept aside cannot go back there. The cleanup leaves the probe's file and
	 * the kept log alone, prints lost instead of back, and leaves the lock for whoever puts the
	 * log back by hand.
	 *
	 * Needs no web server: it runs each step's PHP here, on a path of its own.
	 */
	public function test_cleanup_that_cannot_put_the_log_back_reports_it(): void {
		$folder = sys_get_temp_dir() . '/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$path   = "{$folder}/bricks-mcp-access-test.log";
		$log    = "The site's own log\n";
		$probe  = "A probe's line\n";
		mkdir( $folder );
		file_put_contents( $path, $log );
		$steps = self::earlier_target_steps( $path );
		// The probe writes to the path as soon as the planted file is gone from it.
		$recreate = 'namespace BricksMCP\Tests\Recreate;' . "\nfunction unlink( string \$file ): bool {\n\t\$gone = \\unlink( \$file );\n\t"
			. var_export( $path, true ) . ' === $file && file_put_contents( $file, ' . var_export( $probe, true ) . " );\n\treturn \$gone;\n}\n";

		try {
			$planting = self::run_here( $steps['plant'] );
			$swapping = self::run_here( $steps['swap'] );
			$this->assertSame( [ 'planted held', 'made moved' ], [ $planting, $swapping ] );
			$restored = self::run_here( $recreate . $steps['restore']( $planting, $swapping ) );

			clearstatcache();
			$this->assertSame( 'lost', $restored, 'The cleanup reported the log back while it was still kept aside.' );
			$this->assertSame( $probe, file_get_contents( $path ) );
			$kept = array_values( array_filter( glob( "{$path}-*" ), static fn ( string $file ): bool => file_get_contents( $file ) === $log ) );
			$this->assertCount( 1, $kept, "The site's log is not kept aside next to {$path}." );
			$this->assertDirectoryExists( "{$path}.lock", 'The cleanup removed the lock with the log still kept aside.' );
		} finally {
			foreach ( glob( "{$path}.lock/*" ) as $left ) {
				unlink( $left );
			}
			foreach ( glob( "{$folder}/*" ) as $left ) {
				is_dir( $left ) ? rmdir( $left ) : unlink( $left );
			}
			rmdir( $folder );
		}
	}

	/**
	 * A planted file cut short by a full disk does not stop the cleanup from putting the site's log back.
	 *
	 * plant made its file at the path and wrote only part of it, so the cleanup found neither its
	 * own file nor a free path: the site's log stayed under a -<hex> name with the lock kept. plant
	 * now removes the part it wrote and reports blocked, and the cleanup puts the log back.
	 *
	 * Needs no web server: it runs each step's PHP here, on a path of its own.
	 */
	public function test_planted_file_cut_short_leaves_the_log_to_go_back(): void {
		$folder = sys_get_temp_dir() . '/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$path   = "{$folder}/bricks-mcp-access-test.log";
		$log    = "The site's own log\n";
		mkdir( $folder );
		file_put_contents( $path, $log );
		$steps = self::earlier_target_steps( $path );
		// The disk fills after the first few bytes, as a full /tmp does.
		$full = 'namespace BricksMCP\Tests\Full;' . "\nfunction fwrite( \$stream, string \$data ): int|false {\n\treturn \\fwrite( \$stream, substr( \$data, 0, 5 ) );\n}\n";

		try {
			$planting = self::run_here( $full . $steps['plant'] );
			$this->assertSame( 'blocked held', $planting );
			self::run_here( $steps['restore']( $planting, '' ) );

			clearstatcache();
			$this->assertSame( [ $path ], glob( "{$folder}/*" ), "The run left more than the log in {$folder}." );
			$this->assertSame( $log, file_get_contents( $path ) );
		} finally {
			foreach ( glob( "{$folder}/*" ) as $left ) {
				is_dir( $left ) ? rmdir( $left ) : unlink( $left );
			}
			rmdir( $folder );
		}
	}

	/**
	 * A run whose planting response is lost still puts the site's log back and frees the lock.
	 *
	 * Apache could finish plant while the caller threw before its cleanup, as on a dropped
	 * response, so the log stayed under a -<hex> name with the lock kept and every later run
	 * reported busy. The cleanup now asks the site what plant printed. A run refused while
	 * another holds the lock finds nothing of its own there, so it leaves that run alone.
	 *
	 * Needs no web server: it runs each step's PHP here, on a path of its own.
	 */
	public function test_lost_planting_response_still_puts_the_log_back(): void {
		$folder = sys_get_temp_dir() . '/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$path   = "{$folder}/bricks-mcp-access-test.log";
		$log    = "The site's own log\n";
		mkdir( $folder );
		file_put_contents( $path, $log );
		$first  = self::earlier_target_steps( $path );
		$second = self::earlier_target_steps( $path );
		$run    = static fn ( string $code ): string => self::run_here( $code );

		try {
			// Both plant steps run to the end and neither caller gets what they printed.
			self::run_here( $first['plant'] );
			self::run_here( $second['plant'] );
			$this->assertSame( '', self::undo_earlier_target( $second, null, '', $run ), 'The refused run had something to undo.' );
			$this->assertDirectoryExists( "{$path}.lock", 'The refused run freed the lock the other run holds.' );
			$this->assertSame( 'back', self::undo_earlier_target( $first, null, '', $run ) );

			clearstatcache();
			$this->assertSame( [ $path ], glob( "{$folder}/*" ), "The runs left more than the log in {$folder}." );
			$this->assertSame( $log, file_get_contents( $path ) );
		} finally {
			foreach ( glob( "{$path}.lock/*" ) as $left ) {
				unlink( $left );
			}
			foreach ( glob( "{$folder}/*" ) as $left ) {
				is_dir( $left ) ? rmdir( $left ) : unlink( $left );
			}
			rmdir( $folder );
		}
	}

	/**
	 * A directory at the earlier target that the site cannot move aside is checked in place, as before the planted file.
	 *
	 * A root-owned directory in the sticky /tmp cannot be renamed by the web server's user, so no
	 * regular file can take its place. plant reports it present instead of blocked, swap keeps it,
	 * and the cleanup leaves it and what it holds as they were and removes the lock.
	 *
	 * Needs no web server: it runs each step's PHP here, on a path of its own.
	 */
	public function test_directory_the_site_cannot_move_is_checked_in_place(): void {
		$folder = sys_get_temp_dir() . '/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$path   = "{$folder}/bricks-mcp-access-test.log";
		$log    = "The site's own log\n";
		mkdir( $folder );
		mkdir( $path );
		file_put_contents( "{$path}/debug.log", $log );
		$steps = self::earlier_target_steps( $path );
		// The sticky /tmp refuses to move another user's entry out of the path.
		$sticky = 'namespace BricksMCP\Tests\Sticky;' . "\nfunction rename( string \$from, string \$to ): bool {\n\treturn "
			. var_export( $path, true ) . " !== \$from && \\rename( \$from, \$to );\n}\n";

		try {
			$planting = self::run_here( $sticky . $steps['plant'] );
			$swapping = self::run_here( $sticky . $steps['swap'] );
			$this->assertSame( [ 'present none', 'present kept' ], [ $planting, $swapping ] );
			$restored = self::run_here( $sticky . $steps['restore']( $planting, $swapping ) );

			clearstatcache();
			$this->assertSame( 'back', $restored );
			$this->assertSame( [ $path ], glob( "{$folder}/*" ), "The run left more than the directory in {$folder}." );
			$this->assertSame( [ "{$path}/debug.log" ], glob( "{$path}/*" ) );
			$this->assertSame( $log, file_get_contents( "{$path}/debug.log" ) );
		} finally {
			foreach ( glob( "{$path}/*" ) as $left ) {
				unlink( $left );
			}
			foreach ( glob( "{$folder}/*" ) as $left ) {
				is_dir( $left ) ? rmdir( $left ) : unlink( $left );
			}
			rmdir( $folder );
		}
	}

	/**
	 * A denied log that is a symlink to a served file under the web root fails the configured check at the file's URL.
	 *
	 * The link sits in the denied logs folder and points to a fresh folder of the checkout that
	 * Apache serves, so only the check of the target's URL can catch its 200.
	 */
	public function test_denied_debug_log_symlinked_to_a_served_file_fails_the_check(): void {
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		$name   = $folder . '.log';
		$link   = dirname( __DIR__, 2 ) . '/logs/' . $name;
		mkdir( $dir );

		try {
			$this->create_symlink( "{$dir}/debug.log", $link );
			[ $site, $canary ] = $this->probe( self::SERVER, "wp-content/logs/{$name}" );
			$this->assertSame( "{$site['root']}wp-content/plugins/bricks-mcp/{$folder}/debug.log", $site['target'] );

			try {
				$this->assert_configured_log_denied( self::SERVER, $site, $canary );
			} catch ( AssertionFailedError $e ) {
				$this->assertStringContainsString( "/wp-content/plugins/bricks-mcp/{$folder}/debug.log\nFailed asserting that 200 is identical to 403", $e->getMessage() );
				return;
			}
		} finally {
			if ( is_link( $link ) || file_exists( $link ) ) {
				unlink( $link );
			}
			if ( file_exists( "{$dir}/debug.log" ) ) {
				unlink( "{$dir}/debug.log" );
			}
			rmdir( $dir );
		}

		$this->fail( "The configured check passed for {$site['log']}, which writes to the served {$site['target']}." );
	}

	/**
	 * A log path through a symlinked folder and .. fails the configured check at the URL of the file PHP writes.
	 *
	 * The OS follows the link before .., so the write lands in a served folder of the checkout
	 * while the normalized path names a file in the denied logs folder.
	 */
	public function test_debug_log_through_a_symlinked_folder_and_dot_dot_fails_the_check(): void {
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		$name   = $folder . '.log';
		$link   = dirname( __DIR__, 2 ) . '/logs/' . $folder;
		mkdir( $dir );
		mkdir( "{$dir}/linked" );

		try {
			$this->create_symlink( "{$dir}/linked", $link );
			[ $site, $canary ] = $this->probe( self::SERVER, "wp-content/logs/{$folder}/../{$name}" );
			$this->assertSame( "{$site['root']}wp-content/logs/{$name}", $site['log'] );
			$this->assertSame( "{$site['root']}wp-content/plugins/bricks-mcp/{$folder}/{$name}", $site['target'] );

			try {
				$this->assert_configured_log_denied( self::SERVER, $site, $canary );
			} catch ( AssertionFailedError $e ) {
				$this->assertStringContainsString( "/wp-content/plugins/bricks-mcp/{$folder}/{$name}\nFailed asserting that 200 is identical to 403", $e->getMessage() );
				return;
			}
		} finally {
			if ( is_link( $link ) || file_exists( $link ) ) {
				unlink( $link );
			}
			if ( file_exists( "{$dir}/{$name}" ) ) {
				unlink( "{$dir}/{$name}" );
			}
			rmdir( "{$dir}/linked" );
			rmdir( $dir );
		}

		$this->fail( "The configured check passed for {$site['log']}, which writes to the served {$site['target']}." );
	}

	/**
	 * A symlink the mount rejects fails before the probe runs, instead of letting it log to a regular file at the link's path.
	 *
	 * The regular file already at the link's path makes symlink() fail here, as such a mount would.
	 */
	public function test_symlink_the_mount_rejects_fails(): void {
		$link = dirname( __DIR__, 2 ) . '/logs/bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) ) . '.log';
		file_put_contents( $link, '' );

		try {
			$this->create_symlink( '/tmp/bricks-mcp-access-test.log', $link );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( "Could not create the symlink {$link}", $e->getMessage() );
			return;
		} finally {
			unlink( $link );
		}

		$this->fail( "The symlink check passed for the regular file {$link}." );
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
	 * A configured log whose missing folder before .. names an existing denied log fails the configured check.
	 *
	 * The OS cannot open the path, so error_log() falls back to stderr while the normalized
	 * path is the existing log, which the denial check alone would pass.
	 */
	public function test_debug_log_behind_a_missing_folder_fails_the_check(): void {
		$name = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) ) . '.log';
		$file = dirname( __DIR__, 2 ) . '/logs/' . $name;
		file_put_contents( $file, '' );

		try {
			[ $site, $canary ] = $this->probe( self::SERVER, 'wp-content/logs/bricks-mcp-missing-' . bin2hex( random_bytes( 8 ) ) . "/../{$name}" );
			$this->assertSame( "{$site['root']}wp-content/logs/{$name}", $site['log'] );

			try {
				$this->assert_configured_log_denied( self::SERVER, $site, $canary );
			} catch ( AssertionFailedError $e ) {
				$this->assertStringContainsString( "could not create {$site['log']}.", $e->getMessage() );
				return;
			}
		} finally {
			unlink( $file );
		}

		$this->fail( "The configured check passed for {$site['log']}, which the probe's write never reached." );
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
	public function test_large_response_fails_the_check_within_memory( string $htaccess, string $served, string $failure ): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		$folder = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) );
		$dir    = dirname( __DIR__, 2 ) . '/' . $folder;
		mkdir( $dir );
		file_put_contents( "{$dir}/.htaccess", str_replace( 'FOLDER', $folder, $htaccess ) );
		// Only the file the response sends is large: a denied log is never sent, so it holds the canary alone.
		file_put_contents( "{$dir}/debug.log", $canary . "\n" );
		$limit = ini_get( 'memory_limit' );

		try {
			$this->write_large_file( "{$dir}/{$served}", $canary );
			$this->assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 16 * 1024 * 1024 ) ) );
			$this->assert_denied( self::SERVER, "/wp-content/plugins/bricks-mcp/{$folder}/debug.log", $canary );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( $failure, $e->getMessage() );
			return;
		} finally {
			ini_set( 'memory_limit', $limit );
			foreach ( [ 'debug.log', 'denied.txt' ] as $file ) {
				if ( file_exists( "{$dir}/{$file}" ) ) {
					unlink( "{$dir}/{$file}" );
				}
			}
			unlink( "{$dir}/.htaccess" );
			rmdir( $dir );
		}

		$this->fail( 'The check passed for a log served with its canary.' );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function large_responses(): array {
		return [
			'served log'  => [ "Require all granted\n", 'debug.log', 'Failed asserting that 200 is identical to 403' ],
			'denial page' => [
				"<Files \"debug.log\">\nRequire all denied\n</Files>\nErrorDocument 403 /wp-content/plugins/bricks-mcp/FOLDER/denied.txt\n",
				'denied.txt',
				'served the canary',
			],
		];
	}

	/**
	 * A large fixture the disk fills before its canary fails, instead of leaving a log the check finds clean.
	 */
	public function test_large_fixture_cut_short_by_a_full_disk_fails(): void {
		try {
			$this->write_large_file( '/dev/full', 'diagnostic canary ' . bin2hex( random_bytes( 8 ) ) );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( 'Could not write the whole fixture /dev/full', $e->getMessage() );
			return;
		}

		$this->fail( 'The fixture writer passed on a disk with no space left.' );
	}

	/**
	 * A 403 response that stalls before its trailing canary fails the check instead of passing on the part it sent.
	 *
	 * The page is gone from the checkout while it stalls, so a run stopped during the wait leaves nothing behind.
	 *
	 * @dataProvider read_pauses
	 */
	public function test_stalled_response_fails_the_check( int $pause ): void {
		$canary = 'diagnostic canary ' . bin2hex( random_bytes( 8 ) );
		// Longer than the body's timeout, and shorter than a 3 s one or TIMEOUT.
		$gap   = self::STALL_TIMEOUT + 1;
		$parts = intdiv( self::TIMEOUT, $gap );
		// A length, like a static file's, so the body is not chunked and a read that times out returns nothing.
		// Output buffers ended, as php.ini can start one (php -S does), so each part goes out when it is flushed.
		// Headers one gap late, so only the body is held to its timeout.
		// The rest of the body a byte a gap, then the canary: a read held to its own timeout gives up on the first gap
		// it waits through, however late it starts, while a read held to a longer one gets through them all to the canary.
		$code = "while ( ob_get_level() > 0 && ob_end_flush() ) {\n}\nsleep( %1\$d );\nhttp_response_code( 403 );\nheader( 'Content-Length: %2\$d' );\necho str_repeat( 'x', 65536 );\nflush();\n"
			. "for ( \$i = 1; \$i < %3\$d; \$i++ ) {\n\tsleep( %1\$d );\n\techo 'x';\n\tflush();\n}\nsleep( %1\$d );\necho %4\$s;";
		$page = $this->write_page( sprintf( $code, $gap, 65536 + $parts - 1 + strlen( $canary ), $parts, var_export( $canary, true ) ) );
		$file = dirname( __DIR__, 2 ) . '/' . $page;

		try {
			$this->assert_denied( self::SERVER, '/wp-content/plugins/bricks-mcp/' . $page, $canary, self::STALL_TIMEOUT, $pause );
		} catch ( AssertionFailedError $e ) {
			// Not 'served the canary': the read gave up before the canary came, so the body was held to its own timeout.
			$this->assertStringContainsString( 'timed out before the end of its body', $e->getMessage() );
			$this->assertFileDoesNotExist( $file, 'The stalled page was still in the checkout during its wait.' );
			return;
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->fail( 'The check passed for a response that stalled before its canary.' );
	}

	/**
	 * Seconds the check waits between the headers and reading the body, as a busy runner may.
	 *
	 * @return array<string, array{int}>
	 */
	public static function read_pauses(): array {
		return [
			'read at once'       => [ 0 ],
			'read after a pause' => [ 6 ],
		];
	}

	/**
	 * A probe or page a run stopped before its request leaves at the checkout's root is ignored, so git add -A does not stage it.
	 *
	 * Needs no web server: it only writes each fixture and asks git what it would do with it.
	 */
	public function test_fixtures_left_in_the_checkout_are_ignored_by_git(): void {
		$checkout = dirname( __DIR__, 2 );
		exec( 'git --version 2>&1', $output, $code );
		if ( 0 !== $code || ! file_exists( "{$checkout}/.git" ) ) {
			$this->markTestSkipped( 'Needs git and the checkout\'s .git folder.' );
		}

		$fixtures = [
			'probe' => $this->write_probe( '', '' )[0],
			'page'  => $this->write_page( 'echo 1;' ),
		];

		try {
			foreach ( $fixtures as $kind => $fixture ) {
				// The checkout may belong to another user than the one running the tests, as in wp-env.
				$status = (string) shell_exec( 'git -c safe.directory=' . escapeshellarg( $checkout ) . ' -C ' . escapeshellarg( $checkout ) . ' status --porcelain --untracked-files=all --ignored -- ' . escapeshellarg( $fixture ) . ' 2>&1' );
				$this->assertSame( "!! {$fixture}\n", $status, "git add -A would stage the {$kind} {$fixture}." );
			}
		} finally {
			foreach ( $fixtures as $fixture ) {
				unlink( "{$checkout}/{$fixture}" );
			}
		}
	}

	/**
	 * Runs the probe on a site.
	 *
	 * @return array{array{log: string, target: string, root: string, file: bool, written: bool}, string} What it reports, and its canary.
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
	 * Writes a page of PHP code to the plugin checkout's root.
	 *
	 * The page deletes itself before running the code, like the probe, so it runs once and a
	 * run stopped while the server runs it leaves nothing in the checkout.
	 *
	 * @return string Its path in the checkout.
	 */
	private function write_page( string $code ): string {
		$page = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) ) . '.php';
		file_put_contents( dirname( __DIR__, 2 ) . '/' . $page, "<?php\nif ( ! @unlink( __FILE__ ) ) {\n\thttp_response_code( 500 );\n\texit( 'The page could not delete itself.' );\n}\n{$code}\n" );

		return $page;
	}

	/**
	 * Runs PHP code on a site's web server and returns what it prints.
	 */
	private function run_on_server( string $server, string $code ): string {
		$page = $this->write_page( $code );
		$file = dirname( __DIR__, 2 ) . '/' . $page;

		try {
			[ $status, $body ] = $this->get( $server, '/wp-content/plugins/bricks-mcp/' . $page );
			$this->assertSame( 200, $status, "The page failed on {$server}:\n{$body}" );

			return $body;
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Runs PHP code in a PHP process of its own here and returns what it prints, the way run_on_server() does on a site.
	 */
	private static function run_here( string $code ): string {
		return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) );
	}

	/**
	 * The PHP each step of the earlier-target check runs, for the regular file an earlier run logged to at $path.
	 *
	 * plant first makes the lock <path>.lock, so one run at a time uses the path: a run that finds
	 * the lock prints busy and changes nothing. It then keeps whatever is at the path aside under a
	 * fresh -<hex> name and puts that file there, removing it and printing blocked when it cannot
	 * write all of it; swap moves it aside under another and makes a directory in its place. A directory plant cannot move aside stays and plant prints present,
	 * so the check runs against it in place. plant also records what it printed in the lock, and
	 * recall prints that record, '' when this run made no lock, for a caller whose response was
	 * lost. restore, given what both printed, undoes what they did
	 * and removes the lock last. It moves nothing over what is at the path and deletes only its own
	 * planted file. It prints back only when that file was there as planted, or the directory is
	 * still there, and what the site held is back at the path; when that cannot go back, it prints
	 * lost and keeps the lock.
	 *
	 * @return array{plant: string, swap: string, recall: string, restore: \Closure(string, string): string} restore's code is '' when there is nothing to undo.
	 */
	private static function earlier_target_steps( string $path ): array {
		$earlier = var_export( $path, true );
		$aside   = var_export( $path . '-' . bin2hex( random_bytes( 8 ) ), true );
		$held    = var_export( $path . '-' . bin2hex( random_bytes( 8 ) ), true );
		$old_log = var_export( 'An earlier run logged here ' . bin2hex( random_bytes( 8 ) ) . "\n", true );
		$lock    = var_export( $path . '.lock', true );
		$record  = var_export( $path . '.lock/' . bin2hex( random_bytes( 8 ) ), true );
		// fopen( 'x' ) and link() fail on whatever is at the path, so nothing put there between a check and a write is replaced.
		$put_back = static fn ( string $from ): string => "@link( {$from}, {$earlier} ) && unlink( {$from} )";

		return [
			'plant'   => "if ( ! @mkdir( {$lock} ) ) {\n\texit( 'busy' );\n}\n\$held = ( file_exists( {$earlier} ) || is_link( {$earlier} ) ) && @rename( {$earlier}, {$held} );\n"
				. "\$log = @fopen( {$earlier}, 'x' );\n\$whole = \$log && strlen( {$old_log} ) === @fwrite( \$log, {$old_log} );\n\$whole = \$log && fclose( \$log ) && \$whole;\n"
				// A file cut short, as by a full disk, is not the planted one restore removes, so it would keep the log from going back.
				. "\$log && ! \$whole && unlink( {$earlier} );\n\$present = ! \$held && is_dir( {$earlier} ) && ! is_link( {$earlier} );\n"
				. "\$planting = ( \$whole ? 'planted' : ( \$present ? 'present' : 'blocked' ) ) . ( \$held ? ' held' : ' none' );\n"
				// A response lost on the way leaves this record for recall, so the cleanup still knows what to undo.
				. "@file_put_contents( {$record}, \$planting );\necho \$planting;",
			'recall'  => "echo (string) @file_get_contents( {$record} );",
			'swap'    => "\$moved = ! is_dir( {$earlier} ) && ( file_exists( {$earlier} ) || is_link( {$earlier} ) ) && @rename( {$earlier}, {$aside} );\n"
				. "echo is_dir( {$earlier} ) ? 'present' : ( @mkdir( {$earlier} ) ? 'made' : 'blocked' ), \$moved ? ' moved' : ' kept';",
			'restore' => static function ( string $planting, string $swapping ) use ( $earlier, $aside, $held, $old_log, $lock, $record, $put_back ): string {
				[ $planted, $kept ] = explode( ' ', $planting ) + [ '', '' ];
				[ $dir, $file ]     = explode( ' ', $swapping ) + [ '', '' ];
				if ( '' === $planted || 'busy' === $planted ) {
					return '';
				}
				// Whatever is back where the directory was stays there.
				$undo = ( 'made' === $dir ? "@rmdir( {$earlier} );\n" : '' ) . ( 'moved' === $file ? $put_back( $aside ) . ";\n" : '' );
				if ( 'planted' === $planted ) {
					$undo .= "\$back = is_file( {$earlier} ) && {$old_log} === file_get_contents( {$earlier} );\n\$back && unlink( {$earlier} );\n";
				}
				// A directory cannot be linked; renamed, it replaces at most an empty directory, never a file.
				$undo .= '$returned = ' . ( 'held' === $kept ? "is_dir( {$held} ) && ! is_link( {$held} ) ? @rename( {$held}, {$earlier} ) : " . $put_back( $held ) : 'true' ) . ";\n";
				if ( 'planted' === $planted ) {
					$undo .= "echo \$back && \$returned ? 'back' : 'lost';\n";
				} elseif ( 'present' === $planted ) {
					$undo .= "echo is_dir( {$earlier} ) && ! is_link( {$earlier} ) ? 'back' : 'lost';\n";
				}

				// What the site held stays locked under its -<hex> name until someone puts it back.
				return $undo . "if ( \$returned ) {\n\t@unlink( {$record} );\n\trmdir( {$lock} );\n}";
			},
		];
	}

	/**
	 * Undoes what the earlier-target steps did and returns what restore printed, '' when there was nothing to undo.
	 *
	 * @param array{plant: string, swap: string, recall: string, restore: \Closure(string, string): string} $steps The run's steps.
	 * @param string|null                                                                                      $planting What plant printed, null when its response never came.
	 * @param string                                                                                           $swapping What swap printed, '' when it did not run.
	 * @param \Closure(string): string                                                                         $run      Runs PHP where plant ran and returns what it prints.
	 */
	private static function undo_earlier_target( array $steps, ?string $planting, string $swapping, \Closure $run ): string {
		$undo = $steps['restore']( $planting ?? $run( $steps['recall'] ), $swapping );

		return '' === $undo ? '' : $run( $undo );
	}

	/**
	 * Links a log in the denied logs folder to a file in the tests site's own /tmp and runs the configured check on it.
	 *
	 * The probe's write creates the target. It takes a fresh name per run and is removed after it,
	 * so nothing an earlier run left in /tmp, a directory included, stops that write.
	 */
	private function assert_symlinked_outside_log_denied(): void {
		$name   = 'bricks-mcp-access-test-' . bin2hex( random_bytes( 8 ) ) . '.log';
		$link   = dirname( __DIR__, 2 ) . '/logs/' . $name;
		$target = '/tmp/' . $name;

		try {
			$this->create_symlink( $target, $link );
			[ $site, $canary ] = $this->probe( self::SERVER, "wp-content/logs/{$name}" );

			$this->assertSame( "{$site['root']}wp-content/logs/{$name}", $site['log'] );
			// The site sees the link: a regular file left in its place would report itself as its target.
			$this->assertSame( $target, $site['target'] );
			$this->assert_configured_log_denied( self::SERVER, $site, $canary );
		} finally {
			if ( is_link( $link ) || file_exists( $link ) ) {
				unlink( $link );
			}
			$file    = var_export( $target, true );
			$removed = $this->run_on_server( self::SERVER, "echo ! file_exists( {$file} ) || unlink( {$file} ) ? 'removed' : 'left';" );
		}

		$this->assertSame( 'removed', $removed, "The tests site kept the target {$target}." );
	}

	/**
	 * The configured check: a log, or the file a symlinked log points to, under the web root is
	 * written, created and denied at each of their URLs; any other log skips.
	 *
	 * @param array{log: string, target: string, root: string, file: bool, written: bool} $site What the probe reports.
	 */
	private function assert_configured_log_denied( string $server, array $site, string $canary ): void {
		$paths = [];
		foreach ( array_unique( [ $site['log'], $site['target'] ] ) as $file ) {
			if ( str_starts_with( $file, $site['root'] ) ) {
				$paths[] = '/' . substr( $file, strlen( $site['root'] ) );
			}
		}
		if ( [] === $paths ) {
			$this->markTestSkipped( "{$server} logs to {$site['log']} ({$site['target']}), not a file under its web root {$site['root']}." );
		}

		$this->assert_logged( $server, $site );
		foreach ( $paths as $path ) {
			$this->assert_denied( $server, $path, $canary );
		}
	}

	/**
	 * The probe's log exists: error_log() still returns true when it cannot create the file.
	 *
	 * @param array{log: string, target: string, root: string, file: bool, written: bool} $site What the probe reports.
	 */
	private function assert_logged( string $server, array $site ): void {
		$this->assertTrue( $site['written'], "WordPress on {$server} could not write {$site['log']}." );
		$this->assertTrue( $site['file'], "WordPress on {$server} could not create {$site['log']}." );
	}

	/**
	 * The request is refused and its whole body, read in chunks so a large log fits in memory, holds no canary.
	 *
	 * @param int $timeout Seconds to wait for each part of its body.
	 * @param int $pause   Seconds to wait before reading its body.
	 */
	private function assert_denied( string $server, string $path, string $canary, int $timeout = self::TIMEOUT, int $pause = 0 ): void {
		[ $status, $stream ] = $this->open( $server, $path, $timeout );

		try {
			$this->assertSame( 403, $status, $server . $path );
			sleep( $pause );

			// Keep the end of each chunk, so a canary split across two chunks is still found.
			$tail = '';
			do {
				$read  = (string) fread( $stream, 65536 );
				$chunk = $tail . $read;
				$found = str_contains( $chunk, $canary );
				$tail  = substr( $chunk, -( strlen( $canary ) - 1 ) );
			} while ( ! $found && '' !== $read );
			$this->assertFalse( $found, "{$server}{$path} served the canary." );
			// A read that times out also returns '', leaving the rest of the body unchecked.
			$this->assertFalse( stream_get_meta_data( $stream )['timed_out'], "{$server}{$path} timed out before the end of its body." );
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
	 * The response always gets TIMEOUT seconds to arrive.
	 *
	 * @param int $timeout Seconds to wait for each part of its body.
	 * @return array{int, resource}
	 */
	private function open( string $server, string $path, int $timeout = self::TIMEOUT ): array {
		$url     = 'http://' . $server . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		$context = stream_context_create( [ 'http' => [ 'ignore_errors' => true, 'timeout' => self::TIMEOUT ] ] );
		$stream  = fopen( $url, 'rb', false, $context );
		$this->assertNotFalse( $stream, "{$server} did not answer." );
		stream_set_timeout( $stream, $timeout );

		preg_match( '#^HTTP/\S+ (\d{3})#', stream_get_meta_data( $stream )['wrapper_data'][0], $matches );

		return [ (int) $matches[1], $stream ];
	}

	/**
	 * Links a log to its target, failing when the mount rejects symlinks: the probe would log to a regular file there instead.
	 */
	private function create_symlink( string $target, string $link ): void {
		$this->assertTrue( @symlink( $target, $link ) && is_link( $link ), "Could not create the symlink {$link}: does its mount allow symlinks?" );
	}

	/**
	 * Writes a 64 MiB file that ends with the canary, without holding it in memory.
	 *
	 * A short disk fails here: a file cut off before its canary would pass the check it exists to fail.
	 */
	private function write_large_file( string $file, string $canary ): void {
		$handle = fopen( $file, 'wb' );
		$line   = str_repeat( 'x', 1023 ) . "\n";
		$size   = 0;
		for ( $i = 0; $i < 64 * 1024; $i++ ) {
			$size += (int) @fwrite( $handle, $line );
		}
		$size += (int) @fwrite( $handle, $canary . "\n" );
		fclose( $handle );
		$this->assertSame( 64 * 1024 * 1024 + strlen( $canary ) + 1, $size, "Could not write the whole fixture {$file}: is the disk full?" );
	}
}
