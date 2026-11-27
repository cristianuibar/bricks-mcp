<?php
/**
 * Records the PHPUnit filter of each PHP process that loads the php.ini BuilderTest hands a runner.
 *
 * That file names this script as its auto_prepend_file and a log as bricks_mcp_test_log, so a
 * process launched without it, as by a launcher that drops -c, adds no line even when its default
 * php.ini loads every extension PHPUnit needs.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

file_put_contents( (string) get_cfg_var( 'bricks_mcp_test_log' ), end( $_SERVER['argv'] ) . "\n", FILE_APPEND );
