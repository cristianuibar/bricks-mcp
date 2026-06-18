=== Bricks MCP ===
Contributors: cristianuibar, optiwebopz
Tags: ai, bricks builder, mcp, artificial intelligence, page builder
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 1.5.1
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude to your Bricks Builder site. Build and edit pages using natural language — no clicking required.

== Description ==

Bricks MCP turns your WordPress site into an AI-controlled page builder. It implements the Model Context Protocol (MCP) — an open standard for connecting AI assistants to external tools — so that any MCP-compatible client (Claude Desktop, Claude Code, and others) can read and modify your Bricks Builder pages through plain conversation.

Tell your AI assistant "create a hero section with a headline and a call-to-action button" and it happens. No template hunting. No clicking through panels.

= How It Works =

The plugin registers a REST API endpoint on your WordPress site that speaks the MCP protocol. You add the endpoint URL to your AI client's MCP configuration, authenticate with a WordPress Application Password, and your AI can start working with your site immediately.

= Available Tools =

The MCP server exposes 11 canonical tools (call `tools/list` for schemas). Legacy granular tool names resolve via aliases — use canonical names below.

* **get_site_info** — Site metadata and connection diagnostics (action: diagnose)
* **get_builder_guide** — Built-in Bricks builder reference for AI context
* **bricks** — Bricks settings, element schemas, breakpoints, and global queries
* **content** — Pages and posts: list, search, CRUD, Bricks element trees, SEO (view: visual supported)
* **template** — Template library import, export, and management
* **design** — Global classes, color palettes, and typography
* **media** — Media library operations and Unsplash image search (API key optional)
* **menu** — WordPress navigation menus
* **component** — Reusable Bricks components
* **woocommerce** — WooCommerce Bricks page scaffolds
* **code** — Page custom CSS and scripts (dangerous_actions required for writes)

All tools are free to use. The plugin is open source and hosted on [GitHub](https://github.com/cristianuibar/bricks-mcp).

= Authentication =

All requests are authenticated using WordPress Application Passwords, the built-in authentication system available since WordPress 5.6. No third-party authentication service is involved.

= Requirements =

* WordPress 6.4 or later
* PHP 8.2 or later
* Bricks Builder theme 1.6 or later (required for Bricks-specific tools)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Settings > Bricks MCP** and enable the plugin.
3. Create a WordPress Application Password under **Users > Profile**.
4. Add the MCP server URL to your AI client configuration.
5. Start building pages with natural language.

Full setup documentation is available in the [GitHub repository](https://github.com/cristianuibar/bricks-mcp).

== External Services ==

This plugin optionally connects to the Unsplash API to search for images.

**Service:** Unsplash (api.unsplash.com)
**When used:** Only when the `media` tool image search action is called by an AI assistant, and only if you have configured an Unsplash API key in the plugin settings.
**What is sent:** Your search query string and your Unsplash API key.
**Unsplash Terms of Service:** https://unsplash.com/terms
**Unsplash Privacy Policy:** https://unsplash.com/privacy
**Unsplash API Guidelines:** https://unsplash.com/documentation

No data is sent to Unsplash unless you explicitly configure an API key and an AI assistant invokes the image search tool.

No other external services are contacted by this plugin.

== Installation ==

1. Upload the `bricks-mcp` folder to the `/wp-content/plugins/` directory, or install the plugin via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > Bricks MCP** to configure the plugin.
4. Enable the MCP server and optionally require authentication (strongly recommended for production sites).
5. Go to **Users > Your Profile** and scroll to **Application Passwords**. Create a new Application Password and copy it — you will need it for your AI client.
6. Add your site's MCP endpoint URL and credentials to your AI client (see the [GitHub repository](https://github.com/cristianuibar/bricks-mcp) for client-specific setup guides).
7. (Optional) Enter an Unsplash API key in the settings to enable image search.

== Frequently Asked Questions ==

= What is MCP (Model Context Protocol)? =

MCP is an open protocol created by Anthropic that gives AI assistants a standard way to connect to external tools and data sources. It works like a universal adapter: the AI client connects to an MCP server, discovers what tools are available, and calls them by name. This plugin implements that server for WordPress and Bricks Builder.

= Does this plugin work without Bricks Builder? =

Yes, partially. The `get_site_info` and `get_builder_guide` tools work on any WordPress site. The `content` tool supports read-only WordPress post listing on non-Bricks sites. Bricks-specific actions (`bricks`, `template`, `design`, `component`, `woocommerce`, `code`) require Bricks Builder to be installed and active.

= Which AI tools and clients are supported? =

Any MCP-compatible client can connect to this plugin. Verified clients include Claude Desktop and Claude Code. Because MCP is an open protocol, support for other clients is expected to grow over time.

= Is it safe to expose a REST API endpoint for AI access? =

Yes, when configured correctly. The plugin enforces WordPress Application Password authentication by default. Only users with the appropriate WordPress capabilities can use the tools. For extra security, you can restrict access by IP or role. Never disable authentication on a publicly accessible site.

== Screenshots ==

1. The Bricks MCP settings page under Settings > Bricks MCP.
2. Example Claude Desktop configuration connecting to the MCP server endpoint.
3. An AI assistant creating a Bricks Builder hero section from a plain-text prompt.

== Changelog ==

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
