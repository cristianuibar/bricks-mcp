<?php
/**
 * Tests for the content abilities.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\Content;
use PHPUnit\Framework\TestCase;

/**
 * Covers SEO, post status, featured image and editor mode behaviour.
 */
final class ContentTest extends TestCase {

	private const POST_ID = 10;

	/**
	 * Reset in-memory WordPress state and register the content abilities.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		bricks_mcp_test_reset();
		\Bricks\Capabilities::$can_use_builder          = true;
		$GLOBALS['_bricks_mcp_test_post_locks']         = [];
		$GLOBALS['_bricks_mcp_test_no_thumbnail_types'] = [];

		bricks_mcp_test_add_post(
			[
				'ID'          => self::POST_ID,
				'post_type'   => 'page',
				'post_status' => 'draft',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'             => 20,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'             => 21,
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
			]
		);

		foreach ( ( new Content() )->abilities() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Drop the test globals that are not part of the shared reset.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_bricks_mcp_test_post_locks'], $GLOBALS['_bricks_mcp_test_no_thumbnail_types'] );
	}

	/**
	 * Run an ability by name.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed
	 */
	private function call( string $name, array $input ): mixed {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Register a fake bricks/set-page-settings that merges into the page settings meta.
	 *
	 * @return void
	 */
	private function fake_bricks_page_settings(): void {
		wp_register_ability(
			'bricks/set-page-settings',
			[
				'execute_callback' => static function ( array $input ): array {
					$existing = get_post_meta( $input['postId'], '_bricks_page_settings', true );
					$merged   = is_array( $existing ) ? $existing : [];

					foreach ( $input['settings'] as $key => $value ) {
						if ( null === $value ) {
							unset( $merged[ $key ] );
						} else {
							$merged[ $key ] = $value;
						}
					}

					update_post_meta( $input['postId'], '_bricks_page_settings', $merged );

					return [
						'settings' => $merged,
						'postId'   => $input['postId'],
					];
				},
			]
		);
	}

	/**
	 * Without an SEO plugin the Bricks page settings are read and written through the Bricks ability.
	 *
	 * @return void
	 */
	public function test_seo_falls_back_to_bricks_page_settings(): void {
		$this->fake_bricks_page_settings();
		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'noarchive' ] ] );

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'title'    => 'Roofing in Cluj',
					'ogImage'  => 'https://example.test/og.jpg',
					'noindex'  => true,
					'canonical' => 'https://example.test/roofing',
				],
			]
		);

		$settings = get_post_meta( self::POST_ID, '_bricks_page_settings', true );

		$this->assertSame( 'bricks', $result['source'] );
		$this->assertSame( [ 'title', 'ogImage', 'noindex' ], $result['updated'] );
		$this->assertSame( [ 'canonical' ], $result['unsupported'] );
		$this->assertSame( 'Roofing in Cluj', $settings['documentTitle'] );
		$this->assertSame( 'https://example.test/og.jpg', $settings['sharingImage']['url'] );
		$this->assertSame( [ 'noarchive', 'noindex' ], $settings['metaRobots'] );
		$this->assertTrue( $result['after']['noindex'] );
		$this->assertFalse( $result['before']['noindex'] );
	}

	/**
	 * Clearing robots removes the directive and keeps unrelated ones.
	 *
	 * @return void
	 */
	public function test_bricks_fallback_clears_noindex_and_keeps_other_directives(): void {
		$this->fake_bricks_page_settings();
		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'noindex', 'nosnippet' ] ] );

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'noindex' => false ],
			]
		);

		$settings = get_post_meta( self::POST_ID, '_bricks_page_settings', true );

		$this->assertSame( [ 'nosnippet' ], $settings['metaRobots'] );
	}

	/**
	 * A missing Bricks ability is reported instead of writing page settings directly.
	 *
	 * @return void
	 */
	public function test_bricks_fallback_errors_when_bricks_ability_is_missing(): void {
		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'title' => 'Hello' ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_bricks_ability_missing', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( self::POST_ID, '_bricks_page_settings', true ) );
	}

	/**
	 * Yoast meta is written, sanitized and read back with an audit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_yoast_seo_round_trip_with_audit(): void {
		eval( 'final class WPSEO_Options {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.

		$write = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'title'        => '  Short <b>title</b>  ',
					'description'  => str_repeat( 'a', 170 ),
					'nofollow'     => true,
					'focusKeyword' => 'roofing',
					'ogImage'      => 'javascript:alert(1)',
				],
			]
		);
		$read  = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( 'yoast', $write['source'] );
		$this->assertSame( 'Short title', get_post_meta( self::POST_ID, '_yoast_wpseo_title', true ) );
		$this->assertSame( '1', get_post_meta( self::POST_ID, '_yoast_wpseo_meta-robots-nofollow', true ) );
		$this->assertSame( '', get_post_meta( self::POST_ID, '_yoast_wpseo_opengraph-image', true ) );
		$this->assertSame( 'roofing', $read['fields']['focusKeyword'] );
		$this->assertTrue( $read['fields']['nofollow'] );
		$this->assertFalse( $read['fields']['noindex'] );
		$this->assertSame( 'too_short', $read['audit']['titleIssue'] );
		$this->assertSame( 'too_long', $read['audit']['descriptionIssue'] );
		$this->assertFalse( $read['audit']['hasOgImage'] );
	}

	/**
	 * Publishing needs the post type's publish capability; drafting does not.
	 *
	 * @return void
	 */
	public function test_publish_is_denied_without_publish_capability(): void {
		$GLOBALS['_bricks_mcp_test_caps']['publish_posts'] = false;

		$publish = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);
		$draft   = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'draft',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $publish );
		$this->assertSame( 403, $publish->get_error_data()['status'] );
		$this->assertSame( 'draft', get_post( self::POST_ID )->post_status );
		$this->assertSame( 'draft', $draft['status'] );
	}

	/**
	 * Publishing reports previous and new status.
	 *
	 * @return void
	 */
	public function test_publish_reports_previous_and_new_status(): void {
		$result = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);

		$this->assertSame( 'draft', $result['previousStatus'] );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', get_post( self::POST_ID )->post_status );
	}

	/**
	 * A post locked by another user is not changed.
	 *
	 * @return void
	 */
	public function test_status_change_is_refused_while_another_user_holds_the_lock(): void {
		$GLOBALS['_bricks_mcp_test_post_locks'][ self::POST_ID ] = 7;

		$result = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);

		$this->assertSame( 'bricks_mcp_post_locked', $result->get_error_code() );
		$this->assertSame( 'draft', get_post( self::POST_ID )->post_status );
	}

	/**
	 * Scheduling needs a future date and stores it.
	 *
	 * @return void
	 */
	public function test_future_status_requires_a_future_date(): void {
		$missing = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'future',
			]
		);
		$past    = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'future',
				'date'   => '2020-01-01T09:00:00+00:00',
			]
		);
		$ok      = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'future',
				'date'   => gmdate( 'c', time() + 86400 ),
			]
		);

		$this->assertSame( 'bricks_mcp_invalid_date', $missing->get_error_code() );
		$this->assertSame( 'bricks_mcp_invalid_date', $past->get_error_code() );
		$this->assertSame( 'future', $ok['status'] );
	}

	/**
	 * The featured image can be set, repeated without error and removed.
	 *
	 * @return void
	 */
	public function test_featured_image_set_repeat_and_remove(): void {
		$set    = $this->call(
			'bricks-mcp/set-featured-image',
			[
				'postId'       => self::POST_ID,
				'attachmentId' => 20,
			]
		);
		$repeat = $this->call(
			'bricks-mcp/set-featured-image',
			[
				'postId'       => self::POST_ID,
				'attachmentId' => 20,
			]
		);
		$remove = $this->call(
			'bricks-mcp/set-featured-image',
			[
				'postId'       => self::POST_ID,
				'attachmentId' => 0,
			]
		);

		$this->assertSame( 20, $set['attachmentId'] );
		$this->assertSame( 20, $repeat['attachmentId'] );
		$this->assertSame( 20, $remove['previousAttachmentId'] );
		$this->assertSame( 0, $remove['attachmentId'] );
		$this->assertFalse( get_post_thumbnail_id( self::POST_ID ) );
	}

	/**
	 * Non-image attachments and post types without thumbnail support are rejected.
	 *
	 * @return void
	 */
	public function test_featured_image_rejects_non_images_and_unsupported_post_types(): void {
		$pdf = $this->call(
			'bricks-mcp/set-featured-image',
			[
				'postId'       => self::POST_ID,
				'attachmentId' => 21,
			]
		);

		$GLOBALS['_bricks_mcp_test_no_thumbnail_types'] = [ 'page' ];
		$unsupported                                    = $this->call(
			'bricks-mcp/set-featured-image',
			[
				'postId'       => self::POST_ID,
				'attachmentId' => 20,
			]
		);

		$this->assertSame( 'bricks_mcp_attachment_invalid', $pdf->get_error_code() );
		$this->assertSame( 'bricks_mcp_thumbnails_unsupported', $unsupported->get_error_code() );
		$this->assertFalse( get_post_thumbnail_id( self::POST_ID ) );
	}

	/**
	 * Editor mode stores the chosen mode, so `wordpress` stops Bricks rendering, and keeps element data.
	 *
	 * @return void
	 */
	public function test_editor_mode_stores_each_mode_and_keeps_element_data(): void {
		update_post_meta( self::POST_ID, '_bricks_page_content_2', [ [ 'id' => 'abc123' ] ] );

		$on      = $this->call(
			'bricks-mcp/set-editor-mode',
			[
				'postId' => self::POST_ID,
				'mode'   => 'bricks',
			]
		);
		$meta_on = get_post_meta( self::POST_ID, '_bricks_editor_mode', true );
		$off     = $this->call(
			'bricks-mcp/set-editor-mode',
			[
				'postId' => self::POST_ID,
				'mode'   => 'wordpress',
			]
		);

		$this->assertSame( 'default', $on['previousMode'] );
		$this->assertSame( 'bricks', $meta_on );
		$this->assertSame( 'bricks', $off['previousMode'] );
		$this->assertSame( 'wordpress', get_post_meta( self::POST_ID, '_bricks_editor_mode', true ) );
		$this->assertSame( [ [ 'id' => 'abc123' ] ], get_post_meta( self::POST_ID, '_bricks_page_content_2', true ) );
	}

	/**
	 * A stored `wordpress` mode is reported as the previous mode.
	 *
	 * @return void
	 */
	public function test_editor_mode_reports_stored_wordpress_mode(): void {
		update_post_meta( self::POST_ID, '_bricks_editor_mode', 'wordpress' );

		$result = $this->call(
			'bricks-mcp/set-editor-mode',
			[
				'postId' => self::POST_ID,
				'mode'   => 'bricks',
			]
		);

		$this->assertSame( 'wordpress', $result['previousMode'] );
	}

	/**
	 * Scheduling asks WordPress to keep the given date instead of resetting it to now.
	 *
	 * @return void
	 */
	public function test_future_status_asks_wordpress_to_keep_the_date(): void {
		$timestamp = time() + 86400;

		$this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'future',
				'date'   => gmdate( 'c', $timestamp ),
			]
		);
		$post = get_post( self::POST_ID );

		$this->assertTrue( $post->edit_date );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), $post->post_date_gmt );
	}

	/**
	 * Status changes leave the date alone when no date is being scheduled.
	 *
	 * @return void
	 */
	public function test_publish_does_not_force_a_date(): void {
		$this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);

		$this->assertObjectNotHasProperty( 'edit_date', get_post( self::POST_ID ) );
	}

	/**
	 * Publishing a scheduled post moves its date to now so it goes live.
	 *
	 * @return void
	 */
	public function test_publish_of_a_scheduled_post_goes_live_now(): void {
		$post                = get_post( self::POST_ID );
		$post->post_status   = 'future';
		$post->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() + 86400 );

		$this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);
		$post = get_post( self::POST_ID );

		$this->assertTrue( $post->edit_date );
		$this->assertLessThanOrEqual( time(), strtotime( $post->post_date_gmt . ' UTC' ) );
	}

	/**
	 * Clearing noindex while Bricks stores `none` keeps nofollow and drops `none`.
	 *
	 * @return void
	 */
	public function test_bricks_fallback_expands_none_when_clearing_noindex(): void {
		$this->fake_bricks_page_settings();
		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'none' ] ] );

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'noindex' => false ],
			]
		);

		$settings = get_post_meta( self::POST_ID, '_bricks_page_settings', true );

		$this->assertSame( [ 'nofollow' ], $settings['metaRobots'] );
		$this->assertTrue( $result['before']['noindex'] );
		$this->assertFalse( $result['after']['noindex'] );
		$this->assertTrue( $result['after']['nofollow'] );
	}

	/**
	 * Writing the Open Graph image resets the dynamic data and size that would override it.
	 *
	 * @return void
	 */
	public function test_bricks_fallback_resets_dynamic_sharing_image(): void {
		$sent = [];

		wp_register_ability(
			'bricks/set-page-settings',
			[
				'execute_callback' => static function ( array $input ) use ( &$sent ): array {
					$sent = $input['settings'];

					return [ 'postId' => $input['postId'] ];
				},
			]
		);

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'ogImage' => 'https://example.test/og.jpg' ],
			]
		);

		$this->assertSame( 'https://example.test/og.jpg', $sent['sharingImage']['url'] );
		$this->assertArrayHasKey( 'useDynamicData', $sent['sharingImage'] );
		$this->assertNull( $sent['sharingImage']['useDynamicData'] );
		$this->assertArrayHasKey( 'size', $sent['sharingImage'] );
		$this->assertNull( $sent['sharingImage']['size'] );
	}

	/**
	 * Rank Math robots swap index/noindex and follow/nofollow instead of holding both.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rank_math_robots_swap_opposite_directives(): void {
		eval( 'final class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, 'rank_math_robots', [ 'index', 'follow', 'noarchive' ] );

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'noindex'  => true,
					'nofollow' => true,
				],
			]
		);
		$blocked = get_post_meta( self::POST_ID, 'rank_math_robots', true );

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'noindex' => false ],
			]
		);
		$reopened = get_post_meta( self::POST_ID, 'rank_math_robots', true );

		$this->assertEqualsCanonicalizing( [ 'noarchive', 'noindex', 'nofollow' ], $blocked );
		$this->assertEqualsCanonicalizing( [ 'noarchive', 'nofollow', 'index' ], $reopened );
	}

	/**
	 * Without Bricks builder access an editor can still change status and featured image, but not the editor mode.
	 *
	 * @return void
	 */
	public function test_plain_wordpress_abilities_do_not_need_builder_access(): void {
		\Bricks\Capabilities::$can_use_builder = false;
		$abilities                              = ( new Content() )->abilities();
		$input                                  = [
			'postId'       => self::POST_ID,
			'status'       => 'draft',
			'attachmentId' => 20,
			'mode'         => 'bricks',
		];

		foreach ( [ 'bricks-mcp/get-seo', 'bricks-mcp/update-seo', 'bricks-mcp/set-post-status', 'bricks-mcp/set-featured-image' ] as $name ) {
			$this->assertTrue( call_user_func( $abilities[ $name ]['permission_callback'], $input ), $name );
		}

		$editor_mode = call_user_func( $abilities['bricks-mcp/set-editor-mode']['permission_callback'], $input );

		$this->assertInstanceOf( \WP_Error::class, $editor_mode );
		$this->assertSame( 'bricks_mcp_forbidden_builder', $editor_mode->get_error_code() );
	}

	/**
	 * Every ability refuses users who cannot edit the post; editor mode also needs builder access.
	 *
	 * @return void
	 */
	public function test_every_ability_requires_edit_access(): void {
		$GLOBALS['_bricks_mcp_test_caps']['edit_post'] = false;

		foreach ( ( new Content() )->abilities() as $name => $args ) {
			$allowed = call_user_func(
				$args['permission_callback'],
				[
					'postId' => self::POST_ID,
					'status' => 'draft',
				]
			);

			$this->assertInstanceOf( \WP_Error::class, $allowed, $name );
		}

		$GLOBALS['_bricks_mcp_test_caps']['edit_post'] = true;
		\Bricks\Capabilities::$can_use_builder         = false;
		$allowed                                       = call_user_func( ( new Content() )->abilities()['bricks-mcp/set-editor-mode']['permission_callback'], [ 'postId' => self::POST_ID ] );

		$this->assertSame( 'bricks_mcp_forbidden_builder', $allowed->get_error_code() );
	}
}
