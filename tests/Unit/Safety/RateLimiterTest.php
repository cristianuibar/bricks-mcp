<?php
/**
 * Rate limit behavior for ability execution.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Safety;

use BricksMCP\Options;
use BricksMCP\Safety\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

use function BricksMCP\Tests\Support\make_safety_ability;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		bricks_mcp_test_reset();
		$GLOBALS['_bricks_mcp_test_safety_filters'] = [];
		$GLOBALS['_bricks_mcp_test_serving_rest']   = true;
		$GLOBALS['_bricks_mcp_test_safety_time']    = 1201;
		update_option( Options::OPTION, [ 'rate_limit_rpm' => Options::RATE_LIMIT_MIN ] );
		( new RateLimiter() )->init();
	}

	protected function tearDown(): void {
		bricks_mcp_test_reset();
		wp_using_ext_object_cache( false );
		unset( $GLOBALS['_bricks_mcp_test_safety_filters'], $GLOBALS['_bricks_mcp_test_safety_time'], $GLOBALS['_bricks_mcp_test_serving_rest'], $GLOBALS['_bricks_mcp_test_evict_rate_limit_key'] );
	}

	public static function storage_paths(): array {
		return [
			'transient'        => [ false ],
			'persistent cache' => [ true ],
		];
	}

	#[DataProvider( 'storage_paths' )]
	public function test_user_is_limited_until_the_next_fixed_window( bool $persistent_cache ): void {
		wp_using_ext_object_cache( $persistent_cache );
		$ability = make_safety_ability( 'bricks/get-page-elements', [ 'execute_callback' => static fn() => 'executed' ] );

		for ( $index = 0; $index < Options::RATE_LIMIT_MIN; ++$index ) {
			self::assertSame( 'executed', $ability->execute() );
		}

		$GLOBALS['_bricks_mcp_test_safety_time'] = 1259;
		$result                                 = $ability->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_rate_limited', $result->get_error_code() );
		self::assertSame( [ 'status' => 429, 'retry_after' => 1 ], $result->get_error_data() );
		self::assertSame( 'Rate limit exceeded. Try again in 1 seconds.', $result->get_error_message() );

		$GLOBALS['_bricks_mcp_test_current_user_id'] = 42;
		self::assertSame( 'executed', $ability->execute() );

		$GLOBALS['_bricks_mcp_test_current_user_id'] = 1;
		$GLOBALS['_bricks_mcp_test_safety_time']     = 1260;
		self::assertSame( 'executed', $ability->execute() );

		if ( ! $persistent_cache ) {
			self::assertSame(
				[
					'bricks_mcp_rl_1'  => [ 'window' => 21, 'count' => 1 ],
					'bricks_mcp_rl_42' => [ 'window' => 20, 'count' => 1 ],
				],
				$GLOBALS['_bricks_mcp_test_transients']
			);
		}
	}

	public function test_cache_eviction_during_increment_allows_the_call_and_reseeds(): void {
		wp_using_ext_object_cache( true );
		$GLOBALS['_bricks_mcp_test_evict_rate_limit_key'] = true;
		$ability = make_safety_ability( 'bricks/get-page-elements', [ 'execute_callback' => static fn() => 'executed' ] );

		self::assertSame( 'executed', $ability->execute() );

		for ( $index = 1; $index < Options::RATE_LIMIT_MIN; ++$index ) {
			self::assertSame( 'executed', $ability->execute() );
		}

		$result = $ability->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_rate_limited', $result->get_error_code() );
	}

	#[DataProvider( 'storage_paths' )]
	public function test_unauthenticated_requests_keep_the_permission_error_and_use_no_bucket( bool $persistent_cache ): void {
		wp_using_ext_object_cache( $persistent_cache );
		$GLOBALS['_bricks_mcp_test_current_user_id'] = 0;
		$ability = make_safety_ability( 'bricks/get-page-elements', [ 'permission_callback' => static fn() => false ] );

		for ( $index = 0; $index <= Options::RATE_LIMIT_MIN; ++$index ) {
			$result = $ability->execute();
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}

		self::assertSame( [], $GLOBALS['_bricks_mcp_test_cache'] );
		self::assertSame( [], $GLOBALS['_bricks_mcp_test_transients'] );
	}

	public static function bypassed_contexts(): array {
		return [
			'disabled' => [ true, 0 ],
			'non-REST' => [ false, Options::RATE_LIMIT_MIN ],
		];
	}

	#[DataProvider( 'bypassed_contexts' )]
	public function test_limiter_bypasses_disabled_and_non_rest_calls( bool $is_rest, int $limit ): void {
		$GLOBALS['_bricks_mcp_test_serving_rest'] = $is_rest;
		update_option( Options::OPTION, [ 'rate_limit_rpm' => $limit ] );
		$ability = make_safety_ability( 'bricks/get-page-elements', [ 'execute_callback' => static fn() => 'executed' ] );

		for ( $index = 0; $index <= Options::RATE_LIMIT_MIN; ++$index ) {
			self::assertSame( 'executed', $ability->execute() );
		}
	}

	public function test_nested_failure_counts_once_and_does_not_poison_following_calls(): void {
		$inner = make_safety_ability(
			'bricks/update-page-settings',
			[ 'execute_callback' => static fn() => new WP_Error( 'update_failed' ) ]
		);
		$outer = make_safety_ability( 'bricks-mcp/update-page', [ 'execute_callback' => static fn() => $inner->execute() ] );

		for ( $index = 0; $index < Options::RATE_LIMIT_MIN; ++$index ) {
			$result = $outer->execute();
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'update_failed', $result->get_error_code() );
		}

		$result = $inner->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_rate_limited', $result->get_error_code() );
	}

	public function test_permission_denial_does_not_poison_following_calls(): void {
		$denied  = make_safety_ability( 'bricks/update-page-settings', [ 'permission_callback' => static fn() => false ] );
		$allowed = make_safety_ability( 'bricks/get-page-elements', [ 'execute_callback' => static fn() => 'executed' ] );

		$result = $denied->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		for ( $index = 1; $index < Options::RATE_LIMIT_MIN; ++$index ) {
			self::assertSame( 'executed', $allowed->execute() );
		}

		$result = $allowed->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_rate_limited', $result->get_error_code() );
	}
}
