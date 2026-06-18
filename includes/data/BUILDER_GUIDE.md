# Bricks MCP Builder Guide

Patterns and reference for AI models building Bricks Builder pages via MCP tools.

## Golden Rule: Batch Creation

Always create full pages in one `create_bricks_page` call using simplified nested format. Never add elements one at a time.

```json
{
  "post_title": "My Page",
  "elements": [
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "settings": { "_direction": "column", "_alignItems": "center" },
          "children": [
            { "name": "heading", "settings": { "tag": "h1", "text": "Hello" } },
            { "name": "text-basic", "settings": { "text": "Subtitle" } }
          ]
        }
      ]
    }
  ]
}
```

IDs and parent/children linkage are auto-generated. Nest `children` arrays naturally.

## Page Structure

Every Bricks page follows: **section > container > content elements**.

- `section` — Full-width row. Top-level wrapper.
- `container` — Layout box inside section. Controls direction, alignment, gap.
- Content elements: `heading`, `text-basic`, `button`, `icon`, `image`, `video`, `divider`, `toggle-mode`, etc.

Nest containers for multi-column layouts:

```
section
  container (_direction: row)
    container (card 1)
      icon
      heading
      text-basic
    container (card 2)
      ...
```

## Element Settings Reference

### Content Properties (no underscore prefix)

| Property | Elements | Values |
|----------|----------|--------|
| `text` | heading, text-basic, button | HTML or plain text |
| `tag` | heading | `h1`–`h6`, `div`, `span` |
| `link` | button, heading | `{"url": "...", "type": "external"}` |
| `icon` | icon, toggle-mode | `{"library": "Ionicons", "icon": "ion-ios-rocket"}` — For toggle-mode: light mode icon (default: built-in sun SVG) |
| `iconColor` | icon, toggle-mode | `{"hex": "#3B82F6"}` — For toggle-mode: light mode icon color |
| `iconSize` | icon, toggle-mode | `"48px"` — For toggle-mode: light mode icon size |
| `iconDark` | toggle-mode | `{"library": "Ionicons", "icon": "ion-ios-moon"}` — Dark mode icon (default: built-in moon SVG) |
| `iconDarkColor` | toggle-mode | `{"hex": "#818CF8"}` — Dark mode icon color |
| `iconDarkSize` | toggle-mode | `"24px"` — Dark mode icon size |
| `ariaLabel` | toggle-mode | `"Toggle mode"` — Accessibility label (default: `"Toggle mode"`) |
| `videoType` | video | `"youtube"`, `"vimeo"`, `"media"`, `"file"`, `"meta"` — Video source type |
| `objectFit` | video | `"fill"`, `"contain"`, `"cover"`, `"none"`, `"scale-down"` — CSS object-fit on inner video element. Only for `media`/`file`/`meta` types, NOT youtube/vimeo (those use iframes). (Bricks 2.3+) |
| `loadMoreInitial` | image-gallery | Number of images shown initially (enables load more) |
| `loadMoreStep` | image-gallery | Images per load (`0` = all remaining) |
| `loadMoreInfiniteScroll` | image-gallery | `true` to auto-load on scroll |
| `loadMoreInfiniteScrollDelay` | image-gallery | Scroll trigger delay in ms (default `600`) |
| `loadMoreInfiniteScrollOffset` | image-gallery | Scroll trigger offset in px (default `200`) |

### Style Properties (underscore prefix) — These Generate CSS

| Property | Type | Example |
|----------|------|---------|
| `_padding` | object | `{"top": "60px", "right": "40px", "bottom": "60px", "left": "40px"}` |
| `_margin` | object | Same format as padding |
| `_background` | object | `{"color": {"hex": "#1E293B"}}` |
| `_typography` | object | `{"font-size": "48px", "font-weight": "700", "color": {"hex": "#FFF"}, "line-height": "1.2", "text-align": "center"}` |
| `_direction` | string | `"column"` or `"row"` (flex direction) |
| `_alignItems` | string | `"center"`, `"flex-start"`, `"flex-end"`, `"stretch"` |
| `_justifyContent` | string | `"center"`, `"space-between"`, `"flex-start"` |
| `_border` | object | `{"radius": {"top": "12px", "right": "12px", "bottom": "12px", "left": "12px"}, "style": "solid", "color": {"hex": "#E2E8F0"}, "width": 1}` |
| `_flexGrow` | int | `1` |
| `_width` | string | `"100%"`, `"33.33%"` |
| `_widthMax` | string | `"600px"`, `"80%"` — CSS `max-width`. Note: `_maxWidth` does NOT exist — use `_widthMax` |
| `_display` | string | `"flex"`, `"inline-flex"`, `"block"`, `"inline-block"`, `"inline"`, `"none"` — CSS `display`. Only for non-layout elements (heading, text-basic, button, icon, image, etc.). Layout elements (section, container, block, div) are always flex. |
| `_gap` | string | `"20px"`, `"1rem"` — CSS `gap`. Requires `_display` set to `flex`/`inline-flex`. Only for non-layout elements. For layout elements (section, container), use `_cssCustom`. |
| `_perspective` | string | `"800px"` — CSS `perspective` property. Number with unit (Bricks 2.3+, Layout group) |
| `_perspectiveOrigin` | string | `"center"`, `"50% 50%"`, `"left top"` — CSS `perspective-origin` (Bricks 2.3+, Layout group) |
| `_motionElementParallax` | boolean | `true` — Enable element parallax (Bricks 2.3+, Transform group) |
| `_motionElementParallaxSpeedX` | number | Horizontal speed %. CSS var `--brx-motion-parallax-speed-x`. Requires `_motionElementParallax: true` |
| `_motionElementParallaxSpeedY` | number | Vertical speed %. CSS var `--brx-motion-parallax-speed-y`. Requires `_motionElementParallax: true` |
| `_motionBackgroundParallax` | boolean | `true` — Enable background image parallax (Bricks 2.3+, Transform group) |
| `_motionBackgroundParallaxSpeed` | number | Background speed %. CSS var `--brx-motion-background-speed`. Requires `_motionBackgroundParallax: true` |
| `_motionStartVisiblePercent` | number | 0–100. Where parallax starts based on viewport scroll progress (0 = entering, 50 = near center) |

### Properties That Do NOT Generate CSS

These are silently ignored when set via API:

- `_textAlign` — Use `_typography["text-align"]` instead (no control exists for this key)
- `_maxWidth` — This key does not exist. Use `_widthMax` instead (generates `max-width` CSS)

**Layout element caveat:** `_gap` and `_display` only work on non-layout elements (heading, text-basic, button, icon, image, etc.). Layout elements (section, container, block, div) are always `display: flex` and do not expose these controls. Use `_cssCustom` for gap on layout elements.

### Custom CSS (`_cssCustom`)

For properties not covered by built-in settings, or for layout elements needing gap:

```json
{
  "_cssCustom": "#brxe-ELEMENT_ID { gap: 36px; grid-template-columns: repeat(3, 1fr); }"
}
```

**Important:** Use `#brxe-{elementId}` (the actual rendered selector), NOT `%root%`. The `%root%` placeholder only works inside the Bricks visual editor, not via API.

### Transforms (`_transform`)

The `_transform` control generates the CSS `transform` property. Set it as an object with function keys:

| Key | Type | Unit | Example |
|-----|------|------|---------|
| `translateX` | number/string | px (default if number-only) | `"50px"`, `"10%"`, `50` |
| `translateY` | number/string | px (default if number-only) | `"-20px"`, `"5vh"` |
| `rotateX` | number | deg (auto-appended) | `45` = `rotateX(45deg)` |
| `rotateY` | number | deg (auto-appended) | `180` |
| `rotateZ` | number | deg (auto-appended) | `90` |
| `skewX` | number | deg (auto-appended) | `10` |
| `skewY` | number | deg (auto-appended) | `5` |
| `scale3dX` | number | unitless | `1.2` (Bricks 2.3+) |
| `scale3dY` | number | unitless | `0.8` (Bricks 2.3+) |
| `scale3dZ` | number | unitless | `1` (Bricks 2.3+) |
| `perspective` | number/string | px (default if number-only) | `"800px"` (Bricks 2.3+, always first in output) |

```json
{
  "_transform": {
    "translateY": "-20px",
    "rotateZ": 5,
    "scale3dX": 1.1,
    "scale3dY": 1.1,
    "scale3dZ": 1
  },
  "_transformOrigin": "center center"
}
```

