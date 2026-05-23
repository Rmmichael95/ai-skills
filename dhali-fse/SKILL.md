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
- **Prefer native WordPress:** Prefer core blocks, block bindings, pattern overrides, template parts, and theme.json-compatible attributes before custom blocks or custom CSS.
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
5. Use `dhali/get-icon-manifest` only when the pattern includes an Ollie/Outermost icon or custom SVG.
6. Inspect existing files only for filename collision, project-specific formatting uncertainty, or a requested nearby style match.
7. Do not scan the full pattern library during routine pattern generation.

### Required validation gate after approval

After the user approves and after writing a PHP pattern file, always run both checks before claiming success:

1. PHP lint on the written file.
2. MCP block markup validation with `dhali/validate-pattern-markup`.

MCP validation shape:

```json
{
  "ability_name": "dhali/validate-pattern-markup",
  "parameters": {
    "markup": "BLOCK_MARKUP_HERE"
  }
}
```

If either check fails, do not say the pattern is ready. Report the failure, fix the file, and rerun both checks.

### Block validity rules

- Do not place placeholder comments inside `<svg>` markup in final saved block content.
- Do not leave truncated class names, broken JSON, wrapped line fragments, or placeholder paths in final block markup.
- Custom SVG icon blocks must include the saved `<!-- wp:outermost/icon-block ... -->` wrapper and matching closing comment.
- If the real SVG path data is unavailable, use a simple valid temporary SVG path or ask for the SVG before writing the file.
- Keep generated PHP strings intact; avoid line wrapping that splits JSON keys, class names, or CSS variable names.

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

Use when the icon exists in the Ollie library. `iconColorValue` must match the selected color slug.

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

Use when injecting a custom brand SVG. Set `iconName` to an empty string when required by the block’s saved markup.

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

1. Write the PHP pattern/template/part file.
2. Run PHP lint on the written file.
3. For PHP block patterns, extract the generated block markup from the `content` string and execute `dhali/validate-pattern-markup` through MCP.
4. If the pattern uses an icon, verify the full `outermost/icon-block` wrapper is present and the SVG contains valid elements, not placeholder comments.
5. Report lint result and MCP validation result.
6. Only say the pattern is ready when both checks pass.

Do not skip validation for speed. The final validation calls are cheaper than debugging invalid block recovery in the editor.

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

