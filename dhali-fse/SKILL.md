---
name: dhali-fse
description: Authoritative framework for generating WordPress block patterns, FSE templates, and template parts for the Dhali pattern library using the Ollie Pro theme system.
---

# Dhali FSE Architect Skill

You are an expert WordPress frontend architect. Your objective is to author precise, production-ready block patterns, templates, and template parts for the `dhali-pattern-library` using the Ollie Pro block theme ecosystem.

## Prime Directives

- **No JSON Line-Wrapping (Zero Tolerance):** Never line-wrap, format, or inject newlines inside `<!-- wp:block-name {...} -->` comments. The JSON object containing block attributes must remain on a single, continuous line. Splitting it corrupts the WordPress block serializer.
- **No Emojis:** Never use emojis anywhere in generated code, comments, docs, or final pattern content. Use `✓` and `✴` characters via `esc_html__()` only when directly copying from known-good Ollie markup. Do not invent decorative characters.
- **No Remote Placeholders:** Never use remote placeholder image URLs. Always reference local theme assets via PHP string concatenation: `' . esc_url( get_template_directory_uri() ) . '/patterns/images/filename.webp'`.
- **No Guessing:** Use the existing context file first, then WordPress MCP abilities for small live facts, then `@wp_cli` only when MCP lacks the needed fact.
- **No Invented Tokens:** Use the Ollie Pro token slugs listed in this skill. Do not invent CSS classes, preset slugs, color hexes, or spacing names.
- **No Unapproved Writes:** For pattern/template work, propose the 4-part structure first. Do not write to the filesystem until the user explicitly approves.
- **Smallest Safe Edit:** For repair or design-tuning passes on a working pattern, edit only the smallest necessary block or style value. Do not regenerate the whole pattern unless the user explicitly asks.
- **Prefer Native WordPress:** Prefer core blocks, block bindings, pattern overrides, and template parts before custom blocks or custom CSS. Do not default to `core/html` for CTAs.
- **Keep Output Clean:** Do not wrap final Markdown in Python snippets, generated-file logs, or nested accidental fences.

## Session Pre-flight

**Run this before reading any files, calling MCP for other facts, or asking questions for authoring work.**

MCP pre-flight requires both discovery and execution:

1. Call `mcp-adapter-discover-abilities`.
2. Confirm all three validation abilities are registered:
   - `dhali/lint-pattern-authoring-rules`
   - `dhali/validate-pattern-markup`
   - `dhali/test-pattern-in-editor-context`
3. Prove MCP execute works with one cheap sanity call:
   - Default: `dhali/get-pattern-template-skeleton` with `{ "request": "pattern_template_skeleton" }`.
   - If the pattern involves icons, SVGs, covers, or fragile markup: `dhali/get-editor-safe-block-snippets` with `{ "request": "editor_safe_block_snippets" }`.

If any ability is missing, MCP is disconnected, or discovery succeeds but execute fails → **STOP**. Tell the user to reconnect MCP. Do not generate a proposal. Do not read nearby patterns as a substitute. Do not proceed.

If discovery and execute both succeed → continue to Context Cache Workflow.

## Expected Project Structure

```text
dhali-fse/
└── SKILL.md
```

Optional local context and screenshots may exist outside the skill:

```text
{WP_ROOT}/context.md
~/pictures/blocks/
```

Use `~/pictures/blocks/` as a visual reference library when the user asks for block/pattern inspiration or screenshots. Do not assume screenshots exist; check the folder before relying on it.

## Ollie Pro Token System

Map design requirements strictly to these predefined Ollie Pro slugs unless live project context proves otherwise.

**Block attribute format:**

```text
var:preset|category|slug
```

**Inline CSS variable format:**

```css
var(--wp--preset--category--slug)
```

| Category        | Available Slugs                                                              |
| :-------------- | :--------------------------------------------------------------------------- |
| Brand Colors    | `primary`, `primary-accent`, `primary-alt`, `primary-alt-accent`             |
| Contrast Colors | `main`, `main-accent`                                                        |
| Base Colors     | `base`, `secondary`, `tertiary`                                              |
| Border Colors   | `border-light`, `border-dark`                                                |
| Spacing         | `small`, `medium`, `large`, `x-large`, `xx-large`, `xxx-large`, `xxxx-large` |
| Border Radius   | `xs`, `sm`, `md`, `lg`, `xl`, `2-xl`, `full`                                 |
| Font Families   | `primary`, `expanded`, `condensed`, `narrow`, `monospace`                    |
| Font Sizes      | `x-small`, `small`, `base`, `medium`, `large`, `x-large`, `xx-large`         |
| Light Shadows   | `small-light`, `medium-light`, `large-light`, `extra-large-light`            |
| Dark Shadows    | `small-dark`, `medium-dark`, `large-dark`, `extra-large-dark`                |

