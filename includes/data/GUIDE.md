# Bricks MCP Extensions guide

Bricks MCP Extensions adds abilities named `bricks-mcp/*` next to the Bricks abilities (`bricks/*`) on the same MCP server. It covers what Bricks does not: SEO, post status, featured image, editor mode, element moves, template resolution, global class categories, Unsplash, fonts and page diagnostics.

For layout, styling, dynamic data, query loops and components, read the Bricks guide first: `bricks/start-here`, then `bricks/get-builder-guide` with a topic. This guide (`bricks-mcp/get-guide`, resource `bricks-mcp://guide`) only covers the add-on and the gotchas that guide leaves out.

The guide resource and the audit prompt do not appear in `mcp-adapter-discover-abilities`. Clients that support MCP resources and prompts open `bricks-mcp://guide` and the prompt `bricks-mcp-audit-page` (the ability name with the slash turned into a hyphen). Any other client calls `mcp-adapter-get-ability-info` or `mcp-adapter-execute-ability` with the ability name `bricks-mcp/get-guide` or `bricks-mcp/audit-page`.

## Which ability for which task

| Task | Use |
|---|---|
| Read or write page, post or element content | `bricks/get-page-elements`, `bricks/update-element`, `bricks/set-page-elements` |
| Lint a page before or after editing | `bricks-mcp/diagnose-page` |
| Run a pre-publish review | the `bricks-mcp/audit-page` prompt (`bricks-mcp-audit-page` on the MCP server) |
| Meta title, description, Open Graph | `bricks-mcp/get-seo`, `bricks-mcp/update-seo` |
| Publish, schedule or unpublish | `bricks-mcp/set-post-status` |
| Featured image | `bricks-mcp/set-featured-image` |
| Make an existing post render with Bricks | `bricks-mcp/set-editor-mode` |
| Reorder or reparent an element | `bricks-mcp/move-element` |
| Which template renders a post | `bricks-mcp/resolve-templates` |
| Group global classes | `bricks-mcp/create-global-class-category`, `bricks-mcp/delete-global-class-category` |
| Stock photos | `bricks-mcp/search-unsplash`, `bricks-mcp/import-unsplash-photo` |
| Adobe Fonts and font loading | `bricks-mcp/list-adobe-fonts`, `bricks-mcp/update-font-settings` |

Every ability needs the same access as the Bricks builder: you can only touch posts you may edit and use the builder on. Site-wide settings, such as fonts, need an administrator. Administrators can switch single abilities off in the plugin settings.

## Page diagnostics

### bricks-mcp/diagnose-page

Read-only lint of one page, header or footer template. Input: `postId`, optional `area` (`content`, `header`, `footer`) as a guard; a mismatch returns an error.

Run it before editing an existing page and again after a large write. Each issue has `elementId` (null for page-level issues), `severity` (`error` or `warning`), `type`, `message` and `fix`. The fix names the Bricks ability that applies it. `status` is `clean`, `warnings` or `errors`.

Issue types:

- `root_placeholder`: `%root%` in `_cssCustom`. Bricks resolves it on writes, but data saved elsewhere may still hold it. Resend the CSS through `bricks/update-element`; do not write the selector by hand.
- `wrong_setting_key`: `_maxWidth` (use `_widthMax`), `_textAlign` (use `_typography["text-align"]`), `_animation*` (use `_interactions`, see `bricks/update-element-interactions`). These keys are silently ignored.
- `plain_string_color`: a color stored as `"#fff"` instead of `{"hex": "#fff"}` or `{"raw": "var(--name)"}`.
- `hardcoded_design_token`: a hex value that already exists as a palette color or global variable. Use the variable.
- `missing_global_class`: `_cssGlobalClasses` holds an id that no longer exists. When the global class list cannot be read completely, the check is skipped and an `info` issue (`class_check_skipped`) says so.
- `invalid_breakpoint`: a settings key such as `_padding:mobile` whose suffix is not a breakpoint on this site (`bricks/list-breakpoints`).
- `missing_mobile_padding`: a section, container, block or div has desktop `_padding` and no mobile override.
- `script_missing_tags`: `customScriptsHeader`, `customScriptsBodyHeader` or `customScriptsBodyFooter` holds JavaScript without `<script>` tags.
- `missing_wp_reset_postdata`: a code element runs `WP_Query` without `wp_reset_postdata()`.
- `orphaned_child`: an element lists a child that does not exist. `bricks/set-page-elements` replaces the whole page, so read the complete tree with `bricks/get-page-elements`, remove only the dangling id, and send the complete tree back with `expectedDocumentDigest` from that read.
- `phantom_content`: a header or footer template also has content in the page content meta; Bricks ignores it.
- `multiple_seo_plugins`, `bricks_seo_output`, `bricks_opengraph_output`, `bricks_seo_stale_values`, `bricks_opengraph_stale_values`: the page prints SEO or Open Graph tags next to the active SEO plugin's own, as `bricks-mcp/get-seo` warns. Checked for pages, not header or footer templates.

