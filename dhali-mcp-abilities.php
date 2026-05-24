<?php
/**
 * Plugin Name: Dhali MCP Abilities
 * Description: Local WordPress MCP abilities for Claude/agent workflows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Schema helpers ──────────────────────────────────────────────────────────

/**
 * Shared MCP metadata for public tools.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_public_tool_meta() {
	return array(
		'show_in_rest' => true,
		'mcp'          => array(
			'public' => true,
			'type'   => 'tool',
		),
	);
}

/**
 * Register ability categories before ability registration.
 *
 * The Abilities API requires categories to be registered on
 * wp_abilities_api_categories_init before abilities reference them.
 *
 * @return void
 */
function dhali_register_mcp_ability_categories() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	$categories = array(
		'site'             => array(
			'label'       => __( 'Dhali MCP Site Tools', 'dhali' ),
			'description' => __( 'Read-only and validation abilities for Dhali pattern authoring workflows.', 'dhali' ),
		),
		'dhali-patterns'   => array(
			'label'       => __( 'Dhali Pattern Authoring', 'dhali' ),
			'description' => __( 'Pattern skeletons, snippets, local assets, and editor-safety validation.', 'dhali' ),
		),
		'dhali-diagnostics' => array(
			'label'       => __( 'Dhali MCP Diagnostics', 'dhali' ),
			'description' => __( 'Small diagnostic abilities for MCP stability and permissions.', 'dhali' ),
		),
	);

	foreach ( $categories as $slug => $args ) {
		wp_register_ability_category( $slug, $args );
	}
}
add_action( 'wp_abilities_api_categories_init', 'dhali_register_mcp_ability_categories' );

/**
 * Build a non-empty request-only input schema.
 *
 * The WordPress MCP Adapter execute tool expects a top-level `parameters` object.
 * A required request enum keeps Claude from sending an empty object.
 *
 * @param string $request_value Required request value.
 * @param string $description   Field description.
 * @return array<string, mixed>
 */
function dhali_mcp_request_input_schema( $request_value, $description ) {
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'request' => array(
				'type'        => 'string',
				'description' => $description,
				'enum'        => array( $request_value ),
				'default'     => $request_value,
			),
		),
		'required'             => array( 'request' ),
		'additionalProperties' => false,
	);
}

/**
 * Build a string property schema.
 *
 * @param string $description Field description.
 * @return array<string, string>
 */
function dhali_mcp_string_schema( $description ) {
	return array(
		'type'        => 'string',
		'description' => $description,
	);
}

/**
 * Build a string array property schema.
 *
 * @param string $description Field description.
 * @return array<string, mixed>
 */
function dhali_mcp_string_array_schema( $description ) {
	return array(
		'type'        => 'array',
		'description' => $description,
		'items'       => array( 'type' => 'string' ),
	);
}

// ─── Array utilities ─────────────────────────────────────────────────────────

/**
 * Collect unique string values for a key from a nested array.
 *
 * Handles theme.json settings grouped by origin (theme, default, etc.).
 *
 * @param mixed  $value Nested value.
 * @param string $key   Key to collect.
 * @return array<int, string>
 */
function dhali_mcp_collect_values_by_key( $value, $key = 'slug' ) {
	$values = array();

	if ( ! is_array( $value ) ) {
		return $values;
	}

	foreach ( $value as $item_key => $item_value ) {
		if ( $item_key === $key && is_string( $item_value ) && '' !== $item_value ) {
			$values[] = $item_value;
			continue;
		}

		if ( is_array( $item_value ) ) {
			$values = array_merge( $values, dhali_mcp_collect_values_by_key( $item_value, $key ) );
		}
	}

	return array_values( array_unique( $values ) );
}

/**
 * Read a nested array value by path.
 *
 * @param array<int|string, mixed> $array Source array.
 * @param array<int, string>       $path  Path segments.
 * @return mixed|null
 */
function dhali_mcp_array_get( $array, $path ) {
	$current = $array;

	foreach ( $path as $segment ) {
		if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
			return null;
		}

		$current = $current[ $segment ];
	}

	return $current;
}

/**
 * Merge slugs from multiple theme settings paths.
 *
 * Reads from raw theme.json only — intentionally avoids merging wp_get_global_settings()
 * or WP_Theme_JSON_Resolver output to prevent WordPress core presets (numeric spacing,
 * Inter/Cardo fonts, core palette) from bleeding into pattern token references.
 *
 * @param array<int|string, mixed>       $settings        Theme settings from WP_Theme_JSON_Resolver.
 * @param array<int|string, mixed>       $global_settings Settings from wp_get_global_settings().
 * @param array<int|string, mixed>       $raw_settings    Settings from active theme.json.
 * @param array<int, array<int, string>> $paths           Candidate paths.
 * @return array<int, string>
 */
function dhali_mcp_collect_token_slugs_from_paths( $settings, $global_settings, $raw_settings, $paths ) {
	$slugs = array();

	foreach ( $paths as $path ) {
		$slugs = array_merge( $slugs, dhali_mcp_collect_values_by_key( dhali_mcp_array_get( $raw_settings, $path ), 'slug' ) );
	}

	return array_values( array_unique( array_filter( $slugs ) ) );
}

// ─── Theme data ───────────────────────────────────────────────────────────────

/**
 * Read active theme theme.json directly.
 *
 * @return array{source: string, version: string, settings: array<string, mixed>}
 */
function dhali_mcp_get_raw_theme_json_settings_data() {
	$paths = array( trailingslashit( get_stylesheet_directory() ) . 'theme.json' );

	$template_path = trailingslashit( get_template_directory() ) . 'theme.json';
	if ( ! in_array( $template_path, $paths, true ) ) {
		$paths[] = $template_path;
	}

	foreach ( $paths as $path ) {
		if ( ! is_readable( $path ) ) {
			continue;
		}

		$raw  = file_get_contents( $path );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			continue;
		}

		return array(
			'source'   => $path,
			'version'  => isset( $data['version'] ) ? (string) $data['version'] : '',
			'settings' => isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
		);
	}

	return array(
		'source'   => 'unavailable',
		'version'  => '',
		'settings' => array(),
	);
}

