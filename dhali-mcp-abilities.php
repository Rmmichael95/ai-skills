<?php
/**
 * Plugin Name: Dhali MCP Abilities
 * Description: Local WordPress MCP abilities for Claude/agent workflows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared MCP metadata for public tools.
 *
 * @return array<string, mixed>
 */
function dhali_mcp_public_tool_meta() {
	return array(
		'mcp' => array(
			'public' => true,
			'type'   => 'tool',
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

/**
 * Collect unique string values for a key from a nested array.
 *
 * This handles theme.json settings that may be grouped by origin, for example:
 * settings.color.palette.theme, settings.color.palette.default, etc.
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

	$values = array_values( array_unique( $values ) );
	sort( $values, SORT_NATURAL | SORT_FLAG_CASE );

	return $values;
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
 * @param array<int|string, mixed>       $settings        Theme settings from WP_Theme_JSON_Resolver.
 * @param array<int|string, mixed>       $global_settings Settings from wp_get_global_settings().
 * @param array<int, array<int, string>> $paths     Candidate paths.
 * @return array<int, string>
 */
function dhali_mcp_collect_token_slugs_from_paths( $settings, $global_settings, $paths ) {
	$slugs = array();

	foreach ( $paths as $path ) {
		$slugs = array_merge( $slugs, dhali_mcp_collect_values_by_key( dhali_mcp_array_get( $settings, $path ), 'slug' ) );
		$slugs = array_merge( $slugs, dhali_mcp_collect_values_by_key( dhali_mcp_array_get( $global_settings, $path ), 'slug' ) );
	}

	$slugs = array_values( array_unique( array_filter( $slugs ) ) );
	sort( $slugs, SORT_NATURAL | SORT_FLAG_CASE );

	return $slugs;
}

/**
 * Get merged/global theme settings with safe fallbacks.
 *
 * @return array{settings: array<string, mixed>, global_settings: array<string, mixed>}
 */
function dhali_mcp_get_theme_settings_data() {
	$settings        = array();
	$global_settings = array();

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
		'settings'        => is_array( $settings ) ? $settings : array(),
		'global_settings' => is_array( $global_settings ) ? $global_settings : array(),
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
	$layout        = dhali_mcp_array_get( $global, array( 'layout' ) );

	if ( ! is_array( $layout ) ) {
		$layout = dhali_mcp_array_get( $settings, array( 'layout' ) );
	}

	return array(
		'core_version'    => get_bloginfo( 'version' ),
		'php_version'     => PHP_VERSION,
		'active_theme'    => $theme->get( 'Name' ),
		'theme_slug'      => get_stylesheet(),
		'template'        => get_template(),
		'stylesheet'      => get_stylesheet(),
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

	$layout = dhali_mcp_array_get( $global, array( 'layout' ) );
	if ( ! is_array( $layout ) ) {
		$layout = dhali_mcp_array_get( $settings, array( 'layout' ) );
	}

	$custom = dhali_mcp_array_get( $settings, array( 'custom' ) );
	if ( ! is_array( $custom ) ) {
		$custom = dhali_mcp_array_get( $global, array( 'custom' ) );
	}

	$colors = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'color', 'palette' ),
		)
	);

	$gradients = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'color', 'gradients' ),
		)
	);

	$spacing = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'spacing', 'spacingSizes' ),
			array( 'spacing', 'spacingScale' ),
		)
	);

	$font_sizes = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'typography', 'fontSizes' ),
		)
	);

	$font_families = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'typography', 'fontFamilies' ),
		)
	);

	$shadows = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'shadow', 'presets' ),
			array( 'shadow' ),
		)
	);

	$border_radius = dhali_mcp_collect_token_slugs_from_paths(
		$settings,
		$global,
		array(
			array( 'border', 'radius' ),
			array( 'custom', 'borderRadius' ),
			array( 'custom', 'border-radius' ),
		)
	);

	// Some themes store radius tokens as associative custom keys rather than objects with slugs.
	if ( empty( $border_radius ) ) {
		$custom_radius = dhali_mcp_array_get( $settings, array( 'custom', 'borderRadius' ) );
		if ( ! is_array( $custom_radius ) ) {
			$custom_radius = dhali_mcp_array_get( $settings, array( 'custom', 'border-radius' ) );
		}
		if ( is_array( $custom_radius ) ) {
			$border_radius = array_values( array_filter( array_keys( $custom_radius ), 'is_string' ) );
			sort( $border_radius, SORT_NATURAL | SORT_FLAG_CASE );
		}
	}

	return array(
		'colors'        => $colors,
		'gradients'     => $gradients,
		'spacing'       => $spacing,
		'font_sizes'    => $font_sizes,
		'font_families' => $font_families,
		'shadows'       => $shadows,
		'border_radius' => $border_radius,
		'layout'        => is_array( $layout ) ? $layout : array(),
		'custom'        => is_array( $custom ) ? $custom : array(),
	);
}

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
 * @return string
 */
