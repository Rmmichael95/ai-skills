---
name: dhali-wordpress-context
description: Gather project-specific WordPress context before editing themes, plugins, block patterns, templates, template parts, or block-related PHP. Use this first when working inside a WordPress install, especially for Dhali FSE, Ollie-based, WordPress 7.0, or dhali-pattern-library work.
---

# Dhali WordPress Context Gathering

Use this skill before authoring or modifying WordPress theme, plugin, block pattern, template, template part, block variation, or block-related PHP files.

This skill is for gathering project facts first so the implementation matches the active site, active theme, token system, plugin structure, existing coding conventions, and WordPress 7.0 API surface.

## Core rule

Do not guess WordPress state.

Gather facts from the local project before proposing or writing code.

Prefer project files and approved WordPress tooling over assumptions.

When WordPress 7.0 features are relevant, detect whether the current install supports them before using them. Prefer progressive enhancement and backwards-compatible fallbacks unless the user explicitly targets WordPress 7.0-only code.


## MCP Fast Path for Low-Token Context

When the WordPress MCP server named `wordpress` is available, prefer the registered Dhali abilities before broad file reads or WP-CLI audits. This is the default low-token path for routine Dhali/Ollie/FSE work.

Use this order:

1. If a concise `context.md` already exists and appears current, read it first.
2. For missing or stale runtime facts, call known MCP abilities directly. Do not spend tokens on discovery unless an ability call fails or the required ability is unknown.
3. Use `@wp_cli` only when MCP lacks coverage or the task requires a broad audit.
4. Read project files only for exact existing code style, nearby examples, filenames, or implementation details not exposed through MCP.

Execute MCP abilities with `mcp-adapter-execute-ability` using this exact top-level shape:

```json
{
  "ability_name": "dhali/get-project-snapshot",
  "parameters": {
    "request": "project_snapshot"
  }
}
```

Never use `ability_input`; the execute tool requires `parameters`.

Known fast abilities:

| Ability | Parameters | Use |
| :--- | :--- | :--- |
| `dhali/get-site-info` | `{ "request": "site_info" }` | Site title, active theme name/slug, template, stylesheet. |
| `dhali/get-project-snapshot` | `{ "request": "project_snapshot" }` | Compact WP/PHP/theme/layout runtime facts. |
| `dhali/get-token-and-layout-map` | `{ "request": "token_and_layout_map" }` | Theme preset slugs and layout settings. |
| `dhali/get-pattern-template-skeleton` | `{ "request": "pattern_template_skeleton" }` | Standard Dhali PHP pattern return-array skeleton. |
| `dhali/get-icon-manifest` | `{ "request": "icon_manifest" }` | Ollie/Outermost icon block wrappers and custom SVG behavior. |
| `dhali/validate-pattern-markup` | `{ "markup": "<!-- wp:... -->..." }` | Validate generated block markup without extra file scans. |
| `dhali/sync-context` | `{ "request": "sync_context", "confirm_write": true }` | Refresh `context.md`; use only when the task explicitly includes context cache updating. |

For pattern/template authoring, the usual minimal context set is:

- `dhali/get-project-snapshot`
- `dhali/get-token-and-layout-map`
- `dhali/get-pattern-template-skeleton` only for new PHP patterns
- `dhali/get-icon-manifest` only when the design uses icons or custom SVGs

Do not run full WordPress 7.0 audits, plugin inventories, REST namespace lists, or broad greps unless the task actually touches those areas.

## Required context gathering order

### 1. Find the WordPress root

Find the WordPress root by walking upward from the current working directory or current file until you find:

- `wp-config.php`
- `wp-includes/`
- `wp-admin/`

If a tool already detects the WordPress root, use that tool result.

If the WordPress root cannot be found, stop and report the issue clearly.

### 2. Identify WordPress core version and 7.0 readiness

Determine the installed WordPress version before making API assumptions.

Preferred fast path:

