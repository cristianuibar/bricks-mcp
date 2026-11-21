<?php
/**
 * PSR-4 compatible autoloader for the Bricks MCP plugin.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class.
 *
 * Provides PSR-4 compatible autoloading without Composer.
 * Maps the BricksMCP namespace to the includes directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix for the plugin.
	 *
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'BricksMCP\\';

	/**
	 * Base directory for the namespace.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Cache of loaded class file paths.
	 *
	 * @var array<string, string>
	 */
	private static array $class_map = [];

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		self::$base_dir = BRICKS_MCP_PLUGIN_DIR . 'includes/';
		spl_autoload_register( [ self::class, 'load_class' ] );
	}

	/**
	 * Unregister the autoloader.
	 *
	 * @return void
	 */
	public static function unregister(): void {
		spl_autoload_unregister( [ self::class, 'load_class' ] );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name The fully-qualified class name.
	 * @return void
	 */
	public static function load_class( string $class_name ): void {
		// Check if class uses our namespace prefix.
		$prefix_length = strlen( self::NAMESPACE_PREFIX );
		if ( strncmp( self::NAMESPACE_PREFIX, $class_name, $prefix_length ) !== 0 ) {
			return;
		}

		// Check cache first.
		if ( isset( self::$class_map[ $class_name ] ) ) {
			require self::$class_map[ $class_name ];
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $class_name, $prefix_length );

		// Replace namespace separators with directory separators.
		$file = self::$base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// If the file exists, require it.
		if ( file_exists( $file ) ) {
			self::$class_map[ $class_name ] = $file;
			require $file;
		}
	}

	/**
	 * Get the class map cache.
	 *
	 * Useful for debugging.
	 *
	 * @return array<string, string> The class map.
	 */
	public static function get_class_map(): array {
		return self::$class_map;
	}
}
