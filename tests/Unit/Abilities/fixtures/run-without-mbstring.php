<?php
/**
 * Runs category-without-mbstring.php in a child PHP process with every mbstring function disabled.
 *
 * Executed by BuilderTest with -n and the parent's disabled functions. -n drops a shared mbstring and
 * disable_functions removes a compiled-in one. The child's -d replaces this process's list, and a
 * function disabled here is missing from get_extension_funcs(), so the child gets this process's
 * list back plus every mbstring function still callable here. Passes the child's output and exit
 * status through with exec(), the one process function BuilderTest already needs to start this script.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

$bricks_mcp_disabled = implode( ',', [ ini_get( 'disable_functions' ), ...( get_extension_funcs( 'mbstring' ) ?: [] ) ] );
$bricks_mcp_output = [];
$bricks_mcp_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -n -d ' . escapeshellarg( 'disable_functions=' . $bricks_mcp_disabled ) . ' ' . escapeshellarg( __DIR__ . '/category-without-mbstring.php' ) . ' 2>&1', $bricks_mcp_output, $bricks_mcp_status );
echo implode( "\n", $bricks_mcp_output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Relays the child's JSON to the test.
exit( $bricks_mcp_status );
