<?php
/**
 * Plugin Name:       WPGraphQL Dynamic Styles Exporter
 * Description:       Extends WPGraphQL to provide global theme styles and post-specific dynamic block support CSS for various post types, with caching.
 * Version:           1.5.1
 * Author:            OJ Smith & The Robot (aka Gemini 2.5 by Google)
 * Author URI:        https://ojsmith.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-graphql-dynamic-styles-exporter
 * Requires WP:       6.1 
 * Requires PHP:      7.4
 * WPGraphQL Requires: 1.8.0 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Initializes the plugin after all other plugins are loaded.
 */
function wp_graphql_dynamic_styles_exporter_init() {
    if ( ! class_exists( 'WPGraphQL' ) ) {
        add_action( 'admin_notices', 'wp_graphql_dynamic_styles_exporter_missing_wpgraphql_notice' );
        return;
    }
    global $wp_version;
    if ( version_compare( $wp_version, '6.1', '<' ) ) { 
         add_action( 'admin_notices', 'wp_graphql_dynamic_styles_exporter_version_notice' );
        return;
    }

    if ( class_exists('WPGraphQL_Dynamic_Styles_Exporter') ) { 
        new WPGraphQL_Dynamic_Styles_Exporter();
    } else {
         add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WPGraphQL Dynamic Styles Exporter:</strong> Main plugin class not found.</p></div>';
        });
    }
}
add_action( 'plugins_loaded', 'wp_graphql_dynamic_styles_exporter_init', 20 ); 

/** Admin notice if WPGraphQL is not active. */
function wp_graphql_dynamic_styles_exporter_missing_wpgraphql_notice() {
    echo '<div class="notice notice-error"><p><strong>WPGraphQL Dynamic Styles Exporter:</strong> Requires WPGraphQL to be active.</p></div>';
}

/** Admin notice if WordPress version is too low. */
function wp_graphql_dynamic_styles_exporter_version_notice() {
    echo '<div class="notice notice-error"><p><strong>WPGraphQL Dynamic Styles Exporter:</strong> Requires WordPress version 6.1 or higher.</p></div>';
}


