=== Bricks MCP Extensions ===
Contributors: cristianuibar, optiwebopz
Tags: ai, bricks builder, mcp, abilities api, seo
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 8.2
Requires Plugins: mcp-adapter
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds SEO, media, template and page-QA abilities to the AI abilities built into Bricks Builder. One MCP connection reaches both.

== Description ==

Bricks Builder 2.5 ships about 185 built-in abilities that let an AI assistant read and edit pages, elements, global classes, templates, media and settings. The official MCP Adapter plugin serves WordPress abilities to AI clients such as Claude Desktop and Claude Code.

Bricks MCP Extensions adds 16 abilities named `bricks-mcp/*` for what Bricks does not cover. It does not run its own MCP server. The adapter serves our abilities on the same endpoint as the Bricks abilities, so you connect your AI client once.

= What it adds =

* **SEO**: read and write titles, descriptions, noindex, canonical and Open Graph fields in Yoast SEO, Rank Math, SEOPress or Slim SEO, or in Bricks page settings when none is active.
* **Post status**: publish, schedule, draft or privatise posts, pages and Bricks templates.
* **Featured image and editor mode**: set the featured image, and switch a post between Bricks and the WordPress editor.
* **Builder**: move elements, see which templates apply to a post, create and delete global class categories.
* **Media and fonts**: search and import Unsplash photos, list Adobe Fonts, update Bricks font-loading settings.
* **Quality**: lint a page's element data, run a pre-publish audit prompt, read a guide to the abilities.

Every ability checks WordPress capabilities, and abilities that edit Bricks data also check Bricks builder access. A rate limiter and a CSS guard apply to every ability that runs over REST. Administrators can switch single abilities off under Bricks > Bricks MCP Extensions.

