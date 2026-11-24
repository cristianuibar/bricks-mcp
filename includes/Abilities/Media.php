<?php
/**
 * Media and font abilities: Unsplash search/import, Adobe Fonts and font settings.
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
 * Registers Unsplash media and Bricks font-loading abilities.
 */
final class Media implements Module {

	private const CATEGORY = 'bricks-mcp-media';

	private const UNSPLASH_API_BASE = 'https://api.unsplash.com';

	private const UNSPLASH_API_HOST = 'api.unsplash.com';

	private const UNSPLASH_IMAGE_HOST = 'images.unsplash.com';

	private const MAX_IMAGE_DOWNLOAD_BYTES = 15728640;

	private const ALLOWED_IMAGE_MIMES = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	];

	private const UNSPLASH_UTM = 'utm_source=bricks_mcp&utm_medium=referral';

	private const UNSPLASH_META_KEY = '_unsplash_photo_id';

	private const UNSPLASH_PHOTOGRAPHER_META_KEY = '_unsplash_photographer';

	private const UNSPLASH_PHOTOGRAPHER_URL_META_KEY = '_unsplash_photographer_url';

	// Set after the photo ID on upload and removed once the attribution is saved, so a retry completes the attachment.
	private const UNSPLASH_PENDING_META_KEY = '_unsplash_attribution_pending';

	private const GLOBAL_SETTINGS_OPTION = 'bricks_global_settings';

	private const ADOBE_FONTS_OPTION = 'bricks_adobe_fonts';

	private const VALID_ORIENTATIONS = [ 'landscape', 'portrait', 'squarish' ];

	private const VALID_WEBFONT_LOADING = [ '', 'webfontloader' ];

	private const BOOLEAN_FONT_KEYS = [
		'disableGoogleFonts',
		'customFontsPreload',
		'googleFontsFilesystem',
	];

	private const ALLOWED_FONT_KEYS = [
		'disableGoogleFonts',
		'webfontLoading',
		'customFontsPreload',
		'googleFontsFilesystem',
	];

	/**
	 * Media and font ability definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return [
			'bricks-mcp/search-unsplash'       => $this->search_unsplash_definition(),
			'bricks-mcp/import-unsplash-photo' => $this->import_unsplash_photo_definition(),
			'bricks-mcp/list-adobe-fonts'      => $this->list_adobe_fonts_definition(),
			'bricks-mcp/update-font-settings'  => $this->update_font_settings_definition(),
		];
	}

	/**
	 * Permission callback for searching Unsplash.
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function search_unsplash_permission( mixed $input = null ): bool|\WP_Error {
		unset( $input );

		return $this->check_upload_permission();
	}

	/**
	 * Permission callback for importing an Unsplash photo.
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function import_unsplash_permission( mixed $input = null ): bool|\WP_Error {
		$upload = $this->check_upload_permission();
		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		if ( is_array( $input ) && array_key_exists( 'postId', $input ) && null !== $input['postId'] ) {
			return Access::edit_post( (int) $input['postId'] );
		}

		return true;
	}

	/**
	 * Permission callback for font-related abilities.
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	public function manage_fonts_permission( mixed $input = null ): bool|\WP_Error {
		unset( $input );

		return Access::manage_site();
	}

	/**
	 * Search Unsplash photos using the site's configured Bricks Unsplash API key.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function search_unsplash( array $input ): array|\WP_Error {
		$query = isset( $input['query'] ) && is_string( $input['query'] )
			? trim( sanitize_text_field( $input['query'] ) )
			: '';

		if ( '' === $query ) {
			return new \WP_Error(
				'bricks_mcp_invalid_query',
				__( 'A non-empty search query is required.', 'bricks-mcp' ),
				[ 'status' => 400 ]
			);
		}

		$api_key = $this->get_unsplash_api_key();
		if ( '' === $api_key ) {
			return $this->missing_unsplash_key_error();
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = min( 30, max( 1, (int) ( $input['perPage'] ?? 10 ) ) );

		$params = [
			'query'    => $query,
			'page'     => $page,
			'per_page' => $per_page,
		];

		if ( isset( $input['orientation'] ) && '' !== $input['orientation'] ) {
			$orientation = is_string( $input['orientation'] ) ? $input['orientation'] : '';
			if ( ! in_array( $orientation, self::VALID_ORIENTATIONS, true ) ) {
				return new \WP_Error(
					'bricks_mcp_invalid_orientation',
					__( 'Orientation must be one of: landscape, portrait, squarish.', 'bricks-mcp' ),
					[ 'status' => 400 ]
				);
			}
			$params['orientation'] = $orientation;
		}

		$url  = self::UNSPLASH_API_BASE . '/search/photos?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$body = $this->unsplash_get_json( $url, $api_key );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_parse_error',
				__( 'Unexpected response format from the Unsplash API.', 'bricks-mcp' ),
				[ 'status' => 502 ]
			);
		}

		$results = [];
		foreach ( $body['results'] as $photo ) {
			if ( ! is_array( $photo ) || empty( $photo['id'] ) ) {
				continue;
			}
			$results[] = $this->format_unsplash_result( $photo );
		}

		return [
			'total'      => (int) ( $body['total'] ?? 0 ),
			'totalPages' => (int) ( $body['total_pages'] ?? 0 ),
			'page'       => $page,
			'perPage'    => $per_page,
			'results'    => $results,
		];
	}

	/**
	 * Import an Unsplash photo into the WordPress Media Library via Bricks' upload-media ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function import_unsplash_photo( array $input ): array|\WP_Error {
		$raw_id   = isset( $input['photoId'] ) && is_string( $input['photoId'] ) ? trim( $input['photoId'] ) : '';
		$photo_id = sanitize_text_field( $raw_id );

		if ( '' === $photo_id || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $photo_id ) ) {
			return new \WP_Error(
				'bricks_mcp_invalid_photo_id',
				__( 'A valid Unsplash photoId is required.', 'bricks-mcp' ),
				[ 'status' => 400 ]
			);
		}

		$post_id     = isset( $input['postId'] ) ? (int) $input['postId'] : 0;
		$existing_id = $this->find_existing_unsplash_attachment( $photo_id );
		$pending     = false;
		if ( null !== $existing_id ) {
			// The credit URL is saved last, so an attachment without it lacks its attribution. This import names its
			// upload unsplash-<photoId>, which WordPress stores with the upload itself: such an upload is an unfinished
			// import even if its pending mark failed. Releases before stored attribution kept the Unsplash file name.
			$uncredited = '' === (string) get_post_meta( $existing_id, self::UNSPLASH_PHOTOGRAPHER_URL_META_KEY, true );
			$pending    = $uncredited && (
				'' !== (string) get_post_meta( $existing_id, self::UNSPLASH_PENDING_META_KEY, true )
				|| str_starts_with( wp_basename( (string) get_post_meta( $existing_id, '_wp_attached_file', true ) ), 'unsplash-' )
			);
			// A legacy attachment is completed when this caller can, else returned as it is.
			$uncredited = $uncredited && ! $pending;
			$completing = $pending || ( $uncredited && current_user_can( 'edit_post', $existing_id ) && '' !== $this->get_unsplash_api_key() );
			if ( ! $completing ) {
				return $this->reuse_existing_attachment( $existing_id, $photo_id, $post_id );
			}
		}

		$api_key = $this->get_unsplash_api_key();
		if ( '' === $api_key ) {
			return $this->missing_unsplash_key_error();
		}

		$photo_url = self::UNSPLASH_API_BASE . '/photos/' . rawurlencode( $photo_id );
		$photo     = $this->unsplash_get_json( $photo_url, $api_key );

		if ( is_wp_error( $photo ) ) {
			// A legacy attachment always deduplicates: when Unsplash cannot credit it, it is returned as it is.
			return null !== $existing_id && ! $pending ? $this->reuse_existing_attachment( $existing_id, $photo_id, $post_id ) : $photo;
		}

		$photographer     = isset( $photo['user']['name'] ) && is_string( $photo['user']['name'] )
			? sanitize_text_field( $photo['user']['name'] )
			: '';
		$photographer_url = $this->with_unsplash_utm(
			isset( $photo['user']['links']['html'] ) && is_string( $photo['user']['links']['html'] ) && '' !== $photo['user']['links']['html']
				? $photo['user']['links']['html']
				: 'https://unsplash.com'
		);

		// Attribution is kept so a duplicate import can return it; the caption may be edited later.
		$attribution = [
			self::UNSPLASH_PHOTOGRAPHER_META_KEY     => $photographer,
			self::UNSPLASH_PHOTOGRAPHER_URL_META_KEY => $photographer_url,
		];

		if ( null !== $existing_id ) {
			// A user who can read but not edit an unfinished import gets it with the credit from Unsplash, which only an
			// editor saves on it, rather than a second upload of the photo.
			if ( ! current_user_can( 'edit_post', $existing_id ) ) {
				// Uploading again would duplicate the attachment, and its details are not shown to a user who cannot read
				// it. Checked after the Unsplash request, since an editor may attach it to a private post meanwhile.
				if ( ! current_user_can( 'read_post', $existing_id ) ) {
					return new \WP_Error(
						'bricks_mcp_unsplash_import_incomplete',
						__( 'An earlier import of this Unsplash photo left an attachment without its attribution. A user who can edit that attachment must retry the import.', 'bricks-mcp' ),
						[ 'status' => 403 ]
					);
				}

				$reused = $this->reuse_existing_attachment( $existing_id, $photo_id, $post_id );

				return is_wp_error( $reused ) ? $reused : array_merge(
					$reused,
					[
						'photographer'    => '' !== $photographer ? $photographer : null,
						'photographerUrl' => $photographer_url,
					]
				);
			}

			// The attachment may already be in use, so a failure here keeps it for the next retry.
			$recorded = $this->record_unsplash_attribution( $existing_id, $attribution );
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}

			// After a marked failure the earlier call reported an error, so this call is the one that completed the import.
			$completed = $this->reuse_existing_attachment( $existing_id, $photo_id, $post_id );

			return is_wp_error( $completed ) || ! $pending ? $completed : array_merge( $completed, [ 'duplicate' => false ] );
		}

		$image_url = '';
		if ( isset( $photo['urls'] ) && is_array( $photo['urls'] ) ) {
			$image_url = (string) ( $photo['urls']['regular'] ?? $photo['urls']['full'] ?? '' );
		}

		if ( '' === $image_url ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_no_url',
				__( 'Unsplash photo response did not include a downloadable image URL.', 'bricks-mcp' ),
				[ 'status' => 502 ]
			);
		}

		$downloaded = $this->download_unsplash_image( $image_url, $photo_id );
		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$download_location = isset( $photo['links']['download_location'] ) && is_string( $photo['links']['download_location'] )
			? $photo['links']['download_location']
			: '';
		$this->ping_unsplash_download( $download_location, $api_key );

		$caption = '' !== $photographer
			/* translators: %s: photographer name */
			? sprintf( __( 'Photo by %s on Unsplash', 'bricks-mcp' ), $photographer )
			: __( 'Photo on Unsplash', 'bricks-mcp' );

		$default_alt   = $this->extract_photo_text( $photo, 'alt_description', 'description' );
		$default_title = $this->extract_photo_text( $photo, 'description', 'alt_description' );

		$alt   = isset( $input['alt'] ) && is_string( $input['alt'] ) && '' !== trim( $input['alt'] )
			? sanitize_text_field( $input['alt'] )
			: $default_alt;
		$title = isset( $input['title'] ) && is_string( $input['title'] ) && '' !== trim( $input['title'] )
			? sanitize_text_field( $input['title'] )
			: ( '' !== $default_title ? $default_title : 'Unsplash ' . $photo_id );

		$upload_input = [
			'base64'   => $downloaded['base64'],
			'filename' => $downloaded['filename'],
			'title'    => $title,
			'caption'  => $caption,
		];
		if ( '' !== $alt ) {
			$upload_input['alt'] = $alt;
		}

		$uploaded = Access::run_bricks_ability( 'bricks/upload-media', $upload_input );
		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}

		$attachment_id = is_array( $uploaded ) && isset( $uploaded['id'] ) ? (int) $uploaded['id'] : 0;
		if ( $attachment_id <= 0 ) {
			return new \WP_Error(
				'bricks_mcp_upload_failed',
				__( 'Bricks upload-media did not return a valid attachment ID.', 'bricks-mcp' ),
				[ 'status' => 500 ]
			);
		}

		// The photo ID goes first, so a retry finds this attachment by it; only a failure here leaves it unfindable.
		if ( ! $this->write_unsplash_meta( $attachment_id, [ self::UNSPLASH_META_KEY => $photo_id ] ) ) {
			// A retry cannot find an attachment without the photo ID, so remove it rather than leave a duplicate.
			$error_data = [ 'status' => 500 ];
			if ( ! wp_delete_attachment( $attachment_id, true ) ) {
				$error_data['attachmentId'] = $attachment_id;
			}

			return $this->unsplash_meta_error( $error_data );
		}

		// The pending mark lets any retry complete a failed attribution. If it does not persist, a retry still finds
		// this attachment by its photo ID, without a credit URL, and completes it when the caller can edit it.
		$this->write_unsplash_meta( $attachment_id, [ self::UNSPLASH_PENDING_META_KEY => '1' ] );

		$recorded = $this->record_unsplash_attribution( $attachment_id, $attribution );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		if ( $post_id > 0 ) {
			$attached = $this->attach_to_post( $attachment_id, $post_id );
			if ( is_wp_error( $attached ) ) {
				return $attached;
			}
		}

		return [
			'attachmentId'    => $attachment_id,
			'url'             => (string) ( $uploaded['url'] ?? '' ),
			'title'           => (string) ( $uploaded['title'] ?? $title ),
			'alt'             => (string) ( $uploaded['alt'] ?? $alt ),
			'caption'         => $caption,
			'mimeType'        => (string) ( $uploaded['mimeType'] ?? '' ),
			'width'           => (int) ( $uploaded['width'] ?? 0 ),
			'height'          => (int) ( $uploaded['height'] ?? 0 ),
			'postId'          => $post_id,
			'unsplashPhotoId' => $photo_id,
			'photographer'    => '' !== $photographer ? $photographer : null,
			'photographerUrl' => $photographer_url,
			'duplicate'       => false,
		];
	}

	/**
	 * List cached Adobe Fonts families and current Bricks font-loading settings.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>
	 */
	public function list_adobe_fonts( mixed $input = [] ): array {
		unset( $input );

		$settings   = $this->read_global_settings();
		$configured = ! empty( $settings['adobeFontsProjectId'] );
		$families   = [];

		if ( $configured ) {
			$cached = get_option( self::ADOBE_FONTS_OPTION, [] );
			if ( is_array( $cached ) ) {
				foreach ( $cached as $font ) {
					if ( ! is_array( $font ) ) {
						continue;
					}
					$name = isset( $font['name'] ) && is_string( $font['name'] ) ? $font['name'] : '';
					if ( '' === $name ) {
						continue;
					}
					$slug       = isset( $font['slug'] ) && is_string( $font['slug'] ) ? $font['slug'] : '';
					$css_names  = isset( $font['css_names'] ) && is_array( $font['css_names'] )
						? array_values( array_filter( $font['css_names'], 'is_string' ) )
						: [];
					$variations = isset( $font['variations'] ) && is_array( $font['variations'] )
						? array_values( array_filter( $font['variations'], 'is_string' ) )
						: [];

					$families[] = [
						'name'       => $name,
						'slug'       => $slug,
						'cssFamily'  => ! empty( $css_names ) ? implode( ', ', $css_names ) : $slug,
						'cssNames'   => $css_names,
						'variations' => $variations,
					];
				}
			}
		}

		return [
			'adobeFontsConfigured' => $configured,
			'families'             => $families,
			'count'                => count( $families ),
			'fontSettings'         => $this->read_font_settings( $settings ),
		];
	}

	/**
	 * Partial-merge update of Bricks font-loading global settings.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_font_settings( array $input ): array|\WP_Error {
		if ( empty( $input ) ) {
			return new \WP_Error(
				'bricks_mcp_empty_font_settings',
				__( 'Provide at least one font setting key to update.', 'bricks-mcp' ),
				[ 'status' => 400 ]
			);
		}

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_FONT_KEYS, true ) ) {
				return new \WP_Error(
					'bricks_mcp_unknown_font_setting',
					/* translators: %s: setting key name */
					sprintf( __( 'Unsupported font setting "%s". Use bricks/set-global-settings for other Bricks settings.', 'bricks-mcp' ), (string) $key ),
					[ 'status' => 400 ]
				);
			}
		}

		foreach ( self::BOOLEAN_FONT_KEYS as $bool_key ) {
			if ( array_key_exists( $bool_key, $input ) && ! is_bool( $input[ $bool_key ] ) ) {
				return new \WP_Error(
					'bricks_mcp_invalid_font_setting',
					/* translators: %s: setting key name */
					sprintf( __( 'Setting "%s" must be a boolean.', 'bricks-mcp' ), $bool_key ),
					[ 'status' => 400 ]
				);
			}
		}

		if ( array_key_exists( 'webfontLoading', $input ) ) {
			if ( ! is_string( $input['webfontLoading'] ) || ! in_array( $input['webfontLoading'], self::VALID_WEBFONT_LOADING, true ) ) {
				return new \WP_Error(
					'bricks_mcp_invalid_webfont_loading',
					__( 'webfontLoading must be "" (stylesheets) or "webfontloader".', 'bricks-mcp' ),
					[ 'status' => 400 ]
				);
			}
		}

		$current   = $this->read_global_settings();
		$next      = $current;
		$updated   = [];
		$unchanged = [];

		foreach ( self::BOOLEAN_FONT_KEYS as $bool_key ) {
			if ( ! array_key_exists( $bool_key, $input ) ) {
				continue;
			}

			$desired     = (bool) $input[ $bool_key ];
			$was_enabled = ! empty( $current[ $bool_key ] );

			if ( $desired === $was_enabled && ( $desired || ! array_key_exists( $bool_key, $current ) ) ) {
				$unchanged[] = $bool_key;
				continue;
			}

			if ( $desired ) {
				$next[ $bool_key ] = true;
			} else {
				unset( $next[ $bool_key ] );
			}
			$updated[] = $bool_key;
		}

		if ( array_key_exists( 'webfontLoading', $input ) ) {
			$desired_loading = $input['webfontLoading'];
			$current_loading = isset( $current['webfontLoading'] ) && 'webfontloader' === $current['webfontLoading']
				? 'webfontloader'
				: '';

			if ( $desired_loading === $current_loading && ( '' !== $desired_loading || ! array_key_exists( 'webfontLoading', $current ) ) ) {
				$unchanged[] = 'webfontLoading';
			} else {
				if ( 'webfontloader' === $desired_loading ) {
					$next['webfontLoading'] = 'webfontloader';
				} else {
					unset( $next['webfontLoading'] );
				}
				$updated[] = 'webfontLoading';
			}
		}

		$final_settings = $current;

		if ( ! empty( $updated ) ) {
			update_option( self::GLOBAL_SETTINGS_OPTION, $next );
			$persisted = $this->read_global_settings();

			if ( ! $this->font_keys_persisted( $persisted, $next, $updated ) ) {
				return new \WP_Error(
					'bricks_mcp_font_settings_persist_failed',
					__( 'Failed to persist Bricks font settings.', 'bricks-mcp' ),
					[ 'status' => 500 ]
				);
			}

			$this->sync_bricks_global_settings( $persisted );
			$final_settings = $persisted;
		}

		return [
			'updated'      => $updated,
			'unchanged'    => $unchanged,
			'fontSettings' => $this->read_font_settings( $final_settings ),
		];
	}

	/**
	 * Definition for bricks-mcp/search-unsplash.
	 *
	 * @return array<string, mixed>
	 */
	private function search_unsplash_definition(): array {
		return [
			'label'               => __( 'Search Unsplash photos', 'bricks-mcp' ),
			'description'         => __( 'Search stock photos on Unsplash using the API key configured in Bricks settings. Returns photo IDs, dimensions, preview URLs, photographer attribution links, and download locations for use with bricks-mcp/import-unsplash-photo. Use bricks/find-media to search the existing WordPress Media Library instead.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'query'       => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Search keywords for Unsplash photos.', 'bricks-mcp' ),
					],
					'page'        => [
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( '1-based results page number.', 'bricks-mcp' ),
					],
					'perPage'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 30,
						'default'     => 10,
						'description' => __( 'Number of photos per page (1 to 30).', 'bricks-mcp' ),
					],
					'orientation' => [
						'type'        => 'string',
						'enum'        => self::VALID_ORIENTATIONS,
						'description' => __( 'Optional orientation filter: landscape, portrait, or squarish.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'query' ],
			],
			'output_schema'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'total'      => [ 'type' => 'integer' ],
					'totalPages' => [ 'type' => 'integer' ],
					'page'       => [ 'type' => 'integer' ],
					'perPage'    => [ 'type' => 'integer' ],
					'results'    => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'id'               => [ 'type' => 'string' ],
								'description'      => [ 'type' => 'string' ],
								'alt'              => [ 'type' => 'string' ],
								'width'            => [ 'type' => 'integer' ],
								'height'           => [ 'type' => 'integer' ],
								'thumbUrl'         => [ 'type' => 'string' ],
								'regularUrl'       => [ 'type' => 'string' ],
								'photographer'     => [ 'type' => 'string' ],
								'photographerUrl'  => [ 'type' => 'string' ],
								'unsplashUrl'      => [ 'type' => 'string' ],
								'downloadLocation' => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
			'execute_callback'    => [ $this, 'search_unsplash' ],
			'permission_callback' => [ $this, 'search_unsplash_permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'      => true,
					'destructive'   => false,
					'idempotent'    => true,
					'openWorldHint' => true,
				],
			],
		];
	}

	/**
	 * Definition for bricks-mcp/import-unsplash-photo.
	 *
	 * @return array<string, mixed>
	 */
	private function import_unsplash_photo_definition(): array {
		return [
			'label'               => __( 'Import Unsplash photo', 'bricks-mcp' ),
			'description'         => __( 'Import an Unsplash photo into the WordPress Media Library by photoId, deduplicating on _unsplash_photo_id, triggering Unsplash download tracking, and adding photographer attribution as the caption. Downloads the image and delegates upload to bricks/upload-media. For non-Unsplash URLs or base64 files, call bricks/upload-media directly.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'photoId' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Unsplash photo ID returned by bricks-mcp/search-unsplash.', 'bricks-mcp' ),
					],
					'alt'     => [
						'type'        => 'string',
						'description' => __( 'Optional alt text override. Defaults to the Unsplash photo description.', 'bricks-mcp' ),
					],
					'title'   => [
						'type'        => 'string',
						'description' => __( 'Optional attachment title override.', 'bricks-mcp' ),
					],
					'postId'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Optional post ID to attach the imported media to.', 'bricks-mcp' ),
					],
				],
				'required'             => [ 'photoId' ],
			],
			'output_schema'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'attachmentId'    => [ 'type' => 'integer' ],
					'url'             => [ 'type' => 'string' ],
					'title'           => [ 'type' => 'string' ],
					'alt'             => [ 'type' => 'string' ],
					'caption'         => [ 'type' => 'string' ],
					'mimeType'        => [ 'type' => 'string' ],
					'width'           => [ 'type' => 'integer' ],
					'height'          => [ 'type' => 'integer' ],
					'postId'          => [ 'type' => 'integer' ],
					'unsplashPhotoId' => [ 'type' => 'string' ],
					'photographer'    => [
						'type'        => [ 'string', 'null' ],
						'description' => __( 'Photographer name; null when Unsplash returned no name or a duplicate attachment predates stored attribution.', 'bricks-mcp' ),
					],
					'photographerUrl' => [
						'type'        => [ 'string', 'null' ],
						'description' => __( 'Photographer profile URL; null when a duplicate attachment predates stored attribution.', 'bricks-mcp' ),
					],
					'duplicate'       => [ 'type' => 'boolean' ],
				],
			],
			'execute_callback'    => [ $this, 'import_unsplash_photo' ],
			'permission_callback' => [ $this, 'import_unsplash_permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'      => false,
					'destructive'   => false,
					'openWorldHint' => true,
				],
			],
		];
	}

	/**
	 * Definition for bricks-mcp/list-adobe-fonts.
	 *
	 * @return array<string, mixed>
	 */
	private function list_adobe_fonts_definition(): array {
		return [
			'label'               => __( 'List Adobe Fonts and font settings', 'bricks-mcp' ),
			'description'         => __( 'Return whether an Adobe Fonts project is configured (without exposing the project ID), the cached Adobe Fonts families with their CSS font-family names for Bricks typography settings, and current Bricks font-loading settings. Use bricks/list-custom-fonts for self-hosted custom fonts and bricks/get-global-settings for other Bricks settings.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'adobeFontsConfigured' => [ 'type' => 'boolean' ],
					'families'             => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'name'       => [ 'type' => 'string' ],
								'slug'       => [ 'type' => 'string' ],
								'cssFamily'  => [ 'type' => 'string' ],
								'cssNames'   => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'variations' => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
							],
						],
					],
					'count'                => [ 'type' => 'integer' ],
					'fontSettings'         => $this->font_settings_schema(),
				],
			],
			'execute_callback'    => [ $this, 'list_adobe_fonts' ],
			'permission_callback' => [ $this, 'manage_fonts_permission' ],
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
	 * Definition for bricks-mcp/update-font-settings.
	 *
	 * @return array<string, mixed>
	 */
	private function update_font_settings_definition(): array {
		return [
			'label'               => __( 'Update Bricks font settings', 'bricks-mcp' ),
			'description'         => __( 'Partially update Bricks font-loading settings (disableGoogleFonts, webfontLoading, customFontsPreload, googleFontsFilesystem) that are not writable via bricks/set-global-settings. Preserves all other Bricks global settings. Use bricks/set-global-settings for other global settings and bricks/update-custom-font for custom font families.', 'bricks-mcp' ),
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'minProperties'        => 1,
				'properties'           => [
					'disableGoogleFonts'    => [
						'type'        => 'boolean',
						'description' => __( 'Disable Google Fonts in Bricks.', 'bricks-mcp' ),
					],
					'webfontLoading'        => [
						'type'        => 'string',
						'enum'        => self::VALID_WEBFONT_LOADING,
						'description' => __( 'Webfont loading method: "" for default stylesheets or "webfontloader" for Webfont Loader JS.', 'bricks-mcp' ),
					],
					'customFontsPreload'    => [
						'type'        => 'boolean',
						'description' => __( 'Preload custom fonts to reduce flash of unstyled text.', 'bricks-mcp' ),
					],
					'googleFontsFilesystem' => [
						'type'        => 'boolean',
						'description' => __( 'Store Google font imports in a dedicated uploads folder outside the Media Library.', 'bricks-mcp' ),
					],
				],
				'required'             => [],
			],
			'output_schema'       => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'updated'      => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'unchanged'    => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
					'fontSettings' => $this->font_settings_schema(),
				],
			],
			'execute_callback'    => [ $this, 'update_font_settings' ],
			'permission_callback' => [ $this, 'manage_fonts_permission' ],
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
	 * Output schema fragment for Bricks font-loading settings.
	 *
	 * @return array<string, mixed>
	 */
	private function font_settings_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'disableGoogleFonts'    => [ 'type' => 'boolean' ],
				'webfontLoading'        => [
					'type' => 'string',
					'enum' => self::VALID_WEBFONT_LOADING,
				],
				'customFontsPreload'    => [ 'type' => 'boolean' ],
				'googleFontsFilesystem' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Verify that the current user can upload files.
	 *
	 * @return true|\WP_Error
	 */
	private function check_upload_permission(): bool|\WP_Error {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}

		return new \WP_Error(
			'bricks_mcp_forbidden',
			__( 'You are not allowed to upload media files.', 'bricks-mcp' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Read the Bricks-stored Unsplash API key.
	 *
	 * @return string
	 */
	private function get_unsplash_api_key(): string {
		if ( class_exists( '\Bricks\Database' ) ) {
			$setting = \Bricks\Database::get_setting( 'apiKeyUnsplash', '' );
			if ( is_string( $setting ) && '' !== trim( $setting ) ) {
				return trim( $setting );
			}
		}

		$settings = $this->read_global_settings();
		if ( isset( $settings['apiKeyUnsplash'] ) && is_string( $settings['apiKeyUnsplash'] ) ) {
			return trim( $settings['apiKeyUnsplash'] );
		}

		return '';
	}

	/**
	 * Error returned when the Bricks Unsplash API key is missing.
	 *
	 * @return \WP_Error
	 */
	private function missing_unsplash_key_error(): \WP_Error {
		return new \WP_Error(
			'bricks_mcp_unsplash_no_key',
			__( 'Unsplash API key is not configured. A site administrator must add it under Bricks > Settings > API keys.', 'bricks-mcp' ),
			[ 'status' => 412 ]
		);
	}

	/**
	 * Perform a GET request against the Unsplash API and decode its JSON body.
	 *
	 * @param string $url     Full Unsplash API URL.
	 * @param string $api_key Unsplash API access key.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function unsplash_get_json( string $url, string $api_key ): array|\WP_Error {
		$response = wp_safe_remote_get(
			$url,
			[
				'headers' => [
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_http_error',
				$response->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$status = ( $code >= 400 && $code < 600 ) ? $code : 502;
			if ( 404 === $code ) {
				return new \WP_Error(
					'bricks_mcp_unsplash_not_found',
					__( 'Unsplash photo not found.', 'bricks-mcp' ),
					[ 'status' => 404 ]
				);
			}

			return new \WP_Error(
				'bricks_mcp_unsplash_api_error',
				/* translators: %d: HTTP status code from Unsplash API */
				sprintf( __( 'Unsplash API returned HTTP %d. Check the API key under Bricks > Settings > API keys and try again.', 'bricks-mcp' ), $code ),
				[ 'status' => $status ]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_parse_error',
				__( 'Failed to parse Unsplash API response.', 'bricks-mcp' ),
				[ 'status' => 502 ]
			);
		}

		return $decoded;
	}

	/**
	 * Ping Unsplash's download_location endpoint when its host is strictly api.unsplash.com.
	 *
	 * @param string $download_location Download tracking URL returned by Unsplash.
	 * @param string $api_key           Unsplash API access key.
	 * @return void
	 */
	private function ping_unsplash_download( string $download_location, string $api_key ): void {
		if ( '' === $download_location || '' === $api_key ) {
			return;
		}

		$scheme = wp_parse_url( $download_location, PHP_URL_SCHEME );
		$host   = wp_parse_url( $download_location, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) || self::UNSPLASH_API_HOST !== strtolower( (string) $host ) ) {
			return;
		}

		wp_safe_remote_get(
			$download_location,
			[
				'headers'  => [
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				],
				'timeout'  => 5,
				'blocking' => false,
			]
		);
	}

	/**
	 * Format a single Unsplash search photo record.
	 *
	 * @param array<string, mixed> $photo Raw photo payload from Unsplash.
	 * @return array<string, mixed>
	 */
	private function format_unsplash_result( array $photo ): array {
		$id               = (string) ( $photo['id'] ?? '' );
		$urls             = isset( $photo['urls'] ) && is_array( $photo['urls'] ) ? $photo['urls'] : [];
		$links            = isset( $photo['links'] ) && is_array( $photo['links'] ) ? $photo['links'] : [];
		$user             = isset( $photo['user'] ) && is_array( $photo['user'] ) ? $photo['user'] : [];
		$user_links       = isset( $user['links'] ) && is_array( $user['links'] ) ? $user['links'] : [];
		$profile_base_url = isset( $user_links['html'] ) && is_string( $user_links['html'] ) && '' !== $user_links['html']
			? $user_links['html']
			: 'https://unsplash.com';

		return [
			'id'               => $id,
			'description'      => $this->extract_photo_text( $photo, 'description', 'alt_description' ),
			'alt'              => $this->extract_photo_text( $photo, 'alt_description', 'description' ),
			'width'            => (int) ( $photo['width'] ?? 0 ),
			'height'           => (int) ( $photo['height'] ?? 0 ),
			'thumbUrl'         => (string) ( $urls['thumb'] ?? $urls['small'] ?? '' ),
			'regularUrl'       => (string) ( $urls['regular'] ?? '' ),
			'photographer'     => isset( $user['name'] ) && is_string( $user['name'] ) ? $user['name'] : '',
			'photographerUrl'  => $this->with_unsplash_utm( $profile_base_url ),
			'unsplashUrl'      => $this->with_unsplash_utm( 'https://unsplash.com/photos/' . rawurlencode( $id ) ),
			'downloadLocation' => isset( $links['download_location'] ) && is_string( $links['download_location'] ) ? $links['download_location'] : '',
		];
	}

	/**
	 * Append Unsplash referral UTM parameters to a URL.
	 *
	 * @param string $url Base URL.
	 * @return string
	 */
	private function with_unsplash_utm( string $url ): string {
		$separator = false !== strpos( $url, '?' ) ? '&' : '?';

		return $url . $separator . self::UNSPLASH_UTM;
	}

	/**
	 * Extract a sanitized string field from an Unsplash photo payload with fallback.
	 *
	 * @param array<string, mixed> $photo    Unsplash photo data.
	 * @param string               $primary  Primary key.
	 * @param string               $fallback Fallback key.
	 * @return string
	 */
	private function extract_photo_text( array $photo, string $primary, string $fallback ): string {
		if ( isset( $photo[ $primary ] ) && is_string( $photo[ $primary ] ) && '' !== trim( $photo[ $primary ] ) ) {
			return sanitize_text_field( $photo[ $primary ] );
		}

		if ( isset( $photo[ $fallback ] ) && is_string( $photo[ $fallback ] ) && '' !== trim( $photo[ $fallback ] ) ) {
			return sanitize_text_field( $photo[ $fallback ] );
		}

		return '';
	}

	/**
	 * Save meta values on an attachment, stopping at the first one that does not persist.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string,string> $meta          Meta values by key, in write order.
	 * @return bool Whether every value was saved.
	 */
	private function write_unsplash_meta( int $attachment_id, array $meta ): bool {
		foreach ( $meta as $meta_key => $meta_value ) {
			// Slashed because update_post_meta() unslashes, which would drop literal backslashes.
			update_post_meta( $attachment_id, $meta_key, wp_slash( $meta_value ) );
			if ( (string) get_post_meta( $attachment_id, $meta_key, true ) !== $meta_value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Save the photographer attribution of an attachment marked with its photo ID, then clear its pending mark.
	 *
	 * On failure the attachment stays, as it may already be in use, and a retry finds it by the photo ID.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string,string> $attribution   Attribution meta values by key.
	 * @return true|\WP_Error
	 */
	private function record_unsplash_attribution( int $attachment_id, array $attribution ): true|\WP_Error {
		if ( ! $this->write_unsplash_meta( $attachment_id, $attribution ) ) {
			return $this->unsplash_meta_error(
				[
					'status'       => 500,
					'attachmentId' => $attachment_id,
				]
			);
		}

		// A mark left behind is ignored, since the credit URL saved last shows the attribution is complete.
		delete_post_meta( $attachment_id, self::UNSPLASH_PENDING_META_KEY );

		return true;
	}

	/**
	 * Error for Unsplash import meta that did not persist on the uploaded attachment.
	 *
	 * @param array<string, int> $error_data Error data.
	 * @return \WP_Error
	 */
	private function unsplash_meta_error( array $error_data ): \WP_Error {
		return new \WP_Error(
			'bricks_mcp_unsplash_meta_failed',
			__( 'Failed to record Unsplash photo metadata on the uploaded attachment.', 'bricks-mcp' ),
			$error_data
		);
	}

	/**
	 * Return an attachment already in the library for this photo, attaching it to the post when it is unattached.
	 *
	 * @param int    $attachment_id Existing attachment ID.
	 * @param string $photo_id      Unsplash photo ID.
	 * @param int    $post_id       Requested parent post ID, or 0.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function reuse_existing_attachment( int $attachment_id, string $photo_id, int $post_id ): array|\WP_Error {
		if ( $post_id > 0 && current_user_can( 'edit_post', $attachment_id ) ) {
			$existing_post = get_post( $attachment_id );
			if ( $existing_post && 0 === (int) ( $existing_post->post_parent ?? 0 ) ) {
				$attached = $this->attach_to_post( $attachment_id, $post_id );
				if ( is_wp_error( $attached ) ) {
					return $attached;
				}
			}
		}

		return $this->format_existing_attachment( $attachment_id, $photo_id );
	}

	/**
	 * Find an already-imported WordPress attachment by Unsplash photo ID.
	 *
	 * @param string $photo_id Unsplash photo ID.
	 * @return int|null
	 */
	private function find_existing_unsplash_attachment( string $photo_id ): ?int {
		$existing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => self::UNSPLASH_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded 1-row lookup for Unsplash deduplication.
				'meta_value'     => $photo_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded 1-row lookup for Unsplash deduplication.
				// Photo IDs are case-sensitive, but MySQL compares meta values without case, so the one row could be a case variant.
				'meta_type'      => 'BINARY',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);

		if ( empty( $existing ) ) {
			return null;
		}

		$first = $existing[0];
		$id    = is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : (int) $first;

		return $id > 0 ? $id : null;
	}

	/**
	 * Build the import response for a deduplicated existing attachment.
	 *
	 * @param int    $attachment_id Existing attachment ID.
	 * @param string $photo_id      Unsplash photo ID.
	 * @return array<string, mixed>
	 */
	private function format_existing_attachment( int $attachment_id, string $photo_id ): array {
		$post     = get_post( $attachment_id );
		$url      = wp_get_attachment_url( $attachment_id );
		$alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];

		// Attachments imported before attribution meta existed have no stored URL, and a photo without a
		// user name stores an empty one: report either as unknown (null).
		$photographer     = null;
		$photographer_url = get_post_meta( $attachment_id, self::UNSPLASH_PHOTOGRAPHER_URL_META_KEY, true );
		if ( is_string( $photographer_url ) && '' !== $photographer_url ) {
			$name         = get_post_meta( $attachment_id, self::UNSPLASH_PHOTOGRAPHER_META_KEY, true );
			$photographer = is_string( $name ) && '' !== $name ? $name : null;
		} else {
			$photographer_url = null;
		}

		return [
			'attachmentId'    => $attachment_id,
			'url'             => is_string( $url ) ? $url : '',
			'title'           => $post ? (string) ( $post->post_title ?? '' ) : '',
			'alt'             => is_string( $alt ) ? $alt : '',
			'caption'         => $post ? (string) ( $post->post_excerpt ?? '' ) : '',
			'mimeType'        => $post ? (string) ( $post->post_mime_type ?? '' ) : '',
			'width'           => (int) ( $metadata['width'] ?? 0 ),
			'height'          => (int) ( $metadata['height'] ?? 0 ),
			'postId'          => $post ? (int) ( $post->post_parent ?? 0 ) : 0,
			'unsplashPhotoId' => $photo_id,
			'photographer'    => $photographer,
			'photographerUrl' => $photographer_url,
			'duplicate'       => true,
		];
	}

	/**
	 * Read the `bricks_global_settings` option as an array.
	 *
	 * @return array<string, mixed>
	 */
	private function read_global_settings(): array {
		$settings = get_option( self::GLOBAL_SETTINGS_OPTION, [] );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Normalize Bricks font-loading settings from global settings storage.
	 *
	 * @param array<string, mixed> $settings Raw `bricks_global_settings` array.
	 * @return array<string, mixed>
	 */
	private function read_font_settings( array $settings ): array {
		return [
			'disableGoogleFonts'    => ! empty( $settings['disableGoogleFonts'] ),
			'webfontLoading'        => isset( $settings['webfontLoading'] ) && 'webfontloader' === $settings['webfontLoading']
				? 'webfontloader'
				: '',
			'customFontsPreload'    => ! empty( $settings['customFontsPreload'] ),
			'googleFontsFilesystem' => ! empty( $settings['googleFontsFilesystem'] ),
		];
	}

	/**
	 * Download an Unsplash image from images.unsplash.com and return base64 data and filename.
	 *
	 * @param string $image_url Image URL from the Unsplash photo payload.
	 * @param string $photo_id  Validated Unsplash photo ID.
	 * @return array{base64: string, filename: string}|\WP_Error
	 */
	private function download_unsplash_image( string $image_url, string $photo_id ): array|\WP_Error {
		$parts           = wp_parse_url( $image_url );
		$scheme          = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host            = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port            = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$has_credentials = is_array( $parts ) && ( isset( $parts['user'] ) || isset( $parts['pass'] ) );

		if ( 'https' !== $scheme || self::UNSPLASH_IMAGE_HOST !== $host || $has_credentials || ( null !== $port && 443 !== $port ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_invalid_image_url',
				__( 'Unsplash image URL must use https://images.unsplash.com.', 'bricks-mcp' ),
				[ 'status' => 502 ]
			);
		}

		$max_bytes = $this->max_image_upload_bytes();
		$response  = wp_safe_remote_get(
			$image_url,
			[
				'timeout'             => 20,
				'limit_response_size' => $max_bytes + 1,
				'redirection'         => 0,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_download_error',
				$response->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_download_error',
				/* translators: %d: HTTP status code from Unsplash image download */
				sprintf( __( 'Unsplash image download returned HTTP %d.', 'bricks-mcp' ), $code ),
				[ 'status' => 502 ]
			);
		}

		$raw_content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$mime_type        = strtolower( trim( explode( ';', $raw_content_type, 2 )[0] ) );
		if ( ! isset( self::ALLOWED_IMAGE_MIMES[ $mime_type ] ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_invalid_content_type',
				/* translators: %s: Content-Type header returned by Unsplash image download */
				sprintf( __( 'Unsplash image returned unsupported content type "%s". Expected image/jpeg, image/png, or image/webp.', 'bricks-mcp' ), '' !== $mime_type ? $mime_type : 'unknown' ),
				[ 'status' => 502 ]
			);
		}

		$content_length = trim( (string) wp_remote_retrieve_header( $response, 'content-length' ) );
		if ( '' !== $content_length && ctype_digit( $content_length ) && (int) $content_length > $max_bytes ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_image_too_large',
				/* translators: %d: maximum allowed upload size in bytes */
				sprintf( __( 'Unsplash image exceeds the maximum upload size of %d bytes.', 'bricks-mcp' ), $max_bytes ),
				[ 'status' => 413 ]
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_empty_image',
				__( 'Unsplash image download returned an empty body.', 'bricks-mcp' ),
				[ 'status' => 502 ]
			);
		}

		if ( strlen( $body ) > $max_bytes ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_image_too_large',
				/* translators: %d: maximum allowed upload size in bytes */
				sprintf( __( 'Unsplash image exceeds the maximum upload size of %d bytes.', 'bricks-mcp' ), $max_bytes ),
				[ 'status' => 413 ]
			);
		}

		return [
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by bricks/upload-media base64 input.
			'base64'   => base64_encode( $body ),
			'filename' => 'unsplash-' . $photo_id . '.' . self::ALLOWED_IMAGE_MIMES[ $mime_type ],
		];
	}

	/**
	 * Effective byte ceiling for Unsplash image downloads and base64 upload.
	 *
	 * @return int
	 */
	private function max_image_upload_bytes(): int {
		$site_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 10485760;
		if ( $site_limit <= 0 ) {
			$site_limit = 10485760;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Bricks' own filter name.
		$bricks_limit = max( 1, (int) apply_filters( 'bricks/abilities/content/max_media_upload_bytes', $site_limit ) );

		return min( self::MAX_IMAGE_DOWNLOAD_BYTES, $bricks_limit );
	}

	/**
	 * Attach an uploaded or deduplicated attachment to a parent post and verify persistence.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @param int $post_id       Parent post ID.
	 * @return true|\WP_Error
	 */
	private function attach_to_post( int $attachment_id, int $post_id ): bool|\WP_Error {
		$updated = wp_update_post(
			[
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_attach_failed',
				$updated->get_error_message(),
				[
					'status'       => 500,
					'attachmentId' => $attachment_id,
					'postId'       => $post_id,
				]
			);
		}

		if ( (int) $updated !== $attachment_id ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_attach_failed',
				__( 'Failed to attach the imported media to the target post.', 'bricks-mcp' ),
				[
					'status'       => 500,
					'attachmentId' => $attachment_id,
					'postId'       => $post_id,
				]
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || (int) ( $post->post_parent ?? 0 ) !== $post_id ) {
			return new \WP_Error(
				'bricks_mcp_unsplash_attach_failed',
				__( 'Failed to verify the attachment post_parent update.', 'bricks-mcp' ),
				[
					'status'       => 500,
					'attachmentId' => $attachment_id,
					'postId'       => $post_id,
				]
			);
		}

		return true;
	}

	/**
	 * Verify that every updated font setting key persisted in `bricks_global_settings`.
	 *
	 * @param array<string, mixed> $persisted Re-read option value.
	 * @param array<string, mixed> $expected  Merged option value written by this request.
	 * @param array<int, string>   $keys      Keys modified by this request.
	 * @return bool
	 */
	private function font_keys_persisted( array $persisted, array $expected, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $expected ) ) {
				if ( ! array_key_exists( $key, $persisted ) || $persisted[ $key ] !== $expected[ $key ] ) {
					return false;
				}
			} elseif ( array_key_exists( $key, $persisted ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Refresh Bricks' in-memory global settings cache after an option write.
	 *
	 * @param array<string, mixed> $settings Updated global settings array.
	 * @return void
	 */
	private function sync_bricks_global_settings( array $settings ): void {
		if ( ! class_exists( '\Bricks\Database' ) ) {
			return;
		}

		\Bricks\Database::$global_settings = $settings;

		if ( property_exists( '\Bricks\Database', 'global_data' ) && is_array( \Bricks\Database::$global_data ) ) {
			\Bricks\Database::$global_data['settings'] = $settings;
		}
	}
}