- `scale3d` requires at least one of scale3dX/Y/Z; omitted axes default to `1`.
- `perspective` inside `_transform` is always placed first in the CSS output (required by spec).
- `_transformOrigin` (string) sets `transform-origin`. Default: `"center"`.

### Responsive Breakpoints

Append breakpoint suffix to any style property key:

```json
{
  "_padding": { "top": "80px", "bottom": "80px" },
  "_padding:tablet_portrait": { "top": "40px", "bottom": "40px" },
  "_padding:mobile_portrait": { "top": "20px", "bottom": "20px" }
}
```

Breakpoints: `tablet_landscape`, `tablet_portrait`, `mobile_landscape`, `mobile_portrait`.

## Animations

**WARNING:** Never use the deprecated `_animation`, `_animationDuration`, or `_animationDelay` keys. These were deprecated in Bricks 1.6. Always use the `_interactions` array in element settings. For the full programmatic reference, call `bricks:get_interaction_schema`.

### Interaction Structure

Every element can have a `_interactions` array in its settings. Each interaction object requires:
- `id` — unique 6-char lowercase alphanumeric (same format as element IDs, e.g. `ab1cd2`)
- `trigger` — what event starts the interaction
- `action` — what happens when triggered
- `target` — which element is affected: `"self"` (default), `"custom"` (CSS selector via `targetSelector`), or `"popup"` (template ID via `templateId`)

### Triggers

| Trigger | When It Fires |
|---------|---------------|
| `click` | Element is clicked |
| `mouseover` | Mouse moves over element |
| `mouseenter` | Mouse enters element bounds |
| `mouseleave` | Mouse leaves element bounds |
| `focus` | Element receives focus |
| `blur` | Element loses focus |
| `enterView` | Element enters viewport (IntersectionObserver, optional `rootMargin`) |
| `leaveView` | Element leaves viewport |
| `animationEnd` | Another interaction's animation ends (set `animationId` to that interaction's `id`) |
| `contentLoaded` | DOM content loaded (optional `delay` field, e.g. `"0.5s"`) |
| `scroll` | Window scroll reaches `scrollOffset` value (px/vh/%) |
| `mouseleaveWindow` | Mouse leaves the browser window |
| `ajaxStart` | Query loop AJAX starts (requires `ajaxQueryId`) |
| `ajaxEnd` | Query loop AJAX ends (requires `ajaxQueryId`) |
| `formSubmit` | Form submitted (requires `formId`) |
| `formSuccess` | Form submission succeeded (requires `formId`) |
| `formError` | Form submission failed (requires `formId`) |

### Actions

| Action | What It Does |
|--------|-------------|
| `startAnimation` | Run Animate.css animation on target (requires `animationType`) |
| `show` | Show target element (removes `display:none`) |
| `hide` | Hide target element (sets `display:none`) |
| `click` | Programmatically click target element |
| `setAttribute` | Set HTML attribute on target |
| `removeAttribute` | Remove HTML attribute from target |
| `toggleAttribute` | Toggle HTML attribute on target |
| `toggleOffCanvas` | Toggle Bricks off-canvas element |
| `loadMore` | Load more results in query loop (requires `loadMoreQuery`) |
| `scrollTo` | Smooth scroll to target element |
| `javascript` | Call a global JS function (requires `jsFunction`, optional `jsFunctionArgs`) |
| `openAddress` | Open map info box |
| `closeAddress` | Close map info box |
| `clearForm` | Clear form fields |
| `storageAdd` | Add to browser storage |
| `storageRemove` | Remove from browser storage |
| `storageCount` | Count browser storage items |

### Animation Types (Animate.css)

Bricks auto-enqueues Animate.css when `startAnimation` action is detected — no manual enqueue needed.

**Attention:** `bounce`, `flash`, `pulse`, `rubberBand`, `shakeX`, `shakeY`, `headShake`, `swing`, `tada`, `wobble`, `jello`, `heartBeat`

**Back:** `backInDown`, `backInLeft`, `backInRight`, `backInUp`, `backOutDown`, `backOutLeft`, `backOutRight`, `backOutUp`

**Bounce:** `bounceIn`, `bounceInDown`, `bounceInLeft`, `bounceInRight`, `bounceInUp`, `bounceOut`, `bounceOutDown`, `bounceOutLeft`, `bounceOutRight`, `bounceOutUp`

**Fade:** `fadeIn`, `fadeInDown`, `fadeInDownBig`, `fadeInLeft`, `fadeInLeftBig`, `fadeInRight`, `fadeInRightBig`, `fadeInUp`, `fadeInUpBig`, `fadeInTopLeft`, `fadeInTopRight`, `fadeInBottomLeft`, `fadeInBottomRight`, `fadeOut`, `fadeOutDown`, `fadeOutDownBig`, `fadeOutLeft`, `fadeOutLeftBig`, `fadeOutRight`, `fadeOutRightBig`, `fadeOutUp`, `fadeOutUpBig`, `fadeOutTopLeft`, `fadeOutTopRight`, `fadeOutBottomRight`, `fadeOutBottomLeft`

**Flip:** `flip`, `flipInX`, `flipInY`, `flipOutX`, `flipOutY`

**Light Speed:** `lightSpeedInRight`, `lightSpeedInLeft`, `lightSpeedOutRight`, `lightSpeedOutLeft`

**Rotate:** `rotateIn`, `rotateInDownLeft`, `rotateInDownRight`, `rotateInUpLeft`, `rotateInUpRight`, `rotateOut`, `rotateOutDownLeft`, `rotateOutDownRight`, `rotateOutUpLeft`, `rotateOutUpRight`

**Special:** `hinge`, `jackInTheBox`, `rollIn`, `rollOut`

**Zoom:** `zoomIn`, `zoomInDown`, `zoomInLeft`, `zoomInRight`, `zoomInUp`, `zoomOut`, `zoomOutDown`, `zoomOutLeft`, `zoomOutRight`, `zoomOutUp`

**Slide:** `slideInUp`, `slideInDown`, `slideInLeft`, `slideInRight`, `slideOutUp`, `slideOutDown`, `slideOutLeft`, `slideOutRight`

### Pattern: Scroll-Reveal (enterView)

Fade in an element when it scrolls into view. The `rootMargin` controls how early the trigger fires (negative bottom value means "when element is X pixels inside the viewport").

```json
{
  "_interactions": [
    {
      "id": "aa1bb2",
      "trigger": "enterView",
      "rootMargin": "0px 0px -80px 0px",
      "action": "startAnimation",
      "animationType": "fadeInUp",
      "animationDuration": "0.8s",
      "animationDelay": "0s",
      "target": "self",
      "runOnce": true
    }
  ]
}
```

### Pattern: Stagger (Cascading Elements)

Apply the same animation to sibling elements with incrementing `animationDelay` values. Each element gets its own `_interactions` array with a unique `id`:

```json
// Card 1 settings
{"_interactions": [{"id": "cc3dd4", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0s", "target": "self", "runOnce": true}]}

// Card 2 settings
{"_interactions": [{"id": "ee5ff6", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0.15s", "target": "self", "runOnce": true}]}

// Card 3 settings
{"_interactions": [{"id": "gg7hh8", "trigger": "enterView", "action": "startAnimation", "animationType": "fadeInUp", "animationDuration": "0.8s", "animationDelay": "0.3s", "target": "self", "runOnce": true}]}
```

### Pattern: Chained Animations (animationEnd)

Sequence animations by using the `animationEnd` trigger with an `animationId` pointing to the previous interaction's `id`. The subtitle waits for the title animation to finish:

```json
// Title element settings
{
  "_interactions": [
    {"id": "ii9jj0", "trigger": "contentLoaded", "action": "startAnimation", "animationType": "fadeInDown", "animationDuration": "0.8s", "animationDelay": "0s", "target": "self"}
  ]
}

// Subtitle element settings
{
  "_interactions": [
    {"id": "kk1ll2", "trigger": "animationEnd", "animationId": "ii9jj0", "action": "startAnimation", "animationType": "fadeIn", "animationDuration": "0.6s", "animationDelay": "0s", "target": "self"}
  ]
}
```

**Note:** The `animationId` references an interaction `id` from any element on the same page, not limited to the same element.

### Pattern: Click Interaction

Trigger animations or visibility changes on click:

```json
{
  "_interactions": [
    {"id": "pp1qq2", "trigger": "click", "action": "startAnimation", "animationType": "pulse", "animationDuration": "0.5s", "animationDelay": "0s", "target": "self"}
  ]
}
```

For show/hide, use `action: "show"` or `action: "hide"` with `target: "custom"` and `targetSelector: "#brxe-elementId"`.