```text
MCP dhali/get-project-snapshot
```

Fallback:

```text
@wp_cli core_version
```

For WordPress 7 readiness checks, prefer:

```text
@wp_cli block_overrides_audit
@wp_cli rest_api_namespaces
```

Record:

- WordPress core version
- PHP version, if available
- whether WordPress is 7.0.0 or newer
- whether block bindings are available
- whether pattern overrides are available or likely available
- whether Interactivity API is available
- whether Block Hooks are available
- whether Font Library APIs/CPTs are available
- REST API namespaces
- any custom REST namespaces from the project

If a shell-capable environment is available, use feature detection instead of version-only assumptions. Useful checks include:

```bash
wp eval 'echo wp_json_encode(array(
  "core_version" => get_bloginfo("version"),
  "php_version" => PHP_VERSION,
  "has_block_bindings_registry" => function_exists("wp_block_bindings_registry"),
  "has_register_block_bindings_source" => function_exists("register_block_bindings_source"),
  "has_interactivity_api" => class_exists("WP_Interactivity_API"),
  "has_wp_interactivity_state" => function_exists("wp_interactivity_state"),
  "has_block_hooks" => function_exists("insert_hooked_blocks"),
  "has_font_library" => function_exists("wp_get_font_face_data"),
  "has_font_face_cpt" => post_type_exists("wp_font_face"),
  "has_font_family_cpt" => post_type_exists("wp_font_family"),
  "rest_namespaces" => rest_get_server()->get_namespaces()
), JSON_PRETTY_PRINT);'
```

Do not use a new WordPress 7.0 API just because the task mentions WordPress 7. Confirm support first.

### 3. Identify the active theme

Determine the active theme before reading theme files.

Use WordPress tooling when available.

Preferred fast path:

```text
MCP dhali/get-site-info
```

Fallback:

```text
@wp_cli active_theme
```

Fallback:

```bash
wp theme list --status=active --format=json
```

Record:

- active theme name
- stylesheet slug
- template slug
- whether it is a child theme
- active theme directory
- parent theme directory, if applicable

### 4. Read canonical theme tokens

Read the active theme’s `theme.json`.

If the active theme is a child theme, check both:

- child theme `theme.json`
- parent theme `theme.json`

Gather canonical token slugs for:

- colors
- gradients
- spacing
- font sizes
- font families
- shadows
- border radius, if present
- dimensions presets, if present
- custom project tokens, if present
- block-level custom CSS support conventions, if present
- pseudo-element styling in `theme.json`, especially button hover/focus/focus-visible/active rules

When MCP can provide merged token/layout facts, prefer it for final token confirmation:

```text
MCP dhali/get-token-and-layout-map
```

Fallback:

```text
@wp_cli global_styles_get
```

Use theme preset tokens before hardcoded values.

Prefer these forms in block markup:

```text
var:preset|color|slug
var:preset|spacing|slug
var:preset|shadow|slug
var:preset|font-size|slug
var:preset|dimensions|slug
var(--wp--preset--color--slug)
var(--wp--preset--spacing--slug)
var(--wp--preset--dimensions--slug)
```

Avoid hardcoded hex, rem, px, or arbitrary inline values unless existing project files already use them for the same purpose or the design cannot be expressed with tokens.

### 5. Locate the target plugin/theme directory

For plugin work, locate the plugin directory under:

```text
wp-content/plugins/
```

For the Dhali pattern library, locate:

```text
wp-content/plugins/dhali-pattern-library/
```

Confirm the expected target directory exists before proposing filenames.

For block patterns, inspect:

```text
wp-content/plugins/dhali-pattern-library/patterns/
```

### 6. List nearby existing files

Before creating a new file, list relevant existing files.

For block patterns:

- list `patterns/*.php`
- note naming style
- note category names
- note viewport widths
- note blockTypes usage
- note PHP array formatting
- note indentation style
- note whether files use inline block content strings
- note whether the project uses pattern overrides, synced patterns, or content-only editing assumptions