Not checked, because Bricks rejects them on every write: unknown element types, invalid parent ids, duplicate ids, circular references and malformed query, image or link settings.

Code elements: executing PHP is an administrator decision in the builder. Bricks abilities reject code-sensitive writes from agents, and `bricks/get-page-elements` redacts code the caller may not see. Report code problems to the user; do not try to enable code execution.

### bricks-mcp/audit-page (prompt)

Arguments: `postId`, optional `focus` (`all`, `seo`, `accessibility`, `performance`, `design`). Returns instructions that chain `bricks-mcp/diagnose-page`, `bricks/get-page-elements`, `bricks-mcp/get-seo` and `bricks/audit-design-system` into one report. It changes nothing.

## Content

### bricks-mcp/get-seo and bricks-mcp/update-seo

Read and write SEO fields through the active SEO plugin (Yoast SEO, Rank Math, SEOPress, Slim SEO), or through Bricks page settings when none is active. `get-seo` takes `postId` and returns `source`, `supportedFields`, the fields and an audit. `update-seo` takes `postId` and a `seo` object with only the fields to change (`title`, `description`, `noindex`, `nofollow`, `canonical`, `ogTitle`, `ogDescription`, `ogImage`, `twitterTitle`, `twitterDescription`, `twitterImage`, `focusKeyword`). An empty string clears a field.

