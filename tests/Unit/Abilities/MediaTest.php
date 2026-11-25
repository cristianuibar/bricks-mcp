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
use PHPUnit\Framework\Attributes\DataProvider;
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
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
		$GLOBALS['_bricks_mcp_test_media_before_delete_attachment']  = null;
		$GLOBALS['_bricks_mcp_test_media_on_update_post_meta_fail']  = null;
		$GLOBALS['_bricks_mcp_test_media_delete_post_meta_fail']     = false;
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
	 * A duplicate import saved without attribution, as before it was stored, gets its credit from Unsplash when the
	 * caller can edit it, and is returned as it is when the caller cannot edit it or the Unsplash lookup fails for any
	 * reason, so an upgraded 1.x site keeps deduplicating while Unsplash rate-limits.
	 *
	 * @return void
	 */
	public function test_import_unsplash_dedup_completes_missing_attribution(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 7,
			],
			[
				'_unsplash_photo_id' => 'dup_99',
				// Releases before stored attribution kept the Unsplash image file name.
				'_wp_attached_file'  => '2024/05/photo-1506744038136-46273834b3fb.jpg',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = [ 42 ];
		wp_register_ability(
			'bricks/upload-media',
			[ 'execute_callback' => static fn(): array => [ 'id' => 999 ] ]
		);
		$import = static fn(): array|\WP_Error => wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$unsplash_code                                  = 429;
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function () use ( &$unsplash_code ): array {
			return [
				'response' => [ 'code' => $unsplash_code ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => '{}',
			];
		};
		foreach ( [ 429, 503, 404 ] as $code ) {
			$unsplash_code = $code;
			$unavailable   = $import();
			$this->assertIsArray( $unavailable, (string) $code );
			$this->assertSame( 42, $unavailable['attachmentId'], (string) $code );
			$this->assertTrue( $unavailable['duplicate'], (string) $code );
			$this->assertNull( $unavailable['photographer'], (string) $code );
			$this->assertNull( $unavailable['photographerUrl'], (string) $code );
		}

		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) wp_json_encode(
				[
					'id'   => 'dup_99',
					'user' => [
						'name'  => 'Jane Doe',
						'links' => [ 'html' => 'https://unsplash.com/@jane' ],
					],
				]
			),
		];
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = false;
		$read_only = $import();
		$this->assertSame( 42, $read_only['attachmentId'] );
		$this->assertNull( $read_only['photographerUrl'] );
		$this->assertSame( '', get_post_meta( 42, '_unsplash_photographer_url', true ) );

		unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] );

		// An editor still gets the attachment as it is when its credit does not persist.
		foreach ( [ [ '_unsplash_photographer' ], [ '_unsplash_photographer_url' ] ] as $failing_keys ) {
			$label = implode( ',', $failing_keys );
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = $failing_keys;
			$unsaved = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$this->assertIsArray( $unsaved, $label );
			$this->assertSame( 42, $unsaved['attachmentId'], $label );
			$this->assertTrue( $unsaved['duplicate'], $label );
			$this->assertSame( 7, $unsaved['postId'], $label );
			$this->assertNull( $unsaved['photographerUrl'], $label );
			$this->assertSame( '', get_post_meta( 42, '_unsplash_photographer_url', true ), $label );
		}

		$completed = $import();
		$this->assertIsArray( $completed );
		$this->assertSame( 42, $completed['attachmentId'] );
		$this->assertTrue( $completed['duplicate'] );
		$this->assertSame( 'Jane Doe', $completed['photographer'] );
		$this->assertSame( 'https://unsplash.com/@jane?utm_source=bricks_mcp&utm_medium=referral', $completed['photographerUrl'] );
		$this->assertSame( 'https://unsplash.com/@jane?utm_source=bricks_mcp&utm_medium=referral', get_post_meta( 42, '_unsplash_photographer_url', true ) );
	}

	/**
	 * A malformed Unsplash response during a repair neither erases the saved photographer name nor marks the credit
	 * complete (#269), and a legacy attachment is still returned as it is to a caller who can still see it.
	 *
	 * @return void
	 */
	public function test_import_unsplash_repair_keeps_saved_credit_on_malformed_response(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 7,
			],
			[
				'_unsplash_photo_id'            => 'dup_99',
				'_unsplash_attribution_pending' => '1',
				'_unsplash_photographer'        => 'Pat',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'          => 43,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 7,
			],
			[
				'_unsplash_photo_id' => 'legacy_1',
				'_wp_attached_file'  => '2024/05/photo-1506744038136-46273834b3fb.jpg',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = static fn( array $args ): array => 'legacy_1' === $args['meta_value'] ? [ 43 ] : [ 42 ];
		wp_register_ability(
			'bricks/upload-media',
			[ 'execute_callback' => static fn(): array => [ 'id' => 999 ] ]
		);
		$import = static fn( string $photo_id = 'dup_99' ): array|\WP_Error => wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => $photo_id ] );

		$body                                           = '{}';
		$on_api_fetch                                   = null;
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function () use ( &$body, &$on_api_fetch ): array {
			if ( null !== $on_api_fetch ) {
				$on_api_fetch();
			}

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => $body,
			];
		};

		$malformed = $import();
		$this->assertInstanceOf( \WP_Error::class, $malformed );
		$this->assertSame( 'bricks_mcp_unsplash_parse_error', $malformed->get_error_code() );
		$this->assertSame( 'Pat', get_post_meta( 42, '_unsplash_photographer', true ) );
		$this->assertSame( '', get_post_meta( 42, '_unsplash_photographer_url', true ) );
		$this->assertSame( '1', get_post_meta( 42, '_unsplash_attribution_pending', true ) );

		// A caller who can read but not edit the unfinished import gets the same error, not it without its credit (#371).
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = false;
		$read_only = $import();
		unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] );
		$this->assertInstanceOf( \WP_Error::class, $read_only );
		$this->assertSame( 'bricks_mcp_unsplash_parse_error', $read_only->get_error_code() );
		$this->assertSame( [ 'status' => 502 ], $read_only->get_error_data() );
		$this->assertSame( '1', get_post_meta( 42, '_unsplash_attribution_pending', true ) );

		$legacy = $import( 'legacy_1' );
		$this->assertIsArray( $legacy );
		$this->assertSame( 43, $legacy['attachmentId'] );
		$this->assertTrue( $legacy['duplicate'] );
		$this->assertNull( $legacy['photographerUrl'] );
		$this->assertSame( '', get_post_meta( 43, '_unsplash_photographer_url', true ) );

		// A caller who loses access to the legacy attachment while waiting on Unsplash does not get its details.
		$on_api_fetch = static function (): void {
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][43] = false;
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][43] = false;
		};
		$revoked      = $import( 'legacy_1' );
		unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][43], $GLOBALS['_bricks_mcp_test_object_caps']['read_post'][43] );
		$on_api_fetch = null;
		$this->assertInstanceOf( \WP_Error::class, $revoked );
		$this->assertSame( 'bricks_mcp_unsplash_import_incomplete', $revoked->get_error_code() );

		// A photo whose user has no name keeps the name saved by the earlier attempt.
		$body      = (string) wp_json_encode(
			[
				'id'   => 'dup_99',
				'user' => [ 'links' => [ 'html' => 'https://unsplash.com/@pat' ] ],
			]
		);
		$completed = $import();
		$this->assertIsArray( $completed );
		$this->assertSame( 42, $completed['attachmentId'] );
		$this->assertSame( 'Pat', $completed['photographer'] );
		$this->assertSame( 'Pat', get_post_meta( 42, '_unsplash_photographer', true ) );
		$this->assertSame( 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral', get_post_meta( 42, '_unsplash_photographer_url', true ) );
	}

	/**
	 * A repair whose Unsplash response has no photographer name keeps the name a concurrent repair saved while it waited
	 * on Unsplash, rather than the one this request read into its meta cache before (#369).
	 *
	 * @return void
	 */
	public function test_import_unsplash_nameless_repair_keeps_name_saved_meanwhile(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			],
			[
				'_unsplash_photo_id'            => 'dup_99',
				'_unsplash_attribution_pending' => '1',
				'_unsplash_photographer'        => 'Pat',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return']  = [ 42 ];
		$GLOBALS['_bricks_mcp_test_post_meta_cache']   = [];
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function (): array {
			// The concurrent repair writes the database; this request's meta cache still holds the attachment as it read it.
			$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer']     = 'Pat Lee';
			$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer_url'] = 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral';
			unset( $GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_attribution_pending'] );

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'   => 'dup_99',
						'user' => [ 'links' => [ 'html' => 'https://unsplash.com/@pat' ] ],
					]
				),
			];
		};

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertSame( 'Pat Lee', $result['photographer'] );
		$this->assertSame( 'Pat Lee', $GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer'] );
	}

	/**
	 * A repair whose Unsplash response has no photographer name does not write back the name it read when another retry
	 * corrects that name before this repair saves its credit (#370).
	 *
	 * @return void
	 */
	public function test_import_unsplash_nameless_repair_keeps_name_corrected_after_its_read(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			],
			[
				'_unsplash_photo_id'            => 'dup_99',
				'_unsplash_attribution_pending' => '1',
				'_unsplash_photographer'        => 'Pat',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return']   = [ 42 ];
		$requested                                      = false;
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function () use ( &$requested ): array {
			$requested = true;

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'   => 'dup_99',
						'user' => [ 'links' => [ 'html' => 'https://unsplash.com/@pat' ] ],
					]
				),
			];
		};
		// The other retry saves its credit after this repair has read the stored name, while it checks edit access.
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = static function () use ( &$requested ): bool {
			if ( $requested ) {
				$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer']     = 'Pat Lee';
				$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer_url'] = 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral';
			}

			return true;
		};

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertSame( 'Pat Lee', $result['photographer'] );
		$this->assertSame( 'Pat Lee', $GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer'] );
		$this->assertArrayNotHasKey( '_unsplash_attribution_pending', $GLOBALS['_bricks_mcp_test_post_meta'][42] );
	}

	/**
	 * A nameless repair completes when another repair saves the identical profile URL after this one read the attachment
	 * into its meta cache, rather than report a false failure from that stale cache (#389).
	 *
	 * @return void
	 */
	public function test_import_unsplash_nameless_repair_completes_when_identical_url_saved_meanwhile(): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			],
			[
				'_unsplash_photo_id'            => 'dup_99',
				'_unsplash_attribution_pending' => '1',
				'_unsplash_photographer'        => 'Pat',
			]
		);
		$GLOBALS['_bricks_mcp_test_get_posts_return']   = [ 42 ];
		$GLOBALS['_bricks_mcp_test_post_meta_cache']    = [];
		$requested                                      = false;
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function () use ( &$requested ): array {
			$requested = true;

			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'   => 'dup_99',
						'user' => [ 'links' => [ 'html' => 'https://unsplash.com/@pat' ] ],
					]
				),
			];
		};
		// The other repair writes the database after this one has read the stored name, while it checks edit access.
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = static function () use ( &$requested ): bool {
			if ( $requested ) {
				$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer_url'] = 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral';
				unset( $GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_attribution_pending'] );
			}

			return true;
		};

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertSame( 'Pat', $result['photographer'] );
		$this->assertSame( 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral', $result['photographerUrl'] );
		$this->assertArrayNotHasKey( '_unsplash_attribution_pending', $GLOBALS['_bricks_mcp_test_post_meta'][42] );
	}

	/**
	 * Unsplash failures a waiting repair can receive after another editor saved the credit.
	 *
	 * @return array<string, array{0: int, 1: string, 2: bool}>
	 */
	public static function failures_after_concurrent_repair(): array {
		return [
			'malformed response, editor'       => [ 200, '{}', true ],
			'malformed response, read-only'    => [ 200, '{}', false ],
			'Unsplash server error, editor'    => [ 503, 'unavailable', true ],
			'Unsplash server error, read-only' => [ 503, 'unavailable', false ],
		];
	}

	/**
	 * A repair whose Unsplash request fails after another editor saved the credit while it waited returns the completed
	 * attachment with that credit, not a stale error for an import that is no longer unfinished (#372).
	 *
	 * @param int    $code     Unsplash HTTP status.
	 * @param string $body     Unsplash response body.
	 * @param bool   $can_edit Whether the waiting caller can edit the attachment.
	 * @return void
	 */
	#[DataProvider( 'failures_after_concurrent_repair' )]
	public function test_import_unsplash_failed_repair_returns_credit_saved_meanwhile( int $code, string $body, bool $can_edit ): void {
		\Bricks\Database::$global_settings = [ 'apiKeyUnsplash' => 'secret-unsplash-key' ];
		bricks_mcp_test_add_post(
			[
				'ID'          => 42,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			],
			[
				'_unsplash_photo_id'            => 'dup_99',
				'_unsplash_attribution_pending' => '1',
				'_unsplash_photographer'        => 'Pat',
			]
		);
		if ( ! $can_edit ) {
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][42] = false;
		}
		$GLOBALS['_bricks_mcp_test_get_posts_return']   = [ 42 ];
		$GLOBALS['_bricks_mcp_test_post_meta_cache']    = [];
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function () use ( $code, $body ): array {
			// The other editor's repair writes the database; this request's meta cache still holds the unfinished import.
			$GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_photographer_url'] = 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral';
			unset( $GLOBALS['_bricks_mcp_test_post_meta'][42]['_unsplash_attribution_pending'] );

			return [
				'response' => [ 'code' => $code ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => $body,
			];
		};

		$result = wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute( [ 'photoId' => 'dup_99' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['attachmentId'] );
		$this->assertTrue( $result['duplicate'] );
		$this->assertSame( 'Pat', $result['photographer'] );
		$this->assertSame( 'https://unsplash.com/@pat?utm_source=bricks_mcp&utm_medium=referral', $result['photographerUrl'] );
	}

	/**
	 * Unsplash user payloads with the photographer name both imports should report.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function unsplash_users(): array {
		return [
			'named, with a backslash' => [
				[
					'name'  => 'North\\West Studio',
					'links' => [ 'html' => 'https://unsplash.com/@sampeak' ],
				],
				'North\\West Studio',
			],
			'profile URL but no name' => [ [ 'links' => [ 'html' => 'https://unsplash.com/@sampeak' ] ], null ],
		];
	}

	/**
	 * Importing the same photo twice returns the same sizing and attribution on the duplicate call (#49), backslashes included (#172),
	 * and a missing photographer name is reported as unknown (null), never "" (#174).
	 *
	 * @param array<string, mixed> $user                  Unsplash `user` payload.
	 * @param string|null          $expected_photographer Photographer both imports should report.
	 * @return void
	 */
	#[DataProvider( 'unsplash_users' )]
	public function test_import_unsplash_twice_returns_same_dimensions_and_attribution( array $user, ?string $expected_photographer ): void {
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

		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url, $user ): array {
			if ( 'https://api.unsplash.com/photos/Tr-faG4v38w' === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode(
						[
							'id'    => 'Tr-faG4v38w',
							'urls'  => [ 'regular' => $image_url ],
							'user'  => $user,
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
		$this->assertSame( $expected_photographer, $second['photographer'] );
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
	 * Import returns an error instead of success when Unsplash photo ID or attribution meta, or the post_parent update, fails.
	 * A failed meta write removes the uploaded attachment, so a retry does not leave a duplicate behind.
	 * When WordPress refuses that delete, a retry completes the kept attachment instead of uploading again.
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

		$image_url                                      = 'https://images.unsplash.com/photo-77?w=1080';
		$api_code                                       = 200;
		$on_api_fetch                                   = null;
		$photographer                                   = 'Pat';
		$GLOBALS['_bricks_mcp_test_media_http_handler'] = static function ( string $url ) use ( $image_url, &$api_code, &$on_api_fetch, &$photographer ): array {
			if ( $image_url === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/webp' ],
					'body'     => 'RIFFwebpdata',
				];
			}

			// Runs once, while this call waits on Unsplash, as a concurrent request would.
			if ( null !== $on_api_fetch ) {
				$concurrent   = $on_api_fetch;
				$on_api_fetch = null;
				$concurrent();
			}

			return [
				'response' => [ 'code' => $api_code ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => (string) wp_json_encode(
					[
						'id'              => 'photo_77',
						'alt_description' => 'Red barn',
						'urls'            => [ 'regular' => $image_url ],
						'user'            => [ 'name' => $photographer ],
					]
				),
			];
		};

		// Each upload creates a new attachment, like the real upload-media ability.
		$next_id = 88;
		wp_register_ability(
			'bricks/upload-media',
			[
				'execute_callback' => static function ( array $input ) use ( &$next_id ): array {
					$id = $next_id++;
					bricks_mcp_test_add_post(
						[
							'ID'          => $id,
							'post_type'   => 'attachment',
							'post_status' => 'inherit',
							'post_parent' => 0,
						],
						// WordPress stores the sanitized file path with the attachment, adding -N when the name is taken.
						[
							'_wp_attached_file'        => '2026/11/' . preg_replace( '/-+/', '-', str_replace( '.', '-' . $id . '.', $input['filename'] ) ),
							'_wp_attachment_metadata'  => [
								'width'  => 1080,
								'height' => 720,
							],
							'_wp_attachment_image_alt' => $input['alt'] ?? '',
						]
					);

					return [
						'id'  => $id,
						'url' => 'https://example.com/uploads/unsplash-photo_77-' . $id . '.webp',
					];
				},
			]
		);
		$attachment_ids = static fn(): array => array_keys(
			array_filter(
				$GLOBALS['_bricks_mcp_test_posts'],
				static fn( $post ): bool => 'attachment' === $post->post_type
			)
		);
		// The attachment lookup matches the post status and stored meta like a WordPress query on MySQL: meta_key/meta_value
		// or meta_query clauses using = and REGEXP (both case-insensitive unless the type is BINARY) and NOT EXISTS. It
		// returns the newest matches first, up to posts_per_page.
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = static function ( array $args ) use ( $attachment_ids ): array {
			$matched = array_filter(
				$attachment_ids(),
				static function ( int $id ) use ( $args ): bool {
					if ( ! in_array( $GLOBALS['_bricks_mcp_test_posts'][ $id ]->post_status, (array) ( $args['post_status'] ?? 'inherit' ), true ) ) {
						return false;
					}
					$meta    = $GLOBALS['_bricks_mcp_test_post_meta'][ $id ] ?? [];
					$clauses = $args['meta_query'] ?? [
						[
							'key'   => $args['meta_key'],
							'value' => $args['meta_value'],
							'type'  => $args['meta_type'] ?? 'CHAR',
						],
					];
					foreach ( $clauses as $clause ) {
						$binary  = 'BINARY' === ( $clause['type'] ?? 'CHAR' );
						$stored  = $meta[ $clause['key'] ] ?? null;
						$matches = match ( $clause['compare'] ?? '=' ) {
							'NOT EXISTS' => ! array_key_exists( $clause['key'], $meta ),
							'REGEXP'     => null !== $stored && 1 === preg_match( '#' . $clause['value'] . '#' . ( $binary ? '' : 'i' ), (string) $stored ),
							default      => null !== $stored && ( $binary ? (string) $stored === $clause['value'] : 0 === strcasecmp( (string) $stored, $clause['value'] ) ),
						};
						if ( ! $matches ) {
							return false;
						}
					}

					return true;
				}
			);
			rsort( $matched );
			$limit = (int) ( $args['posts_per_page'] ?? -1 );

			return $limit > 0 ? array_slice( $matched, 0, $limit ) : $matched;
		};
		$import         = static fn( string $photo_id = 'photo_77' ): array|\WP_Error => wp_get_ability( 'bricks-mcp/import-unsplash-photo' )->execute(
			[
				'photoId' => $photo_id,
				'postId'  => 15,
			]
		);

		$clear_attachments = static function () use ( $attachment_ids ): void {
			$GLOBALS['_bricks_mcp_test_posts'] = array_diff_key( $GLOBALS['_bricks_mcp_test_posts'], array_flip( $attachment_ids() ) );
		};

		// A failed photo ID write is an error and removes the upload, which a retry could not find by it.
		foreach ( [ [ '_unsplash_photo_id' ], true ] as $failing_keys ) {
			$label = (string) wp_json_encode( $failing_keys );
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = $failing_keys;
			$meta_error = $import();
			$this->assertInstanceOf( \WP_Error::class, $meta_error, $label );
			$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $meta_error->get_error_code(), $label );
			$this->assertSame( [], $attachment_ids(), $label );
			$this->assertSame( [ 'status' => 500 ], $meta_error->get_error_data(), $label );
		}

		// The retry after the failures leaves exactly one attachment, carrying the photo ID.
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
		$retried = $import();
		$this->assertIsArray( $retried );
		$this->assertSame( [ $retried['attachmentId'] ], $attachment_ids() );
		$this->assertSame( 'photo_77', get_post_meta( $retried['attachmentId'], '_unsplash_photo_id', true ) );

		// When WordPress refuses that delete, also after stripping the meta, the error names the upload it left behind.
		foreach ( [ true, 'after_meta' ] as $delete_fail ) {
			$label = (string) wp_json_encode( $delete_fail );
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photo_id' ];
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = $delete_fail;
			$refused = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
			$this->assertInstanceOf( \WP_Error::class, $refused, $label );
			$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $refused->get_error_code(), $label );
			$left = $attachment_ids();
			$this->assertCount( 1, $left, $label );
			$this->assertSame(
				[
					'status'       => 500,
					'attachmentId' => $left[0],
				],
				$refused->get_error_data(),
				$label
			);
		}

		// A failed photographer URL write keeps the upload, which an editor may already have picked from the media
		// library, intact, even where a cleanup delete would strip its meta and then fail. Another author, who cannot edit
		// it, gets it with its credit instead of a refusal or a second upload, and an editor's retry completes it.
		foreach ( [ false, 'after_meta' ] as $delete_fail ) {
			$label = (string) wp_json_encode( $delete_fail );
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photographer_url' ];
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = $delete_fail;
			$GLOBALS['_bricks_mcp_test_media_on_update_post_meta_fail']  = static function ( int $upload_id ): void {
				update_post_meta( 15, '_thumbnail_id', $upload_id );
			};
			$kept_error = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
			$this->assertInstanceOf( \WP_Error::class, $kept_error, $label );
			$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $kept_error->get_error_code(), $label );
			$kept_id = $kept_error->get_error_data()['attachmentId'];
			$this->assertSame( [ $kept_id ], $attachment_ids(), $label );
			$this->assertSame( $kept_id, (int) get_post_meta( 15, '_thumbnail_id', true ), $label );

			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] = false;
			$other_author = $import();
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] );
			$this->assertIsArray( $other_author, $label );
			$this->assertSame( $kept_id, $other_author['attachmentId'], $label );
			$this->assertTrue( $other_author['duplicate'], $label );
			$this->assertSame( 0, $other_author['postId'], $label );
			$this->assertSame( 'Pat', $other_author['photographer'], $label );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $other_author['photographerUrl'], $label );
			$this->assertSame( '', get_post_meta( $kept_id, '_unsplash_photographer_url', true ), $label );
			$this->assertSame( [ $kept_id ], $attachment_ids(), $label );

			// An author who cannot read it either, as when an editor attached it to a private post, is refused without
			// its caption, parent or ID, and gets no second upload, also when the editor attaches it during the lookup.
			// Refused from the start, it gets the refusal without a request to Unsplash, even while Unsplash is rate-limited.
			foreach ( [ false, true ] as $during_lookup ) {
				$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] = false;
				$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $kept_id ] = $during_lookup;
				$api_code         = $during_lookup ? 200 : 429;
				$reached_unsplash = false;
				$on_api_fetch     = static function () use ( $during_lookup, $kept_id, &$reached_unsplash ): void {
					$reached_unsplash = true;
					if ( $during_lookup ) {
						$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $kept_id ] = false;
					}
				};
				$unreadable       = $import();
				$api_code         = 200;
				$on_api_fetch     = null;
				unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ], $GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $kept_id ] );
				$this->assertInstanceOf( \WP_Error::class, $unreadable, $label . $during_lookup );
				$this->assertSame( 'bricks_mcp_unsplash_import_incomplete', $unreadable->get_error_code(), $label . $during_lookup );
				$this->assertSame( [ 'status' => 403 ], $unreadable->get_error_data(), $label . $during_lookup );
				$this->assertSame( $during_lookup, $reached_unsplash, $label . $during_lookup );
				$this->assertSame( [ $kept_id ], $attachment_ids(), $label . $during_lookup );
			}

			// The same holds when an editor attaches it to the import's target post and makes that post private during
			// the lookup, although the permission check loaded the target post into the request's cache before.
			$GLOBALS['_bricks_mcp_test_post_cache']                            = [];
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] = false;
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $kept_id ] = static function () use ( $kept_id ): bool {
				$parent_id = get_post( $kept_id )->post_parent;
				return 0 === $parent_id || 'private' !== get_post( $parent_id )->post_status;
			};
			$target_status = get_post( 15 )->post_status;
			$on_api_fetch  = static function () use ( $kept_id ): void {
				$GLOBALS['_bricks_mcp_test_posts'][ $kept_id ]->post_parent = 15;
				$GLOBALS['_bricks_mcp_test_posts'][15]->post_status         = 'private';
			};
			$privatized = $import();
			$GLOBALS['_bricks_mcp_test_post_cache']                    = null;
			$GLOBALS['_bricks_mcp_test_posts'][ $kept_id ]->post_parent = 0;
			$GLOBALS['_bricks_mcp_test_posts'][15]->post_status         = $target_status;
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ], $GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $kept_id ] );
			$this->assertInstanceOf( \WP_Error::class, $privatized, $label );
			$this->assertSame( [ 'status' => 403 ], $privatized->get_error_data(), $label );
			$this->assertSame( [ $kept_id ], $attachment_ids(), $label );

			$editor = $import();
			$this->assertIsArray( $editor, $label );
			$this->assertSame( $kept_id, $editor['attachmentId'], $label );
			$this->assertFalse( $editor['duplicate'], $label );
			$this->assertSame( 15, $editor['postId'], $label );
			$this->assertSame( 'Red barn', $editor['alt'], $label );
			$this->assertSame( [ 1080, 720 ], [ $editor['width'], $editor['height'] ], $label );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', get_post_meta( $kept_id, '_unsplash_photographer_url', true ), $label );
			delete_post_meta( 15, '_thumbnail_id' );
		}

		// A retry that runs while the failed import deletes its upload cannot find that upload, so it uploads its own,
		// which stays: the cleanup never deletes an attachment that a retry has returned.
		$clear_attachments();
		$concurrent = null;
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']    = [ '_unsplash_photo_id' ];
		$GLOBALS['_bricks_mcp_test_media_before_delete_attachment'] = static function () use ( $import, &$concurrent ): void {
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$concurrent = $import();
		};
		$cleaned = $import();
		$this->assertInstanceOf( \WP_Error::class, $cleaned );
		$this->assertSame( [ 'status' => 500 ], $cleaned->get_error_data() );
		$this->assertIsArray( $concurrent );
		$this->assertSame( [ $concurrent['attachmentId'] ], $attachment_ids() );
		$this->assertSame( 'photo_77', get_post_meta( $concurrent['attachmentId'], '_unsplash_photo_id', true ) );
		$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $concurrent['photographerUrl'] );

		// An editor's retry that runs as the attribution write fails, and sees the photographer renamed, completes the
		// unfinished upload, which stays: a failed import never deletes an attachment that a retry has returned.
		$clear_attachments();
		$concurrent = null;
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']   = [ '_unsplash_photographer' ];
		$GLOBALS['_bricks_mcp_test_media_on_update_post_meta_fail'] = static function () use ( $import, &$concurrent, &$photographer ): void {
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$photographer = 'Pat Lee';
			$concurrent   = $import();
		};
		$cleaned = $import();
		$this->assertInstanceOf( \WP_Error::class, $cleaned );
		$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $cleaned->get_error_code() );
		$this->assertIsArray( $concurrent );
		$this->assertSame( $cleaned->get_error_data()['attachmentId'], $concurrent['attachmentId'] );
		$this->assertSame( [ $concurrent['attachmentId'] ], $attachment_ids() );
		$this->assertSame( 15, get_post( $concurrent['attachmentId'] )->post_parent );
		$this->assertSame( 'Pat Lee', get_post_meta( $concurrent['attachmentId'], '_unsplash_photographer', true ) );
		$photographer = 'Pat';

		// When the photographer URL write fails, the upload is kept with its photo ID, and a retry completes it
		// instead of uploading a second one. No cleanup delete runs that could strip its meta and then fail, and
		// a photo ID that WordPress's file name sanitizing would alter is found the same way.
		foreach ( [ 'photo_77', '-photo_77' ] as $photo_id ) {
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photographer_url' ];
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = 'after_meta';
			$kept_error = $import( $photo_id );
			$this->assertInstanceOf( \WP_Error::class, $kept_error, $photo_id );
			$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $kept_error->get_error_code(), $photo_id );
			$this->assertSame( 500, $kept_error->get_error_data()['status'], $photo_id );
			$kept_id = $kept_error->get_error_data()['attachmentId'];
			$this->assertSame( [ $kept_id ], $attachment_ids(), $photo_id );
			$this->assertSame( '', get_post_meta( $kept_id, '_unsplash_photographer_url', true ), $photo_id );

			// A user who cannot edit the kept upload gets it with its credit rather than a second upload.
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']             = false;
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] = false;
			$reused = $import( $photo_id );
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $kept_id ] );
			$this->assertSame( $kept_id, $reused['attachmentId'], $photo_id );
			$this->assertSame( 'Pat', $reused['photographer'], $photo_id );
			$this->assertSame( [ $kept_id ], $attachment_ids(), $photo_id );

			// A retry whose meta writes fail again keeps the upload, which may already be in use, even though deletion now works.
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = true;
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
			$repeat_error = $import( $photo_id );
			$this->assertInstanceOf( \WP_Error::class, $repeat_error, $photo_id );
			$this->assertSame( $kept_id, $repeat_error->get_error_data()['attachmentId'], $photo_id );
			$this->assertSame( [ $kept_id ], $attachment_ids(), $photo_id );

			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$completed = $import( $photo_id );
			$this->assertIsArray( $completed, $photo_id );
			$this->assertSame( $kept_id, $completed['attachmentId'], $photo_id );
			$this->assertFalse( $completed['duplicate'], $photo_id );
			$this->assertSame( 15, $completed['postId'], $photo_id );
			$this->assertSame( 'Pat', $completed['photographer'], $photo_id );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $completed['photographerUrl'], $photo_id );
			$this->assertSame( [ $kept_id ], $attachment_ids(), $photo_id );
			$this->assertSame( $photo_id, get_post_meta( $kept_id, '_unsplash_photo_id', true ), $photo_id );
			$duplicate = $import( $photo_id );
			$this->assertSame( $kept_id, $duplicate['attachmentId'], $photo_id );
			$this->assertTrue( $duplicate['duplicate'], $photo_id );
		}

		// A rejected pending mark does not hide the upload: with deletion refused, a retry returns the same attachment
		// instead of uploading a second one, with its attribution completed. Alone, the mark failure does not fail the import.
		foreach ( [ [ '_unsplash_attribution_pending' ], [ '_unsplash_attribution_pending', '_unsplash_photographer_url' ] ] as $failing_keys ) {
			$label = (string) wp_json_encode( $failing_keys );
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = $failing_keys;
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = true;
			$first = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
			$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
			if ( 1 === count( $failing_keys ) ) {
				$this->assertIsArray( $first, $label );
				$this->assertSame( 'Pat', $first['photographer'], $label );
				$first_id = $first['attachmentId'];
			} else {
				$this->assertInstanceOf( \WP_Error::class, $first, $label );
				$this->assertSame( 'bricks_mcp_unsplash_meta_failed', $first->get_error_code(), $label );
				$first_id = $first->get_error_data()['attachmentId'];
			}
			$this->assertSame( [ $first_id ], $attachment_ids(), $label );

			// Without its mark the upload is still an unfinished import, not a legacy one: a retry that cannot fetch the
			// credit (rate-limited, or the photo is gone) gets an error, not null credits, and a user who cannot edit it
			// gets it with its credit.
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $first_id ] = false;
			$other_author = $import();
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $first_id ] );
			$failed_codes = [];
			foreach ( [ 429, 404 ] as $code ) {
				$api_code       = $code;
				$failed_codes[] = $import();
			}
			$api_code = 200;
			$this->assertSame( $first_id, $other_author['attachmentId'], $label );
			$this->assertSame( 'Pat', $other_author['photographer'], $label );
			if ( 1 === count( $failing_keys ) ) {
				$this->assertSame( [ $first_id, $first_id ], array_column( $failed_codes, 'attachmentId' ), $label );
			} else {
				$this->assertSame(
					[ 429, 404 ],
					array_map( static fn( $failed ): int => $failed instanceof \WP_Error ? $failed->get_error_data()['status'] : 0, $failed_codes ),
					$label
				);
			}
			$this->assertSame( [ $first_id ], $attachment_ids(), $label );

			$retry = $import();
			$this->assertIsArray( $retry, $label );
			$this->assertSame( $first_id, $retry['attachmentId'], $label );
			$this->assertSame( 'Pat', $retry['photographer'], $label );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $retry['photographerUrl'], $label );
			$this->assertSame( [ $first_id ], $attachment_ids(), $label );
		}

		// A pending mark that WordPress fails to delete after the attribution saves, on a fresh import or on the retry that
		// completes a kept upload, does not leave the photo unfinished: another author, who can read but not edit it, gets
		// it with its saved credit even while Unsplash is rate-limited, rather than waiting on Unsplash to complete it, and
		// an editor gets it as a duplicate.
		foreach ( [ 'fresh', 'retry' ] as $completion ) {
			$clear_attachments();
			if ( 'retry' === $completion ) {
				$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = [ '_unsplash_photographer_url' ];
				$this->assertInstanceOf( \WP_Error::class, $import(), $completion );
				$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			}
			$GLOBALS['_bricks_mcp_test_media_delete_post_meta_fail'] = [ '_unsplash_attribution_pending' ];
			$completed = $import();
			$GLOBALS['_bricks_mcp_test_media_delete_post_meta_fail'] = false;
			$this->assertIsArray( $completed, $completion );
			$this->assertFalse( $completed['duplicate'], $completion );
			$completed_id = $completed['attachmentId'];
			$this->assertSame( '1', get_post_meta( $completed_id, '_unsplash_attribution_pending', true ), $completion );

			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $completed_id ] = false;
			$api_code     = 429;
			$other_author = $import();
			$api_code     = 200;
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $completed_id ] );
			$this->assertIsArray( $other_author, $completion );
			$this->assertSame( $completed_id, $other_author['attachmentId'], $completion );
			$this->assertTrue( $other_author['duplicate'], $completion );
			$this->assertSame( 'Pat', $other_author['photographer'], $completion );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $other_author['photographerUrl'], $completion );

			$editor = $import();
			$this->assertSame( $completed_id, $editor['attachmentId'], $completion );
			$this->assertTrue( $editor['duplicate'], $completion );
			$this->assertSame( [ $completed_id ], $attachment_ids(), $completion );
		}

		// Another retry that completes the kept upload while this retry waits on Unsplash leaves one attachment:
		// this retry returns the repaired upload instead of uploading a second one.
		$clear_attachments();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photographer_url' ];
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = 'after_meta';
		$kept_error = $import();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
		$this->assertInstanceOf( \WP_Error::class, $kept_error );
		$kept_id      = $kept_error->get_error_data()['attachmentId'];
		$concurrent   = null;
		$on_api_fetch = static function () use ( $import, &$concurrent ): void {
			$concurrent = $import();
		};
		$raced = $import();
		$this->assertIsArray( $concurrent );
		$this->assertSame( $kept_id, $concurrent['attachmentId'] );
		$this->assertIsArray( $raced );
		$this->assertSame( $kept_id, $raced['attachmentId'] );
		$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $raced['photographerUrl'] );
		$this->assertSame( [ $kept_id ], $attachment_ids() );
		$this->assertSame( 'photo_77', get_post_meta( $kept_id, '_unsplash_photo_id', true ) );

		// Photo IDs are case-sensitive. Newer imports of case variants of the photo ID do not hide the kept upload from
		// its retry, and an import of another case variant uploads its own attachment instead of returning one of them.
		$clear_attachments();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photographer_url' ];
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = 'after_meta';
		$kept_error = $import();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
		$this->assertInstanceOf( \WP_Error::class, $kept_error );
		$kept_id  = $kept_error->get_error_data()['attachmentId'];
		$variants = [ 'Photo_77', 'pHoto_77', 'phOto_77', 'phoTo_77', 'photO_77', 'PHoto_77', 'PhOto_77', 'PhoTo_77', 'PhotO_77', 'PHOTO_77' ];
		foreach ( $variants as $offset => $variant ) {
			bricks_mcp_test_add_post(
				[
					'ID'          => 900 + $offset,
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => 0,
				],
				[
					'_unsplash_photo_id'         => $variant,
					'_unsplash_photographer'     => 'Sam',
					'_unsplash_photographer_url' => 'https://unsplash.com/@sam?utm_source=bricks_mcp&utm_medium=referral',
				]
			);
		}
		$variant_ids = range( 900, 909 );
		$completed   = $import();
		$this->assertIsArray( $completed );
		$this->assertSame( $kept_id, $completed['attachmentId'] );
		$this->assertFalse( $completed['duplicate'] );
		$this->assertSame( 'Pat', $completed['photographer'] );
		$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', get_post_meta( $kept_id, '_unsplash_photographer_url', true ) );
		$this->assertSame( array_merge( [ $kept_id ], $variant_ids ), $attachment_ids() );
		$this->assertSame( $kept_id, $import()['attachmentId'] );
		$this->assertSame( 900, $import( 'Photo_77' )['attachmentId'] );
		$fresh_variant = $import( 'PHOTo_77' );
		$this->assertIsArray( $fresh_variant );
		$this->assertNotContains( $fresh_variant['attachmentId'], array_merge( [ $kept_id ], $variant_ids ) );
		$this->assertFalse( $fresh_variant['duplicate'] );
		$this->assertSame( 'PHOTo_77', get_post_meta( $fresh_variant['attachmentId'], '_unsplash_photo_id', true ) );
		unset( $GLOBALS['_bricks_mcp_test_posts'][ $fresh_variant['attachmentId'] ] );
		foreach ( $variant_ids as $variant_id ) {
			unset( $GLOBALS['_bricks_mcp_test_posts'][ $variant_id ], $GLOBALS['_bricks_mcp_test_post_meta'][ $variant_id ] );
		}

		// A kept upload that an editor trashes before the retry is not returned as the import: WordPress purges
		// trashed media later, so the retry uploads an attachment of its own, which outlives the purge.
		$clear_attachments();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = [ '_unsplash_photographer_url' ];
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = 'after_meta';
		$kept_error = $import();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail']     = false;
		$GLOBALS['_bricks_mcp_test_media_wp_delete_attachment_fail'] = false;
		$this->assertInstanceOf( \WP_Error::class, $kept_error );
		$trashed_id = $kept_error->get_error_data()['attachmentId'];
		// As wp_trash_post() does with MEDIA_TRASH on.
		$GLOBALS['_bricks_mcp_test_posts'][ $trashed_id ]->post_status                 = 'trash';
		$GLOBALS['_bricks_mcp_test_post_meta'][ $trashed_id ]['_wp_trash_meta_status'] = 'inherit';
		$after_trash = $import();
		$this->assertIsArray( $after_trash );
		$this->assertNotSame( $trashed_id, $after_trash['attachmentId'] );
		$this->assertFalse( $after_trash['duplicate'] );
		$this->assertSame( 15, $after_trash['postId'] );
		$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $after_trash['photographerUrl'] );
		unset( $GLOBALS['_bricks_mcp_test_posts'][ $trashed_id ], $GLOBALS['_bricks_mcp_test_post_meta'][ $trashed_id ] );
		$this->assertSame( [ $after_trash['attachmentId'] ], $attachment_ids() );
		$this->assertSame( 'photo_77', get_post_meta( $after_trash['attachmentId'], '_unsplash_photo_id', true ) );
		$after_purge = $import();
		$this->assertSame( $after_trash['attachmentId'], $after_purge['attachmentId'] );
		$this->assertTrue( $after_purge['duplicate'] );

		// A kept upload that its uploader deletes while a retry waits on Unsplash is not returned, to an editor or to an
		// author who could read it: the retry uploads an attachment of its own, credited and attached to its post.
		foreach ( [ 'editor', 'reader' ] as $retrier ) {
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = [ '_unsplash_photographer_url' ];
			$kept_error = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$this->assertInstanceOf( \WP_Error::class, $kept_error, $retrier );
			$deleted_id = $kept_error->get_error_data()['attachmentId'];
			if ( 'reader' === $retrier ) {
				$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] = false;
			}
			$on_api_fetch = static function () use ( $deleted_id ): void {
				wp_delete_attachment( $deleted_id, true );
			};
			$after_delete = $import();
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] );
			$this->assertIsArray( $after_delete, $retrier );
			$this->assertNotSame( $deleted_id, $after_delete['attachmentId'], $retrier );
			$this->assertFalse( $after_delete['duplicate'], $retrier );
			$this->assertSame( 15, $after_delete['postId'], $retrier );
			$this->assertSame( [ $after_delete['attachmentId'] ], $attachment_ids(), $retrier );
			$this->assertSame( 'photo_77', get_post_meta( $after_delete['attachmentId'], '_unsplash_photo_id', true ), $retrier );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', get_post_meta( $after_delete['attachmentId'], '_unsplash_photographer_url', true ), $retrier );
		}

		// When another import replaces the deleted upload before the waiting retry resumes, the retry returns that
		// replacement, to an editor or to an author who could read the deleted upload, rather than upload a second copy.
		// Its Unsplash request failing does not hide the replacement either.
		foreach ( [ 'editor' => 200, 'reader' => 200, 'rate-limited' => 429 ] as $retrier => $retry_code ) {
			$clear_attachments();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = [ '_unsplash_photographer_url' ];
			$kept_error = $import();
			$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
			$this->assertInstanceOf( \WP_Error::class, $kept_error, $retrier );
			$deleted_id = $kept_error->get_error_data()['attachmentId'];
			if ( 'reader' === $retrier ) {
				$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] = false;
			}
			$replacement  = null;
			$api_code     = $retry_code;
			$on_api_fetch = static function () use ( $deleted_id, $import, &$replacement, &$api_code ): void {
				wp_delete_attachment( $deleted_id, true );
				$retry_code  = $api_code;
				$api_code    = 200;
				$replacement = $import();
				$api_code    = $retry_code;
			};
			$after_replace = $import();
			$api_code      = 200;
			unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] );
			$this->assertIsArray( $replacement, $retrier );
			$this->assertFalse( $replacement['duplicate'], $retrier );
			$this->assertIsArray( $after_replace, $retrier );
			$this->assertSame( $replacement['attachmentId'], $after_replace['attachmentId'], $retrier );
			$this->assertTrue( $after_replace['duplicate'], $retrier );
			$this->assertSame( 15, $after_replace['postId'], $retrier );
			$this->assertSame( 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral', $after_replace['photographerUrl'], $retrier );
			$this->assertSame( [ $replacement['attachmentId'] ], $attachment_ids(), $retrier );
		}

		// A replacement on a post that an editor makes private while the retry waits is not returned to an author who can
		// neither edit nor read it, although the permission check loaded that post into the request's cache before: the
		// retry uploads an attachment of its own, and the replacement keeps its parent.
		$clear_attachments();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = [ '_unsplash_photographer_url' ];
		$kept_error = $import();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
		$this->assertInstanceOf( \WP_Error::class, $kept_error );
		$deleted_id = $kept_error->get_error_data()['attachmentId'];
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] = false;
		$GLOBALS['_bricks_mcp_test_post_cache']                               = [];
		$target_status = get_post( 15 )->post_status;
		$replacement   = null;
		$on_api_fetch  = static function () use ( $deleted_id, $import, &$replacement ): void {
			wp_delete_attachment( $deleted_id, true );
			$replacement    = $import();
			$replacement_id = $replacement['attachmentId'];
			// The stored replacement is on the target post, as the database holds it after the other import's attach.
			$GLOBALS['_bricks_mcp_test_posts'][ $replacement_id ]->post_parent      = 15;
			$GLOBALS['_bricks_mcp_test_posts'][15]->post_status                    = 'private';
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $replacement_id ] = false;
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $replacement_id ] = static function () use ( $replacement_id ): bool {
				$parent_id = get_post( $replacement_id )->post_parent;
				return 0 === $parent_id || 'private' !== get_post( $parent_id )->post_status;
			};
		};
		$private_replace = $import();
		$GLOBALS['_bricks_mcp_test_post_cache']             = null;
		$GLOBALS['_bricks_mcp_test_posts'][15]->post_status = $target_status;
		unset(
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ],
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $replacement['attachmentId'] ],
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $replacement['attachmentId'] ]
		);
		$this->assertIsArray( $replacement );
		$this->assertIsArray( $private_replace );
		$this->assertNotContains( $private_replace['attachmentId'], [ $deleted_id, $replacement['attachmentId'] ] );
		$this->assertFalse( $private_replace['duplicate'] );
		$this->assertSame( 15, get_post( $replacement['attachmentId'] )->post_parent );
		$this->assertEqualsCanonicalizing( [ $replacement['attachmentId'], $private_replace['attachmentId'] ], $attachment_ids() );

		// The retry returns the replacement it checked access to, not a newer private import of the photo that appears
		// after that check.
		$clear_attachments();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = [ '_unsplash_photographer_url' ];
		$kept_error = $import();
		$GLOBALS['_bricks_mcp_test_media_update_post_meta_fail'] = false;
		$this->assertInstanceOf( \WP_Error::class, $kept_error );
		$deleted_id = $kept_error->get_error_data()['attachmentId'];
		$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ] = false;
		$replacement  = null;
		$on_api_fetch = static function () use ( $deleted_id, $import, &$replacement ): void {
			wp_delete_attachment( $deleted_id, true );
			$replacement    = $import();
			$replacement_id = $replacement['attachmentId'];
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $replacement_id ] = false;
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $replacement_id ] = static function (): bool {
				if ( ! isset( $GLOBALS['_bricks_mcp_test_posts'][950] ) ) {
					bricks_mcp_test_add_post(
						[
							'ID'           => 950,
							'post_type'    => 'attachment',
							'post_status'  => 'inherit',
							'post_parent'  => 16,
							'post_excerpt' => 'Private caption',
						],
						[
							'_unsplash_photo_id'         => 'photo_77',
							'_unsplash_photographer_url' => 'https://unsplash.com?utm_source=bricks_mcp&utm_medium=referral',
						]
					);
					$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][950] = false;
					$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][950] = false;
				}

				return true;
			};
		};
		$checked_replace = $import();
		unset(
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $deleted_id ],
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][ $replacement['attachmentId'] ],
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][ $replacement['attachmentId'] ],
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][950],
			$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][950]
		);
		$this->assertIsArray( $replacement );
		$this->assertIsArray( $checked_replace );
		$this->assertSame( $replacement['attachmentId'], $checked_replace['attachmentId'] );
		$this->assertTrue( $checked_replace['duplicate'] );
		$this->assertEqualsCanonicalizing( [ $replacement['attachmentId'], 950 ], $attachment_ids() );

		// A legacy attachment deleted while its credit backfill waits on a failing Unsplash request is not returned either.
		$clear_attachments();
		bricks_mcp_test_add_post(
			[
				'ID'          => 600,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0,
			],
			[ '_unsplash_photo_id' => 'photo_77' ]
		);
		$api_code     = 429;
		$on_api_fetch = static function (): void {
			wp_delete_attachment( 600, true );
		};
		$legacy_deleted = $import();
		$api_code       = 200;
		$this->assertInstanceOf( \WP_Error::class, $legacy_deleted );
		$this->assertSame( 429, $legacy_deleted->get_error_data()['status'] );
		$this->assertSame( [], $attachment_ids() );

		// A legacy attachment that an editor recaptions and attaches to a post made private, revoking this caller's edit
		// access, while the backfill waits on a failing Unsplash request, is not returned: the caller gets the Unsplash error.
		bricks_mcp_test_add_post(
			[
				'ID'           => 601,
				'post_type'    => 'attachment',
				'post_status'  => 'inherit',
				'post_parent'  => 0,
				'post_excerpt' => 'Old caption',
			],
			[ '_unsplash_photo_id' => 'photo_77' ]
		);
		$GLOBALS['_bricks_mcp_test_post_cache']                    = [];
		$GLOBALS['_bricks_mcp_test_object_caps']['read_post'][601] = static function (): bool {
			$parent_id = get_post( 601 )->post_parent;
			return 0 === $parent_id || 'private' !== get_post( $parent_id )->post_status;
		};
		$target_status = get_post( 15 )->post_status;
		$api_code      = 429;
		$on_api_fetch  = static function (): void {
			$GLOBALS['_bricks_mcp_test_posts'][601]->post_parent             = 15;
			$GLOBALS['_bricks_mcp_test_posts'][601]->post_excerpt            = 'Private caption';
			$GLOBALS['_bricks_mcp_test_posts'][15]->post_status              = 'private';
			$GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][601] = false;
		};
		$legacy_moved = $import();
		$api_code     = 200;
		$GLOBALS['_bricks_mcp_test_post_cache']             = null;
		$GLOBALS['_bricks_mcp_test_posts'][15]->post_status = $target_status;
		unset( $GLOBALS['_bricks_mcp_test_object_caps']['edit_post'][601], $GLOBALS['_bricks_mcp_test_object_caps']['read_post'][601] );
		$this->assertInstanceOf( \WP_Error::class, $legacy_moved );
		$this->assertSame( 429, $legacy_moved->get_error_data()['status'] );
		$this->assertSame( [ 601 ], $attachment_ids() );
		$clear_attachments();

		// An ordinary upload that shares the import's file name is left alone, and the import uploads its own.
		$clear_attachments();
		bricks_mcp_test_add_post(
			[
				'ID'          => 500,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 0,
			],
			[ '_wp_attached_file' => '2026/10/unsplash-photo_77.webp' ]
		);
		$fresh = $import();
		$this->assertIsArray( $fresh );
		$this->assertNotSame( 500, $fresh['attachmentId'] );
		$this->assertSame( '', get_post_meta( 500, '_unsplash_photo_id', true ) );
		$this->assertSame( 0, get_post( 500 )->post_parent );

		unset( $GLOBALS['_bricks_mcp_test_posts'][ $fresh['attachmentId'] ] );
		$GLOBALS['_bricks_mcp_test_media_wp_update_post_fail'] = true;
		$attach_error = $import();
		$this->assertInstanceOf( \WP_Error::class, $attach_error );
		$this->assertSame( 'bricks_mcp_unsplash_attach_failed', $attach_error->get_error_code() );
		$this->assertSame( 500, $attach_error->get_error_data()['status'] );
		$this->assertSame( 'photo_77', get_post_meta( $attach_error->get_error_data()['attachmentId'], '_unsplash_photo_id', true ) );
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
