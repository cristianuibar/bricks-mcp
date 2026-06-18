<?php
/**
 * Bricks MCP
 *
 * @package           BricksMCP
 * @author            Uibar Ion-Cristian
 * @copyright         2025 BUFF UP MEDIA S.R.L.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Bricks MCP
 * Plugin URI:        https://aiforbricks.com
 * Description:       AI-powered assistant for Bricks Builder. Control your website with natural language through MCP-compatible AI tools like Claude.
 * Version:           1.5.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Uibar Ion-Cristian
 * Author URI:        https://aiforbricks.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bricks-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/cristianuibar/bricks-mcp
 */

/**
 * Changelog:
 *
 * 1.5.2 - 2026-04-01 (Opti Webopz — unicornblogger.com)
 *   - Version bump after full MCP tool audit and security review.
 *   - Security audit confirmed: manage_options enforced on all REST routes,
 *     require_auth gate active, ABSPATH direct-access protection in place,
 *     RateLimiter active (Redis/transient fallback), no raw SQL in tools,
 *     sanitization present in Admin/Settings, nonce verified in admin forms.
 *   - Documented all 24 MCP tools inline for AI agent and developer reference.
 *   - No functional code changes in this patch.
 *
 * 1.5.1 - Original upstream release.
 *   - Initial stable release with 24 tools across pages, elements, templates,
 *     global classes/variables, theme styles, media, components, code
 *     injection, WooCommerce scaffolding, and diagnostics.
 */

