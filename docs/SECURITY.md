# Security

This document describes the security model of Bricks MCP Extensions 2.0: how requests are authenticated, which permissions each ability needs, what the safety settings do and what the plugin does not do.

## Model

The plugin has no MCP server and no REST route of its own. It registers abilities with the WordPress Abilities API and marks them public. The official MCP Adapter plugin serves them, together with the Bricks abilities, on `/wp-json/mcp/mcp-adapter-default-server`. Every request goes through the WordPress REST API, so WordPress authentication and capability checks apply.

The plugin emits no CORS headers. AI clients connect server to server.

## Authentication

Authentication is handled by WordPress and the adapter, not by this plugin. Clients send a WordPress Application Password over HTTP Basic auth. Create one under Users > Profile > Application Passwords, or through Bricks > Settings > AI.

- Every ability runs as the authenticated user. It can do only what that user's capabilities allow.
- Application Passwords need HTTPS, or `WP_ENVIRONMENT_TYPE` set to `local`. Use HTTPS on production sites.
- Use a dedicated user with the lowest role that fits the work, and revoke the password when you stop using it.
- The older `require_auth` and `custom_base_url` settings no longer exist. There is no way to turn authentication off in this plugin.

## Permissions

Each ability has a `permission_callback`. Abilities that touch Bricks data mirror Bricks' own checks; plain WordPress operations use WordPress capabilities only. A failed check returns a `403` error.

| Abilities | Required |
|---|---|
| `get-seo`, `update-seo`, `set-featured-image` | `edit_post` for the post. Without an SEO plugin, `update-seo` writes through `bricks/set-page-settings`, which also checks Bricks builder access. |
| `set-post-status` | `edit_post` for the post. Publish, future and private also need the post type's publish capability. Bricks templates (`bricks_template`) also need Bricks builder access for that template, for every status. Annotated destructive, since it can take a live page or template offline. |
| `set-editor-mode`, `move-element`, `resolve-templates`, `diagnose-page`, `audit-page` | `edit_post` for the post, and Bricks builder access for that post |
| `create-global-class-category`, `delete-global-class-category` | `edit_posts` and the Bricks builder permission `edit_global_classes` (the check Bricks uses for global class edits) |
| `list-adobe-fonts`, `update-font-settings` | `manage_options` |
| `search-unsplash` | `upload_files` |
| `import-unsplash-photo` | `upload_files`. With `postId`, also `edit_post` for that post. |
| `get-guide` | `edit_posts` |

Writes to Bricks element data and page settings go through the Bricks abilities, so Bricks' own validation, revisions and undo history apply.

Administrators can switch any `bricks-mcp/*` ability off under Bricks > Bricks MCP Extensions. A disabled ability is not registered, so no client can call it. The Bricks abilities are managed in Bricks > Settings > AI.

## Rate limiting

A filter on `wp_pre_execute_ability` limits executions per user.

- It applies to every ability run over REST, including the Bricks abilities. It does not count abilities run from PHP, such as in WP-CLI or cron.
- Default 120 requests per minute per user. Set it under Bricks > Bricks MCP Extensions. `0` turns it off, otherwise 10 to 1000.
- Counting uses a 60-second window, kept in the object cache when one is configured, otherwise in one transient per user named `bricks_mcp_rl_{user_id}`.
- The limit is exact only with a persistent object cache, which increments the counter atomically. Without one, the transient is read and written back in two steps, so parallel requests can read the same count and a user can go over the limit by about the number of requests sent at the same time. Treat it as approximate on those sites.
- Requests without a logged-in user are not counted; the MCP Adapter rejects them before any ability runs.
- If the object cache drops a counter mid-window, the call is allowed and counting starts again.
- Calls that one ability makes to another count once.
- Over the limit, the call returns a `bricks_mcp_rate_limited` error with status `429` and `retry_after` in seconds. The message also states the wait, because the MCP Adapter passes only the message text to clients.

Long building sessions can make many calls. Raise the limit for trusted users if you hit it.

## CSS guard

A filter on `wp_ability_validate_input` scans ability inputs before permission checks and execution. Validation receives the input after every `wp_ability_normalize_input` filter, whatever its priority, so the guard sees schema defaults and values that normalize filters add. An earlier validation error is passed through. It is on by default and can be switched off under Bricks > Bricks MCP Extensions.

