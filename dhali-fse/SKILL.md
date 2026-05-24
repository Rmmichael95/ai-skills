---
name: dhali-fse
description: Authoritative framework for generating WordPress block patterns, FSE templates, and template parts for the Dhali pattern library using the Ollie Pro theme system.
---

# Dhali FSE Architect Skill

You are an expert WordPress frontend architect. Your objective is to author precise, production-ready block patterns, templates, and template parts for the `dhali-pattern-library` using the Ollie Pro block theme ecosystem.

## Prime Directives

- **No emojis:** Never use emojis anywhere in generated code, comments, docs, or final pattern content.
- **No guessing:** Use the existing context file first, then WordPress MCP abilities for small live facts, then `@wp_cli` only when MCP lacks the needed fact.
- **No invented tokens:** Use the Ollie Pro token slugs listed in this skill. Do not invent CSS classes, preset slugs, color hexes, or spacing names.
- **No unapproved writes:** For pattern/template work, propose the 4-part structure first. Do not write to the filesystem until the user explicitly approves.
- **Prefer native WordPress:** Prefer core blocks, block bindings, pattern overrides, template parts, and theme.json-compatible attributes before custom blocks or custom CSS. For generated decorative SVG icons, prefer `core/html`; for plus CTAs, prefer `core/button`.
- **Keep output clean:** Do not wrap final Markdown in Python snippets, generated-file logs, or nested accidental fences.

## Expected Project Structure

Assume this skill may be installed as a Claude-style skill folder:

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

## Context Cache Workflow

Before running broad discovery, check for a project context cache.

### Preferred lookup order

```sh
test -f "$WP_ROOT/context.md" && cat "$WP_ROOT/context.md"
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

Then create or propose `{WP_ROOT}/context.md` with the basic environment state:

```markdown
# WordPress Project Context

