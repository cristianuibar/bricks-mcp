<?php
/**
 * CSS validation for WordPress ability inputs.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Safety;

use BricksMCP\Abilities\Content;
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
		'style_close'    => [ '~</style~i', '</style>' ],
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
	 * Element keys that list children, which a move changes in the old and new parent.
	 *
	 * @var string[]
	 */
	private const CHILD_KEYS = [ 'children', 'slotChildren' ];

	/**
	 * Stored elements by post ID and element ID, without their child keys, while a move saves them.
	 *
	 * @var array<int, array<int|string, array<string, mixed>>>
	 */
	private static array $stored_elements = [];

	/**
	 * Run a save that changes only the structure of stored elements.
	 *
	 * While the callback runs, `bricks/set-page-elements` for the post skips elements that
	 * match a stored element apart from their child keys and whose ancestors all do too, so CSS
	 * already stored does not block the move. Changed and new elements, and every element whose
	 * ancestors changed, such as a moved subtree that may leave a members-only container, are
	 * still checked.
	 *
	 * @param int                              $post_id Post ID.
	 * @param array<int, array<string, mixed>> $elements Stored elements, verified against their document digest.
	 * @param callable                         $callback Save callback.
	 * @return mixed Callback result.
	 */
	public static function with_stored_elements( int $post_id, array $elements, callable $callback ): mixed {
		$previous = self::$stored_elements[ $post_id ] ?? null;
		$stored   = [];
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ( is_string( $element['id'] ?? null ) || is_int( $element['id'] ?? null ) ) ) {
				$stored[ $element['id'] ] = self::without_child_keys( $element );
			}
		}

		self::$stored_elements[ $post_id ] = $stored;
		try {
			return $callback();
		} finally {
			if ( null === $previous ) {
				unset( self::$stored_elements[ $post_id ] );
			} else {
				self::$stored_elements[ $post_id ] = $previous;
			}
		}
	}

	/**
	 * Register the CSS guard filter.
	 *
	 * Validation receives the input after every normalize filter, whatever its priority, so
	 * schema defaults and values added by those filters are checked before permission checks
	 * and execution.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'wp_ability_validate_input', [ $this, 'check' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Validate CSS in the normalized ability input.
	 *
	 * @param mixed  $is_valid Validation result so far.
	 * @param mixed  $input Normalized ability input.
	 * @param string $name Ability name.
	 * @return mixed Validation result, or the CSS error.
	 */
	public function check( mixed $is_valid, mixed $input, string $name ): mixed {
		// Core rejects only false and a WP_Error holding errors, so any other result is scanned.
		if ( false === $is_valid || ( is_wp_error( $is_valid ) && $is_valid->has_errors() ) || ! Options::get()['css_guard'] ) {
			return $is_valid;
		}

		$ability = wp_get_ability( $name );
		if ( null !== $ability && true === ( $ability->get_meta()['annotations']['readonly'] ?? null ) ) {
			return $is_valid;
		}

		$input = match ( $name ) {
			'bricks/set-page-elements' => $this->without_stored_elements( $input ),
			'bricks-mcp/update-seo'    => $this->with_sanitized_seo_text( $input ),
			default                    => $input,
		};

		return $this->scan_input( $input ) ?? $is_valid;
	}

	/**
	 * Replace the plain-text SEO fields with the text `bricks-mcp/update-seo` stores for them.
	 *
	 * The ability strips markup from these fields before storing them, so copied markup is
	 * checked as the text that remains. The ability itself still receives the raw input.
	 *
	 * @param mixed $input Normalized `bricks-mcp/update-seo` input.
	 * @return mixed
	 */
	private function with_sanitized_seo_text( mixed $input ): mixed {
		if ( ! is_array( $input ) || ! is_array( $input['seo'] ?? null ) ) {
			return $input;
		}

		foreach ( Content::SEO_TEXT_FIELDS as $field ) {
			if ( is_string( $input['seo'][ $field ] ?? null ) ) {
				$input['seo'][ $field ] = sanitize_text_field( $input['seo'][ $field ] );
			}
		}

		return $input;
	}

	/**
	 * Get an element without its child keys, with any root parent as 0.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array<string, mixed>
	 */
	private static function without_child_keys( array $element ): array {
		$element = array_diff_key( $element, array_flip( self::CHILD_KEYS ) );
		if ( in_array( $element['parent'] ?? 0, [ 0, '', '0' ], true ) ) {
			$element['parent'] = 0;
		}

		return $element;
	}

	/**
	 * Replace elements that match the stored ones in place apart from their child keys with null, keeping their indexes.
	 *
	 * @param mixed $input Normalized `bricks/set-page-elements` input.
	 * @return mixed
	 */
	private function without_stored_elements( mixed $input ): mixed {
		$stored = is_array( $input ) && is_int( $input['postId'] ?? null ) ? self::$stored_elements[ $input['postId'] ] ?? null : null;
		if ( null === $stored || ! is_array( $input['elements'] ?? null ) ) {
			return $input;
		}

		// An ID that appears more than once matches no stored element.
		$by_id = [];
		foreach ( $input['elements'] as $element ) {
			$id = is_array( $element ) ? $element['id'] ?? null : null;
			if ( is_string( $id ) || is_int( $id ) ) {
				$by_id[ $id ] = array_key_exists( $id, $by_id ) ? null : self::without_child_keys( $element );
			}
		}

		$in_place = [];
		foreach ( $input['elements'] as $index => $element ) {
			$id = is_array( $element ) ? $element['id'] ?? null : null;
			if ( ( is_string( $id ) || is_int( $id ) ) && $this->is_stored_in_place( $id, $by_id, $stored, $in_place ) ) {
				$input['elements'][ $index ] = null;
			}
		}

		return $input;
	}

	/**
	 * Check whether an element and all its ancestors match their stored elements, parents included.
	 *
	 * @param int|string                                   $id Element ID.
	 * @param array<int|string, array<string, mixed>|null> $by_id Input elements without child keys, null for a repeated ID.
	 * @param array<int|string, array<string, mixed>>      $stored Stored elements without child keys.
	 * @param array<int|string, bool>                      $in_place Results so far, by element ID.
	 * @return bool
	 */
	private function is_stored_in_place( int|string $id, array $by_id, array $stored, array &$in_place ): bool {
		if ( ! isset( $in_place[ $id ] ) ) {
			// Marked first, so a cyclic hierarchy reads as changed.
			$in_place[ $id ] = false;
			$parent          = $stored[ $id ]['parent'] ?? null;
			$in_place[ $id ] = isset( $by_id[ $id ], $stored[ $id ] ) && $by_id[ $id ] === $stored[ $id ]
				&& ( 0 === $parent || ( ( is_string( $parent ) || is_int( $parent ) ) && $this->is_stored_in_place( $parent, $by_id, $stored, $in_place ) ) );
		}

		return $in_place[ $id ];
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
		if ( ! $this->may_hold_style_markup( $html ) ) {
			return null;
		}

		if ( strlen( $html ) > 1048576 ) {
			return $this->unchecked_error( $path, 'html_size', __( 'HTML exceeds the 1 MB limit.', 'bricks-mcp' ) );
		}

		if ( ! class_exists( \DOMDocument::class ) ) {
			return $this->unchecked_error( $path, 'html_parser', __( 'the PHP DOM extension is missing, so styles in HTML cannot be checked.', 'bricks-mcp' ), 500 );
		}

		// libxml stops reading at a NUL byte without an error, so replace NUL like browsers do.
		$document = new \DOMDocument();
		$wrapped  = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . str_replace( "\0", "\u{FFFD}", trim( $html ) ) . '</body></html>';
		libxml_clear_errors();
		$loaded = $document->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		$error  = libxml_get_last_error();
		// A fatal error, such as nesting deeper than libxml allows, halts parsing and drops the rest.
		if ( ! $loaded || ( false !== $error && LIBXML_ERR_FATAL === $error->level ) ) {
			return $this->unchecked_error( $path, 'html_parse', __( 'the HTML could not be parsed completely.', 'bricks-mcp' ) );
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
	 * Check whether a string may hold a style element or attribute.
	 *
	 * Either needs a start tag holding a `style` name. libxml truncates names at 100 characters,
	 * so any character may come before that name. A start tag ends at its first `>` unless a
	 * quoted attribute value holds it, so a quote before that `>` extends the tag to the end.
	 * The string is searched in place, as it may be far larger than the HTML size limit.
	 *
	 * @param string $html String value.
	 * @return bool True also when the regex fails.
	 */
	private function may_hold_style_markup( string $html ): bool {
		$offset = 0;
		$style  = -1;
		while ( 1 === ( $found = preg_match( '~<[a-z_:.]~i', $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Loop over matches.
			$start  = $match[0][1];
			$end    = strpos( $html, '>', $start );
			$length = ( false === $end ? strlen( $html ) : $end + 1 ) - $start;
			if ( strcspn( $html, '"\'', $start, $length ) < $length ) {
				$end = false;
			}

			// The first style name from the tag start, kept until a later tag starts after it.
			if ( $style < $start ) {
				$style_found = preg_match( '~style(?![a-z0-9_:.-])~i', $html, $style_match, PREG_OFFSET_CAPTURE, $start );
				if ( 1 !== $style_found ) {
					return false === $style_found;
				}
				$style = $style_match[0][1];
			}

			if ( false === $end || $style < $end ) {
				return true;
			}
			$offset = $end + 1;
		}

		return false === $found;
	}

	/**
	 * Build the error for CSS or HTML styles the guard cannot check.
	 *
	 * @param string $path Input path.
	 * @param string $pattern Error pattern.
	 * @param string $reason Translated reason.
	 * @param int    $status HTTP status.
	 * @return \WP_Error
	 */
	private function unchecked_error( string $path, string $pattern, string $reason, int $status = 400 ): \WP_Error {
		return new \WP_Error(
			'bricks_mcp_css_blocked',
			/* translators: 1: Input path, 2: Reason. */
			sprintf( __( 'Blocked unsafe CSS at %1$s: %2$s', 'bricks-mcp' ), $path, $reason ),
			[
				'status'  => $status,
				'path'    => $path,
				'pattern' => $pattern,
			]
		);
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
		// A regex failure (null or false) blocks the value, since the text it left unchanged may hide a pattern.
		$comment_pattern = '~/\*.*?(?:\*/|\z)~s';
		$candidates      = array_map(
			fn( ?string $candidate ): ?string => null === $candidate ? null : $this->decode_css_escapes( $candidate ),
			[
				$this->strip_css_comments( $css, '' ),
				$this->strip_css_comments( $css, ' ' ),
				preg_replace( $comment_pattern, '', $css ),
				preg_replace( $comment_pattern, ' ', $css ),
			]
		);
		if ( in_array( null, $candidates, true ) ) {
			return $this->unchecked_error( $path, 'scan_error', __( 'the CSS could not be checked.', 'bricks-mcp' ) );
		}

		// An HTML parser ends a style element at a closing tag even inside a CSS comment, so the raw CSS is checked for one too.
		$scans   = array_map( static fn( string $candidate ): array => [ $candidate, self::PATTERNS ], array_unique( $candidates ) );
		$scans[] = [ $css, [ 'style_close' => self::PATTERNS['style_close'] ] ];
		foreach ( $scans as [ $normalized, $patterns ] ) {
			$url_normalized = str_replace( [ "\t", "\r", "\n" ], '', $normalized );

			foreach ( $patterns as $name => [ $pattern, $label ] ) {
				$matched = preg_match( $pattern, str_ends_with( $name, '_url' ) ? $url_normalized : $normalized );
				if ( false === $matched ) {
					return $this->unchecked_error( $path, 'scan_error', __( 'the CSS could not be checked.', 'bricks-mcp' ) );
				}
				if ( 1 === $matched ) {
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
	 * @return string|null Null when the regex fails.
	 */
	private function strip_css_comments( string $css, string $replacement ): ?string {
		return preg_replace_callback(
			self::TOKEN_PATTERN,
			static fn( array $token ): string => str_starts_with( $token[0], '/*' ) ? $replacement : $token[0],
			$css
		);
	}

	/**
	 * Decode escapes relevant to the ASCII patterns.
	 *
	 * @param string $css CSS without comments.
	 * @return string|null Null when the regex fails.
	 */
	private function decode_css_escapes( string $css ): ?string {
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
		);
	}
}