- Always call `get-seo` first. With an SEO plugin active, write SEO through `update-seo`, never through `bricks/set-page-settings`.
- With several SEO plugins active, the source is the first of Yoast SEO, Rank Math, SEOPress and Slim SEO. `audit.activeSeoPlugins` lists them all, and a `multiple_seo_plugins` warning says the others still print their own tags.
- Bricks prints its own title, description, robots and Open Graph tags from page settings next to an SEO plugin unless `disableSeo` and `disableOpenGraph` are on in Bricks > Settings > General. With an SEO plugin active, `audit.warnings` (and `warnings` in the `update-seo` result) has `bricks_seo_output` or `bricks_opengraph_output` for each output that is still on, and `bricks_seo_stale_values` or `bricks_opengraph_stale_values` with the Bricks page settings it prints in `staleValues`. A stale Bricks `noindex` or `nofollow` is an `error`, since crawlers may drop the page. The check reads only the two settings: a `bricks/frontend/disable_seo` or `bricks/frontend/disable_opengraph` filter answers per page request, so confirm one in the published page head rather than dismissing the warning. Report the setting to the site owner; never change global Bricks settings. Stale values can be cleared with `bricks/set-page-settings` by sending each key as `null`; when `metaRobots` also holds directives that block nothing, such as `nosnippet`, send it with only those (the warning's `fix` names them) rather than `null`, so they are kept.
- Fields the source cannot store come back in `unsupported`. Slim SEO stores title, description, noindex, canonical, ogImage and twitterImage.
- Aim for titles of 30-60 characters, descriptions of 120-160, and an Open Graph image of 1200x630.

### bricks-mcp/set-post-status

Input: `postId`, `status` (`publish`, `draft`, `pending`, `private`, `future`), `date` for `future`. Bricks creates templates as drafts and has no ability to publish them. A template only applies on the site once it is published, so use this after `bricks/create-template` and `bricks/set-template-conditions`. Refused while another user is editing the post. For a draft review of changes, use the Bricks draft abilities instead.

### bricks-mcp/set-featured-image

Input: `postId`, `attachmentId` (`0` removes it). The attachment must be an image already in the media library; upload with `bricks/upload-media` or import from Unsplash first. This does not place an image in the layout: edit the image element for that.

### bricks-mcp/set-editor-mode

Input: `postId`, `mode` (`bricks` or `wordpress`). Bricks renders a post only when it is in Bricks mode. Use `bricks` to enable Bricks on a post created without it before writing elements. Element data is kept when you switch to `wordpress`.

## Builder

### bricks-mcp/move-element

Input: `postId`, `elementId`, `parentId` (`0` for the root), optional zero-based `position` (omit to append). Moves an element with its whole subtree, which `bricks/update-element` cannot do. It goes through the Bricks save pipeline, so use `bricks/restore-revision` to undo. Get ids from `bricks/get-page-elements`. Check the result with `bricks-mcp/diagnose-page`.

### bricks-mcp/resolve-templates

Input: `postId`. Returns which header, footer and content templates Bricks selects for that post and the competing matches. Use it when a template does not apply; use `bricks/list-templates` to browse the library. A template that is a draft never matches.

### bricks-mcp/create-global-class-category and bricks-mcp/delete-global-class-category

Create takes `name`; delete takes `categoryId` and moves its classes to Uncategorized, so it changes many classes at once. Assign a class to a category with `bricks/update-global-class`. List categories with `bricks/list-global-classes`.

## Media and fonts

### bricks-mcp/search-unsplash and bricks-mcp/import-unsplash-photo

`search-unsplash` takes `query` (plus `page`, `perPage`, `orientation`) and returns photo ids, sizes, preview URLs and photographer credits. It uses the Unsplash API key from Bricks settings and fails with a clear error when none is set. `import-unsplash-photo` takes a `photoId` from the search (optional `alt`, `title`, `postId`), downloads it into the media library with the credit as caption, and returns an `attachmentId`; importing the same photo twice returns the existing attachment (`duplicate: true`). Use the `attachmentId` with `bricks/update-element` (image elements), `bricks-mcp/set-featured-image` or a background. For other URLs or files use `bricks/upload-media`; to search the existing library use `bricks/find-media`.

### bricks-mcp/list-adobe-fonts and bricks-mcp/update-font-settings

`list-adobe-fonts` says whether an Adobe Fonts project is configured and lists its cached families with the `cssFamily` name to use in `_typography` (`{"font-family": "..."}`), plus the current font-loading settings. The project id is never returned and is set in Bricks settings. `update-font-settings` changes only `disableGoogleFonts`, `webfontLoading` (`""` for stylesheets or `webfontloader`), `customFontsPreload` and `googleFontsFilesystem`; Bricks does not let `bricks/set-global-settings` write these. Use `bricks/list-custom-fonts` and `bricks/update-custom-font` for uploaded fonts.

## Gotchas that Bricks' own guide does not cover

- Dynamic tags need context: `{post_title}` and similar render empty on static pages and only work in query loops and single templates.
- Text fields take a bare tag (`{post_title}`); image fields take `{"useDynamicData": "{featured_image}"}`; link fields take `{"type": "meta", "useDynamicData": "{post_url}"}`.
- Section, container, block and div take `_display` (`flex`, `inline-flex`, `grid`, `block`, `inline-block`, `inline`, `none`) with `_gridGap` for the gap (stored under that key for flex and grid alike), `_columnGap` and `_rowGap`, plus `_gridTemplateColumns`, `_gridTemplateRows` and the other grid keys when `_display` is `grid`. Other elements (heading, text, button, image) take `_display` without `grid` and `_gap` only for flex.
- `_cssCustom` rules target the persisted selector: `#brxe-{elementId}`, `#{_cssId}` when the element has a custom `_cssId`, `.brxe-{elementId}` inside component definitions and `.brxe-{cid}` on an instance. Writing `%root%` through `bricks/update-element` lets Bricks fill in the right one.
- A component instance's `name` and `cid` both equal the component id.
- Component properties without a `connections` map store values but apply nothing.
- Slot content lives in the page element array with `parent` set to the instance id, not in the component definition.
- Popup triggers are `_interactions` on elements; popup display settings are template settings. They are separate.
- The toggle-mode element does nothing unless dark mode colors are set in the Bricks color manager.
- Icon objects are `{"icon": "...", "library": "..."}`; the library is `fontawesomeSolid`, `fontawesomeRegular`, `fontawesomeBrands`, `ionicons` or `themify`.
- Gradients use `_gradient`, not `_background`: `{"_gradient": {"applyTo": "background", "gradientType": "linear", "angle": 135, "colors": [{"color": {"hex": "#667eea"}, "stop": "0"}, {"color": {"hex": "#764ba2"}, "stop": "100"}]}}`. For a variable or a full CSS gradient use `{"_gradient": {"applyTo": "background", "gradientType": "custom", "custom": "var(--hero-gradient)"}}`. Prefer variables for the colors.
- Breakpoint suffixes go on the setting key (`_padding:mobile_portrait`); read the real keys with `bricks/list-breakpoints` because sites can change them.
- Use `bricks/batch-update-elements` for many edits to one page instead of many single updates.
- Text settings accept HTML: `"text": "<strong>Bold</strong> and normal"`.
- A header or footer template keeps its elements in its own area, not in the page content. `bricks/get-page-elements` picks the right area from the template type, and `bricks-mcp/diagnose-page` reports content stored in the wrong place.
- Custom scripts (`customScriptsHeader`, `customScriptsBodyHeader`, `customScriptsBodyFooter`) need the `<script>` tags in the value and the `unfiltered_html` capability to write.
- Prefer native settings (`_padding`, `_typography`, `_background`) over `_cssCustom`; use global classes and theme styles for anything repeated across pages.
- Deprecated `_animation`, `_animationDuration` and `_animationDelay` keys must not be used; animations live in `_interactions`.
- After a structural change run `bricks-mcp/diagnose-page`, then read the page back with `bricks/get-page-elements` and `responseFormat: "summary"`.
