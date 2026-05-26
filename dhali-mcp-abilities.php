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
		),
	);
}

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
 * Get merged/global theme settings with safe fallbacks.
 *
 * @return array{settings: array<string, mixed>, global_settings: array<string, mixed>, raw_settings: array<string, mixed>, token_source: string, theme_json_version: string}
 */
function dhali_mcp_get_theme_settings_data() {
	$settings        = array();
	$global_settings = array();
	$raw_theme_json  = dhali_mcp_get_raw_theme_json_settings_data();

	if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		$theme_json = WP_Theme_JSON_Resolver::get_theme_data();

		if ( is_object( $theme_json ) && method_exists( $theme_json, 'get_settings' ) ) {
			$settings = $theme_json->get_settings();
		}
	}

	if ( function_exists( 'wp_get_global_settings' ) ) {
		$global_settings = wp_get_global_settings();
	}

	return array(
		'settings'           => is_array( $settings ) ? $settings : array(),
		'global_settings'    => is_array( $global_settings ) ? $global_settings : array(),
		'raw_settings'       => $raw_theme_json['settings'],
		'token_source'       => $raw_theme_json['source'],
		'theme_json_version' => $raw_theme_json['version'],
	);
}

/**
 * Return a compact project snapshot.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_project_snapshot_data() {
	$theme         = wp_get_theme();
	$settings_data = dhali_mcp_get_theme_settings_data();
	$settings      = $settings_data['settings'];
	$global        = $settings_data['global_settings'];

	$layout = dhali_mcp_array_get( $global, array( 'layout' ) );
	if ( ! is_array( $layout ) ) {
		$layout = dhali_mcp_array_get( $settings, array( 'layout' ) );
	}

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
 * Return token and layout data from compiled theme settings.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_token_and_layout_data() {
	$settings_data = dhali_mcp_get_theme_settings_data();
	$settings      = $settings_data['settings'];
	$global        = $settings_data['global_settings'];
	$raw           = $settings_data['raw_settings'];

	$layout = dhali_mcp_array_get( $raw, array( 'layout' ) );
	if ( ! is_array( $layout ) ) {
		$layout = dhali_mcp_array_get( $global, array( 'layout' ) );
	}
	if ( ! is_array( $layout ) ) {
		$layout = dhali_mcp_array_get( $settings, array( 'layout' ) );
	}

	$custom = dhali_mcp_array_get( $raw, array( 'custom' ) );
	if ( ! is_array( $custom ) ) {
		$custom = dhali_mcp_array_get( $settings, array( 'custom' ) );
	}
	if ( ! is_array( $custom ) ) {
		$custom = dhali_mcp_array_get( $global, array( 'custom' ) );
	}

	$colors = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'color', 'palette' ) )
	);

	$gradients = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'color', 'gradients' ) )
	);

	$duotone = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'color', 'duotone' ) )
	);

	$spacing = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'spacing', 'spacingSizes' ), array( 'spacing', 'spacingScale' ) )
	);

	$font_sizes = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'typography', 'fontSizes' ) )
	);

	$font_families = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'typography', 'fontFamilies' ) )
	);

	$shadows = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array( array( 'shadow', 'presets' ), array( 'shadow' ) )
	);

	$border_radius = dhali_mcp_collect_token_slugs_from_paths(
		$settings, $global, $raw,
		array(
			array( 'border', 'radiusSizes' ),
			array( 'border', 'radius' ),
			array( 'custom', 'borderRadius' ),
			array( 'custom', 'border-radius' ),
		)
	);

	// Some themes store radius tokens as associative custom keys rather than preset objects.
	if ( empty( $border_radius ) ) {
		foreach ( array(
			array( $raw, array( 'custom', 'borderRadius' ) ),
			array( $settings, array( 'custom', 'borderRadius' ) ),
			array( $raw, array( 'custom', 'border-radius' ) ),
			array( $settings, array( 'custom', 'border-radius' ) ),
		) as $attempt ) {
			$custom_radius = dhali_mcp_array_get( $attempt[0], $attempt[1] );
			if ( is_array( $custom_radius ) ) {
				$border_radius = array_values( array_filter( array_keys( $custom_radius ), 'is_string' ) );
				break;
			}
		}
	}

	return array(
		'token_source'       => $settings_data['token_source'],
		'theme_json_version' => $settings_data['theme_json_version'],
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

	return '' !== $slug ? $slug : 'wordpress';
}

/**
 * Build concise reusable context markdown.
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

	// Inject plugin asset list from dhali-pattern-library's own assets/ directory.
	// These are the only images and icons patterns should reference.
	// Do NOT reference Ollie theme assets (get_template_directory_uri).
	$plugin_dir = plugin_dir_path( __FILE__ );
	$images_dir = $plugin_dir . 'assets/images/';
	$icons_dir  = $plugin_dir . 'assets/icons/';
	$images     = array();
	$icons      = array();

	if ( is_dir( $images_dir ) ) {
		foreach ( array_diff( scandir( $images_dir ), array( '.', '..' ) ) as $file ) {
			if ( preg_match( '/\.(webp|jpg|jpeg|png|svg|gif)$/i', $file ) ) {
				$images[] = $file;
			}
		}
		sort( $images );
	}

	if ( is_dir( $icons_dir ) ) {
		foreach ( array_diff( scandir( $icons_dir ), array( '.', '..' ) ) as $file ) {
			if ( preg_match( '/\.(svg|png)$/i', $file ) ) {
				$icons[] = $file;
			}
		}
		sort( $icons );
	}

	$content .= "## Plugin Placeholder Assets\n\n";
	$content .= "All pattern images and icons must come from the dhali-pattern-library plugin's own assets/. ";
	$content .= "Use plugin_dir_url( dirname( __FILE__ ) ) — do NOT use dhali_pattern_library_image_url() (helper not registered by default). ";
	$content .= "Never reference Ollie theme assets (get_template_directory_uri).\n\n";

	if ( ! empty( $images ) ) {
		$content .= "**Images** — `' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME' ) . '`\n";
		foreach ( $images as $image ) {
			$content .= '- `' . $image . "`\n";
		}
		$content .= "\n";
	}

	if ( ! empty( $icons ) ) {
		$content .= "**Icons** — `' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/icons/FILENAME' ) . '`\n";
		foreach ( $icons as $icon ) {
			$content .= '- `' . $icon . "`\n";
		}
		$content .= "\n";
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
 * Extract preset token references from block markup.
 *
 * @param string $markup Block markup.
 * @return array<int, array{category: string, slug: string, raw: string}>
 */
function dhali_mcp_extract_preset_references( $markup ) {
	$references = array();

	if ( preg_match_all( '/var:preset\|([a-zA-Z0-9_-]+)\|([a-zA-Z0-9_-]+)/', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$references[] = array(
				'category' => $match[1],
				'slug'     => $match[2],
				'raw'      => $match[0],
			);
		}
	}

	if ( preg_match_all( '/var\(--wp--preset--([a-zA-Z0-9_-]+)--([a-zA-Z0-9_-]+)\)/', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$references[] = array(
				'category' => $match[1],
				'slug'     => $match[2],
				'raw'      => $match[0],
			);
		}
	}

	return $references;
}

/**
 * Validate known preset references against canonical active-theme tokens.
 *
 * @param string $markup Block markup.
 * @return array<int, array<string, string>>
 */
