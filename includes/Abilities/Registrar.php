<?php
/**
 * Registers the plugin's abilities with the WordPress Abilities API.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

use BricksMCP\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers ability categories and the abilities of every module.
 */
final class Registrar {

	public const CATEGORIES = [
		'bricks-mcp-content'   => 'SEO, post status, featured images and editor mode.',
		'bricks-mcp-builder'   => 'Element moves, template resolution and global class categories.',
		'bricks-mcp-media'     => 'Unsplash photos and font settings.',
		'bricks-mcp-qa'        => 'Page diagnostics and audits.',
		'bricks-mcp-reference' => 'Guide for the Bricks MCP Extensions abilities.',
	];

	public const MODULES = [
		Content::class,
		Builder::class,
		Media::class,
		Quality::class,
		Guide::class,
	];

	/**
	 * Hook registration into the Abilities API lifecycle.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		foreach ( self::CATEGORIES as $slug => $description ) {
			wp_register_ability_category(
				$slug,
				[
					'label'       => self::category_label( $slug ),
					'description' => $description,
				]
			);
		}
	}

	/**
	 * Register every enabled ability.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		foreach ( self::definitions() as $name => $args ) {
			if ( Options::is_ability_enabled( $name ) ) {
				wp_register_ability( $name, self::prepare( $args ) );
			}
		}
	}

	/**
	 * All ability definitions from every module, including disabled ones.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$definitions = [];

		foreach ( self::MODULES as $module ) {
			$definitions += ( new $module() )->abilities();
		}

		return $definitions;
	}

	/**
	 * Expose the ability to clients, including the MCP Adapter.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>
	 */
	public static function prepare( array $args ): array {
		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : [];

		$meta['public']       = true;
		$meta['show_in_rest'] = $meta['show_in_rest'] ?? true;
		$args['meta']         = $meta;

		return $args;
	}

	/**
	 * Human label for a category slug.
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	private static function category_label( string $slug ): string {
		$labels = [
			'bricks-mcp-content'   => __( 'Bricks MCP: Content', 'bricks-mcp' ),
			'bricks-mcp-builder'   => __( 'Bricks MCP: Builder', 'bricks-mcp' ),
			'bricks-mcp-media'     => __( 'Bricks MCP: Media & fonts', 'bricks-mcp' ),
			'bricks-mcp-qa'        => __( 'Bricks MCP: Quality', 'bricks-mcp' ),
			'bricks-mcp-reference' => __( 'Bricks MCP: Reference', 'bricks-mcp' ),
		];

		return $labels[ $slug ];
	}
}
