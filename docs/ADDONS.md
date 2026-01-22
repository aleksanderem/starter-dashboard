# Starter Dashboard - Addon Development Guide

This guide explains how to create addons for Starter Dashboard. Addons are modular extensions that can add widgets, shortcodes, integrations, and settings panels.

---

## Table of Contents

1. [Addon Structure](#addon-structure)
2. [Registering an Addon](#registering-an-addon)
3. [Categories](#categories)
4. [Basic Addon (No Settings)](#basic-addon-no-settings)
5. [Addon with Settings](#addon-with-settings)
6. [Elementor Widgets](#elementor-widgets)
7. [Shortcodes](#shortcodes)
8. [External API Integration](#external-api-integration)
9. [AJAX Handlers](#ajax-handlers)
10. [Best Practices](#best-practices)
11. [Complete Examples](#complete-examples)

---

## Addon Structure

Each addon lives in its own folder under `addons/`:

```
addons/
└── my-addon/
    ├── addon.php           # Required: Main addon file
    ├── widget.php          # Optional: Elementor widget
    ├── class-*.php         # Optional: Additional classes
    └── assets/             # Optional: CSS/JS files
        ├── style.css
        └── script.js
```

### Minimum Required File

`addon.php` - The main entry point that gets loaded when the addon is active.

---

## Registering an Addon

Addons are registered in `class-addon-loader.php` in the `register_addons()` method.

### Registration Array

```php
$this->addons['my-addon'] = [
    'name'              => __('My Addon', 'starter-dashboard'),
    'description'       => __('What this addon does', 'starter-dashboard'),
    'icon'              => 'settings-05',  // ezicons icon name
    'category'          => 'elementor',    // elementor|integration|seo|ui
    'file'              => $addons_dir . '/my-addon/addon.php',
    'has_settings'      => false,          // true if addon has settings panel
    'settings_callback' => '',             // 'ClassName::render_settings' if has_settings
    'version'           => '1.0.0',
];
```

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Display name shown in Addons page |
| `description` | string | Brief description of functionality |
| `icon` | string | ezicons icon name (without `easier-icon` prefix) |
| `category` | string | One of: `elementor`, `integration`, `seo`, `ui` |
| `file` | string | Full path to main addon file |
| `has_settings` | bool | Whether addon has a settings panel |
| `version` | string | Addon version number |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `settings_callback` | string | Static method that renders settings HTML |

---

## Categories

Addons are grouped by category in the Addons page:

| Category | Label | Description |
|----------|-------|-------------|
| `elementor` | Elementor | Widgets, form fields, Elementor extensions |
| `integration` | Integrations | External service integrations (HubSpot, APIs) |
| `seo` | SEO & Social | Meta tags, Open Graph, schema markup |
| `ui` | UI Components | Shortcodes, frontend components |

---

## Basic Addon (No Settings)

For addons that don't need configuration.

### Example: Shortcode Addon

```php
<?php
/**
 * Starter Dashboard Addon: My Shortcode
 *
 * Adds [my_shortcode] shortcode
 */

defined('ABSPATH') || exit;

class Starter_Addon_My_Shortcode {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register shortcode
        add_shortcode('my_shortcode', [$this, 'render_shortcode']);

        // Enqueue assets if needed
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Render shortcode output
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'title' => 'Default Title',
            'class' => '',
        ], $atts, 'my_shortcode');

        ob_start();
        ?>
        <div class="my-shortcode <?php echo esc_attr($atts['class']); ?>">
            <h3><?php echo esc_html($atts['title']); ?></h3>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // Only load if shortcode is used
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'my_shortcode')) {
            wp_enqueue_style(
                'my-shortcode-style',
                plugin_dir_url(__FILE__) . 'assets/style.css',
                [],
                '1.0.0'
            );
        }
    }
}

// Initialize addon
Starter_Addon_My_Shortcode::instance();
```

### Registration

```php
$this->addons['my-shortcode'] = [
    'name'         => __('My Shortcode', 'starter-dashboard'),
    'description'  => __('Adds [my_shortcode] shortcode', 'starter-dashboard'),
    'icon'         => 'code-01',
    'category'     => 'ui',
    'file'         => $addons_dir . '/my-shortcode/addon.php',
    'has_settings' => false,
    'version'      => '1.0.0',
];
```

---

## Addon with Settings

Addons can have a settings panel displayed in the dashboard.

### Key Components

1. **`has_settings => true`** in registration
2. **`settings_callback`** pointing to static render method
3. **`render_settings()`** static method that outputs HTML
4. **Filter hook** for saving: `starter_addon_save_settings_{addon-id}`

### Example: Settings Addon

```php
<?php
/**
 * Starter Dashboard Addon: My Settings Addon
 */

defined('ABSPATH') || exit;

class Starter_Addon_My_Settings {

    private static $instance = null;
    private $option_name = 'starter_my_addon_settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register settings save handler
        add_filter('starter_addon_save_settings_my-settings', [$this, 'save_settings'], 10, 2);

        // Other hooks...
    }

    /**
     * Render settings panel
     * Called via AJAX when addon tab is clicked in dashboard
     */
    public static function render_settings() {
        $instance = self::instance();
        $options = get_option($instance->option_name, []);
        ?>
        <div class="bp-addon-settings" data-addon="my-settings">

            <div class="bp-addon-settings__section">
                <h4><?php _e('General Settings', 'starter-dashboard'); ?></h4>

                <div class="bp-addon-settings__field">
                    <label for="my-addon-api-key">
                        <?php _e('API Key', 'starter-dashboard'); ?>
                    </label>
                    <input type="text"
                           id="my-addon-api-key"
                           name="api_key"
                           value="<?php echo esc_attr($options['api_key'] ?? ''); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php _e('Enter your API key', 'starter-dashboard'); ?>
                    </p>
                </div>

                <div class="bp-addon-settings__field">
                    <label for="my-addon-enabled">
                        <input type="checkbox"
                               id="my-addon-enabled"
                               name="enabled"
                               value="1"
                               <?php checked($options['enabled'] ?? false); ?>>
                        <?php _e('Enable feature', 'starter-dashboard'); ?>
                    </label>
                </div>

                <div class="bp-addon-settings__field">
                    <label for="my-addon-mode">
                        <?php _e('Mode', 'starter-dashboard'); ?>
                    </label>
                    <select id="my-addon-mode" name="mode">
                        <option value="basic" <?php selected($options['mode'] ?? '', 'basic'); ?>>
                            <?php _e('Basic', 'starter-dashboard'); ?>
                        </option>
                        <option value="advanced" <?php selected($options['mode'] ?? '', 'advanced'); ?>>
                            <?php _e('Advanced', 'starter-dashboard'); ?>
                        </option>
                    </select>
                </div>

            </div>

        </div>
        <?php
    }

    /**
     * Save settings
     *
     * @param bool  $saved    Whether settings were saved (always false initially)
     * @param array $settings Settings data from POST
     * @return bool Whether save was successful
     */
    public function save_settings($saved, $settings) {
        $clean = [
            'api_key' => sanitize_text_field($settings['api_key'] ?? ''),
            'enabled' => !empty($settings['enabled']),
            'mode'    => in_array($settings['mode'] ?? '', ['basic', 'advanced'])
                         ? $settings['mode'] : 'basic',
        ];

        update_option($this->option_name, $clean);

        return true; // Return true to indicate success
    }
}

// Initialize
Starter_Addon_My_Settings::instance();
```

### Registration

```php
$this->addons['my-settings'] = [
    'name'              => __('My Settings Addon', 'starter-dashboard'),
    'description'       => __('Addon with configuration panel', 'starter-dashboard'),
    'icon'              => 'settings-05',
    'category'          => 'integration',
    'file'              => $addons_dir . '/my-settings/addon.php',
    'has_settings'      => true,
    'settings_callback' => 'Starter_Addon_My_Settings::render_settings',
    'version'           => '1.0.0',
];
```

### Settings HTML Classes

Use these CSS classes for consistent styling:

| Class | Usage |
|-------|-------|
| `bp-addon-settings` | Main wrapper, include `data-addon="addon-id"` |
| `bp-addon-settings__section` | Section wrapper |
| `bp-addon-settings__field` | Individual field wrapper |
| `bp-addon-settings--two-column` | Two-column layout modifier |
| `bp-image-upload-field` | Image upload field with button |

---

## Elementor Widgets

### Widget File Structure

```
my-widget-addon/
├── addon.php       # Registers widget
└── widget.php      # Widget class
```

### addon.php

```php
<?php
defined('ABSPATH') || exit;

class Starter_Addon_My_Widget {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register widget with Elementor
        add_action('elementor/widgets/register', [$this, 'register_widget']);

        // Register widget category (optional)
        add_action('elementor/elements/categories_registered', [$this, 'add_widget_category']);
    }

    /**
     * Add custom widget category
     */
    public function add_widget_category($elements_manager) {
        $elements_manager->add_category(
            'starter-utils',
            [
                'title' => __('Starter Utils', 'starter-dashboard'),
                'icon'  => 'fa fa-plug',
            ]
        );
    }

    /**
     * Register the widget
     */
    public function register_widget($widgets_manager) {
        require_once __DIR__ . '/widget.php';
        $widgets_manager->register(new Starter_My_Widget());
    }
}

Starter_Addon_My_Widget::instance();
```

### widget.php

```php
<?php
defined('ABSPATH') || exit;

class Starter_My_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'starter-my-widget';
    }

    public function get_title() {
        return __('My Widget', 'starter-dashboard');
    }

    public function get_icon() {
        return 'eicon-code';
    }

    public function get_categories() {
        return ['starter-utils'];
    }

    public function get_keywords() {
        return ['custom', 'my', 'widget'];
    }

    protected function register_controls() {

        // Content Section
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Content', 'starter-dashboard'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'title',
            [
                'label'       => __('Title', 'starter-dashboard'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __('Default Title', 'starter-dashboard'),
                'placeholder' => __('Enter title', 'starter-dashboard'),
            ]
        );

        $this->add_control(
            'description',
            [
                'label'       => __('Description', 'starter-dashboard'),
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'rows'        => 5,
                'default'     => '',
                'placeholder' => __('Enter description', 'starter-dashboard'),
            ]
        );

        $this->end_controls_section();

        // Style Section
        $this->start_controls_section(
            'style_section',
            [
                'label' => __('Style', 'starter-dashboard'),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label'     => __('Title Color', 'starter-dashboard'),
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .my-widget-title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'label'    => __('Title Typography', 'starter-dashboard'),
                'selector' => '{{WRAPPER}} .my-widget-title',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <div class="starter-my-widget">
            <?php if (!empty($settings['title'])): ?>
                <h3 class="my-widget-title">
                    <?php echo esc_html($settings['title']); ?>
                </h3>
            <?php endif; ?>

            <?php if (!empty($settings['description'])): ?>
                <div class="my-widget-description">
                    <?php echo wp_kses_post($settings['description']); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
```

---

## Shortcodes

### Basic Shortcode

```php
add_shortcode('my_shortcode', function($atts) {
    $atts = shortcode_atts([
        'id'    => 0,
        'class' => '',
        'title' => '',
    ], $atts, 'my_shortcode');

    ob_start();
    // Output HTML
    return ob_get_clean();
});
```

### Shortcode with Inline Styles

```php
public function render_shortcode($atts) {
    $atts = shortcode_atts([...], $atts);
    $unique_id = 'my-shortcode-' . uniqid();

    ob_start();
    ?>
    <div id="<?php echo esc_attr($unique_id); ?>" class="my-shortcode">
        <!-- Content -->
    </div>

    <style>
        #<?php echo esc_attr($unique_id); ?> {
            /* Scoped styles */
        }
    </style>

    <script>
        (function() {
            const container = document.getElementById('<?php echo esc_js($unique_id); ?>');
            // JavaScript logic
        })();
    </script>
    <?php
    return ob_get_clean();
}
```

---

## External API Integration

### Pattern for API Addons

```php
<?php
defined('ABSPATH') || exit;

class Starter_Addon_API_Integration {

    private static $instance = null;
    private $option_token = 'starter_api_token';
    private $api_base = 'https://api.example.com/v1';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX handlers
        add_action('wp_ajax_starter_api_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_starter_api_get_data', [$this, 'ajax_get_data']);

        // Settings save handler
        add_filter('starter_addon_save_settings_api-integration', [$this, 'save_settings'], 10, 2);
    }

    /**
     * Make API request
     */
    private function api_request($endpoint, $method = 'GET', $body = null) {
        $token = get_option($this->option_token, '');

        if (empty($token)) {
            return new WP_Error('no_token', 'API token not configured');
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($this->api_base . $endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error(
                'api_error',
                $body['message'] ?? 'API request failed',
                ['status' => $code]
            );
        }

        return $body;
    }

    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('starter_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $result = $this->api_request('/ping');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Connection successful']);
    }

    /**
     * AJAX: Get data from API
     */
    public function ajax_get_data() {
        check_ajax_referer('starter_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $result = $this->api_request('/data');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['data' => $result]);
    }

    /**
     * Render settings
     */
    public static function render_settings() {
        $instance = self::instance();
        $token = get_option($instance->option_token, '');
        $is_configured = !empty($token);
        ?>
        <div class="bp-addon-settings" data-addon="api-integration">

            <div class="bp-addon-settings__section">
                <div class="bp-addon-settings__header">
                    <h4><?php _e('API Configuration', 'starter-dashboard'); ?></h4>
                    <?php if ($is_configured): ?>
                        <span class="bp-status bp-status--success">
                            <?php _e('Configured', 'starter-dashboard'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="bp-addon-settings__field">
                    <label for="api-token"><?php _e('API Token', 'starter-dashboard'); ?></label>
                    <input type="password"
                           id="api-token"
                           name="api_token"
                           value="<?php echo esc_attr($token); ?>"
                           class="regular-text">
                </div>

                <div class="bp-addon-settings__actions">
                    <button type="button"
                            class="button button-secondary"
                            id="test-api-connection"
                            <?php disabled(!$is_configured); ?>>
                        <?php _e('Test Connection', 'starter-dashboard'); ?>
                    </button>
                    <span class="bp-test-result"></span>
                </div>
            </div>

        </div>

        <script>
        jQuery(function($) {
            $('#test-api-connection').on('click', function() {
                const $btn = $(this);
                const $result = $btn.siblings('.bp-test-result');

                $btn.prop('disabled', true);
                $result.text('Testing...');

                $.post(ajaxurl, {
                    action: 'starter_api_test_connection',
                    nonce: starterSettings.nonce
                })
                .done(function(response) {
                    $result.text(response.data.message)
                           .css('color', response.success ? 'green' : 'red');
                })
                .fail(function() {
                    $result.text('Request failed').css('color', 'red');
                })
                .always(function() {
                    $btn.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Save settings
     */
    public function save_settings($saved, $settings) {
        if (isset($settings['api_token'])) {
            update_option($this->option_token, sanitize_text_field($settings['api_token']));
        }
        return true;
    }
}

Starter_Addon_API_Integration::instance();
```

---

## AJAX Handlers

### Pattern

```php
// Register in constructor
add_action('wp_ajax_starter_myaddon_action', [$this, 'ajax_handler']);

// Handler method
public function ajax_handler() {
    // Verify nonce (use appropriate nonce based on context)
    check_ajax_referer('starter_settings_nonce', 'nonce');

    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'starter-dashboard')]);
    }

    // Sanitize input
    $param = isset($_POST['param']) ? sanitize_text_field($_POST['param']) : '';

    // Do work...

    // Return response
    wp_send_json_success(['data' => $result]);
    // or
    wp_send_json_error(['message' => 'Error message']);
}
```

### Available Nonces

| Nonce Action | Use Case |
|--------------|----------|
| `starter_settings_nonce` | Settings page operations |
| `starter_dashboard_nonce` | Dashboard page operations |

Both nonces are accepted for addon settings saves.

---

## Best Practices

### 1. Use Singleton Pattern

```php
private static $instance = null;

public static function instance() {
    if (self::$instance === null) {
        self::$instance = new self();
    }
    return self::$instance;
}

private function __construct() {
    // Initialize
}
```

### 2. Prefix Everything

Use `starter_` or your addon-specific prefix for:
- Option names: `starter_myaddon_settings`
- AJAX actions: `starter_myaddon_action`
- CSS classes: `bp-myaddon-*` or `starter-myaddon-*`
- JavaScript globals: `starterMyAddon`

### 3. Sanitize All Input

```php
$text = sanitize_text_field($_POST['text']);
$email = sanitize_email($_POST['email']);
$url = esc_url_raw($_POST['url']);
$int = intval($_POST['number']);
$bool = !empty($_POST['checkbox']);
$array = array_map('sanitize_text_field', $_POST['items'] ?? []);
```

### 4. Escape All Output

```php
echo esc_html($text);
echo esc_attr($attribute);
echo esc_url($url);
echo wp_kses_post($html_content);
```

### 5. Use Text Domain

```php
__('Text', 'starter-dashboard')
_e('Text', 'starter-dashboard')
sprintf(__('Hello %s', 'starter-dashboard'), $name)
```

### 6. Check Capabilities

```php
if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Permission denied']);
}
```

### 7. Handle Errors Gracefully

```php
$result = $this->do_something();

if (is_wp_error($result)) {
    wp_send_json_error([
        'message' => $result->get_error_message()
    ]);
}
```

### 8. Use WordPress APIs

```php
// HTTP requests
wp_remote_get(), wp_remote_post()

// Options
get_option(), update_option(), delete_option()

// User meta
get_user_meta(), update_user_meta()

// Post meta
get_post_meta(), update_post_meta()

// Transients for caching
get_transient(), set_transient(), delete_transient()
```

---

## Complete Examples

### Example 1: Simple Shortcode Addon

See `addons/vertical-menu/addon.php`

Features:
- Shortcode with multiple attributes
- Inline CSS scoped to unique ID
- Inline JavaScript with event handlers
- Elementor integration
- Responsive styles

### Example 2: Elementor Widget Addon

See `addons/post-type-pill/addon.php` and `widget.php`

Features:
- Custom Elementor widget
- Widget category registration
- Controls (content + style)
- Shortcode fallback
- Default styles

### Example 3: API Integration Addon

See `addons/hubspot-forms/addon.php`

Features:
- External API integration
- Settings panel with multiple sections
- AJAX handlers for API operations
- Connection testing
- Submission logging
- Elementor form action registration
- Tabbed interface in settings

### Example 4: Meta Tags Addon

See `addons/og-image/addon.php`

Features:
- `wp_head` output
- Post meta boxes
- Media library integration
- Settings with image upload
- Per-post overrides

### Example 5: Complex Settings Addon (301 Redirects)

See `addons/redirects-301/addon.php`

Features:
- Full-featured settings panel with table UI
- AJAX CRUD operations for redirects
- Multiple match types (exact, wildcard, regex)
- Hit tracking and statistics
- Built-in redirect testing with status display
- CSV import/export
- External redirect scanner (Yoast, Redirection, Rank Math, .htaccess)
- Post list integration showing redirect badges
- Real-time search and filtering

This addon demonstrates:
- Large settings panel with multiple sections
- Drag-and-drop table rows
- Modal dialogs for testing
- Background scanning with progress
- Complex JavaScript interactions
- Integration with WordPress post tables

---

## Checklist for New Addons

- [ ] Create folder under `addons/`
- [ ] Create `addon.php` with class using singleton pattern
- [ ] Add registration in `class-addon-loader.php`
- [ ] Add `defined('ABSPATH') || exit;` at top
- [ ] Use `starter-dashboard` text domain
- [ ] Prefix all option names, AJAX actions, CSS classes
- [ ] Implement settings panel if `has_settings => true`
- [ ] Add filter for `starter_addon_save_settings_{addon-id}`
- [ ] Sanitize all input, escape all output
- [ ] Check user capabilities in AJAX handlers
- [ ] Test activation/deactivation
- [ ] Test settings save/load
- [ ] Verify no PHP notices/warnings