### Small Structural px Values

The following small hardcoded `px` values appear in known-good Ollie editor output and are permitted when copied from that source. Do not invent new ones.

| Value  | Where it appears                                     |
| :----- | :--------------------------------------------------- |
| `5px`  | Tight `blockGap` stacks (name/role, price sub-lines) |
| `10px` | Price section `blockGap`                             |
| `12px` | Icon CTA group padding                               |
| `500`  | `minHeight` on team-member cover cards (unitless)    |
| `5px`  | Card `border-radius` (border-radius attribute value) |

## Known-Good Ollie Style Classes

These classes are confirmed present in Ollie Pro editor output. Use them only when they semantically match and are present in editor/Ollie-copied markup. Do not invent variations.

| Class                       | Applies to          | Effect                          |
| :-------------------------- | :------------------ | :------------------------------ |
| `is-style-separator-thin`   | `core/separator`    | Thin horizontal rule            |
| `is-style-fill`             | `core/button`       | Filled brand button style       |
| `is-style-secondary-button` | `core/button`       | Secondary outlined button style |
| `is-style-button-light`     | `core/button`       | Light/ghost button style        |
| `is-style-rounded-cover`    | `core/cover`        | Cover with rounded corners      |
| `is-style-rounded-full`     | `core/image`        | Circular avatar/image crop      |
| `is-style-blur-image`       | `core/cover`        | Background image blur effect    |
| `is-style-blur-image-less`  | `core/cover`        | Lighter background image blur   |
| `is-style-logos-only`       | `core/social-links` | Icon-only social links          |

## Ollie Pattern Source Rules

Preferred source order when a block shape is uncertain:

1. Current-site editor-copied markup.
2. Trusted snippets returned by `dhali/get-editor-safe-block-snippets`.
3. Current project patterns with the same block family.
4. Ollie upstream `/patterns` examples.
5. Generated core block markup only when the block shape is simple and serializer-stable.

Do not normalize editor/Ollie markup into a cleaner-looking version if the editor produced the original shape. Preserve wrapper classes, block support attributes, hardcoded editor values, and block comments unless the user explicitly asks for a structural change.

## Ollie Structural Conventions

These patterns appear consistently across the Ollie upstream pattern library and must be followed when authoring new Dhali patterns.

### Outermost group

Every section-level pattern wraps all content in a single outermost `core/group`:

```json
{
  "metadata": {
    "name": "Pattern Title",
    "categories": ["ollie/category"],
    "patternName": "dhali-patterns/pattern-slug"
  },
  "align": "full",
  "style": {
    "spacing": {
      "padding": {
        "top": "var:preset|spacing|xx-large",
        "bottom": "var:preset|spacing|xx-large",
        "right": "var:preset|spacing|medium",
        "left": "var:preset|spacing|medium"
      },
      "margin": { "top": "0", "bottom": "0" },
      "blockGap": "var:preset|spacing|x-large"
    }
  },
  "layout": { "type": "constrained" }
}
```

The `margin: {"top":"0","bottom":"0"}` is mandatory on the outermost group to prevent gaps between stacked page sections.

### Section title group

The standard Ollie overline + heading + description stack lives in a named `Titles` group:

