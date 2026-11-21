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
	 * Comments and the CSS tokens a comment cannot start inside: strings, unquoted url() (also with an escaped name) and identifiers with escapes.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '~/\*.*?(?:\*/|\z)'
		. '|"(?:[^"\\\\\r\n\f]|\\\\(?:\r\n|.))*+"?'
		. '|\'(?:[^\'\\\\\r\n\f]|\\\\(?:\r\n|.))*+\'?'
		. '|(?<![#@])(?:u|\\\\(?:0{0,4}[57]5(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|u))'
		. '(?:r|\\\\(?:0{0,4}[57]2(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|r))'
		. '(?:l|\\\\(?:0{0,4}[46]c(?:\r\n|[ \t\r\n\f]|(?![0-9a-f]))|l))'
		. '\([ \t\r\n\f]*+(?![\'"])(?:[^)\\\\]|\\\\.)*+\)?'
		. '|(?:[\w\x80-\xff-]|\\\\(?:[0-9a-f]{1,6}(?:\r\n|[ \t\r\n\f])?|[^\r\n\f0-9a-f]))++~is';

	/**
	 * Register the CSS guard filter.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_pre_execute_ability', [ $this, 'check' ], 10, 4 );
	}

	/**
	 * Validate CSS before an ability executes.
	 *
	 * @param mixed       $pre Existing filter result.
	 * @param string      $name Ability name.
	 * @param mixed       $input Raw ability input.
	 * @param \WP_Ability $ability Ability being executed.
	 * @return mixed
	 */
	public function check( mixed $pre, string $name, mixed $input, \WP_Ability $ability ): mixed {
		if ( ! $pre instanceof \WP_Filter_Sentinel || ! Options::get()['css_guard'] || true === ( $ability->get_meta()['annotations']['readonly'] ?? null ) ) {
			return $pre;
		}

		return $this->scan_input( $input ) ?? $pre;
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
	 * Scan CSS fields in a JSON-like input.
	 *
	 * @param mixed  $input Input value.
	 * @param string $parent_path Path to the parent value.
	 * @return \WP_Error|null
	 */
	private function scan_input( mixed $input, string $parent_path = '' ): ?\WP_Error {
		if ( ! is_array( $input ) && ! $input instanceof \stdClass ) {
			return null;
		}

		$input        = (array) $input;
		$setting_path = $input['settingPath'] ?? null;
		$css_value    = is_string( $setting_path ) && str_starts_with( $setting_path, '/settings/' )
			&& $this->is_css_key( substr( $setting_path, strrpos( $setting_path, '/' ) + 1 ) );

		foreach ( $input as $key => $value ) {
			$path = '' === $parent_path ? (string) $key : "$parent_path.$key";

			if ( is_string( $value ) && ( $this->is_css_key( (string) $key ) || ( 'value' === $key && $css_value ) ) ) {
				$error = $this->check_css( $value, $path );
			} elseif ( 'html' === $key && is_string( $value ) ) {
				$error = $this->scan_html( $value, $path );
			} else {
				$error = $this->scan_input( $value, $path );
			}

			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Check style elements and inline declarations from HTML import inputs.
	 *
	 * @param string $html Imported HTML.
	 * @param string $path Input path.
	 * @return \WP_Error|null
	 */
	private function scan_html( string $html, string $path ): ?\WP_Error {
		if ( false === stripos( $html, 'style' ) || ! class_exists( \DOMDocument::class ) ) {
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
