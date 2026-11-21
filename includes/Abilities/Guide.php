<?php
/**
 * Guide for the Bricks MCP Extensions abilities.
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
 * Serves the add-on guide as an MCP resource and points Bricks' start-here text to it.
 */
final class Guide implements Module {

	public const URI = 'bricks-mcp://guide';

	private const TOPICS = [
		'SEO'                           => [ 'bricks-mcp/get-seo', 'bricks-mcp/update-seo' ],
		'Post status'                   => [ 'bricks-mcp/set-post-status' ],
		'Featured image'                => [ 'bricks-mcp/set-featured-image' ],
		'Editor mode'                   => [ 'bricks-mcp/set-editor-mode' ],
		'Element moves'                 => [ 'bricks-mcp/move-element' ],
		'Template resolution'           => [ 'bricks-mcp/resolve-templates' ],
		'Global class categories'       => [ 'bricks-mcp/create-global-class-category', 'bricks-mcp/delete-global-class-category' ],
		'Unsplash photos'               => [ 'bricks-mcp/search-unsplash', 'bricks-mcp/import-unsplash-photo' ],
		'Adobe Fonts and font settings' => [ 'bricks-mcp/list-adobe-fonts', 'bricks-mcp/update-font-settings' ],
		'Page diagnostics'              => [ 'bricks-mcp/diagnose-page' ],
	];

	/**
	 * Hook into the Bricks start-here text.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'bricks/abilities/start_here_text', [ $this, 'append_start_here_pointer' ] );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return [
			'bricks-mcp/get-guide' => [
				'label'               => __( 'Bricks MCP Extensions guide', 'bricks-mcp' ),
				'description'         => __( 'Markdown guide to the bricks-mcp/* abilities: what each one does, its inputs, which Bricks ability to use for neighbouring tasks, and gotchas that the Bricks builder guide does not cover. Read it before using a bricks-mcp/* ability for the first time.', 'bricks-mcp' ),
				'category'            => 'bricks-mcp-reference',
				'output_schema'       => [ 'type' => 'string' ],
				'execute_callback'    => [ $this, 'get_guide' ],
				'permission_callback' => [ $this, 'can_read_guide' ],
				'meta'                => [
					'annotations' => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'         => [
						'type'     => 'resource',
						'uri'      => self::URI,
						'mimeType' => 'text/markdown',
					],
				],
			],
		];
	}

	/**
	 * Whether the current user may read the guide.
	 *
	 * @return true|\WP_Error
	 */
	public function can_read_guide(): bool|\WP_Error {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new \WP_Error( 'bricks_mcp_forbidden', __( 'You are not allowed to read the guide.', 'bricks-mcp' ), [ 'status' => 403 ] );
	}

	/**
	 * The guide markdown.
	 *
	 * @return string|\WP_Error
	 */
	public function get_guide(): string|\WP_Error {
		$path     = BRICKS_MCP_PLUGIN_DIR . 'includes/data/GUIDE.md';
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.

		if ( false === $contents || '' === trim( $contents ) ) {
			return new \WP_Error( 'bricks_mcp_guide_missing', __( 'The guide file is missing.', 'bricks-mcp' ), [ 'status' => 500 ] );
		}

		return $contents;
	}

	/**
	 * Append the pointer to the Bricks start-here text.
	 *
	 * @param mixed $text Markdown text from Bricks, possibly changed by other filters.
	 * @return string
	 */
	public function append_start_here_pointer( mixed $text ): string {
		$text = rtrim( is_string( $text ) ? $text : '' );
		$body = $this->pointer_lines();

		return [] === $body ? $text . "\n" : $text . "\n\n" . implode( "\n", array_merge( [ '## Bricks MCP Extensions', '' ], $body ) ) . "\n";
	}

	/**
	 * Pointer lines for the abilities an administrator left enabled.
	 *
	 * @return string[]
	 */
	private function pointer_lines(): array {
		$lines = [];

		foreach ( self::TOPICS as $topic => $names ) {
			$enabled = array_filter( $names, [ Options::class, 'is_ability_enabled' ] );

			if ( [] !== $enabled ) {
				$lines[] = '- ' . $topic . ': ' . implode( ', ', array_map( static fn( string $name ): string => '`' . $name . '`', $enabled ) );
			}
		}

		if ( [] === $lines ) {
			return [];
		}

		array_unshift( $lines, 'The Bricks MCP Extensions plugin adds these abilities for what Bricks does not cover:' );

		if ( Options::is_ability_enabled( 'bricks-mcp/diagnose-page' ) ) {
			$lines[] = 'Run `bricks-mcp/diagnose-page` before editing an existing page and after a large write.';
		}

		return array_merge( $lines, $this->reference_lines() );
	}

	/**
	 * Lines on how to reach the guide resource and the audit prompt.
	 *
	 * @return string[]
	 */
	private function reference_lines(): array {
		$guide  = Options::is_ability_enabled( 'bricks-mcp/get-guide' );
		$prompt = Options::is_ability_enabled( 'bricks-mcp/audit-page' );
		$lines  = [];

		if ( $guide ) {
			$lines[] = 'Read the guide for the inputs and gotchas of each ability: MCP resource `' . self::URI . '`, or ability `bricks-mcp/get-guide`.';
		}

		if ( $prompt ) {
			$lines[] = 'Run the page audit with MCP prompt `bricks-mcp-audit-page` (ability `bricks-mcp/audit-page`, arguments `postId` and optional `focus`).';
		}

		if ( $guide || $prompt ) {
			$lines[] = 'Resources and prompts do not appear in `mcp-adapter-discover-abilities`. If your client cannot open them directly, call `mcp-adapter-get-ability-info` or `mcp-adapter-execute-ability` with the ability name above.';
		}

		return $lines;
	}
}
