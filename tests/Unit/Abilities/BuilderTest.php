<?php
/**
 * Tests for Bricks builder extensions.
 *
 * @package BricksMCP
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use Bricks\Abilities\Design;
use Bricks\Abilities\Design_Option_Store;
use Bricks\Abilities\Save_Pipeline;
use BricksMCP\Abilities\Builder;
use BricksMCP\Safety\CssGuard;
use BricksMCP\Tests\Support\BuilderDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

use function BricksMCP\Tests\Support\make_safety_ability;

final class BuilderTest extends TestCase {

	private array $saved_elements;
	private int $save_count;

	protected function setUp(): void {
		parent::setUp();
		bricks_mcp_test_reset();
		bricks_mcp_test_add_post( [ 'ID' => 42 ] );

		foreach ( ( new Builder( BuilderDatabase::class ) )->abilities() as $name => $args ) {
			wp_register_ability( $name, $args );
		}

		$this->save_count     = 0;
		$this->saved_elements = [
			[ 'id' => 'origin', 'name' => 'div', 'parent' => 0, 'children' => [ 'firstt', 'second' ] ],
			[ 'id' => 'firstt', 'name' => 'div', 'parent' => 'origin', 'children' => [ 'deeper' ] ],
			[ 'id' => 'deeper', 'name' => 'text', 'parent' => 'firstt', 'children' => [], 'settings' => [ 'text' => 'Keep this' ] ],
			[ 'id' => 'second', 'name' => 'text', 'parent' => 'origin', 'children' => [] ],
			[ 'id' => 'target', 'name' => 'div', 'parent' => 0, 'children' => [ 'leafxx' ] ],
			[ 'id' => 'leafxx', 'name' => 'text', 'parent' => 'target', 'children' => [] ],
			[ 'id' => 'outsid', 'name' => 'div', 'parent' => 0, 'children' => [ 'lastex', 'firstx' ] ],
			[ 'id' => 'firstx', 'name' => 'text', 'parent' => 'outsid', 'children' => [] ],
			[ 'id' => 'lastex', 'name' => 'text', 'parent' => 'outsid', 'children' => [] ],
		];

		wp_register_ability(
			'bricks/get-page-elements',
			[
				'execute_callback' => fn( array $input ): array => [
					'postId'         => $input['postId'],
					'elements'       => $this->saved_elements,
					'documentDigest' => Save_Pipeline::document_digest( $this->saved_elements ),
				],
			]
		);

		wp_register_ability(
			'bricks/set-page-elements',
			[
				'input_schema'     => [ 'type' => 'object' ],
				'execute_callback' => function ( array $input ): array|WP_Error {
					++$this->save_count;
					$this->assertSame( 42, $input['postId'] );

					if ( ( $input['expectedDocumentDigest'] ?? '' ) !== Save_Pipeline::document_digest( $this->saved_elements ) ) {
						return new WP_Error( 'document_digest_mismatch', 'The document changed.', [ 'status' => 409 ] );
					}

					$order = [];
					foreach ( $input['elements'] as $element ) {
						$order[ $element['parent'] ][] = $element['id'];
					}

					$this->saved_elements = $input['elements'];
					foreach ( $this->saved_elements as $index => $element ) {
						$this->saved_elements[ $index ]['children'] = $order[ $element['id'] ] ?? [];
					}

					return [ 'revisionId' => 97 ];
				},
			]
		);
	}

	protected function tearDown(): void {
		Design::$can_edit_global_classes   = true;
		Design_Option_Store::$before_swap   = null;
		BuilderDatabase::$active_templates = [];
		BuilderDatabase::$header_position  = 'top';
		BuilderDatabase::$template_ids     = [];
		BuilderDatabase::$matches          = [];
		BuilderDatabase::$resolved         = [];
		BuilderDatabase::$query_context    = [];
		unset( $GLOBALS['_bricks_mcp_test_safety_filters'] );
		bricks_mcp_test_reset();
		parent::tearDown();
	}

	public function test_move_within_parent_survives_bricks_flat_order_normalization(): void {
		$result   = $this->move( 'firstt', 'origin', 1 );
		$elements = array_column( $this->saved_elements, null, 'id' );

		$this->assertSame(
			[
				'elementId'   => 'firstt',
				'oldParentId' => 'origin',
				'oldPosition' => 0,
				'newParentId' => 'origin',
				'newPosition' => 1,
				'revisionId'  => 97,
			],
			$result
		);
		$this->assertSame( [ 'second', 'firstt' ], $elements['origin']['children'] );
		$this->assertSame( [ 'lastex', 'firstx' ], $elements['outsid']['children'] );
	}

	public function test_move_to_another_parent_appends_and_keeps_the_subtree(): void {
		$result   = $this->move( 'firstt', 'target' );
		$elements = array_column( $this->saved_elements, null, 'id' );

		$this->assertSame( 'target', $result['newParentId'] );
		$this->assertSame( 1, $result['newPosition'] );
		$this->assertSame( [ 'second' ], $elements['origin']['children'] );
		$this->assertSame( [ 'leafxx', 'firstt' ], $elements['target']['children'] );
		$this->assertSame( 'target', $elements['firstt']['parent'] );
		$this->assertSame( [ 'deeper' ], $elements['firstt']['children'] );
		$this->assertSame( 'firstt', $elements['deeper']['parent'] );
		$this->assertSame( [ 'text' => 'Keep this' ], $elements['deeper']['settings'] );
	}

	public function test_move_saves_unrelated_stored_css_the_guard_blocks(): void {
		$this->saved_elements[3]['settings'] = [ 'text' => '<p style="behavior: url(example.htc)">Stored</p>' ];
		$GLOBALS['_bricks_mcp_test_safety_filters'] = [];
		( new CssGuard() )->init();
		$save = make_safety_ability( 'bricks/set-page-elements', wp_get_ability( 'bricks/set-page-elements' )->get_args() );
		$GLOBALS['_bricks_mcp_test_abilities']['bricks/set-page-elements'] = $save;

		$result   = $this->move( 'firstt', 'target' );
		$elements = array_column( $this->saved_elements, null, 'id' );

		$this->assertNotInstanceOf( WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'target', $result['newParentId'] );
		$this->assertSame( [ 'leafxx', 'firstt' ], $elements['target']['children'] );
		$this->assertSame( '<p style="behavior: url(example.htc)">Stored</p>', $elements['second']['settings']['text'] );

		$direct = $save->execute(
			[
				'postId'                 => 42,
				'elements'               => $this->saved_elements,
				'expectedDocumentDigest' => Save_Pipeline::document_digest( $this->saved_elements ),
			]
		);
		$this->assertInstanceOf( WP_Error::class, $direct );
		$this->assertSame( 'elements.3.settings.text.styleAttribute.0', $direct->get_error_data()['path'] );
		$this->assertSame( 1, $this->save_count );
	}

	public function test_move_to_another_parent_blocks_stored_css_in_the_moved_subtree(): void {
		$this->saved_elements[0]['settings'] = [ '_conditions' => [ [ [ 'key' => 'user_logged_in', 'value' => true ] ] ] ];
		$this->saved_elements[2]['settings'] = [ 'text' => '<style>@import "example.css";</style>Members only' ];
		$stored                              = $this->saved_elements;
		$GLOBALS['_bricks_mcp_test_safety_filters'] = [];
		( new CssGuard() )->init();
		$GLOBALS['_bricks_mcp_test_abilities']['bricks/set-page-elements'] = make_safety_ability( 'bricks/set-page-elements', wp_get_ability( 'bricks/set-page-elements' )->get_args() );

		$result = $this->move( 'firstt', 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'status' => 400, 'path' => 'elements.2.settings.text.style.0', 'pattern' => 'import' ], $result->get_error_data() );
		$this->assertSame( 0, $this->save_count );
		$this->assertSame( $stored, $this->saved_elements );
	}

	public function test_move_out_of_a_component_clears_the_slot_reference(): void {
		$this->saved_elements[0]['cid']          = 'cmpabc';
		$this->saved_elements[0]['slotChildren'] = [ 'slotta' => [ 'firstt', 'second' ] ];

		$result   = $this->move( 'firstt', 'target' );
		$elements = array_column( $this->saved_elements, null, 'id' );

		$this->assertSame( 'target', $result['newParentId'] );
		$this->assertSame( [ 'slotta' => [ 'second' ] ], $elements['origin']['slotChildren'] );
	}

	public static function root_parents(): array {
		return [
			'zero'         => [ 0 ],
			'empty string' => [ '' ],
		];
	}

	#[DataProvider( 'root_parents' )]
	public function test_move_to_root_at_a_sibling_position( int|string $root ): void {
		$result = $this->move( 'firstt', $root, 1 );
		$roots  = array_filter( $this->saved_elements, static fn( array $element ): bool => 0 === $element['parent'] );

		$this->assertSame( 0, $result['newParentId'] );
		$this->assertSame( 1, $result['newPosition'] );
		$this->assertSame( [ 'origin', 'firstt', 'target', 'outsid' ], array_column( $roots, 'id' ) );
		$this->assertSame( [ 'second' ], array_column( $this->saved_elements, null, 'id' )['origin']['children'] );
	}

	public function test_refuses_to_move_into_a_descendant(): void {
		$result = $this->move( 'origin', 'deeper' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_cyclic_move', $result->get_error_code() );
		$this->assertSame( 0, $this->save_count );
	}

	public function test_refuses_to_move_into_a_non_nestable_element(): void {
		$result = $this->move( 'firstt', 'second' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_parent_not_nestable', $result->get_error_code() );
		$this->assertSame( 0, $this->save_count );
	}

	public function test_move_passes_the_read_digest_and_preserves_a_concurrent_edit(): void {
		$snapshot = $this->saved_elements;
		$digest   = Save_Pipeline::document_digest( $snapshot );

		wp_register_ability(
			'bricks/get-page-elements',
			[
				'execute_callback' => function () use ( $snapshot, $digest ): array {
					$this->saved_elements[2]['settings']['text'] = 'Concurrent edit';

					return [
						'postId'         => 42,
						'elements'       => $snapshot,
						'documentDigest' => $digest,
					];
				},
			]
		);

		$result = $this->move( 'firstt', 'target' );
		$calls  = $GLOBALS['_bricks_mcp_test_ability_calls'];
		$save   = end( $calls );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'document_digest_mismatch', $result->get_error_code() );
		$this->assertSame( 'bricks/set-page-elements', $save[0] );
		$this->assertSame( $digest, $save[1]['expectedDocumentDigest'] );
		$this->assertSame( 'Concurrent edit', $this->saved_elements[2]['settings']['text'] );
	}

	public function test_refuses_to_write_a_redacted_tree(): void {
		$redacted                          = $this->saved_elements;
		$redacted[2]['settings']['text']   = '';
		$digest                            = Save_Pipeline::document_digest( $this->saved_elements );

		wp_register_ability(
			'bricks/get-page-elements',
			[
				'execute_callback' => static fn(): array => [
					'postId'         => 42,
					'elements'       => $redacted,
					'documentDigest' => $digest,
				],
			]
		);

		$result = $this->move( 'firstt', 'target' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_incomplete_tree', $result->get_error_code() );
		$this->assertSame( 0, $this->save_count );
	}

	public function test_category_creation_rejects_a_case_insensitive_duplicate(): void {
		update_option( 'bricks_global_classes_categories', [ [ 'id' => 'legacy', 'name' => 'Existing' ] ] );
		$ability   = wp_get_ability( 'bricks-mcp/create-global-class-category' );
		$category  = $ability->execute( [ 'name' => '  Șabloane  ' ] );
		$duplicate = $ability->execute( [ 'name' => 'șABLOANE' ] );

		$this->assertSame( 'Șabloane', $category['name'] );
		$this->assertMatchesRegularExpression( '/^[a-z]{6}$/', $category['id'] );
		$this->assertSame( [ [ 'id' => 'legacy', 'name' => 'Existing' ], $category ], get_option( 'bricks_global_classes_categories' ) );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'bricks_mcp_duplicate_category', $duplicate->get_error_code() );
	}

	public function test_category_creation_limits_the_name_length(): void {
		$stored = [ [ 'id' => 'legacy', 'name' => 'Existing' ] ];
		update_option( 'bricks_global_classes_categories', $stored );
		$ability = wp_get_ability( 'bricks-mcp/create-global-class-category' );

		$rejected = $ability->execute( [ 'name' => str_repeat( 'ș', 101 ) ] );
		$too_long = $ability->get_args()['execute_callback']( [ 'name' => str_repeat( 'ș', 101 ) ] );

		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'ability_invalid_input', $rejected->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertSame( 'bricks_mcp_category_name_too_long', $too_long->get_error_code() );
		$this->assertSame( 400, $too_long->get_error_data()['status'] );
		$this->assertSame( $stored, get_option( 'bricks_global_classes_categories' ) );

		$longest = $ability->execute( [ 'name' => str_repeat( 'ș', 100 ) ] );

		$this->assertSame( [ ...$stored, $longest ], get_option( 'bricks_global_classes_categories' ) );
	}

	public static function runner_encodings(): array {
		return [
			'another default_charset'     => [ '-d default_charset=ISO-8859-1' ],
			'UTF-8 internal_encoding ini' => [ '-d internal_encoding=UTF-8' ],
		];
	}

	#[DataProvider( 'runner_encodings' )]
	public function test_category_name_limit_holds_whatever_encoding_the_runner_ini_sets( string $ini ): void {
		// The bootstrap runs once per process, so use a fresh one; the fixture starts it in ISO-8859-1 whatever the runner's ini says.
		$output = [];
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . $ini . ' ' . escapeshellarg( __DIR__ . '/fixtures/category-name-boundary.php' ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame(
			[
				'result'     => str_repeat( 'ș', 100 ),
				'categories' => [ str_repeat( 'ș', 100 ) ],
			],
			json_decode( implode( "\n", $output ), true )
		);
	}

	public function test_category_creation_counts_name_length_in_utf8_under_another_internal_encoding(): void {
		// Core's schema check counts in the internal encoding (WordPress sets it to the blog charset), so call the callback directly.
		$create   = wp_get_ability( 'bricks-mcp/create-global-class-category' )->get_args()['execute_callback'];
		$encoding = mb_internal_encoding();
		mb_internal_encoding( 'ISO-8859-1' );
		try {
			$category = $create( [ 'name' => str_repeat( 'é', 100 ) ] );
		} finally {
			mb_internal_encoding( $encoding );
		}

		$this->assertSame( [ $category ], get_option( 'bricks_global_classes_categories' ) );
		$this->assertSame( str_repeat( 'é', 100 ), $category['name'] );
	}

	public function test_category_creation_skips_stored_names_that_are_not_strings(): void {
		$stored = [ [ 'id' => 'broken', 'name' => [ 'Layout' ] ], [ 'id' => 'number', 'name' => 7 ], [ 'id' => 'legacy', 'name' => 'Existing' ] ];
		update_option( 'bricks_global_classes_categories', $stored );
		$ability = wp_get_ability( 'bricks-mcp/create-global-class-category' );

		$duplicate = $ability->execute( [ 'name' => 'EXISTING' ] );
		$category  = $ability->execute( [ 'name' => 'Layout' ] );

		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'bricks_mcp_duplicate_category', $duplicate->get_error_code() );
		$this->assertSame( [ ...$stored, $category ], get_option( 'bricks_global_classes_categories' ) );
	}

	public function test_category_creation_skips_stored_names_that_are_not_valid_utf8(): void {
		$stored = [ [ 'id' => 'broken', 'name' => "Lay\xC3out" ], [ 'id' => 'legacy', 'name' => 'Existing' ] ];
		update_option( 'bricks_global_classes_categories', $stored );
		$ability = wp_get_ability( 'bricks-mcp/create-global-class-category' );

		$duplicate = $ability->execute( [ 'name' => 'EXISTING' ] );
		$category  = $ability->execute( [ 'name' => 'Layout' ] );

		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'bricks_mcp_duplicate_category', $duplicate->get_error_code() );
		$this->assertSame( 'Layout', $category['name'] );
		$this->assertSame( [ ...$stored, $category ], get_option( 'bricks_global_classes_categories' ) );
	}

	public static function parent_restrictions(): array {
		return [
			'only the runner\'s'    => [ [] ],
			'mb_send_mail disabled' => [ [ 'mb_send_mail' ] ],
			'passthru disabled'     => [ [ 'passthru' ] ],
		];
	}

	#[DataProvider( 'parent_restrictions' )]
	public function test_category_creation_works_without_mbstring( array $disabled ): void {
		// The launcher keeps this process's disabled functions and disables every mbstring one for the fixture, as on a host without it; the fixture defines only core's compat mb_strlen().
		// The fixture reports which of these it can still call, so a lost restriction shows even when -n already drops a shared mbstring.
		$disabled = implode( ',', [ ini_get( 'disable_functions' ), ...$disabled ] );
		$output   = [];
		$status   = 1;
		putenv( 'BRICKS_MCP_RESTRICTED=' . $disabled );
		try {
			exec( escapeshellarg( PHP_BINARY ) . ' -n -d ' . escapeshellarg( 'disable_functions=' . $disabled ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/run-without-mbstring.php' ) . ' 2>&1', $output, $status );
		} finally {
			putenv( 'BRICKS_MCP_RESTRICTED' );
		}

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame(
			[
				'mbstring'   => [],
				'restricted' => [],
				'results'    => [ 'bricks_mcp_duplicate_category', 'Layout' ],
				'categories' => [ bin2hex( "Lay\xC3out" ), bin2hex( 'Existing' ), bin2hex( 'Layout' ) ],
			],
			json_decode( implode( "\n", $output ), true )
		);
	}

	public function test_category_deletion_uncategorizes_its_classes(): void {
		$remaining = [ 'id' => 'otherc', 'name' => 'Other' ];
		$classes   = [
			[ 'id' => 'button', 'name' => 'button', 'category' => 'design', 'settings' => [ '_padding' => [ 'top' => '1rem' ] ] ],
			[ 'id' => 'layout', 'name' => 'layout', 'category' => 'otherc' ],
			[ 'id' => 'border', 'name' => 'border' ],
		];

		update_option( 'bricks_global_classes_categories', [ [ 'id' => 'design', 'name' => 'Design' ], $remaining ] );
		update_option( 'bricks_global_classes', $classes );

		$result = wp_get_ability( 'bricks-mcp/delete-global-class-category' )->execute( [ 'categoryId' => 'design' ] );

		unset( $classes[0]['category'] );
		$this->assertSame( [ 'categoryId' => 'design', 'uncategorizedCount' => 1 ], $result );
		$this->assertSame( [ $remaining ], get_option( 'bricks_global_classes_categories' ) );
		$this->assertSame( $classes, get_option( 'bricks_global_classes' ) );
		$this->assertSame( 2, get_option( Design::DESIGN_SYSTEM_VERSION_OPTION ) );
	}

	public function test_category_creation_replaces_a_non_array_stored_option(): void {
		update_option( 'bricks_global_classes_categories', '' );

		$result = wp_get_ability( 'bricks-mcp/create-global-class-category' )->execute( [ 'name' => 'Layout' ] );

		$this->assertIsArray( $result );
		$this->assertSame( [ $result ], get_option( 'bricks_global_classes_categories' ) );
		$this->assertSame( 1, get_option( Design::DESIGN_SYSTEM_VERSION_OPTION ) );
	}

	public function test_category_creation_preserves_a_concurrent_category(): void {
		$concurrent                      = [ [ 'id' => 'layout', 'name' => 'Layout' ] ];
		Design_Option_Store::$before_swap = static function () use ( $concurrent ): void {
			update_option( 'bricks_global_classes_categories', $concurrent );
		};

		$result = wp_get_ability( 'bricks-mcp/create-global-class-category' )->execute( [ 'name' => 'Design' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $concurrent, get_option( 'bricks_global_classes_categories' ) );
	}

	public function test_category_deletion_restores_classes_when_the_category_changes_during_save(): void {
		$classes    = [ [ 'id' => 'button', 'name' => 'button', 'category' => 'design' ] ];
		$concurrent = [ [ 'id' => 'design', 'name' => 'Renamed' ] ];
		update_option( 'bricks_global_classes_categories', [ [ 'id' => 'design', 'name' => 'Design' ] ] );
		update_option( 'bricks_global_classes', $classes );
		Design_Option_Store::$before_swap = static function ( string $option ) use ( $concurrent ): void {
			if ( 'bricks_global_classes_categories' === $option ) {
				update_option( $option, $concurrent );
			}
		};

		$result = wp_get_ability( 'bricks-mcp/delete-global-class-category' )->execute( [ 'categoryId' => 'design' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $concurrent, get_option( 'bricks_global_classes_categories' ) );
		$this->assertSame( $classes, get_option( 'bricks_global_classes' ) );
	}

	public function test_resolve_templates_returns_winners_and_eligible_alternates_and_restores_globals(): void {
		bricks_mcp_test_add_post( [ 'ID' => 61, 'post_type' => 'bricks_template' ], [ '_bricks_template_type' => 'content' ] );
		bricks_mcp_test_add_post( [ 'ID' => 62, 'post_type' => 'bricks_template' ], [ '_bricks_template_type' => 'content' ] );
		bricks_mcp_test_add_post( [ 'ID' => 63, 'post_type' => 'bricks_template' ], [ '_bricks_template_type' => 'password_protection' ] );
		BuilderDatabase::$resolved         = [ 'content_type' => '', 'header' => 51, 'content' => 61 ];
		BuilderDatabase::$template_ids     = [ 'header' => [ 51, 52, 53 ], 'body' => [ 61, 62, 63 ] ];
		BuilderDatabase::$matches          = [ 42 => [ 51, 52, 61, 62, 63 ] ];
		$original_templates                = [ 'post_id' => 19, 'content' => 11 ];
		BuilderDatabase::$active_templates = $original_templates;
		BuilderDatabase::$header_position  = 'bottom';

		$original_globals        = array_intersect_key( $GLOBALS, array_flip( [ 'wp_query', 'wp_the_query', 'post' ] ) );
		$query                   = new \WP_Query();
		$main_query              = new \WP_Query();
		$post                    = new \WP_Post( [ 'ID' => 19 ] );
		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $main_query;
		$GLOBALS['post']         = $post;
		try {
			$result = wp_get_ability( 'bricks-mcp/resolve-templates' )->execute( [ 'postId' => 42 ] );

			$this->assertIsArray( $result );
			$this->assertSame( [ 'activeIds' => [ 51 ], 'candidateIds' => [ 52 ] ], $result['slots']['header'] );
			$this->assertSame( [ 'activeIds' => [ 61 ], 'candidateIds' => [ 62 ] ], $result['slots']['content'] );
			$this->assertArrayNotHasKey( '', $result['slots'] );
			[ $temporary_query, $temporary_main_query, $temporary_post ] = BuilderDatabase::$query_context;
			$this->assertSame( $temporary_query, $temporary_main_query );
			$this->assertSame( 42, $temporary_query->queried_object_id );
			$this->assertSame( get_post( 42 ), $temporary_post );
			$this->assertSame( $query, $GLOBALS['wp_query'] );
			$this->assertSame( $main_query, $GLOBALS['wp_the_query'] );
			$this->assertSame( $post, $GLOBALS['post'] );
			$this->assertSame( $original_templates, BuilderDatabase::$active_templates );
			$this->assertSame( 'bottom', BuilderDatabase::$header_position );

			bricks_mcp_test_add_post( [ 'ID' => 64, 'post_type' => 'bricks_template' ], [ '_bricks_template_type' => 'password_protection' ] );
			get_post( 42 )->post_password     = 'required';
			BuilderDatabase::$resolved['content']             = 64;
			BuilderDatabase::$resolved['password_protection'] = 64;
			BuilderDatabase::$template_ids['body'][]          = 64;
			BuilderDatabase::$matches[42][]                    = 64;
			$result = wp_get_ability( 'bricks-mcp/resolve-templates' )->execute( [ 'postId' => 42 ] );
			$this->assertSame( [ 'activeIds' => [ 64 ], 'candidateIds' => [ 61, 62, 63 ] ], $result['slots']['content'] );
		} finally {
			unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'] );
			foreach ( $original_globals as $key => $value ) {
				$GLOBALS[ $key ] = $value;
			}
		}
	}

	public function test_resolve_templates_handles_a_post_removed_after_permission_check(): void {
		$ability = wp_get_ability( 'bricks-mcp/resolve-templates' );
		$input   = [ 'postId' => 42 ];
		$args    = $ability->get_args();
		$this->assertTrue( $args['permission_callback']( $input ) );
		unset( $GLOBALS['_bricks_mcp_test_posts'][42] );

		$result = $args['execute_callback']( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_move_denied_without_permission_to_edit_post(): void {
		$GLOBALS['_bricks_mcp_test_caps']['edit_post'] = false;

		$result = $this->move( 'firstt', 'target' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->save_count );
	}

	public function test_category_denied_without_bricks_permission_to_edit_classes(): void {
		Design::$can_edit_global_classes = false;

		$result = wp_get_ability( 'bricks-mcp/create-global-class-category' )->execute( [ 'name' => 'Layout' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( [], get_option( 'bricks_global_classes_categories', [] ) );
	}

	private function move( string $element_id, int|string $parent_id, ?int $position = null ): array|WP_Error {
		$input = [
			'postId'    => 42,
			'elementId' => $element_id,
			'parentId'  => $parent_id,
		];

		if ( null !== $position ) {
			$input['position'] = $position;
		}

		return wp_get_ability( 'bricks-mcp/move-element' )->execute( $input );
	}
}