For block/plugin work:

- list `block.json` files
- note `apiVersion`
- note `supports` declarations
- note render callbacks or PHP-only block registration patterns
- note block bindings usage
- note Interactivity API directives
- note editor scripts that may be affected by iframed editor behavior

For templates or template parts:

- list `templates/*.html`
- list `parts/*.html`
- inspect similar files before editing
- check Navigation block overlay usage before hardcoding mobile/off-canvas behavior

For custom CSS/classes:

- locate the project CSS file that defines custom classes
- for Dhali pattern work, check `dhali-classes.css` if present
- do not invent `ollieCustomClasses` names that are not already defined
- assume the editor may be iframed in WordPress 7.0 when blocks use Block API v3 or higher; ensure editor styles are loaded through proper block/theme/editor asset channels

### 7. Read representative examples

Read 2-3 existing files that are closest to the requested task.

For block patterns, inspect examples that match the intended layout type:

- header/navigation pattern for header or nav work
- footer pattern for footer work
- card pattern for card work
- query pattern for loop/query work
- section pattern for landing-page sections

Calibrate:

- PHP return-array style
- indentation
- block comment formatting
- content string formatting
- category conventions
- keyword conventions
- `viewportWidth`
- `blockTypes`
- animation usage
- custom class usage
- image asset references
- token reference style
- whether WordPress 7 pattern overrides/content-only behavior changes affect the pattern

### 8. Gather optional WordPress state when relevant

Use WordPress tooling as needed:

```text
@wp_cli plugin_list
@wp_cli theme_list
@wp_cli templates_list
@wp_cli template_parts_list
@wp_cli synced_patterns_list
@wp_cli rest_api_namespaces
```

Use these especially when the task touches:

- active plugins
- custom blocks
- ACF
- Meta Field Block
- block bindings
- pattern overrides
- synced patterns
- templates stored in the database
- template parts stored in the database
- REST endpoints
- WordPress 7 readiness
- editor/admin screens
- AI, Connectors, Abilities, or MCP integrations

## WordPress 7.0 API awareness

WordPress 7.0 adds and changes enough developer surface area that the context file should capture relevant API readiness before implementation.

### 7.0 features to detect and use when appropriate

When relevant to the task, check for and record:

- **AI integration stack**: WP AI Client, server/client Abilities APIs, Connectors screen/API, and MCP-related endpoints if the project registers AI-facing actions.
- **Iframed editor behavior**: editor iframe is enforced when inserted blocks use Block API v3+; avoid editor CSS/JS that depends on parent-document selectors.
- **Custom Navigation Overlays**: prefer Navigation block overlay/template support over hardcoded mobile overlay markup when targeting WP 7 navigation work.
- **Responsive editing / block visibility**: check whether the project uses core visibility controls or a visibility plugin; do not duplicate visibility behavior blindly.
- **Pattern editing and contentOnly**: WP 7 applies contentOnly behavior more broadly; for custom blocks, ensure editable content attributes have `"role": "content"` in `block.json` when needed.
- **Pattern Overrides for custom blocks**: block attributes that support Block Bindings can support Pattern Overrides; use `block_bindings_supported_attributes` only when needed and only after checking project conventions.
- **New/expanded core blocks**: Breadcrumbs, Icon, enhanced Heading, Gallery lightbox/slideshow, video backgrounds in Cover, and Navigation Link dynamic URL support may reduce the need for custom blocks.
- **Design tools and block supports**: block-level custom CSS, paragraph text columns/text indent, width/height/min-height dimensions presets, and theme.json pseudo-element support for core/button hover/focus/focus-visible/active may replace custom CSS.
- **PHP-only block registration**: if creating server-rendered blocks for WP 7-only projects, consider `supports.autoRegister` with a render callback; otherwise preserve existing `block.json` registration style.
- **Interactivity API**: prefer the WP 7 `watch()` pattern and server-populated `state.url`; avoid older `state.navigation` assumptions.
- **DataViews/DataForms**: if building admin/editor UI, prefer current DataViews/DataForm patterns over `WP_List_Table` customization for core screens.
- **Block Bindings iterations**: inspect source registration and supported attributes before adding bindings; align with Field API patterns where relevant.
- **Block Hooks changes**: account for Block Hooks behavior moving through REST-controller flow for content-like custom post types.
- **Plugin list filter**: `plugins_list_status_text` can customize plugin list filter tab labels if plugin-admin work requires it.
- **PHP baseline**: WordPress 7.0 requires PHP 7.4+, but project/server standards may require a higher baseline.

