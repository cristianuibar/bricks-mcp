<?php
/**
 * Rate limiting for WordPress ability executions.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Safety;

use BricksMCP\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limits top-level ability executions per REST user.
 */
final class RateLimiter {

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private const WINDOW = 60;

	/**
	 * Register the rate limit filter.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_pre_execute_ability', [ $this, 'check' ], 9, 4 );
	}

	/**
	 * Check the current ability's rate limit.
	 *
	 * @param mixed       $pre Existing filter result.
	 * @param string      $name Ability name.
	 * @param mixed       $input Raw ability input.
	 * @param \WP_Ability $ability Ability being executed.
	 * @return mixed
	 */
	public function check( mixed $pre, string $name, mixed $input, \WP_Ability $ability ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Core passes four arguments to this filter.
		if ( ! $pre instanceof \WP_Filter_Sentinel ) {
			return $pre;
		}

		$is_rest = function_exists( 'wp_is_serving_rest_request' )
			? wp_is_serving_rest_request()
			: ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! $is_rest ) {
			return $pre;
		}

		$user_id = get_current_user_id();
		$limit   = Options::get()['rate_limit_rpm'];
		if ( 0 === $user_id || $limit <= 0 || $this->is_nested_execution() ) {
			return $pre;
		}

		$now         = time();
		$retry_after = self::WINDOW - $now % self::WINDOW;
		$window      = intdiv( $now, self::WINDOW );
		$key         = 'bricks_mcp_rl_' . $user_id;

		if ( wp_using_ext_object_cache() ) {
			$key .= '_' . $window;
			wp_cache_add( $key, 0, 'bricks_mcp', $retry_after );
			$count = wp_cache_incr( $key, 1, 'bricks_mcp' );
			if ( false === $count ) {
				wp_cache_set( $key, 1, 'bricks_mcp', $retry_after );
				return $pre;
			}
		} else {
			$state = get_transient( $key );
			$count = is_array( $state ) && ( $state['window'] ?? null ) === $window ? (int) ( $state['count'] ?? 0 ) + 1 : 1;
			set_transient(
				$key,
				[
					'window' => $window,
					'count'  => $count,
				],
				$retry_after
			);
		}

		if ( $count <= $limit ) {
			return $pre;
		}

		return new \WP_Error(
			'bricks_mcp_rate_limited',
			/* translators: %d: Seconds until the rate limit resets. */
			sprintf( __( 'Rate limit exceeded. Try again in %d seconds.', 'bricks-mcp' ), $retry_after ),
			[
				'status'      => 429,
				'retry_after' => $retry_after,
			]
		);
	}

	/**
	 * Detect a parent invocation even if execution skips a lifecycle action.
	 *
	 * @return bool
	 */
	private function is_nested_execution(): bool {
		$depth = 0;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Core skips the after action on error, so depth must come from live frames.
		$frames = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS );
		foreach ( $frames as $index => $frame ) {
			if ( 'execute' !== $frame['function'] || ! ( $frame['object'] ?? null ) instanceof \WP_Ability ) {
				continue;
			}

			$callee = $frames[ $index - 1 ];
			if ( 'execute' === $callee['function'] && ( $callee['object'] ?? null ) === $frame['object'] ) {
				continue;
			}

			if ( ++$depth > 1 ) {
				return true;
			}
		}

		return false;
	}
}
