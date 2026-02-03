# External Addon Example

This document shows how to create a standalone WordPress plugin that registers as a Starter Dashboard addon.

## Basic External Addon Structure

```
my-starter-addon/
├── my-starter-addon.php          # Main plugin file
├── includes/
│   └── addon-integration.php     # Addon functionality
└── README.md                      # Optional: Documentation
```

## Example Plugin Code

### my-starter-addon.php

```php
<?php
/**
 * Plugin Name: My Starter Addon
 * Description: Example external addon for Starter Dashboard
 * Version: 1.0.0
 * Author: Your Name
 * Requires Plugins: starter-dashboard
 */

defined('ABSPATH') || exit;

// Check if Starter Dashboard is active
if (!function_exists('starter_addon_loader')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo __('My Starter Addon requires Starter Dashboard plugin to be installed and activated.', 'my-starter-addon');
        echo '</p></div>';
    });
    return;
}

// Register this plugin as a Starter Dashboard addon
add_filter('starter_register_external_addons', function($addons) {
    $addons['my-custom-addon'] = [
        'name' => __('My Custom Addon', 'my-starter-addon'),
        'description' => __('This is an example external addon that integrates with Starter Dashboard', 'my-starter-addon'),
        'icon' => 'star-01',  // Icon from Untitled UI icon set
        'category' => 'integration',  // Options: elementor, integration, seo, ui, or custom
        'file' => plugin_dir_path(__FILE__) . 'includes/addon-integration.php',
        'has_settings' => true,
        'settings_callback' => 'My_Custom_Addon::render_settings',
        'version' => '1.0.0',
        'plugin_file' => __FILE__,
    ];
    return $addons;
});

// Hook into addon settings save
add_filter('starter_addon_save_settings_my-custom-addon', function($saved, $settings) {
    // Save your addon settings
    update_option('my_custom_addon_settings', $settings);
    return true;
}, 10, 2);
```

### includes/addon-integration.php

```php
<?php
/**
 * Addon Integration
 */

defined('ABSPATH') || exit;

class My_Custom_Addon {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize your addon functionality
        add_action('init', [$this, 'init']);
    }

    public function init() {
        // Your addon code here
        // Example: Register shortcodes, hooks, etc.
    }

    /**
     * Render settings panel in Starter Dashboard
     */
    public static function render_settings() {
        $settings = get_option('my_custom_addon_settings', []);
        $api_key = $settings['api_key'] ?? '';
        ?>
        <div class="my-addon-settings" data-addon="my-custom-addon">
            <div class="bp-addon-settings-section">
                <h3><?php _e('API Configuration', 'my-starter-addon'); ?></h3>
                <div class="bp-addon-field">
                    <label><?php _e('API Key', 'my-starter-addon'); ?></label>
                    <input type="text"
                           name="api_key"
                           value="<?php echo esc_attr($api_key); ?>"
                           class="regular-text">
                </div>
                <button type="button" class="button button-primary save-addon-settings">
                    <?php _e('Save Settings', 'my-starter-addon'); ?>
                </button>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('.my-addon-settings .save-addon-settings').on('click', function() {
                    var settings = {
                        api_key: $('[name="api_key"]').val()
                    };

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'starter_save_addon_settings',
                            nonce: '<?php echo wp_create_nonce('starter_settings_nonce'); ?>',
                            addon_id: 'my-custom-addon',
                            settings: settings
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php _e('Settings saved!', 'my-starter-addon'); ?>');
                            }
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
}

// Initialize
My_Custom_Addon::instance();
```

## Custom Categories

You can create your own categories for external addons:

```php
$addons['my-addon'] = [
    // ...
    'category' => 'my-custom-category',  // Any string
    // ...
];
```

The category will be automatically created in the Starter Dashboard UI.

## Available Icon Names

Use any icon from the Untitled UI icon set. Common examples:

- `star-01`, `star-02`
- `puzzle`
- `magic-wand-03`
- `share-08`
- `layout-grid`
- `cloud`
- `database`
- `settings-01`
- `message-02`
- `tick-02`
- `link-backward`

## Best Practices

1. **Dependency Check**: Always check if Starter Dashboard is active
2. **Use Unique ID**: Use a unique addon ID (slug-style, lowercase, hyphens)
3. **Namespace Classes**: Prefix your classes to avoid conflicts
4. **Settings Callback**: Must be a static method or callable function
5. **File Path**: Provide absolute path to your addon file
6. **README**: Include a README.md for documentation in the Starter Dashboard UI

## Activation Flow

1. User installs Starter Dashboard plugin
2. User installs your external addon plugin
3. Your plugin registers itself via `starter_register_external_addons` filter
4. Addon appears in Starter Dashboard → Addons page
5. User activates your addon via toggle switch
6. Your addon's file is loaded and functionality is available

## Testing

To test your external addon:

1. Install both Starter Dashboard and your addon plugin
2. Go to Dashboard → Hub → Addons
3. Find your addon in the list
4. Activate it via the toggle switch
5. Click settings icon to open your settings panel

## Support

For questions about creating external addons, refer to:
- Starter Dashboard documentation
- Example addons in the `/addons` directory
- GitHub repository
