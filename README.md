# Bricks MCP Extensions

Extra abilities for the AI layer built into [Bricks Builder](https://bricksbuilder.io/) 2.5+, served through the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.

Bricks ships about 185 built-in `bricks/*` abilities for pages, elements, global classes, templates, media and settings. This plugin adds 16 `bricks-mcp/*` abilities for what Bricks does not cover: SEO, post status, featured images, editor mode, element moves, template resolution, global class categories, Unsplash, Adobe Fonts, font settings and page diagnostics.

The plugin has no MCP server of its own. The MCP Adapter exposes every public ability, ours and Bricks', on one endpoint, so you connect your AI client once:

```
https://your-site.com/wp-json/mcp/mcp-adapter-default-server
```

## Requirements

- WordPress 7.1+
- PHP 8.2+
- Bricks Builder 2.5+
- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin (`mcp-adapter`)

## Install

1. Install and activate Bricks Builder 2.5+.
2. Install and activate the MCP Adapter plugin.
3. Download the latest ZIP from [GitHub Releases](https://github.com/cristianuibar/bricks-mcp/releases), then use Plugins > Add New > Upload Plugin and activate it.

The plugin folder and slug stay `bricks-mcp`. New releases appear in the normal WordPress update screen. The updater reads GitHub Releases and verifies the ZIP's SHA-256 checksum before WordPress installs it.

## Connect your AI client

Connect once. The connection reaches the Bricks abilities and these ones.

1. Open Bricks > Settings > AI.
2. Check that the abilities API is enabled and the adapter is installed.
3. Create an Application Password for a user who can use the Bricks builder.
4. Copy the client config Bricks shows and paste it into your AI client.

Bricks > Bricks MCP Extensions shows the endpoint URL and whether the adapter is connected.

### Direct HTTP

Clients that support Streamable HTTP can connect to the endpoint without the npx bridge. Send the Application Password as a Basic auth header:

```
Authorization: Basic base64(username:application-password)
```

Claude Code:

```bash
claude mcp add --transport http bricks https://your-site.com/wp-json/mcp/mcp-adapter-default-server \
  --header "Authorization: Basic BASE64_OF_USERNAME_COLON_APP_PASSWORD"
```

Create the Base64 value with `printf '%s' 'username:xxxx xxxx xxxx xxxx xxxx xxxx' | base64 -w0`. Remove the spaces from the Application Password if your client rejects them.

Application Passwords need HTTPS on production sites. WordPress also allows them when `WP_ENVIRONMENT_TYPE` is `local`.

## Abilities

Every ability is registered with the WordPress Abilities API and marked public, so the adapter serves it. Names use camelCase inputs such as `postId` and `attachmentId`.

| Ability | What it does | Type |
|---|---|---|
| `bricks-mcp/get-seo` | Reads SEO fields from Yoast SEO, Rank Math, SEOPress or Slim SEO, or from Bricks page settings when none is active. | Read |
| `bricks-mcp/update-seo` | Writes SEO fields to the same sources. Fields the source cannot store are reported as unsupported. | Write |
| `bricks-mcp/set-post-status` | Sets publish, draft, pending, private or future (scheduled). Publishing Bricks templates needs this, since Bricks creates them as drafts. | Write |
| `bricks-mcp/set-featured-image` | Sets or removes the featured image with a media library attachment. | Write |
| `bricks-mcp/set-editor-mode` | Chooses whether Bricks or the WordPress editor renders a post. Element data is kept. | Write |
| `bricks-mcp/move-element` | Reorders or reparents an element and its subtree. | Write |
| `bricks-mcp/resolve-templates` | Shows which Bricks templates apply to a post. | Read |
| `bricks-mcp/create-global-class-category` | Creates a global class category. | Write |
| `bricks-mcp/delete-global-class-category` | Deletes a category and moves its classes to Uncategorized. | Write (destructive) |
| `bricks-mcp/search-unsplash` | Searches Unsplash with the API key saved in Bricks settings. | Read |
| `bricks-mcp/import-unsplash-photo` | Imports an Unsplash photo into the media library through `bricks/upload-media`, with attribution. | Write |
| `bricks-mcp/list-adobe-fonts` | Lists cached Adobe Fonts families and the Bricks font-loading settings. | Read |
| `bricks-mcp/update-font-settings` | Updates `disableGoogleFonts`, `webfontLoading`, `customFontsPreload` and `googleFontsFilesystem`. | Write |
| `bricks-mcp/diagnose-page` | Lints the stored element data of a page, header or footer and names the Bricks ability that fixes each problem. | Read |
| `bricks-mcp/audit-page` | MCP prompt that guides a pre-publish audit. Findings only. | Read (prompt) |
| `bricks-mcp/get-guide` | Markdown guide to these abilities, also an MCP resource at `bricks-mcp://guide`. | Read (resource) |

Writes to element data and page settings go through Bricks abilities, so Bricks keeps its validation, revisions and undo history.

### Permissions

Each ability has a `permission_callback` that mirrors Bricks:

- SEO, post status and featured image need `edit_post` for that post. The other post abilities also need Bricks builder access for it.
- Global class categories need `edit_posts` and the Bricks builder permission `edit_global_classes`.
- Font settings need `manage_options`.
- Unsplash search and import need `upload_files`. Importing with a `postId` also needs `edit_post` for that post.
- Setting a status of publish, future or private also needs the post type's publish capability.
- The guide needs `edit_posts`.

## Settings

Open Bricks > Bricks MCP Extensions. Settings are stored in the option `bricks_mcp_extensions_settings`.

- **Abilities**: switch single `bricks-mcp/*` abilities off. A disabled ability is not registered.
- **Rate limit**: requests per minute per user. Default 120. `0` turns it off, otherwise 10 to 1000. It applies to every ability run over REST, including Bricks' own. Calls that one ability makes to another are not counted twice. Over the limit, the call returns a `429` error with `retry_after`.
- **CSS guard**: on by default. Blocks `javascript:` URLs, non-image `data:` URLs, `expression()`, `@import`, `behavior:`, `-moz-binding:` and CSS over 100 KB in the inputs of every writing ability, including Bricks' own, `style` custom attributes and any HTML field with `<style>` or `style=` attributes.
- **Diagnostics**: checks HTTPS, permalinks, Application Passwords, REST reachability, security plugins, hosting provider, the MCP endpoint and PHP timeouts.
- **Updates**: shows the installed version and checks GitHub Releases.

See [docs/SECURITY.md](docs/SECURITY.md) for the security model.

## Upgrading from 1.x

Version 2.0 is a breaking change.

- The old endpoint `/wp-json/bricks-mcp/v1/mcp` is removed. Reconnect your AI client once through Bricks > Settings > AI. A notice in wp-admin reminds administrators until dismissed.
- The 20 tools of 1.x are gone. Bricks abilities replace almost all of them; the table below maps each one.
- The old settings (`require_auth`, `custom_base_url`, dangerous actions) are gone. Authentication is the adapter's Application Passwords.
- Bricks 2.5 now owns the option `bricks_mcp_settings`. On upgrade the plugin carries the old rate limit over to `bricks_mcp_extensions_settings` and removes its own 1.x keys from the old option. It leaves the Bricks keys alone and never writes that option otherwise.

| Old tool | Use now |
|---|---|
| `content` read page elements | `bricks/get-page-elements`| 1.x tool | Use now |
|---|---|
| `get_site_info` | `bricks/get-system-information` |
| `get_builder_guide` | `bricks/start-here`, `bricks/get-builder-guide`, `bricks-mcp/get-guide` |
| `wordpress` | `bricks/find-post` |
| `bricks` enable or disable the editor | `bricks-mcp/set-editor-mode` |
| `bricks` global settings | `bricks/get-global-settings`, `bricks/set-global-settings` |
| `page` list, search, create, duplicate, delete | `bricks/find-post`, `bricks/create-post`, `bricks/duplicate-post`, `bricks/delete-post` |
| `page` elements | `bricks/get-page-elements`, `bricks/set-page-elements` |
| `page` settings | `bricks/get-page-settings`, `bricks/set-page-settings` |
| `page` SEO | `bricks-mcp/get-seo`, `bricks-mcp/update-seo` |
| `page` status and featured image | `bricks-mcp/set-post-status`, `bricks-mcp/set-featured-image` |
| `element` | `bricks/add-element`, `bricks/update-element`, `bricks/batch-update-elements`, `bricks/remove-element`, `bricks-mcp/move-element` |
| `template` | `bricks/list-templates`, `bricks/get-template`, `bricks/create-template`, `bricks/delete-template`, `bricks/set-template-settings` |
| `template_condition` | `bricks/set-template-conditions`, `bricks-mcp/resolve-templates` |
| `template_taxonomy` | No ability. Manage tags and bundles under Bricks > Templates. |
| `global_class` | `bricks/list-global-classes`, `bricks/create-global-class`, `bricks/update-global-class`, `bricks/delete-global-class` |
| `global_class` categories | `bricks-mcp/create-global-class-category`, `bricks-mcp/delete-global-class-category` |
| `theme_style` | `bricks/list-theme-styles`, `bricks/get-theme-styles`, `bricks/create-theme-style`, `bricks/update-theme-style`, `bricks/delete-theme-style` |
| `typography_scale` | `bricks/generate-scale-variables` |
| `color_palette` | `bricks/list-color-palettes`, `bricks/create-color-palette`, `bricks/update-color-palette`, `bricks/create-color`, `bricks/update-color` |
| `global_variable` | `bricks/list-global-variables`, `bricks/set-global-variables`, `bricks/set-global-variable-categories` |
| `component` | `bricks/list-components`, `bricks/get-component`, `bricks/create-component`, `bricks/update-component`, `bricks/delete-component` |
| `media` library | `bricks/find-media`, `bricks/upload-media` |
| `media` Unsplash | `bricks-mcp/search-unsplash`, `bricks-mcp/import-unsplash-photo` |
| `menu` | `bricks/list-nav-menus`, `bricks/get-nav-menu`, `bricks/save-nav-menu`, `bricks/delete-nav-menu` |
| `woocommerce` | `bricks/get-woo-setup-status`, `bricks/plan-woo-setup`, `bricks/run-woo-setup` |
| `font` | `bricks-mcp/list-adobe-fonts`, `bricks-mcp/update-font-settings` |
| `code` page CSS and scripts | `bricks/set-page-settings` with `customCss`, `customScriptsHeader`, `customScriptsBodyHeader` or `customScriptsBodyFooter` |
 which abilities are enabled under Bricks > Settings > AI.

## Local development

Requires Docker and Node.js 22.13+.

```bash
npm install
npm start
```

`.wp-env.json` starts WordPress 7.1 with PHP 8.2, installs `mcp-adapter` 0.7.2 from WordPress.org, maps `mu-plugins/`, writes the WordPress debug log to `logs/debug.log` and activates both plugins. Local site: http://localhost:8888 (admin / password).

Bricks is a paid theme and is not in the repository. Add it with a `.wp-env.override.json` next to `.wp-env.json` that points to a local copy:

```json
{
	"themes": [ "/path/to/bricks" ]
}
```

Run `npm run destroy && npm start` after adding it, then activate the theme.

| Command | Does |
|---|---|
| `npm start` / `npm stop` | Start or stop wp-env. |
| `npm run test` | Unit and integration tests inside wp-env. |
| `npm run test:unit` | Unit tests only. |
| `npm run lint` / `npm run lint:fix` | PHPCS check or fix. |
| `npm run wp <command>` | Run WP-CLI. |
| `npm run logs:watch` | Follow the debug log. |

Without Docker:

```bash
composer install
composer test        # PHPCS and unit tests
composer test:unit   # unit tests only
```

Unit tests use stubs for WordPress, the Abilities API and Bricks, so they do not need Bricks installed.

## Extending

An ability module is a class that implements `BricksMCP\Abilities\Module`:

```php
interface Module {
	public function abilities(): array;
}
```

`abilities()` returns `[ 'bricks-mcp/name' => $args ]`, where `$args` are the arguments of `wp_register_ability()`: label, description, category, input and output schemas, `execute_callback`, `permission_callback` and `meta.annotations`. Add the class to `Registrar::MODULES`. The registrar marks the ability public, skips it when it is disabled in settings and lists it in the settings page.

Use `Access::edit_post()` and `Access::manage_site()` for permissions and `Access::run_bricks_ability()` to call a Bricks ability. Put new category slugs in `Registrar::CATEGORIES`.

## License

GPL-2.0-or-later
