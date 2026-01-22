<?php
/**
 * Starter Dashboard Addon Loader
 *
 * Manages loading and activation of dashboard addons
 */

defined('ABSPATH') || exit;

class Starter_Addon_Loader {

    private static $instance = null;

    /**
     * Registered addons
     */
    private $addons = [];

    /**
     * Active addons option name
     */
    private $option_name = 'starter_active_addons';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_addons();
        $this->load_active_addons();

        // AJAX handlers
        add_action('wp_ajax_starter_toggle_addon', [$this, 'ajax_toggle_addon']);
        add_action('wp_ajax_starter_get_addon_settings', [$this, 'ajax_get_addon_settings']);
        add_action('wp_ajax_starter_save_addon_settings', [$this, 'ajax_save_addon_settings']);
        add_action('wp_ajax_starter_get_addon_readme', [$this, 'ajax_get_addon_readme']);
    }

    /**
     * Register available addons
     */
    private function register_addons() {
        $addons_dir = dirname(__FILE__);

        // Post Type Pill
        $this->addons['post-type-pill'] = [
            'name' => __('Post Type Pill', 'starter-dashboard'),
            'description' => __('Elementor widget displaying styled pill/badge with post type name', 'starter-dashboard'),
            'icon' => 'tag-02',
            'category' => 'elementor',
            'file' => $addons_dir . '/post-type-pill/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // HubSpot Forms Integration
        $this->addons['hubspot-forms'] = [
            'name' => __('HubSpot Forms', 'starter-dashboard'),
            'description' => __('HubSpot form submission action for Elementor Pro Forms', 'starter-dashboard'),
            'icon' => 'message-02',
            'category' => 'integration',
            'file' => $addons_dir . '/hubspot-forms/addon.php',
            'has_settings' => true,
            'settings_callback' => 'Starter_Addon_HubSpot_Forms::render_settings',
            'version' => '1.0.0',
        ];

        // OG Image / Social Preview
        $this->addons['og-image'] = [
            'name' => __('Social Preview', 'starter-dashboard'),
            'description' => __('Open Graph, Twitter Card, and social preview meta tags', 'starter-dashboard'),
            'icon' => 'share-08',
            'category' => 'seo',
            'file' => $addons_dir . '/og-image/addon.php',
            'has_settings' => true,
            'settings_callback' => 'Starter_Addon_OG_Image::render_settings',
            'version' => '1.0.0',
        ];

        // Vertical Menu Shortcode
        $this->addons['vertical-menu'] = [
            'name' => __('Vertical Menu', 'starter-dashboard'),
            'description' => __('Shortcode for vertical accordion menus with Elementor integration', 'starter-dashboard'),
            'icon' => 'hamburger-01',
            'category' => 'ui',
            'file' => $addons_dir . '/vertical-menu/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // CSV Options for Elementor Forms
        $this->addons['elementor-csv-options'] = [
            'name' => __('Form CSV Options', 'starter-dashboard'),
            'description' => __('Import options from CSV files for Select, Radio, Checkbox fields in Elementor Forms', 'starter-dashboard'),
            'icon' => 'upload-05',
            'category' => 'elementor',
            'file' => $addons_dir . '/elementor-csv-options/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // International Phone Field for Elementor Forms
        $this->addons['elementor-phone-field'] = [
            'name' => __('Phone Field (International)', 'starter-dashboard'),
            'description' => __('International phone input with country selector, flags and dial codes for Elementor Forms', 'starter-dashboard'),
            'icon' => 'telephone',
            'category' => 'elementor',
            'file' => $addons_dir . '/elementor-phone-field/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // Styled Checkboxes for Elementor Forms
        $this->addons['elementor-styled-checkboxes'] = [
            'name' => __('Styled Checkboxes', 'starter-dashboard'),
            'description' => __('Beautiful styled checkboxes, radio buttons and toggle switches for Elementor Forms', 'starter-dashboard'),
            'icon' => 'tick-02',
            'category' => 'elementor',
            'file' => $addons_dir . '/elementor-styled-checkboxes/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // Reading Time Shortcode
        $this->addons['reading-time'] = [
            'name' => __('Reading Time', 'starter-dashboard'),
            'description' => __('Shortcode displaying estimated reading time for posts', 'starter-dashboard'),
            'icon' => 'clock-05',
            'category' => 'ui',
            'file' => $addons_dir . '/reading-time/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // Adaptive Gallery
        $this->addons['adaptive-gallery'] = [
            'name' => __('Adaptive Gallery', 'starter-dashboard'),
            'description' => __('Gallery carousel that adjusts visible slides based on image orientation', 'starter-dashboard'),
            'icon' => 'image-02',
            'category' => 'elementor',
            'file' => $addons_dir . '/adaptive-gallery/addon.php',
            'has_settings' => false,
            'version' => '1.0.0',
        ];

        // 301 Redirects
        $this->addons['redirects-301'] = [
            'name' => __('301 Redirects', 'starter-dashboard'),
            'description' => __('Manage 301 redirects with hit tracking and post list integration', 'starter-dashboard'),
            'icon' => 'link-backward',
            'category' => 'seo',
            'file' => $addons_dir . '/redirects-301/addon.php',
            'has_settings' => true,
            'settings_callback' => 'Starter_Addon_Redirects_301::render_settings',
            'version' => '1.0.0',
        ];
    }

    /**
     * Load active addons
     */
    private function load_active_addons() {
        $active = $this->get_active_addons();

        // Always load these addons (for development/testing)
        $always_load = ['elementor-phone-field', 'elementor-styled-checkboxes', 'elementor-csv-options'];
        foreach ($always_load as $addon_id) {
            if (!in_array($addon_id, $active)) {
                $active[] = $addon_id;
            }
        }

        foreach ($active as $addon_id) {
            if (isset($this->addons[$addon_id]) && file_exists($this->addons[$addon_id]['file'])) {
                require_once $this->addons[$addon_id]['file'];
            }
        }
    }

    /**
     * Get active addons
     */
    public function get_active_addons() {
        return get_option($this->option_name, []);
    }

    /**
     * Check if addon is active
     */
    public function is_addon_active($addon_id) {
        $active = $this->get_active_addons();
        return in_array($addon_id, $active);
    }

    /**
     * Activate addon
     */
    public function activate_addon($addon_id) {
        if (!isset($this->addons[$addon_id])) {
            return false;
        }

        $active = $this->get_active_addons();
        if (!in_array($addon_id, $active)) {
            $active[] = $addon_id;
            update_option($this->option_name, $active);
        }

        return true;
    }

    /**
     * Deactivate addon
     */
    public function deactivate_addon($addon_id) {
        $active = $this->get_active_addons();
        $active = array_diff($active, [$addon_id]);
        update_option($this->option_name, array_values($active));

        return true;
    }

    /**
     * Get all registered addons
     */
    public function get_addons() {
        return $this->addons;
    }

    /**
     * Get addon by ID
     */
    public function get_addon($addon_id) {
        return isset($this->addons[$addon_id]) ? $this->addons[$addon_id] : null;
    }

    /**
     * Get addons grouped by category
     */
    public function get_addons_by_category() {
        $categories = [
            'elementor' => [
                'label' => __('Elementor', 'starter-dashboard'),
                'icon' => 'magic-wand-03',
                'addons' => [],
            ],
            'integration' => [
                'label' => __('Integrations', 'starter-dashboard'),
                'icon' => 'puzzle',
                'addons' => [],
            ],
            'seo' => [
                'label' => __('SEO & Social', 'starter-dashboard'),
                'icon' => 'share-08',
                'addons' => [],
            ],
            'ui' => [
                'label' => __('UI Components', 'starter-dashboard'),
                'icon' => 'layout-grid',
                'addons' => [],
            ],
        ];

        foreach ($this->addons as $id => $addon) {
            $cat = $addon['category'];
            if (isset($categories[$cat])) {
                // Check if addon has README file
                $addon_dir = dirname($addon['file']);
                $addon['has_readme'] = file_exists($addon_dir . '/README.md');
                $categories[$cat]['addons'][$id] = $addon;
            }
        }

        return $categories;
    }

    /**
     * AJAX: Toggle addon activation
     */
    public function ajax_toggle_addon() {
        check_ajax_referer('starter_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')]);
        }

        $addon_id = isset($_POST['addon_id']) ? sanitize_text_field($_POST['addon_id']) : '';
        $activate = isset($_POST['activate']) ? (bool) $_POST['activate'] : false;

        if (empty($addon_id) || !isset($this->addons[$addon_id])) {
            wp_send_json_error(['message' => __('Invalid addon', 'starter-dashboard')]);
        }

        if ($activate) {
            $this->activate_addon($addon_id);
            wp_send_json_success([
                'message' => sprintf(__('%s activated', 'starter-dashboard'), $this->addons[$addon_id]['name']),
                'active' => true,
            ]);
        } else {
            $this->deactivate_addon($addon_id);
            wp_send_json_success([
                'message' => sprintf(__('%s deactivated', 'starter-dashboard'), $this->addons[$addon_id]['name']),
                'active' => false,
            ]);
        }
    }

    /**
     * AJAX: Get addon settings HTML
     */
    public function ajax_get_addon_settings() {
        check_ajax_referer('starter_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')]);
        }

        $addon_id = isset($_POST['addon_id']) ? sanitize_text_field($_POST['addon_id']) : '';

        if (empty($addon_id) || !isset($this->addons[$addon_id])) {
            wp_send_json_error(['message' => __('Invalid addon', 'starter-dashboard')]);
        }

        $addon = $this->addons[$addon_id];

        if (!$addon['has_settings'] || empty($addon['settings_callback'])) {
            wp_send_json_error(['message' => __('This addon has no settings', 'starter-dashboard')]);
        }

        // Ensure addon file is loaded so settings callback is available
        if (file_exists($addon['file'])) {
            require_once $addon['file'];
        }

        ob_start();
        if (is_callable($addon['settings_callback'])) {
            call_user_func($addon['settings_callback']);
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Save addon settings
     */
    public function ajax_save_addon_settings() {
        // Accept nonce from either dashboard or settings page
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        $valid_nonce = wp_verify_nonce($nonce, 'starter_settings_nonce') ||
                       wp_verify_nonce($nonce, 'starter_dashboard_nonce');

        if (!$valid_nonce) {
            wp_send_json_error(['message' => __('Security check failed', 'starter-dashboard')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')]);
        }

        $addon_id = isset($_POST['addon_id']) ? sanitize_text_field($_POST['addon_id']) : '';
        $settings = isset($_POST['settings']) ? $_POST['settings'] : [];

        if (empty($addon_id) || !isset($this->addons[$addon_id])) {
            wp_send_json_error(['message' => __('Invalid addon', 'starter-dashboard')]);
        }

        // Ensure addon file is loaded so filter handlers are registered
        $addon = $this->addons[$addon_id];
        if (file_exists($addon['file'])) {
            require_once $addon['file'];
        }

        // Each addon handles its own settings saving via filter
        $saved = apply_filters("starter_addon_save_settings_{$addon_id}", false, $settings);

        if ($saved) {
            wp_send_json_success(['message' => __('Settings saved', 'starter-dashboard')]);
        } else {
            wp_send_json_error(['message' => __('Failed to save settings', 'starter-dashboard')]);
        }
    }

    /**
     * AJAX: Get addon README content
     */
    public function ajax_get_addon_readme() {
        // Verify nonce
        if (!check_ajax_referer('starter_settings_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'starter-dashboard')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')]);
            return;
        }

        $addon_id = isset($_POST['addon_id']) ? sanitize_text_field($_POST['addon_id']) : '';

        if (empty($addon_id)) {
            wp_send_json_error(['message' => __('No addon ID provided', 'starter-dashboard')]);
            return;
        }

        if (!isset($this->addons[$addon_id])) {
            wp_send_json_error(['message' => sprintf(__('Invalid addon: %s', 'starter-dashboard'), $addon_id)]);
            return;
        }

        $addon = $this->addons[$addon_id];
        $addon_dir = dirname($addon['file']);
        $readme_path = $addon_dir . '/README.md';

        if (!file_exists($readme_path)) {
            wp_send_json_error(['message' => sprintf(__('README not found at: %s', 'starter-dashboard'), $readme_path)]);
            return;
        }

        $content = file_get_contents($readme_path);

        if ($content === false) {
            wp_send_json_error(['message' => __('Could not read README file', 'starter-dashboard')]);
            return;
        }

        // Convert markdown to HTML
        $html = $this->markdown_to_html($content);

        wp_send_json_success([
            'title' => $addon['name'],
            'content' => $html,
        ]);
    }

    /**
     * Convert markdown to HTML using Parsedown
     */
    private function markdown_to_html($markdown) {
        try {
            // Load Parsedown if not already loaded
            if (!class_exists('Parsedown')) {
                $parsedown_path = dirname(dirname(__FILE__)) . '/lib/Parsedown.php';
                if (!file_exists($parsedown_path)) {
                    return '<p>Parsedown library not found</p>';
                }
                require_once $parsedown_path;
            }

            $parsedown = new Parsedown();

            // setSafeMode() might not exist in older Parsedown versions
            // loaded by other plugins, so check before calling
            if (method_exists($parsedown, 'setSafeMode')) {
                $parsedown->setSafeMode(true);
            }

            return $parsedown->text($markdown);
        } catch (Exception $e) {
            return '<p>Error parsing markdown: ' . esc_html($e->getMessage()) . '</p>';
        }
    }
}

// Initialize
function starter_addon_loader() {
    return Starter_Addon_Loader::instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'starter_addon_loader');
