<?php
/**
 * Loads the unit test bootstrap and creates a category whose name is exactly at the length limit.
 *
 * Executed in its own PHP process by BuilderTest, because the bootstrap sets the mbstring
 * encoding once per process. Prints a JSON result with the ability reply and the stored
 * categories.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Start in a non-UTF-8 encoding even when the runner's ini sets internal_encoding to UTF-8, so only the bootstrap can fix it.
mb_internal_encoding( 'ISO-8859-1' );

require dirname( __DIR__, 3 ) . '/bootstrap-simple.php';

foreach ( ( new BricksMCP\Abilities\Builder( BricksMCP\Tests\Support\BuilderDatabase::class ) )->abilities() as $bricks_mcp_name => $bricks_mcp_args ) {
	wp_register_ability( $bricks_mcp_name, $bricks_mcp_args );
}

$bricks_mcp_result = wp_get_ability( 'bricks-mcp/create-global-class-category' )->execute( [ 'name' => str_repeat( 'ș', 100 ) ] );

echo wp_json_encode(
	[
		'result'     => is_wp_error( $bricks_mcp_result ) ? $bricks_mcp_result->get_error_code() : $bricks_mcp_result['name'],
		'categories' => array_column( get_option( 'bricks_global_classes_categories', [] ), 'name' ),
	]
);
