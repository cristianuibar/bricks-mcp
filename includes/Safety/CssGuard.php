<?php
/**
 * CSS validation for WordPress ability inputs.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Safety;

use BricksMCP\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks disallowed CSS in ability inputs.
 */
final class CssGuard {

	/**
	 * Disallowed CSS patterns.
	 *
	 * @var array<string, array{string, string}>
	 */
	private const PATTERNS = [
		'javascript_url' => [ '/url\s*\(\s*["\']?\s*javascript\s*:/i', 'url(javascript:)' ],
		'expression'     => [ '/expression\s*\(/i', 'expression()' ],
		'import'         => [ '/@import(?![\w-])/i', '@import' ],
		'data_url'       => [ '~url\s*\(\s*["\']?\s*data\s*:(?!image/(?:png|jpe?g|gif|webp|avif|svg\+xml)[;,])~i', 'url(data:)' ],
		'behaviour'      => [ '/(?<![\w-])behaviou?r\s*:/i', 'behavior:' ],
		'moz_binding'    => [ '/-moz-binding\s*:/i', '-moz-binding:' ],
	];

	/**
	 * Comments and the CSS tokens a comment cannot start inside: strings, unquoted url() (also with an escaped name), the <!-- token and identifiers with escapes (NUL counts as U+FFFD, an identifier character).
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '~/\*.*?(?:\*/|\z)'
		. '|"(?:[^"\\\\\r\n\f]|\\\\(?:[0-9a-f]{1,6}(?:\r\n|[ \t\r\n\f])?|\r\n|.))*+"?'
		. '|\'(?:[^\'\\\\\r\n\f]|\\\\(?:[0-9a-f]{1,6}(?:\r\n|[ \t\r\n\f])?|\r\n|.))*+\'?'
		. '|(?<![#@])(?:u|\\\\(?:0{0,4}[57]5(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|u))'
		. '(?:r|\\\\(?:0{0,4}[57]2(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|r))'
		. '(?:l|\\\\(?:0{0,4}[46]c(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|l))'
		. '\([ \t\r\n\f]*+(?![\'"])(?:[^)\\\\]|\\\\.)*+\)?'
		. '|<!--'
		. '|(?:[\w\x00\x80-\xff-]|\\\\(?:[0-9a-f]{1,6}(?:\r\n|[ \t\r\n\f])?|[^\r\n\f0-9a-f]))++~is';

	/**
	 * Register the CSS guard filter.
	 *
	 * Runs last on the normalized input, so schema defaults and values added by other
	 * normalize filters are checked before validation, permission checks and execution.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_ability_normalize_input', [ $this, 'check' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Validate CSS in the normalized ability input.
	 *
	 * @param mixed       $input Normalized ability input, or an error from an earlier filter.
	 * @param string      $name Ability name.
	 * @param \WP_Ability $ability Ability being executed.
	 * @return mixed
	 */
	public function check( mixed $input, string $name, \WP_Ability $ability ): mixed {
		if ( is_wp_error( $input ) || ! Options::get()['css_guard'] || true === ( $ability->get_meta()['annotations']['readonly'] ?? null ) ) {
			return $input;
		}

		return $this->scan_input( $input ) ?? $input;
	}

	/**
	 * Identify a CSS setting by its leaf key.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_css_key( string $key ): bool {
		return in_array( $key, [ 'customCss', '_cssCustom', 'css', 'cssCode', 'stylesheet' ], true )
			|| str_starts_with( $key, 'customCss:' )
			|| str_starts_with( $key, '_cssCustom:' );
	}

	/**
	 * Get the scan mode of a child value.
	 *
	 * Everything below a CSS key is CSS. Entries of `_attributes` are attributes, and the
	 * value of an attribute is CSS unless the entry names an attribute other than `style`.
	 *
	 * @param string      $mode Scan mode of the parent value.
	 * @param string      $key Child key.
	 * @param string|null $attribute Attribute name when the parent is an attribute entry.
	 * @return string
	 */
	private function child_mode( string $mode, string $key, ?string $attribute = null ): string {
		return match ( true ) {
			'css' === $mode, $this->is_css_key( $key ) => 'css',
			'_attributes' === $key => 'attributes',
			'attributes' === $mode => 'attribute',
			'attribute' === $mode && 'value' === $key && ( null === $attribute || 'style' === strtolower( trim( $attribute ) ) ) => 'css',
			default => '',
		};
	}

	/**
	 * Get the scan mode of a typed edit value from its setting path.
	 *
	 * @param string $setting_path Slash or dot separated setting path.
	 * @return string
	 */
	private function setting_path_mode( string $setting_path ): string {
		$mode = '';
		foreach ( preg_split( '~[/.]~', $setting_path, -1, PREG_SPLIT_NO_EMPTY ) as $segment ) {
			$mode = $this->child_mode( $mode, str_replace( [ '~1', '~0' ], [ '/', '~' ], $segment ) );
		}

		return $mode;
	}

	/**
	 * Scan CSS and HTML strings in a JSON-like input.
	 *
	 * @param mixed  $input Input value.
	 * @param string $path Path to the value.
	 * @param string $mode Scan mode: `css`, `attributes`, `attribute` or empty.
	 * @return \WP_Error|null
	 */
	private function scan_input( mixed $input, string $path = '', string $mode = '' ): ?\WP_Error {
		if ( is_string( $input ) ) {
			return 'css' === $mode ? $this->check_css( $input, $path ) : $this->scan_html( $input, $path );
		}

		if ( ! is_array( $input ) && ! $input instanceof \stdClass ) {
			return null;
		}

		$input        = (array) $input;
		$attribute    = 'attribute' === $mode && is_string( $input['name'] ?? null ) ? $input['name'] : null;
		$setting_path = $input['settingPath'] ?? null;

		foreach ( $input as $key => $value ) {
			$key        = (string) $key;
			$child_mode = $this->child_mode( $mode, $key, $attribute );
			if ( '' === $child_mode && 'value' === $key && is_string( $setting_path ) ) {
				$child_mode = $this->setting_path_mode( $setting_path );
			}

			$error = $this->scan_input( $value, '' === $path ? $key : "$path.$key", $child_mode );
			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Check style elements and inline declarations in a string that may hold HTML.
	 *
	 * @param string $html String value.
	 * @param string $path Input path.
	 * @return \WP_Error|null
	 */
	private function scan_html( string $html, string $path ): ?\WP_Error {
		if ( ! str_contains( $html, '<' ) || false === stripos( $html, 'style' ) || ! class_exists( \DOMDocument::class ) ) {
			return null;
		}

		$document = new \DOMDocument();
		$wrapped  = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . trim( $html ) . '</body></html>';
		if ( ! $document->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
			return null;
		}

		$xpath = new \DOMXPath( $document );
		foreach ( [
			'style'          => '//style',
			'styleAttribute' => '//@style',
		] as $type => $query ) {
			foreach ( $xpath->query( $query ) as $index => $node ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property name.
				$error = $this->check_css( $node->textContent, "$path.$type.$index" );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		return null;
	}

	/**
	 * Check the original CSS size and the normalized CSS patterns.
	 *
	 * @param string $css Raw CSS.
	 * @param string $path Input path.
	 * @return \WP_Error|null
	 */
	private function check_css( string $css, string $path ): ?\WP_Error {
		if ( strlen( $css ) > 102400 ) {
			return new \WP_Error(
				'bricks_mcp_css_blocked',
				/* translators: %s: Input path. */
				sprintf( __( 'Blocked unsafe CSS at %s: CSS exceeds the 100 KB limit.', 'bricks-mcp' ), $path ),
				[
					'status'  => 400,
					'path'    => $path,
					'pattern' => 'size',
				]
			);
		}

		// Comments are removed as a browser reads them, and also by the plain pattern, which ignores strings.
		$comment_pattern = '~/\*.*?(?:\*/|\z)~s';
		$candidates      = [
			$this->strip_css_comments( $css, '' ),
			$this->strip_css_comments( $css, ' ' ),
			preg_replace( $comment_pattern, '', $css ) ?? $css,
			preg_replace( $comment_pattern, ' ', $css ) ?? $css,
		];

		foreach ( array_unique( $candidates ) as $candidate ) {
			$normalized     = $this->decode_css_escapes( $candidate );
			$url_normalized = str_replace( [ "\t", "\r", "\n" ], '', $normalized );

			foreach ( self::PATTERNS as $name => [ $pattern, $label ] ) {
				if ( preg_match( $pattern, str_ends_with( $name, '_url' ) ? $url_normalized : $normalized ) ) {
					return new \WP_Error(
						'bricks_mcp_css_blocked',
						/* translators: 1: Input path, 2: Disallowed CSS pattern. */
						sprintf( __( 'Blocked unsafe CSS at %1$s: %2$s is not allowed.', 'bricks-mcp' ), $path, $label ),
						[
							'status'  => 400,
							'path'    => $path,
							'pattern' => $name,
						]
					);
				}
			}
		}

		return null;
	}

	/**
	 * Replace comments outside strings, unquoted URLs and escapes.
	 *
	 * @param string $css Raw CSS.
	 * @param string $replacement Text that replaces each comment.
	 * @return string
	 */
	private function strip_css_comments( string $css, string $replacement ): string {
		return preg_replace_callback(
			self::TOKEN_PATTERN,
			static fn( array $token ): string => str_starts_with( $token[0], '/*' ) ? $replacement : $token[0],
			$css
		) ?? $css;
	}

	/**
	 * Decode escapes relevant to the ASCII patterns.
	 *
	 * @param string $css CSS without comments.
	 * @return string
	 */
	private function decode_css_escapes( string $css ): string {
		return preg_replace_callback(
			'~\\\\(?:([0-9a-f]{1,6})(?:\r\n|[ \t\r\n\f])?|(\r\n|[\r\n\f])|(.))~is',
			static function ( array $escape ): string {
				if ( ! empty( $escape[1] ) ) {
					$codepoint = hexdec( $escape[1] );
					return $codepoint < 128 ? chr( $codepoint ) : $escape[0];
				}
				return $escape[3] ?? '';
			},
			$css
		) ?? $css;
	}
}