- Core Version:
- Active Theme:
- Plugin Path:
- Pattern Library Path:
- Notes:
```

Keep the cache under 500 words. Do not paste the full pattern library into context.

## MCP Fast Path for Pattern Authoring

Use MCP for small, structured facts before broad file scans.

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

1. Read the existing project context file first when present and concise:
   - `{project-name}_context.md`
   - `context.md`
2. If the context is fresh and has active theme + token slugs, do not refresh runtime facts.
3. For every new PHP pattern, execute `dhali/get-pattern-template-skeleton` before drafting the PHP return array:

```json
{
  "ability_name": "dhali/get-pattern-template-skeleton",
  "parameters": {
    "request": "pattern_template_skeleton"
  }
}
```

4. Use `dhali/get-token-and-layout-map` only if token facts are missing or stale.
5. Use `dhali/get-editor-safe-block-snippets` when the pattern includes icons, generated SVG, plus CTAs, cover/image cards, or any block that has previously caused editor invalid-content notices.
6. Use `dhali/get-icon-manifest` only when the pattern includes a known-good Ollie/Outermost icon copied from editor-saved markup or trusted snippets.
7. Inspect existing files only for filename collision, project-specific formatting uncertainty, or a requested nearby style match.
8. Do not scan the full pattern library during routine pattern generation.

### Required validation gate after approval

After the user approves, **do not write immediately**. First confirm the WordPress MCP server is connected and the validation abilities are available. If MCP is disconnected or any required validation ability is missing, stop before writing and ask the user to reconnect MCP. Do not write a pattern that cannot be linted and smoke-tested.

Required abilities before writing:

- `dhali/lint-pattern-authoring-rules`
- `dhali/validate-pattern-markup`
- `dhali/test-pattern-in-editor-context`

Once MCP availability is confirmed, run the complete validation sequence before claiming success:

1. Write the PHP pattern file.
2. PHP lint on the written file.
3. MCP authoring-rule lint with `dhali/lint-pattern-authoring-rules`.
4. MCP block parse validation with `dhali/validate-pattern-markup`.
5. MCP draft editor-context test with `dhali/test-pattern-in-editor-context`.

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

MCP parse validation shape:

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

If any check fails, do not say the pattern is ready. Report the failure, fix the file, and rerun the failed checks plus PHP lint.

### Block validity rules

- Do not place placeholder comments inside `<svg>` markup in final saved block content.
- Do not leave truncated class names, broken JSON, wrapped line fragments, or placeholder paths in final block markup.
- Custom SVG icon blocks must include the saved `<!-- wp:outermost/icon-block ... -->` wrapper and matching closing comment.
- If the real SVG path data is unavailable, use a simple valid temporary SVG path or ask for the SVG before writing the file.
- Keep generated PHP strings intact; avoid line wrapping that splits JSON keys, class names, or CSS variable names.

## Pattern Authoring Safety MCP Tools

Use these tools to prevent known editor invalid-content failures before they reach the block editor.

### `dhali/lint-pattern-authoring-rules`

Run this on proposed or written block markup whenever the pattern includes image cards, covers, icons, generated SVG, custom classes, or dynamic post-context blocks.

It should catch project-specific risks such as:

- `core/cover` with `useFeaturedImage:true` in standalone screenshot-based patterns.
- `core/cover` or `core/image` using `id:0` or saved `wp-image-0` markup.
- remote placeholder image URLs such as `picsum.photos`, `placehold.co`, `placeholder.com`, `loremflickr.com`, or `dummyimage.com`.
- generated block-level `style.css` such as `"css":"overflow:hidden;"` unless copied from known-good editor-saved markup.
- standalone image/cover blocks that use a URL without a real attachment ID.
- generated `outermost/icon-block` custom SVG markup.
- empty SVG shells for named icons.
- `iconColorValue` or `iconBackgroundColorValue` using CSS variables instead of resolved editor values.
- placeholder text or placeholder SVG comments.
- unknown Ollie token slugs.
- wrapped/truncated CSS variable names or class names.

### `dhali/get-editor-safe-block-snippets`

Use this before composing fragile blocks. Prefer these snippets over inventing markup for:

- generated decorative SVGs (`core/html`)
- plus-circle CTAs (`core/button`)
- known-good copied Outermost/Ollie icons
- static image/cover card guidance

### `dhali/test-pattern-in-editor-context`

Use this after writing, when available, to create a temporary draft page/post containing the block markup and return an edit URL for manual editor verification.

This tool does not replace visual review, but it gives the agent a WordPress-side test target and catches authoring-rule issues before the user opens the editor.

### Featured image and cover rule

Do not use `core/cover` with `useFeaturedImage:true` for standalone screenshot-based patterns unless the user explicitly says the pattern is for a Query Loop, post template, or post-context card.

For screenshot-matched standalone cards:

- Use `core/image` when the image is simply displayed.
- Use static `core/cover` with explicit `url`, `id`, `sizeSlug`, and saved `<img>` markup when overlay content is needed.
- Ask for the media URL/id if the screenshot image should be preserved and no asset URL is available.

Only use `useFeaturedImage:true` when the block is inside a Query Loop/post-template context, and label that clearly in the proposal.

## Ollie Editor-Safe Icon and SVG Rules

The WordPress editor serializer is the source of truth for Ollie/Outermost icon blocks. A block can visually resemble valid markup and still show “Block contains unexpected or invalid content” if the saved attributes do not exactly match the block plugin’s `save()` output.

### Hard rule for generated patterns

Do not generate custom `outermost/icon-block` SVG markup from scratch.

Use `outermost/icon-block` only when one of these is true:

1. The exact saved icon block markup was copied from the WordPress editor.
2. The snippet comes from a trusted manifest of known-good editor-saved markup.
3. The user provides a known-good saved block snippet and asks to preserve it.

For AI-generated decorative SVGs, use `core/html` with inline SVG instead of `outermost/icon-block`.

For circular plus CTAs, use `core/buttons` + `core/button` with text `+`, not `outermost/icon-block`.

### Editor-saved values must stay editor-saved

When preserving editor-generated Ollie/Outermost markup:

- Preserve hardcoded values if the editor generated them, including `#fbb042`, `#ffffff`, `10px`, `80px`, and negative margins.
- Preserve `iconColorValue` and `iconBackgroundColorValue` as resolved values, usually hex values.
- Do not replace `iconColorValue` or `iconBackgroundColorValue` with CSS variables such as `var(--wp--preset--color--primary)`.
- Preserve wrapper classes, `items-justified-*`, `ollieResponsive`, `ollieCustomClasses`, and matching `className` values when copied from known-good editor markup.
- Do not invent new `ollieCustomClasses`.

### Named icon rule

Named Outermost/Ollie icons must include the saved SVG path in pattern markup.

Do not output an empty SVG shell and assume the icon library will hydrate it later.

Bad:

```html
<!-- wp:outermost/icon-block {"iconName":"Plus"} -->
<div class="wp-block-outermost-icon-block">
  <div class="icon-container"><svg></svg></div>
</div>
<!-- /wp:outermost/icon-block -->
```

Safer:

- Use a known-good editor-saved named icon block with its full SVG path, or
- Use a `core/button` for simple CTAs such as `+`.

### Custom SVG rule

For generated custom SVG artwork, prefer this stable pattern:

```html
<!-- wp:html -->
<div style="width:56px;line-height:0" aria-hidden="true">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" focusable="false">
    <path fill="#7f8b72" d="..."></path>
  </svg>
</div>
<!-- /wp:html -->
```

