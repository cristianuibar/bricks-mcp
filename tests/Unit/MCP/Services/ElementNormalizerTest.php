<?php
/**
 * Element normalizer unit tests.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
			$text = strip_tags( $text );
			if ( $remove_breaks ) {
				$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
			}
			return $text;
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( string $data ): string {
			return strip_tags(
				$data,
				'<a><abbr><b><blockquote><br><code><del><em><i><li><ol><p><span><strong><sub><sup><ul>'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

namespace BricksMCP\Tests\Unit\MCP\Services {

	use BricksMCP\MCP\Services\ElementIdGenerator;
	use BricksMCP\MCP\Services\ElementNormalizer;
	use PHPUnit\Framework\TestCase;

	/**
	 * Tests for ElementNormalizer::sanitize_settings().
	 */
	final class ElementNormalizerTest extends TestCase {

		private ElementNormalizer $normalizer;

		protected function setUp(): void {
			parent::setUp();

			$this->normalizer = new ElementNormalizer( new ElementIdGenerator() );
		}

		public function test_sanitizes_term_id_whitelist_without_dropping_values(): void {
			$settings = [
				'filterTermInclude' => [ '155', '199', '201', '207' ],
			];

			$this->assertSame( $settings, $this->normalizer->sanitize_settings( $settings ) );
		}

		public function test_recursively_sanitizes_tax_query_clauses_and_nested_terms(): void {
			$sanitized = $this->normalizer->sanitize_settings(
				[
					'tax_query' => [
						[
							'taxonomy' => ' product_cat ',
							'field'    => ' term_id ',
							'terms'    => [ ' 155 ', '<em>199</em>' ],
							'operator' => 'IN',
						],
						[
							'taxonomy' => 'post_tag',
							'field'    => 'slug',
							'terms'    => [ 'featured', 'sale' ],
							'operator' => 'NOT IN',
						],
						'relation' => ' AND ',
					],
					'query'     => [ 'tax_query' => [ [ 'terms' => [ ' 201 ', '207' ] ] ] ],
			]
			);

			$this->assertSame(
				[
					'tax_query' => [
						[
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => [ '155', '199' ],
							'operator' => 'IN',
						],
						[
							'taxonomy' => 'post_tag',
							'field'    => 'slug',
							'terms'    => [ 'featured', 'sale' ],
							'operator' => 'NOT IN',
						],
						'relation' => 'AND',
					],
					'query'     => [ 'tax_query' => [ [ 'terms' => [ '201', '207' ] ] ] ],
				],
				$sanitized
			);
		}

		public function test_preserves_mixed_scalar_types_and_sparse_integer_keys(): void {
			$values = [
				0  => 42,
				2  => true,
				5  => null,
				8  => '<strong> clean </strong>',
				11 => false,
			];

			$this->assertSame(
				[ 'mixed' => [ 0 => 42, 2 => true, 5 => null, 8 => 'clean', 11 => false ] ],
				$this->normalizer->sanitize_settings( [ 'mixed' => $values ] )
			);
		}

		public function test_preserves_named_html_and_style_sanitization(): void {
			$this->assertSame(
				[
					'text'      => '<p>Hello <strong>world</strong></p>',
					'_color'    => [ 'hex' => '#ffffff', 'rgb' => 'rgb(1, 2, 3)' ],
					'customCss' => ".card > .title {\n\tcolor: red;\n}",
				],
				$this->normalizer->sanitize_settings(
					[
						'text'      => '<p>Hello <strong>world</strong></p>',
						'_color'    => [ 'hex' => '<b>#ffffff</b>', 'rgb' => 'rgb(1, 2, 3)' ],
						'customCss' => ".card > .title {\n\tcolor: red;\n}",
					]
				)
			);
		}
	}
}