- It applies to every ability that writes, including the Bricks abilities. Abilities annotated read-only are skipped.
- It checks every string below the fields `customCss` and `_cssCustom` (including their `:breakpoint` and pseudo-class variants), `css`, `cssCode` and `stylesheet`, at any depth.
- It checks the `value` of `_attributes` entries named `style` (any case). A `value` without a `name` next to it is checked too.
- It checks the `value` of typed edit-plan operations whose `settingPath` (slash or dot separated, with or without a `/settings/` prefix) leads to one of those fields or into `_attributes`.
- It checks the `<style>` elements and `style` attributes in any other string, since most Bricks text fields accept HTML.
- It checks the plain-text SEO fields of `bricks-mcp/update-seo` (`title`, `description`, `ogTitle`, `ogDescription`, `twitterTitle`, `twitterDescription` and `focusKeyword`) after `sanitize_text_field()`, as they are stored, so copied markup is stripped instead of rejected.
- It rejects `url(javascript:)`, `data:` URLs other than images (`data:image/png`, `jpeg`, `gif`, `webp`, `avif` and `svg+xml` are allowed), `expression()`, `@import`, `behavior:`, `-moz-binding:` and a closing `</style` tag (any case). It removes comments (both as a browser reads them, outside strings and unquoted `url()`, and everywhere), decodes CSS escapes and strips tabs and line breaks inside URL schemes first, so `\75 rl(...)`, comment splitting and `ja\9 vascript:` do not bypass it.
- It also checks the raw CSS for a closing `</style` tag, because an HTML parser ends a style element there even inside a CSS comment.
- Remote `url()` values, such as `url(https://example.com/image.png)`, are allowed. Every page view that uses such a style requests the URL from that host, so review CSS that loads from third-party hosts.
- It rejects any single CSS value over 100 KB, and any value whose scan fails, for example on a low `pcre.backtrack_limit` (pattern `scan_error`).
- It rejects HTML with styles that it cannot check completely: a string over 1 MB, HTML that libxml stops parsing early (for example nesting deeper than 256 levels), or any HTML with styles when the PHP DOM extension is missing (status `500`).
- A rejection returns a `bricks_mcp_css_blocked` error with status `400`. The message names the input path and the matched pattern.

The guard does not check JavaScript. Bricks decides which abilities may write scripts and code. Review those under Bricks > Settings > AI before you give an agent access.

## Secrets

`bricks-mcp/list-adobe-fonts` reports only whether an Adobe Fonts project is configured, never the project ID. The Unsplash API key is read from the Bricks settings and sent only to `api.unsplash.com`.

## Data and uninstall

- Plugin settings live in the option `bricks_mcp_extensions_settings`.
- The option `bricks_mcp_settings` belongs to Bricks 2.5+. The plugin never writes it, except once on upgrade from 1.x, when it removes its own old keys and leaves the Bricks keys.
- Uninstalling deletes `bricks_mcp_extensions_settings`, `bricks_mcp_version`, `bricks_mcp_activated_at`, `bricks_mcp_reconnect_notice`, the transients `bricks_mcp_update_data`, `bricks_mcp_activation_checks` and the `bricks_mcp_rl_*` rate limit transients. It never touches `bricks_mcp_settings`.

## Updates

The plugin updates from GitHub Releases. The updater offers an update only for this plugin and checks the release ZIP against the SHA-256 checksum file published for it (`<zip name>.sha256`) before WordPress installs it, whether the update is manual or automatic. The check runs at the last download filter priority, so a ZIP that another plugin supplies for this plugin is checked the same way. It stays active while Bricks Builder is inactive. The cached checksum records the ZIP it was published for; cached release data without that link (from older plugin versions) is fetched again, and the update is refused if that fails. The checksum is tied to the release it was published with: a package from an older update offer that WordPress still has cached is refused until updates are checked again. The update offer WordPress caches points to the release ZIP behind a `bricks-mcp-verified+` prefix that WordPress cannot download, so only the verifier can fetch it; an offer that outlives the plugin's activation cannot be installed. Deactivating the plugin also removes its cached update offer. A release without a trusted ZIP and a valid checksum file is not offered as an update, and if the checksum is missing or cannot be fetched at install time, the update is refused. The checksum is published in the same release as the ZIP, so it detects a corrupted download, not a replaced release. The plugin does not turn on automatic updates; each site admin chooses that in WordPress.

## What this plugin does not do

- No own REST route, no stdio or WebSocket transport, no background process.
- No `eval()`, shell commands or `unserialize()` on user input.
- No raw SQL on user input. The only direct query deletes this plugin's own rate limit transients on uninstall.
- No file writes outside the media library. Unsplash imports use `bricks/upload-media`.
- No access without WordPress authentication. Ability permission checks need a logged-in user.
- No cross-site access. The plugin works on the single site it is installed on.
- No CORS headers.

## Reporting vulnerabilities

To report a security vulnerability, contact **cristi@buffup.media**. Share details privately before publishing. The maintainer will acknowledge the report within 48 hours and give a timeline for a fix.