/**
 * Returns the WordPress project snapshot data.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_project_snapshot_data() {
	$theme  = wp_get_theme();
	$raw    = dhali_mcp_get_raw_theme_json_settings_data();
	$layout = dhali_mcp_array_get( $raw['settings'], array( 'layout' ) );

	return array(
		'core_version'    => get_bloginfo( 'version' ),
		'php_version'     => PHP_VERSION,
		'active_theme'    => $theme->get( 'Name' ),
		'theme_slug'      => get_stylesheet(),
		'template'        => get_template(),
		'is_child_theme'  => get_template() !== get_stylesheet(),
		'layout_defaults' => is_array( $layout ) ? $layout : array(),
	);
}

/**
 * Returns the active theme token and layout data from theme.json.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_token_and_layout_data() {
	$raw_data        = dhali_mcp_get_raw_theme_json_settings_data();
	$raw_settings    = $raw_data['settings'];
	$settings        = function_exists( 'WP_Theme_JSON_Resolver' ) ? array() : array();
	$global_settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();

	$colors = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'color', 'palette' ),
		)
	);

	$gradients = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'color', 'gradients' ),
		)
	);

	$duotone = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'color', 'duotone' ),
		)
	);

	$spacing = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'spacing', 'spacingSizes' ),
		)
	);

	$font_sizes = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'typography', 'fontSizes' ),
		)
	);

	$font_families = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'typography', 'fontFamilies' ),
		)
	);

	$shadows = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global_settings,
		$raw_settings,
		array(
			array( 'shadow', 'presets' ),
		)
	);

	$border_radius = array();
	$custom_radius = dhali_mcp_array_get( $raw_settings, array( 'custom', 'borderRadius' ) );
	if ( is_array( $custom_radius ) ) {
		$border_radius = array_values( array_filter( array_keys( $custom_radius ), 'is_string' ) );
	}

	$layout = dhali_mcp_array_get( $raw_settings, array( 'layout' ) );
	$custom = dhali_mcp_array_get( $raw_settings, array( 'custom' ) );

	return array(
		'token_source'       => $raw_data['source'],
		'theme_json_version' => $raw_data['version'],
		'colors'             => $colors,
		'gradients'          => $gradients,
		'duotone'            => $duotone,
		'spacing'            => $spacing,
		'font_sizes'         => $font_sizes,
		'font_families'      => $font_families,
		'shadows'            => $shadows,
		'border_radius'      => $border_radius,
		'layout'             => is_array( $layout ) ? $layout : array(),
		'custom'             => is_array( $custom ) ? $custom : array(),
	);
}

// ─── Local asset helpers ──────────────────────────────────────────────────────

/**
 * Scans the active Ollie theme for local placeholder image filenames.
 *
 * Returns only the filenames (e.g. 'desktop.webp', 'avatar-1.webp'), not full
 * paths. The agent constructs the full URL via PHP string concatenation:
 * esc_url( get_template_directory_uri() ) . '/patterns/images/' . $filename
 *
 * @return array<int, string>
 */
function dhali_mcp_get_local_image_placeholders() {
	$dir = get_template_directory() . '/patterns/images/';

	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$files  = array_diff( scandir( $dir ), array( '.', '..' ) );
	$assets = array();

	foreach ( $files as $file ) {
		if ( preg_match( '/\.(webp|jpg|jpeg|png|svg|gif)$/i', $file ) ) {
			$assets[] = $file;
		}
	}

	sort( $assets );

	return array_values( $assets );
}

// ─── Context helpers ─────────────────────────────────────────────────────────

/**
 * Resolve the project slug for a context filename.
 *
 * @return string
 */
function dhali_mcp_get_project_slug() {
	$root = untrailingslashit( ABSPATH );
	$slug = basename( $root );

	if ( in_array( $slug, array( 'public_html', 'htdocs', 'www', 'web' ), true ) ) {
		$parent = basename( dirname( $root ) );
		if ( '' !== $parent && '.' !== $parent ) {
			$slug = $parent;
		}
	}

	$slug = sanitize_title( $slug );

	return '' !== $slug ? $slug : 'WordPress';
}

/**
 * Build concise reusable context markdown.
 *
 * MERGE: Now appends the available local placeholder image list so the agent
 * never needs a separate dhali/get-local-assets call after a successful sync.
 *
 * @return string
 */
