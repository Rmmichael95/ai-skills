<?php
/**
 * Plugin Name: Dhali MCP Abilities
 * Description: Local WordPress MCP abilities for Claude/agent workflows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared MCP metadata for public read-only tools.
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
 * Register Dhali MCP abilities.
 */
function dhali_register_mcp_abilities() {
	static $registered = false;

	if ( $registered || ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$registered = true;

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
				'required'   => array(
					'site_title',
					'active_theme_name',
					'active_theme_slug',
					'template',
					'stylesheet',
				),
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
					'layout_defaults' => array(
						'type'        => 'object',
						'description' => 'The compiled theme.json layout settings.',
					),
				),
				'required'   => array(
					'core_version',
					'php_version',
					'active_theme',
					'layout_defaults',
				),
			),
			'execute_callback'    => function ( $input = array() ) {
				$theme           = wp_get_theme();
				$theme_json      = WP_Theme_JSON_Resolver::get_theme_data();
				$settings        = $theme_json->get_settings();
				$layout_defaults = isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array();

				return array(
					'core_version'    => get_bloginfo( 'version' ),
					'php_version'     => PHP_VERSION,
					'active_theme'    => $theme->get( 'Name' ),
					'layout_defaults' => $layout_defaults,
				);
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
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'colors'     => array(
						'type'        => 'array',
						'description' => 'Color preset slugs from theme.json.',
						'items'       => array( 'type' => 'string' ),
					),
					'spacing'    => array(
						'type'        => 'array',
						'description' => 'Spacing preset slugs from theme.json.',
						'items'       => array( 'type' => 'string' ),
					),
					'font_sizes' => array(
						'type'        => 'array',
						'description' => 'Font size preset slugs from theme.json.',
						'items'       => array( 'type' => 'string' ),
					),
					'layout'     => array(
						'type'        => 'object',
						'description' => 'The compiled theme.json layout settings.',
					),
				),
				'required'   => array(
					'colors',
					'spacing',
					'font_sizes',
					'layout',
				),
			),
			'execute_callback'    => function ( $input = array() ) {
				$theme_json = WP_Theme_JSON_Resolver::get_theme_data();
				$settings   = $theme_json->get_settings();

				return array(
					'colors'     => array_column( $settings['color']['palette'] ?? array(), 'slug' ),
					'spacing'    => array_column( $settings['spacing']['spacingSizes'] ?? array(), 'slug' ),
					'font_sizes' => array_column( $settings['typography']['fontSizes'] ?? array(), 'slug' ),
					'layout'     => isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array(),
				);
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
					'categories'    => array(
						'type'        => 'array',
						'description' => 'Default pattern categories.',
						'items'       => array( 'type' => 'string' ),
					),
					'description'   => dhali_mcp_string_schema( 'Pattern description placeholder.' ),
					'keywords'      => array(
						'type'        => 'array',
						'description' => 'Default pattern keyword placeholders.',
						'items'       => array( 'type' => 'string' ),
					),
					'viewportWidth' => array(
						'type'        => 'integer',
						'description' => 'Default preview viewport width.',
					),
					'blockTypes'    => array(
						'type'        => 'array',
						'description' => 'Default block type associations.',
						'items'       => array( 'type' => 'string' ),
					),
					'content'       => dhali_mcp_string_schema( 'Pattern block markup placeholder.' ),
				),
				'required'   => array(
					'title',
					'categories',
					'description',
					'keywords',
					'viewportWidth',
					'blockTypes',
					'content',
				),
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
						'description' => 'Whether parse_blocks returned at least one parsed block.',
					),
					'block_count' => array(
						'type'        => 'integer',
						'description' => 'Number of parsed top-level blocks.',
					),
					'block_names' => array(
						'type'        => 'array',
						'description' => 'Parsed top-level block names.',
						'items'       => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'valid', 'block_count', 'block_names' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				$markup = isset( $input['markup'] ) && is_string( $input['markup'] ) ? $input['markup'] : '';
				$blocks = parse_blocks( $markup );

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
					'valid'       => ! empty( $blocks ),
					'block_count' => count( $blocks ),
					'block_names' => $block_names,
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
			'description'         => 'Updates context.md with the current WordPress project state.',
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
						'description' => 'Must be true to write context.md.',
					),
				),
				'required'             => array( 'request', 'confirm_write' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'        => array(
						'type'        => 'string',
						'description' => 'Write status.',
					),
					'path'          => array(
						'type'        => 'string',
						'description' => 'Absolute path to context.md.',
					),
					'bytes_written' => array(
						'type'        => 'integer',
						'description' => 'Number of bytes written.',
					),
					'message'       => array(
						'type'        => 'string',
						'description' => 'Human-readable result message.',
					),
				),
				'required'   => array( 'status', 'path', 'bytes_written', 'message' ),
			),
			'execute_callback'    => function ( $input = array() ) {
				if ( empty( $input['confirm_write'] ) ) {
					return array(
						'status'        => 'error',
						'path'          => ABSPATH . 'context.md',
						'bytes_written' => 0,
						'message'       => 'confirm_write must be true before context.md can be updated.',
					);
				}

				$context_path = ABSPATH . 'context.md';

				$theme      = wp_get_theme();
				$theme_json = WP_Theme_JSON_Resolver::get_theme_data();
				$settings   = $theme_json->get_settings();

				$tokens = array(
					'colors'     => array_column( $settings['color']['palette'] ?? array(), 'slug' ),
					'spacing'    => array_column( $settings['spacing']['spacingSizes'] ?? array(), 'slug' ),
					'font_sizes' => array_column( $settings['typography']['fontSizes'] ?? array(), 'slug' ),
					'layout'     => isset( $settings['layout'] ) && is_array( $settings['layout'] ) ? $settings['layout'] : array(),
				);

				$content = "# WordPress Project Context\n\n";
				$content .= '- Core Version: ' . get_bloginfo( 'version' ) . "\n";
				$content .= '- PHP Version: ' . PHP_VERSION . "\n";
				$content .= '- Active Theme: ' . $theme->get( 'Name' ) . "\n";
				$content .= '- Theme Slug: ' . get_stylesheet() . "\n\n";
				$content .= "## Tokens\n\n";
				$content .= "```json\n";
				$content .= wp_json_encode( $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$content .= "\n```\n";

				$bytes_written = file_put_contents( $context_path, $content );

				if ( false === $bytes_written ) {
					return array(
						'status'        => 'error',
						'path'          => $context_path,
						'bytes_written' => 0,
						'message'       => 'Failed to write context.md.',
					);
				}

				return array(
					'status'        => 'success',
					'path'          => $context_path,
					'bytes_written' => $bytes_written,
					'message'       => 'context.md updated successfully.',
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
