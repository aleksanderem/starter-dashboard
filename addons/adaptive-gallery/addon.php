<?php
/**
 * Starter Dashboard Addon: Adaptive Gallery
 *
 * Elementor widget - Gallery carousel that honors original image dimensions
 * and adjusts visible slides based on image orientation (landscape/portrait).
 */

defined('ABSPATH') || exit;

class Starter_Addon_Adaptive_Gallery {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Check if Elementor is active
        if (!did_action('elementor/loaded')) {
            return;
        }

        // Register widget
        add_action('elementor/widgets/register', [$this, 'register_widget']);

        // Register assets
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('elementor/frontend/after_enqueue_styles', [$this, 'register_assets']);
    }

    /**
     * Register Elementor widget
     */
    public function register_widget($widgets_manager) {
        require_once __DIR__ . '/widget.php';
        $widgets_manager->register(new \Starter_Adaptive_Gallery_Widget());
    }

    /**
     * Register CSS and JS assets
     */
    public function register_assets() {
        $addon_url = plugin_dir_url(__FILE__);
        $prefix = starter_hub_prefix();
        $handle = $prefix . '-adaptive-gallery';

        wp_register_style(
            $handle,
            $addon_url . 'style.css',
            [],
            '4.2.3' // Match plugin version for cache busting
        );

        wp_register_script(
            $handle,
            $addon_url . 'script.js',
            ['jquery'],
            '4.2.3', // Match plugin version for cache busting
            true
        );

        // Pass prefix to JS for Elementor hook
        wp_localize_script($handle, 'adaptiveGalleryConfig', [
            'widgetName' => $prefix . '_adaptive_gallery',
        ]);
    }
}

// Initialize
Starter_Addon_Adaptive_Gallery::instance();
