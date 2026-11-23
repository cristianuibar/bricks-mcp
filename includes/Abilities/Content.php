<?php
/**
 * Content abilities: SEO, post status, featured image and editor mode.
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
 * Post-level content features that Bricks does not cover.
 */
final class Content implements Module {

	private const CATEGORY = 'bricks-mcp-content';

	private const SEO_TEXT_FIELDS = [ 'title', 'description', 'ogTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'focusKeyword' ];

	private const SEO_URL_FIELDS = [ 'canonical', 'ogImage', 'twitterImage' ];

	private const SEO_ROBOTS_FIELDS = [ 'noindex', 'nofollow' ];

	private const SEO_META_KEYS = [
		'yoast'    => [
			'title'              => '_yoast_wpseo_title',
			'description'        => '_yoast_wpseo_metadesc',
			'canonical'          => '_yoast_wpseo_canonical',
			'ogTitle'            => '_yoast_wpseo_opengraph-title',
			'ogDescription'      => '_yoast_wpseo_opengraph-description',
			'ogImage'            => '_yoast_wpseo_opengraph-image',
			'twitterTitle'       => '_yoast_wpseo_twitter-title',
			'twitterDescription' => '_yoast_wpseo_twitter-description',
			'twitterImage'       => '_yoast_wpseo_twitter-image',
			'focusKeyword'       => '_yoast_wpseo_focuskw',
		],
		'rankmath' => [
			'title'        => 'rank_math_title',
			'description'  => 'rank_math_description',
			'canonical'    => 'rank_math_canonical_url',
			'focusKeyword' => 'rank_math_focus_keyword',
			'ogImage'      => 'rank_math_facebook_image',
		],
		'seopress' => [
			'title'              => '_seopress_titles_title',
			'description'        => '_seopress_titles_desc',
			'canonical'          => '_seopress_robots_canonical',
			'ogTitle'            => '_seopress_social_fb_title',
			'ogDescription'      => '_seopress_social_fb_desc',
			'ogImage'            => '_seopress_social_fb_img',
			'twitterTitle'       => '_seopress_social_twitter_title',
			'twitterDescription' => '_seopress_social_twitter_desc',
			'twitterImage'       => '_seopress_social_twitter_img',
		],
	];

	private const SEO_ROBOTS_META_KEYS = [
		'yoast'    => [
			'noindex'  => '_yoast_wpseo_meta-robots-noindex',
			'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
		],
		'seopress' => [
			'noindex'  => '_seopress_robots_index',
			'nofollow' => '_seopress_robots_follow',
		],
	];

	private const SEO_ROBOTS_ON_VALUE = [
		'yoast'    => '1',
		'seopress' => 'yes',
	];

	private const RANK_MATH_POSITIVE_DIRECTIVES = [
		'noindex'  => 'index',
		'nofollow' => 'follow',
	];

	private const SLIM_SEO_FIELDS = [
		'title'        => 'title',
		'description'  => 'description',
		'canonical'    => 'canonical',
		'noindex'      => 'noindex',
		'ogImage'      => 'facebook_image',
		'twitterImage' => 'twitter_image',
	];

	private const BRICKS_SEO_SETTINGS = [
		'title'         => 'documentTitle',
		'description'   => 'metaDescription',
		'ogTitle'       => 'sharingTitle',
		'ogDescription' => 'sharingDescription',
	];

	private const BRICKS_ROBOTS_SETTING = 'metaRobots';

	private const BRICKS_IMAGE_SETTING = 'sharingImage';

	private const SEO_PLUGIN_NAMES = [
		'yoast'    => 'Yoast SEO',
		'rankmath' => 'Rank Math',
		'seopress' => 'SEOPress',
		'slimseo'  => 'Slim SEO',
	];

	/**
	 * Bricks switches in `bricks_global_settings` that turn its own head tags off, the filter that can also turn them off
	 * on a page request (and so cannot be evaluated from an MCP request), and the page settings Bricks prints while on.
	 */
	private const BRICKS_SEO_OUTPUTS = [
		'disableSeo'       => [
			'type'     => 'seo',
			'kind'     => 'SEO',
			'label'    => 'Disable Bricks SEO meta tags',
			'filter'   => 'bricks/frontend/disable_seo',
			'settings' => [ 'documentTitle', 'metaDescription', 'metaKeywords', 'metaRobots' ],
		],
		'disableOpenGraph' => [
			'type'     => 'opengraph',
			'kind'     => 'Open Graph',
			'label'    => 'Disable Bricks Open Graph meta tags',
			'filter'   => 'bricks/frontend/disable_opengraph',
			'settings' => [ 'sharingTitle', 'sharingDescription', 'sharingImage' ],
		],
	];

	private const ROBOTS_BLOCKING_DIRECTIVES = [ 'noindex', 'nofollow', 'none' ];

	private const SEO_WARNINGS_SCHEMA = [
		'type'  => 'array',
		'items' => [
			'type'       => 'object',
			'properties' => [
				'severity'    => [
					'type' => 'string',
					'enum' => [ 'error', 'warning' ],
				],
				'type'        => [ 'type' => 'string' ],
				'message'     => [ 'type' => 'string' ],
				'fix'         => [ 'type' => 'string' ],
				'staleValues' => [ 'type' => 'object' ],
			],
		],
	];

	private const POST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	private const POST_STATUSES_NEEDING_PUBLISH_CAP = [ 'publish', 'future', 'private' ];

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return [
			'bricks-mcp/get-seo'            => $this->get_seo_definition(),
			'bricks-mcp/update-seo'         => $this->update_seo_definition(),
			'bricks-mcp/set-post-status'    => $this->set_post_status_definition(),
			'bricks-mcp/set-featured-image' => $this->set_featured_image_definition(),
			'bricks-mcp/set-editor-mode'    => $this->set_editor_mode_definition(),
		];
	}

