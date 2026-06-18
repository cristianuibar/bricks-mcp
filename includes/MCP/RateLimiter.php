<?php
/**
 * Atomic rate limiter for MCP endpoints.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RateLimiter class.
 *
 * Provides per-identifier rate limiting via two code paths:
 *
 * 1. Persistent object cache path (Redis, Memcached):
 *    Uses wp_cache_add + wp_cache_incr. Both operations are atomic on
 *    persistent backends, eliminating the TOCTOU race condition.
 *    Detected via wp_using_ext_object_cache().
 *
 * 2. Transient fallback path (default WP object cache):
 *    Uses get_transient / set_transient with a WINDOW (60 s) expiry.
 *    The in-process WP object cache resets per request, so wp_cache_incr
 *    is non-functional across requests — transients are the correct
 *    mechanism for sites without Redis/Memcached.
 *
 * Both paths return true within the limit or WP_Error 429 when exceeded.
 */
final class RateLimiter {

	/**
	 * Cache group for rate limit counters (persistent cache path only).
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'bricks_mcp';

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private const WINDOW = 60;

	/**
	 * Check whether the given identifier is within the rate limit.
	 *
	 * Selects between the persistent object cache path and the transient
	 * fallback path based on wp_using_ext_object_cache().
	 *
	 * @param string $identifier The rate limit identifier (e.g. 'user_42' or 'ip_1.2.3.4').
	 * @return true|\WP_Error True if within limit, WP_Error with status 429 if exceeded.
	 */
	public static function check( string $identifier ): bool|\WP_Error {
		$settings = get_option( 'bricks_mcp_settings', [] );
		$limit    = (int) ( $settings['rate_limit_rpm'] ?? 120 );

		if ( wp_using_ext_object_cache() ) {
			$count = self::increment_via_object_cache( $identifier );
		} else {
			$count = self::increment_via_transient( $identifier );
		}

		if ( false === $count || (int) $count > $limit ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- suppress "headers already sent" in test environments.
			@header( 'Retry-After: ' . self::WINDOW );

			return new \WP_Error(
				'bricks_mcp_rate_limit',
				__( 'Rate limit exceeded. Try again later.', 'bricks-mcp' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Increment counter using the persistent object cache (atomic path).
	 *
	 * @param string $identifier Rate limit identifier.
	 * @return int|false New counter value, or false on failure.
	 */
	private static function increment_via_object_cache( string $identifier ): int|false {
		$key = 'rl_' . $identifier;

		// Initialize counter only if it does not already exist (atomic on persistent cache).
		wp_cache_add( $key, 0, self::CACHE_GROUP, self::WINDOW );

		// Atomically increment and return the new count.
		return wp_cache_incr( $key, 1, self::CACHE_GROUP );
	}

	/**
	 * Increment counter using transients (fallback for sites without persistent cache).
	 *
	 * The transient key uses the global namespace (no CACHE_GROUP prefix) to
	 * ensure persistence across requests.
	 *
	 * @param string $identifier Rate limit identifier.
	 * @return int New counter value.
	 */
	private static function increment_via_transient( string $identifier ): int {
		$transient_key = 'bricks_mcp_rl_' . $identifier;
		$current       = get_transient( $transient_key );

		if ( false === $current ) {
			$count = 1;
		} else {
			$count = (int) $current + 1;
		}

		set_transient( $transient_key, $count, self::WINDOW );

		return $count;
	}
}
