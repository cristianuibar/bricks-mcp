<?php
/**
 * Unit tests for BricksMCP\Abilities\Media.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\Media;
use BricksMCP\Abilities\Registrar;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour tests for the Media ability module.
 */
final class MediaTest extends TestCase {

	/**
	 * Reset in-memory WordPress and Bricks test stores before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		bricks_mcp_test_reset();
		$this->reset_media_globals();

		\Bricks\Database::$global_settings     = [];
		\Bricks\Capabilities::$can_use_builder = true;

		foreach ( ( new Media() )->abilities() as $name => $args ) {
			wp_register_ability( $name, Registrar::prepare( $args ) );
		}
	}

	/**
	 * Clear Media-specific stub globals so state never leaks into other tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_media_globals();
		parent::tearDown();
	}

	/**
	 * Reset Media-specific stub globals to their defaults.
	 *
	 * @return void
	 */
	private function reset_media_globals(): void {
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls']    = [];
		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return']   = [];
		$GLOBALS['_bricks_mcp_test_get_posts_return']            = [];
		$GLOBALS['_bricks_mcp_test_last_get_posts_args']         = [];
		$GLOBALS['_bricks_mcp_test_max_upload_size']             = 10485760;
		$GLOBALS['_bricks_mcp_test_media_http_handler']          = null;
		$GLOBALS['_bricks_mcp_test_media_update_option_fail']    = false;
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
		$GLOBALS['_bricks_mcp_test_media_wp_update_post_fail']   = false;
	}

	/**
	 * Search maps Unsplash results with UTM attribution and never leaks the API key.
	 *
	 * @return void
	 */
	public function test_search_unsplash_maps_results_and_hides_api_key(): void {
		$secret_key                        = 'unsplash-secret-key-xyz-999';
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => $secret_key ];

