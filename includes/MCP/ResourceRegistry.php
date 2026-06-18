<?php
/**
 * MCP resource registry for Bricks reference content.
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
	 * Builder guide resource URI.
	 *
	 * @var string
	 */
	private const BUILDER_GUIDE_URI = 'bricks://builder-guide';

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

		if ( ! is_array( $filtered ) ) {
			$filtered = $resources;
		}

		return $this->normalize_resources( $filtered );
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

		if ( self::BUILDER_GUIDE_URI !== $uri ) {
			return new \WP_Error(
				'unknown_resource',
				sprintf(
					/* translators: %s: Resource URI */
					__( 'Unknown resource URI: %s', 'bricks-mcp' ),
					$uri
				)
			);
		}

		$guide_path = BRICKS_MCP_PLUGIN_DIR . 'docs/BUILDER_GUIDE.md';

		if ( ! is_readable( $guide_path ) ) {
			return new \WP_Error(
				'resource_unavailable',
				__( 'Builder guide not found.', 'bricks-mcp' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin doc read.
		$content = file_get_contents( $guide_path );

		if ( false === $content ) {
			return new \WP_Error(
				'resource_read_failed',
				__( 'Failed to read builder guide.', 'bricks-mcp' )
			);
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'text/markdown',
					'text'     => $content,
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
			self::BUILDER_GUIDE_URI => array(
				'uri'         => self::BUILDER_GUIDE_URI,
				'name'        => 'Bricks Builder Guide',
				'description' => 'Markdown reference for Bricks element settings, workflows, and gotchas.',
				'mimeType'    => 'text/markdown',
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
	 * Validate and normalize resource entries after filtering.
	 *
	 * @param array<int, mixed> $resources Candidate resources.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_resources( array $resources ): array {
		$normalized = array();

		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}

			$uri         = $resource['uri'] ?? '';
			$name        = $resource['name'] ?? '';
			$description = $resource['description'] ?? '';
			$mime_type   = $resource['mimeType'] ?? '';

			if ( '' === $uri || '' === $name || '' === $description || '' === $mime_type ) {
				continue;
			}

			$normalized[] = array(
				'uri'         => (string) $uri,
				'name'        => (string) $name,
				'description' => (string) $description,
				'mimeType'    => (string) $mime_type,
			);
		}

		return array_values( $normalized );
	}
}