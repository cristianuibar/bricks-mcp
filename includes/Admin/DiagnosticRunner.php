<?php
/**
 * DiagnosticRunner class.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

use BricksMCP\Admin\Checks\AppPasswordsAvailableCheck;
use BricksMCP\Admin\Checks\AppPasswordsUserCheck;
use BricksMCP\Admin\Checks\HostingProviderCheck;
use BricksMCP\Admin\Checks\HttpsCheck;
use BricksMCP\Admin\Checks\McpEndpointCheck;
use BricksMCP\Admin\Checks\PermalinkStructureCheck;
use BricksMCP\Admin\Checks\PhpTimeoutCheck;
use BricksMCP\Admin\Checks\RestApiReachableCheck;
use BricksMCP\Admin\Checks\SecurityPluginCheck;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs all diagnostic checks and returns structured results.
 *
 * Resolves check dependency order via topological sort and skips
 * downstream checks when a dependency fails.
 */
class DiagnosticRunner {

	/**
	 * Registered checks keyed by check ID.
	 *
	 * @var array<string, DiagnosticCheck>
	 */
	private array $checks = array();

	/**
	 * Register a single diagnostic check.
	 *
	 * @param DiagnosticCheck $check Check instance to register.
	 * @return void
	 */
	public function register( DiagnosticCheck $check ): void {
		$this->checks[ $check->id() ] = $check;
	}

	/**
	 * Register all default diagnostic checks.
	 *
	 * Instantiates and registers the 9 built-in checks. Applies the
	 * bricks_mcp_diagnostic_checks filter to allow third-party extensions.
	 *
	 * @return void
	 */
	public function register_defaults(): void {
		$this->register( new HttpsCheck() );
		$this->register( new PermalinkStructureCheck() );
		$this->register( new AppPasswordsAvailableCheck() );
		$this->register( new AppPasswordsUserCheck() );
		$this->register( new RestApiReachableCheck() );
		$this->register( new SecurityPluginCheck() );
		$this->register( new HostingProviderCheck() );
		$this->register( new McpEndpointCheck() );
		$this->register( new PhpTimeoutCheck() );

		/**
		 * Filter the registered diagnostic checks.
		 *
		 * @param array<string, DiagnosticCheck> $checks Checks keyed by ID.
		 */
		$this->checks = apply_filters( 'bricks_mcp_diagnostic_checks', $this->checks );
	}

	/**
	 * Run all registered checks in dependency order.
	 *
	 * Checks whose dependencies have a 'fail' status are marked as 'skipped'.
	 *
	 * @return array{overall_status: string, checks: array<string, mixed>, summary: string}
	 */
	public function run_all(): array {
		$ordered = $this->resolve_order();
		$results = array();
		$failed  = array(); // IDs of checks that failed.

		foreach ( $ordered as $check ) {
			$check_id     = $check->id();
			$dependencies = $check->dependencies();

			// Check if any dependency failed.
			$blocking_dep = null;
			foreach ( $dependencies as $dep_id ) {
				if ( in_array( $dep_id, $failed, true ) ) {
					$blocking_dep = $dep_id;
					break;
				}
			}

			if ( null !== $blocking_dep ) {
				$dep_label = isset( $results[ $blocking_dep ] )
					? $results[ $blocking_dep ]['label']
					: $blocking_dep;

				$results[ $check_id ] = array(
					'id'        => $check_id,
					'label'     => $check->label(),
					'status'    => 'skipped',
					'message'   => sprintf(
						// translators: %s is the label of the failed dependency check.
						__( 'Skipped -- blocked by failed check: %s', 'bricks-mcp' ),
						$dep_label
					),
					'fix_steps' => array(),
					'category'  => $check->category(),
				);
			} else {
				$result               = $check->run();
				$results[ $check_id ] = $result;

				if ( 'fail' === ( $result['status'] ?? '' ) ) {
					$failed[] = $check_id;
				}
			}
		}

		$overall_status = $this->compute_overall_status( $results );
		$summary        = $this->build_summary( $results );

		return array(
			'overall_status' => $overall_status,
			'checks'         => array_values( $results ),
			'summary'        => $summary,
		);
	}

	/**
	 * Get all registered checks.
	 *
	 * @return array<string, DiagnosticCheck>
	 */
	public function get_checks(): array {
		return $this->checks;
	}

	/**
	 * Topological sort of checks by their dependencies.
	 *
	 * Checks with no dependencies come first. Handles unknown dependencies
	 * gracefully (treats them as absent).
	 *
	 * @return array<DiagnosticCheck> Ordered list of checks.
	 */
	private function resolve_order(): array {
		$ordered  = array();
		$visited  = array();
		$in_stack = array();

		$visit = null;
		$visit = function ( string $id ) use ( &$ordered, &$visited, &$in_stack, &$visit ): void {
			if ( isset( $visited[ $id ] ) ) {
				return;
			}
			if ( isset( $in_stack[ $id ] ) ) {
				// Circular dependency detected — skip to avoid infinite loop.
				return;
			}
			if ( ! isset( $this->checks[ $id ] ) ) {
				return;
			}

			$in_stack[ $id ] = true;

			foreach ( $this->checks[ $id ]->dependencies() as $dep_id ) {
				$visit( $dep_id );
			}

			unset( $in_stack[ $id ] );
			$visited[ $id ] = true;
			$ordered[]      = $this->checks[ $id ];
		};

		foreach ( array_keys( $this->checks ) as $id ) {
			$visit( $id );
		}

		return $ordered;
	}

	/**
	 * Compute the overall status from all check results.
	 *
	 * Returns 'fail' if any check failed, 'warn' if any warned (and none failed),
	 * 'pass' if all passed or skipped.
	 *
	 * @param array<string, array<string, mixed>> $results Check results keyed by ID.
	 * @return string 'pass' | 'warn' | 'fail'
	 */
	private function compute_overall_status( array $results ): string {
		$has_fail = false;
		$has_warn = false;

		foreach ( $results as $result ) {
			$status = $result['status'] ?? 'pass';
			if ( 'fail' === $status ) {
				$has_fail = true;
			} elseif ( 'warn' === $status ) {
				$has_warn = true;
			}
		}

		if ( $has_fail ) {
			return 'fail';
		}
		if ( $has_warn ) {
			return 'warn';
		}
		return 'pass';
	}

	/**
	 * Build a one-line summary string from the check results.
	 *
	 * @param array<string, array<string, mixed>> $results Check results keyed by ID.
	 * @return string e.g. "7/9 checks passed, 1 failed, 1 skipped"
	 */
	private function build_summary( array $results ): string {
		$total   = count( $results );
		$passed  = 0;
		$failed  = 0;
		$warned  = 0;
		$skipped = 0;

		foreach ( $results as $result ) {
			$status = $result['status'] ?? 'pass';
			switch ( $status ) {
				case 'pass':
					++$passed;
					break;
				case 'fail':
					++$failed;
					break;
				case 'warn':
					++$warned;
					break;
				case 'skipped':
					++$skipped;
					break;
			}
		}

		$parts = array(
			sprintf( '%d/%d checks passed', $passed, $total ),
		);

		if ( $failed > 0 ) {
			$parts[] = sprintf( '%d failed', $failed );
		}
		if ( $warned > 0 ) {
			$parts[] = sprintf( '%d warned', $warned );
		}
		if ( $skipped > 0 ) {
			$parts[] = sprintf( '%d skipped', $skipped );
		}

		return implode( ', ', $parts );
	}
}
