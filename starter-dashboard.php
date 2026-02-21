<?php
/**
 * Plugin Name: Starter Dashboard
 * Description: Custom admin dashboard with post type tiles, menu visibility control, role editor, CPT management, and addon system
 * Version: 4.3.6
 * Author: Alex M.
 * Author URI: https://developer.dev
 */

defined('ABSPATH') || exit;

/**
 * Hub prefix for widgets, scripts, and other namespaced elements.
 * Change this to match your project (e.g., 'buspatrol', 'starter', 'mysite').
 * Addons will use this prefix for Elementor widget names, script handles, etc.
 */
if (!defined('STARTER_HUB_PREFIX')) {
    define('STARTER_HUB_PREFIX', 'buspatrol');
}

/**
 * Get the Hub prefix for use in addons
 *
 * @return string The configured prefix
 */
function starter_hub_prefix() {
    return STARTER_HUB_PREFIX;
}

// Load addon system
require_once __DIR__ . '/addons/class-addon-loader.php';

// Load plugin update checker for GitHub releases
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Initialize GitHub update checker
 *
 * To set up updates:
 * 1. Create a GitHub repository for this plugin
 * 2. Replace 'YOUR_GITHUB_USERNAME/YOUR_REPO_NAME' below with your repo
 * 3. Create releases on GitHub with tag matching version (e.g., v4.0.0 or 4.0.0)
 * 4. Attach the plugin ZIP file to each release
 */
if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
    $starter_dashboard_update_checker = PucFactory::buildUpdateChecker(
        'https://github.com/aleksanderem/starter-dashboard/',
        __FILE__,
        'starter-dashboard'
    );

    // Set the branch that contains the stable release (usually 'main' or 'master')
    $starter_dashboard_update_checker->setBranch('main');

    // Optional: If your repo is private, add authentication
    // $starter_dashboard_update_checker->setAuthentication('your-github-token');

    // Optional: Enable release assets (attach ZIP to GitHub release)
    $starter_dashboard_update_checker->getVcsApi()->enableReleaseAssets();
}

