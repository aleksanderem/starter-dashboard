<?php
/**
 * Starter Dashboard Addon: Post Type Pill
 *
 * Elementor widget that displays a styled pill/badge with the current post type name
 */

defined('ABSPATH') || exit;

class Starter_Addon_Post_Type_Pill {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('elementor/widgets/register', [$this, 'register_widget']);
        add_action('elementor/elements/categories_registered', [$this, 'add_widget_category']);
        add_shortcode('post_type_pill', [$this, 'shortcode_handler']);
        add_action('wp_head', [$this, 'output_styles']);
    }

    public function add_widget_category($elements_manager) {
        $elements_manager->add_category(
            'starter-utils',
            [
                'title' => __('Starter Utils', 'starter-dashboard'),
                'icon' => 'fa fa-plug',
            ]
        );
    }

    public function register_widget($widgets_manager) {
        require_once __DIR__ . '/widget.php';
        $widgets_manager->register(new Starter_Post_Type_Pill_Widget());
    }

    /**
     * Get all public post types with their labels
     */
    public static function get_post_types_options() {
        $post_types = get_post_types(['public' => true], 'objects');
        $options = [];

        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->labels->singular_name;
        }

        return $options;
    }

    /**
     * Get default label mappings (can be overridden in widget settings)
     */
    public static function get_default_labels() {
        return [
            'post'              => 'Article',
            'page'              => 'Page',
            'subscribers'       => 'Job Alert',
            'elementor_library' => 'Template',
        ];
    }

    /**
     * Shortcode handler: [post_type_pill]
     */
    public function shortcode_handler($atts) {
        $atts = shortcode_atts([
            'post_id' => 0,
            'class'   => 'post-type-pill',
        ], $atts, 'post_type_pill');

        $post_id = $atts['post_id'] ?: get_the_ID();
        if (!$post_id) {
            return '';
        }

        $post_type = get_post_type($post_id);
        $post_type_obj = get_post_type_object($post_type);

        if (!$post_type_obj) {
            return '';
        }

        $default_labels = self::get_default_labels();
        $label = isset($default_labels[$post_type])
            ? $default_labels[$post_type]
            : $post_type_obj->labels->singular_name;

        return sprintf(
            '<span class="%s" data-post-type="%s">%s</span>',
            esc_attr($atts['class']),
            esc_attr($post_type),
            esc_html($label)
        );
    }

    /**
     * Output default styles
     */
    public function output_styles() {
        ?>
        <style>
            .bp-post-type-pill {
                display: inline-block;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                line-height: 1.4;
                white-space: nowrap;
            }
            .post-type-pill {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                background-color: #0073aa;
                color: #ffffff;
            }
        </style>
        <?php
    }
}

// Initialize
Starter_Addon_Post_Type_Pill::instance();