/**
 * =============================================================================
 * BRICKS MCP — COMPLETE TOOL REFERENCE (24 tools)
 * =============================================================================
 *
 * All tools require: authenticated WP user with manage_options capability.
 * Transport: POST /wp-json/bricks-mcp/v1/mcp (Streamable HTTP / JSON-RPC).
 * Auth: WordPress Application Passwords (Authorization: Basic base64).
 * Rate limiting: RateLimiter class — Redis atomic or transient fallback.
 *
 * -----------------------------------------------------------------------------
 * GROUP 1 — PAGE MANAGEMENT  (tool: page)
 * -----------------------------------------------------------------------------
 *
 * page:list            List all Bricks-enabled pages/posts.
 *                      Params: post_type, status, posts_per_page, bricks_only.
 *
 * page:search          Full-text search across Bricks pages.
 *                      Params: search (required), post_type, posts_per_page.
 *
 * page:get             Read a page and its full element tree.
 *                      Params: post_id (required), view=visual|summary|detail.
 *                      NOTE: Always use view=visual before editing any page.
 *
 * page:create          Create a new page with optional elements.
 *                      Params: title (required), post_type, status, elements.
 *
 * page:update_content  Replace ALL elements on a page. DESTRUCTIVE.
 *                      Params: post_id (required), elements (required).
 *
 * page:update_meta     Update title, slug, or publish status.
 *                      Params: post_id (required), title, slug, status.
 *
 * page:delete          Move page to trash.
 *                      Params: post_id (required).
 *
 * page:duplicate       Clone a page with all its elements.
 *                      Params: post_id (required).
 *
 * page:get_settings    Read page-level Bricks settings.
 *                      Params: post_id (required).
 *
 * page:update_settings Write page-level Bricks settings.
 *                      Params: post_id (required), settings (required).
 *
 * page:get_seo         Read SEO meta with audit (Yoast/Rank Math aware).
 *                      Params: post_id (required).
 *
 * page:update_seo      Write SEO fields via active SEO plugin.
 *                      Params: post_id (required), title, description,
 *                              og_title, og_image, focus_keyword, etc.
 *
 * -----------------------------------------------------------------------------
 * GROUP 2 — ELEMENT OPERATIONS  (tool: element)
 * -----------------------------------------------------------------------------
 *
 * element:add          Insert a new element onto a page.
 *                      Params: post_id, name (required), parent_id,
 *                              position, settings.
 *
 * element:update       Merge new settings into an existing element.
 *                      Params: post_id, element_id, settings (required).
 *
 * element:remove       Delete an element and all its children. DESTRUCTIVE.
 *                      Params: post_id, element_id (required).
 *
 * element:move         Reorder or reparent an element.
 *                      Params: post_id, element_id, target_parent_id, position.
 *
 * element:bulk_update  Update up to 50 elements in a single call.
 *                      Params: post_id, updates[] = [{element_id, settings}].
 *
 * element:get_conditions  Read visibility conditions on an element.
 *                      Params: post_id, element_id (required).
 *
 * element:set_conditions  Set visibility conditions on an element.
 *                      Params: post_id, element_id, conditions (required).
 *
 * -----------------------------------------------------------------------------
 * GROUP 3 — TEMPLATES  (tool: template)
 * -----------------------------------------------------------------------------
 *
 * template:list        List templates — filterable by type/status/tag/bundle.
 * template:get         Read full element content of a template.
 * template:create      Create header/footer/popup/section/etc template.
 * template:update      Update title, status, or type.
 * template:delete      Trash a template.
 * template:duplicate   Clone a template.
 * template:export      Export template as portable JSON.
 * template:import      Import from JSON data object.
 * template:import_url  Import from remote URL returning Bricks JSON.
 * template:get_popup_settings  Read popup display rules.
 * template:set_popup_settings  Write popup display rules.
 *
 * CRITICAL: Headers/footers store elements in _bricks_page_header_2 /
 * _bricks_page_footer_2 — NEVER _bricks_page_content_2 (always empty).
 * Use novamira/execute-php + get_post_meta() for header/footer read/write.
 *
 * -----------------------------------------------------------------------------
 * GROUP 4 — TEMPLATE CONDITIONS  (tool: template_condition)
 * -----------------------------------------------------------------------------
 *
 * template_condition:get_types  All condition types with priority scores.
 * template_condition:set        Assign template to pages (empty = deactivate).
 * template_condition:resolve    Debug which template wins for a given post.
 *
 * -----------------------------------------------------------------------------
 * GROUP 5 — GLOBAL CLASSES  (tool: global_class)
 * -----------------------------------------------------------------------------
 *
 * global_class:list             List all classes (search, category filters).
 * global_class:create           Create new class with styles.
 * global_class:update           Merge or replace styles on a class.
 * global_class:delete           Delete a class sitewide.
 * global_class:apply            Assign class to element IDs on a page.
 * global_class:remove           Remove class from element IDs.
 * global_class:batch_create     Create multiple classes in one call.
 * global_class:batch_delete     Delete multiple classes in one call.
 * global_class:import_css       Parse raw CSS string into global classes.
 * global_class:export           Export all classes as JSON.
 * global_class:import_json      Import classes from JSON (additive only).
 * global_class:list_categories  List class category groupings.
 * global_class:create_category  Create a class category.
 * global_class:delete_category  Delete a class category.
 *
 * -----------------------------------------------------------------------------
 * GROUP 6 — GLOBAL VARIABLES  (tool: global_variable)
 * -----------------------------------------------------------------------------
 *
 * global_variable:list           List all CSS custom property tokens.
 * global_variable:search         Search by name or value substring.
 * global_variable:create         Create new variable.
 * global_variable:update         Update name or value.
 * global_variable:delete         Delete variable.
 * global_variable:batch_create   Create up to 50 variables at once.
 * global_variable:batch_delete   Delete up to 50 variables at once.
 * global_variable:create_category / update_category / delete_category.
 *
 * -----------------------------------------------------------------------------
 * GROUP 7 — THEME STYLES  (tool: theme_style)
 * -----------------------------------------------------------------------------
 *
 * theme_style:list     List all active theme styles.
 * theme_style:get      Get full style definition.
 * theme_style:create   Create style (typography, colors, spacing, buttons).
 * theme_style:update   Modify style (replace_section=true to fully swap group).
 * theme_style:delete   Remove/deactivate (hard_delete=false keeps record).
 *
 * -----------------------------------------------------------------------------
 * GROUP 8 — MEDIA  (tool: media)
 * -----------------------------------------------------------------------------
 *
 * media:search_unsplash   Search Unsplash stock photos (calls external API).
 * media:sideload          Download image URL into WP media library.
 * media:list              Browse media library with mime_type filter.
 * media:set_featured      Set post thumbnail by attachment ID.
 * media:remove_featured   Remove post thumbnail.
 * media:get_image_settings  Get Bricks-format image object for settings.
 *
 * -----------------------------------------------------------------------------
 * GROUP 9 — COMPONENTS  (tool: component)
 * -----------------------------------------------------------------------------
 *
 * component:list              List all reusable components.
 * component:get               Get full component definition.
 * component:create            Build component from flat element tree.
 * component:update            Update definition (propagates to all instances).
 * component:delete            Delete definition (instances render empty).
 * component:instantiate       Place a component instance on a page.
 * component:update_properties Set per-instance property values.
 * component:fill_slot         Inject content into a component slot.
 *
 * -----------------------------------------------------------------------------
 * GROUP 10 — CODE INJECTION  (tool: code)
 * -----------------------------------------------------------------------------
 *
 * code:get_page_css      Read current custom CSS for a page.
 * code:set_page_css      Write/replace page CSS (empty string clears).
 * code:get_page_scripts  Read custom head/body scripts for a page.
 * code:set_page_scripts  Write JS to head / body_header / body_footer.
 *                        REQUIRES: Dangerous Actions toggle ON in settings.
 *                        ALWAYS wrap JS in <script> tags on template posts.
 *
 * -----------------------------------------------------------------------------
 * GROUP 11 — WOOCOMMERCE  (tool: woocommerce)
 * -----------------------------------------------------------------------------
 *
 * woocommerce:status             Check WC version and Bricks WC settings.
 * woocommerce:get_elements       WC-specific element catalog by category.
 * woocommerce:get_dynamic_tags   Product/cart/order dynamic tag reference.
 * woocommerce:scaffold_template  Create one pre-populated WC template.
 * woocommerce:scaffold_store     Create all 8 WC templates at once.
 *
 * -----------------------------------------------------------------------------
 * GROUP 12 — DIAGNOSTICS
 * (tools: page_diagnose, page_spacing_audit, page_screenshot, page_visual_review)
 * -----------------------------------------------------------------------------
 *
 * page_diagnose        Pre-edit health check. Detects: missing <script> tags,
 *                      %root% in CSS, wrong keys (_maxWidth vs _widthMax),
 *                      plain hex colors, invalid breakpoints, orphaned
 *                      children, phantom content on header/footer, missing
 *                      global classes, integer border values, PHP without
 *                      executeCode enabled. Read-only.
 *
 * page_spacing_audit   Audits padding/margin rhythm across all layout elements.
 *                      Flags missing mobile overrides. Read-only.
 *
 * page_screenshot      Live screenshot via Microlink (50/day free) or ApiFlash
 *                      fallback (key in MCP Settings). Returns base64 + CDN
 *                      URL. Viewports: desktop, tablet, mobile.
 *                      Set fresh=true to bypass CDN cache.
 *
 * page_visual_review   Screenshot + Claude Vision AI critique. Requires
 *                      Anthropic API key in MCP Settings.
 *                      Focus areas: spacing, alignment, colors, all.
 *
 * -----------------------------------------------------------------------------
 * GROUP 13 — BRICKS SETTINGS & SCHEMA  (tool: bricks)
 * -----------------------------------------------------------------------------
 *
 * bricks:enable / disable         Toggle Bricks editor on a post.
 * bricks:get_settings             Read global Bricks settings by category.
 * bricks:get_breakpoints          Responsive breakpoint keys and formats.
 * bricks:get_element_schemas      Full schemas or catalog_only=true name list.
 * bricks:get_dynamic_tags         All dynamic data tags, filterable by group.
 * bricks:get_query_types          Query loop objectType and settings keys.
 * bricks:get_form_schema          Form field types and action reference.
 * bricks:get_interaction_schema   Triggers, actions, Animate.css types.
 * bricks:get_component_schema     Component property types and slot mechanics.
 * bricks:get_popup_schema         Popup display settings reference.
 * bricks:get_filter_schema        Query filter element types and workflow.
 * bricks:get_condition_schema     Element visibility condition types.
 * bricks:get_global_queries       List reusable global query definitions.
 * bricks:set_global_query         Create or update a global query.
 * bricks:delete_global_query      Delete a global query by ID.
 *
 * -----------------------------------------------------------------------------
 * GROUP 14 — TEMPLATE TAXONOMY  (tool: template_taxonomy)
 * -----------------------------------------------------------------------------
 *
 * template_taxonomy:list_tags / list_bundles
 * template_taxonomy:create_tag / create_bundle
 * template_taxonomy:delete_tag / delete_bundle
 *
 * -----------------------------------------------------------------------------
 * GROUP 15 — WORDPRESS DATA  (tool: wordpress)
 * -----------------------------------------------------------------------------
 *
 * wordpress:get_posts    List posts by type, orderby, order, status.
 * wordpress:get_post     Single post by ID.
 * wordpress:get_users    List users by role.
 * wordpress:get_plugins  List all plugins with active/inactive status.
 *
 * -----------------------------------------------------------------------------
 * GROUP 16 — BUILDER GUIDE  (tool: get_builder_guide)
 * -----------------------------------------------------------------------------
 *
 * get_builder_guide    Full internal build reference. Sections:
 *                      all | settings | animations | interactions |
 *                      dynamic_data | forms | components | popups |
 *                      element_conditions | woocommerce | seo |
 *                      custom_code | fonts | import_export |
 *                      workflows | gotchas | connection_troubleshooting.
 *
 * =============================================================================
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version.
define( 'BRICKS_MCP_VERSION', '1.5.2' );

// Minimum PHP version.
define( 'BRICKS_MCP_MIN_PHP_VERSION', '8.2' );

// Minimum WordPress version.
define( 'BRICKS_MCP_MIN_WP_VERSION', '6.4' );

// Minimum Bricks Builder version.
define( 'BRICKS_MCP_MIN_BRICKS_VERSION', '1.6' );

// Plugin directory path.
define( 'BRICKS_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'BRICKS_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'BRICKS_MCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version is sufficient.
 */
