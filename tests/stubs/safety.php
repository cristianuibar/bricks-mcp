<?php
/**
 * Safety filter, REST context and clock stubs.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
		final class WP_Filter_Sentinel {}
	}

	if ( ! function_exists( 'wp_is_serving_rest_request' ) ) {
		function wp_is_serving_rest_request(): bool {
			return $GLOBALS['_bricks_mcp_test_serving_rest'] ?? false;
		}
	}
}

namespace BricksMCP\Safety {
	if ( ! function_exists( __NAMESPACE__ . '\add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority, int $accepted_args ): bool {
			$GLOBALS['_bricks_mcp_test_safety_filters'][ $hook ][ $priority ][] = [ $callback, $accepted_args ];
			return true;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\time' ) ) {
		function time(): int {
			return $GLOBALS['_bricks_mcp_test_safety_time'] ?? \time();
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\wp_cache_incr' ) ) {
		function wp_cache_incr( string $key, int $offset, string $group ): int|false {
			if ( $GLOBALS['_bricks_mcp_test_evict_rate_limit_key'] ?? false ) {
				unset( $GLOBALS['_bricks_mcp_test_evict_rate_limit_key'], $GLOBALS['_bricks_mcp_test_cache'][ "$group:$key" ] );
			}
			return \wp_cache_incr( $key, $offset, $group );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\wp_cache_set' ) ) {
		function wp_cache_set( string $key, mixed $value, string $group, int $expire ): bool {
			$GLOBALS['_bricks_mcp_test_cache'][ "$group:$key" ] = $value;
			return true;
		}
	}
}

namespace BricksMCP\Tests\Support {
	use WP_Ability;
	use WP_Error;
	use WP_Filter_Sentinel;

	if ( ! function_exists( __NAMESPACE__ . '\make_safety_ability' ) ) {
		function make_safety_ability( string $name, array $args ): WP_Ability {
			return new class( $name, $args ) extends WP_Ability {
				public function execute( mixed $input = null ): mixed {
					$name = $this->get_name();
					do_action( 'wp_ability_invoked', $name, $input, $this );

					$sentinel = new WP_Filter_Sentinel();
					$pre      = $this->apply_safety_filters( 'wp_pre_execute_ability', $sentinel, $name, $input, $this );
					if ( $pre !== $sentinel ) {
						return $pre;
					}

					$args = $this->get_args();
					if ( null === $input && array_key_exists( 'default', $args['input_schema'] ?? [] ) ) {
						$input = $args['input_schema']['default'];
					}
					$input = $this->apply_safety_filters( 'wp_ability_normalize_input', $input, $name, $this );
					if ( is_wp_error( $input ) ) {
						return $input;
					}

					if ( isset( $args['permission_callback'] ) && true !== $args['permission_callback']( $input ) ) {
						return new WP_Error( 'ability_invalid_permissions' );
					}

					do_action( 'wp_before_execute_ability', $name, $input, $this );
					$result = $args['execute_callback']( $input );
					if ( ! is_wp_error( $result ) ) {
						do_action( 'wp_after_execute_ability', $name, $input, $result, $this );
					}

					return $result;
				}

				private function apply_safety_filters( string $hook, mixed $value, mixed ...$args ): mixed {
					$filters = $GLOBALS['_bricks_mcp_test_safety_filters'][ $hook ] ?? [];
					ksort( $filters );
					foreach ( $filters as $callbacks ) {
						foreach ( $callbacks as [ $callback, $accepted_args ] ) {
							$value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted_args ) );
						}
					}

					return $value;
				}
			};
		}
	}
}