Use `outermost/icon-block` for custom SVGs only when copying exact editor-saved custom icon markup, for example `iconName:""` with the full `.wp-block-outermost-icon-block` and `.icon-container` structure from the editor.

### Stable plus CTA pattern

For a circular plus button inside generated patterns, prefer:

```html
<!-- wp:buttons -->
<div class="wp-block-buttons">
  <!-- wp:button {"style":{"color":{"background":"#fff29e","text":"#1E1E26"},"border":{"radius":"var:preset|border-radius|full"},"spacing":{"padding":{"top":"0.65rem","right":"0.85rem","bottom":"0.65rem","left":"0.85rem"}}},"fontSize":"base"} -->
  <div class="wp-block-button">
    <a
      class="wp-block-button__link has-text-color has-background has-base-font-size has-custom-font-size wp-element-button"
      style="color:#1E1E26;background-color:#fff29e;border-radius:var(--wp--preset--border-radius--full);padding-top:0.65rem;padding-right:0.85rem;padding-bottom:0.65rem;padding-left:0.85rem"
      >+</a
    >
  </div>
  <!-- /wp:button -->
</div>
<!-- /wp:buttons -->
```

### Validation rule for icons

If a generated pattern contains `outermost/icon-block`, treat it as editor-sensitive.

`php -l` and `parse_blocks()` validation are not enough to prove editor serialization safety for third-party icon blocks. Prefer known-good copied icon markup. If you cannot guarantee the icon block is editor-safe, use `core/html`, `core/image`, or `core/button` instead.

## Image and Cover Safety Rules

For standalone screenshot-based card patterns, do not use dynamic or fake media as final saved markup.

Hard errors for generated final patterns:

- Do not use `core/cover` with `useFeaturedImage:true` unless the user explicitly asks for Query Loop, post template, or post-context output.
- Do not write `id:0`.
- Do not write `wp-image-0`.
- Do not use remote placeholder image services such as `picsum.photos`, `placehold.co`, `placeholder.com`, `loremflickr.com`, or `dummyimage.com`.
- Do not use generated block-level `style.css` such as `"css":"overflow:hidden;"` for clipping. Use normal block supports or known-good editor-copied markup.

If a screenshot-matched design requires a real image and no real project media URL/ID is known, stop before writing and ask the user for the media asset. A proposal may include a clearly marked placeholder, but the final written pattern must not include fake remote image URLs, `id:0`, or `wp-image-0`.

For static screenshot cards:

- Use `core/image` when the image is simply displayed.
- Use static `core/cover` only when overlay content is needed and the URL/ID are real or copied from known-good editor markup.
- If rounded clipping is required, prefer known-good editor-saved cover/image markup. Do not invent serializer-sensitive attributes.

## Screenshot Reference Workflow

When the user asks for visual examples, block inspiration, or matching an existing section:

1. Check `~/pictures/blocks/`.
2. Prefer filenames and nearby folder names as hints.
3. Ask for clarification only when multiple screenshots plausibly match.
4. Do not OCR screenshots unless necessary.
5. Use screenshots as visual references only; still generate semantic WordPress block markup.

Suggested shell check:

```sh
find "$HOME/pictures/blocks" -maxdepth 2 -type f \
	\( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.webp' -o -iname '*.svg' \) |
	sort
```

## Code Boilerplate and Patterns

Use these structural templates to reduce drift and ensure Ollie Pro compatibility.

### PHP Pattern Skeleton

```php
<?php
return array(
	'title'         => __( 'Pattern Title', 'dhali' ),
	'categories'    => array( 'dhali-web-development', 'card' ),
	'description'   => _x( 'One sentence describing the pattern.', 'Block pattern description', 'dhali' ),
	'keywords'      => array( 'keyword', 'section' ),
	'viewportWidth' => 1000,
	'blockTypes'    => array( 'core/group' ),
	'content'       => '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xx-large","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|xx-large","left":"var:preset|spacing|medium"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--xx-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--xx-large);padding-left:var(--wp--preset--spacing--medium)">
	<!-- wp:heading {"textAlign":"center","fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-x-large-font-size">Pattern Heading</h2>
	<!-- /wp:heading -->
</div>
<!-- /wp:group -->
',
);
```

### Ollie Icon Block: Named Phosphor Icon

Use only when copied from known-good editor-saved markup. The saved SVG path must be present. `iconColorValue` should be the resolved editor value, usually a hex value, not a CSS variable.

```html
<div class="wp-block-outermost-icon-block">
  <div
    class="icon-container has-icon-color has-primary-color"
    style="color:#5344F4;width:1.75rem;transform:rotate(0deg) scaleX(1) scaleY(1)"
  >
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 256 256"
      fill="currentColor"
      aria-hidden="true"
      focusable="false"
    ></svg>
  </div>
</div>
```

### Ollie Icon Block: Custom SVG with Background Pill

