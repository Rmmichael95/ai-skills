---
name: dhali-fse
description: Rules for Dhali block patterns using Ollie Pro theme. Procedure in prompt. Reference in MCP/files.
---

# Dhali FSE Rules

## Hard Rules

- static-first: no Query Loop/post-template/dynamic unless user says "Query Loop" "dynamic posts" "latest posts" "archive" "post template" "convert to dynamic"
- visual containment: element inside region in source → inside that region in blocks. no silent moves.
- icons: outermost/icon-block iconName:"". read static assets/icons/ file. question.svg default. no core/html. no generated SVG for different shape.
- images: plugin_dir_url(dirname(**FILE**)).'assets/images/FILENAME'. no get_template_directory_uri(). no remote URLs.
- text: placeholder only. headings="This is a title". paragraphs="This is an example of paragraph text". dates="January 1, 2025". no screenshot content.
- social icons: core/social-links + core/social-link. no question.svg for Instagram/Facebook/X.
- tokens: Ollie Pro preset slugs only. no invented classes/hexes/names.
- no writes before Approved.
- viewportWidth: 1500 always.
- esc_html\_\_() concatenation for all user-visible text in content strings.
- outermost group metadata: name + categories + patternName — all three together.

## WP7 Serializer Rules

Violations cause "Block contains unexpected or invalid content". parse_blocks() does NOT catch these.

- core/group border: inside "style" always. {"style":{"border":{"radius":"..."}}}. top-level "border" key = error.
- core/cover class order: is-light FIRST. then has-custom-content-position. then is-position-\*.
- core/cover child order: <img> BEFORE <span> in saved HTML.
- flex groups: layout classes in className attr AND div both. justifyContent:space-between → also add items-justified-space-between to both. omitting className = serializer mismatch.
- never write Cover markup from memory. read cover-with-badge snippet file.

## Validation

Fast (sequential, stop on failure): write → php -l → lint → parse.

Deep (always run for): core/cover, outermost/icon-block with SVG, linked core/group (href/linkDestination/animationType), core/query, core/post-template, fast returned warnings/errors.

Skip message: "Fast validation passed. Editor-context validation skipped — no required trigger. Manual editor check recommended."