function dhali_mcp_build_context_markdown() {
	$snapshot = dhali_mcp_get_project_snapshot_data();
	$tokens   = dhali_mcp_get_token_and_layout_data();

	$content  = '# ' . ucwords( str_replace( array( '-', '_' ), ' ', dhali_mcp_get_project_slug() ) ) . " WordPress Context\n\n";
	$content .= "## Generated\n\n";
	$content .= '- Date/time: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
	$content .= '- WordPress root: ' . untrailingslashit( ABSPATH ) . "\n";
	$content .= '- Token source: ' . $tokens['token_source'] . "\n";
	$content .= '- theme.json version: ' . $tokens['theme_json_version'] . "\n\n";

	$content .= "## WordPress Runtime\n\n";
	$content .= '- Core version: ' . $snapshot['core_version'] . "\n";
	$content .= '- PHP version: ' . $snapshot['php_version'] . "\n";
	$content .= '- Active theme: ' . $snapshot['active_theme'] . "\n";
	$content .= '- Theme slug: ' . $snapshot['theme_slug'] . "\n";
	$content .= '- Template: ' . $snapshot['template'] . "\n";
	$content .= '- Child theme: ' . ( $snapshot['is_child_theme'] ? 'yes' : 'no' ) . "\n\n";

	$content .= "## Layout\n\n";
	$content .= "```json\n";
	$content .= wp_json_encode( $tokens['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	$content .= "\n```\n\n";

	$content .= "## Canonical Token Slugs\n\n";
	$content .= '- Colors: ' . ( ! empty( $tokens['colors'] ) ? implode( ', ', $tokens['colors'] ) : 'None found' ) . "\n";
	$content .= '- Gradients: ' . ( ! empty( $tokens['gradients'] ) ? implode( ', ', $tokens['gradients'] ) : 'None found' ) . "\n";
	$content .= '- Duotone: ' . ( ! empty( $tokens['duotone'] ) ? implode( ', ', $tokens['duotone'] ) : 'None found' ) . "\n";
	$content .= '- Spacing: ' . ( ! empty( $tokens['spacing'] ) ? implode( ', ', $tokens['spacing'] ) : 'None found' ) . "\n";
	$content .= '- Font sizes: ' . ( ! empty( $tokens['font_sizes'] ) ? implode( ', ', $tokens['font_sizes'] ) : 'None found' ) . "\n";
	$content .= '- Font families: ' . ( ! empty( $tokens['font_families'] ) ? implode( ', ', $tokens['font_families'] ) : 'None found' ) . "\n";
	$content .= '- Shadows: ' . ( ! empty( $tokens['shadows'] ) ? implode( ', ', $tokens['shadows'] ) : 'None found' ) . "\n";
	$content .= '- Border radius: ' . ( ! empty( $tokens['border_radius'] ) ? implode( ', ', $tokens['border_radius'] ) : 'None found' ) . "\n\n";

	$content .= "## Custom Tokens\n\n";
	$content .= "```json\n";
	$content .= wp_json_encode( $tokens['custom'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	$content .= "\n```\n\n";

	// MERGE: Inject available local image filenames so the agent does not need
	// a separate dhali/get-local-assets call after context is fresh.
	$images = dhali_mcp_get_local_image_placeholders();
	if ( ! empty( $images ) ) {
		$content .= "## Available Local Placeholder Images\n\n";
		$content .= "Reference these filenames via PHP string concatenation only. Never use remote image URLs.\n\n";
		$content .= "PHP pattern: `' . esc_url( get_template_directory_uri() ) . '/patterns/images/FILENAME'`\n\n";
		foreach ( $images as $image ) {
			$content .= '- `' . $image . "`\n";
		}
		$content .= "\n";
	} else {
		$content .= "## Available Local Placeholder Images\n\n";
		$content .= "No images found in theme patterns/images/ directory.\n\n";
	}

	return $content;
}

// ─── Lint helpers ─────────────────────────────────────────────────────────────

/**
 * Build an issue object for pattern authoring lint.
 *
 * @param string $severity Severity: error, warning, or info.
 * @param string $rule     Rule identifier.
 * @param string $message  Human-readable message.
 * @return array<string, string>
 */
function dhali_mcp_pattern_issue( $severity, $rule, $message ) {
	return array(
		'severity' => $severity,
		'rule'     => $rule,
		'message'  => $message,
	);
}

/**
 * Count issues by severity.
 *
 * @param array<int, array<string, string>> $issues Issues.
 * @return array<string, int>
 */
function dhali_mcp_issue_counts( $issues ) {
	$counts = array(
		'error'   => 0,
		'warning' => 0,
		'info'    => 0,
	);

	foreach ( $issues as $issue ) {
		$severity = isset( $issue['severity'] ) ? $issue['severity'] : 'info';
		if ( isset( $counts[ $severity ] ) ) {
			++$counts[ $severity ];
		}
	}

	return $counts;
}

/**
 * Return parsed named block names from WordPress block markup.
 *
 * @param string $markup Block markup.
 * @return array<int, string>
 */
function dhali_mcp_get_block_names_from_markup( $markup ) {
	$blocks = parse_blocks( $markup );

	return array_values(
		array_filter(
			array_map(
				function ( $block ) {
					return isset( $block['blockName'] ) && is_string( $block['blockName'] ) && '' !== $block['blockName']
						? $block['blockName']
						: '';
				},
				$blocks
			)
		)
	);
}

/**
 * Build a schema-safe validation failure result for ability exceptions.
 *
 * @param string $rule    Rule identifier.
 * @param string $message Error message.
 * @param string $context Pattern context.
 * @return array<string, mixed>
 */
function dhali_mcp_lint_exception_result( $rule, $message, $context = 'standalone' ) {
	$issues = array(
		dhali_mcp_pattern_issue( 'error', $rule, $message ),
	);

	return array(
		'valid'        => false,
		'php_syntax'   => 'skipped',
		'context'      => $context,
		'issue_counts' => dhali_mcp_issue_counts( $issues ),
		'issues'       => $issues,
	);
}

/**
 * Lint block markup token references against allowed Ollie Pro slugs.
 *
 * @param string $markup Block markup to inspect.
 * @return array<int, array<string, string>>
 */
function dhali_mcp_lint_token_references( $markup ) {
	$issues = array();

	$allowed = array(
		'color'         => array( 'primary', 'primary-accent', 'primary-alt', 'primary-alt-accent', 'main', 'main-accent', 'base', 'secondary', 'tertiary', 'border-light', 'border-dark' ),
		'spacing'       => array( 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'xxxx-large' ),
		'border-radius' => array( 'xs', 'sm', 'md', 'lg', 'xl', '2-xl', 'full' ),
		'font-size'     => array( 'x-small', 'small', 'base', 'medium', 'large', 'x-large', 'xx-large' ),
		'shadow'        => array( 'small-light', 'medium-light', 'large-light', 'extra-large-light', 'small-dark', 'medium-dark', 'large-dark', 'extra-large-dark' ),
	);

	// Match var:preset|category|slug and var(--wp--preset--category--slug) references.
	preg_match_all( '/var:preset\|([a-z-]+)\|([a-z0-9-]+)/', $markup, $attr_matches, PREG_SET_ORDER );
	preg_match_all( '/var\(--wp--preset--([a-z-]+)--([a-z0-9-]+)\)/', $markup, $css_matches, PREG_SET_ORDER );

	$references = array();
	foreach ( $attr_matches as $m ) {
		$references[] = array(
			'category' => $m[1],
			'slug'     => $m[2],
			'raw'      => $m[0],
		);
	}
	foreach ( $css_matches as $m ) {
		$references[] = array(
			'category' => $m[1],
			'slug'     => $m[2],
			'raw'      => $m[0],
		);
	}

	foreach ( $references as $reference ) {
		$category = $reference['category'];
		$slug     = $reference['slug'];

		if ( ! isset( $allowed[ $category ] ) ) {
			continue;
		}

		if ( ! in_array( $slug, $allowed[ $category ], true ) ) {
			$issues[] = dhali_mcp_pattern_issue(
				'error',
				'unknown_theme_token',
				sprintf( 'Unknown %s preset slug "%s" in %s.', $category, $slug, $reference['raw'] )
			);
		}
	}

	return $issues;
}

/**
 * Apply Dhali/Ollie authoring lint rules to proposed block markup.
 *
 * @param string $markup  Block markup.
 * @param string $context Pattern context: standalone, query_loop, post_template, template_part.
 * @return array<string, mixed>
 */
function dhali_mcp_lint_pattern_markup( $markup, $context = 'standalone' ) {
	$issues  = array();
	$context = is_string( $context ) && '' !== $context ? $context : 'standalone';

	// ── Structural checks ──────────────────────────────────────────────────

	if ( '' === trim( $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'empty_markup', 'Markup is empty.' );
	}

	if ( false !== strpos( $markup, 'PLACEHOLDER' ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'placeholder_text', 'Markup contains PLACEHOLDER text.' );
	}

	if ( substr_count( $markup, '<!-- wp:' ) !== substr_count( $markup, '<!-- /wp:' ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'block_comment_mismatch', 'Opening and closing block comment counts do not match.' );
	}

	if ( preg_match( '/\bwp--preset--[a-z-]*-\s/', $markup ) || preg_match( '/has-[a-z-]*-\s/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'wrapped_or_truncated_identifier', 'Markup contains a wrapped or truncated CSS variable or class name.' );
	}

	// ── Class consistency checks ───────────────────────────────────────────

	// has-custom-font-size is only valid when style.typography.fontSize (a custom value) is set.
	// With a preset fontSize slug it must not appear — doing so causes a JS serializer mismatch.
	if ( preg_match( '/has-custom-font-size/', $markup ) &&
		! preg_match( '/"style"\s*:\s*\{[^{}]*"typography"\s*:\s*\{[^{}]*"fontSize"/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'invalid_custom_font_size_class',
			'has-custom-font-size is present without a style.typography.fontSize value. Remove it when using a preset fontSize slug — use has-{slug}-font-size instead.'
		);
	}

	// ── Media / image checks ───────────────────────────────────────────────

	if ( preg_match( '/"useFeaturedImage"\s*:\s*true/', $markup ) &&
		! in_array( $context, array( 'query_loop', 'post_template' ), true ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_dynamic_featured_image_in_standalone_pattern',
			'Do not use useFeaturedImage:true for standalone patterns. Use static core/cover or core/image with a real attachment ID instead.'
		);
	}

	if ( preg_match( '/"id"\s*:\s*0\b/', $markup ) || preg_match( '/wp-image-0\b/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'fake_attachment_id', 'Pattern contains id:0 or wp-image-0. Provide a real attachment ID or ask the user for the media asset.' );
	}

	$placeholder_image_hosts = array( 'picsum.photos', 'placehold.co', 'placeholder.com', 'loremflickr.com', 'dummyimage.com', 'via.placeholder.com' );
	foreach ( $placeholder_image_hosts as $host ) {
		if ( false !== strpos( $markup, $host ) ) {
			$issues[] = dhali_mcp_pattern_issue( 'error', 'remote_placeholder_image', 'Pattern references a remote placeholder image from ' . $host . '. Use local theme assets via esc_url( get_template_directory_uri() ) . \'/patterns/images/FILENAME\' instead.' );
			break;
		}
	}

	if ( preg_match( '/"css"\s*:\s*"overflow\s*:\s*hidden/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'css_overflow_hack', 'Generated block-level style.css overflow:hidden detected. Use normal block supports or editor-copied markup.' );
	}

	// ── Icon / SVG checks ─────────────────────────────────────────────────

	if ( preg_match( '/wp:outermost\/icon-block/', $markup ) ) {
		// Empty SVG shell — icon library will not hydrate it.
		if ( preg_match( '/<svg[^>]*>\s*<\/svg>/', $markup ) ) {
			$issues[] = dhali_mcp_pattern_issue( 'error', 'outermost_icon_empty_svg', 'outermost/icon-block contains an empty SVG shell. Include the full saved SVG path or use core/html for decorative SVGs.' );
		}

		// iconColorValue or iconBackgroundColorValue using CSS variables instead of resolved hex.
		if ( preg_match( '/"iconColorValue"\s*:\s*"var\(/', $markup ) || preg_match( '/"iconBackgroundColorValue"\s*:\s*"var\(/', $markup ) ) {
			$issues[] = dhali_mcp_pattern_issue( 'error', 'outermost_icon_css_var_color', 'iconColorValue or iconBackgroundColorValue must be a resolved editor hex value, not a CSS variable.' );
		}

		// Custom SVG icon blocks are allowed only when they use the editor-saved scaffold shape.
		if ( preg_match( '/<!--\s+wp:outermost\/icon-block\s+\{[^}]*"iconName"\s*:\s*""[^}]*\}\s+-->/', $markup ) ) {
			if ( ! preg_match( '/<!--\s+wp:outermost\/icon-block\s+\{[^}]*"iconName"\s*:\s*""[^}]*\}\s+-->\s*<div class="wp-block-outermost-icon-block">\s*<div class="icon-container" style="width:[^"]+;transform:rotate\(0deg\) scaleX\(1\) scaleY\(1\)">\s*<svg\b/s', $markup ) ) {
				$issues[] = dhali_mcp_pattern_issue( 'error', 'outermost_custom_svg_scaffold_mismatch', 'Custom SVG outermost/icon-block must use the exact editor-saved scaffold: wp-block-outermost-icon-block > icon-container with width and transform > inline svg. Do not adapt a named icon snippet.' );
			}
		}
	}

	// ── Fragile block / serializer fidelity checks ─────────────────────────

	// core/group does not become a link just because href/linkDestination are added.
	// Use a real core/button or an exact editor-saved linked-group scaffold.
	if ( preg_match( '/<!--\s+wp:group\s+\{[^}]*"href"\s*:/s', $markup ) || preg_match( '/<!--\s+wp:group\s+\{[^}]*"linkDestination"\s*:/s', $markup ) || preg_match( '/<!--\s+wp:group\s+\{[^}]*"animationType"\s*:/s', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'core_group_link_attributes', 'Do not synthesize CTA links by adding href, linkDestination, or animationType to core/group. Use core/button or an exact editor-saved linked-group snippet.' );
	}

	// Manual layout styles on core/group wrappers often create Gutenberg serializer mismatches.
	if ( preg_match( '/<div class="wp-block-group[^"]*"[^>]*style="[^"]*(?:width:|display:flex|align-items:|justify-content:)/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'manual_group_wrapper_layout_css', 'core/group wrapper contains manual layout CSS such as width/display/align-items/justify-content. Express layout through supported block attributes/classes or use a known editor-saved snippet.' );
	}

	// ── Token reference checks ─────────────────────────────────────────────

	$token_issues = dhali_mcp_lint_token_references( $markup );
	$issues       = array_merge( $issues, $token_issues );

	// ── Result ─────────────────────────────────────────────────────────────

	$counts = dhali_mcp_issue_counts( $issues );

	return array(
		'valid'        => 0 === $counts['error'],
		'context'      => $context,
		'issue_counts' => $counts,
		'issues'       => $issues,
	);
}

// ─── Editor-safe snippets data ────────────────────────────────────────────────

/**
 * Returns known-good editor-safe snippets and composition guidance.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_editor_safe_block_snippets_data() {
	return array(
		'guidelines' => array(
			'Fragile third-party blocks must be copied from editor-safe snippets or exact editor-saved markup; never adapt a named icon snippet into a custom SVG snippet.',
			'Custom SVG outermost/icon-block is allowed only through outermost-custom-svg-icon or exact editor-copied markup. Replace the inner SVG only; preserve the wrapper shape.',
			'Never use core/html as a default CTA. Use plus-cta-circle-button for circular plus CTAs or core/button with an Ollie style class for text CTAs.',
			'For covers, preserve wrapper classes, is-position-*, is-light/is-dark, wp-block-cover__image-background, wp-block-cover__background, and wp-block-cover__inner-container exactly.',
			'For flex groups, use editor-saved classes and block attributes. Do not inject manual display:flex, width, align-items, or justify-content into core/group wrappers.',
		),
		'snippets'   => array(

			'plus-cta-circle-button'     =>
				'<!-- wp:buttons {"metadata":{"name":"CTA"},"layout":{"type":"flex","justifyContent":"left"}} -->' .
				'<div class="wp-block-buttons">' .
				'<!-- wp:button {"style":{"color":{"text":"#1E1E26","background":"#fff29e"},"border":{"radius":"999px"},"spacing":{"padding":{"top":"0.55rem","right":"0.8rem","bottom":"0.55rem","left":"0.8rem"}}}} -->' .
				'<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" href="#" style="border-radius:999px;color:#1E1E26;background-color:#fff29e;padding-top:0.55rem;padding-right:0.8rem;padding-bottom:0.55rem;padding-left:0.8rem">+</a></div>' .
				'<!-- /wp:button -->' .
				'</div>' .
				'<!-- /wp:buttons -->',

			'plus-cta-circle-icon'       =>
				'Deprecated alias. Prefer plus-cta-circle-button. Do not create circular CTAs with core/group href/linkDestination/animationType unless copied exactly from the current editor.',

			'plus-cta-linked-group-icon'  =>
				'Deprecated. Prefer plus-cta-circle-button so serializer-safe core/button markup handles the link.',


			'card-with-shadow'            =>
				'<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium"}},"border":{"radius":"var:preset|border-radius|lg"},"shadow":"var:preset|shadow|small-light"},"backgroundColor":"base","layout":{"type":"constrained"}} -->' .
				'<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--preset--border-radius--lg);padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium);box-shadow:var(--wp--preset--shadow--small-light)"></div>' .
				'<!-- /wp:group -->',

			'flex-group-vertical'         =>
				'<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|medium"}},"layout":{"type":"flex","orientation":"vertical","flexWrap":"nowrap"}} -->' .
				'<div class="wp-block-group is-layout-flex is-vertical wp-block-group-is-layout-flex">' .
				'</div>' .
				'<!-- /wp:group -->',

			'flex-group-horizontal'       =>
				'<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|medium"}},"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap","justifyContent":"left","verticalAlignment":"center"}} -->' .
				'<div class="wp-block-group is-layout-flex is-horizontal wp-block-group-is-layout-flex">' .
				'</div>' .
				'<!-- /wp:group -->',

			'flex-group-note'             =>
				'Use flex-group-vertical or flex-group-horizontal for any core/group with layout.type "flex". ' .
				'Never generate flex group HTML from scratch. ' .
				'Required: layout classes (is-layout-flex, is-vertical/is-horizontal, wp-block-group-is-layout-flex). ' .
				'Required: blockGap omitted from inline style entirely — no gap: and no --wp--style--block-gap: in style="".',

			'static-cover-card-note'      =>
				'For standalone screenshot cards: use core/cover with explicit real media url, id (integer > 0), sizeSlug, and saved <img> markup. ' .
				'Never use useFeaturedImage:true outside Query Loop or post template context. ' .
				'Never use id:0, wp-image-0, placeholder image URLs, or overflow:hidden style.css.',

			'static-image-card-note'      =>
				'Use core/image for ordinary screenshot-matched featured images when no overlay content is required. Provide a real attachment ID.',

			'custom-svg-via-core-html'    =>
				'<!-- wp:html -->' .
				'<div style="width:56px;line-height:0" aria-hidden="true">' .
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" focusable="false">' .
				'<circle fill="#7f8b72" cx="32" cy="22" r="8"></circle><path fill="#7f8b72" d="M31 31h2v23h-2zM29 48c-6-1-11-5-14-11 6 1 11 5 15 12zM35 48c6-1 11-5 14-11-6 1-11 5-15 12zM32 5l2 8h-4zM45 10l-4 7-3-3zM51 24l-8 2v-4zM45 38l-7-4 3-3zM19 38l4-7 3 3zM13 24l8-2v4zM19 10l7 4-3 3z"></path>' .
				'</svg>' .
				'</div>' .
				'<!-- /wp:html -->',

			'is-style-rounded-cover-note' =>
				'For team member photo cards, use core/cover with className:"is-style-rounded-cover", dimRatio:50, overlayColor:"main", isUserOverlayColor:true, contentPosition:"bottom center", isDark:false, and dimensions.aspectRatio:"3/4". ' .
				'Image URL via esc_url( get_template_directory_uri() ) . \'/patterns/images/FILENAME\'.',

			'is-style-rounded-full-note'  =>
				'For circular testimonial avatars, use core/image with className:"is-style-rounded-full", width:"60px", height:"60px", sizeSlug:"full". ' .
				'Image URL via esc_url( get_template_directory_uri() ) . \'/patterns/images/FILENAME\'.',
		),
	);
}

// ─── Ability registration ─────────────────────────────────────────────────────

/**
 * Register Dhali MCP abilities.
 */
function dhali_register_mcp_abilities() {
	static $registered = false;

	if ( $registered || ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$registered = true;

	$lint_issue_schema = array(
		'type'  => 'array',
		'items' => array(
			'type'       => 'object',
			'properties' => array(
				'severity' => dhali_mcp_string_schema( 'Issue severity: error, warning, or info.' ),
				'rule'     => dhali_mcp_string_schema( 'Rule identifier.' ),
				'message'  => dhali_mcp_string_schema( 'Human-readable issue message.' ),
			),
		),
	);

	$token_output_schema = array(
		'type'       => 'object',
		'properties' => array(
			'token_source'       => dhali_mcp_string_schema( 'Source used for token extraction.' ),
			'theme_json_version' => dhali_mcp_string_schema( 'theme.json version.' ),
			'colors'             => dhali_mcp_string_array_schema( 'Color preset slugs from theme.json.' ),
			'gradients'          => dhali_mcp_string_array_schema( 'Gradient preset slugs from theme.json.' ),
			'duotone'            => dhali_mcp_string_array_schema( 'Duotone preset slugs from theme.json.' ),
			'spacing'            => dhali_mcp_string_array_schema( 'Spacing preset slugs from theme.json.' ),
			'font_sizes'         => dhali_mcp_string_array_schema( 'Font size preset slugs from theme.json.' ),
			'font_families'      => dhali_mcp_string_array_schema( 'Font family preset slugs from theme.json.' ),
			'shadows'            => dhali_mcp_string_array_schema( 'Shadow preset slugs from theme.json.' ),
			'border_radius'      => dhali_mcp_string_array_schema( 'Border radius preset slugs from theme.json or custom tokens.' ),
			'layout'             => array(
				'type'        => 'object',
				'description' => 'The compiled theme.json layout settings.',
			),
			'custom'             => array(
				'type'        => 'object',
				'description' => 'Custom theme.json settings.',
			),
		),
		'required'   => array( 'token_source', 'theme_json_version', 'colors', 'gradients', 'duotone', 'spacing', 'font_sizes', 'font_families', 'shadows', 'border_radius', 'layout', 'custom' ),
	);

	$abilities = array(

		// ── dhali/get-site-info ──────────────────────────────────────────────
		'dhali/get-site-info'                  => array(
			'label'               => 'Get site info',
			'description'         => 'Returns the WordPress site title and active theme information.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'site_info', 'Use "site_info".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'site_title'        => dhali_mcp_string_schema( 'The WordPress site title.' ),
					'active_theme_name' => dhali_mcp_string_schema( 'The active theme display name.' ),
					'active_theme_slug' => dhali_mcp_string_schema( 'The active stylesheet/theme slug.' ),
					'template'          => dhali_mcp_string_schema( 'The parent template slug (same as active_theme_slug unless child theme).' ),
					'is_child_theme'    => array(
						'type'        => 'boolean',
						'description' => 'Whether the active theme is a child theme.',
					),
				),
				'required'   => array( 'site_title', 'active_theme_name', 'active_theme_slug', 'template', 'is_child_theme' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$theme = wp_get_theme();
				return array(
					'site_title'        => get_bloginfo( 'name' ),
					'active_theme_name' => $theme->get( 'Name' ),
					'active_theme_slug' => get_stylesheet(),
					'template'          => get_template(),
					'is_child_theme'    => get_template() !== get_stylesheet(),
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-project-snapshot ───────────────────────────────────────
		'dhali/get-project-snapshot'           => array(
			'label'               => 'Get project snapshot',
			'description'         => 'Returns a compact WordPress environment snapshot and layout defaults.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'project_snapshot', 'Use "project_snapshot".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'core_version'    => dhali_mcp_string_schema( 'The WordPress core version.' ),
					'php_version'     => dhali_mcp_string_schema( 'The PHP runtime version.' ),
					'active_theme'    => dhali_mcp_string_schema( 'The active theme display name.' ),
					'theme_slug'      => dhali_mcp_string_schema( 'The active stylesheet/theme slug.' ),
					'template'        => dhali_mcp_string_schema( 'The parent template slug.' ),
					'is_child_theme'  => array(
						'type'        => 'boolean',
						'description' => 'Whether the active theme is a child theme.',
					),
					'layout_defaults' => array(
						'type'        => 'object',
						'description' => 'The compiled theme.json layout settings.',
					),
				),
				'required'   => array( 'core_version', 'php_version', 'active_theme', 'theme_slug', 'template', 'is_child_theme', 'layout_defaults' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_project_snapshot_data();
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-token-and-layout-map ───────────────────────────────────
		'dhali/get-token-and-layout-map'       => array(
			'label'               => 'Get token and layout map',
			'description'         => 'Returns canonical active-theme theme.json preset slugs and layout settings.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'token_and_layout_map', 'Use "token_and_layout_map".' ),
			'output_schema'       => $token_output_schema,
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_token_and_layout_data();
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-local-assets ───────────────────────────────────────────
		// NEW: Returns available local placeholder image filenames from the active
		// Ollie theme. The agent uses these to construct image src attributes via
		// PHP string concatenation rather than inventing or using remote URLs.
		'dhali/get-local-assets'               => array(
			'label'               => 'Get local assets',
			'description'         => 'Returns available local placeholder image filenames from the Ollie theme patterns/images/ directory. Use these filenames with PHP string concatenation: esc_url( get_template_directory_uri() ) . \'/patterns/images/FILENAME\'.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'get_local_assets', 'Use "get_local_assets".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'      => dhali_mcp_string_schema( 'Success or error.' ),
					'images'      => dhali_mcp_string_array_schema( 'Available image filenames (e.g. desktop.webp, avatar-1.webp).' ),
					'php_pattern' => dhali_mcp_string_schema( 'PHP concatenation pattern to use for image src attributes.' ),
					'message'     => dhali_mcp_string_schema( 'Human-readable message.' ),
				),
				'required'   => array( 'status', 'images', 'php_pattern', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$images = dhali_mcp_get_local_image_placeholders();

				if ( empty( $images ) ) {
					return array(
						'status'      => 'error',
						'images'      => array(),
						'php_pattern' => '',
						'message'     => 'No images found in ' . get_template_directory() . '/patterns/images/. Check the theme directory.',
					);
				}

				return array(
					'status'      => 'success',
					'images'      => $images,
					'php_pattern' => "' . esc_url( get_template_directory_uri() ) . '/patterns/images/FILENAME'",
					'message'     => count( $images ) . ' images available. Replace FILENAME with one of the listed filenames.',
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-pattern-template-skeleton ──────────────────────────────
		// MERGE: Updated viewportWidth to 1500 (matching all Ollie upstream patterns)
		// and updated php_skeleton to use esc_html__() concatenation for i18n.
		'dhali/get-pattern-template-skeleton'  => array(
			'label'               => 'Get pattern template skeleton',
			'description'         => 'Returns the standard PHP return-array skeleton and write paths for Dhali block patterns.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'pattern_template_skeleton', 'Use "pattern_template_skeleton".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'categories'    => dhali_mcp_string_array_schema( 'Default pattern categories.' ),
					'keywords'      => dhali_mcp_string_array_schema( 'Default pattern keyword placeholders.' ),
					'viewportWidth' => array(
						'type'        => 'integer',
						'description' => 'Default preview viewport width.',
					),
					'blockTypes'    => dhali_mcp_string_array_schema( 'Default block type associations.' ),
					'plugin_path'   => dhali_mcp_string_schema( 'Absolute path to the dhali-pattern-library plugin directory.' ),
					'patterns_path' => dhali_mcp_string_schema( 'Absolute path to the patterns subdirectory where PHP files are written.' ),
					'php_skeleton'  => dhali_mcp_string_schema( 'PHP return-array skeleton for a new pattern file.' ),
					'i18n_note'     => dhali_mcp_string_schema( 'i18n rules for content string authoring.' ),
				),
				'required'   => array( 'categories', 'keywords', 'viewportWidth', 'blockTypes', 'plugin_path', 'patterns_path', 'php_skeleton', 'i18n_note' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
				$plugin_path   = trailingslashit( $plugin_dir ) . 'dhali-pattern-library';
				$patterns_path = trailingslashit( $plugin_path ) . 'patterns';

				// MERGE: viewportWidth updated to 1500 to match all Ollie upstream patterns.
				// MERGE: Content string now uses esc_html__() concatenation for i18n compliance.
				// The file is require'd by the plugin loader, so PHP runs at registration time.
				$php_skeleton = <<<'PHP'
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
		<!-- wp:heading -->
		<h2 class="wp-block-heading">' . esc_html__( 'Pattern Heading', 'dhali' ) . '</h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
',
);
PHP;

				return array(
					'categories'    => array( 'dhali-web-development', 'card' ),
					'keywords'      => array(),
					'viewportWidth' => 1500,
					'blockTypes'    => array( 'core/group' ),
					'plugin_path'   => $plugin_path,
					'patterns_path' => $patterns_path,
					'php_skeleton'  => $php_skeleton,
					'i18n_note'     => 'All user-visible text inside the content string must use PHP string concatenation: esc_html__() for text nodes, esc_attr__() for HTML attributes, esc_url( get_template_directory_uri() ) for image paths. Single quotes inside content must be escaped as \\\'. Never use bare text strings inside the content value.',
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-icon-manifest ──────────────────────────────────────────
		'dhali/get-icon-manifest'              => array(
			'label'               => 'Get icon manifest',
			'description'         => 'Returns Ollie/Outermost icon block markup templates for named and custom SVG icons.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'icon_manifest', 'Use "icon_manifest".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'icons' => array(
						'type'                 => 'object',
						'description'          => 'Map of icon slugs to block markup templates.',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'icons' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return array(
					'icons' => array(

						// Named Phosphor icon — ollie-phosphor-question with real SVG path.
						// iconColorValue is an editor-resolved hex, not a CSS variable.
						'outermost-named-icon'      =>
							'<!-- wp:outermost/icon-block {"iconName":"ollie-phosphor-question","iconColor":"primary","iconColorValue":"#5344F4","width":"1.75rem"} -->' .
							'<div class="wp-block-outermost-icon-block">' .
							'<div class="icon-container has-icon-color has-primary-color" style="color:#5344F4;width:1.75rem;transform:rotate(0deg) scaleX(1) scaleY(1)">' .
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false">' .
							'<path d="M140,180a12,12,0,1,1-12-12A12,12,0,0,1,140,180Zm-12-56c-11.05,0-20,7.61-20,17v4a8,8,0,0,0,16,0v-4c0-0.67,1.56-1,4-1s4,.33,4,1v4a8,8,0,0,0,16,0v-4C148,131.61,139.05,124,128,124Zm108,4A108,108,0,1,1,128,20,108.12,108.12,0,0,1,236,128Zm-16,0a92,92,0,1,0-92,92A92.1,92.1,0,0,0,220,128Z"></path>' .
							'</svg>' .
							'</div>' .
							'</div>' .
							'<!-- /wp:outermost/icon-block -->',

						// Custom SVG pill — use only when copying exact editor-saved custom icon markup.
						// Replace SVG path data before writing. iconName must be empty string "".
						'outermost-custom-svg-pill' =>
							'<!-- wp:outermost/icon-block {"iconName":"","iconColor":"base","iconColorValue":"#ffffff","iconBackgroundColor":"tertiary","iconBackgroundColorValue":"#f8f7fc","width":"90px","borderRadius":"50%","padding":"20px 5px"} -->' .
							'<div class="wp-block-outermost-icon-block">' .
							'<div class="icon-container has-icon-background-color has-tertiary-background-color" style="background-color:#f8f7fc;width:90px;padding-top:20px;padding-right:5px;padding-bottom:20px;padding-left:5px;border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);transform:rotate(0deg) scaleX(1) scaleY(1)">' .
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1E1E26" aria-hidden="true" focusable="false">' .
							'<circle cx="12" cy="12" r="10"/>' .
							'</svg></div></div>' .
							'<!-- /wp:outermost/icon-block -->',

						// Plain custom SVG icon scaffold copied from editor-saved Outermost/Icon markup.
						// Replace only the inner <svg> element; preserve both wrappers and the iconName empty-string attribute.
						'outermost-custom-svg-icon' =>
							'<!-- wp:outermost/icon-block {"iconName":"","width":"68px"} -->' .
							'<div class="wp-block-outermost-icon-block"><div class="icon-container" style="width:68px;transform:rotate(0deg) scaleX(1) scaleY(1)">' .
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-hidden="true" focusable="false"><path fill="#7f8b72" d="M32 8c10 0 18 8 18 18S42 44 32 44 14 36 14 26 22 8 32 8z"></path></svg>' .
							'</div></div>' .
							'<!-- /wp:outermost/icon-block -->',

						'usage-note'                =>
							'Use outermost-named-icon only for known-good Phosphor icon slugs from editor-saved markup. ' .
							'Use outermost-custom-svg-pill only when copying exact editor-saved custom icon markup — replace the SVG path with the real path before writing. ' .
							'For AI-generated decorative SVGs, always use core/html (see get-editor-safe-block-snippets: custom-svg-via-core-html).',
					),
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/lint-pattern-authoring-rules ───────────────────────────────
		// Applies block-level authoring lint only. PHP syntax lint runs outside MCP
		// in the CLI workflow so PHP warnings/noise cannot destabilize the MCP
		// stdio transport.
		'dhali/lint-pattern-authoring-rules'   => array(
			'label'               => 'Lint pattern authoring rules',
			'description'         => 'Applies Dhali/Ollie editor-safety rules to proposed block markup. PHP syntax lint is intentionally skipped inside MCP; run php -l outside MCP before this ability.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'    => array(
						'type'    => 'string',
						'enum'    => array( 'lint_pattern_authoring_rules' ),
						'default' => 'lint_pattern_authoring_rules',
					),
					'markup'     => array(
						'type'        => 'string',
						'description' => 'Block markup string to lint (the content string extracted from the PHP pattern, not the full PHP file).',
					),
					'php_source' => array(
						'type'        => 'string',
						'description' => 'Optional legacy field. PHP syntax lint is intentionally skipped inside MCP; run php -l outside MCP before this ability.',
					),
					'context'    => array(
						'type'    => 'string',
						'enum'    => array( 'standalone', 'query_loop', 'post_template', 'template_part' ),
						'default' => 'standalone',
					),
				),
				'required'             => array( 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'        => array(
						'type'        => 'boolean',
						'description' => 'True when zero errors found across PHP lint and block lint.',
					),
					'php_syntax'   => dhali_mcp_string_schema( 'PHP syntax check result: passed, failed, or skipped.' ),
					'context'      => dhali_mcp_string_schema( 'Context used for linting.' ),
					'issue_counts' => array(
						'type'        => 'object',
						'description' => 'Lint issue counts by severity.',
					),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'php_syntax', 'context', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup     = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$context    = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';
				$php_source = isset( $input['php_source'] ) && is_string( $input['php_source'] ) ? $input['php_source'] : '';

				try {
					$lint_result = dhali_mcp_lint_pattern_markup( $markup, $context );
					$issues      = $lint_result['issues'];

					if ( '' !== $php_source ) {
						$issues[] = dhali_mcp_pattern_issue(
							'warning',
							'php_syntax_skipped_in_mcp',
							'PHP syntax lint was skipped inside MCP to keep the MCP transport stable. Run php -l on the written file outside MCP.'
						);
					}

					$counts = dhali_mcp_issue_counts( $issues );

					return array(
						'valid'        => 0 === $counts['error'],
						'php_syntax'   => 'skipped',
						'context'      => $lint_result['context'],
						'issue_counts' => $counts,
						'issues'       => $issues,
					);
				} catch ( Throwable $e ) {
					return dhali_mcp_lint_exception_result( 'ability_exception', $e->getMessage(), $context );
				}

			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/validate-pattern-markup ────────────────────────────────────
		'dhali/validate-pattern-markup'        => array(
			'label'               => 'Validate pattern markup',
			'description'         => 'Parses WordPress block markup and runs full authoring lint. Returns structured issues matching lint-pattern-authoring-rules output format.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'markup'  => array(
						'type'        => 'string',
						'description' => 'The WordPress block markup string to validate.',
					),
					'context' => array(
						'type'        => 'string',
						'description' => 'Pattern context for linting. Defaults to standalone.',
						'enum'        => array( 'standalone', 'query_loop', 'post_template', 'template_part' ),
						'default'     => 'standalone',
					),
				),
				'required'             => array( 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'        => array(
						'type'        => 'boolean',
						'description' => 'True when at least one named block parsed and lint returned zero errors.',
					),
					'block_count'  => array(
						'type'        => 'integer',
						'description' => 'Number of named top-level blocks (excludes null/freeform entries).',
					),
					'block_names'  => dhali_mcp_string_array_schema( 'Parsed top-level block names.' ),
					'issue_counts' => array(
						'type'        => 'object',
						'description' => 'Lint issue counts by severity.',
					),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'block_count', 'block_names', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup  = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$context = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';

				try {
					$lint        = dhali_mcp_lint_pattern_markup( $markup, $context );
					$block_names = dhali_mcp_get_block_names_from_markup( $markup );

					return array(
						'valid'        => ! empty( $block_names ) && $lint['valid'],
						'block_count'  => count( $block_names ),
						'block_names'  => $block_names,
						'issue_counts' => $lint['issue_counts'],
						'issues'       => $lint['issues'],
					);
				} catch ( Throwable $e ) {
					$issues = array( dhali_mcp_pattern_issue( 'error', 'ability_exception', $e->getMessage() ) );

					return array(
						'valid'        => false,
						'block_count'  => 0,
						'block_names'  => array(),
						'issue_counts' => dhali_mcp_issue_counts( $issues ),
						'issues'       => $issues,
					);
				}

			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-editor-safe-block-snippets ─────────────────────────────
		'dhali/get-editor-safe-block-snippets' => array(
			'label'               => 'Get editor-safe block snippets',
			'description'         => 'Returns known-good editor-safe snippets and composition guidance for fragile block types.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'editor_safe_block_snippets', 'Use "editor_safe_block_snippets".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'guidelines' => dhali_mcp_string_array_schema( 'Editor-safe authoring guidelines.' ),
					'snippets'   => array(
						'type'                 => 'object',
						'description'          => 'Map of snippet names to saved block markup or guidance strings.',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'guidelines', 'snippets' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_editor_safe_block_snippets_data();
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/test-pattern-in-editor-context ─────────────────────────────
		// Draft is only created when lint passes (zero errors). Creating a draft
		// with broken markup pollutes the DB and gives a misleading edit_url.
		'dhali/test-pattern-in-editor-context' => array(
			'label'               => 'Test pattern in editor context',
			'description'         => 'Runs lint, then — only if lint passes — creates a temporary draft post with the block markup and returns an edit URL for manual editor verification.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'   => array(
						'type'    => 'string',
						'enum'    => array( 'test_pattern_in_editor_context' ),
						'default' => 'test_pattern_in_editor_context',
					),
					'markup'    => array(
						'type'        => 'string',
						'description' => 'The WordPress block markup to place in a temporary draft.',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Post type for the temporary draft. Defaults to page.',
						'default'     => 'page',
					),
					'context'   => array(
						'type'    => 'string',
						'enum'    => array( 'standalone', 'query_loop', 'post_template', 'template_part' ),
						'default' => 'standalone',
					),
				),
				'required'             => array( 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'        => array(
						'type'        => 'boolean',
						'description' => 'True when lint passed and a preview draft was created. This is not a browser/editor recovery check.',
					),
					'status'       => dhali_mcp_string_schema( 'preview_ready, failed_validation, or error. preview_ready still requires manual/browser editor recovery check.' ),
					'editor_invalid_content_checked' => array( 'type' => 'boolean', 'description' => 'False for this ability; browser/editor recovery warning detection is outside PHP.' ),
					'manual_editor_check_required' => array( 'type' => 'boolean', 'description' => 'True when a draft was created and the user must confirm no recovery warning appears.' ),
					'post_id'      => array(
						'type'        => 'integer',
						'description' => 'Temporary draft post ID, or 0 if lint failed or insertion failed.',
					),
					'edit_url'     => dhali_mcp_string_schema( 'Admin edit URL for manual editor verification. Empty if draft was not created.' ),
					'block_count'  => array(
						'type'        => 'integer',
						'description' => 'Named top-level block count.',
					),
					'block_names'  => dhali_mcp_string_array_schema( 'Parsed top-level block names.' ),
					'issue_counts' => array(
						'type'        => 'object',
						'description' => 'Lint issue counts by severity.',
					),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'status', 'editor_invalid_content_checked', 'manual_editor_check_required', 'post_id', 'edit_url', 'block_count', 'block_names', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup    = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$post_type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'page';
				$context   = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';

				try {
					$lint        = dhali_mcp_lint_pattern_markup( $markup, $context );
					$block_names = dhali_mcp_get_block_names_from_markup( $markup );

					if ( ! $lint['valid'] ) {
						return array(
							'valid'        => false,
							'status'       => 'failed_validation',
							'editor_invalid_content_checked' => false,
							'manual_editor_check_required' => false,
							'post_id'      => 0,
							'edit_url'     => '',
							'block_count'  => count( $block_names ),
							'block_names'  => $block_names,
							'issue_counts' => $lint['issue_counts'],
							'issues'       => $lint['issues'],
						);
					}

					$post_id = wp_insert_post(
						array(
							'post_title'   => 'Dhali MCP Pattern Test - ' . gmdate( 'Y-m-d H:i:s' ),
							'post_type'    => post_type_exists( $post_type ) ? $post_type : 'page',
							'post_status'  => 'draft',
							'post_content' => $markup,
						),
						true
					);

					$issues = $lint['issues'];

					if ( is_wp_error( $post_id ) ) {
						$issues[] = dhali_mcp_pattern_issue( 'error', 'draft_creation_failed', $post_id->get_error_message() );
						$post_id  = 0;
					}

					$counts = dhali_mcp_issue_counts( $issues );

					return array(
						'valid'        => ! empty( $block_names ) && 0 === $counts['error'],
						'status'       => $post_id && 0 === $counts['error'] ? 'preview_ready' : 'error',
						'editor_invalid_content_checked' => false,
						'manual_editor_check_required' => (bool) $post_id,
						'post_id'      => (int) $post_id,
						'edit_url'     => $post_id ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
						'block_count'  => count( $block_names ),
						'block_names'  => $block_names,
						'issue_counts' => $counts,
						'issues'       => $issues,
					);
				} catch ( Throwable $e ) {
					$issues = array( dhali_mcp_pattern_issue( 'error', 'ability_exception', $e->getMessage() ) );

					return array(
						'valid'        => false,
						'status'       => 'error',
						'editor_invalid_content_checked' => false,
						'manual_editor_check_required' => false,
						'post_id'      => 0,
						'edit_url'     => '',
						'block_count'  => 0,
						'block_names'  => array(),
						'issue_counts' => dhali_mcp_issue_counts( $issues ),
						'issues'       => $issues,
					);
				}

			},
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),


		// ── dhali/mcp-health-check ─────────────────────────────────────────────
		'dhali/mcp-health-check'               => array(
			'label'               => 'Dhali MCP health check',
			'description'         => 'Returns a small read-only health snapshot for debugging MCP stability and permissions.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'mcp_health_check', 'Use "mcp_health_check".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'ok'                         => array( 'type' => 'boolean' ),
					'php_version'                => dhali_mcp_string_schema( 'PHP version.' ),
					'wp_debug'                   => array( 'type' => 'boolean' ),
					'user_can_edit_theme_options' => array( 'type' => 'boolean' ),
					'user_can_edit_pages'         => array( 'type' => 'boolean' ),
					'shell_exec_available'        => array( 'type' => 'boolean' ),
					'temp_dir_writable'           => array( 'type' => 'boolean' ),
					'temp_dir'                    => dhali_mcp_string_schema( 'WordPress temp directory.' ),
				),
				'required'   => array( 'ok', 'php_version', 'wp_debug', 'user_can_edit_theme_options', 'user_can_edit_pages', 'shell_exec_available', 'temp_dir_writable', 'temp_dir' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$temp_dir = get_temp_dir();

				return array(
					'ok'                         => true,
					'php_version'                => PHP_VERSION,
					'wp_debug'                   => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'user_can_edit_theme_options' => current_user_can( 'edit_theme_options' ),
					'user_can_edit_pages'         => current_user_can( 'edit_pages' ),
					'shell_exec_available'        => function_exists( 'shell_exec' ),
					'temp_dir_writable'           => wp_is_writable( $temp_dir ),
					'temp_dir'                    => $temp_dir,
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/sync-context ───────────────────────────────────────────────
		// MERGE: dhali_mcp_build_context_markdown() now appends the local image
		// list automatically, so the agent gets image filenames in one call and
		// does not need a separate dhali/get-local-assets call after a fresh sync.
		'dhali/sync-context'                   => array(
			'label'               => 'Sync context cache',
			'description'         => 'Updates the project context markdown file with current WordPress state and available local image assets. After a successful sync, the context file contains everything needed for pattern authoring without further MCP calls.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'       => array(
						'type'    => 'string',
						'enum'    => array( 'sync_context' ),
						'default' => 'sync_context',
					),
					'confirm_write' => array(
						'type'        => 'boolean',
						'description' => 'Must be true to write the context markdown file.',
					),
				),
				'required'             => array( 'request', 'confirm_write' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'        => dhali_mcp_string_schema( 'Write status: success or error.' ),
					'path'          => dhali_mcp_string_schema( 'Absolute path to the context markdown file.' ),
					'bytes_written' => array(
						'type'        => 'integer',
						'description' => 'Number of bytes written.',
					),
					'image_count'   => array(
						'type'        => 'integer',
						'description' => 'Number of local placeholder images injected into context.',
					),
					'message'       => dhali_mcp_string_schema( 'Human-readable result message.' ),
				),
				'required'   => array( 'status', 'path', 'bytes_written', 'image_count', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$project_slug = dhali_mcp_get_project_slug();
				$context_path = ABSPATH . 'context.md';
				$legacy_context_path = ABSPATH . $project_slug . '_context.md';

				if ( empty( $input['confirm_write'] ) ) {
					return array(
						'status'        => 'error',
						'path'          => $context_path,
						'bytes_written' => 0,
						'image_count'   => 0,
						'message'       => 'confirm_write must be true before the context file can be updated.',
					);
				}

				// dhali_mcp_build_context_markdown() now appends the image list.
				$content       = dhali_mcp_build_context_markdown();
				$bytes_written = file_put_contents( $context_path, $content );
				if ( false !== $bytes_written && $legacy_context_path !== $context_path ) {
					file_put_contents( $legacy_context_path, $content );
				}

				if ( false === $bytes_written ) {
					return array(
						'status'        => 'error',
						'path'          => $context_path,
						'bytes_written' => 0,
						'image_count'   => 0,
						'message'       => 'Failed to write context file. Check directory permissions.',
					);
				}

				$image_count = count( dhali_mcp_get_local_image_placeholders() );

				return array(
					'status'        => 'success',
					'path'          => $context_path,
					'bytes_written' => $bytes_written,
					'image_count'   => $image_count,
					'message'       => 'Context file updated at context.md and mirrored to legacy project_slug_context.md with current WordPress state and ' . $image_count . ' local image assets. No further MCP calls needed for standard pattern authoring.',
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

	);

	foreach ( $abilities as $name => $args ) {
		$result = wp_register_ability( $name, $args );

		if ( is_wp_error( $result ) ) {
			error_log( 'Dhali MCP ability failed to register: ' . $name . ' — ' . $result->get_error_message() );
		} elseif ( false === $result ) {
			error_log( 'Dhali MCP ability failed to register: ' . $name );
		}
	}
}
add_action( 'wp_abilities_api_init', 'dhali_register_mcp_abilities' );