function dhali_mcp_build_context_markdown() {
	$snapshot = dhali_mcp_get_project_snapshot_data();
	$tokens   = dhali_mcp_get_token_and_layout_data();

	$content  = '# ' . ucwords( str_replace( array( '-', '_' ), ' ', dhali_mcp_get_project_slug() ) ) . " WordPress Context\n\n";
	$content .= "## Generated\n\n";
	$content .= '- Date/time: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
	$content .= '- WordPress root: ' . untrailingslashit( ABSPATH ) . "\n\n";

	$content .= "## WordPress Runtime\n\n";
	$content .= '- Core version: ' . $snapshot['core_version'] . "\n";
	$content .= '- PHP version: ' . $snapshot['php_version'] . "\n";
	$content .= '- Active theme: ' . $snapshot['active_theme'] . "\n";
	$content .= '- Theme slug: ' . $snapshot['theme_slug'] . "\n";
	$content .= '- Template: ' . $snapshot['template'] . "\n";
	$content .= '- Stylesheet: ' . $snapshot['stylesheet'] . "\n";
	$content .= '- Child theme: ' . ( $snapshot['is_child_theme'] ? 'yes' : 'no' ) . "\n\n";

	$content .= "## Layout\n\n";
	$content .= "```json\n";
	$content .= wp_json_encode( $tokens['layout'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	$content .= "\n```\n\n";

	$content .= "## Canonical Token Slugs\n\n";
	$content .= '- Colors: ' . ( ! empty( $tokens['colors'] ) ? implode( ', ', $tokens['colors'] ) : 'None found' ) . "\n";
	$content .= '- Gradients: ' . ( ! empty( $tokens['gradients'] ) ? implode( ', ', $tokens['gradients'] ) : 'None found' ) . "\n";
	$content .= '- Spacing: ' . ( ! empty( $tokens['spacing'] ) ? implode( ', ', $tokens['spacing'] ) : 'None found' ) . "\n";
	$content .= '- Font sizes: ' . ( ! empty( $tokens['font_sizes'] ) ? implode( ', ', $tokens['font_sizes'] ) : 'None found' ) . "\n";
	$content .= '- Font families: ' . ( ! empty( $tokens['font_families'] ) ? implode( ', ', $tokens['font_families'] ) : 'None found' ) . "\n";
	$content .= '- Shadows: ' . ( ! empty( $tokens['shadows'] ) ? implode( ', ', $tokens['shadows'] ) : 'None found' ) . "\n";
	$content .= '- Border radius: ' . ( ! empty( $tokens['border_radius'] ) ? implode( ', ', $tokens['border_radius'] ) : 'None found' ) . "\n\n";

	$content .= "## Custom Tokens\n\n";
	$content .= "```json\n";
	$content .= wp_json_encode( $tokens['custom'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	$content .= "\n```\n";

	return $content;
}

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
			'colors'        => dhali_mcp_string_array_schema( 'Color preset slugs from theme.json.' ),
			'gradients'     => dhali_mcp_string_array_schema( 'Gradient preset slugs from theme.json.' ),
			'spacing'       => dhali_mcp_string_array_schema( 'Spacing preset slugs from theme.json.' ),
			'font_sizes'    => dhali_mcp_string_array_schema( 'Font size preset slugs from theme.json.' ),
			'font_families' => dhali_mcp_string_array_schema( 'Font family preset slugs from theme.json.' ),
			'shadows'       => dhali_mcp_string_array_schema( 'Shadow preset slugs from theme.json.' ),
			'border_radius' => dhali_mcp_string_array_schema( 'Border radius preset slugs from theme.json or custom tokens.' ),
			'layout'        => array(
				'type'        => 'object',
				'description' => 'The compiled theme.json layout settings.',
			),
			'custom'        => array(
				'type'        => 'object',
				'description' => 'Custom theme.json settings.',
			),
		),
		'required'   => array( 'colors', 'gradients', 'spacing', 'font_sizes', 'font_families', 'shadows', 'border_radius', 'layout', 'custom' ),
	);

	$abilities = array(
		'dhali/get-site-info'                 => array(
			'label'               => 'Get site info',
			'description'         => 'Returns the WordPress site title and active theme information.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema(
				'site_info',
				'Use "site_info". This exists to avoid empty-parameter execution issues.'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'site_title'        => dhali_mcp_string_schema( 'The WordPress site title.' ),
					'active_theme_name' => dhali_mcp_string_schema( 'The active theme display name.' ),
					'active_theme_slug' => dhali_mcp_string_schema( 'The active stylesheet/theme slug.' ),
					'template'          => dhali_mcp_string_schema( 'The parent template slug.' ),
					'stylesheet'        => dhali_mcp_string_schema( 'The active stylesheet slug.' ),
				),
				'required'   => array( 'site_title', 'active_theme_name', 'active_theme_slug', 'template', 'stylesheet' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$theme = wp_get_theme();

				return array(
					'site_title'        => get_bloginfo( 'name' ),
					'active_theme_name' => $theme->get( 'Name' ),
					'active_theme_slug' => get_stylesheet(),
					'template'          => get_template(),
					'stylesheet'        => get_stylesheet(),
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/get-project-snapshot'          => array(
			'label'               => 'Get project snapshot',
			'description'         => 'Returns a compact WordPress environment snapshot and layout defaults.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema(
				'project_snapshot',
				'Use "project_snapshot". This exists to avoid empty-parameter execution issues.'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'core_version'    => dhali_mcp_string_schema( 'The WordPress core version.' ),
					'php_version'     => dhali_mcp_string_schema( 'The PHP runtime version.' ),
					'active_theme'    => dhali_mcp_string_schema( 'The active theme display name.' ),
					'theme_slug'      => dhali_mcp_string_schema( 'The active stylesheet/theme slug.' ),
					'template'        => dhali_mcp_string_schema( 'The parent template slug.' ),
					'stylesheet'      => dhali_mcp_string_schema( 'The active stylesheet slug.' ),
					'is_child_theme'  => array(
						'type'        => 'boolean',
						'description' => 'Whether the active theme is a child theme.',
					),
					'layout_defaults' => array(
						'type'        => 'object',
						'description' => 'The compiled theme.json layout settings.',
					),
				),
				'required'   => array( 'core_version', 'php_version', 'active_theme', 'theme_slug', 'template', 'stylesheet', 'is_child_theme', 'layout_defaults' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_project_snapshot_data();
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/get-token-and-layout-map'      => array(
			'label'               => 'Get token and layout map',
			'description'         => 'Returns flattened theme.json preset slugs and layout settings.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema(
				'token_and_layout_map',
				'Use "token_and_layout_map". This exists to avoid empty-parameter execution issues.'
			),
			'output_schema'       => $token_output_schema,
			'execute_callback'    => function ( $input = array() ) {
				return dhali_mcp_get_token_and_layout_data();
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/get-pattern-template-skeleton' => array(
			'label'               => 'Get pattern template skeleton',
			'description'         => 'Returns the standard PHP return-array skeleton for Dhali block patterns.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema(
				'pattern_template_skeleton',
				'Use "pattern_template_skeleton" to fetch the standard Dhali pattern return-array skeleton.'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title'         => dhali_mcp_string_schema( 'Pattern title placeholder.' ),
					'categories'    => dhali_mcp_string_array_schema( 'Default pattern categories.' ),
					'description'   => dhali_mcp_string_schema( 'Pattern description placeholder.' ),
					'keywords'      => dhali_mcp_string_array_schema( 'Default pattern keyword placeholders.' ),
					'viewportWidth' => array(
						'type'        => 'integer',
						'description' => 'Default preview viewport width.',
					),
					'blockTypes'    => dhali_mcp_string_array_schema( 'Default block type associations.' ),
					'content'       => dhali_mcp_string_schema( 'Pattern block markup placeholder.' ),
				),
				'required'   => array( 'title', 'categories', 'description', 'keywords', 'viewportWidth', 'blockTypes', 'content' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				return array(
					'title'         => '',
					'categories'    => array( 'dhali-web-development', 'card' ),
					'description'   => '',
					'keywords'      => array(),
					'viewportWidth' => 1000,
					'blockTypes'    => array( 'core/group' ),
					'content'       => '',
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/validate-pattern-markup'       => array(
			'label'               => 'Validate pattern markup',
			'description'         => 'Checks whether a string can be parsed as WordPress block markup.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'markup' => array(
						'type'        => 'string',
						'description' => 'The WordPress block markup string to validate.',
					),
				),
				'required'             => array( 'markup' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'valid'       => array(
						'type'        => 'boolean',
						'description' => 'Whether parse_blocks returned at least one named parsed block and no obvious markup issue was found.',
					),
					'block_count' => array(
						'type'        => 'integer',
						'description' => 'Number of parsed top-level blocks.',
					),
					'block_names' => dhali_mcp_string_array_schema( 'Parsed top-level block names.' ),
					'issues'      => dhali_mcp_string_array_schema( 'Basic validation issues found.' ),
				),
				'required'   => array( 'valid', 'block_count', 'block_names', 'issues' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$blocks = parse_blocks( $markup );
				$issues = array();

				if ( '' === trim( $markup ) ) {
					$issues[] = 'Markup is empty.';
				}

				if ( false !== strpos( $markup, 'PLACEHOLDER' ) ) {
					$issues[] = 'Markup contains PLACEHOLDER text.';
				}

				if ( substr_count( $markup, '<!-- wp:' ) !== substr_count( $markup, '<!-- /wp:' ) ) {
					$issues[] = 'Opening and closing block comment counts do not match.';
				}

				$block_names = array_values(
					array_filter(
						array_map(
							function ( $block ) {
								return isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
							},
							$blocks
						)
					)
				);

				return array(
					'valid'       => ! empty( $block_names ) && empty( $issues ),
					'block_count' => count( $blocks ),
					'block_names' => $block_names,
					'issues'      => $issues,
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/get-icon-manifest'             => array(
			'label'               => 'Get icon manifest',
			'description'         => 'Returns Ollie/Outermost icon block markup templates for named and custom SVG icons.',
			'category'            => 'site',
			'input_schema'        => dhali_mcp_request_input_schema(
				'icon_manifest',
				'Use "icon_manifest" to fetch available icon slugs and markup skeletons.'
			),
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
						'outermost-named-icon'        => '<!-- wp:outermost/icon-block {"iconName":"placeholder","iconColor":"primary","width":"1.75rem"} --><div class="wp-block-outermost-icon-block"><div class="icon-container has-icon-color has-primary-color" style="width:1.75rem;transform:rotate(0deg) scaleX(1) scaleY(1)"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true" focusable="false"></svg></div></div><!-- /wp:outermost/icon-block -->',
						'outermost-custom-svg-pill'   => '<!-- wp:outermost/icon-block {"iconName":"","iconBackgroundColor":"primary","iconBackgroundColorValue":"#fbb042","width":"90px","style":{"border":{"radius":{"topLeft":"var:preset|border-radius|full","topRight":"var:preset|border-radius|full","bottomLeft":"var:preset|border-radius|full","bottomRight":"var:preset|border-radius|full"}},"spacing":{"padding":{"top":"20px","right":"5px","bottom":"20px","left":"5px"}}}} --><div class="wp-block-outermost-icon-block"><div class="icon-container has-icon-background-color has-primary-background-color" style="background-color:#fbb042;width:90px;padding-top:20px;padding-right:5px;padding-bottom:20px;padding-left:5px;border-top-left-radius:var(--wp--preset--border-radius--full);border-top-right-radius:var(--wp--preset--border-radius--full);border-bottom-left-radius:var(--wp--preset--border-radius--full);border-bottom-right-radius:var(--wp--preset--border-radius--full);transform:rotate(0deg) scaleX(1) scaleY(1)"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"></svg></div></div><!-- /wp:outermost/icon-block -->',
						'custom-svg-behavior-summary' => 'Keep SVG paths inside the icon-container. Put width, background color, padding, border radius, transform, and preset classes on the outermost/icon-block wrapper/container.',
					),
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),

		'dhali/sync-context'                  => array(
			'label'               => 'Sync Context Cache',
			'description'         => 'Updates the project context markdown file with the current WordPress project state.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request'       => array(
						'type'        => 'string',
						'description' => 'Use "sync_context".',
						'enum'        => array( 'sync_context' ),
						'default'     => 'sync_context',
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
					'status'        => dhali_mcp_string_schema( 'Write status.' ),
					'path'          => dhali_mcp_string_schema( 'Absolute path to the context markdown file.' ),
					'bytes_written' => array(
						'type'        => 'integer',
						'description' => 'Number of bytes written.',
					),
					'message'       => dhali_mcp_string_schema( 'Human-readable result message.' ),
				),
				'required'   => array( 'status', 'path', 'bytes_written', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$project_slug = dhali_mcp_get_project_slug();
				$context_path = ABSPATH . $project_slug . '_context.md';

				if ( empty( $input['confirm_write'] ) ) {
					return array(
						'status'        => 'error',
						'path'          => $context_path,
						'bytes_written' => 0,
						'message'       => 'confirm_write must be true before the context file can be updated.',
					);
				}

				$content       = dhali_mcp_build_context_markdown();
				$bytes_written = file_put_contents( $context_path, $content );

				if ( false === $bytes_written ) {
					return array(
						'status'        => 'error',
						'path'          => $context_path,
						'bytes_written' => 0,
						'message'       => 'Failed to write context file.',
					);
				}

				return array(
					'status'        => 'success',
					'path'          => $context_path,
					'bytes_written' => $bytes_written,
					'message'       => 'Context file updated successfully.',
				);
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_theme_options' );
			},
			'meta'                => dhali_mcp_public_tool_meta(),
		),
	);

	foreach ( $abilities as $name => $args ) {
		$result = wp_register_ability( $name, $args );

		if ( is_wp_error( $result ) ) {
			error_log( 'Dhali MCP ability failed to register: ' . $name . ' — ' . $result->get_error_message() );

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::warning( 'Dhali MCP ability failed to register: ' . $name . ' — ' . $result->get_error_message() );
			}
		} elseif ( false === $result ) {
			error_log( 'Dhali MCP ability failed to register: ' . $name );

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::warning( 'Dhali MCP ability failed to register: ' . $name );
			}
		}
	}
}
add_action( 'wp_abilities_api_init', 'dhali_register_mcp_abilities' );