// Load textdomain early to avoid WP 6.7+ notice
add_action('init', function() {
    load_plugin_textdomain('starter-dashboard', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);

class Starter_Dashboard {

    private static $instance = null;
    private $dashboard_hook = '';
    private $settings_hook = '';
    private $addons_hook = '';
    private $import_export_hook = '';
    private $whitelabel_hook = '';

    /**
     * Original menu before restrictions applied (for Additional Elements)
     */
    private $original_menu = [];
    private $original_submenu = [];

    /**
     * Post type categories for tabs
     */
    private $content_types = ['post', 'page'];
    private $builder_types = ['elementor_library', 'e-landing-page'];

    /**
     * Color mapping for post types (using Brand colors)
     */
    private $type_colors = [
        'post'             => '#2ABADE',
        'page'             => '#2ABADE',
        'elementor_library'=> '#0097CD',
        'e-landing-page'   => '#253E89',
    ];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_menu', [$this, 'store_original_menu'], 9998); // Before restrictions
        add_action('admin_menu', [$this, 'apply_menu_restrictions'], 9999);
        add_action('admin_menu', [$this, 'apply_menu_order'], 10000); // After all menu changes
        add_action('wp_ajax_starter_save_tile_order', [$this, 'ajax_save_tile_order']);
        add_action('wp_ajax_starter_save_additional_elements_order', [$this, 'ajax_save_additional_elements_order']);
        add_action('wp_ajax_starter_save_admin_menu_order', [$this, 'ajax_save_admin_menu_order']);
        add_action('wp_ajax_starter_reset_admin_menu_order', [$this, 'ajax_reset_admin_menu_order']);
        add_action('wp_ajax_starter_save_menu_settings', [$this, 'ajax_save_menu_settings']);
        add_action('wp_ajax_starter_save_role_caps', [$this, 'ajax_save_role_caps']);
        add_action('wp_ajax_starter_create_role', [$this, 'ajax_create_role']);
        add_action('wp_ajax_starter_delete_role', [$this, 'ajax_delete_role']);
        add_action('wp_ajax_starter_save_cpt_visibility', [$this, 'ajax_save_cpt_visibility']);
        add_action('wp_ajax_starter_get_modal_items', [$this, 'ajax_get_modal_items']);
        add_action('wp_ajax_starter_save_additional_users', [$this, 'ajax_save_additional_users']);
        add_action('wp_ajax_starter_save_visual_users', [$this, 'ajax_save_visual_users']);
        add_action('wp_ajax_starter_save_visual_settings', [$this, 'ajax_save_visual_settings']);
        add_action('wp_ajax_starter_get_recent_activity', [$this, 'ajax_get_recent_activity']);
        add_action('wp_ajax_starter_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_starter_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_starter_save_custom_action', [$this, 'ajax_save_custom_action']);
        add_action('wp_ajax_starter_remove_custom_action', [$this, 'ajax_remove_custom_action']);
        add_action('wp_ajax_starter_save_whitelabel', [$this, 'ajax_save_whitelabel']);
        add_filter('all_plugins', [$this, 'filter_plugin_metadata']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'redirect_default_dashboard']);
        add_action('admin_head', [$this, 'maybe_hide_admin_sidebar']);
        add_action('admin_head', [$this, 'add_ezicons_sdk']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_command_menu']);
        add_filter('script_loader_tag', [$this, 'add_module_type_to_command_menu'], 10, 3);

        // Disable native WP command palette to avoid conflict with our command menu
        add_action('admin_init', function() {
            remove_action('enqueue_block_editor_assets', 'wp_enqueue_command_palette_assets');
            remove_action('admin_enqueue_scripts', 'wp_enqueue_command_palette_assets');
        }, 999);

        // Hide native WP command palette via CSS
        add_action('admin_head', function() {
            echo '<style>.commands-command-menu, .interface-command-palette { display: none !important; }</style>';
        });

        // Add keyboard shortcut indicator at bottom
        add_action('admin_footer', function() {
            ?>
            <style>
                @keyframes hint-fade-in {
                    from { opacity: 0; transform: translateY(10px) scale(0.95); }
                    to { opacity: 1; transform: translateY(0) scale(1); }
                }
                @keyframes hint-fade-out {
                    from { opacity: 1; transform: translateY(0) scale(1); }
                    to { opacity: 0; transform: translateY(10px) scale(0.95); }
                }
                #wp-command-menu-hint {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    z-index: 9999;
                    cursor: pointer;
                    background: #fff;
                    border: 1px solid #e5e7eb;
                    border-radius: 10px;
                    padding: 8px 12px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 13px;
                    color: #6b7280;
                    font-weight: 500;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                    transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
                    animation: hint-fade-in 0.2s ease-out;
                }
                #wp-command-menu-hint.hiding {
                    animation: hint-fade-out 0.2s ease-out forwards;
                    pointer-events: none;
                }
                #wp-command-menu-hint:hover {
                    background: #f9fafb;
                    border-color: #d1d5db;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
                }
                #wp-command-menu-hint svg {
                    width: 16px;
                    height: 16px;
                    color: #9ca3af;
                }
                #wp-command-menu-hint kbd {
                    background: linear-gradient(180deg, #f9fafb 0%, #e5e7eb 100%);
                    border: 1px solid #d1d5db;
                    border-radius: 5px;
                    padding: 3px 6px;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    font-size: 11px;
                    font-weight: 600;
                    color: #374151;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                }
                #wp-command-menu-hint .plus {
                    font-size: 11px;
                    color: #9ca3af;
                }
            </style>
            <div id="wp-command-menu-hint" onclick="document.dispatchEvent(new KeyboardEvent('keydown', {key: 'k', metaKey: true}));">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <kbd>⌘</kbd>
                <span class="plus">+</span>
                <kbd>K</kbd>
            </div>
            <script>
                (function() {
                    const hint = document.getElementById('wp-command-menu-hint');

                    // Listen for custom event from the React command menu
                    window.addEventListener('wp-command-menu-toggle', function(e) {
                        if (e.detail.isOpen) {
                            hint.classList.add('hiding');
                        } else {
                            hint.classList.remove('hiding');
                        }
                    });
                })();
            </script>
            <?php
        });

        // Content Version metabox
        add_action('add_meta_boxes', [$this, 'add_content_version_metabox']);
        add_action('save_post', [$this, 'save_content_version_metabox']);

        // One-time migration: mark Alex's content as Version 2
        add_action('admin_init', [$this, 'migrate_alex_content_to_version2']);

        // One-time migration: mark Case Studies and Press Releases as Version 2
        add_action('admin_init', [$this, 'migrate_cpt_content_to_version2']);

        // One-time migration: mark ALL public posts (pages, posts, etc.) as Version 2
        // DISABLED - may cause issues, need to debug
        // add_action('admin_init', [$this, 'migrate_all_public_posts_to_version2']);

        // Filter posts list by content version
        add_action('pre_get_posts', [$this, 'filter_posts_by_content_version']);

        // Add Version filter dropdown
        add_action('restrict_manage_posts', [$this, 'add_version_filter_dropdown']);

        // Add filter for all public post types
        add_action('init', [$this, 'register_version_filters_for_all_cpts'], 999);

        // Bulk actions for Version 2
        add_action('init', [$this, 'register_bulk_actions_for_all_cpts'], 999);

        // Set default Version 2 for new posts
        add_action('wp_insert_post', [$this, 'set_default_content_version'], 10, 3);

        // Admin notice for bulk version update
        add_action('admin_notices', [$this, 'bulk_version_admin_notice']);
    }

    /**
     * Add dashboard page to admin menu
     */
    public function add_menu_pages() {
        // Get dynamic hub name from settings
        $visual_settings = $this->get_visual_settings();
        $hub_name = esc_html($visual_settings['hub_name']);

        $this->dashboard_hook = add_menu_page(
            $hub_name,
            $hub_name,
            'read',
            'starter-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-dashboard',
            2
        );

        $this->settings_hook = add_submenu_page(
            'starter-dashboard',
            __('Dashboard Settings', 'starter-dashboard'),
            __('Settings', 'starter-dashboard'),
            'manage_options',
            'starter-dashboard-settings',
            [$this, 'render_settings_page']
        );

        // Addons page
        $this->addons_hook = add_submenu_page(
            'starter-dashboard',
            __('Addons', 'starter-dashboard'),
            __('Addons', 'starter-dashboard'),
            'manage_options',
            'starter-addons',
            [$this, 'render_addons_page']
        );

        // Import/Export page
        $this->import_export_hook = add_submenu_page(
            'starter-dashboard',
            __('Import/Export', 'starter-dashboard'),
            __('Import/Export', 'starter-dashboard'),
            'manage_options',
            'starter-import-export',
            [$this, 'render_import_export_page']
        );

        // Whitelabel page - hidden unless ?whitelabel=1 in URL or already on that page
        $show_whitelabel = isset($_GET['whitelabel']) || (isset($_GET['page']) && $_GET['page'] === 'starter-whitelabel');
        $this->whitelabel_hook = add_submenu_page(
            $show_whitelabel ? 'starter-dashboard' : null, // null = hidden from menu
            __('Whitelabel', 'starter-dashboard'),
            __('Whitelabel', 'starter-dashboard'),
            'manage_options',
            'starter-whitelabel',
            [$this, 'render_whitelabel_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        $plugin_url = plugin_dir_url(__FILE__);

        // Dashboard page scripts
        if ($hook === $this->dashboard_hook) {
            // Media library for addon image settings
            wp_enqueue_media();

            wp_enqueue_style(
                'starter-dashboard-css',
                $plugin_url . 'dashboard.css',
                [],
                '3.9.2'
            );

            wp_enqueue_script(
                'starter-sortable',
                $plugin_url . 'sortable.min.js',
                [],
                '1.15.0',
                true
            );

            wp_enqueue_script(
                'starter-dashboard-js',
                $plugin_url . 'dashboard.js',
                ['jquery', 'starter-sortable'],
                '3.9.2',
                true
            );

            $saved_order = get_user_meta(get_current_user_id(), 'starter_dashboard_tile_order', true);

            wp_localize_script('starter-dashboard-js', 'starterDashboard', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('starter_dashboard_nonce'),
                'order'   => is_array($saved_order) ? $saved_order : [],
                'strings' => [
                    'orderSaved' => __('Tile order saved', 'starter-dashboard'),
                    'error'      => __('An error occurred', 'starter-dashboard'),
                ],
            ]);
        }

        // Settings page scripts
        if ($hook === $this->settings_hook || $hook === $this->addons_hook || $hook === $this->import_export_hook || $hook === $this->whitelabel_hook) {
            // Media library for logo picker
            wp_enqueue_media();

            // Select2 for nice multi-selects (local copy - CDN was blocked on some servers)
            wp_enqueue_style(
                'select2-css',
                $plugin_url . 'lib/select2/select2.min.css',
                [],
                '4.1.0'
            );

            wp_enqueue_script(
                'select2-js',
                $plugin_url . 'lib/select2/select2.min.js',
                ['jquery'],
                '4.1.0',
                true
            );

            wp_enqueue_style(
                'starter-settings-css',
                $plugin_url . 'settings.css',
                ['select2-css'],
                '3.9.2'
            );

            // SortableJS for menu order
            wp_enqueue_script(
                'starter-sortable',
                $plugin_url . 'sortable.min.js',
                [],
                '1.15.0',
                true
            );

            wp_enqueue_script(
                'starter-settings-js',
                $plugin_url . 'settings.js',
                ['jquery', 'select2-js', 'starter-sortable'],
                '3.9.2',
                true
            );

            wp_localize_script('starter-settings-js', 'starterSettings', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('starter_settings_nonce'),
                'strings' => [
                    'saved'  => __('Settings saved', 'starter-dashboard'),
                    'error'  => __('An error occurred', 'starter-dashboard'),
                    'saving' => __('Saving...', 'starter-dashboard'),
                ],
            ]);
        }
    }

    /**
     * Enqueue command menu (Cmd+K) globally on all admin pages
     */
    public function enqueue_command_menu() {
        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_dir = plugin_dir_path(__FILE__);

        // Check if command menu build exists
        $js_file = $plugin_dir . 'command-menu/dist/wp-command-menu.js';
        $css_file = $plugin_dir . 'command-menu/dist/assets/wp-command-menu.css';

        if (!file_exists($js_file)) {
            return;
        }

        // Enqueue styles
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'wp-command-menu-css',
                $plugin_url . 'command-menu/dist/assets/wp-command-menu.css',
                [],
                filemtime($css_file)
            );
        }

        // Enqueue custom styles (not processed by Tailwind)
        $custom_css_file = $plugin_dir . 'command-menu/dist/wp-command-menu-custom.css';
        if (file_exists($custom_css_file)) {
            wp_enqueue_style(
                'wp-command-menu-custom-css',
                $plugin_url . 'command-menu/dist/wp-command-menu-custom.css',
                ['wp-command-menu-css'],
                filemtime($custom_css_file)
            );
        }

        // Enqueue script
        wp_enqueue_script(
            'wp-command-menu-js',
            $plugin_url . 'command-menu/dist/wp-command-menu.js',
            [],
            filemtime($js_file),
            true
        );

        // Prepare data for command menu
        global $menu, $submenu;

        // Build menu items
        $menu_items = [];
        foreach ($menu as $item) {
            if (empty($item[0]) || empty($item[2])) continue;
            if (strpos($item[4] ?? '', 'wp-menu-separator') !== false) continue;

            $label = wp_strip_all_tags($item[0]);
            $label = preg_replace('/\s*\d+\s*$/', '', $label);
            $slug = $item[2];
            $icon = $item[6] ?? 'dashicons-admin-generic';

            if (strpos($slug, '.php') !== false) {
                $url = admin_url($slug);
            } else {
                $url = admin_url('admin.php?page=' . $slug);
            }

            $menu_items[] = [
                'id'    => sanitize_title($slug),
                'label' => $label,
                'url'   => $url,
                'icon'  => $icon,
            ];

            // Add submenu items
            if (!empty($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub) {
                    if (empty($sub[0]) || empty($sub[2])) continue;

                    $sub_label = wp_strip_all_tags($sub[0]);
                    $sub_label = preg_replace('/\s*\d+\s*$/', '', $sub_label);
                    $sub_slug = $sub[2];

                    if (strpos($sub_slug, '.php') !== false) {
                        $sub_url = admin_url($sub_slug);
                    } elseif (strpos($slug, '.php') !== false) {
                        $sub_url = admin_url($slug . '?page=' . $sub_slug);
                    } else {
                        $sub_url = admin_url('admin.php?page=' . $sub_slug);
                    }

                    $menu_items[] = [
                        'id'     => sanitize_title($sub_slug),
                        'label'  => $label . ' → ' . $sub_label,
                        'url'    => $sub_url,
                        'icon'   => $icon,
                        'parent' => sanitize_title($slug),
                    ];
                }
            }
        }

        // Get recent posts
        $recent_posts = get_posts([
            'post_type'      => ['post', 'page'],
            'posts_per_page' => 10,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'post_status'    => ['publish', 'draft', 'pending'],
        ]);

        $recent_items = [];
        foreach ($recent_posts as $post) {
            $recent_items[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'editUrl' => get_edit_post_link($post->ID, 'raw'),
                'type'    => get_post_type_object($post->post_type)->labels->singular_name,
            ];
        }

        // Quick actions
        $quick_actions = [
            ['id' => 'new-post', 'label' => 'New Post', 'url' => admin_url('post-new.php'), 'icon' => 'add-01'],
            ['id' => 'new-page', 'label' => 'New Page', 'url' => admin_url('post-new.php?post_type=page'), 'icon' => 'add-01'],
            ['id' => 'upload-media', 'label' => 'Upload Media', 'url' => admin_url('media-new.php'), 'icon' => 'upload-01'],
            ['id' => 'new-user', 'label' => 'Add New User', 'url' => admin_url('user-new.php'), 'icon' => 'add-01'],
        ];

        // Add custom post types to quick actions
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $cpt) {
            if ($cpt->show_in_menu) {
                $quick_actions[] = [
                    'id' => 'new-' . $cpt->name,
                    'label' => 'New ' . $cpt->labels->singular_name,
                    'url' => admin_url('post-new.php?post_type=' . $cpt->name),
                    'icon' => 'add-01',
                ];
            }
        }

        // Site actions
        $site_actions = [
            ['id' => 'view-site', 'label' => 'View Site', 'url' => home_url(), 'icon' => 'globe-01'],
            ['id' => 'customize', 'label' => 'Customize Theme', 'url' => admin_url('customize.php'), 'icon' => 'palette-01'],
            ['id' => 'widgets', 'label' => 'Widgets', 'url' => admin_url('widgets.php'), 'icon' => 'layout-01'],
            ['id' => 'menus', 'label' => 'Menus', 'url' => admin_url('nav-menus.php'), 'icon' => 'menu-01'],
        ];

        // User actions
        $user_actions = [
            ['id' => 'profile', 'label' => 'Edit Profile', 'url' => admin_url('profile.php'), 'icon' => 'user-01'],
            ['id' => 'logout', 'label' => 'Log Out', 'url' => wp_logout_url(), 'icon' => 'log-out-01'],
        ];

        // Settings shortcuts
        $settings_actions = [
            ['id' => 'settings-general', 'label' => 'General Settings', 'url' => admin_url('options-general.php'), 'icon' => 'settings-01'],
            ['id' => 'settings-writing', 'label' => 'Writing Settings', 'url' => admin_url('options-writing.php'), 'icon' => 'edit-01'],
            ['id' => 'settings-reading', 'label' => 'Reading Settings', 'url' => admin_url('options-reading.php'), 'icon' => 'book-01'],
            ['id' => 'settings-permalinks', 'label' => 'Permalinks', 'url' => admin_url('options-permalink.php'), 'icon' => 'link-01'],
            ['id' => 'settings-privacy', 'label' => 'Privacy Settings', 'url' => admin_url('options-privacy.php'), 'icon' => 'shield-01'],
        ];

        // Tools
        $tools_actions = [
            ['id' => 'site-health', 'label' => 'Site Health', 'url' => admin_url('site-health.php'), 'icon' => 'heart-01'],
            ['id' => 'export', 'label' => 'Export Content', 'url' => admin_url('export.php'), 'icon' => 'download-01'],
            ['id' => 'import', 'label' => 'Import Content', 'url' => admin_url('import.php'), 'icon' => 'upload-01'],
        ];

        // Hub/Addon actions
        $hub_actions = [
            ['id' => 'hub-dashboard', 'label' => 'Hub Dashboard', 'url' => admin_url('admin.php?page=starter-dashboard'), 'icon' => 'layout-01'],
            ['id' => 'hub-settings', 'label' => 'Hub Settings', 'url' => admin_url('admin.php?page=starter-dashboard-settings'), 'icon' => 'settings-01'],
            ['id' => 'hub-addons', 'label' => 'Manage Addons', 'url' => admin_url('admin.php?page=starter-dashboard-addons'), 'icon' => 'package-01'],
        ];

        // Add addon-specific actions if addons are active
        if (function_exists('starter_addon_loader')) {
            $addon_loader = starter_addon_loader();

            // 301 Redirects addon
            if ($addon_loader->is_addon_active('redirects-301')) {
                $hub_actions[] = ['id' => 'redirects-manage', 'label' => '301 Redirects', 'url' => admin_url('admin.php?page=starter-dashboard#addon-redirects-301'), 'icon' => 'link-01'];
            }

            // Social Preview / OG Image addon
            if ($addon_loader->is_addon_active('og-image')) {
                $hub_actions[] = ['id' => 'og-image', 'label' => 'Social Preview Settings', 'url' => admin_url('admin.php?page=starter-dashboard#addon-og-image'), 'icon' => 'globe-01'];
            }

            // HubSpot Forms addon
            if ($addon_loader->is_addon_active('hubspot-forms')) {
                $hub_actions[] = ['id' => 'hubspot-forms', 'label' => 'HubSpot Forms', 'url' => admin_url('admin.php?page=starter-dashboard#addon-hubspot-forms'), 'icon' => 'edit-01'];
            }
        }

        // WooCommerce actions (if active)
        $woo_actions = [];
        if (class_exists('WooCommerce')) {
            $woo_actions = [
                ['id' => 'woo-orders', 'label' => 'WooCommerce Orders', 'url' => admin_url('edit.php?post_type=shop_order'), 'icon' => 'shopping-cart-01'],
                ['id' => 'woo-products', 'label' => 'All Products', 'url' => admin_url('edit.php?post_type=product'), 'icon' => 'package-01'],
                ['id' => 'woo-new-product', 'label' => 'Add New Product', 'url' => admin_url('post-new.php?post_type=product'), 'icon' => 'add-01'],
                ['id' => 'woo-coupons', 'label' => 'Coupons', 'url' => admin_url('edit.php?post_type=shop_coupon'), 'icon' => 'tag-01'],
            ];
        }

        // Pass data to JS
        wp_localize_script('wp-command-menu-js', 'wpCommandMenuConfig', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('wp_command_menu'),
            'menuItems'     => $menu_items,
            'recentPosts'   => $recent_items,
            'quickActions'  => $quick_actions,
            'siteActions'   => $site_actions,
            'userActions'   => $user_actions,
            'settingsActions' => $settings_actions,
            'toolsActions'  => $tools_actions,
            'hubActions'    => $hub_actions,
            'wooActions'    => $woo_actions,
        ]);
    }

    /**
     * Add type="module" to command menu script
     */
    public function add_module_type_to_command_menu($tag, $handle, $src) {
        if ($handle === 'wp-command-menu-js') {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }
        return $tag;
    }

    /**
     * Store original menu before restrictions are applied
     */
    public function store_original_menu() {
        global $menu, $submenu;
        $this->original_menu = $menu;
        $this->original_submenu = $submenu;
    }

    /**
     * Apply menu restrictions based on saved settings
     */
    public function apply_menu_restrictions() {
        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        $user_roles = $user->roles;

        // Get hidden menu items for user's roles
        $hidden_menus = get_option('starter_hidden_menus', []);

        if (empty($hidden_menus) || !is_array($hidden_menus)) {
            return;
        }

        global $menu, $submenu;

        foreach ($user_roles as $role) {
            if (isset($hidden_menus[$role]) && is_array($hidden_menus[$role])) {
                foreach ($hidden_menus[$role] as $menu_slug) {
                    // Check if this is a submenu (contains || separator)
                    if (strpos($menu_slug, '||') !== false) {
                        // Submenu format: parent_slug||submenu_slug
                        $parts = explode('||', $menu_slug, 2);
                        $parent_slug = $parts[0];
                        $submenu_slug = $parts[1];
                        remove_submenu_page($parent_slug, $submenu_slug);
                    } else {
                        // Main menu item
                        remove_menu_page($menu_slug);
                    }
                }
            }
        }
    }

    /**
     * Apply custom menu order
     */
    public function apply_menu_order() {
        global $menu;

        $saved_order = get_option('starter_admin_menu_order', []);

        if (empty($saved_order) || !is_array($saved_order)) {
            return;
        }

        // Build a map of slug => menu item
        $menu_by_slug = [];
        $separators = [];
        $starter_hub = null;

        foreach ($menu as $position => $item) {
            if (!is_array($item) || count($item) < 3) {
                // Separator
                $separators[] = $item;
                continue;
            }
            $slug = $item[2];

            // Always keep the Hub separate - it goes first
            if ($slug === 'starter-dashboard') {
                $starter_hub = $item;
                continue;
            }

            $menu_by_slug[$slug] = $item;
        }

        // Rebuild menu in saved order
        $new_menu = [];
        $position = 0;

        // Hub always first
        if ($starter_hub) {
            $new_menu[$position] = $starter_hub;
            $position += 1;
        }

        foreach ($saved_order as $slug) {
            // Skip Hub in saved order - already added
            if ($slug === 'starter-dashboard') {
                continue;
            }

            if (isset($menu_by_slug[$slug])) {
                $new_menu[$position] = $menu_by_slug[$slug];
                unset($menu_by_slug[$slug]);
                $position += 1;
            }
        }

        // Add remaining items not in saved order
        foreach ($menu_by_slug as $slug => $item) {
            $new_menu[$position] = $item;
            $position += 1;
        }

        // Add separators at the end (or you could intersperse them)
        foreach ($separators as $sep) {
            $new_menu[$position] = $sep;
            $position += 1;
        }

        $menu = $new_menu;
    }

    /**
     * Get all admin menu items for settings
     */
    private function get_all_menu_items() {
        global $menu, $submenu;

        // Use original menu (captured before restrictions) so hidden items remain visible in settings
        $menu_to_scan = !empty($this->original_menu) ? $this->original_menu : $menu;
        $submenu_to_scan = !empty($this->original_submenu) ? $this->original_submenu : $submenu;

        $menu_items = [];

        // Ensure $menu is available
        if (empty($menu_to_scan) || !is_array($menu_to_scan)) {
            return $menu_items;
        }

        foreach ($menu_to_scan as $position => $item) {
            // Skip if not a valid menu item array
            if (!is_array($item) || count($item) < 3) {
                continue;
            }

            // Get the raw title - $item[0] may contain HTML with spans for notification counts
            $raw_title = isset($item[0]) ? $item[0] : '';

            // Skip separators (empty titles or titles that are just whitespace)
            if (empty($raw_title) || trim($raw_title) === '' || $raw_title === 'separator1' || $raw_title === 'separator2' || $raw_title === 'separator-last') {
                continue;
            }

            // Strip HTML tags to get clean title
            $title = strip_tags($raw_title);
            $title = trim($title);

            // Skip if title is still empty after stripping
            if (empty($title)) {
                continue;
            }

            $slug = isset($item[2]) ? $item[2] : '';

            // Skip separators by slug
            if (empty($slug) || strpos($slug, 'separator') !== false) {
                continue;
            }

            // Skip our own plugin pages
            if (strpos($slug, 'starter-') === 0) {
                continue;
            }

            // Get icon - could be dashicons class, base64 SVG, or 'none'
            $icon = isset($item[6]) ? $item[6] : '';

            // Normalize icon - only use dashicons classes
            if (empty($icon) || $icon === 'none' || $icon === 'div' || strpos($icon, 'dashicons-') !== 0) {
                // Try to guess icon based on slug
                $icon = $this->get_menu_icon_for_slug($slug);
            }

            $menu_items[] = [
                'title'    => $title,
                'slug'     => $slug,
                'icon'     => $icon,
                'position' => $position,
                'children' => isset($submenu_to_scan[$slug]) ? $this->format_submenu($submenu_to_scan[$slug]) : [],
            ];
        }

        // Sort by position
        usort($menu_items, function($a, $b) {
            return $a['position'] - $b['position'];
        });

        return $menu_items;
    }

    /**
     * Get default icon for menu slug
     */
    private function get_menu_icon_for_slug($slug) {
        $icon_map = [
            'index.php'              => 'dashicons-dashboard',
            'edit.php'               => 'dashicons-admin-post',
            'upload.php'             => 'dashicons-admin-media',
            'edit.php?post_type=page'=> 'dashicons-admin-page',
            'edit-comments.php'      => 'dashicons-admin-comments',
            'themes.php'             => 'dashicons-admin-appearance',
            'plugins.php'            => 'dashicons-admin-plugins',
            'users.php'              => 'dashicons-admin-users',
            'tools.php'              => 'dashicons-admin-tools',
            'options-general.php'    => 'dashicons-admin-settings',
            'woocommerce'            => 'dashicons-cart',
            'elementor'              => 'dashicons-admin-customizer',
            'edit.php?post_type=elementor_library' => 'dashicons-admin-customizer',
            'edit.php?post_type=acf-field-group' => 'dashicons-database',
            'acf-options'            => 'dashicons-database',
        ];

        // Check exact match
        if (isset($icon_map[$slug])) {
            return $icon_map[$slug];
        }

        // Check partial match
        foreach ($icon_map as $key => $icon) {
            if (strpos($slug, $key) !== false) {
                return $icon;
            }
        }

        return 'dashicons-admin-generic';
    }

    /**
     * Format submenu items
     */
    private function format_submenu($submenu_items) {
        $formatted = [];
        foreach ($submenu_items as $item) {
            $formatted[] = [
                'title' => wp_strip_all_tags($item[0]),
                'slug'  => $item[2],
            ];
        }
        return $formatted;
    }

    /**
     * Render dashboard page content
     */
    public function render_dashboard_page() {
        if (!$this->user_has_dashboard_access()) {
            wp_safe_redirect(admin_url('index.php'));
            exit;
        }

        $user = wp_get_current_user();
        $all_types = $this->get_post_types_for_tiles();
        $stats = $this->get_dashboard_stats($all_types);
        $quick_actions = $this->get_quick_actions();
        $recent_posts = $this->get_recent_posts($all_types);

        // Categorize post types
        $content_types = [];
        $builder_types = [];
        $other_types = [];

        foreach ($all_types as $tile) {
            if (in_array($tile['name'], $this->content_types)) {
                $content_types[] = $tile;
            } elseif (in_array($tile['name'], $this->builder_types)) {
                $builder_types[] = $tile;
            } else {
                $other_types[] = $tile;
            }
        }

        $saved_order = get_user_meta(get_current_user_id(), 'starter_dashboard_tile_order', true);
        if (!empty($saved_order) && is_array($saved_order)) {
            $content_types = $this->sort_tiles_by_order($content_types, $saved_order);
            $builder_types = $this->sort_tiles_by_order($builder_types, $saved_order);
            $other_types = $this->sort_tiles_by_order($other_types, $saved_order);
        }

        // Check if current user has access to Additional Elements
        $additional_elements = [];
        $allowed_users = get_option('starter_additional_elements_users', []);
        if (in_array(get_current_user_id(), (array) $allowed_users)) {
            $additional_elements = $this->get_additional_elements_for_user();
        }

        // Get visual settings
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="wrap starter-dashboard">
            <h1><?php echo esc_html($visual_settings['hub_name']); ?></h1>

            <div class="bp-dashboard__header" style="--header-primary: <?php echo esc_attr($visual_settings['primary_color']); ?>; --header-secondary: <?php echo esc_attr($visual_settings['secondary_color']); ?>; --header-accent: <?php echo esc_attr($visual_settings['accent_color']); ?>;">
                <div class="bp-dashboard__logo">
                    <img src="<?php echo esc_url($visual_settings['logo_url']); ?>" alt="<?php echo esc_attr($visual_settings['hub_name']); ?>" />
                    <div class="bp-dashboard__greeting">
                        <h2><?php printf(__('Welcome, %s', 'starter-dashboard'), esc_html($user->display_name)); ?></h2>
                    </div>
                </div>
                <div class="bp-dashboard__header-actions">
                    <?php if (current_user_can('manage_options')): ?>
                    <a href="<?php echo admin_url('admin.php?page=starter-dashboard-settings'); ?>" class="bp-dashboard__settings-btn">
                        <easier-icon name="settings-05" variant="twotone" size="20" color="currentColor"></easier-icon>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($visual_settings['show_stats'])): ?>
            <div class="bp-dashboard__stats">
                <div class="bp-dashboard__stats-total">
                    <span class="bp-dashboard__stats-number"><?php echo number_format_i18n($stats['total']); ?></span>
                    <span class="bp-dashboard__stats-label"><?php _e('Total Items', 'starter-dashboard'); ?></span>
                </div>
                <?php foreach ($stats['by_type'] as $type_stat): ?>
                <div class="bp-dashboard__stats-item" style="--stat-color: <?php echo esc_attr($type_stat['color']); ?>">
                    <span class="bp-dashboard__stats-number"><?php echo number_format_i18n($type_stat['count']); ?></span>
                    <span class="bp-dashboard__stats-label"><?php echo esc_html($type_stat['label']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="bp-dashboard__tabs">
                <button type="button" class="bp-dashboard__tabs-toggle" aria-label="<?php esc_attr_e('Toggle navigation menu', 'starter-dashboard'); ?>" aria-expanded="false">
                    <easier-icon name="menu-05" variant="twotone" size="20" color="currentColor"></easier-icon>
                </button>
                <div class="bp-dashboard__tabs-active">
                    <easier-icon name="document-attachment" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <span><?php _e('Content', 'starter-dashboard'); ?></span>
                </div>
                <div class="bp-dashboard__tabs-list">
                    <button type="button" class="bp-dashboard__tab bp-dashboard__tab--active" data-tab="content" data-post-types="<?php echo esc_attr(implode(',', array_column($content_types, 'name'))); ?>">
                        <easier-icon name="document-attachment" variant="twotone" size="18" color="currentColor"></easier-icon>
                        <?php _e('Content', 'starter-dashboard'); ?>
                    </button>
                    <?php if (!empty($builder_types)): ?>
                    <button type="button" class="bp-dashboard__tab" data-tab="builder" data-post-types="<?php echo esc_attr(implode(',', array_column($builder_types, 'name'))); ?>">
                        <easier-icon name="magic-wand-03" variant="twotone" size="18" color="currentColor"></easier-icon>
                        <?php _e('Builder & Templates', 'starter-dashboard'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($other_types)): ?>
                    <button type="button" class="bp-dashboard__tab" data-tab="other" data-post-types="<?php echo esc_attr(implode(',', array_column($other_types, 'name'))); ?>">
                        <easier-icon name="database" variant="twotone" size="18" color="currentColor"></easier-icon>
                        <?php _e('Custom Post Types', 'starter-dashboard'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($additional_elements)): ?>
                    <button type="button" class="bp-dashboard__tab" data-tab="additional" data-post-types="">
                        <easier-icon name="plus-sign" variant="twotone" size="18" color="currentColor"></easier-icon>
                        <?php _e('Additional Elements', 'starter-dashboard'); ?>
                    </button>
                    <?php endif; ?>
                    <?php
                    // Add tabs for active addons with settings interface
                    $addon_loader = starter_addon_loader();
                    $active_addons = $addon_loader->get_active_addons();
                    $all_addons = $addon_loader->get_addons();
                    foreach ($active_addons as $addon_id):
                        if (isset($all_addons[$addon_id]) && !empty($all_addons[$addon_id]['has_settings'])):
                            $addon = $all_addons[$addon_id];
                    ?>
                    <button type="button" class="bp-dashboard__tab" data-tab="addon-<?php echo esc_attr($addon_id); ?>" data-post-types="">
                        <easier-icon name="<?php echo esc_attr($addon['icon']); ?>" variant="twotone" size="18" stroke-color="#1C3C8B" color="#1C3C8B"></easier-icon>
                        <?php echo esc_html($addon['name']); ?>
                    </button>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            </div>

            <div class="bp-dashboard__tab-content bp-dashboard__tab-content--active" data-tab="content">
                <div class="bp-dashboard__two-columns">
                    <div class="bp-dashboard__column-main">
                        <?php if (empty($content_types)): ?>
                            <p class="bp-dashboard__empty"><?php _e('No content types available.', 'starter-dashboard'); ?></p>
                        <?php else: ?>
                            <div class="bp-dashboard__grid bp-dashboard__grid--content" data-section="content">
                                <?php foreach ($content_types as $tile): ?>
                                    <?php $this->render_card($tile); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="bp-dashboard__column-side">
                        <?php if (!empty($quick_actions)): ?>
                        <section class="bp-dashboard__section">
                            <h2 class="bp-dashboard__section-title">
                                <easier-icon name="star" variant="twotone" size="20" color="currentColor"></easier-icon>
                                <?php _e('Quick Actions', 'starter-dashboard'); ?>
                            </h2>
                            <div class="bp-dashboard__quick-actions-list">
                                <?php foreach ($quick_actions as $action): ?>
                                    <?php $this->render_quick_action($action, !empty($action['custom'])); ?>
                                <?php endforeach; ?>
                                <button type="button" class="bp-action bp-action--add" id="bp-add-custom-action">
                                    <span class="bp-action__icon">
                                        <easier-icon name="plus-sign" variant="twotone" size="20" color="currentColor"></easier-icon>
                                    </span>
                                    <span class="bp-action__label"><?php _e('Add Action', 'starter-dashboard'); ?></span>
                                </button>
                            </div>
                        </section>
                        <?php endif; ?>

                        <section class="bp-dashboard__section" id="bp-recent-activity">
                            <h2 class="bp-dashboard__section-title">
                                <easier-icon name="clock-05" variant="twotone" size="20" color="currentColor"></easier-icon>
                                <?php _e('Recent Activity', 'starter-dashboard'); ?>
                                <span class="bp-dashboard__section-loading spinner" style="display: none;"></span>
                            </h2>
                            <div class="bp-dashboard__recent" id="bp-recent-content">
                                <?php if (empty($recent_posts)): ?>
                                    <p class="bp-dashboard__empty"><?php _e('No recent posts.', 'starter-dashboard'); ?></p>
                                <?php else: ?>
                                    <div class="bp-dashboard__recent-list">
                                        <?php foreach ($recent_posts as $item): ?>
                                            <?php $this->render_recent_item($item); ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <?php if (!empty($builder_types)): ?>
            <div class="bp-dashboard__tab-content" data-tab="builder">
                <div class="bp-dashboard__grid bp-dashboard__grid--content" data-section="builder">
                    <?php foreach ($builder_types as $tile): ?>
                        <?php $this->render_card($tile); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($other_types)): ?>
            <div class="bp-dashboard__tab-content" data-tab="other">
                <div class="bp-dashboard__grid bp-dashboard__grid--content" data-section="other">
                    <?php foreach ($other_types as $tile): ?>
                        <?php $this->render_card($tile); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($additional_elements)): ?>
            <div class="bp-dashboard__tab-content" data-tab="additional">
                <div class="bp-dashboard__grid bp-dashboard__grid--additional">
                    <?php foreach ($additional_elements as $element): ?>
                        <?php $this->render_additional_element_card($element); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Render tab content for active addons with settings
            foreach ($active_addons as $addon_id):
                if (isset($all_addons[$addon_id]) && !empty($all_addons[$addon_id]['has_settings'])):
                    $addon = $all_addons[$addon_id];
                    // Load addon file to make settings callback available
                    if (file_exists($addon['file'])) {
                        require_once $addon['file'];
                    }
            ?>
            <div class="bp-dashboard__tab-content" data-tab="addon-<?php echo esc_attr($addon_id); ?>">
                <div class="bp-addon-settings-panel">
                    <div class="bp-addon-settings-panel__header">
                        <easier-icon name="<?php echo esc_attr($addon['icon']); ?>" variant="twotone" size="20" stroke-color="#1C3C8B" color="#1C3C8B"></easier-icon>
                        <h3><?php echo esc_html($addon['name']); ?> <?php _e('Settings', 'starter-dashboard'); ?></h3>
                        <span class="bp-addon-version">v<?php echo esc_html($addon['version']); ?></span>
                    </div>
                    <div class="bp-addon-settings-panel__content">
                        <?php
                        if (!empty($addon['settings_callback']) && is_callable($addon['settings_callback'])) {
                            call_user_func($addon['settings_callback']);
                        }
                        ?>
                    </div>
                    <div class="bp-addon-settings-panel__footer">
                        <button type="button" class="button button-primary bp-addon-dashboard-save" data-addon="<?php echo esc_attr($addon_id); ?>">
                            <?php _e('Save Settings', 'starter-dashboard'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php
                endif;
            endforeach;
            ?>

            <!-- Modal for item lists -->
            <div class="bp-modal" id="bp-items-modal" aria-hidden="true">
                <div class="bp-modal__backdrop"></div>
                <div class="bp-modal__container">
                    <div class="bp-modal__header">
                        <h3 class="bp-modal__title"></h3>
                        <button type="button" class="bp-modal__close" aria-label="<?php esc_attr_e('Close', 'starter-dashboard'); ?>">
                            <easier-icon name="cancel-01" variant="twotone" size="20" color="currentColor"></easier-icon>
                        </button>
                    </div>
                    <div class="bp-modal__content">
                        <div class="bp-modal__loading">
                            <span class="spinner is-active"></span>
                            <?php _e('Loading...', 'starter-dashboard'); ?>
                        </div>
                        <ul class="bp-modal__list"></ul>
                        <div class="bp-modal__empty">
                            <?php _e('No items found.', 'starter-dashboard'); ?>
                        </div>
                    </div>
                    <div class="bp-modal__footer">
                        <a href="#" class="bp-modal__full-link bp-btn bp-btn--primary">
                            <easier-icon name="share-08" variant="twotone" size="16" color="currentColor"></easier-icon>
                            <?php _e('View All', 'starter-dashboard'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Elementor Iframe Modal -->
            <div class="bp-iframe-modal" id="bp-elementor-modal" aria-hidden="true">
                <div class="bp-iframe-modal__backdrop"></div>
                <div class="bp-iframe-modal__container">
                    <div class="bp-iframe-modal__header">
                        <div class="bp-iframe-modal__title">
                            <easier-icon name="pencil-edit-02" variant="twotone" size="20" color="currentColor"></easier-icon>
                            <span class="bp-iframe-modal__title-text"><?php _e('Edit with Elementor', 'starter-dashboard'); ?></span>
                        </div>
                        <div class="bp-iframe-modal__actions">
                            <a href="#" class="bp-iframe-modal__btn bp-iframe-modal__btn--secondary bp-iframe-modal__open-tab" target="_blank">
                                <easier-icon name="share-08" variant="twotone" size="16" color="currentColor"></easier-icon>
                                <?php _e('Open in New Tab', 'starter-dashboard'); ?>
                            </a>
                            <button type="button" class="bp-iframe-modal__btn bp-iframe-modal__btn--close bp-iframe-modal__close">
                                <easier-icon name="cancel-01" variant="twotone" size="16" color="currentColor"></easier-icon>
                                <?php _e('Close', 'starter-dashboard'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="bp-iframe-modal__body">
                        <div class="bp-iframe-modal__loader">
                            <div class="bp-iframe-modal__loader-spinner"></div>
                            <span><?php _e('Loading Elementor...', 'starter-dashboard'); ?></span>
                        </div>
                        <iframe class="bp-iframe-modal__iframe" src="about:blank"></iframe>
                    </div>
                </div>
            </div>

            <!-- Add Custom Action Modal -->
            <div class="bp-modal bp-modal--custom-action" id="bp-custom-action-modal" aria-hidden="true">
                <div class="bp-modal__backdrop"></div>
                <div class="bp-modal__container">
                    <div class="bp-modal__header">
                        <h3 class="bp-modal__title">
                            <easier-icon name="plus-sign" variant="twotone" size="20" color="currentColor"></easier-icon>
                            <?php _e('Add Quick Action', 'starter-dashboard'); ?>
                        </h3>
                        <button type="button" class="bp-modal__close" aria-label="<?php esc_attr_e('Close', 'starter-dashboard'); ?>">
                            <easier-icon name="cancel-01" variant="twotone" size="20" color="currentColor"></easier-icon>
                        </button>
                    </div>
                    <div class="bp-modal__content">
                        <div class="bp-custom-action__search">
                            <easier-icon name="search-01" variant="twotone" size="18" color="#999"></easier-icon>
                            <input type="text" id="bp-custom-action-search" placeholder="<?php esc_attr_e('Search pages...', 'starter-dashboard'); ?>">
                        </div>
                        <div class="bp-custom-action__list" id="bp-custom-action-list">
                            <?php $this->render_admin_menu_items(); ?>
                        </div>
                        <div class="bp-custom-action__icon-picker" id="bp-icon-picker" style="display:none;">
                            <div class="bp-custom-action__icon-picker-header">
                                <button type="button" class="bp-custom-action__back" id="bp-icon-picker-back">
                                    <easier-icon name="arrow-left-01" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Back', 'starter-dashboard'); ?>
                                </button>
                                <span class="bp-custom-action__selected-label"></span>
                            </div>
                            <div class="bp-custom-action__search">
                                <easier-icon name="search-01" variant="twotone" size="18" color="#999"></easier-icon>
                                <input type="text" id="bp-icon-search" placeholder="<?php esc_attr_e('Search icons...', 'starter-dashboard'); ?>">
                            </div>
                            <div class="bp-custom-action__icons" id="bp-icon-grid">
                                <!-- Icons loaded via JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page with tabs
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        $roles = wp_roles()->roles;
        $menu_items = $this->get_all_menu_items();
        $saved_roles = get_option('starter_dashboard_allowed_roles', []);
        $hidden_menus = get_option('starter_hidden_menus', []);
        $custom_post_types = $this->get_custom_post_types();

        if (!is_array($saved_roles)) $saved_roles = [];
        if (!is_array($hidden_menus)) $hidden_menus = [];
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="wrap bp-settings">
            <h1 style="display:none;"><?php echo esc_html($visual_settings['hub_name']); ?> <?php _e('Settings', 'starter-dashboard'); ?></h1>

            <div class="bp-settings__header" style="--header-primary: <?php echo esc_attr($visual_settings['primary_color']); ?>;">
                <div class="bp-settings__header-content">
                    <img src="<?php echo esc_url($visual_settings['logo_url']); ?>" alt="<?php echo esc_attr($visual_settings['hub_name']); ?>" class="bp-settings__header-logo" />
                    <div class="bp-settings__header-text">
                        <h2><?php _e('Settings', 'starter-dashboard'); ?></h2>
                        <p><?php printf(__('Configure your %s dashboard', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?></p>
                    </div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=starter-dashboard'); ?>" class="bp-settings__header-btn">
                    <easier-icon name="arrow-left-01" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php printf(__('Back to %s', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
                </a>
            </div>

            <nav class="bp-settings__tabs">
                <a href="?page=starter-dashboard-settings&tab=dashboard" class="bp-settings__tab <?php echo $active_tab === 'dashboard' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="home-11" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Dashboard Access', 'starter-dashboard'); ?>
                </a>
                <a href="?page=starter-dashboard-settings&tab=menu" class="bp-settings__tab <?php echo $active_tab === 'menu' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="hamburger-01" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Menu Visibility', 'starter-dashboard'); ?>
                </a>
                <a href="?page=starter-dashboard-settings&tab=menu-order" class="bp-settings__tab <?php echo $active_tab === 'menu-order' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="sort-by-up-01" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Menu Order', 'starter-dashboard'); ?>
                </a>
                <a href="?page=starter-dashboard-settings&tab=additional" class="bp-settings__tab <?php echo $active_tab === 'additional' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="plus-sign" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Additional Elements', 'starter-dashboard'); ?>
                </a>
                <?php if ($this->can_user_edit_visual_settings()): ?>
                <a href="?page=starter-dashboard-settings&tab=visual" class="bp-settings__tab <?php echo $active_tab === 'visual' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="paint-bucket" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Visual Settings', 'starter-dashboard'); ?>
                </a>
                <?php endif; ?>
                <a href="?page=starter-dashboard-settings&tab=roles" class="bp-settings__tab <?php echo $active_tab === 'roles' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="user-group" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('Role Editor', 'starter-dashboard'); ?>
                </a>
                <a href="?page=starter-dashboard-settings&tab=cpt" class="bp-settings__tab <?php echo $active_tab === 'cpt' ? 'bp-settings__tab--active' : ''; ?>">
                    <easier-icon name="database" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php _e('CPTs', 'starter-dashboard'); ?>
                </a>
            </nav>

            <div class="bp-settings__content">
                <?php if ($active_tab === 'dashboard'): ?>
                    <?php $this->render_dashboard_tab($roles, $saved_roles); ?>
                <?php elseif ($active_tab === 'menu'): ?>
                    <?php $this->render_menu_tab($roles, $menu_items, $hidden_menus); ?>
                <?php elseif ($active_tab === 'menu-order'): ?>
                    <?php $this->render_menu_order_tab($menu_items); ?>
                <?php elseif ($active_tab === 'additional'): ?>
                    <?php $this->render_additional_elements_tab($hidden_menus); ?>
                <?php elseif ($active_tab === 'visual' && $this->can_user_edit_visual_settings()): ?>
                    <?php $this->render_visual_settings_tab(); ?>
                <?php elseif ($active_tab === 'roles'): ?>
                    <?php $this->render_roles_tab($roles); ?>
                <?php elseif ($active_tab === 'cpt'): ?>
                    <?php $this->render_cpt_tab($custom_post_types, $roles); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Dashboard Access tab
     */
    private function render_dashboard_tab($roles, $saved_roles) {
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Dashboard Access Control', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php printf(__('Select which user roles can access the %s dashboard. Users with selected roles will be redirected from the default WordPress dashboard.', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
            </p>

            <form action="options.php" method="post">
                <?php settings_fields('starter_dashboard_settings'); ?>

                <div class="bp-settings__roles-grid">
                    <?php foreach ($roles as $role_slug => $role_data): ?>
                        <?php
                        $role_name = isset($role_data['name']) ? $role_data['name'] : $role_slug;
                        $checked = in_array($role_slug, $saved_roles, true);
                        $cap_count = count($role_data['capabilities']);
                        ?>
                        <label class="bp-settings__role-card <?php echo $checked ? 'bp-settings__role-card--active' : ''; ?>">
                            <input
                                type="checkbox"
                                name="starter_dashboard_allowed_roles[]"
                                value="<?php echo esc_attr($role_slug); ?>"
                                <?php checked($checked); ?>
                            />
                            <span class="bp-settings__role-icon">
                                <easier-icon name="user-group" variant="twotone" size="20" stroke-color="#fff" color="#fff"></easier-icon>
                            </span>
                            <span class="bp-settings__role-name"><?php echo esc_html(translate_user_role($role_name)); ?></span>
                            <span class="bp-settings__role-caps"><?php printf(__('%d capabilities', 'starter-dashboard'), $cap_count); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php submit_button(__('Save Dashboard Access', 'starter-dashboard'), 'primary', 'submit', true, ['class' => 'bp-settings__submit']); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render Menu Visibility tab
     */
    private function render_menu_tab($roles, $menu_items, $hidden_menus) {
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Admin Menu Visibility', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php _e('Control which admin menu items are visible for each user role. Check "Hidden" to hide menu items from all users.', 'starter-dashboard'); ?>
            </p>

            <?php if (empty($menu_items)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('No menu items found. Please refresh the page.', 'starter-dashboard'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat striped" style="margin-top:20px;">
                    <thead>
                        <tr>
                            <th style="width:40%;"><?php _e('Menu Item', 'starter-dashboard'); ?></th>
                            <th style="width:15%;"><?php _e('Hidden', 'starter-dashboard'); ?></th>
                            <th style="width:35%;"><?php _e('Hidden for Roles', 'starter-dashboard'); ?></th>
                            <th style="width:10%;"><?php _e('Actions', 'starter-dashboard'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menu_items as $item):
                            $item_hidden_roles = [];
                            foreach ($hidden_menus as $role => $slugs) {
                                // Check both old format (slug only) and new format (no parent for main items)
                                if (is_array($slugs) && in_array($item['slug'], $slugs)) {
                                    $item_hidden_roles[] = $role;
                                }
                            }
                        ?>
                            <tr data-slug="<?php echo esc_attr($item['slug']); ?>">
                                <td>
                                    <?php echo self::render_icon($item['icon'], 18, '#1C3C8B'); ?>
                                    <strong><?php echo esc_html($item['title']); ?></strong>
                                    <br><code style="font-size:11px;color:#666;"><?php echo esc_html($item['slug']); ?></code>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" class="bp-menu-hidden-all" data-slug="<?php echo esc_attr($item['slug']); ?>" />
                                        <?php _e('Hide for all', 'starter-dashboard'); ?>
                                    </label>
                                </td>
                                <td>
                                    <select class="bp-menu-hidden-roles" data-slug="<?php echo esc_attr($item['slug']); ?>" multiple>
                                        <?php foreach ($roles as $role_slug => $role_data):
                                            $selected = in_array($role_slug, $item_hidden_roles);
                                        ?>
                                            <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($selected); ?>>
                                                <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button bp-save-menu-item" data-slug="<?php echo esc_attr($item['slug']); ?>">
                                        <?php _e('Save', 'starter-dashboard'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php if (!empty($item['children'])): ?>
                                <?php foreach ($item['children'] as $child):
                                    $child_hidden_roles = [];
                                    // Submenu key format: parent_slug||submenu_slug
                                    $submenu_key = $item['slug'] . '||' . $child['slug'];
                                    foreach ($hidden_menus as $role => $slugs) {
                                        // Check both new format (parent||child) and old format (child only) for backward compatibility
                                        if (is_array($slugs) && (in_array($submenu_key, $slugs) || in_array($child['slug'], $slugs))) {
                                            $child_hidden_roles[] = $role;
                                        }
                                    }
                                ?>
                                    <tr data-slug="<?php echo esc_attr($child['slug']); ?>" data-parent="<?php echo esc_attr($item['slug']); ?>" style="background:#f9f9f9;">
                                        <td style="padding-left:40px;">
                                            <span style="color:#999;">↳</span>
                                            <?php echo esc_html($child['title']); ?>
                                            <br><code style="font-size:11px;color:#999;"><?php echo esc_html($child['slug']); ?></code>
                                        </td>
                                        <td>
                                            <label>
                                                <input type="checkbox" class="bp-menu-hidden-all" data-slug="<?php echo esc_attr($child['slug']); ?>" />
                                                <?php _e('Hide', 'starter-dashboard'); ?>
                                            </label>
                                        </td>
                                        <td>
                                            <select class="bp-menu-hidden-roles" data-slug="<?php echo esc_attr($child['slug']); ?>" multiple>
                                                <?php foreach ($roles as $role_slug => $role_data):
                                                    $selected = in_array($role_slug, $child_hidden_roles);
                                                ?>
                                                    <option value="<?php echo esc_attr($role_slug); ?>" <?php selected($selected); ?>>
                                                        <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small bp-save-menu-item" data-slug="<?php echo esc_attr($child['slug']); ?>">
                                                <?php _e('Save', 'starter-dashboard'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:20px;">
                    <button type="button" id="bp-save-all-menu-settings" class="button button-primary button-large">
                        <?php _e('Save All Menu Settings', 'starter-dashboard'); ?>
                    </button>
                </p>

                <input type="hidden" id="bp-hidden-menus-data" value="<?php echo esc_attr(json_encode($hidden_menus)); ?>" />
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Menu Order tab
     */
    private function render_menu_order_tab($menu_items) {
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Admin Menu Order', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php _e('Drag and drop menu items to reorder them in the WordPress admin sidebar. Changes will apply to all users.', 'starter-dashboard'); ?>
                <br><em><?php printf(__('Note: %s always stays at the top of the menu.', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?></em>
            </p>

            <?php
            $saved_menu_order = get_option('starter_admin_menu_order', []);
            // Filter out empty titles and Hub (always stays at top)
            $main_menu_items = array_filter($menu_items, function($item) {
                return !empty($item['title']) && $item['slug'] !== 'starter-dashboard';
            });

            // Sort by saved order if exists
            if (!empty($saved_menu_order)) {
                usort($main_menu_items, function($a, $b) use ($saved_menu_order) {
                    $pos_a = array_search($a['slug'], $saved_menu_order);
                    $pos_b = array_search($b['slug'], $saved_menu_order);
                    if ($pos_a === false) $pos_a = 999;
                    if ($pos_b === false) $pos_b = 999;
                    return $pos_a - $pos_b;
                });
            }
            ?>

            <ul id="bp-menu-order-list" class="bp-menu-order-list">
                <?php foreach ($main_menu_items as $item): ?>
                <li class="bp-menu-order-item" data-slug="<?php echo esc_attr($item['slug']); ?>">
                    <span class="bp-menu-order-item__handle"><easier-icon name="drag-04" variant="twotone" size="18" stroke-color="#999" color="#999"></easier-icon></span>
                    <?php echo self::render_icon($item['icon'], 18, '#1C3C8B'); ?>
                    <span class="bp-menu-order-item__title"><?php echo esc_html($item['title']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <p style="margin-top: 20px;">
                <button type="button" id="bp-save-menu-order" class="button button-primary">
                    <?php _e('Save Menu Order', 'starter-dashboard'); ?>
                </button>
                <button type="button" id="bp-reset-menu-order" class="button" style="margin-left: 10px;">
                    <?php _e('Reset to Default', 'starter-dashboard'); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * Render Additional Elements tab
     */
    private function render_additional_elements_tab($hidden_menus) {
        $visual_settings = $this->get_visual_settings();
        // Get all users
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $allowed_users = get_option('starter_additional_elements_users', []);
        if (!is_array($allowed_users)) {
            $allowed_users = [];
        }

        // Get all menu items that are hidden for any role
        $all_hidden_slugs = [];
        foreach ($hidden_menus as $role => $slugs) {
            if (is_array($slugs)) {
                $all_hidden_slugs = array_merge($all_hidden_slugs, $slugs);
            }
        }
        $all_hidden_slugs = array_unique($all_hidden_slugs);

        // Get menu items details
        $menu_items = $this->get_all_menu_items();
        $hidden_items = [];
        foreach ($menu_items as $item) {
            if (in_array($item['slug'], $all_hidden_slugs)) {
                $hidden_items[] = $item;
            }
        }
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Additional Elements', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php printf(__('Select which users can see hidden menu items as cards in the "Additional Elements" section on the %s dashboard. This allows specific users to access functionality that is hidden from the regular admin menu.', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
            </p>

            <div class="bp-additional-settings">
                <h3 style="margin-top:0;"><?php _e('Users with access to Additional Elements', 'starter-dashboard'); ?></h3>
                <p class="description"><?php _e('Selected users will see a new "Additional Elements" tab in the Hub with cards for hidden menu items.', 'starter-dashboard'); ?></p>

                <div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:15px;border-radius:4px;margin:15px 0;">
                    <?php foreach ($users as $user):
                        $checked = in_array($user->ID, $allowed_users);
                    ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px;margin:0;cursor:pointer;border-bottom:1px solid #f0f0f0;">
                            <input type="checkbox"
                                   class="bp-additional-user-checkbox"
                                   value="<?php echo esc_attr($user->ID); ?>"
                                   <?php checked($checked); ?> />
                            <easier-icon name="user-group" variant="twotone" size="18" color="#1C3C8B"></easier-icon>
                            <span>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <span style="color:#666;font-size:12px;">(<?php echo esc_html($user->user_email); ?>)</span>
                                <br>
                                <small style="color:#999;"><?php echo esc_html(implode(', ', $user->roles)); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="bp-save-additional-users" class="button button-primary">
                    <?php _e('Save User Access', 'starter-dashboard'); ?>
                </button>
            </div>

            <?php if (!empty($hidden_items)): ?>
            <div class="bp-additional-preview" style="margin-top:30px;">
                <h3><?php _e('Hidden Menu Items Preview', 'starter-dashboard'); ?></h3>
                <p class="description"><?php _e('These hidden menu items will appear as cards in the Additional Elements section for allowed users:', 'starter-dashboard'); ?></p>

                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));gap:15px;margin-top:15px;">
                    <?php foreach ($hidden_items as $item): ?>
                        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;border-left:4px solid #1C3C8B;">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                                <?php echo self::render_icon($item['icon'], 24, '#1C3C8B'); ?>
                                <strong style="font-size:14px;"><?php echo esc_html($item['title']); ?></strong>
                            </div>
                            <?php if (!empty($item['children'])): ?>
                                <div style="font-size:12px;color:#666;">
                                    <?php
                                    $child_titles = array_column($item['children'], 'title');
                                    echo esc_html(implode(', ', array_slice($child_titles, 0, 5)));
                                    if (count($child_titles) > 5) {
                                        echo ' +' . (count($child_titles) - 5) . ' more';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="notice notice-info" style="margin-top:20px;">
                    <p><?php _e('No hidden menu items found. Hide some menu items in the "Menu Visibility" tab first, then they will appear here.', 'starter-dashboard'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Visual Settings tab
     */
    private function render_visual_settings_tab() {
        // Get current settings
        $visual_settings = get_option('starter_visual_settings', []);
        $allowed_users = get_option('starter_visual_settings_users', []);

        if (!is_array($visual_settings)) {
            $visual_settings = [];
        }
        if (!is_array($allowed_users)) {
            $allowed_users = [];
        }

        // Get Elementor global settings as defaults
        $elementor_defaults = $this->get_elementor_global_settings();

        // Merge with saved settings (saved takes precedence)
        $hub_name = !empty($visual_settings['hub_name']) ? $visual_settings['hub_name'] : 'Dashboard Hub';
        $logo_url = !empty($visual_settings['logo_url']) ? $visual_settings['logo_url'] : $elementor_defaults['logo_url'];
        $primary_color = !empty($visual_settings['primary_color']) ? $visual_settings['primary_color'] : $elementor_defaults['primary_color'];
        $secondary_color = !empty($visual_settings['secondary_color']) ? $visual_settings['secondary_color'] : $elementor_defaults['secondary_color'];
        $accent_color = !empty($visual_settings['accent_color']) ? $visual_settings['accent_color'] : $elementor_defaults['accent_color'];
        $show_stats = isset($visual_settings['show_stats']) ? (bool) $visual_settings['show_stats'] : true;

        // Get all users
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $admins = get_users(['role' => 'administrator']);
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Visual Settings', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php printf(__('Customize the appearance of the %s dashboard. Logo and colors default to Elementor Global Site Settings if available.', 'starter-dashboard'), esc_html($hub_name)); ?>
            </p>

            <!-- User Access Section -->
            <div class="bp-visual-access" style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:24px;">
                <h3 style="margin:0 0 12px;font-size:14px;">
                    <easier-icon name="security-lock" variant="twotone" size="18" color="#1C3C8B" style="margin-right:8px;vertical-align:middle;"></easier-icon>
                    <?php _e('Access Control', 'starter-dashboard'); ?>
                </h3>
                <p class="description" style="margin-bottom:15px;">
                    <?php _e('By default, all administrators can see Visual Settings. Select specific users to restrict access to only those users.', 'starter-dashboard'); ?>
                </p>

                <div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:15px;border-radius:4px;background:#fff;">
                    <?php foreach ($users as $user):
                        $is_admin = in_array('administrator', $user->roles);
                        $checked = in_array($user->ID, $allowed_users);
                    ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px;margin:0;cursor:pointer;border-bottom:1px solid #f0f0f0;">
                            <input type="checkbox"
                                   class="bp-visual-user-checkbox"
                                   value="<?php echo esc_attr($user->ID); ?>"
                                   <?php checked($checked); ?> />
                            <easier-icon name="user-group" variant="twotone" size="18" color="<?php echo $is_admin ? '#1C3C8B' : '#666'; ?>"></easier-icon>
                            <span>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <?php if ($is_admin): ?>
                                    <span style="background:#1C3C8B;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;">Admin</span>
                                <?php endif; ?>
                                <br>
                                <small style="color:#999;"><?php echo esc_html($user->user_email); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="bp-save-visual-users" class="button" style="margin-top:12px;">
                    <?php _e('Save Access Settings', 'starter-dashboard'); ?>
                </button>
                <span class="description" style="margin-left:10px;color:#666;">
                    <?php _e('Leave all unchecked to allow all administrators.', 'starter-dashboard'); ?>
                </span>
            </div>

            <!-- Visual Customization Section -->
            <div class="bp-visual-customize">
                <h3 style="margin:0 0 20px;font-size:14px;">
                    <easier-icon name="paint-bucket" variant="twotone" size="18" color="#1C3C8B" style="margin-right:8px;vertical-align:middle;"></easier-icon>
                    <?php _e('Hub Appearance', 'starter-dashboard'); ?>
                </h3>

                <!-- Hub Name -->
                <div class="bp-visual-field" style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:8px;"><?php _e('Hub Name', 'starter-dashboard'); ?></label>
                    <input type="text"
                           id="bp-hub-name"
                           value="<?php echo esc_attr($hub_name); ?>"
                           placeholder="Dashboard Hub"
                           style="width:100%;max-width:400px;padding:10px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" />
                    <p class="description" style="margin-top:6px;"><?php _e('The name displayed in the dashboard header.', 'starter-dashboard'); ?></p>
                </div>

                <!-- Logo -->
                <div class="bp-visual-field" style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:8px;"><?php _e('Logo URL', 'starter-dashboard'); ?></label>
                    <div style="display:flex;gap:12px;align-items:flex-start;">
                        <div style="flex:1;max-width:500px;">
                            <input type="text"
                                   id="bp-logo-url"
                                   value="<?php echo esc_attr($logo_url); ?>"
                                   placeholder="<?php echo esc_attr($elementor_defaults['logo_url']); ?>"
                                   style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" />
                            <p class="description" style="margin-top:6px;">
                                <?php _e('URL to the logo image. Leave empty to use Elementor Global Site Settings logo.', 'starter-dashboard'); ?>
                                <button type="button" id="bp-select-logo" class="button button-small" style="margin-left:10px;">
                                    <?php _e('Select from Media', 'starter-dashboard'); ?>
                                </button>
                            </p>
                        </div>
                        <div id="bp-logo-preview" style="width:120px;height:60px;background:#1C3C8B;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:10px;">
                            <?php if ($logo_url): ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="Logo preview" style="max-width:100%;max-height:100%;object-fit:contain;" />
                            <?php else: ?>
                                <span style="color:#fff;font-size:11px;text-align:center;"><?php _e('No logo', 'starter-dashboard'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Colors -->
                <div class="bp-visual-colors" style="display:grid;grid-template-columns:repeat(3, 1fr);gap:20px;margin-bottom:24px;">
                    <!-- Primary Color -->
                    <div class="bp-visual-field">
                        <label style="display:block;font-weight:600;margin-bottom:8px;"><?php _e('Primary Color', 'starter-dashboard'); ?></label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input type="color"
                                   id="bp-primary-color"
                                   value="<?php echo esc_attr($primary_color); ?>"
                                   style="width:50px;height:40px;border:1px solid #ddd;border-radius:4px;cursor:pointer;" />
                            <input type="text"
                                   id="bp-primary-color-text"
                                   value="<?php echo esc_attr($primary_color); ?>"
                                   style="width:100px;padding:8px;border:1px solid #ddd;border-radius:4px;font-family:monospace;" />
                        </div>
                        <p class="description" style="margin-top:6px;"><?php _e('Header background, buttons.', 'starter-dashboard'); ?></p>
                        <?php if (!empty($elementor_defaults['primary_color'])): ?>
                            <small style="color:#999;">Elementor: <?php echo esc_html($elementor_defaults['primary_color']); ?></small>
                        <?php endif; ?>
                    </div>

                    <!-- Secondary Color -->
                    <div class="bp-visual-field">
                        <label style="display:block;font-weight:600;margin-bottom:8px;"><?php _e('Secondary Color', 'starter-dashboard'); ?></label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input type="color"
                                   id="bp-secondary-color"
                                   value="<?php echo esc_attr($secondary_color); ?>"
                                   style="width:50px;height:40px;border:1px solid #ddd;border-radius:4px;cursor:pointer;" />
                            <input type="text"
                                   id="bp-secondary-color-text"
                                   value="<?php echo esc_attr($secondary_color); ?>"
                                   style="width:100px;padding:8px;border:1px solid #ddd;border-radius:4px;font-family:monospace;" />
                        </div>
                        <p class="description" style="margin-top:6px;"><?php _e('Accents, links.', 'starter-dashboard'); ?></p>
                        <?php if (!empty($elementor_defaults['secondary_color'])): ?>
                            <small style="color:#999;">Elementor: <?php echo esc_html($elementor_defaults['secondary_color']); ?></small>
                        <?php endif; ?>
                    </div>

                    <!-- Accent Color -->
                    <div class="bp-visual-field">
                        <label style="display:block;font-weight:600;margin-bottom:8px;"><?php _e('Accent Color', 'starter-dashboard'); ?></label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input type="color"
                                   id="bp-accent-color"
                                   value="<?php echo esc_attr($accent_color); ?>"
                                   style="width:50px;height:40px;border:1px solid #ddd;border-radius:4px;cursor:pointer;" />
                            <input type="text"
                                   id="bp-accent-color-text"
                                   value="<?php echo esc_attr($accent_color); ?>"
                                   style="width:100px;padding:8px;border:1px solid #ddd;border-radius:4px;font-family:monospace;" />
                        </div>
                        <p class="description" style="margin-top:6px;"><?php _e('Highlights, hover states.', 'starter-dashboard'); ?></p>
                        <?php if (!empty($elementor_defaults['accent_color'])): ?>
                            <small style="color:#999;">Elementor: <?php echo esc_html($elementor_defaults['accent_color']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dashboard Options -->
                <div class="bp-visual-options" style="margin-bottom:24px;padding:20px;background:#f8f9fa;border-radius:8px;">
                    <h4 style="margin:0 0 16px;font-size:14px;font-weight:600;">
                        <easier-icon name="setting-done-01" variant="twotone" size="18" color="#1C3C8B" style="margin-right:8px;vertical-align:middle;"></easier-icon>
                        <?php _e('Dashboard Options', 'starter-dashboard'); ?>
                    </h4>
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                        <input type="checkbox"
                               id="bp-show-stats"
                               <?php checked($show_stats); ?>
                               style="width:18px;height:18px;cursor:pointer;" />
                        <span>
                            <strong><?php _e('Show Statistics', 'starter-dashboard'); ?></strong>
                            <br>
                            <small style="color:#666;"><?php _e('Display the statistics bar at the top of the dashboard (Total Items, Pages, Posts, etc.)', 'starter-dashboard'); ?></small>
                        </span>
                    </label>
                </div>

                <!-- Preview -->
                <div class="bp-visual-preview" style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;"><?php _e('Preview', 'starter-dashboard'); ?></label>
                    <div id="bp-header-preview" style="background:<?php echo esc_attr($primary_color); ?>;border-radius:12px;padding:20px 30px;display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:15px;">
                            <img id="bp-preview-logo" src="<?php echo esc_url($logo_url); ?>" alt="" style="height:40px;max-width:150px;object-fit:contain;" />
                            <span id="bp-preview-name" style="color:#fff;font-size:18px;font-weight:600;"><?php echo esc_html($hub_name); ?></span>
                        </div>
                        <div style="display:flex;gap:10px;">
                            <span style="background:<?php echo esc_attr($secondary_color); ?>;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;">Button</span>
                            <span style="background:<?php echo esc_attr($accent_color); ?>;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;">Accent</span>
                        </div>
                    </div>
                </div>

                <!-- Reset to Elementor -->
                <div style="display:flex;gap:12px;align-items:center;padding-top:20px;border-top:1px solid #eee;">
                    <button type="button" id="bp-save-visual-settings" class="button button-primary button-large">
                        <?php _e('Save Visual Settings', 'starter-dashboard'); ?>
                    </button>
                    <button type="button" id="bp-reset-to-elementor" class="button">
                        <?php _e('Reset to Elementor Defaults', 'starter-dashboard'); ?>
                    </button>
                </div>

                <!-- Hidden field with Elementor defaults for JS -->
                <input type="hidden" id="bp-elementor-defaults" value="<?php echo esc_attr(json_encode($elementor_defaults)); ?>" />
            </div>
        </div>
        <?php
    }

    /**
     * Get Elementor Global Site Settings
     */
    private function get_elementor_global_settings() {
        $defaults = [
            'logo_url' => content_url('/uploads/2020/11/Logo_BP-RGB-INV.svg'),
            'primary_color' => '#1C3C8B',
            'secondary_color' => '#2ABADE',
            'accent_color' => '#92003B',
        ];

        // Check if Elementor is active
        if (!class_exists('\Elementor\Plugin')) {
            return $defaults;
        }

        try {
            // Get Elementor Kit settings
            $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

            if ($kit && $kit->get_id()) {
                $kit_settings = $kit->get_settings();

                // Get system colors
                if (!empty($kit_settings['system_colors'])) {
                    foreach ($kit_settings['system_colors'] as $color) {
                        if (isset($color['_id']) && isset($color['color'])) {
                            switch ($color['_id']) {
                                case 'primary':
                                    $defaults['primary_color'] = $color['color'];
                                    break;
                                case 'secondary':
                                    $defaults['secondary_color'] = $color['color'];
                                    break;
                                case 'accent':
                                    $defaults['accent_color'] = $color['color'];
                                    break;
                            }
                        }
                    }
                }

                // Get custom colors if system colors not found
                if (!empty($kit_settings['custom_colors'])) {
                    foreach ($kit_settings['custom_colors'] as $color) {
                        if (isset($color['_id']) && isset($color['color'])) {
                            // Map common naming conventions
                            $id = strtolower($color['_id']);
                            if (strpos($id, 'primary') !== false && $defaults['primary_color'] === '#1C3C8B') {
                                $defaults['primary_color'] = $color['color'];
                            } elseif (strpos($id, 'secondary') !== false && $defaults['secondary_color'] === '#2ABADE') {
                                $defaults['secondary_color'] = $color['color'];
                            } elseif (strpos($id, 'accent') !== false && $defaults['accent_color'] === '#92003B') {
                                $defaults['accent_color'] = $color['color'];
                            }
                        }
                    }
                }

                // Get site logo
                if (!empty($kit_settings['site_logo']['url'])) {
                    $defaults['logo_url'] = $kit_settings['site_logo']['url'];
                }
            }
        } catch (\Exception $e) {
            // Return defaults if Elementor throws any error
        }

        return $defaults;
    }

    /**
     * Render Role Editor tab
     */
    private function render_roles_tab($roles) {
        // Get all available capabilities from all roles
        $all_caps = $this->get_all_capabilities();
        $builtin_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Role Editor', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php _e('Manage user roles and their capabilities. Add or remove capabilities from each role. Be careful when editing administrator capabilities.', 'starter-dashboard'); ?>
            </p>

            <!-- Create New Role -->
            <div class="bp-role-create" style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:24px;">
                <h3 style="margin:0 0 12px;font-size:14px;"><?php _e('Create New Role', 'starter-dashboard'); ?></h3>
                <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;"><?php _e('Role ID (slug)', 'starter-dashboard'); ?></label>
                        <input type="text" id="bp-new-role-slug" placeholder="custom_role" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;"><?php _e('Display Name', 'starter-dashboard'); ?></label>
                        <input type="text" id="bp-new-role-name" placeholder="Custom Role" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px;"><?php _e('Clone from', 'starter-dashboard'); ?></label>
                        <select id="bp-new-role-clone" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                            <option value=""><?php _e('— Empty role —', 'starter-dashboard'); ?></option>
                            <?php foreach ($roles as $role_slug => $role_data): ?>
                                <option value="<?php echo esc_attr($role_slug); ?>"><?php echo esc_html(translate_user_role($role_data['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" id="bp-create-role-btn" class="button button-primary">
                        <?php _e('Create Role', 'starter-dashboard'); ?>
                    </button>
                </div>
            </div>

            <!-- Roles List -->
            <?php foreach ($roles as $role_slug => $role_data):
                $is_builtin = in_array($role_slug, $builtin_roles);
                $role_caps = array_keys(array_filter($role_data['capabilities']));
                sort($role_caps);
            ?>
                <div class="bp-role-editor" data-role="<?php echo esc_attr($role_slug); ?>" style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-bottom:16px;">
                    <div class="bp-role-editor__header" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #eee;cursor:pointer;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <easier-icon name="user-group" variant="twotone" size="18" color="#1C3C8B"></easier-icon>
                            <div>
                                <strong style="font-size:15px;"><?php echo esc_html(translate_user_role($role_data['name'])); ?></strong>
                                <code style="font-size:11px;color:#666;margin-left:8px;"><?php echo esc_html($role_slug); ?></code>
                                <?php if ($is_builtin): ?>
                                    <span style="background:#2ABADE;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;margin-left:8px;"><?php _e('Built-in', 'starter-dashboard'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span class="bp-role-cap-count" style="background:#f0f0f0;padding:4px 10px;border-radius:12px;font-size:12px;"><?php echo count($role_caps); ?> <?php _e('caps', 'starter-dashboard'); ?></span>
                            <easier-icon name="arrow-down-03" variant="twotone" size="18" color="#999" class="bp-role-toggle" style="transition:transform 0.2s;"></easier-icon>
                        </div>
                    </div>
                    <div class="bp-role-editor__content" style="display:none;padding:20px;">
                        <!-- Capability Groups -->
                        <?php
                        $cap_groups = $this->group_capabilities($all_caps);
                        foreach ($cap_groups as $group_name => $group_caps):
                        ?>
                            <div class="bp-cap-group" style="margin-bottom:20px;">
                                <h4 style="margin:0 0 10px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:0.5px;">
                                    <?php echo esc_html($group_name); ?>
                                    <span style="font-weight:normal;color:#999;">(<?php echo count($group_caps); ?>)</span>
                                </h4>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                    <?php foreach ($group_caps as $cap):
                                        $has_cap = in_array($cap, $role_caps);
                                    ?>
                                        <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:<?php echo $has_cap ? '#e8f5e9' : '#f5f5f5'; ?>;border-radius:4px;font-size:12px;cursor:pointer;transition:background 0.2s;">
                                            <input type="checkbox" class="bp-cap-checkbox" data-cap="<?php echo esc_attr($cap); ?>" <?php checked($has_cap); ?> style="margin:0;">
                                            <span style="font-family:monospace;"><?php echo esc_html($cap); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Add Custom Capability -->
                        <div style="margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
                            <div style="display:flex;gap:12px;align-items:center;">
                                <input type="text" class="bp-add-cap-input" placeholder="<?php _e('new_capability_name', 'starter-dashboard'); ?>" style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-family:monospace;">
                                <button type="button" class="button bp-add-cap-btn"><?php _e('Add Capability', 'starter-dashboard'); ?></button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div style="margin-top:20px;display:flex;justify-content:space-between;align-items:center;">
                            <button type="button" class="button button-primary bp-save-role-btn" data-role="<?php echo esc_attr($role_slug); ?>">
                                <?php _e('Save Changes', 'starter-dashboard'); ?>
                            </button>
                            <?php if (!$is_builtin): ?>
                                <button type="button" class="button bp-delete-role-btn" data-role="<?php echo esc_attr($role_slug); ?>" style="color:#dc3545;">
                                    <?php _e('Delete Role', 'starter-dashboard'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Get all capabilities from all roles
     */
    private function get_all_capabilities() {
        global $wp_roles;
        $all_caps = [];

        foreach ($wp_roles->roles as $role) {
            foreach (array_keys($role['capabilities']) as $cap) {
                $all_caps[$cap] = true;
            }
        }

        // Add common WordPress capabilities that might not be assigned
        $common_caps = [
            'read', 'edit_posts', 'delete_posts', 'publish_posts',
            'edit_pages', 'delete_pages', 'publish_pages',
            'edit_others_posts', 'edit_others_pages',
            'delete_others_posts', 'delete_others_pages',
            'edit_published_posts', 'edit_published_pages',
            'delete_published_posts', 'delete_published_pages',
            'edit_private_posts', 'edit_private_pages',
            'delete_private_posts', 'delete_private_pages',
            'read_private_posts', 'read_private_pages',
            'manage_categories', 'edit_themes', 'manage_options',
            'moderate_comments', 'manage_links', 'upload_files',
            'import', 'export', 'unfiltered_html',
            'edit_dashboard', 'update_core', 'update_plugins',
            'update_themes', 'install_plugins', 'install_themes',
            'delete_themes', 'delete_plugins', 'edit_plugins',
            'edit_themes', 'edit_files', 'edit_users',
            'create_users', 'delete_users', 'list_users',
            'promote_users', 'remove_users',
        ];

        foreach ($common_caps as $cap) {
            $all_caps[$cap] = true;
        }

        return array_keys($all_caps);
    }

    /**
     * Group capabilities by type
     */
    private function group_capabilities($caps) {
        $groups = [
            'Posts' => [],
            'Pages' => [],
            'Media' => [],
            'Users' => [],
            'Themes & Plugins' => [],
            'Settings' => [],
            'Custom Post Types' => [],
            'Other' => [],
        ];

        foreach ($caps as $cap) {
            if (strpos($cap, 'post') !== false && strpos($cap, 'page') === false) {
                $groups['Posts'][] = $cap;
            } elseif (strpos($cap, 'page') !== false) {
                $groups['Pages'][] = $cap;
            } elseif (strpos($cap, 'upload') !== false || strpos($cap, 'media') !== false || strpos($cap, 'file') !== false) {
                $groups['Media'][] = $cap;
            } elseif (strpos($cap, 'user') !== false) {
                $groups['Users'][] = $cap;
            } elseif (strpos($cap, 'theme') !== false || strpos($cap, 'plugin') !== false || strpos($cap, 'update') !== false || strpos($cap, 'install') !== false) {
                $groups['Themes & Plugins'][] = $cap;
            } elseif (strpos($cap, 'option') !== false || strpos($cap, 'setting') !== false || strpos($cap, 'manage') !== false) {
                $groups['Settings'][] = $cap;
            } elseif (strpos($cap, 'edit_') === 0 || strpos($cap, 'delete_') === 0 || strpos($cap, 'publish_') === 0 || strpos($cap, 'read_') === 0) {
                $groups['Custom Post Types'][] = $cap;
            } else {
                $groups['Other'][] = $cap;
            }
        }

        // Sort caps within each group and remove empty groups
        foreach ($groups as $name => &$group_caps) {
            if (empty($group_caps)) {
                unset($groups[$name]);
            } else {
                sort($group_caps);
            }
        }

        return $groups;
    }

    /**
     * Get all custom post types with details
     */
    private function get_custom_post_types() {
        $post_types = get_post_types(['_builtin' => false], 'objects');
        $cpts = [];

        // Also add built-in types for reference
        $builtin_types = get_post_types(['_builtin' => true, 'public' => true], 'objects');
        unset($builtin_types['attachment']); // Skip attachment

        foreach (array_merge($builtin_types, $post_types) as $post_type) {
            $count = wp_count_posts($post_type->name);

            // Get associated taxonomies
            $taxonomies = get_object_taxonomies($post_type->name, 'objects');
            $tax_list = [];
            foreach ($taxonomies as $tax) {
                if ($tax->public) {
                    $tax_list[] = [
                        'name' => $tax->name,
                        'label' => $tax->labels->name,
                        'count' => wp_count_terms(['taxonomy' => $tax->name, 'hide_empty' => false]),
                    ];
                }
            }

            // Determine source plugin
            $source = 'WordPress';
            if (!$post_type->_builtin) {
                if (strpos($post_type->name, 'elementor') !== false) {
                    $source = 'Elementor';
                } elseif (strpos($post_type->name, 'acf') !== false) {
                    $source = 'ACF';
                } elseif (strpos($post_type->name, 'woo') !== false || strpos($post_type->name, 'product') !== false || strpos($post_type->name, 'shop') !== false) {
                    $source = 'WooCommerce';
                } elseif (strpos($post_type->name, 'metform') !== false) {
                    $source = 'MetForm';
                } elseif (strpos($post_type->name, 'wpml') !== false) {
                    $source = 'WPML';
                } else {
                    $source = 'Plugin/Theme';
                }
            }

            $cpts[$post_type->name] = [
                'name' => $post_type->name,
                'label' => $post_type->labels->name,
                'singular_label' => $post_type->labels->singular_name,
                'description' => $post_type->description,
                'public' => $post_type->public,
                'show_ui' => $post_type->show_ui,
                'show_in_menu' => $post_type->show_in_menu,
                'hierarchical' => $post_type->hierarchical,
                'has_archive' => $post_type->has_archive,
                'supports' => get_all_post_type_supports($post_type->name),
                'capability_type' => $post_type->capability_type,
                'capabilities' => (array) $post_type->cap,
                'icon' => $post_type->menu_icon ?: 'dashicons-admin-post',
                'builtin' => $post_type->_builtin,
                'source' => $source,
                'count' => [
                    'publish' => $count->publish ?? 0,
                    'draft' => $count->draft ?? 0,
                    'pending' => $count->pending ?? 0,
                    'trash' => $count->trash ?? 0,
                ],
                'taxonomies' => $tax_list,
                'rest_base' => $post_type->rest_base,
                'menu_position' => $post_type->menu_position,
            ];
        }

        return $cpts;
    }

    /**
     * Render Custom Post Types tab
     */
    private function render_cpt_tab($custom_post_types, $roles) {
        $visual_settings = $this->get_visual_settings();
        $visible_cpts = get_option('starter_visible_cpts', ['post', 'page']); // Default: show posts and pages
        if (!is_array($visible_cpts)) $visible_cpts = ['post', 'page'];
        ?>
        <div class="bp-settings__panel">
            <h2 class="bp-settings__panel-title"><?php _e('Custom Post Types', 'starter-dashboard'); ?></h2>
            <p class="bp-settings__panel-desc">
                <?php printf(__('Manage which post types appear as tiles on the %s dashboard. Toggle "Show on Hub" to control visibility.', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
            </p>

            <!-- Summary Stats -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(150px, 1fr));gap:16px;margin-bottom:24px;">
                <?php
                $total_cpts = count(array_filter($custom_post_types, fn($cpt) => !$cpt['builtin']));
                $total_items = array_sum(array_map(fn($cpt) => $cpt['count']['publish'], $custom_post_types));
                $visible_count = count($visible_cpts);
                ?>
                <div style="background:#1C3C8B;color:#fff;padding:20px;border-radius:8px;text-align:center;">
                    <div style="font-size:28px;font-weight:bold;"><?php echo $total_cpts; ?></div>
                    <div style="font-size:12px;opacity:0.8;"><?php _e('Custom Post Types', 'starter-dashboard'); ?></div>
                </div>
                <div style="background:#2ABADE;color:#fff;padding:20px;border-radius:8px;text-align:center;">
                    <div style="font-size:28px;font-weight:bold;"><?php echo $visible_count; ?></div>
                    <div style="font-size:12px;opacity:0.8;"><?php _e('Visible on Hub', 'starter-dashboard'); ?></div>
                </div>
                <div style="background:#0097CD;color:#fff;padding:20px;border-radius:8px;text-align:center;">
                    <div style="font-size:28px;font-weight:bold;"><?php echo number_format_i18n($total_items); ?></div>
                    <div style="font-size:12px;opacity:0.8;"><?php _e('Published Items', 'starter-dashboard'); ?></div>
                </div>
            </div>

            <!-- CPT List -->
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:6%;"><?php _e('Hub', 'starter-dashboard'); ?></th>
                        <th style="width:22%;"><?php _e('Post Type', 'starter-dashboard'); ?></th>
                        <th style="width:10%;"><?php _e('Source', 'starter-dashboard'); ?></th>
                        <th style="width:8%;"><?php _e('Items', 'starter-dashboard'); ?></th>
                        <th style="width:20%;"><?php _e('Taxonomies', 'starter-dashboard'); ?></th>
                        <th style="width:18%;"><?php _e('Features', 'starter-dashboard'); ?></th>
                        <th style="width:16%;"><?php _e('Actions', 'starter-dashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($custom_post_types as $cpt):
                        $is_visible = in_array($cpt['name'], $visible_cpts);
                    ?>
                        <tr data-cpt="<?php echo esc_attr($cpt['name']); ?>" class="<?php echo $is_visible ? 'bp-cpt-visible' : ''; ?>">
                            <td style="text-align:center;">
                                <label class="bp-toggle-switch" title="<?php printf(esc_attr__('Show on %s', 'starter-dashboard'), esc_attr($visual_settings['hub_name'])); ?>">
                                    <input type="checkbox" class="bp-cpt-visible-toggle" data-cpt="<?php echo esc_attr($cpt['name']); ?>" <?php checked($is_visible); ?>>
                                    <span class="bp-toggle-slider"></span>
                                </label>
                            </td>
                            <td>
                                <?php
                                $icon = $cpt['icon'];
                                if (strpos($icon, 'dashicons-') === 0): ?>
                                    <?php echo self::render_icon($icon, 18, '#1C3C8B'); ?>
                                <?php endif; ?>
                                <strong><?php echo esc_html($cpt['label']); ?></strong>
                                <br>
                                <code style="font-size:11px;color:#666;"><?php echo esc_html($cpt['name']); ?></code>
                                <?php if ($cpt['builtin']): ?>
                                    <span style="background:#2ABADE;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;margin-left:4px;"><?php _e('Core', 'starter-dashboard'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size:12px;"><?php echo esc_html($cpt['source']); ?></span>
                            </td>
                            <td>
                                <span style="font-weight:600;color:#1C3C8B;"><?php echo number_format_i18n($cpt['count']['publish']); ?></span>
                                <?php if ($cpt['count']['draft'] > 0): ?>
                                    <br><span style="font-size:11px;color:#999;"><?php echo $cpt['count']['draft']; ?> <?php _e('drafts', 'starter-dashboard'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cpt['taxonomies'])): ?>
                                    <?php foreach ($cpt['taxonomies'] as $tax): ?>
                                        <span style="display:inline-block;background:#f0f0f0;padding:2px 8px;border-radius:3px;font-size:11px;margin:2px;">
                                            <?php echo esc_html($tax['label']); ?>
                                            <span style="color:#999;">(<?php echo $tax['count']; ?>)</span>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#999;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $features = [];
                                $supports = $cpt['supports'];
                                if (!empty($supports['title'])) $features[] = 'title';
                                if (!empty($supports['editor'])) $features[] = 'editor';
                                if (!empty($supports['thumbnail'])) $features[] = 'thumb';
                                if (!empty($supports['custom-fields'])) $features[] = 'cf';
                                if (!empty($supports['revisions'])) $features[] = 'rev';
                                if ($cpt['has_archive']) $features[] = 'archive';
                                if ($cpt['hierarchical']) $features[] = 'hier';
                                ?>
                                <span style="font-size:11px;color:#666;"><?php echo implode(', ', $features); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('edit.php?post_type=' . $cpt['name']); ?>" class="button button-small">
                                    <?php _e('View', 'starter-dashboard'); ?>
                                </a>
                                <button type="button" class="button button-small bp-show-cpt-caps" data-cpt="<?php echo esc_attr($cpt['name']); ?>" style="margin-left:4px;">
                                    <?php _e('Caps', 'starter-dashboard'); ?>
                                </button>
                            </td>
                        </tr>
                        <!-- Capabilities Row (hidden by default) -->
                        <tr class="bp-cpt-caps-row" data-cpt="<?php echo esc_attr($cpt['name']); ?>" style="display:none;background:#f9f9f9;">
                            <td colspan="7" style="padding:16px 20px;">
                                <strong style="display:block;margin-bottom:10px;"><?php _e('Capabilities for', 'starter-dashboard'); ?> <?php echo esc_html($cpt['label']); ?>:</strong>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                    <?php foreach ($cpt['capabilities'] as $cap_name => $cap_value): ?>
                                        <code style="background:#fff;padding:4px 8px;border:1px solid #ddd;border-radius:3px;font-size:11px;">
                                            <?php echo esc_html($cap_name); ?>: <?php echo esc_html($cap_value); ?>
                                        </code>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Info Box -->
            <div class="bp-settings__info-box" style="margin-top:24px;">
                <easier-icon name="eye" variant="twotone" size="20" color="currentColor"></easier-icon>
                <div>
                    <p style="margin:0 0 8px;"><strong><?php _e('Dashboard Visibility', 'starter-dashboard'); ?></strong></p>
                    <p style="margin:0;font-size:13px;">
                        <?php printf(__('Toggle the switch in the "Hub" column to show or hide post types as tiles on the %s dashboard. Core types (Posts, Pages) and Custom Post Types will appear in separate tabs.', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Addons page (standalone)
     */
    public function render_addons_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $addon_loader = starter_addon_loader();
        $categories = $addon_loader->get_addons_by_category();
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="wrap bp-settings bp-addons-page">
            <h1 style="display:none;"><?php printf(__('%s Addons', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?></h1>

            <div class="bp-settings__header" style="--header-primary: <?php echo esc_attr($visual_settings['primary_color']); ?>;">
                <div class="bp-settings__header-content">
                    <img src="<?php echo esc_url($visual_settings['logo_url']); ?>" alt="<?php echo esc_attr($visual_settings['hub_name']); ?>" class="bp-settings__header-logo" />
                    <div class="bp-settings__header-text">
                        <h2><?php _e('Addons', 'starter-dashboard'); ?></h2>
                        <p><?php _e('Enable or disable additional features', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=starter-dashboard'); ?>" class="bp-settings__header-btn">
                    <easier-icon name="arrow-left-01" variant="twotone" size="18" color="currentColor"></easier-icon>
                    <?php printf(__('Back to %s', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
                </a>
            </div>

            <?php foreach ($categories as $cat_id => $category): ?>
                <?php if (!empty($category['addons'])): ?>
                <div class="bp-addons-category">
                    <h3 class="bp-addons-category__title">
                        <easier-icon name="<?php echo esc_attr($category['icon']); ?>" variant="twotone" size="20" stroke-color="#1C3C8B" color="#1C3C8B"></easier-icon>
                        <?php echo esc_html($category['label']); ?>
                    </h3>

                    <div class="bp-addons-grid">
                        <?php foreach ($category['addons'] as $addon_id => $addon):
                            $is_active = $addon_loader->is_addon_active($addon_id);
                        ?>
                        <div class="bp-addon-card <?php echo $is_active ? 'bp-addon-card--active' : ''; ?>" data-addon="<?php echo esc_attr($addon_id); ?>">
                            <div class="bp-addon-card__header">
                                <div class="bp-addon-card__icon">
                                    <easier-icon name="<?php echo esc_attr($addon['icon']); ?>" variant="twotone" size="24" stroke-color="#fff" color="#fff"></easier-icon>
                                </div>
                                <div class="bp-addon-card__info">
                                    <h4 class="bp-addon-card__title"><?php echo esc_html($addon['name']); ?></h4>
                                    <span class="bp-addon-card__version">v<?php echo esc_html($addon['version']); ?></span>
                                </div>
                                <label class="bp-addon-card__toggle">
                                    <input type="checkbox"
                                           class="bp-addon-toggle"
                                           data-addon="<?php echo esc_attr($addon_id); ?>"
                                           <?php checked($is_active); ?>>
                                    <span class="bp-toggle-slider"></span>
                                </label>
                            </div>

                            <p class="bp-addon-card__desc"><?php echo esc_html($addon['description']); ?></p>

                            <?php if (!empty($addon['has_readme'])): ?>
                            <div class="bp-addon-card__footer">
                                <button type="button" class="bp-addon-readme-btn" data-addon="<?php echo esc_attr($addon_id); ?>" title="<?php _e('View documentation', 'starter-dashboard'); ?>">
                                    <easier-icon name="book-02" variant="twotone" size="16" stroke-color="currentColor" color="currentColor"></easier-icon>
                                    <?php _e('Documentation', 'starter-dashboard'); ?>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>

        <!-- Addon Settings Modal -->
        <div id="bp-addon-settings-modal" class="bp-modal" style="display:none;">
            <div class="bp-modal__backdrop"></div>
            <div class="bp-modal__container" style="max-width:600px;">
                <div class="bp-modal__header">
                    <h3 class="bp-modal__title"><?php _e('Addon Settings', 'starter-dashboard'); ?></h3>
                    <button type="button" class="bp-modal__close">&times;</button>
                </div>
                <div class="bp-modal__body" id="bp-addon-settings-content">
                    <!-- Settings loaded via AJAX -->
                </div>
                <div class="bp-modal__footer">
                    <button type="button" class="button bp-modal__cancel"><?php _e('Cancel', 'starter-dashboard'); ?></button>
                    <button type="button" class="button button-primary bp-addon-save-settings"><?php _e('Save Settings', 'starter-dashboard'); ?></button>
                </div>
            </div>
        </div>

        <!-- Addon README Modal -->
        <div id="bp-addon-readme-modal" class="bp-modal" style="display:none;">
            <div class="bp-modal__backdrop"></div>
            <div class="bp-modal__container bp-modal__container--readme" style="max-width:700px;">
                <div class="bp-modal__header">
                    <h3 class="bp-modal__title" id="bp-readme-title"><?php _e('Documentation', 'starter-dashboard'); ?></h3>
                    <button type="button" class="bp-modal__close">&times;</button>
                </div>
                <div class="bp-modal__body bp-readme-content" id="bp-readme-content">
                    <!-- README loaded via AJAX -->
                </div>
            </div>
        </div>

        <style>
            /* Modal Base Styles */
            .bp-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
            }

            .bp-modal__backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(4px);
            }

            .bp-modal__container {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 90%;
                max-width: 600px;
                max-height: 80vh;
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
                display: flex;
                flex-direction: column;
                animation: bp-modal-slide-in 0.2s ease-out;
            }

            @keyframes bp-modal-slide-in {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .bp-modal__header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid #e5e7eb;
            }

            .bp-modal__title {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: #1C3C8B;
            }

            .bp-modal__close {
                background: none;
                border: none;
                font-size: 24px;
                color: #999;
                cursor: pointer;
                padding: 0;
                line-height: 1;
                transition: color 0.2s;
            }

            .bp-modal__close:hover {
                color: #333;
            }

            .bp-modal__body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }

            .bp-modal__footer {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                padding: 16px 20px;
                border-top: 1px solid #e5e7eb;
            }

            .bp-addons-panel { }

            .bp-addons-category {
                margin-bottom: 32px;
            }

            .bp-addons-category__title {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 16px;
                font-weight: 600;
                color: #1C3C8B;
                margin: 0 0 16px;
                padding-bottom: 8px;
                border-bottom: 2px solid #e5e7eb;
            }

            .bp-addons-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 20px;
            }

            .bp-addon-card {
                background: #fff;
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                padding: 20px;
                transition: all 0.2s ease;
            }

            .bp-addon-card--active {
                border-color: #2ABADE;
                box-shadow: 0 0 0 1px rgba(42, 186, 222, 0.2);
            }

            .bp-addon-card__header {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                margin-bottom: 12px;
            }

            .bp-addon-card__icon {
                width: 48px;
                height: 48px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #1C3C8B;
                border-radius: 10px;
                flex-shrink: 0;
            }

            .bp-addon-card__icon easier-icon {
                --ei-stroke-color: #fff;
                --ei-color: #fff;
            }

            .bp-addon-card__info {
                flex: 1;
                min-width: 0;
            }

            .bp-addon-card__title {
                margin: 0 0 4px;
                font-size: 15px;
                font-weight: 600;
                color: #1e1e1e;
            }

            .bp-addon-card__version {
                font-size: 11px;
                color: #757575;
            }

            .bp-addon-card__toggle {
                position: relative;
                width: 44px;
                height: 24px;
                flex-shrink: 0;
            }

            .bp-addon-card__toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .bp-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .3s;
                border-radius: 24px;
            }

            .bp-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .3s;
                border-radius: 50%;
            }

            .bp-addon-card__toggle input:checked + .bp-toggle-slider {
                background-color: #2ABADE;
            }

            .bp-addon-card__toggle input:checked + .bp-toggle-slider:before {
                transform: translateX(20px);
            }

            .bp-addon-card__desc {
                font-size: 13px;
                color: #555;
                line-height: 1.5;
                margin: 0;
            }

            .bp-addon-card__footer {
                margin-top: 16px;
                padding-top: 12px;
                border-top: 1px solid #e5e7eb;
            }

            .bp-addon-settings-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            .bp-addon-settings-btn easier-icon {
                vertical-align: middle;
            }

            /* Modal overrides */
            #bp-addon-settings-modal .bp-modal__body {
                max-height: 60vh;
                overflow-y: auto;
            }

            /* README Button */
            .bp-addon-readme-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 12px;
                background: #f0f0f0;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 500;
                color: #555;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .bp-addon-readme-btn:hover {
                background: #1C3C8B;
                border-color: #1C3C8B;
                color: #fff;
            }

            /* README Modal */
            .bp-readme-content {
                max-height: 70vh;
                overflow-y: auto;
                padding: 20px !important;
                font-size: 14px;
                line-height: 1.7;
            }

            .bp-readme-content h2 {
                font-size: 20px;
                font-weight: 600;
                margin: 0 0 16px;
                padding-bottom: 8px;
                border-bottom: 2px solid #e5e7eb;
                color: #1C3C8B;
            }

            .bp-readme-content h3 {
                font-size: 16px;
                font-weight: 600;
                margin: 24px 0 12px;
                color: #1e1e1e;
            }

            .bp-readme-content h4 {
                font-size: 14px;
                font-weight: 600;
                margin: 20px 0 10px;
                color: #333;
            }

            .bp-readme-content p {
                margin: 0 0 12px;
            }

            .bp-readme-content code {
                background: #f5f5f5;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 13px;
                font-family: monospace;
            }

            .bp-readme-content pre {
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 16px;
                border-radius: 8px;
                overflow-x: auto;
                margin: 16px 0;
            }

            .bp-readme-content pre code {
                background: none;
                padding: 0;
                color: inherit;
            }

            .bp-readme-content ul {
                margin: 0 0 16px 20px;
                padding: 0;
            }

            .bp-readme-content li {
                margin-bottom: 6px;
            }

            .bp-readme-content a {
                color: #1C3C8B;
                text-decoration: none;
            }

            .bp-readme-content a:hover {
                text-decoration: underline;
            }

            .bp-readme-content strong {
                font-weight: 600;
            }

            .bp-readme-content table {
                width: 100%;
                border-collapse: collapse;
                margin: 16px 0;
                font-size: 13px;
            }

            .bp-readme-content th,
            .bp-readme-content td {
                padding: 10px 12px;
                border: 1px solid #e5e7eb;
                text-align: left;
            }

            .bp-readme-content th {
                background: #f8f9fa;
                font-weight: 600;
                color: #1C3C8B;
            }

            .bp-readme-content tr:nth-child(even) td {
                background: #fafafa;
            }

            .bp-readme-content blockquote {
                margin: 16px 0;
                padding: 12px 16px;
                border-left: 4px solid #2ABADE;
                background: #f0f9ff;
                color: #555;
            }

            .bp-readme-content blockquote p {
                margin: 0;
            }

            .bp-readme-content hr {
                border: none;
                border-top: 2px solid #e5e7eb;
                margin: 24px 0;
            }

            /* Addon settings form styles */
            .bp-addon-settings__section {
                margin-bottom: 24px;
            }

            .bp-addon-settings__section h4 {
                margin: 0 0 8px;
                font-size: 14px;
                font-weight: 600;
                color: #1e1e1e;
            }

            .bp-addon-settings__field {
                margin-bottom: 16px;
            }

            .bp-addon-settings__field label {
                display: block;
                margin-bottom: 6px;
                font-weight: 500;
                font-size: 13px;
            }

            .bp-addon-settings__field input[type="text"],
            .bp-addon-settings__field input[type="url"],
            .bp-addon-settings__field input[type="password"],
            .bp-addon-settings__field textarea,
            .bp-addon-settings__field select {
                width: 100%;
            }

            .bp-addon-settings__field .description {
                margin-top: 4px;
                font-size: 12px;
                color: #757575;
            }

            .bp-image-upload-field {
                display: flex;
                gap: 8px;
                align-items: flex-start;
            }

            .bp-image-upload-field input {
                flex: 1;
            }
        </style>
        </div>
        <?php
    }

    /**
     * AJAX handler for saving menu settings
     */
    public function ajax_save_menu_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $hidden_menus = isset($_POST['hidden_menus']) ? $_POST['hidden_menus'] : [];

        // Sanitize the data
        $sanitized = [];
        if (is_array($hidden_menus)) {
            foreach ($hidden_menus as $role => $menus) {
                $role = sanitize_text_field($role);
                $sanitized[$role] = array_map('sanitize_text_field', (array) $menus);
            }
        }

        update_option('starter_hidden_menus', $sanitized);

        wp_send_json_success(['message' => __('Menu settings saved', 'starter-dashboard')]);
    }

    /**
     * AJAX handler for saving additional elements users
     */
    public function ajax_save_additional_users() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];

        // Sanitize user IDs
        $sanitized = array_map('absint', (array) $user_ids);
        $sanitized = array_filter($sanitized); // Remove zeros

        update_option('starter_additional_elements_users', $sanitized);

        wp_send_json_success(['message' => __('User access saved', 'starter-dashboard')]);
    }

    /**
     * AJAX handler for saving visual settings users
     */
    public function ajax_save_visual_users() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];

        // Sanitize user IDs
        $sanitized = array_map('absint', (array) $user_ids);
        $sanitized = array_filter($sanitized); // Remove zeros

        update_option('starter_visual_settings_users', $sanitized);

        wp_send_json_success(['message' => __('Access settings saved', 'starter-dashboard')]);
    }

    /**
     * AJAX handler for saving visual settings
     */
    public function ajax_save_visual_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!$this->can_user_edit_visual_settings()) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $settings = [
            'hub_name' => isset($_POST['hub_name']) ? sanitize_text_field($_POST['hub_name']) : '',
            'logo_url' => isset($_POST['logo_url']) ? esc_url_raw($_POST['logo_url']) : '',
            'primary_color' => isset($_POST['primary_color']) ? sanitize_hex_color($_POST['primary_color']) : '',
            'secondary_color' => isset($_POST['secondary_color']) ? sanitize_hex_color($_POST['secondary_color']) : '',
            'accent_color' => isset($_POST['accent_color']) ? sanitize_hex_color($_POST['accent_color']) : '',
            'show_stats' => isset($_POST['show_stats']) ? (bool) $_POST['show_stats'] : true,
        ];

        update_option('starter_visual_settings', $settings);

        wp_send_json_success(['message' => __('Visual settings saved', 'starter-dashboard')]);
    }

    /**
     * AJAX handler for fetching recent activity by post types
     */
    public function ajax_get_recent_activity() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_dashboard_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        $post_types = isset($_POST['post_types']) ? array_map('sanitize_key', (array) $_POST['post_types']) : [];

        // Validate post types exist
        $valid_types = [];
        foreach ($post_types as $pt) {
            if (post_type_exists($pt)) {
                $valid_types[] = $pt;
            }
        }

        if (empty($valid_types)) {
            wp_send_json_success([
                'html'  => '<p class="bp-dashboard__empty">' . __('No recent posts.', 'starter-dashboard') . '</p>',
                'count' => 0,
            ]);
            return;
        }

        // Query recent posts - Version 2 only
        $posts = get_posts([
            'post_type'      => $valid_types,
            'posts_per_page' => 8,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'meta_key'       => '_bp_content_version',
            'meta_value'     => '2',
        ]);

        if (empty($posts)) {
            wp_send_json_success([
                'html'  => '<p class="bp-dashboard__empty">' . __('No recent posts.', 'starter-dashboard') . '</p>',
                'count' => 0,
            ]);
            return;
        }

        // Build HTML
        ob_start();
        echo '<div class="bp-dashboard__recent-list">';
        foreach ($posts as $post) {
            $type_obj = get_post_type_object($post->post_type);
            $item = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'type'       => $post->post_type,
                'type_label' => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
                'date'       => get_the_modified_date('M j, Y', $post),
                'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                'color'      => $this->get_type_color($post->post_type),
            ];
            $this->render_recent_item($item);
        }
        echo '</div>';
        $html = ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'count' => count($posts),
        ]);
    }

    /**
     * Check if current user can edit visual settings
     */
    private function can_user_edit_visual_settings() {
        $allowed_users = get_option('starter_visual_settings_users', []);

        // If no users selected, all administrators can access
        if (empty($allowed_users)) {
            return current_user_can('manage_options');
        }

        // Check if current user is in allowed list
        return in_array(get_current_user_id(), (array) $allowed_users);
    }

    /**
     * Get visual settings with Elementor fallbacks
     */
    private function get_visual_settings() {
        $saved_settings = get_option('starter_visual_settings', []);
        $elementor_defaults = $this->get_elementor_global_settings();

        return [
            'hub_name' => !empty($saved_settings['hub_name']) ? $saved_settings['hub_name'] : 'Dashboard Hub',
            'logo_url' => !empty($saved_settings['logo_url']) ? $saved_settings['logo_url'] : $elementor_defaults['logo_url'],
            'primary_color' => !empty($saved_settings['primary_color']) ? $saved_settings['primary_color'] : $elementor_defaults['primary_color'],
            'secondary_color' => !empty($saved_settings['secondary_color']) ? $saved_settings['secondary_color'] : $elementor_defaults['secondary_color'],
            'accent_color' => !empty($saved_settings['accent_color']) ? $saved_settings['accent_color'] : $elementor_defaults['accent_color'],
            'show_stats' => isset($saved_settings['show_stats']) ? (bool) $saved_settings['show_stats'] : true,
        ];
    }

    /**
     * Add Content Version metabox to all post types
     */
    public function add_content_version_metabox() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'starter_content_version',
                __('Content Version', 'starter-dashboard'),
                [$this, 'render_content_version_metabox'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Render Content Version metabox
     */
    public function render_content_version_metabox($post) {
        $version = get_post_meta($post->ID, '_bp_content_version', true);
        wp_nonce_field('bp_content_version_nonce', 'bp_content_version_nonce');
        ?>
        <style>
            .bp-version-select {
                display: flex;
                gap: 10px;
                margin: 10px 0;
            }
            .bp-version-option {
                flex: 1;
                padding: 12px 8px;
                text-align: center;
                border: 2px solid #ddd;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                background: #fff;
            }
            .bp-version-option:hover {
                border-color: #2ABADE;
            }
            .bp-version-option.selected {
                border-color: #1C3C8B;
                background: #1C3C8B;
                color: #fff;
            }
            .bp-version-option input {
                display: none;
            }
            .bp-version-label {
                font-weight: 600;
                font-size: 13px;
            }
        </style>
        <div class="bp-version-select">
            <label class="bp-version-option <?php echo $version !== '2' ? 'selected' : ''; ?>">
                <input type="radio" name="bp_content_version" value="1" <?php checked($version !== '2'); ?> />
                <span class="bp-version-label">Version 1</span>
            </label>
            <label class="bp-version-option <?php echo $version === '2' ? 'selected' : ''; ?>">
                <input type="radio" name="bp_content_version" value="2" <?php checked($version, '2'); ?> />
                <span class="bp-version-label">Version 2</span>
            </label>
        </div>
        <p class="description"><?php _e('Mark this content as Version 2 to show it in the Hub filtered view.', 'starter-dashboard'); ?></p>
        <script>
        jQuery(function($) {
            $('.bp-version-option').on('click', function() {
                $('.bp-version-option').removeClass('selected');
                $(this).addClass('selected');
            });
        });
        </script>
        <?php
    }

    /**
     * Save Content Version metabox
     */
    public function save_content_version_metabox($post_id) {
        if (!isset($_POST['bp_content_version_nonce']) ||
            !wp_verify_nonce($_POST['bp_content_version_nonce'], 'bp_content_version_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $version = isset($_POST['bp_content_version']) ? sanitize_text_field($_POST['bp_content_version']) : '1';
        update_post_meta($post_id, '_bp_content_version', $version);
    }

    /**
     * One-time migration: Mark all content by user "Alex" as Version 2
     * This runs once and sets an option to prevent re-running
     */
    public function migrate_alex_content_to_version2() {
        // Check if migration already done
        if (get_option('bp_alex_content_migrated_v2')) {
            return;
        }

        // Find user Alex by login or display name
        $alex_user = get_user_by('login', 'alex');
        if (!$alex_user) {
            $alex_user = get_user_by('login', 'Alex');
        }
        if (!$alex_user) {
            // Try by display name
            $users = get_users([
                'search' => '*Alex*',
                'search_columns' => ['display_name', 'user_login'],
                'number' => 1
            ]);
            if (!empty($users)) {
                $alex_user = $users[0];
            }
        }

        if (!$alex_user) {
            // If no Alex found, mark as done to prevent repeated queries
            update_option('bp_alex_content_migrated_v2', true);
            return;
        }

        $alex_id = $alex_user->ID;

        // Get all public post types
        $post_types = get_post_types(['public' => true], 'names');

        // Query all posts by Alex
        $args = [
            'post_type'      => array_values($post_types),
            'author'         => $alex_id,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ];

        $posts = get_posts($args);

        // Update each post to Version 2
        foreach ($posts as $post_id) {
            update_post_meta($post_id, '_bp_content_version', '2');
        }

        // Mark migration as complete
        update_option('bp_alex_content_migrated_v2', true);
    }

    /**
     * Migrate all Case Studies and Press Releases to Version 2
     */
    public function migrate_cpt_content_to_version2() {
        // Check if migration already done
        if (get_option('bp_cpt_content_migrated_v2')) {
            return;
        }

        // Post types to migrate
        $cpt_types = ['case-study', 'case_study', 'casestudy', 'press-release', 'press_release', 'pressrelease'];

        // Filter to only existing post types
        $existing_types = [];
        foreach ($cpt_types as $pt) {
            if (post_type_exists($pt)) {
                $existing_types[] = $pt;
            }
        }

        if (empty($existing_types)) {
            // No matching post types found, mark as done
            update_option('bp_cpt_content_migrated_v2', true);
            return;
        }

        // Query all posts from these CPTs
        $posts = get_posts([
            'post_type'      => $existing_types,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        // Update each post to Version 2
        foreach ($posts as $post_id) {
            update_post_meta($post_id, '_bp_content_version', '2');
        }

        // Mark migration as complete
        update_option('bp_cpt_content_migrated_v2', true);
    }

    /**
     * Migrate all public post types (including pages and posts) to Version 2
     * This ensures all content shows up when filtering by bp_version=2
     */
    public function migrate_all_public_posts_to_version2() {
        // Check if migration already done
        if (get_option('bp_all_posts_migrated_v2')) {
            return;
        }

        // Get all public post types
        $public_post_types = get_post_types(['public' => true], 'names');

        if (empty($public_post_types)) {
            update_option('bp_all_posts_migrated_v2', true);
            return;
        }

        // Query all posts without _bp_content_version meta
        global $wpdb;

        $post_types_placeholder = implode("','", array_map('esc_sql', $public_post_types));

        // Get posts that don't have _bp_content_version set
        $posts_without_version = $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type IN ('{$post_types_placeholder}')
            AND p.post_status IN ('publish', 'draft', 'pending', 'private')
            AND pm.meta_id IS NULL
        ", '_bp_content_version'));

        // Update each post to Version 2
        foreach ($posts_without_version as $post_id) {
            update_post_meta($post_id, '_bp_content_version', '2');
        }

        // Mark migration as complete
        update_option('bp_all_posts_migrated_v2', true);
    }

    /**
     * Filter posts list by content version (bp_version URL parameter)
     */
    public function filter_posts_by_content_version($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return;
        }

        // Check for bp_version parameter
        if (isset($_GET['bp_version']) && $_GET['bp_version'] !== '') {
            $version = sanitize_text_field($_GET['bp_version']);

            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key'     => '_bp_content_version',
                'value'   => $version,
                'compare' => '='
            ];
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Add Version filter dropdown to posts list
     */
    public function add_version_filter_dropdown($post_type) {
        $post_types = get_post_types(['public' => true], 'names');

        if (!in_array($post_type, $post_types)) {
            return;
        }

        $current = isset($_GET['bp_version']) ? $_GET['bp_version'] : '';
        ?>
        <select name="bp_version" id="bp_version_filter">
            <option value=""><?php _e('All Versions', 'starter-dashboard'); ?></option>
            <option value="2" <?php selected($current, '2'); ?>><?php _e('Version 2', 'starter-dashboard'); ?></option>
            <option value="1" <?php selected($current, '1'); ?>><?php _e('Version 1', 'starter-dashboard'); ?></option>
        </select>
        <?php
    }

    /**
     * Add "Version 2" filter link to views (replaces Mine)
     */
    public function add_version2_filter_link($views) {
        global $typenow;

        // Remove Mine if exists
        if (isset($views['mine'])) {
            unset($views['mine']);
        }

        // Count Version 2 posts
        $count = new WP_Query([
            'post_type'      => $typenow,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_bp_content_version',
                    'value'   => '2',
                    'compare' => '='
                ]
            ]
        ]);
        $version2_count = $count->found_posts;

        // Check if currently filtering by version 2
        $current = isset($_GET['bp_version']) && $_GET['bp_version'] === '2' ? 'current' : '';

        // Build URL
        $url = admin_url('edit.php?post_type=' . $typenow . '&bp_version=2');

        // Add Version 2 link after "All"
        $version2_link = '<a href="' . esc_url($url) . '" class="' . $current . '">' .
                         __('Version 2', 'starter-dashboard') .
                         ' <span class="count">(' . $version2_count . ')</span></a>';

        // Insert after 'all'
        $new_views = [];
        foreach ($views as $key => $view) {
            $new_views[$key] = $view;
            if ($key === 'all') {
                $new_views['version2'] = $version2_link;
            }
        }

        return $new_views;
    }

    /**
     * Register version filter for all public CPTs
     */
    public function register_version_filters_for_all_cpts() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter('views_edit-' . $post_type, [$this, 'add_version2_filter_link']);
        }
    }

    /**
     * Register bulk actions for all public post types
     */
    public function register_bulk_actions_for_all_cpts() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_filter('bulk_actions-edit-' . $post_type, [$this, 'add_version_bulk_actions']);
            add_filter('handle_bulk_actions-edit-' . $post_type, [$this, 'handle_version_bulk_actions'], 10, 3);
        }
    }

    /**
     * Add Version bulk actions to dropdown
     */
    public function add_version_bulk_actions($bulk_actions) {
        $bulk_actions['set_version_2'] = __('Set as Version 2', 'starter-dashboard');
        $bulk_actions['set_version_1'] = __('Set as Version 1', 'starter-dashboard');
        return $bulk_actions;
    }

    /**
     * Handle Version bulk actions
     */
    public function handle_version_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action === 'set_version_2') {
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_bp_content_version', '2');
            }
            $redirect_to = add_query_arg('bp_version_updated', count($post_ids), $redirect_to);
            $redirect_to = add_query_arg('bp_version_set', '2', $redirect_to);
        } elseif ($action === 'set_version_1') {
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_bp_content_version', '1');
            }
            $redirect_to = add_query_arg('bp_version_updated', count($post_ids), $redirect_to);
            $redirect_to = add_query_arg('bp_version_set', '1', $redirect_to);
        }

        return $redirect_to;
    }

    /**
     * Set default Version 2 for new posts
     */
    public function set_default_content_version($post_id, $post, $update) {
        // Only for new posts, not updates
        if ($update) {
            return;
        }

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Only for public post types
        $post_type_obj = get_post_type_object($post->post_type);
        if (!$post_type_obj || !$post_type_obj->public) {
            return;
        }

        // Set default Version 2 if not already set
        $existing = get_post_meta($post_id, '_bp_content_version', true);
        if (empty($existing)) {
            update_post_meta($post_id, '_bp_content_version', '2');
        }
    }

    /**
     * Show admin notice after bulk version update
     */
    public function bulk_version_admin_notice() {
        if (!isset($_GET['bp_version_updated']) || !isset($_GET['bp_version_set'])) {
            return;
        }

        $count = intval($_GET['bp_version_updated']);
        $version = sanitize_text_field($_GET['bp_version_set']);

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            sprintf(
                _n(
                    '%d post updated to Version %s.',
                    '%d posts updated to Version %s.',
                    $count,
                    'starter-dashboard'
                ),
                $count,
                $version
            )
        );
    }

    /**
     * AJAX handler for saving role capabilities
     */
    public function ajax_save_role_caps() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $role_slug = isset($_POST['role']) ? sanitize_key($_POST['role']) : '';
        $capabilities = isset($_POST['capabilities']) ? $_POST['capabilities'] : [];

        if (empty($role_slug)) {
            wp_send_json_error(['message' => __('Invalid role', 'starter-dashboard')], 400);
            return;
        }

        $role = get_role($role_slug);
        if (!$role) {
            wp_send_json_error(['message' => __('Role not found', 'starter-dashboard')], 404);
            return;
        }

        // Sanitize capabilities array
        $new_caps = [];
        if (is_array($capabilities)) {
            foreach ($capabilities as $cap) {
                $new_caps[sanitize_key($cap)] = true;
            }
        }

        // Get current capabilities
        $current_caps = array_keys(array_filter($role->capabilities));

        // Remove capabilities that are no longer selected
        foreach ($current_caps as $cap) {
            if (!isset($new_caps[$cap])) {
                $role->remove_cap($cap);
            }
        }

        // Add new capabilities
        foreach ($new_caps as $cap => $grant) {
            if (!in_array($cap, $current_caps)) {
                $role->add_cap($cap, true);
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Capabilities saved for %s', 'starter-dashboard'), $role_slug),
            'cap_count' => count($new_caps),
        ]);
    }

    /**
     * AJAX handler for creating a new role
     */
    public function ajax_create_role() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $role_slug = isset($_POST['role_slug']) ? sanitize_key($_POST['role_slug']) : '';
        $role_name = isset($_POST['role_name']) ? sanitize_text_field($_POST['role_name']) : '';
        $clone_from = isset($_POST['clone_from']) ? sanitize_key($_POST['clone_from']) : '';

        if (empty($role_slug) || empty($role_name)) {
            wp_send_json_error(['message' => __('Role slug and name are required', 'starter-dashboard')], 400);
            return;
        }

        // Check if role already exists
        if (get_role($role_slug)) {
            wp_send_json_error(['message' => __('A role with this slug already exists', 'starter-dashboard')], 400);
            return;
        }

        // Get capabilities from clone source
        $capabilities = ['read' => true]; // Default capability
        if (!empty($clone_from)) {
            $source_role = get_role($clone_from);
            if ($source_role) {
                $capabilities = array_filter($source_role->capabilities);
            }
        }

        // Create the new role
        $result = add_role($role_slug, $role_name, $capabilities);

        if ($result === null) {
            wp_send_json_error(['message' => __('Failed to create role', 'starter-dashboard')], 500);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(__('Role "%s" created successfully', 'starter-dashboard'), $role_name),
            'role_slug' => $role_slug,
            'role_name' => $role_name,
            'cap_count' => count($capabilities),
        ]);
    }

    /**
     * AJAX handler for deleting a role
     */
    public function ajax_delete_role() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $role_slug = isset($_POST['role']) ? sanitize_key($_POST['role']) : '';

        if (empty($role_slug)) {
            wp_send_json_error(['message' => __('Invalid role', 'starter-dashboard')], 400);
            return;
        }

        // Prevent deletion of built-in roles
        $builtin_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
        if (in_array($role_slug, $builtin_roles)) {
            wp_send_json_error(['message' => __('Cannot delete built-in WordPress roles', 'starter-dashboard')], 400);
            return;
        }

        // Check if any users have this role
        $users = get_users(['role' => $role_slug]);
        if (!empty($users)) {
            wp_send_json_error([
                'message' => sprintf(__('Cannot delete role. %d users are assigned to this role.', 'starter-dashboard'), count($users)),
            ], 400);
            return;
        }

        // Delete the role
        remove_role($role_slug);

        // Verify deletion
        if (get_role($role_slug)) {
            wp_send_json_error(['message' => __('Failed to delete role', 'starter-dashboard')], 500);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(__('Role "%s" deleted successfully', 'starter-dashboard'), $role_slug),
        ]);
    }

    /**
     * AJAX handler for saving CPT visibility
     */
    public function ajax_save_cpt_visibility() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $cpt = isset($_POST['cpt']) ? sanitize_key($_POST['cpt']) : '';
        $visible = isset($_POST['visible']) ? (bool) $_POST['visible'] : false;

        if (empty($cpt)) {
            wp_send_json_error(['message' => __('Invalid post type', 'starter-dashboard')], 400);
            return;
        }

        // Get current visible CPTs
        $visible_cpts = get_option('starter_visible_cpts', ['post', 'page']);
        if (!is_array($visible_cpts)) {
            $visible_cpts = ['post', 'page'];
        }

        if ($visible) {
            // Add to visible list
            if (!in_array($cpt, $visible_cpts)) {
                $visible_cpts[] = $cpt;
            }
        } else {
            // Remove from visible list
            $visible_cpts = array_diff($visible_cpts, [$cpt]);
        }

        // Re-index array
        $visible_cpts = array_values($visible_cpts);

        update_option('starter_visible_cpts', $visible_cpts);

        wp_send_json_success([
            'message' => $visible
                ? sprintf(__('%s will now appear on the Hub', 'starter-dashboard'), $cpt)
                : sprintf(__('%s hidden from Hub', 'starter-dashboard'), $cpt),
            'visible_count' => count($visible_cpts),
        ]);
    }

    /**
     * AJAX handler for fetching modal items
     */
    public function ajax_get_modal_items() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_dashboard_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        $item_type = isset($_POST['item_type']) ? sanitize_text_field($_POST['item_type']) : '';
        $source_type = isset($_POST['source_type']) ? sanitize_key($_POST['source_type']) : '';
        $template_type = isset($_POST['template_type']) ? sanitize_key($_POST['template_type']) : '';

        $items = [];
        $title = '';
        $full_url = '';

        switch ($item_type) {
            case 'taxonomy':
                $items = $this->get_taxonomy_items_for_modal($source_type);
                $taxonomy = get_taxonomy($source_type);
                $title = $taxonomy ? $taxonomy->labels->name : $source_type;
                $full_url = admin_url('edit-tags.php?taxonomy=' . $source_type);
                break;

            case 'elementor-content':
            case 'elementor-structure':
            case 'elementor-special':
            case 'elementor-theme':
            case 'elementor':
                $items = $this->get_elementor_items_for_modal($template_type, $source_type);
                $title = $this->get_elementor_type_label($template_type);
                $full_url = admin_url('edit.php?post_type=elementor_library' . ($template_type ? '&elementor_library_type=' . $template_type : ''));
                break;

            case 'acf':
                $items = $this->get_acf_items_for_modal($source_type);
                $title = __('ACF Field Groups', 'starter-dashboard');
                $full_url = admin_url('edit.php?post_type=acf-field-group');
                break;

            default:
                wp_send_json_error(['message' => __('Unknown item type', 'starter-dashboard')], 400);
                return;
        }

        // Calculate count (handle grouped data differently)
        $count = 0;
        if (isset($items['grouped']) && $items['grouped']) {
            foreach ($items['groups'] as $group) {
                $count += count($group['items']);
            }
        } else {
            $count = count($items);
        }

        wp_send_json_success([
            'items'    => $items,
            'title'    => $title,
            'full_url' => $full_url,
            'count'    => $count,
        ]);
    }

    /**
     * Get taxonomy terms for modal
     */
    private function get_taxonomy_items_for_modal($taxonomy) {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 50,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            $items[] = [
                'id'    => $term->term_id,
                'title' => $term->name,
                'count' => $term->count,
                'url'   => get_edit_term_link($term->term_id, $taxonomy),
            ];
        }

        return $items;
    }

    /**
     * Get Elementor templates for modal - grouped by location, filtered by post type
     */
    private function get_elementor_items_for_modal($template_type = '', $filter_post_type = '') {
        $args = [
            'post_type'      => 'elementor_library',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ($template_type && $template_type !== 'all') {
            $args['meta_query'] = [
                [
                    'key'   => '_elementor_template_type',
                    'value' => $template_type,
                ],
            ];
        }

        $posts = get_posts($args);
        $grouped = [];

        foreach ($posts as $post) {
            $template_type_meta = get_post_meta($post->ID, '_elementor_template_type', true);
            $conditions = get_post_meta($post->ID, '_elementor_conditions', true);
            $location = $this->parse_elementor_conditions($conditions, $filter_post_type);

            // Skip templates that don't apply to this post type
            if ($filter_post_type && !$location['applies_to_post_type']) {
                continue;
            }

            $item = [
                'id'             => $post->ID,
                'title'          => $post->post_title ?: __('(no title)', 'starter-dashboard'),
                'status'         => $post->post_status,
                'type'           => $template_type_meta,
                'url'            => get_edit_post_link($post->ID, 'raw'),
                'edit_elementor' => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
                'location'       => $location['label'],
                'location_url'   => $location['url'],
            ];

            // Group by location
            $group_key = $location['group'];
            if (!isset($grouped[$group_key])) {
                $grouped[$group_key] = [
                    'label' => $location['group_label'],
                    'items' => [],
                ];
            }
            $grouped[$group_key]['items'][] = $item;
        }

        // Sort groups: specific pages first, then general conditions
        uksort($grouped, function($a, $b) {
            $order = ['specific' => 0, 'post_type' => 1, 'archive' => 2, 'general' => 3, 'none' => 4];
            $a_order = $order[$a] ?? 5;
            $b_order = $order[$b] ?? 5;
            return $a_order - $b_order;
        });

        // Ensure groups is always encoded as object (not array) in JSON
        return [
            'grouped' => true,
            'groups'  => !empty($grouped) ? (object) $grouped : new stdClass(),
        ];
    }

    /**
     * Parse Elementor template conditions to human-readable location
     *
     * @param array  $conditions       Elementor conditions array
     * @param string $filter_post_type If set, determines if template applies to this post type
     * @return array Location info with applies_to_post_type flag
     */
    private function parse_elementor_conditions($conditions, $filter_post_type = '') {
        if (empty($conditions) || !is_array($conditions)) {
            return [
                'label'               => __('Not assigned', 'starter-dashboard'),
                'group'               => 'none',
                'group_label'         => __('Not Assigned', 'starter-dashboard'),
                'url'                 => '',
                'applies_to_post_type' => empty($filter_post_type), // If no filter, include unassigned
            ];
        }

        $locations = [];
        $group = 'general';
        $group_label = __('General', 'starter-dashboard');
        $url = '';
        $applies_to_post_type = false;

        foreach ($conditions as $condition) {
            $parts = explode('/', $condition);
            $type = $parts[0] ?? '';
            $scope = $parts[1] ?? '';
            $sub = $parts[2] ?? '';
            $id = $parts[3] ?? '';

            if ($type !== 'include') {
                continue;
            }

            if ($scope === 'general') {
                $locations[] = __('Entire Site', 'starter-dashboard');
                $group = 'general';
                $group_label = __('Entire Site', 'starter-dashboard');
                // General applies to all post types
                $applies_to_post_type = true;
            } elseif ($scope === 'singular') {
                if ($id) {
                    // Specific post/page
                    $post = get_post($id);
                    if ($post) {
                        $locations[] = $post->post_title;
                        $url = get_permalink($id);
                        $group = 'specific';
                        $group_label = __('Specific Pages', 'starter-dashboard');
                        // Check if specific post matches filter post type
                        if (empty($filter_post_type) || $post->post_type === $filter_post_type) {
                            $applies_to_post_type = true;
                        }
                    }
                } elseif ($sub) {
                    // Post type (e.g., include/singular/page or include/singular/post)
                    $pt = get_post_type_object($sub);
                    $locations[] = $pt ? sprintf(__('All %s', 'starter-dashboard'), $pt->labels->name) : ucfirst($sub);
                    $group = 'post_type';
                    $group_label = __('Post Types', 'starter-dashboard');
                    // Check if post type matches filter
                    if (empty($filter_post_type) || $sub === $filter_post_type) {
                        $applies_to_post_type = true;
                    }
                }
            } elseif ($scope === 'archive') {
                if ($sub === 'post_type' && isset($parts[3])) {
                    $archive_pt = $parts[3];
                    $pt = get_post_type_object($archive_pt);
                    $locations[] = $pt ? sprintf(__('%s Archive', 'starter-dashboard'), $pt->labels->name) : ucfirst($archive_pt) . ' Archive';
                    // Archive pages relate to their post type
                    if (empty($filter_post_type) || $archive_pt === $filter_post_type) {
                        $applies_to_post_type = true;
                    }
                } else {
                    $locations[] = __('Archives', 'starter-dashboard');
                    // Generic archives could apply to any post type with archives
                    $applies_to_post_type = empty($filter_post_type);
                }
                $group = 'archive';
                $group_label = __('Archives', 'starter-dashboard');
            }
        }

        // If no filter specified, all templates apply
        if (empty($filter_post_type)) {
            $applies_to_post_type = true;
        }

        return [
            'label'                => !empty($locations) ? implode(', ', $locations) : __('Not assigned', 'starter-dashboard'),
            'group'                => $group,
            'group_label'          => $group_label,
            'url'                  => $url,
            'applies_to_post_type' => $applies_to_post_type,
        ];
    }

    /**
     * Get ACF field groups for modal
     */
    private function get_acf_items_for_modal($post_type = '') {
        if (!function_exists('acf_get_field_groups')) {
            return [];
        }

        $field_groups = acf_get_field_groups();
        $items = [];

        foreach ($field_groups as $group) {
            // Check if field group applies to this post type
            $applies = false;
            if (empty($post_type)) {
                $applies = true;
            } else {
                foreach ($group['location'] as $location_group) {
                    foreach ($location_group as $rule) {
                        if ($rule['param'] === 'post_type' && $rule['value'] === $post_type) {
                            $applies = true;
                            break 2;
                        }
                    }
                }
            }

            if ($applies) {
                $field_count = count(acf_get_fields($group['key']) ?: []);
                $items[] = [
                    'id'          => $group['ID'],
                    'title'       => $group['title'],
                    'field_count' => $field_count,
                    'url'         => admin_url('post.php?post=' . $group['ID'] . '&action=edit'),
                    'active'      => $group['active'],
                ];
            }
        }

        return $items;
    }

    /**
     * Get Elementor template type label
     */
    private function get_elementor_type_label($type) {
        $labels = [
            'header'          => __('Headers', 'starter-dashboard'),
            'footer'          => __('Footers', 'starter-dashboard'),
            'single'          => __('Single Templates', 'starter-dashboard'),
            'single-post'     => __('Single Post', 'starter-dashboard'),
            'single-page'     => __('Single Page', 'starter-dashboard'),
            'archive'         => __('Archives', 'starter-dashboard'),
            'search-results'  => __('Search Results', 'starter-dashboard'),
            'error-404'       => __('404 Page', 'starter-dashboard'),
            'popup'           => __('Popups', 'starter-dashboard'),
            'loop-item'       => __('Loop Items', 'starter-dashboard'),
            'page'            => __('Pages', 'starter-dashboard'),
            'section'         => __('Sections', 'starter-dashboard'),
            'container'       => __('Containers', 'starter-dashboard'),
            'kit'             => __('Kits', 'starter-dashboard'),
        ];
        return $labels[$type] ?? __('Templates', 'starter-dashboard');
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'starter_dashboard_settings',
            'starter_dashboard_allowed_roles',
            ['sanitize_callback' => [$this, 'sanitize_allowed_roles']]
        );
    }

    public function sanitize_allowed_roles($input) {
        if (!is_array($input)) {
            return [];
        }
        return array_map('sanitize_text_field', $input);
    }

    // ========================================================================
    // Dashboard rendering methods (unchanged from v2.1)
    // ========================================================================

    private function render_card($tile) {
        $count = wp_count_posts($tile['name']);
        $published_count = isset($count->publish) ? (int) $count->publish : 0;
        $links = $this->get_tile_links($tile['name']);
        $color = $this->get_type_color($tile['name']);

        // Separate links by type
        $primary_links = array_slice($links, 0, 2); // View All, Add New
        $related_links = array_slice($links, 2);

        // Group related links by type
        $grouped_links = [];
        foreach ($related_links as $link) {
            $type = $link['type'] ?? 'other';
            if (!isset($grouped_links[$type])) {
                $grouped_links[$type] = [];
            }
            $grouped_links[$type][] = $link;
        }

        // Define display order and labels for groups
        $group_order = [
            'archive'             => __('Archive', 'starter-dashboard'),
            'taxonomy'            => __('Taxonomies', 'starter-dashboard'),
            'elementor-content'   => __('Elementor: Content', 'starter-dashboard'),
            'elementor-structure' => __('Elementor: Structure', 'starter-dashboard'),
            'elementor-special'   => __('Elementor: Special', 'starter-dashboard'),
            'elementor-theme'     => __('Elementor: Theme', 'starter-dashboard'),
            'elementor'           => __('Elementor', 'starter-dashboard'),
            'acf'                 => __('Custom Fields', 'starter-dashboard'),
            'comments'            => __('Engagement', 'starter-dashboard'),
            'other'               => __('Related', 'starter-dashboard'),
        ];
        ?>
        <div class="bp-card bp-card--content" data-post-type="<?php echo esc_attr($tile['name']); ?>" style="--card-color: <?php echo esc_attr($color); ?>">
            <div class="bp-card__header">
                <div class="bp-card__icon">
                    <?php echo self::render_icon($tile['icon'], 28, null); ?>
                </div>
                <div class="bp-card__header-content">
                    <h3 class="bp-card__title"><?php echo esc_html($tile['label']); ?></h3>
                    <p class="bp-card__count">
                        <span class="bp-card__count-number"><?php echo number_format_i18n($published_count); ?></span>
                        <?php echo esc_html(_n('item', 'items', $published_count, 'starter-dashboard')); ?>
                    </p>
                </div>
            </div>

            <div class="bp-card__primary-actions">
                <?php if (!empty($primary_links[0])): ?>
                <a href="<?php echo esc_url($primary_links[0]['url']); ?>"
                   class="bp-card__primary-btn bp-card__primary-btn--view bp-action--iframe"
                   data-iframe-title="<?php echo esc_attr($tile['label']); ?>">
                    <?php echo self::render_icon($primary_links[0]['icon'], 18, '#fff'); ?>
                    <?php echo esc_html($primary_links[0]['label']); ?>
                </a>
                <?php endif; ?>
                <?php if (!empty($primary_links[1])): ?>
                <a href="<?php echo esc_url($primary_links[1]['url']); ?>"
                   class="bp-card__primary-btn bp-card__primary-btn--add">
                    <?php echo self::render_icon($primary_links[1]['icon'], 18, 'currentColor'); ?>
                    <?php echo esc_html($primary_links[1]['label']); ?>
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($grouped_links)): ?>
            <div class="bp-card__nav">
                <?php foreach ($group_order as $type => $group_label):
                    if (empty($grouped_links[$type])) continue;
                ?>
                    <div class="bp-card__nav-group" data-group="<?php echo esc_attr($type); ?>">
                        <div class="bp-card__nav-label"><?php echo esc_html($group_label); ?></div>
                        <div class="bp-card__nav-links">
                            <?php foreach ($grouped_links[$type] as $link):
                                $skip_modal = !empty($link['skip_modal']);
                                $target = !empty($link['target']) ? $link['target'] : '';
                                $data_attrs = '';
                                if (!$skip_modal) {
                                    $data_attrs .= ' data-modal-type="' . esc_attr($type) . '"';
                                    if (!empty($link['source_type'])) {
                                        $data_attrs .= ' data-source-type="' . esc_attr($link['source_type']) . '"';
                                    }
                                    if (!empty($link['template_type'])) {
                                        $data_attrs .= ' data-template-type="' . esc_attr($link['template_type']) . '"';
                                    }
                                }
                            ?>
                                <a href="<?php echo esc_url($link['url']); ?>"
                                   class="bp-card__link bp-card__link--<?php echo esc_attr($link['type'] ?? $type); ?><?php echo $skip_modal ? '' : ' bp-card__link--modal'; ?>"
                                   <?php echo $data_attrs; ?>
                                   <?php echo $target ? 'target="' . esc_attr($target) . '"' : ''; ?>>
                                    <?php echo self::render_icon($link['icon'], 14, 'currentColor'); ?>
                                    <span class="bp-card__link-text"><?php echo esc_html($link['label']); ?></span>
                                    <?php if (!empty($link['count'])): ?>
                                        <span class="bp-card__link-count"><?php echo esc_html($link['count']); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_quick_action($action, $is_custom = false) {
        $open_iframe = !empty($action['open_in_iframe']);
        $iframe_title = $action['iframe_title'] ?? $action['label'];
        $classes = 'bp-action';
        if ($open_iframe) {
            $classes .= ' bp-action--iframe';
        }
        if ($is_custom) {
            $classes .= ' bp-action--custom';
        }
        ?>
        <div class="bp-action__wrapper<?php echo $is_custom ? ' bp-action__wrapper--custom' : ''; ?>">
            <a href="<?php echo esc_url($action['url']); ?>"
               class="<?php echo esc_attr($classes); ?>"
               style="--action-color: <?php echo esc_attr($action['color']); ?>"
               <?php if ($open_iframe): ?>
               data-iframe-title="<?php echo esc_attr($iframe_title); ?>"
               <?php endif; ?>>
                <span class="bp-action__icon">
                    <?php echo self::render_icon($action['icon'], 20, 'currentColor'); ?>
                </span>
                <span class="bp-action__label"><?php echo esc_html($action['label']); ?></span>
            </a>
            <?php if ($is_custom): ?>
            <button type="button" class="bp-action__remove" data-action-id="<?php echo esc_attr($action['id']); ?>" title="<?php esc_attr_e('Remove', 'starter-dashboard'); ?>">
                <easier-icon name="cancel-01" variant="twotone" size="14" color="currentColor"></easier-icon>
            </button>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_recent_item($item) {
        $status_labels = [
            'publish' => __('Published', 'starter-dashboard'),
            'draft'   => __('Draft', 'starter-dashboard'),
            'pending' => __('Pending', 'starter-dashboard'),
            'private' => __('Private', 'starter-dashboard'),
        ];
        $status_label = $status_labels[$item['status']] ?? $item['status'];
        ?>
        <div class="bp-recent-item" style="--item-color: <?php echo esc_attr($item['color']); ?>">
            <div class="bp-recent-item__date"><?php echo esc_html($item['date']); ?></div>
            <div class="bp-recent-item__timeline">
                <div class="bp-recent-item__dot"></div>
                <div class="bp-recent-item__line"></div>
            </div>
            <div class="bp-recent-item__card">
                <div class="bp-recent-item__content">
                    <a href="<?php echo esc_url($item['edit_url']); ?>" class="bp-recent-item__title">
                        <?php echo esc_html($item['title'] ?: __('(no title)', 'starter-dashboard')); ?>
                    </a>
                    <div class="bp-recent-item__meta">
                        <span class="bp-recent-item__type"><?php echo esc_html($item['type_label']); ?></span>
                        <span class="bp-recent-item__status bp-recent-item__status--<?php echo esc_attr($item['status']); ?>">
                            <?php echo esc_html($status_label); ?>
                        </span>
                    </div>
                </div>
                <div class="bp-recent-item__actions">
                    <button type="button" class="bp-recent-item__action bp-recent-item__action--preview" data-url="<?php echo esc_url($item['view_url']); ?>" data-title="<?php echo esc_attr($item['title']); ?>" title="<?php esc_attr_e('Preview', 'starter-dashboard'); ?>">
                        <easier-icon name="view" variant="twotone" size="16" color="currentColor"></easier-icon>
                    </button>
                    <a href="<?php echo esc_url($item['edit_url']); ?>" class="bp-recent-item__action" title="<?php esc_attr_e('Edit', 'starter-dashboard'); ?>">
                        <easier-icon name="pencil-edit-02" variant="twotone" size="16" color="currentColor"></easier-icon>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get additional elements (hidden menu items) for current user
     * Uses cached full menu from before restrictions were applied
     */
    private function get_additional_elements_for_user() {
        $hidden_menus = get_option('starter_hidden_menus', []);
        $user = wp_get_current_user();
        $user_roles = $user->roles;

        // Collect all hidden slugs for user's roles
        $hidden_slugs = [];
        foreach ($user_roles as $role) {
            if (isset($hidden_menus[$role]) && is_array($hidden_menus[$role])) {
                $hidden_slugs = array_merge($hidden_slugs, $hidden_menus[$role]);
            }
        }
        $hidden_slugs = array_unique($hidden_slugs);

        if (empty($hidden_slugs)) {
            return [];
        }

        // Filter only main menu items (not submenus - those contain ||)
        $main_hidden_slugs = array_filter($hidden_slugs, function($slug) {
            return strpos($slug, '||') === false;
        });

        if (empty($main_hidden_slugs)) {
            return [];
        }

        // Use stored original menu (before restrictions were applied)
        $menu_to_use = !empty($this->original_menu) ? $this->original_menu : [];
        $submenu_to_use = !empty($this->original_submenu) ? $this->original_submenu : [];
        $additional_elements = [];

        foreach ($menu_to_use as $position => $item) {
            if (!is_array($item) || count($item) < 3) {
                continue;
            }

            $slug = isset($item[2]) ? $item[2] : '';
            if (empty($slug) || !in_array($slug, $main_hidden_slugs)) {
                continue;
            }

            $raw_title = isset($item[0]) ? $item[0] : '';
            $title = strip_tags($raw_title);
            $title = trim($title);

            if (empty($title)) {
                continue;
            }

            $icon = isset($item[6]) ? $item[6] : '';
            if (empty($icon) || $icon === 'none' || $icon === 'div' || strpos($icon, 'dashicons-') !== 0) {
                $icon = $this->get_menu_icon_for_slug($slug);
            }

            // Get children from submenu
            $children = [];
            if (isset($submenu_to_use[$slug]) && is_array($submenu_to_use[$slug])) {
                foreach ($submenu_to_use[$slug] as $sub_item) {
                    $children[] = [
                        'title' => wp_strip_all_tags($sub_item[0]),
                        'slug'  => $sub_item[2],
                    ];
                }
            }

            $additional_elements[] = [
                'title'    => $title,
                'slug'     => $slug,
                'icon'     => $icon,
                'children' => $children,
            ];
        }

        // Sort by saved order
        $saved_order = get_option('starter_additional_elements_order', []);
        if (!empty($saved_order)) {
            usort($additional_elements, function($a, $b) use ($saved_order) {
                $pos_a = array_search($a['slug'], $saved_order);
                $pos_b = array_search($b['slug'], $saved_order);

                // Items not in saved order go to the end
                if ($pos_a === false) $pos_a = 999;
                if ($pos_b === false) $pos_b = 999;

                return $pos_a - $pos_b;
            });
        }

        return $additional_elements;
    }

    /**
     * Build correct admin URL for a menu slug.
     * Simple .php filenames (edit.php, options-general.php) map directly under /wp-admin/.
     * Plugin path slugs (sitepress-multilingual-cms/menu/languages.php) and plain slugs
     * (cptui_main_menu) route through admin.php?page=.
     */
    private function build_menu_url($slug) {
        $base = explode('?', $slug, 2)[0];
        // Only simple .php filenames (no directory separators) are true wp-admin files
        if (strpos($base, '/') === false && strpos($base, '.php') !== false) {
            return admin_url($slug);
        }
        return admin_url('admin.php?page=' . $slug);
    }

    /**
     * Render additional element card (for hidden menu items)
     */
    private function render_additional_element_card($element) {
        $colors = ['#1C3C8B', '#2ABADE', '#0097CD', '#253E89', '#0073B8', '#92003B'];
        $color = $colors[array_rand($colors)];
        ?>
        <div class="bp-card bp-card--content bp-card--additional" data-slug="<?php echo esc_attr($element['slug']); ?>" style="--card-color: <?php echo esc_attr($color); ?>">
            <div class="bp-card__header">
                <div class="bp-card__icon">
                    <?php echo self::render_icon($element['icon'], 24, null); ?>
                </div>
                <div class="bp-card__header-content">
                    <h3 class="bp-card__title"><?php echo esc_html($element['title']); ?></h3>
                </div>
            </div>

            <div class="bp-card__primary-actions">
                <a href="<?php echo esc_url($this->build_menu_url($element['slug'])); ?>"
                   class="bp-card__primary-btn bp-card__primary-btn--view bp-action--iframe"
                   data-iframe-title="<?php echo esc_attr($element['title']); ?>">
                    <easier-icon name="eye" variant="twotone" size="18" stroke-color="currentColor" color="currentColor" aria-hidden="true"></easier-icon>
                    <?php _e('Open', 'starter-dashboard'); ?>
                </a>
            </div>

            <?php if (!empty($element['children'])): ?>
            <div class="bp-card__nav">
                <div class="bp-card__nav-group">
                    <div class="bp-card__nav-label"><?php _e('Menu Items', 'starter-dashboard'); ?></div>
                    <div class="bp-card__nav-links">
                        <?php foreach ($element['children'] as $child): ?>
                            <a href="<?php echo esc_url($this->build_menu_url($child['slug'])); ?>"
                               class="bp-card__link bp-action--iframe"
                               data-iframe-title="<?php echo esc_attr($child['title']); ?>">
                                <easier-icon name="arrow-right-03" variant="twotone" size="16" color="currentColor" aria-hidden="true"></easier-icon>
                                <span class="bp-card__link-text"><?php echo esc_html($child['title']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_quick_actions() {
        $actions = [];

        if (current_user_can('upload_files')) {
            $actions[] = [
                'label'           => __('Media Library', 'starter-dashboard'),
                'url'             => admin_url('upload.php?mode=grid'),
                'icon'            => 'dashicons-admin-media',
                'color'           => '#2ABADE',
                'open_in_iframe'  => true,
                'iframe_title'    => __('Media Library', 'starter-dashboard'),
            ];
            $actions[] = [
                'label'           => __('Add Media', 'starter-dashboard'),
                'url'             => admin_url('media-new.php'),
                'icon'            => 'dashicons-upload',
                'color'           => '#0097CD',
                'open_in_iframe'  => true,
                'iframe_title'    => __('Upload Media', 'starter-dashboard'),
            ];
        }

        if (current_user_can('edit_posts')) {
            $actions[] = [
                'label'          => __('Elementor Templates', 'starter-dashboard'),
                'url'            => admin_url('edit.php?post_type=elementor_library'),
                'icon'           => 'dashicons-admin-customizer',
                'color'          => '#1C3C8B',
                'open_in_iframe' => true,
                'iframe_title'   => __('Elementor Templates', 'starter-dashboard'),
            ];
        }

        if (current_user_can('list_users')) {
            $actions[] = [
                'label'          => __('Users', 'starter-dashboard'),
                'url'            => admin_url('users.php'),
                'icon'           => 'dashicons-admin-users',
                'color'          => '#253E89',
                'open_in_iframe' => true,
                'iframe_title'   => __('Users', 'starter-dashboard'),
            ];
        }

        if (current_user_can('manage_options')) {
            $actions[] = [
                'label'          => __('Settings', 'starter-dashboard'),
                'url'            => admin_url('options-general.php'),
                'icon'           => 'dashicons-admin-settings',
                'color'          => '#0073B8',
                'open_in_iframe' => true,
                'iframe_title'   => __('General Settings', 'starter-dashboard'),
            ];
        }

        // Load custom user actions
        $custom_actions = get_user_meta(get_current_user_id(), 'bp_custom_quick_actions', true);
        if (!empty($custom_actions) && is_array($custom_actions)) {
            foreach ($custom_actions as $custom) {
                $actions[] = [
                    'label'          => $custom['label'],
                    'url'            => $custom['url'],
                    'icon'           => $custom['icon'] ?? 'star',
                    'color'          => $custom['color'] ?? '#1C3C8B',
                    'open_in_iframe' => true,
                    'iframe_title'   => $custom['label'],
                    'custom'         => true,
                    'id'             => $custom['id'] ?? md5($custom['url']),
                ];
            }
        }

        return $actions;
    }

    private function get_recent_posts($all_types) {
        $post_type_names = array_column($all_types, 'name');

        // If no visible post types, return empty
        if (empty($post_type_names)) {
            return [];
        }

        // Only show posts from visible CPTs and only Version 2 content
        $posts = get_posts([
            'post_type'      => $post_type_names,
            'posts_per_page' => 8,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'meta_key'       => '_bp_content_version',
            'meta_value'     => '2',
        ]);

        $recent = [];
        foreach ($posts as $post) {
            $type_obj = get_post_type_object($post->post_type);
            $recent[] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'type'       => $post->post_type,
                'type_label' => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
                'date'       => get_the_modified_date('M j, Y', $post),
                'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                'view_url'   => get_permalink($post->ID),
                'color'      => $this->get_type_color($post->post_type),
            ];
        }

        return $recent;
    }

    private function get_dashboard_stats($all_types) {
        $stats = [
            'total'   => 0,
            'by_type' => [],
        ];

        $main_types = ['post', 'page'];

        foreach ($all_types as $tile) {
            $count = wp_count_posts($tile['name']);
            $published = isset($count->publish) ? (int) $count->publish : 0;

            $stats['total'] += $published;

            if (in_array($tile['name'], $main_types) && $published > 0) {
                $stats['by_type'][] = [
                    'name'  => $tile['name'],
                    'label' => $tile['label'],
                    'count' => $published,
                    'color' => $this->get_type_color($tile['name']),
                ];
            }
        }

        return $stats;
    }

    private function get_type_color($post_type_name) {
        return $this->type_colors[$post_type_name] ?? '#2ABADE';
    }

    private function sort_tiles_by_order($tiles, $order) {
        $tiles_by_name = [];
        foreach ($tiles as $tile) {
            $tiles_by_name[$tile['name']] = $tile;
        }

        $sorted = [];
        $used_names = [];

        foreach ($order as $post_type_name) {
            if (isset($tiles_by_name[$post_type_name])) {
                $sorted[] = $tiles_by_name[$post_type_name];
                $used_names[$post_type_name] = true;
            }
        }

        foreach ($tiles as $tile) {
            if (!isset($used_names[$tile['name']])) {
                $sorted[] = $tile;
            }
        }

        return $sorted;
    }

    public function ajax_save_tile_order() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_dashboard_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('read')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        if (!isset($_POST['order']) || !is_array($_POST['order'])) {
            wp_send_json_error(['message' => __('Invalid order data', 'starter-dashboard')], 400);
            return;
        }

        $order = array_map('sanitize_text_field', $_POST['order']);

        update_user_meta(
            get_current_user_id(),
            'starter_dashboard_tile_order',
            $order
        );

        wp_send_json_success(['message' => __('Tile order saved', 'starter-dashboard')]);
    }

    public function ajax_save_additional_elements_order() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_dashboard_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('read')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        if (!isset($_POST['order']) || !is_array($_POST['order'])) {
            wp_send_json_error(['message' => __('Invalid order data', 'starter-dashboard')], 400);
            return;
        }

        $order = array_map('sanitize_text_field', $_POST['order']);

        // Save globally (same order for all users with access)
        update_option('starter_additional_elements_order', $order);

        wp_send_json_success(['message' => __('Order saved', 'starter-dashboard')]);
    }

    public function ajax_save_admin_menu_order() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        if (!isset($_POST['menu_order']) || !is_array($_POST['menu_order'])) {
            wp_send_json_error(['message' => __('Invalid order data', 'starter-dashboard')], 400);
            return;
        }

        $order = array_map('sanitize_text_field', $_POST['menu_order']);

        update_option('starter_admin_menu_order', $order);

        wp_send_json_success(['message' => __('Menu order saved', 'starter-dashboard')]);
    }

    public function ajax_reset_admin_menu_order() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        delete_option('starter_admin_menu_order');

        wp_send_json_success(['message' => __('Menu order reset', 'starter-dashboard')]);
    }

    public function redirect_default_dashboard() {
        if (wp_doing_ajax()) {
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'index.php') {
            return;
        }

        if ($this->user_has_dashboard_access()) {
            wp_safe_redirect(admin_url('admin.php?page=starter-dashboard'));
            exit;
        }
    }

    /**
     * Hide admin sidebar when page is loaded in iframe (bp_iframe=1 parameter)
     */
    public function maybe_hide_admin_sidebar() {
        if (!isset($_GET['bp_iframe']) || $_GET['bp_iframe'] !== '1') {
            return;
        }
        ?>
        <style id="bp-iframe-mode-styles">
            /* Hide admin sidebar and top bar in iframe mode */
            #adminmenumain,
            #adminmenuback,
            #adminmenuwrap,
            #wpadminbar {
                display: none !important;
            }

            /* Expand content to full width */
            #wpcontent,
            #wpfooter {
                margin-left: 0 !important;
            }

            /* Adjust for collapsed menu state */
            .auto-fold #wpcontent,
            .auto-fold #wpfooter {
                margin-left: 0 !important;
            }

            /* Remove top margin from wpadminbar */
            html.wp-toolbar {
                padding-top: 0 !important;
            }

            #wpbody {
                padding-top: 10px;
            }

            /* Hide screen options and help tabs */
            #screen-meta,
            #screen-meta-links {
                display: none !important;
            }

            /* Clean up page header spacing */
            .wrap {
                margin: 10px 20px 0 20px;
            }

            /* Hide collapse button */
            #collapse-button {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Add ezicons SDK to admin head
     */
    public function add_ezicons_sdk() {
        ?>
        <script src="https://ezicons.com/sdk.js" data-key="iek_oYNmSmglKJTtwcB1AHUMdI2XDxW98DHP" data-global-inline="true"></script>
        <?php
    }

    /**
     * Convert dashicons class to ezicons name
     */
    public static function dashicon_to_ezicon($dashicon) {
        $map = [
            'dashicons-dashboard'           => 'home-11',
            'dashicons-admin-post'          => 'document-attachment',
            'dashicons-admin-media'         => 'image-02',
            'dashicons-admin-page'          => 'file-02',
            'dashicons-admin-comments'      => 'comment-02',
            'dashicons-admin-appearance'    => 'paint-brush-04',
            'dashicons-admin-plugins'       => 'puzzle',
            'dashicons-admin-users'         => 'user-group',
            'dashicons-admin-tools'         => 'wrench-02',
            'dashicons-admin-settings'      => 'settings-05',
            'dashicons-admin-generic'       => 'settings-05',
            'dashicons-admin-customizer'    => 'magic-wand-03',
            'dashicons-cart'                => 'shopping-cart-02',
            'dashicons-database'            => 'database',
            'dashicons-visibility'          => 'eye',
            'dashicons-hidden'              => 'view-off',
            'dashicons-plus-alt'            => 'add-circle',
            'dashicons-category'            => 'folder-01',
            'dashicons-tag'                 => 'tag-02',
            'dashicons-menu'                => 'hamburger-01',
            'dashicons-external'            => 'share-08',
            'dashicons-arrow-up-alt'        => 'arrow-up-01',
            'dashicons-arrow-down-alt'      => 'arrow-down-01',
            'dashicons-media-default'       => 'file-02',
            'dashicons-portfolio'           => 'briefcase-01',
            'dashicons-search'              => 'search-01',
            'dashicons-warning'             => 'alert-02',
            'dashicons-grid-view'           => 'grid-view',
            'dashicons-screenoptions'       => 'layout-grid',
            'dashicons-align-full-width'    => 'layout-01',
            'dashicons-store'               => 'store-01',
            'dashicons-layout'              => 'layout-grid',
            'dashicons-upload'              => 'upload-05',
            'dashicons-email-alt'           => 'message-02',
            'dashicons-share'               => 'share-08',
            'dashicons-phone'               => 'telephone',
            'dashicons-yes-alt'             => 'tick-02',
        ];

        return $map[$dashicon] ?? 'settings-05';
    }

    /**
     * Render ezicon element from dashicon class
     * Pass null for $color to let CSS control the color
     */
    public static function render_icon($dashicon, $size = 18, $color = 'currentColor') {
        $ezicon = self::dashicon_to_ezicon($dashicon);
        if ($color === null) {
            return sprintf(
                '<easier-icon name="%s" variant="twotone" size="%d"></easier-icon>',
                esc_attr($ezicon),
                (int) $size
            );
        }
        return sprintf(
            '<easier-icon name="%s" variant="twotone" size="%d" stroke-color="%s" color="%s"></easier-icon>',
            esc_attr($ezicon),
            (int) $size,
            esc_attr($color),
            esc_attr($color)
        );
    }

    public function user_has_dashboard_access() {
        if (!is_user_logged_in()) {
            return false;
        }

        $allowed_roles = get_option('starter_dashboard_allowed_roles', []);

        if (empty($allowed_roles) || !is_array($allowed_roles)) {
            return false;
        }

        $user = wp_get_current_user();
        $user_roles = $user->roles;

        return !empty(array_intersect($user_roles, $allowed_roles));
    }

    public function get_post_types_for_tiles() {
        // Get all post types that have UI (visible in admin)
        $post_types = get_post_types(['show_ui' => true], 'objects');

        if (empty($post_types)) {
            return [];
        }

        unset($post_types['attachment']);

        // Get visible CPTs from settings
        $visible_cpts = get_option('starter_visible_cpts', ['post', 'page']);
        if (!is_array($visible_cpts)) {
            $visible_cpts = ['post', 'page'];
        }

        $tiles = [];

        foreach ($post_types as $post_type) {
            // Skip if not in visible list
            if (!in_array($post_type->name, $visible_cpts)) {
                continue;
            }

            $icon = $post_type->menu_icon;

            if (!$icon || !is_string($icon) || strpos($icon, 'dashicons-') !== 0) {
                $icon = 'dashicons-admin-post';
            }

            $tiles[] = [
                'name'           => $post_type->name,
                'label'          => $post_type->labels->name ?: $post_type->name,
                'singular_label' => $post_type->labels->singular_name ?: $post_type->name,
                'icon'           => $icon,
                'capabilities'   => $post_type->cap,
            ];
        }

        usort($tiles, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return $tiles;
    }

    public function get_tile_links($post_type_name) {
        $post_type_obj = get_post_type_object($post_type_name);

        if (!$post_type_obj) {
            return [];
        }

        $links = [];

        // Primary actions (View All - sorted by date desc, no version filter)
        $links[] = [
            'label' => __('View All', 'starter-dashboard'),
            'url'   => admin_url('edit.php?post_type=' . $post_type_name . '&orderby=date&order=desc'),
            'icon'  => 'dashicons-visibility',
        ];

        $create_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : 'edit_posts';
        if (current_user_can($create_cap)) {
            $links[] = [
                'label' => __('Add New', 'starter-dashboard'),
                'url'   => admin_url('post-new.php?post_type=' . $post_type_name),
                'icon'  => 'dashicons-plus-alt',
            ];
        }

        // Taxonomies
        $taxonomies = get_object_taxonomies($post_type_name, 'objects');
        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy->public || !$taxonomy->show_ui) {
                continue;
            }

            $manage_cap = isset($taxonomy->cap->manage_terms) ? $taxonomy->cap->manage_terms : 'manage_categories';
            if (!current_user_can($manage_cap)) {
                continue;
            }

            $tax_icon = 'dashicons-category';
            if ($taxonomy->name === 'post_tag') {
                $tax_icon = 'dashicons-tag';
            } elseif (strpos($taxonomy->name, 'nav_menu') !== false) {
                $tax_icon = 'dashicons-menu';
            }

            $term_count = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);

            $links[] = [
                'label'       => $taxonomy->labels->name ?: $taxonomy->name,
                'url'         => admin_url('edit-tags.php?taxonomy=' . $taxonomy->name . '&post_type=' . $post_type_name),
                'icon'        => $tax_icon,
                'count'       => is_wp_error($term_count) ? 0 : $term_count,
                'type'        => 'taxonomy',
                'source_type' => $taxonomy->name,
            ];
        }

        // Elementor templates (if Elementor is active)
        if (defined('ELEMENTOR_VERSION')) {
            $elementor_links = $this->get_elementor_template_links($post_type_name);
            $links = array_merge($links, $elementor_links);
        }

        // ACF Field Groups (if ACF is active and user can manage)
        if (function_exists('acf_get_field_groups') && current_user_can('manage_options')) {
            $acf_links = $this->get_acf_field_group_links($post_type_name);
            $links = array_merge($links, $acf_links);
        }

        // Comments (if post type supports them)
        if (post_type_supports($post_type_name, 'comments') && current_user_can('moderate_comments')) {
            $comment_count = wp_count_comments();
            $links[] = [
                'label' => __('Comments', 'starter-dashboard'),
                'url'   => admin_url('edit-comments.php?post_type=' . $post_type_name),
                'icon'  => 'dashicons-admin-comments',
                'count' => $comment_count->approved ?? 0,
                'type'  => 'comments',
            ];
        }

        // Archive links (if post type has archive)
        if ($post_type_obj->has_archive || $post_type_name === 'post') {
            $archive_url = get_post_type_archive_link($post_type_name);
            if ($archive_url) {
                $links[] = [
                    'label'      => __('View Archive', 'starter-dashboard'),
                    'url'        => $archive_url,
                    'icon'       => 'dashicons-external',
                    'type'       => 'archive',
                    'skip_modal' => true,
                    'target'     => '_blank',
                ];
            }
        }

        return $links;
    }

    /**
     * Get Elementor template links for a post type
     */
    private function get_elementor_template_links($post_type_name) {
        $links = [];

        // Get all Elementor templates grouped by type
        $all_templates = $this->get_all_elementor_templates();

        // Template type labels
        $type_labels = [
            'header'          => __('Headers', 'starter-dashboard'),
            'footer'          => __('Footers', 'starter-dashboard'),
            'single'          => __('Single Templates', 'starter-dashboard'),
            'single-post'     => __('Single Post', 'starter-dashboard'),
            'single-page'     => __('Single Page', 'starter-dashboard'),
            'archive'         => __('Archives', 'starter-dashboard'),
            'search-results'  => __('Search Results', 'starter-dashboard'),
            'error-404'       => __('404 Page', 'starter-dashboard'),
            'popup'           => __('Popups', 'starter-dashboard'),
            'loop-item'       => __('Loop Items', 'starter-dashboard'),
            'page'            => __('Pages', 'starter-dashboard'),
            'section'         => __('Sections', 'starter-dashboard'),
            'container'       => __('Containers', 'starter-dashboard'),
            'wp-post'         => __('WP Posts', 'starter-dashboard'),
            'wp-page'         => __('WP Pages', 'starter-dashboard'),
            'kit'             => __('Kits', 'starter-dashboard'),
            'product'         => __('Single Product', 'starter-dashboard'),
            'product-archive' => __('Product Archive', 'starter-dashboard'),
        ];

        // Icons for template types
        $type_icons = [
            'header'          => 'dashicons-arrow-up-alt',
            'footer'          => 'dashicons-arrow-down-alt',
            'single'          => 'dashicons-media-default',
            'single-post'     => 'dashicons-admin-post',
            'single-page'     => 'dashicons-admin-page',
            'archive'         => 'dashicons-portfolio',
            'search-results'  => 'dashicons-search',
            'error-404'       => 'dashicons-warning',
            'popup'           => 'dashicons-external',
            'loop-item'       => 'dashicons-grid-view',
            'page'            => 'dashicons-admin-page',
            'section'         => 'dashicons-screenoptions',
            'container'       => 'dashicons-align-full-width',
            'product'         => 'dashicons-cart',
            'product-archive' => 'dashicons-store',
        ];

        // Map template types to subcategories
        $type_categories = [
            // Content templates - for displaying content
            'single'          => 'elementor-content',
            'single-post'     => 'elementor-content',
            'single-page'     => 'elementor-content',
            'archive'         => 'elementor-content',
            'loop-item'       => 'elementor-content',
            'page'            => 'elementor-content',
            'wp-post'         => 'elementor-content',
            'wp-page'         => 'elementor-content',
            'product'         => 'elementor-content',
            'product-archive' => 'elementor-content',
            // Structural templates - site structure
            'header'          => 'elementor-structure',
            'footer'          => 'elementor-structure',
            'section'         => 'elementor-structure',
            'container'       => 'elementor-structure',
            // Special templates - specific use cases
            'popup'           => 'elementor-special',
            'search-results'  => 'elementor-special',
            'error-404'       => 'elementor-special',
            // Theme templates
            'kit'             => 'elementor-theme',
        ];

        // Relevant types for each post type
        $relevant_types = [
            'post'    => ['single-post', 'single', 'archive', 'loop-item', 'header', 'footer'],
            'page'    => ['single-page', 'single', 'page', 'header', 'footer', 'error-404'],
            'product' => ['product', 'product-archive', 'single', 'archive', 'loop-item'],
        ];

        // For CPTs, show single, archive, loop-item, header, footer
        $types_to_show = $relevant_types[$post_type_name] ?? ['single', 'archive', 'loop-item', 'header', 'footer', 'popup'];

        $filtered_total = 0;

        foreach ($types_to_show as $type) {
            if (!isset($all_templates[$type]) || $all_templates[$type]['count'] == 0) {
                continue;
            }

            // Get filtered count for this post type
            $filtered_count = $this->get_filtered_template_count($type, $post_type_name);

            if ($filtered_count == 0) {
                continue; // Don't show types with no templates for this post type
            }

            $filtered_total += $filtered_count;

            $links[] = [
                'label'         => $type_labels[$type] ?? ucfirst(str_replace('-', ' ', $type)),
                'url'           => admin_url('edit.php?post_type=elementor_library&elementor_library_type=' . $type),
                'icon'          => $type_icons[$type] ?? 'dashicons-admin-customizer',
                'count'         => $filtered_count,
                'type'          => $type_categories[$type] ?? 'elementor',
                'template_type' => $type,
                'source_type'   => $post_type_name, // Filter templates by this post type
            ];
        }

        // Always add Theme Builder link if there are any templates (skip modal for this)
        if (!empty($all_templates)) {
            $links[] = [
                'label'      => __('Theme Builder', 'starter-dashboard'),
                'url'        => admin_url('admin.php?page=elementor-app#/site-editor'),
                'icon'       => 'dashicons-layout',
                'type'       => 'elementor-theme',
                'skip_modal' => true,
            ];
        }

        // Add all templates link with filtered count
        if ($filtered_total > 0) {
            $links[] = [
                'label'         => __('All Templates', 'starter-dashboard'),
                'url'           => admin_url('edit.php?post_type=elementor_library'),
                'icon'          => 'dashicons-admin-customizer',
                'count'         => $filtered_total,
                'type'          => 'elementor-theme',
                'template_type' => 'all',
                'source_type'   => $post_type_name, // Filter templates by this post type
            ];
        }

        return $links;
    }

    /**
     * Get all Elementor templates grouped by type
     */
    private function get_all_elementor_templates() {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        global $wpdb;

        // Query all template types and their counts
        $results = $wpdb->get_results("
            SELECT pm.meta_value as template_type, COUNT(*) as count
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_elementor_template_type'
            AND p.post_type = 'elementor_library'
            AND p.post_status IN ('publish', 'draft', 'private')
            GROUP BY pm.meta_value
            ORDER BY count DESC
        ");

        $cached = [];
        foreach ($results as $row) {
            $cached[$row->template_type] = [
                'count' => (int) $row->count,
            ];
        }

        return $cached;
    }

    /**
     * Get template count filtered by post type
     *
     * @param string $template_type Template type (single-post, archive, etc.)
     * @param string $post_type_name Post type to filter by
     * @return int Filtered count
     */
    private function get_filtered_template_count($template_type, $post_type_name) {
        global $wpdb;

        // For structure templates (header, footer), don't filter - they apply to all
        $structure_types = ['header', 'footer', 'section', 'container'];
        if (in_array($template_type, $structure_types)) {
            $all = $this->get_all_elementor_templates();
            return $all[$template_type]['count'] ?? 0;
        }

        // Get templates of this type
        $templates = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_elementor_template_type'
            AND pm.meta_value = %s
            AND p.post_type = 'elementor_library'
            AND p.post_status IN ('publish', 'draft', 'private')
        ", $template_type));

        if (empty($templates)) {
            return 0;
        }

        $count = 0;
        foreach ($templates as $template) {
            $conditions = get_post_meta($template->ID, '_elementor_conditions', true);
            if (empty($conditions)) {
                // No conditions = template not assigned anywhere, skip when filtering
                continue;
            }

            $parsed = $this->parse_elementor_conditions($conditions, $post_type_name);
            if ($parsed['applies_to_post_type']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get ACF Field Group links for a post type
     */
    private function get_acf_field_group_links($post_type_name) {
        $links = [];

        if (!function_exists('acf_get_field_groups')) {
            return $links;
        }

        // Get field groups that apply to this post type
        $field_groups = acf_get_field_groups([
            'post_type' => $post_type_name,
        ]);

        if (empty($field_groups)) {
            return $links;
        }

        // Add link to field groups
        $links[] = [
            'label'       => __('ACF Field Groups', 'starter-dashboard'),
            'url'         => admin_url('edit.php?post_type=acf-field-group'),
            'icon'        => 'dashicons-database',
            'count'       => count($field_groups),
            'type'        => 'acf',
            'source_type' => $post_type_name,
        ];

        return $links;
    }

    // ========================================================================
    // Import/Export Settings
    // ========================================================================

    /**
     * Render Import/Export page
     */
    public function render_import_export_page() {
        $visual_settings = $this->get_visual_settings();
        ?>
        <div class="wrap bp-settings">
            <h1 style="display:none;"><?php _e('Import/Export', 'starter-dashboard'); ?></h1>

            <div class="bp-settings__header" style="--header-primary: <?php echo esc_attr($visual_settings['primary_color']); ?>;">
                <div class="bp-settings__header-content">
                    <img src="<?php echo esc_url($visual_settings['logo_url']); ?>" alt="<?php echo esc_attr($visual_settings['hub_name']); ?>" class="bp-settings__header-logo" />
                    <div class="bp-settings__header-text">
                        <h2><?php _e('Import/Export', 'starter-dashboard'); ?></h2>
                        <p><?php _e('Backup and restore dashboard settings', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=starter-dashboard'); ?>" class="bp-settings__header-btn">
                    <easier-icon name="arrow-left-01" variant="twotone" size="18" color="#fff"></easier-icon>
                    <?php printf(__('Back to %s', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
                </a>
            </div>

            <div class="bp-settings__content">
                <div class="bp-settings__panel">
                    <h2 class="bp-settings__panel-title"><?php _e('Export Settings', 'starter-dashboard'); ?></h2>
                    <p class="bp-settings__panel-desc">
                        <?php _e('Export all dashboard settings to a JSON file. This includes menu visibility, menu order, CPT settings, visual settings, and addon configurations.', 'starter-dashboard'); ?>
                    </p>

                    <div style="background:#f8f9fa;border-radius:8px;padding:20px;margin-bottom:20px;">
                        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
                                <input type="checkbox" class="bp-export-option" value="menu_settings" checked />
                                <?php _e('Menu Visibility', 'starter-dashboard'); ?>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
                                <input type="checkbox" class="bp-export-option" value="menu_order" checked />
                                <?php _e('Menu Order', 'starter-dashboard'); ?>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
                                <input type="checkbox" class="bp-export-option" value="dashboard_access" checked />
                                <?php _e('Dashboard Access', 'starter-dashboard'); ?>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
                                <input type="checkbox" class="bp-export-option" value="cpt_visibility" checked />
                                <?php _e('CPT Visibility', 'starter-dashboard'); ?>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;">
                                <input type="checkbox" class="bp-export-option" value="visual_settings" checked />
                                <?php _e('Visual Settings', 'starter-dashboard'); ?>
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:#fff;border:1px solid #ddd;border-radius:4px;cursor:pointer;" title="<?php esc_attr_e('Includes: Active addons, OG/Social settings, HubSpot Portal ID & Access Token', 'starter-dashboard'); ?>">
                                <input type="checkbox" class="bp-export-option" value="addons" checked />
                                <?php _e('Addon Settings', 'starter-dashboard'); ?>
                                <easier-icon name="information-circle" variant="twotone" size="14" color="#666"></easier-icon>
                            </label>
                        </div>

                        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
                            <p style="margin:0;color:#856404;font-size:13px;display:flex;align-items:center;gap:6px;">
                                <easier-icon name="lock" variant="twotone" size="16" color="#856404"></easier-icon>
                                <strong><?php _e('Security Note:', 'starter-dashboard'); ?></strong>
                                <?php _e('Addon Settings include sensitive data (HubSpot Access Token). Keep the exported file secure.', 'starter-dashboard'); ?>
                            </p>
                        </div>

                        <button type="button" id="bp-export-settings" class="button button-primary" style="display:inline-flex;align-items:center;gap:6px;">
                            <easier-icon name="download-01" variant="twotone" size="16" color="#fff"></easier-icon>
                            <?php _e('Export Settings', 'starter-dashboard'); ?>
                        </button>
                    </div>
                </div>

                <div class="bp-settings__panel" style="margin-top:30px;">
                    <h2 class="bp-settings__panel-title"><?php _e('Import Settings', 'starter-dashboard'); ?></h2>
                    <p class="bp-settings__panel-desc">
                        <?php _e('Import dashboard settings from a previously exported JSON file. This will overwrite your current settings.', 'starter-dashboard'); ?>
                    </p>

                    <div style="background:#fff8e1;border:1px solid #ffcc80;border-radius:8px;padding:16px;margin-bottom:20px;">
                        <p style="margin:0;color:#e65100;font-size:13px;display:flex;align-items:center;gap:6px;">
                            <easier-icon name="alert-circle" variant="twotone" size="16" color="#e65100"></easier-icon>
                            <strong><?php _e('Warning:', 'starter-dashboard'); ?></strong>
                            <?php _e('Importing settings will overwrite your current configuration. Consider exporting your current settings first as a backup.', 'starter-dashboard'); ?>
                        </p>
                    </div>

                    <div style="background:#f8f9fa;border-radius:8px;padding:20px;">
                        <div style="margin-bottom:16px;">
                            <label for="bp-import-file" style="display:block;margin-bottom:8px;font-weight:500;">
                                <?php _e('Select JSON file to import:', 'starter-dashboard'); ?>
                            </label>
                            <input type="file" id="bp-import-file" accept=".json" style="display:block;margin-bottom:12px;" />
                        </div>

                        <div id="bp-import-preview" style="display:none;background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:16px;">
                            <h4 style="margin:0 0 12px;"><?php _e('File Contents Preview:', 'starter-dashboard'); ?></h4>
                            <div id="bp-import-preview-content" style="font-size:13px;"></div>
                        </div>

                        <button type="button" id="bp-import-settings" class="button button-primary" disabled style="display:inline-flex;align-items:center;gap:6px;">
                            <easier-icon name="upload-01" variant="twotone" size="16" color="#fff"></easier-icon>
                            <?php _e('Import Settings', 'starter-dashboard'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get all exportable settings
     */
    private function get_exportable_settings($sections = []) {
        $all_sections = empty($sections) || in_array('all', $sections);

        $export = [
            'meta' => [
                'plugin_version' => '4.0.0',
                'export_date' => current_time('c'),
                'site_url' => home_url(),
                'sections' => $sections,
            ],
        ];

        // Menu visibility settings
        if ($all_sections || in_array('menu_settings', $sections)) {
            $export['menu_settings'] = [
                'hidden_menus' => get_option('starter_hidden_menus', []),
            ];
        }

        // Menu order
        if ($all_sections || in_array('menu_order', $sections)) {
            $export['menu_order'] = [
                'admin_menu_order' => get_option('starter_admin_menu_order', []),
                'additional_elements_order' => get_option('starter_additional_elements_order', []),
            ];
        }

        // Dashboard access
        if ($all_sections || in_array('dashboard_access', $sections)) {
            $export['dashboard_access'] = [
                'allowed_roles' => get_option('starter_dashboard_allowed_roles', []),
            ];
        }

        // CPT visibility
        if ($all_sections || in_array('cpt_visibility', $sections)) {
            $export['cpt_visibility'] = [
                'visible_cpts' => get_option('starter_visible_cpts', ['post', 'page']),
            ];
        }

        // Visual settings
        if ($all_sections || in_array('visual_settings', $sections)) {
            $export['visual_settings'] = [
                'settings' => get_option('starter_visual_settings', []),
            ];
        }

        // Addon settings
        if ($all_sections || in_array('addons', $sections)) {
            $export['addons'] = [
                'active_addons' => get_option('starter_active_addons', []),
                'og_settings' => get_option('starter_og_settings', []),
                'hubspot_portal_id' => get_option('starter_hubspot_portal_id', ''),
                'hubspot_access_token' => get_option('starter_hubspot_access_token', ''),
            ];
        }

        // Whitelabel settings
        if ($all_sections || in_array('whitelabel', $sections)) {
            $export['whitelabel'] = get_option('starter_whitelabel', []);
        }

        return $export;
    }

    /**
     * AJAX handler for exporting settings
     */
    public function ajax_export_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $sections = isset($_POST['sections']) ? array_map('sanitize_text_field', (array) $_POST['sections']) : [];

        $export = $this->get_exportable_settings($sections);

        wp_send_json_success([
            'message' => __('Settings exported successfully', 'starter-dashboard'),
            'data' => $export,
            'filename' => 'starter-dashboard-settings-' . date('Y-m-d') . '.json',
        ]);
    }

    /**
     * AJAX handler for importing settings
     */
    public function ajax_import_settings() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $import_data = isset($_POST['import_data']) ? $_POST['import_data'] : '';

        if (empty($import_data)) {
            wp_send_json_error(['message' => __('No import data provided', 'starter-dashboard')]);
            return;
        }

        // Decode JSON (handle both string and already decoded data)
        if (is_string($import_data)) {
            $data = json_decode(stripslashes($import_data), true);
        } else {
            $data = $import_data;
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            wp_send_json_error(['message' => __('Invalid JSON format', 'starter-dashboard')]);
            return;
        }

        // Verify it's a valid export file
        if (!isset($data['meta']) || !isset($data['meta']['plugin_version'])) {
            wp_send_json_error(['message' => __('Invalid export file format', 'starter-dashboard')]);
            return;
        }

        $imported = [];

        // Import menu settings
        if (isset($data['menu_settings'])) {
            if (isset($data['menu_settings']['hidden_menus']) && is_array($data['menu_settings']['hidden_menus'])) {
                update_option('starter_hidden_menus', $data['menu_settings']['hidden_menus']);
                $imported[] = __('Menu visibility settings', 'starter-dashboard');
            }
        }

        // Import menu order
        if (isset($data['menu_order'])) {
            if (isset($data['menu_order']['admin_menu_order']) && is_array($data['menu_order']['admin_menu_order'])) {
                update_option('starter_admin_menu_order', $data['menu_order']['admin_menu_order']);
                $imported[] = __('Admin menu order', 'starter-dashboard');
            }
            if (isset($data['menu_order']['additional_elements_order']) && is_array($data['menu_order']['additional_elements_order'])) {
                update_option('starter_additional_elements_order', $data['menu_order']['additional_elements_order']);
                $imported[] = __('Additional elements order', 'starter-dashboard');
            }
        }

        // Import dashboard access
        if (isset($data['dashboard_access'])) {
            if (isset($data['dashboard_access']['allowed_roles']) && is_array($data['dashboard_access']['allowed_roles'])) {
                update_option('starter_dashboard_allowed_roles', $data['dashboard_access']['allowed_roles']);
                $imported[] = __('Dashboard access roles', 'starter-dashboard');
            }
        }

        // Import CPT visibility
        if (isset($data['cpt_visibility'])) {
            if (isset($data['cpt_visibility']['visible_cpts']) && is_array($data['cpt_visibility']['visible_cpts'])) {
                update_option('starter_visible_cpts', $data['cpt_visibility']['visible_cpts']);
                $imported[] = __('CPT visibility', 'starter-dashboard');
            }
        }

        // Import visual settings
        if (isset($data['visual_settings'])) {
            if (isset($data['visual_settings']['settings']) && is_array($data['visual_settings']['settings'])) {
                // Sanitize visual settings
                $sanitized = [
                    'hub_name' => isset($data['visual_settings']['settings']['hub_name'])
                        ? sanitize_text_field($data['visual_settings']['settings']['hub_name']) : '',
                    'logo_url' => isset($data['visual_settings']['settings']['logo_url'])
                        ? esc_url_raw($data['visual_settings']['settings']['logo_url']) : '',
                    'primary_color' => isset($data['visual_settings']['settings']['primary_color'])
                        ? sanitize_hex_color($data['visual_settings']['settings']['primary_color']) : '',
                    'secondary_color' => isset($data['visual_settings']['settings']['secondary_color'])
                        ? sanitize_hex_color($data['visual_settings']['settings']['secondary_color']) : '',
                    'accent_color' => isset($data['visual_settings']['settings']['accent_color'])
                        ? sanitize_hex_color($data['visual_settings']['settings']['accent_color']) : '',
                ];
                update_option('starter_visual_settings', $sanitized);
                $imported[] = __('Visual settings', 'starter-dashboard');
            }
        }

        // Import addon settings
        if (isset($data['addons'])) {
            if (isset($data['addons']['active_addons']) && is_array($data['addons']['active_addons'])) {
                update_option('starter_active_addons', array_map('sanitize_text_field', $data['addons']['active_addons']));
                $imported[] = __('Active addons', 'starter-dashboard');
            }
            if (isset($data['addons']['og_settings']) && is_array($data['addons']['og_settings'])) {
                // Sanitize OG settings
                $og_sanitized = [];
                if (isset($data['addons']['og_settings']['default_og_image'])) {
                    $og_sanitized['default_og_image'] = esc_url_raw($data['addons']['og_settings']['default_og_image']);
                }
                if (isset($data['addons']['og_settings']['site_name'])) {
                    $og_sanitized['site_name'] = sanitize_text_field($data['addons']['og_settings']['site_name']);
                }
                if (isset($data['addons']['og_settings']['default_description'])) {
                    $og_sanitized['default_description'] = sanitize_textarea_field($data['addons']['og_settings']['default_description']);
                }
                if (isset($data['addons']['og_settings']['twitter_card_type'])) {
                    $og_sanitized['twitter_card_type'] = sanitize_text_field($data['addons']['og_settings']['twitter_card_type']);
                }
                if (isset($data['addons']['og_settings']['twitter_site'])) {
                    $og_sanitized['twitter_site'] = sanitize_text_field($data['addons']['og_settings']['twitter_site']);
                }
                update_option('starter_og_settings', $og_sanitized);
                $imported[] = __('OG/Social preview settings', 'starter-dashboard');
            }
            if (isset($data['addons']['hubspot_portal_id']) && !empty($data['addons']['hubspot_portal_id'])) {
                update_option('starter_hubspot_portal_id', sanitize_text_field($data['addons']['hubspot_portal_id']));
                $imported[] = __('HubSpot Portal ID', 'starter-dashboard');
            }
            if (isset($data['addons']['hubspot_access_token']) && !empty($data['addons']['hubspot_access_token'])) {
                update_option('starter_hubspot_access_token', sanitize_text_field($data['addons']['hubspot_access_token']));
                $imported[] = __('HubSpot Access Token', 'starter-dashboard');
            }
        }

        // Import whitelabel settings
        if (isset($data['whitelabel']) && is_array($data['whitelabel'])) {
            $whitelabel = [
                'name' => sanitize_text_field($data['whitelabel']['name'] ?? ''),
                'description' => sanitize_textarea_field($data['whitelabel']['description'] ?? ''),
                'author' => sanitize_text_field($data['whitelabel']['author'] ?? ''),
                'author_uri' => esc_url_raw($data['whitelabel']['author_uri'] ?? ''),
                'plugin_uri' => esc_url_raw($data['whitelabel']['plugin_uri'] ?? ''),
            ];
            update_option('starter_whitelabel', $whitelabel);
            $imported[] = __('Whitelabel settings', 'starter-dashboard');
        }

        if (empty($imported)) {
            wp_send_json_error(['message' => __('No valid settings found in import file', 'starter-dashboard')]);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Successfully imported: %s', 'starter-dashboard'),
                implode(', ', $imported)
            ),
            'imported' => $imported,
        ]);
    }

    /**
     * Render admin menu items for custom action picker
     */
    private function render_admin_menu_items() {
        global $menu, $submenu;

        $items = [];

        // Process main menu items
        foreach ($menu as $item) {
            if (empty($item[0]) || empty($item[2])) continue;
            if (strpos($item[4] ?? '', 'wp-menu-separator') !== false) continue;

            $label = wp_strip_all_tags($item[0]);
            $label = preg_replace('/\s*\d+\s*$/', '', $label); // Remove notification counts
            $slug = $item[2];
            $icon = $item[6] ?? 'dashicons-admin-generic';

            // Build URL
            if (strpos($slug, '.php') !== false) {
                $url = admin_url($slug);
            } else {
                $url = admin_url('admin.php?page=' . $slug);
            }

            $items[] = [
                'label' => $label,
                'url'   => $url,
                'icon'  => $icon,
                'type'  => 'menu',
            ];

            // Add submenu items
            if (!empty($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub) {
                    if (empty($sub[0]) || empty($sub[2])) continue;

                    $sub_label = wp_strip_all_tags($sub[0]);
                    $sub_label = preg_replace('/\s*\d+\s*$/', '', $sub_label);
                    $sub_slug = $sub[2];

                    if (strpos($sub_slug, '.php') !== false) {
                        $sub_url = admin_url($sub_slug);
                    } elseif (strpos($slug, '.php') !== false) {
                        $sub_url = admin_url($slug . '?page=' . $sub_slug);
                    } else {
                        $sub_url = admin_url('admin.php?page=' . $sub_slug);
                    }

                    $items[] = [
                        'label'  => $label . ' → ' . $sub_label,
                        'url'    => $sub_url,
                        'icon'   => $icon,
                        'type'   => 'submenu',
                        'parent' => $label,
                    ];
                }
            }
        }

        // Render items
        foreach ($items as $item) {
            $ezicon = self::dashicon_to_ezicon($item['icon']);
            ?>
            <div class="bp-custom-action__item"
                 data-url="<?php echo esc_attr($item['url']); ?>"
                 data-label="<?php echo esc_attr(strip_tags($item['label'])); ?>"
                 data-icon="<?php echo esc_attr($ezicon); ?>">
                <span class="bp-custom-action__item-icon">
                    <easier-icon name="<?php echo esc_attr($ezicon); ?>" variant="twotone" size="18" color="currentColor"></easier-icon>
                </span>
                <span class="bp-custom-action__item-label"><?php echo esc_html($item['label']); ?></span>
                <easier-icon name="arrow-right-01" variant="twotone" size="16" color="#999"></easier-icon>
            </div>
            <?php
        }
    }

    /**
     * AJAX: Save custom action
     */
    public function ajax_save_custom_action() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        $label = sanitize_text_field($_POST['label'] ?? '');
        $url = esc_url_raw($_POST['url'] ?? '');
        $icon = sanitize_text_field($_POST['icon'] ?? 'star');
        $color = sanitize_hex_color($_POST['color'] ?? '') ?: '#1C3C8B';

        if (empty($label) || empty($url)) {
            wp_send_json_error(['message' => __('Label and URL are required', 'starter-dashboard')]);
            return;
        }

        $user_id = get_current_user_id();
        $actions = get_user_meta($user_id, 'bp_custom_quick_actions', true);
        if (!is_array($actions)) {
            $actions = [];
        }

        $id = md5($url . time());
        $actions[] = [
            'id'    => $id,
            'label' => $label,
            'url'   => $url,
            'icon'  => $icon,
            'color' => $color,
        ];

        update_user_meta($user_id, 'bp_custom_quick_actions', $actions);

        wp_send_json_success([
            'message' => __('Action added successfully', 'starter-dashboard'),
            'action'  => [
                'id'    => $id,
                'label' => $label,
                'url'   => $url,
                'icon'  => $icon,
                'color' => $color,
            ],
        ]);
    }

    /**
     * AJAX: Remove custom action
     */
    public function ajax_remove_custom_action() {
        check_ajax_referer('starter_dashboard_nonce', 'nonce');

        $action_id = sanitize_text_field($_POST['action_id'] ?? '');

        if (empty($action_id)) {
            wp_send_json_error(['message' => __('Action ID is required', 'starter-dashboard')]);
            return;
        }

        $user_id = get_current_user_id();
        $actions = get_user_meta($user_id, 'bp_custom_quick_actions', true);

        if (!is_array($actions)) {
            wp_send_json_error(['message' => __('No custom actions found', 'starter-dashboard')]);
            return;
        }

        $actions = array_filter($actions, function($action) use ($action_id) {
            return ($action['id'] ?? '') !== $action_id;
        });

        update_user_meta($user_id, 'bp_custom_quick_actions', array_values($actions));

        wp_send_json_success(['message' => __('Action removed successfully', 'starter-dashboard')]);
    }

    /**
     * Render Whitelabel settings page
     */
    public function render_whitelabel_page() {
        $visual_settings = $this->get_visual_settings();
        $whitelabel = get_option('starter_whitelabel', [
            'name' => '',
            'description' => '',
            'author' => '',
            'author_uri' => '',
            'plugin_uri' => '',
        ]);
        ?>
        <div class="wrap bp-settings">
            <h1 style="display:none;"><?php _e('Whitelabel', 'starter-dashboard'); ?></h1>

            <div class="bp-settings__header" style="--header-primary: <?php echo esc_attr($visual_settings['primary_color']); ?>;">
                <div class="bp-settings__header-content">
                    <img src="<?php echo esc_url($visual_settings['logo_url']); ?>" alt="<?php echo esc_attr($visual_settings['hub_name']); ?>" class="bp-settings__header-logo" />
                    <div class="bp-settings__header-text">
                        <h2><?php _e('Whitelabel', 'starter-dashboard'); ?></h2>
                        <p><?php _e('Customize how this plugin appears in WordPress', 'starter-dashboard'); ?></p>
                    </div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=starter-dashboard'); ?>" class="bp-settings__header-btn">
                    <easier-icon name="arrow-left-01" variant="twotone" size="18" color="#fff"></easier-icon>
                    <?php printf(__('Back to %s', 'starter-dashboard'), esc_html($visual_settings['hub_name'])); ?>
                </a>
            </div>

            <div class="bp-settings__content">
                <!-- Settings Form -->
                <div class="bp-settings__panel">
                    <h2 class="bp-settings__panel-title">
                        <easier-icon name="tag-01" variant="twotone" size="20" color="currentColor"></easier-icon>
                        <?php _e('Plugin Identity', 'starter-dashboard'); ?>
                    </h2>
                    <p class="bp-settings__panel-desc">
                        <?php _e('Customize the plugin name, description, and author information shown in the WordPress plugins list.', 'starter-dashboard'); ?>
                    </p>

                    <form id="whitelabel-form">
                        <?php wp_nonce_field('starter_settings_nonce', 'whitelabel_nonce'); ?>

                        <div style="display:grid;gap:20px;margin-top:20px;">
                            <!-- Plugin Name -->
                            <div class="bp-whitelabel-field">
                                <label for="whitelabel_name" class="bp-whitelabel-label">
                                    <easier-icon name="text" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Plugin Name', 'starter-dashboard'); ?>
                                </label>
                                <input type="text" id="whitelabel_name" name="whitelabel_name"
                                       value="<?php echo esc_attr($whitelabel['name']); ?>"
                                       class="bp-whitelabel-input"
                                       placeholder="Starter Dashboard">
                                <span class="bp-whitelabel-hint"><?php _e('Default: Starter Dashboard', 'starter-dashboard'); ?></span>
                            </div>

                            <!-- Description -->
                            <div class="bp-whitelabel-field">
                                <label for="whitelabel_description" class="bp-whitelabel-label">
                                    <easier-icon name="file-text" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Description', 'starter-dashboard'); ?>
                                </label>
                                <textarea id="whitelabel_description" name="whitelabel_description"
                                          class="bp-whitelabel-textarea" rows="3"
                                          placeholder="Custom admin dashboard with post type tiles, menu visibility control, role editor, CPT management, and addon system"><?php echo esc_textarea($whitelabel['description']); ?></textarea>
                                <span class="bp-whitelabel-hint"><?php _e('Brief description of the plugin functionality', 'starter-dashboard'); ?></span>
                            </div>

                            <!-- Author -->
                            <div class="bp-whitelabel-field">
                                <label for="whitelabel_author" class="bp-whitelabel-label">
                                    <easier-icon name="user-02" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Author Name', 'starter-dashboard'); ?>
                                </label>
                                <input type="text" id="whitelabel_author" name="whitelabel_author"
                                       value="<?php echo esc_attr($whitelabel['author']); ?>"
                                       class="bp-whitelabel-input"
                                       placeholder="Alex M.">
                                <span class="bp-whitelabel-hint"><?php _e('Default: Alex M.', 'starter-dashboard'); ?></span>
                            </div>

                            <!-- Author URI -->
                            <div class="bp-whitelabel-field">
                                <label for="whitelabel_author_uri" class="bp-whitelabel-label">
                                    <easier-icon name="link-01" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Author URL', 'starter-dashboard'); ?>
                                </label>
                                <input type="url" id="whitelabel_author_uri" name="whitelabel_author_uri"
                                       value="<?php echo esc_attr($whitelabel['author_uri']); ?>"
                                       class="bp-whitelabel-input"
                                       placeholder="https://developer.dev">
                                <span class="bp-whitelabel-hint"><?php _e('Link to author website', 'starter-dashboard'); ?></span>
                            </div>

                            <!-- Plugin URI -->
                            <div class="bp-whitelabel-field">
                                <label for="whitelabel_plugin_uri" class="bp-whitelabel-label">
                                    <easier-icon name="globe-01" variant="twotone" size="16" color="currentColor"></easier-icon>
                                    <?php _e('Plugin URL', 'starter-dashboard'); ?>
                                </label>
                                <input type="url" id="whitelabel_plugin_uri" name="whitelabel_plugin_uri"
                                       value="<?php echo esc_attr($whitelabel['plugin_uri']); ?>"
                                       class="bp-whitelabel-input"
                                       placeholder="https://developer.dev/plugins/starter-dashboard">
                                <span class="bp-whitelabel-hint"><?php _e('Plugin homepage or documentation', 'starter-dashboard'); ?></span>
                            </div>
                        </div>

                        <div style="margin-top:24px;display:flex;align-items:center;gap:12px;">
                            <button type="submit" class="button button-primary" id="save-whitelabel" style="display:inline-flex;align-items:center;gap:6px;">
                                <easier-icon name="check" variant="twotone" size="16" color="#fff"></easier-icon>
                                <?php _e('Save Changes', 'starter-dashboard'); ?>
                            </button>
                            <span class="spinner" style="float:none;margin:0;"></span>
                            <span id="whitelabel-status" style="color:#46b450;font-weight:500;display:none;">
                                <easier-icon name="check-circle" variant="twotone" size="16" color="#46b450"></easier-icon>
                                <?php _e('Saved!', 'starter-dashboard'); ?>
                            </span>
                        </div>
                    </form>
                </div>

                <!-- Live Preview -->
                <div class="bp-settings__panel" style="margin-top:30px;">
                    <h2 class="bp-settings__panel-title">
                        <easier-icon name="eye" variant="twotone" size="20" color="currentColor"></easier-icon>
                        <?php _e('Live Preview', 'starter-dashboard'); ?>
                    </h2>
                    <p class="bp-settings__panel-desc">
                        <?php _e('This is how the plugin will appear in the WordPress plugins list.', 'starter-dashboard'); ?>
                    </p>

                    <div class="bp-whitelabel-preview">
                        <div class="bp-whitelabel-preview__row">
                            <div class="bp-whitelabel-preview__checkbox">
                                <input type="checkbox" disabled />
                            </div>
                            <div class="bp-whitelabel-preview__content">
                                <div class="bp-whitelabel-preview__name">
                                    <strong id="preview-name"><?php echo esc_html($whitelabel['name'] ?: 'Starter Dashboard'); ?></strong>
                                </div>
                                <div class="bp-whitelabel-preview__desc" id="preview-description">
                                    <?php echo esc_html($whitelabel['description'] ?: 'Custom admin dashboard with post type tiles, menu visibility control, role editor, CPT management, and addon system'); ?>
                                </div>
                                <div class="bp-whitelabel-preview__meta">
                                    <?php _e('Version', 'starter-dashboard'); ?> 4.0.0 |
                                    <?php _e('By', 'starter-dashboard'); ?>
                                    <a href="#" id="preview-author-link"><span id="preview-author"><?php echo esc_html($whitelabel['author'] ?: 'Alex M.'); ?></span></a>
                                    <span id="preview-plugin-uri-wrapper" style="<?php echo empty($whitelabel['plugin_uri']) ? 'display:none;' : ''; ?>">
                                        | <a href="#" id="preview-plugin-uri"><?php _e('Visit plugin site', 'starter-dashboard'); ?></a>
                                    </span>
                                </div>
                                <div class="bp-whitelabel-preview__actions">
                                    <span class="bp-whitelabel-preview__active"><?php _e('Deactivate', 'starter-dashboard'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                .bp-whitelabel-field {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                }
                .bp-whitelabel-label {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    font-weight: 600;
                    font-size: 13px;
                    color: #1d2327;
                }
                .bp-whitelabel-input,
                .bp-whitelabel-textarea {
                    width: 100%;
                    max-width: 500px;
                    padding: 10px 12px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    font-size: 14px;
                    transition: border-color 0.2s, box-shadow 0.2s;
                }
                .bp-whitelabel-input:focus,
                .bp-whitelabel-textarea:focus {
                    border-color: <?php echo esc_attr($visual_settings['primary_color']); ?>;
                    box-shadow: 0 0 0 2px <?php echo esc_attr($visual_settings['primary_color']); ?>22;
                    outline: none;
                }
                .bp-whitelabel-hint {
                    font-size: 12px;
                    color: #757575;
                }

                /* Preview Styles - mimics WP plugins list */
                .bp-whitelabel-preview {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    margin-top: 16px;
                }
                .bp-whitelabel-preview__row {
                    display: flex;
                    padding: 10px 0;
                    border-bottom: 1px solid #f0f0f1;
                    background: linear-gradient(to bottom, #fcfcfc, #fafafa);
                }
                .bp-whitelabel-preview__checkbox {
                    padding: 8px 10px 0 10px;
                }
                .bp-whitelabel-preview__content {
                    flex: 1;
                    padding: 8px 10px 10px 0;
                }
                .bp-whitelabel-preview__name {
                    font-size: 14px;
                    line-height: 1.5;
                    margin-bottom: 4px;
                }
                .bp-whitelabel-preview__name strong {
                    font-weight: 600;
                }
                .bp-whitelabel-preview__desc {
                    color: #50575e;
                    font-size: 13px;
                    line-height: 1.5;
                    margin-bottom: 8px;
                }
                .bp-whitelabel-preview__meta {
                    font-size: 13px;
                    color: #787c82;
                    margin-bottom: 8px;
                }
                .bp-whitelabel-preview__meta a {
                    color: #2271b1;
                    text-decoration: none;
                }
                .bp-whitelabel-preview__meta a:hover {
                    color: #135e96;
                    text-decoration: underline;
                }
                .bp-whitelabel-preview__actions {
                    font-size: 13px;
                }
                .bp-whitelabel-preview__active {
                    color: #b32d2e;
                    cursor: default;
                }
            </style>

            <script>
            jQuery(document).ready(function($) {
                // Live preview updates
                $('#whitelabel_name').on('input', function() {
                    $('#preview-name').text($(this).val() || 'Starter Dashboard');
                });
                $('#whitelabel_description').on('input', function() {
                    $('#preview-description').text($(this).val() || 'Custom admin dashboard with post type tiles, menu visibility control, role editor, CPT management, and addon system');
                });
                $('#whitelabel_author').on('input', function() {
                    $('#preview-author').text($(this).val() || 'Alex M.');
                });
                $('#whitelabel_plugin_uri').on('input', function() {
                    $('#preview-plugin-uri-wrapper').toggle(!!$(this).val());
                });

                // Save form via AJAX
                $('#whitelabel-form').on('submit', function(e) {
                    e.preventDefault();

                    var $btn = $('#save-whitelabel');
                    var $spinner = $btn.siblings('.spinner');
                    var $status = $('#whitelabel-status');

                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $status.hide();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'starter_save_whitelabel',
                            nonce: $('#whitelabel_nonce').val(),
                            name: $('#whitelabel_name').val(),
                            description: $('#whitelabel_description').val(),
                            author: $('#whitelabel_author').val(),
                            author_uri: $('#whitelabel_author_uri').val(),
                            plugin_uri: $('#whitelabel_plugin_uri').val()
                        },
                        success: function(response) {
                            $btn.prop('disabled', false);
                            $spinner.removeClass('is-active');

                            if (response.success) {
                                $status.css('display', 'inline-flex').delay(3000).fadeOut();
                            } else {
                                alert(response.data.message || '<?php _e('Error saving settings', 'starter-dashboard'); ?>');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false);
                            $spinner.removeClass('is-active');
                            alert('<?php _e('Error saving settings', 'starter-dashboard'); ?>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * AJAX handler for saving whitelabel settings
     */
    public function ajax_save_whitelabel() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'starter_settings_nonce')) {
            wp_send_json_error(['message' => __('Invalid security token', 'starter-dashboard')], 403);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')], 403);
            return;
        }

        $whitelabel = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'author' => sanitize_text_field($_POST['author'] ?? ''),
            'author_uri' => esc_url_raw($_POST['author_uri'] ?? ''),
            'plugin_uri' => esc_url_raw($_POST['plugin_uri'] ?? ''),
        ];

        update_option('starter_whitelabel', $whitelabel);

        wp_send_json_success(['message' => __('Whitelabel settings saved successfully', 'starter-dashboard')]);
    }

    /**
     * Filter plugin metadata for plugins list
     */
    public function filter_plugin_metadata($plugins) {
        $plugin_file = plugin_basename(__FILE__);

        if (!isset($plugins[$plugin_file])) {
            return $plugins;
        }

        $whitelabel = get_option('starter_whitelabel', []);

        // Apply whitelabel settings if set
        if (!empty($whitelabel['name'])) {
            $plugins[$plugin_file]['Name'] = $whitelabel['name'];
            $plugins[$plugin_file]['Title'] = $whitelabel['name'];
        }

        if (!empty($whitelabel['description'])) {
            $plugins[$plugin_file]['Description'] = $whitelabel['description'];
        }

        if (!empty($whitelabel['author'])) {
            $plugins[$plugin_file]['Author'] = $whitelabel['author'];
            $plugins[$plugin_file]['AuthorName'] = $whitelabel['author'];
        }

        if (!empty($whitelabel['author_uri'])) {
            $plugins[$plugin_file]['AuthorURI'] = $whitelabel['author_uri'];
        }

        if (!empty($whitelabel['plugin_uri'])) {
            $plugins[$plugin_file]['PluginURI'] = $whitelabel['plugin_uri'];
        }

        return $plugins;
    }
}

// Initialize
Starter_Dashboard::instance();
