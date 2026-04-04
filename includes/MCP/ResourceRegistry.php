<?php
/**
 * MCP resource registry for Bricks design-system data.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

use BricksMCP\MCP\Services\BricksService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for MCP resources exposed by the plugin.
 */
final class ResourceRegistry {

	/**
	 * Bricks service.
	 *
	 * @var BricksService
	 */
	private BricksService $bricks_service;

	/**
	 * Constructor.
	 *
	 * @param BricksService $bricks_service Bricks service dependency.
	 */
	public function __construct( BricksService $bricks_service ) {
		$this->bricks_service = $bricks_service;
	}

	/**
	 * List available resources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_resources(): array {
		$resources = array_values( $this->get_default_resources() );

		/**
		 * Filter the MCP resources registry.
		 *
		 * @param array<int, array<string, mixed>> $resources Default resources.
		 */
		$filtered = apply_filters( 'bricks_mcp_resources', $resources );

		return is_array( $filtered ) ? array_values( $filtered ) : $resources;
	}

	/**
	 * Read the content for a resource URI.
	 *
	 * @param string $uri Resource URI.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read_resource( string $uri ): array|\WP_Error {
		$resource = $this->find_resource( $uri );

		if ( null === $resource ) {
			return new \WP_Error(
				'unknown_resource',
				sprintf(
					/* translators: %s: Resource URI */
					__( 'Unknown resource URI: %s', 'bricks-mcp' ),
					$uri
				)
			);
		}

		$payload = $this->read_default_resource_payload( $uri );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => $resource['mimeType'] ?? 'application/json',
					'text'     => (string) wp_json_encode( $payload, JSON_PRETTY_PRINT ),
				),
			),
		);
	}

	/**
	 * Get the built-in resources keyed by URI.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_default_resources(): array {
		return array(
			'bricks://design/colors' => array(
				'uri'         => 'bricks://design/colors',
				'name'        => 'Bricks design colors',
				'description' => 'Read the current Bricks color palettes and palette colors for this site.',
				'mimeType'    => 'application/json',
			),
			'bricks://design/breakpoints' => array(
				'uri'         => 'bricks://design/breakpoints',
				'name'        => 'Bricks breakpoints',
				'description' => 'Read the current responsive breakpoint definitions used by Bricks.',
				'mimeType'    => 'application/json',
			),
			'bricks://design/global-classes' => array(
				'uri'         => 'bricks://design/global-classes',
				'name'        => 'Bricks global classes',
				'description' => 'Read the current Bricks global CSS classes and their metadata.',
				'mimeType'    => 'application/json',
			),
			'bricks://design/variables' => array(
				'uri'         => 'bricks://design/variables',
				'name'        => 'Bricks global variables',
				'description' => 'Read the current Bricks global CSS variables and categories.',
				'mimeType'    => 'application/json',
			),
			'bricks://design/theme-styles' => array(
				'uri'         => 'bricks://design/theme-styles',
				'name'        => 'Bricks theme styles',
				'description' => 'Read the current Bricks theme styles and site-wide design settings.',
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * Find a resource definition by URI.
	 *
	 * @param string $uri Resource URI.
	 * @return array<string, mixed>|null
	 */
	private function find_resource( string $uri ): ?array {
		foreach ( $this->list_resources() as $resource ) {
			if ( ( $resource['uri'] ?? '' ) === $uri ) {
				return $resource;
			}
		}

		return null;
	}

	/**
	 * Read a built-in resource payload.
	 *
	 * @param string $uri Resource URI.
	 * @return array<string, mixed>|array<int, mixed>|\WP_Error
	 */
	private function read_default_resource_payload( string $uri ): array|\WP_Error {
		return match ( $uri ) {
			'bricks://design/colors' => $this->bricks_service->get_color_palettes(),
			'bricks://design/breakpoints' => $this->bricks_service->get_breakpoints(),
			'bricks://design/global-classes' => $this->bricks_service->get_global_classes(),
			'bricks://design/variables' => $this->bricks_service->get_global_variables(),
			'bricks://design/theme-styles' => $this->bricks_service->get_theme_styles(),
			default => new \WP_Error(
				'unknown_resource',
				sprintf(
					/* translators: %s: Resource URI */
					__( 'Unknown resource URI: %s', 'bricks-mcp' ),
					$uri
				)
			),
		};
	}
}