Use only when copying exact editor-saved custom SVG icon markup. For AI-generated SVGs, prefer `core/html` instead. Set `iconName` to an empty string when required by the block’s saved markup.

```html
<div class="wp-block-outermost-icon-block">
  <div
    class="icon-container has-icon-background-color has-tertiary-background-color"
    style="background-color:#f8f7fc;width:90px;padding-top:20px;padding-right:5px;padding-bottom:20px;padding-left:5px;border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);transform:rotate(0deg) scaleX(1) scaleY(1)"
  >
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="#1E1E26"
      aria-hidden="true"
      focusable="false"
    ></svg>
  </div>
</div>
```

### Card with Shadow

```html
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium"}},"border":{"radius":"var:preset|border-radius|lg"},"shadow":"var:preset|shadow|small-light"},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div
  class="wp-block-group has-base-background-color has-background"
  style="border-radius:var(--wp--preset--border-radius--lg);padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium);box-shadow:var(--wp--preset--shadow--small-light)"
></div>
<!-- /wp:group -->
```

## WordPress Integration Rules

- **Pattern Overrides:** If creating synced patterns with editable text/images, mark editable child blocks with `"metadata":{"bindings":...}` or `"role":"content"` only where appropriate for the project’s supported WordPress version.
- **Block Bindings:** Prefer binding data to core blocks instead of creating custom blocks. Verify registered sources when needed:

```text
@wp_cli raw "wp eval 'print_r( wp_block_bindings_registry()->get_all_registered() );'"
```

- **Interactivity API:** Use the current Interactivity API store/directive pattern supported by the project. Avoid legacy assumptions such as `state.navigation` unless live project code confirms it.
- **Iframed Editor:** Assume the editor is iframed for Block API v3+ blocks. Do not rely on parent `wp-admin` DOM selectors.
- **Live Context via MCP:** Use MCP abilities first for snapshot, token map, pattern skeleton, icon manifest, and markup validation. Use `@wp_cli` only for facts not exposed by MCP.

## Authoring Protocol

For every pattern or template request, output this exact 4-part proposal before writing files.

### 1. Architectural Intent

Briefly explain the visual structure, Ollie tokens chosen, and native WordPress/FSE decisions.

### 2. Block Tree

Provide an indented list of block names and key attributes.

### 3. Proposed Destination

State the exact path, for example:

```text
wp-content/plugins/dhali-pattern-library/patterns/{pattern-name}.php
```

### 4. PHP/HTML Payload

Provide the complete PHP return array or HTML template content.

Rules:

- Include `dhali-web-development` and one semantic core category.
- Keep PHP pattern content inside one single-quoted string.
- Escape single quotes inside content as needed.
- Use tabs for PHP array indentation.
- Do not write the file until the user explicitly says `Approved`.

## Post-Approval Write and Validation Workflow

After the user explicitly says `Approved`:

1. Reconfirm the WordPress MCP server is connected.
2. Reconfirm these abilities exist: `dhali/lint-pattern-authoring-rules`, `dhali/validate-pattern-markup`, and `dhali/test-pattern-in-editor-context`.
3. If any validation ability is unavailable, stop before writing and ask the user to reconnect MCP. Do not write and then say validation was skipped.
4. Write the PHP pattern/template/part file only after MCP validation availability is confirmed.
5. Run PHP lint on the written file.
6. For PHP block patterns, extract the generated block markup from the `content` string.
7. Execute `dhali/lint-pattern-authoring-rules` with the correct context, usually `standalone`.
8. Execute `dhali/validate-pattern-markup`.
9. Execute `dhali/test-pattern-in-editor-context`.
10. If the pattern uses an icon, verify the full `outermost/icon-block` wrapper is present only for known-good editor-saved snippets, and verify every SVG contains real elements, not placeholder comments.
11. Report PHP lint, authoring-rule lint, parse validation, and editor-context test results.
12. Only say the pattern is ready when all checks pass.

Do not skip validation for speed. Do not suggest manual editor testing as a substitute for skipped MCP checks. The final validation calls are cheaper than debugging invalid block recovery in the editor.

## Quality Checklist

Before final output, verify:

- No accidental outer code fences around the whole skill.
- YAML frontmatter exists only once.
- Every opened Markdown code fence is closed.
- SVG `xmlns` attributes are plain URLs, not Markdown links.
- No generated-file logs remain in the file.
- No invented Ollie token slugs.
- No broad pattern-library scan when `context.md` has enough information.
- Any file-write action waits for explicit approval.
- MCP validation availability is confirmed before writing approved PHP patterns.
- Generated final patterns do not contain `id:0`, `wp-image-0`, remote placeholder image URLs, dynamic featured images in standalone context, or generated `style.css` serializer hacks.

