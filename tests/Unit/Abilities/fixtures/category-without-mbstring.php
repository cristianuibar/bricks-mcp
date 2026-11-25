<?php
/**
 * Loads the unit test bootstrap without mbstring and creates categories next to stored legacy names.
 *
 * Executed by run-without-mbstring.php in a PHP process started with -n and every mbstring
 * function disabled, so none is callable even when mbstring is compiled in. Only WordPress's
 * compat mb_strlen() is defined, as on a site without the extension. Prints a JSON result with
 * the internal mb_* functions still available, the functions BuilderTest disabled (listed in
 * BRICKS_MCP_RESTRICTED) that are still callable, each ability reply and the stored category names.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! function_exists( 'mb_strlen' ) ) {
	/**
	 * Like WordPress's compat mb_strlen() for a UTF-8 blog: counts UTF-8 characters.
	 *
	 * @param string      $str      Text to count.
	 * @param string|null $encoding Ignored; the blog charset is UTF-8.
	 * @return int Character count.
	 */
	function mb_strlen( string $str, ?string $encoding = null ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Matches core's signature.
		return (int) preg_match_all( '/./us', $str );
	}
}

require dirname( __DIR__, 3 ) . '/bootstrap-simple.php';

foreach ( ( new BricksMCP\Abilities\Builder( BricksMCP\Tests\Support\BuilderDatabase::class ) )->abilities() as $bricks_mcp_name => $bricks_mcp_args ) {
	wp_register_ability( $bricks_mcp_name, $bricks_mcp_args );
}

update_option( 'bricks_global_classes_categories', [ [ 'id' => 'broken', 'name' => "Lay\xC3out" ], [ 'id' => 'legacy', 'name' => 'Existing' ] ] );

$bricks_mcp_results = [];
foreach ( [ 'EXISTING', 'Layout' ] as $bricks_mcp_category ) {
	$bricks_mcp_result    = wp_get_ability( 'bricks-mcp/create-global-class-category' )->execute( [ 'name' => $bricks_mcp_category ] );
	$bricks_mcp_results[] = is_wp_error( $bricks_mcp_result ) ? $bricks_mcp_result->get_error_code() : $bricks_mcp_result['name'];
}

echo wp_json_encode(
	[
		'mbstring'   => array_values( array_filter( get_defined_functions()['internal'], static fn( string $bricks_mcp_function ): bool => str_starts_with( $bricks_mcp_function, 'mb_' ) ) ),
		'restricted' => array_values( array_intersect( array_map( 'strtolower', array_map( 'trim', explode( ',', (string) getenv( 'BRICKS_MCP_RESTRICTED' ) ) ) ), get_defined_functions()['internal'] ) ),
		'results'    => $bricks_mcp_results,
		'categories' => array_map( 'bin2hex', array_column( get_option( 'bricks_global_classes_categories', [] ), 'name' ) ),
	]
);
