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

	// core/html must never be used for icon SVGs. Any core/html block containing an <svg>
	// element with a width/height <= 200px (icon-sized) should use outermost/icon-block
	// with iconName:"" instead. question.svg from the plugin assets is the mandatory fallback
	// when no static icon matches the design.
	if ( preg_match_all( '/<!--\s*wp:html\s*-->(.*?)<!--\s*\/wp:html\s*-->/s', $markup, $html_blocks, PREG_SET_ORDER ) ) {
		foreach ( $html_blocks as $html_block ) {
			$body = isset( $html_block[1] ) ? $html_block[1] : '';

			if ( ! preg_match( '/<svg\b/', $body ) ) {
				continue;
			}

			// Check if it looks icon-sized: width/height attribute or style <= 200px.
			$is_icon_sized = preg_match( '/(?:width|height)\s*[=:]\s*["\']?(\d+)(?:px)?["\']?/i', $body, $size_match )
				&& isset( $size_match[1] )
				&& (int) $size_match[1] <= 200;

			if ( $is_icon_sized ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'core_html_used_for_icon_svg',
					'core/html contains an icon-sized SVG (width/height ≤ 200px). Use outermost/icon-block with iconName:"" instead. Select a static icon from the plugin\'s assets/icons/ directory — if none match, question.svg is the mandatory default. Never use core/html as a fallback for decorative icons.'
				);
			}
		}
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

	// Flex groups with justifyContent need the matching items-justified-* class in
	// both the className attribute and the rendered div. Without it, the CSS
	// justification has no effect and elements stack at flex-start regardless of the
	// layout attribute value. Most commonly hits space-between title/icon rows.
	$justify_map = array(
		'space-between' => 'items-justified-space-between',
		'center'        => 'items-justified-center',
		'right'         => 'items-justified-right',
		'space-around'  => 'items-justified-space-around',
		'space-evenly'  => 'items-justified-space-evenly',
	);
	if ( preg_match_all( '/<!--\s*wp:group\s+(\{.*?\})\s*-->(.*?)<!--\s*\/wp:group\s*-->/s', $markup, $flex_groups, PREG_SET_ORDER ) ) {
		foreach ( $flex_groups as $idx => $group ) {
			$attrs_raw  = isset( $group[1] ) ? $group[1] : '';
			$inner_html = isset( $group[2] ) ? $group[2] : '';
			$label      = 'core/group #' . ( $idx + 1 );
			$attrs      = json_decode( $attrs_raw, true );

			if ( ! is_array( $attrs ) ) {
				continue;
			}

			$justify = isset( $attrs['layout']['justifyContent'] ) ? $attrs['layout']['justifyContent'] : '';
			if ( empty( $justify ) || $justify === 'left' ) {
				continue;
			}

			$expected_class = isset( $justify_map[ $justify ] ) ? $justify_map[ $justify ] : '';
			if ( empty( $expected_class ) ) {
				continue;
			}

			$class_name = isset( $attrs['className'] ) ? $attrs['className'] : '';
			if ( false === strpos( $class_name, $expected_class ) ) {
				$issues[] = dhali_mcp_pattern_issue(
					'error',
					'flex_group_missing_justify_class',
					sprintf(
						'%s has layout.justifyContent:"%s" but is missing the "%s" class in className. Add it to both the className block attribute and the rendered div class list, or the justification will not apply.',
						$label,
						$justify,
						$expected_class
					)
				);
			}
		}
	}

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
		// ── dhali/get-plugin-paths ───────────────────────────────────────────
		// Sole purpose: return live filesystem paths the agent cannot know without WP.
		// PHP skeleton, viewportWidth, categories are static — documented in prompt.
		'dhali/get-plugin-paths' => array(
			'label'               => __( 'Get plugin paths', 'dhali' ),
			'description'         => __( 'Returns live plugin_path, patterns_path, and snippets_path for the dhali-pattern-library plugin. Also confirms viewportWidth and plugin_dir_url pattern.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'plugin_paths', 'Use "plugin_paths".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugin_path'    => dhali_mcp_string_schema( 'Absolute path to the dhali-pattern-library plugin directory.' ),
					'patterns_path'  => dhali_mcp_string_schema( 'Absolute path to the patterns/ subdirectory.' ),
					'snippets_path'  => dhali_mcp_string_schema( 'Absolute path to the snippets/ subdirectory.' ),
					'asset_url_pattern' => dhali_mcp_string_schema( 'PHP pattern for asset URLs inside pattern content strings.' ),
					'viewportWidth'  => array( 'type' => 'integer', 'description' => 'Always 1500.' ),
				),
				'required'   => array( 'plugin_path', 'patterns_path', 'snippets_path', 'asset_url_pattern', 'viewportWidth' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
				$plugin_path   = trailingslashit( $plugin_dir ) . 'Dhali-Pattern-Library';
				$patterns_path = trailingslashit( $plugin_path ) . 'patterns';
				$snippets_path = trailingslashit( $plugin_path ) . 'snippets';

				return array(
					'plugin_path'       => $plugin_path,
					'patterns_path'     => $patterns_path,
					'snippets_path'     => $snippets_path,
					'asset_url_pattern' => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME' ) . '",
					'viewportWidth'     => 1500,
				);
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		// Legacy alias — kept for backwards compatibility with older prompts.
		'dhali/get-pattern-template-skeleton' => array(
			'label'               => __( 'Get plugin paths (legacy)', 'dhali' ),
			'description'         => __( 'Deprecated alias for dhali/get-plugin-paths. Use get-plugin-paths instead.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema( 'pattern_template_skeleton', 'Use "pattern_template_skeleton".' ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'plugin_path'   => dhali_mcp_string_schema( 'Plugin directory path.' ),
					'patterns_path' => dhali_mcp_string_schema( 'Patterns subdirectory path.' ),
					'snippets_path' => dhali_mcp_string_schema( 'Snippets subdirectory path.' ),
				),
				'required' => array( 'plugin_path', 'patterns_path', 'snippets_path' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
				$plugin_path   = trailingslashit( $plugin_dir ) . 'Dhali-Pattern-Library';
				return array(
					'plugin_path'   => $plugin_path,
					'patterns_path' => trailingslashit( $plugin_path ) . 'patterns',
					'snippets_path' => trailingslashit( $plugin_path ) . 'snippets',
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
		// dhali/get-editor-safe-block-snippets removed.
		// HTML snippets now live as static files in plugin/snippets/.
		// Read with: @wp_cli raw cat PLUGIN_PATH/snippets/FILENAME.html
		// Available: cover-with-badge.html, plus-cta.html, flex-vertical.html,
		//            flex-horizontal.html, flex-space-between.html, card-shadow.html, social-links.html
		// get-local-assets returns the full snippets list alongside images and icons.

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
		// ── dhali/get-pattern-approach ──────────────────────────────────────────
		// Agentic routing: given a pattern type, returns the specific block tree,
		// required MCP abilities, and validation requirements for that type.
		'dhali/get-pattern-approach' => array(
			'label'               => __( 'Get pattern approach', 'dhali' ),
			'description'         => __( 'Given a pattern type, returns the canonical block tree, required MCP fetches, and validation requirements. Call after classifying the design source.', 'dhali' ),
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern_type' => array(
						'type'        => 'string',
						'description' => 'The classified pattern type.',
						'enum'        => array( 'hero', 'cta', 'feature-grid', 'testimonial', 'pricing', 'card', 'contact', 'post-card', 'intro', 'other' ),
					),
				),
				'required'             => array( 'pattern_type' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'pattern_type'       => array( 'type' => 'string' ),
					'visual_signals'     => array( 'type' => 'string', 'description' => 'How to identify this type visually.' ),
					'required_mcp'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'MCP abilities to call for this type.' ),
					'block_tree'         => array( 'type' => 'string', 'description' => 'Canonical block structure for this type.' ),
					'deep_validation'    => array( 'type' => 'string', 'description' => 'When deep editor-context validation is required.' ),
					'ollie_slugs'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Reference Ollie pattern slugs for this type.' ),
					'notes'              => array( 'type' => 'string', 'description' => 'Type-specific authoring notes.' ),
				),
				'required' => array( 'pattern_type', 'visual_signals', 'required_mcp', 'block_tree', 'deep_validation' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$type = isset( $input['pattern_type'] ) ? $input['pattern_type'] : 'other';

				$approaches = array(

					'hero' => array(
						'pattern_type'    => 'hero',
						'visual_signals'  => 'Full-width section, large heading (often centered), short subtext, one or two CTA buttons. May have a background image or solid color fill. Often the first section on a page.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"core/group [section — align:full, bg:tertiary or main, padding:xx-large v / medium h, constrained, margin:0]\n" .
							"  core/group [Hero — constrained]\n" .
							"    core/paragraph [overline — primary color, fontWeight:500, small, center]\n" .
							"    core/heading [h1 or h2 — x-large or xx-large, center]\n" .
							"    core/paragraph [subtext — secondary color, center]\n" .
							"    core/buttons [CTA row — center]\n" .
							"      core/button [is-style-fill]\n" .
							"      core/button [is-style-button-light] (optional second)\n" .
							"  [OPTIONAL] core/columns [50/50 — align:wide, verticalAlignment:center]\n" .
							"    core/column [content — heading, paragraph, buttons]\n" .
							"    core/column [image — core/cover or core/image]",
						'deep_validation' => 'Always — hero patterns almost always include core/cover for background images or linked CTA groups.',
						'ollie_slugs'     => array( 'ollie/hero-text-image-and-logos', 'ollie/hero-light', 'ollie/hero-dark' ),
						'notes'           => 'Use tertiary background for light heroes, main for dark. Overline paragraph (primary color, fontWeight:500, small) above the heading is the Ollie standard. Button row uses is-style-fill for primary and is-style-button-light for secondary.',
					),

					'cta' => array(
						'pattern_type'    => 'cta',
						'visual_signals'  => 'Heading + one or two buttons. Often centered or 50/50 columns (text left, action right). No card shell, no image (or small supporting image). Purpose is to drive a single action.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets' ),
						'block_tree'      =>
							"Centered variant:\n" .
							"  core/group [section — align:full, bg, padding:xx-large/medium, constrained]\n" .
							"    core/paragraph [overline]\n" .
							"    core/heading [center]\n" .
							"    core/paragraph [center, secondary]\n" .
							"    core/buttons [center]\n" .
							"\n" .
							"50/50 columns variant:\n" .
							"  core/group [section — align:full]\n" .
							"    core/columns [align:wide, verticalAlignment:center, blockGap:x-large]\n" .
							"      core/column [content — overline, heading, paragraph]\n" .
							"      core/column [action — paragraph secondary, core/buttons]",
						'deep_validation' => 'Only if CTA uses a linked core/group (href + animationType). Standard core/buttons with core/button do not require deep validation.',
						'ollie_slugs'     => array( 'ollie/text-call-to-action', 'ollie/card-text-and-call-to-action' ),
						'notes'           => 'Use core/buttons + core/button (is-style-fill / is-style-button-light) — never plus-cta-linked-group for page-level CTAs. The plus CTA is for card-level micro-CTAs only.',
					),

					'feature-grid' => array(
						'pattern_type'    => 'feature-grid',
						'visual_signals'  => 'Three or four equal columns, each with an icon at the top, a bold heading, and a short paragraph. May include a section title group above the grid.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"core/group [section — align:full, bg, padding:xx-large/medium, constrained, blockGap:x-large]\n" .
							"  core/group [Titles — constrained, blockGap:small]\n" .
							"    core/paragraph [overline — primary, fontWeight:500, small]\n" .
							"    core/heading [center]\n" .
							"    core/paragraph [center, secondary] (optional)\n" .
							"  core/columns [align:wide, blockGap on both axes]\n" .
							"    core/column × N\n" .
							"      outermost/icon-block [iconName:\"\", 48-64px, static SVG from assets/icons/]\n" .
							"      core/heading [h3, medium or large]\n" .
							"      core/paragraph",
						'deep_validation' => 'Always — outermost/icon-block with a read SVG always triggers deep validation.',
						'ollie_slugs'     => array( 'ollie/feature-boxes-with-icon-dark', 'ollie/feature-boxes-with-button', 'ollie/text-and-image-columns-with-icons' ),
						'notes'           => 'Icon selection: check.svg for features/benefits, question.svg as default. Read SVG from assets/icons/ with @wp_cli raw cat. Embed in outermost/icon-block with iconName:"". Never use core/html for icons.',
					),

					'testimonial' => array(
						'pattern_type'    => 'testimonial',
						'visual_signals'  => 'Quote text, star rating (★★★★★), person name and role, avatar photo. Usually 2-3 testimonials in a grid. May include logo strip.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"core/group [section — align:full, bg, padding:xx-large/medium, constrained, blockGap:x-large]\n" .
							"  core/group [Titles]\n" .
							"    core/paragraph [overline]\n" .
							"    core/heading [center]\n" .
							"  core/columns [testimonial grid — align:wide]\n" .
							"    core/column × N\n" .
							"      core/group [Testimonial — bg:base, radius:lg, shadow, padding:medium, constrained]\n" .
							"        core/paragraph [★★★★★ — primary color]\n" .
							"        core/paragraph [quote text]\n" .
							"        core/separator [is-style-separator-thin]\n" .
							"        core/group [Cite — flex horizontal, blockGap:small, verticalAlignment:center]\n" .
							"          core/image [avatar — is-style-rounded-full, width:60px, height:60px, src:avatar-placeholder.webp]\n" .
							"          core/group [Name + role — flex vertical, blockGap:0]\n" .
							"            core/paragraph [name — fontWeight:600, small]\n" .
							"            core/paragraph [role — secondary, x-small]",
						'deep_validation' => 'Rarely required. No cover, no linked groups, no icon-block. Skip unless fast validation returns warnings.',
						'ollie_slugs'     => array( 'ollie/testimonials-and-logos', 'ollie/text-and-image-columns-with-testimonial', 'ollie/testimonial-highlight' ),
						'notes'           => 'Avatar: core/image with className:is-style-rounded-full, width/height:60px, src from plugin assets (avatar-placeholder.webp). Stars are the literal ★ character in a paragraph with textColor:primary. Never use core/html for stars.',
					),

					'pricing' => array(
						'pattern_type'    => 'pricing',
						'visual_signals'  => 'Price amount (large number + period), feature list with checkmarks (✓), 2-3 tier columns, CTA button at the bottom of each tier.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"core/group [section — align:full, bg:tertiary, padding:xx-large/medium, constrained, blockGap:x-large]\n" .
							"  core/group [Titles]\n" .
							"    core/paragraph [overline]\n" .
							"    core/heading [center]\n" .
							"  core/columns [pricing grid — align:wide, blockGap:large]\n" .
							"    core/column × N\n" .
							"      core/group [Pricing Table — bg:base or main (featured), radius:lg, shadow, padding:large, constrained, blockGap:medium]\n" .
							"        core/group [Price — blockGap:small]\n" .
							"          core/heading [tier name, h3]\n" .
							"          core/paragraph [tier description, secondary]\n" .
							"          core/group [price amount — flex, blockGap:0]\n" .
							"            core/heading [price, xx-large, fontWeight:700]\n" .
							"            core/paragraph [/period, secondary, aligned bottom]\n" .
							"        core/separator [is-style-separator-thin]\n" .
							"        core/group [Features — blockGap:small]\n" .
							"          [repeat per feature:]\n" .
							"          core/group [Feature — flex horizontal nowrap, blockGap:small]\n" .
							"            core/paragraph [✓ — fontWeight:700]\n" .
							"            core/paragraph [feature text]\n" .
							"          core/separator [is-style-separator-thin, bg:border-light]\n" .
							"        core/buttons [CTA — full width]\n" .
							"          core/button [is-style-fill or is-style-button-light]",
						'deep_validation' => 'Only if CTA uses a linked core/group. Standard core/buttons does not require deep validation.',
						'ollie_slugs'     => array( 'ollie/pricing-table', 'ollie/pricing-table-with-testimonials' ),
						'notes'           => 'The ✓ character (not check.svg) is standard in pricing feature rows per Ollie patterns — it is a text character inside a bold paragraph, not an icon block. Use check.svg icon-block only when the design explicitly shows a graphic icon rather than a text character.',
					),

					'card' => array(
						'pattern_type'    => 'card',
						'visual_signals'  => 'Single card with rounded corners and shadow, containing image and/or content. Check get-editor-safe-block-snippets for card-structure-choose to pick the right variant (inset / edge-to-edge / overlapping).',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      => 'Fetch card-structure-choose from get-editor-safe-block-snippets and select the correct variant based on how the image relates to the card boundary.',
						'deep_validation' => 'Always if the card includes core/cover (badge-in-image, edge-to-edge, or overlapping variants). Always if outermost/icon-block with SVG is used.',
						'ollie_slugs'     => array( 'ollie/card-text-and-call-to-action', 'ollie/image-and-numbered-features' ),
						'notes'           => 'See card-structure-inset-image, card-structure-edge-to-edge-image, card-structure-overlapping-content-box in get-editor-safe-block-snippets for the three structural variants.',
					),

					'contact' => array(
						'pattern_type'    => 'contact',
						'visual_signals'  => 'Business name, hours, phone/email, social media icons. Often includes a photo header. May show multiple locations.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"Use card-structure-overlapping-content-box from get-editor-safe-block-snippets.\n" .
							"Inside the content group:\n" .
							"  core/heading [business name, center, fontWeight:700]\n" .
							"  core/paragraph [tagline, center, main-accent]\n" .
							"  core/separator [is-style-separator-thin]\n" .
							"  core/group [Hours — flex vertical, blockGap:small]\n" .
							"    core/paragraph [<strong>Open:</strong> placeholder, center] × rows\n" .
							"  core/paragraph [contact info, center, x-small, main-accent]\n" .
							"  core/social-links [center, is-style-logos-only, justifyContent:center]",
						'deep_validation' => 'Always — uses core/cover for the top image.',
						'ollie_slugs'     => array(),
						'notes'           => 'Social icons MUST use core/social-links + core/social-link, not question.svg. Use url:"#" as placeholder. core/social-link supports self-closing syntax (/-->).',
					),

					'post-card' => array(
						'pattern_type'    => 'post-card',
						'visual_signals'  => 'Article card with date, category, title, excerpt, and read-more. May have a category badge overlaid on the image.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      =>
							"Static variant (default — always build this first):\n" .
							"  card-structure-edge-to-edge-image from get-editor-safe-block-snippets\n" .
							"  Cover uses article-cover-card-with-pill snippet if badge overlaid on image\n" .
							"  Content group:\n" .
							"    core/paragraph [date — x-small, uppercase, letterSpacing:0.05em, main-accent]\n" .
							"    core/heading [h2 or h3, title]\n" .
							"    core/paragraph [• Read more link, small]\n" .
							"\n" .
							"Query Loop variant (only when explicitly requested):\n" .
							"  core/query > core/post-template > post blocks",
						'deep_validation' => 'Always — uses core/cover for the image.',
						'ollie_slugs'     => array( 'ollie/blog-post-columns' ),
						'notes'           => 'Always build the static variant first. Convert to Query Loop only when the user explicitly asks. Badge-in-image requires article-cover-card-with-pill from get-editor-safe-block-snippets.',
					),

					'intro' => array(
						'pattern_type'    => 'intro',
						'visual_signals'  => 'Text-only or text + small image section. Overline, heading, paragraph, optional link. No CTA buttons, no card shell.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton' ),
						'block_tree'      =>
							"core/group [section — align:full, bg, padding:xx-large/medium, constrained]\n" .
							"  core/group [Titles — constrained, blockGap:small]\n" .
							"    core/paragraph [overline — primary, fontWeight:500, small, center]\n" .
							"    core/heading [center]\n" .
							"    core/paragraph [center, secondary]",
						'deep_validation' => 'Rarely. Only if a cover or linked group is included.',
						'ollie_slugs'     => array( 'ollie/large-text-and-text-boxes' ),
						'notes'           => 'Simplest pattern type. No images, no icons, no CTAs. Rarely needs get-editor-safe-block-snippets. Just the skeleton and tokens.',
					),

					'other' => array(
						'pattern_type'    => 'other',
						'visual_signals'  => 'Could not classify into a known type.',
						'required_mcp'    => array( 'dhali/get-pattern-template-skeleton', 'dhali/get-editor-safe-block-snippets', 'dhali/get-local-assets' ),
						'block_tree'      => 'Describe the visual layout in the proposal. Use constrained core/group as the outer section wrapper with align:full, bg, padding:xx-large/medium, margin:0.',
						'deep_validation' => 'Use deep validation if core/cover, outermost/icon-block, or linked core/group is present.',
						'ollie_slugs'     => array(),
						'notes'           => 'Fetch all three MCP abilities to have full reference available.',
					),

				);

				$approach = isset( $approaches[ $type ] ) ? $approaches[ $type ] : $approaches['other'];

				return $approach;
			},
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta'                => dhali_mcp_public_tool_meta(),
		),

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
					'snippets'          => dhali_mcp_string_array_schema( 'Available snippet HTML filenames from snippets/. Read with @wp_cli raw cat PLUGIN_PATH/snippets/FILENAME.html.' ),
					'image_php_pattern' => dhali_mcp_string_schema( 'PHP pattern for image src.' ),
					'icon_php_pattern'  => dhali_mcp_string_schema( 'PHP pattern for icon src.' ),
					'snippet_read_cmd'  => dhali_mcp_string_schema( 'Command template for reading snippet files.' ),
					'icon_selection_guide' => array( 'type' => 'object', 'description' => 'Map of icon filename to use case.' ),
					'icon_read_cmd'     => dhali_mcp_string_schema( 'Command for reading and embedding an icon SVG.' ),
					'message'           => dhali_mcp_string_schema( 'Summary.' ),
				),
				'required'   => array( 'status', 'images', 'icons', 'snippets', 'image_php_pattern', 'icon_php_pattern', 'snippet_read_cmd', 'icon_selection_guide', 'icon_read_cmd', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$plugin_dir = plugin_dir_path( __FILE__ );
				$images_dir   = $plugin_dir . 'assets/images/';
				$icons_dir    = $plugin_dir . 'assets/icons/';
				$snippets_dir = $plugin_dir . 'snippets/';
				$images       = array();
				$icons        = array();
				$snippets     = array();

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

				if ( is_dir( $snippets_dir ) ) {
					foreach ( array_diff( scandir( $snippets_dir ), array( '.', '..' ) ) as $file ) {
						if ( preg_match( '/\.html$/i', $file ) ) {
							$snippets[] = $file;
						}
					}
					sort( $snippets );
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
					'snippets'          => array_values( $snippets ),
					'image_php_pattern' => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/FILENAME' ) . '",
					'icon_php_pattern'  => "' . esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/icons/FILENAME' ) . '",
					'snippet_read_cmd'  => '@wp_cli raw cat PLUGIN_PATH/snippets/FILENAME.html',
					'icon_selection_guide' => array(
						'check.svg'       => 'lists/confirmations/benefits',
						'arrow-right.svg' => 'CTAs/navigation/read-more',
						'plus.svg'        => 'add/expand/create actions',
						'question.svg'    => 'DEFAULT — use when nothing else fits',
						'image.svg'       => 'media/gallery/photography',
						'user.svg'        => 'person/author/avatar/team',
					),
					'icon_read_cmd'     => '@wp_cli raw cat PLUGIN_PATH/assets/icons/FILENAME.svg → embed in outermost/icon-block iconName:""',
					'message'           => count( $images ) . ' images, ' . count( $icons ) . ' icons, ' . count( $snippets ) . ' snippet files available.',
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
