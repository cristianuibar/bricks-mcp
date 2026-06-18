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
	 * Audit page prompt name.
	 *
	 * @var string
	 */
	private const AUDIT_PAGE_PROMPT = 'bricks-audit-page';

	/**
	 * Allowed audit focus values.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FOCUS_VALUES = array( 'structure', 'accessibility', 'performance' );

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

		if ( ! is_array( $filtered ) ) {
			$filtered = $prompts;
		}

		return $this->normalize_prompts( $filtered );
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
			self::AUDIT_PAGE_PROMPT => array(
				'name'        => self::AUDIT_PAGE_PROMPT,
				'description' => 'Audit a Bricks page for structure, accessibility, and design-system consistency before publishing.',
				'arguments'   => array(
					array(
						'name'        => 'page_id',
						'description' => 'Bricks page ID to audit',
						'required'    => false,
					),
					array(
						'name'        => 'focus',
						'description' => 'Audit focus area',
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
	 * Build prompt text for a named workflow.
	 *
	 * @param string               $name      Prompt name.
	 * @param array<string, mixed> $arguments Prompt arguments.
	 * @return string
	 */
	private function build_prompt_text( string $name, array $arguments ): string {
		if ( self::AUDIT_PAGE_PROMPT !== $name ) {
			return '';
		}

		$page_id = isset( $arguments['page_id'] ) ? (int) $arguments['page_id'] : 0;
		$focus   = '';

		if ( isset( $arguments['focus'] ) ) {
			$candidate = sanitize_text_field( (string) $arguments['focus'] );
			if ( in_array( $candidate, self::ALLOWED_FOCUS_VALUES, true ) ) {
				$focus = $candidate;
			}
		}

		$lines = array(
			'Audit a Bricks Builder page before publishing.',
			'',
			'Use existing MCP tools only:',
			'- Call the content tool to load and inspect the target page structure and elements.',
			'- Call get_builder_guide to verify element settings, CSS gotchas, and workflow patterns.',
			'',
			'Review for:',
			'- Structure: semantic hierarchy, section/container nesting, and responsive layout consistency.',
			'- Accessibility: heading order, link/button labels, contrast risks, and keyboard-friendly patterns.',
			'- Design-system consistency: global classes, theme styles, breakpoints, and repeated component usage.',
			'- Performance: avoid unnecessary nested containers, heavy animations, and redundant elements.',
		);

		if ( $page_id > 0 ) {
			$lines[] = '';
			$lines[] = 'Target page ID: ' . $page_id . '.';
		}

		if ( '' !== $focus ) {
			$lines[] = 'Primary audit focus: ' . $focus . '.';
		} else {
			$lines[] = '';
			$lines[] = 'If no focus is provided, cover structure, accessibility, and performance.';
		}

		$lines[] = '';
		$lines[] = 'Return a concise audit report with prioritized findings and concrete remediation steps.';

		return implode( "\n", $lines );
	}

	/**
	 * Validate and normalize prompt entries after filtering.
	 *
	 * @param array<int, mixed> $prompts Candidate prompts.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_prompts( array $prompts ): array {
		$normalized = array();

		foreach ( $prompts as $prompt ) {
			if ( ! is_array( $prompt ) ) {
				continue;
			}

			$name        = $prompt['name'] ?? '';
			$description = $prompt['description'] ?? '';

			if ( '' === $name || '' === $description ) {
				continue;
			}

			$entry = array(
				'name'        => (string) $name,
				'description' => (string) $description,
			);

			if ( isset( $prompt['arguments'] ) && is_array( $prompt['arguments'] ) ) {
				$entry['arguments'] = $prompt['arguments'];
			}

			$normalized[] = $entry;
		}

		return array_values( $normalized );
	}
}