The plugin is open source and hosted on [GitHub](https://github.com/cristianuibar/bricks-mcp).

= Requirements =

* WordPress 7.1 or later
* PHP 8.2 or later
* Bricks Builder 2.5 or later
* The MCP Adapter plugin

= Getting Started =

1. Install and activate Bricks Builder 2.5 or later and the MCP Adapter plugin.
2. Install and activate this plugin.
3. Open **Bricks > Settings > AI** and follow the steps there: create an Application Password and copy the client config.
4. Paste the config into your AI client.

== External Services ==

This plugin contacts two external services.

**Unsplash (api.unsplash.com)**
When used: only when an AI assistant runs `bricks-mcp/search-unsplash` or `bricks-mcp/import-unsplash-photo`, and only if you saved an Unsplash API key in the Bricks settings.
What is sent: the search query or photo ID, and your Unsplash API key.
Terms: https://unsplash.com/terms
Privacy: https://unsplash.com/privacy
API guidelines: https://unsplash.com/documentation

**GitHub (api.github.com)**
When used: to check for plugin updates, from the Plugins screen, the update checks WordPress runs, and the Check Now button in Bricks > Bricks MCP Extensions.
What is sent: a request for the latest release of the `cristianuibar/bricks-mcp` repository. The plugin downloads the release ZIP from GitHub when you update.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

No other external services are contacted by this plugin.

== Installation ==

1. Install and activate Bricks Builder 2.5 or later.
2. Install and activate the MCP Adapter plugin from the Plugins screen.
3. Upload the `bricks-mcp` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen, then activate it.
4. Open **Bricks > Settings > AI**, create an Application Password and copy the client config into your AI client.
5. Open **Bricks > Bricks MCP Extensions** to switch abilities off, set the rate limit and CSS guard, and run diagnostics.

== Frequently Asked Questions ==

= Do I need two connections, one for Bricks and one for this plugin? =

No. The MCP Adapter serves the Bricks abilities and the `bricks-mcp/*` abilities on one endpoint, `/wp-json/mcp/mcp-adapter-default-server`. Connect your AI client once.

= Does this plugin work without Bricks Builder? =

No. It needs Bricks Builder 2.5 or later. Its abilities build on the Bricks abilities and the Bricks builder permissions.

= Does it work without the MCP Adapter? =

The abilities register with WordPress, but no AI client can reach them. Install the MCP Adapter plugin.

= Which AI clients are supported? =

Any MCP client that can connect to the adapter endpoint with an Application Password, including Claude Desktop and Claude Code.

= How is access controlled? =

Authentication uses WordPress Application Passwords, handled by the adapter. Each ability has its own permission check: post abilities need permission to edit the post and use the Bricks builder, font settings need `manage_options`, and media abilities need `upload_files`. The plugin adds no endpoint of its own.

= Is there a rate limit? =

Yes. The default is 120 requests per minute per user, for every ability run over REST, including the Bricks abilities. Set it to 0 under Bricks > Bricks MCP Extensions to turn it off.

= What happens to my 1.x setup? =

The old endpoint `/wp-json/bricks-mcp/v1/mcp` is removed. Reconnect your AI client once through Bricks > Settings > AI. The old tools are replaced by Bricks abilities. See the changelog.

= Does uninstalling delete Bricks settings? =

No. Uninstalling removes only this plugin's options and transients. It does not touch `bricks_mcp_settings`, which belongs to Bricks.

== Screenshots ==

1. The settings page under Bricks > Bricks MCP Extensions.

== Changelog ==

= 2.0.0 =
Breaking changes:
* The plugin no longer runs its own MCP server. It requires WordPress 7.1, Bricks Builder 2.5 and the MCP Adapter plugin.
* The endpoint `/wp-json/bricks-mcp/v1/mcp` is removed. Connect once through Bricks > Settings > AI to the adapter endpoint `/wp-json/mcp/mcp-adapter-default-server`.
* All 1.x tools are removed. Bricks abilities cover pages, elements, global classes, templates, media and menus. The plugin keeps only what Bricks lacks, as 16 `bricks-mcp/*` abilities.
* The settings `require_auth`, `custom_base_url` and dangerous actions are removed. Authentication is the adapter's Application Passwords.
* Settings moved to the option `bricks_mcp_extensions_settings`. The settings page is now Bricks > Bricks MCP Extensions.

New:
* 16 abilities: get-seo, update-seo, set-post-status, set-featured-image, set-editor-mode, move-element, resolve-templates, create-global-class-category, delete-global-class-category, search-unsplash, import-unsplash-photo, list-adobe-fonts, update-font-settings, diagnose-page, audit-page (prompt) and get-guide (resource).
* Per-ability toggles, a rate limit and a CSS guard that apply to every ability executed over REST.
* Admin notice after upgrading from 1.x, and a notice when the Bricks abilities are switched off.

Fixes:
* The plugin no longer reads or writes `bricks_mcp_settings`. Bricks 2.5 uses the same option name for its own AI settings and the two collided. Old keys are migrated out, Bricks keys are left alone.
* The GitHub updater no longer offers this plugin's ZIP to other plugins hosted on GitHub.
* SEO writes without an SEO plugin now go through `bricks/set-page-settings`, so Bricks validates them and keeps revisions.
* Font-loading settings are validated against the values Bricks accepts.

= 1.5.1 =
* Fix: save_elements() uses resolve_elements_meta_key() for header/footer templates.
* Fix: validate_with_opis() fails closed when Opis library throws.
* New: Hidden page_diagnose tool for pre-edit health checks (callable, not in tools/list).
* New: content tool view=visual returns condensed layout representation.
* Security: SSRF hardening for template import and media sideload paths.
* Docs: readme.txt and SECURITY.md aligned with 11 canonical MCP tools and dangerous-actions gating.

= 1.5.0 =
* Security: Fix SSRF in template import by enforcing wp_safe_remote_get (blocks internal network requests).
* Security: Fix SSRF in media sideload by validating URL scheme and blocking internal IPs.
* Security: Fix CSS injection by requiring dangerous_actions toggle for custom CSS writes.
* Security: Fix DOM-based XSS in diagnostic display via escHtml() helper.
* Security: Fix WP_Query parameter injection in get_posts tool — strict allowlist for query args.
* Security: Fix WP_User_Query parameter injection in get_users tool — strict allowlist for query args.
* Security: Fix schema validation failing open when Opis library is unavailable.
* Security: Hide user email and login from get_users by default, add opt-in include_pii argument.
* Security: Add MAX_BODY_SIZE constant (1 MB) and body size check before JSON decode.
* Security: Add batch size limit (max 20) for JSON-RPC requests.
* Security: Add SHA-256 checksum verification for auto-updates via upgrader_pre_download hook.
* Fix: Critical sanitization overhaul — _cssCustom multi-line CSS now preserved correctly, CSS child combinator (>) no longer encoded to &gt;, all style keys use CSS-safe sanitization.
* Fix: Background and text color in CSS import now store correct Bricks color object format.
* Fix: Border width/radius handled as CSS unit strings, not integers.
* Fix: Breakpoint map corrected to Bricks 2.x keys.
* Fix: Schema cache moved from transients to non-autoloaded wp_options (survives cache flushes).
* Fix: WooCommerce scaffold wrong method call, missing normalization, 18 incorrect element names.
* Fix: set_conditions private method access error in BricksService.
* Fix: Type mismatch in component:instantiate root-level placement.
* Fix: Type error in get_posts thumbnail cache priming.
* New: MCP auth discovery endpoint and WWW-Authenticate header for 401 responses.
* New: OAuth well-known endpoints return JSON 404 instead of HTML error pages.
* New: Rate limiter transient fallback for sites without persistent object cache.
* New: Rate limiting now covers unauthenticated requests.
* New: trigger_css_regeneration() for programmatic saves with External Files CSS mode.
* New: 20+ additional CSS properties mapped in import_classes_from_css.
* New: SchemaGenerator color, border, and typography schemas corrected for Bricks internal formats.
* New: SHA-256 checksum file generated alongside release ZIP.
* New: Unit test suite with stubs-based bootstrap (66 tests, 157 assertions).
* Removed: Dead Deactivator class and 17 unused $write_actions arrays.
* Compatibility: Verified against Bricks Builder 2.3.1 on PHP 8.2+.
* Props: @optiwebopz for the CSS sanitization contribution (PR #14).

= 1.4.0 =
* New: Connection diagnostics system — 9 automated checks detect what's blocking MCP API endpoints or App Passwords.
* New: Diagnostic panel on MCP Settings page replaces Test Connection button with richer output and fix instructions.
* New: WP Site Health integration — 3 Bricks MCP checks appear in Tools > Site Health.
* New: Plugin activation checks — lightweight PHP-only checks run on activate and surface issues as admin notices.
* New: MCP `get_site_info(action: 'diagnose')` returns structured JSON diagnostics for AI agents.
* New: Hosting provider detection (WP Engine, Kinsta, Flywheel, Cloudways, GoDaddy, SiteGround, Pantheon) with provider-specific fix instructions.
* New: Security plugin compatibility detection (Wordfence, iThemes, Sucuri, AIOS, WP Cerber, Perfmatters, Shield).
* New: Dependency-ordered check execution — checks skip automatically when prerequisites fail.
* New: Connection Troubleshooting section in Builder Guide for AI-assisted troubleshooting.

= 1.3.0 =
* New: Add MCP instructions field to initialize response to guide AI clients on available tools.
* New: Surface Bricks 2.3 builder settings (builderHtmlCssConverter, builderGlobalClassesImport) in get_settings.
* New: Add is_infobox flag to template list, get, and get_popup_settings responses.
* New: Enhance get_popup_schema with infobox_behavior block.
* Compatibility: Accept 'light' as canonical color param with 'hex' as alias in color_palette tool.
* Compatibility: Update Builder Guide with Bricks 2.3 CSS gotchas (_gap, _display, _widthMax corrections).
* Compatibility: Document Bricks 2.3 video element objectFit control in Builder Guide.
* Compatibility: Update wc_thankyou scaffold with Bricks 2.3 button styling controls.
* Compatibility: Document builderHtmlCssConverter and builderGlobalClassesImport in Builder Guide Key Gotchas.

= 1.2.1 =
* Compatibility: Document Bricks 2.3 toggle-mode element in Builder Guide, data model, and SchemaGenerator.
* Compatibility: Document Bricks 2.3 filter improvements in get_filter_schema.
* Compatibility: Document Bricks 2.3 Image Gallery load more and infinite scroll settings.
* Compatibility: Add loadMoreGallery interaction action for Bricks 2.3 Image Gallery.
* Compatibility: Document Bricks 2.3 perspective, scale3d, and parallax style properties.

= 1.2.0 =
* Security: Remove CORS wildcard headers and enforce per-user rate limiting.
* Security: Add `current_user_can()` authorization checks to tool execution and WordPress tool.
* Security: Validate tool arguments against inputSchema before handler dispatch.
* Security: Strip JS page settings keys on template import when dangerous actions disabled.
* New: RateLimiter class with atomic `wp_cache_incr` pattern replaces duplicated transient logic.
* New: ValidationService `validate_arguments()` for tool input schema validation.
* New: Rate limit RPM settings field on admin page.
* New: SECURITY.md documentation.
* Performance: Fix N+1 queries in post listing tools via cache priming.
* Accessibility: Add ARIA roles, id attributes, and aria-selected/tabindex to settings page.
* Accessibility: Add arrow-key tab navigation to admin updates UI.
* UI: Add settings save feedback via `settings_errors()`.
* UI: Extract inline styles from Settings.php into admin-settings.css.

= 1.1.5 =
* Fix: Plugins page now automatically detects new releases when local update cache expires, instead of requiring manual Check Now.

= 1.1.4 =
* Fix: Removed duplicate update notification on plugins page. WordPress core update notice now handles this alone.

= 1.1.3 =
* Fix: Manual setup configs showed base64-encoded placeholder that looked like real credentials. Now shows readable YOUR_BASE64_AUTH_STRING placeholder.
* Improved: Help text now explains Base64 encoding and points to Generate Setup Command button.

= 1.1.2 =
* Fix: Generated `claude mcp add` command had arguments in wrong order, causing "missing required argument name" error.

= 1.1.1 =
* Fix: Settings link in plugins list pointed to old URL after menu move, causing "not allowed to access this page" error.

= 1.1.0 =
* Move MCP settings page from WP Settings into Bricks admin menu as MCP submenu.
* Add quick setup UI with one-click app password generation and auto-fill.
* Add generate setup command JS handler for streamlined onboarding.

= 1.0.0 =
* Initial release.
* MCP server with REST API transport.
* Tools: 11 canonical MCP tools (get_site_info, get_builder_guide, bricks, content, template, design, media, menu, component, woocommerce, code).
* WordPress Application Password authentication.
* Admin settings page with enable/disable toggle, auth requirement, and rate limiting.
* Unsplash API integration for image search.

== Upgrade Notice ==

= 2.0.0 =
Breaking release. Requires WordPress 7.1, Bricks 2.5 and the MCP Adapter plugin. The old endpoint is removed: reconnect your AI client once through Bricks > Settings > AI.

= 1.5.1 =
Data integrity and diagnostics release: safer Bricks writes, page diagnostics, visual layout view, and documentation aligned with canonical MCP tools. Recommended for all users.

= 1.5.0 =
Major security hardening release: fixes SSRF, XSS, parameter injection, and CSS injection vulnerabilities. CSS sanitization overhaul for visual builder compatibility. MCP auth discovery endpoints. Recommended for all users.

= 1.3.0 =
MCP initialize instructions, infobox template support, Bricks 2.3 builder settings and CSS gotcha corrections, color param aliases.

= 1.2.1 =
Bricks 2.3 compatibility: toggle-mode element, filter improvements, Image Gallery load more/infinite scroll, perspective and parallax properties.

= 1.2.0 =
Security hardening, input validation, authorization checks, rate limiting overhaul, N+1 query fix, and accessibility improvements.

= 1.1.5 =
Plugins page now auto-detects new releases without manual Check Now.

= 1.1.4 =
Removes duplicate update notification on plugins page.

= 1.1.3 =
Manual setup configs now show readable placeholders instead of confusing base64-encoded strings.

= 1.1.2 =
Fixes generated setup command argument order.

= 1.1.1 =
Fixes settings link from plugins list after menu relocation.

= 1.1.0 =
Settings page moved to Bricks admin menu. New quick setup UI for easier onboarding.

= 1.0.0 =
Initial release.