### Pattern: Native Parallax (Bricks 2.3+)

Bricks 2.3 adds built-in parallax as style properties under the Transform control group — no GSAP or custom JS needed. Set these directly in element settings:

**Element parallax** — moves the element itself while scrolling:

```json
{
  "_motionElementParallax": true,
  "_motionElementParallaxSpeedX": 0,
  "_motionElementParallaxSpeedY": -20,
  "_motionStartVisiblePercent": 0
}
```

**Background parallax** — moves the background image while scrolling:

```json
{
  "_motionBackgroundParallax": true,
  "_motionBackgroundParallaxSpeed": -15,
  "_motionStartVisiblePercent": 0
}
```

- Speed values are percentages. Negative = opposite scroll direction, positive = same direction.
- `_motionStartVisiblePercent` controls when the effect begins (0 = element entering viewport, 50 = near center).
- Parallax effects are not visible in the Bricks builder preview — only on the live frontend.
- These are NOT interactions — they are style properties set directly on element settings, same as `_padding` or `_typography`.

### Pattern: GSAP Integration (Advanced)

GSAP is NOT bundled with Bricks. The site owner must load it (CDN or local). The MCP plugin does not enqueue GSAP. Use this two-step pattern for advanced animations like scroll-scrub and timelines. For simple parallax, prefer the native parallax properties above — use GSAP only when you need advanced control (custom easing, scrub values, timeline sequencing).

**Step 1 — Inject GSAP init script via page footer:**

Use `page:update_settings` with `customScriptsBodyFooter`:

```html
<script>
document.addEventListener("DOMContentLoaded", function() {
  if (typeof gsap === "undefined" || typeof ScrollTrigger === "undefined") return;
  gsap.registerPlugin(ScrollTrigger);

  window.brxGsap = {
    parallax: function(brxParams) {
      gsap.to(brxParams.source, {
        yPercent: -20,
        ease: "none",
        scrollTrigger: {
          trigger: brxParams.source,
          scrub: 1,
          start: "top bottom",
          end: "bottom top"
        }
      });
    }
  };
});
</script>
```

**Step 2 — Add `javascript` interaction on the element:**

```json
{
  "_interactions": [
    {
      "id": "mm3nn4",
      "trigger": "contentLoaded",
      "action": "javascript",
      "jsFunction": "brxGsap.parallax",
      "jsFunctionArgs": [{"id": "oo5pp6", "jsFunctionArg": "%brx%"}],
      "target": "self"
    }
  ]
}
```

The `%brx%` placeholder is replaced by Bricks with an object: `{source: sourceElement, targets: targetElements, target: firstTarget}`.

### Animation Tips

- Use `fadeInUp` as the default scroll-entrance animation
- Keep durations 0.6s--1.0s, delays 0.1s--0.15s increments for stagger
- Hero elements: `contentLoaded` trigger, no delay. Below-fold: `enterView` trigger
- Avoid animating more than 5--6 elements per viewport
- Animation types containing `"In"` (case-sensitive) auto-hide the element on page load and reveal on animation
- Use `"In"` types for entrance animations, `"Out"` types for exit or click-triggered hiding
- For GSAP: always wrap init code in `DOMContentLoaded`, check `typeof gsap !== "undefined"` before use
- Each interaction `id` must be unique across the page -- 6-char lowercase alphanumeric

### Video Element

The `video` element supports YouTube, Vimeo, and self-hosted (media/file/meta) sources. Self-hosted videos support the `objectFit` control (Bricks 2.3+) for CSS object-fit on the inner `<video>` tag.

Self-hosted video with objectFit:

```json
{
  "name": "video",
  "settings": {
    "videoType": "media",
    "video": {
      "id": 123,
      "url": "https://example.com/wp-content/uploads/video.mp4"
    },
    "objectFit": "cover"
  }
}
```

> **Note:** `objectFit` only works when `videoType` is `"media"`, `"file"`, or `"meta"`. YouTube and Vimeo use iframes where object-fit does not apply.

YouTube example:

```json
{
  "name": "video",
  "settings": {
    "videoType": "youtube",
    "videoUrl": "https://www.youtube.com/watch?v=dQw4w9WgXcQ"
  }
}
```

### Toggle - Mode (Light/Dark) (Bricks 2.3+)

The `toggle-mode` element renders a button that toggles between light and dark mode. It uses the site's color manager dark mode colors. Default icons are sun (light) and moon (dark) SVGs built into Bricks.

Minimal usage (defaults to built-in sun/moon SVGs):

```json
{
  "name": "toggle-mode",
  "settings": {
    "ariaLabel": "Switch color mode"
  }
}
```

With custom icons and colors:

```json
{
  "name": "toggle-mode",
  "settings": {
    "icon": {"library": "Ionicons", "icon": "ion-ios-sunny"},
    "iconColor": {"hex": "#F59E0B"},
    "iconSize": "28px",
    "iconDark": {"library": "Ionicons", "icon": "ion-ios-moon"},
    "iconDarkColor": {"hex": "#818CF8"},
    "iconDarkSize": "28px",
    "ariaLabel": "Switch between light and dark mode"
  }
}
```

**Prerequisites:** At least one color in the Bricks color manager must have a dark mode variant configured. Without dark mode colors, the toggle renders but has no effect.

**CSS selectors for styling:** `.toggle.light > *` (light mode icon), `.toggle.dark > *` (dark mode icon).

## Dynamic Data & Query Loops

### Dynamic Data Tags

Embed dynamic data in element settings using `{tag_name}` syntax. Call `bricks:get_dynamic_tags` to see all available tags including third-party plugin tags (ACF, MetaBox, etc.).

**Text fields** — use bare tag string:
```json
{"name": "heading", "settings": {"text": "{post_title}", "tag": "h2"}}
```

**Image fields** — use `useDynamicData` key:
```json
{"name": "image", "settings": {"image": {"useDynamicData": "{featured_image}"}}}
```

**Link fields** — use `type: dynamic` + `dynamicData`:
```json
{"name": "button", "settings": {"text": "Read More", "link": {"type": "dynamic", "dynamicData": "{post_url}"}}}
```

**Tag filters:**
- `{post_excerpt:20}` — limit to 20 words
- `{featured_image:medium}` — specific image size
- `{post_terms_category}` — taxonomy terms as linked list
- `{cf_field_key}` — native WP custom field (no plugin needed)
- `{acf_field_name}` — ACF field value
- `{query_loop_index}` — current loop position (1-based)

### Query Loops

Turn any layout element into a repeating loop by adding `hasLoop: true` and a `query` object. Call `bricks:get_query_types` for full settings reference.

**Blog post loop:**
```json
{
  "name": "container",
  "settings": {
    "_direction": "row",
    "hasLoop": true,
    "query": {
      "objectType": "post",
      "postType": ["post"],
      "orderby": "date",
      "order": "DESC",
      "postsPerPage": 9
    }
  },
  "children": [
    {"name": "image", "settings": {"image": {"useDynamicData": "{featured_image}"}}},
    {"name": "heading", "settings": {"text": "{post_title}", "tag": "h2", "link": {"type": "dynamic", "dynamicData": "{post_url}"}}},
    {"name": "text-basic", "settings": {"text": "{post_excerpt:25}"}},
    {"name": "text-basic", "settings": {"text": "{post_date}"}}
  ]
}
```

**Taxonomy grid:**
```json
{
  "name": "container",
  "settings": {
    "hasLoop": true,
    "query": {"objectType": "term", "taxonomies": ["category"], "orderby": "count", "order": "DESC", "number": 6}
  },
  "children": [
    {"name": "heading", "settings": {"text": "{term_name}", "tag": "h3"}},
    {"name": "text-basic", "settings": {"text": "{term_description}"}}
  ]
}
```

### Archive Templates

Create archive templates via `template:create` with type `archive`. The query loop container MUST have `is_main_query: true`.

```json
{
  "action": "create",
  "title": "Blog Archive",
  "template_type": "archive",
  "elements": [
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "settings": {
            "hasLoop": true,
            "query": {
              "objectType": "post",
              "postType": ["post"],
              "is_main_query": true
            }
          },
          "children": [
            {"name": "heading", "settings": {"text": "{post_title}", "tag": "h2"}},
            {"name": "text-basic", "settings": {"text": "{post_excerpt:30}"}}
          ]
        }
      ]
    }
  ]
}
```

Then assign conditions via `template_condition:set`:
```json
{"action": "set", "template_id": 123, "conditions": [{"main": "archiveType", "archiveType": "post"}]}
```

