<?php
/**
 * DiagnosticRunner unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use BricksMCP\Admin\DiagnosticRunner;

// WordPress function stubs are provided by tests/stubs/wp-functions.php
// (loaded via bootstrap-simple.php) in the global namespace.

/**
 * Fake check implementation for testing.
 */
class FakeCheck implements \BricksMCP\Admin\DiagnosticCheck {

	/**
	 * Check ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Check label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Check category.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * Check dependencies (array of check IDs).
	 *
	 * @var array<string>
	 */
	private array $dependencies;

	/**
	 * Check status to return.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Constructor.
	 *
	 * @param string        $id           Check ID.
	 * @param string        $status       Status to return ('pass', 'warn', 'fail').
	 * @param array<string> $dependencies IDs of dependency checks.
	 * @param string        $label        Human-readable label.
	 * @param string        $category     Check category.
	 */
	public function __construct(
		string $id,
		string $status = 'pass',
		array $dependencies = array(),
		string $label = '',
		string $category = 'connectivity'
	) {
		$this->id           = $id;
		$this->label        = $label ?: ucfirst( $id );
		$this->category     = $category;
		$this->dependencies = $dependencies;
		$this->status       = $status;
	}

	/**
	 * Get check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Get check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Get check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Get check dependencies.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return $this->dependencies;
	}

	/**
	 * Run the check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		return array(
			'id'        => $this->id,
			'label'     => $this->label,
			'status'    => $this->status,
			'message'   => $this->label . ' ' . $this->status,
			'fix_steps' => 'fail' === $this->status ? array( 'Fix ' . $this->id ) : array(),
			'category'  => $this->category,
		);
	}
}

/**
 * Tests for DiagnosticRunner.
 */
final class DiagnosticRunnerTest extends TestCase {

	/**
	 * Test that run_all returns overall_status 'pass' when all checks pass.
	 *
	 * @return void
	 */
	public function test_run_all_returns_overall_pass_when_all_checks_pass(): void {
		$runner = new DiagnosticRunner();
		$runner->register( new FakeCheck( 'check_a', 'pass' ) );
		$runner->register( new FakeCheck( 'check_b', 'pass' ) );
		$runner->register( new FakeCheck( 'check_c', 'pass' ) );

		$result = $runner->run_all();

		$this->assertSame( 'pass', $result['overall_status'] );
		$this->assertCount( 3, $result['checks'] );
	}

	/**
	 * Test that run_all skips dependent checks when a dependency fails.
	 *
	 * @return void
	 */
	public function test_run_all_skips_dependent_checks_on_failure(): void {
		$runner = new DiagnosticRunner();
		$runner->register( new FakeCheck( 'check_a', 'fail' ) );
		$runner->register( new FakeCheck( 'check_b', 'pass', array( 'check_a' ) ) );

		$result = $runner->run_all();

		// Find check_b in results.
		$check_b_result = null;
		foreach ( $result['checks'] as $check ) {
			if ( 'check_b' === $check['id'] ) {
				$check_b_result = $check;
				break;
			}
		}

		$this->assertNotNull( $check_b_result, 'check_b should be in results' );
		$this->assertSame( 'skipped', $check_b_result['status'] );
		$this->assertStringContainsString( 'blocked by', $check_b_result['message'] );
	}

	/**
	 * Test that run_all returns overall_status 'fail' when any check fails.
	 *
	 * @return void
	 */
	public function test_run_all_returns_overall_fail_when_any_check_fails(): void {
		$runner = new DiagnosticRunner();
		$runner->register( new FakeCheck( 'check_a', 'pass' ) );
		$runner->register( new FakeCheck( 'check_b', 'fail' ) );
		$runner->register( new FakeCheck( 'check_c', 'pass' ) );

		$result = $runner->run_all();

		$this->assertSame( 'fail', $result['overall_status'] );
	}

	/**
	 * Test that run_all returns overall_status 'warn' when any check warns.
	 *
	 * @return void
	 */
	public function test_run_all_returns_overall_warn_when_any_check_warns(): void {
		$runner = new DiagnosticRunner();
		$runner->register( new FakeCheck( 'check_a', 'pass' ) );
		$runner->register( new FakeCheck( 'check_b', 'warn' ) );
		$runner->register( new FakeCheck( 'check_c', 'pass' ) );

		$result = $runner->run_all();

		$this->assertSame( 'warn', $result['overall_status'] );
	}

	/**
	 * Test that resolve_order respects check dependencies.
	 *
	 * @return void
	 */
	public function test_resolve_order_respects_dependencies(): void {
		$runner = new DiagnosticRunner();
		// Register b (depends on a) before a.
		$runner->register( new FakeCheck( 'check_b', 'pass', array( 'check_a' ) ) );
		$runner->register( new FakeCheck( 'check_a', 'pass' ) );

		$result = $runner->run_all();

		// Find positions of check_a and check_b in results.
		$pos_a = null;
		$pos_b = null;
		foreach ( $result['checks'] as $index => $check ) {
			if ( 'check_a' === $check['id'] ) {
				$pos_a = $index;
			}
			if ( 'check_b' === $check['id'] ) {
				$pos_b = $index;
			}
		}

		$this->assertNotNull( $pos_a, 'check_a should be in results' );
		$this->assertNotNull( $pos_b, 'check_b should be in results' );
		$this->assertLessThan( $pos_b, $pos_a, 'check_a (dependency) should run before check_b' );
	}

	/**
	 * Test that summary string has the expected format.
	 *
	 * @return void
	 */
	public function test_summary_string_format(): void {
		$runner = new DiagnosticRunner();
		$runner->register( new FakeCheck( 'check_a', 'pass' ) );
		$runner->register( new FakeCheck( 'check_b', 'pass' ) );
		$runner->register( new FakeCheck( 'check_c', 'fail' ) );

		$result = $runner->run_all();

		$this->assertArrayHasKey( 'summary', $result );
		$this->assertStringContainsString( '/3 checks passed', $result['summary'] );
	}

	/**
	 * Test that register_defaults registers the 9 v2 checks without bricks_active.
	 *
	 * @return void
	 */
	public function test_register_defaults_registers_expected_checks(): void {
		$runner = new DiagnosticRunner();
		$runner->register_defaults();

		$ids = array_keys( $runner->get_checks() );

		$this->assertSame(
			[
				'https',
				'permalink_structure',
				'app_passwords',
				'app_passwords_user',
				'rest_api_reachable',
				'security_plugins',
				'hosting_provider',
				'mcp_endpoint',
				'php_timeout',
			],
			$ids
		);
	}
}
