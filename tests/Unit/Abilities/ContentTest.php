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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers SEO, post status, featured image and editor mode behaviour.
 */
final class ContentTest extends TestCase {

	private const POST_ID = 10;

	private const YOAST_FAKE = "final class WPSEO_Options { public static function get( string \$key, mixed \$default = null ): mixed { return \$GLOBALS['_bricks_mcp_test_yoast_options'][ \$key ] ?? \$default; } }";

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
		$this->assertSame( [], $result['warnings'] );
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
		eval( self::YOAST_FAKE ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.

		$write = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'title'        => '  Short <b>title</b>  ',
					'description'  => str_repeat( 'a', 170 ),
					'nofollow'     => true,
					'focusKeyword' => 'roofing',
				],
			]
		);
		$read  = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( 'yoast', $write['source'] );
		$this->assertSame( 'Short title', get_post_meta( self::POST_ID, '_yoast_wpseo_title', true ) );
		$this->assertSame( '1', get_post_meta( self::POST_ID, '_yoast_wpseo_meta-robots-nofollow', true ) );
		$this->assertSame( 'roofing', $read['fields']['focusKeyword'] );
		$this->assertTrue( $read['fields']['nofollow'] );
		$this->assertFalse( $read['fields']['noindex'] );
		$this->assertSame( 'too_short', $read['audit']['titleIssue'] );
		$this->assertSame( 'too_long', $read['audit']['descriptionIssue'] );
		$this->assertFalse( $read['audit']['hasOgImage'] );
	}

	/**
	 * Yoast keeps noindex in three states: 1 noindex, 2 index, anything else the post type default. noindex:false must
	 * force index, and get-seo reports what the page serves.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_yoast_noindex_false_forces_index_over_a_noindex_post_type_default(): void {
		eval( self::YOAST_FAKE ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$GLOBALS['_bricks_mcp_test_yoast_options'] = [ 'noindex-page' => true ];
		$noindex                                  = static fn( array $read ): bool => $read['fields']['noindex'];

		$inherited = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'noindex' => false ] ] );
		$forced_index = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );
		$stored_index = get_post_meta( self::POST_ID, '_yoast_wpseo_meta-robots-noindex', true );

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'nofollow' => false ] ] );
		$stored_follow = get_post_meta( self::POST_ID, '_yoast_wpseo_meta-robots-nofollow', true );

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'noindex' => true ] ] );
		$forced_noindex = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertTrue( $noindex( $inherited ) );
		$this->assertSame( '2', $stored_index );
		$this->assertSame( '', $stored_follow );
		$this->assertFalse( $noindex( $forced_index ) );
		$this->assertSame( '1', get_post_meta( self::POST_ID, '_yoast_wpseo_meta-robots-noindex', true ) );
		$this->assertTrue( $noindex( $forced_noindex ) );
	}

	/**
	 * Rank Math ignores its Twitter fields while the use-Facebook toggle is on, so writing one must switch it off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rank_math_twitter_write_switches_off_the_use_facebook_toggle(): void {
		eval( 'final class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$toggle = static fn(): mixed => get_post_meta( self::POST_ID, 'rank_math_twitter_use_facebook', true );

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'ogTitle' => 'Share title' ] ] );
		$after_og = $toggle();

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'twitterTitle' => 'Tweet title' ] ] );

		$this->assertSame( '', $after_og );
		$this->assertSame( 'off', $toggle() );
	}

	/**
	 * Rank Math prefers the stored image ID, so writing or clearing a social image must keep the ID in step with the URL.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rank_math_social_image_write_keeps_the_image_id_in_sync(): void {
		eval( 'final class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, 'rank_math_facebook_image_id', 99 );
		update_post_meta( self::POST_ID, 'rank_math_twitter_image_id', 99 );

		$library = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'ogImage'      => 'https://example.test/uploads/20.jpg',
					'twitterImage' => 'https://cdn.example.com/outside.jpg',
					'ogTitle'      => 'Share title',
				],
			]
		);
		$stored  = [
			get_post_meta( self::POST_ID, 'rank_math_facebook_image_id', true ),
			get_post_meta( self::POST_ID, 'rank_math_twitter_image_id', true ),
		];

		$this->call( 'bricks-mcp/update-seo', [ 'postId' => self::POST_ID, 'seo' => [ 'ogImage' => '' ] ] );
		$read = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( [ 'ogImage', 'twitterImage', 'ogTitle' ], $library['updated'] );
		$this->assertSame( [], $library['unsupported'] );
		$this->assertSame( [ 20, '' ], $stored );
		$this->assertSame( '', get_post_meta( self::POST_ID, 'rank_math_facebook_image_id', true ) );
		$this->assertSame( '', $read['fields']['ogImage'] );
		$this->assertSame( 'https://cdn.example.com/outside.jpg', $read['fields']['twitterImage'] );
		$this->assertSame( 'Share title', $read['fields']['ogTitle'] );
	}

	/**
	 * SEO plugins with a stored image, and a write whose image URL WordPress rejects.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed, 3: array<string, string>}>
	 */
	public static function rejected_image_urls(): array {
		return [
			'Slim SEO image as a data URL'  => [
				'namespace SlimSEO\\MetaTags; final class Title {}',
				'slim_seo',
				[ 'facebook_image' => 'https://example.com/fb.jpg' ],
				[
					'title'   => 'New title',
					'ogImage' => 'data:image/png;base64,iVBORw0KGgo=',
				],
			],
			'Yoast image as a javascript URL' => [
				self::YOAST_FAKE,
				'_yoast_wpseo_opengraph-image',
				'https://example.com/og.jpg',
				[
					'title'   => 'New title',
					'ogImage' => 'javascript:alert(1)',
				],
			],
		];
	}

	/**
	 * A non-empty image URL that sanitizes to nothing fails the write and keeps the stored image and every other field.
	 *
	 * @param string                $plugin   Fake plugin declaration.
	 * @param string                $meta_key Meta key holding the image.
	 * @param mixed                 $stored   Stored meta value.
	 * @param array<string, string> $seo      Fields to write.
	 * @return void
	 */
	#[DataProvider( 'rejected_image_urls' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rejected_image_url_keeps_the_stored_image( string $plugin, string $meta_key, mixed $stored, array $seo ): void {
		eval( $plugin ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, $meta_key, $stored );

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => $seo,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_invalid_seo_url', $result->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
		$this->assertSame( $stored, get_post_meta( self::POST_ID, $meta_key, true ) );
		$this->assertSame( '', get_post_meta( self::POST_ID, '_yoast_wpseo_title', true ) );
	}

	/**
	 * SEOPress keeps its target keywords as one comma-separated string, which get-seo reads and update-seo replaces as is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_seopress_focus_keyword_round_trip(): void {
		eval( 'function seopress_init() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin function in this separate test process only.
		update_post_meta( self::POST_ID, '_seopress_analysis_target_kw', 'local garden,garden design' );

		$read  = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );
		$write = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'focusKeyword' => 'replacement,second keyword' ],
			]
		);

		$this->assertContains( 'focusKeyword', $read['supportedFields'] );
		$this->assertSame( 'local garden,garden design', $read['fields']['focusKeyword'] );
		$this->assertSame( [ 'focusKeyword' ], $write['updated'] );
		$this->assertSame( [], $write['unsupported'] );
		$this->assertSame( 'replacement,second keyword', $write['after']['focusKeyword'] );
		$this->assertSame( 'replacement,second keyword', get_post_meta( self::POST_ID, '_seopress_analysis_target_kw', true ) );
	}

	/**
	 * With SEOPress active, Bricks output that is still on and a noindex left by the Bricks fallback are reported until
	 * the Bricks SEO and Open Graph output is turned off. A sharing image with a saved URL and a dynamic tag reports the
	 * tag, the image Bricks prints, and a whitespace-only tag reports the URL.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_seo_plugin_reports_bricks_output_and_stale_bricks_robots(): void {
		eval( 'function seopress_init() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin function in this separate test process only.
		update_post_meta(
			self::POST_ID,
			'_bricks_page_settings',
			[
				'metaRobots'      => [ 'noindex', 'nofollow' ],
				'metaDescription' => 'Old Bricks description',
				'sharingImage'    => [
					'url'            => 'https://example.test/old-og.jpg',
					'useDynamicData' => '{featured_image}',
				],
			]
		);

		$read   = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );
		$write  = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'title' => 'Roofing in Cluj' ],
			]
		);
		$issues = array_column( $read['audit']['warnings'], null, 'type' );

		$this->assertSame( 'seopress', $read['source'] );
		$this->assertFalse( $read['fields']['noindex'] );
		$this->assertSame( [ 'seopress' ], $read['audit']['activeSeoPlugins'] );
		$this->assertSame( [ 'bricks_seo_output', 'bricks_seo_stale_values', 'bricks_opengraph_output', 'bricks_opengraph_stale_values' ], array_keys( $issues ) );
		$this->assertSame( 'error', $issues['bricks_seo_stale_values']['severity'] );
		$this->assertSame(
			[
				'metaDescription' => 'Old Bricks description',
				'metaRobots'      => [ 'noindex', 'nofollow' ],
			],
			$issues['bricks_seo_stale_values']['staleValues']
		);
		$this->assertStringContainsString( 'noindex, nofollow', $issues['bricks_seo_stale_values']['message'] );
		$this->assertStringContainsString( 'disableSeo', $issues['bricks_seo_output']['fix'] );
		$this->assertSame( 'warning', $issues['bricks_opengraph_stale_values']['severity'] );
		$this->assertSame( [ 'sharingImage' => '{featured_image}' ], $issues['bricks_opengraph_stale_values']['staleValues'] );
		$this->assertStringContainsString( 'disableOpenGraph', $issues['bricks_opengraph_output']['fix'] );
		$this->assertSame( $read['audit']['warnings'], $write['warnings'] );

		update_post_meta(
			self::POST_ID,
			'_bricks_page_settings',
			[
				'sharingImage' => [
					'url'            => 'https://example.test/old-og.jpg',
					'useDynamicData' => ' ',
				],
			]
		);
		$blank_tag = array_column( $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] )['audit']['warnings'], null, 'type' );

		$this->assertSame( [ 'sharingImage' => 'https://example.test/old-og.jpg' ], $blank_tag['bricks_opengraph_stale_values']['staleValues'] );

		update_option( 'bricks_global_settings', [ 'disableSeo' => true ] );
		$seo_off = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		update_option(
			'bricks_global_settings',
			[
				'disableSeo'       => true,
				'disableOpenGraph' => true,
			]
		);
		$both_off = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( [ 'bricks_opengraph_output', 'bricks_opengraph_stale_values' ], array_column( $seo_off['audit']['warnings'], 'type' ) );
		$this->assertSame( [], $both_off['audit']['warnings'] );
		$this->assertSame( [ 'disableSeo' => true, 'disableOpenGraph' => true ], get_option( 'bricks_global_settings' ) );
	}

	/**
	 * The fix for a stale Bricks noindex drops only the blocking directives, so a nosnippet next to it is kept. Robots
	 * directives are case-insensitive, so a stored NOINDEX is an error too. A nofollow alone is an error that keeps the
	 * page indexed, so its message does not say crawlers may drop the page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_stale_bricks_robots_fix_keeps_other_directives(): void {
		eval( 'function seopress_init() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin function in this separate test process only.
		update_option( 'bricks_global_settings', [ 'disableOpenGraph' => true ] );
		update_post_meta(
			self::POST_ID,
			'_bricks_page_settings',
			[
				'metaRobots'      => [ 'noindex', 'nosnippet', 'none' ],
				'metaDescription' => 'Old Bricks description',
			]
		);
		$mixed = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] )['audit']['warnings'][1];

		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'noindex' ] ] );
		$blocking_only = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] )['audit']['warnings'][1];

		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'NOINDEX', 'nosnippet' ] ] );
		$upper = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] )['audit']['warnings'][1];

		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'nofollow' ] ] );
		$nofollow = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] )['audit']['warnings'][1];

		$this->assertSame( 'bricks_seo_stale_values', $mixed['type'] );
		$this->assertStringStartsWith( 'Clear metaDescription by sending each as null and send metaRobots as ["nosnippet"] with bricks/set-page-settings, which drops noindex, none and keeps the other robots directives, ', $mixed['fix'] );
		$this->assertStringStartsWith( 'Clear metaRobots with bricks/set-page-settings by sending each as null, ', $blocking_only['fix'] );
		$this->assertSame( 'error', $upper['severity'] );
		$this->assertStringContainsString( 'Its robots tag says NOINDEX whatever the SEO plugin says', $upper['message'] );
		$this->assertStringStartsWith( 'Send metaRobots as ["nosnippet"] with bricks/set-page-settings, which drops NOINDEX and keeps the other robots directives, ', $upper['fix'] );
		$this->assertStringContainsString( 'crawlers may drop the page', $mixed['message'] );
		$this->assertSame( 'error', $nofollow['severity'] );
		$this->assertSame( 'The Bricks page settings of this post still hold metaRobots, which Bricks prints next to SEOPress. Its robots tag says nofollow whatever the SEO plugin says, so crawlers may not follow the links on the page.', $nofollow['message'] );
	}

	/**
	 * A Bricks disable filter answers per front-end request, so its answer during an MCP request does not hide a live noindex.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_bricks_disable_filters_do_not_hide_stale_bricks_robots(): void {
		eval( 'final class RankMath {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		// Run registered filters from the global apply_filters(), as WordPress would, so qualified and unqualified calls both see them.
		$GLOBALS['_bricks_mcp_test_run_filters'] = true;
		// Like `! is_page( 42 )`: true outside the page request, false on the published page.
		add_filter( 'bricks/frontend/disable_seo', static fn(): bool => true );
		add_filter( 'bricks/frontend/disable_opengraph', static fn(): bool => true );
		update_post_meta( self::POST_ID, '_bricks_page_settings', [ 'metaRobots' => [ 'noindex' ] ] );

		$read = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( [ 'bricks_seo_output', 'bricks_seo_stale_values', 'bricks_opengraph_output' ], array_column( $read['audit']['warnings'], 'type' ) );
		$this->assertSame( 'error', $read['audit']['warnings'][1]['severity'] );
		$this->assertStringContainsString( 'bricks/frontend/disable_seo', $read['audit']['warnings'][0]['fix'] );
	}

	/**
	 * With two SEO plugins active the one bricks-mcp uses is named and the other is reported as still printing tags.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_second_active_seo_plugin_is_reported(): void {
		eval( 'namespace { ' . self::YOAST_FAKE . ' } namespace SlimSEO\MetaTags { final class Title {} }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares fake plugin classes in this separate test process only.
		update_option(
			'bricks_global_settings',
			[
				'disableSeo'       => true,
				'disableOpenGraph' => true,
			]
		);

		$read = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( 'yoast', $read['source'] );
		$this->assertSame( [ 'yoast', 'slimseo' ], $read['audit']['activeSeoPlugins'] );
		$this->assertSame( [ 'multiple_seo_plugins' ], array_column( $read['audit']['warnings'], 'type' ) );
		$this->assertSame( 'More than one SEO plugin is active (Yoast SEO, Slim SEO). bricks-mcp reads and writes Yoast SEO only; the others still print their own tags.', $read['audit']['warnings'][0]['message'] );
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
	 * Publishing or unpublishing a Bricks template needs builder access on it; pages do not.
	 *
	 * @return void
	 */
	public function test_template_status_change_needs_builder_access(): void {
		bricks_mcp_test_add_post(
			[
				'ID'          => 30,
				'post_type'   => 'bricks_template',
				'post_status' => 'draft',
			]
		);
		bricks_mcp_test_add_post(
			[
				'ID'          => 31,
				'post_type'   => 'bricks_template',
				'post_status' => 'publish',
			]
		);
		\Bricks\Capabilities::$can_use_builder = false;

		$publish   = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => 30,
				'status' => 'publish',
			]
		);
		$unpublish = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => 31,
				'status' => 'draft',
			]
		);
		$page      = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => self::POST_ID,
				'status' => 'publish',
			]
		);

		$this->assertSame( 'bricks_mcp_forbidden_builder', $publish->get_error_code() );
		$this->assertSame( 'bricks_mcp_forbidden_builder', $unpublish->get_error_code() );
		$this->assertSame( 'draft', get_post( 30 )->post_status );
		$this->assertSame( 'publish', get_post( 31 )->post_status );
		$this->assertSame( 'publish', $page['status'] );

		\Bricks\Capabilities::$can_use_builder = true;

		$allowed = $this->call(
			'bricks-mcp/set-post-status',
			[
				'postId' => 30,
				'status' => 'publish',
			]
		);

		$this->assertSame( 'publish', $allowed['status'] );
		$this->assertSame( 'publish', get_post( 30 )->post_status );
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
	 * Slim SEO noindex, Facebook image and Twitter image are read, set and cleared in its settings array.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_reads_sets_and_clears_noindex_and_images(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta(
			self::POST_ID,
			'slim_seo',
			[
				'title'          => 'Roofing',
				'noindex'        => 1,
				'facebook_image' => 'https://example.com/fb.jpg',
				'twitter_image'  => 'https://example.com/tw.jpg',
			]
		);

		$read = $this->call( 'bricks-mcp/get-seo', [ 'postId' => self::POST_ID ] );

		$this->assertSame( 'slimseo', $read['source'] );
		$this->assertEqualsCanonicalizing( [ 'title', 'description', 'canonical', 'noindex', 'ogImage', 'twitterImage' ], $read['supportedFields'] );
		$this->assertTrue( $read['fields']['noindex'] );
		$this->assertSame( 'https://example.com/fb.jpg', $read['fields']['ogImage'] );
		$this->assertSame( 'https://example.com/tw.jpg', $read['fields']['twitterImage'] );
		$this->assertTrue( $read['audit']['hasOgImage'] );

		$cleared = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'noindex'      => false,
					'ogImage'      => '',
					'twitterImage' => '',
				],
			]
		);

		$this->assertSame( [], $cleared['unsupported'] );
		$this->assertSame( [ 'title' => 'Roofing' ], get_post_meta( self::POST_ID, 'slim_seo', true ) );
		$this->assertFalse( $cleared['after']['noindex'] );

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'title'        => '',
					'noindex'      => true,
					'twitterImage' => 'https://example.com/new.jpg',
				],
			]
		);

		$this->assertSame(
			[
				'noindex'       => 1,
				'twitter_image' => 'https://example.com/new.jpg',
			],
			get_post_meta( self::POST_ID, 'slim_seo', true )
		);

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'noindex'      => false,
					'twitterImage' => '',
				],
			]
		);

		$this->assertSame( '', get_post_meta( self::POST_ID, 'slim_seo', true ) );
	}

	/**
	 * Slim SEO records an editor saves while a clear empties the record, and the clear's fields.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string|bool>, 3: bool}>
	 */
	public static function slim_seo_saves_during_a_clear(): array {
		return [
			'a new image'                     => [ [ 'noindex' => 1 ], [ 'noindex' => 1, 'facebook_image' => 'https://example.com/fb.jpg' ], [ 'noindex' => false ], false ],
			'an image that only changes case' => [ [ 'facebook_image' => 'https://example.com/Hero.jpg' ], [ 'facebook_image' => 'https://example.com/hero.jpg' ], [ 'ogImage' => '' ], false ],
			'an image that only changes case, in SQL without spaces inside parentheses' => [ [ 'facebook_image' => 'https://example.com/Hero.jpg' ], [ 'facebook_image' => 'https://example.com/hero.jpg' ], [ 'ogImage' => '' ], true ],
		];
	}

	/**
	 * Clearing the last Slim SEO field keeps a record saved after the clear read it, up to the delete itself, and fails instead of reporting the clear.
	 *
	 * @param array<string, mixed>       $record Record the clear reads.
	 * @param array<string, mixed>       $saved  Record the editor saves.
	 * @param array<string, string|bool> $seo    Fields the clear empties.
	 * @param bool                       $compact Whether the DELETE is sent without spaces inside its parentheses, as equivalent SQL.
	 * @return void
	 */
	#[DataProvider( 'slim_seo_saves_during_a_clear' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_slim_seo_clear_keeps_a_record_saved_after_it_was_read( array $record, array $saved, array $seo, bool $compact ): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, 'slim_seo', $record );
		// Slim SEO's editor saves the post after the clear has read and matched the row, just before the row is deleted.
		$GLOBALS['wpdb'] = new class( $saved, $compact ) extends \wpdb {
			/**
			 * @param array<string, mixed> $saved   Record the editor saves.
			 * @param bool                 $compact Whether to remove the spaces inside the query's parentheses.
			 */
			public function __construct( private array $saved, private bool $compact ) {}

			/**
			 * @param string $query Prepared query.
			 * @return int|false
			 */
			public function query( string $query ): int|false {
				return parent::query( $this->compact ? (string) preg_replace( [ '/\(\s+/', '/\s+\)/' ], [ '(', ')' ], $query ) : $query );
			}

			/**
			 * @param int         $post_id  Post ID.
			 * @param string      $meta_key Meta key.
			 * @param string|null $value    Serialized value to match, or null for any.
			 * @param bool        $binary   Whether the value is compared byte for byte.
			 * @return int
			 */
			protected function delete_post_meta_row( int $post_id, string $meta_key, ?string $value, bool $binary ): int {
				update_metadata( 'post', $post_id, 'slim_seo', $this->saved );
				return parent::delete_post_meta_row( $post_id, $meta_key, $value, $binary );
			}
		};

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => $seo,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_seo_changed', $result->get_error_code() );
		$this->assertSame( $saved, get_post_meta( self::POST_ID, 'slim_seo', true ) );
	}

	/**
	 * Clearing the last Slim SEO field fails, instead of reporting it updated, when the database refuses the delete.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_clear_fails_when_the_delete_is_denied(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, 'slim_seo', [ 'noindex' => 1 ] );
		// Like wpdb when MySQL denies DELETE on the post meta table.
		$GLOBALS['wpdb'] = new class() extends \wpdb {
			/**
			 * @param string $query Prepared query.
			 * @return false
			 */
			public function query( string $query ): int|false {
				return false;
			}
		};

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'noindex' => false ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_seo_write_failed', $result->get_error_code() );
		$this->assertSame( [ 'noindex' => 1 ], get_post_meta( self::POST_ID, 'slim_seo', true ) );
	}

	/**
	 * Clearing the last Slim SEO fields deletes a record whose values hold a backslash.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_clear_deletes_a_record_with_a_backslash(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$GLOBALS['_bricks_mcp_test_post_meta'][ self::POST_ID ]['slim_seo'] = [
			'title'   => 'AC\\DC tribute',
			'noindex' => 1,
		];

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [
					'title'   => '',
					'noindex' => false,
				],
			]
		);

		$this->assertSame( '', get_post_meta( self::POST_ID, 'slim_seo', true ) );
	}

	/**
	 * Clearing the last Slim SEO field deletes an unchanged record with an accented letter from a latin1 column on a utf8mb4 connection.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_clear_deletes_an_accented_record_from_a_latin1_column(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$GLOBALS['wpdb']->meta_value_charset = 'latin1';
		update_post_meta( self::POST_ID, 'slim_seo', [ 'title' => 'Café' ] );

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'title' => '' ],
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', get_post_meta( self::POST_ID, 'slim_seo', true ) );
	}

	/**
	 * Clearing a Slim SEO field deletes a stored empty-string record, so a later unique insert of the meta is not blocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_clear_deletes_an_empty_string_record(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		update_post_meta( self::POST_ID, 'slim_seo', '' );

		$result = $this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'title' => '' ],
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( metadata_exists( 'post', self::POST_ID, 'slim_seo' ) );
	}

	/**
	 * Changing one Slim SEO field keeps the literal backslashes of the fields it leaves alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_write_keeps_backslashes_in_untouched_fields(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$GLOBALS['_bricks_mcp_test_post_meta'][ self::POST_ID ]['slim_seo'] = [ 'title' => 'C:\\Docs' ];

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => self::POST_ID,
				'seo'    => [ 'noindex' => true ],
			]
		);

		$this->assertSame(
			[
				'title'   => 'C:\\Docs',
				'noindex' => 1,
			],
			get_post_meta( self::POST_ID, 'slim_seo', true )
		);
	}

	/**
	 * Clearing Slim SEO fields on a revision changes the revision's own record, not its parent's.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_slim_seo_write_to_a_revision_keeps_the_parent_record(): void {
		eval( 'namespace SlimSEO\MetaTags; final class Title {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a fake plugin class in this separate test process only.
		$parent = [
			'title'   => 'Roofing',
			'noindex' => 1,
		];
		update_post_meta( self::POST_ID, 'slim_seo', $parent );
		bricks_mcp_test_add_post(
			[
				'ID'          => 30,
				'post_type'   => 'revision',
				'post_parent' => self::POST_ID,
			]
		);

		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => 30,
				'seo'    => [ 'noindex' => false ],
			]
		);
		$this->call(
			'bricks-mcp/update-seo',
			[
				'postId' => 30,
				'seo'    => [ 'twitterImage' => 'https://example.com/tw.jpg' ],
			]
		);

		$this->assertSame( $parent, get_post_meta( self::POST_ID, 'slim_seo', true ) );
		$this->assertSame( [ 'twitter_image' => 'https://example.com/tw.jpg' ], get_post_meta( 30, 'slim_seo', true ) );
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
