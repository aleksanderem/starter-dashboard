<?php
/**
 * Starter Dashboard Addon: Image Compare Labels
 *
 * Persistent before/after labels on Happy Addons Image Compare widget.
 * Requires: Happy Elementor Addons
 */

defined('ABSPATH') || exit;

class Starter_Addon_Image_Compare {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('elementor/frontend/after_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets() {
        $addon_url = plugin_dir_url(__FILE__);
        $prefix = starter_hub_prefix();
        $handle = $prefix . '-image-compare';

        wp_register_style(
            $handle,
            $addon_url . 'style.css',
            [],
            '1.0.0'
        );

        wp_register_script(
            $handle,
            $addon_url . 'script.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_style($handle);
        wp_enqueue_script($handle);
    }
}

Starter_Addon_Image_Compare::instance();
