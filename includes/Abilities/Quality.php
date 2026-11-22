<?php
/**
 * Page diagnostics and the page audit prompt.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lints stored Bricks element data and provides the audit workflow prompt.
 *
 * Checks that Bricks enforces on every write (unknown element types, invalid parent references,
 * duplicate ids, query, image and link shapes, circular references) are not repeated here.
 */
final class Quality implements Module {

	private const AREAS = [ 'content', 'header', 'footer' ];

	private const FOCUS_VALUES = [ 'all', 'seo', 'accessibility', 'performance', 'design' ];

	private const PAGE_SIZE = 200;

	private const MAX_PAGES = 50;

	private const SCRIPT_KEYS = [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ];

	private const TOKEN_KEYS = [ '_background', '_typography', '_border', '_color', '_gradient' ];

	private const LAYOUT_ELEMENTS = [ 'section', 'container', 'div', 'block' ];

	private const PRINTABLE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}\z/';

	private const INVALID_ID = '(invalid id)';

	private const REPLACED_KEYS = [
		'_maxWidth'          => [ '_widthMax', 'max-width' ],
		'_textAlign'         => [ '_typography["text-align"]', 'text-align' ],
		'_animation'         => [ '_interactions', 'animations' ],
		'_animationDuration' => [ '_interactions', 'animations' ],
		'_animationDelay'    => [ '_interactions', 'animations' ],
	];

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return [
			'bricks-mcp/diagnose-page' => $this->diagnose_page_definition(),
			'bricks-mcp/audit-page'    => $this->audit_page_definition(),
		];
	}

	/**
	 * Definition of the diagnose-page ability.
	 *
	 * @return array<string, mixed>
	 */
	private function diagnose_page_definition(): array {
		return [
			'label'               => __( 'Diagnose Bricks page', 'bricks-mcp' ),
			'description'         => __( 'Lint the stored element data of one page, header or footer template and list problems with the element id, severity, message and the Bricks ability that fixes it. Run it before editing an existing page and again after a large write. Catches %root% in custom CSS, wrong setting keys (_maxWidth, _textAlign, _animation), hex colors that duplicate a palette color or variable, missing script tags, references to global classes that do not exist, unknown breakpoint keys, missing mobile padding, WP_Query code without wp_reset_postdata(), children that point to missing elements and header or footer content stored in the page content meta. A check whose reference data could not be read completely (global classes, breakpoints) is skipped and reported as an info note instead of guessing. Read-only: apply fixes with bricks/update-element, bricks/update-element-interactions, bricks/create-global-class or bricks/set-page-settings. Structure errors that Bricks rejects on write (unknown element types, invalid parents, duplicate ids) are not repeated here.', 'bricks-mcp' ),
			'category'            => 'bricks-mcp-qa',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page, post or template ID.', 'bricks-mcp' ),
					],
					'area'   => [
						'type'        => 'string',
						'enum'        => self::AREAS,
						'description' => __( 'Optional guard: the area you expect the post to hold. Header and footer templates are detected automatically; a mismatch returns an error.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'postId' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'       => [ 'type' => 'integer' ],
					'title'        => [ 'type' => 'string' ],
					'area'         => [
						'type' => 'string',
						'enum' => self::AREAS,
					],
					'elementCount' => [ 'type' => 'integer' ],
					'issueCount'   => [ 'type' => 'integer' ],
					'errorCount'   => [ 'type' => 'integer' ],
					'warningCount' => [ 'type' => 'integer' ],
					'infoCount'    => [ 'type' => 'integer' ],
					'status'       => [
						'type' => 'string',
						'enum' => [ 'clean', 'warnings', 'errors' ],
					],
					'issues'       => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'elementId' => [ 'type' => [ 'string', 'null' ] ],
								'severity'  => [
									'type' => 'string',
									'enum' => [ 'error', 'warning', 'info' ],
								],
								'type'      => [ 'type' => 'string' ],
								'message'   => [ 'type' => 'string' ],
								'fix'       => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
			'execute_callback'    => [ $this, 'diagnose_page' ],
			'permission_callback' => static fn( $input = null ) => Access::edit_post( (int) ( is_array( $input ) ? ( $input['postId'] ?? 0 ) : 0 ) ),
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Definition of the audit-page prompt.
	 *
	 * @return array<string, mixed>
	 */
	private function audit_page_definition(): array {
		return [
			'label'               => __( 'Audit Bricks page', 'bricks-mcp' ),
			'description'         => __( 'Prompt that walks an agent through a pre-publish audit of one page: diagnostics, structure, SEO, accessibility, performance and design-system consistency. Findings only; nothing is changed.', 'bricks-mcp' ),
			'category'            => 'bricks-mcp-qa',
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Page, post or template ID to audit.', 'bricks-mcp' ),
					],
					'focus'  => [
						'type'        => 'string',
						'enum'        => self::FOCUS_VALUES,
						'default'     => 'all',
						'description' => __( 'Audit focus. Defaults to all.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'postId' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'messages' => [
						'type'  => 'array',
						'items' => [ 'type' => 'object' ],
					],
				],
			],
			'execute_callback'    => [ $this, 'audit_page' ],
			'permission_callback' => static fn( $input = null ) => Access::edit_post( (int) ( is_array( $input ) ? ( $input['postId'] ?? 0 ) : 0 ) ),
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'mcp'         => [
					'type' => 'prompt',
				],
			],
		];
	}

	/**
	 * Lint one page and return the report.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function diagnose_page( array $input ): array|\WP_Error {
		$post_id = (int) ( $input['postId'] ?? 0 );
		$area    = $this->area_of( $post_id );

		if ( isset( $input['area'] ) && $input['area'] !== $area ) {
			return new \WP_Error(
				'bricks_mcp_area_mismatch',
				/* translators: 1: requested area, 2: detected area */
				sprintf( __( 'Post %1$d holds %2$s content, not %3$s.', 'bricks-mcp' ), $post_id, $area, (string) $input['area'] ),
				[ 'status' => 400 ]
			);
		}

		$read = Access::run_bricks_ability( 'bricks/get-page-elements', [ 'postId' => $post_id ] );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		$elements = isset( $read['elements'] ) && is_array( $read['elements'] ) ? array_values( array_filter( $read['elements'], 'is_array' ) ) : [];
		$context  = $this->load_context( $elements );
		$issues   = array_merge(
			$this->lint_area_meta( $post_id, $area ),
			$this->lint_page_settings( $post_id ),
			$this->lint_elements( $elements, $context ),
			$this->skipped_checks( $context, $elements )
		);
		$errors   = $this->count_severity( $issues, 'error' );
		$warnings = $this->count_severity( $issues, 'warning' );
		$post     = get_post( $post_id );

		return [
			'postId'       => $post_id,
			'title'        => $post ? (string) $post->post_title : '',
			'area'         => $area,
			'elementCount' => count( $elements ),
			'issueCount'   => count( $issues ),
			'errorCount'   => $errors,
			'warningCount' => $warnings,
			'infoCount'    => count( $issues ) - $errors - $warnings,
			'status'       => $errors > 0 ? 'errors' : ( $warnings > 0 ? 'warnings' : 'clean' ),
			'issues'       => $issues,
		];
	}

	/**
	 * Build the audit prompt messages.
	 *
	 * @param array<string, mixed> $input Prompt arguments.
	 * @return array<string, mixed>
	 */
	public function audit_page( array $input ): array {
		$post_id = (int) ( $input['postId'] ?? 0 );
		$focus   = in_array( $input['focus'] ?? 'all', self::FOCUS_VALUES, true ) ? (string) ( $input['focus'] ?? 'all' ) : 'all';

		$lines = [
			sprintf( 'Audit Bricks page %d before it goes live. Focus: %s.', $post_id, $focus ),
			'',
			'Work through these steps with the abilities named here:',
			sprintf( '1. Call bricks-mcp/diagnose-page with postId %d. Keep every issue with its element id and the fix it names.', $post_id ),
			sprintf( '2. Call bricks/get-page-elements with postId %d and responseFormat "summary" for the structure and heading outline. Request detailed settings only for an element you need to inspect.', $post_id ),
		];

		foreach ( $this->focus_steps( $focus ) as $index => $step ) {
			$lines[] = ( $index + 3 ) . '. ' . $step;
		}

		$lines[] = '';
		$lines[] = 'Return a short report ordered by severity: finding, element id, proposed fix and the ability that applies it. Change nothing unless the user asks for it.';

		return [
			'messages' => [
				[
					'role'    => 'user',
					'content' => [
						'type' => 'text',
						'text' => implode( "\n", $lines ),
					],
				],
			],
		];
	}

	/**
	 * Audit steps for a focus value.
	 *
	 * @param string $focus Focus value.
	 * @return string[]
	 */
	private function focus_steps( string $focus ): array {
		$steps = [
			'seo'           => 'SEO: call bricks-mcp/get-seo. Title should be 30-60 characters, description 120-160, an Open Graph image set, one h1 on the page. Propose changes with bricks-mcp/update-seo.',
			'accessibility' => 'Accessibility: check heading order in the outline, a text or aria-label on every link and button, alt text on images, and low-contrast color pairs. Fix elements with bricks/update-element.',
			'performance'   => 'Performance: look for needless nested containers, oversized images, heavy interactions and query loops without a limit. Use bricks/get-element-interactions for animations.',
			'design'        => 'Design system: call bricks/audit-design-system for site-wide rot, then compare this page with bricks/get-design-context. Replace hardcoded colors and sizes with palette colors, variables and global classes (bricks/create-global-class when one is missing).',
		];

		return 'all' === $focus ? array_values( $steps ) : [ $steps[ $focus ] ];
	}

	/**
	 * Area (content, header or footer) Bricks stores the post's elements in.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function area_of( int $post_id ): string {
		if ( 'bricks_template' !== get_post_type( $post_id ) ) {
			return 'content';
		}

		$type = get_post_meta( $post_id, '_bricks_template_type', true );

		return in_array( $type, [ 'header', 'footer' ], true ) ? $type : 'content';
	}

	/**
	 * Reference data the element checks compare against; null entries mean the data is unknown and the checks that need it are skipped.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return array<string, mixed>
	 */
	private function load_context( array $elements ): array {
		$breakpoints = Access::run_bricks_ability( 'bricks/list-breakpoints', [] );
		$pseudo      = Access::run_bricks_ability( 'bricks/list-pseudo-classes', [] );
		$keys        = [];

		if ( ! is_wp_error( $breakpoints ) && isset( $breakpoints['breakpoints'] ) && is_array( $breakpoints['breakpoints'] ) ) {
			$keys = array_values( array_filter( array_column( $breakpoints['breakpoints'], 'key' ), 'is_string' ) );
		}

		$pseudo_classes = [];

		if ( ! is_wp_error( $pseudo ) && isset( $pseudo['pseudoClasses'] ) && is_array( $pseudo['pseudoClasses'] ) ) {
			$pseudo_classes = array_map( static fn( $value ): string => ltrim( (string) $value, ':' ), array_filter( $pseudo['pseudoClasses'], 'is_string' ) );
		}

		return [
			'classIds'      => $this->load_class_ids( $elements ),
			'breakpoints'   => [] === $keys ? null : $keys,
			'pseudoClasses' => [] === $pseudo_classes ? null : $pseudo_classes,
			'tokens'        => $this->color_tokens( $elements ),
		];
	}

	/**
	 * Ids of every global class, read only when an element references one.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return array<string, int>|null Null when the full list could not be read.
	 */
	private function load_class_ids( array $elements ): ?array {
		if ( ! $this->uses_global_classes( $elements ) ) {
			return [];
		}

		$classes = $this->read_items( 'bricks/list-global-classes' );

		return $classes['complete'] ? array_flip( array_filter( array_column( $classes['items'], 'id' ), 'is_string' ) ) : null;
	}

	/**
	 * Whether any element references a global class.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return bool
	 */
	private function uses_global_classes( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! empty( $this->settings_of( $element )['_cssGlobalClasses'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Info notes for checks that were skipped because their reference data is unknown.
	 *
	 * @param array<string, mixed>             $context  Reference data.
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function skipped_checks( array $context, array $elements ): array {
		$notes = [];

		if ( null === $context['classIds'] ) {
			$notes[] = $this->issue(
				null,
				'info',
				'class_check_skipped',
				__( 'The global class list could not be read completely, so references to missing global classes were not checked.', 'bricks-mcp' ),
				'Run bricks-mcp/diagnose-page again; if it persists, check bricks/list-global-classes.'
			);
		}

		if ( null === $context['breakpoints'] || null === $context['pseudoClasses'] ) {
			$notes[] = $this->issue(
				null,
				'info',
				'breakpoint_check_skipped',
				__( 'The breakpoint or pseudo-class list could not be read, so unknown breakpoint keys and missing mobile padding were not checked.', 'bricks-mcp' ),
				'Run bricks-mcp/diagnose-page again; if it persists, check bricks/list-breakpoints and bricks/list-pseudo-classes.'
			);
		}

		return $notes;
	}

	/**
	 * Number of issues with a severity.
	 *
	 * @param array<int, array<string, mixed>> $issues   Issues.
	 * @param string                           $severity Severity.
	 * @return int
	 */
	private function count_severity( array $issues, string $severity ): int {
		return count( array_filter( $issues, static fn( array $issue ): bool => $severity === $issue['severity'] ) );
	}

	/**
	 * Map of normalized hex colors to the palette color or variable that already holds them.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return array<string, string>
	 */
	private function color_tokens( array $elements ): array {
		if ( ! $this->uses_token_keys( $elements ) ) {
			return [];
		}

		$tokens = [];

		foreach ( $this->read_items( 'bricks/list-color-palettes' )['items'] as $palette ) {
			foreach ( (array) ( $palette['colors'] ?? [] ) as $color ) {
				$raw = $this->css_variable( (string) ( $color['raw'] ?? '' ) );
				$hex = $this->normalize_hex( (string) ( $color['light'] ?? '' ) );

				if ( '' !== $raw && '' !== $hex ) {
					$tokens[ $hex ] ??= $raw;
				}
			}
		}

		foreach ( $this->read_items( 'bricks/list-global-variables' )['items'] as $variable ) {
			$hex  = $this->normalize_hex( (string) ( $variable['value'] ?? '' ) );
			$name = ltrim( (string) ( $variable['name'] ?? '' ), '-' );

			if ( '' !== $hex && '' !== $name ) {
				$tokens[ $hex ] ??= 'var(--' . $name . ')';
			}
		}

		return $tokens;
	}

	/**
	 * Whether any element carries a setting the token check inspects.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @return bool
	 */
	private function uses_token_keys( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( array_intersect( self::TOKEN_KEYS, array_keys( $this->settings_of( $element ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read every item of a paginated Bricks list ability.
	 *
	 * @param string $ability Ability name.
	 * @return array{items: array<int, array<string, mixed>>, complete: bool} Complete is false when a page failed or the page cap was reached.
	 */
	private function read_items( string $ability ): array {
		$items    = [];
		$complete = false;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = Access::run_bricks_ability(
				$ability,
				[
					'page'    => $page,
					'perPage' => self::PAGE_SIZE,
				]
			);

			if ( is_wp_error( $result ) || ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) {
				break;
			}

			$items = array_merge( $items, array_values( array_filter( $result['items'], 'is_array' ) ) );

			if ( empty( $result['hasMore'] ) ) {
				$complete = true;
				break;
			}
		}

		return [
			'items'    => $items,
			'complete' => $complete,
		];
	}

	/**
	 * Problems with where a header or footer stores its content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $area    Area of the post.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_area_meta( int $post_id, string $area ): array {
		if ( 'content' === $area || empty( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) ) {
			return [];
		}

		return [
			$this->issue(
				null,
				'error',
				'phantom_content',
				/* translators: %s: header or footer */
				sprintf( __( 'This %s template also has content stored in the page content meta; Bricks ignores it.', 'bricks-mcp' ), $area ),
				sprintf( 'Re-save the %s elements with bricks/set-page-elements so they live in the %s area, and ask an administrator to delete the stale _bricks_page_content_2 meta.', $area, $area )
			),
		];
	}

	/**
	 * Script settings of the page itself that lack script tags.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_page_settings( int $post_id ): array {
		$result = Access::run_bricks_ability( 'bricks/get-page-settings', [ 'postId' => $post_id ] );

		if ( is_wp_error( $result ) || ! isset( $result['settings'] ) || ! is_array( $result['settings'] ) ) {
			return [];
		}

		$issues = [];

		foreach ( self::SCRIPT_KEYS as $key ) {
			if ( $this->lacks_script_tag( $result['settings'][ $key ] ?? null ) ) {
				$issues[] = $this->issue(
					null,
					'error',
					'script_missing_tags',
					/* translators: %s: setting key */
					sprintf( __( 'Page setting %s holds JavaScript without <script> tags.', 'bricks-mcp' ), $key ),
					sprintf( 'Wrap the code in <script>...</script> and save it with bricks/set-page-settings (needs the unfiltered_html capability): %s.', $key )
				);
			}
		}

		return $issues;
	}

	/**
	 * Run every element check.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements of the page.
	 * @param array<string, mixed>             $context  Reference data.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_elements( array $elements, array $context ): array {
		$ids    = array_flip( array_map( 'strval', array_filter( array_column( $elements, 'id' ), 'is_scalar' ) ) );
		$issues = [];

		foreach ( $elements as $element ) {
			$id       = $this->printable_id( $element['id'] ?? null );
			$name     = (string) ( $element['name'] ?? '' );
			$settings = $this->settings_of( $element );
			$issues   = array_merge(
				$issues,
				$this->lint_children( $id, $element, $ids ),
				$this->lint_keys( $id, $settings ),
				$this->lint_css_and_scripts( $id, $name, $settings ),
				$this->lint_colors( $id, $settings, $context['tokens'] ),
				$this->lint_class_references( $id, $settings, $context['classIds'] ),
				$this->lint_breakpoint_keys( $id, $settings, $context ),
				$this->lint_mobile_padding( $id, $name, $settings, $context['breakpoints'] )
			);
		}

		return $issues;
	}

	/**
	 * Children that point to elements that do not exist.
	 *
	 * @param string                    $id       Element ID.
	 * @param array<string, mixed>      $element  Element row.
	 * @param array<string, int|string> $ids      Known element IDs as keys.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_children( string $id, array $element, array $ids ): array {
		$issues = [];

		foreach ( (array) ( $element['children'] ?? [] ) as $child ) {
			if ( ! is_scalar( $child ) || ! isset( $ids[ (string) $child ] ) ) {
				$child    = $this->printable_id( $child );
				$issues[] = $this->issue(
					$id,
					'error',
					'orphaned_child',
					sprintf( 'Element %s lists child "%s" that does not exist.', $id, $child ),
					sprintf( 'Read the complete tree with bricks/get-page-elements and keep its documentDigest. In that tree remove "%2$s" from the children of %1$s and change nothing else. Send the complete tree back to bricks/set-page-elements with expectedDocumentDigest set to that digest. set-page-elements replaces the whole page, so never send only the repaired section.', $id, $child )
				);
			}
		}

		return $issues;
	}

	/**
	 * Setting keys that do not exist or are deprecated.
	 *
	 * @param string               $id       Element ID.
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_keys( string $id, array $settings ): array {
		$issues = [];

		foreach ( self::REPLACED_KEYS as $wrong => [ $right, $purpose ] ) {
			if ( ! array_key_exists( $wrong, $settings ) ) {
				continue;
			}

			$issues[] = $this->issue(
				$id,
				'error',
				'wrong_setting_key',
				sprintf( 'Element %s uses %s, which does not produce %s.', $id, $wrong, $purpose ),
				'_animation' === substr( $wrong, 0, 10 )
					? sprintf( 'Move the animation into _interactions with bricks/update-element-interactions for element %s and remove %s.', $id, $wrong )
					: sprintf( 'Call bricks/update-element for %s: set %s and remove %s.', $id, $right, $wrong )
			);
		}

		return $issues;
	}

	/**
	 * Custom CSS, script and query code problems.
	 *
	 * @param string               $id       Element ID.
	 * @param string               $name     Element type.
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_css_and_scripts( string $id, string $name, array $settings ): array {
		$issues = [];

		if ( is_string( $settings['_cssCustom'] ?? null ) && str_contains( $settings['_cssCustom'], '%root%' ) ) {
			$issues[] = $this->issue(
				$id,
				'error',
				'root_placeholder',
				sprintf( 'Element %s: _cssCustom still contains %%root%%.', $id ),
				sprintf( 'Resend the same _cssCustom through bricks/update-element for %s. The Bricks style normalizer resolves %%root%% to the real selector on save; do not write a selector by hand.', $id )
			);
		}

		foreach ( self::SCRIPT_KEYS as $key ) {
			if ( $this->lacks_script_tag( $settings[ $key ] ?? null ) ) {
				$issues[] = $this->issue(
					$id,
					'error',
					'script_missing_tags',
					sprintf( 'Element %1$s: %2$s has JavaScript without <script> tags.', $id, $key ),
					sprintf( 'Wrap the code in <script>...</script> with bricks/update-element for %s.', $id )
				);
			}
		}

		$code = $this->code_of( $settings );

		if ( 'code' === $name && str_contains( $code, 'WP_Query' ) && ! str_contains( $code, 'wp_reset_postdata' ) ) {
			$issues[] = $this->issue(
				$id,
				'warning',
				'missing_wp_reset_postdata',
				sprintf( 'Element %s runs a WP_Query without wp_reset_postdata().', $id ),
				'Add wp_reset_postdata() after the loop in the builder. Code changes need an administrator with code execution rights; Bricks abilities reject code writes from agents.'
			);
		}

		return $issues;
	}

	/**
	 * Colors stored as plain strings and hex values that duplicate a palette color or variable.
	 *
	 * @param string                $id       Element ID.
	 * @param array<string, mixed>  $settings Element settings.
	 * @param array<string, string> $tokens   Hex to CSS variable map.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_colors( string $id, array $settings, array $tokens ): array {
		$issues = [];

		foreach ( [ 'color', 'iconColor' ] as $key ) {
			if ( is_string( $settings[ $key ] ?? null ) && '' !== $this->normalize_hex( $settings[ $key ] ) ) {
				$issues[] = $this->issue(
					$id,
					'warning',
					'plain_string_color',
					sprintf( 'Element %1$s: %2$s is the plain string %3$s.', $id, $key, $settings[ $key ] ),
					sprintf( 'Call bricks/update-element for %1$s and set %2$s to {"hex":"%3$s"}, or to a palette color {"raw":"var(--...)"} from bricks/list-color-palettes.', $id, $key, $settings[ $key ] )
				);
			}
		}

		$json = '';

		foreach ( self::TOKEN_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$json .= (string) wp_json_encode( $settings[ $key ] );
			}
		}

		if ( [] === $tokens || '' === $json ) {
			return $issues;
		}

		preg_match_all( '/#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/', $json, $matches );

		foreach ( array_unique( $matches[0] ) as $found ) {
			$variable = $tokens[ $this->normalize_hex( $found ) ] ?? null;

			if ( null !== $variable ) {
				$issues[] = $this->issue(
					$id,
					'warning',
					'hardcoded_design_token',
					sprintf( 'Element %1$s: %2$s duplicates %3$s.', $id, $found, $variable ),
					sprintf( 'Call bricks/update-element for %1$s and replace %2$s with {"raw":"%3$s"}.', $id, $found, $variable )
				);
			}
		}

		return $issues;
	}

	/**
	 * Global class ids that no longer exist.
	 *
	 * @param string                  $id        Element ID.
	 * @param array<string, mixed>    $settings  Element settings.
	 * @param array<string, int>|null $class_ids Known class ids as keys; null when unknown.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_class_references( string $id, array $settings, ?array $class_ids ): array {
		if ( null === $class_ids || empty( $settings['_cssGlobalClasses'] ) ) {
			return [];
		}

		$used   = is_array( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : explode( ' ', (string) $settings['_cssGlobalClasses'] );
		$issues = [];

		foreach ( $used as $stored ) {
			$class_id = is_scalar( $stored ) ? trim( (string) $stored ) : $stored;

			if ( '' !== $class_id && ( ! is_string( $class_id ) || ! isset( $class_ids[ $class_id ] ) ) ) {
				// Print the stored value, not the trimmed one, so the message names the id that is really there.
				$class_id = $this->printable_id( $stored );
				$issues[] = $this->issue(
					$id,
					'warning',
					'missing_global_class',
					sprintf( 'Element %1$s references global class "%2$s" that does not exist.', $id, $class_id ),
					sprintf( 'Create it with bricks/create-global-class, or remove "%1$s" from _cssGlobalClasses with bricks/update-element for %2$s.', $class_id, $id )
				);
			}
		}

		return $issues;
	}

	/**
	 * Responsive suffixes that are not a breakpoint, pseudo-class or variant.
	 *
	 * @param string               $id       Element ID.
	 * @param array<string, mixed> $settings Element settings.
	 * @param array<string, mixed> $context  Reference data.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_breakpoint_keys( string $id, array $settings, array $context ): array {
		if ( null === $context['breakpoints'] || null === $context['pseudoClasses'] ) {
			return [];
		}

		$issues = [];

		foreach ( array_keys( $settings ) as $key ) {
			$key = (string) $key;

			if ( ! str_contains( $key, ':' ) || str_contains( $key, '(' ) ) {
				continue;
			}

			$segments = explode( ':', $key );
			array_shift( $segments );

			foreach ( $segments as $segment ) {
				if ( '' === $segment || in_array( $segment, $context['pseudoClasses'], true ) || str_starts_with( $segment, 'variant-' ) || in_array( $segment, $context['breakpoints'], true ) ) {
					continue;
				}

				$issues[] = $this->issue(
					$id,
					'warning',
					'invalid_breakpoint',
					sprintf( 'Element %1$s: key "%2$s" has the unknown segment "%3$s".', $id, $key, $segment ),
					sprintf( 'Rename the key with bricks/update-element for %1$s. Valid breakpoints: %2$s (bricks/list-breakpoints).', $id, implode( ', ', $context['breakpoints'] ) )
				);
				break;
			}
		}

		return $issues;
	}

	/**
	 * Layout elements with desktop padding and no mobile override.
	 *
	 * @param string               $id          Element ID.
	 * @param string               $name        Element type.
	 * @param array<string, mixed> $settings    Element settings.
	 * @param string[]|null        $breakpoints Breakpoint keys; null when unknown.
	 * @return array<int, array<string, mixed>>
	 */
	private function lint_mobile_padding( string $id, string $name, array $settings, ?array $breakpoints ): array {
		$mobile = array_values( array_filter( $breakpoints ?? [], static fn( string $key ): bool => str_starts_with( $key, 'mobile' ) ) );

		if ( ! in_array( $name, self::LAYOUT_ELEMENTS, true ) || empty( $settings['_padding'] ) || [] === $mobile ) {
			return [];
		}

		foreach ( $mobile as $key ) {
			if ( ! empty( $settings[ '_padding:' . $key ] ) ) {
				return [];
			}
		}

		$target = end( $mobile );

		return [
			$this->issue(
				$id,
				'warning',
				'missing_mobile_padding',
				sprintf( 'Element %1$s (%2$s) has desktop _padding and no mobile override.', $id, $name ),
				sprintf( 'Call bricks/update-element for %1$s and add _padding:%2$s.', $id, $target )
			),
		];
	}

	/**
	 * One diagnostic entry.
	 *
	 * @param string|null $element_id Element ID, null for page level issues.
	 * @param string      $severity   error, warning or info.
	 * @param string      $type       Issue type.
	 * @param string      $message    What is wrong.
	 * @param string      $fix        How to fix it.
	 * @return array<string, mixed>
	 */
	private function issue( ?string $element_id, string $severity, string $type, string $message, string $fix ): array {
		return [
			'elementId' => $element_id,
			'severity'  => $severity,
			'type'      => $type,
			'message'   => $message,
			'fix'       => $fix,
		];
	}

	/**
	 * Stored id as it may appear in a message or fix hint. Diagnostics are read
	 * by an agent as guidance, so anything other than a plain id is replaced.
	 *
	 * @param mixed $value Stored id.
	 * @return string
	 */
	private function printable_id( mixed $value ): string {
		return is_scalar( $value ) && 1 === preg_match( self::PRINTABLE_ID_PATTERN, (string) $value ) ? (string) $value : self::INVALID_ID;
	}

	/**
	 * Settings array of an element row.
	 *
	 * @param array<string, mixed> $element Element row.
	 * @return array<string, mixed>
	 */
	private function settings_of( array $element ): array {
		return isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
	}

	/**
	 * Code string of a code element, whether stored plain or as an object.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return string
	 */
	private function code_of( array $settings ): string {
		$code = $settings['code'] ?? '';
		$code = is_array( $code ) ? ( $code['code'] ?? '' ) : $code;

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Whether a script setting holds code without a script tag.
	 *
	 * @param mixed $value Setting value.
	 * @return bool
	 */
	private function lacks_script_tag( mixed $value ): bool {
		return is_string( $value ) && '' !== trim( $value ) && ! str_contains( $value, '<script' );
	}

	/**
	 * Lower case six digit hex, or an empty string when the value is not a hex color.
	 *
	 * @param string $value Color value.
	 * @return string
	 */
	private function normalize_hex( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( ! preg_match( '/^#(?:[0-9a-f]{6}|[0-9a-f]{3})$/', $value ) ) {
			return '';
		}

		return 4 === strlen( $value ) ? '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] : $value;
	}

	/**
	 * A palette raw value as var(--name).
	 *
	 * @param string $raw Raw palette value.
	 * @return string
	 */
	private function css_variable( string $raw ): string {
		$raw = trim( $raw );

		if ( preg_match( '/^var\(\s*--[\w-]+\s*\)$/', $raw ) ) {
			return $raw;
		}

		return str_starts_with( $raw, '--' ) ? 'var(' . $raw . ')' : '';
	}
}