if ( ! class_exists('WPGraphQL_Dynamic_Styles_Exporter') ) {
    class WPGraphQL_Dynamic_Styles_Exporter {

        const TRANSIENT_PREFIX = 'wpgql_dyn_css_';
        const TRANSIENT_EXPIRATION = 12 * HOUR_IN_SECONDS; 

        public function __construct() {
            add_action( 'graphql_register_types', [ $this, 'register_graphql_fields' ], 20 ); 
            add_action( 'save_post', [ $this, 'clear_post_block_support_styles_cache' ], 10, 2 );
        }

        public function clear_post_block_support_styles_cache( $post_id, $post ) {
            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                return;
            }
            
            $registered_types_graphql_names = $this->get_target_graphql_post_type_names();
            $post_type_object = get_post_type_object( $post->post_type );
            
            // Convert WP post_type slug to its GraphQL single name for comparison
            $current_post_type_graphql_name = '';
            if ( $post_type_object && isset($post_type_object->graphql_single_name) ) {
                $current_post_type_graphql_name = ucfirst($post_type_object->graphql_single_name); // Ensure consistent casing with what TypeRegistry might return
            } elseif ( $post_type_object ) { // Fallback if graphql_single_name isn't set, try to derive from WP name
                 $current_post_type_graphql_name = ucfirst($post_type_object->name); // e.g. 'Post', 'Page'
            }


            if ( !empty($current_post_type_graphql_name) && in_array($current_post_type_graphql_name, $registered_types_graphql_names, true)) {
                $transient_key = self::TRANSIENT_PREFIX . $post_id;
                delete_transient( $transient_key );
            }
        }
        
        private function get_target_graphql_post_type_names() {
            $target_names = [];
            if (class_exists('\WPGraphQL\Type\Registry\TypeRegistry')) {
                $type_registry = \WPGraphQL\Type\Registry\TypeRegistry::get_instance();
                if ($type_registry) {
                    $all_types = $type_registry->get_types();
                    foreach ( $all_types as $type_name => $type_object ) { // Iterate with key as type name
                        if ( $type_object instanceof \WPGraphQL\Type\WPObjectType && 
                             method_exists($type_object, 'get_wp_object_type') &&
                             ($wp_object_type = $type_object->get_wp_object_type()) && 
                             $wp_object_type instanceof \WP_Post_Type && 
                             $wp_object_type->publicly_queryable ) {
                            
                            if (!empty($type_name)) { 
                                $target_names[] = $type_name; // Use the GraphQL type name directly
                            }
                        }
                    }
                }
            }
            if (empty($target_names)) {
                // Fallback to a default list if automatic detection fails (e.g. WPGraphQL context issue)
                // These should be the GraphQL Type names (e.g. "Post", "Page", "Project" if your CPT is "project")
                $target_names = ['Post', 'Page', 'Project']; 
                error_log('WPGraphQL Dynamic Styles Exporter: Automatic WPGraphQL post type detection failed or returned empty. Using default list: ' . implode(', ', $target_names));
            }
            return array_unique( $target_names );
        }


        public function register_graphql_fields() {
            register_graphql_field(
                'RootQuery', 
                'themeGlobalStyles',
                [
                    'type'        => 'String',
                    'description' => __( 'Retrieves global theme styles (global-styles-inline-css).', 'wp-graphql-dynamic-styles-exporter' ),
                    'args'        => [
                        'includeVariables'      => ['type' => 'Boolean', 'defaultValue' => true],
                        'includePresets'        => ['type' => 'Boolean', 'defaultValue' => true],
                        'includeStyles'         => ['type' => 'Boolean', 'defaultValue' => true],
                        'includeBaseLayoutStyles' => ['type' => 'Boolean', 'defaultValue' => true],
                    ],
                    'resolve'     => function( $source, array $args, $context, $info ) {
                        if ( ! function_exists( 'wp_get_global_stylesheet' ) ) {
                            return '/* Function wp_get_global_stylesheet() not available. */';
                        }
                        $style_types_to_include = [];
                        if ( ! empty( $args['includeVariables'] ) ) $style_types_to_include[] = 'variables';
                        if ( ! empty( $args['includePresets'] ) ) $style_types_to_include[] = 'presets';
                        if ( ! empty( $args['includeStyles'] ) ) $style_types_to_include[] = 'styles';
                        global $wp_version;
                        if ( version_compare( $wp_version, '6.5', '>=' ) && ! empty( $args['includeBaseLayoutStyles'] ) ) {
                             $style_types_to_include[] = 'base-layout-styles';
                        } elseif ( version_compare( $wp_version, '<', '6.5' ) && ! empty( $args['includeBaseLayoutStyles'] ) && !in_array('styles', $style_types_to_include, true) ) {
                            $style_types_to_include[] = 'styles'; 
                            $style_types_to_include = array_unique($style_types_to_include);
                        }
                        if ( empty( $style_types_to_include ) ) return '/* No style types selected. */';
                        return wp_get_global_stylesheet( $style_types_to_include );
                    },
                ]
            );

            $target_graphql_type_names = $this->get_target_graphql_post_type_names();

            if (empty($target_graphql_type_names)) {
                // Log already happened in get_target_graphql_post_type_names
                return;
            }
            
            foreach ( $target_graphql_type_names as $graphql_type_name ) {
                register_graphql_field(
                    $graphql_type_name,
                    'postBlockSupportStyles',
                    [
                        'type'        => 'String',
                        'description' => __( 'Dynamically generated CSS for block supports (core-block-supports-inline-css). Cached for performance.', 'wp-graphql-dynamic-styles-exporter' ),
                        'resolve'     => [ $this, 'resolve_post_block_support_styles' ], 
                    ]
                );
            }
        }

        public function resolve_post_block_support_styles( $source_object, array $args, $context, $info ) {
            try {
                if ( ! isset( $source_object->databaseId ) ) {
                    return '/* Source object does not have a databaseId. */';
                }
                
                $post_id = $source_object->databaseId;
                $transient_key = self::TRANSIENT_PREFIX . $post_id;

                $cached_css = get_transient( $transient_key );
                if ( false !== $cached_css ) {
                    return '/* CSS from cache for post ID ' . esc_html($post_id) . " */\n" . $cached_css;
                }

                $wp_post_object = get_post( $post_id );
                if ( ! $wp_post_object || ! is_a($wp_post_object, 'WP_Post') || ! property_exists( $wp_post_object, 'post_content' ) || is_null( $wp_post_object->post_content ) ) {
                    return '/* Post object or post_content not found/invalid for ID: ' . esc_html($post_id) . '. */';
                }
                $post_content = $wp_post_object->post_content;
                
                $style_engine_file_path = ABSPATH . WPINC . '/class-wp-style-engine.php';
                if ( file_exists( $style_engine_file_path ) && ! class_exists( 'WP_Style_Engine', false ) ) {
                    require_once $style_engine_file_path;
                }
                if ( ! class_exists( 'WP_Style_Engine' ) ) { 
                    return '/* WP_Style_Engine class not available. */';
                }
                if ( is_null($post_content) || empty( trim( $post_content ) ) ) {
                    set_transient( $transient_key, '/* Content is empty or null. */', self::TRANSIENT_EXPIRATION );
                    return '/* Content is empty or null. */';
                }
                
                $blocks = parse_blocks( $post_content );
                if ( empty( $blocks ) ) {
                    set_transient( $transient_key, '/* No blocks found in content. */', self::TRANSIENT_EXPIRATION );
                    return '/* No blocks found in content. */';
                }

                global $post;
                $original_post_global = $post; 
                $post = $wp_post_object; 
                setup_postdata( $post );

                $css = '';
                $debug_messages = [];
                
                $loaded_style_engine_file_path = 'N/A';
                if (class_exists('WP_Style_Engine', false)) {
                    $reflector = new \ReflectionClass('WP_Style_Engine');
                    $loaded_style_engine_file_path = $reflector->getFileName();
                }
                $debug_messages[] = 'WP_Style_Engine_loaded_from: ' . esc_html($loaded_style_engine_file_path);

                ob_start();
                foreach ( $blocks as $block ) {
                    echo render_block( $block );
                }
                ob_end_clean(); 

                if ( class_exists('WP_Style_Engine_CSS_Rules_Store', false) ) {
                    $block_supports_store = WP_Style_Engine::get_store( 'block-supports' ); 
                    if ( $block_supports_store && is_a( $block_supports_store, 'WP_Style_Engine_CSS_Rules_Store' ) ) {
                        if ( method_exists( $block_supports_store, 'get_all_rules' ) ) { // Check if get_all_rules exists on the store
                             $rules_collection_array = $block_supports_store->get_all_rules();
                             if ( !empty( $rules_collection_array ) && is_array( $rules_collection_array ) ) {
                                if (method_exists('WP_Style_Engine', 'compile_stylesheet_from_css_rules')) {
                                    $css = WP_Style_Engine::compile_stylesheet_from_css_rules( $rules_collection_array, ['prettify' => false] );
                                    $debug_messages[] = 'Used_compile_stylesheet_from_css_rules.';
                                } else {
                                    $css = '/* Fallback: Method compile_stylesheet_from_css_rules does not exist on WP_Style_Engine. */';
                                    $debug_messages[] = 'Method_compile_stylesheet_from_css_rules_NOT_FOUND.';
                                }
                                if (method_exists($block_supports_store, 'reset')) {
                                    $block_supports_store->reset(); 
                                }
                            } elseif (empty($rules_collection_array)) { 
                                $css = '/* No rules found in block supports store collection (collection is empty). */';
                                $debug_messages[] = 'Rules_collection_from_store_is_empty.';
                            } else { 
                                $css = '/* Rules collection from store is not an array as expected. Type: ' . esc_html(gettype($rules_collection_array)) . ' */';
                                 $debug_messages[] = 'Rules_collection_from_store_is_not_array.';
                            }
                        } else { 
                             $css = '/* Fallback: Store object (class: ' . esc_html(get_class($block_supports_store)) . ') does not have get_all_rules method. */';
                             $debug_messages[] = 'Store_object_missing_get_all_rules_method.';
                        }
                    } else { 
                         $css = '/* Block supports store not found or not a valid store object. */';
                         $debug_messages[] = 'Block_supports_store_invalid_or_not_found.';
                    }
                } else {
                    $css = '/* WP_Style_Engine_CSS_Rules_Store class not found. */';
                    $debug_messages[] = 'WP_Style_Engine_CSS_Rules_Store_class_not_found.';
                }
                
                wp_reset_postdata(); 
                $post = $original_post_global;

                $final_css_content = $css ?: '/* No dynamic block support styles generated. */';
                $final_output = '/* Debug: ' . implode(' ', $debug_messages) . " */\n" . $final_css_content;
                
                set_transient( $transient_key, $final_css_content, self::TRANSIENT_EXPIRATION );
                return $final_output;

            } catch ( \Throwable $e ) {
                $error_message = '/* GraphQL Resolver Error: ' . esc_html( $e->getMessage() ) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ' */';
                if ( defined('WP_DEBUG') && WP_DEBUG === true ) {
                    error_log( 'WPGraphQL Dynamic Styles Exporter - CAUGHT ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . ' Stack: ' . $e->getTraceAsString() );
                }
                return $error_message;
            }
        }
    }
}