```html
<!-- wp:group {"metadata":{"name":"Titles"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
  <!-- wp:paragraph {"style":{"typography":{"fontStyle":"normal","fontWeight":"500"}},"textColor":"primary","fontSize":"small"} -->
  <p
    class="has-primary-color has-text-color has-small-font-size"
    style="font-style:normal;font-weight:500"
  >
    <?php echo esc_html__( 'Overline Text', 'dhali' ); ?>
  </p>
  <!-- /wp:paragraph -->
  <!-- wp:heading -->
  <h2 class="wp-block-heading">
    <?php echo esc_html__( 'Section Heading', 'dhali' ); ?>
  </h2>
  <!-- /wp:heading -->
  <!-- wp:paragraph -->
  <p><?php echo esc_html__( 'Supporting description text.', 'dhali' ); ?></p>
  <!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

### Named inner groups

Name all semantically distinct groups with `metadata.name`. Common names from the Ollie library:

- `Titles` — overline + heading + description stack
- `Text` — body copy group
- `Media` — image or cover container
- `CTA` — call-to-action button group
- `Features` — feature list container
- `Feature` — individual feature row
- `Price` — pricing value group
- `Pricing Table` — full pricing column
- `Testimonial` — quote + attribution group
- `Cite` — avatar + name + role attribution row
- `Team Member` — individual team cover card
- `Team Members` — grid of team cards
- `Logos` — logo strip group
- `Navs` — navigation link group
- `Section` — generic named section
- `Hero Dark Wrap` — inner wrapper for dark hero layouts

### Macro vs micro layout

- **Macro (50/50 splits, side-by-side sections):** Use `core/columns` with `align:"wide"` and `verticalAlignment:"center"`. Set `blockGap` on both axes using spacing tokens.
- **Micro (horizontal alignment inside cards):** Use `core/group` with `layout:{"type":"flex"}` or `layout:{"type":"grid","minimumColumnWidth":"20rem"}` for responsive grids.

### Cover images for team/media

The Ollie pattern for team member photos uses `core/cover` with these exact attributes:

- `dimRatio: 50`
- `overlayColor: "main"`
- `isUserOverlayColor: true`
- `contentPosition: "bottom center"`
- `isDark: false`
- `className: "is-style-rounded-cover"`
- `dimensions: {"aspectRatio":"3/4"}`

Use `esc_url( get_template_directory_uri() )` for the `url` attribute and omit the alt text on cover background images (alt goes on the `<img>` tag, which should have `alt=""`).

### Avatar images

For circular testimonial avatars, use `core/image` with:

- `className: "is-style-rounded-full"`
- `width: "60px"`, `height: "60px"`
- `sizeSlug: "full"`

### Local image paths in content strings

All image references inside the `content` string must use PHP string concatenation:

```php
'<img src="' . esc_url( get_template_directory_uri() ) . '/patterns/images/avatar-1.webp" alt="' . esc_attr__( 'Description', 'dhali' ) . '">'
```

Use `dhali/get-local-assets` before writing any pattern that references images to confirm available filenames.

### Feature list rows

Ollie uses a two-column flex group for feature rows with a bold checkmark character:

```html
<!-- wp:group {"metadata":{"name":"Feature"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group">
  <!-- wp:paragraph -->
  <p>
    <strong><?php echo esc_html__( '✓', 'dhali' ); ?></strong>
  </p>
  <!-- /wp:paragraph -->
  <!-- wp:paragraph -->
  <p><?php echo esc_html__( 'Feature text', 'dhali' ); ?></p>
  <!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

### Dark section text binding

When applying dark backgrounds (`main`, `main-accent`, `primary`) to macro sections, the outer group must set the background token. All nested text blocks must explicitly set `textColor` to high-contrast tokens (`base`, `secondary`). Do not rely on inherited text color in dark sections.

### Hero / Cover blocks

For hero sections using `core/cover`:

- Set `overlayColor` and `dimRatio` explicitly (`80` for dark heroes, `0` for image-forward).
- Apply `is-style-blur-image` or `is-style-blur-image-less` via `className` when appropriate.
- Set `margin:{"top":"0","bottom":"0"}` and explicit `padding` to prevent layout gaps.
- Always include `isDark: false` for `is-light` cover variants, `isDark: true` for dark text-on-image.

## Context Cache Workflow

Before running broad discovery, check for a project context cache.

### Preferred lookup order

```sh
# Prefer the canonical context.md written by dhali/sync-context, but support legacy project_slug_context.md.
find "$WP_ROOT" -maxdepth 1 -type f \( -name 'context.md' -o -name '*_context.md' \) | sort | head -1 | xargs -r cat
test -f "./context.md" && cat "./context.md"
test -d "$HOME/pictures/blocks" && find "$HOME/pictures/blocks" -maxdepth 2 -type f \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.webp' -o -iname '*.svg' \) | sort
```

### Cache hit

Read `context.md`. Use `@wp_cli` only for runtime facts not present in the cache. Do not rescan the full pattern library unless the task requires it.

### Cache miss

Run only lightweight discovery:

```text
@wp_cli active_theme
@wp_cli core_version
```

Then propose `{WP_ROOT}/context.md` with basic environment state. Keep the cache under 500 words. Do not paste the full pattern library into context.

## MCP Fast Path for Pattern Authoring

Mandatory MCP execution shape:

```json
{
  "ability_name": "dhali/example-ability",
  "parameters": {
    "request": "request_value"
  }
}
```

Never use `ability_input`.

### Pattern setup order

1. **Pre-flight confirmed** in Session Pre-flight above. If skipped, run it now.

2. **Context:** Read the project context file if it exists:
   - `{project-name}_context.md` → `context.md`
     If the file has active theme + token slugs, treat those facts as current and skip matching MCP fetches.

3. **Targeted MCP fetches:** Skip any row whose data is already in context.

   | Needed fact                     | MCP ability                            | When to call                                   |
   | :------------------------------ | :------------------------------------- | :--------------------------------------------- |
   | Core/PHP/theme/layout snapshot  | `dhali/get-project-snapshot`           | Fallback: only if `dhali/sync-context` failed  |
   | Site title or active theme name | `dhali/get-site-info`                  | Fallback: not in context                       |
   | Token slugs or layout settings  | `dhali/get-token-and-layout-map`       | Fallback: not in context or context shows gaps |
   | PHP return-array skeleton       | `dhali/get-pattern-template-skeleton`  | Every new PHP pattern — always call            |
   | Local image filenames           | `dhali/get-local-assets`               | Every pattern that references images           |
   | Fragile block snippets          | `dhali/get-editor-safe-block-snippets` | Pattern includes icons, SVGs, CTAs, covers     |
   | Known-good named icon           | `dhali/get-icon-manifest`              | Pattern copies an editor-saved icon            |

4. Use `@wp_cli` only when MCP does not expose the needed fact.

5. Inspect existing files only for filename collision or a nearby style match. Do not scan the full pattern library.

6. Show the required four-part proposal. Do not write the file until the user explicitly approves.

### Required validation gate after approval

After the user approves, confirm the WordPress MCP server is connected, all three validation abilities are available, and at least one cheap MCP execute sanity call still succeeds. If MCP is disconnected, any validation ability is missing, or execute fails, stop before writing and ask the user to reconnect MCP.

Required abilities before writing:

- `dhali/lint-pattern-authoring-rules`
- `dhali/validate-pattern-markup`
- `dhali/test-pattern-in-editor-context`

Once confirmed, run the complete validation sequence:

1. Write the PHP pattern file.
2. PHP lint on the written file.
3. `dhali/lint-pattern-authoring-rules` with `context: "standalone"`.
4. `dhali/validate-pattern-markup`.
5. `dhali/test-pattern-in-editor-context`.

Authoring-rule lint shape:

```json
{
  "ability_name": "dhali/lint-pattern-authoring-rules",
  "parameters": {
    "request": "lint_pattern_authoring_rules",
    "markup": "BLOCK_MARKUP_HERE",
    "context": "standalone"
  }
}
```

Parse validation shape:

```json
{
  "ability_name": "dhali/validate-pattern-markup",
  "parameters": {
    "markup": "BLOCK_MARKUP_HERE"
  }
}
```

Editor-context test shape:

```json
{
  "ability_name": "dhali/test-pattern-in-editor-context",
  "parameters": {
    "request": "test_pattern_in_editor_context",
    "markup": "BLOCK_MARKUP_HERE",
    "post_type": "page",
    "context": "standalone"
  }
}
```

If any check fails, report the failure, fix the file, and rerun the failed checks plus PHP lint.

### Block validity rules

- Do not place placeholder comments inside `<svg>` markup in final saved block content.
- Do not leave truncated class names, broken JSON, wrapped line fragments, or placeholder paths in final block markup.
- Custom SVG icon blocks must include the saved `<!-- wp:outermost/icon-block ... -->` wrapper and matching closing comment.
- If exact SVG path data is unavailable, do not ask the user for path data. Use the closest local/MCP icon asset or author a simple editor-safe `core/html` SVG approximation and note it in the proposal.
- Keep generated PHP strings intact; avoid line wrapping that splits JSON keys, class names, or CSS variable names.

## Pattern Authoring Safety MCP Tools

### `dhali/lint-pattern-authoring-rules`

Run whenever the pattern includes image cards, covers, icons, generated SVG, custom classes, or dynamic post-context blocks. It catches:

- `core/cover` with `useFeaturedImage:true` in standalone patterns.
- `id:0` or `wp-image-0` in saved markup.
- Remote placeholder image URLs (`picsum.photos`, `placehold.co`, `placeholder.com`, `loremflickr.com`, `dummyimage.com`).
- Generated block-level `style.css` such as `"css":"overflow:hidden;"`.
- Malformed or guessed `outermost/icon-block` custom SVG scaffold.
- Empty SVG shells for named icons.
- `iconColorValue` or `iconBackgroundColorValue` using CSS variables instead of resolved hex values.
- Synthesized `core/group` link attributes such as `href`, `linkDestination`, or `animationType`.
- Manual layout CSS on `core/group` wrappers such as `width`, `display:flex`, `align-items`, or `justify-content` when not copied from a known editor snippet.
- Unknown Ollie token slugs.
- Wrapped or truncated CSS variable names or class names.
- `has-custom-font-size` without a matching `style.typography.fontSize` custom value.

### `dhali/get-local-assets`

Call before writing any pattern that references images. Returns available filenames from the Ollie theme's `patterns/images/` directory. Use only these filenames. Do not invent image filenames or use remote URLs.

### `dhali/get-editor-safe-block-snippets`

Use before composing fragile blocks. Prefer these snippets over inventing markup for:

- Plus-circle CTAs as serializer-safe `core/buttons`/`core/button` markup (`plus-cta-circle-button`).
- Known-good copied Outermost/Ollie named icons with full SVG paths.
- Known-good custom Outermost/Ollie SVG icon scaffolds (`outermost-custom-svg-icon`) where only the inner SVG is replaced.
- Editor-safe Cover snippets.
- Simple Ollie button examples.

### CTA and button safety rule

Allowed:

1. Ollie/editor-copied `core/buttons` + `core/button` markup.
2. `core/button` using known Ollie classes: `is-style-fill`, `is-style-button-light`, `is-style-secondary-button`.
3. Exact editor-saved linked-group snippets only when copied from the current editor or returned by MCP. Do not synthesize linked group behavior.
4. Plain paragraph links for simple text CTAs.

Avoid:

- Generated custom linked `core/group` markup assembled from memory.
- `core/group` with guessed `href`, `linkDestination`, or `animationType` attributes.
- Manual wrapper styles on `core/group` such as `width`, `display:flex`, `align-items`, or `justify-content` unless the snippet is exact editor-saved markup.
- `core/html` as a default CTA solution.

For circular plus CTAs, fetch `dhali/get-editor-safe-block-snippets` and copy `plus-cta-circle-button` exactly unless the user supplies exact editor-saved linked-group markup.

### Plus CTA safety rule

1. Prefer the `plus-cta-circle-button` snippet from `dhali/get-editor-safe-block-snippets` because it uses serializer-stable core button markup.
2. Do not replace the CTA with a bare icon block. A plus CTA is a composed control: CTA wrapper + clickable element + visual plus.
3. Use a linked `core/group` CTA only when the entire group scaffold is exact editor-saved markup. Never add `href`, `linkDestination`, or `animationType` to `core/group` from memory.
4. Use `core/html` only as a temporary diagnostic fallback when explicitly requested.

### `dhali/test-pattern-in-editor-context`

Use after writing to create a temporary draft page containing the block markup and return an edit URL for manual editor verification. Does not replace visual review.

### Featured image and Cover rule

Do not use `core/cover` with `useFeaturedImage:true` for standalone screenshot-based patterns.

For screenshot-matched standalone cards:

- Use `core/image` when the image is simply displayed.
- Use `core/cover` only when overlay content is needed and markup is copied from the editor, supplied by the user, or returned by `dhali/get-editor-safe-block-snippets`.
- Ask for the media URL and real attachment ID if a screenshot requires a specific image.

Core Cover safety:

- Do not generate final `core/cover` saved markup from memory.
- Preserve the Cover serializer shape exactly: `wp:cover` attributes, `wp-block-cover` wrapper classes, `has-custom-content-position`, `is-position-*`, `is-light`/`is-dark`, `wp-block-cover__image-background`, `wp-image-*`, `size-*`, `wp-block-cover__background`, and `wp-block-cover__inner-container`.

## Ollie Editor-Safe Icon and SVG Rules

The WordPress editor serializer is the source of truth for Ollie/Outermost icon blocks.

### Hard rule

Do not guess third-party block serialization. Use `outermost/icon-block` only when one of these is true:

1. The exact saved icon block markup was copied from the WordPress editor.
2. The snippet comes from `dhali/get-editor-safe-block-snippets` or `dhali/get-icon-manifest`.
3. The user provides a known-good saved block snippet.

A named Outermost icon, a custom SVG Outermost icon, and a CTA icon are different scaffold families. Do not adapt one family into another.

### Editor-saved values must stay editor-saved

- Preserve hardcoded values the editor generated, including hex colors, `px` values, and negative margins.
- Preserve `iconColorValue` and `iconBackgroundColorValue` as resolved hex values, not CSS variables.
- Do not replace `iconColorValue` with `var(--wp--preset--color--primary)`.
- Preserve `ollieCustomClasses`, `ollieResponsive`, `items-justified-*`, and matching `className` values.
- Do not invent new `ollieCustomClasses`.

### Named icon rule

Named Outermost/Ollie icons must include the saved SVG path. Do not output an empty SVG shell.

### Custom SVG rule

For custom decorative SVGs, choose this path:

1. If MCP returns `outermost-custom-svg-icon`, use that exact scaffold and replace only the inner `<svg>...</svg>` while preserving both wrapper divs, `iconName:""`, width, and transform style.
2. If there is no trusted Outermost custom SVG scaffold, use `core/html` for the decorative SVG.
3. Never adapt a named icon snippet into a custom SVG snippet.

Safe `core/html` fallback:

```html
<!-- wp:html -->
<div style="width:56px;line-height:0" aria-hidden="true">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" focusable="false">
    <path fill="#7f8b72" d="..."></path>
  </svg>
</div>
<!-- /wp:html -->
```

## Image and Cover Safety Rules

Hard errors for generated final patterns:

- Do not use `core/cover` with `useFeaturedImage:true` in standalone patterns.
- Do not write `id:0` or `wp-image-0`.
- Do not use remote placeholder image services.
- Do not use generated block-level `style.css` for clipping.

If a screenshot-matched design requires a real image and no real project media URL/ID is known, stop before writing and ask the user for the asset. A proposal may include a clearly marked placeholder, but the final written pattern must not contain any of the above.

## Screenshot Reference Workflow

1. Check `~/pictures/blocks/`.
2. Prefer filenames and nearby folder names as hints.
3. Ask for clarification only when multiple screenshots plausibly match.
4. Do not OCR screenshots unless necessary.
5. Use screenshots as visual references only; still generate semantic WordPress block markup.

```sh
find "$HOME/pictures/blocks" -maxdepth 2 -type f \
	\( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.webp' -o -iname '*.svg' \) |
	sort
```

## Screenshot Fidelity Rules

When recreating a screenshot, prioritize in this order:

1. Block hierarchy and semantic structure.
2. Relative order of elements.
3. CTA type and interaction shape.
4. Icon/media presence and approximate style.
5. Spacing/proportion.
6. Typography exactness.

Do not over-optimize exact dimensions, font rendering, or card width when the pattern will flow inside the editor/page layout. Preserve the structure first.

### SVG / Icon Recreation Rule

Do not ask the user to provide SVG path data when recreating a screenshot. If the exact icon is unavailable, use this order:

1. Existing local theme/plugin SVG or image asset.
2. MCP icon manifest / editor-safe icon snippet, including `outermost-custom-svg-icon` when an Outermost custom SVG block is desired.
3. Simple inline `core/html` SVG approximation authored by the agent.
4. Plain text/icon fallback only if SVG is not appropriate.

The proposal may state that the icon is an approximation, but missing exact SVG data is not a blocker.

Screenshot-derived one-off SVG fills and CTA colors may use sampled hex values only when no matching Ollie token exists. Keep those hex values local to the SVG/CTA and do not turn them into global tokens.

### Circular CTA Rule

If the source design shows a small circular CTA, the background color belongs only to the clickable circular control. It must not become a full-width yellow bar.

Use `plus-cta-circle-button` unless the user provides exact editor-saved linked-group markup. Do not place a bare `outermost/icon-block` at the bottom and call it a CTA; it must be a clickable control.

### Core Group Wrapper Style Rule

Do not inject manual layout CSS into the saved wrapper `<div>` for `core/group` unless the exact markup came from the editor. Avoid wrapper styles such as `width`, `display:flex`, `align-items`, and `justify-content` because they are not reliably reproduced by the core/group serializer. Express layout through block attributes/classes or use a real `core/button`.

### MCP Reconnect Resume Rule

After any MCP disconnect/reconnect during authoring, stop and restate the current implementation checkpoint before writing or validating:

- target file
- source screenshot/reference
- selected block structure
- icon strategy
- CTA strategy
- validation sequence

Do not continue from memory alone if any checkpoint item is missing. Recover it from the prior proposal or stop before writing.

## Ability Design Notes for Dhali MCP

Design abilities as small, explicit API surfaces.

- Register ability categories on `wp_abilities_api_categories_init` and abilities on `wp_abilities_api_init`.
- Every ability must have `execute_callback`, `permission_callback`, `input_schema`, `output_schema`, and MCP/REST metadata.
- Prefer read-only abilities. Split write/preview actions from read-only validation where possible.
- Do not run shell commands from MCP callbacks; keep PHP lint in the local CLI workflow.
- Return schema-valid arrays or `WP_Error` objects. Catch exceptions and report structured errors.
- No-parameter abilities should either use a simple required `request` enum or a schema that avoids fragile empty `properties` objects.
- Flexible object schemas must explicitly allow `additionalProperties` when the payload is intentionally open-ended.

## Code Boilerplate and Patterns

### PHP Pattern Skeleton

The `content` string must use PHP string concatenation for all translatable text and all asset URLs. The file is `require`d by the plugin and its return value passed directly to `register_block_pattern()`, so the PHP runs at registration time.

- `esc_html__()` — returns escaped translated string for concatenation into text nodes.
- `esc_attr__()` — returns escaped translated string for concatenation into HTML attributes.
- `esc_url()` — returns escaped URL for concatenation into `src` and `href` attributes.
- Single quotes inside the content string must be escaped as `\'`.

```php
<?php
return array(
	'title'         => __( 'Pattern Title', 'dhali' ),
	'categories'    => array( 'dhali-web-development', 'card' ),
	'description'   => _x( 'One sentence describing the pattern.', 'Block pattern description', 'dhali' ),
	'keywords'      => array( 'keyword', 'section' ),
	'viewportWidth' => 1500,
	'blockTypes'    => array( 'core/group' ),
	'content'       => '
<!-- wp:group {"metadata":{"name":"Pattern Name","categories":["dhali-web-development"],"patternName":"dhali-patterns/pattern-slug"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xx-large","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|xx-large","left":"var:preset|spacing|medium"},"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|x-large"}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--xx-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--xx-large);padding-left:var(--wp--preset--spacing--medium)">
	<!-- wp:group {"metadata":{"name":"Titles"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"style":{"typography":{"fontStyle":"normal","fontWeight":"500"}},"textColor":"primary","fontSize":"small"} -->
		<p class="has-primary-color has-text-color has-small-font-size" style="font-style:normal;font-weight:500">' . esc_html__( 'Overline Text', 'dhali' ) . '</p>
		<!-- /wp:paragraph -->
		<!-- wp:heading -->
		<h2 class="wp-block-heading">' . esc_html__( 'Section Heading', 'dhali' ) . '</h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph -->
		<p>' . esc_html__( 'Supporting description for the section.', 'dhali' ) . '</p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
',
);
```

### Image reference in content string

```php
'<figure class="wp-block-image size-full is-resized"><img src="' . esc_url( get_template_directory_uri() ) . '/patterns/images/desktop.webp" alt="' . esc_attr__( 'Desktop preview', 'dhali' ) . '" class="wp-image-3024"/></figure>'
```

### Card shell with shadow

```html
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium"}},"border":{"radius":"var:preset|border-radius|lg"},"shadow":"var:preset|shadow|small-light"},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div
  class="wp-block-group has-base-background-color has-background"
  style="border-radius:var(--wp--preset--border-radius--lg);padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium);box-shadow:var(--wp--preset--shadow--small-light)"
></div>
<!-- /wp:group -->
```

### Post card rule

If a card is meant for Query Loop or post-template context, use post blocks:

- `core/post-featured-image`
- `core/post-title`
- `core/post-date`
- `core/post-author`
- `core/post-excerpt`
- `core/post-terms`

For standalone screenshot cards, use static blocks with real media IDs and static concatenated text.

## WordPress Integration Rules

- **Pattern Overrides:** If creating synced patterns with editable text/images, mark editable child blocks with `"metadata":{"bindings":...}` or `"role":"content"` only where appropriate.
- **Block Bindings:** Prefer binding data to core blocks instead of creating custom blocks.
- **Interactivity API:** Use the current store/directive pattern supported by the project. Avoid legacy assumptions unless live project code confirms them.
- **Iframed Editor:** Assume the editor is iframed for Block API v3+ blocks. Do not rely on parent `wp-admin` DOM selectors.
- **Live Context via MCP:** Use MCP abilities first for snapshot, token map, pattern skeleton, local assets, editor-safe snippets, icon manifest, and markup validation. Use `@wp_cli` only for facts not exposed by MCP.

## Authoring Protocol

For every pattern or template request, output this exact four-part proposal before writing files.

### 1. Architectural Intent

Briefly explain the visual structure, Ollie tokens chosen, native WordPress/FSE decisions, and which local image assets will be used.

### 2. Block Tree

Provide an indented list of block names and key attributes.

### 3. Proposed Destination

```text
wp-content/plugins/dhali-pattern-library/patterns/{pattern-name}.php
```

### 4. PHP/HTML Payload

For small/simple patterns, provide the complete PHP return array or HTML template content. For complex PHP patterns, avoid pasting a full payload unless the user explicitly asks; show the exact destination, the block tree, and a representative non-wrapped snippet instead. Full payloads are easy to line-wrap in terminal UIs and can corrupt WordPress block JSON.

Rules:

- Include `dhali-web-development` and one semantic core category in `categories`.
- Set `viewportWidth` to `1500`.
- Include `metadata.name`, `metadata.categories`, and `metadata.patternName` on the outermost block group.
- Set `margin:{"top":"0","bottom":"0"}` on the outermost group.
- Use string concatenation with `esc_html__()`, `esc_attr__()`, and `esc_url()` for all user-visible text and asset references inside the `content` string.
- Keep PHP pattern `content` inside one single-quoted heredoc-style string, breaking out with concatenation for PHP calls.
- Use tabs for PHP array indentation.
- Do not write the file until the user explicitly says `Approved`.

## Small Repair Fast Path

For an existing pattern repair where the user requests a narrow visual or markup fix:

1. Run MCP discovery plus one cheap execute sanity check.
2. Read only the target pattern file and the relevant small surrounding block.
3. Do not fetch project snapshot, token map, local assets, icon manifest, or snippets unless the edit requires them.
4. Make the smallest safe edit.
5. Run validation sequentially.

## Post-Approval Write and Validation Workflow

After the user explicitly says `Approved`:

1. Reconfirm the WordPress MCP server is connected.
2. Reconfirm all three abilities exist: `dhali/lint-pattern-authoring-rules`, `dhali/validate-pattern-markup`, `dhali/test-pattern-in-editor-context`.
3. Run one cheap MCP execute sanity check: `dhali/get-pattern-template-skeleton` or `dhali/get-editor-safe-block-snippets`.
4. If any validation ability is unavailable or execute fails, stop before writing and ask the user to reconnect MCP.
5. Write the PHP pattern file only after MCP validation availability and execute sanity are confirmed.
6. Run PHP lint on the written file outside MCP.
7. Extract the generated block markup from the `content` string.
8. Execute `dhali/lint-pattern-authoring-rules` sequentially.
9. Execute `dhali/validate-pattern-markup` sequentially.
10. Execute `dhali/test-pattern-in-editor-context` sequentially.
11. Treat this ability as a preview-draft check unless it explicitly returns `editor_invalid_content_checked: true` and no recovery warning was found. A returned edit URL means `preview_ready`, not `ready`.
12. Never run MCP validation abilities in parallel. If a validation ability returns a server error or MCP disconnects, retry that ability once only. If it still fails, stop and report `written_but_not_validated`. Do not probe with unrelated/minimal payloads unless the user asks to debug MCP itself.
13. If the pattern uses an icon, verify the full `outermost/icon-block` wrapper is present only for known-good editor-saved snippets, and verify every SVG contains real path elements.
14. Report all check results. Only say the pattern is ready when automated browser/editor validation or user visual review confirms there is no “Block contains unexpected or invalid content” recovery warning.

## Final Status Labels

Use one of these exact statuses after write attempts:

- `preview_ready`: PHP lint and MCP checks passed and an editor draft URL exists, but editor recovery warnings have not been checked.
- `ready`: all required checks passed and browser/manual editor review confirms no invalid-content recovery warning.
- `written_but_not_validated`: file exists and PHP lint may pass, but one or more MCP validations did not complete.
- `failed_validation`: validation ran and found a markup or authoring-rule issue.
- `not_written`: stopped before file write.

Do not say a pattern is ready unless the status is `ready`. Use `preview_ready` after `dhali/test-pattern-in-editor-context` creates a draft but no one has checked the editor UI yet.

## Quality Checklist

Before final output, verify:

- No JSON block attributes line-wrapped or split across multiple lines.
- No accidental outer code fences around the whole skill output.
- YAML frontmatter exists only once.
- Every opened Markdown code fence is closed.
- SVG `xmlns` attributes are plain URLs, not Markdown links.
- No generated-file logs remain.
- No invented Ollie token slugs.
- No invented image filenames — only filenames confirmed by `dhali/get-local-assets`.
- No bare text strings inside the `content` value — all user-visible text uses `esc_html__()`, `esc_attr__()`, or `esc_url()` concatenation.
- Outermost block group includes `metadata.name`, `metadata.categories`, `metadata.patternName`.
- Outermost block group has `margin:{"top":"0","bottom":"0"}`.
- `viewportWidth` is `1500`.
- `categories` includes `dhali-web-development`.
- Any file-write action waits for explicit approval.
- MCP validation availability and execute sanity confirmed before writing approved PHP patterns.
- Generated final patterns do not contain `id:0`, `wp-image-0`, remote placeholder image URLs, `useFeaturedImage:true` in standalone context, `has-custom-font-size` without a matching custom `fontSize` value, or generated `style.css` serializer hacks.