### 7.0 audit patterns

When the task involves compatibility, scan project files for likely impact points:

```bash
grep -R "apiVersion\|block_bindings\|metadata.bindings\|register_block_bindings_source\|wp_interactivity\|data-wp-\|state.navigation\|add_meta_box\|WP_List_Table\|register_block_type\|autoRegister\|contentOnly\|role.*content\|ollieCustomClasses" wp-content/themes wp-content/plugins 2>/dev/null
```

Use findings to decide whether to:

- keep current project conventions
- progressively enhance for WordPress 7.0
- avoid a WP 7-only API
- recommend a separate compatibility pass

## Context markdown file

After completing the required context gathering, save the gathered context to a markdown file in the WordPress project root before proposing implementation details.

Name the file:

```text
[project-name]_context.md
```

Derive `[project-name]` from the WordPress project root directory name. Normalize it for a filename:

- lowercase
- replace spaces with hyphens
- keep letters, numbers, hyphens, and underscores
- remove unsafe shell/path characters

Examples:

```text
my-client-site_context.md
dhali-pattern-library_context.md
watersafe_context.md
```

Save the file directly in the WordPress root, next to `wp-config.php`.

The context file should be concise and reusable. Include these sections when available:

```md
# [Project Name] WordPress Context

## Generated

- Date/time:
- WordPress root:
- Current working directory:

## WordPress Runtime

- Core version:
- PHP version:
- WordPress 7.0+:
- Notes:

## WordPress 7.0 API Readiness

- Block Bindings API:
- Pattern Overrides support:
- Interactivity API:
- Interactivity `watch()` / `state.url` relevance:
- Block Hooks:
- Font Library:
- Iframed editor risk:
- Custom Navigation Overlay relevance:
- Responsive/block visibility relevance:
- DataViews/DataForms relevance:
- AI Client / Connectors / Abilities relevance:
- PHP-only block registration relevance:
- REST namespaces checked:
- Compatibility concerns:

## Active Theme

- Name:
- Stylesheet slug:
- Template slug:
- Child theme:
- Child theme directory:
- Parent theme directory:

## Theme Token Sources Checked

- Child theme.json:
- Parent theme.json:
- Merged global settings source:

## Canonical Token Slugs

### Colors

### Gradients

### Spacing

### Font Sizes

### Font Families

### Shadows

### Border Radius

### Dimensions

### Custom Tokens

## Target Plugin or Theme

- Target type:
- Target directory:
- Relevant subdirectory:

## Existing Files Inspected

- File:
  - Why inspected:
  - Conventions noticed:
  - WordPress 7.0 relevance:

## Project Conventions

- PHP array style:
- Indentation:
- Block content style:
- Categories:
- Animation usage:
- Image asset references:
- Custom class rules:
- Block API versions:
- Binding/override conventions:
- Editor asset conventions:

## WordPress State Checked

- Plugins:
- Templates:
- Template parts:
- Synced patterns:
- REST namespaces:

## Task-Specific Notes

- Request:
- Design source:
- Assumptions:
- Missing information:
```

If a section is not relevant or could not be gathered, include it with a short note such as `Not checked`, `Not relevant`, or `Could not determine`.