**Critical:** Without `is_main_query: true`, paginated archives return 404 on page 2+.

### Query Filters

Add AJAX-powered filtering to query loops using Bricks filter elements. Requires query filters enabled in Bricks > Settings > Performance > "Enable query sort / filter / live search".

Call `bricks:get_filter_schema` for the full list of filter elements and their settings.

**Filter setup example** — a posts loop with a category checkbox filter:
```json
[
  {
    "name": "container",
    "settings": {
      "hasLoop": true,
      "query": {"objectType": "post", "postType": ["post"], "postsPerPage": 12}
    },
    "children": [
      {"name": "heading", "settings": {"text": "{post_title}", "tag": "h2"}},
      {"name": "text-basic", "settings": {"text": "{post_excerpt:20}"}}
    ]
  },
  {
    "name": "filter-checkbox",
    "settings": {
      "filterQueryId": "<query-container-element-id>",
      "filterSource": "taxonomy",
      "filterTaxonomy": "category"
    }
  }
]
```

**Key rules:**
- `filterQueryId` is the 6-character Bricks element ID of the loop container, NOT a post ID
- Filter elements must be on the same page as the query loop they target
- Filter elements render as empty when Query Filters is disabled in Bricks settings

### Pagination & Infinite Scroll

Pagination settings live inside the `query` object on the loop element.

**Infinite scroll** — automatically loads next page when user scrolls to bottom:
```json
{
  "name": "container",
  "settings": {
    "hasLoop": true,
    "query": {
      "objectType": "post",
      "postType": ["post"],
      "postsPerPage": 9,
      "infinite_scroll": true,
      "infinite_scroll_margin": "200px"
    }
  }
}
```

**Load more button** — manual trigger via interactions:
```json
{
  "name": "button",
  "settings": {
    "text": "Load More",
    "_interactions": [{"trigger": "click", "action": "loadMore", "loadMoreQuery": "<loop-element-id>"}]
  }
}
```

**Note:** Infinite scroll and load more button are mutually exclusive. Choose one approach per query loop.

### Image Gallery Load More (Bricks 2.3+)

The Image Gallery element has its own load more system, separate from query loop pagination. It progressively reveals images already present in the gallery using a "Load more" control group.

#### Content Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `loadMoreInitial` | number | — | Images shown on initial load. Enables the load more system when set. Responsive (per-breakpoint values read on page load) |
| `loadMoreStep` | number | — | Images revealed per load. Empty or `0` reveals all remaining images. Requires `loadMoreInitial` |
| `loadMoreInfiniteScroll` | boolean | `false` | Auto-load more images when user scrolls near gallery end. Requires `loadMoreInitial` |
| `loadMoreInfiniteScrollDelay` | number | `600` | Delay in ms between scroll trigger and loading more items. Requires `loadMoreInfiniteScroll` |
| `loadMoreInfiniteScrollOffset` | number | `200` | Distance in px from gallery end at which to trigger loading. Requires `loadMoreInfiniteScroll` |

All properties are content properties (no underscore prefix).

#### Pattern: Gallery with Load More Button

```json
[
  {
    "id": "gallery1",
    "name": "image-gallery",
    "parent": "container1",
    "children": [],
    "settings": {
      "images": [{"id": 101, "url": "..."}, {"id": 102, "url": "..."}, {"id": 103, "url": "..."}],
      "loadMoreInitial": 4,
      "loadMoreStep": 4
    }
  },
  {
    "id": "btn1",
    "name": "button",
    "parent": "container1",
    "children": [],
    "settings": {
      "text": "Load More",
      "_interactions": [
        {
          "trigger": "click",
          "action": "loadMoreGallery",
          "loadMoreGalleryTarget": "gallery1"
        }
      ]
    }
  }
]
```

#### Pattern: Gallery with Infinite Scroll

```json
{
  "id": "gallery1",
  "name": "image-gallery",
  "settings": {
    "images": [{"id": 101, "url": "..."}, {"id": 102, "url": "..."}],
    "loadMoreInitial": 6,
    "loadMoreStep": 3,
    "loadMoreInfiniteScroll": true,
    "loadMoreInfiniteScrollDelay": 600,
    "loadMoreInfiniteScrollOffset": 200
  }
}
```

**Key differences from query loop load more:**
- Gallery load more reveals images already in the DOM (no server requests)
- Uses the `loadMoreGallery` interaction action (not `loadMore`)
- The `loadMoreGalleryTarget` points to the gallery element ID
- `loadMoreInitial` and `loadMoreStep` support responsive values per breakpoint
- Load more does not run inside the Bricks builder — only on the frontend

### Global Queries

Reusable named query configurations stored in `bricks_global_queries`. Reference a global query on any element using `query.id` instead of inline settings.

- Use `bricks:get_global_queries` to list available global queries
- Reference a global query: set `"query": {"id": "<global-query-id>"}` on the element
- Bricks resolves the global query settings at render time

```json
{
  "name": "container",
  "settings": {
    "hasLoop": true,
    "query": {"id": "abc123"}
  }
}
```

## Forms

Bricks forms are standard elements (name: `form`) with specific settings. Use `bricks:get_form_schema` for the full reference.

### Field Types

| Type | Description | Key Properties |
|------|-------------|----------------|
| `text` | Single-line text input | placeholder, required, minLength, maxLength, pattern |
| `email` | Email input with validation | placeholder, required |
| `textarea` | Multi-line text | placeholder, height, required |
| `richtext` | TinyMCE rich text editor | height |
| `tel` | Telephone input | placeholder, pattern |
| `number` | Numeric input | min, max, step |
| `url` | URL input | placeholder |
| `password` | Password with optional toggle | passwordToggle |
| `select` | Dropdown | options (newline-separated string) |
| `checkbox` | Checkbox group | options (newline-separated string) |
| `radio` | Radio group | options (newline-separated string) |
| `file` | File upload | fileUploadLimit, fileUploadSize, fileUploadAllowedTypes |
| `datepicker` | Date/time picker (Flatpickr) | time (bool), l10n |
| `image` | Image picker | (media library) |
| `gallery` | Gallery picker | (media library) |
| `hidden` | Hidden field | value |
| `html` | Static HTML (not an input) | |
| `rememberme` | Remember me checkbox | (for login forms) |

### Field Structure

Every field requires a unique `id` — a 6-character lowercase alphanumeric string (same format as element IDs):

```json
{
  "id": "abc123",
  "type": "email",
  "label": "Email Address",
  "placeholder": "you@example.com",
  "required": true,
  "width": 100
}
```

**Options format for select/checkbox/radio:** Use newline-separated strings, NOT arrays:
```json
{"options": "Option 1\nOption 2\nOption 3"}
```

For value:label pairs, set `valueLabelOptions: true`:
```json
{"options": "us:United States\nuk:United Kingdom\nde:Germany", "valueLabelOptions": true}
```

### Form Actions

Set `actions` array to control what happens on submit. Common actions:

| Action | Required Settings | Description |
|--------|-------------------|-------------|
| `email` | `emailSubject`, `emailTo` | Send email notification |
| `redirect` | `redirect` (URL) | Redirect after submit (always runs last) |
| `webhook` | `webhooks` array | POST data to external URL |
| `login` | `loginName`, `loginPassword` | User login |
| `registration` | `registrationEmail`, `registrationPassword` | User registration |
| `create-post` | `createPostType`, `createPostTitle` | Create a WordPress post |

### Contact Form Example

```json
{
  "name": "form",
  "settings": {
    "fields": [
      {"id": "abc123", "type": "text", "label": "Name", "placeholder": "Your Name", "width": 100},
      {"id": "def456", "type": "email", "label": "Email", "placeholder": "you@example.com", "required": true, "width": 100},
      {"id": "ghi789", "type": "textarea", "label": "Message", "placeholder": "Your Message", "required": true, "width": 100}
    ],
    "actions": ["email"],
    "emailSubject": "Contact form request",
    "emailTo": "admin_email",
    "htmlEmail": true,
    "successMessage": "Thank you! We will get back to you soon.",
    "submitButtonText": "Send Message"
  }
}
```

### Webhook Form Example

```json
{
  "name": "form",
  "settings": {
    "fields": [
      {"id": "usr001", "type": "text", "label": "Name", "required": true, "width": 50},
      {"id": "eml001", "type": "email", "label": "Email", "required": true, "width": 50}
    ],
    "actions": ["webhook", "redirect"],
    "webhooks": [
      {
        "name": "CRM Webhook",
        "url": "https://hooks.example.com/endpoint",
        "contentType": "json"
      }
    ],
    "redirect": "https://example.com/thank-you",
    "successMessage": "Form submitted!"
  }
}
```