	/**
	 * Definition of bricks-mcp/get-seo.
	 *
	 * @return array<string, mixed>
	 */
	private function get_seo_definition(): array {
		return [
			'label'               => __( 'Get SEO', 'bricks-mcp' ),
			'description'         => __( 'Read the SEO fields of a post or page from the active SEO plugin (Yoast SEO, Rank Math, SEOPress or Slim SEO), or from the Bricks page settings when none is active. When several SEO plugins are active, the first of Yoast SEO, Rank Math, SEOPress and Slim SEO is the source. Returns title, description, noindex, nofollow, canonical, Open Graph, Twitter and focus keyword fields the source supports, `source`, `supportedFields`, and an audit with title and description length warnings, `activeSeoPlugins` and `warnings`. With an SEO plugin active, `warnings` lists tags the page prints next to it: other active SEO plugins, Bricks SEO or Open Graph output that is still on, and the Bricks page settings (such as a stale metaRobots noindex) it prints. Use update-seo to change the fields.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post, page or template ID.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'postId' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'          => [ 'type' => 'integer' ],
					'source'          => [
						'type' => 'string',
						'enum' => [ 'yoast', 'rankmath', 'seopress', 'slimseo', 'bricks' ],
					],
					'supportedFields' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'fields'          => [ 'type' => 'object' ],
					'audit'           => [
						'type'       => 'object',
						'properties' => [
							'activeSeoPlugins' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'warnings'         => self::SEO_WARNINGS_SCHEMA,
						],
					],
				],
			],
			'execute_callback'    => fn( $input ) => $this->get_seo( (int) $input['postId'] ),
			'permission_callback' => fn( $input ) => $this->can_edit( $input ),
			'meta'                => [
				'annotations' => [
					'readonly'   => true,
					'idempotent' => true,
				],
			],
		];
	}

	/**
	 * Definition of bricks-mcp/update-seo.
	 *
	 * @return array<string, mixed>
	 */
	private function update_seo_definition(): array {
		$text = [ 'type' => 'string' ];

		return [
			'label'               => __( 'Update SEO', 'bricks-mcp' ),
			'description'         => __( 'Write SEO fields of a post or page to the active SEO plugin (Yoast SEO, Rank Math, SEOPress or Slim SEO), or to the Bricks page settings when none is active. Send only the fields to change; an empty string clears a text or URL field. Fields the source cannot store are listed in `unsupported` and ignored. Returns the fields before and after, and the same `warnings` as get-seo. Run get-seo first to see which fields the source supports.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post, page or template ID.', 'bricks-mcp' ),
					],
					'seo'    => [
						'type'                 => 'object',
						'minProperties'        => 1,
						'properties'           => [
							'title'              => $text,
							'description'        => $text,
							'noindex'            => [ 'type' => 'boolean' ],
							'nofollow'           => [ 'type' => 'boolean' ],
							'canonical'          => $text,
							'ogTitle'            => $text,
							'ogDescription'      => $text,
							'ogImage'            => $text,
							'twitterTitle'       => $text,
							'twitterDescription' => $text,
							'twitterImage'       => $text,
							'focusKeyword'       => $text,
						],
						'additionalProperties' => false,
					],
				],
				'required'             => [ 'postId', 'seo' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'      => [ 'type' => 'integer' ],
					'source'      => [ 'type' => 'string' ],
					'updated'     => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'unsupported' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'before'      => [ 'type' => 'object' ],
					'after'       => [ 'type' => 'object' ],
					'warnings'    => self::SEO_WARNINGS_SCHEMA,
				],
			],
			'execute_callback'    => fn( $input ) => $this->update_seo( (int) $input['postId'], (array) $input['seo'] ),
			'permission_callback' => fn( $input ) => $this->can_edit( $input ),
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Definition of bricks-mcp/set-post-status.
	 *
	 * @return array<string, mixed>
	 */
	private function set_post_status_definition(): array {
		return [
			'label'               => __( 'Set post status', 'bricks-mcp' ),
			'description'         => __( 'Change the status of a post, page or Bricks template: publish, draft, pending, private, or future (scheduled, needs `date`). Bricks creates templates as drafts and cannot publish them, so use this to publish a template. Publishing, scheduling and making private need the post type\'s publish capability; templates also need Bricks builder access. Refused while another user is editing the post. Returns the previous and new status.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post, page or template ID.', 'bricks-mcp' ),
					],
					'status' => [
						'type' => 'string',
						'enum' => self::POST_STATUSES,
					],
					'date'   => [
						'type'        => 'string',
						'description' => __( 'ISO 8601 publish date with timezone, for example 2026-12-01T09:00:00+02:00. Required for `future`, must be in the future, ignored for other statuses.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'postId', 'status' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'         => [ 'type' => 'integer' ],
					'previousStatus' => [ 'type' => 'string' ],
					'status'         => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => fn( $input ) => $this->set_post_status( (int) $input['postId'], (string) $input['status'], isset( $input['date'] ) ? (string) $input['date'] : '' ),
			'permission_callback' => fn( $input ) => $this->can_set_status( $input ),
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Definition of bricks-mcp/set-featured-image.
	 *
	 * @return array<string, mixed>
	 */
	private function set_featured_image_definition(): array {
		return [
			'label'               => __( 'Set featured image', 'bricks-mcp' ),
			'description'         => __( 'Set the featured image of a post, page or template to an image from the media library, or remove it with `attachmentId: 0`. The attachment must be an image and the post type must support featured images. Returns the previous and new attachment ID. To add an image to a page layout instead, edit the Bricks elements.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post, page or template ID.', 'bricks-mcp' ),
					],
					'attachmentId' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Media library attachment ID, or 0 to remove the featured image.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'postId', 'attachmentId' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'               => [ 'type' => 'integer' ],
					'attachmentId'         => [ 'type' => 'integer' ],
					'previousAttachmentId' => [ 'type' => 'integer' ],
					'url'                  => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => fn( $input ) => $this->set_featured_image( (int) $input['postId'], (int) $input['attachmentId'] ),
			'permission_callback' => fn( $input ) => $this->can_edit( $input ),
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Definition of bricks-mcp/set-editor-mode.
	 *
	 * @return array<string, mixed>
	 */
	private function set_editor_mode_definition(): array {
		return [
			'label'               => __( 'Set editor mode', 'bricks-mcp' ),
			'description'         => __( 'Choose which editor renders a post: `bricks` makes Bricks render the post from its elements, `wordpress` hands rendering back to the WordPress editor content. Bricks element data is kept in both cases, so switching back restores the layout. `previousMode` is `default` when the post never had a mode stored, in which case Bricks decides. Use it to enable Bricks on an existing post or page that was created without it.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'postId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Post or page ID.', 'bricks-mcp' ),
					],
					'mode'   => [
						'type' => 'string',
						'enum' => [ 'bricks', 'wordpress' ],
					],
				],
				'required'             => [ 'postId', 'mode' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'postId'       => [ 'type' => 'integer' ],
					'mode'         => [ 'type' => 'string' ],
					'previousMode' => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => fn( $input ) => $this->set_editor_mode( (int) $input['postId'], (string) $input['mode'] ),
			'permission_callback' => fn( $input ) => $this->can_edit( $input, true ),
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Permission check shared by every ability.
	 *
	 * @param mixed $input         Ability input.
	 * @param bool  $needs_builder Whether Bricks builder access on the post is required too.
	 * @return true|\WP_Error
	 */
	private function can_edit( mixed $input, bool $needs_builder = false ): bool|\WP_Error {
		return Access::edit_post( (int) ( is_array( $input ) ? ( $input['postId'] ?? 0 ) : 0 ), $needs_builder );
	}

	/**
	 * Permission check for status changes, which may need the publish capability.
	 *
	 * Bricks templates also need builder access, since publishing or unpublishing one applies or removes it site-wide.
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	private function can_set_status( mixed $input ): bool|\WP_Error {
		$post_id = (int) ( is_array( $input ) ? ( $input['postId'] ?? 0 ) : 0 );
		$allowed = $this->can_edit( $input, $post_id > 0 && 'bricks_template' === get_post_type( $post_id ) );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$status = is_array( $input ) ? (string) ( $input['status'] ?? '' ) : '';

		if ( ! in_array( $status, self::POST_STATUSES_NEEDING_PUBLISH_CAP, true ) ) {
			return true;
		}

		$post        = get_post( (int) $input['postId'] );
		$post_type   = get_post_type_object( $post->post_type );
		$publish_cap = $post_type->cap->publish_posts ?? 'publish_posts';

		if ( current_user_can( $publish_cap ) ) {
			return true;
		}

		return new \WP_Error( 'bricks_mcp_forbidden', __( 'You are not allowed to publish, schedule or privatise this post type.', 'bricks-mcp' ), [ 'status' => 403 ] );
	}

	/**
	 * Read SEO data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function get_seo( int $post_id ): array {
		$source = $this->detect_seo_source();
		$fields = $this->read_seo_fields( $post_id, $source );

		return [
			'postId'          => $post_id,
			'source'          => $source,
			'supportedFields' => $this->supported_seo_fields( $source ),
			'fields'          => $fields,
			'audit'           => $this->audit_seo( $fields ) + [
				'activeSeoPlugins' => $this->active_seo_plugins(),
				'warnings'         => $this->seo_output_warnings( $post_id ),
			],
		];
	}

	/**
	 * Write SEO data for a post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $seo     Requested fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function update_seo( int $post_id, array $seo ): array|\WP_Error {
		$source      = $this->detect_seo_source();
		$requested   = $this->sanitize_seo_fields( $seo );
		$supported   = $this->supported_seo_fields( $source );
		$unsupported = array_values( array_diff( array_keys( $requested ), $supported ) );
		$writable    = array_diff_key( $requested, array_flip( $unsupported ) );
		$before      = $this->read_seo_fields( $post_id, $source );

		if ( array() !== $writable ) {
			$written = $this->write_seo_fields( $post_id, $source, $writable );

			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		return [
			'postId'      => $post_id,
			'source'      => $source,
			'updated'     => array_keys( $writable ),
			'unsupported' => $unsupported,
			'before'      => $before,
			'after'       => $this->read_seo_fields( $post_id, $source ),
			'warnings'    => $this->seo_output_warnings( $post_id ),
		];
	}

	/**
	 * Keep only known SEO fields, sanitized by type.
	 *
	 * @param array<string, mixed> $seo Raw fields.
	 * @return array<string, string|bool>
	 */
	private function sanitize_seo_fields( array $seo ): array {
		$clean = [];

		foreach ( $seo as $field => $value ) {
			if ( in_array( $field, self::SEO_TEXT_FIELDS, true ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $value );
			} elseif ( in_array( $field, self::SEO_URL_FIELDS, true ) ) {
				$clean[ $field ] = esc_url_raw( (string) $value );
			} elseif ( in_array( $field, self::SEO_ROBOTS_FIELDS, true ) ) {
				$clean[ $field ] = (bool) $value;
			}
		}

		return $clean;
	}

	/**
	 * Detect the active SEO plugin, or `bricks` when there is none.
	 *
	 * @return string
	 */
	private function detect_seo_source(): string {
		return $this->active_seo_plugins()[0] ?? 'bricks';
	}

	/**
	 * Active SEO plugins, the one bricks-mcp reads and writes first.
	 *
	 * @return string[]
	 */
	private function active_seo_plugins(): array {
		$active = [
			'yoast'    => class_exists( 'WPSEO_Options' ),
			'rankmath' => class_exists( 'RankMath' ),
			'seopress' => class_exists( 'SeoPress_Seo_Metabox' ) || function_exists( 'seopress_init' ),
			'slimseo'  => class_exists( 'SlimSEO\MetaTags\Title' ),
		];

		return array_keys( array_filter( $active ) );
	}

	/**
	 * Tags the page prints next to the active SEO plugin's own: other active SEO plugins, Bricks SEO or Open Graph
	 * output that is still on, and the Bricks page settings of the post that output prints. Empty without an SEO plugin.
	 *
	 * Global Bricks settings are only reported, never changed.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>> Each with `severity`, `type`, `message`, `fix`, and `staleValues` for stale page settings.
	 */
	public function seo_output_warnings( int $post_id ): array {
		$plugins = $this->active_seo_plugins();

		if ( array() === $plugins ) {
			return [];
		}

		$source   = self::SEO_PLUGIN_NAMES[ $plugins[0] ];
		$warnings = [];

		if ( count( $plugins ) > 1 ) {
			$names      = array_map( static fn( string $plugin ): string => self::SEO_PLUGIN_NAMES[ $plugin ], $plugins );
			$warnings[] = $this->seo_warning(
				'warning',
				'multiple_seo_plugins',
				/* translators: 1: active SEO plugins, 2: SEO plugin bricks-mcp uses */
				sprintf( __( 'More than one SEO plugin is active (%1$s). bricks-mcp reads and writes %2$s only; the others still print their own tags.', 'bricks-mcp' ), implode( ', ', $names ), $source ),
				sprintf( 'Ask the site owner to deactivate %s so the page prints one set of SEO tags.', implode( ', ', array_slice( $names, 1 ) ) )
			);
		}

		$global = get_option( 'bricks_global_settings', [] );
		$global = is_array( $global ) ? $global : [];
		$page   = $this->bricks_page_settings( $post_id );

		foreach ( self::BRICKS_SEO_OUTPUTS as $switch => $output ) {
			if ( ! empty( $global[ $switch ] ) ) {
				continue;
			}

			$turn_off   = sprintf( 'turn on "%1$s" (%2$s) in Bricks > Settings > General', $output['label'], $switch );
			$warnings[] = $this->seo_warning(
				'warning',
				'bricks_' . $output['type'] . '_output',
				/* translators: 1: SEO or Open Graph, 2: SEO plugin name */
				sprintf( __( 'Bricks still prints its own %1$s tags next to %2$s, so the page can carry two sets.', 'bricks-mcp' ), $output['kind'], $source ),
				sprintf( 'Ask the site owner to %1$s. bricks-mcp never changes global Bricks settings. A %2$s filter that turns the output off only answers on a page request, so this check reads the setting alone; confirm such a filter in the published page head.', $turn_off, $output['filter'] )
			);

			$stale = $this->bricks_stale_seo_values( $page, $output['settings'] );

			if ( array() === $stale ) {
				continue;
			}

			$keys     = implode( ', ', array_keys( $stale ) );
			$robots   = (array) ( $stale[ self::BRICKS_ROBOTS_SETTING ] ?? [] );
			$blocking = array_values( array_intersect( self::ROBOTS_BLOCKING_DIRECTIVES, $robots ) );
			$kept     = array_values( array_diff( $robots, self::ROBOTS_BLOCKING_DIRECTIVES ) );
			/* translators: 1: Bricks page setting keys, 2: SEO plugin name */
			$message = sprintf( __( 'The Bricks page settings of this post still hold %1$s, which Bricks prints next to %2$s.', 'bricks-mcp' ), $keys, $source );
			$fix     = sprintf( 'Clear %1$s with bricks/set-page-settings by sending each as null', $keys );

			if ( array() !== $blocking ) {
				/* translators: %s: robots directives */
				$message .= ' ' . sprintf( __( 'Its robots tag says %s whatever the SEO plugin says, so crawlers may drop the page.', 'bricks-mcp' ), implode( ', ', $blocking ) );
			}

			// Clearing the whole robots setting would also drop directives such as nosnippet that block nothing.
			if ( array() !== $blocking && array() !== $kept ) {
				$clear = array_diff( array_keys( $stale ), [ self::BRICKS_ROBOTS_SETTING ] );
				$fix   = sprintf( 'send %1$s as %2$s with bricks/set-page-settings, which drops %3$s and keeps the other robots directives', self::BRICKS_ROBOTS_SETTING, wp_json_encode( $kept ), implode( ', ', $blocking ) );
				$fix   = array() === $clear ? ucfirst( $fix ) : sprintf( 'Clear %1$s by sending each as null and %2$s', implode( ', ', $clear ), $fix );
			}

			$warnings[] = $this->seo_warning(
				array() !== $blocking ? 'error' : 'warning',
				'bricks_' . $output['type'] . '_stale_values',
				$message,
				sprintf( '%1$s, or ask the site owner to %2$s.', $fix, $turn_off )
			) + [ 'staleValues' => $stale ];
		}

		return $warnings;
	}

	/**
	 * One SEO output warning.
	 *
	 * @param string $severity `error` or `warning`.
	 * @param string $type     Warning type.
	 * @param string $message  What the page prints.
	 * @param string $fix      How to stop it.
	 * @return array<string, string>
	 */
	private function seo_warning( string $severity, string $type, string $message, string $fix ): array {
		return [
			'severity' => $severity,
			'type'     => $type,
			'message'  => $message,
			'fix'      => $fix,
		];
	}

	/**
	 * Non-empty Bricks page settings among the given keys, robots as a directive list and the sharing image as its URL or dynamic tag.
	 *
	 * @param array<string, mixed> $page Page settings.
	 * @param string[]             $keys Setting keys.
	 * @return array<string, string|string[]>
	 */
	private function bricks_stale_seo_values( array $page, array $keys ): array {
		$stale = [];

		foreach ( $keys as $key ) {
			$value = $page[ $key ] ?? null;

			if ( self::BRICKS_ROBOTS_SETTING === $key ) {
				$value = $this->bricks_robots( $page );
			} elseif ( self::BRICKS_IMAGE_SETTING === $key ) {
				$value = is_array( $value ) ? ( empty( $value['url'] ) ? ( $value['useDynamicData'] ?? null ) : $value['url'] ) : null;
			}

			if ( ! is_array( $value ) ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			}

			if ( '' !== $value && array() !== $value ) {
				$stale[ $key ] = $value;
			}
		}

		return $stale;
	}

	/**
	 * Fields a source can store.
	 *
	 * @param string $source SEO source.
	 * @return string[]
	 */
	private function supported_seo_fields( string $source ): array {
		return match ( $source ) {
			'slimseo' => array_keys( self::SLIM_SEO_FIELDS ),
			'bricks'  => array_merge( array_keys( self::BRICKS_SEO_SETTINGS ), [ 'ogImage' ], self::SEO_ROBOTS_FIELDS ),
			default   => array_merge( array_keys( self::SEO_META_KEYS[ $source ] ), self::SEO_ROBOTS_FIELDS ),
		};
	}

	/**
	 * Read the normalized fields a source supports.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source  SEO source.
	 * @return array<string, string|bool>
	 */
	private function read_seo_fields( int $post_id, string $source ): array {
		if ( 'bricks' === $source ) {
			return $this->read_bricks_seo( $post_id );
		}

		if ( 'slimseo' === $source ) {
			$slim   = $this->slim_seo_meta( $post_id );
			$fields = [];

			foreach ( self::SLIM_SEO_FIELDS as $field => $key ) {
				$fields[ $field ] = in_array( $field, self::SEO_ROBOTS_FIELDS, true ) ? (bool) ( $slim[ $key ] ?? false ) : (string) ( $slim[ $key ] ?? '' );
			}

			return $fields;
		}

		$fields = [];

		foreach ( self::SEO_META_KEYS[ $source ] as $field => $meta_key ) {
			$fields[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
		}

		return $fields + $this->read_plugin_robots( $post_id, $source );
	}

	/**
	 * Read noindex and nofollow from a plugin.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source  SEO plugin.
	 * @return array<string, bool>
	 */
	private function read_plugin_robots( int $post_id, string $source ): array {
		if ( 'rankmath' === $source ) {
			$robots = $this->rank_math_robots( $post_id );

			return [
				'noindex'  => in_array( 'noindex', $robots, true ),
				'nofollow' => in_array( 'nofollow', $robots, true ),
			];
		}

		$robots = [];

		foreach ( self::SEO_ROBOTS_META_KEYS[ $source ] as $field => $meta_key ) {
			$robots[ $field ] = (string) get_post_meta( $post_id, $meta_key, true ) === self::SEO_ROBOTS_ON_VALUE[ $source ];
		}

		return $robots;
	}

	/**
	 * Write already sanitized, supported fields.
	 *
	 * @param int                        $post_id Post ID.
	 * @param string                     $source  SEO source.
	 * @param array<string, string|bool> $fields  Fields to write.
	 * @return true|\WP_Error
	 */
	private function write_seo_fields( int $post_id, string $source, array $fields ): bool|\WP_Error {
		if ( 'bricks' === $source ) {
			return $this->write_bricks_seo( $post_id, $fields );
		}

		if ( 'slimseo' === $source ) {
			return $this->write_slim_seo( $post_id, $fields );
		}

		foreach ( self::SEO_META_KEYS[ $source ] as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $meta_key, $fields[ $field ] );
			}
		}

		$this->write_plugin_robots( $post_id, $source, $fields );

		return true;
	}

	/**
	 * Write noindex and nofollow to a plugin.
	 *
	 * @param int                        $post_id Post ID.
	 * @param string                     $source  SEO plugin.
	 * @param array<string, string|bool> $fields  Fields being written.
	 * @return void
	 */
	private function write_plugin_robots( int $post_id, string $source, array $fields ): void {
		if ( 'rankmath' === $source ) {
			$robots = $this->rank_math_robots( $post_id );

			foreach ( self::SEO_ROBOTS_FIELDS as $field ) {
				if ( array_key_exists( $field, $fields ) ) {
					$robots = $this->toggle_directive( $robots, $field, (bool) $fields[ $field ] );
					$robots = $this->toggle_directive( $robots, self::RANK_MATH_POSITIVE_DIRECTIVES[ $field ], ! $fields[ $field ] );
				}
			}

			if ( array_intersect( self::SEO_ROBOTS_FIELDS, array_keys( $fields ) ) ) {
				update_post_meta( $post_id, 'rank_math_robots', $robots );
			}

			return;
		}

		foreach ( self::SEO_ROBOTS_META_KEYS[ $source ] as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $post_id, $meta_key, $fields[ $field ] ? self::SEO_ROBOTS_ON_VALUE[ $source ] : '' );
			}
		}
	}

	/**
	 * Rank Math robots directives of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function rank_math_robots( int $post_id ): array {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );

		return is_array( $robots ) ? array_values( $robots ) : [];
	}

	/**
	 * Slim SEO's per-post settings array.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function slim_seo_meta( int $post_id ): array {
		$slim = get_post_meta( $post_id, 'slim_seo', true );

		return is_array( $slim ) ? $slim : [];
	}

	/**
	 * Merge fields into Slim SEO's per-post settings array.
	 *
	 * Like Slim SEO's own editor, a cleared field drops its key, noindex is stored as 1, and an empty array deletes the meta.
	 * The metadata functions keep a revision's write on the revision; the post meta ones would redirect it to the parent.
	 * The delete matches the value read in one statement, so a record saved after the read is kept.
	 *
	 * @param int                        $post_id Post ID.
	 * @param array<string, string|bool> $fields  Fields to write.
	 * @return true|\WP_Error Error when the database refuses the delete.
	 */
	private function write_slim_seo( int $post_id, array $fields ): bool|\WP_Error {
		$stored = get_post_meta( $post_id, 'slim_seo', true );
		$slim   = is_array( $stored ) ? $stored : [];

		foreach ( self::SLIM_SEO_FIELDS as $field => $key ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}

			if ( '' === $fields[ $field ] || false === $fields[ $field ] ) {
				unset( $slim[ $key ] );
			} else {
				$slim[ $key ] = true === $fields[ $field ] ? 1 : $fields[ $field ];
			}
		}

		if ( array() !== $slim ) {
			update_metadata( 'post', $post_id, 'slim_seo', $slim );
		} elseif ( '' !== $stored && false !== $stored && ! $this->delete_post_meta_if_unchanged( $post_id, 'slim_seo', $stored ) ) {
			return new \WP_Error( 'bricks_mcp_seo_write_failed', __( 'The database did not delete the cleared Slim SEO record, so its fields are still stored. Re-read the SEO before retrying.', 'bricks-mcp' ), [ 'status' => 500 ] );
		}

		return true;
	}

	/**
	 * Delete a post meta row only while it still holds the given value.
	 *
	 * WordPress's delete_metadata() selects the matching row IDs and then deletes those IDs, so a save between the two is lost.
	 * One DELETE that matches the value cannot remove a newer save. The value is compared as bytes: the column's collation
	 * would match a save that only changed letter case or trailing spaces.
	 *
	 * @param int    $post_id  Post ID; a revision keeps its own row.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value read before the delete.
	 * @return bool False when the database refused the DELETE; true when it ran, even if a newer save kept the row.
	 */
	private function delete_post_meta_if_unchanged( int $post_id, string $meta_key, mixed $value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_metadata() cannot match the value in its DELETE; the meta cache is cleared below.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value = CAST( %s AS BINARY )",
				$post_id,
				$meta_key,
				maybe_serialize( $value )
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );

		return false !== $deleted;
	}

	/**
	 * Add or remove one robots directive.
	 *
	 * @param string[] $directives Current directives.
	 * @param string   $directive  Directive to change.
	 * @param bool     $enabled    Whether it should be present.
	 * @return string[]
	 */
	private function toggle_directive( array $directives, string $directive, bool $enabled ): array {
		$directives = array_diff( $directives, [ $directive ] );

		if ( $enabled ) {
			$directives[] = $directive;
		}

		return array_values( array_unique( $directives ) );
	}

	/**
	 * Replace the `none` directive with the `noindex` and `nofollow` it stands for.
	 *
	 * @param string[] $directives Current directives.
	 * @return string[]
	 */
	private function expand_none_directive( array $directives ): array {
		if ( ! in_array( 'none', $directives, true ) ) {
			return $directives;
		}

		return array_values( array_unique( array_merge( array_diff( $directives, [ 'none' ] ), self::SEO_ROBOTS_FIELDS ) ) );
	}

	/**
	 * Bricks page settings of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function bricks_page_settings( int $post_id ): array {
		$settings = get_post_meta( $post_id, defined( 'BRICKS_DB_PAGE_SETTINGS' ) ? BRICKS_DB_PAGE_SETTINGS : '_bricks_page_settings', true );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Robots directives stored in Bricks page settings.
	 *
	 * @param array<string, mixed> $settings Page settings.
	 * @return string[]
	 */
	private function bricks_robots( array $settings ): array {
		$robots = $settings[ self::BRICKS_ROBOTS_SETTING ] ?? [];

		if ( is_string( $robots ) ) {
			$robots = preg_split( '/\s*,\s*/', $robots, -1, PREG_SPLIT_NO_EMPTY );
		}

		return is_array( $robots ) ? array_values( array_map( 'strval', $robots ) ) : [];
	}

	/**
	 * Read SEO fields from Bricks page settings.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|bool>
	 */
	private function read_bricks_seo( int $post_id ): array {
		$settings = $this->bricks_page_settings( $post_id );
		$robots   = $this->bricks_robots( $settings );
		$fields   = [];

		foreach ( self::BRICKS_SEO_SETTINGS as $field => $setting ) {
			$fields[ $field ] = (string) ( $settings[ $setting ] ?? '' );
		}

		$image             = $settings[ self::BRICKS_IMAGE_SETTING ] ?? [];
		$fields['ogImage'] = is_array( $image ) ? (string) ( $image['url'] ?? '' ) : '';

		foreach ( self::SEO_ROBOTS_FIELDS as $field ) {
			$fields[ $field ] = in_array( $field, $robots, true ) || in_array( 'none', $robots, true );
		}

		return $fields;
	}

	/**
	 * Write SEO fields through bricks/set-page-settings.
	 *
	 * @param int                        $post_id Post ID.
	 * @param array<string, string|bool> $fields  Fields to write.
	 * @return true|\WP_Error
	 */
	private function write_bricks_seo( int $post_id, array $fields ): bool|\WP_Error {
		$settings = [];

		foreach ( self::BRICKS_SEO_SETTINGS as $field => $setting ) {
			if ( array_key_exists( $field, $fields ) ) {
				$settings[ $setting ] = '' === $fields[ $field ] ? null : $fields[ $field ];
			}
		}

		if ( array_key_exists( 'ogImage', $fields ) ) {
			$settings[ self::BRICKS_IMAGE_SETTING ] = '' === $fields['ogImage'] ? null : [
				'url'            => $fields['ogImage'],
				'id'             => null,
				'filename'       => null,
				'useDynamicData' => null,
				'size'           => null,
			];
		}

		if ( array_intersect( self::SEO_ROBOTS_FIELDS, array_keys( $fields ) ) ) {
			$robots = $this->expand_none_directive( $this->bricks_robots( $this->bricks_page_settings( $post_id ) ) );

			foreach ( self::SEO_ROBOTS_FIELDS as $field ) {
				if ( array_key_exists( $field, $fields ) ) {
					$robots = $this->toggle_directive( $robots, $field, (bool) $fields[ $field ] );
				}
			}

			$settings[ self::BRICKS_ROBOTS_SETTING ] = array() === $robots ? null : $robots;
		}

		$result = Access::run_bricks_ability(
			'bricks/set-page-settings',
			[
				'postId'   => $post_id,
				'settings' => $settings,
			]
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Title and description length audit.
	 *
	 * @param array<string, string|bool> $fields Normalized fields.
	 * @return array<string, mixed>
	 */
	private function audit_seo( array $fields ): array {
		$title_length       = mb_strlen( (string) ( $fields['title'] ?? '' ) );
		$description_length = mb_strlen( (string) ( $fields['description'] ?? '' ) );

		return [
			'titleLength'       => $title_length,
			'titleIssue'        => $this->length_issue( $title_length, 30, 60 ),
			'descriptionLength' => $description_length,
			'descriptionIssue'  => $this->length_issue( $description_length, 120, 160 ),
			'hasOgImage'        => '' !== (string) ( $fields['ogImage'] ?? '' ),
		];
	}

	/**
	 * Classify a length against a recommended range.
	 *
	 * @param int $length Length in characters.
	 * @param int $min    Recommended minimum.
	 * @param int $max    Recommended maximum.
	 * @return string|null `missing`, `too_short`, `too_long` or null when fine.
	 */
	private function length_issue( int $length, int $min, int $max ): ?string {
		if ( 0 === $length ) {
			return 'missing';
		}

		if ( $length < $min ) {
			return 'too_short';
		}

		return $length > $max ? 'too_long' : null;
	}

	/**
	 * Change a post's status.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Target status.
	 * @param string $date    ISO 8601 date, used for `future`.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set_post_status( int $post_id, string $status, string $date ): array|\WP_Error {
		$post     = get_post( $post_id );
		$previous = $post->post_status;
		$update   = [
			'ID'          => $post_id,
			'post_status' => $status,
		];

		if ( 'future' === $status ) {
			$timestamp = '' === $date ? false : strtotime( $date );

			if ( false === $timestamp || $timestamp < time() + MINUTE_IN_SECONDS ) {
				return new \WP_Error( 'bricks_mcp_invalid_date', __( 'Scheduling needs `date` as an ISO 8601 timestamp at least a minute in the future.', 'bricks-mcp' ), [ 'status' => 400 ] );
			}

			$update['edit_date']     = true;
			$update['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			$update['post_date']     = get_date_from_gmt( $update['post_date_gmt'] );
		}

		if ( 'publish' === $status && isset( $post->post_date_gmt ) && strtotime( $post->post_date_gmt . ' UTC' ) > time() ) {
			$update['edit_date']     = true;
			$update['post_date_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$update['post_date']     = get_date_from_gmt( $update['post_date_gmt'] );
		}

		if ( false !== $this->post_lock_holder( $post_id ) ) {
			return new \WP_Error( 'bricks_mcp_post_locked', __( 'Another user is editing this post right now.', 'bricks-mcp' ), [ 'status' => 409 ] );
		}

		$updated = wp_update_post( $update, true );

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'bricks_mcp_status_failed', $updated->get_error_message(), [ 'status' => 500 ] );
		}

		return [
			'postId'         => $post_id,
			'previousStatus' => $previous,
			'status'         => get_post( $post_id )->post_status,
		];
	}

	/**
	 * User ID holding the edit lock on a post, or false.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	private function post_lock_holder( int $post_id ): int|false {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		return wp_check_post_lock( $post_id );
	}

	/**
	 * Set or remove a post's featured image.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Attachment ID, 0 to remove.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function set_featured_image( int $post_id, int $attachment_id ): array|\WP_Error {
		$previous = (int) get_post_thumbnail_id( $post_id );

		if ( 0 === $attachment_id ) {
			if ( 0 !== $previous ) {
				delete_post_thumbnail( $post_id );
			}

			return $this->featured_image_result( $post_id, 0, $previous );
		}

		$post = get_post( $post_id );

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return new \WP_Error(
				'bricks_mcp_thumbnails_unsupported',
				/* translators: %s: post type */
				sprintf( __( 'Post type "%s" does not support featured images.', 'bricks-mcp' ), $post->post_type ),
				[ 'status' => 400 ]
			);
		}

		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error(
				'bricks_mcp_attachment_invalid',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d does not exist or is not an image.', 'bricks-mcp' ), $attachment_id ),
				[ 'status' => 400 ]
			);
		}

		if ( $previous !== $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
			return new \WP_Error( 'bricks_mcp_featured_image_failed', __( 'WordPress did not accept the featured image.', 'bricks-mcp' ), [ 'status' => 500 ] );
		}

		return $this->featured_image_result( $post_id, $attachment_id, $previous );
	}

	/**
	 * Featured image response.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Current attachment ID, 0 when none.
	 * @param int $previous      Previous attachment ID, 0 when none.
	 * @return array<string, mixed>
	 */
	private function featured_image_result( int $post_id, int $attachment_id, int $previous ): array {
		return [
			'postId'               => $post_id,
			'attachmentId'         => $attachment_id,
			'previousAttachmentId' => $previous,
			'url'                  => 0 === $attachment_id ? '' : (string) wp_get_attachment_url( $attachment_id ),
		];
	}

	/**
	 * Switch the editor that renders a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    `bricks` or `wordpress`.
	 * @return array<string, mixed>
	 */
	private function set_editor_mode( int $post_id, string $mode ): array {
		$meta_key = defined( 'BRICKS_DB_EDITOR_MODE' ) ? BRICKS_DB_EDITOR_MODE : '_bricks_editor_mode';
		$stored   = (string) get_post_meta( $post_id, $meta_key, true );
		$previous = in_array( $stored, [ 'bricks', 'wordpress' ], true ) ? $stored : 'default'; // phpcs:ignore WordPress.WP.CapitalPDangit -- machine identifier of the editor mode.

		update_post_meta( $post_id, $meta_key, $mode );

		return [
			'postId'       => $post_id,
			'mode'         => $mode,
			'previousMode' => $previous,
		];
	}
}