If the context file already exists, update it in place rather than creating duplicates. Preserve useful previous notes when they are still accurate, but refresh facts that may have changed, such as active theme, WordPress version, API readiness, token slugs, plugin list, or inspected files.

After saving or updating the context file, mention its path in the response.

## Pattern authoring workflow

When the user asks for a new PHP block pattern, gather context first, then produce a proposal.

Before writing anything, show all four sections:

1. **Inferred layout**  
   Describe the visual structure in plain English.

2. **Block tree**  
   Provide an indented list of block names and key attributes only.

3. **Proposed filename**  
   Use:

   ```text
   dhali-pattern-library/patterns/{name}.php
   ```

4. **Complete PHP content**  
   Provide the full PHP return array with all block content inline.

Do not write the file until the user explicitly approves.

## Dhali pattern rules

For `dhali-pattern-library` block patterns:

- The PHP file must return an array.
- `categories` must include `dhali-web-development`.
- `categories` must also include one matching WordPress/core-style category such as:
  - `header`
  - `footer`
  - `navigation`
  - `card`
  - `query`
  - `template-part`
  - `text`
  - `columns`
  - `gallery`
  - `call-to-action`
- Prefer core blocks unless the existing project already uses a plugin block for the same purpose.
- In WordPress 7.0 projects, prefer new core blocks/features where they replace older custom solutions, especially Breadcrumbs, Icon, Navigation overlay support, Gallery lightbox/slideshow, Cover video backgrounds, and expanded dimensions/text controls.
- Prefer active theme preset tokens over hardcoded values.
- Prefer dimensions presets and theme.json button pseudo-element styling when available instead of one-off custom CSS.
- Match existing project indentation and content string style.
- Use `animationType` sparingly.
- Good places for animation:
  - section heading groups
  - major card groups
  - CTA button groups
  - interactive card wrappers
- Bad places for animation:
  - every paragraph
  - every nested group
  - every column
  - every icon
- Do not add `ollieCustomClasses` unless the class already exists in the project CSS.
- Do not invent custom class names just to style one pattern.
- Do not add custom CSS unless the user approves or the design cannot be achieved with block supports and theme tokens.
- If the pattern may be edited in WordPress 7.0 contentOnly or pattern override workflows, keep editable content in normal content-bearing blocks where possible and avoid hiding critical editable text inside non-content attributes unless the related block supports are confirmed.

## Block/plugin implementation rules for WordPress 7.0

When creating or modifying custom blocks/plugins:

- Prefer Block API v3+ unless project compatibility requires older versions.
- Ensure editor assets work in the iframed editor; do not rely on parent admin DOM selectors.
- Use `block.json` `role: "content"` on content attributes that must remain editable inside contentOnly/pattern override flows.
- Prefer Block Bindings and Pattern Overrides only after checking existing source registration and supported attributes.
- For dynamic/PHP blocks targeting WP 7.0-only, consider PHP-only block registration with `supports.autoRegister` and a render callback.
- For interactive frontend blocks, prefer the current Interactivity API pattern, including `watch()` and server-populated `state.url` where relevant.
- For admin UI, prefer modern DataViews/DataForms patterns over fragile customizations of core list tables.
- For AI-facing work, check whether the site exposes or needs AI Client, Connectors, Abilities, or MCP-related infrastructure before adding integration code.

## Approval workflow

After the user approves the proposed file:

1. Write the file.
2. Run PHP lint:

```bash
php -l dhali-pattern-library/patterns/{name}.php
```

3. Report:
   - file written
   - lint command run
   - lint result
   - any warnings or follow-up checks

Do not claim the file was written unless it was actually written.

## Response style

Be concise but explicit.

Always summarize the gathered context before proposing code.

When relevant, include:

- WordPress root
- WordPress core version
- WordPress 7.0 API readiness
- active theme
- token source checked
- pattern examples inspected
- target file path
- assumptions
- missing information

If required context cannot be gathered, state what is missing and make the safest partial proposal without writing files.