### Key Form Gotchas

1. **Field IDs are required** — every field must have a unique 6-char alphanumeric `id`. Without it, Bricks cannot process submissions.
2. **Options are strings, not arrays** — select/checkbox/radio options use `"Option 1\nOption 2"` format.
3. **Redirect always runs last** — Bricks moves `redirect` to the end regardless of array position.
4. **CAPTCHA requires API keys** — `enableRecaptcha`, `enableHCaptcha`, `enableTurnstile` only work if keys are configured in Bricks > Settings > API Keys. Use honeypot (`isHoneypot: true` on a field) as a universal alternative.
5. **Never set `registrationRole` to `administrator`** — Bricks blocks this for security.

## Components

Components (Bricks 2.0+) are reusable element trees stored globally. They support properties (customizable inputs per instance) and slots (flexible content zones, Bricks 2.2+). All instances auto-update when the component definition changes.

### Component Definition Structure

```json
{
  "id": "abc123",
  "label": "Card Component",
  "category": "Cards",
  "description": "Reusable card with title and content slot",
  "elements": [
    {"id": "abc123", "name": "container", "parent": 0, "children": ["def456", "ghi789"], "settings": {}},
    {"id": "def456", "name": "heading", "parent": "abc123", "children": [], "settings": {"text": "Card Title"}},
    {"id": "ghi789", "name": "slot", "parent": "abc123", "children": [], "settings": {}}
  ],
  "properties": [
    {"id": "prop01", "name": "title", "type": "text", "default": "Card Title", "connections": {"def456": ["text"]}}
  ]
}
```

### Critical Rules

- **Root element ID MUST equal the component ID** — `elements[0].id === component.id`. Auto-enforced by `component:create`.
- **Component IDs** are 6-char alphanumeric (auto-generated, same format as element IDs).
- **Slot elements MUST use `name: "slot"`** — no other element type triggers slot behavior.
- **Properties need `connections`** to have any effect — without wiring, property values are ignored.

### Instance Element Structure

When you instantiate a component on a page, the instance is stored as a regular element:

```json
{
  "id": "xyz789",
  "name": "abc123",
  "cid": "abc123",
  "parent": "0",
  "children": [],
  "settings": {},
  "properties": {"prop01": "My Custom Title"},
  "slotChildren": {"ghi789": ["filla1", "fillb2"]}
}
```

Both `name` and `cid` equal the component ID. Property values override defaults via the `connections` map.

### Property Types

| Type | Value Format | Description |
|------|-------------|-------------|
| `text` | `"string"` | Text, textarea, or rich-text controls |
| `icon` | `{library, icon}` | Icon picker controls |
| `image` | `{id, url}` | Image controls |
| `gallery` | `[{id, url}, ...]` | Image gallery controls |
| `link` | `{url, type, newTab}` | Link controls |
| `select` | `"option_value"` | Select/radio controls |
| `toggle` | `"on"` or `"off"` | Toggle controls |
| `query` | `{query params}` | Query loop controls |
| `class` | `["class_id", ...]` | Global class pickers |

### Slot Fill Pattern

Filling a slot adds content elements to the page array and references them via `slotChildren`:

```
component:fill_slot with post_id, instance_id, slot_id, slot_elements

Before: instance.slotChildren = {}
After:  instance.slotChildren = {"ghi789": ["filla1", "fillb2"]}
```

Slot content elements are added to the page's flat element array with `parent = instance_id`. Use `component:fill_slot` — it handles both steps atomically.

## Popups

Bricks popups are templates with `type: "popup"`. Content is stored in `_bricks_page_content_2` (same as any template). Display behavior (close, backdrop, sizing, limits) is stored in `_bricks_template_settings` popup* keys — separate from element settings.

### Quick Start Workflow

1. **Create popup template:** `template:create` with `type: "popup"`, `title: "My Popup"`
2. **Add content:** `page:update_content` with elements (heading, text, button, form, etc.) on the popup template
3. **Configure behavior:** `template:set_popup_settings` with keys like `popupCloseOn`, `popupContentMaxWidth`, `popupLimitLocalStorage`
4. **Set trigger:** `element:update` on a button with `_interactions`:
   ```json
   [{"id": "abc123", "trigger": "click", "action": "show", "target": "popup", "templateId": <popup_id>}]
   ```
5. **Set page targeting:** `template_condition:set` to control which pages include the popup

### Trigger Patterns

**Click trigger** (button opens popup):
```json
{"id": "abc123", "trigger": "click", "action": "show", "target": "popup", "templateId": 456}
```

**Page load with delay** (auto-open after 2s):
```json
{"id": "def456", "trigger": "contentLoaded", "delay": "2s", "action": "show", "target": "popup", "templateId": 789}
```

**Scroll percentage** (open at 50% scroll):
```json
{"id": "ghi789", "trigger": "scroll", "scrollOffset": "50%", "action": "show", "target": "popup", "templateId": 456}
```

**Exit intent** (mouse leaves window, fire once):
```json
{"id": "jkl012", "trigger": "mouseleaveWindow", "action": "show", "target": "popup", "templateId": 456, "runOnce": true}
```

### Key Settings Reference

| Setting | Type | Description |
|---------|------|-------------|
| `popupCloseOn` | string | `'backdrop'`=click only, `'esc'`=key only, `'none'`=disabled. Unset=both. |
| `popupContentMaxWidth` | number+unit | Max-width of content box (e.g. `"600px"`) |
| `popupContentPadding` | spacing object | Padding inside content box (default 30px all sides) |
| `popupBackground` | background object | Backdrop background (color, image, gradient) |
| `popupLimitLocalStorage` | number | Max times shown across sessions (localStorage) |
| `popupDisableBackdrop` | boolean | Remove backdrop for floating/sticky popups |
| `popupBodyScroll` | boolean | Allow body scroll when popup open |
| `popupAjax` | boolean | Load content via AJAX (Post/Term/User context only) |

Use `bricks:get_popup_schema` for the complete settings reference with all keys, types, and defaults.

### Common Patterns

**Marketing popup** (newsletter modal with display limit):
```
template:create — type=popup, title="Newsletter Modal"
template:set_popup_settings — popupContentMaxWidth="500px", popupCloseOn="backdrop", popupLimitLocalStorage=1
page:update_content — add heading + text + form elements to popup template
template_condition:set — conditions for all pages
```

**Confirmation dialog** (no backdrop close, ESC only):
```
template:set_popup_settings — popupCloseOn="esc", popupContentMaxWidth="400px", popupDisableAutoFocus=false
```

**Lightbox image viewer** (AJAX content, full backdrop):
```
template:set_popup_settings — popupAjax=true, popupContentMaxWidth="900px", popupContentBackground={"color": {"hex": "#000000"}}
```

### Important Notes

- **Settings vs triggers:** `_bricks_template_settings` stores display behavior. `_interactions` on elements stores open/close triggers. These are separate systems.
- **Conditions vs triggers:** Template conditions control WHICH PAGES include the popup in the footer. Interactions control WHEN it opens. Both are needed.
- **popupCloseOn values:** Unset = both backdrop AND ESC close the popup. Set `'backdrop'` for click-only, `'esc'` for key-only, `'none'` to disable. Never pass `'both'`.
- **Use `bricks:get_popup_schema`** for the full settings reference with all keys organized by category.
- **Use `bricks:get_interaction_schema`** for the full trigger/action reference for opening/closing popups.

## Element Conditions & Visibility

Control which elements render based on runtime context. Conditions are server-side — elements with failing conditions are not sent to the browser at all.

**Important:** Element conditions (`_conditions` in element settings) are different from template conditions (`template_condition` tool). Template conditions control which pages a template targets. Element conditions control whether an individual element renders on that page.

### Data Structure

Conditions use AND/OR logic via nested arrays:
- **Outer array** = condition SETS (OR logic — any set passing renders the element)
- **Inner arrays** = conditions within a set (AND logic — all must pass)

```json
{
  "_conditions": [
    [
      {"key": "user_logged_in", "compare": "==", "value": "1"},
      {"key": "user_role", "compare": "==", "value": ["administrator"]}
    ],
    [
      {"key": "featured_image", "compare": "==", "value": "1"}
    ]
  ]
}
```
This means: show if (logged in AND admin) OR (has featured image).

### Common Patterns

**Logged-in users only:**
```json
{"_conditions": [[{"key": "user_logged_in", "compare": "==", "value": "1"}]]}
```

