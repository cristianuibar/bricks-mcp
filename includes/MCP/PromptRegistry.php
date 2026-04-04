<?php
/**
 * MCP prompt registry for structured Bricks workflows.
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
 * Registry for MCP prompts exposed by the plugin.
 */
final class PromptRegistry {

	/**
	 * Optional Bricks service for contextual summaries.
	 *
	 * @var BricksService|null
	 */
	private ?BricksService $bricks_service;

	/**
	 * Constructor.
	 *
	 * @param BricksService|null $bricks_service Optional Bricks service dependency.
	 */
	public function __construct( ?BricksService $bricks_service = null ) {
		$this->bricks_service = $bricks_service;
	}

	/**
	 * List available prompts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_prompts(): array {
		$prompts = array_values( $this->get_default_prompts() );

		/**
		 * Filter the MCP prompts registry.
		 *
		 * @param array<int, array<string, mixed>> $prompts Default prompts.
		 */
		$filtered = apply_filters( 'bricks_mcp_prompts', $prompts );

		return is_array( $filtered ) ? array_values( $filtered ) : $prompts;
	}

	/**
	 * Get a prompt by name with optional arguments.
	 *
	 * @param string               $name      Prompt name.
	 * @param array<string, mixed> $arguments Prompt arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_prompt( string $name, array $arguments = array() ): array|\WP_Error {
		$prompt = $this->find_prompt( $name );

		if ( null === $prompt ) {
			return new \WP_Error(
				'unknown_prompt',
				sprintf(
					/* translators: %s: Prompt name */
					__( 'Unknown prompt: %s', 'bricks-mcp' ),
					$name
				)
			);
		}

		return array(
			'description' => $prompt['description'],
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $this->build_prompt_text( $name, $arguments ),
					),
				),
			),
		);
	}

	/**
	 * Get default prompt definitions keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_prompts(): array {
		return array(
			'build_landing_page' => array(
				'name'        => 'build_landing_page',
				'description' => 'Create a conversion-focused landing page that matches the site design system.',
				'arguments'   => array(
					array(
						'name'        => 'title',
						'description' => 'Landing page title or campaign name.',
						'required'    => false,
					),
					array(
						'name'        => 'audience',
						'description' => 'Primary audience or customer segment for the page.',
						'required'    => false,
					),
				),
			),
			'build_template' => array(
				'name'        => 'build_template',
				'description' => 'Create or refine a Bricks template while preserving the existing site-wide design language.',
				'arguments'   => array(
					array(
						'name'        => 'template_type',
						'description' => 'Template type such as header, footer, archive, section, or popup.',
						'required'    => false,
					),
					array(
						'name'        => 'goal',
						'description' => 'What the template should accomplish for the user or site.',
						'required'    => false,
					),
				),
			),
			'audit_page' => array(
				'name'        => 'audit_page',
				'description' => 'Review an existing Bricks page for structure, consistency, accessibility, and content quality.',
				'arguments'   => array(
					array(
						'name'        => 'post_id',
						'description' => 'Numeric WordPress post ID to audit.',
						'required'    => false,
					),
				),
			),
			'migrate_content' => array(
				'name'        => 'migrate_content',
				'description' => 'Move existing content into Bricks while preserving hierarchy, intent, and reusable design tokens.',
				'arguments'   => array(
					array(
						'name'        => 'source',
						'description' => 'Source description such as classic editor, another builder, or imported HTML.',
						'required'    => false,
					),
					array(
						'name'        => 'destination',
						'description' => 'Target post, template, or content area to migrate into.',
						'required'    => false,
					),
				),
			),
		);
	}

	/**
	 * Find a prompt definition by name.
	 *
	 * @param string $name Prompt name.
	 * @return array<string, mixed>|null
	 */
	private function find_prompt( string $name ): ?array {
		foreach ( $this->list_prompts() as $prompt ) {
			if ( ( $prompt['name'] ?? '' ) === $name ) {
				return $prompt;
			}
		}

		return null;
	}

	/**
	 * Build a contextual prompt message.
	 *
	 * @param string               $name      Prompt name.
	 * @param array<string, mixed> $arguments Prompt arguments.
	 * @return string
	 */
	private function build_prompt_text( string $name, array $arguments ): string {
		$context = $this->build_design_context_summary();

		return match ( $name ) {
			'build_landing_page' => sprintf(
				"Build a Bricks landing page%s%s. Focus on message clarity, hierarchy, and reuse of the site's current design tokens. %sReturn content that is ready for implementation in Bricks without narrating tool-call steps.",
				$this->build_subject_suffix( $arguments['title'] ?? null, ' titled "%s"' ),
				$this->build_subject_suffix( $arguments['audience'] ?? null, ' for the audience "%s"' ),
				$context
			),
			'build_template' => sprintf(
				"Create or refine a Bricks template%s%s. Keep the output aligned with the existing site-wide design system and template conventions. %sDescribe the intended structure, constraints, and content guidance instead of enumerating tool calls.",
				$this->build_subject_suffix( $arguments['template_type'] ?? null, ' of type "%s"' ),
				$this->build_subject_suffix( $arguments['goal'] ?? null, ' with the goal "%s"' ),
				$context
			),
			'audit_page' => sprintf(
				'Audit the Bricks page%s for structural quality, design-system consistency, accessibility, and obvious content issues. %sReport findings as concrete improvements to make in the page, not as tool instructions.',
				$this->build_subject_suffix( $arguments['post_id'] ?? null, ' with post ID %s' ),
				$context
			),
			'migrate_content' => sprintf(
				"Migrate content%s%s into Bricks while preserving hierarchy, semantics, and reusable tokens. %sProduce guidance that explains the target information architecture and component mapping without turning the prompt into a scripted tool sequence.",
				$this->build_subject_suffix( $arguments['source'] ?? null, ' from "%s"' ),
				$this->build_subject_suffix( $arguments['destination'] ?? null, ' into "%s"' ),
				$context
			),
			default => '',
		};
	}

	/**
	 * Build a short suffix when an argument is present.
	 *
	 * @param mixed  $value  Raw argument value.
	 * @param string $format sprintf-compatible format.
	 * @return string
	 */
	private function build_subject_suffix( mixed $value, string $format ): string {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return '';
		}

		return sprintf( $format, trim( (string) $value ) );
	}

	/**
	 * Build a compact summary of the current Bricks design system.
	 *
	 * @return string
	 */
	private function build_design_context_summary(): string {
		if ( null === $this->bricks_service ) {
			return '';
		}

		$theme_styles   = $this->bricks_service->get_theme_styles();
		$color_palettes = $this->bricks_service->get_color_palettes();
		$breakpoints    = $this->bricks_service->get_breakpoints();

		$theme_style_count = is_array( $theme_styles ) ? count( $theme_styles ) : 0;
		$palette_count     = is_array( $color_palettes ) ? count( $color_palettes ) : 0;
		$breakpoint_count  = is_array( $breakpoints ) ? count( $breakpoints ) : 0;

		if ( 0 === $theme_style_count && 0 === $palette_count && 0 === $breakpoint_count ) {
			return '';
		}

		return sprintf(
			'Current site context: %1$d theme styles, %2$d color palettes, and %3$d responsive breakpoints are available for reference. ',
			$theme_style_count,
			$palette_count,
			$breakpoint_count
		);
	}
}