		$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_return'] = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'total'       => 42,
					'total_pages' => 5,
					'results'     => [
						[
							'id'              => 'abc_123',
							'description'     => 'Mountain sunrise',
							'alt_description' => 'Orange sunrise over peaks',
							'width'           => 4000,
							'height'          => 3000,
							'urls'            => [
								'thumb'   => 'https://images.unsplash.com/photo-1?w=200',
								'regular' => 'https://images.unsplash.com/photo-1?w=1080',
							],
							'links'           => [
								'download_location' => 'https://api.unsplash.com/photos/abc_123/download?ixid=1',
							],
							'user'            => [
								'name'  => 'Jane Doe',
								'links' => [ 'html' => 'https://unsplash.com/@janedoe' ],
							],
						],
					],
				]
			),
		];

		$result = wp_get_ability( 'bricks-mcp/search-unsplash' )->execute(
			[
				'query'       => 'mountain',
				'page'        => 2,
				'perPage'     => 15,
				'orientation' => 'landscape',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['total'] );
		$this->assertSame( 5, $result['totalPages'] );
		$this->assertSame( 2, $result['page'] );
		$this->assertSame( 15, $result['perPage'] );
		$this->assertCount( 1, $result['results'] );

		$photo = $result['results'][0];
		$this->assertSame( 'abc_123', $photo['id'] );
		$this->assertSame( 'Mountain sunrise', $photo['description'] );
		$this->assertSame( 'Orange sunrise over peaks', $photo['alt'] );
		$this->assertSame( 4000, $photo['width'] );
		$this->assertSame( 3000, $photo['height'] );
		$this->assertSame( 'https://images.unsplash.com/photo-1?w=200', $photo['thumbUrl'] );
		$this->assertSame( 'https://images.unsplash.com/photo-1?w=1080', $photo['regularUrl'] );
		$this->assertSame( 'Jane Doe', $photo['photographer'] );
		$this->assertSame( 'https://unsplash.com/@janedoe?utm_source=bricks_mcp&utm_medium=referral', $photo['photographerUrl'] );
		$this->assertSame( 'https://api.unsplash.com/photos/abc_123/download?ixid=1', $photo['downloadLocation'] );
		$this->assertStringNotContainsString( $secret_key, (string) wp_json_encode( $result ) );

		$calls = $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'];
		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( 'query=mountain', $calls[0]['url'] );
		$this->assertStringContainsString( 'page=2', $calls[0]['url'] );
		$this->assertStringContainsString( 'per_page=15', $calls[0]['url'] );
		$this->assertStringContainsString( 'orientation=landscape', $calls[0]['url'] );
		$this->assertSame( 'Client-ID ' . $secret_key, $calls[0]['args']['headers']['Authorization'] );
	}

	/**
	 * Search returns a clear error when no Unsplash API key is configured.
	 *
	 * @return void
	 */
	public function test_search_unsplash_returns_error_when_key_missing(): void {
		$result = wp_get_ability( 'bricks-mcp/search-unsplash' )->execute( [ 'query' => 'ocean' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_unsplash_no_key', $result->get_error_code() );
		$this->assertStringContainsString( 'Bricks > Settings > API keys', $result->get_error_message() );
		$this->assertSame( 412, $result->get_error_data()['status'] );
		$this->assertEmpty( $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}

	/**
	 * Search rejects callers lacking upload_files capability.
	 *
	 * @return void
	 */
	public function test_search_unsplash_permission_denied_without_upload_files(): void {
		$GLOBALS['_bricks_mcp_test_caps']['upload_files'] = false;

		$result = wp_get_ability( 'bricks-mcp/search-unsplash' )->execute( [ 'query' => 'forest' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Import deduplicates on _unsplash_photo_id without calling bricks/upload-media or HTTP.
	 *
	 * @return void
	 */
	public function test_import_unsplash_dedup_returns_existing_attachment(): void {
		bricks_mcp_test_add_post(
			[
				'ID'             => 42,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Existing Mountain',
				'post_excerpt'   => 'Photo by Jane Doe on Unsplash',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 7,
			],
			[
				'_unsplash_photo_id'       => 'dup_99',
				'_wp_attachment_image_alt' => 'Existing alt',
				'_wp_attachment_metadata'  => [
					'width'  => 1600,
					'height' => 900,
				],
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = [ 42 ];

		$upload_called = false;
		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function () use ( &$upload_called ): array {
					$upload_called = true;
					return [ 'id' => 999 ];
				},
			]
		);

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['duplicate'] );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertSame( 'https://example.test/uploads/42.jpg', $result['url'] );
		$this->assertSame( 'Existing Mountain', $result['title'] );
		$this->assertSame( 'Existing alt', $result['alt'] );
		$this->assertSame( 'Photo by Jane Doe on Unsplash', $result['caption'] );
		$this->assertSame( 7, $result['postId'] );
		$this->assertSame( 1600, $result['width'] );
		$this->assertSame( 900, $result['height'] );
		// Imported before attribution meta was stored: unknown, not a silent empty string.
		$this->assertNull( $result['photographer'] );
		$this->assertNull( $result['photographerUrl'] );
		$this->assertFalse( $upload_called );
		$this->assertEmpty( $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}

	/**
	 * Importing the same photo twice returns the same sizing and attribution on the duplicate call (#49).
	 *
	 * @return void
	 */
	public function test_import_unsplash_twice_returns_same_dimensions_and_attribution(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'        => 15,
				'post_type' => 'page',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'             => 17,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			]
		);

		$image_url = 'https://images.unsplash.com/photo-alpine?w=1080';

		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url ): array {
			if ( 'https://api.unsplash.com/photos/Tr-faG4v38w' === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode(
						[
							'id'    => 'Tr-faG4v38w',
							'urls'  => [ 'regular' => $image_url ],
							'user'  => [
								'name'  => 'Sam Peak',
								'links' => [ 'html' => 'https://unsplash.com/@sampeak' ],
							],
							'links' => [ 'download_location' => 'https://api.unsplash.com/photos/Tr-faG4v38w/download' ],
						]
					),
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'image/jpeg' ],
				'body'     => "\xFF\xD8\xFF\xE0alpine",
			];
		};

		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function ( array $input ): array {
					// WordPress stores the generated image sizes as attachment metadata on upload.
					update_post_meta(
						17,
						'_wp_attachment_metadata',
						[
							'width'  => 1080,
							'height' => 540,
						]
					);
					return [
						'id'       => 17,
						'url'      => 'https://example.test/uploads/17.jpg',
						'title'    => $input['title'],
						'mimeType' => 'image/jpeg',
						'width'    => 1080,
						'height'   => 540,
					];
				},
			]
		);

		$input = [
			'photoId' => 'Tr-faG4v38w',
			'postId'  => 15,
		];
		$first = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( $input );

		$this->assertIsArray( $first );
		$this->assertFalse( $first['duplicate'] );

		$GLOBALS['_bricks_mcp_test_get_posts_return'] = [ 17 ];
		$second = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( $input );

		$this->assertIsArray( $second );
		$this->assertTrue( $second['duplicate'] );
		$this->assertSame( 17, $second['attachmentId'] );
		foreach ( [ 'width', 'height', 'photographer', 'photographerUrl' ] as $field ) {
			$this->assertSame( $first[ $field ], $second[ $field ], $field );
		}
		$this->assertSame( 1080, $second['width'] );
		$this->assertSame( 540, $second['height'] );
		$this->assertSame( 'Sam Peak', $second['photographer'] );
		$this->assertSame( 'https://unsplash.com/@sampeak?utm_source=bricks_mcp&utm_medium=referral', $second['photographerUrl'] );
	}

	/**
	 * Dedup retry attaches an unattached existing attachment to postId, fails on attach error, and preserves existing parents.
	 *
	 * @return void
	 */
	public function test_import_unsplash_dedup_attaches_unattached_attachment_on_retry(): void {
		bricks_mcp_test_add_post(
			[
				'ID'        => 15,
				'post_type' => 'page',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'        => 16,
				'post_type' => 'page',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'             => 42,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Unattached Photo',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			],
			[
				'_unsplash_photo_id' => 'dup_retry',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = [ 42 ];

		$GLOBALS['_bricks_mcp_test_media_wp_update_post_fail'] = true;
		$failed                                                = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'dup_retry',
				'postId'  => 15,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $failed );
		$this->assertSame( 'bricks_mcp_unsplash_attach_failed', $failed->get_error_code() );
		$this->assertSame( 500, $failed->get_error_data()['status'] );
		$this->assertSame( 0, get_post( 42 )->post_parent );

		$GLOBALS['_bricks_mcp_test_media_wp_update_post_fail'] = false;
		$attached                                              = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'dup_retry',
				'postId'  => 15,
			]
		);
		$this->assertIsArray( $attached );
		$this->assertTrue( $attached['duplicate'] );
		$this->assertSame( 42, $attached['attachmentId'] );
		$this->assertSame( 15, $attached['postId'] );
		$this->assertSame( 15, get_post( 42 )->post_parent );

		$preserved = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'dup_retry',
				'postId'  => 16,
			]
		);
		$this->assertIsArray( $preserved );
		$this->assertSame( 15, $preserved['postId'] );
		$this->assertSame( 15, get_post( 42 )->post_parent );
		$this->assertEmpty( $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}

	/**
	 * Dedup never re-parents an existing attachment the user cannot edit, but still returns it.
	 *
	 * @return void
	 */
	public function test_import_unsplash_dedup_skips_attach_without_edit_rights_on_attachment(): void {
		bricks_mcp_test_add_post(
			[
				'ID'        => 15,
				'post_type' => 'page',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'             => 42,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Someone Else Photo',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			],
			[
				'_unsplash_photo_id' => 'dup_foreign',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return']            = [ 42 ];
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][15] = true;
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = false;

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'dup_foreign',
				'postId'  => 15,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['duplicate'] );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertSame( 0, $result['postId'] );
		$this->assertSame( 0, get_post( 42 )->post_parent );
		$this->assertEmpty( $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] );
	}

	/**
	 * Import downloads from images.unsplash.com, sends base64 + unsplash-<id>.jpg to bricks/upload-media, pings download_location, and sets meta/parent.
	 *
	 * @return void
	 */
	public function test_import_unsplash_downloads_and_uploads_base64_with_filename(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'         => 15,
				'post_type'  => 'page',
				'post_title' => 'Landing',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'          => 88,
				'post_type'   => 'attachment',
				'post_parent' => 0,
			]
		);

		$image_url       = 'https://images.unsplash.com/photo-1234567890?auto=format&fit=crop&w=1080&fm=jpg';
		$fake_jpeg_bytes = "\xFF\xD8\xFF\xE0fake-jpeg-payload";

		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url, $fake_jpeg_bytes ): array {
			if ( 'https://api.unsplash.com/photos/photo_42' === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode(
						[
							'id'              => 'photo_42',
							'description'     => 'Minimalist workspace',
							'alt_description' => 'Desk with laptop',
							'urls'            => [
								'regular' => $image_url,
							],
							'links'           => [
								'download_location' => 'https://api.unsplash.com/photos/photo_42/download?ixid=abc',
							],
							'user'            => [
								'name'  => 'Alex Rivera',
								'links' => [ 'html' => 'https://unsplash.com/@alexrivera' ],
							],
						]
					),
				];
			}

			if ( $image_url === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/jpeg; charset=binary' ],
					'body'     => $fake_jpeg_bytes,
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => '{}',
			];
		};

		$captured_upload_input = null;
		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function ( array $input ) use ( &$captured_upload_input ): array {
					$captured_upload_input = $input;
					return [
						'id'       => 88,
						'url'      => 'https://example.com/uploads/unsplash-photo_42.jpg',
						'title'    => $input['title'],
						'alt'      => $input['alt'],
						'mimeType' => 'image/jpeg',
						'width'    => 1080,
						'height'   => 720,
					];
				},
			]
		);

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'photo_42',
				'alt'     => 'Hero workspace',
				'title'   => 'Workspace Hero',
				'postId'  => 15,
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['duplicate'] );
		$this->assertSame( 88, $result['attachmentId'] );
		$this->assertSame( 'Photo by Alex Rivera on Unsplash', $result['caption'] );
		$this->assertSame( 15, $result['postId'] );
		$this->assertSame( 'photo_42', get_post_meta( 88, '_unsplash_photo_id', true ) );
		$this->assertSame( 15, get_post( 88 )->post_parent );

		$this->assertSame(
			[
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Asserting expected base64 upload payload.
				'base64'   => base64_encode( $fake_jpeg_bytes ),
				'filename' => 'unsplash-photo_42.jpg',
				'title'    => 'Workspace Hero',
				'caption'  => 'Photo by Alex Rivera on Unsplash',
				'alt'      => 'Hero workspace',
			],
			$captured_upload_input
		);

		$calls = $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'];
		$this->assertCount( 3, $calls );
		$this->assertSame( 'https://api.unsplash.com/photos/photo_42', $calls[0]['url'] );
		$this->assertSame( $image_url, $calls[1]['url'] );
		$this->assertSame( 20, $calls[1]['args']['timeout'] );
		$this->assertSame( 10485761, $calls[1]['args']['limit_response_size'] );
		$this->assertSame( 'https://api.unsplash.com/photos/photo_42/download?ixid=abc', $calls[2]['url'] );
		$this->assertFalse( $calls[2]['args']['blocking'] );
	}

	/**
	 * Import rejects non-Unsplash image hosts, non-image content types, non-200 downloads, and oversized images.
	 *
	 * @return void
	 */
	public function test_import_unsplash_rejects_untrusted_host_bad_mime_and_oversized_body(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];

		$upload_called = false;
		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function () use ( &$upload_called ): array {
					$upload_called = true;
					return [ 'id' => 999 ];
				},
			]
		);

		$cases = [
			'non-unsplash host'       => [
				'image_url'       => 'https://evil.example.com/photo.jpg',
				'download_result' => [],
				'expected_code'   => 'bricks_mcp_unsplash_invalid_image_url',
				'expected_status' => 502,
				'expected_calls'  => 1,
			],
			'http instead of https'   => [
				'image_url'       => 'http://images.unsplash.com/photo-1',
				'download_result' => [],
				'expected_code'   => 'bricks_mcp_unsplash_invalid_image_url',
				'expected_status' => 502,
				'expected_calls'  => 1,
			],
			'non-image content type'  => [
				'image_url'       => 'https://images.unsplash.com/photo-1?w=1080',
				'download_result' => [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'text/html; charset=utf-8' ],
					'body'     => '<html>not an image</html>',
				],
				'expected_code'   => 'bricks_mcp_unsplash_invalid_content_type',
				'expected_status' => 502,
				'expected_calls'  => 2,
			],
			'non-200 image download'  => [
				'image_url'       => 'https://images.unsplash.com/photo-1?w=1080',
				'download_result' => [
					'response' => [ 'code' => 503 ],
					'headers'  => [ 'content-type' => 'image/jpeg' ],
					'body'     => 'unavailable',
				],
				'expected_code'   => 'bricks_mcp_unsplash_download_error',
				'expected_status' => 502,
				'expected_calls'  => 2,
			],
			'body exceeds upload cap' => [
				'image_url'       => 'https://images.unsplash.com/photo-1?w=1080',
				'download_result' => [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => str_repeat( 'A', 65 ),
				],
				'expected_code'   => 'bricks_mcp_unsplash_image_too_large',
				'expected_status' => 413,
				'expected_calls'  => 2,
				'max_upload_size' => 64,
			],
		];

		foreach ( $cases as $label => $case ) {
			$GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'] = [];
			$GLOBALS['_bricks_mcp_test_max_upload_size']          = $case['max_upload_size'] ?? 10485760;

			$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $case ): array {
				if ( str_starts_with( $url, 'https://api.unsplash.com/photos/' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'headers'  => [ 'content-type' => 'application/json' ],
						'body'     => (string) wp_json_encode(
							[
								'id'   => 'photo_bad',
								'urls' => [ 'regular' => $case['image_url'] ],
								'user' => [ 'name' => 'Someone' ],
							]
						),
					];
				}

				return $case['download_result'];
			};

			$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'photo_bad' ] );

			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( $case['expected_code'], $result->get_error_code(), $label );
			$this->assertSame( $case['expected_status'], $result->get_error_data()['status'], $label );
			$this->assertCount( $case['expected_calls'], $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'], $label );
			$this->assertFalse( $upload_called, $label );
		}
	}

	/**
	 * Import returns an error instead of success when _unsplash_photo_id meta or post_parent update fails.
	 *
	 * @return void
	 */
	public function test_import_unsplash_fails_when_meta_or_post_parent_write_fails(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'        => 15,
				'post_type' => 'page',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'          => 88,
				'post_type'   => 'attachment',
				'post_parent' => 0,
			]
		);

		$image_url                                      = 'https://images.unsplash.com/photo-77?w=1080';
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url ): array {
			if ( $image_url === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/webp' ],
					'body'     => 'RIFFwebpdata',
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'   => 'photo_77',
						'urls' => [ 'regular' => $image_url ],
						'user' => [ 'name' => 'Pat' ],
					]
				),
			];
		};

		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static fn(): array => [
					'id'  => 88,
					'url' => 'https://example.com/uploads/unsplash-photo_77.webp',
				],
			]
		);

		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = true;
		$meta_error                                              = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'photo_77',
				'postId'  => 15,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $meta_error );
		$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $meta_error->get_error_code() );
		$this->assertSame( 500, $meta_error->get_error_data()['status'] );

		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
		$GLOBALS['_bricks_mcp_test_media_wp_update_post_fail']   = true;
		$attach_error                                            = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'photo_77',
				'postId'  => 15,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $attach_error );
		$this->assertSame( 'bricks_mcp_unsplash_attach_failed', $attach_error->get_error_code() );
		$this->assertSame( 500, $attach_error->get_error_data()['status'] );
		$this->assertSame( 'photo_77', get_post_meta( 88, '_unsplash_photo_id', true ) );
	}

	/**
	 * Import refuses to send the Unsplash API key to a non-Unsplash download_location host.
	 *
	 * @return void
	 */
	public function test_import_unsplash_skips_download_ping_for_untrusted_host(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];

		$image_url                                      = 'https://images.unsplash.com/photo-99?w=1080';
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url ): array {
			if ( $image_url === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => 'fake-png-bytes',
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'    => 'photo_99',
						'urls'  => [ 'regular' => $image_url ],
						'links' => [ 'download_location' => 'https://evil.example.com/steal-key' ],
						'user'  => [ 'name' => 'Someone' ],
					]
				),
			];
		};

		$captured_filename = null;
		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function ( array $input ) use ( &$captured_filename ): array {
					$captured_filename = $input['filename'] ?? null;
					return [
						'id'  => 91,
						'url' => 'https://example.com/uploads/unsplash-photo_99.png',
					];
				},
			]
		);

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'photo_99' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'unsplash-photo_99.png', $captured_filename );
		$calls = $GLOBALS['_bricks_mcp_test_wp_safe_remote_get_calls'];
		$this->assertCount( 2, $calls );
		$this->assertSame( 'https://api.unsplash.com/photos/photo_99', $calls[0]['url'] );
		$this->assertSame( $image_url, $calls[1]['url'] );
	}

	/**
	 * Import enforces post edit and builder permissions when postId is supplied.
	 *
	 * @return void
	 */
	public function test_import_unsplash_permission_denies_when_cannot_edit_post(): void {
		bricks_mcp_test_add_post(
			[
				'ID'        => 21,
				'post_type' => 'page',
			]
		);
		$GLOBALS['_bricks_mcp_test_caps']['upload_files'] = true;
		$GLOBALS['_bricks_mcp_test_caps']['edit_post']    = false;

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => 'photo_42',
				'postId'  => 21,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * List Adobe Fonts returns cached CSS family names, hides the project ID, and emits valid JSON Schema input_schema.
	 *
	 * @return void
	 */
	public function test_list_adobe_fonts_returns_families_and_hides_project_id(): void {
		$project_id = 'tk_secret_kit_777';
		update_option(
			'bricks_global_settings',
			[
				'adobeFontsProjectId'   => $project_id,
				'disableGoogleFonts'    => true,
				'webfontLoading'        => 'webfontloader',
				'customFontsPreload'    => true,
				'googleFontsFilesystem' => false,
			]
		);
		update_option(
			'bricks_adobe_fonts',
			[
				[
					'name'       => 'Azo Sans',
					'slug'       => 'azo-sans',
					'css_names'  => [ 'azo-sans-web', 'azo-sans-fallback' ],
					'variations' => [ 'n4', 'n7' ],
				],
				[
					'name' => 'Fallback Serif',
					'slug' => 'fallback-serif',
				],
			]
		);

		$ability = wp_get_ability( 'bricks-mcp/list-adobe-fonts' );
		$schema  = $ability->get_args()['input_schema'] ?? [];
		$this->assertSame( '{"type":"object","additionalProperties":false}', wp_json_encode( $schema ) );

		$result = $ability->execute( [] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['adobeFontsConfigured'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 'azo-sans-web, azo-sans-fallback', $result['families'][0]['cssFamily'] );
		$this->assertSame( 'fallback-serif', $result['families'][1]['cssFamily'] );
		$this->assertSame(
			[
				'disableGoogleFonts'    => true,
				'webfontLoading'        => 'webfontloader',
				'customFontsPreload'    => true,
				'googleFontsFilesystem' => false,
			],
			$result['fontSettings']
		);
		$this->assertStringNotContainsString( $project_id, (string) wp_json_encode( $result ) );
	}

	/**
	 * Update font settings merges partially, unsets disabled booleans for Bricks isset(), and rejects invalid values.
	 *
	 * @return void
	 */
	public function test_update_font_settings_partial_merge_and_validation(): void {
		update_option(
			'bricks_global_settings',
			[
				'cssLoading'         => 'file',
				'apiKeyUnsplash'     => 'keep-this-key',
				'disableGoogleFonts' => true,
			]
		);

		$result = wp_get_ability( 'bricks-mcp/update-font-settings' )->execute(
			[
				'disableGoogleFonts' => false,
				'webfontLoading'     => 'webfontloader',
				'customFontsPreload' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'disableGoogleFonts', 'customFontsPreload', 'webfontLoading' ], $result['updated'] );
		$this->assertSame( [], $result['unchanged'] );

		$stored = get_option( 'bricks_global_settings', [] );
		$this->assertSame( 'file', $stored['cssLoading'] );
		$this->assertSame( 'keep-this-key', $stored['apiKeyUnsplash'] );
		$this->assertArrayNotHasKey( 'disableGoogleFonts', $stored );
		$this->assertTrue( $stored['customFontsPreload'] );
		$this->assertSame( 'webfontloader', $stored['webfontLoading'] );

		$invalid = wp_get_ability( 'bricks-mcp/update-font-settings' )->execute(
			[ 'webfontLoading' => 'swap' ]
		);
		$this->assertInstanceOf( \WP_Error::class, $invalid );
		$this->assertSame( 'bricks_mcp_invalid_webfont_loading', $invalid->get_error_code() );
		$this->assertSame( 400, $invalid->get_error_data()['status'] );
	}

	/**
	 * Update font settings returns HTTP 500 WP_Error and skips Bricks memory sync when option persistence fails.
	 *
	 * @return void
	 */
	public function test_update_font_settings_returns_error_when_persistence_fails(): void {
		$initial = [ 'cssLoading' => 'file' ];
		update_option( 'bricks_global_settings', $initial );
		\Bricks\Database::$global_settings = $initial;

		$GLOBALS['_bricks_mcp_test_media_update_option_fail'] = true;

		$result = wp_get_ability( 'bricks-mcp/update-font-settings' )->execute(
			[
				'disableGoogleFonts' => true,
				'webfontLoading'     => 'webfontloader',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_font_settings_persist_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( $initial, \Bricks\Database::$global_settings );
	}

	/**
	 * Both font abilities deny callers lacking manage_options.
	 *
	 * @return void
	 */
	public function test_font_abilities_deny_without_manage_options(): void {
		$GLOBALS['_bricks_mcp_test_caps']['manage_options'] = false;

		$list_result   = wp_get_ability( 'bricks-mcp/list-adobe-fonts' )->execute( [] );
		$update_result = wp_get_ability( 'bricks-mcp/update-font-settings' )->execute( [ 'customFontsPreload' => true ] );

		$this->assertInstanceOf( \WP_Error::class, $list_result );
		$this->assertSame( 403, $list_result->get_error_data()['status'] );

		$this->assertInstanceOf( \WP_Error::class, $update_result );
		$this->assertSame( 403, $update_result->get_error_data()['status'] );
		$this->assertSame( [], get_option( 'bricks_global_settings', [] ) );
	}
}