function dhali_mcp_lint_token_references( $markup ) {
	$tokens = dhali_mcp_get_token_and_layout_data();

	$allowed = array(
		'color'         => isset( $tokens['colors'] ) ? $tokens['colors'] : array(),
		'spacing'       => isset( $tokens['spacing'] ) ? $tokens['spacing'] : array(),
		'font-size'     => isset( $tokens['font_sizes'] ) ? $tokens['font_sizes'] : array(),
		'font-family'   => isset( $tokens['font_families'] ) ? $tokens['font_families'] : array(),
		'shadow'        => isset( $tokens['shadows'] ) ? $tokens['shadows'] : array(),
		'border-radius' => isset( $tokens['border_radius'] ) ? $tokens['border_radius'] : array(),
	);

	$issues = array();

	foreach ( dhali_mcp_extract_preset_references( $markup ) as $reference ) {
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
 * Count WordPress block comments while treating self-closing block comments as balanced.
 *
 * Valid WordPress syntax includes comments like `<!-- wp:post-title {"isLink":true} /-->`.
 * These must not be counted as unclosed opening comments.
 *
 * @param string $markup Block markup.
 * @return array{opening: int, closing: int, self_closing: int, balanced: bool}
 */
function dhali_mcp_count_block_comments( $markup ) {
	preg_match_all( '/<!--\s*wp:[^>]*?-->/s', $markup, $opening_matches );
	preg_match_all( '/<!--\s*\/wp:[^>]*?-->/s', $markup, $closing_matches );
	preg_match_all( '/<!--\s*wp:[^>]*?\/\s*-->/s', $markup, $self_closing_matches );

	$opening_total = isset( $opening_matches[0] ) ? count( $opening_matches[0] ) : 0;
	$closing_total = isset( $closing_matches[0] ) ? count( $closing_matches[0] ) : 0;
	$self_closing  = isset( $self_closing_matches[0] ) ? count( $self_closing_matches[0] ) : 0;
	$non_self_open = max( 0, $opening_total - $self_closing );

	return array(
		'opening'      => $non_self_open,
		'closing'      => $closing_total,
		'self_closing' => $self_closing,
		'balanced'     => $non_self_open === $closing_total,
	);
}

/**
 * Apply Dhali/Ollie authoring lint rules to proposed block markup.
 *
 * Intentionally stricter than parse_blocks(). Catches project-specific
 * editor-safety risks before a pattern is written or inserted.
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

	$comment_counts = dhali_mcp_count_block_comments( $markup );
	if ( ! $comment_counts['balanced'] ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'block_comment_mismatch',
			sprintf(
				'Non-self-closing opening and closing block comment counts do not match. Open: %d, close: %d, self-closing: %d. Self-closing comments ending in /--> are valid and count as balanced.',
				$comment_counts['opening'],
				$comment_counts['closing'],
				$comment_counts['self_closing']
			)
		);
	}

	if ( preg_match( '/\bwp--preset--[a-z-]*-\s/', $markup ) || preg_match( '/has-[a-z-]*-\s/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'wrapped_or_truncated_identifier', 'Markup appears to contain a wrapped or truncated CSS variable or class name.' );
	}

	// ── Class consistency checks ───────────────────────────────────────────

	// FIX: has-custom-font-size is only valid when style.typography.fontSize (a custom
	// value) is set. With a preset fontSize slug it must not appear — doing so causes a
	// JS serializer mismatch and "Block contains unexpected or invalid content" in the editor.
	if ( preg_match( '/has-custom-font-size/', $markup ) &&
		! preg_match( '/"style"\s*:\s*\{[^{}]*"typography"\s*:\s*\{[^{}]*"fontSize"/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'invalid_custom_font_size_class',
			'has-custom-font-size is present without a style.typography.fontSize value. WordPress only generates this class for custom (non-preset) font sizes. Remove it when using a preset fontSize slug such as "base" — use has-{slug}-font-size only.'
		);
	}

	// CONFIRMED by editor testing: preset fontSize on core/button must not serialize
	// has-custom-font-size. The saved link should contain has-{slug}-font-size only.
	if ( preg_match_all( '/<!--\s*wp:button\s+(\{.*?\})\s*-->.*?<a\s+[^>]*class="([^"]*)"/s', $markup, $button_blocks, PREG_SET_ORDER ) ) {
		foreach ( $button_blocks as $button_block ) {
			$attrs_raw = isset( $button_block[1] ) ? $button_block[1] : '';
			$classes   = isset( $button_block[2] ) ? $button_block[2] : '';

			if ( preg_match( '/"fontSize"\s*:\s*"([a-zA-Z0-9_-]+)"/', $attrs_raw, $font_match ) && false !== strpos( $classes, 'has-custom-font-size' ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'button_preset_font_size_must_not_have_custom_class',
					sprintf(
						'core/button uses preset fontSize "%s" but the saved <a> includes has-custom-font-size. Use has-%s-font-size only. For plus CTAs, prefer plus-cta-circle-button from dhali/get-editor-safe-block-snippets or an exact editor-copied/Ollie button snippet.',
						$font_match[1],
						$font_match[1]
					)
				);
			}
		}
	}


	// CONFIRMED by editor testing: manually generated styled core/button plus CTAs
	// can pass parse/lint checks but still show "Block contains unexpected or invalid content".
	// Do not ban core/button globally: Ollie uses core/buttons safely when copied from
	// editor/Ollie markup or when simple style classes are used. Warn only for custom
	// hand-assembled plus buttons with custom color/spacing/border.
	if ( preg_match_all( '/<!--\s*wp:button\s+(\{.*?\})\s*-->.*?<a\s+[^>]*>(\s*\+\s*)<\/a>/s', $markup, $plus_button_blocks, PREG_SET_ORDER ) ) {
		foreach ( $plus_button_blocks as $plus_button_block ) {
			$attrs_raw = isset( $plus_button_block[1] ) ? $plus_button_block[1] : '';

			if ( false !== strpos( $attrs_raw, '"color"' ) || false !== strpos( $attrs_raw, '"spacing"' ) || false !== strpos( $attrs_raw, '"border"' ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'warning',
					'generated_plus_cta_core_button_risk',
					'Manually generated styled core/button plus CTAs are serializer-risky in this site. Prefer plus-cta-circle-button from dhali/get-editor-safe-block-snippets, or paste an exact current-site/Ollie editor-copied button snippet.'
				);
			}
		}
	}

	// Custom HTML should not become the default CTA strategy. It is acceptable only as
	// an explicit diagnostic fallback or rare escape hatch.
	if ( preg_match( '/<!--\s*wp:html\s*-->\s*<a\s+[^>]*>\s*\+\s*<\/a>\s*<!--\s*\/wp:html\s*-->/s', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'warning',
			'core_html_plus_cta_diagnostic_only',
			'core/html plus CTA found. Use only as a diagnostic fallback. Prefer plus-cta-circle-button from dhali/get-editor-safe-block-snippets or an editor-copied/Ollie native button/link composition.'
		);
	}

	// ── Media / image checks ───────────────────────────────────────────────

	if ( preg_match( '/"useFeaturedImage"\s*:\s*true/', $markup ) &&
		! in_array( $context, array( 'query_loop', 'post_template' ), true ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_dynamic_featured_image_in_standalone_pattern',
			'Do not use useFeaturedImage:true for standalone screenshot-based patterns. Use a static image/cover or label the pattern as a post-template pattern explicitly.'
		);
	}

	if ( preg_match( '/"id"\s*:\s*0\b/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_zero_media_id',
			'Do not write image or cover blocks with id:0. Use a real media attachment ID or omit image-id-specific saved classes and attributes.'
		);
	}

	if ( false !== strpos( $markup, 'wp-image-0' ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_wp_image_zero',
			'Do not emit wp-image-0 in saved markup. Use a real media attachment ID or omit the wp-image-* class.'
		);
	}

	if ( preg_match( '#https?://[^\"\']*(picsum\.photos|placehold\.co|placeholder\.com|loremflickr\.com|dummyimage\.com)[^\"\']*#i', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_remote_placeholder_images',
			'Do not write final generated patterns with remote placeholder image URLs. Use plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/FILENAME\' with a filename from the plugin assets/images/ directory.'
		);
	}

	if ( preg_match( '/get_template_directory_uri\s*\(\s*\)/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_theme_asset_references',
			'Pattern references get_template_directory_uri() which resolves to the Ollie theme, not the plugin. Use plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/FILENAME\' instead.'
		);
	}

	if ( preg_match( '/dhali_pattern_library_image_url\s*\(|dhali_pattern_library_icon_url\s*\(|dhali_pattern_library_asset_url\s*\(/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'undefined_helper_function',
			'Pattern calls dhali_pattern_library_image_url() or a related helper that is not registered in the plugin by default. Use plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/FILENAME\' to avoid a fatal PHP error at pattern registration time.'
		);
	}

	// Block core/cover or core/image in standalone context only when using a fake or
	// remote image, not when using a plugin-owned asset URL. Plugin assets legitimately
	// have no WordPress attachment ID and do not produce wp-image-0 class issues because
	// there is no id attribute in the serialized block at all. Only block id:0 and
	// remote placeholder services, which are always wrong in final patterns.
	if ( 'standalone' === $context &&
		preg_match_all( '/<!--\s*wp:(cover|image)\s+(\{.*?\})\s*-->/s', $markup, $media_blocks, PREG_SET_ORDER ) ) {
		foreach ( $media_blocks as $media_block ) {
			$block_name = isset( $media_block[1] ) ? $media_block[1] : 'media';
			$attrs_raw  = isset( $media_block[2] ) ? $media_block[2] : '';

			// Hard error: explicit id:0 is always wrong.
			if ( preg_match( '/"id"\s*:\s*0\b/', $attrs_raw ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'fake_attachment_id_zero',
					sprintf( 'Standalone core/%s has id:0. Remove the id attribute entirely when using a plugin asset URL, or provide a real attachment ID.', $block_name )
				);
				continue;
			}

			// Hard error: URL present but it's a remote placeholder service.
			if ( false !== strpos( $attrs_raw, '"url"' ) &&
				preg_match( '#picsum\.photos|placehold\.co|placeholder\.com|loremflickr\.com|dummyimage\.com#', $attrs_raw ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'remote_placeholder_in_media_block',
					sprintf( 'Standalone core/%s uses a remote placeholder image service URL. Use plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/FILENAME\' instead.', $block_name )
				);
				continue;
			}

			// URL present with no id attribute at all — only allowed when the URL is a
			// plugin asset reference. Warn if it looks like a non-plugin URL.
			if ( false !== strpos( $attrs_raw, '"url"' ) &&
				! preg_match( '/"id"\s*:\s*[1-9][0-9]*/', $attrs_raw ) &&
				! preg_match( '/plugin_dir_url|assets\/images|assets\/icons/', $attrs_raw ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'warning',
					'standalone_media_url_without_id',
					sprintf(
						'Standalone core/%s has a URL with no attachment id. This is fine for plugin asset URLs (plugin_dir_url). For real media library images, provide a real attachment ID or ask the user for it.',
						$block_name
					)
				);
			}
		}
	}

	// ── Pattern metadata checks ───────────────────────────────────────────────

	// The outermost block group should include metadata.name, metadata.categories,
	// and metadata.patternName for discoverability and pattern override support.
	// Only check the FIRST wp:group block (the outermost section wrapper).
	if ( preg_match( '/<!--\s*wp:group\s+(\{.*?\})\s*-->/s', $markup, $first_group ) ) {
		$first_attrs = json_decode( isset( $first_group[1] ) ? $first_group[1] : '{}', true );

		if ( is_array( $first_attrs ) ) {
			$metadata = isset( $first_attrs['metadata'] ) ? $first_attrs['metadata'] : array();

			if ( empty( $metadata['patternName'] ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'info',
					'missing_pattern_name_metadata',
					'The outermost core/group block is missing metadata.patternName (e.g. "dhali-patterns/pattern-slug"). Adding it enables pattern overrides and improves editor discoverability.'
				);
			}
		}
	}

	// ── WP 7 Block API v3 serializer shape checks ──────────────────────────────

	// core/group: "border" must be inside "style", never a top-level block attribute.
	// A top-level "border" key causes "Block contains unexpected or invalid content" in WP 7.
	if ( preg_match_all( '/<!--\s*wp:group\s+(\{.*?\})\s*-->/s', $markup, $group_blocks, PREG_SET_ORDER ) ) {
		foreach ( $group_blocks as $idx => $group_block ) {
			$attrs_raw = isset( $group_block[1] ) ? $group_block[1] : '';
			$label     = 'core/group #' . ( $idx + 1 );

			$attrs = json_decode( $attrs_raw, true );
			if ( is_array( $attrs ) && isset( $attrs['border'] ) && ! isset( $attrs['style']['border'] ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'group_border_at_top_level',
					$label . ' has "border" as a top-level block attribute. In WP 7/Block API v3, border radius and other border properties must be nested inside "style": {"style":{"border":{"radius":"..."}}}. A top-level "border" key causes "Block contains unexpected or invalid content".'
				);
			}
		}
	}

	// core/cover: WP 7 serializer class order — is-light must come BEFORE position classes.
	if ( preg_match_all( '/<!--\s*wp:cover\s+[^>]*-->(.*?)<!--\s*\/wp:cover\s*-->/s', $markup, $cover_html_blocks, PREG_SET_ORDER ) ) {
		foreach ( $cover_html_blocks as $idx => $block ) {
			$body  = isset( $block[1] ) ? $block[1] : '';
			$label = 'core/cover #' . ( $idx + 1 );

			// is-light appearing AFTER has-custom-content-position is a WP 7 serializer mismatch.
			if ( preg_match( '/has-custom-content-position[^"]*is-light/', $body ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'cover_is_light_class_order',
					$label . ' has is-light after has-custom-content-position in the class list. WP 7 Block API v3 serializer outputs is-light FIRST. Correct order: class="wp-block-cover is-light has-custom-content-position is-position-*".'
				);
			}

			// <span> before <img> is old serializer order; WP 7 uses <img> before <span>.
			if ( preg_match( '/wp-block-cover__background.*?wp-block-cover__image-background/s', $body ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'cover_span_before_img',
					$label . ' has <span class="wp-block-cover__background"> before <img class="wp-block-cover__image-background">. WP 7 serializer outputs <img> before <span>. Use the article-cover-card-with-pill snippet from dhali/get-editor-safe-block-snippets.'
				);
			}
		}
	}

	// ── Cover serializer checks ─────────────────────────────────────────────

	if ( preg_match_all( '/<!--\s*wp:cover\s+(\{.*?\})\s*-->(.*?)<!--\s*\/wp:cover\s*-->/s', $markup, $cover_blocks, PREG_SET_ORDER ) ) {
		foreach ( $cover_blocks as $cover_index => $cover_block ) {
			$attrs_raw = isset( $cover_block[1] ) ? $cover_block[1] : '';
			$body      = isset( $cover_block[2] ) ? $cover_block[2] : '';
			$label     = 'core/cover #' . ( $cover_index + 1 );

			if ( false !== strpos( $attrs_raw, '"contentPosition"' ) && false === strpos( $body, 'has-custom-content-position' ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'cover_missing_custom_content_position_class',
					$label . ' has contentPosition but the saved wrapper is missing has-custom-content-position. Use editor-copied/trusted Cover markup.'
				);
			}

			if ( false !== strpos( $attrs_raw, '"contentPosition"' ) && ! preg_match( '/is-position-[a-z-]+/', $body ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'cover_missing_position_class',
					$label . ' has contentPosition but the saved wrapper is missing its is-position-* class.'
				);
			}

			foreach ( array(
				'wp-block-cover__image-background' => 'cover_missing_image_background_class',
				'wp-block-cover__background'       => 'cover_missing_background_span',
				'wp-block-cover__inner-container'  => 'cover_missing_inner_container',
			) as $required_fragment => $rule ) {
				if ( false === strpos( $body, $required_fragment ) ) {
					$issues[] = dhali_mcp_pattern_issue(
						'error',
						$rule,
						$label . ' is missing ' . $required_fragment . '. Do not generate Cover markup from memory; use editor-copied/trusted markup.'
					);
				}
			}

			if ( false !== strpos( $body, 'min-block-size:' ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'warning',
					'cover_min_block_size_needs_editor_confirmation',
					$label . ' uses min-block-size in saved markup. This can be valid only if copied from the editor; generated Cover markup should use a trusted snippet.'
				);
			}
		}
	}

	// ── Style / serializer safety ──────────────────────────────────────────

	// FIX: Narrowed from matching any "css" attribute to only the specific
	// overflow:hidden serializer hack. Valid custom CSS block support uses "css" too.
	if ( preg_match( '/"css"\s*:\s*"[^"]*overflow\s*:\s*hidden/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'error',
			'no_overflow_hidden_style_css',
			'Do not use "css":"overflow:hidden" in generated patterns. Use per-corner border-radius on the block instead.'
		);
	}

	// CONFIRMED by editor testing: when style.spacing.blockGap is set on a flex
	// core/group, WordPress save() generates NO inline gap style. The gap is applied
	// entirely through WordPress's CSS generation system. Any gap-related property
	// in the saved HTML div's style attribute is a serializer mismatch.
	if ( preg_match( '/class="[^"]*is-layout-flex/', $markup ) ) {
		if ( preg_match( '/style="[^"]*--wp--style--block-gap\s*:/', $markup ) ) {
			$issues[] = dhali_mcp_pattern_issue(
				'error',
				'flex_group_invalid_block_gap_inline_style',
				'Found --wp--style--block-gap in an inline style on a flex layout block. ' .
				'WordPress does not serialize blockGap as an inline style on flex groups — ' .
				'the gap is applied via generated CSS. Remove it from the style attribute.'
			);
		}

		if ( preg_match( '/style="[^"]*\bgap\s*:\s*var\(/', $markup ) ) {
			$issues[] = dhali_mcp_pattern_issue(
				'error',
				'flex_group_invalid_gap_property',
				'Found gap:var(...) in an inline style on a flex layout block. ' .
				'WordPress applies blockGap via generated CSS, not an inline gap property. ' .
				'Remove it. Use the flex-group-vertical or flex-group-horizontal snippet from get-editor-safe-block-snippets.'
			);
		}
	}

	// ── Icon / SVG checks ──────────────────────────────────────────────────

	if ( preg_match( '/<svg[^>]*>\s*<!--/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue( 'error', 'svg_placeholder_comment', 'Do not place placeholder comments inside final SVG markup.' );
	}

	if ( preg_match_all( '/<!--\s*wp:outermost\/icon-block\b.*?<!--\s*\/wp:outermost\/icon-block\s*-->/s', $markup, $icon_blocks ) ) {
		foreach ( $icon_blocks[0] as $index => $icon_block ) {
			$label = 'outermost/icon-block #' . ( $index + 1 );

			if ( preg_match( '/"(iconColorValue|iconBackgroundColorValue)"\s*:\s*"[^"]*var\(/', $icon_block ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'outermost_icon_value_fields_must_be_resolved',
					$label . ' uses iconColorValue or iconBackgroundColorValue with a CSS variable. Use editor-resolved hex values such as #fbb042 or #ffffff.'
				);
			}

			if ( preg_match( '/<svg\b[^>]*>\s*<\/svg>/s', $icon_block ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'outermost_icon_empty_svg',
					$label . ' contains an empty SVG shell. Named icons must include the saved SVG path data.'
				);
			}

			if ( preg_match( '/"iconName"\s*:\s*"(Plus|plus|placeholder)"/', $icon_block ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'outermost_icon_untrusted_name',
					$label . ' uses an untrusted iconName. Use known-good editor-saved icon slugs with full SVG paths. For custom/decorative SVGs use iconName:"" with the full SVG embedded. For plus CTAs use plus-cta-linked-group-icon or an exact editor-copied/Ollie button/link snippet.'
				);
			}

			if ( preg_match( '/"iconName"\s*:\s*""/', $icon_block ) &&
				preg_match( '/<svg\b[^>]*>\s*<\/svg>/s', $icon_block ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'outermost_icon_empty_svg_custom',
					$label . ' uses iconName:"" (custom SVG) but the SVG element is empty. Provide real path/shape elements inside the <svg> tag.'
				);
			}
		}
	}

	// ── Custom class checks ────────────────────────────────────────────────

	if ( preg_match( '/"ollieCustomClasses"\s*:\s*\[/', $markup ) ) {
		$issues[] = dhali_mcp_pattern_issue(
			'warning',
			'ollie_custom_classes_need_confirmation',
			'Markup uses ollieCustomClasses. Preserve only known-good editor classes confirmed in project CSS.'
		);
	}

	// ── Token reference validation ─────────────────────────────────────────

	$issues = array_merge( $issues, dhali_mcp_lint_token_references( $markup ) );

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
 * Known-good snippets and rules for editor-safe block composition.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_get_editor_safe_block_snippets_data() {
	return array(
		'guidelines' => array(
			'Decorative and AI-generated icon SVGs must use outermost/icon-block with iconName:"" and the full SVG embedded inside .icon-container. Do not use core/html for decorative icons.',
			'Circular plus CTAs should use plus-cta-linked-group-icon: a native linked core/group wrapper with a known-good wordpress-plus icon block and full SVG path. Do not default to core/html. Do not manually generate custom styled core/button plus buttons.',
			'Use outermost/icon-block with a named iconName only for known-good editor-saved icon slugs with full SVG paths.',
			'Use native Ollie/editor button markup for CTAs. core/html is diagnostic fallback only, not a default CTA strategy.',
			'Use core/image for ordinary images. Use core/cover only from editor-copied markup, user-provided markup, or trusted snippets. Do not generate final Cover markup from memory. Use useFeaturedImage:true only in Query Loop or post-template context.',
			'Final generated patterns must not use id:0, wp-image-0, remote placeholder images, or generated overflow:hidden style.css.',
			'If a screenshot-matched card needs a real image and no media URL/id is known, ask for the asset before writing the final pattern.',
			'When fontSize is a preset slug (e.g. "base"), the <a> element must have has-{slug}-font-size only. Never add has-custom-font-size alongside a preset fontSize. For plus CTAs, prefer plus-cta-linked-group-icon or exact editor-copied/Ollie button markup.',
			'CONFIRMED by editor testing: core/group with layout.type "flex" must include className with the layout classes (is-layout-flex, is-vertical/is-horizontal, wp-block-group-is-layout-flex) in BOTH the block attributes and the rendered div. blockGap must NOT appear in the inline style attribute.',
		),
		'snippets'   => array(

			// TRUSTED: native/editor-safe plus CTA shape. This keeps the CTA block-native
			// and editable: linked core/group wrapper + known-good wordpress-plus icon block
			// with full saved SVG path. Prefer this over core/html and over hand-generated
			// custom core/button plus markup.
			'plus-cta-linked-group-icon' => '<!-- wp:group {"style":{"color":{"background":"#fff29e"},"border":{"radius":{"topLeft":"var:preset|border-radius|full","topRight":"var:preset|border-radius|full","bottomLeft":"var:preset|border-radius|full","bottomRight":"var:preset|border-radius|full"}},"spacing":{"padding":{"top":"0.5rem","bottom":"0.5rem","left":"0.5rem","right":"0.5rem"}}},"layout":{"type":"constrained"},"href":"#","linkDestination":"custom","animationType":"scaleOnHover"} --><div class="wp-block-group has-background" style="border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);background-color:#fff29e;padding-top:0.5rem;padding-right:0.5rem;padding-bottom:0.5rem;padding-left:0.5rem"><!-- wp:outermost/icon-block {"iconName":"wordpress-plus","customIconBackgroundColor":"#fff29e","width":"30px"} --><div class="wp-block-outermost-icon-block"><div class="icon-container" style="width:30px;transform:rotate(0deg) scaleX(1) scaleY(1)"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 12.5V17.5H12.5V12.5H17.5V11H12.5V6H11V11H6V12.5H11Z"></path></svg></div></div><!-- /wp:outermost/icon-block --></div><!-- /wp:group -->',
			'plus-cta-button'            => '<!-- wp:group {"style":{"color":{"background":"#fff29e"},"border":{"radius":{"topLeft":"var:preset|border-radius|full","topRight":"var:preset|border-radius|full","bottomLeft":"var:preset|border-radius|full","bottomRight":"var:preset|border-radius|full"}},"spacing":{"padding":{"top":"0.5rem","bottom":"0.5rem","left":"0.5rem","right":"0.5rem"}}},"layout":{"type":"constrained"},"href":"#","linkDestination":"custom","animationType":"scaleOnHover"} --><div class="wp-block-group has-background" style="border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);background-color:#fff29e;padding-top:0.5rem;padding-right:0.5rem;padding-bottom:0.5rem;padding-left:0.5rem"><!-- wp:outermost/icon-block {"iconName":"wordpress-plus","customIconBackgroundColor":"#fff29e","width":"30px"} --><div class="wp-block-outermost-icon-block"><div class="icon-container" style="width:30px;transform:rotate(0deg) scaleX(1) scaleY(1)"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 12.5V17.5H12.5V12.5H17.5V11H12.5V6H11V11H6V12.5H11Z"></path></svg></div></div><!-- /wp:outermost/icon-block --></div><!-- /wp:group -->',

			// Diagnostic fallback only. Do not use as the default CTA strategy.
			'plus-cta-html-diagnostic-fallback' => '<!-- wp:html --><a href="#" aria-label="Read more" style="display:inline-flex;align-items:center;justify-content:center;width:3rem;height:3rem;border-radius:999px;background:#fff29e;color:#1E1E26;text-decoration:none;font-size:1.5rem;line-height:1;">+</a><!-- /wp:html -->',

			// Ollie-style button examples. These are intentionally simple/editor-native.
			'ollie-button-light' => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-button-light"} --><div class="wp-block-button is-style-button-light"><a class="wp-block-button__link wp-element-button">Download Ollie</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			'ollie-button-fill'  => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-fill"} --><div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button">Discover More</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',


			// TRUSTED: editor-safe Cover shape for image-card badge overlays.
			// Serializer shape verified against WP 7.0 Block API v3:
			// - Class order: is-light FIRST, then position classes
			// - <img> BEFORE <span> in the saved HTML
			// - All border-radius values (including "0") in the inline style
			// - Badge pill border radius INSIDE "style" (NOT as a top-level "border" key)
			// - minHeight included in block attrs when a fixed height is needed
			// For plugin asset URLs: omit id attribute entirely, no wp-image-* class.
			// For real media library images: add "id":INTEGER and wp-image-INTEGER class.
			'article-cover-card-with-pill' =>
				'<!-- wp:cover {"url":"PLUGIN_ASSET_URL","dimRatio":0,"customOverlayColor":"#c8cecf","isUserOverlayColor":true,"minHeight":240,"sizeSlug":"full","contentPosition":"top left","isDark":false,"style":{"border":{"radius":{"topLeft":"var:preset|border-radius|lg","topRight":"var:preset|border-radius|lg","bottomLeft":"0","bottomRight":"0"}}}} -->' .
				'<div class="wp-block-cover is-light has-custom-content-position is-position-top-left" style="border-top-left-radius:var(--wp--preset--border-radius--lg);border-top-right-radius:var(--wp--preset--border-radius--lg);border-bottom-left-radius:0;border-bottom-right-radius:0;min-height:240px">' .
				'<img class="wp-block-cover__image-background size-full" alt="" src="PLUGIN_ASSET_URL" data-object-fit="cover"/>' .
				'<span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim" style="background-color:#c8cecf"></span>' .
				'<div class="wp-block-cover__inner-container">' .
				'<!-- wp:group {"style":{"border":{"radius":"var:preset|border-radius|full"},"spacing":{"margin":{"top":"1.25rem","left":"1.25rem"},"padding":{"top":"0.25rem","right":"0.75rem","bottom":"0.25rem","left":"0.75rem"}}},"backgroundColor":"primary-accent","layout":{"type":"constrained"}} -->' .
				'<div class="wp-block-group has-primary-accent-background-color has-background" style="border-radius:var(--wp--preset--border-radius--full);margin-top:1.25rem;margin-left:1.25rem;padding-top:0.25rem;padding-right:0.75rem;padding-bottom:0.25rem;padding-left:0.75rem">' .
				'<!-- wp:paragraph {"style":{"typography":{"fontWeight":"500"}},"textColor":"main","fontSize":"x-small"} --><p class="has-main-color has-text-color has-x-small-font-size" style="font-weight:500">Category</p><!-- /wp:paragraph -->' .
				'</div><!-- /wp:group -->' .
				'</div></div><!-- /wp:cover -->',

			'article-cover-card-with-pill-note' =>
				'WP 7 serializer rules for cover+badge patterns: ' .
				'(1) Cover div class order: is-light FIRST, then has-custom-content-position, then is-position-*. ' .
				'(2) <img> comes BEFORE <span> in the saved HTML. ' .
				'(3) All border-radius values including "0" appear in the inline style. ' .
				'(4) Badge pill border radius must be inside "style": {"style":{"border":{"radius":"..."},"spacing":{...}}} — NEVER as a top-level "border" key on core/group. ' .
				'(5) For plugin placeholder assets: omit id, omit wp-image-* class. Replace PLUGIN_ASSET_URL with: \' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/placeholder-wide-16x9.webp\' ) . \'',

			// CORRECT pattern for all decorative/AI-generated icon SVGs.
			// Use outermost/icon-block with iconName:"" and the full SVG embedded.
			// Do not use core/html for decorative icons.
			'custom-svg-via-icon-block' => '<!-- wp:outermost/icon-block {"iconName":"","width":"64px"} --><div class="wp-block-outermost-icon-block"><div class="icon-container" style="width:64px;transform:rotate(0deg) scaleX(1) scaleY(1)"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" focusable="false"><path fill="#7f8b72" d="REPLACE_WITH_REAL_PATH_DATA"/></svg></div></div><!-- /wp:outermost/icon-block -->',

			// CONFIRMED by editor testing (2026-05):
			// - Layout classes ARE required: is-layout-flex, is-vertical, wp-block-group-is-layout-flex
			// - blockGap must NOT appear in the inline style attribute
			// - Other style properties (padding, border-radius, shadow) DO go in the inline style normally
			// - gap: or --wp--style--block-gap: in the style attribute causes "invalid content" error
			'flex-group-vertical' =>
				'<!-- wp:group {"className":"is-layout-flex is-vertical wp-block-group-is-layout-flex","style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium"},"blockGap":"var:preset|spacing|small"},"border":{"radius":"var:preset|border-radius|lg"},"shadow":"var:preset|shadow|small-light"},"backgroundColor":"base","layout":{"type":"flex","orientation":"vertical","flexWrap":"nowrap"}} -->' .
				'<div class="wp-block-group is-layout-flex is-vertical wp-block-group-is-layout-flex has-base-background-color has-background" style="border-radius:var(--wp--preset--border-radius--lg);padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium);box-shadow:var(--wp--preset--shadow--small-light)">' .
				'</div>' .
				'<!-- /wp:group -->',

			'flex-group-horizontal' =>
				'<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|medium"}},"layout":{"type":"flex","orientation":"horizontal","flexWrap":"nowrap","justifyContent":"left","verticalAlignment":"center"}} -->' .
				'<div class="wp-block-group is-layout-flex is-horizontal wp-block-group-is-layout-flex">' .
				'</div>' .
				'<!-- /wp:group -->',

			'flex-group-note' =>
				'Use flex-group-vertical or flex-group-horizontal for any core/group with layout.type "flex". ' .
				'Never generate flex group HTML from scratch. ' .
				'Required: layout classes (is-layout-flex, is-vertical/is-horizontal, wp-block-group-is-layout-flex). ' .
				'Required: blockGap omitted from inline style entirely — no gap: and no --wp--style--block-gap: in style="".',

			'card-with-shadow' =>
				'<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium"}},"border":{"radius":"var:preset|border-radius|lg"},"shadow":"var:preset|shadow|small-light"},"backgroundColor":"base","layout":{"type":"constrained"}} -->' .
				'<div class="wp-block-group has-base-background-color has-background" style="border-radius:var(--wp--preset--border-radius--lg);padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium);box-shadow:var(--wp--preset--shadow--small-light)">' .
				'</div>' .
				'<!-- /wp:group -->',

			'static-cover-card-note' =>
				'For standalone screenshot cards: use core/cover only from editor-copied or trusted snippet markup with explicit real media url, id (integer > 0), sizeSlug, saved <img> markup, background span, and inner-container. Never generate Cover from memory. Never use useFeaturedImage:true outside Query Loop or post template context. Never use id:0, wp-image-0, placeholder image URLs, or overflow:hidden style.css.',

			'static-image-card-note' =>
				'Use core/image for ordinary screenshot-matched featured images when no overlay content is required. Provide a real attachment ID.',
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
			'layout'             => array( 'type' => 'object', 'description' => 'The compiled theme.json layout settings.' ),
			'custom'             => array( 'type' => 'object', 'description' => 'Custom theme.json settings.' ),
		),
		'required'   => array( 'token_source', 'theme_json_version', 'colors', 'gradients', 'duotone', 'spacing', 'font_sizes', 'font_families', 'shadows', 'border_radius', 'layout', 'custom' ),
	);

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

	$abilities = array(

		// ── dhali/get-site-info ──────────────────────────────────────────────
		// FIX: Removed duplicate `stylesheet` field (identical to `active_theme_slug`).
		'dhali/get-site-info' => array(
			'label'               => __( 'Get site info', 'dhali' ),
			'description'         => __( 'Returns the WordPress site title and active theme information.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'site_info', 'Use "site_info".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'site_title'        => dhali_mcp_string_schema( 'The WordPress site title.' ),
					'active_theme_name' => dhali_mcp_string_schema( 'The active theme display name.' ),
					'active_theme_slug' => dhali_mcp_string_schema( 'The active stylesheet/theme slug.' ),
					'template'          => dhali_mcp_string_schema( 'The parent template slug (same as active_theme_slug unless child theme).' ),
					'is_child_theme'    => array( 'type' => 'boolean', 'description' => 'Whether the active theme is a child theme.' ),
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
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-project-snapshot ───────────────────────────────────────
		// FIX: Removed duplicate `stylesheet` field (identical to `theme_slug`).
		'dhali/get-project-snapshot' => array(
			'label'               => __( 'Get project snapshot', 'dhali' ),
			'description'         => __( 'Returns a compact WordPress environment snapshot and layout defaults.', 'dhali' ),
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
					'is_child_theme'  => array( 'type' => 'boolean', 'description' => 'Whether the active theme is a child theme.' ),
					'layout_defaults' => array( 'type' => 'object', 'description' => 'The compiled theme.json layout settings.' ),
				),
				'required'   => array( 'core_version', 'php_version', 'active_theme', 'theme_slug', 'template', 'is_child_theme', 'layout_defaults' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_project_snapshot_data();
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-token-and-layout-map ───────────────────────────────────
		'dhali/get-token-and-layout-map' => array(
			'label'               => __( 'Get token and layout map', 'dhali' ),
			'description'         => __( 'Returns canonical active-theme theme.json preset slugs and layout settings.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'token_and_layout_map', 'Use "token_and_layout_map".' ),
			'output_schema'       => $token_output_schema,
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_token_and_layout_data();
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-pattern-template-skeleton ──────────────────────────────
		// FIX: Now returns the active plugin and patterns paths so the agent
		// knows the write destination without guessing or scanning.
		'dhali/get-pattern-template-skeleton' => array(
			'label'               => __( 'Get pattern template skeleton', 'dhali' ),
			'description'         => __( 'Returns the standard PHP return-array skeleton and write paths for Dhali block patterns.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'pattern_template_skeleton', 'Use "pattern_template_skeleton".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'categories'    => dhali_mcp_string_array_schema( 'Default pattern categories.' ),
					'keywords'      => dhali_mcp_string_array_schema( 'Default pattern keyword placeholders.' ),
					'viewportWidth' => array( 'type' => 'integer', 'description' => 'Default preview viewport width.' ),
					'blockTypes'    => dhali_mcp_string_array_schema( 'Default block type associations.' ),
					'plugin_path'   => dhali_mcp_string_schema( 'Absolute path to the dhali-pattern-library plugin directory.' ),
					'patterns_path' => dhali_mcp_string_schema( 'Absolute path to the patterns subdirectory where PHP files are written.' ),
					'php_skeleton'  => dhali_mcp_string_schema( 'PHP return-array skeleton for a new pattern file.' ),
				),
				'required'   => array( 'categories', 'keywords', 'viewportWidth', 'blockTypes', 'plugin_path', 'patterns_path', 'php_skeleton' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
				$plugin_path   = trailingslashit( $plugin_dir ) . 'Dhali-Pattern-Library';
				$patterns_path = trailingslashit( $plugin_path ) . 'patterns';

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
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|xx-large","right":"var:preset|spacing|medium","bottom":"var:preset|spacing|xx-large","left":"var:preset|spacing|medium"},"margin":{"top":"0","bottom":"0"}}},"backgroundColor":"base","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="margin-top:0;margin-bottom:0;padding-top:var(--wp--preset--spacing--xx-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--xx-large);padding-left:var(--wp--preset--spacing--medium)">
	<!-- wp:heading {"textAlign":"center","fontSize":"x-large"} -->
	<h2 class="wp-block-heading has-text-align-center has-x-large-font-size">' . esc_html__( 'Pattern Heading', 'dhali' ) . '</h2>
	<!-- /wp:heading -->
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
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/validate-pattern-markup ────────────────────────────────────
		// FIX: Replaced weak inline string checks + inconsistent format with a call
		// to dhali_mcp_lint_pattern_markup(). Issues are now structured objects
		// matching lint-pattern-authoring-rules output. block_count now counts
		// named blocks only (excludes null/freeform parse_blocks() entries).
		'dhali/validate-pattern-markup' => array(
			'label'               => __( 'Validate pattern markup', 'dhali' ),
			'description'         => __( 'Parses WordPress block markup and runs full authoring lint. Returns structured issues matching lint-pattern-authoring-rules output format.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'markup'  => array( 'type' => 'string', 'description' => 'The WordPress block markup string to validate.' ),
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
					'valid'        => array( 'type' => 'boolean', 'description' => 'True when at least one named block parsed and lint returned zero errors.' ),
					'block_count'  => array( 'type' => 'integer', 'description' => 'Number of named top-level blocks (excludes null/freeform entries).' ),
					'block_names'  => dhali_mcp_string_array_schema( 'Parsed top-level block names.' ),
					'issue_counts' => array( 'type' => 'object', 'description' => 'Lint issue counts by severity.' ),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'block_count', 'block_names', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup  = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$context = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';

				$lint   = dhali_mcp_lint_pattern_markup( $markup, $context );
				$blocks = parse_blocks( $markup );

				$block_names = array_values(
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

				return array(
					'valid'        => ! empty( $block_names ) && $lint['valid'],
					'block_count'  => count( $block_names ),
					'block_names'  => $block_names,
					'issue_counts' => $lint['issue_counts'],
					'issues'       => $lint['issues'],
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-icon-manifest ──────────────────────────────────────────
		// FIX: Templates previously used `iconName:"placeholder"` (fails lint rule
		// outermost_icon_untrusted_name) and empty SVG shells (fails outermost_icon_empty_svg).
		// Both now use real icon data so they pass lint if used verbatim.
		'dhali/get-icon-manifest' => array(
			'label'               => __( 'Get icon manifest', 'dhali' ),
			'description'         => __( 'Returns Ollie/Outermost icon block markup templates for named and custom SVG icons.', 'dhali' ),
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
						'outermost-named-icon' =>
							'<!-- wp:outermost/icon-block {"iconName":"ollie-phosphor-question","iconColor":"primary","iconColorValue":"#5344F4","width":"1.75rem"} -->' .
							'<div class="wp-block-outermost-icon-block">' .
							'<div class="icon-container has-icon-color has-primary-color" style="color:#5344F4;width:1.75rem;transform:rotate(0deg) scaleX(1) scaleY(1)">' .
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false">' .
							'<path d="M140,180a12,12,0,1,1-12-12A12,12,0,0,1,140,180ZM128,72c-22.06,0-40,16.15-40,36v4a8,8,0,0,0,16,0v-4c0-11,10.77-20,24-20s24,9,24,20-10.77,20-24,20a8,8,0,0,0-8,8v8a8,8,0,0,0,16,0v-.72c18.24-3.35,32-17.9,32-35.28C168,88.15,150.06,72,128,72Zm104,56A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"></path>' .
							'</svg></div></div>' .
							'<!-- /wp:outermost/icon-block -->',

						// Custom SVG with background pill — iconName:"" with real SVG path.
						// For custom/decorative SVGs, use outermost/icon-block with iconName:"" and the full SVG embedded (see get-editor-safe-block-snippets: custom-svg-via-icon-block).
						// Only use this template when copying exact editor-saved custom icon markup.
						'outermost-custom-svg-pill' =>
							'<!-- wp:outermost/icon-block {"iconName":"","iconBackgroundColor":"tertiary","iconBackgroundColorValue":"#f8f7fc","width":"90px","style":{"border":{"radius":{"topLeft":"var:preset|border-radius|full","topRight":"var:preset|border-radius|full","bottomLeft":"var:preset|border-radius|full","bottomRight":"var:preset|border-radius|full"}},"spacing":{"padding":{"top":"20px","right":"5px","bottom":"20px","left":"5px"}}}} -->' .
							'<div class="wp-block-outermost-icon-block">' .
							'<div class="icon-container has-icon-background-color has-tertiary-background-color" style="background-color:#f8f7fc;width:90px;padding-top:20px;padding-right:5px;padding-bottom:20px;padding-left:5px;border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);transform:rotate(0deg) scaleX(1) scaleY(1)">' .
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1E1E26" aria-hidden="true" focusable="false">' .
							'<circle cx="12" cy="12" r="10"/>' .
							'</svg></div></div>' .
							'<!-- /wp:outermost/icon-block -->',

						'usage-note' =>
							'Use outermost-named-icon only for known-good Phosphor icon slugs from editor-saved markup. ' .
							'Use outermost-custom-svg-pill only when copying exact editor-saved custom icon markup — replace the SVG path with the real path before writing. ' .
							'For all decorative or AI-generated icon SVGs, use outermost/icon-block with iconName:"" and the full SVG embedded (see get-editor-safe-block-snippets: custom-svg-via-icon-block). Do not use core/html for icons.',
					),
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/lint-pattern-authoring-rules ───────────────────────────────
		'dhali/lint-pattern-authoring-rules' => array(
			'label'               => __( 'Lint pattern authoring rules', 'dhali' ),
			'description'         => __( 'Applies fast Dhali/Ollie editor-safety rules to proposed block markup before or after writing a pattern.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request' => array(
						'type'    => 'string',
						'enum'    => array( 'lint_pattern_authoring_rules' ),
						'default' => 'lint_pattern_authoring_rules',
					),
					'markup'  => array( 'type' => 'string', 'description' => 'Block markup to lint.' ),
					'context' => array(
						'type'    => 'string',
						'enum'    => array( 'standalone', 'query_loop', 'post_template', 'template_part' ),
						'default' => 'standalone',
					),
				),
				'required'             => array( 'request', 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'        => array( 'type' => 'boolean', 'description' => 'True when zero errors found.' ),
					'context'      => dhali_mcp_string_schema( 'Context used for linting.' ),
					'issue_counts' => array( 'type' => 'object', 'description' => 'Lint issue counts by severity.' ),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'context', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup  = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$context = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';
				return dhali_mcp_lint_pattern_markup( $markup, $context );
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-editor-safe-block-snippets ─────────────────────────────
		'dhali/get-editor-safe-block-snippets' => array(
			'label'               => __( 'Get editor-safe block snippets', 'dhali' ),
			'description'         => __( 'Returns known-good editor-safe snippets and composition guidance for fragile block types.', 'dhali' ),
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
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/test-pattern-in-editor-context ─────────────────────────────
		// FIX: Draft is now only created when lint passes (zero errors). Previously
		// a draft was inserted even when lint returned errors, polluting the DB with
		// known-broken markup and giving a misleading edit_url.
		'dhali/test-pattern-in-editor-context' => array(
			'label'               => __( 'Test pattern in editor context', 'dhali' ),
			'description'         => __( 'Runs lint, then — only if lint passes — creates a temporary draft post with the block markup and returns an edit URL for manual editor verification.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'   => array(
						'type'    => 'string',
						'enum'    => array( 'test_pattern_in_editor_context' ),
						'default' => 'test_pattern_in_editor_context',
					),
					'markup'    => array( 'type' => 'string', 'description' => 'The WordPress block markup to place in a temporary draft.' ),
					'post_type' => array( 'type' => 'string', 'description' => 'Post type for the temporary draft. Defaults to page.', 'default' => 'page' ),
					'context'   => array(
						'type'    => 'string',
						'enum'    => array( 'standalone', 'query_loop', 'post_template', 'template_part' ),
						'default' => 'standalone',
					),
				),
				'required'             => array( 'request', 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'        => array( 'type' => 'boolean', 'description' => 'True when lint passed and draft was created successfully.' ),
					'post_id'      => array( 'type' => 'integer', 'description' => 'Temporary draft post ID, or 0 if lint failed or insertion failed.' ),
					'edit_url'     => dhali_mcp_string_schema( 'Admin edit URL for manual editor verification. Empty if draft was not created.' ),
					'block_count'  => array( 'type' => 'integer', 'description' => 'Named top-level block count.' ),
					'block_names'  => dhali_mcp_string_array_schema( 'Parsed top-level block names.' ),
					'issue_counts' => array( 'type' => 'object', 'description' => 'Lint issue counts by severity.' ),
					'issues'       => $lint_issue_schema,
				),
				'required'   => array( 'valid', 'post_id', 'edit_url', 'block_count', 'block_names', 'issue_counts', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup    = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$post_type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'page';
				$context   = isset( $input['context'] ) && is_string( $input['context'] ) ? $input['context'] : 'standalone';

				$lint = dhali_mcp_lint_pattern_markup( $markup, $context );

				$blocks      = parse_blocks( $markup );
				$block_names = array_values(
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

				// FIX: Only create the draft when lint passes.
				// Inserting broken markup wastes DB rows and misleads the agent with an edit_url.
				if ( ! $lint['valid'] ) {
					return array(
						'valid'        => false,
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
					'post_id'      => (int) $post_id,
					'edit_url'     => $post_id ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
					'block_count'  => count( $block_names ),
					'block_names'  => $block_names,
					'issue_counts' => $counts,
					'issues'       => $issues,
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/sync-context ───────────────────────────────────────────────
		'dhali/sync-context' => array(
			'label'               => __( 'Sync context cache', 'dhali' ),
			'description'         => __( 'Updates the project context markdown file with the current WordPress project state. After a successful sync the context file contains token slugs, layout settings, and available local image assets — no further MCP calls needed for standard pattern authoring.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'       => array( 'type' => 'string', 'enum' => array( 'sync_context' ), 'default' => 'sync_context' ),
					'confirm_write' => array( 'type' => 'boolean', 'description' => 'Must be true to write the context markdown file.' ),
				),
				'required'             => array( 'request', 'confirm_write' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'        => dhali_mcp_string_schema( 'Write status.' ),
					'path'          => dhali_mcp_string_schema( 'Absolute path to the context markdown file.' ),
					'bytes_written' => array( 'type' => 'integer', 'description' => 'Number of bytes written.' ),
					'message'       => dhali_mcp_string_schema( 'Human-readable result message.' ),
				),
				'required'   => array( 'status', 'path', 'bytes_written', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$project_slug       = dhali_mcp_get_project_slug();
				$context_path       = ABSPATH . 'context.md';
				$legacy_context_path = ABSPATH . $project_slug . '_context.md';

				if ( empty( $input['confirm_write'] ) ) {
					return new WP_Error(
						'confirm_write_required',
						__( 'confirm_write must be true before the context file can be updated.', 'dhali' )
					);
				}

				$content       = dhali_mcp_build_context_markdown();
				$bytes_written = file_put_contents( $context_path, $content );

				if ( false !== $bytes_written && $legacy_context_path !== $context_path ) {
					file_put_contents( $legacy_context_path, $content );
				}

				if ( false === $bytes_written ) {
					return new WP_Error(
						'context_write_failed',
						__( 'Failed to write context file. Check directory permissions.', 'dhali' )
					);
				}

				return array(
					'status'        => 'success',
					'path'          => $context_path,
					'bytes_written' => $bytes_written,
					'message'       => 'Context file updated successfully at context.md (mirrored to ' . basename( $legacy_context_path ) . ').',
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// ── dhali/get-local-assets ───────────────────────────────────────────
		// Returns available placeholder asset filenames from the dhali-pattern-library
		// plugin's own assets/ directory. Patterns must reference these via
		// plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME'.
		// Do NOT use dhali_pattern_library_image_url() — the helper functions are
		// not registered in the plugin by default and will cause a fatal PHP error.
		'dhali/get-local-assets' => array(
			'label'               => __( 'Get local assets', 'dhali' ),
			'description'         => __( 'Returns placeholder asset filenames from the dhali-pattern-library plugin\'s own assets/images/ and assets/icons/ directories. Use plugin_dir_url( dirname( __FILE__ ) ) . \'assets/images/FILENAME\' in pattern content strings.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'get_local_assets', 'Use "get_local_assets".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'            => dhali_mcp_string_schema( 'success or error.' ),
					'images'            => dhali_mcp_string_array_schema( 'Available image filenames from assets/images/.' ),
					'icons'             => dhali_mcp_string_array_schema( 'Available icon filenames from assets/icons/.' ),
					'image_php_pattern' => dhali_mcp_string_schema( 'PHP pattern for image src using the helper function.' ),
					'icon_php_pattern'  => dhali_mcp_string_schema( 'PHP pattern for icon src using the helper function.' ),
					'fallback_pattern'  => dhali_mcp_string_schema( 'PHP fallback using plugin_dir_url( dirname( __FILE__ ) ) for pattern files.' ),
					'message'           => dhali_mcp_string_schema( 'Human-readable message.' ),
				),
				'required'   => array( 'status', 'images', 'icons', 'image_php_pattern', 'icon_php_pattern', 'fallback_pattern', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir = plugin_dir_path( __FILE__ );
				$images_dir = $plugin_dir . 'assets/images/';
				$icons_dir  = $plugin_dir . 'assets/icons/';
				$images     = array();
				$icons      = array();

				if ( is_dir( $images_dir ) ) {
					foreach ( array_diff( scandir( $images_dir ), array( '.', '..' ) ) as $file ) {
						if ( preg_match( '/\.(webp|jpg|jpeg|png|svg|gif)$/i', $file ) ) {
							$images[] = $file;
						}
					}
					sort( $images );
				}

				if ( is_dir( $icons_dir ) ) {
					foreach ( array_diff( scandir( $icons_dir ), array( '.', '..' ) ) as $file ) {
						if ( preg_match( '/\.(svg|png)$/i', $file ) ) {
							$icons[] = $file;
						}
					}
					sort( $icons );
				}

				if ( empty( $images ) && empty( $icons ) ) {
					return new WP_Error(
						'no_assets_found',
						sprintf( 'No assets found in plugin at %s. Check the assets/images/ and assets/icons/ directories exist.', $plugin_dir )
					);
				}

				return array(
					'status'            => 'success',
					'images'            => array_values( $images ),
					'icons'             => array_values( $icons ),
					'image_php_pattern' => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME' ) . '",
					'icon_php_pattern'  => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/icons/FILENAME' ) . '",
					'fallback_pattern'  => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME' ) . '",
					'message'           => count( $images ) . ' images and ' . count( $icons ) . ' icons available in the dhali-pattern-library plugin assets. Replace FILENAME with a listed filename. Use plugin_dir_url( dirname( __FILE__ ) ) — do not use dhali_pattern_library_image_url() unless you have confirmed the helper functions are registered in the plugin.',
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
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