function bricks_mcp_check_php_version(): bool {
	return version_compare( PHP_VERSION, BRICKS_MCP_MIN_PHP_VERSION, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version is sufficient.
 */
function bricks_mcp_check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, BRICKS_MCP_MIN_WP_VERSION, '>=' );
}

/**
 * Display admin notice for PHP version requirement.
 *
 * @return void
 */
function bricks_mcp_php_version_notice(): void {
	$message = sprintf(
		/* translators: 1: Required PHP version, 2: Current PHP version */
		esc_html__( 'Bricks MCP requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to use this plugin.', 'bricks-mcp' ),
		BRICKS_MCP_MIN_PHP_VERSION,
		PHP_VERSION
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Display admin notice for WordPress version requirement.
 *
 * @return void
 */
function bricks_mcp_wp_version_notice(): void {
	global $wp_version;
	$message = sprintf(
		/* translators: 1: Required WordPress version, 2: Current WordPress version */
		esc_html__( 'Bricks MCP requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress to use this plugin.', 'bricks-mcp' ),
		BRICKS_MCP_MIN_WP_VERSION,
		$wp_version
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Check Bricks Builder version requirement.
 *
 * @return bool True if Bricks is installed and version is sufficient.
 */
function bricks_mcp_check_bricks_version(): bool {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		return false;
	}
	if ( ! class_exists( '\Bricks\Elements' ) ) {
		return false;
	}
	return version_compare( BRICKS_VERSION, BRICKS_MCP_MIN_BRICKS_VERSION, '>=' );
}

/**
 * Display admin notice for Bricks Builder requirement.
 *
 * @return void
 */
function bricks_mcp_bricks_version_notice(): void {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		$message = sprintf(
			/* translators: %s: Required Bricks version */
			esc_html__( 'Bricks MCP requires Bricks Builder %s or higher. Bricks Builder is not installed or not activated.', 'bricks-mcp' ),
			BRICKS_MCP_MIN_BRICKS_VERSION
		);
	} else {
		$message = sprintf(
			/* translators: 1: Required Bricks version, 2: Current Bricks version */
			esc_html__( 'Bricks MCP requires Bricks Builder %1$s or higher. You are running Bricks %2$s. Please upgrade Bricks Builder to use this plugin.', 'bricks-mcp' ),
			BRICKS_MCP_MIN_BRICKS_VERSION,
			BRICKS_VERSION
		);
	}
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

// Check requirements before loading the plugin.
if ( ! bricks_mcp_check_php_version() ) {
	add_action( 'admin_notices', 'bricks_mcp_php_version_notice' );
	return;
}

if ( ! bricks_mcp_check_wp_version() ) {
	add_action( 'admin_notices', 'bricks_mcp_wp_version_notice' );
	return;
}

// Load the autoloader.
require_once BRICKS_MCP_PLUGIN_DIR . 'includes/Autoloader.php';

// Initialize autoloader.
BricksMCP\Autoloader::register();

// Load Composer autoloader if available (for Opis JSON Schema).
$bricks_mcp_composer_autoload = BRICKS_MCP_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $bricks_mcp_composer_autoload ) ) {
	require_once $bricks_mcp_composer_autoload;
}

/**
 * Run plugin activation routine.
 *
 * @return void
 */
function bricks_mcp_activate(): void {
	BricksMCP\Activator::activate();
}
register_activation_hook( __FILE__, 'bricks_mcp_activate' );

/**
 * Run plugin deactivation routine.
 *
 * @return void
 */
function bricks_mcp_deactivate(): void {
	BricksMCP\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'bricks_mcp_deactivate' );

/**
 * Initialize the plugin after theme is loaded (Bricks version available).
 *
 * @return void
 */
function bricks_mcp_init(): void {
	if ( ! bricks_mcp_check_bricks_version() ) {
		add_action( 'admin_notices', 'bricks_mcp_bricks_version_notice' );
		return;
	}
	BricksMCP\Plugin::get_instance();
}
add_action( 'after_setup_theme', 'bricks_mcp_init' );