**Specific roles:**
```json
{"_conditions": [[{"key": "user_role", "compare": "==", "value": ["administrator", "editor"]}]]}
```

**Business hours (Mon-Fri 9am-5pm):**
```json
{"_conditions": [[
  {"key": "weekday", "compare": ">=", "value": "1"},
  {"key": "weekday", "compare": "<=", "value": "5"},
  {"key": "time", "compare": ">=", "value": "09:00"},
  {"key": "time", "compare": "<=", "value": "17:00"}
]]}
```

**Dynamic data (ACF field check):**
```json
{"_conditions": [[{"key": "dynamic_data", "compare": "==", "value": "1", "dynamic_data": "{acf_show_banner}"}]]}
```
Note: The `dynamic_data` key type requires a separate `dynamic_data` field for the tag and uses `value` for the comparison target.

### Workflow

1. Call `bricks:get_condition_schema` to see available condition types and examples
2. Call `element:get_conditions` to read existing conditions on an element
3. Call `element:set_conditions` with the full conditions array to set/replace conditions
4. To clear all conditions, pass an empty array: `element:set_conditions` with `conditions: []`

### Validation

- Unknown condition keys are saved with a warning (3rd-party plugins may add custom keys)
- Unknown user roles are rejected with an error
- Missing `dynamic_data` field on `dynamic_data` conditions triggers a warning
- Conditions must be properly nested: array of arrays of condition objects

## WooCommerce

WooCommerce integration requires WooCommerce plugin active. All WooCommerce tools and elements are unavailable without it. Use `woocommerce:status` to check.

### Template Types

Bricks supports 8 WooCommerce template types. Create via `template:create` with these type slugs:

| Type Slug | Purpose | Auto-Condition |
|-----------|---------|----------------|
| `wc_product` | Single product page | WooCommerce single product |
| `wc_archive` | Shop/category/tag archive | WooCommerce product archive |
| `wc_cart` | Cart (with items) | WooCommerce cart page |
| `wc_cart_empty` | Empty cart | WooCommerce empty cart |
| `wc_checkout` | Checkout page | WooCommerce checkout page |
| `wc_account_form` | Login/register (logged out) | WooCommerce account login/register |
| `wc_account_page` | My Account (logged in) | WooCommerce my account page |
| `wc_thankyou` | Order confirmation | WooCommerce thank you page |

Use `woocommerce:scaffold_template` instead of `template:create` for pre-populated templates with standard elements already placed.

### Single Product Template Pattern

Standard single product layout — gallery left, info right, tabs below, related products at bottom:

```json
{
  "elements": [
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "settings": { "_direction": "row", "_justifyContent": "space-between" },
          "children": [
            {
              "name": "container",
              "settings": { "_width": "50%" },
              "children": [
                { "name": "product-gallery", "settings": {} }
              ]
            },
            {
              "name": "container",
              "settings": { "_width": "45%" },
              "children": [
                { "name": "woocommerce-breadcrumbs", "settings": {} },
                { "name": "product-title", "settings": {} },
                { "name": "product-rating", "settings": {} },
                { "name": "product-price", "settings": {} },
                { "name": "product-short-description", "settings": {} },
                { "name": "product-add-to-cart", "settings": {} },
                { "name": "product-meta", "settings": {} }
              ]
            }
          ]
        }
      ]
    },
    {
      "name": "section",
      "children": [
        { "name": "container", "children": [
          { "name": "product-tabs", "settings": {} }
        ]}
      ]
    },
    {
      "name": "section",
      "children": [
        { "name": "container", "children": [
          { "name": "product-upsells", "settings": {} },
          { "name": "product-related", "settings": {} }
        ]}
      ]
    }
  ]
}
```

### Cart Template Pattern

