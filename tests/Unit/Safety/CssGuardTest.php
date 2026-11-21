<?php
/**
 * CSS guard behavior for ability inputs.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Safety;

use BricksMCP\Options;
use BricksMCP\Safety\CssGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;

use function BricksMCP\Tests\Support\make_safety_ability;

final class CssGuardTest extends TestCase {

	private WP_Ability $ability;

	protected function setUp(): void {
		bricks_mcp_test_reset();
		$GLOBALS['_bricks_mcp_test_safety_filters'] = [];
		( new CssGuard() )->init();
		$this->ability = make_safety_ability( 'bricks/update-page-settings', [ 'execute_callback' => static fn() => 'executed' ] );
	}

	protected function tearDown(): void {
		bricks_mcp_test_reset();
		unset( $GLOBALS['_bricks_mcp_test_safety_filters'] );
	}

	public static function disallowed_inputs(): array {
		return [
			'javascript URL with case, spacing, and hex escape' => [
				[ 'settings' => [ 'customCss' => '.x { background: URL ( " \\6a avaScript:example" ); }' ] ],
				'settings.customCss',
				'javascript_url',
			],
			'expression with comment'                          => [
				[ 'elements' => [ [ 'settings' => [ '_cssCustom' => '.x { color: expre/**/ssion (1); }' ] ] ] ],
				'elements.0.settings._cssCustom',
				'expression',
			],
			'import with a comment after the keyword'          => [
				(object) [ 'css' => '@IMPORT/**/url("example.css");' ],
				'css',
				'import',
			],
			'non-image data URL in a code element'             => [
				[ 'elements' => [ [ 'settings' => [ 'cssCode' => '.x { background: url( Data:text/html,example ); }' ] ] ] ],
				'elements.0.settings.cssCode',
				'data_url',
			],
			'IE behavior with a simple escape'                => [
				[ 'classes' => [ [ 'settings' => [ '_cssCustom:mobile_portrait' => '.x { behavio\\r: none; }' ] ] ] ],
				'classes.0.settings._cssCustom:mobile_portrait',
				'behaviour',
			],
			'breakpoint page CSS'                             => [
				[ 'settings' => [ 'customCss:mobile_portrait' => '@import "example.css";' ] ],
				'settings.customCss:mobile_portrait',
				'import',
			],
			'CSS in a typed edit operation'                    => [
				[ 'operations' => [ (object) [ 'op' => 'set-design-setting', 'settingPath' => '/settings/_cssCustom', 'value' => '@import "example.css";' ] ] ],
				'operations.0.value',
				'import',
			],
			'nested CSS in a typed edit operation'             => [
				[ 'operations' => [ [ 'op' => 'set-design-setting', 'settingPath' => '/settings/css/stylesheet', 'value' => '@import "example.css";' ] ] ],
				'operations.0.value',
				'import',
			],
			'URL scheme with an escaped tab'                   => [
				[ 'css' => 'background: url("ja\9 vascript:example");' ],
				'css',
				'javascript_url',
			],
			'Mozilla binding in a theme style'                 => [
				[ 'settings' => [ 'css' => [ 'stylesheet' => '.x {-MOZ-BINDING : none;}' ] ] ],
				'settings.css.stylesheet',
				'moz_binding',
			],
			'oversized nested value'                           => [
				[ 'header' => [ 'css' => str_repeat( ' ', 102401 ) ] ],
				'header.css',
				'size',
			],
			'embedded stylesheet'                              => [
				[ 'header' => [ 'html' => '<style>@import "example.css";</style><p>Example</p>' ] ],
				'header.html.style.0',
				'import',
			],
			'embedded inline CSS'                              => [
				[ 'html' => '<p style="background: url( data&#58;text/plain,example )">Example</p>' ],
				'html.styleAttribute.0',
				'data_url',
			],
		];
	}

	#[DataProvider( 'disallowed_inputs' )]
	public function test_blocks_disallowed_css_before_executing( mixed $input, string $path, string $pattern ): void {
		$result = $this->ability->execute( $input );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_css_blocked', $result->get_error_code() );
		self::assertSame( [ 'status' => 400, 'path' => $path, 'pattern' => $pattern ], $result->get_error_data() );
		$detail = [
			'javascript_url' => 'javascript:',
			'data_url'       => 'data:',
			'expression'     => 'expression()',
			'import'         => '@import',
			'behaviour'      => 'behavior',
			'moz_binding'    => '-moz-binding',
			'size'           => '100 KB',
		];
		self::assertStringContainsString( $path, $result->get_error_message() );
		self::assertStringContainsString( $detail[ $pattern ], $result->get_error_message() );
	}

	public function test_allows_inline_images_in_css(): void {
		foreach ( [ 'png', 'jpeg', 'jpg', 'gif', 'webp', 'avif', 'svg+xml' ] as $type ) {
			self::assertSame( 'executed', $this->ability->execute( [ 'css' => ".x { background: url('data:image/$type;base64,AAAA'); }" ] ), $type );
		}
	}

	public function test_skips_readonly_ability(): void {
		$ability = make_safety_ability(
			'bricks/preview-site-edit-plan',
			[
				'meta'             => [ 'annotations' => [ 'readonly' => true ] ],
				'execute_callback' => static fn() => 'previewed',
			]
		);

		self::assertSame( 'previewed', $ability->execute( [ 'css' => '@import "example.css";' ] ) );
	}

	public function test_allows_normal_css_at_the_size_limit_and_ignores_non_css_fields(): void {
		$input = [
			'css'      => str_pad( '.x { background: url("https://example.com/image.png"); scroll-behavior: smooth; }', 102400 ),
			'html'     => '<style>.x { color: red; }</style><p style="color: blue">Example</p>',
			'settings' => [ '_cssClasses' => '@import example', 'label' => 'expression()' ],
		];
		self::assertSame( 'executed', $this->ability->execute( $input ) );
	}

	public function test_allows_css_when_guard_is_disabled(): void {
		update_option( Options::OPTION, [ 'css_guard' => false ] );

		self::assertSame( 'executed', $this->ability->execute( [ 'css' => '@import "example.css";' ] ) );
	}

	public function test_preserves_an_earlier_short_circuit_including_null(): void {
		\BricksMCP\Safety\add_filter( 'wp_pre_execute_ability', static fn() => null, 9, 4 );

		self::assertNull( $this->ability->execute( [ 'css' => '@import "example.css";' ] ) );
	}
}
