<?php
/**
 * CSS guard behavior for ability inputs.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Safety;

use BricksMCP\Abilities\Content;
use BricksMCP\Options;
use BricksMCP\Safety\CssGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;

use function BricksMCP\Tests\Support\make_safety_ability;

final class CssGuardTest extends TestCase {

	// Core validates input only against a declared schema, so fixtures declare a permissive one.
	private const SCHEMA = [ 'type' => 'object' ];

	// The `{ postId, seo }` shape of `bricks-mcp/update-seo` input.
	private const SEO_SCHEMA = [
		'type'       => 'object',
		'properties' => [
			'postId' => [ 'type' => 'integer' ],
			'seo'    => [ 'type' => 'object' ],
		],
	];

	private WP_Ability $ability;

	protected function setUp(): void {
		bricks_mcp_test_reset();
		$GLOBALS['_bricks_mcp_test_safety_filters'] = [];
		( new CssGuard() )->init();
		$this->ability = make_safety_ability( 'bricks/update-page-settings', [ 'input_schema' => self::SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
	}

	protected function tearDown(): void {
		bricks_mcp_test_reset();
		unset( $GLOBALS['_bricks_mcp_test_safety_filters'], $GLOBALS['_bricks_mcp_test_missing_classes'] );
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
			'stylesheet after plain text with a less-than sign' => [
				[ 'content' => 'Sale < 10 days <style>@import "example.css";</style>' ],
				'content.style.0',
				'import',
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
			'closing style tag in a string'                    => [
				[ 'settings' => [ 'customCss' => '.x::before { content: "</STYLE ><script>example()</script>"; }' ] ],
				'settings.customCss',
				'style_close',
			],
			'closing style tag in a comment'                   => [
				[ 'elements' => [ [ 'settings' => [ '_cssCustom' => '.x { color: red; } /* </style><script>example()</script> */' ] ] ] ],
				'elements.0.settings._cssCustom',
				'style_close',
			],
			'oversized HTML with styles'                       => [
				[ 'content' => '<p style="color: red">' . str_repeat( 'x', 1048576 ) . '</p>' ],
				'content',
				'html_size',
			],
			'stylesheet after nesting deeper than libxml parses' => [
				[ 'content' => str_repeat( '<div>', 300 ) . '<style>@import "example.css";</style>' ],
				'content',
				'html_parse',
			],
			'stylesheet after a NUL byte'                      => [
				[ 'content' => "<p>\0</p><style>@import \"example.css\";</style>" ],
				'content.style.0',
				'import',
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
			'style_close'    => '</style>',
			'size'           => '100 KB',
			'html_size'      => '1 MB',
			'html_parse'     => 'could not be parsed',
		];
		self::assertStringContainsString( $path, $result->get_error_message() );
		self::assertStringContainsString( $detail[ $pattern ], $result->get_error_message() );
	}

	public function test_blocks_css_when_the_scan_hits_a_regex_limit(): void {
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$result = $this->ability->execute( [ 'css' => '.x { content: "' . str_repeat( 'a', 5000 ) . '/*"; behavior: url(example.htc); } .y { content: "*/"; }' ] );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
		}

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'bricks_mcp_css_blocked', $result->get_error_code() );
		self::assertSame( [ 'status' => 400, 'path' => 'css', 'pattern' => 'scan_error' ], $result->get_error_data() );
		self::assertStringContainsString( 'could not be checked', $result->get_error_message() );
	}

	// A copy of the value exhausts the memory limit, which ends the process, so the test runs alone.
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_blocks_oversized_html_without_copying_it_when_memory_is_short(): void {
		$input = [ 'content' => 'Intro <p title="a" style="color: red">' . str_repeat( 'x', 4194304 ) . '</p>' ];
		$limit = ini_get( 'memory_limit' );
		self::assertNotFalse( ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 2097152 ) ) );
		try {
			$result = $this->ability->execute( $input );
		} finally {
			ini_set( 'memory_limit', $limit );
		}

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 400, 'path' => 'content', 'pattern' => 'html_size' ], $result->get_error_data() );
	}

	public function test_blocks_html_with_styles_when_the_dom_extension_is_missing(): void {
		$GLOBALS['_bricks_mcp_test_missing_classes'] = [ \DOMDocument::class ];

		$result = $this->ability->execute( [ 'content' => '<p style="color: red">Example</p>' ] );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 500, 'path' => 'content', 'pattern' => 'html_parser' ], $result->get_error_data() );
		self::assertStringContainsString( 'DOM extension', $result->get_error_message() );
		self::assertSame( 'executed', $this->ability->execute( [ 'content' => '<p>Example</p>', 'title' => 'style' ] ) );
	}

	public function test_blocks_style_markup_libxml_accepts_when_the_dom_extension_is_missing(): void {
		$GLOBALS['_bricks_mcp_test_missing_classes'] = [ \DOMDocument::class ];

		foreach ( [
			'<STYLE>.x { color: red; }</STYLE>',
			'<p title="1"style="color: red">Example</p>',
			'<p ' . str_repeat( 'a', 100 ) . 'style="color: red">Example</p>',
			'<' . str_repeat( 'a', 100 ) . 'style="color: red">Example',
			'Sale < 10 days <p style="color: red">Example</p>',
			'p<.05 and p>.05 <p style="color: red">Example</p>',
			'<p title=">" style="color: red">Example</p>',
			'<p a"b c=">" style="color: red">Example</p>',
		] as $html ) {
			$result = $this->ability->execute( [ 'content' => $html ] );
			self::assertInstanceOf( WP_Error::class, $result, $html );
			self::assertSame( [ 'status' => 500, 'path' => 'content', 'pattern' => 'html_parser' ], $result->get_error_data(), $html );
		}
	}

	public function test_allows_plain_text_with_style_and_less_than_signs(): void {
		$ability = make_safety_ability( 'bricks-mcp/update-seo', [ 'input_schema' => self::SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
		$inputs  = [
			[ 'title' => 'Summer styles < $50', 'description' => 'Find your style in < 10 minutes' ],
			[ 'title' => 'Find your style in < 10 minutes', 'description' => 'A lifestyle guide to styles that last.' ],
			[ 'title' => 'Sale < 10 days left', 'description' => 'Prices <$20 & <b>bold</b> picks' ],
			[ 'title' => 'Report p<.05 and p>.05 in APA style', 'description' => 'Use <b>bold</b> for a stylish title.' ],
		];

		foreach ( [ false, true ] as $dom_missing ) {
			$GLOBALS['_bricks_mcp_test_missing_classes'] = $dom_missing ? [ \DOMDocument::class ] : [];
			foreach ( $inputs as $input ) {
				self::assertSame( 'executed', $ability->execute( $input ), $input['title'] );
			}
		}
	}

	public function test_scans_seo_text_fields_as_the_plain_text_update_seo_stores(): void {
		$ability = make_safety_ability( 'bricks-mcp/update-seo', [ 'input_schema' => self::SEO_SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
		$markup  = [ '<span style="behavior: url(example.htc)">Short title</span>', '<span style="font-weight:bold">Short title</span>' ];

		foreach ( [ false, true ] as $dom_missing ) {
			$GLOBALS['_bricks_mcp_test_missing_classes'] = $dom_missing ? [ \DOMDocument::class ] : [];
			foreach ( [ 'title', 'description', 'ogTitle', 'ogDescription', 'twitterTitle', 'twitterDescription', 'focusKeyword' ] as $field ) {
				foreach ( $markup as $html ) {
					self::assertSame( 'executed', $ability->execute( [ 'postId' => 42, 'seo' => [ $field => $html ] ] ), "$field: $html" );
				}
			}
		}
	}

	public function test_blocks_style_markup_outside_the_seo_text_fields(): void {
		$seo      = make_safety_ability( 'bricks-mcp/update-seo', [ 'input_schema' => self::SEO_SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
		$settings = make_safety_ability( 'bricks/set-page-settings', [ 'input_schema' => self::SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
		$elements = make_safety_ability( 'bricks/set-page-elements', [ 'input_schema' => self::SCHEMA, 'execute_callback' => static fn() => 'executed' ] );
		$behavior = '<span style="behavior: url(example.htc)">Short title</span>';
		$cases    = [
			'canonical behavior'   => [ $seo, [ 'postId' => 42, 'seo' => [ 'canonical' => $behavior ] ] ],
			'canonical expression' => [ $seo, [ 'postId' => 42, 'seo' => [ 'title' => 'Short title', 'canonical' => '<span style="color: expression(1)">x</span>' ] ] ],
			'page settings'        => [ $settings, [ 'postId' => 42, 'settings' => [ 'documentTitle' => $behavior ] ] ],
			'page settings seo'    => [ $settings, [ 'postId' => 42, 'seo' => [ 'title' => $behavior ] ] ],
			'page elements'        => [ $elements, [ 'postId' => 42, 'elements' => [ [ 'id' => 'a', 'settings' => [ 'text' => $behavior ] ] ] ] ],
		];

		self::assertSame(
			[
				'canonical behavior'   => [ 'status' => 400, 'path' => 'seo.canonical.styleAttribute.0', 'pattern' => 'behaviour' ],
				'canonical expression' => [ 'status' => 400, 'path' => 'seo.canonical.styleAttribute.0', 'pattern' => 'expression' ],
				'page settings'        => [ 'status' => 400, 'path' => 'settings.documentTitle.styleAttribute.0', 'pattern' => 'behaviour' ],
				'page settings seo'    => [ 'status' => 400, 'path' => 'seo.title.styleAttribute.0', 'pattern' => 'behaviour' ],
				'page elements'        => [ 'status' => 400, 'path' => 'elements.0.settings.text.styleAttribute.0', 'pattern' => 'behaviour' ],
			],
			array_map(
				static function ( array $case ): mixed {
					$result = $case[0]->execute( $case[1] );
					return $result instanceof WP_Error ? $result->get_error_data() : $result;
				},
				$cases
			)
		);

		$GLOBALS['_bricks_mcp_test_missing_classes'] = [ \DOMDocument::class ];
		$result = $seo->execute( [ 'postId' => 42, 'seo' => [ 'canonical' => '<span style="font-weight:bold">x</span>' ] ] );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 500, 'path' => 'seo.canonical', 'pattern' => 'html_parser' ], $result->get_error_data() );
	}

	public function test_update_seo_receives_the_raw_title_and_stores_the_plain_text(): void {
		bricks_mcp_test_add_post( [ 'ID' => 42, 'post_type' => 'page' ] );
		\Bricks\Capabilities::$can_use_builder = true;
		wp_register_ability(
			'bricks/set-page-settings',
			[
				'execute_callback' => static function ( array $input ): array {
					update_post_meta( $input['postId'], '_bricks_page_settings', $input['settings'] );
					return [ 'postId' => $input['postId'], 'settings' => $input['settings'] ];
				},
			]
		);
		$args     = ( new Content() )->abilities()['bricks-mcp/update-seo'];
		$received = null;
		$execute  = $args['execute_callback'];

		$args['execute_callback'] = static function ( $input ) use ( $execute, &$received ) {
			$received = $input;
			return $execute( $input );
		};
		$input = [ 'postId' => 42, 'seo' => [ 'title' => '<span style="behavior: url(example.htc)">Short title</span>' ] ];

		$result = make_safety_ability( 'bricks-mcp/update-seo', $args )->execute( $input );

		self::assertIsArray( $result );
		self::assertSame( $input, $received );
		self::assertSame( [ 'documentTitle' => 'Short title' ], get_post_meta( 42, '_bricks_page_settings', true ) );
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
				'input_schema'     => self::SCHEMA,
				'meta'             => [ 'annotations' => [ 'readonly' => true ] ],
				'execute_callback' => static fn() => 'previewed',
			]
		);

		self::assertSame( 'previewed', $ability->execute( [ 'css' => '@import "example.css";' ] ) );
	}

	public function test_allows_normal_css_at_the_size_limit_and_ignores_non_css_fields(): void {
		$input = [
			'css'      => str_pad( '.x { background: url("https://example.com/image.png"); scroll-behavior: smooth; } .x > .y::before { content: "</p>"; }', 102400 ),
			'html'     => '<style>.x { color: red; }</style><p style="color: blue">Example</p>' . str_repeat( ' ', 1048576 - 67 ),
			'content'  => '<section><nav><p style="color: blue">Example</div></span>',
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

	public function test_blocks_css_a_later_last_priority_normalize_filter_adds(): void {
		\BricksMCP\Safety\add_filter( 'wp_ability_normalize_input', static fn( $input ) => [ 'settings' => [ 'customCss' => str_repeat( ' ', 102401 ) ] ] + $input, PHP_INT_MAX, 1 );

		$result = $this->ability->execute( [ 'postId' => 42 ] );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 400, 'path' => 'settings.customCss', 'pattern' => 'size' ], $result->get_error_data() );
	}

	public function test_input_without_a_schema_is_rejected_before_the_guard(): void {
		$ability = make_safety_ability( 'bricks/update-page-settings', [ 'execute_callback' => static fn() => 'executed' ] );
		\BricksMCP\Safety\add_filter( 'wp_ability_normalize_input', static fn( $input ) => [ 'settings' => [ 'customCss' => str_repeat( ' ', 102401 ) ] ] + $input, PHP_INT_MAX, 1 );

		$result = $ability->execute( [ 'postId' => 42 ] );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'ability_missing_input_schema', $result->get_error_code() );
	}

	public function test_scans_input_an_earlier_validate_filter_passes_with_an_empty_error(): void {
		\BricksMCP\Safety\add_filter( 'wp_ability_validate_input', static fn() => new WP_Error(), 10, 1 );

		$result = $this->ability->execute( [ 'settings' => [ 'customCss' => '@import "example.css";' ] ] );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( [ 'status' => 400, 'path' => 'settings.customCss', 'pattern' => 'import' ], $result->get_error_data() );
	}

	public function test_skips_only_stored_elements_unchanged_in_place_while_a_move_saves_them(): void {
		$save   = make_safety_ability( 'bricks/set-page-elements', [ 'input_schema' => self::SCHEMA, 'execute_callback' => static fn() => 'saved' ] );
		$stored = [
			[ 'id' => 'member', 'name' => 'div', 'parent' => 0, 'children' => [ 'wraper', 'otherx' ], 'settings' => [ '_conditions' => [ [ [ 'key' => 'user_logged_in', 'value' => true ] ] ] ] ],
			[ 'id' => 'wraper', 'name' => 'div', 'parent' => 'member', 'children' => [ 'styled' ] ],
			[ 'id' => 'styled', 'name' => 'text', 'parent' => 'wraper', 'children' => [], 'settings' => [ '_cssCustom' => '@import "example.css";' ] ],
			[ 'id' => 'otherx', 'name' => 'text', 'parent' => 'member', 'children' => [] ],
		];
		$saves  = [];

		$saves['reordered']                      = $stored;
		$saves['reordered'][0]['children']       = [ 'otherx', 'wraper' ];
		$saves['sibling moved']                  = $stored;
		$saves['sibling moved'][0]['children']   = [ 'wraper' ];
		$saves['sibling moved'][3]['parent']     = 0;
		$saves['changed']                        = $saves['sibling moved'];
		$saves['changed'][2]['settings']         = [ '_cssCustom' => '.x { behavior: none; }' ];
		$saves['added']                          = $saves['sibling moved'];
		$saves['added'][]                        = [ 'id' => 'addedx', 'name' => 'text', 'parent' => 0, 'settings' => [ 'css' => '@import "example.css";' ] ];
		$saves['moved to root']                  = $stored;
		$saves['moved to root'][1]['children']   = [];
		$saves['moved to root'][2]['parent']     = 0;
		$saves['ancestor moved']                 = $stored;
		$saves['ancestor moved'][0]['children']  = [ 'otherx' ];
		$saves['ancestor moved'][1]['parent']    = 0;
		$saves['ancestor changed']               = $stored;
		$saves['ancestor changed'][0]['settings'] = [];

		$results = CssGuard::with_stored_elements(
			42,
			$stored,
			static fn(): array => array_map( static fn( array $elements ) => $save->execute( [ 'postId' => 42, 'elements' => $elements ] ), $saves )
				+ [ 'other post' => $save->execute( [ 'postId' => 43, 'elements' => $saves['sibling moved'] ] ) ]
		);
		$results['after the move'] = $save->execute( [ 'postId' => 42, 'elements' => $saves['sibling moved'] ] );

		$import = [ 'status' => 400, 'path' => 'elements.2.settings._cssCustom', 'pattern' => 'import' ];
		self::assertSame(
			[
				'reordered'        => 'saved',
				'sibling moved'    => 'saved',
				'changed'          => [ 'status' => 400, 'path' => 'elements.2.settings._cssCustom', 'pattern' => 'behaviour' ],
				'added'            => [ 'status' => 400, 'path' => 'elements.4.settings.css', 'pattern' => 'import' ],
				'moved to root'    => $import,
				'ancestor moved'   => $import,
				'ancestor changed' => $import,
				'other post'       => $import,
				'after the move'   => $import,
			],
			array_map( static fn( mixed $result ): mixed => $result instanceof WP_Error ? $result->get_error_data() : $result, $results )
		);
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
