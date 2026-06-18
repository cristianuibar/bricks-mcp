<?php
/**
 * File: includes/MCP/Services/MediaService.php
 *
 * Media service for Unsplash search, image sideloading, and media library operations.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 * @version 1.5.2
 *
 * Changelog:
 * ----------
 * 1.5.2 — 2026-03-31
 *   - FIX (GitHub #10): sideload_from_url() — added DNS-rebinding guard.
 *                       wp_http_validate_url() blocks obvious private IP literals but
 *                       does NOT protect against DNS rebinding where a public domain
 *                       resolves to a private IP at request time. download_url() uses
 *                       wp_remote_get() internally (not wp_safe_remote_get()), so
 *                       we now call gethostbyname() and validate the resolved IP against
 *                       private/reserved ranges (10.x, 172.16.x, 192.168.x, 127.x,
 *                       169.254.x, 0.x) before proceeding with the download.
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MediaService class.
 *
 * Handles Unsplash API proxy, image sideloading into WordPress media library,
 * media library queries, and Bricks image object building.
 */
class MediaService {

	/**
	 * Unsplash API base URL.
	 *
	 * @var string
	 */
	private const UNSPLASH_API_URL = 'https://api.unsplash.com/search/photos';

	/**
	 * UTM parameters for Unsplash attribution links.
	 *
	 * @var string
	 */
	private const UTM_PARAMS = 'utm_source=bricks_mcp&utm_medium=referral';

