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
			'comment opener inside a quoted string'            => [
				[ 'css' => '.x { content: "/*"; background: url(javascript:example); } .y { content: "*/"; }' ],
				'css',
				'javascript_url',
			],
			'comment opener after an escaped quote'            => [
				[ 'css' => ".x { content: '\\'/*'; } @import 'example.css'; .y { content: '*/'; }" ],
				'css',
				'import',
			],
			'comment opener inside an unquoted URL'            => [
				[ 'css' => '.x { background: url(/*); background-image: url(data:text/html,example); } .y { background: url(*/); }' ],
				'css',
				'data_url',
			],
			'comment opener inside an escaped unquoted URL'    => [
				[ 'css' => '.x { background: \\75 rl(/*); behavior: url(example.htc); } .y { background: url(*/); }' ],
				'css',
				'behaviour',
			],
			'comment opener after a newline ending an escape'  => [
				[ 'css' => ".x { content: \"\\41\n/*\"; } @import 'example.css'; .y { content: '*/'; }" ],
				'css',
				'import',
			],
			'comment opener in a URL after a CDO token'        => [
				[ 'css' => '.x { background: <!--url(/*); behavior: url(example.htc); } .y { background: url(*/); }' ],
				'css',
				'behaviour',
			],
			'comment opener in a string after a NUL in a name' => [
				[ 'css' => ".x { background: x\0url(a \")/*\"); behavior: url(example.htc); } .y { content: \"*/\"; }" ],
				'css',
				'behaviour',
			],
			'style attribute in custom attributes'             => [
				[ 'elements' => [ [ 'settings' => [ '_attributes' => [ [ 'id' => 'a1', 'name' => ' Style ', 'value' => 'background: url(javascript:example)' ] ] ] ] ] ],
				'elements.0.settings._attributes.0.value',
				'javascript_url',
			],
			'string below a list under a CSS key'              => [
				[ 'settings' => [ '_cssCustom' => [ 'rules' => [ '.x {}', '@import "example.css";' ] ] ] ],
				'settings._cssCustom.rules.1',
				'import',
			],
			'inline CSS in a rich text field'                  => [
				[ 'elements' => [ [ 'settings' => [ 'text' => '<p style="behavior: url(example.htc)">Example</p>' ] ] ] ],
				'elements.0.settings.text.styleAttribute.0',
				'behaviour',
			],
			'stylesheet in another HTML field'                 => [
				[ 'content' => '<style>.x { color: expression(1); }</style>' ],
				'content.style.0',
				'expression',
			],
			'typed edit with a dotted setting path'            => [
				[ 'operations' => [ [ 'settingPath' => 'settings._cssCustom:hover', 'value' => '@import "example.css";' ] ] ],
				'operations.0.value',
				'import',
			],
			'typed edit with a bare setting key'               => [
				[ 'operations' => [ [ 'settingPath' => 'customCss', 'value' => '@import "example.css";' ] ] ],
				'operations.0.value',
				'import',
			],
			'typed edit of a custom attribute value'           => [
				[ 'operations' => [ [ 'settingPath' => '/settings/_attributes/0/value', 'value' => '-moz-binding: none' ] ] ],
				'operations.0.value',
				'moz_binding',
			],
			'typed edit of the custom attribute list'          => [
				[ 'operations' => [ [ 'settingPath' => '/settings/_attributes', 'value' => [ [ 'name' => 'style', 'value' => 'background: url(data:text/html,example)' ] ] ] ] ],
				'operations.0.value.0.value',
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

	public function test_allows_css_patterns_in_attributes_other_than_style(): void {
		$input = [
			'settings'   => [ '_attributes' => [ [ 'name' => 'data-style', 'value' => '@import example' ], [ 'name' => 'title', 'value' => 'expression()' ] ] ],
			'operations' => [ [ 'settingPath' => '/settings/_attributes/1', 'value' => [ 'name' => 'title', 'value' => 'behavior: none' ] ] ],
		];
		self::assertSame( 'executed', $this->ability->execute( $input ) );
	}

	public function test_blocks_css_added_during_input_normalization(): void {
		$ability = make_safety_ability(
			'bricks/update-page-settings',
			[
				'input_schema'     => [ 'default' => [ 'settings' => [ 'customCss' => '@import "example.css";' ] ] ],
				'execute_callback' => static fn() => 'executed',
			]
		);
		$result  = $ability->execute();
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 400, 'path' => 'settings.customCss', 'pattern' => 'import' ], $result->get_error_data() );

		\BricksMCP\Safety\add_filter( 'wp_ability_normalize_input', static fn( $input ) => [ 'css' => '.x { behavior: none; }' ] + $input, 10, 1 );
		$result = $this->ability->execute( [ 'title' => 'Example' ] );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 400, 'path' => 'css', 'pattern' => 'behaviour' ], $result->get_error_data() );
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