```json
{
  "elements": [
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "children": [
            { "name": "woocommerce-notice", "settings": {} },
            { "name": "heading", "settings": { "tag": "h1", "text": "Shopping Cart" } }
          ]
        }
      ]
    },
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "settings": { "_direction": "row" },
          "children": [
            {
              "name": "container",
              "settings": { "_width": "65%" },
              "children": [
                { "name": "cart-items", "settings": {} },
                { "name": "cart-coupon", "settings": {} }
              ]
            },
            {
              "name": "container",
              "settings": { "_width": "30%" },
              "children": [
                { "name": "cart-totals", "settings": {} }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

### Checkout Template Pattern

```json
{
  "elements": [
    {
      "name": "section",
      "children": [
        { "name": "container", "children": [
          { "name": "woocommerce-notice", "settings": {} },
          { "name": "heading", "settings": { "tag": "h1", "text": "Checkout" } },
          { "name": "checkout-login", "settings": {} },
          { "name": "checkout-coupon", "settings": {} }
        ]}
      ]
    },
    {
      "name": "section",
      "children": [
        {
          "name": "container",
          "settings": { "_direction": "row" },
          "children": [
            {
              "name": "container",
              "settings": { "_width": "60%" },
              "children": [
                { "name": "checkout-customer-details", "settings": {} }
              ]
            },
            {
              "name": "container",
              "settings": { "_width": "35%" },
              "children": [
                { "name": "checkout-order-review", "settings": {} }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

### WooCommerce Elements Reference

**Single Product:**
`product-gallery`, `product-title`, `product-price`, `product-short-description`, `product-content`, `product-add-to-cart`, `product-stock`, `product-meta`, `product-rating`, `product-reviews`, `product-tabs`, `product-additional-information`, `product-upsells`, `product-related`

**Cart:**
`cart-items`, `cart-totals`, `cart-coupon`

**Checkout:**
`checkout-customer-details`, `checkout-order-review`, `checkout-coupon`, `checkout-login`, `checkout-thankyou`, `checkout-order-table`, `checkout-order-payment`

**Account:**
`account-page`, `account-login-form`, `account-register-form`, `account-lost-password`, `account-reset-password`, `account-orders`, `account-view-order`, `account-downloads`, `account-addresses`, `account-edit-address`, `account-edit-account`

**Archive/Shop:**
`products` (main product grid), `products-filter`, `products-pagination`, `products-orderby`, `products-total-results`, `products-archive-description`

**Utility:**
`woocommerce-notice` (REQUIRED on cart, checkout, account templates for form feedback), `woocommerce-breadcrumbs`

**Important:** Element names listed above are based on Bricks documentation. Actual registered names may vary slightly. Use `woocommerce:get_elements` to discover exact names at runtime.

### Dynamic Data Tags

Use in any text field with curly braces. Common WooCommerce tags:

| Tag | Returns | Context |
|-----|---------|---------|
| `{woo_product_price}` | Full price (sale + regular) | Product templates |
| `{woo_product_regular_price}` | Regular price | Product templates |
| `{woo_product_sale_price}` | Sale price (empty if not on sale) | Product templates |
| `{woo_product_sku}` | SKU string | Product templates |
| `{woo_product_stock}` | Stock text | Product templates |
| `{woo_product_rating}` | Star rating | Product templates |
| `{woo_product_images}` | Featured + gallery | Product templates |
| `{woo_add_to_cart}` | Add to cart button | Product templates |
| `{woo_product_on_sale}` | Sale badge | Product templates |

**Modifiers:** Append `:plain` (no HTML), `:value` (numeric only), `:format` (force display). Example: `{woo_product_regular_price:value}` returns `29.99`.

Use `woocommerce:get_dynamic_tags` for the complete categorized reference including cart, order, and hook tags.

### Variation Swatches

Native Bricks feature (2.0+). Enable in Bricks > Settings > WooCommerce > Enable product variation swatches.

Swatch types: Color, Image, Label (configured per product attribute at Products > Attributes).

Swatches render automatically inside the `product-add-to-cart` element — no separate swatch element needed. Style via the "Variation swatches" settings group in the Add to Cart element (size, spacing, borders, active states, tooltips).

### WooCommerce Notices

**Critical:** When using Bricks WooCommerce notices (enabled in Bricks > Settings > WooCommerce), you MUST manually place the `woocommerce-notice` element on every template that has form submissions:
- Cart (add/remove/update feedback)
- Checkout (validation errors, payment feedback)
- Account login/register (auth errors)
- Single product (add to cart confirmation)

Only ONE notice element per page is needed. If multiple are placed, only the first outputs notices.

### WooCommerce Conditions (Element Visibility)

Use `element:set_conditions` with WooCommerce condition keys to show/hide elements based on product state:

| Key | Description | Values |
|-----|-------------|--------|
| `woo_product_sale` | Product on sale | "1" (yes) / "0" (no) |
| `woo_product_stock_status` | Stock status | instock / outofstock / onbackorder |
| `woo_product_featured` | Featured product | "1" / "0" |
| `woo_product_type` | Product type | simple / grouped / external / variable |
| `woo_product_new` | New product | "1" / "0" |

See `bricks:get_condition_schema` for the full WooCommerce conditions reference.

### WooCommerce Gotchas

- **Gallery zoom/lightbox are GLOBAL settings**, not element settings. Controlled by `woocommerceDisableProductGalleryLightbox` and `woocommerceDisableProductGalleryZoom` in Bricks > Settings > WooCommerce.
- **Template conditions are REQUIRED.** A WooCommerce template without conditions won't display on the frontend. Always assign the matching condition (e.g., `wc_product` condition for single product template).
- **Notice element is MANDATORY.** Without it, form submissions on cart/checkout show no feedback.
- **Element names may differ from documentation.** Always verify with `woocommerce:get_elements` or `bricks:get_element_schemas(catalog_only=true)`.
- **Products are posts.** Standard post dynamic data tags (`{post_title}`, `{post_id}`, `{post_terms_product_cat}`) work in product templates.
- **AJAX add-to-cart is a global setting** (`woocommerceEnableAjaxAddToCart`), not an element setting.

## SEO Optimization

### SEO Plugin Detection

Most WordPress sites use a dedicated SEO plugin that overrides Bricks native SEO fields. The `page:get_seo` action automatically detects the active SEO plugin and returns data from the correct source. The response `seo_plugin` field tells you which plugin is active: `yoast`, `rankmath`, `seopress`, `slimseo`, or `bricks`.

When `plugin_active: true`, always use `page:update_seo` for SEO changes -- `page:update_settings` only writes to Bricks native fields which are ignored when an SEO plugin is active. When `plugin_active: false` (Bricks native), both `page:update_seo` and `page:update_settings` work for SEO fields.

Detection priority when multiple plugins are installed: Yoast > Rank Math > SEOPress > Slim SEO > Bricks native.

### SEO Workflow

1. Read current SEO state: `page:get_seo` with `post_id`
2. Check the `audit` block for quality issues (title length, description length, missing OG image)
3. Update fields: `page:update_seo` with `post_id` and any normalized field names

Normalized fields: `title`, `description`, `robots_noindex` (boolean), `robots_nofollow` (boolean), `canonical`, `og_title`, `og_description`, `og_image`, `twitter_title`, `twitter_description`, `twitter_image`, `focus_keyword`.

Not all plugins support all fields. The response `unsupported_fields` object lists which fields the active plugin does not support and why.

### SEO Audit Thresholds

The `audit` block in `page:get_seo` response provides quality metrics:

- Title: 30-60 characters optimal. Issues: `missing`, `too_short`, `too_long`, or `null` (ok)
- Description: 120-160 characters optimal. Same issue values as title
- OG image: `has_og_image` boolean. Recommended size 1200x630px

### Plugin-Specific Notes

- **Yoast**: Supports all fields including `focus_keyword`. Full OG and Twitter card support.
- **Rank Math**: Supports all core fields including `focus_keyword`. OG/Twitter titles and descriptions use the main title/description (separate OG title/description fields are unsupported).
- **SEOPress**: Supports all fields except `focus_keyword`. Full OG and Twitter card support.
- **Slim SEO**: Only supports `title`, `description`, and `canonical`. No per-post OG, robots, Twitter, or focus keyword fields -- these are handled globally in plugin settings.
- **Bricks native**: Supports title, description, keywords, robots, OG title/description/image. No canonical, no Twitter-specific fields, no focus keyword. Only effective when no SEO plugin is active.

### Common Mistake

Do NOT use `page:update_settings` with keys like `documentTitle` or `metaDescription` when an SEO plugin is active. Those Bricks native fields are ignored by the rendered page because the SEO plugin takes over the HTML head output. Always call `page:get_seo` first to detect the active plugin, then use `page:update_seo` for any SEO changes.

## Custom Code

### Page Custom CSS

Use `code:get_page_css` with `post_id` to read the page's custom CSS and script status. Use `code:set_page_css` with `post_id` and `css` to set the page's custom CSS.

CSS is scoped to the page. Use `#brxe-{elementId}` selectors to target specific Bricks elements. Send an empty string for `css` to remove all custom CSS from the page. Custom CSS write is license-gated.

### Page Custom Scripts

Use `code:set_page_scripts` to add JavaScript to specific page locations:

- `header` -- injected in `<head>` (for tracking pixels, meta tags, early-load scripts)
- `body_header` -- after opening `<body>` tag (for analytics, tag managers)
- `body_footer` -- before closing `</body>` tag (for deferred scripts, tracking)

**SECURITY: Requires Dangerous Actions toggle** enabled in Bricks MCP settings. Scripts execute on every page load -- test carefully.

Use `code:get_page_scripts` with `post_id` to read existing scripts for a page.

### Element Custom CSS

Elements support `_customCss` in their settings for element-scoped CSS. Use `#brxe-{elementId}` as the selector (NOT `%root%` which only works in the visual editor):

```json
{"_customCss": "#brxe-abc123 { box-shadow: 0 4px 6px rgba(0,0,0,0.1); }"}
```

### Important Notes

- Custom CSS requires Bricks-specific selectors (`#brxe-{id}`) -- standard CSS class selectors may not have sufficient specificity
- Script writing requires the Dangerous Actions toggle in Bricks MCP settings (security measure)
- Prefer Bricks native styling (`_padding`, `_margin`, `_typography`, etc.) over custom CSS when possible
- For site-wide styles, use theme styles (`theme_style` tool) or global classes (`global_class:create`) instead of page CSS
- For global custom CSS, use `page:update_settings` with the `customCss` key on the relevant page

## Font Management

### Font Sources in Bricks

Bricks supports three font sources: **Google Fonts** (enabled by default), **Adobe Fonts** (requires project ID), and **system fonts** (always available).

Use `font:get_status` to check which font sources are available and the current loading strategy.

- Google Fonts can be disabled for performance via `font:update_settings` with `disable_google_fonts: true`
- Adobe Fonts require a project ID configured via `bricks:update_settings` (integrations category, `adobeFontsProjectId` key)
- System fonts are always available: Arial, Helvetica, Georgia, Times New Roman, Courier New, Verdana, system-ui, etc.

### Applying Fonts to Elements

Set font family via the `_typography` style property on any element:

```json
{"_typography": {"font-family": "Inter", "font-weight": "400"}}
```

For responsive fonts use breakpoint suffixes:

```json
{"_typography:tablet_portrait": {"font-family": "system-ui"}}
```

Google Fonts are auto-loaded when referenced in element settings. No additional configuration needed.

### Applying Fonts in Theme Styles

Use `theme_style:update` with the typography group to set site-wide font defaults:

```json
{"styles": {"typography": {"font-family": "Inter", "font-weight": "400"}}}
```

Theme style fonts apply globally to headings, body text, buttons, and other text elements.

### Font Loading Strategy

Use `font:update_settings` with `webfont_loading` to control how web fonts display:

- **swap** (default) — show fallback font immediately, swap when loaded. Best for performance.
- **block** — hide text until font loads. Best for brand-critical headings.
- **optional** — like swap but may keep fallback if load is slow. Best for body text.
- **fallback** — short block period, then show fallback. Balanced approach.
- **auto** — browser default behavior.

### Adobe Fonts

1. Set project ID via `bricks:update_settings` (integrations category, `adobeFontsProjectId` key)
2. Use `font:get_adobe_fonts` to see cached fonts from your project
3. Reference Adobe fonts by family name in `_typography` settings, same as any other font

## Import & Export

Move templates and design systems between Bricks sites programmatically.

### Template Export

Use `template:export` with a `template_id` to get the full template as Bricks-compatible JSON. The export includes title, templateType, content (element array), pageSettings, and templateSettings.

Set `include_classes: true` to bundle the global classes referenced by the template's elements. Only classes actually used in the template are included (not all site classes).

Export is a read-only operation (no license required).

### Template Import

Use `template:import` with a `template_data` object containing at minimum:
- `title` (string) — the template name
- `content` (array) — Bricks element array

Optional fields: `templateType` (defaults to "section"), `pageSettings`, `templateSettings`, `globalClasses`.

Element IDs are automatically regenerated on import to prevent collisions with existing content. If `globalClasses` are included, they are merged by name: existing classes on the target site are preserved, only new classes are added.

Import always creates a new published template. It never overwrites existing templates. Import requires a license (write operation).

### Remote Template Import

Use `template:import_url` with a `url` pointing to a public URL that returns valid Bricks template JSON. Maximum response size is 10MB. The URL must return HTTP 200 with valid JSON matching the Bricks export format.

Import from URL requires a license (write operation).

### Cross-Site Template Workflow

1. **Export** on source site: `template:export` with `template_id` and `include_classes: true`
2. **Copy** the JSON response
3. **Import** on target site: `template:import` with the copied JSON as `template_data`

Alternatively, host the exported JSON at a public URL and use `template:import_url` on the target site.

### Global Class Export/Import

Use `global_class:export` to get all global classes with their full styles and categories as portable JSON. Filter by category with the `category` parameter.

Use `global_class:import_json` with `classes_data` to merge imported classes. Classes are matched by name: existing classes are never overwritten, only new classes are added with auto-generated IDs. Categories from the import data are also merged.

Export is read-only (no license). Import requires a license (write operation).

### Important Notes

- Exported templates include element content and Bricks metadata but NOT media files (images are referenced by URL and must be accessible on the target site)
- Global class import is additive only — it never modifies or deletes existing classes
- Template type defaults to "section" if not specified in import data
- For bulk operations, call export/import multiple times (one template per call)

## Common Workflows

### Build a Full Page

1. Call `create_bricks_page` with full element tree (nested format)
2. Verify output visually or via `get_bricks_content`
3. Refine with parallel `update_element` calls
4. Add animations via `update_element` with `_interactions`

### Add a Section to an Existing Page

1. `get_bricks_content` — see current elements and IDs
2. `add_element` with `parent_id` and `position` for placement
3. Add child elements using the new container as parent

### Restyle a Page

1. `get_bricks_content` — get all element IDs and current settings
2. Fire parallel `update_element` calls (each element is independent)
3. For properties that don't generate CSS, use `_cssCustom` with `#brxe-{id}`

## Tool Quick Reference

**Bricks:** `bricks:enable`, `bricks:disable`, `bricks:get_settings`, `bricks:get_breakpoints`, `bricks:get_element_schemas`, `bricks:get_dynamic_tags`, `bricks:get_query_types`, `bricks:get_form_schema`, `bricks:get_interaction_schema`, `bricks:get_component_schema`, `bricks:get_popup_schema`

**Pages:** `page:list`, `page:search`, `page:get`, `page:create`, `page:update_content`, `page:update_meta`, `page:delete`, `page:duplicate`

**Elements:** `element:add`, `element:update`, `element:remove`, `element:get_conditions`, `element:set_conditions` [license]

**Templates:** `template:list`, `template:get`, `template:create`, `template:update`, `template:delete`, `template:duplicate`, `template:get_popup_settings`, `template:set_popup_settings` [license]

**Template Conditions:** `template_condition:types`, `template_condition:set`, `template_condition:resolve`

**Template Taxonomies:** `template_taxonomy:list_tags`, `template_taxonomy:list_bundles`, `template_taxonomy:create_tag`, `template_taxonomy:create_bundle`, `template_taxonomy:delete_tag`, `template_taxonomy:delete_bundle`

**Components:** `component:list`, `component:get`, `component:create` [license], `component:update` [license], `component:delete` [license], `component:instantiate` [license], `component:update_properties` [license], `component:fill_slot` [license]

**Other:** `get_site_info`, `wordpress`, `get_builder_guide`

## Key Gotchas

1. **`_textAlign` does nothing** — put `text-align` inside `_typography` instead
2. **`%root%` does nothing via API** — use `#brxe-{elementId}` in `_cssCustom`
3. **`_maxWidth` does not exist** — use `_widthMax` for `max-width`. **`_gap` and `_display` work in Bricks 2.3+ but only on non-layout elements** (not section/container/block/div — those are always flex). For gap on layout elements, use `_cssCustom`.
4. **Templates must be `publish` status** to be active in Bricks
5. **Icon libraries:** `Ionicons`, `FontAwesome`, `Themify` — check `get_element_schemas` if unsure
6. **Text supports HTML:** `"text": "<strong>Bold</strong> and normal"`
7. **`update_element` calls are independent** — always fire them in parallel for speed
8. **Background gradients:** `{"_background": {"gradient": "linear-gradient(135deg, #667eea 0%, #764ba2 100%)"}}`
9. **Dynamic tags need context** — `{post_title}`, `{post_url}` only work inside query loops or single post templates. On static pages they render empty.
10. **Image vs text dynamic data format** — text fields use bare `{tag}`, image fields use `{"useDynamicData": "{tag}"}`, links use `{"type": "dynamic", "dynamicData": "{tag}"}`
11. **`_animation` is deprecated** — `_animation`, `_animationDuration`, `_animationDelay` are deprecated since Bricks 1.6. Always use the `_interactions` array. Bricks shows a converter warning for deprecated keys.
12. **Component instance `name` = component ID** — the element `name` for a component instance is the 6-char component ID (e.g., `"abc123"`), not a human-readable type like `"card"`. Both `name` and `cid` must equal the component ID.
13. **Properties without `connections` do nothing** — defining properties on a component without setting the `connections` map means instance property values are stored but never applied to any element setting.
14. **Slot content lives in the page array, not the component definition** — slot filler elements are stored as regular elements in the page's flat array with `parent = instance_id`. The component definition only contains the slot placeholder element (`name: "slot"`).
15. **Popup triggers are NOT popup settings** — triggers use `_interactions` on elements (click, scroll, exit intent). `_bricks_template_settings` only stores display behavior (close, backdrop, sizing, limits). These are separate systems managed by different tools.
16. **`toggle-mode` needs dark mode colors** — The toggle element only works if dark mode variants are configured in the Bricks color manager. Without them, the button renders but does nothing.
17. **Builder paste/import behavior settings** — `builderHtmlCssConverter` controls HTML/CSS paste conversion (`confirm`|`enabled`|`disabled`), `builderGlobalClassesImport` controls global class auto-import on paste (`confirm`|`enabled`|`disabled`). Both default to `confirm`. Read via `bricks:get_settings` with `category: builder`.

## Connection Troubleshooting

If you're having trouble connecting to the MCP server, work through these checks in order:

### 1. HTTPS Required
Application Passwords require HTTPS by default. If your site doesn't use HTTPS:
- Enable SSL via your hosting provider
- If behind a reverse proxy (Cloudflare, load balancer), ensure `X-Forwarded-Proto: https` header is set
- As a last resort: `add_filter( 'wp_is_application_passwords_available', '__return_true' );` in your theme's functions.php

### 2. Application Passwords Disabled
Some plugins disable Application Passwords:
- **Disable Application Passwords** plugin: Deactivate it
- **iThemes/Solid Security**: Check Settings > Advanced > Application Passwords
- **WP Cerber**: Check Security Rules > Application Passwords
- **Custom code**: Search mu-plugins/ for `wp_is_application_passwords_available`

### 3. REST API Blocked
Security plugins commonly block the REST API for unauthenticated users:
- **Perfmatters**: Add `bricks-mcp` to Settings > REST API > Allowed Routes
- **All In One WP Security**: Disable Firewall > PHP Firewall Rules > REST API
- **Disable WP REST API** plugin: Deactivate or whitelist `bricks-mcp`
- **WP Cerber**: Check Hardening > Disable REST API

Note: Blocking REST API for *unauthenticated* users usually doesn't affect MCP because clients authenticate via Application Passwords. But if Application Passwords are also broken, both issues compound.

### 4. Permalink Structure
The REST API requires pretty permalinks. Go to Settings > Permalinks and select any structure other than "Plain".

### 5. Hosting-Specific Issues
- **WP Engine**: May strip Authorization headers. Add to .htaccess: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
- **Kinsta**: REST API works by default. If blocked, check Kinsta security settings.
- **Flywheel**: May strip Authorization headers. Same .htaccess fix as WP Engine.
- **Pantheon**: May disable Application Passwords by default. Add `add_filter( 'wp_is_application_passwords_available', '__return_true' );` to wp-config.php.
- **Cloudflare**: WAF rules may block /wp-json/ traffic. Add a WAF exception for the bricks-mcp/v1/ path.
- **Server-level rate limiting**: If diagnostics pass but external AI tools get 429 errors, check Cloudflare/CDN rate limits on /wp-json/.

### 6. Run Full Diagnostics
Use the built-in diagnostic tool from your AI client:
```
get_site_info(action: 'diagnose')
```
Or click "Run Diagnostics" on the Bricks MCP settings page in WordPress admin.