	/**
	 * Search Unsplash photos using the Bricks-stored API key.
	 *
	 * @param string $query Search query for photos.
	 * @return array{total: int, results: array}|\WP_Error Search results or error.
	 */
	public function search_photos( string $query ): array|\WP_Error {
		$api_key = $this->get_unsplash_api_key();
		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'unsplash_no_key',
				__( 'Unsplash API key not configured. Add your key in Bricks > Settings > API Keys > Unsplash.', 'bricks-mcp' )
			);
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'query'    => $query,
					'per_page' => 5,
				),
				self::UNSPLASH_API_URL
			),
			array(
				'headers' => array(
					'Authorization'  => 'Client-ID ' . $api_key,
					'Accept-Version' => 'v1',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'unsplash_api_error',
				/* translators: %d: HTTP status code from Unsplash API */
				sprintf( __( 'Unsplash API returned HTTP %d. Check your API key in Bricks settings and try again.', 'bricks-mcp' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['results'] ) ) {
			return new \WP_Error(
				'unsplash_parse_error',
				__( 'Failed to parse Unsplash API response.', 'bricks-mcp' )
			);
		}

		$results = array();
		foreach ( $body['results'] as $photo ) {
			$results[] = array(
				'id'                => $photo['id'],
				'description'       => $photo['description'] ?? $photo['alt_description'] ?? '',
				'urls'              => array(
					'full'    => $photo['urls']['full'] ?? '',
					'regular' => $photo['urls']['regular'] ?? '',
					'small'   => $photo['urls']['small'] ?? '',
				),
				'download_location' => $photo['links']['download_location'] ?? '',
				'photographer'      => $photo['user']['name'] ?? '',
				'photographer_url'  => ( $photo['user']['links']['html'] ?? 'https://unsplash.com' ) . '?' . self::UTM_PARAMS,
				'unsplash_url'      => 'https://unsplash.com/photos/' . $photo['id'] . '?' . self::UTM_PARAMS,
			);
		}

		return array(
			'total'   => $body['total'] ?? 0,
			'results' => $results,
		);
	}

	/**
	 * Sideload an image from a URL into the WordPress media library.
	 *
	 * @param string      $url               Image URL to download.
	 * @param string      $alt_text          Alt text for the image.
	 * @param string      $title             Title for the media library entry.
	 * @param string|null $unsplash_id       Unsplash photo ID for duplicate detection.
	 * @param string|null $download_location Unsplash download_location URL for tracking.
	 * @return array{attachment_id: int, url: string, title: string, alt_text: string, mime_type: string, duplicate: bool, bricks_image_object: array}|\WP_Error Sideload result or error.
	 */
	public function sideload_from_url(
		string $url,
		string $alt_text = '',
		string $title = '',
		?string $unsplash_id = null,
		?string $download_location = null
	): array|\WP_Error {
		// Validate URL scheme — only HTTP(S) allowed to prevent SSRF.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_scheme', 'Only HTTP and HTTPS URLs are allowed.' );
		}

		// Validate URL against internal/private IPs.
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', 'URL validation failed.' );
		}

		// GitHub #10: DNS-rebinding guard — wp_http_validate_url() blocks obvious private IP
		// literals but does NOT protect against DNS rebinding, where a public-looking domain
		// resolves to a private IP at request time. download_url() calls wp_remote_get()
		// (not wp_safe_remote_get()) internally, so we must validate the resolved IP ourselves.
		$_mcp_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $_mcp_host ) {
			$_mcp_resolved = gethostbyname( $_mcp_host );
			if ( $_mcp_resolved !== $_mcp_host ) {
				$_mcp_private = array(
					array( '10.0.0.0',   '10.255.255.255'  ),
					array( '172.16.0.0',  '172.31.255.255'  ),
					array( '192.168.0.0', '192.168.255.255' ),
					array( '127.0.0.0',   '127.255.255.255' ),
					array( '169.254.0.0', '169.254.255.255' ),
					array( '0.0.0.0',     '0.255.255.255'   ),
				);
				$_mcp_ip_long = ip2long( $_mcp_resolved );
				if ( false !== $_mcp_ip_long ) {
					foreach ( $_mcp_private as $_mcp_range ) {
						if ( $_mcp_ip_long >= ip2long( $_mcp_range[0] ) && $_mcp_ip_long <= ip2long( $_mcp_range[1] ) ) {
							return new \WP_Error(
								'ssrf_blocked',
								__( 'Image URL resolves to a private or reserved IP address and cannot be sideloaded.', 'bricks-mcp' )
							);
						}
					}
				}
			}
		}

		// Require admin includes for sideloading (safe to call multiple times).
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Duplicate detection for Unsplash photos.
		if ( null !== $unsplash_id && '' !== $unsplash_id ) {
			$existing = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'meta_key'       => '_unsplash_photo_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => sanitize_text_field( $unsplash_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( ! empty( $existing ) ) {
				return $this->build_sideload_response( (int) $existing[0], true );
			}
		}

		// Download to temp file.
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Build clean filename: strip query string, sanitize.
		$clean_url = preg_replace( '/\?.*/', '', $url );
		$filename  = sanitize_file_name( wp_basename( $clean_url ) );
		if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$filename .= '.jpg';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$post_data = array();
		if ( '' !== $title ) {
			$post_data['post_title'] = sanitize_text_field( $title );
		}

		$attachment_id = media_handle_sideload( $file_array, 0, '' !== $alt_text ? $alt_text : null, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		// Set alt text on the attachment.
		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt_text ) );
		}

		// Store Unsplash photo ID for duplicate detection.
		if ( null !== $unsplash_id && '' !== $unsplash_id ) {
			update_post_meta( $attachment_id, '_unsplash_photo_id', sanitize_text_field( $unsplash_id ) );
		}

		// Fire Unsplash download tracking (required by API guidelines).
		// Validate domain to prevent API key leakage to attacker-controlled URLs.
		if ( null !== $download_location && '' !== $download_location ) {
			$dl_host = wp_parse_url( $download_location, PHP_URL_HOST );
			if ( 'api.unsplash.com' === $dl_host ) {
				$api_key = $this->get_unsplash_api_key();
				if ( '' !== $api_key ) {
					wp_remote_get(
						$download_location,
						array(
							'headers'  => array( 'Authorization' => 'Client-ID ' . $api_key ),
							'blocking' => false,
						)
					);
				}
			}
		}

		return $this->build_sideload_response( $attachment_id, false );
	}

	/**
	 * Build the response array for a sideloaded image.
	 *
	 * @param int  $attachment_id WordPress attachment ID.
	 * @param bool $duplicate     Whether this was a duplicate detection match.
	 * @return array{attachment_id: int, url: string, title: string, alt_text: string, mime_type: string, duplicate: bool, bricks_image_object: array} Sideload response data.
	 */
	public function build_sideload_response( int $attachment_id, bool $duplicate ): array {
		$url      = wp_get_attachment_url( $attachment_id );
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$response = array(
			'attachment_id'       => $attachment_id,
			'url'                 => $url ? $url : '',
			'title'               => get_the_title( $attachment_id ),
			'alt_text'            => is_string( $alt_text ) ? $alt_text : '',
			'mime_type'           => get_post_mime_type( $attachment_id ) ? get_post_mime_type( $attachment_id ) : '',
			'duplicate'           => $duplicate,
			'bricks_image_object' => $this->build_bricks_image_object( $attachment_id ),
		);

		return $response;
	}

	/**
	 * Build the Bricks image settings object from a WordPress attachment ID.
	 *
	 * Returns the {id, filename, size, full, url} format used by Bricks Builder
	 * for Image elements, background images, and Gallery elements.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $size          WordPress image size (full, large, medium, thumbnail).
	 * @return array{id: int, filename: string, size: string, full: string, url: string}|\WP_Error Image object or error.
	 */
	public function build_bricks_image_object( int $attachment_id, string $size = 'full' ): array|\WP_Error {
		$full_url = wp_get_attachment_url( $attachment_id );
		if ( ! $full_url ) {
			return new \WP_Error(
				'attachment_not_found',
				/* translators: %d: attachment ID */
				sprintf( __( 'Attachment %d not found.', 'bricks-mcp' ), $attachment_id )
			);
		}

		$sized_url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $sized_url ) {
			$sized_url = $full_url;
		}

		return array(
			'id'       => $attachment_id,
			'filename' => basename( $full_url ),
			'size'     => $size,
			'full'     => $full_url,
			'url'      => $sized_url,
		);
	}

	/**
	 * Query the WordPress media library for image attachments.
	 *
	 * @param string $search    Search keyword to filter by title or filename.
	 * @param string $mime_type MIME type filter (e.g., 'image', 'image/jpeg').
	 * @param int    $per_page  Results per page (max 100).
	 * @param int    $page      Page number.
	 * @return array{items: array, total: int, page: int, total_pages: int} Media library results.
	 */
	public function get_media_library_items( string $search = '', string $mime_type = 'image', int $per_page = 20, int $page = 1 ): array {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( $per_page, 100 ),
			'paged'          => max( $page, 1 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		if ( '' !== $mime_type ) {
			$query_args['post_mime_type'] = $mime_type;
		}

		$query = new \WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$attachment_url = wp_get_attachment_url( $post->ID );
			$alt_text       = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );

			$sizes            = array();
			$registered_sizes = get_intermediate_image_sizes();
			foreach ( $registered_sizes as $size_name ) {
				$src = wp_get_attachment_image_src( $post->ID, $size_name );
				if ( $src ) {
					$sizes[ $size_name ] = array(
						'url'    => $src[0],
						'width'  => $src[1],
						'height' => $src[2],
					);
				}
			}

			$items[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'url'       => $attachment_url ? $attachment_url : '',
				'alt_text'  => is_string( $alt_text ) ? $alt_text : '',
				'mime_type' => $post->post_mime_type,
				'date'      => $post->post_date,
				'filename'  => $attachment_url ? basename( $attachment_url ) : '',
				'sizes'     => $sizes,
			);
		}

		return array(
			'items'       => $items,
			'total'       => $query->found_posts,
			'page'        => max( $page, 1 ),
			'total_pages' => $query->max_num_pages,
		);
	}

	/**
	 * Get the Unsplash API key from Bricks global settings.
	 *
	 * @return string API key or empty string if not configured.
	 */
	private function get_unsplash_api_key(): string {
		$settings = get_option( 'bricks_global_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}

		return $settings['apiKeyUnsplash'] ?? $settings['unsplashAccessKey'] ?? '';
	}
}